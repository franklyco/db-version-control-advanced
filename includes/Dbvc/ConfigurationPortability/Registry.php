<?php

namespace Dbvc\ConfigurationPortability;

use Dbvc\ConfigurationPortability\Providers\LoggingProvider;
use Dbvc\ConfigurationPortability\Providers\MediaHandlingProvider;
use Dbvc\ConfigurationPortability\Providers\VisualEditorProvider;
use Dbvc\ConfigurationPortability\Providers\CoreImportExportProvider;
use Dbvc\ConfigurationPortability\Providers\MaskingProvider;
use Dbvc\ConfigurationPortability\Providers\ThirdPartyPortabilitySettingsProvider;
use Dbvc\ConfigurationPortability\Providers\BricksAddonProvider;
use Dbvc\ConfigurationPortability\Providers\MasterToolsProvider;
use Dbvc\ConfigurationPortability\Providers\AiPackageProvider;
use Dbvc\ConfigurationPortability\Providers\ContentCollectorProvider;
use Dbvc\ConfigurationPortability\Providers\ContentCollectorRuntimeProvider;

if (! defined('WPINC')) {
    die;
}

final class Registry
{
    /**
     * @var array<string, DomainProviderInterface>|null
     */
    private static $providers = null;

    /**
     * @return array<string, DomainProviderInterface>
     */
    public static function get_providers(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $providers = [
            new CoreImportExportProvider(),
            new MaskingProvider(),
            new VisualEditorProvider(),
            new LoggingProvider(),
            new MediaHandlingProvider(),
            new ThirdPartyPortabilitySettingsProvider(),
            new BricksAddonProvider(),
            new MasterToolsProvider(),
            new AiPackageProvider(),
            new ContentCollectorProvider(),
            new ContentCollectorRuntimeProvider(),
        ];

        if (function_exists('apply_filters')) {
            $providers = apply_filters('dbvc_configuration_portability_domains', $providers);
        }

        self::$providers = self::normalize_providers(is_array($providers) ? $providers : []);

        return self::$providers;
    }

    /**
     * @return void
     */
    public static function reset(): void
    {
        self::$providers = null;
    }

    /**
     * @param string $domain_key
     * @return DomainProviderInterface|null
     */
    public static function get_provider($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        $providers = self::get_providers();

        return $providers[$domain_key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_status(): array
    {
        $domains = [];
        foreach (self::get_providers() as $provider) {
            $domains[] = [
                'key' => $provider->get_key(),
                'label' => $provider->get_label(),
                'version' => $provider->get_version(),
                'groups' => $provider->get_groups(),
                'fields' => $provider->get_fields(),
            ];
        }

        return [
            'feature' => 'dbvc_configuration_portability',
            'feature_version' => '0.1.0',
            'domains' => $domains,
        ];
    }

    /**
     * @param array<int|string, mixed> $providers
     * @return array<string, DomainProviderInterface>
     */
    private static function normalize_providers(array $providers): array
    {
        $normalized = [];
        foreach ($providers as $provider) {
            if (! $provider instanceof DomainProviderInterface) {
                continue;
            }

            $key = sanitize_key($provider->get_key());
            if ($key === '') {
                continue;
            }

            $normalized[$key] = $provider;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
