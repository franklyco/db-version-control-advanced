<?php

namespace Dbvc\Connected\Preparation;

/**
 * Portable tokens for post meta that stores an attachment BY ID — ACF image,
 * file and gallery fields, and any key a site designates through the
 * `dbvc_connected_post_media_meta_keys` filter (2026-11-20 real-pair dry run:
 * `intro_section_image` 84137 → 84291 read as a content change on a page whose
 * `_thumbnail_id` was already media-tokenized).
 *
 * {@see MediaReferences} rewrites `_thumbnail_id` and the string forms a post
 * body carries (`wp-image-<id>`, upload URLs); {@see BricksMediaReferences}
 * rewrites a Bricks image control's structured `{id, url, …}` object. Neither
 * can know that an arbitrary meta key holds an attachment id, so this helper
 * learns the keys at projection time — from the ACF field reference the sibling
 * `_<key>` meta carries (`field_…` → `acf_get_field()` → type image/file/gallery)
 * or from the filter — and rewrites every positive id under those keys to a
 * content-hash token (`dbvc-media-id:<sha256hex>`). The token is deliberately
 * self-describing ("this value is an attachment id"), with a prefix distinct
 * from MediaReferences' `dbvc-media:` (which detokenizes a bare token to a URL),
 * so apply needs NO field knowledge: it resolves every such token in the body to
 * this site's attachment id by content hash. A token the target cannot resolve
 * is left literal so the guarded re-snapshot fails closed rather than storing a
 * broken id. The bytes ride the same media channel as post media
 * ({@see MediaReferences::collect_for_hashes}).
 *
 * Idempotent and identity-independent: two sites holding the same bytes under
 * the same field project the same token, whatever their attachment ids.
 */
final class MetaMediaReferences
{
    public const TOKEN_PREFIX = 'dbvc-media-id:';

    /** ACF field types whose stored value is an attachment id (or a list of ids). */
    public const ACF_MEDIA_TYPES = ['image', 'file', 'gallery'];

    /**
     * The meta keys of a post body that store attachment ids: ACF image/file/
     * gallery fields discovered through their `_<key>` field reference (when ACF
     * is loaded) plus the keys the filter designates. Keys only; the value shape
     * (one id, `[id]`, a gallery's `[[id, id]]`) is handled uniformly by tokenize.
     *
     * @param array<string, mixed> $meta      Post meta as projected (`key => [values]`).
     * @param string               $post_type
     * @return array<int, string>
     */
    public static function media_meta_keys(array $meta, $post_type)
    {
        $keys = [];
        if (function_exists('acf_get_field')) {
            foreach (array_keys($meta) as $key) {
                $key = (string) $key;
                if ($key === '' || $key[0] === '_' || ! array_key_exists('_' . $key, $meta)) {
                    continue;
                }
                $field_key = self::first_field_key($meta['_' . $key]);
                if ($field_key === '') {
                    continue;
                }
                $field = acf_get_field($field_key);
                $type = is_array($field) && isset($field['type']) ? (string) $field['type'] : '';
                if (in_array($type, self::ACF_MEDIA_TYPES, true)) {
                    $keys[] = $key;
                }
            }
        }

        /**
         * Post meta keys whose value is an attachment id (or a list of ids) and
         * must therefore travel as a content-hash token. ACF image/file/gallery
         * fields are discovered automatically; name other keys here.
         *
         * @param array<int, string> $keys
         * @param string             $post_type
         */
        $keys = (array) apply_filters('dbvc_connected_post_media_meta_keys', $keys, (string) $post_type);

        return array_values(array_unique(array_filter(array_map('strval', $keys), static function ($key) {
            return $key !== '' && $key !== '_thumbnail_id'; // _thumbnail_id is MediaReferences' (its token shape predates this helper).
        })));
    }

