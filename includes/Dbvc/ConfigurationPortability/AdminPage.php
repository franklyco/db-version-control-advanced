<?php

namespace Dbvc\ConfigurationPortability;

if (! defined('WPINC')) {
    die;
}

final class AdminPage
{
    public const PAGE_SLUG = 'dbvc-configuration-portability';
    private const ACTION_EXPORT = 'dbvc_config_portability_export';
    private const ACTION_DOWNLOAD = 'dbvc_config_portability_download';
    private const ACTION_UPLOAD = 'dbvc_config_portability_upload';
    private const ACTION_APPLY = 'dbvc_config_portability_apply';
    private const ACTION_ROLLBACK = 'dbvc_config_portability_rollback';
    private const NOTICE_TRANSIENT_PREFIX = 'dbvc_config_portability_notice_';

    /**
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_' . self::ACTION_EXPORT, [self::class, 'handle_export']);
        add_action('admin_post_' . self::ACTION_DOWNLOAD, [self::class, 'handle_download']);
        add_action('admin_post_' . self::ACTION_UPLOAD, [self::class, 'handle_upload']);
        add_action('admin_post_' . self::ACTION_APPLY, [self::class, 'handle_apply']);
        add_action('admin_post_' . self::ACTION_ROLLBACK, [self::class, 'handle_rollback']);
    }

    /**
     * @return void
     */
    public static function register_menu(): void
    {
        add_submenu_page(
            'dbvc-export',
            esc_html__('Configuration Portability', 'dbvc'),
            esc_html__('Config Portability', 'dbvc'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /**
     * @return void
     */
    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'dbvc'), 403);
        }

        $notice = self::consume_notice();
        $package_id = isset($_GET['package_id']) ? sanitize_key((string) wp_unslash($_GET['package_id'])) : '';
        $session_id = isset($_GET['session_id']) ? sanitize_key((string) wp_unslash($_GET['session_id'])) : '';
        $summary = $package_id !== '' ? ExportPackageBuilder::get_export_summary($package_id) : null;
        $session = $session_id !== '' ? ImportSessionService::get_session($session_id) : null;
        $status = Registry::get_status();
        $domains = isset($status['domains']) && is_array($status['domains']) ? $status['domains'] : [];

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DBVC Configuration Portability', 'dbvc'); ?></h1>
            <?php self::render_page_styles(); ?>

