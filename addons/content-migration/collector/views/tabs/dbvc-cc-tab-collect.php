<?php

if (! defined('ABSPATH')) {
    exit;
}

$options = isset($options) && is_array($options) ? $options : DBVC_CC_Settings_Service::get_options();
$allowed_capture_modes = [
    DBVC_CC_Contracts::CAPTURE_MODE_STANDARD => __('Standard', 'dbvc'),
    DBVC_CC_Contracts::CAPTURE_MODE_DEEP => __('Deep', 'dbvc'),
];
$allowed_scrub_profiles = [
    DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT => __('Deterministic Default', 'dbvc'),
    DBVC_CC_Contracts::SCRUB_PROFILE_CUSTOM => __('Custom', 'dbvc'),
    DBVC_CC_Contracts::SCRUB_PROFILE_AI_SUGGESTED_APPROVED => __('AI Suggested (Approved)', 'dbvc'),
];
$allowed_scrub_actions = [
    DBVC_CC_Contracts::SCRUB_ACTION_KEEP => __('Keep', 'dbvc'),
    DBVC_CC_Contracts::SCRUB_ACTION_DROP => __('Drop', 'dbvc'),
    DBVC_CC_Contracts::SCRUB_ACTION_HASH => __('Hash', 'dbvc'),
    DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE => __('Tokenize', 'dbvc'),
];
?>
<section class="dbvc-cc-collect-section" id="dbvc-cc-collect-panel">
    <h2><?php esc_html_e('Collect', 'dbvc'); ?></h2>
    <p><?php esc_html_e('Crawl a sitemap and collect content artifacts. Crawl settings are prefilled from Configure defaults and can be overridden for this run.', 'dbvc'); ?></p>

    <div id="cc-app" class="metabox-holder">
        <div class="postbox">
            <h3 class="hndle"><span><?php esc_html_e('Sitemap Crawler', 'dbvc'); ?></span></h3>
            <div class="inside">
                <form id="cc-form">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><label for="sitemap_url"><?php esc_html_e('Sitemap URL', 'dbvc'); ?></label></th>
                            <td>
                                <input type="url" id="sitemap_url" name="sitemap_url" class="large-text" placeholder="https://example.com/sitemap.xml" required />
                            </td>
                        </tr>
                    </table>

                    <section class="dbvc-cc-collect-overrides" aria-label="<?php esc_attr_e('Per-crawl overrides', 'dbvc'); ?>">
                        <h4><?php esc_html_e('Per-crawl Settings Override', 'dbvc'); ?></h4>
                        <p class="description"><?php esc_html_e('These values start from Configure defaults and apply only to this crawl run.', 'dbvc'); ?></p>

                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><label for="request_delay"><?php esc_html_e('Delay Between Requests', 'dbvc'); ?></label></th>
                                <td>
                                    <input type="number" id="request_delay" value="<?php echo esc_attr($options['request_delay']); ?>" class="small-text" min="0" max="10000" /> ms
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="request_timeout"><?php esc_html_e('Request Timeout', 'dbvc'); ?></label></th>
                                <td>
                                    <input type="number" id="request_timeout" value="<?php echo esc_attr($options['request_timeout']); ?>" class="small-text" min="1" max="300" /> seconds
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="user_agent"><?php esc_html_e('User-Agent', 'dbvc'); ?></label></th>
                                <td>
                                    <input type="text" id="user_agent" value="<?php echo esc_attr($options['user_agent']); ?>" class="large-text" />
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="exclude_selectors"><?php esc_html_e('Exclude Elements', 'dbvc'); ?></label></th>
                                <td>
                                    <textarea id="exclude_selectors" class="large-text" rows="3" placeholder="#sidebar, .footer, .ad-banner"><?php echo esc_textarea($options['exclude_selectors']); ?></textarea>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="focus_selectors"><?php esc_html_e('Focus on Elements', 'dbvc'); ?></label></th>
                                <td>
                                    <textarea id="focus_selectors" class="large-text" rows="3" placeholder="#main-content, .article-body"><?php echo esc_textarea($options['focus_selectors']); ?></textarea>
                                </td>
                            </tr>
                        </table>

                        <h5><?php esc_html_e('Advanced Collection Overrides', 'dbvc'); ?></h5>
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><label for="capture_mode"><?php esc_html_e('Capture Mode', 'dbvc'); ?></label></th>
                                <td>
                                    <select id="capture_mode">
                                        <?php foreach ($allowed_capture_modes as $capture_mode_value => $capture_mode_label) : ?>
                                            <option value="<?php echo esc_attr($capture_mode_value); ?>" <?php selected((string) $options['capture_mode'], (string) $capture_mode_value); ?>><?php echo esc_html($capture_mode_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php esc_html_e('Deep Capture Toggles', 'dbvc'); ?></th>
                                <td>
                                    <label><input type="checkbox" id="capture_include_attribute_context" value="1" <?php checked(!empty($options['capture_include_attribute_context'])); ?> /> <?php esc_html_e('Include attribute context', 'dbvc'); ?></label><br />
                                    <label><input type="checkbox" id="capture_include_dom_path" value="1" <?php checked(!empty($options['capture_include_dom_path'])); ?> /> <?php esc_html_e('Include DOM path', 'dbvc'); ?></label>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="capture_max_elements_per_page"><?php esc_html_e('Max Elements / Page', 'dbvc'); ?></label></th>
                                <td><input type="number" id="capture_max_elements_per_page" value="<?php echo esc_attr($options['capture_max_elements_per_page']); ?>" class="small-text" min="100" max="10000" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="capture_max_chars_per_element"><?php esc_html_e('Max Chars / Element', 'dbvc'); ?></label></th>
                                <td><input type="number" id="capture_max_chars_per_element" value="<?php echo esc_attr($options['capture_max_chars_per_element']); ?>" class="small-text" min="100" max="4000" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php esc_html_e('Context Toggles', 'dbvc'); ?></th>
                                <td>
                                    <label><input type="checkbox" id="context_enable_boilerplate_detection" value="1" <?php checked(!empty($options['context_enable_boilerplate_detection'])); ?> /> <?php esc_html_e('Enable boilerplate detection', 'dbvc'); ?></label><br />
                                    <label><input type="checkbox" id="context_enable_entity_hints" value="1" <?php checked(!empty($options['context_enable_entity_hints'])); ?> /> <?php esc_html_e('Enable entity hints', 'dbvc'); ?></label>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php esc_html_e('Section Typing', 'dbvc'); ?></th>
                                <td>
                                    <label><input type="checkbox" id="ai_enable_section_typing" value="1" <?php checked(!empty($options['ai_enable_section_typing'])); ?> /> <?php esc_html_e('Enable section typing', 'dbvc'); ?></label><br />
                                    <label for="ai_section_typing_confidence_threshold"><?php esc_html_e('Confidence Threshold', 'dbvc'); ?></label>
                                    <input type="number" step="0.01" id="ai_section_typing_confidence_threshold" value="<?php echo esc_attr($options['ai_section_typing_confidence_threshold']); ?>" class="small-text" min="0" max="1" />
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php esc_html_e('Scrub Policy', 'dbvc'); ?></th>
                                <td>
                                    <label><input type="checkbox" id="scrub_policy_enabled" value="1" <?php checked(!empty($options['scrub_policy_enabled'])); ?> /> <?php esc_html_e('Enable scrub policy', 'dbvc'); ?></label><br />
                                    <label for="scrub_profile_mode"><?php esc_html_e('Profile Mode', 'dbvc'); ?></label>
                                    <select id="scrub_profile_mode">
                                        <?php foreach ($allowed_scrub_profiles as $scrub_profile_value => $scrub_profile_label) : ?>
                                            <option value="<?php echo esc_attr($scrub_profile_value); ?>" <?php selected((string) $options['scrub_profile_mode'], (string) $scrub_profile_value); ?>><?php echo esc_html($scrub_profile_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php esc_html_e('Scrub Attribute Actions', 'dbvc'); ?></th>
                                <td>
                                    <label for="scrub_attr_action_class"><?php esc_html_e('class', 'dbvc'); ?></label>
                                    <select id="scrub_attr_action_class">
                                        <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                                            <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_class'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br />
                                    <label for="scrub_attr_action_id"><?php esc_html_e('id', 'dbvc'); ?></label>
                                    <select id="scrub_attr_action_id">
                                        <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                                            <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_id'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br />
                                    <label for="scrub_attr_action_data"><?php esc_html_e('data-*', 'dbvc'); ?></label>
                                    <select id="scrub_attr_action_data">
                                        <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                                            <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_data'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br />
                                    <label for="scrub_attr_action_style"><?php esc_html_e('style', 'dbvc'); ?></label>
                                    <select id="scrub_attr_action_style">
                                        <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                                            <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_style'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br />
                                    <label for="scrub_attr_action_aria"><?php esc_html_e('aria-*', 'dbvc'); ?></label>
                                    <select id="scrub_attr_action_aria">
                                        <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                                            <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_aria'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="scrub_custom_allowlist"><?php esc_html_e('Scrub Custom Allowlist', 'dbvc'); ?></label></th>
                                <td><textarea id="scrub_custom_allowlist" class="large-text" rows="2"><?php echo esc_textarea($options['scrub_custom_allowlist']); ?></textarea></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="scrub_custom_denylist"><?php esc_html_e('Scrub Custom Denylist', 'dbvc'); ?></label></th>
                                <td><textarea id="scrub_custom_denylist" class="large-text" rows="2"><?php echo esc_textarea($options['scrub_custom_denylist']); ?></textarea></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php esc_html_e('Scrub AI Suggestions', 'dbvc'); ?></th>
                                <td>
                                    <label><input type="checkbox" id="scrub_ai_suggestion_enabled" value="1" <?php checked(!empty($options['scrub_ai_suggestion_enabled'])); ?> /> <?php esc_html_e('Enable suggestions (manual approval required)', 'dbvc'); ?></label><br />
                                    <label for="scrub_preview_sample_size"><?php esc_html_e('Preview Sample Size', 'dbvc'); ?></label>
                                    <input type="number" id="scrub_preview_sample_size" value="<?php echo esc_attr($options['scrub_preview_sample_size']); ?>" class="small-text" min="1" max="100" />
                                </td>
                            </tr>
                        </table>
                    </section>

                    <div class="submit">
                        <button type="submit" id="cc-submit" class="button button-primary"><?php esc_html_e('Start Crawling', 'dbvc'); ?></button>
                        <button type="button" id="cc-stop" class="button button-secondary" style="display:none;"><?php esc_html_e('Stop Crawling', 'dbvc'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="postbox" id="cc-status-wrapper" style="display:none;">
            <h3 class="hndle"><span><?php esc_html_e('Progress Log', 'dbvc'); ?></span></h3>
            <div class="inside">
                <div id="cc-progress-bar-container">
                    <div id="cc-progress-bar">0%</div>
                </div>
                <div class="cc-log-controls">
                    <button type="button" id="cc-clear-log" class="button button-small"><?php esc_html_e('Clear Log', 'dbvc'); ?></button>
                    <button type="button" id="cc-download-log" class="button button-small"><?php esc_html_e('Download Log', 'dbvc'); ?></button>
                </div>
                <div id="cc-status-log"></div>
            </div>
        </div>
    </div>
</section>
