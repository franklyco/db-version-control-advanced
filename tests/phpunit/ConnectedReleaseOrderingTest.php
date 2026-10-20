<?php

use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * M9 Part 6 follow-up — hub-side topological ordering of release items. A
 * referenced object (a category a class uses, a class a component uses, a class/
 * component a template uses) must seal, deliver and apply before the object that
 * references it. ReleaseStore::items() returns items in dependency order; because
 * seal() hashes the manifest in that order and delivery/apply read through items(),
 * the digest and the apply order stay consistent.
 *
 * @group connected-environments
 */
class ConnectedReleaseOrderingTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
    }

    public function tear_down(): void
    {
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    /**
     * @param array<int, array<string, string>> $domains_and_uids
     */
    private function seed_release(array $domains_and_uids): array
    {
        $items = array_map(static function ($du) {
            return [
                'domain' => $du[0],
                'instance_uid' => $du[1],
                'profile' => 'p-v1',
                'after_hash' => str_repeat('a', 64),
            ];
        }, $domains_and_uids);
        $release = [
            'release_uid' => 'rel-order-' . substr(md5(wp_json_encode($domains_and_uids)), 0, 8),
            'agency_id' => 'ag-1', 'client_id' => 'cl-1',
            'source_environment_id' => 'src-1', 'source_epoch' => 'epoch-1',
        ];
        $created = (new ReleaseStore())->create($release, $items);
        $this->assertIsArray($created, 'release created');

        return $created;
    }

    public function test_items_are_returned_in_topological_dependency_order(): void
    {
        // Inserted in a deliberately dependency-reversed, scrambled order.
        $created = $this->seed_release([
            ['wp.post:bricks_template', 't1'],
            ['wp.bricks:components', 'c1'],
            ['bricks.global_class', 'cl2'],
            ['bricks.global_class', 'cl1'],
            ['bricks.variable', 'v1'],
            ['wp.bricks:variable_categories', 'vc1'],
            ['wp.service', 's1'],
            ['wp.bricks:theme_styles', 'singleton'],
        ]);
        $items = (new ReleaseStore())->items($created['release_id']);
        $domains = array_map(static function ($i) {
            return $i['domain'];
        }, $items);

        $this->assertSame([
            'wp.bricks:variable_categories', // 10
            'bricks.variable',               // 20
            'bricks.global_class',           // 40
            'bricks.global_class',           // 40 (second class)
            'wp.bricks:theme_styles',        // 50
            'wp.bricks:components',          // 60
            'wp.post:bricks_template',       // 70
            'wp.service',                    // 80
        ], $domains, 'Items ordered so each dependency precedes its referrer.');

        // Within the class rank, the lexical instance_uid order is preserved (stable sort).
        $class_uids = array_values(array_map(static function ($i) {
            return $i['instance_uid'];
        }, array_filter($items, static function ($i) {
            return $i['domain'] === 'bricks.global_class';
        })));
        $this->assertSame(['cl1', 'cl2'], $class_uids);
    }

    public function test_domain_ranks_are_filterable(): void
    {
        // Promote classes ahead of everything (e.g. a custom pipeline).
        $filter = static function ($ranks) {
            $ranks['bricks.global_class'] = 1;
            return $ranks;
        };
        add_filter('dbvc_agency_release_domain_ranks', $filter);
        $created = $this->seed_release([
            ['wp.bricks:variable_categories', 'vc1'],
            ['bricks.global_class', 'cl1'],
        ]);
        $domains = array_map(static function ($i) {
            return $i['domain'];
        }, (new ReleaseStore())->items($created['release_id']));
        remove_filter('dbvc_agency_release_domain_ranks', $filter);

        $this->assertSame(['bricks.global_class', 'wp.bricks:variable_categories'], $domains, 'The filter reorders ranks.');
    }
}