            <?php self::render_notice($notice); ?>
            <?php self::render_export_summary($summary); ?>
            <?php self::render_import_session($session); ?>

            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;align-items:start;">
                <div class="card" style="max-width:none;">
                    <h2><?php esc_html_e('Export Settings Package', 'dbvc'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_EXPORT); ?>" />
                        <?php wp_nonce_field(self::ACTION_EXPORT, self::ACTION_EXPORT . '_nonce'); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="dbvc_config_profile"><?php esc_html_e('Profile', 'dbvc'); ?></label></th>
                                <td>
                                    <select id="dbvc_config_profile" name="profile">
                                        <option value="agency_baseline"><?php esc_html_e('Agency Baseline', 'dbvc'); ?></option>
                                        <option value="add_on_baseline"><?php esc_html_e('Add-On Baseline', 'dbvc'); ?></option>
                                        <option value="core_import_export_baseline"><?php esc_html_e('Core Import/Export Baseline', 'dbvc'); ?></option>
                                        <option value="full_review"><?php esc_html_e('Full Review', 'dbvc'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Domains', 'dbvc'); ?></th>
                                <td>
                                    <fieldset>
                                        <?php foreach ($domains as $domain) : ?>
                                            <?php
                                            $domain_key = is_array($domain) ? sanitize_key((string) ($domain['key'] ?? '')) : '';
                                            if ($domain_key === '') {
                                                continue;
                                            }
                                            ?>
                                            <label style="display:block;margin:0 0 6px;">
                                                <input type="checkbox" name="domains[]" value="<?php echo esc_attr($domain_key); ?>" checked />
                                                <?php echo esc_html(is_array($domain) ? (string) ($domain['label'] ?? $domain_key) : $domain_key); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dbvc_config_environment"><?php esc_html_e('Environment Label', 'dbvc'); ?></label></th>
                                <td><input type="text" id="dbvc_config_environment" name="environment" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dbvc_config_notes"><?php esc_html_e('Notes', 'dbvc'); ?></label></th>
                                <td><textarea id="dbvc_config_notes" name="notes" rows="3" class="large-text"></textarea></td>
                            </tr>
                        </table>

                        <p><button type="submit" class="button button-primary"><?php esc_html_e('Generate Export Package', 'dbvc'); ?></button></p>
                    </form>
                </div>

                <div class="card" style="max-width:none;">
                    <h2><?php esc_html_e('Upload Package For Review', 'dbvc'); ?></h2>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_UPLOAD); ?>" />
                        <?php wp_nonce_field(self::ACTION_UPLOAD, self::ACTION_UPLOAD . '_nonce'); ?>
                        <p><input type="file" name="dbvc_config_package" accept=".zip,application/zip" required /></p>
                        <p><button type="submit" class="button button-primary"><?php esc_html_e('Upload And Preview Diff', 'dbvc'); ?></button></p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @return void
     */
    public static function handle_export(): void
    {
        self::assert_can_manage();
        check_admin_referer(self::ACTION_EXPORT, self::ACTION_EXPORT . '_nonce');

        $domains = isset($_POST['domains']) && is_array($_POST['domains'])
            ? array_values(array_map('sanitize_key', wp_unslash($_POST['domains'])))
            : [];
        $profile = isset($_POST['profile']) ? sanitize_key((string) wp_unslash($_POST['profile'])) : 'agency_baseline';
        $environment = isset($_POST['environment']) ? sanitize_key((string) wp_unslash($_POST['environment'])) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field((string) wp_unslash($_POST['notes'])) : '';

        $result = ExportPackageBuilder::create_export(
            ['domains' => $domains],
            [
                'profile' => $profile,
                'environment' => $environment,
                'notes' => $notes,
            ]
        );

        if (\is_wp_error($result)) {
            self::set_notice('error', $result->get_error_message());
            wp_safe_redirect(self::get_page_url());
            exit;
        }

        self::set_notice('success', __('Configuration export package generated.', 'dbvc'));
        wp_safe_redirect(add_query_arg('package_id', rawurlencode((string) $result['package_id']), self::get_page_url()));
        exit;
    }

    /**
     * @return void
     */
    public static function handle_upload(): void
    {
        self::assert_can_manage();
        check_admin_referer(self::ACTION_UPLOAD, self::ACTION_UPLOAD . '_nonce');

        $file = isset($_FILES['dbvc_config_package']) && is_array($_FILES['dbvc_config_package'])
            ? $_FILES['dbvc_config_package']
            : [];

        $result = ImportSessionService::import_uploaded_package($file);
        if (\is_wp_error($result)) {
            self::set_notice('error', $result->get_error_message());
            wp_safe_redirect(self::get_page_url());
            exit;
        }

        self::set_notice('success', __('Configuration package uploaded and staged for review. No settings were applied.', 'dbvc'));
        wp_safe_redirect(add_query_arg('session_id', rawurlencode((string) $result['session_id']), self::get_page_url()));
        exit;
    }

    /**
     * @return void
     */
    public static function handle_download(): void
    {
        self::assert_can_manage();

        $package_id = isset($_GET['package_id']) ? sanitize_key((string) wp_unslash($_GET['package_id'])) : '';
        if ($package_id === '') {
            wp_die(esc_html__('Missing configuration portability package identifier.', 'dbvc'), 400);
        }

        check_admin_referer(self::ACTION_DOWNLOAD . '_' . $package_id);

        $summary = ExportPackageBuilder::get_export_summary($package_id);
        if (\is_wp_error($summary)) {
            wp_die(esc_html($summary->get_error_message()), 404);
        }

        $zip_path = isset($summary['zip_path']) ? (string) $summary['zip_path'] : '';
        if ($zip_path === '' || ! is_file($zip_path)) {
            wp_die(esc_html__('Configuration portability ZIP is unavailable.', 'dbvc'), 404);
        }

        $filename = isset($summary['download_filename']) ? (string) $summary['download_filename'] : 'dbvc-configuration-portability.zip';
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        exit;
    }

