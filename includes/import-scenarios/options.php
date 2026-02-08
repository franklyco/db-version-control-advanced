<?php

class DBVC_Import_Scenario_Options implements DBVC_Import_Scenario
{
    public function get_key(): string
    {
        return 'options';
    }

    public function can_handle(array $file, array $payload, array $context): bool
    {
        $name = isset($file['name']) ? strtolower((string) $file['name']) : '';
        if ($name === 'options.json') {
            return true;
        }

        return isset($payload['meta']['group_id']);
    }

    public function route(array $file, array $payload, array $context): array
    {
        $sync_dir = trailingslashit($context['sync_dir']);
        if (! DBVC_Import_Router::ensure_directory($sync_dir, 'sync')) {
            return [
                'status'      => 'error',
                'message'     => 'Failed to access sync root.',
                'output_path' => $sync_dir,
            ];
        }

        $target_path = $sync_dir . 'options.json';

        return DBVC_Import_Router::write_json_file(
            $target_path,
            $payload,
            (bool) $context['overwrite'],
            ! empty($context['dry_run'])
        );
    }
}

return new DBVC_Import_Scenario_Options();
