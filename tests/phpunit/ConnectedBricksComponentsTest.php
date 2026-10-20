<?php

use Dbvc\Connected\Adapters\BricksComponentObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Preparation\DependencyLedger;
use Dbvc\Connected\Preparation\ReferenceTokens;

/**
 * M9 slice 6c-1 — the Bricks components domain, OBSERVE side. A component is a
 * collection member keyed by its own id, but its element tree references global
 * classes (and nested components) by site-local id. The observer rewrites those
 * to portable NAME tokens before hashing, so the projection hash is identical
 * across the fleet; apply (detokenize + remap) lands in 6c-2.
 *
 * @group connected-environments
 */
class ConnectedBricksComponentsTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_bricks_available', '__return_true');
        delete_option(DomainRegistry::OPTION_BRICKS_DOMAINS);
        delete_option('bricks_components');
        delete_option('bricks_global_classes');
        DomainRegistry::reset_observers();
    }

    public function tear_down(): void
    {
        delete_option(DomainRegistry::OPTION_BRICKS_DOMAINS);
        delete_option('bricks_components');
        delete_option('bricks_global_classes');
        DomainRegistry::reset_observers();
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        parent::tear_down();
    }

    /**
     * One component: root element labelled $label, referencing the given class ids.
     *
     * @param string            $id
     * @param string            $label
     * @param array<int,string> $class_ids
     * @return array<string,mixed>
     */
    private function component(string $id, string $label, array $class_ids): array
    {
        return [
            'id' => $id,
            'category' => 'Cards',
            'elements' => [
                ['id' => $id, 'name' => 'block', 'label' => $label, 'settings' => ['_cssGlobalClasses' => $class_ids]],
            ],
        ];
    }

    public function test_reference_tokens_round_trip(): void
    {
        $component = [
            'id' => 'cmp001',
            'elements' => [
                ['id' => 'cmp001', 'label' => 'card', 'settings' => [
                    '_cssGlobalClasses' => ['gilukg', 'unknownx'],
                    '_cssGlobalClassesProps' => ['gilukg' => ['x' => 1]],
                    'cid' => 'nested1',
                ]],
            ],
        ];
        $tok = ReferenceTokens::tokenize($component, ['gilukg' => 'team-card'], ['nested1' => 'Hero Block']);
        $settings = $tok['elements'][0]['settings'];
        // Known class id → name token; unknown id stays literal.
        $this->assertSame([ReferenceTokens::token('class', 'team-card'), 'unknownx'], $settings['_cssGlobalClasses']);
        // Props map re-keyed by the token; nested component ref tokenized (name has a space → rawurlencoded).
        $this->assertArrayHasKey(ReferenceTokens::token('class', 'team-card'), $settings['_cssGlobalClassesProps']);
        $this->assertSame(ReferenceTokens::token('component', 'Hero Block'), $settings['cid']);

        // tokens_in_body lists the resolved references (the literal unknown id is not a token).
        $names = array_column(ReferenceTokens::tokens_in_body($tok), 'name', 'kind');
        $this->assertSame('team-card', $names['class']);
        $this->assertSame('Hero Block', $names['component']);

        // Detokenize with the TARGET's (different) local ids resolves back by name; an absent name stays a token.
        $back = ReferenceTokens::detokenize($tok, ['team-card' => 'zzaa99'], ['Hero Block' => 'tgtcmp']);
        $this->assertSame(['zzaa99', 'unknownx'], $back['elements'][0]['settings']['_cssGlobalClasses']);
        $this->assertSame('tgtcmp', $back['elements'][0]['settings']['cid']);
    }

    public function test_component_domain_is_opt_in_and_uses_the_component_observer(): void
    {
        $this->assertArrayNotHasKey(DomainRegistry::DOMAIN_BRICKS_COMPONENTS, DomainRegistry::definitions());

        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, ['components']);
        DomainRegistry::reset_observers();

        $this->assertArrayHasKey(DomainRegistry::DOMAIN_BRICKS_COMPONENTS, DomainRegistry::definitions());
        $this->assertFalse(DomainRegistry::is_singleton_domain(DomainRegistry::DOMAIN_BRICKS_COMPONENTS));
        $observer = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_COMPONENTS);
        $this->assertInstanceOf(BricksComponentObserver::class, $observer);
        $this->assertSame('bricks_components', $observer->option_name());
        $this->assertContains('bricks_components', DomainRegistry::watched_options());
    }

    public function test_component_projection_is_identity_independent_across_sites(): void
    {
        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, ['components']);

        // Site A: class "team-card" has local id gilukg; the component references it.
        update_option('bricks_global_classes', [['id' => 'gilukg', 'name' => 'team-card']]);
        update_option('bricks_components', [$this->component('cmp001', 'team-card-person', ['gilukg'])]);
        DomainRegistry::reset_observers();
        $a = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_COMPONENTS)->snapshot(['storage_key' => 'cmp001']);
        $this->assertSame('available', $a['status']);
        $this->assertSame('team-card-person', $a['display_name'], 'The component is named by its root element label.');

        // Site B: the SAME component + class NAME, but a different local class id.
        update_option('bricks_global_classes', [['id' => 'ZZZZZZ', 'name' => 'team-card']]);
        update_option('bricks_components', [$this->component('cmp001', 'team-card-person', ['ZZZZZZ'])]);
        DomainRegistry::reset_observers();
        $b = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_COMPONENTS)->snapshot(['storage_key' => 'cmp001']);

        $this->assertSame($a['semantic_hash'], $b['semantic_hash'], 'Tokenized class references make the component hash the same despite a different local class id.');

        // An unresolvable class id (no matching name) keeps the raw id, so the hash diverges — it cannot converge and must block at prepare.
        update_option('bricks_global_classes', []);
        update_option('bricks_components', [$this->component('cmp001', 'team-card-person', ['ZZZZZZ'])]);
        DomainRegistry::reset_observers();
        $c = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_COMPONENTS)->snapshot(['storage_key' => 'cmp001']);
        $this->assertNotSame($a['semantic_hash'], $c['semantic_hash']);
    }

    public function test_dependency_ledger_resolves_component_class_references(): void
    {
        update_option(DomainRegistry::OPTION_BRICKS_DOMAINS, ['components']);
        update_option('bricks_global_classes', [['id' => 'local1', 'name' => 'team-card']]);
        DomainRegistry::reset_observers();
        $observer = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_COMPONENTS);

        // A release body is already tokenized (the projection form).
        $after = ['id' => 'cmp001', 'elements' => [['id' => 'cmp001', 'label' => 'card', 'settings' => [
            '_cssGlobalClasses' => [ReferenceTokens::token('class', 'team-card'), ReferenceTokens::token('class', 'missing-class')],
        ]]]];

        // "missing-class" is present on neither the target nor the release → blocks.
        $ledger = DependencyLedger::build(DomainRegistry::DOMAIN_BRICKS_COMPONENTS, $after, [], $observer);
        $this->assertTrue($ledger['complete']);
        $this->assertContains('dependency_unresolved:bricks.global_class:missing-class', $ledger['blocking']);
        $this->assertNotContains('dependency_unresolved:bricks.global_class:team-card', $ledger['blocking'], 'A class present on the target does not block.');

        // Supplying the missing class in the same release resolves it (selected).
        $release = [['domain' => DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, 'instance_uid' => 'uid-mc', 'body' => wp_json_encode(['id' => 'x', 'name' => 'missing-class'])]];
        $ledger2 = DependencyLedger::build(DomainRegistry::DOMAIN_BRICKS_COMPONENTS, $after, $release, $observer);
        $this->assertSame([], $ledger2['blocking'], 'Both references resolve (one present, one supplied by the release).');
    }
}
