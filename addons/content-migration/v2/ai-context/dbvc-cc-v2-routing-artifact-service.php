<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Routing_Artifact_Service
{
    /**
     * @var DBVC_CC_V2_Routing_Artifact_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Routing_Artifact_Service
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
     * @param array<string, mixed> $context_artifact
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function build_artifact(array $page_context, array $context_artifact, array $classification_artifact, array $args = [])
    {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $primary_classification = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];
        $section_routes = $this->build_section_routes($context_artifact);
        $primary_route = $this->build_primary_route($page_context, $raw_artifact, $context_artifact, $primary_classification, $section_routes);
        $review = $this->build_review($classification_artifact, $primary_route, $section_routes);

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'routing-artifact.v1',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'status' => $review['status'] === 'needs_review' ? 'completed_with_warnings' : 'completed',
            'primary_route' => $primary_route,
            'section_routes' => $section_routes,
            'summary' => [
                'page_path' => isset($page_context['path']) ? (string) $page_context['path'] : '',
                'object_key' => isset($primary_route['object_key']) ? (string) $primary_route['object_key'] : '',
                'object_identity' => isset($primary_route['object_identity']) ? (string) $primary_route['object_identity'] : '',
                'page_intent' => isset($primary_route['page_intent']) ? (string) $primary_route['page_intent'] : '',
                'dominant_section_scopes' => $this->extract_dominant_section_scopes($section_routes),
                'average_section_confidence' => $this->calculate_average_section_confidence($section_routes),
            ],
            'review' => $review,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'context_ref' => isset($args['context_ref']) ? (string) $args['context_ref'] : '',
                'classification_ref' => isset($args['classification_ref']) ? (string) $args['classification_ref'] : '',
                'source_fingerprint' => isset($raw_artifact['content_hash']) ? (string) $raw_artifact['content_hash'] : '',
                'prompt_input' => [
                    'path' => isset($page_context['path']) ? (string) $page_context['path'] : '',
                    'page_title' => isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '',
                    'dominant_context_tags' => isset($context_artifact['summary']['dominant_context_tags']) && is_array($context_artifact['summary']['dominant_context_tags'])
                        ? array_values($context_artifact['summary']['dominant_context_tags'])
                        : [],
                ],
            ],
            'stats' => [
                'section_route_count' => count($section_routes),
                'distinct_scope_count' => count($this->extract_dominant_section_scopes($section_routes)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $raw_artifact
     * @param array<string, mixed> $context_artifact
     * @param array<string, mixed> $primary_classification
     * @param array<int, array<string, mixed>> $section_routes
     * @return array<string, mixed>
     */
    private function build_primary_route(
        array $page_context,
        array $raw_artifact,
        array $context_artifact,
        array $primary_classification,
        array $section_routes
    ) {
        $path = isset($page_context['path']) ? (string) $page_context['path'] : (isset($raw_artifact['path']) ? (string) $raw_artifact['path'] : '');
        $title = isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '';
        $context_tags = isset($context_artifact['summary']['dominant_context_tags']) && is_array($context_artifact['summary']['dominant_context_tags'])
            ? array_values($context_artifact['summary']['dominant_context_tags'])
            : [];
        $object_key = isset($primary_classification['object_key']) ? sanitize_key((string) $primary_classification['object_key']) : '';
        $page_intent = $this->infer_page_intent($path, $title, $context_tags, $object_key);
        $dominant_scopes = $this->extract_dominant_section_scopes($section_routes);
        $confidence = isset($primary_classification['confidence']) ? (float) $primary_classification['confidence'] : 0.58;
        if ($page_intent !== 'informational_page' && $page_intent !== '') {
            $confidence += 0.04;
        }
        if ($path === '/' && in_array('hero', $dominant_scopes, true)) {
            $confidence += 0.04;
        }

        $rationale = [];
        if ($object_key !== '') {
            $rationale[] = sprintf('Primary classification routes this URL into the `%s` target family.', $object_key);
        }
        if ($page_intent !== '') {
            $rationale[] = sprintf('Page intent was normalized to `%s` from the path, title, and dominant context tags.', $page_intent);
        }
        if (! empty($dominant_scopes)) {
            $rationale[] = sprintf('Section routing found dominant scopes: %s.', implode(', ', array_slice($dominant_scopes, 0, 3)));
        }

        return [
            'object_key' => $object_key,
            'object_identity' => $this->build_object_identity($object_key, $path, $page_intent),
            'page_intent' => $page_intent,
            'dominant_section_scopes' => $dominant_scopes,
            'confidence' => round(min(0.99, max(0.05, $confidence)), 2),
            'rationale' => $rationale,
            'evidence' => [
                'path' => $path,
                'page_title' => $title,
                'context_tags' => $context_tags,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context_artifact
     * @return array<int, array<string, mixed>>
     */
    private function build_section_routes(array $context_artifact)
    {
        $items = isset($context_artifact['items']) && is_array($context_artifact['items']) ? $context_artifact['items'] : [];
        $routes = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $section_id = $this->extract_section_id($item);
            if ($section_id === '') {
                continue;
            }

            $context_tag = isset($item['context_tag']) ? sanitize_key((string) $item['context_tag']) : '';
            $section_scope = $this->normalize_section_scope(
                $context_tag,
                isset($item['authoring_intent']) ? (string) $item['authoring_intent'] : '',
                isset($item['audience_purpose']) ? (string) $item['audience_purpose'] : ''
            );
            $routes[] = [
                'route_id' => 'route_' . str_pad((string) (count($routes) + 1), 3, '0', STR_PAD_LEFT),
                'section_id' => $section_id,
                'source_refs' => isset($item['source_refs']) && is_array($item['source_refs']) ? array_values($item['source_refs']) : [],
                'context_tag' => $context_tag,
                'section_scope' => $section_scope,
                'authoring_intent' => isset($item['authoring_intent']) ? sanitize_key((string) $item['authoring_intent']) : '',
                'audience_purpose' => isset($item['audience_purpose']) ? sanitize_key((string) $item['audience_purpose']) : '',
                'confidence' => isset($item['confidence']) ? round((float) $item['confidence'], 2) : 0.0,
                'rationale' => isset($item['rationale']) ? sanitize_text_field((string) $item['rationale']) : '',
            ];
        }

        return $routes;
    }

    /**
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $primary_route
     * @param array<int, array<string, mixed>> $section_routes
     * @return array<string, mixed>
     */
    private function build_review(array $classification_artifact, array $primary_route, array $section_routes)
    {
        $classification_review = isset($classification_artifact['review']) && is_array($classification_artifact['review'])
            ? $classification_artifact['review']
            : [];
        $status = 'auto_accept_candidate';
        $reason_codes = [];
        $confidence = isset($primary_route['confidence']) ? (float) $primary_route['confidence'] : 0.0;

        if ($confidence < 0.7) {
            $status = 'needs_review';
            $reason_codes[] = 'low_route_confidence';
        }
        if (empty($section_routes)) {
            $status = 'needs_review';
            $reason_codes[] = 'missing_section_routes';
        }
        if (! empty($classification_review['status']) && in_array((string) $classification_review['status'], ['blocked', 'needs_review'], true)) {
            $status = 'needs_review';
            $reason_codes[] = 'classification_requires_review';
        }

        return [
            'status' => $status,
            'reason_codes' => array_values(array_unique($reason_codes)),
            'confidence' => round($confidence, 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $section_routes
     * @return array<int, string>
     */
    private function extract_dominant_section_scopes(array $section_routes)
    {
        $counts = [];
        foreach ($section_routes as $route) {
            if (! is_array($route) || empty($route['section_scope'])) {
                continue;
            }

            $scope = sanitize_key((string) $route['section_scope']);
            if ($scope === '') {
                continue;
            }

            $counts[$scope] = isset($counts[$scope]) ? $counts[$scope] + 1 : 1;
        }

        arsort($counts);

        return array_values(array_slice(array_keys($counts), 0, 4));
    }

    /**
     * @param array<int, array<string, mixed>> $section_routes
     * @return float
     */
    private function calculate_average_section_confidence(array $section_routes)
    {
        if (empty($section_routes)) {
            return 0.0;
        }

        $values = [];
        foreach ($section_routes as $route) {
            if (! is_array($route) || ! isset($route['confidence'])) {
                continue;
            }

            $values[] = (float) $route['confidence'];
        }

        if (empty($values)) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @param string $path
     * @param string $title
     * @param array<int, string> $context_tags
     * @param string $object_key
     * @return string
     */
    private function infer_page_intent($path, $title, array $context_tags, $object_key)
    {
        $path = strtolower((string) $path);
        $title = strtolower((string) $title);
        $normalized_path = trim(str_replace(['-', '_', '/'], ' ', $path));
        $combined = trim($normalized_path . ' ' . $title . ' ' . implode(' ', array_map('strval', $context_tags)));

        if ($path === '/' || $path === '') {
            return 'homepage';
        }
        if ((bool) preg_match('/\b(pricing|plans?|cost)\b/', $combined)) {
            return 'pricing';
        }
        if ((bool) preg_match('/\b(process|approach|how we work|our process)\b/', $combined)) {
            return 'process';
        }
        if ((bool) preg_match('/\b(contact|book|schedule|quote|get started|consultation)\b/', $combined)) {
            return 'conversion';
        }
        if ((bool) preg_match('/\b(about|team|story|mission|values)\b/', $combined)) {
            return 'about';
        }
        if ((bool) preg_match('/\b(service|services|solution|capability|offering)\b/', $combined)) {
            return 'service_overview';
        }
        if ((bool) preg_match('/\b(blog|news|article|post)\b/', $combined) || $object_key === 'post') {
            return 'article';
        }

        return $object_key === 'page' ? 'informational_page' : $object_key;
    }

    /**
     * @param string $object_key
     * @param string $path
     * @param string $page_intent
     * @return string
     */
    private function build_object_identity($object_key, $path, $page_intent)
    {
        $object_key = sanitize_key((string) $object_key);
        $path = trim((string) $path);
        $page_intent = sanitize_key((string) $page_intent);

        if ($object_key === 'page' && ($path === '' || $path === '/')) {
            return 'page:front_page';
        }

        if ($path !== '') {
            return sprintf('%s:path:%s', $object_key !== '' ? $object_key : 'unknown', untrailingslashit($path === '' ? '/' : $path));
        }

        return sprintf('%s:intent:%s', $object_key !== '' ? $object_key : 'unknown', $page_intent !== '' ? $page_intent : 'general');
    }

    /**
     * @param string $context_tag
     * @param string $authoring_intent
     * @param string $audience_purpose
     * @return string
     */
    private function normalize_section_scope($context_tag, $authoring_intent, $audience_purpose)
    {
        $context_tag = sanitize_key((string) $context_tag);
        $authoring_intent = sanitize_key((string) $authoring_intent);
        $audience_purpose = sanitize_key((string) $audience_purpose);

        if (in_array($context_tag, ['hero', 'banner'], true)) {
            return 'hero';
        }
        if (in_array($context_tag, ['faq', 'question', 'answer'], true)) {
            return 'faq';
        }
        if (in_array($context_tag, ['pricing', 'price'], true)) {
            return 'pricing';
        }
        if (in_array($context_tag, ['process', 'journey', 'steps'], true) || strpos($authoring_intent, 'process') !== false) {
            return 'process';
        }
        if (in_array($context_tag, ['about', 'team', 'story'], true)) {
            return 'about';
        }
        if (in_array($context_tag, ['testimonial', 'proof', 'quote'], true)) {
            return 'social_proof';
        }
        if (in_array($context_tag, ['cta', 'contact'], true) || $audience_purpose === 'convert') {
            return 'conversion';
        }
        if (in_array($context_tag, ['nav', 'navigation', 'utility', 'footer'], true)) {
            return 'utility';
        }

        return $context_tag !== '' ? $context_tag : 'content';
    }

    /**
     * @param array<string, mixed> $item
     * @return string
     */
    private function extract_section_id(array $item)
    {
        $source_refs = isset($item['source_refs']) && is_array($item['source_refs']) ? $item['source_refs'] : [];
        foreach ($source_refs as $source_ref) {
            $source_ref = (string) $source_ref;
            if (strpos($source_ref, 'sections.v2#') !== 0) {
                continue;
            }

            $section_id = sanitize_key(substr($source_ref, strlen('sections.v2#')));
            if ($section_id !== '') {
                return $section_id;
            }
        }

        return '';
    }
}
