<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Error;

final class MediaScanCoordinator
{
    public const SNAPSHOT_SCHEMA_VERSION = 1;
    public const SCANNER_VERSION = 1;
    private const DEFAULT_CHUNK_SIZE = 20;
    private const MAX_CHUNK_SIZE = 50;

    /**
     * @var ScanCandidateProvider
     */
    private $candidates;

    /**
     * @var MediaScanService
     */
    private $scanner;

    /**
     * @var ScanSnapshotStore
     */
    private $snapshots;

    /**
     * @var AcfMediaFieldCatalog
     */
    private $catalog;

    /**
     * @var int
     */
    private $chunk_size;

    public function __construct(
        ScanCandidateProvider $candidates,
        MediaScanService $scanner,
        ScanSnapshotStore $snapshots,
        AcfMediaFieldCatalog $catalog,
        $chunk_size = self::DEFAULT_CHUNK_SIZE
    ) {
        $this->candidates = $candidates;
        $this->scanner = $scanner;
        $this->snapshots = $snapshots;
        $this->catalog = $catalog;
        $this->chunk_size = max(1, min(self::MAX_CHUNK_SIZE, absint($chunk_size)));
    }

    /**
     * Start a new generation. A previous active generation is canceled on a
     * best-effort basis; even if it is currently locked, the latest-generation
     * pointer prevents it from advancing again after this snapshot is created.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function start()
    {
        $enabled = $this->requireEnabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $previous = $this->snapshots->loadLatest();
        if (! is_wp_error($previous) && in_array((string) ($previous['state'] ?? ''), ['scanning', 'failed'], true)) {
            $this->cancel(
                (string) $previous['scan_ref'],
                (string) $previous['generation'],
                absint($previous['revision'] ?? 0),
                'replaced'
            );
        }

        $sources = $this->candidates->getSources();
        $now = time();
        $snapshot = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'scanner_version' => self::SCANNER_VERSION,
            'config_fingerprint' => $this->configurationFingerprint($sources),
            'state' => 'scanning',
            'sources' => $sources,
            'cursor' => $this->candidates->initialCursor(),
            'progress' => [
                'processed' => 0,
                'total_estimate' => $this->candidates->estimateTotal($sources),
                'chunks' => 0,
                'attempts' => 0,
                'retry_count' => 0,
            ],
            'summary' => $this->emptySummary(),
            'diagnostics' => [
                'candidates_ineligible' => 0,
                'supported_fields_scanned' => 0,
                'unsupported_field_observations' => 0,
                'invalid_nonempty_values' => 0,
            ],
            'performance' => [
                'total_duration_ms' => 0.0,
                'max_duration_ms' => 0.0,
                'total_query_count' => 0,
                'max_memory_delta_bytes' => 0,
                'max_peak_memory_delta_bytes' => 0,
                'last_chunk' => [],
            ],
            'groups' => [],
            'last_error' => [],
            'started_at' => $now,
            'completed_at' => 0,
        ];

        return $this->snapshots->create($snapshot);
    }

    /**
     * @param string $scan_ref
     * @param string $generation
     * @param int    $expected_revision
     * @return array<string, mixed>|WP_Error
     */
    public function runNextChunk($scan_ref, $generation, $expected_revision)
    {
        $enabled = $this->requireEnabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $lock = $this->snapshots->acquireLock($scan_ref);
        if (is_wp_error($lock)) {
            return $lock;
        }

        try {
            $snapshot = $this->snapshots->load($scan_ref);
            if (is_wp_error($snapshot)) {
                return $snapshot;
            }

            $request_check = $this->validateRequest($snapshot, $generation, $expected_revision);
            if (is_wp_error($request_check)) {
                return $request_check;
            }
            if ($request_check === 'stale') {
                $snapshot['request_status'] = 'stale';

                return $snapshot;
            }

            if (! $this->snapshots->isLatest($scan_ref, $generation)) {
                return new WP_Error('media_scan_superseded', __('This media scan was replaced by a newer generation.', 'dbvc'));
            }

            $state = sanitize_key((string) ($snapshot['state'] ?? ''));
            if (in_array($state, ['complete', 'canceled', 'invalidated'], true)) {
                $snapshot['request_status'] = 'terminal';

                return $snapshot;
            }
            if ($state === 'failed') {
                $snapshot['request_status'] = 'retry_required';

                return $snapshot;
            }
            if ($state !== 'scanning') {
                return new WP_Error('media_scan_state_invalid', __('The media scan state is invalid.', 'dbvc'));
            }

            $current_sources = $this->candidates->getSources();
            if (! hash_equals(
                (string) ($snapshot['config_fingerprint'] ?? ''),
                $this->configurationFingerprint($current_sources)
            )) {
                $snapshot['state'] = 'invalidated';
                $snapshot['completed_at'] = time();
                $snapshot['last_error'] = [
                    'code' => 'configuration_changed',
                    'message' => __('Media scan eligibility changed. Start a fresh scan.', 'dbvc'),
                    'retryable' => false,
                ];

                return $this->saveWithStatus($snapshot, $expected_revision, 'invalidated');
            }

            $started_at = microtime(true);
            $memory_start = memory_get_usage(true);
            $peak_start = memory_get_peak_usage(true);
            $query_start = function_exists('get_num_queries') ? get_num_queries() : 0;
            $batch = $this->candidates->next(
                isset($snapshot['sources']) && is_array($snapshot['sources']) ? $snapshot['sources'] : [],
                isset($snapshot['cursor']) && is_array($snapshot['cursor']) ? $snapshot['cursor'] : [],
                $this->chunk_size
            );
            $scanned = $this->scanner->scan(
                isset($batch['candidates']) && is_array($batch['candidates']) ? $batch['candidates'] : [],
                (string) $snapshot['generation']
            );
            $metrics = $this->chunkMetrics(
                $started_at,
                $memory_start,
                $peak_start,
                $query_start,
                count($batch['candidates'] ?? []),
                absint($batch['source_queries'] ?? 0),
                is_wp_error($scanned) ? 0 : absint($scanned['counts']['findings'] ?? 0),
                is_wp_error($scanned) ? 'failed' : 'ok'
            );
            $snapshot['progress']['attempts'] = absint($snapshot['progress']['attempts'] ?? 0) + 1;
            $this->recordPerformance($snapshot, $metrics);

            if (is_wp_error($scanned)) {
                $data = $scanned->get_error_data();
                $snapshot['state'] = 'failed';
                $snapshot['last_error'] = [
                    'code' => sanitize_key((string) $scanned->get_error_code()),
                    'message' => sanitize_text_field((string) $scanned->get_error_message()),
                    'retryable' => is_array($data) && ! empty($data['retryable']),
                ];

                return $this->saveWithStatus($snapshot, $expected_revision, 'failed');
            }

            foreach (($scanned['groups'] ?? []) as $group_ref => $group) {
                $group_ref = sanitize_key((string) $group_ref);
                if ($group_ref !== '' && is_array($group)) {
                    $snapshot['groups'][$group_ref] = $group;
                }
            }

            $snapshot['cursor'] = isset($batch['cursor']) && is_array($batch['cursor'])
                ? $batch['cursor']
                : $snapshot['cursor'];
            $snapshot['progress']['processed'] = absint($snapshot['progress']['processed'] ?? 0)
                + count($batch['candidates'] ?? []);
            $snapshot['progress']['chunks'] = absint($snapshot['progress']['chunks'] ?? 0) + 1;
            $this->mergeDiagnosticCounts($snapshot, $scanned['counts'] ?? []);
            $snapshot['summary'] = $this->summarize($snapshot);
            $snapshot['last_error'] = [];

            if (! empty($batch['complete'])) {
                $snapshot['state'] = 'complete';
                $snapshot['completed_at'] = time();
            }

            $saved = $this->saveWithStatus(
                $snapshot,
                $expected_revision,
                $snapshot['state'] === 'complete' ? 'complete' : 'advanced'
            );
            if (! is_wp_error($saved) || $saved->get_error_code() !== 'media_scan_snapshot_too_large') {
                if (! is_wp_error($saved) && (string) ($saved['state'] ?? '') === 'complete') {
                    /**
                     * Fires once when a media scan reaches the `complete` state.
                     *
                     * @param array<string, mixed> $saved The completed snapshot.
                     */
                    do_action('dbvc_visual_editor_media_scan_completed', $saved);
                }

                return $saved;
            }

            $fallback = $this->snapshots->load($scan_ref);
            if (is_wp_error($fallback)) {
                return $saved;
            }
            $fallback['state'] = 'failed';
            $fallback['last_error'] = [
                'code' => 'snapshot_too_large',
                'message' => __('The media scan exceeded its safe snapshot size. Narrower storage is required before retrying.', 'dbvc'),
                'retryable' => false,
            ];
            $this->recordPerformance($fallback, $metrics);

            return $this->saveWithStatus($fallback, $expected_revision, 'failed');
        } finally {
            $this->snapshots->releaseLock($scan_ref, $lock);
        }
    }

