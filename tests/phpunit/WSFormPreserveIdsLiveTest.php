<?php

/**
 * The live write path of the ID-preserving WS Form import.
 *
 * WSFormPreserveIdsTest covers the decision logic without WS Form installed:
 * the opt-in gate, payload ID collection, and the collision set arithmetic.
 * This is the "covered separately" half it refers to — it loads WS Form, builds
 * a real form, seeds a real submission, and drives the real import, so it can
 * assert what those unit tests cannot: that stored submission data is still
 * readable afterwards, and that a mid-import failure does not destroy the form.
 *
 * Skips unless the suite is run with DBVC_TESTS_WS_FORM=1, which makes
 * tests/phpunit/bootstrap.php load WS Form and create its tables:
 *
 *     DBVC_TESTS_WS_FORM=1 vendor/bin/phpunit --filter WSFormPreserveIdsLiveTest
 *
 * Loading is opt-in rather than automatic because WS Form's block registration
 * trips an incorrect-usage notice in BricksAddonPhase1Test; see bootstrap.php.
 *
 * Scope: a flat (non-repeatable) fixture, so submission meta keys are plain
 * `field_<id>`. Repeatable sections add a `_<index>` suffix and warrant their
 * own case.
 *
 * @group wsform
 * @group wsform-id-parity
 */
class WSFormPreserveIdsLiveTest extends WP_UnitTestCase
{
    /**
     * Unique per test method. A shared uid let the second test match the
     * first test's leftover portability uid and skip fixture creation.
     *
     * @var string
     */
    private $uid = '';

    /**
     * Field types used for the fixture. Core WS Form types only, so the
     * licence check in WS_Form_Field::db_check_licensed() cannot skip them.
     */
    private const FIXTURE_FIELDS = [
        'Client Name'  => 'text',
        'Client Email' => 'email',
        'Project Brief' => 'textarea',
    ];

    /** @var int */
    private $form_id = 0;

    /** @var int */
    private $submit_id = 0;

    /** @var int Second, unrelated form used by the collision test. */
    private $other_form_id = 0;

    public function set_up(): void
    {
        parent::set_up();

        if (! class_exists('DBVC_Third_Party_Portability')) {
            $this->markTestSkipped('DBVC_Third_Party_Portability is not loaded.');
        }

        if (! DBVC_Third_Party_Portability::is_wsform_available()) {
            $this->markTestSkipped(
                'WS Form is not available in the test environment. Load and activate WS Form in tests/phpunit/bootstrap.php to run this test.'
            );
        }

        if (! $this->wsform_tables_exist()) {
            $this->markTestSkipped(
                'WS Form tables are missing from the test database. Run WS_Form_Activator::activate() in tests/phpunit/bootstrap.php to run this test.'
            );
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS);

        $this->uid = 'dbvc-test-submission-survival-' . wp_generate_uuid4();
    }

