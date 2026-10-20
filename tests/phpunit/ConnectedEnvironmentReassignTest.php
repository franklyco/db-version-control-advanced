<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Parity\SyncPolicyService;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * The hub "Reassign client" action: move an environment into another client's
 * fleet so the two can compare and sync. A pair only pairs when it shares the
 * same agency AND client, so this is the operator fix for an environment that
 * was enrolled under the wrong client.
 *
 * @group connected-environments
 */
class ConnectedEnvironmentReassignTest extends WP_UnitTestCase
{
    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $this->environments = new EnvironmentRegistry();
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

    private function seed(string $agency, string $client, string $id): void
    {
        $this->environments->create([
            'agency_id' => $agency,
            'client_id' => $client,
            'environment_id' => $id,
            'label' => $id,
            'current_epoch' => 'epoch-' . $id,
            'principal_user_id' => 0,
            'principal_uuid' => 'uuid-' . $id,
            'site_url' => 'https://' . $id . '.example/',
        ]);
    }

    public function test_reassign_moves_the_client_and_makes_the_pair_syncable(): void
    {
        $this->seed('studio', 'client-a', 'src');
        $this->seed('studio', 'client-b', 'tgt');
        $policies = new SyncPolicyService();

        // Different clients: a policy across the pair is refused.
        $before = $policies->set(['source' => 'src', 'target' => 'tgt', 'mode' => 'manual']);
        $this->assertWPError($before);
        $this->assertSame('dbvc_agency_scope_mismatch', $before->get_error_code());

        // Reassign the target into the source's client (agency untouched).
        $result = (new EnrollmentService())->reassign('tgt', 'client-a');
        $this->assertIsArray($result);
        $this->assertSame('client-a', $result['client_id']);
        $this->assertSame('studio', $result['agency_id']);
        $this->assertSame('client-a', $this->environments->find('tgt')['client_id']);

        // Same client now: the policy is accepted.
        $after = $policies->set(['source' => 'src', 'target' => 'tgt', 'mode' => 'manual']);
        $this->assertNotWPError($after);
        $this->assertSame('client-a', $after['client_id']);
    }

    public function test_reassign_keeps_the_agency_unless_one_is_given(): void
    {
        $this->seed('studio', 'client-a', 'e1');
        $service = new EnrollmentService();

        $kept = $service->reassign('e1', 'client-z');
        $this->assertSame('studio', $kept['agency_id']);
        $this->assertSame('client-z', $kept['client_id']);

        $moved = $service->reassign('e1', 'client-z', 'agency-2');
        $this->assertSame('agency-2', $moved['agency_id']);
        $this->assertSame('agency-2', $this->environments->find('e1')['agency_id']);
    }

    public function test_reassign_validates_client_environment_and_status(): void
    {
        $this->seed('studio', 'client-a', 'ok');
        $service = new EnrollmentService();

        $unknown = $service->reassign('nope', 'client-a');
        $this->assertWPError($unknown);
        $this->assertSame('dbvc_agency_environment_not_found', $unknown->get_error_code());

        $bad = $service->reassign('ok', 'not a valid id!');
        $this->assertWPError($bad);
        $this->assertSame('dbvc_agency_invalid_client_id', $bad->get_error_code());

        $empty = $service->reassign('ok', '');
        $this->assertWPError($empty);
        $this->assertSame('dbvc_agency_invalid_client_id', $empty->get_error_code());

        // A revoked environment must re-enroll, not be reassigned.
        $this->environments->update('ok', ['status' => EnvironmentRegistry::STATUS_REVOKED]);
        $revoked = $service->reassign('ok', 'client-a');
        $this->assertWPError($revoked);
        $this->assertSame('dbvc_agency_environment_revoked', $revoked->get_error_code());
    }
}
