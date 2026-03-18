<?php

if (! defined('ABSPATH')) {
    exit;
}

$options = isset($options) && is_array($options) ? $options : DBVC_CC_Settings_Service::get_options();
$uploads_info = wp_upload_dir();
$uploads_base = isset($uploads_info['basedir']) ? trailingslashit((string) $uploads_info['basedir']) : '';
$configure_subtabs = isset($configure_subtabs) && is_array($configure_subtabs) ? $configure_subtabs : ['general' => __('General', 'dbvc')];
$active_configure_subtab = isset($active_configure_subtab) ? (string) $active_configure_subtab : 'general';
?>
<section class="dbvc-cc-configure-section dbvc-cc-configure-core" aria-label="<?php esc_attr_e('Core addon defaults', 'dbvc'); ?>">
    <h2><?php esc_html_e('Configure', 'dbvc'); ?></h2>
    <p><?php esc_html_e('Set addon defaults used by collect, AI processing, and storage behavior.', 'dbvc'); ?></p>

    <nav class="nav-tab-wrapper dbvc-cc-configure-subtab-nav" aria-label="<?php esc_attr_e('Configure Subtabs', 'dbvc'); ?>">
        <?php foreach ($configure_subtabs as $subtab_key => $subtab_label) : ?>
            <?php
            $subtab_url = add_query_arg(
                [
                    'page' => DBVC_CC_Contracts::ADMIN_MENU_SLUG,
                    'tab' => 'configure',
                    'configure_subtab' => $subtab_key,
                ],
                admin_url('admin.php')
            );
            $is_subtab_active = ($active_configure_subtab === $subtab_key);
            ?>
            <a href="<?php echo esc_url($subtab_url); ?>" class="nav-tab <?php echo $is_subtab_active ? 'nav-tab-active' : ''; ?>" <?php echo $is_subtab_active ? 'aria-current="page"' : ''; ?>>
                <?php echo esc_html($subtab_label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="options.php">
        <?php settings_fields(DBVC_CC_Contracts::SETTINGS_GROUP); ?>

        <?php
        if ($active_configure_subtab === 'advanced-collection-controls') {
            require DBVC_PLUGIN_PATH . 'addons/content-migration/collector/views/tabs/configure/dbvc-cc-configure-advanced-collection-controls.php';
        } else {
            require DBVC_PLUGIN_PATH . 'addons/content-migration/collector/views/tabs/configure/dbvc-cc-configure-general.php';
        }
        ?>

        <?php submit_button(__('Save Settings', 'dbvc')); ?>
    </form>
</section>
