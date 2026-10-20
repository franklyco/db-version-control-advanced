<?php

namespace Dbvc\Connected\Preparation;

/**
 * Portable, identity-independent tokens for the media an element tree references
 * by STRUCTURE (M9 slice 6d follow-up). A Bricks image control stores an
 * attachment as an object — `{id, url, filename, size, useDynamicData?}` — where
 * `id`/`url`/`filename` are site-local. {@see MediaReferences} only rewrites
 * string references (`wp-image-<id>`, upload URLs, `_thumbnail_id`), so it never
 * sees a structured `settings.image.id`; this helper does.
 *
 * It rewrites each image object's `id` to a content-hash token
 * (`dbvc-bmedia:<sha256hex>`) and drops the site-local `url`/`filename`, keeping
 * the portable `size`/`useDynamicData`, so the projection hashes the same on
 * every site holding the same bytes — exactly as M7 did for posts and M9 6c did
 * for class references. A DELIBERATELY DISTINCT token prefix (`dbvc-bmedia:` vs
 * MediaReferences' `dbvc-media:`) keeps the string-based media detokenize, which
 * runs first on a post body, from rewriting a structured id into a URL. At apply
 * the connector resolves each token back to its own attachment id (by content
 * hash, after the bytes are materialized) and reconstructs the local url/filename.
 * An unresolvable reference is left literal so the guarded write fails closed
 * rather than storing a broken id. The bytes ride the same media channel as
 * post media ({@see MediaReferences::collect_for_hashes}).
 */
final class BricksMediaReferences
{
    public const TOKEN_PREFIX = 'dbvc-bmedia:';

    /**
     * Rewrite every structured image reference to a content-hash token.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function tokenize(array $body)
    {
        return self::walk($body, static function (array $image) {
            $id = isset($image['id']) && is_scalar($image['id']) ? (int) $image['id'] : 0;
            if ($id <= 0) {
                return $image; // already a token, external, or empty — leave as-is.
            }
            $hex = MediaReferences::content_hash($id);
            if ($hex === '') {
                return $image; // unresolvable locally → leave literal; the ledger/verify reports it.
            }
            $image['id'] = self::TOKEN_PREFIX . $hex;
            // url + filename are site-local and redundant with the id; drop them so
            // two sites holding the same bytes project an identical image object.
            unset($image['url'], $image['filename']);

            return $image;
        });
    }

    /**
     * Resolve every structured image token back to this site's attachment id and
     * reconstruct its local url/filename. A token this site cannot resolve is left
     * literal so the guarded re-snapshot fails rather than storing a broken id.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function detokenize(array $body)
    {
        return self::walk($body, static function (array $image) {
            $token = isset($image['id']) && is_string($image['id']) ? $image['id'] : '';
            $hex = self::hash_of($token);
            if ($hex === '') {
                return $image;
            }
            $local = MediaReferences::local_by_hash($hex);
            if ($local <= 0) {
                return $image; // leave the token literal → verification fails closed.
            }
            $image['id'] = $local;
            $url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($local) : '';
            if (is_string($url) && $url !== '') {
                $image['url'] = $url;
            }
            $file = function_exists('get_attached_file') ? get_attached_file($local) : '';
            if (is_string($file) && $file !== '') {
                $image['filename'] = basename($file);
            }

            return $image;
        });
    }

    /**
     * Distinct content hashes referenced by a tokenized body.
     *
     * @param array<string, mixed> $body
     * @return array<int, string>
     */
    public static function tokens_in_body(array $body)
    {
        $hashes = [];
        self::walk($body, static function (array $image) use (&$hashes) {
            $hex = self::hash_of(isset($image['id']) && is_string($image['id']) ? $image['id'] : '');
            if ($hex !== '') {
                $hashes[$hex] = true;
            }

            return $image;
        });

        return array_keys($hashes);
    }

    /**
     * Transportable media bundle (descriptor + base64 bytes) for a tokenized body,
     * under the same protocol caps as post media.
     *
     * @param array<string, mixed> $body Tokenized body.
     * @return array{media: array<int, array<string, mixed>>, deferred: array<int, array<string, mixed>>}
     */
    public static function collect_for_transport(array $body)
    {
        return MediaReferences::collect_for_hashes(self::tokens_in_body($body));
    }

    /**
     * The sha256 hex a `dbvc-bmedia:` token carries, or '' when $value is not one.
     *
     * @param string $value
     * @return string
     */
    private static function hash_of($value)
    {
        if (! is_string($value) || strpos($value, self::TOKEN_PREFIX) !== 0) {
            return '';
        }
        $hex = substr($value, strlen(self::TOKEN_PREFIX));

        return preg_match('/^[a-f0-9]{64}$/', $hex) ? $hex : '';
    }

    /**
     * Recurse the tree; every node that looks like a Bricks image control is
     * passed to $fn (and treated as a leaf — image controls do not nest media),
     * everything else is recursed. $fn returns the replacement node.
     *
     * @param mixed    $node
     * @param callable $fn
     * @return mixed
     */
    private static function walk($node, callable $fn)
    {
        if (! is_array($node)) {
            return $node;
        }
        if (self::is_image_control($node)) {
            return $fn($node);
        }
        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = self::walk($value, $fn);
        }

        return $out;
    }

    /**
     * A Bricks image control value: an object carrying an attachment id (an int,
     * or an already-tokenized string) together with at least one image-control
     * marker (`size`, `filename`, or an uploads `url`). The marker requirement
     * keeps non-media controls that merely carry an `id` (links, post selectors)
     * from being mistaken for media.
     *
     * @param array<string, mixed> $node
     * @return bool
     */
    private static function is_image_control(array $node)
    {
        $id = $node['id'] ?? null;
        $has_id = (is_int($id) && $id > 0)
            || (is_string($id) && (self::hash_of($id) !== '' || ctype_digit($id)));
        if (! $has_id) {
            return false;
        }

        return array_key_exists('size', $node)
            || array_key_exists('filename', $node)
            || (isset($node['url']) && is_string($node['url'])
                && (strpos($node['url'], '/uploads/') !== false
                    // MediaReferences::tokenize runs first on a post body and may have
                    // already rewritten this object's url to a content-hash token.
                    || strpos($node['url'], MediaReferences::TOKEN_PREFIX) === 0));
    }
}
