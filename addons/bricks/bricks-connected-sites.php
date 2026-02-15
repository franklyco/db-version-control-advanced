<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Connected_Sites
{
    public const OPTION_CONNECTED_SITES = 'dbvc_bricks_connected_sites';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_sites()
    {
        $sites = get_option(self::OPTION_CONNECTED_SITES, []);
        return is_array($sites) ? $sites : [];
    }

    /**
     * @param string $site_uid
     * @return array<string, mixed>|null
     */
    public static function get_site($site_uid)
    {
        $sites = self::get_sites();
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '' || ! isset($sites[$site_uid]) || ! is_array($sites[$site_uid])) {
            return null;
        }
        return $sites[$site_uid];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function upsert_site(array $payload)
    {
        $site_uid = sanitize_key((string) ($payload['site_uid'] ?? ''));
        if ($site_uid === '') {
            return [];
        }

        $existing = self::get_site($site_uid);
        $site = [
            'site_uid' => $site_uid,
            'site_label' => sanitize_text_field((string) ($payload['site_label'] ?? ($existing['site_label'] ?? $site_uid))),
            'base_url' => esc_url_raw((string) ($payload['base_url'] ?? ($existing['base_url'] ?? ''))),
            'status' => sanitize_key((string) ($payload['status'] ?? ($existing['status'] ?? 'online'))),
            'auth_mode' => sanitize_key((string) ($payload['auth_mode'] ?? ($existing['auth_mode'] ?? 'wp_app_password'))),
            'allow_receive_packages' => ! empty($payload['allow_receive_packages']) ? 1 : (! empty($existing['allow_receive_packages']) ? 1 : 0),
            'onboarding_state' => strtoupper(sanitize_text_field((string) ($payload['onboarding_state'] ?? ($existing['onboarding_state'] ?? '')))),
            'onboarding_updated_at' => sanitize_text_field((string) ($payload['onboarding_updated_at'] ?? ($existing['onboarding_updated_at'] ?? ''))),
            'last_seen_at' => sanitize_text_field((string) ($payload['last_seen_at'] ?? gmdate('c'))),
            'updated_at' => gmdate('c'),
        ];

        if ($site['site_label'] === '') {
            $site['site_label'] = $site_uid;
        }
        if (! in_array($site['status'], ['online', 'offline', 'disabled'], true)) {
            $site['status'] = 'online';
        }
        if (! in_array($site['auth_mode'], ['wp_app_password', 'api_key', 'hmac'], true)) {
            $site['auth_mode'] = 'wp_app_password';
        }
        if (! in_array($site['onboarding_state'], ['', 'PENDING_INTRO', 'VERIFIED', 'REJECTED', 'DISABLED'], true)) {
            $site['onboarding_state'] = '';
        }
        if ($site['base_url'] === '' && isset($existing['base_url'])) {
            $site['base_url'] = (string) $existing['base_url'];
        }
        if (! isset($existing['created_at'])) {
            $site['created_at'] = gmdate('c');
        } else {
            $site['created_at'] = (string) $existing['created_at'];
        }

        $sites = self::get_sites();
        $sites[$site_uid] = $site;
        update_option(self::OPTION_CONNECTED_SITES, $sites);

        return $site;
    }

    /**
     * @param string $site_uid
     * @return bool
     */
    public static function is_allowed_site($site_uid)
    {
        $site = self::get_site($site_uid);
        if (! is_array($site)) {
            return false;
        }
        if (($site['status'] ?? 'online') === 'disabled') {
            return false;
        }
        return ! empty($site['allow_receive_packages']);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest_list(\WP_REST_Request $request)
    {
        $mode = 'registry_table';
        if (class_exists('DBVC_Bricks_Addon')) {
            $mode = DBVC_Bricks_Addon::get_enum_setting('dbvc_bricks_connected_sites_mode', ['packages_backfill', 'registry_table'], 'registry_table');
        }
        self::sync_from_registry($mode);
        $status_filter = sanitize_key((string) $request->get_param('status'));
        $items = array_values(self::get_sites());
        if ($status_filter !== '') {
            $items = array_values(array_filter($items, static function ($site) use ($status_filter) {
                return isset($site['status']) && $site['status'] === $status_filter;
            }));
        }
        return rest_ensure_response([
            'items' => $items,
            'registry_mode' => $mode,
        ]);
    }

    /**
     * @param string $mode
     * @return void
     */
    private static function sync_from_registry($mode)
    {
        if ($mode === 'packages_backfill') {
            self::sync_from_packages_sources();
            self::sync_from_onboarding_registry();
            return;
        }

        self::sync_from_onboarding_registry();
        if (empty(self::get_sites())) {
            self::sync_from_packages_sources();
        }
    }

    /**
     * Backfill connected-site registry from known package source metadata.
     *
     * @return void
     */
    public static function sync_from_packages_sources()
    {
        if (! class_exists('DBVC_Bricks_Packages') || ! method_exists('DBVC_Bricks_Packages', 'get_packages')) {
            return;
        }
        $packages = DBVC_Bricks_Packages::get_packages();
        if (! is_array($packages) || empty($packages)) {
            return;
        }
        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }
            $source_site = isset($package['source_site']) && is_array($package['source_site']) ? $package['source_site'] : [];
            $site_uid = sanitize_key((string) ($source_site['site_uid'] ?? ''));
            if ($site_uid === '') {
                continue;
            }
            $existing = self::get_site($site_uid);
            self::upsert_site([
                'site_uid' => $site_uid,
                'site_label' => (string) ($existing['site_label'] ?? $site_uid),
                'base_url' => esc_url_raw((string) ($source_site['base_url'] ?? ($existing['base_url'] ?? ''))),
                'status' => (string) ($existing['status'] ?? 'online'),
                'auth_mode' => (string) ($existing['auth_mode'] ?? 'wp_app_password'),
                'allow_receive_packages' => ! empty($existing['allow_receive_packages']) ? 1 : 0,
                'onboarding_state' => (string) ($existing['onboarding_state'] ?? ''),
                'onboarding_updated_at' => (string) ($existing['onboarding_updated_at'] ?? ''),
                'last_seen_at' => sanitize_text_field((string) ($package['updated_at'] ?? gmdate('c'))),
            ]);
        }
    }

    /**
     * Sync connected-site records from onboarding registry option.
     *
     * @return void
     */
    public static function sync_from_onboarding_registry()
    {
        if (! class_exists('DBVC_Bricks_Onboarding') || ! method_exists('DBVC_Bricks_Onboarding', 'get_clients')) {
            return;
        }
        $clients = DBVC_Bricks_Onboarding::get_clients();
        if (! is_array($clients) || empty($clients)) {
            return;
        }

        foreach ($clients as $client) {
            if (! is_array($client)) {
                continue;
            }
            $site_uid = sanitize_key((string) ($client['site_uid'] ?? ''));
            if ($site_uid === '') {
                continue;
            }

            $existing = self::get_site($site_uid);
            $onboarding_state = strtoupper(sanitize_text_field((string) ($client['onboarding_state'] ?? 'PENDING_INTRO')));
            $status = (string) ($existing['status'] ?? 'online');
            if (in_array($onboarding_state, ['REJECTED', 'DISABLED'], true)) {
                $status = 'disabled';
            }
            $allow_receive = ! empty($existing['allow_receive_packages']) ? 1 : 0;
            if ($onboarding_state === 'VERIFIED') {
                $allow_receive = 1;
            }
            if (in_array($onboarding_state, ['REJECTED', 'DISABLED'], true)) {
                $allow_receive = 0;
            }

            $auth_profile = isset($client['auth_profile']) && is_array($client['auth_profile']) ? $client['auth_profile'] : [];
            self::upsert_site([
                'site_uid' => $site_uid,
                'site_label' => sanitize_text_field((string) ($client['site_label'] ?? ($existing['site_label'] ?? $site_uid))),
                'base_url' => esc_url_raw((string) ($client['base_url'] ?? ($existing['base_url'] ?? ''))),
                'status' => $status,
                'auth_mode' => sanitize_key((string) ($auth_profile['method'] ?? ($existing['auth_mode'] ?? 'wp_app_password'))),
                'allow_receive_packages' => $allow_receive,
                'onboarding_state' => $onboarding_state,
                'onboarding_updated_at' => sanitize_text_field((string) ($client['last_handshake_at'] ?? ($client['last_intro_at'] ?? ''))),
                'last_seen_at' => sanitize_text_field((string) ($client['last_seen_at'] ?? gmdate('c'))),
            ]);
        }
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_upsert(\WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }
        $site = self::upsert_site($payload);
        if (empty($site)) {
            return new \WP_Error('dbvc_bricks_site_uid_required', 'site_uid is required.', ['status' => 400]);
        }
        return rest_ensure_response([
            'ok' => true,
            'site' => $site,
        ]);
    }
}
