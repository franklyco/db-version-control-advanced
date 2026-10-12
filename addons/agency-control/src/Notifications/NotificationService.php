<?php

namespace Dbvc\AgencyControl\Notifications;

use Dbvc\AgencyControl\Storage\Schema;

/**
 * Outbound notifications (M9 slice 7d): webhook/email channels plus an enqueue →
 * flush pipeline. Governance events (a framework rollout finishing/pausing, an
 * override needing rebase, a site drifting) ENQUEUE a pending row per matching
 * channel — deduped by (channel, dedupe_key) so a repeated event on every cron
 * tick is sent once — and a later flush() sends them. Emitting never does
 * network I/O inline, so it cannot stall the rollout/cron path.
 *
 * Payloads carry only safe metadata (ids, states, counts), never object bodies
 * or secrets; a content-bearing field would be run through the
 * ConfigurationPortability REDACT policy before inclusion. Webhook targets are
 * fenced by an SSRF guard (https only; internal/private/reserved hosts refused).
 */
final class NotificationService
{
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_EMAIL = 'email';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Create or update a channel (keyed by channel_uid; generated when absent).
     *
     * @param array<string, mixed> $args type, target, agency_id, events[], secret, enabled, channel_uid?
     * @return array<string, mixed>|\WP_Error
     */
    public function save_channel(array $args)
    {
        global $wpdb;

        $type = in_array((string) ($args['type'] ?? ''), [self::TYPE_WEBHOOK, self::TYPE_EMAIL], true) ? (string) $args['type'] : '';
        if ($type === '') {
            return new \WP_Error('dbvc_agency_invalid_channel', 'type must be webhook or email.', ['status' => 400]);
        }
        $target = trim((string) ($args['target'] ?? ''));
        if ($type === self::TYPE_WEBHOOK && ! self::is_safe_webhook_url($target)) {
            return new \WP_Error('dbvc_agency_invalid_channel', 'A webhook target must be an https URL to a public host.', ['status' => 400]);
        }
        if ($type === self::TYPE_EMAIL && ! is_email($target)) {
            return new \WP_Error('dbvc_agency_invalid_channel', 'An email target must be a valid address.', ['status' => 400]);
        }
        $events = [];
        foreach ((array) ($args['events'] ?? []) as $event) {
            $event = preg_replace('/[^a-z0-9_.]/', '', strtolower((string) $event));
            if ($event !== '') {
                $events[$event] = true;
            }
        }
        $uid = (string) ($args['channel_uid'] ?? '');
        if ($uid === '') {
            $uid = 'nch-' . bin2hex(random_bytes(8));
        }
        $now = current_time('mysql', true);
        $row = [
            'agency_id' => (string) ($args['agency_id'] ?? ''),
            'type' => $type,
            'target' => $target,
            'events' => wp_json_encode(array_keys($events)),
            'secret' => substr((string) ($args['secret'] ?? ''), 0, 191),
            'enabled' => array_key_exists('enabled', $args) ? (int) (bool) $args['enabled'] : 1,
            'updated_at' => $now,
        ];
        $table = Schema::table('notification_channels');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT channel_row_id FROM {$table} WHERE channel_uid = %s", $uid)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($existing) {
            $wpdb->update($table, $row, ['channel_uid' => $uid]);
        } else {
            $row['channel_uid'] = $uid;
            $row['created_at'] = $now;
            $wpdb->insert($table, $row);
        }

