<?php

namespace Dbvc\Connected\Transport;

use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Storage\InboxStore;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Outbound inbox poll. The connector initiates every connection, so a
 * LocalWP site can receive while it runs and simply catches up later.
 * Items are stored durably before they are acknowledged, and the local
 * cursor advances only past stored+acknowledged deliveries. Received
 * observations are never applied to content.
 */
final class InboxWorker
{
    public const MAX_PAGES_PER_RUN = 5;

    /**
     * @var InboxStore
     */
    private $inbox;

    /**
     * @var StateStore
     */
    private $state;

    public function __construct(?InboxStore $inbox = null, ?StateStore $state = null)
    {
        $this->inbox = $inbox ?: new InboxStore();
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
     * @param array<string, mixed> $options context, limit (items per page).
     * @return array<string, mixed>
     */
    public function run(array $options = [])
    {
        $limit = isset($options['limit']) ? max(1, min(Protocol::MAX_INBOX_ITEMS, (int) $options['limit'])) : Protocol::MAX_INBOX_ITEMS;
        $summary = [
            'context' => isset($options['context']) ? substr((string) $options['context'], 0, 32) : 'manual',
            'started_at' => gmdate('c'),
            'finished_at' => null,
            'blocked' => null,
            'pages' => 0,
            'retrieved' => 0,
            'stored' => 0,
            'duplicates' => 0,
            'invalid' => 0,
            'acked' => 0,
            'recovered' => 0,
            'cursor_before' => null,
            'cursor_after' => null,
            'has_more' => false,
            'http_status' => null,
            'error' => '',
        ];

        $state = $this->state->get();
        if (! is_array($state) || $state['enrollment_state'] !== StateStore::ENROLLMENT_ENROLLED) {
            return $this->finish($summary, 'not_enrolled', null);
        }
        if ($state['connection_state'] === StateStore::CONNECTION_HELD) {
            return $this->finish($summary, 'held:' . $state['hold_reason'], null);
        }
        $secret = $this->state->hub_secret();
        if ($secret === null) {
            $this->state->set_connection_state(StateStore::CONNECTION_HELD, 'credentials_unreadable');
            return $this->finish($summary, 'held:credentials_unreadable', null);
        }
        $auth = ['username' => (string) $state['hub_principal'], 'password' => $secret];
        $hub_url = (string) $state['hub_url'];
        $cursor = (int) $state['inbox_cursor'];
        $summary['cursor_before'] = $cursor;

        // Recover a crash between store and ack before fetching more.
        $unacked = $this->inbox->unacked_delivery_ids(Protocol::MAX_INBOX_ITEMS);
        if ($unacked !== []) {
            $acked = $this->acknowledge($hub_url, $auth, $unacked, $summary);
            if ($acked === null) {
                return $this->finish($summary, null, $cursor);
            }
            // Everything up to the highest recovered delivery is now stored and acknowledged.
            $summary['recovered'] = count($unacked);
            $cursor = max($cursor, max($unacked));
            $this->state->record_inbox_progress($cursor, ['cursor' => $cursor, 'recovered' => count($unacked), 'updated_at' => gmdate('c')]);
        }

        for ($page = 0; $page < self::MAX_PAGES_PER_RUN; $page++) {
            $response = HubClient::get_json($hub_url, '/inbox', ['cursor' => $cursor, 'limit' => $limit], $auth);
            $summary['http_status'] = $response['status'];
            if (! $response['ok']) {
                $summary['error'] = $response['error_code'] . ': ' . $response['error'];
                if (in_array($response['error_code'], ['dbvc_agency_environment_held', 'dbvc_agency_environment_revoked', 'dbvc_agency_not_enrolled', 'dbvc_agency_app_password_required'], true)) {
                    $this->state->set_connection_state(StateStore::CONNECTION_HELD, $response['error_code']);
                    return $this->finish($summary, 'held:' . $response['error_code'], $cursor);
                }
                return $this->finish($summary, null, $cursor);
            }
            $summary['pages']++;
            $items = isset($response['body']['items']) && is_array($response['body']['items']) ? $response['body']['items'] : [];
            $summary['retrieved'] += count($items);
            $summary['has_more'] = ! empty($response['body']['has_more']);

            $stored_ids = [];
            $page_cursor = $cursor;
            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['delivery_id'], $item['event']) || ObservationEvent::validate($item['event']) !== []) {
                    $summary['invalid']++;
                    continue;
                }
                $result = $this->inbox->insert($item, $hub_url);
                if ($result === 'inserted') {
                    $summary['stored']++;
                    $stored_ids[] = (int) $item['delivery_id'];
                } elseif ($result === 'duplicate') {
                    $summary['duplicates']++;
                    $stored_ids[] = (int) $item['delivery_id'];
                } else {
                    // Storage failure: do not advance past this item.
                    return $this->finish($summary, 'storage_error', $cursor);
                }
                $page_cursor = max($page_cursor, (int) $item['delivery_id']);
            }

            if ($stored_ids !== []) {
                $acked = $this->acknowledge($hub_url, $auth, $stored_ids, $summary);
                if ($acked === null) {
                    // Stored but not acknowledged: keep the cursor; the next run acks first.
                    return $this->finish($summary, null, $cursor);
                }
            }
            $cursor = max($cursor, (int) ($response['body']['next_cursor'] ?? $page_cursor));
            $this->state->record_inbox_progress($cursor, ['cursor' => $cursor, 'updated_at' => gmdate('c')]);

            if (! $summary['has_more']) {
                break;
            }
        }

        return $this->finish($summary, null, $cursor);
    }

    /**
     * @param string               $hub_url
     * @param array<string, mixed> $auth
     * @param array<int, int>      $delivery_ids
     * @param array<string, mixed> $summary
     * @return int|null Acked count, or null on transport failure.
     */
    private function acknowledge($hub_url, array $auth, array $delivery_ids, array &$summary)
    {
        $response = HubClient::post_json($hub_url, '/inbox/ack', ['delivery_ids' => array_values($delivery_ids)], $auth);
        $summary['http_status'] = $response['status'];
        if (! $response['ok']) {
            $summary['error'] = $response['error_code'] . ': ' . $response['error'];
            return null;
        }
        $confirmed = [];
        foreach ((array) ($response['body']['outcomes'] ?? []) as $outcome) {
            if (is_array($outcome) && isset($outcome['delivery_id']) && in_array((string) ($outcome['outcome'] ?? ''), ['acked', 'already_acked'], true)) {
                $confirmed[] = (int) $outcome['delivery_id'];
            }
        }
        $summary['acked'] += $this->inbox->mark_acked($confirmed);

        return count($confirmed);
    }

    /**
     * @param array<string, mixed> $summary
     * @param string|null          $blocked
     * @param int|null             $cursor
     * @return array<string, mixed>
     */
    private function finish(array $summary, $blocked, $cursor)
    {
        $summary['blocked'] = $blocked;
        $summary['cursor_after'] = $cursor;
        $summary['finished_at'] = gmdate('c');
        $summary['inbox'] = $this->inbox->counts();
        if ($cursor !== null) {
            $this->state->record_inbox_progress($cursor, $summary);
        }
        if ($blocked === null) {
            DirtyCapture::schedule_inbox_poll($summary['has_more'] ? 1 : null);
        }

        return $summary;
    }
}