    /**
     * @return void
     */
    public static function handle_apply(): void
    {
        self::assert_can_manage();

        $session_id = isset($_POST['session_id']) ? sanitize_key((string) wp_unslash($_POST['session_id'])) : '';
        if ($session_id === '') {
            wp_die(esc_html__('Missing configuration portability import session.', 'dbvc'), 400);
        }

        check_admin_referer(self::ACTION_APPLY . '_' . $session_id, self::ACTION_APPLY . '_nonce');

        $raw_decisions = isset($_POST['environment_decisions']) && is_array($_POST['environment_decisions'])
            ? wp_unslash($_POST['environment_decisions'])
            : [];
        $decisions = self::sanitize_environment_decisions(is_array($raw_decisions) ? $raw_decisions : []);
        $confirmed = ! empty($_POST['confirm_apply']);

        $result = ImportSessionService::apply_session($session_id, $decisions, [
            'confirm_apply' => $confirmed,
        ]);

        if (\is_wp_error($result)) {
            self::set_notice('error', $result->get_error_message());
            wp_safe_redirect(add_query_arg('session_id', rawurlencode($session_id), self::get_page_url()));
            exit;
        }

        $summary = isset($result['apply_summary']) && is_array($result['apply_summary']) ? $result['apply_summary'] : [];
        self::set_notice(
            'success',
            sprintf(
                __('Configuration import applied. Applied fields: %d. Skipped fields: %d.', 'dbvc'),
                (int) ($summary['applied_fields'] ?? 0),
                (int) ($summary['skipped_fields'] ?? 0)
            )
        );
        wp_safe_redirect(add_query_arg('session_id', rawurlencode($session_id), self::get_page_url()));
        exit;
    }

    /**
     * @return void
     */
    public static function handle_rollback(): void
    {
        self::assert_can_manage();

        $session_id = isset($_POST['session_id']) ? sanitize_key((string) wp_unslash($_POST['session_id'])) : '';
        if ($session_id === '') {
            wp_die(esc_html__('Missing configuration portability import session.', 'dbvc'), 400);
        }

        check_admin_referer(self::ACTION_ROLLBACK . '_' . $session_id, self::ACTION_ROLLBACK . '_nonce');

        $result = ImportSessionService::rollback_session($session_id, [
            'confirm_rollback' => ! empty($_POST['confirm_rollback']),
        ]);

        if (\is_wp_error($result)) {
            self::set_notice('error', $result->get_error_message());
            wp_safe_redirect(add_query_arg('session_id', rawurlencode($session_id), self::get_page_url()));
            exit;
        }

        $summary = isset($result['rollback_summary']) && is_array($result['rollback_summary']) ? $result['rollback_summary'] : [];
        self::set_notice(
            'success',
            sprintf(
                __('Configuration rollback completed. Restored fields: %d. Deleted fields: %d.', 'dbvc'),
                (int) ($summary['restored_fields'] ?? 0),
                (int) ($summary['deleted_fields'] ?? 0)
            )
        );
        wp_safe_redirect(add_query_arg('session_id', rawurlencode($session_id), self::get_page_url()));
        exit;
    }

