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
        $tool_settings = class_exists('DBVC_Master_Settings')
            ? DBVC_Master_Settings::get_download_sample_entities_settings()
            : [];
        $has_saved_tool_settings = ! empty($tool_settings['last_saved_at']);
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

        $default_selected_post_types = (array) get_option('dbvc_post_types', []);
        $default_selected_taxonomies = function_exists('dbvc_get_selected_taxonomies')
            ? dbvc_get_selected_taxonomies()
            : (array) get_option('dbvc_taxonomies', []);
        $available_post_types = function_exists('dbvc_get_available_post_types')
            ? dbvc_get_available_post_types()
            : [];
        $available_taxonomies = function_exists('dbvc_get_available_taxonomies')
            ? dbvc_get_available_taxonomies()
            : [];

        $generation = isset($settings['generation']) && is_array($settings['generation']) ? $settings['generation'] : [];
        $selected_post_types = $has_saved_tool_settings && isset($tool_settings['post_types']) && is_array($tool_settings['post_types'])
            ? $tool_settings['post_types']
            : $default_selected_post_types;
        $selected_taxonomies = $has_saved_tool_settings && isset($tool_settings['taxonomies']) && is_array($tool_settings['taxonomies'])
            ? $tool_settings['taxonomies']
            : $default_selected_taxonomies;
        $selected_shape_mode = $has_saved_tool_settings && ! empty($tool_settings['shape_mode'])
            ? (string) $tool_settings['shape_mode']
            : (string) ($generation['shape_mode'] ?? '');
        $selected_package_profile = $has_saved_tool_settings && ! empty($tool_settings['package_profile'])
            ? (string) $tool_settings['package_profile']
            : (string) ($generation['package_profile'] ?? \Dbvc\AiPackage\Settings::DEFAULT_PACKAGE_PROFILE);
        $selected_value_style = $has_saved_tool_settings && ! empty($tool_settings['value_style'])
            ? (string) $tool_settings['value_style']
            : (string) ($generation['value_style'] ?? '');
        $selected_variant_set = $has_saved_tool_settings && ! empty($tool_settings['variant_set'])
            ? (string) $tool_settings['variant_set']
            : (string) ($generation['variant_set'] ?? '');
        $selected_observed_scan_cap = $has_saved_tool_settings && ! empty($tool_settings['observed_scan_cap'])
            ? (int) $tool_settings['observed_scan_cap']
            : (int) ($generation['observed_scan_cap'] ?? 0);
        $included_docs = $has_saved_tool_settings && isset($tool_settings['included_docs']) && is_array($tool_settings['included_docs'])
            ? $tool_settings['included_docs']
            : (isset($generation['included_docs']) && is_array($generation['included_docs']) ? $generation['included_docs'] : []);
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
                    max-width: 1040px;
                    width: 100%;
                }
                .dbvc-ai-option-table-wrap {
                    max-width: 1040px;
                }
                .dbvc-ai-option-table-toolbar {
                    display: flex;
                    align-items: center;
                    gap: 0.4rem;
                    margin: 0 0 0.75rem;
                }
                .dbvc-ai-option-table-toolbar .button-link {
                    padding: 0;
                }
                .dbvc-ai-option-table-toolbar__divider {
                    color: #646970;
                }
                .dbvc-ai-option-grid .check-column {
                    width: 2.5rem;
                }
                .dbvc-ai-option-grid td,
                .dbvc-ai-option-grid th {
                    vertical-align: middle;
                }
                .dbvc-ai-option-row-label {
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
                                        <?php
                                        $post_type_rows = [];
                                        foreach ($available_post_types as $post_type => $post_type_object) {
                                            $post_type_rows[] = [
                                                'value' => (string) $post_type,
                                                'checked' => in_array($post_type, $selected_post_types, true),
                                                'label' => $post_type_object->label . ' (' . $post_type . ')',
                                            ];
                                        }

                                        self::render_checkbox_table('post-types', 'dbvc_ai_generate[post_types][]', $post_type_rows);
                                        ?>
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
                                        <?php
                                        $taxonomy_rows = [];
                                        foreach ($available_taxonomies as $taxonomy => $taxonomy_object) {
                                            $taxonomy_rows[] = [
                                                'value' => (string) $taxonomy,
                                                'checked' => in_array($taxonomy, $selected_taxonomies, true),
                                                'label' => $taxonomy_object->labels->name . ' (' . $taxonomy . ')',
                                            ];
                                        }

                                        self::render_checkbox_table('taxonomies', 'dbvc_ai_generate[taxonomies][]', $taxonomy_rows);
                                        ?>
                                    </fieldset>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Generation defaults', 'dbvc'); ?></th>
                            <td>
                                <label for="dbvc-ai-package-profile"><strong><?php esc_html_e('Package profile', 'dbvc'); ?></strong></label><br />
                                <select id="dbvc-ai-package-profile" name="dbvc_ai_generate[package_profile]">
                                    <?php foreach ((array) \Dbvc\AiPackage\Settings::get_package_profile_options() as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_package_profile, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <br /><small class="description"><?php esc_html_e('Compact AI Chat is the default. It minimizes root docs and sample-file sprawl for browser-based LLM sessions. Full Reference keeps the richer package for deeper review workflows.', 'dbvc'); ?></small>
                                <br /><br />

                                <label for="dbvc-ai-shape-mode"><strong><?php esc_html_e('Shape mode', 'dbvc'); ?></strong></label><br />
                                <select id="dbvc-ai-shape-mode" name="dbvc_ai_generate[shape_mode]">
                                    <?php foreach ((array) \Dbvc\AiPackage\Settings::get_shape_mode_options() as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_shape_mode, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <br /><br />

                                <label for="dbvc-ai-value-style"><strong><?php esc_html_e('Value style', 'dbvc'); ?></strong></label><br />
                                <select id="dbvc-ai-value-style" name="dbvc_ai_generate[value_style]">
                                    <?php foreach ((array) \Dbvc\AiPackage\Settings::get_value_style_options() as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_value_style, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <br /><br />

                                <label for="dbvc-ai-variant-set"><strong><?php esc_html_e('Variant set', 'dbvc'); ?></strong></label><br />
                                <select id="dbvc-ai-variant-set" name="dbvc_ai_generate[variant_set]">
                                    <?php foreach ((array) \Dbvc\AiPackage\Settings::get_variant_set_options() as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_variant_set, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <br /><br />

                                <label for="dbvc-ai-observed-scan-cap"><strong><?php esc_html_e('Observed-shape scan cap', 'dbvc'); ?></strong></label><br />
                                <input id="dbvc-ai-observed-scan-cap" name="dbvc_ai_generate[observed_scan_cap]" type="number" min="<?php echo esc_attr((string) \Dbvc\AiPackage\Settings::MIN_OBSERVED_SCAN_CAP); ?>" max="<?php echo esc_attr((string) \Dbvc\AiPackage\Settings::MAX_OBSERVED_SCAN_CAP); ?>" value="<?php echo esc_attr((string) $selected_observed_scan_cap); ?>" class="small-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Included docs', 'dbvc'); ?></th>
                            <td>
                                <?php if ($selected_package_profile === 'compact_ai_chat') : ?>
                                    <p><strong><?php esc_html_e('Compact profile preset', 'dbvc'); ?></strong></p>
                                    <p class="description"><?php esc_html_e('Compact mode emits a single merged root guide and removes the heavier top-level doc set by default. Switch to Full Reference to control the richer root docs directly.', 'dbvc'); ?></p>
                                <?php else : ?>
                                    <fieldset>
                                        <?php
                                        $doc_rows = [];
                                        foreach ((array) \Dbvc\AiPackage\Settings::get_included_doc_options() as $doc_key => $doc_label) {
                                            $doc_rows[] = [
                                                'value' => (string) $doc_key,
                                                'checked' => in_array($doc_key, $included_docs, true),
                                                'label' => (string) $doc_label,
                                            ];
                                        }

                                        self::render_checkbox_table('included-docs', 'dbvc_ai_generate[included_docs][]', $doc_rows);
                                        ?>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e('These toggles control the richer top-level agent/operator docs in the package root. Compact mode replaces them with a merged guide instead.', 'dbvc'); ?></p>
                                <?php endif; ?>
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
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var toggleButtons = document.querySelectorAll('.dbvc-ai-option-table-toggle');

                    toggleButtons.forEach(function(button) {
                        button.addEventListener('click', function(event) {
                            event.preventDefault();

                            var target = button.getAttribute('data-dbvc-target');
                            var shouldCheck = button.getAttribute('data-dbvc-state') === 'checked';
                            var container = target ? document.querySelector('[data-dbvc-option-table-target="' + target + '"]') : null;

                            if (! container) {
                                return;
                            }

                            container.querySelectorAll('input[type="checkbox"]').forEach(function(input) {
                                input.checked = shouldCheck;
                            });
                        });
                    });
                });
            </script>
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
        if (class_exists('DBVC_Master_Settings')) {
            DBVC_Master_Settings::save_download_sample_entities_settings($payload);
        }
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

        $package_profile = isset($raw['package_profile']) ? sanitize_key((string) $raw['package_profile']) : '';
        $shape_mode = isset($raw['shape_mode']) ? sanitize_key((string) $raw['shape_mode']) : '';
        $value_style = isset($raw['value_style']) ? sanitize_key((string) $raw['value_style']) : '';
        $variant_set = isset($raw['variant_set']) ? sanitize_key((string) $raw['variant_set']) : '';
        $observed_scan_cap = isset($raw['observed_scan_cap']) ? absint($raw['observed_scan_cap']) : 0;

        return [
            'post_types' => self::sanitize_string_array(isset($raw['post_types']) && is_array($raw['post_types']) ? $raw['post_types'] : []),
            'taxonomies' => self::sanitize_string_array(isset($raw['taxonomies']) && is_array($raw['taxonomies']) ? $raw['taxonomies'] : []),
            'package_profile' => $package_profile,
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
     * @param string                    $group_id
     * @param string                    $name
     * @param array<int,array<string,mixed>> $rows
     * @return void
     */
    private static function render_checkbox_table(string $group_id, string $name, array $rows): void
    {
        $sanitized_group_id = sanitize_html_class($group_id);
        ?>
        <div class="dbvc-ai-option-table-wrap" data-dbvc-option-table-target="<?php echo esc_attr($group_id); ?>">
            <div class="dbvc-ai-option-table-toolbar">
                <button type="button" class="button-link dbvc-ai-option-table-toggle" data-dbvc-target="<?php echo esc_attr($group_id); ?>" data-dbvc-state="checked"><?php esc_html_e('Select All', 'dbvc'); ?></button>
                <span class="dbvc-ai-option-table-toolbar__divider">|</span>
                <button type="button" class="button-link dbvc-ai-option-table-toggle" data-dbvc-target="<?php echo esc_attr($group_id); ?>" data-dbvc-state="unchecked"><?php esc_html_e('Deselect All', 'dbvc'); ?></button>
            </div>
            <table class="widefat striped dbvc-ai-option-grid">
                <thead>
                    <tr>
                        <th scope="col" class="check-column"><span class="screen-reader-text"><?php esc_html_e('Select', 'dbvc'); ?></span></th>
                        <th scope="col"><?php esc_html_e('Item', 'dbvc'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $index => $row) : ?>
                        <?php
                        $row_id = sprintf(
                            'dbvc-ai-option-%1$s-%2$d',
                            $sanitized_group_id,
                            (int) $index
                        );
                        ?>
                        <tr>
                            <td class="check-column">
                                <input
                                    id="<?php echo esc_attr($row_id); ?>"
                                    type="checkbox"
                                    name="<?php echo esc_attr($name); ?>"
                                    value="<?php echo esc_attr((string) ($row['value'] ?? '')); ?>"
                                    <?php checked(! empty($row['checked'])); ?> />
                            </td>
                            <td>
                                <label class="dbvc-ai-option-row-label" for="<?php echo esc_attr($row_id); ?>">
                                    <?php echo esc_html((string) ($row['label'] ?? '')); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
