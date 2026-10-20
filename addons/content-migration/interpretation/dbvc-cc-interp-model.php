<?php
/**
 * The one place that makes an outbound model call.
 *
 * Provider-agnostic by design: adapters normalise request and response, so changing provider is a
 * setting and not a rewrite. V2's single provider was hardcoded OpenAI behind a plaintext option;
 * that is D-12 and it is fixed here.
 *
 * The transport is injectable. Every branch of this class - success, HTTP error, malformed JSON,
 * budget refusal, missing key - is therefore testable with no network and no key, which is the
 * only way the fallback paths get exercised before they matter in production.
 */

if (! defined('WPINC') && PHP_SAPI !== 'cli') {
    die;
}

final class DBVC_CC_Interp_Model
{
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI = 'openai';

    /**
     * Default model per provider. Deliberately not V2's `gpt-4o-mini`: that default was never
     * revisited across 32 phases (V3 guide section 7.2).
     *
     * The OpenAI default is measured, not assumed. On corpus slot 1's homepage, against the same
     * prompt and the same ground truth:
     *
     *   gpt-4o          10 recommendations, 7 right, 3 wrong,  70% accuracy,  9/17 probes
     *   gpt-5.6-luna    21 recommendations, 17 right, 2 wrong, 89% accuracy
     *   gpt-5.6-terra   18 recommendations, 17 right, 0 wrong, 100% accuracy, 17/17 probes
     *   gpt-6-luna      19 recommendations, 17 right, 0 wrong, 100% accuracy, 17/17 probes
     *
     * terra and gpt-6-luna are indistinguishable on correctness, so the default is the cheaper of
     * the two: 1,341 output tokens against 2,577 for identical results. gpt-4o's shortfall was
     * capability, not prompting - it declined card titles and offer text that the field list plainly
     * covers, which looked like a prompt defect until a better model mapped them without changes.
     *
     * Measured on one page. Re-measure across corpus slots 2-5 before trusting it on harder layouts.
     */
    public const DEFAULT_MODELS = [
        self::PROVIDER_ANTHROPIC => 'claude-sonnet-5-5',
        self::PROVIDER_OPENAI => 'gpt-5.6-terra',
    ];

    /** Why a model result was not used. Each becomes an honest fallback label. */
    public const SKIP_NO_KEY = 'no_api_key';
    public const SKIP_OVER_BUDGET = 'over_budget';
    public const SKIP_TRANSPORT_ERROR = 'transport_error';
    public const SKIP_INVALID_SHAPE = 'invalid_response_shape';

    /** @var callable|null */
    private $transport;

    /** @var array<string, mixed> */
    private $config;

    /**
     * @param array<string, mixed> $config
     * @param callable|null        $transport function(string $url, array $headers, string $body): array{status:int, body:string}
     */
    public function __construct(array $config = [], $transport = null)
    {
        $this->config = array_merge([
            'provider' => self::PROVIDER_ANTHROPIC,
            'model' => '',
            'api_key' => '',
            // An organization-level Anthropic key is not bound to a workspace, and the API then
            // requires the workspace to be named explicitly. A workspace-scoped key needs none of
            // this; this exists so an org key works too rather than failing with a 400.
            'workspace_id' => '',
            'max_output_tokens' => 4096,
            'timeout' => 60,
            // A per-call ceiling on prompt size. A 150-page site must not quietly become a
            // five-figure bill; G-8 requires the estimate be known before the run starts.
            'max_prompt_chars' => 120000,
        ], $config);

        if ($this->config['model'] === '') {
            $provider = (string) $this->config['provider'];
            $this->config['model'] = self::DEFAULT_MODELS[$provider] ?? '';
        }

        if ($this->config['workspace_id'] === '') {
            $this->config['workspace_id'] = self::resolve_workspace_id();
        }

        $this->transport = $transport;
    }

