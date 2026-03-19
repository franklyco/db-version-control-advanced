<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Exception_Queue_Service
{
    /**
     * @var DBVC_CC_V2_Exception_Queue_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Exception_Queue_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string               $run_id
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function get_queue($run_id, array $args = [])
    {
        $run_id = sanitize_text_field((string) $run_id);
        $domain = DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory($domain);
        if (is_wp_error($inventory)) {
            return $inventory;
        }

        $filter = $this->normalize_filter(isset($args['filter']) ? $args['filter'] : '');
        $status = $this->normalize_status(isset($args['status']) ? $args['status'] : '');
        $query = sanitize_text_field((string) (isset($args['q']) ? $args['q'] : ''));
        $sort = $this->normalize_sort(isset($args['sort']) ? $args['sort'] : '');

        $items = [];
        $totals = [
            'all' => 0,
            'blocked' => 0,
            'needsReview' => 0,
            'overridden' => 0,
            'stale' => 0,
        ];

        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['scope_status'] ?? '') !== 'eligible') {
                continue;
            }

            $item = $this->build_queue_item($domain, $run_id, $row);
            if ($item === null) {
                continue;
            }

            ++$totals['all'];
            if ($item['status'] === 'blocked') {
                ++$totals['blocked'];
            }
            if ($item['status'] === 'needs_review') {
                ++$totals['needsReview'];
            }
            if (! empty($item['manualOverrideCount'])) {
                ++$totals['overridden'];
            }
            if (! empty($item['stale'])) {
                ++$totals['stale'];
            }

            if (! $this->matches_filters($item, $filter, $status, $query)) {
                continue;
            }

            $items[] = $item;
        }

        usort(
            $items,
            function ($left, $right) use ($sort) {
                return $this->compare_items($left, $right, $sort);
            }
        );

        return [
            'runId' => $run_id,
            'domain' => $domain,
            'generatedAt' => current_time('c'),
            'filter' => $filter,
            'status' => $status,
            'q' => $query,
            'sort' => $sort,
            'counts' => $totals,
            'items' => array_values($items),
        ];
    }

    /**
     * @param string               $domain
     * @param string               $run_id
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function build_queue_item($domain, $run_id, array $row)
    {
        $page_id = isset($row['page_id']) ? sanitize_text_field((string) $row['page_id']) : '';
        if ($page_id === '') {
            return null;
        }

        $page_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context($domain, $page_id);
        if (is_wp_error($page_context)) {
            return null;
        }

        $artifact_paths = isset($page_context['artifact_paths']) && is_array($page_context['artifact_paths']) ? $page_context['artifact_paths'] : [];
        $recommendations = $this->read_json_file(isset($artifact_paths['mapping_recommendations']) ? $artifact_paths['mapping_recommendations'] : '');
        if (! is_array($recommendations)) {
            return null;
        }

        $mapping_decisions = $this->read_json_file(isset($artifact_paths['mapping_decisions']) ? $artifact_paths['mapping_decisions'] : '');
        $media_decisions = $this->read_json_file(isset($artifact_paths['media_decisions']) ? $artifact_paths['media_decisions'] : '');
        $target_transform = $this->read_json_file(isset($artifact_paths['target_transform']) ? $artifact_paths['target_transform'] : '');

        $review = isset($recommendations['review']) && is_array($recommendations['review']) ? $recommendations['review'] : [];
        $review_status = isset($review['status']) ? sanitize_key((string) $review['status']) : 'needs_review';
        $reason_codes = isset($review['reason_codes']) && is_array($review['reason_codes']) ? array_values($review['reason_codes']) : [];
        $mapping_decision_status = is_array($mapping_decisions) && ! empty($mapping_decisions['decision_status'])
            ? sanitize_key((string) $mapping_decisions['decision_status'])
            : 'pending';
        $media_decision_status = is_array($media_decisions) && ! empty($media_decisions['decision_status'])
            ? sanitize_key((string) $media_decisions['decision_status'])
            : 'pending';
        $manual_override_count = $this->count_overrides($mapping_decisions, $media_decisions);
        $decision_status = $this->resolve_decision_status($mapping_decision_status, $media_decision_status, $manual_override_count);
        $fingerprint = $this->compute_fingerprint($recommendations);
        $is_stale = is_array($mapping_decisions)
            && ! empty($mapping_decisions['recommendation_fingerprint'])
            && (string) $mapping_decisions['recommendation_fingerprint'] !== $fingerprint;

        $needs_review = in_array($review_status, ['needs_review', 'blocked'], true)
            || $decision_status === 'needs_review'
            || $manual_override_count > 0
            || $is_stale;

        if (! $needs_review) {
            return null;
        }

        $status = 'needs_review';
        if ($review_status === 'blocked') {
            $status = 'blocked';
        } elseif ($decision_status !== 'pending') {
            $status = 'completed';
        }

        $recommended_object = isset($recommendations['recommended_target_object']) && is_array($recommendations['recommended_target_object'])
            ? $recommendations['recommended_target_object']
            : [];
        $resolution_preview = isset($target_transform['resolution_preview']) && is_array($target_transform['resolution_preview'])
            ? $target_transform['resolution_preview']
            : [];

        return [
            'runId' => $run_id,
            'pageId' => $page_id,
            'path' => isset($row['path']) ? (string) $row['path'] : '',
            'sourceUrl' => isset($row['source_url']) ? (string) $row['source_url'] : '',
            'status' => $status,
            'reviewStatus' => $review_status,
            'decisionStatus' => $decision_status,
            'reasonCodes' => array_values(array_unique($reason_codes)),
            'stale' => $is_stale,
            'needsReview' => $needs_review,
            'resolutionMode' => isset($resolution_preview['mode'])
                ? (string) $resolution_preview['mode']
                : (isset($recommended_object['resolution_mode']) ? (string) $recommended_object['resolution_mode'] : ''),
            'targetObject' => [
                'family' => isset($recommended_object['target_family']) ? (string) $recommended_object['target_family'] : '',
                'key' => isset($recommended_object['target_object_key']) ? (string) $recommended_object['target_object_key'] : '',
                'label' => isset($recommended_object['label']) ? (string) $recommended_object['label'] : '',
            ],
            'recommendationCount' => isset($recommendations['recommendations']) && is_array($recommendations['recommendations'])
                ? count($recommendations['recommendations'])
                : 0,
            'mediaRecommendationCount' => isset($recommendations['media_recommendations']) && is_array($recommendations['media_recommendations'])
                ? count($recommendations['media_recommendations'])
                : 0,
            'unresolvedCount' => isset($recommendations['unresolved_items']) && is_array($recommendations['unresolved_items'])
                ? count($recommendations['unresolved_items'])
                : 0,
            'conflictCount' => isset($recommendations['conflicts']) && is_array($recommendations['conflicts'])
                ? count($recommendations['conflicts'])
                : 0,
            'manualOverrideCount' => $manual_override_count,
            'decisionUpdatedAt' => is_array($mapping_decisions) && ! empty($mapping_decisions['generated_at'])
                ? (string) $mapping_decisions['generated_at']
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param string               $filter
     * @param string               $status
     * @param string               $query
     * @return bool
     */
    private function matches_filters(array $item, $filter, $status, $query)
    {
        if ($status !== '' && ($item['status'] ?? '') !== $status) {
            return false;
        }

        if ($filter === 'blocked' && ($item['status'] ?? '') !== 'blocked') {
            return false;
        }
        if ($filter === 'low-confidence' && ! in_array('low_confidence_recommendations', isset($item['reasonCodes']) && is_array($item['reasonCodes']) ? $item['reasonCodes'] : [], true)) {
            return false;
        }
        if ($filter === 'overridden' && empty($item['manualOverrideCount'])) {
            return false;
        }
        if ($filter === 'stale' && empty($item['stale'])) {
            return false;
        }
        if ($filter === 'policy' && ! $this->item_has_policy_reason($item)) {
            return false;
        }

        if ($query === '') {
            return true;
        }

        $haystack = strtolower(
            trim(
                implode(
                    ' ',
                    [
                        isset($item['pageId']) ? (string) $item['pageId'] : '',
                        isset($item['path']) ? (string) $item['path'] : '',
                        isset($item['sourceUrl']) ? (string) $item['sourceUrl'] : '',
                        isset($item['targetObject']['label']) ? (string) $item['targetObject']['label'] : '',
                        isset($item['targetObject']['key']) ? (string) $item['targetObject']['key'] : '',
                    ]
                )
            )
        );

        return strpos($haystack, strtolower($query)) !== false;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @param string               $sort
     * @return int
     */
    private function compare_items(array $left, array $right, $sort)
    {
        if ($sort === 'updated') {
            $left_updated = isset($left['decisionUpdatedAt']) ? strtotime((string) $left['decisionUpdatedAt']) : 0;
            $right_updated = isset($right['decisionUpdatedAt']) ? strtotime((string) $right['decisionUpdatedAt']) : 0;
            if ($left_updated !== $right_updated) {
                return $right_updated <=> $left_updated;
            }
        }

        $priority_order = [
            'blocked' => 0,
            'needs_review' => 1,
            'completed' => 2,
        ];
        $left_priority = isset($priority_order[$left['status']]) ? $priority_order[$left['status']] : 9;
        $right_priority = isset($priority_order[$right['status']]) ? $priority_order[$right['status']] : 9;
        if ($left_priority !== $right_priority) {
            return $left_priority <=> $right_priority;
        }

        return strnatcasecmp(
            isset($left['path']) ? (string) $left['path'] : '',
            isset($right['path']) ? (string) $right['path'] : ''
        );
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalize_filter($value)
    {
        $value = sanitize_key((string) $value);
        $allowed = ['all', 'blocked', 'low-confidence', 'stale', 'policy', 'overridden'];

        return in_array($value, $allowed, true) ? $value : 'all';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalize_status($value)
    {
        $value = sanitize_key((string) $value);
        $allowed = ['needs_review', 'blocked', 'completed'];

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalize_sort($value)
    {
        $value = sanitize_key((string) $value);
        return in_array($value, ['priority', 'updated'], true) ? $value : 'priority';
    }

    /**
     * @param array<string, mixed> $item
     * @return bool
     */
    private function item_has_policy_reason(array $item)
    {
        $reason_codes = isset($item['reasonCodes']) && is_array($item['reasonCodes']) ? $item['reasonCodes'] : [];
        foreach ($reason_codes as $reason_code) {
            if (! in_array((string) $reason_code, ['low_confidence_recommendations'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $mapping_decisions
     * @param array<string, mixed>|null $media_decisions
     * @return int
     */
    private function count_overrides($mapping_decisions, $media_decisions)
    {
        $count = 0;

        if (is_array($mapping_decisions)) {
            $count += isset($mapping_decisions['overrides']) && is_array($mapping_decisions['overrides'])
                ? count($mapping_decisions['overrides'])
                : 0;
            if (
                isset($mapping_decisions['target_object_decision']['decision_mode'])
                && (string) $mapping_decisions['target_object_decision']['decision_mode'] === 'override'
            ) {
                ++$count;
            }
        }

        if (is_array($media_decisions)) {
            $count += isset($media_decisions['overrides']) && is_array($media_decisions['overrides'])
                ? count($media_decisions['overrides'])
                : 0;
        }

        return $count;
    }

    /**
     * @param string $mapping_status
     * @param string $media_status
     * @param int    $manual_override_count
     * @return string
     */
    private function resolve_decision_status($mapping_status, $media_status, $manual_override_count)
    {
        if ($manual_override_count > 0 || $mapping_status === 'overridden' || $media_status === 'overridden') {
            return 'overridden';
        }
        if ($mapping_status === 'needs_review' || $media_status === 'needs_review') {
            return 'needs_review';
        }
        if ($mapping_status !== 'pending' || $media_status !== 'pending') {
            return 'reviewed';
        }

        return 'pending';
    }

    /**
     * @param array<string, mixed> $artifact
     * @return string
     */
    private function compute_fingerprint(array $artifact)
    {
        return hash('sha256', (string) wp_json_encode($artifact, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        $path = (string) $path;
        if ($path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
