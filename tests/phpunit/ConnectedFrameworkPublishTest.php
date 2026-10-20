<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Framework\FrameworkPublishService;
use Dbvc\AgencyControl\Framework\FrameworkService;
use Dbvc\AgencyControl\Storage\DefinitionStore;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M9 slice 4b: fleet framework publishing. publish() stamps one version label
 * across a golden source's objects (each an immutable per-object definition
 * version) and marks each desired; plan() previews, read-only, what a rollout
 * of those desired versions would do to each subscribing environment — one
 * action per object per target — honouring approved overrides as skip_override,
 * never overwriting client work, and applying nothing.
 *
 * @group connected-environments
 */
class ConnectedFrameworkPublishTest extends WP_UnitTestCase
{
    private const DOMAIN = 'bricks.global_class';
    private const PROFILE = 'bricks-global-class-v1';
    private const DEF = 'fw-def-a';

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
        foreach (['fp-src', 'fp-a', 'fp-prot', 'fp-new', 'fp-stale', 'fp-sync'] as $environment_id) {
            $this->env[$environment_id] = $this->enroll('fpc', $environment_id);
            $this->sequence[$environment_id] = 0;
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

    public function test_publish_stamps_a_version_per_object_marks_desired_and_is_idempotent(): void
    {
        $hash = hash('sha256', 'frame:v1');
        $this->report('fp-src', 'seed-fp-src', $hash, true);

        $result = (new FrameworkPublishService())->publish([
            'source_environment_id' => 'fp-src',
            'version' => 'v1',
            'desire' => true,
            'objects' => [
                ['definition_uid' => self::DEF, 'domain' => self::DOMAIN, 'instance_uid' => 'seed-fp-src', 'profile' => self::PROFILE],
            ],
        ]);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame(['published' => 1, 'exists' => 0, 'error' => 0, 'desired' => 1], $result['summary']);
        $this->assertSame('published', $result['objects'][0]['status']);
        $this->assertSame($hash, $result['objects'][0]['hash'], 'The definition hash is read from the source projection.');

        $desired = (new DefinitionStore())->desired('studio', self::DEF, 'stable');
        $this->assertNotNull($desired);
        $this->assertSame('v1', $desired['version']);
        $this->assertSame($hash, $desired['definition_hash']);

        // Re-publishing the same version is an idempotent no-op (versions are immutable).
        $again = (new FrameworkPublishService())->publish([
            'source_environment_id' => 'fp-src',
            'version' => 'v1',
            'desire' => true,
            'objects' => [
                ['definition_uid' => self::DEF, 'domain' => self::DOMAIN, 'instance_uid' => 'seed-fp-src', 'profile' => self::PROFILE],
            ],
        ]);
        $this->assertSame(['published' => 0, 'exists' => 1, 'error' => 0, 'desired' => 1], $again['summary']);
        $this->assertSame('v1', (new DefinitionStore())->desired('studio', self::DEF, 'stable')['version']);
    }

    public function test_publish_rejects_a_stale_or_foreign_source(): void
    {
        // fp-stale never reported: publishing from it fails fast, before any object.
        $stale = (new FrameworkPublishService())->publish([
            'source_environment_id' => 'fp-stale',
            'version' => 'v1',
            'objects' => [['definition_uid' => self::DEF, 'domain' => self::DOMAIN, 'instance_uid' => 'x', 'profile' => self::PROFILE]],
        ]);
        $this->assertWPError($stale);
        $this->assertSame('dbvc_agency_environment_stale', $stale->get_error_code());

        $missing = (new FrameworkPublishService())->publish([
            'source_environment_id' => 'not-enrolled',
            'version' => 'v1',
            'objects' => [['definition_uid' => self::DEF, 'domain' => self::DOMAIN, 'instance_uid' => 'x', 'profile' => self::PROFILE]],
        ]);
        $this->assertWPError($missing);
        $this->assertSame('dbvc_agency_environment_not_found', $missing->get_error_code());
    }

    public function test_plan_resolves_subscribers_maps_each_action_and_protects_overrides(): void
    {
        $desired_hash = hash('sha256', 'frame:v1');

        // Publish + desire fw-def-a@v1 directly (explicit hash keeps the plan test
        // independent of the source read exercised in the publish test).
        $published = (new FrameworkService())->publish([
            'definition_uid' => self::DEF,
            'version' => 'v1',
            'domain' => self::DOMAIN,
            'profile' => self::PROFILE,
            'hash' => $desired_hash,
            'desired' => true,
        ]);
        $this->assertIsArray($published, is_wp_error($published) ? $published->get_error_message() : '');

        // Subscribe five targets, each adopting v1, then shape their observed state.
        $subscriptions = new SubscriptionService();
        foreach (['fp-a', 'fp-prot', 'fp-new', 'fp-stale', 'fp-sync'] as $environment_id) {
            $sub = $subscriptions->subscribe_framework($environment_id, self::DOMAIN, 'seed-' . $environment_id, self::DEF, 'v1', 'stable');
            $this->assertIsArray($sub, is_wp_error($sub) ? $sub->get_error_message() : '');
        }

        $this->report('fp-a', 'seed-fp-a', hash('sha256', 'drifted'), true);       // present, differs -> replace
        $this->report('fp-prot', 'seed-fp-prot', hash('sha256', 'clientwork'), true); // present + override -> skip
        $this->report('fp-new', 'seed-fp-new', '', false);                          // absent -> create
        $this->report('fp-sync', 'seed-fp-sync', $desired_hash, true);              // matches desired -> up_to_date
        // fp-stale never reports -> blocked.

        $this->assertTrue((new OverrideStore())->approve([
            'agency_id' => 'studio',
            'client_id' => 'fpc',
            'environment_id' => 'fp-prot',
            'domain' => self::DOMAIN,
            'instance_uid' => 'seed-fp-prot',
            'definition_uid' => self::DEF,
            'approved_hash' => hash('sha256', 'clientwork'),
            'rationale' => 'client customised this class',
        ]));

        $plan = (new FrameworkPublishService())->plan(['definitions' => self::DEF]);
        $this->assertIsArray($plan);

        $by_env = [];
        foreach ($plan['targets'] as $target) {
            $by_env[$target['environment_id']] = $target;
        }
        $this->assertCount(5, $by_env, 'Every subscribing environment appears once.');

        $this->assertSame('replace', $by_env['fp-a']['items'][0]['action']);
        $this->assertSame('replace', $by_env['fp-a']['items'][0]['operation']);
        $this->assertSame('will_update', $by_env['fp-a']['state']);

        $this->assertSame('skip_override', $by_env['fp-prot']['items'][0]['action']);
        $this->assertSame('protected', $by_env['fp-prot']['state']);
        $this->assertArrayNotHasKey('operation', $by_env['fp-prot']['items'][0], 'A protected object carries no rollout operation.');

        $this->assertSame('create', $by_env['fp-new']['items'][0]['action']);
        $this->assertSame('create', $by_env['fp-new']['items'][0]['operation']);
        $this->assertSame('will_update', $by_env['fp-new']['state']);

        $this->assertSame('blocked', $by_env['fp-stale']['items'][0]['action']);
        $this->assertSame('not_reporting', $by_env['fp-stale']['state']);

        $this->assertSame('up_to_date', $by_env['fp-sync']['items'][0]['action']);
        $this->assertSame('up_to_date', $by_env['fp-sync']['state']);

        $this->assertSame(5, $plan['summary']['targets']);
        $this->assertSame(1, $plan['summary']['create']);
        $this->assertSame(1, $plan['summary']['replace']);
        $this->assertSame(1, $plan['summary']['skip_override']);
        $this->assertSame(2, $plan['summary']['will_update']);
        $this->assertSame(1, $plan['summary']['protected']);
        $this->assertSame(1, $plan['summary']['up_to_date']);
        $this->assertSame(1, $plan['summary']['not_reporting']);
    }

    public function test_plan_cohort_filter_restricts_targets(): void
    {
        (new FrameworkService())->publish([
            'definition_uid' => self::DEF,
            'version' => 'v1',
            'domain' => self::DOMAIN,
            'profile' => self::PROFILE,
            'hash' => hash('sha256', 'frame:v1'),
            'desired' => true,
        ]);
        $subscriptions = new SubscriptionService();
        foreach (['fp-a', 'fp-sync'] as $environment_id) {
            $subscriptions->subscribe_framework($environment_id, self::DOMAIN, 'seed-' . $environment_id, self::DEF, 'v1', 'stable');
            $this->report($environment_id, 'seed-' . $environment_id, hash('sha256', 'drifted-' . $environment_id), true);
        }

        $plan = (new FrameworkPublishService())->plan(['definitions' => self::DEF, 'cohorts' => 'fp-a']);
        $ids = array_column($plan['targets'], 'environment_id');
        $this->assertSame(['fp-a'], $ids, 'The cohort filter keeps only the named environments.');
        $this->assertSame(['fp-a'], $plan['cohorts']);
    }

    /**
     * @return void
     */
    private function report(string $environment_id, string $instance_uid, string $hash, bool $exists): void
    {
        $env = $this->env[$environment_id];
        $this->sequence[$environment_id]++;
        $body = ObservationEvent::build([
            'object' => ['domain' => self::DOMAIN, 'instance_uid' => $instance_uid],
            'projection' => ['profile' => self::PROFILE, 'hash' => $exists ? $hash : '', 'exists' => $exists, 'complete' => true],
            'event_id' => 'obs-' . $environment_id . '-' . $this->sequence[$environment_id],
            'environment_id' => $env['environment_id'],
            'installation_epoch' => $env['epoch'],
            'sequence' => $this->sequence[$environment_id],
            'observed_at' => '2026-09-28T10:00:00Z',
        ]);
        wp_set_current_user($env['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $env['uuid'];
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['events' => [$body]]));
        $response = rest_do_request($request);
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
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
