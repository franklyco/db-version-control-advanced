<?php

namespace Dbvc\ConnectedProtocol;

/**
 * The one rule for rendering an object value inside a prepare receipt or a
 * review surface (CR-1). The connector applies it before a value leaves the
 * target (`before_value` / `after_value` on a changed path); the hub applies
 * the same rule when it enriches a receipt with values from a stored payload.
 * Keeping it here means both sides redact and truncate identically, so a
 * reviewer never sees more on one side than the other.
 *
 * - A path with a secret-looking segment (password, token, api key, nonce,
 *   credential, auth, private key) renders as `[redacted]`, whatever the value.
 * - Scalars render as text, booleans as `true`/`false`, an absent value as
 *   `(absent)`; structures render as compact JSON.
 * - Whitespace collapses and anything over {@see MAX_VALUE_CHARS} is cut with
 *   a `… (n chars)` marker, so a CSS blob or an element tree never bloats a
 *   receipt. The full value stays on the site that owns it.
 *
 * Pure: no WordPress, no I/O.
 */
final class ReceiptValues
{
    public const MAX_VALUE_CHARS = 200;
    public const REDACTED = '[redacted]';
    public const ABSENT = '(absent)';
    public const SECRET_SEGMENT_PATTERN = '/pass(word)?|secret|token|api[-_]?key|private[-_]?key|nonce|credential|(^|_)auth($|_)/';

    /**
     * @param string $path Dot-path; every segment is tested.
     * @return bool
     */
    public static function is_secret_path($path)
    {
        foreach (explode('.', strtolower((string) $path)) as $segment) {
            if (preg_match(self::SECRET_SEGMENT_PATTERN, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed  $value
     * @param string $path
     * @param bool   $present False when the path does not exist on this side.
     * @return string
     */
    public static function format($value, $path, $present = true)
    {
        if (self::is_secret_path($path)) {
            return self::REDACTED;
        }
        if (! $present || $value === null) {
            return self::ABSENT;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            $string = (string) $value;
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $string = is_string($encoded) ? $encoded : '';
        }
        $string = trim((string) preg_replace('/\s+/', ' ', $string));
        if (mb_strlen($string) > self::MAX_VALUE_CHARS) {
            return mb_substr($string, 0, self::MAX_VALUE_CHARS) . '… (' . mb_strlen($string) . ' chars)';
        }

        return $string;
    }
}
