<?php

namespace Dbvc\Connected\Transport;

use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Apply\Applier;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Preparation\MediaReferences;
use Dbvc\Connected\Preparation\Preparer;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\OperationStore;
use Dbvc\Connected\Storage\PreparationStore;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Connector-initiated release work, outbound only:
 *  1. As a release source, answer the hub's payload requests with the
 *     canonical bodies already recorded for the observed objects — only
 *     when the stored hash still equals the manifest hash under the
 *     current epoch; otherwise report the mismatch.
 *  2. As a prepare target, retrieve outstanding prepare requests, run the
 *     local dry run, store the receipt durably, then report it (a receipt
 *     stored but not yet accepted by the hub is re-sent first).
 * Neither path writes content.
 */
final class ReleaseWorker
{
    /**
     * @var ObjectStore
     */
    private $objects;

    /**
     * @var PreparationStore
     */
    private $preparations;

    /**
     * @var StateStore
     */
    private $state;

    /**
     * @var Preparer
     */
    private $preparer;

    /**
     * @var OperationStore
     */
    private $operations;

    /**
     * @var Applier
     */
    private $applier;

    public function __construct(?ObjectStore $objects = null, ?PreparationStore $preparations = null, ?StateStore $state = null, ?Preparer $preparer = null, ?OperationStore $operations = null, ?Applier $applier = null)
    {
        $this->objects = $objects ?: new ObjectStore();
        $this->preparations = $preparations ?: new PreparationStore();
        $this->state = $state ?: new StateStore();
        $this->preparer = $preparer ?: new Preparer();
        $this->operations = $operations ?: new OperationStore();
        $this->applier = $applier ?: new Applier($this->operations, $this->preparations);
    }

