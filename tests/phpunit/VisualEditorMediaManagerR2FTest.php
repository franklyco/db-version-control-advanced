<?php

// Guarded ACF shims (shared with the R2-A/R2-C/R2-E scanner tests).
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
 * R2-F Slice 1 — populated-field inventory + bounded preview.
 *
 * Verifies that expanding an entity lists both empty findings and already
 * populated fields (with a sanitized thumbnail preview), that the preview leaks
 * no raw target, and that the top-level results list/counts stay empty-focused.
 */
final class VisualEditorMediaManagerR2FTest extends WP_UnitTestCase
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
     * @var array<int, string>
     */
    private $scan_refs = [];

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

        register_post_type('dbvc_r2f', [
            'label' => 'R2-F Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $this->seedAcfDefinitions();
    }

    public function tear_down(): void
    {
        wp_set_current_user($this->administrator_id);
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        foreach ($this->scan_refs as $scan_ref) {
            $store->delete($scan_ref);
        }

        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility']
        );

        if (post_type_exists('dbvc_r2f')) {
            unregister_post_type('dbvc_r2f');
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

    public function test_detail_panel_lists_empty_findings_and_populated_fields_with_preview(): void
    {
        $image = $this->createImageAttachment();
        // hero_image populated; featured image and showcase gallery empty.
        [$context] = $this->completedSnapshot(['hero_image' => $image]);

        $row = $context['read_model']->expandGroup(
            $context['scan_ref'],
            $context['group_ref'],
            $context['identity']
        );
        $this->assertNotWPError($row);
        $fields = $this->fieldsByFamily($row['row']['fields']);

        // Empty fields remain findings.
        $this->assertSame('missing', $fields['featured_image']['status']);
        $this->assertSame('missing', $fields['acf_gallery']['status']);

        // The populated ACF image is reported as an inventory item with a preview.
        $assigned = $fields['acf_image'];
        $this->assertSame('assigned', $assigned['status']);
        $this->assertSame(1, absint($assigned['preview']['count'] ?? 0));
        $this->assertIsString($assigned['preview']['url'] ?? null);
        $this->assertArrayHasKey('url', $assigned['preview']);

        // Counts distinguish missing from populated.
        $this->assertSame(2, absint($row['row']['counts']['missing']));
        $this->assertSame(1, absint($row['row']['counts']['populated']));

        // The projection never exposes a raw target.
        $serialized = wp_json_encode($assigned);
        foreach (['hero_image', 'field_key', 'field_selector', 'acf_object_id', 'group_path'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $serialized, $needle);
        }
        $this->assertArrayNotHasKey('field', $assigned);
    }

    public function test_top_level_list_stays_empty_focused(): void
    {
        $image = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $image]);

        $list = $context['read_model']->getList($context['scan_ref'], $context['identity']);
        $this->assertNotWPError($list);
        $this->assertCount(1, $list['items']);
        // Only the two empty fields count toward the row; the populated field is excluded.
        $this->assertSame(2, absint($list['items'][0]['missingCount']));
    }

    public function test_populated_gallery_reports_count_and_a_single_preview(): void
    {
        $first = $this->createImageAttachment();
        $second = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['showcase' => [$first, $second]]);

        $row = $context['read_model']->expandGroup(
            $context['scan_ref'],
            $context['group_ref'],
            $context['identity']
        );
        $fields = $this->fieldsByFamily($row['row']['fields']);
        $gallery = $fields['acf_gallery'];
        $this->assertSame('assigned', $gallery['status']);
        $this->assertSame(2, absint($gallery['preview']['count'] ?? 0));
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function fieldsByFamily($fields)
    {
        $map = [];
        foreach ((array) $fields as $field) {
            if (is_array($field)) {
                $map[(string) ($field['family'] ?? '')] = $field;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{0: array<string, mixed>}
     */
    private function completedSnapshot(array $meta)
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_r2f',
            'post_status' => 'publish',
            'post_title' => 'R2-F media inventory owner',
        ]);
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        $services = $this->services();
        $snapshot = $services['coordinator']->start();
        $this->assertNotWPError($snapshot);
        $this->scan_refs[] = (string) $snapshot['scan_ref'];

        $iterations = 0;
        while ($snapshot['state'] === 'scanning' && $iterations < 100) {
            $snapshot = $services['coordinator']->runNextChunk(
                $snapshot['scan_ref'],
                $snapshot['generation'],
                $snapshot['revision']
            );
            $this->assertNotWPError($snapshot);
            $iterations++;
        }
        $this->assertSame('complete', $snapshot['state']);

        $loaded = $services['coordinator']->load($snapshot['scan_ref']);
        $group_ref = (string) array_key_first($loaded['groups']);

        return [[
            'read_model' => $services['read_model'],
            'post_id' => $post_id,
            'scan_ref' => (string) $loaded['scan_ref'],
            'group_ref' => $group_ref,
            'identity' => [
                'generation' => (string) $loaded['generation'],
                'expectedRevision' => absint($loaded['revision']),
            ],
        ]];
    }

    /**
     * @return array<string, object>
     */
    private function services()
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $provider = new \Dbvc\VisualEditor\MediaManager\ScanCandidateProvider($policy, 50);
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        $reader = static function ($selector, $object_id) {
            return get_post_meta(absint($object_id), (string) $selector, true);
        };
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            $reader
        );
        $coordinator = new \Dbvc\VisualEditor\MediaManager\MediaScanCoordinator($provider, $scanner, $store, $catalog, 50);
        $read_model = new \Dbvc\VisualEditor\MediaManager\MediaScanReadModel($coordinator, $scanner, $policy);

        return compact('coordinator', 'read_model');
    }

    /**
     * @return int
     */
    private function createImageAttachment()
    {
        return (int) self::factory()->attachment->create_object(
            'r2f-image-' . wp_generate_password(6, false, false) . '.jpg',
            0,
            [
                'post_mime_type' => 'image/jpeg',
                'post_type' => 'attachment',
            ]
        );
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_r2f_media',
                'title' => 'R2-F Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_r2f']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_r2f_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
                ['key' => 'showcase', 'name' => 'showcase', 'label' => 'Showcase', 'type' => 'gallery'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_r2f_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_r2f';
        };
    }
}
