<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Enrollment\SegmentService;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Fleet\FleetService;
use Dbvc\AgencyControl\Framework\FrameworkPublishService;
use Dbvc\AgencyControl\Framework\FrameworkRolloutService;
use Dbvc\AgencyControl\Framework\FrameworkService;
use Dbvc\AgencyControl\Storage\FrameworkRolloutStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M9 slice 5: environment tags / segments. Operators group enrolled sites by a
 * free slug and target a framework plan or rollout, or filter the fleet, BY
 * SEGMENT across clients. Tags live as a JSON list on each environment row.
 *
 * @group connected-environments
 */
class ConnectedSegmentTest extends WP_UnitTestCase
{
    private const DOMAIN = 'bricks.global_class';
    private const PROFILE = 'bricks-global-class-v1';
    private const DEF = 'fw-seg';
    private const UID = 'segc-x';

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
        foreach (['seg-a', 'seg-b', 'seg-c'] as $environment_id) {
            $this->env[$environment_id] = $this->enroll('segc', $environment_id);
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

    public function test_sanitizes_tags_and_supports_set_add_remove(): void
    {
        $service = new SegmentService();
        // Mixed case, an illegal char, a dupe and a blank all normalise down.
        $set = $service->set_tags('seg-a', ['Production', 'dental!!', 'dental', '']);
        $this->assertSame(['production', 'dental'], $set['tags']);

        $add = $service->add_tags('seg-a', ['tier-1', 'production']);
        sort($add['tags']);
        $this->assertSame(['dental', 'production', 'tier-1'], $add['tags']);

        $removed = $service->remove_tags('seg-a', ['Dental']);
        sort($removed['tags']);
        $this->assertSame(['production', 'tier-1'], $removed['tags']);

        $this->assertWPError($service->set_tags('not-enrolled', ['x']));
    }

    public function test_segments_aggregate_and_resolve_across_clients(): void
    {
        $service = new SegmentService();
        $service->set_tags('seg-a', ['prod', 'dental']);
        $service->set_tags('seg-b', ['prod']);
        $service->set_tags('seg-c', ['staging']);

        $segments = $service->segments('studio');
        $by = [];
        foreach ($segments['segments'] as $s) {
            $by[$s['tag']] = $s['environments'];
        }
        $this->assertSame(['dental' => 1, 'prod' => 2, 'staging' => 1], $by);

        $prod = $service->environments_for_segment('prod', 'studio');
        sort($prod);
        $this->assertSame(['seg-a', 'seg-b'], $prod);
        $this->assertSame(['seg-c'], $service->environments_for_segment('staging', 'studio'));
        $this->assertSame([], $service->environments_for_segment('nope', 'studio'));
    }

    public function test_fleet_overview_filters_by_segment_and_carries_tags(): void
    {
        (new SegmentService())->set_tags('seg-a', ['prod']);
        (new SegmentService())->set_tags('seg-b', ['prod']);
        (new SegmentService())->set_tags('seg-c', ['staging']);
        foreach (['seg-a', 'seg-b', 'seg-c'] as $id) {
            $this->report($id);
        }

        $all = (new FleetService())->overview(null, 'segc');
        $this->assertSame(3, $all['summary']['sites']);

        $prod = (new FleetService())->overview(null, 'segc', 'prod');
        $ids = array_column($prod['sites'], 'environment_id');
        sort($ids);
        $this->assertSame(['seg-a', 'seg-b'], $ids, 'The fleet is filtered to the segment.');
        foreach ($prod['sites'] as $site) {
            $this->assertContains('prod', $site['tags'], 'Each site row carries its tags.');
        }
    }

    public function test_plan_and_rollout_target_by_segment(): void
    {
        // seg-b is the golden source carrying the framework object; publish from it
        // so the definition has a real source (a rollout needs one).
        $this->report('seg-b', self::UID);
        $published = (new FrameworkService())->publish([
            'definition_uid' => self::DEF,
            'version' => 'v1',
            'domain' => self::DOMAIN,
            'profile' => self::PROFILE,
            'from_environment_id' => 'seg-b',
            'from_instance_uid' => self::UID,
            'desired' => true,
        ]);
        $this->assertIsArray($published, is_wp_error($published) ? $published->get_error_message() : '');
        $subscriptions = new SubscriptionService();
        foreach (['seg-a', 'seg-c'] as $id) {
            $subscriptions->subscribe_framework($id, self::DOMAIN, self::UID, self::DEF, 'v1', 'stable');
            $this->report($id); // fresh; framework object absent → create
        }
        (new SegmentService())->set_tags('seg-a', ['prod']);
        (new SegmentService())->set_tags('seg-c', ['staging']);

        // Plan for the whole fleet sees both subscribers.
        $planAll = (new FrameworkPublishService())->plan(['definitions' => [self::DEF]]);
        $this->assertSame(2, $planAll['summary']['targets']);

        // Plan restricted to the "prod" segment sees only seg-a.
        $planProd = (new FrameworkPublishService())->plan(['definitions' => [self::DEF], 'segment' => 'prod']);
        $this->assertSame('prod', $planProd['segment']);
        $ids = array_column($planProd['targets'], 'environment_id');
        $this->assertSame(['seg-a'], $ids);

        // A rollout started by segment enrols only that segment's sites.
        $start = (new FrameworkRolloutService())->start(['definitions' => [self::DEF], 'segment' => 'prod']);
        $this->assertIsArray($start, is_wp_error($start) ? $start->get_error_message() : '');
        $this->assertSame(1, $start['summary']['targets']);
        $this->assertSame('seg-a', $start['targets'][0]['target_environment_id']);

        // An empty segment is a clear error, not a silent all-fleet push.
        $empty = (new FrameworkRolloutService())->start(['definitions' => [self::DEF], 'segment' => 'ghost']);
        $this->assertWPError($empty);
        $this->assertSame('dbvc_agency_empty_segment', $empty->get_error_code());
    }

    /**
     * @return void
     */
    private function report(string $environment_id, ?string $uid = null): void
    {
        $env = $this->env[$environment_id];
        $this->sequence++;
        $instance = $uid ?? ('other-' . $environment_id);
        $body = ObservationEvent::build([
            'object' => ['domain' => self::DOMAIN, 'instance_uid' => $instance],
            'projection' => ['profile' => self::PROFILE, 'hash' => Canonicalizer::hash('seg:' . $instance), 'exists' => true, 'complete' => true],
            'event_id' => 'obs-' . $environment_id . '-' . $this->sequence,
            'environment_id' => $env['environment_id'],
            'installation_epoch' => $env['epoch'],
            'sequence' => $this->sequence,
            'observed_at' => '2026-10-01T10:00:00Z',
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
