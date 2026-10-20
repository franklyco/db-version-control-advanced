<?php

/**
 * R3-B — Shared Globals compatibility provider.
 *
 * Discovery-only provider that adapts the existing `SharedGlobalFieldsController`
 * field-enumeration path onto the R3-A {@see \Dbvc\VisualEditor\Registry\ControlRegistry}.
 * Verifies the provider's field-type filter, unresolvable-name handling, the
 * shape of a happy-path record, per-user visibility (mirrors
 * `canManageSharedGlobalOptions`), and that a bare registry with no provider
 * still lists nothing (the R3-A baseline stays intact).
 *
 * Tests inject fixtures through the provider's two callable seams — no ACF
 * is loaded, no live option is toggled, and no content is mutated.
 */
final class VisualEditorSharedGlobalsControlProviderTest extends WP_UnitTestCase
{
    public function test_happy_path_registers_relationship_and_post_object_records_with_expected_shape(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $provider = $this->makeProvider(
            ['settings_globals_default_posts', 'settings_globals_default_author'],
            [
                'settings_globals_default_posts' => [
                    'name' => 'settings_globals_default_posts',
                    'key' => 'field_default_posts',
                    'type' => 'relationship',
                    'label' => 'Default Posts',
                    'parent' => '',
                ],
                'settings_globals_default_author' => [
                    'name' => 'settings_globals_default_author',
                    'key' => 'field_default_author',
                    'type' => 'post_object',
                    'label' => 'Default Author',
                    'parent' => '',
                ],
            ]
        );

        $this->assertTrue($registry->registerProvider($provider));

        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);

        $items = $registry->listControls();
        $this->assertCount(2, $items);

        $by_public_id = [];
        foreach ($items as $item) {
            $by_public_id[$item['publicId']] = $item;
        }

        $this->assertArrayHasKey('shared_globals:settings_globals_default_author', $by_public_id);
        $this->assertArrayHasKey('shared_globals:settings_globals_default_posts', $by_public_id);

        $posts = $by_public_id['shared_globals:settings_globals_default_posts'];
        $this->assertSame('Default Posts', $posts['label']);
        $this->assertSame('globals', $posts['category']);
        $this->assertSame('option', $posts['ownerType']);
        $this->assertSame('acf_options', $posts['ownerSubtype']);
        $this->assertSame('relationship', $posts['fieldFamily']);
        $this->assertSame('available', $posts['status']);
        $this->assertArrayHasKey('meta', $posts);
        $this->assertSame(__('Shared Global', 'dbvc'), $posts['meta']['badge']);
        // The list projection never carries the internal source hint.
        $this->assertArrayNotHasKey('source', $posts);

        $author = $by_public_id['shared_globals:settings_globals_default_author'];
        $this->assertSame('post_object', $author['fieldFamily']);
        $this->assertSame('Default Author', $author['label']);

        // The internal record accessor still carries the source bag so R3-C can
        // hand the provider back its own opaque hint at open time.
        $internal = $registry->getVisibleRecord('shared_globals:settings_globals_default_posts');
        $this->assertNotNull($internal);
        $this->assertSame(
            ['field_name' => 'settings_globals_default_posts', 'field_key' => 'field_default_posts'],
            $internal->source
        );
    }

    public function test_unsupported_field_type_is_skipped(): void
    {
        // Every family with an existing resolver + panel controller is
        // now wired. The remaining un-widened ACF field type is
        // `taxonomy` — a real type outside every supported list. Kept as
        // the "still-unsupported" fixture so the skip promise stays
        // covered.
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $provider = $this->makeProvider(
            ['settings_globals_tax'],
            [
                'settings_globals_tax' => [
                    'name' => 'settings_globals_tax',
                    'key' => 'field_tax',
                    'type' => 'taxonomy',
                    'label' => 'Taxonomy',
                    'parent' => '',
                ],
            ]
        );

        $this->assertTrue($registry->registerProvider($provider));

        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);

        $this->assertSame([], $registry->listControls());
    }

    public function test_unresolvable_configured_name_is_skipped(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $provider = $this->makeProvider(
            ['settings_globals_missing', 'settings_globals_ok'],
            [
                // settings_globals_missing resolves to false → skipped.
                'settings_globals_missing' => false,
                'settings_globals_ok' => [
                    'name' => 'settings_globals_ok',
                    'key' => 'field_ok',
                    'type' => 'relationship',
                    'label' => 'OK',
                    'parent' => '',
                ],
            ]
        );

        $this->assertTrue($registry->registerProvider($provider));

        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);

        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('shared_globals:settings_globals_ok', $items[0]['publicId']);
    }

    public function test_visibility_closure_mirrors_manage_options_probe(): void
    {
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $provider = $this->makeProvider(
            ['settings_globals_default_posts'],
            [
                'settings_globals_default_posts' => [
                    'name' => 'settings_globals_default_posts',
                    'key' => 'field_default_posts',
                    'type' => 'relationship',
                    'label' => 'Default Posts',
                    'parent' => '',
                ],
            ]
        );

        $this->assertTrue($registry->registerProvider($provider));

        // A subscriber lacks manage_options → the option-owned descriptor probe
        // returns false, so the record is filtered from listControls().
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame([], $registry->listControls());

        // An administrator sees the record.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $items = $registry->listControls();
        $this->assertCount(1, $items);
        $this->assertSame('shared_globals:settings_globals_default_posts', $items[0]['publicId']);
    }

    public function test_empty_registry_baseline_is_preserved(): void
    {
        // A ControlRegistry with no provider registered continues to list nothing —
        // R3-B must not regress the R3-A baseline for callers that never opt in.
        $registry = new \Dbvc\VisualEditor\Registry\ControlRegistry();
        $this->assertSame([], $registry->providerIds());
        $this->assertSame([], $registry->listControls());
    }

    /**
     * @param array<int, string>                          $configured_names
     * @param array<string, array<string, mixed>|bool>    $field_objects Keyed by field name;
     *                                                    value is the ACF field-object
     *                                                    fixture, or `false` to simulate
     *                                                    an unresolvable name.
     * @return \Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider
     */
    private function makeProvider(array $configured_names, array $field_objects)
    {
        return new \Dbvc\VisualEditor\Registry\Providers\SharedGlobalsControlProvider(
            new \Dbvc\VisualEditor\Permissions\CapabilityManager(),
            static function () use ($configured_names) {
                return $configured_names;
            },
            static function ($name) use ($field_objects) {
                return array_key_exists($name, $field_objects) ? $field_objects[$name] : null;
            }
        );
    }
}
