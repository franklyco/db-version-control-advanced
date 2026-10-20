<?php

// Guarded ACF shims (shared with the R2-A/R2-C/R2-F mutation tests).
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
 * R2-E2 security / stale / permission hardening for the mutation paths.
 *
 * Complements the R2-C assignment and R2-F replace coverage (which already prove
 * populated-after-scan, stale-generation, non-image, cardinality, stale-value-ref,
 * and emptied-after-read cases) by asserting that the end-to-end assign() and
 * replace() writes ALSO fail closed — leaving content untouched — when the caller
 * is a different user, the scan revision changed, the owner lost eligibility, or
 * the object edit capability was revoked between the scan/read and the write.
 */
final class VisualEditorMediaManagerR2E2Test extends WP_UnitTestCase
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

        register_post_type('dbvc_r2e2', [
            'label' => 'R2-E2 Content',
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

        if (post_type_exists('dbvc_r2e2')) {
            unregister_post_type('dbvc_r2e2');
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

    // ---------------------------------------------------------------------
    // Assignment (empty featured image) hardening.
    // ---------------------------------------------------------------------

    public function test_assign_rejects_a_foreign_users_snapshot_without_writing(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        // The scan snapshot is user/blog-bound; a different user cannot write with it.
        $other = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($other);

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => $image]
        );

        wp_set_current_user($this->administrator_id);
        $this->assertWPError($result);
        $this->assertSame('media_scan_expired_or_invalid', $result->get_error_code());
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    public function test_assign_blocks_on_revision_mismatch_without_writing(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            [
                'generation' => $context['identity']['generation'],
                'expectedRevision' => $context['identity']['expectedRevision'] + 1,
            ],
            ['attachmentId' => $image]
        );

        $this->assertWPError($result);
        $this->assertSame('media_scan_revision_changed', $result->get_error_code());
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    public function test_assign_blocks_when_owner_is_unpublished_after_scan(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        // The owner leaves published/eligible status after the scan snapshot.
        wp_update_post(['ID' => $context['post_id'], 'post_status' => 'draft']);

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => $image]
        );

        $this->assertWPError($result);
        $this->assertSame('media_assignment_stale', $result->get_error_code());
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    public function test_assign_fails_closed_when_edit_capability_is_revoked(): void
    {
        [$context, $findings] = $this->completedSnapshot();
        $image = $this->createImageAttachment();

        $deny = $this->denyEditPost($context['post_id']);
        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => $image]
        );
        remove_filter('map_meta_cap', $deny, 10);

        $this->assertWPError($result);
        // Capability loss surfaces as an eligibility failure before the write.
        $this->assertSame('media_assignment_stale', $result->get_error_code());
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    public function test_assign_rejects_a_nonexistent_attachment_without_writing(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $result = $context['assignment']->assign(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity'],
            ['attachmentId' => 99999999]
        );

        $this->assertWPError($result);
        $this->assertSame('media_assignment_save_failed', $result->get_error_code());
        $this->assertSame(0, absint(get_post_thumbnail_id($context['post_id'])));
    }

    // ---------------------------------------------------------------------
    // Replacement (populated ACF image) hardening.
    // ---------------------------------------------------------------------

    public function test_replace_rejects_a_foreign_users_snapshot_without_writing(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $replacement = $this->createImageAttachment();
        $assigned = $this->assignedField($context, 'acf_image');

        $other = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($other);

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $assigned['valueRef'],
            $context['identity'],
            ['attachmentId' => $replacement]
        );

        wp_set_current_user($this->administrator_id);
        $this->assertWPError($result);
        $this->assertSame('media_scan_expired_or_invalid', $result->get_error_code());
        $this->assertSame($original, absint(get_post_meta($context['post_id'], 'hero_image', true)));
    }

    public function test_replace_blocks_on_revision_mismatch_without_writing(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $replacement = $this->createImageAttachment();
        $assigned = $this->assignedField($context, 'acf_image');

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $assigned['valueRef'],
            [
                'generation' => $context['identity']['generation'],
                'expectedRevision' => $context['identity']['expectedRevision'] + 1,
            ],
            ['attachmentId' => $replacement]
        );

        $this->assertWPError($result);
        $this->assertSame('media_scan_revision_changed', $result->get_error_code());
        $this->assertSame($original, absint(get_post_meta($context['post_id'], 'hero_image', true)));
    }

    public function test_replace_blocks_when_owner_is_unpublished_after_read(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $replacement = $this->createImageAttachment();
        $assigned = $this->assignedField($context, 'acf_image');

        wp_update_post(['ID' => $context['post_id'], 'post_status' => 'draft']);

        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $assigned['valueRef'],
            $context['identity'],
            ['attachmentId' => $replacement]
        );

        $this->assertWPError($result);
        $this->assertSame('media_replace_unavailable', $result->get_error_code());
        $this->assertSame($original, absint(get_post_meta($context['post_id'], 'hero_image', true)));
    }

    public function test_replace_fails_closed_when_edit_capability_is_revoked(): void
    {
        $original = $this->createImageAttachment();
        [$context] = $this->completedSnapshot(['hero_image' => $original]);
        $replacement = $this->createImageAttachment();
        $assigned = $this->assignedField($context, 'acf_image');

        $deny = $this->denyEditPost($context['post_id']);
        $result = $context['assignment']->replace(
            $context['scan_ref'],
            $context['group_ref'],
            $assigned['findingRef'],
            $assigned['valueRef'],
            $context['identity'],
            ['attachmentId' => $replacement]
        );
        remove_filter('map_meta_cap', $deny, 10);

        $this->assertWPError($result);
        $this->assertSame('media_replace_unavailable', $result->get_error_code());
        $this->assertSame($original, absint(get_post_meta($context['post_id'], 'hero_image', true)));
    }

    /**
     * A map_meta_cap filter that denies edit_post for one post id, simulating a
     * capability revoked between the scan/read and the write. Returns the callable
     * so the caller can remove it.
     *
     * @param int $post_id
     * @return callable
     */
    private function denyEditPost($post_id)
    {
        $filter = static function ($caps, $cap, $user_id, $args) use ($post_id) {
            if ($cap === 'edit_post' && isset($args[0]) && absint($args[0]) === absint($post_id)) {
                return ['do_not_allow'];
            }

            return $caps;
        };
        add_filter('map_meta_cap', $filter, 10, 4);

        return $filter;
    }

    /**
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
     * @param array<string, mixed> $meta
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function completedSnapshot(array $meta = [])
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_r2e2',
            'post_status' => 'publish',
            'post_title' => 'R2-E2 media owner',
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
        $findings = [];
        foreach ($loaded['groups'][$group_ref]['findings'] as $finding_ref => $finding) {
            $findings[(string) $finding['family']] = (string) $finding_ref;
        }

        $context = [
            'assignment' => $services['assignment'],
            'read_model' => $services['read_model'],
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
            'r2e2-image-' . wp_generate_password(6, false, false) . '.jpg',
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
                'key' => 'group_r2e2_media',
                'title' => 'R2-E2 Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_r2e2']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_r2e2_media' => [
                ['key' => 'hero_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_r2e2_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_r2e2';
        };
    }
}
