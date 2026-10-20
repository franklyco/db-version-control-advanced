<?php

/**
 * Phase 1: ID-preserving WS Form import (opt-in) and its collision guard.
 *
 * WS Form is not installed in the test environment, so these cover the parts
 * that decide safety: the opt-in gate, payload ID collection including nested
 * sections, and the set arithmetic that blocks a hijack. The live write path is
 * covered separately.
 */
class WSFormPreserveIdsTest extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        delete_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS);
        remove_all_filters('dbvc_third_party_wsform_preserve_ids');
        parent::tear_down();
    }

    public function test_preserve_ids_is_off_by_default(): void
    {
        $this->assertFalse(
            DBVC_Third_Party_Portability::wsform_preserve_ids_enabled(),
            'renumbering must stay the default so existing users see no behaviour change'
        );
        $this->assertSame('0', DBVC_Third_Party_Portability::get_settings()['wsform_preserve_ids']);
    }

    public function test_preserve_ids_reads_the_option(): void
    {
        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS, '1');

        $this->assertTrue(DBVC_Third_Party_Portability::wsform_preserve_ids_enabled());
        $this->assertSame('1', DBVC_Third_Party_Portability::get_settings()['wsform_preserve_ids']);
    }

    public function test_preserve_ids_filter_overrides_the_option(): void
    {
        add_filter('dbvc_third_party_wsform_preserve_ids', static function () {
            return true;
        });

        $this->assertTrue(
            DBVC_Third_Party_Portability::wsform_preserve_ids_enabled(),
            'the filter must allow forcing it per environment without touching options'
        );
    }

    public function test_settings_save_round_trips_the_toggle(): void
    {
        DBVC_Third_Party_Portability::save_settings_from_post([
            DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS => '1',
        ]);
        $this->assertSame('1', DBVC_Third_Party_Portability::get_settings()['wsform_preserve_ids']);

        DBVC_Third_Party_Portability::save_settings_from_post([]);
        $this->assertSame('0', DBVC_Third_Party_Portability::get_settings()['wsform_preserve_ids']);
    }

    public function test_collects_ids_from_a_flat_form_tree(): void
    {
        $ids = DBVC_Third_Party_Portability::collect_wsform_object_ids([
            'groups' => [[
                'id'       => 7,
                'sections' => [[
                    'id'     => 11,
                    'fields' => [['id' => 101], ['id' => 102]],
                ]],
            ]],
        ]);

        $this->assertSame([7], $ids['group']);
        $this->assertSame([11], $ids['section']);
        $this->assertSame([101, 102], $ids['field']);
    }

    public function test_collects_ids_from_nested_sections(): void
    {
        // Test 7 from the handoff plan: a nested section carries group_id = 0 and
        // is reachable only through its parent chain, which is why ownership uses
        // set arithmetic rather than a field -> section -> group -> form join.
        $ids = DBVC_Third_Party_Portability::collect_wsform_object_ids([
            'groups' => [[
                'id'       => 7,
                'sections' => [[
                    'id'       => 11,
                    'group_id' => 7,
                    'fields'   => [['id' => 101]],
                    'sections' => [[
                        'id'                => 12,
                        'group_id'          => 0,
                        'parent_section_id' => 11,
                        'fields'            => [['id' => 102], ['id' => 103]],
                    ]],
                ]],
            ]],
        ]);

        $this->assertSame([11, 12], $ids['section'], 'the nested section must be collected');
        $this->assertSame([101, 102, 103], $ids['field'], 'fields under a nested section must be collected');
    }

    public function test_ignores_missing_and_zero_ids(): void
    {
        $ids = DBVC_Third_Party_Portability::collect_wsform_object_ids([
            'groups' => [[
                'sections' => [[
                    'id'     => 0,
                    'fields' => [['id' => 5], ['label' => 'no id']],
                ]],
            ]],
        ]);

        $this->assertSame([], $ids['group']);
        $this->assertSame([], $ids['section']);
        $this->assertSame([5], $ids['field']);
    }

    public function test_no_collision_when_incoming_ids_are_free(): void
    {
        $collisions = DBVC_Third_Party_Portability::compute_wsform_id_collisions(
            ['field' => [101, 102]],
            ['field' => []],
            ['field' => []]
        );

        $this->assertSame([], $collisions);
    }

    public function test_no_collision_when_the_target_form_already_owns_the_ids(): void
    {
        // The round-trip case: re-importing into the same form must be allowed.
        $collisions = DBVC_Third_Party_Portability::compute_wsform_id_collisions(
            ['group' => [7], 'section' => [11], 'field' => [101, 102]],
            ['group' => [7], 'section' => [11], 'field' => [101, 102]],
            ['group' => [7], 'section' => [11], 'field' => [101, 102]]
        );

        $this->assertSame([], $collisions);
    }

    public function test_collision_when_an_id_belongs_to_another_form(): void
    {
        // Field 212 exists but the target does not own it: importing with
        // $new = false would UPDATE that foreign row and drag it into this form.
        $collisions = DBVC_Third_Party_Portability::compute_wsform_id_collisions(
            ['field' => [101, 212]],
            ['field' => [101, 212]],
            ['field' => [101]]
        );

        $this->assertSame(['field' => [212]], $collisions);
    }

    public function test_collisions_are_reported_per_level_and_sorted(): void
    {
        $collisions = DBVC_Third_Party_Portability::compute_wsform_id_collisions(
            ['group' => [9, 7], 'section' => [11], 'field' => [212, 101]],
            ['group' => [9, 7], 'section' => [11], 'field' => [212, 101]],
            ['group' => [7], 'section' => [11], 'field' => [101]]
        );

        $this->assertSame(['group' => [9], 'field' => [212]], $collisions);
        $this->assertSame('group 9; field 212', DBVC_Third_Party_Portability::describe_wsform_id_collisions($collisions));
    }

    public function test_unreadable_target_fails_closed(): void
    {
        // When the target's owned set cannot be read it is empty, so every
        // existing incoming ID counts as foreign and the import is refused.
        $collisions = DBVC_Third_Party_Portability::compute_wsform_id_collisions(
            ['field' => [101, 102]],
            ['field' => [101, 102]],
            ['field' => []]
        );

        $this->assertSame(['field' => [101, 102]], $collisions);
    }

    public function test_prepared_import_object_retains_every_payload_id(): void
    {
        // Guards Phase 1 against a future "clean the payload" change in
        // prepare_wsform_form_import_object() silently dropping IDs.
        $method = new ReflectionMethod(DBVC_Third_Party_Portability::class, 'prepare_wsform_form_import_object');
        $method->setAccessible(true);

        $payload = [
            'id'     => 21,
            'label'  => 'Contact Form',
            'groups' => [[
                'id'       => 7,
                'sections' => [[
                    'id'     => 11,
                    'fields' => [['id' => 101], ['id' => 102]],
                ]],
            ]],
        ];

        $prepared = $method->invokeArgs(null, [$payload, 'uid-contact']);
        $ids = DBVC_Third_Party_Portability::collect_wsform_object_ids(json_decode(wp_json_encode($prepared), true));

        $this->assertSame(21, (int) $prepared->id);
        $this->assertSame([7], $ids['group']);
        $this->assertSame([11], $ids['section']);
        $this->assertSame([101, 102], $ids['field']);
    }
}
