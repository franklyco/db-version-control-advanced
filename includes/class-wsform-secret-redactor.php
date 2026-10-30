<?php

/**
 * Secret redaction for WS Form form exports written to the sync folder.
 *
 * Form exports land in third-party/ws-form/forms/ under the web-served sync
 * folder, so they follow the options.json contract: secret values become
 * DBVC_Options_Secret_Redactor::PLACEHOLDER on export, and on import the
 * placeholder keeps the target form's own value.
 *
 * WS Form needs its own key list. Each action's settings are a JSON string
 * inside the form's "action" data grid, which the generic nested redaction
 * cannot see, and action settings use names such as `..._dl_var_key` and
 * `..._header_name` for mapping labels, which the generic `_key` ending would
 * redact. The secrets are credentials, webhook endpoints, HTTP header values
 * (where bearer tokens live) and literal email addresses.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_WSForm_Secret_Redactor')) {
    class DBVC_WSForm_Secret_Redactor
    {
        /**
         * Key endings whose non-empty value is always redacted.
         */
        const SECRET_KEY_PATTERN = '/(^|_)(password|passwd|pwd|secret|secret_key|private_key|access_key|api_key|apikey|token|access_token|auth_token|bearer_token|license_key|licence_key|username|endpoint|webhook|webhook_url|header_value)$/i';

        /**
         * Key endings redacted only when the value holds a literal address.
         * A WS Form variable such as #blog_admin_email reveals nothing.
         */
        const EMAIL_KEY_PATTERN = '/(^|_)(email|cc|bcc)$/i';

        /**
         * Redact secret values in a form export payload.
         *
         * Covers form-level meta and every action's settings. Field and
         * section meta are not walked: they hold layout and validation
         * settings, not credentials.
         *
         * @param array $payload Form export payload (decoded JSON).
         * @return array
         */
        public static function redact_form_payload(array $payload): array
        {
            if (! isset($payload['meta']) || ! is_array($payload['meta'])) {
                return $payload;
            }

            $meta = $payload['meta'];
            $action = array_key_exists('action', $meta) ? $meta['action'] : null;
            unset($meta['action']);

            $meta = self::redact_tree($meta, 'wsform.meta');
            if ($action !== null) {
                $meta['action'] = self::map_action_configs($action, static function (array $config) {
                    if (isset($config['meta']) && is_array($config['meta'])) {
                        $config['meta'] = self::redact_tree($config['meta'], 'wsform.action.' . self::action_type($config));
                    }
                    return $config;
                });
            }

            $payload['meta'] = self::restore_key_order($payload['meta'], $meta);
            return $payload;
        }

        /**
         * Resolve redaction placeholders in an incoming form against the target form.
         *
         * Actions are matched by their row ID in the action grid and must be
         * the same action type, so a reordered grid cannot hand one action's
         * credentials to another. A placeholder with no matching value is
         * dropped, which leaves WS Form's default (empty) setting.
         *
         * @param array      $incoming Payload read from the sync folder.
         * @param array|null $current  The uid-matched local form as an export-shaped array, or null.
         * @return array
         */
        public static function restore_form_payload(array $incoming, ?array $current): array
        {
            if (! self::payload_has_placeholder($incoming)) {
                return $incoming;
            }

            $meta = $incoming['meta'];
            $current_meta = isset($current['meta']) && is_array($current['meta']) ? $current['meta'] : [];

            $current_actions = [];
            if (array_key_exists('action', $current_meta)) {
                self::map_action_configs($current_meta['action'], static function (array $config, $row_id) use (&$current_actions) {
                    if ($row_id !== null) {
                        $current_actions[(string) $row_id] = $config;
                    }
                    return $config;
                });
            }

            $action = array_key_exists('action', $meta) ? $meta['action'] : null;
            unset($meta['action'], $current_meta['action']);

            $meta = DBVC_Options_Secret_Redactor::restore_placeholders($meta, $current_meta);
            if ($action !== null) {
                $meta['action'] = self::map_action_configs($action, static function (array $config, $row_id) use ($current_actions) {
                    if (! isset($config['meta']) || ! DBVC_Options_Secret_Redactor::contains_placeholder($config['meta'])) {
                        return $config;
                    }
                    $match = $row_id !== null ? ($current_actions[(string) $row_id] ?? null) : null;
                    $same_type = is_array($match) && self::action_type($match) === self::action_type($config);
                    $config['meta'] = DBVC_Options_Secret_Redactor::restore_placeholders(
                        $config['meta'],
                        $same_type && isset($match['meta']) ? $match['meta'] : null
                    );
                    return $config;
                });
            }

            $incoming['meta'] = self::restore_key_order($incoming['meta'], $meta);
            return $incoming;
        }

        /**
         * Whether a payload carries the placeholder anywhere, including inside
         * the JSON-encoded action settings.
         *
         * @param array $payload
         * @return bool
         */
        public static function payload_has_placeholder(array $payload): bool
        {
            if (! isset($payload['meta']) || ! is_array($payload['meta'])) {
                return false;
            }

            $json = wp_json_encode($payload['meta']);
            return is_string($json) && strpos($json, DBVC_Options_Secret_Redactor::PLACEHOLDER) !== false;
        }

        /**
         * Re-apply redaction to a form export file in place.
         *
         * Used for forms already in the sync folder or a backup, which may
         * predate this redaction.
         *
         * @param string $file_path
         * @param bool   $write False to only report whether the file holds unredacted secrets.
         * @return bool True when the file held unredacted secrets (and, when writing, was rewritten).
         */
        public static function redact_form_file(string $file_path, bool $write = true): bool
        {
            $raw = is_file($file_path) && is_readable($file_path) ? file_get_contents($file_path) : false;
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($decoded) || ! self::looks_like_form_payload($decoded)) {
                return false;
            }

            $redacted = self::redact_form_payload($decoded);
            if ($redacted === $decoded) {
                return false;
            }
            if (! $write) {
                return true;
            }

            if (array_key_exists('checksum', $redacted)) {
                $redacted['checksum'] = self::form_checksum($redacted);
            }
            $json = wp_json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return is_string($json) && file_put_contents($file_path, $json) !== false;
        }

        /**
         * WS Form's checksum for an export payload.
         *
         * WS Form's own importer decodes the file to objects, drops
         * `checksum` and compares md5(wp_json_encode()) of the rest, so the
         * checksum must be recomputed after redaction for the file to stay
         * importable through WS Form itself.
         *
         * @param array $payload
         * @return string
         */
        public static function form_checksum(array $payload): string
        {
            unset($payload['checksum']);
            $object = json_decode((string) wp_json_encode($payload));
            return md5((string) wp_json_encode($object));
        }

        /**
         * @param string $key   Setting key.
         * @param mixed  $value Scalar setting value.
         * @param string $path  Dotted path of the key's parent.
         * @return bool
         */
        public static function is_secret_setting($key, $value, $path): bool
        {
            $key = (string) $key;
            $is_secret = (bool) preg_match(self::SECRET_KEY_PATTERN, $key)
                || ((bool) preg_match(self::EMAIL_KEY_PATTERN, $key) && is_string($value) && strpos($value, '@') !== false);

            /** This filter is documented in DBVC_Options_Secret_Redactor::is_secret_option_name(). */
            return (bool) apply_filters('dbvc_is_secret_option_key', $is_secret, $key, (string) $path);
        }

        /**
         * @param array  $value
         * @param string $path
         * @return array
         */
        private static function redact_tree(array $value, string $path): array
        {
            foreach ($value as $key => $child) {
                if (is_array($child)) {
                    $value[$key] = self::redact_tree($child, $path . '.' . $key);
                } elseif (
                    is_string($key)
                    && (is_string($child) || is_int($child) || is_float($child))
                    && $child !== ''
                    && $child !== DBVC_Options_Secret_Redactor::PLACEHOLDER
                    && self::is_secret_setting($key, $child, $path)
                ) {
                    $value[$key] = DBVC_Options_Secret_Redactor::PLACEHOLDER;
                }
            }

            return $value;
        }

        /**
         * Apply a callback to each action's decoded settings in an action grid.
         *
         * The grid is groups[].rows[].data[], where one data cell holds the
         * action settings as a JSON string ({"id": "<type>", "meta": {...}}).
         * A cell is re-encoded only when the callback changed it, so untouched
         * actions keep their exact bytes. The grid itself may be a JSON string.
         *
         * @param mixed    $grid
         * @param callable $callback Receives (array $config, int|string|null $row_id), returns the config.
         * @return mixed
         */
        private static function map_action_configs($grid, callable $callback)
        {
            $grid_was_string = is_string($grid);
            $decoded_grid = $grid_was_string ? json_decode($grid, true) : $grid;
            if (! is_array($decoded_grid) || ! isset($decoded_grid['groups']) || ! is_array($decoded_grid['groups'])) {
                return $grid;
            }

            $changed = false;
            foreach ($decoded_grid['groups'] as $g => $group) {
                if (! is_array($group) || ! isset($group['rows']) || ! is_array($group['rows'])) {
                    continue;
                }
                foreach ($group['rows'] as $r => $row) {
                    if (! is_array($row) || ! isset($row['data']) || ! is_array($row['data'])) {
                        continue;
                    }
                    $row_id = isset($row['id']) && (is_int($row['id']) || is_string($row['id'])) ? $row['id'] : null;
                    foreach ($row['data'] as $c => $cell) {
                        $cell_was_string = is_string($cell);
                        $config = $cell_was_string ? json_decode($cell, true) : $cell;
                        if (! is_array($config) || ! isset($config['id']) || ! array_key_exists('meta', $config)) {
                            continue;
                        }
                        $updated = $callback($config, $row_id);
                        if ($updated === $config) {
                            continue;
                        }
                        $decoded_grid['groups'][$g]['rows'][$r]['data'][$c] = $cell_was_string ? wp_json_encode($updated) : $updated;
                        $changed = true;
                    }
                }
            }

            if (! $changed) {
                return $grid;
            }

            return $grid_was_string ? wp_json_encode($decoded_grid) : $decoded_grid;
        }

        /**
         * @param array $config Decoded action settings.
         * @return string
         */
        private static function action_type(array $config): string
        {
            return isset($config['id']) && is_scalar($config['id']) ? (string) $config['id'] : '';
        }

        /**
         * Put "action" back at its original position in the meta map.
         *
         * @param array $original Meta before redaction.
         * @param array $updated  Meta after redaction, with "action" appended.
         * @return array
         */
        private static function restore_key_order(array $original, array $updated): array
        {
            $ordered = [];
            foreach (array_keys($original) as $key) {
                if (array_key_exists($key, $updated)) {
                    $ordered[$key] = $updated[$key];
                }
            }
            foreach ($updated as $key => $value) {
                if (! array_key_exists($key, $ordered)) {
                    $ordered[$key] = $value;
                }
            }

            return $ordered;
        }

        /**
         * @param array $payload
         * @return bool
         */
        private static function looks_like_form_payload(array $payload): bool
        {
            return class_exists('DBVC_Third_Party_Portability')
                ? DBVC_Third_Party_Portability::is_wsform_form_payload($payload)
                : isset($payload['meta']) && is_array($payload['meta']);
        }
    }
}
