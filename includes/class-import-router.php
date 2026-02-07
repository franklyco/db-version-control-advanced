<?php

if (! defined('WPINC')) {
    die;
}

interface DBVC_Import_Scenario
{
    public function get_key(): string;

    /**
     * Determine whether this scenario can handle the given payload.
     *
     * @param array $file    Uploaded file entry (name/tmp_name/type/size/error).
     * @param array $payload Parsed JSON payload.
     * @param array $context Router context (sync_dir, overwrite, etc.).
     */
    public function can_handle(array $file, array $payload, array $context): bool;

    /**
     * Route the payload to its destination.
     *
     * @param array $file
     * @param array $payload
     * @param array $context
     * @return array{status:string,message:string,output_path:?string}
     */
    public function route(array $file, array $payload, array $context): array;
}

class DBVC_Import_Router
{
    private static $scenarios = null;

    public static function normalize_uploads(array $upload): array
    {
        $normalized = [];
        if (! isset($upload['name'])) {
            return $normalized;
        }

        if (is_array($upload['name'])) {
            $count = count($upload['name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($upload['tmp_name'][$i])) {
                    continue;
                }
                $normalized[] = [
                    'name'     => $upload['name'][$i] ?? '',
                    'type'     => $upload['type'][$i] ?? '',
                    'tmp_name' => $upload['tmp_name'][$i] ?? '',
                    'error'    => $upload['error'][$i] ?? 0,
                    'size'     => $upload['size'][$i] ?? 0,
                ];
            }
            return $normalized;
        }

        if (! empty($upload['tmp_name'])) {
            $normalized[] = $upload;
        }

        return $normalized;
    }

    public static function route_uploaded_json(array $files, array $context = []): array
    {
        $context = wp_parse_args($context, [
            'sync_dir'       => function_exists('dbvc_get_sync_path') ? dbvc_get_sync_path() : '',
            'overwrite'      => true,
            'log_enabled'    => class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled(),
            'generate_manifest' => false,
            'dry_run'        => false,
            'strip_history'  => true,
        ]);

        $stats = [
            'processed' => 0,
            'routed'    => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'details'   => [],
        ];

        $scenarios = self::get_scenarios();
        if (empty($scenarios)) {
            return $stats;
        }

        foreach ($files as $file) {
            $stats['processed']++;

            $payload = self::read_payload($file);
            if (! is_array($payload)) {
                $stats['errors']++;
                self::log('JSON upload skipped - invalid JSON', [
                    'file' => $file['name'] ?? '',
                ], $context);
                continue;
            }

            $handled = false;
            foreach ($scenarios as $scenario) {
                if (! $scenario->can_handle($file, $payload, $context)) {
                    continue;
                }

                $handled = true;
                $result = $scenario->route($file, $payload, $context);
                if (! is_array($result)) {
                    $stats['errors']++;
                    $stats['details'][] = [
                        'file'     => $file['name'] ?? '',
                        'scenario' => $scenario->get_key(),
                        'status'   => 'error',
                        'message'  => 'Scenario returned invalid result.',
                        'path'     => null,
                    ];
                    break;
                }

                if ($result['status'] === 'routed') {
                    $stats['routed']++;
                } elseif ($result['status'] === 'skipped') {
                    $stats['skipped']++;
                } else {
                    $stats['errors']++;
                }

                $stats['details'][] = [
                    'file'     => $file['name'] ?? '',
                    'scenario' => $scenario->get_key(),
                    'status'   => $result['status'] ?? 'error',
                    'message'  => $result['message'] ?? '',
                    'path'     => $result['output_path'] ?? null,
                ];

                self::log('JSON upload route result', [
                    'file'     => $file['name'] ?? '',
                    'scenario' => $scenario->get_key(),
                    'status'   => $result['status'] ?? 'error',
                    'message'  => $result['message'] ?? '',
                    'path'     => $result['output_path'] ?? null,
                ], $context);

                break;
            }

            if (! $handled) {
                $stats['skipped']++;
                self::log('JSON upload skipped - no scenario matched', [
                    'file' => $file['name'] ?? '',
                ], $context);
                $stats['details'][] = [
                    'file'     => $file['name'] ?? '',
                    'scenario' => 'none',
                    'status'   => 'skipped',
                    'message'  => 'No scenario matched.',
                    'path'     => null,
                ];
            }
        }

        if (! empty($context['generate_manifest']) && class_exists('DBVC_Backup_Manager') && function_exists('dbvc_get_sync_path')) {
            DBVC_Backup_Manager::generate_manifest(dbvc_get_sync_path());
        }

        self::log('JSON upload routing summary', [
            'processed' => $stats['processed'],
            'routed'    => $stats['routed'],
            'skipped'   => $stats['skipped'],
            'errors'    => $stats['errors'],
        ], $context);

        return $stats;
    }

    public static function ensure_directory(string $path, string $type = 'sync'): bool
    {
        if (! is_dir($path)) {
            if (! wp_mkdir_p($path)) {
                return false;
            }
        }

        if ($type === 'taxonomy' && class_exists('DBVC_Sync_Taxonomies')) {
            DBVC_Sync_Taxonomies::ensure_directory_security($path);
        } elseif (class_exists('DBVC_Sync_Posts')) {
            DBVC_Sync_Posts::ensure_directory_security($path);
        }

        return true;
    }

