<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$wp_load = $root . '/wp-load.php';

if (! file_exists($wp_load)) {
    fwrite(STDERR, "wp-load.php not found at {$wp_load}\n");
    exit(1);
}

require_once $wp_load;

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function dbvc_bricks_phase19_parse_args(array $argv): array
{
    $options = [
        'limit' => 10,
        'distribution_id' => '',
        'site_uid' => '',
        'state' => '',
        'include_hidden' => false,
        'output' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }

        $parts = explode('=', substr($arg, 2), 2);
        $key = sanitize_key((string) ($parts[0] ?? ''));
        $value = isset($parts[1]) ? (string) $parts[1] : '1';

        switch ($key) {
            case 'limit':
                $options['limit'] = max(1, min(100, (int) $value));
                break;
            case 'distribution_id':
                $options['distribution_id'] = sanitize_key($value);
                break;
            case 'site_uid':
                $options['site_uid'] = sanitize_key($value);
                break;
            case 'state':
                $options['state'] = sanitize_key($value);
                break;
            case 'include_hidden':
                $options['include_hidden'] = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
                break;
            case 'output':
                $options['output'] = trim($value);
                break;
        }
    }

    return $options;
}

/**
 * @return void
 */
function dbvc_bricks_phase19_prepare_runtime(): void
{
    if (! class_exists('DBVC_Bricks_Addon')) {
        throw new RuntimeException('DBVC_Bricks_Addon is unavailable.');
    }

    DBVC_Bricks_Addon::ensure_defaults();
    DBVC_Bricks_Addon::refresh_runtime_registration();

    if (! DBVC_Bricks_Addon::is_enabled()) {
        throw new RuntimeException('Bricks add-on is disabled on this site.');
    }

    $admins = get_users([
        'role' => 'administrator',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);

    if (empty($admins[0]) || ! $admins[0] instanceof WP_User) {
        throw new RuntimeException('No administrator account is available for REST evidence capture.');
    }

    wp_set_current_user((int) $admins[0]->ID);
    rest_get_server();
}

/**
 * @param mixed $response
 * @return array<string, mixed>
 */
function dbvc_bricks_phase19_normalize_response($response): array
{
    if (is_wp_error($response)) {
        $error_data = $response->get_error_data();
        $status = is_array($error_data) && isset($error_data['status'])
            ? (int) $error_data['status']
            : 500;
        return [
            'ok' => false,
            'status' => $status,
            'code' => (string) $response->get_error_code(),
            'message' => (string) $response->get_error_message(),
            'data' => $error_data,
        ];
    }

    if ($response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response) {
        $status = method_exists($response, 'get_status') ? (int) $response->get_status() : 200;
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => $response->get_data(),
        ];
    }

    return [
        'ok' => false,
        'status' => 500,
        'code' => 'dbvc_bricks_phase19_unexpected_response',
        'message' => 'Unexpected REST response type.',
        'data' => [],
    ];
}

/**
 * @param string $route
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function dbvc_bricks_phase19_probe_get(string $route, array $params = []): array
{
    $request = new WP_REST_Request('GET', $route);
    $previous_get = $_GET;
    if (! empty($params)) {
        $request->set_query_params($params);
        foreach ($params as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $_GET[(string) $key] = (string) $value;
        }
    }

    try {
        $result = dbvc_bricks_phase19_normalize_response(rest_get_server()->dispatch($request));
    } finally {
        $_GET = $previous_get;
    }
    $result['route'] = $route;
    if (! empty($params)) {
        $result['params'] = $params;
    }

    return $result;
}

/**
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>
 */