    /**
     * @param string $scan_ref
     * @param string $generation
     * @param int    $expected_revision
     * @return array<string, mixed>|WP_Error
     */
    public function retry($scan_ref, $generation, $expected_revision)
    {
        $enabled = $this->requireEnabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $lock = $this->snapshots->acquireLock($scan_ref);
        if (is_wp_error($lock)) {
            return $lock;
        }

        try {
            $snapshot = $this->snapshots->load($scan_ref);
            if (is_wp_error($snapshot)) {
                return $snapshot;
            }

            $request_check = $this->validateRequest($snapshot, $generation, $expected_revision);
            if (is_wp_error($request_check)) {
                return $request_check;
            }
            if ($request_check === 'stale') {
                $snapshot['request_status'] = 'stale';

                return $snapshot;
            }

            if (! $this->snapshots->isLatest($scan_ref, $generation)) {
                return new WP_Error('media_scan_superseded', __('This media scan was replaced by a newer generation.', 'dbvc'));
            }

            if (($snapshot['state'] ?? '') !== 'failed' || empty($snapshot['last_error']['retryable'])) {
                return new WP_Error('media_scan_not_retryable', __('This media scan cannot retry its current state.', 'dbvc'));
            }

            $current_sources = $this->candidates->getSources();
            if (! hash_equals(
                (string) ($snapshot['config_fingerprint'] ?? ''),
                $this->configurationFingerprint($current_sources)
            )) {
                return new WP_Error('media_scan_configuration_changed', __('Media scan eligibility changed. Start a fresh scan.', 'dbvc'));
            }

            $snapshot['state'] = 'scanning';
            $snapshot['last_error'] = [];
            $snapshot['progress']['retry_count'] = absint($snapshot['progress']['retry_count'] ?? 0) + 1;

            return $this->saveWithStatus($snapshot, $expected_revision, 'retrying');
        } finally {
            $this->snapshots->releaseLock($scan_ref, $lock);
        }
    }

