<?php
/**
 * The interpretation orchestrator.
 *
 * Tries the model, validates what comes back strictly, and falls back to the deterministic floor
 * on any failure — recording WHICH failure, so an operator can tell "we had no key" from "the
 * model hallucinated a field" from "this page was over budget". V2's single `fallback_mode`
 * boolean could not express the difference, and its artifacts claimed a model ran either way.
 *
 * Provenance is written by whichever component actually produced each value. That is the D-3 fix
 * and `DBVC_CC_Interp_Contracts::validate_result()` enforces it.
 */

if (! defined('WPINC') && PHP_SAPI !== 'cli') {
    die;
}

final class DBVC_CC_Interp_Service
{
    /**
     * @param array<string, mixed> $input      interpretation-input.v1
     * @param array<string, mixed> $schema     corpus-target-schema.v1
     * @param string               $object_key
     * @param array<string, mixed> $options    model config, plus 'transport', 'cache_dir', 'use_model'
     * @return array<string, mixed> interpretation-result.v1
     */
    public static function interpret(array $input, array $schema, $object_key, array $options = [])
    {
        $use_model = array_key_exists('use_model', $options) ? (bool) $options['use_model'] : true;
        if (! $use_model) {
            return self::with_attempt(
                DBVC_CC_Interp_Floor::interpret($input, $schema, $object_key),
                ['attempted' => false, 'reason' => 'model_disabled']
            );
        }

        $object = null;
        foreach ($schema['objects'] ?? [] as $candidate) {
            if (is_array($candidate) && (string) ($candidate['object_key'] ?? '') === (string) $object_key) {
                $object = $candidate;
                break;
            }
        }
        if ($object === null) {
            return self::with_attempt(
                DBVC_CC_Interp_Floor::interpret($input, $schema, $object_key),
                ['attempted' => false, 'reason' => 'unknown_object_key']
            );
        }

        $fields = DBVC_CC_Interp_Contracts::mappable_fields($object);
        $system = DBVC_CC_Interp_Prompt::system_prompt();
        $user_payload = DBVC_CC_Interp_Prompt::user_payload($input, $fields, $object);

        // Construct the client BEFORE keying the cache, and key on the model it resolved rather
        // than the option as passed. When a caller omits 'model' the option is empty, so keying on
        // the raw option made every default share one cache entry - changing the default then
        // silently replayed the previous model's answer, which is exactly what happened when the
        // OpenAI default moved off gpt-4o. A model change must always miss the cache.
        $client = new DBVC_CC_Interp_Model($options, $options['transport'] ?? null);
        $resolved = $client->config();

        $cache_key = hash('sha256', implode('|', [
            DBVC_CC_Interp_Prompt::PROMPT_VERSION,
            (string) $resolved['model'],
            (string) $resolved['provider'],
            $system,
            $user_payload,
        ]));

        $cached = self::cache_read($options, $cache_key);
        $from_cache = $cached !== null;

        if ($from_cache) {
            $completion = $cached;
        } else {
            $completion = $client->complete($system, $user_payload);
        }

        if (empty($completion['ok'])) {
            return self::with_attempt(
                DBVC_CC_Interp_Floor::interpret($input, $schema, $object_key),
                [
                    'attempted' => true,
                    'reason' => (string) ($completion['skip'] ?? DBVC_CC_Interp_Model::SKIP_TRANSPORT_ERROR),
                    'detail' => (string) ($completion['detail'] ?? ''),
                ]
            );
        }

        $decoded = self::decode_json_payload((string) $completion['text']);
        $errors = DBVC_CC_Interp_Prompt::validate_response($decoded, $fields, $input);
        if ($errors !== []) {
            return self::with_attempt(
                DBVC_CC_Interp_Floor::interpret($input, $schema, $object_key),
                [
                    'attempted' => true,
                    'reason' => DBVC_CC_Interp_Model::SKIP_INVALID_SHAPE,
                    // Capped: a hallucinating model can produce a very long error list.
                    'detail' => implode('; ', array_slice($errors, 0, 5))
                        . (count($errors) > 5 ? sprintf(' (+%d more)', count($errors) - 5) : ''),
                ]
            );
        }

        if (! $from_cache) {
            self::cache_write($options, $cache_key, $completion);
        }

        return self::build_model_result($input, $decoded, $completion, $object_key, $from_cache);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $completion
     * @param string               $object_key
     * @param bool                 $from_cache
     * @return array<string, mixed>
     */
    private static function build_model_result(array $input, array $decoded, array $completion, $object_key, $from_cache)
    {
        $provenance = DBVC_CC_Interp_Contracts::provenance(
            DBVC_CC_Interp_Contracts::INTERPRETER_MODEL,
            DBVC_CC_Interp_Contracts::BASIS_MODEL_REPORTED,
            [
                'model' => (string) ($completion['model'] ?? ''),
                'provider' => (string) ($completion['provider'] ?? ''),
                'prompt_version' => DBVC_CC_Interp_Prompt::PROMPT_VERSION,
            ]
        );

        $text_by_item = [];
        foreach ($input['items'] ?? [] as $item) {
            $text_by_item[(string) $item['item_id']] = (string) ($item['text'] ?? '');
        }

        $recommendations = [];
        foreach ($decoded['mappings'] as $mapping) {
            $confidence = round((float) $mapping['confidence'], 2);
            $reason_codes = [];
            if ($confidence < DBVC_CC_Interp_Contracts::AUTO_ACCEPT_MIN_CONFIDENCE) {
                $reason_codes[] = 'below_auto_accept_confidence';
            }

            $recommendations[] = [
                'recommendation_id' => 'rec_' . str_pad((string) (count($recommendations) + 1), 3, '0', STR_PAD_LEFT),
                'item_id' => (string) $mapping['item_id'],
                'target_ref' => (string) $mapping['target_ref'],
                'target_object_key' => (string) $object_key,
                'confidence' => $confidence,
                // Identical gate to the floor's, so G-4 compares like with like.
                'requires_review' => $confidence < DBVC_CC_Interp_Contracts::AUTO_ACCEPT_MIN_CONFIDENCE,
                'reason_codes' => $reason_codes,
                'rationale' => (string) ($mapping['rationale'] ?? ''),
                'source_evidence' => $text_by_item[(string) $mapping['item_id']] ?? '',
                'provenance' => $provenance,
            ];
        }

        $declined = [];
        foreach ($decoded['declined'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $item_id = (string) ($entry['item_id'] ?? '');
            $declined[] = [
                'item_id' => $item_id,
                'reason' => (string) ($entry['reason'] ?? ''),
                'source_evidence' => $text_by_item[$item_id] ?? '',
            ];
        }

        return [
            'artifact_type' => DBVC_CC_Interp_Contracts::RESULT_TYPE,
            'page_slug' => (string) ($input['page_slug'] ?? ''),
            'source_url' => (string) ($input['source_url'] ?? ''),
            'target_object_key' => (string) $object_key,
            'interpreter' => DBVC_CC_Interp_Contracts::INTERPRETER_MODEL,
            'model_attempt' => [
                'attempted' => true,
                'used' => true,
                'from_cache' => (bool) $from_cache,
                'model' => (string) ($completion['model'] ?? ''),
                'provider' => (string) ($completion['provider'] ?? ''),
                'prompt_version' => DBVC_CC_Interp_Prompt::PROMPT_VERSION,
                'usage' => isset($completion['usage']) && is_array($completion['usage']) ? $completion['usage'] : [],
            ],
            'recommendations' => $recommendations,
            'declined' => $declined,
            'errors' => [],
            'stats' => [
                'items_in' => count($input['items'] ?? []),
                'recommended' => count($recommendations),
                'declined' => count($declined),
                'auto_accepted' => count(array_filter(
                    $recommendations,
                    static function ($recommendation) {
                        return empty($recommendation['requires_review']);
                    }
                )),
            ],
        ];
    }

    /**
     * Annotate a floor result with why the model was not used. The floor's own provenance is
     * untouched, so nothing claims a model ran.
     *
     * @param array<string, mixed> $floor_result
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private static function with_attempt(array $floor_result, array $attempt)
    {
        $floor_result['model_attempt'] = array_merge(['used' => false], $attempt);

        return $floor_result;
    }

    /**
     * Models sometimes wrap JSON in a code fence despite instructions. Unwrapping that is not
     * leniency about shape - the validator is still strict about contents.
     *
     * @param string $text
     * @return mixed
     */
    private static function decode_json_payload($text)
    {
        $trimmed = trim($text);
        if (strpos($trimmed, '```') === 0) {
            $trimmed = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $trimmed);
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Last resort: the outermost JSON object in the text.
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return json_decode(substr($trimmed, $start, $end - $start + 1), true);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     * @param string               $key
     * @return array<string, mixed>|null
     */
    private static function cache_read(array $options, $key)
    {
        $path = self::cache_path($options, $key);
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && ! empty($decoded['ok']) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $options
     * @param string               $key
     * @param array<string, mixed> $completion
     * @return void
     */
    private static function cache_write(array $options, $key, array $completion)
    {
        $path = self::cache_path($options, $key);
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }
        @file_put_contents($path, (string) wp_json_encode_compat($completion));
    }

    /**
     * Cache keyed on prompt version plus payload, so a prompt change never reuses an old answer.
     *
     * @param array<string, mixed> $options
     * @param string               $key
     * @return string
     */
    private static function cache_path(array $options, $key)
    {
        $dir = (string) ($options['cache_dir'] ?? '');

        return $dir === '' ? '' : rtrim($dir, '/') . '/' . $key . '.json';
    }
}
