<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/wp-load.php';

/**
 * Find the most recent post by slug for a specific post type.
 */
function dbvc_ai_runtime_smoke_find_post_by_slug(string $slug, string $post_type): ?WP_Post
{
    $slug = sanitize_title($slug);
    $post_type = sanitize_key($post_type);

    if ($slug === '' || $post_type === '') {
        return null;
    }

    $posts = get_posts([
        'post_type' => [$post_type],
        'name' => $slug,
        'post_status' => 'any',
        'posts_per_page' => 1,
        'orderby' => 'ID',
        'order' => 'DESC',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ]);

    if (! empty($posts[0]) && $posts[0] instanceof WP_Post) {
        return $posts[0];
    }

    $candidate = get_page_by_path($slug, OBJECT, $post_type);

    return $candidate instanceof WP_Post ? $candidate : null;
}

/**
 * @param array<int,array<string,mixed>> $fields
 * @return array<string,mixed>|null
 */
function dbvc_ai_runtime_smoke_find_acf_field(array $fields, string $field_name): ?array
{
    foreach ($fields as $field) {
        if (! is_array($field)) {
            continue;
        }

        if (($field['name'] ?? '') === $field_name) {
            return $field;
        }

        if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
            $match = dbvc_ai_runtime_smoke_find_acf_field($field['sub_fields'], $field_name);
            if (is_array($match)) {
                return $match;
            }
        }

        if (! empty($field['layouts']) && is_array($field['layouts'])) {
            foreach ($field['layouts'] as $layout) {
                if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                    continue;
                }

                $match = dbvc_ai_runtime_smoke_find_acf_field($layout['sub_fields'], $field_name);
                if (is_array($match)) {
                    return $match;
                }
            }
        }
    }

    return null;
}

/**
 * @return array<string,string>
 */
function dbvc_ai_runtime_smoke_get_required_acf_fields(): array
{
    $context = \Dbvc\AiPackage\SiteFingerprintService::build_current_context();
    $schema_bundle = isset($context['schema_bundle']) && is_array($context['schema_bundle']) ? $context['schema_bundle'] : [];
    $post_catalog = isset($schema_bundle['field_catalog']['post_types']['post']) && is_array($schema_bundle['field_catalog']['post_types']['post'])
        ? $schema_bundle['field_catalog']['post_types']['post']
        : [];
    $acf_groups = isset($post_catalog['acf']['groups']) && is_array($post_catalog['acf']['groups']) ? $post_catalog['acf']['groups'] : [];

    $required = [
        'article_related_teammember' => '',
        'filter_tag' => '',
    ];

    foreach ($acf_groups as $group) {
        if (! is_array($group) || empty($group['fields']) || ! is_array($group['fields'])) {
            continue;
        }

        foreach (array_keys($required) as $field_name) {
            if ($required[$field_name] !== '') {
                continue;
            }

            $match = dbvc_ai_runtime_smoke_find_acf_field($group['fields'], $field_name);
            if (is_array($match) && ! empty($match['key'])) {
                $required[$field_name] = (string) $match['key'];
            }
        }
    }

    return $required;
}

$admin_users = get_users([
    'role' => 'administrator',
    'number' => 1,
    'orderby' => 'ID',
    'order' => 'ASC',
]);

if (! empty($admin_users[0]) && $admin_users[0] instanceof WP_User) {
    wp_set_current_user((int) $admin_users[0]->ID);
}

$field_keys = dbvc_ai_runtime_smoke_get_required_acf_fields();
foreach ($field_keys as $field_name => $field_key) {
    if ($field_key === '') {
        throw new RuntimeException(sprintf('Required ACF field `%s` is not available in the current site schema.', $field_name));
    }
}