    /**
     * @param string $scan_ref
     * @param string $generation
     * @param int    $expected_revision
     * @param string $reason
     * @return array<string, mixed>|WP_Error
     */
    public function cancel($scan_ref, $generation, $expected_revision, $reason = 'user_canceled')
    {
        $lock = $this->snapshots->acquireLock($scan_ref);
        if (is_wp_error($lock)) {
            return $lock;
        }

        try {
            $snapshot = $this->snapshots->load($scan_ref);
            if (is_wp_error($snapshot)) {
                return $snapshot;
            }

            $request_check = $this->validateRequest($snapshot, $generation, $expected_revision);
            if (is_wp_error($request_check)) {
                return $request_check;
            }
            if ($request_check === 'stale') {
                $snapshot['request_status'] = 'stale';

                return $snapshot;
            }

            if (in_array((string) ($snapshot['state'] ?? ''), ['complete', 'canceled', 'invalidated'], true)) {
                $snapshot['request_status'] = 'terminal';

                return $snapshot;
            }

            $snapshot['state'] = 'canceled';
            $snapshot['completed_at'] = time();
            $snapshot['last_error'] = [
                'code' => sanitize_key((string) $reason),
                'message' => __('The media scan was canceled.', 'dbvc'),
                'retryable' => false,
            ];

            return $this->saveWithStatus($snapshot, $expected_revision, 'canceled');
        } finally {
            $this->snapshots->releaseLock($scan_ref, $lock);
        }
    }

