<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Initial_Classification_Service
{
    /**
     * @var DBVC_CC_V2_Initial_Classification_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Initial_Classification_Service
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
     * @param array<string, mixed> $inventory_bundle
     * @param array<string, mixed> $context_artifact
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function build_artifact(array $page_context, array $inventory_bundle, array $context_artifact, array $args = [])
    {
        $runtime = DBVC_CC_V2_Contracts::get_ai_runtime_settings();
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $inventory = isset($inventory_bundle['inventory']) && is_array($inventory_bundle['inventory']) ? $inventory_bundle['inventory'] : [];
        $candidates = $this->build_candidates($page_context, $inventory, $context_artifact);
        $primary = ! empty($candidates) ? $candidates[0] : $this->build_fallback_candidate();
        $alternates = array_slice($candidates, 1, 3);
        $taxonomy_hints = $this->build_taxonomy_hints($primary, $inventory, $page_context, $context_artifact);
        $review = $this->build_review($primary, $alternates);

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'initial-classification.v1',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'prompt_version' => isset($runtime['prompt_version']) ? (string) $runtime['prompt_version'] : 'v1',
            'model' => isset($runtime['model']) ? (string) $runtime['model'] : 'gpt-4o-mini',
            'inventory_fingerprint' => isset($inventory_bundle['inventory_fingerprint']) ? (string) $inventory_bundle['inventory_fingerprint'] : '',
            'status' => $review['status'] === 'blocked' || $review['status'] === 'needs_review' ? 'completed_with_warnings' : 'completed',
            'primary_classification' => $primary,
            'alternate_classifications' => array_values($alternates),
            'taxonomy_hints' => $taxonomy_hints,
            'review' => $review,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'source_fingerprint' => isset($raw_artifact['content_hash']) ? (string) $raw_artifact['content_hash'] : '',
                'context_ref' => isset($args['context_ref']) ? (string) $args['context_ref'] : '',
                'fallback_mode' => isset($runtime['fallback_mode']) ? (string) $runtime['fallback_mode'] : DBVC_CC_V2_Contracts::AI_FALLBACK_MODE,
                'stage_budget' => DBVC_CC_V2_Contracts::get_ai_stage_budget(DBVC_CC_V2_Contracts::AI_STAGE_INITIAL_CLASSIFICATION),
                'prompt_input' => $this->build_prompt_input($page_context, $inventory, $context_artifact),
            ],
            'stats' => [
                'candidate_count' => count($candidates),
                'taxonomy_hint_count' => count($taxonomy_hints),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $context_artifact
     * @return array<int, array<string, mixed>>
     */
    private function build_candidates(array $page_context, array $inventory, array $context_artifact)
    {
        $object_types = isset($inventory['object_types']) && is_array($inventory['object_types']) ? $inventory['object_types'] : [];
        $candidate_pool = [];

        foreach ($object_types as $object_type) {
            if (! is_array($object_type)) {
                continue;
            }

            $object_key = isset($object_type['object_key']) ? sanitize_key((string) $object_type['object_key']) : '';
            if ($object_key === '' || ! $this->is_classification_candidate($object_type)) {
                continue;
            }

            $candidate_pool[] = $object_type;
        }

        if (empty($candidate_pool)) {
            $candidate_pool = array_values(array_filter($object_types, 'is_array'));
        }

        $features = $this->extract_page_features($page_context, $context_artifact);
        $candidates = [];

        foreach ($candidate_pool as $object_type) {
            $candidate = $this->score_candidate($object_type, $features, $context_artifact);
            if (is_array($candidate)) {
                $candidates[] = $candidate;
            }
        }

        usort(
            $candidates,
            static function ($left, $right) {
                $left_score = isset($left['confidence']) ? (float) $left['confidence'] : 0.0;
                $right_score = isset($right['confidence']) ? (float) $right['confidence'] : 0.0;
                if ($left_score === $right_score) {
                    return strnatcasecmp(
                        isset($left['object_key']) ? (string) $left['object_key'] : '',
                        isset($right['object_key']) ? (string) $right['object_key'] : ''
                    );
                }

                return $left_score < $right_score ? 1 : -1;
            }
        );

        return $candidates;
    }

    /**
     * @param array<string, mixed> $object_type
     * @return bool
     */
    private function is_classification_candidate(array $object_type)
    {
        $object_key = isset($object_type['object_key']) ? sanitize_key((string) $object_type['object_key']) : '';
        $ignore = [
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_navigation',
            'acf-field',
            'acf-field-group',
        ];

        return ! empty($object_type['public']) && ! in_array($object_key, $ignore, true);
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $context_artifact
     * @return array<string, mixed>
     */
    private function extract_page_features(array $page_context, array $context_artifact)
    {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $path = isset($page_context['path']) ? (string) $page_context['path'] : (isset($raw_artifact['path']) ? (string) $raw_artifact['path'] : '');
        $path_tokens = $this->tokenize($path);
        $title = isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '';
        $title_tokens = $this->tokenize($title);
        $headings = isset($raw_artifact['headings']) && is_array($raw_artifact['headings']) ? array_values($raw_artifact['headings']) : [];
        $heading_tokens = $this->tokenize(implode(' ', array_slice($headings, 0, 5)));
        $context_tags = isset($context_artifact['summary']['dominant_context_tags']) && is_array($context_artifact['summary']['dominant_context_tags'])
            ? array_values($context_artifact['summary']['dominant_context_tags'])
            : [];
        $context_tokens = $this->tokenize(implode(' ', $context_tags));
        $path_depth = $path === '' ? 0 : count(array_values(array_filter(explode('/', trim($path, '/')))));
        $combined_tokens = array_values(array_unique(array_merge($path_tokens, $title_tokens, $heading_tokens, $context_tokens)));

        $combined_text = strtolower(trim(implode(' ', array_merge([$path, $title], $headings, $context_tags))));
        $is_article_like = (bool) preg_match('/\b(blog|news|article|post|press|updates?)\b/', $combined_text)
            || (bool) preg_match('#/\d{4}/\d{2}/#', $path);
        $is_contact_like = (bool) preg_match('/\b(contact|book|schedule|quote|request|call)\b/', $combined_text);
        $is_about_like = (bool) preg_match('/\b(about|team|story|mission|values)\b/', $combined_text);
        $is_service_like = (bool) preg_match('/\b(service|services|solution|capability|offering)\b/', $combined_text);
        $is_product_like = (bool) preg_match('/\b(product|platform|feature|pricing|plan)\b/', $combined_text);

        return [
            'path' => $path,
            'path_depth' => $path_depth,
            'title' => $title,
            'tokens' => $combined_tokens,
            'context_tags' => $context_tags,
            'is_article_like' => $is_article_like,
            'is_contact_like' => $is_contact_like,
            'is_about_like' => $is_about_like,
            'is_service_like' => $is_service_like,
            'is_product_like' => $is_product_like,
        ];
    }

    /**
     * @param array<string, mixed> $object_type
     * @param array<string, mixed> $features
     * @param array<string, mixed> $context_artifact
     * @return array<string, mixed>
     */
    private function score_candidate(array $object_type, array $features, array $context_artifact)
    {
        $object_key = isset($object_type['object_key']) ? sanitize_key((string) $object_type['object_key']) : '';
        $label = isset($object_type['label']) ? (string) $object_type['label'] : $object_key;
        $taxonomy_refs = isset($object_type['taxonomy_refs']) && is_array($object_type['taxonomy_refs']) ? array_values($object_type['taxonomy_refs']) : [];
        $candidate_tokens = $this->tokenize($object_key . ' ' . $label);
        $matched_tokens = array_values(array_intersect($candidate_tokens, isset($features['tokens']) && is_array($features['tokens']) ? $features['tokens'] : []));

        $score = $this->get_base_score($object_key);
        $rationale = [];

        if (! empty($matched_tokens)) {
            $score += min(0.18, count($matched_tokens) * 0.06);
            $rationale[] = sprintf('Matched target tokens: %s.', implode(', ', array_slice($matched_tokens, 0, 3)));
        }

        if ($object_key === 'page') {
            if (! empty($features['is_about_like']) || ! empty($features['is_contact_like']) || ! empty($features['is_service_like']) || ! empty($features['is_product_like'])) {
                $score += 0.16;
                $rationale[] = 'Page-like path and section cues align with site information content.';
            }
            if (isset($features['path_depth']) && (int) $features['path_depth'] <= 2) {
                $score += 0.05;
            }
        } elseif ($object_key === 'post') {
            if (! empty($features['is_article_like'])) {
                $score += 0.28;
                $rationale[] = 'Article markers in the URL or headings favor post classification.';
            }
            if (! empty($features['is_about_like']) || ! empty($features['is_contact_like'])) {
                $score -= 0.08;
            }
        } else {
            if (! empty($features['is_service_like']) && in_array('service', $candidate_tokens, true)) {
                $score += 0.14;
                $rationale[] = 'Service-related cues align with the target object label.';
            }
            if (! empty($features['is_product_like']) && (in_array('product', $candidate_tokens, true) || in_array('products', $candidate_tokens, true))) {
                $score += 0.14;
                $rationale[] = 'Product-related cues align with the target object label.';
            }
        }

        if (! empty($object_type['hierarchical']) && isset($features['path_depth']) && (int) $features['path_depth'] > 1) {
            $score += 0.04;
        }

        $context_items = isset($context_artifact['items']) && is_array($context_artifact['items']) ? $context_artifact['items'] : [];
        $source_refs = [];
        if (! empty($context_items) && is_array($context_items[0]) && isset($context_items[0]['source_refs']) && is_array($context_items[0]['source_refs'])) {
            $source_refs = array_values($context_items[0]['source_refs']);
        }

        return [
            'object_key' => $object_key,
            'label' => $label,
            'type_family' => isset($object_type['type_family']) ? (string) $object_type['type_family'] : 'post_type',
            'confidence' => round(max(0.05, min(0.99, $score)), 2),
            'rationale' => empty($rationale)
                ? 'Deterministic classification fell back to the target object baseline.'
                : implode(' ', $rationale),
            'source_refs' => $source_refs,
            'taxonomy_refs' => $taxonomy_refs,
        ];
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $context_artifact
     * @return array<int, array<string, mixed>>
     */
    private function build_taxonomy_hints(array $primary, array $inventory, array $page_context, array $context_artifact)
    {
        $taxonomy_types = isset($inventory['taxonomy_types']) && is_array($inventory['taxonomy_types']) ? $inventory['taxonomy_types'] : [];
        $primary_taxonomies = isset($primary['taxonomy_refs']) && is_array($primary['taxonomy_refs']) ? $primary['taxonomy_refs'] : [];
        if (empty($primary_taxonomies)) {
            return [];
        }

        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $title = strtolower(isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '');
        $context_tags = isset($context_artifact['summary']['dominant_context_tags']) && is_array($context_artifact['summary']['dominant_context_tags'])
            ? array_values($context_artifact['summary']['dominant_context_tags'])
            : [];

        $hints = [];
        foreach ($taxonomy_types as $taxonomy_type) {
            if (! is_array($taxonomy_type)) {
                continue;
            }

            $taxonomy_key = isset($taxonomy_type['taxonomy_key']) ? sanitize_key((string) $taxonomy_type['taxonomy_key']) : '';
            if ($taxonomy_key === '' || ! in_array($taxonomy_key, $primary_taxonomies, true)) {
                continue;
            }

            $confidence = max(0.45, ((float) $primary['confidence']) - 0.22);
            $rationale = 'Taxonomy is registered on the primary target object type.';
            if (($taxonomy_key === 'category' || $taxonomy_key === 'post_tag') && (strpos($title, 'news') !== false || in_array('hero', $context_tags, true))) {
                $confidence += 0.08;
                $rationale = 'Primary target supports editorial taxonomies and the page carries article-like or topic framing cues.';
            }

            $hints[] = [
                'taxonomy_key' => $taxonomy_key,
                'label' => isset($taxonomy_type['label']) ? (string) $taxonomy_type['label'] : $taxonomy_key,
                'confidence' => round(min(0.95, $confidence), 2),
                'rationale' => $rationale,
            ];
        }

        return $hints;
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<int, array<string, mixed>> $alternates
     * @return array<string, mixed>
     */
    private function build_review(array $primary, array $alternates)
    {
        $automation = DBVC_CC_V2_Contracts::get_automation_settings();
        $primary_confidence = isset($primary['confidence']) ? (float) $primary['confidence'] : 0.0;
        $next_confidence = ! empty($alternates) && isset($alternates[0]['confidence']) ? (float) $alternates[0]['confidence'] : 0.0;
        $gap_to_next = round(max(0.0, $primary_confidence - $next_confidence), 2);
        $unambiguous = empty($alternates) || $gap_to_next >= 0.1;
        $reason_codes = [];
        $status = 'needs_review';

        if ($primary_confidence < (float) $automation['blockBelowConfidence']) {
            $status = 'blocked';
            $reason_codes[] = 'low_confidence';
        } elseif (! $unambiguous) {
            $status = 'needs_review';
            $reason_codes[] = 'ambiguous_primary_classification';
        } elseif ($primary_confidence >= (float) $automation['autoAcceptMinConfidence']) {
            $status = 'auto_accept_candidate';
        } else {
            $status = 'needs_review';
            $reason_codes[] = 'manual_review_band';
        }

        return [
            'status' => $status,
            'reason_codes' => $reason_codes,
            'confidence' => round($primary_confidence, 2),
            'unambiguous' => $unambiguous,
            'gap_to_next' => $gap_to_next,
            'policy_snapshot' => $automation,
        ];
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $context_artifact
     * @return array<string, mixed>
     */
    private function build_prompt_input(array $page_context, array $inventory, array $context_artifact)
    {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $object_types = isset($inventory['object_types']) && is_array($inventory['object_types']) ? $inventory['object_types'] : [];

        $inventory_summary = [];
        foreach (array_slice($object_types, 0, 8) as $object_type) {
            if (! is_array($object_type)) {
                continue;
            }

            $inventory_summary[] = [
                'object_key' => isset($object_type['object_key']) ? (string) $object_type['object_key'] : '',
                'label' => isset($object_type['label']) ? (string) $object_type['label'] : '',
                'taxonomy_refs' => isset($object_type['taxonomy_refs']) && is_array($object_type['taxonomy_refs']) ? array_values($object_type['taxonomy_refs']) : [],
            ];
        }

        return [
            'page' => [
                'title' => isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '',
                'path' => isset($page_context['path']) ? (string) $page_context['path'] : '',
            ],
            'context_summary' => isset($context_artifact['summary']) && is_array($context_artifact['summary']) ? $context_artifact['summary'] : [],
            'target_object_inventory' => $inventory_summary,
        ];
    }

    /**
     * @param string $value
     * @return array<int, string>
     */
    private function tokenize($value)
    {
        $value = strtolower((string) $value);
        $parts = preg_split('/[^a-z0-9]+/', $value);
        if (! is_array($parts)) {
            return [];
        }

        return array_values(array_filter(array_map('sanitize_key', $parts)));
    }

    /**
     * @param string $object_key
     * @return float
     */
    private function get_base_score($object_key)
    {
        if ($object_key === 'page') {
            return 0.72;
        }

        if ($object_key === 'post') {
            return 0.46;
        }

        return 0.52;
    }

    /**
     * @return array<string, mixed>
     */
    private function build_fallback_candidate()
    {
        return [
            'object_key' => 'page',
            'label' => 'Page',
            'type_family' => 'post_type',
            'confidence' => 0.51,
            'rationale' => 'No target object candidates were available, so the classifier fell back to the generic page profile.',
            'source_refs' => ['context-creation.v1#summary'],
            'taxonomy_refs' => [],
        ];
    }
}
