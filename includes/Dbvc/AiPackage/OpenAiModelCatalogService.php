<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class OpenAiModelCatalogService
{
    public const OPTION_CATALOG = 'dbvc_ai_openai_model_catalog';
    public const CRON_HOOK = 'dbvc_ai_refresh_openai_models';
    public const API_URL = 'https://api.openai.com/v1/models';
    public const REFRESH_INTERVAL = 12 * HOUR_IN_SECONDS;

    /**
     * Register hooks for scheduled refresh behavior.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'refresh_catalog_from_schedule']);
        add_action('init', [self::class, 'sync_schedule_for_current_settings']);
    }

    /**
     * @param bool $refresh_if_stale
     * @return array<string,mixed>
     */
    public static function get_catalog(bool $refresh_if_stale = false): array
    {
        $catalog = get_option(self::OPTION_CATALOG, []);
        if (! is_array($catalog)) {
            $catalog = [];
        }

        $catalog = self::merge_catalog_defaults($catalog);

        if ($refresh_if_stale && self::has_api_key() && self::is_catalog_stale($catalog)) {
            $refreshed = self::refresh_catalog(false);
            if (is_array($refreshed)) {
                $catalog = $refreshed;
            }
        }

        return $catalog;
    }

    /**
     * @param bool $force
     * @return array<string,mixed>
     */
    public static function refresh_catalog(bool $force = true): array
    {
        $settings = Settings::get_all_settings();
        $providers = isset($settings['providers']) && is_array($settings['providers']) ? $settings['providers'] : [];
        $provider_key = isset($providers['provider_key']) ? sanitize_key((string) $providers['provider_key']) : Settings::DEFAULT_PROVIDER_KEY;
        $api_key = isset($providers['api_key']) ? trim((string) $providers['api_key']) : '';

        if ($provider_key !== Settings::DEFAULT_PROVIDER_KEY || $api_key === '') {
            self::clear_catalog();

            return self::merge_catalog_defaults([]);
        }

        $catalog = self::get_catalog(false);
        if (! $force && ! self::is_catalog_stale($catalog)) {
            self::sync_schedule(true);
            return $catalog;
        }

        $response = wp_remote_get(
            self::API_URL,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            $catalog['last_error'] = $response->get_error_message();
            update_option(self::OPTION_CATALOG, $catalog, false);
            self::sync_schedule(true);

            return $catalog;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode((string) $body, true);

        if ($status_code !== 200 || ! is_array($decoded) || ! isset($decoded['data']) || ! is_array($decoded['data'])) {
            $catalog['last_error'] = self::extract_error_message($decoded, $status_code);
            update_option(self::OPTION_CATALOG, $catalog, false);
            self::sync_schedule(true);

            return $catalog;
        }

        $models = self::normalize_model_catalog($decoded['data']);
        $catalog = [
            'provider_key' => Settings::DEFAULT_PROVIDER_KEY,
            'provider_label' => Settings::DEFAULT_PROVIDER_LABEL,
            'models' => $models,
            'refreshed_at' => current_time('c'),
            'source' => 'openai_api',
            'last_error' => '',
        ];
        update_option(self::OPTION_CATALOG, $catalog, false);

        if (! empty($models)) {
            self::ensure_default_model($models);
        }

        self::sync_schedule(true);

        return $catalog;
    }

    /**
     * @return void
     */
    public static function refresh_catalog_from_schedule(): void
    {
        self::refresh_catalog(true);
    }

    /**
     * @return void
     */
    public static function clear_catalog(): void
    {
        update_option(self::OPTION_CATALOG, self::merge_catalog_defaults([]), false);
        self::sync_schedule(false);
    }

    /**
     * @return bool
     */
    public static function has_api_key(): bool
    {
        $settings = Settings::get_all_settings();
        $providers = isset($settings['providers']) && is_array($settings['providers']) ? $settings['providers'] : [];

        return ! empty($providers['api_key']);
    }

    /**
     * @return void
     */
    public static function sync_schedule_for_current_settings(): void
    {
        self::sync_schedule(self::has_api_key());
    }

    /**
     * @param array<string,mixed> $catalog
     * @return bool
     */
    public static function is_catalog_stale(array $catalog): bool
    {
        $refreshed_at = isset($catalog['refreshed_at']) ? strtotime((string) $catalog['refreshed_at']) : 0;
        if ($refreshed_at <= 0) {
            return true;
        }

        return (time() - $refreshed_at) >= self::REFRESH_INTERVAL;
    }

    /**
     * @param array<int,mixed> $models
     * @return void
     */
    private static function ensure_default_model(array $models): void
    {
        $settings = Settings::get_all_settings();
        $providers = isset($settings['providers']) && is_array($settings['providers']) ? $settings['providers'] : [];
        $current_model = isset($providers['model_default']) ? (string) $providers['model_default'] : '';

        $model_ids = array_map(
            static function (array $model): string {
                return (string) ($model['id'] ?? '');
            },
            $models
        );

        if ($current_model !== '' && in_array($current_model, $model_ids, true)) {
            return;
        }

        $providers['model_default'] = (string) ($models[0]['id'] ?? Settings::DEFAULT_MODEL_ID);
        $settings['providers'] = $providers;
        update_option(Settings::OPTION_SETTINGS, $settings, false);
    }

    /**
     * @param array<int,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_model_catalog(array $data): array
    {
        $models = [];
        foreach ($data as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }

            $id = (string) $row['id'];
            if (! self::is_supported_model_id($id)) {
                continue;
            }

            $models[$id] = [
                'id' => $id,
                'created' => isset($row['created']) ? (int) $row['created'] : 0,
                'owned_by' => isset($row['owned_by']) ? (string) $row['owned_by'] : '',
            ];
        }

        if (empty($models)) {
            foreach ($data as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    continue;
                }

                $id = (string) $row['id'];
                if (strpos($id, 'ft:') === 0) {
                    continue;
                }

                $models[$id] = [
                    'id' => $id,
                    'created' => isset($row['created']) ? (int) $row['created'] : 0,
                    'owned_by' => isset($row['owned_by']) ? (string) $row['owned_by'] : '',
                ];
            }
        }

        $models = array_values($models);
        usort(
            $models,
            static function (array $left, array $right): int {
                $created_compare = (int) ($right['created'] ?? 0) <=> (int) ($left['created'] ?? 0);
                if ($created_compare !== 0) {
                    return $created_compare;
                }

                return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
            }
        );

        return $models;
    }

    /**
     * @param string $model_id
     * @return bool
     */
    private static function is_supported_model_id(string $model_id): bool
    {
        if ($model_id === '' || strpos($model_id, 'ft:') === 0) {
            return false;
        }

        $excluded_fragments = [
            'image',
            'audio',
            'realtime',
            'transcribe',
            'transcription',
            'tts',
            'embedding',
            'moderation',
            'whisper',
            'dall',
            'omni',
            'chatgpt',
        ];
        foreach ($excluded_fragments as $fragment) {
            if (stripos($model_id, $fragment) !== false) {
                return false;
            }
        }

        if (preg_match('/^(gpt-|o[1-9]|o[1-9]-|o[1-9][a-z])/i', $model_id)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed>|null $decoded
     * @param int                      $status_code
     * @return string
     */
    private static function extract_error_message(?array $decoded, int $status_code): string
    {
        if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }

        if ($status_code > 0) {
            return sprintf(__('OpenAI model refresh failed with status %d.', 'dbvc'), $status_code);
        }

        return __('OpenAI model refresh failed.', 'dbvc');
    }

    /**
     * @param array<string,mixed> $catalog
     * @return array<string,mixed>
     */
    private static function merge_catalog_defaults(array $catalog): array
    {
        return wp_parse_args(
            $catalog,
            [
                'provider_key' => Settings::DEFAULT_PROVIDER_KEY,
                'provider_label' => Settings::DEFAULT_PROVIDER_LABEL,
                'models' => [],
                'refreshed_at' => '',
                'source' => '',
                'last_error' => '',
            ]
        );
    }

    /**
     * @param bool $enabled
     * @return void
     */
    private static function sync_schedule(bool $enabled): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($enabled) {
            if (! $timestamp) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK);
            }

            return;
        }

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
}
