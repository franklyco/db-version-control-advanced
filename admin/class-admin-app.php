<?php

if (! defined('WPINC')) {
    die;
}

/**
 * Admin application loader for the diff React UI.
 */
final class DBVC_Admin_App
{
    private const DECISIONS_OPTION = 'dbvc_proposal_decisions';
    private const RESOLVER_DECISIONS_OPTION = 'dbvc_resolver_decisions';
    private const DEFAULT_DIFF_IGNORE_PATHS = 'meta.dbvc_post_history.*';
    private const NEW_ENTITY_DECISION_KEY = DBVC_NEW_ENTITY_DECISION_KEY;
    private const DUPLICATE_BULK_CONFIRM_PHRASE = 'DELETE';
    private const MASK_SUPPRESS_OPTION = 'dbvc_masked_field_suppressions';
    private const MASK_OVERRIDES_OPTION = 'dbvc_mask_overrides';
    private const MASKING_CHUNK_DEFAULT = 10;

    private static $diff_ignore_patterns = null;
    private static $term_field_roots = [
        'name',
        'term_name',
        'slug',
        'term_slug',
        'description',
        'parent',
        'parent_slug',
        'taxonomy',
        'term_taxonomy',
    ];

    /**
     * Bootstrap hooks.
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
    }

    /**
     * Enqueue React bundle for the DBVC admin screen.
     *
     * @param string $hook
     * @return void
     */
    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_dbvc-export') {
            return;
        }

        $asset = self::get_manifest_asset();
        if (! $asset) {
            return;
        }

        if (! empty($asset['css'])) {
            foreach ($asset['css'] as $handle => $url) {
                wp_enqueue_style(
                    $handle,
                    $url,
                    [],
                    $asset['version']
                );
            }
        }

        wp_enqueue_script(
            'dbvc-admin-app',
            $asset['js'],
            ['wp-element', 'wp-i18n', 'wp-components'],
            $asset['version'],
            true
        );

        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('wp-components');
        }

        wp_localize_script(
            'dbvc-admin-app',
            'DBVC_ADMIN_APP',
            [
                'root'     => esc_url_raw(rest_url('dbvc/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'features' => [
                    'resolver' => true,
                ],
            ]
        );
    }

    /**
     * Register REST routes consumed by the admin app (placeholders for now).
     *
     * @return void
     */
    public static function register_rest_routes()
    {
        register_rest_route(
            'dbvc/v1',
            '/proposals',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_proposals'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/upload',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'upload_proposal'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'proposal_id' => [
                        'required' => false,
                    ],
                    'overwrite'   => [
                        'required' => false,
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)',
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [self::class, 'delete_proposal'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'proposal_id' => [
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/fixtures/upload',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'upload_fixture'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'fixture_name' => [
                        'required' => false,
                    ],
                    'overwrite'    => [
                        'required' => false,
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_proposal_entities'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'proposal_id' => [
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/duplicates',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_proposal_duplicates'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/duplicates/cleanup',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'cleanup_proposal_duplicates'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/masking',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_proposal_masking'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/masking/apply',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'apply_proposal_masking'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/masking/revert',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'revert_proposal_masking'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/(?P<vf_object_uid>[^/]+)',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_proposal_entity'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'proposal_id'   => ['required' => true],
                    'vf_object_uid' => ['required' => true],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/(?P<vf_object_uid>[^/]+)/selections',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'update_entity_decision'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/(?P<vf_object_uid>[^/]+)/selections/bulk',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'update_entity_decision_bulk'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/accept',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'accept_entities_bulk'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );
        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/unaccept',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'unaccept_entities_bulk'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );
        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/unkeep',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'unkeep_entities_bulk'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/(?P<vf_object_uid>[^/]+)/snapshot',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'capture_entity_snapshot'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/(?P<vf_object_uid>[^/]+)/hash-sync',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'sync_entity_hash'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/hash-sync',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'sync_entity_hash_bulk'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/snapshot',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'capture_proposal_snapshot'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/apply',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'apply_proposal'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/resolver',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_proposal_resolver'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'proposal_id' => ['required' => true],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/status',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'update_proposal_status'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/resolver/(?P<original_id>\d+)',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'update_resolver_decision'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/resolver/(?P<original_id>\d+)',
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [self::class, 'delete_resolver_decision_endpoint'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/resolver-rules',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'list_resolver_rules'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );
        register_rest_route(
            'dbvc/v1',
            '/resolver-rules/bulk-delete',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'bulk_delete_resolver_rules'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/resolver-rules/(?P<original_id>\d+)',
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [self::class, 'delete_resolver_rule'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/resolver-rules',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'upsert_resolver_rule'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/resolver-rules/import',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'import_resolver_rules'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/maintenance/clear-proposals',
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [self::class, 'clear_all_proposals'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/logs/client',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'log_client_error'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );
    }

    /**
     * REST: list proposals (placeholder).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_proposals(\WP_REST_Request $request)
    {
        $backups = class_exists('DBVC_Backup_Manager') ? DBVC_Backup_Manager::list_backups() : [];
        $items   = [];
        $decision_store = self::get_decision_store();

        foreach ($backups as $backup) {
            $manifest = isset($backup['manifest']) && is_array($backup['manifest']) ? $backup['manifest'] : null;
            if (! $manifest) {
                continue;
            }

            $proposal_id = $backup['name'];
            $resolver_metrics = null;
            if (class_exists('\Dbvc\Media\Resolver')) {
                try {
                    $proposal_path = trailingslashit(DBVC_Backup_Manager::get_base_path()) . $proposal_id;
                    $resolver_result  = \Dbvc\Media\Resolver::resolve_manifest($manifest, [
                        'allow_remote' => false,
                        'dry_run'      => true,
                        'proposal_id'  => $proposal_id,
                        'bundle_meta'  => $manifest['media_bundle'] ?? [],
                        'manifest_dir' => $proposal_path,
                    ]);
                    $resolver_metrics = $resolver_result['metrics'] ?? null;
                } catch (\Throwable $e) {
                    $resolver_metrics = null;
                }
            }

            $proposal_decisions = isset($decision_store[$proposal_id]) && is_array($decision_store[$proposal_id])
                ? $decision_store[$proposal_id]
                : [];
            $decision_summary = self::summarize_proposal_decisions($proposal_decisions);

        $duplicate_summary = self::find_duplicate_manifest_entities($manifest);

        $new_entity_summary = self::summarize_manifest_new_entities($manifest, $proposal_decisions);

        $items[] = [
            'id'             => $proposal_id,
            'title'          => $proposal_id,
            'generated_at'   => $manifest['generated_at'] ?? null,
            'files'          => $manifest['totals']['files'] ?? null,
            'media_items'    => $manifest['totals']['media_items'] ?? null,
            'missing_hashes' => $manifest['totals']['missing_import_hash'] ?? null,
            'locked'         => ! empty($backup['locked']),
            'size'           => isset($backup['size']) ? (int) $backup['size'] : null,
            'resolver'       => [
                'metrics' => $resolver_metrics,
            ],
            'media_bundle'  => $manifest['media_bundle'] ?? null,
            'decisions'      => $decision_summary,
            'status'        => $manifest['status'] ?? 'draft',
            'duplicate_count' => is_array($duplicate_summary) ? count($duplicate_summary) : 0,
            'new_entities'    => $new_entity_summary,
        ];
        }

        return new \WP_REST_Response([
            'items' => $items,
        ]);
    }

    /**
     * REST: upload a proposal bundle (ZIP) and register it as a backup.
     */
    public static function upload_proposal(\WP_REST_Request $request)
    {
        $files = $request->get_file_params();
        if (empty($files['file']) || ! isset($files['file']['tmp_name'])) {
            return new \WP_Error('dbvc_missing_file', __('Upload a ZIP file that contains a DBVC manifest.', 'dbvc'), ['status' => 400]);
        }

        $file = $files['file'];
        if (! empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new \WP_Error('dbvc_upload_error', __('File upload failed.', 'dbvc'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $handled = wp_handle_sideload($file, [
            'test_form' => false,
        ]);

        if (isset($handled['error'])) {
            return new \WP_Error('dbvc_upload_error', $handled['error'], ['status' => 400]);
        }

        $zip_path = $handled['file'];
        $preferred_id = self::sanitize_proposal_id($request->get_param('proposal_id'));
        $overwrite = $request->get_param('overwrite');
        if (function_exists('rest_sanitize_boolean')) {
            $overwrite = rest_sanitize_boolean($overwrite);
        } else {
            $overwrite = in_array($overwrite, [true, 1, '1', 'true', 'on'], true);
        }

        $result = self::import_proposal_from_zip($zip_path, [
            'preferred_id' => $preferred_id,
            'overwrite'    => $overwrite,
        ]);

        @unlink($zip_path);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * REST: delete a closed proposal backup.
     */
    public static function delete_proposal(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Backup_Manager')) {
            return new \WP_Error('dbvc_missing_manager', __('Backup manager unavailable.', 'dbvc'), ['status' => 500]);
        }

        $proposal_id = self::sanitize_proposal_id($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_invalid_proposal', __('Invalid proposal ID.', 'dbvc'), ['status' => 400]);
        }

        $backup_path = trailingslashit(DBVC_Backup_Manager::get_base_path()) . $proposal_id;
        $manifest = DBVC_Backup_Manager::read_manifest($backup_path);
        if (! $manifest) {
            return new \WP_Error('dbvc_backup_missing', __('Backup folder not found.', 'dbvc'), ['status' => 404]);
        }

        $deleted = DBVC_Backup_Manager::delete_backup($proposal_id);
        if (is_wp_error($deleted)) {
            return $deleted;
        }

        if (class_exists('DBVC_Snapshot_Manager')) {
            $snapshot_dir = trailingslashit(DBVC_Snapshot_Manager::get_base_path()) . sanitize_file_name($proposal_id);
            if (is_dir($snapshot_dir)) {
                self::delete_directory_recursive($snapshot_dir);
            }
        }

        $decision_store = self::get_decision_store();
        if (isset($decision_store[$proposal_id])) {
            unset($decision_store[$proposal_id]);
            update_option(self::DECISIONS_OPTION, $decision_store, false);
        }

        $resolver_store = get_option(self::RESOLVER_DECISIONS_OPTION, []);
        if (is_array($resolver_store) && isset($resolver_store[$proposal_id])) {
            unset($resolver_store[$proposal_id]);
            update_option(self::RESOLVER_DECISIONS_OPTION, $resolver_store, false);
        }

        $suppress_store = self::get_mask_suppression_store();
        if (isset($suppress_store[$proposal_id])) {
            unset($suppress_store[$proposal_id]);
            $suppress_store = self::cleanup_mask_store($suppress_store, $proposal_id);
            self::set_mask_suppression_store($suppress_store);
        }

        $override_store = self::get_mask_override_store();
        if (isset($override_store[$proposal_id])) {
            unset($override_store[$proposal_id]);
            $override_store = self::cleanup_mask_store($override_store, $proposal_id);
            self::set_mask_override_store($override_store);
        }

        return new \WP_REST_Response([
            'deleted'     => true,
            'proposal_id' => $proposal_id,
        ]);
    }

    /**
     * REST: copy an uploaded ZIP into docs/fixtures for dev/QA.
     */
    public static function upload_fixture(\WP_REST_Request $request)
    {
        $files = $request->get_file_params();
        if (empty($files['file']) || ! isset($files['file']['tmp_name'])) {
            return new \WP_Error('dbvc_missing_file', __('Upload a ZIP file to store as a dev fixture.', 'dbvc'), ['status' => 400]);
        }

        $file = $files['file'];
        if (! empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new \WP_Error('dbvc_upload_error', __('File upload failed.', 'dbvc'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $handled = wp_handle_sideload($file, [
            'test_form' => false,
        ]);

        if (isset($handled['error'])) {
            return new \WP_Error('dbvc_upload_error', $handled['error'], ['status' => 400]);
        }

        $fixture_dir = self::get_fixture_directory();
        wp_mkdir_p($fixture_dir);
        if (! is_dir($fixture_dir) || ! wp_is_writable($fixture_dir)) {
            @unlink($handled['file']);
            return new \WP_Error('dbvc_fixture_dir', __('Fixture directory is not writable.', 'dbvc'), ['status' => 500]);
        }

        $fixture_name = $request->get_param('fixture_name');
        $fixture_name = sanitize_file_name($fixture_name ?: ($file['name'] ?? 'dev-fixture.zip'));
        if ($fixture_name === '') {
            $fixture_name = 'dev-fixture.zip';
        }
        if (strtolower(pathinfo($fixture_name, PATHINFO_EXTENSION)) !== 'zip') {
            $fixture_name .= '.zip';
        }

        $overwrite = $request->get_param('overwrite');
        if (function_exists('rest_sanitize_boolean')) {
            $overwrite = rest_sanitize_boolean($overwrite);
        } else {
            $overwrite = in_array($overwrite, [true, 1, '1', 'true', 'on'], true);
        }

        $destination = trailingslashit($fixture_dir) . $fixture_name;
        if (file_exists($destination) && ! $overwrite) {
            @unlink($handled['file']);
            return new \WP_Error('dbvc_fixture_exists', __('Fixture already exists. Enable overwrite to replace it.', 'dbvc'), ['status' => 409]);
        }

        if (file_exists($destination)) {
            @unlink($destination);
        }

        $moved = @rename($handled['file'], $destination);
        if (! $moved) {
            $moved = @copy($handled['file'], $destination);
            @unlink($handled['file']);
        }

        if (! $moved) {
            return new \WP_Error('dbvc_fixture_move_failed', __('Unable to store the fixture ZIP.', 'dbvc'), ['status' => 500]);
        }

        return new \WP_REST_Response([
            'fixture' => basename($destination),
            'path'    => str_replace(DBVC_PLUGIN_PATH, '', $destination),
        ]);
    }

    /**
     * Shared importer used by REST + CLI to ingest proposal zips.
     *
     * @param string $zip_path
     * @param array  $args
     * @return array|\WP_Error
     */
    public static function import_proposal_from_zip(string $zip_path, array $args = [])
    {
        if (! class_exists('DBVC_Backup_Manager')) {
            return new \WP_Error('dbvc_missing_manager', __('Backup manager is unavailable.', 'dbvc'), ['status' => 500]);
        }

        if (! class_exists('ZipArchive')) {
            return new \WP_Error('dbvc_zip_missing', __('ZipArchive is required to upload proposals.', 'dbvc'), ['status' => 500]);
        }

        if (! file_exists($zip_path) || ! is_readable($zip_path)) {
            return new \WP_Error('dbvc_missing_file', __('ZIP file could not be read.', 'dbvc'), ['status' => 400]);
        }

        $extension = strtolower(pathinfo($zip_path, PATHINFO_EXTENSION));
        if ($extension !== 'zip') {
            return new \WP_Error('dbvc_invalid_file', __('Only ZIP archives are supported.', 'dbvc'), ['status' => 400]);
        }

        $preferred_id = isset($args['preferred_id']) ? self::sanitize_proposal_id($args['preferred_id']) : '';
        $overwrite    = ! empty($args['overwrite']);

        $temp_dir = wp_tempnam($zip_path);
        if (! $temp_dir) {
            return new \WP_Error('dbvc_tmp_failed', __('Unable to prepare a temporary folder for extraction.', 'dbvc'), ['status' => 500]);
        }

        @unlink($temp_dir);
        wp_mkdir_p($temp_dir);

        $zip = new \ZipArchive();
        $open_result = $zip->open($zip_path);
        if (true !== $open_result) {
            self::delete_directory_recursive($temp_dir);
            return new \WP_Error('dbvc_zip_open_failed', __('Unable to open the uploaded ZIP archive.', 'dbvc'), ['status' => 400]);
        }

        $extracted = $zip->extractTo($temp_dir);
        $zip->close();

        if (! $extracted) {
            self::delete_directory_recursive($temp_dir);
            return new \WP_Error('dbvc_zip_extract_failed', __('Failed to extract the uploaded archive.', 'dbvc'), ['status' => 400]);
        }

        $manifest_path = self::find_manifest_path($temp_dir);
        if (! $manifest_path || ! file_exists($manifest_path)) {
            self::delete_directory_recursive($temp_dir);
            return new \WP_Error('dbvc_manifest_missing', __('The uploaded bundle is missing manifest.json.', 'dbvc'), ['status' => 400]);
        }

        $manifest_raw = file_get_contents($manifest_path);
        $manifest = json_decode($manifest_raw, true);
        if (! is_array($manifest)) {
            self::delete_directory_recursive($temp_dir);
            return new \WP_Error('dbvc_manifest_invalid', __('manifest.json is not valid JSON.', 'dbvc'), ['status' => 400]);
        }

        $duplicates = self::find_duplicate_manifest_entities($manifest);
        if (! empty($duplicates)) {
            self::delete_directory_recursive($temp_dir);
            $messages = array_map(static function ($dup) {
                return sprintf('Post ID %d has multiple payloads (paths: %s)', $dup['post_id'], implode(', ', $dup['paths']));
            }, $duplicates);
            return new \WP_Error('dbvc_manifest_duplicates', implode("\n", $messages), ['status' => 400]);
        }

        if ($preferred_id === '') {
            $preferred_id = self::derive_proposal_id_from_manifest($manifest);
        }
        if ($preferred_id === '') {
            $preferred_id = 'upload-' . gmdate('Ymd-His');
        }

        $proposal_id = self::resolve_proposal_id($preferred_id, $overwrite);
        $target_path = trailingslashit(DBVC_Backup_Manager::get_base_path()) . $proposal_id;

        if (is_dir($target_path)) {
            if (! $overwrite) {
                self::delete_directory_recursive($temp_dir);
                return new \WP_Error('dbvc_exists', __('A proposal with that ID already exists.', 'dbvc'), ['status' => 409]);
            }
            if (class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'delete_folder_contents')) {
                DBVC_Sync_Posts::delete_folder_contents($target_path);
            } else {
                self::delete_directory_recursive($target_path);
                wp_mkdir_p($target_path);
            }
        }

        wp_mkdir_p($target_path);
        $bundle_root = dirname($manifest_path);
        if (class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'recursive_copy')) {
            DBVC_Sync_Posts::recursive_copy($bundle_root, $target_path);
        } else {
            self::copy_directory($bundle_root, $target_path);
        }

        $ingested_bundle_dir = null;
        if (class_exists('\Dbvc\Media\BundleManager')) {
            $ingested_bundle_dir = \Dbvc\Media\BundleManager::ingest_from_backup($proposal_id, $target_path);
        }

        $target_manifest_path = trailingslashit($target_path) . DBVC_Backup_Manager::MANIFEST_FILENAME;
        $manifest_for_site = file_exists($target_manifest_path)
            ? json_decode(file_get_contents($target_manifest_path), true)
            : $manifest;

        if (is_array($manifest_for_site)) {
            $manifest_for_site['backup_name'] = $proposal_id;
            if ($ingested_bundle_dir && isset($manifest_for_site['media_bundle']['storage'])) {
                $manifest_for_site['media_bundle']['storage']['absolute'] = $ingested_bundle_dir;
            }
            file_put_contents(
                $target_manifest_path,
                wp_json_encode($manifest_for_site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            if (class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'import_resolver_decisions_from_manifest')) {
                DBVC_Sync_Posts::import_resolver_decisions_from_manifest($manifest_for_site, $proposal_id);
            }
        }

        if (class_exists('DBVC_Snapshot_Manager')) {
            try {
                DBVC_Snapshot_Manager::capture_for_proposal($proposal_id, $manifest_for_site);
            } catch (\Throwable $e) {
                // Snapshot capture failures shouldn't block upload; optionally log.
            }
        }

        self::delete_directory_recursive($temp_dir);

        return [
            'proposal_id' => $proposal_id,
            'manifest'    => $manifest_for_site,
        ];
    }

    /**
     * REST: list proposal entities (placeholder).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_proposal_entities(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        $status_filter = sanitize_text_field($request->get_param('status') ?? '');

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response([
                'proposal_id' => $proposal_id,
                'items'       => [],
            ], 404);
        }

        $resolver_result = null;
        $resolver_index  = [];
        if (class_exists('\Dbvc\Media\Resolver')) {
            try {
                $resolver_result = \Dbvc\Media\Resolver::resolve_manifest($manifest, [
                    'allow_remote' => false,
                    'dry_run'      => true,
                ]);
                $resolver_index = self::index_resolver_by_original_id($resolver_result);
            } catch (\Throwable $e) {
                $resolver_result = null;
                $resolver_index  = [];
            }
        }

        $decision_store      = self::get_decision_store();
        $proposal_decisions  = isset($decision_store[$proposal_id]) && is_array($decision_store[$proposal_id])
            ? $decision_store[$proposal_id]
            : [];

        $items = [];

        foreach ($manifest['items'] as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if ($item_type !== 'post') {
                if ($item_type === 'term') {
                    $term_entry = self::format_term_manifest_entity($proposal_id, $item, $status_filter, $proposal_decisions);
                    if ($term_entry) {
                        $items[] = $term_entry;
                    }
                }
                continue;
            }

            $media_refs = $item['media_refs'] ?? ['meta' => [], 'content' => []];
            $media_ids  = self::extract_original_ids_from_refs($media_refs);

            $summary = [
                'total'       => count($media_ids),
                'resolved'    => 0,
                'unresolved'  => 0,
                'conflicts'   => 0,
                'unknown'     => 0,
            ];
            $attachments = [];

            foreach ($media_ids as $original_id) {
                $resolution = $resolver_index[$original_id] ?? null;
                $status     = $resolution['status'] ?? 'unknown';
                $reason     = $resolution['reason'] ?? null;
                $decision   = self::get_resolver_decision($proposal_id, (string) $original_id);
                $descriptor = is_array($resolution) ? ($resolution['descriptor'] ?? []) : [];
                $target_id  = isset($resolution['target_id']) ? (int) $resolution['target_id'] : 0;
                $preview    = self::build_attachment_preview($descriptor, $target_id, $proposal_id);

                if ($status === 'reused') {
                    $summary['resolved']++;
                } elseif ($status === 'conflict') {
                    $summary['conflicts']++;
                } elseif ($status === 'needs_download' || $status === 'missing') {
                    $summary['unresolved']++;
                } else {
                    $summary['unknown']++;
                }

                $attachment_row = [
                    'original_id' => $original_id,
                    'status'      => $status,
                    'reason'      => $reason,
                    'target_id'   => $target_id ?: null,
                    'decision'    => $decision,
                ];
                if (! empty($descriptor)) {
                    $attachment_row['descriptor'] = $descriptor;
                }
                if ($preview) {
                    $attachment_row['preview'] = $preview;
                }
                $attachments[] = $attachment_row;
            }

            $vf_object_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');

            $identity    = self::describe_entity_identity($item);
            $is_new_entity = $identity['is_new'];
            $identity_match = $identity['match_source'];

            $diff_counts = self::summarize_entity_diff_counts($proposal_id, $item, $vf_object_uid);
            $diff_state = self::evaluate_entity_diff_state($item, $vf_object_uid, $diff_counts, $identity);
            $diff_needs_review = $diff_state['needs_review'];
            $media_needs_review = ($summary['unresolved'] + $summary['conflicts']) > 0;
            $needs_review = $media_needs_review || $diff_needs_review;

            if ($status_filter === 'needs_review' && ! $needs_review) {
                continue;
            }
            if ($status_filter === 'needs_review_media' && ! $media_needs_review) {
                continue;
            }
            if ($status_filter === 'resolved' && $needs_review) {
                continue;
            }
            if ($status_filter === 'new_entities' && ! $is_new_entity) {
                continue;
            }

            $entity_decisions = ($vf_object_uid !== '' && isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid]))
                ? $proposal_decisions[$vf_object_uid]
                : [];
            $decision_summary = self::summarize_entity_decisions($entity_decisions);
            $new_entity_decision = self::get_new_entity_decision($proposal_id, $vf_object_uid, $entity_decisions);

            $items[] = [
                'vf_object_uid' => $vf_object_uid !== '' ? $vf_object_uid : (string) ($item['post_id'] ?? ''),
                'post_id'       => $item['post_id'],
                'post_type'     => $item['post_type'],
                'post_title'    => $item['post_title'],
                'post_status'   => $item['post_status'],
                'post_name'     => $item['post_name'] ?? null,
                'post_modified' => $item['post_modified'] ?? null,
                'path'          => $item['path'],
                'hash'          => $item['hash'],
                'content_hash'  => $item['content_hash'] ?? null,
                'media_refs'    => $media_refs,
                'diff_state'    => $diff_state,
                'diff_total'    => $diff_counts['total'],
                'meta_diff_count' => $diff_counts['meta'] ?? 0,
                'tax_diff_count'  => $diff_counts['tax'] ?? 0,
                'media_needs_review' => $media_needs_review,
                'overall_status' => $needs_review ? 'needs_review' : 'resolved',
                'resolver'      => [
                    'summary'     => $summary,
                    'attachments' => $attachments,
                    'status'      => $media_needs_review ? 'needs_review' : 'resolved',
                ],
                'entity_type'        => 'post',
                'is_new_entity'      => $is_new_entity,
                'identity_match'     => $identity_match,
                'local_uid'          => $identity['local_uid'] ?? '',
                'uid_mismatch'       => $identity['uid_mismatch'] ?? false,
                'new_entity_decision'=> $new_entity_decision,
                'decision_summary' => $decision_summary,
            ];
        }

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'items'       => $items,
            'resolver'    => [
                'metrics' => $resolver_result['metrics'] ?? [],
            ],
            'decision_summary' => self::summarize_proposal_decisions($proposal_decisions),
            'resolver_decisions' => self::summarize_resolver_decisions($proposal_id),
        ]);
    }

    /**
     * REST: duplicate manifest entries for a proposal.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_proposal_duplicates(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $report = self::build_manifest_duplicate_report($manifest);

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'count'       => count($report),
            'items'       => $report,
        ]);
    }

    public static function cleanup_proposal_duplicates(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        $params = $request->get_json_params();
        $vf_object_uid = isset($params['vf_object_uid']) ? sanitize_text_field($params['vf_object_uid']) : '';
        $keep_path     = isset($params['keep_path']) ? ltrim((string) $params['keep_path'], '/\\') : '';
        $preferred_format = isset($params['preferred_format']) ? sanitize_key($params['preferred_format']) : '';
        $apply_all = ! empty($params['apply_all']);
        $confirmation = isset($params['confirm_token']) ? strtoupper(trim((string) $params['confirm_token'])) : '';
        $allowed_formats = ['id', 'slug', 'slug_id'];

        if ($preferred_format !== '' && ! in_array($preferred_format, $allowed_formats, true)) {
            $preferred_format = '';
        }

        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        if ($apply_all) {
            if ($preferred_format === '') {
                return new \WP_Error('dbvc_missing_format', __('Choose which filename format to keep (ID, slug, or slug-ID).', 'dbvc'), ['status' => 400]);
            }
            if ($confirmation !== self::DUPLICATE_BULK_CONFIRM_PHRASE) {
                return new \WP_Error(
                    'dbvc_missing_confirmation',
                    sprintf(
                        __('Type %s to confirm bulk duplicate cleanup.', 'dbvc'),
                        self::DUPLICATE_BULK_CONFIRM_PHRASE
                    ),
                    ['status' => 400]
                );
            }
        } elseif ($vf_object_uid === '' || $keep_path === '') {
            return new \WP_Error('dbvc_invalid_request', __('Specify the entity UID and canonical file path.', 'dbvc'), ['status' => 400]);
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $items  = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $groups = [];

        foreach ($items as $index => $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                continue;
            }

            $entity_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($entity_uid === '') {
                continue;
            }
            if (! $apply_all && $entity_uid !== $vf_object_uid) {
                continue;
            }

            $path = isset($item['path']) ? ltrim((string) $item['path'], '/\\') : '';
            if ($path === '') {
                continue;
            }

            if (! isset($groups[$entity_uid])) {
                $groups[$entity_uid] = [
                    'vf_object_uid' => $entity_uid,
                    'entries'       => [],
                ];
            }

            $groups[$entity_uid]['entries'][$index] = [
                'index' => $index,
                'path'  => $path,
                'item'  => $item,
            ];
        }

        if ($apply_all) {
            $groups = array_filter($groups, static function ($group) {
                return isset($group['entries']) && count($group['entries']) > 1;
            });
            if (empty($groups)) {
                return new \WP_Error('dbvc_no_duplicates', __('No duplicate entries were found for this proposal.', 'dbvc'), ['status' => 400]);
            }
        } else {
            if (! isset($groups[$vf_object_uid]) || count($groups[$vf_object_uid]['entries']) <= 1) {
                return new \WP_Error('dbvc_no_duplicates', __('No duplicate entries were found for this entity.', 'dbvc'), ['status' => 400]);
            }
            $keep_found = false;
            foreach ($groups[$vf_object_uid]['entries'] as $entry) {
                if ($entry['path'] === $keep_path) {
                    $keep_found = true;
                    break;
                }
            }
            if (! $keep_found) {
                return new \WP_Error('dbvc_keep_missing', __('Canonical file path was not found among duplicates.', 'dbvc'), ['status' => 400]);
            }
        }

        if (! class_exists('DBVC_Backup_Manager')) {
            return new \WP_Error('dbvc_missing_manager', __('Backup manager is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $base_dir = trailingslashit(DBVC_Backup_Manager::get_base_path()) . $proposal_id;
        $base_real = realpath($base_dir);
        if ($base_real === false || ! is_dir($base_real)) {
            return new \WP_Error('dbvc_missing_proposal_dir', __('Proposal directory not found.', 'dbvc'), ['status' => 500]);
        }

        foreach ($groups as $uid => $group) {
            $entries = array_values($group['entries']);
            $canonical_path = $apply_all
                ? self::determine_duplicate_keep_path($entries, $preferred_format)
                : $keep_path;

            if (! $canonical_path) {
                $canonical_path = $entries[0]['path'] ?? null;
            }
            if (! $canonical_path) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry['path'] === $canonical_path) {
                    continue;
                }
                $absolute = self::resolve_manifest_entry_path($base_real, $entry['path']);
                if ($absolute && file_exists($absolute) && strpos($absolute, $base_real) === 0) {
                    @unlink($absolute);
                }
                unset($items[$entry['index']]);
            }
        }

        $manifest['items'] = array_values($items);

        $manifest_path = trailingslashit($base_real) . DBVC_Backup_Manager::MANIFEST_FILENAME;
        file_put_contents(
            $manifest_path,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $updated_report = self::build_manifest_duplicate_report($manifest);

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'items'       => $updated_report,
        ]);
    }

    /**
     * REST: list masking candidates for a proposal.
     */
    public static function get_proposal_masking(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        if (defined('DBVC_MASK_DEBUG') && DBVC_MASK_DEBUG) {
            error_log(sprintf('[DBVC Masking] Loading masking data for proposal %s', $proposal_id));
        }

        $page = max(1, (int) $request->get_param('page') ?: 1);
        $default_chunk = (int) apply_filters('dbvc_masking_chunk_size', self::MASKING_CHUNK_DEFAULT);
        if ($default_chunk <= 0) {
            $default_chunk = self::MASKING_CHUNK_DEFAULT;
        }

        $per_page_param = (int) $request->get_param('per_page');
        $per_page = $per_page_param > 0 ? $per_page_param : $default_chunk;
        $per_page = max(5, min($per_page, 50));

        $fields = self::collect_masking_fields($proposal_id, $manifest, $page, $per_page);
        $total_items = isset($manifest['items']) && is_array($manifest['items']) ? count($manifest['items']) : 0;
        $total_pages = $per_page > 0 ? (int) ceil($total_items / $per_page) : 1;

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'updated_at'  => current_time('mysql'),
            'fields'      => $fields,
            'chunk'       => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => $total_pages,
                'has_more'    => $page < $total_pages,
            ],
        ]);
    }

    /**
     * REST: apply masking directives (ignore/auto-accept/override).
     */
    public static function apply_proposal_masking(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $debug_mask = defined('DBVC_MASK_DEBUG') && DBVC_MASK_DEBUG;
        if ($debug_mask) {
            error_log(sprintf('[DBVC Masking] apply start proposal=%s', $proposal_id));
        }

        if (defined('DBVC_MASK_DEBUG') && DBVC_MASK_DEBUG) {
            error_log(sprintf('[DBVC Masking] Applying directives for proposal %s', $proposal_id));
        }

        $payload = $request->get_json_params();
        $items   = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
        if (empty($items)) {
            return new \WP_Error('dbvc_missing_mask_items', __('Provide at least one masking directive to apply.', 'dbvc'), ['status' => 400]);
        }

        $manifest_index = self::index_manifest_entities($manifest);
        $patterns       = self::get_mask_meta_patterns();
        $post_fields    = isset($patterns['post_fields']) && is_array($patterns['post_fields'])
            ? $patterns['post_fields']
            : [];
        if (empty($patterns['keys']) && empty($patterns['subkeys']) && empty($post_fields)) {
            return new \WP_Error('dbvc_no_mask_patterns', __('No masking patterns are configured for this site.', 'dbvc'), ['status' => 400]);
        }

        $suppress_store = self::get_mask_suppression_store();
        $override_store = self::get_mask_override_store();
        $proposal_suppress = self::normalize_mask_entity_store($suppress_store[$proposal_id] ?? []);
        $proposal_overrides = self::normalize_mask_entity_store($override_store[$proposal_id] ?? []);

        $summary = [
            'ignore'     => 0,
            'auto_accept'=> 0,
            'override'   => 0,
        ];
        $errors = [];
        $affected_entities = [];

        foreach ($items as $idx => $entry) {
            if (! is_array($entry)) {
                $errors[] = sprintf(__('Invalid masking payload at index %d.', 'dbvc'), $idx);
                continue;
            }

            $vf_object_uid = isset($entry['vf_object_uid']) ? sanitize_text_field($entry['vf_object_uid']) : '';
            $meta_path     = isset($entry['meta_path']) ? (string) $entry['meta_path'] : '';
            $action        = isset($entry['action']) ? sanitize_key($entry['action']) : '';
            $path_parts    = self::parse_mask_path($meta_path);
            $meta_key      = $path_parts['bucket_key'];
            $scope         = $path_parts['scope'];
            $field_key     = $path_parts['field'];

            if ($vf_object_uid === '' || $meta_path === '' || $meta_key === '') {
                $errors[] = __('Masking entries must include vf_object_uid, meta_path, and a valid meta key.', 'dbvc');
                continue;
            }
            if (! isset($manifest_index[$vf_object_uid])) {
                $errors[] = sprintf(__('Unknown entity %s for masking.', 'dbvc'), $vf_object_uid);
                continue;
            }
            $matches = ($scope === 'post')
                ? in_array($field_key, $post_fields, true)
                : self::mask_path_matches_patterns($meta_path, $patterns['keys'], $patterns['subkeys']);
            if (! $matches) {
                if ($scope === 'post') {
                    $errors[] = sprintf(__('Post field %s is not enabled for masking.', 'dbvc'), $field_key ?: $meta_path);
                } else {
                    $errors[] = sprintf(__('Meta path %s is not covered by masking patterns.', 'dbvc'), $meta_path);
                }
                continue;
            }

            $decision_path = ($scope === 'post' && $field_key !== '') ? $field_key : $meta_path;

            if (! in_array($action, ['ignore', 'auto_accept', 'override'], true)) {
                $errors[] = sprintf(__('Unsupported masking action "%s".', 'dbvc'), $action);
                continue;
            }

            if ('ignore' === $action) {
                self::set_entity_decision($proposal_id, $vf_object_uid, $decision_path, 'keep');
                $proposal_suppress = self::remove_mask_store_entry($proposal_suppress, $vf_object_uid, $meta_key, $meta_path, $scope);
                $proposal_overrides = self::remove_mask_store_entry($proposal_overrides, $vf_object_uid, $meta_key, $meta_path, $scope);
                $summary['ignore']++;
            } elseif ('auto_accept' === $action) {
                $should_suppress = ! empty($entry['suppress']);
                self::set_entity_decision($proposal_id, $vf_object_uid, $decision_path, 'accept');
                if ($should_suppress) {
                    $proposal_suppress = self::store_mask_suppression($proposal_suppress, $vf_object_uid, $meta_key, $meta_path, $scope);
                } else {
                    $proposal_suppress = self::remove_mask_store_entry($proposal_suppress, $vf_object_uid, $meta_key, $meta_path, $scope);
                }
                $proposal_overrides = self::remove_mask_store_entry($proposal_overrides, $vf_object_uid, $meta_key, $meta_path, $scope);
                $summary['auto_accept']++;
            } else {
                $override_value = isset($entry['override_value']) ? (string) $entry['override_value'] : '';
                if ($override_value === '') {
                    $errors[] = sprintf(__('Override requires a replacement value for %s.', 'dbvc'), $meta_path);
                    continue;
                }
                $note = isset($entry['note']) ? sanitize_textarea_field($entry['note']) : '';
                $proposal_overrides = self::store_mask_override(
                    $proposal_overrides,
                    $vf_object_uid,
                    $meta_key,
                    $meta_path,
                    $override_value,
                    $note,
                    $scope,
                    $field_key
                );
                self::set_entity_decision($proposal_id, $vf_object_uid, $decision_path, 'accept');
                $proposal_suppress = self::remove_mask_store_entry($proposal_suppress, $vf_object_uid, $meta_key, $meta_path, $scope);
                $summary['override']++;
            }

            $affected_entities[$vf_object_uid] = true;
            if ($debug_mask) {
                error_log(sprintf('[DBVC Masking] applied %s action=%s entity=%s', $meta_path, $action, $vf_object_uid));
            }
        }

        $suppress_store[$proposal_id] = $proposal_suppress;
        $override_store[$proposal_id] = $proposal_overrides;

        $suppress_store = self::cleanup_mask_store($suppress_store, $proposal_id);
        $override_store = self::cleanup_mask_store($override_store, $proposal_id);

        self::set_mask_suppression_store($suppress_store);
        self::set_mask_override_store($override_store);

        $updated_entities = self::summarize_masking_entities($proposal_id, array_keys($affected_entities), $manifest);

        if ($debug_mask) {
            error_log(sprintf('[DBVC Masking] apply done proposal=%s fields=%d', $proposal_id, $summary['ignore'] + $summary['auto_accept'] + $summary['override']));
        }

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'applied'     => $summary,
            'entities'    => $updated_entities,
            'errors'      => $errors,
        ]);
    }

    /**
     * REST: revert previously applied masking directives.
     */
    public static function revert_proposal_masking(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $patterns = self::get_mask_meta_patterns();
        $post_fields = isset($patterns['post_fields']) && is_array($patterns['post_fields'])
            ? $patterns['post_fields']
            : [];
        if (empty($patterns['keys']) && empty($patterns['subkeys']) && empty($post_fields)) {
            return new \WP_Error('dbvc_no_mask_patterns', __('No masking patterns are configured for this site.', 'dbvc'), ['status' => 400]);
        }

        $decision_store = self::get_decision_store();
        $proposal_decisions = $decision_store[$proposal_id] ?? [];

        if (empty($proposal_decisions)) {
            return new \WP_REST_Response([
                'proposal_id' => $proposal_id,
                'cleared'     => ['decisions' => 0, 'entities' => 0],
                'entities'    => [],
            ]);
        }

        $affected_entities = [];
        $cleared = 0;

        foreach ($proposal_decisions as $vf_object_uid => $decisions) {
            if (! is_array($decisions)) {
                continue;
            }

            foreach ($decisions as $path => $action) {
                if (! is_string($path) || $path === '' || $path === DBVC_NEW_ENTITY_DECISION_KEY) {
                    continue;
                }

                $parts = self::parse_mask_path($path);
                $scope = $parts['scope'];
                $field = $parts['field'];
                $normalized_path = $scope === 'post' && $field !== ''
                    ? 'post.' . $field
                    : (string) $path;

                if ($scope === 'post') {
                    if ($field === '' || ! in_array($field, $post_fields, true)) {
                        continue;
                    }
                } else {
                    if (! self::mask_path_matches_patterns($normalized_path, $patterns['keys'], $patterns['subkeys'])) {
                        continue;
                    }
                }

                self::clear_entity_decision($proposal_id, (string) $vf_object_uid, $path);
                $affected_entities[(string) $vf_object_uid] = true;
                $cleared++;
            }
        }

        $suppress_store = self::get_mask_suppression_store();
        $override_store = self::get_mask_override_store();

        if (isset($suppress_store[$proposal_id])) {
            unset($suppress_store[$proposal_id]);
        }
        if (isset($override_store[$proposal_id])) {
            unset($override_store[$proposal_id]);
        }

        $suppress_store = self::cleanup_mask_store($suppress_store, $proposal_id);
        $override_store = self::cleanup_mask_store($override_store, $proposal_id);

        self::set_mask_suppression_store($suppress_store);
        self::set_mask_override_store($override_store);

        $entities = [];
        if (! empty($affected_entities)) {
            $entities = self::summarize_masking_entities($proposal_id, array_keys($affected_entities), $manifest);
        }

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'cleared'     => [
                'decisions' => $cleared,
                'entities'  => count($affected_entities),
            ],
            'entities'    => $entities,
        ]);
    }

    private static function evaluate_entity_diff_state(array $item, string $vf_object_uid, ?array $diff_counts = null, ?array $identity = null): array
    {
        $expected_hash = isset($item['content_hash']) ? (string) $item['content_hash'] : '';
        $post_type     = isset($item['post_type']) ? $item['post_type'] : '';
        $original_id   = isset($item['post_id']) ? (int) $item['post_id'] : 0;
        $local_post_id = null;
        $identity_match = 'none';

        if (is_array($identity)) {
            if (isset($identity['local_post_id'])) {
                $local_post_id = $identity['local_post_id'] ? (int) $identity['local_post_id'] : null;
            }
            if (isset($identity['match_source'])) {
                $identity_match = (string) $identity['match_source'];
            }
        }

        if (! $local_post_id) {
            $local_post_id = class_exists('DBVC_Sync_Posts')
                ? DBVC_Sync_Posts::resolve_local_post_id($original_id, $vf_object_uid, $post_type)
                : $original_id;
        }

        $current_hash = $local_post_id ? get_post_meta($local_post_id, '_dbvc_import_hash', true) : '';
        $needs_review = true;
        $reason       = 'missing_expected_hash';

        if ($expected_hash !== '' && $current_hash !== '') {
            $needs_review = ! hash_equals($expected_hash, $current_hash);
            $reason = $needs_review ? 'hash_mismatch' : 'hash_match';
        } elseif ($expected_hash !== '' && $current_hash === '') {
            $needs_review = true;
            $reason = 'missing_local_hash';
        } elseif ($expected_hash === '' && $current_hash !== '') {
            $needs_review = true;
            $reason = 'missing_expected_hash';
        } elseif ($local_post_id === 0) {
            $needs_review = true;
            $reason = 'missing_local_post';
        }

        $diff_total = is_array($diff_counts) ? (int) ($diff_counts['total'] ?? 0) : 0;
        if ($diff_total > 0) {
            if (! $needs_review || $reason === 'hash_match') {
                $needs_review = true;
                $reason = 'snapshot_diff';
            }
        } elseif ($diff_total === 0 && $needs_review && $reason === 'hash_mismatch') {
            $needs_review = false;
            $reason = 'hash_filtered';
        }

        return [
            'needs_review' => $needs_review,
            'reason'       => $reason,
            'expected_hash'=> $expected_hash,
            'current_hash' => $current_hash,
            'local_post_id'=> $local_post_id,
            'diff_total'   => $diff_total,
            'identity_match' => $identity_match ?: ($local_post_id ? 'id' : 'none'),
        ];
    }

    /**
     * REST: single entity diff (placeholder).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_proposal_entity(\WP_REST_Request $request)
    {
        $proposal_id   = sanitize_text_field($request->get_param('proposal_id'));
        $vf_object_uid = sanitize_text_field($request->get_param('vf_object_uid'));

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $entity = null;
        $current_path = null;
        $proposed = null;
        foreach ($manifest['items'] as $item) {
            $entity_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($entity_uid === $vf_object_uid) {
                $item['vf_object_uid'] = $entity_uid;
                $entity = $item;
                $current_path = isset($item['path']) ? $item['path'] : null;
                $proposed = $item;
                break;
            }
        }

        if (! $entity) {
            return new \WP_REST_Response(null, 404);
        }

        $proposed_data = [];
        if ($current_path) {
            $payload = self::read_entity_payload($proposal_id, $current_path);
            if (is_array($payload)) {
                $proposed_data = $payload;
            }
        }

        $current_source = 'bundle';
        $current = [];
        if (class_exists('DBVC_Snapshot_Manager')) {
            $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
            if (is_array($snapshot) && ! empty($snapshot)) {
                $current = $snapshot;
                $current_source = 'snapshot';
            }
        }

        if (empty($current)) {
            $current = $proposed_data;
        }

        $identity = self::describe_entity_identity($entity);

        $diff_summary = self::compare_snapshots($current, $proposed_data);
        $diff_paths = [];
        if (! empty($diff_summary['changes'])) {
            foreach ($diff_summary['changes'] as $change) {
                $path = isset($change['path']) ? (string) $change['path'] : '';
                if ($path !== '') {
                    $diff_paths[] = $path;
                }
            }
        }
        self::prune_entity_decisions_for_paths($proposal_id, $vf_object_uid, $diff_paths);
        $meta_changes = 0;
        $tax_changes  = 0;
        foreach ($diff_summary['changes'] as $change) {
            $section = $change['section'] ?? '';
            if ($section === 'meta') {
                $meta_changes++;
            } elseif ($section === 'tax') {
                $tax_changes++;
            }
        }
        $diff_counts = [
            'total' => isset($diff_summary['total']) ? (int) $diff_summary['total'] : 0,
            'meta'  => $meta_changes,
            'tax'   => $tax_changes,
        ];
        $diff_state   = self::evaluate_entity_diff_state($entity, $vf_object_uid, $diff_counts, $identity);
        $decisions    = self::get_entity_decisions($proposal_id, $vf_object_uid);
        $new_entity_decision = self::get_new_entity_decision($proposal_id, $vf_object_uid, $decisions);

        return new \WP_REST_Response([
            'proposal_id'   => $proposal_id,
            'vf_object_uid' => $vf_object_uid,
            'item'          => $entity,
            'diff'          => $diff_summary,
            'current'       => $current,
            'current_source'=> $current_source,
            'proposed'      => $proposed_data,
            'diff_state'    => $diff_state,
            'decisions'     => $decisions,
            'decision_summary' => self::summarize_entity_decisions($decisions),
            'is_new_entity'     => $identity['is_new'],
            'identity_match'    => $identity['match_source'],
            'new_entity_decision'=> $new_entity_decision,
        ]);
    }

    /**
     * REST: set the import hash for a single entity.
     */
    public static function sync_entity_hash(\WP_REST_Request $request)
    {
        $proposal_id   = sanitize_text_field($request->get_param('proposal_id'));
        $vf_object_uid = sanitize_text_field($request->get_param('vf_object_uid'));

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $result = self::handle_entity_hash_sync($manifest, $proposal_id, $vf_object_uid);
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * REST: bulk hash sync for multiple entities.
     */
    public static function sync_entity_hash_bulk(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        $manifest    = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $body = $request->get_json_params();
        $uids = [];
        if (isset($body['vf_object_uids']) && is_array($body['vf_object_uids'])) {
            $uids = array_filter(array_map('sanitize_text_field', $body['vf_object_uids']));
        }

        if (empty($uids)) {
            return new \WP_Error('dbvc_missing_entities', __('Select at least one entity to update.', 'dbvc'), ['status' => 400]);
        }

        $updated = [];
        $errors  = [];

        foreach ($uids as $vf_object_uid) {
            $result = self::handle_entity_hash_sync($manifest, $proposal_id, $vf_object_uid);
            if (is_wp_error($result)) {
                $errors[] = [
                    'vf_object_uid' => $vf_object_uid,
                    'message'       => $result->get_error_message(),
                ];
                continue;
            }
            $updated[] = $result;
        }

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'updated'     => $updated,
            'errors'      => $errors,
        ]);
    }

    /**
     * REST: resolver summary (placeholder).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_proposal_resolver(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        $manifest   = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        if (class_exists('\Dbvc\Media\Resolver')) {
            $result = \Dbvc\Media\Resolver::resolve_manifest($manifest, [
                'allow_remote' => false,
                'dry_run'      => true,
                'proposal_id'  => $proposal_id,
                'bundle_meta'  => $manifest['media_bundle'] ?? [],
                'manifest_dir' => trailingslashit(DBVC_Backup_Manager::get_base_path()) . $proposal_id,
            ]);

            $attachments = [];
            foreach (($result['attachments'] ?? []) as $attachment) {
                $descriptor = $attachment['descriptor'] ?? [];
                $original   = isset($descriptor['original_id']) ? (int) $descriptor['original_id'] : 0;
                if ($original) {
                    $attachment['decision'] = self::get_resolver_decision($proposal_id, (string) $original);
                }
                $target_id = isset($attachment['target_id']) ? (int) $attachment['target_id'] : 0;
                $preview   = self::build_attachment_preview($descriptor, $target_id, $proposal_id);
                if ($preview) {
                    $attachment['preview'] = $preview;
                }
                $attachments[] = $attachment;
            }

            return new \WP_REST_Response([
                'proposal_id' => $proposal_id,
                'metrics'     => $result['metrics'] ?? [],
                'conflicts'   => $result['conflicts'] ?? [],
                'id_map'      => $result['id_map'] ?? [],
                'attachments' => $attachments,
                'media_bundle'=> $manifest['media_bundle'] ?? [],
            ]);
        }

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'metrics'     => [],
            'conflicts'   => [],
            'id_map'      => [],
            'attachments' => [],
        ]);
    }

    /**
     * Permission callback wrapper.
     *
     * @return bool
     */
    public static function can_manage()
    {
        return current_user_can('manage_options');
    }

    /**
     * Locate built assets (if present).
     *
     * @return array|null
     */
    private static function get_manifest_asset()
    {
        $dir = DBVC_PLUGIN_PATH . 'build/';
        if (! is_dir($dir)) {
            return null;
        }

        $asset_file = $dir . 'admin-app.asset.php';
        if (! file_exists($asset_file)) {
            return null;
        }

        $asset = include $asset_file;

        $css = [];
        if (file_exists($dir . 'style-admin-app.css')) {
            $css['dbvc-admin-app'] = DBVC_PLUGIN_URL . 'build/style-admin-app.css';
        }
        if (file_exists($dir . 'style-admin-app-rtl.css')) {
            $css['dbvc-admin-app-rtl'] = DBVC_PLUGIN_URL . 'build/style-admin-app-rtl.css';
        }

        return [
            'js'      => DBVC_PLUGIN_URL . 'build/admin-app.js',
            'css'     => $css,
            'version' => isset($asset['version']) ? $asset['version'] : DBVC_PLUGIN_VERSION,
        ];
    }

    private static function find_manifest_path($base_dir)
    {
        if (! is_dir($base_dir)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base_dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getFilename()) === 'manifest.json') {
                return $file->getPathname();
            }
        }
        return null;
    }

    private static function sanitize_proposal_id($value)
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        $sanitized = sanitize_title($value);
        $sanitized = preg_replace('/[^a-z0-9\-]/', '-', $sanitized);
        $sanitized = trim(preg_replace('/-+/', '-', $sanitized), '-');

        return $sanitized === '' ? '' : substr($sanitized, 0, 190);
    }

    private static function resolve_proposal_id($preferred, $allow_existing = false)
    {
        $preferred = $preferred !== '' ? $preferred : 'upload-' . gmdate('Ymd-His');
        $base      = trailingslashit(DBVC_Backup_Manager::get_base_path());

        if ($allow_existing) {
            return $preferred;
        }

        $candidate = $preferred;
        $suffix    = 2;
        while (is_dir($base . $candidate)) {
            $candidate = $preferred . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private static function copy_directory($source, $destination)
    {
        if (! is_dir($source)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = trailingslashit($destination) . $iterator->getSubPathName();
            if ($item->isDir()) {
                wp_mkdir_p($target);
            } else {
                wp_mkdir_p(dirname($target));
                @copy($item->getPathname(), $target);
            }
        }
    }

    private static function delete_directory_recursive($path)
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }

    private static function derive_proposal_id_from_manifest(array $manifest): string
    {
        $timestamp = isset($manifest['generated_at']) ? strtotime($manifest['generated_at']) : false;
        if ($timestamp) {
            $derived = gmdate('Ymd-His', $timestamp);
            $derived = self::sanitize_proposal_id($derived);
            if ($derived !== '') {
                return $derived;
            }
        }

        if (! empty($manifest['backup_name'])) {
            $candidate = self::sanitize_proposal_id($manifest['backup_name']);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Helper to map proposal ID (backup folder name) to manifest array.
     *
     * @param string $proposal_id
     * @return array|null
     */
    private static function read_manifest_by_id($proposal_id)
    {
        if (! class_exists('DBVC_Backup_Manager')) {
            return null;
        }

        $base    = DBVC_Backup_Manager::get_base_path();
        $folder  = trailingslashit($base) . $proposal_id;

        if (! is_dir($folder)) {
            return null;
        }

        return DBVC_Backup_Manager::read_manifest($folder);
    }

    private static function read_entity_payload(string $proposal_id, string $relative_path): ?array
    {
        if ($relative_path === '') {
            return null;
        }

        $base = DBVC_Backup_Manager::get_base_path();
        $proposal_dir = trailingslashit($base) . $proposal_id;
        if (! is_dir($proposal_dir)) {
            return null;
        }

        $file_path = trailingslashit($proposal_dir) . ltrim($relative_path, '/');
        if (! file_exists($file_path) || ! is_readable($file_path)) {
            return null;
        }

        $raw = file_get_contents($file_path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function find_duplicate_manifest_entities(array $manifest): array
    {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $seen  = [];
        $duplicates = [];

        foreach ($items as $item) {
            if (($item['item_type'] ?? '') !== 'post') {
                continue;
            }
            $entity_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            $path    = isset($item['path']) ? $item['path'] : '';
            if ($entity_uid === '' || $path === '') {
                continue;
            }
            if (! isset($seen[$entity_uid])) {
                $seen[$entity_uid] = [];
            }
            $seen[$entity_uid][] = $path;
        }

        foreach ($seen as $entity_uid => $paths) {
            if (count($paths) > 1) {
                $duplicates[] = [
                    'post_id' => $entity_uid,
                    'paths'   => $paths,
                ];
            }
        }

        return $duplicates;
    }

    private static function build_manifest_duplicate_report(array $manifest): array
    {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $groups = [];

        foreach ($items as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                continue;
            }
            $entity_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            $path    = isset($item['path']) ? $item['path'] : '';
            if ($entity_uid === '' || $path === '') {
                continue;
            }
            if (! isset($groups[$entity_uid])) {
                $taxonomy = '';
                if ($item_type === 'term') {
                    $taxonomy = isset($item['term_taxonomy']) ? (string) $item['term_taxonomy'] : (isset($item['taxonomy']) ? (string) $item['taxonomy'] : '');
                }

                $groups[$entity_uid] = [
                    'vf_object_uid' => $entity_uid,
                    'entity_type'   => $item_type,
                    'post_id'       => $item['post_id'] ?? null,
                    'post_title'    => $item_type === 'term'
                        ? ($item['term_name'] ?? $item['post_title'] ?? '')
                        : ($item['post_title'] ?? ''),
                    'post_name'     => $item_type === 'term'
                        ? ($item['term_slug'] ?? $item['slug'] ?? $item['post_name'] ?? '')
                        : ($item['post_name'] ?? ''),
                    'post_type'     => $item_type === 'term'
                        ? ('term:' . ($taxonomy !== '' ? $taxonomy : 'term'))
                        : ($item['post_type'] ?? ''),
                    'post_status'   => $item['post_status'] ?? ($item_type === 'term' ? 'term' : ''),
                    'term_id'       => $item['term_id'] ?? null,
                    'term_slug'     => $item['term_slug'] ?? $item['slug'] ?? '',
                    'term_name'     => $item['term_name'] ?? $item['name'] ?? '',
                    'term_taxonomy' => $taxonomy,
                    'taxonomy'      => $taxonomy,
                    'entries'       => [],
                ];
            }
            $groups[$entity_uid]['entries'][] = [
                'path'          => $path,
                'hash'          => $item['hash'] ?? '',
                'content_hash'  => $item['content_hash'] ?? '',
                'post_status'   => $item['post_status'] ?? '',
                'post_modified' => $item['post_modified'] ?? '',
                'size'          => isset($item['size']) ? (int) $item['size'] : null,
                'filename_mode' => self::detect_manifest_entry_filename_mode($item, $path),
                'term_taxonomy' => $item_type === 'term'
                    ? ($item['term_taxonomy'] ?? $item['taxonomy'] ?? '')
                    : null,
            ];
        }

        $report = [];
        foreach ($groups as $group) {
            if (count($group['entries']) > 1) {
                $report[] = $group;
            }
        }

        return $report;
    }

    private static function determine_duplicate_keep_path(array $entries, string $preferred_format): ?string
    {
        if (empty($entries)) {
            return null;
        }

        $allowed = ['slug_id', 'slug', 'id'];
        $preferred = in_array($preferred_format, $allowed, true)
            ? array_merge([$preferred_format], array_diff($allowed, [$preferred_format]))
            : $allowed;

        $paths_by_mode = [];
        foreach ($entries as $entry) {
            if (! isset($entry['item']) || ! isset($entry['path'])) {
                continue;
            }
            $mode = self::detect_manifest_entry_filename_mode($entry['item'], $entry['path']);
            if ($mode && ! isset($paths_by_mode[$mode])) {
                $paths_by_mode[$mode] = $entry['path'];
            }
        }

        foreach ($preferred as $mode) {
            if (isset($paths_by_mode[$mode])) {
                return $paths_by_mode[$mode];
            }
        }

        $latest_path = null;
        $latest_stamp = 0;
        foreach ($entries as $entry) {
            if (! isset($entry['item']['post_modified'])) {
                continue;
            }
            $timestamp = strtotime((string) $entry['item']['post_modified']);
            if ($timestamp && $timestamp > $latest_stamp) {
                $latest_stamp = $timestamp;
                $latest_path  = $entry['path'];
            }
        }

        if ($latest_path) {
            return $latest_path;
        }

        return $entries[0]['path'] ?? null;
    }

    private static function detect_manifest_entry_filename_mode(array $item, string $path): ?string
    {
        $basename = basename($path);
        if ($basename === '') {
            return null;
        }

        $candidates = self::build_manifest_entry_filename_candidates($item);
        foreach ($candidates as $mode => $filename) {
            if ($filename !== '' && strcasecmp($filename, $basename) === 0) {
                return $mode;
            }
        }

        return null;
    }

    private static function build_manifest_entry_filename_candidates(array $item): array
    {
        $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
        $slug = $item_type === 'term'
            ? (string) ($item['term_slug'] ?? $item['slug'] ?? '')
            : (string) ($item['post_name'] ?? '');
        $id = $item_type === 'term'
            ? (int) ($item['term_id'] ?? 0)
            : (int) ($item['post_id'] ?? 0);
        $prefix = $item_type === 'term'
            ? (string) ($item['term_taxonomy'] ?? $item['taxonomy'] ?? 'term')
            : (string) ($item['post_type'] ?? 'post');
        if ($prefix === '') {
            $prefix = $item_type === 'term' ? 'term' : 'post';
        }

        $modes = ['id', 'slug', 'slug_id'];
        $candidates = [];
        foreach ($modes as $mode) {
            $part = self::build_filename_part_for_mode($mode, $slug, $id);
            if ($part === '') {
                continue;
            }
            $candidates[$mode] = sanitize_file_name($prefix . '-' . $part . '.json');
        }

        return $candidates;
    }

    private static function build_filename_part_for_mode(string $mode, string $slug, int $id): string
    {
        $slug_token = sanitize_title($slug);
        $id_token   = (string) ($id ?: 0);

        if ($mode === 'slug_id') {
            if ($slug_token !== '' && ! is_numeric($slug_token)) {
                return $slug_token . '-' . $id_token;
            }
            return $id_token;
        }

        if ($mode === 'slug') {
            if ($slug_token !== '' && ! is_numeric($slug_token)) {
                return $slug_token;
            }
            return $id_token;
        }

        return $id_token;
    }

    private static function resolve_manifest_entry_path(string $base_dir, string $relative_path): ?string
    {
        $relative_path = ltrim($relative_path, '/\\');
        if ($relative_path === '' || strpos($relative_path, '..') !== false) {
            return null;
        }

        $absolute = trailingslashit($base_dir) . $relative_path;
        $real     = realpath($absolute);
        if ($real === false) {
            return $absolute;
        }
        if (strpos($real, $base_dir) !== 0) {
            return null;
        }
        return $real;
    }

    /**
     * Handle selection updates for a diff field.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function update_entity_decision(\WP_REST_Request $request)
    {
        $proposal_id   = sanitize_text_field($request->get_param('proposal_id'));
        $vf_object_uid = sanitize_text_field($request->get_param('vf_object_uid'));
        $params        = $request->get_json_params();

        $path   = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        $action = isset($params['action']) ? sanitize_key($params['action']) : '';

        $skip_action = false;
        if ($path === '' && $action !== 'clear_all') {
            // Gracefully ignore stale requests that arrive with an empty path (often caused by
            // entities whose diff paths were regenerated after snapshot capture). Returning a
            // soft success prevents the React app from crashing while we recompute decisions.
            $skip_action = true;
        }

        if ($action === '') {
            $skip_action = true;
        }

        $allowed_actions = ['accept', 'keep', 'clear', 'clear_all', 'accept_new', 'decline_new'];
        if (! $skip_action && ! in_array($action, $allowed_actions, true)) {
            return new \WP_Error('dbvc_invalid_action', __('Invalid action supplied.', 'dbvc'), ['status' => 400]);
        }

        if (! $skip_action) {
            if ($action === 'clear') {
                self::clear_entity_decision($proposal_id, $vf_object_uid, $path);
            } elseif ($action === 'clear_all') {
                self::clear_all_entity_decisions($proposal_id, $vf_object_uid);
            } else {
                self::set_entity_decision($proposal_id, $vf_object_uid, $path, $action);
            }
        }

        $store             = self::get_decision_store();
        $proposal_store    = isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];
        $decisions         = self::get_entity_decisions($proposal_id, $vf_object_uid);
        $entity_summary    = self::summarize_entity_decisions($decisions);
        $proposal_summary  = self::summarize_proposal_decisions($proposal_store);

        return new \WP_REST_Response([
            'proposal_id'   => $proposal_id,
            'vf_object_uid' => $vf_object_uid,
            'decisions'     => $decisions,
            'summary'       => $entity_summary,
            'proposal_summary' => $proposal_summary,
        ]);
    }

    /**
     * REST: bulk update selections for multiple diff paths.
     */
    public static function update_entity_decision_bulk(\WP_REST_Request $request)
    {
        $proposal_id   = sanitize_text_field($request->get_param('proposal_id'));
        $vf_object_uid = sanitize_text_field($request->get_param('vf_object_uid'));
        $params        = $request->get_json_params();

        $action = isset($params['action']) ? sanitize_key($params['action']) : '';
        $paths  = isset($params['paths']) && is_array($params['paths']) ? $params['paths'] : [];

        if (! in_array($action, ['accept', 'keep', 'clear'], true)) {
            return new \WP_Error('dbvc_invalid_action', __('Invalid action supplied.', 'dbvc'), ['status' => 400]);
        }

        $sanitized_paths = array_values(array_unique(array_filter(array_map('sanitize_text_field', $paths))));
        if (empty($sanitized_paths)) {
            return new \WP_Error('dbvc_missing_paths', __('At least one field path must be provided.', 'dbvc'), ['status' => 400]);
        }

        $store = self::get_decision_store();
        if (! isset($store[$proposal_id])) {
            $store[$proposal_id] = [];
        }

        $entity_store = isset($store[$proposal_id][$vf_object_uid]) && is_array($store[$proposal_id][$vf_object_uid])
            ? $store[$proposal_id][$vf_object_uid]
            : [];

        foreach ($sanitized_paths as $path) {
            if ($action === 'clear') {
                if (isset($entity_store[$path])) {
                    unset($entity_store[$path]);
                }
            } else {
                $entity_store[$path] = $action;
            }
        }

        if (! empty($entity_store)) {
            $store[$proposal_id][$vf_object_uid] = $entity_store;
        } else {
            unset($store[$proposal_id][$vf_object_uid]);
        }

        if (! empty($store[$proposal_id])) {
            $store[$proposal_id] = self::recalculate_proposal_summary($store[$proposal_id]);
        }

        $store = self::cleanup_empty_proposals($store, $proposal_id);
        self::set_decision_store($store);

        $proposal_store = isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];
        $decisions      = isset($store[$proposal_id][$vf_object_uid]) && is_array($store[$proposal_id][$vf_object_uid])
            ? $store[$proposal_id][$vf_object_uid]
            : [];
        $entity_summary   = self::summarize_entity_decisions($decisions);
        $proposal_summary = self::summarize_proposal_decisions($proposal_store);

        return new \WP_REST_Response([
            'proposal_id'      => $proposal_id,
            'vf_object_uid'    => $vf_object_uid,
            'decisions'        => $decisions,
            'summary'          => $entity_summary,
            'proposal_summary' => $proposal_summary,
        ]);
    }

    /**
     * REST: Accept multiple entities (new or existing) in bulk.
     */
    public static function accept_entities_bulk(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $body  = $request->get_json_params();
        $scope = isset($body['scope']) ? sanitize_key($body['scope']) : 'selected';
        $requested_ids = [];
        if (! empty($body['vf_object_uids']) && is_array($body['vf_object_uids'])) {
            $requested_ids = array_filter(array_map('sanitize_text_field', $body['vf_object_uids']));
        }

        $manifest_map = [];
        foreach ($manifest['items'] as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                continue;
            }
            $vf_object_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($vf_object_uid !== '') {
                $manifest_map[$vf_object_uid] = $item;
            }
        }

        $target_ids = [];
        if ($scope === 'new_only') {
            foreach ($manifest_map as $uid => $item) {
                $identity = self::describe_entity_identity($item);
                if ($identity['is_new']) {
                    $target_ids[] = $uid;
                }
            }
        } else {
            $target_ids = $requested_ids;
        }

        if (empty($target_ids)) {
            return new \WP_Error('dbvc_no_entities', __('No entities were selected for acceptance.', 'dbvc'), ['status' => 400]);
        }

        $accepted_new  = 0;
        $accepted_diff = 0;

        foreach ($target_ids as $vf_object_uid) {
            $item = $manifest_map[$vf_object_uid] ?? null;
            if (! $item) {
                continue;
            }

            $identity = self::describe_entity_identity($item);
            if ($identity['is_new']) {
                self::set_entity_decision($proposal_id, $vf_object_uid, DBVC_NEW_ENTITY_DECISION_KEY, 'accept_new');
                $accepted_new++;
                continue;
            }

            if ($scope === 'new_only') {
                continue;
            }

            $paths = self::resolve_entity_diff_paths($proposal_id, $vf_object_uid, $item);
            if (empty($paths)) {
                continue;
            }
            foreach ($paths as $path) {
                self::set_entity_decision($proposal_id, $vf_object_uid, $path, 'accept');
            }
            $accepted_diff++;
        }

        $store            = self::get_decision_store();
        $proposal_store   = isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];
        $proposal_summary = self::summarize_proposal_decisions($proposal_store);

        return new \WP_REST_Response([
            'proposal_id'      => $proposal_id,
            'accepted_new'     => $accepted_new,
            'accepted_existing'=> $accepted_diff,
            'proposal_summary' => $proposal_summary,
        ]);
    }

    /**
     * REST: Clear Accept/Keep decisions (and new entity approvals) in bulk.
     */
    public static function unaccept_entities_bulk(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        $body = $request->get_json_params();
        $scope = isset($body['scope']) ? sanitize_key($body['scope']) : 'selected';
        $requested_ids = [];
        if (! empty($body['vf_object_uids']) && is_array($body['vf_object_uids'])) {
            $requested_ids = array_filter(array_map('sanitize_text_field', $body['vf_object_uids']));
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $manifest_map = [];
        foreach ($manifest['items'] as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                continue;
            }
            $vf_object_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($vf_object_uid !== '') {
                $manifest_map[$vf_object_uid] = $item;
            }
        }

        $target_ids = [];
        if ($scope === 'all') {
            $target_ids = array_keys($manifest_map);
        } else {
            $target_ids = $requested_ids;
        }

        if (empty($target_ids)) {
            return new \WP_Error('dbvc_no_entities', __('No entities were selected.', 'dbvc'), ['status' => 400]);
        }

        $cleared_new  = 0;
        $cleared_diff = 0;

        foreach ($target_ids as $vf_object_uid) {
            $item = $manifest_map[$vf_object_uid] ?? null;
            if (! $item) {
                continue;
            }
            $identity = self::describe_entity_identity($item);
            if ($identity['is_new']) {
                self::clear_entity_decision($proposal_id, $vf_object_uid, DBVC_NEW_ENTITY_DECISION_KEY);
                $cleared_new++;
            }

            if (! $identity['is_new']) {
                $paths = self::resolve_entity_diff_paths($proposal_id, $vf_object_uid, $item);
                if (! empty($paths)) {
                    foreach ($paths as $path) {
                        self::clear_entity_decision($proposal_id, $vf_object_uid, $path);
                    }
                    $cleared_diff++;
                }
            }
        }

        $store            = self::get_decision_store();
        $proposal_store   = isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];
        $proposal_summary = self::summarize_proposal_decisions($proposal_store);

        return new \WP_REST_Response([
            'proposal_id'      => $proposal_id,
            'cleared_new'      => $cleared_new,
            'cleared_existing' => $cleared_diff,
            'proposal_summary' => $proposal_summary,
        ]);
    }

    /**
     * REST: Clear only Keep decisions for multiple entities.
     */
    public static function unkeep_entities_bulk(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        $body = $request->get_json_params();
        $scope = isset($body['scope']) ? sanitize_key($body['scope']) : 'selected';
        $requested_ids = [];
        if (! empty($body['vf_object_uids']) && is_array($body['vf_object_uids'])) {
            $requested_ids = array_filter(array_map('sanitize_text_field', $body['vf_object_uids']));
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $manifest_map = [];
        foreach ($manifest['items'] as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                continue;
            }
            $vf_object_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($vf_object_uid !== '') {
                $manifest_map[$vf_object_uid] = $item;
            }
        }

        $target_ids = [];
        if ($scope === 'all') {
            $target_ids = array_keys($manifest_map);
        } else {
            $target_ids = $requested_ids;
        }

        if (empty($target_ids)) {
            return new \WP_Error('dbvc_no_entities', __('No entities were selected.', 'dbvc'), ['status' => 400]);
        }

        $cleared_keep = 0;
        $entities_updated = 0;

        foreach ($target_ids as $vf_object_uid) {
            if (! isset($manifest_map[$vf_object_uid])) {
                continue;
            }

            $decisions = self::get_entity_decisions($proposal_id, $vf_object_uid);
            if (empty($decisions)) {
                continue;
            }

            $cleared_for_entity = 0;
            foreach ($decisions as $path => $action) {
                if ($action !== 'keep') {
                    continue;
                }
                self::clear_entity_decision($proposal_id, $vf_object_uid, $path);
                $cleared_keep++;
                $cleared_for_entity++;
            }

            if ($cleared_for_entity > 0) {
                $entities_updated++;
            }
        }

        $store            = self::get_decision_store();
        $proposal_store   = isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];
        $proposal_summary = self::summarize_proposal_decisions($proposal_store);

        return new \WP_REST_Response([
            'proposal_id'       => $proposal_id,
            'cleared_keep'      => $cleared_keep,
            'entities_updated'  => $entities_updated,
            'proposal_summary'  => $proposal_summary,
        ]);
    }

    /**
     * REST: capture snapshot for a single entity on demand.
     */
    public static function capture_entity_snapshot(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Snapshot_Manager')) {
            return new \WP_Error('dbvc_snapshot_unavailable', __('Snapshot manager is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $proposal_id   = sanitize_text_field($request->get_param('proposal_id'));
        $vf_object_uid = sanitize_text_field($request->get_param('vf_object_uid'));
        $post_id       = DBVC_Sync_Posts::resolve_local_post_id(0, $vf_object_uid);

        if (! $post_id && $proposal_id !== '' && $vf_object_uid !== '' && class_exists('DBVC_Sync_Posts')) {
            $manifest = self::read_manifest_by_id($proposal_id);
            if ($manifest && ! empty($manifest['items']) && is_array($manifest['items'])) {
                $matched_item = null;
                foreach ($manifest['items'] as $item) {
                    $item_uid = isset($item['vf_object_uid']) ? (string) $item['vf_object_uid'] : '';
                    if ($item_uid !== '' && $item_uid === $vf_object_uid) {
                        $matched_item = $item;
                        break;
                    }
                }
                if ($matched_item) {
                    $identity = DBVC_Sync_Posts::identify_local_entity([
                        'vf_object_uid' => $vf_object_uid,
                        'post_id'       => $matched_item['post_id'] ?? 0,
                        'post_type'     => $matched_item['post_type'] ?? '',
                        'post_name'     => $matched_item['post_name'] ?? '',
                    ]);
                    if (! empty($identity['post_id'])) {
                        $post_id = (int) $identity['post_id'];
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            $match_source = isset($identity['match_source']) ? (string) $identity['match_source'] : 'unknown';
                            error_log(sprintf('[DBVC Snapshot] Fallback matched local entity for proposal %s uid %s via %s (post_id=%d).', $proposal_id, $vf_object_uid, $match_source, $post_id));
                        }
                    }
                }
            }
        }

        if (! $post_id) {
            return new \WP_Error('dbvc_invalid_entity', __('Entity is not available on this site yet.', 'dbvc'), ['status' => 400]);
        }

        try {
            DBVC_Snapshot_Manager::capture_post_snapshot($proposal_id, $post_id, $vf_object_uid);
            $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
            self::rebuild_entity_decisions_for_manifest_item($proposal_id, $vf_object_uid);
        } catch (\Throwable $e) {
            return new \WP_Error('dbvc_snapshot_failed', $e->getMessage(), ['status' => 500]);
        }

        if (! is_array($snapshot) || empty($snapshot)) {
            return new \WP_Error('dbvc_snapshot_missing', __('Snapshot could not be captured for this entity.', 'dbvc'), ['status' => 500]);
        }

        return new \WP_REST_Response([
            'proposal_id'   => $proposal_id,
            'vf_object_uid' => $vf_object_uid,
            'snapshot'      => $snapshot,
        ]);
    }

    /**
     * REST: capture snapshots for multiple entities within a proposal.
     */
    public static function capture_proposal_snapshot(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Snapshot_Manager')) {
            return new \WP_Error('dbvc_snapshot_unavailable', __('Snapshot manager is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        $manifest    = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_Error('dbvc_manifest_missing', __('Proposal manifest could not be found.', 'dbvc'), ['status' => 404]);
        }

        $entity_ids_param = $request->get_param('entity_ids');
        $entity_ids = [];
        if (is_string($entity_ids_param)) {
            $entity_ids = array_filter(array_map('sanitize_text_field', array_map('trim', explode(',', $entity_ids_param))));
        } elseif (is_array($entity_ids_param)) {
            $entity_ids = array_filter(array_map('sanitize_text_field', $entity_ids_param));
        }
        $entity_ids = array_values(array_unique($entity_ids));

        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        if (empty($items)) {
            return new \WP_Error('dbvc_manifest_empty', __('Proposal contains no entities to snapshot.', 'dbvc'), ['status' => 400]);
        }

        $manifest_index = [];
        foreach ($items as $item) {
            $entity_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($entity_uid !== '') {
                $manifest_index[$entity_uid] = $item;
            }
        }

        $targets = [];
        foreach ($items as $item) {
            if (($item['item_type'] ?? '') !== 'post') {
                continue;
            }
            $entity_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($entity_uid === '') {
                continue;
            }
            if (! empty($entity_ids) && ! in_array($entity_uid, $entity_ids, true)) {
                continue;
            }
            $local_post_id = DBVC_Sync_Posts::resolve_local_post_id(isset($item['post_id']) ? (int) $item['post_id'] : 0, $entity_uid, $item['post_type'] ?? '');
            if (! $local_post_id) {
                continue;
            }
            $targets[$entity_uid] = $local_post_id;
        }

        if (empty($targets)) {
            return new \WP_REST_Response([
                'proposal_id' => $proposal_id,
                'targets'     => 0,
                'captured'    => 0,
            ]);
        }

        $captured = 0;
        foreach ($targets as $entity_uid => $post_id) {
            try {
                DBVC_Snapshot_Manager::capture_post_snapshot($proposal_id, $post_id, $entity_uid);
                $captured++;
                if (isset($manifest_index[$entity_uid])) {
                    self::rebuild_entity_decisions_for_manifest_item($proposal_id, $entity_uid, $manifest_index[$entity_uid]);
                }
            } catch (\Throwable $e) {
                // Continue with remaining entities; optionally log.
            }
        }

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'targets'     => count($targets),
            'captured'    => $captured,
        ]);
    }

    /**
     * Persist a resolver decision.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function update_resolver_decision(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        $original_id = sanitize_text_field($request->get_param('original_id'));
        $params      = $request->get_json_params();

        $action   = isset($params['action']) ? sanitize_key($params['action']) : '';
        $target_id = isset($params['target_id']) ? absint($params['target_id']) : 0;
        $note     = isset($params['note']) ? sanitize_text_field($params['note']) : '';
        $persist_global = ! empty($params['persist_global']);

        $allowed_actions = ['reuse', 'download', 'map', 'skip'];
        if (! in_array($action, $allowed_actions, true)) {
            return new \WP_Error('dbvc_invalid_resolver_action', __('Invalid resolver action.', 'dbvc'), ['status' => 400]);
        }

        if (in_array($action, ['reuse', 'map'], true) && $target_id <= 0) {
            return new \WP_Error('dbvc_missing_target', __('Target attachment ID is required for this action.', 'dbvc'), ['status' => 400]);
        }

        $decision = [
            'action'    => $action,
            'target_id' => $target_id ?: null,
            'note'      => $note,
            'saved_at'  => current_time('mysql', true),
            'saved_by'  => get_current_user_id(),
        ];

        self::set_resolver_decision($proposal_id, $original_id, $decision, $persist_global);

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'original_id' => (int) $original_id,
            'decision'    => self::get_resolver_decision($proposal_id, $original_id),
        ]);
    }

    /**
     * Delete resolver decision.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function delete_resolver_decision_endpoint(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        $original_id = sanitize_text_field($request->get_param('original_id'));
        $scope       = sanitize_key($request->get_param('scope') ?? 'proposal');

        self::delete_resolver_decision($proposal_id, $original_id, $scope === 'global');

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'original_id' => (int) $original_id,
            'decision'    => self::get_resolver_decision($proposal_id, $original_id),
        ]);
    }

    /**
     * List global resolver rules.
     *
     * @return \WP_REST_Response
     */
    public static function list_resolver_rules()
    {
        $store  = self::get_resolver_decision_store();
        $global = isset($store['__global']) && is_array($store['__global']) ? $store['__global'] : [];
        $items  = [];

        foreach ($global as $original_id => $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $items[] = self::format_global_rule($original_id, $decision);
        }

        return new \WP_REST_Response([
            'rules' => $items,
        ]);
    }

    /**
     * Delete a global resolver rule.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function delete_resolver_rule(\WP_REST_Request $request)
    {
        $original_id = sanitize_text_field($request->get_param('original_id'));
        self::delete_resolver_decision('__global', $original_id, true);

        return new \WP_REST_Response([
            'original_id' => (int) $original_id,
        ]);
    }

    /**
     * Manually update proposal status (draft/closed).
     */
    public static function update_proposal_status(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        $body   = $request->get_json_params();
        $status = isset($body['status']) ? sanitize_key($body['status']) : '';
        $allowed = ['draft', 'closed'];
        if (! in_array($status, $allowed, true)) {
            return new \WP_Error('dbvc_invalid_status', __('Status must be draft or closed.', 'dbvc'), ['status' => 400]);
        }

        $restore_new_entities = null;
        if (isset($body['restore_new_entities'])) {
            if (function_exists('rest_sanitize_boolean')) {
                $restore_new_entities = rest_sanitize_boolean($body['restore_new_entities']);
            } else {
                $restore_new_entities = in_array($body['restore_new_entities'], [true, 1, '1', 'true', 'on'], true);
            }
        }
        if ($restore_new_entities === null) {
            $restore_new_entities = (get_option('dbvc_force_reapply_new_posts', '0') === '1');
        }

        if (! self::mark_proposal_status($proposal_id, $status)) {
            return new \WP_Error('dbvc_status_failed', __('Unable to update proposal status.', 'dbvc'), ['status' => 500]);
        }

        $restored_entities = [
            'total'   => 0,
            'applied' => 0,
        ];
        if ($status === 'draft' && $restore_new_entities) {
            $restored_entities = self::restore_new_entity_decisions($proposal_id);
        }

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'status'      => $status,
            'restore_new_entities' => (bool) $restore_new_entities,
            'restored_new_entities'=> $restored_entities,
        ]);
    }

    /**
     * Bulk delete resolver rules.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function bulk_delete_resolver_rules(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $ids    = isset($params['original_ids']) && is_array($params['original_ids']) ? $params['original_ids'] : [];

        $deleted = [];
        foreach ($ids as $original_id) {
            $original_id = sanitize_text_field($original_id);
            self::delete_resolver_decision('__global', $original_id, true);
            $deleted[] = (int) $original_id;
        }

        return new \WP_REST_Response([
            'deleted' => $deleted,
        ]);
    }

    /**
     * Create or update a global resolver rule.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function upsert_resolver_rule(\WP_REST_Request $request)
    {
        $params      = $request->get_json_params();
        $original_id = isset($params['original_id']) ? (string) absint($params['original_id']) : '';
        $action      = isset($params['action']) ? sanitize_key($params['action']) : '';
        $target_id   = isset($params['target_id']) ? absint($params['target_id']) : 0;
        $note        = isset($params['note']) ? sanitize_text_field($params['note']) : '';

        if ($original_id === '' || $original_id === '0') {
            return new \WP_Error('dbvc_invalid_original', __('Original ID is required.', 'dbvc'), ['status' => 400]);
        }

        $allowed_actions = ['reuse', 'download', 'map', 'skip'];
        if (! in_array($action, $allowed_actions, true)) {
            return new \WP_Error('dbvc_invalid_resolver_action', __('Invalid resolver action.', 'dbvc'), ['status' => 400]);
        }

        if (in_array($action, ['reuse', 'map'], true) && $target_id <= 0) {
            return new \WP_Error('dbvc_missing_target', __('Target attachment ID is required for this action.', 'dbvc'), ['status' => 400]);
        }

        $decision = [
            'action'    => $action,
            'target_id' => $target_id ?: null,
            'note'      => $note,
            'saved_at'  => current_time('mysql', true),
            'saved_by'  => get_current_user_id(),
        ];

        self::set_resolver_decision('__global', $original_id, $decision, true);
        $rule = self::get_resolver_decision('__global', $original_id) ?: $decision;

        return new \WP_REST_Response([
            'rule' => self::format_global_rule($original_id, $rule),
        ]);
    }

    /**
     * Bulk import resolver rules.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function import_resolver_rules(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $rules  = isset($params['rules']) && is_array($params['rules']) ? $params['rules'] : [];

        if (empty($rules)) {
            return new \WP_Error('dbvc_no_rules', __('No rules supplied for import.', 'dbvc'), ['status' => 400]);
        }

        $imported = [];
        $errors   = [];

        foreach ($rules as $index => $rule) {
            $original_id = isset($rule['original_id']) ? (string) absint($rule['original_id']) : '';
            $action      = isset($rule['action']) ? sanitize_key($rule['action']) : '';
            $target_id   = isset($rule['target_id']) ? absint($rule['target_id']) : 0;
            $note        = isset($rule['note']) ? sanitize_text_field($rule['note']) : '';

            if ($original_id === '' || $original_id === '0') {
                $errors[] = sprintf(__('Row %d: missing original ID.', 'dbvc'), $index + 1);
                continue;
            }
            $allowed_actions = ['reuse', 'download', 'map', 'skip'];
            if (! in_array($action, $allowed_actions, true)) {
                $errors[] = sprintf(__('Row %1$d: invalid action "%2$s".', 'dbvc'), $index + 1, $action);
                continue;
            }
            if (in_array($action, ['reuse', 'map'], true) && $target_id <= 0) {
                $errors[] = sprintf(__('Row %d: target ID is required.', 'dbvc'), $index + 1);
                continue;
            }

            $decision = [
                'action'    => $action,
                'target_id' => $target_id ?: null,
                'note'      => $note,
                'saved_at'  => current_time('mysql', true),
                'saved_by'  => get_current_user_id(),
            ];

            self::set_resolver_decision('__global', $original_id, $decision, true);
            $imported[] = self::format_global_rule($original_id, self::get_resolver_decision('__global', $original_id) ?: $decision);
        }

        return new \WP_REST_Response([
            'imported' => $imported,
            'errors'   => $errors,
        ]);
    }

    /**
     * REST: apply a proposal by invoking the import pipeline.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function apply_proposal(\WP_REST_Request $request)
    {
        $proposal_id = sanitize_text_field($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        if (! class_exists('DBVC_Sync_Posts')) {
            return new \WP_Error('dbvc_import_unavailable', __('Import pipeline is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $mode          = sanitize_key($request->get_param('mode') ?? 'full');
        $allowed_modes = ['full', 'partial'];
        if (! in_array($mode, $allowed_modes, true)) {
            $mode = 'full';
        }

        $ignore_missing_hash = false;
        $ignore_param = $request->get_param('ignore_missing_hash');
        if (function_exists('rest_sanitize_boolean')) {
            $ignore_missing_hash = rest_sanitize_boolean($ignore_param);
        } else {
            $ignore_missing_hash = in_array($ignore_param, [true, 1, '1', 'true', 'on'], true);
        }

        $force_reapply_new_posts = get_option('dbvc_force_reapply_new_posts', '0') === '1';
        $force_param = $request->get_param('force_reapply_new_posts');
        if ($force_param !== null) {
            if (function_exists('rest_sanitize_boolean')) {
                $force_reapply_new_posts = rest_sanitize_boolean($force_param);
            } else {
                $force_reapply_new_posts = in_array($force_param, [true, 1, '1', 'true', 'on'], true);
            }
        }

        $decision_store_before = self::get_decision_store();
        if (
            isset($decision_store_before[$proposal_id]['__summary'])
            && is_array($decision_store_before[$proposal_id]['__summary'])
        ) {
            $summary_before = $decision_store_before[$proposal_id]['__summary'];
        } elseif (isset($decision_store_before[$proposal_id]) && is_array($decision_store_before[$proposal_id])) {
            $summary_before = self::summarize_proposal_decisions($decision_store_before[$proposal_id]);
        } else {
            $summary_before = self::summarize_proposal_decisions([]);
        }
        $had_decisions = ($summary_before['total'] ?? 0) > 0;

        $import_options = ['mode' => $mode];
        if ($ignore_missing_hash) {
            $import_options['ignore_missing_hash'] = true;
        }
        if ($force_reapply_new_posts) {
            $import_options['force_reapply_new_posts'] = true;
        }

        $result = apply_filters('dbvc_import_backup_override', null, $proposal_id, $mode, $import_options);
        if ($result === null) {
            $result = DBVC_Sync_Posts::import_backup($proposal_id, $import_options);
        }
        if (is_wp_error($result)) {
            if (class_exists('DBVC_Sync_Logger')) {
                DBVC_Sync_Logger::log('Proposal apply failed', [
                    'proposal' => $proposal_id,
                    'mode'     => $mode,
                    'error'    => $result->get_error_message(),
                    'code'     => $result->get_error_code(),
                ]);
            }
            if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
                DBVC_Database::log_activity(
                    'proposal_apply_failed',
                    'error',
                    $result->get_error_message(),
                    [
                        'proposal' => $proposal_id,
                        'mode'     => $mode,
                        'code'     => $result->get_error_code(),
                    ]
                );
            }
            $status = 500;
            $error_data = $result->get_error_data();
            if (is_array($error_data) && isset($error_data['status'])) {
                $status = (int) $error_data['status'];
            } elseif (is_int($error_data)) {
                $status = $error_data;
            }

            return new \WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                ['status' => $status]
            );
        }

        $decision_store_after = self::get_decision_store();
        if (
            isset($decision_store_after[$proposal_id]['__summary'])
            && is_array($decision_store_after[$proposal_id]['__summary'])
        ) {
            $summary_after = $decision_store_after[$proposal_id]['__summary'];
        } elseif (isset($decision_store_after[$proposal_id]) && is_array($decision_store_after[$proposal_id])) {
            $summary_after = self::summarize_proposal_decisions($decision_store_after[$proposal_id]);
        } else {
            $summary_after = self::summarize_proposal_decisions([]);
        }

        $auto_clear_enabled = get_option('dbvc_auto_clear_decisions', '1') === '1';
        $decisions_cleared  = $had_decisions && (($summary_after['total'] ?? 0) === 0);
        $resolver_summary   = self::summarize_resolver_decisions($proposal_id);

        $status_after = ($summary_after['total'] ?? 0) === 0 ? 'closed' : 'draft';

        $errors = isset($result['errors']) && is_array($result['errors'])
            ? array_map('strval', $result['errors'])
            : [];

        if (! empty($errors)) {
            if (class_exists('DBVC_Sync_Logger')) {
                DBVC_Sync_Logger::log('Proposal apply completed with errors', [
                    'proposal' => $proposal_id,
                    'mode'     => $mode,
                    'errors'   => $errors,
                ]);
            }
            if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
                DBVC_Database::log_activity(
                    'proposal_apply_completed_with_errors',
                    'warning',
                    'Proposal apply completed with errors.',
                    [
                        'proposal' => $proposal_id,
                        'mode'     => $mode,
                        'errors'   => $errors,
                    ]
                );
            }
        }

        $response = [
            'proposal_id'         => $proposal_id,
            'mode'                => $mode,
            'result'              => [
                'imported'       => isset($result['imported']) ? (int) $result['imported'] : 0,
                'skipped'        => isset($result['skipped']) ? (int) $result['skipped'] : 0,
                'errors'         => $errors,
                'media'          => isset($result['media']) ? $result['media'] : [],
                'media_resolver' => isset($result['media_resolver']) ? $result['media_resolver'] : [],
                'media_reconcile'=> isset($result['media_reconcile']) ? $result['media_reconcile'] : [],
            ],
            'decisions_before'   => $summary_before,
            'decisions'          => $summary_after,
            'resolver_decisions' => $resolver_summary,
            'auto_clear_enabled' => $auto_clear_enabled,
            'decisions_cleared'  => $decisions_cleared,
            'had_decisions'      => $had_decisions,
            'ignore_missing_hash'=> $ignore_missing_hash,
            'force_reapply_new_posts' => (bool) $force_reapply_new_posts,
            'status'             => $status_after,
        ];

        self::mark_proposal_status($proposal_id, $status_after);

        return new \WP_REST_Response($response);
    }
    private static function mark_proposal_status(string $proposal_id, string $status): bool
    {
        if (! in_array($status, ['draft', 'closed'], true)) {
            return false;
        }

        if (! class_exists('DBVC_Backup_Manager')) {
            return false;
        }

        $base   = DBVC_Backup_Manager::get_base_path();
        $folder = trailingslashit($base) . $proposal_id;
        if (! is_dir($folder)) {
            return false;
        }

        $manifest = DBVC_Backup_Manager::read_manifest($folder);
        if (! is_array($manifest)) {
            return false;
        }

        $manifest['status'] = $status;

        $path = trailingslashit($folder) . DBVC_Backup_Manager::MANIFEST_FILENAME;
        return false !== file_put_contents(
            $path,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Produce a simple diff summary between current and proposed snapshots.
     *
     * @param array $current
     * @param array $proposed
     * @return array
     */
    private static function compare_snapshots(array $current, array $proposed): array
    {
        $changes = [];

        $current_flat  = self::flatten_snapshot($current);
        $proposed_flat = self::flatten_snapshot($proposed);

        $unique_keys = array_unique(array_merge(array_keys($current_flat), array_keys($proposed_flat)));

        foreach ($unique_keys as $key) {
            $old = $current_flat[$key] ?? null;
            $new = $proposed_flat[$key] ?? null;

            if ($old === $new) {
                continue;
            }

            $changes[] = [
                'path' => $key,
                'label' => self::humanize_path($key),
                'section' => self::determine_section($key),
                'from' => $old,
                'to'   => $new,
            ];
        }

        if (! empty($changes)) {
            $changes = array_values(array_filter($changes, function ($change) {
                $path = isset($change['path']) ? (string) $change['path'] : '';
                return $path === '' ? true : ! self::should_ignore_diff_path($path);
            }));
        }

        return [
            'changes' => $changes,
            'total'   => count($changes),
        ];
    }

    /**
     * Determine diff paths for an entity.
     *
     * @param string $proposal_id
     * @param string $vf_object_uid
     * @param array  $manifest_item
     * @return array
     */
    private static function resolve_entity_diff_paths(string $proposal_id, string $vf_object_uid, array $manifest_item): array
    {
        $current_path = isset($manifest_item['path']) ? (string) $manifest_item['path'] : '';
        $proposed = [];
        if ($current_path !== '') {
            $payload = self::read_entity_payload($proposal_id, $current_path);
            if (is_array($payload)) {
                $proposed = $payload;
            }
        }

        $current_source = 'bundle';
        $current = [];
        if (class_exists('DBVC_Snapshot_Manager')) {
            $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
            if (is_array($snapshot) && ! empty($snapshot)) {
                $current = $snapshot;
                $current_source = 'snapshot';
            }
        }

        if (empty($current)) {
            $current = $proposed;
        }

        $diff_summary = self::compare_snapshots($current, $proposed);
        $paths = [];
        foreach ($diff_summary['changes'] as $change) {
            $path = isset($change['path']) ? (string) $change['path'] : '';
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        if ($current_source === 'snapshot' && empty($paths)) {
            // fallback to manifest diff when no snapshot changes detected
            $manifest_diff = self::compare_snapshots($proposed, $proposed);
            foreach ($manifest_diff['changes'] as $change) {
                $path = isset($change['path']) ? (string) $change['path'] : '';
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * Re-evaluate decisions for an entity using the latest snapshot diff.
     *
     * @param string     $proposal_id
     * @param string     $vf_object_uid
     * @param array|null $manifest_item
     * @return void
     */
    private static function rebuild_entity_decisions_for_manifest_item(string $proposal_id, string $vf_object_uid, ?array $manifest_item = null): void
    {
        if ($manifest_item === null) {
            $manifest = self::read_manifest_by_id($proposal_id);
            if (! $manifest || empty($manifest['items']) || ! is_array($manifest['items'])) {
                return;
            }
            foreach ($manifest['items'] as $item) {
                $entity_uid = isset($item['vf_object_uid'])
                    ? (string) $item['vf_object_uid']
                    : (isset($item['post_id']) ? (string) $item['post_id'] : '');
                if ($entity_uid === $vf_object_uid) {
                    $manifest_item = $item;
                    break;
                }
            }
        }

        if (! $manifest_item) {
            return;
        }

        $paths = self::resolve_entity_diff_paths($proposal_id, $vf_object_uid, $manifest_item);
        self::prune_entity_decisions_for_paths($proposal_id, $vf_object_uid, $paths);
    }

    /**
     * Remove stored decisions that no longer match any diff paths.
     *
     * @param string $proposal_id
     * @param string $vf_object_uid
     * @param array  $paths
     * @return void
     */
    private static function prune_entity_decisions_for_paths(string $proposal_id, string $vf_object_uid, array $paths): void
    {
        $store = self::get_decision_store();
        if (
            ! isset($store[$proposal_id][$vf_object_uid])
            || ! is_array($store[$proposal_id][$vf_object_uid])
        ) {
            return;
        }

        $entity_store = $store[$proposal_id][$vf_object_uid];
        if (empty($entity_store)) {
            return;
        }

        $normalized_paths = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $normalized_paths[] = $path;
            if (
                strpos($path, 'meta.') !== 0
                && strpos($path, 'tax.') !== 0
                && strpos($path, 'post.') !== 0
                && strpos($path, '.') === false
            ) {
                $normalized_paths[] = 'post.' . $path;
            }
        }
        $normalized_paths = array_values(array_unique($normalized_paths));

        $changed = false;
        foreach ($entity_store as $path => $action) {
            if (! is_string($path) || $path === '' || $path === self::NEW_ENTITY_DECISION_KEY) {
                continue;
            }
            if (! self::decision_path_overlaps_list($path, $normalized_paths)) {
                unset($entity_store[$path]);
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        if (! isset($store[$proposal_id])) {
            $store[$proposal_id] = [];
        }

        if (! empty($entity_store)) {
            $store[$proposal_id][$vf_object_uid] = $entity_store;
        } else {
            unset($store[$proposal_id][$vf_object_uid]);
        }

        if (! empty($store[$proposal_id])) {
            $store[$proposal_id] = self::recalculate_proposal_summary($store[$proposal_id]);
        }

        $store = self::cleanup_empty_proposals($store, $proposal_id);
        self::set_decision_store($store);
    }

    private static function decision_path_overlaps_list(string $decision_path, array $valid_paths): bool
    {
        if ($decision_path === self::NEW_ENTITY_DECISION_KEY) {
            return true;
        }

        if (empty($valid_paths)) {
            return false;
        }

        foreach ($valid_paths as $path) {
            if ($path === '') {
                continue;
            }

            if (
                $decision_path === $path
                || strpos($decision_path, $path . '.') === 0
                || strpos($path, $decision_path . '.') === 0
            ) {
                return true;
            }
        }

        return false;
    }

    private static function summarize_entity_diff_counts(string $proposal_id, array $item, string $vf_object_uid): array
    {
        $path = isset($item['path']) ? (string) $item['path'] : '';
        if ($path === '') {
            return [
                'total' => 0,
                'meta'  => 0,
                'tax'   => 0,
            ];
        }

        if ($vf_object_uid === '' && isset($item['post_id'])) {
            $vf_object_uid = (string) $item['post_id'];
        }

        $proposed = self::read_entity_payload($proposal_id, $path);
        if (! is_array($proposed)) {
            return [
                'total' => 0,
                'meta'  => 0,
                'tax'   => 0,
            ];
        }

        $current = [];
        if (class_exists('DBVC_Snapshot_Manager') && $vf_object_uid !== '') {
            $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
            if (is_array($snapshot) && ! empty($snapshot)) {
                $current = $snapshot;
            }
        }

        if (empty($current)) {
            $current = $proposed;
        }

        $diff_summary = self::compare_snapshots($current, $proposed);
        $meta_changes = 0;
        $tax_changes  = 0;

        foreach ($diff_summary['changes'] as $change) {
            if (($change['section'] ?? '') === 'meta') {
                $meta_changes++;
            } elseif (($change['section'] ?? '') === 'tax') {
                $tax_changes++;
            }
        }

        return [
            'total' => isset($diff_summary['total']) ? (int) $diff_summary['total'] : 0,
            'meta'  => $meta_changes,
            'tax'   => $tax_changes,
        ];
    }

    /**
     * Flatten nested arrays into dot/bracket notation.
     *
     * @param array  $data
     * @param string $prefix
     * @return array
     */
    private static function flatten_snapshot(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat = array_merge($flat, self::flatten_snapshot($value, $path));
            } else {
                if (is_scalar($value) || $value === null) {
                    $flat[$path] = $value;
                } else {
                    $flat[$path] = json_encode($value);
                }
            }
        }

        return $flat;
    }

    private static function humanize_path(string $path): string
    {
        $parts = explode('.', $path);
        $parts = array_map(function ($part) {
            if (is_numeric($part)) {
                return "#{$part}";
            }
            return ucwords(str_replace('_', ' ', $part));
        }, $parts);
        return implode(' › ', $parts);
    }

    private static function determine_section(string $path): string
    {
        $root = explode('.', $path, 2)[0];
        switch ($root) {
            case 'meta':
                return 'meta';
            case 'post':
                return 'post_fields';
            case 'tax_input':
            case 'taxonomies':
                return 'tax';
            case 'media_refs':
                return 'media';
            case 'post_content':
                return 'content';
            case 'post_title':
                return 'title';
            case 'post_status':
                return 'status';
            default:
                if (in_array($root, self::$term_field_roots, true)) {
                    return 'term_fields';
                }
                return 'other';
        }
    }

    private static function get_diff_ignore_patterns(): array
    {
        if (is_array(self::$diff_ignore_patterns)) {
            return self::$diff_ignore_patterns;
        }

        $raw = get_option('dbvc_diff_ignore_paths', null);
        if ($raw === null || $raw === false) {
            $raw = self::DEFAULT_DIFF_IGNORE_PATHS;
        }

        if (! is_string($raw)) {
            $raw = '';
        }

        if (function_exists('dbvc_mask_parse_list')) {
            $patterns = dbvc_mask_parse_list($raw);
        } else {
            $parts = preg_split('/[,\r\n]+/', $raw);
            $patterns = [];
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $part = trim((string) $part);
                    if ($part !== '') {
                        $patterns[] = $part;
                    }
                }
            }
        }

        self::$diff_ignore_patterns = $patterns;
        return self::$diff_ignore_patterns;
    }

    private static function should_ignore_diff_path(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $patterns = self::get_diff_ignore_patterns();
        if (empty($patterns)) {
            return false;
        }

        $leaf = $path;
        $last_dot = strrpos($path, '.');
        if ($last_dot !== false) {
            $leaf = substr($path, $last_dot + 1);
        }

        foreach ($patterns as $pattern) {
            if (self::match_diff_pattern($path, $leaf, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function collect_masking_fields(string $proposal_id, array $manifest, int $page = 1, int $per_page = self::MASKING_CHUNK_DEFAULT): array
    {
        $patterns = self::get_mask_meta_patterns();
        $post_fields = isset($patterns['post_fields']) && is_array($patterns['post_fields'])
            ? $patterns['post_fields']
            : [];
        $has_meta_patterns = ! empty($patterns['keys']) || ! empty($patterns['subkeys']);
        if (! $has_meta_patterns && empty($post_fields)) {
            return [];
        }
        $post_field_labels = ! empty($post_fields) ? self::get_maskable_post_fields_map() : [];

        $fields = [];
        $debug_log_enabled = defined('DBVC_MASK_DEBUG') && DBVC_MASK_DEBUG;
        if ($debug_log_enabled) {
            error_log(sprintf('[DBVC Masking] Collecting fields for %s', $proposal_id));
        }
        $items  = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        if (empty($items)) {
            if ($debug_log_enabled) {
                error_log('[DBVC Masking] No manifest items found.');
            }
            return [];
        }

        $suppress_store = self::get_mask_suppression_store();
        $override_store = self::get_mask_override_store();
        $proposal_suppress = self::normalize_mask_entity_store($suppress_store[$proposal_id] ?? []);
        $proposal_overrides = self::normalize_mask_entity_store($override_store[$proposal_id] ?? []);

        $offset = max(0, ($page - 1) * $per_page);
        if ($per_page > 0) {
            $items = array_slice($items, $offset, $per_page, true);
        }

        foreach ($items as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                continue;
            }
            $vf_object_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($vf_object_uid === '' || empty($item['path'])) {
                continue;
            }

            $identity = $item_type === 'term'
                ? self::describe_term_identity($item)
                : self::describe_entity_identity($item);
            $diff_counts = self::summarize_entity_diff_counts($proposal_id, $item, $vf_object_uid);

            $diff_state = ($item_type === 'term')
                ? [
                    'needs_review'  => ($diff_counts['total'] ?? 0) > 0 || ! empty($identity['is_new']),
                    'reason'        => ! empty($identity['is_new']) ? 'new_term' : 'term_diff',
                    'expected_hash' => null,
                    'current_hash'  => null,
                  ]
                : self::evaluate_entity_diff_state($item, $vf_object_uid, $diff_counts, $identity);

            $proposed = self::read_entity_payload($proposal_id, (string) $item['path']);
            if (! is_array($proposed)) {
                continue;
            }

            $current = [];
            if (class_exists('DBVC_Snapshot_Manager')) {
                $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
                if (is_array($snapshot) && ! empty($snapshot)) {
                    $current = $snapshot;
                }
            }
            if (empty($current)) {
                $current = $proposed;
            }

            $meta_tree = isset($proposed['meta']) ? $proposed['meta'] : [];
            $current_meta = isset($current['meta']) ? $current['meta'] : [];

            if ($has_meta_patterns) {
                if (empty($meta_tree)) {
                    if ($debug_log_enabled) {
                        error_log(sprintf('[DBVC Masking] Skipping meta scan for %s (no meta payload)', $vf_object_uid));
                    }
                } else {
                    self::walk_mask_meta_tree(
                        $meta_tree,
                        $current_meta,
                        'meta',
                        $patterns,
                        function ($path, $value, $current_value) use (&$fields, $vf_object_uid, $item, $item_type, $proposal_overrides, $proposal_suppress, $diff_state, $debug_log_enabled) {
                            $meta_key = self::extract_meta_key_from_path($path);
                            if ($meta_key === '') {
                                if ($debug_log_enabled) {
                                    error_log(sprintf('[DBVC Masking] Path %s missing meta key', $path));
                                }
                                return;
                            }

                            $default_action = 'ignore';
                            $override_entry = self::get_mask_store_entry($proposal_overrides, $vf_object_uid, $meta_key, $path, 'meta');
                            $supp_entry     = self::get_mask_store_entry($proposal_suppress, $vf_object_uid, $meta_key, $path, 'meta');
                            if ($override_entry) {
                                $default_action = 'override';
                            } elseif ($supp_entry) {
                                $default_action = 'auto_accept';
                            }

                    $fields[] = [
                        'vf_object_uid' => $vf_object_uid,
                        'entity_type'   => $item_type,
                        'title'         => self::get_entity_display_title($item, $item_type),
                        'meta_path'     => $path,
                        'meta_key'      => $meta_key,
                        'label'         => self::humanize_path($path),
                        'section'       => self::determine_section($path),
                        'proposed_value'=> $value,
                        'current_value' => $current_value,
                        'default_action'=> $default_action,
                                'diff_state'    => $diff_state,
                                'override'      => $override_entry,
                                'suppressed'    => (bool) $supp_entry,
                            ];
                            if ($debug_log_enabled) {
                                error_log(sprintf('[DBVC Masking] Added %s for %s (action %s)', $path, $vf_object_uid, $default_action));
                            }
                        }
                    );
                }
            }

            if (! empty($post_fields) && $item_type === 'post') {
                foreach ($post_fields as $field_key) {
                    if (! array_key_exists($field_key, $proposed)) {
                        continue;
                    }
                    $path = 'post.' . $field_key;
                    $bucket_key = self::build_mask_bucket_key('post', $field_key);
                    $override_entry = self::get_mask_store_entry($proposal_overrides, $vf_object_uid, $bucket_key, $path, 'post');
                    $supp_entry     = self::get_mask_store_entry($proposal_suppress, $vf_object_uid, $bucket_key, $path, 'post');
                    $default_action = $override_entry ? 'override' : ($supp_entry ? 'auto_accept' : 'ignore');

                    $fields[] = [
                        'vf_object_uid' => $vf_object_uid,
                        'entity_type'   => $item_type,
                        'title'         => self::get_entity_display_title($item, $item_type),
                        'meta_path'     => $path,
                        'meta_key'      => $bucket_key,
                        'section'       => 'post_fields',
                        'label'         => $post_field_labels[$field_key] ?? self::humanize_path($path),
                        'proposed_value'=> $proposed[$field_key],
                        'current_value' => $current[$field_key] ?? null,
                        'default_action'=> $default_action,
                        'diff_state'    => $diff_state,
                        'override'      => $override_entry,
                        'suppressed'    => (bool) $supp_entry,
                    ];

                    if ($debug_log_enabled) {
                        error_log(sprintf('[DBVC Masking] Added %s for %s (scope post)', $path, $vf_object_uid));
                    }
                }
            }
        }

        if ($debug_log_enabled) {
            error_log(sprintf('[DBVC Masking] Returning %d mask rows', count($fields)));
        }

        return $fields;
    }

    private static function walk_mask_meta_tree($proposed, $current, string $path, array $patterns, callable $callback): void
    {
        if (is_object($proposed)) {
            $proposed = (array) $proposed;
        }
        if (is_object($current)) {
            $current = (array) $current;
        }

        if (is_array($proposed)) {
            foreach ($proposed as $key => $value) {
                $child_path = $path === '' ? (string) $key : $path . '.' . $key;
                $current_child = null;
                if (is_array($current) && array_key_exists($key, $current)) {
                    $current_child = $current[$key];
                } elseif (is_object($current) && isset($current->$key)) {
                    $current_child = $current->$key;
                }
                self::walk_mask_meta_tree($value, $current_child, $child_path, $patterns, $callback);
            }
            return;
        }

        if (is_object($proposed)) {
            self::walk_mask_meta_tree((array) $proposed, $current, $path, $patterns, $callback);
            return;
        }

        if (! self::mask_path_matches_patterns($path, $patterns['keys'], $patterns['subkeys'])) {
            return;
        }

        $callback($path, $proposed, (is_array($current) || is_object($current)) ? null : $current);
    }

    private static function summarize_masking_entities(string $proposal_id, array $vf_object_uids, array $manifest): array
    {
        if (empty($vf_object_uids)) {
            return [];
        }
        $vf_object_uids = array_values(array_unique(array_filter(array_map('sanitize_text_field', $vf_object_uids))));
        if (empty($vf_object_uids)) {
            return [];
        }

        $index = self::index_manifest_entities($manifest);
        $decision_store = self::get_decision_store();
        $proposal_decisions = $decision_store[$proposal_id] ?? [];

        $results = [];
        foreach ($vf_object_uids as $vf_object_uid) {
            if (! isset($index[$vf_object_uid])) {
                continue;
            }
            $item      = $index[$vf_object_uid];
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            $identity  = $item_type === 'term'
                ? self::describe_term_identity($item)
                : self::describe_entity_identity($item);
            $diff_counts = self::summarize_entity_diff_counts($proposal_id, $item, $vf_object_uid);
            $diff_state = ($item_type === 'term')
                ? [
                    'needs_review'  => ($diff_counts['total'] ?? 0) > 0 || ! empty($identity['is_new']),
                    'reason'        => ! empty($identity['is_new']) ? 'new_term' : 'term_diff',
                    'expected_hash' => null,
                    'current_hash'  => null,
                ]
                : self::evaluate_entity_diff_state($item, $vf_object_uid, $diff_counts, $identity);

            $entity_decisions = isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid])
                ? $proposal_decisions[$vf_object_uid]
                : [];

            $results[] = [
                'vf_object_uid'    => $vf_object_uid,
                'diff_state'       => $diff_state,
                'decision_summary' => self::summarize_entity_decisions($entity_decisions),
                'overall_status'   => ! empty($diff_state['needs_review']) ? 'needs_review' : 'resolved',
            ];
        }

        return $results;
    }

    private static function index_manifest_entities(array $manifest): array
    {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $index = [];
        foreach ($items as $item) {
            $uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');
            if ($uid === '') {
                continue;
            }
            $index[$uid] = $item;
        }
        return $index;
    }

    private static function get_mask_meta_patterns(): array
    {
        $keys_raw = trim((string) get_option('dbvc_mask_meta_keys', '') . "\n" . (string) get_option('dbvc_mask_defaults_meta_keys', ''));
        $subs_raw = trim((string) get_option('dbvc_mask_subkeys', '') . "\n" . (string) get_option('dbvc_mask_defaults_subkeys', ''));

        if (function_exists('dbvc_mask_parse_list')) {
            $key_patterns = dbvc_mask_parse_list($keys_raw);
            $sub_patterns = dbvc_mask_parse_list($subs_raw);
        } else {
            $key_patterns = self::simple_mask_list($keys_raw);
            $sub_patterns = self::simple_mask_list($subs_raw);
        }

        $post_field_input = get_option('dbvc_mask_post_fields', []);
        $post_fields = [];
        if (is_array($post_field_input) && ! empty($post_field_input)) {
            $available = array_keys(self::get_maskable_post_fields_map());
            $requested = array_map('sanitize_key', $post_field_input);
            $post_fields = array_values(array_intersect($requested, $available));
        }

        return [
            'keys'        => $key_patterns,
            'subkeys'     => $sub_patterns,
            'post_fields' => $post_fields,
        ];
    }

    private static function simple_mask_list(string $raw): array
    {
        $parts = preg_split('/[\\r\\n,]+/', $raw);
        $patterns = [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $patterns[] = $part;
                }
            }
        }
        return $patterns;
    }

    private static function extract_meta_key_from_path(string $path): string
    {
        $parsed = self::parse_mask_path($path);
        return $parsed['bucket_key'];
    }

    private static function parse_mask_path(string $path): array
    {
        $path = (string) $path;
        $scope = 'meta';
        $field = '';

        if (strpos($path, 'post.') === 0) {
            $scope = 'post';
            $field = substr($path, 5);
        } elseif (strpos($path, 'meta.') === 0) {
            $chunks = explode('.', $path);
            $field = $chunks[1] ?? '';
        } elseif ($path !== '') {
            $field = $path;
        }

        $field = trim($field);
        if ($field === '') {
            $field = $path;
        }

        if ($scope !== 'post' && self::is_maskable_post_field($field)) {
            $scope = 'post';
        }

        return [
            'scope'      => self::normalize_mask_scope($scope),
            'field'      => $field,
            'bucket_key' => self::build_mask_bucket_key($scope, $field),
        ];
    }

    private static function build_mask_bucket_key(string $scope, string $field): string
    {
        $scope_key = self::normalize_mask_scope($scope);
        return $scope_key === 'post' ? 'post:' . (string) $field : (string) $field;
    }

    private static function normalize_mask_scope(string $scope): string
    {
        return $scope === 'post' ? 'post' : 'meta';
    }

    private static function get_maskable_post_fields_map(): array
    {
        if (function_exists('dbvc_get_maskable_post_fields')) {
            $fields = dbvc_get_maskable_post_fields();
            if (is_array($fields)) {
                return $fields;
            }
        }

        return [
            'post_date'             => __('Post Date', 'dbvc'),
            'post_date_gmt'         => __('Post Date (GMT)', 'dbvc'),
            'post_modified'         => __('Post Modified', 'dbvc'),
            'post_modified_gmt'     => __('Post Modified (GMT)', 'dbvc'),
            'post_excerpt'          => __('Excerpt', 'dbvc'),
            'post_parent'           => __('Parent ID', 'dbvc'),
            'post_author'           => __('Author ID', 'dbvc'),
            'post_password'         => __('Password', 'dbvc'),
            'post_content_filtered' => __('Filtered Content', 'dbvc'),
            'menu_order'            => __('Menu Order', 'dbvc'),
            'guid'                  => __('GUID', 'dbvc'),
            'comment_status'        => __('Comment Status', 'dbvc'),
            'ping_status'           => __('Ping Status', 'dbvc'),
            'post_mime_type'        => __('MIME Type', 'dbvc'),
            'vf_object_uid'         => __('Entity UID', 'dbvc'),
        ];
    }

    private static function is_maskable_post_field(string $field): bool
    {
        $field = trim((string) $field);
        if ($field === '') {
            return false;
        }

        static $cache = null;
        if ($cache === null) {
            $cache = array_keys(self::get_maskable_post_fields_map());
        }

        return in_array($field, $cache, true);
    }

    private static function mask_path_matches_patterns(string $path, array $key_patterns, array $sub_patterns): bool
    {
        $leaf = $path;
        $dot  = strrpos($path, '.');
        if ($dot !== false) {
            $leaf = substr($path, $dot + 1);
        }

        if (! empty($key_patterns) && function_exists('dbvc_mask_should_remove_key')) {
            $meta_key = self::extract_meta_key_from_path($path);
            if ($meta_key !== '' && dbvc_mask_should_remove_key($meta_key, $key_patterns)) {
                return true;
            }
        }

        if (! empty($sub_patterns) && function_exists('dbvc_mask_should_remove_path')) {
            if (dbvc_mask_should_remove_path($path, $leaf, $sub_patterns)) {
                return true;
            }
        }

        return false;
    }

    private static function get_mask_suppression_store(): array
    {
        $store = get_option(self::MASK_SUPPRESS_OPTION, []);
        return is_array($store) ? $store : [];
    }

    private static function set_mask_suppression_store(array $store): void
    {
        update_option(self::MASK_SUPPRESS_OPTION, $store, false);
    }

    private static function get_mask_override_store(): array
    {
        $store = get_option(self::MASK_OVERRIDES_OPTION, []);
        return is_array($store) ? $store : [];
    }

    private static function set_mask_override_store(array $store): void
    {
        update_option(self::MASK_OVERRIDES_OPTION, $store, false);
    }

    private static function store_mask_suppression(array $store, string $vf_object_uid, string $meta_key, string $path, string $scope = 'meta'): array
    {
        if ($vf_object_uid === '' || $meta_key === '' || $path === '') {
            return $store;
        }

        $path_parts = self::parse_mask_path($path);
        $scope_key = self::normalize_mask_scope($scope !== '' ? $scope : $path_parts['scope']);
        $field_key = $path_parts['field'];

        if (! isset($store[$vf_object_uid]) || ! is_array($store[$vf_object_uid])) {
            $store[$vf_object_uid] = [];
        }
        if (! isset($store[$vf_object_uid][$scope_key]) || ! is_array($store[$vf_object_uid][$scope_key])) {
            $store[$vf_object_uid][$scope_key] = [];
        }
        if (! isset($store[$vf_object_uid][$scope_key][$meta_key]) || ! is_array($store[$vf_object_uid][$scope_key][$meta_key])) {
            $store[$vf_object_uid][$scope_key][$meta_key] = [];
        }
        $store[$vf_object_uid][$scope_key][$meta_key][$path] = [
            'path'      => $path,
            'meta_key'  => $meta_key,
            'scope'     => $scope_key,
            'field_key' => $field_key,
            'updated'   => current_time('mysql'),
        ];
        return $store;
    }

    private static function store_mask_override(array $store, string $vf_object_uid, string $meta_key, string $path, string $value, string $note = '', string $scope = 'meta', string $field_key = ''): array
    {
        if ($vf_object_uid === '' || $meta_key === '' || $path === '') {
            return $store;
        }

        $path_parts = self::parse_mask_path($path);
        $scope_key = self::normalize_mask_scope($scope !== '' ? $scope : $path_parts['scope']);
        $resolved_field = $field_key !== '' ? $field_key : $path_parts['field'];

        if (! isset($store[$vf_object_uid]) || ! is_array($store[$vf_object_uid])) {
            $store[$vf_object_uid] = [];
        }
        if (! isset($store[$vf_object_uid][$scope_key]) || ! is_array($store[$vf_object_uid][$scope_key])) {
            $store[$vf_object_uid][$scope_key] = [];
        }
        if (! isset($store[$vf_object_uid][$scope_key][$meta_key]) || ! is_array($store[$vf_object_uid][$scope_key][$meta_key])) {
            $store[$vf_object_uid][$scope_key][$meta_key] = [];
        }
        $store[$vf_object_uid][$scope_key][$meta_key][$path] = [
            'path'      => $path,
            'meta_key'  => $meta_key,
            'value'     => $value,
            'note'      => $note,
            'scope'     => $scope_key,
            'field_key' => $resolved_field,
            'updated'   => current_time('mysql'),
        ];
        return $store;
    }

    private static function cleanup_mask_store(array $store, string $proposal_id): array
    {
        if (! isset($store[$proposal_id]) || ! is_array($store[$proposal_id])) {
            return $store;
        }

        $normalized = self::normalize_mask_entity_store($store[$proposal_id]);
        if (empty($normalized)) {
            unset($store[$proposal_id]);
        } else {
            $store[$proposal_id] = $normalized;
        }

        return $store;
    }

    private static function normalize_mask_entity_store(array $store): array
    {
        $normalized = [];

        foreach ($store as $vf_object_uid => $meta_entries) {
            if (! is_array($meta_entries)) {
                continue;
            }

            $scoped = self::normalize_mask_scope_entries($meta_entries);
            if (! empty($scoped)) {
                $normalized[$vf_object_uid] = $scoped;
            }
        }

        return $normalized;
    }

    private static function normalize_mask_scope_entries(array $entries): array
    {
        $scoped = [];
        $has_explicit_scopes = false;

        foreach (['meta', 'post'] as $scope_key) {
            if (isset($entries[$scope_key]) && is_array($entries[$scope_key])) {
                $has_explicit_scopes = true;
                $bucket = self::normalize_mask_meta_entries($entries[$scope_key], $scope_key);
                if (! empty($bucket)) {
                    $scoped[$scope_key] = $bucket;
                }
            }
        }

        if (! $has_explicit_scopes) {
            $bucket = self::normalize_mask_meta_entries($entries, 'meta');
            if (! empty($bucket)) {
                $scoped['meta'] = $bucket;
            }
        }

        return $scoped;
    }

    private static function normalize_mask_meta_entries(array $entries, string $scope = 'meta'): array
    {
        $normalized = [];

        foreach ($entries as $meta_key => $bucket) {
            if (! is_array($bucket)) {
                continue;
            }

            if (self::is_mask_store_leaf($bucket)) {
                $bucket = [($bucket['path'] ?? (string) $meta_key) => $bucket];
            }

            foreach ($bucket as $path_key => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $meta_path = isset($entry['path']) ? (string) $entry['path'] : (string) $path_key;
                $meta_path = $meta_path !== '' ? $meta_path : (string) $path_key;
                $resolved_meta_key = isset($entry['meta_key']) && $entry['meta_key'] !== ''
                    ? (string) $entry['meta_key']
                    : (is_string($meta_key) && $meta_key !== '' ? (string) $meta_key : self::extract_meta_key_from_path($meta_path));

                if ($resolved_meta_key === '') {
                    continue;
                }

                $bucket_key = $resolved_meta_key;
                if ($scope === 'post' && strpos($bucket_key, 'post:') !== 0) {
                    $bucket_key = self::build_mask_bucket_key('post', $resolved_meta_key);
                }

                if (! isset($normalized[$bucket_key]) || ! is_array($normalized[$bucket_key])) {
                    $normalized[$bucket_key] = [];
                }

                $entry['path'] = $meta_path;
                $entry['meta_key'] = $bucket_key;
                $entry['scope'] = self::normalize_mask_scope($scope);
                if (! isset($entry['field_key']) || $entry['field_key'] === '') {
                    $parsed = self::parse_mask_path($meta_path);
                    $entry['field_key'] = $parsed['field'];
                }

                $normalized[$bucket_key][$meta_path] = $entry;
            }
        }

        return $normalized;
    }

    private static function is_mask_store_leaf(array $entry): bool
    {
        return array_key_exists('path', $entry) && ! array_key_exists(0, $entry);
    }

    private static function get_mask_store_entry(array $store, string $vf_object_uid, string $meta_key, string $meta_path, string $scope = 'meta'): ?array
    {
        if (! isset($store[$vf_object_uid]) || ! is_array($store[$vf_object_uid])) {
            return null;
        }

        $scope_key = self::normalize_mask_scope($scope);
        $scoped = null;
        if (isset($store[$vf_object_uid][$scope_key]) && is_array($store[$vf_object_uid][$scope_key])) {
            $scoped = $store[$vf_object_uid][$scope_key];
        } elseif (isset($store[$vf_object_uid][$meta_key])) {
            $scoped = $store[$vf_object_uid]; // legacy flat structure
        }

        if (! is_array($scoped)) {
            return null;
        }

        if (isset($scoped[$meta_key][$meta_path])) {
            return $scoped[$meta_key][$meta_path];
        }

        if (isset($scoped[$meta_key]['path']) && $scoped[$meta_key]['path'] === $meta_path) {
            return $scoped[$meta_key];
        }

        return null;
    }

    private static function remove_mask_store_entry(array $store, string $vf_object_uid, string $meta_key, string $meta_path, string $scope = 'meta'): array
    {
        if (! isset($store[$vf_object_uid]) || ! is_array($store[$vf_object_uid])) {
            return $store;
        }

        $scope_key = self::normalize_mask_scope($scope);
        $removed = false;

        if (isset($store[$vf_object_uid][$scope_key][$meta_key][$meta_path])) {
            unset($store[$vf_object_uid][$scope_key][$meta_key][$meta_path]);
            if (empty($store[$vf_object_uid][$scope_key][$meta_key])) {
                unset($store[$vf_object_uid][$scope_key][$meta_key]);
            }
            if (empty($store[$vf_object_uid][$scope_key])) {
                unset($store[$vf_object_uid][$scope_key]);
            }
            $removed = true;
        } elseif (isset($store[$vf_object_uid][$scope_key][$meta_key]['path']) && $store[$vf_object_uid][$scope_key][$meta_key]['path'] === $meta_path) {
            unset($store[$vf_object_uid][$scope_key][$meta_key]);
            if (empty($store[$vf_object_uid][$scope_key])) {
                unset($store[$vf_object_uid][$scope_key]);
            }
            $removed = true;
        } elseif (isset($store[$vf_object_uid][$meta_key][$meta_path])) {
            unset($store[$vf_object_uid][$meta_key][$meta_path]);
            if (empty($store[$vf_object_uid][$meta_key])) {
                unset($store[$vf_object_uid][$meta_key]);
            }
            $removed = true;
        } elseif (isset($store[$vf_object_uid][$meta_key]['path']) && $store[$vf_object_uid][$meta_key]['path'] === $meta_path) {
            unset($store[$vf_object_uid][$meta_key]);
            $removed = true;
        }

        if ($removed && empty($store[$vf_object_uid])) {
            unset($store[$vf_object_uid]);
        }

        return $store;
    }

    private static function get_entity_display_title(array $item, string $item_type): string
    {
        if ($item_type === 'term') {
            if (! empty($item['term_name'])) {
                return (string) $item['term_name'];
            }
            if (! empty($item['name'])) {
                return (string) $item['name'];
            }
            if (! empty($item['taxonomy']) && ! empty($item['term_slug'])) {
                return sprintf('%s/%s', $item['taxonomy'], $item['term_slug']);
            }
            return __('Term', 'dbvc');
        }

        if (! empty($item['post_title'])) {
            return (string) $item['post_title'];
        }
        if (! empty($item['post_name'])) {
            return (string) $item['post_name'];
        }
        return __('Post', 'dbvc');
    }

    private static function match_diff_pattern(string $full_path, string $leaf, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        if (function_exists('dbvc_mask_match')) {
            if (dbvc_mask_match($full_path, $pattern) || dbvc_mask_match($leaf, $pattern)) {
                return true;
            }
        } else {
            if (strpbrk($pattern, '*?') !== false) {
                $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.?'], preg_quote($pattern, '/')) . '$/i';
                if (preg_match($regex, $full_path) || preg_match($regex, $leaf)) {
                    return true;
                }
            } elseif ($full_path === $pattern || $leaf === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index resolver results by original attachment ID.
     *
     * @param array|null $resolver_result
     * @return array<int, array>
     */
    private static function index_resolver_by_original_id($resolver_result): array
    {
        $index = [];
        if (! is_array($resolver_result) || empty($resolver_result['attachments'])) {
            return $index;
        }

        foreach ($resolver_result['attachments'] as $resolution) {
            $descriptor = $resolution['descriptor'] ?? [];
            $original   = isset($descriptor['original_id']) ? (int) $descriptor['original_id'] : 0;
            if (! $original) {
                continue;
            }

            $index[$original] = array_merge(['descriptor' => $descriptor], $resolution);
        }

        return $index;
    }

    /**
     * Extract unique original attachment IDs from media refs.
     *
     * @param array $media_refs
     * @return int[]
     */
    private static function extract_original_ids_from_refs(array $media_refs): array
    {
        $ids = [];

        foreach ($media_refs['meta'] ?? [] as $ref) {
            if (! empty($ref['original_id'])) {
                $ids[] = (int) $ref['original_id'];
            }
        }

        foreach ($media_refs['content'] ?? [] as $ref) {
            if (! empty($ref['original_id'])) {
                $ids[] = (int) $ref['original_id'];
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Retrieve decision store.
     *
     * @return array
     */
    private static function get_decision_store(): array
    {
        $stored = get_option(self::DECISIONS_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * Summarize proposal-level decisions for quick UI badges.
     *
     * @param array $proposal_decisions
     * @return array{accepted:int,kept:int,total:int,entities_reviewed:int,entities_with_accept:int}
     */
    private static function summarize_proposal_decisions(array $proposal_decisions): array
    {
        $summary = [
            'accepted'             => 0,
            'kept'                 => 0,
            'accepted_new'         => 0,
            'total'                => 0,
            'entities_reviewed'    => 0,
            'entities_with_accept' => 0,
        ];

        foreach ($proposal_decisions as $entity_id => $decisions) {
            if (! is_array($decisions)) {
                continue;
            }

            $entity_key = (string) $entity_id;
            if ($entity_key !== '' && strpos($entity_key, '__') === 0) {
                continue;
            }

            $summary['entities_reviewed']++;
            $entity_accepts = 0;

            foreach ($decisions as $action) {
                if ($action === 'accept') {
                    $summary['accepted']++;
                    $entity_accepts++;
                } elseif ($action === 'keep') {
                    $summary['kept']++;
                } elseif ($action === 'accept_new') {
                    $summary['accepted_new']++;
                    $entity_accepts++;
                }
            }

            if ($entity_accepts > 0) {
                $summary['entities_with_accept']++;
            }
        }

        $summary['total'] = $summary['accepted'] + $summary['kept'] + $summary['accepted_new'];
        return $summary;
    }

    private static function summarize_manifest_new_entities(array $manifest, array $proposal_decisions): array
    {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $summary = [
            'total'   => 0,
            'accepted'=> 0,
            'pending' => 0,
        ];

        if (empty($items)) {
            return $summary;
        }

        foreach ($items as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if ($item_type !== 'post' && $item_type !== 'term') {
                continue;
            }

            $identity = ($item_type === 'term')
                ? self::describe_term_identity($item)
                : self::describe_entity_identity($item);

            if (empty($identity['is_new'])) {
                continue;
            }

            $summary['total']++;

            $vf_object_uid = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');

            $entity_decisions = ($vf_object_uid !== '' && isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid]))
                ? $proposal_decisions[$vf_object_uid]
                : [];

            $new_decision = '';
            if (
                isset($entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY])
                && is_string($entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY])
            ) {
                $new_decision = $entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY];
            }

            if ($new_decision === 'accept_new') {
                $summary['accepted']++;
            } else {
                $summary['pending']++;
            }
        }

        return $summary;
    }

    /**
     * Summaries for a single entity's decisions.
     *
     * @param array $entity_decisions
     * @return array{accepted:int,kept:int,total:int,has_accept:bool}
     */
    private static function summarize_entity_decisions(array $entity_decisions): array
    {
        $accepted = 0;
        $kept     = 0;
        $accepted_new = 0;

        foreach ($entity_decisions as $action) {
            if ($action === 'accept') {
                $accepted++;
            } elseif ($action === 'keep') {
                $kept++;
            } elseif ($action === 'accept_new') {
                $accepted_new++;
            }
        }

        return [
            'accepted'  => $accepted,
            'kept'      => $kept,
            'accepted_new' => $accepted_new,
            'total'     => $accepted + $kept + $accepted_new,
            'has_accept'=> $accepted > 0,
        ];
    }

    /**
     * Persist decision store.
     *
     * @param array $store
     * @return void
     */
    private static function set_decision_store(array $store): void
    {
        update_option(self::DECISIONS_OPTION, $store, false);
    }

    /**
     * Return decisions for a proposal/entity pair.
     *
     * @param string $proposal_id
     * @param string $vf_object_uid
     * @return array
     */
    private static function get_entity_decisions(string $proposal_id, string $vf_object_uid): array
    {
        $store = self::get_decision_store();
        $decisions = $store[$proposal_id][$vf_object_uid] ?? [];
        if (! is_array($decisions) || empty($decisions)) {
            return is_array($decisions) ? $decisions : [];
        }

        $normalized = $decisions;
        foreach ($decisions as $path => $action) {
            if (! is_string($path) || strpos($path, 'post.') !== 0) {
                continue;
            }
            $alias = substr($path, 5);
            if ($alias === '') {
                continue;
            }
            if (! isset($normalized[$alias])) {
                $normalized[$alias] = $action;
            }
        }

        return $normalized;
    }

    /**
     * Set decision for a specific field path.
     *
     * @param string $proposal_id
     * @param string $vf_object_uid
     * @param string $path
     * @param string $action
     * @return void
     */
    private static function set_entity_decision(string $proposal_id, string $vf_object_uid, string $path, string $action): void
    {
        $store = self::get_decision_store();
        if (! isset($store[$proposal_id])) {
            $store[$proposal_id] = [];
        }
        if (! isset($store[$proposal_id][$vf_object_uid])) {
            $store[$proposal_id][$vf_object_uid] = [];
        }
        $store[$proposal_id][$vf_object_uid][$path] = $action;
        $store[$proposal_id] = self::recalculate_proposal_summary($store[$proposal_id]);
        $store = self::cleanup_empty_proposals($store, $proposal_id);
        self::set_decision_store($store);

        if (
            defined('DBVC_NEW_ENTITY_DECISION_KEY')
            && $path === DBVC_NEW_ENTITY_DECISION_KEY
            && $action !== 'accept_new'
            && class_exists('DBVC_Sync_Posts')
        ) {
            DBVC_Sync_Posts::remove_proposal_new_entity($proposal_id, $vf_object_uid);
        }
    }

    private static function clear_entity_decision(string $proposal_id, string $vf_object_uid, string $path): void
    {
        $store = self::get_decision_store();
        if (! isset($store[$proposal_id])) {
            return;
        }

        if (isset($store[$proposal_id][$vf_object_uid][$path])) {
            unset($store[$proposal_id][$vf_object_uid][$path]);
        }

        if (isset($store[$proposal_id][$vf_object_uid]) && empty($store[$proposal_id][$vf_object_uid])) {
            unset($store[$proposal_id][$vf_object_uid]);
        }

        if (! empty($store[$proposal_id])) {
            $store[$proposal_id] = self::recalculate_proposal_summary($store[$proposal_id]);
        }

        $store = self::cleanup_empty_proposals($store, $proposal_id);
        self::set_decision_store($store);

        if (
            defined('DBVC_NEW_ENTITY_DECISION_KEY')
            && $path === DBVC_NEW_ENTITY_DECISION_KEY
            && class_exists('DBVC_Sync_Posts')
        ) {
            DBVC_Sync_Posts::remove_proposal_new_entity($proposal_id, $vf_object_uid);
        }
    }

    private static function clear_all_entity_decisions(string $proposal_id, string $vf_object_uid): void
    {
        $store = self::get_decision_store();
        if (! isset($store[$proposal_id][$vf_object_uid])) {
            return;
        }

        unset($store[$proposal_id][$vf_object_uid]);
        if (! empty($store[$proposal_id])) {
            $store[$proposal_id] = self::recalculate_proposal_summary($store[$proposal_id]);
        }
        $store = self::cleanup_empty_proposals($store, $proposal_id);
        self::set_decision_store($store);

        if (
            defined('DBVC_NEW_ENTITY_DECISION_KEY')
            && class_exists('DBVC_Sync_Posts')
        ) {
            DBVC_Sync_Posts::remove_proposal_new_entity($proposal_id, $vf_object_uid);
        }
    }

    private static function recalculate_proposal_summary(array $proposal_store): array
    {
        if (isset($proposal_store['__summary'])) {
            unset($proposal_store['__summary']);
        }

        if (! empty($proposal_store)) {
            $proposal_store['__summary'] = self::summarize_proposal_decisions($proposal_store);
        }

        return $proposal_store;
    }

    private static function cleanup_empty_proposals(array $store, string $proposal_id): array
    {
        if (! isset($store[$proposal_id])) {
            return $store;
        }

        $snapshot = $store[$proposal_id];
        if (isset($snapshot['__summary'])) {
            unset($snapshot['__summary']);
        }

        if (empty($snapshot)) {
            unset($store[$proposal_id]);
        }

        return $store;
    }

    public static function clear_all_proposals()
    {
        if (! class_exists('DBVC_Backup_Manager')) {
            return new \WP_Error('dbvc_missing_manager', __('Backup manager unavailable.', 'dbvc'), ['status' => 500]);
        }

        $base = DBVC_Backup_Manager::get_base_path();
        if (is_dir($base)) {
            $folders = glob($base . '/*', GLOB_ONLYDIR);
            foreach ($folders as $folder) {
                self::delete_directory_recursive($folder);
            }
        }

        if (class_exists('DBVC_Snapshot_Manager')) {
            $snapshot_base = DBVC_Snapshot_Manager::get_base_path();
            if (is_dir($snapshot_base)) {
                $folders = glob($snapshot_base . '/*', GLOB_ONLYDIR);
                foreach ($folders as $folder) {
                    self::delete_directory_recursive($folder);
                }
            }
        }

        delete_option(self::DECISIONS_OPTION);

        $resolver_store = get_option(self::RESOLVER_DECISIONS_OPTION, []);
        if (is_array($resolver_store)) {
            foreach ($resolver_store as $key => $value) {
                if ($key === '__global') {
                    continue;
                }
                unset($resolver_store[$key]);
            }
            update_option(self::RESOLVER_DECISIONS_OPTION, $resolver_store, false);
        }

        return new \WP_REST_Response([
            'status' => 'cleared',
        ]);
    }

    /**
     * Capture client-side app errors for logging.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function log_client_error(\WP_REST_Request $request)
    {
        $message = sanitize_text_field((string) $request->get_param('message'));
        $stack   = sanitize_textarea_field((string) $request->get_param('stack'));
        $component_stack = sanitize_textarea_field((string) $request->get_param('componentStack'));
        $path    = sanitize_text_field((string) $request->get_param('path'));
        $context = sanitize_text_field((string) $request->get_param('context'));

        $payload = [
            'context'        => $context,
            'path'           => $path,
            'stack'          => $stack,
            'componentStack' => $component_stack,
        ];

        if (class_exists('DBVC_Sync_Logger')) {
            DBVC_Sync_Logger::log('Admin app error boundary captured an error', array_merge(
                ['message' => $message],
                array_filter($payload)
            ));
        }

        if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
            DBVC_Database::log_activity(
                'admin_app_error',
                'error',
                $message,
                array_filter($payload)
            );
        }

        return new \WP_REST_Response([
            'logged' => true,
        ]);
    }

    /**
     * Retrieve resolver decision store.
     *
     * @return array
     */
    private static function get_resolver_decision_store(): array
    {
        $stored = get_option(self::RESOLVER_DECISIONS_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * Persist resolver decision store.
     *
     * @param array $store
     * @return void
     */
    private static function set_resolver_decision_store(array $store): void
    {
        update_option(self::RESOLVER_DECISIONS_OPTION, $store, false);
    }

    /**
     * Build preview metadata for a resolver attachment.
     *
     * @param array $descriptor
     * @param int   $target_id
     * @return array|null
     */
    private static function build_attachment_preview(array $descriptor, int $target_id = 0, string $proposal_id = ''): ?array
    {
        $proposed = self::build_manifest_preview($descriptor, $proposal_id);
        $local    = $target_id ? wp_get_attachment_image_url($target_id, 'thumbnail') : null;

        if (! $proposed && ! $local) {
            return null;
        }

        return [
            'proposed' => $proposed,
            'local'    => $local,
            'local_id' => $local ? $target_id : null,
        ];
    }

    /**
     * Generate base64 thumbnail for manifest asset.
     *
     * @param array $descriptor
     * @param string $proposal_id
     * @return string|null
     */
    private static function build_manifest_preview(array $descriptor, string $proposal_id = ''): ?string
    {
        $manifest_dir = '';
        if ($proposal_id !== '' && class_exists('DBVC_Backup_Manager')) {
            $manifest_dir = trailingslashit(DBVC_Backup_Manager::get_base_path()) . $proposal_id;
        } elseif (function_exists('dbvc_get_sync_path')) {
            $manifest_dir = trailingslashit(dbvc_get_sync_path());
        }

        if ($manifest_dir === '') {
            return null;
        }
        $manifest_dir = trailingslashit(wp_normalize_path($manifest_dir));

        $relative_candidates = [];
        if (! empty($descriptor['bundle_path'])) {
            $relative_candidates[] = (string) $descriptor['bundle_path'];
        }
        if (! empty($descriptor['path'])) {
            $relative_candidates[] = (string) $descriptor['path'];
        }
        if (! empty($descriptor['relative_path'])) {
            $relative_candidates[] = (string) $descriptor['relative_path'];
        }

        $path = null;
        foreach ($relative_candidates as $candidate) {
            $candidate_path = wp_normalize_path($manifest_dir . ltrim($candidate, '/'));
            if (file_exists($candidate_path)) {
                $path = $candidate_path;
                break;
            }
        }

        if (! $path) {
            $remote = isset($descriptor['source_url']) ? esc_url_raw($descriptor['source_url']) : '';
            if ($remote && self::is_image_like($remote)) {
                return $remote;
            }
            return null;
        }
        if (! file_exists($path) || ! is_readable($path)) {
            return null;
        }

        $type = wp_check_filetype(basename($path));
        if (empty($type['type']) || strpos($type['type'], 'image/') !== 0) {
            return null;
        }

        $editor = wp_get_image_editor($path);
        if (! is_wp_error($editor)) {
            $editor->resize(320, 320, false);
            $temp_file = wp_tempnam(basename($path));
            if ($temp_file) {
                $saved = $editor->save($temp_file);
                if (! is_wp_error($saved) && ! empty($saved['path'])) {
                    $contents = file_get_contents($saved['path']);
                    @unlink($saved['path']);
                    if ($contents !== false) {
                        $mime = $saved['mime-type'] ?? $saved['type'] ?? $type['type'];
                        return sprintf('data:%s;base64,%s', $mime, base64_encode($contents));
                    }
                }
                @unlink($temp_file);
            }
        }

        $size = filesize($path);
        if ($size === false || $size > 1024 * 1024) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return sprintf('data:%s;base64,%s', $type['type'], base64_encode($contents));
    }

    /**
     * Check if a URL/path appears to be an image.
     *
     * @param string $path
     * @return bool
     */
    private static function is_image_like(string $path): bool
    {
        $ext = pathinfo(parse_url($path, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
        if (! $ext) {
            return false;
        }
        $type = wp_check_filetype('preview.' . $ext);
        return ! empty($type['type']) && strpos($type['type'], 'image/') === 0;
    }

    /**
     * Return resolver decision for given proposal/original pair, falling back to global rules.
     *
     * @param string $proposal_id
     * @param string $original_id
     * @return array|null
     */
    private static function get_resolver_decision(string $proposal_id, string $original_id): ?array
    {
        $store = self::get_resolver_decision_store();
        if (isset($store[$proposal_id][$original_id])) {
            return $store[$proposal_id][$original_id];
        }
        if (isset($store['__global'][$original_id])) {
            return $store['__global'][$original_id];
        }
        return null;
    }

    /**
     * Save resolver decision.
     *
     * @param string $proposal_id
     * @param string $original_id
     * @param array  $decision
     * @param bool   $persist_global
     * @return void
     */
    private static function set_resolver_decision(string $proposal_id, string $original_id, array $decision, bool $persist_global = false): void
    {
        $store = self::get_resolver_decision_store();
        if (! isset($store[$proposal_id])) {
            $store[$proposal_id] = [];
        }
        $decision['scope'] = $persist_global ? 'global' : 'proposal';
        $store[$proposal_id][$original_id] = $decision;

        if ($persist_global) {
            if (! isset($store['__global'])) {
                $store['__global'] = [];
            }
            $store['__global'][$original_id] = array_merge($decision, ['scope' => 'global']);
        }

        self::set_resolver_decision_store($store);
    }

    /**
     * Delete resolver decision.
     *
     * @param string $proposal_id
     * @param string $original_id
     * @param bool   $global
     * @return void
     */
    private static function delete_resolver_decision(string $proposal_id, string $original_id, bool $global = false): void
    {
        $store = self::get_resolver_decision_store();
        if (isset($store[$proposal_id][$original_id])) {
            unset($store[$proposal_id][$original_id]);
            if (empty($store[$proposal_id])) {
                unset($store[$proposal_id]);
            }
        }

        if ($global && isset($store['__global'][$original_id])) {
            unset($store['__global'][$original_id]);
            if (empty($store['__global'])) {
                unset($store['__global']);
            }
        }

        self::set_resolver_decision_store($store);
    }

    /**
     * Normalize resolver rule for API responses.
     *
     * @param string     $original_id
     * @param array|null $decision
     * @return array
     */
    private static function format_global_rule(string $original_id, ?array $decision): array
    {
        $decision = is_array($decision) ? $decision : [];

        return [
            'original_id' => (int) $original_id,
            'action'      => isset($decision['action']) ? (string) $decision['action'] : '',
            'target_id'   => isset($decision['target_id']) ? (int) $decision['target_id'] : null,
            'note'        => isset($decision['note']) ? (string) $decision['note'] : '',
            'saved_at'    => isset($decision['saved_at']) ? (string) $decision['saved_at'] : null,
            'saved_by'    => isset($decision['saved_by']) ? (int) $decision['saved_by'] : null,
        ];
    }

    private static function describe_entity_identity(array $item): array
    {
        if (($item['item_type'] ?? '') === 'term' || isset($item['term_taxonomy']) || isset($item['taxonomy'])) {
            return self::describe_term_identity($item);
        }

        $vf_object_uid = isset($item['vf_object_uid'])
            ? (string) $item['vf_object_uid']
            : (isset($item['post_id']) ? (string) $item['post_id'] : '');

        $context = [
            'vf_object_uid' => $vf_object_uid,
            'post_id'       => isset($item['post_id']) ? (int) $item['post_id'] : 0,
            'post_type'     => isset($item['post_type']) ? (string) $item['post_type'] : '',
            'post_name'     => isset($item['post_name']) ? (string) $item['post_name'] : '',
        ];

        $identity = [
            'post_id'      => null,
            'match_source' => 'none',
        ];

        if (class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'identify_local_entity')) {
            $identity = DBVC_Sync_Posts::identify_local_entity($context);
        }

        $match_source = isset($identity['match_source']) ? (string) $identity['match_source'] : 'none';
        $local_post_id = isset($identity['post_id']) ? (int) $identity['post_id'] : null;

        $local_uid = '';
        if ($local_post_id) {
            $stored_uid = get_post_meta($local_post_id, 'vf_object_uid', true);
            if (is_string($stored_uid)) {
                $local_uid = $stored_uid;
            }
        }
        $uid_mismatch = ($local_post_id && $vf_object_uid !== '' && $local_uid !== '' && $local_uid !== $vf_object_uid);

        return [
            'local_post_id' => $local_post_id ?: null,
            'match_source'  => $match_source !== '' ? $match_source : 'none',
            'is_new'        => $local_post_id ? false : true,
            'local_uid'     => $local_uid,
            'uid_mismatch'  => $uid_mismatch,
        ];
    }

    private static function describe_term_identity(array $item): array
    {
        $vf_object_uid = isset($item['vf_object_uid']) ? (string) $item['vf_object_uid'] : '';
        $taxonomy      = isset($item['term_taxonomy']) ? sanitize_key($item['term_taxonomy']) : (isset($item['taxonomy']) ? sanitize_key($item['taxonomy']) : '');
        $term_id       = isset($item['term_id']) ? (int) $item['term_id'] : 0;
        $slug          = isset($item['term_slug']) ? sanitize_title($item['term_slug']) : (isset($item['slug']) ? sanitize_title($item['slug']) : '');

        $match_source = 'none';
        $local_id     = null;

        if ($vf_object_uid !== '' && class_exists('DBVC_Database')) {
            $record = DBVC_Database::get_entity_by_uid($vf_object_uid);
            if ($record && ! empty($record->object_id) && is_string($record->object_type) && strpos($record->object_type, 'term:') === 0) {
                $local_id = (int) $record->object_id;
                $match_source = 'uid';
            }
        }

        if (! $local_id && $term_id) {
            $term = get_term($term_id);
            if ($term && ! is_wp_error($term)) {
                $local_id = (int) $term->term_id;
                $match_source = 'id';
            }
        }

        if (! $local_id && $slug !== '' && $taxonomy && taxonomy_exists($taxonomy)) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term && ! is_wp_error($term)) {
                $local_id = (int) $term->term_id;
                $match_source = 'slug';
            }
        }

        $local_uid = '';
        if ($local_id) {
            $stored_uid = get_term_meta($local_id, 'vf_object_uid', true);
            if (is_string($stored_uid)) {
                $local_uid = $stored_uid;
            }
        }
        $uid_mismatch = ($local_id && $vf_object_uid !== '' && $local_uid !== '' && $local_uid !== $vf_object_uid);

        return [
            'local_post_id' => $local_id ?: null,
            'match_source'  => $match_source !== '' ? $match_source : 'none',
            'is_new'        => $local_id ? false : true,
            'local_uid'     => $local_uid,
            'uid_mismatch'  => $uid_mismatch,
        ];
    }

    private static function format_term_manifest_entity(string $proposal_id, array $item, string $status_filter, array $proposal_decisions): ?array
    {
        $vf_object_uid = isset($item['vf_object_uid']) ? (string) $item['vf_object_uid'] : '';
        $taxonomy      = isset($item['term_taxonomy']) ? sanitize_key($item['term_taxonomy']) : (isset($item['taxonomy']) ? sanitize_key($item['taxonomy']) : '');
        $term_name     = isset($item['term_name']) ? (string) $item['term_name'] : (isset($item['name']) ? (string) $item['name'] : '');
        $term_slug     = isset($item['term_slug']) ? (string) $item['term_slug'] : (isset($item['slug']) ? (string) $item['slug'] : '');
        $term_id       = isset($item['term_id']) ? (int) $item['term_id'] : null;

        $identity = self::describe_term_identity($item);
        $is_new_entity = $identity['is_new'];

        $diff_counts = self::summarize_entity_diff_counts($proposal_id, $item, $vf_object_uid);
        $has_changes = ($diff_counts['total'] ?? 0) > 0;

        $needs_review = $is_new_entity || $has_changes;

        if ($status_filter === 'needs_review' && ! $needs_review) {
            return null;
        }
        if ($status_filter === 'resolved' && $needs_review) {
            return null;
        }
        if ($status_filter === 'needs_review_media') {
            return null;
        }
        if ($status_filter === 'new_entities' && ! $is_new_entity) {
            return null;
        }

        $entity_decisions = ($vf_object_uid !== '' && isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid]))
            ? $proposal_decisions[$vf_object_uid]
            : [];
        $decision_summary = self::summarize_entity_decisions($entity_decisions);
        $new_entity_decision = self::get_new_entity_decision($proposal_id, $vf_object_uid, $entity_decisions);

        $diff_reason = $is_new_entity ? 'new_term' : ($has_changes ? 'term_modified' : 'term_clean');

        return [
            'vf_object_uid' => $vf_object_uid !== '' ? $vf_object_uid : ($term_slug !== '' ? $term_slug : uniqid('term_', true)),
            'post_id'       => $term_id,
            'post_type'     => $taxonomy ? ('term:' . $taxonomy) : 'term',
            'post_title'    => $term_name !== '' ? $term_name : ($taxonomy . '/' . $term_slug),
            'post_status'   => 'term',
            'post_name'     => $term_slug,
            'post_modified' => $item['post_modified'] ?? null,
            'path'          => $item['path'] ?? '',
            'hash'          => $item['hash'] ?? '',
            'content_hash'  => $item['content_hash'] ?? null,
            'media_refs'    => [
                'meta'    => [],
                'content' => [],
            ],
            'diff_state' => [
                'needs_review'  => $needs_review,
                'reason'        => $diff_reason,
                'expected_hash' => null,
                'current_hash'  => null,
                'local_post_id' => $identity['local_post_id'],
            ],
            'diff_total'        => $diff_counts['total'] ?? 0,
            'meta_diff_count'   => $diff_counts['meta'] ?? 0,
            'tax_diff_count'    => 0,
            'media_needs_review'=> false,
            'overall_status'    => $needs_review ? 'needs_review' : 'resolved',
            'resolver'          => [
                'summary'     => [
                    'total'      => 0,
                    'resolved'   => 0,
                    'unresolved' => 0,
                    'conflicts'  => 0,
                    'unknown'    => 0,
                ],
                'attachments' => [],
                'status'      => 'resolved',
            ],
            'entity_type'        => 'term',
            'term_taxonomy'      => $taxonomy,
            'is_new_entity'      => $is_new_entity,
            'identity_match'     => $identity['match_source'],
            'local_uid'          => $identity['local_uid'] ?? '',
            'uid_mismatch'       => $identity['uid_mismatch'] ?? false,
            'new_entity_decision'=> $new_entity_decision,
            'decision_summary'   => $decision_summary,
        ];
    }

    private static function get_new_entity_decision(string $proposal_id, string $vf_object_uid, ?array $decisions = null): string
    {
        if ($decisions === null) {
            $decisions = self::get_entity_decisions($proposal_id, $vf_object_uid);
        }

        if (
            isset($decisions[self::NEW_ENTITY_DECISION_KEY])
            && is_string($decisions[self::NEW_ENTITY_DECISION_KEY])
        ) {
            return $decisions[self::NEW_ENTITY_DECISION_KEY];
        }

        return '';
    }

    private static function restore_new_entity_decisions(string $proposal_id): array
    {
        if ($proposal_id === '' || ! class_exists('DBVC_Sync_Posts')) {
            return ['total' => 0, 'applied' => 0];
        }

        $entity_uids = DBVC_Sync_Posts::get_proposal_new_entities($proposal_id);
        if (empty($entity_uids)) {
            return ['total' => 0, 'applied' => 0];
        }

        $applied = 0;
        foreach ($entity_uids as $entity_uid) {
            $entity_uid = (string) $entity_uid;
            if ($entity_uid === '') {
                continue;
            }
            self::set_entity_decision($proposal_id, $entity_uid, DBVC_NEW_ENTITY_DECISION_KEY, 'accept_new');
            $applied++;
        }

        return [
            'total'   => count($entity_uids),
            'applied' => $applied,
        ];
    }

    /**
     * Summarize resolver decisions for a proposal.
     *
     * @param string $proposal_id
     * @return array
     */
    private static function summarize_resolver_decisions(string $proposal_id): array
    {
        $store = self::get_resolver_decision_store();
        $decisions = isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];

        $summary = [
            'total'   => 0,
            'reuse'   => 0,
            'download'=> 0,
            'map'     => 0,
            'skip'    => 0,
            'global_rules' => isset($store['__global']) ? count($store['__global']) : 0,
        ];

        foreach ($decisions as $key => $decision) {
            if ($key === '__summary' || ! is_array($decision)) {
                continue;
            }
            $summary['total']++;
            $action = $decision['action'] ?? '';
            if (isset($summary[$action])) {
                $summary[$action]++;
            }
        }

        return $summary;
    }

    private static function handle_entity_hash_sync(array $manifest, string $proposal_id, string $vf_object_uid)
    {
        if (! class_exists('DBVC_Sync_Posts')) {
            return new \WP_Error('dbvc_sync_unavailable', __('Import pipeline is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $item = self::find_manifest_item_by_uid($manifest, $vf_object_uid);
        if (! $item) {
            return new \WP_Error('dbvc_entity_missing', __('Entity not found in proposal manifest.', 'dbvc'), ['status' => 404]);
        }

        $content_hash = isset($item['content_hash']) ? (string) $item['content_hash'] : '';
        if ($content_hash === '') {
            return new \WP_Error('dbvc_missing_content_hash', __('Manifest lacks a content hash for this entity.', 'dbvc'), ['status' => 400]);
        }

        $post_type = isset($item['post_type']) ? $item['post_type'] : '';
        $post_id   = DBVC_Sync_Posts::resolve_local_post_id(isset($item['post_id']) ? (int) $item['post_id'] : 0, $vf_object_uid, $post_type);
        if (! $post_id) {
            return new \WP_Error('dbvc_local_post_missing', __('Matching post was not found on this site.', 'dbvc'), ['status' => 400]);
        }

        if (! DBVC_Sync_Posts::store_import_hash($post_id, $content_hash)) {
            return new \WP_Error('dbvc_hash_store_failed', __('Unable to store import hash for this post.', 'dbvc'), ['status' => 500]);
        }

        $diff_counts = self::summarize_entity_diff_counts($proposal_id, $item, $vf_object_uid);

        return [
            'proposal_id'   => $proposal_id,
            'vf_object_uid' => $vf_object_uid,
            'post_id'       => $post_id,
            'content_hash'  => $content_hash,
            'diff_state'    => self::evaluate_entity_diff_state($item, $vf_object_uid, $diff_counts),
        ];
    }

    private static function find_manifest_item_by_uid(array $manifest, string $vf_object_uid)
    {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];

        foreach ($items as $item) {
            if (($item['item_type'] ?? '') !== 'post') {
                continue;
            }

            $candidate = isset($item['vf_object_uid'])
                ? (string) $item['vf_object_uid']
                : (isset($item['post_id']) ? (string) $item['post_id'] : '');

            if ($candidate === $vf_object_uid) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Path to docs/fixtures inside the plugin.
     */
    private static function get_fixture_directory()
    {
        return trailingslashit(DBVC_PLUGIN_PATH . 'docs/fixtures');
    }
}
