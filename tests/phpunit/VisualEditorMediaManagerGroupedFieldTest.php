<?php

// Guarded ACF shims (mirrors the R2-A/R2-C/R2-F scanner tests) so the catalog
// reports ACF as available and can walk nested group sub-fields under test.
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
 * Group-nested ACF media field write targeting.
 *
 * Regression coverage for the Media Manager save of a field nested inside one or
 * more ACF Group fields. ACF stores group subfields under a prefixed meta key
 * (`{group}_{subfield}`) and only writes them correctly through the root group's
 * own update_value. A direct `update_field()` on the leaf key writes to the wrong,
 * unprefixed meta (no error, no persistence). The descriptor therefore targets the
 * ROOT group selector and carries `group_write_path` — the {key,name} chain beneath
 * the root down to the leaf — so the resolver writes/reads THROUGH the group.
 */
final class VisualEditorMediaManagerGroupedFieldTest extends WP_UnitTestCase
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

        register_post_type('dbvc_grp', [
            'label' => 'Grouped Media Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title'],
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

        if (post_type_exists('dbvc_grp')) {
            unregister_post_type('dbvc_grp');
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

    public function test_grouped_gallery_descriptor_targets_the_leaf_field_key_not_the_group_root(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $this->assertArrayHasKey('acf_gallery', $findings, 'The nested gallery should be cataloged as a finding.');

        $resolution = $context['bridge']->resolveFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_gallery'],
            $context['identity']
        );

        $this->assertIsArray($resolution);
        $this->assertSame('writable', $resolution['status']);
        $descriptor = $resolution['descriptor'];
        $this->assertInstanceOf(\Dbvc\VisualEditor\Registry\EditableDescriptor::class, $descriptor);

        // The write goes THROUGH the root group: the selector is the ROOT group key, and
        // group_write_path is the {key,name} chain beneath it down to the leaf.
        $this->assertSame('field_container', $descriptor->source['field_selector']);
        $this->assertSame('field_container', $descriptor->source['field_selector_raw']);
        $this->assertSame('field_leaf_gallery', $descriptor->source['field_key']);
        $this->assertTrue((bool) $descriptor->source['is_grouped_field']);
        $this->assertSame(
            [['key' => 'field_leaf_gallery', 'name' => 'leaf_gallery']],
            $descriptor->source['group_write_path']
        );
    }

    public function test_grouped_image_descriptor_writes_through_the_root_group(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $this->assertArrayHasKey('acf_image', $findings, 'The nested image should be cataloged as a finding.');

        $resolution = $context['bridge']->resolveFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_image'],
            $context['identity']
        );

        $this->assertIsArray($resolution);
        $this->assertSame('writable', $resolution['status']);
        $descriptor = $resolution['descriptor'];
        $this->assertInstanceOf(\Dbvc\VisualEditor\Registry\EditableDescriptor::class, $descriptor);
        $this->assertSame('field_container', $descriptor->source['field_selector']);
        $this->assertSame('field_container', $descriptor->source['field_selector_raw']);
        $this->assertTrue((bool) $descriptor->source['is_grouped_field']);
        $this->assertSame(
            [['key' => 'field_leaf_image', 'name' => 'leaf_image']],
            $descriptor->source['group_write_path']
        );
    }

    public function test_grouped_descriptor_selector_is_the_root_not_the_leaf(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $resolution = $context['bridge']->resolveFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_gallery'],
            $context['identity']
        );
        $descriptor = $resolution['descriptor'];
        // The write selector must be the root group key (not the leaf), because ACF only
        // writes a group subfield correctly through the root group's update_value.
        $this->assertNotSame(
            $descriptor->source['field_key'],
            $descriptor->source['field_selector'],
            'A grouped field must not write directly to the leaf key.'
        );
        // The leaf is the final segment of the group write path.
        $path = $descriptor->source['group_write_path'];
        $leaf = end($path);
        $this->assertSame($descriptor->source['field_key'], $leaf['key']);
    }

    public function test_grouped_write_routes_through_the_group_and_never_writes_a_bare_leaf_meta(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = (int) self::factory()->attachment->create_object(
            'grp-image-' . wp_generate_password(6, false, false) . '.jpg',
            0,
            ['post_mime_type' => 'image/jpeg', 'post_type' => 'attachment']
        );

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_gallery'],
            $context['identity'],
            ['attachmentIds' => [$image]]
        );

        // This env has no real ACF, so the group write (which requires update_field())
        // fails HONESTLY instead of silently writing to the wrong, unprefixed meta — the
        // failure mode that hid the earlier bug. The important guarantee is that no value
        // lands in the bare leaf meta keys.
        $this->assertWPError($result);
        $this->assertSame('media_assignment_save_failed', $result->get_error_code());
        $this->assertSame('', (string) get_post_meta($context['post_id'], 'leaf_gallery', true));
        $this->assertSame('', (string) get_post_meta($context['post_id'], 'field_leaf_gallery', true));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function completedSnapshot()
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_grp',
            'post_status' => 'publish',
            'post_title' => 'Grouped media owner',
        ]);

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
        $findings = [];
        foreach ($loaded['groups'][$group_ref]['findings'] as $finding_ref => $finding) {
            $findings[(string) $finding['family']] = (string) $finding_ref;
        }

        $context = [
            'bridge' => $services['bridge'],
            'assignment' => $services['assignment'],
            'post_id' => $post_id,
            'scan_ref' => (string) $loaded['scan_ref'],
            'group_ref' => $group_ref,
            'identity' => [
                'generation' => (string) $loaded['generation'],
                'expectedRevision' => absint($loaded['revision']),
            ],
        ];

        return [$context, $findings];
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
        $read_model = new \Dbvc\VisualEditor\MediaManager\MediaScanReadModel($coordinator, $scanner, $policy);
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

        return compact('coordinator', 'bridge', 'assignment');
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_grp_media',
                'title' => 'Grouped Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_grp']]],
            ],
        ];
        // A top-level ACF group field ("container") holds the media leaves. The catalog
        // reports each leaf with selector = the group root key ("field_container") and
        // field_key = the leaf key, with a non-empty group_path — exactly the shape
        // that broke the save before the leaf-key targeting fix.
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_grp_media' => [
                [
                    'key' => 'field_container',
                    'name' => 'container',
                    'label' => 'Container',
                    'type' => 'group',
                    'sub_fields' => [
                        ['key' => 'field_leaf_gallery', 'name' => 'leaf_gallery', 'label' => 'Leaf Gallery', 'type' => 'gallery'],
                        ['key' => 'field_leaf_image', 'name' => 'leaf_image', 'label' => 'Leaf Image', 'type' => 'image'],
                    ],
                ],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_grp_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_grp';
        };
    }
}
