<?php

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\FrontendRuntimeGuard;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlProvider;
use Dbvc\VisualEditor\Registry\ControlRecord;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider;
use Dbvc\VisualEditor\Rest\Controllers\ControlCenterListController;
use Dbvc\VisualEditor\Rest\Controllers\ControlCenterValueSummariesController;

/**
 * R4-A — expanded Brand Control Center backend.
 *
 * Covers the four surfaces R4-A adds on top of R3's stack:
 *   1. ControlRecord widening — `description` + `sortKey` land on the safe
 *      list projection when set; empty when the provider does not opt in.
 *   2. ControlRegistry sort — `sortKey ASC` wins over label alpha; ties fall
 *      through to label ASC then publicId ASC as a deterministic tiebreaker.
 *   3. ControlRegistry provider-error capture — a provider that throws in
 *      `getControls()` is captured in `getProviderErrors()` and its records
 *      are dropped without shielding the rest of the map.
 *   4. ControlRegistry filter widening — `family` prunes by fieldFamily; `q`
 *      is case-insensitive substring against label OR description.
 *   5. SharedGlobalsControlProvider — description filter runs, ACF's
 *      `instructions` fallback works, and `buildValueSummary` returns the
 *      relationship shape / null-when-empty / null-when-cap-denied contract.
 *   6. ControlCenterListController — new params round-trip; providerErrors
 *      reaches the response; viewModelVersion bumped from 1→2.
 *   7. ControlCenterValueSummariesController — batch endpoint returns
 *      per-record summaries, caps at 50, drops duplicates, fails soft per
 *      record (unknown / gated / provider null returns null).
 *
 * No ACF loaded; every provider seam is a closure that returns a fixture.
 */
