<?php

if (! defined('ABSPATH')) {
    exit;
}

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
<section class="dbvc-cc-configure-section dbvc-cc-configure-advanced" aria-label="<?php esc_attr_e('Advanced collection controls', 'dbvc'); ?>">
    <h3><?php esc_html_e('Advanced Collection Controls', 'dbvc'); ?></h3>
    <p class="description"><?php esc_html_e('Configure deep capture controls, context behavior, and attribute scrub policy defaults.', 'dbvc'); ?></p>

    <table class="form-table">
        <tr valign="top">
            <th scope="row"><label for="capture_mode"><?php esc_html_e('Capture Mode', 'dbvc'); ?></label></th>
            <td>
                <select id="capture_mode" name="dbvc_cc_settings[capture_mode]">
                    <?php foreach ($allowed_capture_modes as $capture_mode_value => $capture_mode_label) : ?>
                        <option value="<?php echo esc_attr($capture_mode_value); ?>" <?php selected((string) $options['capture_mode'], (string) $capture_mode_value); ?>><?php echo esc_html($capture_mode_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="capture_max_elements_per_page"><?php esc_html_e('Max Elements Per Page', 'dbvc'); ?></label></th>
            <td>
                <input type="number" id="capture_max_elements_per_page" name="dbvc_cc_settings[capture_max_elements_per_page]" value="<?php echo esc_attr($options['capture_max_elements_per_page']); ?>" class="small-text" min="100" max="10000" />
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="capture_max_chars_per_element"><?php esc_html_e('Max Characters Per Element', 'dbvc'); ?></label></th>
            <td>
                <input type="number" id="capture_max_chars_per_element" name="dbvc_cc_settings[capture_max_chars_per_element]" value="<?php echo esc_attr($options['capture_max_chars_per_element']); ?>" class="small-text" min="100" max="4000" />
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><?php esc_html_e('Capture Detail Toggles', 'dbvc'); ?></th>
            <td>
                <input type="hidden" name="dbvc_cc_settings[capture_include_attribute_context]" value="0" />
                <label><input type="checkbox" id="capture_include_attribute_context" name="dbvc_cc_settings[capture_include_attribute_context]" value="1" <?php checked(! empty($options['capture_include_attribute_context'])); ?> /> <?php esc_html_e('Include attribute context in element artifacts.', 'dbvc'); ?></label><br />
                <input type="hidden" name="dbvc_cc_settings[capture_include_dom_path]" value="0" />
                <label><input type="checkbox" id="capture_include_dom_path" name="dbvc_cc_settings[capture_include_dom_path]" value="1" <?php checked(! empty($options['capture_include_dom_path'])); ?> /> <?php esc_html_e('Include DOM path trace in element artifacts.', 'dbvc'); ?></label><br />
                <input type="hidden" name="dbvc_cc_settings[context_enable_boilerplate_detection]" value="0" />
                <label><input type="checkbox" id="context_enable_boilerplate_detection" name="dbvc_cc_settings[context_enable_boilerplate_detection]" value="1" <?php checked(! empty($options['context_enable_boilerplate_detection'])); ?> /> <?php esc_html_e('Enable deterministic boilerplate detection hints.', 'dbvc'); ?></label><br />
                <input type="hidden" name="dbvc_cc_settings[context_enable_entity_hints]" value="0" />
                <label><input type="checkbox" id="context_enable_entity_hints" name="dbvc_cc_settings[context_enable_entity_hints]" value="1" <?php checked(! empty($options['context_enable_entity_hints'])); ?> /> <?php esc_html_e('Enable deterministic entity hint extraction.', 'dbvc'); ?></label>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><?php esc_html_e('Section Typing', 'dbvc'); ?></th>
            <td>
                <input type="hidden" name="dbvc_cc_settings[ai_enable_section_typing]" value="0" />
                <label><input type="checkbox" id="ai_enable_section_typing" name="dbvc_cc_settings[ai_enable_section_typing]" value="1" <?php checked(! empty($options['ai_enable_section_typing'])); ?> /> <?php esc_html_e('Enable AI section typing stage (with fallback).', 'dbvc'); ?></label><br />
                <label for="ai_section_typing_confidence_threshold"><?php esc_html_e('Confidence Threshold', 'dbvc'); ?></label>
                <input type="number" step="0.01" id="ai_section_typing_confidence_threshold" name="dbvc_cc_settings[ai_section_typing_confidence_threshold]" value="<?php echo esc_attr($options['ai_section_typing_confidence_threshold']); ?>" class="small-text" min="0" max="1" />
            </td>
        </tr>
    </table>
</section>

<section class="dbvc-cc-configure-section dbvc-cc-configure-scrub" aria-label="<?php esc_attr_e('Attribute scrub policy', 'dbvc'); ?>">
    <h3><?php esc_html_e('Attribute Scrub Policy', 'dbvc'); ?></h3>
    <p class="description"><?php esc_html_e('Define deterministic actions for collected attributes. AI suggestions can be enabled but are never auto-applied.', 'dbvc'); ?></p>

    <table class="form-table">
        <tr valign="top">
            <th scope="row"><?php esc_html_e('Policy', 'dbvc'); ?></th>
            <td>
                <input type="hidden" name="dbvc_cc_settings[scrub_policy_enabled]" value="0" />
                <label><input type="checkbox" id="scrub_policy_enabled" name="dbvc_cc_settings[scrub_policy_enabled]" value="1" <?php checked(! empty($options['scrub_policy_enabled'])); ?> /> <?php esc_html_e('Enable attribute scrub policy.', 'dbvc'); ?></label><br />
                <label for="scrub_profile_mode"><?php esc_html_e('Profile Mode', 'dbvc'); ?></label>
                <select id="scrub_profile_mode" name="dbvc_cc_settings[scrub_profile_mode]">
                    <?php foreach ($allowed_scrub_profiles as $scrub_profile_value => $scrub_profile_label) : ?>
                        <option value="<?php echo esc_attr($scrub_profile_value); ?>" <?php selected((string) $options['scrub_profile_mode'], (string) $scrub_profile_value); ?>><?php echo esc_html($scrub_profile_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><?php esc_html_e('Attribute Actions', 'dbvc'); ?></th>
            <td>
                <label for="scrub_attr_action_class"><?php esc_html_e('class', 'dbvc'); ?></label>
                <select id="scrub_attr_action_class" name="dbvc_cc_settings[scrub_attr_action_class]">
                    <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                        <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_class'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <br />
                <label for="scrub_attr_action_id"><?php esc_html_e('id', 'dbvc'); ?></label>
                <select id="scrub_attr_action_id" name="dbvc_cc_settings[scrub_attr_action_id]">
                    <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                        <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_id'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <br />
                <label for="scrub_attr_action_data"><?php esc_html_e('data-*', 'dbvc'); ?></label>
                <select id="scrub_attr_action_data" name="dbvc_cc_settings[scrub_attr_action_data]">
                    <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                        <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_data'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <br />
                <label for="scrub_attr_action_style"><?php esc_html_e('style', 'dbvc'); ?></label>
                <select id="scrub_attr_action_style" name="dbvc_cc_settings[scrub_attr_action_style]">
                    <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                        <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_style'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <br />
                <label for="scrub_attr_action_aria"><?php esc_html_e('aria-*', 'dbvc'); ?></label>
                <select id="scrub_attr_action_aria" name="dbvc_cc_settings[scrub_attr_action_aria]">
                    <?php foreach ($allowed_scrub_actions as $scrub_action_value => $scrub_action_label) : ?>
                        <option value="<?php echo esc_attr($scrub_action_value); ?>" <?php selected((string) $options['scrub_attr_action_aria'], (string) $scrub_action_value); ?>><?php echo esc_html($scrub_action_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="scrub_custom_allowlist"><?php esc_html_e('Custom Allowlist', 'dbvc'); ?></label></th>
            <td>
                <textarea id="scrub_custom_allowlist" name="dbvc_cc_settings[scrub_custom_allowlist]" class="large-text" rows="2" placeholder="aria-label, data-important, class"><?php echo esc_textarea($options['scrub_custom_allowlist']); ?></textarea>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><label for="scrub_custom_denylist"><?php esc_html_e('Custom Denylist', 'dbvc'); ?></label></th>
            <td>
                <textarea id="scrub_custom_denylist" name="dbvc_cc_settings[scrub_custom_denylist]" class="large-text" rows="2" placeholder="style, data-tracking-*, on*"><?php echo esc_textarea($options['scrub_custom_denylist']); ?></textarea>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row"><?php esc_html_e('AI Suggestions', 'dbvc'); ?></th>
            <td>
                <input type="hidden" name="dbvc_cc_settings[scrub_ai_suggestion_enabled]" value="0" />
                <label><input type="checkbox" id="scrub_ai_suggestion_enabled" name="dbvc_cc_settings[scrub_ai_suggestion_enabled]" value="1" <?php checked(! empty($options['scrub_ai_suggestion_enabled'])); ?> /> <?php esc_html_e('Enable AI suggestions for scrub policies (manual approval required).', 'dbvc'); ?></label><br />
                <label for="scrub_preview_sample_size"><?php esc_html_e('Preview Sample Size', 'dbvc'); ?></label>
                <input type="number" id="scrub_preview_sample_size" name="dbvc_cc_settings[scrub_preview_sample_size]" value="<?php echo esc_attr($options['scrub_preview_sample_size']); ?>" class="small-text" min="1" max="100" />
            </td>
        </tr>
    </table>
</section>
