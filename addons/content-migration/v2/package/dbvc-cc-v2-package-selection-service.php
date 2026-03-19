<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_Selection_Service
{
    /**
     * @var DBVC_CC_V2_Package_Selection_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Package_Selection_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed> $artifact_paths
     * @return array<string, mixed>
     */
    public function load_page_artifacts(array $artifact_paths)
    {
        return [
            'recommendations' => $this->read_json_file(isset($artifact_paths['mapping_recommendations']) ? $artifact_paths['mapping_recommendations'] : ''),
            'mapping_decisions' => $this->read_json_file(isset($artifact_paths['mapping_decisions']) ? $artifact_paths['mapping_decisions'] : ''),
            'media_decisions' => $this->read_json_file(isset($artifact_paths['media_decisions']) ? $artifact_paths['media_decisions'] : ''),
            'target_transform' => $this->read_json_file(isset($artifact_paths['target_transform']) ? $artifact_paths['target_transform'] : ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $mapping_decisions
     * @param array<string, mixed>|null $recommendations
     * @return bool
     */
    public function is_decision_stale($mapping_decisions, $recommendations)
    {
        if (! is_array($mapping_decisions) || ! is_array($recommendations)) {
            return false;
        }

        $stored_fingerprint = isset($mapping_decisions['recommendation_fingerprint'])
            ? (string) $mapping_decisions['recommendation_fingerprint']
            : '';

        return $stored_fingerprint !== '' && $stored_fingerprint !== $this->compute_fingerprint($recommendations);
    }

    /**
     * @param array<string, mixed>      $recommended_target
     * @param array<string, mixed>|null $mapping_decisions
     * @return array<string, string>
     */
    public function resolve_selected_target_object(array $recommended_target, $mapping_decisions)
    {
        $decision = is_array($mapping_decisions) && isset($mapping_decisions['target_object_decision']) && is_array($mapping_decisions['target_object_decision'])
            ? $mapping_decisions['target_object_decision']
            : [];

        return [
            'targetFamily' => ! empty($decision['selected_target_family'])
                ? (string) $decision['selected_target_family']
                : (isset($recommended_target['target_family']) ? (string) $recommended_target['target_family'] : ''),
            'targetObjectKey' => ! empty($decision['selected_target_object_key'])
                ? (string) $decision['selected_target_object_key']
                : (isset($recommended_target['target_object_key']) ? (string) $recommended_target['target_object_key'] : ''),
            'label' => isset($recommended_target['label']) ? (string) $recommended_target['label'] : '',
            'resolutionMode' => ! empty($decision['selected_resolution_mode'])
                ? (string) $decision['selected_resolution_mode']
                : (isset($recommended_target['resolution_mode']) ? (string) $recommended_target['resolution_mode'] : ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $recommendations
     * @param array<string, mixed>|null $mapping_decisions
     * @return array<int, array<string, mixed>>
     */
    public function build_field_values($recommendations, $mapping_decisions)
    {
        if (! is_array($recommendations)) {
            return [];
        }

        $items = isset($recommendations['recommendations']) && is_array($recommendations['recommendations'])
            ? $recommendations['recommendations']
            : [];
        $review = isset($recommendations['review']) && is_array($recommendations['review']) ? $recommendations['review'] : [];
        $default_auto_accept = isset($review['status']) && (string) $review['status'] === 'auto_accept_candidate' && ! is_array($mapping_decisions);
        $approved = $this->index_items(
            is_array($mapping_decisions) && isset($mapping_decisions['approved']) && is_array($mapping_decisions['approved'])
                ? $mapping_decisions['approved']
                : []
        );
        $rejected = $this->index_items(
            is_array($mapping_decisions) && isset($mapping_decisions['rejected']) && is_array($mapping_decisions['rejected'])
                ? $mapping_decisions['rejected']
                : []
        );
        $unresolved = $this->index_items(
            is_array($mapping_decisions) && isset($mapping_decisions['unresolved']) && is_array($mapping_decisions['unresolved'])
                ? $mapping_decisions['unresolved']
                : []
        );
        $overrides = $this->index_overrides(
            is_array($mapping_decisions) && isset($mapping_decisions['overrides']) && is_array($mapping_decisions['overrides'])
                ? $mapping_decisions['overrides']
                : []
        );

        $field_values = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['recommendation_id'])) {
                continue;
            }

            $recommendation_id = (string) $item['recommendation_id'];
            if (isset($rejected[$recommendation_id]) || isset($unresolved[$recommendation_id])) {
                continue;
            }

            $is_selected = $default_auto_accept || isset($approved[$recommendation_id]) || isset($overrides[$recommendation_id]);
            if (! $is_selected) {
                continue;
            }

            $override_items = isset($overrides[$recommendation_id]) ? $overrides[$recommendation_id] : [];
            if (empty($override_items)) {
                $field_values[] = [
                    'recommendation_id' => $recommendation_id,
                    'target_ref' => isset($item['target_ref']) ? (string) $item['target_ref'] : '',
                    'value' => isset($item['target_evidence']) && $item['target_evidence'] !== ''
                        ? $item['target_evidence']
                        : (isset($item['source_evidence']) ? $item['source_evidence'] : ''),
                    'value_type' => isset($item['recommended_value_type']) ? (string) $item['recommended_value_type'] : '',
                    'confidence' => isset($item['confidence']) ? (float) $item['confidence'] : 0.0,
                    'source_refs' => isset($item['source_refs']) && is_array($item['source_refs']) ? array_values($item['source_refs']) : [],
                    'decision_source' => $default_auto_accept ? 'auto_accept' : 'reviewed',
                ];
                continue;
            }

            foreach ($override_items as $override_item) {
                $field_values[] = [
                    'recommendation_id' => $recommendation_id,
                    'target_ref' => isset($override_item['override_target']) ? (string) $override_item['override_target'] : '',
                    'value' => isset($item['source_evidence']) ? $item['source_evidence'] : '',
                    'value_type' => isset($item['recommended_value_type']) ? (string) $item['recommended_value_type'] : '',
                    'confidence' => isset($item['confidence']) ? (float) $item['confidence'] : 0.0,
                    'source_refs' => isset($item['source_refs']) && is_array($item['source_refs']) ? array_values($item['source_refs']) : [],
                    'decision_source' => 'override',
                ];
            }
        }

        return array_values($field_values);
    }

    /**
     * @param array<string, mixed>|null $recommendations
     * @param array<string, mixed>|null $media_decisions
     * @return array<int, array<string, mixed>>
     */
    public function build_media_refs($recommendations, $media_decisions)
    {
        if (! is_array($recommendations)) {
            return [];
        }

        $items = isset($recommendations['media_recommendations']) && is_array($recommendations['media_recommendations'])
            ? $recommendations['media_recommendations']
            : [];
        $review = isset($recommendations['review']) && is_array($recommendations['review']) ? $recommendations['review'] : [];
        $default_auto_accept = isset($review['status']) && (string) $review['status'] === 'auto_accept_candidate' && ! is_array($media_decisions);
        $approved = $this->index_items(
            is_array($media_decisions) && isset($media_decisions['approved']) && is_array($media_decisions['approved'])
                ? $media_decisions['approved']
                : []
        );
        $ignored = $this->index_items(
            is_array($media_decisions) && isset($media_decisions['ignored']) && is_array($media_decisions['ignored'])
                ? $media_decisions['ignored']
                : []
        );
        $conflicts = $this->index_items(
            is_array($media_decisions) && isset($media_decisions['conflicts']) && is_array($media_decisions['conflicts'])
                ? $media_decisions['conflicts']
                : []
        );
        $overrides = $this->index_overrides(
            is_array($media_decisions) && isset($media_decisions['overrides']) && is_array($media_decisions['overrides'])
                ? $media_decisions['overrides']
                : []
        );

        $media_refs = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['recommendation_id'])) {
                continue;
            }

            $recommendation_id = (string) $item['recommendation_id'];
            if (isset($ignored[$recommendation_id]) || isset($conflicts[$recommendation_id])) {
                continue;
            }

            $is_selected = $default_auto_accept || isset($approved[$recommendation_id]) || isset($overrides[$recommendation_id]);
            if (! $is_selected) {
                continue;
            }

            $override_items = isset($overrides[$recommendation_id]) ? $overrides[$recommendation_id] : [];
            if (empty($override_items)) {
                $media_refs[] = [
                    'recommendation_id' => $recommendation_id,
                    'media_id' => isset($item['media_id']) ? (string) $item['media_id'] : '',
                    'target_ref' => isset($item['target_ref']) ? (string) $item['target_ref'] : '',
                    'source_url' => isset($item['source_evidence']) ? (string) $item['source_evidence'] : '',
                    'media_kind' => isset($item['media_kind']) ? (string) $item['media_kind'] : '',
                    'value_type' => 'attachment_reference',
                    'source_refs' => isset($item['source_refs']) && is_array($item['source_refs']) ? array_values($item['source_refs']) : [],
                    'decision_source' => $default_auto_accept ? 'auto_accept' : 'reviewed',
                ];
                continue;
            }

            foreach ($override_items as $override_item) {
                $media_refs[] = [
                    'recommendation_id' => $recommendation_id,
                    'media_id' => isset($item['media_id']) ? (string) $item['media_id'] : '',
                    'target_ref' => isset($override_item['override_target']) ? (string) $override_item['override_target'] : '',
                    'source_url' => isset($item['source_evidence']) ? (string) $item['source_evidence'] : '',
                    'media_kind' => isset($item['media_kind']) ? (string) $item['media_kind'] : '',
                    'value_type' => 'attachment_reference',
                    'source_refs' => isset($item['source_refs']) && is_array($item['source_refs']) ? array_values($item['source_refs']) : [],
                    'decision_source' => 'override',
                ];
            }
        }

        return array_values($media_refs);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @param array<string, mixed>|null        $mapping_decisions
     * @return int
     */
    public function count_reruns(array $events, $mapping_decisions)
    {
        $count = 0;
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            if (
                isset($event['step_key'])
                && (string) $event['step_key'] === DBVC_CC_V2_Contracts::STEP_STAGE_RERUN_COMPLETED
            ) {
                ++$count;
            }
        }

        if (is_array($mapping_decisions) && isset($mapping_decisions['reruns']) && is_array($mapping_decisions['reruns'])) {
            $count = max($count, count($mapping_decisions['reruns']));
        }

        return $count;
    }

    /**
     * @param array<string, mixed>|null $mapping_decisions
     * @param array<string, mixed>|null $media_decisions
     * @return int
     */
    public function count_overrides($mapping_decisions, $media_decisions)
    {
        $count = 0;
        if (is_array($mapping_decisions) && isset($mapping_decisions['overrides']) && is_array($mapping_decisions['overrides'])) {
            $count += count($mapping_decisions['overrides']);
        }
        if (is_array($media_decisions) && isset($media_decisions['overrides']) && is_array($media_decisions['overrides'])) {
            $count += count($media_decisions['overrides']);
        }

        return $count;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function compute_fingerprint($value)
    {
        return hash('sha256', (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param mixed $items
     * @return array<string, array<string, mixed>>
     */
    private function index_items($items)
    {
        if (! is_array($items)) {
            return [];
        }

        $indexed = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['recommendation_id'])) {
                continue;
            }

            $indexed[(string) $item['recommendation_id']] = $item;
        }

        return $indexed;
    }

    /**
     * @param mixed $items
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function index_overrides($items)
    {
        if (! is_array($items)) {
            return [];
        }

        $indexed = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['recommendation_id'])) {
                continue;
            }

            $recommendation_id = (string) $item['recommendation_id'];
            if (! isset($indexed[$recommendation_id])) {
                $indexed[$recommendation_id] = [];
            }

            $indexed[$recommendation_id][] = $item;
        }

        return $indexed;
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