$suffix = strtolower(wp_generate_password(8, false, false));
$page_parent_slug = 'dbvc-ai-parent-' . $suffix;
$page_child_slug = 'dbvc-ai-child-' . $suffix;
$term_parent_slug = 'dbvc-ai-cat-parent-' . $suffix;
$term_child_slug = 'dbvc-ai-cat-child-' . $suffix;
$post_slug = 'dbvc-ai-post-' . $suffix;
$team_member_slug = 'dbvc-ai-team-member-' . $suffix;
$filter_tag_slug = 'dbvc-ai-filter-tag-' . $suffix;
$socialgrid_slug = 'dbvc-ai-socialgrid-' . $suffix;
$layout_slug = 'dbvc-ai-layout-' . $suffix;
$has_socialgrid = post_type_exists('socialgrid');
$has_layout = post_type_exists('layout');

$cleanup_posts = [];
$cleanup_terms = [];
$previous_auto_create_terms = get_option('dbvc_auto_create_terms', '1');
$previous_allow_new_posts = get_option('dbvc_allow_new_posts', '0');
$previous_post_type_whitelist = get_option('dbvc_new_post_types_whitelist', []);

update_option('dbvc_auto_create_terms', '0');
update_option('dbvc_allow_new_posts', '1');
update_option('dbvc_new_post_types_whitelist', array_values(array_filter([
    'page',
    'post',
    'team-member',
    $has_socialgrid ? 'socialgrid' : '',
    $has_layout ? 'layout' : '',
])));

