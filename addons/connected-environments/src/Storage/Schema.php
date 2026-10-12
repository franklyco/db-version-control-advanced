<?php

namespace Dbvc\Connected\Storage;

/**
 * Connector-owned durable stores.
 *
 * Tables are created only through the explicit module lifecycle (enable via
 * settings, `plugins_loaded` upgrade while enabled, or the worker/CLI ensure
 * step). Ordinary public requests with the connector disabled never reach
 * this class. The schema-version option is written only after every table
 * is verified readable, so a partial migration leaves the module in
 * `migration_required` instead of half-registered.
 */
final class Schema
{
    public const SCHEMA_VERSION = 5;
    public const OPTION_SCHEMA_VERSION = 'dbvc_connected_schema_version';

    /**
     * @var bool|null Per-request readiness memo.
     */
    private static $ready_memo = null;

    /**
     * @param string $name state|jobs|objects|outbox|identity
     * @return string
     */
    public static function table($name)
    {
        global $wpdb;

        $map = [
            'state' => "{$wpdb->prefix}dbvc_ce_state",
            'jobs' => "{$wpdb->prefix}dbvc_ce_jobs",
            'objects' => "{$wpdb->prefix}dbvc_ce_objects",
            'outbox' => "{$wpdb->prefix}dbvc_ce_outbox",
            'identity' => "{$wpdb->prefix}dbvc_ce_identity",
            'inbox' => "{$wpdb->prefix}dbvc_ce_inbox",
            'preparations' => "{$wpdb->prefix}dbvc_ce_preparations",
            'operations' => "{$wpdb->prefix}dbvc_ce_operations",
        ];

        if (! isset($map[$name])) {
            throw new \InvalidArgumentException('Unknown connector table: ' . (string) $name);
        }

        return $map[$name];
    }

    /**
     * @return array<int, string>
     */
    public static function table_names()
    {
        return array_map([self::class, 'table'], ['state', 'jobs', 'objects', 'outbox', 'identity', 'inbox', 'preparations', 'operations']);
    }

    /**
     * Stored schema version is current. Cheap: one cached option read.
     *
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
     * Explicit lifecycle install/upgrade (settings enable, CLI). The only path
     * that creates the tables for the first time.
     *
     * @return bool True when the schema is ready afterwards.
     */
    public static function install()
    {
        self::reset_memo();
        if (self::is_ready()) {
            return true;
        }

        return self::create_or_update_tables();
    }

    /**
     * Version-bump upgrade for an already installed schema. Runs from
     * `plugins_loaded` while the connector is enabled; a never-installed
     * schema (stored version 0) is left alone so ordinary requests perform
     * no DDL and the gate reports `migration_required` until an explicit
     * install.
     *
     * @return bool True when the schema is ready afterwards.
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
     * Every connector table answers a trivial query. Works for temporary tables
     * in the PHPUnit harness, where SHOW TABLES would not list them.
     *
     * @return bool
     */
    public static function tables_exist()
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $ok = true;
        foreach (self::table_names() as $table) {
            $result = $wpdb->query("SELECT 1 FROM {$table} LIMIT 0"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($result === false) {
                $ok = false;
                break;
            }
        }
        $wpdb->suppress_errors($suppress);

        return $ok;
    }

    /**
     * @return bool
     */
    private static function create_or_update_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $state = self::table('state');
        $jobs = self::table('jobs');
        $objects = self::table('objects');
        $outbox = self::table('outbox');
        $identity = self::table('identity');
        $inbox = self::table('inbox');
        $preparations = self::table('preparations');
        $operations = self::table('operations');

        $sql = [];

