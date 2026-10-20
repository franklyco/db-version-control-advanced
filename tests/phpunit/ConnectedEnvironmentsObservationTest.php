<?php

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Identity\InstanceIdentity;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * Local observation workflow: save-side signals, coalescing, generation-aware
 * background processing, durable snapshots and outbox, tombstones, ordering,
 * lease fencing and read-only inspection. Runs against real WordPress option
 * storage and the connector tables; no Bricks theme, Visual Editor, automatic
 * export or network is involved.
 *
 * @group connected-environments
 */
class ConnectedEnvironmentsObservationTest extends WP_UnitTestCase
{
    private const CLASSES = 'bricks_global_classes';
    private const VARIABLES = 'bricks_global_variables';
    private const BASELINE_EVENTS = 3;

    /**
     * @var int
     */
    private $http_requests = 0;

    /**
     * @var int
     */
    private $export_hook_fired = 0;

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_connected_bricks_available', '__return_true');
        add_filter('dbvc_connected_processing_delay', '__return_zero');
        add_filter('dbvc_should_export_on_option_update', '__return_false');
        add_filter('pre_http_request', [$this, 'trap_http'], 10, 3);
        add_action('dbvc_after_option_update', [$this, 'count_export_hook']);
        $this->http_requests = 0;
        $this->export_hook_fired = 0;

        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        delete_option(self::CLASSES);
        delete_option(self::VARIABLES);
        delete_option('dbvc_addon_visual_editor_enabled');
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $this->assertSame('ready', DBVC_Connected_Environments_Addon::get_gate_state());

