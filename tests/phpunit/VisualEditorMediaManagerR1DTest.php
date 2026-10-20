<?php

final class VisualEditorMediaManagerR1DTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var int
     */
    private $post_id = 0;

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, '');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->post_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_type' => 'post',
        ]);
        $_COOKIE['dbvc_visual_editor_mode'] = '1';
        $this->go_to(get_permalink($this->post_id));
        $this->resetAssetHandles();
    }

    public function tear_down(): void
    {
        unset($_COOKIE['dbvc_visual_editor_mode'], $_GET['bricks']);
        $this->resetAssetHandles();

        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }

        parent::tear_down();
    }

    public function test_feature_gate_controls_media_manager_assets_and_bootstrap_configuration(): void
    {
        $this->assetLoader()->enqueue();

        $this->assertTrue(wp_style_is('dbvc-visual-editor-overlay', 'enqueued'));
        $this->assertTrue(wp_style_is('dbvc-visual-editor-media-manager', 'enqueued'));
        $this->assertTrue(wp_script_is('dbvc-visual-editor-overlay', 'enqueued'));
        $this->assertTrue(wp_script_is('dbvc-visual-editor-media-manager', 'enqueued'));

        $localized = (string) wp_scripts()->get_data('dbvc-visual-editor-overlay', 'data');
        $this->assertStringContainsString('"mediaManager"', $localized);
        $this->assertStringContainsString('"enabled":true', $localized);
        $this->assertStringContainsString('dbvc/v1/visual-editor/media-manager', $localized);

        $this->resetAssetHandles();
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '0');
        $this->assetLoader()->enqueue();

        $this->assertTrue(wp_style_is('dbvc-visual-editor-overlay', 'enqueued'));
        $this->assertFalse(wp_style_is('dbvc-visual-editor-media-manager', 'enqueued'));
        $this->assertTrue(wp_script_is('dbvc-visual-editor-overlay', 'enqueued'));
        $this->assertFalse(wp_script_is('dbvc-visual-editor-media-manager', 'enqueued'));
        $localized = (string) wp_scripts()->get_data('dbvc-visual-editor-overlay', 'data');
        $this->assertStringContainsString('"enabled":false', $localized);
    }

    public function test_builder_request_inherits_existing_asset_isolation(): void
    {
        $_GET['bricks'] = 'run';
        $this->assetLoader()->enqueue();

        $this->assertFalse(wp_style_is('dbvc-visual-editor-overlay', 'enqueued'));
        $this->assertFalse(wp_style_is('dbvc-visual-editor-media-manager', 'enqueued'));
        $this->assertFalse(wp_script_is('dbvc-visual-editor-overlay', 'enqueued'));
        $this->assertFalse(wp_script_is('dbvc-visual-editor-media-manager', 'enqueued'));
    }

    public function test_frontend_shell_stays_read_only_and_supports_safe_lazy_row_expansion(): void
    {
        $root = dirname(__DIR__, 2) . '/addons/visual-editor/assets/js/';
        $overlay = (string) file_get_contents($root . 'overlay-app.js');
        $media_manager = (string) file_get_contents($root . 'media-manager-app.js');

        // Tolerate prettier's multi-line call formatting for
        // createToolbarButtonMarkup( 'media-manager', … ).
        $this->assertMatchesRegularExpression("/createToolbarButtonMarkup\\(\\s*'media-manager'/", $overlay);
        $this->assertStringContainsString("hasPopup: 'dialog'", $overlay);
        $this->assertMatchesRegularExpression("/dispatchMediaManagerEvent\\(\\s*'toggle'/", $overlay);
        $this->assertMatchesRegularExpression("/trigger\\.setAttribute\\(\\s*'aria-expanded'/", $media_manager);
        $this->assertStringContainsString("closeButton.focus()", $media_manager);
        $this->assertStringContainsString("trigger.focus()", $media_manager);
        $this->assertMatchesRegularExpression("/event\\.key\\s*!==\\s*'Escape'/", $media_manager);
        $this->assertStringContainsString('window.DBVCVisualEditorApi', $media_manager);
        $this->assertMatchesRegularExpression("/createElement\\(\\s*'table'/", $media_manager);
        $this->assertStringContainsString('data-dbvc-ve-media-manager-group', $media_manager);
        $this->assertStringContainsString('data-dbvc-ve-media-manager-expanded-group', $media_manager);
        $this->assertStringContainsString("'aria-controls'", $media_manager);
        $this->assertStringContainsString('safeFrontendUrl', $media_manager);
        $this->assertStringContainsString('normalizeExpandedRow', $media_manager);
        $this->assertStringContainsString('client.group( scan, normalizedRef )', $media_manager);
        $this->assertStringContainsString('body.ownerDocument.activeElement', $media_manager);
        $this->assertStringContainsString('dbvc-ve-media-manager-description', $media_manager);
        $this->assertStringContainsString('dbvc-ve-media-manager-results-title', $media_manager);
        $this->assertStringContainsString('mediaManagerExpansionCompleteAnnouncement', $media_manager);
        $this->assertMatchesRegularExpression("/panel\\.setAttribute\\(\\s*'aria-busy'/", $media_manager);
        $this->assertStringContainsString('function loadMore()', $media_manager);
        $this->assertStringNotContainsString('fetch(', $media_manager);
        // R2-B/R2-C/R2-F: native Media Library staging (R2-B), a gated empty-field
        // save (R2-C), and a gated populated-field replacement (R2-F Slice 3). Each
        // write runs only through a dedicated, server-revalidated endpoint. The
        // module still issues no direct fetch, no generic descriptor save, and no
        // composite save, and the server availableActions stay false.
        //
        // RK-011 Slice 1: `wp.media` construction moved into the shared factory
        // `DBVCVisualEditorMediaFrame` (see assets/js/media-frame-factory.js). The
        // Media Manager now reaches native `wp.media` through the factory rather
        // than by direct call — the intent of the assertion (native Media Library
        // staging, not an ad-hoc uploader) is preserved.
        $this->assertStringContainsString('DBVCVisualEditorMediaFrame', $media_manager);
        $this->assertStringContainsString('createMediaFrame', $media_manager);
        $this->assertStringContainsString('supportsWpMedia', $media_manager);
        $this->assertStringContainsString("'assign-media'", $media_manager);
        $this->assertStringContainsString("'save-assignment'", $media_manager);
        $this->assertStringContainsString('client.assign(', $media_manager);
        $this->assertStringContainsString('saved: false', $media_manager);
        // R2-F Slice 3: the replace path is a distinct gated endpoint carrying the
        // opaque expected-current-value fingerprint; it never deletes attachments.
        $this->assertStringContainsString("'replace-media'", $media_manager);
        $this->assertStringContainsString("'save-replacement'", $media_manager);
        $this->assertStringContainsString('client.replace(', $media_manager);
        $this->assertStringContainsString('field.valueRef', $media_manager);
        $this->assertDoesNotMatchRegularExpression('/assignMedia\\s*:\\s*true/', $media_manager);
        $this->assertStringNotContainsString('composite-save', $media_manager);
        $this->assertStringNotContainsString('.save(', $media_manager);
    }

    public function test_shared_subtle_token_and_scoped_shell_styles_use_the_approved_contrast_value(): void
    {
        $root = dirname(__DIR__, 2) . '/addons/visual-editor/assets/css/';
        $overlay = (string) file_get_contents($root . 'overlay.css');
        $media_manager = (string) file_get_contents($root . 'media-manager.css');

        $this->assertStringContainsString(
            '--dbvc-ve-color-text-subtle: color-mix(in srgb, var(--dbvc-ve-color-dark) 60%, #ffffff);',
            $overlay
        );
        $this->assertStringNotContainsString('--dbvc-ve-color-text-subtle:', $media_manager);
        $this->assertStringContainsString('.dbvc-ve-media-manager', $media_manager);
        $this->assertStringContainsString('@media (max-width: 640px), (max-height: 760px)', $media_manager);
        $this->assertStringContainsString('width: 44px;', $media_manager);
        $this->assertStringContainsString('height: 44px;', $media_manager);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $media_manager);
    }

    /**
     * @return \Dbvc\VisualEditor\Assets\AssetLoader
     */
    private function assetLoader()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $page_context = new \Dbvc\VisualEditor\Context\PageContextResolver();
        $edit_mode = new \Dbvc\VisualEditor\Context\EditModeState(
            $capabilities,
            $page_context,
            new \Dbvc\VisualEditor\Context\FrontendRuntimeGuard()
        );
        $registry = new \Dbvc\VisualEditor\Registry\EditableRegistry($page_context);

        return new \Dbvc\VisualEditor\Assets\AssetLoader(
            dirname(__DIR__, 2) . '/addons/visual-editor/bootstrap.php',
            $edit_mode,
            $registry,
            $page_context
        );
    }

    /**
     * @return void
     */
    private function resetAssetHandles()
    {
        foreach ([
            'dbvc-visual-editor-media-manager',
            'dbvc-visual-editor-overlay',
            'dbvc-visual-editor-api-client',
        ] as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }
}