        $sql[] = "CREATE TABLE {$state} (
            singleton_id tinyint(3) unsigned NOT NULL,
            environment_id varbinary(128) NOT NULL DEFAULT '',
            installation_epoch varbinary(128) NOT NULL DEFAULT '',
            enrollment_state varbinary(32) NOT NULL DEFAULT 'uninitialized',
            next_sequence bigint(20) unsigned NOT NULL DEFAULT 1,
            schema_version int(10) unsigned NOT NULL DEFAULT 0,
            worker_status longtext NULL,
            hub_url text NULL,
            hub_agency_id varbinary(128) NOT NULL DEFAULT '',
            hub_client_id varbinary(128) NOT NULL DEFAULT '',
            hub_principal varchar(191) NOT NULL DEFAULT '',
            hub_secret longtext NULL,
            connection_state varbinary(32) NOT NULL DEFAULT 'disconnected',
            hold_reason varbinary(128) NOT NULL DEFAULT '',
            enrolled_site_url text NULL,
            delivery_status longtext NULL,
            inbox_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
            inbox_status longtext NULL,
            release_status longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (singleton_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$jobs} (
            job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain varbinary(128) NOT NULL,
            object_key varbinary(128) NOT NULL,
            generation bigint(20) unsigned NOT NULL DEFAULT 1,
            claimed_generation bigint(20) unsigned NOT NULL DEFAULT 0,
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            lease_token varbinary(64) NULL,
            lease_until datetime NULL,
            last_error_code varbinary(128) NULL,
            last_signal_context text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (job_id),
            UNIQUE KEY dirty_object (domain, object_key),
            KEY due_jobs (available_at, lease_until, job_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$objects} (
            object_row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL,
            profile varbinary(128) NOT NULL,
            storage_key varbinary(128) NOT NULL DEFAULT '',
            display_name varchar(191) NOT NULL DEFAULT '',
            object_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
            snapshot_complete tinyint(1) unsigned NOT NULL DEFAULT 0,
            semantic_hash varbinary(64) NOT NULL,
            storage_fingerprint varbinary(64) NULL,
            position int(10) unsigned NULL,
            observed_sequence bigint(20) unsigned NOT NULL,
            observed_epoch varbinary(128) NOT NULL,
            snapshot_body longtext NULL,
            problems text NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (object_row_id),
            UNIQUE KEY object_profile (domain, instance_uid, profile),
            KEY domain_storage_key (domain, storage_key)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$outbox} (
            outbox_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            installation_epoch varbinary(128) NOT NULL,
            event_id varbinary(128) NOT NULL,
            source_sequence bigint(20) unsigned NOT NULL,
            domain varbinary(128) NOT NULL DEFAULT '',
            instance_uid varbinary(128) NOT NULL DEFAULT '',
            body_digest varbinary(64) NOT NULL,
            immutable_body longtext NOT NULL,
            delivery_state varbinary(32) NOT NULL DEFAULT 'pending',
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            lease_token varbinary(64) NULL,
            lease_until datetime NULL,
            receipt_body longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (outbox_id),
            UNIQUE KEY event_identity (installation_epoch, event_id),
            UNIQUE KEY event_sequence (installation_epoch, source_sequence),
            KEY pending_delivery (delivery_state, available_at, outbox_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$identity} (
            identity_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain varbinary(128) NOT NULL,
            storage_key varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL,
            display_name varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (identity_id),
            UNIQUE KEY domain_storage_key (domain, storage_key),
            UNIQUE KEY domain_instance (domain, instance_uid)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$inbox} (
            inbox_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            hub_url text NULL,
            delivery_id bigint(20) unsigned NOT NULL,
            source_environment_id varbinary(128) NOT NULL,
            installation_epoch varbinary(128) NOT NULL,
            event_id varbinary(128) NOT NULL,
            source_sequence bigint(20) unsigned NOT NULL,
            domain varbinary(128) NOT NULL,
            instance_uid varbinary(128) NOT NULL,
            profile varbinary(128) NOT NULL,
            body_digest varbinary(64) NOT NULL,
            policy_revision varbinary(64) NOT NULL DEFAULT '',
            immutable_body longtext NOT NULL,
            received_at datetime NOT NULL,
            acked_at datetime NULL,
            PRIMARY KEY  (inbox_id),
            UNIQUE KEY delivery (delivery_id),
            UNIQUE KEY source_event (source_environment_id, installation_epoch, event_id),
            KEY source_object (source_environment_id, domain, instance_uid, profile)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$preparations} (
            preparation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation_id varbinary(128) NOT NULL,
            release_uid varbinary(128) NOT NULL,
            release_digest varbinary(64) NOT NULL,
            hub_url text NULL,
            installation_epoch varbinary(128) NOT NULL,
            outcome varbinary(32) NOT NULL,
            items_ready int(10) unsigned NOT NULL DEFAULT 0,
            items_noop int(10) unsigned NOT NULL DEFAULT 0,
            items_blocked int(10) unsigned NOT NULL DEFAULT 0,
            receipt longtext NOT NULL,
            receipt_digest varbinary(64) NOT NULL,
            prepared_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            reported_at datetime NULL,
            report_error varbinary(128) NOT NULL DEFAULT '',
            PRIMARY KEY  (preparation_id),
            UNIQUE KEY operation_id (operation_id),
            KEY release_uid (release_uid, preparation_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$operations} (
            operation_row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation_id varbinary(128) NOT NULL,
            approval_uid varbinary(128) NOT NULL,
            release_uid varbinary(128) NOT NULL,
            release_digest varbinary(64) NOT NULL,
            receipt_digest varbinary(64) NOT NULL,
            hub_url text NULL,
            installation_epoch varbinary(128) NOT NULL,
            state varbinary(32) NOT NULL,
            outcome varbinary(32) NOT NULL DEFAULT '',
            before_image longtext NULL,
            journal longtext NULL,
            execution_receipt longtext NULL,
            execution_digest varbinary(64) NOT NULL DEFAULT '',
            started_at datetime NOT NULL,
            finished_at datetime NULL,
            reported_at datetime NULL,
            report_error varbinary(128) NOT NULL DEFAULT '',
            PRIMARY KEY  (operation_row_id),
            UNIQUE KEY operation_id (operation_id),
            KEY release_uid (release_uid, operation_row_id)
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

    /**
     * Whether the connector tables use a transactional engine. Determined from
     * the state table; temporary tables report their engine too.
     *
     * @return bool|null Null when the table cannot be inspected.
     */
    public static function is_transactional()
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::table('state')), ARRAY_A);
        $wpdb->suppress_errors($suppress);

        if (! is_array($row) || empty($row['Engine'])) {
            return null;
        }

        return in_array(strtolower((string) $row['Engine']), ['innodb', 'xtradb', 'tokudb', 'rocksdb'], true);
    }
}
