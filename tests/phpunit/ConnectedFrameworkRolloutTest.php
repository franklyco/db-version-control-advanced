<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Framework\FrameworkRolloutScheduler;
use Dbvc\AgencyControl\Framework\FrameworkRolloutService;
use Dbvc\AgencyControl\Framework\FrameworkService;
use Dbvc\AgencyControl\Release\ApprovalService;
use Dbvc\AgencyControl\Storage\ApprovalStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\FrameworkRolloutStore;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\PreparationStore as HubPreparationStore;
use Dbvc\AgencyControl\Storage\RolloutStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M9 slice 4c: framework rollout EXECUTION. Publish a framework version, then
 * roll it out to a same-client fleet — each target rides its OWN override-aware
 * release + single-target rollout through the existing M5/M6 pipeline. A locked
 * target is skipped (never overwritten); an out-of-date target lands the change
 * and converges; an already-current target is a no-op. The connector side is
 * simulated in-process (payload delivery, prepare + execution receipts) exactly
 * as the M6/M8 rollout suites do.
 *
 * @group connected-environments
 */
class ConnectedFrameworkRolloutTest extends WP_UnitTestCase
{
    private const DOMAIN = 'bricks.global_class';
    private const PROFILE = 'bricks-global-class-v1';
    private const DEF = 'fw-def-a';
    private const UID = 'fwc-x';

    /**
     * @var array<string, array<string, mixed>>
     */
    private $env = [];

    /**
     * @var int
     */
    private $sequence = 0;

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
        foreach (['fr-src', 'fr-a', 'fr-skip', 'fr-sync'] as $environment_id) {
            $this->env[$environment_id] = $this->enroll('frc', $environment_id);
        }
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

    public function test_start_and_advance_drives_a_full_per_target_cycle_and_protects_overrides(): void
    {
        $this->seed_fleet();

        $start = (new FrameworkRolloutService())->start(['definitions' => [self::DEF]]);
        $this->assertIsArray($start, is_wp_error($start) ? $start->get_error_message() : '');
        $this->assertSame(FrameworkRolloutStore::STATE_RUNNING, $start['state']);
        $this->assertSame(3, $start['summary']['targets']);
        $campaign_uid = $start['campaign_uid'];

        $driver = new FrameworkRolloutService();

        // Advance 1: fr-a builds its release; fr-skip + fr-sync settle to skipped.
        $status = $driver->advance($campaign_uid);
        $this->assertSame('release_open', $this->target($status, 'fr-a')['state']);
        $this->assertSame(1, $this->target($status, 'fr-a')['item_count']);
        $this->assertSame('protected', $this->target($status, 'fr-skip')['detail'], 'A locked target is skipped as protected.');
        $this->assertSame(FrameworkRolloutStore::TARGET_SKIPPED, $this->target($status, 'fr-skip')['state']);
        $this->assertSame(FrameworkRolloutStore::TARGET_SKIPPED, $this->target($status, 'fr-sync')['state']);
        $this->assertSame('up_to_date', $this->target($status, 'fr-sync')['detail']);
        $release_uid = $this->target($status, 'fr-a')['release_uid'];
        $this->assertNotSame('', $release_uid);

        // Advance 2: the source has not sealed yet.
        $status = $driver->advance($campaign_uid);
        $this->assertSame('awaiting_payloads', $this->target($status, 'fr-a')['state']);

        // The golden source delivers the payload → the release seals.
        $this->seal($release_uid, [[
            'domain' => self::DOMAIN,
            'instance_uid' => self::UID,
            'profile' => self::PROFILE,
            'body' => $this->klass_body('x2'),
        ]]);

        // Advance 3: sealed → a single-target rollout is created and started.
        $status = $driver->advance($campaign_uid);
        $this->assertSame('rollout_running', $this->target($status, 'fr-a')['state']);
        $rollout_uid = $this->target($status, 'fr-a')['rollout_uid'];
        $this->assertNotSame('', $rollout_uid);

        // The target dry-runs the release (prepare receipt: ready).
        $this->deliver_prepare($rollout_uid, 'fr-a');
        $status = $driver->advance($campaign_uid);
        $this->assertSame('rollout_running', $this->target($status, 'fr-a')['state']);

        // The target executes the apply (execution receipt: applied + verified).
        $this->deliver_execution($rollout_uid, $this->env['fr-a'], $release_uid, 'applied', [[
            'domain' => self::DOMAIN,
            'instance_uid' => self::UID,
            'target_instance_uid' => self::UID,
            'profile' => self::PROFILE,
            'operation' => 'replace',
            'outcome' => 'applied',
            'verified' => true,
            'before_hash' => Canonicalizer::hash($this->klass_body('x1')),
            'after_hash' => Canonicalizer::hash($this->klass_body('x2')),
        ]]);

        // Advance 4: the rollout completes → the target succeeds → the campaign completes.
        $status = $driver->advance($campaign_uid);
        $this->assertSame(FrameworkRolloutStore::TARGET_SUCCEEDED, $this->target($status, 'fr-a')['state']);
        $this->assertSame(FrameworkRolloutStore::STATE_COMPLETED, $status['state']);
        $this->assertSame(1, $status['summary']['succeeded']);
        $this->assertSame(2, $status['summary']['skipped']);

        // Advancing a terminal campaign is an idempotent no-op.
        $again = $driver->advance($campaign_uid);
        $this->assertSame(FrameworkRolloutStore::STATE_COMPLETED, $again['state']);
    }

