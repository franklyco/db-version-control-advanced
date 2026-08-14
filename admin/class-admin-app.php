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
    public const DUPLICATE_BULK_CONFIRM_PHRASE = 'DELETE';
    private const MASK_SUPPRESS_OPTION = 'dbvc_masked_field_suppressions';
    private const MASK_OVERRIDES_OPTION = 'dbvc_mask_overrides';
    private const SNAPSHOT_STATES_OPTION = 'dbvc_proposal_snapshot_states';
    private const DECLINED_NEW_ENTITIES_OPTION = 'dbvc_proposal_declined_new_entities';
    private const MASKING_CHUNK_DEFAULT = 10;
    private const PROPOSAL_ZIP_MAX_ENTRIES_DEFAULT = 10000;
    private const PROPOSAL_ZIP_MAX_ENTRY_BYTES_DEFAULT = 268435456;
    private const PROPOSAL_ZIP_MAX_TOTAL_BYTES_DEFAULT = 1073741824;
    private const PROPOSAL_ZIP_MAX_COMPRESSION_RATIO_DEFAULT = 200.0;
    private const DIFF_INLINE_VALUE_BYTES = 5000;
    private const DIFF_MAX_RENDERED_ROWS = 1000;
    private const DIFF_RAW_PREVIEW_BYTES = 20000;
    private const DIFF_RAW_INDEX_ROWS = 1000;

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

    private static $post_apply_fields = [
        'post_title',
        'post_content',
        'post_excerpt',
        'post_status',
        'post_name',
        'post_date',
        'post_modified',
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
        $allowed_hooks = [
            'toplevel_page_dbvc-export',
        ];

        if (! in_array($hook, $allowed_hooks, true)) {
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
                'root'         => esc_url_raw(rest_url('dbvc/v1/')),
                'nonce'        => wp_create_nonce('wp_rest'),
                'initialRoute' => (isset($_GET['dbvc_route']) && $_GET['dbvc_route'] === 'entity-editor') ? 'entity-editor' : 'proposal-review', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                'features'     => [
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
                'args'                => [
                    'proposal_id'      => [
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'page'             => [
                        'required'          => false,
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page'         => [
                        'required'          => false,
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                    ],
                    'include_readiness'=> [
                        'required' => false,
                        'default'  => false,
                    ],
                ],
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
            '/proposals/(?P<proposal_id>[^/]+)/readiness',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_proposal_readiness'],
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
                    'view'          => [
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => static function ($value): bool {
                            return $value === null
                                || $value === ''
                                || in_array($value, ['changed', 'all', 'raw'], true);
                        },
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/proposals/(?P<proposal_id>[^/]+)/entities/(?P<vf_object_uid>[^/]+)/raw/(?P<side>current|proposed)',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'download_proposal_entity_raw'],
                'permission_callback' => [self::class, 'can_manage'],
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
            '/proposals/(?P<proposal_id>[^/]+)/entities/(?P<vf_object_uid>[^/]+)/selections/prune',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'prune_entity_decisions'],
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

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/index',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_entity_editor_index'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/index/rebuild',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'rebuild_entity_editor_index'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/file',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_entity_editor_file'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'path' => [
                        'required' => true,
                    ],
                    'force_takeover' => [
                        'required' => false,
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/file/save',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'save_entity_editor_file'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'path' => [
                        'required' => true,
                    ],
                    'content' => [
                        'required' => true,
                    ],
                    'lock_token' => [
                        'required' => false,
                    ],
                    'force_takeover' => [
                        'required' => false,
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/files/delete',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'delete_entity_editor_files'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/file/import-partial',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'save_and_partial_import_entity_editor_file'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'path' => [
                        'required' => true,
                    ],
                    'content' => [
                        'required' => true,
                    ],
                    'lock_token' => [
                        'required' => false,
                    ],
                    'force_takeover' => [
                        'required' => false,
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/file/import-replace',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'save_and_full_replace_entity_editor_file'],
                'permission_callback' => [self::class, 'can_manage'],
                'args'                => [
                    'path' => [
                        'required' => true,
                    ],
                    'content' => [
                        'required' => true,
                    ],
                    'confirm_phrase' => [
                        'required' => true,
                    ],
                    'lock_token' => [
                        'required' => false,
                    ],
                    'force_takeover' => [
                        'required' => false,
                    ],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/merge-json/preview',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'preview_entity_editor_merge_json'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/merge-json/save',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'save_entity_editor_merge_json'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/merge-json/save-and-partial-import',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'save_and_partial_import_entity_editor_merge_json'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/transfer-preview',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'preview_entity_editor_transfer_packet'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/raw-intake/preview',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'preview_entity_editor_raw_intake'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/raw-intake/commit',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'commit_entity_editor_raw_intake'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/sync-file-import/preview',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'preview_entity_editor_sync_file_import'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/sync-file-import/commit',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'commit_entity_editor_sync_file_import'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/sync-file-import/remediate',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'remediate_entity_editor_sync_file_import'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/third-party/preview',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'preview_entity_editor_third_party_import'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1',
            '/entity-editor/third-party/commit',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'commit_entity_editor_third_party_import'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );
    }

    /**
     * REST: list proposal inventory.
     *
     * Full apply readiness is intentionally opt-in because it reads every
     * proposal entity, masking field, and snapshot. The selected proposal
     * receives authoritative readiness from its detail endpoints.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_proposals(\WP_REST_Request $request)
    {
        $backups = class_exists('DBVC_Backup_Manager') ? DBVC_Backup_Manager::list_backups() : [];
        $proposal_filter = self::sanitize_proposal_id($request->get_param('proposal_id'));
        if ($proposal_filter !== '') {
            $backups = array_values(array_filter($backups, static function ($backup) use ($proposal_filter) {
                return isset($backup['name']) && (string) $backup['name'] === $proposal_filter;
            }));
        }

        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = absint($request->get_param('per_page') ?: 20);
        $per_page = min(100, max(1, $per_page));
        $total_items = count($backups);
        $total_pages = $total_items > 0 ? (int) ceil($total_items / $per_page) : 0;
        if ($total_pages > 0 && $page > $total_pages) {
            $page = $total_pages;
        }
        $backups = array_slice($backups, ($page - 1) * $per_page, $per_page);

        $include_readiness = self::sanitize_boolean($request->get_param('include_readiness'));
        $items = [];
        $decision_store = self::get_decision_store();

        foreach ($backups as $backup) {
            $manifest = isset($backup['manifest']) && is_array($backup['manifest']) ? $backup['manifest'] : null;
            if (! $manifest) {
                continue;
            }

            $proposal_id = $backup['name'];
            $resolver_metrics = null;
            $resolver_result = null;
            if (class_exists('\Dbvc\Media\Resolver')) {
                try {
                    $proposal_path = trailingslashit(DBVC_Backup_Manager::get_base_path(false)) . $proposal_id;
                    $resolver_result  = \Dbvc\Media\Resolver::resolve_manifest($manifest, [
                        'allow_remote' => false,
                        'dry_run'      => true,
                        'proposal_id'  => $proposal_id,
                        'bundle_meta'  => $manifest['media_bundle'] ?? [],
                        'manifest_dir' => $proposal_path,
                    ]);
                    $resolver_metrics = $resolver_result['metrics'] ?? null;
                } catch (\Throwable $e) {
                    $resolver_result = null;
                    $resolver_metrics = null;
                }
            }

            $proposal_decisions = isset($decision_store[$proposal_id]) && is_array($decision_store[$proposal_id])
                ? $decision_store[$proposal_id]
                : [];
            $decision_summary = self::summarize_proposal_decisions($proposal_decisions);
            $transfer_context = self::build_transfer_packet_context($manifest);

            $duplicate_summary = self::build_manifest_duplicate_report($manifest);

            $new_entity_summary = self::summarize_manifest_new_entities($manifest, $proposal_decisions, $proposal_id);
            $bricks_reference_summary = self::build_manifest_bricks_reference_summary($manifest, $proposal_id, false);
            $apply_gates = null;
            if ($include_readiness) {
                $apply_gates = self::build_proposal_apply_gates($proposal_id, $manifest, [
                    'resolver_result'  => $resolver_result,
                    'duplicate_report' => $duplicate_summary,
                    'new_entities'     => $new_entity_summary,
                ]);
            }

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
                'media_bundle'    => $manifest['media_bundle'] ?? null,
                'decisions'       => $decision_summary,
                'status'          => $manifest['status'] ?? 'draft',
                'duplicate_count' => is_array($duplicate_summary) ? count($duplicate_summary) : 0,
                'new_entities'    => $new_entity_summary,
                'origin'          => $transfer_context['origin'],
                'selection'       => $transfer_context['selection'],
                'requirements'    => $transfer_context['requirements'],
                'preflight'       => $transfer_context['preflight'],
                'warnings'        => $transfer_context['warnings'],
                'bricks_references' => $bricks_reference_summary,
                'snapshot_capture'=> $manifest['snapshot_capture'] ?? null,
                'readiness_state' => $include_readiness ? 'complete' : 'deferred',
                'apply_gates'     => $apply_gates,
                'status_counts'   => is_array($apply_gates) ? ($apply_gates['status_counts'] ?? null) : null,
            ];
        }

        return new \WP_REST_Response([
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
            ],
            'readiness'  => [
                'included' => $include_readiness,
                'mode'     => $include_readiness ? 'full' : 'deferred',
            ],
        ]);
    }

    /**
     * REST: return the current proposal apply readiness contract.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function get_proposal_readiness(\WP_REST_Request $request)
    {
        $proposal_id = self::sanitize_proposal_id($request->get_param('proposal_id'));
        if ($proposal_id === '') {
            return new \WP_Error('dbvc_missing_proposal', __('Proposal ID is required.', 'dbvc'), ['status' => 400]);
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_Error('dbvc_manifest_missing', __('Proposal manifest could not be found.', 'dbvc'), ['status' => 404]);
        }

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'apply_gates' => self::build_proposal_apply_gates($proposal_id, $manifest, [
                'ignore_missing_hash' => self::sanitize_boolean($request->get_param('ignore_missing_hash')),
            ]),
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
        $original_name = sanitize_file_name((string) ($file['name'] ?? basename($zip_path)));
        $preferred_id = self::sanitize_proposal_id($request->get_param('proposal_id'));
        $overwrite = $request->get_param('overwrite');
        if (function_exists('rest_sanitize_boolean')) {
            $overwrite = rest_sanitize_boolean($overwrite);
        } else {
            $overwrite = in_array($overwrite, [true, 1, '1', 'true', 'on'], true);
        }

        $ai_result = self::maybe_stage_ai_package_upload($zip_path, $original_name);
        if (is_array($ai_result)) {
            @unlink($zip_path);
            return new \WP_REST_Response($ai_result);
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
     * Detect AI submission packages uploaded through the proposal uploader and route
     * them into the classic AI intake review flow instead of registering them as proposals.
     *
     * @param string $zip_path
     * @param string $original_name
     * @return array<string,mixed>|null
     */
    private static function maybe_stage_ai_package_upload(string $zip_path, string $original_name): ?array
    {
        if (
            ! class_exists('\Dbvc\AiPackage\SubmissionPackageDetector')
            || ! class_exists('\Dbvc\AiPackage\SubmissionPackageValidator')
        ) {
            return null;
        }

        $inspection = \Dbvc\AiPackage\SubmissionPackageDetector::inspect_uploaded_zip($zip_path);
        if (! is_array($inspection) || empty($inspection['detected'])) {
            return null;
        }

        delete_option('dbvc_sync_upload_report');
        delete_option('dbvc_ai_upload_report');

        $ai_report = \Dbvc\AiPackage\SubmissionPackageValidator::intake_uploaded_zip([
            'name'     => $original_name !== '' ? $original_name : basename($zip_path),
            'tmp_name' => $zip_path,
            'type'     => 'application/zip',
            'error'    => 0,
            'size'     => file_exists($zip_path) ? (int) filesize($zip_path) : 0,
        ]);

        if (is_wp_error($ai_report)) {
            $ai_report = [
                'mode' => 'ai_package',
                'generated_at' => current_time('mysql'),
                'status' => 'blocked',
                'package_type' => '',
                'package_schema_version' => null,
                'counts' => [
                    'post_entities' => 0,
                    'term_entities' => 0,
                    'issues' => 1,
                    'warnings' => 0,
                    'blocked' => 1,
                ],
                'issues' => [
                    [
                        'severity' => 'error',
                        'code' => 'intake_failed',
                        'message' => $ai_report->get_error_message(),
                        'path' => '',
                    ],
                ],
                'entities' => [],
                'artifacts' => [
                    'source_archive' => '',
                    'extracted_root' => '',
                    'validation_report' => '',
                    'validation_summary' => '',
                    'translation_manifest' => null,
                    'translated_sync_root' => null,
                ],
            ];
        }

        update_option('dbvc_ai_upload_report', $ai_report, false);

        $state = (($ai_report['status'] ?? 'blocked') === 'blocked')
            ? 'ai_blocked'
            : 'ai_review';

        $redirect_url = 'admin.php?page=dbvc-export&dbvc_upload=' . rawurlencode($state) . '#dbvc-ai-review-workbench';

        return [
            'mode' => 'ai_package',
            'status' => $state,
            'redirect_url' => $redirect_url,
            'intake_id' => (string) ($ai_report['intake_id'] ?? ''),
            'report_status' => (string) ($ai_report['status'] ?? 'blocked'),
            'counts' => is_array($ai_report['counts'] ?? null) ? $ai_report['counts'] : [],
            'message' => $state === 'ai_blocked'
                ? __('AI package intake was blocked. Opening the retained review report.', 'dbvc')
                : __('AI package staged for intake review. Opening the review screen.', 'dbvc'),
        ];
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

        $media_bundle_deleted = true;
        if (class_exists('\\Dbvc\\Media\\BundleManager')) {
            $media_bundle_deleted = \Dbvc\Media\BundleManager::delete_bundle($proposal_id);
            if (! $media_bundle_deleted) {
                $cleanup_context = [
                    'proposal' => $proposal_id,
                    'storage'  => \Dbvc\Media\BundleManager::get_storage_relative_path($proposal_id),
                ];
                if (class_exists('DBVC_Sync_Logger') && method_exists('DBVC_Sync_Logger', 'log_media')) {
                    DBVC_Sync_Logger::log_media('Proposal media bundle cleanup failed', $cleanup_context);
                }
                if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
                    DBVC_Database::log_activity(
                        'proposal_bundle_cleanup_failed',
                        'warning',
                        'Proposal deleted, but its media bundle could not be removed.',
                        $cleanup_context
                    );
                }
            }
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
        self::clear_declined_new_proposal($proposal_id);

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

        self::clear_snapshot_state_entry($proposal_id);

        return new \WP_REST_Response([
            'deleted'              => true,
            'proposal_id'          => $proposal_id,
            'media_bundle_deleted' => $media_bundle_deleted,
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

        $validation = self::validate_proposal_zip($zip, $zip_path);
        if (is_wp_error($validation)) {
            $zip->close();
            self::delete_directory_recursive($temp_dir);
            return $validation;
        }

        $extracted = $zip->extractTo($temp_dir);
        $zip->close();

        if (! $extracted) {
            self::delete_directory_recursive($temp_dir);
            return new \WP_Error('dbvc_zip_extract_failed', __('Failed to extract the uploaded archive.', 'dbvc'), ['status' => 400]);
        }

        $manifest_path = trailingslashit($temp_dir) . $validation['manifest_entry'];
        if (! $manifest_path || ! file_exists($manifest_path)) {
            self::delete_directory_recursive($temp_dir);
            return new \WP_Error('dbvc_manifest_missing', __('The uploaded bundle is missing manifest.json.', 'dbvc'), ['status' => 400]);
        }

        $manifest = $validation['manifest'];

        $duplicates = self::build_manifest_duplicate_report($manifest);
        if (! empty($duplicates)) {
            self::delete_directory_recursive($temp_dir);
            $messages = array_map(static function ($dup) {
                $label = (string) ($dup['vf_object_uid'] ?? $dup['duplicate_id'] ?? __('unknown entity', 'dbvc'));
                $paths = array_filter(array_column((array) ($dup['entries'] ?? []), 'path'));
                return sprintf(
                    __('%1$s entity %2$s has multiple payloads (paths: %3$s)', 'dbvc'),
                    (string) ($dup['entity_type'] ?? 'manifest'),
                    $label,
                    implode(', ', $paths)
                );
            }, $duplicates);
            return new \WP_Error('dbvc_manifest_duplicates', implode("\n", $messages), [
                'status'     => 400,
                'duplicates' => [
                    'count' => count($duplicates),
                    'items' => $duplicates,
                ],
            ]);
        }

        $bundle_root = dirname($manifest_path);
        $validation = self::validate_import_bundle_manifest($manifest, $bundle_root);
        if (is_wp_error($validation)) {
            self::delete_directory_recursive($temp_dir);
            return $validation;
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
        self::clear_snapshot_state_entry($proposal_id);
        self::clear_declined_new_proposal($proposal_id);
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

        $snapshot_capture = null;
        if (is_array($manifest_for_site)) {
            try {
                $snapshot_capture = self::recapture_proposal_snapshots($proposal_id, $manifest_for_site);
            } catch (\Throwable $e) {
                $snapshot_capture = [
                    'proposal_id'  => $proposal_id,
                    'targets'      => 0,
                    'captured'     => 0,
                    'failed'       => 1,
                    'not_required' => 0,
                    'skipped'      => 0,
                    'results'      => [[
                        'state'   => 'failed',
                        'code'    => 'capture_exception',
                        'message' => sanitize_text_field($e->getMessage()),
                    ]],
                ];
                self::log_snapshot_capture_result($snapshot_capture);
            }

            $failed_entities = [];
            foreach ((array) ($snapshot_capture['results'] ?? []) as $capture_item) {
                if (! is_array($capture_item) || ($capture_item['state'] ?? '') !== 'failed') {
                    continue;
                }
                $failed_entities[] = [
                    'vf_object_uid' => sanitize_text_field((string) ($capture_item['vf_object_uid'] ?? '')),
                    'code'          => sanitize_key((string) ($capture_item['code'] ?? 'capture_failed')),
                    'message'       => sanitize_text_field((string) ($capture_item['message'] ?? __('Snapshot capture failed.', 'dbvc'))),
                ];
            }
            $capture_failed = isset($snapshot_capture['failed']) ? (int) $snapshot_capture['failed'] : 0;
            $capture_count = isset($snapshot_capture['captured']) ? (int) $snapshot_capture['captured'] : 0;
            $manifest_for_site['snapshot_capture'] = [
                'status'          => $capture_failed > 0 ? ($capture_count > 0 ? 'partial' : 'failed') : 'complete',
                'attempted_at'    => current_time('mysql', true),
                'targets'         => isset($snapshot_capture['targets']) ? (int) $snapshot_capture['targets'] : 0,
                'captured'        => $capture_count,
                'failed'          => $capture_failed,
                'not_required'    => isset($snapshot_capture['not_required']) ? (int) $snapshot_capture['not_required'] : 0,
                'failed_entities' => $failed_entities,
            ];
            file_put_contents(
                $target_manifest_path,
                wp_json_encode($manifest_for_site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        self::delete_directory_recursive($temp_dir);

        return [
            'proposal_id'      => $proposal_id,
            'manifest'         => $manifest_for_site,
            'snapshot_capture' => $snapshot_capture,
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
        $transfer_context = self::build_transfer_packet_context($manifest);
        $bricks_reference_summary = self::build_manifest_bricks_reference_summary($manifest, $proposal_id, true);
        $bricks_reference_entities = isset($bricks_reference_summary['entities']) && is_array($bricks_reference_summary['entities'])
            ? $bricks_reference_summary['entities']
            : [];
        $duplicate_groups = self::detect_manifest_duplicate_groups($manifest);
        $duplicate_group_keys = [];
        foreach ($duplicate_groups as $duplicate_group) {
            if (! empty($duplicate_group['_identity_key'])) {
                $duplicate_group_keys[(string) $duplicate_group['_identity_key']] = 1;
            }
        }
        $new_entity_summary = self::summarize_manifest_new_entities($manifest, $proposal_decisions, $proposal_id);
        $masking_readiness = self::summarize_masking_apply_readiness(
            $proposal_id,
            $manifest,
            $proposal_decisions
        );
        $field_decision_readiness = self::summarize_field_decision_apply_readiness(
            $proposal_id,
            $manifest,
            $proposal_decisions,
            $masking_readiness['pending_paths']
        );
        $apply_gates = self::build_proposal_apply_gates($proposal_id, $manifest, [
            'resolver_result'  => $resolver_result,
            'duplicate_report' => $duplicate_groups,
            'new_entities'     => $new_entity_summary,
            'masking'          => $masking_readiness,
            'field_decisions'  => $field_decision_readiness,
        ]);

        $items = [];

        foreach ($manifest['items'] as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if ($item_type !== 'post') {
                if ($item_type === 'term') {
                    $term_entry = self::format_term_manifest_entity(
                        $proposal_id,
                        $item,
                        $status_filter,
                        $proposal_decisions,
                        $field_decision_readiness,
                        $masking_readiness,
                        $duplicate_group_keys
                    );
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

            $vf_object_uid = self::get_manifest_item_uid($item);

            $identity    = self::describe_entity_identity($item);
            $is_new_entity = $identity['is_new'];
            $identity_match = $identity['match_source'];
            $entity_decisions = ($vf_object_uid !== '' && isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid]))
                ? $proposal_decisions[$vf_object_uid]
                : [];
            $decision_summary = self::summarize_entity_decisions($entity_decisions);
            $new_entity_decision = self::get_new_entity_decision($proposal_id, $vf_object_uid, $entity_decisions);
            $new_entity_state = $is_new_entity
                ? self::normalize_new_entity_state($new_entity_decision)
                : '';

            $diff_counts = self::summarize_entity_diff_counts($proposal_id, $item, $vf_object_uid);
            $snapshot_status = isset($diff_counts['snapshot_status']) && is_array($diff_counts['snapshot_status'])
                ? $diff_counts['snapshot_status']
                : self::get_entity_snapshot_status($proposal_id, $item, $identity);
            $diff_state = self::evaluate_entity_diff_state($item, $vf_object_uid, $diff_counts, $identity);
            $status_counts = self::build_entity_status_counts(
                $field_decision_readiness['by_entity'][$vf_object_uid] ?? [],
                $masking_readiness['by_entity'][$vf_object_uid] ?? [],
                $attachments,
                self::count_duplicate_groups_for_item($item, $duplicate_group_keys),
                $is_new_entity && $new_entity_state === 'pending_new'
            );
            $media_needs_review = $status_counts['media_needs_review'] > 0;
            $needs_review = self::entity_status_requires_review(
                $status_counts,
                $snapshot_status,
                $diff_state
            );
            $diff_state['needs_review'] = $needs_review;

            if (! self::entity_matches_status_filter(
                $status_filter,
                $status_counts,
                $needs_review,
                $is_new_entity,
                $snapshot_status
            )) {
                continue;
            }

            $entity_bricks_references = isset($bricks_reference_entities[$vf_object_uid]) && is_array($bricks_reference_entities[$vf_object_uid])
                ? $bricks_reference_entities[$vf_object_uid]
                : self::empty_bricks_reference_summary(false);

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
                'snapshot_state'=> $snapshot_status['state'] ?? 'failed',
                'snapshot_status'=> $snapshot_status,
                'diff_total'    => $diff_counts['total'],
                'meta_diff_count' => $diff_counts['meta'] ?? 0,
                'tax_diff_count'  => $diff_counts['tax'] ?? 0,
                'media_needs_review' => $media_needs_review,
                'status_counts'  => $status_counts,
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
                'new_entity_state'   => $new_entity_state,
                'decision_summary' => $decision_summary,
                'bricks_references' => $entity_bricks_references,
            ];
        }

        return new \WP_REST_Response([
            'proposal_id'        => $proposal_id,
            'items'              => $items,
            'resolver'           => [
                'metrics' => $resolver_result['metrics'] ?? [],
            ],
            'decision_summary'   => self::summarize_proposal_decisions($proposal_decisions),
            'resolver_decisions' => self::summarize_resolver_decisions($proposal_id),
            'origin'             => $transfer_context['origin'],
            'selection'          => $transfer_context['selection'],
            'requirements'       => $transfer_context['requirements'],
            'preflight'          => $transfer_context['preflight'],
            'warnings'           => $transfer_context['warnings'],
            'bricks_references'  => $bricks_reference_summary,
            'status_counts'      => $apply_gates['status_counts'],
            'apply_gates'        => $apply_gates,
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
        $body_params = $request->get_body_params();
        $json_params = $request->get_json_params();
        $params = array_merge(
            is_array($body_params) ? $body_params : [],
            is_array($json_params) ? $json_params : []
        );
        $duplicate_id = isset($params['duplicate_id']) ? sanitize_text_field($params['duplicate_id']) : '';
        $vf_object_uid = isset($params['vf_object_uid']) ? sanitize_text_field($params['vf_object_uid']) : '';
        $keep_entry_id = isset($params['keep_entry_id']) ? sanitize_text_field($params['keep_entry_id']) : '';
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
        } elseif (
            ($duplicate_id === '' && $vf_object_uid === '')
            || ($keep_entry_id === '' && $keep_path === '')
        ) {
            return new \WP_Error(
                'dbvc_invalid_request',
                __('Specify the duplicate group and canonical manifest entry.', 'dbvc'),
                ['status' => 400]
            );
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $items  = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $groups = self::detect_manifest_duplicate_groups($manifest);

        if ($apply_all) {
            if (empty($groups)) {
                return new \WP_Error('dbvc_no_duplicates', __('No duplicate entries were found for this proposal.', 'dbvc'), ['status' => 400]);
            }
        } else {
            if ($duplicate_id !== '') {
                $groups = array_values(array_filter($groups, static function ($group) use ($duplicate_id) {
                    return ($group['duplicate_id'] ?? '') === $duplicate_id;
                }));
            } else {
                $groups = array_values(array_filter($groups, static function ($group) use ($vf_object_uid) {
                    return ($group['vf_object_uid'] ?? '') === $vf_object_uid;
                }));
                if (count($groups) > 1) {
                    return new \WP_Error(
                        'dbvc_duplicate_identity_ambiguous',
                        __('This identity matches more than one duplicate group. Refresh the report and use its duplicate ID.', 'dbvc'),
                        [
                            'status'        => 409,
                            'duplicate_ids' => array_values(array_filter(array_column($groups, 'duplicate_id'))),
                        ]
                    );
                }
            }

            if (count($groups) !== 1) {
                return new \WP_Error('dbvc_no_duplicates', __('No duplicate entries were found for this entity.', 'dbvc'), ['status' => 400]);
            }

            $matching_entries = array_values(array_filter($groups[0]['entries'], static function ($entry) use ($keep_entry_id, $keep_path) {
                if ($keep_entry_id !== '') {
                    return ($entry['entry_id'] ?? '') === $keep_entry_id;
                }
                return ($entry['path'] ?? '') === $keep_path;
            }));
            if (empty($matching_entries)) {
                return new \WP_Error('dbvc_keep_missing', __('Canonical manifest entry was not found among duplicates.', 'dbvc'), ['status' => 400]);
            }
            if (count($matching_entries) > 1) {
                return new \WP_Error(
                    'dbvc_keep_ambiguous',
                    __('More than one duplicate entry uses that path. Refresh the report and use its entry ID.', 'dbvc'),
                    ['status' => 409]
                );
            }
            $keep_entry_id = (string) $matching_entries[0]['entry_id'];
        }

        if (! class_exists('DBVC_Backup_Manager')) {
            return new \WP_Error('dbvc_missing_manager', __('Backup manager is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $base_dir = trailingslashit(DBVC_Backup_Manager::get_base_path(false)) . $proposal_id;
        $base_real = realpath($base_dir);
        if ($base_real === false || ! is_dir($base_real)) {
            return new \WP_Error('dbvc_missing_proposal_dir', __('Proposal directory not found.', 'dbvc'), ['status' => 500]);
        }

        $remove_indexes = [];
        $kept_entries = [];
        foreach ($groups as $group) {
            $entries = array_values($group['entries']);
            $canonical_entry = $apply_all
                ? self::determine_duplicate_keep_entry($entries, $preferred_format)
                : null;
            if (! $apply_all) {
                foreach ($entries as $entry) {
                    if (($entry['entry_id'] ?? '') === $keep_entry_id) {
                        $canonical_entry = $entry;
                        break;
                    }
                }
            }
            if (! is_array($canonical_entry)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (($entry['entry_id'] ?? '') === ($canonical_entry['entry_id'] ?? '')) {
                    continue;
                }
                if (isset($entry['_manifest_index'])) {
                    $remove_indexes[(int) $entry['_manifest_index']] = true;
                }
            }
            $kept_entries[] = [
                'duplicate_id' => $group['duplicate_id'] ?? '',
                'entry_id'     => $canonical_entry['entry_id'] ?? '',
                'path'         => $canonical_entry['path'] ?? '',
            ];
        }

        if (empty($remove_indexes)) {
            return new \WP_Error('dbvc_no_duplicates', __('No duplicate entries were selected for removal.', 'dbvc'), ['status' => 400]);
        }

        $removed_entries = [];
        $remaining_items = [];
        foreach ($items as $index => $item) {
            if (isset($remove_indexes[(int) $index])) {
                $removed_entries[] = $item;
            } else {
                $remaining_items[] = $item;
            }
        }

        $remaining_paths = [];
        foreach ($remaining_items as $item) {
            $path = isset($item['path']) ? ltrim((string) $item['path'], '/\\') : '';
            if ($path !== '') {
                $remaining_paths[$path] = true;
            }
        }

        $paths_to_remove = [];
        foreach ($removed_entries as $item) {
            $path = isset($item['path']) ? ltrim((string) $item['path'], '/\\') : '';
            if ($path !== '' && ! isset($remaining_paths[$path])) {
                $paths_to_remove[$path] = true;
            }
        }

        $manifest['items'] = array_values($remaining_items);
        if (! isset($manifest['totals']) || ! is_array($manifest['totals'])) {
            $manifest['totals'] = [];
        }
        $manifest['totals']['files'] = count($manifest['items']);

        $transaction = self::commit_duplicate_cleanup_transaction(
            $base_real,
            $manifest,
            array_keys($paths_to_remove)
        );
        if (is_wp_error($transaction)) {
            return $transaction;
        }

        $updated_report = self::build_manifest_duplicate_report($manifest);

        return new \WP_REST_Response([
            'proposal_id' => $proposal_id,
            'count'       => count($updated_report),
            'items'       => $updated_report,
            'removed'     => count($removed_entries),
            'removed_files' => (int) ($transaction['removed_files'] ?? 0),
            'kept'        => $kept_entries,
            'warnings'    => $transaction['warnings'] ?? [],
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

        $snapshot_status = is_array($diff_counts) && isset($diff_counts['snapshot_status']) && is_array($diff_counts['snapshot_status'])
            ? $diff_counts['snapshot_status']
            : null;
        if ($snapshot_status && ! empty($snapshot_status['required']) && empty($snapshot_status['trusted'])) {
            $needs_review = true;
            $reason = 'snapshot_' . sanitize_key((string) ($snapshot_status['state'] ?? 'failed'));
        } elseif ($snapshot_status && ($snapshot_status['state'] ?? '') === 'not_required' && ! empty($identity['is_new'])) {
            $needs_review = true;
            $reason = 'new_entity';
        }

        return [
            'needs_review' => $needs_review,
            'reason'       => $reason,
            'expected_hash'=> $expected_hash,
            'current_hash' => $current_hash,
            'local_post_id'=> $local_post_id,
            'diff_total'   => $diff_total,
            'identity_match' => $identity_match ?: ($local_post_id ? 'id' : 'none'),
            'snapshot_state' => $snapshot_status['state'] ?? null,
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
        $requested_view = sanitize_key((string) $request->get_param('view'));
        $view_mode = in_array($requested_view, ['changed', 'all', 'raw'], true)
            ? $requested_view
            : 'changed';
        $legacy_payloads = $requested_view === '';

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_REST_Response(null, 404);
        }

        $entity = null;
        $current_path = null;
        $proposed = null;
        foreach ($manifest['items'] as $item) {
            $entity_uid = self::get_manifest_item_uid($item);
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

        $identity = self::describe_entity_identity($entity);
        $snapshot_status = self::get_entity_snapshot_status($proposal_id, $entity, $identity);
        $snapshot_state = isset($snapshot_status['state']) ? (string) $snapshot_status['state'] : 'failed';
        $current_source = $snapshot_state === 'available' ? 'snapshot' : $snapshot_state;
        $current = [];
        if (! empty($snapshot_status['trusted']) && class_exists('DBVC_Snapshot_Manager')) {
            $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
            if (is_array($snapshot) && ! empty($snapshot)) {
                $current = $snapshot;
            }
        }

        $raw_base = rest_url(
            'dbvc/v1/proposals/'
            . rawurlencode($proposal_id)
            . '/entities/'
            . rawurlencode($vf_object_uid)
            . '/raw/'
        );
        $raw_downloads = [
            'current'  => ! empty($snapshot_status['trusted']) ? $raw_base . 'current' : null,
            'proposed' => $raw_base . 'proposed',
        ];

        $canonical_diff_summary = ! empty($snapshot_status['trusted']) && ! empty($current)
            ? array_merge(self::compare_snapshots($current, $proposed_data), [
                'available' => true,
                'reason'    => null,
            ])
            : array_merge(self::empty_diff_summary(), [
                'available' => false,
                'reason'    => $snapshot_state === 'not_required' ? 'new_entity' : 'snapshot_' . $snapshot_state,
            ]);
        $diff_summary = $canonical_diff_summary;
        if (
            $view_mode === 'all'
            && ! empty($canonical_diff_summary['available'])
        ) {
            $diff_summary = array_merge(
                self::compare_snapshots($current, $proposed_data, ['include_unchanged' => true]),
                [
                    'available' => true,
                    'reason'    => null,
                ]
            );
        } elseif ($view_mode === 'raw') {
            $raw_apply_paths = isset($diff_summary['apply_paths']) && is_array($diff_summary['apply_paths'])
                ? $diff_summary['apply_paths']
                : [];
            $diff_summary['changes'] = [];
            $diff_summary['displayed_total'] = 0;
            $diff_summary['omitted_total'] = (int) ($diff_summary['total'] ?? 0);
            $diff_summary['truncated'] = $diff_summary['omitted_total'] > 0;
            $diff_summary['apply_paths_total'] = count($raw_apply_paths);
            $diff_summary['apply_paths'] = array_slice($raw_apply_paths, 0, self::DIFF_RAW_INDEX_ROWS);
            $diff_summary['apply_paths_omitted'] = max(
                0,
                $diff_summary['apply_paths_total'] - count($diff_summary['apply_paths'])
            );
        }
        $diff_summary['raw_downloads'] = $raw_downloads;
        $diff_paths = isset($canonical_diff_summary['apply_paths']) && is_array($canonical_diff_summary['apply_paths'])
            ? $canonical_diff_summary['apply_paths']
            : [];
        if (empty($diff_paths) && ! empty($canonical_diff_summary['changes'])) {
            foreach ($canonical_diff_summary['changes'] as $change) {
                $apply_path = ! empty($change['can_apply']) && isset($change['apply_path'])
                    ? (string) $change['apply_path']
                    : '';
                if ($apply_path !== '') {
                    $diff_paths[] = $apply_path;
                }
            }
        }
        $decisions = self::get_entity_decisions($proposal_id, $vf_object_uid);
        $decision_pruning = [
            'performed'    => false,
            'source'       => $current_source,
            'reason'       => $snapshot_state === 'not_required'
                ? 'not_applicable_new_entity'
                : 'untrusted_snapshot',
            'before_count' => count($decisions),
            'after_count'  => count($decisions),
            'pruned_count' => 0,
        ];
        $warnings = [];
        $can_prune_decisions = ! empty($snapshot_status['trusted'])
            && ! empty($canonical_diff_summary['available']);
        if ($can_prune_decisions) {
            $decision_pruning = [
                'performed'    => false,
                'source'       => 'snapshot',
                'reason'       => 'explicit_action_required',
                'before_count' => $decision_pruning['before_count'],
                'after_count'  => $decision_pruning['before_count'],
                'pruned_count' => 0,
                'eligible'     => true,
            ];
        } elseif ($snapshot_state !== 'not_required') {
            $decision_pruning['reason'] = ! empty($snapshot_status['trusted'])
                ? 'authoritative_diff_unavailable'
                : 'untrusted_snapshot';
            $warnings[] = [
                'code'    => 'dbvc_decisions_preserved_untrusted_baseline',
                'message' => __('Stored review decisions were preserved because an authoritative current-state snapshot was unavailable.', 'dbvc'),
            ];
        }
        $meta_changes = 0;
        $tax_changes  = 0;
        if (
            isset($canonical_diff_summary['section_counts'])
            && is_array($canonical_diff_summary['section_counts'])
        ) {
            $meta_changes = (int) ($canonical_diff_summary['section_counts']['meta'] ?? 0);
            $tax_changes = (int) ($canonical_diff_summary['section_counts']['tax'] ?? 0);
        } else {
            foreach ($canonical_diff_summary['changes'] as $change) {
                $section = $change['section'] ?? '';
                if ($section === 'meta') {
                    $meta_changes++;
                } elseif ($section === 'tax') {
                    $tax_changes++;
                }
            }
        }
        $diff_counts = [
            'total'           => isset($canonical_diff_summary['actionable_total'])
                ? (int) $canonical_diff_summary['actionable_total']
                : 0,
            'display_total'   => isset($canonical_diff_summary['total'])
                ? (int) $canonical_diff_summary['total']
                : 0,
            'meta'            => $meta_changes,
            'tax'             => $tax_changes,
            'diff_available'  => ! empty($canonical_diff_summary['available']),
            'snapshot_state'  => $snapshot_state,
            'snapshot_status' => $snapshot_status,
        ];
        $diff_state   = self::evaluate_entity_diff_state($entity, $vf_object_uid, $diff_counts, $identity);
        foreach ($diff_summary['changes'] as &$change) {
            $decision_path = ! empty($change['can_apply']) && ! empty($change['apply_path'])
                ? (string) $change['apply_path']
                : '';
            $change['decision'] = $decision_path !== '' && isset($decisions[$decision_path])
                ? $decisions[$decision_path]
                : null;
            if (! empty($change['render_hint']['truncated'])) {
                $change['render_hint']['raw_downloads'] = $raw_downloads;
            }
        }
        unset($change);
        $raw_view = $view_mode === 'raw'
            ? self::build_raw_diff_view(
                $current,
                $proposed_data,
                ! empty($snapshot_status['trusted']) && ! empty($current),
                $canonical_diff_summary,
                $raw_downloads,
                $canonical_diff_summary['reason'] ?? null,
                $decisions
            )
            : null;
        $new_entity_decision = self::get_new_entity_decision($proposal_id, $vf_object_uid, $decisions);
        $new_entity_state = ! empty($identity['is_new'])
            ? self::normalize_new_entity_state($new_entity_decision)
            : '';
        if (! empty($identity['is_new'])) {
            $diff_state['needs_review'] = $new_entity_state === 'pending_new';
        }

        return new \WP_REST_Response([
            'proposal_id'   => $proposal_id,
            'vf_object_uid' => $vf_object_uid,
            'item'          => $entity,
            'diff'          => $diff_summary,
            'view'          => [
                'mode'                     => $view_mode,
                'available_modes'          => ['changed', 'all', 'raw'],
                'legacy_payloads_included' => $legacy_payloads,
            ],
            'raw_view'      => $raw_view,
            'current'       => $legacy_payloads ? $current : self::build_entity_view_context($current),
            'current_source'=> $current_source,
            'proposed'      => $legacy_payloads ? $proposed_data : self::build_entity_view_context($proposed_data),
            'raw_downloads' => $raw_downloads,
            'snapshot_state'=> $snapshot_state,
            'snapshot_status'=> $snapshot_status,
            'decision_pruning'=> $decision_pruning,
            'warnings'         => $warnings,
            'diff_state'    => $diff_state,
            'decisions'     => $decisions,
            'decision_summary' => self::summarize_entity_decisions($decisions),
            'is_new_entity'     => $identity['is_new'],
            'identity_match'    => $identity['match_source'],
            'new_entity_decision'=> $new_entity_decision,
            'new_entity_state'   => $new_entity_state,
        ]);
    }

    /**
     * REST: explicitly remove stale review decisions after a trusted snapshot diff.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function prune_entity_decisions(\WP_REST_Request $request)
    {
        $proposal_id   = sanitize_text_field($request->get_param('proposal_id'));
        $vf_object_uid = sanitize_text_field($request->get_param('vf_object_uid'));
        $manifest      = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_Error('dbvc_manifest_missing', __('Proposal manifest could not be found.', 'dbvc'), ['status' => 404]);
        }

        $entity = null;
        foreach ((array) ($manifest['items'] ?? []) as $item) {
            if (is_array($item) && self::get_manifest_item_uid($item) === $vf_object_uid) {
                $entity = $item;
                break;
            }
        }
        if (! is_array($entity)) {
            return new \WP_Error('dbvc_invalid_entity', __('Entity is not part of this proposal.', 'dbvc'), ['status' => 404]);
        }

        $identity = ($entity['item_type'] ?? '') === 'term'
            ? self::describe_term_identity($entity)
            : self::describe_entity_identity($entity);
        $snapshot_status = self::get_entity_snapshot_status($proposal_id, $entity, $identity);
        $before = self::get_entity_decisions($proposal_id, $vf_object_uid);
        if (empty($snapshot_status['trusted'])) {
            return new \WP_Error(
                'dbvc_decision_pruning_unavailable',
                __('Stale decisions can be pruned only after a trusted current-state snapshot is available.', 'dbvc'),
                [
                    'status'           => 409,
                    'proposal_id'      => $proposal_id,
                    'vf_object_uid'    => $vf_object_uid,
                    'decision_pruning' => [
                        'performed'    => false,
                        'source'       => (string) ($snapshot_status['state'] ?? 'failed'),
                        'reason'       => 'untrusted_snapshot',
                        'before_count' => count($before),
                        'after_count'  => count($before),
                        'pruned_count' => 0,
                    ],
                ]
            );
        }

        $current_path = isset($entity['path']) ? (string) $entity['path'] : '';
        $proposed = [];
        if ($current_path !== '') {
            $payload = self::read_entity_payload($proposal_id, $current_path);
            if (is_array($payload)) {
                $proposed = $payload;
            }
        }
        $snapshot = class_exists('DBVC_Snapshot_Manager')
            ? DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid)
            : null;
        if (! is_array($snapshot) || empty($snapshot)) {
            return new \WP_Error(
                'dbvc_decision_pruning_unavailable',
                __('Stale decisions can be pruned only when the trusted snapshot can still produce an authoritative diff.', 'dbvc'),
                [
                    'status'           => 409,
                    'proposal_id'      => $proposal_id,
                    'vf_object_uid'    => $vf_object_uid,
                    'decision_pruning' => [
                        'performed'    => false,
                        'source'       => 'snapshot',
                        'reason'       => 'authoritative_diff_unavailable',
                        'before_count' => count($before),
                        'after_count'  => count($before),
                        'pruned_count' => 0,
                    ],
                ]
            );
        }

        $diff_summary = self::compare_snapshots($snapshot, $proposed);
        $paths = array_merge(
            isset($diff_summary['apply_paths']) && is_array($diff_summary['apply_paths'])
                ? $diff_summary['apply_paths']
                : [],
            self::resolve_entity_masking_decision_paths($proposal_id, $vf_object_uid, $entity)
        );
        self::prune_entity_decisions_for_paths($proposal_id, $vf_object_uid, $paths);
        $after = self::get_entity_decisions($proposal_id, $vf_object_uid);
        $store = self::get_decision_store();
        $proposal_store = isset($store[$proposal_id]) && is_array($store[$proposal_id]) ? $store[$proposal_id] : [];

        return new \WP_REST_Response([
            'proposal_id'      => $proposal_id,
            'vf_object_uid'    => $vf_object_uid,
            'decisions'        => $after,
            'summary'          => self::summarize_entity_decisions($after),
            'proposal_summary' => self::summarize_proposal_decisions($proposal_store),
            'decision_pruning' => [
                'performed'    => count($before) !== count($after),
                'source'       => 'snapshot',
                'reason'       => 'trusted_snapshot',
                'before_count' => count($before),
                'after_count'  => count($after),
                'pruned_count' => max(0, count($before) - count($after)),
            ],
        ]);
    }

    /**
     * REST: download one unbounded side of an entity comparison as JSON.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function download_proposal_entity_raw(\WP_REST_Request $request)
    {
        $proposal_id = self::sanitize_proposal_id($request->get_param('proposal_id'));
        $vf_object_uid = sanitize_text_field($request->get_param('vf_object_uid'));
        $side = sanitize_key($request->get_param('side'));
        if ($proposal_id === '' || $vf_object_uid === '' || ! in_array($side, ['current', 'proposed'], true)) {
            return new \WP_Error(
                'dbvc_invalid_raw_diff_request',
                __('A proposal, entity, and valid diff side are required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest || empty($manifest['items']) || ! is_array($manifest['items'])) {
            return new \WP_Error(
                'dbvc_manifest_missing',
                __('Proposal manifest could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $entity = null;
        foreach ($manifest['items'] as $item) {
            if (is_array($item) && self::get_manifest_item_uid($item) === $vf_object_uid) {
                $entity = $item;
                break;
            }
        }
        if (! $entity) {
            return new \WP_Error(
                'dbvc_entity_missing',
                __('Proposal entity could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        if ($side === 'proposed') {
            $path = isset($entity['path']) ? (string) $entity['path'] : '';
            $payload = $path !== '' ? self::read_entity_payload($proposal_id, $path) : null;
        } else {
            $item_type = isset($entity['item_type']) ? (string) $entity['item_type'] : 'post';
            $identity = $item_type === 'term'
                ? self::describe_term_identity($entity)
                : self::describe_entity_identity($entity);
            $snapshot_status = self::get_entity_snapshot_status($proposal_id, $entity, $identity);
            if (empty($snapshot_status['trusted']) || ! class_exists('DBVC_Snapshot_Manager')) {
                return new \WP_Error(
                    'dbvc_snapshot_untrusted',
                    __('A trusted current-site snapshot is not available for this entity.', 'dbvc'),
                    ['status' => 409]
                );
            }
            $payload = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
        }

        if (! is_array($payload)) {
            return new \WP_Error(
                'dbvc_raw_diff_missing',
                __('The requested raw diff payload could not be read.', 'dbvc'),
                ['status' => 404]
            );
        }

        $filename = sanitize_file_name($proposal_id . '-' . $vf_object_uid . '-' . $side . '.json');
        $response = new \WP_REST_Response($payload);
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('X-Content-Type-Options', 'nosniff');

        return $response;
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
                'manifest_dir' => trailingslashit(DBVC_Backup_Manager::get_base_path(false)) . $proposal_id,
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
     * Sanitize Entity Editor bulk path payloads from REST requests.
     *
     * @param mixed $paths
     * @return array<int,string>
     */
    private static function sanitize_entity_editor_paths($paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        $sanitized = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $normalized = str_replace('\\', '/', ltrim(trim($path), '/'));
            if ($normalized === '') {
                continue;
            }

            $sanitized[$normalized] = $normalized;
        }

        return array_values($sanitized);
    }

    /**
     * REST: Return cached Entity Editor index payload.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_entity_editor_index(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_REST_Response([
                'items' => [],
                'stats' => [
                    'scanned_files' => 0,
                    'indexed_files' => 0,
                    'excluded_files' => 0,
                    'duplicate_groups' => 0,
                    'duplicate_files' => 0,
                ],
                'generated_at' => gmdate('c'),
                'sync_root' => '',
            ]);
        }

        return new \WP_REST_Response(DBVC_Entity_Editor_Indexer::get_index(false));
    }

    /**
     * REST: Force a rebuild of the Entity Editor index payload.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rebuild_entity_editor_index(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_REST_Response([
                'message' => __('Entity indexer unavailable.', 'dbvc'),
            ], 500);
        }

        return new \WP_REST_Response(DBVC_Entity_Editor_Indexer::get_index(true));
    }


    /**
     * REST: Load an entity JSON file for editor view.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function get_entity_editor_file(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error('dbvc_entity_editor_unavailable', __('Entity Editor indexer unavailable.', 'dbvc'), ['status' => 500]);
        }

        $relative_path = (string) $request->get_param('path');
        $force_takeover = rest_sanitize_boolean($request->get_param('force_takeover'));
        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($relative_path, get_current_user_id(), $force_takeover);
        if (is_wp_error($loaded)) {
            return $loaded;
        }

        $decoded = isset($loaded['decoded']) && is_array($loaded['decoded']) ? $loaded['decoded'] : [];
        $description = DBVC_Entity_Editor_Indexer::describe_payload($decoded, $relative_path);

        return new \WP_REST_Response([
            'relative_path' => $loaded['relative_path'],
            'content' => $loaded['content'],
            'mtime' => $loaded['mtime'],
            'mtime_gmt' => $loaded['mtime_gmt'],
            'entity_kind' => (string) ($description['entity_kind'] ?? ''),
            'subtype' => (string) ($description['subtype'] ?? ''),
            'provider' => (string) ($description['provider'] ?? ''),
            'object_type' => (string) ($description['object_type'] ?? ''),
            'title' => (string) ($description['title'] ?? ''),
            'uid' => (string) ($description['uid'] ?? ''),
            'source_id' => $description['source_id'] ?? null,
            'source_status' => (string) ($description['source_status'] ?? ''),
            'matched_provider_entity' => $description['matched_provider_entity'] ?? null,
            'lock' => isset($loaded['lock']) && is_array($loaded['lock']) ? $loaded['lock'] : null,
        ]);
    }


    /**
     * REST: Save entity JSON file from editor view.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function save_entity_editor_file(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error('dbvc_entity_editor_unavailable', __('Entity Editor indexer unavailable.', 'dbvc'), ['status' => 500]);
        }

        $relative_path = (string) $request->get_param('path');
        $content = (string) $request->get_param('content');
        $lock_token = (string) $request->get_param('lock_token');
        $force_takeover = rest_sanitize_boolean($request->get_param('force_takeover'));
        $saved = DBVC_Entity_Editor_Indexer::save_entity_file($relative_path, $content, get_current_user_id(), $lock_token, $force_takeover);
        if (is_wp_error($saved)) {
            return $saved;
        }

        return new \WP_REST_Response($saved);
    }

    /**
     * REST: Delete selected Entity Editor JSON files from sync.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function delete_entity_editor_files(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error('dbvc_entity_editor_unavailable', __('Entity Editor indexer unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $paths = self::sanitize_entity_editor_paths($params['paths'] ?? []);
        if (empty($paths)) {
            return new \WP_Error('dbvc_entity_editor_delete_empty', __('No entity files were selected for removal.', 'dbvc'), ['status' => 400]);
        }

        $result = DBVC_Entity_Editor_Indexer::delete_entity_files($paths, get_current_user_id());
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * REST: Save entity JSON then run non-destructive partial import.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function save_and_partial_import_entity_editor_file(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error('dbvc_entity_editor_unavailable', __('Entity Editor indexer unavailable.', 'dbvc'), ['status' => 500]);
        }

        $relative_path = (string) $request->get_param('path');
        $content = (string) $request->get_param('content');
        $lock_token = (string) $request->get_param('lock_token');
        $force_takeover = rest_sanitize_boolean($request->get_param('force_takeover'));
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = $request->get_params();
        }
        $matching_options = self::normalize_entity_editor_matching_options($params, 'strict_uid');
        $result = DBVC_Entity_Editor_Indexer::save_and_partial_import($relative_path, $content, get_current_user_id(), $lock_token, $force_takeover, $matching_options);
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * REST: Save entity JSON then run destructive full replace import.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function save_and_full_replace_entity_editor_file(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error('dbvc_entity_editor_unavailable', __('Entity Editor indexer unavailable.', 'dbvc'), ['status' => 500]);
        }

        $relative_path = (string) $request->get_param('path');
        $content = (string) $request->get_param('content');
        $confirm_phrase = (string) $request->get_param('confirm_phrase');
        $lock_token = (string) $request->get_param('lock_token');
        $force_takeover = rest_sanitize_boolean($request->get_param('force_takeover'));
        $result = DBVC_Entity_Editor_Indexer::save_and_full_replace($relative_path, $content, get_current_user_id(), $lock_token, $force_takeover, $confirm_phrase);
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * REST: Preview merging pasted JSON into the selected Entity Editor file.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function preview_entity_editor_merge_json(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\EntityEditor\EntityJsonMergeService')) {
            return new \WP_Error('dbvc_entity_editor_merge_unavailable', __('Entity JSON merge service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $relative_path = isset($params['path']) ? (string) $params['path'] : '';
        $incoming_json = isset($params['incoming_json']) ? (string) $params['incoming_json'] : '';
        $identity = isset($params['identity']) && is_array($params['identity']) ? $params['identity'] : [];
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'save';
        $matching_options = self::normalize_entity_editor_matching_options($params, 'selected_entity');

        $preview = \Dbvc\EntityEditor\EntityJsonMergeService::preview($relative_path, $incoming_json, $identity, $mode, $matching_options);
        if (is_wp_error($preview)) {
            return $preview;
        }

        return new \WP_REST_Response($preview);
    }

    /**
     * REST: Save a server-generated merge into the selected Entity Editor file.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function save_entity_editor_merge_json(\WP_REST_Request $request)
    {
        return self::save_entity_editor_merge_json_with_mode($request, false);
    }

    /**
     * REST: Save a server-generated merge and run partial import.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function save_and_partial_import_entity_editor_merge_json(\WP_REST_Request $request)
    {
        return self::save_entity_editor_merge_json_with_mode($request, true);
    }

    /**
     * @param \WP_REST_Request $request
     * @param bool             $partial_import
     * @return \WP_REST_Response|\WP_Error
     */
    private static function save_entity_editor_merge_json_with_mode(\WP_REST_Request $request, $partial_import)
    {
        if (! class_exists('\Dbvc\EntityEditor\EntityJsonMergeService')) {
            return new \WP_Error('dbvc_entity_editor_merge_unavailable', __('Entity JSON merge service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $relative_path = isset($params['path']) ? (string) $params['path'] : '';
        $incoming_json = isset($params['incoming_json']) ? (string) $params['incoming_json'] : '';
        $identity = isset($params['identity']) && is_array($params['identity']) ? $params['identity'] : [];
        $lock_token = isset($params['lock_token']) ? (string) $params['lock_token'] : '';
        $force_takeover = rest_sanitize_boolean($params['force_takeover'] ?? false);
        $preview_hash = isset($params['preview_hash']) ? (string) $params['preview_hash'] : '';
        $confirmed = rest_sanitize_boolean($params['confirmed'] ?? false);
        $matching_options = self::normalize_entity_editor_matching_options($params, 'selected_entity');

        $result = \Dbvc\EntityEditor\EntityJsonMergeService::save(
            $relative_path,
            $incoming_json,
            $identity,
            get_current_user_id(),
            $lock_token,
            $force_takeover,
            $preview_hash,
            $confirmed,
            (bool) $partial_import,
            $matching_options
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * Normalize one-run Entity Editor matching policy overrides from REST input.
     *
     * @param array<string,mixed> $params
     * @param string              $default_policy
     * @return array<string,mixed>
     */
    private static function normalize_entity_editor_matching_options(array $params, $default_policy = 'strict_uid')
    {
        $matching = [];
        if (isset($params['matching']) && is_array($params['matching'])) {
            $matching = $params['matching'];
        } elseif (isset($params['matching_options']) && is_array($params['matching_options'])) {
            $matching = $params['matching_options'];
        }

        $policy = '';
        if (isset($matching['policy'])) {
            $policy = sanitize_key((string) $matching['policy']);
        } elseif (isset($params['matching_policy'])) {
            $policy = sanitize_key((string) $params['matching_policy']);
        }

        $default_policy = sanitize_key((string) $default_policy);
        if (! in_array($default_policy, ['strict_uid', 'allow_slug_fallback', 'selected_entity'], true)) {
            $default_policy = 'strict_uid';
        }

        if (! in_array($policy, ['strict_uid', 'allow_slug_fallback', 'selected_entity'], true)) {
            $policy = $default_policy;
        }

        $options = [
            'policy' => $policy,
        ];

        if (array_key_exists('allow_uid_fallback', $matching)) {
            $options['allow_uid_fallback'] = rest_sanitize_boolean($matching['allow_uid_fallback']);
        }

        return $options;
    }

    /**
     * REST: Preview an Entity Editor transfer packet before download.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function preview_entity_editor_transfer_packet(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\Transfer\EntityPacketBuilder')) {
            return new \WP_Error('dbvc_transfer_unavailable', __('Transfer packet builder unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $paths = self::sanitize_entity_editor_paths($params['paths'] ?? []);
        if (empty($paths)) {
            return new \WP_Error('dbvc_transfer_empty_selection', __('No entity files selected for transfer preview.', 'dbvc'), ['status' => 400]);
        }

        $preview = \Dbvc\Transfer\EntityPacketBuilder::preview_from_entity_paths($paths);
        if (is_wp_error($preview)) {
            return $preview;
        }

        return new \WP_REST_Response($preview);
    }

    /**
     * REST: Preview Entity Editor raw JSON intake before writing/importing.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function preview_entity_editor_raw_intake(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\EntityEditor\RawJsonIntakeService')) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_unavailable', __('Raw JSON intake service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $content = isset($params['content']) ? (string) $params['content'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'create_only';

        if (
            class_exists('\Dbvc\EntityEditor\ThirdPartySyncFileImportService')
            && \Dbvc\EntityEditor\ThirdPartySyncFileImportService::can_handle_raw_content($content)
        ) {
            $preview = \Dbvc\EntityEditor\ThirdPartySyncFileImportService::preview_raw($content, $mode);
            if (is_wp_error($preview)) {
                return $preview;
            }

            return new \WP_REST_Response($preview);
        }

        $preview = \Dbvc\EntityEditor\RawJsonIntakeService::preview($content, $mode);
        if (is_wp_error($preview)) {
            return $preview;
        }

        return new \WP_REST_Response($preview);
    }

    /**
     * REST: Write/import a raw JSON payload from the Entity Editor.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function commit_entity_editor_raw_intake(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\EntityEditor\RawJsonIntakeService')) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_unavailable', __('Raw JSON intake service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $content = isset($params['content']) ? (string) $params['content'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'create_only';
        $args = [
            'confirmation'  => isset($params['confirmation']) && is_array($params['confirmation'])
                ? $params['confirmation']
                : [],
            'confirmations' => isset($params['confirmations']) && is_array($params['confirmations'])
                ? $params['confirmations']
                : [],
        ];

        if (
            class_exists('\Dbvc\EntityEditor\ThirdPartySyncFileImportService')
            && \Dbvc\EntityEditor\ThirdPartySyncFileImportService::can_handle_raw_content($content)
        ) {
            $result = \Dbvc\EntityEditor\ThirdPartySyncFileImportService::commit_raw($content, $mode, get_current_user_id(), $args);
            if (is_wp_error($result)) {
                return $result;
            }

            return new \WP_REST_Response($result);
        }

        $result = \Dbvc\EntityEditor\RawJsonIntakeService::commit($content, $mode, get_current_user_id(), $args);
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * REST: Preview importing an existing Entity Editor sync JSON file.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function preview_entity_editor_sync_file_import(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\EntityEditor\SyncFileImportService')) {
            return new \WP_Error('dbvc_entity_editor_sync_import_unavailable', __('Sync file import service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $paths = $params['paths'] ?? ($params['path'] ?? []);
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'create_only';

        $preview = \Dbvc\EntityEditor\SyncFileImportService::preview($paths, $mode);
        if (is_wp_error($preview)) {
            return $preview;
        }

        return new \WP_REST_Response($preview);
    }

    /**
     * REST: Import an existing Entity Editor sync JSON file.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function commit_entity_editor_sync_file_import(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\EntityEditor\SyncFileImportService')) {
            return new \WP_Error('dbvc_entity_editor_sync_import_unavailable', __('Sync file import service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $paths = $params['paths'] ?? ($params['path'] ?? []);
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'create_only';
        $args = [
            'confirmations' => isset($params['confirmations']) && is_array($params['confirmations'])
                ? $params['confirmations']
                : [],
        ];

        $result = \Dbvc\EntityEditor\SyncFileImportService::commit($paths, $mode, get_current_user_id(), $args);
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * REST: Resolve a blocked sync-file import preview with an allowlisted action.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function remediate_entity_editor_sync_file_import(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\EntityEditor\SyncFileImportService')) {
            return new \WP_Error('dbvc_entity_editor_sync_import_unavailable', __('Sync file import service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $path = isset($params['path']) ? (string) $params['path'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'create_only';
        $remediation = isset($params['remediation']) ? (string) $params['remediation'] : '';
        $args = [
            'preview_hash' => isset($params['preview_hash']) ? (string) $params['preview_hash'] : '',
        ];

        $result = \Dbvc\EntityEditor\SyncFileImportService::remediate($path, $mode, $remediation, $args, get_current_user_id());
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
    }

    /**
     * REST: Preview importing third-party sync JSON from Entity Editor.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function preview_entity_editor_third_party_import(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\EntityEditor\ThirdPartySyncFileImportService')) {
            return new \WP_Error('dbvc_entity_editor_third_party_import_unavailable', __('Third-party Entity Editor import service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $paths = $params['paths'] ?? ($params['path'] ?? []);
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'preview';

        $preview = \Dbvc\EntityEditor\ThirdPartySyncFileImportService::preview($paths, $mode);
        if (is_wp_error($preview)) {
            return $preview;
        }

        return new \WP_REST_Response($preview);
    }

    /**
     * REST: Commit third-party sync JSON imports from Entity Editor.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function commit_entity_editor_third_party_import(\WP_REST_Request $request)
    {
        if (! class_exists('\Dbvc\EntityEditor\ThirdPartySyncFileImportService')) {
            return new \WP_Error('dbvc_entity_editor_third_party_import_unavailable', __('Third-party Entity Editor import service unavailable.', 'dbvc'), ['status' => 500]);
        }

        $params = $request->get_json_params();
        $paths = $params['paths'] ?? ($params['path'] ?? []);
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'create_form';
        $args = [
            'confirmations' => isset($params['confirmations']) && is_array($params['confirmations'])
                ? $params['confirmations']
                : [],
        ];

        $result = \Dbvc\EntityEditor\ThirdPartySyncFileImportService::commit($paths, $mode, get_current_user_id(), $args);
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result);
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


    /**
     * Validate a proposal archive before allowing ZipArchive to write any entries.
     *
     * @param \ZipArchive $zip
     * @param string      $zip_path
     * @return array|\WP_Error
     */
    private static function validate_proposal_zip(\ZipArchive $zip, string $zip_path)
    {
        $resource_limits = self::get_proposal_zip_resource_limits($zip_path);
        if ($zip->numFiles < 1) {
            return self::reject_proposal_zip(
                'dbvc_zip_layout_invalid',
                __('The uploaded ZIP archive is empty.', 'dbvc'),
                $zip_path,
                'empty_archive'
            );
        }
        if ($zip->numFiles > $resource_limits['max_entries']) {
            return self::reject_proposal_zip(
                'dbvc_zip_resource_limit',
                __('The uploaded ZIP contains too many entries to extract safely.', 'dbvc'),
                $zip_path,
                'entry_count_exceeded',
                null,
                '',
                [
                    'actual_entries' => $zip->numFiles,
                    'max_entries'    => $resource_limits['max_entries'],
                ]
            );
        }

        clearstatcache(true, $zip_path);
        $archive_bytes = @filesize($zip_path);
        if (! is_int($archive_bytes) || $archive_bytes < 1) {
            return self::reject_proposal_zip(
                'dbvc_zip_stats_invalid',
                __('The uploaded ZIP has unreadable size metadata.', 'dbvc'),
                $zip_path,
                'archive_size_invalid'
            );
        }

        $entries = [];
        $entry_lookup = [];
        $casefold_lookup = [];
        $manifest_entries = [];
        $total_uncompressed_bytes = 0;
        $file_entries = 0;
        $directory_entries = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $raw_name = $zip->getNameIndex($index);
            $entry = self::normalize_proposal_zip_entry($raw_name);
            if (! is_array($entry)) {
                return self::reject_proposal_zip(
                    'dbvc_zip_unsafe_entry',
                    __('The uploaded ZIP contains an unsafe entry.', 'dbvc'),
                    $zip_path,
                    'unsafe_path',
                    $index,
                    is_string($raw_name) ? $raw_name : ''
                );
            }

            $lookup_key = strtolower($entry['path']);
            if (isset($casefold_lookup[$lookup_key])) {
                return self::reject_proposal_zip(
                    'dbvc_zip_unsafe_entry',
                    __('The uploaded ZIP contains duplicate or conflicting entries.', 'dbvc'),
                    $zip_path,
                    'duplicate_path',
                    $index,
                    $raw_name
                );
            }

            $entry_type = self::get_proposal_zip_entry_type($zip, $index);
            if ($entry_type === 'symlink') {
                return self::reject_proposal_zip(
                    'dbvc_zip_symlink_entry',
                    __('The uploaded ZIP contains a symbolic link, which is not supported.', 'dbvc'),
                    $zip_path,
                    'symlink',
                    $index,
                    $raw_name
                );
            }
            if ($entry_type === 'unsupported') {
                return self::reject_proposal_zip(
                    'dbvc_zip_unsupported_entry',
                    __('The uploaded ZIP contains an unsupported file type.', 'dbvc'),
                    $zip_path,
                    'unsupported_file_type',
                    $index,
                    $raw_name
                );
            }

            $stat = $zip->statIndex($index);
            if (is_array($stat) && ! empty($stat['encryption_method'])) {
                return self::reject_proposal_zip(
                    'dbvc_zip_unsupported_entry',
                    __('Encrypted ZIP entries are not supported.', 'dbvc'),
                    $zip_path,
                    'encrypted_entry',
                    $index,
                    $raw_name
                );
            }

            $resource_usage = self::evaluate_proposal_zip_entry_resource_usage(
                $stat,
                $entry['is_directory'],
                $archive_bytes,
                $resource_limits,
                $total_uncompressed_bytes
            );
            if (empty($resource_usage['valid'])) {
                $reason = isset($resource_usage['reason']) ? (string) $resource_usage['reason'] : 'entry_stats_invalid';
                return self::reject_proposal_zip(
                    ! empty($resource_usage['limit_exceeded']) ? 'dbvc_zip_resource_limit' : 'dbvc_zip_stats_invalid',
                    self::get_proposal_zip_resource_error_message($reason),
                    $zip_path,
                    $reason,
                    $index,
                    $raw_name,
                    isset($resource_usage['details']) && is_array($resource_usage['details'])
                        ? $resource_usage['details']
                        : []
                );
            }
            $total_uncompressed_bytes = (int) $resource_usage['total_uncompressed_bytes'];
            if ($entry['is_directory']) {
                $directory_entries++;
            } else {
                $file_entries++;
            }

            $entry['index'] = $index;
            $entries[] = $entry;
            $entry_lookup[$entry['path']] = $entry;
            $casefold_lookup[$lookup_key] = $entry;

            if (! $entry['is_directory'] && basename($entry['path']) === DBVC_Backup_Manager::MANIFEST_FILENAME) {
                $manifest_entries[] = $entry;
            }
        }

        if (empty($manifest_entries)) {
            return self::reject_proposal_zip(
                'dbvc_manifest_missing',
                __('The uploaded bundle is missing manifest.json.', 'dbvc'),
                $zip_path,
                'manifest_missing'
            );
        }
        if (count($manifest_entries) !== 1) {
            return self::reject_proposal_zip(
                'dbvc_zip_layout_invalid',
                __('The uploaded ZIP must contain exactly one manifest.json file.', 'dbvc'),
                $zip_path,
                'multiple_manifests'
            );
        }

        $manifest_entry = $manifest_entries[0];
        $manifest_parent = dirname($manifest_entry['path']);
        $bundle_root = $manifest_parent === '.' ? '' : trim($manifest_parent, '/');
        if ($bundle_root !== '' && strpos($bundle_root, '/') !== false) {
            return self::reject_proposal_zip(
                'dbvc_zip_layout_invalid',
                __('manifest.json must be at the archive root or inside one top-level folder.', 'dbvc'),
                $zip_path,
                'nested_bundle_root',
                $manifest_entry['index'],
                $manifest_entry['path']
            );
        }

        if ($bundle_root !== '') {
            $root_prefix = $bundle_root . '/';
            foreach ($entries as $entry) {
                if ($entry['path'] !== $bundle_root && strpos($entry['path'], $root_prefix) !== 0) {
                    return self::reject_proposal_zip(
                        'dbvc_zip_layout_invalid',
                        __('All uploaded bundle files must share the manifest top-level folder.', 'dbvc'),
                        $zip_path,
                        'mixed_bundle_roots',
                        $entry['index'],
                        $entry['path']
                    );
                }
            }
        }

        $file_types = self::validate_proposal_zip_file_types(
            $zip,
            $entries,
            $bundle_root,
            $zip_path
        );
        if (is_wp_error($file_types)) {
            return $file_types;
        }

        $manifest_raw = $zip->getFromIndex($manifest_entry['index']);
        $manifest = is_string($manifest_raw) ? json_decode($manifest_raw, true) : null;
        if (! is_array($manifest) || ! isset($manifest['items']) || ! is_array($manifest['items'])) {
            return self::reject_proposal_zip(
                'dbvc_manifest_invalid',
                __('manifest.json is not valid proposal JSON.', 'dbvc'),
                $zip_path,
                'manifest_invalid',
                $manifest_entry['index'],
                $manifest_entry['path']
            );
        }

        $manifest_files = self::validate_proposal_manifest_files(
            $manifest,
            $entry_lookup,
            $bundle_root,
            $zip_path
        );
        if (is_wp_error($manifest_files)) {
            return $manifest_files;
        }

        return [
            'manifest'       => $manifest,
            'manifest_entry' => $manifest_entry['path'],
            'bundle_root'    => $bundle_root,
            'resource_usage' => [
                'archive_entries'         => $zip->numFiles,
                'file_entries'            => $file_entries,
                'directory_entries'       => $directory_entries,
                'archive_bytes'           => $archive_bytes,
                'total_uncompressed_bytes' => $total_uncompressed_bytes,
            ],
        ];
    }

    /**
     * Return mandatory extraction ceilings for proposal archives.
     *
     * @param string $zip_path
     * @return array
     */
    private static function get_proposal_zip_resource_limits(string $zip_path): array
    {
        $defaults = [
            'max_entries'                  => self::PROPOSAL_ZIP_MAX_ENTRIES_DEFAULT,
            'max_entry_uncompressed_bytes' => self::PROPOSAL_ZIP_MAX_ENTRY_BYTES_DEFAULT,
            'max_total_uncompressed_bytes' => self::PROPOSAL_ZIP_MAX_TOTAL_BYTES_DEFAULT,
            'max_compression_ratio'         => self::PROPOSAL_ZIP_MAX_COMPRESSION_RATIO_DEFAULT,
        ];

        /**
         * Filter proposal ZIP extraction ceilings before any archive entry is read or written.
         *
         * All limits remain mandatory. Invalid or non-positive values fall back to the defaults.
         *
         * @param array  $limits   Entry count, per-entry bytes, total bytes, and compression ratio.
         * @param string $zip_path Local path to the uploaded archive.
         */
        $filtered = apply_filters('dbvc_proposal_zip_resource_limits', $defaults, $zip_path);
        if (! is_array($filtered)) {
            $filtered = [];
        }

        $normalize_positive_integer = static function ($value, int $default): int {
            if (! is_numeric($value)) {
                return $default;
            }
            $numeric = (float) $value;
            if (! is_finite($numeric) || $numeric < 1 || $numeric > PHP_INT_MAX) {
                return $default;
            }
            return (int) floor($numeric);
        };

        $ratio = isset($filtered['max_compression_ratio']) && is_numeric($filtered['max_compression_ratio'])
            ? (float) $filtered['max_compression_ratio']
            : $defaults['max_compression_ratio'];
        if (! is_finite($ratio) || $ratio < 1) {
            $ratio = $defaults['max_compression_ratio'];
        }

        return [
            'max_entries' => $normalize_positive_integer(
                $filtered['max_entries'] ?? $defaults['max_entries'],
                $defaults['max_entries']
            ),
            'max_entry_uncompressed_bytes' => $normalize_positive_integer(
                $filtered['max_entry_uncompressed_bytes'] ?? $defaults['max_entry_uncompressed_bytes'],
                $defaults['max_entry_uncompressed_bytes']
            ),
            'max_total_uncompressed_bytes' => $normalize_positive_integer(
                $filtered['max_total_uncompressed_bytes'] ?? $defaults['max_total_uncompressed_bytes'],
                $defaults['max_total_uncompressed_bytes']
            ),
            'max_compression_ratio' => $ratio,
        ];
    }

    /**
     * Validate one central-directory stat record and advance the expanded byte total.
     *
     * @param mixed $stat
     * @return array
     */
    private static function evaluate_proposal_zip_entry_resource_usage(
        $stat,
        bool $is_directory,
        int $archive_bytes,
        array $limits,
        int $current_total
    ): array {
        if (! is_array($stat)) {
            return ['valid' => false, 'reason' => 'entry_stats_missing'];
        }

        if (! array_key_exists('size', $stat) || ! is_int($stat['size']) || $stat['size'] < 0) {
            return ['valid' => false, 'reason' => 'entry_size_invalid'];
        }
        if (! array_key_exists('comp_size', $stat) || ! is_int($stat['comp_size']) || $stat['comp_size'] < 0) {
            return ['valid' => false, 'reason' => 'entry_compressed_size_invalid'];
        }

        $uncompressed_bytes = $stat['size'];
        $compressed_bytes = $stat['comp_size'];

        if ($is_directory) {
            if ($uncompressed_bytes !== 0 || $compressed_bytes !== 0) {
                return ['valid' => false, 'reason' => 'directory_size_invalid'];
            }
            return [
                'valid'                    => true,
                'total_uncompressed_bytes' => $current_total,
            ];
        }

        if ($uncompressed_bytes > 0 && $compressed_bytes === 0) {
            return ['valid' => false, 'reason' => 'entry_compressed_size_invalid'];
        }
        if ($compressed_bytes > $archive_bytes) {
            return ['valid' => false, 'reason' => 'entry_compressed_size_inconsistent'];
        }
        if ($uncompressed_bytes > $limits['max_entry_uncompressed_bytes']) {
            return [
                'valid'          => false,
                'limit_exceeded' => true,
                'reason'         => 'entry_size_exceeded',
                'details'        => [
                    'entry_uncompressed_bytes'     => $uncompressed_bytes,
                    'max_entry_uncompressed_bytes' => $limits['max_entry_uncompressed_bytes'],
                ],
            ];
        }
        if (
            $uncompressed_bytes > $limits['max_total_uncompressed_bytes']
            || $current_total > $limits['max_total_uncompressed_bytes'] - $uncompressed_bytes
        ) {
            return [
                'valid'          => false,
                'limit_exceeded' => true,
                'reason'         => 'total_size_exceeded',
                'details'        => [
                    'expanded_bytes_before_entry'  => $current_total,
                    'entry_uncompressed_bytes'     => $uncompressed_bytes,
                    'max_total_uncompressed_bytes' => $limits['max_total_uncompressed_bytes'],
                ],
            ];
        }

        $compression_ratio = $uncompressed_bytes > 0
            ? $uncompressed_bytes / $compressed_bytes
            : 1.0;
        if ($compression_ratio > $limits['max_compression_ratio']) {
            return [
                'valid'          => false,
                'limit_exceeded' => true,
                'reason'         => 'compression_ratio_exceeded',
                'details'        => [
                    'compression_ratio'     => round($compression_ratio, 4),
                    'max_compression_ratio' => $limits['max_compression_ratio'],
                ],
            ];
        }

        return [
            'valid'                    => true,
            'total_uncompressed_bytes' => $current_total + $uncompressed_bytes,
        ];
    }

    private static function get_proposal_zip_resource_error_message(string $reason): string
    {
        switch ($reason) {
            case 'entry_size_exceeded':
                return __('The uploaded ZIP contains a file that is too large to extract safely.', 'dbvc');
            case 'total_size_exceeded':
                return __('The uploaded ZIP expands beyond the allowed size.', 'dbvc');
            case 'compression_ratio_exceeded':
                return __('The uploaded ZIP contains a file with an unsafe compression ratio.', 'dbvc');
            default:
                return __('The uploaded ZIP contains unreadable or inconsistent size metadata.', 'dbvc');
        }
    }

    /**
     * Normalize a ZIP entry to a portable relative path.
     *
     * @param mixed $raw_name
     * @return array|null
     */
    private static function normalize_proposal_zip_entry($raw_name): ?array
    {
        if (! is_string($raw_name) || $raw_name === '' || preg_match('/[\x00-\x1F\x7F]/', $raw_name)) {
            return null;
        }

        $portable = str_replace('\\', '/', $raw_name);
        if (
            strpos($portable, '/') === 0
            || preg_match('/^[A-Za-z]:/', $portable)
        ) {
            return null;
        }

        $is_directory = substr($portable, -1) === '/';
        $parts = explode('/', $portable);
        $normalized = [];
        $last_index = count($parts) - 1;

        foreach ($parts as $index => $part) {
            if ($part === '') {
                if ($is_directory && $index === $last_index) {
                    continue;
                }
                return null;
            }
            if ($part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $normalized[] = $part;
        }

        if (empty($normalized)) {
            return null;
        }

        return [
            'path'         => implode('/', $normalized),
            'is_directory' => $is_directory,
        ];
    }

    /**
     * Identify links and special Unix entries from ZIP external attributes.
     */
    private static function get_proposal_zip_entry_type(\ZipArchive $zip, int $index): string
    {
        if (! method_exists($zip, 'getExternalAttributesIndex')) {
            return 'unknown';
        }

        $opsys = 0;
        $attributes = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return 'unknown';
        }

        $file_type = ($attributes >> 16) & 0xF000;
        if ($file_type === 0xA000) {
            return 'symlink';
        }
        if ($file_type !== 0 && ! in_array($file_type, [0x4000, 0x8000], true)) {
            return 'unsupported';
        }

        return 'regular';
    }

    /**
     * Reject executable/server configuration files except DBVC's inert root guards.
     *
     * @return true|\WP_Error
     */
    private static function validate_proposal_zip_file_types(
        \ZipArchive $zip,
        array $entries,
        string $bundle_root,
        string $zip_path
    ) {
        $root_prefix = $bundle_root !== '' ? $bundle_root . '/' : '';
        $safe_index_path = $root_prefix . 'index.php';
        $safe_htaccess_path = $root_prefix . '.htaccess';
        $safe_index_contents = "<?php\n// Silence is golden.\nexit;";
        $safe_htaccess_contents = "# Protect DBVC backup files from direct web access\n"
            . "Order allow,deny\n"
            . "Deny from all\n\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n\n"
            . 'Options -Indexes';
        $blocked_extensions = [
            'bat', 'bash', 'cgi', 'cmd', 'com', 'dll', 'dylib', 'exe', 'jar',
            'phar', 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml',
            'pl', 'ps1', 'py', 'rb', 'sh', 'so',
        ];

        foreach ($entries as $entry) {
            if (! empty($entry['is_directory'])) {
                continue;
            }

            $path = $entry['path'];
            $basename = strtolower(basename($path));
            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            $is_server_config = in_array($basename, ['.user.ini', 'web.config'], true);
            $is_executable = in_array($extension, $blocked_extensions, true);

            if ($path === $safe_index_path) {
                $contents = $zip->getFromIndex($entry['index']);
                $normalized = is_string($contents) ? str_replace("\r\n", "\n", trim($contents)) : '';
                if ($normalized === $safe_index_contents) {
                    continue;
                }
                $is_executable = true;
            }

            if ($path === $safe_htaccess_path) {
                $contents = $zip->getFromIndex($entry['index']);
                $normalized = is_string($contents) ? str_replace("\r\n", "\n", trim($contents)) : '';
                if ($normalized === $safe_htaccess_contents) {
                    continue;
                }
                $is_server_config = true;
            } elseif ($basename === '.htaccess') {
                $is_server_config = true;
            }

            if ($is_executable || $is_server_config) {
                return self::reject_proposal_zip(
                    'dbvc_zip_unsupported_entry',
                    __('The uploaded ZIP contains an executable or server configuration file.', 'dbvc'),
                    $zip_path,
                    'executable_entry',
                    $entry['index'],
                    $path
                );
            }
        }

        return true;
    }

    /**
     * Ensure every manifest entity payload is a safe file present in the archive.
     *
     * @return true|\WP_Error
     */
    private static function validate_proposal_manifest_files(
        array $manifest,
        array $entry_lookup,
        string $bundle_root,
        string $zip_path
    ) {
        foreach ($manifest['items'] as $item_index => $item) {
            $relative_path = is_array($item) && isset($item['path']) ? (string) $item['path'] : '';
            $entry = self::normalize_proposal_zip_entry($relative_path);
            if (! is_array($entry) || $entry['is_directory']) {
                return self::reject_proposal_zip(
                    'dbvc_manifest_path_invalid',
                    __('The proposal manifest contains an unsafe payload path.', 'dbvc'),
                    $zip_path,
                    'manifest_path_invalid',
                    (int) $item_index,
                    $relative_path
                );
            }

            $archive_path = $bundle_root !== ''
                ? $bundle_root . '/' . $entry['path']
                : $entry['path'];
            if (! isset($entry_lookup[$archive_path]) || ! empty($entry_lookup[$archive_path]['is_directory'])) {
                return self::reject_proposal_zip(
                    'dbvc_manifest_file_missing',
                    __('The uploaded bundle is missing a file required by its manifest.', 'dbvc'),
                    $zip_path,
                    'manifest_file_missing',
                    (int) $item_index,
                    $relative_path
                );
            }
        }

        return true;
    }

    /**
     * Log a sanitized archive rejection and return its REST/CLI-safe error.
     */
    private static function reject_proposal_zip(
        string $code,
        string $message,
        string $zip_path,
        string $reason,
        ?int $entry_index = null,
        string $entry_name = '',
        array $details = []
    ): \WP_Error {
        $context = [
            'archive' => sanitize_file_name(basename($zip_path)),
            'code'    => $code,
            'reason'  => $reason,
        ];
        if ($entry_index !== null) {
            $context['entry_index'] = $entry_index;
        }

        $safe_entry_name = sanitize_file_name(basename(str_replace('\\', '/', $entry_name)));
        if ($safe_entry_name !== '') {
            $context['entry_name'] = $safe_entry_name;
        }

        $safe_details = [];
        foreach ($details as $key => $value) {
            $safe_key = sanitize_key((string) $key);
            if ($safe_key === '') {
                continue;
            }
            if (is_int($value)) {
                $safe_details[$safe_key] = $value;
            } elseif (is_float($value) && is_finite($value)) {
                $safe_details[$safe_key] = $value;
            } elseif (is_string($value)) {
                $safe_details[$safe_key] = sanitize_text_field($value);
            }
        }
        $context = array_merge($context, $safe_details);

        if (class_exists('DBVC_Sync_Logger') && method_exists('DBVC_Sync_Logger', 'log_upload')) {
            DBVC_Sync_Logger::log_upload('Proposal ZIP rejected', $context);
        }
        if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
            DBVC_Database::log_activity(
                'proposal_upload_rejected',
                'warning',
                'Proposal ZIP rejected before extraction.',
                $context
            );
        }

        $error_data = [
            'status' => 400,
            'reason' => $reason,
        ];
        if ($entry_index !== null) {
            $error_data['entry_index'] = $entry_index;
        }
        $error_data = array_merge($error_data, $safe_details);

        return new \WP_Error($code, $message, $error_data);
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

        $base    = DBVC_Backup_Manager::get_base_path(false);
        $folder  = trailingslashit($base) . $proposal_id;

        if (! is_dir($folder)) {
            return null;
        }

        return DBVC_Backup_Manager::read_manifest($folder);
    }

    /**
     * Build transfer-packet metadata for proposal list/detail payloads.
     *
     * @param array $manifest
     * @return array<string,mixed>
     */
    private static function build_transfer_packet_context(array $manifest): array
    {
        $origin      = self::extract_manifest_origin($manifest);
        $is_transfer = self::is_transfer_packet_manifest($manifest, $origin);

        if (! $is_transfer) {
            return [
                'origin'       => $origin,
                'selection'    => null,
                'requirements' => null,
                'preflight'    => null,
                'warnings'     => null,
            ];
        }

        if (! is_array($origin)) {
            $origin = [
                'type' => 'entity_transfer',
            ];
        }

        $requirements = self::extract_transfer_requirements($manifest);

        return [
            'origin'       => $origin,
            'selection'    => self::extract_transfer_selection($manifest),
            'requirements' => $requirements,
            'preflight'    => self::build_transfer_preflight($manifest, $requirements),
            'warnings'     => self::extract_transfer_warnings($manifest),
        ];
    }

    /**
     * Determine whether the manifest represents a transfer packet.
     *
     * @param array      $manifest
     * @param array|null $origin
     * @return bool
     */
    private static function is_transfer_packet_manifest(array $manifest, ?array $origin = null): bool
    {
        if (is_array($origin) && (($origin['type'] ?? '') === 'entity_transfer')) {
            return true;
        }

        if (! empty($manifest['selection']) && is_array($manifest['selection'])) {
            return true;
        }

        if (! empty($manifest['requirements']) && is_array($manifest['requirements'])) {
            return true;
        }

        return false;
    }

    /**
     * Sanitize manifest origin data for the review UI.
     *
     * @param array $manifest
     * @return array<string,string>|null
     */
    private static function extract_manifest_origin(array $manifest): ?array
    {
        $origin = isset($manifest['origin']) && is_array($manifest['origin']) ? $manifest['origin'] : [];
        if (empty($origin)) {
            return null;
        }

        $sanitized = [];

        if (! empty($origin['type'])) {
            $sanitized['type'] = sanitize_key((string) $origin['type']);
        }

        if (! empty($origin['packet_id'])) {
            $sanitized['packet_id'] = sanitize_file_name((string) $origin['packet_id']);
        }

        if (! empty($origin['source_surface'])) {
            $sanitized['source_surface'] = sanitize_key((string) $origin['source_surface']);
        }

        if (! empty($origin['generated_from_site'])) {
            $sanitized['generated_from_site'] = esc_url_raw((string) $origin['generated_from_site']);
        }

        if (! empty($origin['generated_from_name'])) {
            $sanitized['generated_from_name'] = sanitize_text_field((string) $origin['generated_from_name']);
        }

        return ! empty($sanitized) ? $sanitized : null;
    }

    /**
     * Sanitize transfer selection summary for proposal payloads.
     *
     * @param array $manifest
     * @return array<string,mixed>|null
     */
    private static function extract_transfer_selection(array $manifest): ?array
    {
        $selection = isset($manifest['selection']) && is_array($manifest['selection']) ? $manifest['selection'] : [];
        if (empty($selection)) {
            return null;
        }

        $summary = isset($selection['summary']) && is_array($selection['summary']) ? $selection['summary'] : [];
        $summary_keys = [
            'requested_paths',
            'selected_posts',
            'selected_terms',
            'dependency_terms',
            'live_exports',
            'fallback_files',
            'duplicates_skipped',
            'missing_dependencies',
        ];

        $sanitized_summary = [];
        foreach ($summary_keys as $summary_key) {
            if (array_key_exists($summary_key, $summary)) {
                $sanitized_summary[$summary_key] = max(0, (int) $summary[$summary_key]);
            }
        }

        $requested_paths = isset($selection['requested_paths']) && is_array($selection['requested_paths'])
            ? array_values(array_filter(array_map(static function ($path) {
                return is_string($path) ? ltrim(str_replace('\\', '/', $path), '/') : '';
            }, $selection['requested_paths'])))
            : [];

        $sanitized = [];
        if (! empty($sanitized_summary)) {
            $sanitized['summary'] = $sanitized_summary;
        }
        if (! empty($requested_paths)) {
            $sanitized['requested_paths_count'] = count($requested_paths);
        }

        return ! empty($sanitized) ? $sanitized : null;
    }

    /**
     * Sanitize structured transfer warnings for proposal payloads.
     *
     * @param array $manifest
     * @return array<string,mixed>|null
     */
    private static function extract_transfer_warnings(array $manifest): ?array
    {
        $warnings = isset($manifest['warnings']) && is_array($manifest['warnings']) ? $manifest['warnings'] : [];
        if (empty($warnings)) {
            return null;
        }

        $summary = isset($warnings['summary']) && is_array($warnings['summary']) ? $warnings['summary'] : [];
        $unsupported = isset($warnings['unsupported_post_references']) && is_array($warnings['unsupported_post_references'])
            ? $warnings['unsupported_post_references']
            : [];

        $sanitized_unsupported = [];
        foreach ($unsupported as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $referenced_post_id = isset($warning['referenced_post_id']) ? absint($warning['referenced_post_id']) : 0;
            $source_path = isset($warning['source_path']) ? ltrim(str_replace('\\', '/', (string) $warning['source_path']), '/') : '';
            $meta_key = isset($warning['meta_key']) ? sanitize_key((string) $warning['meta_key']) : '';
            if ($referenced_post_id <= 0 || $source_path === '' || $meta_key === '') {
                continue;
            }

            $sanitized_unsupported[] = [
                'source_path'           => $source_path,
                'source_post_title'     => isset($warning['source_post_title']) ? sanitize_text_field((string) $warning['source_post_title']) : '',
                'source_post_type'      => isset($warning['source_post_type']) ? sanitize_key((string) $warning['source_post_type']) : '',
                'meta_key'              => $meta_key,
                'field_label'           => isset($warning['field_label']) ? sanitize_text_field((string) $warning['field_label']) : '',
                'field_type'            => isset($warning['field_type']) ? sanitize_key((string) $warning['field_type']) : '',
                'reference_source'      => isset($warning['reference_source']) ? sanitize_key((string) $warning['reference_source']) : '',
                'referenced_post_id'    => $referenced_post_id,
                'referenced_post_title' => isset($warning['referenced_post_title']) ? sanitize_text_field((string) $warning['referenced_post_title']) : '',
                'referenced_post_type'  => isset($warning['referenced_post_type']) ? sanitize_key((string) $warning['referenced_post_type']) : '',
                'referenced_post_name'  => isset($warning['referenced_post_name']) ? sanitize_title((string) $warning['referenced_post_name']) : '',
            ];
        }

        $sanitized = [];
        if (isset($summary['unsupported_post_references'])) {
            $sanitized['summary'] = [
                'unsupported_post_references' => max(0, (int) $summary['unsupported_post_references']),
            ];
        }
        if (! empty($sanitized_unsupported)) {
            $sanitized['unsupported_post_references'] = $sanitized_unsupported;
        }

        return ! empty($sanitized) ? $sanitized : null;
    }

    /**
     * Extract transfer requirements from the manifest, falling back to inferred values when needed.
     *
     * @param array $manifest
     * @return array<string,array>
     */
    private static function extract_transfer_requirements(array $manifest): array
    {
        $requirements = isset($manifest['requirements']) && is_array($manifest['requirements']) ? $manifest['requirements'] : [];
        $post_types = [];
        $taxonomies = [];
        $notes = [];

        foreach (($requirements['post_types'] ?? []) as $post_type) {
            $normalized = sanitize_key((string) $post_type);
            if ($normalized !== '') {
                $post_types[$normalized] = $normalized;
            }
        }

        foreach (($requirements['taxonomies'] ?? []) as $taxonomy) {
            $normalized = sanitize_key((string) $taxonomy);
            if ($normalized !== '') {
                $taxonomies[$normalized] = $normalized;
            }
        }

        foreach (($requirements['notes'] ?? []) as $note) {
            $normalized = sanitize_text_field((string) $note);
            if ($normalized !== '') {
                $notes[$normalized] = $normalized;
            }
        }

        $inferred = self::infer_manifest_requirements($manifest);
        foreach ($inferred['post_types'] as $post_type) {
            $post_types[$post_type] = $post_type;
        }
        foreach ($inferred['taxonomies'] as $taxonomy) {
            $taxonomies[$taxonomy] = $taxonomy;
        }

        $post_types = array_values($post_types);
        $taxonomies = array_values($taxonomies);
        $notes = array_values($notes);

        sort($post_types);
        sort($taxonomies);
        sort($notes);

        return [
            'post_types' => $post_types,
            'taxonomies' => $taxonomies,
            'notes'      => $notes,
        ];
    }

    /**
     * Infer post-type and taxonomy requirements from manifest items.
     *
     * @param array $manifest
     * @return array{post_types:array<int,string>,taxonomies:array<int,string>}
     */
    private static function infer_manifest_requirements(array $manifest): array
    {
        $post_types = [];
        $taxonomies = [];
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if ($item_type === 'term') {
                $taxonomy = isset($item['term_taxonomy']) ? sanitize_key((string) $item['term_taxonomy']) : '';
                if ($taxonomy !== '') {
                    $taxonomies[$taxonomy] = $taxonomy;
                }
                continue;
            }

            if ($item_type !== 'post') {
                continue;
            }

            $post_type = isset($item['post_type']) ? sanitize_key((string) $item['post_type']) : '';
            if ($post_type !== '') {
                $post_types[$post_type] = $post_type;
            }

            if (! empty($item['tax_input']) && is_array($item['tax_input'])) {
                foreach (array_keys($item['tax_input']) as $taxonomy_key) {
                    $taxonomy = sanitize_key((string) $taxonomy_key);
                    if ($taxonomy !== '') {
                        $taxonomies[$taxonomy] = $taxonomy;
                    }
                }
            }
        }

        return [
            'post_types' => array_values($post_types),
            'taxonomies' => array_values($taxonomies),
        ];
    }

    /**
     * Evaluate destination-side warnings for transfer packets.
     *
     * @param array $manifest
     * @param array $requirements
     * @return array<string,mixed>
     */
    private static function build_transfer_preflight(array $manifest, array $requirements): array
    {
        $missing_post_types = [];
        foreach (($requirements['post_types'] ?? []) as $post_type) {
            $normalized = sanitize_key((string) $post_type);
            if ($normalized !== '' && ! post_type_exists($normalized)) {
                $missing_post_types[$normalized] = $normalized;
            }
        }

        $missing_taxonomies = [];
        foreach (($requirements['taxonomies'] ?? []) as $taxonomy) {
            $normalized = sanitize_key((string) $taxonomy);
            if ($normalized !== '' && ! taxonomy_exists($normalized)) {
                $missing_taxonomies[$normalized] = $normalized;
            }
        }

        $unsupported_items = [];
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $unsupported_items['invalid_payload'] = 'invalid_payload';
                continue;
            }

            $item_type = isset($item['item_type']) ? sanitize_key((string) $item['item_type']) : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                $unsupported_items['item_type:' . $item_type] = 'item_type:' . $item_type;
                continue;
            }

            if ($item_type === 'term') {
                $taxonomy = isset($item['term_taxonomy']) ? sanitize_key((string) $item['term_taxonomy']) : '';
                if ($taxonomy === '') {
                    $unsupported_items['term_missing_taxonomy'] = 'term_missing_taxonomy';
                }
            }
        }

        $missing_post_types = array_values($missing_post_types);
        $missing_taxonomies = array_values($missing_taxonomies);
        $unsupported_items = array_values($unsupported_items);

        sort($missing_post_types);
        sort($missing_taxonomies);
        sort($unsupported_items);

        $warning_count = count($missing_post_types) + count($missing_taxonomies) + count($unsupported_items);

        return [
            'status'             => $warning_count > 0 ? 'warning' : 'ok',
            'has_warnings'       => $warning_count > 0,
            'warning_count'      => $warning_count,
            'missing_post_types' => $missing_post_types,
            'missing_taxonomies' => $missing_taxonomies,
            'unsupported_items'  => $unsupported_items,
        ];
    }

    /**
     * Validate that an extracted proposal bundle is internally coherent before registration.
     *
     * @param array  $manifest
     * @param string $bundle_root
     * @return true|\WP_Error
     */
    private static function validate_import_bundle_manifest(array $manifest, string $bundle_root)
    {
        $bundle_root = realpath($bundle_root);
        if ($bundle_root === false || ! is_dir($bundle_root)) {
            return new \WP_Error(
                'dbvc_bundle_root_invalid',
                __('The uploaded bundle could not be validated.', 'dbvc'),
                ['status' => 400]
            );
        }

        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $invalid_items = [];
        $invalid_paths = [];
        $missing_files = [];
        $invalid_json = [];

        foreach ($items as $index => $item) {
            $item_label = sprintf('#%d', $index + 1);
            if (! is_array($item)) {
                $invalid_items[] = $item_label;
                continue;
            }

            $relative_path = isset($item['path']) && is_string($item['path'])
                ? ltrim(str_replace('\\', '/', $item['path']), '/')
                : '';
            if ($relative_path === '') {
                $invalid_paths[] = $item_label;
                continue;
            }

            $absolute_path = self::resolve_manifest_entry_path($bundle_root, $relative_path);
            if (! $absolute_path) {
                $invalid_paths[] = $relative_path;
                continue;
            }

            if (! is_file($absolute_path) || ! is_readable($absolute_path)) {
                $missing_files[] = $relative_path;
                continue;
            }

            $raw = file_get_contents($absolute_path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($decoded)) {
                $invalid_json[] = $relative_path;
            }
        }

        $missing_bundle_assets = self::validate_import_bundle_media_assets($manifest, $bundle_root);
        if (empty($invalid_items) && empty($invalid_paths) && empty($missing_files) && empty($invalid_json) && empty($missing_bundle_assets)) {
            return true;
        }

        $messages = [];
        if (! empty($invalid_items)) {
            $messages[] = sprintf(
                __('Manifest items are malformed: %s.', 'dbvc'),
                implode(', ', array_slice($invalid_items, 0, 5))
            );
        }
        if (! empty($invalid_paths)) {
            $messages[] = sprintf(
                __('Manifest item paths are invalid: %s.', 'dbvc'),
                implode(', ', array_slice($invalid_paths, 0, 5))
            );
        }
        if (! empty($missing_files)) {
            $messages[] = sprintf(
                __('Referenced payload files are missing: %s.', 'dbvc'),
                implode(', ', array_slice($missing_files, 0, 5))
            );
        }
        if (! empty($invalid_json)) {
            $messages[] = sprintf(
                __('Referenced payload files are not valid JSON objects: %s.', 'dbvc'),
                implode(', ', array_slice($invalid_json, 0, 5))
            );
        }
        if (! empty($missing_bundle_assets)) {
            $messages[] = sprintf(
                __('Referenced media-bundle assets are missing: %s.', 'dbvc'),
                implode(', ', array_slice($missing_bundle_assets, 0, 5))
            );
        }

        return new \WP_Error(
            'dbvc_manifest_bundle_invalid',
            implode(' ', $messages),
            [
                'status'  => 400,
                'details' => [
                    'invalid_items'        => array_values($invalid_items),
                    'invalid_paths'        => array_values($invalid_paths),
                    'missing_files'        => array_values($missing_files),
                    'invalid_json'         => array_values($invalid_json),
                    'missing_bundle_assets'=> array_values($missing_bundle_assets),
                ],
            ]
        );
    }

    /**
     * Validate that referenced media-bundle assets exist inside the extracted bundle.
     *
     * @param array  $manifest
     * @param string $bundle_root
     * @return array<int,string>
     */
    private static function validate_import_bundle_media_assets(array $manifest, string $bundle_root): array
    {
        $missing = [];
        $media_bundle = isset($manifest['media_bundle']) && is_array($manifest['media_bundle']) ? $manifest['media_bundle'] : [];
        if (empty($media_bundle)) {
            return $missing;
        }

        $relative_keys = ['backup_relative', 'map'];
        foreach ($relative_keys as $relative_key) {
            $relative_path = isset($media_bundle[$relative_key]) && is_string($media_bundle[$relative_key])
                ? ltrim(str_replace('\\', '/', $media_bundle[$relative_key]), '/')
                : '';
            if ($relative_path === '') {
                continue;
            }

            $absolute_path = self::resolve_manifest_entry_path($bundle_root, $relative_path);
            if (! $absolute_path) {
                $missing[] = $relative_path;
                continue;
            }

            $exists = $relative_key === 'backup_relative'
                ? is_dir($absolute_path)
                : (is_file($absolute_path) && is_readable($absolute_path));
            if (! $exists) {
                $missing[] = $relative_path;
            }
        }

        return array_values(array_unique($missing));
    }

    private static function read_entity_payload(string $proposal_id, string $relative_path): ?array
    {
        if ($relative_path === '') {
            return null;
        }

        $base = DBVC_Backup_Manager::get_base_path(false);
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

    /**
     * Build proposal-level Bricks entity-reference preflight results.
     *
     * @param array  $manifest
     * @param string $proposal_id
     * @param bool   $include_entities Include per-entity summaries for detail responses.
     * @return array<string,mixed>
     */
    private static function build_manifest_bricks_reference_summary(array $manifest, string $proposal_id = '', bool $include_entities = false): array
    {
        $summary = self::empty_bricks_reference_summary($include_entities);
        if (! class_exists('\Dbvc\EntityReferences\BricksReferenceMapper')) {
            return $summary;
        }

        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        if (empty($items)) {
            return $summary;
        }

        $source_to_local_post_ids = self::build_manifest_source_to_local_post_id_map($manifest);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item_type = isset($item['item_type']) ? sanitize_key((string) $item['item_type']) : 'post';
            if ($item_type !== 'post') {
                continue;
            }

            $payload = null;
            $relative_path = isset($item['path']) ? ltrim(str_replace('\\', '/', (string) $item['path']), '/') : '';
            if ($proposal_id !== '' && $relative_path !== '') {
                $payload = self::read_entity_payload($proposal_id, $relative_path);
            }

            $entity_summary = self::build_item_bricks_reference_summary($item, $payload, $source_to_local_post_ids);
            if (($entity_summary['total'] ?? 0) <= 0) {
                continue;
            }

            $summary = self::merge_bricks_reference_summary($summary, $entity_summary);
            if ($include_entities) {
                $entity_uid = (string) ($entity_summary['entity_uid'] ?? '');
                if ($entity_uid !== '') {
                    $summary['entities'][$entity_uid] = $entity_summary;
                }
            }
        }

        $summary['status'] = self::bricks_reference_status_for_counts($summary);
        return $summary;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $payload
     * @param array<int,int> $source_to_local_post_ids
     * @return array<string,mixed>
     */
    private static function build_item_bricks_reference_summary(array $item, ?array $payload, array $source_to_local_post_ids): array
    {
        $entity_uid = isset($item['vf_object_uid'])
            ? (string) $item['vf_object_uid']
            : (isset($item['post_id']) ? (string) $item['post_id'] : '');

        $summary = self::empty_bricks_reference_summary(false);
        $summary['entity_uid'] = $entity_uid;
        $summary['entity_path'] = isset($item['path']) ? ltrim(str_replace('\\', '/', (string) $item['path']), '/') : '';
        $summary['entity_title'] = isset($item['post_title']) ? sanitize_text_field((string) $item['post_title']) : '';
        $summary['entity_type'] = isset($item['post_type']) ? sanitize_key((string) $item['post_type']) : 'post';

        $references = [];
        if (isset($item['dbvc_entity_references'])) {
            $references = \Dbvc\EntityReferences\BricksReferenceMapper::normalize_references($item['dbvc_entity_references']);
        }

        if (empty($references) && is_array($payload)) {
            $references = \Dbvc\EntityReferences\BricksReferenceMapper::normalize_references(
                \Dbvc\EntityReferences\BricksReferenceMapper::collect_post_references($payload, false)
            );
        }

        if (empty($references)) {
            return $summary;
        }

        if (! is_array($payload)) {
            foreach ($references as $reference) {
                $summary['items'][] = self::format_bricks_reference_row($reference, [
                    'status' => 'payload_missing',
                ], $summary);
            }
            return self::finalize_bricks_reference_summary($summary);
        }

        $payload['dbvc_entity_references'] = array_values($references);
        $preflight = \Dbvc\EntityReferences\BricksReferenceMapper::preflight_post_payload($payload, $source_to_local_post_ids);
        $results = isset($preflight['results']) && is_array($preflight['results']) ? $preflight['results'] : [];

        $seen_paths = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $path = isset($result['path']) ? (string) $result['path'] : '';
            if ($path !== '') {
                $seen_paths[$path] = true;
            }

            $reference = isset($references[$path]) && is_array($references[$path])
                ? $references[$path]
                : $result;
            $summary['items'][] = self::format_bricks_reference_row($reference, $result, $summary);
        }

        foreach ($references as $path => $reference) {
            if (isset($seen_paths[$path])) {
                continue;
            }

            $summary['items'][] = self::format_bricks_reference_row($reference, [
                'status' => 'unsupported_path',
            ], $summary);
        }

        return self::finalize_bricks_reference_summary($summary);
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<int,int>
     */
    private static function build_manifest_source_to_local_post_id_map(array $manifest): array
    {
        $map = [];
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item_type = isset($item['item_type']) ? sanitize_key((string) $item['item_type']) : 'post';
            if ($item_type !== 'post') {
                continue;
            }

            $source_id = isset($item['post_id']) ? absint($item['post_id']) : 0;
            if (! $source_id) {
                continue;
            }

            $identity = self::describe_entity_identity($item);
            $local_post_id = isset($identity['local_post_id']) ? absint($identity['local_post_id']) : 0;
            if ($local_post_id) {
                $map[$source_id] = $local_post_id;
            }
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $reference
     * @param array<string,mixed> $result
     * @param array<string,mixed> $entity_summary
     * @return array<string,mixed>
     */
    private static function format_bricks_reference_row(array $reference, array $result, array $entity_summary): array
    {
        $context = isset($reference['context']) && is_array($reference['context']) ? $reference['context'] : [];
        $sanitized_context = [];
        foreach ($context as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }

            $sanitized_context[$key] = sanitize_text_field((string) $value);
        }

        $status = isset($result['status']) ? sanitize_key((string) $result['status']) : 'unknown';
        $local_id = isset($result['local_id']) ? absint($result['local_id']) : 0;

        return [
            'provider'     => isset($reference['provider']) ? sanitize_key((string) $reference['provider']) : 'bricks',
            'kind'         => isset($reference['kind']) ? sanitize_key((string) $reference['kind']) : '',
            'path'         => isset($reference['path']) ? sanitize_text_field((string) $reference['path']) : '',
            'source_id'    => isset($reference['source_id']) ? absint($reference['source_id']) : 0,
            'local_id'     => $local_id ?: null,
            'status'       => $status !== '' ? $status : 'unknown',
            'severity'     => $status === 'resolved' ? 'resolved' : 'warning',
            'context'      => $sanitized_context,
            'entity_uid'   => isset($entity_summary['entity_uid']) ? (string) $entity_summary['entity_uid'] : '',
            'entity_path'  => isset($entity_summary['entity_path']) ? (string) $entity_summary['entity_path'] : '',
            'entity_title' => isset($entity_summary['entity_title']) ? (string) $entity_summary['entity_title'] : '',
            'entity_type'  => isset($entity_summary['entity_type']) ? (string) $entity_summary['entity_type'] : '',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function empty_bricks_reference_summary(bool $include_entities = false): array
    {
        $summary = [
            'status'      => 'none',
            'total'       => 0,
            'resolved'    => 0,
            'unresolved'  => 0,
            'by_status'   => [],
            'items'       => [],
        ];

        if ($include_entities) {
            $summary['entities'] = [];
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private static function finalize_bricks_reference_summary(array $summary): array
    {
        $items = isset($summary['items']) && is_array($summary['items']) ? $summary['items'] : [];
        $summary['total'] = count($items);
        $summary['resolved'] = 0;
        $summary['unresolved'] = 0;
        $summary['by_status'] = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $status = isset($item['status']) ? sanitize_key((string) $item['status']) : 'unknown';
            if ($status === '') {
                $status = 'unknown';
            }

            if (! isset($summary['by_status'][$status])) {
                $summary['by_status'][$status] = 0;
            }
            $summary['by_status'][$status]++;

            if ($status === 'resolved') {
                $summary['resolved']++;
            } else {
                $summary['unresolved']++;
            }
        }

        $summary['status'] = self::bricks_reference_status_for_counts($summary);
        return $summary;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $entity_summary
     * @return array<string,mixed>
     */
    private static function merge_bricks_reference_summary(array $base, array $entity_summary): array
    {
        $base_items = isset($base['items']) && is_array($base['items']) ? $base['items'] : [];
        $entity_items = isset($entity_summary['items']) && is_array($entity_summary['items']) ? $entity_summary['items'] : [];
        $base['items'] = array_values(array_merge($base_items, $entity_items));

        return self::finalize_bricks_reference_summary($base);
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function bricks_reference_status_for_counts(array $summary): string
    {
        $total = isset($summary['total']) ? (int) $summary['total'] : 0;
        if ($total <= 0) {
            return 'none';
        }

        $unresolved = isset($summary['unresolved']) ? (int) $summary['unresolved'] : 0;
        return $unresolved > 0 ? 'warning' : 'ok';
    }

    private static function get_bricks_reference_unresolved_policy(): string
    {
        $policy = sanitize_key((string) get_option('dbvc_bricks_reference_unresolved_policy', 'warn'));
        if (! in_array($policy, ['warn', 'block'], true)) {
            $policy = 'warn';
        }

        $filtered = sanitize_key((string) apply_filters('dbvc_bricks_reference_unresolved_policy', $policy));
        return in_array($filtered, ['warn', 'block'], true) ? $filtered : $policy;
    }

    private static function maybe_block_proposal_apply_for_bricks_references(string $proposal_id)
    {
        if (self::get_bricks_reference_unresolved_policy() !== 'block') {
            return null;
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! is_array($manifest)) {
            return null;
        }

        $summary = self::build_manifest_bricks_reference_summary($manifest, $proposal_id, false);
        $unresolved = isset($summary['unresolved']) ? (int) $summary['unresolved'] : 0;
        if ($unresolved <= 0) {
            return null;
        }

        return new \WP_Error(
            'dbvc_bricks_reference_preflight_blocked',
            sprintf(
                _n(
                    'Proposal apply blocked: %d Bricks entity reference could not be resolved safely.',
                    'Proposal apply blocked: %d Bricks entity references could not be resolved safely.',
                    $unresolved,
                    'dbvc'
                ),
                $unresolved
            ),
            [
                'status'            => 409,
                'policy'            => 'block',
                'bricks_references' => $summary,
            ]
        );
    }

    /**
     * Build the authoritative readiness contract for Proposal Diff apply.
     *
     * This method intentionally lives in the proposal wrapper. Classic backup
     * restore, Entity Editor imports, and add-on apply pipelines do not opt in.
     *
     * @param string $proposal_id
     * @param array  $manifest
     * @param array  $options
     * @return array
     */
    public static function build_proposal_apply_gates(string $proposal_id, array $manifest, array $options = []): array
    {
        $decision_store = self::get_decision_store();
        $proposal_decisions = isset($decision_store[$proposal_id]) && is_array($decision_store[$proposal_id])
            ? $decision_store[$proposal_id]
            : [];
        $ignore_missing_hash = ! empty($options['ignore_missing_hash']);
        $blocking = [];
        $warnings = [];

        $duplicate_report = isset($options['duplicate_report']) && is_array($options['duplicate_report'])
            ? $options['duplicate_report']
            : self::build_manifest_duplicate_report($manifest);
        $duplicate_count = count($duplicate_report);
        if ($duplicate_count > 0) {
            $blocking[] = self::format_apply_gate_issue(
                'duplicates',
                $duplicate_count,
                sprintf(
                    _n(
                        'Resolve %d duplicate entity group before applying.',
                        'Resolve %d duplicate entity groups before applying.',
                        $duplicate_count,
                        'dbvc'
                    ),
                    $duplicate_count
                )
            );
        }

        $new_entities = isset($options['new_entities']) && is_array($options['new_entities'])
            ? $options['new_entities']
            : self::summarize_manifest_new_entities($manifest, $proposal_decisions, $proposal_id);
        $new_entity_pending = (int) ($new_entities['pending'] ?? 0);
        if ($new_entity_pending > 0) {
            $blocking[] = self::format_apply_gate_issue(
                'new_entities',
                $new_entity_pending,
                sprintf(
                    _n(
                        'Accept or decline %d new entity before applying.',
                        'Accept or decline %d new entities before applying.',
                        $new_entity_pending,
                        'dbvc'
                    ),
                    $new_entity_pending
                )
            );
        }

        $resolver = self::summarize_resolver_apply_readiness($proposal_id, $manifest, $options);
        if ($resolver['pending'] > 0) {
            $blocking[] = self::format_apply_gate_issue(
                'resolver',
                $resolver['pending'],
                sprintf(
                    _n(
                        'Resolve or skip %d media item before applying.',
                        'Resolve or skip %d media items before applying.',
                        $resolver['pending'],
                        'dbvc'
                    ),
                    $resolver['pending']
                )
            );
        }

        $masking = isset($options['masking']) && is_array($options['masking'])
            ? $options['masking']
            : self::summarize_masking_apply_readiness($proposal_id, $manifest, $proposal_decisions);
        if ($masking['pending'] > 0) {
            $blocking[] = self::format_apply_gate_issue(
                'masking',
                $masking['pending'],
                sprintf(
                    _n(
                        'Review %d configured masking field before applying.',
                        'Review %d configured masking fields before applying.',
                        $masking['pending'],
                        'dbvc'
                    ),
                    $masking['pending']
                )
            );
        }

        $field_decisions = isset($options['field_decisions']) && is_array($options['field_decisions'])
            ? $options['field_decisions']
            : self::summarize_field_decision_apply_readiness(
                $proposal_id,
                $manifest,
                $proposal_decisions,
                $masking['pending_paths']
            );
        if ($field_decisions['pending'] > 0) {
            $blocking[] = self::format_apply_gate_issue(
                'field_decisions',
                $field_decisions['pending'],
                sprintf(
                    _n(
                        'Review %d changed field before applying.',
                        'Review %d changed fields before applying.',
                        $field_decisions['pending'],
                        'dbvc'
                    ),
                    $field_decisions['pending']
                )
            );
        }

        $snapshots = self::summarize_snapshot_apply_readiness($proposal_id, $manifest);
        if ($snapshots['untrusted'] > 0) {
            $blocking[] = self::format_apply_gate_issue(
                'snapshots',
                $snapshots['untrusted'],
                sprintf(
                    _n(
                        '%d existing entity has no trusted comparison snapshot. Recapture it before applying.',
                        '%d existing entities have no trusted comparison snapshot. Recapture them before applying.',
                        $snapshots['untrusted'],
                        'dbvc'
                    ),
                    $snapshots['untrusted']
                )
            );
        }

        $unsupported_domains = self::summarize_unsupported_domain_apply_readiness($manifest);
        if ($unsupported_domains['blocked'] > 0) {
            $blocking[] = self::format_apply_gate_issue(
                'unsupported_domains',
                $unsupported_domains['blocked'],
                sprintf(
                    _n(
                        '%d writable non-post item is blocked because Proposal Review does not yet support trusted baselines and field decisions for options, option groups, or menus.',
                        '%d writable non-post items are blocked because Proposal Review does not yet support trusted baselines and field decisions for options, option groups, or menus.',
                        $unsupported_domains['blocked'],
                        'dbvc'
                    ),
                    $unsupported_domains['blocked']
                )
            );
        }

        $missing_hashes = isset($manifest['totals']['missing_import_hash'])
            ? max(0, (int) $manifest['totals']['missing_import_hash'])
            : 0;
        if ($missing_hashes > 0 && ! $ignore_missing_hash) {
            $blocking[] = self::format_apply_gate_issue(
                'hashes',
                $missing_hashes,
                sprintf(
                    _n(
                        '%d entity is missing an import hash. Use the hash override only after review.',
                        '%d entities are missing import hashes. Use the hash override only after review.',
                        $missing_hashes,
                        'dbvc'
                    ),
                    $missing_hashes
                )
            );
        }

        $permission_granted = array_key_exists('permission_granted', $options)
            ? ! empty($options['permission_granted'])
            : current_user_can('manage_options') || (defined('WP_CLI') && WP_CLI);
        $permission_denied = $permission_granted ? 0 : 1;
        if ($permission_denied) {
            $blocking[] = self::format_apply_gate_issue(
                'permissions',
                1,
                __('You do not have permission to apply this proposal.', 'dbvc')
            );
        }

        $override_tokens = [];
        if ($missing_hashes > 0) {
            $override_tokens[] = [
                'token'    => 'ignore_missing_hash',
                'category' => 'hashes',
                'active'   => $ignore_missing_hash,
                'message'  => __('Allow apply to continue without import hashes.', 'dbvc'),
            ];
        }

        $status_counts = self::build_canonical_status_counts(
            $field_decisions,
            $resolver,
            $masking,
            $duplicate_count,
            $new_entity_pending
        );

        return [
            'ready'           => empty($blocking),
            'blocking'        => array_values($blocking),
            'warnings'        => array_values($warnings),
            'status_counts'    => $status_counts,
            'counts'          => [
                'duplicates'     => [
                    'groups' => $duplicate_count,
                ],
                'resolver'       => $resolver,
                'masking'        => [
                    'total'    => $masking['total'],
                    'reviewed' => $masking['reviewed'],
                    'pending'  => $masking['pending'],
                ],
                'new_entities'   => $new_entities,
                'field_decisions'=> [
                    'total'    => (int) ($field_decisions['total'] ?? 0),
                    'reviewed' => (int) ($field_decisions['reviewed'] ?? 0),
                    'pending'  => (int) ($field_decisions['pending'] ?? 0),
                ],
                'snapshots'      => $snapshots,
                'unsupported_domains' => $unsupported_domains,
                'hashes'         => [
                    'missing'    => $missing_hashes,
                    'overridden' => $missing_hashes > 0 && $ignore_missing_hash,
                ],
                'permissions'    => [
                    'denied' => $permission_denied,
                ],
            ],
            'override_tokens' => $override_tokens,
        ];
    }

    private static function summarize_resolver_apply_readiness(string $proposal_id, array $manifest, array $options): array
    {
        $result = array_key_exists('resolver_result', $options) ? $options['resolver_result'] : null;
        $resolution_failed = false;

        if (! array_key_exists('resolver_result', $options) && class_exists('\Dbvc\Media\Resolver')) {
            try {
                $proposal_path = class_exists('DBVC_Backup_Manager')
                    ? trailingslashit(DBVC_Backup_Manager::get_base_path(false)) . $proposal_id
                    : '';
                $result = \Dbvc\Media\Resolver::resolve_manifest($manifest, [
                    'allow_remote' => false,
                    'dry_run'      => true,
                    'proposal_id'  => $proposal_id,
                    'bundle_meta'  => $manifest['media_bundle'] ?? [],
                    'manifest_dir' => $proposal_path,
                ]);
            } catch (\Throwable $e) {
                $result = null;
                $resolution_failed = true;
            }
        } elseif (! class_exists('\Dbvc\Media\Resolver')) {
            $resolution_failed = true;
        } elseif ($result === null) {
            $resolution_failed = true;
        }

        $attachments = is_array($result) && isset($result['attachments']) && is_array($result['attachments'])
            ? $result['attachments']
            : [];
        $metrics = is_array($result) && isset($result['metrics']) && is_array($result['metrics'])
            ? $result['metrics']
            : [];
        $total = max(count($attachments), (int) ($metrics['detected'] ?? 0));
        $pending = 0;
        $conflicts = 0;
        $resolved_by_decision = 0;

        foreach ($attachments as $resolution) {
            if (! is_array($resolution)) {
                $pending++;
                continue;
            }

            $status = isset($resolution['status']) ? (string) $resolution['status'] : 'unresolved';
            if (in_array($status, ['reused', 'downloaded'], true)) {
                continue;
            }

            $descriptor = isset($resolution['descriptor']) && is_array($resolution['descriptor'])
                ? $resolution['descriptor']
                : [];
            $original_id = isset($descriptor['original_id'])
                ? (string) absint($descriptor['original_id'])
                : (isset($resolution['original_id']) ? (string) absint($resolution['original_id']) : '');
            $decision = $original_id !== '' && $original_id !== '0'
                ? self::get_resolver_decision($proposal_id, $original_id)
                : null;

            if (self::resolver_decision_is_actionable($decision)) {
                $resolved_by_decision++;
            } else {
                $pending++;
                if (in_array($status, ['conflict', 'decision_failed'], true)) {
                    $conflicts++;
                }
            }
        }

        if (empty($attachments)) {
            $pending = max(0, (int) ($metrics['unresolved'] ?? 0));
            $conflicts = is_array($result) && isset($result['conflicts']) && is_array($result['conflicts'])
                ? count($result['conflicts'])
                : 0;
        }

        $manifest_media_total = isset($manifest['totals']['media_items'])
            ? max(0, (int) $manifest['totals']['media_items'])
            : (isset($manifest['media_index']) && is_array($manifest['media_index']) ? count($manifest['media_index']) : 0);
        $total = max($total, $manifest_media_total);
        if ($resolution_failed && $total > 0) {
            $pending = max($pending, $total);
        }

        return [
            'total'                => $total,
            'pending'              => $pending,
            'conflicts'            => min($pending, $conflicts),
            'resolved_by_decision' => $resolved_by_decision,
            'resolver_available'   => ! $resolution_failed,
        ];
    }

    private static function resolver_decision_is_actionable($decision): bool
    {
        if (! is_array($decision)) {
            return false;
        }

        $action = isset($decision['action']) ? sanitize_key($decision['action']) : '';
        if (in_array($action, ['skip', 'download'], true)) {
            return true;
        }

        if (! in_array($action, ['reuse', 'map'], true) || empty($decision['target_id'])) {
            return false;
        }

        if (class_exists('DBVC_Media_Sync')) {
            return DBVC_Media_Sync::is_valid_resolver_target($decision['target_id']);
        }

        return get_post_type(absint($decision['target_id'])) === 'attachment';
    }

    private static function summarize_masking_apply_readiness(
        string $proposal_id,
        array $manifest,
        array $proposal_decisions
    ): array {
        $fields = self::collect_masking_fields($proposal_id, $manifest, 1, 0);
        $pending = 0;
        $reviewed = 0;
        $pending_paths = [];
        $by_entity = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $vf_object_uid = isset($field['vf_object_uid']) ? (string) $field['vf_object_uid'] : '';
            $path = isset($field['meta_path']) ? (string) $field['meta_path'] : '';
            if ($vf_object_uid === '' || $path === '') {
                continue;
            }
            if (! isset($by_entity[$vf_object_uid])) {
                $by_entity[$vf_object_uid] = [
                    'total'    => 0,
                    'reviewed' => 0,
                    'pending'  => 0,
                ];
            }
            $by_entity[$vf_object_uid]['total']++;

            $entity_decisions = isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid])
                ? $proposal_decisions[$vf_object_uid]
                : [];
            if (self::decision_covers_apply_path($path, $entity_decisions)) {
                $reviewed++;
                $by_entity[$vf_object_uid]['reviewed']++;
                continue;
            }

            $pending++;
            $by_entity[$vf_object_uid]['pending']++;
            if (! isset($pending_paths[$vf_object_uid])) {
                $pending_paths[$vf_object_uid] = [];
            }
            $pending_paths[$vf_object_uid][] = $path;
        }

        return [
            'total'         => count($fields),
            'reviewed'      => $reviewed,
            'pending'       => $pending,
            'pending_paths' => $pending_paths,
            'by_entity'     => $by_entity,
        ];
    }

    private static function summarize_field_decision_apply_readiness(
        string $proposal_id,
        array $manifest,
        array $proposal_decisions,
        array $masking_pending_paths
    ): array {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $total = 0;
        $reviewed = 0;
        $pending = 0;
        $pending_by_section = [];
        $by_entity = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                continue;
            }

            $identity = $item_type === 'term'
                ? self::describe_term_identity($item)
                : self::describe_entity_identity($item);
            if (! empty($identity['is_new'])) {
                continue;
            }

            $vf_object_uid = self::get_manifest_item_uid($item);
            if ($vf_object_uid === '') {
                continue;
            }
            $entity_decisions = isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid])
                ? $proposal_decisions[$vf_object_uid]
                : [];
            if (! isset($by_entity[$vf_object_uid])) {
                $by_entity[$vf_object_uid] = [
                    'total'              => 0,
                    'reviewed'           => 0,
                    'pending'            => 0,
                    'pending_by_section' => [],
                ];
            }

            foreach (self::resolve_entity_diff_paths($proposal_id, $vf_object_uid, $item) as $path) {
                $section = self::determine_section($path);
                $total++;
                $by_entity[$vf_object_uid]['total']++;
                if (self::decision_covers_apply_path($path, $entity_decisions)) {
                    $reviewed++;
                    $by_entity[$vf_object_uid]['reviewed']++;
                    continue;
                }
                if (self::path_is_pending_masking_review($path, $masking_pending_paths[$vf_object_uid] ?? [])) {
                    continue;
                }
                $pending++;
                $pending_by_section[$section] = (int) ($pending_by_section[$section] ?? 0) + 1;
                $by_entity[$vf_object_uid]['pending']++;
                $by_entity[$vf_object_uid]['pending_by_section'][$section] =
                    (int) ($by_entity[$vf_object_uid]['pending_by_section'][$section] ?? 0) + 1;
            }
        }

        return [
            'total'              => $total,
            'reviewed'           => $reviewed,
            'pending'            => $pending,
            'pending_by_section' => $pending_by_section,
            'by_entity'          => $by_entity,
        ];
    }

    /**
     * Canonical Proposal Diff status counters.
     *
     * Legacy readiness groups remain available under counts. These scalar
     * counters give REST and UI consumers one stable vocabulary without
     * changing resolver, masking, or importer behavior.
     */
    private static function build_canonical_status_counts(
        array $field_decisions,
        array $resolver,
        array $masking,
        int $duplicate_count,
        int $new_entity_pending
    ): array {
        return [
            'field_needs_review'   => max(0, (int) ($field_decisions['pending'] ?? 0)),
            'meta_needs_review'    => max(0, (int) ($field_decisions['pending_by_section']['meta'] ?? 0)),
            'media_needs_review'   => max(0, (int) ($resolver['pending'] ?? 0)),
            'resolver_conflicts'   => max(0, (int) ($resolver['conflicts'] ?? 0)),
            'masking_candidates'   => max(0, (int) ($masking['pending'] ?? 0)),
            'duplicates'           => max(0, $duplicate_count),
            'new_entities_pending' => max(0, $new_entity_pending),
        ];
    }

    /**
     * Build the same canonical counters for one entity table/drawer row.
     */
    private static function build_entity_status_counts(
        array $field_decisions,
        array $masking,
        array $attachments,
        int $duplicate_count,
        bool $new_entity_pending
    ): array {
        $media_needs_review = 0;
        $resolver_conflicts = 0;
        $resolved_statuses = ['reused', 'mapped', 'downloaded', 'skipped', 'resolved'];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                $media_needs_review++;
                continue;
            }

            $status = sanitize_key((string) ($attachment['status'] ?? 'unknown'));
            if (in_array($status, $resolved_statuses, true)) {
                continue;
            }
            if (self::resolver_decision_is_actionable($attachment['decision'] ?? null)) {
                continue;
            }

            $media_needs_review++;
            if (in_array($status, ['conflict', 'decision_failed'], true)) {
                $resolver_conflicts++;
            }
        }

        return self::build_canonical_status_counts(
            $field_decisions,
            [
                'pending'   => $media_needs_review,
                'conflicts' => $resolver_conflicts,
            ],
            $masking,
            $duplicate_count,
            $new_entity_pending ? 1 : 0
        );
    }

    private static function count_duplicate_groups_for_item(array $item, array $duplicate_group_keys): int
    {
        $identity = self::resolve_manifest_duplicate_identity($item);
        if ($identity === null || empty($identity['group_key'])) {
            return 0;
        }

        return isset($duplicate_group_keys[(string) $identity['group_key']])
            ? (int) $duplicate_group_keys[(string) $identity['group_key']]
            : 0;
    }

    private static function entity_status_requires_review(
        array $status_counts,
        array $snapshot_status,
        array $diff_state
    ): bool {
        foreach ([
            'field_needs_review',
            'media_needs_review',
            'masking_candidates',
            'duplicates',
            'new_entities_pending',
        ] as $key) {
            if ((int) ($status_counts[$key] ?? 0) > 0) {
                return true;
            }
        }

        if (! empty($snapshot_status['required']) && empty($snapshot_status['trusted'])) {
            return true;
        }

        return in_array(
            (string) ($diff_state['reason'] ?? ''),
            ['missing_local_hash', 'missing_expected_hash'],
            true
        );
    }

    private static function entity_matches_status_filter(
        string $status_filter,
        array $status_counts,
        bool $needs_review,
        bool $is_new_entity,
        array $snapshot_status
    ): bool {
        switch ($status_filter) {
            case '':
            case 'all':
                return true;
            case 'needs_review':
                return $needs_review;
            case 'resolved':
                return ! $needs_review;
            case 'field_needs_review':
                return (int) ($status_counts['field_needs_review'] ?? 0) > 0;
            case 'meta_needs_review':
                return (int) ($status_counts['meta_needs_review'] ?? 0) > 0;
            case 'needs_review_media':
            case 'media_needs_review':
                return (int) ($status_counts['media_needs_review'] ?? 0) > 0;
            case 'resolver_conflicts':
                return (int) ($status_counts['resolver_conflicts'] ?? 0) > 0;
            case 'masking_candidates':
                return (int) ($status_counts['masking_candidates'] ?? 0) > 0;
            case 'duplicates':
                return (int) ($status_counts['duplicates'] ?? 0) > 0;
            case 'new_entities_pending':
                return (int) ($status_counts['new_entities_pending'] ?? 0) > 0;
            case 'new_entities':
                return $is_new_entity;
            case 'snapshot_needs_review':
                return ! empty($snapshot_status['required']) && empty($snapshot_status['trusted']);
            default:
                return true;
        }
    }

    private static function summarize_snapshot_apply_readiness(string $proposal_id, array $manifest): array
    {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $summary = [
            'required'      => 0,
            'available'     => 0,
            'captured'      => 0,
            'missing'       => 0,
            'stale'         => 0,
            'recapturing'   => 0,
            'failed'        => 0,
            'not_required'  => 0,
            'untrusted'     => 0,
            'recapturable'  => 0,
            'enforced'      => true,
        ];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $status = self::get_entity_snapshot_status($proposal_id, $item);
            $state = isset($status['state']) ? (string) $status['state'] : 'failed';

            if (empty($status['required'])) {
                $summary['not_required']++;
                continue;
            }

            $summary['required']++;
            if (! empty($status['available'])) {
                $summary['captured']++;
            }
            if (isset($summary[$state])) {
                $summary[$state]++;
            } else {
                $summary['failed']++;
            }
            if (empty($status['trusted'])) {
                $summary['untrusted']++;
                if (! empty($status['can_recapture'])) {
                    $summary['recapturable']++;
                }
            }
        }

        return $summary;
    }

    /**
     * Block writable domains that do not yet have Proposal Review decisions
     * or trusted current-site baselines. Their dedicated import paths remain
     * available outside the proposal apply wrapper.
     */
    private static function summarize_unsupported_domain_apply_readiness(array $manifest): array
    {
        $type_counts = [
            'options'       => 0,
            'options_group' => 0,
            'menus'         => 0,
        ];
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (array_key_exists($item_type, $type_counts)) {
                $type_counts[$item_type]++;
            }
        }

        $blocked = array_sum($type_counts);

        return [
            'total'              => $blocked,
            'blocked'            => $blocked,
            'types'              => $type_counts,
            'review_supported'   => false,
            'baseline_supported' => false,
            'enforced'           => true,
        ];
    }

    /**
     * Derive one entity's snapshot trust state from identity, disk, and the
     * latest explicit capture outcome.
     */
    private static function get_entity_snapshot_status(string $proposal_id, array $item, ?array $identity = null): array
    {
        $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
        $vf_object_uid = self::get_manifest_item_uid($item);

        if (! in_array($item_type, ['post', 'term'], true)) {
            return [
                'state'           => 'not_required',
                'required'        => false,
                'available'       => false,
                'trusted'         => true,
                'can_recapture'   => false,
                'captured_at'     => null,
                'message'         => __('Snapshots are not required for this entity type.', 'dbvc'),
                'entity_type'     => $item_type,
                'vf_object_uid'   => $vf_object_uid,
            ];
        }

        if ($identity === null) {
            $identity = $item_type === 'term'
                ? self::describe_term_identity($item)
                : self::describe_entity_identity($item);
        }

        if (! empty($identity['is_new'])) {
            return [
                'state'           => 'not_required',
                'required'        => false,
                'available'       => false,
                'trusted'         => true,
                'can_recapture'   => false,
                'captured_at'     => null,
                'message'         => __('This is a new entity, so no current-site snapshot is required.', 'dbvc'),
                'entity_type'     => $item_type,
                'vf_object_uid'   => $vf_object_uid,
            ];
        }

        $local_entity_id = isset($identity['local_post_id']) ? (int) $identity['local_post_id'] : 0;
        $can_recapture = class_exists('DBVC_Snapshot_Manager') && $vf_object_uid !== '' && $local_entity_id > 0;
        $term_taxonomy = $item_type === 'term'
            ? (isset($item['term_taxonomy'])
                ? sanitize_key($item['term_taxonomy'])
                : (isset($item['taxonomy']) ? sanitize_key($item['taxonomy']) : ''))
            : '';
        if ($item_type === 'term' && $term_taxonomy === '') {
            $can_recapture = false;
        }
        $stored = self::get_snapshot_state_entry($proposal_id, $vf_object_uid);
        $stored_state = isset($stored['state']) ? (string) $stored['state'] : '';
        $stored_timestamp = isset($stored['updated_timestamp']) ? (int) $stored['updated_timestamp'] : 0;
        $recapture_active = $stored_state === 'recapturing'
            && $stored_timestamp > 0
            && (time() - $stored_timestamp) < 300;

        if ($recapture_active) {
            return [
                'state'           => 'recapturing',
                'required'        => true,
                'available'       => false,
                'trusted'         => false,
                'can_recapture'   => false,
                'captured_at'     => null,
                'message'         => $stored['message'] ?? __('Snapshot recapture is in progress.', 'dbvc'),
                'entity_type'     => $item_type,
                'vf_object_uid'   => $vf_object_uid,
                'updated_at'      => $stored['updated_at'] ?? null,
            ];
        }

        if (! $can_recapture) {
            return [
                'state'           => 'failed',
                'required'        => true,
                'available'       => false,
                'trusted'         => false,
                'can_recapture'   => false,
                'captured_at'     => null,
                'message'         => $vf_object_uid === ''
                    ? __('The entity has no stable UID for snapshot storage.', 'dbvc')
                    : ($item_type === 'term' && $term_taxonomy === ''
                        ? __('The term taxonomy is missing from the proposal manifest.', 'dbvc')
                        : __('The snapshot manager or local entity is unavailable.', 'dbvc')),
                'entity_type'     => $item_type,
                'vf_object_uid'   => $vf_object_uid,
            ];
        }

        try {
            if ($item_type === 'term') {
                $inspection = DBVC_Snapshot_Manager::inspect_term_snapshot($proposal_id, $local_entity_id, $term_taxonomy, $vf_object_uid);
            } else {
                $inspection = DBVC_Snapshot_Manager::inspect_post_snapshot($proposal_id, $local_entity_id, $vf_object_uid);
            }
        } catch (\Throwable $e) {
            $inspection = [
                'exists'      => false,
                'valid'       => false,
                'stale'       => false,
                'captured_at' => null,
                'message'     => $e->getMessage(),
            ];
        }

        $state = 'available';
        $message = isset($inspection['message']) ? (string) $inspection['message'] : '';
        if (empty($inspection['exists'])) {
            $state = in_array($stored_state, ['failed', 'recapturing'], true) ? 'failed' : 'missing';
            if ($stored_state === 'recapturing' && ! $recapture_active) {
                $message = __('The previous snapshot recapture did not complete.', 'dbvc');
            } elseif ($stored_state === 'failed' && ! empty($stored['message'])) {
                $message = (string) $stored['message'];
            }
        } elseif (empty($inspection['valid'])) {
            $state = 'failed';
        } elseif (! empty($inspection['stale'])) {
            $state = 'stale';
        }

        return [
            'state'           => $state,
            'required'        => true,
            'available'       => ! empty($inspection['exists']) && ! empty($inspection['valid']),
            'trusted'         => $state === 'available',
            'can_recapture'   => true,
            'captured_at'     => $inspection['captured_at'] ?? null,
            'message'         => $message,
            'entity_type'     => $item_type,
            'vf_object_uid'   => $vf_object_uid,
            'updated_at'      => $stored['updated_at'] ?? null,
            'failure_code'    => $stored['code'] ?? null,
        ];
    }

    private static function get_snapshot_state_entry(string $proposal_id, string $vf_object_uid): array
    {
        $store = get_option(self::SNAPSHOT_STATES_OPTION, []);
        if (! is_array($store) || ! isset($store[$proposal_id][$vf_object_uid]) || ! is_array($store[$proposal_id][$vf_object_uid])) {
            return [];
        }

        return $store[$proposal_id][$vf_object_uid];
    }

    private static function set_snapshot_state_entry(string $proposal_id, string $vf_object_uid, string $state, string $message = '', string $code = ''): void
    {
        if ($proposal_id === '' || $vf_object_uid === '') {
            return;
        }

        $store = get_option(self::SNAPSHOT_STATES_OPTION, []);
        $store = is_array($store) ? $store : [];
        if (! isset($store[$proposal_id]) || ! is_array($store[$proposal_id])) {
            $store[$proposal_id] = [];
        }
        $store[$proposal_id][$vf_object_uid] = [
            'state'             => in_array($state, ['recapturing', 'failed'], true) ? $state : 'failed',
            'message'           => sanitize_text_field($message),
            'code'              => sanitize_key($code),
            'updated_at'        => current_time('mysql', true),
            'updated_timestamp' => time(),
        ];
        update_option(self::SNAPSHOT_STATES_OPTION, $store, false);
    }

    private static function clear_snapshot_state_entry(string $proposal_id, string $vf_object_uid = ''): void
    {
        $store = get_option(self::SNAPSHOT_STATES_OPTION, []);
        if (! is_array($store) || ! isset($store[$proposal_id])) {
            return;
        }

        if ($vf_object_uid === '') {
            unset($store[$proposal_id]);
        } else {
            unset($store[$proposal_id][$vf_object_uid]);
            if (empty($store[$proposal_id])) {
                unset($store[$proposal_id]);
            }
        }

        if (empty($store)) {
            delete_option(self::SNAPSHOT_STATES_OPTION);
        } else {
            update_option(self::SNAPSHOT_STATES_OPTION, $store, false);
        }
    }

    private static function decision_covers_apply_path(string $path, array $decisions): bool
    {
        $path_aliases = self::get_apply_path_aliases($path);
        foreach ($decisions as $decision_path => $action) {
            if (! is_string($decision_path) || ! in_array($action, ['accept', 'keep'], true)) {
                continue;
            }
            foreach (self::get_apply_path_aliases($decision_path) as $decision_alias) {
                foreach ($path_aliases as $path_alias) {
                    if (
                        $decision_alias === $path_alias
                        || strpos($path_alias, $decision_alias . '.') === 0
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function remove_overlapping_decisions(array $decisions, string $path): array
    {
        foreach (array_keys($decisions) as $existing_path) {
            if (
                ! is_string($existing_path)
                || $existing_path === self::NEW_ENTITY_DECISION_KEY
                || ! self::decision_paths_overlap($existing_path, $path)
            ) {
                continue;
            }
            unset($decisions[$existing_path]);
        }

        return $decisions;
    }

    private static function decision_paths_overlap(string $left, string $right): bool
    {
        $term_aliases = [
            'term_name'   => 'name',
            'term_slug'   => 'slug',
            'parent_slug' => 'parent',
            'parent_uid'  => 'parent',
        ];
        $left = $term_aliases[$left] ?? $left;
        $right = $term_aliases[$right] ?? $right;
        if (strpos($left, 'taxonomies.') === 0) {
            $left = 'tax_input.' . substr($left, 11);
        }
        if (strpos($right, 'taxonomies.') === 0) {
            $right = 'tax_input.' . substr($right, 11);
        }

        foreach (self::get_apply_path_aliases($left) as $left_alias) {
            foreach (self::get_apply_path_aliases($right) as $right_alias) {
                if (
                    $left_alias === $right_alias
                    || strpos($left_alias, $right_alias . '.') === 0
                    || strpos($right_alias, $left_alias . '.') === 0
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function get_apply_path_aliases(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [];
        }

        $aliases = [$path];
        if (strpos($path, 'post.') === 0) {
            $aliases[] = substr($path, 5);
        } elseif (strpos($path, '.') === false && in_array($path, self::$post_apply_fields, true)) {
            $aliases[] = 'post.' . $path;
        }

        return array_values(array_unique(array_filter($aliases)));
    }

    private static function path_is_pending_masking_review(string $path, array $masking_paths): bool
    {
        foreach ($masking_paths as $masking_path) {
            $masking_aliases = self::get_apply_path_aliases((string) $masking_path);
            foreach (self::get_apply_path_aliases($path) as $path_alias) {
                foreach ($masking_aliases as $masking_alias) {
                    if (
                        $path_alias === $masking_alias
                        || strpos($path_alias, $masking_alias . '.') === 0
                        || strpos($masking_alias, $path_alias . '.') === 0
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function get_manifest_item_uid(array $item): string
    {
        if (! empty($item['vf_object_uid'])) {
            return (string) $item['vf_object_uid'];
        }
        if (isset($item['post_id'])) {
            return (string) $item['post_id'];
        }
        if (isset($item['term_id'])) {
            return (string) $item['term_id'];
        }
        return '';
    }

    private static function format_apply_gate_issue(string $category, int $count, string $message): array
    {
        return [
            'category' => $category,
            'count'    => max(0, $count),
            'message'  => $message,
        ];
    }

    private static function sanitize_boolean($value): bool
    {
        if (function_exists('rest_sanitize_boolean')) {
            return rest_sanitize_boolean($value);
        }

        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    /**
     * Build one canonical duplicate inventory for every proposal surface.
     *
     * Internal item/index fields let cleanup mutate the exact manifest entries;
     * build_manifest_duplicate_report() removes those fields before REST output.
     */
    private static function detect_manifest_duplicate_groups(array $manifest): array
    {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $groups = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $path = isset($item['path']) ? ltrim((string) $item['path'], '/\\') : '';
            $identity = self::resolve_manifest_duplicate_identity($item);
            if ($path === '' || $identity === null) {
                continue;
            }

            $group_key = $identity['group_key'];
            $item_type = $identity['entity_type'];
            $entity_uid = $identity['display_value'];
            if (! isset($groups[$group_key])) {
                $taxonomy = '';
                if ($item_type === 'term') {
                    $taxonomy = isset($item['term_taxonomy']) ? (string) $item['term_taxonomy'] : (isset($item['taxonomy']) ? (string) $item['taxonomy'] : '');
                }

                $groups[$group_key] = [
                    'duplicate_id' => 'duplicate-' . substr(hash('sha256', $group_key), 0, 20),
                    'vf_object_uid' => $entity_uid,
                    'entity_type'   => $item_type,
                    'entity_domain' => $identity['entity_domain'],
                    'identity'      => [
                        'kind'  => $identity['kind'],
                        'value' => $identity['value'],
                    ],
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
                    '_identity_key' => $group_key,
                ];
            }

            $path_occurrence = 1;
            foreach ($groups[$group_key]['entries'] as $existing_entry) {
                if (($existing_entry['path'] ?? '') === $path) {
                    $path_occurrence++;
                }
            }
            $entry_id = 'entry-' . substr(
                hash('sha256', $group_key . '|' . $path . '|' . $path_occurrence),
                0,
                20
            );

            $groups[$group_key]['entries'][] = [
                'entry_id'      => $entry_id,
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
                '_manifest_index' => $index,
                '_item'           => $item,
            ];
        }

        return array_values(array_filter($groups, static function ($group) {
            return isset($group['entries']) && count($group['entries']) > 1;
        }));
    }

    private static function resolve_manifest_duplicate_identity(array $item): ?array
    {
        $item_type = sanitize_key((string) ($item['item_type'] ?? 'post'));
        if ($item_type === '') {
            $item_type = 'post';
        }

        $entity_domain = $item_type;
        if ($item_type === 'term') {
            $taxonomy = sanitize_key((string) ($item['term_taxonomy'] ?? $item['taxonomy'] ?? ''));
            if ($taxonomy !== '') {
                $entity_domain .= ':' . $taxonomy;
            }
        } elseif ($item_type === 'post') {
            $post_type = sanitize_key((string) ($item['post_type'] ?? ''));
            if ($post_type !== '') {
                $entity_domain .= ':' . $post_type;
            }
        } else {
            $subtype = sanitize_key((string) ($item['entity_type'] ?? $item['provider'] ?? $item['subtype'] ?? ''));
            if ($subtype !== '' && $subtype !== $item_type) {
                $entity_domain .= ':' . $subtype;
            }
        }

        $kind = '';
        $value = '';
        $uid = trim((string) ($item['vf_object_uid'] ?? ''));
        if ($uid !== '') {
            $kind = 'uid';
            $value = $uid;
        } elseif ($item_type === 'term' && (int) ($item['term_id'] ?? 0) > 0) {
            $kind = 'term_id';
            $value = (string) (int) $item['term_id'];
        } elseif ($item_type === 'post' && (int) ($item['post_id'] ?? 0) > 0) {
            $kind = 'post_id';
            $value = (string) (int) $item['post_id'];
        } elseif ((string) ($item['entity_id'] ?? '') !== '') {
            $kind = 'entity_id';
            $value = trim((string) $item['entity_id']);
        } else {
            $slug = $item_type === 'term'
                ? (string) ($item['term_slug'] ?? $item['slug'] ?? '')
                : (string) ($item['post_name'] ?? $item['slug'] ?? '');
            $slug = sanitize_title($slug);
            if ($slug !== '') {
                $kind = 'slug';
                $value = $slug;
            }
        }

        if ($kind === '' || $value === '') {
            return null;
        }

        $identity_scope = $kind === 'slug' ? $entity_domain : $item_type;
        $group_key = $identity_scope . '|' . $kind . '|' . $value;

        return [
            'group_key'    => $group_key,
            'entity_type'  => $item_type,
            'entity_domain'=> $entity_domain,
            'kind'         => $kind,
            'value'        => $value,
            'display_value'=> $uid !== '' ? $uid : $kind . ':' . $value,
        ];
    }

    private static function build_manifest_duplicate_report(array $manifest): array
    {
        $report = self::detect_manifest_duplicate_groups($manifest);
        foreach ($report as &$group) {
            unset($group['_identity_key']);
            foreach ($group['entries'] as &$entry) {
                unset($entry['_manifest_index'], $entry['_item']);
            }
            unset($entry);
        }
        unset($group);

        return $report;
    }

    private static function determine_duplicate_keep_entry(array $entries, string $preferred_format): ?array
    {
        if (empty($entries)) {
            return null;
        }

        $allowed = ['slug_id', 'slug', 'id'];
        $preferred = in_array($preferred_format, $allowed, true)
            ? array_merge([$preferred_format], array_diff($allowed, [$preferred_format]))
            : $allowed;

        $entries_by_mode = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $mode = isset($entry['filename_mode']) ? (string) $entry['filename_mode'] : '';
            if ($mode === '' && isset($entry['_item'], $entry['path']) && is_array($entry['_item'])) {
                $mode = (string) self::detect_manifest_entry_filename_mode($entry['_item'], (string) $entry['path']);
            }
            if ($mode !== '' && ! isset($entries_by_mode[$mode])) {
                $entries_by_mode[$mode] = $entry;
            }
        }

        foreach ($preferred as $mode) {
            if (isset($entries_by_mode[$mode])) {
                return $entries_by_mode[$mode];
            }
        }

        $latest_entry = null;
        $latest_stamp = 0;
        foreach ($entries as $entry) {
            $modified = (string) ($entry['post_modified'] ?? ($entry['_item']['post_modified'] ?? ''));
            if ($modified === '') {
                continue;
            }
            $timestamp = strtotime($modified);
            if ($timestamp && $timestamp > $latest_stamp) {
                $latest_stamp = $timestamp;
                $latest_entry = $entry;
            }
        }

        if (is_array($latest_entry)) {
            return $latest_entry;
        }

        return is_array($entries[0] ?? null) ? $entries[0] : null;
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
        $base_real = realpath($base_dir);
        if ($base_real === false || ! is_dir($base_real)) {
            return null;
        }
        $base_real = wp_normalize_path($base_real);

        $relative_path = str_replace('\\', '/', ltrim($relative_path, '/\\'));
        $segments = explode('/', $relative_path);
        if (
            $relative_path === ''
            || strpos($relative_path, "\0") !== false
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            return null;
        }

        $base_prefix = trailingslashit($base_real);
        $absolute = $base_prefix . $relative_path;
        $real     = realpath($absolute);
        if ($real !== false) {
            $real = wp_normalize_path($real);
            return strpos($real, $base_prefix) === 0 ? $real : null;
        }

        $parent_real = realpath(dirname($absolute));
        if ($parent_real === false) {
            return null;
        }
        $parent_real = wp_normalize_path($parent_real);
        if (strpos(trailingslashit($parent_real), $base_prefix) !== 0) {
            return null;
        }

        return trailingslashit($parent_real) . basename($absolute);
    }

    /**
     * Remove duplicate payloads and replace the manifest as one recoverable operation.
     *
     * @param string   $base_dir
     * @param array    $manifest
     * @param string[] $relative_paths
     * @return array|\WP_Error
     */
    private static function commit_duplicate_cleanup_transaction(
        string $base_dir,
        array $manifest,
        array $relative_paths
    ) {
        $base_real = realpath($base_dir);
        if ($base_real === false || ! is_dir($base_real)) {
            return new \WP_Error(
                'dbvc_duplicate_cleanup_path_invalid',
                __('Proposal directory could not be resolved.', 'dbvc'),
                ['status' => 500]
            );
        }

        $manifest_path = trailingslashit($base_real) . DBVC_Backup_Manager::MANIFEST_FILENAME;
        if (! is_file($manifest_path)) {
            return new \WP_Error(
                'dbvc_duplicate_cleanup_manifest_missing',
                __('Proposal manifest could not be found for duplicate cleanup.', 'dbvc'),
                ['status' => 500]
            );
        }

        $encoded_manifest = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded_manifest) || $encoded_manifest === '') {
            return new \WP_Error(
                'dbvc_duplicate_cleanup_manifest_encode_failed',
                __('Proposal manifest could not be prepared for duplicate cleanup.', 'dbvc'),
                ['status' => 500]
            );
        }

        $token = function_exists('wp_generate_uuid4')
            ? str_replace('-', '', wp_generate_uuid4())
            : str_replace('.', '', uniqid('', true));
        $quarantine_dir = trailingslashit($base_real) . '.dbvc-duplicate-cleanup-' . $token;
        $temp_manifest = trailingslashit($base_real) . '.manifest.json.tmp-' . $token;
        $warnings = [];
        $validated = [];

        foreach (array_values(array_unique($relative_paths)) as $relative_path) {
            $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/\\'));
            $absolute = self::resolve_manifest_entry_path($base_real, $relative_path);
            if ($absolute === null || $absolute === $manifest_path) {
                return new \WP_Error(
                    'dbvc_duplicate_cleanup_payload_path_invalid',
                    __('A duplicate payload path is outside the proposal directory.', 'dbvc'),
                    [
                        'status' => 400,
                        'path'   => $relative_path,
                    ]
                );
            }
            if (! file_exists($absolute) && ! is_link($absolute)) {
                $warnings[] = sprintf(
                    __('Duplicate payload was already missing: %s', 'dbvc'),
                    $relative_path
                );
                continue;
            }
            if (! is_file($absolute) && ! is_link($absolute)) {
                return new \WP_Error(
                    'dbvc_duplicate_cleanup_payload_type_invalid',
                    __('Duplicate cleanup only removes payload files.', 'dbvc'),
                    [
                        'status' => 400,
                        'path'   => $relative_path,
                    ]
                );
            }
            $validated[] = [
                'relative_path' => $relative_path,
                'source'        => $absolute,
                'quarantine'    => trailingslashit($quarantine_dir)
                    . substr(hash('sha256', $relative_path), 0, 20)
                    . '-'
                    . basename($relative_path),
            ];
        }

        if (! empty($validated) && ! wp_mkdir_p($quarantine_dir)) {
            return new \WP_Error(
                'dbvc_duplicate_cleanup_quarantine_failed',
                __('Duplicate cleanup could not create its recovery directory.', 'dbvc'),
                ['status' => 500]
            );
        }

        $moved = [];
        foreach ($validated as $file) {
            if (! @rename($file['source'], $file['quarantine'])) {
                $rollback_failures = self::rollback_duplicate_cleanup_files($moved);
                self::delete_directory_recursive($quarantine_dir);
                return new \WP_Error(
                    'dbvc_duplicate_cleanup_payload_move_failed',
                    __('Duplicate cleanup could not stage every payload file.', 'dbvc'),
                    [
                        'status'            => 500,
                        'path'              => $file['relative_path'],
                        'rollback_failures' => $rollback_failures,
                    ]
                );
            }
            $moved[] = $file;
        }

        $written = @file_put_contents($temp_manifest, $encoded_manifest, LOCK_EX);
        if ($written === false || $written !== strlen($encoded_manifest)) {
            @unlink($temp_manifest);
            $rollback_failures = self::rollback_duplicate_cleanup_files($moved);
            self::delete_directory_recursive($quarantine_dir);
            return new \WP_Error(
                'dbvc_duplicate_cleanup_manifest_write_failed',
                __('Duplicate cleanup could not write the updated proposal manifest.', 'dbvc'),
                [
                    'status'            => 500,
                    'rollback_failures' => $rollback_failures,
                ]
            );
        }

        $manifest_permissions = @fileperms($manifest_path);
        if (is_int($manifest_permissions)) {
            @chmod($temp_manifest, $manifest_permissions & 0777);
        }

        if (! @rename($temp_manifest, $manifest_path)) {
            @unlink($temp_manifest);
            $rollback_failures = self::rollback_duplicate_cleanup_files($moved);
            self::delete_directory_recursive($quarantine_dir);
            return new \WP_Error(
                'dbvc_duplicate_cleanup_manifest_commit_failed',
                __('Duplicate cleanup could not replace the proposal manifest.', 'dbvc'),
                [
                    'status'            => 500,
                    'rollback_failures' => $rollback_failures,
                ]
            );
        }

        self::delete_directory_recursive($quarantine_dir);
        if (file_exists($quarantine_dir)) {
            $warnings[] = __('Duplicate payloads were removed from the proposal but recovery files could not be deleted.', 'dbvc');
        }

        return [
            'removed_files' => count($moved),
            'warnings'      => $warnings,
        ];
    }

    /**
     * Restore files moved to the cleanup quarantine.
     *
     * @param array[] $moved
     * @return string[]
     */
    private static function rollback_duplicate_cleanup_files(array $moved): array
    {
        $failures = [];
        foreach (array_reverse($moved) as $file) {
            if (! isset($file['source'], $file['quarantine'])) {
                continue;
            }
            if (! @rename($file['quarantine'], $file['source'])) {
                $failures[] = (string) ($file['relative_path'] ?? $file['source']);
            }
        }

        return $failures;
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
                $entity_store = self::remove_overlapping_decisions($entity_store, $path);
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
            $vf_object_uid = self::get_manifest_item_uid($item);
            if ($vf_object_uid !== '') {
                $manifest_map[$vf_object_uid] = $item;
            }
        }

        $decision_store = self::get_decision_store();
        $proposal_decisions = isset($decision_store[$proposal_id]) && is_array($decision_store[$proposal_id])
            ? $decision_store[$proposal_id]
            : [];
        $target_ids = [];
        if ($scope === 'new_only') {
            foreach ($manifest_map as $uid => $item) {
                $identity = self::describe_entity_identity($item);
                $entity_decisions = isset($proposal_decisions[$uid]) && is_array($proposal_decisions[$uid])
                    ? $proposal_decisions[$uid]
                    : [];
                $new_entity_state = self::normalize_new_entity_state(
                    self::get_new_entity_decision($proposal_id, $uid, $entity_decisions)
                );
                if ($identity['is_new'] && $new_entity_state === 'pending_new') {
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
            $vf_object_uid = self::get_manifest_item_uid($item);
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
            $vf_object_uid = self::get_manifest_item_uid($item);
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
     * Capture current snapshots for selected existing proposal entities.
     *
     * @param string $proposal_id
     * @param array  $manifest
     * @param array  $entity_ids Empty captures every supported existing entity.
     * @return array
     */
    public static function recapture_proposal_snapshots(string $proposal_id, array $manifest, array $entity_ids = []): array
    {
        $entity_ids = array_values(array_unique(array_filter(array_map('sanitize_text_field', $entity_ids))));
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        $capture_enabled = class_exists('DBVC_Snapshot_Manager')
            && apply_filters('dbvc_enable_snapshot_capture', true, $proposal_id, $manifest);
        $summary = [
            'proposal_id'  => $proposal_id,
            'targets'      => 0,
            'captured'     => 0,
            'failed'       => 0,
            'not_required' => 0,
            'skipped'      => 0,
            'results'      => [],
        ];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $vf_object_uid = self::get_manifest_item_uid($item);
            if ($vf_object_uid === '' || (! empty($entity_ids) && ! in_array($vf_object_uid, $entity_ids, true))) {
                continue;
            }

            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                $summary['skipped']++;
                continue;
            }

            $identity = $item_type === 'term'
                ? self::describe_term_identity($item)
                : self::describe_entity_identity($item);
            if (! empty($identity['is_new'])) {
                $summary['not_required']++;
                $summary['results'][] = [
                    'vf_object_uid' => $vf_object_uid,
                    'entity_type'   => $item_type,
                    'state'         => 'not_required',
                    'message'       => __('New entities do not need current-site snapshots.', 'dbvc'),
                ];
                continue;
            }

            $summary['targets']++;
            self::set_snapshot_state_entry(
                $proposal_id,
                $vf_object_uid,
                'recapturing',
                __('Snapshot recapture is in progress.', 'dbvc')
            );

            if (! $capture_enabled) {
                $message = class_exists('DBVC_Snapshot_Manager')
                    ? __('Snapshot capture is disabled by the dbvc_enable_snapshot_capture filter.', 'dbvc')
                    : __('Snapshot manager is unavailable.', 'dbvc');
                self::set_snapshot_state_entry($proposal_id, $vf_object_uid, 'failed', $message, 'capture_disabled');
                $summary['failed']++;
                $summary['results'][] = [
                    'vf_object_uid' => $vf_object_uid,
                    'entity_type'   => $item_type,
                    'state'         => 'failed',
                    'code'          => 'capture_disabled',
                    'message'       => $message,
                ];
                continue;
            }

            $local_entity_id = isset($identity['local_post_id']) ? (int) $identity['local_post_id'] : 0;
            try {
                if ($item_type === 'term') {
                    $taxonomy = isset($item['term_taxonomy'])
                        ? sanitize_key($item['term_taxonomy'])
                        : (isset($item['taxonomy']) ? sanitize_key($item['taxonomy']) : '');
                    $capture = $taxonomy !== ''
                        ? DBVC_Snapshot_Manager::capture_term_snapshot_result($proposal_id, $local_entity_id, $taxonomy, $vf_object_uid)
                        : new \WP_Error('dbvc_snapshot_taxonomy_missing', __('The term taxonomy is missing from the proposal manifest.', 'dbvc'));
                } else {
                    $capture = DBVC_Snapshot_Manager::capture_post_snapshot_result($proposal_id, $local_entity_id, $vf_object_uid);
                }
            } catch (\Throwable $e) {
                $capture = new \WP_Error('dbvc_snapshot_failed', $e->getMessage());
            }

            if (is_wp_error($capture)) {
                $message = $capture->get_error_message();
                $code = $capture->get_error_code();
                self::set_snapshot_state_entry($proposal_id, $vf_object_uid, 'failed', $message, $code);
                $summary['failed']++;
                $summary['results'][] = [
                    'vf_object_uid' => $vf_object_uid,
                    'entity_type'   => $item_type,
                    'state'         => 'failed',
                    'code'          => $code,
                    'message'       => $message,
                ];
                continue;
            }

            self::clear_snapshot_state_entry($proposal_id, $vf_object_uid);
            $status = self::get_entity_snapshot_status($proposal_id, $item, $identity);
            if (($status['state'] ?? '') !== 'available') {
                $summary['failed']++;
                $summary['results'][] = array_merge([
                    'vf_object_uid' => $vf_object_uid,
                    'entity_type'   => $item_type,
                ], $status);
                continue;
            }

            self::rebuild_entity_decisions_for_manifest_item($proposal_id, $vf_object_uid, $item);
            $summary['captured']++;
            $summary['results'][] = array_merge([
                'vf_object_uid' => $vf_object_uid,
                'entity_type'   => $item_type,
            ], $status);
        }

        $summary['snapshot_readiness'] = self::summarize_snapshot_apply_readiness($proposal_id, $manifest);
        self::log_snapshot_capture_result($summary);

        return $summary;
    }

    /**
     * REST: capture snapshot for a single entity on demand.
     */
    public static function capture_entity_snapshot(\WP_REST_Request $request)
    {
        $proposal_id   = sanitize_text_field($request->get_param('proposal_id'));
        $vf_object_uid = sanitize_text_field($request->get_param('vf_object_uid'));
        $manifest      = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_Error('dbvc_manifest_missing', __('Proposal manifest could not be found.', 'dbvc'), ['status' => 404]);
        }

        $matched = false;
        foreach ((array) ($manifest['items'] ?? []) as $item) {
            if (is_array($item) && self::get_manifest_item_uid($item) === $vf_object_uid) {
                $matched = true;
                break;
            }
        }
        if (! $matched) {
            return new \WP_Error('dbvc_invalid_entity', __('Entity is not part of this proposal.', 'dbvc'), ['status' => 404]);
        }

        $result = self::recapture_proposal_snapshots($proposal_id, $manifest, [$vf_object_uid]);
        $entity_result = isset($result['results'][0]) && is_array($result['results'][0]) ? $result['results'][0] : [];
        if (($entity_result['state'] ?? '') === 'not_required') {
            return new \WP_Error('dbvc_snapshot_not_required', $entity_result['message'], [
                'status'  => 400,
                'capture' => $result,
            ]);
        }
        if (($entity_result['state'] ?? '') !== 'available') {
            return new \WP_Error('dbvc_snapshot_failed', $entity_result['message'] ?? __('Snapshot capture failed.', 'dbvc'), [
                'status'  => 500,
                'capture' => $result,
            ]);
        }

        return new \WP_REST_Response([
            'proposal_id'    => $proposal_id,
            'vf_object_uid'  => $vf_object_uid,
            'snapshot'       => DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid),
            'snapshot_state' => 'available',
            'snapshot_status'=> $entity_result,
            'capture'        => $result,
        ]);
    }

    /**
     * REST: capture snapshots for multiple entities within a proposal.
     */
    public static function capture_proposal_snapshot(\WP_REST_Request $request)
    {
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

        if (empty($manifest['items']) || ! is_array($manifest['items'])) {
            return new \WP_Error('dbvc_manifest_empty', __('Proposal contains no entities to snapshot.', 'dbvc'), ['status' => 400]);
        }

        return new \WP_REST_Response(self::recapture_proposal_snapshots($proposal_id, $manifest, $entity_ids));
    }

    private static function log_snapshot_capture_result(array $result): void
    {
        $failed = isset($result['failed']) ? (int) $result['failed'] : 0;
        $context = [
            'proposal_id' => $result['proposal_id'] ?? '',
            'targets'     => isset($result['targets']) ? (int) $result['targets'] : 0,
            'captured'    => isset($result['captured']) ? (int) $result['captured'] : 0,
            'failed'      => $failed,
            'not_required'=> isset($result['not_required']) ? (int) $result['not_required'] : 0,
        ];

        if (class_exists('DBVC_Sync_Logger')) {
            DBVC_Sync_Logger::log(
                $failed > 0 ? 'Proposal snapshot capture completed with failures' : 'Proposal snapshot capture completed',
                $context
            );
        }
        if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
            DBVC_Database::log_activity(
                $failed > 0 ? 'proposal_snapshot_capture_failed' : 'proposal_snapshot_capture_completed',
                $failed > 0 ? 'warning' : 'info',
                $failed > 0 ? 'Proposal snapshot capture completed with failures.' : 'Proposal snapshot capture completed.',
                $context
            );
        }
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

        $bricks_reference_block = self::maybe_block_proposal_apply_for_bricks_references($proposal_id);
        if (is_wp_error($bricks_reference_block)) {
            if (class_exists('DBVC_Sync_Logger')) {
                $error_data = $bricks_reference_block->get_error_data();
                $summary = is_array($error_data) && isset($error_data['bricks_references']) && is_array($error_data['bricks_references'])
                    ? $error_data['bricks_references']
                    : [];
                DBVC_Sync_Logger::log('Proposal apply blocked by unresolved Bricks references', [
                    'proposal'   => $proposal_id,
                    'mode'       => $mode,
                    'unresolved' => isset($summary['unresolved']) ? (int) $summary['unresolved'] : 0,
                ]);
            }

            return $bricks_reference_block;
        }

        $manifest = self::read_manifest_by_id($proposal_id);
        if (! $manifest) {
            return new \WP_Error('dbvc_manifest_missing', __('Proposal manifest could not be found.', 'dbvc'), ['status' => 404]);
        }

        $apply_gates = self::build_proposal_apply_gates($proposal_id, $manifest, [
            'ignore_missing_hash' => $ignore_missing_hash,
        ]);
        if (empty($apply_gates['ready'])) {
            $categories = [];
            $messages = [];
            foreach ($apply_gates['blocking'] as $blocker) {
                if (! is_array($blocker)) {
                    continue;
                }
                if (! empty($blocker['category'])) {
                    $categories[] = (string) $blocker['category'];
                }
                if (! empty($blocker['message'])) {
                    $messages[] = (string) $blocker['message'];
                }
            }
            $categories = array_values(array_unique($categories));
            $message = __('Proposal is not ready to apply.', 'dbvc');
            if (! empty($messages)) {
                $message .= ' ' . implode(' ', $messages);
            }

            $log_context = [
                'proposal'   => $proposal_id,
                'mode'       => $mode,
                'categories' => $categories,
                'counts'     => $apply_gates['counts'],
                'overrides'  => [
                    'ignore_missing_hash' => $ignore_missing_hash,
                ],
            ];
            if (class_exists('DBVC_Sync_Logger')) {
                DBVC_Sync_Logger::log('Proposal apply blocked', $log_context);
            }
            if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
                DBVC_Database::log_activity(
                    'proposal_apply_blocked',
                    'warning',
                    $message,
                    $log_context
                );
            }

            return new \WP_Error(
                'dbvc_proposal_not_ready',
                $message,
                [
                    'status' => 409,
                    'gates'  => $apply_gates,
                ]
            );
        }

        $decision_store_before = self::get_decision_store();
        if (isset($decision_store_before[$proposal_id]) && is_array($decision_store_before[$proposal_id])) {
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

        $outcome = self::normalize_proposal_apply_outcome($result);
        if (! is_array($result)) {
            $result = [];
        }
        if (empty($outcome['success'])) {
            $decision_store_after = self::get_decision_store();
            if (array_key_exists($proposal_id, $decision_store_before)) {
                $decision_store_after[$proposal_id] = $decision_store_before[$proposal_id];
            } else {
                unset($decision_store_after[$proposal_id]);
            }
            self::set_decision_store($decision_store_after);
        }

        $decision_store_after = self::get_decision_store();
        if (isset($decision_store_after[$proposal_id]) && is_array($decision_store_after[$proposal_id])) {
            $summary_after = self::summarize_proposal_decisions($decision_store_after[$proposal_id]);
        } else {
            $summary_after = self::summarize_proposal_decisions([]);
        }

        $auto_clear_enabled = get_option('dbvc_auto_clear_decisions', '1') === '1';
        $decisions_cleared  = ! empty($outcome['success'])
            && $had_decisions
            && (($summary_after['total'] ?? 0) === 0);
        $resolver_summary   = self::summarize_resolver_decisions($proposal_id);
        $resolver_outcomes  = isset($result['media']['resolver_decisions'])
            && is_array($result['media']['resolver_decisions'])
            ? $result['media']['resolver_decisions']
            : [];

        $status_after = ! empty($outcome['success']) && ($summary_after['total'] ?? 0) === 0
            ? 'closed'
            : 'draft';

        $errors = array_values(array_filter(array_map(static function ($failure) {
            return is_array($failure) ? (string) ($failure['message'] ?? '') : '';
        }, (array) ($outcome['errors'] ?? []))));
        $skipped_entities = isset($result['skipped_entities']) && is_array($result['skipped_entities'])
            ? array_values($result['skipped_entities'])
            : [];
        $reviewer_declined = count(array_filter($skipped_entities, static function ($entity): bool {
            return is_array($entity) && ($entity['reason'] ?? '') === 'declined_by_reviewer';
        }));

        if (empty($outcome['success'])) {
            $error_message = $errors[0] ?? __('Proposal apply did not complete successfully.', 'dbvc');
            if (class_exists('DBVC_Sync_Logger')) {
                DBVC_Sync_Logger::log('Proposal apply failed', [
                    'proposal' => $proposal_id,
                    'mode'     => $mode,
                    'errors'   => $errors,
                    'outcome'  => $outcome,
                ]);
            }
            if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
                DBVC_Database::log_activity(
                    'proposal_apply_failed',
                    'error',
                    $error_message,
                    [
                        'proposal' => $proposal_id,
                        'mode'     => $mode,
                        'errors'   => $errors,
                        'outcome'  => $outcome,
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
                'skipped_entities'=> $skipped_entities,
                'reviewer_declined'=> $reviewer_declined,
                'errors'         => $errors,
                'media'          => isset($result['media']) ? $result['media'] : [],
                'media_resolver' => isset($result['media_resolver']) ? $result['media_resolver'] : [],
                'media_reconcile'=> isset($result['media_reconcile']) ? $result['media_reconcile'] : [],
                'outcome'        => $outcome,
            ],
            'decisions_before'   => $summary_before,
            'decisions'          => $summary_after,
            'resolver_decisions' => $resolver_summary,
            'resolver_outcomes'  => $resolver_outcomes,
            'auto_clear_enabled' => $auto_clear_enabled,
            'decisions_cleared'  => $decisions_cleared,
            'had_decisions'      => $had_decisions,
            'ignore_missing_hash'=> $ignore_missing_hash,
            'force_reapply_new_posts' => (bool) $force_reapply_new_posts,
            'status'             => $status_after,
            'outcome'            => $outcome,
        ];

        self::mark_proposal_status($proposal_id, $status_after);

        if (empty($outcome['success'])) {
            return new \WP_Error(
                'dbvc_proposal_apply_failed',
                $errors[0] ?? __('Proposal apply did not complete successfully.', 'dbvc'),
                [
                    'status'             => 409,
                    'proposal_id'        => $proposal_id,
                    'proposal_status'    => $status_after,
                    'mode'               => $mode,
                    'outcome'            => $outcome,
                    'result'             => $response['result'],
                    'decisions'          => $summary_after,
                    'resolver_outcomes'  => $resolver_outcomes,
                ]
            );
        }

        return new \WP_REST_Response($response);
    }

    /**
     * Normalize importer responses into one apply outcome contract.
     *
     * @param mixed $result
     * @return array
     */
    private static function normalize_proposal_apply_outcome($result): array
    {
        $failures = [];
        $failure_keys = [];
        $append_failure = static function (
            string $domain,
            string $code,
            string $message,
            array $context = []
        ) use (&$failures, &$failure_keys): void {
            $domain = sanitize_key($domain);
            $code = sanitize_key($code);
            $message = sanitize_text_field($message);
            if ($message === '') {
                $message = __('Proposal apply did not complete successfully.', 'dbvc');
            }
            $key = $domain . '|' . $code . '|' . $message . '|' . wp_json_encode($context);
            if (isset($failure_keys[$key])) {
                return;
            }
            $failure_keys[$key] = true;
            $failure = [
                'domain'  => $domain !== '' ? $domain : 'proposal',
                'code'    => $code !== '' ? $code : 'apply_failed',
                'message' => $message,
            ];
            if (! empty($context)) {
                $failure['context'] = $context;
            }
            $failures[] = $failure;
        };

        if (! is_array($result)) {
            $append_failure(
                'proposal',
                'invalid_import_result',
                __('The import pipeline returned an invalid result.', 'dbvc')
            );
            return [
                'success' => false,
                'status'  => 'failed',
                'errors'  => $failures,
                'counts'  => [
                    'imported'      => 0,
                    'skipped'       => 0,
                    'entity_failed' => 1,
                    'media_failed'  => 0,
                ],
            ];
        }

        $explicit = isset($result['outcome']) && is_array($result['outcome'])
            ? $result['outcome']
            : [];
        foreach ((array) ($explicit['errors'] ?? []) as $failure) {
            if (is_array($failure)) {
                $append_failure(
                    (string) ($failure['domain'] ?? 'proposal'),
                    (string) ($failure['code'] ?? 'apply_failed'),
                    (string) ($failure['message'] ?? ''),
                    isset($failure['context']) && is_array($failure['context']) ? $failure['context'] : []
                );
            } elseif (is_scalar($failure)) {
                $append_failure('proposal', 'apply_failed', (string) $failure);
            }
        }

        if (empty($failures)) {
            foreach ((array) ($result['errors'] ?? []) as $message) {
                if (is_scalar($message) && (string) $message !== '') {
                    $append_failure('entity', 'entity_import_failed', (string) $message);
                }
            }
        }

        $media = isset($result['media']) && is_array($result['media']) ? $result['media'] : [];
        $media_error_count = is_numeric($media['errors'] ?? null) ? (int) $media['errors'] : 0;
        if ($media_error_count > 0 && empty($failures)) {
            $append_failure(
                'media_sync',
                'media_sync_errors',
                sprintf(
                    _n('%d media synchronization error occurred.', '%d media synchronization errors occurred.', $media_error_count, 'dbvc'),
                    $media_error_count
                )
            );
        }

        $resolver_summary = isset($media['resolver_decisions']) && is_array($media['resolver_decisions'])
            ? $media['resolver_decisions']
            : [];
        $reconcile = isset($result['media_reconcile']) && is_array($result['media_reconcile'])
            ? $result['media_reconcile']
            : [];
        $reconcile_summary = isset($reconcile['decision_summary']) && is_array($reconcile['decision_summary'])
            ? $reconcile['decision_summary']
            : [];
        if (empty($failures)) {
            foreach (
                [
                    'media_resolver'  => $resolver_summary,
                    'media_reconcile' => $reconcile_summary,
                ] as $domain => $summary
            ) {
                foreach (['failed', 'pending'] as $state) {
                    $count = isset($summary[$state]) ? (int) $summary[$state] : 0;
                    if ($count < 1) {
                        continue;
                    }
                    $append_failure(
                        $domain,
                        'required_media_' . $state,
                        sprintf(
                            _n(
                                '%1$d required media action is %2$s.',
                                '%1$d required media actions are %2$s.',
                                $count,
                                'dbvc'
                            ),
                            $count,
                            $state
                        )
                    );
                }
            }
        }

        $explicit_success = array_key_exists('success', $explicit)
            ? (bool) $explicit['success']
            : null;
        if ($explicit_success === false && empty($failures)) {
            $append_failure(
                'proposal',
                'apply_failed',
                __('Proposal apply did not complete successfully.', 'dbvc')
            );
        }

        $success = $explicit_success !== false && empty($failures);
        $imported = isset($result['imported']) ? (int) $result['imported'] : 0;
        $status = $success ? 'success' : ($imported > 0 ? 'partial' : 'failed');
        $counts = isset($explicit['counts']) && is_array($explicit['counts'])
            ? $explicit['counts']
            : [];
        $counts['imported'] = $imported;
        $counts['skipped'] = isset($result['skipped']) ? (int) $result['skipped'] : 0;
        $counts['entity_failed'] = isset($counts['entity_failed'])
            ? (int) $counts['entity_failed']
            : count(array_filter($failures, static function ($failure) {
                return ($failure['domain'] ?? '') === 'entity';
            }));
        $counts['media_failed'] = isset($counts['media_failed'])
            ? (int) $counts['media_failed']
            : count(array_filter($failures, static function ($failure) {
                return strpos((string) ($failure['domain'] ?? ''), 'media') === 0;
            }));

        return [
            'success' => $success,
            'status'  => $status,
            'errors'  => array_values($failures),
            'counts'  => $counts,
        ];
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
     * Keep explicit diff-view responses limited to identity and header context.
     */
    private static function build_entity_view_context(array $payload): array
    {
        $allowed_keys = [
            'ID',
            'term_id',
            'vf_object_uid',
            'post_type',
            'taxonomy',
            'term_taxonomy',
            'post_title',
            'name',
            'term_name',
            'post_name',
            'slug',
            'term_slug',
            'parent',
            'parent_slug',
            'parent_uid',
            'term_parent',
            'term_parent_slug',
            'term_parent_uid',
            'post_status',
            'post_date',
            'post_modified',
        ];
        $context = [];

        foreach ($allowed_keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            if (is_string($value) && strlen($value) > 512) {
                $value = function_exists('mb_strcut')
                    ? mb_strcut($value, 0, 512, 'UTF-8')
                    : wp_check_invalid_utf8(substr($value, 0, 512), true);
            }
            $context[$key] = $value;
        }

        return $context;
    }

    /**
     * Build the bounded payload and structural index used by the raw diff view.
     */
    private static function build_raw_diff_view(
        array $current,
        array $proposed,
        bool $current_available,
        array $canonical_diff,
        array $raw_downloads,
        ?string $current_reason,
        array $decisions = []
    ): array {
        $rows = [];
        $changes = isset($canonical_diff['changes']) && is_array($canonical_diff['changes'])
            ? array_slice($canonical_diff['changes'], 0, self::DIFF_RAW_INDEX_ROWS)
            : [];

        foreach ($changes as $change) {
            $apply_path = ! empty($change['can_apply']) && ! empty($change['apply_path'])
                ? (string) $change['apply_path']
                : '';
            $render_hint = isset($change['render_hint']) && is_array($change['render_hint'])
                ? $change['render_hint']
                : [];
            $rows[] = [
                'id'                 => (string) ($change['id'] ?? ''),
                'path'               => (string) ($change['path'] ?? ''),
                'label'              => (string) ($change['label'] ?? ''),
                'section'            => (string) ($change['section'] ?? 'other'),
                'changeType'         => (string) ($change['changeType'] ?? 'modified'),
                'source_exists'      => ! empty($change['source_exists']),
                'destination_exists' => ! empty($change['destination_exists']),
                'source_bytes'       => (int) ($render_hint['source_bytes'] ?? 0),
                'destination_bytes'  => (int) ($render_hint['destination_bytes'] ?? 0),
                'truncated_inline'   => ! empty($render_hint['truncated']),
                'can_apply'          => ! empty($change['can_apply']),
                'apply_path'         => $apply_path !== '' ? $apply_path : null,
                'decision'           => $apply_path !== '' && isset($decisions[$apply_path])
                    ? $decisions[$apply_path]
                    : null,
            ];
        }

        $total = (int) ($canonical_diff['total'] ?? 0);
        $displayed_total = count($rows);

        return [
            'limits' => [
                'preview_bytes'  => self::DIFF_RAW_PREVIEW_BYTES,
                'max_index_rows' => self::DIFF_RAW_INDEX_ROWS,
            ],
            'current' => self::build_raw_payload_preview(
                $current,
                $current_available,
                $raw_downloads['current'] ?? null,
                $current_available ? null : $current_reason
            ),
            'proposed' => self::build_raw_payload_preview(
                $proposed,
                true,
                $raw_downloads['proposed'] ?? null
            ),
            'change_index' => [
                'rows'            => $rows,
                'total'           => $total,
                'displayed_total' => $displayed_total,
                'omitted_total'   => max(0, $total - $displayed_total),
                'truncated'       => $total > $displayed_total,
                'change_counts'   => $canonical_diff['change_counts'] ?? [],
                'section_counts'  => $canonical_diff['section_counts'] ?? [],
            ],
        ];
    }

    /**
     * Return a pretty-printed JSON preview that never exceeds the byte limit.
     */
    private static function build_raw_payload_preview(
        array $payload,
        bool $available,
        ?string $download,
        ?string $reason = null
    ): array {
        if (! $available) {
            return [
                'available'       => false,
                'reason'          => $reason ?: 'unavailable',
                'content'         => '',
                'bytes'           => 0,
                'preview_bytes'   => 0,
                'lines'           => 0,
                'displayed_lines' => 0,
                'truncated'       => false,
                'sha256'          => null,
                'download'        => null,
            ];
        }

        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            $encoded = '{}';
        }

        $bytes = strlen($encoded);
        $truncated = $bytes > self::DIFF_RAW_PREVIEW_BYTES;
        $content = $encoded;
        if ($truncated) {
            $marker = "\n... [preview truncated; download full JSON]";
            $content_limit = max(0, self::DIFF_RAW_PREVIEW_BYTES - strlen($marker));
            $content = function_exists('mb_strcut')
                ? mb_strcut($encoded, 0, $content_limit, 'UTF-8')
                : wp_check_invalid_utf8(substr($encoded, 0, $content_limit), true);
            $content .= $marker;
        }

        return [
            'available'       => true,
            'reason'          => null,
            'content'         => $content,
            'bytes'           => $bytes,
            'preview_bytes'   => strlen($content),
            'lines'           => substr_count($encoded, "\n") + 1,
            'displayed_lines' => substr_count($content, "\n") + 1,
            'truncated'       => $truncated,
            'sha256'          => hash('sha256', $encoded),
            'download'        => $download,
        ];
    }

    private static function empty_diff_summary(): array
    {
        return [
            'changes'          => [],
            'total'            => 0,
            'displayed_total'  => 0,
            'omitted_total'    => 0,
            'actionable_total' => 0,
            'apply_paths'      => [],
            'change_counts'    => [
                'added'     => 0,
                'deleted'   => 0,
                'modified'  => 0,
                'unchanged' => 0,
            ],
            'section_counts'   => [],
            'truncated'        => false,
            'limits'           => [
                'max_rendered_rows' => self::DIFF_MAX_RENDERED_ROWS,
                'inline_value_bytes'=> self::DIFF_INLINE_VALUE_BYTES,
            ],
        ];
    }

    /**
     * Produce a classified, bounded diff between current and proposed snapshots.
     *
     * Existing row keys remain available while the FieldDiffItem contract is
     * introduced. Apply paths are collected across every row even when display
     * rows are truncated.
     *
     * @param array $current
     * @param array $proposed
     * @param array $options
     * @return array
     */
    private static function compare_snapshots(array $current, array $proposed, array $options = []): array
    {
        $summary = self::empty_diff_summary();
        $include_unchanged = ! empty($options['include_unchanged']);
        $max_rows = isset($options['max_rows'])
            ? min(self::DIFF_MAX_RENDERED_ROWS, max(1, absint($options['max_rows'])))
            : self::DIFF_MAX_RENDERED_ROWS;
        $inline_value_bytes = isset($options['inline_value_bytes'])
            ? min(self::DIFF_INLINE_VALUE_BYTES, max(256, absint($options['inline_value_bytes'])))
            : self::DIFF_INLINE_VALUE_BYTES;
        $summary['limits'] = [
            'max_rendered_rows' => $max_rows,
            'inline_value_bytes'=> $inline_value_bytes,
        ];

        $current_flat  = self::flatten_snapshot($current);
        $proposed_flat = self::flatten_snapshot($proposed);
        $entity_type = (
            array_key_exists('post_type', $current)
            || array_key_exists('post_type', $proposed)
            || array_key_exists('ID', $current)
            || array_key_exists('ID', $proposed)
        ) ? 'post' : (
            array_key_exists('term_id', $current)
            || array_key_exists('term_id', $proposed)
            || array_key_exists('taxonomy', $current)
            || array_key_exists('taxonomy', $proposed)
                ? 'term'
                : 'unknown'
        );
        $proposed_meta_roots = [];
        foreach (array_keys($proposed_flat) as $proposed_path) {
            $parts = explode('.', (string) $proposed_path);
            if (($parts[0] ?? '') === 'meta' && ! empty($parts[1])) {
                $proposed_meta_roots['meta.' . $parts[1]] = true;
            }
        }

        $unique_keys = array_values(array_unique(array_merge(array_keys($current_flat), array_keys($proposed_flat))));
        sort($unique_keys, SORT_STRING);
        $apply_paths = [];

        foreach ($unique_keys as $key) {
            $old_exists = array_key_exists($key, $current_flat);
            $new_exists = array_key_exists($key, $proposed_flat);
            $old = $old_exists ? $current_flat[$key] : null;
            $new = $new_exists ? $proposed_flat[$key] : null;
            $is_equal = $old_exists && $new_exists && $old === $new;

            if ($is_equal && ! $include_unchanged) {
                continue;
            }
            if ($key !== '' && self::should_ignore_diff_path($key)) {
                continue;
            }

            if (! $old_exists && $new_exists) {
                $change_type = 'added';
            } elseif ($old_exists && ! $new_exists) {
                $change_type = 'deleted';
            } elseif ($is_equal) {
                $change_type = 'unchanged';
            } else {
                $change_type = 'modified';
            }

            $apply_scope = self::describe_diff_apply_scope($key, $entity_type);
            if (($apply_scope['apply_scope'] ?? '') === 'meta_leaf') {
                $parts = explode('.', $key);
                $meta_root = isset($parts[1]) ? 'meta.' . $parts[1] : '';
                if ($meta_root !== '' && empty($proposed_meta_roots[$meta_root])) {
                    $apply_scope = [
                        'apply_scope' => 'meta_key',
                        'apply_path'  => $meta_root,
                        'apply_label' => __('This complete meta key', 'dbvc'),
                        'can_apply'   => true,
                    ];
                }
            }

            $section = self::determine_section($key);
            $source = self::format_diff_inline_value($old, $old_exists, $inline_value_bytes);
            $destination = self::format_diff_inline_value($new, $new_exists, $inline_value_bytes);
            $render_kind = self::determine_diff_render_kind(
                $source['value'],
                $destination['value']
            );
            $is_truncated = ! empty($source['truncated']) || ! empty($destination['truncated']);

            $summary['total']++;
            $summary['change_counts'][$change_type]++;
            if (! isset($summary['section_counts'][$section])) {
                $summary['section_counts'][$section] = 0;
            }
            $summary['section_counts'][$section]++;

            if (
                $change_type !== 'unchanged'
                && ! empty($apply_scope['can_apply'])
                && ! empty($apply_scope['apply_path'])
            ) {
                $apply_paths[] = (string) $apply_scope['apply_path'];
            }

            if (count($summary['changes']) >= $max_rows) {
                continue;
            }

            $summary['changes'][] = array_merge([
                'id'                 => self::build_field_diff_id($key, $entity_type),
                'path'               => $key,
                'label'              => self::humanize_path($key),
                'section'            => $section,
                'changeType'         => $change_type,
                'source'             => $source['value'],
                'destination'        => $destination['value'],
                'source_exists'      => $old_exists,
                'destination_exists' => $new_exists,
                'decision'           => null,
                'render_hint'        => [
                    'display'           => $render_kind,
                    'truncated'         => $is_truncated,
                    'source_truncated'  => ! empty($source['truncated']),
                    'source_bytes'      => (int) $source['bytes'],
                    'destination_truncated' => ! empty($destination['truncated']),
                    'destination_bytes'=> (int) $destination['bytes'],
                    'inline_value_bytes'=> $inline_value_bytes,
                    'raw_available'     => $is_truncated,
                ],
                'from'               => $source['value'],
                'to'                 => $destination['value'],
                'is_equal'           => $change_type === 'unchanged',
            ], $apply_scope);
        }

        $summary['displayed_total'] = count($summary['changes']);
        $summary['omitted_total'] = max(0, $summary['total'] - $summary['displayed_total']);
        $summary['truncated'] = $summary['omitted_total'] > 0;
        $summary['apply_paths'] = array_values(array_unique($apply_paths));
        $summary['actionable_total'] = count($summary['apply_paths']);

        return $summary;
    }

    /**
     * Return a bounded scalar value plus rendering metadata.
     */
    private static function format_diff_inline_value($value, bool $exists, int $limit): array
    {
        if (! $exists) {
            return [
                'value'     => null,
                'bytes'     => 0,
                'truncated' => false,
            ];
        }

        $encoded = is_string($value) ? $value : wp_json_encode($value);
        $bytes = is_string($encoded) ? strlen($encoded) : 0;
        if (! is_string($value) || $bytes <= $limit) {
            return [
                'value'     => $value,
                'bytes'     => $bytes,
                'truncated' => false,
            ];
        }

        if (function_exists('mb_strcut')) {
            $preview = mb_strcut($value, 0, $limit, 'UTF-8');
        } else {
            $preview = wp_check_invalid_utf8(substr($value, 0, $limit), true);
        }

        return [
            'value'     => $preview . '... [truncated]',
            'bytes'     => $bytes,
            'truncated' => true,
        ];
    }

    private static function determine_diff_render_kind($source, $destination): string
    {
        foreach ([$source, $destination] as $value) {
            if (! is_string($value)) {
                continue;
            }
            if (strpos($value, "\n") !== false) {
                return 'multiline';
            }
            $trimmed = ltrim($value);
            if ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true)) {
                return 'json';
            }
        }

        return 'scalar';
    }

    private static function build_field_diff_id(string $path, string $entity_type): string
    {
        return 'field-' . substr(hash('sha256', $entity_type . "\0" . $path), 0, 20);
    }

    /**
     * Describe the importer unit controlled by a displayed diff path.
     */
    private static function describe_diff_apply_scope(string $path, string $entity_type = 'unknown'): array
    {
        $path = trim($path);
        $parts = $path === '' ? [] : explode('.', $path);
        $root = $parts[0] ?? '';
        $identity_roots = [
            'ID',
            'term_id',
            'post_type',
            'taxonomy',
            'term_taxonomy',
            'vf_object_uid',
            'entity_refs',
        ];

        if (in_array($root, $identity_roots, true)) {
            return [
                'apply_scope' => 'identity',
                'apply_path'  => null,
                'apply_label' => __('Reference only', 'dbvc'),
                'can_apply'   => false,
            ];
        }

        if ($root === 'post' && isset($parts[1]) && count($parts) === 2) {
            $root = $parts[1];
        }
        if (
            $entity_type !== 'term'
            && in_array($root, self::$post_apply_fields, true)
            && (count($parts) === 1 || $parts[0] === 'post')
        ) {
            return [
                'apply_scope' => 'post_field',
                'apply_path'  => $root,
                'apply_label' => __('This post field', 'dbvc'),
                'can_apply'   => true,
            ];
        }
        if ($entity_type === 'post' && count($parts) === 1 && $root === 'slug') {
            return [
                'apply_scope' => 'post_field',
                'apply_path'  => 'post_name',
                'apply_label' => __('This post field', 'dbvc'),
                'can_apply'   => true,
            ];
        }

        if (($parts[0] ?? '') === 'meta' && ! empty($parts[1])) {
            $is_leaf = count($parts) > 2;
            return [
                'apply_scope' => $is_leaf ? 'meta_leaf' : 'meta_key',
                'apply_path'  => $path,
                'apply_label' => $is_leaf
                    ? __('This nested meta value', 'dbvc')
                    : __('This complete meta key', 'dbvc'),
                'can_apply'   => true,
            ];
        }

        if (in_array(($parts[0] ?? ''), ['tax_input', 'taxonomies'], true) && ! empty($parts[1])) {
            $taxonomy = sanitize_key((string) $parts[1]);
            return [
                'apply_scope' => 'taxonomy',
                'apply_path'  => $taxonomy !== '' ? 'tax_input.' . $taxonomy : null,
                'apply_label' => __('Complete taxonomy assignment', 'dbvc'),
                'can_apply'   => $taxonomy !== '',
            ];
        }

        $term_apply_paths = [
            'name'        => 'name',
            'term_name'   => 'name',
            'slug'        => 'slug',
            'term_slug'   => 'slug',
            'description' => 'description',
            'parent'      => 'parent',
            'parent_slug' => 'parent',
            'parent_uid'  => 'parent',
        ];
        if ($entity_type !== 'post' && count($parts) === 1 && isset($term_apply_paths[$root])) {
            return [
                'apply_scope' => $root === 'parent' || strpos($root, 'parent_') === 0 ? 'term_parent' : 'term_field',
                'apply_path'  => $term_apply_paths[$root],
                'apply_label' => $term_apply_paths[$root] === 'parent'
                    ? __('Complete parent assignment', 'dbvc')
                    : __('This term field', 'dbvc'),
                'can_apply'   => true,
            ];
        }

        return [
            'apply_scope' => 'unsupported',
            'apply_path'  => null,
            'apply_label' => __('Not applied by Proposal Review', 'dbvc'),
            'can_apply'   => false,
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
        $snapshot_status = self::get_entity_snapshot_status($proposal_id, $manifest_item);
        if (empty($snapshot_status['trusted'])) {
            return [];
        }

        $current_path = isset($manifest_item['path']) ? (string) $manifest_item['path'] : '';
        $proposed = [];
        if ($current_path !== '') {
            $payload = self::read_entity_payload($proposal_id, $current_path);
            if (is_array($payload)) {
                $proposed = $payload;
            }
        }

        $current = [];
        if (class_exists('DBVC_Snapshot_Manager')) {
            $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
            if (is_array($snapshot) && ! empty($snapshot)) {
                $current = $snapshot;
            }
        }

        if (empty($current)) {
            return [];
        }

        $diff_summary = self::compare_snapshots($current, $proposed);
        if (isset($diff_summary['apply_paths']) && is_array($diff_summary['apply_paths'])) {
            return array_values(array_unique(array_filter($diff_summary['apply_paths'])));
        }

        return [];
    }

    /**
     * Return masking paths whose saved review decisions must survive diff cleanup.
     */
    private static function resolve_entity_masking_decision_paths(
        string $proposal_id,
        string $vf_object_uid,
        array $manifest_item
    ): array {
        $paths = [];
        $fields = self::collect_masking_fields($proposal_id, ['items' => [$manifest_item]], 1, 0);

        foreach ($fields as $field) {
            if (
                ! is_array($field)
                || (string) ($field['vf_object_uid'] ?? '') !== $vf_object_uid
            ) {
                continue;
            }

            $mask_path = trim((string) ($field['meta_path'] ?? ''));
            if ($mask_path === '') {
                continue;
            }

            $parts = self::parse_mask_path($mask_path);
            $paths[] = ($parts['scope'] ?? '') === 'post' && ! empty($parts['field'])
                ? (string) $parts['field']
                : $mask_path;
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

        $paths = array_merge(
            self::resolve_entity_diff_paths($proposal_id, $vf_object_uid, $manifest_item),
            self::resolve_entity_masking_decision_paths($proposal_id, $vf_object_uid, $manifest_item)
        );
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
        $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
        $identity = $item_type === 'term'
            ? self::describe_term_identity($item)
            : self::describe_entity_identity($item);
        $snapshot_status = self::get_entity_snapshot_status($proposal_id, $item, $identity);
        $empty = [
            'total'           => 0,
            'meta'            => 0,
            'tax'             => 0,
            'diff_available'  => ! empty($snapshot_status['trusted']),
            'snapshot_state'  => $snapshot_status['state'],
            'snapshot_status' => $snapshot_status,
        ];

        $path = isset($item['path']) ? (string) $item['path'] : '';
        if ($path === '') {
            return $empty;
        }

        if ($vf_object_uid === '') {
            $vf_object_uid = self::get_manifest_item_uid($item);
        }

        $proposed = self::read_entity_payload($proposal_id, $path);
        if (! is_array($proposed)) {
            return $empty;
        }

        if (empty($snapshot_status['trusted'])) {
            return $empty;
        }

        $current = [];
        if (class_exists('DBVC_Snapshot_Manager') && $vf_object_uid !== '') {
            $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
            if (is_array($snapshot) && ! empty($snapshot)) {
                $current = $snapshot;
            }
        }

        if (empty($current)) {
            return $empty;
        }

        $diff_summary = self::compare_snapshots($current, $proposed);
        $section_counts = isset($diff_summary['section_counts']) && is_array($diff_summary['section_counts'])
            ? $diff_summary['section_counts']
            : [];
        $meta_changes = (int) ($section_counts['meta'] ?? 0);
        $tax_changes  = (int) ($section_counts['tax'] ?? 0);

        return [
            'total'           => isset($diff_summary['actionable_total']) ? (int) $diff_summary['actionable_total'] : 0,
            'display_total'   => isset($diff_summary['total']) ? (int) $diff_summary['total'] : 0,
            'meta'            => $meta_changes,
            'tax'             => $tax_changes,
            'diff_available'  => true,
            'snapshot_state'  => $snapshot_status['state'],
            'snapshot_status' => $snapshot_status,
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
        $decision_store = self::get_decision_store();
        $proposal_decisions = isset($decision_store[$proposal_id]) && is_array($decision_store[$proposal_id])
            ? $decision_store[$proposal_id]
            : [];

        $offset = max(0, ($page - 1) * $per_page);
        if ($per_page > 0) {
            $items = array_slice($items, $offset, $per_page, true);
        }

        foreach ($items as $item) {
            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if (! in_array($item_type, ['post', 'term'], true)) {
                continue;
            }
            $vf_object_uid = self::get_manifest_item_uid($item);
            if ($vf_object_uid === '' || empty($item['path'])) {
                continue;
            }

            $identity = $item_type === 'term'
                ? self::describe_term_identity($item)
                : self::describe_entity_identity($item);
            $entity_decisions = isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid])
                ? $proposal_decisions[$vf_object_uid]
                : [];
            if (
                ! empty($identity['is_new'])
                && self::get_new_entity_decision($proposal_id, $vf_object_uid, $entity_decisions) === 'decline_new'
            ) {
                continue;
            }
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
            $snapshot_status = isset($diff_counts['snapshot_status']) && is_array($diff_counts['snapshot_status'])
                ? $diff_counts['snapshot_status']
                : self::get_entity_snapshot_status($proposal_id, $item, $identity);
            if (! empty($snapshot_status['required']) && empty($snapshot_status['trusted'])) {
                continue;
            }
            if (! empty($snapshot_status['trusted']) && class_exists('DBVC_Snapshot_Manager')) {
                $snapshot = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $vf_object_uid);
                if (is_array($snapshot) && ! empty($snapshot)) {
                    $current = $snapshot;
                }
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
            $uid = self::get_manifest_item_uid($item);
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
     * @return array{accepted:int,kept:int,accepted_new:int,declined_new:int,total:int,entities_reviewed:int,entities_with_accept:int}
     */
    private static function summarize_proposal_decisions(array $proposal_decisions): array
    {
        $summary = [
            'accepted'             => 0,
            'kept'                 => 0,
            'accepted_new'         => 0,
            'declined_new'         => 0,
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
                } elseif ($action === 'decline_new') {
                    $summary['declined_new']++;
                }
            }

            if ($entity_accepts > 0) {
                $summary['entities_with_accept']++;
            }
        }

        $summary['total'] = $summary['accepted']
            + $summary['kept']
            + $summary['accepted_new']
            + $summary['declined_new'];
        return $summary;
    }

    private static function summarize_manifest_new_entities(
        array $manifest,
        array $proposal_decisions,
        string $proposal_id = ''
    ): array
    {
        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        if ($proposal_id === '' && ! empty($manifest['backup_name'])) {
            $proposal_id = (string) $manifest['backup_name'];
        }
        $summary = [
            'total'    => 0,
            'accepted' => 0,
            'declined' => 0,
            'pending'  => 0,
            'resolved' => 0,
            'states'   => [
                'accepted_new' => 0,
                'declined_new' => 0,
                'pending_new'  => 0,
            ],
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

            $vf_object_uid = self::get_manifest_item_uid($item);

            $entity_decisions = ($vf_object_uid !== '' && isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid]))
                ? $proposal_decisions[$vf_object_uid]
                : [];

            $new_decision = self::get_new_entity_decision(
                $proposal_id,
                $vf_object_uid,
                $entity_decisions
            );

            $new_entity_state = self::normalize_new_entity_state($new_decision);
            $summary['states'][$new_entity_state]++;

            if ($new_entity_state === 'accepted_new') {
                $summary['accepted']++;
                $summary['resolved']++;
            } elseif ($new_entity_state === 'declined_new') {
                $summary['declined']++;
                $summary['resolved']++;
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
     * @return array{accepted:int,kept:int,accepted_new:int,declined_new:int,total:int,has_accept:bool}
     */
    private static function summarize_entity_decisions(array $entity_decisions): array
    {
        $accepted = 0;
        $kept     = 0;
        $accepted_new = 0;
        $declined_new = 0;

        foreach ($entity_decisions as $action) {
            if ($action === 'accept') {
                $accepted++;
            } elseif ($action === 'keep') {
                $kept++;
            } elseif ($action === 'accept_new') {
                $accepted_new++;
            } elseif ($action === 'decline_new') {
                $declined_new++;
            }
        }

        return [
            'accepted'  => $accepted,
            'kept'      => $kept,
            'accepted_new' => $accepted_new,
            'declined_new' => $declined_new,
            'total'     => $accepted + $kept + $accepted_new + $declined_new,
            'has_accept'=> ($accepted + $accepted_new) > 0,
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
        $store[$proposal_id][$vf_object_uid] = self::remove_overlapping_decisions(
            $store[$proposal_id][$vf_object_uid],
            $path
        );
        $store[$proposal_id][$vf_object_uid][$path] = $action;
        $store[$proposal_id] = self::recalculate_proposal_summary($store[$proposal_id]);
        $store = self::cleanup_empty_proposals($store, $proposal_id);
        self::set_decision_store($store);

        if ($path === self::NEW_ENTITY_DECISION_KEY) {
            self::set_declined_new_state(
                $proposal_id,
                $vf_object_uid,
                $action === 'decline_new'
            );
        }

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
        if ($path === self::NEW_ENTITY_DECISION_KEY) {
            self::set_declined_new_state($proposal_id, $vf_object_uid, false);
        }

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
        self::set_declined_new_state($proposal_id, $vf_object_uid, false);

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
        delete_option(self::SNAPSHOT_STATES_OPTION);
        delete_option(self::DECLINED_NEW_ENTITIES_OPTION);

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
            $manifest_dir = trailingslashit(DBVC_Backup_Manager::get_base_path(false)) . $proposal_id;
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
        $allow_identity_fallback = true;

        if ($vf_object_uid !== '' && class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'find_term_id_by_uid')) {
            $found = DBVC_Sync_Posts::find_term_id_by_uid($vf_object_uid, $taxonomy);
            if ($found) {
                $local_id = (int) $found;
                $match_source = 'uid';
            }
        }

        if ($vf_object_uid !== '' && ! $local_id && class_exists('DBVC_Sync_Posts') && ! DBVC_Sync_Posts::is_uid_fallback_matching_allowed()) {
            $match_source = 'uid_unmatched';
            $allow_identity_fallback = false;
        }

        if ($allow_identity_fallback && ! $local_id && $term_id) {
            $term = get_term($term_id);
            if ($term && ! is_wp_error($term)) {
                $local_id = (int) $term->term_id;
                $match_source = 'id';
            }
        }

        if ($allow_identity_fallback && ! $local_id && $slug !== '' && $taxonomy && taxonomy_exists($taxonomy)) {
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

    private static function format_term_manifest_entity(
        string $proposal_id,
        array $item,
        string $status_filter,
        array $proposal_decisions,
        array $field_decision_readiness = [],
        array $masking_readiness = [],
        array $duplicate_group_keys = []
    ): ?array {
        $vf_object_uid = self::get_manifest_item_uid($item);
        $taxonomy      = isset($item['term_taxonomy']) ? sanitize_key($item['term_taxonomy']) : (isset($item['taxonomy']) ? sanitize_key($item['taxonomy']) : '');
        $term_name     = isset($item['term_name']) ? (string) $item['term_name'] : (isset($item['name']) ? (string) $item['name'] : '');
        $term_slug     = isset($item['term_slug']) ? (string) $item['term_slug'] : (isset($item['slug']) ? (string) $item['slug'] : '');
        $term_id       = isset($item['term_id']) ? (int) $item['term_id'] : null;

        $identity = self::describe_term_identity($item);
        $is_new_entity = $identity['is_new'];
        $entity_decisions = ($vf_object_uid !== '' && isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid]))
            ? $proposal_decisions[$vf_object_uid]
            : [];
        $decision_summary = self::summarize_entity_decisions($entity_decisions);
        $new_entity_decision = self::get_new_entity_decision($proposal_id, $vf_object_uid, $entity_decisions);
        $new_entity_state = $is_new_entity
            ? self::normalize_new_entity_state($new_entity_decision)
            : '';

        $diff_counts = self::summarize_entity_diff_counts($proposal_id, $item, $vf_object_uid);
        $snapshot_status = isset($diff_counts['snapshot_status']) && is_array($diff_counts['snapshot_status'])
            ? $diff_counts['snapshot_status']
            : self::get_entity_snapshot_status($proposal_id, $item, $identity);
        $status_counts = self::build_entity_status_counts(
            $field_decision_readiness['by_entity'][$vf_object_uid] ?? [],
            $masking_readiness['by_entity'][$vf_object_uid] ?? [],
            [],
            self::count_duplicate_groups_for_item($item, $duplicate_group_keys),
            $is_new_entity && $new_entity_state === 'pending_new'
        );
        $needs_review = self::entity_status_requires_review(
            $status_counts,
            $snapshot_status,
            []
        );
        $snapshot_needs_review = ! empty($snapshot_status['required']) && empty($snapshot_status['trusted']);

        if (! self::entity_matches_status_filter(
            $status_filter,
            $status_counts,
            $needs_review,
            $is_new_entity,
            $snapshot_status
        )) {
            return null;
        }

        $diff_reason = $is_new_entity
            ? 'new_term'
            : ($snapshot_needs_review
                ? 'snapshot_' . sanitize_key((string) ($snapshot_status['state'] ?? 'failed'))
                : (($diff_counts['total'] ?? 0) > 0 ? 'term_modified' : 'term_clean'));

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
            'snapshot_state'    => $snapshot_status['state'] ?? 'failed',
            'snapshot_status'   => $snapshot_status,
            'meta_diff_count'   => $diff_counts['meta'] ?? 0,
            'tax_diff_count'    => 0,
            'media_needs_review'=> false,
            'status_counts'     => $status_counts,
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
            'new_entity_state'   => $new_entity_state,
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
        if (self::is_declined_new_entity($proposal_id, $vf_object_uid)) {
            return 'decline_new';
        }

        return '';
    }

    private static function normalize_new_entity_state(string $decision): string
    {
        if ($decision === 'accept_new') {
            return 'accepted_new';
        }
        if ($decision === 'decline_new') {
            return 'declined_new';
        }
        return 'pending_new';
    }

    private static function is_declined_new_entity(string $proposal_id, string $vf_object_uid): bool
    {
        if ($proposal_id === '' || $vf_object_uid === '') {
            return false;
        }
        $store = get_option(self::DECLINED_NEW_ENTITIES_OPTION, []);
        return is_array($store)
            && ! empty($store[$proposal_id])
            && is_array($store[$proposal_id])
            && ! empty($store[$proposal_id][$vf_object_uid]);
    }

    private static function set_declined_new_state(
        string $proposal_id,
        string $vf_object_uid,
        bool $declined
    ): void {
        if ($proposal_id === '' || $vf_object_uid === '') {
            return;
        }
        $store = get_option(self::DECLINED_NEW_ENTITIES_OPTION, []);
        $store = is_array($store) ? $store : [];

        if ($declined) {
            if (! isset($store[$proposal_id]) || ! is_array($store[$proposal_id])) {
                $store[$proposal_id] = [];
            }
            $store[$proposal_id][$vf_object_uid] = true;
        } elseif (isset($store[$proposal_id]) && is_array($store[$proposal_id])) {
            unset($store[$proposal_id][$vf_object_uid]);
            if (empty($store[$proposal_id])) {
                unset($store[$proposal_id]);
            }
        }

        if (empty($store)) {
            delete_option(self::DECLINED_NEW_ENTITIES_OPTION);
            return;
        }
        update_option(self::DECLINED_NEW_ENTITIES_OPTION, $store, false);
    }

    private static function clear_declined_new_proposal(string $proposal_id): void
    {
        if ($proposal_id === '') {
            return;
        }
        $store = get_option(self::DECLINED_NEW_ENTITIES_OPTION, []);
        if (! is_array($store) || ! isset($store[$proposal_id])) {
            return;
        }
        unset($store[$proposal_id]);
        if (empty($store)) {
            delete_option(self::DECLINED_NEW_ENTITIES_OPTION);
            return;
        }
        update_option(self::DECLINED_NEW_ENTITIES_OPTION, $store, false);
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
