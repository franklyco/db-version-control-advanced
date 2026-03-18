<?php

if (! defined('ABSPATH')) {
    exit;
}

$options = isset($options) && is_array($options) ? $options : DBVC_CC_Settings_Service::get_options();
?>
<div class="wrap">
    <h1><?php esc_html_e('Content Migration Explorer', 'dbvc'); ?></h1>
    <p><?php esc_html_e('Visualize crawl artifacts as an expandable sitemap graph and inspect per-page content.', 'dbvc'); ?></p>

    <?php require DBVC_PLUGIN_PATH . 'addons/content-migration/explorer/views/dbvc-cc-explorer-content.php'; ?>
</div>
