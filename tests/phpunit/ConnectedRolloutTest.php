<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService as HubEnrollment;
use Dbvc\AgencyControl\Release\ApprovalService;
use Dbvc\AgencyControl\Release\PreparationService;
use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Release\RolloutService;
use Dbvc\AgencyControl\Storage\ApprovalStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\PreparationStore as HubPreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\RolloutStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M6: fleet rollout (canary/cohort scheduling), hub side. A sealed release is
 * staged across ordered cohorts of enrolled targets — canary first. The
 * orchestrator drives each target through the ordinary single-environment
 * prepare -> approve -> execute path; a cohort opens the next only once every
 * one of its targets verified, and one failure pauses the rollout so later
 * cohorts never start. Targets are enrolled directly on the hub and their two
 * asynchronous responses (the prepare receipt and the execution receipt) are
 * delivered through the same store and service the connector would use, so the
 * cohort gate is exercised for real without a second connector install.
 *
 * @group connected-environments
 */
class ConnectedRolloutTest extends WP_UnitTestCase
{
    /** @var int Monotonic observation sequence so repeated seals never collide. */
    private $sequence = 0;

    /** @var array<int, array<string, mixed>> Captured outbound webhook calls. */
    private $http_calls = [];

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
    }

    public function tear_down(): void
    {
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_a_canary_gates_the_next_cohort_and_a_healthy_fleet_completes(): void
    {
        $source = $this->enroll_remote('client-a', 'studio-src');
        $canary = $this->enroll_remote('client-a', 'prod-canary');
        $prod_a = $this->enroll_remote('client-a', 'prod-a');
        $prod_b = $this->enroll_remote('client-a', 'prod-b');
        $release = $this->seal_release($source, 'studio fleet release');
        $service = new RolloutService();

        $status = $service->create($release['release_uid'], [['prod-canary'], ['prod-a', 'prod-b']], 'canary then the rest');
        $this->assertIsArray($status, is_wp_error($status) ? $status->get_error_message() : '');
        $this->assertSame(RolloutStore::STATE_RUNNING, $status['rollout']['state']);
        $this->assertSame(0, $status['rollout']['current_cohort']);
        $this->assertSame(2, $status['rollout']['cohort_count']);
        $this->assertSame(3, $status['rollout']['target_count']);
        $uid = $status['rollout']['rollout_uid'];
        $this->assertSame(RolloutStore::TARGET_PENDING, $this->target_state($uid, 'prod-canary'));

        // The canary prepares and is approved, but until it has actually executed the next cohort stays shut.
        $service->advance($uid);
        $this->assertSame(RolloutStore::TARGET_PREPARING, $this->target_state($uid, 'prod-canary'));
        $this->assertSame(RolloutStore::TARGET_PENDING, $this->target_state($uid, 'prod-a'));
        $this->deliver_prepare($uid, 'prod-canary');
        $service->advance($uid);
        $this->assertSame(RolloutStore::TARGET_APPROVED, $this->target_state($uid, 'prod-canary'));
        $paused_check = $service->advance($uid);
        $this->assertSame(0, $paused_check['rollout']['current_cohort'], 'An approved-but-unexecuted canary does not open the next cohort.');
        $this->assertSame(RolloutStore::TARGET_PENDING, $this->target_state($uid, 'prod-a'));

        // Canary verifies: the next cohort opens and both of its targets begin preparing in the same pass.
        $this->deliver_execution($uid, $canary, $release['release_uid'], 'applied');
        $after = $service->advance($uid);
        $this->assertSame(RolloutStore::STATE_RUNNING, $after['rollout']['state']);
        $this->assertSame(1, $after['rollout']['current_cohort']);
        $this->assertSame(RolloutStore::TARGET_SUCCEEDED, $this->target_state($uid, 'prod-canary'));
        $this->assertSame(RolloutStore::TARGET_PREPARING, $this->target_state($uid, 'prod-a'));
        $this->assertSame(RolloutStore::TARGET_PREPARING, $this->target_state($uid, 'prod-b'));

        $this->deliver_prepare($uid, 'prod-a');
        $this->deliver_prepare($uid, 'prod-b');
        $service->advance($uid);
        $this->deliver_execution($uid, $prod_a, $release['release_uid'], 'applied');
        $this->deliver_execution($uid, $prod_b, $release['release_uid'], 'noop');
        $final = $service->advance($uid);

        $this->assertSame(RolloutStore::STATE_COMPLETED, $final['rollout']['state']);
        $this->assertSame(RolloutStore::TARGET_SUCCEEDED, $this->target_state($uid, 'prod-a'));
        $this->assertSame('noop', $this->target_row($uid, 'prod-b')['outcome']);
        // A completed rollout is inert: advancing it changes nothing.
        $this->assertSame(RolloutStore::STATE_COMPLETED, $service->advance($uid)['rollout']['state']);
    }

    public function test_a_canary_failure_pauses_later_cohorts_until_the_target_is_retried(): void
    {
        $source = $this->enroll_remote('client-a', 'studio-src');
        $canary = $this->enroll_remote('client-a', 'prod-canary');
        $this->enroll_remote('client-a', 'prod-a');
        $release = $this->seal_release($source);
        $service = new RolloutService();
        $uid = $service->create($release['release_uid'], [['prod-canary'], ['prod-a']])['rollout']['rollout_uid'];

        $service->advance($uid);
        $this->deliver_prepare($uid, 'prod-canary');
        $service->advance($uid);
        $this->deliver_execution($uid, $canary, $release['release_uid'], 'stale');
        $paused = $service->advance($uid);

        $this->assertSame(RolloutStore::STATE_PAUSED, $paused['rollout']['state']);
        $this->assertSame('cohort_0_failed', $paused['rollout']['paused_reason']);
        $this->assertSame(RolloutStore::TARGET_FAILED, $this->target_state($uid, 'prod-canary'));
        $this->assertSame('stale', $this->target_row($uid, 'prod-canary')['outcome']);
        $this->assertSame(0, $paused['rollout']['current_cohort']);
        $this->assertSame(RolloutStore::TARGET_PENDING, $this->target_state($uid, 'prod-a'), 'The later cohort never started.');

        // Resume alone cannot blow past the failure: the next advance re-pauses.
        $this->assertSame(RolloutStore::STATE_PAUSED, $service->resume($uid)['rollout']['state']);

        // Retry the failed target (rollout stays paused), then resume to re-drive it.
        $retried = $service->retry($uid, 'prod-canary');
        $this->assertSame(RolloutStore::STATE_PAUSED, $retried['rollout']['state']);
        $this->assertSame(RolloutStore::TARGET_PENDING, $this->target_state($uid, 'prod-canary'));
        $this->assertSame(RolloutStore::STATE_RUNNING, $service->resume($uid)['rollout']['state']);
        $this->assertSame(RolloutStore::TARGET_PREPARING, $this->target_state($uid, 'prod-canary'));

        $this->deliver_prepare($uid, 'prod-canary');
        $service->advance($uid);
        $this->deliver_execution($uid, $canary, $release['release_uid'], 'applied');
        $opened = $service->advance($uid);
        $this->assertSame(1, $opened['rollout']['current_cohort']);

        $prod_a = (new EnvironmentRegistry())->find('prod-a');
        $this->deliver_prepare($uid, 'prod-a');
        $service->advance($uid);
        $this->deliver_execution($uid, ['environment_id' => 'prod-a', 'epoch' => $prod_a['current_epoch']], $release['release_uid'], 'applied');
        $this->assertSame(RolloutStore::STATE_COMPLETED, $service->advance($uid)['rollout']['state']);
    }

    /**
     * M9 slice 7d follow-up: a plain M6 rollout reaching a terminal state through
     * advance() enqueues a notification (rollout.completed / rollout.paused), the
     * same best-effort emit the framework rollout path has. RUNNING passes do not
     * notify.
     */
    public function test_advance_notifies_when_a_rollout_completes(): void
    {
        $svc = new \Dbvc\AgencyControl\Notifications\NotificationService();
        $svc->save_channel(['type' => 'webhook', 'target' => 'https://93.184.216.34/hook', 'agency_id' => '', 'events' => []]);

        $source = $this->enroll_remote('client-a', 'studio-src');
        $canary = $this->enroll_remote('client-a', 'prod-canary');
        $release = $this->seal_release($source);
        $service = new RolloutService();
        $uid = $service->create($release['release_uid'], [['prod-canary']])['rollout']['rollout_uid'];

        $service->advance($uid); // -> preparing (RUNNING, no notification)
        $this->deliver_prepare($uid, 'prod-canary');
        $service->advance($uid); // -> approved (RUNNING, no notification)
        $this->deliver_execution($uid, $canary, $release['release_uid'], 'applied');
        $this->assertSame(RolloutStore::STATE_COMPLETED, $service->advance($uid)['rollout']['state']);

        $this->http_calls = [];
        add_filter('pre_http_request', [$this, 'capture_http'], 10, 3);
        $flushed = $svc->flush(50);
        remove_filter('pre_http_request', [$this, 'capture_http'], 10);

        $this->assertSame(1, $flushed['sent'], wp_json_encode($flushed));
        $body = json_decode((string) $this->http_calls[0]['args']['body'], true);
        $this->assertSame('rollout.completed', $body['event']);
        $this->assertSame($uid, $body['payload']['rollout_uid']);
        $this->assertSame($release['release_uid'], $body['payload']['release_uid']);
    }

    public function test_advance_notifies_when_a_rollout_pauses(): void
    {
        $svc = new \Dbvc\AgencyControl\Notifications\NotificationService();
        $svc->save_channel(['type' => 'webhook', 'target' => 'https://93.184.216.34/hook', 'agency_id' => '', 'events' => []]);

        $source = $this->enroll_remote('client-a', 'studio-src');
        $canary = $this->enroll_remote('client-a', 'prod-canary');
        $this->enroll_remote('client-a', 'prod-a');
        $release = $this->seal_release($source);
        $service = new RolloutService();
        $uid = $service->create($release['release_uid'], [['prod-canary'], ['prod-a']])['rollout']['rollout_uid'];

        $service->advance($uid);
        $this->deliver_prepare($uid, 'prod-canary');
        $service->advance($uid);
        $this->deliver_execution($uid, $canary, $release['release_uid'], 'stale');
        $this->assertSame(RolloutStore::STATE_PAUSED, $service->advance($uid)['rollout']['state']);

        $this->http_calls = [];
        add_filter('pre_http_request', [$this, 'capture_http'], 10, 3);
        $svc->flush(50);
        remove_filter('pre_http_request', [$this, 'capture_http'], 10);

        $events = array_map(static function ($call) {
            $body = json_decode((string) $call['args']['body'], true);

            return isset($body['event']) ? (string) $body['event'] : '';
        }, $this->http_calls);
        $this->assertContains('rollout.paused', $events, wp_json_encode($events));
    }

    /**
     * @param mixed                $pre
     * @param array<string, mixed> $args
     * @param string               $url
     * @return array<string, mixed>
     */
    public function capture_http($pre, $args, $url)
    {
        unset($pre, $url);
        $this->http_calls[] = ['args' => $args];

        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => 'ok', 'headers' => []];
    }

    public function test_create_validates_the_plan_and_a_rollout_can_be_withdrawn(): void
    {
        $source = $this->enroll_remote('client-a', 'studio-src');
        $this->enroll_remote('client-a', 'prod-canary');
        $foreign = $this->enroll_remote('client-b', 'b-prod');
        $release = $this->seal_release($source);
        $service = new RolloutService();

        $this->assertSame('dbvc_agency_release_not_found', $service->create('rel-missing', [['prod-canary']])->get_error_code());
        $this->assertSame('dbvc_agency_rollout_no_cohorts', $service->create($release['release_uid'], [])->get_error_code());
        $this->assertSame('dbvc_agency_self_release', $service->create($release['release_uid'], [['studio-src']])->get_error_code());
        $this->assertSame('dbvc_agency_scope_mismatch', $service->create($release['release_uid'], [['b-prod']])->get_error_code());
        $this->assertSame('dbvc_agency_rollout_duplicate_target', $service->create($release['release_uid'], [['prod-canary'], ['prod-canary']])->get_error_code());
        $this->assertSame('dbvc_agency_environment_not_found', $service->create($release['release_uid'], [['ghost']])->get_error_code());
        unset($foreign);

        // An open (unsealed) release is refused.
        $open = $this->create_open_release($source);
        $this->assertSame('dbvc_agency_release_not_sealed', $service->create($open, [['prod-canary']])->get_error_code());

        $uid = $service->create($release['release_uid'], [['prod-canary']])['rollout']['rollout_uid'];
        $withdrawn = $service->withdraw($uid);
        $this->assertSame(RolloutStore::STATE_WITHDRAWN, $withdrawn['rollout']['state']);
        // A withdrawn rollout is inert and cannot be paused or withdrawn again.
        $this->assertSame(RolloutStore::STATE_WITHDRAWN, $service->advance($uid)['rollout']['state']);
        $this->assertSame('dbvc_agency_rollout_not_running', $service->pause($uid)->get_error_code());
        $this->assertSame('dbvc_agency_rollout_closed', $service->withdraw($uid)->get_error_code());
    }

    public function test_retention_prunes_only_old_finished_rollouts(): void
    {
        $source = $this->enroll_remote('client-a', 'studio-src');
        $canary = $this->enroll_remote('client-a', 'prod-canary');
        $release = $this->seal_release($source);
        $service = new RolloutService();

        // A completed rollout (drive the single canary through to done).
        $completed = $service->create($release['release_uid'], [['prod-canary']])['rollout']['rollout_uid'];
        $service->advance($completed);
        $this->deliver_prepare($completed, 'prod-canary');
        $service->advance($completed);
        $this->deliver_execution($completed, $canary, $release['release_uid'], 'applied');
        $this->assertSame(RolloutStore::STATE_COMPLETED, $service->advance($completed)['rollout']['state']);

        // A withdrawn rollout, an untouched recent withdrawn one, and a running one.
        $withdrawn_old = $service->create($release['release_uid'], [['prod-canary']])['rollout']['rollout_uid'];
        $service->withdraw($withdrawn_old);
        $withdrawn_recent = $service->create($release['release_uid'], [['prod-canary']])['rollout']['rollout_uid'];
        $service->withdraw($withdrawn_recent);
        $running = $service->create($release['release_uid'], [['prod-canary']])['rollout']['rollout_uid'];

        // Age the completed and one withdrawn rollout well past the window; leave the rest current.
        $this->age_rollout($completed, '2020-01-01 00:00:00');
        $this->age_rollout($withdrawn_old, '2020-01-01 00:00:00');

        // Dry run counts the two aged finished rollouts and deletes nothing.
        $dry = $service->prune(30, true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(2, $dry['prunable']);
        $this->assertSame(0, $dry['pruned']);
        $this->assertNotNull((new RolloutStore())->find($completed));

        // A running rollout is never prunable even when aged.
        $this->age_rollout($running, '2020-01-01 00:00:00');
        $this->assertSame(2, $service->prune(30, true)['prunable'], 'The aged running rollout is not counted.');
        $this->reset_age($running);

        // The real prune removes the two aged finished rollouts and their target rows only.
        $store = new RolloutStore();
        $completed_id = $store->find($completed)['rollout_id'];
        $result = $service->prune(30);
        $this->assertFalse($result['dry_run']);
        $this->assertSame(2, $result['pruned']);
        $this->assertNull($store->find($completed));
        $this->assertNull($store->find($withdrawn_old));
        $this->assertSame([], $store->targets($completed_id), 'The pruned rollout keeps no target rows.');
        $this->assertNotNull($store->find($withdrawn_recent), 'A recent finished rollout is kept.');
        $this->assertSame(RolloutStore::STATE_RUNNING, $store->find($running)['state'], 'The running rollout is kept.');
        $this->assertSame(2, $store->counts()['total']);
    }

    // --- Harness ---------------------------------------------------------

    private function age_rollout(string $rollout_uid, string $updated_at_gmt): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . HubSchema::table('rollouts') . ' SET updated_at = %s WHERE rollout_uid = %s',
            $updated_at_gmt,
            $rollout_uid
        ));
    }

    private function reset_age(string $rollout_uid): void
    {
        $this->age_rollout($rollout_uid, current_time('mysql', true));
    }

    /**
     * @return array<string, mixed> environment_id, epoch, user_id, uuid
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
     * @return array<int, array<string, string>>
     */
    private function release_bodies(): array
    {
        return [
            ['domain' => 'bricks.global_class', 'instance_uid' => 'bricks-global-class-r1', 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode(['id' => 'r1', 'name' => '--rollout', 'settings' => ['_display' => 'flex']])],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, string> release_uid, digest
     */
    private function seal_release(array $source, string $note = ''): array
    {
        $bodies = $this->release_bodies();
        $release_uid = $this->create_open_release($source, $bodies, $note);
        $response = $this->as_environment($source, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => $bodies]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertTrue($response->get_data()['sealed'], wp_json_encode($response->get_data()));

        return ['release_uid' => $release_uid, 'digest' => $response->get_data()['digest']];
    }

    /**
     * Report observations and create a release, but do not supply payloads (stays open).
     *
     * @param array<string, mixed>                   $source
     * @param array<int, array<string, string>>|null $bodies
     * @return string release_uid
     */
    private function create_open_release(array $source, ?array $bodies = null, string $note = ''): string
    {
        $bodies = $bodies ?? $this->release_bodies();
        $events = [];
        foreach ($bodies as $body) {
            $sequence = ++$this->sequence;
            $events[] = ObservationEvent::build([
                'event_id' => 'obs-' . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT), 'environment_id' => $source['environment_id'], 'installation_epoch' => $source['epoch'], 'sequence' => $sequence, 'observed_at' => '2026-09-19T10:00:00Z',
                'object' => ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid']],
                'projection' => ['profile' => $body['profile'], 'hash' => Canonicalizer::hash($body['body']), 'exists' => true, 'complete' => true],
            ]);
        }
        $response = $this->as_environment($source, 'POST', '/dbvc-agency/v1/observations', ['events' => $events]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(count($events), $response->get_data()['counts']['accepted'], wp_json_encode($response->get_data()['outcomes']));
        $created = (new ReleaseService())->create($source['environment_id'], array_map(static function ($body) {
            return ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid']];
        }, $bodies), $note);
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');

        return $created['release']['release_uid'];
    }

    /**
     * The target posts its prepare receipt: every item ready, agreeing with the hub.
     */
    private function deliver_prepare(string $rollout_uid, string $environment_id): void
    {
        $target = $this->target_row($rollout_uid, $environment_id);
        $this->assertNotSame('', $target['operation_id'], 'A prepare was requested for ' . $environment_id . '.');
        $preparation = (new HubPreparationStore())->find($target['operation_id']);
        $this->assertIsArray($preparation);
        $receipt = [
            'operation_id' => $target['operation_id'],
            'outcome' => 'ready',
            'expires_at' => gmdate('c', time() + 3600),
            'counts' => ['ready' => 1, 'noop' => 0, 'blocked' => 0],
            'items' => [],
        ];
        $stored = (new HubPreparationStore())->record_receipt($preparation['preparation_id'], $receipt, str_repeat('a', 64), ['items_disagreeing_with_hub_projection' => 0]);
        $this->assertTrue($stored, 'The prepare receipt was recorded.');
    }

    /**
     * The target posts its execution receipt with the given overall outcome.
     *
     * @param array<string, mixed> $env environment_id + epoch
     */
    private function deliver_execution(string $rollout_uid, array $env, string $release_uid, string $outcome): void
    {
        $target = $this->target_row($rollout_uid, $env['environment_id']);
        $this->assertNotSame('', $target['approval_uid'], 'An approval exists for ' . $env['environment_id'] . '.');
        $approval = (new ApprovalStore())->find($target['approval_uid']);
        $this->assertIsArray($approval);
        $receipt = [
            'approval_uid' => $target['approval_uid'],
            'operation_id' => $approval['operation_id'],
            'release_digest' => $approval['release_digest'],
            'receipt_digest' => $approval['receipt_digest'],
            'target' => ['environment_id' => $env['environment_id'], 'epoch' => $env['epoch']],
            'outcome' => $outcome,
            'items' => [],
        ];
        $result = (new ApprovalService())->accept_execution((new EnvironmentRegistry())->find($env['environment_id']), $release_uid, $receipt);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function target_row(string $rollout_uid, string $environment_id): array
    {
        $store = new RolloutStore();
        $rollout = $store->find($rollout_uid);
        $this->assertIsArray($rollout);
        foreach ($store->targets($rollout['rollout_id']) as $target) {
            if ($target['target_environment_id'] === $environment_id) {
                return $target;
            }
        }
        $this->fail('No rollout target for ' . $environment_id . '.');
    }

    private function target_state(string $rollout_uid, string $environment_id): string
    {
        return (string) $this->target_row($rollout_uid, $environment_id)['state'];
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
}
