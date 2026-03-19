<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Media_Candidate_Service
{
    /**
     * @var DBVC_CC_V2_Media_Candidate_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Media_Candidate_Service
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
     * @param array<string, mixed> $catalog_bundle
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function build_artifact(array $page_context, array $classification_artifact, array $catalog_bundle, array $args = [])
    {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $sections_artifact = isset($page_context['sections_artifact']) && is_array($page_context['sections_artifact']) ? $page_context['sections_artifact'] : [];
        $catalog = isset($catalog_bundle['catalog']) && is_array($catalog_bundle['catalog']) ? $catalog_bundle['catalog'] : [];
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];

        $images = isset($raw_artifact['images']) && is_array($raw_artifact['images']) ? $raw_artifact['images'] : [];
        $section_order_map = $this->build_section_order_map($sections_artifact);
        $image_section_map = $this->build_image_section_map(
            isset($raw_artifact['sections_raw']) && is_array($raw_artifact['sections_raw']) ? $raw_artifact['sections_raw'] : [],
            $section_order_map
        );

        $object_key = isset($primary['object_key']) ? sanitize_key((string) $primary['object_key']) : '';
        $media_items = [];
        foreach ($images as $index => $image) {
            if (! is_array($image)) {
                continue;
            }

            $image_url = isset($image['source_url']) ? esc_url_raw((string) $image['source_url']) : '';
            if ($image_url === '') {
                continue;
            }

            $alt_text = isset($image['alt']) ? sanitize_text_field((string) $image['alt']) : '';
            $source_section_id = isset($image_section_map[$image_url]) ? (string) $image_section_map[$image_url] : '';
            $role_candidates = $this->determine_role_candidates((int) $index, $source_section_id, $alt_text);
            $target_candidates = $this->build_target_candidates(
                $catalog,
                $object_key,
                'image',
                $role_candidates
            );

            $media_items[] = [
                'media_id' => 'dbvc_cc_v2_med_' . substr(hash('sha256', $page_context['domain'] . '|' . $page_context['page_id'] . '|' . $image_url), 0, 16),
                'source_refs' => array_values(array_filter([
                    'page-artifact.v1#images[' . $index . ']',
                    $source_section_id !== '' ? 'sections.v2#' . $source_section_id : '',
                ])),
                'source_section_id' => $source_section_id,
                'normalized_url' => $image_url,
                'source_url' => $image_url,
                'media_kind' => 'image',
                'alt_text' => $alt_text,
                'preview_ref' => $image_url,
                'preview_status' => 'available',
                'role_candidates' => $role_candidates,
                'target_candidates' => $target_candidates,
            ];
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'media-candidates.v2',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'catalog_fingerprint' => isset($catalog_bundle['catalog_fingerprint']) ? (string) $catalog_bundle['catalog_fingerprint'] : '',
            'media_items' => $media_items,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'primary_object_key' => $object_key,
            ],
            'stats' => [
                'media_item_count' => count($media_items),
                'image_count' => count($media_items),
                'with_target_candidates' => count(
                    array_filter(
                        $media_items,
                        static function ($item) {
                            return is_array($item) && ! empty($item['target_candidates']);
                        }
                    )
                ),
                'unresolved_count' => count(
                    array_filter(
                        $media_items,
                        static function ($item) {
                            return is_array($item) && empty($item['target_candidates']);
                        }
                    )
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $sections_artifact
     * @return array<int, string>
     */
    private function build_section_order_map(array $sections_artifact)
    {
        $map = [];
        $sections = isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $order = isset($section['order']) ? (int) $section['order'] : 0;
            $section_id = isset($section['section_id']) ? sanitize_key((string) $section['section_id']) : '';
            if ($order > 0 && $section_id !== '') {
                $map[$order] = $section_id;
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $raw_sections
     * @param array<int, string> $section_order_map
     * @return array<string, string>
     */
    private function build_image_section_map(array $raw_sections, array $section_order_map)
    {
        $map = [];
        foreach ($raw_sections as $raw_section) {
            if (! is_array($raw_section)) {
                continue;
            }

            $order = isset($raw_section['order']) ? (int) $raw_section['order'] : 0;
            $section_id = isset($section_order_map[$order]) ? (string) $section_order_map[$order] : '';
            if ($section_id === '') {
                continue;
            }

            $images = isset($raw_section['images']) && is_array($raw_section['images']) ? $raw_section['images'] : [];
            foreach ($images as $image) {
                if (! is_array($image)) {
                    continue;
                }

                $image_url = isset($image['source_url']) ? esc_url_raw((string) $image['source_url']) : '';
                if ($image_url !== '') {
                    $map[$image_url] = $section_id;
                }
            }
        }

        return $map;
    }

    /**
     * @param int $index
     * @param string $source_section_id
     * @param string $alt_text
     * @return array<int, string>
     */
    private function determine_role_candidates($index, $source_section_id, $alt_text)
    {
        $roles = [];
        $alt_text = strtolower((string) $alt_text);

        if ($index === 0 || $source_section_id === 'dbvc_cc_sec_0001') {
            $roles[] = 'featured_image';
            $roles[] = 'hero_image';
        } else {
            $roles[] = 'inline_image';
        }

        if ($alt_text !== '' && strpos($alt_text, 'logo') !== false) {
            $roles[] = 'logo';
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string $object_key
     * @param string $media_kind
     * @param array<int, string> $role_candidates
     * @return array<int, array<string, mixed>>
     */
    private function build_target_candidates(array $catalog, $object_key, $media_kind, array $role_candidates)
    {
        $candidates = [];
        $object_key = sanitize_key((string) $object_key);
        $media_kind = sanitize_key((string) $media_kind);

        if ($media_kind === 'image' && in_array('featured_image', $role_candidates, true)) {
            $candidates[] = [
                'target_ref' => 'core:featured_image',
                'confidence' => 0.94,
                'reason' => 'primary_image_to_featured_image',
            ];
        }

        $media_field_catalog = isset($catalog['media_field_catalog']) && is_array($catalog['media_field_catalog']) ? $catalog['media_field_catalog'] : [];
        foreach ($media_field_catalog as $field_ref => $field) {
            if (! is_array($field) || ! $this->matches_media_field($field, $object_key, $media_kind)) {
                continue;
            }

            $confidence = isset($field['confidence']) ? (float) $field['confidence'] : 0.72;
            $field_key = strtolower((string) ($field['field_key'] ?? $field['field_name'] ?? ''));
            if ($field_key !== '' && in_array('hero_image', $role_candidates, true) && preg_match('/hero|banner|feature/', $field_key)) {
                $confidence += 0.08;
            } elseif ($field_key !== '' && in_array('logo', $role_candidates, true) && strpos($field_key, 'logo') !== false) {
                $confidence += 0.08;
            }

            $candidates[] = [
                'target_ref' => sanitize_text_field((string) $field_ref),
                'confidence' => round(min(0.95, $confidence), 2),
                'reason' => 'media_field_catalog_match',
            ];
        }

        $deduped = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || empty($candidate['target_ref'])) {
                continue;
            }

            $target_ref = (string) $candidate['target_ref'];
            $current_confidence = isset($candidate['confidence']) ? (float) $candidate['confidence'] : 0.0;
            if (! isset($deduped[$target_ref]) || $current_confidence > (float) $deduped[$target_ref]['confidence']) {
                $deduped[$target_ref] = $candidate;
            }
        }

        uasort(
            $deduped,
            static function ($left, $right) {
                $left_confidence = isset($left['confidence']) ? (float) $left['confidence'] : 0.0;
                $right_confidence = isset($right['confidence']) ? (float) $right['confidence'] : 0.0;
                if ($left_confidence === $right_confidence) {
                    return strnatcasecmp(
                        isset($left['target_ref']) ? (string) $left['target_ref'] : '',
                        isset($right['target_ref']) ? (string) $right['target_ref'] : ''
                    );
                }

                return $left_confidence < $right_confidence ? 1 : -1;
            }
        );

        return array_values($deduped);
    }

    /**
     * @param array<string, mixed> $field
     * @param string $object_key
     * @param string $media_kind
     * @return bool
     */
    private function matches_media_field(array $field, $object_key, $media_kind)
    {
        $accepted_kinds = isset($field['accepted_media_kinds']) && is_array($field['accepted_media_kinds'])
            ? array_map('sanitize_key', $field['accepted_media_kinds'])
            : [];
        if (! empty($accepted_kinds) && ! in_array($media_kind, $accepted_kinds, true)) {
            return false;
        }

        $source = isset($field['source']) ? sanitize_key((string) $field['source']) : '';
        if ($source === 'meta') {
            $field_object_type = isset($field['object_type']) ? sanitize_key((string) $field['object_type']) : '';
            $field_subtype = isset($field['subtype']) ? sanitize_key((string) $field['subtype']) : '';
            if ($field_object_type !== 'post') {
                return false;
            }

            return $field_subtype === $object_key || $field_subtype === 'default';
        }

        return true;
    }
}