try {
    $fingerprint_context = \Dbvc\AiPackage\SiteFingerprintService::build_current_context();
    $fingerprint = isset($fingerprint_context['site_fingerprint']) ? (string) $fingerprint_context['site_fingerprint'] : '';

    $root = sys_get_temp_dir() . '/dbvc-ai-import-smoke-' . uniqid('', true);
    wp_mkdir_p($root . '/entities/posts/page');
    wp_mkdir_p($root . '/entities/posts/post');
    wp_mkdir_p($root . '/entities/posts/team-member');
    wp_mkdir_p($root . '/entities/terms/category');
    wp_mkdir_p($root . '/entities/terms/filter-tag');

    if (! post_type_exists('team-member')) {
        throw new RuntimeException('team-member post type is not registered on this site.');
    }

    if (! taxonomy_exists('filter-tag')) {
        throw new RuntimeException('filter-tag taxonomy is not registered on this site.');
    }

    $manifest = [
        'package_type' => 'dbvc_ai_submission_package',
        'package_schema_version' => 1,
        'generated_at' => gmdate('c'),
        'source_sample_package' => [
            'site_fingerprint' => $fingerprint,
            'package_schema_version' => 1,
        ],
        'generator' => [
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'label' => 'repo smoke',
        ],
        'intended_operation' => 'create_or_update',
        'counts' => [
            'post_entities' => 4 + ($has_socialgrid ? 1 : 0) + ($has_layout ? 1 : 0),
            'term_entities' => 3,
        ],
    ];

    $files = [
        'dbvc-ai-manifest.json' => $manifest,
        'entities/posts/page/' . $page_parent_slug . '.json' => [
            'ID' => 0,
            'post_type' => 'page',
            'post_title' => 'AI Parent ' . $suffix,
            'post_name' => $page_parent_slug,
            'post_status' => 'draft',
            'post_content' => 'Parent page smoke payload',
        ],
        'entities/posts/page/' . $page_child_slug . '.json' => [
            'ID' => 0,
            'post_type' => 'page',
            'post_title' => 'AI Child ' . $suffix,
            'post_name' => $page_child_slug,
            'post_status' => 'draft',
            'post_content' => 'Child page smoke payload',
            'post_parent' => [
                'slug' => $page_parent_slug,
                'post_type' => 'page',
            ],
        ],
        'entities/posts/post/' . $post_slug . '.json' => [
            'ID' => 0,
            'post_type' => 'post',
            'post_title' => 'AI Post ' . $suffix,
            'post_name' => $post_slug,
            'post_status' => 'draft',
            'post_content' => 'Post taxonomy smoke payload',
            'meta' => [
                'article_options' => [
                    'content_type' => 'simple',
                    'enable_features' => [
                        'related_section',
                        'cta_section',
                    ],
                    'filter_tag' => [
                        'taxonomy' => 'filter-tag',
                        'slug' => $filter_tag_slug,
                    ],
                ],
                'post_content' => [
                    'article_related_teammember' => [
                        [
                            'slug' => $team_member_slug,
                            'post_type' => 'team-member',
                        ],
                    ],
                ],
            ],
            'tax_input' => [
                'category' => [
                    [
                        'slug' => $term_child_slug,
                        'name' => 'AI Child Category ' . $suffix,
                        'parent' => $term_parent_slug,
                    ],
                ],
            ],
        ],
        'entities/posts/team-member/' . $team_member_slug . '.json' => [
            'ID' => 0,
            'post_type' => 'team-member',
            'post_title' => 'AI Team Member ' . $suffix,
            'post_name' => $team_member_slug,
            'post_status' => 'draft',
            'post_content' => 'Team member relationship smoke payload',
        ],
        'entities/terms/category/' . $term_child_slug . '.json' => [
            'term_id' => 0,
            'taxonomy' => 'category',
            'name' => 'AI Child Category ' . $suffix,
            'slug' => $term_child_slug,
            'parent_slug' => $term_parent_slug,
        ],
        'entities/terms/category/' . $term_parent_slug . '.json' => [
            'term_id' => 0,
            'taxonomy' => 'category',
            'name' => 'AI Parent Category ' . $suffix,
            'slug' => $term_parent_slug,
        ],
        'entities/terms/filter-tag/' . $filter_tag_slug . '.json' => [
            'term_id' => 0,
            'taxonomy' => 'filter-tag',
            'name' => 'AI Filter Tag ' . $suffix,
            'slug' => $filter_tag_slug,
        ],
    ];

    if ($has_socialgrid) {
        $files['entities/posts/socialgrid/' . $socialgrid_slug . '.json'] = [
            'ID' => 0,
            'post_type' => 'socialgrid',
            'post_title' => 'AI SocialGrid ' . $suffix,
            'post_name' => $socialgrid_slug,
            'post_status' => 'draft',
            'post_content' => 'SocialGrid flexible content smoke payload',
            'meta' => [
                'fco_socialgrid' => [
                    'sg_flexible' => [
                        [
                            'acf_fc_layout' => 'sg_stack',
                            'global_links' => ['/register/'],
                        ],
                    ],
                ],
            ],
        ];
    }

    if ($has_layout) {
        $files['entities/posts/layout/' . $layout_slug . '.json'] = [
            'ID' => 0,
            'post_type' => 'layout',
            'post_title' => 'AI Layout ' . $suffix,
            'post_name' => $layout_slug,
            'post_status' => 'draft',
            'post_content' => 'Layout repeater smoke payload',
            'meta' => [
                'repeaters_group' => [
                    'repeater_social' => [
                        [
                            'url' => 'https://example.com/' . $suffix,
                            'profile_name' => 'dbvc-' . $suffix,
                        ],
                    ],
                ],
            ],
        ];
    }

    foreach ($files as $relative_path => $payload) {
        $target = $root . '/' . $relative_path;
        $directory = dirname($target);
        if (! is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        file_put_contents($target, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    $zip_path = $root . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to open smoke zip for writing.');
    }

    foreach (array_keys($files) as $relative_path) {
        $zip->addFile($root . '/' . $relative_path, $relative_path);
    }
    $zip->close();

    $report = \Dbvc\AiPackage\SubmissionPackageValidator::intake_uploaded_zip([
        'tmp_name' => $zip_path,
        'name' => 'dbvc-ai-import-smoke.zip',
    ]);
    if (is_wp_error($report)) {
        throw new RuntimeException($report->get_error_message());
    }

    $import_result = \Dbvc\AiPackage\SubmissionPackageImporter::import_intake(
        (string) $report['intake_id'],
        $report,
        []
    );
    if (is_wp_error($import_result)) {
        throw new RuntimeException($import_result->get_error_message());
    }

    $intake_id = (string) ($report['intake_id'] ?? '');
    $import_report_path = \Dbvc\AiPackage\Storage::resolve_intake_artifact_path($intake_id, 'reports/import-report.json');
    $import_summary_path = \Dbvc\AiPackage\Storage::resolve_intake_artifact_path($intake_id, 'reports/import-summary.md');
    if (is_wp_error($import_report_path) || ! is_string($import_report_path) || ! is_file($import_report_path)) {
        throw new RuntimeException('Retained import-report.json artifact was not written.');
    }
    if (is_wp_error($import_summary_path) || ! is_string($import_summary_path) || ! is_file($import_summary_path)) {
        throw new RuntimeException('Retained import-summary.md artifact was not written.');
    }

    $parent_page = dbvc_ai_runtime_smoke_find_post_by_slug($page_parent_slug, 'page');
    $child_page = dbvc_ai_runtime_smoke_find_post_by_slug($page_child_slug, 'page');
    $smoke_post = dbvc_ai_runtime_smoke_find_post_by_slug($post_slug, 'post');
    $team_member_post = dbvc_ai_runtime_smoke_find_post_by_slug($team_member_slug, 'team-member');
    $socialgrid_post = $has_socialgrid ? dbvc_ai_runtime_smoke_find_post_by_slug($socialgrid_slug, 'socialgrid') : null;
    $layout_post = $has_layout ? dbvc_ai_runtime_smoke_find_post_by_slug($layout_slug, 'layout') : null;
    $parent_term = get_term_by('slug', $term_parent_slug, 'category');
    $child_term = get_term_by('slug', $term_child_slug, 'category');
    $filter_tag_term = get_term_by('slug', $filter_tag_slug, 'filter-tag');

    if (! ($parent_page instanceof WP_Post) || ! ($child_page instanceof WP_Post) || ! ($smoke_post instanceof WP_Post) || ! ($team_member_post instanceof WP_Post)) {
        throw new RuntimeException('Expected imported posts were not found.');
    }

    if ($has_socialgrid && ! ($socialgrid_post instanceof WP_Post)) {
        throw new RuntimeException('Expected imported socialgrid post was not found.');
    }

    if ($has_layout && ! ($layout_post instanceof WP_Post)) {
        throw new RuntimeException('Expected imported layout post was not found.');
    }

    if (
        ! $parent_term || is_wp_error($parent_term)
        || ! $child_term || is_wp_error($child_term)
        || ! $filter_tag_term || is_wp_error($filter_tag_term)
    ) {
        throw new RuntimeException('Expected imported terms were not found.');
    }

    $cleanup_posts = array_values(array_filter([
        (int) $parent_page->ID,
        (int) $child_page->ID,
        (int) $smoke_post->ID,
        (int) $team_member_post->ID,
        $socialgrid_post instanceof WP_Post ? (int) $socialgrid_post->ID : 0,
        $layout_post instanceof WP_Post ? (int) $layout_post->ID : 0,
    ]));
    $cleanup_terms = [
        ['term_id' => (int) $child_term->term_id, 'taxonomy' => 'category'],
        ['term_id' => (int) $parent_term->term_id, 'taxonomy' => 'category'],
        ['term_id' => (int) $filter_tag_term->term_id, 'taxonomy' => 'filter-tag'],
    ];

    $child_parent_id = (int) get_post_field('post_parent', $child_page->ID);
    $term_parent_id = (int) get_term($child_term->term_id, 'category')->parent;
    $post_terms = wp_get_object_terms($smoke_post->ID, 'category', ['fields' => 'ids']);
    $post_terms = is_wp_error($post_terms) ? [] : array_map('intval', $post_terms);
    $filter_tag_terms = wp_get_object_terms($smoke_post->ID, 'filter-tag', ['fields' => 'ids']);
    $filter_tag_terms = is_wp_error($filter_tag_terms) ? [] : array_map('intval', $filter_tag_terms);
    $related_team_member_meta = get_post_meta($smoke_post->ID, 'post_content_article_related_teammember', true);
    $filter_tag_meta = get_post_meta($smoke_post->ID, 'article_options_filter_tag', true);
    $related_team_member_key = (string) get_post_meta($smoke_post->ID, '_post_content_article_related_teammember', true);
    $filter_tag_key = (string) get_post_meta($smoke_post->ID, '_article_options_filter_tag', true);
    $content_type_meta = (string) get_post_meta($smoke_post->ID, 'article_options_content_type', true);
    $enable_features_meta = get_post_meta($smoke_post->ID, 'article_options_enable_features', true);

    if ($child_parent_id !== (int) $parent_page->ID) {
        throw new RuntimeException('Child page parent was not resolved correctly.');
    }

    if ($term_parent_id !== (int) $parent_term->term_id) {
        throw new RuntimeException('Child category parent was not resolved correctly.');
    }

    if (! in_array((int) $child_term->term_id, $post_terms, true)) {
        throw new RuntimeException('Imported post category assignment was not replayed correctly.');
    }

    if ($content_type_meta !== 'simple') {
        throw new RuntimeException('Nested ACF group scalar meta was not translated correctly.');
    }

    $enable_features_values = is_array($enable_features_meta) ? array_values($enable_features_meta) : [];
    if (! in_array('related_section', $enable_features_values, true) || ! in_array('cta_section', $enable_features_values, true)) {
        throw new RuntimeException('Nested ACF group checkbox values were not translated correctly.');
    }

    $related_team_member_ids = is_array($related_team_member_meta)
        ? array_map('intval', $related_team_member_meta)
        : [(int) $related_team_member_meta];
    if (! in_array((int) $team_member_post->ID, $related_team_member_ids, true)) {
        throw new RuntimeException('ACF relationship field did not resolve to the imported team member.');
    }

    if ((int) $filter_tag_meta !== (int) $filter_tag_term->term_id) {
        throw new RuntimeException('ACF taxonomy meta field did not resolve to the imported filter-tag term.');
    }

    if (! in_array((int) $filter_tag_term->term_id, $filter_tag_terms, true)) {
        throw new RuntimeException('ACF taxonomy save_terms assignment was not applied to the imported post.');
    }

    if ($related_team_member_key !== $field_keys['article_related_teammember']) {
        throw new RuntimeException('ACF relationship reference key was not normalized correctly.');
    }

    if ($filter_tag_key !== $field_keys['filter_tag']) {
        throw new RuntimeException('ACF taxonomy reference key was not normalized correctly.');
    }

    $article_options_field = function_exists('get_field') ? get_field('article_options', $smoke_post->ID) : [];
    if (is_array($article_options_field)) {
        if (($article_options_field['content_type'] ?? '') !== 'simple') {
            throw new RuntimeException('ACF group field did not resolve through get_field().');
        }
    }

    $post_content_field = function_exists('get_field') ? get_field('post_content', $smoke_post->ID) : [];
    if (is_array($post_content_field) && ! empty($post_content_field['article_related_teammember'])) {
        $post_content_relationships = array_map(
            static function ($item): int {
                return $item instanceof WP_Post ? (int) $item->ID : (int) $item;
            },
            is_array($post_content_field['article_related_teammember'])
                ? $post_content_field['article_related_teammember']
                : [$post_content_field['article_related_teammember']]
        );

        if (! in_array((int) $team_member_post->ID, $post_content_relationships, true)) {
            throw new RuntimeException('Nested post_content relationship field did not resolve through get_field().');
        }
    }

    $socialgrid_layouts = [];
    if ($socialgrid_post instanceof WP_Post) {
        $socialgrid_field = function_exists('get_field') ? get_field('fco_socialgrid', $socialgrid_post->ID) : [];
        $socialgrid_layouts = is_array($socialgrid_field) && isset($socialgrid_field['sg_flexible']) && is_array($socialgrid_field['sg_flexible'])
            ? $socialgrid_field['sg_flexible']
            : [];

        $socialgrid_row_count = (int) get_post_meta($socialgrid_post->ID, 'fco_socialgrid_sg_flexible', true);
        if ($socialgrid_row_count !== 1) {
            throw new RuntimeException('Flexible content row count was not translated correctly.');
        }

        if (empty($socialgrid_layouts) || (($socialgrid_layouts[0]['acf_fc_layout'] ?? '') !== 'sg_stack')) {
            throw new RuntimeException(
                'Flexible content rows did not resolve through get_field(): '
                . wp_json_encode([
                    'field' => $socialgrid_field,
                    'row_count' => $socialgrid_row_count,
                    'row_meta' => get_post_meta($socialgrid_post->ID, 'fco_socialgrid_sg_flexible_0_acf_fc_layout', true),
                    'global_links_meta' => get_post_meta($socialgrid_post->ID, 'fco_socialgrid_sg_flexible_0_global_links', true),
                ], JSON_UNESCAPED_SLASHES)
            );
        }
    }

    $layout_repeater_rows = [];
    if ($layout_post instanceof WP_Post) {
        $layout_field = function_exists('get_field') ? get_field('repeaters_group', $layout_post->ID) : [];
        $layout_repeater_rows = is_array($layout_field) && isset($layout_field['repeater_social']) && is_array($layout_field['repeater_social'])
            ? $layout_field['repeater_social']
            : [];

        $layout_row_count = (int) get_post_meta($layout_post->ID, 'repeaters_group_repeater_social', true);
        if ($layout_row_count !== 1) {
            throw new RuntimeException('Repeater row count was not translated correctly.');
        }

        if (
            empty($layout_repeater_rows)
            || (($layout_repeater_rows[0]['url'] ?? '') !== 'https://example.com/' . $suffix)
            || (($layout_repeater_rows[0]['profile_name'] ?? '') !== 'dbvc-' . $suffix)
        ) {
            throw new RuntimeException('Repeater rows did not resolve through get_field().');
        }
    }

    echo wp_json_encode([
        'status' => $import_result['status'] ?? '',
        'intake_status' => $report['status'] ?? '',
        'relationship_resolution' => $import_result['relationship_resolution'] ?? [],
        'artifacts' => $import_result['artifacts'] ?? [],
        'parent_page_id' => (int) $parent_page->ID,
        'child_page_parent' => $child_parent_id,
        'parent_term_id' => (int) $parent_term->term_id,
        'child_term_parent' => $term_parent_id,
        'post_term_ids' => $post_terms,
        'filter_tag_term_ids' => $filter_tag_terms,
        'related_team_member_ids' => $related_team_member_ids,
        'socialgrid_layout_count' => count($socialgrid_layouts),
        'layout_repeater_count' => count($layout_repeater_rows),
    ], JSON_UNESCAPED_SLASHES) . "\n";
} finally {
    update_option('dbvc_auto_create_terms', $previous_auto_create_terms);
    update_option('dbvc_allow_new_posts', $previous_allow_new_posts);
    update_option('dbvc_new_post_types_whitelist', $previous_post_type_whitelist);

    foreach ($cleanup_posts as $post_id) {
        if ($post_id > 0) {
            wp_delete_post($post_id, true);
        }
    }

    foreach ($cleanup_terms as $term_entry) {
        if (! is_array($term_entry)) {
            continue;
        }

        $term_id = isset($term_entry['term_id']) ? (int) $term_entry['term_id'] : 0;
        $taxonomy = isset($term_entry['taxonomy']) ? sanitize_key((string) $term_entry['taxonomy']) : '';
        if ($term_id > 0 && $taxonomy !== '') {
            wp_delete_term($term_id, $taxonomy);
        }
    }
}