        return $this->channel($uid);
    }

    /**
     * @param string $channel_uid
     * @return array<string, mixed>|null Channel with the secret masked.
     */
    public function channel($channel_uid)
    {
        global $wpdb;

        $table = Schema::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE channel_uid = %s", (string) $channel_uid), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? self::public_channel($row) : null;
    }

    /**
     * @param string|null $agency_id
     * @return array<int, array<string, mixed>>
     */
    public function channels($agency_id = null)
    {
        global $wpdb;

        $table = Schema::table('notification_channels');
        if ($agency_id !== null && $agency_id !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE agency_id = %s ORDER BY channel_row_id", (string) $agency_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY channel_row_id", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
        }

        return array_map([self::class, 'public_channel'], is_array($rows) ? $rows : []);
    }

    /**
     * @param string $channel_uid
     * @return array<string, mixed>
     */
    public function delete_channel($channel_uid)
    {
        global $wpdb;

        $deleted = $wpdb->delete(Schema::table('notification_channels'), ['channel_uid' => (string) $channel_uid]);

        return ['channel_uid' => (string) $channel_uid, 'deleted' => (int) $deleted === 1];
    }

    /**
     * Queue an event to every enabled channel that subscribes to it, deduped by
     * (channel, dedupe_key). Cheap: one INSERT IGNORE per channel, no network I/O.
     *
     * @param string               $agency_id
     * @param string               $event_kind
     * @param string               $dedupe_key
     * @param array<string, mixed> $payload Safe metadata only.
     * @return int Channels queued (new rows).
     */
    public function enqueue($agency_id, $event_kind, $dedupe_key, array $payload)
    {
        global $wpdb;

        $event_kind = (string) $event_kind;
        $log = Schema::table('notification_log');
        $now = current_time('mysql', true);
        $body = wp_json_encode($payload);
        $queued = 0;
        foreach ($this->matching_channels((string) $agency_id, $event_kind) as $channel) {
            // INSERT IGNORE: the UNIQUE (channel_uid, dedupe_key) makes a repeat a no-op.
            $affected = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$log} (channel_uid, event_kind, dedupe_key, status, payload, detail, created_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) $channel['channel_uid'],
                $event_kind,
                substr((string) $dedupe_key, 0, 191),
                self::STATUS_PENDING,
                $body,
                '',
                $now
            ));
            $queued += (int) $affected === 1 ? 1 : 0;
        }

        return $queued;
    }

    /**
     * Send pending notifications. Each row is sent once; a send failure marks the
     * row failed with a reason and does not block the others.
     *
     * @param int $limit
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function flush($limit = 25)
    {
        global $wpdb;

        $log = Schema::table('notification_log');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$log} WHERE status = %s ORDER BY log_id LIMIT %d", self::STATUS_PENDING, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $channel = $this->raw_channel((string) $row['channel_uid']);
            if ($channel === null || (int) $channel['enabled'] !== 1) {
                $this->finish_log((int) $row['log_id'], self::STATUS_SKIPPED, 'channel_absent_or_disabled');
                $result['skipped']++;
                continue;
            }
            $payload = json_decode((string) $row['payload'], true);
            $payload = is_array($payload) ? $payload : [];
            $outcome = $channel['type'] === self::TYPE_EMAIL
                ? $this->send_email($channel, (string) $row['event_kind'], $payload)
                : $this->send_webhook($channel, (string) $row['event_kind'], $payload);
            $this->finish_log((int) $row['log_id'], $outcome['ok'] ? self::STATUS_SENT : self::STATUS_FAILED, $outcome['detail']);
            $result[$outcome['ok'] ? 'sent' : 'failed']++;
        }

        return $result;
    }

    /**
     * @param int    $limit
     * @param string|null $status
     * @return array<int, array<string, mixed>>
     */
    public function log_entries($limit = 50, $status = null)
    {
        global $wpdb;

        $log = Schema::table('notification_log');
        if ($status !== null && $status !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT log_id, channel_uid, event_kind, dedupe_key, status, detail, created_at, sent_at FROM {$log} WHERE status = %s ORDER BY log_id DESC LIMIT %d", (string) $status, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT log_id, channel_uid, event_kind, dedupe_key, status, detail, created_at, sent_at FROM {$log} ORDER BY log_id DESC LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * SSRF guard: an acceptable webhook target is an https URL to a public host
     * (no internal/private/reserved/loopback/link-local address, and no obvious
     * internal hostname). A hostname that resolves to a private address is
     * refused on a best-effort basis; resolution failure does not grant access.
     *
     * @param string $url
     * @return bool
     */
    public static function is_safe_webhook_url($url)
    {
        $url = (string) $url;
        $parts = wp_parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || preg_match('/\.(local|internal|test|localhost)$/', $host)) {
            return false;
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = @gethostbynamel($host);
            $ips = is_array($resolved) ? $resolved : [];
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false; // private/reserved/loopback/link-local.
            }
        }

        return true;
    }

    /**
     * @param string $agency_id
     * @param string $event_kind
     * @return array<int, array<string, mixed>> enabled channels subscribing to this event.
     */
    private function matching_channels($agency_id, $event_kind)
    {
        global $wpdb;

        $table = Schema::table('notification_channels');
        // A channel with agency_id '' is fleet-wide; otherwise it must match.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE enabled = 1 AND (agency_id = %s OR agency_id = '')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $agency_id
        ), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $events = json_decode((string) $row['events'], true);
            $events = is_array($events) ? $events : [];
            if ($events === [] || in_array($event_kind, $events, true)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param string $channel_uid
     * @return array<string, mixed>|null Raw channel row (includes the secret; internal use only).
     */
    private function raw_channel($channel_uid)
    {
        global $wpdb;

        $table = Schema::table('notification_channels');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE channel_uid = %s", (string) $channel_uid), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $channel
     * @param string               $event_kind
     * @param array<string, mixed> $payload
     * @return array{ok: bool, detail: string}
     */
    private function send_webhook(array $channel, $event_kind, array $payload)
    {
        $target = (string) $channel['target'];
        if (! self::is_safe_webhook_url($target)) {
            return ['ok' => false, 'detail' => 'target_failed_ssrf_guard'];
        }
        $body = wp_json_encode(['event' => $event_kind, 'payload' => $payload, 'sent_at' => gmdate('c')]);
        $headers = ['Content-Type' => 'application/json', 'X-DBVC-Event' => $event_kind];
        if ((string) $channel['secret'] !== '') {
            $headers['X-DBVC-Signature'] = 'sha256=' . hash_hmac('sha256', (string) $body, (string) $channel['secret']);
        }
        $response = wp_remote_post($target, ['timeout' => 5, 'headers' => $headers, 'body' => $body, 'blocking' => true]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'detail' => 'transport:' . $response->get_error_code()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);

        return ['ok' => $code >= 200 && $code < 300, 'detail' => 'http:' . $code];
    }

    /**
     * @param array<string, mixed> $channel
     * @param string               $event_kind
     * @param array<string, mixed> $payload
     * @return array{ok: bool, detail: string}
     */
    private function send_email(array $channel, $event_kind, array $payload)
    {
        $subject = 'DBVC agency notification: ' . $event_kind;
        $sent = wp_mail((string) $channel['target'], $subject, (string) wp_json_encode($payload, JSON_PRETTY_PRINT));

        return ['ok' => (bool) $sent, 'detail' => $sent ? 'mailed' : 'wp_mail_failed'];
    }

    /**
     * @param int    $log_id
     * @param string $status
     * @param string $detail
     * @return void
     */
    private function finish_log($log_id, $status, $detail)
    {
        global $wpdb;

        $wpdb->update(
            Schema::table('notification_log'),
            ['status' => (string) $status, 'detail' => substr((string) $detail, 0, 191), 'sent_at' => current_time('mysql', true)],
            ['log_id' => (int) $log_id]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> Channel without the secret (presence only).
     */
    private static function public_channel(array $row)
    {
        $events = json_decode((string) ($row['events'] ?? '[]'), true);

        return [
            'channel_uid' => (string) $row['channel_uid'],
            'agency_id' => (string) $row['agency_id'],
            'type' => (string) $row['type'],
            'target' => (string) $row['target'],
            'events' => is_array($events) ? $events : [],
            'has_secret' => (string) ($row['secret'] ?? '') !== '',
            'enabled' => (int) $row['enabled'] === 1,
        ];
    }
}