    /**
     * Resolve an API key without storing one in the database.
     *
     * Order: an explicitly passed key, then a PHP constant, then the environment. A wp_options
     * value is accepted last and only if nothing better exists, because that is D-12.
     *
     * @param string $provider
     * @return string
     */
    public static function resolve_api_key($provider)
    {
        $constant = $provider === self::PROVIDER_ANTHROPIC
            ? 'DBVC_CC_ANTHROPIC_API_KEY'
            : 'DBVC_CC_OPENAI_API_KEY';
        if (defined($constant) && constant($constant) !== '') {
            return (string) constant($constant);
        }

        foreach ([$constant, strtoupper($provider) . '_API_KEY'] as $env_name) {
            $value = getenv($env_name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Resolve an Anthropic workspace id, by the same precedence as the key.
     *
     * Only needed for an organization-level key. Left empty for a workspace-scoped one, and the
     * header is then omitted entirely.
     *
     * @return string
     */
    public static function resolve_workspace_id()
    {
        if (defined('DBVC_CC_ANTHROPIC_WORKSPACE_ID')
            && (string) constant('DBVC_CC_ANTHROPIC_WORKSPACE_ID') !== '') {
            return (string) constant('DBVC_CC_ANTHROPIC_WORKSPACE_ID');
        }

        $value = getenv('DBVC_CC_ANTHROPIC_WORKSPACE_ID');

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function config()
    {
        return $this->config;
    }

    /**
     * Make the call.
     *
     * @param string $system
     * @param string $user_payload
     * @return array{ok:bool, skip?:string, detail?:string, text?:string, usage?:array<string,mixed>, model?:string, provider?:string}
     */
    public function complete($system, $user_payload)
    {
        $api_key = (string) $this->config['api_key'];
        if ($api_key === '') {
            $api_key = self::resolve_api_key((string) $this->config['provider']);
        }
        if ($api_key === '') {
            return [
                'ok' => false,
                'skip' => self::SKIP_NO_KEY,
                'detail' => sprintf(
                    'no API key for provider "%s". Set the %s constant in wp-config.php or export it. '
                    . 'An organization-level Anthropic key also needs DBVC_CC_ANTHROPIC_WORKSPACE_ID, '
                    . 'or use a workspace-scoped key.',
                    $this->config['provider'],
                    $this->config['provider'] === self::PROVIDER_ANTHROPIC
                        ? 'DBVC_CC_ANTHROPIC_API_KEY'
                        : 'DBVC_CC_OPENAI_API_KEY'
                ),
            ];
        }

        $prompt_chars = strlen($system) + strlen($user_payload);
        if ($prompt_chars > (int) $this->config['max_prompt_chars']) {
            return [
                'ok' => false,
                'skip' => self::SKIP_OVER_BUDGET,
                'detail' => sprintf(
                    'prompt is %d characters, over the %d ceiling. Split the page or raise the ceiling deliberately.',
                    $prompt_chars,
                    (int) $this->config['max_prompt_chars']
                ),
            ];
        }

        $request = $this->build_request($system, $user_payload, $api_key);
        $response = $this->send($request['url'], $request['headers'], $request['body']);

        if (! empty($response['error'])) {
            return ['ok' => false, 'skip' => self::SKIP_TRANSPORT_ERROR, 'detail' => (string) $response['error']];
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'skip' => self::SKIP_TRANSPORT_ERROR,
                'detail' => sprintf('HTTP %d from %s: %s', $status, $this->config['provider'], substr((string) ($response['body'] ?? ''), 0, 400)),
            ];
        }

        $parsed = $this->parse_response((string) ($response['body'] ?? ''));
        if ($parsed === null) {
            return [
                'ok' => false,
                'skip' => self::SKIP_INVALID_SHAPE,
                'detail' => 'provider envelope did not contain assistant text',
            ];
        }

        return [
            'ok' => true,
            'text' => $parsed['text'],
            'usage' => $parsed['usage'],
            'model' => (string) $this->config['model'],
            'provider' => (string) $this->config['provider'],
        ];
    }

    /**
     * @param string $system
     * @param string $user_payload
     * @param string $api_key
     * @return array{url:string, headers:array<string,string>, body:string}
     */
    private function build_request($system, $user_payload, $api_key)
    {
        if ((string) $this->config['provider'] === self::PROVIDER_ANTHROPIC) {
            $headers = [
                'content-type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ];
            if ((string) $this->config['workspace_id'] !== '') {
                $headers['anthropic-workspace-id'] = (string) $this->config['workspace_id'];
            }

            return [
                'url' => 'https://api.anthropic.com/v1/messages',
                'headers' => $headers,
                'body' => (string) wp_json_encode_compat([
                    'model' => $this->config['model'],
                    'max_tokens' => (int) $this->config['max_output_tokens'],
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user_payload]],
                ]),
            ];
        }

        // OpenAI renamed this parameter for the model families after gpt-4/gpt-3, and sending the
        // wrong one is rejected outright rather than ignored. Pick by model name so a newer model
        // passed with --model does not fail on a parameter rather than on its answer.
        $model = (string) $this->config['model'];
        $token_param = preg_match('/^gpt-(?:4|3)/i', $model) ? 'max_tokens' : 'max_completion_tokens';

        $body = [
            'model' => $model,
            $token_param => (int) $this->config['max_output_tokens'],
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user_payload],
            ],
        ];

        return [
            'url' => 'https://api.openai.com/v1/chat/completions',
            'headers' => [
                'content-type' => 'application/json',
                'authorization' => 'Bearer ' . $api_key,
            ],
            'body' => (string) wp_json_encode_compat($body),
        ];
    }

    /**
     * @param string $body
     * @return array{text:string, usage:array<string,mixed>}|null
     */
    private function parse_response($body)
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return null;
        }

        if ((string) $this->config['provider'] === self::PROVIDER_ANTHROPIC) {
            $text = '';
            foreach ($decoded['content'] ?? [] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }
            if ($text === '') {
                return null;
            }

            return [
                'text' => $text,
                'usage' => [
                    'input_tokens' => (int) ($decoded['usage']['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($decoded['usage']['output_tokens'] ?? 0),
                ],
            ];
        }

        $text = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            return null;
        }

        return [
            'text' => $text,
            'usage' => [
                'input_tokens' => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     * @return array{status?:int, body?:string, error?:string}
     */
    private function send($url, array $headers, $body)
    {
        if (is_callable($this->transport)) {
            return call_user_func($this->transport, $url, $headers, $body);
        }

        if (function_exists('wp_remote_post')) {
            $response = wp_remote_post($url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => (int) $this->config['timeout'],
            ]);
            if (function_exists('is_wp_error') && is_wp_error($response)) {
                return ['error' => $response->get_error_message()];
            }

            return [
                'status' => (int) wp_remote_retrieve_response_code($response),
                'body' => (string) wp_remote_retrieve_body($response),
            ];
        }

        if (! function_exists('curl_init')) {
            return ['error' => 'no HTTP transport available (no wp_remote_post, no cURL)'];
        }

        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config['timeout'],
        ]);
        $result = curl_exec($handle);
        if ($result === false) {
            $error = curl_error($handle);
            curl_close($handle);

            return ['error' => $error !== '' ? $error : 'cURL request failed'];
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => (string) $result];
    }
}
