<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SubmissionPackageDetector
{
    /**
     * Legacy compatibility aliases. Canonical packages should still use
     * `dbvc-ai-manifest.json`, but intake can tolerate these names.
     *
     * @var array<int,string>
     */
    private const LEGACY_MANIFEST_FILENAMES = [
        'manifest.json',
        'manifest.md',
    ];

    /**
     * @return array<int,string>
     */
    public static function get_supported_manifest_filenames(): array
    {
        return array_merge([SamplePackageBuilder::MANIFEST_FILENAME], self::LEGACY_MANIFEST_FILENAMES);
    }

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
            $basename = basename($normalized);
            if (! in_array($basename, self::get_supported_manifest_filenames(), true)) {
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
                'basename' => $basename,
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
            'manifest_basename' => (string) ($manifest_candidate['basename'] ?? ''),
            'manifest_is_canonical' => ((string) ($manifest_candidate['basename'] ?? '')) === SamplePackageBuilder::MANIFEST_FILENAME,
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