    public function test_start_rejects_a_hash_only_definition_and_an_empty_fleet(): void
    {
        // Publish by explicit hash (no source environment) → not rollable.
        (new FrameworkService())->publish([
            'definition_uid' => self::DEF,
            'version' => 'v1',
            'domain' => self::DOMAIN,
            'profile' => self::PROFILE,
            'hash' => hash('sha256', 'frame'),
            'desired' => true,
        ]);
        $hash_only = (new FrameworkRolloutService())->start(['definitions' => [self::DEF]]);
        $this->assertWPError($hash_only);
        $this->assertSame('dbvc_agency_definition_has_no_source', $hash_only->get_error_code());

        // No definitions with a desired version at all.
        $none = (new FrameworkRolloutService())->start(['definitions' => ['fw-missing']]);
        $this->assertWPError($none);
        $this->assertSame('dbvc_agency_no_desired_version', $none->get_error_code());
    }

    public function test_start_enrolls_cross_client_targets_under_agency_scope(): void
    {
        $this->seed_fleet();
        // Reassign fr-sync to another client of the SAME agency: under agency
        // scope (4d) it is now eligible, not deferred.
        (new EnvironmentRegistry())->update('fr-sync', ['client_id' => 'other-client']);

        $start = (new FrameworkRolloutService())->start(['definitions' => [self::DEF], 'targets' => ['fr-a', 'fr-skip', 'fr-sync']]);
        $this->assertIsArray($start, is_wp_error($start) ? $start->get_error_message() : '');
        $this->assertSame(3, $start['summary']['targets'], 'A same-agency target in another client is enrolled under agency scope.');
        $this->assertSame([], $start['skipped_other_agency']);
        $ids = array_map(static function ($t) {
            return $t['target_environment_id'];
        }, $start['targets']);
        $this->assertContains('fr-sync', $ids);
    }

    public function test_start_refuses_a_different_agency_target(): void
    {
        $this->seed_fleet();
        // A target under a DIFFERENT agency is never in scope, even for a fleet push.
        (new EnvironmentRegistry())->update('fr-sync', ['agency_id' => 'other-agency']);

        $start = (new FrameworkRolloutService())->start(['definitions' => [self::DEF], 'targets' => ['fr-a', 'fr-sync']]);
        $this->assertIsArray($start, is_wp_error($start) ? $start->get_error_message() : '');
        $this->assertContains('fr-sync', $start['skipped_other_agency']);
        $ids = array_map(static function ($t) {
            return $t['target_environment_id'];
        }, $start['targets']);
        $this->assertNotContains('fr-sync', $ids);
    }

