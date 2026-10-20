<?php

use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Adapters\PostTypeObserver;
use Dbvc\Connected\Adapters\ServicePostObserver;

/**
 * M8 slice 2 — connector config surface. The per-CPT observe/apply allow-lists
 * and per-type meta/term policy are stored, sanitized and round-tripped through
 * the add-on settings, feed the DomainRegistry definitions, gate a generic post
 * type's apply-eligibility, and shape the projection — with `wp.service` always
 * apply-eligible and the operator's config never wiped by a programmatic enable.
 *
 * @group connected-environments
 */
class ConnectedPostTypeConfigTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_should_export_on_option_update', '__return_false');
        add_filter('pre_http_request', [$this, 'trap_http'], 10, 3);
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        register_post_type('portfolio', ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        register_post_type('report', ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        update_option('dbvc_post_types', ['service', 'portfolio', 'report']);
        delete_option(DomainRegistry::OPTION_POST_DOMAINS);
        delete_option(DomainRegistry::OPTION_POST_APPLY_DOMAINS);
        delete_option(DomainRegistry::OPTION_POST_TYPE_META);
        delete_option('dbvc_mask_meta_keys');
        DomainRegistry::reset_observers();
    }

    public function tear_down(): void
    {
        unregister_post_type('service');
        unregister_post_type('portfolio');
        unregister_post_type('report');
        delete_option('dbvc_post_types');
        delete_option(DomainRegistry::OPTION_POST_DOMAINS);
        delete_option(DomainRegistry::OPTION_POST_APPLY_DOMAINS);
        delete_option(DomainRegistry::OPTION_POST_TYPE_META);
        if (get_option(DBVC_Connected_Environments_Addon::OPTION_ENABLED) === '1') {
            DBVC_Connected_Environments_Addon::save_settings([]);
        }
        remove_filter('dbvc_should_export_on_option_update', '__return_false');
        remove_filter('pre_http_request', [$this, 'trap_http'], 10);
        DomainRegistry::reset_observers();
        parent::tear_down();
    }

    public function trap_http($preempt, $args, $url)
    {
        unset($preempt, $args);
        $this->fail('Unexpected HTTP request: ' . (string) $url);
    }

    public function test_save_settings_persists_and_round_trips_coverage_config(): void
    {
        $result = DBVC_Connected_Environments_Addon::save_settings([
            DBVC_Connected_Environments_Addon::OPTION_ENABLED => '0',
            DomainRegistry::OPTION_POST_DOMAINS => "portfolio\nreport, portfolio",
            DomainRegistry::OPTION_POST_APPLY_DOMAINS => "portfolio",
            DomainRegistry::OPTION_POST_TYPE_META => '{"portfolio": {"allow": ["client"], "deny": ["_internal"], "create_terms": true}}',
        ]);
        $this->assertSame([], $result['errors']);

        // Sanitized + deduped slug arrays.
        $this->assertSame(['portfolio', 'report'], get_option(DomainRegistry::OPTION_POST_DOMAINS));
        $this->assertSame(['portfolio'], get_option(DomainRegistry::OPTION_POST_APPLY_DOMAINS));
        $this->assertSame(['portfolio' => ['allow' => ['client'], 'deny' => ['_internal'], 'create_terms' => true]], get_option(DomainRegistry::OPTION_POST_TYPE_META));

        // The resolved coverage reflects the config.
        $this->assertSame(['portfolio', 'report'], DomainRegistry::opted_in_post_types());
        $this->assertSame(['portfolio'], DomainRegistry::apply_post_types());

        // get_all_settings round-trips into textarea-friendly display strings.
        $settings = DBVC_Connected_Environments_Addon::get_all_settings();
        $this->assertSame("portfolio\nreport", $settings[DomainRegistry::OPTION_POST_DOMAINS]);
        $this->assertSame('portfolio', $settings[DomainRegistry::OPTION_POST_APPLY_DOMAINS]);
        $this->assertStringContainsString('"client"', $settings[DomainRegistry::OPTION_POST_TYPE_META]);
        $this->assertSame(get_option(DomainRegistry::OPTION_POST_TYPE_META), json_decode($settings[DomainRegistry::OPTION_POST_TYPE_META], true));
    }

    public function test_invalid_meta_json_is_rejected_and_the_previous_value_is_kept(): void
    {
        update_option(DomainRegistry::OPTION_POST_TYPE_META, ['report' => ['deny' => ['secret']]]);
        $result = DBVC_Connected_Environments_Addon::save_settings([
            DBVC_Connected_Environments_Addon::OPTION_ENABLED => '0',
            DomainRegistry::OPTION_POST_TYPE_META => 'not json {',
        ]);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('valid JSON', $result['errors'][0]);
        $this->assertSame(['report' => ['deny' => ['secret']]], get_option(DomainRegistry::OPTION_POST_TYPE_META), 'A bad JSON edit never clobbers the stored policy.');
    }

    public function test_coverage_config_is_not_wiped_by_a_programmatic_enable_without_those_keys(): void
    {
        update_option(DomainRegistry::OPTION_POST_DOMAINS, ['portfolio']);
        update_option(DomainRegistry::OPTION_POST_APPLY_DOMAINS, ['portfolio']);
        update_option(DomainRegistry::OPTION_POST_TYPE_META, ['portfolio' => ['create_terms' => true]]);

        // The enrollment/CLI/test path enables with just the gate key.
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);

        $this->assertSame(['portfolio'], get_option(DomainRegistry::OPTION_POST_DOMAINS));
        $this->assertSame(['portfolio'], get_option(DomainRegistry::OPTION_POST_APPLY_DOMAINS));
        $this->assertSame(['portfolio' => ['create_terms' => true]], get_option(DomainRegistry::OPTION_POST_TYPE_META));
    }

    public function test_apply_allow_list_gates_capabilities_and_stays_a_subset_of_observe(): void
    {
        update_option(DomainRegistry::OPTION_POST_DOMAINS, ['portfolio', 'report']);
        // `audio` is not observed, so it can never be apply-eligible.
        update_option(DomainRegistry::OPTION_POST_APPLY_DOMAINS, ['portfolio', 'audio']);
        DomainRegistry::reset_observers();

        $this->assertSame(['portfolio'], DomainRegistry::apply_post_types(), 'Apply is intersected with the observe set.');

        $defs = DomainRegistry::definitions();
        $this->assertTrue($defs['wp.post:portfolio']['apply']);
        $this->assertFalse($defs['wp.post:report']['apply']);

        $portfolio = DomainRegistry::observer_for('wp.post:portfolio');
        $report = DomainRegistry::observer_for('wp.post:report');
        $this->assertInstanceOf(PostTypeObserver::class, $portfolio);
        $this->assertTrue($portfolio->capabilities()['report']);
        $this->assertTrue($portfolio->capabilities()['apply'], 'Observed + apply-listed + DBVC post type.');
        $this->assertTrue($report->capabilities()['report'], 'report is still observed.');
        $this->assertFalse($report->capabilities()['apply'], 'Observed but not apply-listed: apply is refused.');

        // The wp.service alias is always apply-eligible (back-compat with the pre-M8 gate).
        $service = DomainRegistry::observer_for('wp.service');
        $this->assertInstanceOf(ServicePostObserver::class, $service);
        $this->assertTrue($service->capabilities()['apply']);
    }

    public function test_per_type_meta_policy_from_the_option_shapes_the_projection(): void
    {
        update_option(DomainRegistry::OPTION_POST_DOMAINS, ['portfolio']);
        update_option(DomainRegistry::OPTION_POST_TYPE_META, ['portfolio' => ['allow' => ['keep'], 'create_terms' => true]]);
        DomainRegistry::reset_observers();

        $post_id = wp_insert_post(['post_type' => 'portfolio', 'post_status' => 'publish', 'post_title' => 'Policy']);
        update_post_meta($post_id, 'keep', 'yes');
        update_post_meta($post_id, 'drop', 'no');

        $observer = DomainRegistry::observer_for('wp.post:portfolio');
        $this->assertTrue($observer->create_terms(), 'create_terms comes from the stored per-type policy.');
        $allowed = json_decode($observer->snapshot(['storage_key' => (string) $post_id])['canonical'], true);
        $this->assertSame(['keep'], array_keys($allowed['meta']), 'The allow-list from the option narrows the projected meta.');

        // Switch to a deny policy (no create_terms): keep is projected, drop is excluded, create_terms defaults off.
        update_option(DomainRegistry::OPTION_POST_TYPE_META, ['portfolio' => ['deny' => ['drop']]]);
        DomainRegistry::reset_observers();
        $denied_observer = DomainRegistry::observer_for('wp.post:portfolio');
        $this->assertFalse($denied_observer->create_terms());
        $denied = json_decode($denied_observer->snapshot(['storage_key' => (string) $post_id])['canonical'], true);
        $this->assertArrayHasKey('keep', $denied['meta']);
        $this->assertArrayNotHasKey('drop', $denied['meta'], 'A per-type denied key never projects.');
    }
}