        // Enabling requests a reconciliation of every supported domain; settle it so tests start from a verified empty state.
        $this->assertSame(3, (new JobStore())->counts()['total']);
        $settled = $this->run_worker();
        $this->assertSame(3, $settled['acknowledged'], wp_json_encode($settled['jobs']));
        $this->assertSame(self::BASELINE_EVENTS, $settled['events'], 'One empty collection-order projection per Bricks domain plus the empty (complete) service inventory.');
        foreach ((new OutboxStore())->all() as $event) {
            $this->assertSame('reconciliation', $event['body']['origin']);
            $this->assertSame('enable', $event['body']['causation_id']);
        }
    }

    public function tear_down(): void
    {
        // Registrations are process-global (not rolled back with the DB); leave no extra sources for later suites.
        unregister_post_type('service');
        DBVC_Connected_Environments_Addon::save_settings([]);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        remove_filter('dbvc_connected_bricks_available', '__return_false');
        remove_filter('dbvc_connected_processing_delay', '__return_zero');
        remove_filter('dbvc_should_export_on_option_update', '__return_false');
        remove_filter('pre_http_request', [$this, 'trap_http'], 10);
        remove_action('dbvc_after_option_update', [$this, 'count_export_hook']);
        delete_option(self::CLASSES);
        delete_option(self::VARIABLES);
        Schema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function trap_http($preempt, $args, $url)
    {
        unset($args);
        $this->http_requests++;
        $this->fail('Unexpected HTTP request during connector activity: ' . (string) $url);
    }

    public function count_export_hook(): void
    {
        $this->export_hook_fired++;
    }

    public function test_save_records_one_dirty_marker_without_export_hooks_or_network(): void
    {
        $classes = $this->classes_fixture();
        $before = maybe_serialize($classes);
        update_option(self::CLASSES, $classes);

        $job = (new JobStore())->find(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, DomainRegistry::COLLECTION_KEY);
        $this->assertIsArray($job);
        $this->assertSame(1, (int) $job['generation']);
        $this->assertNull($job['lease_token']);
        $context = json_decode((string) $job['last_signal_context'], true);
        $this->assertSame(['origin' => 'human', 'source_hook' => 'added_option'], $context, 'The first save creates the option; creation is captured too.');
        $this->assertNotFalse(wp_next_scheduled(DirtyCapture::CRON_HOOK));
        $this->assertSame(0, $this->export_hook_fired, 'Capture must not depend on the export-gated dbvc_after_option_update hook.');
        $this->assertSame(0, $this->http_requests);
        $this->assertSame($before, maybe_serialize(get_option(self::CLASSES)));
        $this->assertSame([], $this->events_since_baseline(), 'Saving alone must not produce events; only the worker does.');
        $this->assertSame([], array_filter((new ObjectStore())->all(), static function ($row) {
            return $row['storage_key'] !== '';
        }));
        $this->assertSame(0, (new InstanceIdentity())->count(), 'Save-side capture must never assign identity.');

        $classes[0]['settings']['_display'] = 'flex';
        update_option(self::CLASSES, $classes);
        $job = (new JobStore())->find(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, DomainRegistry::COLLECTION_KEY);
        $this->assertSame(2, (int) $job['generation']);
        $this->assertSame('updated_option', json_decode((string) $job['last_signal_context'], true)['source_hook']);
    }

    public function test_repeated_saves_coalesce_into_one_marker_with_incremented_generation(): void
    {
        $classes = $this->classes_fixture();
        update_option(self::CLASSES, $classes);
        $classes[0]['settings']['_display'] = 'flex';
        update_option(self::CLASSES, $classes);
        $classes[1]['name'] = '--renamed';
        update_option(self::CLASSES, $classes);
        update_option(self::CLASSES, $classes); // Identical value: WordPress skips the write and no signal fires.

        $jobs = (new JobStore())->all();
        $this->assertCount(1, $jobs);
        $this->assertSame(3, (int) $jobs[0]['generation']);
        $this->assertSame(1, (new JobStore())->counts()['total']);
    }

    public function test_worker_observes_each_member_and_the_collection_order(): void
    {
        $classes = $this->classes_fixture();
        update_option(self::CLASSES, $classes);
        $before = maybe_serialize($classes);

        $summary = $this->run_worker();
        $this->assertNull($summary['blocked']);
        $this->assertSame(1, $summary['claimed']);
        $this->assertSame(1, $summary['acknowledged']);
        $this->assertSame(4, $summary['events'], 'Three members plus one collection-order projection.');
        $this->assertSame(3, $summary['identities_assigned']);
        $this->assertSame(0, (new JobStore())->counts()['total'], 'Acknowledged marker is cleared.');
        $this->assertSame($before, maybe_serialize(get_option(self::CLASSES)), 'The worker must not mutate source content.');
        $this->assertSame(0, $this->http_requests);

        $objects = (new ObjectStore())->all(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, 50);
        $this->assertCount(4, $objects);
        $by_key = [];
        foreach ($objects as $object) {
            $by_key[$object['storage_key'] !== '' ? $object['storage_key'] : $object['instance_uid']] = $object;
        }
        $keys = array_keys($by_key);
        sort($keys);
        $this->assertSame(['aaa111', 'bbb222', 'ccc333', BricksOptionCollectionObserver::ORDER_INSTANCE_UID], $keys);
        $this->assertSame('bricks-global-class-v1', $by_key['aaa111']['profile']);
        $this->assertSame(0, $by_key['aaa111']['position']);
        $this->assertSame(2, $by_key['ccc333']['position']);
        $this->assertSame(1, $by_key['aaa111']['object_exists']);
        $this->assertSame(1, $by_key['aaa111']['snapshot_complete']);
        $this->assertStringStartsWith('bricks-global-class-', $by_key['aaa111']['instance_uid']);
        $this->assertSame(Canonicalizer::encode($classes[0]), $by_key['aaa111']['snapshot_body']);
        $this->assertSame(Canonicalizer::hash(Canonicalizer::encode($classes[0])), $by_key['aaa111']['semantic_hash']);
        $this->assertSame(hash('sha256', $before), $by_key['aaa111']['storage_fingerprint']);

        $order = $by_key[BricksOptionCollectionObserver::ORDER_INSTANCE_UID];
        $this->assertSame(BricksOptionCollectionObserver::ORDER_PROFILE, $order['profile']);
        $this->assertSame('["aaa111","bbb222","ccc333"]', $order['snapshot_body']);

        $events = $this->events_since_baseline();
        $this->assertCount(4, $events);
        $state = (new StateStore())->get();
        $this->assertSame(self::BASELINE_EVENTS + 5, $state['next_sequence']);
        $expected_sequence = self::BASELINE_EVENTS + 1;
        foreach ($events as $event) {
            $this->assertSame($expected_sequence, $event['source_sequence']);
            $this->assertSame('obs-' . str_pad((string) $expected_sequence, 12, '0', STR_PAD_LEFT), $event['event_id']);
            $this->assertSame($state['installation_epoch'], $event['installation_epoch']);
            $this->assertSame('pending', $event['delivery_state']);
            $this->assertSame([], ObservationEvent::validate($event['body']), 'Every stored body must satisfy the v0.2 contract.');
            $this->assertSame(ObservationEvent::digest($event['body']), $event['body_digest']);
            $this->assertSame($state['environment_id'], $event['body']['environment_id']);
            $this->assertSame('human', $event['body']['origin']);
            $this->assertArrayNotHasKey('recipients', $event['body']);
            $this->assertArrayNotHasKey('client_id', $event['body']);
            $expected_sequence++;
        }
        $this->assertSame(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, $events[0]['body']['object']['domain']);
        $this->assertSame($by_key['aaa111']['instance_uid'], $events[0]['body']['object']['instance_uid']);
        $this->assertTrue($events[0]['body']['projection']['exists']);
        $this->assertTrue($events[0]['body']['projection']['complete']);

        $identity = (new InstanceIdentity())->all_for_domain(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS);
        $this->assertSame(['aaa111', 'bbb222', 'ccc333'], array_keys($identity));
        $this->assertSame('--flex-col', $identity['aaa111']['display_name']);
    }

    public function test_reprocessing_without_changes_emits_no_duplicate_events(): void
    {
        update_option(self::CLASSES, $this->classes_fixture());
        $this->run_worker();
        $this->assertSame(self::BASELINE_EVENTS + 4, (new OutboxStore())->counts()['total']);

        // Simulate a lost/duplicate signal for an unchanged collection.
        (new JobStore())->mark_dirty(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, DomainRegistry::COLLECTION_KEY, [], 0);
        $summary = $this->run_worker();
        $this->assertSame(1, $summary['acknowledged']);
        $this->assertSame(0, $summary['events']);
        $this->assertSame(self::BASELINE_EVENTS + 4, (new OutboxStore())->counts()['total']);
        $this->assertSame(self::BASELINE_EVENTS + 5, (new StateStore())->get()['next_sequence'], 'No-op observations allocate no sequence.');
    }

    public function test_editing_one_member_emits_exactly_one_event_and_keeps_order(): void
    {
        $classes = $this->classes_fixture();
        update_option(self::CLASSES, $classes);
        $this->run_worker();

        $classes[1]['settings']['_direction'] = 'column';
        update_option(self::CLASSES, $classes);
        $summary = $this->run_worker();
        $this->assertSame(1, $summary['events']);
        $this->assertSame(0, $summary['tombstones']);

        $events = $this->events_since_baseline();
        $this->assertCount(5, $events);
        $latest = end($events);
        $this->assertSame(self::BASELINE_EVENTS + 5, $latest['source_sequence']);
        $this->assertSame('bricks-global-class-v1', $latest['body']['projection']['profile']);
        $this->assertSame(Canonicalizer::hash(Canonicalizer::encode($classes[1])), $latest['body']['projection']['hash']);
        $object = (new ObjectStore())->get(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, $latest['body']['object']['instance_uid'], 'bricks-global-class-v1');
        $this->assertSame('bbb222', $object['storage_key']);
        $this->assertSame(self::BASELINE_EVENTS + 5, $object['observed_sequence']);
    }

    public function test_reordering_members_emits_only_the_order_projection(): void
    {
        $classes = $this->classes_fixture();
        update_option(self::CLASSES, $classes);
        $this->run_worker();

        update_option(self::CLASSES, array_reverse($classes));
        $summary = $this->run_worker();
        $this->assertSame(1, $summary['events']);

        $events = $this->events_since_baseline();
        $latest = end($events);
        $this->assertSame(BricksOptionCollectionObserver::ORDER_PROFILE, $latest['body']['projection']['profile']);
        $this->assertSame(BricksOptionCollectionObserver::ORDER_INSTANCE_UID, $latest['body']['object']['instance_uid']);
        $this->assertSame(Canonicalizer::hash('["ccc333","bbb222","aaa111"]'), $latest['body']['projection']['hash']);

        $objects = (new ObjectStore())->all(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, 50);
        $positions = [];
        foreach ($objects as $object) {
            if ($object['storage_key'] !== '') {
                $positions[$object['storage_key']] = $object['observed_sequence'];
            }
        }
        $this->assertSame([self::BASELINE_EVENTS + 1, self::BASELINE_EVENTS + 2, self::BASELINE_EVENTS + 3], array_values($positions), 'Member projections are untouched by a pure reorder.');
    }

    public function test_removed_member_produces_tombstone_and_readding_restores_it(): void
    {
        $classes = $this->classes_fixture();
        update_option(self::CLASSES, $classes);
        $this->run_worker();
        $identity = (new InstanceIdentity())->all_for_domain(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS);
        $removed_uid = $identity['ccc333']['instance_uid'];

        update_option(self::CLASSES, [$classes[0], $classes[1]]);
        $summary = $this->run_worker();
        $this->assertSame(2, $summary['events'], 'One tombstone plus the changed collection order.');
        $this->assertSame(1, $summary['tombstones']);

        $tombstone = $this->events_since_baseline()[4];
        $this->assertSame($removed_uid, $tombstone['body']['object']['instance_uid']);
        $this->assertFalse($tombstone['body']['projection']['exists']);
        $this->assertTrue($tombstone['body']['projection']['complete']);
        $this->assertSame(BricksOptionCollectionObserver::absent_hash(), $tombstone['body']['projection']['hash']);
        $object = (new ObjectStore())->get(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, $removed_uid, 'bricks-global-class-v1');
        $this->assertSame(0, $object['object_exists']);
        $this->assertSame('ccc333', $object['storage_key']);

        update_option(self::CLASSES, $classes);
        $summary = $this->run_worker();
        $this->assertSame(2, $summary['events']);
        $restored = (new ObjectStore())->get(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, $removed_uid, 'bricks-global-class-v1');
        $this->assertSame(1, $restored['object_exists']);
        $this->assertSame($removed_uid, $restored['instance_uid'], 'Identity is preserved across removal and restoration.');
        $this->assertCount(3, (new InstanceIdentity())->all_for_domain(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS));
    }

    public function test_deleting_the_option_tombstones_every_member(): void
    {
        update_option(self::CLASSES, $this->classes_fixture());
        $this->run_worker();

        // Bricks deletes bricks_global_classes when the last class is removed.
        delete_option(self::CLASSES);
        $job = (new JobStore())->find(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, DomainRegistry::COLLECTION_KEY);
        $this->assertSame('deleted_option', json_decode((string) $job['last_signal_context'], true)['source_hook']);

        $summary = $this->run_worker();
        $this->assertSame(3, $summary['tombstones']);
        $this->assertSame(4, $summary['events']);
        $counts = (new ObjectStore())->counts_by_domain()[DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS];
        $this->assertSame(['total' => 4, 'present' => 1, 'absent' => 3, 'incomplete' => 0], $counts);
        $order = (new ObjectStore())->get(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, BricksOptionCollectionObserver::ORDER_INSTANCE_UID, BricksOptionCollectionObserver::ORDER_PROFILE);
        $this->assertSame('[]', $order['snapshot_body']);
    }

    public function test_newer_generation_during_processing_keeps_marker_pending(): void
    {
        $classes = $this->classes_fixture();
        update_option(self::CLASSES, $classes);
        $this->run_worker();

        $classes[0]['name'] = '--first-edit';
        update_option(self::CLASSES, $classes);

        // A concurrent editor save lands while the worker is reading the collection.
        $later = $classes;
        $later[0]['name'] = '--second-edit';
        $fired = false;
        $concurrent_save = static function ($available) use (&$fired, $later) {
            if (! $fired && $available) {
                $fired = true;
                update_option(self::CLASSES, $later);
            }
            return $available;
        };
        add_filter('dbvc_connected_bricks_available', $concurrent_save, 20);
        $summary = $this->run_worker();
        remove_filter('dbvc_connected_bricks_available', $concurrent_save, 20);

        $this->assertTrue($fired);
        $this->assertSame(1, $summary['requeued']);
        $this->assertSame(0, $summary['acknowledged']);
        $job = (new JobStore())->find(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, DomainRegistry::COLLECTION_KEY);
        $this->assertIsArray($job, 'The marker must survive processing when a newer generation arrived.');
        $this->assertSame(2, (int) $job['generation']);
        $this->assertNull($job['lease_token'], 'The lease is released so the next run can reclaim it.');
        $this->assertNotFalse(wp_next_scheduled(DirtyCapture::CRON_HOOK));

        $summary = $this->run_worker();
        $this->assertSame(1, $summary['acknowledged']);
        $this->assertSame(0, (new JobStore())->counts()['total']);
        $identity = (new InstanceIdentity())->all_for_domain(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS);
        $object = (new ObjectStore())->get(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, $identity['aaa111']['instance_uid'], 'bricks-global-class-v1');
        $this->assertSame(Canonicalizer::hash(Canonicalizer::encode($later[0])), $object['semantic_hash'], 'The settled state wins.');
    }

    public function test_expired_lease_is_reclaimable_and_the_stale_worker_is_fenced(): void
    {
        global $wpdb;

        update_option(self::CLASSES, $this->classes_fixture());
        $store = new JobStore();
        $first = $store->claim_due(10, 60);
        $this->assertCount(1, $first);
        $this->assertSame([], $store->claim_due(10, 60), 'A leased marker cannot be claimed twice.');

        // Simulate a crashed worker whose lease expired.
        $wpdb->update(Schema::table('jobs'), ['lease_until' => '2000-01-01 00:00:00'], ['job_id' => $first[0]['job_id']], ['%s'], ['%d']);
        $second = $store->claim_due(10, 60);
        $this->assertCount(1, $second);
        $this->assertNotSame($first[0]['lease_token'], $second[0]['lease_token']);
        $this->assertSame(2, $second[0]['attempts']);

        $this->assertSame(JobStore::OUTCOME_LEASE_LOST, $store->complete($first[0]), 'The stale worker must not acknowledge under a lost lease.');
        $this->assertSame(JobStore::OUTCOME_ACKNOWLEDGED, $store->complete($second[0]));
        $this->assertSame(0, $store->counts()['total']);

        // The worker itself drains a lease-expired marker and produces the events.
        update_option(self::CLASSES, $this->classes_fixture(1));
        $summary = $this->run_worker();
        $this->assertSame(1, $summary['acknowledged']);
        $this->assertGreaterThan(0, $summary['events']);
    }

    public function test_duplicate_storage_keys_fail_closed_without_tombstoning(): void
    {
        $classes = $this->classes_fixture();
        update_option(self::CLASSES, $classes);
        $this->run_worker();

        $ambiguous = $classes;
        $ambiguous[] = ['id' => 'bbb222', 'name' => '--duplicate-id', 'settings' => []];
        unset($ambiguous[2]);
        update_option(self::CLASSES, array_values($ambiguous));
        $summary = $this->run_worker();
        $this->assertSame(1, $summary['acknowledged']);
        $this->assertSame(0, $summary['tombstones'], 'An incomplete inventory must never infer deletion.');
        $this->assertContains('duplicate_storage_key:bbb222', $summary['jobs'][0]['problems']);

        $objects = (new ObjectStore())->all(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, 50);
        foreach ($objects as $object) {
            if ($object['storage_key'] === 'ccc333' || $object['storage_key'] === 'bbb222') {
                $this->assertSame(1, $object['object_exists'], $object['storage_key'] . ' keeps its last verified state.');
            }
            if ($object['instance_uid'] === BricksOptionCollectionObserver::ORDER_INSTANCE_UID) {
                $this->assertSame(0, $object['snapshot_complete'], 'Collection order is reported incomplete while identity is ambiguous.');
            }
        }
    }

    public function test_incomplete_member_projection_is_reported_not_hidden(): void
    {
        // Invalid UTF-8 never reaches the database through wpdb (it rejects the query), so the reachable
        // incompleteness case is a serialized non-finite float, which PHP round-trips and the canonicalizer refuses.
        $classes = $this->classes_fixture();
        $classes[0]['settings']['_opacity'] = INF;
        update_option(self::CLASSES, $classes);
        $summary = $this->run_worker();
        $this->assertSame(4, $summary['events']);

        $identity = (new InstanceIdentity())->all_for_domain(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS);
        $object = (new ObjectStore())->get(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, $identity['aaa111']['instance_uid'], 'bricks-global-class-v1');
        $this->assertSame(0, $object['snapshot_complete']);
        $this->assertSame(1, $object['object_exists']);
        $this->assertSame(BricksOptionCollectionObserver::incomplete_hash(), $object['semantic_hash']);
        $this->assertSame(['canonicalization:non_finite_float'], $object['problems']);
        $this->assertNull($object['snapshot_body']);
    }

    public function test_unavailable_bricks_holds_the_marker_and_reports_no_tombstones(): void
    {
        update_option(self::CLASSES, $this->classes_fixture());
        $this->run_worker();

        remove_filter('dbvc_connected_bricks_available', '__return_true');
        add_filter('dbvc_connected_bricks_available', '__return_false');
        delete_option(self::CLASSES);
        $summary = $this->run_worker();
        $this->assertSame(0, $summary['events']);
        $this->assertSame(1, $summary['retried']);
        $this->assertSame('source_unavailable:bricks_not_available', $summary['jobs'][0]['reason']);
        $job = (new JobStore())->find(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, DomainRegistry::COLLECTION_KEY);
        $this->assertSame('source_unavailable:bricks_not_available', $job['last_error_code']);
        $counts = (new ObjectStore())->counts_by_domain()[DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS];
        $this->assertSame(0, $counts['absent'], 'Unavailable coverage must never be reported as deletion.');
        $this->assertSame(4, $counts['present'], 'Three members plus the order projection keep their last verified state.');

        $observer = DomainRegistry::observer_for(DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS);
        $this->assertFalse($observer->capabilities()['report']);
        $this->assertSame('unavailable', $observer->snapshot(['storage_key' => 'aaa111'])['status']);
    }

    public function test_variables_domain_is_observed_independently(): void
    {
        $variables = [
            ['id' => 'var001', 'name' => 'gap-l', 'type' => 'static', 'category' => 'cat1', 'value' => 'clamp(1rem, 2vw, 2rem)'],
            ['id' => 'var002', 'name' => 'bg-l-d', 'type' => 'static', 'category' => 'cat1', 'value' => 'hsla(222, 100%, 97.5%, 1)'],
        ];
        update_option(self::VARIABLES, $variables);
        update_option(self::CLASSES, $this->classes_fixture(1));
        $this->assertSame(2, (new JobStore())->counts()['total']);

        $summary = $this->run_worker();
        $this->assertSame(2, $summary['acknowledged']);
        $this->assertSame(5, $summary['events'], 'Two variables + order, one class + order.');
        $counts = (new ObjectStore())->counts_by_domain();
        $this->assertSame(3, $counts[DomainRegistry::DOMAIN_BRICKS_VARIABLE]['total']);
        $this->assertSame(2, $counts[DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]['total']);
        $variable_objects = (new ObjectStore())->all(DomainRegistry::DOMAIN_BRICKS_VARIABLE, 10);
        $this->assertSame('bricks-variable-v1', $variable_objects[0]['profile']);
        $this->assertStringStartsWith('bricks-variable-', $variable_objects[0]['instance_uid']);
        $this->assertSame('gap-l', $variable_objects[0]['display_name']);

        $variables[0]['value'] = '2rem';
        update_option(self::VARIABLES, $variables);
        $summary = $this->run_worker();
        $this->assertSame(1, $summary['events']);
        $all_events = (new OutboxStore())->all();
        $this->assertSame(DomainRegistry::DOMAIN_BRICKS_VARIABLE, end($all_events)['domain']);
    }

    public function test_worker_is_blocked_until_identity_is_initialized(): void
    {
        global $wpdb;

        update_option(self::CLASSES, $this->classes_fixture());
        $wpdb->update(Schema::table('state'), ['enrollment_state' => StateStore::ENROLLMENT_UNINITIALIZED, 'environment_id' => ''], ['singleton_id' => 1], ['%s', '%s'], ['%d']);
        $summary = $this->run_worker();
        $this->assertSame('identity_uninitialized', $summary['blocked']);
        $this->assertSame(0, $summary['claimed']);
        $this->assertSame(1, (new JobStore())->counts()['total']);
        $this->assertSame([], $this->events_since_baseline());

        (new StateStore())->initialize_provisional();
        $summary = $this->run_worker();
        $this->assertNull($summary['blocked']);
        $this->assertSame(4, $summary['events']);
    }

    public function test_cli_inspector_exposes_read_only_state_and_explicit_processing(): void
    {
        update_option(self::CLASSES, $this->classes_fixture());

        $status = DBVC_Connected_CLI_Inspector::status();
        $this->assertSame('ready', $status['gate_state']);
        $this->assertTrue($status['runtime_registered']);
        $this->assertTrue($status['schema']['ready']);
        $this->assertSame('provisional', $status['identity']['enrollment_state']);
        $this->assertSame(1, $status['jobs']['total']);
        $this->assertSame('available', $status['domains'][DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]['coverage']);
        $this->assertSame('pending', $status['domains'][DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]['observation']);
        $this->assertSame('disabled', $status['hub']['gate_state']);
        $this->assertNull($status['hub']['runtime']);

        $inventory = DBVC_Connected_CLI_Inspector::inventory(['domain' => DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS, 'limit' => 2]);
        $this->assertSame('available', $inventory['status']);
        $this->assertFalse($inventory['complete'], 'A bounded page is not a complete inventory.');
        $this->assertSame(2, $inventory['next_cursor']);
        $this->assertSame(3, $inventory['total']);
        $this->assertSame('identity_missing', $inventory['items'][0]['identity']);
        $this->assertSame(0, (new InstanceIdentity())->count(), 'Inventory is read-only and assigns no identity.');

        $jobs = DBVC_Connected_CLI_Inspector::jobs(['limit' => 10]);
        $this->assertSame(1, $jobs['returned']);
        $this->assertSame('no', $jobs['jobs'][0]['leased']);

        $processed = DBVC_Connected_CLI_Inspector::process(['limit' => 10, 'budget' => 5]);
        $this->assertSame('cli', $processed['context']);
        $this->assertSame(1, $processed['acknowledged']);

        $objects = DBVC_Connected_CLI_Inspector::objects(['domain' => DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]);
        $this->assertSame(4, $objects['returned']);
        $outbox = DBVC_Connected_CLI_Inspector::outbox(['state' => 'pending']);
        $this->assertSame(self::BASELINE_EVENTS + 4, $outbox['returned']);
        $this->assertSame('observed', DBVC_Connected_CLI_Inspector::status()['domains'][DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS]['observation']);
        $reconcile = DBVC_Connected_CLI_Inspector::reconcile(['domain' => DomainRegistry::DOMAIN_BRICKS_VARIABLE]);
        $this->assertSame([DomainRegistry::DOMAIN_BRICKS_VARIABLE => 1], $reconcile['generations']);
        $this->assertSame('pending', DBVC_Connected_CLI_Inspector::status()['domains'][DomainRegistry::DOMAIN_BRICKS_VARIABLE]['observation']);
        $this->assertSame('pending', $outbox['events'][0]['delivery_state']);
        $this->assertSame('mapped', DBVC_Connected_CLI_Inspector::inventory(['domain' => DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS])['items'][0]['identity']);
        $this->assertSame('cli', DBVC_Connected_CLI_Inspector::status()['worker']['context']);

        $this->assertInstanceOf(WP_Error::class, DBVC_Connected_CLI_Inspector::inventory(['domain' => 'bricks.template']));

        DBVC_Connected_Environments_Addon::save_settings([]);
        $disabled = DBVC_Connected_CLI_Inspector::status();
        $this->assertSame('disabled', $disabled['gate_state']);
        $this->assertArrayNotHasKey('jobs', $disabled);
        $this->assertSame('dbvc_connected_cli_not_ready', DBVC_Connected_CLI_Inspector::jobs([])->get_error_code());
        $this->assertSame('dbvc_connected_cli_not_ready', DBVC_Connected_CLI_Inspector::process([])->get_error_code());
    }

    /**
     * Events produced after the set_up reconciliation baseline.
     *
     * @return array<int, array<string, mixed>>
     */
    private function events_since_baseline(): array
    {
        return array_values(array_filter((new OutboxStore())->all(null, 500), static function ($event) {
            return $event['source_sequence'] > self::BASELINE_EVENTS;
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function run_worker(): array
    {
        $runtime = DBVC_Connected_Environments_Addon::runtime();
        $this->assertNotNull($runtime);

        return $runtime->worker()->run(['context' => 'test', 'budget_seconds' => 30]);
    }

    /**
     * Shapes mirror live Bricks 2.3.8 storage: sequential list, `id`, `name`, `settings`, optional `cat`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function classes_fixture(int $count = 3): array
    {
        $fixture = [
            ['id' => 'aaa111', 'name' => '--flex-col', 'settings' => ['_direction' => 'column'], 'cat' => false],
            ['id' => 'bbb222', 'name' => '--flex-row', 'settings' => ['_direction' => 'row', '_display' => 'flex']],
            ['id' => 'ccc333', 'name' => '--bg-gradient-light', 'settings' => ['_gradient' => ['applyTo' => 'overlay', 'colors' => [['id' => 'vypvsh', 'stop' => '0'], ['id' => 'wjtzex', 'stop' => '100']]], '_cssCustom' => ".--bg-gradient-light:before {\n\topacity: 0.4;\n}"], 'cat' => 'rgxlvq'],
        ];

        return array_slice($fixture, 0, $count);
    }
}
