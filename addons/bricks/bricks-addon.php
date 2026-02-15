<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Addon
{
    public const OPTION_ENABLED = 'dbvc_addon_bricks_enabled';
    public const OPTION_VISIBILITY = 'dbvc_addon_bricks_visibility';
    public const OPTION_SETTINGS_VERSION = 'dbvc_bricks_settings_version';
    public const OPTION_UI_DIAGNOSTICS = 'dbvc_bricks_ui_diagnostics';
    public const OPTION_FLEET_MODE_ENABLED = 'dbvc_bricks_fleet_mode_enabled';
    public const SETTINGS_VERSION = 1;
    public const UI_CONTRACT_VERSION = '1.0.0';
    public const UI_DIAGNOSTIC_MAX_ITEMS = 100;
    public const UI_DIAGNOSTIC_DEFAULT_LIMIT = 25;
    public const UI_DIAGNOSTIC_MAX_DEPTH = 3;
    public const UI_DIAGNOSTIC_MAX_KEYS = 50;
    public const CRON_HOOK = 'dbvc_bricks_addon_hourly';
    public const ADMIN_MENU_PRIORITY = 30;
    public const MENU_SLUG = 'addon-dbvc-bricks-addon';
    public const LEGACY_MENU_SLUG = 'dbvc-bricks-addon';

    /**
     * Bootstrap add-on defaults and conditional runtime registrations.
     *
     * @return void
     */
    public static function bootstrap()
    {
        self::maybe_migrate_settings();
        self::ensure_defaults();
        self::refresh_runtime_registration();
    }

    /**
     * Ensure add-on options exist with deterministic defaults.
     *
     * @return void
     */
    public static function ensure_defaults()
    {
        foreach (self::get_default_values() as $key => $default_value) {
            add_option($key, $default_value);
        }
    }

    /**
     * Get field schema for Bricks add-on settings.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_settings_schema()
    {
        return [
            self::OPTION_ENABLED => [
                'type'    => 'bool',
                'default' => '0',
            ],
            self::OPTION_VISIBILITY => [
                'type'    => 'enum',
                'default' => 'configure_and_submenu',
                'allowed' => ['submenu_only', 'configure_and_submenu'],
            ],
            'dbvc_bricks_role' => [
                'type'    => 'enum',
                'default' => 'client',
                'allowed' => ['mothership', 'client'],
            ],
            'dbvc_bricks_site_uid' => [
                'type'    => 'key_id',
                'default' => '',
            ],
            'dbvc_bricks_mothership_url' => [
                'type'    => 'url',
                'default' => '',
            ],
            'dbvc_bricks_auth_method' => [
                'type'    => 'enum',
                'default' => 'hmac',
                'allowed' => ['hmac', 'api_key', 'wp_app_password'],
            ],
            'dbvc_bricks_api_key_id' => [
                'type'    => 'key_id',
                'default' => '',
            ],
            'dbvc_bricks_api_secret' => [
                'type'    => 'secret',
                'default' => '',
            ],
            'dbvc_bricks_credentials_updated_at' => [
                'type'    => 'text',
                'default' => '',
            ],
            'dbvc_bricks_credentials_rotate_days' => [
                'type'    => 'int',
                'default' => '90',
                'min'     => 1,
                'max'     => 365,
            ],
            'dbvc_bricks_http_timeout' => [
                'type'    => 'int',
                'default' => '30',
                'min'     => 5,
                'max'     => 120,
            ],
            'dbvc_bricks_tls_verify' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_read_only' => [
                'type'    => 'bool',
                'default' => '0',
            ],
            self::OPTION_FLEET_MODE_ENABLED => [
                'type'    => 'bool',
                'default' => '0',
            ],
            'dbvc_bricks_source_mode' => [
                'type'    => 'enum',
                'default' => 'mothership_api',
                'allowed' => ['mothership_api', 'pinned_version', 'local_package'],
            ],
            'dbvc_bricks_channel' => [
                'type'    => 'enum',
                'default' => 'stable',
                'allowed' => ['stable', 'beta', 'canary'],
            ],
            'dbvc_bricks_client_force_channel' => [
                'type'    => 'enum',
                'default' => 'none',
                'allowed' => ['none', 'canary', 'beta', 'stable'],
            ],
            'dbvc_bricks_force_stable_confirm' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_intro_auto_send' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_intro_handshake_token' => [
                'type'    => 'secret',
                'default' => '',
            ],
            'dbvc_bricks_client_registry_state' => [
                'type'    => 'enum',
                'default' => 'PENDING_INTRO',
                'allowed' => ['PENDING_INTRO', 'VERIFIED', 'REJECTED', 'DISABLED'],
            ],
            'dbvc_bricks_intro_retry_max_attempts' => [
                'type'    => 'int',
                'default' => '6',
                'min'     => 1,
                'max'     => 20,
            ],
            'dbvc_bricks_intro_retry_interval_minutes' => [
                'type'    => 'int',
                'default' => '30',
                'min'     => 5,
                'max'     => 1440,
            ],
            'dbvc_bricks_connected_sites_source' => [
                'type'    => 'enum',
                'default' => 'manual',
                'allowed' => ['manual', 'heartbeat_auto'],
            ],
            'dbvc_bricks_connected_sites_mode' => [
                'type'    => 'enum',
                'default' => 'registry_table',
                'allowed' => ['packages_backfill', 'registry_table'],
            ],
            'dbvc_bricks_pinned_version' => [
                'type'    => 'text',
                'default' => '',
            ],
            'dbvc_bricks_verify_signature' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_allow_fallback' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_retention_count' => [
                'type'    => 'int',
                'default' => '25',
                'min'     => 1,
                'max'     => 200,
            ],
            'dbvc_bricks_fetch_batch' => [
                'type'    => 'int',
                'default' => '100',
                'min'     => 10,
                'max'     => 500,
            ],
            'dbvc_bricks_policy_entity_default' => [
                'type'    => 'enum',
                'default' => 'REQUIRE_MANUAL_ACCEPT',
                'allowed' => ['AUTO_ACCEPT', 'REQUIRE_MANUAL_ACCEPT', 'ALWAYS_OVERRIDE', 'REQUEST_REVIEW', 'IGNORE'],
            ],
            'dbvc_bricks_policy_option_default' => [
                'type'    => 'enum',
                'default' => 'REQUEST_REVIEW',
                'allowed' => ['AUTO_ACCEPT', 'REQUIRE_MANUAL_ACCEPT', 'ALWAYS_OVERRIDE', 'REQUEST_REVIEW', 'IGNORE'],
            ],
            'dbvc_bricks_policy_new_entity_gate' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_policy_block_delete' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_policy_max_diff_kb' => [
                'type'    => 'int',
                'default' => '1024',
                'min'     => 50,
                'max'     => 5000,
            ],
            'dbvc_bricks_policy_overrides' => [
                'type'    => 'json_map',
                'default' => '{}',
            ],
            'dbvc_bricks_scan_mode' => [
                'type'    => 'enum',
                'default' => 'manual',
                'allowed' => ['manual', 'scheduled'],
            ],
            'dbvc_bricks_scan_interval_minutes' => [
                'type'    => 'int',
                'default' => '60',
                'min'     => 5,
                'max'     => 1440,
            ],
            'dbvc_bricks_restore_before_apply' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_restore_retention' => [
                'type'    => 'int',
                'default' => '20',
                'min'     => 1,
                'max'     => 100,
            ],
            'dbvc_bricks_apply_batch_size' => [
                'type'    => 'int',
                'default' => '25',
                'min'     => 1,
                'max'     => 200,
            ],
            'dbvc_bricks_apply_dry_run_default' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_proposals_enabled' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_proposals_auto_submit' => [
                'type'    => 'bool',
                'default' => '0',
            ],
            'dbvc_bricks_proposals_require_note' => [
                'type'    => 'bool',
                'default' => '1',
            ],
            'dbvc_bricks_proposals_queue_limit' => [
                'type'    => 'int',
                'default' => '500',
                'min'     => 10,
                'max'     => 5000,
            ],
            'dbvc_bricks_proposals_reviewer_group' => [
                'type'    => 'text',
                'default' => '',
            ],
            'dbvc_bricks_proposals_sla_hours' => [
                'type'    => 'int',
                'default' => '72',
                'min'     => 1,
                'max'     => 720,
            ],
        ];
    }

    /**
     * Get grouped settings for Configure UI.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_settings_groups()
    {
        return [
            'activation' => [
                'label' => 'Activation',
                'fields' => [self::OPTION_ENABLED, self::OPTION_VISIBILITY],
            ],
            'connection' => [
                'label' => 'Connection',
                'fields' => [
                    'dbvc_bricks_role',
                    'dbvc_bricks_site_uid',
                    'dbvc_bricks_mothership_url',
                    'dbvc_bricks_auth_method',
                    'dbvc_bricks_api_key_id',
                    'dbvc_bricks_api_secret',
                    'dbvc_bricks_credentials_rotate_days',
                    'dbvc_bricks_http_timeout',
                    'dbvc_bricks_tls_verify',
                    'dbvc_bricks_read_only',
                    self::OPTION_FLEET_MODE_ENABLED,
                ],
            ],
            'golden_source' => [
                'label' => 'Golden Source',
                'fields' => [
                    'dbvc_bricks_source_mode',
                    'dbvc_bricks_channel',
                    'dbvc_bricks_client_force_channel',
                    'dbvc_bricks_force_stable_confirm',
                    'dbvc_bricks_intro_auto_send',
                    'dbvc_bricks_intro_handshake_token',
                    'dbvc_bricks_client_registry_state',
                    'dbvc_bricks_intro_retry_max_attempts',
                    'dbvc_bricks_intro_retry_interval_minutes',
                    'dbvc_bricks_connected_sites_source',
                    'dbvc_bricks_connected_sites_mode',
                    'dbvc_bricks_pinned_version',
                    'dbvc_bricks_verify_signature',
                    'dbvc_bricks_allow_fallback',
                    'dbvc_bricks_retention_count',
                    'dbvc_bricks_fetch_batch',
                ],
            ],
            'policies' => [
                'label' => 'Policies',
                'fields' => [
                    'dbvc_bricks_policy_entity_default',
                    'dbvc_bricks_policy_option_default',
                    'dbvc_bricks_policy_new_entity_gate',
                    'dbvc_bricks_policy_block_delete',
                    'dbvc_bricks_policy_max_diff_kb',
                    'dbvc_bricks_policy_overrides',
                ],
            ],
            'operations' => [
                'label' => 'Operations',
                'fields' => [
                    'dbvc_bricks_scan_mode',
                    'dbvc_bricks_scan_interval_minutes',
                    'dbvc_bricks_restore_before_apply',
                    'dbvc_bricks_restore_retention',
                    'dbvc_bricks_apply_batch_size',
                    'dbvc_bricks_apply_dry_run_default',
                ],
            ],
            'proposals' => [
                'label' => 'Proposals',
                'fields' => [
                    'dbvc_bricks_proposals_enabled',
                    'dbvc_bricks_proposals_auto_submit',
                    'dbvc_bricks_proposals_require_note',
                    'dbvc_bricks_proposals_queue_limit',
                    'dbvc_bricks_proposals_reviewer_group',
                    'dbvc_bricks_proposals_sla_hours',
                ],
            ],
        ];
    }

    /**
     * Get UI metadata for fields.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_field_meta()
    {
        $meta = [
            self::OPTION_ENABLED => ['label' => 'Enable Bricks Add-on', 'input' => 'checkbox'],
            self::OPTION_VISIBILITY => [
                'label' => 'Add-on Visibility Mode',
                'input' => 'select',
                'options' => ['submenu_only' => 'submenu_only', 'configure_and_submenu' => 'configure_and_submenu'],
            ],
            'dbvc_bricks_role' => ['label' => 'Role', 'input' => 'select', 'options' => ['mothership' => 'mothership', 'client' => 'client']],
            'dbvc_bricks_site_uid' => ['label' => 'Site UID', 'input' => 'text'],
            'dbvc_bricks_mothership_url' => [
                'label' => 'Mothership Base URL',
                'input' => 'url',
            ],
            'dbvc_bricks_auth_method' => ['label' => 'Auth Method', 'input' => 'select', 'options' => ['hmac' => 'hmac', 'api_key' => 'api_key', 'wp_app_password' => 'wp_app_password']],
            'dbvc_bricks_api_key_id' => ['label' => 'API Key ID', 'input' => 'text'],
            'dbvc_bricks_api_secret' => ['label' => 'API Secret', 'input' => 'password'],
            'dbvc_bricks_credentials_rotate_days' => ['label' => 'Credential Rotation Warning (days)', 'input' => 'number'],
            'dbvc_bricks_http_timeout' => ['label' => 'Request Timeout (sec)', 'input' => 'number'],
            'dbvc_bricks_tls_verify' => ['label' => 'Strict TLS Verify', 'input' => 'checkbox'],
            'dbvc_bricks_read_only' => ['label' => 'Read-only Mode', 'input' => 'checkbox'],
            self::OPTION_FLEET_MODE_ENABLED => ['label' => 'Fleet Mode Planning (Multisite)', 'input' => 'checkbox'],
            'dbvc_bricks_source_mode' => ['label' => 'Source Mode', 'input' => 'select', 'options' => ['mothership_api' => 'mothership_api', 'pinned_version' => 'pinned_version', 'local_package' => 'local_package']],
            'dbvc_bricks_channel' => ['label' => 'Channel', 'input' => 'select', 'options' => ['stable' => 'stable', 'beta' => 'beta', 'canary' => 'canary']],
            'dbvc_bricks_client_force_channel' => ['label' => 'Client Publish Force Channel', 'input' => 'select', 'options' => ['none' => 'none', 'canary' => 'canary', 'beta' => 'beta', 'stable' => 'stable']],
            'dbvc_bricks_force_stable_confirm' => ['label' => 'Require Stable Force Confirmation', 'input' => 'checkbox'],
            'dbvc_bricks_intro_auto_send' => ['label' => 'Intro Packet Auto-send', 'input' => 'checkbox'],
            'dbvc_bricks_intro_handshake_token' => ['label' => 'Intro Handshake Token', 'input' => 'password'],
            'dbvc_bricks_client_registry_state' => ['label' => 'Client Registry State', 'input' => 'select', 'options' => ['PENDING_INTRO' => 'PENDING_INTRO', 'VERIFIED' => 'VERIFIED', 'REJECTED' => 'REJECTED', 'DISABLED' => 'DISABLED']],
            'dbvc_bricks_intro_retry_max_attempts' => ['label' => 'Intro Retry Max Attempts', 'input' => 'number'],
            'dbvc_bricks_intro_retry_interval_minutes' => ['label' => 'Intro Retry Interval Minutes', 'input' => 'number'],
            'dbvc_bricks_connected_sites_source' => ['label' => 'Connected Sites Registry Source', 'input' => 'select', 'options' => ['manual' => 'manual', 'heartbeat_auto' => 'heartbeat_auto']],
            'dbvc_bricks_connected_sites_mode' => ['label' => 'Connected Sites Registry Mode', 'input' => 'select', 'options' => ['registry_table' => 'registry_table', 'packages_backfill' => 'packages_backfill']],
            'dbvc_bricks_pinned_version' => ['label' => 'Pinned Package Version', 'input' => 'text'],
            'dbvc_bricks_verify_signature' => ['label' => 'Verify Package Signature', 'input' => 'checkbox'],
            'dbvc_bricks_allow_fallback' => ['label' => 'Allow Fallback to Last Applied', 'input' => 'checkbox'],
            'dbvc_bricks_retention_count' => ['label' => 'Keep Package History (count)', 'input' => 'number'],
            'dbvc_bricks_fetch_batch' => ['label' => 'Package Fetch Batch Size', 'input' => 'number'],
            'dbvc_bricks_policy_entity_default' => ['label' => 'Default Policy: Entity artifacts', 'input' => 'select', 'options' => ['AUTO_ACCEPT' => 'AUTO_ACCEPT', 'REQUIRE_MANUAL_ACCEPT' => 'REQUIRE_MANUAL_ACCEPT', 'ALWAYS_OVERRIDE' => 'ALWAYS_OVERRIDE', 'REQUEST_REVIEW' => 'REQUEST_REVIEW', 'IGNORE' => 'IGNORE']],
            'dbvc_bricks_policy_option_default' => ['label' => 'Default Policy: Option artifacts', 'input' => 'select', 'options' => ['AUTO_ACCEPT' => 'AUTO_ACCEPT', 'REQUIRE_MANUAL_ACCEPT' => 'REQUIRE_MANUAL_ACCEPT', 'ALWAYS_OVERRIDE' => 'ALWAYS_OVERRIDE', 'REQUEST_REVIEW' => 'REQUEST_REVIEW', 'IGNORE' => 'IGNORE']],
            'dbvc_bricks_policy_new_entity_gate' => ['label' => 'New Entity Requires Explicit Accept', 'input' => 'checkbox'],
            'dbvc_bricks_policy_block_delete' => ['label' => 'Block Destructive Deletes', 'input' => 'checkbox'],
            'dbvc_bricks_policy_max_diff_kb' => ['label' => 'Max Diff Payload KB', 'input' => 'number'],
            'dbvc_bricks_policy_overrides' => ['label' => 'Per-artifact overrides (JSON map)', 'input' => 'textarea'],
            'dbvc_bricks_scan_mode' => ['label' => 'Drift Scan Mode', 'input' => 'select', 'options' => ['manual' => 'manual', 'scheduled' => 'scheduled']],
            'dbvc_bricks_scan_interval_minutes' => ['label' => 'Drift Scan Interval (minutes)', 'input' => 'number'],
            'dbvc_bricks_restore_before_apply' => ['label' => 'Auto-create Restore Point Before Apply', 'input' => 'checkbox'],
            'dbvc_bricks_restore_retention' => ['label' => 'Restore Point Retention', 'input' => 'number'],
            'dbvc_bricks_apply_batch_size' => ['label' => 'Apply Batch Size (artifacts)', 'input' => 'number'],
            'dbvc_bricks_apply_dry_run_default' => ['label' => 'Dry-run on Apply by Default', 'input' => 'checkbox'],
            'dbvc_bricks_proposals_enabled' => ['label' => 'Proposals Enabled', 'input' => 'checkbox'],
            'dbvc_bricks_proposals_auto_submit' => ['label' => 'Auto-submit on Divergence', 'input' => 'checkbox'],
            'dbvc_bricks_proposals_require_note' => ['label' => 'Proposal Require Note', 'input' => 'checkbox'],
            'dbvc_bricks_proposals_queue_limit' => ['label' => 'Proposal Queue Limit', 'input' => 'number'],
            'dbvc_bricks_proposals_reviewer_group' => ['label' => 'Default Reviewer Group', 'input' => 'text'],
            'dbvc_bricks_proposals_sla_hours' => ['label' => 'Mothership SLA (hours)', 'input' => 'number'],
        ];

        foreach (self::get_field_help_texts() as $field_key => $help_text) {
            if (! isset($meta[$field_key])) {
                continue;
            }
            $meta[$field_key]['help'] = $help_text;
        }

        return $meta;
    }

    /**
     * @return array<string, string>
     */
    private static function get_field_help_texts()
    {
        return [
            self::OPTION_ENABLED => 'Turn on to register Bricks submenu, routes, and jobs. Turn off to fully disable Bricks runtime.',
            self::OPTION_VISIBILITY => '`configure_and_submenu` (recommended): show Bricks settings in Configure and submenu. `submenu_only`: manage from submenu only.',
            'dbvc_bricks_role' => 'Set this site as `client` (consumes packages) or `mothership` (publishes/reviews).',
            'dbvc_bricks_site_uid' => 'Manual unique site slug (letters/numbers/._-). Use a fixed value per site, e.g. `client_a`.',
            'dbvc_bricks_mothership_url' => 'Enter base origin only (no trailing slash, no /wp-json). Example LocalWP: https://dbvc-mothership.local. Required when Role is client.',
            'dbvc_bricks_auth_method' => 'Choose request auth mode used for mothership calls. Use `wp_app_password` for WordPress Application Password authentication.',
            'dbvc_bricks_api_key_id' => 'Credential identifier. For `wp_app_password`, use the integration username/login on the mothership site.',
            'dbvc_bricks_api_secret' => 'Credential secret. For `wp_app_password`, paste the generated Application Password (spaces are allowed).',
            'dbvc_bricks_credentials_rotate_days' => 'Show warning when credentials are older than this number of days.',
            'dbvc_bricks_http_timeout' => 'HTTP request timeout in seconds for package/proposal operations.',
            'dbvc_bricks_tls_verify' => 'Keep enabled to verify TLS certificates on HTTPS requests.',
            'dbvc_bricks_read_only' => 'Disables mutating operations (apply/rollback/approval) while keeping review visibility.',
            self::OPTION_FLEET_MODE_ENABLED => 'Future multisite planning hook. Keep disabled unless fleet orchestration is intentionally enabled.',
            'dbvc_bricks_source_mode' => 'Select how this site resolves golden artifacts: API channel, pinned version, or local package.',
            'dbvc_bricks_channel' => 'Default channel to consume when source mode uses mothership API (`canary` early validation, `beta` pre-release, `stable` production).',
            'dbvc_bricks_client_force_channel' => 'Optional channel override for outgoing client publish operations (`none` keeps selected channel).',
            'dbvc_bricks_force_stable_confirm' => 'Require explicit operator confirmation before forcing outgoing publishes to `stable`.',
            'dbvc_bricks_intro_auto_send' => 'Automatically attempt introduction packet submission when valid mothership credentials are present.',
            'dbvc_bricks_intro_handshake_token' => 'Last accepted handshake token for trust establishment (if issued).',
            'dbvc_bricks_client_registry_state' => 'Onboarding lifecycle state for this client registry record.',
            'dbvc_bricks_intro_retry_max_attempts' => 'Maximum intro/handshake retry attempts before marking onboarding as blocked.',
            'dbvc_bricks_intro_retry_interval_minutes' => 'Retry interval in minutes for intro/handshake transport.',
            'dbvc_bricks_connected_sites_source' => 'Controls how connected-site records are populated (`manual` or heartbeat-assisted automation).',
            'dbvc_bricks_connected_sites_mode' => 'Data source strategy for Packages -> Connected Sites table (`registry_table` preferred, `packages_backfill` fallback).',
            'dbvc_bricks_pinned_version' => 'Set explicit package version/id when source mode is pinned version.',
            'dbvc_bricks_verify_signature' => 'Verify package signature before apply when signatures are available.',
            'dbvc_bricks_allow_fallback' => 'Allow fallback to last applied package when source retrieval fails.',
            'dbvc_bricks_retention_count' => 'Number of historical package records to retain locally.',
            'dbvc_bricks_fetch_batch' => 'Page size for remote package listing/fetch operations.',
            'dbvc_bricks_policy_entity_default' => 'Default policy for template Entity artifacts during apply planning.',
            'dbvc_bricks_policy_option_default' => 'Default policy for option artifacts during apply planning.',
            'dbvc_bricks_policy_new_entity_gate' => 'Require explicit acceptance before creating new template Entities.',
            'dbvc_bricks_policy_block_delete' => 'Blocks destructive delete operations unless explicitly allowed.',
            'dbvc_bricks_policy_max_diff_kb' => 'Maximum diff payload size before truncation/guardrails apply.',
            'dbvc_bricks_policy_overrides' => 'Optional JSON map of `artifact_uid => policy` for specific overrides.',
            'dbvc_bricks_scan_mode' => 'Choose manual scans only or scheduled drift scans.',
            'dbvc_bricks_scan_interval_minutes' => 'Scheduled scan interval in minutes when scan mode is scheduled.',
            'dbvc_bricks_restore_before_apply' => 'Automatically create a restore point before apply runs.',
            'dbvc_bricks_restore_retention' => 'Maximum number of restore points to keep.',
            'dbvc_bricks_apply_batch_size' => 'Number of artifacts processed per apply batch.',
            'dbvc_bricks_apply_dry_run_default' => 'Start apply operations in dry-run mode by default.',
            'dbvc_bricks_proposals_enabled' => 'Enable proposal submission/review workflows.',
            'dbvc_bricks_proposals_auto_submit' => 'Automatically create proposals for divergence events.',
            'dbvc_bricks_proposals_require_note' => 'Require operator note text when submitting proposals.',
            'dbvc_bricks_proposals_queue_limit' => 'Maximum queued proposal records before retention pruning.',
            'dbvc_bricks_proposals_reviewer_group' => 'Optional reviewer group slug/label for routing proposal review.',
            'dbvc_bricks_proposals_sla_hours' => 'Target review SLA window for proposal processing.',
        ];
    }

    /**
     * Get all settings with defaults applied.
     *
     * @return array<string, string>
     */
    public static function get_all_settings()
    {
        $values = [];
        foreach (self::get_settings_schema() as $key => $schema) {
            $values[$key] = (string) get_option($key, (string) $schema['default']);
        }
        return $values;
    }

    /**
     * Save settings from request payload.
     *
     * @param array<string, mixed> $request_data
     * @return array<string, mixed>
     */
    public static function save_settings(array $request_data)
    {
        $current = self::get_all_settings();
        $result = self::sanitize_settings_input($request_data, $current);
        $credential_keys = ['dbvc_bricks_auth_method', 'dbvc_bricks_api_key_id', 'dbvc_bricks_api_secret'];
        $credentials_changed = false;
        foreach ($credential_keys as $credential_key) {
            $old_value = isset($current[$credential_key]) ? (string) $current[$credential_key] : '';
            $new_value = isset($result['values'][$credential_key]) ? (string) $result['values'][$credential_key] : '';
            if ($old_value !== $new_value) {
                $credentials_changed = true;
                break;
            }
        }
        if ($credentials_changed) {
            $result['values']['dbvc_bricks_credentials_updated_at'] = gmdate('c');
        }
        foreach ($result['values'] as $key => $value) {
            update_option($key, $value);
        }
        self::refresh_runtime_registration();
        return $result;
    }

    /**
     * Typed setting getter.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function get_setting($key, $default = '')
    {
        return (string) get_option($key, $default);
    }

    /**
     * Typed bool getter.
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function get_bool_setting($key, $default = false)
    {
        return self::get_setting($key, $default ? '1' : '0') === '1';
    }

    /**
     * Typed integer getter.
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    public static function get_int_setting($key, $default = 0)
    {
        return (int) self::get_setting($key, (string) $default);
    }

    /**
     * Typed enum getter with allowlist fallback.
     *
     * @param string $key
     * @param array<int, string> $allowed
     * @param string $default
     * @return string
     */
    public static function get_enum_setting($key, array $allowed, $default)
    {
        $value = self::get_setting($key, $default);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Run lightweight migration for option-key versioning.
     *
     * @return void
     */
    public static function maybe_migrate_settings()
    {
        $stored_version = (int) get_option(self::OPTION_SETTINGS_VERSION, 0);
        if ($stored_version >= self::SETTINGS_VERSION) {
            return;
        }

        self::ensure_defaults();
        update_option(self::OPTION_SETTINGS_VERSION, (string) self::SETTINGS_VERSION);
    }

    /**
     * Get default values for all Bricks add-on settings.
     *
     * @return array<string, string>
     */
    public static function get_default_values()
    {
        $defaults = [self::OPTION_SETTINGS_VERSION => (string) self::SETTINGS_VERSION];
        foreach (self::get_settings_schema() as $key => $schema) {
            $defaults[$key] = (string) $schema['default'];
        }
        return $defaults;
    }

    /**
     * @param array<string, mixed> $request_data
     * @param array<string, string> $current
     * @return array<string, mixed>
     */
    private static function sanitize_settings_input(array $request_data, array $current)
    {
        $schema = self::get_settings_schema();
        $sanitized = [];
        $errors = [];

        foreach ($schema as $key => $field) {
            $type = (string) $field['type'];
            $default = (string) $field['default'];
            $current_value = isset($current[$key]) ? (string) $current[$key] : $default;
            $raw_value = isset($request_data[$key]) ? wp_unslash($request_data[$key]) : null;

            if ($type === 'bool') {
                $sanitized[$key] = isset($request_data[$key]) ? '1' : '0';
                continue;
            }

            if ($type === 'secret') {
                $candidate = is_string($raw_value) ? sanitize_text_field($raw_value) : '';
                $sanitized[$key] = $candidate === '' ? $current_value : $candidate;
                continue;
            }

            if ($raw_value === null) {
                $sanitized[$key] = $current_value;
                continue;
            }

            if ($type === 'enum') {
                $candidate = sanitize_key((string) $raw_value);
                $allowed = isset($field['allowed']) && is_array($field['allowed']) ? $field['allowed'] : [];
                if (! in_array($candidate, $allowed, true)) {
                    $sanitized[$key] = $current_value;
                    $errors[] = sprintf('%s is invalid.', $key);
                    continue;
                }
                $sanitized[$key] = $candidate;
                continue;
            }

            if ($type === 'int') {
                $candidate = absint($raw_value);
                $min = isset($field['min']) ? (int) $field['min'] : 0;
                $max = isset($field['max']) ? (int) $field['max'] : PHP_INT_MAX;
                if ($candidate < $min || $candidate > $max) {
                    $sanitized[$key] = $current_value;
                    $errors[] = sprintf('%s must be between %d and %d.', $key, $min, $max);
                    continue;
                }
                $sanitized[$key] = (string) $candidate;
                continue;
            }

            if ($type === 'url') {
                $candidate = esc_url_raw((string) $raw_value);
                if ($candidate !== '' && stripos($candidate, 'http') !== 0) {
                    $candidate = '';
                }
                $sanitized[$key] = $candidate;
                continue;
            }

            if ($type === 'key_id') {
                $candidate = sanitize_text_field((string) $raw_value);
                if ($candidate !== '' && ! preg_match('/^[A-Za-z0-9._-]{3,128}$/', $candidate)) {
                    $sanitized[$key] = $current_value;
                    $errors[] = sprintf('%s format is invalid.', $key);
                    continue;
                }
                $sanitized[$key] = $candidate;
                continue;
            }

            if ($type === 'json_map') {
                $candidate = trim((string) $raw_value);
                if ($candidate === '') {
                    $sanitized[$key] = '{}';
                    continue;
                }
                $decoded = json_decode($candidate, true);
                if (! is_array($decoded)) {
                    $sanitized[$key] = $current_value;
                    $errors[] = sprintf('%s must be valid JSON object/map.', $key);
                    continue;
                }

                if ($key === 'dbvc_bricks_policy_overrides') {
                    $allowed_policies = ['AUTO_ACCEPT', 'REQUIRE_MANUAL_ACCEPT', 'ALWAYS_OVERRIDE', 'REQUEST_REVIEW', 'IGNORE'];
                    $normalized = [];
                    $valid_map = true;
                    foreach ($decoded as $artifact_uid => $policy_value) {
                        if (! is_string($artifact_uid) || trim($artifact_uid) === '') {
                            $valid_map = false;
                            break;
                        }
                        $normalized_policy = strtoupper(sanitize_text_field((string) $policy_value));
                        if (! in_array($normalized_policy, $allowed_policies, true)) {
                            $valid_map = false;
                            break;
                        }
                        $normalized[(string) $artifact_uid] = $normalized_policy;
                    }

                    if (! $valid_map) {
                        $sanitized[$key] = $current_value;
                        $errors[] = sprintf('%s must map artifact UID keys to valid policy values.', $key);
                        continue;
                    }

                    $decoded = $normalized;
                }

                $sanitized[$key] = wp_json_encode($decoded);
                continue;
            }

            $sanitized[$key] = sanitize_text_field((string) $raw_value);
        }

        if (($sanitized['dbvc_bricks_role'] ?? 'client') === 'client' && ($sanitized['dbvc_bricks_mothership_url'] ?? '') === '') {
            $sanitized['dbvc_bricks_mothership_url'] = $current['dbvc_bricks_mothership_url'] ?? '';
            $errors[] = 'dbvc_bricks_mothership_url is required when role is client.';
        }

        $auth_method = $sanitized['dbvc_bricks_auth_method'] ?? 'hmac';
        if (in_array($auth_method, ['hmac', 'api_key'], true)) {
            if (($sanitized['dbvc_bricks_api_key_id'] ?? '') === '') {
                $sanitized['dbvc_bricks_api_key_id'] = $current['dbvc_bricks_api_key_id'] ?? '';
                $errors[] = 'dbvc_bricks_api_key_id is required for selected auth method.';
            }
            if (($sanitized['dbvc_bricks_api_secret'] ?? '') === '') {
                $sanitized['dbvc_bricks_api_secret'] = $current['dbvc_bricks_api_secret'] ?? '';
                $errors[] = 'dbvc_bricks_api_secret is required for selected auth method.';
            }
        }
        if ($auth_method === 'wp_app_password' && ($sanitized['dbvc_bricks_api_secret'] ?? '') === '') {
            $sanitized['dbvc_bricks_api_secret'] = $current['dbvc_bricks_api_secret'] ?? '';
            $errors[] = 'dbvc_bricks_api_secret is required for wp_app_password auth method.';
        }

        if (($sanitized['dbvc_bricks_source_mode'] ?? 'mothership_api') === 'pinned_version' && ($sanitized['dbvc_bricks_pinned_version'] ?? '') === '') {
            $sanitized['dbvc_bricks_pinned_version'] = $current['dbvc_bricks_pinned_version'] ?? '';
            $errors[] = 'dbvc_bricks_pinned_version is required when source mode is pinned_version.';
        }

        if (($sanitized['dbvc_bricks_scan_mode'] ?? 'manual') === 'scheduled' && empty($sanitized['dbvc_bricks_scan_interval_minutes'])) {
            $sanitized['dbvc_bricks_scan_interval_minutes'] = $current['dbvc_bricks_scan_interval_minutes'] ?? '60';
            $errors[] = 'dbvc_bricks_scan_interval_minutes is required when scan mode is scheduled.';
        }

        return [
            'values' => $sanitized,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * Register or unregister runtime hooks based on enable state.
     *
     * @return void
     */
    public static function refresh_runtime_registration()
    {
        remove_action('admin_menu', [self::class, 'register_admin_submenu']);
        remove_action('admin_menu', [self::class, 'register_admin_submenu'], self::ADMIN_MENU_PRIORITY);
        remove_action('admin_init', [self::class, 'maybe_redirect_admin_path']);
        remove_action('rest_api_init', [self::class, 'register_rest_routes']);
        remove_action('init', [self::class, 'maybe_register_scheduled_jobs']);
        remove_action(self::CRON_HOOK, [self::class, 'run_scheduled_job']);

        if (! self::is_enabled()) {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }
            return;
        }

        add_action('admin_menu', [self::class, 'register_admin_submenu'], self::ADMIN_MENU_PRIORITY);
        add_action('admin_init', [self::class, 'maybe_redirect_admin_path']);
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
        add_action('init', [self::class, 'maybe_register_scheduled_jobs']);
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled_job']);
    }

    /**
     * Is the Bricks add-on enabled.
     *
     * @return bool
     */
    public static function is_enabled()
    {
        return get_option(self::OPTION_ENABLED, '0') === '1';
    }

    /**
     * Register Bricks submenu under DBVC.
     *
     * @return void
     */
    public static function register_admin_submenu()
    {
        add_submenu_page(
            'dbvc-export',
            esc_html__('Bricks Add-on', 'dbvc'),
            esc_html__('Bricks', 'dbvc'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render_admin_page']
        );
    }

    /**
     * Get canonical Bricks admin page URL under DBVC.
     *
     * @return string
     */
    public static function get_admin_page_url()
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    /**
     * Redirect legacy direct admin path requests to canonical submenu URL.
     *
     * @return void
     */
    public static function maybe_redirect_admin_path()
    {
        if (! is_admin()) {
            return;
        }

        if ((string) ($_GET['page'] ?? '') === self::MENU_SLUG) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
        if ($request_uri === '') {
            return;
        }

        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        if (! is_string($path)) {
            return;
        }

        $trimmed = untrailingslashit($path);
        $allowed_slugs = [self::MENU_SLUG, self::LEGACY_MENU_SLUG];
        $is_supported_legacy_path = false;
        foreach ($allowed_slugs as $slug) {
            if (preg_match('#/wp-admin/' . preg_quote($slug, '#') . '$#', $trimmed)) {
                $is_supported_legacy_path = true;
                break;
            }
        }

        if (! $is_supported_legacy_path) {
            return;
        }

        wp_safe_redirect(self::get_admin_page_url(), 301);
        exit;
    }

    /**
     * Render the Bricks add-on submenu page.
     *
     * @return void
     */
    public static function render_admin_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'dbvc'));
        }

        if (! self::is_enabled()) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('DBVC Bricks Add-on', 'dbvc') . '</h1>';
            echo '<div class="notice notice-warning"><p>' . esc_html__('Bricks add-on is disabled. Enable it in Configure -> Add-ons to access submenu actions.', 'dbvc') . '</p></div>';
            echo '</div>';
            return;
        }

        $role_mode = self::get_role_mode();
        $is_read_only = self::get_bool_setting('dbvc_bricks_read_only', false);
        $tabs = self::get_admin_tabs_for_role($role_mode);
        $current_tab = self::get_current_admin_tab($tabs);
        $configure_addons_url = add_query_arg(
            [
                'page' => 'dbvc-export',
                'tab' => 'configure',
                'subtab' => 'addons',
            ],
            admin_url('admin.php')
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('DBVC Bricks Add-on', 'dbvc') . '</h1>';
        echo '<p>' . esc_html__('Role-aware Bricks controls and status for this site.', 'dbvc') . '</p>';
        if ($is_read_only) {
            echo '<div class="notice notice-info" id="dbvc-bricks-read-only-notice"><p>' . esc_html__('Read-only mode is enabled. Mutating actions are disabled.', 'dbvc') . '</p></div>';
        }
        $deprecations = self::get_deprecation_notices();
        if (! empty($deprecations)) {
            foreach ($deprecations as $deprecation) {
                $message = isset($deprecation['message']) ? (string) $deprecation['message'] : '';
                if ($message === '') {
                    continue;
                }
                echo '<div class="notice notice-warning dbvc-bricks-deprecation-notice"><p>' . esc_html($message) . '</p></div>';
            }
        }

        echo '<details id="dbvc-bricks-onboarding" class="dbvc-bricks-onboarding" open>';
        echo '<summary><strong>' . esc_html__('First-Time Checklist', 'dbvc') . '</strong> - ' . esc_html__('Guided setup for new operators', 'dbvc') . '</summary>';
        echo '<p>' . esc_html__('Complete each step once for this site role. Your progress is saved in this browser.', 'dbvc') . '</p>';
        echo '<p id="dbvc-bricks-onboarding-progress" style="margin:8px 0;"><strong>' . esc_html__('0/0 complete', 'dbvc') . '</strong></p>';
        echo '<ul id="dbvc-bricks-onboarding-list" style="margin-left:18px;list-style:disc;">';
        echo '<li><label><input type="checkbox" class="dbvc-bricks-onboarding-check" data-key="enabled" /> ' . esc_html__('Enable Bricks add-on in Configure -> Add-ons.', 'dbvc') . '</label></li>';
        echo '<li><label><input type="checkbox" class="dbvc-bricks-onboarding-check" data-key="role" /> ' . esc_html__('Confirm site role and policies in Configure -> Add-ons.', 'dbvc') . '</label></li>';
        echo '<li><label><input type="checkbox" class="dbvc-bricks-onboarding-check" data-key="scan" /> ' . esc_html__('Run a drift scan in Differences.', 'dbvc') . '</label></li>';
        echo '<li><label><input type="checkbox" class="dbvc-bricks-onboarding-check" data-key="review" /> ' . esc_html__('Review Entity and option artifact diffs before actions.', 'dbvc') . '</label></li>';
        if ($role_mode === 'client') {
            echo '<li><label><input type="checkbox" class="dbvc-bricks-onboarding-check" data-key="client_apply" /> ' . esc_html__('Run Dry Run Apply and create a restore point before real apply.', 'dbvc') . '</label></li>';
        } else {
            echo '<li><label><input type="checkbox" class="dbvc-bricks-onboarding-check" data-key="mothership_pkg" /> ' . esc_html__('Review package channel/version details in Packages.', 'dbvc') . '</label></li>';
        }
        echo '<li><label><input type="checkbox" class="dbvc-bricks-onboarding-check" data-key="docs" /> ' . esc_html__('Read the Documentation tab for advanced operations.', 'dbvc') . '</label></li>';
        echo '</ul>';
        echo '<p>';
        echo '<button type="button" class="button button-secondary" id="dbvc-bricks-onboarding-mark-all">' . esc_html__('Mark All Complete', 'dbvc') . '</button> ';
        echo '<button type="button" class="button" id="dbvc-bricks-onboarding-reset">' . esc_html__('Reset Checklist', 'dbvc') . '</button>';
        echo '</p>';
        echo '</details>';

        echo '<div id="dbvc-bricks-notice-success" class="notice notice-success is-dismissible" style="display:none;"><p></p></div>';
        echo '<div id="dbvc-bricks-notice-error" class="notice notice-error" style="display:none;"><p></p><p><button type="button" class="button button-secondary" id="dbvc-bricks-retry-last-action" style="display:none;">' . esc_html__('Retry Last Action', 'dbvc') . '</button></p></div>';
        echo '<div id="dbvc-bricks-loading" class="notice notice-info" style="display:none;"><p>' . esc_html__('Loading Bricks data...', 'dbvc') . '</p></div>';

        echo '<h2 class="nav-tab-wrapper" id="dbvc-bricks-admin-tabs" role="tablist" aria-label="' . esc_attr__('Bricks admin sections', 'dbvc') . '">';
        foreach ($tabs as $tab_key => $tab_label) {
            $is_active = $tab_key === $current_tab;
            $tab_url = add_query_arg(
                [
                    'page' => self::MENU_SLUG,
                    'tab'  => $tab_key,
                ],
                admin_url('admin.php')
            );
            echo '<a href="' . esc_url($tab_url) . '" class="nav-tab' . ($is_active ? ' nav-tab-active' : '') . '" data-dbvc-bricks-tab="' . esc_attr($tab_key) . '" id="dbvc-bricks-tab-' . esc_attr($tab_key) . '" role="tab" aria-controls="dbvc-bricks-panel-' . esc_attr($tab_key) . '" aria-selected="' . ($is_active ? 'true' : 'false') . '">' . esc_html($tab_label) . '</a>';
        }
        echo '</h2>';

        echo '<div id="dbvc-bricks-admin-panels" data-role="' . esc_attr($role_mode) . '" data-read-only="' . ($is_read_only ? '1' : '0') . '">';
        if (isset($tabs['overview'])) {
            echo '<section id="dbvc-bricks-panel-overview" class="dbvc-bricks-panel" role="tabpanel" aria-labelledby="dbvc-bricks-tab-overview" tabindex="0"' . ($current_tab === 'overview' ? '' : ' hidden') . '>';
            echo '<h2>' . esc_html__('Overview', 'dbvc') . '</h2>';
            echo '<p><strong>' . esc_html__('Role:', 'dbvc') . '</strong> ' . esc_html($role_mode) . '</p>';
            echo '<p><strong>' . esc_html__('Last Updated:', 'dbvc') . '</strong> <span id="dbvc-bricks-last-updated">' . esc_html__('Not loaded yet', 'dbvc') . '</span></p>';
            echo '<p><button type="button" class="button button-secondary" id="dbvc-bricks-refresh-status">' . esc_html__('Refresh Status', 'dbvc') . '</button></p>';
            echo '<p><button type="button" class="button button-secondary" id="dbvc-bricks-refresh-diagnostics">' . esc_html__('Refresh Diagnostics', 'dbvc') . '</button></p>';
            echo '<pre id="dbvc-bricks-status-json" style="max-height:320px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;"></pre>';
            echo '<h3>' . esc_html__('Recent Diagnostics', 'dbvc') . '</h3>';
            echo '<pre id="dbvc-bricks-diagnostics-json" style="max-height:220px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;" tabindex="0"></pre>';
            echo '</section>';
        }
        if (isset($tabs['differences'])) {
            echo '<section id="dbvc-bricks-panel-differences" class="dbvc-bricks-panel" role="tabpanel" aria-labelledby="dbvc-bricks-tab-differences" tabindex="0"' . ($current_tab === 'differences' ? '' : ' hidden') . '>';
            echo '<h2>' . esc_html__('Differences', 'dbvc') . '</h2>';
            echo '<p>' . esc_html__('Run drift scan and review template Entity and option artifact differences.', 'dbvc') . '</p>';
            echo '<p>';
            echo '<label for="dbvc-bricks-package-select"><strong>' . esc_html__('Package', 'dbvc') . '</strong></label> ';
            echo '<select id="dbvc-bricks-package-select"></select> ';
            echo '<button type="button" class="button" id="dbvc-bricks-refresh-packages-inline">' . esc_html__('Refresh Packages', 'dbvc') . '</button> ';
            echo '<button type="button" class="button button-primary" id="dbvc-bricks-run-drift-scan">' . esc_html__('Run Drift Scan', 'dbvc') . '</button> ';
            echo '<button type="button" class="button" id="dbvc-bricks-export-review">' . esc_html__('Export Review JSON', 'dbvc') . '</button> ';
            if ($role_mode === 'client' && ! $is_read_only) {
                echo '<button type="button" class="button" id="dbvc-bricks-publish-remote">' . esc_html__('Publish Package to Mothership', 'dbvc') . '</button>';
            }
            echo '</p>';
            echo '<p>';
            echo '<label for="dbvc-bricks-filter-class">' . esc_html__('Artifact Class', 'dbvc') . '</label> ';
            echo '<select id="dbvc-bricks-filter-class">';
            echo '<option value="all">' . esc_html__('All', 'dbvc') . '</option>';
            echo '<option value="Entity">' . esc_html__('Entity', 'dbvc') . '</option>';
            echo '<option value="Option">' . esc_html__('Option', 'dbvc') . '</option>';
            echo '</select> ';
            echo '<label for="dbvc-bricks-filter-status">' . esc_html__('Status', 'dbvc') . '</label> ';
            echo '<select id="dbvc-bricks-filter-status">';
            echo '<option value="all">' . esc_html__('All', 'dbvc') . '</option>';
            echo '<option value="CLEAN">CLEAN</option>';
            echo '<option value="DIVERGED">DIVERGED</option>';
            echo '<option value="OVERRIDDEN">OVERRIDDEN</option>';
            echo '<option value="PENDING_REVIEW">PENDING_REVIEW</option>';
            echo '</select> ';
            echo '<label for="dbvc-bricks-filter-search">' . esc_html__('Search', 'dbvc') . '</label> ';
            echo '<input type="search" id="dbvc-bricks-filter-search" placeholder="' . esc_attr__('artifact uid', 'dbvc') . '" />';
            echo '</p>';
            echo '<div id="dbvc-bricks-diff-summary-cards">';
            echo '<span class="button" id="dbvc-bricks-count-clean">CLEAN: 0</span> ';
            echo '<span class="button" id="dbvc-bricks-count-diverged">DIVERGED: 0</span> ';
            echo '<span class="button" id="dbvc-bricks-count-overridden">OVERRIDDEN: 0</span> ';
            echo '<span class="button" id="dbvc-bricks-count-pending">PENDING_REVIEW: 0</span>';
            echo '</div>';
            echo '<div style="display:flex;gap:16px;align-items:flex-start;margin-top:12px;">';
            echo '<div style="flex:1 1 50%;">';
            echo '<table class="widefat striped" id="dbvc-bricks-diff-table" aria-label="' . esc_attr__('Bricks difference results', 'dbvc') . '">';
            echo '<thead><tr><th>' . esc_html__('Artifact', 'dbvc') . '</th><th>' . esc_html__('Class', 'dbvc') . '</th><th>' . esc_html__('Status', 'dbvc') . '</th></tr></thead>';
            echo '<tbody id="dbvc-bricks-diff-table-body"><tr><td colspan="3">' . esc_html__('Run a drift scan to load differences.', 'dbvc') . '</td></tr></tbody>';
            echo '</table>';
            echo '</div>';
            echo '<div style="flex:1 1 50%;">';
            echo '<h3>' . esc_html__('Detail', 'dbvc') . '</h3>';
            echo '<div id="dbvc-bricks-diff-detail" tabindex="0">' . esc_html__('Select an artifact to inspect details.', 'dbvc') . '</div>';
            echo '</div>';
            echo '</div>';
            echo '</section>';
        }
        if (isset($tabs['apply_restore'])) {
            $disabled_attr = $is_read_only ? ' disabled="disabled"' : '';
            echo '<section id="dbvc-bricks-panel-apply-restore" class="dbvc-bricks-panel" role="tabpanel" aria-labelledby="dbvc-bricks-tab-apply_restore" tabindex="0"' . ($current_tab === 'apply_restore' ? '' : ' hidden') . '>';
            echo '<h2>' . esc_html__('Apply & Restore', 'dbvc') . '</h2>';
            echo '<p>' . esc_html__('Client-only actions for dry-run/apply and restore workflows.', 'dbvc') . '</p>';
            echo '<p><label><input type="checkbox" id="dbvc-bricks-apply-dry-run" checked="checked" /> ' . esc_html__('Dry-run', 'dbvc') . '</label> ';
            echo '<label><input type="checkbox" id="dbvc-bricks-apply-allow-destructive" /> ' . esc_html__('Allow destructive operations', 'dbvc') . '</label> ';
            echo '<label><input type="checkbox" id="dbvc-bricks-bulk-mode" /> ' . esc_html__('Bulk mode (chunked)', 'dbvc') . '</label> ';
            echo '<label for="dbvc-bricks-bulk-chunk-size">' . esc_html__('Chunk Size', 'dbvc') . '</label> ';
            echo '<input type="number" id="dbvc-bricks-bulk-chunk-size" min="1" max="200" value="25" style="width:80px;" /></p>';
            echo '<p>';
            echo '<button type="button" class="button" id="dbvc-bricks-dry-run-apply">' . esc_html__('Dry Run Apply', 'dbvc') . '</button> ';
            echo '<button type="button" class="button button-primary" id="dbvc-bricks-apply-selected"' . $disabled_attr . '>' . esc_html__('Apply Selected', 'dbvc') . '</button> ';
            echo '<button type="button" class="button" id="dbvc-bricks-create-restore-point"' . $disabled_attr . '>' . esc_html__('Create Restore Point', 'dbvc') . '</button>';
            echo '</p>';
            echo '<p><label for="dbvc-bricks-rollback-restore-id">' . esc_html__('Rollback Restore ID', 'dbvc') . '</label> ';
            echo '<input type="text" id="dbvc-bricks-rollback-restore-id" placeholder="' . esc_attr__('bricks-restore-...', 'dbvc') . '" /> ';
            echo '<button type="button" class="button" id="dbvc-bricks-run-rollback"' . $disabled_attr . '>' . esc_html__('Run Rollback', 'dbvc') . '</button></p>';
            echo '<pre id="dbvc-bricks-apply-output" style="max-height:240px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;"></pre>';
            echo '</section>';
        }
        if (isset($tabs['proposals'])) {
            $disabled_attr = $is_read_only ? ' disabled="disabled"' : '';
            echo '<section id="dbvc-bricks-panel-proposals" class="dbvc-bricks-panel" role="tabpanel" aria-labelledby="dbvc-bricks-tab-proposals" tabindex="0"' . ($current_tab === 'proposals' ? '' : ' hidden') . '>';
            echo '<h2>' . esc_html__('Proposals', 'dbvc') . '</h2>';
            echo '<p>' . esc_html__('Submit and review proposal state transitions for Bricks artifacts.', 'dbvc') . '</p>';
            echo '<p><label for="dbvc-bricks-proposal-status-filter">' . esc_html__('Status Filter', 'dbvc') . '</label> ';
            echo '<select id="dbvc-bricks-proposal-status-filter">';
            echo '<option value="">' . esc_html__('All', 'dbvc') . '</option>';
            echo '<option value="RECEIVED">RECEIVED</option><option value="APPROVED">APPROVED</option><option value="REJECTED">REJECTED</option><option value="NEEDS_CHANGES">NEEDS_CHANGES</option>';
            echo '</select> ';
            echo '<button type="button" class="button" id="dbvc-bricks-refresh-proposals">' . esc_html__('Refresh Proposals', 'dbvc') . '</button> ';
            echo '<button type="button" class="button button-primary" id="dbvc-bricks-submit-proposal"' . $disabled_attr . '>' . esc_html__('Submit Proposal (Selected Diff)', 'dbvc') . '</button></p>';
            echo '<p><label for="dbvc-bricks-proposal-review-notes">' . esc_html__('Review Notes', 'dbvc') . '</label><br />';
            echo '<textarea id="dbvc-bricks-proposal-review-notes" rows="3" style="min-width:420px;"></textarea></p>';
            echo '<p>';
            echo '<button type="button" class="button" id="dbvc-bricks-proposal-approve"' . $disabled_attr . '>Approve</button> ';
            echo '<button type="button" class="button" id="dbvc-bricks-proposal-reject"' . $disabled_attr . '>Reject</button> ';
            echo '<button type="button" class="button" id="dbvc-bricks-proposal-needs-changes"' . $disabled_attr . '>Needs Changes</button>';
            echo '</p>';
            echo '<table class="widefat striped" id="dbvc-bricks-proposals-table" aria-label="' . esc_attr__('Bricks proposals', 'dbvc') . '">';
            echo '<thead><tr><th>' . esc_html__('Proposal', 'dbvc') . '</th><th>' . esc_html__('Artifact', 'dbvc') . '</th><th>' . esc_html__('Status', 'dbvc') . '</th></tr></thead>';
            echo '<tbody id="dbvc-bricks-proposals-table-body"><tr><td colspan="3">' . esc_html__('No proposals loaded.', 'dbvc') . '</td></tr></tbody>';
            echo '</table>';
            echo '<pre id="dbvc-bricks-proposal-detail" style="max-height:220px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;margin-top:12px;" tabindex="0"></pre>';
            echo '</section>';
        }
        if (isset($tabs['packages'])) {
            echo '<section id="dbvc-bricks-panel-packages" class="dbvc-bricks-panel" role="tabpanel" aria-labelledby="dbvc-bricks-tab-packages" tabindex="0"' . ($current_tab === 'packages' ? '' : ' hidden') . '>';
            echo '<h2>' . esc_html__('Packages', 'dbvc') . '</h2>';
            echo '<p>' . esc_html__('Mothership package listing and inspection controls.', 'dbvc') . '</p>';
            $force_channel = self::get_enum_setting('dbvc_bricks_client_force_channel', ['none', 'canary', 'beta', 'stable'], 'none');
            $credentials_updated_at = self::get_setting('dbvc_bricks_credentials_updated_at', '');
            $credentials_rotate_days = self::get_int_setting('dbvc_bricks_credentials_rotate_days', 90);
            if ($credentials_rotate_days < 1) {
                $credentials_rotate_days = 90;
            }
            $credentials_warning = '';
            if ($credentials_updated_at !== '') {
                $age_seconds = time() - (int) strtotime($credentials_updated_at);
                if ($age_seconds > ($credentials_rotate_days * DAY_IN_SECONDS)) {
                    $credentials_warning = sprintf(
                        'Credential rotation warning: credentials are older than %d days (last updated %s UTC).',
                        $credentials_rotate_days,
                        gmdate('Y-m-d H:i', (int) strtotime($credentials_updated_at))
                    );
                }
            }
            if ($role_mode === 'client') {
                $site_uid_display = self::get_setting('dbvc_bricks_site_uid', '');
                if ($site_uid_display === '') {
                    $site_uid_display = 'site_' . get_current_blog_id();
                }
                echo '<p><em>' . esc_html__('Client view is filtered to packages eligible for this site UID:', 'dbvc') . ' <code>' . esc_html($site_uid_display) . '</code>.</em></p>';
                if ($force_channel === 'stable') {
                    echo '<div class="notice notice-warning" style="margin:8px 0 12px 0;"><p><strong>' . esc_html__('Stable Force Channel Active:', 'dbvc') . '</strong> ' . esc_html__('Outgoing client publish channel is forced to stable. Explicit confirmation is required before publish.', 'dbvc') . '</p></div>';
                }
                if ($credentials_warning !== '') {
                    echo '<div class="notice notice-warning" style="margin:8px 0 12px 0;"><p>' . esc_html($credentials_warning) . '</p></div>';
                }
            }
            echo '<div class="notice notice-info" style="margin:8px 0 12px 0;"><p><strong>' . esc_html__('Channel definitions:', 'dbvc') . '</strong> '
                . esc_html__('canary = first validation group, beta = wider pre-release rollout, stable = production-ready release.', 'dbvc')
                . ' ' . esc_html__('Create on client with the channel you need; promote forward on mothership only (canary -> beta -> stable).', 'dbvc')
                . '</p></div>';
            echo '<p><label for="dbvc-bricks-packages-channel-filter">' . esc_html__('Channel', 'dbvc') . '</label> ';
            echo '<select id="dbvc-bricks-packages-channel-filter"><option value="">' . esc_html__('All', 'dbvc') . '</option><option value="stable">stable</option><option value="beta">beta</option><option value="canary">canary</option></select> ';
            echo '<button type="button" class="button" id="dbvc-bricks-refresh-packages">' . esc_html__('Refresh Packages', 'dbvc') . '</button> ';
            echo '<button type="button" class="button" id="dbvc-bricks-bootstrap-package">' . esc_html__('Create Package from Current Site', 'dbvc') . '</button></p>';
            echo '<h3>' . esc_html__('Connected Sites Targeting', 'dbvc') . '</h3>';
            echo '<p><label for="dbvc-bricks-target-mode">' . esc_html__('Target Mode', 'dbvc') . '</label> ';
            echo '<select id="dbvc-bricks-target-mode"><option value="all">all</option><option value="selected">selected</option></select> ';
            echo '<button type="button" class="button" id="dbvc-bricks-refresh-connected-sites">' . esc_html__('Refresh Connected Sites', 'dbvc') . '</button> ';
            echo '<button type="button" class="button" id="dbvc-bricks-save-connected-sites">' . esc_html__('Save Allowlist', 'dbvc') . '</button></p>';
            echo '<p><label for="dbvc-bricks-connected-sites-search">' . esc_html__('Search', 'dbvc') . '</label> ';
            echo '<input type="search" id="dbvc-bricks-connected-sites-search" placeholder="' . esc_attr__('site uid or label', 'dbvc') . '" /> ';
            echo '<label for="dbvc-bricks-connected-sites-status-filter">' . esc_html__('Status', 'dbvc') . '</label> ';
            echo '<select id="dbvc-bricks-connected-sites-status-filter"><option value="">' . esc_html__('All', 'dbvc') . '</option><option value="online">online</option><option value="offline">offline</option><option value="disabled">disabled</option></select> ';
            echo '<label for="dbvc-bricks-connected-sites-sort">' . esc_html__('Sort', 'dbvc') . '</label> ';
            echo '<select id="dbvc-bricks-connected-sites-sort"><option value="site_uid_asc">site_uid (A-Z)</option><option value="site_uid_desc">site_uid (Z-A)</option><option value="site_label_asc">label (A-Z)</option><option value="site_label_desc">label (Z-A)</option><option value="last_seen_desc">last seen (newest)</option><option value="last_seen_asc">last seen (oldest)</option></select> ';
            echo '<button type="button" class="button" id="dbvc-bricks-select-visible-sites">' . esc_html__('Select Visible', 'dbvc') . '</button> ';
            echo '<button type="button" class="button" id="dbvc-bricks-clear-visible-sites">' . esc_html__('Clear Visible', 'dbvc') . '</button></p>';
            echo '<table class="widefat striped" id="dbvc-bricks-connected-sites-table" aria-label="' . esc_attr__('Bricks connected sites', 'dbvc') . '">';
            echo '<thead><tr><th>' . esc_html__('Allow', 'dbvc') . '</th><th>' . esc_html__('Site UID', 'dbvc') . '</th><th>' . esc_html__('Label', 'dbvc') . '</th><th>' . esc_html__('Status', 'dbvc') . '</th><th>' . esc_html__('Onboarding', 'dbvc') . '</th><th>' . esc_html__('Last Seen', 'dbvc') . '</th></tr></thead>';
            echo '<tbody id="dbvc-bricks-connected-sites-body"><tr><td colspan="6">' . esc_html__('No connected sites loaded.', 'dbvc') . '</td></tr></tbody>';
            echo '</table>';
            echo '<p><button type="button" class="button" id="dbvc-bricks-publish-remote-preflight">' . esc_html__('Run Publish Preflight', 'dbvc') . '</button> ';
            echo '<button type="button" class="button" id="dbvc-bricks-test-remote-connection">' . esc_html__('Test Mothership Connection', 'dbvc') . '</button> ';
            if ($role_mode === 'client' && ! $is_read_only) {
                echo '<label style="margin-right:8px;"><input type="checkbox" id="dbvc-bricks-force-stable-confirm" /> ' . esc_html__('Confirm forced stable publish', 'dbvc') . '</label> ';
                echo '<button type="button" class="button" id="dbvc-bricks-pull-latest-dry-run">' . esc_html__('Pull Latest Allowed + Dry Run', 'dbvc') . '</button> ';
                echo '<button type="button" class="button" id="dbvc-bricks-publish-remote-packages">' . esc_html__('Publish Package to Mothership', 'dbvc') . '</button> ';
            }
            if ($role_mode === 'mothership' && ! $is_read_only) {
                echo '<label for="dbvc-bricks-promote-channel" class="screen-reader-text">' . esc_html__('Promote Channel', 'dbvc') . '</label> ';
                echo '<select id="dbvc-bricks-promote-channel"><option value="beta">beta</option><option value="stable">stable</option></select> ';
                echo '<button type="button" class="button" id="dbvc-bricks-promote-package">' . esc_html__('Promote Selected Package', 'dbvc') . '</button> ';
                echo '<button type="button" class="button" id="dbvc-bricks-revoke-package">' . esc_html__('Revoke Selected Package', 'dbvc') . '</button> ';
            }
            echo '<button type="button" class="button button-primary" id="dbvc-bricks-publish-local-targeted">' . esc_html__('Publish Selected Package (Targeting Applied)', 'dbvc') . '</button></p>';
            echo '<table class="widefat striped" id="dbvc-bricks-packages-table" aria-label="' . esc_attr__('Bricks packages', 'dbvc') . '">';
            echo '<thead><tr><th>' . esc_html__('Select', 'dbvc') . '</th><th>' . esc_html__('Package', 'dbvc') . '</th><th>' . esc_html__('Version', 'dbvc') . '</th><th>' . esc_html__('Channel', 'dbvc') . '</th><th>' . esc_html__('Audience', 'dbvc') . '</th></tr></thead>';
            echo '<tbody id="dbvc-bricks-packages-table-body"><tr><td colspan="5">' . esc_html__('No packages loaded.', 'dbvc') . '</td></tr></tbody>';
            echo '</table>';
            echo '<pre id="dbvc-bricks-package-detail" style="max-height:260px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;margin-top:12px;" tabindex="0"></pre>';
            echo '</section>';
        }
        if (isset($tabs['documentation'])) {
            echo '<section id="dbvc-bricks-panel-documentation" class="dbvc-bricks-panel" role="tabpanel" aria-labelledby="dbvc-bricks-tab-documentation" tabindex="0"' . ($current_tab === 'documentation' ? '' : ' hidden') . '>';
            echo '<h2>' . esc_html__('Documentation', 'dbvc') . '</h2>';
            echo '<p>' . esc_html__('Use this page to configure, review, and operate Bricks synchronization safely.', 'dbvc') . '</p>';
            echo '<h3>' . esc_html__('Quick Start', 'dbvc') . '</h3>';
            echo '<ol>';
            echo '<li>' . sprintf(
                esc_html__('Enable the Bricks add-on in %s.', 'dbvc'),
                '<a href="' . esc_url($configure_addons_url) . '">' . esc_html__('Configure -> Add-ons', 'dbvc') . '</a>'
            ) . '</li>';
            echo '<li>' . esc_html__('Set role mode (`client` or `mothership`) and policy settings in Configure -> Add-ons.', 'dbvc') . '</li>';
            echo '<li>' . esc_html__('Open `Differences` and run a drift scan against a selected package.', 'dbvc') . '</li>';
            echo '<li>' . esc_html__('Review diff details before apply/proposal actions.', 'dbvc') . '</li>';
            echo '</ol>';
            echo '<h3>' . esc_html__('Role Guidance', 'dbvc') . '</h3>';
            echo '<p>' . esc_html__('Client role: use `Apply & Restore` for dry-run/apply/rollback, and `Proposals` for submission and review.', 'dbvc') . '</p>';
            echo '<p>' . esc_html__('Mothership role: use `Packages` to inspect package manifests and channel versions before distribution.', 'dbvc') . '</p>';
            echo '<h3>' . esc_html__('Channel Definitions & Usage', 'dbvc') . '</h3>';
            echo '<ul>';
            echo '<li><strong>' . esc_html__('canary', 'dbvc') . ':</strong> ' . esc_html__('First validation stage for a small, controlled audience. Use this for initial verification after package creation.', 'dbvc') . '</li>';
            echo '<li><strong>' . esc_html__('beta', 'dbvc') . ':</strong> ' . esc_html__('Wider pre-release stage after canary passes. Use this to expand confidence before production.', 'dbvc') . '</li>';
            echo '<li><strong>' . esc_html__('stable', 'dbvc') . ':</strong> ' . esc_html__('Production-ready stage. Promote to stable only after canary and beta checks complete.', 'dbvc') . '</li>';
            echo '</ul>';
            echo '<p>' . esc_html__('How to use: clients create packages at a starting channel, then mothership promotes forward (`canary -> beta -> stable`) or revokes if needed.', 'dbvc') . '</p>';
            echo '<h3>' . esc_html__('Additional Functionality', 'dbvc') . '</h3>';
            echo '<ul>';
            echo '<li>' . esc_html__('Export Review JSON: export current drift review data for offline approvals or ticket attachments.', 'dbvc') . '</li>';
            echo '<li>' . esc_html__('Bulk mode (chunked): apply selected artifacts in deterministic chunks for safer large runs.', 'dbvc') . '</li>';
            echo '<li>' . esc_html__('Diagnostics: inspect recent UI events and failures from the Overview diagnostics panel.', 'dbvc') . '</li>';
            echo '<li>' . esc_html__('Read-only mode: blocks mutating actions while preserving visibility for review and auditing.', 'dbvc') . '</li>';
            echo '</ul>';
            echo '<h3>' . esc_html__('Safety Rules', 'dbvc') . '</h3>';
            echo '<p>' . esc_html__('Use dry-run first, create restore points before destructive changes, and verify drift status after apply.', 'dbvc') . '</p>';
            echo '</section>';
        }
        $overlay_lines = apply_filters('dbvc_bricks_governance_overlay', [], [
            'role_mode' => $role_mode,
            'read_only' => $is_read_only,
            'page' => self::MENU_SLUG,
        ]);
        if (is_array($overlay_lines) && ! empty($overlay_lines)) {
            echo '<section id="dbvc-bricks-panel-governance-overlay" class="dbvc-bricks-panel-extra">';
            echo '<h2>' . esc_html__('Governance Overlay', 'dbvc') . '</h2>';
            echo '<ul>';
            foreach ($overlay_lines as $line) {
                echo '<li>' . esc_html((string) $line) . '</li>';
            }
            echo '</ul>';
            echo '</section>';
        }

        do_action('dbvc_bricks_render_extra_panels', $role_mode, $current_tab, self::MENU_SLUG);

        echo '</div>';

        $status_config = [
            'statusEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/status')),
            'packagesEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/packages')),
            'pullLatestEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/packages/pull-latest')),
            'bootstrapPackageEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/packages/bootstrap-create')),
            'publishRemoteEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/packages/publish-remote')),
            'testRemoteConnectionEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/packages/test-remote-connection')),
            'connectedSitesEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/connected-sites')),
            'driftScanEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/drift-scan')),
            'applyEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/apply')),
            'restoreEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/restore-points')),
            'proposalsEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/proposals')),
            'diagnosticsEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/diagnostics')),
            'uiEventEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/ui-event')),
            'nonce' => wp_create_nonce('wp_rest'),
            'siteUid' => self::get_setting('dbvc_bricks_site_uid', ''),
            'clientForceChannel' => self::get_enum_setting('dbvc_bricks_client_force_channel', ['none', 'canary', 'beta', 'stable'], 'none'),
            'forceStableConfirm' => self::get_bool_setting('dbvc_bricks_force_stable_confirm', true),
            'messages' => [
                'statusLoaded' => esc_html__('Status loaded.', 'dbvc'),
                'packagesLoaded' => esc_html__('Packages loaded.', 'dbvc'),
                'packageBootstrapped' => esc_html__('Package created from current site.', 'dbvc'),
                'scanComplete' => esc_html__('Drift scan complete.', 'dbvc'),
                'applyComplete' => esc_html__('Apply request completed.', 'dbvc'),
                'restoreCreated' => esc_html__('Restore point created.', 'dbvc'),
                'rollbackComplete' => esc_html__('Rollback completed.', 'dbvc'),
                'proposalsLoaded' => esc_html__('Proposals loaded.', 'dbvc'),
                'proposalSubmitted' => esc_html__('Proposal submitted.', 'dbvc'),
                'proposalTransitioned' => esc_html__('Proposal status updated.', 'dbvc'),
                'diagnosticsLoaded' => esc_html__('Diagnostics loaded.', 'dbvc'),
                'packagePublished' => esc_html__('Package published.', 'dbvc'),
                'connectionTestPassed' => esc_html__('Mothership connection succeeded.', 'dbvc'),
                'packagePromoted' => esc_html__('Package promoted.', 'dbvc'),
                'packageRevoked' => esc_html__('Package revoked.', 'dbvc'),
                'connectedSitesLoaded' => esc_html__('Connected sites loaded.', 'dbvc'),
                'connectedSitesSaved' => esc_html__('Connected sites allowlist saved.', 'dbvc'),
            ],
        ];
        echo '<script>';
        echo 'window.DBVC_BRICKS_ADMIN = ' . wp_json_encode($status_config) . ';';
        echo '(function(){';
        echo 'const cfg = window.DBVC_BRICKS_ADMIN || {};';
        echo 'const statusEl = document.getElementById("dbvc-bricks-status-json");';
        echo 'const lastUpdatedEl = document.getElementById("dbvc-bricks-last-updated");';
        echo 'const refreshBtn = document.getElementById("dbvc-bricks-refresh-status");';
        echo 'const loadingNotice = document.getElementById("dbvc-bricks-loading");';
        echo 'const successNotice = document.getElementById("dbvc-bricks-notice-success");';
        echo 'const errorNotice = document.getElementById("dbvc-bricks-notice-error");';
        echo 'const retryLastActionBtn = document.getElementById("dbvc-bricks-retry-last-action");';
        echo 'const refreshDiagnosticsBtn = document.getElementById("dbvc-bricks-refresh-diagnostics");';
        echo 'const diagnosticsEl = document.getElementById("dbvc-bricks-diagnostics-json");';
        echo 'function setLoading(active){ if(!loadingNotice){ return; } loadingNotice.style.display = active ? "block" : "none"; }';
        echo 'function genCorrelationId(){ return "dbvc-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10); }';
        echo 'function emitUiEvent(eventType, payload){ if(!cfg.uiEventEndpoint){ return Promise.resolve(); } const body = {event_type: eventType, payload: payload || {}}; return fetch(cfg.uiEventEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "Content-Type":"application/json"}, body: JSON.stringify(body)}).catch(function(){ return null; }); }';
        echo 'function setSuccess(message, eventType, payload){ if(!successNotice){ return; } const p = successNotice.querySelector("p"); if(p){ p.textContent = message || ""; } successNotice.style.display = message ? "block" : "none"; if(message && eventType){ emitUiEvent(eventType, Object.assign({message: message}, (payload && typeof payload === "object") ? payload : {})); } }';
        echo 'function setError(message, retryAction, context){ if(!errorNotice){ return; } const p = errorNotice.querySelector("p"); if(p){ p.textContent = message || ""; } errorNotice.style.display = message ? "block" : "none"; if(retryLastActionBtn){ retryLastActionBtn.style.display = (message && typeof retryAction === "function") ? "inline-block" : "none"; retryLastActionBtn.onclick = typeof retryAction === "function" ? function(){ retryAction(); } : null; } if(message){ emitUiEvent("ui_error", {message: message, context: context || ""}); } }';
        echo 'function stamp(){ if(lastUpdatedEl){ lastUpdatedEl.textContent = new Date().toLocaleString(); } }';
        echo 'const packagesSelect = document.getElementById("dbvc-bricks-package-select");';
        echo 'const refreshPackagesBtn = document.getElementById("dbvc-bricks-refresh-packages-inline");';
        echo 'const runScanBtn = document.getElementById("dbvc-bricks-run-drift-scan");';
        echo 'const exportReviewBtn = document.getElementById("dbvc-bricks-export-review");';
        echo 'const filterClassEl = document.getElementById("dbvc-bricks-filter-class");';
        echo 'const filterStatusEl = document.getElementById("dbvc-bricks-filter-status");';
        echo 'const filterSearchEl = document.getElementById("dbvc-bricks-filter-search");';
        echo 'const tableBody = document.getElementById("dbvc-bricks-diff-table-body");';
        echo 'const detailEl = document.getElementById("dbvc-bricks-diff-detail");';
        echo 'const countCleanEl = document.getElementById("dbvc-bricks-count-clean");';
        echo 'const countDivergedEl = document.getElementById("dbvc-bricks-count-diverged");';
        echo 'const countOverriddenEl = document.getElementById("dbvc-bricks-count-overridden");';
        echo 'const countPendingEl = document.getElementById("dbvc-bricks-count-pending");';
        echo 'const applyDryRunToggle = document.getElementById("dbvc-bricks-apply-dry-run");';
        echo 'const applyAllowDestructiveToggle = document.getElementById("dbvc-bricks-apply-allow-destructive");';
        echo 'const bulkModeToggle = document.getElementById("dbvc-bricks-bulk-mode");';
        echo 'const bulkChunkSizeInput = document.getElementById("dbvc-bricks-bulk-chunk-size");';
        echo 'const dryRunApplyBtn = document.getElementById("dbvc-bricks-dry-run-apply");';
        echo 'const applySelectedBtn = document.getElementById("dbvc-bricks-apply-selected");';
        echo 'const createRestoreBtn = document.getElementById("dbvc-bricks-create-restore-point");';
        echo 'const rollbackIdInput = document.getElementById("dbvc-bricks-rollback-restore-id");';
        echo 'const runRollbackBtn = document.getElementById("dbvc-bricks-run-rollback");';
        echo 'const applyOutput = document.getElementById("dbvc-bricks-apply-output");';
        echo 'const proposalStatusFilter = document.getElementById("dbvc-bricks-proposal-status-filter");';
        echo 'const refreshProposalsBtn = document.getElementById("dbvc-bricks-refresh-proposals");';
        echo 'const submitProposalBtn = document.getElementById("dbvc-bricks-submit-proposal");';
        echo 'const proposalApproveBtn = document.getElementById("dbvc-bricks-proposal-approve");';
        echo 'const proposalRejectBtn = document.getElementById("dbvc-bricks-proposal-reject");';
        echo 'const proposalNeedsChangesBtn = document.getElementById("dbvc-bricks-proposal-needs-changes");';
        echo 'const proposalReviewNotes = document.getElementById("dbvc-bricks-proposal-review-notes");';
        echo 'const proposalsTableBody = document.getElementById("dbvc-bricks-proposals-table-body");';
        echo 'const proposalDetail = document.getElementById("dbvc-bricks-proposal-detail");';
        echo 'const refreshPackagesPanelBtn = document.getElementById("dbvc-bricks-refresh-packages");';
        echo 'const bootstrapPackageBtn = document.getElementById("dbvc-bricks-bootstrap-package");';
        echo 'const packagesChannelFilter = document.getElementById("dbvc-bricks-packages-channel-filter");';
        echo 'const packagesTableBody = document.getElementById("dbvc-bricks-packages-table-body");';
        echo 'const packageDetail = document.getElementById("dbvc-bricks-package-detail");';
        echo 'const publishRemoteBtn = document.getElementById("dbvc-bricks-publish-remote");';
        echo 'const targetModeSelect = document.getElementById("dbvc-bricks-target-mode");';
        echo 'const refreshConnectedSitesBtn = document.getElementById("dbvc-bricks-refresh-connected-sites");';
        echo 'const saveConnectedSitesBtn = document.getElementById("dbvc-bricks-save-connected-sites");';
        echo 'const connectedSitesSearch = document.getElementById("dbvc-bricks-connected-sites-search");';
        echo 'const connectedSitesStatusFilter = document.getElementById("dbvc-bricks-connected-sites-status-filter");';
        echo 'const connectedSitesSort = document.getElementById("dbvc-bricks-connected-sites-sort");';
        echo 'const selectVisibleSitesBtn = document.getElementById("dbvc-bricks-select-visible-sites");';
        echo 'const clearVisibleSitesBtn = document.getElementById("dbvc-bricks-clear-visible-sites");';
        echo 'const connectedSitesBody = document.getElementById("dbvc-bricks-connected-sites-body");';
        echo 'const publishRemotePreflightBtn = document.getElementById("dbvc-bricks-publish-remote-preflight");';
        echo 'const testRemoteConnectionBtn = document.getElementById("dbvc-bricks-test-remote-connection");';
        echo 'const publishRemotePackagesBtn = document.getElementById("dbvc-bricks-publish-remote-packages");';
        echo 'const forceStableConfirmToggle = document.getElementById("dbvc-bricks-force-stable-confirm");';
        echo 'const pullLatestDryRunBtn = document.getElementById("dbvc-bricks-pull-latest-dry-run");';
        echo 'const promoteChannelSelect = document.getElementById("dbvc-bricks-promote-channel");';
        echo 'const promotePackageBtn = document.getElementById("dbvc-bricks-promote-package");';
        echo 'const revokePackageBtn = document.getElementById("dbvc-bricks-revoke-package");';
        echo 'const publishLocalTargetedBtn = document.getElementById("dbvc-bricks-publish-local-targeted");';
        echo 'const onboardingRoot = document.getElementById("dbvc-bricks-onboarding");';
        echo 'const onboardingProgress = document.getElementById("dbvc-bricks-onboarding-progress");';
        echo 'const onboardingChecks = Array.prototype.slice.call(document.querySelectorAll(".dbvc-bricks-onboarding-check"));';
        echo 'const onboardingMarkAllBtn = document.getElementById("dbvc-bricks-onboarding-mark-all");';
        echo 'const onboardingResetBtn = document.getElementById("dbvc-bricks-onboarding-reset");';
        echo 'const state = {packages: [], scan: null, selected: null, selectedProposalId: null, selectedPackageId: null, currentManifest: null, connectedSites: [], connectedSitesView: []};';
        echo 'const roleNode = document.getElementById("dbvc-bricks-admin-panels");';
        echo 'const onboardingRole = roleNode ? (roleNode.getAttribute("data-role") || "client") : "client";';
        echo 'const onboardingStorageKey = "dbvc_bricks_onboarding_v1_" + onboardingRole;';
        echo 'function artifactClass(item){ return (item && item.artifact_type === "bricks_template") ? "Entity" : "Option"; }';
        echo 'function esc(v){ return String(v || "").replace(/[&<>"]/g, function(c){ return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]; }); }';
        echo 'function readOnboardingState(){ try { const raw = window.localStorage.getItem(onboardingStorageKey); const parsed = raw ? JSON.parse(raw) : {}; return parsed && typeof parsed === "object" ? parsed : {}; } catch (e) { return {}; } }';
        echo 'function writeOnboardingState(next){ try { window.localStorage.setItem(onboardingStorageKey, JSON.stringify(next || {})); } catch (e) { } }';
        echo 'function renderOnboarding(){ if(!onboardingChecks || onboardingChecks.length === 0){ return; } const saved = readOnboardingState(); let complete = 0; onboardingChecks.forEach(function(input){ const key = input.getAttribute("data-key") || ""; const checked = !!saved[key]; input.checked = checked; if(checked){ complete++; } }); if(onboardingProgress){ onboardingProgress.innerHTML = "<strong>" + complete + "/" + onboardingChecks.length + " complete</strong>"; } if(onboardingRoot){ onboardingRoot.open = complete < onboardingChecks.length; } }';
        echo 'function bindOnboarding(){ if(!onboardingChecks || onboardingChecks.length === 0){ return; } onboardingChecks.forEach(function(input){ input.addEventListener("change", function(){ const next = readOnboardingState(); const key = input.getAttribute("data-key") || ""; next[key] = !!input.checked; writeOnboardingState(next); renderOnboarding(); }); }); if(onboardingMarkAllBtn){ onboardingMarkAllBtn.addEventListener("click", function(){ const next = {}; onboardingChecks.forEach(function(input){ const key = input.getAttribute("data-key") || ""; next[key] = true; }); writeOnboardingState(next); renderOnboarding(); }); } if(onboardingResetBtn){ onboardingResetBtn.addEventListener("click", function(){ writeOnboardingState({}); renderOnboarding(); }); } renderOnboarding(); }';
        echo 'function setCounts(scan){ if(!scan || !scan.counts){ return; } if(countCleanEl){ countCleanEl.textContent = "CLEAN: " + (scan.counts.clean || 0); } if(countDivergedEl){ countDivergedEl.textContent = "DIVERGED: " + (scan.counts.diverged || 0); } if(countOverriddenEl){ countOverriddenEl.textContent = "OVERRIDDEN: " + (scan.counts.overridden || 0); } if(countPendingEl){ countPendingEl.textContent = "PENDING_REVIEW: " + (scan.counts.pending_review || 0); } }';
        echo 'function renderDetail(item){ if(!detailEl){ return; } if(!item){ detailEl.textContent = "Select an artifact to inspect details."; return; } const summary = item.diff_summary || {changes:[],total:0,truncated:false,raw_available:false}; let html = ""; html += "<p><strong>Artifact UID:</strong> " + esc(item.artifact_uid) + "</p>"; html += "<p><strong>Class:</strong> " + esc(artifactClass(item)) + "</p>"; html += "<p><strong>Status:</strong> " + esc(item.status) + "</p>"; html += "<p><strong>Local Hash:</strong> <code>" + esc(item.local_hash) + "</code></p>"; html += "<p><strong>Golden Hash:</strong> <code>" + esc(item.golden_hash) + "</code></p>"; html += "<p><strong>Changes:</strong> " + Number(summary.total || 0) + "</p>"; html += "<ul>"; (summary.changes || []).forEach(function(change){ html += "<li><code>" + esc(change.path) + "</code> (" + esc(change.type) + ")</li>"; }); html += "</ul>"; if(summary.truncated){ html += "<p><em>Diff list truncated. raw_available=" + (summary.raw_available ? "true" : "false") + "</em></p>"; } detailEl.innerHTML = html; }';
        echo 'function selectedArtifact(){ if(!state.scan || !Array.isArray(state.scan.artifacts) || !state.selected){ return null; } return state.scan.artifacts.find(function(item){ return item.artifact_uid === state.selected; }) || null; }';
        echo 'function chunkArray(items, chunkSize){ const size = Math.max(1, Number(chunkSize || 1)); const out = []; for(let i=0;i<items.length;i+=size){ out.push(items.slice(i, i + size)); } return out; }';
        echo 'function filteredArtifacts(){ if(!state.scan || !Array.isArray(state.scan.artifacts)){ return []; } const classVal = filterClassEl ? filterClassEl.value : "all"; const statusVal = filterStatusEl ? filterStatusEl.value : "all"; const query = filterSearchEl ? filterSearchEl.value.toLowerCase().trim() : ""; return state.scan.artifacts.filter(function(item){ if(classVal !== "all" && artifactClass(item) !== classVal){ return false; } if(statusVal !== "all" && String(item.status) !== statusVal){ return false; } if(query !== "" && String(item.artifact_uid || "").toLowerCase().indexOf(query) === -1){ return false; } return true; }); }';
        echo 'function renderTable(){ if(!tableBody){ return; } const items = filteredArtifacts(); if(items.length === 0){ tableBody.innerHTML = "<tr><td colspan=\\"3\\">No artifacts match current filters.</td></tr>"; if(!state.selected){ renderDetail(null); } return; } tableBody.innerHTML = items.map(function(item){ const selectedClass = state.selected === item.artifact_uid ? " style=\\"background:#f0f6fc;\\"" : ""; return "<tr data-uid=\\"" + esc(item.artifact_uid) + "\\"" + selectedClass + "><td><code>" + esc(item.artifact_uid) + "</code></td><td>" + esc(artifactClass(item)) + "</td><td>" + esc(item.status) + "</td></tr>"; }).join(""); Array.prototype.forEach.call(tableBody.querySelectorAll("tr[data-uid]"), function(row){ row.addEventListener("click", function(){ state.selected = row.getAttribute("data-uid"); const found = items.find(function(entry){ return entry.artifact_uid === state.selected; }); renderDetail(found || null); if(detailEl && typeof detailEl.focus === "function"){ detailEl.focus(); } renderTable(); }); }); if(!state.selected && items[0]){ state.selected = items[0].artifact_uid; renderDetail(items[0]); if(detailEl && typeof detailEl.focus === "function"){ detailEl.focus(); } } }';
        echo 'function loadStatus(){ if(!cfg.statusEndpoint || !statusEl){ return; } setLoading(true); setError(""); fetch(cfg.statusEndpoint, {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Status request failed (" + r.status + ")"); } return r.json(); }).then(function(data){ statusEl.textContent = JSON.stringify(data, null, 2); stamp(); setSuccess((cfg.messages && cfg.messages.statusLoaded) || "Status loaded.", "status_loaded"); }).catch(function(err){ setError(err.message || "Failed to load status.", loadStatus, "status"); }).finally(function(){ setLoading(false); }); }';
        echo 'function loadDiagnostics(){ if(!cfg.diagnosticsEndpoint || !diagnosticsEl){ return; } setLoading(true); setError(""); fetch(cfg.diagnosticsEndpoint, {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Diagnostics request failed (" + r.status + ")"); } return r.json(); }).then(function(data){ diagnosticsEl.textContent = JSON.stringify(data, null, 2); setSuccess((cfg.messages && cfg.messages.diagnosticsLoaded) || "Diagnostics loaded.", "diagnostics_loaded"); }).catch(function(err){ setError(err.message || "Failed to load diagnostics.", loadDiagnostics, "diagnostics"); }).finally(function(){ setLoading(false); }); }';
        echo 'function loadPackages(){ if(!cfg.packagesEndpoint || !packagesSelect){ return Promise.resolve(); } setLoading(true); setError(""); const role = roleNode ? String(roleNode.getAttribute("data-role") || "") : ""; const params = []; if(packagesChannelFilter && packagesChannelFilter.value){ params.push("channel=" + encodeURIComponent(packagesChannelFilter.value)); } const siteUid = cfg.siteUid ? String(cfg.siteUid) : ""; if(role === "client" && siteUid){ params.push("site_uid=" + encodeURIComponent(siteUid)); } const suffix = params.length ? ("?" + params.join("&")) : ""; return fetch(cfg.packagesEndpoint + suffix, {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Packages request failed (" + r.status + ")"); } return r.json(); }).then(function(data){ state.packages = Array.isArray(data.items) ? data.items : []; if(state.packages.length === 0){ state.selectedPackageId = null; packagesSelect.innerHTML = "<option value=\\"\\">No packages available</option>"; if(packagesTableBody){ packagesTableBody.innerHTML = "<tr><td colspan=\\"5\\">No packages available.</td></tr>"; } if(packageDetail){ packageDetail.textContent = ""; } setSuccess((cfg.messages && cfg.messages.packagesLoaded) || "Packages loaded.", "packages_loaded"); return; } packagesSelect.innerHTML = state.packages.map(function(pkg){ const id = pkg.package_id || pkg.id || ""; const label = (pkg.version || id || "package") + (pkg.channel ? " (" + pkg.channel + ")" : ""); return "<option value=\\"" + esc(id) + "\\">" + esc(label) + "</option>"; }).join(""); const packageIds = state.packages.map(function(pkg){ return String(pkg.package_id || pkg.id || ""); }); let selectedId = state.selectedPackageId && packageIds.indexOf(state.selectedPackageId) !== -1 ? state.selectedPackageId : null; if(!selectedId && packagesSelect.value && packageIds.indexOf(String(packagesSelect.value)) !== -1){ selectedId = String(packagesSelect.value); } if(!selectedId){ selectedId = packageIds[0] || ""; } state.selectedPackageId = selectedId || null; if(packagesSelect && selectedId){ packagesSelect.value = selectedId; } if(packagesTableBody){ packagesTableBody.innerHTML = state.packages.map(function(pkg){ const id = pkg.package_id || pkg.id || ""; const version = pkg.version || ""; const channelName = pkg.channel || ""; const targeting = pkg.targeting && typeof pkg.targeting === "object" ? pkg.targeting : {mode:"all",site_uids:[]}; const audience = String(targeting.mode || "all") === "selected" ? ("selected (" + (Array.isArray(targeting.site_uids) ? targeting.site_uids.length : 0) + ")") : "all"; const visibility = pkg.visibility_reason ? (" / " + String(pkg.visibility_reason)) : ""; const audienceText = audience + visibility; const isSelected = selectedId && id === selectedId; const checked = isSelected ? " checked=\\"checked\\"" : ""; const rowStyle = isSelected ? " style=\\"background:#f0f6fc;\\"" : ""; return "<tr data-package-id=\\"" + esc(id) + "\\"" + rowStyle + "><td><input type=\\"radio\\" name=\\"dbvc-bricks-package-select-row\\" data-package-id=\\"" + esc(id) + "\\"" + checked + " /></td><td><code>" + esc(id) + "</code></td><td>" + esc(version) + "</td><td>" + esc(channelName) + "</td><td>" + esc(audienceText) + "</td></tr>"; }).join(""); const setPackageSelectionUI = function(id){ Array.prototype.forEach.call(packagesTableBody.querySelectorAll("tr[data-package-id]"), function(row){ const rowId = String(row.getAttribute("data-package-id") || ""); row.style.background = rowId === id ? "#f0f6fc" : ""; }); Array.prototype.forEach.call(packagesTableBody.querySelectorAll("input[name=\\"dbvc-bricks-package-select-row\\"]"), function(input){ const inputId = String(input.getAttribute("data-package-id") || ""); input.checked = inputId === id; }); }; const selectPackage = function(id){ if(!id){ return; } state.selectedPackageId = id; state.currentManifest = null; if(packagesSelect){ packagesSelect.value = id; } setPackageSelectionUI(id); loadPackageDetail(id); }; Array.prototype.forEach.call(packagesTableBody.querySelectorAll("tr[data-package-id]"), function(row){ row.addEventListener("click", function(){ const id = String(row.getAttribute("data-package-id") || ""); selectPackage(id); }); }); Array.prototype.forEach.call(packagesTableBody.querySelectorAll("input[name=\\"dbvc-bricks-package-select-row\\"]"), function(input){ input.addEventListener("change", function(ev){ if(ev && ev.stopPropagation){ ev.stopPropagation(); } const id = String(input.getAttribute("data-package-id") || ""); selectPackage(id); }); input.addEventListener("click", function(ev){ if(ev && ev.stopPropagation){ ev.stopPropagation(); } }); }); } if(selectedId){ return loadPackageDetail(selectedId); } setSuccess((cfg.messages && cfg.messages.packagesLoaded) || "Packages loaded.", "packages_loaded"); }).then(function(){ setSuccess((cfg.messages && cfg.messages.packagesLoaded) || "Packages loaded.", "packages_loaded"); }).catch(function(err){ setError(err.message || "Failed to load packages.", loadPackages, "packages"); }).finally(function(){ setLoading(false); }); }';
        echo 'function loadPackageDetail(packageId){ if(!cfg.packagesEndpoint || !packageId){ return Promise.resolve(); } setLoading(true); return fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(packageId), {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Package detail failed (" + r.status + ")"); } return r.json(); }).then(function(detail){ state.currentManifest = detail && detail.manifest ? detail.manifest : null; if(packageDetail){ packageDetail.textContent = JSON.stringify(detail, null, 2); if(typeof packageDetail.focus === "function"){ packageDetail.focus(); } } }).catch(function(err){ setError(err.message || "Failed to load package detail.", function(){ loadPackageDetail(packageId); }, "package_detail"); }).finally(function(){ setLoading(false); }); }';
        echo 'function selectedPackageRecord(){ if(!state.selectedPackageId || !Array.isArray(state.packages)){ return null; } return state.packages.find(function(pkg){ const id = String(pkg && (pkg.package_id || pkg.id) || ""); return id === String(state.selectedPackageId); }) || null; }';
        echo 'function createBootstrapPackage(){ if(!cfg.bootstrapPackageEndpoint){ return; } const selectedChannel = packagesChannelFilter && packagesChannelFilter.value ? String(packagesChannelFilter.value) : "stable"; setLoading(true); fetch(cfg.bootstrapPackageEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Idempotency-Key": "pkg-bootstrap-" + Date.now(), "Content-Type":"application/json"}, body: JSON.stringify({channel: selectedChannel})}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Bootstrap package create failed."); } return data; }); }).then(function(data){ if(packageDetail){ packageDetail.textContent = JSON.stringify(data, null, 2); } setSuccess((cfg.messages && cfg.messages.packageBootstrapped) || "Package created from current site.", "package_bootstrapped"); return loadPackages().then(function(){ if(packagesSelect && data && data.package_id){ packagesSelect.value = data.package_id; state.selectedPackageId = data.package_id; return loadPackageDetail(data.package_id); } return null; }); }).catch(function(err){ setError(err.message || "Failed to create package from current site.", createBootstrapPackage, "package_bootstrap"); }).finally(function(){ setLoading(false); }); }';
        echo 'function selectedConnectedSiteUids(){ if(!connectedSitesBody){ return []; } const out = []; Array.prototype.forEach.call(connectedSitesBody.querySelectorAll("input[data-site-uid]"), function(input){ if(input.checked){ out.push(String(input.getAttribute("data-site-uid") || "")); } }); return out.filter(function(v){ return !!v; }); }';
        echo 'function getConnectedSiteSortValue(site, key){ if(key === "last_seen"){ return String(site && site.last_seen_at || ""); } if(key === "site_label"){ return String(site && site.site_label || "").toLowerCase(); } return String(site && site.site_uid || "").toLowerCase(); }';
        echo 'function filterSortConnectedSites(items){ const query = connectedSitesSearch ? String(connectedSitesSearch.value || "").toLowerCase().trim() : ""; const status = connectedSitesStatusFilter ? String(connectedSitesStatusFilter.value || "") : ""; const sortVal = connectedSitesSort ? String(connectedSitesSort.value || "site_uid_asc") : "site_uid_asc"; const parts = sortVal.split("_"); const key = parts.length > 1 ? parts.slice(0, -1).join("_") : "site_uid"; const dir = parts.length > 1 ? parts[parts.length - 1] : "asc"; let out = Array.isArray(items) ? items.slice() : []; if(status){ out = out.filter(function(site){ return String(site && site.status || "") === status; }); } if(query){ out = out.filter(function(site){ const uid = String(site && site.site_uid || "").toLowerCase(); const label = String(site && site.site_label || "").toLowerCase(); const onboarding = String(site && site.onboarding_state || "").toLowerCase(); return uid.indexOf(query) !== -1 || label.indexOf(query) !== -1 || onboarding.indexOf(query) !== -1; }); } out.sort(function(a, b){ const aVal = getConnectedSiteSortValue(a, key); const bVal = getConnectedSiteSortValue(b, key); if(aVal === bVal){ return 0; } if(dir === "desc"){ return aVal < bVal ? 1 : -1; } return aVal > bVal ? 1 : -1; }); return out; }';
        echo 'function renderConnectedSites(items){ if(!connectedSitesBody){ return; } const rows = filterSortConnectedSites(items); state.connectedSitesView = rows; if(!Array.isArray(rows) || rows.length === 0){ connectedSitesBody.innerHTML = "<tr><td colspan=\\"6\\">No connected sites match current filters.</td></tr>"; return; } connectedSitesBody.innerHTML = rows.map(function(site){ const uid = site.site_uid || ""; const label = site.site_label || uid; const status = site.status || ""; const onboarding = site.onboarding_state || ""; const onboardingBadge = onboarding ? ("<code>" + esc(onboarding) + "</code>") : ""; const lastSeen = site.last_seen_at || ""; const checked = site.allow_receive_packages ? " checked=\\"checked\\"" : ""; return "<tr><td><input type=\\"checkbox\\" data-site-uid=\\"" + esc(uid) + "\\"" + checked + " /></td><td><code>" + esc(uid) + "</code></td><td>" + esc(label) + "</td><td>" + esc(status) + "</td><td>" + onboardingBadge + "</td><td>" + esc(lastSeen) + "</td></tr>"; }).join(""); }';
        echo 'function loadConnectedSites(){ if(!cfg.connectedSitesEndpoint){ return Promise.resolve(); } setLoading(true); return fetch(cfg.connectedSitesEndpoint, {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Connected sites request failed (" + r.status + ")"); } return r.json(); }).then(function(data){ state.connectedSites = Array.isArray(data.items) ? data.items : []; renderConnectedSites(state.connectedSites); setSuccess((cfg.messages && cfg.messages.connectedSitesLoaded) || "Connected sites loaded.", "connected_sites_loaded"); }).catch(function(err){ setError(err.message || "Failed to load connected sites.", loadConnectedSites, "connected_sites"); }).finally(function(){ setLoading(false); }); }';
        echo 'function saveConnectedSites(){ if(!cfg.connectedSitesEndpoint || !connectedSitesBody){ return; } const rows = Array.prototype.slice.call(connectedSitesBody.querySelectorAll("input[data-site-uid]")); if(rows.length === 0){ return; } setLoading(true); let chain = Promise.resolve(); rows.forEach(function(input){ chain = chain.then(function(){ const uid = String(input.getAttribute("data-site-uid") || ""); return fetch(cfg.connectedSitesEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({site_uid: uid, allow_receive_packages: !!input.checked})}).then(function(r){ if(!r.ok){ throw new Error("Connected site save failed (" + r.status + ")"); } return r.json(); }); }); }); chain.then(function(){ setSuccess((cfg.messages && cfg.messages.connectedSitesSaved) || "Connected sites allowlist saved.", "connected_sites_saved"); loadConnectedSites(); }).catch(function(err){ setError(err.message || "Failed to save connected sites.", saveConnectedSites, "connected_sites_save"); }).finally(function(){ setLoading(false); }); }';
        echo 'function setVisibleSitesChecked(checked){ if(!connectedSitesBody){ return; } Array.prototype.forEach.call(connectedSitesBody.querySelectorAll("input[data-site-uid]"), function(input){ input.checked = !!checked; }); }';
        echo 'function runPublishPreflight(){ if(!cfg.publishRemoteEndpoint || !cfg.packagesEndpoint){ return; } const packageId = state.selectedPackageId || (packagesSelect ? packagesSelect.value : ""); if(!packageId){ setError("Select a package first.", runPublishPreflight, "publish_preflight"); return; } const mode = targetModeSelect ? String(targetModeSelect.value || "all") : "all"; const siteUids = mode === "selected" ? selectedConnectedSiteUids() : []; if(mode === "selected" && siteUids.length === 0){ setError("Select at least one connected site for selected mode.", runPublishPreflight, "publish_preflight"); return; } setLoading(true); fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(packageId), {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Package detail failed (" + r.status + ")"); } return r.json(); }).then(function(detail){ const manifest = detail && detail.manifest ? detail.manifest : null; if(!manifest){ throw new Error("Selected package manifest missing."); } return fetch(cfg.publishRemoteEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Idempotency-Key": "pkg-preflight-" + Date.now(), "Content-Type":"application/json"}, body: JSON.stringify({package: manifest, targeting: {mode: mode, site_uids: siteUids}, dry_run: true})}); }).then(function(r){ return r.json().then(function(data){ if(!r.ok){ const msg = data && data.message ? data.message : "Preflight failed."; const details = data && data.data ? data.data : null; const err = new Error(msg); err.details = details; throw err; } return data; }); }).then(function(data){ if(packageDetail){ packageDetail.textContent = JSON.stringify(data, null, 2); } setSuccess("Publish preflight passed.", "package_publish_preflight"); }).catch(function(err){ if(packageDetail){ packageDetail.textContent = JSON.stringify({ok:false, error: err && err.message ? err.message : "Publish preflight failed.", details: err && err.details ? err.details : null}, null, 2); } setError(err.message || "Publish preflight failed.", runPublishPreflight, "publish_preflight"); }).finally(function(){ setLoading(false); }); }';
        echo 'function runRemoteConnectionTest(){ if(!cfg.testRemoteConnectionEndpoint){ return; } setLoading(true); fetch(cfg.testRemoteConnectionEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({})}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ const msg = data && data.message ? data.message : "Connection test failed."; const details = data && data.data ? data.data : null; const err = new Error(msg); err.details = details; throw err; } return data; }); }).then(function(data){ if(packageDetail){ packageDetail.textContent = JSON.stringify(data, null, 2); } setSuccess((cfg.messages && cfg.messages.connectionTestPassed) || "Mothership connection succeeded.", "connection_test_passed"); }).catch(function(err){ if(packageDetail){ packageDetail.textContent = JSON.stringify({ok:false, error: err && err.message ? err.message : "Mothership connection failed.", details: err && err.details ? err.details : null}, null, 2); } setError(err.message || "Mothership connection failed.", runRemoteConnectionTest, "connection_test"); }).finally(function(){ setLoading(false); }); }';
        echo 'function publishLocalTargeted(){ if(!cfg.packagesEndpoint){ return; } const packageId = state.selectedPackageId || (packagesSelect ? packagesSelect.value : ""); if(!packageId){ setError("Select a package first.", publishLocalTargeted, "publish_local_targeted"); return; } const mode = targetModeSelect ? String(targetModeSelect.value || "all") : "all"; const siteUids = mode === "selected" ? selectedConnectedSiteUids() : []; if(mode === "selected" && siteUids.length === 0){ setError("Select at least one connected site for selected mode.", publishLocalTargeted, "publish_local_targeted"); return; } setLoading(true); fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(packageId), {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Package detail failed (" + r.status + ")"); } return r.json(); }).then(function(detail){ const manifest = detail && detail.manifest ? detail.manifest : null; if(!manifest){ throw new Error("Selected package manifest missing."); } return fetch(cfg.packagesEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Idempotency-Key": "pkg-local-" + Date.now(), "Content-Type":"application/json"}, body: JSON.stringify({package: manifest, targeting: {mode: mode, site_uids: siteUids}})}); }).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Publish failed."); } return data; }); }).then(function(data){ if(packageDetail){ packageDetail.textContent = JSON.stringify(data, null, 2); } setSuccess((cfg.messages && cfg.messages.packagePublished) || "Package published.", "package_published"); loadPackages(); }).catch(function(err){ setError(err.message || "Failed to publish package.", publishLocalTargeted, "publish_local_targeted"); }).finally(function(){ setLoading(false); }); }';
        echo 'function publishRemotePackage(){ if(!cfg.publishRemoteEndpoint){ return; } const packageId = packagesSelect ? packagesSelect.value : ""; if(!packageId){ setError("Select a package first.", publishRemotePackage, "publish_remote"); return; } const mode = targetModeSelect ? String(targetModeSelect.value || "all") : "all"; const siteUids = mode === "selected" ? selectedConnectedSiteUids() : []; if(mode === "selected" && siteUids.length === 0){ setError("Select at least one connected site for selected mode.", publishRemotePackage, "publish_remote"); return; } const forceChannel = cfg.clientForceChannel ? String(cfg.clientForceChannel) : "none"; const requireStableConfirm = !!cfg.forceStableConfirm; const confirmStable = !!(forceStableConfirmToggle && forceStableConfirmToggle.checked); if(forceChannel === "stable" && requireStableConfirm && !confirmStable){ setError("Confirm forced stable publish before sending package.", publishRemotePackage, "publish_remote"); return; } setLoading(true); fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(packageId), {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Package detail failed (" + r.status + ")"); } return r.json(); }).then(function(detail){ const manifest = detail && detail.manifest ? detail.manifest : null; if(!manifest){ throw new Error("Selected package manifest missing."); } return fetch(cfg.publishRemoteEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Idempotency-Key": "pkg-remote-" + Date.now(), "Content-Type":"application/json"}, body: JSON.stringify({package: manifest, targeting: {mode: mode, site_uids: siteUids}, confirm_force_stable: confirmStable})}); }).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Remote publish failed."); } return data; }); }).then(function(data){ if(packageDetail){ packageDetail.textContent = JSON.stringify(data, null, 2); } const receiptId = data && data.response && data.response.receipt_id ? String(data.response.receipt_id) : ""; setSuccess((cfg.messages && cfg.messages.packagePublished) || "Package published.", "package_published_remote", {package_id: packageId, receipt_id: receiptId}); }).catch(function(err){ setError(err.message || "Failed to publish package to mothership.", publishRemotePackage, "publish_remote"); }).finally(function(){ setLoading(false); }); }';
        echo 'function pullLatestAllowedDryRun(){ if(!cfg.pullLatestEndpoint){ return; } const selectedChannel = packagesChannelFilter && packagesChannelFilter.value ? String(packagesChannelFilter.value) : ""; setLoading(true); fetch(cfg.pullLatestEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({channel: selectedChannel})}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ const msg = data && data.message ? data.message : "Pull latest dry-run failed."; throw new Error(msg); } return data; }); }).then(function(data){ if(data && data.package_id){ state.selectedPackageId = String(data.package_id); if(packagesSelect){ packagesSelect.value = String(data.package_id); } } if(data && data.manifest){ state.currentManifest = data.manifest; } if(packageDetail){ packageDetail.textContent = JSON.stringify(data, null, 2); } if(applyOutput && data && data.dry_run_apply){ applyOutput.textContent = JSON.stringify(data.dry_run_apply, null, 2); } setSuccess("Pulled latest allowed package and completed dry-run apply.", "package_pull_dry_run"); return loadPackages(); }).catch(function(err){ setError(err.message || "Failed to pull latest allowed package.", pullLatestAllowedDryRun, "package_pull_dry_run"); }).finally(function(){ setLoading(false); }); }';
        echo 'function promoteSelectedPackage(){ if(!cfg.packagesEndpoint){ return; } const packageId = state.selectedPackageId || (packagesSelect ? String(packagesSelect.value || "") : ""); if(!packageId){ setError("Select a package first.", promoteSelectedPackage, "package_promote"); return; } const record = selectedPackageRecord(); const currentChannel = record && record.channel ? String(record.channel) : ""; const targetChannel = promoteChannelSelect ? String(promoteChannelSelect.value || "beta") : "beta"; const order = {canary:1,beta:2,stable:3}; if(order[currentChannel] && order[targetChannel] && order[targetChannel] <= order[currentChannel]){ setError("Promotion must move forward (canary -> beta -> stable).", promoteSelectedPackage, "package_promote"); return; } if(targetChannel === "stable"){ const ok = window.confirm("Confirm promotion to stable channel."); if(!ok){ return; } } setLoading(true); fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(packageId) + "/promote", {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Idempotency-Key": "pkg-promote-" + Date.now(), "Content-Type":"application/json"}, body: JSON.stringify({channel: targetChannel, confirm_stable_promotion: targetChannel === "stable"})}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Promote failed."); } return data; }); }).then(function(data){ if(packageDetail){ packageDetail.textContent = JSON.stringify(data, null, 2); } setSuccess((cfg.messages && cfg.messages.packagePromoted) || "Package promoted.", "package_promoted"); return loadPackages(); }).catch(function(err){ setError(err.message || "Package promotion failed.", promoteSelectedPackage, "package_promote"); }).finally(function(){ setLoading(false); }); }';
        echo 'function revokeSelectedPackage(){ if(!cfg.packagesEndpoint){ return; } const packageId = state.selectedPackageId || (packagesSelect ? String(packagesSelect.value || "") : ""); if(!packageId){ setError("Select a package first.", revokeSelectedPackage, "package_revoke"); return; } const ok = window.confirm("Revoke selected package? This stops rollout immediately."); if(!ok){ return; } setLoading(true); fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(packageId) + "/revoke", {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Idempotency-Key": "pkg-revoke-" + Date.now(), "Content-Type":"application/json"}, body: JSON.stringify({confirm_revoke: true})}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Revoke failed."); } return data; }); }).then(function(data){ if(packageDetail){ packageDetail.textContent = JSON.stringify(data, null, 2); } setSuccess((cfg.messages && cfg.messages.packageRevoked) || "Package revoked.", "package_revoked"); return loadPackages(); }).catch(function(err){ setError(err.message || "Package revoke failed.", revokeSelectedPackage, "package_revoke"); }).finally(function(){ setLoading(false); }); }';
        echo 'function runDriftScan(){ if(!cfg.packagesEndpoint || !cfg.driftScanEndpoint || !packagesSelect){ return; } const packageId = packagesSelect.value; if(!packageId){ setError("Select a package first.", runDriftScan, "drift_scan"); return; } setLoading(true); setError(""); state.scan = null; state.selected = null; fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(packageId), {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Package detail failed (" + r.status + ")"); } return r.json(); }).then(function(detail){ const manifest = detail && detail.manifest ? detail.manifest : {}; state.currentManifest = manifest; return fetch(cfg.driftScanEndpoint, {method: "POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type": "application/json"}, body: JSON.stringify({manifest: manifest, options: {max_changes: 50}})}); }).then(function(r){ if(!r.ok){ throw new Error("Drift scan failed (" + r.status + ")"); } return r.json(); }).then(function(scan){ state.scan = scan; setCounts(scan); renderTable(); setSuccess((cfg.messages && cfg.messages.scanComplete) || "Drift scan complete.", "drift_scan_complete"); }).catch(function(err){ setError(err.message || "Failed to run drift scan.", runDriftScan, "drift_scan"); }).finally(function(){ setLoading(false); }); }';
        echo 'function writeApplyOutput(data){ if(applyOutput){ applyOutput.textContent = JSON.stringify(data, null, 2); } }';
        echo 'function exportReview(){ if(!state.scan){ setError("Run a drift scan before exporting review JSON.", exportReview, "export_review"); return; } const payload = {exported_at: new Date().toISOString(), role: document.getElementById("dbvc-bricks-admin-panels") ? document.getElementById("dbvc-bricks-admin-panels").getAttribute("data-role") : "", scan: state.scan, selected_artifact_uid: state.selected, selected_proposal_id: state.selectedProposalId}; const blob = new Blob([JSON.stringify(payload, null, 2)], {type:"application/json"}); const url = URL.createObjectURL(blob); const a = document.createElement("a"); a.href = url; a.download = "dbvc-bricks-review-" + Date.now() + ".json"; a.click(); URL.revokeObjectURL(url); setSuccess("Review export generated.", "review_export"); }';
        echo 'function ensureManifest(){ if(state.currentManifest){ return Promise.resolve(state.currentManifest); } const packageId = packagesSelect ? packagesSelect.value : ""; if(!packageId){ return Promise.reject(new Error("Select a package first.")); } return fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(packageId), {headers: {"X-WP-Nonce": cfg.nonce}}).then(function(r){ if(!r.ok){ throw new Error("Package detail failed (" + r.status + ")"); } return r.json(); }).then(function(detail){ state.currentManifest = detail && detail.manifest ? detail.manifest : {}; return state.currentManifest; }); }';
        echo 'function runApply(dryRun){ if(!cfg.applyEndpoint){ return; } ensureManifest().then(function(manifest){ const selected = selectedArtifact(); const allowDestructive = !!(applyAllowDestructiveToggle && applyAllowDestructiveToggle.checked); const bulkMode = !!(bulkModeToggle && bulkModeToggle.checked); const chunkSize = bulkChunkSizeInput ? Math.max(1, Number(bulkChunkSizeInput.value || 25)) : 25; const receiptId = manifest && manifest.receipt_id ? String(manifest.receipt_id) : ""; if(!dryRun && allowDestructive){ const ok = window.confirm("Confirm destructive operations are allowed for this apply run."); if(!ok){ throw new Error("Apply cancelled by operator."); } } const baseUids = selected ? [selected.artifact_uid] : filteredArtifacts().map(function(item){ return item.artifact_uid; }); const chunked = bulkMode ? chunkArray(baseUids, chunkSize) : [baseUids]; setLoading(true); const runs = []; let chain = Promise.resolve(); chunked.forEach(function(chunk){ chain = chain.then(function(){ const selection = chunk.length > 0 ? {artifact_uids: chunk} : {}; return fetch(cfg.applyEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({manifest: manifest, selection: selection, options: {dry_run: dryRun, allow_destructive: allowDestructive}, receipt_id: receiptId})}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ const msg = data && data.message ? data.message : ("Apply failed (" + r.status + ")"); throw new Error(msg); } return data; }); }).then(function(data){ runs.push({selection: selection, response: data}); }); }); }); return chain.then(function(){ return {bulk_mode: bulkMode, chunk_size: chunkSize, run_count: runs.length, receipt_id: receiptId, runs: runs}; }); }).then(function(data){ writeApplyOutput(data); if(!dryRun && cfg.packagesEndpoint && state.selectedPackageId){ fetch(cfg.packagesEndpoint + "/" + encodeURIComponent(state.selectedPackageId) + "/ack", {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({site_uid: cfg.siteUid || "", state: "applied", receipt_id: data && data.receipt_id ? data.receipt_id : ""})}); } setSuccess((cfg.messages && cfg.messages.applyComplete) || "Apply request completed.", "apply_complete", {receipt_id: data && data.receipt_id ? data.receipt_id : ""}); }).catch(function(err){ setError(err.message || "Apply failed.", function(){ runApply(dryRun); }, "apply"); }).finally(function(){ setLoading(false); }); }';
        echo 'function runCreateRestorePoint(){ if(!cfg.restoreEndpoint){ return; } ensureManifest().then(function(manifest){ const receiptId = manifest && manifest.receipt_id ? String(manifest.receipt_id) : ""; return fetch(cfg.applyEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({manifest: manifest, options: {dry_run: true}, receipt_id: receiptId})}).then(function(r){ if(!r.ok){ throw new Error("Unable to build plan for restore point."); } return r.json(); }).then(function(planRes){ const plan = planRes && planRes.plan ? planRes.plan : null; if(!plan){ throw new Error("Dry-run plan missing."); } return fetch(cfg.restoreEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({plan: plan, receipt_id: receiptId})}); }).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Create restore point failed."); } return data; }); }).then(function(data){ writeApplyOutput(data); if(rollbackIdInput && data.restore_id){ rollbackIdInput.value = data.restore_id; } setSuccess((cfg.messages && cfg.messages.restoreCreated) || "Restore point created.", "restore_created", {receipt_id: data && data.receipt_id ? data.receipt_id : receiptId}); }); }).catch(function(err){ setError(err.message || "Create restore point failed.", runCreateRestorePoint, "restore_create"); }).finally(function(){ setLoading(false); }); }';
        echo 'function runRollback(){ if(!cfg.restoreEndpoint || !rollbackIdInput){ return; } const restoreId = String(rollbackIdInput.value || "").trim(); if(!restoreId){ setError("Enter a restore ID.", runRollback, "rollback"); return; } const receiptId = state.currentManifest && state.currentManifest.receipt_id ? String(state.currentManifest.receipt_id) : ""; setLoading(true); fetch(cfg.restoreEndpoint + "/" + encodeURIComponent(restoreId) + "/rollback", {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({receipt_id: receiptId})}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Rollback failed."); } return data; }); }).then(function(data){ writeApplyOutput(data); setSuccess((cfg.messages && cfg.messages.rollbackComplete) || "Rollback completed.", "rollback_complete", {receipt_id: data && data.receipt_id ? data.receipt_id : receiptId}); }).catch(function(err){ setError(err.message || "Rollback failed.", runRollback, "rollback"); }).finally(function(){ setLoading(false); }); }';
        echo 'function renderProposals(items){ if(!proposalsTableBody){ return; } if(!Array.isArray(items) || items.length === 0){ proposalsTableBody.innerHTML = "<tr><td colspan=\\"3\\">No proposals found.</td></tr>"; if(proposalDetail){ proposalDetail.textContent = ""; } return; } proposalsTableBody.innerHTML = items.map(function(item){ const id = item.proposal_id || ""; return "<tr data-proposal-id=\\"" + esc(id) + "\\"><td><code>" + esc(id) + "</code></td><td><code>" + esc(item.artifact_uid || "") + "</code></td><td>" + esc(item.status || "") + "</td></tr>"; }).join(""); Array.prototype.forEach.call(proposalsTableBody.querySelectorAll("tr[data-proposal-id]"), function(row){ row.addEventListener("click", function(){ state.selectedProposalId = row.getAttribute("data-proposal-id"); const found = items.find(function(it){ return it.proposal_id === state.selectedProposalId; }); if(proposalDetail){ proposalDetail.textContent = JSON.stringify(found || {}, null, 2); if(typeof proposalDetail.focus === "function"){ proposalDetail.focus(); } } }); }); if(!state.selectedProposalId && items[0]){ state.selectedProposalId = items[0].proposal_id || null; if(proposalDetail){ proposalDetail.textContent = JSON.stringify(items[0], null, 2); if(typeof proposalDetail.focus === "function"){ proposalDetail.focus(); } } } }';
        echo 'function loadProposals(){ if(!cfg.proposalsEndpoint){ return; } setLoading(true); const status = proposalStatusFilter && proposalStatusFilter.value ? ("?status=" + encodeURIComponent(proposalStatusFilter.value)) : ""; fetch(cfg.proposalsEndpoint + status, {headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId()}}).then(function(r){ if(!r.ok){ throw new Error("Proposals request failed (" + r.status + ")"); } return r.json(); }).then(function(data){ renderProposals(data.items || []); setSuccess((cfg.messages && cfg.messages.proposalsLoaded) || "Proposals loaded.", "proposals_loaded"); }).catch(function(err){ setError(err.message || "Failed to load proposals.", loadProposals, "proposals"); }).finally(function(){ setLoading(false); }); }';
        echo 'function submitProposal(){ if(!cfg.proposalsEndpoint){ return; } const artifact = selectedArtifact(); if(!artifact){ setError("Select a diff artifact before submitting a proposal.", submitProposal, "proposal_submit"); return; } const notes = proposalReviewNotes ? proposalReviewNotes.value : ""; const payload = {artifact_uid: artifact.artifact_uid, artifact_type: artifact.artifact_type || "", base_hash: artifact.local_hash || "", proposed_hash: artifact.golden_hash || "", notes: notes}; setLoading(true); fetch(cfg.proposalsEndpoint, {method:"POST", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify(payload)}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Submit proposal failed."); } return data; }); }).then(function(){ setSuccess((cfg.messages && cfg.messages.proposalSubmitted) || "Proposal submitted.", "proposal_submitted"); loadProposals(); }).catch(function(err){ setError(err.message || "Submit proposal failed.", submitProposal, "proposal_submit"); }).finally(function(){ setLoading(false); }); }';
        echo 'function transitionProposal(nextStatus){ if(!cfg.proposalsEndpoint || !state.selectedProposalId){ setError("Select a proposal first.", function(){ transitionProposal(nextStatus); }, "proposal_transition"); return; } const notes = proposalReviewNotes ? proposalReviewNotes.value : ""; setLoading(true); fetch(cfg.proposalsEndpoint + "/" + encodeURIComponent(state.selectedProposalId), {method:"PATCH", headers: {"X-WP-Nonce": cfg.nonce, "X-DBVC-Correlation-ID": genCorrelationId(), "Content-Type":"application/json"}, body: JSON.stringify({status: nextStatus, review_notes: notes})}).then(function(r){ return r.json().then(function(data){ if(!r.ok){ throw new Error(data && data.message ? data.message : "Proposal transition failed."); } return data; }); }).then(function(){ setSuccess((cfg.messages && cfg.messages.proposalTransitioned) || "Proposal status updated.", "proposal_transition"); loadProposals(); }).catch(function(err){ setError(err.message || "Proposal transition failed.", function(){ transitionProposal(nextStatus); }, "proposal_transition"); }).finally(function(){ setLoading(false); }); }';
        echo 'if(refreshBtn){ refreshBtn.addEventListener("click", loadStatus); }';
        echo 'if(refreshDiagnosticsBtn){ refreshDiagnosticsBtn.addEventListener("click", loadDiagnostics); }';
        echo 'if(refreshPackagesBtn){ refreshPackagesBtn.addEventListener("click", loadPackages); }';
        echo 'if(runScanBtn){ runScanBtn.addEventListener("click", runDriftScan); }';
        echo 'if(exportReviewBtn){ exportReviewBtn.addEventListener("click", exportReview); }';
        echo 'if(dryRunApplyBtn){ dryRunApplyBtn.addEventListener("click", function(){ runApply(true); }); }';
        echo 'if(applySelectedBtn){ applySelectedBtn.addEventListener("click", function(){ const forceDry = applyDryRunToggle ? applyDryRunToggle.checked : false; runApply(forceDry ? true : false); }); }';
        echo 'if(createRestoreBtn){ createRestoreBtn.addEventListener("click", runCreateRestorePoint); }';
        echo 'if(runRollbackBtn){ runRollbackBtn.addEventListener("click", runRollback); }';
        echo 'if(refreshProposalsBtn){ refreshProposalsBtn.addEventListener("click", loadProposals); }';
        echo 'if(submitProposalBtn){ submitProposalBtn.addEventListener("click", submitProposal); }';
        echo 'if(proposalApproveBtn){ proposalApproveBtn.addEventListener("click", function(){ transitionProposal("APPROVED"); }); }';
        echo 'if(proposalRejectBtn){ proposalRejectBtn.addEventListener("click", function(){ transitionProposal("REJECTED"); }); }';
        echo 'if(proposalNeedsChangesBtn){ proposalNeedsChangesBtn.addEventListener("click", function(){ transitionProposal("NEEDS_CHANGES"); }); }';
        echo 'if(refreshPackagesPanelBtn){ refreshPackagesPanelBtn.addEventListener("click", loadPackages); }';
        echo 'if(bootstrapPackageBtn){ bootstrapPackageBtn.addEventListener("click", createBootstrapPackage); }';
        echo 'if(refreshConnectedSitesBtn){ refreshConnectedSitesBtn.addEventListener("click", loadConnectedSites); }';
        echo 'if(saveConnectedSitesBtn){ saveConnectedSitesBtn.addEventListener("click", saveConnectedSites); }';
        echo 'if(connectedSitesSearch){ connectedSitesSearch.addEventListener("input", function(){ renderConnectedSites(state.connectedSites); }); }';
        echo 'if(connectedSitesStatusFilter){ connectedSitesStatusFilter.addEventListener("change", function(){ renderConnectedSites(state.connectedSites); }); }';
        echo 'if(connectedSitesSort){ connectedSitesSort.addEventListener("change", function(){ renderConnectedSites(state.connectedSites); }); }';
        echo 'if(selectVisibleSitesBtn){ selectVisibleSitesBtn.addEventListener("click", function(){ setVisibleSitesChecked(true); }); }';
        echo 'if(clearVisibleSitesBtn){ clearVisibleSitesBtn.addEventListener("click", function(){ setVisibleSitesChecked(false); }); }';
        echo 'if(publishRemotePreflightBtn){ publishRemotePreflightBtn.addEventListener("click", runPublishPreflight); }';
        echo 'if(testRemoteConnectionBtn){ testRemoteConnectionBtn.addEventListener("click", runRemoteConnectionTest); }';
        echo 'if(publishRemotePackagesBtn){ publishRemotePackagesBtn.addEventListener("click", publishRemotePackage); }';
        echo 'if(pullLatestDryRunBtn){ pullLatestDryRunBtn.addEventListener("click", pullLatestAllowedDryRun); }';
        echo 'if(promotePackageBtn){ promotePackageBtn.addEventListener("click", promoteSelectedPackage); }';
        echo 'if(revokePackageBtn){ revokePackageBtn.addEventListener("click", revokeSelectedPackage); }';
        echo 'if(publishLocalTargetedBtn){ publishLocalTargetedBtn.addEventListener("click", publishLocalTargeted); }';
        echo 'if(publishRemoteBtn){ publishRemoteBtn.addEventListener("click", publishRemotePackage); }';
        echo 'if(packagesChannelFilter){ packagesChannelFilter.addEventListener("change", loadPackages); }';
        echo 'if(packagesSelect){ packagesSelect.addEventListener("change", function(){ const id = String(packagesSelect.value || ""); state.selectedPackageId = id || null; state.currentManifest = null; if(id){ loadPackageDetail(id); } }); }';
        echo 'if(filterClassEl){ filterClassEl.addEventListener("change", renderTable); }';
        echo 'if(filterStatusEl){ filterStatusEl.addEventListener("change", renderTable); }';
        echo 'if(filterSearchEl){ filterSearchEl.addEventListener("input", renderTable); }';
        echo 'bindOnboarding();';
        echo 'loadStatus();';
        echo 'loadDiagnostics();';
        echo 'loadPackages();';
        echo 'loadConnectedSites();';
        echo 'loadProposals();';
        echo '})();';
        echo '</script>';
        echo '</div>';
    }

    /**
     * Get normalized role mode for admin page wiring.
     *
     * @return string
     */
    public static function get_role_mode()
    {
        return self::get_enum_setting('dbvc_bricks_role', ['mothership', 'client'], 'client');
    }

    /**
     * Get tab map keyed by tab id for role mode.
     *
     * @param string $role_mode
     * @return array<string, string>
     */
    public static function get_admin_tabs_for_role($role_mode)
    {
        $tabs = [
            'overview' => 'Overview',
            'differences' => 'Differences',
            'proposals' => 'Proposals',
            'documentation' => 'Documentation',
        ];

        if ($role_mode === 'client') {
            $tabs = [
                'overview' => 'Overview',
                'differences' => 'Differences',
                'packages' => 'Packages',
                'apply_restore' => 'Apply & Restore',
                'proposals' => 'Proposals',
                'documentation' => 'Documentation',
            ];
        } elseif ($role_mode === 'mothership') {
            $tabs['packages'] = 'Packages';
        }

        $tabs = apply_filters('dbvc_bricks_admin_tabs', $tabs, $role_mode);
        if (! is_array($tabs)) {
            return [];
        }

        $normalized = [];
        foreach ($tabs as $tab_key => $label) {
            $key = sanitize_key((string) $tab_key);
            if ($key === '') {
                continue;
            }
            $normalized[$key] = sanitize_text_field((string) $label);
        }

        return $normalized;
    }

    /**
     * Resolve the selected admin tab from query.
     *
     * @param array<string, string> $tabs
     * @return string
     */
    public static function get_current_admin_tab(array $tabs)
    {
        if (empty($tabs)) {
            return '';
        }

        $selected = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($selected !== '' && isset($tabs[$selected])) {
            return $selected;
        }

        return (string) array_key_first($tabs);
    }

    /**
     * Resolve local artifact payloads from package manifest.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, array<string, mixed>>
     */
    public static function resolve_local_artifacts_from_manifest(array $manifest)
    {
        $normalized_manifest = self::normalize_manifest_payload($manifest);
        $artifacts = isset($normalized_manifest['artifacts']) && is_array($normalized_manifest['artifacts']) ? $normalized_manifest['artifacts'] : [];
        $local = [];

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $artifact_uid = isset($artifact['artifact_uid']) ? (string) $artifact['artifact_uid'] : '';
            $artifact_type = isset($artifact['artifact_type']) ? (string) $artifact['artifact_type'] : '';
            if ($artifact_uid === '') {
                continue;
            }

            if (str_starts_with($artifact_uid, 'option:')) {
                $option_key = substr($artifact_uid, 7);
                $local[$artifact_uid] = [
                    'payload' => get_option($option_key, null),
                ];
                continue;
            }

            if ($artifact_type === 'bricks_template') {
                $entity_id = self::extract_entity_id_from_artifact($artifact);
                $local[$artifact_uid] = [
                    'payload' => $entity_id > 0 ? self::read_entity_payload_for_diff($entity_id) : null,
                ];
            }
        }

        return $local;
    }

    /**
     * @param array<string, mixed> $artifact
     * @return int
     */
    public static function extract_entity_id_from_artifact(array $artifact)
    {
        if (isset($artifact['entity_id'])) {
            return absint($artifact['entity_id']);
        }
        if (isset($artifact['payload']) && is_array($artifact['payload']) && isset($artifact['payload']['ID'])) {
            return absint($artifact['payload']['ID']);
        }

        $artifact_uid = isset($artifact['artifact_uid']) ? (string) $artifact['artifact_uid'] : '';
        if (preg_match('/(\d+)$/', $artifact_uid, $matches)) {
            return absint($matches[1]);
        }

        return 0;
    }

    /**
     * @param int $entity_id
     * @return array<string, mixed>|null
     */
    public static function read_entity_payload_for_diff($entity_id)
    {
        $post = get_post((int) $entity_id, ARRAY_A);
        if (! is_array($post)) {
            return null;
        }
        $post['meta'] = [
            '_bricks_page_content_2' => get_post_meta((int) $entity_id, '_bricks_page_content_2', true),
        ];
        return $post;
    }

    /**
     * Return artifact class label for UI.
     *
     * @param string $artifact_type
     * @return string
     */
    public static function get_artifact_class_label($artifact_type)
    {
        return (string) $artifact_type === 'bricks_template' ? 'Entity' : 'Option';
    }

    /**
     * Normalize incoming manifest payload shapes for compatibility.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalize_manifest_payload(array $payload)
    {
        if (isset($payload['manifest']) && is_array($payload['manifest'])) {
            $payload = $payload['manifest'];
        }

        $manifest = [
            'package_id' => (string) ($payload['package_id'] ?? $payload['id'] ?? ''),
            'version' => (string) ($payload['version'] ?? ''),
            'channel' => (string) ($payload['channel'] ?? ''),
            'artifacts' => [],
            'compatibility' => [
                'source_shape' => 'canonical',
            ],
        ];

        $rows = [];
        if (isset($payload['artifacts']) && is_array($payload['artifacts'])) {
            $rows = $payload['artifacts'];
        } elseif (isset($payload['items']) && is_array($payload['items'])) {
            $rows = $payload['items'];
            $manifest['compatibility']['source_shape'] = 'legacy_items';
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $artifact_uid = (string) ($row['artifact_uid'] ?? $row['uid'] ?? '');
            $artifact_type = (string) ($row['artifact_type'] ?? $row['type'] ?? '');
            if ($artifact_uid === '' || $artifact_type === '') {
                continue;
            }
            $manifest['artifacts'][] = [
                'artifact_uid' => $artifact_uid,
                'artifact_type' => $artifact_type,
                'hash' => (string) ($row['hash'] ?? ''),
                'payload' => $row['payload'] ?? null,
            ];
        }

        return $manifest;
    }

    /**
     * Build deterministic bulk chunks.
     *
     * @param array<int, string> $artifact_uids
     * @param int $chunk_size
     * @return array<int, array<int, string>>
     */
    public static function build_bulk_chunks(array $artifact_uids, $chunk_size)
    {
        $chunk_size = max(1, (int) $chunk_size);
        $seen = [];
        $normalized = [];
        foreach ($artifact_uids as $uid) {
            $clean = sanitize_text_field((string) $uid);
            if ($clean === '' || isset($seen[$clean])) {
                continue;
            }
            $seen[$clean] = true;
            $normalized[] = $clean;
        }
        sort($normalized, SORT_STRING);
        return array_values(array_map('array_values', array_chunk($normalized, $chunk_size)));
    }

    /**
     * Get deprecation notices for legacy paths/contracts.
     *
     * @return array<int, array<string, string>>
     */
    public static function get_deprecation_notices()
    {
        return [
            [
                'id' => 'legacy-menu-slug',
                'message' => 'Legacy direct admin path /wp-admin/' . self::LEGACY_MENU_SLUG . ' is deprecated; use admin.php?page=' . self::MENU_SLUG . '.',
                'since' => '1.0.0',
                'remove_after' => '2.0.0',
            ],
        ];
    }

    /**
     * Return UI contract/feature capability payload.
     *
     * @return \WP_REST_Response
     */
    public static function get_ui_contract()
    {
        return rest_ensure_response([
            'ui_contract_version' => self::UI_CONTRACT_VERSION,
            'features' => [
                'bulk_mode' => true,
                'offline_export' => true,
                'fleet_mode_planning' => self::get_bool_setting(self::OPTION_FLEET_MODE_ENABLED, false),
                'compat_manifest_normalization' => true,
            ],
            'deprecations' => self::get_deprecation_notices(),
        ]);
    }

    /**
     * Register Phase 1 gate validation endpoint.
     *
     * @return void
     */
    public static function register_rest_routes()
    {
        register_rest_route(
            'dbvc/v1/bricks',
            '/status',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_status'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/drift-scan',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Drift::class, 'rest_scan'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/apply',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Apply::class, 'rest_apply'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/restore-points',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Apply::class, 'rest_create_restore_point'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/restore-points/(?P<restore_id>[^/]+)/rollback',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Apply::class, 'rest_rollback'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/proposals',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [DBVC_Bricks_Proposals::class, 'rest_list'],
                    'permission_callback' => [self::class, 'can_manage'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [DBVC_Bricks_Proposals::class, 'rest_submit'],
                    'permission_callback' => [self::class, 'can_manage'],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/proposals/(?P<proposal_id>[^/]+)',
            [
                'methods'             => 'PATCH',
                'callback'            => [DBVC_Bricks_Proposals::class, 'rest_patch'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [DBVC_Bricks_Packages::class, 'rest_list'],
                    'permission_callback' => [self::class, 'can_manage'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [DBVC_Bricks_Packages::class, 'rest_create'],
                    'permission_callback' => [self::class, 'can_manage'],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages/bootstrap-create',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Packages::class, 'rest_bootstrap_create'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages/pull-latest',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Packages::class, 'rest_pull_latest'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages/publish-remote',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Packages::class, 'rest_publish_remote'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages/test-remote-connection',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Packages::class, 'rest_test_remote_connection'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages/(?P<package_id>[^/]+)',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [DBVC_Bricks_Packages::class, 'rest_get'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages/(?P<package_id>[^/]+)/promote',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Packages::class, 'rest_promote'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages/(?P<package_id>[^/]+)/revoke',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Packages::class, 'rest_revoke'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/packages/(?P<package_id>[^/]+)/ack',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Packages::class, 'rest_ack'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/connected-sites',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [DBVC_Bricks_Connected_Sites::class, 'rest_list'],
                    'permission_callback' => [self::class, 'can_manage'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [DBVC_Bricks_Connected_Sites::class, 'rest_upsert'],
                    'permission_callback' => [self::class, 'can_manage'],
                ],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/intro/packet',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Onboarding::class, 'rest_intro_packet'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/intro/handshake',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Onboarding::class, 'rest_intro_handshake'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/commands/ping',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [DBVC_Bricks_Command_Auth::class, 'rest_signed_ping'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/diagnostics',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_diagnostics'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/ui-contract',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_ui_contract'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/ui-event',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'post_ui_event'],
                'permission_callback' => [self::class, 'can_manage'],
            ]
        );
    }

    /**
     * Permission callback.
     *
     * @return bool
     */
    public static function can_manage()
    {
        return current_user_can('manage_options');
    }

    /**
     * Return add-on status payload.
     *
     * @return \WP_REST_Response
     */
    public static function get_status()
    {
        return rest_ensure_response([
            'enabled' => self::is_enabled(),
            'addon'   => 'bricks',
            'role' => self::get_role_mode(),
            'read_only' => self::get_bool_setting('dbvc_bricks_read_only', false),
            'fleet_mode_enabled' => self::get_bool_setting(self::OPTION_FLEET_MODE_ENABLED, false),
            'visibility' => self::get_enum_setting(self::OPTION_VISIBILITY, ['submenu_only', 'configure_and_submenu'], 'configure_and_submenu'),
            'ui_contract_version' => self::UI_CONTRACT_VERSION,
            'deprecations' => self::get_deprecation_notices(),
            'timestamp_gmt' => gmdate('c'),
        ]);
    }

    /**
     * Return recent UI diagnostics entries.
     *
     * @return \WP_REST_Response
     */
    public static function get_diagnostics()
    {
        $rows = get_option(self::OPTION_UI_DIAGNOSTICS, []);
        if (! is_array($rows)) {
            $rows = [];
        }
        $limit = isset($_GET['limit']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? absint(wp_unslash((string) $_GET['limit'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : self::UI_DIAGNOSTIC_DEFAULT_LIMIT;
        if ($limit < 1) {
            $limit = self::UI_DIAGNOSTIC_DEFAULT_LIMIT;
        }
        $limit = min($limit, self::UI_DIAGNOSTIC_MAX_ITEMS);
        $rows = array_slice(array_values($rows), -$limit);
        $package_delivery = class_exists('DBVC_Bricks_Packages')
            ? DBVC_Bricks_Packages::get_delivery_diagnostics($limit)
            : [];
        return rest_ensure_response([
            'items' => array_values($rows),
            'package_delivery' => $package_delivery,
            'limit' => $limit,
        ]);
    }

    /**
     * Store a UI event diagnostic.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function post_ui_event(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $event_type = sanitize_key((string) ($params['event_type'] ?? 'unknown'));
        if ($event_type === '') {
            $event_type = 'unknown';
        }
        $payload = isset($params['payload']) && is_array($params['payload']) ? $params['payload'] : [];
        $payload = self::sanitize_diagnostics_payload($payload);
        $correlation_id = sanitize_text_field((string) $request->get_header('X-DBVC-Correlation-ID'));
        $allowed_event_types = [
            'status_loaded',
            'packages_loaded',
            'drift_scan_complete',
            'apply_complete',
            'restore_created',
            'rollback_complete',
            'proposals_loaded',
            'proposal_submitted',
            'proposal_transition',
            'diagnostics_loaded',
            'review_export',
            'ui_error',
            'package_published',
            'package_published_remote',
            'package_promoted',
            'package_revoked',
            'connected_sites_loaded',
            'connected_sites_saved',
            'package_publish_preflight',
            'package_bootstrapped',
            'package_pull_dry_run',
            'connection_test_passed',
            'unknown',
        ];
        if (! in_array($event_type, $allowed_event_types, true)) {
            $event_type = 'unknown';
        }
        $entry = [
            'event_type' => $event_type,
            'payload' => $payload,
            'correlation_id' => $correlation_id,
            'actor_id' => get_current_user_id(),
            'at' => gmdate('c'),
        ];

        $rows = get_option(self::OPTION_UI_DIAGNOSTICS, []);
        if (! is_array($rows)) {
            $rows = [];
        }
        $rows[] = $entry;
        if (count($rows) > self::UI_DIAGNOSTIC_MAX_ITEMS) {
            $rows = array_slice($rows, -self::UI_DIAGNOSTIC_MAX_ITEMS);
        }
        update_option(self::OPTION_UI_DIAGNOSTICS, array_values($rows));

        do_action('dbvc_bricks_ui_event', $entry);
        if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
            $encoded = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            DBVC_Database::log_activity('dbvc_bricks_ui_event', is_string($encoded) ? $encoded : '{}');
        }

        return rest_ensure_response([
            'stored' => true,
            'entry' => $entry,
        ]);
    }

    /**
     * @param mixed $payload
     * @param int $depth
     * @return mixed
     */
    public static function sanitize_diagnostics_payload($payload, $depth = 0)
    {
        if ($depth >= self::UI_DIAGNOSTIC_MAX_DEPTH) {
            return '[truncated-depth]';
        }

        if (is_array($payload)) {
            $clean = [];
            $count = 0;
            foreach ($payload as $key => $value) {
                if ($count >= self::UI_DIAGNOSTIC_MAX_KEYS) {
                    $clean['__truncated__'] = true;
                    break;
                }
                $sanitized_key = is_string($key) ? sanitize_key($key) : (int) $key;
                $clean[$sanitized_key] = self::sanitize_diagnostics_payload($value, $depth + 1);
                $count++;
            }
            return $clean;
        }

        if (is_bool($payload) || is_int($payload) || is_float($payload) || $payload === null) {
            return $payload;
        }

        return sanitize_text_field((string) $payload);
    }

    /**
     * Ensure scheduled hook exists while enabled.
     *
     * @return void
     */
    public static function maybe_register_scheduled_jobs()
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Scheduled job placeholder for Phase 1 hook gating.
     *
     * @return void
     */
    public static function run_scheduled_job()
    {
        do_action('dbvc_bricks_addon_job_run');
        if (
            self::get_bool_setting(self::OPTION_FLEET_MODE_ENABLED, false)
            && is_multisite()
        ) {
            do_action('dbvc_bricks_fleet_mode_planning', [
                'network_id' => function_exists('get_current_network_id') ? (int) get_current_network_id() : 0,
                'blog_id' => (int) get_current_blog_id(),
                'at' => gmdate('c'),
            ]);
        }
    }
}
