<?php

namespace Dbvc\Connected\Apply;

use Dbvc\Connected\Preparation\MediaReferences;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Materialize a release's carried media (M7 step 3b): make every referenced
 * attachment resolvable on this target so apply's detokenize can map each
 * content-hash token to a local id/URL.
 *
 * For each carried entry the target either **reuses** a byte-identical local
 * attachment (matched by content hash — never a duplicate) or **sideloads** a
 * new one from the carried bytes, stamping the content hash
 * (`_dbvc_media_hash`) so `MediaReferences::local_by_hash()` and the projection
 * resolve it. The bytes are re-hashed against the declared hash before anything
 * is written, and WordPress's own upload/type checks gate the file — the target
 * trusts nothing about the payload beyond what it can verify. Ids this run
 * *created* are returned so the caller can journal them (for step-4 rollback)
 * and discard them if the guarded write does not verify.
 */
final class MediaMaterializer
{
    /**
     * @param array<int, array<string, mixed>> $media Carried descriptors with base64 `bytes`.
     * @return array{created: array<int,int>, reused: array<int,int>, errors: array<string,string>}
     */
    public static function materialize(array $media)
    {
        $created = [];
        $reused = [];
        $errors = [];

        foreach ($media as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $hash = self::hex((string) ($entry['hash'] ?? ''));
            if ($hash === '') {
                continue;
            }
            $existing = MediaReferences::local_by_hash($hash);
            if ($existing > 0) {
                $reused[$hash] = $existing;
                continue;
            }
            $bytes = is_string($entry['bytes'] ?? null) ? base64_decode((string) $entry['bytes'], true) : false;
            if ($bytes === false || $bytes === '') {
                $errors[$hash] = 'invalid_bytes';
                continue;
            }
            if (strlen($bytes) > Protocol::MAX_MEDIA_FILE_BYTES) {
                $errors[$hash] = 'too_large';
                continue;
            }
            if (hash('sha256', $bytes) !== $hash) {
                $errors[$hash] = 'hash_mismatch';
                continue;
            }
            $id = self::sideload($entry, $bytes, $hash);
            if ($id <= 0) {
                $errors[$hash] = 'sideload_failed';
                continue;
            }
            $created[$hash] = $id;
        }

        return ['created' => $created, 'reused' => $reused, 'errors' => $errors];
    }

    /**
     * Delete attachments this run created (a failed/compensated apply must not
     * leave orphans). Reused attachments are never touched.
     *
     * @param array<int, int> $ids
     * @return void
     */
    public static function discard(array $ids)
    {
        foreach ($ids as $id) {
            if ((int) $id > 0 && get_post_type((int) $id) === 'attachment') {
                wp_delete_attachment((int) $id, true);
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param string               $bytes
     * @param string               $hash
     * @return int New attachment id, or 0.
     */
    private static function sideload(array $entry, $bytes, $hash)
    {
        $filename = self::safe_filename($entry, $hash);
        // WordPress rejects a disallowed extension here, so the payload can only
        // land as an allowed upload type.
        $upload = wp_upload_bits($filename, null, $bytes);
        if (! is_array($upload) || ! empty($upload['error']) || empty($upload['file'])) {
            return 0;
        }
        $file = (string) $upload['file'];
        $filetype = wp_check_filetype($file);
        if (empty($filetype['type'])) {
            @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return 0;
        }

        $attachment = [
            'post_mime_type' => (string) $filetype['type'],
            'post_title' => self::title($entry, $filename),
            'post_excerpt' => isset($entry['caption']) ? sanitize_text_field((string) $entry['caption']) : '',
            'post_status' => 'inherit',
        ];
        $id = wp_insert_attachment($attachment, $file, 0, true);
        if (is_wp_error($id) || (int) $id <= 0) {
            @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return 0;
        }
        $id = (int) $id;

        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        $metadata = wp_generate_attachment_metadata($id, $file);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($id, $metadata);
        }

        // Content-hash identity so local_by_hash() and the projection resolve it;
        // the base plugin's identity meta is stamped too for cross-tool dedup.
        update_post_meta($id, MediaReferences::HASH_META, $hash);
        if ((int) ($entry['source_id'] ?? 0) > 0) {
            update_post_meta($id, '_dbvc_original_attachment_id', (int) $entry['source_id']);
        }
        if (isset($entry['url']) && (string) $entry['url'] !== '') {
            update_post_meta($id, '_dbvc_original_source_url', esc_url_raw((string) $entry['url']));
        }
        if (isset($entry['alt']) && (string) $entry['alt'] !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $entry['alt']));
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $entry
     * @param string               $hash
     * @return string
     */
    private static function safe_filename(array $entry, $hash)
    {
        $filename = isset($entry['filename']) ? sanitize_file_name((string) $entry['filename']) : '';
        if ($filename !== '' && strpos($filename, '.') !== false) {
            return $filename;
        }
        $ext = '';
        $mime = isset($entry['mime']) ? (string) $entry['mime'] : '';
        if ($mime !== '' && function_exists('wp_get_default_extension_for_mime_type')) {
            $ext = (string) wp_get_default_extension_for_mime_type($mime);
        }
        if ($ext === '' && strpos($mime, '/') !== false) {
            $ext = substr($mime, strpos($mime, '/') + 1);
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext));

        return 'dbvc-media-' . substr($hash, 0, 16) . ($ext !== '' ? '.' . $ext : '');
    }

    /**
     * @param array<string, mixed> $entry
     * @param string               $filename
     * @return string
     */
    private static function title(array $entry, $filename)
    {
        $title = isset($entry['title']) ? sanitize_text_field((string) $entry['title']) : '';

        return $title !== '' ? $title : pathinfo($filename, PATHINFO_FILENAME);
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
}
