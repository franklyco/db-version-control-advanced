<?php

class ContentMigrationPhase37W0SettingsTest extends WP_UnitTestCase
{
    public function test_mapping_and_media_bridges_default_on_while_write_capable_flags_default_off(): void
    {
        $defaults = DBVC_CC_Contracts::get_feature_flag_defaults();

        // The two bridges only read: the target field catalog, the crawl artifacts already
        // on disk, and media candidates discovered from those artifacts. They write nothing
        // to the site and make no outbound request, so they are on by default.
        $this->assertArrayHasKey(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, $defaults);
        $this->assertArrayHasKey(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, $defaults);
        $this->assertSame('1', $defaults[DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE]);
        $this->assertSame('1', $defaults[DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE]);

        // What must stay off is the half that writes to the site or leaves it.
        $this->assertSame('0', $defaults[DBVC_CC_Contracts::OPTION_FLAG_IMPORT_PLAN]);
        $this->assertSame('0', $defaults[DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE]);
        $this->assertSame('0', $defaults[DBVC_CC_Contracts::OPTION_FLAG_EXPORT]);
    }

    public function test_enabling_the_bridges_does_not_enable_import_execution(): void
    {
        DBVC_CC_Contracts::ensure_phase_zero_defaults();

        $this->assertTrue(DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE));
        $this->assertTrue(DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE));
        $this->assertFalse(DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE));
    }

    public function test_phase37_settings_defaults_include_mapping_and_media_controls(): void
    {
        $defaults = DBVC_CC_Contracts::get_settings_defaults();

        $this->assertSame(DBVC_CC_Contracts::MAPPING_CATALOG_REFRESH_REUSE, $defaults['dbvc_cc_mapping_catalog_refresh_strategy']);
        $this->assertSame(0.65, (float) $defaults['dbvc_cc_mapping_candidate_confidence_threshold']);
        $this->assertSame(0.70, (float) $defaults['dbvc_cc_media_mapping_confidence_threshold']);
        $this->assertSame(DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_METADATA_FIRST, $defaults['dbvc_cc_media_discovery_mode']);
        $this->assertSame(DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY, $defaults['dbvc_cc_media_download_policy']);
        $this->assertSame(8388608, (int) $defaults['dbvc_cc_media_max_bytes_per_asset']);
        $this->assertSame(1, (int) $defaults['dbvc_cc_media_preview_thumbnail_enabled']);
        $this->assertSame(1, (int) $defaults['dbvc_cc_media_block_private_hosts']);
    }

    public function test_sanitize_settings_normalizes_phase37_mapping_and_media_inputs(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, DBVC_CC_Contracts::get_settings_defaults());

        $sanitized = DBVC_CC_Settings_Service::sanitize_settings(
            [
                'dbvc_cc_mapping_catalog_refresh_strategy' => 'always-rebuild',
                'dbvc_cc_mapping_candidate_confidence_threshold' => '1.7',
                'dbvc_cc_media_mapping_confidence_threshold' => '-0.5',
                'dbvc_cc_media_discovery_mode' => 'selective-download',
                'dbvc_cc_media_download_policy' => 'download_selected',
                'dbvc_cc_media_mime_allowlist' => 'image/jpeg, video/mp4, text/html, not/a mime, image/webp',
                'dbvc_cc_media_max_bytes_per_asset' => '500000000',
                'dbvc_cc_media_preview_thumbnail_enabled' => 1,
                'dbvc_cc_media_block_private_hosts' => '',
                'dbvc_cc_media_source_domain_allowlist' => "cdn.example.com\nimages.example.com",
                'dbvc_cc_media_source_domain_denylist' => "bad.example.com<script>alert(1)</script>",
            ]
        );

        $this->assertSame(DBVC_CC_Contracts::MAPPING_CATALOG_REFRESH_ALWAYS, $sanitized['dbvc_cc_mapping_catalog_refresh_strategy']);
        $this->assertSame(1.0, (float) $sanitized['dbvc_cc_mapping_candidate_confidence_threshold']);
        $this->assertSame(0.0, (float) $sanitized['dbvc_cc_media_mapping_confidence_threshold']);
        $this->assertSame(DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_SELECTIVE_DOWNLOAD, $sanitized['dbvc_cc_media_discovery_mode']);
        $this->assertSame(DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_DOWNLOAD_SELECTED, $sanitized['dbvc_cc_media_download_policy']);
        $this->assertSame('image/jpeg,video/mp4,image/webp', $sanitized['dbvc_cc_media_mime_allowlist']);
        $this->assertSame(104857600, (int) $sanitized['dbvc_cc_media_max_bytes_per_asset']);
        $this->assertSame(1, (int) $sanitized['dbvc_cc_media_preview_thumbnail_enabled']);
        $this->assertSame(0, (int) $sanitized['dbvc_cc_media_block_private_hosts']);
        $this->assertSame("cdn.example.com\nimages.example.com", $sanitized['dbvc_cc_media_source_domain_allowlist']);
        $this->assertSame('bad.example.com', $sanitized['dbvc_cc_media_source_domain_denylist']);
    }

    public function test_sanitize_settings_falls_back_for_invalid_phase37_inputs(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, DBVC_CC_Contracts::get_settings_defaults());

        $sanitized = DBVC_CC_Settings_Service::sanitize_settings(
            [
                'dbvc_cc_mapping_catalog_refresh_strategy' => 'not-valid',
                'dbvc_cc_media_discovery_mode' => 'invalid-mode',
                'dbvc_cc_media_download_policy' => 'invalid-policy',
                'dbvc_cc_media_mime_allowlist' => 'text/html,application/xphp-script',
            ]
        );

        $this->assertSame(DBVC_CC_Contracts::MAPPING_CATALOG_REFRESH_REUSE, $sanitized['dbvc_cc_mapping_catalog_refresh_strategy']);
        $this->assertSame(DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_METADATA_FIRST, $sanitized['dbvc_cc_media_discovery_mode']);
        $this->assertSame(DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY, $sanitized['dbvc_cc_media_download_policy']);
        $this->assertSame('application/xphp-script', $sanitized['dbvc_cc_media_mime_allowlist']);
    }
}
