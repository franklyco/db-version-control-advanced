<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability
{
    public const PAGE_SLUG = 'dbvc-bricks-settings-portability';
    public const DOWNLOAD_ACTION = 'dbvc_bricks_portability_download_export';

    /**
     * @return void
     */
    public static function bootstrap()
    {
        add_action('admin_menu', [self::class, 'register_admin_submenu'], DBVC_Bricks_Addon::ADMIN_MENU_PRIORITY + 1);
        add_action('admin_init', [self::class, 'maybe_redirect_legacy_tab']);
        add_action('rest_api_init', [DBVC_Bricks_Portability_Rest_Controller::class, 'register_routes']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_post_' . self::DOWNLOAD_ACTION, [self::class, 'handle_download_export']);
    }

    /**
     * @return void
     */
    public static function register_admin_submenu()
    {
        if (! class_exists('DBVC_Bricks_Addon') || ! DBVC_Bricks_Addon::is_enabled()) {
            return;
        }

        add_submenu_page(
            'dbvc-export',
            esc_html__('Bricks Settings Transfer', 'dbvc'),
            esc_html__('↳ Settings Transfer', 'dbvc'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_admin_page']
        );
    }

    /**
     * @return string
     */
    public static function get_admin_page_url()
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    /**
     * @return void
     */
    public static function maybe_redirect_legacy_tab()
    {
        if (! is_admin()) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($page !== DBVC_Bricks_Addon::MENU_SLUG || $tab !== 'portability') {
            return;
        }

        wp_safe_redirect(self::get_admin_page_url(), 301);
        exit;
    }

    /**
     * @return void
     */
    public static function render_admin_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'dbvc'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Bricks Settings Transfer', 'dbvc') . '</h1>';
        echo '<p class="description">' . esc_html__('Create transfer packages, compare them with this site, apply selected changes, and restore from backups if needed.', 'dbvc') . '</p>';

        if (! DBVC_Bricks_Addon::is_enabled()) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Bricks Add-on is turned off. Enable it in Configure -> Add-ons to access transfer actions.', 'dbvc') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<div id="dbvc-bricks-notice-success" class="notice notice-success is-dismissible" hidden><p></p></div>';
        echo '<div id="dbvc-bricks-notice-error" class="notice notice-error is-dismissible" hidden><p></p></div>';
        echo '<div id="dbvc-bricks-panel-portability" class="dbvc-bricks-portability-page">';
        self::render_workspace();
        echo '</div>';
        echo '</div>';
    }

    /**
     * @return void
     */
    private static function render_workspace()
    {
        echo '<div class="dbvc-bricks-portability-intro">';
        echo '<h2>' . esc_html__('Bricks Settings Transfer', 'dbvc') . '</h2>';
        echo '<p>' . esc_html__('Move selected Bricks settings between sites, compare what will change, apply selected changes, and restore from backups.', 'dbvc') . '</p>';
        echo '</div>';

        echo '<h2 class="nav-tab-wrapper dbvc-bricks-portability-subtabs" role="tablist" aria-label="' . esc_attr__('Bricks Settings Transfer sections', 'dbvc') . '">';
        echo '<button type="button" class="nav-tab nav-tab-active" id="dbvc-bricks-portability-subtab-workspace" data-portability-tab="workspace" role="tab" aria-selected="true" aria-controls="dbvc-bricks-portability-panel-workspace">' . esc_html__('Transfer & Compare', 'dbvc') . '</button>';
        echo '<button type="button" class="nav-tab" id="dbvc-bricks-portability-subtab-history" data-portability-tab="history" role="tab" aria-selected="false" aria-controls="dbvc-bricks-portability-panel-history">' . esc_html__('History & Restore', 'dbvc') . '</button>';
        echo '</h2>';

        echo '<section id="dbvc-bricks-portability-panel-workspace" class="dbvc-bricks-portability-tab-panel" data-portability-panel="workspace" role="tabpanel" aria-labelledby="dbvc-bricks-portability-subtab-workspace">';
        echo '<div class="dbvc-bricks-portability-layout">';
        echo '<div class="dbvc-bricks-portability-column">';
        echo '<h3>' . esc_html__('Create Transfer Package', 'dbvc') . '</h3>';
        echo '<p>' . esc_html__('Choose the Bricks settings groups to include in the ZIP file.', 'dbvc') . '</p>';
        echo '<div id="dbvc-bricks-portability-domain-list" class="dbvc-bricks-portability-box">' . esc_html__('Loading supported settings groups...', 'dbvc') . '</div>';
        echo '<p><label for="dbvc-bricks-portability-export-notes">' . esc_html__('Notes', 'dbvc') . '</label><br />';
        echo '<textarea id="dbvc-bricks-portability-export-notes" rows="3" style="width:100%;"></textarea></p>';
        echo '<p><button type="button" class="button button-primary" id="dbvc-bricks-portability-export-button">' . esc_html__('Export Selected Settings Groups', 'dbvc') . '</button></p>';
        echo '<h4>' . esc_html__('Recent Transfer Packages', 'dbvc') . '</h4>';
        echo '<table class="widefat striped" id="dbvc-bricks-portability-exports-table"><thead><tr><th>' . esc_html__('Transfer Package', 'dbvc') . '</th><th>' . esc_html__('Created', 'dbvc') . '</th><th>' . esc_html__('Settings Groups', 'dbvc') . '</th><th>' . esc_html__('Actions', 'dbvc') . '</th></tr></thead><tbody id="dbvc-bricks-portability-exports-body"><tr><td colspan="4">' . esc_html__('No transfer packages yet.', 'dbvc') . '</td></tr></tbody></table>';
        echo '</div>';

        echo '<div class="dbvc-bricks-portability-column">';
        echo '<h3>' . esc_html__('Upload Transfer Package', 'dbvc') . '</h3>';
        echo '<p>' . esc_html__('Upload a Bricks settings transfer ZIP and compare it with this site.', 'dbvc') . '</p>';
        echo '<p><input type="file" id="dbvc-bricks-portability-import-file" accept=".zip" /></p>';
        echo '<p><button type="button" class="button" id="dbvc-bricks-portability-import-button">' . esc_html__('Upload Transfer Package and Compare', 'dbvc') . '</button></p>';
        echo '<div id="dbvc-bricks-portability-session-meta" class="dbvc-bricks-portability-box">' . esc_html__('No transfer package loaded yet.', 'dbvc') . '</div>';
        echo '<h4>' . esc_html__('Recent Review Sessions', 'dbvc') . '</h4>';
        echo '<table class="widefat striped" id="dbvc-bricks-portability-sessions-table"><thead><tr><th>' . esc_html__('Session', 'dbvc') . '</th><th>' . esc_html__('Transfer Package', 'dbvc') . '</th><th>' . esc_html__('Summary', 'dbvc') . '</th><th>' . esc_html__('Actions', 'dbvc') . '</th></tr></thead><tbody id="dbvc-bricks-portability-sessions-body"><tr><td colspan="4">' . esc_html__('No review sessions yet.', 'dbvc') . '</td></tr></tbody></table>';
        echo '</div>';
        echo '</div>';

        echo '<div class="dbvc-bricks-portability-workbench">';
        echo '<h3>' . esc_html__('Review Changes', 'dbvc') . '</h3>';
        echo '<p id="dbvc-bricks-portability-summary">' . esc_html__('Upload a transfer package to load changes.', 'dbvc') . '</p>';
        echo '<p class="description dbvc-bricks-portability-context-note">' . esc_html__('Transfer Package = data from the uploaded ZIP. This Site = data currently stored on this site.', 'dbvc') . '</p>';
        echo '<div id="dbvc-bricks-portability-review-state" class="dbvc-bricks-portability-review-state">';
        echo '<div class="dbvc-bricks-portability-review-state__meta">' . esc_html__('Upload a transfer package to check whether this site has changed since the comparison was created.', 'dbvc') . '</div>';
        echo '<div class="dbvc-bricks-portability-review-state__actions"><button type="button" class="button" id="dbvc-bricks-portability-refresh-session">' . esc_html__('Refresh Comparison', 'dbvc') . '</button></div>';
        echo '</div>';
        echo '<div id="dbvc-bricks-portability-applied-summary" class="dbvc-bricks-portability-applied-summary">' . esc_html__('Apply selected changes to record review timestamps for this transfer package on this site.', 'dbvc') . '</div>';
        echo '<div id="dbvc-bricks-portability-stats" class="dbvc-bricks-portability-stats">' . esc_html__('Upload a transfer package to load review totals.', 'dbvc') . '</div>';
        echo '<details id="dbvc-bricks-portability-domain-summary-toggle" class="dbvc-bricks-portability-summary-toggle">';
        echo '<summary>' . esc_html__('Settings Group Summary', 'dbvc') . '</summary>';
        echo '<pre id="dbvc-bricks-portability-domain-summary" class="dbvc-bricks-portability-pre">' . esc_html__('Upload a transfer package to load settings group summaries.', 'dbvc') . '</pre>';
        echo '</details>';
        echo '<p>';
        echo '<label for="dbvc-bricks-portability-filter-domain">' . esc_html__('Settings Group', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-filter-domain"><option value="">' . esc_html__('All', 'dbvc') . '</option></select> ';
        echo '<label for="dbvc-bricks-portability-filter-status">' . esc_html__('Status', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-filter-status"><option value="">' . esc_html__('All', 'dbvc') . '</option></select> ';
        echo '<label for="dbvc-bricks-portability-filter-decision">' . esc_html__('Selected Action', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-filter-decision"><option value="">' . esc_html__('All', 'dbvc') . '</option></select> ';
        echo '<label for="dbvc-bricks-portability-filter-warning">' . esc_html__('Warnings', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-filter-warning"><option value="">' . esc_html__('All', 'dbvc') . '</option><option value="with_warnings">' . esc_html__('With Warnings', 'dbvc') . '</option><option value="without_warnings">' . esc_html__('Without Warnings', 'dbvc') . '</option></select> ';
        echo '<label for="dbvc-bricks-portability-filter-search">' . esc_html__('Search', 'dbvc') . '</label> ';
        echo '<input type="search" id="dbvc-bricks-portability-filter-search" placeholder="' . esc_attr__('item name or ID', 'dbvc') . '" />';
        echo '<label class="dbvc-bricks-portability-inline-toggle" for="dbvc-bricks-portability-hide-identical"><input type="checkbox" id="dbvc-bricks-portability-hide-identical" checked="checked" /> ' . esc_html__('Hide Unchanged Rows', 'dbvc') . '</label>';
        echo '</p>';
        echo '<p>';
        echo '<label for="dbvc-bricks-portability-bulk-action">' . esc_html__('Set Action for Filtered Rows', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-bulk-action"><option value="keep_current">' . esc_html__('Keep This Site\'s Version', 'dbvc') . '</option><option value="add_incoming">' . esc_html__('Add From Transfer Package', 'dbvc') . '</option><option value="replace_with_incoming">' . esc_html__('Replace With Transfer Package Version', 'dbvc') . '</option><option value="skip">' . esc_html__('Skip', 'dbvc') . '</option></select> ';
        echo '<button type="button" class="button" id="dbvc-bricks-portability-apply-bulk">' . esc_html__('Apply Action to Filtered Rows', 'dbvc') . '</button>';
        echo '</p>';
        echo '<p class="description dbvc-bricks-portability-row-hint">' . esc_html__('Click any review row to open its full transfer-package-versus-this-site comparison in a wider modal view.', 'dbvc') . '</p>';
        self::render_approval_controls('top');
        echo '<div id="dbvc-bricks-portability-pagination" class="dbvc-bricks-portability-pagination">';
        echo '<div class="dbvc-bricks-portability-pagination__controls">';
        echo '<label for="dbvc-bricks-portability-page-size">' . esc_html__('Rows per page', 'dbvc') . '</label> ';
        echo '<select id="dbvc-bricks-portability-page-size"><option value="25">25</option><option value="50" selected="selected">50</option><option value="100">100</option><option value="250">250</option></select> ';
        echo '<button type="button" class="button" id="dbvc-bricks-portability-page-prev">' . esc_html__('Previous', 'dbvc') . '</button> ';
        echo '<button type="button" class="button" id="dbvc-bricks-portability-page-next">' . esc_html__('Next', 'dbvc') . '</button>';
        echo '</div>';
        echo '<div id="dbvc-bricks-portability-page-summary" class="dbvc-bricks-portability-pagination__summary">' . esc_html__('Upload a transfer package to page through review rows.', 'dbvc') . '</div>';
        echo '</div>';
        echo '<div class="dbvc-bricks-portability-table-wrap">';
        echo '<table class="widefat striped" id="dbvc-bricks-portability-table"><thead><tr><th>' . esc_html__('Settings Group', 'dbvc') . '</th><th>' . esc_html__('Item', 'dbvc') . '</th><th>' . esc_html__('Item ID', 'dbvc') . '</th><th>' . esc_html__('Matched By', 'dbvc') . '</th><th>' . esc_html__('Status', 'dbvc') . '</th><th>' . esc_html__('Selected Action', 'dbvc') . '</th><th><button type="button" class="button-link dbvc-bricks-portability-sort-button" id="dbvc-bricks-portability-sort-approved-at" data-portability-sort="approved_at_gmt" aria-sort="none">' . esc_html__('Applied to This Site', 'dbvc') . '</button></th><th>' . esc_html__('Warnings', 'dbvc') . '</th><th>' . esc_html__('Choose Action', 'dbvc') . '</th></tr></thead><tbody id="dbvc-bricks-portability-table-body"><tr><td colspan="9">' . esc_html__('No review rows loaded.', 'dbvc') . '</td></tr></tbody></table>';
        echo '</div>';
        self::render_approval_controls('bottom');
        echo '</div>';
        echo '<div id="dbvc-bricks-portability-row-modal" class="dbvc-bricks-portability-modal" hidden>';
        echo '<div class="dbvc-bricks-portability-modal__backdrop" data-portability-modal-close="backdrop"></div>';
        echo '<div class="dbvc-bricks-portability-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="dbvc-bricks-portability-row-modal-title">';
        echo '<div class="dbvc-bricks-portability-modal__header">';
        echo '<div><h4 id="dbvc-bricks-portability-row-modal-title">' . esc_html__('Item Details', 'dbvc') . '</h4>';
        echo '<p id="dbvc-bricks-portability-row-modal-subtitle" class="description dbvc-bricks-portability-modal__subtitle">' . esc_html__('Select a row to inspect its exact transfer package versus this site comparison.', 'dbvc') . '</p></div>';
        echo '<button type="button" class="button-link dbvc-bricks-portability-modal__close" id="dbvc-bricks-portability-row-modal-close" data-portability-modal-close="button" aria-label="' . esc_attr__('Close item details', 'dbvc') . '">&times;</button>';
        echo '</div>';
        echo '<div id="dbvc-bricks-portability-detail" class="dbvc-bricks-portability-modal__body"><p class="dbvc-bricks-portability-empty">' . esc_html__('Select a row to inspect its exact transfer package versus this site comparison.', 'dbvc') . '</p></div>';
        echo '</div>';
        echo '</div>';
        echo '</section>';

        echo '<section id="dbvc-bricks-portability-panel-history" class="dbvc-bricks-portability-tab-panel" data-portability-panel="history" role="tabpanel" aria-labelledby="dbvc-bricks-portability-subtab-history" hidden>';
        echo '<div class="dbvc-bricks-portability-layout">';
        echo '<div class="dbvc-bricks-portability-column">';
        echo '<h3>' . esc_html__('Backups & Restore', 'dbvc') . '</h3>';
        echo '<table class="widefat striped" id="dbvc-bricks-portability-backups-table"><thead><tr><th>' . esc_html__('Backup', 'dbvc') . '</th><th>' . esc_html__('Created', 'dbvc') . '</th><th>' . esc_html__('Settings Changed', 'dbvc') . '</th><th>' . esc_html__('Actions', 'dbvc') . '</th></tr></thead><tbody id="dbvc-bricks-portability-backups-body"><tr><td colspan="4">' . esc_html__('No backups yet.', 'dbvc') . '</td></tr></tbody></table>';
        echo '</div>';
        echo '<div class="dbvc-bricks-portability-column">';
        echo '<h3>' . esc_html__('Recent Activity', 'dbvc') . '</h3>';
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
        if ((string) ($_GET['page'] ?? '') !== self::PAGE_SLUG) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
            'sessionDraftBase' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/sessions/')),
            'sessionRefreshBase' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/sessions/')),
            'backupRollbackBase' => esc_url_raw(rest_url('dbvc/v1/bricks/portability/backups/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'messages' => [
                'exported' => esc_html__('Bricks settings transfer package created.', 'dbvc'),
                'imported' => esc_html__('Bricks settings transfer package uploaded and compared.', 'dbvc'),
                'draftSaved' => esc_html__('Bricks settings transfer draft saved.', 'dbvc'),
                'applied' => esc_html__('Bricks settings transfer applied.', 'dbvc'),
                'refreshed' => esc_html__('Bricks settings comparison refreshed against this site.', 'dbvc'),
                'rolledBack' => esc_html__('Bricks settings restore completed.', 'dbvc'),
            ],
        ]);
    }

    /**
     * @param string $position
     * @return void
     */
    private static function render_approval_controls($position)
    {
        $position = sanitize_key((string) $position);
        $is_top = $position === 'top';
        $confirm_id = $is_top ? 'dbvc-bricks-portability-confirm-apply-top' : 'dbvc-bricks-portability-confirm-apply';
        $save_id = $is_top ? 'dbvc-bricks-portability-save-draft-top' : 'dbvc-bricks-portability-save-draft';
        $apply_id = $is_top ? 'dbvc-bricks-portability-apply-button-top' : 'dbvc-bricks-portability-apply-button';

        echo '<div class="dbvc-bricks-portability-approval-controls" data-portability-approval-controls="' . esc_attr($position) . '">';
        echo '<div class="dbvc-bricks-portability-approval-controls__confirm">';
        echo '<label for="' . esc_attr($confirm_id) . '"><input type="checkbox" id="' . esc_attr($confirm_id) . '" data-portability-confirm-apply /> ' . esc_html__('I confirm these transfer package changes should be applied to this site after creating a backup.', 'dbvc') . '</label>';
        echo '</div>';
        echo '<div class="dbvc-bricks-portability-approval-controls__actions">';
        echo '<button type="button" class="button" id="' . esc_attr($save_id) . '" data-portability-save-draft>' . esc_html__('Save Review Draft', 'dbvc') . '</button> ';
        echo '<button type="button" class="button button-primary" id="' . esc_attr($apply_id) . '" data-portability-apply-button>' . esc_html__('Apply Selected Changes', 'dbvc') . '</button>';
        echo '</div>';
        echo '</div>';
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
