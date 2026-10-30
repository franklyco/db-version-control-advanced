<?php
/**
 * Secret redaction for the generic options.json export and import.
 *
 * options.json is written under ABSPATH, and its .htaccess deny is ignored by
 * nginx, so every value in it must be treated as publicly readable. Secret
 * shaped values are replaced with a fixed placeholder on export. On import the
 * placeholder means "keep this site's own value", so a redacted export never
 * overwrites the target's licence keys or credentials.
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
        $is_secret = (bool) preg_match('/(^|[_\-.])(' . self::TOP_LEVEL_WORDS . ')([_\-.]|$)/i', $name)
            || (bool) preg_match('/' . self::SECRET_ENDINGS . '/i', $name);

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
