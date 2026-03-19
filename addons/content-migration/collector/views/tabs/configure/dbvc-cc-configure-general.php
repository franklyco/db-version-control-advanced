<?php

if (! defined('ABSPATH')) {
    exit;
}
?>
<section class="dbvc-cc-configure-section" aria-label="<?php esc_attr_e('Primary settings', 'dbvc'); ?>">
    <h3><?php esc_html_e('Primary Settings', 'dbvc'); ?></h3>
    <table class="form-table">
        <tr valign="top">
            <th scope="row"><label for="storage_path"><?php esc_html_e('Storage Folder', 'dbvc'); ?></label></th>
            <td>
                <code><?php echo esc_html($uploads_base); ?></code>
                <input type="text" id="storage_path" name="dbvc_cc_settings[storage_path]" value="<?php echo esc_attr($options['storage_path']); ?>" class="regular-text" />
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="dev_mode"><?php esc_html_e('Dev Mode', 'dbvc'); ?></label></th>
            <td>
                <input type="hidden" name="dbvc_cc_settings[dev_mode]" value="0" />
                <label>
                    <input type="checkbox" id="dev_mode" name="dbvc_cc_settings[dev_mode]" value="1" <?php checked(! empty($options['dev_mode'])); ?> />
                    <?php esc_html_e('Enable dev copies/logs for local inspection.', 'dbvc'); ?>
                </label>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="openai_model"><?php esc_html_e('OpenAI Model', 'dbvc'); ?></label></th>
            <td>
                <input type="text" id="openai_model" name="dbvc_cc_settings[openai_model]" value="<?php echo esc_attr($options['openai_model']); ?>" class="regular-text" />
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="openai_api_key"><?php esc_html_e('OpenAI API Key', 'dbvc'); ?></label></th>
            <td>
                <input type="password" id="openai_api_key" name="dbvc_cc_settings[openai_api_key]" value="<?php echo esc_attr($options['openai_api_key']); ?>" class="regular-text" autocomplete="off" />
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="prompt_version"><?php esc_html_e('Prompt Version', 'dbvc'); ?></label></th>
            <td>
                <input type="text" id="prompt_version" name="dbvc_cc_settings[prompt_version]" value="<?php echo esc_attr($options['prompt_version']); ?>" class="regular-text" />
            </td>
        </tr>
    </table>
</section>

<section class="dbvc-cc-configure-section dbvc-cc-configure-crawl-defaults" aria-label="<?php esc_attr_e('Default crawl settings', 'dbvc'); ?>">
    <h3><?php esc_html_e('Default Crawl Settings', 'dbvc'); ?></h3>
    <p class="description"><?php esc_html_e('Collect tab inputs are prefilled from these defaults and can be overridden per crawl.', 'dbvc'); ?></p>
    <table class="form-table">
        <tr valign="top">
            <th scope="row"><label for="request_delay"><?php esc_html_e('Delay Between Requests', 'dbvc'); ?></label></th>
            <td>
                <input type="number" id="request_delay" name="dbvc_cc_settings[request_delay]" value="<?php echo esc_attr($options['request_delay']); ?>" class="small-text" min="0" max="10000" /> ms
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="request_timeout"><?php esc_html_e('Request Timeout', 'dbvc'); ?></label></th>
            <td>
                <input type="number" id="request_timeout" name="dbvc_cc_settings[request_timeout]" value="<?php echo esc_attr($options['request_timeout']); ?>" class="small-text" min="1" max="300" /> seconds
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="user_agent"><?php esc_html_e('User-Agent', 'dbvc'); ?></label></th>
            <td>
                <input type="text" id="user_agent" name="dbvc_cc_settings[user_agent]" value="<?php echo esc_attr($options['user_agent']); ?>" class="large-text" />
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="exclude_selectors"><?php esc_html_e('Exclude Elements', 'dbvc'); ?></label></th>
            <td>
                <textarea id="exclude_selectors" name="dbvc_cc_settings[exclude_selectors]" class="large-text" rows="3" placeholder="#sidebar, .footer, .ad-banner"><?php echo esc_textarea($options['exclude_selectors']); ?></textarea>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="focus_selectors"><?php esc_html_e('Focus on Elements', 'dbvc'); ?></label></th>
            <td>
                <textarea id="focus_selectors" name="dbvc_cc_settings[focus_selectors]" class="large-text" rows="3" placeholder="#main-content, .article-body"><?php echo esc_textarea($options['focus_selectors']); ?></textarea>
            </td>
        </tr>
    </table>
</section>
