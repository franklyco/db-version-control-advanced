<?php

class ThirdPartyPortabilityTest extends WP_UnitTestCase
{
    public function test_detects_wsform_form_payload(): void
    {
        $payload = [
            'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
            'label'      => 'Contact Form',
            'meta'       => [
                'export_object' => 'form',
            ],
        ];

        $this->assertTrue(DBVC_Third_Party_Portability::is_wsform_form_payload($payload));
        $this->assertTrue(DBVC_Third_Party_Portability::is_third_party_payload($payload));
        $this->assertFalse(DBVC_Third_Party_Portability::is_wsform_settings_payload($payload));
    }

    public function test_detects_wsform_settings_payload(): void
    {
        $payload = [
            'entity_type' => 'third_party',
            'provider'    => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
            'object_type' => 'settings',
            'options'     => [
                'ws_form' => [
                    'framework' => 'ws-form',
                ],
            ],
        ];

        $this->assertTrue(DBVC_Third_Party_Portability::is_wsform_settings_payload($payload));
        $this->assertTrue(DBVC_Third_Party_Portability::is_third_party_payload($payload));
        $this->assertFalse(DBVC_Third_Party_Portability::is_wsform_form_payload($payload));
    }

    public function test_detects_wsform_form_template_without_export_object(): void
    {
        $payload = [
            'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
            'label'      => 'OpenAI Chat Simple',
            'version'    => '1.9.210',
            'meta'       => [
                'label_position_form' => 'top',
            ],
            'groups'     => [
                [
                    'label'    => 'Default',
                    'sections' => [],
                ],
            ],
        ];

        $this->assertTrue(DBVC_Third_Party_Portability::is_wsform_payload($payload));
        $this->assertTrue(DBVC_Third_Party_Portability::is_wsform_form_payload($payload));
        $this->assertSame('form', DBVC_Third_Party_Portability::get_wsform_payload_object_type($payload));
    }

    public function test_detects_explicit_non_form_wsform_object_type(): void
    {
        $payload = [
            'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
            'label'      => 'Reusable Section',
            'meta'       => [
                'export_object' => 'section',
            ],
        ];

        $this->assertTrue(DBVC_Third_Party_Portability::is_wsform_payload($payload));
        $this->assertFalse(DBVC_Third_Party_Portability::is_wsform_form_payload($payload));
        $this->assertFalse(DBVC_Third_Party_Portability::is_third_party_payload($payload));
        $this->assertSame('section', DBVC_Third_Party_Portability::get_wsform_payload_object_type($payload));
    }

    public function test_wsform_form_filename_uses_source_id_and_sanitized_label(): void
    {
        $payload = [
            'id'     => 15,
            'label'  => 'New Web Client Onboarding Form!',
            'dbvc_portability' => [
                'source_id' => 15,
            ],
        ];

        $this->assertSame(
            'ws-form-15-new-web-client-onboarding-form.json',
            DBVC_Third_Party_Portability::determine_wsform_form_filename_from_payload($payload)
        );
    }

    public function test_wsform_snapshot_export_rejects_invalid_form_id(): void
    {
        $result = DBVC_Third_Party_Portability::export_wsform_form_snapshot_for_entity_editor(0);

        $this->assertWPError($result);
        $this->assertSame('dbvc_wsform_snapshot_invalid_form', $result->get_error_code());
    }

    public function test_wsform_snapshot_export_accepts_filtered_payload(): void
    {
        $filter = static function ($payload, $form_id) {
            return [
                'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                'id'         => (int) $form_id,
                'label'      => 'Filtered Snapshot Form',
                'meta'       => [
                    'export_object'        => 'form',
                    'dbvc_portability_uid' => 'filtered-snapshot-uid',
                ],
                'dbvc_portability' => [
                    'provider'    => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                    'object_type' => 'form',
                    'uid'         => 'filtered-snapshot-uid',
                    'source_id'   => (int) $form_id,
                ],
            ];
        };

        add_filter('dbvc_third_party_wsform_form_snapshot_payload', $filter, 10, 2);
        try {
            $result = DBVC_Third_Party_Portability::export_wsform_form_snapshot_for_entity_editor(900);
        } finally {
            remove_filter('dbvc_third_party_wsform_form_snapshot_payload', $filter, 10);
        }

        $this->assertIsArray($result);
        $this->assertTrue(DBVC_Third_Party_Portability::is_wsform_form_payload($result));
        $this->assertSame('Filtered Snapshot Form', (string) ($result['label'] ?? ''));
        $this->assertSame(900, (int) ($result['dbvc_portability']['source_id'] ?? 0));
    }

    public function test_sensitive_wsform_settings_are_excluded_recursively(): void
    {
        $method = new ReflectionMethod(DBVC_Third_Party_Portability::class, 'filter_sensitive_settings');
        $method->setAccessible(true);

        $excluded = [];
        $filtered = $method->invokeArgs(null, [
            [
                'framework' => 'tailwind',
                'license_key' => 'do-not-export',
                'nested' => [
                    'api_key_google_map' => 'do-not-export',
                    'safe_setting' => 'keep-me',
                ],
            ],
            &$excluded,
        ]);

        $this->assertSame('tailwind', $filtered['framework']);
        $this->assertSame('keep-me', $filtered['nested']['safe_setting']);
        $this->assertArrayNotHasKey('license_key', $filtered);
        $this->assertArrayNotHasKey('api_key_google_map', $filtered['nested']);
        $this->assertContains('license_key', $excluded);
        $this->assertContains('nested.api_key_google_map', $excluded);
    }

    public function test_sensitive_wsform_settings_are_preserved_during_merge(): void
    {
        $method = new ReflectionMethod(DBVC_Third_Party_Portability::class, 'merge_preserving_sensitive_settings');
        $method->setAccessible(true);

        $merged = $method->invoke(null, [
            'framework' => 'old',
            'license_key' => 'local-secret',
            'nested' => [
                'secret_key' => 'nested-local-secret',
                'safe_setting' => 'old-safe',
            ],
        ], [
            'framework' => 'new',
            'license_key' => 'incoming-secret',
            'nested' => [
                'secret_key' => 'incoming-nested-secret',
                'safe_setting' => 'new-safe',
            ],
        ]);

        $this->assertSame('new', $merged['framework']);
        $this->assertSame('local-secret', $merged['license_key']);
        $this->assertSame('nested-local-secret', $merged['nested']['secret_key']);
        $this->assertSame('new-safe', $merged['nested']['safe_setting']);
    }
}
