<?php
/**
 * Secret redaction for option and meta data written to the sync folder.
 *
 * The sync folder is written under ABSPATH, and its .htaccess deny is ignored
 * by nginx, so every value in it must be treated as publicly readable. Secret
 * shaped values in options.json, the ACF options-group files and the Entity
 * Editor snapshots are replaced with a fixed placeholder on export. On import
 * the placeholder means "keep this site's own value", so a redacted export
 * never overwrites the target's licence keys or credentials.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

class DBVC_Options_Secret_Redactor
{
    /**
     * Stored in options.json in place of a secret value. Part of the file
     * contract: exports committed to git carry this exact string.
     */
    const PLACEHOLDER = '[dbvc-redacted]';

    /**
     * Name fragments that mark a whole top-level option as secret wherever
     * they appear as a word (bricks_license_key, webpc_token_data,
     * dbvc_bricks_credentials_updated_at).
     */
    const TOP_LEVEL_WORDS = 'secret|secrets|token|password|passwd|license|licence|credential|credentials|apikey';

    /**
     * Name endings that mark a value as secret at any depth (api_key,
     * smtp.pass, facebook_secret, kits.N.token). Nested keys only match on
     * the ending so look-alikes such as max_tokens, lost_password_page and a
     * bare Bricks condition "key" are left alone. "_api" catches keys stored
     * under names such as unsplash_api. Licence activation and licence IDs
     * (brickssync_activation_id, sc_license_id) can act on the licence
     * through the vendor's API.
     */
    const SECRET_ENDINGS = '(^|[_\-.])(secret|token|password|passwd|pwd|pass|license|licence|salt|credentials?|apikey|apitoken)$|.[_\-.](key|api)$|(api|secret|private|access|license|licence)key$|(api|auth|access|refresh|bearer)token$|(^|[_\-.])(activation|license|licence)[_\-]?id$';

    /**
     * Redact every secret-shaped value in an options map destined for options.json.
     *
     * @param array $options Option name => value.
     * @return array
     */
    public static function redact_options(array $options)
    {
        foreach ($options as $name => $value) {
            if (self::is_secret_option_name((string) $name)) {
                $options[$name] = self::PLACEHOLDER;
            } elseif (is_array($value)) {
                $options[$name] = self::redact_nested($value, (string) $name);
            }
        }

        return $options;
    }

    /**
     * Redact secret-shaped values in ACF options-group field records.
     *
     * Each record is the options-group file shape: field key => {name, type,
     * value}. The whole value is redacted when the field is an ACF password
     * field or its name looks secret; otherwise nested sub-fields (groups,
     * repeaters) are redacted by key name.
     *
     * @param array  $fields Field key => field record.
     * @param string $path   Dotted path used for the classification filter.
     * @return array
     */
    public static function redact_acf_fields(array $fields, $path)
    {
        foreach ($fields as $field_key => $field) {
            if (! is_array($field) || ! array_key_exists('value', $field)) {
                continue;
            }
            $value = $field['value'];
            if ($value === null || $value === '' || $value === [] || $value === false) {
                continue;
            }

            $name = isset($field['name']) ? (string) $field['name'] : (string) $field_key;
            $is_password = isset($field['type']) && $field['type'] === 'password';
            if ($is_password || self::is_secret_field_name($name, $path)) {
                $fields[$field_key]['value'] = self::PLACEHOLDER;
            } elseif (is_array($value)) {
                $fields[$field_key]['value'] = self::redact_nested($value, $path . '.' . $name);
            }
        }

        return $fields;
    }

    /**
     * Redact secret-shaped values in a get_post_meta()/get_term_meta() map.
     *
     * The map is meta key => list of stored values, where serialized values
     * are still strings. A secret-named key has every value redacted. Other
     * values are unserialized and redacted by nested key name; a value that
     * changed is kept in its unserialized, redacted form.
     *
     * @param array $meta Meta key => list of values.
     * @return array
     */
    public static function redact_meta(array $meta)
    {
        foreach ($meta as $meta_key => $values) {
            $meta_key = (string) $meta_key;
            $list = is_array($values) ? $values : [$values];
            $secret_key = self::is_secret_field_name($meta_key, 'meta');

            foreach ($list as $index => $stored) {
                if ($stored === null || $stored === '') {
                    continue;
                }
                if ($secret_key) {
                    $list[$index] = self::PLACEHOLDER;
                    continue;
                }
                $decoded = is_string($stored) ? maybe_unserialize($stored) : $stored;
                if (is_array($decoded)) {
                    $redacted = self::redact_nested($decoded, 'meta.' . $meta_key);
                    if ($redacted !== $decoded) {
                        $list[$index] = $redacted;
                    }
                }
            }

            $meta[$meta_key] = is_array($values) ? $list : reset($list);
        }

        return $meta;
    }

    /**
     * Whether a value, at any depth, carries the redaction placeholder.
     *
     * @param mixed $value
     * @return bool
     */
    public static function contains_placeholder($value)
    {
        if ($value === self::PLACEHOLDER) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (self::contains_placeholder($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve placeholders in an imported option value against this site's value.
     *
     * Returns null when the whole option is redacted, meaning the caller must
     * leave the site's option untouched. Inside arrays a placeholder takes the
     * site's value at the same path, or is dropped when the site has none.
     *
     * @param mixed $incoming Value read from options.json.
     * @param mixed $current  The site's current value of the option.
     * @return mixed|null
     */
    public static function restore_placeholders($incoming, $current)
    {
        if ($incoming === self::PLACEHOLDER) {
            return null;
        }
        if (! is_array($incoming)) {
            return $incoming;
        }

        $current = is_array($current) ? $current : [];
        foreach ($incoming as $key => $value) {
            $existing = array_key_exists($key, $current) ? $current[$key] : null;
            if ($value === self::PLACEHOLDER) {
                if ($existing === null) {
                    unset($incoming[$key]);
                } else {
                    $incoming[$key] = $existing;
                }
            } elseif (is_array($value)) {
                $incoming[$key] = self::restore_placeholders($value, $existing);
            }
        }

        return $incoming;
    }

    /**
     * @param string $name Top-level option name.
     * @return bool
     */
    public static function is_secret_option_name($name)
    {
        $is_secret = self::name_looks_secret($name);

        /**
         * Filter whether an options.json key is treated as secret.
         *
         * @param bool   $is_secret Default classification.
         * @param string $key       Option name, or the nested array key.
         * @param string $path      Dotted path from the option name ('' at top level).
         */
        return (bool) apply_filters('dbvc_is_secret_option_key', $is_secret, $name, '');
    }

    /**
     * Classify a standalone field or meta key, such as an ACF field name.
     *
     * Uses the whole-option rule (secret words anywhere, or a secret ending),
     * because these keys name a stored value the way an option name does.
     *
     * @param string $name Field name or meta key.
     * @param string $path Dotted path of the container, e.g. the options group id.
     * @return bool
     */
    public static function is_secret_field_name($name, $path)
    {
        $is_secret = self::name_looks_secret($name);

        /** This filter is documented in is_secret_option_name(). */
        return (bool) apply_filters('dbvc_is_secret_option_key', $is_secret, $name, (string) $path);
    }

    /**
     * @param string $key  Nested array key.
     * @param string $path Dotted path of the key's parent.
     * @return bool
     */
    public static function is_secret_nested_key($key, $path)
    {
        $is_secret = (bool) preg_match('/' . self::SECRET_ENDINGS . '/i', $key);

        /** This filter is documented in is_secret_option_name(). */
        return (bool) apply_filters('dbvc_is_secret_option_key', $is_secret, $key, $path);
    }

    /**
     * The whole-value rule: a secret word anywhere, or a secret ending.
     *
     * @param string $name
     * @return bool
     */
    private static function name_looks_secret($name)
    {
        return (bool) preg_match('/(^|[_\-.])(' . self::TOP_LEVEL_WORDS . ')([_\-.]|$)/i', $name)
            || (bool) preg_match('/' . self::SECRET_ENDINGS . '/i', $name);
    }

    /**
     * @param array  $value Option value or a sub-array of it.
     * @param string $path  Dotted path of $value.
     * @return array
     */
    private static function redact_nested(array $value, $path)
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::redact_nested($child, $path . '.' . $key);
            } elseif (
                is_string($key)
                && (is_string($child) || is_int($child) || is_float($child))
                && $child !== ''
                && self::is_secret_nested_key($key, $path)
            ) {
                $value[$key] = self::PLACEHOLDER;
            }
        }

        return $value;
    }
}
