<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$wp_load = $root . '/wp-load.php';

if (! file_exists($wp_load)) {
    fwrite(STDERR, "wp-load.php not found at {$wp_load}\n");
    exit(1);
}

require_once $wp_load;

if (! class_exists('\Dbvc\AiPackage\SamplePackageBuilder')) {
    fwrite(STDERR, "SamplePackageBuilder unavailable\n");
    exit(1);
}

$post_types = function_exists('dbvc_get_available_post_types')
    ? array_keys((array) dbvc_get_available_post_types())
    : ['page'];
$post_types = array_values(array_filter(array_map('sanitize_key', $post_types), static function ($value) {
    return $value !== '';
}));

if (empty($post_types)) {
    fwrite(STDERR, "No available post types for compact authoring smoke\n");
    exit(1);
}

$result = \Dbvc\AiPackage\SamplePackageBuilder::build([
    'post_types' => [reset($post_types)],
    'taxonomies' => [],
    'package_profile' => 'compact_ai_chat',
    'shape_mode' => 'conservative',
    'value_style' => 'blank',
    'variant_set' => 'single',
]);

if (is_wp_error($result)) {
    fwrite(STDERR, 'build_error:' . $result->get_error_code() . ':' . $result->get_error_message() . "\n");
    exit(1);
}

$manifest = isset($result['manifest']) && is_array($result['manifest']) ? $result['manifest'] : [];
$workspace = isset($result['workspace_path']) ? (string) $result['workspace_path'] : '';
$sample_post_type = reset($post_types);
$expected_paths = [
    $workspace . '/START_HERE.md',
    $workspace . '/SCHEMA_COMPACT.json',
    $workspace . '/samples/posts/' . $sample_post_type . '.json',
];
$unexpected_paths = [
    $workspace . '/README.md',
    $workspace . '/schema/object-inventory.json',
    $workspace . '/samples/posts/' . $sample_post_type . '/sample.md',
];

foreach ($expected_paths as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "missing_expected_artifact:{$path}\n");
        exit(1);
    }
}

foreach ($unexpected_paths as $path) {
    if (file_exists($path)) {
        fwrite(STDERR, "unexpected_artifact:{$path}\n");
        exit(1);
    }
}

if ((string) (($manifest['generation']['package_profile'] ?? '')) !== 'compact_ai_chat') {
    fwrite(STDERR, "manifest_profile_mismatch\n");
    exit(1);
}

if (! isset($manifest['artifacts']['schema']['schema_compact'])) {
    fwrite(STDERR, "manifest_schema_compact_missing\n");
    exit(1);
}

echo "compact-authoring-smoke-ok\n";