    public static function write_json_file(string $path, array $payload, bool $overwrite, bool $dry_run = false): array
    {
        if (file_exists($path) && ! $overwrite) {
            return [
                'status'      => 'skipped',
                'message'     => 'File exists and overwrite disabled.',
                'output_path' => $path,
            ];
        }

        if ($dry_run) {
            return [
                'status'      => 'routed',
                'message'     => 'Dry run (no write).',
                'output_path' => $path,
            ];
        }

        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [
                'status'      => 'error',
                'message'     => 'Failed to encode JSON.',
                'output_path' => $path,
            ];
        }

        if (file_put_contents($path, $json) === false) {
            return [
                'status'      => 'error',
                'message'     => 'Failed to write JSON file.',
                'output_path' => $path,
            ];
        }

        return [
            'status'      => 'routed',
            'message'     => 'File routed.',
            'output_path' => $path,
        ];
    }

    public static function determine_post_filename(array $payload): string
    {
        $post_type = isset($payload['post_type']) ? sanitize_key($payload['post_type']) : 'post';
        $post_id   = isset($payload['ID']) ? absint($payload['ID']) : 0;
        $slug      = isset($payload['post_name']) ? sanitize_title($payload['post_name']) : '';

        $mode = dbvc_get_export_filename_format();
        $allowed = apply_filters('dbvc_allowed_export_filename_formats', ['id', 'slug', 'slug_id']);
        if (! is_array($allowed) || empty($allowed)) {
            $allowed = ['id', 'slug', 'slug_id'];
        }
        if (! in_array($mode, $allowed, true)) {
            $mode = in_array('id', $allowed, true) ? 'id' : reset($allowed);
        }

        $part = '';
        if ($mode === 'slug_id') {
            if ($slug !== '' && ! is_numeric($slug) && $post_id) {
                $part = $slug . '-' . $post_id;
            } elseif ($post_id) {
                $part = (string) $post_id;
            } elseif ($slug !== '' && ! is_numeric($slug)) {
                $part = $slug;
            }
        } elseif ($mode === 'slug') {
            if ($slug !== '' && ! is_numeric($slug)) {
                $part = $slug;
            } elseif ($post_id) {
                $part = (string) $post_id;
            }
        } else {
            $part = $post_id ? (string) $post_id : ($slug !== '' ? $slug : 'post');
        }

        if ($part === '') {
            $part = $post_id ? (string) $post_id : 'post';
        }

        return sanitize_file_name($post_type . '-' . $part . '.json');
    }

    public static function determine_term_filename(array $payload, string $taxonomy): string
    {
        $term_id = isset($payload['term_id']) ? absint($payload['term_id']) : 0;
        $slug    = isset($payload['slug']) ? sanitize_title($payload['slug']) : '';

        $mode = function_exists('dbvc_get_taxonomy_filename_format') ? dbvc_get_taxonomy_filename_format() : 'id';
        $allowed = apply_filters('dbvc_allowed_taxonomy_filename_formats', ['id', 'slug', 'slug_id']);
        if (! is_array($allowed) || empty($allowed)) {
            $allowed = ['id', 'slug', 'slug_id'];
        }
        if (! in_array($mode, $allowed, true)) {
            $mode = in_array('id', $allowed, true) ? 'id' : reset($allowed);
        }

        $part = '';
        if ($mode === 'slug_id') {
            if ($slug !== '' && ! is_numeric($slug) && $term_id) {
                $part = $slug . '-' . $term_id;
            } elseif ($term_id) {
                $part = (string) $term_id;
            } elseif ($slug !== '' && ! is_numeric($slug)) {
                $part = $slug;
            }
        } elseif ($mode === 'slug') {
            if ($slug !== '' && ! is_numeric($slug)) {
                $part = $slug;
            } elseif ($term_id) {
                $part = (string) $term_id;
            }
        } else {
            $part = $term_id ? (string) $term_id : ($slug !== '' ? $slug : 'term');
        }

        if ($part === '') {
            $part = $term_id ? (string) $term_id : 'term';
        }

        return sanitize_file_name($taxonomy . '-' . $part . '.json');
    }

    private static function get_scenarios(): array
    {
        if (self::$scenarios !== null) {
            return self::$scenarios;
        }

        self::$scenarios = [];
        $scenario_dir = trailingslashit(DBVC_PLUGIN_PATH) . 'includes/import-scenarios/';
        if (! is_dir($scenario_dir)) {
            return self::$scenarios;
        }

        $files = glob($scenario_dir . '*.php');
        if (! empty($files)) {
            foreach ($files as $file) {
                $scenario = include $file;
                if ($scenario instanceof DBVC_Import_Scenario) {
                    self::$scenarios[] = $scenario;
                }
            }
        }

        return self::$scenarios;
    }

    private static function read_payload(array $file)
    {
        if (! empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if (empty($file['tmp_name']) || ! file_exists($file['tmp_name'])) {
            return null;
        }

        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function log(string $message, array $context, array $router_context): void
    {
        if (! empty($router_context['log_enabled']) && class_exists('DBVC_Sync_Logger')) {
            DBVC_Sync_Logger::log_upload($message, $context);
        }
    }
}
