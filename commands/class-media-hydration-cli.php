<?php

/**
 * WP-CLI media hydration commands.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (defined('WP_CLI') && WP_CLI && ! class_exists('DBVC_WP_CLI_Media')) {
    WP_CLI::add_command('dbvc media', 'DBVC_WP_CLI_Media');

    /**
     * Read-only media inventory commands for hydration planning.
     */
    class DBVC_WP_CLI_Media extends WP_CLI_Command
    {
        /**
         * Build a dry-run hydration plan from a media mirror manifest.
         *
         * ## OPTIONS
         *
         * --manifest=<path>
         * : Path to dbvc-media-mirror.json.
         *
         * [--dry-run]
         * : Build a dry-run plan.
         *
         * [--apply]
         * : Hydrate existing attachment files from the local mirror package.
         *
         * [--confirm=<token>]
         * : Required with --apply. Use hydrate-existing-media.
         *
         * [--package-root=<path>]
         * : Package directory containing media/. Defaults to the manifest directory.
         *
         * [--limit=<number>]
         * : Max planned items to apply in this run. Default: 100. Maximum: 500.
         *
         * [--offset=<number>]
         * : Planned item offset for apply pagination. Default: 0.
         *
         * [--no-repair-metadata]
         * : Copy missing files without regenerating attachment metadata.
         *
         * [--save-receipt]
         * : Save a JSON receipt for dry-run output. Apply runs always save receipts when possible.
         *
         * [--no-clone-confirmation]
         * : Do not require source and target attachment IDs to match.
         *
         * [--no-strict-hashes]
         * : Do not compare target hashes when source hashes are available.
         *
         * [--match-policy=<policy>]
         * : same_id_then_uid or uid_then_path. Default: same_id_then_uid.
         *
         * [--format=<format>]
         * : Output format: table or json. Default: table.
         *
         * ## EXAMPLES
         *
         * wp dbvc media hydrate --manifest=/path/dbvc-media-mirror.json --dry-run
         * wp dbvc media hydrate --manifest=/path/dbvc-media-mirror.json --apply --confirm=hydrate-existing-media
         *
         * @param array<int,string> $args
         * @param array<string,mixed> $assoc_args
         * @return void
         */
        public function hydrate($args, $assoc_args): void
        {
            unset($args);

            $apply = ! empty($assoc_args['apply']);
            $dry_run = ! empty($assoc_args['dry-run']);
            if ($apply && $dry_run) {
                WP_CLI::error('Choose either --dry-run or --apply, not both.');
            }

            if (! $apply && ! $dry_run) {
                WP_CLI::error('Add --dry-run to inspect the plan or --apply --confirm=hydrate-existing-media to hydrate files.');
            }

            if (empty($assoc_args['manifest'])) {
                WP_CLI::error('Missing required --manifest path.');
            }

            $settings_available = class_exists('\Dbvc\Media\Hydration\Settings');
            $common_args = [
                'clone_confirmation' => empty($assoc_args['no-clone-confirmation']) && (! $settings_available || \Dbvc\Media\Hydration\Settings::get_bool(\Dbvc\Media\Hydration\Settings::OPTION_CLONE_CONFIRMATION) === '1'),
                'strict_hashes' => empty($assoc_args['no-strict-hashes']) && (! $settings_available || \Dbvc\Media\Hydration\Settings::get_bool(\Dbvc\Media\Hydration\Settings::OPTION_STRICT_HASHES) === '1'),
                'match_policy' => isset($assoc_args['match-policy']) ? (string) $assoc_args['match-policy'] : ($settings_available ? \Dbvc\Media\Hydration\Settings::get_match_policy() : 'same_id_then_uid'),
            ];

            if ($apply) {
                if ($settings_available && \Dbvc\Media\Hydration\Settings::get_bool(\Dbvc\Media\Hydration\Settings::OPTION_ENABLED) !== '1') {
                    WP_CLI::error('Media hydration workflow is disabled in DBVC settings.');
                }

                if ((string) ($assoc_args['confirm'] ?? '') !== 'hydrate-existing-media') {
                    WP_CLI::error('Applying media hydration requires --confirm=hydrate-existing-media.');
                }

                if (! class_exists('\Dbvc\Media\Hydration\Hydrator')) {
                    WP_CLI::error('Media hydrator is unavailable.');
                }
                if (! class_exists('\Dbvc\Media\Hydration\HydrationLock')) {
                    WP_CLI::error('Media hydration lock service is unavailable.');
                }

                $lock = \Dbvc\Media\Hydration\HydrationLock::acquire([
                    'owner' => 'wp-cli',
                    'ttl_seconds' => $settings_available ? \Dbvc\Media\Hydration\Settings::get_lock_timeout_minutes() * 60 : 1800,
                ]);
                if (is_wp_error($lock)) {
                    WP_CLI::error($lock->get_error_message());
                }

                $default_limit = $settings_available ? \Dbvc\Media\Hydration\Settings::get_batch_size() : 100;
                $repair_metadata = empty($assoc_args['no-repair-metadata'])
                    && (! $settings_available || \Dbvc\Media\Hydration\Settings::get_metadata_policy() !== 'skip');

                $report = null;
                try {
                    $report = \Dbvc\Media\Hydration\Hydrator::apply_from_manifest_file((string) $assoc_args['manifest'], $common_args + [
                        'package_root' => isset($assoc_args['package-root']) ? (string) $assoc_args['package-root'] : '',
                        'limit' => isset($assoc_args['limit']) ? absint($assoc_args['limit']) : $default_limit,
                        'offset' => isset($assoc_args['offset']) ? absint($assoc_args['offset']) : 0,
                        'repair_metadata' => $repair_metadata,
                    ]);
                } finally {
                    \Dbvc\Media\Hydration\HydrationLock::release((string) ($lock['token'] ?? ''));
                }

                if (is_wp_error($report)) {
                    WP_CLI::error($report->get_error_message());
                }

                $receipt = [];
                if (! $settings_available || \Dbvc\Media\Hydration\Settings::get_bool(\Dbvc\Media\Hydration\Settings::OPTION_RECEIPTS_ENABLED) === '1') {
                    $receipt = $this->persist_media_hydration_receipt('apply', $report);
                    if (is_wp_error($receipt)) {
                        WP_CLI::warning($receipt->get_error_message());
                        $receipt = [];
                    } else {
                        $report['receipt'] = $receipt;
                    }
                }

                $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
                if ($format === 'json') {
                    WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    return;
                }

                $summary = isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : [];
                $pagination = isset($report['pagination']) && is_array($report['pagination']) ? $report['pagination'] : [];
                \WP_CLI\Utils\format_items('table', [[
                    'items' => (int) ($summary['items'] ?? 0),
                    'hydrated' => (int) ($summary['hydrated'] ?? 0),
                    'metadata' => (int) ($summary['metadata_repaired'] ?? 0),
                    'skipped' => (int) ($summary['skipped'] ?? 0),
                    'blocked' => (int) ($summary['blocked'] ?? 0),
                    'errors' => (int) ($summary['errors'] ?? 0),
                    'bytes' => (int) ($summary['bytes'] ?? 0),
                    'has_more' => ! empty($pagination['has_more']) ? 'yes' : 'no',
                ]], ['items', 'hydrated', 'metadata', 'skipped', 'blocked', 'errors', 'bytes', 'has_more']);
                if (! empty($receipt['receipt_path'])) {
                    WP_CLI::log('Receipt: ' . (string) $receipt['receipt_path']);
                }
                return;
            }

            if (! class_exists('\Dbvc\Media\Hydration\HydrationPlanner')) {
                WP_CLI::error('Media hydration planner is unavailable.');
            }

            $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan_from_file((string) $assoc_args['manifest'], $common_args);

            if (is_wp_error($plan)) {
                WP_CLI::error($plan->get_error_message());
            }

            if (! empty($assoc_args['save-receipt']) && (! $settings_available || \Dbvc\Media\Hydration\Settings::get_bool(\Dbvc\Media\Hydration\Settings::OPTION_RECEIPTS_ENABLED) === '1')) {
                $receipt = $this->persist_media_hydration_receipt('plan', $plan);
                if (is_wp_error($receipt)) {
                    WP_CLI::warning($receipt->get_error_message());
                } else {
                    $plan['receipt'] = $receipt;
                }
            } elseif (! empty($assoc_args['save-receipt'])) {
                WP_CLI::warning('Media hydration receipts are disabled in settings.');
            }

            $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
            if ($format === 'json') {
                WP_CLI::line(wp_json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            }

            $summary = isset($plan['summary']) && is_array($plan['summary']) ? $plan['summary'] : [];
            \WP_CLI\Utils\format_items('table', [[
                'items' => (int) ($summary['items'] ?? 0),
                'ok' => (int) ($summary['ok'] ?? 0),
                'needs_hydration' => (int) ($summary['needs_hydration'] ?? 0),
                'metadata' => (int) ($summary['needs_metadata_repair'] ?? 0),
                'mismatch' => (int) ($summary['hash_mismatch'] ?? 0),
                'missing_target' => (int) ($summary['target_attachment_missing'] ?? 0),
                'blocked' => (int) ($summary['blocked'] ?? 0),
                'conflict' => (int) ($summary['conflict'] ?? 0),
            ]], ['items', 'ok', 'needs_hydration', 'metadata', 'mismatch', 'missing_target', 'blocked', 'conflict']);
            if (! empty($plan['receipt']['receipt_path'])) {
                WP_CLI::log('Receipt: ' . (string) $plan['receipt']['receipt_path']);
            }
        }

        /**
         * Build a full-library media mirror manifest/package from the current site.
         *
         * ## OPTIONS
         *
         * [--with-files]
         * : Copy original files into the package under media/.
         *
         * [--zip]
         * : Create a ZIP package.
         *
         * [--out=<path>]
         * : Optional ZIP destination path. Requires --zip and must end in .zip.
         *
         * [--package-id=<slug>]
         * : Optional package folder name.
         *
         * [--ids=<ids>]
         * : Comma-separated attachment IDs to export.
         *
         * [--mime-groups=<groups>]
         * : Comma-separated groups to include: image,video,audio,font,document,other.
         *
         * [--batch-size=<number>]
         * : Inventory page size. Default: 100. Maximum: 500.
         *
         * [--check-derivatives]
         * : Include generated derivative file checks in manifest metadata.
         *
         * [--format=<format>]
         * : Output format: table or json. Default: table.
         *
         * ## EXAMPLES
         *
         * wp dbvc media mirror-export
         * wp dbvc media mirror-export --with-files --zip
         * wp dbvc media mirror-export --ids=10,11 --format=json
         *
         * @param array<int,string> $args
         * @param array<string,mixed> $assoc_args
         * @return void
         */
        public function mirror_export($args, $assoc_args): void
        {
            unset($args);

            if (! class_exists('\Dbvc\Media\Hydration\MirrorManifestBuilder')) {
                WP_CLI::error('Media mirror builder is unavailable.');
            }

            $settings_available = class_exists('\Dbvc\Media\Hydration\Settings');
            $default_batch_size = $settings_available ? \Dbvc\Media\Hydration\Settings::get_batch_size() : 100;
            $default_mime_groups = $settings_available ? implode(',', \Dbvc\Media\Hydration\Settings::get_allowed_mime_groups()) : [];

            $result = \Dbvc\Media\Hydration\MirrorManifestBuilder::build_package([
                'include_files' => ! empty($assoc_args['with-files']),
                'create_zip' => ! empty($assoc_args['zip']),
                'zip_path' => isset($assoc_args['out']) ? (string) $assoc_args['out'] : '',
                'package_id' => isset($assoc_args['package-id']) ? (string) $assoc_args['package-id'] : '',
                'attachment_ids' => isset($assoc_args['ids']) ? (string) $assoc_args['ids'] : [],
                'mime_groups' => isset($assoc_args['mime-groups']) ? (string) $assoc_args['mime-groups'] : $default_mime_groups,
                'batch_size' => isset($assoc_args['batch-size']) ? absint($assoc_args['batch-size']) : $default_batch_size,
                'check_derivatives' => ! empty($assoc_args['check-derivatives']),
                'include_hashes' => true,
            ]);

            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
            if ($format === 'json') {
                WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            }

            $manifest = isset($result['manifest']) && is_array($result['manifest']) ? $result['manifest'] : [];
            $summary = isset($manifest['summary']) && is_array($manifest['summary']) ? $manifest['summary'] : [];
            $copy_stats = isset($result['copy_stats']) && is_array($result['copy_stats']) ? $result['copy_stats'] : [];

            \WP_CLI\Utils\format_items('table', [[
                'package_id' => (string) ($result['package_id'] ?? ''),
                'attachments' => (int) ($summary['attachments'] ?? 0),
                'existing' => (int) ($summary['existing_files'] ?? 0),
                'missing' => (int) ($summary['missing_files'] ?? 0),
                'copied' => (int) ($copy_stats['copied'] ?? 0),
                'errors' => (int) ($copy_stats['errors'] ?? 0),
            ]], ['package_id', 'attachments', 'existing', 'missing', 'copied', 'errors']);

            WP_CLI::log('Manifest: ' . (string) ($result['manifest_path'] ?? ''));
            if (! empty($result['zip_path'])) {
                WP_CLI::log('ZIP: ' . (string) $result['zip_path']);
            }
        }

        /**
         * Inventory registered Media Library attachments and local file state.
         *
         * ## OPTIONS
         *
         * [--limit=<number>]
         * : Number of attachments to inspect. Default: 100. Maximum: 500.
         *
         * [--offset=<number>]
         * : Offset for pagination. Default: 0.
         *
         * [--ids=<ids>]
         * : Comma-separated attachment IDs to inspect.
         *
         * [--mime-groups=<groups>]
         * : Comma-separated groups to include: image,video,audio,font,document,other.
         *
         * [--compute-hash]
         * : Compute SHA-256 hashes for existing local files.
         *
         * [--check-derivatives]
         * : Check generated image derivative files referenced by attachment metadata.
         *
         * [--format=<format>]
         * : Output format: table or json. Default: table.
         *
         * ## EXAMPLES
         *
         * wp dbvc media inventory
         * wp dbvc media inventory --ids=10,11 --format=json
         * wp dbvc media inventory --mime-groups=image --compute-hash
         *
         * @param array<int,string> $args
         * @param array<string,mixed> $assoc_args
         * @return void
         */
        public function inventory($args, $assoc_args): void
        {
            unset($args);

            if (! class_exists('\Dbvc\Media\Hydration\LibraryInventoryService')) {
                WP_CLI::error('Media hydration inventory service is unavailable.');
            }

            $result = \Dbvc\Media\Hydration\LibraryInventoryService::query([
                'limit' => isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 100,
                'offset' => isset($assoc_args['offset']) ? absint($assoc_args['offset']) : 0,
                'attachment_ids' => isset($assoc_args['ids']) ? (string) $assoc_args['ids'] : [],
                'mime_groups' => isset($assoc_args['mime-groups']) ? (string) $assoc_args['mime-groups'] : [],
                'compute_hash' => ! empty($assoc_args['compute-hash']),
                'check_derivatives' => ! empty($assoc_args['check-derivatives']),
                'include_file_state' => true,
            ]);

            $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
            if ($format === 'json') {
                WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            }

            $rows = [];
            foreach ((array) ($result['items'] ?? []) as $item) {
                $file_state = isset($item['file_state']) && is_array($item['file_state']) ? $item['file_state'] : [];
                $metadata = isset($file_state['metadata']) && is_array($file_state['metadata']) ? $file_state['metadata'] : [];

                $rows[] = [
                    'id' => (int) ($item['attachment_id'] ?? 0),
                    'mime_group' => (string) ($item['mime_group'] ?? ''),
                    'file_status' => (string) ($file_state['status'] ?? 'unknown'),
                    'metadata' => (string) ($metadata['status'] ?? 'unknown'),
                    'size' => isset($file_state['file_size']) ? (string) $file_state['file_size'] : '',
                    'relative_path' => (string) ($item['relative_path'] ?? ''),
                ];
            }

            if (empty($rows)) {
                WP_CLI::log('No attachments matched the inventory query.');
            } else {
                \WP_CLI\Utils\format_items('table', $rows, ['id', 'mime_group', 'file_status', 'metadata', 'size', 'relative_path']);
            }

            $summary = isset($result['summary']) && is_array($result['summary']) ? $result['summary'] : [];
            $pagination = isset($result['pagination']) && is_array($result['pagination']) ? $result['pagination'] : [];
            WP_CLI::log(sprintf(
                'Inventory page complete. Returned: %d. Total: %d. Missing files: %d. Unsafe paths: %d. Metadata missing: %d.',
                (int) ($pagination['returned'] ?? count($rows)),
                (int) ($pagination['total'] ?? 0),
                (int) ($summary['missing_files'] ?? 0),
                (int) ($summary['unsafe_paths'] ?? 0),
                (int) ($summary['metadata_missing'] ?? 0)
            ));
        }

        /**
         * Persist a media hydration receipt when the store is available.
         *
         * @param string              $type
         * @param array<string,mixed> $payload
         * @return array<string,string>|\WP_Error
         */
        private function persist_media_hydration_receipt(string $type, array $payload)
        {
            if (! class_exists('\Dbvc\Media\Hydration\HydrationReceiptStore')) {
                return new WP_Error('dbvc_media_hydration_receipt_store_missing', 'Media hydration receipt store is unavailable.');
            }

            return \Dbvc\Media\Hydration\HydrationReceiptStore::write($type, $payload);
        }
    }
}