final class VisualEditorControlCenterR4ATest extends WP_UnitTestCase
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
        remove_all_filters('dbvc_visual_editor_control_center_description');
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // 1. ControlRecord widening
    // ---------------------------------------------------------------------

    public function test_control_record_projection_carries_description_and_sort_key_when_provider_sets_them(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            [
                'id' => 'described',
                'label' => 'Described',
                'description' => 'Short helper text',
                'sortKey' => 'zzz_last',
            ],
        ]));

        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('Short helper text', $items[0]['description']);
        $this->assertSame('zzz_last', $items[0]['sortKey']);
    }

    public function test_control_record_projection_defaults_description_and_sort_key_to_empty(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            ['id' => 'silent', 'label' => 'Silent'],
        ]));

        $items = $registry->listControls();
        $this->assertSame('', $items[0]['description']);
        $this->assertSame('', $items[0]['sortKey']);
    }

    // ---------------------------------------------------------------------
    // 2. ControlRegistry sort — sortKey wins over label alpha
    // ---------------------------------------------------------------------

    public function test_registry_sort_puts_records_with_sort_key_ahead_and_falls_through_to_label(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            // Alphabetical by label would put "Alpha" first — sortKey wins.
            ['id' => 'alpha', 'label' => 'Alpha', 'sortKey' => 'z_alpha'],
            ['id' => 'beta', 'label' => 'Beta', 'sortKey' => 'a_beta'],
            // No sortKey — falls through to label, then publicId.
            ['id' => 'gamma_two', 'label' => 'Gamma'],
            ['id' => 'gamma_one', 'label' => 'Gamma'],
        ]));

        $public_ids = array_column($registry->listControls(), 'publicId');
        $this->assertSame(
            // Empty sortKey ('' < 'a_beta' < 'z_alpha') plus label tiebreak:
            // Gamma × 2 tie on label → publicId ASC ties them (gamma_one, gamma_two).
            [
                'fixture:gamma_one',
                'fixture:gamma_two',
                'fixture:beta',
                'fixture:alpha',
            ],
            $public_ids
        );
    }

    // ---------------------------------------------------------------------
    // 3. ControlRegistry provider-error capture
    // ---------------------------------------------------------------------

    public function test_registry_captures_throwing_provider_without_shielding_others(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            ['id' => 'ok', 'label' => 'OK'],
        ]));
        $registry->registerProvider($this->makeThrowingProvider('breaks_things', 'Boom'));

        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('fixture:ok', $items[0]['publicId']);

        $errors = $registry->getProviderErrors();
        $this->assertArrayHasKey('breaks_things', $errors);
        $this->assertSame('Boom', $errors['breaks_things']['message']);
        $this->assertArrayNotHasKey('fixture', $errors);
    }

    public function test_registry_clears_provider_errors_between_passes(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeThrowingProvider('flaky', 'oops'));

        $registry->listControls();
        $this->assertArrayHasKey('flaky', $registry->getProviderErrors());

        // Second pass on the same provider (still throwing) — the map is
        // cleared+repopulated, not appended, so exactly one entry stands.
        $registry->listControls();
        $errors = $registry->getProviderErrors();
        $this->assertCount(1, $errors);
        $this->assertArrayHasKey('flaky', $errors);
    }

    // ---------------------------------------------------------------------
    // 4. ControlRegistry filter widening
    // ---------------------------------------------------------------------

    public function test_registry_family_filter_prunes_by_field_family(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            ['id' => 'r_one', 'label' => 'R One', 'fieldFamily' => 'relationship'],
            ['id' => 'p_one', 'label' => 'P One', 'fieldFamily' => 'post_object'],
            ['id' => 't_one', 'label' => 'T One', 'fieldFamily' => 'text'],
        ]));

        $rels = $registry->listControls(['family' => 'relationship']);
        $this->assertCount(1, $rels);
        $this->assertSame('fixture:r_one', $rels[0]['publicId']);
    }

    public function test_registry_q_filter_matches_label_and_description_case_insensitively(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            ['id' => 'branding_palette', 'label' => 'Palette', 'description' => 'Brand color set'],
            ['id' => 'typography_heading', 'label' => 'Heading', 'description' => 'Font stack for h1..h6'],
            ['id' => 'noise', 'label' => 'Miscellaneous', 'description' => ''],
        ]));

        $on_label = $registry->listControls(['q' => 'HEAD']);
        $this->assertCount(1, $on_label);
        $this->assertSame('fixture:typography_heading', $on_label[0]['publicId']);

        $on_description = $registry->listControls(['q' => 'brand']);
        $this->assertCount(1, $on_description);
        $this->assertSame('fixture:branding_palette', $on_description[0]['publicId']);

        $miss = $registry->listControls(['q' => 'xyzzy']);
        $this->assertSame([], $miss);
    }

    // ---------------------------------------------------------------------
    // 5. SharedGlobalsControlProvider R4-A emissions
    // ---------------------------------------------------------------------

    public function test_shared_globals_description_filter_wins_over_acf_instructions_fallback(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['settings_globals_default_posts'],
            [
                'settings_globals_default_posts' => [
                    'name' => 'settings_globals_default_posts',
                    'key' => 'field_default_posts',
                    'type' => 'relationship',
                    'label' => 'Default Posts',
                    'parent' => '',
                    'instructions' => 'ACF stock instructions',
                ],
            ]
        );

        add_filter('dbvc_visual_editor_control_center_description', function ($default, $context) {
            $this->assertSame('shared_globals', $context['providerId']);
            $this->assertSame('settings_globals_default_posts', $context['fieldName']);
            $this->assertSame('relationship', $context['fieldType']);
            return 'Vertical purpose text';
        }, 10, 2);

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertCount(1, $items);
        $this->assertSame('Vertical purpose text', $items[0]['description']);
        $this->assertSame('shared_settings_globals_default_posts', $items[0]['sortKey']);
    }

    public function test_shared_globals_description_falls_back_to_acf_instructions_when_no_filter(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['settings_globals_default_posts'],
            [
                'settings_globals_default_posts' => [
                    'name' => 'settings_globals_default_posts',
                    'key' => 'field_default_posts',
                    'type' => 'relationship',
                    'label' => 'Default Posts',
                    'parent' => '',
                    'instructions' => 'Curated by editors weekly',
                ],
            ]
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $items = $registry->listControls();

        $this->assertSame('Curated by editors weekly', $items[0]['description']);
    }

    public function test_shared_globals_value_summary_returns_relationship_shape(): void
    {
        $post_ids = self::factory()->post->create_many(4, ['post_status' => 'publish']);
        $provider = $this->makeSharedGlobalsProvider(
            ['settings_globals_default_posts'],
            [
                'settings_globals_default_posts' => [
                    'name' => 'settings_globals_default_posts',
                    'key' => 'field_default_posts',
                    'type' => 'relationship',
                    'label' => 'Default Posts',
                    'parent' => '',
                ],
            ],
            static function ($name) use ($post_ids) {
                return $name === 'settings_globals_default_posts' ? $post_ids : null;
            }
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $record = $registry->getVisibleRecord('shared_globals:settings_globals_default_posts');
        $this->assertNotNull($record);

        $summary = $registry->buildValueSummaryForRecord($record, $this->session_id);
        $this->assertIsArray($summary);
        $this->assertSame('relationship', $summary['family']);
        $this->assertSame(4, $summary['count']);
        $this->assertCount(3, $summary['firstTitles']);
        $this->assertTrue($summary['hasMore']);
    }

    public function test_shared_globals_value_summary_null_when_value_empty(): void
    {
        $provider = $this->makeSharedGlobalsProvider(
            ['settings_globals_default_posts'],
            [
                'settings_globals_default_posts' => [
                    'name' => 'settings_globals_default_posts',
                    'key' => 'field_default_posts',
                    'type' => 'relationship',
                    'label' => 'Default Posts',
                    'parent' => '',
                ],
            ],
            static function () {
                return [];
            }
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        $record = $registry->getVisibleRecord('shared_globals:settings_globals_default_posts');
        $this->assertNotNull($record);
        $this->assertNull($registry->buildValueSummaryForRecord($record, $this->session_id));
    }

    public function test_shared_globals_value_summary_null_when_capability_denied(): void
    {
        $post_ids = self::factory()->post->create_many(2, ['post_status' => 'publish']);
        $provider = $this->makeSharedGlobalsProvider(
            ['settings_globals_default_posts'],
            [
                'settings_globals_default_posts' => [
                    'name' => 'settings_globals_default_posts',
                    'key' => 'field_default_posts',
                    'type' => 'relationship',
                    'label' => 'Default Posts',
                    'parent' => '',
                ],
            ],
            static function ($name) use ($post_ids) {
                return $name === 'settings_globals_default_posts' ? $post_ids : null;
            }
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);
        // Drop the admin so the option-owned capability probe fails. The
        // list-time visibility gate would already prune the record, so bypass
        // that by resolving via the registry as admin, then swap the user
        // right before minting the summary. (Simpler seam is to hand the
        // record straight to the provider.)
        $record = $registry->getVisibleRecord('shared_globals:settings_globals_default_posts');
        $this->assertNotNull($record);

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertNull($registry->buildValueSummaryForRecord($record, $this->session_id));
    }

    // ---------------------------------------------------------------------
    // 6. ControlCenterListController — R4-A response contract
    // ---------------------------------------------------------------------

    public function test_list_controller_view_model_version_is_two_and_echoes_all_four_query_params(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            [
                'id' => 'one',
                'label' => 'One',
                'category' => 'globals',
                'fieldFamily' => 'relationship',
            ],
        ]));

        $controller = new ControlCenterListController(
            $registry,
            new EditableRegistry(new PageContextResolver(), null),
            new EditModeState(new CapabilityManager(), new PageContextResolver(), new FrontendRuntimeGuard()),
            new CapabilityManager()
        );

        $response = $controller->handle($this->makeListRequest($this->session_id, [
            'category' => 'globals',
            'status' => 'available',
            'family' => 'relationship',
            'q' => 'On',
        ]));

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        // R5.7-b bumped 2→3 to signal parentPublicId schema addition.
        $this->assertSame(3, $body['viewModelVersion']);
        $this->assertSame(
            ['category' => 'globals', 'status' => 'available', 'family' => 'relationship', 'q' => 'On'],
            $body['query']
        );
        $this->assertCount(1, $body['items']);
        $this->assertSame([], $body['providerErrors']);
    }

    public function test_list_controller_surfaces_provider_errors_alongside_partial_items(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            ['id' => 'kept', 'label' => 'Kept'],
        ]));
        $registry->registerProvider($this->makeThrowingProvider('boomy', 'kaboom'));

        $controller = new ControlCenterListController(
            $registry,
            new EditableRegistry(new PageContextResolver(), null),
            new EditModeState(new CapabilityManager(), new PageContextResolver(), new FrontendRuntimeGuard()),
            new CapabilityManager()
        );

        $body = $controller->handle($this->makeListRequest($this->session_id))->get_data();
        $this->assertCount(1, $body['items']);
        $this->assertSame('fixture:kept', $body['items'][0]['publicId']);
        $this->assertArrayHasKey('boomy', $body['providerErrors']);
        $this->assertSame('kaboom', $body['providerErrors']['boomy']['message']);
    }

    public function test_list_controller_clamps_q_at_128_chars(): void
    {
        $registry = new ControlRegistry();
        $registry->registerProvider($this->makeFixtureProvider([
            ['id' => 'ok', 'label' => 'OK'],
        ]));

        $controller = new ControlCenterListController(
            $registry,
            new EditableRegistry(new PageContextResolver(), null),
            new EditModeState(new CapabilityManager(), new PageContextResolver(), new FrontendRuntimeGuard()),
            new CapabilityManager()
        );

        $long = str_repeat('a', 200);
        $body = $controller->handle($this->makeListRequest($this->session_id, ['q' => $long]))->get_data();
        $this->assertSame(128, strlen($body['query']['q']));
    }

    // ---------------------------------------------------------------------
    // 7. ControlCenterValueSummariesController — batch endpoint
    // ---------------------------------------------------------------------

    public function test_value_summaries_controller_returns_map_of_summaries(): void
    {
        $post_ids = self::factory()->post->create_many(2, ['post_status' => 'publish']);
        $provider = $this->makeSharedGlobalsProvider(
            ['settings_globals_default_posts'],
            [
                'settings_globals_default_posts' => [
                    'name' => 'settings_globals_default_posts',
                    'key' => 'field_default_posts',
                    'type' => 'relationship',
                    'label' => 'Default Posts',
                    'parent' => '',
                ],
            ],
            static function ($name) use ($post_ids) {
                return $name === 'settings_globals_default_posts' ? $post_ids : null;
            }
        );

        $registry = new ControlRegistry();
        $registry->registerProvider($provider);

        $controller = new ControlCenterValueSummariesController(
            $registry,
            new EditableRegistry(new PageContextResolver(), null),
            new EditModeState(new CapabilityManager(), new PageContextResolver(), new FrontendRuntimeGuard()),
            new CapabilityManager()
        );

        $response = $controller->handle($this->makeSummariesRequest($this->session_id, [
            'shared_globals:settings_globals_default_posts',
            'shared_globals:unknown',
        ]));

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['ok']);
        $this->assertArrayHasKey('shared_globals:settings_globals_default_posts', $body['summaries']);
        $summary = $body['summaries']['shared_globals:settings_globals_default_posts'];
        $this->assertIsArray($summary);
        $this->assertSame('relationship', $summary['family']);
        $this->assertSame(2, $summary['count']);
        // Unknown public id fails soft to null in the same map — the drawer
        // renders nothing in that slot rather than removing the row.
        $this->assertArrayHasKey('shared_globals:unknown', $body['summaries']);
        $this->assertNull($body['summaries']['shared_globals:unknown']);
    }

    public function test_value_summaries_controller_rejects_batch_over_cap(): void
    {
        $registry = new ControlRegistry();
        $controller = new ControlCenterValueSummariesController(
            $registry,
            new EditableRegistry(new PageContextResolver(), null),
            new EditModeState(new CapabilityManager(), new PageContextResolver(), new FrontendRuntimeGuard()),
            new CapabilityManager()
        );

        $too_many = array_map(static function ($n) {
            return 'fixture:row_' . $n;
        }, range(1, 51));
        $response = $controller->handle($this->makeSummariesRequest($this->session_id, $too_many));
        $this->assertSame(400, $response->get_status());
    }

    public function test_value_summaries_controller_deduplicates_repeated_public_ids(): void
    {
        $provider = $this->makeFixtureProviderWithSummary([
            ['id' => 'row_a', 'label' => 'Row A'],
        ]);
        $registry = new ControlRegistry();
        $registry->registerProvider($provider);

        $controller = new ControlCenterValueSummariesController(
            $registry,
            new EditableRegistry(new PageContextResolver(), null),
            new EditModeState(new CapabilityManager(), new PageContextResolver(), new FrontendRuntimeGuard()),
            new CapabilityManager()
        );

        $body = $controller->handle($this->makeSummariesRequest($this->session_id, [
            'fixture:row_a',
            'fixture:row_a',
            'fixture:row_a',
        ]))->get_data();
        $this->assertCount(1, $body['summaries']);
        $this->assertSame(['tag' => 'row_a'], $body['summaries']['fixture:row_a']);
    }

    public function test_value_summaries_controller_returns_403_when_edit_mode_inactive(): void
    {
        unset($_COOKIE[self::COOKIE_NAME]);
        $registry = new ControlRegistry();
        $controller = new ControlCenterValueSummariesController(
            $registry,
            new EditableRegistry(new PageContextResolver(), null),
            new EditModeState(new CapabilityManager(), new PageContextResolver(), new FrontendRuntimeGuard()),
            new CapabilityManager()
        );

        $response = $controller->handle($this->makeSummariesRequest($this->session_id, ['fixture:anything']));
        $this->assertSame(403, $response->get_status());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function makeFixtureProvider(array $records): ControlProvider
    {
        return new class ($records) implements ControlProvider {
            /** @var array<int, array<string, mixed>> */
            private $records;
            public function __construct(array $records)
            {
                $this->records = $records;
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
                return null;
            }
            public function buildValueSummary(ControlRecord $record, $sessionId)
            {
                return null;
            }
        };
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function makeFixtureProviderWithSummary(array $records): ControlProvider
    {
        return new class ($records) implements ControlProvider {
            /** @var array<int, array<string, mixed>> */
            private $records;
            public function __construct(array $records)
            {
                $this->records = $records;
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
                // Provide a minimally-valid descriptor so the batch endpoint
                // gets past its capability recheck (option-owned descriptors
                // authorize under `manage_options`, which the admin has).
                return new EditableDescriptor(
                    've_summary_fixture_' . $record->id,
                    'editable',
                    'shared_entity',
                    [
                        'type' => 'option',
                        'id' => 0,
                        'subtype' => 'acf_options',
                        'acf_object_id' => 'option',
                    ],
                    [],
                    [],
                    [],
                    []
                );
            }
            public function buildValueSummary(ControlRecord $record, $sessionId)
            {
                return ['tag' => $record->id];
            }
        };
    }

    /**
     * @param string $providerId
     * @param string $message
     */
    private function makeThrowingProvider(string $providerId, string $message): ControlProvider
    {
        return new class ($providerId, $message) implements ControlProvider {
            /** @var string */
            private $providerId;
            /** @var string */
            private $message;
            public function __construct(string $providerId, string $message)
            {
                $this->providerId = $providerId;
                $this->message = $message;
            }
            public function getProviderId()
            {
                return $this->providerId;
            }
            public function getControls()
            {
                throw new \RuntimeException($this->message);
            }
            public function buildDescriptor(ControlRecord $record, $sessionId, array $pageContext)
            {
                return null;
            }
            public function buildValueSummary(ControlRecord $record, $sessionId)
            {
                return null;
            }
        };
    }

    /**
     * @param array<int, string>                       $configured_names
     * @param array<string, array<string, mixed>|bool> $field_objects
     * @param callable(string):mixed|null              $option_value_resolver
     */
    private function makeSharedGlobalsProvider(
        array $configured_names,
        array $field_objects,
        $option_value_resolver = null
    ): SharedGlobalsControlProvider {
        return new SharedGlobalsControlProvider(
            new CapabilityManager(),
            static function () use ($configured_names) {
                return $configured_names;
            },
            static function ($name) use ($field_objects) {
                return array_key_exists($name, $field_objects) ? $field_objects[$name] : null;
            },
            $option_value_resolver
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function makeListRequest(string $session_id, array $query = []): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/dbvc/v1/visual-editor/session/' . $session_id . '/control-center/controls');
        $request->set_url_params(['session_id' => $session_id]);
        foreach ($query as $key => $value) {
            $request->set_param($key, $value);
        }
        return $request;
    }

    /**
     * @param array<int, string> $public_ids
     */
    private function makeSummariesRequest(string $session_id, array $public_ids): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/visual-editor/session/' . $session_id . '/control-center/value-summaries');
        $request->set_url_params(['session_id' => $session_id]);
        $request->set_param('publicIds', $public_ids);
        return $request;
    }

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
}
