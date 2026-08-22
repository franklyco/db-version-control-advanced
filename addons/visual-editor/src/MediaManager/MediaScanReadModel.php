<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Error;
use WP_Post;
use WP_Term;

final class MediaScanReadModel
{
    public const VIEW_MODEL_VERSION = 1;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;
    private const MAX_SEARCH_LENGTH = 100;

    /**
     * @var MediaScanCoordinator
     */
    private $coordinator;

    /**
     * @var MediaScanService
     */
    private $scanner;

    /**
     * @var EligibilityPolicy
     */
    private $eligibility;

    public function __construct(
        MediaScanCoordinator $coordinator,
        MediaScanService $scanner,
        EligibilityPolicy $eligibility
    ) {
        $this->coordinator = $coordinator;
        $this->scanner = $scanner;
        $this->eligibility = $eligibility;
    }

    /**
     * @param string               $scan_ref
     * @param array<string, mixed> $query
     * @return array<string, mixed>|WP_Error
     */
    public function getList($scan_ref, array $query = [])
    {
        $snapshot = $this->coordinator->load($scan_ref);

        return is_wp_error($snapshot) ? $snapshot : $this->buildList($snapshot, $query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|WP_Error
     */
    public function getLatestList(array $query = [])
    {
        $snapshot = $this->coordinator->loadLatest();

        return is_wp_error($snapshot) ? $snapshot : $this->buildList($snapshot, $query);
    }

    /**
     * @param string               $scan_ref
     * @param string               $group_ref
     * @param array<string, mixed> $request
     * @return array<string, mixed>|WP_Error
     */
    public function expandGroup($scan_ref, $group_ref, array $request = [])
    {
        $snapshot = $this->coordinator->load($scan_ref);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        $request_check = $this->validateSnapshotRequest($snapshot, $request);
        if (is_wp_error($request_check)) {
            return $request_check;
        }

        $group_ref = strtolower(trim((string) $group_ref));
        if (! preg_match('/^vemg_[a-f0-9]{20}$/', $group_ref)) {
            return $this->error('media_scan_group_invalid', __('The media finding group is invalid.', 'dbvc'), 400);
        }

        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];
        $original = $groups[$group_ref] ?? null;
        if (! is_array($original)) {
            return $this->error('media_scan_group_unavailable', __('The media finding group is unavailable.', 'dbvc'), 404);
        }

        $owner = $this->resolveEligibleOwner($original);
        if (empty($owner)) {
            return $this->error('media_scan_group_unavailable', __('The media finding group is unavailable.', 'dbvc'), 404);
        }

        $candidate = [
            'family' => $owner['family'],
            'subtype' => $owner['subtype'],
            'id' => $owner['id'],
        ];
        // R2-F: include the populated-field inventory so the detail panel can list
        // both empty findings and already-assigned fields with sanitized previews.
        $rescanned = $this->scanner->scan([$candidate], (string) ($snapshot['generation'] ?? ''), true);
        if (is_wp_error($rescanned)) {
            return $this->unavailableGroupResponse($snapshot, $original, $owner, $rescanned);
        }

        $current_groups = isset($rescanned['groups']) && is_array($rescanned['groups']) ? $rescanned['groups'] : [];
        $current = isset($current_groups[$group_ref]) && is_array($current_groups[$group_ref])
            ? $current_groups[$group_ref]
            : [];
        $current_findings = isset($current['findings']) && is_array($current['findings'])
            ? $current['findings']
            : [];
        $original_findings = isset($original['findings']) && is_array($original['findings'])
            ? $original['findings']
            : [];
        $current_assigned = isset($current['assigned']) && is_array($current['assigned'])
            ? $current['assigned']
            : [];
        $assigned_by_ref = [];
        foreach ($current_assigned as $assigned_ref => $assigned_item) {
            if (is_array($assigned_item)) {
                $assigned_by_ref[sanitize_key((string) $assigned_ref)] = $assigned_item;
            }
        }
        $original_ref_set = [];
        foreach (array_keys($original_findings) as $original_ref) {
            $original_ref_set[sanitize_key((string) $original_ref)] = true;
        }
        $fields = [];
        $counts = [
            'missing' => 0,
            'changed' => 0,
            'resolvedOrChanged' => 0,
            'unavailable' => 0,
            'populated' => 0,
        ];

        foreach ($original_findings as $finding_ref => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $finding_ref = sanitize_key((string) $finding_ref);
            $current_finding = isset($current_findings[$finding_ref]) && is_array($current_findings[$finding_ref])
                ? $current_findings[$finding_ref]
                : null;

            // D-052: a field that was missing at scan and is now populated (present in
            // the live assigned inventory) is projected as an ASSIGNED, replaceable field
            // — with a valueRef and a replace control — not the terminal
            // resolved_or_changed "refresh the scan" state. This makes a just-assigned
            // field immediately replaceable without a full rescan. The genuinely
            // gone/ineligible case (absent from the assigned inventory) still falls
            // through to resolved_or_changed below.
            if ($current_finding === null
                && isset($assigned_by_ref[$finding_ref])
                && is_array($assigned_by_ref[$finding_ref])) {
                $counts['populated']++;
                $fields[] = $this->projectAssignedField($finding_ref, $assigned_by_ref[$finding_ref]);
                continue;
            }

            $status = 'resolved_or_changed';
            $descriptor_status = 'unavailable';
            $message = __('This field is no longer confirmed missing. Refresh the scan before taking further action.', 'dbvc');

            if (is_array($current_finding)) {
                $original_fingerprint = (string) ($finding['empty_fingerprint'] ?? '');
                $current_fingerprint = (string) ($current_finding['empty_fingerprint'] ?? '');
                if ($original_fingerprint !== ''
                    && $current_fingerprint !== ''
                    && hash_equals($original_fingerprint, $current_fingerprint)) {
                    $status = 'missing';
                    $descriptor_status = 'not_hydrated';
                    $message = __('This supported media field is still empty. Descriptor hydration is deferred to the remediation phase.', 'dbvc');
                } else {
                    $status = 'changed';
                    $descriptor_status = 'blocked_stale';
                    $message = __('This field is still empty, but its scan evidence changed. Refresh the scan before taking further action.', 'dbvc');
                }
            }

            $count_key = $status === 'resolved_or_changed' ? 'resolvedOrChanged' : $status;
            $counts[$count_key]++;
            // Remaining resolved_or_changed fields are genuinely gone/ineligible (not in
            // the assigned inventory) and carry no preview; still-empty fields have none.
            $fields[] = $this->projectFinding($finding_ref, $finding, $status, $descriptor_status, $message, []);
        }

        // R2-F: project the populated-field inventory, skipping fields already
        // represented above (empty at scan, now populated) to avoid duplicates.
        foreach ($assigned_by_ref as $assigned_ref => $assigned) {
            if (isset($original_ref_set[$assigned_ref])) {
                continue;
            }
            $counts['populated']++;
            $fields[] = $this->projectAssignedField($assigned_ref, $assigned);
        }

        usort($fields, static function (array $left, array $right) {
            $label_compare = strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));

            return $label_compare !== 0
                ? $label_compare
                : strcmp((string) ($left['findingRef'] ?? ''), (string) ($right['findingRef'] ?? ''));
        });

