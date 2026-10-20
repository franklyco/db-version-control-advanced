<?php

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Preparation\DependencyLedger;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * M9 slice 6a — additional Bricks domains under the `wp.bricks:` namespace
 * (color palettes, pseudo-classes, class/variable categories). They are opt-in
 * per connector (default none), ride the existing collection observer + apply
 * path, and carry no cross-object id reference (components land in 6b).
 *
 * @group connected-environments
 */
class ConnectedBricksDomainsTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_bricks_available', '__return_true');
        delete_option(DomainRegistry::OPTION_BRICKS_DOMAINS);
        delete_option('bricks_color_palette');
        DomainRegistry::reset_observers();
    }

    public function tear_down(): void
    {
        delete_option(DomainRegistry::OPTION_BRICKS_DOMAINS);
        delete_option('bricks_color_palette');
        DomainRegistry::reset_observers();
        parent::tear_down();
    }

    public function test_isdomain_accepts_the_wp_bricks_pattern(): void
    {
        $this->assertTrue(ObservationEvent::isDomain('wp.bricks:color_palette'));
        $this->assertTrue(ObservationEvent::isDomain('wp.bricks:pseudo_classes'));
        // The legacy dotted domains still validate.
        $this->assertTrue(ObservationEvent::isDomain('bricks.global_class'));
        // Uppercase, spaces and the dotted spelling of a new domain are rejected.
        $this->assertFalse(ObservationEvent::isDomain('wp.bricks:Color_Palette'));
        $this->assertFalse(ObservationEvent::isDomain('wp.bricks:color palette'));
        $this->assertFalse(ObservationEvent::isDomain('bricks.color_palette'));
    }

    public function test_bricks_domains_are_opt_in(): void
    {
        // Default: none of the new domains are registered.
        $this->assertArrayNotHasKey(
            DomainRegistry::DOMAIN_BRICKS_COLOR_PALETTE,
            DomainRegistry::definitions()
        );

        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, [ 'color_palette', 'class_categories', 'bogus' ]);
        DomainRegistry::reset_observers();

        $definitions = DomainRegistry::definitions();
        $this->assertArrayHasKey(DomainRegistry::DOMAIN_BRICKS_COLOR_PALETTE, $definitions);
        $this->assertArrayHasKey(DomainRegistry::DOMAIN_BRICKS_CLASS_CATEGORIES, $definitions);
        // An unknown slug is ignored, and un-opted domains stay out.
        $this->assertArrayNotHasKey(DomainRegistry::DOMAIN_BRICKS_PSEUDO_CLASSES, $definitions);
        $this->assertNotContains('bogus', array_keys($definitions));

        $observer = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_COLOR_PALETTE);
        $this->assertInstanceOf(BricksOptionCollectionObserver::class, $observer);
        $this->assertSame('bricks_color_palette', $observer->option_name());
        $this->assertContains('bricks_color_palette', DomainRegistry::watched_options());
    }

    public function test_observer_snapshots_a_seeded_palette_and_order(): void
    {
        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, [ 'color_palette' ]);
        DomainRegistry::reset_observers();
        update_option('bricks_color_palette', [
            [ 'id' => 'pal-brand', 'name' => 'Brand', 'colors' => [ [ 'id' => 'c1', 'raw' => 'var(--primary)' ] ] ],
            [ 'id' => 'pal-grey', 'name' => 'Greys', 'colors' => [] ],
        ]);

        $observer = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_COLOR_PALETTE);
        $hit = $observer->snapshot([ 'storage_key' => 'pal-brand' ]);
        $this->assertSame('available', $hit['status']);
        $this->assertTrue($hit['exists']);
        $this->assertTrue($hit['complete']);
        $this->assertNotSame('', $hit['semantic_hash']);

        // A key that is not present snapshots as missing (absent), not an error.
        $miss = $observer->snapshot([ 'storage_key' => 'pal-none' ]);
        $this->assertSame('missing', $miss['status']);
        $this->assertSame($observer->absent_hash(), $miss['semantic_hash']);

        // Name resolves to its unique storage key (idempotent create-by-name).
        $this->assertSame('pal-grey', $observer->find_storage_key_by_name('Greys'));

        // The inventory lists both members and an order projection.
        $inventory = $observer->inventory([]);
        $this->assertSame(2, $inventory['total']);
    }

    public function test_dependency_ledger_is_non_blocking_for_self_contained_collections(): void
    {
        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, [ 'color_palette' ]);
        DomainRegistry::reset_observers();
        $observer = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_COLOR_PALETTE);

        $after = [ 'id' => 'pal-brand', 'name' => 'Brand', 'colors' => [ [ 'id' => 'c1', 'raw' => 'var(--primary)' ] ] ];
        $ledger = DependencyLedger::build(
            DomainRegistry::DOMAIN_BRICKS_COLOR_PALETTE,
            $after,
            [],
            $observer
        );
        $this->assertTrue($ledger['complete'], 'Discovery completes (not the unsupported default).');
        $this->assertSame([], $ledger['blocking'], 'A self-contained palette blocks on nothing.');
    }
}
