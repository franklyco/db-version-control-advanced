<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Workbench_Service
{
    public const DECISION_APPROVE = 'approved';
    public const DECISION_REJECT = 'rejected';
    public const DECISION_EDIT = 'edited';

    /**
     * @return DBVC_CC_Workbench_Service
     */
    public static function get_instance()
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }

        return $instance;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function get_review_queue($args)
    {
        $domain_filter = isset($args['domain']) ? $this->sanitize_domain((string) $args['domain']) : '';
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        $limit = max(1, min(200, $limit));
        $include_decided = ! empty($args['include_decided']);
        $min_confidence = isset($args['min_confidence']) ? (float) $args['min_confidence'] : DBVC_CC_AI_Service::REVIEW_CONFIDENCE_THRESHOLD;
        $min_confidence = max(0.0, min(1.0, $min_confidence));

        $suggestion_contexts = $this->discover_suggestion_contexts($domain_filter);
        if (is_wp_error($suggestion_contexts)) {
            return $suggestion_contexts;
        }

        $items = [];
        $pending_count = 0;
        $reviewed_count = 0;

        foreach ($suggestion_contexts as $context) {
            $suggestions = $this->read_json_file($context['suggestions_file']);
            if (! is_array($suggestions)) {
                continue;
            }

            $item = $this->build_queue_item($context, $suggestions, $min_confidence);
            if ($item === null) {
                continue;
            }

            $decision = $this->read_json_file($context['review_file']);
            $decision_status = is_array($decision) && isset($decision['decision'])
                ? sanitize_key((string) $decision['decision'])
                : 'pending';

            if ($decision_status !== 'pending') {
                $reviewed_count++;
                if (! $include_decided) {
                    continue;
                }
            } else {
                $pending_count++;
            }

            $item['decision'] = [
                'status' => $decision_status,
                'updated_at' => is_array($decision) && isset($decision['updated_at']) ? (string) $decision['updated_at'] : null,
                'reviewed_by' => is_array($decision) && isset($decision['reviewed_by']) ? absint($decision['reviewed_by']) : 0,
                'notes' => is_array($decision) && isset($decision['notes']) ? sanitize_text_field((string) $decision['notes']) : '',
            ];

            $items[] = $item;
        }

        usort(
            $items,
            static function ($left, $right) {
                $left_domain = isset($left['domain']) ? (string) $left['domain'] : '';
                $right_domain = isset($right['domain']) ? (string) $right['domain'] : '';
                $domain_cmp = strnatcasecmp($left_domain, $right_domain);
                if ($domain_cmp !== 0) {
                    return $domain_cmp;
                }

                $left_path = isset($left['path']) ? (string) $left['path'] : '';
                $right_path = isset($right['path']) ? (string) $right['path'] : '';
                return strnatcasecmp($left_path, $right_path);
            }
        );

        if (count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
        }

        return [
            'schema_version' => '1.0',
            'generated_at' => current_time('c'),
            'domain' => $domain_filter,
            'include_decided' => $include_decided,
            'min_confidence' => $min_confidence,
            'total_items' => count($items),
            'pending_items' => $pending_count,
            'reviewed_items' => $reviewed_count,
            'items' => $items,
        ];
    }

    /**
     * Returns available crawl domains for Workbench selectors.
     *
     * @return array<int, array<string, string>>
     */
    public function dbvc_cc_get_domains()
    {
        $dbvc_cc_domains = [];
        $dbvc_cc_entries = DBVC_CC_Artifact_Manager::list_domain_keys();
        foreach ($dbvc_cc_entries as $dbvc_cc_entry) {
            $dbvc_cc_domain_key = $this->sanitize_domain((string) $dbvc_cc_entry);
            if ($dbvc_cc_domain_key === '') {
                continue;
            }

            $dbvc_cc_domains[$dbvc_cc_domain_key] = [
                'key' => $dbvc_cc_domain_key,
                'label' => $dbvc_cc_domain_key,
                'dbvc_cc_ai_health' => DBVC_CC_AI_Service::get_instance()->dbvc_cc_get_domain_ai_health($dbvc_cc_domain_key),
            ];
        }

        $dbvc_cc_domain_rows = array_values($dbvc_cc_domains);
        usort(
            $dbvc_cc_domain_rows,
            static function ($left, $right) {
                $dbvc_cc_left_key = isset($left['key']) ? (string) $left['key'] : '';
                $dbvc_cc_right_key = isset($right['key']) ? (string) $right['key'] : '';
                return strnatcasecmp($dbvc_cc_left_key, $dbvc_cc_right_key);
            }
        );

        return $dbvc_cc_domain_rows;
    }

    /**
     * Rebuilds mapping artifacts for every discovered path in a domain.
     *
     * @param string              $dbvc_cc_domain
     * @param array<string, mixed> $dbvc_cc_args
     * @return array<string, mixed>|WP_Error
     */
    public function dbvc_cc_rebuild_domain_mapping_artifacts($dbvc_cc_domain, array $dbvc_cc_args = [])
    {
        $dbvc_cc_domain = $this->sanitize_domain($dbvc_cc_domain);
        if ($dbvc_cc_domain === '') {
            return new WP_Error(
                'dbvc_cc_workbench_invalid_domain',
                __('A valid domain is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $dbvc_cc_force_rebuild = isset($dbvc_cc_args['force_rebuild'])
            ? rest_sanitize_boolean($dbvc_cc_args['force_rebuild'])
            : true;
        $dbvc_cc_refresh_catalog = isset($dbvc_cc_args['refresh_catalog'])
            ? rest_sanitize_boolean($dbvc_cc_args['refresh_catalog'])
            : false;

        $dbvc_cc_paths = DBVC_CC_Artifact_Manager::dbvc_cc_list_domain_relative_paths($dbvc_cc_domain);
        if (empty($dbvc_cc_paths)) {
            return new WP_Error(
                'dbvc_cc_workbench_domain_empty',
                __('No page artifacts were found for the selected domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        $dbvc_cc_catalog_fingerprint = '';
        $dbvc_cc_catalog_result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($dbvc_cc_domain, $dbvc_cc_refresh_catalog);
        if (is_wp_error($dbvc_cc_catalog_result)) {
            return $dbvc_cc_catalog_result;
        }
        if (is_array($dbvc_cc_catalog_result) && isset($dbvc_cc_catalog_result['catalog_fingerprint'])) {
            $dbvc_cc_catalog_fingerprint = (string) $dbvc_cc_catalog_result['catalog_fingerprint'];
        }

        $dbvc_cc_page_results = [];
        $dbvc_cc_errors = [];
        $dbvc_cc_success_pages = 0;
        $dbvc_cc_failed_pages = 0;
        $dbvc_cc_section_built = 0;
        $dbvc_cc_media_built = 0;

        foreach ($dbvc_cc_paths as $dbvc_cc_path) {
            $dbvc_cc_path_result = $this->dbvc_cc_rebuild_mapping_artifacts_for_path(
                $dbvc_cc_domain,
                (string) $dbvc_cc_path,
                $dbvc_cc_force_rebuild
            );
            if (is_wp_error($dbvc_cc_path_result)) {
                $dbvc_cc_failed_pages++;
                if (count($dbvc_cc_errors) < 50) {
                    $dbvc_cc_errors[] = [
                        'path' => (string) $dbvc_cc_path,
                        'section_status' => 'error',
                        'media_status' => 'error',
                        'section_error_code' => $dbvc_cc_path_result->get_error_code(),
                        'section_error_message' => $dbvc_cc_path_result->get_error_message(),
                    ];
                }
                continue;
            }

            $dbvc_cc_row = is_array($dbvc_cc_path_result) ? $dbvc_cc_path_result : [];
            if ((isset($dbvc_cc_row['section_status']) ? (string) $dbvc_cc_row['section_status'] : '') === 'built') {
                $dbvc_cc_section_built++;
            }
            if ((isset($dbvc_cc_row['media_status']) ? (string) $dbvc_cc_row['media_status'] : '') === 'built') {
                $dbvc_cc_media_built++;
            }

            if (! empty($dbvc_cc_row['has_error'])) {
                $dbvc_cc_failed_pages++;
                if (count($dbvc_cc_errors) < 50) {
                    $dbvc_cc_errors[] = $dbvc_cc_row;
                }
            } else {
                $dbvc_cc_success_pages++;
            }

            if (count($dbvc_cc_page_results) < 200) {
                $dbvc_cc_page_results[] = $dbvc_cc_row;
            }
        }

        $dbvc_cc_status = 'rebuilt';
        if ($dbvc_cc_failed_pages > 0 && $dbvc_cc_success_pages > 0) {
            $dbvc_cc_status = 'partial';
        } elseif ($dbvc_cc_failed_pages > 0) {
            $dbvc_cc_status = 'failed';
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage' => 'mapping_workbench',
                'status' => $dbvc_cc_status,
                'page_url' => 'https://' . $dbvc_cc_domain . '/',
                'path' => '',
                'message' => sprintf(
                    'Domain mapping rebuild processed %d paths (%d success, %d failed).',
                    count($dbvc_cc_paths),
                    $dbvc_cc_success_pages,
                    $dbvc_cc_failed_pages
                ),
            ]
        );

        return [
            'schema_version' => '1.0',
            'generated_at' => current_time('c'),
            'status' => $dbvc_cc_status,
            'domain' => $dbvc_cc_domain,
            'catalog_fingerprint' => $dbvc_cc_catalog_fingerprint,
            'refresh_catalog' => $dbvc_cc_refresh_catalog,
            'force_rebuild' => $dbvc_cc_force_rebuild,
            'total_paths' => count($dbvc_cc_paths),
            'success_paths' => $dbvc_cc_success_pages,
            'failed_paths' => $dbvc_cc_failed_pages,
            'section_built_count' => $dbvc_cc_section_built,
            'media_built_count' => $dbvc_cc_media_built,
            'pages' => $dbvc_cc_page_results,
            'errors' => $dbvc_cc_errors,
        ];
    }

    /**
     * Rebuilds mapping candidate artifacts for a single page path.
     *
     * @param string $dbvc_cc_domain
     * @param string $dbvc_cc_path
     * @param bool   $dbvc_cc_force_rebuild
     * @return array<string, mixed>|WP_Error
     */
    public function dbvc_cc_rebuild_mapping_artifacts_for_path($dbvc_cc_domain, $dbvc_cc_path, $dbvc_cc_force_rebuild = true)
    {
        $dbvc_cc_domain = $this->sanitize_domain($dbvc_cc_domain);
        $dbvc_cc_path = $this->normalize_relative_path($dbvc_cc_path);
        if ($dbvc_cc_domain === '' || $dbvc_cc_path === '') {
            return new WP_Error(
                'dbvc_cc_workbench_invalid_path_context',
                __('A valid domain and path are required for mapping rebuild.', 'dbvc'),
                ['status' => 400]
            );
        }

        $dbvc_cc_row = [
            'path' => (string) $dbvc_cc_path,
            'section_status' => '',
            'media_status' => '',
            'has_error' => false,
        ];

        $dbvc_cc_section_result = DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates(
            $dbvc_cc_domain,
            (string) $dbvc_cc_path,
            $dbvc_cc_force_rebuild
        );
        if (is_wp_error($dbvc_cc_section_result)) {
            $dbvc_cc_row['has_error'] = true;
            $dbvc_cc_row['section_status'] = 'error';
            $dbvc_cc_row['section_error_code'] = $dbvc_cc_section_result->get_error_code();
            $dbvc_cc_row['section_error_message'] = $dbvc_cc_section_result->get_error_message();
        } else {
            $dbvc_cc_row['section_status'] = isset($dbvc_cc_section_result['status']) ? (string) $dbvc_cc_section_result['status'] : 'built';
        }

        if (class_exists('DBVC_CC_Media_Candidate_Service')) {
            $dbvc_cc_media_result = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates(
                $dbvc_cc_domain,
                (string) $dbvc_cc_path,
                $dbvc_cc_force_rebuild
            );
            if (is_wp_error($dbvc_cc_media_result)) {
                $dbvc_cc_row['has_error'] = true;
                $dbvc_cc_row['media_status'] = 'error';
                $dbvc_cc_row['media_error_code'] = $dbvc_cc_media_result->get_error_code();
                $dbvc_cc_row['media_error_message'] = $dbvc_cc_media_result->get_error_message();
            } else {
                $dbvc_cc_row['media_status'] = isset($dbvc_cc_media_result['status']) ? (string) $dbvc_cc_media_result['status'] : 'built';
            }
        }

        if ($dbvc_cc_row['media_status'] === '') {
            $dbvc_cc_row['media_status'] = 'skipped';
        }

        return $dbvc_cc_row;
    }

    /**
     * @param string $domain
     * @param string $path
     * @return array<string, mixed>|WP_Error
     */
    public function get_suggestions($domain, $path)
    {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $suggestions = $this->read_json_file($context['suggestions_file']);
        if (! is_array($suggestions)) {
            return new WP_Error(
                'dbvc_cc_workbench_missing_suggestions',
                __('No mapping suggestion artifact found for this node.', 'dbvc'),
                ['status' => 404]
            );
        }

        $decision = $this->read_json_file($context['review_file']);

        return [
            'schema_version' => '1.0',
            'generated_at' => current_time('c'),
            'domain' => $context['domain'],
            'path' => $context['path'],
            'suggestions' => $suggestions,
            'decision' => is_array($decision) ? $decision : [
                'decision' => 'pending',
                'updated_at' => null,
                'reviewed_by' => 0,
                'notes' => '',
                'overrides' => new stdClass(),
            ],
        ];
    }

    /**
     * @param string              $domain
     * @param string              $path
     * @param array<string, mixed> $payload
     * @param int                 $user_id
     * @return array<string, mixed>|WP_Error
     */
    public function save_decision($domain, $path, $payload, $user_id)
    {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $suggestions = $this->read_json_file($context['suggestions_file']);
        if (! is_array($suggestions)) {
            return new WP_Error(
                'dbvc_cc_workbench_missing_suggestions',
                __('No mapping suggestion artifact found for this node.', 'dbvc'),
                ['status' => 404]
            );
        }

        $decision = isset($payload['decision']) ? sanitize_key((string) $payload['decision']) : '';
        $allowed = [self::DECISION_APPROVE, self::DECISION_REJECT, self::DECISION_EDIT];
        if (! in_array($decision, $allowed, true)) {
            return new WP_Error(
                'dbvc_cc_workbench_invalid_decision',
                __('Decision must be one of: approved, rejected, edited.', 'dbvc'),
                ['status' => 400]
            );
        }

        $notes = isset($payload['notes']) ? sanitize_textarea_field((string) $payload['notes']) : '';
        $overrides = isset($payload['overrides']) && is_array($payload['overrides'])
            ? $this->sanitize_overrides($payload['overrides'])
            : [];

        $record = [
            'schema_version' => '1.0',
            'decision' => $decision,
            'notes' => $notes,
            'overrides' => $overrides,
            'domain' => $context['domain'],
            'path' => $context['path'],
            'source_url' => isset($suggestions['source_url']) ? esc_url_raw((string) $suggestions['source_url']) : '',
            'updated_at' => current_time('c'),
            'reviewed_by' => absint($user_id),
        ];

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['review_file'], $record)) {
            return new WP_Error(
                'dbvc_cc_workbench_write_failed',
                __('Could not persist mapping review decision.', 'dbvc'),
                ['status' => 500]
            );
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage' => 'mapping_workbench',
                'status' => 'saved',
                'page_url' => isset($suggestions['source_url']) ? (string) $suggestions['source_url'] : '',
                'path' => $context['path'],
                'message' => sprintf('Mapping review marked as %s.', $decision),
            ]
        );

        return [
            'schema_version' => '1.0',
            'message' => __('Mapping review decision saved.', 'dbvc'),
            'decision' => $record,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sanitize_overrides($overrides)
    {
        $sanitized = [];

        foreach ($overrides as $key => $value) {
            $safe_key = sanitize_key((string) $key);
            if ($safe_key === '') {
                continue;
            }

            if (is_array($value)) {
                $nested = [];
                foreach ($value as $nested_key => $nested_value) {
                    $safe_nested_key = sanitize_key((string) $nested_key);
                    if ($safe_nested_key === '') {
                        continue;
                    }
                    $nested[$safe_nested_key] = is_scalar($nested_value)
                        ? sanitize_text_field((string) $nested_value)
                        : '';
                }
                $sanitized[$safe_key] = $nested;
                continue;
            }

            $sanitized[$safe_key] = is_scalar($value)
                ? sanitize_text_field((string) $value)
                : '';
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $suggestions
     * @param float                $min_confidence
     * @return array<string, mixed>|null
     */
    private function build_queue_item($context, $suggestions, $min_confidence)
    {
        $review = isset($suggestions['review']) && is_array($suggestions['review']) ? $suggestions['review'] : [];
        $conflicts = isset($suggestions['conflicts']) && is_array($suggestions['conflicts'])
            ? array_values($suggestions['conflicts'])
            : [];

        $post_type = isset($suggestions['suggestions']['post_type']) && is_array($suggestions['suggestions']['post_type'])
            ? $suggestions['suggestions']['post_type']
            : [];
        $post_confidence = isset($post_type['confidence']) ? (float) $post_type['confidence'] : 0.0;

        $taxonomy_terms = isset($suggestions['suggestions']['taxonomy']['terms']) && is_array($suggestions['suggestions']['taxonomy']['terms'])
            ? $suggestions['suggestions']['taxonomy']['terms']
            : [];

        $has_low_confidence_term = false;
        foreach ($taxonomy_terms as $term) {
            if (is_array($term) && isset($term['confidence']) && (float) $term['confidence'] < $min_confidence) {
                $has_low_confidence_term = true;
                break;
            }
        }

        $needs_review = ! empty($review['needs_review'])
            || ! empty($conflicts)
            || $post_confidence < $min_confidence
            || $has_low_confidence_term;

        if (! $needs_review) {
            return null;
        }

        $custom_fields = isset($suggestions['suggestions']['custom_fields']) && is_array($suggestions['suggestions']['custom_fields'])
            ? $suggestions['suggestions']['custom_fields']
            : [];
        $media_roles = isset($suggestions['suggestions']['media_roles']) && is_array($suggestions['suggestions']['media_roles'])
            ? $suggestions['suggestions']['media_roles']
            : [];
        $dbvc_cc_mapping_health = $this->dbvc_cc_build_mapping_health($context);

        return [
            'domain' => $context['domain'],
            'path' => $context['path'],
            'source_url' => isset($suggestions['source_url']) ? esc_url_raw((string) $suggestions['source_url']) : '',
            'status' => 'pending',
            'review' => [
                'needs_review' => true,
                'reasons' => isset($review['reasons']) && is_array($review['reasons']) ? array_values($review['reasons']) : [],
                'confidence_threshold' => isset($review['confidence_threshold']) ? (float) $review['confidence_threshold'] : $min_confidence,
            ],
            'conflicts' => array_values($conflicts),
            'suggestion_summary' => [
                'post_type' => isset($post_type['value']) ? sanitize_key((string) $post_type['value']) : '',
                'post_type_confidence' => $post_confidence,
                'taxonomy_terms_count' => count($taxonomy_terms),
                'custom_field_count' => count($custom_fields),
                'media_role_count' => count($media_roles),
            ],
            'mapping_health' => $dbvc_cc_mapping_health,
        ];
    }

    /**
     * @param string $domain_filter
     * @return array<int, array<string, string>>|WP_Error
     */
    private function discover_suggestion_contexts($domain_filter)
    {
        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_dir($base_dir)) {
            return new WP_Error(
                'dbvc_cc_storage_missing',
                __('Content collector storage path does not exist yet.', 'dbvc'),
                ['status' => 404]
            );
        }

        $base_real = realpath($base_dir);
        if (! is_string($base_real)) {
            return new WP_Error(
                'dbvc_cc_storage_invalid',
                __('Could not resolve storage path.', 'dbvc'),
                ['status' => 500]
            );
        }

        $domain_dirs = [];
        if ($domain_filter !== '') {
            $candidate = trailingslashit($base_real) . $domain_filter;
            if (! is_dir($candidate)) {
                return new WP_Error(
                    'dbvc_cc_domain_missing',
                    __('No crawl data found for this domain.', 'dbvc'),
                    ['status' => 404]
                );
            }
            $domain_dirs[] = [
                'domain' => $domain_filter,
                'dir' => $candidate,
            ];
        } else {
            $entries = @scandir($base_real);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                        continue;
                    }

                    $entry_dir = trailingslashit($base_real) . $entry;
                    if (! is_dir($entry_dir)) {
                        continue;
                    }

                    $domain_dirs[] = [
                        'domain' => $entry,
                        'dir' => $entry_dir,
                    ];
                }
            }
        }

        $contexts = [];
        foreach ($domain_dirs as $domain_entry) {
            $domain = (string) $domain_entry['domain'];
            $domain_dir = (string) $domain_entry['dir'];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($domain_dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $filename = (string) $file->getFilename();
                if (substr($filename, -25) !== '.mapping.suggestions.json') {
                    continue;
                }

                $absolute = $file->getPathname();
                if (! dbvc_cc_path_is_within($absolute, $base_real)) {
                    continue;
                }

                $dir = $file->getPath();
                $relative_dir = trim(str_replace(wp_normalize_path($domain_dir), '', wp_normalize_path($dir)), '/');
                if ($relative_dir === '') {
                    continue;
                }

                $slug = basename($relative_dir);
                $contexts[] = [
                    'domain' => $domain,
                    'path' => $relative_dir,
                    'slug' => $slug,
                    'page_dir' => $dir,
                    'suggestions_file' => $absolute,
                    'review_file' => trailingslashit($dir) . $slug . '.mapping.review.json',
                ];
            }
        }

        return $contexts;
    }

    /**
     * @param string $domain
     * @param string $path
     * @return array<string, string>|WP_Error
     */
    private function resolve_page_context($domain, $path)
    {
        $domain = $this->sanitize_domain($domain);
        $path = $this->normalize_relative_path($path);
        if ($domain === '') {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }
        if ($path === '') {
            return new WP_Error('dbvc_cc_invalid_path', __('A valid page path is required.', 'dbvc'), ['status' => 400]);
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_dir($base_dir)) {
            return new WP_Error('dbvc_cc_storage_missing', __('Content collector storage path does not exist.', 'dbvc'), ['status' => 404]);
        }

        $base_real = realpath($base_dir);
        if (! is_string($base_real)) {
            return new WP_Error('dbvc_cc_storage_invalid', __('Could not resolve storage path.', 'dbvc'), ['status' => 500]);
        }

        $domain_dir = trailingslashit($base_real) . $domain;
        if (! is_dir($domain_dir)) {
            return new WP_Error('dbvc_cc_domain_missing', __('No crawl data found for this domain.', 'dbvc'), ['status' => 404]);
        }

        $target_dir = trailingslashit($domain_dir) . $path;
        if (! is_dir($target_dir)) {
            return new WP_Error('dbvc_cc_missing_path', __('The selected page path does not exist.', 'dbvc'), ['status' => 404]);
        }

        $domain_real = realpath($domain_dir);
        $target_real = realpath($target_dir);
        if (! is_string($domain_real) || ! is_string($target_real) || strpos($target_real, $domain_real) !== 0) {
            return new WP_Error('dbvc_cc_invalid_path', __('Invalid page path.', 'dbvc'), ['status' => 400]);
        }

        $slug = basename($path);
        return [
            'domain' => $domain,
            'path' => $path,
            'suggestions_file' => trailingslashit($target_real) . $slug . '.mapping.suggestions.json',
            'review_file' => trailingslashit($target_real) . $slug . '.mapping.review.json',
        ];
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        if (! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $domain
     * @return string
     */
    private function sanitize_domain($domain)
    {
        return preg_replace('/[^a-z0-9.-]/', '', strtolower((string) $domain));
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalize_relative_path($path)
    {
        $value = str_replace('\\', '/', urldecode((string) $path));
        $value = trim($value, '/');
        if ($value === '') {
            return '';
        }

        $parts = [];
        foreach (explode('/', $value) as $part) {
            $part = sanitize_title($part);
            if ($part !== '' && $part !== '..') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * @param array<string, mixed> $dbvc_cc_context
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_mapping_health(array $dbvc_cc_context)
    {
        $dbvc_cc_slug = isset($dbvc_cc_context['slug']) ? sanitize_title((string) $dbvc_cc_context['slug']) : '';
        $dbvc_cc_page_dir = isset($dbvc_cc_context['page_dir']) ? (string) $dbvc_cc_context['page_dir'] : '';
        if ($dbvc_cc_slug === '' || $dbvc_cc_page_dir === '' || ! is_dir($dbvc_cc_page_dir)) {
            return [
                'stale' => false,
                'reasons' => [],
                'section_stale' => false,
                'media_stale' => false,
                'mapping_decision_outdated' => false,
                'media_decision_outdated' => false,
            ];
        }

        $dbvc_cc_files = [
            'artifact' => trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . '.json',
            'elements' => trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX,
            'sections' => trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX,
            'section_typing' => trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . DBVC_CC_Contracts::STORAGE_SECTION_TYPING_V2_SUFFIX,
            'section_candidates' => trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . DBVC_CC_Contracts::STORAGE_SECTION_FIELD_CANDIDATES_V1_SUFFIX,
            'media_candidates' => trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . DBVC_CC_Contracts::STORAGE_MEDIA_CANDIDATES_V1_SUFFIX,
            'mapping_decision' => trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . DBVC_CC_Contracts::STORAGE_MAPPING_DECISIONS_V1_SUFFIX,
            'media_decision' => trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . DBVC_CC_Contracts::STORAGE_MEDIA_DECISIONS_V1_SUFFIX,
        ];

        $dbvc_cc_reasons = [];
        $dbvc_cc_section_stale_reason = $this->dbvc_cc_detect_candidate_stale_reason(
            $dbvc_cc_files['section_candidates'],
            [
                $dbvc_cc_files['artifact'],
                $dbvc_cc_files['elements'],
                $dbvc_cc_files['sections'],
                $dbvc_cc_files['section_typing'],
            ]
        );
        $dbvc_cc_media_stale_reason = $this->dbvc_cc_detect_candidate_stale_reason(
            $dbvc_cc_files['media_candidates'],
            [
                $dbvc_cc_files['artifact'],
                $dbvc_cc_files['elements'],
                $dbvc_cc_files['sections'],
            ]
        );

        $dbvc_cc_section_stale = $dbvc_cc_section_stale_reason !== '';
        $dbvc_cc_media_stale = $dbvc_cc_media_stale_reason !== '';

        if ($dbvc_cc_section_stale) {
            $dbvc_cc_reasons[] = 'section_' . $dbvc_cc_section_stale_reason;
        }
        if ($dbvc_cc_media_stale) {
            $dbvc_cc_reasons[] = 'media_' . $dbvc_cc_media_stale_reason;
        }

        $dbvc_cc_mapping_decision_outdated = false;
        if (is_file($dbvc_cc_files['mapping_decision']) && is_file($dbvc_cc_files['section_candidates'])) {
            $dbvc_cc_mapping_decision_outdated = (int) @filemtime($dbvc_cc_files['mapping_decision']) < (int) @filemtime($dbvc_cc_files['section_candidates']);
            if ($dbvc_cc_mapping_decision_outdated) {
                $dbvc_cc_reasons[] = 'mapping_decision_outdated';
            }
        }

        $dbvc_cc_media_decision_outdated = false;
        if (is_file($dbvc_cc_files['media_decision']) && is_file($dbvc_cc_files['media_candidates'])) {
            $dbvc_cc_media_decision_outdated = (int) @filemtime($dbvc_cc_files['media_decision']) < (int) @filemtime($dbvc_cc_files['media_candidates']);
            if ($dbvc_cc_media_decision_outdated) {
                $dbvc_cc_reasons[] = 'media_decision_outdated';
            }
        }

        $dbvc_cc_reasons = array_values(array_unique($dbvc_cc_reasons));

        return [
            'stale' => ! empty($dbvc_cc_reasons),
            'reasons' => $dbvc_cc_reasons,
            'section_stale' => $dbvc_cc_section_stale,
            'media_stale' => $dbvc_cc_media_stale,
            'mapping_decision_outdated' => $dbvc_cc_mapping_decision_outdated,
            'media_decision_outdated' => $dbvc_cc_media_decision_outdated,
        ];
    }

    /**
     * @param string              $dbvc_cc_candidate_file
     * @param array<int, string> $dbvc_cc_dependencies
     * @return string
     */
    private function dbvc_cc_detect_candidate_stale_reason($dbvc_cc_candidate_file, array $dbvc_cc_dependencies)
    {
        if (! is_file($dbvc_cc_candidate_file)) {
            return 'missing';
        }

        $dbvc_cc_candidate_payload = $this->read_json_file($dbvc_cc_candidate_file);
        if (! is_array($dbvc_cc_candidate_payload)) {
            return 'invalid_json';
        }

        $dbvc_cc_schema = isset($dbvc_cc_candidate_payload['artifact_schema_version']) ? (string) $dbvc_cc_candidate_payload['artifact_schema_version'] : '';
        if ($dbvc_cc_schema !== DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION) {
            return 'schema_mismatch';
        }

        $dbvc_cc_candidate_mtime = (int) @filemtime($dbvc_cc_candidate_file);
        if ($dbvc_cc_candidate_mtime <= 0) {
            return 'missing';
        }

        $dbvc_cc_dependency_mtime = $this->dbvc_cc_max_mtime($dbvc_cc_dependencies);
        if ($dbvc_cc_dependency_mtime > $dbvc_cc_candidate_mtime) {
            return 'source_artifact_newer';
        }

        return '';
    }

    /**
     * @param array<int, string> $dbvc_cc_paths
     * @return int
     */
    private function dbvc_cc_max_mtime(array $dbvc_cc_paths)
    {
        $dbvc_cc_latest = 0;
        foreach ($dbvc_cc_paths as $dbvc_cc_path) {
            if (! is_string($dbvc_cc_path) || $dbvc_cc_path === '' || ! is_file($dbvc_cc_path)) {
                continue;
            }

            $dbvc_cc_mtime = (int) @filemtime($dbvc_cc_path);
            if ($dbvc_cc_mtime > $dbvc_cc_latest) {
                $dbvc_cc_latest = $dbvc_cc_mtime;
            }
        }

        return $dbvc_cc_latest;
    }
}
