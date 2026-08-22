<?php

namespace Dbvc\VisualEditor\MediaManager;

/**
 * R2-H Phase 1 (Slice 1) — durable Media Index storage.
 *
 * A per-entity summary of media-field completeness, backing the persistent,
 * cross-user Media Manager index. This is the working store only: it holds and
 * returns rows and never decides authority — eligibility/capability is re-checked
 * at read time for the requesting user by the read model (D-053). Per-field detail
 * remains computed live on expand, so this table stays a compact per-entity
 * summary. No hooks and no read/write wiring into the Media Manager are added by
 * this slice; those arrive in Slice 2+.
 */
final class MediaIndexStore
{
    private const SCHEMA_VERSION = 1;
    private const OPTION_SCHEMA_VERSION = 'dbvc_visual_editor_media_index_schema_version';
    private const OPTION_GENERATION = 'dbvc_visual_editor_media_index_generation';

    /**
     * @return void
     */
    public function register()
    {
        add_action('plugins_loaded', [$this, 'maybeUpgrade'], 16);

        if (did_action('plugins_loaded')) {
            $this->maybeUpgrade();
        }
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('plugins_loaded', [$this, 'maybeUpgrade'], 16);
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

        $this->createOrUpdateTable();
    }

    /**
     * The current index generation (a stable version string that changes when the
     * whole index is rebuilt, e.g. after an ACF field-group or exclusion change).
     *
     * @return string
     */
    public function currentGeneration()
    {
        $generation = sanitize_key((string) get_option(self::OPTION_GENERATION, ''));

        return $generation !== '' ? $generation : $this->rotateGeneration();
    }

    /**
     * Rotate to a fresh index generation and return it.
     *
     * @return string
     */
    public function rotateGeneration()
    {
        $generation = 'vmig_' . substr(hash('sha256', uniqid('dbvc_media_index', true) . wp_rand()), 0, 20);
        update_option(self::OPTION_GENERATION, $generation);

        return $generation;
    }

    /**
     * Insert or update the per-entity index row (keyed by entity identity).
     *
     * @param array<string, mixed> $row
     * @return int The affected row id (0 on failure).
     */
    public function upsertEntity(array $row)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $entity_type = sanitize_key((string) ($row['entity_type'] ?? ''));
        $entity_id = absint($row['entity_id'] ?? 0);
        $entity_subtype = sanitize_key((string) ($row['entity_subtype'] ?? ''));
        if ($entity_type === '' || $entity_id <= 0) {
            return 0;
        }

        $index_generation = sanitize_key((string) ($row['index_generation'] ?? $this->currentGeneration()));

        $data = [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'entity_subtype' => $entity_subtype,
            // A stable, opaque, non-guessable ref derived from the entity identity under
            // the index generation. Resolvable only via getByEntityRef(); a forged ref
            // matches no row. Any client-supplied entity_ref is ignored.
            'entity_ref' => $this->entityRef($entity_type, $entity_id, $entity_subtype, $index_generation),
            'label' => sanitize_text_field((string) ($row['label'] ?? '')),
            'frontend_url' => esc_url_raw((string) ($row['frontend_url'] ?? '')),
            'missing_count' => absint($row['missing_count'] ?? 0),
            'populated_count' => absint($row['populated_count'] ?? 0),
            'family_counts_json' => $this->jsonEncode($row['family_counts'] ?? []),
            'content_hash' => sanitize_text_field((string) ($row['content_hash'] ?? '')),
            'index_generation' => $index_generation,
            'indexed_at' => sanitize_text_field((string) ($row['indexed_at'] ?? current_time('mysql', true))),
            'is_dirty' => ! empty($row['is_dirty']) ? 1 : 0,
        ];

        $existing_id = $this->entityRowId($entity_type, $entity_id, $entity_subtype);
        if ($existing_id > 0) {
            $updated = $wpdb->update($this->tableName(), $data, ['id' => $existing_id]);

            return $updated === false ? 0 : $existing_id;
        }

        $inserted = $wpdb->insert($this->tableName(), $data);

