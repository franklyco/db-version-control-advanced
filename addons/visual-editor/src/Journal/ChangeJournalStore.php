<?php

namespace Dbvc\VisualEditor\Journal;

final class ChangeJournalStore
{
    private const SCHEMA_VERSION = 1;
    private const OPTION_SCHEMA_VERSION = 'dbvc_visual_editor_journal_schema_version';

    /**
     * @return void
     */
    public function register()
    {
        add_action('plugins_loaded', [$this, 'maybeUpgrade'], 15);

        if (did_action('plugins_loaded')) {
            $this->maybeUpgrade();
        }
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('plugins_loaded', [$this, 'maybeUpgrade'], 15);
    }

    /**
     * @return void
     */
    public function maybeUpgrade()
    {
        $stored_version = (int) get_option(self::OPTION_SCHEMA_VERSION, 0);
        if ($stored_version >= self::SCHEMA_VERSION) {
            return;
        }

        $this->createOrUpdateTables();
    }

    /**
     * @param array<string, mixed> $data
     * @return int
     */
    public function startChangeSet(array $data)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $defaults = [
            'status' => 'pending',
            'scope_type' => 'current_entity',
            'page_entity_type' => '',
            'page_entity_id' => 0,
            'page_entity_subtype' => '',
            'owner_entity_type' => '',
            'owner_entity_id' => 0,
            'owner_entity_subtype' => '',
            'descriptor_token' => '',
            'snapshot_id' => 0,
            'initiated_by' => get_current_user_id() ?: null,
            'context' => [],
            'error_message' => '',
            'created_at' => current_time('mysql', true),
            'completed_at' => null,
        ];
        $payload = wp_parse_args($data, $defaults);

        $inserted = $wpdb->insert(
            $this->tableName('change_sets'),
            [
                'status' => sanitize_key((string) $payload['status']),
                'scope_type' => sanitize_key((string) $payload['scope_type']),
                'page_entity_type' => sanitize_key((string) $payload['page_entity_type']),
                'page_entity_id' => absint($payload['page_entity_id']),
                'page_entity_subtype' => sanitize_key((string) $payload['page_entity_subtype']),
                'owner_entity_type' => sanitize_key((string) $payload['owner_entity_type']),
                'owner_entity_id' => absint($payload['owner_entity_id']),
                'owner_entity_subtype' => sanitize_key((string) $payload['owner_entity_subtype']),
                'descriptor_token' => sanitize_key((string) $payload['descriptor_token']),
                'snapshot_id' => absint($payload['snapshot_id']),
                'initiated_by' => $payload['initiated_by'] ? absint($payload['initiated_by']) : null,
                'context_json' => $this->jsonEncode($payload['context']),
                'error_message' => sanitize_text_field((string) $payload['error_message']),
                'created_at' => sanitize_text_field((string) $payload['created_at']),
                'completed_at' => ! empty($payload['completed_at']) ? sanitize_text_field((string) $payload['completed_at']) : null,
            ]
        );

        if ($inserted === false) {
            return 0;
        }

