<?php

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\FrontendRuntimeGuard;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Presentation\DescriptorSummaryBuilder;
use Dbvc\VisualEditor\Registry\ControlProvider;
use Dbvc\VisualEditor\Registry\ControlRecord;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;
use Dbvc\VisualEditor\Rest\Controllers\ControlCenterListController;
use Dbvc\VisualEditor\Rest\Controllers\ControlCenterOpenController;
use Dbvc\VisualEditor\Rest\DescriptorPayloadBuilder;
use Dbvc\VisualEditor\Save\MutationContractService;

/**
 * R3-C-1 — Brand Control Center REST controllers.
 *
 * Exercises the two new session-scoped routes and the descriptor-factory
 * extraction against fixture data — no ACF is loaded, no live options are
 * toggled beyond the toggle-mode cookie the controllers need to consider the
 * request authorized. Fixture provider stands in for real Shared Globals /
 * Vertical providers so the tests focus on the controller-layer contract.
 */
final class VisualEditorControlCenterRoutesTest extends WP_UnitTestCase
{
    private const COOKIE_NAME = 'dbvc_visual_editor_mode';
    private const TRANSIENT_PREFIX = 'dbvc_visual_editor_session_';

    /**
     * @var int
     */
    private $administrator_id = 0;

    /**
     * @var string
     */
    private $session_id = '';

