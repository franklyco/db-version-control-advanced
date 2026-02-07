<?php

class DBVC_Import_Scenario_Term implements DBVC_Import_Scenario
{
    public function get_key(): string
    {
        return 'term';
    }

    public function can_handle(array $file, array $payload, array $context): bool
    {
        return isset($payload['taxonomy']) && (isset($payload['slug']) || isset($payload['term_id']));
    }

    public function route(array $file, array $payload, array $context): array
    {
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key($payload['taxonomy']) : '';
        if ($taxonomy === '') {
            return [
                'status'      => 'error',
                'message'     => 'Missing taxonomy.',
                'output_path' => null,
            ];
        }

        $sync_dir = trailingslashit($context['sync_dir']);
        $target_dir = trailingslashit($sync_dir . 'taxonomy/' . $taxonomy);
        if (! DBVC_Import_Router::ensure_directory($target_dir, 'taxonomy')) {
            return [
                'status'      => 'error',
                'message'     => 'Failed to create taxonomy folder.',
                'output_path' => $target_dir,
            ];
        }

        $payload = $this->normalize_payload($payload, $taxonomy, $file);

        $filename = DBVC_Import_Router::determine_term_filename($payload, $taxonomy);
        $target_path = $target_dir . $filename;

        return DBVC_Import_Router::write_json_file(
            $target_path,
            $payload,
            (bool) $context['overwrite'],
            ! empty($context['dry_run'])
        );
    }

    private function normalize_payload(array $payload, string $taxonomy, array $file): array
    {
        $slug = isset($payload['slug']) ? sanitize_title($payload['slug']) : '';
        $incoming_uid = isset($payload['vf_object_uid']) ? trim((string) $payload['vf_object_uid']) : '';
        $resolved_uid = $incoming_uid;
        $term = null;

        if ($slug !== '' && taxonomy_exists($taxonomy)) {
            $term = get_term_by('slug', $slug, $taxonomy);
        }

        if ($term && ! is_wp_error($term) && class_exists('DBVC_Sync_Taxonomies')) {
            $term_uid = DBVC_Sync_Taxonomies::ensure_term_uid($term->term_id, $taxonomy);
            if ($term_uid !== '') {
                $resolved_uid = $term_uid;
            }
        } elseif ($resolved_uid === '') {
            $resolved_uid = wp_generate_uuid4();
        }

        $payload['vf_object_uid'] = $resolved_uid;

        if (! isset($payload['meta']) || ! is_array($payload['meta'])) {
            $payload['meta'] = [];
        }

        if (empty($payload['meta']['dbvc_term_history'])) {
            $payload['meta']['dbvc_term_history'] = [[
                'normalized_from' => 'upload-router',
                'original_term_id'=> isset($payload['term_id']) ? absint($payload['term_id']) : ($term ? (int) $term->term_id : 0),
                'original_slug'   => $slug,
                'taxonomy'        => $taxonomy,
                'normalized_at'   => current_time('mysql'),
                'normalized_by'   => get_current_user_id(),
                'json_filename'   => isset($file['name']) ? (string) $file['name'] : '',
                'status'          => ($term && ! is_wp_error($term)) ? 'existing' : 'unknown',
                'vf_object_uid'   => $resolved_uid,
            ]];
        }

        return $payload;
    }
}

return new DBVC_Import_Scenario_Term();
