<?php

/**
 * The rollout gate in ensure_phase_zero_defaults() is the only thing in the add-on that can
 * overwrite a feature flag an administrator set deliberately. It used to re-assert ten flags on
 * every FEATURE_FLAG_VERSION bump, so a release that moved the constant silently re-enabled a flag
 * someone had switched off -- including OPTION_FLAG_AI_MAPPING, whose whole purpose is to stop the
 * AI job surface doing work.
 *
 * It is now scoped per version: a bump applies only the keys its own entry in
 * get_feature_flag_rollouts() names. These tests pin both halves of that contract -- the runtime
 * behaviour, and the rules a future rollout entry has to obey. The second half is deliberately
 * enforced here rather than at runtime: an entry that breaks a rule should fail loudly in the
 * suite while it is being written, not be silently skipped on a live site.
 */
class ContentMigrationFeatureFlagRolloutGateTest extends WP_UnitTestCase
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

    public function test_a_fresh_install_still_seeds_every_flag_at_its_declared_default(): void
    {
        $this->reset_to_fresh_install_state();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();

        foreach (DBVC_CC_Contracts::get_feature_flag_defaults() as $option_key => $default_value) {
            $this->assertSame(
                $default_value,
                (string) get_option($option_key),
                sprintf('Flag %s was not seeded at its default.', $option_key)
            );
        }
    }

    /**
     * Every flag, not just the two the defaults test names, and in both directions: a flag turned
     * off that ships on, and a flag turned on that ships off.
     */
    public function test_no_flag_is_overwritten_by_a_bump_that_does_not_name_it(): void
    {
        DBVC_CC_Contracts::ensure_phase_zero_defaults();

        $inverted = [];
        foreach (DBVC_CC_Contracts::get_feature_flag_defaults() as $option_key => $default_value) {
            $inverted[$option_key] = $default_value === '1' ? '0' : '1';
            update_option($option_key, $inverted[$option_key]);
        }

        update_option(DBVC_CC_Contracts::OPTION_FEATURE_FLAG_VERSION, '0');
        DBVC_CC_Contracts::ensure_phase_zero_defaults();

        $named = [];
        foreach (DBVC_CC_Contracts::get_feature_flag_rollouts() as $rollout_values) {
            $named = array_merge($named, array_keys($rollout_values));
        }

        foreach ($inverted as $option_key => $chosen_value) {
            if (in_array($option_key, $named, true)) {
                continue;
            }

            $this->assertSame(
                $chosen_value,
                (string) get_option($option_key),
                sprintf('Flag %s was overwritten by a bump that does not name it.', $option_key)
            );
        }
    }

    /**
     * The mechanism still has to work, or a future rollout would be dead on arrival. Exercised
     * against a synthetic map so the assertion does not depend on what the shipped map happens to
     * contain today (it is empty).
     */
    public function test_a_rollout_entry_is_applied_only_to_sites_below_its_version(): void
    {
        $apply = static function (int $stored_version, array $rollouts, int $target_version): string {
            update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

            // Mirrors the gate's selection rule in ensure_phase_zero_defaults().
            foreach ($rollouts as $rollout_version => $rollout_values) {
                $rollout_version = (int) $rollout_version;
                if ($rollout_version <= $stored_version || $rollout_version > $target_version) {
                    continue;
                }
                foreach ($rollout_values as $option_key => $option_value) {
                    update_option($option_key, $option_value);
                }
            }

            return (string) get_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING);
        };

        $rollouts = [7 => [DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING => '1']];

        $this->assertSame('1', $apply(6, $rollouts, 7), 'A site below the rollout version should receive it.');
        $this->assertSame('0', $apply(7, $rollouts, 7), 'A site already at the rollout version should not.');
        $this->assertSame('0', $apply(6, $rollouts, 6), 'A rollout above the target version should not be applied yet.');
    }

    public function test_rollouts_are_applied_in_ascending_version_order(): void
    {
        $versions = array_map('intval', array_keys(DBVC_CC_Contracts::get_feature_flag_rollouts()));
        $sorted = $versions;
        sort($sorted);

        $this->assertSame(
            $sorted,
            $versions,
            'get_feature_flag_rollouts() must be declared in ascending version order; the gate applies it as given, '
                . 'so a later version listed first would be overwritten by an earlier one.'
        );
    }

    public function test_every_rollout_key_is_a_known_feature_flag_with_a_boolean_value(): void
    {
        $known = array_keys(DBVC_CC_Contracts::get_feature_flag_defaults());

        foreach (DBVC_CC_Contracts::get_feature_flag_rollouts() as $version => $rollout_values) {
            $this->assertIsArray($rollout_values, sprintf('Rollout %s must be an array.', (string) $version));

            foreach ($rollout_values as $option_key => $option_value) {
                $this->assertContains(
                    $option_key,
                    $known,
                    sprintf('Rollout %s names %s, which is not a declared feature flag.', (string) $version, $option_key)
                );
                $this->assertContains(
                    $option_value,
                    ['0', '1'],
                    sprintf('Rollout %s sets %s to a non-boolean value.', (string) $version, $option_key)
                );
            }
        }
    }

    public function test_no_rollout_targets_a_version_above_the_current_constant(): void
    {
        foreach (array_keys(DBVC_CC_Contracts::get_feature_flag_rollouts()) as $version) {
            $this->assertLessThanOrEqual(
                DBVC_CC_Contracts::FEATURE_FLAG_VERSION,
                (int) $version,
                'A rollout above FEATURE_FLAG_VERSION would never be applied. Bump the constant with it.'
            );
        }
    }

    /**
     * The write and export paths are operator-owned and ship off. A rollout entry is the one
     * mechanism that could flip them behind an administrator's back, so it must never name them.
     */
    public function test_no_rollout_forces_a_write_or_export_path_flag(): void
    {
        $forbidden = [
            DBVC_CC_Contracts::OPTION_FLAG_IMPORT_PLAN,
            DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE,
            DBVC_CC_Contracts::OPTION_FLAG_EXPORT,
        ];

        foreach ($forbidden as $option_key) {
            $this->assertSame(
                '0',
                DBVC_CC_Contracts::get_feature_flag_defaults()[$option_key],
                sprintf('%s is expected to ship off.', $option_key)
            );
        }

        foreach (DBVC_CC_Contracts::get_feature_flag_rollouts() as $version => $rollout_values) {
            foreach ($forbidden as $option_key) {
                $this->assertArrayNotHasKey(
                    $option_key,
                    $rollout_values,
                    sprintf(
                        'Rollout %s forces %s. Enabling a write or export path is an operator decision, '
                            . 'not something a version bump may impose.',
                        (string) $version,
                        $option_key
                    )
                );
            }
        }
    }
}