    /**
     * @param array<string, mixed>|\WP_Error|null $summary
     * @return void
     */
    private static function render_export_summary($summary): void
    {
        if ($summary === null) {
            return;
        }

        if (\is_wp_error($summary)) {
            self::render_notice(['type' => 'error', 'message' => $summary->get_error_message()]);
            return;
        }

        $package_id = sanitize_key((string) ($summary['package_id'] ?? ''));
        ?>
        <div class="notice notice-success">
            <p>
                <?php echo esc_html(sprintf(__('Export package `%s` is ready.', 'dbvc'), $package_id)); ?>
                <a class="button button-small" href="<?php echo esc_url(self::get_download_url($package_id)); ?>"><?php esc_html_e('Download ZIP', 'dbvc'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed>|\WP_Error|null $session
     * @return void
     */
    private static function render_import_session($session): void
    {
        if ($session === null) {
            return;
        }

        if (\is_wp_error($session)) {
            self::render_notice(['type' => 'error', 'message' => $session->get_error_message()]);
            return;
        }

        $summary = isset($session['summary']) && is_array($session['summary']) ? $session['summary'] : [];
        $diffs = isset($session['diffs']) && is_array($session['diffs']) ? $session['diffs'] : [];
        $compatibility_warnings = isset($session['compatibility_warnings']) && is_array($session['compatibility_warnings']) ? $session['compatibility_warnings'] : [];
        $session_id = sanitize_key((string) ($session['session_id'] ?? ''));
        $applied = ! empty($session['applied_at_gmt']);
        $rolled_back = ! empty($session['rolled_back_at_gmt']);
        ?>
        <div class="card" style="max-width:none;">
            <h2><?php esc_html_e('Import Review Session', 'dbvc'); ?></h2>
            <p>
                <?php
                if ($rolled_back) {
                    echo esc_html(sprintf(__('Session `%s` was rolled back at %s.', 'dbvc'), $session_id, (string) ($session['rolled_back_at_gmt'] ?? '')));
                } elseif ($applied) {
                    echo esc_html(sprintf(__('Session `%s` was applied at %s.', 'dbvc'), $session_id, (string) ($session['applied_at_gmt'] ?? '')));
                } else {
                    echo esc_html(sprintf(__('Session `%s` staged. No settings have been applied.', 'dbvc'), $session_id));
                }
                ?>
            </p>
            <p>
                <?php echo esc_html(sprintf(__('Domains: %d. Rows: %d.', 'dbvc'), (int) ($summary['domain_count'] ?? 0), (int) ($summary['row_count'] ?? 0))); ?>
            </p>
            <?php if (! empty($summary['statuses']) && is_array($summary['statuses'])) : ?>
                <ul>
                    <?php foreach ($summary['statuses'] as $status => $count) : ?>
                        <li><?php echo esc_html($status . ': ' . (int) $count); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php self::render_compatibility_warnings($compatibility_warnings); ?>

            <?php if ($applied) : ?>
                <?php $apply_summary = isset($session['apply_summary']) && is_array($session['apply_summary']) ? $session['apply_summary'] : []; ?>
                <div class="notice notice-info inline">
                    <p>
                        <?php
                        echo esc_html(sprintf(
                            __('Applied fields: %d. Skipped fields: %d. Backup: %s.', 'dbvc'),
                            (int) ($apply_summary['applied_fields'] ?? 0),
                            (int) ($apply_summary['skipped_fields'] ?? 0),
                            (string) ($session['backup_id'] ?? '')
                        ));
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($applied && ! $rolled_back) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:16px 0;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ROLLBACK); ?>" />
                    <input type="hidden" name="session_id" value="<?php echo esc_attr($session_id); ?>" />
                    <?php wp_nonce_field(self::ACTION_ROLLBACK . '_' . $session_id, self::ACTION_ROLLBACK . '_nonce'); ?>
                    <label>
                        <input type="checkbox" name="confirm_rollback" value="1" required />
                        <?php esc_html_e('Restore the settings backup captured immediately before this import was applied.', 'dbvc'); ?>
                    </label>
                    <p><button type="submit" class="button"><?php esc_html_e('Rollback Applied Import', 'dbvc'); ?></button></p>
                </form>
            <?php endif; ?>

            <?php if (! $applied && $session_id !== '') : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:16px 0 24px;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_APPLY); ?>" />
                    <input type="hidden" name="session_id" value="<?php echo esc_attr($session_id); ?>" />
                    <?php wp_nonce_field(self::ACTION_APPLY . '_' . $session_id, self::ACTION_APPLY . '_nonce'); ?>
                    <?php self::render_environment_decisions($diffs); ?>
                    <label>
                        <input type="checkbox" name="confirm_apply" value="1" required />
                        <?php esc_html_e('Apply this reviewed package to the current site and capture a rollback backup first.', 'dbvc'); ?>
                    </label>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Apply Reviewed Settings', 'dbvc'); ?></button></p>
                </form>
            <?php endif; ?>

            <?php self::render_domain_diff_accordion($diffs); ?>
        </div>
        <?php
    }

