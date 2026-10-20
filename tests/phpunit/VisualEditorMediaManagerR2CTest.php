<?php

// Guarded ACF shims (mirrors the R1-B/R2-A scanner tests) so the catalog reports
// ACF as available and can evaluate the top-level media fields under test.
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
 * R2-C field-level assignment and save coverage.
 *
 * Verifies that MediaAssignmentService writes the staged selection through the
 * shared mutation pipeline only after the expected-empty precondition passes,
 * fails closed when a field was populated/changed after the scan, validates
 * attachments, and reconciles the finding after a successful save. Content is
 * only ever mutated on disposable fixture posts, which are torn down.
 */
final class VisualEditorMediaManagerR2CTest extends WP_UnitTestCase
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

        register_post_type('dbvc_r2c', [
            'label' => 'R2-C Content',
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

        if (post_type_exists('dbvc_r2c')) {
            unregister_post_type('dbvc_r2c');
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

    public function test_featured_image_assignment_saves_and_reconciles_the_finding(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => $image]
        );

        $this->assertIsArray($result);
        $this->assertSame('saved', $result['assignment']['status']);
        $this->assertSame('featured_image', $result['assignment']['family']);
        $this->assertSame(1, $result['assignment']['attachmentCount']);
        $this->assertGreaterThan(0, $result['assignment']['changeSetId']);

        // The write actually happened.
        $this->assertSame($image, absint(get_post_thumbnail_id($context['post_id'])));

        // Reconciliation reread shows the just-assigned field as an assigned,
        // replaceable field (D-052) — not a terminal resolved_or_changed prompt.
        $this->assertTrue($result['reconciled']);
        $field = $this->findRowField($result['row'], $findings['featured_image']);
        $this->assertSame('assigned', $field['status']);
        $this->assertMatchesRegularExpression('/^vemv_[a-f0-9]{24}$/', (string) ($field['valueRef'] ?? ''));
        $this->assertTrue((bool) ($field['availableActions']['replace'] ?? false));
        // The featured finding is no longer missing; the two ACF fields remain missing.
        $this->assertSame(2, absint($result['row']['counts']['missing'] ?? -1));
    }

    public function test_field_populated_after_scan_is_blocked_and_not_overwritten(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $existing = $this->createImageAttachment();
        $replacement = $this->createImageAttachment();

        // Populate the featured image AFTER the scan snapshot was taken.
        set_post_thumbnail($context['post_id'], $existing);

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => $replacement]
        );

        $this->assertWPError($result);
        $this->assertSame('media_assignment_stale', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertSame(409, $data['status'] ?? 0);
        // The existing assignment must not be overwritten.
        $this->assertSame($existing, absint(get_post_thumbnail_id($context['post_id'])));
    }

    public function test_non_image_attachment_is_rejected_without_writing(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $document = $this->createNonImageAttachment();

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => $document]
        );

        $this->assertWPError($result);
        $this->assertSame('media_assignment_save_failed', $result->get_error_code());
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    public function test_missing_attachment_value_is_rejected(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            []
        );

        $this->assertWPError($result);
        $this->assertSame('media_assignment_value_invalid', $result->get_error_code());
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    public function test_stale_generation_blocks_the_save(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            ['generation' => 'vmsg_wronggeneration', 'expectedRevision' => $context['identity']['expectedRevision']],
            ['attachmentId' => $image]
        );

        $this->assertWPError($result);
        $this->assertSame('media_scan_generation_mismatch', $result->get_error_code());
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    public function test_acf_image_assignment_saves_the_field(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_image'],
            $context['identity'],
            ['attachmentId' => $image]
        );

        $this->assertIsArray($result);
        $this->assertSame('saved', $result['assignment']['status']);
        $this->assertSame('acf_image', $result['assignment']['family']);
        $this->assertSame($image, absint(get_post_meta($context['post_id'], 'hero_image', true)));
        $field = $this->findRowField($result['row'], $findings['acf_image']);
        // D-052: a just-assigned field is immediately replaceable, not resolved_or_changed.
        $this->assertSame('assigned', $field['status']);
        $this->assertMatchesRegularExpression('/^vemv_[a-f0-9]{24}$/', (string) ($field['valueRef'] ?? ''));
        $this->assertTrue((bool) ($field['availableActions']['replace'] ?? false));
    }

    public function test_acf_gallery_assignment_saves_ordered_ids(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $first = $this->createImageAttachment();
        $second = $this->createImageAttachment();

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_gallery'],
            $context['identity'],
            ['attachmentIds' => [$first, $second]]
        );

        $this->assertIsArray($result);
        $this->assertSame('saved', $result['assignment']['status']);
        $this->assertSame(2, $result['assignment']['attachmentCount']);
        $stored = get_post_meta($context['post_id'], 'showcase', true);
        $this->assertSame([$first, $second], array_map('absint', is_array($stored) ? $stored : []));
    }

    public function test_gallery_assignment_exceeding_the_cap_is_rejected_without_a_write(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_gallery'],
            $context['identity'],
            ['attachmentIds' => range(1, 201)]
        );

        $this->assertWPError($result);
        $this->assertSame('media_assignment_value_invalid', $result->get_error_code());
        $this->assertSame('', (string) get_post_meta($context['post_id'], 'showcase', true));
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
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function completedSnapshot()
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_r2c',
            'post_status' => 'publish',
            'post_title' => 'R2-C empty media owner',
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
        $this->assertArrayHasKey('featured_image', $findings);
        $this->assertArrayHasKey('acf_image', $findings);
        $this->assertArrayHasKey('acf_gallery', $findings);

        $context = [
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
            'r2c-image-' . wp_generate_password(6, false, false) . '.jpg',
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
            'r2c-doc-' . wp_generate_password(6, false, false) . '.pdf',
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
                'key' => 'group_r2c_media',
                'title' => 'R2-C Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_r2c']]],
            ],
        ];
        // In this ACF-less env the scanner reads by the catalog selector (field key)
        // while the resolver's post-meta fallback reads/writes by field name. Real
        // ACF resolves key/name/selector to one value; using key === name here makes
        // the raw-meta test env mirror that single-value behavior.
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_r2c_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
                ['key' => 'showcase', 'name' => 'showcase', 'label' => 'Showcase', 'type' => 'gallery'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_r2c_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_r2c';
        };
    }
}
