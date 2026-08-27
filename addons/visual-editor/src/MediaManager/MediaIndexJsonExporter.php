<?php

namespace Dbvc\VisualEditor\MediaManager;

/**
 * R2-H Phase 1 (Slice 5) — derived JSON mirror of the durable Media Index.
 *
 * The custom table {@see MediaIndexStore} is the source of truth. This exporter writes
 * a derived JSON mirror into the DBVC sync folder so a backup that captures
 * `wp-content/plugins/db-version-control-main/sync/` (or the site's custom sync path)
 * round-trips the index — the table can be dropped, restored empty, and re-hydrated
 * from the JSON without waiting for the background builder.
 *
 * Only the SERVING generation is exported (rebuilds-in-flight are internal state and
 * must not be preserved across a restore). The envelope carries the generation so
 * the importer can set the serving pointer to the same opaque value and mark the
 * builder complete for that generation — no re-drain on restore.
 *
 * Export is called from the completion boundaries (rebuild swap, first-run build
 * completion, reconcile chunks that modified rows). It never fires per-invalidator
 * so incremental edits do not thrash the file; the next completion sweep captures
 * them.
 *
 * Import is guarded: it runs only when the table is empty AND the JSON file exists,
 * so a fresh restore is hydrated automatically but a populated table (the normal
 * runtime case) is never overwritten from the mirror.
 */
final class MediaIndexJsonExporter
{
    private const SCHEMA_VERSION = 1;
    private const SYNC_SUBFOLDER = 'visual-editor';
    private const FILE_NAME = 'media-index.json';

    /**
     * @var MediaIndexStore
     */
    private $store;

    /**
     * @var MediaIndexBuilder|null
     */
    private $builder;

    /**
     * @var string
     */
    private $override_sync_base;

    /**
     * @param MediaIndexStore        $store
     * @param MediaIndexBuilder|null $builder            Optional; used by import to mark the
     *                                                    builder complete so the drain does not
     *                                                    re-fire immediately after a restore.
     * @param string                 $override_sync_base Optional; test seam that overrides the
     *                                                    default `dbvc_get_sync_path()` root.
     */
    public function __construct(
        MediaIndexStore $store,
        ?MediaIndexBuilder $builder = null,
        $override_sync_base = ''
    ) {
        $this->store = $store;
        $this->builder = $builder;
        $this->override_sync_base = (string) $override_sync_base;
    }

    /**
     * Export the current serving generation to the derived JSON mirror. Returns the
     * absolute file path on success, or an empty string on any failure (missing sync
     * dir, encode error, unsafe path, write error).
     *
     * @return string
     */
    public function exportAll()
    {
        $target_dir = $this->targetDir();
        if ($target_dir === '' || ! $this->ensureDirectory($target_dir)) {
            return '';
        }

        $file_path = $this->filePath($target_dir);
        if ($file_path === '' || ! $this->isSafeFilePath($file_path)) {
            return '';
        }

        $generation = $this->store->currentGeneration();
        $rows = $this->collectServingRows($generation);

        $envelope = [
            'schema' => self::SCHEMA_VERSION,
            'exported_at' => current_time('mysql', true),
            'source' => [
                'entity' => 'visual_editor_media_index',
                'plugin' => 'db-version-control',
            ],
            'generation' => $generation,
            'count' => count($rows),
            'entities' => $rows,
        ];

        $json = wp_json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return '';
        }

        $written = @file_put_contents($file_path, $json);
        if ($written === false) {
            return '';
        }

        /**
         * Fires after the derived Media Index JSON mirror has been written.
         *
         * @param string $file_path Absolute path to the written file.
         * @param int    $count     Number of serving-generation entities written.
         */
        do_action('dbvc_visual_editor_media_index_exported', $file_path, count($rows));

