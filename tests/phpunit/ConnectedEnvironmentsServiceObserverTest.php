<?php

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Adapters\ServicePostObserver;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\ConnectedProtocol\Canonicalizer;

/**
 * `wp.service` observation through DBVC's portable identity and masking
 * owners: per-object dirty markers, projections over post fields, masked
 * meta and taxonomies, identity_missing without backfill, trash/delete
 * tombstones, and reconciliation of edits made while disabled.
 *
 * @group connected-environments
 */
class ConnectedEnvironmentsServiceObserverTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_connected_bricks_available', '__return_true');
        add_filter('dbvc_connected_processing_delay', '__return_zero');
        add_filter('dbvc_should_export_on_option_update', '__return_false');
        add_filter('pre_http_request', [$this, 'trap_http'], 10, 3);
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        register_taxonomy('service_area', 'service');
        update_option('dbvc_post_types', ['service']); // DBVC assigns vf_object_uid on save for its configured post types.
        delete_option('dbvc_mask_meta_keys');
        delete_option('dbvc_mask_subkeys');
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $settled = $this->run_worker();
        $this->assertSame(3, $settled['acknowledged'], wp_json_encode($settled['jobs']));
        $inventory = $this->inventory_row();
        $this->assertSame(1, $inventory['snapshot_complete'], 'An empty service inventory is a complete fact: no services exist yet.');
        $this->assertSame([], json_decode($inventory['snapshot_body'], true));
    }

    public function tear_down(): void
    {
        // Registrations are process-global (not rolled back with the DB); leave no extra sources for later suites.
        unregister_taxonomy('service_area');
        unregister_post_type('service');
        DBVC_Connected_Environments_Addon::save_settings([]);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        remove_filter('dbvc_connected_processing_delay', '__return_zero');
        remove_filter('dbvc_should_export_on_option_update', '__return_false');
        remove_filter('pre_http_request', [$this, 'trap_http'], 10);
        delete_option('dbvc_post_types');
        delete_option('dbvc_mask_meta_keys');
        delete_option('dbvc_mask_action');
        Schema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function trap_http($preempt, $args, $url)
    {
        unset($preempt, $args);
        $this->fail('Unexpected HTTP request: ' . (string) $url);
    }

    public function test_service_save_produces_one_settled_observation_with_dbvc_identity(): void
    {
        $observer = DomainRegistry::observer_for('wp.service');
        $this->assertInstanceOf(ServicePostObserver::class, $observer);
        $this->assertTrue($observer->capabilities()['report']);
        $this->assertTrue($observer->capabilities()['identity_available']);
        $this->assertSame(['service' => 'wp.service'], DomainRegistry::watched_post_types());

        $post_id = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Roof repair', 'post_content' => 'Content v1', 'post_name' => 'roof-repair']);
        update_post_meta($post_id, 'price', '250');
        update_post_meta($post_id, 'cta', ['label' => 'Book', 'url' => '/book']);
        wp_set_object_terms($post_id, ['north', 'south'], 'service_area');
        $uid = (string) get_post_meta($post_id, 'vf_object_uid', true);
        $this->assertNotSame('', $uid, 'DBVC assigned the portable identity on save.');

        $job = (new JobStore())->find('wp.service', 'post:' . $post_id);
        $this->assertIsArray($job, 'Each save-side signal marks the individual post.');
        $this->assertGreaterThanOrEqual(2, (int) $job['generation'], 'Insert, meta and term writes coalesce into one marker.');
        $this->assertSame(1, (new JobStore())->counts()['total']);

        $summary = $this->run_worker();
        $this->assertSame(1, $summary['acknowledged']);
        $this->assertSame(2, $summary['events'], 'Many low-level writes settle into one observation, plus the inventory projection that now lists the post.');
        $this->assertSame(0, $summary['identities_assigned'], 'The domain owns identity; the sidecar is untouched.');
        $inventory = $this->inventory_row();
        $this->assertSame(1, $inventory['snapshot_complete']);
        $this->assertSame([$uid], json_decode($inventory['snapshot_body'], true), 'The inventory is the sorted UID set under its own profile.');
        $this->assertSame(ServicePostObserver::INVENTORY_PROFILE, $this->last_event()['body']['projection']['profile']);

        $object = (new ObjectStore())->get('wp.service', $uid, ServicePostObserver::PROFILE);
        $this->assertIsArray($object);
        $this->assertSame((string) $post_id, $object['storage_key']);
        $this->assertSame(1, $object['snapshot_complete']);
        $canonical = json_decode($object['snapshot_body'], true);
        $this->assertSame('Roof repair', $canonical['post_title']);
        $this->assertSame('publish', $canonical['post_status']);
        $this->assertSame(['250'], $canonical['meta']['price']);
        $this->assertSame([['label' => 'Book', 'url' => '/book']], $canonical['meta']['cta']);
        $this->assertArrayNotHasKey('vf_object_uid', $canonical['meta']);
        $this->assertArrayNotHasKey('_edit_lock', $canonical['meta']);
        $this->assertArrayNotHasKey('post_modified', $canonical);
        $this->assertArrayNotHasKey('post_author', $canonical);
        $this->assertArrayNotHasKey('ID', $canonical);
        $this->assertSame(['north', 'south'], array_column($canonical['tax_input']['service_area'], 'slug'));

        $event = $this->event_for($uid);
        $this->assertSame('wp.service', $event['body']['object']['domain']);
        $this->assertSame($uid, $event['body']['object']['instance_uid']);
        $this->assertSame(ServicePostObserver::PROFILE, $event['body']['projection']['profile']);
        $this->assertTrue($event['body']['projection']['complete']);

        // Content edit → exactly one new event; no-op save → nothing.
        wp_update_post(['ID' => $post_id, 'post_content' => 'Content v2']);
        $summary = $this->run_worker();
        $this->assertSame(1, $summary['events']);
        $this->assertSame(Canonicalizer::hash(Canonicalizer::encode(json_decode((new ObjectStore())->get('wp.service', $uid, ServicePostObserver::PROFILE)['snapshot_body'], true))), $this->last_event()['body']['projection']['hash']);
        (new JobStore())->mark_dirty('wp.service', 'post:' . $post_id, [], 0);
        $this->assertSame(0, $this->run_worker()['events']);
        $this->assertSame('Content v2', get_post($post_id)->post_content, 'The reporter never edits the post.');
    }

    public function test_masked_meta_marks_the_projection_incomplete_instead_of_hashing_placeholders(): void
    {
        update_option('dbvc_mask_meta_keys', 'secret_*');
        update_option('dbvc_mask_action', 'redact');
        $post_id = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Masked service']);
        update_post_meta($post_id, 'secret_api_key', 'sk-live-123');
        update_post_meta($post_id, 'public_note', 'ok');
        $uid = (string) get_post_meta($post_id, 'vf_object_uid', true);

        $this->run_worker();
        $object = (new ObjectStore())->get('wp.service', $uid, ServicePostObserver::PROFILE);
        $this->assertSame(0, $object['snapshot_complete'], 'A masked field cannot prove equivalence.');
        $this->assertSame(['masked:secret_api_key'], $object['problems']);
        $canonical = json_decode($object['snapshot_body'], true);
        $this->assertSame('***', $canonical['meta']['secret_api_key'], 'The redacted placeholder is what leaves the site, never the raw value.');
        $this->assertStringNotContainsString('sk-live-123', $object['snapshot_body']);
        $this->assertSame(['ok'], $canonical['meta']['public_note']);
        $this->assertFalse($this->event_for($uid)['body']['projection']['complete']);
        $this->assertSame(1, $this->inventory_row()['snapshot_complete'], 'Masking affects the member, not the inventory (the UID is known).');
    }

    public function test_identity_missing_is_an_explicit_condition_and_is_never_backfilled(): void
    {
        remove_action('save_post', ['DBVC_Sync_Posts', 'ensure_post_uid_on_save'], 1);
        $post_id = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'No identity yet']);
        add_action('save_post', ['DBVC_Sync_Posts', 'ensure_post_uid_on_save'], 1, 2);
        $this->assertSame('', (string) get_post_meta($post_id, 'vf_object_uid', true));

        $observer = DomainRegistry::observer_for('wp.service');
        $this->assertSame('identity_missing', $observer->snapshot(['storage_key' => (string) $post_id])['status']);
        $inventory = $observer->inventory(['limit' => 0]);
        $this->assertSame([], $inventory['items']);
        $this->assertFalse($inventory['complete'], 'An inventory with an unidentified member is never complete.');
        $this->assertContains('identity_missing:post=' . $post_id, $inventory['problems']);

        $summary = $this->run_worker();
        $this->assertSame(1, $summary['retried']);
        $this->assertSame('identity_missing', $summary['jobs'][0]['reason']);
        $this->assertSame('', (string) get_post_meta($post_id, 'vf_object_uid', true), 'Reads never assign identity.');
        $this->assertSame(0, $summary['events']);
        $job = (new JobStore())->find('wp.service', 'post:' . $post_id);
        $this->assertSame('identity_missing', $job['last_error_code']);
        $this->assertSame(BricksOptionCollectionObserver::incomplete_hash(), $observer->inventory_projection()['hash'], 'Coverage cannot be complete while a member has no identity.');
        // A reconciliation while the member is unidentified records the inventory as incomplete rather than pretending coverage.
        DBVC_Connected_Environments_Addon::request_reconciliation('wp.service', 'test');
        $this->run_worker();
        $this->assertSame(0, $this->inventory_row()['snapshot_complete']);
        $wpdb_jobs = (new JobStore())->find('wp.service', 'post:' . $post_id);
        $this->assertIsArray($wpdb_jobs, 'The per-object marker is still pending.');

        // An ordinary DBVC save assigns the identity; the next run captures the post.
        wp_update_post(['ID' => $post_id, 'post_title' => 'Identified now']);
        $uid = (string) get_post_meta($post_id, 'vf_object_uid', true);
        $this->assertNotSame('', $uid);
        global $wpdb;
        $wpdb->update(Schema::table('jobs'), ['available_at' => '2000-01-01 00:00:00'], ['job_id' => $job['job_id']], ['%s'], ['%d']);
        $summary = $this->run_worker();
        $this->assertSame(2, $summary['events'], 'The post and the now-complete inventory.');
        $this->assertSame($uid, $this->event_for($uid)['body']['object']['instance_uid']);
        $inventory = $this->inventory_row();
        $this->assertSame(1, $inventory['snapshot_complete']);
        $this->assertSame([$uid], json_decode($inventory['snapshot_body'], true));
    }

    public function test_trash_and_delete_produce_a_single_verified_tombstone(): void
    {
        $post_id = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Temporary']);
        $uid = (string) get_post_meta($post_id, 'vf_object_uid', true);
        $this->run_worker();
        $before = count($this->events());

        wp_trash_post($post_id);
        $summary = $this->run_worker();
        $this->assertSame(1, $summary['tombstones']);
        $this->assertSame(2, $summary['events'], 'The tombstone and the inventory that no longer lists the post.');
        $tombstone = $this->event_for($uid);
        $this->assertSame($uid, $tombstone['body']['object']['instance_uid']);
        $this->assertFalse($tombstone['body']['projection']['exists']);
        $this->assertSame(0, (new ObjectStore())->get('wp.service', $uid, ServicePostObserver::PROFILE)['object_exists']);
        $this->assertSame([], json_decode($this->inventory_row()['snapshot_body'], true));

        wp_delete_post($post_id, true);
        $summary = $this->run_worker();
        $this->assertSame(0, $summary['events'], 'Deleting an already tombstoned post emits nothing new.');
        $this->assertCount($before + 2, $this->events());

        wp_delete_post(wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Never observed']), true);
        $this->assertSame(0, $this->run_worker()['events'], 'A post that was never observed has no row to tombstone.');
    }

    public function test_reconciliation_catches_up_edits_and_deletions_made_while_disabled(): void
    {
        $kept = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Kept']);
        $removed = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Removed later']);
        $removed_uid = (string) get_post_meta($removed, 'vf_object_uid', true);
        $this->run_worker();
        $this->assertSame(3, (new ObjectStore())->counts_by_domain()['wp.service']['present'], 'Two posts plus the inventory projection.');

        DBVC_Connected_Environments_Addon::save_settings([]);
        wp_update_post(['ID' => $kept, 'post_title' => 'Kept (edited offline)']);
        wp_delete_post($removed, true);
        $added = wp_insert_post(['post_type' => 'service', 'post_status' => 'draft', 'post_title' => 'Added offline']);
        $this->assertSame(0, (new JobStore())->counts()['total'], 'Nothing is captured while disabled.');

        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $summary = $this->run_worker();
        $service = array_values(array_filter($summary['jobs'], static function ($job) {
            return $job['domain'] === 'wp.service';
        }))[0];
        $this->assertSame('acknowledged', $service['outcome']);
        $this->assertSame(3, $service['events'] - $service['tombstones'], 'The edited and the added post are re-observed, and the inventory changed.');
        $this->assertSame(1, $service['tombstones'], 'The deletion is proven by the complete inventory.');
        $this->assertSame(0, (new ObjectStore())->get('wp.service', $removed_uid, ServicePostObserver::PROFILE)['object_exists']);
        $counts = (new ObjectStore())->counts_by_domain()['wp.service'];
        $this->assertSame(['total' => 4, 'present' => 3, 'absent' => 1, 'incomplete' => 0], $counts);
        $this->assertSame('reconciliation', $this->last_event()['body']['origin']);
        $this->assertSame('Added offline', get_post($added)->post_title);
    }

    /**
     * @return array<string, mixed>
     */
    private function last_event(): array
    {
        $events = $this->events();

        return end($events);
    }

    /**
     * Latest event for one instance UID.
     *
     * @return array<string, mixed>
     */
    private function event_for(string $uid): array
    {
        $match = null;
        foreach ($this->events() as $event) {
            if ($event['body']['object']['instance_uid'] === $uid) {
                $match = $event;
            }
        }
        $this->assertIsArray($match, 'No event for ' . $uid);

        return $match;
    }

    /**
     * @return array<string, mixed>
     */
    private function inventory_row(): array
    {
        $row = (new ObjectStore())->get('wp.service', BricksOptionCollectionObserver::ORDER_INSTANCE_UID, ServicePostObserver::INVENTORY_PROFILE);
        $this->assertIsArray($row, 'The service inventory projection is missing.');

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(): array
    {
        return array_values(array_filter((new OutboxStore())->all(null, 500), static function ($event) {
            return $event['domain'] === 'wp.service';
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function run_worker(): array
    {
        return DBVC_Connected_Environments_Addon::runtime()->worker()->run(['context' => 'test', 'budget_seconds' => 30]);
    }
}