function dbvc_bricks_phase19_build_summary(array $snapshot): array
{
    $role = (string) ($snapshot['runtime']['role'] ?? '');
    $summary = [
        'role' => $role,
        'site_uid' => (string) ($snapshot['runtime']['site_uid'] ?? ''),
        'connected_site_count' => 0,
        'package_count' => 0,
        'protected_variant_count' => 0,
        'protected_site_count' => 0,
        'command_envelope_count' => 0,
        'diagnostic_count' => 0,
    ];

    $connected = $snapshot['connected_sites']['data']['items'] ?? [];
    if (is_array($connected)) {
        $summary['connected_site_count'] = count($connected);
    }

    $packages = $snapshot['packages']['data']['items'] ?? [];
    if (is_array($packages)) {
        $summary['package_count'] = count($packages);
    }

    if ($role === 'mothership') {
        $fleet = isset($snapshot['protected_variant_fleet']['data']) && is_array($snapshot['protected_variant_fleet']['data'])
            ? $snapshot['protected_variant_fleet']['data']
            : [];
        $fleet_summary = isset($fleet['summary']) && is_array($fleet['summary']) ? $fleet['summary'] : [];
        $summary['protected_variant_count'] = max(0, (int) ($fleet_summary['total_protected_variants'] ?? 0));
        $summary['protected_site_count'] = max(0, (int) ($fleet_summary['protected_site_count'] ?? 0));
        $summary['command_envelope_count'] = max(0, (int) ($snapshot['command_status']['data']['count'] ?? 0));
    } else {
        $variants = $snapshot['protected_variants']['data']['items'] ?? [];
        if (is_array($variants)) {
            $summary['protected_variant_count'] = count($variants);
        }
    }

    $diagnostics = $snapshot['diagnostics']['data']['items'] ?? [];
    if (is_array($diagnostics)) {
        $summary['diagnostic_count'] = count($diagnostics);
    }

    return $summary;
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function dbvc_bricks_phase19_build_snapshot(array $options): array
{
    $role = class_exists('DBVC_Bricks_Addon')
        ? DBVC_Bricks_Addon::get_role_mode()
        : sanitize_key((string) get_option('dbvc_bricks_role', ''));
    $site_uid = sanitize_key((string) get_option('dbvc_bricks_site_uid', ''));
    $limit = max(1, (int) ($options['limit'] ?? 10));

    $snapshot = [
        'generated_at' => gmdate('c'),
        'runtime' => [
            'blog_id' => get_current_blog_id(),
            'site_name' => get_bloginfo('name'),
            'home_url' => home_url('/'),
            'site_url' => site_url('/'),
            'role' => $role,
            'site_uid' => $site_uid,
            'read_only' => get_option('dbvc_bricks_read_only', '0') === '1',
            'enabled' => class_exists('DBVC_Bricks_Addon') ? DBVC_Bricks_Addon::is_enabled() : false,
            'transport_mode' => sanitize_key((string) get_option('dbvc_bricks_command_transport_mode', 'direct_push')),
            'mothership_url' => esc_url_raw((string) get_option('dbvc_bricks_mothership_url', '')),
        ],
        'filters' => [
            'limit' => $limit,
            'distribution_id' => (string) ($options['distribution_id'] ?? ''),
            'site_uid' => (string) ($options['site_uid'] ?? ''),
            'state' => (string) ($options['state'] ?? ''),
            'include_hidden' => ! empty($options['include_hidden']),
        ],
        'status' => dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/status'),
        'diagnostics' => dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/diagnostics', [
            'limit' => $limit,
        ]),
    ];

    if ($role === 'mothership') {
        $command_params = [
            'limit' => $limit,
        ];
        if (! empty($options['distribution_id'])) {
            $command_params['distribution_id'] = (string) $options['distribution_id'];
        }
        if (! empty($options['site_uid'])) {
            $command_params['site_uid'] = (string) $options['site_uid'];
        }
        if (! empty($options['state'])) {
            $command_params['state'] = (string) $options['state'];
        }

        $snapshot['shared_rules_profile'] = dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/configure/shared-rules-profile');
        $snapshot['connected_sites'] = dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/connected-sites', [
            'include_hidden' => ! empty($options['include_hidden']) ? 1 : 0,
        ]);
        $snapshot['packages'] = dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/packages', [
            'limit' => $limit,
        ]);
        $snapshot['protected_variant_fleet'] = dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/protected-variant-fleet');
        $snapshot['command_status'] = dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/commands/status', $command_params);
    } else {
        $package_params = [
            'limit' => $limit,
        ];
        if ($site_uid !== '') {
            $package_params['site_uid'] = $site_uid;
        }

        $snapshot['packages'] = dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/packages', $package_params);
        $snapshot['protected_variants'] = dbvc_bricks_phase19_probe_get('/dbvc/v1/bricks/protected-variants');
    }

    $snapshot['summary'] = dbvc_bricks_phase19_build_summary($snapshot);

    $probe_keys = [
        'status',
        'diagnostics',
        'shared_rules_profile',
        'connected_sites',
        'packages',
        'protected_variant_fleet',
        'command_status',
        'protected_variants',
    ];
    $failures = [];
    foreach ($probe_keys as $key) {
        if (! isset($snapshot[$key]) || ! is_array($snapshot[$key])) {
            continue;
        }
        if (! empty($snapshot[$key]['ok'])) {
            continue;
        }
        $failures[$key] = [
            'status' => (int) ($snapshot[$key]['status'] ?? 500),
            'code' => (string) ($snapshot[$key]['code'] ?? ''),
            'message' => (string) ($snapshot[$key]['message'] ?? ''),
            'route' => (string) ($snapshot[$key]['route'] ?? ''),
        ];
    }

    $snapshot['ok'] = empty($failures);
    $snapshot['failures'] = $failures;

    return $snapshot;
}

$options = dbvc_bricks_phase19_parse_args($argv);

try {
    dbvc_bricks_phase19_prepare_runtime();
    $snapshot = dbvc_bricks_phase19_build_snapshot($options);
} catch (Throwable $e) {
    $snapshot = [
        'ok' => false,
        'generated_at' => gmdate('c'),
        'error' => [
            'code' => 'dbvc_bricks_phase19_bootstrap_failed',
            'message' => $e->getMessage(),
        ],
    ];
}

$json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (! is_string($json)) {
    fwrite(STDERR, "Failed to encode Phase 19 evidence snapshot.\n");
    exit(1);
}

if (! empty($options['output'])) {
    $target = (string) $options['output'];
    $dir = dirname($target);
    if (! is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    if (@file_put_contents($target, $json . PHP_EOL) === false) {
        fwrite(STDERR, "Failed to write output file: {$target}\n");
        exit(1);
    }
}

echo $json . PHP_EOL;

if (! empty($snapshot['ok'])) {
    exit(0);
}

exit(2);
