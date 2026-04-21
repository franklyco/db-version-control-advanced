<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_AI_Tools_Page
{
    private const PAGE_SLUG = 'dbvc-tools';
    private const ACTION_GENERATE = 'dbvc_ai_generate_sample_package';
    private const ACTION_DOWNLOAD = 'dbvc_ai_download_sample_package';
    private const NOTICE_TRANSIENT_PREFIX = 'dbvc_ai_tools_notice_';

    /**
     * Bootstrap admin-post handlers.
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_post_' . self::ACTION_GENERATE, [self::class, 'handle_generate']);
        add_action('admin_post_' . self::ACTION_DOWNLOAD, [self::class, 'handle_download']);
    }

    /**
     * Render the Tools submenu page.
     *
     * @return void
     */
    public static function render_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'dbvc'));
        }

        $settings = class_exists('\Dbvc\AiPackage\Settings')
            ? \Dbvc\AiPackage\Settings::get_all_settings()
            : [];
        $storage = class_exists('\Dbvc\AiPackage\Storage')
            ? \Dbvc\AiPackage\Storage::ensure_base_roots()
            : new WP_Error('dbvc_ai_storage_unavailable', __('AI package storage service unavailable.', 'dbvc'));
        $schema_bundle = class_exists('\Dbvc\AiPackage\SchemaDiscoveryService')
            ? \Dbvc\AiPackage\SchemaDiscoveryService::build_schema_bundle()
            : [];
        $template_bundle = (! empty($schema_bundle) && class_exists('\Dbvc\AiPackage\TemplateBuilder'))
            ? \Dbvc\AiPackage\TemplateBuilder::build_templates($schema_bundle)
            : [];
        $fingerprint = (! empty($schema_bundle) && class_exists('\Dbvc\AiPackage\SiteFingerprintService'))
            ? \Dbvc\AiPackage\SiteFingerprintService::build_from_schema_bundle($schema_bundle)
            : [];

        $selected_post_types = (array) get_option('dbvc_post_types', []);
        $selected_taxonomies = function_exists('dbvc_get_selected_taxonomies')
            ? dbvc_get_selected_taxonomies()
            : (array) get_option('dbvc_taxonomies', []);
        $available_post_types = function_exists('dbvc_get_available_post_types')
            ? dbvc_get_available_post_types()
            : [];
        $available_taxonomies = function_exists('dbvc_get_available_taxonomies')
            ? dbvc_get_available_taxonomies()
            : [];

        $generation = isset($settings['generation']) && is_array($settings['generation']) ? $settings['generation'] : [];
        $included_docs = isset($generation['included_docs']) && is_array($generation['included_docs']) ? $generation['included_docs'] : [];
        $schema_stats = isset($schema_bundle['stats']) && is_array($schema_bundle['stats']) ? $schema_bundle['stats'] : [];
        $schema_sources = isset($schema_bundle['sources']['acf']) && is_array($schema_bundle['sources']['acf']) ? $schema_bundle['sources']['acf'] : [];
        $template_variants = isset($template_bundle['variants']) && is_array($template_bundle['variants']) ? $template_bundle['variants'] : [];
        $post_template_count = isset($template_bundle['post_types']) && is_array($template_bundle['post_types']) ? count($template_bundle['post_types']) : 0;
        $term_template_count = isset($template_bundle['taxonomies']) && is_array($template_bundle['taxonomies']) ? count($template_bundle['taxonomies']) : 0;
        $fingerprint_value = isset($fingerprint['site_fingerprint']) ? (string) $fingerprint['site_fingerprint'] : '';
        $local_json_dir_count = isset($schema_sources['local_json_dirs']) && is_array($schema_sources['local_json_dirs'])
            ? count($schema_sources['local_json_dirs'])
            : 0;
        $notice = self::consume_notice();
        $package_summary = self::get_requested_package_summary();
        $package_download_url = is_array($package_summary) && isset($package_summary['package_id'])
            ? self::get_download_url((string) $package_summary['package_id'])
            : '';
        ?>
        <div class="wrap">
            <style>
                .dbvc-ai-option-grid {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.5rem 0.75rem;
                    max-width: 1040px;
                }
                .dbvc-ai-option-chip {
                    display: inline-flex;
                    align-items: flex-start;
                    gap: 0.5rem;
                    flex: 0 1 280px;
                    max-width: 320px;
                    padding: 0.55rem 0.75rem;
                    border: 1px solid #dcdcde;
                    border-radius: 4px;
                    background: #fff;
                    box-sizing: border-box;
                }
                .dbvc-ai-option-chip input {
                    margin: 2px 0 0;
                    flex: 0 0 auto;
                }
                .dbvc-ai-option-chip__text {
                    display: block;
                    line-height: 1.35;
                }
            </style>
            <h1><?php esc_html_e('DBVC Tools', 'dbvc'); ?></h1>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if (is_wp_error($package_summary)) : ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($package_summary->get_error_message()); ?></p>
                </div>
            <?php elseif (is_array($package_summary)) : ?>
                <?php $manifest = isset($package_summary['manifest']) && is_array($package_summary['manifest']) ? $package_summary['manifest'] : []; ?>
                <?php $counts = isset($package_summary['counts']) && is_array($package_summary['counts']) ? $package_summary['counts'] : []; ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e('Latest generated package', 'dbvc'); ?>:</strong> <code><?php echo esc_html((string) ($package_summary['package_id'] ?? '')); ?></code></p>
                    <p><?php echo esc_html(sprintf(__('Generated at %s with %d sample JSON files and %d sample docs.', 'dbvc'), (string) ($manifest['generated_at'] ?? ''), (int) ($counts['sample_json_files'] ?? 0), (int) ($counts['sample_markdown_files'] ?? 0))); ?></p>
                    <?php if ($package_download_url !== '') : ?>
                        <p><a class="button button-primary" href="<?php echo esc_url($package_download_url); ?>"><?php esc_html_e('Download Latest Sample Package', 'dbvc'); ?></a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (is_wp_error($storage)) : ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($storage->get_error_message()); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_GENERATE); ?>" />
                <?php wp_nonce_field(self::ACTION_GENERATE, self::ACTION_GENERATE . '_nonce'); ?>

                <h2><?php esc_html_e('Download Sample Entities', 'dbvc'); ?></h2>
                <p class="description"><?php esc_html_e('Generate an AI-facing sample package for the selected post types and taxonomies. The package includes canonical DBVC-shaped sample JSON, schema artifacts, and AI-readable guidance docs.', 'dbvc'); ?></p>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Post types', 'dbvc'); ?></th>
                            <td>
                                <?php if (empty($available_post_types)) : ?>
                                    <p><?php esc_html_e('No post types available.', 'dbvc'); ?></p>
                                <?php else : ?>
                                    <fieldset>
                                        <div class="dbvc-ai-option-grid">
                                            <?php foreach ($available_post_types as $post_type => $post_type_object) : ?>
                                                <?php self::render_checkbox_chip('dbvc_ai_generate[post_types][]', (string) $post_type, in_array($post_type, $selected_post_types, true), $post_type_object->label . ' (' . $post_type . ')'); ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>
                                <?php endif; ?>
                                <p class="description"><?php esc_html_e('Use the current DBVC export selections as defaults, then override them per package as needed.', 'dbvc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Taxonomies', 'dbvc'); ?></th>
                            <td>
                                <?php if (empty($available_taxonomies)) : ?>
                                    <p><?php esc_html_e('No taxonomies available.', 'dbvc'); ?></p>
                                <?php else : ?>
                                    <fieldset>
                                        <div class="dbvc-ai-option-grid">
                                            <?php foreach ($available_taxonomies as $taxonomy => $taxonomy_object) : ?>
                                                <?php self::render_checkbox_chip('dbvc_ai_generate[taxonomies][]', (string) $taxonomy, in_array($taxonomy, $selected_taxonomies, true), $taxonomy_object->labels->name . ' (' . $taxonomy . ')'); ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </fieldset>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Generation defaults', 'dbvc'); ?></th>
                            <td>
                                <label for="dbvc-ai-shape-mode"><strong><?php esc_html_e('Shape mode', 'dbvc'); ?></strong></label><br />
                                <select id="dbvc-ai-shape-mode" name="dbvc_ai_generate[shape_mode]">
                                    <?php foreach ((array) \Dbvc\AiPackage\Settings::get_shape_mode_options() as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($generation['shape_mode'] ?? ''), $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <br /><br />

                                <label for="dbvc-ai-value-style"><strong><?php esc_html_e('Value style', 'dbvc'); ?></strong></label><br />
                                <select id="dbvc-ai-value-style" name="dbvc_ai_generate[value_style]">
                                    <?php foreach ((array) \Dbvc\AiPackage\Settings::get_value_style_options() as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($generation['value_style'] ?? ''), $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <br /><br />

                                <label for="dbvc-ai-variant-set"><strong><?php esc_html_e('Variant set', 'dbvc'); ?></strong></label><br />
                                <select id="dbvc-ai-variant-set" name="dbvc_ai_generate[variant_set]">
                                    <?php foreach ((array) \Dbvc\AiPackage\Settings::get_variant_set_options() as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($generation['variant_set'] ?? ''), $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <br /><br />

                                <label for="dbvc-ai-observed-scan-cap"><strong><?php esc_html_e('Observed-shape scan cap', 'dbvc'); ?></strong></label><br />
                                <input id="dbvc-ai-observed-scan-cap" name="dbvc_ai_generate[observed_scan_cap]" type="number" min="<?php echo esc_attr((string) \Dbvc\AiPackage\Settings::MIN_OBSERVED_SCAN_CAP); ?>" max="<?php echo esc_attr((string) \Dbvc\AiPackage\Settings::MAX_OBSERVED_SCAN_CAP); ?>" value="<?php echo esc_attr((string) ($generation['observed_scan_cap'] ?? '')); ?>" class="small-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Included docs', 'dbvc'); ?></th>
                            <td>
                                <fieldset>
                                    <div class="dbvc-ai-option-grid">
                                        <?php foreach ((array) \Dbvc\AiPackage\Settings::get_included_doc_options() as $doc_key => $doc_label) : ?>
                                            <?php self::render_checkbox_chip('dbvc_ai_generate[included_docs][]', (string) $doc_key, in_array($doc_key, $included_docs, true), (string) $doc_label); ?>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                                <p class="description"><?php esc_html_e('Per-sample markdown guidance is always included. These toggles control the top-level agent/operator docs in the package root.', 'dbvc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Storage roots', 'dbvc'); ?></th>
                            <td>
                                <?php if (! is_wp_error($storage)) : ?>
                                    <p><strong><?php esc_html_e('Sample packages', 'dbvc'); ?>:</strong> <code><?php echo esc_html((string) $storage['sample_packages_root']); ?></code></p>
                                    <p><strong><?php esc_html_e('AI intake', 'dbvc'); ?>:</strong> <code><?php echo esc_html((string) $storage['intake_root']); ?></code></p>
                                    <p><strong><?php esc_html_e('Retention', 'dbvc'); ?>:</strong> <?php echo esc_html(sprintf(_n('%d day', '%d days', (int) $storage['retention_days'], 'dbvc'), (int) $storage['retention_days'])); ?></p>
                                <?php endif; ?>
                                <p class="description"><?php esc_html_e('These directories are created under uploads/dbvc and hardened with the same file protections used elsewhere in DBVC.', 'dbvc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Schema readiness', 'dbvc'); ?></th>
                            <td>
                                <p><strong><?php esc_html_e('Discovered post types', 'dbvc'); ?>:</strong> <?php echo esc_html((string) ($schema_stats['post_type_count'] ?? 0)); ?></p>
                                <p><strong><?php esc_html_e('Discovered taxonomies', 'dbvc'); ?>:</strong> <?php echo esc_html((string) ($schema_stats['taxonomy_count'] ?? 0)); ?></p>
                                <p><strong><?php esc_html_e('Registered post meta keys', 'dbvc'); ?>:</strong> <?php echo esc_html((string) ($schema_stats['registered_post_meta_count'] ?? 0)); ?></p>
                                <p><strong><?php esc_html_e('Registered term meta keys', 'dbvc'); ?>:</strong> <?php echo esc_html((string) ($schema_stats['registered_term_meta_count'] ?? 0)); ?></p>
                                <p><strong><?php esc_html_e('ACF groups in scope', 'dbvc'); ?>:</strong> <?php echo esc_html((string) ($schema_stats['acf_group_count'] ?? 0)); ?></p>
                                <?php if (! empty($schema_stats['observed_post_meta_count']) || ! empty($schema_stats['observed_term_meta_count'])) : ?>
                                    <p><strong><?php esc_html_e('Observed post meta keys', 'dbvc'); ?>:</strong> <?php echo esc_html((string) ($schema_stats['observed_post_meta_count'] ?? 0)); ?></p>
                                    <p><strong><?php esc_html_e('Observed term meta keys', 'dbvc'); ?>:</strong> <?php echo esc_html((string) ($schema_stats['observed_term_meta_count'] ?? 0)); ?></p>
                                <?php endif; ?>
                                <p class="description">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            __('ACF source: %1$s. Local JSON roots: %2$d.', 'dbvc'),
                                            (string) ($schema_sources['field_groups'] ?? 'none'),
                                            $local_json_dir_count
                                        )
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Template readiness', 'dbvc'); ?></th>
                            <td>
                                <p><strong><?php esc_html_e('Post/CPT template sets', 'dbvc'); ?>:</strong> <?php echo esc_html((string) $post_template_count); ?></p>
                                <p><strong><?php esc_html_e('Taxonomy template sets', 'dbvc'); ?>:</strong> <?php echo esc_html((string) $term_template_count); ?></p>
                                <p><strong><?php esc_html_e('Resolved variants', 'dbvc'); ?>:</strong> <?php echo esc_html(! empty($template_variants) ? implode(', ', array_map('strval', $template_variants)) : __('none', 'dbvc')); ?></p>
                                <p class="description"><?php esc_html_e('Template builders emit canonical DBVC-shaped samples. Package generation now assembles those samples into a downloadable AI package ZIP.', 'dbvc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Site fingerprint', 'dbvc'); ?></th>
                            <td>
                                <?php if ($fingerprint_value !== '') : ?>
                                    <code style="display:block;max-width:100%;overflow:auto;"><?php echo esc_html($fingerprint_value); ?></code>
                                <?php else : ?>
                                    <p><?php esc_html_e('Fingerprint not available.', 'dbvc'); ?></p>
                                <?php endif; ?>
                                <p class="description"><?php esc_html_e('This schema-oriented fingerprint is embedded into the sample package and will later be used to validate AI submission packages before importer handoff.', 'dbvc'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Generate Sample Package', 'dbvc')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @return void
     */
    public static function handle_generate()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to generate AI sample packages.', 'dbvc'), 403);
        }

        check_admin_referer(self::ACTION_GENERATE, self::ACTION_GENERATE . '_nonce');

        if (! class_exists('\Dbvc\AiPackage\SamplePackageBuilder')) {
            self::set_notice('error', __('AI sample package builder is unavailable.', 'dbvc'));
            wp_safe_redirect(self::get_page_url());
            exit;
        }

        $payload = self::get_generate_request_payload();
        $result = \Dbvc\AiPackage\SamplePackageBuilder::build($payload);

        if (is_wp_error($result)) {
            self::set_notice('error', $result->get_error_message());
            wp_safe_redirect(self::get_page_url());
            exit;
        }

        self::set_notice('success', __('Sample package generated successfully.', 'dbvc'));
        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => self::PAGE_SLUG,
                    'package_id' => sanitize_key((string) ($result['package_id'] ?? '')),
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * @return void
     */
    public static function handle_download()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to download AI sample packages.', 'dbvc'), 403);
        }

        $package_id = isset($_GET['package_id']) ? sanitize_key((string) wp_unslash($_GET['package_id'])) : '';
        if ($package_id === '') {
            wp_die(esc_html__('Missing AI sample package identifier.', 'dbvc'), 400);
        }

        check_admin_referer(self::ACTION_DOWNLOAD . '_' . $package_id);

        $summary = \Dbvc\AiPackage\SamplePackageBuilder::get_package_summary($package_id);
        if (is_wp_error($summary)) {
            wp_die(esc_html($summary->get_error_message()), 404);
        }

        $zip_path = isset($summary['zip_path']) ? (string) $summary['zip_path'] : '';
        if ($zip_path === '' || ! is_file($zip_path)) {
            wp_die(esc_html__('Sample package ZIP is unavailable.', 'dbvc'), 404);
        }

        $filename = isset($summary['download_filename']) ? (string) $summary['download_filename'] : 'dbvc-ai-sample-package.zip';
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        exit;
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_generate_request_payload(): array
    {
        $raw = isset($_POST['dbvc_ai_generate']) && is_array($_POST['dbvc_ai_generate'])
            ? wp_unslash($_POST['dbvc_ai_generate'])
            : [];

        $shape_mode = isset($raw['shape_mode']) ? sanitize_key((string) $raw['shape_mode']) : '';
        $value_style = isset($raw['value_style']) ? sanitize_key((string) $raw['value_style']) : '';
        $variant_set = isset($raw['variant_set']) ? sanitize_key((string) $raw['variant_set']) : '';
        $observed_scan_cap = isset($raw['observed_scan_cap']) ? absint($raw['observed_scan_cap']) : 0;

        return [
            'post_types' => self::sanitize_string_array(isset($raw['post_types']) && is_array($raw['post_types']) ? $raw['post_types'] : []),
            'taxonomies' => self::sanitize_string_array(isset($raw['taxonomies']) && is_array($raw['taxonomies']) ? $raw['taxonomies'] : []),
            'shape_mode' => $shape_mode,
            'value_style' => $value_style,
            'variant_set' => $variant_set,
            'observed_scan_cap' => $observed_scan_cap,
            'included_docs' => self::sanitize_string_array(isset($raw['included_docs']) && is_array($raw['included_docs']) ? $raw['included_docs'] : []),
        ];
    }

    /**
     * @return array<string,mixed>|\WP_Error|null
     */
    private static function get_requested_package_summary()
    {
        $package_id = isset($_GET['package_id']) ? sanitize_key((string) wp_unslash($_GET['package_id'])) : '';
        if ($package_id === '' || ! class_exists('\Dbvc\AiPackage\SamplePackageBuilder')) {
            return null;
        }

        return \Dbvc\AiPackage\SamplePackageBuilder::get_package_summary($package_id);
    }

    /**
     * @param string $type
     * @param string $message
     * @return void
     */
    private static function set_notice(string $type, string $message): void
    {
        $type = $type === 'success' ? 'success' : 'error';
        set_transient(
            self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
            [
                'type' => $type,
                'message' => sanitize_text_field($message),
            ],
            MINUTE_IN_SECONDS
        );
    }

    /**
     * @return array<string,string>|null
     */
    private static function consume_notice()
    {
        $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);

        if (! is_array($notice) || empty($notice['message'])) {
            return null;
        }

        return [
            'type' => isset($notice['type']) && $notice['type'] === 'success' ? 'success' : 'error',
            'message' => (string) $notice['message'],
        ];
    }

    /**
     * @param string $package_id
     * @return string
     */
    private static function get_download_url(string $package_id): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => self::ACTION_DOWNLOAD,
                    'package_id' => sanitize_key($package_id),
                ],
                admin_url('admin-post.php')
            ),
            self::ACTION_DOWNLOAD . '_' . sanitize_key($package_id)
        );
    }

    /**
     * @return string
     */
    private static function get_page_url(): string
    {
        return add_query_arg(
            [
                'page' => self::PAGE_SLUG,
            ],
            admin_url('admin.php')
        );
    }

    /**
     * @param array<int|string,mixed> $values
     * @return array<int,string>
     */
    private static function sanitize_string_array(array $values): array
    {
        $sanitized = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = sanitize_key((string) $value);
            if ($string === '') {
                continue;
            }

            $sanitized[$string] = $string;
        }

        return array_values($sanitized);
    }

    /**
     * @param string $name
     * @param string $value
     * @param bool   $checked
     * @param string $label
     * @return void
     */
    private static function render_checkbox_chip(string $name, string $value, bool $checked, string $label): void
    {
        ?>
        <label class="dbvc-ai-option-chip">
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($checked); ?> />
            <span class="dbvc-ai-option-chip__text"><?php echo esc_html($label); ?></span>
        </label>
        <?php
    }
}
