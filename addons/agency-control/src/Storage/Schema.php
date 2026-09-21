<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Hub-owned durable stores. Created only through the explicit enable/CLI
 * lifecycle; `plugins_loaded` applies version bumps to an installed schema
 * only, so ordinary requests never perform DDL.
 */
final class Schema
{
    public const SCHEMA_VERSION = 8;
    public const OPTION_SCHEMA_VERSION = 'dbvc_agency_control_schema_version';
    public const ENVIRONMENT_ROLE = 'dbvc_connected_environment';

    /**
     * @var bool|null
     */
    private static $ready_memo = null;

    /**
     * @param string $name invitations|environments|events|projections
     * @return string
     */
    public static function table($name)
    {
        global $wpdb;

        $map = [
            'invitations' => "{$wpdb->prefix}dbvc_ac_invitations",
            'environments' => "{$wpdb->prefix}dbvc_ac_environments",
            'events' => "{$wpdb->prefix}dbvc_ac_events",
            'projections' => "{$wpdb->prefix}dbvc_ac_projections",
            'subscriptions' => "{$wpdb->prefix}dbvc_ac_subscriptions",
            'deliveries' => "{$wpdb->prefix}dbvc_ac_deliveries",
            'review_items' => "{$wpdb->prefix}dbvc_ac_review_items",
            'baselines' => "{$wpdb->prefix}dbvc_ac_baselines",
            'instance_links' => "{$wpdb->prefix}dbvc_ac_instance_links",
            'definitions' => "{$wpdb->prefix}dbvc_ac_definitions",
            'overrides' => "{$wpdb->prefix}dbvc_ac_overrides",
            'releases' => "{$wpdb->prefix}dbvc_ac_releases",
            'release_items' => "{$wpdb->prefix}dbvc_ac_release_items",
            'preparations' => "{$wpdb->prefix}dbvc_ac_preparations",
            'approvals' => "{$wpdb->prefix}dbvc_ac_approvals",
        ];
        if (! isset($map[$name])) {
            throw new \InvalidArgumentException('Unknown hub table: ' . (string) $name);
        }

        return $map[$name];
    }

    /**
     * @return array<int, string>
     */
    public static function table_names()
    {
        return array_map([self::class, 'table'], ['invitations', 'environments', 'events', 'projections', 'subscriptions', 'deliveries', 'review_items', 'baselines', 'instance_links', 'definitions', 'overrides', 'releases', 'release_items', 'preparations', 'approvals']);
    }

    /**
     * @return bool
     */
    public static function is_ready()
    {
        if (self::$ready_memo === null) {
            self::$ready_memo = (int) get_option(self::OPTION_SCHEMA_VERSION, 0) >= self::SCHEMA_VERSION;
        }

        return self::$ready_memo;
    }

    /**
     * @return void
     */
    public static function reset_memo()
    {
        self::$ready_memo = null;
    }