    /**
     * @param string $scan_ref
     * @return array<string, mixed>|WP_Error
     */
    public function load($scan_ref)
    {
        return $this->snapshots->load($scan_ref);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function loadLatest()
    {
        return $this->snapshots->loadLatest();
    }

    /**
     * R2-H Slice 2b: build a complete, detached single-entity snapshot on demand (does
     * not touch the user's "latest scan" pointer). Used to drive the detail panel and
     * the existing assign/replace flow from a persistent Media Index row without a full
     * scan. Eligibility must be re-checked by the caller before invoking this.
     *
     * @param string $family  post|term
     * @param string $subtype
     * @param int    $id
     * @return array<string, mixed>|WP_Error The stored complete snapshot.
     */
    public function snapshotEntity($family, $subtype, $id)
    {
        $enabled = $this->requireEnabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $family = sanitize_key((string) $family);
        $subtype = sanitize_key((string) $subtype);
        $id = absint($id);
        if (! in_array($family, ['post', 'term'], true) || $id <= 0) {
            return new WP_Error('media_index_entity_invalid', __('The media index entity is invalid.', 'dbvc'), ['status' => 400]);
        }

        $now = time();
        $shell = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'scanner_version' => self::SCANNER_VERSION,
            'config_fingerprint' => '',
            'state' => 'scanning',
            'origin' => 'index_entity',
            'sources' => [],
            'cursor' => [],
            'progress' => ['processed' => 0, 'total_estimate' => 1, 'chunks' => 0, 'attempts' => 0, 'retry_count' => 0],
            'summary' => $this->emptySummary(),
            'diagnostics' => [
                'candidates_ineligible' => 0,
                'supported_fields_scanned' => 0,
                'unsupported_field_observations' => 0,
                'invalid_nonempty_values' => 0,
            ],
            'performance' => [
                'total_duration_ms' => 0.0,
                'max_duration_ms' => 0.0,
                'total_query_count' => 0,
                'max_memory_delta_bytes' => 0,
                'max_peak_memory_delta_bytes' => 0,
                'last_chunk' => [],
            ],
            'groups' => [],
            'last_error' => [],
            'started_at' => $now,
            'completed_at' => 0,
        ];

        $created = $this->snapshots->createDetached($shell);
        if (is_wp_error($created)) {
            return $created;
        }

        $scanned = $this->scanner->scan([
            ['family' => $family, 'subtype' => $subtype, 'id' => $id],
        ], (string) $created['generation'], true);
        if (is_wp_error($scanned)) {
            $this->snapshots->delete((string) $created['scan_ref']);

            return $scanned;
        }

        $created['groups'] = isset($scanned['groups']) && is_array($scanned['groups']) ? $scanned['groups'] : [];
        $this->mergeDiagnosticCounts($created, $scanned['counts'] ?? []);
        $created['progress']['processed'] = 1;
        $created['progress']['chunks'] = 1;
        $created['state'] = 'complete';
        $created['completed_at'] = time();
        $created['summary'] = $this->summarize($created);

        $saved = $this->snapshots->save($created, absint($created['revision']));

        return $saved;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param string               $generation
     * @param int                  $expected_revision
     * @return true|string|WP_Error
     */
    private function validateRequest(array $snapshot, $generation, $expected_revision)
    {
        if (! hash_equals((string) ($snapshot['generation'] ?? ''), (string) $generation)) {
            return new WP_Error('media_scan_generation_mismatch', __('The media scan generation does not match.', 'dbvc'));
        }

        if (absint($snapshot['revision'] ?? 0) !== absint($expected_revision)) {
            return 'stale';
        }

        return true;
    }

    /**
     * @param array<int, array<string, string>> $sources
     * @return string
     */
    private function configurationFingerprint(array $sources)
    {
        $payload = [
            'schema' => self::SNAPSHOT_SCHEMA_VERSION,
            'scanner' => self::SCANNER_VERSION,
            'sources' => $sources,
            'acf' => $this->catalog->getDefinitionFingerprint(true),
        ];
        $json = wp_json_encode($payload);

        return hash('sha256', is_string($json) ? $json : 'media_scan_config_encoding_failed');
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, int>
     */
    private function summarize(array $snapshot)
    {
        $summary = $this->emptySummary();
        $summary['candidate_entities_processed'] = absint($snapshot['progress']['processed'] ?? 0);
        $summary['unsupported_field_observations'] = absint($snapshot['diagnostics']['unsupported_field_observations'] ?? 0);
        $summary['invalid_nonempty_values'] = absint($snapshot['diagnostics']['invalid_nonempty_values'] ?? 0);
        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];
        $summary['entities_with_findings'] = count($groups);

        foreach ($groups as $group) {
            if (! is_array($group) || empty($group['findings']) || ! is_array($group['findings'])) {
                continue;
            }

            foreach ($group['findings'] as $finding) {
                if (! is_array($finding)) {
                    continue;
                }
                $family = sanitize_key((string) ($finding['family'] ?? ''));
                $summary['total_findings']++;
                if ($family === 'featured_image') {
                    $summary['featured_image_findings']++;
                } elseif ($family === 'acf_image') {
                    $summary['acf_image_findings']++;
                } elseif ($family === 'acf_gallery') {
                    $summary['acf_gallery_findings']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function emptySummary()
    {
        return [
            'candidate_entities_processed' => 0,
            'entities_with_findings' => 0,
            'total_findings' => 0,
            'featured_image_findings' => 0,
            'acf_image_findings' => 0,
            'acf_gallery_findings' => 0,
            'unsupported_field_observations' => 0,
            'invalid_nonempty_values' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $counts
     * @return void
     */
    private function mergeDiagnosticCounts(array &$snapshot, array $counts)
    {
        foreach (['candidates_ineligible', 'supported_fields_scanned', 'unsupported_field_observations', 'invalid_nonempty_values'] as $key) {
            $snapshot['diagnostics'][$key] = absint($snapshot['diagnostics'][$key] ?? 0) + absint($counts[$key] ?? 0);
        }
    }

    /**
     * @param float $started_at
     * @param int   $memory_start
     * @param int   $peak_start
     * @param int   $query_start
     * @param int   $candidate_count
     * @param int   $source_queries
     * @param int   $finding_count
     * @param string $status
     * @return array<string, mixed>
     */
    private function chunkMetrics($started_at, $memory_start, $peak_start, $query_start, $candidate_count, $source_queries, $finding_count, $status)
    {
        $query_end = function_exists('get_num_queries') ? get_num_queries() : $query_start;

        return [
            'duration_ms' => round(max(0, microtime(true) - (float) $started_at) * 1000, 3),
            'memory_delta_bytes' => max(0, memory_get_usage(true) - absint($memory_start)),
            'peak_memory_delta_bytes' => max(0, memory_get_peak_usage(true) - absint($peak_start)),
            'query_count' => max(0, absint($query_end) - absint($query_start)),
            'source_queries' => absint($source_queries),
            'candidate_count' => absint($candidate_count),
            'finding_count' => absint($finding_count),
            'status' => sanitize_key((string) $status),
            'recorded_at' => time(),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $metrics
     * @return void
     */
    private function recordPerformance(array &$snapshot, array $metrics)
    {
        $duration = (float) ($metrics['duration_ms'] ?? 0);
        $snapshot['performance']['total_duration_ms'] = round(
            (float) ($snapshot['performance']['total_duration_ms'] ?? 0) + $duration,
            3
        );
        $snapshot['performance']['max_duration_ms'] = max(
            (float) ($snapshot['performance']['max_duration_ms'] ?? 0),
            $duration
        );
        $snapshot['performance']['total_query_count'] = absint($snapshot['performance']['total_query_count'] ?? 0)
            + absint($metrics['query_count'] ?? 0);
        $snapshot['performance']['max_memory_delta_bytes'] = max(
            absint($snapshot['performance']['max_memory_delta_bytes'] ?? 0),
            absint($metrics['memory_delta_bytes'] ?? 0)
        );
        $snapshot['performance']['max_peak_memory_delta_bytes'] = max(
            absint($snapshot['performance']['max_peak_memory_delta_bytes'] ?? 0),
            absint($metrics['peak_memory_delta_bytes'] ?? 0)
        );
        $snapshot['performance']['last_chunk'] = $metrics;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param int                  $expected_revision
     * @param string               $request_status
     * @return array<string, mixed>|WP_Error
     */
    private function saveWithStatus(array $snapshot, $expected_revision, $request_status)
    {
        $saved = $this->snapshots->save($snapshot, $expected_revision);
        if (! is_wp_error($saved)) {
            $saved['request_status'] = sanitize_key((string) $request_status);
        }

        return $saved;
    }

    /**
     * @return true|WP_Error
     */
    private function requireEnabled()
    {
        if (! class_exists('\\DBVC_Visual_Editor_Addon')
            || ! method_exists('\\DBVC_Visual_Editor_Addon', 'is_media_manager_enabled')
            || ! \DBVC_Visual_Editor_Addon::is_media_manager_enabled()) {
            return new WP_Error('media_manager_disabled', __('The Frontend Media Manager is disabled.', 'dbvc'));
        }

        return true;
    }
}
