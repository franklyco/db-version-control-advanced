<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$wp_load = $root . '/wp-load.php';

if (! file_exists($wp_load)) {
    fwrite(STDERR, "wp-load.php not found at {$wp_load}\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'dbvc-codexchanges.local';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/gallery/';

require_once $wp_load;

if (! class_exists('\Bricks\Frontend') || ! class_exists('\Bricks\Database')) {
    fwrite(STDERR, "Bricks runtime unavailable\n");
    exit(1);
}

if (! class_exists('\Dbvc\VisualEditor\Bricks\DynamicDataInspector')
    || ! class_exists('\Dbvc\VisualEditor\Context\PageContextResolver')
    || ! class_exists('\Dbvc\VisualEditor\Bricks\LoopContextResolver')
    || ! class_exists('\Dbvc\VisualEditor\Resolvers\ResolverRegistry')
    || ! class_exists('\Dbvc\VisualEditor\Resolvers\AcfGalleryResolver')) {
    fwrite(STDERR, "Visual Editor runtime unavailable\n");
    exit(1);
}

wp_set_current_user(1);
$_COOKIE['dbvc_visual_editor_mode'] = '1';

global $wp;

if (! isset($wp) || ! is_object($wp) || ! method_exists($wp, 'main')) {
    fwrite(STDERR, "WP main runtime unavailable\n");
    exit(1);
}

$requested_page_id = isset($argv[1]) ? absint((string) $argv[1]) : 86;
$wp->main('page_id=' . $requested_page_id);

$probe = [
    'classifications' => [],
];

add_filter('bricks/element/render_attributes', static function ($attributes, $key, $element) use (&$probe) {
    $page_context = (new \Dbvc\VisualEditor\Context\PageContextResolver())->resolve();
    $loops = new \Dbvc\VisualEditor\Bricks\LoopContextResolver();
    $inspection = (new \Dbvc\VisualEditor\Bricks\DynamicDataInspector())->inspectForAttribute($element, $key);

    if (empty($inspection['supported']) || ($inspection['render_context'] ?? '') !== 'gallery_collection') {
        return $attributes;
    }

    $classification = (new \Dbvc\VisualEditor\Resolvers\ResolverRegistry(null, $loops))
        ->classifyCandidate($inspection, $page_context);

    $probe['classifications'][] = [
        'elementId' => isset($element->id) ? (string) $element->id : '',
        'attributeKey' => $key,
        'inspection' => $inspection,
        'classification' => $classification,
        'attributesAfter' => $attributes,
    ];

    return $attributes;
}, 30, 3);

$page_id = get_queried_object_id();
$active_template_id = \Bricks\Database::$active_templates['content'] ?? 0;
$template_id = absint($active_template_id ?: $page_id);
$bricks_data = \Bricks\Database::get_data($template_id, 'content');
if (is_array($bricks_data) && ! empty($bricks_data)) {
    ob_start();
    \Bricks\Frontend::render_content($bricks_data);
    ob_end_clean();
}

$result = [
    'pageId' => $page_id,
    'activeTemplateId' => $active_template_id,
    'renderTemplateId' => $template_id,
    'classificationCount' => count($probe['classifications']),
    'saveProbe' => [],
];

$first = $probe['classifications'][0] ?? null;
$manual_descriptor = null;

if (! is_array($first) && function_exists('get_field_object')) {
    $field = get_field_object('gallery_section_gallery', $requested_page_id, false, false);

    if (is_array($field) && ! empty($field)) {
        $manual_descriptor = new \Dbvc\VisualEditor\Registry\EditableDescriptor(
            'probe_gallery_manual',
            'editable',
            'current_entity',
            [
                'type' => 'post',
                'id' => $requested_page_id,
                'subtype' => (string) get_post_type($requested_page_id),
                'acf_object_id' => $requested_page_id,
            ],
            [
                'context' => 'gallery_collection',
                'attribute' => 'src',
            ],
            [
                'type' => 'acf_field',
                'expression' => '{acf_gallery_section_gallery}',
                'expression_args' => [],
                'field_name' => sanitize_key((string) ($field['name'] ?? 'gallery_section_gallery')),
                'field_key' => sanitize_key((string) ($field['key'] ?? '')),
                'field_selector' => sanitize_key((string) ($field['name'] ?? 'gallery_section_gallery')),
                'leaf_field_name' => sanitize_key((string) ($field['name'] ?? 'gallery_section_gallery')),
                'leaf_field_key' => sanitize_key((string) ($field['key'] ?? '')),
                'field_type' => 'gallery',
                'return_format' => sanitize_key((string) ($field['return_format'] ?? '')),
                'media_size' => 'medium_large',
                'reference_post_types' => [],
                'reference_taxonomies' => [],
                'reference_multiple' => true,
                'container_type' => '',
                'parent_field_name' => '',
                'parent_field_key' => '',
                'parent_field_selector' => '',
                'row_index' => null,
                'layout_key' => '',
                'layout_name' => '',
                'group_path' => [],
                'group_key_path' => [],
                'is_nested_group' => false,
                'is_grouped_field' => false,
                'native_query_active' => false,
                'native_query_kind' => '',
                'native_query_selector' => '',
                'native_query_object_type' => '',
                'native_query_field_name' => '',
                'native_query_field_type' => '',
                'parent_native_query_active' => false,
                'parent_native_query_kind' => '',
                'parent_native_query_selector' => '',
                'parent_native_query_object_type' => '',
                'parent_native_query_field_name' => '',
                'parent_native_query_field_type' => '',
            ],
            [
                'label' => sanitize_text_field((string) ($field['label'] ?? 'Gallery')),
                'input' => 'media_gallery_reference',
                'warning' => '',
                'allowMultiple' => true,
                'options' => [],
            ],
            [
                'name' => 'acf_gallery',
                'version' => 1,
            ],
            [],
            [],
            []
        );
    }
}

if (! is_array($first) && ! $manual_descriptor instanceof \Dbvc\VisualEditor\Registry\EditableDescriptor) {
    echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$descriptor = $manual_descriptor instanceof \Dbvc\VisualEditor\Registry\EditableDescriptor
    ? $manual_descriptor
    : new \Dbvc\VisualEditor\Registry\EditableDescriptor(
        'probe_gallery',
        (string) ($first['classification']['status'] ?? 'unsupported'),
        (string) ($first['classification']['scope'] ?? 'current_entity'),
        is_array($first['classification']['entity'] ?? null) ? $first['classification']['entity'] : [],
        [
            'context' => (string) ($first['inspection']['render_context'] ?? 'gallery_collection'),
            'attribute' => (string) ($first['inspection']['render_attribute'] ?? 'src'),
        ],
        is_array($first['classification']['source'] ?? null) ? $first['classification']['source'] : [],
        is_array($first['classification']['ui'] ?? null) ? $first['classification']['ui'] : [],
        is_array($first['classification']['resolver'] ?? null) ? $first['classification']['resolver'] : [],
        [],
        [],
        is_array($first['classification']['loop'] ?? null) ? $first['classification']['loop'] : []
    );

$resolver = new \Dbvc\VisualEditor\Resolvers\AcfGalleryResolver();
$current_value = $resolver->getValue($descriptor);
$submitted_ids = array_values(array_filter(array_map(static function ($item) {
    if (is_array($item) && isset($item['id'])) {
        return absint($item['id']);
    }

    return 0;
}, is_array($current_value) ? $current_value : [])));
$validation = $resolver->validate($descriptor, $submitted_ids);
$sanitized = $resolver->sanitize($descriptor, $submitted_ids);
$save = $resolver->save($descriptor, $sanitized);

$result['saveProbe'] = [
    'elementId' => is_array($first) ? (string) ($first['elementId'] ?? '') : '',
    'status' => is_array($first) ? (string) ($first['classification']['status'] ?? '') : 'editable',
    'scope' => is_array($first) ? (string) ($first['classification']['scope'] ?? '') : 'current_entity',
    'renderContext' => is_array($first) ? (string) ($first['inspection']['render_context'] ?? '') : 'gallery_collection',
    'fieldName' => is_array($first) ? (string) (($first['classification']['source']['field_name'] ?? '')) : 'gallery_section_gallery',
    'usedManualDescriptor' => ! is_array($first),
    'requestedPageId' => $requested_page_id,
    'currentCount' => is_array($current_value) ? count($current_value) : 0,
    'submittedIds' => $submitted_ids,
    'validation' => $validation,
    'sanitized' => $sanitized,
    'save' => [
        'ok' => ! empty($save['ok']),
        'message' => isset($save['message']) ? (string) $save['message'] : '',
        'savedCount' => isset($save['value']) && is_array($save['value']) ? count($save['value']) : 0,
    ],
];

echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
