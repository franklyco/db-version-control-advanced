<?php

class ContentMigrationPhase36LeakGuardTest extends WP_UnitTestCase
{
    public function test_attribute_scrub_outputs_do_not_retain_raw_sensitive_attribute_values(): void
    {
        $html = '<html><body><main>'
            . '<h1 class="secret-class-token" id="secret-id-token">Leak Guard Fixture</h1>'
            . '<p data-secret="sensitive-data-token" style="display:none">Hidden copy</p>'
            . '</main></body></html>';

        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        $options = DBVC_CC_Contracts::get_settings_defaults();
        $options['capture_include_attribute_context'] = 1;
        $options['scrub_policy_enabled'] = 1;

        $artifacts = DBVC_CC_Element_Extractor_Service::extract_artifacts($xpath, [$doc], 'https://phase36-leak-guard.test/', $options);
        $elements_payload = isset($artifacts['elements']) && is_array($artifacts['elements']) ? $artifacts['elements'] : [];
        $scrub_report = isset($artifacts['scrub_report']) && is_array($artifacts['scrub_report']) ? $artifacts['scrub_report'] : [];

        $json = (string) wp_json_encode(
            [
                'elements' => $elements_payload,
                'report' => $scrub_report,
            ]
        );

        $this->assertStringNotContainsString('secret-class-token', $json);
        $this->assertStringNotContainsString('secret-id-token', $json);
        $this->assertStringNotContainsString('sensitive-data-token', $json);
    }

    public function test_observability_sanitizer_redacts_api_like_tokens(): void
    {
        $crawler = new DBVC_CC_Crawler_Service('https://phase36-leak-guard.test/');
        $reflection = new ReflectionClass($crawler);
        $method = $reflection->getMethod('dbvc_cc_sanitize_observability_value');
        $method->setAccessible(true);

        $input = [
            'message' => 'api_key=sk-abcdefghijklmnopqrstuvwxyz123456 Bearer abcdefghijklmnopqrstuvwxyz012345',
            'nested' => [
                'raw' => 'api-key: sk-1234567890ABCDEFGHIJKLMNOP',
            ],
        ];
        $sanitized = $method->invoke($crawler, $input);

        $this->assertIsArray($sanitized);
        $encoded = (string) wp_json_encode($sanitized);
        $this->assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz123456', $encoded);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz012345', $encoded);
        $this->assertStringNotContainsString('sk-1234567890ABCDEFGHIJKLMNOP', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
    }
}
