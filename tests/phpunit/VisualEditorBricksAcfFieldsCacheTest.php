<?php

use Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache;
use Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCacheTriggers;
use Dbvc\VisualEditor\Performance\Bricks\ProviderFingerprint;

/**
 * R5.later-perf-b — persistent Bricks ACF fields cache (E-161, D-083).
 *
 * No Bricks in the test environment: covers encoding, fingerprint composition,
 * triggers → salt bumps + warm scheduling, cron scheduling on toggle, the
 * fail-closed gate and context rules, settings wiring, and the invariant that
 * nothing is written unless a trigger fires or the switch is saved.
 */
class VisualEditorBricksAcfFieldsCacheTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            AcfFieldsCache::OPTION_ENABLED, AcfFieldsCache::OPTION_SALT, AcfFieldsCache::OPTION_VERIFIED, AcfFieldsCache::OPTION_META,
            DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION, 'cron',
        ] as $option) {
            $this->previous_options[$option] = get_option($option, '__dbvc_missing_option__');
        }

        AcfFieldsCache::reset();
        AcfFieldsCacheTriggers::reset();
        delete_transient(AcfFieldsCache::transientName());
    }

    public function tear_down(): void
    {
        AcfFieldsCache::clearSchedules();

        foreach ($this->previous_options as $option => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option);
            } else {
                update_option($option, $value);
            }
        }

        delete_transient(AcfFieldsCache::transientName());
        AcfFieldsCache::reset();
        AcfFieldsCacheTriggers::reset();
        remove_all_filters('dbvc_visual_editor_bricks_acf_fields_cache_enabled');
        remove_all_filters('dbvc_visual_editor_bricks_acf_fields_cache_trigger_post_types');
        remove_all_filters('dbvc_visual_editor_bricks_acf_fields_cache_salt');
        remove_all_filters('dbvc_visual_editor_bricks_acf_fields_cache_ttl');
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // Payload
    // ---------------------------------------------------------------------

    public function test_encode_decode_round_trips_nested_arrays_and_rejects_garbage(): void
    {
        $fields = [
            ['key' => 'field_a', 'type' => 'group', 'sub_fields' => [['key' => 'field_b', 'type' => 'text', 'choices' => ['x' => 'Ünïcode ✓']]], '_bricks_locations' => ['option' => 1]],
            ['key' => 'field_c', 'type' => 'repeater', 'sub_fields' => [], 'conditional_logic' => 0, 'wrapper' => ['width' => '', 'class' => '', 'id' => '']],
        ];
        $encoded = AcfFieldsCache::encode($fields);
        $this->assertIsString($encoded);
        $this->assertSame($fields, AcfFieldsCache::decode($encoded));
        $this->assertNull(AcfFieldsCache::decode('not base64 !!'));
        $this->assertNull(AcfFieldsCache::decode(base64_encode('not gzip')));
        $this->assertNull(AcfFieldsCache::decode(base64_encode(gzcompress(serialize('a string, not an array')))));
        $this->assertNull(AcfFieldsCache::decode(base64_encode(gzcompress('O:8:"stdClass":0:{}'))), 'objects are never unserialised');
    }

    public function test_store_and_read_payload_carry_fingerprint_registrars_and_meta_and_honour_the_size_cap(): void
    {
        $fields = [['key' => 'field_a', 'type' => 'text']];
        $this->assertSame('stored', AcfFieldsCache::storeFields($fields, 'test'));
        $payload = AcfFieldsCache::readPayload();
        $this->assertNotNull($payload);
        $this->assertSame($fields, $payload['fields']);
        $this->assertSame(AcfFieldsCache::fingerprint($payload['registrars']), $payload['fingerprint'], 'fingerprint stored is the current one for the recorded registrars');
        $this->assertGreaterThan(0, $payload['built']);
        $meta = get_option(AcfFieldsCache::OPTION_META);
        $this->assertSame('test', $meta['built_by']);
        $this->assertSame(1, $meta['fields']);

        add_filter('dbvc_visual_editor_bricks_acf_fields_cache_size_cap', function () { return 10; });
        $this->assertSame('payload_too_large', AcfFieldsCache::storeFields($fields, 'test'));
        remove_all_filters('dbvc_visual_editor_bricks_acf_fields_cache_size_cap');
        $this->assertSame('payload_too_large', get_option(AcfFieldsCache::OPTION_META)['last_error']);
    }

    public function test_ttl_is_filterable_with_a_floor(): void
    {
        $this->assertSame(12 * HOUR_IN_SECONDS, AcfFieldsCache::ttl());
        add_filter('dbvc_visual_editor_bricks_acf_fields_cache_ttl', function () { return 10; });
        $this->assertSame(5 * MINUTE_IN_SECONDS, AcfFieldsCache::ttl(), 'floor');
        remove_all_filters('dbvc_visual_editor_bricks_acf_fields_cache_ttl');
        add_filter('dbvc_visual_editor_bricks_acf_fields_cache_ttl', function () { return DAY_IN_SECONDS; });
        $this->assertSame(DAY_IN_SECONDS, AcfFieldsCache::ttl());
    }

    // ---------------------------------------------------------------------
    // Fingerprint
    // ---------------------------------------------------------------------

    public function test_fingerprint_is_stable_per_request_and_changes_with_salt_extra_and_registrar_mtimes(): void
    {
        $base = AcfFieldsCache::fingerprint();
        $this->assertSame($base, AcfFieldsCache::fingerprint(), 'memoised');

        // Salt bump → different.
        AcfFieldsCache::flush('test');
        AcfFieldsCache::reset();
        $bumped = AcfFieldsCache::fingerprint();
        $this->assertNotSame($base, $bumped);

        // Site-owner extra → different.
        add_filter('dbvc_visual_editor_bricks_acf_fields_cache_salt', function () { return 'forms:v2'; });
        AcfFieldsCache::reset();
        $extra = AcfFieldsCache::fingerprint();
        $this->assertNotSame($bumped, $extra);
        remove_all_filters('dbvc_visual_editor_bricks_acf_fields_cache_salt');

        // Registrar file mtime → different (relative path under ABSPATH).
        $dir = ABSPATH . 'wp-content/dbvc-test-registrar';
        wp_mkdir_p($dir);
        $file = $dir . '/register.php';
        file_put_contents($file, "<?php acf_add_local_field_group([]);\n");
        touch($file, 1700000000);
        AcfFieldsCache::reset();
        $before = AcfFieldsCache::fingerprint(['wp-content/dbvc-test-registrar/register.php' => 1700000000]);
        touch($file, 1700000999);
        clearstatcache();
        AcfFieldsCache::reset();
        $after = AcfFieldsCache::fingerprint(['wp-content/dbvc-test-registrar/register.php' => 1700000000]);
        $this->assertNotSame($before, $after, 'editing a registering file invalidates without any version bump');

        // Registrar discovery finds the file (theme roots + active plugins are scanned; add ours as a plugin root).
        add_filter('option_active_plugins', function ($plugins) { return array_merge((array) $plugins, ['../dbvc-test-registrar/register.php']); });
        $found = AcfFieldsCache::discoverRegistrarFiles();
        remove_all_filters('option_active_plugins');
        $this->assertArrayHasKey('wp-content/dbvc-test-registrar/register.php', $found);

        unlink($file);
        rmdir($dir);
    }

    public function test_locale_is_part_of_the_transient_name(): void
    {
        $name = AcfFieldsCache::transientName();
        $this->assertStringStartsWith(AcfFieldsCache::TRANSIENT_PREFIX, $name);
        add_filter('locale', function () { return 'de_DE'; });
        add_filter('pre_determine_locale', function () { return 'de_DE'; });
        $this->assertNotSame($name, AcfFieldsCache::transientName());
        remove_all_filters('locale');
        remove_all_filters('pre_determine_locale');
    }

    // ---------------------------------------------------------------------
    // Triggers, warm-up, cron
    // ---------------------------------------------------------------------

    public function test_triggers_bump_the_salt_once_per_request_and_schedule_a_warm_up(): void
    {
        update_option(AcfFieldsCache::OPTION_ENABLED, '1');
        AcfFieldsCacheTriggers::register();
        $this->assertSame('', get_option(AcfFieldsCache::OPTION_SALT, ''));
        $this->assertFalse(wp_next_scheduled(AcfFieldsCache::CRON_WARM));

        do_action('acf/update_field_group', ['key' => 'group_x']);
        $salt = get_option(AcfFieldsCache::OPTION_SALT);
        $this->assertNotSame('', $salt);
        $this->assertNotFalse(wp_next_scheduled(AcfFieldsCache::CRON_WARM), 'warm-up scheduled');
        $this->assertSame('acf/update_field_group', get_option(AcfFieldsCache::OPTION_META)['flushed_by']);
        $this->assertSame(100, has_action('shutdown', 'spawn_cron'));

        // A second trigger in the same request is a no-op (one bump per request).
        do_action('switch_theme', 'x', null, null);
        $this->assertSame($salt, get_option(AcfFieldsCache::OPTION_SALT));
    }

    public function test_save_post_trigger_respects_the_filterable_post_type_list(): void
    {
        update_option(AcfFieldsCache::OPTION_ENABLED, '1');
        AcfFieldsCacheTriggers::register();
        $this->assertSame(['acf-field-group', 'acf-field'], AcfFieldsCacheTriggers::postTypes());

        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->assertSame('', get_option(AcfFieldsCache::OPTION_SALT, ''), 'a page save is not a trigger by default');

        add_filter('dbvc_visual_editor_bricks_acf_fields_cache_trigger_post_types', function ($types) { $types[] = 'page'; return $types; });
        $this->assertContains('page', AcfFieldsCacheTriggers::postTypes());
        wp_update_post(['ID' => $page, 'post_title' => 'Edited']);
        $this->assertNotSame('', get_option(AcfFieldsCache::OPTION_SALT, ''), 'configured post type flushes on save');
        $this->assertSame('save_post:page', get_option(AcfFieldsCache::OPTION_META)['flushed_by']);
    }

    public function test_verify_schedule_is_ensured_and_cleared(): void
    {
        $this->assertFalse(wp_next_scheduled(AcfFieldsCache::CRON_VERIFY));
        AcfFieldsCache::ensureVerifySchedule();
        $first = wp_next_scheduled(AcfFieldsCache::CRON_VERIFY);
        $this->assertNotFalse($first);
        AcfFieldsCache::ensureVerifySchedule();
        $this->assertSame($first, wp_next_scheduled(AcfFieldsCache::CRON_VERIFY), 'idempotent');
        AcfFieldsCache::scheduleWarm();
        AcfFieldsCache::clearSchedules();
        $this->assertFalse(wp_next_scheduled(AcfFieldsCache::CRON_VERIFY));
        $this->assertFalse(wp_next_scheduled(AcfFieldsCache::CRON_WARM));
    }

    // ---------------------------------------------------------------------
    // Gate, contexts, runtime invariants
    // ---------------------------------------------------------------------

    public function test_defaults_off_settings_version_and_gate_without_bricks(): void
    {
        delete_option(AcfFieldsCache::OPTION_ENABLED);
        update_option(DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION, '8');
        DBVC_Visual_Editor_Addon::ensure_defaults();
        $this->assertSame('0', get_option(AcfFieldsCache::OPTION_ENABLED));
        $this->assertSame('9', get_option(DBVC_Visual_Editor_Addon::OPTION_SETTINGS_VERSION));
        $this->assertFalse(AcfFieldsCache::isEnabled());
        $this->assertSame('bricks_not_loaded', AcfFieldsCache::gate());
        $this->assertNull(ProviderFingerprint::fieldsCacheCurrent());
        $this->assertFalse(ProviderFingerprint::isKnownFieldsCache(null));
        $this->assertNotEmpty(ProviderFingerprint::KNOWN_FIELDS_CACHE);

        // Disabled: one cheap hook, nothing armed.
        AcfFieldsCache::register();
        $this->assertSame(0, has_action('after_setup_theme', [AcfFieldsCache::class, 'arm']));
        AcfFieldsCache::arm();
        $this->assertSame('disabled', AcfFieldsCache::state());
        $this->assertFalse(has_action('init', [AcfFieldsCache::class, 'seed']));
        $this->assertFalse(has_action('init', [AcfFieldsCache::class, 'capture']));

        // Enabled without Bricks: triggers register, but seed/capture never hook.
        AcfFieldsCache::reset();
        update_option(AcfFieldsCache::OPTION_ENABLED, '1');
        AcfFieldsCache::register();
        AcfFieldsCache::arm();
        $this->assertSame('bricks_not_loaded', AcfFieldsCache::state());
        $this->assertFalse(has_action('init', [AcfFieldsCache::class, 'seed']));
        $this->assertFalse(has_action('init', [AcfFieldsCache::class, 'capture']));

        // seed()/capture()/verify() are defensive when called directly.
        AcfFieldsCache::seed();
        $this->assertSame('skipped_context', AcfFieldsCache::state(), 'without Bricks helpers the context check refuses');
        AcfFieldsCache::capture();
        $result = AcfFieldsCache::verify(true);
        $this->assertFalse($result['ok']);
        $this->assertSame('admin_context', $result['reason']);
        $this->assertSame('', get_option(AcfFieldsCache::OPTION_VERIFIED, ''), 'refused verify writes nothing');
        $this->assertFalse(get_transient(AcfFieldsCache::transientName()), 'nothing stored by a refused path');
    }

    public function test_filter_can_veto_or_force_the_switch(): void
    {
        update_option(AcfFieldsCache::OPTION_ENABLED, '1');
        add_filter('dbvc_visual_editor_bricks_acf_fields_cache_enabled', '__return_false');
        $this->assertFalse(AcfFieldsCache::isEnabled());
        remove_filter('dbvc_visual_editor_bricks_acf_fields_cache_enabled', '__return_false');
        update_option(AcfFieldsCache::OPTION_ENABLED, '0');
        add_filter('dbvc_visual_editor_bricks_acf_fields_cache_enabled', '__return_true');
        $this->assertTrue(AcfFieldsCache::isEnabled());
    }

    public function test_settings_wiring_save_path_and_actions(): void
    {
        $groups = DBVC_Visual_Editor_Addon::get_settings_groups();
        $this->assertContains(AcfFieldsCache::OPTION_ENABLED, $groups['performance_bricks']['fields']);
        $meta = DBVC_Visual_Editor_Addon::get_field_meta()[AcfFieldsCache::OPTION_ENABLED];
        $this->assertSame('checkbox', $meta['input']);
        $this->assertStringContainsString('gate: bricks_not_loaded', $meta['help']);
        $this->assertStringContainsString('12-hour TTL', $meta['help']);
        $this->assertSame(['bricks-acf-fields-cache-rebuild', 'bricks-acf-fields-cache-verify'], array_column($meta['actions'], 'action'));

        // Enabling schedules the daily verify and flushes (warm-up scheduled); disabling clears both.
        $result = DBVC_Visual_Editor_Addon::save_settings([AcfFieldsCache::OPTION_ENABLED => 'on']);
        $this->assertSame('1', $result['values'][AcfFieldsCache::OPTION_ENABLED]);
        $this->assertNotFalse(wp_next_scheduled(AcfFieldsCache::CRON_VERIFY));
        $this->assertNotFalse(wp_next_scheduled(AcfFieldsCache::CRON_WARM));
        $this->assertSame('enabled', get_option(AcfFieldsCache::OPTION_META)['flushed_by']);

        $result = DBVC_Visual_Editor_Addon::save_settings([]);
        $this->assertSame('0', $result['values'][AcfFieldsCache::OPTION_ENABLED]);
        $this->assertFalse(wp_next_scheduled(AcfFieldsCache::CRON_VERIFY));
        $this->assertFalse(wp_next_scheduled(AcfFieldsCache::CRON_WARM));

        // Settings-page actions.
        AcfFieldsCache::reset();
        $feedback = DBVC_Visual_Editor_Addon::run_settings_action('bricks-acf-fields-cache-rebuild');
        $this->assertCount(1, $feedback['success']);
        $this->assertSame('settings_rebuild', get_option(AcfFieldsCache::OPTION_META)['flushed_by']);
        $feedback = DBVC_Visual_Editor_Addon::run_settings_action('bricks-acf-fields-cache-verify');
        $this->assertCount(1, $feedback['success']);
        $this->assertNotFalse(wp_next_scheduled(AcfFieldsCache::CRON_VERIFY));
        $feedback = DBVC_Visual_Editor_Addon::run_settings_action('nope');
        $this->assertCount(1, $feedback['error']);

        $status = AcfFieldsCache::status();
        $this->assertSame('bricks_not_loaded', $status['gate']);
        $this->assertFalse($status['transient']);
    }
}