    public function test_a_cross_client_target_applies_through_the_full_cycle(): void
    {
        $this->seed_fleet();
        // fr-a moves to another client of the same agency: the rollout must still
        // reach it end to end (release → prepare → approve → apply) under agency scope.
        (new EnvironmentRegistry())->update('fr-a', ['client_id' => 'other-client']);

        $driver = new FrameworkRolloutService();
        $start = $driver->start(['definitions' => [self::DEF], 'targets' => ['fr-a']]);
        $this->assertIsArray($start, is_wp_error($start) ? $start->get_error_message() : '');
        $campaign_uid = $start['campaign_uid'];

        $driver->advance($campaign_uid); // pending → release_open
        $status = $driver->advance($campaign_uid); // → awaiting_payloads
        $release_uid = $this->target($status, 'fr-a')['release_uid'];
        $this->assertNotSame('', $release_uid, 'A cross-client target still gets a release built.');

        $this->seal($release_uid, [[
            'domain' => self::DOMAIN,
            'instance_uid' => self::UID,
            'profile' => self::PROFILE,
            'body' => $this->klass_body('x2'),
        ]]);

        $status = $driver->advance($campaign_uid); // sealed → single-target rollout (scope=agency)
        $this->assertSame('rollout_running', $this->target($status, 'fr-a')['state'], 'Cross-client rollout was created under agency scope.');
        $rollout_uid = $this->target($status, 'fr-a')['rollout_uid'];

        $this->deliver_prepare($rollout_uid, 'fr-a'); // prepare allowed cross-client under agency scope
        $driver->advance($campaign_uid);
        $this->deliver_execution($rollout_uid, $this->env['fr-a'], $release_uid, 'applied', [[
            'domain' => self::DOMAIN,
            'instance_uid' => self::UID,
            'target_instance_uid' => self::UID,
            'profile' => self::PROFILE,
            'operation' => 'replace',
            'outcome' => 'applied',
            'verified' => true,
            'before_hash' => Canonicalizer::hash($this->klass_body('x1')),
            'after_hash' => Canonicalizer::hash($this->klass_body('x2')),
        ]]);

        $status = $driver->advance($campaign_uid);
        $this->assertSame(FrameworkRolloutStore::TARGET_SUCCEEDED, $this->target($status, 'fr-a')['state']);
        $this->assertSame(FrameworkRolloutStore::STATE_COMPLETED, $status['state']);
    }

    public function test_staged_cohorts_open_in_order_only_after_the_canary_succeeds(): void
    {
        $this->seed_fleet();
        $driver = new FrameworkRolloutService();
        // Canary = fr-a (out of date → will apply); second cohort = fr-sync (current → skip).
        $start = $driver->start(['definitions' => [self::DEF], 'cohorts' => [['fr-a'], ['fr-sync']]]);
        $this->assertIsArray($start, is_wp_error($start) ? $start->get_error_message() : '');
        $campaign_uid = $start['campaign_uid'];
        $this->assertSame(0, $this->target($start, 'fr-a')['cohort']);
        $this->assertSame(1, $this->target($start, 'fr-sync')['cohort']);

        // First step touches only the canary; the second cohort is held pending.
        $status = $driver->advance($campaign_uid);
        $this->assertSame('release_open', $this->target($status, 'fr-a')['state']);
        $this->assertSame('pending', $this->target($status, 'fr-sync')['state'], 'The second cohort is not opened until the canary succeeds.');

        $this->drive_target_success($driver, $campaign_uid, 'fr-a');

        // With the canary verified, the next cohort opens and settles (fr-sync is current → skipped), completing the campaign.
        $status = (new FrameworkRolloutService())->status($campaign_uid);
        $this->assertSame(FrameworkRolloutStore::TARGET_SUCCEEDED, $this->target($status, 'fr-a')['state']);
        $this->assertSame(FrameworkRolloutStore::TARGET_SKIPPED, $this->target($status, 'fr-sync')['state']);
        $this->assertSame(FrameworkRolloutStore::STATE_COMPLETED, $status['state']);
    }

