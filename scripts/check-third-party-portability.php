<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$wp_load = $root . '/wp-load.php';

if (! file_exists($wp_load)) {
    fwrite(STDERR, "wp-load.php not found at {$wp_load}\n");
    exit(1);
}

require_once $wp_load;

if (! class_exists('DBVC_Third_Party_Portability')) {
    fwrite(STDERR, "DBVC_Third_Party_Portability unavailable\n");
    exit(1);
}

if (! function_exists('dbvc_get_sync_path')) {
    fwrite(STDERR, "dbvc_get_sync_path unavailable\n");
    exit(1);
}

$sync_path = trailingslashit(dbvc_get_sync_path());
$base = $sync_path . DBVC_Third_Party_Portability::SYNC_DIR;
$forms = glob(trailingslashit($base) . 'forms/*.json');

if (! is_array($forms) || empty($forms)) {
    fwrite(STDERR, "no_wsform_form_exports_found:{$base}\n");
    exit(1);
}

$invalid_forms = [];
foreach ($forms as $form_path) {
    $payload = json_decode((string) file_get_contents($form_path), true);
    if (! is_array($payload)) {
        $invalid_forms[] = basename($form_path) . ':invalid_json';
        continue;
    }

    if (
        ($payload['identifier'] ?? '') !== DBVC_Third_Party_Portability::PROVIDER_WSFORM
        || empty($payload['dbvc_portability']['uid'])
        || (($payload['dbvc_portability']['object_type'] ?? '') !== 'form')
        || (($payload['meta']['export_object'] ?? '') !== 'form')
    ) {
        $invalid_forms[] = basename($form_path) . ':missing_contract_fields';
    }
}

if (! empty($invalid_forms)) {
    fwrite(STDERR, "invalid_form_exports:" . implode(',', $invalid_forms) . "\n");
    exit(1);
}

$settings_path = trailingslashit($base) . 'settings.json';
if (! is_readable($settings_path)) {
    fwrite(STDERR, "settings_export_missing:{$settings_path}\n");
    exit(1);
}

$settings = json_decode((string) file_get_contents($settings_path), true);
if (
    ! is_array($settings)
    || (($settings['entity_type'] ?? '') !== 'third_party')
    || (($settings['provider'] ?? '') !== DBVC_Third_Party_Portability::PROVIDER_WSFORM)
    || (($settings['object_type'] ?? '') !== 'settings')
) {
    fwrite(STDERR, "settings_contract_invalid\n");
    exit(1);
}

$sensitive_pattern = '/license|api_key|secret|token|password|private_key|client_secret|publishable_key/i';
$sensitive_hits = [];
$walk_settings = static function ($value, string $prefix = '') use (&$walk_settings, &$sensitive_hits, $sensitive_pattern): void {
    if (! is_array($value)) {
        return;
    }

    foreach ($value as $key => $child) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
        if (preg_match($sensitive_pattern, (string) $key)) {
            $sensitive_hits[] = $path;
        }
        if (is_array($child)) {
            $walk_settings($child, $path);
        }
    }
};
$walk_settings($settings['options'] ?? []);

if (! empty($sensitive_hits)) {
    fwrite(STDERR, "sensitive_keys_exported:" . implode(',', $sensitive_hits) . "\n");
    exit(1);
}

$manifest_path = $sync_path . DBVC_Backup_Manager::MANIFEST_FILENAME;
if (! is_readable($manifest_path)) {
    fwrite(STDERR, "manifest_missing:{$manifest_path}\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifest_path), true);
if (! is_array($manifest)) {
    fwrite(STDERR, "manifest_invalid_json\n");
    exit(1);
}

$third_party_entries = array_values(array_filter((array) ($manifest['items'] ?? []), static function ($item): bool {
    return is_array($item) && (($item['item_type'] ?? '') === 'third_party');
}));

$expected_count = count($forms) + 1;
if (count($third_party_entries) < $expected_count) {
    fwrite(STDERR, sprintf(
        "third_party_manifest_count_too_low:expected_at_least_%d_found_%d\n",
        $expected_count,
        count($third_party_entries)
    ));
    exit(1);
}

echo wp_json_encode([
    'status' => 'ok',
    'forms' => count($forms),
    'settings' => 1,
    'third_party_manifest_entries' => count($third_party_entries),
    'settings_excluded_keys' => array_sum(array_map('count', array_filter((array) ($settings['excluded_keys'] ?? []), 'is_array'))),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
