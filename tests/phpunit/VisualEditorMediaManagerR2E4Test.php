<?php

/**
 * R2-E4 feature isolation (ship-readiness).
 *
 * Proves the Media Manager kill switch actually gates the whole remediation
 * surface, not just asset enqueue: the REST permission gate (`canAccess`, the
 * permission_callback for every Media Manager route) is closed whenever the
 * Media Manager flag is off, the Visual Editor master flag is off, the user
 * lacks the base capability, or the user is logged out — and open only when the
 * VE master flag, the Media Manager flag, and the base capability all hold.
 */
final class VisualEditorMediaManagerR2E4Test extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var int
     */
    private $administrator_id = 0;

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
    }

    public function tear_down(): void
    {
        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }

        parent::tear_down();
    }

    public function test_is_media_manager_enabled_requires_both_the_master_and_feature_flags(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        $this->assertTrue(DBVC_Visual_Editor_Addon::is_media_manager_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '0');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_media_manager_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_media_manager_enabled());

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '0');
        $this->assertFalse(DBVC_Visual_Editor_Addon::is_media_manager_enabled());
    }

    public function test_disabled_media_manager_flag_closes_the_rest_access_gate(): void
    {
        $controller = $this->controller();

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        $this->assertTrue($controller->canAccess(), 'Enabled + capable admin can access.');

        // Turning off only the Media Manager flag closes every route.
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '0');
        $this->assertFalse($controller->canAccess(), 'Disabled Media Manager flag closes access.');
    }

    public function test_disabled_visual_editor_master_flag_closes_the_gate(): void
    {
        $controller = $this->controller();

        // Media Manager flag on, but the Visual Editor master flag off.
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        $this->assertFalse($controller->canAccess(), 'The master flag gates the Media Manager.');
    }

    public function test_base_capability_and_authentication_gate_access(): void
    {
        $controller = $this->controller();
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');

        // A user without the base capability cannot access even when enabled.
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse($controller->canAccess(), 'Subscriber lacks the base capability.');

        // Logged-out requests cannot access.
        wp_set_current_user(0);
        $this->assertFalse($controller->canAccess(), 'Logged-out requests are gated.');

        wp_set_current_user($this->administrator_id);
        $this->assertTrue($controller->canAccess(), 'A capable admin can access when enabled.');
    }

    /**
     * @return \Dbvc\VisualEditor\Rest\Controllers\MediaManagerController
     */
    private function controller()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $provider = new \Dbvc\VisualEditor\MediaManager\ScanCandidateProvider($policy, 50);
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier()
        );
        $coordinator = new \Dbvc\VisualEditor\MediaManager\MediaScanCoordinator($provider, $scanner, $store, $catalog, 50);
        $read_model = new \Dbvc\VisualEditor\MediaManager\MediaScanReadModel($coordinator, $scanner, $policy);
        $page_context = new \Dbvc\VisualEditor\Context\PageContextResolver();
        $edit_mode = new \Dbvc\VisualEditor\Context\EditModeState(
            $capabilities,
            $page_context,
            new \Dbvc\VisualEditor\Context\FrontendRuntimeGuard()
        );
        $registry = new \Dbvc\VisualEditor\Registry\EditableRegistry($page_context);
        $bridge = new \Dbvc\VisualEditor\MediaManager\MediaFindingDescriptorBridge(
            $coordinator,
            $scanner,
            $policy,
            $capabilities,
            $registry
        );
        $mutations = new \Dbvc\VisualEditor\Save\MutationService(
            new \Dbvc\VisualEditor\Resolvers\ResolverRegistry(null, new \Dbvc\VisualEditor\Bricks\LoopContextResolver(null, null), null, null),
            new \Dbvc\VisualEditor\Save\ValidationService(),
            new \Dbvc\VisualEditor\Save\SanitizationService(),
            new \Dbvc\VisualEditor\Audit\ChangeLogger(),
            new \Dbvc\VisualEditor\Cache\CacheInvalidator(),
            new \Dbvc\VisualEditor\Presentation\DescriptorSummaryBuilder(),
            new \Dbvc\VisualEditor\Journal\ChangeJournalRecorder(new \Dbvc\VisualEditor\Journal\ChangeJournalStore())
        );
        $assignment = new \Dbvc\VisualEditor\MediaManager\MediaAssignmentService($bridge, $mutations, $read_model);

        return new \Dbvc\VisualEditor\Rest\Controllers\MediaManagerController(
            $coordinator,
            $read_model,
            $edit_mode,
            $capabilities,
            $bridge,
            $assignment
        );
    }
}