    public function test_a_failed_canary_pauses_the_fleet_and_holds_later_cohorts(): void
    {
        $this->seed_fleet();
        $driver = new FrameworkRolloutService();
        $start = $driver->start(['definitions' => [self::DEF], 'cohorts' => [['fr-a'], ['fr-sync']]]);
        $campaign_uid = $start['campaign_uid'];

        $driver->advance($campaign_uid); // fr-a → release_open
        $status = $driver->advance($campaign_uid); // → awaiting_payloads
        $release_uid = $this->target($status, 'fr-a')['release_uid'];
        $this->seal($release_uid, [[
            'domain' => self::DOMAIN, 'instance_uid' => self::UID, 'profile' => self::PROFILE, 'body' => $this->klass_body('x2'),
        ]]);
        $status = $driver->advance($campaign_uid); // → rollout_running
        $rollout_uid = $this->target($status, 'fr-a')['rollout_uid'];

        // The target reports the release as BLOCKED → the canary fails.
        $this->deliver_prepare_blocked($rollout_uid, 'fr-a');
        $status = $driver->advance($campaign_uid);

        $this->assertSame(FrameworkRolloutStore::TARGET_FAILED, $this->target($status, 'fr-a')['state']);
        $this->assertSame(FrameworkRolloutStore::STATE_PAUSED, $status['state'], 'A failed canary pauses the whole campaign.');
        $this->assertSame('pending', $this->target($status, 'fr-sync')['state'], 'Later cohorts are never touched while an earlier one has a failure.');

        // The cron scheduler leaves a paused campaign alone.
        $tick = FrameworkRolloutScheduler::tick();
        $this->assertSame(FrameworkRolloutStore::STATE_PAUSED, (new FrameworkRolloutService())->status($campaign_uid)['state']);

        // Retry the failed target, then resume: the campaign runs again and the canary re-enters.
        $retry = $driver->retry($campaign_uid, 'fr-a');
        $this->assertTrue($retry['changed']);
        $status = $driver->resume($campaign_uid);
        $this->assertSame(FrameworkRolloutStore::STATE_RUNNING, $status['state']);
        $this->assertSame('release_open', $this->target($status, 'fr-a')['state']);
        $this->assertSame('pending', $this->target($status, 'fr-sync')['state']);
    }

    public function test_scheduler_tick_advances_a_running_campaign(): void
    {
        $this->seed_fleet();
        $start = (new FrameworkRolloutService())->start(['definitions' => [self::DEF], 'targets' => ['fr-a']]);
        $campaign_uid = $start['campaign_uid'];
        $this->assertSame('pending', $this->target($start, 'fr-a')['state']);

        $tick = FrameworkRolloutScheduler::tick();
        $this->assertGreaterThanOrEqual(1, $tick['ran']);
        $uids = array_column($tick['results'], 'campaign_uid');
        $this->assertContains($campaign_uid, $uids);

        // The tick advanced the campaign one step (fr-a now has a release open).
        $status = (new FrameworkRolloutService())->status($campaign_uid);
        $this->assertSame('release_open', $this->target($status, 'fr-a')['state']);
    }

