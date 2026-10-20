<?php

use Dbvc\AgencyControl\Comparison\BaselineService;
use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Comparison\InstanceLinkProposer;
use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Storage\InstanceLinkStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M9 slice 0: cross-site object identity auto-linker. Two environments whose
 * framework objects carry the same name/token under different site-local
 * instance UIDs get correct link proposals by name; accepting them aligns the
 * objects (and, once a baseline is confirmed, reads synchronized). Nothing is
 * ever linked without an explicit accept, and ambiguous or non-current pairs
 * are never written.
 *
 * @group connected-environments
 */
class ConnectedInstanceLinkerTest extends WP_UnitTestCase
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private $env = [];

    /**
     * @var array<string, int>
     */
    private $sequence = [];

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
        foreach ([['client-a', 'a-prod'], ['client-a', 'a-stage'], ['client-b', 'b-prod']] as $pair) {
            $this->env[$pair[1]] = $this->enroll($pair[0], $pair[1]);
            $this->sequence[$pair[1]] = 0;
        }
        (new SubscriptionService())->subscribe_client('a-prod', 'a-stage', []);
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

    public function test_match_key_rides_the_observation_and_is_optional(): void
    {
        // Present and valid.
        $with = ObservationEvent::build($this->class('cls-1', 'x1', 'fw-button') + $this->envelope('a-prod', 1));
        $this->assertSame([], ObservationEvent::validate($with));
        $this->assertSame('fw-button', $with['object']['match_key']);

        // Absent stays valid under the same schema (backward compatible).
        $without = ObservationEvent::build($this->class('cls-1', 'x1') + $this->envelope('a-prod', 2));
        $this->assertSame([], ObservationEvent::validate($without));
        $this->assertArrayNotHasKey('match_key', $without['object']);
        $this->assertSame(ObservationEvent::SCHEMA_VERSION, $without['schema_version']);

        // An invalid match key is rejected; a name with spaces/UTF-8 is allowed.
        $bad = $without;
        $bad['object']['match_key'] = "has\tcontrol";
        $this->assertContains('invalid:object.match_key', ObservationEvent::validate($bad));
        $bad['object']['match_key'] = str_repeat('a', ObservationEvent::MATCH_KEY_MAX_BYTES + 1);
        $this->assertContains('invalid:object.match_key', ObservationEvent::validate($bad));
        $this->assertTrue(ObservationEvent::isMatchKey('Primary Buttons — brand/2'));
    }

    public function test_proposes_by_name_across_different_local_ids_then_accept_aligns_and_synchronizes(): void
    {
        // Same framework objects, different site-local ids on each environment.
        $this->report('a-prod', [
            $this->class('cls-prod-a', 'shared-1', 'fw-button'),
            $this->variable('var-prod-a', 'shared-2', 'fw-container'),
            $this->order('o1'),
        ]);
        $this->report('a-stage', [
            $this->class('cls-stage-z', 'shared-1', 'fw-button'),
            $this->variable('var-stage-z', 'shared-2', 'fw-container'),
            $this->order('o1'),
        ]);

        $service = new ComparisonService();
        // Before linking the source objects look unrelated to the target.
        $before = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertFalse($before['cls-prod-a']['target_observed']);
        $this->assertFalse($before['var-prod-a']['target_observed']);

        $proposer = new InstanceLinkProposer();
        $proposal = $proposer->propose('a-prod', 'a-stage');
        $this->assertIsArray($proposal);
        $this->assertSame(0, $proposal['already_linked']);
        $this->assertSame(0, $proposal['already_aligned']);
        $this->assertSame(0, $proposal['unmatched_source']);
        $this->assertSame([], $proposal['ambiguous']);
        $byKey = $this->proposals_by_source($proposal);
        $this->assertSame('cls-stage-z', $byKey['cls-prod-a']['target_instance_uid']);
        $this->assertSame('fw-button', $byKey['cls-prod-a']['match_key']);
        $this->assertSame('bricks.global_class', $byKey['cls-prod-a']['domain']);
        $this->assertSame('var-stage-z', $byKey['var-prod-a']['target_instance_uid']);
        $this->assertSame('fw-container', $byKey['var-prod-a']['match_key']);

        // Proposing writes nothing.
        $this->assertSame(0, count((new InstanceLinkStore())->all()));

        // Accept every current proposal.
        $accept = $proposer->accept('a-prod', 'a-stage', 'all');
        $this->assertSame(2, $accept['created']);
        $this->assertSame(2, count((new InstanceLinkStore())->all()));

        // The objects now pair through the link (aligned by name, not id).
        $linked = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('link', $linked['cls-prod-a']['pairing']);
        $this->assertSame('cls-stage-z', $linked['cls-prod-a']['target_instance_uid']);
        $this->assertSame('link', $linked['var-prod-a']['pairing']);
        $this->assertSame('var-stage-z', $linked['var-prod-a']['target_instance_uid']);

        // Confirming the baseline for the now-aligned, equal-hash objects reads synchronized.
        (new BaselineService())->confirm('a-prod', 'a-stage');
        $synced = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('synchronized', $synced['cls-prod-a']['state']);
        $this->assertSame('synchronized', $synced['var-prod-a']['state']);

        // Re-proposing offers nothing new: the pairs are already linked.
        $again = $proposer->propose('a-prod', 'a-stage');
        $this->assertSame([], $again['proposals']);
        $this->assertSame(2, $again['already_linked']);
    }

    public function test_accept_only_writes_current_proposals(): void
    {
        $this->report('a-prod', [$this->class('cls-prod-a', 'shared-1', 'fw-button'), $this->order('o1')]);
        $this->report('a-stage', [$this->class('cls-stage-z', 'shared-1', 'fw-button'), $this->order('o1')]);
        $proposer = new InstanceLinkProposer();

        // A hand-supplied pair that is not a current proposal is rejected, nothing written.
        $forged = $proposer->accept('a-prod', 'a-stage', [[
            'domain' => 'bricks.global_class',
            'source_instance_uid' => 'cls-prod-a',
            'target_instance_uid' => 'does-not-exist',
        ]]);
        $this->assertSame(0, $forged['created']);
        $this->assertSame('not_a_current_proposal', $forged['rejected'][0]['reason']);
        $this->assertSame(0, count((new InstanceLinkStore())->all()));

        // The real pair, supplied explicitly, is accepted.
        $ok = $proposer->accept('a-prod', 'a-stage', [[
            'domain' => 'bricks.global_class',
            'source_instance_uid' => 'cls-prod-a',
            'target_instance_uid' => 'cls-stage-z',
        ]]);
        $this->assertSame(1, $ok['created']);
        $this->assertSame('created', $ok['results'][0]['result']);
        $this->assertSame(1, count((new InstanceLinkStore())->all()));
    }

    public function test_duplicate_names_are_reported_ambiguous_never_proposed(): void
    {
        // Two source classes share a name; a single target class carries it.
        $this->report('a-prod', [
            $this->class('cls-prod-1', 'p1', 'fw-dup'),
            $this->class('cls-prod-2', 'p2', 'fw-dup'),
            $this->order('o1'),
        ]);
        $this->report('a-stage', [$this->class('cls-stage-1', 's1', 'fw-dup'), $this->order('o1')]);

        $proposer = new InstanceLinkProposer();
        $proposal = $proposer->propose('a-prod', 'a-stage', 'bricks.global_class');
        $this->assertSame([], $proposal['proposals'], 'An ambiguous name is never proposed.');
        $this->assertNotEmpty($proposal['ambiguous']);
        $this->assertSame('duplicate_source_match_key', $proposal['ambiguous'][0]['reason']);

        // Accepting all still writes nothing, because there are no proposals.
        $accept = $proposer->accept('a-prod', 'a-stage', 'all', 'bricks.global_class');
        $this->assertSame(0, $accept['created']);
        $this->assertSame(0, count((new InstanceLinkStore())->all()));
    }

    public function test_shared_local_id_needs_no_link_and_scope_is_enforced(): void
    {
        // Same name AND same local id: already aligned, nothing to propose.
        $this->report('a-prod', [$this->class('cls-shared', 'v1', 'fw-x'), $this->order('o1')]);
        $this->report('a-stage', [$this->class('cls-shared', 'v9', 'fw-x'), $this->order('o1')]);
        $proposer = new InstanceLinkProposer();
        $proposal = $proposer->propose('a-prod', 'a-stage', 'bricks.global_class');
        $this->assertSame([], $proposal['proposals']);
        $this->assertSame(1, $proposal['already_aligned']);

        // Cross-client and self are refused (scope reuse from ComparisonService).
        $this->assertSame('dbvc_agency_scope_mismatch', $proposer->propose('a-prod', 'b-prod')->get_error_code());
        $this->assertSame('dbvc_agency_self_comparison', $proposer->propose('a-prod', 'a-prod')->get_error_code());
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, array<string, mixed>>
     */
    private function rows_by_uid(array $result): array
    {
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[$row['source_instance_uid']] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array<string, array<string, mixed>>
     */
    private function proposals_by_source(array $proposal): array
    {
        $rows = [];
        foreach ($proposal['proposals'] as $row) {
            $rows[$row['source_instance_uid']] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function report(string $environment_id, array $events): void
    {
        $env = $this->env[$environment_id];
        $bodies = [];
        foreach ($events as $event) {
            $this->sequence[$environment_id]++;
            $bodies[] = ObservationEvent::build($event + $this->envelope($environment_id, $this->sequence[$environment_id]));
        }
        wp_set_current_user($env['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $env['uuid'];
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['events' => $bodies]));
        $response = rest_do_request($request);
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(count($bodies), $response->get_data()['counts']['accepted'], wp_json_encode($response->get_data()['outcomes']));
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(string $environment_id, int $sequence): array
    {
        $env = $this->env[$environment_id];

        return [
            'event_id' => 'obs-' . $environment_id . '-' . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT),
            'environment_id' => $env['environment_id'],
            'installation_epoch' => $env['epoch'],
            'sequence' => $sequence,
            'observed_at' => '2026-09-27T10:00:00Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function class(string $uid, string $seed, string $match_key = ''): array
    {
        $object = ['domain' => 'bricks.global_class', 'instance_uid' => $uid];
        if ($match_key !== '') {
            $object['match_key'] = $match_key;
        }

        return ['object' => $object, 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function variable(string $uid, string $seed, string $match_key = ''): array
    {
        $object = ['domain' => 'bricks.variable', 'instance_uid' => $uid];
        if ($match_key !== '') {
            $object['match_key'] = $match_key;
        }

        return ['object' => $object, 'projection' => ['profile' => 'bricks-variable-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $seed): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => ComparisonService::ORDER_INSTANCE_UID], 'projection' => ['profile' => 'bricks-collection-order-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => true]];
    }

    private function hash(string $seed): string
    {
        return hash('sha256', 'fixture:' . $seed);
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
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();

        return ['environment_id' => $data['environment_id'], 'epoch' => $data['installation_epoch'], 'user_id' => get_user_by('login', $data['principal']['username'])->ID, 'uuid' => $data['principal']['uuid']];
    }
}
