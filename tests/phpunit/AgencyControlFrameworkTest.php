<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Framework\FrameworkService;
use Dbvc\AgencyControl\Framework\FrameworkStatusService;
use Dbvc\AgencyControl\Framework\ReviewResolutionService;
use Dbvc\AgencyControl\Storage\DefinitionStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\ReviewStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\AgencyControl\Storage\SubscriptionStore;
use Dbvc\ConnectedProtocol\Comparison;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M3 step 2: immutable framework definition versions, explicit adoption,
 * approved overrides and the framework status report over them.
 *
 * @group connected-environments
 */
class AgencyControlFrameworkTest extends WP_UnitTestCase
{
    private const DOMAIN = 'bricks.global_class';
    private const PROFILE = 'bricks-global-class-v1';

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
        foreach ([['client-a', 'a-prod'], ['client-b', 'b-prod']] as $pair) {
            $this->env[$pair[1]] = $this->enroll($pair[0], $pair[1]);
            $this->sequence[$pair[1]] = 0;
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

    public function test_schema_v4_adds_definition_and_override_tables(): void
    {
        global $wpdb;
        $this->assertSame(HubSchema::SCHEMA_VERSION, (int) get_option(HubSchema::OPTION_SCHEMA_VERSION));
        foreach (['definitions', 'overrides'] as $name) {
            $this->assertSame('1', (string) $wpdb->get_var('SELECT 1 FROM ' . HubSchema::table($name) . ' LIMIT 0') ?: '1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->assertSame('', (string) $wpdb->last_error, $name);
        }
        $this->assertStringContainsString('dbvc_ac_definitions', HubSchema::table('definitions'));
        $this->assertStringContainsString('dbvc_ac_overrides', HubSchema::table('overrides'));
    }

    public function test_definitions_are_immutable_and_ordered_by_studio_supplied_order_only(): void
    {
        $service = new FrameworkService();
        $v19 = $service->publish($this->definition('1.9', 'h19', ['desired' => true]));
        $this->assertIsArray($v19);
        $this->assertSame(1, $v19['version_order']);
        $this->assertTrue($v19['desired']);
        $v110 = $service->publish($this->definition('1.10', 'h110'));
        $this->assertSame(2, $v110['version_order'], 'Order follows the publish sequence, not a string comparison of "1.10" and "1.9".');
        $this->assertFalse($v110['desired']);

        // Republishing a version, even with another hash, is refused: versions are immutable.
        $again = $service->publish($this->definition('1.9', 'h19-changed'));
        $this->assertInstanceOf(WP_Error::class, $again);
        $this->assertSame('dbvc_agency_definition_exists', $again->get_error_code());
        $this->assertSame($this->hash('h19'), (new DefinitionStore())->get('studio', 'def-a', '1.9')['definition_hash']);

        // Explicit order wins over the sequence; nothing is inferred from the label.
        $hotfix = $service->publish($this->definition('1.9.1', 'h191', ['version_order' => 10, 'channel' => 'beta']));
        $this->assertSame(10, $hotfix['version_order']);
        $this->assertSame('beta', $hotfix['channel']);
        $this->assertSame('dbvc_agency_invalid_version_order', $service->publish($this->definition('2.0', 'h2', ['version_order' => 0]))->get_error_code());
        $this->assertSame('dbvc_agency_version_order_taken', $service->publish($this->definition('2.0', 'h2', ['version_order' => 2]))->get_error_code(), 'Two versions on one channel never share an order.');
        $this->assertSame(10, $service->publish($this->definition('2.0-rc', 'h2rc', ['version_order' => 10, 'channel' => 'rc']))['version_order'], 'Order is unique per channel, so another channel may reuse it.');
        $this->assertSame('dbvc_agency_invalid_hash', $service->publish($this->definition('2.0', 'h2', ['hash' => 'not-a-hash']))->get_error_code());
        $this->assertSame('dbvc_agency_invalid_domain', $service->publish($this->definition('2.0', 'h2', ['domain' => 'bricks.template']))->get_error_code());

        // Desired is per channel; copying a version onto another channel keeps its hash.
        $copy = $service->publish(['agency_id' => 'studio', 'definition_uid' => 'def-a', 'version' => '1.9.1-stable', 'from_version' => '1.9.1', 'desired' => true]);
        $this->assertSame($this->hash('h191'), $copy['definition_hash']);
        $this->assertSame('stable', $copy['channel']);
        $this->assertSame(self::PROFILE, $copy['profile']);
        $store = new DefinitionStore();
        $this->assertSame('1.9.1-stable', $store->desired('studio', 'def-a', 'stable')['version']);
        $this->assertNull($store->desired('studio', 'def-a', 'beta'));
        $this->assertSame(0, $store->get('studio', 'def-a', '1.9')['desired']);
        $this->assertSame(5, count($store->all('def-a')));
    }

    public function test_publish_from_environment_reads_only_a_fresh_complete_existing_projection(): void
    {
        $service = new FrameworkService();
        $from = ['agency_id' => 'studio', 'definition_uid' => 'def-a', 'version' => '1.0', 'domain' => self::DOMAIN, 'profile' => self::PROFILE, 'from_environment_id' => 'a-prod', 'from_instance_uid' => 'class-x'];
        $this->assertSame('dbvc_agency_environment_stale', $service->publish($from)->get_error_code(), 'Enrolled but never reported: no contact yet.');

        $this->report('a-prod', [$this->class('class-x', 'x1', false)]);
        $this->assertSame('dbvc_agency_projection_not_found', $service->publish(array_merge($from, ['from_instance_uid' => 'class-y']))->get_error_code());
        $this->assertSame('dbvc_agency_projection_incomplete', $service->publish($from)->get_error_code());

        $this->report('a-prod', [$this->tombstone('class-x')]);
        $this->assertSame('dbvc_agency_projection_absent', $service->publish($from)->get_error_code());

        $this->report('a-prod', [$this->class('class-x', 'x1')]);
        (new EnvironmentRegistry())->update('a-prod', ['last_contact_at' => '2000-01-01 00:00:00']);
        $this->assertSame('dbvc_agency_environment_stale', $service->publish($from)->get_error_code());

        $this->report('a-prod', [$this->class('class-x', 'x1')]);
        $published = $service->publish($from);
        $this->assertIsArray($published);
        $this->assertSame($this->hash('x1'), $published['definition_hash']);
        $this->assertSame(['environment_id' => 'a-prod', 'instance_uid' => 'class-x'], $published['source']);

        // Another agency's environment is not a source for this agency's definitions.
        $this->assertSame('dbvc_agency_scope_mismatch', $service->publish(array_merge($from, ['agency_id' => 'other', 'version' => '1.1']))->get_error_code());
    }

    public function test_framework_status_reports_drift_and_version_distance_without_changing_anything(): void
    {
        $service = new FrameworkService();
        $status = new FrameworkStatusService();
        $service->publish($this->definition('1.0', 'v1', ['desired' => true]));

        // Subscribe with adoption; the adopted version must be published.
        $subscriptions = new SubscriptionService();
        $this->assertSame('dbvc_agency_definition_not_found', $subscriptions->subscribe_framework('a-prod', self::DOMAIN, 'class-x', 'def-a', '9.9')->get_error_code());
        $subscribed = $subscriptions->subscribe_framework('a-prod', self::DOMAIN, 'class-x', 'def-a', '1.0');
        $this->assertSame('created', $subscribed['result']);
        $this->assertSame('stable', $subscribed['channel']);
        $subscription_id = (new SubscriptionStore())->framework_subscriptions('a-prod', 'def-a')[0]['subscription_id'];

        // Nothing observed yet: unknown, never clean.
        $row = $this->row($status->status('a-prod'));
        $this->assertSame(['drift' => 'unknown', 'version' => 'current'], ['drift' => $row['drift'], 'version' => $row['version']]);
        $this->assertContains('not_observed', $row['reasons']);

        // Matching hash → clean/current; different hash → local_drift.
        $this->report('a-prod', [$this->class('class-x', 'v1')]);
        $row = $this->row($status->status('a-prod'));
        $this->assertSame('clean', $row['drift']);
        $this->assertSame('current', $row['version']);
        $this->assertSame(self::PROFILE, $row['profile']);
        $this->report('a-prod', [$this->class('class-x', 'edited')]);
        $this->assertSame('local_drift', $this->row($status->status('a-prod'))['drift']);

        // Approving the observed hash records the deviation; it never rewrites the definition or the projection.
        $this->assertSame('dbvc_agency_rationale_required', $service->approve_override(['environment_id' => 'a-prod', 'domain' => self::DOMAIN, 'instance_uid' => 'class-x', 'definition_uid' => 'def-a'])->get_error_code());
        $approved = $service->approve_override(['environment_id' => 'a-prod', 'domain' => self::DOMAIN, 'instance_uid' => 'class-x', 'definition_uid' => 'def-a', 'rationale' => 'client brand spacing']);
        $this->assertIsArray($approved);
        $this->assertSame($this->hash('edited'), $approved['approved_hash']);
        $this->assertSame('1.0', $approved['definition_version']);
        $this->assertNotSame('', $approved['policy_revision']);
        $this->assertSame($this->hash('v1'), (new DefinitionStore())->get('studio', 'def-a', '1.0')['definition_hash']);
        $this->assertSame($this->hash('edited'), (new ProjectionStore())->get('a-prod', $this->env['a-prod']['epoch'], self::DOMAIN, 'class-x', self::PROFILE)['semantic_hash']);
        $row = $this->row($status->status('a-prod'));
        $this->assertSame('approved_override', $row['drift']);
        $this->assertSame(OverrideStore::STATE_APPROVED, $row['override_state']);

        // The client edits again → override_changed. Reverting to the approved hash → approved_override.
        $this->report('a-prod', [$this->class('class-x', 'edited-again')]);
        $this->assertSame('override_changed', $this->row($status->status('a-prod'))['drift']);
        $this->report('a-prod', [$this->class('class-x', 'edited')]);
        $this->assertSame('approved_override', $this->row($status->status('a-prod'))['drift']);

        // A new desired version: behind_version, and the override needs a rebase review (it is neither dropped nor rewritten).
        $service->publish($this->definition('1.1', 'v11', ['desired' => true]));
        $row = $this->row($status->status('a-prod'));
        $this->assertSame('behind_version', $row['version']);
        $this->assertSame('1.1', $row['desired_version']);
        $this->assertSame('approved_override', $row['drift']);
        $this->assertSame(OverrideStore::STATE_NEEDS_REBASE_REVIEW, $row['override_state']);
        $this->assertSame(1, $status->status('a-prod')['counts']['rebase_review']);
        $override = (new OverrideStore())->find('a-prod', self::DOMAIN, 'class-x', 'def-a');
        $this->assertSame($this->hash('edited'), $override['approved_hash']);
        $this->assertSame('client brand spacing', $override['rationale']);

        // Explicit adoption of the new version: current again; the instance still differs from 1.1 → the (flagged) override still matches.
        $this->assertSame('dbvc_agency_definition_not_found', $service->adopt_version($subscription_id, '3.0')->get_error_code());
        $adopted = $service->adopt_version($subscription_id, '1.1');
        $this->assertTrue($adopted['changed']);
        $row = $this->row($status->status('a-prod'));
        $this->assertSame('current', $row['version']);
        $this->assertSame('approved_override', $row['drift']);
        $this->assertSame($this->hash('v11'), $row['adopted_hash']);

        // Re-approval under the new version clears the rebase flag; detaching restores plain comparison.
        $reapproved = $service->approve_override(['environment_id' => 'a-prod', 'domain' => self::DOMAIN, 'instance_uid' => 'class-x', 'definition_uid' => 'def-a', 'rationale' => 'still fine on 1.1']);
        $this->assertSame('1.1', $reapproved['definition_version']);
        $this->assertSame(OverrideStore::STATE_APPROVED, $this->row($status->status('a-prod'))['override_state']);
        $detached = $service->detach_override(['environment_id' => 'a-prod', 'domain' => self::DOMAIN, 'instance_uid' => 'class-x', 'definition_uid' => 'def-a']);
        $this->assertTrue($detached['changed']);
        $row = $this->row($status->status('a-prod'));
        $this->assertSame('local_drift', $row['drift']);
        $this->assertNull($row['override_hash']);
        $this->assertSame(OverrideStore::STATE_DETACHED, $row['override_state']);

        // Ahead of the desired version, and a channel mismatch when the adopted version lives on another channel.
        $service->publish($this->definition('1.2', 'v12'));
        $service->adopt_version($subscription_id, '1.2');
        $this->assertSame('ahead_version', $this->row($status->status('a-prod'))['version']);
        $service->publish($this->definition('2.0-beta', 'v2b', ['channel' => 'beta']));
        $service->adopt_version($subscription_id, '2.0-beta');
        $this->assertSame('channel_mismatch', $this->row($status->status('a-prod'))['version']);

        // Stale contact → unknown drift, even with a matching hash.
        $service->adopt_version($subscription_id, '1.1');
        $this->report('a-prod', [$this->class('class-x', 'v11')]);
        $this->assertSame('clean', $this->row($status->status('a-prod'))['drift']);
        (new EnvironmentRegistry())->update('a-prod', ['last_contact_at' => '2000-01-01 00:00:00']);
        $row = $this->row($status->status('a-prod'));
        $this->assertSame('unknown', $row['drift']);
        $this->assertContains('stale', $row['reasons']);
        $this->assertFalse($row['fresh']);

        // Reporting touched no adoption, definition or override rows.
        $this->assertSame('1.1', (new SubscriptionStore())->get($subscription_id)['adopted_version']);
        $this->assertSame(4, count((new DefinitionStore())->all('def-a')));
        $this->assertSame(1, count((new OverrideStore())->all()));
    }

    public function test_subscriptions_without_adoption_or_desired_version_report_unknown(): void
    {
        $status = new FrameworkStatusService();
        (new SubscriptionService())->subscribe_framework('a-prod', self::DOMAIN, 'class-x', 'def-none');
        $this->report('a-prod', [$this->class('class-x', 'x1')]);
        $row = $this->row($status->status('a-prod'));
        $this->assertSame(['drift' => 'unknown', 'version' => 'unknown'], ['drift' => $row['drift'], 'version' => $row['version']]);
        $this->assertSame(['no_adopted_version', 'no_desired_version'], $row['reasons']);
        $this->assertNull($row['profile']);

        // Overrides need a subscription; a subscription on another client's environment is invisible to this one.
        $service = new FrameworkService();
        $this->assertSame('dbvc_agency_subscription_not_found', $service->approve_override(['environment_id' => 'b-prod', 'domain' => self::DOMAIN, 'instance_uid' => 'class-x', 'definition_uid' => 'def-none', 'rationale' => 'x'])->get_error_code());
        $this->assertSame('dbvc_agency_definition_not_found', $service->approve_override(['environment_id' => 'a-prod', 'domain' => self::DOMAIN, 'instance_uid' => 'class-x', 'definition_uid' => 'def-none', 'rationale' => 'x'])->get_error_code(), 'Without any published version the profile is unknown; an explicit hash is required.');
        $explicit = $service->approve_override(['environment_id' => 'a-prod', 'domain' => self::DOMAIN, 'instance_uid' => 'class-x', 'definition_uid' => 'def-none', 'rationale' => 'x', 'hash' => $this->hash('x1')]);
        $this->assertSame($this->hash('x1'), $explicit['approved_hash']);
        $this->assertSame('unknown', $this->row($status->status('a-prod'))['drift'], 'An override alone proves nothing without an adopted definition.');
        $this->assertSame([], $status->status('b-prod')['rows']);
    }

    public function test_review_items_are_resolved_through_the_status_report_never_by_a_second_classifier(): void
    {
        $service = new FrameworkService();
        $reviews = new ReviewStore();
        $resolution = new ReviewResolutionService();
        $this->assertSame(HubSchema::SCHEMA_VERSION, (int) get_option(HubSchema::OPTION_SCHEMA_VERSION));
        $service->publish($this->definition('1.0', 'v1', ['desired' => true]));
        (new SubscriptionService())->subscribe_framework('a-prod', self::DOMAIN, 'class-x', 'def-a', '1.0');

        // Routing after receipt creates one raw item per observation; classification is a separate, explicit step.
        $this->report('a-prod', [$this->class('class-x', 'v1')]);
        $items = $reviews->all('def-a');
        $this->assertCount(1, $items);
        $this->assertSame(ReviewStore::STATE_OBSERVED, $items[0]['state']);
        $this->assertSame(1, $items[0]['event_sequence']);
        $this->assertSame($this->env['a-prod']['epoch'], $items[0]['installation_epoch']);
        $this->assertSame('', $items[0]['drift']);

        // Clean and current: resolved automatically, with the state it was judged under.
        $summary = $resolution->classify();
        $this->assertSame(['examined' => 1, 'classified' => 0, 'resolved' => 1, 'unmatched' => 0], array_intersect_key($summary, ['examined' => 1, 'classified' => 1, 'resolved' => 1, 'unmatched' => 1]));
        $item = $reviews->get($items[0]['review_item_id']);
        $this->assertSame([ReviewStore::STATE_RESOLVED, 'clean', 'current', ReviewResolutionService::RESOLUTION_CLEAN], [$item['state'], $item['drift'], $item['version_state'], $item['resolution']]);

        // A drifting edit stays open as classified; a further edit supersedes the first item.
        $this->report('a-prod', [$this->class('class-x', 'edited')]);
        $summary = $resolution->classify('a-prod');
        $this->assertSame(1, $summary['classified']);
        $open = $reviews->all('def-a', 200, ReviewStore::STATE_CLASSIFIED);
        $this->assertCount(1, $open);
        $this->assertSame(['local_drift', 'current'], [$open[0]['drift'], $open[0]['version_state']]);
        $this->report('a-prod', [$this->class('class-x', 'edited-again')]);
        $summary = $resolution->classify('a-prod');
        $this->assertSame(1, $summary['resolutions'][ReviewResolutionService::RESOLUTION_SUPERSEDED] ?? 0);
        $this->assertSame(1, $summary['classified'], 'The latest observation is still open.');
        $this->assertSame(ReviewResolutionService::RESOLUTION_SUPERSEDED, $reviews->get($open[0]['review_item_id'])['resolution']);
        $latest = $reviews->all('def-a', 200, ReviewStore::STATE_CLASSIFIED)[0];
        $this->assertSame(3, $latest['event_sequence']);

        // Approval makes the instance approved_override/current: the open item resolves; a rebase-flagged override does not.
        $service->approve_override(['environment_id' => 'a-prod', 'domain' => self::DOMAIN, 'instance_uid' => 'class-x', 'definition_uid' => 'def-a', 'rationale' => 'brand']);
        $this->assertSame(1, $resolution->classify()['resolutions'][ReviewResolutionService::RESOLUTION_APPROVED_OVERRIDE] ?? 0);
        $this->assertSame(ReviewStore::STATE_RESOLVED, $reviews->get($latest['review_item_id'])['state']);
        $service->publish($this->definition('1.1', 'v11', ['desired' => true]));
        $this->report('a-prod', [$this->class('class-x', 'edited-again')]);
        $this->assertSame(0, (new ProjectionStore())->get('a-prod', $this->env['a-prod']['epoch'], self::DOMAIN, 'class-x', self::PROFILE)['observed_sequence'] - 4, 'Same hash, newer sequence.');
        $summary = $resolution->classify();
        $this->assertSame(1, $summary['classified'], 'behind_version with a needs_rebase_review override is not auto-resolved.');
        $stuck = $reviews->all('def-a', 200, ReviewStore::STATE_CLASSIFIED)[0];
        $this->assertSame(['approved_override', 'behind_version'], [$stuck['drift'], $stuck['version_state']]);

        // Operator resolution needs a note and records the actor; a resolved item is left alone by later classification.
        $this->assertSame('dbvc_agency_note_required', $resolution->resolve($stuck['review_item_id'])->get_error_code());
        $this->assertSame('dbvc_agency_review_not_found', $resolution->resolve(999999, 'x')->get_error_code());
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $resolved = $resolution->resolve($stuck['review_item_id'], 'rebase scheduled for next sprint');
        $this->assertTrue($resolved['changed']);
        $item = $reviews->get($stuck['review_item_id']);
        $this->assertSame([ReviewStore::STATE_RESOLVED, ReviewResolutionService::RESOLUTION_OPERATOR, 'rebase scheduled for next sprint'], [$item['state'], $item['resolution'], $item['note']]);
        $this->assertSame(get_current_user_id(), $item['resolved_by']);
        $this->assertFalse($resolution->resolve($stuck['review_item_id'], 'again')['changed']);
        $this->assertSame(0, $resolution->classify()['examined']);
        $this->assertSame(['total' => 4, ReviewStore::STATE_RESOLVED => 4], $reviews->counts());

        // An item whose subscription was disabled has no status row: left untouched, counted as unmatched.
        $subscription_id = (new SubscriptionStore())->framework_subscriptions('a-prod', 'def-a')[0]['subscription_id'];
        $this->report('a-prod', [$this->class('class-x', 'v11')]);
        (new SubscriptionService())->set_enabled($subscription_id, false);
        $summary = $resolution->classify();
        $this->assertSame(['examined' => 1, 'classified' => 0, 'resolved' => 0, 'unmatched' => 1], array_intersect_key($summary, ['examined' => 1, 'unmatched' => 1, 'classified' => 1, 'resolved' => 1]));
        $this->assertSame(ReviewStore::STATE_OBSERVED, $reviews->all('def-a', 200, ReviewStore::STATE_OBSERVED)[0]['state']);

        // Nothing here touched the projection, the definitions or the override.
        $this->assertSame($this->hash('v11'), (new ProjectionStore())->get('a-prod', $this->env['a-prod']['epoch'], self::DOMAIN, 'class-x', self::PROFILE)['semantic_hash']);
        $this->assertSame(2, count((new DefinitionStore())->all('def-a')));
        $this->assertSame(OverrideStore::STATE_NEEDS_REBASE_REVIEW, (new OverrideStore())->find('a-prod', self::DOMAIN, 'class-x', 'def-a')['state']);
    }

    public function test_protocol_framework_matrix(): void
    {
        $this->assertSame(['drift' => 'clean', 'version' => 'current'], Comparison::framework('a', 'a', '1', '1'));
        $this->assertSame(['drift' => 'local_drift', 'version' => 'version_differs'], Comparison::framework('b', 'a', '1', '2'));
        $this->assertSame(['drift' => 'approved_override', 'version' => 'current'], Comparison::framework('b', 'a', '1', '1', 'b'));
        $this->assertSame(['drift' => 'override_changed', 'version' => 'current'], Comparison::framework('c', 'a', '1', '1', 'b'));
        $this->assertSame(['drift' => 'unknown', 'version' => 'unknown'], Comparison::framework(null, 'a', null, '1'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $status): array
    {
        $this->assertCount(1, $status['rows'], wp_json_encode($status));

        return $status['rows'][0];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function definition(string $version, string $seed, array $extra = []): array
    {
        return array_merge(['agency_id' => 'studio', 'definition_uid' => 'def-a', 'version' => $version, 'domain' => self::DOMAIN, 'profile' => self::PROFILE, 'hash' => $this->hash($seed)], $extra);
    }

    private function report(string $environment_id, array $events): void
    {
        $env = $this->env[$environment_id];
        $bodies = [];
        foreach ($events as $event) {
            $this->sequence[$environment_id]++;
            $bodies[] = ObservationEvent::build(array_merge($event, [
                'event_id' => 'obs-' . str_pad((string) $this->sequence[$environment_id], 12, '0', STR_PAD_LEFT),
                'environment_id' => $env['environment_id'],
                'installation_epoch' => $env['epoch'],
                'sequence' => $this->sequence[$environment_id],
                'observed_at' => '2026-09-19T10:00:00Z',
            ]));
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

    private function class(string $uid, string $seed, bool $complete = true): array
    {
        return ['object' => ['domain' => self::DOMAIN, 'instance_uid' => $uid], 'projection' => ['profile' => self::PROFILE, 'hash' => $this->hash($seed), 'exists' => true, 'complete' => $complete]];
    }

    private function tombstone(string $uid): array
    {
        return ['object' => ['domain' => self::DOMAIN, 'instance_uid' => $uid], 'projection' => ['profile' => self::PROFILE, 'hash' => \Dbvc\AgencyControl\Comparison\ComparisonService::absent_hash(), 'exists' => false, 'complete' => true]];
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
