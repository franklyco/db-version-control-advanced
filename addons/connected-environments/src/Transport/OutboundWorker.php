<?php

namespace Dbvc\Connected\Transport;

use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Outbound batch delivery. Leases pending outbox events of the current
 * epoch in sequence order, sends them with the same immutable ids/bodies on
 * every attempt, and settles each event from the hub's per-event outcome:
 * accepted/duplicate → delivered, conflict/rejected → rejected (held for an
 * operator). Transport failures release the lease with backoff. A held or
 * epoch-mismatched enrollment stops delivery until re-enrollment/release.
 */
final class OutboundWorker
{
    public const DEFAULT_LEASE_SECONDS = 120;
    public const RETRY_BASE_SECONDS = 60;
    public const RETRY_MAX_SECONDS = 3600;

    /**
     * @var OutboxStore
     */
    private $outbox;

    /**
     * @var StateStore
     */
    private $state;

    public function __construct(?OutboxStore $outbox = null, ?StateStore $state = null)
    {
        $this->outbox = $outbox ?: new OutboxStore();
        $this->state = $state ?: new StateStore();
    }

    /**
     * @return void
     */
    public function run_from_cron()
    {
        $this->run(['context' => 'cron']);
    }

    /**
     * @param array<string, mixed> $options limit, context.
     * @return array<string, mixed>
     */
    public function run(array $options = [])
    {
        $limit = isset($options['limit']) ? max(1, min(Protocol::MAX_BATCH_EVENTS, (int) $options['limit'])) : Protocol::MAX_BATCH_EVENTS;
        $summary = [
            'context' => isset($options['context']) ? substr((string) $options['context'], 0, 32) : 'manual',
            'started_at' => gmdate('c'),
            'finished_at' => null,
            'blocked' => null,
            'batch_id' => null,
            'sent' => 0,
            'delivered' => 0,
            'duplicate' => 0,
            'rejected' => 0,
            'released' => 0,
            'http_status' => null,
            'error' => '',
            'pending_after' => null,
        ];

        $state = $this->state->get();
        if (! is_array($state) || $state['enrollment_state'] !== StateStore::ENROLLMENT_ENROLLED) {
            return $this->finish($summary, 'not_enrolled');
        }
        if ($state['connection_state'] === StateStore::CONNECTION_HELD) {
            return $this->finish($summary, 'held:' . $state['hold_reason']);
        }
        $secret = $this->state->hub_secret();
        if ($secret === null) {
            $this->state->set_connection_state(StateStore::CONNECTION_HELD, 'credentials_unreadable');
            return $this->finish($summary, 'held:credentials_unreadable');
        }

        $rows = $this->outbox->claim_pending((string) $state['installation_epoch'], $limit, self::DEFAULT_LEASE_SECONDS);
        $rows = $this->trim_to_byte_limit($rows);
        if ($rows === []) {
            return $this->finish($summary, null);
        }
        $lease_token = (string) $rows[0]['lease_token'];
        $summary['sent'] = count($rows);
        $summary['batch_id'] = 'batch-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));

        $events = [];
        foreach ($rows as $row) {
            $events[] = $row['body'];
        }
        $response = HubClient::post_json((string) $state['hub_url'], '/observations', [
            'batch_id' => $summary['batch_id'],
            'events' => $events,
        ], ['username' => (string) $state['hub_principal'], 'password' => $secret]);
        $summary['http_status'] = $response['status'];

        if (! $response['ok']) {
            $summary['error'] = $response['error_code'] . ': ' . $response['error'];
            $summary['released'] = $this->outbox->release($lease_token, time() + $this->backoff((int) $rows[0]['attempts']));
            if (in_array($response['error_code'], ['dbvc_agency_environment_held', 'dbvc_agency_environment_revoked', 'dbvc_agency_not_enrolled', 'dbvc_agency_app_password_required'], true)) {
                $this->state->set_connection_state(StateStore::CONNECTION_HELD, $response['error_code']);
                return $this->finish($summary, 'held:' . $response['error_code']);
            }
            return $this->finish($summary, null);
        }

        $by_id = [];
        foreach ($rows as $row) {
            $by_id[(string) $row['event_id']] = $row;
        }
        $epoch_mismatch = false;
        foreach ((array) ($response['body']['outcomes'] ?? []) as $outcome) {
            if (! is_array($outcome) || ! isset($by_id[(string) ($outcome['event_id'] ?? '')])) {
                continue;
            }
            $row = $by_id[(string) $outcome['event_id']];
            $receipt = [
                'outcome' => (string) ($outcome['outcome'] ?? ''),
                'reason' => (string) ($outcome['reason'] ?? ''),
                'batch_id' => $summary['batch_id'],
                'received_at' => (string) ($response['body']['received_at'] ?? ''),
            ];
            switch ($receipt['outcome']) {
                case Protocol::OUTCOME_ACCEPTED:
                case Protocol::OUTCOME_DUPLICATE:
                    $this->outbox->settle($row['outbox_id'], $lease_token, OutboxStore::STATE_DELIVERED, $receipt);
                    $summary[$receipt['outcome'] === Protocol::OUTCOME_ACCEPTED ? 'delivered' : 'duplicate']++;
                    break;
                default:
                    $this->outbox->settle($row['outbox_id'], $lease_token, OutboxStore::STATE_REJECTED, $receipt);
                    $summary['rejected']++;
                    if ($receipt['reason'] === 'epoch_mismatch') {
                        $epoch_mismatch = true;
                    }
            }
            unset($by_id[(string) $outcome['event_id']]);
        }
        // Anything the hub did not answer for stays pending.
        if ($by_id !== []) {
            $summary['released'] = $this->outbox->release($lease_token, time() + $this->backoff((int) $rows[0]['attempts']));
        }
        if ($epoch_mismatch) {
            $this->state->set_connection_state(StateStore::CONNECTION_HELD, 'epoch_mismatch');
            return $this->finish($summary, 'held:epoch_mismatch');
        }

        return $this->finish($summary, null);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function trim_to_byte_limit(array $rows)
    {
        $bytes = 64;
        $kept = [];
        $overflow_token = null;
        foreach ($rows as $row) {
            $size = strlen((string) $row['immutable_body']) + 1;
            if ($kept !== [] && $bytes + $size > Protocol::MAX_BATCH_BYTES) {
                $overflow_token = (string) $row['lease_token'];
                continue;
            }
            $bytes += $size;
            $kept[] = $row;
        }
        if ($overflow_token !== null) {
            // Release the rows beyond the byte budget for the next run; the leased token is shared, so re-lease only the kept ids.
            global $wpdb;
            $table = \Dbvc\Connected\Storage\Schema::table('outbox');
            $kept_ids = array_map(static function ($row) {
                return (int) $row['outbox_id'];
            }, $kept);
            $placeholders = implode(',', array_fill(0, count($kept_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET lease_token = NULL, lease_until = NULL, attempts = GREATEST(attempts, 1) - 1 WHERE lease_token = %s AND outbox_id NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                array_merge([$overflow_token], $kept_ids)
            ));
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $summary
     * @param string|null          $blocked
     * @return array<string, mixed>
     */
    private function finish(array $summary, $blocked)
    {
        $summary['blocked'] = $blocked;
        $summary['finished_at'] = gmdate('c');
        $summary['pending_after'] = $this->outbox->counts();
        if ($blocked === null && (int) ($summary['pending_after'][OutboxStore::STATE_PENDING] ?? 0) > 0) {
            DirtyCapture::schedule_delivery(DirtyCapture::processing_delay());
        }
        $this->state->record_delivery_status($summary);

        return $summary;
    }

    /**
     * @param int $attempts
     * @return int
     */
    private function backoff($attempts)
    {
        $exponent = max(0, min(6, $attempts - 1));
        $seconds = self::RETRY_BASE_SECONDS * (2 ** $exponent);

        return (int) min(self::RETRY_MAX_SECONDS, $seconds + wp_rand(0, (int) max(1, $seconds / 5)));
    }
}
