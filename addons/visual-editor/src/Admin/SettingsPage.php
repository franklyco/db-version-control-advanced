<?php

namespace Dbvc\VisualEditor\Admin;

if (! defined('WPINC')) {
    die;
}

final class SettingsPage
{
    private const PAGE_SLUG = 'dbvc-visual-editor';
    private const NONCE_ACTION = 'dbvc_visual_editor_settings_action';
    private const NONCE_NAME = 'dbvc_visual_editor_settings_nonce';

    /**
     * @return void
     */
    public function register()
    {
        add_action('admin_menu', [$this, 'registerMenu'], 20);
    }

    /**
     * @return void
     */
    public function registerMenu()
    {
        add_submenu_page(
            'dbvc-export',
            __('Visual Editor Settings', 'dbvc'),
            __('Visual Editor', 'dbvc'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * @return void
     */
    public function render()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage Visual Editor settings.', 'dbvc'));
        }

        $feedback = [
            'success' => [],
            'error' => [],
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (
                ! isset($_POST[self::NONCE_NAME])
                || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
            ) {
                $feedback['error'][] = __('Visual Editor settings could not be saved because the security check failed.', 'dbvc');
            } elseif (class_exists('\DBVC_Visual_Editor_Addon')) {
                $result = \DBVC_Visual_Editor_Addon::save_settings((array) $_POST);
                foreach ((array) ($result['errors'] ?? []) as $error) {
                    $feedback['error'][] = sanitize_text_field((string) $error);
                }

                if (empty($feedback['error'])) {
                    $feedback['success'][] = __('Visual Editor settings saved.', 'dbvc');
                }
            } else {
                $feedback['error'][] = __('Visual Editor settings module unavailable.', 'dbvc');
            }
        }

        $settings = class_exists('\DBVC_Visual_Editor_Addon') ? \DBVC_Visual_Editor_Addon::get_all_settings() : [];
        $groups = class_exists('\DBVC_Visual_Editor_Addon') ? \DBVC_Visual_Editor_Addon::get_settings_groups() : [];
        $field_meta = class_exists('\DBVC_Visual_Editor_Addon') ? \DBVC_Visual_Editor_Addon::get_field_meta() : [];
        $enabled = isset($settings[\DBVC_Visual_Editor_Addon::OPTION_ENABLED])
            ? (string) $settings[\DBVC_Visual_Editor_Addon::OPTION_ENABLED]
            : '0';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Visual Editor Settings', 'dbvc'); ?></h1>
            <p class="description"><?php esc_html_e('Configure the DBVC Visual Editor runtime, toolbar global fields, and frontend content visibility exclusions.', 'dbvc'); ?></p>

            <?php $this->renderFeedback($feedback); ?>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                <?php if (! empty($groups) && ! empty($field_meta)) : ?>
                    <?php foreach ($groups as $group) : ?>
                        <?php
                        $group_label = isset($group['label']) ? (string) $group['label'] : '';
                        $group_fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
                        ?>
                        <section class="card" style="max-width: 900px;">
                            <?php if ($group_label !== '') : ?>
                                <h2><?php echo esc_html($group_label); ?></h2>
                            <?php endif; ?>
                            <?php $this->renderFields($group_fields, $field_meta, $settings); ?>
                        </section>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p><?php esc_html_e('Visual Editor settings metadata unavailable.', 'dbvc'); ?></p>
                <?php endif; ?>

                <?php submit_button(__('Save Visual Editor Settings', 'dbvc')); ?>
            </form>

            <p>
                <small class="description">
                    <?php
                    echo esc_html(
                        $enabled === '1'
                            ? __('Enabled. Authorized users can toggle frontend edit mode from the admin bar on supported Bricks pages.', 'dbvc')
                            : __('Disabled. No frontend markers, overlay assets, or Visual Editor REST runtime will load.', 'dbvc')
                    );
                    ?>
                </small>
            </p>
        </div>
        <?php
    }

    /**
     * @param array<string, array<int, string>> $feedback
     * @return void
     */
    private function renderFeedback(array $feedback)
    {
        foreach ((array) ($feedback['success'] ?? []) as $message) {
            ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html((string) $message); ?></p></div>
            <?php
        }

        foreach ((array) ($feedback['error'] ?? []) as $message) {
            ?>
            <div class="notice notice-error"><p><?php echo esc_html((string) $message); ?></p></div>
            <?php
        }
    }

    /**
     * @param array<int, string>                 $field_keys
     * @param array<string, array<string, mixed>> $field_meta_index
     * @param array<string, string>              $settings
     * @return void
     */
    private function renderFields(array $field_keys, array $field_meta_index, array $settings)
    {
        foreach ($field_keys as $field_key) {
            $field_key = (string) $field_key;
            if (! isset($field_meta_index[$field_key])) {
                continue;
            }

            $field_meta = $field_meta_index[$field_key];
            $field_value = isset($settings[$field_key]) ? (string) $settings[$field_key] : '';
            $this->renderField($field_key, $field_meta, $field_value);
        }
    }

    /**
     * @param string               $field_key
     * @param array<string, mixed> $field_meta
     * @param string               $field_value
     * @return void
     */
    private function renderField($field_key, array $field_meta, $field_value)
    {
        $field_label = isset($field_meta['label']) ? (string) $field_meta['label'] : $field_key;
        $field_help = isset($field_meta['help']) ? (string) $field_meta['help'] : '';
        $field_id = 'dbvc-ve-setting-' . sanitize_html_class($field_key);
        $input_type = isset($field_meta['input']) ? (string) $field_meta['input'] : 'text';

        if ($input_type === 'checkbox') {
            ?>
            <p>
                <label>
                    <input type="checkbox" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_key); ?>" value="1" <?php checked($field_value, '1'); ?> />
                    <?php echo esc_html($field_label); ?>
                </label>
                <?php if ($field_help !== '') : ?>
                    <br><small class="description"><?php echo esc_html($field_help); ?></small>
                <?php endif; ?>
            </p>
            <?php
            return;
        }

        if ($input_type === 'textarea') {
            ?>
            <p>
                <label for="<?php echo esc_attr($field_id); ?>"><strong><?php echo esc_html($field_label); ?></strong></label><br>
                <textarea id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_key); ?>" rows="<?php echo esc_attr((string) ($field_meta['rows'] ?? '3')); ?>" class="large-text code"><?php echo esc_textarea($field_value); ?></textarea>
                <?php if ($field_help !== '') : ?>
                    <br><small class="description"><?php echo esc_html($field_help); ?></small>
                <?php endif; ?>
            </p>
            <?php
            return;
        }

        ?>
        <p>
            <label for="<?php echo esc_attr($field_id); ?>"><strong><?php echo esc_html($field_label); ?></strong></label><br>
            <input type="text" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_key); ?>" value="<?php echo esc_attr($field_value); ?>" class="regular-text" />
            <?php if ($field_help !== '') : ?>
                <br><small class="description"><?php echo esc_html($field_help); ?></small>
            <?php endif; ?>
        </p>
        <?php
    }
}