    /**
     * @return void
     */
    public function run_from_cron()
    {
        $this->run(['context' => 'cron']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(array $options = [])
    {
        $summary = [
            'context' => isset($options['context']) ? substr((string) $options['context'], 0, 32) : 'manual',
            'started_at' => gmdate('c'),
            'finished_at' => null,
            'blocked' => null,
            'payload_requests' => 0,
            'payloads_sent' => 0,
            'payloads_mismatched' => 0,
            'payloads_skipped' => 0,
            'prepare_requests' => 0,
            'receipts_stored' => 0,
            'receipts_reported' => 0,
            'receipts_recovered' => 0,
            'receipts' => [],
            'apply_enabled' => false,
            'apply_requests' => 0,
            'executions' => 0,
            'executions_reported' => 0,
            'executions_recovered' => 0,
            'operations' => [],
            'http_status' => null,
            'error' => '',
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
        $auth = ['username' => (string) $state['hub_principal'], 'password' => $secret];
        $hub_url = (string) $state['hub_url'];

        $blocked = $this->serve_payload_requests($hub_url, $auth, $state, $summary);
        if ($blocked !== null) {
            return $this->finish($summary, $blocked);
        }
        $blocked = $this->report_unreported_receipts($hub_url, $auth, $summary);
        if ($blocked !== null) {
            return $this->finish($summary, $blocked);
        }
        $blocked = $this->serve_prepare_requests($hub_url, $auth, $state, $summary);
        if ($blocked !== null) {
            return $this->finish($summary, $blocked);
        }
        $summary['apply_enabled'] = class_exists('DBVC_Connected_Environments_Addon') && \DBVC_Connected_Environments_Addon::is_apply_enabled();
        if ($summary['apply_enabled']) {
            $blocked = $this->report_unreported_executions($hub_url, $auth, $summary);
            if ($blocked !== null) {
                return $this->finish($summary, $blocked);
            }
            $blocked = $this->serve_apply_requests($hub_url, $auth, $state, $summary);
        }

        return $this->finish($summary, $blocked);
    }

    /**
     * @param string               $hub_url
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $summary
     * @return string|null
     */
    private function report_unreported_executions($hub_url, array $auth, array &$summary)
    {
        foreach ($this->operations->unreported(10) as $row) {
            if (! is_array($row['execution_receipt'])) {
                continue;
            }
            $error = $this->report_execution($hub_url, $auth, $row['execution_receipt'], $summary);
            if ($error === null) {
                $summary['executions_recovered']++;
            } elseif (strpos($error, 'transport:') === 0) {
                return substr($error, 10);
            }
        }

        return null;
    }

    /**
     * Approved executions for this target: each one runs at most once (the journal is the guard) and is reported after it is journalled.
     *
     * @param string               $hub_url
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $state
     * @param array<string, mixed> $summary
     * @return string|null
     */
    private function serve_apply_requests($hub_url, array $auth, array $state, array &$summary)
    {
        $response = HubClient::get_json($hub_url, '/releases/apply-requests', [], $auth);
        $summary['http_status'] = $response['status'];
        if (! $response['ok']) {
            return $this->transport_failure($response, $summary);
        }
        $approvals = isset($response['body']['approvals']) && is_array($response['body']['approvals']) ? $response['body']['approvals'] : [];
        $summary['apply_requests'] = count($approvals);
        foreach ($approvals as $approval) {
            if (! is_array($approval) || empty($approval['operation_id']) || empty($approval['approval_uid'])) {
                continue;
            }
            if ($this->operations->find((string) $approval['operation_id']) !== null) {
                continue; // Executed (or refused) already; the unreported path resends its receipt.
            }
            $receipt = ($approval['kind'] ?? 'apply') === 'rollback'
                ? $this->applier->rollback($approval, $state, $hub_url)
                : $this->applier->apply($approval, $state, $hub_url);
            $summary['executions']++;
            $summary['operations'][] = ['operation_id' => $receipt['operation_id'], 'release_uid' => $receipt['release_uid'], 'outcome' => $receipt['outcome'], 'counts' => $receipt['counts']];
            $error = $this->report_execution($hub_url, $auth, $receipt, $summary);
            if ($error !== null && strpos($error, 'transport:') === 0) {
                return substr($error, 10);
            }
        }

        return null;
    }

    /**
     * @param string               $hub_url
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $summary
     * @return string|null
     */
    private function report_execution($hub_url, array $auth, array $receipt, array &$summary)
    {
        $post = HubClient::post_json($hub_url, '/releases/' . rawurlencode((string) $receipt['release_uid']) . '/apply-receipts', ['receipt' => $receipt], $auth);
        $summary['http_status'] = $post['status'];
        if ($post['ok']) {
            $this->operations->mark_reported((string) $receipt['operation_id']);
            $summary['executions_reported']++;
            return null;
        }
        $code = (string) $post['error_code'];
        if (in_array($code, ['dbvc_agency_approval_not_found', 'dbvc_agency_approval_closed', 'dbvc_agency_invalid_receipt'], true)) {
            $this->operations->mark_reported((string) $receipt['operation_id'], $code);
            $summary['error'] = $code . ': ' . $post['error'];
            return $code;
        }
        $this->operations->mark_report_failed((string) $receipt['operation_id'], $code);

        return 'transport:' . $this->transport_failure($post, $summary);
    }

    /**
     * @param string               $hub_url
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $state
     * @param array<string, mixed> $summary
     * @return string|null Blocker.
     */
    private function serve_payload_requests($hub_url, array $auth, array $state, array &$summary)
    {
        $response = HubClient::get_json($hub_url, '/releases/payload-requests', [], $auth);
        $summary['http_status'] = $response['status'];
        if (! $response['ok']) {
            return $this->transport_failure($response, $summary);
        }
        $items = isset($response['body']['items']) && is_array($response['body']['items']) ? $response['body']['items'] : [];
        $summary['payload_requests'] = count($items);
        if ($items === []) {
            return null;
        }

        $batches = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['release_uid'], $item['domain'], $item['instance_uid'], $item['profile'], $item['after_hash'])) {
                $summary['payloads_skipped']++;
                continue;
            }
            $row = $this->objects->get((string) $item['domain'], (string) $item['instance_uid'], (string) $item['profile']);
            $payload = ['domain' => (string) $item['domain'], 'instance_uid' => (string) $item['instance_uid'], 'profile' => (string) $item['profile']];
            $deletion = (string) ($item['operation'] ?? 'replace') === 'delete';
            // A deletion's payload is the canonical absence (`null`), sent only while the object is still verified absent here.
            $body = is_array($row) ? ($deletion ? Canonicalizer::encode(null) : (string) ($row['snapshot_body'] ?? '')) : '';
            $expected_exists = $deletion ? 0 : 1;
            if (! is_array($row) || $row['observed_epoch'] !== $state['installation_epoch'] || $row['object_exists'] !== $expected_exists || $row['snapshot_complete'] !== 1
                || $row['semantic_hash'] !== (string) $item['after_hash'] || $body === '' || Canonicalizer::hash($body) !== (string) $item['after_hash']) {
                // The object changed (or was never complete) since the manifest was fixed: say so, never send a different body.
                $payload['status'] = 'mismatch';
                $payload['current_hash'] = is_array($row) ? (string) $row['semantic_hash'] : '';
                $summary['payloads_mismatched']++;
            } else {
                $payload['body'] = $body;
                $summary['payloads_sent']++;
                // A service post's referenced attachments ride as a sibling media channel
                // (bytes out-of-body, so the after_hash contract is untouched).
                if (! $deletion && (string) $item['domain'] === DomainRegistry::DOMAIN_WP_SERVICE) {
                    $after = json_decode($body, true);
                    if (is_array($after)) {
                        $bundle = MediaReferences::collect_for_transport($after);
                        if ($bundle['media'] !== []) {
                            $payload['media'] = $bundle['media'];
                            $summary['media_sent'] = ($summary['media_sent'] ?? 0) + count($bundle['media']);
                        }
                        if ($bundle['deferred'] !== []) {
                            $payload['media_deferred'] = $bundle['deferred'];
                            $summary['media_deferred'] = ($summary['media_deferred'] ?? 0) + count($bundle['deferred']);
                        }
                    }
                }
            }
            $batches[(string) $item['release_uid']][] = $payload;
        }

        foreach ($batches as $release_uid => $payloads) {
            foreach ($this->chunk_payloads($payloads) as $chunk) {
                $post = HubClient::post_json($hub_url, '/releases/' . rawurlencode($release_uid) . '/payloads', ['items' => $chunk], $auth);
                $summary['http_status'] = $post['status'];
                if (! $post['ok']) {
                    return $this->transport_failure($post, $summary);
                }
            }
        }

        return null;
    }

