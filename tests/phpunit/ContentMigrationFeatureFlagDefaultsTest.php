<?php

/**
 * The feature-flag defaults array is not only an initializer. is_feature_enabled() uses it as the
 * get_option() fallback, so whenever it disagrees with what ensure_phase_zero_defaults() actually
 * leaves in the database, a site whose option rows were never written reports the opposite of the
 * shipped rollout -- and the admin UI then disagrees with the gate.
 */
class ContentMigrationFeatureFlagDefaultsTest extends WP_UnitTestCase
{
    /**
     * @return void
     */
    private function reset_to_fresh_install_state(): void
    {
        foreach (array_keys(DBVC_CC_Contracts::get_feature_flag_defaults()) as $option_key) {
            delete_option($option_key);
        }

        delete_option(DBVC_CC_Contracts::OPTION_FEATURE_FLAG_VERSION);
    }

    public function test_every_flag_default_matches_what_a_fresh_install_stores(): void
    {
        $this->reset_to_fresh_install_state();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();

        foreach (DBVC_CC_Contracts::get_feature_flag_defaults() as $option_key => $default_value) {
            $this->assertSame(
                $default_value,
                (string) get_option($option_key),
                sprintf(
                    'Flag %s is declared as %s but ensure_phase_zero_defaults() stored %s.',
                    $option_key,
                    var_export($default_value, true),
                    var_export(get_option($option_key), true)
                )
            );
        }
    }

    public function test_is_feature_enabled_agrees_with_the_stored_flag_when_the_option_row_is_absent(): void
    {
        $this->reset_to_fresh_install_state();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();

        $stored = [];
        foreach (array_keys(DBVC_CC_Contracts::get_feature_flag_defaults()) as $option_key) {
            $stored[$option_key] = DBVC_CC_Contracts::is_feature_enabled($option_key);
        }

        // Delete the rows again. is_feature_enabled() now answers purely from the defaults array,
        // which is the case this invariant protects.
        foreach (array_keys(DBVC_CC_Contracts::get_feature_flag_defaults()) as $option_key) {
            delete_option($option_key);

            $this->assertSame(
                $stored[$option_key],
                DBVC_CC_Contracts::is_feature_enabled($option_key),
                sprintf('Flag %s reports a different state once its option row is gone.', $option_key)
            );
        }
    }

    public function test_ai_mapping_and_the_workbench_ship_on_while_the_write_and_outbound_paths_stay_closed(): void
    {
        $defaults = DBVC_CC_Contracts::get_feature_flag_defaults();

        // Both register administrator-capability surfaces over artifacts already on disk.
        $this->assertSame('1', $defaults[DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING]);
        $this->assertSame('1', $defaults[DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH]);

        // AI mapping being on is not an outbound capability: the OpenAI request is reached only
        // through build_ai_outputs(), which fails closed while openai_api_key is empty.
        $this->assertSame('', DBVC_CC_Contracts::get_settings_defaults()['openai_api_key']);

        // Writing to the site stays separately gated.
        $this->assertSame('0', $defaults[DBVC_CC_Contracts::OPTION_FLAG_IMPORT_PLAN]);
        $this->assertSame('0', $defaults[DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE]);
        $this->assertSame('0', $defaults[DBVC_CC_Contracts::OPTION_FLAG_EXPORT]);
    }

    public function test_the_version_gate_re_asserts_shipped_flag_values_on_upgrade(): void
    {
        DBVC_CC_Contracts::ensure_phase_zero_defaults();

        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '0');
        update_option(DBVC_CC_Contracts::OPTION_FEATURE_FLAG_VERSION, (string) (DBVC_CC_Contracts::FEATURE_FLAG_VERSION - 1));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();

        // Documented, deliberate: the gate restores the shipped value and so discards an
        // administrator's explicit off for these keys. If that override is ever narrowed, this
        // test should change with it rather than be deleted.
        $this->assertSame('1', (string) get_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING));
        $this->assertSame('1', (string) get_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH));
        $this->assertSame(
            (string) DBVC_CC_Contracts::FEATURE_FLAG_VERSION,
            (string) get_option(DBVC_CC_Contracts::OPTION_FEATURE_FLAG_VERSION)
        );
    }
}
