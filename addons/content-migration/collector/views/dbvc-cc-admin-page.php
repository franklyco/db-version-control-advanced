<?php

if (! defined('ABSPATH')) {
    exit;
}

$active_tab = isset($active_tab) ? (string) $active_tab : 'collect';
$tabs = isset($tabs) && is_array($tabs) ? $tabs : ['collect' => __('Collect', 'dbvc')];
?>
<div class="wrap dbvc-cc-admin-wrap">
    <h1><?php esc_html_e('Content Migration', 'dbvc'); ?></h1>
    <p><?php esc_html_e('Collect source content, explore crawl artifacts, and manage default migration settings.', 'dbvc'); ?></p>

    <nav class="nav-tab-wrapper dbvc-cc-tab-nav" aria-label="<?php esc_attr_e('Content Migration Tabs', 'dbvc'); ?>">
        <?php foreach ($tabs as $tab_key => $tab_label) : ?>
            <?php
            $tab_query_args = [
                'page' => DBVC_CC_Contracts::ADMIN_MENU_SLUG,
                'tab'  => $tab_key,
            ];
            if ($tab_key === 'configure' && isset($active_configure_subtab)) {
                $tab_query_args['configure_subtab'] = (string) $active_configure_subtab;
            }
            $tab_url = add_query_arg(
                $tab_query_args,
                admin_url('admin.php')
            );
            $is_active = $active_tab === $tab_key;
            ?>
            <a href="<?php echo esc_url($tab_url); ?>" class="nav-tab <?php echo $is_active ? 'nav-tab-active' : ''; ?>" <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="dbvc-cc-tab-panel dbvc-cc-tab-panel-<?php echo esc_attr($active_tab); ?>">
        <?php
        if ($active_tab === 'explore') {
            require DBVC_PLUGIN_PATH . 'addons/content-migration/collector/views/tabs/dbvc-cc-tab-explore.php';
        } elseif ($active_tab === 'configure') {
            require DBVC_PLUGIN_PATH . 'addons/content-migration/collector/views/tabs/dbvc-cc-tab-configure.php';
        } else {
            require DBVC_PLUGIN_PATH . 'addons/content-migration/collector/views/tabs/dbvc-cc-tab-collect.php';
        }
        ?>
    </div>
</div>