        return $file_path;
    }

    /**
     * Guarded restore path: when the table is empty AND the JSON mirror exists,
     * upsert every row from the file under the file's generation, set the serving
     * pointer to that generation, and mark the builder complete for it so the
     * drain does not re-fire.
     *
     * A no-op when the table already carries rows (the normal runtime state) or
     * when the mirror file is missing/unreadable/malformed.
     *
     * @return int Rows imported (0 on any no-op / failure).
     */
    public function importIfEmpty()
    {
        if ($this->store->countEntities() > 0) {
            return 0;
        }

        $target_dir = $this->targetDir();
        if ($target_dir === '') {
            return 0;
        }

        $file_path = $this->filePath($target_dir);
        if ($file_path === '' || ! file_exists($file_path) || ! is_readable($file_path)) {
            return 0;
        }

        $raw = @file_get_contents($file_path);
        if (! is_string($raw) || $raw === '') {
            return 0;
        }

        $envelope = json_decode($raw, true);
        if (! is_array($envelope) || (int) ($envelope['schema'] ?? 0) !== self::SCHEMA_VERSION) {
            return 0;
        }

        $generation = sanitize_key((string) ($envelope['generation'] ?? ''));
        $entities = isset($envelope['entities']) && is_array($envelope['entities']) ? $envelope['entities'] : [];
        if ($generation === '' || ! preg_match('/^vmig_[a-f0-9]{20}$/', $generation) || $entities === []) {
            return 0;
        }

        // Adopt the file's generation as the serving pointer so entity_refs stay
        // stable across the restore (refs are HMAC-derived from identity + generation).
        update_option('dbvc_visual_editor_media_index_generation', $generation);

        $imported = 0;
        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $row = $this->normalizeImportRow($entity, $generation);
            if ($row === null) {
                continue;
            }
            if ($this->store->upsertEntity($row) > 0) {
                $imported++;
            }
        }

        // Mark the builder complete for this generation so the scheduler does not
        // re-drain immediately after a restore — the JSON supplied the population.
        if ($this->builder !== null && $imported > 0) {
            update_option('dbvc_visual_editor_media_index_build', [
                'generation' => $generation,
                'cursor' => [],
                'status' => 'complete',
                'processed' => $imported,
                'indexed' => $imported,
                'started_at' => current_time('mysql', true),
                'imported_from_json' => true,
            ], false);
        }

        /**
         * Fires after the derived Media Index JSON mirror has been imported into an
         * empty table.
         *
         * @param string $file_path Absolute path to the imported file.
         * @param int    $imported  Number of rows upserted.
         */
        do_action('dbvc_visual_editor_media_index_imported', $file_path, $imported);

        return $imported;
    }

    /**
     * @return string
     */
    public function filePathIfConfigured()
    {
        $target_dir = $this->targetDir();

        return $target_dir === '' ? '' : $this->filePath($target_dir);
    }

    /**
     * @param string $generation
     * @return array<int, array<string, mixed>>
     */
    private function collectServingRows($generation)
    {
        $rows = [];
        $offset = 0;
        $chunk = 200;

        while (true) {
            $batch = $this->store->listEntities([
                'generation' => $generation,
                'limit' => $chunk,
                'offset' => $offset,
                'sort' => 'entity_asc',
            ]);
            if (! is_array($batch) || $batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $rows[] = $this->prepareExportRow($row);
            }
            if (count($batch) < $chunk) {
                break;
            }
            $offset += $chunk;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function prepareExportRow(array $row)
    {
        return [
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => absint($row['entity_id'] ?? 0),
            'entity_subtype' => (string) ($row['entity_subtype'] ?? ''),
            'entity_ref' => (string) ($row['entity_ref'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'frontend_url' => (string) ($row['frontend_url'] ?? ''),
            'missing_count' => absint($row['missing_count'] ?? 0),
            'populated_count' => absint($row['populated_count'] ?? 0),
            'family_counts' => is_array($row['family_counts'] ?? null) ? $row['family_counts'] : [],
            'content_hash' => (string) ($row['content_hash'] ?? ''),
            'indexed_at' => (string) ($row['indexed_at'] ?? ''),
            'is_dirty' => ! empty($row['is_dirty']),
        ];
    }

    /**
     * @param array<string, mixed> $entity
     * @param string               $generation
     * @return array<string, mixed>|null
     */
    private function normalizeImportRow(array $entity, $generation)
    {
        $type = sanitize_key((string) ($entity['entity_type'] ?? ''));
        $id = absint($entity['entity_id'] ?? 0);
        if (! in_array($type, ['post', 'term'], true) || $id <= 0) {
            return null;
        }

        return [
            'entity_type' => $type,
            'entity_id' => $id,
            'entity_subtype' => sanitize_key((string) ($entity['entity_subtype'] ?? '')),
            'label' => sanitize_text_field((string) ($entity['label'] ?? '')),
            'frontend_url' => esc_url_raw((string) ($entity['frontend_url'] ?? '')),
            'missing_count' => absint($entity['missing_count'] ?? 0),
            'populated_count' => absint($entity['populated_count'] ?? 0),
            'family_counts' => is_array($entity['family_counts'] ?? null) ? $entity['family_counts'] : [],
            'content_hash' => sanitize_text_field((string) ($entity['content_hash'] ?? '')),
            'index_generation' => $generation,
            'indexed_at' => sanitize_text_field((string) ($entity['indexed_at'] ?? current_time('mysql', true))),
            'is_dirty' => ! empty($entity['is_dirty']),
        ];
    }

    /**
     * @return string
     */
    private function targetDir()
    {
        $base = $this->syncBase();
        if ($base === '') {
            return '';
        }

        return trailingslashit($base) . self::SYNC_SUBFOLDER;
    }

    /**
     * @param string $target_dir
     * @return string
     */
    private function filePath($target_dir)
    {
        return trailingslashit($target_dir) . self::FILE_NAME;
    }

    /**
     * @return string
     */
    private function syncBase()
    {
        if ($this->override_sync_base !== '') {
            return $this->override_sync_base;
        }
        if (function_exists('dbvc_get_sync_path')) {
            $base = (string) dbvc_get_sync_path();

            return $base !== '' ? untrailingslashit($base) : '';
        }

        return '';
    }

    /**
     * @param string $target_dir
     * @return bool
     */
    private function ensureDirectory($target_dir)
    {
        if (is_dir($target_dir)) {
            return true;
        }
        if (! function_exists('wp_mkdir_p')) {
            return @mkdir($target_dir, 0755, true);
        }

        return wp_mkdir_p($target_dir);
    }

    /**
     * @param string $file_path
     * @return bool
     */
    private function isSafeFilePath($file_path)
    {
        if (function_exists('dbvc_is_safe_file_path')) {
            return (bool) dbvc_is_safe_file_path($file_path);
        }

        // Minimal safety without the helper: no null bytes, no traversal, .json extension.
        if (strpos($file_path, chr(0)) !== false || strpos($file_path, '..') !== false) {
            return false;
        }

        return strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION)) === 'json';
    }
}
