<?php

class BricksAddonPhase3Test extends WP_UnitTestCase
{
    public function test_canonicalization_is_deterministic_for_entity_and_option_payloads(): void
    {
        $entity_a = [
            'post_title' => 'Header',
            'post_modified' => '2026-02-14 12:00:00',
            'meta' => [
                '_bricks_page_content_2' => [
                    ['name' => 'zeta', 'id' => 'z2'],
                    ['id' => 'a1', 'name' => 'alpha'],
                ],
            ],
            'post_date' => '2026-02-14 11:00:00',
        ];
        $entity_b = [
            'post_date' => '2026-01-01 00:00:00',
            'meta' => [
                '_bricks_page_content_2' => [
                    ['id' => 'a1', 'name' => 'alpha'],
                    ['name' => 'zeta', 'id' => 'z2'],
                ],
            ],
            'post_title' => 'Header',
            'post_modified' => '2026-01-01 00:00:00',
        ];

        $entity_canonical_a = DBVC_Bricks_Artifacts::canonicalize('bricks_template', $entity_a);
        $entity_canonical_b = DBVC_Bricks_Artifacts::canonicalize('bricks_template', $entity_b);
        $this->assertSame($entity_canonical_a, $entity_canonical_b);
        $this->assertSame(
            DBVC_Bricks_Artifacts::fingerprint($entity_canonical_a),
            DBVC_Bricks_Artifacts::fingerprint($entity_canonical_b)
        );

        $option_a = [
            ['name' => 'zeta', 'id' => 'z2'],
            ['id' => 'a1', 'name' => 'alpha'],
        ];
        $option_b = [
            ['id' => 'a1', 'name' => 'alpha'],
            ['id' => 'z2', 'name' => 'zeta'],
        ];
        $option_canonical_a = DBVC_Bricks_Artifacts::canonicalize('bricks_global_classes', $option_a);
        $option_canonical_b = DBVC_Bricks_Artifacts::canonicalize('bricks_global_classes', $option_b);
        $this->assertSame($option_canonical_a, $option_canonical_b);
        $this->assertSame(
            DBVC_Bricks_Artifacts::fingerprint($option_canonical_a),
            DBVC_Bricks_Artifacts::fingerprint($option_canonical_b)
        );
    }

    public function test_volatile_fields_are_stripped_from_entity_and_option_payloads(): void
    {
        $entity_payload = [
            'post_date' => '2026-02-14 12:00:00',
            'post_date_gmt' => '2026-02-14 12:00:00',
            'post_modified' => '2026-02-14 12:10:00',
            'post_modified_gmt' => '2026-02-14 12:10:00',
            'meta' => [
                '_edit_lock' => '123',
                '_edit_last' => '1',
                'kept' => 'value',
            ],
        ];
        $canonical_entity = DBVC_Bricks_Artifacts::canonicalize('bricks_template', $entity_payload);
        $this->assertArrayNotHasKey('post_date', $canonical_entity);
        $this->assertArrayNotHasKey('post_date_gmt', $canonical_entity);
        $this->assertArrayNotHasKey('post_modified', $canonical_entity);
        $this->assertArrayNotHasKey('post_modified_gmt', $canonical_entity);
        $this->assertArrayHasKey('meta', $canonical_entity);
        $this->assertArrayHasKey('kept', $canonical_entity['meta']);
        $this->assertArrayNotHasKey('_edit_lock', $canonical_entity['meta']);
        $this->assertArrayNotHasKey('_edit_last', $canonical_entity['meta']);

        $option_payload = [
            ['id' => 'a1', 'name' => 'x', 'updated_at' => 'now'],
            ['id' => 'a2', 'name' => 'y', 'time' => 999],
        ];
        $canonical_option = DBVC_Bricks_Artifacts::canonicalize('bricks_components', $option_payload);
        foreach ($canonical_option as $item) {
            $this->assertArrayNotHasKey('updated_at', $item);
            $this->assertArrayNotHasKey('time', $item);
        }
    }

    public function test_fixture_schema_validation_passes_for_each_artifact_type(): void
    {
        $registry = DBVC_Bricks_Artifacts::get_registry();
        $fixtures_path = DBVC_PLUGIN_PATH . 'addons/bricks/fixtures/bricks-artifact-fixtures.php';
        $this->assertFileExists($fixtures_path);
        $fixtures = require $fixtures_path;
        $this->assertIsArray($fixtures);
        $this->assertSame(array_keys($registry), array_keys($fixtures));

        $validation_errors = DBVC_Bricks_Artifacts::validate_fixtures($fixtures);
        $this->assertSame([], $validation_errors);
    }

    public function test_fingerprint_format_and_collision_smoke(): void
    {
        $hash_a = DBVC_Bricks_Artifacts::fingerprint(['a' => 1, 'b' => 2]);
        $hash_b = DBVC_Bricks_Artifacts::fingerprint(['a' => 1, 'b' => 3]);
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $hash_a);
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $hash_b);
        $this->assertNotSame($hash_a, $hash_b);

        $diag_match = DBVC_Bricks_Artifacts::hash_diagnostics($hash_a, $hash_a);
        $this->assertTrue($diag_match['match']);
        $this->assertSame('hash_match', $diag_match['message']);

        $diag_mismatch = DBVC_Bricks_Artifacts::hash_diagnostics($hash_a, $hash_b);
        $this->assertFalse($diag_mismatch['match']);
        $this->assertSame('hash_mismatch', $diag_mismatch['message']);
    }
}
