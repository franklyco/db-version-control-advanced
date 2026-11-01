<?php
/**
 * Keeps secret option values out of portability rollback backups on disk.
 *
 * Security finding S1: configuration portability and Bricks portability
 * capture a rollback backup (`backup.json`) before they change options. The
 * backup copies whole option values, API keys and licence keys included, and
 * lands under `uploads/dbvc/`, which nginx serves because it ignores the
 * .htaccess deny. DBVC_SYNC_PATH_ABSOLUTE moves the folder out of the web
 * root, but sites without it stay exposed, and the files are still plain text
 * wherever they are.
 *
 * Redacting the values would break rollback, which must put the real values
 * back. So the secret values are moved out of the file instead: each one is
 * replaced in `backup.json` with a marker, `[dbvc-vaulted:<ref>]`, and the
 * values are kept in one non-autoloaded option per backup,
 * `dbvc_backup_vault_secrets_<hash>`. The values came from wp_options, so the
 * copy stays in the store, and under the access rules, they were already in.
 * Encrypting them in the file was not chosen: the key would come from the
 * wp-config salts, and rotating those, a routine step after an incident,
 * would make every earlier rollback unable to restore its secrets.
 *
 * Rollback opens the backup before it writes anything. A marker whose value
 * is missing (the option was deleted, or the database came from another
 * site) fails the whole rollback, so a marker is never written to a real
 * option.
 *
 * The vault option is not autoloaded, so the options.json export, which reads
 * wp_load_alloptions(), never sees it; its name also carries the word
 * "secrets", so DBVC_Options_Secret_Redactor would redact it anyway.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Backup_Secret_Vault')) {
    final class DBVC_Backup_Secret_Vault
    {
        const SCOPE_CONFIG = 'config_portability';
        const SCOPE_BRICKS = 'bricks_portability';

        /**
         * Option name prefix. The word "secrets" makes the options.json
         * redactor treat the whole option as secret.
         */
        const OPTION_PREFIX = 'dbvc_backup_vault_secrets_';

        /**
         * Backups folder of each scope, relative to the DBVC uploads root.
         *
         * @var array<string,string>
         */
        const BACKUP_DIRS = [
            self::SCOPE_CONFIG => 'dbvc-config-portability/backups',
            self::SCOPE_BRICKS => 'bricks-portability/backups',
        ];

        /**
         * Key fragments treated as secret in a backup, matched without case.
         * Wider than the export rules: moving a value to the vault loses
         * nothing, so a false positive costs nothing, while a miss leaves a
         * key on disk.
         */
        const BACKUP_SECRET_WORDS = '/secret|token|passw|pwd|api[_\-]?key|credential|licen[cs]e|webhook|private[_\-]?key|access[_\-]?key|salt/i';

        /**
         * Case-sensitive camelCase shapes, for Bricks keys such as
         * apiKeyGoogleMaps, apiSecretKeyTurnstile and mailchimpKey.
         */
        const BACKUP_SECRET_CAMEL = '/^api[A-Z_\-]|[a-z0-9]Key$/';

        /**
         * Replace every secret value in a backup document with a marker and
         * store the values in the backup's vault option.
         *
         * The document is the in-memory backup with real values. Callers keep
         * using that copy and write only the sealed one to disk. If the vault
         * cannot be written an error is returned, and the caller must not
         * write the backup file at all.
         *
         * @param array  $document      Backup document.
         * @param string $scope         One of the SCOPE_* constants.
         * @param string $backup_id     Backup identifier (its folder name).
         * @param array  $always_secret Option or field names to vault whole, whatever their name.
         * @return array|WP_Error Sealed document.
         */
        public static function seal(array $document, $scope, $backup_id, array $always_secret = [])
        {
            $option = self::option_name($scope, $backup_id);
            if ($option === '') {
                return new WP_Error('dbvc_backup_vault_invalid', __('The backup secret vault key is invalid.', 'dbvc'));
            }

            $split = self::split($document, $scope, $always_secret);
            $store = self::store($option, $scope, $backup_id, $split['secrets'], $split['referenced']);
            if (is_wp_error($store)) {
                return $store;
            }

            return $split['document'];
        }

        /**
         * Put the vaulted values back into a sealed backup document.
         *
         * A document without markers is returned unchanged, so backups
         * written before the vault existed still roll back.
         *
         * @param array  $document  Backup document read from disk.
         * @param string $scope     One of the SCOPE_* constants.
         * @param string $backup_id Backup identifier.
         * @return array|WP_Error The document with real values, or an error when any marker cannot be resolved.
         */
        public static function open(array $document, $scope, $backup_id)
        {
            if (! self::contains_marker($document)) {
                return $document;
            }

            $vault   = self::load(self::option_name($scope, $backup_id));
            $missing = 0;
            $opened  = self::resolve_markers($document, $vault, $missing);
            if ($missing > 0) {
                return new WP_Error(
                    'dbvc_backup_secrets_unavailable',
                    sprintf(
                        /* translators: %d: number of values */
                        __('This backup keeps %d secret value(s) in the database, and they are missing here. Nothing was restored.', 'dbvc'),
                        $missing
                    )
                );
            }

            return $opened;
        }

        /**
         * Whether a value, at any depth, carries a vault marker.
         *
         * @param mixed $value
         * @return bool
         */
        public static function contains_marker($value)
        {
            if (is_string($value)) {
                return self::marker_ref($value) !== '';
            }
            if (is_array($value)) {
                foreach ($value as $child) {
                    if (self::contains_marker($child)) {
                        return true;
                    }
                }
            }

            return false;
        }

        /**
         * Delete a backup's vault option.
         *
         * @param string $scope
         * @param string $backup_id
         * @return void
         */
        public static function delete($scope, $backup_id)
        {
            $option = self::option_name($scope, $backup_id);
            if ($option !== '') {
                delete_option($option);
            }
        }

        /**
         * The vault option name of one backup.
         *
         * @param string $scope
         * @param string $backup_id
         * @return string Empty for an unknown scope or an empty id.
         */
        public static function option_name($scope, $backup_id)
        {
            $backup_id = sanitize_key((string) $backup_id);
            if ($backup_id === '' || ! isset(self::BACKUP_DIRS[$scope])) {
                return '';
            }

            return self::OPTION_PREFIX . md5($scope . '|' . $backup_id);
        }

        /**
         * Whether a key names a secret in a backup.
         *
         * @param string $name Option name, field key or nested array key.
         * @param string $path Dotted path of the key's parent ('' at the top).
         * @return bool
         */
        public static function is_secret_name($name, $path = '')
        {
            $name      = (string) $name;
            $is_secret = $name !== '' && (
                (bool) preg_match(self::BACKUP_SECRET_WORDS, $name)
                || (bool) preg_match(self::BACKUP_SECRET_CAMEL, $name)
                || (class_exists('DBVC_Options_Secret_Redactor') && DBVC_Options_Secret_Redactor::is_secret_field_name($name, (string) $path))
            );

            /** This filter is documented in DBVC_Options_Secret_Redactor::is_secret_option_name(). */
            return (bool) apply_filters('dbvc_is_secret_option_key', $is_secret, $name, (string) $path);
        }

        /**
         * Configuration portability field and option names marked sensitive or
         * typed `secret` by their provider. Their whole value is vaulted.
         *
         * @return array<int,string>
         */
        public static function config_secret_names()
        {
            if (! class_exists('\Dbvc\ConfigurationPortability\Registry')) {
                return [];
            }

            $names = [];
            foreach (\Dbvc\ConfigurationPortability\Registry::get_providers() as $provider) {
                foreach ($provider->get_fields() as $field_key => $field) {
                    if (! is_array($field) || (empty($field['sensitive']) && ($field['type'] ?? '') !== 'secret')) {
                        continue;
                    }
                    $names[] = (string) $field_key;
                    $names[] = (string) ($field['option_key'] ?? $field_key);
                }
            }

            return array_values(array_unique($names));
        }

        /**
         * Seal secret values in portability backups written before the vault.
         *
         * Scans `dbvc-config-portability/backups/` and `bricks-portability/backups/`
         * under the active DBVC uploads root and, when it differs, under the
         * historical `uploads/dbvc/`, where files stay until
         * `wp dbvc migrate-artifacts --remove-originals` removes them. Both
         * copies of one backup seal to the same bytes and share one vault
         * option, so the migration still sees them as identical.
         *
         * With $prune_orphans, vault options whose backup is in neither root
         * are reported and, outside a dry run, deleted.
         *
         * The report names backup folders and counts values; it never carries
         * a value or an option name from inside a backup.
         *
         * @param bool $dry_run       Report without writing.
         * @param bool $prune_orphans Also remove vault options without a backup.
         * @return array|WP_Error {dry_run, backups: list of {scope, location, backup, status, secret_values}, orphans, totals}
         */
        public static function secure_existing_backups($dry_run = true, $prune_orphans = false)
        {
            if (! current_user_can('manage_options')) {
                return new WP_Error('dbvc_forbidden', __('You do not have permission to modify backups.', 'dbvc'));
            }

            $dry_run = (bool) $dry_run;
            $report  = [
                'dry_run' => $dry_run,
                'backups' => [],
                'orphans' => [],
                'totals'  => ['backups' => 0, 'to_seal' => 0, 'sealed' => 0, 'clean' => 0, 'failed' => 0, 'secret_values' => 0, 'orphans' => 0],
            ];
            $known = [];

            foreach (self::backup_roots() as $location => $root) {
                foreach (self::BACKUP_DIRS as $scope => $relative) {
                    $folders = glob($root . '/' . $relative . '/*', GLOB_ONLYDIR) ?: [];
                    sort($folders);
                    foreach ($folders as $folder) {
                        $backup_id = sanitize_key(basename($folder));
                        if ($backup_id === '' || $backup_id !== basename($folder)) {
                            continue;
                        }
                        $known[self::option_name($scope, $backup_id)] = true;
                        $entry = self::secure_backup_file($folder, $scope, $backup_id, $dry_run);
                        $entry['location'] = $location;
                        $report['backups'][] = $entry;
                        $report['totals']['backups']++;
                        $report['totals']['secret_values'] += $entry['secret_values'];
                        if (isset($report['totals'][$entry['status']])) {
                            $report['totals'][$entry['status']]++;
                        }
                    }
                }
            }

            if ($prune_orphans) {
                foreach (self::vault_option_names() as $option) {
                    if (isset($known[$option])) {
                        continue;
                    }
                    $vault                = self::load($option);
                    $report['orphans'][]  = [
                        'scope'         => (string) ($vault['scope'] ?? ''),
                        'backup'        => (string) ($vault['backup_id'] ?? ''),
                        'secret_values' => count((array) ($vault['secrets'] ?? [])),
                    ];
                    if (! $dry_run) {
                        delete_option($option);
                    }
                }
                $report['totals']['orphans'] = count($report['orphans']);
            }

            if (! $dry_run && class_exists('DBVC_Sync_Logger')) {
                DBVC_Sync_Logger::log('Portability backup secrets moved to the database vault', [
                    'backups'       => $report['totals']['backups'],
                    'sealed'        => $report['totals']['sealed'],
                    'failed'        => $report['totals']['failed'],
                    'secret_values' => $report['totals']['secret_values'],
                    'orphans'       => $report['totals']['orphans'],
                ]);
            }

            return $report;
        }

        /**
         * @param string $folder
         * @param string $scope
         * @param string $backup_id
         * @param bool   $dry_run
         * @return array{scope:string,backup:string,status:string,secret_values:int}
         */
        private static function secure_backup_file($folder, $scope, $backup_id, $dry_run)
        {
            $entry = ['scope' => $scope, 'backup' => $backup_id, 'status' => 'clean', 'secret_values' => 0];
            $path  = $folder . '/backup.json';
            if (is_link($folder) || is_link($path) || ! is_file($path)) {
                $entry['status'] = is_file($path) ? 'failed' : 'clean';
                return $entry;
            }

            $raw      = file_get_contents($path);
            $document = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($document)) {
                $entry['status'] = 'failed';
                return $entry;
            }

            $always = $scope === self::SCOPE_CONFIG ? self::config_secret_names() : [];
            $split  = self::split($document, $scope, $always);
            $entry['secret_values'] = count($split['secrets']);
            if (empty($split['secrets'])) {
                return $entry;
            }
            if ($dry_run) {
                $entry['status'] = 'to_seal';
                return $entry;
            }

            $sealed = self::seal($document, $scope, $backup_id, $always);
            $write  = is_wp_error($sealed) ? $sealed : self::write_backup_file($folder, $scope, $sealed);
            $entry['status'] = is_wp_error($write) ? 'failed' : 'sealed';

            return $entry;
        }

        /**
         * Write a sealed document with the scope's own JSON writer, so the
         * bytes match what the runtime writes.
         *
         * @param string $folder
         * @param string $scope
         * @param array  $document
         * @return string|WP_Error
         */
        private static function write_backup_file($folder, $scope, array $document)
        {
            if ($scope === self::SCOPE_BRICKS && class_exists('DBVC_Bricks_Portability_Storage')) {
                return DBVC_Bricks_Portability_Storage::write_json_file($folder, 'backup.json', $document);
            }
            if ($scope === self::SCOPE_CONFIG && class_exists('\Dbvc\ConfigurationPortability\Storage')) {
                return \Dbvc\ConfigurationPortability\Storage::write_json_file($folder, 'backup.json', $document);
            }

            return new WP_Error('dbvc_backup_vault_writer_missing', __('The backup writer is unavailable.', 'dbvc'));
        }

        /**
         * DBVC uploads roots that can hold portability backups, keyed by
         * location label.
         *
         * @return array<string,string>
         */
        private static function backup_roots()
        {
            $roots = [];
            if (class_exists('\Dbvc\AiPackage\Storage')) {
                $current = \Dbvc\AiPackage\Storage::get_dbvc_root();
                if (is_string($current) && $current !== '') {
                    $roots['current'] = untrailingslashit(wp_normalize_path($current));
                }
            }
            if (class_exists('DBVC_Artifact_Location')) {
                $uploads = DBVC_Artifact_Location::legacy_uploads_dir();
                $legacy  = $uploads !== '' ? $uploads . '/dbvc' : '';
                if ($legacy !== '' && ! in_array($legacy, $roots, true)) {
                    $roots['legacy'] = $legacy;
                }
            }

            return $roots;
        }

        /**
         * @return array<int,string>
         */
        private static function vault_option_names()
        {
            global $wpdb;

            $like = $wpdb->esc_like(self::OPTION_PREFIX) . '%';
            return array_map('strval', (array) $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like)));
        }

        /**
         * Split a backup document into its sealed form and the vaulted values.
         *
         * @param array  $document
         * @param string $scope
         * @param array  $always_secret
         * @return array{document:array,secrets:array<string,mixed>,referenced:array<string,bool>}
         */
        private static function split(array $document, $scope, array $always_secret)
        {
            $state = ['secrets' => [], 'referenced' => [], 'always' => array_flip(array_map('strval', $always_secret))];

            if ($scope === self::SCOPE_BRICKS) {
                foreach ((array) ($document['options'] ?? []) as $name => $value) {
                    $document['options'][$name] = self::seal_value($value, (string) $name, 'options.' . $name, false, $state);
                }
            } elseif ($scope === self::SCOPE_CONFIG) {
                foreach ((array) ($document['domains'] ?? []) as $domain => $record) {
                    if (! is_array($record)) {
                        continue;
                    }
                    $base = 'domains.' . $domain;
                    if (isset($record['values']) && is_array($record['values'])) {
                        foreach ($record['values'] as $field => $slot) {
                            if (! is_array($slot) || ! array_key_exists('value', $slot)) {
                                continue;
                            }
                            $option = (string) ($slot['option_key'] ?? $field);
                            $whole  = isset($state['always'][(string) $field]) || isset($state['always'][$option]) || self::is_secret_name((string) $field);
                            $document['domains'][$domain]['values'][$field]['value'] = self::seal_value($slot['value'], $option, $base . '.values.' . $field . '.value', $whole, $state);
                        }
                    } elseif (array_key_exists('value', $record)) {
                        $option = (string) ($record['option_key'] ?? '');
                        $document['domains'][$domain]['value'] = self::seal_value($record['value'], $option, $base . '.value', isset($state['always'][$option]), $state);
                    }
                }
            }

            return ['document' => $document, 'secrets' => $state['secrets'], 'referenced' => $state['referenced']];
        }

        /**
         * @param mixed  $value
         * @param string $name  Key naming $value.
         * @param string $path  Dotted path of $value in the document; the ref is derived from it.
         * @param bool   $whole Vault the value whatever its name.
         * @param array  $state Collected secrets and kept markers.
         * @return mixed
         */
        private static function seal_value($value, $name, $path, $whole, array &$state)
        {
            if (is_string($value) && self::marker_ref($value) !== '') {
                $state['referenced'][self::marker_ref($value)] = true;
                return $value;
            }
            if (self::is_empty($value)) {
                return $value;
            }
            if ($whole || self::is_secret_name($name, '')) {
                $ref                    = substr(hash('sha256', $path), 0, 20);
                $state['secrets'][$ref] = $value;
                return '[dbvc-vaulted:' . $ref . ']';
            }
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    $child_path = $path . '.' . $key;
                    if (is_string($key) && ! self::is_empty($child) && ! (is_string($child) && self::marker_ref($child) !== '') && self::is_secret_name($key, $path)) {
                        $ref                    = substr(hash('sha256', $child_path), 0, 20);
                        $state['secrets'][$ref] = $child;
                        $value[$key]            = '[dbvc-vaulted:' . $ref . ']';
                    } else {
                        $value[$key] = self::seal_value($child, '', $child_path, false, $state);
                    }
                }
            }

            return $value;
        }

        /**
         * @param mixed $value
         * @param array $vault   Loaded vault option.
         * @param int   $missing Count of unresolved markers.
         * @return mixed
         */
        private static function resolve_markers($value, array $vault, &$missing)
        {
            if (is_string($value)) {
                $ref = self::marker_ref($value);
                if ($ref === '') {
                    return $value;
                }
                $secrets = isset($vault['secrets']) && is_array($vault['secrets']) ? $vault['secrets'] : [];
                if (! array_key_exists($ref, $secrets)) {
                    $missing++;
                    return $value;
                }
                return $secrets[$ref];
            }
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    $value[$key] = self::resolve_markers($child, $vault, $missing);
                }
            }

            return $value;
        }

        /**
         * Replace the vault option with the new secrets plus any still
         * referenced by markers kept from an earlier seal.
         *
         * @param string $option
         * @param string $scope
         * @param string $backup_id
         * @param array  $secrets
         * @param array  $referenced
         * @return true|WP_Error
         */
        private static function store($option, $scope, $backup_id, array $secrets, array $referenced)
        {
            $existing = self::load($option);
            $kept     = array_intersect_key((array) ($existing['secrets'] ?? []), $referenced);
            $all      = array_replace($kept, $secrets);
            if (! empty(array_diff_key($referenced, $all))) {
                return new WP_Error('dbvc_backup_secrets_unavailable', __('This backup refers to secret values that are missing from the database.', 'dbvc'));
            }
            if (empty($all)) {
                delete_option($option);
                return true;
            }

            $payload = [
                'scope'          => $scope,
                'backup_id'      => sanitize_key((string) $backup_id),
                'updated_at_gmt' => gmdate('c'),
                'secrets'        => $all,
            ];
            if (! empty($existing) && (array) ($existing['secrets'] ?? []) === $all) {
                return true;
            }

            delete_option($option);
            if (! add_option($option, $payload, '', false)) {
                return new WP_Error('dbvc_backup_vault_write_failed', __('Could not store the backup secret values in the database. The backup was not written.', 'dbvc'));
            }

            return true;
        }

        /**
         * @param string $option
         * @return array
         */
        private static function load($option)
        {
            if ($option === '') {
                return [];
            }
            $value = get_option($option, []);
            return is_array($value) ? $value : [];
        }

        /**
         * @param string $value
         * @return string The ref, or '' when $value is not a marker.
         */
        private static function marker_ref($value)
        {
            return preg_match('/^\[dbvc-vaulted:([a-f0-9]{20})\]$/', (string) $value, $match) ? $match[1] : '';
        }

        /**
         * @param mixed $value
         * @return bool
         */
        private static function is_empty($value)
        {
            return $value === null || $value === '' || $value === [] || is_bool($value);
        }
    }
}