        return absint($wpdb->insert_id);
    }

    /**
     * @param array<string, mixed> $data
     * @return int
     */
    public function recordChangeItem(array $data)
    {
        global $wpdb;

        $defaults = [
            'change_set_id' => 0,
            'resolver_name' => '',
            'field_type' => '',
            'field_name' => '',
            'field_key' => '',
            'field_path' => [],
            'old_value' => null,
            'new_value' => null,
            'rollback_value' => null,
            'result_status' => 'completed',
            'error_message' => '',
            'context' => [],
            'created_at' => current_time('mysql', true),
        ];
        $payload = wp_parse_args($data, $defaults);

        if (absint($payload['change_set_id']) <= 0) {
            return 0;
        }

        $inserted = $wpdb->insert(
            $this->tableName('change_items'),
            [
                'change_set_id' => absint($payload['change_set_id']),
                'resolver_name' => sanitize_key((string) $payload['resolver_name']),
                'field_type' => sanitize_key((string) $payload['field_type']),
                'field_name' => sanitize_key((string) $payload['field_name']),
                'field_key' => sanitize_key((string) $payload['field_key']),
                'field_path_json' => $this->jsonEncode($payload['field_path']),
                'old_value_json' => $this->jsonEncode($payload['old_value']),
                'new_value_json' => $this->jsonEncode($payload['new_value']),
                'rollback_value_json' => $this->jsonEncode($payload['rollback_value']),
                'result_status' => sanitize_key((string) $payload['result_status']),
                'error_message' => sanitize_text_field((string) $payload['error_message']),
                'context_json' => $this->jsonEncode($payload['context']),
                'created_at' => sanitize_text_field((string) $payload['created_at']),
            ]
        );

        if ($inserted === false) {
            return 0;
        }

        return absint($wpdb->insert_id);
    }

    /**
     * @param int    $change_set_id
     * @param string $status
     * @param int    $snapshot_id
     * @param string $error_message
     * @return bool
     */
    public function finishChangeSet($change_set_id, $status = 'completed', $snapshot_id = 0, $error_message = '')
    {
        global $wpdb;

        $change_set_id = absint($change_set_id);
        if ($change_set_id <= 0) {
            return false;
        }

        $updated = $wpdb->update(
            $this->tableName('change_sets'),
            [
                'status' => sanitize_key((string) $status),
                'snapshot_id' => absint($snapshot_id),
                'error_message' => sanitize_text_field((string) $error_message),
                'completed_at' => current_time('mysql', true),
            ],
            [
                'id' => $change_set_id,
            ]
        );

        return $updated !== false;
    }

    /**
     * @return void
     */
    private function createOrUpdateTables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $change_sets = $this->tableName('change_sets');
        $change_items = $this->tableName('change_items');

        dbDelta("CREATE TABLE {$change_sets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            status varchar(32) NOT NULL DEFAULT 'pending',
            scope_type varchar(32) NOT NULL DEFAULT 'current_entity',
            page_entity_type varchar(32) NOT NULL DEFAULT '',
            page_entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
            page_entity_subtype varchar(64) NOT NULL DEFAULT '',
            owner_entity_type varchar(32) NOT NULL DEFAULT '',
            owner_entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
            owner_entity_subtype varchar(64) NOT NULL DEFAULT '',
            descriptor_token varchar(64) NOT NULL DEFAULT '',
            snapshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
            initiated_by bigint(20) unsigned DEFAULT NULL,
            context_json longtext,
            error_message text,
            created_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status_created (status, created_at),
            KEY owner_lookup (owner_entity_type, owner_entity_id),
            KEY page_lookup (page_entity_type, page_entity_id),
            KEY descriptor_token (descriptor_token)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$change_items} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            change_set_id bigint(20) unsigned NOT NULL,
            resolver_name varchar(64) NOT NULL DEFAULT '',
            field_type varchar(64) NOT NULL DEFAULT '',
            field_name varchar(191) NOT NULL DEFAULT '',
            field_key varchar(191) NOT NULL DEFAULT '',
            field_path_json longtext,
            old_value_json longtext,
            new_value_json longtext,
            rollback_value_json longtext,
            result_status varchar(32) NOT NULL DEFAULT 'completed',
            error_message text,
            context_json longtext,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY change_set_id (change_set_id),
            KEY result_status (result_status),
            KEY field_name (field_name)
        ) {$charset_collate};");

        update_option(self::OPTION_SCHEMA_VERSION, (string) self::SCHEMA_VERSION);
    }

    /**
     * @param string $suffix
     * @return string
     */
    private function tableName($suffix)
    {
        global $wpdb;

        if ($suffix === 'change_sets') {
            return "{$wpdb->prefix}dbvc_ve_change_sets";
        }

        return "{$wpdb->prefix}dbvc_ve_change_items";
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function jsonEncode($value)
    {
        if (is_string($value)) {
            return $value;
        }

        return wp_json_encode($value);
    }
}
