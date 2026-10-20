<?php

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

        return is_callable($callback) ? (bool) call_user_func($callback, $group, $screen) : false;
    }
}

final class VisualEditorMediaManagerR1CTest extends WP_UnitTestCase
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
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, '');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, '');

        register_post_type('dbvc_mm_read', [
            'label' => 'Media Read Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);
        register_taxonomy('dbvc_mm_read_tax', ['dbvc_mm_read'], [
            'label' => 'Media Read Terms',
            'public' => true,
            'show_ui' => true,
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $_COOKIE['dbvc_visual_editor_mode'] = '1';
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
            $_COOKIE['dbvc_visual_editor_mode'],
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_acf_group_visibility'],
            $GLOBALS['dbvc_test_acf_values'],
            $GLOBALS['dbvc_test_acf_value_reader']
        );

        if (taxonomy_exists('dbvc_mm_read_tax')) {
            unregister_taxonomy('dbvc_mm_read_tax');
        }
        if (post_type_exists('dbvc_mm_read')) {
            unregister_post_type('dbvc_mm_read');
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

    public function test_list_projection_supports_bounded_cursor_search_filter_and_sort_without_private_targets(): void
    {
        $fixture = $this->seedMediaEntities();
        $services = $this->services(2);
        $snapshot = $this->completeScan($services['coordinator']);

        $first = $services['read_model']->getList($snapshot['scan_ref'], [
            'generation' => $snapshot['generation'],
            'expectedRevision' => $snapshot['revision'],
            'sort' => 'entity_asc',
            'limit' => 1,
        ]);
        $this->assertNotWPError($first);
        $this->assertCount(1, $first['items']);
        $this->assertTrue($first['pagination']['hasMore']);
        $this->assertMatchesRegularExpression('/^vemg_[a-f0-9]{20}$/', $first['pagination']['nextCursor']);
        $this->assertSame(1, $first['viewModelVersion']);
        $this->assertSame('complete', $first['scan']['state']);
        $this->assertFalse($first['items'][0]['availableActions']['assignMedia']);
        $this->assertPrivateTargetKeysAbsent($first);

        $second = $services['read_model']->getList($snapshot['scan_ref'], [
            'generation' => $snapshot['generation'],
            'expectedRevision' => $snapshot['revision'],
            'sort' => 'entity_asc',
            'limit' => 1,
            'cursor' => $first['pagination']['nextCursor'],
        ]);
        $this->assertNotWPError($second);
        $this->assertCount(1, $second['items']);
        $this->assertNotSame($first['items'][0]['groupRef'], $second['items'][0]['groupRef']);

        $search = $services['read_model']->getList($snapshot['scan_ref'], [
            'generation' => $snapshot['generation'],
            'expectedRevision' => $snapshot['revision'],
            'search' => 'Alpha',
            'entityFamily' => 'post',
            'fieldFamily' => 'acf_image',
            'sort' => 'missing_desc',
            'limit' => 20,
        ]);
        $this->assertNotWPError($search);
        $this->assertCount(1, $search['items']);
        $this->assertSame('Alpha empty media', $search['items'][0]['entity']['label']);
        $this->assertSame(1, $search['items'][0]['findingCounts']['acfImage']);
        $this->assertSame(0, $search['items'][0]['findingCounts']['acfGallery']);
        $this->assertStringContainsString('alpha-empty-media', $search['items'][0]['entity']['frontendUrl']);
        $this->assertArrayHasKey('alpha', $fixture);

        $deny_alpha = static function ($caps, $cap, $user_id, $args) use ($fixture) {
            unset($user_id);
            if ($cap === 'edit_post' && absint($args[0] ?? 0) === $fixture['alpha']) {
                return ['do_not_allow'];
            }

            return $caps;
        };
        add_filter('map_meta_cap', $deny_alpha, 10, 4);
        $hidden = $services['read_model']->getList($snapshot['scan_ref'], [
            'generation' => $snapshot['generation'],
            'expectedRevision' => $snapshot['revision'],
            'search' => 'Alpha',
            'limit' => 20,
        ]);
        remove_filter('map_meta_cap', $deny_alpha, 10);
        $this->assertNotWPError($hidden);
        $this->assertSame([], $hidden['items']);
    }

    public function test_invalid_query_identity_cursor_and_revision_fail_closed(): void
    {
        $this->seedMediaEntities();
        $services = $this->services(3);
        $snapshot = $this->completeScan($services['coordinator']);
        $base = [
            'generation' => $snapshot['generation'],
            'expectedRevision' => $snapshot['revision'],
        ];
        $cases = [
            ['entityFamily' => 'option', 'code' => 'media_scan_entity_filter_invalid'],
            ['fieldFamily' => 'video', 'code' => 'media_scan_field_filter_invalid'],
            ['sort' => 'owner_id', 'code' => 'media_scan_sort_invalid'],
            ['limit' => 51, 'code' => 'media_scan_limit_invalid'],
            ['cursor' => 'vemg_tampered', 'code' => 'media_scan_cursor_invalid'],
            ['search' => str_repeat('a', 101), 'code' => 'media_scan_search_too_long'],
            ['generation' => 'vmsg_wrong', 'code' => 'media_scan_generation_mismatch'],
            ['expectedRevision' => $snapshot['revision'] + 1, 'code' => 'media_scan_revision_changed'],
        ];

        foreach ($cases as $case) {
            $expected = $case['code'];
            unset($case['code']);
            $result = $services['read_model']->getList($snapshot['scan_ref'], array_merge($base, $case));
            $this->assertWPError($result);
            $this->assertSame($expected, $result->get_error_code());
        }

        $unknown_cursor = $services['read_model']->getList($snapshot['scan_ref'], array_merge($base, [
            'cursor' => 'vemg_aaaaaaaaaaaaaaaaaaaa',
        ]));
        $this->assertWPError($unknown_cursor);
        $this->assertSame('media_scan_cursor_invalid', $unknown_cursor->get_error_code());
    }

    public function test_row_expansion_revalidates_missing_changed_resolved_and_unavailable_states(): void
    {
        $fixture = $this->seedMediaEntities();
        $services = $this->services(3);
        $snapshot = $this->completeScan($services['coordinator']);
        $group_ref = $this->groupRefForLabel($snapshot, 'Alpha empty media');
        $identity = [
            'generation' => $snapshot['generation'],
            'expectedRevision' => $snapshot['revision'],
        ];

        $current = $services['read_model']->expandGroup($snapshot['scan_ref'], $group_ref, $identity);
        $this->assertNotWPError($current);
        $this->assertSame('current', $current['row']['status']);
        $this->assertSame(3, $current['row']['counts']['missing']);
        foreach ($current['row']['fields'] as $field) {
            $this->assertSame('missing', $field['status']);
            $this->assertSame('not_hydrated', $field['descriptorStatus']);
            $this->assertFalse($field['availableActions']['hydrateDescriptor']);
            $this->assertFalse($field['availableActions']['assignMedia']);
        }
        $this->assertPrivateTargetKeysAbsent($current);

        $GLOBALS['dbvc_test_acf_values'][$fixture['alpha'] . '|field_read_image'] = 321;
        $GLOBALS['dbvc_test_acf_values'][$fixture['alpha'] . '|field_read_group'] = ['gallery' => null];
        $changed = $services['read_model']->expandGroup($snapshot['scan_ref'], $group_ref, $identity);
        $this->assertNotWPError($changed);
        $this->assertSame('changed', $changed['row']['status']);
        $this->assertSame(1, $changed['row']['counts']['missing']);
        $this->assertSame(1, $changed['row']['counts']['changed']);
        // D-052: a field that is now populated projects as an assigned (replaceable)
        // field, not a terminal resolved_or_changed prompt.
        $this->assertSame(0, $changed['row']['counts']['resolvedOrChanged']);
        $this->assertSame(1, $changed['row']['counts']['populated']);
        $assigned_states = array_values(array_filter(array_map(static function ($field) {
            return is_array($field) && ($field['status'] ?? '') === 'assigned' ? $field : null;
        }, $changed['row']['fields'])));
        $this->assertCount(1, $assigned_states);
        $this->assertMatchesRegularExpression('/^vemv_[a-f0-9]{24}$/', (string) ($assigned_states[0]['valueRef'] ?? ''));

        $tampered = $services['read_model']->expandGroup(
            $snapshot['scan_ref'],
            'vemg_aaaaaaaaaaaaaaaaaaaa',
            $identity
        );
        $this->assertWPError($tampered);
        $this->assertSame('media_scan_group_unavailable', $tampered->get_error_code());

        wp_update_post(['ID' => $fixture['alpha'], 'post_status' => 'draft']);
        $unpublished = $services['read_model']->expandGroup($snapshot['scan_ref'], $group_ref, $identity);
        $this->assertWPError($unpublished);
        $this->assertSame('media_scan_group_unavailable', $unpublished->get_error_code());
    }

    public function test_row_provider_failure_returns_safe_unavailable_projection(): void
    {
        $this->seedMediaEntities();
        $services = $this->services(3);
        $snapshot = $this->completeScan($services['coordinator']);
        $group_ref = $this->groupRefForLabel($snapshot, 'Alpha empty media');
        $GLOBALS['dbvc_test_acf_value_reader'] = static function () {
            throw new RuntimeException('synthetic provider failure');
        };

        $result = $services['read_model']->expandGroup($snapshot['scan_ref'], $group_ref, [
            'generation' => $snapshot['generation'],
            'expectedRevision' => $snapshot['revision'],
        ]);
        $this->assertNotWPError($result);
        $this->assertSame('unavailable', $result['row']['status']);
        $this->assertSame('media_scan_acf_value_read_failed', $result['row']['error']['code']);
        $this->assertTrue($result['row']['error']['retryable']);
        $this->assertSame(3, $result['row']['counts']['unavailable']);
        $this->assertPrivateTargetKeysAbsent($result);
    }

    public function test_rest_routes_enforce_feature_mode_capability_identity_and_cross_user_isolation(): void
    {
        $this->seedMediaEntities();
        $services = $this->services(2);
        add_action('rest_api_init', [$services['controller'], 'register']);
        do_action('rest_api_init');
        remove_action('rest_api_init', [$services['controller'], 'register']);
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans', $routes);
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans/latest', $routes);
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)', $routes);
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/groups/(?P<group_ref>vemg_[a-f0-9]{20})', $routes);

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $_COOKIE['dbvc_visual_editor_mode'] = '1';
        $denied = rest_get_server()->dispatch(new WP_REST_Request('POST', '/dbvc/v1/visual-editor/media-manager/scans'));
        $this->assertGreaterThanOrEqual(401, $denied->get_status());

        wp_set_current_user($this->administrator_id);
        unset($_COOKIE['dbvc_visual_editor_mode']);
        $inactive = rest_get_server()->dispatch(new WP_REST_Request('POST', '/dbvc/v1/visual-editor/media-manager/scans'));
        $this->assertSame(403, $inactive->get_status());
        $this->assertSame('media_manager_mode_inactive', $inactive->get_data()['code']);

        $_COOKIE['dbvc_visual_editor_mode'] = '1';
        $started_response = rest_get_server()->dispatch(new WP_REST_Request('POST', '/dbvc/v1/visual-editor/media-manager/scans'));
        $this->assertSame(201, $started_response->get_status());
        $started = $started_response->get_data()['scan'];
        $this->scan_refs[] = $started['scanRef'];
        $this->assertSame('scanning', $started['state']);
        $this->assertArrayNotHasKey('groups', $started);

        $next = new WP_REST_Request('POST', '/dbvc/v1/visual-editor/media-manager/scans/' . $started['scanRef'] . '/next');
        $next->set_param('generation', $started['generation']);
        $next->set_param('expectedRevision', $started['revision']);
        $advanced_response = rest_get_server()->dispatch($next);
        $this->assertSame(200, $advanced_response->get_status());
        $advanced = $advanced_response->get_data()['scan'];
        $this->assertGreaterThan($started['revision'], $advanced['revision']);

        $list = new WP_REST_Request('GET', '/dbvc/v1/visual-editor/media-manager/scans/' . $started['scanRef']);
        $list->set_param('generation', $advanced['generation']);
        $list->set_param('expectedRevision', $advanced['revision']);
        $list->set_param('limit', 2);
        $list_response = rest_get_server()->dispatch($list);
        $this->assertSame(200, $list_response->get_status());
        $this->assertTrue($list_response->get_data()['ok']);
        $this->assertPrivateTargetKeysAbsent($list_response->get_data());

        $other_admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($other_admin);
        $_COOKIE['dbvc_visual_editor_mode'] = '1';
        $cross_user = rest_get_server()->dispatch($list);
        $this->assertSame(404, $cross_user->get_status());
        $this->assertSame('media_scan_expired_or_invalid', $cross_user->get_data()['code']);

        wp_set_current_user($this->administrator_id);
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '0');
        $disabled = rest_get_server()->dispatch(new WP_REST_Request('POST', '/dbvc/v1/visual-editor/media-manager/scans'));
        $this->assertGreaterThanOrEqual(401, $disabled->get_status());
    }

    public function test_addon_composition_registers_the_media_manager_route_family(): void
    {
        $addon = new \Dbvc\VisualEditor\Bootstrap\Addon(
            dirname(__DIR__, 2) . '/addons/visual-editor/bootstrap.php'
        );
        $addon->register();
        do_action('rest_api_init');

        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans', $routes);
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans/latest', $routes);
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/next', $routes);
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/retry', $routes);
        $this->assertArrayHasKey('/dbvc/v1/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/cancel', $routes);
        $addon->unregister();
    }

    /**
     * @param int $chunk_size
     * @return array<string, object>
     */
    private function services($chunk_size)
    {
        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $catalog = new \Dbvc\VisualEditor\MediaManager\AcfMediaFieldCatalog($policy);
        $provider = new \Dbvc\VisualEditor\MediaManager\ScanCandidateProvider($policy, 3);
        $store = new \Dbvc\VisualEditor\MediaManager\ScanSnapshotStore(600, 1048576);
        $scanner = new \Dbvc\VisualEditor\MediaManager\MediaScanService(
            $catalog,
            new \Dbvc\VisualEditor\MediaManager\MediaAssignmentValueClassifier(),
            static function ($selector, $object_id) {
                $callback = $GLOBALS['dbvc_test_acf_value_reader'] ?? null;
                if (is_callable($callback)) {
                    return call_user_func($callback, $selector, $object_id);
                }
                $key = (string) $object_id . '|' . (string) $selector;

                return array_key_exists($key, $GLOBALS['dbvc_test_acf_values'] ?? [])
                    ? $GLOBALS['dbvc_test_acf_values'][$key]
                    : null;
            }
        );
        $coordinator = new \Dbvc\VisualEditor\MediaManager\MediaScanCoordinator(
            $provider,
            $scanner,
            $store,
            $catalog,
            $chunk_size
        );
        $read_model = new \Dbvc\VisualEditor\MediaManager\MediaScanReadModel($coordinator, $scanner, $policy);
        $edit_mode = new \Dbvc\VisualEditor\Context\EditModeState(
            $capabilities,
            new \Dbvc\VisualEditor\Context\PageContextResolver()
        );
        $registry = new \Dbvc\VisualEditor\Registry\EditableRegistry(
            new \Dbvc\VisualEditor\Context\PageContextResolver()
        );
        $descriptor_bridge = new \Dbvc\VisualEditor\MediaManager\MediaFindingDescriptorBridge(
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
        $assignment_service = new \Dbvc\VisualEditor\MediaManager\MediaAssignmentService(
            $descriptor_bridge,
            $mutations,
            $read_model
        );
        $controller = new \Dbvc\VisualEditor\Rest\Controllers\MediaManagerController(
            $coordinator,
            $read_model,
            $edit_mode,
            $capabilities,
            $descriptor_bridge,
            $assignment_service
        );

        return compact('store', 'scanner', 'coordinator', 'read_model', 'controller', 'registry', 'descriptor_bridge', 'assignment_service');
    }

    /**
     * @param \Dbvc\VisualEditor\MediaManager\MediaScanCoordinator $coordinator
     * @return array<string, mixed>
     */
    private function completeScan($coordinator)
    {
        $snapshot = $coordinator->start();
        $this->assertNotWPError($snapshot);
        $this->scan_refs[] = $snapshot['scan_ref'];
        $iterations = 0;

        while ($snapshot['state'] === 'scanning' && $iterations < 100) {
            $snapshot = $coordinator->runNextChunk(
                $snapshot['scan_ref'],
                $snapshot['generation'],
                $snapshot['revision']
            );
            $this->assertNotWPError($snapshot);
            $iterations++;
        }

        $this->assertSame('complete', $snapshot['state']);
        $this->assertLessThan(100, $iterations);

        return $snapshot;
    }

    /**
     * @return array<string, int>
     */
    private function seedMediaEntities()
    {
        $alpha = self::factory()->post->create([
            'post_type' => 'dbvc_mm_read',
            'post_status' => 'publish',
            'post_title' => 'Alpha empty media',
        ]);
        $beta = self::factory()->post->create([
            'post_type' => 'dbvc_mm_read',
            'post_status' => 'publish',
            'post_title' => 'Beta gallery gap',
        ]);
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'dbvc_mm_read_tax',
            'name' => 'Gamma term media',
        ]);

        $GLOBALS['dbvc_test_acf_values'] = [
            $alpha . '|field_read_image' => null,
            $alpha . '|field_read_group' => ['gallery' => []],
            $beta . '|field_read_image' => 44,
            $beta . '|field_read_group' => ['gallery' => []],
            'dbvc_mm_read_tax_' . $term->term_id . '|field_read_image' => null,
            'dbvc_mm_read_tax_' . $term->term_id . '|field_read_group' => ['gallery' => []],
        ];

        return [
            'alpha' => $alpha,
            'beta' => $beta,
            'term' => $term->term_id,
        ];
    }

    /**
     * @return void
     */
    private function seedAcfDefinitions(): void
    {
        $GLOBALS['dbvc_test_acf_groups'] = [[
            'key' => 'group_read_media',
            'title' => 'Read Media',
            'active' => true,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'dbvc_mm_read']]],
        ]];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_read_media' => [
                ['key' => 'field_read_image', 'name' => 'hero_image', 'label' => 'Hero image', 'type' => 'image'],
                [
                    'key' => 'field_read_group',
                    'name' => 'media_group',
                    'label' => 'Media group',
                    'type' => 'group',
                    'sub_fields' => [
                        ['key' => 'field_read_gallery', 'name' => 'gallery', 'label' => 'Gallery', 'type' => 'gallery'],
                    ],
                ],
            ],
        ];
        $GLOBALS['dbvc_test_acf_group_visibility'] = static function ($group, $screen) {
            return ($group['key'] ?? '') === 'group_read_media'
                && (($screen['post_type'] ?? '') === 'dbvc_mm_read'
                    || ($screen['taxonomy'] ?? '') === 'dbvc_mm_read_tax');
        };
        $GLOBALS['dbvc_test_acf_values'] = [];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param string               $label
     * @return string
     */
    private function groupRefForLabel(array $snapshot, $label)
    {
        foreach ($snapshot['groups'] as $group_ref => $group) {
            if (($group['entity_label'] ?? '') === $label) {
                return (string) $group_ref;
            }
        }

        $this->fail('Expected media scan group was not found.');

        return '';
    }

    /**
     * @param mixed $value
     * @return void
     */
    private function assertPrivateTargetKeysAbsent($value): void
    {
        if (! is_array($value)) {
            return;
        }

        $private_keys = [
            'id',
            'subtype',
            'owner',
            'field',
            'field_key',
            'field_name',
            'selector',
            'path',
            'empty_fingerprint',
            'config_fingerprint',
            'groups',
            'sources',
            'acf_object_id',
        ];
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $this->assertNotContains($key, $private_keys, 'Private target key leaked: ' . $key);
            }
            $this->assertPrivateTargetKeysAbsent($child);
        }
    }
}