        return $inserted === false ? 0 : absint($wpdb->insert_id);
    }

    /**
     * @param string $entity_type
     * @param int    $entity_id
     * @param string $entity_subtype
     * @return array<string, mixed>|null
     */
    public function getEntity($entity_type, $entity_id, $entity_subtype)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $found = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE entity_type = %s AND entity_id = %d AND entity_subtype = %s LIMIT 1",
                sanitize_key((string) $entity_type),
                absint($entity_id),
                sanitize_key((string) $entity_subtype)
            ),
            ARRAY_A
        );

        return is_array($found) ? $this->hydrate($found) : null;
    }

    /**
     * Resolve a row by its opaque entity_ref (the addressable list identifier). Returns
     * null for a forged/stale ref that matches no row.
     *
     * @param string $entity_ref
     * @return array<string, mixed>|null
     */
    public function getByEntityRef($entity_ref)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $entity_ref = sanitize_key((string) $entity_ref);
        if (! preg_match('/^vemx_[a-f0-9]{24}$/', $entity_ref)) {
            return null;
        }

        $found = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->tableName()} WHERE entity_ref = %s LIMIT 1",
                $entity_ref
            ),
            ARRAY_A
        );

        return is_array($found) ? $this->hydrate($found) : null;
    }

    /**
     * The stable opaque ref for an entity identity under an index generation.
     *
     * @param string $entity_type
     * @param int    $entity_id
     * @param string $entity_subtype
     * @param string $generation
     * @return string
     */
    private function entityRef($entity_type, $entity_id, $entity_subtype, $generation)
    {
        $seed = sanitize_key((string) $entity_type) . '|' . absint($entity_id) . '|' . sanitize_key((string) $entity_subtype);

        return 'vemx_' . substr(hash_hmac('sha256', $seed, wp_salt('auth') . '|' . sanitize_key((string) $generation)), 0, 24);
    }

    /**
     * List index rows, newest-indexed first.
     *
     * @param array<string, mixed> $args Keys: generation, onlyMissing (bool),
     *                                    onlyDirty (bool), limit, offset.
     * @return array<int, array<string, mixed>>
     */
    public function listEntities(array $args = [])
    {
        global $wpdb;

        $this->maybeUpgrade();

        [$where, $params] = $this->buildFilter($args);
        $limit = isset($args['limit']) ? max(1, min(500, absint($args['limit']))) : 50;
        $offset = isset($args['offset']) ? max(0, absint($args['offset'])) : 0;
        $order_by = $this->orderByClause(isset($args['sort']) ? (string) $args['sort'] : '');

        $sql = "SELECT * FROM {$this->tableName()} {$where} {$order_by} LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @param array<string, mixed> $args See listEntities().
     * @return int
     */
    public function countEntities(array $args = [])
    {
        global $wpdb;

        $this->maybeUpgrade();

        [$where, $params] = $this->buildFilter($args);
        $sql = "SELECT COUNT(*) FROM {$this->tableName()} {$where}";

        return (int) $wpdb->get_var(empty($params) ? $sql : $wpdb->prepare($sql, $params));
    }

    /**
     * @param string $entity_type
     * @param int    $entity_id
     * @param string $entity_subtype
     * @return int Affected rows.
     */
    public function markDirty($entity_type, $entity_id, $entity_subtype)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $updated = $wpdb->update(
            $this->tableName(),
            ['is_dirty' => 1],
            [
                'entity_type' => sanitize_key((string) $entity_type),
                'entity_id' => absint($entity_id),
                'entity_subtype' => sanitize_key((string) $entity_subtype),
            ]
        );

        return $updated === false ? 0 : (int) $updated;
    }

    /**
     * Mark every row in a generation dirty (used when field topology changes).
     *
     * @param string $generation
     * @return int Affected rows.
     */
    public function markGenerationDirty($generation)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $updated = $wpdb->update(
            $this->tableName(),
            ['is_dirty' => 1],
            ['index_generation' => sanitize_key((string) $generation)]
        );

        return $updated === false ? 0 : (int) $updated;
    }

    /**
     * @param string $entity_type
     * @param int    $entity_id
     * @param string $entity_subtype
     * @return int Affected rows.
     */
    public function deleteEntity($entity_type, $entity_id, $entity_subtype)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $deleted = $wpdb->delete(
            $this->tableName(),
            [
                'entity_type' => sanitize_key((string) $entity_type),
                'entity_id' => absint($entity_id),
                'entity_subtype' => sanitize_key((string) $entity_subtype),
            ]
        );

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * Delete by entity type + id regardless of subtype (post/term ids are unique), for
     * hook paths that no longer have the subtype (e.g. a hard-deleted post).
     *
     * @param string $entity_type
     * @param int    $entity_id
     * @return int Affected rows.
     */
    public function deleteEntityById($entity_type, $entity_id)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $deleted = $wpdb->delete(
            $this->tableName(),
            [
                'entity_type' => sanitize_key((string) $entity_type),
                'entity_id' => absint($entity_id),
            ]
        );

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * Remove every row that does not belong to the given generation.
     *
     * @param string $generation
     * @return int Affected rows.
     */
    public function pruneOtherGenerations($generation)
    {
        global $wpdb;

        $this->maybeUpgrade();

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->tableName()} WHERE index_generation <> %s",
                sanitize_key((string) $generation)
            )
        );

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * @return int Affected rows.
     */
    public function deleteAll()
    {
        global $wpdb;

        $this->maybeUpgrade();

        $deleted = $wpdb->query("DELETE FROM {$this->tableName()}");

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildFilter(array $args)
    {
        global $wpdb;

        $clauses = [];
        $params = [];

        if (! empty($args['generation'])) {
            $clauses[] = 'index_generation = %s';
            $params[] = sanitize_key((string) $args['generation']);
        }
        if (! empty($args['onlyMissing'])) {
            $clauses[] = 'missing_count > 0';
        }
        if (! empty($args['onlyDirty'])) {
            $clauses[] = 'is_dirty = 1';
        }

        // Optional label search (index parity with the ephemeral scan list). The term
        // is escaped for LIKE and bounded; matching is a case-insensitive substring.
        $search = isset($args['search']) ? sanitize_text_field((string) $args['search']) : '';
        if ($search !== '') {
            $clauses[] = 'label LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Entity-family filter (post|term). Any other value is ignored, matching the
        // ephemeral scan list's "all" default.
        $entity_family = isset($args['entityFamily']) ? sanitize_key((string) $args['entityFamily']) : '';
        if (in_array($entity_family, ['post', 'term'], true)) {
            $clauses[] = 'entity_type = %s';
            $params[] = $entity_family;
        }

        $where = empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**
     * Map a sort key to a safe ORDER BY clause (whitelisted columns only).
     *
     * @param string $sort
     * @return string
     */
    private function orderByClause($sort)
    {
        $map = [
            'entity_asc' => 'label ASC, id ASC',
            'entity_desc' => 'label DESC, id DESC',
            'missing_asc' => 'missing_count ASC, id ASC',
            'missing_desc' => 'missing_count DESC, id DESC',
            'scanned_asc' => 'indexed_at ASC, id ASC',
            'scanned_desc' => 'indexed_at DESC, id DESC',
        ];
        $key = sanitize_key((string) $sort);

        return 'ORDER BY ' . ($map[$key] ?? 'indexed_at DESC, id DESC');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row)
    {
        return [
            'id' => absint($row['id'] ?? 0),
            'entity_type' => sanitize_key((string) ($row['entity_type'] ?? '')),
            'entity_id' => absint($row['entity_id'] ?? 0),
            'entity_subtype' => sanitize_key((string) ($row['entity_subtype'] ?? '')),
            'entity_ref' => (string) ($row['entity_ref'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'frontend_url' => (string) ($row['frontend_url'] ?? ''),
            'missing_count' => absint($row['missing_count'] ?? 0),
            'populated_count' => absint($row['populated_count'] ?? 0),
            'family_counts' => $this->jsonDecode((string) ($row['family_counts_json'] ?? '')),
            'content_hash' => (string) ($row['content_hash'] ?? ''),
            'index_generation' => sanitize_key((string) ($row['index_generation'] ?? '')),
            'indexed_at' => (string) ($row['indexed_at'] ?? ''),
            'is_dirty' => ! empty($row['is_dirty']),
        ];
    }

    /**
     * @param string $entity_type
     * @param int    $entity_id
     * @param string $entity_subtype
     * @return int
     */
    private function entityRowId($entity_type, $entity_id, $entity_subtype)
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->tableName()} WHERE entity_type = %s AND entity_id = %d AND entity_subtype = %s LIMIT 1",
                sanitize_key((string) $entity_type),
                absint($entity_id),
                sanitize_key((string) $entity_subtype)
            )
        );
    }

    /**
     * @return void
     */
    private function createOrUpdateTable()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table = $this->tableName();

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(32) NOT NULL DEFAULT '',
            entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
            entity_subtype varchar(64) NOT NULL DEFAULT '',
            entity_ref varchar(64) NOT NULL DEFAULT '',
            label varchar(191) NOT NULL DEFAULT '',
            frontend_url text,
            missing_count int(10) unsigned NOT NULL DEFAULT 0,
            populated_count int(10) unsigned NOT NULL DEFAULT 0,
            family_counts_json longtext,
            content_hash varchar(64) NOT NULL DEFAULT '',
            index_generation varchar(64) NOT NULL DEFAULT '',
            indexed_at datetime NOT NULL,
            is_dirty tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY entity_identity (entity_type, entity_id, entity_subtype),
            KEY dirty (is_dirty),
            KEY generation (index_generation),
            KEY missing (missing_count)
        ) {$charset_collate};");

        update_option(self::OPTION_SCHEMA_VERSION, (string) self::SCHEMA_VERSION);
    }

    /**
     * @return string
     */
    private function tableName()
    {
        global $wpdb;

        // Named `media_field_index` (not `media_index`) to avoid confusion with the
        // core DBVC `dbvc_media_index` attachment-file table — this indexes the media
        // *field* completeness of entities, not media files.
        return "{$wpdb->prefix}dbvc_ve_media_field_index";
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function jsonEncode($value)
    {
        $encoded = wp_json_encode($value);

        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * @param string $json
     * @return array<string, mixed>
     */
    private function jsonDecode($json)
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
