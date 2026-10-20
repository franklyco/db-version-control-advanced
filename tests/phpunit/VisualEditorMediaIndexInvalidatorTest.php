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
 * R2-H Phase 1 Slice 3 — incremental invalidation.
 *
 * Verifies the invalidator keeps a single entity's index row current on entity
 * changes: filling a media field lowers the row's missing count, an entity that
 * loses eligibility (unpublished) or is deleted has its row removed, and a term
 * edit re-indexes just that term — all without a full rebuild.
 */
final class VisualEditorMediaIndexInvalidatorTest extends WP_UnitTestCase
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
     * @var \Dbvc\VisualEditor\MediaManager\MediaIndexStore
     */
    private $store;

    /**
     * @var \Dbvc\VisualEditor\MediaManager\MediaIndexInvalidator
     */
    private $invalidator;

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

        register_post_type('dbvc_inv', [
            'label' => 'Invalidator Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);
        register_taxonomy('dbvc_inv_tax', 'dbvc_inv', [
            'label' => 'Invalidator Tax',
            'public' => true,
            'show_ui' => true,
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $this->seedAcfDefinitions();

        $services = $this->services();
        $this->store = $services['store'];
        $this->invalidator = $services['invalidator'];
        $this->store->maybeUpgrade();
        $this->store->deleteAll();
    }

    public function tear_down(): void
    {
        $this->store->deleteAll();
        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility']
        );
        if (taxonomy_exists('dbvc_inv_tax')) {
            unregister_taxonomy('dbvc_inv_tax');
        }
        if (post_type_exists('dbvc_inv')) {
            unregister_post_type('dbvc_inv');
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

    public function test_reindex_updates_the_missing_count_when_a_field_is_filled(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_inv',
            'post_status' => 'publish',
            'post_title' => 'Filling owner',
        ]);

        $this->invalidator->reindexEntity('post', 'dbvc_inv', $post_id);
        $before = $this->store->getEntity('post', $post_id, 'dbvc_inv');
        $this->assertIsArray($before);
        // Featured image + hero_image both empty.
        $this->assertSame(2, $before['missing_count']);

        // Fill the ACF image field, then re-index the single entity.
        update_post_meta($post_id, 'hero_image', $this->createImageAttachment());
        $this->invalidator->reindexEntity('post', 'dbvc_inv', $post_id);

        $after = $this->store->getEntity('post', $post_id, 'dbvc_inv');
        $this->assertIsArray($after);
        $this->assertSame(1, $after['missing_count']);
        $this->assertNotSame($before['content_hash'], $after['content_hash']);
    }

    public function test_save_post_hook_reindexes_the_entity(): void
    {
        $this->invalidator->register();
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_inv',
            'post_status' => 'publish',
            'post_title' => 'Saved owner',
        ]);
        // The create above fired save_post, so the row exists already.
        $this->invalidator->unregister();

        $row = $this->store->getEntity('post', $post_id, 'dbvc_inv');
        $this->assertIsArray($row);
        $this->assertGreaterThan(0, $row['missing_count']);
    }

    public function test_unpublished_entity_is_removed_from_the_index(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_inv',
            'post_status' => 'publish',
            'post_title' => 'Unpublishing owner',
        ]);
        $this->invalidator->reindexEntity('post', 'dbvc_inv', $post_id);
        $this->assertIsArray($this->store->getEntity('post', $post_id, 'dbvc_inv'));

        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        $this->invalidator->onSavePost($post_id, get_post($post_id));

        $this->assertNull($this->store->getEntity('post', $post_id, 'dbvc_inv'));
    }

    public function test_deleting_a_post_removes_its_index_row(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_inv',
            'post_status' => 'publish',
            'post_title' => 'Doomed owner',
        ]);
        $this->invalidator->reindexEntity('post', 'dbvc_inv', $post_id);
        $this->assertIsArray($this->store->getEntity('post', $post_id, 'dbvc_inv'));

        $this->invalidator->onPostRemoved($post_id);
        $this->assertNull($this->store->getEntity('post', $post_id, 'dbvc_inv'));
    }

    public function test_edited_term_reindexes_the_term(): void
    {
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'dbvc_inv_tax',
            'name' => 'Indexed term',
        ]);
        $this->assertNotWPError($term);

        $this->invalidator->onEditedTerm($term->term_id, $term->term_taxonomy_id, 'dbvc_inv_tax');

        // Terms carry no featured image or ACF media fields in this fixture, so the term
        // has no supported media fields and is not retained in the index.
        $this->assertNull($this->store->getEntity('term', $term->term_id, 'dbvc_inv_tax'));
    }

    /**
     * @return int
     */
    private function createImageAttachment()
    {
        return (int) self::factory()->attachment->create_object(
            'inv-image-' . wp_generate_password(6, false, false) . '.jpg',
            0,
            ['post_mime_type' => 'image/jpeg', 'post_type' => 'attachment']
        );
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

        return compact('store', 'projector', 'invalidator');
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_inv_media',
                'title' => 'Invalidator Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_inv']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_inv_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_inv_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_inv';
        };
    }
}
