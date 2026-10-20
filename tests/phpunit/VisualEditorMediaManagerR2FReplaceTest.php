<?php

// Guarded ACF shims (mirrors the R2-A/R2-C/R2-F scanner tests) so the catalog
// reports ACF as available and can evaluate the top-level media fields under test.
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
 * R2-F Slice 3 controlled replacement coverage.
 *
 * Verifies that MediaAssignmentService::replace overwrites a populated field
 * through the shared mutation pipeline only after the expected-current-value
 * precondition (opaque value fingerprint) passes, fails closed when the field
 * changed or was emptied after the read, never deletes the prior attachment,
 * and reconciles the still-populated inventory item. Content is only ever
 * mutated on disposable fixture posts, which are torn down.
 */
final class VisualEditorMediaManagerR2FReplaceTest extends WP_UnitTestCase
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

        register_post_type('dbvc_r2fr', [
            'label' => 'R2-F Replace Content',
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

        if (post_type_exists('dbvc_r2fr')) {
            unregister_post_type('dbvc_r2fr');
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

    public function test_replace_overwrites_the_field_and_reconciles_without_deleting_the_prior_attachment(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $replacement = $this->createImageAttachment();

        $assigned = $this->assignedField($context, 'acf_image');
        $this->assertSame('assigned', $assigned['status']);
        $this->assertMatchesRegularExpression('/^vemv_[a-f0-9]{24}$/', (string) $assigned['valueRef']);

        $cache_events = 0;
        $listener = static function () use (&$cache_events) {
            $cache_events++;
        };
        add_action('dbvc_visual_editor_invalidate_cache', $listener);

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $assigned['valueRef'],
            $context['identity'],
            ['attachmentId' => $replacement]
        );

        remove_action('dbvc_visual_editor_invalidate_cache', $listener);

        $this->assertIsArray($result);
        $this->assertSame('replaced', $result['assignment']['status']);
        $this->assertSame('acf_image', $result['assignment']['family']);
        $this->assertGreaterThan(0, $result['assignment']['changeSetId']);

        // The field now points to the replacement value.
        $this->assertSame($replacement, absint(get_post_meta($context['post_id'], 'hero_image', true)));

        // The prior attachment is never deleted by a replacement.
        $this->assertInstanceOf(WP_Post::class, get_post($original));

        // A cache invalidation fired for the write.
        $this->assertGreaterThan(0, $cache_events);

        // The reconciled field stays populated with a fresh value fingerprint.
        $reconciled = $this->findRowField($result['row'], $assigned['findingRef']);
        $this->assertSame('assigned', $reconciled['status']);
        $this->assertMatchesRegularExpression('/^vemv_[a-f0-9]{24}$/', (string) $reconciled['valueRef']);
        $this->assertNotSame($assigned['valueRef'], $reconciled['valueRef']);
    }

    public function test_stale_expected_value_ref_is_blocked_without_overwriting(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $replacement = $this->createImageAttachment();

        $assigned = $this->assignedField($context, 'acf_image');
        $stale_ref = 'vemv_' . str_repeat('a', 24);
        $this->assertNotSame($assigned['valueRef'], $stale_ref);

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $stale_ref,
            $context['identity'],
            ['attachmentId' => $replacement]
        );

        $this->assertWPError($result);
        $this->assertSame('media_replace_stale', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertSame(409, $data['status'] ?? 0);
        // The existing value is untouched.
        $this->assertSame($original, absint(get_post_meta($context['post_id'], 'hero_image', true)));
    }

    public function test_field_emptied_after_read_is_blocked_as_not_populated(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $replacement = $this->createImageAttachment();

        $assigned = $this->assignedField($context, 'acf_image');

        // Empty the field AFTER the expand read captured its value fingerprint.
        delete_post_meta($context['post_id'], 'hero_image');

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $assigned['valueRef'],
            $context['identity'],
            ['attachmentId' => $replacement]
        );

        $this->assertWPError($result);
        $this->assertSame('media_replace_not_populated', $result->get_error_code());
        // The field stays empty; the replacement was not written.
        $this->assertSame('', (string) get_post_meta($context['post_id'], 'hero_image', true));
    }

    public function test_malformed_expected_value_ref_is_rejected(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $replacement = $this->createImageAttachment();

        $assigned = $this->assignedField($context, 'acf_image');

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            'not-a-value-ref',
            $context['identity'],
            ['attachmentId' => $replacement]
        );

        $this->assertWPError($result);
        $this->assertSame('media_replace_value_ref_invalid', $result->get_error_code());
        $this->assertSame($original, absint(get_post_meta($context['post_id'], 'hero_image', true)));
    }

    public function test_non_image_replacement_is_rejected_without_writing(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $document = $this->createNonImageAttachment();

        $assigned = $this->assignedField($context, 'acf_image');

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $assigned['valueRef'],
            $context['identity'],
            ['attachmentId' => $document]
        );

        $this->assertWPError($result);
        $this->assertSame('media_assignment_save_failed', $result->get_error_code());
        $this->assertSame($original, absint(get_post_meta($context['post_id'], 'hero_image', true)));
    }

    public function test_gallery_replacement_overwrites_ordered_ids(): void
    {
        $first = $this->createImageAttachment();
        $second = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['showcase' => [$first, $second]]);
        $third = $this->createImageAttachment();

        $assigned = $this->assignedField($context, 'acf_gallery');
        $this->assertSame('assigned', $assigned['status']);

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $assigned['valueRef'],
            $context['identity'],
            ['attachmentIds' => [$third, $first]]
        );

        $this->assertIsArray($result);
        $this->assertSame('replaced', $result['assignment']['status']);
        $this->assertSame(2, $result['assignment']['attachmentCount']);
        $stored = get_post_meta($context['post_id'], 'showcase', true);
        $this->assertSame([$third, $first], array_map('absint', is_array($stored) ? $stored : []));
        // Neither of the prior attachments is deleted.
        $this->assertInstanceOf(WP_Post::class, get_post($second));
    }

    /**
     * Expand the group and return the projected inventory field for a family.
     *
     * @param array<string, mixed> $context
     * @param string               $family
     * @return array<string, mixed>
     */
    private function assignedField(array $context, $family)
    {
        $row = $context['read_model']->expandGroup(
            $context['scan_ref'],
            $context['group_ref'],
            $context['identity']
        );
        $this->assertNotWPError($row);
        $fields = is_array($row['row']['fields'] ?? null) ? $row['row']['fields'] : [];
        foreach ($fields as $field) {
            if (is_array($field) && ($field['family'] ?? '') === $family && ($field['status'] ?? '') === 'assigned') {
                return $field;
            }
        }

        $this->fail('No assigned ' . $family . ' field was projected for the group.');
    }

    /**
     * @param array<string, mixed> $row
     * @param string               $finding_ref
     * @return array<string, mixed>
     */
    private function findRowField($row, $finding_ref)
    {
        $fields = is_array($row) && isset($row['fields']) && is_array($row['fields']) ? $row['fields'] : [];
        foreach ($fields as $field) {
            if (is_array($field) && ($field['findingRef'] ?? '') === $finding_ref) {
                return $field;
            }
        }

        $this->fail('Finding ' . $finding_ref . ' was not present in the reconciled row.');
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{0: array<string, mixed>}
     */
    private function completedSnapshot(array $meta)
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_r2fr',
            'post_status' => 'publish',
            'post_title' => 'R2-F replace owner',
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
        $this->assertNotWPError($loaded);
        $group_ref = (string) array_key_first($loaded['groups']);

        return [[
            'assignment' => $services['assignment'],
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
        // A post-meta-backed value reader keeps the scanner in agreement with the
        // resolver's update_field-absent post-meta fallback in this ACF-less env.
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
        $registry = new \Dbvc\VisualEditor\Registry\EditableRegistry(
            new \Dbvc\VisualEditor\Context\PageContextResolver()
        );
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

        return compact('coordinator', 'read_model', 'assignment');
    }

    /**
     * @return int
     */
    private function createImageAttachment()
    {
        return (int) self::factory()->attachment->create_object(
            'r2fr-image-' . wp_generate_password(6, false, false) . '.jpg',
            0,
            [
                'post_mime_type' => 'image/jpeg',
                'post_type' => 'attachment',
            ]
        );
    }

    /**
     * @return int
     */
    private function createNonImageAttachment()
    {
        return (int) self::factory()->attachment->create_object(
            'r2fr-doc-' . wp_generate_password(6, false, false) . '.pdf',
            0,
            [
                'post_mime_type' => 'application/pdf',
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
                'key' => 'group_r2fr_media',
                'title' => 'R2-F Replace Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_r2fr']]],
            ],
        ];
        // key === name mirrors real ACF single-value resolution in this ACF-less env
        // (the scanner reads by catalog selector/field key; the resolver's post-meta
        // fallback reads/writes by field name).
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_r2fr_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
                ['key' => 'showcase', 'name' => 'showcase', 'label' => 'Showcase', 'type' => 'gallery'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_r2fr_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_r2fr';
        };
    }
}
