<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_Storage_Service
{
    /**
     * @var DBVC_CC_V2_Package_Storage_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Package_Storage_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $domain
     * @return array<int, array<string, mixed>>
     */
    public function list_builds_by_domain($domain)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return [];
        }

        $history = $this->read_history_payload($context);
        $items = isset($history['builds']) && is_array($history['builds']) ? $history['builds'] : [];

        usort(
            $items,
            static function ($left, $right) {
                $left_time = isset($left['built_at']) ? strtotime((string) $left['built_at']) : 0;
                $right_time = isset($right['built_at']) ? strtotime((string) $right['built_at']) : 0;
                if ($left_time === $right_time) {
                    return ((int) ($right['build_seq'] ?? 0)) <=> ((int) ($left['build_seq'] ?? 0));
                }

                return $right_time <=> $left_time;
            }
        );

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>
     */
    public function read_history_payload(array $context)
    {
        $history = $this->read_json_file($context['package_builds_file']);
        $items = isset($history['builds']) && is_array($history['builds']) ? array_values(array_filter($history['builds'], 'is_array')) : [];

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'package-builds.v1',
            'domain' => isset($context['domain']) ? (string) $context['domain'] : '',
            'generated_at' => isset($history['generated_at']) ? (string) $history['generated_at'] : '',
            'builds' => $items,
        ];
    }

    /**
     * @param array<string, string> $context
     * @param string                $package_id
     * @return array<string, mixed>|null
     */
    public function read_package_detail(array $context, $package_id)
    {
        $package_dir = trailingslashit($context['packages_dir']) . sanitize_text_field((string) $package_id);
        if (! is_dir($package_dir)) {
            return null;
        }

        $manifest = $this->read_json_file(trailingslashit($package_dir) . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MANIFEST_FILE);
        $records = $this->read_json_file(trailingslashit($package_dir) . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_RECORDS_FILE);
        $media = $this->read_json_file(trailingslashit($package_dir) . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MEDIA_MANIFEST_FILE);
        $qa = $this->read_json_file(trailingslashit($package_dir) . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_QA_REPORT_FILE);
        $summary = $this->read_json_file(trailingslashit($package_dir) . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_SUMMARY_FILE);

        return [
            'packageId' => sanitize_text_field((string) $package_id),
            'manifest' => is_array($manifest) ? $manifest : [],
            'records' => is_array($records) ? $records : [],
            'mediaManifest' => is_array($media) ? $media : [],
            'qaReport' => is_array($qa) ? $qa : [],
            'summary' => is_array($summary) ? $summary : [],
            'artifactRefs' => $this->build_package_artifact_refs($context, $package_id),
        ];
    }

    /**
     * @param array<string, string> $context
     * @param string                $package_id
     * @return array<string, string>
     */
    public function build_package_artifact_refs(array $context, $package_id)
    {
        $package_dir = trailingslashit($context['packages_dir']) . sanitize_text_field((string) $package_id);
        $relative_dir = DBVC_CC_V2_Page_Artifact_Service::get_instance()->get_domain_relative_path($package_dir, $context['domain_dir']);
        $prefix = trailingslashit($relative_dir);

        return [
            'manifest' => $prefix . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MANIFEST_FILE,
            'records' => $prefix . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_RECORDS_FILE,
            'media' => $prefix . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MEDIA_MANIFEST_FILE,
            'qa' => $prefix . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_QA_REPORT_FILE,
            'summary' => $prefix . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_SUMMARY_FILE,
            'zip' => $prefix . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_ZIP_FILE,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return int
     */
    public function get_next_build_sequence(array $history)
    {
        $max_seq = 0;
        foreach ($history as $item) {
            if (! is_array($item)) {
                continue;
            }

            $max_seq = max($max_seq, (int) ($item['build_seq'] ?? 0));
        }

        return $max_seq + 1;
    }

    /**
     * @param string                              $package_dir
     * @param array<string, array<string, mixed>> $artifact_bundle
     * @return bool
     */
    public function write_artifact_bundle($package_dir, array $artifact_bundle)
    {
        foreach ($artifact_bundle as $filename => $payload) {
            if (! DBVC_CC_Artifact_Manager::write_json_file(trailingslashit($package_dir) . $filename, $payload)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string>            $context
     * @param array<int, array<string, mixed>> $history
     * @param array<string, mixed>             $history_item
     * @return bool
     */
    public function write_history(array $context, array $history, array $history_item)
    {
        $history[] = $history_item;

        return $this->write_history_payload(
            $context,
            [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-builds.v1',
                'domain' => isset($context['domain']) ? (string) $context['domain'] : '',
                'generated_at' => current_time('c'),
                'builds' => array_values($history),
            ]
        );
    }

    /**
     * @param array<string, string> $context
     * @param string                $package_id
     * @param callable              $mutator
     * @return true|WP_Error
     */
    public function update_history_item(array $context, $package_id, $mutator)
    {
        $package_id = sanitize_text_field((string) $package_id);
        if ($package_id === '') {
            return new WP_Error(
                'dbvc_cc_v2_package_history_package_missing',
                __('A package ID is required to update package history.', 'dbvc'),
                ['status' => 400]
            );
        }

        if (! is_callable($mutator)) {
            return new WP_Error(
                'dbvc_cc_v2_package_history_mutator_invalid',
                __('A valid package history mutator is required.', 'dbvc'),
                ['status' => 500]
            );
        }

        $history = $this->read_history_payload($context);
        $items = isset($history['builds']) && is_array($history['builds']) ? array_values($history['builds']) : [];
        $updated = false;

        foreach ($items as $index => $item) {
            if (! is_array($item) || (($item['package_id'] ?? '') !== $package_id)) {
                continue;
            }

            $next_item = call_user_func($mutator, $item);
            if (! is_array($next_item)) {
                return new WP_Error(
                    'dbvc_cc_v2_package_history_mutator_failed',
                    __('The package history mutator did not return a valid build entry.', 'dbvc'),
                    ['status' => 500]
                );
            }

            $items[$index] = $next_item;
            $updated = true;
            break;
        }

        if (! $updated) {
            return new WP_Error(
                'dbvc_cc_v2_package_history_missing',
                __('The requested package build history entry could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $history['generated_at'] = current_time('c');
        $history['builds'] = array_values($items);

        return $this->write_history_payload($context, $history)
            ? true
            : new WP_Error(
                'dbvc_cc_v2_package_history_update_failed',
                __('Could not update the V2 package build history.', 'dbvc'),
                ['status' => 500]
            );
    }

    /**
     * @param string                              $zip_path
     * @param array<string, array<string, mixed>> $artifact_bundle
     * @return true|WP_Error
     */
    public function write_zip_archive($zip_path, array $artifact_bundle)
    {
        if (! class_exists('ZipArchive')) {
            return new WP_Error(
                'dbvc_cc_v2_zip_unavailable',
                __('ZipArchive is required to build the V2 package zip artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error(
                'dbvc_cc_v2_zip_create_failed',
                __('Could not create the V2 package zip artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        foreach ($artifact_bundle as $filename => $payload) {
            $contents = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (! is_string($contents)) {
                continue;
            }

            $zip->addFromString($filename, $contents);
        }

        $zip->close();

        return true;
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        $path = (string) $path;
        if ($path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, string> $context
     * @param array<string, mixed>  $payload
     * @return bool
     */
    private function write_history_payload(array $context, array $payload)
    {
        return DBVC_CC_Artifact_Manager::write_json_file(
            $context['package_builds_file'],
            [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-builds.v1',
                'domain' => isset($payload['domain']) ? (string) $payload['domain'] : (isset($context['domain']) ? (string) $context['domain'] : ''),
                'generated_at' => isset($payload['generated_at']) ? (string) $payload['generated_at'] : current_time('c'),
                'builds' => isset($payload['builds']) && is_array($payload['builds']) ? array_values($payload['builds']) : [],
            ]
        );
    }
}
