<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Normalizes exact Media Library URL references from http:// to https://.
 */
final class MediaUrlHttpsNormalizer
{
    /**
     * @param int                 $attachment_id
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function normalize_attachment(int $attachment_id, array $entry = []): array
    {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return self::empty_report('invalid_attachment');
        }

        $target_url = self::target_media_url($attachment_id);
        if ($target_url === '') {
            return self::empty_report('target_url_unavailable');
        }

        $target_https = set_url_scheme($target_url, 'https');
        $candidates = self::candidate_urls($attachment_id, $target_url, $entry);
        if (empty($candidates)) {
            return self::empty_report('');
        }

        $report = self::empty_report('');
        $report['target_url'] = $target_https;
        $report['candidate_count'] = count($candidates);

        foreach ($candidates as $old_url) {
            if ($old_url === $target_https) {
                continue;
            }

            $result = self::replace_url($old_url, $target_https, $attachment_id);
            $report['replacements'] += (int) ($result['replacements'] ?? 0);
            $report['guid_updated'] += (int) ($result['guid_updated'] ?? 0);
            $report['post_rows_updated'] += (int) ($result['post_rows_updated'] ?? 0);
            $report['post_meta_rows_updated'] += (int) ($result['post_meta_rows_updated'] ?? 0);
        }

        return $report;
    }

    /**
     * @param int $attachment_id
     * @return string
     */
    private static function target_media_url(int $attachment_id): string
    {
        $relative = FileStateService::normalize_relative_path((string) get_post_meta($attachment_id, '_wp_attached_file', true));
        if ($relative !== '') {
            $uploads = wp_get_upload_dir();
            $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
            if ($baseurl !== '') {
                return trailingslashit($baseurl) . $relative;
            }
        }

        $url = wp_get_attachment_url($attachment_id);
        return is_string($url) ? $url : '';
    }

    /**
     * @param int                 $attachment_id
     * @param string              $target_url
     * @param array<string,mixed> $entry
     * @return string[]
     */
    private static function candidate_urls(int $attachment_id, string $target_url, array $entry): array
    {
        $relative_path = FileStateService::normalize_relative_path((string) ($entry['relative_path'] ?? ''));
        if ($relative_path === '') {
            $relative_path = FileStateService::normalize_relative_path((string) get_post_meta($attachment_id, '_wp_attached_file', true));
        }

        $raw_candidates = [
            set_url_scheme($target_url, 'http'),
        ];

        $guid = (string) get_post_field('guid', $attachment_id);
        if ($guid !== '') {
            $raw_candidates[] = $guid;
            $raw_candidates[] = set_url_scheme($guid, 'http');
        }

        $source_url = isset($entry['source_url']) ? (string) $entry['source_url'] : '';
        if ($source_url !== '') {
            $raw_candidates[] = $source_url;
            $raw_candidates[] = set_url_scheme($source_url, 'http');
        }

        $target_https = set_url_scheme($target_url, 'https');
        $target_http = set_url_scheme($target_url, 'http');
        $normalized = [];
        foreach ($raw_candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || $candidate === $target_https || strpos($candidate, 'http://') !== 0) {
                continue;
            }
            if ($candidate !== $target_http && ! self::url_path_matches_relative_path($candidate, $relative_path)) {
                continue;
            }
            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private static function url_path_matches_relative_path(string $url, string $relative_path): bool
    {
        if ($relative_path === '') {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }

        $path = FileStateService::normalize_relative_path(rawurldecode($path));
        $relative_path = FileStateService::normalize_relative_path($relative_path);
        if ($path === '' || $relative_path === '') {
            return false;
        }

        return substr($path, -strlen($relative_path)) === $relative_path;
    }

    /**
     * @param string $old_url
     * @param string $new_url
     * @param int    $attachment_id
     * @return array<string,int>
     */
    private static function replace_url(string $old_url, string $new_url, int $attachment_id): array
    {
        global $wpdb;

        $result = [
            'replacements' => 0,
            'guid_updated' => 0,
            'post_rows_updated' => 0,
            'post_meta_rows_updated' => 0,
        ];

        if ($old_url === '' || $new_url === '' || $old_url === $new_url) {
            return $result;
        }

        $guid = (string) get_post_field('guid', $attachment_id);
        if ($guid !== '' && strpos($guid, $old_url) !== false) {
            $updated = str_replace($old_url, $new_url, $guid, $count);
            if ($count > 0) {
                $saved = $wpdb->update(
                    $wpdb->posts,
                    ['guid' => esc_url_raw($updated)],
                    ['ID' => $attachment_id],
                    ['%s'],
                    ['%d']
                );
                if ($saved !== false) {
                    clean_post_cache($attachment_id);
                    $result['guid_updated']++;
                    $result['replacements'] += $count;
                }
            }
        }

        $like = '%' . $wpdb->esc_like($old_url) . '%';
        $post_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content, post_excerpt, post_content_filtered FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_excerpt LIKE %s OR post_content_filtered LIKE %s LIMIT 5000",
                $like,
                $like,
                $like
            ),
            ARRAY_A
        );

        foreach (is_array($post_rows) ? $post_rows : [] as $row) {
            $post_id = isset($row['ID']) ? absint($row['ID']) : 0;
            if ($post_id <= 0) {
                continue;
            }

            $changed = false;
            $postarr = ['ID' => $post_id];
            foreach (['post_content', 'post_excerpt', 'post_content_filtered'] as $field) {
                $value = (string) ($row[$field] ?? '');
                if ($value !== '' && strpos($value, $old_url) !== false) {
                    $postarr[$field] = str_replace($old_url, $new_url, $value, $count);
                    $result['replacements'] += $count;
                    $changed = $changed || $count > 0;
                }
            }

            if ($changed) {
                wp_update_post(wp_slash($postarr));
                $result['post_rows_updated']++;
            }
        }

        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 5000",
                $like
            ),
            ARRAY_A
        );

        foreach (is_array($meta_rows) ? $meta_rows : [] as $row) {
            $meta_id = isset($row['meta_id']) ? absint($row['meta_id']) : 0;
            if ($meta_id <= 0) {
                continue;
            }

            $value = isset($row['meta_value']) ? (string) $row['meta_value'] : '';
            $decoded = maybe_unserialize($value);
            $replacements = 0;
            $updated = self::replace_recursive($decoded, $old_url, $new_url, $replacements);
            if ($replacements <= 0) {
                continue;
            }

            update_metadata_by_mid('post', $meta_id, $updated);
            $result['post_meta_rows_updated']++;
            $result['replacements'] += $replacements;
        }

        return $result;
    }

    /**
     * @param mixed  $value
     * @param string $old_url
     * @param string $new_url
     * @param int    $replacements
     * @return mixed
     */
    private static function replace_recursive($value, string $old_url, string $new_url, int &$replacements)
    {
        if (is_string($value)) {
            if (strpos($value, $old_url) === false) {
                return $value;
            }

            $updated = str_replace($old_url, $new_url, $value, $count);
            $replacements += (int) $count;
            return $updated;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::replace_recursive($item, $old_url, $new_url, $replacements);
            }
            return $value;
        }

        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    private static function empty_report(string $reason): array
    {
        return [
            'reason' => $reason,
            'target_url' => '',
            'candidate_count' => 0,
            'replacements' => 0,
            'guid_updated' => 0,
            'post_rows_updated' => 0,
            'post_meta_rows_updated' => 0,
        ];
    }
}
