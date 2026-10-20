<?php

use Dbvc\Connected\Adapters\BricksOptionSingletonObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Preparation\DependencyLedger;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * M9 slice 6b — whole-blob Bricks singleton domains (`wp.bricks:breakpoints`,
 * `wp.bricks:theme_styles`). One logical object per option, a deterministic
 * site-independent identity, no keyed members and no cross-object reference
 * remap. Opt-in per connector (default none), riding a singleton observer +
 * singleton apply branch. `wp.bricks:global_settings` is deliberately NOT a
 * singleton domain (it mixes site-operational config with design).
 *
 * @group connected-environments
 */
class ConnectedBricksSingletonTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_bricks_available', '__return_true');
        delete_option(DomainRegistry::OPTION_BRICKS_DOMAINS);
        delete_option('bricks_theme_styles');
        delete_option('bricks_breakpoints');
        DomainRegistry::reset_observers();
    }

    public function tear_down(): void
    {
        delete_option(DomainRegistry::OPTION_BRICKS_DOMAINS);
        delete_option('bricks_theme_styles');
        delete_option('bricks_breakpoints');
        DomainRegistry::reset_observers();
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        parent::tear_down();
    }

    public function test_isdomain_accepts_the_singleton_slugs(): void
    {
        $this->assertTrue(ObservationEvent::isDomain('wp.bricks:breakpoints'));
        $this->assertTrue(ObservationEvent::isDomain('wp.bricks:theme_styles'));
        // The namespace is still case-sensitive and dotted spellings are rejected.
        $this->assertFalse(ObservationEvent::isDomain('wp.bricks:Theme_Styles'));
        $this->assertFalse(ObservationEvent::isDomain('bricks.theme_styles'));
    }

    public function test_singleton_domains_are_opt_in_and_use_the_singleton_observer(): void
    {
        // Default: neither singleton domain is registered.
        $this->assertArrayNotHasKey(DomainRegistry::DOMAIN_BRICKS_THEME_STYLES, DomainRegistry::definitions());
        $this->assertFalse(DomainRegistry::is_singleton_domain(DomainRegistry::DOMAIN_BRICKS_THEME_STYLES));

        // Opt into theme_styles (plus an unknown slug and global_settings, which is NOT in the catalog).
        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, ['theme_styles', 'global_settings', 'bogus']);
        DomainRegistry::reset_observers();

        $definitions = DomainRegistry::definitions();
        $this->assertArrayHasKey(DomainRegistry::DOMAIN_BRICKS_THEME_STYLES, $definitions);
        // global_settings is excluded by design (mixed operational/design → needs field policy, not whole-blob).
        $this->assertArrayNotHasKey('wp.bricks:global_settings', $definitions);
        $this->assertNotContains('bogus', array_keys($definitions));

        $this->assertTrue(DomainRegistry::is_singleton_domain(DomainRegistry::DOMAIN_BRICKS_THEME_STYLES));
        $observer = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_THEME_STYLES);
        $this->assertInstanceOf(BricksOptionSingletonObserver::class, $observer);
        $this->assertSame('bricks_theme_styles', $observer->option_name());
        $this->assertContains('bricks_theme_styles', DomainRegistry::watched_options());
    }

    public function test_observer_snapshots_the_whole_option_under_a_deterministic_identity(): void
    {
        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, ['theme_styles']);
        DomainRegistry::reset_observers();
        $observer = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_THEME_STYLES);

        // Absent option: the single object is verified-missing (absent hash), and a
        // complete inventory reports no members so a removal tombstones cleanly.
        $missing = $observer->snapshot(['storage_key' => BricksOptionSingletonObserver::SINGLETON_KEY]);
        $this->assertSame('missing', $missing['status']);
        $this->assertFalse($missing['exists']);
        $empty = $observer->inventory([]);
        $this->assertSame('available', $empty['status']);
        $this->assertSame([], $empty['items']);
        $this->assertTrue($empty['complete']);
        $this->assertNull($empty['order_hash'], 'A singleton never emits a collection-order projection.');

        // Present option: one member under the fixed singleton identity, hashed whole.
        update_option('bricks_theme_styles', [
            'global' => ['settings' => ['typography' => ['typographyBody' => ['color' => ['raw' => 'var(--primary)', 'id' => 'aoasik']]]]],
            'global_old' => ['settings' => []],
        ]);
        $hit = $observer->snapshot(['storage_key' => BricksOptionSingletonObserver::SINGLETON_KEY]);
        $this->assertSame('available', $hit['status']);
        $this->assertTrue($hit['exists']);
        $this->assertTrue($hit['complete']);
        $this->assertNotSame('', (string) $hit['semantic_hash']);
        $this->assertSame(BricksOptionSingletonObserver::SINGLETON_KEY, $hit['instance_uid']);

        $inventory = $observer->inventory([]);
        $this->assertCount(1, $inventory['items']);
        $this->assertSame(BricksOptionSingletonObserver::SINGLETON_KEY, $inventory['items'][0]['storage_key']);
        $this->assertSame(BricksOptionSingletonObserver::SINGLETON_KEY, $inventory['items'][0]['instance_uid']);
        $this->assertSame($hit['semantic_hash'], $inventory['items'][0]['hash'], 'Snapshot and inventory agree on the whole-blob hash.');
        $this->assertNull($inventory['order_hash']);

        // Only the singleton sentinel is a valid storage key.
        $this->assertSame('unsupported', $observer->snapshot(['storage_key' => 'something-else'])['status']);
    }

    public function test_dependency_ledger_is_non_blocking_for_singletons(): void
    {
        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, ['theme_styles', 'breakpoints']);
        DomainRegistry::reset_observers();

        foreach ([DomainRegistry::DOMAIN_BRICKS_THEME_STYLES, DomainRegistry::DOMAIN_BRICKS_BREAKPOINTS] as $domain) {
            $observer = DomainRegistry::observer_for($domain);
            $ledger = DependencyLedger::build($domain, ['global' => ['settings' => []]], [], $observer);
            $this->assertTrue($ledger['complete'], $domain . ' discovery completes (not the unsupported default).');
            $this->assertSame([], $ledger['blocking'], $domain . ' blocks on nothing.');
        }
    }
}