    public function set_up(): void
    {
        parent::set_up();
        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);
        $_COOKIE[self::COOKIE_NAME] = '1';
        $this->session_id = 'ves_' . strtolower(wp_generate_password(12, false, false));
        $this->seedSession($this->session_id, $this->administrator_id);
    }

    public function tear_down(): void
    {
        delete_transient(self::TRANSIENT_PREFIX . $this->session_id);
        unset($_COOKIE[self::COOKIE_NAME]);
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // ControlCenterListController
    // ---------------------------------------------------------------------

    public function test_list_happy_path_returns_registry_projection(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            $this->availableRecordArray('one', 'One'),
            $this->availableRecordArray('two', 'Two'),
        ]));

        $response = $this->buildListController($registry)->handle($this->makeRequest($this->session_id));
        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['ok']);
        // R4-A: viewModelVersion bumped from 1→2; query echo widened with
        // R4-A's `family` + `q` params. R5.7-b bumped 2→3 to signal
        // parentPublicId schema addition.
        $this->assertSame(3, $body['viewModelVersion']);
        $this->assertSame(
            ['category' => '', 'status' => '', 'family' => '', 'q' => ''],
            $body['query']
        );
        // R4-A: providerErrors is empty on a healthy pass; the R4-A suite
        // covers the populated-map case.
        $this->assertSame([], $body['providerErrors']);
        $public_ids = array_column($body['items'], 'publicId');
        sort($public_ids);
        $this->assertSame(['fixture:one', 'fixture:two'], $public_ids);
        // The safe projection must NOT include the internal source bag.
        $this->assertArrayNotHasKey('source', $body['items'][0]);
    }

    public function test_list_returns_403_when_edit_mode_is_inactive(): void
    {
        unset($_COOKIE[self::COOKIE_NAME]);
        $registry = new ControlRegistry();
        $response = $this->buildListController($registry)->handle($this->makeRequest($this->session_id));
        $this->assertSame(403, $response->get_status());
        $this->assertFalse($response->get_data()['ok']);
    }

    public function test_list_returns_404_when_session_expired(): void
    {
        delete_transient(self::TRANSIENT_PREFIX . $this->session_id);
        $registry = new ControlRegistry();
        $response = $this->buildListController($registry)->handle($this->makeRequest($this->session_id));
        $this->assertSame(404, $response->get_status());
    }

    // ---------------------------------------------------------------------
    // ControlCenterOpenController
    // ---------------------------------------------------------------------

    public function test_open_happy_path_returns_descriptor_and_hydration(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            $this->availableRecordArray('one', 'One'),
        ]));

        $response = $this->buildOpenController($registry)->handle(
            $this->makeRequest($this->session_id, ['publicId' => 'fixture:one'])
        );

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['ok']);
        $this->assertSame('fixture:one', $body['publicId']);
        $this->assertNotEmpty($body['descriptors']);
        $this->assertNotEmpty($body['descriptorHydrations']);
        $token = array_key_first($body['descriptors']);
        $this->assertArrayHasKey($token, $body['descriptorHydrations']);
        $this->assertTrue($body['descriptorHydrations'][$token]['ok']);
    }

    public function test_open_returns_404_for_unknown_public_id(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([]));
        $response = $this->buildOpenController($registry)->handle(
            $this->makeRequest($this->session_id, ['publicId' => 'fixture:no_such_thing'])
        );
        $this->assertSame(404, $response->get_status());
    }

    public function test_open_returns_404_for_malformed_public_id(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([]));
        $response = $this->buildOpenController($registry)->handle(
            $this->makeRequest($this->session_id, ['publicId' => 'not-a-valid-public-id'])
        );
        $this->assertSame(404, $response->get_status());
    }

    public function test_open_returns_404_when_provider_build_descriptor_returns_null(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider(
            $this->makeFixtureProvider(
                [$this->availableRecordArray('vanishing', 'Vanishing')],
                /* returnNullFor */ 'vanishing'
            )
        );
        $response = $this->buildOpenController($registry)->handle(
            $this->makeRequest($this->session_id, ['publicId' => 'fixture:vanishing'])
        );
        $this->assertSame(404, $response->get_status());
    }

    public function test_open_returns_403_when_descriptor_targets_only_excluded_post_types(): void
    {
        $registry = new ControlRegistry();
        // Provider hands back a descriptor whose source.reference_post_types is
        // deliberately empty — this is the same case SharedGlobalFieldsController
        // surfaces as a warning; the open route promotes it to a 403.
        $registry->registerProvider(
            $this->makeFixtureProvider(
                [$this->availableRecordArray('excluded', 'Excluded')],
                /* returnNullFor */ '',
                /* referencePostTypes */ []
            )
        );
        $response = $this->buildOpenController($registry)->handle(
            $this->makeRequest($this->session_id, ['publicId' => 'fixture:excluded'])
        );
        $this->assertSame(403, $response->get_status());
    }

    public function test_open_returns_404_when_session_expired(): void
    {
        delete_transient(self::TRANSIENT_PREFIX . $this->session_id);
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            $this->availableRecordArray('one', 'One'),
        ]));
        $response = $this->buildOpenController($registry)->handle(
            $this->makeRequest($this->session_id, ['publicId' => 'fixture:one'])
        );
        $this->assertSame(404, $response->get_status());
    }

    // ---------------------------------------------------------------------
    // SharedGlobalsDescriptorFactory extraction sanity
    // ---------------------------------------------------------------------

    // ---------------------------------------------------------------------
    // R5.later-perf-c (E-162 F3) — batch session attach
    // ---------------------------------------------------------------------

    public function test_perf_c_open_attaches_parent_and_pre_minted_leaves_in_one_session_write(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider(
            [$this->availableRecordArray('palette', 'Palette')],
            '',
            ['post'],
            ['swatch_a', 'swatch_b', '']
        ));

        $writes = $this->countSessionWrites();
        $response = $this->buildOpenController($registry)->handle(
            $this->makeRequest($this->session_id, ['publicId' => 'fixture:palette'])
        );

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['ok']);
        // Parent + the two tokened leaves are reported; the token-less leaf is skipped.
        $this->assertSame(
            ['ve_fixture_palette', 've_fixture_swatch_a', 've_fixture_swatch_b'],
            array_keys($body['descriptors'])
        );
        $this->assertSame(['ve_fixture_palette'], array_keys($body['descriptorHydrations']));

        // ONE transient write for the whole open (was 1 + one per leaf).
        $this->assertSame(1, $writes());

        // Everything the response reports is really in the session.
        $session = $this->buildEditableRegistry()->loadSession($this->session_id, true);
        $this->assertSame(
            ['ve_fixture_palette', 've_fixture_swatch_a', 've_fixture_swatch_b'],
            array_keys($session['descriptors'])
        );
        $this->assertSame(
            ['ve_fixture_palette', 've_fixture_swatch_a', 've_fixture_swatch_b'],
            array_keys($session['public_map'])
        );
    }

    public function test_perf_c_batch_attach_is_one_round_trip_with_per_descriptor_results(): void
    {
        $sessions = $this->buildEditableRegistry();
        $writes = $this->countSessionWrites();
        $one = $this->fixtureDescriptor('one');
        $two = $this->fixtureDescriptor('two');
        $none = $this->fixtureDescriptor('');

        $result = $sessions->addDescriptorsToSession($this->session_id, [$one, 'not a descriptor', $none, $two]);

        $this->assertSame(['ve_fixture_one' => true, 've_fixture_two' => true], $result);
        $this->assertSame(1, $writes());

        $session = $sessions->loadSession($this->session_id, true);
        $this->assertSame(['ve_fixture_one', 've_fixture_two'], array_keys($session['descriptors']));
        $this->assertSame(['ve_fixture_one', 've_fixture_two'], array_keys($session['public_map']));

        // The single form still works and still costs one write each.
        $this->assertTrue($sessions->addDescriptorToSession($this->session_id, $this->fixtureDescriptor('three')));
        $this->assertSame(2, $writes());
        $this->assertFalse($sessions->addDescriptorToSession($this->session_id, $none));
        $this->assertSame(2, $writes());

        // Later duplicates win, as with serial calls.
        $again = $this->fixtureDescriptor('one', 'Renamed');
        $this->assertSame(['ve_fixture_one' => true], $sessions->addDescriptorsToSession($this->session_id, [$one, $again]));
        $session = $sessions->loadSession($this->session_id, true);
        $this->assertSame('Renamed', $session['descriptors']['ve_fixture_one']['ui']['label']);
        $this->assertSame(3, $writes());
    }

    public function test_perf_c_batch_attach_refuses_everything_without_writing_when_session_is_missing(): void
    {
        $sessions = $this->buildEditableRegistry();
        $writes = $this->countSessionWrites();

        $this->assertSame([], $sessions->addDescriptorsToSession($this->session_id, []));
        $this->assertSame(
            ['ve_fixture_one' => false, 've_fixture_two' => false],
            $sessions->addDescriptorsToSession('ves_no_such_session', [$this->fixtureDescriptor('one'), $this->fixtureDescriptor('two')])
        );
        $this->assertSame(['ve_fixture_one' => false], $sessions->addDescriptorsToSession('', [$this->fixtureDescriptor('one')]));

        // Another user's session is refused the same way (loadSession fails closed).
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame(['ve_fixture_one' => false], $sessions->addDescriptorsToSession($this->session_id, [$this->fixtureDescriptor('one')]));
        $this->assertSame(0, $writes());
    }

    public function test_extracted_factory_produces_descriptor_with_expected_shared_globals_shape(): void
    {
        $factory = new SharedGlobalsDescriptorFactory();
        $field = [
            'name' => 'settings_globals_default_posts',
            'key' => 'field_default_posts',
            'type' => 'relationship',
            'label' => 'Default Posts',
            'parent' => '',
            'post_type' => ['post'],
            'multiple' => true,
            'min' => 0,
            'max' => 0,
        ];

        $descriptor = $factory->build('ves_abc123', ['entityId' => 0], $field);

        $this->assertInstanceOf(EditableDescriptor::class, $descriptor);
        $this->assertStringStartsWith('ve_', $descriptor->token);
        $this->assertSame('editable', $descriptor->status);
        $this->assertSame('shared_entity', $descriptor->scope);
        $this->assertSame('option', $descriptor->entity['type']);
        $this->assertSame('acf_options', $descriptor->entity['subtype']);
        $this->assertSame('option', $descriptor->entity['acf_object_id']);
        $this->assertSame('acf_collection_field', $descriptor->source['type']);
        $this->assertSame('settings_globals_default_posts', $descriptor->source['field_name']);
        $this->assertSame('field_default_posts', $descriptor->source['field_key']);
        $this->assertSame('relationship', $descriptor->source['field_type']);
        $this->assertSame('shared_relationship_collection', $descriptor->mutation['contract']);
        $this->assertTrue($descriptor->source['reference_multiple']);
        $this->assertSame('reference_collection', $descriptor->ui['input']);
        $this->assertSame(__('Shared Global', 'dbvc'), $descriptor->ui['badgeLabel']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function buildListController(ControlRegistry $registry): ControlCenterListController
    {
        return new ControlCenterListController(
            $registry,
            $this->buildEditableRegistry(),
            $this->buildEditModeState(),
            new CapabilityManager()
        );
    }

    private function buildOpenController(ControlRegistry $registry): ControlCenterOpenController
    {
        return new ControlCenterOpenController(
            $registry,
            $this->buildEditableRegistry(),
            $this->buildEditModeState(),
            new CapabilityManager(),
            $this->buildDescriptorPayloadBuilder()
        );
    }

    private function buildEditableRegistry(): EditableRegistry
    {
        return new EditableRegistry(new PageContextResolver(), null);
    }

    private function buildEditModeState(): EditModeState
    {
        return new EditModeState(new CapabilityManager(), new PageContextResolver(), new FrontendRuntimeGuard());
    }

    private function buildDescriptorPayloadBuilder(): DescriptorPayloadBuilder
    {
        $capabilities = new CapabilityManager();
        return new DescriptorPayloadBuilder(
            new ResolverRegistry(),
            $capabilities,
            new DescriptorSummaryBuilder(),
            new MutationContractService(),
            null
        );
    }

    /**
     * @param string               $session_id
     * @param array<string, mixed> $query_or_body
     */
    private function makeRequest($session_id, array $query_or_body = []): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/dbvc/v1/visual-editor/session/' . $session_id . '/control-center/controls');
        $request->set_url_params(['session_id' => $session_id]);
        foreach ($query_or_body as $key => $value) {
            $request->set_param($key, $value);
        }
        return $request;
    }

    /**
     * @param int $user_id
     */
    private function seedSession(string $session_id, int $user_id): void
    {
        set_transient(
            self::TRANSIENT_PREFIX . sanitize_key($session_id),
            [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'page_context' => [],
                'public_map' => [],
                'created_at' => time(),
            ],
            HOUR_IN_SECONDS
        );
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param string                            $returnNullFor  Local id whose buildDescriptor
     *                                                          returns null (simulates a record
     *                                                          that vanished between list and open).
     * @param array<int, string>                $referencePostTypes Post types the fixture descriptor
     *                                                          exposes; empty exercises the 403 path.
     */
    private function makeFixtureProvider(
        array $records,
        string $returnNullFor = '',
        array $referencePostTypes = ['post'],
        array $leafIds = []
    ): ControlProvider {
        return new class ($records, $returnNullFor, $referencePostTypes, $leafIds) implements ControlProvider {
            /** @var array<int, array<string, mixed>> */
            private $records;
            /** @var string */
            private $returnNullFor;
            /** @var array<int, string> */
            private $referencePostTypes;
            /**
             * R5.later-perf-c: when non-empty, every minted descriptor carries
             * `source.leaves[i].descriptor` = a pre-minted leaf descriptor per
             * id (R5.later-y-3's palette-parent shape); an empty-string id
             * yields a leaf entry without a token.
             *
             * @var array<int, string>
             */
            private $leafIds;

            public function __construct(array $records, string $returnNullFor, array $referencePostTypes, array $leafIds)
            {
                $this->records = $records;
                $this->returnNullFor = $returnNullFor;
                $this->referencePostTypes = $referencePostTypes;
                $this->leafIds = $leafIds;
            }

            public function getProviderId()
            {
                return 'fixture';
            }

            public function getControls()
            {
                return $this->records;
            }

            public function buildDescriptor(ControlRecord $record, $sessionId, array $pageContext)
            {
                if ($this->returnNullFor !== '' && $record->id === $this->returnNullFor) {
                    return null;
                }

                $source_extra = [];
                if (! empty($this->leafIds)) {
                    $source_extra['leaves'] = [];
                    foreach ($this->leafIds as $leaf_id) {
                        $leaf = $this->mint((string) $leaf_id, $record->label . ' leaf ' . $leaf_id, $pageContext, []);
                        $source_extra['leaves'][] = ['fieldKey' => 'field_' . $leaf_id, 'descriptor' => $leaf->toArray()];
                    }
                }

                return $this->mint($record->id, $record->label, $pageContext, $source_extra);
            }

            /**
             * @param array<string, mixed> $pageContext
             * @param array<string, mixed> $sourceExtra
             */
            private function mint(string $id, string $label, array $pageContext, array $sourceExtra): EditableDescriptor
            {
                $record = (object) ['id' => $id, 'label' => $label];

                return new EditableDescriptor(
                    $id === '' ? '' : 've_fixture_' . $record->id,
                    'editable',
                    'shared_entity',
                    [
                        'type' => 'option',
                        'id' => 0,
                        'subtype' => 'acf_options',
                        'acf_object_id' => 'option',
                    ],
                    [
                        'context' => 'query_collection',
                        'attribute' => 'toolbar_shared_global',
                        'element_id' => 'fixture-' . $record->id,
                        'display_key' => 'default',
                        'sync_group' => 'option:' . $record->id,
                        'source_group' => 'option:' . $record->id,
                    ],
                    array_merge([
                        'type' => 'acf_collection_field',
                        'source_context' => 'toolbar_shared_global_option',
                        'field_name' => $record->id,
                        'field_key' => 'field_' . $record->id,
                        'field_type' => 'relationship',
                        'reference_post_types' => $this->referencePostTypes,
                        'reference_multiple' => true,
                        'query_collection_write_mode' => 'replace_full_collection',
                        'query_result_ids' => [],
                        'query_full_value_ids' => [],
                        'query_preserved_ids' => [],
                        'query_result_empty' => true,
                    ], $sourceExtra),
                    [
                        'label' => $record->label,
                        'badgeLabel' => 'Fixture',
                        'input' => 'reference_collection',
                    ],
                    [
                        'name' => 'acf_reference_collection',
                    ],
                    $pageContext,
                    [],
                    [],
                    [
                        'fieldName' => $record->id,
                        'fieldKey' => 'field_' . $record->id,
                        'rootFieldName' => $record->id,
                        'rootFieldKey' => 'field_' . $record->id,
                    ],
                    [
                        'version' => 1,
                        'kind' => 'collection',
                        'target' => 'field',
                        'contract' => 'shared_relationship_collection',
                        'renderContext' => 'query_collection',
                        'reloadAfterSave' => true,
                    ]
                );
            }

            // R4-A: interface widened. The routes tests exercise the R3-C-1
            // list + open surfaces, not the R4-A value-summary batch endpoint,
            // so a null stub is enough here — the dedicated
            // VisualEditorControlCenterR4ATest covers value summaries.
            public function buildValueSummary(ControlRecord $record, $sessionId)
            {
                return null;
            }
        };
    }

    /**
     * R5.later-perf-c — counts `set_transient()` calls for THIS test's
     * session key from the moment it is installed. Returns a closure that
     * yields the running count.
     *
     * @return callable(): int
     */
    private function countSessionWrites(): callable
    {
        $count = 0;
        $key = self::TRANSIENT_PREFIX . sanitize_key($this->session_id);
        add_action('set_transient_' . $key, static function () use (&$count) {
            $count++;
        }, 10, 0);

        return static function () use (&$count) {
            return $count;
        };
    }

    /**
     * R5.later-perf-c — a fixture descriptor minted through the same anonymous
     * provider the routes use, so its shape passes the open route's checks.
     */
    private function fixtureDescriptor(string $id, string $label = ''): EditableDescriptor
    {
        $provider = $this->makeFixtureProvider([], '', ['post'], $id === '' ? [''] : []);
        $record = ControlRecord::fromArray($this->availableRecordArray($id === '' ? 'seed' : $id, $label !== '' ? $label : ucfirst($id === '' ? 'seed' : $id)), 'fixture');
        $descriptor = $provider->buildDescriptor($record, $this->session_id, []);
        if ($id !== '') {
            return $descriptor;
        }

        // The token-less variant is the first pre-minted leaf of the seed.
        return EditableDescriptor::fromArray($descriptor->source['leaves'][0]['descriptor']);
    }

    /**
     * @return array<string, mixed>
     */
    private function availableRecordArray(string $id, string $label): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'category' => 'globals',
            'group' => 'Fixture',
            'ownerType' => 'option',
            'ownerSubtype' => 'acf_options',
            'fieldFamily' => 'relationship',
            'status' => 'available',
            'source' => ['field_name' => $id, 'field_key' => 'field_' . $id],
            'meta' => ['badge' => 'Fixture'],
        ];
    }
}
