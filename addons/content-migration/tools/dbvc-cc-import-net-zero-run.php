<?php
/**
 * Net-zero verification runner: stage captured artifacts, import them through the
 * shipping commitment path, then roll the import back.
 *
 * This is the WordPress half of the G-7 proof. The shell half
 * (dbvc-cc-import-net-zero-verify.sh) owns the disposable clone, the authoritative
 * before/after capture and the diff; this file only drives the product.
 *
 * It refuses to run unless DBVC_CC_NET_ZERO_CONFIRM=1 is exported, because it
 * writes real posts, meta and attachments into whatever site it is pointed at.
 *
 * Usage (inside a WordPress runtime, e.g. wp eval-file or the shell driver's boot):
 *   DBVC_CC_NET_ZERO_CONFIRM=1 php <boot>.php dbvc-cc-import-net-zero-run.php -- \
 *     --domain=example.com --artifacts=/path/to/dbvc-artifacts --media-base=http://127.0.0.1:8731 \
 *     --report=/path/to/run-report.json
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside a WordPress runtime.\n");
    exit(1);
}

if ((string) getenv('DBVC_CC_NET_ZERO_CONFIRM') !== '1') {
    fwrite(STDERR, "Refusing to run: export DBVC_CC_NET_ZERO_CONFIRM=1 to confirm this site is disposable.\n");
    exit(1);
}

/**
 * @param string $message
 * @return void
 */
function dbvc_cc_nz_log($message)
{
    fwrite(STDERR, '[net-zero] ' . $message . "\n");
}

/**
 * @param string $message
 * @return void
 */
function dbvc_cc_nz_abort($message)
{
    fwrite(STDERR, '[net-zero] ABORT ' . $message . "\n");
    exit(2);
}

/**
 * @param mixed $maybe_error
 * @param string $context
 * @return mixed
 */
function dbvc_cc_nz_unwrap($maybe_error, $context)
{
    if (is_wp_error($maybe_error)) {
        dbvc_cc_nz_abort($context . ': ' . $maybe_error->get_error_code() . ' ' . $maybe_error->get_error_message());
    }

    return $maybe_error;
}

$dbvc_cc_nz_argv = isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : [];
$dbvc_cc_nz_options = [
    'domain' => '',
    'artifacts' => '',
    'media-base' => '',
    'report' => '',
    'paths' => '',
];
foreach ($dbvc_cc_nz_argv as $dbvc_cc_nz_arg) {
    $dbvc_cc_nz_arg = (string) $dbvc_cc_nz_arg;
    if (strpos($dbvc_cc_nz_arg, '--') !== 0) {
        continue;
    }
    $dbvc_cc_nz_pair = explode('=', substr($dbvc_cc_nz_arg, 2), 2);
    $dbvc_cc_nz_key = isset($dbvc_cc_nz_pair[0]) ? strtolower(trim((string) $dbvc_cc_nz_pair[0])) : '';
    if ($dbvc_cc_nz_key === '' || ! array_key_exists($dbvc_cc_nz_key, $dbvc_cc_nz_options)) {
        continue;
    }
    $dbvc_cc_nz_options[$dbvc_cc_nz_key] = isset($dbvc_cc_nz_pair[1]) ? (string) $dbvc_cc_nz_pair[1] : '';
}

$dbvc_cc_nz_domain = sanitize_text_field((string) $dbvc_cc_nz_options['domain']);
$dbvc_cc_nz_artifact_dir = (string) $dbvc_cc_nz_options['artifacts'];
$dbvc_cc_nz_media_base = untrailingslashit((string) $dbvc_cc_nz_options['media-base']);
$dbvc_cc_nz_report_file = (string) $dbvc_cc_nz_options['report'];

