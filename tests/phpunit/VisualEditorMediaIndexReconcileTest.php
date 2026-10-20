<?php

// Guarded ACF shims (shared with the R2-A/R2-C/R2-F scanner tests).
if (! function_exists('acf_get_field_groups')) {
    function acf_get_field_groups()
    {
        return isset($GLOBALS['dbvc_test_acf_groups']) && is_array($GLOBALS['dbvc_test_acf_groups'])
            ? $GLOBALS['dbvc_test_acf_groups']
            : [];
    }
}

if (! function_exists('acf_get_fields')) {
    function acf_get_fields($group_key)
    {
        return isset($GLOBALS['dbvc_test_acf_fields'][$group_key]) && is_array($GLOBALS['dbvc_test_acf_fields'][$group_key])
            ? $GLOBALS['dbvc_test_acf_fields'][$group_key]
            : [];
    }
}

if (! function_exists('acf_get_field_group_visibility')) {
    function acf_get_field_group_visibility($group, $screen = [])
    {
        $callback = $GLOBALS['dbvc_test_acf_group_visibility'] ?? null;

        return is_callable($callback)
            ? (bool) call_user_func($callback, $group, $screen)
            : false;
    }
}

/**
 * R2-H Phase 1 Slice 4 — background reconcile + scheduler.
 *
 * Verifies the reconcile job re-indexes dirty entities (clearing the dirty flag)
 * and removes rows whose entity is no longer indexable, that an attachment deletion
 * flags the current generation dirty for the reconcile to sweep, and that the
 * scheduler registers a recurring job (WP-Cron fallback when Action Scheduler is
 * absent) and cleans it up.
 */
final class VisualEditorMediaIndexReconcileTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var int
     */
    private $administrator_id = 0;

    /**
     * @var array<string, object>
     */
    private $services;

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, 'post,page,attachment');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, 'category,post_tag,post_format,nav_menu,link_category');

        register_post_type('dbvc_rec', [
            'label' => 'Reconcile Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $this->seedAcfDefinitions();

        $this->services = $this->services();
        $this->services['store']->maybeUpgrade();
        $this->services['store']->deleteAll();
    }

    public function tear_down(): void
    {
        $this->services['scheduler']->unregister();
        $this->services['store']->deleteAll();
        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility']
        );
        if (post_type_exists('dbvc_rec')) {
            unregister_post_type('dbvc_rec');
        }
        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }

        parent::tear_down();
    }

    public function test_reconcile_reindexes_dirty_entities_and_clears_the_flag(): void
    {
        $post_id = $this->newIndexedPost('Dirty owner');
        $this->services['store']->markDirty('post', $post_id, 'dbvc_rec');
        $this->assertSame(1, $this->services['store']->countEntities(['onlyDirty' => true]));

        $processed = $this->services['reconciler']->reconcileDirty();

        $this->assertSame(1, $processed);
        $this->assertSame(0, $this->services['store']->countEntities(['onlyDirty' => true]));
        $this->assertIsArray($this->services['store']->getEntity('post', $post_id, 'dbvc_rec'));
    }

    public function test_reconcile_removes_a_dirty_entity_that_is_no_longer_eligible(): void
    {
        $post_id = $this->newIndexedPost('Ineligible owner');
        $this->services['store']->markDirty('post', $post_id, 'dbvc_rec');
        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);

        $this->services['reconciler']->reconcileDirty();

        $this->assertNull($this->services['store']->getEntity('post', $post_id, 'dbvc_rec'));
    }

    public function test_attachment_deletion_flags_the_current_generation_dirty(): void
    {
        $this->newIndexedPost('Attachment owner A');
        $this->newIndexedPost('Attachment owner B');
        $this->assertSame(0, $this->services['store']->countEntities(['onlyDirty' => true]));

        $this->services['invalidator']->onAttachmentDeleted(4242);

        $this->assertSame(2, $this->services['store']->countEntities(['onlyDirty' => true]));
    }

    public function test_scheduler_registers_and_clears_a_recurring_job_via_wp_cron_fallback(): void
    {
        // Action Scheduler is absent in this environment, so the WP-Cron path is used.
        $this->assertFalse(wp_next_scheduled(\Dbvc\VisualEditor\MediaManager\MediaIndexReconciler::RECONCILE_HOOK));

        $this->services['scheduler']->register();
        $this->assertNotFalse(wp_next_scheduled(\Dbvc\VisualEditor\MediaManager\MediaIndexReconciler::RECONCILE_HOOK));

        $this->services['scheduler']->unregister();
        $this->assertFalse(wp_next_scheduled(\Dbvc\VisualEditor\MediaManager\MediaIndexReconciler::RECONCILE_HOOK));
    }

    /**
     * @param string $title
     * @return int
     */
    private function newIndexedPost($title)
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_rec',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        $this->services['invalidator']->reindexEntity('post', 'dbvc_rec', $post_id);
        $this->assertIsArray($this->services['store']->getEntity('post', $post_id, 'dbvc_rec'));

        return $post_id;
    }

    /**
     * @return array<string, object>
     */
    private function services()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $reader = static function ($selector, $object_id) {
            return get_post_meta(absint($object_id), (string) $selector, true);
        };
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            $reader
        );
        $store = new \Dbvc\VisualEditor\MediaManager\MediaIndexStore();
        $projector = new \Dbvc\VisualEditor\MediaManager\MediaIndexProjector($store);
        $invalidator = new \Dbvc\VisualEditor\MediaManager\MediaIndexInvalidator($store, $scanner, $policy, $projector);
        $reconciler = new \Dbvc\VisualEditor\MediaManager\MediaIndexReconciler($store, $invalidator);
        $scheduler = new \Dbvc\VisualEditor\MediaManager\MediaIndexScheduler($reconciler);

        return compact('store', 'projector', 'invalidator', 'reconciler', 'scheduler');
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_rec_media',
                'title' => 'Reconcile Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_rec']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_rec_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_rec_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_rec';
        };
    }
}
