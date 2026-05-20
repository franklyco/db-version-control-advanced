<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class LoggingProvider extends AbstractOptionDomainProvider
{
    /**
     * @return string
     */
    public function get_key(): string
    {
        return 'logging';
    }

    /**
     * @return string
     */
    public function get_label(): string
    {
        return __('Logging', 'dbvc');
    }

    /**
     * @return int
     */
    public function get_version(): int
    {
        return 1;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_groups(): array
    {
        return [
            'logging' => [
                'label' => __('Logging', 'dbvc'),
                'fields' => [
                    \DBVC_Sync_Logger::OPTION_ENABLED,
                    \DBVC_Sync_Logger::OPTION_IMPORT_EVENTS,
                    \DBVC_Sync_Logger::OPTION_TERM_EVENTS,
                    \DBVC_Sync_Logger::OPTION_UPLOAD_EVENTS,
                    \DBVC_Sync_Logger::OPTION_MEDIA_EVENTS,
                    \DBVC_Sync_Logger::OPTION_MAX_SIZE,
                    \DBVC_Sync_Logger::OPTION_DIRECTORY,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_fields(): array
    {
        return [
            \DBVC_Sync_Logger::OPTION_ENABLED => Field::bool(\DBVC_Sync_Logger::OPTION_ENABLED, __('Enable DBVC logging', 'dbvc'), 'logging', '0'),
            \DBVC_Sync_Logger::OPTION_IMPORT_EVENTS => Field::bool(\DBVC_Sync_Logger::OPTION_IMPORT_EVENTS, __('Log import runs', 'dbvc'), 'logging', '0'),
            \DBVC_Sync_Logger::OPTION_TERM_EVENTS => Field::bool(\DBVC_Sync_Logger::OPTION_TERM_EVENTS, __('Log term import events', 'dbvc'), 'logging', '0'),
            \DBVC_Sync_Logger::OPTION_UPLOAD_EVENTS => Field::bool(\DBVC_Sync_Logger::OPTION_UPLOAD_EVENTS, __('Log sync uploads', 'dbvc'), 'logging', '0'),
            \DBVC_Sync_Logger::OPTION_MEDIA_EVENTS => Field::bool(\DBVC_Sync_Logger::OPTION_MEDIA_EVENTS, __('Log media retrieval', 'dbvc'), 'logging', '0'),
            \DBVC_Sync_Logger::OPTION_MAX_SIZE => Field::integer(
                \DBVC_Sync_Logger::OPTION_MAX_SIZE,
                __('Maximum log size', 'dbvc'),
                'logging',
                \DBVC_Sync_Logger::DEFAULT_MAX_SIZE,
                1024,
                104857600
            ),
            \DBVC_Sync_Logger::OPTION_DIRECTORY => Field::path(
                \DBVC_Sync_Logger::OPTION_DIRECTORY,
                __('Logging directory', 'dbvc'),
                'logging',
                '',
                [
                    'default_export' => Field::POLICY_PROMPT,
                    'environment_policy' => Field::POLICY_PROMPT,
                    'apply_strategy' => Field::STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED,
                    'placeholder' => '${DBVC_LOGGING_DIRECTORY}',
                    'requires_confirmation' => true,
                    'description' => __('Site-local path. Prompt during import instead of applying the source value blindly.', 'dbvc'),
                ]
            ),
        ];
    }
}