if ($dbvc_cc_nz_domain === '' || $dbvc_cc_nz_artifact_dir === '' || ! is_dir($dbvc_cc_nz_artifact_dir)) {
    dbvc_cc_nz_abort('--domain and an existing --artifacts directory are required.');
}
if ($dbvc_cc_nz_media_base === '') {
    dbvc_cc_nz_abort('--media-base is required so captured image URLs resolve to a local fixture server.');
}

foreach (
    [
        'DBVC_CC_Contracts',
        'DBVC_CC_Settings_Service',
        'DBVC_CC_Target_Field_Catalog_Service',
        'DBVC_CC_Section_Field_Candidate_Service',
        'DBVC_CC_Media_Candidate_Service',
        'DBVC_CC_Mapping_Decision_Service',
        'DBVC_CC_Media_Decision_Service',
        'DBVC_CC_Import_Plan_Service',
        'DBVC_CC_Import_Executor_Service',
        'DBVC_CC_Import_Run_Store',
    ] as $dbvc_cc_nz_required_class
) {
    if (! class_exists($dbvc_cc_nz_required_class)) {
        dbvc_cc_nz_abort('Required class is unavailable: ' . $dbvc_cc_nz_required_class);
    }
}

global $wpdb;
dbvc_cc_nz_log('database=' . DB_NAME . ' prefix=' . $wpdb->prefix . ' site=' . get_option('siteurl'));

$dbvc_cc_nz_admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if (! is_array($dbvc_cc_nz_admins) || empty($dbvc_cc_nz_admins)) {
    dbvc_cc_nz_abort('No administrator user exists on this site.');
}
wp_set_current_user((int) $dbvc_cc_nz_admins[0]);

DBVC_CC_Contracts::ensure_phase_zero_defaults();
update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
update_option(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, '1');
update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');

$dbvc_cc_nz_settings = DBVC_CC_Settings_Service::get_options();
$dbvc_cc_nz_settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
// The fixture server is on a loopback address, which the shipped default blocks.
$dbvc_cc_nz_settings['dbvc_cc_media_block_private_hosts'] = '';
update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $dbvc_cc_nz_settings);

/**
 * Restores the fixture server's port on the way out to the network.
 *
 * The media candidate service's normalize_media_url() drops a non-default port
 * (scheme + host + path only), so an http://127.0.0.1:8731/... source becomes
 * http://127.0.0.1/... by the time the executor fetches it. This filter puts the
 * port back and performs the fetch for real over a socket; everything after the
 * download — the temp file, wp_handle_sideload, the attachment row, generated
 * metadata and thumbnails — runs unmodified.
 *
 * It is scoped to the fixture host, so no other request is touched.
 *
 * @param false|array<string, mixed> $preempt
 * @param array<string, mixed>       $parsed_args
 * @param string                     $url
 * @return false|array<string, mixed>|WP_Error
 */
function dbvc_cc_nz_fetch_from_fixture_server($preempt, $parsed_args, $url)
{
    $base = $GLOBALS['dbvc_cc_nz_media_base'];
    $base_host = (string) wp_parse_url($base, PHP_URL_HOST);
    $host = (string) wp_parse_url((string) $url, PHP_URL_HOST);
    if ($host === '' || $host !== $base_host) {
        return $preempt;
    }

    $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    $target = $base . $path;

    $handle = curl_init($target);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $content_type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false || $code === 0) {
        return new WP_Error('http_request_failed', 'Fixture server fetch failed: ' . $error);
    }

    if (! empty($parsed_args['stream']) && ! empty($parsed_args['filename'])) {
        file_put_contents((string) $parsed_args['filename'], $body);
        $body = '';
    }

    return [
        'headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(['content-type' => $content_type]),
        'body' => $body,
        'response' => ['code' => $code, 'message' => get_status_header_desc($code)],
        'cookies' => [],
        'filename' => isset($parsed_args['filename']) ? (string) $parsed_args['filename'] : null,
    ];
}
$GLOBALS['dbvc_cc_nz_media_base'] = $dbvc_cc_nz_media_base;
add_filter('pre_http_request', 'dbvc_cc_nz_fetch_from_fixture_server', 10, 3);

