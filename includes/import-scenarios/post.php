<?php

class DBVC_Import_Scenario_Post implements DBVC_Import_Scenario
{
    public function get_key(): string
    {
        return 'post';
    }

    public function can_handle(array $file, array $payload, array $context): bool
    {
        return isset($payload['post_type']) && (isset($payload['ID']) || isset($payload['post_name']) || isset($payload['post_title']));
    }

    public function route(array $file, array $payload, array $context): array
    {
        $post_type = isset($payload['post_type']) ? sanitize_key($payload['post_type']) : '';
        if ($post_type === '') {
            return [
                'status'      => 'error',
                'message'     => 'Missing post_type.',
                'output_path' => null,
            ];
        }

        $sync_dir = trailingslashit($context['sync_dir']);
        $target_dir = trailingslashit($sync_dir . $post_type);
        if (! DBVC_Import_Router::ensure_directory($target_dir, 'post')) {
            return [
                'status'      => 'error',
                'message'     => 'Failed to create post type folder.',
                'output_path' => $target_dir,
            ];
        }

        $payload = $this->normalize_payload($payload, $context);
        $filename = DBVC_Import_Router::determine_post_filename($payload);
        $target_path = $target_dir . $filename;

        return DBVC_Import_Router::write_json_file(
            $target_path,
            $payload,
            (bool) $context['overwrite'],
            ! empty($context['dry_run'])
        );
    }

    private function normalize_payload(array $payload, array $context): array
    {
        if (! empty($context['strip_history'])) {
            if (isset($payload['meta']) && is_array($payload['meta'])) {
                if (isset($payload['meta']['dbvc_post_history'])) {
                    unset($payload['meta']['dbvc_post_history']);
                }
                if (isset($payload['meta']['_dbvc_import_hash'])) {
                    unset($payload['meta']['_dbvc_import_hash']);
                }
            }
        }

        return $payload;
    }
}

return new DBVC_Import_Scenario_Post();
