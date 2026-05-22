<?php

class DBVC_Import_Scenario_Third_Party implements DBVC_Import_Scenario
{
    public function get_key(): string
    {
        return 'third_party';
    }

    public function can_handle(array $file, array $payload, array $context): bool
    {
        return class_exists('DBVC_Third_Party_Portability')
            && DBVC_Third_Party_Portability::is_third_party_payload($payload);
    }

    public function route(array $file, array $payload, array $context): array
    {
        $sync_dir = trailingslashit($context['sync_dir']);
        $target_dir = trailingslashit($sync_dir . DBVC_Third_Party_Portability::SYNC_DIR);

        if (DBVC_Third_Party_Portability::is_wsform_form_payload($payload)) {
            $target_dir = trailingslashit($target_dir . 'forms');
            $filename = DBVC_Third_Party_Portability::determine_wsform_form_filename_from_payload($payload);
        } elseif (DBVC_Third_Party_Portability::is_wsform_settings_payload($payload)) {
            $filename = 'settings.json';
        } else {
            return [
                'status'      => 'error',
                'message'     => 'Unsupported third-party payload.',
                'output_path' => null,
            ];
        }

        if (! DBVC_Import_Router::ensure_directory($target_dir, 'sync')) {
            return [
                'status'      => 'error',
                'message'     => 'Failed to create third-party folder.',
                'output_path' => $target_dir,
            ];
        }

        $target_path = trailingslashit($target_dir) . $filename;

        return DBVC_Import_Router::write_json_file(
            $target_path,
            $payload,
            (bool) $context['overwrite'],
            ! empty($context['dry_run'])
        );
    }
}

return new DBVC_Import_Scenario_Third_Party();