$dbvc_cc_nz_upload_dir = wp_upload_dir();
$dbvc_cc_nz_storage_root = trailingslashit((string) $dbvc_cc_nz_upload_dir['basedir']) . DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
$dbvc_cc_nz_domain_dir = trailingslashit($dbvc_cc_nz_storage_root) . $dbvc_cc_nz_domain;
dbvc_cc_nz_log('storage=' . $dbvc_cc_nz_domain_dir);

/**
 * Reads a captured artifact bundle for one slug.
 *
 * @param string $dir
 * @param string $slug
 * @param string $suffix
 * @return array<string, mixed>|null
 */
function dbvc_cc_nz_read_capture($dir, $slug, $suffix)
{
    $file = trailingslashit($dir) . $slug . '.' . $suffix . '.json';
    if (! file_exists($file)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($file), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Rewrites a captured image URL onto the local fixture server so the download is
 * offline and deterministic. The path is preserved, so filenames stay recognisable.
 *
 * @param string $url
 * @param string $media_base
 * @return string
 */
function dbvc_cc_nz_localise_media_url($url, $media_base)
{
    $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    if ($path === '') {
        return '';
    }

    return $media_base . $path;
}

$dbvc_cc_nz_slugs = [];
foreach (glob(trailingslashit($dbvc_cc_nz_artifact_dir) . '*.page-artifact.v1.json') as $dbvc_cc_nz_file) {
    $dbvc_cc_nz_slugs[] = basename($dbvc_cc_nz_file, '.page-artifact.v1.json');
}
sort($dbvc_cc_nz_slugs);
if (! empty($dbvc_cc_nz_options['paths'])) {
    $dbvc_cc_nz_wanted = array_map('trim', explode(',', (string) $dbvc_cc_nz_options['paths']));
    $dbvc_cc_nz_slugs = array_values(array_intersect($dbvc_cc_nz_slugs, $dbvc_cc_nz_wanted));
}
if (empty($dbvc_cc_nz_slugs)) {
    dbvc_cc_nz_abort('No page-artifact.v1 bundles found in ' . $dbvc_cc_nz_artifact_dir);
}
dbvc_cc_nz_log('pages=' . count($dbvc_cc_nz_slugs) . ' (' . implode(', ', $dbvc_cc_nz_slugs) . ')');

$dbvc_cc_nz_report = [
    'generated_at' => gmdate('c'),
    'database' => DB_NAME,
    'site_url' => (string) get_option('siteurl'),
    'domain' => $dbvc_cc_nz_domain,
    'artifact_dir' => $dbvc_cc_nz_artifact_dir,
    'media_base' => $dbvc_cc_nz_media_base,
    'pages' => [],
    'runs' => [],
    'rollbacks' => [],
    'totals' => [
        'pages_staged' => 0,
        'pages_executed' => 0,
        'entity_writes' => 0,
        'field_writes' => 0,
        'media_writes' => 0,
        'attachments_created' => 0,
        'rollbacks_completed' => 0,
        'rollback_restored_actions' => 0,
        'rollback_failures' => [],
    ],
];

// ---------------------------------------------------------------------------
// Stage: captured artifacts -> the storage layout the commitment path reads.
// ---------------------------------------------------------------------------
foreach ($dbvc_cc_nz_slugs as $dbvc_cc_nz_slug) {
    $dbvc_cc_nz_page = dbvc_cc_nz_read_capture($dbvc_cc_nz_artifact_dir, $dbvc_cc_nz_slug, 'page-artifact.v1');
    $dbvc_cc_nz_elements = dbvc_cc_nz_read_capture($dbvc_cc_nz_artifact_dir, $dbvc_cc_nz_slug, 'elements.v2');
    $dbvc_cc_nz_sections = dbvc_cc_nz_read_capture($dbvc_cc_nz_artifact_dir, $dbvc_cc_nz_slug, 'sections.v2');
    if (! is_array($dbvc_cc_nz_page) || ! is_array($dbvc_cc_nz_elements) || ! is_array($dbvc_cc_nz_sections)) {
        dbvc_cc_nz_abort('Incomplete capture bundle for ' . $dbvc_cc_nz_slug);
    }

    $dbvc_cc_nz_page_dir = trailingslashit($dbvc_cc_nz_domain_dir) . $dbvc_cc_nz_slug;
    wp_mkdir_p($dbvc_cc_nz_page_dir);

    $dbvc_cc_nz_source_url = isset($dbvc_cc_nz_page['source_url']) ? (string) $dbvc_cc_nz_page['source_url'] : '';
    $dbvc_cc_nz_images = [];
    foreach (isset($dbvc_cc_nz_page['images']) && is_array($dbvc_cc_nz_page['images']) ? $dbvc_cc_nz_page['images'] : [] as $dbvc_cc_nz_image) {
        if (! is_array($dbvc_cc_nz_image) || empty($dbvc_cc_nz_image['url'])) {
            continue;
        }
        $dbvc_cc_nz_local = dbvc_cc_nz_localise_media_url((string) $dbvc_cc_nz_image['url'], $dbvc_cc_nz_media_base);
        if ($dbvc_cc_nz_local === '') {
            continue;
        }
        $dbvc_cc_nz_images[] = [
            'url' => $dbvc_cc_nz_local,
            'alt' => isset($dbvc_cc_nz_image['alt']) ? (string) $dbvc_cc_nz_image['alt'] : '',
        ];
    }

    $dbvc_cc_nz_main_sections = [];
    foreach (isset($dbvc_cc_nz_sections['sections']) && is_array($dbvc_cc_nz_sections['sections']) ? $dbvc_cc_nz_sections['sections'] : [] as $dbvc_cc_nz_section) {
        if (! is_array($dbvc_cc_nz_section) || empty($dbvc_cc_nz_section['section_id'])) {
            continue;
        }
        $dbvc_cc_nz_main_sections[] = [
            'id' => (string) $dbvc_cc_nz_section['section_id'],
            'heading' => isset($dbvc_cc_nz_section['section_label_candidate'])
                ? (string) $dbvc_cc_nz_section['section_label_candidate']
                : '',
            'images' => [],
        ];
    }

    $dbvc_cc_nz_main_artifact = [
        'page_name' => isset($dbvc_cc_nz_page['metadata']['title'])
            ? (string) $dbvc_cc_nz_page['metadata']['title']
            : ucfirst(str_replace('-', ' ', $dbvc_cc_nz_slug)),
        'slug' => $dbvc_cc_nz_slug,
        'source_url' => $dbvc_cc_nz_source_url,
        'provenance' => [
            'source_url' => $dbvc_cc_nz_source_url,
            'canonical_source_url' => isset($dbvc_cc_nz_page['normalized_url'])
                ? (string) $dbvc_cc_nz_page['normalized_url']
                : $dbvc_cc_nz_source_url,
            'content_hash' => isset($dbvc_cc_nz_page['content_hash'])
                ? (string) $dbvc_cc_nz_page['content_hash']
                : hash('sha256', $dbvc_cc_nz_slug),
        ],
        'content' => [
            'images' => wp_list_pluck($dbvc_cc_nz_images, 'url'),
            'sections' => $dbvc_cc_nz_main_sections,
        ],
    ];

    // The captured elements/sections artifacts ride through verbatim apart from
    // image URLs, which are pointed at the fixture server.
    $dbvc_cc_nz_staged_elements = $dbvc_cc_nz_elements;
    foreach ($dbvc_cc_nz_staged_elements['elements'] as $dbvc_cc_nz_index => $dbvc_cc_nz_element) {
        if (! is_array($dbvc_cc_nz_element) || ! isset($dbvc_cc_nz_element['attributes']['src'])) {
            continue;
        }
        $dbvc_cc_nz_local = dbvc_cc_nz_localise_media_url((string) $dbvc_cc_nz_element['attributes']['src'], $dbvc_cc_nz_media_base);
        if ($dbvc_cc_nz_local === '') {
            continue;
        }
        $dbvc_cc_nz_staged_elements['elements'][$dbvc_cc_nz_index]['attributes']['src'] = $dbvc_cc_nz_local;
    }

    file_put_contents(
        trailingslashit($dbvc_cc_nz_page_dir) . $dbvc_cc_nz_slug . '.json',
        wp_json_encode($dbvc_cc_nz_main_artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    file_put_contents(
        trailingslashit($dbvc_cc_nz_page_dir) . $dbvc_cc_nz_slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX,
        wp_json_encode($dbvc_cc_nz_staged_elements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    file_put_contents(
        trailingslashit($dbvc_cc_nz_page_dir) . $dbvc_cc_nz_slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX,
        wp_json_encode($dbvc_cc_nz_sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $dbvc_cc_nz_report['pages'][$dbvc_cc_nz_slug] = [
        'source_url' => $dbvc_cc_nz_source_url,
        'sections' => count($dbvc_cc_nz_main_sections),
        'images' => count($dbvc_cc_nz_images),
    ];
    $dbvc_cc_nz_report['totals']['pages_staged']++;
}
dbvc_cc_nz_log('staged ' . $dbvc_cc_nz_report['totals']['pages_staged'] . ' page bundles');

dbvc_cc_nz_unwrap(
    DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($dbvc_cc_nz_domain, true),
    'build_catalog'
);

// ---------------------------------------------------------------------------
// Approve: build candidates, then approve the deterministic top choice per row.
// A reviewer does this in the workbench; here it is done from the artifact so
// the commitment path receives a real approved decision set.
// ---------------------------------------------------------------------------
foreach ($dbvc_cc_nz_slugs as $dbvc_cc_nz_slug) {
    $dbvc_cc_nz_section_candidates = dbvc_cc_nz_unwrap(
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($dbvc_cc_nz_domain, $dbvc_cc_nz_slug, true),
        'section build_candidates ' . $dbvc_cc_nz_slug
    );
    $dbvc_cc_nz_media_candidates = dbvc_cc_nz_unwrap(
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($dbvc_cc_nz_domain, $dbvc_cc_nz_slug, true),
        'media build_candidates ' . $dbvc_cc_nz_slug
    );

    $dbvc_cc_nz_sections = isset($dbvc_cc_nz_section_candidates['section_candidates']['sections'])
        && is_array($dbvc_cc_nz_section_candidates['section_candidates']['sections'])
            ? $dbvc_cc_nz_section_candidates['section_candidates']['sections']
            : [];

    $dbvc_cc_nz_approved = [];
    $dbvc_cc_nz_title_assigned = false;
    foreach ($dbvc_cc_nz_sections as $dbvc_cc_nz_row) {
        if (! is_array($dbvc_cc_nz_row) || empty($dbvc_cc_nz_row['section_id'])) {
            continue;
        }
        $dbvc_cc_nz_section_id = (string) $dbvc_cc_nz_row['section_id'];
        if (! $dbvc_cc_nz_title_assigned) {
            $dbvc_cc_nz_approved[] = [
                'section_id' => $dbvc_cc_nz_section_id,
                'target_ref' => 'core:post_title',
            ];
            $dbvc_cc_nz_title_assigned = true;
            continue;
        }
        $dbvc_cc_nz_choices = isset($dbvc_cc_nz_row['deterministic_candidates']) && is_array($dbvc_cc_nz_row['deterministic_candidates'])
            ? array_values($dbvc_cc_nz_row['deterministic_candidates'])
            : [];
        if (empty($dbvc_cc_nz_choices) || empty($dbvc_cc_nz_choices[0]['target_ref'])) {
            continue;
        }
        $dbvc_cc_nz_approved[] = [
            'section_id' => $dbvc_cc_nz_section_id,
            'target_ref' => (string) $dbvc_cc_nz_choices[0]['target_ref'],
        ];
    }

    dbvc_cc_nz_unwrap(
        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $dbvc_cc_nz_domain,
            $dbvc_cc_nz_slug,
            [
                'decision_status' => 'approved',
                'approved_mappings' => $dbvc_cc_nz_approved,
                'overrides' => [],
                'rejections' => [],
                'unresolved_fields' => [],
                'unresolved_media' => [],
            ],
            get_current_user_id()
        ),
        'save mapping decision ' . $dbvc_cc_nz_slug
    );

    $dbvc_cc_nz_media_rows = isset($dbvc_cc_nz_media_candidates['media_candidates']['media_items'])
        && is_array($dbvc_cc_nz_media_candidates['media_candidates']['media_items'])
            ? array_values($dbvc_cc_nz_media_candidates['media_candidates']['media_items'])
            : [];
    $dbvc_cc_nz_media_approved = [];
    $dbvc_cc_nz_media_ignored = [];
    foreach ($dbvc_cc_nz_media_rows as $dbvc_cc_nz_media_index => $dbvc_cc_nz_media_row) {
        if (! is_array($dbvc_cc_nz_media_row) || empty($dbvc_cc_nz_media_row['media_id'])) {
            continue;
        }
        if ($dbvc_cc_nz_media_index === 0) {
            $dbvc_cc_nz_media_approved[] = [
                'media_id' => (string) $dbvc_cc_nz_media_row['media_id'],
                'target_ref' => 'core:featured_image',
                'source_url' => isset($dbvc_cc_nz_media_row['source_url']) ? (string) $dbvc_cc_nz_media_row['source_url'] : '',
            ];
            continue;
        }
        $dbvc_cc_nz_media_ignored[] = [
            'media_id' => (string) $dbvc_cc_nz_media_row['media_id'],
            'reason' => 'net_zero_run_scope',
        ];
    }

    dbvc_cc_nz_unwrap(
        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $dbvc_cc_nz_domain,
            $dbvc_cc_nz_slug,
            [
                'decision_status' => 'approved',
                'approved' => $dbvc_cc_nz_media_approved,
                'overrides' => [],
                'ignored' => $dbvc_cc_nz_media_ignored,
                'conflicts' => [],
            ],
            get_current_user_id()
        ),
        'save media decision ' . $dbvc_cc_nz_slug
    );

    $dbvc_cc_nz_report['pages'][$dbvc_cc_nz_slug]['approved_mappings'] = count($dbvc_cc_nz_approved);
    $dbvc_cc_nz_report['pages'][$dbvc_cc_nz_slug]['approved_media'] = count($dbvc_cc_nz_media_approved);
}

// ---------------------------------------------------------------------------
// Commit: dry run -> preflight approval -> guarded execute, per page.
// ---------------------------------------------------------------------------
$dbvc_cc_nz_executor = DBVC_CC_Import_Executor_Service::get_instance();
foreach ($dbvc_cc_nz_slugs as $dbvc_cc_nz_slug) {
    $dbvc_cc_nz_dry_run = dbvc_cc_nz_unwrap(
        $dbvc_cc_nz_executor->execute_dry_run($dbvc_cc_nz_domain, $dbvc_cc_nz_slug, true),
        'dry run ' . $dbvc_cc_nz_slug
    );
    if ((string) $dbvc_cc_nz_dry_run['status'] !== 'completed') {
        dbvc_cc_nz_abort(
            'dry run ' . $dbvc_cc_nz_slug . ' status=' . (string) $dbvc_cc_nz_dry_run['status']
            . ' issues=' . wp_json_encode($dbvc_cc_nz_dry_run['issues'])
        );
    }

    $dbvc_cc_nz_approval = dbvc_cc_nz_unwrap(
        $dbvc_cc_nz_executor->approve_preflight($dbvc_cc_nz_domain, $dbvc_cc_nz_slug, true, true),
        'preflight ' . $dbvc_cc_nz_slug
    );
    $dbvc_cc_nz_token = isset($dbvc_cc_nz_approval['approval']['approval_token'])
        ? (string) $dbvc_cc_nz_approval['approval']['approval_token']
        : '';
    if ($dbvc_cc_nz_token === '') {
        dbvc_cc_nz_abort(
            'preflight ' . $dbvc_cc_nz_slug . ' issued no token: ' . wp_json_encode($dbvc_cc_nz_approval['guard_failures'])
        );
    }

    $dbvc_cc_nz_execution = dbvc_cc_nz_unwrap(
        $dbvc_cc_nz_executor->execute_write_skeleton($dbvc_cc_nz_domain, $dbvc_cc_nz_slug, true, true, $dbvc_cc_nz_token),
        'execute ' . $dbvc_cc_nz_slug
    );
    if ((string) $dbvc_cc_nz_execution['status'] !== 'completed') {
        dbvc_cc_nz_abort(
            'execute ' . $dbvc_cc_nz_slug . ' status=' . (string) $dbvc_cc_nz_execution['status']
            . ' guards=' . wp_json_encode($dbvc_cc_nz_execution['guard_failures'])
            . ' failures=' . wp_json_encode($dbvc_cc_nz_execution['execution_failures'])
        );
    }

    $dbvc_cc_nz_counts = isset($dbvc_cc_nz_execution['operation_counts']) && is_array($dbvc_cc_nz_execution['operation_counts'])
        ? $dbvc_cc_nz_execution['operation_counts']
        : [];
    $dbvc_cc_nz_report['runs'][] = [
        'path' => $dbvc_cc_nz_slug,
        'run_id' => isset($dbvc_cc_nz_execution['run_id']) ? (int) $dbvc_cc_nz_execution['run_id'] : 0,
        'run_uuid' => isset($dbvc_cc_nz_execution['run_uuid']) ? (string) $dbvc_cc_nz_execution['run_uuid'] : '',
        'status' => (string) $dbvc_cc_nz_execution['status'],
        'operation_counts' => $dbvc_cc_nz_counts,
    ];
    $dbvc_cc_nz_report['totals']['pages_executed']++;
    $dbvc_cc_nz_report['totals']['entity_writes'] += isset($dbvc_cc_nz_counts['executed_entity_writes']) ? (int) $dbvc_cc_nz_counts['executed_entity_writes'] : 0;
    $dbvc_cc_nz_report['totals']['field_writes'] += isset($dbvc_cc_nz_counts['executed_field_writes']) ? (int) $dbvc_cc_nz_counts['executed_field_writes'] : 0;
    $dbvc_cc_nz_report['totals']['media_writes'] += isset($dbvc_cc_nz_counts['executed_media_writes']) ? (int) $dbvc_cc_nz_counts['executed_media_writes'] : 0;
    $dbvc_cc_nz_report['totals']['attachments_created'] += isset($dbvc_cc_nz_counts['created_media_attachments']) ? (int) $dbvc_cc_nz_counts['created_media_attachments'] : 0;

    dbvc_cc_nz_log(
        'executed ' . $dbvc_cc_nz_slug
        . ' run=' . (string) $dbvc_cc_nz_report['runs'][count($dbvc_cc_nz_report['runs']) - 1]['run_id']
        . ' entity=' . (isset($dbvc_cc_nz_counts['executed_entity_writes']) ? (int) $dbvc_cc_nz_counts['executed_entity_writes'] : 0)
        . ' field=' . (isset($dbvc_cc_nz_counts['executed_field_writes']) ? (int) $dbvc_cc_nz_counts['executed_field_writes'] : 0)
        . ' media=' . (isset($dbvc_cc_nz_counts['executed_media_writes']) ? (int) $dbvc_cc_nz_counts['executed_media_writes'] : 0)
    );
}

// ---------------------------------------------------------------------------
// Roll back, newest run first.
// ---------------------------------------------------------------------------
foreach (array_reverse($dbvc_cc_nz_report['runs']) as $dbvc_cc_nz_run) {
    $dbvc_cc_nz_run_id = isset($dbvc_cc_nz_run['run_id']) ? (int) $dbvc_cc_nz_run['run_id'] : 0;
    if ($dbvc_cc_nz_run_id <= 0) {
        continue;
    }
    $dbvc_cc_nz_rollback = dbvc_cc_nz_unwrap(
        $dbvc_cc_nz_executor->rollback_run($dbvc_cc_nz_run_id),
        'rollback ' . $dbvc_cc_nz_run_id
    );
    $dbvc_cc_nz_report['rollbacks'][] = [
        'path' => isset($dbvc_cc_nz_run['path']) ? (string) $dbvc_cc_nz_run['path'] : '',
        'run_id' => $dbvc_cc_nz_run_id,
        'status' => (string) $dbvc_cc_nz_rollback['status'],
        'restored_count' => (int) $dbvc_cc_nz_rollback['restored_count'],
        'failed_count' => (int) $dbvc_cc_nz_rollback['failed_count'],
        'failures' => $dbvc_cc_nz_rollback['failures'],
    ];
    if ((string) $dbvc_cc_nz_rollback['status'] === 'completed') {
        $dbvc_cc_nz_report['totals']['rollbacks_completed']++;
    }
    $dbvc_cc_nz_report['totals']['rollback_restored_actions'] += (int) $dbvc_cc_nz_rollback['restored_count'];
    if ((int) $dbvc_cc_nz_rollback['failed_count'] > 0) {
        $dbvc_cc_nz_report['totals']['rollback_failures'][] = [
            'run_id' => $dbvc_cc_nz_run_id,
            'failures' => $dbvc_cc_nz_rollback['failures'],
        ];
    }
    dbvc_cc_nz_log(
        'rolled back run=' . $dbvc_cc_nz_run_id
        . ' status=' . (string) $dbvc_cc_nz_rollback['status']
        . ' restored=' . (int) $dbvc_cc_nz_rollback['restored_count']
        . ' failed=' . (int) $dbvc_cc_nz_rollback['failed_count']
    );
}

// The staged capture artifacts are inputs, not site state: remove them so the
// uploads comparison sees only what the import itself created.
if (is_dir($dbvc_cc_nz_domain_dir)) {
    $dbvc_cc_nz_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dbvc_cc_nz_domain_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($dbvc_cc_nz_iterator as $dbvc_cc_nz_entry) {
        if ($dbvc_cc_nz_entry->isDir()) {
            @rmdir($dbvc_cc_nz_entry->getPathname());
            continue;
        }
        @unlink($dbvc_cc_nz_entry->getPathname());
    }
    @rmdir($dbvc_cc_nz_domain_dir);
}

if ($dbvc_cc_nz_report_file !== '') {
    file_put_contents($dbvc_cc_nz_report_file, wp_json_encode($dbvc_cc_nz_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    dbvc_cc_nz_log('report=' . $dbvc_cc_nz_report_file);
}

dbvc_cc_nz_log(
    'done executed=' . $dbvc_cc_nz_report['totals']['pages_executed']
    . ' rolled_back=' . $dbvc_cc_nz_report['totals']['rollbacks_completed']
    . ' rollback_failures=' . count($dbvc_cc_nz_report['totals']['rollback_failures'])
);

exit(empty($dbvc_cc_nz_report['totals']['rollback_failures']) ? 0 : 3);
