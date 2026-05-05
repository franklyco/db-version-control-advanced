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
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/our-process/';

require_once $wp_load;

if (! class_exists('\Bricks\Frontend') || ! class_exists('\Bricks\Database')) {
    fwrite(STDERR, "Bricks runtime unavailable\n");
    exit(1);
}

if (! class_exists('\Dbvc\VisualEditor\Bricks\DynamicDataInspector')
    || ! class_exists('\Dbvc\VisualEditor\Context\PageContextResolver')
    || ! class_exists('\Dbvc\VisualEditor\Bricks\LoopContextResolver')
    || ! class_exists('\Dbvc\VisualEditor\Resolvers\ResolverRegistry')) {
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

$wp->main('page_id=24732');

$debug = [
    'attributeFilters' => [],
    'renderElementBefore' => [],
    'renderElementAfter' => [],
];

add_filter('bricks/element/render_attributes', static function ($attributes, $key, $element) use (&$debug) {
    $element_id = isset($element->id) ? (string) $element->id : '';

    if (! in_array($element_id, ['xhcpsg', 'tkwqua'], true)) {
        return $attributes;
    }

    $page_context = (new \Dbvc\VisualEditor\Context\PageContextResolver())->resolve();
    $loops = new \Dbvc\VisualEditor\Bricks\LoopContextResolver();
    $inspection = (new \Dbvc\VisualEditor\Bricks\DynamicDataInspector())->inspectForAttribute($element, $key);
    $classification = [];

    if (! empty($inspection['supported'])) {
        $classification = (new \Dbvc\VisualEditor\Resolvers\ResolverRegistry(null, $loops))
            ->classifyCandidate($inspection, $page_context);
    }

    $debug['attributeFilters'][] = [
        'elementId' => $element_id,
        'attributeKey' => $key,
        'inspection' => $inspection,
        'classification' => $classification,
        'attributesAfter' => $attributes,
    ];

    return $attributes;
}, 30, 3);

add_filter('bricks/frontend/render_element', static function ($element_html, $element) use (&$debug) {
    $element_id = isset($element->id) ? (string) $element->id : '';

    if ($element_id === 'xhcpsg') {
        $debug['renderElementBefore'][] = (string) $element_html;
    }

    return $element_html;
}, 15, 2);

add_filter('bricks/frontend/render_element', static function ($element_html, $element) use (&$debug) {
    $element_id = isset($element->id) ? (string) $element->id : '';

    if ($element_id === 'xhcpsg') {
        $debug['renderElementAfter'][] = (string) $element_html;
    }

    return $element_html;
}, 25, 2);

$page_id = get_queried_object_id();
$active_template_id = \Bricks\Database::$active_templates['content'] ?? 0;
$template_id = absint($active_template_id ?: $page_id);
$bricks_data = \Bricks\Database::get_data($template_id, 'content');

if (! is_array($bricks_data) || empty($bricks_data)) {
    fwrite(STDERR, "No Bricks data for template {$template_id}\n");
    exit(1);
}

ob_start();
\Bricks\Frontend::render_content($bricks_data);
$html = (string) ob_get_clean();

$targets = [
    'xhcpsg',
    'pzmroy',
    'jvispx',
];

$result = [
    'pageId' => $page_id,
    'activeTemplateId' => $active_template_id,
    'renderTemplateId' => $template_id,
    'debug' => $debug,
    'resolvedImageProbe' => [],
    'rawFlexibleRowsProbe' => [],
    'targets' => [],
];

$first_image_probe = null;
foreach ($debug['attributeFilters'] as $entry) {
    if (($entry['elementId'] ?? '') === 'xhcpsg' && ! empty($entry['classification'])) {
        $first_image_probe = $entry;
        break;
    }
}

if (is_array($first_image_probe)) {
    $descriptor = new \Dbvc\VisualEditor\Registry\EditableDescriptor(
        'probe',
        (string) ($first_image_probe['classification']['status'] ?? 'unsupported'),
        (string) ($first_image_probe['classification']['scope'] ?? 'current_entity'),
        is_array($first_image_probe['classification']['entity'] ?? null) ? $first_image_probe['classification']['entity'] : [],
        [
            'context' => (string) ($first_image_probe['inspection']['render_context'] ?? 'image_src'),
            'attribute' => (string) ($first_image_probe['inspection']['render_attribute'] ?? 'src'),
        ],
        is_array($first_image_probe['classification']['source'] ?? null) ? $first_image_probe['classification']['source'] : [],
        is_array($first_image_probe['classification']['ui'] ?? null) ? $first_image_probe['classification']['ui'] : [],
        is_array($first_image_probe['classification']['resolver'] ?? null) ? $first_image_probe['classification']['resolver'] : [],
        [],
        [],
        is_array($first_image_probe['classification']['loop'] ?? null) ? $first_image_probe['classification']['loop'] : []
    );

    $resolver = new \Dbvc\VisualEditor\Resolvers\AcfImageResolver();
    $value = $resolver->getValue($descriptor);
    $display = $resolver->getDisplayCandidates($descriptor, $value);

    $result['resolvedImageProbe'] = [
        'value' => $value,
        'display' => $display,
    ];
}

$raw_rows = function_exists('get_field') ? get_field('core_sections_flexible_layouts', 24732, false) : [];
if (is_array($raw_rows)) {
    foreach (array_slice(array_values($raw_rows), 0, 3) as $index => $row) {
        if (! is_array($row)) {
            continue;
        }

        $result['rawFlexibleRowsProbe'][] = [
            'index' => $index,
            'layout' => $row['acf_fc_layout'] ?? '',
            'keys' => array_keys($row),
            'imageByName' => $row['image'] ?? null,
            'imageByFieldKey' => $row['field_67203bdffcbef'] ?? null,
            'titleByName' => $row['title'] ?? null,
            'titleByFieldKey' => $row['field_67203bdffcbf3'] ?? null,
        ];
    }
}

foreach ($targets as $target) {
    $pattern = '/<[^>]*class="[^"]*brxe-' . preg_quote($target, '/') . '[^"]*"[^>]*>/i';
    preg_match($pattern, $html, $matches);

    $tag = isset($matches[0]) ? (string) $matches[0] : '';

    $result['targets'][$target] = [
        'found' => $tag !== '',
        'tag' => $tag,
        'hasMarker' => strpos($tag, 'data-dbvc-ve=') !== false,
        'context' => preg_match('/data-dbvc-ve-context="([^"]*)"/i', $tag, $context_matches) ? $context_matches[1] : '',
        'scope' => preg_match('/data-dbvc-ve-scope="([^"]*)"/i', $tag, $scope_matches) ? $scope_matches[1] : '',
        'status' => preg_match('/data-dbvc-ve-status="([^"]*)"/i', $tag, $status_matches) ? $status_matches[1] : '',
    ];
}

echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