    /**
     * @return bool
     */
    public static function tables_exist()
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $ok = true;
        foreach (self::table_names() as $table) {
            if ($wpdb->query("SELECT 1 FROM {$table} LIMIT 0") === false) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $ok = false;
                break;
            }
        }
        $wpdb->suppress_errors($suppress);

        return $ok;
    }

    /**
     * Explicit lifecycle install.
     *
     * @return bool
     */
    public static function install()
    {
        self::reset_memo();
        self::ensure_role();
        if (self::is_ready()) {
            return true;
        }

        return self::create_or_update_tables();
    }

    /**
     * Version-bump upgrade for an installed schema only.
     *
     * @return bool
     */
    public static function maybe_upgrade()
    {
        self::reset_memo();
        if (self::is_ready()) {
            return true;
        }
        if ((int) get_option(self::OPTION_SCHEMA_VERSION, 0) === 0) {
            return false;
        }

        return self::create_or_update_tables();
    }

    /**
     * Capability-less role for enrolled-environment service users. Such a
     * user can authenticate with an application password but cannot use
     * wp-admin or any capability-gated surface.
     *
     * @return void
     */
    public static function ensure_role()
    {
        if (get_role(self::ENVIRONMENT_ROLE) === null) {
            add_role(self::ENVIRONMENT_ROLE, 'DBVC Connected Environment', []);
        }
    }

    /**
     * @return bool|null
     */
    public static function is_transactional()
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::table('events')), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        if (! is_array($row) || empty($row['Engine'])) {
            return null;
        }

        return in_array(strtolower((string) $row['Engine']), ['innodb', 'xtradb', 'tokudb', 'rocksdb'], true);
    }

    /**
     * @return bool
     */
    private static function create_or_update_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $invitations = self::table('invitations');
        $environments = self::table('environments');
        $events = self::table('events');
        $projections = self::table('projections');
        $subscriptions = self::table('subscriptions');
        $deliveries = self::table('deliveries');
        $review_items = self::table('review_items');
        $baselines = self::table('baselines');
        $instance_links = self::table('instance_links');
        $definitions = self::table('definitions');
        $overrides = self::table('overrides');
        $releases = self::table('releases');
        $release_items = self::table('release_items');
        $preparations = self::table('preparations');
        $approvals = self::table('approvals');

        $sql = [];
        $sql[] = "CREATE TABLE {$invitations} (
            invitation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash varbinary(64) NOT NULL,
            agency_id varbinary(128) NOT NULL,
            client_id varbinary(128) NOT NULL,
            environment_label varchar(191) NOT NULL DEFAULT '',
            environment_id varbinary(128) NOT NULL DEFAULT '',
            expires_at datetime NOT NULL,
            consumed_at datetime NULL,
            consumed_environment_id varbinary(128) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (invitation_id),
            UNIQUE KEY token_hash (token_hash),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$environments} (
            environment_row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agency_id varbinary(128) NOT NULL,
            client_id varbinary(128) NOT NULL,
            environment_id varbinary(128) NOT NULL,
            label varchar(191) NOT NULL DEFAULT '',
            current_epoch varbinary(128) NOT NULL,
            epoch_changed_at datetime NOT NULL,
            principal_user_id bigint(20) unsigned NOT NULL,
            principal_uuid varbinary(64) NOT NULL,
            status varbinary(32) NOT NULL DEFAULT 'enabled',
            hold_reason varbinary(128) NOT NULL DEFAULT '',
            enrolled_site_url text NULL,
            last_site_url text NULL,
            last_contact_at datetime NULL,
            last_batch_id varbinary(128) NOT NULL DEFAULT '',
            max_received_sequence bigint(20) unsigned NOT NULL DEFAULT 0,
            received_events bigint(20) unsigned NOT NULL DEFAULT 0,
            last_inbox_poll_at datetime NULL,
            last_inbox_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (environment_row_id),
            UNIQUE KEY environment_id (environment_id),
            UNIQUE KEY principal (principal_user_id, principal_uuid),
            KEY agency_client_status (agency_id, client_id, status)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$events} (
            event_row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            environment_id varbinary(128) NOT NULL,
            installation_epoch varbinary(128) NOT NULL,
            event_id varbinary(128) NOT NULL,
            source_sequence bigint(20) unsigned NOT NULL,
            domain varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL,
            profile varbinary(128) NOT NULL,
            body_digest varbinary(64) NOT NULL,
            immutable_body longtext NOT NULL,
            routing_state varbinary(32) NOT NULL DEFAULT 'pending',
            routing_policy_revision varbinary(64) NULL,
            batch_id varbinary(128) NOT NULL DEFAULT '',
            received_at datetime NOT NULL,
            PRIMARY KEY  (event_row_id),
            UNIQUE KEY event_identity (environment_id, installation_epoch, event_id),
            UNIQUE KEY event_sequence (environment_id, installation_epoch, source_sequence),
            KEY routing (routing_state, event_row_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$projections} (
            projection_row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            environment_id varbinary(128) NOT NULL,
            installation_epoch varbinary(128) NOT NULL,
            domain varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL,
            profile varbinary(128) NOT NULL,
            semantic_hash varbinary(64) NOT NULL,
            object_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
            snapshot_complete tinyint(1) unsigned NOT NULL DEFAULT 0,
            observed_sequence bigint(20) unsigned NOT NULL,
            observed_at datetime NOT NULL,
            event_id varbinary(128) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (projection_row_id),
            UNIQUE KEY object_profile (environment_id, installation_epoch, domain, instance_uid, profile)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$subscriptions} (
            subscription_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subscription_type varbinary(32) NOT NULL,
            agency_id varbinary(128) NOT NULL,
            client_id varbinary(128) NOT NULL,
            source_environment_id varbinary(128) NOT NULL,
            target_environment_id varbinary(128) NOT NULL DEFAULT '',
            domain varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL DEFAULT '',
            definition_uid varbinary(128) NOT NULL DEFAULT '',
            adopted_version varbinary(64) NOT NULL DEFAULT '',
            channel varbinary(64) NOT NULL DEFAULT 'stable',
            enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (subscription_id),
            UNIQUE KEY typed_identity (subscription_type, source_environment_id, target_environment_id, domain, instance_uid, definition_uid),
            KEY source_domain (source_environment_id, domain, enabled)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$deliveries} (
            delivery_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_row_id bigint(20) unsigned NOT NULL,
            source_environment_id varbinary(128) NOT NULL,
            target_environment_id varbinary(128) NOT NULL,
            domain varbinary(128) NOT NULL,
            policy_revision varbinary(64) NOT NULL,
            state varbinary(32) NOT NULL DEFAULT 'pending',
            acked_at datetime NULL,
            cancelled_reason varbinary(128) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (delivery_id),
            UNIQUE KEY event_target (event_row_id, target_environment_id),
            KEY target_state (target_environment_id, state, delivery_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$review_items} (
            review_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_row_id bigint(20) unsigned NOT NULL,
            agency_id varbinary(128) NOT NULL,
            client_id varbinary(128) NOT NULL,
            environment_id varbinary(128) NOT NULL,
            domain varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL,
            definition_uid varbinary(128) NOT NULL,
            installation_epoch varbinary(64) NOT NULL DEFAULT '',
            event_sequence bigint(20) unsigned NOT NULL DEFAULT 0,
            state varbinary(32) NOT NULL DEFAULT 'observed',
            policy_revision varbinary(64) NOT NULL,
            drift varbinary(32) NOT NULL DEFAULT '',
            version_state varbinary(32) NOT NULL DEFAULT '',
            resolution varbinary(64) NOT NULL DEFAULT '',
            note varchar(191) NOT NULL DEFAULT '',
            classified_at datetime NULL,
            resolved_at datetime NULL,
            resolved_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (review_item_id),
            UNIQUE KEY event_definition (event_row_id, definition_uid),
            KEY definition_state (definition_uid, state, review_item_id),
            KEY environment_state (environment_id, state, review_item_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$baselines} (
            baseline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_environment_id varbinary(128) NOT NULL,
            target_environment_id varbinary(128) NOT NULL,
            domain varbinary(128) NOT NULL,
            source_instance_uid varbinary(128) NOT NULL,
            target_instance_uid varbinary(128) NOT NULL,
            profile varbinary(128) NOT NULL,
            baseline_hash varbinary(64) NOT NULL,
            source_sequence bigint(20) unsigned NOT NULL DEFAULT 0,
            target_sequence bigint(20) unsigned NOT NULL DEFAULT 0,
            source_epoch varbinary(128) NOT NULL DEFAULT '',
            target_epoch varbinary(128) NOT NULL DEFAULT '',
            confirmed_by bigint(20) unsigned NULL,
            confirmed_at datetime NOT NULL,
            note varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (baseline_id),
            UNIQUE KEY pair_object (source_environment_id, target_environment_id, domain, source_instance_uid, profile)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$instance_links} (
            link_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain varbinary(128) NOT NULL,
            source_environment_id varbinary(128) NOT NULL,
            source_instance_uid varbinary(128) NOT NULL,
            target_environment_id varbinary(128) NOT NULL,
            target_instance_uid varbinary(128) NOT NULL,
            created_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            note varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (link_id),
            UNIQUE KEY source_link (domain, source_environment_id, source_instance_uid, target_environment_id),
            UNIQUE KEY target_link (domain, target_environment_id, target_instance_uid, source_environment_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$definitions} (
            definition_row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agency_id varbinary(128) NOT NULL,
            definition_uid varbinary(128) NOT NULL,
            version varbinary(64) NOT NULL,
            version_order int(10) unsigned NOT NULL,
            channel varbinary(64) NOT NULL DEFAULT 'stable',
            domain varbinary(128) NOT NULL,
            profile varbinary(128) NOT NULL,
            definition_hash varbinary(64) NOT NULL,
            desired tinyint(1) unsigned NOT NULL DEFAULT 0,
            source_environment_id varbinary(128) NOT NULL DEFAULT '',
            source_instance_uid varbinary(128) NOT NULL DEFAULT '',
            published_by bigint(20) unsigned NULL,
            published_at datetime NOT NULL,
            note varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (definition_row_id),
            UNIQUE KEY definition_version (agency_id, definition_uid, version),
            UNIQUE KEY definition_order (agency_id, definition_uid, channel, version_order),
            KEY definition_channel (agency_id, definition_uid, channel, desired)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$overrides} (
            override_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            agency_id varbinary(128) NOT NULL,
            client_id varbinary(128) NOT NULL,
            environment_id varbinary(128) NOT NULL,
            domain varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL,
            definition_uid varbinary(128) NOT NULL,
            definition_version varbinary(64) NOT NULL DEFAULT '',
            approved_hash varbinary(64) NOT NULL,
            policy_revision varbinary(64) NOT NULL DEFAULT '',
            state varbinary(32) NOT NULL DEFAULT 'approved',
            rationale text NULL,
            approved_by bigint(20) unsigned NULL,
            approved_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (override_id),
            UNIQUE KEY instance_definition (environment_id, domain, instance_uid, definition_uid)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$releases} (
            release_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            release_uid varbinary(128) NOT NULL,
            agency_id varbinary(128) NOT NULL,
            client_id varbinary(128) NOT NULL,
            source_environment_id varbinary(128) NOT NULL,
            source_epoch varbinary(64) NOT NULL,
            state varbinary(32) NOT NULL DEFAULT 'open',
            item_count int(10) unsigned NOT NULL DEFAULT 0,
            digest varbinary(64) NOT NULL DEFAULT '',
            note varchar(191) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            sealed_at datetime NULL,
            PRIMARY KEY  (release_id),
            UNIQUE KEY release_uid (release_uid),
            KEY source_state (source_environment_id, state, release_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$release_items} (
            release_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            release_id bigint(20) unsigned NOT NULL,
            domain varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL,
            profile varbinary(128) NOT NULL,
            operation varbinary(16) NOT NULL DEFAULT 'replace',
            after_hash varbinary(64) NOT NULL,
            source_sequence bigint(20) unsigned NOT NULL DEFAULT 0,
            payload_state varbinary(32) NOT NULL DEFAULT 'requested',
            payload longtext NULL,
            payload_reason varbinary(128) NOT NULL DEFAULT '',
            payload_received_at datetime NULL,
            PRIMARY KEY  (release_item_id),
            UNIQUE KEY release_object (release_id, domain, instance_uid, profile),
            KEY release_payload (release_id, payload_state)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$preparations} (
            preparation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation_id varbinary(128) NOT NULL,
            release_id bigint(20) unsigned NOT NULL,
            release_uid varbinary(128) NOT NULL,
            target_environment_id varbinary(128) NOT NULL,
            target_epoch varbinary(64) NOT NULL,
            state varbinary(32) NOT NULL DEFAULT 'requested',
            outcome varbinary(32) NOT NULL DEFAULT '',
            items_ready int(10) unsigned NOT NULL DEFAULT 0,
            items_noop int(10) unsigned NOT NULL DEFAULT 0,
            items_blocked int(10) unsigned NOT NULL DEFAULT 0,
            receipt longtext NULL,
            receipt_digest varbinary(64) NOT NULL DEFAULT '',
            hub_notes longtext NULL,
            requested_by bigint(20) unsigned NULL,
            requested_at datetime NOT NULL,
            received_at datetime NULL,
            expires_at datetime NULL,
            PRIMARY KEY  (preparation_id),
            UNIQUE KEY operation_id (operation_id),
            KEY target_state (target_environment_id, state, preparation_id),
            KEY release_target (release_id, target_environment_id, preparation_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$approvals} (
            approval_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            approval_uid varbinary(128) NOT NULL,
            operation_id varbinary(128) NOT NULL,
            release_id bigint(20) unsigned NOT NULL,
            release_uid varbinary(128) NOT NULL,
            release_digest varbinary(64) NOT NULL,
            receipt_digest varbinary(64) NOT NULL,
            target_environment_id varbinary(128) NOT NULL,
            target_epoch varbinary(64) NOT NULL,
            policy_revision varbinary(64) NOT NULL DEFAULT '',
            state varbinary(32) NOT NULL DEFAULT 'approved',
            note varchar(191) NOT NULL DEFAULT '',
            approved_by bigint(20) unsigned NULL,
            approved_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            execution_outcome varbinary(32) NOT NULL DEFAULT '',
            execution_receipt longtext NULL,
            execution_digest varbinary(64) NOT NULL DEFAULT '',
            executed_at datetime NULL,
            PRIMARY KEY  (approval_id),
            UNIQUE KEY approval_uid (approval_uid),
            UNIQUE KEY operation_id (operation_id),
            KEY target_state (target_environment_id, state, approval_id)
        ) {$charset_collate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        if (! self::tables_exist()) {
            self::reset_memo();
            return false;
        }

        update_option(self::OPTION_SCHEMA_VERSION, (string) self::SCHEMA_VERSION, false);
        self::reset_memo();

        return self::is_ready();
    }
}