    /**
     * Cleanup runs AFTER parent::tear_down() on purpose.
     *
     * The ID-preserving import issues a raw START TRANSACTION/COMMIT rather
     * than going through the add-ons' Transaction abstraction, so the suite-wide
     * `savepoint` strategy installed in bootstrap.php does not reach it. That is
     * the same implicit-commit leak documented there: the fixture rows are
     * already committed and survive WP's ROLLBACK, while a DELETE issued before
     * that rollback is itself rolled back. Cleaning up afterwards runs outside a
     * transaction and actually sticks.
     *
     * Giving the WS Form import a transaction strategy filter would remove the
     * need for this ordering; see the pull request for that follow-up.
     */
    public function tear_down(): void
    {
        parent::tear_down();

        // Both of these must happen after the rollback, not before. The import
        // committed, so the option write and the fixture rows outlived WP's
        // transaction; a delete issued before the rollback is itself rolled
        // back, leaving preserve-ids enabled for every later test in the run.
        delete_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS);
        $this->delete_fixture();
    }

    /**
     * The acceptance test: a seeded submission is still readable after the
     * form is re-imported over itself with ID preservation on.
     */
    public function test_submission_meta_still_resolves_after_id_preserving_import(): void
    {
        $this->form_id = $this->import_fixture_form();
        $fields_before = $this->read_field_types($this->form_id);
        $this->assertCount(
            count(self::FIXTURE_FIELDS),
            $fields_before,
            'Fixture form did not produce the expected fields.'
        );

        $this->submit_id = $this->seed_submission($this->form_id, $fields_before);
        $seeded = $this->read_submission_field_meta($this->submit_id);
        $this->assertCount(
            count($fields_before),
            $seeded,
            'Seeded submission did not store one meta row per field.'
        );

        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS, '1');
        $this->assertTrue(
            DBVC_Third_Party_Portability::wsform_preserve_ids_enabled(),
            'ID preservation did not switch on.'
        );

        $result = $this->reimport($this->form_id);

        // The uid must match the existing form, otherwise preservation is
        // skipped by design and this test would prove nothing.
        $this->assertSame($this->form_id, (int) $result['form_id'], 'Import did not land on the matched form.');
        $this->assertSame('update_matched_form', $result['action']);
        $this->assertFalse($result['created']);

        $fields_after = $this->read_field_types($this->form_id);

        // The feature: identical field IDs, identical types.
        $this->assertSame($fields_before, $fields_after, 'Field IDs or types changed across the import.');

        // This test: every stored submission key still points at a live field
        // of the type it was written for.
        foreach ($seeded as $meta_key => $seeded_type) {
            $field_id = $this->field_id_from_meta_key($meta_key);

            $this->assertArrayHasKey(
                $field_id,
                $fields_after,
                sprintf('Submission meta key %s no longer resolves to a live field.', $meta_key)
            );

            $this->assertSame(
                $seeded_type,
                $fields_after[$field_id],
                sprintf('Field %d changed type, so %s now means something else.', $field_id, $meta_key)
            );
        }
    }

    /**
     * Control: with preservation off the renumbering still happens, so the
     * acceptance test above is measuring something real.
     */
    public function test_submission_meta_is_orphaned_without_id_preservation(): void
    {
        $this->form_id = $this->import_fixture_form();
        $fields_before = $this->read_field_types($this->form_id);
        $this->submit_id = $this->seed_submission($this->form_id, $fields_before);
        $seeded = $this->read_submission_field_meta($this->submit_id);

        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS, '0');
        $this->assertFalse(
            DBVC_Third_Party_Portability::wsform_preserve_ids_enabled(),
            'ID preservation should be off for the control test.'
        );

        $this->reimport($this->form_id);
        $fields_after = $this->read_field_types($this->form_id);

        $this->assertNotSame(
            $fields_before,
            $fields_after,
            'Expected WS Form to renumber fields when preservation is off.'
        );

        $orphans = 0;
        foreach (array_keys($seeded) as $meta_key) {
            if (! array_key_exists($this->field_id_from_meta_key($meta_key), $fields_after)) {
                $orphans++;
            }
        }

        $this->assertGreaterThan(
            0,
            $orphans,
            'Expected at least one orphaned submission meta key without ID preservation.'
        );
    }

    /**
     * The guard that makes the whole feature safe, end to end.
     *
     * WSFormPreserveIdsTest already asserts the set arithmetic in isolation.
     * This drives it through a real import to prove the refusal reaches the
     * caller and that neither form is touched on the way out.
     */
    public function test_import_is_refused_when_ids_belong_to_another_form(): void
    {
        // Form A is the uid-matched target. Form B is an innocent bystander.
        $this->form_id = $this->import_fixture_form();
        $a_fields_before = $this->read_field_types($this->form_id);

        $other_uid = 'dbvc-test-other-' . wp_generate_uuid4();
        $this->other_form_id = $this->import_fixture_form($other_uid);
        $b_fields_before = $this->read_field_types($this->other_form_id);

        $this->assertNotEmpty($b_fields_before, 'Bystander form produced no fields.');
        $this->assertEmpty(
            array_intersect(array_keys($a_fields_before), array_keys($b_fields_before)),
            'Fixtures should start with disjoint field IDs.'
        );

        // Take A's snapshot and rewrite its field IDs to B's, which is exactly
        // the cross-site case where two sites numbered their fields differently.
        $snapshot = DBVC_Third_Party_Portability::export_wsform_form_snapshot_for_entity_editor($this->form_id);
        $this->assertIsArray($snapshot, 'Export did not return a payload array.');

        $stolen_ids = array_keys($b_fields_before);
        $snapshot = $this->rewrite_snapshot_field_ids($snapshot, $stolen_ids);

        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS, '1');
        $this->assertTrue(DBVC_Third_Party_Portability::wsform_preserve_ids_enabled());

        $result = DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($snapshot);

        // Refused, with a code and a message that names what collided.
        $this->assertTrue(is_wp_error($result), 'Import should have been refused.');
        $this->assertSame('dbvc_wsform_id_collision', $result->get_error_code());

        $message = $result->get_error_message();
        foreach ($stolen_ids as $stolen_id) {
            $this->assertStringContainsString(
                (string) $stolen_id,
                $message,
                sprintf('Collision report does not name field %d.', $stolen_id)
            );
        }

        $data = $result->get_error_data();
        $this->assertSame($this->form_id, (int) ($data['form_id'] ?? 0));
        $this->assertNotEmpty($data['collisions'] ?? [], 'Error data carries no collision detail.');

        // Nothing mutated: both forms are still readable and unchanged.
        $this->assertSame(
            $a_fields_before,
            $this->read_field_types($this->form_id),
            'Target form was modified despite the refusal.'
        );
        $this->assertSame(
            $b_fields_before,
            $this->read_field_types($this->other_form_id),
            'Bystander form was modified despite the refusal.'
        );
    }

    /**
     * A failure mid-import must not leave the target form destroyed.
     *
     * The import tears the form's tree down before rebuilding it, so the window
     * between those two steps is the dangerous one. With ID preservation on and
     * InnoDB tables the import wraps that window in a transaction; this asserts
     * the rollback actually restores the form.
     *
     * Fault is injected through dbvc_third_party_wsform_import_after_reset,
     * which fires at exactly that boundary.
     */
    public function test_failure_after_reset_rolls_the_form_back(): void
    {
        $this->form_id = $this->import_fixture_form();
        $fields_before = $this->read_field_types($this->form_id);
        $this->assertNotEmpty($fields_before, 'Fixture form produced no fields.');

        $this->submit_id = $this->seed_submission($this->form_id, $fields_before);
        $seeded = $this->read_submission_field_meta($this->submit_id);

        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS, '1');
        $this->assertTrue(DBVC_Third_Party_Portability::wsform_preserve_ids_enabled());

        $fired = 0;
        $boom = static function () use (&$fired): void {
            $fired++;
            throw new RuntimeException('injected mid-import failure');
        };
        add_action('dbvc_third_party_wsform_import_after_reset', $boom);

        try {
            $snapshot = DBVC_Third_Party_Portability::export_wsform_form_snapshot_for_entity_editor($this->form_id);
            $this->assertIsArray($snapshot, 'Export did not return a payload array.');

            $result = DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($snapshot);
        } finally {
            remove_action('dbvc_third_party_wsform_import_after_reset', $boom);
        }

        $this->assertSame(1, $fired, 'The seam did not fire exactly once.');

        // The import reports the failure rather than swallowing it.
        $this->assertTrue(is_wp_error($result), 'Import should have failed.');
        $this->assertSame('dbvc_wsform_import_failed', $result->get_error_code());
        $this->assertStringContainsString('injected mid-import failure', $result->get_error_message());

        // And the form is intact: same fields, same IDs, same types.
        $this->assertSame(
            $fields_before,
            $this->read_field_types($this->form_id),
            'Form was not restored after the mid-import failure.'
        );

        // The submission is still readable, which is the thing that matters.
        $this->assertSame(
            $seeded,
            $this->read_submission_field_meta($this->submit_id),
            'Submission meta did not survive the rolled-back import.'
        );
    }

    /**
     * Without a transaction there is nothing to roll back, so the same failure
     * must surface as an error rather than appearing to succeed.
     *
     * Preservation off means no transaction is opened, which is also the
     * MyISAM/degraded path. Documents the real behaviour: the form is left torn
     * down, so the error is the only signal an operator gets.
     */
    public function test_failure_after_reset_is_reported_when_untransacted(): void
    {
        $this->form_id = $this->import_fixture_form();
        $this->assertNotEmpty($this->read_field_types($this->form_id));

        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS, '0');
        $this->assertFalse(DBVC_Third_Party_Portability::wsform_preserve_ids_enabled());

        $boom = static function (): void {
            throw new RuntimeException('injected untransacted failure');
        };
        add_action('dbvc_third_party_wsform_import_after_reset', $boom);

        try {
            $snapshot = DBVC_Third_Party_Portability::export_wsform_form_snapshot_for_entity_editor($this->form_id);
            $result = DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($snapshot);
        } finally {
            remove_action('dbvc_third_party_wsform_import_after_reset', $boom);
        }

        $this->assertTrue(is_wp_error($result), 'Import should have failed.');
        $this->assertSame('dbvc_wsform_import_failed', $result->get_error_code());
        $this->assertStringContainsString('injected untransacted failure', $result->get_error_message());

        // The form really is left torn down. Asserting this is what proves the
        // transactional test above is measuring DBVC's own rollback rather than
        // WP_UnitTestCase's outer transaction, and it pins the operational risk:
        // an untransacted import that fails mid-flight destroys the target form.
        $this->assertSame(
            [],
            $this->read_field_types($this->form_id),
            'Expected the untransacted failure to leave the form torn down.'
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Replace the payload's field IDs with the given ones, in tree order.
     *
     * @param array<string, mixed> $snapshot
     * @param int[]                $ids
     * @return array<string, mixed>
     */
    private function rewrite_snapshot_field_ids(array $snapshot, array $ids): array
    {
        $next = 0;

        foreach ($snapshot['groups'] as $g => $group) {
            foreach ($group['sections'] as $s => $section) {
                foreach ($section['fields'] as $f => $field) {
                    if (! array_key_exists($next, $ids)) {
                        continue;
                    }
                    $snapshot['groups'][$g]['sections'][$s]['fields'][$f]['id'] = $ids[$next];
                    $next++;
                }
            }
        }

        $this->assertSame(count($ids), $next, 'Did not rewrite every supplied field ID.');

        return $snapshot;
    }

    /**
     * Create the fixture form through the import path itself, so a malformed
     * payload fails loudly here rather than quietly testing nothing. No uid
     * match exists yet, so this takes the ordinary create/renumber path and
     * DBVC stamps $this->uid onto the new form.
     *
     * @return int
     */
    private function import_fixture_form(string $uid = ''): int
    {
        $result = DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor(
            $this->fixture_payload($uid === '' ? $this->uid : $uid)
        );

        $this->assertImportSucceeded($result, 'Creating the fixture form failed');
        $this->assertTrue((bool) $result['created'], 'Fixture form was not created fresh.');

        return (int) $result['form_id'];
    }

    /**
     * Export the live form and import that snapshot straight back over it —
     * the local round trip that stands in for a cross-site sync.
     *
     * @param int $form_id
     * @return array<string, mixed>
     */
    private function reimport(int $form_id): array
    {
        $snapshot = DBVC_Third_Party_Portability::export_wsform_form_snapshot_for_entity_editor($form_id);

        $this->assertFalse(
            is_wp_error($snapshot),
            'Export failed: ' . (is_wp_error($snapshot) ? $snapshot->get_error_message() : '')
        );
        $this->assertIsArray($snapshot, 'Export did not return a payload array.');

        $result = DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($snapshot);
        $this->assertImportSucceeded($result, 'Re-importing the snapshot failed');

        return $result;
    }

    /**
     * A minimal, fully specified form payload. Every NOT NULL column named in
     * the WS Form DB_INSERT constants is set explicitly, because
     * WS_Form_Core::get_wpdb_data() substitutes an empty string for a missing
     * property and that would fail under MySQL strict mode.
     *
     * @return array<string, mixed>
     */
    private function fixture_payload(string $uid): array
    {
        $now = current_time('mysql', true);
        $user_id = get_current_user_id();

        $fields = [];
        $sort_index = 1;
        foreach (self::FIXTURE_FIELDS as $label => $type) {
            $fields[] = [
                'label'        => $label,
                'type'         => $type,
                'user_id'      => $user_id,
                'date_added'   => $now,
                'date_updated' => $now,
                'sort_index'   => $sort_index++,
                'meta'         => [],
            ];
        }

        return [
            'label'      => 'DBVC ID Parity Fixture',
            'status'     => 'publish',
            'identifier' => 'ws_form',
            'meta'       => [
                'export_object' => 'form',
            ],
            'groups'     => [
                [
                    'label'        => 'Group',
                    'user_id'      => $user_id,
                    'date_added'   => $now,
                    'date_updated' => $now,
                    'sort_index'   => 1,
                    'meta'         => [],
                    'sections'     => [
                        [
                            'label'             => 'Section',
                            'child_count'       => 0,
                            'user_id'           => $user_id,
                            'date_added'        => $now,
                            'date_updated'      => $now,
                            'sort_index'        => 1,
                            'parent_section_id' => 0,
                            'meta'              => [],
                            'fields'            => $fields,
                        ],
                    ],
                ],
            ],
            'dbvc_portability' => [
                'schema'        => 1,
                'provider'      => 'ws_form',
                'object_type'   => 'form',
                'uid'           => $uid,
                'source_status' => 'publish',
            ],
        ];
    }

    /**
     * Map field id => type by walking the form tree WS Form itself returns.
     * Read through WS Form rather than by SQL join, because nested sections can
     * carry group_id = 0 and a field -> section -> group -> form join misses
     * them.
     *
     * @param int $form_id
     * @return array<int, string>
     */
    private function read_field_types(int $form_id): array
    {
        $ws_form = new WS_Form_Form();
        $ws_form->id = $form_id;
        $tree = $ws_form->db_read(true, true, false, false, true);

        $types = [];

        $walk_sections = static function ($sections) use (&$types, &$walk_sections): void {
            foreach ((array) $sections as $section) {
                $section = (object) $section;
                foreach ((array) ($section->fields ?? []) as $field) {
                    $field = (object) $field;
                    if (isset($field->id, $field->type)) {
                        $types[(int) $field->id] = (string) $field->type;
                    }
                }
                if (! empty($section->sections)) {
                    $walk_sections($section->sections);
                }
            }
        };

        foreach ((array) ($tree->groups ?? []) as $group) {
            $group = (object) $group;
            $walk_sections($group->sections ?? []);
        }

        ksort($types);

        return $types;
    }

    /**
     * Insert a submission and one meta row per field.
     *
     * Written with $wpdb rather than WS_Form_Submit so the precondition does
     * not depend on WS Form's submit pipeline. The subject under test is
     * DBVC's import, and the assertions read the real tables either way.
     *
     * @param int                $form_id
     * @param array<int, string> $fields field id => type
     * @return int
     */
    private function seed_submission(int $form_id, array $fields): int
    {
        global $wpdb;

        $now = current_time('mysql', true);

        $inserted = $wpdb->insert(
            $this->wsform_table('submit'),
            [
                'form_id'            => $form_id,
                'date_added'         => $now,
                'date_updated'       => $now,
                'date_expire'        => null,
                'user_id'            => 0,
                'hash'               => md5('dbvc-test-' . $form_id),
                'actions'            => '',
                'section_repeatable' => '',
                'count_submit'       => 1,
                'duration'           => 0,
                'status'             => 'publish',
                'preview'            => 0,
                'spam_level'         => 0,
                'starred'            => 0,
                'viewed'             => 1,
                'encrypted'          => 0,
                'token'              => '',
                'token_validated'    => 0,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d']
        );

        $this->assertNotFalse($inserted, 'Could not insert the fixture submission.');
        $submit_id = (int) $wpdb->insert_id;

        foreach ($fields as $field_id => $type) {
            $meta_inserted = $wpdb->insert(
                $this->wsform_table('submit_meta'),
                [
                    'parent_id'  => $submit_id,
                    'field_id'   => $field_id,
                    'meta_key'   => 'field_' . $field_id,
                    'meta_value' => 'seeded value for ' . $type,
                ],
                ['%d', '%d', '%s', '%s']
            );

            $this->assertNotFalse(
                $meta_inserted,
                sprintf('Could not insert submission meta for field %d.', $field_id)
            );
        }

        return $submit_id;
    }

    /**
     * Submission meta keys of the form `field_<id>`, mapped to the field type
     * they were seeded against. Non-field keys such as wsf_meta_key_hidden and
     * repeatable-index keys are ignored.
     *
     * @param int $submit_id
     * @return array<string, string>
     */
    private function read_submission_field_meta(int $submit_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT meta_key, meta_value FROM ' . $this->wsform_table('submit_meta') . ' WHERE parent_id = %d',
                $submit_id
            ),
            ARRAY_A
        );

        $meta = [];
        foreach ((array) $rows as $row) {
            if (! preg_match('/^field_\d+$/', (string) $row['meta_key'])) {
                continue;
            }
            // seed_submission() writes "seeded value for <type>".
            $meta[(string) $row['meta_key']] = (string) str_replace('seeded value for ', '', (string) $row['meta_value']);
        }

        ksort($meta);

        return $meta;
    }

    /**
     * @param string $meta_key
     * @return int
     */
    private function field_id_from_meta_key(string $meta_key): int
    {
        return (int) substr($meta_key, strlen('field_'));
    }

    /**
     * @param mixed  $result
     * @param string $context
     * @return void
     */
    private function assertImportSucceeded($result, string $context): void
    {
        if (is_wp_error($result)) {
            $this->fail($context . ': ' . $result->get_error_message());
        }

        $this->assertIsArray($result, $context . ': import did not return a details array.');
        $this->assertSame('applied', $result['status'] ?? null, $context . ': import was not applied.');
    }

    /**
     * @param string $suffix
     * @return string
     */
    private function wsform_table(string $suffix): string
    {
        global $wpdb;

        return $wpdb->prefix . WS_FORM_DB_TABLE_PREFIX . $suffix;
    }

    /**
     * @return bool
     */
    private function wsform_tables_exist(): bool
    {
        global $wpdb;

        foreach (['form', 'group', 'section', 'field', 'submit', 'submit_meta'] as $suffix) {
            $table = $this->wsform_table($suffix);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove the fixture forms and the seeded submission.
     *
     * Deletes with $wpdb rather than WS_Form_Form::db_delete(), which is
     * capability gated: by teardown the current user is gone, db_delete()
     * throws, and the fixture silently leaks. WordPress's own transaction
     * rollback does not cover these tables either — DBVC's preserve path issues
     * its own START TRANSACTION/COMMIT, which ends the outer test transaction.
     *
     * @return void
     */
    private function delete_fixture(): void
    {
        if ($this->submit_id > 0) {
            global $wpdb;
            $wpdb->delete($this->wsform_table('submit_meta'), ['parent_id' => $this->submit_id], ['%d']);
            $wpdb->delete($this->wsform_table('submit'), ['id' => $this->submit_id], ['%d']);
            $this->submit_id = 0;
        }

        foreach ([$this->form_id, $this->other_form_id] as $form_id) {
            if ($form_id > 0) {
                $this->delete_form_tree((int) $form_id);
            }
        }

        $this->form_id = 0;
        $this->other_form_id = 0;
    }

    /**
     * Delete a form and everything hanging off it, by SQL.
     *
     * @param int $form_id
     * @return void
     */
    private function delete_form_tree(int $form_id): void
    {
        global $wpdb;

        $group_table   = $this->wsform_table('group');
        $section_table = $this->wsform_table('section');
        $field_table   = $this->wsform_table('field');

        $group_ids = $wpdb->get_col(
            $wpdb->prepare("SELECT id FROM {$group_table} WHERE form_id = %d", $form_id)
        );

        $section_ids = [];
        if (! empty($group_ids)) {
            $in = implode(',', array_map('absint', $group_ids));
            $section_ids = $wpdb->get_col("SELECT id FROM {$section_table} WHERE group_id IN ({$in})");
        }

        // Nested sections hang off a parent section, not a group.
        $pending = $section_ids;
        while (! empty($pending)) {
            $in = implode(',', array_map('absint', $pending));
            $children = $wpdb->get_col("SELECT id FROM {$section_table} WHERE parent_section_id IN ({$in})");
            $children = array_diff(array_map('absint', (array) $children), array_map('absint', $section_ids));
            $section_ids = array_merge($section_ids, $children);
            $pending = $children;
        }

        $field_ids = [];
        if (! empty($section_ids)) {
            $in = implode(',', array_map('absint', $section_ids));
            $field_ids = $wpdb->get_col("SELECT id FROM {$field_table} WHERE section_id IN ({$in})");
        }

        $this->delete_rows_and_meta('field', $field_ids);
        $this->delete_rows_and_meta('section', $section_ids);
        $this->delete_rows_and_meta('group', $group_ids);
        $this->delete_rows_and_meta('form', [$form_id]);
    }

    /**
     * @param string            $object One of form, group, section, field.
     * @param array<int, mixed> $ids
     * @return void
     */
    private function delete_rows_and_meta(string $object, array $ids): void
    {
        $ids = array_filter(array_map('absint', $ids));
        if (empty($ids)) {
            return;
        }

        global $wpdb;

        $in = implode(',', $ids);
        $table = $this->wsform_table($object);
        $meta_table = $this->wsform_table($object . '_meta');

        $wpdb->query("DELETE FROM {$meta_table} WHERE parent_id IN ({$in})");
        $wpdb->query("DELETE FROM {$table} WHERE id IN ({$in})");
    }
}
