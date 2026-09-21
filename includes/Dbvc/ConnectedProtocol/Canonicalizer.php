<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Versioned canonical projection used for semantic hashes.
 *
 * Contract (version 1):
 *
 * - PHP lists (keys exactly 0..n-1 in order) encode as JSON arrays and keep
 *   their order. Ordering inside a list is treated as meaningful; nothing is
 *   sorted or de-duplicated.
 * - Every other PHP array, and every object, encodes as a JSON object whose
 *   members are sorted by key using byte-order string comparison. Integer keys
 *   become their decimal string.
 * - An empty PHP array encodes as `[]`. PHP arrays cannot distinguish an empty
 *   map from an empty list, so callers that need `{}` must pass an empty
 *   `stdClass`. This limitation is part of the profile and is documented for
 *   any non-PHP implementation.
 * - `null`, booleans and integers encode natively. A key that is present with a
 *   `null` value is therefore distinct from an absent key, an empty string, an
 *   empty list and an empty map.
 * - Floats encode with the shortest round-trip representation and always keep a
 *   fractional part (`1.0` stays `1.0`, never `1`). Non-finite floats are
 *   rejected.
 * - Strings must be valid UTF-8 and encode without escaping slashes or
 *   non-ASCII characters. Invalid UTF-8 is rejected instead of being replaced.
 * - Only the caller-supplied, documented volatile top-level keys are stripped;
 *   nothing is stripped recursively. A nested key such as `time` is kept.
 *
 * The version number is part of every hash comparison; hashes from different
 * canonicalizer versions or profiles are never comparable.
 */
final class Canonicalizer
{
    public const VERSION = 1;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Encode a value into its canonical JSON form.
     *
     * @param mixed $value
     * @return string
     * @throws CanonicalizationException
     */
    public static function encode($value)
    {
        return self::encodeValue($value, 0);
    }

    /**
     * @param string $canonical
     * @return string 64-character lowercase hexadecimal SHA-256.
     */
    public static function hash($canonical)
    {
        return hash('sha256', (string) $canonical);
    }

    /**
     * Strip documented volatile top-level keys, then encode and hash.
     *
     * @param mixed              $value
     * @param array<int, string> $volatile_top_level_keys
     * @return array{ok: bool, canonical?: string, hash?: string, version: int, reason?: string, detail?: string}
     */
    public static function project($value, array $volatile_top_level_keys = [])
    {
        if (is_array($value) && $volatile_top_level_keys !== []) {
            foreach ($volatile_top_level_keys as $key) {
                unset($value[$key]);
            }
        }

        try {
            $canonical = self::encode($value);
        } catch (CanonicalizationException $exception) {
            return [
                'ok' => false,
                'version' => self::VERSION,
                'reason' => $exception->getReason(),
                'detail' => $exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'version' => self::VERSION,
            'canonical' => $canonical,
            'hash' => self::hash($canonical),
        ];
    }

    /**
     * @param array<int|string, mixed> $value
     * @return bool
     */
    public static function isList(array $value)
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param mixed $value
     * @param int   $depth
     * @return string
     * @throws CanonicalizationException
     */
    private static function encodeValue($value, $depth)
    {
        if ($depth > 256) {
            throw new CanonicalizationException('depth_exceeded', 'Value nesting exceeds 256 levels.');
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new CanonicalizationException('non_finite_float', 'Non-finite floats cannot be canonicalized.');
            }
            $encoded = json_encode($value, self::JSON_FLAGS);
            if (! is_string($encoded)) {
                throw new CanonicalizationException('float_encoding_failed', 'Float could not be encoded.');
            }
            return $encoded;
        }

        if (is_string($value)) {
            if (! preg_match('//u', $value)) {
                throw new CanonicalizationException('invalid_utf8', 'String contains invalid UTF-8.');
            }
            $encoded = json_encode($value, self::JSON_FLAGS);
            if (! is_string($encoded)) {
                throw new CanonicalizationException('string_encoding_failed', 'String could not be encoded: ' . json_last_error_msg());
            }
            return $encoded;
        }

        if (is_array($value)) {
            if (self::isList($value)) {
                $parts = [];
                foreach ($value as $item) {
                    $parts[] = self::encodeValue($item, $depth + 1);
                }
                return '[' . implode(',', $parts) . ']';
            }

            return self::encodeMap($value, $depth);
        }

        if ($value instanceof \stdClass) {
            return self::encodeMap(get_object_vars($value), $depth);
        }

        if ($value instanceof \JsonSerializable) {
            return self::encodeValue($value->jsonSerialize(), $depth + 1);
        }

        throw new CanonicalizationException(
            'unsupported_type',
            'Unsupported value type: ' . (is_object($value) ? get_class($value) : gettype($value))
        );
    }

    /**
     * @param array<int|string, mixed> $map
     * @param int                      $depth
     * @return string
     * @throws CanonicalizationException
     */
    private static function encodeMap(array $map, $depth)
    {
        $keyed = [];
        foreach ($map as $key => $item) {
            $keyed[(string) $key] = $item;
        }

        $keys = array_keys($keyed);
        usort($keys, 'strcmp');

        $parts = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (! preg_match('//u', $key)) {
                throw new CanonicalizationException('invalid_utf8', 'Map key contains invalid UTF-8.');
            }
            $encoded_key = json_encode($key, self::JSON_FLAGS);
            if (! is_string($encoded_key)) {
                throw new CanonicalizationException('string_encoding_failed', 'Map key could not be encoded.');
            }
            $parts[] = $encoded_key . ':' . self::encodeValue($keyed[$key], $depth + 1);
        }

        return '{' . implode(',', $parts) . '}';
    }
}
