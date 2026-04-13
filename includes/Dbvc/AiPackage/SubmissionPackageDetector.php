<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SubmissionPackageDetector
{
    /**
     * @param string $zip_path
     * @return array<string,mixed>|false
     */
    public static function inspect_uploaded_zip(string $zip_path)
    {
        if (! class_exists('\ZipArchive') || ! is_file($zip_path) || ! is_readable($zip_path)) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return false;
        }

        $entries = [];
        $manifest_candidates = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if ($name === '') {
                continue;
            }

            $normalized = self::normalize_entry_name($name);
            if ($normalized === '') {
                continue;
            }

            $entries[] = $normalized;
            if (basename($normalized) !== SamplePackageBuilder::MANIFEST_FILENAME) {
                continue;
            }

            $directory = dirname($normalized);
            if ($directory === '.') {
                $directory = '';
            }

            if ($directory !== '' && strpos($directory, '/') !== false) {
                continue;
            }

            $manifest_candidates[] = [
                'entry' => $normalized,
                'wrapper_dir' => $directory,
            ];
        }

        $zip->close();

        if (empty($manifest_candidates)) {
            return false;
        }

        usort(
            $manifest_candidates,
            static function (array $left, array $right): int {
                return strlen((string) $left['wrapper_dir']) <=> strlen((string) $right['wrapper_dir']);
            }
        );
        $manifest_candidate = $manifest_candidates[0];

        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return false;
        }

        $manifest_entry = (string) $manifest_candidate['entry'];
        $manifest_raw = $zip->getFromName($manifest_entry);
        $zip->close();

        $manifest = is_string($manifest_raw) ? json_decode($manifest_raw, true) : null;

        return [
            'detected' => true,
            'manifest_entry' => $manifest_entry,
            'wrapper_dir' => (string) $manifest_candidate['wrapper_dir'],
            'manifest_raw' => is_string($manifest_raw) ? $manifest_raw : '',
            'manifest' => is_array($manifest) ? $manifest : null,
            'package_type' => is_array($manifest) && isset($manifest['package_type']) ? (string) $manifest['package_type'] : '',
            'entries' => $entries,
        ];
    }

    /**
     * @param string $name
     * @return string
     */
    private static function normalize_entry_name(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = ltrim($name, '/');

        return trim($name);
    }
}