    /**
     * @return void
     */
    private static function render_page_styles(): void
    {
        ?>
        <style>
            .dbvc-config-domain-accordion {
                margin-top: 20px;
            }
            .dbvc-config-domain {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                margin: 0 0 10px;
            }
            .dbvc-config-domain summary {
                align-items: center;
                cursor: pointer;
                display: flex;
                gap: 16px;
                justify-content: space-between;
                padding: 12px 14px;
            }
            .dbvc-config-domain[open] summary {
                border-bottom: 1px solid #dcdcde;
            }
            .dbvc-config-domain-title {
                align-items: center;
                display: flex;
                gap: 8px;
                min-width: 220px;
            }
            .dbvc-config-domain-title strong {
                font-size: 14px;
            }
            .dbvc-config-domain-count {
                color: #646970;
                font-size: 12px;
            }
            .dbvc-config-domain-badges {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                justify-content: flex-end;
            }
            .dbvc-config-badge {
                align-items: center;
                border: 1px solid #c3c4c7;
                border-radius: 3px;
                color: #1d2327;
                display: inline-flex;
                font-size: 12px;
                gap: 4px;
                line-height: 1;
                padding: 4px 6px;
                white-space: nowrap;
            }
            .dbvc-config-badge .dashicons {
                font-size: 14px;
                height: 14px;
                line-height: 14px;
                width: 14px;
            }
            .dbvc-config-badge-review {
                background: #fcf9e8;
                border-color: #dba617;
            }
            .dbvc-config-badge-changed {
                background: #eef7ff;
                border-color: #72aee6;
            }
            .dbvc-config-badge-missing,
            .dbvc-config-badge-blocked {
                background: #fcf0f1;
                border-color: #d63638;
            }
            .dbvc-config-badge-same {
                background: #f0f6fc;
                border-color: #8c8f94;
                color: #50575e;
            }
            .dbvc-config-domain-body {
                padding: 0 14px 14px;
            }
            .dbvc-config-value-preview {
                display: inline-block;
                max-width: 360px;
                overflow: hidden;
                text-overflow: ellipsis;
                vertical-align: bottom;
                white-space: nowrap;
            }
        </style>
        <?php
    }

