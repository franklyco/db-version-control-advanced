<?php

class BricksAddonPhase5Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        delete_option(DBVC_Bricks_Apply::OPTION_RESTORE_POINTS);
    }

    public function tear_down(): void
    {
        delete_option(DBVC_Bricks_Apply::OPTION_RESTORE_POINTS);
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        parent::tear_down();
    }

    public function test_dry_run_plan_reports_ordered_apply_without_writes(): void
    {
        update_option('bricks_global_classes', ['before' => true]);
        $post_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Before',
            'post_status' => 'publish',
        ]);

        $manifest = [
            'package_id' => 'pkg-apply-1',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_classes',
                    'artifact_type' => 'bricks_global_classes',
                    'payload' => ['after' => true],
                ],
                [
                    'artifact_uid' => 'entity:' . $post_id,
                    'artifact_type' => 'bricks_template',
                    'payload' => ['ID' => $post_id, 'post_title' => 'After'],
                ],
            ],
        ];

        $result = DBVC_Bricks_Apply::apply_package($manifest, [], ['dry_run' => true]);
        $this->assertIsArray($result);
        $this->assertTrue($result['dry_run']);
        $this->assertCount(1, $result['plan']['option_artifacts']);
        $this->assertCount(1, $result['plan']['entity_artifacts']);
        $this->assertSame(['before' => true], get_option('bricks_global_classes'));
        $this->assertSame('Before', get_post($post_id)->post_title);
    }

    public function test_restore_point_create_and_rollback_restores_option_state(): void
    {
        update_option('bricks_global_classes', ['version' => 'v1']);
        $plan = [
            'option_artifacts' => [
                ['option_key' => 'bricks_global_classes'],
            ],
            'entity_artifacts' => [],
            'skipped' => [],
        ];

        $restore = DBVC_Bricks_Apply::create_restore_point($plan);
        $this->assertIsArray($restore);
        update_option('bricks_global_classes', ['version' => 'v2']);

        $rollback = DBVC_Bricks_Apply::rollback_restore_point($restore['restore_id']);
        $this->assertIsArray($rollback);
        $this->assertTrue($rollback['rolled_back']);
        $this->assertSame(['version' => 'v1'], get_option('bricks_global_classes'));
    }

    public function test_policy_gate_ignore_skips_artifact_apply(): void
    {
        update_option('dbvc_bricks_policy_option_default', 'IGNORE');
        update_option('bricks_global_classes', ['kept' => true]);
        $manifest = [
            'package_id' => 'pkg-apply-2',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_classes',
                    'artifact_type' => 'bricks_global_classes',
                    'payload' => ['replaced' => true],
                ],
            ],
        ];
        $result = DBVC_Bricks_Apply::apply_package($manifest);
        $this->assertIsArray($result);
        $this->assertEmpty($result['applied']['options']);
        $this->assertSame(['kept' => true], get_option('bricks_global_classes'));
    }

    public function test_destructive_gate_blocks_without_explicit_approval(): void
    {
        update_option('dbvc_bricks_policy_block_delete', '1');
        $manifest = [
            'package_id' => 'pkg-apply-3',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_classes',
                    'artifact_type' => 'bricks_global_classes',
                    'payload' => ['__delete' => true],
                ],
            ],
        ];

        $result = DBVC_Bricks_Apply::apply_package($manifest, [], ['allow_destructive' => false]);
        $this->assertWPError($result);
        $this->assertSame('dbvc_bricks_destructive_blocked', $result->get_error_code());
    }
}
