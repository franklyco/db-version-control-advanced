<?php
/**
 * Private artifact storage: backups, snapshots, media bundles, transfer
 * packets and the log, kept outside the web root beside the absolute sync root.
 *
 * Security finding S1: DBVC_SYNC_PATH_ABSOLUTE moves the sync folder out of
 * the web root, but backups (which copy options.json), proposals, snapshots,
 * transfer packets and media bundles were still written to
 * `uploads/sync/`, and the log to `wp-content/`. nginx ignores their
 * .htaccess deny, so they stayed downloadable.
 *
 * Layout: when a validated absolute sync root is active, these artifacts move
 * to a sibling folder named after it, `<absolute root>-artifacts`, for example
 * `private_html/dbvc-sync` and `private_html/dbvc-sync-artifacts`. The
 * subfolder names stay the ones used under `uploads/sync/`, so migrated data
 * and the relative paths recorded in manifests keep working. A sibling, not a
 * subfolder of the sync root, because "clear sync folder" empties the sync
 * root and the sync root is often a git working tree: backups inside it would
 * be deleted or committed. With no absolute root configured, nothing changes.
 *
 * The storage trees DBVC keeps directly under the uploads folder follow the
 * same rule: `uploads/dbvc/` (AI sample packages and intake, configuration
 * and Bricks portability exports, sessions and backups, official
 * collections) and the content collector's `uploads/<storage path>/`. They
 * move to `<private root>/uploads/`, keeping their uploads-relative paths.
 * Configuration portability backups copy whole option values, API keys
 * included, so they need this as much as the sync backups do.
 *
 * Existing data is moved by migrate(): preview, then copy-and-verify. The
 * originals are only removed by remove_legacy(), a separate, confirmed step
 * that re-verifies every copy first.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Artifact_Location')) {
    final class DBVC_Artifact_Location
    {
        const ROOT_SUFFIX      = '-artifacts';
        const SITE_HEALTH_TEST = 'dbvc_private_artifacts_location';
        const LOG_SUBDIR       = 'logs';
        const DENY_FILES       = ['.htaccess', 'index.php'];

        /**
         * Artifact areas and the folder each uses below the artifact base.
         *
         * @var array<string,string>
         */
        const AREAS = [
            'backups'          => 'db-version-control-backups',
            'snapshots'        => 'db-version-control-snapshots',
            'media_bundles'    => 'media-bundles',
            'transfer_packets' => 'dbvc-transfer-packets',
        ];

        /**
         * Folder below the private root that mirrors the uploads folder.
         */
        const UPLOADS_SUBDIR = 'uploads';

        /**
         * Storage trees kept directly under the uploads folder, keyed by
         * area. uploads_areas() adds the content collector, whose folder name
         * is a setting.
         *
         * @var array<string,string>
         */
        const UPLOADS_AREAS = [
            'dbvc_storage' => 'dbvc',
        ];

        /**
         * Validation results keyed by the absolute sync root.
         *
         * @var array<string,string|WP_Error>
         */
        private static $validated = [];

        /**
         * Register the Site Health test.
         *
         * @return void
         */
        public static function register()
        {
            add_filter('site_status_tests', [self::class, 'register_site_health_test']);
        }

        /**
         * The validated private artifact root, without a trailing slash.
         *
         * @return string Empty when no absolute sync root is active or the
         *                artifact root was refused.
         */
        public static function private_root()
        {
            $result = self::validation_result();
            return is_string($result) ? $result : '';
        }

        /**
         * Whether artifacts are stored in the private root.
         *
         * @return bool
         */
        public static function is_private()
        {
            return self::private_root() !== '';
        }

        /**
         * Why the private artifact root was refused.
         *
         * @return WP_Error|null Null when no absolute sync root is active or the root is valid.
         */
        public static function refusal()
        {
            $result = self::validation_result();
            return is_wp_error($result) ? $result : null;
        }

        /**
         * The historical artifact base, `uploads/sync`, without creating it.
         *
         * @return string Empty when the uploads folder cannot be resolved.
         */
        public static function legacy_root()
        {
            $uploads = self::legacy_uploads_dir();
            return $uploads !== '' ? $uploads . '/sync' : '';
        }

        /**
         * The active artifact base: the private root, else `uploads/sync`.
         *
         * @return string
         */
        public static function base_dir()
        {
            $private = self::private_root();
            return $private !== '' ? $private : self::legacy_root();
        }

        /**
         * Absolute folder of one artifact area. Never creates it.
         *
         * @param string $area Key of self::AREAS.
         * @return string Empty for an unknown area or an unresolvable base.
         */
        public static function path($area)
        {
            $base = self::base_dir();
            if ($base === '' || ! isset(self::AREAS[$area])) {
                return '';
            }
            return $base . '/' . self::AREAS[$area];
        }

        /**
         * Create the artifact base and one area, and add deny files to both.
         *
         * @param string $area Key of self::AREAS.
         * @return string|WP_Error The area folder.
         */
        public static function ensure($area)
        {
            $base = self::base_dir();
            $dir  = self::path($area);
            if ($dir === '') {
                return new WP_Error('dbvc_artifacts_unresolved', __('Unable to resolve the DBVC artifact folder.', 'dbvc'));
            }
            foreach ([$base, $dir] as $folder) {
                if (! is_dir($folder) && ! wp_mkdir_p($folder)) {
                    return new WP_Error('dbvc_artifacts_create_failed', __('Unable to create the DBVC artifact folder.', 'dbvc'));
                }
                if (method_exists('DBVC_Sync_Posts', 'ensure_directory_security')) {
                    DBVC_Sync_Posts::ensure_directory_security($folder);
                }
            }
            return $dir;
        }

        /**
         * The real uploads folder, without a trailing slash.
         *
         * @return string Empty when the uploads folder cannot be resolved.
         */
        public static function legacy_uploads_dir()
        {
            $uploads = wp_get_upload_dir();
            if (! empty($uploads['error']) || empty($uploads['basedir'])) {
                return '';
            }
            return untrailingslashit(wp_normalize_path($uploads['basedir']));
        }

        /**
         * The base for DBVC's uploads storage trees: `<private root>/uploads`
         * while artifacts are private, else the uploads folder. Never creates it.
         *
         * Callers append the same relative path either way (`dbvc/ai-intake`,
         * `contentcollector`), so recorded relative paths survive a migration.
         *
         * @return string Empty when the uploads folder cannot be resolved.
         */
        public static function uploads_base_dir()
        {
            $private = self::private_root();
            return $private !== '' ? $private . '/' . self::UPLOADS_SUBDIR : self::legacy_uploads_dir();
        }

        /**
         * Uploads storage areas and their folder names.
         *
         * The content collector is included when its add-on is loaded. A
         * collector folder that is `sync` or another area's folder is left
         * out: it would be copied twice.
         *
         * @return array<string,string>
         */
        public static function uploads_areas()
        {
            $areas = self::UPLOADS_AREAS;
            if (class_exists('DBVC_CC_Artifact_Manager') && method_exists('DBVC_CC_Artifact_Manager', 'get_storage_folder_name')) {
                $folder = (string) DBVC_CC_Artifact_Manager::get_storage_folder_name();
                if ($folder !== '' && $folder !== 'sync' && ! in_array($folder, $areas, true)) {
                    $areas['content_collector'] = $folder;
                }
            }
            return $areas;
        }

        /**
         * The private log folder, or '' when artifacts are not private.
         *
         * @return string
         */
        public static function private_log_dir()
        {
            $private = self::private_root();
            return $private !== '' ? $private . '/' . self::LOG_SUBDIR : '';
        }

        /**
         * Validate a private artifact root.
         *
         * It must pass the same checks as the absolute sync root (outside
         * every web root, no traversal, no system folder) and must not
         * overlap the sync root in either direction: clearing the sync folder
         * would otherwise delete the artifacts, or the artifacts would sit
         * above the sync data.
         *
         * @param string $path      Candidate absolute path.
         * @param string $sync_root The validated absolute sync root.
         * @return string|WP_Error Normalized path without a trailing slash.
         */
        public static function validate_root($path, $sync_root)
        {
            $validated = DBVC_Sync_Location::validate_absolute_path($path);
            if (is_wp_error($validated)) {
                return $validated;
            }
            if (
                DBVC_Sync_Location::is_within($validated, $sync_root)
                || DBVC_Sync_Location::is_within($sync_root, $validated)
            ) {
                return new WP_Error('dbvc_artifacts_overlap_sync', __('The private artifact folder overlaps the sync folder. Clearing the sync folder would delete the backups.', 'dbvc'));
            }
            return $validated;
        }

        /**
         * Preview or run the copy of `uploads/sync` artifacts and the legacy
         * log into the private root.
         *
         * Files are copied, then re-read and hash-compared. An existing,
         * identical target counts as verified; a differing one is a conflict
         * and is never overwritten. Old logs land in `logs/legacy/`. Symlinks are skipped, so the copy cannot
         * pull in files from outside the legacy folders. Originals are never
         * deleted here: see remove_legacy().
         *
         * The report names areas and top-level entries (backup folders,
         * proposal IDs) and gives counts. It never carries file contents.
         *
         * @param bool $dry_run Report without writing.
         * @return array|WP_Error
         */
        public static function migrate($dry_run = true)
        {
            $context = self::migration_context();
            if (is_wp_error($context)) {
                return $context;
            }

            $dry_run = (bool) $dry_run;
            $report  = self::empty_report($dry_run, $context);
            if (! $dry_run) {
                foreach (array_keys(self::AREAS) as $area) {
                    $ensured = self::ensure($area);
                    if (is_wp_error($ensured)) {
                        return $ensured;
                    }
                }
                $ensured = self::ensure_uploads_areas($context);
                if (is_wp_error($ensured)) {
                    return $ensured;
                }
            }

            foreach ($context['sources'] as $area => $source) {
                $target = $context['targets'][$area];
                $row    = ['area' => $area, 'entries' => [], 'files' => 0, 'bytes' => 0, 'to_copy' => 0, 'copied' => 0, 'verified' => 0, 'conflicts' => 0, 'skipped' => 0, 'failed' => 0];

                foreach (self::collect_files($source['dir'], $source['match']) as $file) {
                    $row['files']++;
                    $row['bytes'] += $file['size'];
                    $row['entries'][$file['entry']] = true;
                    if ($file['link']) {
                        $row['skipped']++;
                        continue;
                    }

                    $destination = $target . '/' . $file['relative'];
                    $state       = self::compare($file['path'], $destination);
                    if ($state === 'identical') {
                        $row['verified']++;
                        continue;
                    }
                    if ($state === 'conflict') {
                        $row['conflicts']++;
                        continue;
                    }

                    $row['to_copy']++;
                    if ($dry_run) {
                        continue;
                    }
                    if (self::copy_verified($file['path'], $destination, $context['private_root'])) {
                        $row['copied']++;
                        $row['verified']++;
                    } else {
                        $row['failed']++;
                    }
                }

                $row['entries'] = array_keys($row['entries']);
                sort($row['entries']);
                $report['areas'][] = $row;
                foreach (['files', 'bytes', 'to_copy', 'copied', 'verified', 'conflicts', 'skipped', 'failed'] as $key) {
                    $report['totals'][$key] += $row[$key];
                }
            }

            if (! $dry_run && class_exists('DBVC_Sync_Logger')) {
                DBVC_Sync_Logger::log('Artifacts copied to the private root', $report['totals']);
            }

            $report['complete'] = $report['totals']['verified'] === $report['totals']['files'] - $report['totals']['skipped'];
            return $report;
        }

        /**
         * Preview or delete originals in `uploads/sync` and the legacy log
         * that have a verified identical copy in the private root.
         *
         * Every file is hash-compared with its copy immediately before it is
         * removed; anything without an identical copy stays. Empty folders
         * inside an area are removed afterwards, including the area folder
         * itself, but never `uploads/sync`, the uploads folder, the sync
         * root, the private root or anything that contains them.
         *
         * @param bool $dry_run Report without deleting.
         * @return array|WP_Error
         */
        public static function remove_legacy($dry_run = true)
        {
            $context = self::migration_context();
            if (is_wp_error($context)) {
                return $context;
            }

            $dry_run = (bool) $dry_run;
            $report  = self::empty_report($dry_run, $context);

            foreach ($context['sources'] as $area => $source) {
                $target = $context['targets'][$area];
                $row    = ['area' => $area, 'entries' => [], 'files' => 0, 'bytes' => 0, 'to_remove' => 0, 'removed' => 0, 'kept' => 0, 'failed' => 0];

                // The log area removes matched files only, never folders, and
                // its folder is usually wp-content itself.
                if ($source['match'] === null && ! self::is_removable_area($source['dir'], $source['parent'], $context)) {
                    $row['failed'] = 1;
                    $report['areas'][] = $row;
                    $report['totals']['failed']++;
                    continue;
                }

                foreach (self::collect_files($source['dir'], $source['match']) as $file) {
                    $row['files']++;
                    $row['bytes'] += $file['size'];
                    if ($file['link'] || self::compare($file['path'], $target . '/' . $file['relative']) !== 'identical') {
                        $row['kept']++;
                        continue;
                    }
                    $row['to_remove']++;
                    $row['entries'][$file['entry']] = true;
                    if ($dry_run) {
                        continue;
                    }
                    if (@unlink($file['path'])) {
                        $row['removed']++;
                    } else {
                        $row['failed']++;
                    }
                }

                if (! $dry_run && $source['match'] === null) {
                    self::remove_empty_dirs($source['dir'], $source['parent']);
                }

                $row['entries'] = array_keys($row['entries']);
                sort($row['entries']);
                $report['areas'][] = $row;
                foreach (['files', 'bytes', 'to_remove', 'removed', 'kept', 'failed'] as $key) {
                    $report['totals'][$key] += $row[$key];
                }
            }

            if (! $dry_run && class_exists('DBVC_Sync_Logger')) {
                DBVC_Sync_Logger::log('Legacy artifact originals removed', $report['totals']);
            }

            return $report;
        }

        /**
         * Count legacy artifact files that still sit in the web root.
         *
         * @return int
         */
        public static function legacy_file_count()
        {
            return array_sum(self::legacy_file_counts());
        }

        /**
         * Register the "private artifact storage" Site Health test.
         *
         * @param array $tests
         * @return array
         */
        public static function register_site_health_test($tests)
        {
            if (! is_array($tests)) {
                $tests = [];
            }
            $tests['direct'][self::SITE_HEALTH_TEST] = [
                'label' => __('DBVC backup and log location', 'dbvc'),
                'test'  => [self::class, 'site_health_test'],
            ];
            return $tests;
        }

        /**
         * Site Health result for backups, snapshots, bundles, packets and the log.
         *
         * Only reports once an absolute sync root is active; before that the
         * sync folder test already tells the operator to set one.
         *
         * @return array
         */
        public static function site_health_test()
        {
            $result = [
                'label'       => __('DBVC backups and logs are outside the web root', 'dbvc'),
                'status'      => 'good',
                'badge'       => ['label' => __('Security', 'dbvc'), 'color' => 'blue'],
                'description' => '<p>' . esc_html__('DBVC stores backups, proposals, snapshots, media bundles, transfer packets and its log where the web server cannot serve them.', 'dbvc') . '</p>',
                'actions'     => '',
                'test'        => self::SITE_HEALTH_TEST,
            ];

            if (DBVC_Sync_Location::absolute_root() === '') {
                $result['label']       = __('DBVC backups and logs follow the sync folder location', 'dbvc');
                $result['description'] = '<p>' . esc_html(sprintf(
                    /* translators: %s: constant name */
                    __('Backups and logs move outside the web root once %s is set.', 'dbvc'),
                    DBVC_Sync_Location::CONSTANT_NAME
                )) . '</p>';

                // Without the constant, existing backups sit in uploads/sync
                // and DBVC's uploads storage in uploads/dbvc and the content
                // collector folder, which the sync folder's nginx guidance
                // does not cover.
                $folders = self::exposed_legacy_folders();
                if ($folders !== []) {
                    $legacy_root              = self::legacy_root();
                    $others                   = array_values(array_diff($folders, [$legacy_root]));
                    $result['status']         = 'recommended';
                    $result['badge']['color'] = 'red';
                    $result['label']          = __('DBVC backups and logs are web-reachable', 'dbvc');
                    $result['description']    = '';
                    if (in_array($legacy_root, $folders, true)) {
                        $result['description'] .= '<p>' . esc_html(sprintf(
                            /* translators: %s: absolute folder path */
                            __('Backups (which copy options.json), proposals, snapshots, media bundles and transfer packets are stored in %s. nginx ignores its .htaccess, so these files can be downloaded unless the server blocks the path.', 'dbvc'),
                            $legacy_root
                        )) . '</p>';
                    }
                    if ($others !== []) {
                        $result['description'] .= '<p>' . esc_html(sprintf(
                            /* translators: %s: comma-separated absolute folder paths */
                            __('Configuration and Bricks portability backups (which copy option values, API keys included), AI packages and content collector data are stored in %s. nginx ignores their .htaccess, so these files can be downloaded unless the server blocks the path.', 'dbvc'),
                            implode(', ', $others)
                        )) . '</p>';
                    }
                    $result['actions'] = '<p>' . esc_html(DBVC_Sync_Location::setup_instructions()) . ' '
                        . esc_html__('Backups, logs and DBVC uploads storage then move beside it; run "wp dbvc migrate-artifacts" for existing ones.', 'dbvc') . '</p>';
                    $rules = [];
                    foreach ($folders as $folder) {
                        $rule = class_exists('DBVC_Sync_Exposure') ? DBVC_Sync_Exposure::nginx_rule($folder) : '';
                        if ($rule !== '') {
                            $rules[] = '<pre class="dbvc-sync-nginx-rule"><code>' . esc_html($rule) . '</code></pre>';
                        }
                    }
                    if ($rules !== []) {
                        $result['actions'] .= '<p>' . esc_html__('Until then, nginx: add these blocks to the server block for this site, then reload nginx.', 'dbvc') . '</p>'
                            . implode('', $rules);
                    }
                }
                return $result;
            }

            $refusal = self::refusal();
            if ($refusal instanceof WP_Error) {
                $result['status']         = 'critical';
                $result['badge']['color'] = 'red';
                $result['label']          = __('DBVC backups and logs stayed web-reachable', 'dbvc');
                $result['description']    = '<p>' . esc_html($refusal->get_error_message()) . '</p>';
                return $result;
            }

            $legacy = self::legacy_file_count();
            if ($legacy > 0) {
                $result['status']         = 'recommended';
                $result['badge']['color'] = 'red';
                $result['label']          = __('Old DBVC backups are still in the web root', 'dbvc');
                $result['description']    = '<p>' . esc_html(sprintf(
                    /* translators: %d: file count */
                    _n('%d backup, proposal, bundle, packet, log or DBVC uploads storage file is still under the uploads folder or wp-content.', '%d backup, proposal, bundle, packet, log or DBVC uploads storage files are still under the uploads folder or wp-content.', $legacy, 'dbvc'),
                    $legacy
                )) . '</p>';
                $result['actions'] = '<p>' . esc_html__('Run "wp dbvc migrate-artifacts --dry-run --user=<admin>", then "wp dbvc migrate-artifacts", then "wp dbvc migrate-artifacts --remove-originals".', 'dbvc') . '</p>';
            }

            return $result;
        }

        /**
         * Clear the validation cache. For tests that change the filters.
         *
         * @return void
         */
        public static function reset_cache()
        {
            self::$validated = [];
        }

        /**
         * @return string|WP_Error|null
         */
        private static function validation_result()
        {
            $sync_root = DBVC_Sync_Location::absolute_root();
            if ($sync_root === '') {
                return null;
            }
            if (! array_key_exists($sync_root, self::$validated)) {
                /**
                 * Filters the private artifact root used beside the absolute sync root.
                 *
                 * For must-use plugins and tests. The returned value is
                 * validated like DBVC_SYNC_PATH_ABSOLUTE and must not overlap
                 * the sync root.
                 *
                 * @param string $candidate Default `<absolute sync root>-artifacts`.
                 * @param string $sync_root The validated absolute sync root.
                 */
                $candidate = apply_filters('dbvc_private_artifacts_root', $sync_root . self::ROOT_SUFFIX, $sync_root);
                self::$validated[$sync_root] = self::validate_root(is_string($candidate) ? $candidate : '', $sync_root);
            }
            return self::$validated[$sync_root];
        }

        /**
         * Capability, private-root and source checks shared by migrate() and remove_legacy().
         *
         * @return array|WP_Error {private_root, sources, targets}
         */
        private static function migration_context()
        {
            if (! current_user_can('manage_options')) {
                return new WP_Error('dbvc_forbidden', __('You do not have permission to move DBVC artifacts.', 'dbvc'));
            }
            $private = self::private_root();
            if ($private === '') {
                $refusal = self::refusal();
                return $refusal instanceof WP_Error
                    ? $refusal
                    : new WP_Error('dbvc_artifacts_not_private', sprintf(
                        /* translators: %s: constant name */
                        __('Define %s first. Artifacts only move once the sync folder is outside the web root.', 'dbvc'),
                        DBVC_Sync_Location::CONSTANT_NAME
                    ));
            }

            $sources = self::legacy_sources();
            $targets = [];
            foreach ($sources as $area => $source) {
                $targets[$area] = $private . '/' . $source['target'];
            }

            return ['private_root' => $private, 'sources' => $sources, 'targets' => $targets];
        }

        /**
         * Legacy source folders. `match` limits the log area to log files,
         * since the legacy log folder defaults to wp-content itself.
         * `parent` is the folder the area sits in, which removal never
         * touches; `target` is the area's path below the private root.
         *
         * @return array<string,array{dir:string,match:?string,parent:string,target:string}>
         */
        private static function legacy_sources()
        {
            $sources = [];
            $legacy  = self::legacy_root();
            if ($legacy !== '') {
                foreach (self::AREAS as $area => $folder) {
                    $sources[$area] = ['dir' => $legacy . '/' . $folder, 'match' => null, 'parent' => $legacy, 'target' => $folder];
                }
            }
            $log_dir = class_exists('DBVC_Sync_Logger') ? DBVC_Sync_Logger::get_legacy_log_directory() : '';
            if ($log_dir !== '') {
                // Old logs go to logs/legacy: the live log in logs/ would
                // otherwise collide with them once logging writes there.
                $sources['logs'] = ['dir' => $log_dir, 'match' => DBVC_Sync_Logger::LOG_FILENAME, 'parent' => $log_dir, 'target' => self::LOG_SUBDIR . '/legacy'];
            }
            $uploads = self::legacy_uploads_dir();
            if ($uploads !== '') {
                foreach (self::uploads_areas() as $area => $folder) {
                    $sources[$area] = ['dir' => $uploads . '/' . $folder, 'match' => null, 'parent' => $uploads, 'target' => self::UPLOADS_SUBDIR . '/' . $folder];
                }
            }
            return $sources;
        }

        /**
         * Data files per legacy area. Deny files are not data and are not
         * counted: the admin pages recreate them in empty storage folders.
         *
         * @return array<string,int>
         */
        private static function legacy_file_counts()
        {
            $counts = [];
            foreach (self::legacy_sources() as $area => $source) {
                $counts[$area] = 0;
                foreach (self::collect_files($source['dir'], $source['match']) as $file) {
                    if (! in_array(basename($file['path']), self::DENY_FILES, true)) {
                        $counts[$area]++;
                    }
                }
            }
            return $counts;
        }

        /**
         * Web-reachable legacy folders that still hold data files:
         * `uploads/sync` for backups and the log (as before), and each
         * uploads storage area by its own folder.
         *
         * @return array<int,string>
         */
        private static function exposed_legacy_folders()
        {
            $sources = self::legacy_sources();
            $folders = [];
            foreach (self::legacy_file_counts() as $area => $count) {
                if ($count === 0) {
                    continue;
                }
                $folder = (isset(self::AREAS[$area]) || $area === 'logs') ? self::legacy_root() : $sources[$area]['dir'];
                if ($folder !== '' && ! in_array($folder, $folders, true) && DBVC_Sync_Location::is_web_reachable($folder)) {
                    $folders[] = $folder;
                }
            }
            return $folders;
        }

        /**
         * Create `<private root>/uploads` and each uploads area target, with deny files.
         *
         * @param array $context From migration_context().
         * @return true|WP_Error
         */
        private static function ensure_uploads_areas(array $context)
        {
            $folders = [$context['private_root'] . '/' . self::UPLOADS_SUBDIR];
            foreach (array_keys(self::uploads_areas()) as $area) {
                if (isset($context['targets'][$area])) {
                    $folders[] = $context['targets'][$area];
                }
            }
            foreach ($folders as $folder) {
                if (! is_dir($folder) && ! wp_mkdir_p($folder)) {
                    return new WP_Error('dbvc_artifacts_create_failed', __('Unable to create the DBVC artifact folder.', 'dbvc'));
                }
                if (method_exists('DBVC_Sync_Posts', 'ensure_directory_security')) {
                    DBVC_Sync_Posts::ensure_directory_security($folder);
                }
            }
            return true;
        }

        /**
         * List files below a folder.
         *
         * With $match, only files directly in the folder whose name is $match
         * or a rotated `$match.<stamp>` archive.
         *
         * @param string      $dir
         * @param string|null $match
         * @return array<int,array{path:string,relative:string,entry:string,size:int,link:bool}>
         */
        private static function collect_files($dir, $match)
        {
            $dir = untrailingslashit(wp_normalize_path((string) $dir));
            if ($dir === '' || ! is_dir($dir) || is_link($dir)) {
                return [];
            }

            $files = [];
            if ($match !== null) {
                foreach ((array) glob($dir . '/' . $match . '*') as $path) {
                    $name = basename((string) $path);
                    if ($name !== $match && strpos($name, $match . '.') !== 0) {
                        continue;
                    }
                    if (! is_file($path) && ! is_link($path)) {
                        continue;
                    }
                    $files[] = self::file_row(wp_normalize_path($path), $dir);
                }
                return $files;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if ($item->isDir() && ! $item->isLink()) {
                    continue;
                }
                $path = wp_normalize_path($item->getPathname());
                if (! dbvc_path_is_within($path, $dir) || $path === $dir) {
                    continue;
                }
                $files[] = self::file_row($path, $dir);
            }
            usort($files, static function ($a, $b) {
                return strcmp($a['relative'], $b['relative']);
            });
            return $files;
        }

        /**
         * @param string $path
         * @param string $dir
         * @return array{path:string,relative:string,entry:string,size:int,link:bool}
         */
        private static function file_row($path, $dir)
        {
            $relative = ltrim(substr($path, strlen($dir)), '/');
            $parts    = explode('/', $relative);
            $link     = is_link($path);
            return [
                'path'     => $path,
                'relative' => $relative,
                'entry'    => $parts[0],
                'size'     => $link ? 0 : (int) @filesize($path),
                'link'     => $link,
            ];
        }

        /**
         * @param string $source
         * @param string $destination
         * @return string missing | identical | conflict
         */
        private static function compare($source, $destination)
        {
            if (! file_exists($destination) && ! is_link($destination)) {
                return 'missing';
            }
            if (is_link($destination) || ! is_file($destination)) {
                return 'conflict';
            }
            // Deny files are not data: the private folder's own .htaccess or
            // index.php, written by ensure(), stands in for the original.
            if (in_array(basename($source), self::DENY_FILES, true)) {
                return 'identical';
            }
            $a = @hash_file('sha256', $source);
            $b = @hash_file('sha256', $destination);
            return ($a !== false && $a === $b) ? 'identical' : 'conflict';
        }

        /**
         * Copy one file into the private root and confirm its hash.
         *
         * @param string $source
         * @param string $destination
         * @param string $private_root
         * @return bool
         */
        private static function copy_verified($source, $destination, $private_root)
        {
            if (! dbvc_path_is_within($destination, $private_root) || strpos($destination, '/../') !== false) {
                return false;
            }
            $dir = dirname($destination);
            if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
                return false;
            }
            if (! DBVC_Sync_Location::is_within($dir, $private_root)) {
                return false;
            }
            if (! @copy($source, $destination)) {
                return false;
            }
            if (self::compare($source, $destination) !== 'identical') {
                @unlink($destination);
                return false;
            }
            @touch($destination, (int) @filemtime($source));
            return true;
        }

        /**
         * Whether a legacy folder may have files removed from it.
         *
         * The area must sit strictly inside its parent, and no configured
         * root (private, sync, `uploads/sync`, uploads) may sit inside it.
         *
         * @param string $dir
         * @param string $parent  The folder the area belongs to.
         * @param array  $context
         * @return bool
         */
        private static function is_removable_area($dir, $parent, array $context)
        {
            if (DBVC_Sync_Location::is_within($dir, $context['private_root'])) {
                return false;
            }
            if ($parent === '' || untrailingslashit($dir) === untrailingslashit($parent) || ! dbvc_path_is_within($dir, $parent)) {
                return false;
            }
            $protected = array_filter([
                $context['private_root'],
                DBVC_Sync_Location::absolute_root(),
                self::legacy_root(),
                self::legacy_uploads_dir(),
            ]);
            foreach ($protected as $root) {
                if (DBVC_Sync_Location::is_within($root, $dir) && untrailingslashit($root) !== untrailingslashit($dir)) {
                    return false;
                }
            }
            return true;
        }

        /**
         * Remove empty folders below and including $dir, leaving deny files
         * only when the folder holds nothing else.
         *
         * @param string $dir    An area folder below uploads/sync or uploads.
         * @param string $parent The folder the area sits in; never removed.
         * @return void
         */
        private static function remove_empty_dirs($dir, $parent)
        {
            $legacy = untrailingslashit((string) $parent);
            if ($legacy === '' || ! dbvc_path_is_within($dir, $legacy) || untrailingslashit($dir) === $legacy || ! is_dir($dir)) {
                return;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir() && ! $item->isLink()) {
                    @rmdir($item->getPathname());
                }
            }
            @rmdir($dir);
        }

        /**
         * @param bool  $dry_run
         * @param array $context
         * @return array
         */
        private static function empty_report($dry_run, array $context)
        {
            return [
                'dry_run'      => $dry_run,
                'private_root' => $context['private_root'],
                'areas'        => [],
                'totals'       => ['files' => 0, 'bytes' => 0, 'to_copy' => 0, 'copied' => 0, 'verified' => 0, 'conflicts' => 0, 'skipped' => 0, 'failed' => 0, 'to_remove' => 0, 'removed' => 0, 'kept' => 0],
            ];
        }
    }
}