    /**
     * Drive one active-cohort target from release_open through to SUCCEEDED
     * (seal → rollout → prepare → apply). Assumes the target is in the cohort
     * currently being advanced.
     */
    private function drive_target_success(FrameworkRolloutService $driver, string $campaign_uid, string $env_key): void
    {
        $status = (new FrameworkRolloutService())->status($campaign_uid);
        if ($this->target($status, $env_key)['state'] === 'pending') {
            $status = $driver->advance($campaign_uid); // → release_open
        }
        $status = $driver->advance($campaign_uid); // → awaiting_payloads
        $release_uid = $this->target($status, $env_key)['release_uid'];
        $this->assertNotSame('', $release_uid);
        $this->seal($release_uid, [[
            'domain' => self::DOMAIN, 'instance_uid' => self::UID, 'profile' => self::PROFILE, 'body' => $this->klass_body('x2'),
        ]]);
        $status = $driver->advance($campaign_uid); // → rollout_running
        $rollout_uid = $this->target($status, $env_key)['rollout_uid'];
        $this->deliver_prepare($rollout_uid, $env_key);
        $driver->advance($campaign_uid);
        $this->deliver_execution($rollout_uid, $this->env[$env_key], $release_uid, 'applied', [[
            'domain' => self::DOMAIN,
            'instance_uid' => self::UID,
            'target_instance_uid' => self::UID,
            'profile' => self::PROFILE,
            'operation' => 'replace',
            'outcome' => 'applied',
            'verified' => true,
            'before_hash' => Canonicalizer::hash($this->klass_body('x1')),
            'after_hash' => Canonicalizer::hash($this->klass_body('x2')),
        ]]);
        $driver->advance($campaign_uid);
    }

    private function deliver_prepare_blocked(string $rollout_uid, string $environment_id): void
    {
        $target = $this->rollout_target($rollout_uid, $environment_id);
        $preparation = (new HubPreparationStore())->find($target['operation_id']);
        $this->assertIsArray($preparation);
        $receipt = ['operation_id' => $target['operation_id'], 'outcome' => 'blocked', 'expires_at' => gmdate('c', time() + 3600), 'counts' => ['ready' => 0, 'noop' => 0, 'blocked' => 1], 'items' => []];
        $this->assertTrue((new HubPreparationStore())->record_receipt($preparation['preparation_id'], $receipt, str_repeat('a', 64), ['items_disagreeing_with_hub_projection' => 0]));
    }

