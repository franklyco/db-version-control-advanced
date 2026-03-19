<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Target_Transform_Service
{
    /**
     * @var DBVC_CC_V2_Target_Transform_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Target_Transform_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $mapping_index_artifact
     * @param array<string, mixed> $media_candidates_artifact
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function build_artifact(
        array $page_context,
        array $classification_artifact,
        array $mapping_index_artifact,
        array $media_candidates_artifact,
        array $args = []
    ) {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];
        $resolution_preview = $this->build_resolution_preview($page_context, $classification_artifact, $mapping_index_artifact);

        $transform_items = [];
        $warning_count = 0;

        $content_items = isset($mapping_index_artifact['content_items']) && is_array($mapping_index_artifact['content_items'])
            ? $mapping_index_artifact['content_items']
            : [];
        foreach ($content_items as $content_item) {
            if (! is_array($content_item)) {
                continue;
            }

            $candidate = isset($content_item['target_candidates'][0]) && is_array($content_item['target_candidates'][0])
                ? $content_item['target_candidates'][0]
                : [];
            if (empty($candidate['target_ref'])) {
                continue;
            }

            $preview_value = isset($content_item['value_preview']) ? (string) $content_item['value_preview'] : '';
            $warnings = [];
            if ($preview_value === '') {
                $warnings[] = 'empty_preview_value';
                ++$warning_count;
            }

            $transform_items[] = [
                'source_refs' => isset($content_item['source_refs']) && is_array($content_item['source_refs']) ? array_values($content_item['source_refs']) : [],
                'target_ref' => (string) $candidate['target_ref'],
                'transform_type' => $this->determine_transform_type((string) $candidate['target_ref']),
                'transform_status' => empty($warnings) ? 'ready' : 'completed_with_warnings',
                'output_shape' => $this->determine_output_shape((string) $candidate['target_ref']),
                'preview_value' => $preview_value,
                'warnings' => $warnings,
            ];
        }

        $media_items = isset($media_candidates_artifact['media_items']) && is_array($media_candidates_artifact['media_items'])
            ? $media_candidates_artifact['media_items']
            : [];
        foreach ($media_items as $media_item) {
            if (! is_array($media_item)) {
                continue;
            }

            $candidate = isset($media_item['target_candidates'][0]) && is_array($media_item['target_candidates'][0])
                ? $media_item['target_candidates'][0]
                : [];
            if (empty($candidate['target_ref'])) {
                continue;
            }

            $transform_items[] = [
                'source_refs' => isset($media_item['source_refs']) && is_array($media_item['source_refs']) ? array_values($media_item['source_refs']) : [],
                'target_ref' => (string) $candidate['target_ref'],
                'transform_type' => 'media_reference',
                'transform_status' => 'ready',
                'output_shape' => 'attachment_reference',
                'preview_value' => isset($media_item['normalized_url']) ? (string) $media_item['normalized_url'] : '',
                'warnings' => [],
            ];
        }

        $taxonomy_hints = isset($classification_artifact['taxonomy_hints']) && is_array($classification_artifact['taxonomy_hints'])
            ? $classification_artifact['taxonomy_hints']
            : [];
        foreach ($taxonomy_hints as $taxonomy_hint) {
            if (! is_array($taxonomy_hint) || empty($taxonomy_hint['taxonomy_key'])) {
                continue;
            }

            $terms_preview = $this->build_taxonomy_preview_terms($page_context);
            $warnings = empty($terms_preview) ? ['no_taxonomy_terms_inferred'] : [];
            if (! empty($warnings)) {
                ++$warning_count;
            }

            $transform_items[] = [
                'source_refs' => ['initial-classification.v1#taxonomy_hints'],
                'target_ref' => 'taxonomy:' . sanitize_key((string) $taxonomy_hint['taxonomy_key']),
                'transform_type' => 'taxonomy_terms',
                'transform_status' => empty($warnings) ? 'ready' : 'completed_with_warnings',
                'output_shape' => 'term_set',
                'preview_value' => $terms_preview,
                'warnings' => $warnings,
            ];
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-transform.v1',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'classification_ref' => isset($args['classification_ref']) ? (string) $args['classification_ref'] : '',
            'transform_items' => $transform_items,
            'resolution_preview' => $resolution_preview,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'primary_object_key' => isset($primary['object_key']) ? (string) $primary['object_key'] : '',
            ],
            'stats' => [
                'transform_item_count' => count($transform_items),
                'ready_count' => count(
                    array_filter(
                        $transform_items,
                        static function ($item) {
                            return is_array($item) && isset($item['transform_status']) && (string) $item['transform_status'] === 'ready';
                        }
                    )
                ),
                'warning_count' => $warning_count,
                'resolution_mode' => isset($resolution_preview['mode']) ? (string) $resolution_preview['mode'] : '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $mapping_index_artifact
     * @return array<string, mixed>
     */
    private function build_resolution_preview(array $page_context, array $classification_artifact, array $mapping_index_artifact)
    {
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];
        $object_key = isset($primary['object_key']) ? sanitize_key((string) $primary['object_key']) : '';
        $review = isset($classification_artifact['review']) && is_array($classification_artifact['review']) ? $classification_artifact['review'] : [];
        $review_status = isset($review['status']) ? sanitize_key((string) $review['status']) : '';
        if ($object_key === '' || $review_status === 'blocked') {
            return [
                'mode' => DBVC_CC_V2_Contracts::RESOLUTION_MODE_BLOCKED,
                'reason' => 'classification_blocked',
                'target' => [],
                'ambiguous' => false,
            ];
        }

        $row = isset($page_context['row']) && is_array($page_context['row']) ? $page_context['row'] : [];
        $path = isset($row['path']) ? (string) $row['path'] : '';
        $slug = isset($row['slug']) ? sanitize_title((string) $row['slug']) : sanitize_title((string) $path);

        if ($path === '/' && $object_key === 'page') {
            $front_page_id = (int) get_option('page_on_front');
            if ($front_page_id > 0) {
                return [
                    'mode' => DBVC_CC_V2_Contracts::RESOLUTION_MODE_UPDATE_EXISTING,
                    'reason' => 'front_page_match',
                    'target' => [
                        'object_id' => $front_page_id,
                        'object_key' => $object_key,
                    ],
                    'ambiguous' => false,
                ];
            }
        }

        if ($slug !== '') {
            $existing = get_page_by_path($slug, OBJECT, $object_key);
            if ($existing instanceof WP_Post) {
                return [
                    'mode' => DBVC_CC_V2_Contracts::RESOLUTION_MODE_UPDATE_EXISTING,
                    'reason' => 'slug_match',
                    'target' => [
                        'object_id' => (int) $existing->ID,
                        'object_key' => $object_key,
                    ],
                    'ambiguous' => false,
                ];
            }
        }

        $content_items = isset($mapping_index_artifact['content_items']) && is_array($mapping_index_artifact['content_items'])
            ? $mapping_index_artifact['content_items']
            : [];
        $has_required_content = ! empty(
            array_filter(
                $content_items,
                static function ($item) {
                    return is_array($item) && ! empty($item['value_preview']);
                }
            )
        );

        return [
            'mode' => $has_required_content ? DBVC_CC_V2_Contracts::RESOLUTION_MODE_CREATE_NEW : DBVC_CC_V2_Contracts::RESOLUTION_MODE_BLOCKED,
            'reason' => $has_required_content ? 'create_inputs_present' : 'missing_required_create_inputs',
            'target' => [
                'object_key' => $object_key,
            ],
            'ambiguous' => false,
        ];
    }

    /**
     * @param string $target_ref
     * @return string
     */
    private function determine_transform_type($target_ref)
    {
        if (strpos($target_ref, 'taxonomy:') === 0) {
            return 'taxonomy_terms';
        }

        if ($target_ref === 'core:featured_image') {
            return 'media_reference';
        }

        return 'copy_text';
    }

    /**
     * @param string $target_ref
     * @return string
     */
    private function determine_output_shape($target_ref)
    {
        if ($target_ref === 'core:post_content') {
            return 'rich_text';
        }

        if ($target_ref === 'core:featured_image') {
            return 'attachment_reference';
        }

        if (strpos($target_ref, 'taxonomy:') === 0) {
            return 'term_set';
        }

        return 'scalar_string';
    }

    /**
     * @param array<string, mixed> $page_context
     * @return array<int, string>
     */
    private function build_taxonomy_preview_terms(array $page_context)
    {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $title = isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '';
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($title)) ?: [];
        $tokens = array_values(array_filter($tokens, static function ($token) {
            return strlen((string) $token) >= 4;
        }));

        return array_slice($tokens, 0, 3);
    }
}
