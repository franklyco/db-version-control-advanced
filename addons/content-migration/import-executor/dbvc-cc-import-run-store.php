<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Import_Run_Store
{
    private const DBVC_CC_SCHEMA_VERSION = 1;
    private const DBVC_CC_OPTION_SCHEMA_VERSION = 'dbvc_cc_import_run_schema_version';

    /**
     * @var DBVC_CC_Import_Run_Store|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Import_Run_Store
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * @return void
     */
    public function maybe_upgrade()
    {
        $stored_version = (int) get_option(self::DBVC_CC_OPTION_SCHEMA_VERSION, 0);
        if ($stored_version >= self::DBVC_CC_SCHEMA_VERSION) {
            return;
        }

        $this->create_or_update_tables();
    }

    /**
     * @param string $suffix
     * @return string
     */
    public function table_name($suffix)
    {
        global $wpdb;

        $suffix = sanitize_key((string) $suffix);
        if ($suffix === 'runs') {
            return $wpdb->prefix . 'dbvc_cc_import_runs';
        }
        if ($suffix === 'actions') {
            return $wpdb->prefix . 'dbvc_cc_import_run_actions';
        }

        return $wpdb->prefix . 'dbvc_cc_import_' . $suffix;
    }

    /**
     * @param array<string, mixed> $data
     * @return int|WP_Error
     */
    public function create_run(array $data)
    {
        global $wpdb;

        $this->maybe_upgrade();

        $table = $this->table_name('runs');
        $defaults = [
            'run_uuid' => '',
            'approval_id' => '',
            'approval_token_hash' => '',
            'approval_context_fingerprint' => '',
            'domain' => '',
            'path' => '',
            'source_url' => '',
            'dry_run_execution_id' => '',
            'plan_id' => '',
            'graph_fingerprint' => '',
            'write_plan_id' => '',
            'status' => 'queued',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'approved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'rollback_started_at' => null,
            'rollback_finished_at' => null,
            'rollback_status' => 'not_started',
            'summary_json' => '',
            'trace_json' => '',
            'error_summary' => '',
        ];
        $payload = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $table,
            [
                'run_uuid' => sanitize_text_field((string) $payload['run_uuid']),
                'approval_id' => sanitize_text_field((string) $payload['approval_id']),
                'approval_token_hash' => sanitize_text_field((string) $payload['approval_token_hash']),
                'approval_context_fingerprint' => sanitize_text_field((string) $payload['approval_context_fingerprint']),
                'domain' => sanitize_text_field((string) $payload['domain']),
                'path' => sanitize_text_field((string) $payload['path']),
                'source_url' => esc_url_raw((string) $payload['source_url']),
                'dry_run_execution_id' => sanitize_text_field((string) $payload['dry_run_execution_id']),
                'plan_id' => sanitize_text_field((string) $payload['plan_id']),
                'graph_fingerprint' => sanitize_text_field((string) $payload['graph_fingerprint']),
                'write_plan_id' => sanitize_text_field((string) $payload['write_plan_id']),
                'status' => sanitize_key((string) $payload['status']),
                'created_by' => absint($payload['created_by']),
                'created_at' => sanitize_text_field((string) $payload['created_at']),
                'approved_at' => $this->dbvc_cc_nullable_datetime($payload['approved_at']),
                'started_at' => $this->dbvc_cc_nullable_datetime($payload['started_at']),
                'finished_at' => $this->dbvc_cc_nullable_datetime($payload['finished_at']),
                'rollback_started_at' => $this->dbvc_cc_nullable_datetime($payload['rollback_started_at']),
                'rollback_finished_at' => $this->dbvc_cc_nullable_datetime($payload['rollback_finished_at']),
                'rollback_status' => sanitize_key((string) $payload['rollback_status']),
                'summary_json' => $this->dbvc_cc_json_encode(isset($payload['summary_json']) ? $payload['summary_json'] : ''),
                'trace_json' => $this->dbvc_cc_json_encode(isset($payload['trace_json']) ? $payload['trace_json'] : ''),
                'error_summary' => sanitize_text_field((string) $payload['error_summary']),
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            ]
        );

        if ($result === false) {
            return new WP_Error(
                'dbvc_cc_import_run_create_failed',
                __('Could not create import run ledger.', 'dbvc'),
                ['status' => 500]
            );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @param int                  $run_id
     * @param array<string, mixed> $data
     * @return bool|WP_Error
     */
    public function update_run($run_id, array $data)
    {
        global $wpdb;

        $run_id = absint($run_id);
        if ($run_id <= 0) {
            return new WP_Error(
                'dbvc_cc_import_run_invalid',
                __('Import run ID is invalid.', 'dbvc'),
                ['status' => 400]
            );
        }

        $table = $this->table_name('runs');
        $update = [];
        $format = [];

        $map = [
            'approval_id' => '%s',
            'approval_token_hash' => '%s',
            'approval_context_fingerprint' => '%s',
            'domain' => '%s',
            'path' => '%s',
            'source_url' => '%s',
            'dry_run_execution_id' => '%s',
            'plan_id' => '%s',
            'graph_fingerprint' => '%s',
            'write_plan_id' => '%s',
            'status' => '%s',
            'created_by' => '%d',
            'created_at' => '%s',
            'approved_at' => '%s',
            'started_at' => '%s',
            'finished_at' => '%s',
            'rollback_started_at' => '%s',
            'rollback_finished_at' => '%s',
            'rollback_status' => '%s',
            'summary_json' => '%s',
            'trace_json' => '%s',
            'error_summary' => '%s',
        ];

        foreach ($map as $key => $db_format) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            if (strpos($key, '_at') !== false) {
                $update[$key] = $this->dbvc_cc_nullable_datetime($data[$key]);
            } elseif ($key === 'created_by') {
                $update[$key] = absint($data[$key]);
            } elseif ($key === 'source_url') {
                $update[$key] = esc_url_raw((string) $data[$key]);
            } elseif ($key === 'status' || $key === 'rollback_status') {
                $update[$key] = sanitize_key((string) $data[$key]);
            } elseif ($key === 'summary_json' || $key === 'trace_json') {
                $update[$key] = $this->dbvc_cc_json_encode($data[$key]);
            } else {
                $update[$key] = sanitize_text_field((string) $data[$key]);
            }
            $format[] = $db_format;
        }

        if (empty($update)) {
            return true;
        }

        $result = $wpdb->update($table, $update, ['id' => $run_id], $format, ['%d']);
        if ($result === false) {
            return new WP_Error(
                'dbvc_cc_import_run_update_failed',
                __('Could not update import run ledger.', 'dbvc'),
                ['status' => 500]
            );
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return int|WP_Error
     */
    public function create_action(array $data)
    {
        global $wpdb;

        $this->maybe_upgrade();

        $table = $this->table_name('actions');
        $defaults = [
            'run_id' => 0,
            'action_order' => 0,
            'stage' => '',
            'action_type' => '',
            'execution_status' => 'pending',
            'execution_error' => '',
            'target_object_type' => '',
            'target_object_id' => 0,
            'target_subtype' => '',
            'target_meta_key' => '',
            'before_state_json' => '',
            'after_state_json' => '',
            'rollback_status' => 'not_started',
            'rollback_error' => '',
            'created_at' => current_time('mysql', true),
        ];
        $payload = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $table,
            [
                'run_id' => absint($payload['run_id']),
                'action_order' => absint($payload['action_order']),
                'stage' => sanitize_key((string) $payload['stage']),
                'action_type' => sanitize_key((string) $payload['action_type']),
                'execution_status' => sanitize_key((string) $payload['execution_status']),
                'execution_error' => sanitize_text_field((string) $payload['execution_error']),
                'target_object_type' => sanitize_key((string) $payload['target_object_type']),
                'target_object_id' => absint($payload['target_object_id']),
                'target_subtype' => sanitize_key((string) $payload['target_subtype']),
                'target_meta_key' => sanitize_key((string) $payload['target_meta_key']),
                'before_state_json' => $this->dbvc_cc_json_encode(isset($payload['before_state_json']) ? $payload['before_state_json'] : ''),
                'after_state_json' => $this->dbvc_cc_json_encode(isset($payload['after_state_json']) ? $payload['after_state_json'] : ''),
                'rollback_status' => sanitize_key((string) $payload['rollback_status']),
                'rollback_error' => sanitize_text_field((string) $payload['rollback_error']),
                'created_at' => sanitize_text_field((string) $payload['created_at']),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            return new WP_Error(
                'dbvc_cc_import_action_create_failed',
                __('Could not create import action journal row.', 'dbvc'),
                ['status' => 500]
            );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @param int                  $action_id
     * @param array<string, mixed> $data
     * @return bool|WP_Error
     */
    public function update_action($action_id, array $data)
    {
        global $wpdb;

        $action_id = absint($action_id);
        if ($action_id <= 0) {
            return new WP_Error(
                'dbvc_cc_import_action_invalid',
                __('Import action ID is invalid.', 'dbvc'),
                ['status' => 400]
            );
        }

        $table = $this->table_name('actions');
        $update = [];
        $format = [];
        $map = [
            'action_order' => '%d',
            'stage' => '%s',
            'action_type' => '%s',
            'execution_status' => '%s',
            'execution_error' => '%s',
            'target_object_type' => '%s',
            'target_object_id' => '%d',
            'target_subtype' => '%s',
            'target_meta_key' => '%s',
            'before_state_json' => '%s',
            'after_state_json' => '%s',
            'rollback_status' => '%s',
            'rollback_error' => '%s',
            'created_at' => '%s',
        ];

        foreach ($map as $key => $db_format) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            if ($key === 'action_order' || $key === 'target_object_id') {
                $update[$key] = absint($data[$key]);
            } elseif ($key === 'before_state_json' || $key === 'after_state_json') {
                $update[$key] = $this->dbvc_cc_json_encode($data[$key]);
            } elseif ($key === 'stage' || $key === 'action_type' || $key === 'execution_status' || $key === 'target_object_type' || $key === 'target_subtype' || $key === 'target_meta_key' || $key === 'rollback_status') {
                $update[$key] = sanitize_key((string) $data[$key]);
            } else {
                $update[$key] = sanitize_text_field((string) $data[$key]);
            }
            $format[] = $db_format;
        }

        if (empty($update)) {
            return true;
        }

        $result = $wpdb->update($table, $update, ['id' => $action_id], $format, ['%d']);
        if ($result === false) {
            return new WP_Error(
                'dbvc_cc_import_action_update_failed',
                __('Could not update import action journal row.', 'dbvc'),
                ['status' => 500]
            );
        }

        return true;
    }

    /**
     * @param int|string $run_id_or_uuid
     * @return array<string, mixed>|null
     */
    public function get_run($run_id_or_uuid)
    {
        global $wpdb;

        $table = $this->table_name('runs');
        if (is_numeric($run_id_or_uuid)) {
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint($run_id_or_uuid)),
                ARRAY_A
            );
        } else {
            $run_uuid = sanitize_text_field((string) $run_id_or_uuid);
            if ($run_uuid === '') {
                return null;
            }
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE run_uuid = %s LIMIT 1", $run_uuid),
                ARRAY_A
            );
        }

        return is_array($row) ? $this->dbvc_cc_normalize_run_row($row) : null;
    }

    /**
     * @param int $run_id
     * @return array<int, array<string, mixed>>
     */
    public function get_run_actions($run_id)
    {
        global $wpdb;

        $run_id = absint($run_id);
        if ($run_id <= 0) {
            return [];
        }

        $table = $this->table_name('actions');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE run_id = %d ORDER BY action_order ASC, id ASC",
                $run_id
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'dbvc_cc_normalize_action_row'], $rows);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public function list_runs(array $args = [])
    {
        global $wpdb;

        $defaults = [
            'limit' => 20,
            'domain' => '',
            'path' => '',
        ];
        $args = wp_parse_args($args, $defaults);
        $table = $this->table_name('runs');
        $where = [];
        $params = [];

        if (! empty($args['domain'])) {
            $where[] = 'domain = %s';
            $params[] = sanitize_text_field((string) $args['domain']);
        }
        if (! empty($args['path'])) {
            $where[] = 'path = %s';
            $params[] = sanitize_text_field((string) $args['path']);
        }

        $sql = "SELECT * FROM {$table}";
        if (! empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, min(100, absint($args['limit'])));

        $query = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($query, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'dbvc_cc_normalize_run_row'], $rows);
    }

    /**
     * @return void
     */
    private function create_or_update_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $runs = $this->table_name('runs');
        $actions = $this->table_name('actions');

        dbDelta("CREATE TABLE {$runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_uuid varchar(64) NOT NULL,
            approval_id varchar(64) NOT NULL DEFAULT '',
            approval_token_hash varchar(64) NOT NULL DEFAULT '',
            approval_context_fingerprint varchar(64) NOT NULL DEFAULT '',
            domain varchar(191) NOT NULL DEFAULT '',
            path text,
            source_url text,
            dry_run_execution_id varchar(64) NOT NULL DEFAULT '',
            plan_id varchar(64) NOT NULL DEFAULT '',
            graph_fingerprint varchar(64) NOT NULL DEFAULT '',
            write_plan_id varchar(64) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL DEFAULT 'queued',
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            approved_at datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            finished_at datetime DEFAULT NULL,
            rollback_started_at datetime DEFAULT NULL,
            rollback_finished_at datetime DEFAULT NULL,
            rollback_status varchar(32) NOT NULL DEFAULT 'not_started',
            summary_json longtext,
            trace_json longtext,
            error_summary text,
            PRIMARY KEY  (id),
            UNIQUE KEY run_uuid (run_uuid),
            KEY domain_status (domain(191), status),
            KEY plan_lookup (plan_id, dry_run_execution_id),
            KEY rollback_status (rollback_status),
            KEY created_at (created_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$actions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            action_order int unsigned NOT NULL DEFAULT 0,
            stage varchar(32) NOT NULL DEFAULT '',
            action_type varchar(64) NOT NULL DEFAULT '',
            execution_status varchar(32) NOT NULL DEFAULT 'pending',
            execution_error text,
            target_object_type varchar(32) NOT NULL DEFAULT '',
            target_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_subtype varchar(64) NOT NULL DEFAULT '',
            target_meta_key varchar(191) NOT NULL DEFAULT '',
            before_state_json longtext,
            after_state_json longtext,
            rollback_status varchar(32) NOT NULL DEFAULT 'not_started',
            rollback_error text,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY run_order (run_id, action_order),
            KEY rollback_status (rollback_status),
            KEY target_lookup (target_object_type, target_object_id)
        ) {$charset_collate};");

        update_option(self::DBVC_CC_OPTION_SCHEMA_VERSION, (string) self::DBVC_CC_SCHEMA_VERSION);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function dbvc_cc_normalize_run_row(array $row)
    {
        return [
            'id' => isset($row['id']) ? absint($row['id']) : 0,
            'run_uuid' => isset($row['run_uuid']) ? sanitize_text_field((string) $row['run_uuid']) : '',
            'approval_id' => isset($row['approval_id']) ? sanitize_text_field((string) $row['approval_id']) : '',
            'approval_token_hash' => isset($row['approval_token_hash']) ? sanitize_text_field((string) $row['approval_token_hash']) : '',
            'approval_context_fingerprint' => isset($row['approval_context_fingerprint']) ? sanitize_text_field((string) $row['approval_context_fingerprint']) : '',
            'domain' => isset($row['domain']) ? sanitize_text_field((string) $row['domain']) : '',
            'path' => isset($row['path']) ? sanitize_text_field((string) $row['path']) : '',
            'source_url' => isset($row['source_url']) ? esc_url_raw((string) $row['source_url']) : '',
            'dry_run_execution_id' => isset($row['dry_run_execution_id']) ? sanitize_text_field((string) $row['dry_run_execution_id']) : '',
            'plan_id' => isset($row['plan_id']) ? sanitize_text_field((string) $row['plan_id']) : '',
            'graph_fingerprint' => isset($row['graph_fingerprint']) ? sanitize_text_field((string) $row['graph_fingerprint']) : '',
            'write_plan_id' => isset($row['write_plan_id']) ? sanitize_text_field((string) $row['write_plan_id']) : '',
            'status' => isset($row['status']) ? sanitize_key((string) $row['status']) : '',
            'created_by' => isset($row['created_by']) ? absint($row['created_by']) : 0,
            'created_at' => isset($row['created_at']) ? sanitize_text_field((string) $row['created_at']) : '',
            'approved_at' => isset($row['approved_at']) ? sanitize_text_field((string) $row['approved_at']) : '',
            'started_at' => isset($row['started_at']) ? sanitize_text_field((string) $row['started_at']) : '',
            'finished_at' => isset($row['finished_at']) ? sanitize_text_field((string) $row['finished_at']) : '',
            'rollback_started_at' => isset($row['rollback_started_at']) ? sanitize_text_field((string) $row['rollback_started_at']) : '',
            'rollback_finished_at' => isset($row['rollback_finished_at']) ? sanitize_text_field((string) $row['rollback_finished_at']) : '',
            'rollback_status' => isset($row['rollback_status']) ? sanitize_key((string) $row['rollback_status']) : '',
            'summary' => $this->dbvc_cc_json_decode(isset($row['summary_json']) ? $row['summary_json'] : ''),
            'trace' => $this->dbvc_cc_json_decode(isset($row['trace_json']) ? $row['trace_json'] : ''),
            'error_summary' => isset($row['error_summary']) ? sanitize_text_field((string) $row['error_summary']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function dbvc_cc_normalize_action_row(array $row)
    {
        return [
            'id' => isset($row['id']) ? absint($row['id']) : 0,
            'run_id' => isset($row['run_id']) ? absint($row['run_id']) : 0,
            'action_order' => isset($row['action_order']) ? absint($row['action_order']) : 0,
            'stage' => isset($row['stage']) ? sanitize_key((string) $row['stage']) : '',
            'action_type' => isset($row['action_type']) ? sanitize_key((string) $row['action_type']) : '',
            'execution_status' => isset($row['execution_status']) ? sanitize_key((string) $row['execution_status']) : '',
            'execution_error' => isset($row['execution_error']) ? sanitize_text_field((string) $row['execution_error']) : '',
            'target_object_type' => isset($row['target_object_type']) ? sanitize_key((string) $row['target_object_type']) : '',
            'target_object_id' => isset($row['target_object_id']) ? absint($row['target_object_id']) : 0,
            'target_subtype' => isset($row['target_subtype']) ? sanitize_key((string) $row['target_subtype']) : '',
            'target_meta_key' => isset($row['target_meta_key']) ? sanitize_key((string) $row['target_meta_key']) : '',
            'before_state' => $this->dbvc_cc_json_decode(isset($row['before_state_json']) ? $row['before_state_json'] : ''),
            'after_state' => $this->dbvc_cc_json_decode(isset($row['after_state_json']) ? $row['after_state_json'] : ''),
            'rollback_status' => isset($row['rollback_status']) ? sanitize_key((string) $row['rollback_status']) : '',
            'rollback_error' => isset($row['rollback_error']) ? sanitize_text_field((string) $row['rollback_error']) : '',
            'created_at' => isset($row['created_at']) ? sanitize_text_field((string) $row['created_at']) : '',
        ];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function dbvc_cc_json_encode($value)
    {
        if (is_string($value)) {
            return $value;
        }

        return wp_json_encode($value);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|array<int, mixed>|string
     */
    private function dbvc_cc_json_decode($value)
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return sanitize_text_field($value);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function dbvc_cc_nullable_datetime($value)
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }

        return sanitize_text_field($value);
    }
}
