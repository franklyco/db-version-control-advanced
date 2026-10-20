<?php

/**
 * M9 slice 2: value review at approval. The prepare-receipt view annotates each
 * changed path with the incoming (source) value pulled from the sealed payload —
 * redacting secret-ish keys and truncating long/structured values — so an
 * operator reviews the actual values before approving. Source-only by design:
 * the hub holds the target's hash, not its content.
 *
 * @group connected-environments
 */
class ConnectedApprovalValuesTest extends WP_UnitTestCase
{
    public function test_changed_paths_are_annotated_with_redacted_and_truncated_incoming_values(): void
    {
        $long = str_repeat('a', 260);
        $bodies = [
            'bricks.global_class|fw-btn' => [
                'value' => '1200px',
                'settings' => [
                    '_cssCustom' => '.fw-btn { color: red; }',
                    'password' => 'hunter2',
                    'api_key' => 'sk-live-123',
                    'enabled' => true,
                    'colors' => ['a', 'b'],
                    'blob' => $long,
                ],
            ],
        ];
        $receipt = [
            'items' => [
                [
                    'domain' => 'bricks.global_class',
                    'instance_uid' => 'fw-btn',
                    'patch' => [
                        'changed_paths' => [
                            ['path' => 'value', 'operation' => 'modify'],
                            ['path' => 'settings._cssCustom', 'operation' => 'modify'],
                            ['path' => 'settings.password', 'operation' => 'modify'],
                            ['path' => 'settings.api_key', 'operation' => 'modify'],
                            ['path' => 'settings.enabled', 'operation' => 'modify'],
                            ['path' => 'settings.colors', 'operation' => 'modify'],
                            ['path' => 'settings.blob', 'operation' => 'modify'],
                            ['path' => 'settings.missing', 'operation' => 'add'],
                        ],
                    ],
                ],
            ],
        ];

        $out = \DBVC_Agency_CLI_Inspector::apply_incoming_values($receipt, $bodies);
        $values = [];
        foreach ($out['items'][0]['patch']['changed_paths'] as $changed) {
            $values[$changed['path']] = $changed['after_value'];
        }

        $this->assertSame('1200px', $values['value']);
        $this->assertSame('.fw-btn { color: red; }', $values['settings._cssCustom']);
        $this->assertSame('[redacted]', $values['settings.password'], 'Secret-ish keys are never shown.');
        $this->assertSame('[redacted]', $values['settings.api_key']);
        $this->assertSame('true', $values['settings.enabled'], 'Booleans stringify.');
        $this->assertSame('["a","b"]', $values['settings.colors'], 'Structured values are JSON-encoded.');
        $this->assertStringStartsWith(str_repeat('a', 200), $values['settings.blob']);
        $this->assertStringContainsString('(260 chars)', $values['settings.blob'], 'Long values are truncated with a length note.');
        $this->assertSame('(absent)', $values['settings.missing'], 'A path missing from the payload reads absent.');
    }

    public function test_missing_body_and_shapeless_receipts_are_safe(): void
    {
        // No matching body for the item -> after_value is null, never a crash.
        $receipt = ['items' => [['domain' => 'x', 'instance_uid' => 'y', 'patch' => ['changed_paths' => [['path' => 'a']]]]]];
        $out = \DBVC_Agency_CLI_Inspector::apply_incoming_values($receipt, []);
        $this->assertNull($out['items'][0]['patch']['changed_paths'][0]['after_value']);

        // A receipt with no items is returned unchanged.
        $this->assertSame(['note' => 'x'], \DBVC_Agency_CLI_Inspector::apply_incoming_values(['note' => 'x'], []));

        // An item with no patch is left alone (no after_value injected anywhere).
        $noPatch = \DBVC_Agency_CLI_Inspector::apply_incoming_values(['items' => [['domain' => 'a', 'instance_uid' => 'b']]], []);
        $this->assertArrayNotHasKey('patch', $noPatch['items'][0]);
    }
}
