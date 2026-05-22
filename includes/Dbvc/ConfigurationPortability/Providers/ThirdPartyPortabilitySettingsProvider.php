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
}
