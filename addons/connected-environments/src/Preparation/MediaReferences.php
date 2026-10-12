<?php

namespace Dbvc\Connected\Preparation;

use Dbvc\ConnectedProtocol\Protocol;

/**
 * Portable attachment references for the `wp.service` domain.
 *
 * A post names its attachments by *local* ids (`_thumbnail_id`, a
 * `wp-image-<id>` class) and *local* URLs — both meaningless on another site.
 * So the canonical projection replaces every resolvable reference with a
 * portable token that carries only the attachment's content hash
 * (`dbvc-media:<sha256hex>`); apply reverses it to this site's own ids/URLs
 * before the post is written. Because the token is content-addressed, two
 * sites holding the same image bytes project the *same* hash — so Compare and
 * the pair baseline read a media-bearing post as synchronized once its media
 * has landed, and the fingerprint-guarded convergence check still holds.
 *
 * The tokenize/detokenize round trip is lossless: tokenize (live post →
 * canonical) resolves each reference to a local attachment and records its
 * content hash on the attachment (`_dbvc_media_hash`); detokenize (canonical →
 * live post) maps each hash back to the local attachment that holds it. An
 * unresolvable reference is left literal — it is content, not managed media.
 * This class creates nothing; sideloading missing bytes is M7 step 3b.
 */
final class MediaReferences
{
    public const TOKEN_PREFIX = 'dbvc-media:';
    public const HASH_META = '_dbvc_media_hash';

    /**
     * Canonical projection of a service body: resolvable media references
     * replaced by content-hash tokens. Idempotent and deterministic.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function tokenize(array $body)
    {
        if (isset($body['meta']['_thumbnail_id'])) {
            $body['meta']['_thumbnail_id'] = self::tokenize_thumbnail($body['meta']['_thumbnail_id']);
        }
        if (isset($body['post_content'])) {
            $body['post_content'] = self::tokenize_string((string) $body['post_content']);
        }
        if (isset($body['meta']) && is_array($body['meta'])) {
            $body['meta'] = self::walk_strings($body['meta'], [self::class, 'tokenize_string']);
        }

        return $body;
    }

    /**
     * Reverse of tokenize(): every token becomes this site's own attachment id
     * or URL, looked up by content hash. A token this site cannot resolve is
     * left as-is (the guarded write then fails verification rather than storing
     * a broken reference).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function detokenize(array $body)
    {
        $map = self::local_map(self::tokens_in_body($body));
        if (isset($body['meta']['_thumbnail_id'])) {
            $body['meta']['_thumbnail_id'] = self::detokenize_thumbnail($body['meta']['_thumbnail_id'], $map);
        }
        if (isset($body['post_content'])) {
            $body['post_content'] = self::detokenize_string((string) $body['post_content'], $map);
        }
        if (isset($body['meta']) && is_array($body['meta'])) {
            $body['meta'] = self::walk_strings($body['meta'], static function ($value) use ($map) {
                return self::detokenize_string($value, $map);
            });
        }

        return $body;
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
        $collect = static function ($value) use (&$hashes) {
            if (is_string($value) && preg_match_all('/' . preg_quote(self::TOKEN_PREFIX, '/') . '([0-9a-f]{64})/', $value, $matches)) {
                foreach ($matches[1] as $hash) {
                    $hashes[$hash] = true;
                }
            }
        };
        $collect($body['meta']['_thumbnail_id'] ?? null);
        self::walk_strings(['c' => $body['post_content'] ?? '', 'm' => $body['meta'] ?? []], static function ($value) use ($collect) {
            $collect($value);
            return $value;
        });

        return array_keys($hashes);
    }

    /**
     * The local attachment id holding a given content hash, or 0. Backed by the
     * `_dbvc_media_hash` meta that tokenize() and descriptor() record.
     *
     * @param string $hash sha256 hex.
     * @return int
     */
    public static function local_by_hash($hash)
    {
        $hash = self::hex($hash);
        if ($hash === '' || ! function_exists('get_posts')) {
            return 0;
        }
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'meta_key' => self::HASH_META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery
            'posts_per_page' => 1,
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        return $ids !== [] ? (int) $ids[0] : 0;
    }

