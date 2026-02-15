<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Proposals
{
    public const OPTION_QUEUE = 'dbvc_bricks_proposals_queue';

    /**
     * @return array<int, string>
     */
    public static function statuses()
    {
        return ['DRAFT', 'SUBMITTED', 'RECEIVED', 'APPROVED', 'REJECTED', 'NEEDS_CHANGES'];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function transitions()
    {
        return [
            'DRAFT' => ['SUBMITTED'],
            'SUBMITTED' => ['RECEIVED'],
            'RECEIVED' => ['APPROVED', 'REJECTED', 'NEEDS_CHANGES'],
            'NEEDS_CHANGES' => ['SUBMITTED'],
            'APPROVED' => [],
            'REJECTED' => [],
        ];
    }

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
    public static function set_queue(array $queue)
    {
        update_option(self::OPTION_QUEUE, $queue);
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $actor_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function submit(array $payload, $actor_id)
    {
        $artifact_uid = isset($payload['artifact_uid']) ? sanitize_text_field((string) $payload['artifact_uid']) : '';
        $artifact_type = isset($payload['artifact_type']) ? sanitize_text_field((string) $payload['artifact_type']) : '';
        $base_hash = isset($payload['base_hash']) ? sanitize_text_field((string) $payload['base_hash']) : '';
        $proposed_hash = isset($payload['proposed_hash']) ? sanitize_text_field((string) $payload['proposed_hash']) : '';
        if ($artifact_uid === '' || $artifact_type === '' || $base_hash === '' || $proposed_hash === '') {
            return new \WP_Error('dbvc_bricks_proposal_invalid', 'Proposal requires artifact_uid, artifact_type, base_hash, and proposed_hash.', ['status' => 400]);
        }

        $queue = self::get_queue();
        foreach ($queue as $existing) {
            if (
                ($existing['artifact_uid'] ?? '') === $artifact_uid
                && ($existing['base_hash'] ?? '') === $base_hash
                && ($existing['proposed_hash'] ?? '') === $proposed_hash
            ) {
                return [
                    'deduplicated' => true,
                    'proposal' => $existing,
                ];
            }
        }

        $proposal_id = isset($payload['proposal_id']) && $payload['proposal_id'] !== ''
            ? sanitize_text_field((string) $payload['proposal_id'])
            : 'prop_' . wp_generate_password(10, false, false);

        $proposal = [
            'proposal_id' => $proposal_id,
            'artifact_uid' => $artifact_uid,
            'artifact_type' => $artifact_type,
            'base_hash' => $base_hash,
            'proposed_hash' => $proposed_hash,
            'status' => 'RECEIVED',
            'submitted_at' => gmdate('c'),
            'notes' => isset($payload['notes']) ? sanitize_textarea_field((string) $payload['notes']) : '',
            'history' => [
                [
                    'from' => 'SUBMITTED',
                    'to' => 'RECEIVED',
                    'actor_id' => (int) $actor_id,
                    'at' => gmdate('c'),
                ],
            ],
        ];
        $queue[$proposal_id] = $proposal;
        self::set_queue($queue);
        self::emit_transition_audit('SUBMITTED', 'RECEIVED', $proposal_id, (int) $actor_id);

        return [
            'deduplicated' => false,
            'proposal' => $proposal,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function list(array $filters = [])
    {
        $queue = array_values(self::get_queue());
        $status = isset($filters['status']) ? strtoupper(sanitize_text_field((string) $filters['status'])) : '';
        if ($status !== '' && in_array($status, self::statuses(), true)) {
            $queue = array_values(array_filter($queue, static function ($item) use ($status) {
                return ($item['status'] ?? '') === $status;
            }));
        }
        return $queue;
    }

    /**
     * @param string $proposal_id
     * @param string $next_status
     * @param array<string, mixed> $extra
     * @param int $actor_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function transition($proposal_id, $next_status, array $extra, $actor_id)
    {
        $proposal_id = sanitize_text_field((string) $proposal_id);
        $next_status = strtoupper(sanitize_text_field((string) $next_status));
        if (! in_array($next_status, self::statuses(), true)) {
            return new \WP_Error('dbvc_bricks_proposal_status_invalid', 'Invalid proposal status transition target.', ['status' => 400]);
        }

        $queue = self::get_queue();
        if (! isset($queue[$proposal_id])) {
            return new \WP_Error('dbvc_bricks_proposal_missing', 'Proposal not found.', ['status' => 404]);
        }

        $current = (string) ($queue[$proposal_id]['status'] ?? 'DRAFT');
        $allowed = self::transitions()[$current] ?? [];
        if (! in_array($next_status, $allowed, true)) {
            return new \WP_Error('dbvc_bricks_proposal_transition_invalid', 'Transition is not allowed.', ['status' => 400]);
        }

        $queue[$proposal_id]['status'] = $next_status;
        $queue[$proposal_id]['review_notes'] = isset($extra['review_notes'])
            ? sanitize_textarea_field((string) $extra['review_notes'])
            : (string) ($queue[$proposal_id]['review_notes'] ?? '');
        if (! isset($queue[$proposal_id]['history']) || ! is_array($queue[$proposal_id]['history'])) {
            $queue[$proposal_id]['history'] = [];
        }
        $queue[$proposal_id]['history'][] = [
            'from' => $current,
            'to' => $next_status,
            'actor_id' => (int) $actor_id,
            'at' => gmdate('c'),
        ];

        self::set_queue($queue);
        self::emit_transition_audit($current, $next_status, $proposal_id, (int) $actor_id);
        return $queue[$proposal_id];
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_submit(\WP_REST_Request $request)
    {
        $idempotency_key = class_exists('DBVC_Bricks_Idempotency')
            ? DBVC_Bricks_Idempotency::extract_key($request)
            : '';
        if ($idempotency_key !== '' && class_exists('DBVC_Bricks_Idempotency')) {
            $cached = DBVC_Bricks_Idempotency::get('proposal_submit', $idempotency_key);
            if (is_array($cached) && isset($cached['response']) && is_array($cached['response'])) {
                $cached_response = $cached['response'];
                $cached_response['idempotent_replay'] = true;
                return rest_ensure_response($cached_response);
            }
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $result = self::submit($params, get_current_user_id());
        if (is_wp_error($result)) {
            return $result;
        }
        if ($idempotency_key !== '' && class_exists('DBVC_Bricks_Idempotency')) {
            DBVC_Bricks_Idempotency::put('proposal_submit', $idempotency_key, $result);
        }
        return rest_ensure_response($result);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest_list(\WP_REST_Request $request)
    {
        return rest_ensure_response([
            'items' => self::list(['status' => $request->get_param('status')]),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_patch(\WP_REST_Request $request)
    {
        $proposal_id = (string) $request->get_param('proposal_id');
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $status = isset($params['status']) ? (string) $params['status'] : '';
        $result = self::transition($proposal_id, $status, $params, get_current_user_id());
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response($result);
    }

    /**
     * @param string $from
     * @param string $to
     * @param string $proposal_id
     * @param int $actor_id
     * @return void
     */
    private static function emit_transition_audit($from, $to, $proposal_id, $actor_id)
    {
        do_action('dbvc_bricks_proposal_transition', [
            'proposal_id' => $proposal_id,
            'from' => $from,
            'to' => $to,
            'actor_id' => $actor_id,
            'at' => gmdate('c'),
        ]);
    }
}