        $original_refs = array_fill_keys(array_keys($original_findings), true);
        $new_missing_count = 0;
        foreach (array_keys($current_findings) as $current_ref) {
            if (! isset($original_refs[$current_ref])) {
                $new_missing_count++;
            }
        }

        $row_status = 'current';
        if ($counts['changed'] > 0 || $counts['resolvedOrChanged'] > 0 || $new_missing_count > 0) {
            $row_status = $counts['missing'] === 0 && $counts['changed'] === 0 && $new_missing_count === 0
                ? 'resolved_or_changed'
                : 'changed';
        }

        return [
            'viewModelVersion' => self::VIEW_MODEL_VERSION,
            'scan' => $this->projectSnapshot($snapshot),
            'row' => [
                'groupRef' => $group_ref,
                'status' => $row_status,
                'entity' => $this->projectEntity($owner),
                'counts' => $counts,
                'newMissingFindingCount' => $new_missing_count,
                'fields' => $fields,
                'availableActions' => [
                    'refreshScan' => true,
                    'assignMedia' => false,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function projectSnapshot(array $snapshot)
    {
        $state = sanitize_key((string) ($snapshot['state'] ?? ''));
        $progress = isset($snapshot['progress']) && is_array($snapshot['progress']) ? $snapshot['progress'] : [];
        $summary = isset($snapshot['summary']) && is_array($snapshot['summary']) ? $snapshot['summary'] : [];
        $last_error = isset($snapshot['last_error']) && is_array($snapshot['last_error']) ? $snapshot['last_error'] : [];

        return [
            'scanRef' => sanitize_key((string) ($snapshot['scan_ref'] ?? '')),
            'generation' => sanitize_key((string) ($snapshot['generation'] ?? '')),
            'revision' => absint($snapshot['revision'] ?? 0),
            'schemaVersion' => absint($snapshot['schema_version'] ?? 0),
            'scannerVersion' => absint($snapshot['scanner_version'] ?? 0),
            'viewModelVersion' => self::VIEW_MODEL_VERSION,
            'state' => $state,
            'requestStatus' => sanitize_key((string) ($snapshot['request_status'] ?? '')),
            'progress' => [
                'processed' => absint($progress['processed'] ?? 0),
                'totalEstimate' => absint($progress['total_estimate'] ?? 0),
                'chunks' => absint($progress['chunks'] ?? 0),
                'attempts' => absint($progress['attempts'] ?? 0),
                'retryCount' => absint($progress['retry_count'] ?? 0),
            ],
            'summary' => [
                'candidateEntitiesProcessed' => absint($summary['candidate_entities_processed'] ?? 0),
                'entitiesWithFindings' => absint($summary['entities_with_findings'] ?? 0),
                'totalFindings' => absint($summary['total_findings'] ?? 0),
                'featuredImageFindings' => absint($summary['featured_image_findings'] ?? 0),
                'acfImageFindings' => absint($summary['acf_image_findings'] ?? 0),
                'acfGalleryFindings' => absint($summary['acf_gallery_findings'] ?? 0),
                'unsupportedFieldObservations' => absint($summary['unsupported_field_observations'] ?? 0),
                'invalidNonemptyValues' => absint($summary['invalid_nonempty_values'] ?? 0),
            ],
            'error' => empty($last_error) ? null : [
                'code' => sanitize_key((string) ($last_error['code'] ?? '')),
                'message' => sanitize_text_field((string) ($last_error['message'] ?? '')),
                'retryable' => ! empty($last_error['retryable']),
            ],
            'startedAt' => absint($snapshot['started_at'] ?? 0),
            'completedAt' => absint($snapshot['completed_at'] ?? 0),
            'expiresAt' => absint($snapshot['expires_at'] ?? 0),
            'canRetry' => $state === 'failed' && ! empty($last_error['retryable']),
            'canCancel' => in_array($state, ['scanning', 'failed'], true),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $query
     * @return array<string, mixed>|WP_Error
     */
    private function buildList(array $snapshot, array $query)
    {
        $request_check = $this->validateSnapshotRequest($snapshot, $query);
        if (is_wp_error($request_check)) {
            return $request_check;
        }

        $normalized = $this->normalizeQuery($query);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];
        $matches = [];
        foreach ($groups as $group_ref => $group) {
            if (! is_array($group)) {
                continue;
            }

            $filtered_findings = $this->filterFindings($group, $normalized['fieldFamily']);
            if (empty($filtered_findings)) {
                continue;
            }

            $family = sanitize_key((string) ($group['owner']['family'] ?? ''));
            if ($normalized['entityFamily'] !== 'all' && $family !== $normalized['entityFamily']) {
                continue;
            }

            $search_haystack = $this->lower(
                sanitize_text_field((string) ($group['entity_label'] ?? '')) . ' '
                . sanitize_text_field((string) ($group['entity_type_label'] ?? ''))
            );
            if ($normalized['search'] !== '' && strpos($search_haystack, $this->lower($normalized['search'])) === false) {
                continue;
            }

            $group['_read_findings'] = $filtered_findings;
            $group['_read_group_ref'] = sanitize_key((string) $group_ref);
            $group['_read_missing_count'] = count($filtered_findings);
            $matches[] = $group;
        }

        $this->sortGroups($matches, $normalized['sort']);
        $start_index = 0;
        if ($normalized['cursor'] !== '') {
            $cursor_found = false;
            foreach ($matches as $index => $match) {
                if (($match['_read_group_ref'] ?? '') === $normalized['cursor']) {
                    $start_index = $index + 1;
                    $cursor_found = true;
                    break;
                }
            }
            if (! $cursor_found) {
                return $this->error('media_scan_cursor_invalid', __('The media results cursor is invalid for this query.', 'dbvc'), 400);
            }
        }

        $items = [];
        for ($index = $start_index; $index < count($matches); $index++) {
            $match = $matches[$index];
            $owner = $this->resolveEligibleOwner($match);
            if (empty($owner)) {
                continue;
            }

            $items[] = $this->projectGroup($match, $owner);
            if (count($items) > $normalized['limit']) {
                break;
            }
        }

        $has_more = count($items) > $normalized['limit'];
        if ($has_more) {
            array_pop($items);
        }
        $next_cursor = $has_more && ! empty($items)
            ? (string) $items[count($items) - 1]['groupRef']
            : '';

        return [
            'viewModelVersion' => self::VIEW_MODEL_VERSION,
            'scan' => $this->projectSnapshot($snapshot),
            'query' => [
                'search' => $normalized['search'],
                'entityFamily' => $normalized['entityFamily'],
                'fieldFamily' => $normalized['fieldFamily'],
                'sort' => $normalized['sort'],
                'limit' => $normalized['limit'],
            ],
            'items' => $items,
            'pagination' => [
                'hasMore' => $has_more,
                'nextCursor' => $next_cursor,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $request
     * @return true|WP_Error
     */
    private function validateSnapshotRequest(array $snapshot, array $request)
    {
        if (array_key_exists('generation', $request)) {
            $generation = sanitize_key((string) $request['generation']);
            if ($generation === '' || ! hash_equals((string) ($snapshot['generation'] ?? ''), $generation)) {
                return $this->error('media_scan_generation_mismatch', __('The media scan generation does not match.', 'dbvc'), 409);
            }
        }

        if (array_key_exists('expectedRevision', $request)) {
            if (! is_numeric($request['expectedRevision'])) {
                return $this->error('media_scan_revision_invalid', __('The media scan revision is invalid.', 'dbvc'), 400);
            }
            if (absint($snapshot['revision'] ?? 0) !== absint($request['expectedRevision'])) {
                return $this->error('media_scan_revision_changed', __('The media scan changed. Refresh the results before continuing.', 'dbvc'), 409);
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|WP_Error
     */
    private function normalizeQuery(array $query)
    {
        $raw_search = isset($query['search']) ? (string) $query['search'] : '';
        $search_length = function_exists('mb_strlen') ? mb_strlen($raw_search) : strlen($raw_search);
        if ($search_length > self::MAX_SEARCH_LENGTH) {
            return $this->error('media_scan_search_too_long', __('The media results search is too long.', 'dbvc'), 400);
        }

        $entity_family = sanitize_key((string) ($query['entityFamily'] ?? 'all'));
        if (! in_array($entity_family, ['all', 'post', 'term'], true)) {
            return $this->error('media_scan_entity_filter_invalid', __('The media entity filter is invalid.', 'dbvc'), 400);
        }

        $field_family = sanitize_key((string) ($query['fieldFamily'] ?? 'all'));
        if (! in_array($field_family, ['all', 'featured_image', 'acf_image', 'acf_gallery'], true)) {
            return $this->error('media_scan_field_filter_invalid', __('The media field filter is invalid.', 'dbvc'), 400);
        }

        $sort = sanitize_key((string) ($query['sort'] ?? 'entity_asc'));
        if (! in_array($sort, ['entity_asc', 'entity_desc', 'missing_asc', 'missing_desc', 'scanned_asc', 'scanned_desc'], true)) {
            return $this->error('media_scan_sort_invalid', __('The media results sort is invalid.', 'dbvc'), 400);
        }

        $limit = isset($query['limit']) ? $query['limit'] : self::DEFAULT_LIMIT;
        if (! is_numeric($limit) || absint($limit) < 1 || absint($limit) > self::MAX_LIMIT) {
            return $this->error('media_scan_limit_invalid', __('The media results limit is invalid.', 'dbvc'), 400);
        }

        $cursor = strtolower(trim((string) ($query['cursor'] ?? '')));
        if ($cursor !== '' && ! preg_match('/^vemg_[a-f0-9]{20}$/', $cursor)) {
            return $this->error('media_scan_cursor_invalid', __('The media results cursor is invalid.', 'dbvc'), 400);
        }

        return [
            'search' => sanitize_text_field($raw_search),
            'entityFamily' => $entity_family,
            'fieldFamily' => $field_family,
            'sort' => $sort,
            'limit' => absint($limit),
            'cursor' => $cursor,
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param string               $field_family
     * @return array<string, array<string, mixed>>
     */
    private function filterFindings(array $group, $field_family)
    {
        $result = [];
        $findings = isset($group['findings']) && is_array($group['findings']) ? $group['findings'] : [];
        foreach ($findings as $finding_ref => $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $family = sanitize_key((string) ($finding['family'] ?? ''));
            if ($field_family !== 'all' && $family !== $field_family) {
                continue;
            }
            $result[sanitize_key((string) $finding_ref)] = $finding;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @param string                           $sort
     * @return void
     */
    private function sortGroups(array &$groups, $sort)
    {
        usort($groups, static function (array $left, array $right) use ($sort) {
            if (strpos($sort, 'missing_') === 0) {
                $comparison = absint($left['_read_missing_count'] ?? 0) <=> absint($right['_read_missing_count'] ?? 0);
            } elseif (strpos($sort, 'scanned_') === 0) {
                $comparison = absint($left['scanned_at'] ?? 0) <=> absint($right['scanned_at'] ?? 0);
            } else {
                $comparison = strcasecmp(
                    sanitize_text_field((string) ($left['entity_label'] ?? '')),
                    sanitize_text_field((string) ($right['entity_label'] ?? ''))
                );
            }

            if (substr($sort, -5) === '_desc') {
                $comparison *= -1;
            }
            if ($comparison !== 0) {
                return $comparison;
            }

            return strcmp((string) ($left['_read_group_ref'] ?? ''), (string) ($right['_read_group_ref'] ?? ''));
        });
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>|null
     */
    private function resolveEligibleOwner(array $group)
    {
        $family = sanitize_key((string) ($group['owner']['family'] ?? ''));
        $subtype = sanitize_key((string) ($group['owner']['subtype'] ?? ''));
        $id = absint($group['owner']['id'] ?? 0);

        if ($family === 'post') {
            $entity = get_post($id);
            $assessment = $entity instanceof WP_Post ? $this->eligibility->assessPost($entity) : [];
        } elseif ($family === 'term') {
            $entity = get_term($id, $subtype);
            $assessment = ! is_wp_error($entity) && $entity instanceof WP_Term
                ? $this->eligibility->assessTerm($entity)
                : [];
        } else {
            return null;
        }

        if (empty($assessment['eligible'])) {
            return null;
        }

        return [
            'family' => $family,
            'subtype' => $subtype,
            'id' => $id,
            'entity' => $entity,
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private function projectGroup(array $group, array $owner)
    {
        $findings = isset($group['_read_findings']) && is_array($group['_read_findings'])
            ? $group['_read_findings']
            : [];
        $counts = $this->findingCounts($findings);
        $entity = $this->projectEntity($owner);

        return [
            'groupRef' => sanitize_key((string) ($group['_read_group_ref'] ?? $group['group_ref'] ?? '')),
            'entity' => $entity,
            'status' => 'scan_observation',
            'missingCount' => count($findings),
            'findingCounts' => $counts,
            'scannedAt' => absint($group['scanned_at'] ?? 0),
            'modifiedGmt' => sanitize_text_field((string) ($group['modified_gmt'] ?? '')),
            'availableActions' => [
                'expand' => true,
                'openFrontend' => $entity['frontendUrl'] !== '',
                'assignMedia' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private function projectEntity(array $owner)
    {
        $entity = $owner['entity'] ?? null;
        $family = sanitize_key((string) ($owner['family'] ?? ''));
        $subtype = sanitize_key((string) ($owner['subtype'] ?? ''));
        $title = '';
        $type_label = $subtype;
        $frontend_url = '';

        if ($entity instanceof WP_Post) {
            $raw_title = get_the_title($entity);
            $title = sanitize_text_field(wp_strip_all_tags(is_string($raw_title) ? $raw_title : ''));
            if ($title === '') {
                $title = __('Untitled content', 'dbvc');
            }
            $type = get_post_type_object($subtype);
            if ($type && ! empty($type->labels->singular_name)) {
                $type_label = sanitize_text_field((string) $type->labels->singular_name);
            }
            $url = get_permalink($entity);
            $frontend_url = is_string($url) ? esc_url_raw($url) : '';
        } elseif ($entity instanceof WP_Term) {
            $title = sanitize_text_field((string) $entity->name);
            $taxonomy = get_taxonomy($subtype);
            if ($taxonomy && ! empty($taxonomy->labels->singular_name)) {
                $type_label = sanitize_text_field((string) $taxonomy->labels->singular_name);
            }
            $url = get_term_link($entity);
            $frontend_url = is_wp_error($url) ? '' : esc_url_raw((string) $url);
        }

        return [
            'label' => $title,
            'family' => $family,
            'typeLabel' => $type_label,
            'frontendUrl' => $frontend_url,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $findings
     * @return array<string, int>
     */
    private function findingCounts(array $findings)
    {
        $counts = [
            'featuredImage' => 0,
            'acfImage' => 0,
            'acfGallery' => 0,
        ];
        foreach ($findings as $finding) {
            $family = is_array($finding) ? sanitize_key((string) ($finding['family'] ?? '')) : '';
            if ($family === 'featured_image') {
                $counts['featuredImage']++;
            } elseif ($family === 'acf_image') {
                $counts['acfImage']++;
            } elseif ($family === 'acf_gallery') {
                $counts['acfGallery']++;
            }
        }

        return $counts;
    }

    /**
     * R2-F: project a populated (assigned) field for the detail-panel inventory.
     * Exposes only a sanitized preview, never a raw target.
     *
     * @param string               $finding_ref
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function projectAssignedField($finding_ref, array $finding)
    {
        $family = sanitize_key((string) ($finding['family'] ?? ''));
        $field = isset($finding['field']) && is_array($finding['field']) ? $finding['field'] : [];
        $label = sanitize_text_field((string) ($field['field_label'] ?? ''));
        if ($label === '') {
            $label = $family === 'featured_image' ? __('Featured image', 'dbvc') : __('Media field', 'dbvc');
        }
        $preview = isset($finding['preview']) && is_array($finding['preview']) ? $finding['preview'] : [];
        $value_ref = sanitize_key((string) ($finding['value_fingerprint'] ?? ''));
        $replaceable = $value_ref !== '' && in_array($family, ['featured_image', 'acf_image', 'acf_gallery'], true);

        return [
            'findingRef' => sanitize_key((string) $finding_ref),
            'label' => $label,
            'family' => $family,
            'contextLabel' => sanitize_text_field((string) ($finding['context_label'] ?? '')),
            'status' => 'assigned',
            'descriptorStatus' => 'assigned',
            'message' => '',
            'valueRef' => $replaceable ? $value_ref : '',
            'preview' => [
                'url' => esc_url_raw((string) ($preview['url'] ?? '')),
                'alt' => sanitize_text_field((string) ($preview['alt'] ?? '')),
                'count' => absint($preview['count'] ?? 0),
            ],
            'availableActions' => [
                'refreshScan' => true,
                'assignMedia' => false,
                'replace' => $replaceable,
            ],
        ];
    }

    /**
     * @param string               $finding_ref
     * @param array<string, mixed> $finding
     * @param string               $status
     * @param string               $descriptor_status
     * @param string               $message
     * @return array<string, mixed>
     */
    private function projectFinding($finding_ref, array $finding, $status, $descriptor_status, $message, array $preview = [])
    {
        $family = sanitize_key((string) ($finding['family'] ?? ''));
        $field = isset($finding['field']) && is_array($finding['field']) ? $finding['field'] : [];
        $label = sanitize_text_field((string) ($field['field_label'] ?? ''));
        if ($label === '') {
            $label = $family === 'featured_image' ? __('Featured image', 'dbvc') : __('Media field', 'dbvc');
        }

        return [
            'findingRef' => sanitize_key((string) $finding_ref),
            'label' => $label,
            'family' => $family,
            'contextLabel' => sanitize_text_field((string) ($finding['context_label'] ?? '')),
            'status' => sanitize_key((string) $status),
            'descriptorStatus' => sanitize_key((string) $descriptor_status),
            'message' => sanitize_text_field((string) $message),
            'preview' => empty($preview) ? [] : [
                'url' => esc_url_raw((string) ($preview['url'] ?? '')),
                'alt' => sanitize_text_field((string) ($preview['alt'] ?? '')),
                'count' => absint($preview['count'] ?? 0),
            ],
            'availableActions' => [
                'refreshScan' => true,
                'hydrateDescriptor' => false,
                'assignMedia' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $group
     * @param array<string, mixed> $owner
     * @param WP_Error             $error
     * @return array<string, mixed>
     */
    private function unavailableGroupResponse(array $snapshot, array $group, array $owner, WP_Error $error)
    {
        $error_data = $error->get_error_data();
        $fields = [];
        $findings = isset($group['findings']) && is_array($group['findings']) ? $group['findings'] : [];
        foreach ($findings as $finding_ref => $finding) {
            if (is_array($finding)) {
                $fields[] = $this->projectFinding(
                    $finding_ref,
                    $finding,
                    'unavailable',
                    'unavailable',
                    __('This field could not be revalidated safely. Refresh the scan after the provider is available.', 'dbvc')
                );
            }
        }

        return [
            'viewModelVersion' => self::VIEW_MODEL_VERSION,
            'scan' => $this->projectSnapshot($snapshot),
            'row' => [
                'groupRef' => sanitize_key((string) ($group['group_ref'] ?? '')),
                'status' => 'unavailable',
                'entity' => $this->projectEntity($owner),
                'counts' => [
                    'missing' => 0,
                    'changed' => 0,
                    'resolvedOrChanged' => 0,
                    'unavailable' => count($fields),
                ],
                'newMissingFindingCount' => 0,
                'fields' => $fields,
                'error' => [
                    'code' => sanitize_key((string) $error->get_error_code()),
                    'message' => sanitize_text_field((string) $error->get_error_message()),
                    'retryable' => is_array($error_data) && ! empty($error_data['retryable']),
                ],
                'availableActions' => [
                    'refreshScan' => true,
                    'assignMedia' => false,
                ],
            ],
        ];
    }

    /**
     * @param string $value
     * @return string
     */
    private function lower($value)
    {
        return function_exists('mb_strtolower') ? mb_strtolower((string) $value) : strtolower((string) $value);
    }

    /**
     * @param string $code
     * @param string $message
     * @param int    $status
     * @return WP_Error
     */
    private function error($code, $message, $status)
    {
        return new WP_Error(
            sanitize_key((string) $code),
            sanitize_text_field((string) $message),
            ['status' => absint($status)]
        );
    }
}
