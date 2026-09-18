<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class ThirdPartyPortabilitySettingsProvider extends AbstractOptionDomainProvider
{
    public function get_key(): string
    {
        return 'third_party_portability_settings';
    }

    public function get_label(): string
    {
        return __('Third-Party Portability Settings', 'dbvc');
    }

    public function get_version(): int
    {
        return 1;
    }

    public function get_groups(): array
    {
        return [
            'ws_form' => [
                'label' => __('WS Form', 'dbvc'),
                'fields' => [
                    \DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS,
                    \DBVC_Third_Party_Portability::OPTION_WSFORM_SETTINGS,
                    \DBVC_Third_Party_Portability::OPTION_WSFORM_INCLUDE_TRASH,
                ],
            ],
        ];
    }

    public function get_fields(): array
    {
        return [
            \DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS => Field::bool(\DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS, __('Include WS Form definitions', 'dbvc'), 'ws_form', '0'),
            \DBVC_Third_Party_Portability::OPTION_WSFORM_SETTINGS => Field::bool(\DBVC_Third_Party_Portability::OPTION_WSFORM_SETTINGS, __('Include WS Form settings', 'dbvc'), 'ws_form', '0'),
            \DBVC_Third_Party_Portability::OPTION_WSFORM_INCLUDE_TRASH => Field::bool(\DBVC_Third_Party_Portability::OPTION_WSFORM_INCLUDE_TRASH, __('Include trashed WS Form definitions', 'dbvc'), 'ws_form', '0'),
        ];
    }

    public function get_import_dependencies(array $incoming): array
    {
        $fields = $this->flatten_incoming_fields($incoming);
        $requires_ws_form = false;
        foreach ([
            \DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS,
            \DBVC_Third_Party_Portability::OPTION_WSFORM_SETTINGS,
            \DBVC_Third_Party_Portability::OPTION_WSFORM_INCLUDE_TRASH,
        ] as $field_key) {
            $value = strtolower(trim((string) ($fields[$field_key]['value'] ?? '0')));
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                $requires_ws_form = true;
                break;
            }
        }

        if (! $requires_ws_form) {
            return [];
        }

        return array_merge(
            $this->get_class_dependency($incoming, 'WS_Form_Form', __('WS Form', 'dbvc')),
            $this->get_class_dependency($incoming, 'WS_Form_Common', __('WS Form', 'dbvc'))
        );
    }
}