    /**
     * Rewrite every resolvable attachment id under the given meta keys to a
     * content-hash token. An id this site cannot hash is left literal (it is
     * content, not managed media); any other value shape is left alone.
     *
     * @param array<string, mixed> $body
     * @param array<int, string>   $keys From {@see media_meta_keys()}.
     * @return array<string, mixed>
     */
    public static function tokenize(array $body, array $keys)
    {
        if ($keys === [] || ! isset($body['meta']) || ! is_array($body['meta'])) {
            return $body;
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $body['meta'])) {
                continue;
            }
            $body['meta'][$key] = self::map_ids($body['meta'][$key], static function ($id) {
                $hex = MediaReferences::content_hash((int) $id);

                return $hex !== '' ? self::TOKEN_PREFIX . $hex : $id;
            });
        }

        return $body;
    }

    /**
     * Resolve every `dbvc-media-id:` token in the body's meta to this site's own
     * attachment id (by content hash). Self-describing, so no field knowledge is
     * needed on the target. A token this site cannot resolve is left literal so
     * the guarded write fails verification rather than storing a broken id.
     *
     * The id is written as a string where the token sits directly in a meta
     * value list (WordPress stores scalar meta as strings) and as an int deeper
     * inside a structure (a gallery's unserialized id list); both re-tokenize to
     * the same token, so either shape verifies.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function detokenize(array $body)
    {
        if (! isset($body['meta']) || ! is_array($body['meta'])) {
            return $body;
        }
        $map = [];
        foreach (self::tokens_in_body($body) as $hex) {
            $local = MediaReferences::local_by_hash($hex);
            if ($local > 0) {
                $map[$hex] = $local;
            }
        }
        if ($map === []) {
            return $body;
        }
        foreach ($body['meta'] as $key => $value) {
            $body['meta'][$key] = self::walk($value, static function ($leaf, $depth) use ($map) {
                $hex = self::hash_of($leaf);
                if ($hex === '' || ! isset($map[$hex])) {
                    return $leaf;
                }

                return $depth <= 1 ? (string) $map[$hex] : $map[$hex];
            }, 0);
        }

        return $body;
    }

    /**
     * Distinct content hashes the body's meta references through this helper's tokens.
     *
     * @param array<string, mixed> $body
     * @return array<int, string>
     */
    public static function tokens_in_body(array $body)
    {
        $hashes = [];
        if (isset($body['meta']) && is_array($body['meta'])) {
            self::walk($body['meta'], static function ($leaf) use (&$hashes) {
                $hex = self::hash_of($leaf);
                if ($hex !== '') {
                    $hashes[$hex] = true;
                }

                return $leaf;
            }, -1);
        }

        return array_keys($hashes);
    }

    /**
     * The sha256 hex a `dbvc-media-id:` token carries, or '' when $value is not one.
     *
     * @param mixed $value
     * @return string
     */
    public static function hash_of($value)
    {
        if (! is_string($value) || strpos($value, self::TOKEN_PREFIX) !== 0) {
            return '';
        }
        $hex = substr($value, strlen(self::TOKEN_PREFIX));

        return preg_match('/^[a-f0-9]{64}$/', $hex) ? $hex : '';
    }

    /**
     * Apply $fn to every positive-integer-like leaf (int, or digit string) of a
     * meta value, preserving the surrounding structure. Existing tokens and any
     * other leaf are left as they are.
     *
     * @param mixed    $value
     * @param callable $fn
     * @return mixed
     */
    private static function map_ids($value, callable $fn)
    {
        return self::walk($value, static function ($leaf) use ($fn) {
            if (is_int($leaf) && $leaf > 0) {
                return $fn($leaf);
            }
            if (is_string($leaf) && $leaf !== '' && ctype_digit($leaf) && (int) $leaf > 0) {
                return $fn((int) $leaf);
            }

            return $leaf;
        }, 0);
    }

    /**
     * @param mixed    $node
     * @param callable $fn    fn($leaf, int $depth): mixed
     * @param int      $depth
     * @return mixed
     */
    private static function walk($node, callable $fn, $depth)
    {
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $node[$key] = self::walk($child, $fn, $depth + 1);
            }

            return $node;
        }

        return $fn($node, $depth);
    }

    /**
     * The `field_…` reference an ACF `_<key>` meta carries, or ''.
     *
     * @param mixed $value
     * @return string
     */
    private static function first_field_key($value)
    {
        foreach (is_array($value) ? $value : [$value] as $candidate) {
            if (is_scalar($candidate)) {
                $candidate = trim((string) $candidate);
                if (strpos($candidate, 'field_') === 0) {
                    return $candidate;
                }
            }
        }

        return '';
    }
}