    /**
     * The raw file bytes of a local attachment, or null when unreadable.
     *
     * @param int $attachment_id
     * @return string|null
     */
    public static function read_bytes($attachment_id)
    {
        $path = function_exists('get_attached_file') ? get_attached_file((int) $attachment_id) : '';
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }
        $bytes = file_get_contents($path);

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * Portable descriptor for a local attachment (the byte channel fills the
     * `bytes` in M7 step 2). `hash` is null when the file is unreadable; when it
     * is readable the content hash is cached on the attachment for later lookup.
     *
     * @param int $attachment_id
     * @return array<string, mixed>|null
     */
    public static function descriptor($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return null;
        }
        $hex = self::attachment_hash($attachment_id);
        $path = function_exists('get_attached_file') ? get_attached_file($attachment_id) : '';
        $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($attachment_id) : '';
        $post = get_post($attachment_id);

        return [
            'source_id' => $attachment_id,
            'hash' => $hex !== '' ? 'sha256:' . $hex : null,
            'mime' => (string) get_post_mime_type($attachment_id),
            'filename' => is_string($path) && $path !== '' ? basename((string) $path) : ($url !== '' ? basename((string) wp_parse_url($url, PHP_URL_PATH)) : ''),
            'relpath' => self::relative_upload_path(is_string($path) ? $path : ''),
            'filesize' => is_string($path) && $path !== '' && is_readable($path) ? (int) filesize($path) : 0,
            'url' => $url,
            'title' => $post instanceof \WP_Post ? (string) $post->post_title : '',
            'alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'caption' => $post instanceof \WP_Post ? (string) $post->post_excerpt : '',
        ];
    }

    /**
     * Gather the transportable media bundle for a *tokenized* service body on
     * the source: a descriptor plus inline base64 bytes for every referenced
     * attachment, under the protocol's per-file, per-item-total and count caps.
     * Anything unreadable or over a cap is `deferred` (descriptor kept, bytes
     * not) — never silently dropped.
     *
     * @param array<string, mixed> $body Tokenized canonical body.
     * @return array{media: array<int, array<string, mixed>>, deferred: array<int, array<string, mixed>>}
     */
    public static function collect_for_transport(array $body)
    {
        $media = [];
        $deferred = [];
        $total = 0;

        foreach (self::tokens_in_body($body) as $hash) {
            $ref = self::TOKEN_PREFIX . $hash;
            $local = self::local_by_hash($hash);
            if ($local <= 0) {
                $deferred[] = ['ref' => $ref, 'hash' => 'sha256:' . $hash, 'reason' => 'source_missing'];
                continue;
            }
            $descriptor = self::descriptor($local);
            if ($descriptor === null || $descriptor['hash'] === null) {
                $deferred[] = ['ref' => $ref, 'hash' => 'sha256:' . $hash, 'reason' => 'unreadable'];
                continue;
            }
            if ((int) $descriptor['filesize'] > Protocol::MAX_MEDIA_FILE_BYTES) {
                $deferred[] = ['ref' => $ref, 'hash' => $descriptor['hash'], 'reason' => 'too_large'];
                continue;
            }
            if (count($media) >= Protocol::MAX_MEDIA_ITEMS) {
                $deferred[] = ['ref' => $ref, 'hash' => $descriptor['hash'], 'reason' => 'too_many'];
                continue;
            }
            $bytes = self::read_bytes($local);
            if ($bytes === null || 'sha256:' . hash('sha256', $bytes) !== $descriptor['hash']) {
                $deferred[] = ['ref' => $ref, 'hash' => $descriptor['hash'], 'reason' => 'unreadable'];
                continue;
            }
            if ($total + strlen($bytes) > Protocol::MAX_MEDIA_TOTAL_BYTES) {
                $deferred[] = ['ref' => $ref, 'hash' => $descriptor['hash'], 'reason' => 'total_exceeded'];
                continue;
            }
            $total += strlen($bytes);
            $descriptor['bytes'] = base64_encode($bytes);
            $descriptor['refs'] = [$ref];
            $media[] = $descriptor;
        }

        return ['media' => $media, 'deferred' => $deferred];
    }

    /**
     * @param mixed $value _thumbnail_id meta value (scalar or single-element array).
     * @return mixed
     */
    private static function tokenize_thumbnail($value)
    {
        $is_array = is_array($value);
        $id = self::first_scalar($value);
        if ($id === null || (int) $id <= 0) {
            return $value;
        }
        $hex = self::attachment_hash((int) $id);
        if ($hex === '') {
            return $value;
        }
        $token = self::TOKEN_PREFIX . $hex;

        return $is_array ? [$token] : $token;
    }

    /**
     * @param mixed                          $value
     * @param array<string, array{id:int,url:string}> $map
     * @return mixed
     */
    private static function detokenize_thumbnail($value, array $map)
    {
        $is_array = is_array($value);
        $token = self::first_scalar($value);
        if (! is_string($token) || strpos($token, self::TOKEN_PREFIX) !== 0) {
            return $value;
        }
        $hex = substr($token, strlen(self::TOKEN_PREFIX));
        if (! isset($map[$hex])) {
            return $value;
        }
        $id = (string) $map[$hex]['id'];

        return $is_array ? [$id] : $id;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function tokenize_string($value)
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        // Inline wp-image-<id> classes.
        $value = preg_replace_callback('/wp-image-(\d+)/', static function ($m) {
            $hex = self::attachment_hash((int) $m[1]);
            return $hex !== '' ? 'wp-image-' . self::TOKEN_PREFIX . $hex : $m[0];
        }, $value);
        // Upload URLs.
        $value = preg_replace_callback('#https?://[^\s"\'<>]+/wp-content/uploads/[^\s"\'<>]+#', static function ($m) {
            $id = function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($m[0]) : 0;
            if ($id <= 0 || get_post_type($id) !== 'attachment') {
                return $m[0];
            }
            $hex = self::attachment_hash($id);
            return $hex !== '' ? self::TOKEN_PREFIX . $hex : $m[0];
        }, $value);

        return $value;
    }

    /**
     * @param mixed                          $value
     * @param array<string, array{id:int,url:string}> $map
     * @return mixed
     */
    private static function detokenize_string($value, array $map)
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        // wp-image-<token> -> the local id.
        $value = preg_replace_callback('/wp-image-' . preg_quote(self::TOKEN_PREFIX, '/') . '([0-9a-f]{64})/', static function ($m) use ($map) {
            return isset($map[$m[1]]) ? 'wp-image-' . $map[$m[1]]['id'] : $m[0];
        }, $value);
        // Bare token (a URL) -> the local URL. The lookbehind avoids the wp-image form.
        $value = preg_replace_callback('/(?<!wp-image-)' . preg_quote(self::TOKEN_PREFIX, '/') . '([0-9a-f]{64})/', static function ($m) use ($map) {
            return isset($map[$m[1]]) ? $map[$m[1]]['url'] : $m[0];
        }, $value);

        return $value;
    }

    /**
     * hash => {id, url} for the hashes this site holds locally.
     *
     * @param array<int, string> $hashes
     * @return array<string, array{id:int,url:string}>
     */
    private static function local_map(array $hashes)
    {
        $map = [];
        foreach ($hashes as $hash) {
            $id = self::local_by_hash($hash);
            if ($id > 0) {
                $map[$hash] = ['id' => $id, 'url' => function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : ''];
            }
        }

        return $map;
    }

    /**
     * The content hash of a local attachment (sha256 hex), cached on the
     * attachment as `_dbvc_media_hash`. Empty string when unreadable.
     *
     * @param int $attachment_id
     * @return string
     */
    private static function attachment_hash($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return '';
        }
        $cached = self::hex((string) get_post_meta($attachment_id, self::HASH_META, true));
        if ($cached !== '') {
            return $cached;
        }
        $path = function_exists('get_attached_file') ? get_attached_file($attachment_id) : '';
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return '';
        }
        $hex = (string) hash_file('sha256', $path);
        update_post_meta($attachment_id, self::HASH_META, $hex);

        return $hex;
    }

    /**
     * @param mixed $value
     * @param callable $fn
     * @return array<string, mixed>
     */
    private static function walk_strings($value, $fn)
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::walk_strings($child, $fn);
            } elseif (is_string($child)) {
                $value[$key] = $fn($child);
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string|int|float|bool|null
     */
    private static function first_scalar($value)
    {
        while (is_array($value)) {
            if ($value === []) {
                return null;
            }
            $value = reset($value);
        }

        return is_scalar($value) ? $value : null;
    }

    /**
     * @param string $hash sha256 hex, optionally `sha256:`-prefixed.
     * @return string bare lowercase hex, or '' if not a sha256 hex.
     */
    private static function hex($hash)
    {
        $hash = strpos((string) $hash, ':') !== false ? substr((string) $hash, strpos((string) $hash, ':') + 1) : (string) $hash;

        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }

    /**
     * @param string $path
     * @return string
     */
    private static function relative_upload_path($path)
    {
        if (! is_string($path) || $path === '' || ! function_exists('wp_get_upload_dir')) {
            return '';
        }
        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ($basedir !== '' && strpos($path, $basedir) === 0) {
            return ltrim(substr($path, strlen($basedir)), '/');
        }

        return basename($path);
    }
}
