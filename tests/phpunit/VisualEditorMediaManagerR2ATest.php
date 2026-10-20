<?php

// ACF is not installed in the PHPUnit environment. These guarded shims mirror the
// R1-B/R1-E scanner tests so the catalog can evaluate applicability and read raw
// values from injected fixtures without depending on test-file load order.
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
 * R2-A descriptor bridge coverage.
 *
 * Verifies that MediaFindingDescriptorBridge exchanges an opaque finding for a
 * fresh server-authoritative descriptor only after full revalidation, never
 * exposes raw targets, and fails closed on tamper/stale/changed/permission cases.
 * No content is mutated and no media frame is opened.
 */
final class VisualEditorMediaManagerR2ATest extends WP_UnitTestCase
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

        register_post_type('dbvc_r2a', [
            'label' => 'R2-A Content',
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
            $GLOBALS['dbvc_test_acf_group_visibility'],
            $GLOBALS['dbvc_test_acf_values']
        );

        if (post_type_exists('dbvc_r2a')) {
            unregister_post_type('dbvc_r2a');
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

    public function test_writable_finding_mints_a_fresh_standard_descriptor_for_each_family(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $expectations = [
            'featured_image' => [
                'input' => 'image',
                'resolver' => new \Dbvc\VisualEditor\Resolvers\PostFeaturedImageResolver(),
                'source_type' => 'post_field',
                'field_type' => 'featured_image',
                'contract' => 'post_featured_image',
            ],
            'acf_image' => [
                'input' => 'image',
                'resolver' => new \Dbvc\VisualEditor\Resolvers\AcfImageResolver(),
                'source_type' => 'acf_field',
                'field_type' => 'image',
                'contract' => 'acf_image',
            ],
            'acf_gallery' => [
                'input' => 'gallery',
                'resolver' => new \Dbvc\VisualEditor\Resolvers\AcfGalleryResolver(),
                'source_type' => 'acf_field',
                'field_type' => 'gallery',
                'contract' => 'acf_gallery',
            ],
        ];

        foreach ($expectations as $family => $expected) {
            $finding_ref = $findings[$family];
            $result = $context['bridge']->bridgeFinding(
                $context['scan_ref'],
                $context['group_ref'],
                $finding_ref,
                $context['identity']
            );

            $this->assertIsArray($result, $family);
            $this->assertSame('writable', $result['finding']['status'], $family);
            $this->assertSame('hydrated', $result['finding']['descriptorStatus'], $family);
            $this->assertSame($family, $result['finding']['family'], $family);
            $this->assertSame($expected['input'], $result['descriptor']['input'], $family);
            $this->assertSame('empty', $result['descriptor']['expectedState'], $family);
            $this->assertFalse($result['availableActions']['assignMedia'], $family);
            $this->assertFalse($result['availableActions']['openMediaLibrary'], $family);
            $this->assertFalse($result['availableActions']['save'], $family);

            // The descriptor is re-resolved server-side, not persisted, so no
            // opaque token/session is returned to the client.
            $this->assertArrayNotHasKey('token', $result['descriptor'], $family);
            $this->assertArrayNotHasKey('sessionId', $result['descriptor'], $family);

            // Inspect the freshly minted descriptor directly to confirm its shape
            // and single-resolver routing.
            $resolution = $context['bridge']->resolveFinding(
                $context['scan_ref'],
                $context['group_ref'],
                $finding_ref,
                $context['identity']
            );
            $this->assertSame('writable', $resolution['status'], $family);
            $descriptor = $resolution['descriptor'];
            $this->assertInstanceOf(\Dbvc\VisualEditor\Registry\EditableDescriptor::class, $descriptor, $family);
            $this->assertSame('editable', $descriptor->status, $family);
            $this->assertSame($expected['source_type'], (string) ($descriptor->source['type'] ?? ''), $family);
            $this->assertSame($expected['field_type'], (string) ($descriptor->source['field_type'] ?? ''), $family);
            $this->assertSame('post', (string) ($descriptor->entity['type'] ?? ''), $family);
            $this->assertSame($context['post_id'], absint($descriptor->entity['id'] ?? 0), $family);
            $this->assertSame($expected['contract'], (string) ($descriptor->mutation['contract'] ?? ''), $family);
            $this->assertSame('empty', (string) ($descriptor->mutation['expectedState'] ?? ''), $family);
            $this->assertNotSame('', (string) ($descriptor->mutation['expectedEmptyFingerprint'] ?? ''), $family);

            // The minted descriptor routes to exactly one, correct resolver.
            $this->assertTrue($expected['resolver']->supports($descriptor), $family . ' supported');
            foreach ($expectations as $other_family => $other) {
                if ($other_family !== $family) {
                    $this->assertFalse($other['resolver']->supports($descriptor), $family . ' not ' . $other_family);
                }
            }
        }
    }

    public function test_acf_finding_descriptor_carries_server_resolved_selector_and_group_path(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        // The internal field target comes only from the snapshot, never the client.
        $gallery_finding = $context['groups'][$context['group_ref']]['findings'][$findings['acf_gallery']];
        $leaf_field_key = (string) $gallery_finding['field']['field_key'];
        $root_selector = (string) $gallery_finding['field']['selector'];
        $expected_group_path = array_values($gallery_finding['field']['group_path']);
        // The catalog selector is the ROOT group key; the leaf key differs for a nested field.
        $this->assertNotSame($root_selector, $leaf_field_key);

        $resolution = $context['bridge']->resolveFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_gallery'],
            $context['identity']
        );
        $descriptor = $resolution['descriptor'];

        // A group-nested field writes THROUGH the root group: the selector is the ROOT
        // group key, and group_write_path is the {key,name} chain down to the leaf. ACF
        // only writes group subfields correctly via the root group's update_value.
        $this->assertSame($root_selector, (string) ($descriptor->source['field_selector'] ?? ''));
        $this->assertSame($root_selector, (string) ($descriptor->source['field_selector_raw'] ?? ''));
        $this->assertNotSame($leaf_field_key, (string) ($descriptor->source['field_selector'] ?? ''));
        $this->assertSame($expected_group_path, array_values($descriptor->source['group_path'] ?? []));
        $write_path = $descriptor->source['group_write_path'] ?? [];
        $this->assertNotEmpty($write_path);
        $leaf_segment = end($write_path);
        $this->assertSame($leaf_field_key, (string) ($leaf_segment['key'] ?? ''));
        $this->assertTrue(! empty($descriptor->source['is_grouped_field']));
    }

    public function test_response_never_exposes_raw_targets(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $finding = $context['groups'][$context['group_ref']]['findings'][$findings['acf_image']];
        $field = $finding['field'];

        $result = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_image'],
            $context['identity']
        );
        $serialized = wp_json_encode($result);
        $this->assertIsString($serialized);

        foreach ([
            (string) $field['field_key'],
            (string) $field['field_name'],
            (string) $field['selector'],
            'field_selector',
            'group_path',
            'acf_object_id',
        ] as $needle) {
            if ($needle !== '') {
                $this->assertStringNotContainsString($needle, $serialized, $needle);
            }
        }

        // The owner post id must not leak as an exposed integer target.
        $this->assertArrayNotHasKey('owner', $result);
        $this->assertNull($result['finding']['field'] ?? null);
    }

    public function test_snapshot_is_user_bound_and_not_bridgeable_cross_user(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $result = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity']
        );
        $this->assertSame('writable', $result['finding']['status']);

        // The scan snapshot is user/blog-bound, so a different user cannot resolve
        // or bridge the same references.
        $other = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($other);
        $cross_user = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity']
        );
        $this->assertWPError($cross_user);
        $this->assertSame('media_scan_expired_or_invalid', $cross_user->get_error_code());
    }

    public function test_tampered_and_malformed_references_fail_closed(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $unknown_scan = $context['bridge']->bridgeFinding('vems_unknownscan', $context['group_ref'], $findings['featured_image'], $context['identity']);
        $this->assertWPError($unknown_scan);
        $this->assertSame('media_scan_expired_or_invalid', $unknown_scan->get_error_code());

        $bad_group = $context['bridge']->bridgeFinding($context['scan_ref'], 'vemg_not_hex', $findings['featured_image'], $context['identity']);
        $this->assertWPError($bad_group);
        $this->assertSame('media_finding_group_invalid', $bad_group->get_error_code());

        $bad_finding = $context['bridge']->bridgeFinding($context['scan_ref'], $context['group_ref'], 'vemf_not_hex', $context['identity']);
        $this->assertWPError($bad_finding);
        $this->assertSame('media_finding_invalid', $bad_finding->get_error_code());

        $missing_finding = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            'vemf_' . str_repeat('a', 20),
            $context['identity']
        );
        $this->assertWPError($missing_finding);
        $this->assertSame('media_finding_unavailable', $missing_finding->get_error_code());
    }

    public function test_stale_generation_and_revision_fail_closed(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $bad_generation = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            ['generation' => 'vmsg_wronggeneration', 'expectedRevision' => $context['identity']['expectedRevision']]
        );
        $this->assertWPError($bad_generation);
        $this->assertSame('media_scan_generation_mismatch', $bad_generation->get_error_code());

        $bad_revision = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            ['generation' => $context['identity']['generation'], 'expectedRevision' => $context['identity']['expectedRevision'] + 99]
        );
        $this->assertWPError($bad_revision);
        $this->assertSame('media_scan_revision_changed', $bad_revision->get_error_code());
    }

    public function test_expired_snapshot_fails_closed(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        $transient_key = 'dbvc_visual_editor_media_scan_' . $context['scan_ref'];
        $payload = get_transient($transient_key);
        $this->assertIsArray($payload);
        $payload['expires_at'] = time() - 1;
        set_transient($transient_key, $payload, 300);

        $result = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity']
        );
        $this->assertWPError($result);
        $this->assertSame('media_scan_expired_or_invalid', $result->get_error_code());
    }

    public function test_field_populated_after_scan_is_reported_resolved_without_a_descriptor(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        // Populate the ACF image after the snapshot was taken.
        $GLOBALS['dbvc_test_acf_values'][$context['post_id'] . '|field_r2a_image'] = 4242;

        $result = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_image'],
            $context['identity']
        );

        $this->assertIsArray($result);
        $this->assertSame('resolved', $result['finding']['status']);
        $this->assertNull($result['descriptor']);
    }

    public function test_still_empty_but_changed_evidence_is_reported_changed_without_a_descriptor(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        // Still empty, but a different empty representation changes the fingerprint.
        $GLOBALS['dbvc_test_acf_values'][$context['post_id'] . '|field_r2a_image'] = [];

        $result = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_image'],
            $context['identity']
        );

        $this->assertIsArray($result);
        $this->assertSame('changed', $result['finding']['status']);
        $this->assertSame('blocked_stale', $result['finding']['descriptorStatus']);
        $this->assertNull($result['descriptor']);
    }

    public function test_unpublished_owner_is_reported_unavailable_without_a_descriptor(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        wp_update_post(['ID' => $context['post_id'], 'post_status' => 'draft']);

        $result = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['acf_image'],
            $context['identity']
        );

        $this->assertIsArray($result);
        $this->assertSame('unavailable', $result['finding']['status']);
        $this->assertNull($result['descriptor']);
    }

    public function test_deleted_owner_is_reported_unavailable_without_a_descriptor(): void
    {
        [$context, $findings] = $this->completedSnapshot();

        wp_delete_post($context['post_id'], true);

        $result = $context['bridge']->bridgeFinding(
            $context['scan_ref'],
            $context['group_ref'],
            $findings['featured_image'],
            $context['identity']
        );

        $this->assertIsArray($result);
        $this->assertSame('unavailable', $result['finding']['status']);
        $this->assertNull($result['descriptor']);
    }

    /**
     * Build one completed snapshot over a single empty-media owner and return the
     * bridge context plus a family => finding_ref map.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function completedSnapshot()
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'dbvc_r2a',
            'post_status' => 'publish',
            'post_title' => 'R2-A empty media owner',
        ]);
        $this->seedOwnerValues($post_id);

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
        $groups = $loaded['groups'];
        $group_ref = (string) array_key_first($groups);
        $this->assertMatchesRegularExpression('/^vemg_[a-f0-9]{20}$/', $group_ref);

        $findings = [];
        foreach ($groups[$group_ref]['findings'] as $finding_ref => $finding) {
            $findings[(string) $finding['family']] = (string) $finding_ref;
        }
        $this->assertArrayHasKey('featured_image', $findings);
        $this->assertArrayHasKey('acf_image', $findings);
        $this->assertArrayHasKey('acf_gallery', $findings);

        $context = [
            'bridge' => $services['bridge'],
            'registry' => $services['registry'],
            'coordinator' => $services['coordinator'],
            'scan_ref' => (string) $loaded['scan_ref'],
            'group_ref' => $group_ref,
            'groups' => $groups,
            'post_id' => $post_id,
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
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            static function ($selector, $object_id) {
                $key = (string) $object_id . '|' . (string) $selector;

                return array_key_exists($key, $GLOBALS['dbvc_test_acf_values'] ?? [])
                    ? $GLOBALS['dbvc_test_acf_values'][$key]
                    : null;
            }
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

        return compact('coordinator', 'registry', 'bridge', 'store');
    }

    /**
     * @param int $post_id
     * @return void
     */
    private function seedOwnerValues($post_id)
    {
        if (! isset($GLOBALS['dbvc_test_acf_values']) || ! is_array($GLOBALS['dbvc_test_acf_values'])) {
            $GLOBALS['dbvc_test_acf_values'] = [];
        }

        $GLOBALS['dbvc_test_acf_values'][$post_id . '|field_r2a_image'] = null;
        $GLOBALS['dbvc_test_acf_values'][$post_id . '|field_r2a_group'] = ['gallery' => []];
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions()
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_r2a_media',
                'title' => 'R2-A Media',
                'active' => true,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_r2a']]],
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_r2a_media' => [
                ['key' => 'field_r2a_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
                [
                    'key' => 'field_r2a_group',
                    'name' => 'media_group',
                    'label' => 'Media group',
                    'type' => 'group',
                    'sub_fields' => [
                        ['key' => 'field_r2a_gallery', 'name' => 'gallery', 'label' => 'Gallery', 'type' => 'gallery'],
                    ],
                ],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            if (($group['key'] ?? '') !== 'group_r2a_media') {
                return false;
            }

            return ($screen['post_type'] ?? '') === 'dbvc_r2a';
        };
        $GLOBALS['dbvc_test_acf_values'] = [];
    }
}