    /**
     * @param array<int|string, mixed> $warnings
     * @return void
     */
    private static function render_compatibility_warnings(array $warnings): void
    {
        if (empty($warnings)) {
            return;
        }

        ?>
        <div class="notice notice-warning inline">
            <p><?php esc_html_e('Review these compatibility notes before applying.', 'dbvc'); ?></p>
            <ul>
                <?php foreach ($warnings as $warning) : ?>
                    <?php if (! is_array($warning)) { continue; } ?>
                    <li>
                        <?php
                        $domain = sanitize_key((string) ($warning['domain'] ?? ''));
                        $message = (string) ($warning['message'] ?? '');
                        echo esc_html($domain !== '' ? '[' . $domain . '] ' . $message : $message);
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $diffs
     * @return void
     */
    private static function render_domain_diff_accordion(array $diffs): void
    {
        if (empty($diffs)) {
            return;
        }

        ?>
        <div class="dbvc-config-domain-accordion">
            <h3><?php esc_html_e('Domain Review', 'dbvc'); ?></h3>
            <?php foreach ($diffs as $domain_key => $diff) : ?>
                <?php
                $domain_key = sanitize_key((string) $domain_key);
                if ($domain_key === '') {
                    continue;
                }

                $rows = isset($diff['rows']) && is_array($diff['rows']) ? array_values($diff['rows']) : [];
                $counts = self::count_domain_statuses($rows);
                $needs_review = self::domain_needs_review($counts);
                ?>
                <details class="dbvc-config-domain">
                    <summary>
                        <span class="dbvc-config-domain-title">
                            <span class="dashicons <?php echo esc_attr($needs_review ? 'dashicons-warning' : 'dashicons-yes-alt'); ?>" aria-hidden="true"></span>
                            <strong><?php echo esc_html(self::get_domain_label($domain_key)); ?></strong>
                            <span class="dbvc-config-domain-count"><?php echo esc_html(sprintf(__('%d rows', 'dbvc'), count($rows))); ?></span>
                        </span>
                        <?php self::render_domain_status_badges($counts); ?>
                    </summary>
                    <div class="dbvc-config-domain-body">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Field', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Status', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Policy', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Current', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Incoming', 'dbvc'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row) : ?>
                                    <?php if (! is_array($row)) { continue; } ?>
                                    <tr>
                                        <td><?php echo esc_html((string) ($row['label'] ?? $row['field'] ?? '')); ?></td>
                                        <td><?php echo esc_html(self::get_status_label((string) ($row['status'] ?? ''))); ?></td>
                                        <td><?php echo esc_html((string) ($row['policy'] ?? '')); ?></td>
                                        <td><code class="dbvc-config-value-preview"><?php echo esc_html(self::format_preview_value($row['current_value'] ?? '')); ?></code></td>
                                        <td><code class="dbvc-config-value-preview"><?php echo esc_html(self::format_preview_value($row['incoming_value'] ?? '')); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<string, int>
     */
    private static function count_domain_statuses(array $rows): array
    {
        $counts = [
            'needs_environment_value' => 0,
            'blocked_secret' => 0,
            'incoming_missing' => 0,
            'changed' => 0,
            'same' => 0,
            'other' => 0,
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = sanitize_key((string) ($row['status'] ?? 'other'));
            if (! isset($counts[$status])) {
                $status = 'other';
            }

            $counts[$status]++;
        }

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     * @return bool
     */
    private static function domain_needs_review(array $counts): bool
    {
        foreach (['needs_environment_value', 'blocked_secret', 'incoming_missing', 'changed', 'other'] as $status) {
            if ((int) ($counts[$status] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $counts
     * @return void
     */
    private static function render_domain_status_badges(array $counts): void
    {
        $badge_order = [
            'needs_environment_value',
            'blocked_secret',
            'incoming_missing',
            'changed',
            'other',
            'same',
        ];

        echo '<span class="dbvc-config-domain-badges">';
        foreach ($badge_order as $status) {
            $count = (int) ($counts[$status] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $meta = self::get_status_badge_meta($status);
            printf(
                '<span class="dbvc-config-badge dbvc-config-badge-%1$s"><span class="dashicons %2$s" aria-hidden="true"></span>%3$s</span>',
                esc_attr((string) $meta['class']),
                esc_attr((string) $meta['icon']),
                esc_html(sprintf('%s: %d', (string) $meta['label'], $count))
            );
        }
        echo '</span>';
    }

    /**
     * @param string $status
     * @return array<string, string>
     */
    private static function get_status_badge_meta($status): array
    {
        $status = sanitize_key((string) $status);
        $map = [
            'needs_environment_value' => [
                'label' => __('Review', 'dbvc'),
                'icon' => 'dashicons-visibility',
                'class' => 'review',
            ],
            'blocked_secret' => [
                'label' => __('Secret skipped', 'dbvc'),
                'icon' => 'dashicons-lock',
                'class' => 'blocked',
            ],
            'incoming_missing' => [
                'label' => __('Missing', 'dbvc'),
                'icon' => 'dashicons-warning',
                'class' => 'missing',
            ],
            'changed' => [
                'label' => __('Changed', 'dbvc'),
                'icon' => 'dashicons-update',
                'class' => 'changed',
            ],
            'same' => [
                'label' => __('Same', 'dbvc'),
                'icon' => 'dashicons-yes-alt',
                'class' => 'same',
            ],
        ];

        return $map[$status] ?? [
            'label' => __('Other', 'dbvc'),
            'icon' => 'dashicons-info',
            'class' => 'review',
        ];
    }

    /**
     * @param string $status
     * @return string
     */
    private static function get_status_label($status): string
    {
        $meta = self::get_status_badge_meta($status);

        return (string) $meta['label'];
    }

    /**
     * @param string $domain_key
     * @return string
     */
    private static function get_domain_label($domain_key): string
    {
        $domain_key = sanitize_key((string) $domain_key);
        $provider = Registry::get_provider($domain_key);

        return $provider ? $provider->get_label() : $domain_key;
    }

    /**
     * @param array<string, mixed> $diffs
     * @return void
     */
    private static function render_environment_decisions(array $diffs): void
    {
        $rows_by_domain = [];
        $blocked_rows = [];
        foreach ($diffs as $domain_key => $diff) {
            $domain_key = sanitize_key((string) $domain_key);
            $rows = isset($diff['rows']) && is_array($diff['rows']) ? $diff['rows'] : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $status = sanitize_key((string) ($row['status'] ?? ''));
                if ($status === 'needs_environment_value') {
                    $rows_by_domain[$domain_key][] = $row;
                } elseif ($status === 'blocked_secret') {
                    $blocked_rows[$domain_key][] = $row;
                }
            }
        }

        if (! empty($rows_by_domain)) : ?>
            <h3><?php esc_html_e('Environment-Specific Values', 'dbvc'); ?></h3>
            <p><?php esc_html_e('Choose whether each environment-specific field keeps the current site value or is replaced during apply.', 'dbvc'); ?></p>
            <table class="widefat striped" style="margin-bottom:16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Domain', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Field', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Current', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Decision', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Replacement', 'dbvc'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows_by_domain as $domain_key => $rows) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $field_key = sanitize_key((string) ($row['field'] ?? ''));
                            if ($field_key === '') {
                                continue;
                            }
                            $input_name = 'environment_decisions[' . $domain_key . '][' . $field_key . ']';
                            $type = (string) ($row['type'] ?? 'text');
                            $input_type = in_array($type, ['secret', 'key_id'], true) ? 'password' : 'text';
                            ?>
                            <tr>
                                <td><?php echo esc_html($domain_key); ?></td>
                                <td><?php echo esc_html((string) ($row['label'] ?? $field_key)); ?></td>
                                <td><code><?php echo esc_html(self::format_preview_value($row['current_value'] ?? '')); ?></code></td>
                                <td>
                                    <select name="<?php echo esc_attr($input_name . '[action]'); ?>" required>
                                        <option value=""><?php esc_html_e('Choose...', 'dbvc'); ?></option>
                                        <option value="keep"><?php esc_html_e('Keep Current', 'dbvc'); ?></option>
                                        <option value="replace"><?php esc_html_e('Replace', 'dbvc'); ?></option>
                                    </select>
                                </td>
                                <td>
                                    <input type="<?php echo esc_attr($input_type); ?>" name="<?php echo esc_attr($input_name . '[value]'); ?>" class="regular-text" autocomplete="off" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (! empty($blocked_rows)) : ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e('Some secrets were excluded from the package and will not be applied. Configure those values directly on the target site if needed.', 'dbvc'); ?></p>
            </div>
        <?php endif;
    }

    /**
     * @param array<string, string>|null $notice
     * @return void
     */
    private static function render_notice($notice): void
    {
        if (empty($notice['message'])) {
            return;
        }

        $type = isset($notice['type']) && $notice['type'] === 'error' ? 'error' : 'success';
        printf(
            '<div class="notice notice-%1$s"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html((string) $notice['message'])
        );
    }

    /**
     * @return string
     */
    public static function get_page_url(): string
    {
        return add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php'));
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function format_preview_value($value): string
    {
        if (is_array($value)) {
            $json = wp_json_encode($value);
            return is_string($json) ? $json : '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param string $package_id
     * @return string
     */
    private static function get_download_url($package_id): string
    {
        $package_id = sanitize_key((string) $package_id);

        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => self::ACTION_DOWNLOAD,
                    'package_id' => $package_id,
                ],
                admin_url('admin-post.php')
            ),
            self::ACTION_DOWNLOAD . '_' . $package_id
        );
    }

    /**
     * @return void
     */
    private static function assert_can_manage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'dbvc'), 403);
        }
    }

    /**
     * @param string $type
     * @param string $message
     * @return void
     */
    private static function set_notice($type, $message): void
    {
        set_transient(self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), [
            'type' => $type === 'error' ? 'error' : 'success',
            'message' => sanitize_text_field((string) $message),
        ], MINUTE_IN_SECONDS);
    }

    /**
     * @param array<string, mixed> $raw_decisions
     * @return array<string, array<string, array<string, string>>>
     */
    private static function sanitize_environment_decisions(array $raw_decisions): array
    {
        $decisions = [];
        foreach ($raw_decisions as $domain_key => $domain_decisions) {
            $domain_key = sanitize_key((string) $domain_key);
            if ($domain_key === '' || ! is_array($domain_decisions)) {
                continue;
            }

            foreach ($domain_decisions as $field_key => $decision) {
                $field_key = sanitize_key((string) $field_key);
                if ($field_key === '' || ! is_array($decision)) {
                    continue;
                }

                $action = sanitize_key((string) ($decision['action'] ?? ''));
                if (! in_array($action, ['keep', 'replace'], true)) {
                    $action = '';
                }

                $decisions[$domain_key][$field_key] = [
                    'action' => $action,
                    'value' => isset($decision['value']) && is_scalar($decision['value']) ? (string) $decision['value'] : '',
                ];
            }
        }

        return $decisions;
    }

    /**
     * @return array<string, string>|null
     */
    private static function consume_notice()
    {
        $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);

        return is_array($notice) ? $notice : null;
    }
}
