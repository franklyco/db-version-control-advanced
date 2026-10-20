<?php

use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\Connected\Storage\Schema;

/**
 * Slice A1 of the dedicated Connected Environments page: menu and admin REST
 * routes exist only while a gate is ready, require manage_options, wrap the
 * CLI inspectors unchanged, and never accept a plain request without the
 * capability.
 *
 * @group connected-environments
 */
class ConnectedAdminPageTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_connected_bricks_available', '__return_true');
        add_filter('dbvc_connected_processing_delay', '__return_zero');
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        HubSchema::reset_memo();
        DBVC_Connected_Environments_Addon::save_settings([]);
        DBVC_Agency_Control_Addon::save_settings([]);
    }

    public function tear_down(): void
    {
        wp_set_current_user(0);
        DBVC_Connected_Environments_Addon::save_settings([]);
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        remove_filter('dbvc_connected_processing_delay', '__return_zero');
        Schema::reset_memo();
        HubSchema::reset_memo();
        unset($GLOBALS['submenu']['dbvc-export']);
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    private function routes(): array
    {
        $GLOBALS['wp_rest_server'] = null;

        return array_keys(rest_get_server()->get_routes('dbvc/v1'));
    }

    private function request(string $method, string $route, array $body = null): WP_REST_Response
    {
        $parts = explode('?', $route, 2);
        $request = new WP_REST_Request($method, '/dbvc/v1/' . ltrim($parts[0], '/'));
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
            $request->set_query_params($query);
        }
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return rest_do_request($request);
    }

    public function test_page_and_routes_exist_only_while_a_gate_is_ready(): void
    {
        $this->assertFalse(DBVC_Connected_Admin_Page::is_available());
        $this->assertSame([], preg_grep('#^/dbvc/v1/(connected-admin|connected|agency)/#', $this->routes()), 'Both gates off: no admin routes.');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['submenu'] = [];
        do_action('admin_menu');
        $slugs = array_column($GLOBALS['submenu']['dbvc-export'] ?? [], 2);
        $this->assertNotContains(DBVC_Connected_Admin_Page::MENU_SLUG, $slugs, 'Both gates off: no menu entry.');

        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $this->assertTrue(DBVC_Connected_Admin_Page::is_available());
        $routes = $this->routes();
        $this->assertContains('/dbvc/v1/connected-admin/overview', $routes);
        $this->assertContains('/dbvc/v1/connected-admin/settings', $routes);
        $this->assertContains('/dbvc/v1/connected/status', $routes);
        $this->assertContains('/dbvc/v1/connected/run/(?P<runner>process|deliver|poll|release)', $routes);
        $this->assertSame([], preg_grep('#^/dbvc/v1/agency/#', $routes), 'Connector only: no hub admin routes.');
        $GLOBALS['submenu'] = [];
        do_action('admin_menu');
        $this->assertContains(DBVC_Connected_Admin_Page::MENU_SLUG, array_column($GLOBALS['submenu']['dbvc-export'] ?? [], 2));

        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $routes = $this->routes();
        $this->assertContains('/dbvc/v1/agency/status', $routes);
        $this->assertContains('/dbvc/v1/agency/framework-status', $routes);
        $this->assertContains('/dbvc/v1/agency/release-create', $routes);
        $this->assertContains('/dbvc/v1/agency/approve', $routes);
        $this->assertContains('/dbvc/v1/agency/rollouts', $routes);
        $this->assertContains('/dbvc/v1/agency/rollout-create', $routes);
        $this->assertContains('/dbvc/v1/agency/rollout-advance', $routes);
        $this->assertContains('/dbvc/v1/agency/rollout-prune', $routes);
        $this->assertContains('/dbvc/v1/agency/enable-subscription', $routes);
        $this->assertSame([], preg_grep('#^/dbvc/v1/agency/(exchange|observations|inbox)#', $routes), 'Machine routes stay on dbvc-agency/v1 only.');
    }

    public function test_routes_require_manage_options_and_wrap_the_inspectors(): void
    {
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $this->routes();

        $this->assertSame(401, $this->request('GET', 'connected-admin/overview')->get_status(), 'Anonymous requests are refused.');
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->request('GET', 'connected-admin/overview')->get_status());
        $this->assertSame(403, $this->request('GET', 'connected/status')->get_status());
        $this->assertSame(403, $this->request('GET', 'agency/status')->get_status());
        $this->assertSame(403, $this->request('POST', 'connected-admin/settings', ['connector' => ['enabled' => false]])->get_status(), 'A subscriber cannot flip gates.');
        $this->assertSame('ready', DBVC_Connected_Environments_Addon::get_gate_state());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $overview = $this->request('GET', 'connected-admin/overview');
        $this->assertSame(200, $overview->get_status());
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $overview->get_headers()['Cache-Control']);
        $data = $overview->get_data();
        $this->assertSame(['connector' => true, 'hub' => true], $data['roles']);
        $this->assertSame('ready', $data['connector']['gate_state']);
        $this->assertSame('provisional', $data['connector']['identity']['enrollment_state']);
        $this->assertSame('ready', $data['hub']['gate_state']);
        $this->assertSame(HubSchema::SCHEMA_VERSION, $data['hub']['schema']['version']);
        $this->assertSame('0', $data['settings']['connector'][DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED]);
        $this->assertSame(DBVC_Connected_CLI_Inspector::status()['gate_state'], $this->request('GET', 'connected/status')->get_data()['gate_state'], 'Read routes return the inspector result unchanged.');
        $this->assertSame(DBVC_Agency_CLI_Inspector::status()['environments'], $this->request('GET', 'agency/status')->get_data()['environments']);

        // A runner is the same explicit run the CLI performs; the reconciliation requested by enabling settles.
        $run = $this->request('POST', 'connected/run/process', []);
        $this->assertSame(200, $run->get_status(), wp_json_encode($run->get_data()));
        $this->assertSame(3, $run->get_data()['claimed'], 'The reconciliation requested by enabling is processed (wp.service retries: no service post type here).');
        $this->assertSame(404, $this->request('POST', 'connected/run/apply', [])->get_status(), 'Only the four named runners exist.');
        $deliver = $this->request('POST', 'connected/run/deliver', []);
        $this->assertSame('not_enrolled', $deliver->get_data()['blocked']);
        $enroll = $this->request('POST', 'connected/enroll', ['hub' => 'not a url', 'token' => 'x']);
        $this->assertGreaterThanOrEqual(400, $enroll->get_status(), 'Inspector WP_Errors become error responses.');
        $this->assertSame(DBVC_Connected_CLI_Inspector::enroll(['hub' => 'not a url', 'token' => 'x'])->get_error_code(), $enroll->get_data()['code']);
        $invalid = $this->request('POST', 'agency/invite', ['client' => 'bad client!']);
        $this->assertGreaterThanOrEqual(400, $invalid->get_status());

        // Settings: the apply gate follows the same rules as the Add-ons tab save.
        $saved = $this->request('POST', 'connected-admin/settings', ['connector' => ['enabled' => true, 'apply_enabled' => true], 'hub' => ['enabled' => true]]);
        $this->assertSame(200, $saved->get_status());
        $this->assertSame('1', $saved->get_data()['saved']['connector'][DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED]);
        $this->assertTrue(DBVC_Connected_Environments_Addon::is_apply_enabled());
        $saved = $this->request('POST', 'connected-admin/settings', ['connector' => ['enabled' => false, 'apply_enabled' => true]]);
        $this->assertSame('0', $saved->get_data()['saved']['connector'][DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED], 'Apply cannot stay on while the connector is off.');
        $this->assertTrue($saved->get_data()['page_available'], 'The hub keeps the page alive.');
        $this->assertSame('disabled', DBVC_Connected_Environments_Addon::get_gate_state());
        $saved = $this->request('POST', 'connected-admin/settings', ['hub' => ['enabled' => false]]);
        $this->assertFalse($saved->get_data()['page_available']);
        $this->assertSame([], preg_grep('#^/dbvc/v1/(connected-admin|connected|agency)/#', $this->routes()), 'Both gates off again: routes gone on the next request.');
    }

    /**
     * Slice A2: the invitation token appears only in the invite response, the
     * invitations list never carries it, and hold / release / revoke drive the
     * registry through the same service the CLI uses.
     */
    public function test_environment_actions_and_invitations_never_expose_tokens(): void
    {
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $this->routes();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $invite = $this->request('POST', 'agency/invite', ['client' => 'client-a', 'label' => 'Client A staging', 'ttl' => 600]);
        $this->assertSame(200, $invite->get_status());
        $issued = $invite->get_data();
        $this->assertArrayHasKey('token', $issued);
        $this->assertSame(home_url('/'), $issued['hub_url']);

        $list = $this->request('GET', 'agency/invitations')->get_data();
        $this->assertSame(1, $list['returned']);
        $this->assertSame('open', $list['invitations'][0]['state']);
        $this->assertSame('Client A staging', $list['invitations'][0]['environment_label']);
        $this->assertStringNotContainsString($issued['token'], wp_json_encode($list), 'Listing never carries the token.');
        $this->assertStringNotContainsString($issued['token'], wp_json_encode($this->request('GET', 'agency/status')->get_data()));

        $exchange = (new \Dbvc\AgencyControl\Enrollment\EnrollmentService())->exchange(['token' => $issued['token'], 'environment_id' => 'a-stage', 'site_url' => 'https://a-stage.example/', 'protocol_versions' => [\Dbvc\ConnectedProtocol\Protocol::PROTOCOL_VERSION]]);
        $this->assertIsArray($exchange);
        $this->assertSame('consumed', $this->request('GET', 'agency/invitations')->get_data()['invitations'][0]['state']);
        $this->assertSame('a-stage', $this->request('GET', 'agency/invitations')->get_data()['invitations'][0]['consumed_environment_id']);

        $environments = $this->request('GET', 'agency/environments')->get_data()['environments'];
        $this->assertSame('enabled', $environments[0]['status']);

        $held = $this->request('POST', 'agency/hold', ['environment' => 'a-stage', 'reason' => 'clone suspected']);
        $this->assertSame(200, $held->get_status());
        $this->assertSame(['environment_id' => 'a-stage', 'status' => 'held', 'hold_reason' => 'operator:clone suspected'], $held->get_data());
        $this->assertSame(409, $this->request('POST', 'agency/hold', ['environment' => 'a-stage'])->get_status(), 'Holding twice is refused.');
        $this->assertSame('held', $this->request('GET', 'agency/environments')->get_data()['environments'][0]['status']);

        $released = $this->request('POST', 'agency/release', ['environment' => 'a-stage']);
        $this->assertSame(['environment_id' => 'a-stage', 'status' => 'enabled'], $released->get_data());
        $row = $this->request('GET', 'agency/environments')->get_data()['environments'][0];
        $this->assertSame('', $row['hold_reason']);
        $this->assertSame('https://a-stage.example/', $row['enrolled_site_url'], 'Releasing an operator hold keeps the enrolled URL.');

        $this->assertSame(404, $this->request('POST', 'agency/revoke', ['environment' => 'nope'])->get_status());
        $revoked = $this->request('POST', 'agency/revoke', ['environment' => 'a-stage']);
        $this->assertSame('revoked', $revoked->get_data()['status']);
        $this->assertSame(409, $this->request('POST', 'agency/hold', ['environment' => 'a-stage'])->get_status(), 'A revoked environment cannot be held.');
        $this->assertSame(409, $this->request('POST', 'agency/release', ['environment' => 'a-stage'])->get_status());

        $this->assertSame(DBVC_Agency_CLI_Inspector::invitations(['open' => true]), $this->request('GET', 'agency/invitations?open=1')->get_data());
        $this->assertSame(0, $this->request('GET', 'agency/invitations?open=1')->get_data()['returned']);
    }

    /**
     * Slice A3: compare, baseline confirmation, instance links and the object
     * drawer's per-instance reads (projections, baselines, events) over the
     * admin routes, seeded through the machine receipt route.
     */
    public function test_compare_baselines_links_and_instance_filters(): void
    {
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        $this->routes();
        $prod = $this->enroll_for_admin_test('client-a', 'a-prod');
        $stage = $this->enroll_for_admin_test('client-a', 'a-stage');
        $hash = static function (string $seed): string {
            return hash('sha256', 'fixture:' . $seed);
        };
        $class = static function (string $uid, string $seed) use ($hash): array {
            return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => $hash($seed), 'exists' => true, 'complete' => true]];
        };
        $order = static function (string $seed) use ($hash): array {
            return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => \Dbvc\AgencyControl\Comparison\ComparisonService::ORDER_INSTANCE_UID], 'projection' => ['profile' => 'bricks-collection-order-v1', 'hash' => $hash($seed), 'exists' => true, 'complete' => true]];
        };
        $this->report_for_admin_test($prod, 1, [$class('class-x', 'x1'), $class('class-y', 'y1'), $order('o1')]);
        $this->report_for_admin_test($stage, 1, [$class('class-x', 'x1'), $class('class-y', 'y1'), $order('o1')]);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $compare = $this->request('GET', 'agency/compare?source=a-prod&target=a-stage');
        $this->assertSame(200, $compare->get_status());
        $data = $compare->get_data();
        $this->assertSame(3, $data['counts']['baseline_required']);
        $this->assertSame(['bricks.global_class' => true], $data['target']['coverage']);
        $this->assertSame('yes', $data['rows'][0]['complete'], 'Rows arrive in the CLI inspector shape (yes/no flags, 12-char hashes).');
        $this->assertSame(12, strlen($data['rows'][0]['source_hash']));
        $this->assertSame($data, DBVC_Agency_CLI_Inspector::compare(['source' => 'a-prod', 'target' => 'a-stage']));

        $confirmed = $this->request('POST', 'agency/baseline-confirm', ['source' => 'a-prod', 'target' => 'a-stage', 'domain' => 'bricks.global_class', 'instance' => 'class-x']);
        $this->assertSame(200, $confirmed->get_status());
        $this->assertSame(1, $confirmed->get_data()['confirmed']);
        $rows = [];
        foreach ($this->request('GET', 'agency/compare?source=a-prod&target=a-stage')->get_data()['rows'] as $row) {
            $rows[$row['source_instance_uid']] = $row;
        }
        $this->assertSame('synchronized', $rows['class-x']['state']);
        $this->assertSame('baseline_required', $rows['class-y']['state']);
        $this->assertSame(1, $this->request('GET', 'agency/compare?source=a-prod&target=a-stage&state=synchronized')->get_data()['returned']);

        // Drawer reads: instance filters narrow projections, baselines and events to one UID.
        $this->assertSame(1, $this->request('GET', 'agency/projections?environment=a-prod&instance=class-x')->get_data()['returned']);
        $this->assertSame(3, $this->request('GET', 'agency/projections?environment=a-prod')->get_data()['returned']);
        $baselines = $this->request('GET', 'agency/baselines?instance=class-x')->get_data();
        $this->assertSame(1, $baselines['returned']);
        $this->assertSame('class-x', $baselines['baselines'][0]['source_instance_uid']);
        $this->assertSame(0, $this->request('GET', 'agency/baselines?instance=class-y')->get_data()['returned']);
        $events = $this->request('GET', 'agency/events?environment=a-stage&instance=class-y')->get_data();
        $this->assertSame(1, $events['returned']);
        $this->assertSame('class-y', $events['events'][0]['instance_uid']);
        $this->assertSame(DBVC_Agency_CLI_Inspector::events(['environment' => 'a-stage', 'instance' => 'class-y']), $events);

        // Links: an independently identified target instance pairs only through an explicit link.
        $this->report_for_admin_test($prod, 4, [$class('class-p', 'p1'), $order('o2')]);
        $this->report_for_admin_test($stage, 4, [$class('class-q', 'p1'), $order('o2')]);
        $rows = [];
        foreach ($this->request('GET', 'agency/compare?source=a-prod&target=a-stage')->get_data()['rows'] as $row) {
            $rows[$row['source_instance_uid']] = $row;
        }
        $this->assertSame('uid', $rows['class-p']['pairing']);
        $this->assertSame('no', $rows['class-p']['target_observed']);
        $linked = $this->request('POST', 'agency/link-instance', ['domain' => 'bricks.global_class', 'source' => 'a-prod', 'source-instance' => 'class-p', 'target' => 'a-stage', 'target-instance' => 'class-q']);
        $this->assertSame(200, $linked->get_status(), wp_json_encode($linked->get_data()));
        $rows = [];
        foreach ($this->request('GET', 'agency/compare?source=a-prod&target=a-stage')->get_data()['rows'] as $row) {
            $rows[$row['source_instance_uid']] = $row;
        }
        $this->assertSame('link', $rows['class-p']['pairing']);
        $this->assertSame('class-q', $rows['class-p']['target_instance_uid']);
        $this->assertSame(1, $this->request('GET', 'agency/links')->get_data()['returned']);
        $this->assertGreaterThanOrEqual(400, $this->request('POST', 'agency/link-instance', ['domain' => 'nope', 'source' => 'a-prod', 'source-instance' => 'x', 'target' => 'a-stage', 'target-instance' => 'y'])->get_status());
        $this->assertGreaterThanOrEqual(400, $this->request('GET', 'agency/compare?source=a-prod&target=missing')->get_status());

        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        $GLOBALS['wp_rest_application_password_uuid'] = null;
    }

    /**
     * Slice A4: definitions, framework subscriptions, the status report,
     * overrides and review items over the admin routes.
     */
    public function test_framework_definitions_status_overrides_and_reviews(): void
    {
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        $this->routes();
        $prod = $this->enroll_for_admin_test('client-a', 'a-prod');
        $hash = static function (string $seed): string {
            return hash('sha256', 'fixture:' . $seed);
        };
        $class = static function (string $uid, string $seed) use ($hash): array {
            return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => $hash($seed), 'exists' => true, 'complete' => true]];
        };
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Definitions: publish (validation passes through), list, set desired.
        $this->assertGreaterThanOrEqual(400, $this->request('POST', 'agency/definition-publish', ['definition' => 'def-a', 'version' => '1.0', 'domain' => 'bricks.global_class', 'hash' => 'nope'])->get_status());
        $published = $this->request('POST', 'agency/definition-publish', ['definition' => 'def-a', 'version' => '1.0', 'channel' => 'stable', 'order' => 1, 'domain' => 'bricks.global_class', 'profile' => 'bricks-global-class-v1', 'hash' => $hash('v1'), 'desired' => true, 'note' => 'first']);
        $this->assertSame(200, $published->get_status(), wp_json_encode($published->get_data()));
        $definitions = $this->request('GET', 'agency/definitions')->get_data();
        $this->assertSame(1, $definitions['returned']);
        $this->assertSame(['def-a', '1.0', 'yes', 1], [$definitions['definitions'][0]['definition_uid'], $definitions['definitions'][0]['version'], $definitions['definitions'][0]['desired'], $definitions['definitions'][0]['order']]);

        // Subscribe an object to the definition; status is unknown until observed, then clean.
        $this->assertSame(404, $this->request('POST', 'agency/subscribe-framework', ['environment' => 'a-prod', 'domain' => 'bricks.global_class', 'instance' => 'class-x', 'definition' => 'def-a', 'adopted-version' => '9.9'])->get_status());
        $subscribed = $this->request('POST', 'agency/subscribe-framework', ['environment' => 'a-prod', 'domain' => 'bricks.global_class', 'instance' => 'class-x', 'definition' => 'def-a', 'adopted-version' => '1.0', 'channel' => 'stable']);
        $this->assertSame(200, $subscribed->get_status(), wp_json_encode($subscribed->get_data()));
        $status = $this->request('GET', 'agency/framework-status')->get_data();
        $this->assertSame(1, $status['returned']);
        $this->assertSame(['unknown', 'current'], [$status['rows'][0]['drift'], $status['rows'][0]['version']], 'Not observed yet: unknown, never clean.');
        $this->assertSame($status, DBVC_Agency_CLI_Inspector::framework_status([]));
        $subscription_id = $status['rows'][0]['subscription_id'];

        $this->report_for_admin_test($prod, 1, [$class('class-x', 'v1')]);
        $this->assertSame('clean', $this->request('GET', 'agency/framework-status')->get_data()['rows'][0]['drift']);
        $this->assertSame(1, $this->request('GET', 'agency/framework-status?drift=clean')->get_data()['returned']);

        // Reviews: routing created one raw item; classify resolves the clean one automatically.
        $reviews = $this->request('GET', 'agency/reviews')->get_data();
        $this->assertSame(1, $reviews['returned']);
        $this->assertSame('observed', $reviews['reviews'][0]['state']);
        $classified = $this->request('POST', 'agency/review-classify', [])->get_data();
        $this->assertSame([1, 0, 1], [$classified['examined'], $classified['classified'], $classified['resolved']]);
        $this->assertSame('resolved', $this->request('GET', 'agency/reviews')->get_data()['reviews'][0]['state']);

        // Drift: the new review item is classified (stays open) and can be resolved by an operator with a note only.
        $this->report_for_admin_test($prod, 2, [$class('class-x', 'edited')]);
        $this->assertSame('local_drift', $this->request('GET', 'agency/framework-status')->get_data()['rows'][0]['drift']);
        $classified = $this->request('POST', 'agency/review-classify', [])->get_data();
        $this->assertSame([1, 1, 0], [$classified['examined'], $classified['classified'], $classified['resolved']]);
        $open = $this->request('GET', 'agency/reviews?state=classified')->get_data();
        $this->assertSame(1, $open['returned']);
        $this->assertSame('local_drift', $open['reviews'][0]['drift']);
        $this->assertSame(400, $this->request('POST', 'agency/review-resolve', ['id' => $open['reviews'][0]['review_item_id']])->get_status());
        $resolved = $this->request('POST', 'agency/review-resolve', ['id' => $open['reviews'][0]['review_item_id'], 'note' => 'approved as override']);
        $this->assertSame('resolved', $resolved->get_data()['state']);
        $this->assertSame(0, $this->request('GET', 'agency/reviews?state=classified')->get_data()['returned']);

        // Override needs a rationale → approved_override.
        $identity = ['environment' => 'a-prod', 'domain' => 'bricks.global_class', 'instance' => 'class-x', 'definition' => 'def-a'];
        $this->assertSame(400, $this->request('POST', 'agency/override-approve', $identity)->get_status(), 'A rationale is required.');
        $approved = $this->request('POST', 'agency/override-approve', $identity + ['rationale' => 'client brand spacing']);
        $this->assertSame(200, $approved->get_status(), wp_json_encode($approved->get_data()));
        $row = $this->request('GET', 'agency/framework-status')->get_data()['rows'][0];
        $this->assertSame(['approved_override', 'approved'], [$row['drift'], $row['override_state']]);
        $overrides = $this->request('GET', 'agency/overrides')->get_data();
        $this->assertSame(1, $overrides['returned']);
        $this->assertSame('client brand spacing', $overrides['overrides'][0]['rationale']);

        // A new desired version: behind_version + needs_rebase_review; adopting it restores current; detaching drops the override.
        $this->request('POST', 'agency/definition-publish', ['definition' => 'def-a', 'version' => '1.1', 'order' => 2, 'domain' => 'bricks.global_class', 'profile' => 'bricks-global-class-v1', 'hash' => $hash('v11')]);
        $desired = $this->request('POST', 'agency/definition-desire', ['definition' => 'def-a', 'version' => '1.1']);
        $this->assertSame(200, $desired->get_status());
        $this->assertSame(1, $desired->get_data()['rebase_reviews']);
        $status = $this->request('GET', 'agency/framework-status')->get_data();
        $this->assertSame(['behind_version', '1.1', 'needs_rebase_review', 1], [$status['rows'][0]['version'], $status['rows'][0]['desired_version'], $status['rows'][0]['override_state'], $status['counts']['rebase_review']]);
        $this->assertSame(404, $this->request('POST', 'agency/adopt-version', ['id' => $subscription_id, 'version' => '3.0'])->get_status());
        $adopted = $this->request('POST', 'agency/adopt-version', ['id' => $subscription_id, 'version' => '1.1']);
        $this->assertTrue($adopted->get_data()['changed']);
        $this->assertSame('current', $this->request('GET', 'agency/framework-status')->get_data()['rows'][0]['version']);
        $detached = $this->request('POST', 'agency/override-detach', $identity);
        $this->assertSame('detached', $detached->get_data()['state']);
        $this->assertSame('local_drift', $this->request('GET', 'agency/framework-status')->get_data()['rows'][0]['drift']);

        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        $GLOBALS['wp_rest_application_password_uuid'] = null;
    }

    /**
     * Slice A5: the release pipeline surface over the admin routes — create from
     * a source's projections, list + detail, manifest payload state, withdraw,
     * and the guard/validation errors the inspectors raise. Sealing a release
     * needs the source connector's authorized payload transfer (ConnectedReleaseTest)
     * and execution needs the target's apply gate (ConnectedApplyTest); both are
     * covered there and verified end to end in the disposable lab.
     */
    public function test_release_pipeline_create_list_withdraw_and_guards(): void
    {
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        $this->routes();
        $prod = $this->enroll_for_admin_test('client-a', 'a-prod');
        $this->enroll_for_admin_test('client-a', 'a-stage');
        $hash = static function (string $seed): string {
            return hash('sha256', 'fixture:' . $seed);
        };
        $class = static function (string $uid, string $seed) use ($hash): array {
            return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => $hash($seed), 'exists' => true, 'complete' => true]];
        };
        $order = static function (string $seed) use ($hash): array {
            return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => \Dbvc\AgencyControl\Comparison\ComparisonService::ORDER_INSTANCE_UID], 'projection' => ['profile' => 'bricks-collection-order-v1', 'hash' => $hash($seed), 'exists' => true, 'complete' => true]];
        };
        $this->report_for_admin_test($prod, 1, [$class('class-x', 'x1'), $class('class-y', 'y1'), $order('o1')]);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Validation surfaced by the route.
        $this->assertGreaterThanOrEqual(400, $this->request('POST', 'agency/release-create', ['source' => 'a-prod', 'items' => 'bricks.global_class:collection.order'])->get_status());
        $this->assertGreaterThanOrEqual(400, $this->request('POST', 'agency/release-create', ['source' => 'a-prod', 'items' => 'not-a-valid-spec'])->get_status());
        $this->assertGreaterThanOrEqual(400, $this->request('POST', 'agency/release-create', ['source' => 'a-prod', 'items' => ''])->get_status());

        // Create from two projected objects; the release opens, payloads pending.
        $created = $this->request('POST', 'agency/release-create', ['source' => 'a-prod', 'items' => 'bricks.global_class:class-x,bricks.global_class:class-y', 'note' => 'two classes']);
        $this->assertSame(200, $created->get_status(), wp_json_encode($created->get_data()));
        $data = $created->get_data();
        $release_uid = $data['release']['release_uid'];
        $this->assertSame('open', $data['release']['state']);
        $this->assertSame(2, $data['release']['items']);
        $this->assertSame('two classes', $data['release']['note']);
        $this->assertCount(2, $data['items']);
        $this->assertStringStartsWith('requested', $data['items'][0]['payload']);
        $this->assertSame('replace', $data['items'][0]['operation']);

        // List + detail equal the inspector.
        $list = $this->request('GET', 'agency/releases')->get_data();
        $this->assertSame(1, $list['returned']);
        $this->assertSame(1, $list['counts']['open']);
        $detail = $this->request('GET', 'agency/releases?release=' . $release_uid)->get_data();
        $this->assertSame($release_uid, $detail['release']['release_uid']);
        $this->assertSame(DBVC_Agency_CLI_Inspector::releases(['release' => $release_uid]), $detail);
        $this->assertGreaterThanOrEqual(400, $this->request('GET', 'agency/releases?release=rel-000000000000')->get_status());

        // An unsealed release cannot be prepared; approvals for a missing operation 404.
        $this->assertSame('dbvc_agency_release_not_sealed', $this->request('POST', 'agency/prepare-request', ['release' => $release_uid, 'target' => 'a-stage'])->get_data()['code']);
        $this->assertSame(0, $this->request('GET', 'agency/preparations?release=' . $release_uid)->get_data()['returned']);
        $this->assertSame(0, $this->request('GET', 'agency/approvals')->get_data()['returned']);
        $this->assertSame(404, $this->request('POST', 'agency/approve', ['operation' => 'prep-000000000000'])->get_status());
        $this->assertGreaterThanOrEqual(400, $this->request('POST', 'agency/revoke-approval', ['approval' => 'apr-000000000000'])->get_status());
        // The rollback write route is wired and surfaces the inspector's not-found error.
        $rollback = $this->request('POST', 'agency/rollback', ['operation' => 'prep-000000000000']);
        $this->assertSame(404, $rollback->get_status());
        $this->assertSame('dbvc_agency_operation_not_found', $rollback->get_data()['code']);

        // The fleet-rollout routes are wired: create surfaces the release-not-found error, advance the rollout-not-found error, and the list reads.
        $rollout_create = $this->request('POST', 'agency/rollout-create', ['release' => 'rel-000000000000', 'cohorts' => [['a-prod']]]);
        $this->assertSame(404, $rollout_create->get_status());
        $this->assertSame('dbvc_agency_release_not_found', $rollout_create->get_data()['code']);
        $rollout_advance = $this->request('POST', 'agency/rollout-advance', ['rollout' => 'rol-000000000000']);
        $this->assertSame(404, $rollout_advance->get_status());
        $this->assertSame('dbvc_agency_rollout_not_found', $rollout_advance->get_data()['code']);
        $this->assertArrayHasKey('rollouts', $this->request('GET', 'agency/rollouts')->get_data());
        // Retention: a dry-run prune reports a count and deletes nothing.
        $prune = $this->request('POST', 'agency/rollout-prune', ['days' => 30, 'dry_run' => true]);
        $this->assertSame(200, $prune->get_status(), wp_json_encode($prune->get_data()));
        $this->assertTrue($prune->get_data()['dry_run']);
        $this->assertArrayHasKey('prunable', $prune->get_data());

        // Withdraw is idempotent and reported.
        $withdrawn = $this->request('POST', 'agency/release-withdraw', ['release' => $release_uid]);
        $this->assertSame('withdrawn', $withdrawn->get_data()['state']);
        $this->assertTrue($withdrawn->get_data()['changed']);
        $this->assertFalse($this->request('POST', 'agency/release-withdraw', ['release' => $release_uid])->get_data()['changed']);
        $this->assertSame('withdrawn', $this->request('GET', 'agency/releases?release=' . $release_uid)->get_data()['release']['state']);

        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        $GLOBALS['wp_rest_application_password_uuid'] = null;
    }

    /**
     * Slice A6: the connector-role Activity reads over the admin routes — outbox
     * (with state filter), inbox, prepare receipts and the execution journal —
     * all equal to the inspector output. Received-observation, prepare and
     * execution machinery is covered by ConnectedEnvironmentsInboxTest,
     * ConnectedReleaseTest and ConnectedApplyTest and in the disposable lab.
     */
    public function test_connector_activity_reads_wrap_the_inspectors(): void
    {
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        update_option('bricks_global_classes', [['id' => 'btn001', 'name' => '--btn-primary', 'settings' => ['_background' => ['color' => ['raw' => '#123']]]]]);
        $this->routes();
        DBVC_Connected_CLI_Inspector::reconcile(['domain' => 'bricks.global_class']);
        $processed = DBVC_Connected_CLI_Inspector::process([]);
        $this->assertIsArray($processed);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Outbox: events produced by this site, with counts and a state filter, equal to the inspector.
        $outbox = $this->request('GET', 'connected/outbox?limit=200');
        $this->assertSame(200, $outbox->get_status());
        $this->assertArrayHasKey('counts', $outbox->get_data());
        $this->assertGreaterThan(0, $outbox->get_data()['returned'], 'A seeded Bricks class produces an outbox event.');
        $this->assertSame(DBVC_Connected_CLI_Inspector::outbox(['limit' => 200]), $outbox->get_data());
        $this->assertSame(DBVC_Connected_CLI_Inspector::outbox(['state' => 'pending', 'limit' => 200]), $this->request('GET', 'connected/outbox?state=pending&limit=200')->get_data());
        $event = $outbox->get_data()['events'][0];
        foreach (['event_id', 'sequence', 'domain', 'instance_uid', 'origin', 'delivery_state', 'hash'] as $key) {
            $this->assertArrayHasKey($key, $event);
        }

        // Inbox / preparations / operations: empty on a fresh connector, shaped and equal to the inspector.
        $inbox = $this->request('GET', 'connected/inbox');
        $this->assertSame(0, $inbox->get_data()['returned']);
        $this->assertSame(DBVC_Connected_CLI_Inspector::inbox([]), $inbox->get_data());
        $this->assertSame(DBVC_Connected_CLI_Inspector::preparations([]), $this->request('GET', 'connected/preparations')->get_data());
        $this->assertSame(DBVC_Connected_CLI_Inspector::operations([]), $this->request('GET', 'connected/operations')->get_data());

        // Reconcile runner: a domain arg is honoured; an unknown domain is refused.
        $reconcile = $this->request('POST', 'connected/reconcile', ['domain' => 'bricks.global_class']);
        $this->assertSame(200, $reconcile->get_status());
        $this->assertArrayHasKey('generations', $reconcile->get_data());
        $this->assertGreaterThanOrEqual(400, $this->request('POST', 'connected/reconcile', ['domain' => 'nonsense'])->get_status());

        delete_option('bricks_global_classes');
    }

    /**
     * @return array{environment_id: string, epoch: string, user_id: int, uuid: string}
     */
    private function enroll_for_admin_test(string $client_id, string $environment_id): array
    {
        $invite = (new \Dbvc\AgencyControl\Enrollment\EnrollmentService())->invite(['client_id' => $client_id, 'environment_label' => $environment_id]);
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/enrollments/exchange');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['token' => $invite['token'], 'environment_id' => $environment_id, 'site_url' => 'https://' . $environment_id . '.example/', 'protocol_versions' => [\Dbvc\ConnectedProtocol\Protocol::PROTOCOL_VERSION]]));
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();

        return ['environment_id' => $data['environment_id'], 'epoch' => $data['installation_epoch'], 'user_id' => get_user_by('login', $data['principal']['username'])->ID, 'uuid' => $data['principal']['uuid']];
    }

    private function report_for_admin_test(array $env, int $first_sequence, array $events): void
    {
        $bodies = [];
        $sequence = $first_sequence;
        foreach ($events as $event) {
            $bodies[] = \Dbvc\ConnectedProtocol\ObservationEvent::build(array_merge($event, [
                'event_id' => 'obs-' . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT),
                'environment_id' => $env['environment_id'],
                'installation_epoch' => $env['epoch'],
                'sequence' => $sequence,
                'observed_at' => '2026-09-20T10:00:00Z',
            ]));
            $sequence++;
        }
        $previous = get_current_user_id();
        wp_set_current_user($env['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $env['uuid'];
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['events' => $bodies]));
        $response = rest_do_request($request);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        wp_set_current_user($previous);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
    }

    public function test_bootstrap_config_carries_no_secrets_and_the_page_renders_a_mount_point(): void
    {
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $config = DBVC_Connected_Admin_Page::bootstrap_config();
        $this->assertSame(['connector' => true, 'hub' => false], $config['roles']);
        $this->assertSame(rest_url(), $config['root']);
        $this->assertSame('dbvc/v1', $config['namespace']);
        $this->assertArrayHasKey('nonce', $config);
        $this->assertSame(['root', 'namespace', 'nonce', 'roles', 'gates', 'pluginVersion', 'addonsUrl'], array_keys($config));
        ob_start();
        DBVC_Connected_Admin_Page::render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('id="dbvc-connected-app"', $html);
        $this->assertStringContainsString('class="dbvc-ce"', $html);
        $this->assertFileExists(DBVC_PLUGIN_PATH . 'build/connected-app.js');
        $this->assertFileExists(DBVC_PLUGIN_PATH . 'build/style-connected-app.css');
        $asset = include DBVC_PLUGIN_PATH . 'build/connected-app.asset.php';
        $this->assertContains('wp-api-fetch', $asset['dependencies']);
    }
}
