<?php

if (! defined('ABSPATH')) {
    exit;
}

$options = isset($options) && is_array($options) ? $options : DBVC_CC_Settings_Service::get_options();
?>
<section class="dbvc-cc-explore-section" id="dbvc-cc-explore-panel" aria-label="<?php esc_attr_e('Explore', 'dbvc'); ?>">
    <h2><?php esc_html_e('Explore', 'dbvc'); ?></h2>
    <p><?php esc_html_e('Inspect crawled nodes, compare raw vs sanitized output, and run AI reruns where needed.', 'dbvc'); ?></p>

    <?php require DBVC_PLUGIN_PATH . 'addons/content-migration/explorer/views/dbvc-cc-explorer-content.php'; ?>
</section>
