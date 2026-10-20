<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService as HubEnrollment;
use Dbvc\AgencyControl\Release\PreparationService;
use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Storage\PreparationStore as HubPreparationStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\Connected\Enrollment\EnrollmentService;
use Dbvc\Connected\Identity\InstanceIdentity;
use Dbvc\Connected\Preparation\Preparer;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\PreparationStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M4 step 1: release manifests, authorized payload transfer and target
 * prepare receipts (dry runs) — connector ⇄ hub inside one install through
 * the loopback shim. The local connector plays the release source in one
 * scenario and the prepare target in the other; a second environment is
 * simulated directly against the hub's REST routes.
 *
 * @group connected-environments
 */
require_once __DIR__ . '/support/ConnectedLoopbackTrait.php';

class ConnectedReleaseTest extends WP_UnitTestCase
{
    use ConnectedLoopbackTrait;

    private const CLASSES = 'bricks_global_classes';
    private const HUB = 'http://hub.test';

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_connected_bricks_available', '__return_true');
        add_filter('dbvc_connected_processing_delay', '__return_zero');
        add_filter('dbvc_connected_allow_insecure_hub', '__return_true');
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        add_filter('pre_http_request', [$this, 'loopback'], 10, 3);
        $this->http_log = [];
        $this->simulated_transport_error = null;
        $this->transport_failure_predicate = null;

        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        update_option('dbvc_post_types', ['service']);
        delete_option(self::CLASSES);
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
        update_option(self::CLASSES, $this->classes_fixture());
        $this->run_observation();
    }

    public function tear_down(): void
    {
        // Registrations are process-global (not rolled back with the DB); leave no extra sources for later suites.
        unregister_taxonomy('service_area');
        unregister_post_type('service');
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        DBVC_Connected_Environments_Addon::save_settings([]);
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        remove_filter('dbvc_connected_processing_delay', '__return_zero');
        remove_filter('dbvc_connected_allow_insecure_hub', '__return_true');
        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        remove_filter('pre_http_request', [$this, 'loopback'], 10);
        delete_option(self::CLASSES);
        delete_option('dbvc_post_types');
        Schema::reset_memo();
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_source_supplies_verified_payloads_and_the_release_seals_or_records_a_mismatch(): void
    {
        $local = $this->enroll_local();
        $uids = $this->local_uids();
        $releases = new ReleaseService();
        $store = new ReleaseStore();

        // Manifest selection is validated against the source's current, complete, existing projections.
        $this->assertSame('dbvc_agency_projection_not_found', $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => 'nope']])->get_error_code());
        $this->assertSame('dbvc_agency_invalid_identifier', $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => 'collection.order']])->get_error_code());
        $this->assertSame('dbvc_agency_invalid_selection', $releases->create($local['environment_id'], [])->get_error_code());
        $created = $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111']], ['domain' => 'bricks.global_class', 'instance_uid' => $uids['bbb222']], ['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111']]], 'two classes');
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
        $release = $created['release'];
        $this->assertSame(ReleaseStore::STATE_OPEN, $release['state']);
        $this->assertSame(2, $release['item_count'], 'Duplicate selections collapse.');
        $this->assertSame('', $release['digest']);
        $this->assertSame($local['epoch'], $release['source_epoch']);
        foreach ($created['items'] as $item) {
            $this->assertSame(ReleaseStore::PAYLOAD_REQUESTED, $item['payload_state']);
            $this->assertSame((new ProjectionStore())->get($local['environment_id'], $local['epoch'], 'bricks.global_class', $item['instance_uid'], 'bricks-global-class-v1')['semantic_hash'], $item['after_hash']);
        }
        $this->assertSame('dbvc_agency_release_not_sealed', (new PreparationService())->request($release['release_uid'], 'other')->get_error_code());

        // The connector answers the payload request with the recorded canonical bodies; the hub verifies each hash and seals.
        $before = maybe_serialize(get_option(self::CLASSES));
        $summary = $this->run_release();
        $this->assertNull($summary['blocked'], wp_json_encode($summary));
        $this->assertSame([2, 2, 0], [$summary['payload_requests'], $summary['payloads_sent'], $summary['payloads_mismatched']]);
        $sealed = $store->find($release['release_uid']);
        $this->assertSame(ReleaseStore::STATE_SEALED, $sealed['state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sealed['digest']);
        $this->assertNotEmpty($sealed['sealed_at']);
        foreach ($store->items($sealed['release_id'], true) as $item) {
            $this->assertSame(ReleaseStore::PAYLOAD_RECEIVED, $item['payload_state']);
            $this->assertSame($item['after_hash'], Canonicalizer::hash($item['payload']));
            $this->assertSame((new ObjectStore())->get('bricks.global_class', $item['instance_uid'], 'bricks-global-class-v1')['snapshot_body'], $item['payload']);
        }
        $this->assertSame($before, maybe_serialize(get_option(self::CLASSES)), 'Supplying payloads never touches source content.');
        $this->assertSame(0, $this->run_release()['payload_requests'], 'Nothing is requested twice.');

        // A source edit after the manifest was fixed: the connector reports the mismatch instead of sending a different body.
        $second = $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => $uids['ccc333']]]);
        $classes = $this->classes_fixture();
        $classes[2]['settings']['_gradient']['colors'][0]['stop'] = '10';
        update_option(self::CLASSES, $classes);
        $this->run_observation();
        $summary = $this->run_release();
        $this->assertSame([1, 0, 1], [$summary['payload_requests'], $summary['payloads_sent'], $summary['payloads_mismatched']]);
        $second_release = $store->find($second['release']['release_uid']);
        $this->assertSame(ReleaseStore::STATE_OPEN, $second_release['state'], 'A release with a mismatched item never seals.');
        $item = $store->items($second_release['release_id'])[0];
        $this->assertSame(ReleaseStore::PAYLOAD_MISMATCH, $item['payload_state']);
        $this->assertStringStartsWith('source_changed:', $item['payload_reason']);
        $this->assertSame('dbvc_agency_release_not_sealed', (new PreparationService())->request($second_release['release_uid'], 'other')->get_error_code());
        $this->assertTrue($releases->withdraw($second_release['release_uid'])['changed']);

        // A tampered body is refused by hash, a foreign principal cannot supply payloads, a sealed release accepts none.
        $third = $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111']]]);
        $foreign = $this->enroll_remote('client-a', 'foreign-env');
        $response = $this->as_environment($foreign, 'POST', '/dbvc-agency/v1/releases/' . $third['release']['release_uid'] . '/payloads', ['items' => [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => '{}']]]);
        $this->assertSame(404, $response->get_status());
        $this->assertSame('dbvc_agency_release_not_found', $response->get_data()['code']);
        $tampered = (new ReleaseService())->accept_payloads((new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->find($local['environment_id']), $third['release']['release_uid'], [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode(['tampered' => true])]]);
        $this->assertSame('hash_mismatch', $tampered['outcomes'][0]['outcome']);
        $this->assertFalse($tampered['sealed']);
        $this->assertSame('dbvc_agency_release_not_open', (new ReleaseService())->accept_payloads((new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->find($local['environment_id']), $release['release_uid'], [['domain' => 'x', 'instance_uid' => 'y', 'profile' => 'z', 'body' => '{}']])->get_error_code());

        // A deletion is released from a verified absence: the source tombstones bbb222, the payload is the canonical `null`.
        $this->assertSame('dbvc_agency_projection_present', $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => $uids['bbb222'], 'operation' => 'delete']])->get_error_code(), 'Only an absent object can be released as a deletion.');
        update_option(self::CLASSES, [$classes[0], $classes[2]]);
        $this->run_observation();
        DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);
        $this->assertSame('dbvc_agency_projection_absent', $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => $uids['bbb222']]])->get_error_code(), 'An absent object is not released as a replacement.');
        $this->assertSame('dbvc_agency_invalid_operation', $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => $uids['bbb222'], 'operation' => 'purge']])->get_error_code());
        $deletion = $releases->create($local['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => $uids['bbb222'], 'operation' => 'delete']], 'remove bbb222');
        $this->assertIsArray($deletion, is_wp_error($deletion) ? $deletion->get_error_message() : '');
        $this->assertSame(ReleaseStore::OPERATION_DELETE, $deletion['items'][0]['operation']);
        $this->assertSame(\Dbvc\AgencyControl\Comparison\ComparisonService::absent_hash(), $deletion['items'][0]['after_hash']);
        $summary = $this->run_release();
        $this->assertSame([1, 1, 0], [$summary['payload_requests'], $summary['payloads_sent'], $summary['payloads_mismatched']]);
        $sealed_deletion = $store->find($deletion['release']['release_uid']);
        $this->assertSame(ReleaseStore::STATE_SEALED, $sealed_deletion['state']);
        $this->assertSame(Canonicalizer::encode(null), $store->items($sealed_deletion['release_id'], true)[0]['payload']);
    }

    public function test_target_prepares_a_sealed_release_as_a_dry_run_and_the_hub_stores_the_annotated_receipt(): void
    {
        $local = $this->enroll_local();
        $uids = $this->local_uids();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        $local_object = (new ObjectStore())->get('bricks.global_class', $uids['aaa111'], 'bricks-global-class-v1');
        $local_bbb = (new ObjectStore())->get('bricks.global_class', $uids['bbb222'], 'bricks-global-class-v1');

        // A service post the target owns by portable identity.
        $post_id = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Roof repair', 'post_content' => 'v1']);
        $service_uid = (string) get_post_meta($post_id, 'vf_object_uid', true);
        $this->run_observation();
        $local_service = (new ObjectStore())->get('wp.service', $service_uid, 'wp-service-v1');
        $this->assertIsArray($local_service);

        // The source reports: aaa111 with a new body (shared lineage → ready), bbb222 unchanged (noop), an unknown uid (blocked),
        // and the service with a changed title (ready).
        $after_aaa = json_decode($local_object['snapshot_body'], true);
        $after_aaa['settings']['_direction'] = 'row-reverse';
        $after_aaa_body = Canonicalizer::encode($after_aaa);
        $after_service = json_decode($local_service['snapshot_body'], true);
        $after_service['post_title'] = 'Roof repair (studio)';
        $after_service_body = Canonicalizer::encode($after_service);
        $unknown_body = Canonicalizer::encode(['id' => 'zzz999', 'name' => '--new', 'settings' => []]);
        $bodies = [
            ['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => $after_aaa_body],
            ['domain' => 'bricks.global_class', 'instance_uid' => $uids['bbb222'], 'profile' => 'bricks-global-class-v1', 'body' => $local_bbb['snapshot_body']],
            ['domain' => 'bricks.global_class', 'instance_uid' => 'bricks-global-class-unknown1', 'profile' => 'bricks-global-class-v1', 'body' => $unknown_body],
            ['domain' => 'wp.service', 'instance_uid' => $service_uid, 'profile' => 'wp-service-v1', 'body' => $after_service_body],
        ];
        $events = [];
        foreach ($bodies as $index => $body) {
            $events[] = ObservationEvent::build([
                'event_id' => 'obs-' . str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT), 'environment_id' => 'studio-src', 'installation_epoch' => $remote['epoch'], 'sequence' => $index + 1, 'observed_at' => '2026-09-19T10:00:00Z',
                'object' => ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid']],
                'projection' => ['profile' => $body['profile'], 'hash' => Canonicalizer::hash($body['body']), 'exists' => true, 'complete' => true],
            ]);
        }
        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/observations', ['events' => $events]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(4, $response->get_data()['counts']['accepted']);

        $created = (new ReleaseService())->create('studio-src', array_map(static function ($body) {
            return ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid']];
        }, $bodies), 'studio release');
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
        $release_uid = $created['release']['release_uid'];
        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => $bodies]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertTrue($response->get_data()['sealed']);
        $digest = $response->get_data()['digest'];

        // Prepare request: scope and state checks, then one outstanding request per release/target.
        $preparations = new PreparationService();
        $this->assertSame('dbvc_agency_self_release', $preparations->request($release_uid, 'studio-src')->get_error_code());
        $other_client = $this->enroll_remote('client-b', 'b-prod');
        $this->assertSame('dbvc_agency_scope_mismatch', $preparations->request($release_uid, 'b-prod')->get_error_code());
        $requested = $preparations->request($release_uid, $local['environment_id']);
        $this->assertIsArray($requested, is_wp_error($requested) ? $requested->get_error_message() : '');
        $this->assertSame(HubPreparationStore::STATE_REQUESTED, $requested['state']);
        $this->assertSame('dbvc_agency_preparation_pending', $preparations->request($release_uid, $local['environment_id'])->get_error_code());

        // The receipt POST fails once (transport): the receipt is kept locally and reported on the next run.
        $classes_before = maybe_serialize(get_option(self::CLASSES));
        $post_before = get_post($post_id)->post_title;
        $this->simulated_transport_error = new WP_Error('http_request_failed', 'simulated outage');
        $this->transport_failure_predicate = static function ($url) {
            return strpos((string) $url, 'prepare-receipts') !== false;
        };
        $summary = $this->run_release();
        $this->assertSame(1, $summary['prepare_requests']);
        $this->assertSame(1, $summary['receipts_stored']);
        $this->assertSame(0, $summary['receipts_reported']);
        $this->assertStringStartsWith('transport:', (string) $summary['blocked']);
        $local_store = new PreparationStore();
        $stored = $local_store->find($requested['operation_id']);
        $this->assertIsArray($stored);
        $this->assertNull($stored['reported_at']);
        $this->assertSame(HubPreparationStore::STATE_REQUESTED, (new HubPreparationStore())->find($requested['operation_id'])['state']);
        $this->simulated_transport_error = null;
        $this->transport_failure_predicate = null;
        $summary = $this->run_release();
        $this->assertNull($summary['blocked'], wp_json_encode($summary));
        $this->assertSame(1, $summary['receipts_recovered']);
        $this->assertSame(0, $summary['receipts_stored'], 'The request is not prepared twice.');
        $this->assertNotEmpty($local_store->find($requested['operation_id'])['reported_at']);

        // The receipt: exact patches for the two matched objects, noop for the identical one, an explicit blocker for the unknown uid.
        $receipt = $stored['receipt'];
        $this->assertSame($release_uid, $receipt['release_uid']);
        $this->assertSame($digest, $receipt['release_digest']);
        $this->assertSame(['environment_id' => $local['environment_id'], 'epoch' => $local['epoch']], $receipt['target']);
        $this->assertSame('partial', $receipt['outcome']);
        $this->assertSame(['ready' => 2, 'noop' => 1, 'blocked' => 1], $receipt['counts']);
        $this->assertSame(Canonicalizer::VERSION, $receipt['compatibility']['canonicalizer']);
        $this->assertTrue($receipt['compatibility']['domains']['wp.service']['available']);
        $items = [];
        foreach ($receipt['items'] as $item) {
            $items[$item['instance_uid']] = $item;
        }
        $aaa = $items[$uids['aaa111']];
        $this->assertSame(Preparer::OUTCOME_READY, $aaa['outcome']);
        $this->assertSame(['storage_key' => 'aaa111', 'source' => 'sidecar'], $aaa['identity']);
        $this->assertSame($local_object['semantic_hash'], $aaa['before_hash']);
        $this->assertSame(Canonicalizer::hash($after_aaa_body), $aaa['after_hash']);
        $this->assertSame('option:bricks_global_classes', $aaa['container']);
        $this->assertNotEmpty($aaa['storage_fingerprint']);
        $this->assertSame('replace_object', $aaa['patch']['strategy']);
        $this->assertSame([['path' => 'settings', 'operation' => 'replace']], array_map(static function ($path) {
            return ['path' => $path['path'], 'operation' => $path['operation']];
        }, $aaa['patch']['changed_paths']));
        $this->assertSame(Preparer::OUTCOME_NOOP, $items[$uids['bbb222']]['outcome']);
        $this->assertNull($items[$uids['bbb222']]['patch']);
        $blocked = $items['bricks-global-class-unknown1'];
        $this->assertSame(Preparer::OUTCOME_BLOCKED, $blocked['outcome']);
        $this->assertSame(['identity_unmatched:creation_requires_decision'], $blocked['blockers']);
        $this->assertNull($blocked['identity']['storage_key']);
        $service = $items[$service_uid];
        $this->assertSame(Preparer::OUTCOME_READY, $service['outcome']);
        $this->assertSame(['storage_key' => (string) $post_id, 'source' => 'vf_object_uid'], $service['identity']);
        $this->assertSame('post_type:service#' . $post_id, $service['container']);
        $this->assertSame(['post_title'], array_column($service['patch']['changed_paths'], 'path'));
        $this->assertGreaterThan(strtotime($receipt['prepared_at']), strtotime($receipt['expires_at']));

        // Nothing was written by the dry run.
        $this->assertSame($classes_before, maybe_serialize(get_option(self::CLASSES)));
        $this->assertSame($post_before, get_post($post_id)->post_title);
        $this->assertSame($local_object['semantic_hash'], (new ObjectStore())->get('bricks.global_class', $uids['aaa111'], 'bricks-global-class-v1')['semantic_hash']);
        $this->assertNull((new InstanceIdentity())->find_by_instance_uid('bricks.global_class', 'bricks-global-class-unknown1'), 'Prepare never assigns identity.');

        // Hub side: stored once, digest matches, annotated with the hub's own projections of the target.
        $hub_row = (new HubPreparationStore())->find($requested['operation_id']);
        $this->assertSame(HubPreparationStore::STATE_RECEIVED, $hub_row['state']);
        $this->assertSame('partial', $hub_row['outcome']);
        $this->assertSame([2, 1, 1], [$hub_row['items_ready'], $hub_row['items_noop'], $hub_row['items_blocked']]);
        $this->assertSame($stored['receipt_digest'], $hub_row['receipt_digest']);
        $this->assertSame($receipt, $hub_row['receipt']);
        $notes = [];
        foreach ($hub_row['hub_notes']['items'] as $note) {
            $notes[$note['instance_uid']] = $note;
        }
        $this->assertTrue($notes[$uids['aaa111']]['target_projection_agrees'], 'The target reported the same hash the hub last received from it.');
        $this->assertNull($notes['bricks-global-class-unknown1']['hub_target_hash']);
        $this->assertSame(0, $hub_row['hub_notes']['items_disagreeing_with_hub_projection']);
        $this->assertNotEmpty($hub_row['expires_at']);

        // A second receipt for the same operation, a foreign principal, and a tampered digest are all refused.
        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/prepare-receipts', ['receipt' => $receipt]);
        $this->assertSame(404, $response->get_status(), 'The source is not the target.');
        $again = $preparations->request($release_uid, $local['environment_id']);
        $this->assertIsArray($again, 'A completed preparation allows a fresh request.');
        $forged = $receipt;
        $forged['operation_id'] = $again['operation_id'];
        $forged['release_digest'] = str_repeat('0', 64);
        $error = $preparations->accept_receipt((new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->find($local['environment_id']), $release_uid, $forged);
        $this->assertSame('dbvc_agency_invalid_receipt', $error->get_error_code());
        $this->assertContains('release_digest', $error->get_error_data()['errors']);
        $this->assertSame(1, $this->run_release()['receipts_stored'], 'The fresh request is prepared and reported.');
        $this->assertSame(HubPreparationStore::STATE_RECEIVED, (new HubPreparationStore())->find($again['operation_id'])['state']);

        // An operator lineage link resolves a source UID the target does not share: the unknown class becomes ready.
        $link = (new \Dbvc\AgencyControl\Comparison\BaselineService())->link(['domain' => 'bricks.global_class', 'source_environment_id' => 'studio-src', 'source_instance_uid' => 'bricks-global-class-unknown1', 'target_environment_id' => $local['environment_id'], 'target_instance_uid' => $uids['ccc333']]);
        $this->assertSame('created', $link['result']);
        $linked = $preparations->request($release_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $linked_receipt = $local_store->find($linked['operation_id'])['receipt'];
        $this->assertSame(['ready' => 3, 'noop' => 1, 'blocked' => 0], $linked_receipt['counts']);
        $this->assertSame('ready', $linked_receipt['outcome']);
        foreach ($linked_receipt['items'] as $item) {
            if ($item['instance_uid'] === 'bricks-global-class-unknown1') {
                $this->assertSame($uids['ccc333'], $item['target_instance_uid']);
                $this->assertSame(['storage_key' => 'ccc333', 'source' => 'sidecar+link'], $item['identity']);
                $this->assertSame(Preparer::OUTCOME_READY, $item['outcome']);
                $this->assertSame(['cat', 'id', 'name', 'settings'], array_column($item['patch']['changed_paths'], 'path'), 'ccc333 has a category the new body lacks: remove; id/name/settings differ: replace.');
            }
        }
        $hub_linked = (new HubPreparationStore())->find($linked['operation_id']);
        foreach ($hub_linked['hub_notes']['items'] as $note) {
            if ($note['instance_uid'] === 'bricks-global-class-unknown1') {
                $this->assertSame($uids['ccc333'], $note['target_instance_uid']);
                $this->assertTrue($note['target_projection_agrees']);
            }
        }
        $this->assertSame($classes_before, maybe_serialize(get_option(self::CLASSES)));

        // Withdrawing the release cancels a later request at retrieval.
        $third = $preparations->request($release_uid, $local['environment_id']);
        (new ReleaseService())->withdraw($release_uid);
        $summary = $this->run_release();
        $this->assertSame(0, $summary['prepare_requests']);
        $this->assertSame(HubPreparationStore::STATE_CANCELLED, (new HubPreparationStore())->find($third['operation_id'])['state']);
        $this->assertSame('release_withdrawn', (new HubPreparationStore())->find($third['operation_id'])['outcome']);
        unset($other_client);
    }

    public function test_dependency_ledger_and_deletions_are_reported_on_the_receipt(): void
    {
        $local = $this->enroll_local();
        $uids = $this->local_uids();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        register_taxonomy('service_area', 'service');
        $parent_id = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Parent service']);
        $parent_uid = (string) get_post_meta($parent_id, 'vf_object_uid', true);
        $child_id = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Child service', 'post_parent' => $parent_id]);
        $child_uid = (string) get_post_meta($child_id, 'vf_object_uid', true);
        wp_insert_term('North', 'service_area', ['slug' => 'north']);
        $this->run_observation();
        $local_aaa = (new ObjectStore())->get('bricks.global_class', $uids['aaa111'], 'bricks-global-class-v1');
        $local_child = (new ObjectStore())->get('wp.service', $child_uid, 'wp-service-v1');

        // After-states: aaa111 gains a category and a colour that uses --primary; the child post gains two terms and an image.
        $after_aaa = json_decode($local_aaa['snapshot_body'], true);
        $after_aaa['category'] = 'rgxlvq';
        $after_aaa['settings']['_background'] = ['color' => ['raw' => 'var(--primary)']];
        $after_child = json_decode($local_child['snapshot_body'], true);
        $after_child['post_content'] = '<img class="wp-image-77" src="https://studio.example/wp-content/uploads/2026/09/hero.png" />';
        $after_child['tax_input'] = ['service_area' => [['slug' => 'north', 'name' => 'North', 'parent' => null], ['slug' => 'south', 'name' => 'South', 'parent' => null]], 'service_kind' => [['slug' => 'roofing', 'name' => 'Roofing', 'parent' => null]]];
        $variable_body = Canonicalizer::encode(['id' => 'var001', 'name' => 'primary', 'type' => 'static', 'value' => '#123456']);
        $bodies = [
            ['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode($after_aaa)],
            ['domain' => 'wp.service', 'instance_uid' => $child_uid, 'profile' => 'wp-service-v1', 'body' => Canonicalizer::encode($after_child)],
        ];
        $this->report_and_seal($remote, $bodies, $release_uid);
        $preparations = new PreparationService();
        $preparations->request($release_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $receipt = $this->latest_local_receipt();
        $items = $this->items_by_uid($receipt);
        $aaa = $items[$uids['aaa111']];
        $this->assertSame(Preparer::OUTCOME_BLOCKED, $aaa['outcome'], 'Unresolved dependencies block the item even though the patch itself is exact.');
        $this->assertSame(['dependency_unresolved:bricks.class_category:rgxlvq', 'dependency_unresolved:bricks.variable:--primary'], $aaa['blockers']);
        $this->assertNotNull($aaa['patch'], 'The exact patch is still reported beside the blockers.');
        $this->assertTrue($aaa['dependencies']['complete']);
        $child = $items[$child_uid];
        $this->assertSame(Preparer::OUTCOME_BLOCKED, $child['outcome']);
        $ledger = [];
        foreach ($child['dependencies']['entries'] as $entry) {
            $ledger[$entry['kind'] . ' ' . $entry['ref']] = $entry['status'];
        }
        // The raw wp-image-77 / upload URL in this hand-crafted body are literal content:
        // media references are portable content-hash tokens (see ConnectedMediaTest), which
        // this body carries none of, so the ledger reports no wp.media dependency here.
        $this->assertSame([
            'wp.post_parent ' . $parent_uid => 'present',
            'wp.term service_area:north' => 'present',
            'wp.term service_area:south' => 'unresolved',
            'wp.taxonomy service_kind' => 'unsupported',
        ], $ledger);
        $this->assertContains('dependency_unresolved:wp.term:service_area:south', $child['blockers']);
        $this->assertSame('blocked', $receipt['outcome']);
        $this->assertSame(['ready' => 0, 'noop' => 0, 'blocked' => 2], $receipt['counts']);

        // The target gains the category and the variable: the class becomes ready with `present` entries.
        update_option('bricks_global_classes_categories', [['id' => 'rgxlvq', 'name' => 'Layout']]);
        update_option('bricks_global_variables', [['id' => 'v1', 'name' => 'primary', 'type' => 'static', 'value' => '#000']]);
        $this->run_observation();
        $preparations->request($release_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $aaa = $this->items_by_uid($this->latest_local_receipt())[$uids['aaa111']];
        $this->assertSame(Preparer::OUTCOME_READY, $aaa['outcome']);
        $this->assertSame(['present', 'present'], array_column($aaa['dependencies']['entries'], 'status'));
        $this->assertSame(['bricks.class_category', 'bricks.variable'], array_column($aaa['dependencies']['entries'], 'kind'));

        // A variable supplied by the same release counts as `selected` even where the target lacks it.
        delete_option('bricks_global_variables');
        $this->run_observation();
        $this->report_and_seal($remote, [$bodies[0], ['domain' => 'bricks.variable', 'instance_uid' => 'bricks-variable-src0001', 'profile' => 'bricks-variable-v1', 'body' => $variable_body]], $second_uid, 10);
        $preparations->request($second_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $items = $this->items_by_uid($this->latest_local_receipt());
        $variable_entry = null;
        foreach ($items[$uids['aaa111']]['dependencies']['entries'] as $entry) {
            if ($entry['kind'] === 'bricks.variable') {
                $variable_entry = $entry;
            }
        }
        $this->assertSame(['status' => 'selected', 'detail' => 'release:bricks-variable-src0001'], ['status' => $variable_entry['status'], 'detail' => $variable_entry['detail']]);
        $this->assertSame(Preparer::OUTCOME_READY, $items[$uids['aaa111']]['outcome']);
        $this->assertSame(Preparer::OUTCOME_BLOCKED, $items['bricks-variable-src0001']['outcome'], 'The variable itself is unknown here: creation needs a decision.');

        // Deletions: the source tombstones aaa111 (shared lineage) → the target's aaa111 is ready for removal; a second run after it is gone → noop; an unknown uid → noop.
        $tombstone = [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode(null), 'operation' => 'delete', 'exists' => false]];
        $this->report_and_seal($remote, array_merge($tombstone, [['domain' => 'bricks.global_class', 'instance_uid' => 'bricks-global-class-never', 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode(null), 'operation' => 'delete', 'exists' => false]]), $deletion_uid, 20);
        $preparations->request($deletion_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $receipt = $this->latest_local_receipt();
        $items = $this->items_by_uid($receipt);
        $this->assertSame(Preparer::OUTCOME_READY, $items[$uids['aaa111']]['outcome']);
        $this->assertSame('remove_object', $items[$uids['aaa111']]['patch']['strategy']);
        $this->assertSame($local_aaa['semantic_hash'], $items[$uids['aaa111']]['before_hash']);
        $this->assertSame('delete', $items[$uids['aaa111']]['operation']);
        $this->assertSame(Preparer::OUTCOME_NOOP, $items['bricks-global-class-never']['outcome']);
        $this->assertSame([], $items['bricks-global-class-never']['blockers']);
        $this->assertSame(['ready' => 1, 'noop' => 1, 'blocked' => 0], $receipt['counts']);
        $this->assertSame(3, count(get_option(self::CLASSES)), 'The dry run removed nothing.');
        $classes = $this->classes_fixture();
        update_option(self::CLASSES, [$classes[1], $classes[2]]);
        $this->run_observation();
        $preparations->request($deletion_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $items = $this->items_by_uid($this->latest_local_receipt());
        $this->assertSame(Preparer::OUTCOME_NOOP, $items[$uids['aaa111']]['outcome'], 'Already absent on the target.');
        $this->assertFalse($items[$uids['aaa111']]['exists']);
    }

    /**
     * A simulated source reports the bodies (replace: present + hash of body; delete: tombstone) and supplies the payloads.
     *
     * @param array<string, mixed>              $remote
     * @param array<int, array<string, mixed>>  $bodies
     * @param string|null                       $release_uid Filled with the sealed release uid.
     * @param int                               $sequence_base
     */
    public function test_media_bytes_travel_from_source_to_target_via_the_hub(): void
    {
        $local = $this->enroll_local();
        $remote = $this->enroll_remote('client-a', 'studio-src');

        $uid = 'svcmedia0001';
        $body = Canonicalizer::encode([
            'post_status' => 'publish', 'post_title' => 'Hero', 'post_name' => 'hero',
            'post_content' => '<img class="wp-image-7" src="https://studio-src.example/wp-content/uploads/2026/09/ok.png" />',
            'post_excerpt' => '', 'post_date_gmt' => '2026-09-19 10:00:00', 'post_parent_uid' => '',
            'menu_order' => 0, 'tax_input' => [], 'meta' => [],
        ]);
        $after_hash = Canonicalizer::hash($body);

        // The source observes the service object so the hub can release it.
        $event = ObservationEvent::build([
            'event_id' => 'obs-' . str_pad('1', 12, '0', STR_PAD_LEFT), 'environment_id' => $remote['environment_id'], 'installation_epoch' => $remote['epoch'],
            'sequence' => 1, 'observed_at' => '2026-09-19T10:00:00Z',
            'object' => ['domain' => 'wp.service', 'instance_uid' => $uid],
            'projection' => ['profile' => 'wp-service-v1', 'hash' => $after_hash, 'exists' => true, 'complete' => true],
        ]);
        $obs = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/observations', ['events' => [$event]]);
        $this->assertSame(200, $obs->get_status(), wp_json_encode($obs->get_data()));

        $created = (new ReleaseService())->create($remote['environment_id'], [['domain' => 'wp.service', 'instance_uid' => $uid]]);
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
        $release_uid = $created['release']['release_uid'];

        // Three media entries: one valid, one whose bytes do not match its hash, one over the per-file cap.
        $ok_bytes = 'valid-image-bytes';
        $ok_hash = 'sha256:' . hash('sha256', $ok_bytes);
        $ok = ['source_id' => 7, 'hash' => $ok_hash, 'mime' => 'image/png', 'filename' => 'ok.png', 'relpath' => '2026/09/ok.png', 'filesize' => strlen($ok_bytes), 'url' => 'https://studio-src.example/wp-content/uploads/2026/09/ok.png', 'title' => 'OK', 'alt' => 'a', 'caption' => 'c', 'refs' => ['attachment:7', 'upload:ok.png'], 'bytes' => base64_encode($ok_bytes)];
        $mismatch = ['source_id' => 8, 'hash' => 'sha256:' . hash('sha256', 'declared'), 'refs' => ['attachment:8'], 'bytes' => base64_encode('different')];
        $oversize_bytes = str_repeat('x', Protocol::MAX_MEDIA_FILE_BYTES + 1);
        $oversize = ['source_id' => 9, 'hash' => 'sha256:' . hash('sha256', $oversize_bytes), 'refs' => ['attachment:9'], 'bytes' => base64_encode($oversize_bytes)];

        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => [
            ['domain' => 'wp.service', 'instance_uid' => $uid, 'profile' => 'wp-service-v1', 'body' => $body, 'media' => [$ok, $mismatch, $oversize]],
        ]]);
        $data = $response->get_data();
        $this->assertSame(200, $response->get_status(), wp_json_encode($data));
        $this->assertTrue($data['sealed'], wp_json_encode($data));
        $this->assertSame(['stored' => 1, 'deferred' => 2], $data['outcomes'][0]['media'], 'The hub re-hashes and size-caps every entry; only the valid one is stored.');

        // The target's prepare request now carries the stored bytes out-of-body.
        (new PreparationService())->request($release_uid, $local['environment_id']);
        $target = (new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->find($local['environment_id']);
        $requests = (new PreparationService())->pending_for_target($target);
        $this->assertNotEmpty($requests);
        $items = $requests[0]['release']['items'];
        $this->assertCount(1, $items);
        $media = $items[0]['media'];
        $this->assertCount(1, $media, 'Only the valid entry is delivered; the mismatch and oversize were deferred.');
        $this->assertSame($ok_hash, $media[0]['hash']);
        $this->assertSame($ok_bytes, base64_decode($media[0]['bytes']));
        $this->assertSame(['attachment:7', 'upload:ok.png'], $media[0]['refs']);
        // The body itself is unchanged: media rides as a sibling, never inside the hash-locked body.
        $this->assertSame($body, $items[0]['body']);
        $this->assertSame($after_hash, Canonicalizer::hash($items[0]['body']));
    }

    private function report_and_seal(array $remote, array $bodies, &$release_uid, int $sequence_base = 0): void
    {
        $events = [];
        foreach ($bodies as $index => $body) {
            $events[] = ObservationEvent::build([
                'event_id' => 'obs-' . str_pad((string) ($sequence_base + $index + 1), 12, '0', STR_PAD_LEFT), 'environment_id' => $remote['environment_id'], 'installation_epoch' => $remote['epoch'], 'sequence' => $sequence_base + $index + 1, 'observed_at' => '2026-09-19T10:00:00Z',
                'object' => ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid']],
                'projection' => ['profile' => $body['profile'], 'hash' => Canonicalizer::hash($body['body']), 'exists' => $body['exists'] ?? true, 'complete' => true],
            ]);
        }
        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/observations', ['events' => $events]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(count($events), $response->get_data()['counts']['accepted'], wp_json_encode($response->get_data()['outcomes']));
        $created = (new ReleaseService())->create($remote['environment_id'], array_map(static function ($body) {
            return ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid'], 'operation' => $body['operation'] ?? 'replace'];
        }, $bodies));
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
        $release_uid = $created['release']['release_uid'];
        $payloads = array_map(static function ($body) {
            return ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid'], 'profile' => $body['profile'], 'body' => $body['body']];
        }, $bodies);
        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => $payloads]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertTrue($response->get_data()['sealed'], wp_json_encode($response->get_data()));
    }

    /**
     * @return array<string, mixed>
     */
    private function latest_local_receipt(): array
    {
        $rows = (new PreparationStore())->all(1);
        $this->assertNotEmpty($rows);

        return $rows[0]['receipt'];
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array<string, array<string, mixed>>
     */
    private function items_by_uid(array $receipt): array
    {
        $items = [];
        foreach ($receipt['items'] as $item) {
            $items[$item['instance_uid']] = $item;
        }

        return $items;
    }

    /**
     * @return array{environment_id: string, epoch: string}
     */
    private function enroll_local(): array
    {
        $invite = (new HubEnrollment())->invite(['client_id' => 'client-a', 'environment_id' => 'a-stage']);
        $result = (new EnrollmentService())->exchange(self::HUB, $invite['token']);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->run_observation();
        $delivery = DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);
        $this->assertNull($delivery['blocked'], wp_json_encode($delivery));

        return ['environment_id' => $result['environment_id'], 'epoch' => $result['installation_epoch']];
    }

    /**
     * A second environment enrolled directly against the hub (no local connector).
     *
     * @return array<string, mixed>
     */
    private function enroll_remote(string $client_id, string $environment_id): array
    {
        $invite = (new HubEnrollment())->invite(['client_id' => $client_id, 'environment_id' => $environment_id]);
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/enrollments/exchange');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['token' => $invite['token'], 'environment_id' => $environment_id, 'site_url' => 'https://' . $environment_id . '.example/', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]));
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();

        return ['environment_id' => $data['environment_id'], 'epoch' => $data['installation_epoch'], 'user_id' => get_user_by('login', $data['principal']['username'])->ID, 'uuid' => $data['principal']['uuid']];
    }

    /**
     * @param array<string, mixed> $env
     * @param array<string, mixed> $body
     */
    private function as_environment(array $env, string $method, string $route, array $body): WP_REST_Response
    {
        wp_set_current_user($env['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $env['uuid'];
        $request = new WP_REST_Request($method, $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        $response = rest_do_request($request);
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;

        return $response;
    }

    /**
     * @return array<string, string> storage key => instance uid.
     */
    private function local_uids(): array
    {
        $map = [];
        foreach ((new InstanceIdentity())->all_for_domain('bricks.global_class') as $storage_key => $row) {
            $map[$storage_key] = $row['instance_uid'];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function run_observation(): array
    {
        return DBVC_Connected_Environments_Addon::runtime()->worker()->run(['context' => 'test', 'budget_seconds' => 30]);
    }

    /**
     * @return array<string, mixed>
     */
    private function run_release(): array
    {
        return DBVC_Connected_Environments_Addon::runtime()->release()->run(['context' => 'test']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function classes_fixture(): array
    {
        return [
            ['id' => 'aaa111', 'name' => '--flex-col', 'settings' => ['_direction' => 'column'], 'cat' => false],
            ['id' => 'bbb222', 'name' => '--flex-row', 'settings' => ['_direction' => 'row', '_display' => 'flex']],
            ['id' => 'ccc333', 'name' => '--bg-gradient-light', 'settings' => ['_gradient' => ['colors' => [['id' => 'vypvsh', 'stop' => '0'], ['id' => 'wjtzex', 'stop' => '100']]]], 'cat' => 'rgxlvq'],
        ];
    }
}
