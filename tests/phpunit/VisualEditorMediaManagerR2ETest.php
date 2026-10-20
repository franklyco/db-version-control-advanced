<?php

// Guarded ACF shims (shared with the R2-A/R2-C scanner tests).
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
 * R2-E1 journal/cache verification.
 *
 * Proves that a successful media assignment records a completed change-journal
 * item, fires the audit event with the correct old/new values, and invalidates
 * the entity cache; and that a failed save records a journal failure and fires
 * neither the audit nor the cache-invalidation hook. Content is only mutated on
 * disposable fixtures, which are torn down.
 */
final class VisualEditorMediaManagerR2ETest extends WP_UnitTestCase
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

        register_post_type('dbvc_r2e', [
            'label' => 'R2-E Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $this->seedAcfDefinitions();

        // Ensure the change-journal tables exist for this test's assertions.
        (new \Dbvc\VisualEditor\Journal\ChangeJournalStore())->maybeUpgrade();
    }

    public function tear_down(): void
    {
        wp_set_current_user($this->administrator_id);
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore();
        foreach ($this->scan_refs as $scan_ref) {
            $store->delete($scan_ref);
        }

        remove_all_actions('dbvc_visual_editor_invalidate_cache');
        remove_all_actions('dbvc_visual_editor_audit_event');

        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility']
        );

        if (post_type_exists('dbvc_r2e')) {
            unregister_post_type('dbvc_r2e');
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

    public function test_successful_assignment_records_journal_audit_and_cache(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        $cache_events = [];
        $audit_events = [];
        add_action('dbvc_visual_editor_invalidate_cache', static function ($descriptor) use (&$cache_events) {
            $cache_events[] = $descriptor;
        });
        add_action('dbvc_visual_editor_audit_event', static function ($context) use (&$audit_events) {
            $audit_events[] = $context;
        });

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => $image]
        );

        $this->assertIsArray($result);
        $this->assertSame('saved', $result['assignment']['status']);
        $change_set_id = absint($result['assignment']['changeSetId']);
        $this->assertGreaterThan(0, $change_set_id);

        // Cache invalidation fired exactly once for the correct post entity.
        $this->assertCount(1, $cache_events);
        $this->assertSame('post', (string) ($cache_events[0]['entity']['type'] ?? ''));
        $this->assertSame($context['post_id'], absint($cache_events[0]['entity']['id'] ?? 0));

        // Audit event fired once with the empty old value and the new attachment.
        $this->assertCount(1, $audit_events);
        $this->assertSame($this->administrator_id, absint($audit_events[0]['changed_by'] ?? 0));
        $this->assertSame(0, absint($audit_events[0]['old_value']['attachmentId'] ?? 0));
        $this->assertSame($image, absint($audit_events[0]['new_value']['attachmentId'] ?? 0));

        // A completed journal item records the resolver and the new value.
        $item = $this->journalItem($change_set_id);
        $this->assertNotEmpty($item);
        $this->assertSame('completed', $item['result_status']);
        $this->assertSame('post_featured_image', $item['resolver_name']);
        $this->assertStringContainsString((string) $image, (string) $item['new_value_json']);
    }

    public function test_acf_image_assignment_records_its_resolver_and_invalidates_cache(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        $cache_events = [];
        add_action('dbvc_visual_editor_invalidate_cache', static function ($descriptor) use (&$cache_events) {
            $cache_events[] = $descriptor;
        });

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_image'],
            $context['identity'],
            ['attachmentId' => $image]
        );

        $this->assertSame('saved', $result['assignment']['status']);
        $item = $this->journalItem(absint($result['assignment']['changeSetId']));
        $this->assertSame('completed', $item['result_status']);
        $this->assertSame('acf_image', $item['resolver_name']);
        $this->assertCount(1, $cache_events);
        $this->assertSame('post', (string) ($cache_events[0]['entity']['type'] ?? ''));
        $this->assertSame($context['post_id'], absint($cache_events[0]['entity']['id'] ?? 0));
    }

    public function test_failed_assignment_records_a_journal_failure_without_cache_or_audit(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $document = $this->createNonImageAttachment();

        $cache_events = [];
        $audit_events = [];
        add_action('dbvc_visual_editor_invalidate_cache', static function () use (&$cache_events) {
            $cache_events[] = true;
        });
        add_action('dbvc_visual_editor_audit_event', static function () use (&$audit_events) {
            $audit_events[] = true;
        });

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => $document]
        );

        $this->assertWPError($result);
        $this->assertSame('media_assignment_save_failed', $result->get_error_code());

        // No cache invalidation and no audit event on a failed save.
        $this->assertCount(0, $cache_events);
        $this->assertCount(0, $audit_events);

        // The journal recorded a failure, not a phantom success.
        $failed = $this->latestJournalItem('post_featured_image');
        $this->assertNotEmpty($failed);
        $this->assertSame('failed', $failed['result_status']);

        // The featured image was not set.
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    /**
     * @param int $change_set_id
     * @return array<string, mixed>
     */
    private function journalItem($change_set_id)
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dbvc_ve_change_items WHERE change_set_id = %d ORDER BY id DESC LIMIT 1",
                absint($change_set_id)
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @param string $resolver_name
     * @return array<string, mixed>
     */
    private function latestJournalItem($resolver_name)
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dbvc_ve_change_items WHERE resolver_name = %s ORDER BY id DESC LIMIT 1",
                sanitize_key($resolver_name)
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function completedSnapshot()
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_r2e',
            'post_status' => 'publish',
            'post_title' => 'R2-E empty media owner',
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
            'r2e-image-' . wp_generate_password(6, false, false) . '.jpg',
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
            'r2e-doc-' . wp_generate_password(6, false, false) . '.pdf',
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
                'key' => 'group_r2e_media',
                'title' => 'R2-E Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_r2e']]],
            ],
        ];
        // key === name so the ACF-less scanner and resolver agree on post-meta storage.
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_r2e_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
                ['key' => 'showcase', 'name' => 'showcase', 'label' => 'Showcase', 'type' => 'gallery'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_r2e_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_r2e';
        };
    }
}
