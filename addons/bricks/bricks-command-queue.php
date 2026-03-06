<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Command_Queue
{
    public const OPTION_QUEUE = 'dbvc_bricks_command_envelopes';
    public const OPTION_PROCESSED = 'dbvc_bricks_command_processed_envelopes';
    public const OPTION_CLIENT_NONCES = 'dbvc_bricks_command_client_nonce_store';
    public const STATE_QUEUED = 'queued';
    public const STATE_LEASED = 'leased';
    public const STATE_APPLIED = 'applied';
    public const STATE_FAILED = 'failed';
    public const STATE_DEAD_LETTER = 'dead_letter';
    public const MAX_ITEMS = 2000;
    public const DEFAULT_LEASE_SECONDS = 90;
    public const DEFAULT_RETRY_BASE_SECONDS = 60;
    public const MAX_CLIENT_NONCES = 1000;
    public const RECENT_PULL_WINDOW_SECONDS = 900;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_queue()
    {
        $queue = get_option(self::OPTION_QUEUE, []);
        return is_array($queue) ? $queue : [];
    }

    /**
     * @param array<string, array<string, mixed>> $queue
     * @return void
     */
    private static function set_queue(array $queue)
    {
        if (count($queue) > self::MAX_ITEMS) {
            $queue = array_slice($queue, -self::MAX_ITEMS, null, true);
        }
        update_option(self::OPTION_QUEUE, $queue);
    }

    /**
     * @param string $event_type
     * @param array<string, mixed> $payload
     * @return void
     */
    private static function append_diagnostic($event_type, array $payload)
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return;
        }
        $rows = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        if (! is_array($rows)) {
            $rows = [];
        }
        $rows[] = [
            'event_type' => sanitize_key((string) $event_type),
            'payload' => DBVC_Bricks_Addon::sanitize_diagnostics_payload($payload),
            'correlation_id' => 'cmdq-' . substr(hash('sha256', microtime(true) . self::random_seed()), 0, 8),
            'actor_id' => get_current_user_id(),
            'at' => gmdate('c'),
        ];
        if (count($rows) > DBVC_Bricks_Addon::UI_DIAGNOSTIC_MAX_ITEMS) {
            $rows = array_slice($rows, -DBVC_Bricks_Addon::UI_DIAGNOSTIC_MAX_ITEMS);
        }
        update_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, array_values($rows));
    }

    /**
     * @return array<string, mixed>
     */
    private static function load_shared_rules_profile_snapshot()
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return [];
        }
        $raw = get_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }
        $profile = [
            'profile_version' => max(1, (int) ($decoded['profile_version'] ?? 1)),
            'updated_at' => sanitize_text_field((string) ($decoded['updated_at'] ?? '')),
            'updated_by' => sanitize_text_field((string) ($decoded['updated_by'] ?? '')),
            'notes' => sanitize_text_field((string) ($decoded['notes'] ?? '')),
            'rules' => isset($decoded['rules']) && is_array($decoded['rules']) ? (array) $decoded['rules'] : [],
        ];
        if (isset($decoded['non_goals']) && is_array($decoded['non_goals'])) {
            $profile['non_goals'] = array_values(array_map(static function ($item) {
                return sanitize_text_field((string) $item);
            }, $decoded['non_goals']));
        }
        return $profile;
    }

    /**
     * @param string $command_type
     * @param string $distribution_id
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalize_enqueue_payload($command_type, $distribution_id, array $payload)
    {
        $command_type = sanitize_key((string) $command_type);
        $distribution_id = sanitize_key((string) $distribution_id);
        if ($distribution_id !== '' && empty($payload['distribution_id'])) {
            $payload['distribution_id'] = $distribution_id;
        }
        if ($command_type === 'shared_rules_apply' && empty($payload['profile'])) {
            $profile = self::load_shared_rules_profile_snapshot();
            if (! empty($profile)) {
                $payload['profile'] = $profile;
            }
        }
        return $payload;
    }

    /**
     * @return true|\WP_Error
     */
    private static function ensure_mothership_role()
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return new \WP_Error('dbvc_bricks_command_queue_runtime_missing', 'Bricks addon runtime is unavailable.', ['status' => 500]);
        }
        if (DBVC_Bricks_Addon::get_role_mode() !== 'mothership') {
            return new \WP_Error('dbvc_bricks_command_queue_role_invalid', 'Command queue endpoints are mothership-only.', ['status' => 400]);
        }
        return true;
    }

    /**
     * @param string $site_uid
     * @return true|\WP_Error
     */
    private static function assert_site_auth_context($site_uid)
    {
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '') {
            return new \WP_Error('dbvc_bricks_command_site_uid_required', 'site_uid is required.', ['status' => 400]);
        }

        $client = class_exists('DBVC_Bricks_Onboarding') ? DBVC_Bricks_Onboarding::get_client($site_uid) : null;
        if (! is_array($client)) {
            return true;
        }
        $auth_profile = isset($client['auth_profile']) && is_array($client['auth_profile']) ? $client['auth_profile'] : [];
        $expected_user = sanitize_text_field((string) ($auth_profile['key_id'] ?? ''));
        if ($expected_user === '') {
            return true;
        }
        $current = wp_get_current_user();
        $current_login = ($current instanceof \WP_User && ! empty($current->user_login))
            ? (string) $current->user_login
            : '';
        if ($current_login !== '' && ! hash_equals($expected_user, $current_login)) {
            return new \WP_Error('dbvc_bricks_command_auth_context_invalid', 'Authenticated user does not match site auth profile.', ['status' => 403]);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $profile
     * @param string $distribution_id
     * @param string $correlation_id
     * @return array<string, mixed>
     */
    public static function enqueue_shared_rules_for_site(array $site, array $profile, $distribution_id, $correlation_id = '')
    {
        $site_uid = sanitize_key((string) ($site['site_uid'] ?? ''));
        if ($site_uid === '') {
            return ['site_uid' => '', 'status' => 'failed', 'error_code' => 'site_uid_invalid'];
        }
        if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
            $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($site_uid);
            $resolved_uid = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
            if ($resolved_uid !== '') {
                $site_uid = $resolved_uid;
            }
        }

        $secret = DBVC_Bricks_Addon::get_command_secret_for_site($site_uid);
        if ($secret === '') {
            return ['site_uid' => $site_uid, 'status' => 'failed', 'error_code' => 'command_secret_missing'];
        }

        $payload = [
            'distribution_id' => sanitize_key((string) $distribution_id),
            'profile' => $profile,
        ];

        $envelope = self::create_envelope(
            'shared_rules_apply',
            $site_uid,
            $payload,
            $secret,
            [
                'distribution_id' => sanitize_key((string) $distribution_id),
                'correlation_id' => sanitize_text_field((string) $correlation_id),
            ]
        );
        if (is_wp_error($envelope)) {
            return [
                'site_uid' => $site_uid,
                'status' => 'failed',
                'error_code' => (string) $envelope->get_error_code(),
            ];
        }

        return [
            'site_uid' => $site_uid,
            'status' => 'queued',
            'envelope_id' => (string) ($envelope['envelope_id'] ?? ''),
            'state' => (string) ($envelope['state'] ?? self::STATE_QUEUED),
        ];
    }

    /**
     * @param string $command_type
     * @param string $site_uid
     * @param array<string, mixed> $payload
     * @param string $secret
     * @param array<string, mixed> $context
     * @return array<string, mixed>|\WP_Error
     */
    private static function create_envelope($command_type, $site_uid, array $payload, $secret, array $context = [])
    {
        $command_type = sanitize_key((string) $command_type);
        $site_uid = sanitize_key((string) $site_uid);
        $secret = sanitize_text_field((string) $secret);
        if ($command_type === '' || $site_uid === '' || $secret === '') {
            return new \WP_Error('dbvc_bricks_command_envelope_invalid', 'command_type, site_uid, and command secret are required.', ['status' => 400]);
        }

        $raw_payload = wp_json_encode($payload);
        if (! is_string($raw_payload)) {
            $raw_payload = '{}';
        }
        $envelope_id = 'env_' . substr(hash('sha256', $site_uid . '|' . gmdate('c') . '|' . self::random_seed()), 0, 16);
        $created_at = gmdate('c');
        $max_attempts = max(1, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_command_retry_max_attempts', 3));
        $expires_in_hours = max(1, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_command_envelope_ttl_hours', 24));

        $envelope = [
            'envelope_id' => $envelope_id,
            'command_type' => $command_type,
            'site_uid' => $site_uid,
            'payload' => $payload,
            'payload_hash' => 'sha256:' . hash('sha256', $raw_payload),
            'signature' => '',
            'signature_meta' => [],
            'state' => self::STATE_QUEUED,
            'attempt_count' => 0,
            'max_attempts' => $max_attempts,
            'next_attempt_at' => $created_at,
            'lease_expires_at' => '',
            'expires_at' => gmdate('c', time() + ($expires_in_hours * HOUR_IN_SECONDS)),
            'last_error_code' => '',
            'last_error_message' => '',
            'result' => [],
            'context' => self::sanitize_context($context),
            'created_at' => $created_at,
            'updated_at' => $created_at,
        ];
        $signed = self::refresh_envelope_signature($envelope, $secret);
        if (is_wp_error($signed)) {
            return $signed;
        }
        $envelope = $signed;

        $queue = self::get_queue();
        $queue[$envelope_id] = $envelope;
        self::set_queue($queue);
        self::append_diagnostic('command_envelope_queued', [
            'envelope_id' => $envelope_id,
            'site_uid' => $site_uid,
            'command_type' => $command_type,
            'distribution_id' => (string) ($envelope['context']['distribution_id'] ?? ''),
        ]);

        return $envelope;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param string $secret
     * @return array<string, mixed>|\WP_Error
     */
    private static function refresh_envelope_signature(array $envelope, $secret)
    {
        $site_uid = sanitize_key((string) ($envelope['site_uid'] ?? ''));
        $secret = sanitize_text_field((string) $secret);
        if ($site_uid === '' || $secret === '') {
            return new \WP_Error('dbvc_bricks_command_signature_refresh_invalid', 'Envelope signature refresh requires site UID and secret.', ['status' => 400]);
        }
        $payload = isset($envelope['payload']) && is_array($envelope['payload']) ? $envelope['payload'] : [];
        $raw_payload = wp_json_encode($payload);
        if (! is_string($raw_payload)) {
            $raw_payload = '{}';
        }
        $timestamp = time();
        $nonce = 'env_' . substr(hash('sha256', $site_uid . '|' . microtime(true) . '|' . self::random_seed()), 0, 16);
        $envelope['payload_hash'] = 'sha256:' . hash('sha256', $raw_payload);
        $envelope['signature'] = DBVC_Bricks_Command_Auth::build_signature($secret, $timestamp, $nonce, $site_uid, $raw_payload);
        $envelope['signature_meta'] = [
            'alg' => 'hmac-sha256',
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'site_uid' => $site_uid,
        ];
        return $envelope;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitize_context(array $context)
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $k = sanitize_key((string) $key);
            if ($k === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$k] = sanitize_text_field((string) $value);
            } elseif (is_array($value)) {
                $clean[$k] = DBVC_Bricks_Addon::sanitize_diagnostics_payload($value);
            }
        }
        return $clean;
    }

    /**
     * @param string $site_uid
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private static function lease_envelopes_for_site($site_uid, $limit = 10)
    {
        $site_uid = sanitize_key((string) $site_uid);
        $limit = max(1, min(100, (int) $limit));
        if ($site_uid === '') {
            return [];
        }

        $queue = self::get_queue();
        if (empty($queue)) {
            return [];
        }

        $now = time();
        $now_iso = gmdate('c', $now);
        $lease_seconds = max(15, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_command_lease_seconds', self::DEFAULT_LEASE_SECONDS));
        $leased = [];
        $site_secret = DBVC_Bricks_Addon::get_command_secret_for_site($site_uid);

        foreach ($queue as $envelope_id => $envelope) {
            if (! is_array($envelope)) {
                continue;
            }
            if (($envelope['site_uid'] ?? '') !== $site_uid) {
                continue;
            }
            $state = sanitize_key((string) ($envelope['state'] ?? self::STATE_QUEUED));
            if (in_array($state, [self::STATE_APPLIED, self::STATE_DEAD_LETTER], true)) {
                continue;
            }

            $next_attempt = (string) ($envelope['next_attempt_at'] ?? '');
            if ($next_attempt !== '' && strtotime($next_attempt) > $now) {
                continue;
            }
            if ($state === self::STATE_LEASED) {
                $lease_expires = (string) ($envelope['lease_expires_at'] ?? '');
                if ($lease_expires !== '' && strtotime($lease_expires) > $now) {
                    continue;
                }
            }

            $envelope['state'] = self::STATE_LEASED;
            $envelope['attempt_count'] = max(0, (int) ($envelope['attempt_count'] ?? 0)) + 1;
            $envelope['lease_expires_at'] = gmdate('c', $now + $lease_seconds);
            $envelope['updated_at'] = $now_iso;
            if ($site_secret !== '') {
                $signed = self::refresh_envelope_signature($envelope, $site_secret);
                if (is_wp_error($signed)) {
                    self::append_diagnostic('command_envelope_signature_refresh_failed', [
                        'envelope_id' => sanitize_key((string) ($envelope['envelope_id'] ?? '')),
                        'site_uid' => $site_uid,
                        'error_code' => (string) $signed->get_error_code(),
                    ]);
                } else {
                    $envelope = $signed;
                }
            } else {
                self::append_diagnostic('command_envelope_signature_refresh_skipped', [
                    'envelope_id' => sanitize_key((string) ($envelope['envelope_id'] ?? '')),
                    'site_uid' => $site_uid,
                    'reason' => 'command_secret_missing',
                ]);
            }
            $queue[$envelope_id] = $envelope;
            $leased[] = $envelope;
            self::append_diagnostic('command_envelope_leased', [
                'envelope_id' => (string) ($envelope['envelope_id'] ?? ''),
                'site_uid' => $site_uid,
                'attempt_count' => (int) ($envelope['attempt_count'] ?? 0),
            ]);
            if (count($leased) >= $limit) {
                break;
            }
        }

        if (! empty($leased)) {
            self::set_queue($queue);
        }

        return array_values($leased);
    }

    /**
     * @param string $envelope_id
     * @param string $site_uid
     * @param string $state
     * @param array<string, mixed> $result
     * @return array<string, mixed>|\WP_Error
     */
    private static function ack_envelope($envelope_id, $site_uid, $state, array $result = [])
    {
        $envelope_id = sanitize_key((string) $envelope_id);
        $site_uid = sanitize_key((string) $site_uid);
        $state = sanitize_key((string) $state);
        if ($envelope_id === '' || $site_uid === '' || ! in_array($state, [self::STATE_APPLIED, self::STATE_FAILED], true)) {
            return new \WP_Error('dbvc_bricks_command_ack_invalid', 'envelope_id, site_uid, and state (applied|failed) are required.', ['status' => 400]);
        }

        $queue = self::get_queue();
        if (! isset($queue[$envelope_id]) || ! is_array($queue[$envelope_id])) {
            return new \WP_Error('dbvc_bricks_command_envelope_not_found', 'Envelope not found.', ['status' => 404]);
        }

        $envelope = $queue[$envelope_id];
        if (($envelope['site_uid'] ?? '') !== $site_uid) {
            return new \WP_Error('dbvc_bricks_command_ack_site_mismatch', 'Envelope site UID mismatch.', ['status' => 403]);
        }

        $envelope['result'] = self::sanitize_context($result);
        $envelope['updated_at'] = gmdate('c');
        $envelope['lease_expires_at'] = '';

        if ($state === self::STATE_APPLIED) {
            $envelope['state'] = self::STATE_APPLIED;
            $envelope['next_attempt_at'] = '';
            $envelope['last_error_code'] = '';
            $envelope['last_error_message'] = '';
            self::append_diagnostic('command_envelope_applied', [
                'envelope_id' => $envelope_id,
                'site_uid' => $site_uid,
                'distribution_id' => (string) ((isset($envelope['context']) && is_array($envelope['context'])) ? ($envelope['context']['distribution_id'] ?? '') : ''),
            ]);
        } else {
            $attempt_count = max(0, (int) ($envelope['attempt_count'] ?? 0));
            $max_attempts = max(1, (int) ($envelope['max_attempts'] ?? 3));
            $error_code = sanitize_key((string) ($result['error_code'] ?? 'client_apply_failed'));
            $error_message = sanitize_text_field((string) ($result['error_message'] ?? 'Client apply failed.'));
            $envelope['last_error_code'] = $error_code;
            $envelope['last_error_message'] = $error_message;

            if ($attempt_count >= $max_attempts) {
                $envelope['state'] = self::STATE_DEAD_LETTER;
                $envelope['next_attempt_at'] = '';
                self::append_diagnostic('command_envelope_dead_letter', [
                    'envelope_id' => $envelope_id,
                    'site_uid' => $site_uid,
                    'error_code' => $error_code,
                ]);
            } else {
                $base = max(15, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_command_retry_base_seconds', self::DEFAULT_RETRY_BASE_SECONDS));
                $delay = min(HOUR_IN_SECONDS, $base * (int) pow(2, max(0, $attempt_count - 1)));
                $envelope['state'] = self::STATE_FAILED;
                $envelope['next_attempt_at'] = gmdate('c', time() + $delay);
                self::append_diagnostic('command_envelope_failed', [
                    'envelope_id' => $envelope_id,
                    'site_uid' => $site_uid,
                    'error_code' => $error_code,
                    'next_attempt_at' => (string) $envelope['next_attempt_at'],
                ]);
            }
            self::maybe_mark_site_pending_intro_from_ack_error($site_uid, $envelope_id, $error_code, $error_message);
        }

        $queue[$envelope_id] = $envelope;
        self::set_queue($queue);
        return $envelope;
    }

    /**
     * @param string $site_uid
     * @param string $envelope_id
     * @param string $error_code
     * @param string $error_message
     * @return void
     */
    private static function maybe_mark_site_pending_intro_from_ack_error($site_uid, $envelope_id, $error_code, $error_message)
    {
        $site_uid = sanitize_key((string) $site_uid);
        $envelope_id = sanitize_key((string) $envelope_id);
        $error_code = sanitize_key((string) $error_code);
        $error_message = sanitize_text_field((string) $error_message);
        if ($site_uid === '' || $error_code !== 'dbvc_bricks_client_envelope_secret_missing') {
            return;
        }

        $onboarding_reset = false;
        if (class_exists('DBVC_Bricks_Onboarding') && method_exists('DBVC_Bricks_Onboarding', 'reset_client_linkage')) {
            $record = DBVC_Bricks_Onboarding::reset_client_linkage($site_uid);
            $onboarding_reset = is_array($record);
        }

        $connected_site_updated = false;
        if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'upsert_site')) {
            $site = DBVC_Bricks_Connected_Sites::upsert_site([
                'site_uid' => $site_uid,
                'status' => 'online',
                'allow_receive_packages' => 0,
                'onboarding_state' => 'PENDING_INTRO',
                'onboarding_updated_at' => gmdate('c'),
                'last_seen_at' => gmdate('c'),
            ]);
            $connected_site_updated = is_array($site) && ! empty($site['site_uid']);
        }

        self::append_diagnostic('command_site_pending_intro_required', [
            'site_uid' => $site_uid,
            'envelope_id' => $envelope_id,
            'error_code' => $error_code,
            'error_message' => $error_message,
            'onboarding_reset' => $onboarding_reset ? 1 : 0,
            'connected_site_updated' => $connected_site_updated ? 1 : 0,
            'action_required' => 'run_client_reset_rerun_intro',
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function list_envelopes(array $filters = [])
    {
        $site_uid = sanitize_key((string) ($filters['site_uid'] ?? ''));
        $state = sanitize_key((string) ($filters['state'] ?? ''));
        $distribution_id = sanitize_key((string) ($filters['distribution_id'] ?? ''));
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));

        $items = array_values(self::get_queue());
        $items = array_values(array_filter($items, static function ($item) use ($site_uid, $state, $distribution_id) {
            if (! is_array($item)) {
                return false;
            }
            if ($site_uid !== '' && (($item['site_uid'] ?? '') !== $site_uid)) {
                return false;
            }
            if ($state !== '' && (($item['state'] ?? '') !== $state)) {
                return false;
            }
            if ($distribution_id !== '') {
                $context = isset($item['context']) && is_array($item['context']) ? $item['context'] : [];
                if (($context['distribution_id'] ?? '') !== $distribution_id) {
                    return false;
                }
            }
            return true;
        }));

        usort($items, static function ($a, $b) {
            $a_ts = strtotime((string) ($a['updated_at'] ?? $a['created_at'] ?? ''));
            $b_ts = strtotime((string) ($b['updated_at'] ?? $b['created_at'] ?? ''));
            return $b_ts <=> $a_ts;
        });
        return array_slice($items, 0, $limit);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_enqueue(\WP_REST_Request $request)
    {
        $role = self::ensure_mothership_role();
        if (is_wp_error($role)) {
            return $role;
        }

        $idempotency_key = class_exists('DBVC_Bricks_Idempotency') ? DBVC_Bricks_Idempotency::extract_key($request) : '';
        if ($idempotency_key === '') {
            self::append_diagnostic('command_enqueue_rejected', [
                'error_code' => 'dbvc_bricks_idempotency_required',
                'reason' => 'missing_idempotency_key',
            ]);
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        if (class_exists('DBVC_Bricks_Idempotency')) {
            $existing = DBVC_Bricks_Idempotency::get('command_enqueue', $idempotency_key);
            if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
                return rest_ensure_response($existing['response']);
            }
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }

        $command_type = sanitize_key((string) ($params['command_type'] ?? ''));
        $command_alias = sanitize_key((string) ($params['command'] ?? ''));
        if ($command_type === '') {
            $command_type = $command_alias !== '' ? $command_alias : 'shared_rules_apply';
        }
        if ($command_type === 'refresh_shared_rules') {
            $command_type = 'shared_rules_apply';
        }
        $target_mode = sanitize_key((string) ($params['target_mode'] ?? 'all'));
        if (! in_array($target_mode, ['all', 'selected'], true)) {
            return new \WP_Error('dbvc_bricks_command_target_mode_invalid', 'target_mode must be all or selected.', ['status' => 400]);
        }

        $selected = isset($params['site_uids']) && is_array($params['site_uids']) ? $params['site_uids'] : [];
        $selected = array_values(array_unique(array_filter(array_map(static function ($uid) {
            return sanitize_key((string) $uid);
        }, $selected))));
        if ($target_mode === 'selected' && empty($selected)) {
            self::append_diagnostic('command_enqueue_rejected', [
                'error_code' => 'dbvc_bricks_command_target_sites_required',
                'target_mode' => 'selected',
            ]);
            return new \WP_Error('dbvc_bricks_command_target_sites_required', 'selected mode requires at least one site_uid.', ['status' => 400]);
        }

        $payload = isset($params['payload']) && is_array($params['payload']) ? $params['payload'] : [];
        $distribution_id = sanitize_key((string) ($params['distribution_id'] ?? ''));
        if ($distribution_id === '') {
            $distribution_id = 'dist_cmdq_' . substr(hash('sha256', microtime(true) . self::random_seed()), 0, 16);
        }
        $payload = self::normalize_enqueue_payload($command_type, $distribution_id, $payload);
        $correlation_id = sanitize_text_field((string) ($params['correlation_id'] ?? $request->get_header('X-DBVC-Correlation-ID')));
        if ($correlation_id === '') {
            $correlation_id = 'cmdq_' . substr(hash('sha256', microtime(true) . self::random_seed()), 0, 10);
        }

        $sites = class_exists('DBVC_Bricks_Connected_Sites') ? DBVC_Bricks_Connected_Sites::get_sites() : [];
        if (! is_array($sites)) {
            $sites = [];
        }
        $results = [];
        $summary = ['queued' => 0, 'failed' => 0];
        $preflight_counts = [];
        $site_lookup = [];
        foreach ($sites as $site) {
            if (! is_array($site)) {
                continue;
            }
            $site_uid = sanitize_key((string) ($site['site_uid'] ?? ''));
            if ($site_uid === '') {
                continue;
            }
            $site_lookup[$site_uid] = $site;
            if ($target_mode === 'selected' && ! in_array($site_uid, $selected, true)) {
                continue;
            }
        }

        $targets = [];
        $seen_target_uids = [];
        if ($target_mode === 'selected') {
            foreach ($selected as $selected_uid) {
                $incoming_uid = sanitize_key((string) $selected_uid);
                $resolved_uid = $incoming_uid;
                if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
                    $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($incoming_uid);
                    $resolved_candidate = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
                    if ($resolved_candidate !== '') {
                        $resolved_uid = $resolved_candidate;
                    }
                }
                if (isset($site_lookup[$resolved_uid]) && is_array($site_lookup[$resolved_uid])) {
                    if (! isset($seen_target_uids[$resolved_uid])) {
                        $site_row = $site_lookup[$resolved_uid];
                        $site_row['incoming_site_uid'] = $incoming_uid;
                        $targets[] = $site_row;
                        $seen_target_uids[$resolved_uid] = 1;
                    }
                    continue;
                }
                $blocked_result = self::build_blocked_enqueue_result($incoming_uid, $resolved_uid, 'site_uid_not_found');
                $results[] = $blocked_result;
                self::increment_classification_count($preflight_counts, (string) ($blocked_result['classification'] ?? 'blocked_unknown'));
                $summary['failed']++;
            }
        } else {
            foreach ($site_lookup as $site_uid => $site_row) {
                $resolved_uid = $site_uid;
                if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
                    $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($site_uid);
                    $resolved_candidate = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
                    if ($resolved_candidate !== '') {
                        $resolved_uid = $resolved_candidate;
                    }
                }
                if (! isset($site_lookup[$resolved_uid]) || ! is_array($site_lookup[$resolved_uid])) {
                    continue;
                }
                if (isset($seen_target_uids[$resolved_uid])) {
                    continue;
                }
                $site_row = $site_lookup[$resolved_uid];
                $site_row['incoming_site_uid'] = $site_uid;
                $targets[] = $site_row;
                $seen_target_uids[$resolved_uid] = 1;
            }
        }

        foreach ($targets as $site) {
            $site_uid = sanitize_key((string) ($site['site_uid'] ?? ''));
            $incoming_site_uid = sanitize_key((string) ($site['incoming_site_uid'] ?? $site_uid));
            if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
                $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($site_uid);
                $resolved_uid = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
                if ($resolved_uid !== '') {
                    $site_uid = $resolved_uid;
                    if (isset($site_lookup[$site_uid]) && is_array($site_lookup[$site_uid])) {
                        $site = $site_lookup[$site_uid];
                    }
                }
            }
            $classification = self::classify_enqueue_target($incoming_site_uid, $site_uid, $site_lookup);
            if (empty($classification['ok'])) {
                $blocked_result = self::build_blocked_enqueue_result(
                    $incoming_site_uid,
                    sanitize_key((string) ($classification['resolved_site_uid'] ?? $site_uid)),
                    sanitize_key((string) ($classification['error_code'] ?? 'site_not_targetable')),
                    $classification
                );
                $results[] = $blocked_result;
                self::append_diagnostic('command_enqueue_site_blocked', [
                    'site_uid' => $incoming_site_uid,
                    'resolved_site_uid' => sanitize_key((string) ($classification['resolved_site_uid'] ?? $site_uid)),
                    'error_code' => sanitize_key((string) ($classification['error_code'] ?? 'site_not_targetable')),
                    'classification' => sanitize_key((string) ($classification['classification'] ?? 'blocked_unknown')),
                    'target_mode' => $target_mode,
                    'distribution_id' => $distribution_id,
                ]);
                self::increment_classification_count($preflight_counts, sanitize_key((string) ($blocked_result['classification'] ?? 'blocked_unknown')));
                $summary['failed']++;
                continue;
            }
            $effective_site_uid = sanitize_key((string) ($classification['effective_site_uid'] ?? $site_uid));
            if ($effective_site_uid === '') {
                $effective_site_uid = $site_uid;
            }
            $secret = DBVC_Bricks_Addon::get_command_secret_for_site($effective_site_uid);
            if ($secret === '') {
                $blocked_result = self::build_blocked_enqueue_result(
                    $incoming_site_uid,
                    $effective_site_uid,
                    'command_secret_missing',
                    [
                        'classification' => 'blocked_secret_missing',
                        'remediation_hint' => 'Reset linkage on mothership and run client "Reset + Re-run Intro Handshake".',
                        'canonical_site_uid' => sanitize_key((string) ($classification['canonical_site_uid'] ?? '')),
                    ]
                );
                $results[] = $blocked_result;
                self::append_diagnostic('command_enqueue_site_blocked', [
                    'site_uid' => $incoming_site_uid,
                    'resolved_site_uid' => $effective_site_uid,
                    'error_code' => 'command_secret_missing',
                    'classification' => 'blocked_secret_missing',
                    'target_mode' => $target_mode,
                    'distribution_id' => $distribution_id,
                ]);
                self::increment_classification_count($preflight_counts, 'blocked_secret_missing');
                $summary['failed']++;
                continue;
            }
            $envelope = self::create_envelope(
                $command_type,
                $effective_site_uid,
                $payload,
                $secret,
                [
                    'distribution_id' => $distribution_id,
                    'correlation_id' => $correlation_id,
                    'incoming_site_uid' => $incoming_site_uid,
                    'resolved_site_uid' => sanitize_key((string) ($classification['resolved_site_uid'] ?? $site_uid)),
                    'effective_site_uid' => $effective_site_uid,
                ]
            );
            if (is_wp_error($envelope)) {
                $results[] = ['site_uid' => $site_uid, 'status' => 'failed', 'error_code' => (string) $envelope->get_error_code()];
                $summary['failed']++;
                continue;
            }
            $classification_key = sanitize_key((string) ($classification['classification'] ?? ($incoming_site_uid === $effective_site_uid ? 'ready' : 'ready_alias_reroute')));
            if ($classification_key === '') {
                $classification_key = $incoming_site_uid === $effective_site_uid ? 'ready' : 'ready_alias_reroute';
            }
            self::increment_classification_count($preflight_counts, $classification_key);
            $results[] = [
                'site_uid' => $incoming_site_uid,
                'status' => 'queued',
                'envelope_id' => (string) ($envelope['envelope_id'] ?? ''),
                'resolved_site_uid' => $effective_site_uid,
                'classification' => $classification_key,
                'remediation_hint' => sanitize_text_field((string) ($classification['remediation_hint'] ?? '')),
            ];
            $summary['queued']++;
        }

        $response = [
            'ok' => $summary['failed'] === 0,
            'command_type' => $command_type,
            'target_mode' => $target_mode,
            'distribution_id' => $distribution_id,
            'summary' => $summary,
            'results' => $results,
            'preflight' => [
                'ready' => (int) $summary['queued'],
                'blocked' => (int) $summary['failed'],
                'classification_counts' => $preflight_counts,
            ],
        ];
        if (class_exists('DBVC_Bricks_Idempotency')) {
            DBVC_Bricks_Idempotency::put('command_enqueue', $idempotency_key, $response);
        }
        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_pull(\WP_REST_Request $request)
    {
        $role = self::ensure_mothership_role();
        if (is_wp_error($role)) {
            return $role;
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $incoming_site_uid = sanitize_key((string) ($params['site_uid'] ?? ''));
        if ($incoming_site_uid === '') {
            return new \WP_Error('dbvc_bricks_command_pull_site_uid_required', 'site_uid is required.', ['status' => 400]);
        }
        $site_uid = $incoming_site_uid;
        if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
            $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($incoming_site_uid);
            $resolved_uid = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
            if ($resolved_uid !== '') {
                $site_uid = $resolved_uid;
            }
        }
        $auth_context = self::assert_site_auth_context($site_uid);
        if (is_wp_error($auth_context)) {
            return $auth_context;
        }
        if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'record_pull_activity')) {
            DBVC_Bricks_Connected_Sites::record_pull_activity($incoming_site_uid, $site_uid);
        }
        $limit = max(1, min(100, (int) ($params['limit'] ?? 10)));
        $items = self::lease_envelopes_for_site($site_uid, $limit);
        return rest_ensure_response([
            'ok' => true,
            'site_uid' => $site_uid,
            'incoming_site_uid' => $incoming_site_uid,
            'items' => $items,
            'count' => count($items),
            'server_time' => gmdate('c'),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_ack(\WP_REST_Request $request)
    {
        $role = self::ensure_mothership_role();
        if (is_wp_error($role)) {
            return $role;
        }

        $idempotency_key = class_exists('DBVC_Bricks_Idempotency') ? DBVC_Bricks_Idempotency::extract_key($request) : '';
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        if (class_exists('DBVC_Bricks_Idempotency')) {
            $existing = DBVC_Bricks_Idempotency::get('command_ack', $idempotency_key);
            if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
                return rest_ensure_response($existing['response']);
            }
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $envelope_id = sanitize_key((string) ($params['envelope_id'] ?? ''));
        $incoming_site_uid = sanitize_key((string) ($params['site_uid'] ?? ''));
        $site_uid = $incoming_site_uid;
        if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
            $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($incoming_site_uid);
            $resolved_uid = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
            if ($resolved_uid !== '') {
                $site_uid = $resolved_uid;
            }
        }
        $state = sanitize_key((string) ($params['state'] ?? ''));
        $result = isset($params['result']) && is_array($params['result']) ? $params['result'] : [];
        $auth_context = self::assert_site_auth_context($site_uid);
        if (is_wp_error($auth_context)) {
            return $auth_context;
        }
        if (is_array($result)) {
            $result['incoming_site_uid'] = $incoming_site_uid;
            $result['resolved_site_uid'] = $site_uid;
        }
        $acked = self::ack_envelope($envelope_id, $site_uid, $state, $result);
        if (is_wp_error($acked)) {
            return $acked;
        }
        $response = [
            'ok' => true,
            'envelope' => $acked,
        ];
        if (class_exists('DBVC_Bricks_Idempotency')) {
            DBVC_Bricks_Idempotency::put('command_ack', $idempotency_key, $response);
        }
        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_status(\WP_REST_Request $request)
    {
        $role = self::ensure_mothership_role();
        if (is_wp_error($role)) {
            return $role;
        }

        $items = self::list_envelopes([
            'site_uid' => (function () use ($request) {
                $site_uid = sanitize_key((string) $request->get_param('site_uid'));
                if ($site_uid === '') {
                    return '';
                }
                if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
                    $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($site_uid);
                    $resolved_uid = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
                    if ($resolved_uid !== '') {
                        return $resolved_uid;
                    }
                }
                return $site_uid;
            })(),
            'state' => $request->get_param('state'),
            'distribution_id' => $request->get_param('distribution_id'),
            'limit' => $request->get_param('limit'),
        ]);
        return rest_ensure_response([
            'items' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * @param string $context
     * @return array<string, mixed>
     */
    public static function run_client_pull_tick($context = 'cron')
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return ['ok' => false, 'reason' => 'runtime_missing'];
        }
        if (DBVC_Bricks_Addon::get_role_mode() !== 'client') {
            return ['ok' => false, 'reason' => 'role_not_client'];
        }
        self::maybe_seed_client_pull_transport_mode();
        $transport_mode = DBVC_Bricks_Addon::get_enum_setting('dbvc_bricks_command_transport_mode', ['direct_push', 'client_pull_envelope'], 'direct_push');
        if ($transport_mode !== 'client_pull_envelope') {
            return ['ok' => false, 'reason' => 'transport_mode_disabled'];
        }

        $site_uid = sanitize_key((string) DBVC_Bricks_Addon::get_setting('dbvc_bricks_site_uid', ''));
        if ($site_uid === '') {
            $site_uid = 'site_' . get_current_blog_id();
        }
        $pull = self::pull_remote_envelopes($site_uid, 10);
        if (is_wp_error($pull)) {
            self::append_diagnostic('command_client_pull_failed', [
                'site_uid' => $site_uid,
                'context' => sanitize_key((string) $context),
                'error_code' => (string) $pull->get_error_code(),
                'message' => (string) $pull->get_error_message(),
            ]);
            return ['ok' => false, 'reason' => 'pull_failed', 'error_code' => (string) $pull->get_error_code()];
        }
        $items = isset($pull['items']) && is_array($pull['items']) ? $pull['items'] : [];
        if (empty($items)) {
            return ['ok' => true, 'site_uid' => $site_uid, 'processed' => 0];
        }

        $processed = 0;
        $applied = 0;
        $failed = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $processed++;
            $envelope_id = sanitize_key((string) ($item['envelope_id'] ?? ''));
            if ($envelope_id === '') {
                continue;
            }
            if (self::is_envelope_processed($envelope_id)) {
                self::ack_remote_envelope($site_uid, $envelope_id, self::STATE_APPLIED, ['receipt_id' => 'replay_skip']);
                continue;
            }

            $verification = self::verify_client_envelope($item, $site_uid);
            if (is_wp_error($verification)) {
                self::append_diagnostic('command_client_envelope_failed', [
                    'site_uid' => $site_uid,
                    'envelope_id' => $envelope_id,
                    'error_code' => (string) $verification->get_error_code(),
                    'error_message' => (string) $verification->get_error_message(),
                ]);
                self::ack_remote_envelope($site_uid, $envelope_id, self::STATE_FAILED, [
                    'error_code' => (string) $verification->get_error_code(),
                    'error_message' => (string) $verification->get_error_message(),
                ]);
                $failed++;
                continue;
            }

            $run = self::execute_client_envelope($item, $site_uid);
            if (is_wp_error($run)) {
                self::append_diagnostic('command_client_envelope_failed', [
                    'site_uid' => $site_uid,
                    'envelope_id' => $envelope_id,
                    'error_code' => (string) $run->get_error_code(),
                    'error_message' => (string) $run->get_error_message(),
                ]);
                self::ack_remote_envelope($site_uid, $envelope_id, self::STATE_FAILED, [
                    'error_code' => (string) $run->get_error_code(),
                    'error_message' => (string) $run->get_error_message(),
                ]);
                $failed++;
                continue;
            }

            self::mark_envelope_processed($envelope_id);
            self::append_diagnostic('command_client_envelope_applied', [
                'site_uid' => $site_uid,
                'envelope_id' => $envelope_id,
                'receipt_id' => (string) ($run['receipt_id'] ?? ''),
            ]);
            self::ack_remote_envelope($site_uid, $envelope_id, self::STATE_APPLIED, $run);
            $applied++;
        }

        self::append_diagnostic('command_client_pull_processed', [
            'site_uid' => $site_uid,
            'context' => sanitize_key((string) $context),
            'processed' => $processed,
            'applied' => $applied,
            'failed' => $failed,
        ]);
        return [
            'ok' => true,
            'site_uid' => $site_uid,
            'processed' => $processed,
            'applied' => $applied,
            'failed' => $failed,
        ];
    }

    /**
     * @param string $site_uid
     * @param int $limit
     * @return array<string, mixed>|\WP_Error
     */
    private static function pull_remote_envelopes($site_uid, $limit)
    {
        $remote = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $secret = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        if ($remote === '' || $username === '' || $secret === '') {
            return new \WP_Error('dbvc_bricks_client_pull_config_required', 'Client command pull requires mothership URL and credentials.', ['status' => 400]);
        }
        $url = $remote . '/wp-json/dbvc/v1/bricks/commands/pull';
        $response = wp_remote_post($url, [
            'timeout' => max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout', 30)),
            'sslverify' => DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_tls_verify', true),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $secret),
                'Content-Type' => 'application/json',
                'X-DBVC-Correlation-ID' => 'cmdq-pull-' . substr(hash('sha256', microtime(true) . self::random_seed()), 0, 10),
            ],
            'body' => wp_json_encode([
                'site_uid' => sanitize_key((string) $site_uid),
                'limit' => max(1, min(100, (int) $limit)),
            ]),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dbvc_bricks_client_pull_http_error', $response->get_error_message(), ['status' => 502]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            $body = ['raw' => (string) wp_remote_retrieve_body($response)];
        }
        if ($status < 200 || $status >= 300) {
            return new \WP_Error('dbvc_bricks_client_pull_failed', (string) ($body['message'] ?? 'Client command pull failed.'), ['status' => $status]);
        }
        return $body;
    }

    /**
     * @param string $site_uid
     * @param string $envelope_id
     * @param string $state
     * @param array<string, mixed> $result
     * @return true|\WP_Error
     */
    private static function ack_remote_envelope($site_uid, $envelope_id, $state, array $result = [])
    {
        $remote = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $secret = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        if ($remote === '' || $username === '' || $secret === '') {
            return new \WP_Error('dbvc_bricks_client_ack_config_required', 'Client command ack requires mothership URL and credentials.', ['status' => 400]);
        }
        $url = $remote . '/wp-json/dbvc/v1/bricks/commands/ack';
        $response = wp_remote_post($url, [
            'timeout' => max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout', 30)),
            'sslverify' => DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_tls_verify', true),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $secret),
                'Content-Type' => 'application/json',
                'Idempotency-Key' => 'ack-' . sanitize_key((string) $envelope_id) . '-' . sanitize_key((string) $state),
                'X-DBVC-Correlation-ID' => 'cmdq-ack-' . substr(hash('sha256', microtime(true) . self::random_seed()), 0, 10),
            ],
            'body' => wp_json_encode([
                'envelope_id' => sanitize_key((string) $envelope_id),
                'site_uid' => sanitize_key((string) $site_uid),
                'state' => sanitize_key((string) $state),
                'result' => $result,
            ]),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dbvc_bricks_client_ack_http_error', $response->get_error_message(), ['status' => 502]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            $message = is_array($data) ? (string) ($data['message'] ?? 'Client command ack failed.') : 'Client command ack failed.';
            return new \WP_Error('dbvc_bricks_client_ack_failed', $message, ['status' => $status]);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param string $local_site_uid
     * @return true|\WP_Error
     */
    private static function verify_client_envelope(array $envelope, $local_site_uid)
    {
        $envelope_id = sanitize_key((string) ($envelope['envelope_id'] ?? ''));
        if ($envelope_id === '') {
            return new \WP_Error('dbvc_bricks_client_envelope_id_missing', 'Envelope ID is required.', ['status' => 400]);
        }
        $site_uid = sanitize_key((string) ($envelope['site_uid'] ?? ''));
        if ($site_uid === '' || $site_uid !== sanitize_key((string) $local_site_uid)) {
            return new \WP_Error('dbvc_bricks_client_envelope_site_mismatch', 'Envelope site UID does not match local site.', ['status' => 403]);
        }
        $expires_at = (string) ($envelope['expires_at'] ?? '');
        if ($expires_at !== '' && strtotime($expires_at) !== false && strtotime($expires_at) < time()) {
            return new \WP_Error('dbvc_bricks_client_envelope_expired', 'Envelope has expired.', ['status' => 410]);
        }
        $secret = DBVC_Bricks_Addon::get_setting('dbvc_bricks_intro_handshake_token', '');
        if ($secret === '') {
            return new \WP_Error('dbvc_bricks_client_envelope_secret_missing', 'Client handshake token is missing.', ['status' => 400]);
        }
        $sig_meta = isset($envelope['signature_meta']) && is_array($envelope['signature_meta']) ? $envelope['signature_meta'] : [];
        $timestamp = (int) ($sig_meta['timestamp'] ?? 0);
        $nonce = sanitize_text_field((string) ($sig_meta['nonce'] ?? ''));
        if ($timestamp <= 0 || $nonce === '') {
            return new \WP_Error('dbvc_bricks_client_envelope_signature_meta_invalid', 'Envelope signature metadata is incomplete.', ['status' => 401]);
        }
        if (abs(time() - $timestamp) > DBVC_Bricks_Command_Auth::TIMESTAMP_WINDOW_SECONDS) {
            return new \WP_Error('dbvc_bricks_client_envelope_timestamp_invalid', 'Envelope signature timestamp is outside allowed window.', ['status' => 401]);
        }
        $sig_meta_site_uid = sanitize_key((string) ($sig_meta['site_uid'] ?? ''));
        if ($sig_meta_site_uid !== '' && $sig_meta_site_uid !== $site_uid) {
            return new \WP_Error('dbvc_bricks_client_envelope_signature_site_uid_mismatch', 'Envelope signature metadata site UID mismatch.', ['status' => 403]);
        }
        $payload = isset($envelope['payload']) && is_array($envelope['payload']) ? $envelope['payload'] : [];
        $raw_body = wp_json_encode($payload);
        if (! is_string($raw_body)) {
            $raw_body = '{}';
        }
        $payload_hash = sanitize_text_field((string) ($envelope['payload_hash'] ?? ''));
        $expected_payload_hash = 'sha256:' . hash('sha256', $raw_body);
        if ($payload_hash !== '' && ! hash_equals($expected_payload_hash, $payload_hash)) {
            return new \WP_Error('dbvc_bricks_client_envelope_payload_hash_invalid', 'Envelope payload hash mismatch.', ['status' => 401]);
        }
        $expected = DBVC_Bricks_Command_Auth::build_signature($secret, $timestamp, $nonce, $site_uid, $raw_body);
        $actual = sanitize_text_field((string) ($envelope['signature'] ?? ''));
        if ($actual === '' || ! hash_equals($expected, $actual)) {
            return new \WP_Error('dbvc_bricks_client_envelope_signature_invalid', 'Envelope signature validation failed.', ['status' => 401]);
        }
        $nonce_ok = self::validate_and_store_client_nonce($nonce, $timestamp, $envelope_id);
        if (is_wp_error($nonce_ok)) {
            return $nonce_ok;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param string $site_uid
     * @return array<string, mixed>|\WP_Error
     */
    private static function execute_client_envelope(array $envelope, $site_uid)
    {
        $command_type = sanitize_key((string) ($envelope['command_type'] ?? ''));
        if ($command_type !== 'shared_rules_apply') {
            return new \WP_Error('dbvc_bricks_client_command_type_unsupported', 'Unsupported envelope command type.', ['status' => 400]);
        }
        $payload = isset($envelope['payload']) && is_array($envelope['payload']) ? $envelope['payload'] : [];
        $raw_body = wp_json_encode($payload);
        if (! is_string($raw_body)) {
            $raw_body = '{}';
        }
        $secret = DBVC_Bricks_Addon::get_setting('dbvc_bricks_intro_handshake_token', '');
        if ($secret === '') {
            return new \WP_Error('dbvc_bricks_client_envelope_secret_missing', 'Client handshake token is missing.', ['status' => 400]);
        }
        $timestamp = time();
        $nonce = 'loc_' . substr(hash('sha256', microtime(true) . self::random_seed()), 0, 16);
        $signature = DBVC_Bricks_Command_Auth::build_signature($secret, $timestamp, $nonce, $site_uid, $raw_body);

        $request = new \WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile/apply');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-DBVC-Timestamp', (string) $timestamp);
        $request->set_header('X-DBVC-Nonce', $nonce);
        $request->set_header('X-DBVC-Site-UID', $site_uid);
        $request->set_header('X-DBVC-Signature', $signature);
        $request->set_body($raw_body);
        $response = DBVC_Bricks_Addon::post_shared_rules_profile_apply($request);

        if (is_wp_error($response)) {
            return $response;
        }
        if ($response instanceof \WP_REST_Response) {
            $status = (int) $response->get_status();
            $data = $response->get_data();
            if ($status < 200 || $status >= 300 || ! is_array($data) || empty($data['ok'])) {
                $message = is_array($data) ? (string) ($data['message'] ?? 'Client shared rules apply failed.') : 'Client shared rules apply failed.';
                return new \WP_Error('dbvc_bricks_client_apply_failed', $message, ['status' => $status]);
            }
            return [
                'receipt_id' => sanitize_text_field((string) ($data['distribution_id'] ?? '')),
                'applied_profile_version' => (int) ($data['applied_profile_version'] ?? 0),
            ];
        }
        return new \WP_Error('dbvc_bricks_client_apply_failed', 'Client shared rules apply response was invalid.', ['status' => 500]);
    }

    /**
     * @param string $envelope_id
     * @return bool
     */
    private static function is_envelope_processed($envelope_id)
    {
        $envelope_id = sanitize_key((string) $envelope_id);
        if ($envelope_id === '') {
            return false;
        }
        $processed = get_option(self::OPTION_PROCESSED, []);
        if (! is_array($processed)) {
            return false;
        }
        return isset($processed[$envelope_id]);
    }

    /**
     * @param string $envelope_id
     * @return void
     */
    private static function mark_envelope_processed($envelope_id)
    {
        $envelope_id = sanitize_key((string) $envelope_id);
        if ($envelope_id === '') {
            return;
        }
        $processed = get_option(self::OPTION_PROCESSED, []);
        if (! is_array($processed)) {
            $processed = [];
        }
        $processed[$envelope_id] = gmdate('c');
        if (count($processed) > 1000) {
            $processed = array_slice($processed, -1000, null, true);
        }
        update_option(self::OPTION_PROCESSED, $processed);
    }

    /**
     * @param string $nonce
     * @param int $timestamp
     * @param string $envelope_id
     * @return true|\WP_Error
     */
    private static function validate_and_store_client_nonce($nonce, $timestamp, $envelope_id)
    {
        $nonce = sanitize_text_field((string) $nonce);
        $timestamp = (int) $timestamp;
        $envelope_id = sanitize_key((string) $envelope_id);
        if ($nonce === '' || $timestamp <= 0 || $envelope_id === '') {
            return new \WP_Error('dbvc_bricks_client_envelope_nonce_invalid', 'Envelope nonce metadata is invalid.', ['status' => 401]);
        }

        $store = get_option(self::OPTION_CLIENT_NONCES, []);
        if (! is_array($store)) {
            $store = [];
        }

        $cutoff = time() - (DBVC_Bricks_Command_Auth::TIMESTAMP_WINDOW_SECONDS * 2);
        foreach ($store as $existing_nonce => $entry) {
            $seen_at = 0;
            if (is_array($entry)) {
                $seen_at = (int) ($entry['timestamp'] ?? 0);
            } elseif (is_numeric($entry)) {
                $seen_at = (int) $entry;
            }
            if ($seen_at < $cutoff) {
                unset($store[$existing_nonce]);
            }
        }

        $existing = $store[$nonce] ?? null;
        if (is_array($existing)) {
            $existing_envelope_id = sanitize_key((string) ($existing['envelope_id'] ?? ''));
            if ($existing_envelope_id !== '' && $existing_envelope_id !== $envelope_id) {
                return new \WP_Error('dbvc_bricks_client_envelope_nonce_replay', 'Envelope nonce replay detected.', ['status' => 409]);
            }
        }

        $store[$nonce] = [
            'timestamp' => $timestamp,
            'envelope_id' => $envelope_id,
        ];
        if (count($store) > self::MAX_CLIENT_NONCES) {
            uasort($store, static function ($a, $b) {
                $a_ts = is_array($a) ? (int) ($a['timestamp'] ?? 0) : 0;
                $b_ts = is_array($b) ? (int) ($b['timestamp'] ?? 0) : 0;
                return $a_ts <=> $b_ts;
            });
            $remove_count = count($store) - self::MAX_CLIENT_NONCES;
            $to_remove = array_slice(array_keys($store), 0, $remove_count);
            foreach ($to_remove as $old_nonce) {
                unset($store[$old_nonce]);
            }
        }
        update_option(self::OPTION_CLIENT_NONCES, $store);

        return true;
    }

    /**
     * @param array<string, int> $counts
     * @param string $classification
     * @return void
     */
    private static function increment_classification_count(array &$counts, $classification)
    {
        $classification = sanitize_key((string) $classification);
        if ($classification === '') {
            return;
        }
        $counts[$classification] = max(0, (int) ($counts[$classification] ?? 0)) + 1;
    }

    /**
     * @param string $incoming_site_uid
     * @param string $resolved_site_uid
     * @param string $error_code
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function build_blocked_enqueue_result($incoming_site_uid, $resolved_site_uid, $error_code, array $context = [])
    {
        $incoming_site_uid = sanitize_key((string) $incoming_site_uid);
        $resolved_site_uid = sanitize_key((string) $resolved_site_uid);
        $error_code = sanitize_key((string) $error_code);
        $result = [
            'site_uid' => $incoming_site_uid,
            'status' => 'failed',
            'error_code' => $error_code,
            'resolved_site_uid' => $resolved_site_uid,
            'classification' => sanitize_key((string) ($context['classification'] ?? self::classification_from_error_code($error_code))),
            'remediation_hint' => sanitize_text_field((string) ($context['remediation_hint'] ?? self::remediation_hint_for_error_code($error_code))),
        ];
        $canonical_site_uid = sanitize_key((string) ($context['canonical_site_uid'] ?? ''));
        if ($canonical_site_uid !== '') {
            $result['canonical_site_uid'] = $canonical_site_uid;
        }
        $normalized_base_url = sanitize_text_field((string) ($context['normalized_base_url'] ?? ''));
        if ($normalized_base_url !== '') {
            $result['normalized_base_url'] = $normalized_base_url;
        }
        return $result;
    }

    /**
     * @param string $error_code
     * @return string
     */
    private static function classification_from_error_code($error_code)
    {
        $error_code = sanitize_key((string) $error_code);
        $map = [
            'site_onboarding_pending_intro' => 'blocked_pending_intro',
            'command_secret_missing' => 'blocked_secret_missing',
            'site_uid_conflict_duplicate_base_url' => 'blocked_duplicate_conflict',
            'site_allow_receive_disabled' => 'blocked_allow_receive_disabled',
            'site_disabled' => 'blocked_site_disabled',
            'site_onboarding_disabled' => 'blocked_site_disabled',
            'site_onboarding_rejected' => 'blocked_site_disabled',
            'site_uid_not_found' => 'blocked_site_not_found',
            'site_uid_required' => 'blocked_site_uid_required',
        ];
        return isset($map[$error_code]) ? $map[$error_code] : 'blocked_unknown';
    }

    /**
     * @param string $error_code
     * @return string
     */
    private static function remediation_hint_for_error_code($error_code)
    {
        $error_code = sanitize_key((string) $error_code);
        if ($error_code === 'site_onboarding_pending_intro' || $error_code === 'command_secret_missing') {
            return 'Run client "Reset + Re-run Intro Handshake" and then re-enable the site on mothership.';
        }
        if ($error_code === 'site_uid_conflict_duplicate_base_url') {
            return 'Resolve duplicate URL identities by merge/deactivate alias or map known alias to canonical UID.';
        }
        if ($error_code === 'site_allow_receive_disabled') {
            return 'Enable "Allow receive packages" for this site in Connected Sites.';
        }
        if ($error_code === 'site_disabled' || $error_code === 'site_onboarding_disabled' || $error_code === 'site_onboarding_rejected') {
            return 'Re-enable site onboarding/linkage before enqueue.';
        }
        if ($error_code === 'site_uid_not_found') {
            return 'Refresh Connected Sites and verify the target site UID exists.';
        }
        return '';
    }

    /**
     * @param string $incoming_site_uid
     * @param string $resolved_site_uid
     * @param array<string, array<string, mixed>> $site_lookup
     * @return array<string, mixed>
     */
    private static function classify_enqueue_target($incoming_site_uid, $resolved_site_uid, array $site_lookup)
    {
        $incoming_site_uid = sanitize_key((string) $incoming_site_uid);
        $resolved_site_uid = sanitize_key((string) $resolved_site_uid);
        $base = [
            'ok' => false,
            'incoming_site_uid' => $incoming_site_uid,
            'resolved_site_uid' => $resolved_site_uid,
            'effective_site_uid' => $resolved_site_uid,
            'classification' => 'blocked_unknown',
            'error_code' => 'site_not_targetable',
            'remediation_hint' => '',
            'canonical_site_uid' => '',
            'normalized_base_url' => '',
        ];
        if ($resolved_site_uid === '' || ! isset($site_lookup[$resolved_site_uid]) || ! is_array($site_lookup[$resolved_site_uid])) {
            $base['classification'] = 'blocked_site_not_found';
            $base['error_code'] = 'site_uid_not_found';
            $base['remediation_hint'] = self::remediation_hint_for_error_code('site_uid_not_found');
            return $base;
        }
        if (! class_exists('DBVC_Bricks_Connected_Sites') || ! method_exists('DBVC_Bricks_Connected_Sites', 'get_enqueue_guard_status')) {
            $base['ok'] = true;
            $base['classification'] = $incoming_site_uid === $resolved_site_uid ? 'ready' : 'ready_alias_reroute';
            $base['error_code'] = '';
            return $base;
        }
        $guard = DBVC_Bricks_Connected_Sites::get_enqueue_guard_status($resolved_site_uid);
        if (is_array($guard) && ! empty($guard['ok'])) {
            $base['ok'] = true;
            $base['classification'] = $incoming_site_uid === $resolved_site_uid ? 'ready' : 'ready_alias_reroute';
            $base['error_code'] = '';
            return $base;
        }

        $error_code = sanitize_key((string) ($guard['error_code'] ?? 'site_not_targetable'));
        $base['classification'] = self::classification_from_error_code($error_code);
        $base['error_code'] = $error_code;
        $base['canonical_site_uid'] = sanitize_key((string) ($guard['canonical_site_uid'] ?? ''));
        $base['normalized_base_url'] = sanitize_text_field((string) ($guard['normalized_base_url'] ?? ''));
        $base['remediation_hint'] = self::remediation_hint_for_error_code($error_code);

        if ($error_code === 'site_uid_conflict_duplicate_base_url') {
            $canonical_site_uid = $base['canonical_site_uid'];
            if ($canonical_site_uid !== '' && self::can_auto_reroute_conflict_site($resolved_site_uid, $canonical_site_uid, $site_lookup)) {
                $canonical_guard = DBVC_Bricks_Connected_Sites::get_enqueue_guard_status($canonical_site_uid);
                if (is_array($canonical_guard) && ! empty($canonical_guard['ok'])) {
                    $base['ok'] = true;
                    $base['classification'] = 'ready_alias_reroute';
                    $base['effective_site_uid'] = $canonical_site_uid;
                    $base['error_code'] = '';
                    $base['remediation_hint'] = 'Auto-routed to canonical UID with deterministic identity evidence.';
                }
            }
        }

        return $base;
    }

    /**
     * @param string $alias_site_uid
     * @param string $canonical_site_uid
     * @param array<string, array<string, mixed>> $site_lookup
     * @return bool
     */
    private static function can_auto_reroute_conflict_site($alias_site_uid, $canonical_site_uid, array $site_lookup)
    {
        $alias_site_uid = sanitize_key((string) $alias_site_uid);
        $canonical_site_uid = sanitize_key((string) $canonical_site_uid);
        if ($alias_site_uid === '' || $canonical_site_uid === '' || $alias_site_uid === $canonical_site_uid) {
            return false;
        }
        if (! isset($site_lookup[$alias_site_uid]) || ! is_array($site_lookup[$alias_site_uid])) {
            return false;
        }
        if (! isset($site_lookup[$canonical_site_uid]) || ! is_array($site_lookup[$canonical_site_uid])) {
            return false;
        }
        if (! class_exists('DBVC_Bricks_Connected_Sites') || ! method_exists('DBVC_Bricks_Connected_Sites', 'normalize_base_url')) {
            return false;
        }
        $alias_site = $site_lookup[$alias_site_uid];
        $canonical_site = $site_lookup[$canonical_site_uid];
        $alias_url = DBVC_Bricks_Connected_Sites::normalize_base_url((string) ($alias_site['base_url'] ?? ''));
        $canonical_url = DBVC_Bricks_Connected_Sites::normalize_base_url((string) ($canonical_site['base_url'] ?? ''));
        if ($alias_url === '' || $canonical_url === '' || ! hash_equals($alias_url, $canonical_url)) {
            return false;
        }
        if (! self::site_has_recent_pull($canonical_site) || self::site_has_recent_pull($alias_site)) {
            return false;
        }
        $alias_instance = sanitize_text_field((string) ($alias_site['local_instance_uuid'] ?? ''));
        $canonical_instance = sanitize_text_field((string) ($canonical_site['local_instance_uuid'] ?? ''));
        if ($alias_instance !== '' && $canonical_instance !== '' && hash_equals($alias_instance, $canonical_instance)) {
            return true;
        }
        $alias_snapshot = sanitize_text_field((string) ($alias_site['site_title_host_snapshot'] ?? ''));
        $canonical_snapshot = sanitize_text_field((string) ($canonical_site['site_title_host_snapshot'] ?? ''));
        if ($alias_snapshot !== '' && $canonical_snapshot !== '' && hash_equals($alias_snapshot, $canonical_snapshot)) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $site
     * @return bool
     */
    private static function site_has_recent_pull(array $site)
    {
        $last_pull_at = sanitize_text_field((string) ($site['last_pull_at'] ?? ''));
        if ($last_pull_at === '') {
            return false;
        }
        $pull_ts = strtotime($last_pull_at);
        if ($pull_ts === false) {
            return false;
        }
        return $pull_ts >= (time() - self::RECENT_PULL_WINDOW_SECONDS);
    }

    /**
     * @return string
     */
    private static function random_seed()
    {
        if (function_exists('wp_rand')) {
            return (string) wp_rand();
        }
        return (string) mt_rand();
    }

    /**
     * @return void
     */
    private static function maybe_seed_client_pull_transport_mode()
    {
        $existing = get_option('dbvc_bricks_command_transport_mode', null);
        if ($existing !== null && (string) $existing !== '') {
            return;
        }
        $remote = trim((string) DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        $username = trim((string) DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', ''));
        $secret = trim((string) DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', ''));
        if ($remote === '' || $username === '' || $secret === '') {
            return;
        }
        update_option('dbvc_bricks_command_transport_mode', 'client_pull_envelope');
        self::append_diagnostic('command_transport_mode_seeded', [
            'mode' => 'client_pull_envelope',
            'context' => 'client_bootstrap',
        ]);
    }
}
