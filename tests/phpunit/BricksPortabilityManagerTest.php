<?php

class BricksPortabilityManagerTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '0');
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
        $this->register_bricks_template_test_types();
        $this->reset_sample_options();
    }

    public function tear_down(): void
    {
        $this->reset_sample_options();
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '0');
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        $this->cleanup_portability_storage();
        parent::tear_down();
    }

    public function test_portability_page_renders_as_standalone_bricks_submenu(): void
    {
        $main_render = $this->render_admin_page('overview');
        $this->assertStringNotContainsString('id="dbvc-bricks-tab-portability"', $main_render);
        $this->assertStringNotContainsString('id="dbvc-bricks-panel-portability"', $main_render);

        $render = $this->render_portability_page();
        $this->assertStringContainsString('Bricks Settings Transfer', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-subtab-workspace"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-subtab-history"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-panel-workspace"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-panel-history"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-panel-portability"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-review-state"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-refresh-session"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-export-button"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-import-button"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-save-draft"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-save-draft-top"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-apply-button"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-apply-button-top"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-confirm-apply"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-confirm-apply-top"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-filter-decision"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-filter-warning"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-hide-identical"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-page-size"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-page-prev"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-page-next"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-page-summary"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-applied-summary"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-stats"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-domain-summary-toggle"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-row-modal"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-portability-row-modal-close"', $render);
        $this->assertSame(2, substr_count($render, 'class="dbvc-bricks-portability-approval-controls"'));
        $this->assertStringContainsString('Selected Action', $render);
        $this->assertStringContainsString('Applied to This Site', $render);
        $this->assertStringContainsString('id="dbvc-bricks-notice-success" class="notice notice-success is-dismissible" hidden', $render);
        $this->assertStringContainsString('id="dbvc-bricks-notice-error" class="notice notice-error is-dismissible" hidden', $render);
    }

    public function test_portability_export_import_apply_and_rollback_flow(): void
    {
        update_option('bricks_color_palette', [
            ['id' => 'pal-primary', 'name' => 'Primary', 'value' => '#ff0000'],
            ['id' => 'pal-secondary', 'name' => 'Secondary', 'value' => '#00ff00'],
        ]);
        update_option('bricks_global_classes', [
            ['id' => 'cls-btn', 'name' => 'btn-primary', 'settings' => ['color' => 'red']],
            ['id' => 'cls-card', 'name' => 'card', 'settings' => ['gap' => 'm']],
        ]);
        update_option('bricks_global_classes_categories', [
            ['id' => 'cat-layout', 'name' => 'Layout'],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['color_palette', 'global_classes']);
        $this->assertNotWPError($export);
        $this->assertNotEmpty($export['zip_path']);
        $this->assertFileExists($export['zip_path']);
        $download_url = DBVC_Bricks_Portability::get_export_download_url((string) $export['export_id']);
        $this->assertStringContainsString('export_id=', $download_url);
        $this->assertStringNotContainsString('&amp;', $download_url);

        $pre_apply_target_color_palette = [
            ['id' => 'pal-primary', 'name' => 'Primary', 'value' => '#0000ff'],
        ];
        $pre_apply_target_classes = [
            ['id' => 'target-btn', 'name' => 'btn-primary', 'settings' => ['color' => 'blue']],
            ['id' => 'cls-local', 'name' => 'local-only', 'settings' => ['color' => 'black']],
        ];
        update_option('bricks_color_palette', $pre_apply_target_color_palette);
        update_option('bricks_global_classes', $pre_apply_target_classes);
        update_option('bricks_global_classes_categories', [
            ['id' => 'cat-legacy', 'name' => 'Legacy'],
        ]);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename($export['zip_path']),
        ]);
        $this->assertNotWPError($session);
        $this->assertNotEmpty($session['session_id']);
        $this->assertNotEmpty($session['rows']);
        $this->assertSame('fresh', (string) $session['freshness']['state']);

        $statuses = array_map(static function ($row) {
            return is_array($row) ? (string) ($row['status'] ?? '') : '';
        }, $session['rows']);
        $this->assertContains('same_name_different_id', $statuses);
        $this->assertContains('new_in_source', $statuses);
        $this->assertContains('missing_from_source', $statuses);

        $decisions = [];
        foreach ($session['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = (string) ($row['row_id'] ?? '');
            $status = sanitize_key((string) ($row['status'] ?? ''));
            if ($row_id === '') {
                continue;
            }

            if (in_array($status, ['same_name_different_id', 'value_changed', 'changed_props', 'added_props', 'removed_props'], true)) {
                $decisions[$row_id] = 'replace_with_incoming';
            } elseif ($status === 'new_in_source') {
                $decisions[$row_id] = 'add_incoming';
            } else {
                $decisions[$row_id] = 'keep_current';
            }

            if (($row['row_type'] ?? '') === 'meta') {
                $decisions[$row_id] = 'replace_with_incoming';
            }
        }

        $apply = DBVC_Bricks_Portability_Apply_Service::apply_session($session['session_id'], $decisions);
        $this->assertNotWPError($apply);
        $this->assertTrue((bool) ($apply['applied'] ?? false));
        $this->assertNotEmpty($apply['backup_id']);
        $this->assertNotEmpty($apply['approved_at_gmt']);

        $applied_classes = get_option('bricks_global_classes', []);
        $this->assertCount(3, $applied_classes);
        $this->assertSame('btn-primary', (string) $applied_classes[0]['name']);
        $this->assertSame('cls-btn', (string) $applied_classes[0]['id']);
        $this->assertSame('local-only', (string) $applied_classes[1]['name']);
        $this->assertSame('card', (string) $applied_classes[2]['name']);

        $applied_palettes = get_option('bricks_color_palette', []);
        $this->assertCount(2, $applied_palettes);
        $this->assertSame('#ff0000', (string) $applied_palettes[0]['value']);
        $this->assertSame('Secondary', (string) $applied_palettes[1]['name']);

        $loaded_after_apply = DBVC_Bricks_Portability_Package_Service::load_session($session['session_id']);
        $this->assertNotWPError($loaded_after_apply);
        $this->assertNotEmpty($loaded_after_apply['approval']['approved_at_gmt']);
        $this->assertNotEmpty($loaded_after_apply['approval']['backup_id']);

        $view_after_apply = DBVC_Bricks_Portability_Package_Service::build_session_view($loaded_after_apply);
        $this->assertNotEmpty($view_after_apply['approval']['approved_at_gmt']);
        $this->assertSame('stale', (string) $view_after_apply['freshness']['state']);
        $this->assertContains('color_palette', (array) $view_after_apply['freshness']['changed_domains']);
        $matched_class_row = $this->find_row_by_object_label((array) $view_after_apply['rows'], 'btn-primary');
        $this->assertNotNull($matched_class_row);
        $matched_class_row_id = (string) ($matched_class_row['row_id'] ?? '');
        $this->assertNotSame('', $matched_class_row_id);
        $this->assertSame('replace_with_incoming', (string) $view_after_apply['draft']['decisions'][$matched_class_row_id]);
        $this->assertNotEmpty($matched_class_row['approved_at_gmt']);
        $this->assertSame('applied', (string) $matched_class_row['approval_status']);

        $recent_sessions = DBVC_Bricks_Portability_Package_Service::list_recent_sessions(10);
        $record = $this->find_session_record($recent_sessions, (string) $session['session_id']);
        $this->assertNotNull($record);
        $this->assertNotEmpty($record['approval']['approved_at_gmt']);

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup($apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertTrue((bool) ($rollback['rolled_back'] ?? false));
        $this->assertSame($pre_apply_target_classes, get_option('bricks_global_classes', []));
        $this->assertSame($pre_apply_target_color_palette, get_option('bricks_color_palette', []));

        $loaded_after_rollback = DBVC_Bricks_Portability_Package_Service::load_session($session['session_id']);
        $this->assertNotWPError($loaded_after_rollback);
        $view_after_rollback = DBVC_Bricks_Portability_Package_Service::build_session_view($loaded_after_rollback);
        $this->assertSame('fresh', (string) $view_after_rollback['freshness']['state']);
        $this->assertNotEmpty($view_after_rollback['rollback']['rolled_back_at_gmt']);
        $this->assertSame((string) $apply['backup_id'], (string) $view_after_rollback['rollback']['backup_id']);
    }

    public function test_refresh_session_rebuilds_target_compare_against_live_site(): void
    {
        update_option('bricks_color_palette', [
            ['id' => 'pal-primary', 'name' => 'Primary', 'value' => '#ff0000'],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['color_palette']);
        $this->assertNotWPError($export);

        update_option('bricks_color_palette', []);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename($export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_status((array) $session['rows'], 'new_in_source');
        $this->assertNotNull($row);
        $row_id = (string) $row['row_id'];
        $this->assertNotSame('', $row_id);

        $apply = DBVC_Bricks_Portability_Apply_Service::apply_session($session['session_id'], [
            $row_id => 'add_incoming',
        ], [
            $row_id,
        ]);
        $this->assertNotWPError($apply);
        $this->assertTrue((bool) ($apply['applied'] ?? false));

        $loaded_stale = DBVC_Bricks_Portability_Package_Service::load_session($session['session_id']);
        $this->assertNotWPError($loaded_stale);
        $stale_view = DBVC_Bricks_Portability_Package_Service::build_session_view($loaded_stale);
        $this->assertSame('stale', (string) $stale_view['freshness']['state']);

        $refreshed = DBVC_Bricks_Portability_Package_Service::refresh_session($session['session_id']);
        $this->assertNotWPError($refreshed);
        $this->assertSame('fresh', (string) $refreshed['freshness']['state']);
        $this->assertNotEmpty($refreshed['refreshed_at_gmt']);
    }

    public function test_import_rejects_unchecksummed_package_payload_files(): void
    {
        update_option('bricks_color_palette', [
            ['id' => 'pal-primary', 'name' => 'Primary', 'value' => '#ff0000'],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['color_palette']);
        $this->assertNotWPError($export);

        $this->mutate_zip_checksums((string) $export['zip_path'], static function (array $checksums): array {
            unset($checksums['raw-options/bricks_color_palette.json']);
            return $checksums;
        });

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename($export['zip_path']),
        ]);

        $this->assertWPError($session);
        $this->assertSame('dbvc_bricks_portability_checksum_unlisted', $session->get_error_code());
    }

    public function test_import_rejects_unexpected_checksummed_package_files(): void
    {
        update_option('bricks_color_palette', [
            ['id' => 'pal-primary', 'name' => 'Primary', 'value' => '#ff0000'],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['color_palette']);
        $this->assertNotWPError($export);

        $this->mutate_zip_checksums((string) $export['zip_path'], static function (array $checksums, ZipArchive $zip): array {
            $payload = "<?php\n// unexpected package payload\n";
            $zip->addFromString('unexpected.php', $payload);
            $checksums['unexpected.php'] = 'sha256:' . hash('sha256', $payload);
            return $checksums;
        });

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename($export['zip_path']),
        ]);

        $this->assertWPError($session);
        $this->assertSame('dbvc_bricks_portability_checksum_path_invalid', $session->get_error_code());
    }

    public function test_breakpoints_verification_blocks_unrecognized_export_shape(): void
    {
        update_option('bricks_breakpoints', [
            'mobile' => ['label' => 'Mobile', 'width' => '480px'],
            'tablet' => ['label' => 'Tablet', 'width' => '768px'],
        ]);

        $domain = DBVC_Bricks_Portability_Registry::get_domain('breakpoints');
        $this->assertIsArray($domain);

        $normalized = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($domain);
        $this->assertSame('verified', (string) $normalized['verification']['status']);
        $this->assertTrue((bool) $normalized['verification']['safe_for_export']);
        $this->assertTrue((bool) $normalized['verification']['safe_for_apply']);

        update_option('bricks_breakpoints', [
            'unexpected' => 'shape',
        ]);

        $blocked = DBVC_Bricks_Portability_Package_Service::create_export(['breakpoints']);
        $this->assertWPError($blocked);
        $this->assertSame('dbvc_bricks_portability_domain_verification_failed', $blocked->get_error_code());
    }

    public function test_media_backed_fonts_and_icons_export_import_apply_and_rollback(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks media-backed portability package tests.');
        }

        $font_attachment_id = $this->create_test_attachment('dbvc-test-font.woff2', 'test-font-binary', 'font/woff2');
        $font_post_id = wp_insert_post([
            'post_type' => 'bricks_fonts',
            'post_status' => 'publish',
            'post_title' => 'Inter Tight',
            'post_name' => 'inter-tight',
        ]);
        $this->assertIsInt($font_post_id);
        $this->assertGreaterThan(0, $font_post_id);
        update_post_meta($font_post_id, 'bricks_font_faces', [
            [
                'font-weight' => '400',
                'font-style' => 'normal',
                'woff2' => $font_attachment_id,
            ],
        ]);
        update_option('bricks_font_face_rules', '@font-face{font-family:"Inter Tight";}');
        update_option('bricks_global_classes', [
            [
                'id' => 'cls-font',
                'name' => 'uses-imported-font',
                'settings' => [
                    'typography' => [
                        'font-family' => 'custom_font_' . $font_post_id,
                    ],
                ],
            ],
        ]);
        update_option('bricks_global_classes_categories', []);

        $icon_attachment_id = $this->create_test_attachment('dbvc-test-icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><path d="M0 0h8v8H0z"/></svg>', 'image/svg+xml');
        update_option('bricks_icon_sets', [
            [
                'id' => 'set_test',
                'name' => 'DBVC Icons',
            ],
        ]);
        update_option('bricks_custom_icons', [
            [
                'id' => 'icon_test',
                'name' => 'Spark',
                'setId' => 'set_test',
                'url' => wp_get_attachment_url($icon_attachment_id),
                'attachment_id' => $icon_attachment_id,
            ],
        ]);
        update_option('bricks_disabled_icon_sets', []);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['custom_fonts', 'icon_collections', 'global_classes']);
        $this->assertNotWPError($export);
        $this->assertFileExists($export['zip_path']);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open((string) $export['zip_path']) === true);
        $font_domain = json_decode((string) $zip->getFromName('domains/custom-fonts.json'), true);
        $icon_domain = json_decode((string) $zip->getFromName('domains/icon-collections.json'), true);
        $media_manifest = json_decode((string) $zip->getFromName('media.json'), true);
        $this->assertIsArray($font_domain);
        $this->assertIsArray($icon_domain);
        $this->assertIsArray($media_manifest);
        $this->assertSame('custom_fonts', (string) $font_domain['domain']);
        $this->assertSame('icon_collections', (string) $icon_domain['domain']);
        $this->assertCount(1, (array) $font_domain['objects']);
        $this->assertCount(1, (array) $icon_domain['objects']);
        $this->assertNotEmpty($font_domain['media_refs'][0]['package_path']);
        $this->assertNotEmpty($icon_domain['media_refs'][0]['package_path']);
        $this->assertArrayNotHasKey('source_file', (array) $font_domain['media_refs'][0]);
        $this->assertArrayNotHasKey('source_file', (array) $icon_domain['media_refs'][0]);
        $this->assertSame(2, count((array) $media_manifest['files']));
        $this->assertNotFalse($zip->locateName((string) $font_domain['media_refs'][0]['package_path']));
        $this->assertNotFalse($zip->locateName((string) $icon_domain['media_refs'][0]['package_path']));
        $this->assertTrue($zip->close());

        wp_delete_post($font_post_id, true);
        wp_delete_attachment($font_attachment_id, true);
        wp_delete_attachment($icon_attachment_id, true);
        delete_option('bricks_font_face_rules');
        update_option('bricks_global_classes', []);
        update_option('bricks_global_classes_categories', []);
        update_option('bricks_icon_sets', []);
        update_option('bricks_custom_icons', []);
        update_option('bricks_disabled_icon_sets', []);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename($export['zip_path']),
        ]);
        $this->assertNotWPError($session);
        $this->assertSame('fresh', (string) $session['freshness']['state']);

        $font_row = $this->find_row_by_object_label((array) $session['rows'], 'Inter Tight');
        $icon_row = $this->find_row_by_object_label((array) $session['rows'], 'DBVC Icons');
        $class_row = $this->find_row_by_object_label((array) $session['rows'], 'uses-imported-font');
        $this->assertNotNull($font_row);
        $this->assertNotNull($icon_row);
        $this->assertNotNull($class_row);
        $this->assertSame('custom_fonts', (string) $font_row['domain_key']);
        $this->assertSame('icon_collections', (string) $icon_row['domain_key']);
        $this->assertSame('new_in_source', (string) $font_row['status']);
        $this->assertSame('new_in_source', (string) $icon_row['status']);
        $this->assertSame('new_in_source', (string) $class_row['status']);
        $this->assertSame('add_incoming', (string) $font_row['suggested_action']);
        $this->assertSame('add_incoming', (string) $icon_row['suggested_action']);
        $this->assertSame(['add_incoming', 'keep_current', 'skip'], array_values((array) $font_row['available_actions']));
        $this->assertSame(['add_incoming', 'keep_current', 'skip'], array_values((array) $icon_row['available_actions']));
        $this->assertContains('This media-backed Bricks domain supports adding new incoming objects; replacement and delete-sync remain disabled.', (array) $font_row['warnings']);
        $this->assertContains('This media-backed Bricks domain supports adding new incoming objects; replacement and delete-sync remain disabled.', (array) $icon_row['warnings']);

        $decisions = [
            (string) $font_row['row_id'] => 'add_incoming',
            (string) $icon_row['row_id'] => 'add_incoming',
            (string) $class_row['row_id'] => 'add_incoming',
        ];
        $apply = DBVC_Bricks_Portability_Apply_Service::apply_session((string) $session['session_id'], $decisions);
        $this->assertNotWPError($apply);
        $this->assertTrue((bool) ($apply['applied'] ?? false));
        $this->assertNotEmpty($apply['backup_id']);
        $this->assertSame(1, (int) $apply['summary']['media']['created_posts']);
        $this->assertSame(2, (int) $apply['summary']['media']['created_attachments']);

        $target_font_post_id = $this->find_bricks_font_post_by_title('Inter Tight');
        $this->assertGreaterThan(0, $target_font_post_id);
        $target_font_faces = get_post_meta($target_font_post_id, 'bricks_font_faces', true);
        $this->assertIsArray($target_font_faces);
        $target_font_attachment_id = (int) ($target_font_faces[0]['woff2'] ?? 0);
        $this->assertGreaterThan(0, $target_font_attachment_id);
        $this->assertNotSame($font_attachment_id, $target_font_attachment_id);
        $this->assertFileExists((string) get_attached_file($target_font_attachment_id));
        $this->assertSame('', get_option('bricks_font_face_rules', 'missing'));

        $applied_classes = get_option('bricks_global_classes', []);
        $this->assertCount(1, $applied_classes);
        $this->assertSame('custom_font_' . $target_font_post_id, (string) $applied_classes[0]['settings']['typography']['font-family']);

        $applied_icon_sets = get_option('bricks_icon_sets', []);
        $applied_icons = get_option('bricks_custom_icons', []);
        $this->assertCount(1, $applied_icon_sets);
        $this->assertCount(1, $applied_icons);
        $this->assertSame('set_test', (string) $applied_icon_sets[0]['id']);
        $target_icon_attachment_id = (int) ($applied_icons[0]['attachment_id'] ?? 0);
        $this->assertGreaterThan(0, $target_icon_attachment_id);
        $this->assertNotSame($icon_attachment_id, $target_icon_attachment_id);
        $this->assertSame(wp_get_attachment_url($target_icon_attachment_id), (string) $applied_icons[0]['url']);

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertTrue((bool) ($rollback['rolled_back'] ?? false));
        $this->assertSame([], get_option('bricks_global_classes', []));
        $this->assertSame([], get_option('bricks_icon_sets', []));
        $this->assertSame([], get_option('bricks_custom_icons', []));
        $this->assertSame([], get_option('bricks_disabled_icon_sets', []));
        $this->assertSame('missing', get_option('bricks_font_face_rules', 'missing'));
        $this->assertNull(get_post($target_font_post_id));
        $this->assertNull(get_post($target_font_attachment_id));
        $this->assertNull(get_post($target_icon_attachment_id));
    }

    public function test_bricks_templates_export_import_apply_and_rollback(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_header_id = $this->create_bricks_template_fixture([
            'title' => 'Site Header',
            'slug' => 'site-header',
            'type' => 'header',
            'area_key' => '_bricks_page_header_2',
            'elements' => [
                [
                    'id' => 'header-root',
                    'name' => 'section',
                    'settings' => [
                        'tag' => 'header',
                        'color' => 'var(--brand-primary)',
                        '_cssGlobalClasses' => ['header-shell'],
                    ],
                ],
            ],
            'settings' => [
                'templateConditions' => [
                    ['main' => 'entireWebsite'],
                ],
            ],
            'tags' => [
                ['slug' => 'layout', 'name' => 'Layout'],
            ],
            'bundles' => [
                ['slug' => 'site-shell', 'name' => 'Site Shell'],
            ],
        ]);
        $source_footer_id = $this->create_bricks_template_fixture([
            'title' => 'Site Footer',
            'slug' => 'site-footer',
            'type' => 'footer',
            'area_key' => '_bricks_page_footer_2',
            'elements' => [
                [
                    'id' => 'footer-root',
                    'name' => 'section',
                    'settings' => [
                        'tag' => 'footer',
                        'content' => 'Incoming footer',
                    ],
                ],
            ],
            'settings' => [
                'templateConditions' => [
                    ['main' => 'entireWebsite'],
                ],
            ],
            'tags' => [
                ['slug' => 'layout', 'name' => 'Layout'],
            ],
            'bundles' => [
                ['slug' => 'site-shell', 'name' => 'Site Shell'],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        wp_delete_post($source_header_id, true);
        wp_delete_post($source_footer_id, true);
        $this->delete_all_terms_for_taxonomy('template_tag');
        $this->delete_all_terms_for_taxonomy('template_bundle');

        $target_header_id = $this->create_bricks_template_fixture([
            'title' => 'Site Header',
            'slug' => 'site-header',
            'type' => 'header',
            'area_key' => '_bricks_page_header_2',
            'elements' => [
                [
                    'id' => 'header-root',
                    'name' => 'section',
                    'settings' => [
                        'tag' => 'header',
                        'content' => 'Current header',
                    ],
                ],
            ],
            'settings' => [
                'templateConditions' => [
                    ['main' => 'frontPage'],
                ],
            ],
        ]);
        $target_local_id = $this->create_bricks_template_fixture([
            'title' => 'Local Only',
            'slug' => 'local-only',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'local-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Target only',
                    ],
                ],
            ],
        ]);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);
        $template_row = $this->find_row_by_domain_key((array) $session['rows'], 'bricks_templates');
        $this->assertNotNull($template_row);
        $this->assertSame('bricks_templates', (string) $template_row['domain_key']);

        $statuses = [];
        foreach ((array) $session['rows'] as $row) {
            if (is_array($row) && (string) ($row['domain_key'] ?? '') === 'bricks_templates') {
                $statuses[] = (string) ($row['status'] ?? '');
            }
        }
        $this->assertNotEmpty(array_intersect(['value_changed', 'changed_props', 'added_props', 'removed_props'], $statuses));
        $this->assertContains('new_in_source', $statuses);
        $this->assertContains('missing_from_source', $statuses);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);
        $this->assertTrue((bool) ($apply['applied'] ?? false));
        $this->assertSame(['bricks_templates'], (array) ($apply['summary']['affected_domains'] ?? []));
        $this->assertSame(1, (int) ($apply['summary']['entities']['created_posts'] ?? 0));
        $this->assertSame(1, (int) ($apply['summary']['entities']['updated_posts'] ?? 0));

        $updated_header = get_post($target_header_id);
        $this->assertInstanceOf(WP_Post::class, $updated_header);
        $this->assertSame('site-header', (string) $updated_header->post_name);
        $this->assertSame('header', (string) get_post_meta($target_header_id, '_bricks_template_type', true));
        $this->assertSame('entireWebsite', (string) get_post_meta($target_header_id, '_bricks_template_settings', true)['templateConditions'][0]['main']);
        $this->assertSame('var(--brand-primary)', (string) get_post_meta($target_header_id, '_bricks_page_header_2', true)[0]['settings']['color']);
        $this->assertSame(['layout'], $this->get_template_term_slugs($target_header_id, 'template_tag'));
        $this->assertSame(['site-shell'], $this->get_template_term_slugs($target_header_id, 'template_bundle'));

        $created_footer_id = $this->find_bricks_template_by_slug('site-footer');
        $this->assertGreaterThan(0, $created_footer_id);
        $this->assertSame('footer', (string) get_post_meta($created_footer_id, '_bricks_template_type', true));
        $this->assertSame('Incoming footer', (string) get_post_meta($created_footer_id, '_bricks_page_footer_2', true)[0]['settings']['content']);
        $this->assertGreaterThan(0, $this->find_bricks_template_by_slug('local-only'));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame('frontPage', (string) get_post_meta($target_header_id, '_bricks_template_settings', true)['templateConditions'][0]['main']);
        $this->assertSame('Current header', (string) get_post_meta($target_header_id, '_bricks_page_header_2', true)[0]['settings']['content']);
        $this->assertSame(0, $this->find_bricks_template_by_slug('site-footer'));
        $this->assertGreaterThan(0, $this->find_bricks_template_by_slug('local-only'));
        $this->assertInstanceOf(WP_Post::class, get_post($target_local_id));
    }

    public function test_bricks_templates_hydrate_embedded_media_and_rollback(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_attachment_id = $this->create_test_attachment('dbvc-template-image.jpg', 'template-image-binary', 'image/jpeg');
        $source_url = wp_get_attachment_url($source_attachment_id);
        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Image Template',
            'slug' => 'image-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'image-root',
                    'name' => 'image',
                    'settings' => [
                        'image' => [
                            'id' => $source_attachment_id,
                            'url' => $source_url,
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        wp_delete_post($source_template_id, true);
        wp_delete_attachment($source_attachment_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Image Template');
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['source']['media_refs']);
        $this->assertNotEmpty($row['source']['dependency_refs']);
        $this->assertSame('media', (string) $row['source']['dependency_refs'][0]['ref_type']);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);
        $this->assertSame(1, (int) ($apply['summary']['media']['created_attachments'] ?? 0));
        $this->assertSame(1, (int) ($apply['summary']['entities']['created_posts'] ?? 0));

        $target_template_id = $this->find_bricks_template_by_slug('image-template');
        $this->assertGreaterThan(0, $target_template_id);
        $elements = get_post_meta($target_template_id, '_bricks_page_content_2', true);
        $target_attachment_id = (int) ($elements[0]['settings']['image']['id'] ?? 0);
        $this->assertGreaterThan(0, $target_attachment_id);
        $this->assertNotSame($source_attachment_id, $target_attachment_id);
        $this->assertSame(wp_get_attachment_url($target_attachment_id), (string) ($elements[0]['settings']['image']['url'] ?? ''));
        $this->assertInstanceOf(WP_Post::class, get_post($target_attachment_id));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('image-template'));
        $this->assertNull(get_post($target_attachment_id));
    }

    public function test_bricks_templates_hydrate_gallery_background_and_video_media(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $image_attachment_id = $this->create_test_attachment('dbvc-template-hero.jpg', 'hero-image-binary', 'image/jpeg');
        $gallery_one_id = $this->create_test_attachment('dbvc-template-gallery-one.png', 'gallery-one-binary', 'image/png');
        $gallery_two_id = $this->create_test_attachment('dbvc-template-gallery-two.webp', 'gallery-two-binary', 'image/webp');
        $background_id = $this->create_test_attachment('dbvc-template-background.jpg', 'background-binary', 'image/jpeg');
        $video_id = $this->create_test_attachment('dbvc-template-video.mp4', 'video-binary', 'video/mp4');
        $source_attachment_ids = [$image_attachment_id, $gallery_one_id, $gallery_two_id, $background_id, $video_id];

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Media Matrix Template',
            'slug' => 'media-matrix-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'media-image',
                    'name' => 'image',
                    'settings' => [
                        'image' => [
                            'id' => $image_attachment_id,
                            'url' => wp_get_attachment_url($image_attachment_id),
                        ],
                    ],
                ],
                [
                    'id' => 'media-gallery',
                    'name' => 'gallery',
                    'settings' => [
                        'gallery' => [
                            [
                                'id' => $gallery_one_id,
                                'url' => wp_get_attachment_url($gallery_one_id),
                            ],
                            [
                                'id' => $gallery_two_id,
                                'url' => wp_get_attachment_url($gallery_two_id),
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'media-background',
                    'name' => 'section',
                    'settings' => [
                        'background' => [
                            'image' => [
                                'id' => $background_id,
                                'url' => wp_get_attachment_url($background_id),
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'media-video',
                    'name' => 'video',
                    'settings' => [
                        'video' => [
                            'id' => $video_id,
                            'url' => wp_get_attachment_url($video_id),
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        wp_delete_post($source_template_id, true);
        foreach ($source_attachment_ids as $source_attachment_id) {
            wp_delete_attachment($source_attachment_id, true);
        }

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Media Matrix Template');
        $this->assertNotNull($row);
        $media_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'media';
        }));
        $this->assertCount(5, $media_dependency_refs);
        $this->assertCount(5, (array) ($row['source']['media_refs'] ?? []));

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);
        $this->assertSame(5, (int) ($apply['summary']['media']['created_attachments'] ?? 0));
        $this->assertSame(1, (int) ($apply['summary']['entities']['created_posts'] ?? 0));

        $target_template_id = $this->find_bricks_template_by_slug('media-matrix-template');
        $this->assertGreaterThan(0, $target_template_id);
        $elements = get_post_meta($target_template_id, '_bricks_page_content_2', true);
        $target_attachment_ids = [
            (int) ($elements[0]['settings']['image']['id'] ?? 0),
            (int) ($elements[1]['settings']['gallery'][0]['id'] ?? 0),
            (int) ($elements[1]['settings']['gallery'][1]['id'] ?? 0),
            (int) ($elements[2]['settings']['background']['image']['id'] ?? 0),
            (int) ($elements[3]['settings']['video']['id'] ?? 0),
        ];

        $this->assertCount(5, array_unique($target_attachment_ids));
        foreach ($target_attachment_ids as $index => $target_attachment_id) {
            $this->assertGreaterThan(0, $target_attachment_id);
            $this->assertNotContains($target_attachment_id, $source_attachment_ids);
            $this->assertInstanceOf(WP_Post::class, get_post($target_attachment_id), 'Expected hydrated target attachment at index ' . $index);
        }

        $this->assertSame(wp_get_attachment_url($target_attachment_ids[0]), (string) ($elements[0]['settings']['image']['url'] ?? ''));
        $this->assertSame(wp_get_attachment_url($target_attachment_ids[1]), (string) ($elements[1]['settings']['gallery'][0]['url'] ?? ''));
        $this->assertSame(wp_get_attachment_url($target_attachment_ids[2]), (string) ($elements[1]['settings']['gallery'][1]['url'] ?? ''));
        $this->assertSame(wp_get_attachment_url($target_attachment_ids[3]), (string) ($elements[2]['settings']['background']['image']['url'] ?? ''));
        $this->assertSame(wp_get_attachment_url($target_attachment_ids[4]), (string) ($elements[3]['settings']['video']['url'] ?? ''));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('media-matrix-template'));
        foreach ($target_attachment_ids as $target_attachment_id) {
            $this->assertNull(get_post($target_attachment_id));
        }
    }

    public function test_bricks_templates_hydrate_template_settings_media(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $settings_attachment_id = $this->create_test_attachment('dbvc-template-settings-thumbnail.jpg', 'settings-thumbnail-binary', 'image/jpeg');
        $settings_attachment_url = wp_get_attachment_url($settings_attachment_id);
        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Template Settings Media',
            'slug' => 'template-settings-media',
            'type' => 'content',
            'settings' => [
                'thumbnail' => [
                    'id' => $settings_attachment_id,
                    'url' => $settings_attachment_url,
                ],
                'templateConditions' => [
                    [
                        'main' => 'entireWebsite',
                    ],
                ],
            ],
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'settings-media-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Template settings media',
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        wp_delete_post($source_template_id, true);
        wp_delete_attachment($settings_attachment_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Template Settings Media');
        $this->assertNotNull($row);
        $media_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'media';
        }));
        $this->assertCount(1, $media_dependency_refs);
        $this->assertCount(1, (array) ($row['source']['media_refs'] ?? []));
        $this->assertSame('template_settings.thumbnail.id', (string) ($media_dependency_refs[0]['payload_path'] ?? ''));
        $this->assertSame(['template_settings', 'thumbnail', 'url'], (array) ($media_dependency_refs[0]['url_path'] ?? []));

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);
        $this->assertSame(1, (int) ($apply['summary']['media']['created_attachments'] ?? 0));
        $this->assertSame(1, (int) ($apply['summary']['entities']['created_posts'] ?? 0));

        $target_template_id = $this->find_bricks_template_by_slug('template-settings-media');
        $this->assertGreaterThan(0, $target_template_id);
        $settings = get_post_meta($target_template_id, '_bricks_template_settings', true);
        $target_attachment_id = (int) ($settings['thumbnail']['id'] ?? 0);
        $this->assertGreaterThan(0, $target_attachment_id);
        $this->assertNotSame($settings_attachment_id, $target_attachment_id);
        $this->assertSame(wp_get_attachment_url($target_attachment_id), (string) ($settings['thumbnail']['url'] ?? ''));
        $this->assertSame('entireWebsite', (string) ($settings['templateConditions'][0]['main'] ?? ''));
        $this->assertInstanceOf(WP_Post::class, get_post($target_attachment_id));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('template-settings-media'));
        $this->assertNull(get_post($target_attachment_id));
    }

    public function test_bricks_templates_remap_preview_post_and_term_references_by_uid(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_page_uid = wp_generate_uuid4();
        $source_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Preview Page',
            'post_name' => 'bricks-preview-page',
        ]);
        $this->assertGreaterThan(0, $source_page_id);
        update_post_meta($source_page_id, 'vf_object_uid', $source_page_uid);

        $source_term_uid = wp_generate_uuid4();
        $source_term = wp_insert_term('Bricks Preview Category', 'category', [
            'slug' => 'bricks-preview-category',
        ]);
        $this->assertNotWPError($source_term);
        $source_term_id = (int) ($source_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $source_term_id);
        update_term_meta($source_term_id, 'vf_object_uid', $source_term_uid);

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Preview Entity References',
            'slug' => 'preview-entity-references',
            'type' => 'content',
            'settings' => [
                'templatePreviewPostId' => $source_page_id,
                'templatePreviewTerm' => 'category::' . $source_term_id,
                'templateConditions' => [
                    [
                        'main' => 'archive',
                    ],
                ],
            ],
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'preview-reference-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Preview entity references',
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        wp_delete_post($source_template_id, true);
        wp_delete_post($source_page_id, true);
        wp_delete_term($source_term_id, 'category');

        $target_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Preview Page Target',
            'post_name' => 'bricks-preview-page',
        ]);
        $this->assertGreaterThan(0, $target_page_id);
        update_post_meta($target_page_id, 'vf_object_uid', $source_page_uid);

        $target_term = wp_insert_term('Bricks Preview Category Target', 'category', [
            'slug' => 'bricks-preview-category',
        ]);
        $this->assertNotWPError($target_term);
        $target_term_id = (int) ($target_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $target_term_id);
        update_term_meta($target_term_id, 'vf_object_uid', $source_term_uid);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Preview Entity References');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(2, $entity_dependency_refs);

        $refs_by_kind = [];
        foreach ($entity_dependency_refs as $ref) {
            $refs_by_kind[(string) ($ref['entity_kind'] ?? '')] = $ref;
        }
        $this->assertSame($source_page_uid, (string) ($refs_by_kind['post']['entity_uid'] ?? ''));
        $this->assertSame('page', (string) ($refs_by_kind['post']['object_subtype'] ?? ''));
        $this->assertSame('bricks-preview-page', (string) ($refs_by_kind['post']['object_slug'] ?? ''));
        $this->assertSame('template_settings.templatePreviewPostId', (string) ($refs_by_kind['post']['payload_path'] ?? ''));
        $this->assertSame($source_term_uid, (string) ($refs_by_kind['term']['entity_uid'] ?? ''));
        $this->assertSame('category', (string) ($refs_by_kind['term']['object_subtype'] ?? ''));
        $this->assertSame('bricks-preview-category', (string) ($refs_by_kind['term']['object_slug'] ?? ''));
        $this->assertSame('template_settings.templatePreviewTerm', (string) ($refs_by_kind['term']['payload_path'] ?? ''));

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);
        $this->assertSame(1, (int) ($apply['summary']['entities']['created_posts'] ?? 0));

        $target_template_id = $this->find_bricks_template_by_slug('preview-entity-references');
        $this->assertGreaterThan(0, $target_template_id);
        $settings = get_post_meta($target_template_id, '_bricks_template_settings', true);
        $this->assertSame($target_page_id, (int) ($settings['templatePreviewPostId'] ?? 0));
        $this->assertSame('category::' . $target_term_id, (string) ($settings['templatePreviewTerm'] ?? ''));
        $this->assertSame('archive', (string) ($settings['templateConditions'][0]['main'] ?? ''));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('preview-entity-references'));
        $this->assertInstanceOf(WP_Post::class, get_post($target_page_id));
        $this->assertInstanceOf(WP_Term::class, get_term($target_term_id, 'category'));
    }

    public function test_bricks_templates_remap_preview_post_and_term_references_by_slug_when_uid_missing(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Preview Slug Page',
            'post_name' => 'bricks-preview-slug-page',
        ]);
        $this->assertGreaterThan(0, $source_page_id);
        update_post_meta($source_page_id, 'vf_object_uid', wp_generate_uuid4());

        $source_term = wp_insert_term('Bricks Preview Slug Category', 'category', [
            'slug' => 'bricks-preview-slug-category',
        ]);
        $this->assertNotWPError($source_term);
        $source_term_id = (int) ($source_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $source_term_id);
        update_term_meta($source_term_id, 'vf_object_uid', wp_generate_uuid4());

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Preview Slug References',
            'slug' => 'preview-slug-references',
            'type' => 'content',
            'settings' => [
                'templatePreviewPostId' => (string) $source_page_id,
                'templatePreviewTerm' => 'category::' . $source_term_id,
            ],
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'preview-slug-reference-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Preview slug references',
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);
        wp_delete_post($source_page_id, true);
        wp_delete_term($source_term_id, 'category');

        $target_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Preview Slug Page Target',
            'post_name' => 'bricks-preview-slug-page',
        ]);
        $this->assertGreaterThan(0, $target_page_id);
        update_post_meta($target_page_id, 'vf_object_uid', wp_generate_uuid4());

        $target_term = wp_insert_term('Bricks Preview Slug Category Target', 'category', [
            'slug' => 'bricks-preview-slug-category',
        ]);
        $this->assertNotWPError($target_term);
        $target_term_id = (int) ($target_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $target_term_id);
        update_term_meta($target_term_id, 'vf_object_uid', wp_generate_uuid4());

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Preview Slug References');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(2, $entity_dependency_refs);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);

        $target_template_id = $this->find_bricks_template_by_slug('preview-slug-references');
        $this->assertGreaterThan(0, $target_template_id);
        $settings = get_post_meta($target_template_id, '_bricks_template_settings', true);
        $this->assertSame((string) $target_page_id, (string) ($settings['templatePreviewPostId'] ?? ''));
        $this->assertSame('category::' . $target_term_id, (string) ($settings['templatePreviewTerm'] ?? ''));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('preview-slug-references'));
        $this->assertInstanceOf(WP_Post::class, get_post($target_page_id));
        $this->assertInstanceOf(WP_Term::class, get_term($target_term_id, 'category'));
    }

    public function test_bricks_templates_preserve_unresolved_preview_post_and_term_references(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Unresolved Preview Page',
            'post_name' => 'unresolved-preview-page',
        ]);
        $this->assertGreaterThan(0, $source_page_id);
        update_post_meta($source_page_id, 'vf_object_uid', wp_generate_uuid4());

        $source_term = wp_insert_term('Unresolved Preview Category', 'category', [
            'slug' => 'unresolved-preview-category',
        ]);
        $this->assertNotWPError($source_term);
        $source_term_id = (int) ($source_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $source_term_id);
        update_term_meta($source_term_id, 'vf_object_uid', wp_generate_uuid4());

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Unresolved Preview References',
            'slug' => 'unresolved-preview-references',
            'type' => 'content',
            'settings' => [
                'templatePreviewPostId' => $source_page_id,
                'templatePreviewTerm' => 'category::' . $source_term_id,
            ],
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'unresolved-preview-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Unresolved preview references',
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);
        wp_delete_post($source_page_id, true);
        wp_delete_term($source_term_id, 'category');

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Unresolved Preview References');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(2, $entity_dependency_refs);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);

        $target_template_id = $this->find_bricks_template_by_slug('unresolved-preview-references');
        $this->assertGreaterThan(0, $target_template_id);
        $settings = get_post_meta($target_template_id, '_bricks_template_settings', true);
        $this->assertSame($source_page_id, (int) ($settings['templatePreviewPostId'] ?? 0));
        $this->assertSame('category::' . $source_term_id, (string) ($settings['templatePreviewTerm'] ?? ''));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('unresolved-preview-references'));
    }

    public function test_bricks_templates_remap_query_post_and_term_references_by_uid(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $include_page_uid = wp_generate_uuid4();
        $include_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Query Include Page',
            'post_name' => 'bricks-query-include-page',
        ]);
        $this->assertGreaterThan(0, $include_page_id);
        update_post_meta($include_page_id, 'vf_object_uid', $include_page_uid);

        $exclude_page_uid = wp_generate_uuid4();
        $exclude_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Query Exclude Page',
            'post_name' => 'bricks-query-exclude-page',
        ]);
        $this->assertGreaterThan(0, $exclude_page_id);
        update_post_meta($exclude_page_id, 'vf_object_uid', $exclude_page_uid);

        $include_term_uid = wp_generate_uuid4();
        $include_term = wp_insert_term('Bricks Query Include Category', 'category', [
            'slug' => 'bricks-query-include-category',
        ]);
        $this->assertNotWPError($include_term);
        $include_term_id = (int) ($include_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $include_term_id);
        update_term_meta($include_term_id, 'vf_object_uid', $include_term_uid);

        $exclude_term_uid = wp_generate_uuid4();
        $exclude_term = wp_insert_term('Bricks Query Exclude Category', 'category', [
            'slug' => 'bricks-query-exclude-category',
        ]);
        $this->assertNotWPError($exclude_term);
        $exclude_term_id = (int) ($exclude_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $exclude_term_id);
        update_term_meta($exclude_term_id, 'vf_object_uid', $exclude_term_uid);

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Query Entity References',
            'slug' => 'query-entity-references',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'query-reference-root',
                    'name' => 'container',
                    'settings' => [
                        'hasLoop' => true,
                        'query' => [
                            'objectType' => 'post',
                            'post_type' => 'page',
                            'post__in' => [
                                $include_page_id,
                            ],
                            'post__not_in' => [
                                (string) $exclude_page_id,
                            ],
                            'tax_query' => [
                                'category::' . $include_term_id,
                            ],
                            'tax_query_not' => [
                                'category::' . $exclude_term_id,
                            ],
                            'posts_per_page' => 6,
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);
        wp_delete_post($include_page_id, true);
        wp_delete_post($exclude_page_id, true);
        wp_delete_term($include_term_id, 'category');
        wp_delete_term($exclude_term_id, 'category');

        $target_include_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Query Include Page Target',
            'post_name' => 'bricks-query-include-page',
        ]);
        $this->assertGreaterThan(0, $target_include_page_id);
        update_post_meta($target_include_page_id, 'vf_object_uid', $include_page_uid);

        $target_exclude_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Query Exclude Page Target',
            'post_name' => 'bricks-query-exclude-page',
        ]);
        $this->assertGreaterThan(0, $target_exclude_page_id);
        update_post_meta($target_exclude_page_id, 'vf_object_uid', $exclude_page_uid);

        $target_include_term = wp_insert_term('Bricks Query Include Category Target', 'category', [
            'slug' => 'bricks-query-include-category',
        ]);
        $this->assertNotWPError($target_include_term);
        $target_include_term_id = (int) ($target_include_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $target_include_term_id);
        update_term_meta($target_include_term_id, 'vf_object_uid', $include_term_uid);

        $target_exclude_term = wp_insert_term('Bricks Query Exclude Category Target', 'category', [
            'slug' => 'bricks-query-exclude-category',
        ]);
        $this->assertNotWPError($target_exclude_term);
        $target_exclude_term_id = (int) ($target_exclude_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $target_exclude_term_id);
        update_term_meta($target_exclude_term_id, 'vf_object_uid', $exclude_term_uid);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Query Entity References');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(4, $entity_dependency_refs);

        $refs_by_path = [];
        foreach ($entity_dependency_refs as $ref) {
            $refs_by_path[(string) ($ref['payload_path'] ?? '')] = $ref;
        }

        $this->assertSame($include_page_uid, (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.post__in.0']['entity_uid'] ?? ''));
        $this->assertSame('post__in', (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.post__in.0']['query_ref_kind'] ?? ''));
        $this->assertSame('integer', (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.post__in.0']['source_value_type'] ?? ''));
        $this->assertSame($exclude_page_uid, (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.post__not_in.0']['entity_uid'] ?? ''));
        $this->assertSame('string', (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.post__not_in.0']['source_value_type'] ?? ''));
        $this->assertSame($include_term_uid, (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.tax_query.0']['entity_uid'] ?? ''));
        $this->assertSame('category', (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.tax_query.0']['object_subtype'] ?? ''));
        $this->assertSame($exclude_term_uid, (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.tax_query_not.0']['entity_uid'] ?? ''));
        $this->assertSame('category', (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.query.tax_query_not.0']['object_subtype'] ?? ''));

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);

        $target_template_id = $this->find_bricks_template_by_slug('query-entity-references');
        $this->assertGreaterThan(0, $target_template_id);
        $elements = get_post_meta($target_template_id, '_bricks_page_content_2', true);
        $query = (array) ($elements[0]['settings']['query'] ?? []);
        $this->assertSame($target_include_page_id, (int) ($query['post__in'][0] ?? 0));
        $this->assertSame((string) $target_exclude_page_id, (string) ($query['post__not_in'][0] ?? ''));
        $this->assertIsString($query['post__not_in'][0] ?? null);
        $this->assertSame('category::' . $target_include_term_id, (string) ($query['tax_query'][0] ?? ''));
        $this->assertSame('category::' . $target_exclude_term_id, (string) ($query['tax_query_not'][0] ?? ''));
        $this->assertSame(6, (int) ($query['posts_per_page'] ?? 0));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('query-entity-references'));
        $this->assertInstanceOf(WP_Post::class, get_post($target_include_page_id));
        $this->assertInstanceOf(WP_Post::class, get_post($target_exclude_page_id));
        $this->assertInstanceOf(WP_Term::class, get_term($target_include_term_id, 'category'));
        $this->assertInstanceOf(WP_Term::class, get_term($target_exclude_term_id, 'category'));
    }

    public function test_bricks_templates_preserve_unresolved_query_post_and_term_references(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Unresolved Query Page',
            'post_name' => 'unresolved-query-page',
        ]);
        $this->assertGreaterThan(0, $source_page_id);
        update_post_meta($source_page_id, 'vf_object_uid', wp_generate_uuid4());

        $source_term = wp_insert_term('Unresolved Query Category', 'category', [
            'slug' => 'unresolved-query-category',
        ]);
        $this->assertNotWPError($source_term);
        $source_term_id = (int) ($source_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $source_term_id);
        update_term_meta($source_term_id, 'vf_object_uid', wp_generate_uuid4());

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Unresolved Query References',
            'slug' => 'unresolved-query-references',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'unresolved-query-root',
                    'name' => 'container',
                    'settings' => [
                        'hasLoop' => true,
                        'query' => [
                            'objectType' => 'post',
                            'post_type' => 'page',
                            'post__in' => [
                                $source_page_id,
                            ],
                            'tax_query' => [
                                'category::' . $source_term_id,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);
        wp_delete_post($source_page_id, true);
        wp_delete_term($source_term_id, 'category');

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Unresolved Query References');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(2, $entity_dependency_refs);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);

        $target_template_id = $this->find_bricks_template_by_slug('unresolved-query-references');
        $this->assertGreaterThan(0, $target_template_id);
        $elements = get_post_meta($target_template_id, '_bricks_page_content_2', true);
        $query = (array) ($elements[0]['settings']['query'] ?? []);
        $this->assertSame($source_page_id, (int) ($query['post__in'][0] ?? 0));
        $this->assertSame('category::' . $source_term_id, (string) ($query['tax_query'][0] ?? ''));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('unresolved-query-references'));
    }

    public function test_bricks_templates_warn_about_unmapped_query_reference_shapes(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Unmapped Query Reference Shapes',
            'slug' => 'unmapped-query-reference-shapes',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'unmapped-query-root',
                    'name' => 'container',
                    'settings' => [
                        'hasLoop' => true,
                        'query' => [
                            'objectType' => 'post',
                            'post_type' => 'page',
                            'post__in' => '123,456',
                            'post__not_in' => [
                                '{post_meta:related_posts}',
                            ],
                            'tax_query' => [
                                'category::999999',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Unmapped Query Reference Shapes');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(0, $entity_dependency_refs);

        $warnings = (array) ($row['source']['warnings'] ?? []);
        $this->assertContains('Template contains Bricks query post reference at areas._bricks_page_content_2.0.settings.query.post__in that could not be mapped safely; source value will be preserved.', $warnings);
        $this->assertContains('Template contains Bricks query post reference at areas._bricks_page_content_2.0.settings.query.post__not_in.0 that could not be mapped safely; source value will be preserved.', $warnings);
        $this->assertContains('Template contains Bricks query term reference at areas._bricks_page_content_2.0.settings.query.tax_query.0 that could not be mapped safely; source value will be preserved.', $warnings);
    }

    public function test_bricks_templates_warn_about_unmapped_link_reference_shapes(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Unmapped Link Reference Shapes',
            'slug' => 'unmapped-link-reference-shapes',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'unmapped-link-post-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Unmapped internal link',
                        'link' => [
                            'type' => 'internal',
                            'postId' => '123,456',
                        ],
                    ],
                ],
                [
                    'id' => 'unmapped-link-term-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Unmapped taxonomy link',
                        'link' => [
                            'type' => 'taxonomy',
                            'term' => 'category::999999',
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Unmapped Link Reference Shapes');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(0, $entity_dependency_refs);

        $warnings = (array) ($row['source']['warnings'] ?? []);
        $this->assertContains('Template contains Bricks internal link reference at areas._bricks_page_content_2.0.settings.link.postId that could not be mapped safely; source value will be preserved.', $warnings);
        $this->assertContains('Template contains Bricks taxonomy link reference at areas._bricks_page_content_2.1.settings.link.term that could not be mapped safely; source value will be preserved.', $warnings);
    }

    public function test_bricks_templates_review_payload_includes_compact_reference_summary_counts(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $linked_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Summary Linked Page',
            'post_name' => 'summary-linked-page',
        ]);
        $this->assertGreaterThan(0, $linked_page_id);

        $linked_term = wp_insert_term('Summary Linked Category', 'category', [
            'slug' => 'summary-linked-category',
        ]);
        $this->assertNotWPError($linked_term);
        $linked_term_id = (int) ($linked_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $linked_term_id);

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Compact Reference Summary',
            'slug' => 'compact-reference-summary',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'summary-query-root',
                    'name' => 'container',
                    'settings' => [
                        'hasLoop' => true,
                        'query' => [
                            'objectType' => 'post',
                            'post_type' => 'page',
                            'post__in' => [$linked_page_id],
                            'tax_query' => ['category::' . $linked_term_id],
                        ],
                    ],
                ],
                [
                    'id' => 'summary-link-post-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Linked page',
                        'link' => [
                            'type' => 'internal',
                            'postId' => $linked_page_id,
                        ],
                    ],
                ],
                [
                    'id' => 'summary-link-term-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Linked term',
                        'link' => [
                            'type' => 'taxonomy',
                            'term' => 'category::' . $linked_term_id,
                        ],
                    ],
                ],
                [
                    'id' => 'summary-dynamic-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Login {site_login:' . $linked_page_id . '}',
                    ],
                ],
                [
                    'id' => 'summary-preserved-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Preserved internal link',
                        'link' => [
                            'type' => 'internal',
                            'postId' => '123,456',
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Compact Reference Summary');
        $this->assertNotNull($row);
        $summary = (array) ($row['references']['template_reference_summary'] ?? []);

        $this->assertSame(6, (int) ($summary['total_refs'] ?? 0));
        $this->assertSame(5, (int) ($summary['safe_refs'] ?? 0));
        $this->assertSame(5, (int) ($summary['entity_refs'] ?? 0));
        $this->assertSame(3, (int) ($summary['post_refs'] ?? 0));
        $this->assertSame(2, (int) ($summary['term_refs'] ?? 0));
        $this->assertSame(2, (int) ($summary['query_refs'] ?? 0));
        $this->assertSame(2, (int) ($summary['link_refs'] ?? 0));
        $this->assertSame(1, (int) ($summary['dynamic_data_refs'] ?? 0));
        $this->assertSame(1, (int) ($summary['preserved_refs'] ?? 0));
        $this->assertSame(0, (int) ($summary['blocked_refs'] ?? 0));
    }

    public function test_bricks_template_apply_receipt_records_compact_reference_summary_without_extra_decisions(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $linked_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Receipt Linked Page',
            'post_name' => 'receipt-linked-page',
        ]);
        $this->assertGreaterThan(0, $linked_page_id);

        $linked_term = wp_insert_term('Receipt Linked Category', 'category', [
            'slug' => 'receipt-linked-category',
        ]);
        $this->assertNotWPError($linked_term);
        $linked_term_id = (int) ($linked_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $linked_term_id);

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Apply Reference Receipt',
            'slug' => 'apply-reference-receipt',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'receipt-query-root',
                    'name' => 'container',
                    'settings' => [
                        'hasLoop' => true,
                        'query' => [
                            'objectType' => 'post',
                            'post_type' => 'page',
                            'post__in' => [$linked_page_id],
                            'tax_query' => ['category::' . $linked_term_id],
                        ],
                    ],
                ],
                [
                    'id' => 'receipt-link-post-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Linked page',
                        'link' => [
                            'type' => 'internal',
                            'postId' => $linked_page_id,
                        ],
                    ],
                ],
                [
                    'id' => 'receipt-link-term-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Linked term',
                        'link' => [
                            'type' => 'taxonomy',
                            'term' => 'category::' . $linked_term_id,
                        ],
                    ],
                ],
                [
                    'id' => 'receipt-dynamic-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Login {site_login:' . $linked_page_id . '}',
                    ],
                ],
                [
                    'id' => 'receipt-preserved-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Preserved internal link',
                        'link' => [
                            'type' => 'internal',
                            'postId' => '123,456',
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);

        $receipt = (array) ($apply['summary']['reference_receipt'] ?? []);
        $this->assertSame(1, (int) ($receipt['template_rows'] ?? 0));
        $this->assertSame(5, (int) ($receipt['references']['safe_refs'] ?? 0));
        $this->assertSame(5, (int) ($receipt['references']['remapped_refs'] ?? 0));
        $this->assertSame(2, (int) ($receipt['references']['query_refs'] ?? 0));
        $this->assertSame(2, (int) ($receipt['references']['link_refs'] ?? 0));
        $this->assertSame(1, (int) ($receipt['references']['dynamic_data_refs'] ?? 0));
        $this->assertSame(1, (int) ($receipt['references']['preserved_refs'] ?? 0));
        $this->assertSame(1, (int) ($receipt['entities']['created_posts'] ?? 0));
        $this->assertSame(1, (int) ($receipt['entities']['template_post_maps'] ?? 0));

        $target_template_id = $this->find_bricks_template_by_slug('apply-reference-receipt');
        $this->assertGreaterThan(0, $target_template_id);
        $this->assertSame($target_template_id, (int) ($receipt['maps']['template_posts'][(string) $source_template_id] ?? 0));

        $loaded_session = DBVC_Bricks_Portability_Package_Service::load_session((string) $session['session_id']);
        $this->assertNotWPError($loaded_session);
        $session_view = DBVC_Bricks_Portability_Package_Service::build_session_view((array) $loaded_session);
        $this->assertSame(5, (int) ($session_view['approval']['reference_receipt']['references']['remapped_refs'] ?? 0));
        $this->assertSame(1, (int) ($session_view['approval']['reference_receipt']['references']['preserved_refs'] ?? 0));

        $backup = DBVC_Bricks_Portability_Backup_Service::load_backup((string) $apply['backup_id']);
        $this->assertNotWPError($backup);
        $this->assertSame(5, (int) ($backup['reference_receipt']['references']['remapped_refs'] ?? 0));
        $this->assertSame(1, (int) ($backup['reference_receipt']['references']['preserved_refs'] ?? 0));

        $recent_backups = DBVC_Bricks_Portability_Backup_Service::list_recent_backups(10);
        $recent_backup = null;
        foreach ($recent_backups as $record) {
            if (is_array($record) && (string) ($record['backup_id'] ?? '') === (string) $apply['backup_id']) {
                $recent_backup = $record;
                break;
            }
        }
        $this->assertNotNull($recent_backup);
        $this->assertSame(5, (int) ($recent_backup['reference_receipt']['references']['remapped_refs'] ?? 0));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(5, (int) ($rollback['reference_receipt']['references']['remapped_refs'] ?? 0));

        $rolled_back_session = DBVC_Bricks_Portability_Package_Service::load_session((string) $session['session_id']);
        $this->assertNotWPError($rolled_back_session);
        $rolled_back_view = DBVC_Bricks_Portability_Package_Service::build_session_view((array) $rolled_back_session);
        $this->assertSame(5, (int) ($rolled_back_view['rollback']['reference_receipt']['references']['remapped_refs'] ?? 0));
        $this->assertSame(0, $this->find_bricks_template_by_slug('apply-reference-receipt'));
    }

    public function test_bricks_templates_remap_link_post_and_term_controls_by_uid(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $linked_page_uid = wp_generate_uuid4();
        $linked_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Linked Page',
            'post_name' => 'bricks-linked-page',
        ]);
        $this->assertGreaterThan(0, $linked_page_id);
        update_post_meta($linked_page_id, 'vf_object_uid', $linked_page_uid);

        $linked_term_uid = wp_generate_uuid4();
        $linked_term = wp_insert_term('Bricks Linked Category', 'category', [
            'slug' => 'bricks-linked-category',
        ]);
        $this->assertNotWPError($linked_term);
        $linked_term_id = (int) ($linked_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $linked_term_id);
        update_term_meta($linked_term_id, 'vf_object_uid', $linked_term_uid);

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Link Control References',
            'slug' => 'link-control-references',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'link-post-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Linked page',
                        'link' => [
                            'type' => 'internal',
                            'postId' => $linked_page_id,
                        ],
                    ],
                ],
                [
                    'id' => 'link-term-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Linked term',
                        'link' => [
                            'type' => 'taxonomy',
                            'term' => 'category::' . $linked_term_id,
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);
        wp_delete_post($linked_page_id, true);
        wp_delete_term($linked_term_id, 'category');

        $target_linked_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Linked Page Target',
            'post_name' => 'bricks-linked-page',
        ]);
        $this->assertGreaterThan(0, $target_linked_page_id);
        update_post_meta($target_linked_page_id, 'vf_object_uid', $linked_page_uid);

        $target_linked_term = wp_insert_term('Bricks Linked Category Target', 'category', [
            'slug' => 'bricks-linked-category',
        ]);
        $this->assertNotWPError($target_linked_term);
        $target_linked_term_id = (int) ($target_linked_term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $target_linked_term_id);
        update_term_meta($target_linked_term_id, 'vf_object_uid', $linked_term_uid);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Link Control References');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(2, $entity_dependency_refs);

        $refs_by_path = [];
        foreach ($entity_dependency_refs as $ref) {
            $refs_by_path[(string) ($ref['payload_path'] ?? '')] = $ref;
        }
        $this->assertSame($linked_page_uid, (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.link.postId']['entity_uid'] ?? ''));
        $this->assertSame('internal', (string) ($refs_by_path['areas._bricks_page_content_2.0.settings.link.postId']['link_ref_kind'] ?? ''));
        $this->assertSame($linked_term_uid, (string) ($refs_by_path['areas._bricks_page_content_2.1.settings.link.term']['entity_uid'] ?? ''));
        $this->assertSame('taxonomy', (string) ($refs_by_path['areas._bricks_page_content_2.1.settings.link.term']['link_ref_kind'] ?? ''));

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);

        $target_template_id = $this->find_bricks_template_by_slug('link-control-references');
        $this->assertGreaterThan(0, $target_template_id);
        $elements = get_post_meta($target_template_id, '_bricks_page_content_2', true);
        $this->assertSame($target_linked_page_id, (int) ($elements[0]['settings']['link']['postId'] ?? 0));
        $this->assertSame('category::' . $target_linked_term_id, (string) ($elements[1]['settings']['link']['term'] ?? ''));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('link-control-references'));
        $this->assertInstanceOf(WP_Post::class, get_post($target_linked_page_id));
        $this->assertInstanceOf(WP_Term::class, get_term($target_linked_term_id, 'category'));
    }

    public function test_bricks_templates_remap_dynamic_data_post_tokens_by_uid(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $login_page_uid = wp_generate_uuid4();
        $login_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Dynamic Login Redirect',
            'post_name' => 'bricks-dynamic-login-redirect',
        ]);
        $this->assertGreaterThan(0, $login_page_id);
        update_post_meta($login_page_id, 'vf_object_uid', $login_page_uid);

        $logout_page_uid = wp_generate_uuid4();
        $logout_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Dynamic Logout Redirect',
            'post_name' => 'bricks-dynamic-logout-redirect',
        ]);
        $this->assertGreaterThan(0, $logout_page_id);
        update_post_meta($logout_page_id, 'vf_object_uid', $logout_page_uid);

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Dynamic Data Token References',
            'slug' => 'dynamic-data-token-references',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'dynamic-token-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => sprintf(
                            'Login {site_login:%d}, logout {site_logout:%d:raw}, keep runtime {post_id}, keep unknown {site_login:not_numeric}.',
                            $login_page_id,
                            $logout_page_id
                        ),
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($source_template_id, true);
        wp_delete_post($login_page_id, true);
        wp_delete_post($logout_page_id, true);

        $target_login_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Dynamic Login Redirect Target',
            'post_name' => 'bricks-dynamic-login-redirect',
        ]);
        $this->assertGreaterThan(0, $target_login_page_id);
        update_post_meta($target_login_page_id, 'vf_object_uid', $login_page_uid);

        $target_logout_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Bricks Dynamic Logout Redirect Target',
            'post_name' => 'bricks-dynamic-logout-redirect',
        ]);
        $this->assertGreaterThan(0, $target_logout_page_id);
        update_post_meta($target_logout_page_id, 'vf_object_uid', $logout_page_uid);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_object_label((array) $session['rows'], 'Dynamic Data Token References');
        $this->assertNotNull($row);
        $entity_dependency_refs = array_values(array_filter((array) ($row['source']['dependency_refs'] ?? []), static function ($ref): bool {
            return is_array($ref) && (string) ($ref['ref_type'] ?? '') === 'post_or_term';
        }));
        $this->assertCount(2, $entity_dependency_refs);

        $refs_by_token = [];
        foreach ($entity_dependency_refs as $ref) {
            $refs_by_token[(string) ($ref['dynamic_token_name'] ?? '')] = $ref;
        }
        $this->assertSame($login_page_uid, (string) ($refs_by_token['site_login']['entity_uid'] ?? ''));
        $this->assertSame('dynamic_data_token', (string) ($refs_by_token['site_login']['dynamic_ref_kind'] ?? ''));
        $this->assertSame('areas._bricks_page_content_2.0.settings.content', (string) ($refs_by_token['site_login']['payload_path'] ?? ''));
        $this->assertSame($logout_page_uid, (string) ($refs_by_token['site_logout']['entity_uid'] ?? ''));
        $this->assertSame('dynamic_data_token', (string) ($refs_by_token['site_logout']['dynamic_ref_kind'] ?? ''));

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);

        $target_template_id = $this->find_bricks_template_by_slug('dynamic-data-token-references');
        $this->assertGreaterThan(0, $target_template_id);
        $elements = get_post_meta($target_template_id, '_bricks_page_content_2', true);
        $content = (string) ($elements[0]['settings']['content'] ?? '');
        $this->assertStringContainsString('{site_login:' . $target_login_page_id . '}', $content);
        $this->assertStringContainsString('{site_logout:' . $target_logout_page_id . ':raw}', $content);
        $this->assertStringContainsString('{post_id}', $content);
        $this->assertStringContainsString('{site_login:not_numeric}', $content);
        $this->assertStringNotContainsString('{site_login:' . $login_page_id . '}', $content);
        $this->assertStringNotContainsString('{site_logout:' . $logout_page_id . ':raw}', $content);

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('dynamic-data-token-references'));
        $this->assertInstanceOf(WP_Post::class, get_post($target_login_page_id));
        $this->assertInstanceOf(WP_Post::class, get_post($target_logout_page_id));
    }

    public function test_bricks_templates_remap_nested_template_references(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $child_source_id = $this->create_bricks_template_fixture([
            'title' => 'Z Child Template',
            'slug' => 'z-child-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'child-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Nested child',
                    ],
                ],
            ],
        ]);
        $parent_source_id = $this->create_bricks_template_fixture([
            'title' => 'A Parent Template',
            'slug' => 'a-parent-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'parent-root',
                    'name' => 'template',
                    'settings' => [
                        'templateId' => $child_source_id,
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($parent_source_id, true);
        wp_delete_post($child_source_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $parent_row = $this->find_row_by_object_label((array) $session['rows'], 'A Parent Template');
        $this->assertNotNull($parent_row);
        $this->assertSame('nested_template', (string) $parent_row['source']['dependency_refs'][0]['ref_type']);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);
        $this->assertSame(2, (int) ($apply['summary']['entities']['created_posts'] ?? 0));

        $target_child_id = $this->find_bricks_template_by_slug('z-child-template');
        $target_parent_id = $this->find_bricks_template_by_slug('a-parent-template');
        $this->assertGreaterThan(0, $target_child_id);
        $this->assertGreaterThan(0, $target_parent_id);
        $parent_elements = get_post_meta($target_parent_id, '_bricks_page_content_2', true);
        $this->assertSame($target_child_id, (int) ($parent_elements[0]['settings']['templateId'] ?? 0));
        $this->assertNotSame($child_source_id, (int) ($parent_elements[0]['settings']['templateId'] ?? 0));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame(0, $this->find_bricks_template_by_slug('z-child-template'));
        $this->assertSame(0, $this->find_bricks_template_by_slug('a-parent-template'));
    }

    public function test_bricks_template_apply_blocks_unselected_nested_template_dependency(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $child_source_id = $this->create_bricks_template_fixture([
            'title' => 'Unselected Child Template',
            'slug' => 'unselected-child-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'unselected-child-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Unselected nested child',
                    ],
                ],
            ],
        ]);
        $parent_source_id = $this->create_bricks_template_fixture([
            'title' => 'Unselected Parent Template',
            'slug' => 'unselected-parent-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'unselected-parent-root',
                    'name' => 'template',
                    'settings' => [
                        'templateId' => $child_source_id,
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($parent_source_id, true);
        wp_delete_post($child_source_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $parent_row = $this->find_row_by_object_label((array) $session['rows'], 'Unselected Parent Template');
        $child_row = $this->find_row_by_object_label((array) $session['rows'], 'Unselected Child Template');
        $this->assertNotNull($parent_row);
        $this->assertNotNull($child_row);
        $this->assertSame('nested_template', (string) ($parent_row['source']['dependency_refs'][0]['ref_type'] ?? ''));
        $this->assertSame($child_source_id, (int) ($parent_row['source']['dependency_refs'][0]['source_id'] ?? 0));

        $apply = DBVC_Bricks_Portability_Apply_Service::apply_session((string) $session['session_id'], [
            (string) $parent_row['row_id'] => 'add_incoming',
            (string) $child_row['row_id'] => 'keep_current',
        ]);

        $this->assertWPError($apply);
        $this->assertSame('dbvc_bricks_portability_template_nested_unresolved', $apply->get_error_code());
        $this->assertSame(0, $this->find_bricks_template_by_slug('unselected-parent-template'));
        $this->assertSame(0, $this->find_bricks_template_by_slug('unselected-child-template'));
    }

    public function test_bricks_template_apply_blocks_nested_template_dependency_cycle(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $first_source_id = $this->create_bricks_template_fixture([
            'title' => 'Cycle First Template',
            'slug' => 'cycle-first-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'cycle-first-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Cycle first placeholder',
                    ],
                ],
            ],
        ]);
        $second_source_id = $this->create_bricks_template_fixture([
            'title' => 'Cycle Second Template',
            'slug' => 'cycle-second-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'cycle-second-root',
                    'name' => 'template',
                    'settings' => [
                        'templateId' => $first_source_id,
                    ],
                ],
            ],
        ]);
        update_post_meta($first_source_id, '_bricks_page_content_2', [
            [
                'id' => 'cycle-first-root',
                'name' => 'template',
                'settings' => [
                    'templateId' => $second_source_id,
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);

        wp_delete_post($first_source_id, true);
        wp_delete_post($second_source_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $first_row = $this->find_row_by_object_label((array) $session['rows'], 'Cycle First Template');
        $second_row = $this->find_row_by_object_label((array) $session['rows'], 'Cycle Second Template');
        $this->assertNotNull($first_row);
        $this->assertNotNull($second_row);
        $this->assertSame($second_source_id, (int) ($first_row['source']['dependency_refs'][0]['source_id'] ?? 0));
        $this->assertSame($first_source_id, (int) ($second_row['source']['dependency_refs'][0]['source_id'] ?? 0));

        $apply = $this->apply_all_addable_rows($session);

        $this->assertWPError($apply);
        $this->assertSame('dbvc_bricks_portability_template_dependency_unresolved', $apply->get_error_code());
        $this->assertSame(0, $this->find_bricks_template_by_slug('cycle-first-template'));
        $this->assertSame(0, $this->find_bricks_template_by_slug('cycle-second-template'));
    }

    public function test_bricks_template_add_blocks_stale_slug_collision_after_review(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_template_id = $this->create_bricks_template_fixture([
            'title' => 'Collision Template',
            'slug' => 'collision-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'collision-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Incoming collision',
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);
        wp_delete_post($source_template_id, true);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $target_template_id = $this->create_bricks_template_fixture([
            'title' => 'Target Collision',
            'slug' => 'collision-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'target-collision-root',
                    'name' => 'section',
                    'settings' => [
                        'content' => 'Target collision',
                    ],
                ],
            ],
        ]);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertWPError($apply);
        $this->assertSame('dbvc_bricks_portability_target_changed', $apply->get_error_code());
        $this->assertSame($target_template_id, $this->find_bricks_template_by_slug('collision-template'));
        $this->assertSame('Target collision', (string) get_post_meta($target_template_id, '_bricks_page_content_2', true)[0]['settings']['content']);
    }

    public function test_bricks_template_import_rejects_unsafe_dependency_descriptor_paths(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $source_attachment_id = $this->create_test_attachment('dbvc-template-unsafe-path.jpg', 'template-image-binary', 'image/jpeg');
        $source_url = wp_get_attachment_url($source_attachment_id);
        $this->create_bricks_template_fixture([
            'title' => 'Unsafe Descriptor Template',
            'slug' => 'unsafe-descriptor-template',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'unsafe-descriptor-root',
                    'name' => 'image',
                    'settings' => [
                        'image' => [
                            'id' => $source_attachment_id,
                            'url' => $source_url,
                        ],
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        $this->mutate_zip_json_payload((string) $export['zip_path'], 'domains/bricks-templates.json', static function (array $payload): array {
            $payload['objects'][0]['dependency_refs'][0]['path'] = ['areas', '..', '_bricks_page_content_2', 0, 'settings', 'image', 'id'];
            $payload['objects'][0]['dependency_refs'][0]['payload_path'] = 'areas..._bricks_page_content_2.0.settings.image.id';
            return $payload;
        });

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);

        $this->assertWPError($session);
        $this->assertSame('dbvc_bricks_portability_template_dependency_invalid', $session->get_error_code());
    }

    public function test_bricks_template_import_rejects_malformed_dynamic_token_descriptors(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks template portability package tests.');
        }

        $login_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Malformed Dynamic Token Redirect',
            'post_name' => 'malformed-dynamic-token-redirect',
        ]);
        $this->assertGreaterThan(0, $login_page_id);

        $this->create_bricks_template_fixture([
            'title' => 'Malformed Dynamic Token Descriptor',
            'slug' => 'malformed-dynamic-token-descriptor',
            'type' => 'content',
            'area_key' => '_bricks_page_content_2',
            'elements' => [
                [
                    'id' => 'malformed-dynamic-token-root',
                    'name' => 'text-basic',
                    'settings' => [
                        'content' => 'Login {site_login:' . $login_page_id . '}',
                    ],
                ],
            ],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['bricks_templates']);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        $this->mutate_zip_json_payload((string) $export['zip_path'], 'domains/bricks-templates.json', static function (array $payload): array {
            $payload['objects'][0]['dependency_refs'][0]['dynamic_token_name'] = 'site_url';
            return $payload;
        });

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);

        $this->assertWPError($session);
        $this->assertSame('dbvc_bricks_portability_template_dependency_invalid', $session->get_error_code());
    }

    public function test_media_apply_rejects_missing_packaged_font_media_without_creating_posts(): void
    {
        $fixture = $this->create_imported_custom_font_session();
        $session = $fixture['session'];
        $ref = $this->get_first_session_media_ref($session, 'custom_fonts');
        $path = wp_normalize_path(trailingslashit((string) $session['extract_dir']) . (string) $ref['package_path']);
        $this->assertFileExists($path);
        $this->assertTrue(@unlink($path));

        $apply = $this->apply_all_addable_rows($session);
        $this->assertWPError($apply);
        $this->assertSame('dbvc_bricks_portability_media_file_missing', $apply->get_error_code());
        $this->assertSame(0, $this->find_bricks_font_post_by_title('Inter Tight'));
    }

    public function test_media_apply_rejects_checksum_mismatch_without_creating_posts(): void
    {
        $fixture = $this->create_imported_custom_font_session();
        $session = $fixture['session'];
        $ref = $this->get_first_session_media_ref($session, 'custom_fonts');
        $path = wp_normalize_path(trailingslashit((string) $session['extract_dir']) . (string) $ref['package_path']);
        $this->assertFileExists($path);
        $this->assertNotFalse(file_put_contents($path, 'tampered-font-binary'));

        $apply = $this->apply_all_addable_rows($session);
        $this->assertWPError($apply);
        $this->assertSame('dbvc_bricks_portability_media_checksum_mismatch', $apply->get_error_code());
        $this->assertSame(0, $this->find_bricks_font_post_by_title('Inter Tight'));
    }

    public function test_media_apply_rejects_invalid_media_reference_paths_without_creating_posts(): void
    {
        $fixture = $this->create_imported_custom_font_session();
        $session = $this->mutate_first_session_media_ref($fixture['session'], 'custom_fonts', static function (array $ref): array {
            $ref['package_path'] = '../outside.woff2';
            return $ref;
        });
        $this->persist_test_session($session);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertWPError($apply);
        $this->assertSame('dbvc_bricks_portability_media_ref_invalid', $apply->get_error_code());
        $this->assertSame(0, $this->find_bricks_font_post_by_title('Inter Tight'));
    }

    public function test_media_apply_rejects_unsafe_svg_without_writing_icon_options(): void
    {
        $fixture = $this->create_imported_icon_collection_session();
        $session = $fixture['session'];
        $ref = $this->get_first_session_media_ref($session, 'icon_collections');
        $path = wp_normalize_path(trailingslashit((string) $session['extract_dir']) . (string) $ref['package_path']);
        $unsafe_svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->assertFileExists($path);
        $this->assertNotFalse(file_put_contents($path, $unsafe_svg));

        $session = $this->mutate_first_session_media_ref($session, 'icon_collections', static function (array $ref) use ($unsafe_svg): array {
            $ref['checksum'] = 'sha256:' . hash('sha256', $unsafe_svg);
            return $ref;
        });
        $this->persist_test_session($session);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertWPError($apply);
        $this->assertSame('dbvc_bricks_portability_svg_unsafe', $apply->get_error_code());
        $this->assertSame([], get_option('bricks_icon_sets', []));
        $this->assertSame([], get_option('bricks_custom_icons', []));
    }

    public function test_media_apply_respects_size_limit_without_creating_posts(): void
    {
        $fixture = $this->create_imported_custom_font_session();
        $session = $fixture['session'];
        $limit_filter = static function (): int {
            return 4;
        };
        add_filter('dbvc_bricks_portability_max_media_file_bytes', $limit_filter);
        try {
            $apply = $this->apply_all_addable_rows($session);
        } finally {
            remove_filter('dbvc_bricks_portability_max_media_file_bytes', $limit_filter);
        }

        $this->assertWPError($apply);
        $this->assertSame('dbvc_bricks_portability_media_file_too_large', $apply->get_error_code());
        $this->assertSame(0, $this->find_bricks_font_post_by_title('Inter Tight'));
    }

    public function test_media_apply_reuses_existing_attachment_by_checksum(): void
    {
        $fixture = $this->create_imported_custom_font_session();
        $session = $fixture['session'];
        $ref = $this->get_first_session_media_ref($session, 'custom_fonts');
        $existing_attachment_id = $this->create_test_attachment('dbvc-existing-font.woff2', 'test-font-binary', 'font/woff2');
        update_post_meta($existing_attachment_id, '_dbvc_bricks_portability_checksum', (string) $ref['checksum']);

        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);
        $this->assertTrue((bool) ($apply['applied'] ?? false));
        $this->assertSame(1, (int) $apply['summary']['media']['created_posts']);
        $this->assertSame(0, (int) $apply['summary']['media']['created_attachments']);
        $this->assertSame(1, (int) $apply['summary']['media']['reused_attachments']);

        $target_font_post_id = $this->find_bricks_font_post_by_title('Inter Tight');
        $this->assertGreaterThan(0, $target_font_post_id);
        $target_font_faces = get_post_meta($target_font_post_id, 'bricks_font_faces', true);
        $this->assertSame($existing_attachment_id, (int) ($target_font_faces[0]['woff2'] ?? 0));

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertNull(get_post($target_font_post_id));
        $this->assertInstanceOf(WP_Post::class, get_post($existing_attachment_id));
        wp_delete_attachment($existing_attachment_id, true);
    }

    public function test_custom_font_references_remap_across_settings_theme_styles_and_components(): void
    {
        $fixture = $this->create_imported_custom_font_session(
            ['custom_fonts', 'settings', 'theme_styles', 'components'],
            static function (int $font_post_id): void {
                update_option('bricks_global_settings', [
                    'typography' => [
                        'body' => [
                            'font-family' => 'custom_font_' . $font_post_id,
                        ],
                    ],
                ]);
                update_option('bricks_theme_styles', [
                    [
                        'id' => 'theme-style-font',
                        'name' => 'Imported Theme Style',
                        'settings' => [
                            'typography' => [
                                'font-family' => 'custom_font_' . $font_post_id,
                            ],
                        ],
                    ],
                ]);
                update_option('bricks_components', [
                    [
                        'id' => 'component-font',
                        'name' => 'Imported Component',
                        'settings' => [
                            'typography' => [
                                'font-family' => 'custom_font_' . $font_post_id,
                            ],
                        ],
                        'elements' => [
                            [
                                'id' => 'component-font-text',
                                'label' => 'Imported Component Text',
                                'settings' => [
                                    'typography' => [
                                        'font-family' => 'custom_font_' . $font_post_id,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);
            }
        );

        $session = $fixture['session'];
        $apply = $this->apply_all_addable_rows($session);
        $this->assertNotWPError($apply);
        $this->assertTrue((bool) ($apply['applied'] ?? false));

        $target_font_post_id = $this->find_bricks_font_post_by_title('Inter Tight');
        $this->assertGreaterThan(0, $target_font_post_id);
        $expected = 'custom_font_' . $target_font_post_id;
        $settings = get_option('bricks_global_settings', []);
        $theme_styles = get_option('bricks_theme_styles', []);
        $components = get_option('bricks_components', []);
        $this->assertSame($expected, (string) $settings['typography']['body']['font-family']);
        $this->assertSame($expected, (string) $theme_styles[0]['settings']['typography']['font-family']);
        $this->assertSame($expected, (string) $components[0]['settings']['typography']['font-family']);
        $this->assertSame($expected, (string) $components[0]['elements'][0]['settings']['typography']['font-family']);

        $rollback = DBVC_Bricks_Portability_Apply_Service::rollback_backup((string) $apply['backup_id']);
        $this->assertNotWPError($rollback);
        $this->assertSame([], get_option('bricks_global_settings', []));
        $this->assertSame([], get_option('bricks_theme_styles', []));
        $this->assertSame([], get_option('bricks_components', []));
        $this->assertNull(get_post($target_font_post_id));
    }

    public function test_component_normalizer_uses_nested_component_label_candidates(): void
    {
        $domain = DBVC_Bricks_Portability_Registry::get_domain('components');
        $this->assertIsArray($domain);

        $normalized = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($domain, [
            'bricks_components' => [
                [
                    'id' => 'component-hero',
                    'elements' => [
                        [
                            'id' => 'hero-root',
                            'label' => 'Homepage Hero',
                            'name' => 'hero-section',
                        ],
                    ],
                    'settings' => [
                        'label' => 'Fallback Hero Label',
                    ],
                ],
            ],
        ]);

        $this->assertSame('Homepage Hero', (string) $normalized['objects'][0]['display_name']);
        $this->assertSame('component-hero', (string) $normalized['objects'][0]['object_id']);
        $this->assertSame('Homepage Hero', (string) $normalized['objects'][0]['match_keys']['name']);
    }

    public function test_css_variable_dependency_warnings_are_categorized(): void
    {
        $classes_domain = DBVC_Bricks_Portability_Registry::get_domain('global_classes');
        $variables_domain = DBVC_Bricks_Portability_Registry::get_domain('global_variables');
        $this->assertIsArray($classes_domain);
        $this->assertIsArray($variables_domain);

        $source_classes = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($classes_domain, [
            'bricks_global_classes' => [
                [
                    'id' => 'cls-toc',
                    'name' => 'toc',
                    'settings' => [
                        'gap' => 'var(--gap-lists)',
                        'fontSize' => 'var(--font-body-s)',
                        'backgroundColor' => 'var(--brand-shell)',
                    ],
                ],
            ],
            'bricks_global_classes_categories' => [],
        ]);
        $source_variables = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($variables_domain, [
            'bricks_global_variables' => [
                ['id' => 'var-gap-lists', 'name' => '--gap-lists', 'value' => '1rem'],
            ],
            'bricks_global_variables_categories' => [],
        ]);
        $target_classes = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($classes_domain, [
            'bricks_global_classes' => [],
            'bricks_global_classes_categories' => [],
        ]);
        $target_variables = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($variables_domain, [
            'bricks_global_variables' => [
                ['id' => 'var-font-body-s', 'name' => '--font-body-s', 'value' => '14px'],
            ],
            'bricks_global_variables_categories' => [],
        ]);

        $review = DBVC_Bricks_Portability_Diff_Engine::build_review_session(
            ['selected_domains' => ['global_classes', 'global_variables']],
            [
                'global_classes' => $source_classes,
                'global_variables' => $source_variables,
            ],
            [
                'global_classes' => $target_classes,
                'global_variables' => $target_variables,
            ]
        );
        $class_row = $this->find_row_by_object_id((array) $review['rows'], 'cls-toc');
        $this->assertNotNull($class_row);
        $this->assertContains('Missing CSS variable on Current Site but supplied by Incoming Package: --gap-lists', (array) $class_row['warnings']);
        $this->assertContains('Missing CSS variable on both Current Site and Incoming Package: --brand-shell', (array) $class_row['warnings']);
        $this->assertSame(['--gap-lists'], (array) $class_row['references']['css_variable_dependencies']['missing_on_current_supplied_by_incoming']);
        $this->assertSame(['--brand-shell'], (array) $class_row['references']['css_variable_dependencies']['missing_on_both']);
        $this->assertSame([], (array) $class_row['references']['css_variable_dependencies']['possibly_external']);

        $review_without_variables = DBVC_Bricks_Portability_Diff_Engine::build_review_session(
            ['selected_domains' => ['global_classes']],
            [
                'global_classes' => $source_classes,
            ],
            [
                'global_classes' => $target_classes,
            ]
        );
        $class_row_without_variables = $this->find_row_by_object_id((array) $review_without_variables['rows'], 'cls-toc');
        $this->assertNotNull($class_row_without_variables);
        $this->assertContains('CSS variable may be external or outside Bricks portability scope: --gap-lists', (array) $class_row_without_variables['warnings']);
        $this->assertContains('CSS variable may be external or outside Bricks portability scope: --brand-shell', (array) $class_row_without_variables['warnings']);
        $this->assertSame(['--brand-shell', '--font-body-s', '--gap-lists'], (array) $class_row_without_variables['references']['css_variable_dependencies']['possibly_external']);
    }

    public function test_category_dependency_warnings_are_categorized_and_linked_to_metadata(): void
    {
        $classes_domain = DBVC_Bricks_Portability_Registry::get_domain('global_classes');
        $this->assertIsArray($classes_domain);

        $source_classes = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($classes_domain, [
            'bricks_global_classes' => [
                [
                    'id' => 'cls-toc',
                    'name' => 'toc',
                    'category' => 'Layout',
                    'settings' => [
                        'fontSize' => '16px',
                    ],
                ],
            ],
            'bricks_global_classes_categories' => [
                ['id' => 'cat-layout', 'name' => 'Layout'],
            ],
        ]);
        $target_classes = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($classes_domain, [
            'bricks_global_classes' => [],
            'bricks_global_classes_categories' => [],
        ]);

        $review = DBVC_Bricks_Portability_Diff_Engine::build_review_session(
            ['selected_domains' => ['global_classes']],
            [
                'global_classes' => $source_classes,
            ],
            [
                'global_classes' => $target_classes,
            ]
        );

        $class_row = $this->find_row_by_object_id((array) $review['rows'], 'cls-toc');
        $this->assertNotNull($class_row);
        $this->assertContains('Missing class category on Current Site but supplied by Incoming Package: Layout', (array) $class_row['warnings']);
        $this->assertSame('bricks_global_classes_categories', (string) $class_row['references']['category_dependencies']['option_name']);
        $this->assertSame(['Layout'], (array) $class_row['references']['category_dependencies']['missing_on_current_supplied_by_incoming']);
        $this->assertSame([], (array) $class_row['references']['category_dependencies']['missing_on_both']);

        $meta_row = $this->find_row_by_object_id((array) $review['rows'], 'bricks_global_classes_categories');
        $this->assertNotNull($meta_row);
        $this->assertSame('meta', (string) $meta_row['row_type']);
    }

    public function test_review_session_draft_decisions_can_be_saved_and_reloaded(): void
    {
        update_option('bricks_color_palette', [
            ['id' => 'pal-primary', 'name' => 'Primary', 'value' => '#ff0000'],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['color_palette']);
        $this->assertNotWPError($export);

        update_option('bricks_color_palette', []);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename($export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_status((array) $session['rows'], 'new_in_source');
        $this->assertNotNull($row);
        $row_id = (string) $row['row_id'];
        $this->assertNotSame('', $row_id);

        $saved = DBVC_Bricks_Portability_Package_Service::save_session_draft($session['session_id'], [
            $row_id => 'add_incoming',
        ], [
            $row_id,
        ]);
        $this->assertNotWPError($saved);
        $this->assertSame('add_incoming', (string) $saved['draft']['decisions'][$row_id]);
        $this->assertSame(1, (int) $saved['draft']['decision_count']);
        $this->assertSame(1, (int) $saved['draft']['manual_decision_count']);
        $this->assertSame([$row_id], array_values((array) $saved['draft']['manual_rows']));
        $this->assertNotEmpty($saved['draft']['saved_at_gmt']);

        $loaded = DBVC_Bricks_Portability_Package_Service::load_session($session['session_id']);
        $this->assertNotWPError($loaded);
        $this->assertSame('add_incoming', (string) $loaded['draft']['decisions'][$row_id]);
        $this->assertSame([$row_id], array_values((array) $loaded['draft']['manual_rows']));

        $view = DBVC_Bricks_Portability_Package_Service::build_session_view($loaded);
        $this->assertSame('add_incoming', (string) $view['draft']['decisions'][$row_id]);
        $this->assertSame(1, (int) $view['draft']['decision_count']);
        $this->assertSame([$row_id], array_values((array) $view['draft']['manual_rows']));

        $recent_sessions = DBVC_Bricks_Portability_Package_Service::list_recent_sessions(10);
        $record = $this->find_session_record($recent_sessions, (string) $session['session_id']);
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record['draft']['decision_count']);
        $this->assertSame(1, (int) $record['draft']['manual_decision_count']);
        $this->assertNotEmpty($record['draft']['saved_at_gmt']);
    }

    public function test_import_review_uses_live_target_variables_even_when_package_excludes_variable_domain(): void
    {
        update_option('bricks_global_classes', [
            [
                'id' => 'cls-toc',
                'name' => 'toc',
                'settings' => [
                    'fontSize' => 'var(--font-body-s)',
                ],
            ],
        ]);
        update_option('bricks_global_classes_categories', []);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['global_classes']);
        $this->assertNotWPError($export);

        update_option('bricks_global_classes', []);
        update_option('bricks_global_variables', [
            [
                'id' => 't7lqia',
                'name' => 'font-body-s',
                'value' => '14px',
            ],
        ]);
        update_option('bricks_global_variables_categories', []);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename($export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $class_row = $this->find_row_by_object_id((array) $session['rows'], 'cls-toc');
        $this->assertNotNull($class_row);
        $this->assertNotContains('Missing CSS variable on Current Site but supplied by Incoming Package: --font-body-s', (array) $class_row['warnings']);
        $this->assertNotContains('Missing CSS variable on both Current Site and Incoming Package: --font-body-s', (array) $class_row['warnings']);
        $this->assertNotContains('CSS variable may be external or outside Bricks portability scope: --font-body-s', (array) $class_row['warnings']);
        $this->assertSame([], (array) $class_row['references']['css_variable_dependencies']['missing_on_current_supplied_by_incoming']);
        $this->assertSame([], (array) $class_row['references']['css_variable_dependencies']['missing_on_both']);
        $this->assertSame([], (array) $class_row['references']['css_variable_dependencies']['possibly_external']);

        $loaded = DBVC_Bricks_Portability_Package_Service::load_session($session['session_id']);
        $this->assertNotWPError($loaded);
        $this->assertArrayNotHasKey('global_variables', (array) $loaded['target_domains']);

        $view = DBVC_Bricks_Portability_Package_Service::build_session_view($loaded);
        $view_row = $this->find_row_by_object_id((array) $view['rows'], 'cls-toc');
        $this->assertNotNull($view_row);
        $this->assertNotContains('CSS variable may be external or outside Bricks portability scope: --font-body-s', (array) $view_row['warnings']);
    }

    public function test_raw_row_ids_preserve_keep_current_for_draft_and_apply(): void
    {
        update_option('bricks_color_palette', [
            ['id' => 'pal-primary', 'name' => 'Primary', 'value' => '#ff0000'],
        ]);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['color_palette']);
        $this->assertNotWPError($export);

        update_option('bricks_color_palette', []);

        $session = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename($export['zip_path']),
        ]);
        $this->assertNotWPError($session);

        $row = $this->find_row_by_status((array) $session['rows'], 'new_in_source');
        $this->assertNotNull($row);
        $row_id = (string) $row['row_id'];
        $this->assertNotSame('', $row_id);
        $this->assertSame('add_incoming', (string) ($row['suggested_action'] ?? ''));

        $saved = DBVC_Bricks_Portability_Package_Service::save_session_draft($session['session_id'], [
            $row_id => 'keep_current',
        ]);
        $this->assertNotWPError($saved);
        $this->assertSame('keep_current', (string) $saved['draft']['decisions'][$row_id]);

        $loaded = DBVC_Bricks_Portability_Package_Service::load_session($session['session_id']);
        $this->assertNotWPError($loaded);
        $this->assertSame('keep_current', (string) $loaded['draft']['decisions'][$row_id]);

        $view = DBVC_Bricks_Portability_Package_Service::build_session_view($loaded);
        $this->assertSame('keep_current', (string) $view['draft']['decisions'][$row_id]);

        $apply = DBVC_Bricks_Portability_Apply_Service::apply_session($session['session_id'], [
            $row_id => 'keep_current',
        ], [
            $row_id,
        ]);
        $this->assertNotWPError($apply);
        $this->assertFalse((bool) ($apply['applied'] ?? true));
        $this->assertNotEmpty($apply['approved_at_gmt']);
        $this->assertSame([], get_option('bricks_color_palette', []));

        $loaded = DBVC_Bricks_Portability_Package_Service::load_session($session['session_id']);
        $this->assertNotWPError($loaded);
        $this->assertNotEmpty($loaded['approval']['approved_at_gmt']);

        $view = DBVC_Bricks_Portability_Package_Service::build_session_view($loaded);
        $row_view = $this->find_row_by_status((array) $view['rows'], 'new_in_source');
        $this->assertNotNull($row_view);
        $this->assertSame('keep_current', (string) $view['draft']['decisions'][$row_id]);
        $this->assertSame([$row_id], array_values((array) $view['draft']['manual_rows']));
        $this->assertSame([$row_id], array_values((array) $view['approval']['manual_rows']));
        $this->assertSame('keep_current', (string) $row_view['approved_action']);
        $this->assertSame('approved', (string) $row_view['approval_status']);
        $this->assertTrue((bool) $row_view['manual_decision']);
        $this->assertNotEmpty($row_view['approved_at_gmt']);
    }

    private function render_admin_page(string $tab): string
    {
        $_GET['page'] = DBVC_Bricks_Addon::MENU_SLUG;
        $_GET['tab'] = $tab;
        ob_start();
        DBVC_Bricks_Addon::render_admin_page();
        return (string) ob_get_clean();
    }

    private function render_portability_page(): string
    {
        $_GET['page'] = DBVC_Bricks_Portability::PAGE_SLUG;
        unset($_GET['tab']);
        ob_start();
        DBVC_Bricks_Portability::render_admin_page();
        return (string) ob_get_clean();
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    private function reset_sample_options(): void
    {
        update_option('bricks_color_palette', []);
        update_option('bricks_global_classes', []);
        update_option('bricks_global_classes_categories', []);
        update_option('bricks_global_variables', []);
        update_option('bricks_global_variables_categories', []);
        update_option('bricks_global_settings', []);
        update_option('bricks_theme_styles', []);
        update_option('bricks_components', []);
        delete_option('bricks_breakpoints');
        delete_option('bricks_breakpoints_last_generated');
        delete_option('bricks_font_face_rules');
        delete_option('bricks_icon_sets');
        delete_option('bricks_custom_icons');
        delete_option('bricks_disabled_icon_sets');
        $fonts = get_posts([
            'post_type' => 'bricks_fonts',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        foreach ($fonts as $font_id) {
            wp_delete_post((int) $font_id, true);
        }
        $this->delete_all_bricks_templates();
        $this->delete_all_terms_for_taxonomy('template_tag');
        $this->delete_all_terms_for_taxonomy('template_bundle');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $object_id
     * @return array<string, mixed>|null
     */
    private function find_row_by_object_id(array $rows, string $object_id): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['object_id'] ?? '') === $object_id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $status
     * @return array<string, mixed>|null
     */
    private function find_row_by_status(array $rows, string $status): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['status'] ?? '') === $status) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $object_label
     * @return array<string, mixed>|null
     */
    private function find_row_by_object_label(array $rows, string $object_label): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['object_label'] ?? '') === $object_label) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param string $session_id
     * @return array<string, mixed>|null
     */
    private function find_session_record(array $records, string $session_id): ?array
    {
        foreach ($records as $record) {
            if (is_array($record) && (string) ($record['session_id'] ?? '') === $session_id) {
                return $record;
            }
        }

        return null;
    }

    private function register_bricks_template_test_types(): void
    {
        if (! post_type_exists('bricks_template')) {
            register_post_type('bricks_template', [
                'public' => true,
                'supports' => ['title', 'thumbnail', 'revisions'],
            ]);
        }

        if (! taxonomy_exists('template_tag')) {
            register_taxonomy('template_tag', 'bricks_template', ['public' => true]);
        }

        if (! taxonomy_exists('template_bundle')) {
            register_taxonomy('template_bundle', 'bricks_template', ['public' => true]);
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    private function create_bricks_template_fixture(array $args): int
    {
        $post_id = wp_insert_post([
            'post_type' => 'bricks_template',
            'post_status' => sanitize_key((string) ($args['status'] ?? 'publish')),
            'post_title' => sanitize_text_field((string) ($args['title'] ?? 'Template')),
            'post_name' => sanitize_title((string) ($args['slug'] ?? 'template')),
        ], true);
        $this->assertNotWPError($post_id);
        $post_id = (int) $post_id;
        $this->assertGreaterThan(0, $post_id);

        update_post_meta($post_id, '_bricks_template_type', sanitize_key((string) ($args['type'] ?? 'content')));
        update_post_meta($post_id, '_bricks_template_settings', isset($args['settings']) && is_array($args['settings']) ? $args['settings'] : []);
        $area_key = sanitize_key((string) ($args['area_key'] ?? '_bricks_page_content_2'));
        update_post_meta($post_id, $area_key, isset($args['elements']) && is_array($args['elements']) ? $args['elements'] : []);

        $this->set_template_terms($post_id, 'template_tag', isset($args['tags']) && is_array($args['tags']) ? $args['tags'] : []);
        $this->set_template_terms($post_id, 'template_bundle', isset($args['bundles']) && is_array($args['bundles']) ? $args['bundles'] : []);

        return $post_id;
    }

    /**
     * @param array<int, array<string, string>> $terms
     */
    private function set_template_terms(int $post_id, string $taxonomy, array $terms): void
    {
        if (! taxonomy_exists($taxonomy)) {
            return;
        }

        $term_ids = [];
        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $slug = sanitize_title((string) ($term['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $name = sanitize_text_field((string) ($term['name'] ?? $slug));
            $existing = term_exists($slug, $taxonomy);
            if (is_array($existing) && ! empty($existing['term_id'])) {
                $term_ids[] = (int) $existing['term_id'];
                continue;
            }
            if (is_int($existing) && $existing > 0) {
                $term_ids[] = $existing;
                continue;
            }
            $created = wp_insert_term($name !== '' ? $name : $slug, $taxonomy, ['slug' => $slug]);
            $this->assertNotWPError($created);
            $term_ids[] = (int) $created['term_id'];
        }

        if (! empty($term_ids)) {
            $set = wp_set_object_terms($post_id, $term_ids, $taxonomy, false);
            $this->assertNotWPError($set);
        }
    }

    private function find_bricks_template_by_slug(string $slug): int
    {
        $posts = get_posts([
            'post_type' => 'bricks_template',
            'post_status' => 'any',
            'name' => sanitize_title($slug),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        return ! empty($posts[0]) ? (int) $posts[0] : 0;
    }

    /**
     * @return array<int, string>
     */
    private function get_template_term_slugs(int $post_id, string $taxonomy): array
    {
        if (! taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'slugs']);
        if (is_wp_error($terms)) {
            return [];
        }
        $slugs = array_values(array_map('strval', (array) $terms));
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    private function delete_all_bricks_templates(): void
    {
        $post_ids = get_posts([
            'post_type' => 'bricks_template',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($post_ids as $post_id) {
            wp_delete_post((int) $post_id, true);
        }
    }

    private function delete_all_terms_for_taxonomy(string $taxonomy): void
    {
        if (! taxonomy_exists($taxonomy)) {
            return;
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
        ]);
        if (is_wp_error($terms)) {
            return;
        }

        foreach ((array) $terms as $term_id) {
            wp_delete_term((int) $term_id, $taxonomy);
        }
    }

    /**
     * @param array<int, string> $domains
     * @param callable|null $before_export
     * @return array<string, mixed>
     */
    private function create_imported_custom_font_session(array $domains = ['custom_fonts'], $before_export = null): array
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks media-backed portability package tests.');
        }

        $font_attachment_id = $this->create_test_attachment('dbvc-test-font.woff2', 'test-font-binary', 'font/woff2');
        $font_post_id = wp_insert_post([
            'post_type' => 'bricks_fonts',
            'post_status' => 'publish',
            'post_title' => 'Inter Tight',
            'post_name' => 'inter-tight',
        ], true);
        $this->assertNotWPError($font_post_id);
        $font_post_id = (int) $font_post_id;
        $this->assertGreaterThan(0, $font_post_id);
        update_post_meta($font_post_id, 'bricks_font_faces', [
            [
                'font-weight' => '400',
                'font-style' => 'normal',
                'woff2' => $font_attachment_id,
            ],
        ]);
        update_option('bricks_font_face_rules', '@font-face{font-family:"Inter Tight";}');

        if (is_callable($before_export)) {
            $before_export($font_post_id, $font_attachment_id);
        }

        $export = DBVC_Bricks_Portability_Package_Service::create_export($domains);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        wp_delete_post($font_post_id, true);
        wp_delete_attachment($font_attachment_id, true);
        delete_option('bricks_font_face_rules');
        update_option('bricks_global_settings', []);
        update_option('bricks_theme_styles', []);
        update_option('bricks_components', []);
        update_option('bricks_global_classes', []);
        update_option('bricks_global_classes_categories', []);

        $session_view = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session_view);
        $session = DBVC_Bricks_Portability_Package_Service::load_session((string) $session_view['session_id']);
        $this->assertNotWPError($session);

        return [
            'session' => $session,
            'export' => $export,
            'source_font_post_id' => $font_post_id,
            'source_font_attachment_id' => $font_attachment_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function create_imported_icon_collection_session(): array
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks media-backed portability package tests.');
        }

        $icon_attachment_id = $this->create_test_attachment('dbvc-test-icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><path d="M0 0h8v8H0z"/></svg>', 'image/svg+xml');
        update_option('bricks_icon_sets', [
            [
                'id' => 'set_test',
                'name' => 'DBVC Icons',
            ],
        ]);
        update_option('bricks_custom_icons', [
            [
                'id' => 'icon_test',
                'name' => 'Spark',
                'setId' => 'set_test',
                'url' => wp_get_attachment_url($icon_attachment_id),
                'attachment_id' => $icon_attachment_id,
            ],
        ]);
        update_option('bricks_disabled_icon_sets', []);

        $export = DBVC_Bricks_Portability_Package_Service::create_export(['icon_collections']);
        $this->assertNotWPError($export);
        $this->assertFileExists((string) $export['zip_path']);

        wp_delete_attachment($icon_attachment_id, true);
        update_option('bricks_icon_sets', []);
        update_option('bricks_custom_icons', []);
        update_option('bricks_disabled_icon_sets', []);

        $session_view = DBVC_Bricks_Portability_Package_Service::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
        ]);
        $this->assertNotWPError($session_view);
        $session = DBVC_Bricks_Portability_Package_Service::load_session((string) $session_view['session_id']);
        $this->assertNotWPError($session);

        return [
            'session' => $session,
            'export' => $export,
            'source_icon_attachment_id' => $icon_attachment_id,
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|\WP_Error
     */
    private function apply_all_addable_rows(array $session)
    {
        $decisions = [];
        foreach ((array) ($session['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = (string) ($row['row_id'] ?? '');
            if ($row_id === '') {
                continue;
            }
            $status = sanitize_key((string) ($row['status'] ?? ''));
            $available = isset($row['available_actions']) && is_array($row['available_actions']) ? $row['available_actions'] : [];
            if ($status === 'new_in_source' && in_array('add_incoming', $available, true)) {
                $decisions[$row_id] = 'add_incoming';
                continue;
            }
            if (! in_array($status, ['identical', 'missing_from_source'], true) && in_array('replace_with_incoming', $available, true)) {
                $decisions[$row_id] = 'replace_with_incoming';
            }
        }

        return DBVC_Bricks_Portability_Apply_Service::apply_session((string) $session['session_id'], $decisions);
    }

    /**
     * @param array<string, mixed> $session
     * @param string $domain_key
     * @return array<string, mixed>
     */
    private function get_first_session_media_ref(array $session, string $domain_key): array
    {
        $row = $this->find_row_by_domain_key((array) ($session['rows'] ?? []), $domain_key);
        $this->assertNotNull($row);
        $refs = isset($row['source']['media_refs']) && is_array($row['source']['media_refs']) ? $row['source']['media_refs'] : [];
        $this->assertNotEmpty($refs);
        $this->assertIsArray($refs[0]);

        return $refs[0];
    }

    /**
     * @param array<string, mixed> $session
     * @param string $domain_key
     * @param callable $mutator
     * @return array<string, mixed>
     */
    private function mutate_first_session_media_ref(array $session, string $domain_key, callable $mutator): array
    {
        foreach ((array) ($session['rows'] ?? []) as $row_index => $row) {
            if (! is_array($row) || (string) ($row['domain_key'] ?? '') !== $domain_key) {
                continue;
            }
            $refs = isset($row['source']['media_refs']) && is_array($row['source']['media_refs']) ? $row['source']['media_refs'] : [];
            if (empty($refs) || ! is_array($refs[0])) {
                continue;
            }
            $refs[0] = $mutator($refs[0]);
            $session['rows'][$row_index]['source']['media_refs'] = $refs;
            return $session;
        }

        $this->fail('Expected media-backed test session row was not found.');
        return $session;
    }

    /**
     * @param array<string, mixed> $session
     * @return void
     */
    private function persist_test_session(array $session): void
    {
        $session_dir = DBVC_Bricks_Portability_Storage::resolve_session_directory((string) ($session['session_id'] ?? ''));
        $this->assertNotWPError($session_dir);
        $write = DBVC_Bricks_Portability_Storage::write_json_file((string) $session_dir, 'session.json', $session);
        $this->assertNotWPError($write);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $domain_key
     * @return array<string, mixed>|null
     */
    private function find_row_by_domain_key(array $rows, string $domain_key): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['domain_key'] ?? '') === $domain_key) {
                return $row;
            }
        }

        return null;
    }

    private function find_bricks_font_post_by_title(string $title): int
    {
        $posts = get_posts([
            'post_type' => 'bricks_fonts',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);
        foreach ($posts as $post) {
            if ($post instanceof WP_Post && (string) $post->post_title === $title) {
                return (int) $post->ID;
            }
        }

        return 0;
    }

    private function create_test_attachment(string $filename, string $contents, string $mime_type): int
    {
        $uploads = wp_upload_dir();
        $this->assertIsArray($uploads);
        $path = trailingslashit((string) $uploads['path']) . sanitize_file_name($filename);
        $this->assertNotFalse(file_put_contents($path, $contents));
        $attachment_id = wp_insert_attachment([
            'post_title' => sanitize_file_name($filename),
            'post_mime_type' => $mime_type,
            'post_status' => 'inherit',
        ], $path);
        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        return (int) $attachment_id;
    }

    private function cleanup_portability_storage(): void
    {
        $roots = DBVC_Bricks_Portability_Storage::ensure_roots();
        if (is_wp_error($roots) || empty($roots['root'])) {
            return;
        }
        $this->delete_directory((string) $roots['root']);
    }

    private function delete_directory(string $directory): void
    {
        $directory = wp_normalize_path($directory);
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file_info) {
            $path = wp_normalize_path((string) $file_info->getPathname());
            if ($file_info->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function mutate_zip_checksums(string $zip_path, callable $mutator): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks portability package mutation tests.');
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true, 'Expected Bricks portability export zip to open for test mutation.');
        $raw_checksums = $zip->getFromName('checksums.json');
        $this->assertIsString($raw_checksums);
        $checksums = json_decode($raw_checksums, true);
        $this->assertIsArray($checksums);

        $next_checksums = $mutator($checksums, $zip);
        $this->assertIsArray($next_checksums);
        $this->assertTrue($zip->deleteName('checksums.json'));
        $this->assertTrue($zip->addFromString('checksums.json', DBVC_Bricks_Portability_Utils::json_encode($next_checksums)));
        $this->assertTrue($zip->close());
    }

    private function mutate_zip_json_payload(string $zip_path, string $relative_path, callable $mutator): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for Bricks portability package mutation tests.');
        }

        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true, 'Expected Bricks portability export zip to open for test mutation.');
        $raw_payload = $zip->getFromName($relative_path);
        $this->assertIsString($raw_payload);
        $payload = json_decode($raw_payload, true);
        $this->assertIsArray($payload);

        $raw_checksums = $zip->getFromName('checksums.json');
        $this->assertIsString($raw_checksums);
        $checksums = json_decode($raw_checksums, true);
        $this->assertIsArray($checksums);

        $next_payload = $mutator($payload, $zip);
        $this->assertIsArray($next_payload);
        $encoded_payload = DBVC_Bricks_Portability_Utils::json_encode($next_payload);
        $checksums[$relative_path] = 'sha256:' . hash('sha256', $encoded_payload);

        $this->assertTrue($zip->deleteName($relative_path));
        $this->assertTrue($zip->addFromString($relative_path, $encoded_payload));
        $this->assertTrue($zip->deleteName('checksums.json'));
        $this->assertTrue($zip->addFromString('checksums.json', DBVC_Bricks_Portability_Utils::json_encode($checksums)));
        $this->assertTrue($zip->close());
    }
}