    /**
     * Publish fw-def-a@v1 from the golden source, then set up three targets:
     * fr-a (out of date → replace), fr-skip (locked → skip), fr-sync (current → no-op).
     */
    private function seed_fleet(): void
    {
        $this->report('fr-src', $this->klass('x2'));
        $published = (new FrameworkService())->publish([
            'definition_uid' => self::DEF,
            'version' => 'v1',
            'domain' => self::DOMAIN,
            'profile' => self::PROFILE,
            'from_environment_id' => 'fr-src',
            'from_instance_uid' => self::UID,
            'desired' => true,
        ]);
        $this->assertIsArray($published, is_wp_error($published) ? $published->get_error_message() : '');

        $subscriptions = new SubscriptionService();
        foreach (['fr-a', 'fr-skip', 'fr-sync'] as $environment_id) {
            $sub = $subscriptions->subscribe_framework($environment_id, self::DOMAIN, self::UID, self::DEF, 'v1', 'stable');
            $this->assertIsArray($sub, is_wp_error($sub) ? $sub->get_error_message() : '');
        }
        $this->report('fr-a', $this->klass('x1'));    // out of date → replace
        $this->report('fr-skip', $this->klass('x1'));  // out of date, but locked → skip
        $this->report('fr-sync', $this->klass('x2'));  // matches the desired version → no-op

        $this->assertTrue((new OverrideStore())->approve([
            'agency_id' => 'studio',
            'client_id' => 'frc',
            'environment_id' => 'fr-skip',
            'domain' => self::DOMAIN,
            'instance_uid' => self::UID,
            'definition_uid' => self::DEF,
            'approved_hash' => Canonicalizer::hash($this->klass_body('x1')),
            'rationale' => 'client customised this class',
        ]));
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function target(array $status, string $environment_id): array
    {
        foreach ($status['targets'] as $target) {
            if ($target['target_environment_id'] === $environment_id) {
                return $target;
            }
        }
        $this->fail('No campaign target for ' . $environment_id . '.');
    }

    // --- Harness (mirrors ConnectedParityAutoTest) -----------------------

    private function klass_body(string $seed): string
    {
        return Canonicalizer::encode(['id' => 'r-' . $seed, 'name' => '--c', 'settings' => ['_x' => $seed]]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function klass(string $seed): array
    {
        $body = $this->klass_body($seed);

        return [['object' => ['domain' => self::DOMAIN, 'instance_uid' => self::UID], 'projection' => ['profile' => self::PROFILE, 'hash' => Canonicalizer::hash($body), 'exists' => true, 'complete' => true]]];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function report(string $key, array $events): void
    {
        $env = $this->env[$key];
        $bodies = [];
        foreach ($events as $event) {
            $this->sequence++;
            $bodies[] = ObservationEvent::build(array_merge($event, [
                'event_id' => 'obs-' . str_pad((string) $this->sequence, 12, '0', STR_PAD_LEFT),
                'environment_id' => $env['environment_id'],
                'installation_epoch' => $env['epoch'],
                'sequence' => $this->sequence,
                'observed_at' => '2026-09-29T10:00:00Z',
            ]));
        }
        $response = $this->as_environment($env, 'POST', '/dbvc-agency/v1/observations', ['events' => $bodies]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
    }

    /**
     * @param array<int, array<string, string>> $items
     */
    private function seal(string $release_uid, array $items): void
    {
        $response = $this->as_environment($this->env['fr-src'], 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => $items]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertTrue($response->get_data()['sealed'], wp_json_encode($response->get_data()));
    }

    private function deliver_prepare(string $rollout_uid, string $environment_id): void
    {
        $target = $this->rollout_target($rollout_uid, $environment_id);
        $preparation = (new HubPreparationStore())->find($target['operation_id']);
        $this->assertIsArray($preparation);
        $receipt = ['operation_id' => $target['operation_id'], 'outcome' => 'ready', 'expires_at' => gmdate('c', time() + 3600), 'counts' => ['ready' => 1, 'noop' => 0, 'blocked' => 0], 'items' => []];
        $this->assertTrue((new HubPreparationStore())->record_receipt($preparation['preparation_id'], $receipt, str_repeat('a', 64), ['items_disagreeing_with_hub_projection' => 0]));
    }

    /**
     * @param array<string, mixed>             $env
     * @param array<int, array<string, mixed>> $items
     */
    private function deliver_execution(string $rollout_uid, array $env, string $release_uid, string $outcome, array $items): void
    {
        $target = $this->rollout_target($rollout_uid, $env['environment_id']);
        $approval = (new ApprovalStore())->find($target['approval_uid']);
        $this->assertIsArray($approval);
        $receipt = ['approval_uid' => $target['approval_uid'], 'operation_id' => $approval['operation_id'], 'release_digest' => $approval['release_digest'], 'receipt_digest' => $approval['receipt_digest'], 'target' => ['environment_id' => $env['environment_id'], 'epoch' => $env['epoch']], 'outcome' => $outcome, 'items' => $items];
        $result = (new ApprovalService())->accept_execution((new EnvironmentRegistry())->find($env['environment_id']), $release_uid, $receipt);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function rollout_target(string $rollout_uid, string $environment_id): array
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
     * @return array<string, mixed>
     */
    private function enroll(string $client_id, string $environment_id): array
    {
        $invite = (new EnrollmentService())->invite(['client_id' => $client_id, 'environment_label' => $environment_id]);
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/enrollments/exchange');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['token' => $invite['token'], 'environment_id' => $environment_id, 'site_url' => 'https://' . $environment_id . '.example/', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]));
        $data = rest_do_request($request)->get_data();

        return ['environment_id' => $data['environment_id'], 'epoch' => $data['installation_epoch'], 'user_id' => get_user_by('login', $data['principal']['username'])->ID, 'uuid' => $data['principal']['uuid']];
    }
}
