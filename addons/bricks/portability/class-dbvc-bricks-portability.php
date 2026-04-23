<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability
{
    public const DOWNLOAD_ACTION = 'dbvc_bricks_portability_download_export';

    /**
     * @return void
     */
    public static function bootstrap()
    {
        add_filter('dbvc_bricks_admin_tabs', [self::class, 'filter_admin_tabs'], 20, 2);
        add_action('dbvc_bricks_render_extra_panels', [self::class, 'render_panel'], 20, 3);
        add_action('rest_api_init', [DBVC_Bricks_Portability_Rest_Controller::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_post_' . self::DOWNLOAD_ACTION, [self::class, 'handle_download_export']);
    }

    /**
     * @param array<string, string> $tabs
     * @param string $role_mode
     * @return array<string, string>
     */
    public static function filter_admin_tabs($tabs, $role_mode)
    {
        unset($role_mode);
        if (! is_array($tabs)) {
            return [];
        }
        if (isset($tabs['portability'])) {
            return $tabs;
        }

        $ordered = [];
        foreach ($tabs as $key => $label) {
            if ($key === 'apply_restore') {
                $ordered['portability'] = 'Portability';
            }
            $ordered[$key] = $label;
        }
        if (! isset($ordered['portability'])) {
            $ordered['portability'] = 'Portability';
        }

        return $ordered;
    }

    /**
     * @param string $role_mode
     * @param string $current_tab
     * @param string $menu_slug
     * @return void
     */
    public static function render_panel($role_mode, $current_tab, $menu_slug)
    {
        unset($role_mode, $menu_slug);
        $hidden = $current_tab === 'portability' ? '' : ' hidden';

        echo '<section id="dbvc-bricks-panel-portability" class="dbvc-bricks-panel dbvc-bricks-portability-panel" role="tabpanel" aria-labelledby="dbvc-bricks-tab-portability" tabindex="0"' . $hidden . '>';
        echo '<h2>' . esc_html__('Portability & Drift Manager', 'dbvc') . '</h2>';
        echo '<p>' . esc_html__('Export portable Bricks settings packages, upload them into another site, review normalized drift in bulk, apply approved changes, and roll back from pre-apply backups.', 'dbvc') . '</p>';

        echo '<div class="dbvc-bricks-portability-layout">';
        echo '<div class="dbvc-bricks-portability-column">';
        echo '<h3>' . esc_html__('Export Package', 'dbvc') . '</h3>';
        echo '<p>' . esc_html__('Select portable Bricks domains to include in a zip package.', 'dbvc') . '</p>';
        echo '<div id="dbvc-bricks-portability-domain-list" class="dbvc-bricks-portability-box">' . esc_html__('Loading supported domains...', 'dbvc') . '</div>';
        echo '<p><label for="dbvc-bricks-portability-export-notes">' . esc_html__('Notes', 'dbvc') . '</label><br />';
        echo '<textarea id="dbvc-bricks-portability-export-notes" rows="3" style="width:100%;"></textarea></p>';
        echo '<p><button type="button" class="button button-primary" id="dbvc-bricks-portability-export-button">' . esc_html__('Export Selected Domains', 'dbvc') . '</button></p>';
        echo '<h4>' . esc_html__('Recent Exports', 'dbvc') . '</h4>';
        echo '<table class="widefat striped" id="dbvc-bricks-portability-exports-table"><thead><tr><th>' . esc_html__('Package', 'dbvc') . '</th><th>' . esc_html__('Created', 'dbvc') . '</th><th>' . esc_html__('Domains', 'dbvc') . '</th><th>' . esc_html__('Actions', 'dbvc') . '</th></tr></thead><tbody id="dbvc-bricks-portability-exports-body"><tr><td colspan="4">' . esc_html__('No exports yet.', 'dbvc') . '</td></tr></tbody></table>';
        echo '</div>';

        echo '<div class="dbvc-bricks-portability-column">';
        echo '<h3>' . esc_html__('Import Package', 'dbvc') . '</h3>';
        echo '<p>' . esc_html__('Upload a Bricks portability package zip, normalize it, and compare it against this site.', 'dbvc') . '</p>';
        echo '<p><input type="file" id="dbvc-bricks-portability-import-file" accept=".zip" /></p>';
        echo '<p><button type="button" class="button" id="dbvc-bricks-portability-import-button">' . esc_html__('Upload Package and Compare', 'dbvc') . '</button></p>';
        echo '<div id="dbvc-bricks-portability-session-meta" class="dbvc-bricks-portability-box">' . esc_html__('No package loaded yet.', 'dbvc') . '</div>';
        echo '<h4>' . esc_html__('Recent Review Sessions', 'dbvc') . '</h4>';
        echo '<table class="widefat striped" id="dbvc-bricks-portability-sessions-table"><thead><tr><th>' . esc_html__('Session', 'dbvc') . '</th><th>' . esc_html__('Package', 'dbvc') . '</th><th>' . esc_html__('Summary', 'dbvc') . '</th><th>' . esc_html__('Actions', 'dbvc') . '</th></tr></thead><tbody id="dbvc-bricks-portability-sessions-body"><tr><td colspan="4">' . esc_html__('No review sessions yet.', 'dbvc') . '</td></tr></tbody></table>';
        echo '</div>';
        echo '</div>';

        echo '<div class="dbvc-bricks-portability-workbench">';
        echo '<h3>' . esc_html__('Review Workbench', 'dbvc') . '</h3>';
        echo '<p id="dbvc-bricks-portability-summary">' . esc_html__('Import a package to load drift rows.', 'dbvc') . '</p>';
        echo '<p>';
        echo '<label for="dbvc-bricks-portability-filter-domain">' . esc_html__('Domain', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-filter-domain"><option value="">' . esc_html__('All', 'dbvc') . '</option></select> ';
        echo '<label for="dbvc-bricks-portability-filter-status">' . esc_html__('Status', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-filter-status"><option value="">' . esc_html__('All', 'dbvc') . '</option></select> ';
        echo '<label for="dbvc-bricks-portability-filter-search">' . esc_html__('Search', 'dbvc') . '</label> ';
        echo '<input type="search" id="dbvc-bricks-portability-filter-search" placeholder="' . esc_attr__('object label or ID', 'dbvc') . '" />';
        echo '</p>';
        echo '<p>';
        echo '<label for="dbvc-bricks-portability-bulk-action">' . esc_html__('Bulk Action', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-bulk-action"><option value="keep_current">Keep current</option><option value="add_incoming">Add incoming</option><option value="replace_with_incoming">Replace with incoming</option><option value="skip">Skip</option></select> ';
        echo '<button type="button" class="button" id="dbvc-bricks-portability-apply-bulk">' . esc_html__('Apply To Filtered Rows', 'dbvc') . '</button>';
        echo '</p>';
        echo '<div class="dbvc-bricks-portability-table-wrap">';
        echo '<table class="widefat striped" id="dbvc-bricks-portability-table"><thead><tr><th>' . esc_html__('Domain', 'dbvc') . '</th><th>' . esc_html__('Object', 'dbvc') . '</th><th>' . esc_html__('Object ID', 'dbvc') . '</th><th>' . esc_html__('Match', 'dbvc') . '</th><th>' . esc_html__('Status', 'dbvc') . '</th><th>' . esc_html__('Warnings', 'dbvc') . '</th><th>' . esc_html__('Proposed Action', 'dbvc') . '</th></tr></thead><tbody id="dbvc-bricks-portability-table-body"><tr><td colspan="7">' . esc_html__('No review rows loaded.', 'dbvc') . '</td></tr></tbody></table>';
        echo '</div>';
        echo '<div class="dbvc-bricks-portability-detail-grid">';
        echo '<div><h4>' . esc_html__('Row Preview', 'dbvc') . '</h4><pre id="dbvc-bricks-portability-detail" class="dbvc-bricks-portability-pre">' . esc_html__('Select a row to inspect its normalized diff preview.', 'dbvc') . '</pre></div>';
        echo '<div><h4>' . esc_html__('Domain Summary', 'dbvc') . '</h4><pre id="dbvc-bricks-portability-domain-summary" class="dbvc-bricks-portability-pre">' . esc_html__('Import a package to load per-domain summaries.', 'dbvc') . '</pre></div>';
        echo '</div>';
        echo '<p><label><input type="checkbox" id="dbvc-bricks-portability-confirm-apply" /> ' . esc_html__('I confirm the selected incoming changes should be applied and backed up first.', 'dbvc') . '</label></p>';
        echo '<p><button type="button" class="button button-primary" id="dbvc-bricks-portability-apply-button">' . esc_html__('Apply Approved Changes', 'dbvc') . '</button></p>';
        echo '</div>';

        echo '<div class="dbvc-bricks-portability-layout">';
        echo '<div class="dbvc-bricks-portability-column">';
        echo '<h3>' . esc_html__('Backups & Rollback', 'dbvc') . '</h3>';
        echo '<table class="widefat striped" id="dbvc-bricks-portability-backups-table"><thead><tr><th>' . esc_html__('Backup', 'dbvc') . '</th><th>' . esc_html__('Created', 'dbvc') . '</th><th>' . esc_html__('Options', 'dbvc') . '</th><th>' . esc_html__('Actions', 'dbvc') . '</th></tr></thead><tbody id="dbvc-bricks-portability-backups-body"><tr><td colspan="4">' . esc_html__('No backups yet.', 'dbvc') . '</td></tr></tbody></table>';
        echo '</div>';
        echo '<div class="dbvc-bricks-portability-column">';
        echo '<h3>' . esc_html__('Recent Jobs', 'dbvc') . '</h3>';
        echo '<pre id="dbvc-bricks-portability-jobs" class="dbvc-bricks-portability-pre">' . esc_html__('No job activity loaded.', 'dbvc') . '</pre>';
        echo '</div>';
        echo '</div>';

        echo '</section>';
    }

    /**
     * @param string $hook
     * @return void
     */
    public static function enqueue_assets($hook)
    {
        unset($hook);
        if ((string) ($_GET['page'] ?? '') !== DBVC_Bricks_Addon::MENU_SLUG) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        wp_enqueue_style(
            'dbvc-bricks-portability',
            DBVC_PLUGIN_URL . 'addons/bricks/portability/assets/bricks-portability.css',
            [],
            defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '1.0.0'
        );

        wp_enqueue_script(
            'dbvc-bricks-portability',
            DBVC_PLUGIN_URL . 'addons/bricks/portability/assets/bricks-portability.js',
            [],
            defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '1.0.0',
            true
        );

        wp_localize_script('dbvc-bricks-portability', 'DBVC_BRICKS_PORTABILITY', [
            'statusEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/status')),
            'exportEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/export')),
            'importEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/import')),
            'applyEndpoint' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/apply')),
            'sessionEndpointBase' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/sessions/')),
            'backupRollbackBase' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/backups/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'messages' => [
                'exported' => esc_html__('Bricks portability export created.', 'dbvc'),
                'imported' => esc_html__('Bricks portability package imported and compared.', 'dbvc'),
                'applied' => esc_html__('Bricks portability apply completed.', 'dbvc'),
                'rolledBack' => esc_html__('Bricks portability rollback completed.', 'dbvc'),
            ],
        ]);
    }

    /**
     * @param string $export_id
     * @return string
     */
    public static function get_export_download_url($export_id)
    {
        $export_id = sanitize_key((string) $export_id);
        if ($export_id === '') {
            return '';
        }

        return esc_url_raw(add_query_arg(
            [
                'action' => self::DOWNLOAD_ACTION,
                'export_id' => $export_id,
                '_wpnonce' => wp_create_nonce(self::DOWNLOAD_ACTION . ':' . $export_id),
            ],
            admin_url('admin-post.php')
        ));
    }

    /**
     * @return void
     */
    public static function handle_download_export()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to download this export.', 'dbvc'));
        }

        $export_id = isset($_GET['export_id']) ? sanitize_key(wp_unslash((string) $_GET['export_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($export_id === '' && isset($_GET['amp;export_id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $export_id = sanitize_key(wp_unslash((string) $_GET['amp;export_id'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        if ($export_id === '') {
            wp_die(esc_html__('Missing export identifier.', 'dbvc'));
        }

        if (! isset($_GET['_wpnonce']) && isset($_GET['amp;_wpnonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $_GET['_wpnonce'] = wp_unslash((string) $_GET['amp;_wpnonce']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        check_admin_referer(self::DOWNLOAD_ACTION . ':' . $export_id);

        $zip_path = DBVC_Bricks_Portability_Storage::resolve_export_zip_path($export_id);
        if (is_wp_error($zip_path) || ! is_file($zip_path) || ! is_readable($zip_path)) {
            wp_die(esc_html__('The requested Bricks portability export could not be found.', 'dbvc'));
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(basename((string) $zip_path)) . '"');
        header('Content-Length: ' . (string) filesize((string) $zip_path));
        readfile((string) $zip_path);
        exit;
    }
}
