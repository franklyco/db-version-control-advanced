<?php

/**
 * DBVC Database layer.
 *
 * Handles custom tables for snapshots, media index, jobs, and structured logs.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Database')) {
    class DBVC_Database
    {
        const SCHEMA_VERSION = 3;
        const OPTION_SCHEMA_VERSION = 'dbvc_schema_version';

        /**
         * Initialize upgrade checks.
         *
         * @return void
         */
        public static function init()
        {
            add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade'], 5);
        }

        /**
         * Run on plugin activation to create/update tables.
         *
         * @return void
         */
        public static function activate()
        {
            self::create_or_update_tables();
        }

        /**
         * Conditionally upgrade schema when version bumps.
         *
         * @return void
         */
        public static function maybe_upgrade()
        {
            $stored = (int) get_option(self::OPTION_SCHEMA_VERSION, 0);
            if ($stored >= self::SCHEMA_VERSION) {
                return;
            }

            self::create_or_update_tables();
        }

        /**
         * Create tables via dbDelta and persist schema version.
         *
         * @return void
         */
        private static function create_or_update_tables()
        {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset_collate = $wpdb->get_charset_collate();

            $snapshots = self::table_name('snapshots');
            $snapshot_items = self::table_name('snapshot_items');
            $entities = self::table_name('entities');
            $media_index = self::table_name('media_index');
            $jobs = self::table_name('jobs');
            $activity = self::table_name('activity_log');
            $collections = self::table_name('collections');
            $collection_items = self::table_name('collection_items');

            $sql = [];

            $sql[] = "CREATE TABLE {$snapshots} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                initiated_by bigint(20) unsigned DEFAULT NULL,
                type varchar(32) NOT NULL,
                sync_path text,
                bundle_hash varchar(64) DEFAULT NULL,
                notes longtext,
                PRIMARY KEY  (id),
                KEY type_created (type, created_at),
                KEY initiated_by (initiated_by)
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$snapshot_items} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                snapshot_id bigint(20) unsigned NOT NULL,
                object_type varchar(32) NOT NULL,
                object_id bigint(20) unsigned NOT NULL DEFAULT 0,
                entity_uid varchar(64) DEFAULT NULL,
                content_hash varchar(64) NOT NULL,
                media_hash varchar(64) DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'created',
                payload_path text,
                exported_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY snapshot_id (snapshot_id),
                KEY object_lookup (object_type, object_id),
                KEY entity_uid (entity_uid),
                KEY content_hash (content_hash)
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$entities} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                entity_uid varchar(64) NOT NULL,
                object_type varchar(64) NOT NULL,
                object_id bigint(20) unsigned DEFAULT NULL,
                object_status varchar(20) DEFAULT NULL,
                last_seen datetime DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY entity_uid (entity_uid),
                KEY object_lookup (object_type, object_id)
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$media_index} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                attachment_id bigint(20) unsigned DEFAULT NULL,
                original_id bigint(20) unsigned DEFAULT NULL,
                source_url text,
                relative_path text,
                file_hash varchar(64) DEFAULT NULL,
                file_size bigint(20) unsigned DEFAULT NULL,
                mime_type varchar(100) DEFAULT NULL,
                last_seen datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY original_id (original_id),
                KEY file_hash (file_hash),
                KEY relative_path (relative_path(191))
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$jobs} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_type varchar(32) NOT NULL,
                status varchar(20) NOT NULL,
                context longtext,
                progress float DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY job_type_status (job_type, status),
                KEY updated_at (updated_at)
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$activity} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event varchar(64) NOT NULL,
                severity varchar(20) NOT NULL DEFAULT 'info',
                message text,
                context longtext,
                user_id bigint(20) unsigned DEFAULT NULL,
                job_id bigint(20) unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY severity (severity),
                KEY created_at (created_at),
                KEY job_id (job_id)
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$collections} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                proposal_id varchar(191) NOT NULL DEFAULT '',
                title varchar(191) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'draft',
                notes longtext NULL,
                manifest_path text,
                archive_path text,
                checksum varchar(64) DEFAULT NULL,
                entities_count int unsigned NOT NULL DEFAULT 0,
                media_count int unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                created_by bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY proposal_status (proposal_id, status),
                KEY created_at (created_at)
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$collection_items} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                collection_id bigint(20) unsigned NOT NULL,
                entity_uid varchar(64) NOT NULL,
                entity_type varchar(32) NOT NULL DEFAULT 'post',
                entity_label varchar(191) DEFAULT NULL,
                decision varchar(20) NOT NULL DEFAULT 'accept',
                snapshot_path text,
                payload longtext,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY collection_entity (collection_id, entity_uid),
                KEY entity_uid (entity_uid)
            ) {$charset_collate};";

            foreach ($sql as $statement) {
                dbDelta($statement);
            }

            update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION);
        }

        /**
         * Convenience helper to get full table name.
         *
         * @param string $suffix
         * @return string
         */
        public static function table_name($suffix)
        {
            global $wpdb;
            $map = [
                'snapshots'      => "{$wpdb->prefix}dbvc_snapshots",
                'snapshot_items' => "{$wpdb->prefix}dbvc_snapshot_items",
                'media_index'    => "{$wpdb->prefix}dbvc_media_index",
                'jobs'           => "{$wpdb->prefix}dbvc_jobs",
                'activity_log'   => "{$wpdb->prefix}dbvc_activity_log",
                'entities'       => "{$wpdb->prefix}dbvc_entities",
                'collections'    => "{$wpdb->prefix}dbvc_collections",
                'collection_items' => "{$wpdb->prefix}dbvc_collection_items",
            ];

            return $map[$suffix] ?? "{$wpdb->prefix}dbvc_{$suffix}";
        }

        /**
         * Insert a snapshot record.
         *
         * @param array $data
         * @return int Snapshot ID.
         */
        public static function insert_snapshot(array $data)
        {
            global $wpdb;

            $defaults = [
                'name'         => '',
                'created_at'   => current_time('mysql', true),
                'initiated_by' => get_current_user_id() ?: null,
                'type'         => '',
                'sync_path'    => '',
                'bundle_hash'  => null,
                'notes'        => null,
            ];

            $payload = wp_parse_args($data, $defaults);

            $wpdb->insert(
                self::table_name('snapshots'),
                [
                    'name'         => $payload['name'],
                    'created_at'   => $payload['created_at'],
                    'initiated_by' => $payload['initiated_by'],
                    'type'         => $payload['type'],
                    'sync_path'    => $payload['sync_path'],
                    'bundle_hash'  => $payload['bundle_hash'],
                    'notes'        => $payload['notes'],
                ],
                [
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                ]
            );

            return (int) $wpdb->insert_id;
        }

        /**
         * Bulk insert snapshot items.
         *
         * @param int   $snapshot_id
         * @param array $items
         * @return void
         */
        public static function insert_snapshot_items($snapshot_id, array $items)
        {
            global $wpdb;
            $table = self::table_name('snapshot_items');

            foreach ($items as $item) {
                $payload = wp_parse_args($item, [
                    'object_type'  => '',
                    'object_id'    => 0,
                    'entity_uid'   => '',
                    'content_hash' => '',
                    'media_hash'   => null,
                    'status'       => 'created',
                    'payload_path' => '',
                    'exported_at'  => current_time('mysql', true),
                ]);

                $wpdb->insert(
                    $table,
                    [
                        'snapshot_id'  => $snapshot_id,
                        'object_type'  => $payload['object_type'],
                        'object_id'    => $payload['object_id'],
                        'entity_uid'   => $payload['entity_uid'],
                        'content_hash' => $payload['content_hash'],
                        'media_hash'   => $payload['media_hash'],
                        'status'       => $payload['status'],
                        'payload_path' => $payload['payload_path'],
                        'exported_at'  => $payload['exported_at'],
                    ],
                    [
                        '%d',
                        '%s',
                        '%d',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                    ]
                );
            }
        }

        /**
         * Upsert media index entry based on original ID or hash.
         *
         * @param array $data
         * @return void
         */
        public static function upsert_media(array $data)
        {
            global $wpdb;

            $defaults = [
                'attachment_id' => null,
                'original_id'   => null,
                'source_url'    => null,
                'relative_path' => null,
                'file_hash'     => null,
                'file_size'     => null,
                'mime_type'     => null,
                'last_seen'     => current_time('mysql', true),
            ];

            $payload = wp_parse_args($data, $defaults);

            $table = self::table_name('media_index');

            $where = null;
            if (! empty($payload['original_id'])) {
                $where = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE original_id = %d",
                        $payload['original_id']
                    )
                );
            } elseif (! empty($payload['file_hash'])) {
                $where = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE file_hash = %s",
                        $payload['file_hash']
                    )
                );
            }

            $data_to_write = [
                'attachment_id' => $payload['attachment_id'],
                'original_id'   => $payload['original_id'],
                'source_url'    => $payload['source_url'],
                'relative_path' => $payload['relative_path'],
                'file_hash'     => $payload['file_hash'],
                'file_size'     => $payload['file_size'],
                'mime_type'     => $payload['mime_type'],
                'last_seen'     => $payload['last_seen'],
            ];

            if ($where) {
                $wpdb->update(
                    $table,
                    $data_to_write,
                    ['id' => $where->id],
                    ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s'],
                    ['%d']
                );
            } else {
                $wpdb->insert(
                    $table,
                    $data_to_write,
                    ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
                );
            }
        }

        /**
         * Upsert entity registry entry keyed by UID.
         *
         * @param array $data
         * @return void
         */
        public static function upsert_entity(array $data)
        {
            global $wpdb;

            $defaults = [
                'entity_uid'   => '',
                'object_type'  => '',
                'object_id'    => null,
                'object_status'=> null,
                'last_seen'    => current_time('mysql', true),
            ];

            $payload = wp_parse_args($data, $defaults);
            $entity_uid = trim((string) $payload['entity_uid']);
            if ($entity_uid === '') {
                return;
            }

            $table = self::table_name('entities');

            $wpdb->replace(
                $table,
                [
                    'entity_uid'   => $entity_uid,
                    'object_type'  => sanitize_key($payload['object_type']),
                    'object_id'    => $payload['object_id'] ? (int) $payload['object_id'] : null,
                    'object_status'=> $payload['object_status'] ? sanitize_key($payload['object_status']) : null,
                    'last_seen'    => $payload['last_seen'],
                ],
                [
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                    '%s',
                ]
            );
        }

        /**
         * Retrieve an entity registry row by UID.
         *
         * @param string $entity_uid
         * @return object|null
         */
        public static function get_entity_by_uid($entity_uid)
        {
            global $wpdb;
            $entity_uid = trim((string) $entity_uid);
            if ($entity_uid === '') {
                return null;
            }

            $table = self::table_name('entities');

            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE entity_uid = %s LIMIT 1",
                    $entity_uid
                )
            );
        }

        /**
         * Create a tracked job entry.
         *
         * @param string $type
         * @param array  $context
         * @param string $status
         * @return int Job ID.
         */
        public static function create_job($type, array $context = [], $status = 'pending')
        {
            global $wpdb;

            $table = self::table_name('jobs');
            $now   = current_time('mysql', true);

            $wpdb->insert(
                $table,
                [
                    'job_type'   => sanitize_key($type),
                    'status'     => sanitize_key($status),
                    'context'    => $context ? wp_json_encode($context) : null,
                    'progress'   => isset($context['progress']) ? (float) $context['progress'] : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    '%s',
                    '%s',
                    '%s',
                    '%f',
                    '%s',
                    '%s',
                ]
            );

            return (int) $wpdb->insert_id;
        }

        /**
         * Update an existing job entry.
         *
         * @param int        $job_id
         * @param array      $fields
         * @param array|null $context
         * @return void
         */
        public static function update_job($job_id, array $fields = [], $context = null)
        {
            global $wpdb;

            $job_id = (int) $job_id;
            if (! $job_id) {
                return;
            }

            $table  = self::table_name('jobs');
            $data   = [];
            $format = [];

            if (isset($fields['job_type'])) {
                $data['job_type'] = sanitize_key($fields['job_type']);
                $format[] = '%s';
            }

            if (isset($fields['status'])) {
                $data['status'] = sanitize_key($fields['status']);
                $format[] = '%s';
            }

            if (isset($fields['progress'])) {
                $data['progress'] = (float) $fields['progress'];
                $format[] = '%f';
            }

            if ($context !== null) {
                $data['context'] = wp_json_encode($context);
                $format[]        = '%s';
            }

            $data['updated_at'] = current_time('mysql', true);
            $format[] = '%s';

            if (empty($data)) {
                return;
            }

            $wpdb->update(
                $table,
                $data,
                ['id' => $job_id],
                $format,
                ['%d']
            );
        }

        /**
         * Fetch a job row.
         *
         * @param int $job_id
         * @return object|null
         */
        public static function get_job($job_id)
        {
            global $wpdb;

            $job_id = (int) $job_id;
            if (! $job_id) {
                return null;
            }

            $table = self::table_name('jobs');

            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $job_id)
            );
        }

        /**
         * Retrieve a snapshot row.
         *
         * @param int $snapshot_id
         * @return object|null
         */
        public static function get_snapshot($snapshot_id)
        {
            global $wpdb;

            if (! $snapshot_id) {
                return null;
            }

            $table = self::table_name('snapshots');
            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $snapshot_id)
            );
        }

        /**
         * Fetch the most recent snapshot for a given type.
         *
         * @param string $type
         * @return object|null
         */
        public static function get_latest_snapshot($type = 'full_export')
        {
            global $wpdb;
            $table = self::table_name('snapshots');

            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE type = %s ORDER BY created_at DESC, id DESC LIMIT 1",
                    $type
                )
            );
        }

        /**
         * Return a map of object_id => content_hash for a snapshot.
         *
         * @param int    $snapshot_id
         * @param string $object_type
         * @return array
         */
        public static function get_snapshot_item_hashes($snapshot_id, $object_type = 'post')
        {
            global $wpdb;
            if (! $snapshot_id) {
                return [];
            }

            $table = self::table_name('snapshot_items');

            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT object_id, content_hash FROM {$table} WHERE snapshot_id = %d AND object_type = %s",
                    $snapshot_id,
                    $object_type
                )
            );

            if (empty($results)) {
                return [];
            }

            $map = [];
            foreach ($results as $row) {
                $map[(int) $row->object_id] = (string) $row->content_hash;
            }
            return $map;
        }

        /**
         * Fetch snapshots with optional filters.
         *
         * @param array $args
         * @return array
         */
        public static function get_snapshots(array $args = [])
        {
            global $wpdb;

            $defaults = [
                'type'   => '',
                'limit'  => 20,
                'offset' => 0,
            ];

            $args = wp_parse_args($args, $defaults);

            $limit  = max(1, (int) $args['limit']);
            $offset = max(0, (int) $args['offset']);
            $type   = sanitize_key($args['type']);

            $table  = self::table_name('snapshots');
            $sql    = "SELECT * FROM {$table}";
            $params = [];

            if (! empty($type)) {
                $sql     .= " WHERE type = %s";
                $params[] = $type;
            }

            $sql     .= " ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
            $params[] = $limit;
            $params[] = $offset;

            if (! empty($params)) {
                $prepared = $wpdb->prepare($sql, $params);
            } else {
                $prepared = $sql;
            }

            return $wpdb->get_results($prepared);
        }

        /**
         * Retrieve jobs with optional filters.
         *
         * @param array $args
         * @return array
         */
        public static function get_jobs(array $args = [])
        {
            global $wpdb;

            $defaults = [
                'type'    => '',
                'status'  => '',
                'limit'   => 20,
                'offset'  => 0,
                'order'   => 'DESC',
            ];

            $args = wp_parse_args($args, $defaults);

            $limit   = max(1, (int) $args['limit']);
            $offset  = max(0, (int) $args['offset']);
            $order   = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
            $type    = sanitize_key($args['type']);
            $status  = sanitize_key($args['status']);

            $table = self::table_name('jobs');
            $where = [];
            $params = [];

            if ($type) {
                $where[]  = 'job_type = %s';
                $params[] = $type;
            }

            if ($status) {
                $where[]  = 'status = %s';
                $params[] = $status;
            }

            $sql = "SELECT * FROM {$table}";
            if (! empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= " ORDER BY updated_at {$order}, id {$order} LIMIT %d OFFSET %d";
            $params[] = $limit;
            $params[] = $offset;

            $prepared = $wpdb->prepare($sql, $params);
            return $wpdb->get_results($prepared);
        }

        /**
         * Insert structured activity log.
         *
         * @param string $event
         * @param string $severity
         * @param string $message
         * @param array  $context
         * @param array  $meta
         * @return void
         */
        public static function log_activity($event, $severity = 'info', $message = '', array $context = [], array $meta = [])
        {
            global $wpdb;

            $table = self::table_name('activity_log');
            $wpdb->insert(
                $table,
                [
                    'event'      => $event,
                    'severity'   => $severity,
                    'message'    => $message,
                    'context'    => $context ? wp_json_encode($context) : null,
                    'user_id'    => isset($meta['user_id']) ? (int) $meta['user_id'] : (get_current_user_id() ?: null),
                    'job_id'     => isset($meta['job_id']) ? (int) $meta['job_id'] : null,
                    'created_at' => current_time('mysql', true),
                ],
                [
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%s',
                ]
            );
        }
    }
}