    /**
     * Group payloads into POSTs bounded by item count and request size: a
     * media-bearing payload (its inline base64 bytes make it large) is posted
     * on its own, so one request never carries more than a single item's media
     * — well within the per-item media cap. Body-only payloads pack up to
     * MAX_PAYLOAD_ITEMS per request as before.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function chunk_payloads(array $payloads)
    {
        $chunks = [];
        $current = [];
        foreach ($payloads as $payload) {
            if (! empty($payload['media'])) {
                if ($current !== []) {
                    $chunks[] = $current;
                    $current = [];
                }
                $chunks[] = [$payload];
                continue;
            }
            $current[] = $payload;
            if (count($current) >= Protocol::MAX_PAYLOAD_ITEMS) {
                $chunks[] = $current;
                $current = [];
            }
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param string               $hub_url
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $summary
     * @return string|null
     */
    private function report_unreported_receipts($hub_url, array $auth, array &$summary)
    {
        foreach ($this->preparations->unreported(10) as $row) {
            if (! is_array($row['receipt'])) {
                continue;
            }
            $error = $this->report($hub_url, $auth, $row['receipt'], $summary);
            if ($error === null) {
                $summary['receipts_recovered']++;
            } elseif (strpos($error, 'transport:') === 0) {
                return substr($error, 10);
            }
        }

        return null;
    }

    /**
     * @param string               $hub_url
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $state
     * @param array<string, mixed> $summary
     * @return string|null
     */
    private function serve_prepare_requests($hub_url, array $auth, array $state, array &$summary)
    {
        $response = HubClient::get_json($hub_url, '/releases/prepare-requests', [], $auth);
        $summary['http_status'] = $response['status'];
        if (! $response['ok']) {
            return $this->transport_failure($response, $summary);
        }
        $requests = isset($response['body']['requests']) && is_array($response['body']['requests']) ? $response['body']['requests'] : [];
        $summary['prepare_requests'] = count($requests);
        foreach ($requests as $request) {
            if (! is_array($request) || empty($request['operation_id']) || ! is_array($request['release'] ?? null)) {
                continue;
            }
            if ($this->preparations->find((string) $request['operation_id']) !== null) {
                continue; // Already prepared; the unreported path resends it.
            }
            $receipt = $this->preparer->prepare($request, $state);
            $stored = $this->preparations->insert($receipt, $hub_url);
            if ($stored === 'error') {
                return 'storage_error';
            }
            $summary['receipts_stored']++;
            $summary['receipts'][] = ['operation_id' => $receipt['operation_id'], 'release_uid' => $receipt['release_uid'], 'outcome' => $receipt['outcome'], 'counts' => $receipt['counts']];
            $error = $this->report($hub_url, $auth, $receipt, $summary);
            if ($error !== null && strpos($error, 'transport:') === 0) {
                return substr($error, 10);
            }
        }

        return null;
    }

    /**
     * @param string               $hub_url
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $summary
     * @return string|null Null when accepted; `transport:<reason>` or the hub's rejection code.
     */
    private function report($hub_url, array $auth, array $receipt, array &$summary)
    {
        $post = HubClient::post_json($hub_url, '/releases/' . rawurlencode((string) $receipt['release_uid']) . '/prepare-receipts', ['receipt' => $receipt], $auth);
        $summary['http_status'] = $post['status'];
        if ($post['ok']) {
            $this->preparations->mark_reported((string) $receipt['operation_id']);
            $summary['receipts_reported']++;
            return null;
        }
        $code = (string) $post['error_code'];
        if (in_array($code, ['dbvc_agency_preparation_not_found', 'dbvc_agency_preparation_closed', 'dbvc_agency_invalid_receipt', 'dbvc_agency_release_not_found'], true)) {
            // The hub will never accept this receipt; keep it as local evidence and stop resending.
            $this->preparations->mark_reported((string) $receipt['operation_id'], $code);
            $summary['error'] = $code . ': ' . $post['error'];
            return $code;
        }
        $this->preparations->mark_report_failed((string) $receipt['operation_id'], $code);

        return 'transport:' . $this->transport_failure($post, $summary);
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $summary
     * @return string
     */
    private function transport_failure(array $response, array &$summary)
    {
        $summary['error'] = $response['error_code'] . ': ' . $response['error'];
        if (in_array($response['error_code'], ['dbvc_agency_environment_held', 'dbvc_agency_environment_revoked', 'dbvc_agency_not_enrolled', 'dbvc_agency_app_password_required'], true)) {
            $this->state->set_connection_state(StateStore::CONNECTION_HELD, $response['error_code']);
            return 'held:' . $response['error_code'];
        }

        return 'transport:' . $response['error_code'];
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
        $summary['preparations'] = $this->preparations->counts();
        $summary['operations_journal'] = $this->operations->counts();
        $this->state->record_release_status($summary);

        return $summary;
    }
}
