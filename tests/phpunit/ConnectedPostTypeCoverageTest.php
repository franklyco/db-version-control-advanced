<?php

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Adapters\PostTypeObserver;
use Dbvc\Connected\Adapters\ServicePostObserver;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * M8 slice 1 — universal post-type coverage. A second custom post type is
 * observed as its own `wp.post:<type>` domain through the parameterized
 * PostTypeObserver, with `wp.service` retained as a back-compat alias, the
 * `ObservationEvent` domain pattern accepting `wp.post:<type>`, and per-type
 * meta allow/deny + create-missing-terms controls.
 *
 * @group connected-environments
 */
class ConnectedPostTypeCoverageTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private const CPT = 'portfolio';

    /**
     * @var string
     */
    private const DOMAIN = 'wp.post:portfolio';

    /**
     * @var string
     */
    private const PROFILE = 'wp-post:portfolio-v1';

    /**
     * @var string
     */
    private const INVENTORY_PROFILE = 'wp-post:portfolio-inventory-v1';

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_connected_bricks_available', '__return_true');
        add_filter('dbvc_connected_processing_delay', '__return_zero');
        add_filter('dbvc_should_export_on_option_update', '__return_false');
        add_filter('pre_http_request', [$this, 'trap_http'], 10, 3);
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        register_post_type(self::CPT, ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        register_taxonomy('portfolio_cat', self::CPT);
        // DBVC assigns vf_object_uid on save for its configured post types; both must be listed.
        update_option('dbvc_post_types', ['service', self::CPT]);
        // Opt the second CPT into universal coverage; the alias still owns `service`.
        update_option(DomainRegistry::OPTION_POST_DOMAINS, [self::CPT]);
        delete_option('dbvc_mask_meta_keys');
        delete_option('dbvc_mask_subkeys');
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        DomainRegistry::reset_observers();
        Schema::reset_memo();
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        // Two Bricks collections + the wp.service alias + the opted-in wp.post:portfolio.
        $settled = $this->run_worker();
        $this->assertSame(4, $settled['acknowledged'], wp_json_encode($settled['jobs']));
    }

    public function tear_down(): void
    {
        unregister_taxonomy('portfolio_cat');
        unregister_post_type(self::CPT);
        unregister_post_type('service');
        DBVC_Connected_Environments_Addon::save_settings([]);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        remove_filter('dbvc_connected_processing_delay', '__return_zero');
        remove_filter('dbvc_should_export_on_option_update', '__return_false');
        remove_filter('pre_http_request', [$this, 'trap_http'], 10);
        delete_option('dbvc_post_types');
        delete_option(DomainRegistry::OPTION_POST_DOMAINS);
        delete_option('dbvc_mask_meta_keys');
        delete_option('dbvc_mask_action');
        DomainRegistry::reset_observers();
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

    public function test_protocol_domain_pattern_accepts_wp_post_and_keeps_the_base_domains(): void
    {
        // Base domains still valid.
        $this->assertTrue(ObservationEvent::isDomain('bricks.global_class'));
        $this->assertTrue(ObservationEvent::isDomain('bricks.variable'));
        $this->assertTrue(ObservationEvent::isDomain('wp.service'));
        // Universal post domains.
        $this->assertTrue(ObservationEvent::isDomain('wp.post:portfolio'));
        $this->assertTrue(ObservationEvent::isDomain('wp.post:my_type'));
        $this->assertTrue(ObservationEvent::isDomain('wp.post:with-dash'));
        // Rejected shapes.
        $this->assertFalse(ObservationEvent::isDomain('wp.post:'), 'The slug is required.');
        $this->assertFalse(ObservationEvent::isDomain('wp.post:Bad Slug'), 'Only sanitized slugs.');
        $this->assertFalse(ObservationEvent::isDomain('wp.post:UPPER'), 'Slugs are lowercase.');
        $this->assertFalse(ObservationEvent::isDomain('wp.page'), 'Only the wp.post: prefix.');
        $this->assertFalse(ObservationEvent::isDomain('wp.post:x' . str_repeat('y', 200)), 'Bounded length.');
        $this->assertFalse(ObservationEvent::isDomain(123));

        $valid = ObservationEvent::build([
            'event_id' => 'obs-000000000001',
            'environment_id' => 'env-1',
            'installation_epoch' => 'epoch-1',
            'sequence' => 1,
            'observed_at' => '2026-09-24T00:00:00Z',
            'object' => ['domain' => self::DOMAIN, 'instance_uid' => 'vf-portfolio-1'],
            'projection' => ['profile' => self::PROFILE, 'hash' => str_repeat('a', 64), 'exists' => true, 'complete' => true],
        ]);
        $this->assertSame([], ObservationEvent::validate($valid), 'A wp.post:<type> event validates.');

        $invalid = $valid;
        $invalid['object']['domain'] = 'wp.post:Bad Slug';
        $this->assertContains('invalid:object.domain', ObservationEvent::validate($invalid));
    }

    public function test_domain_registry_generates_post_domains_from_the_allow_list(): void
    {
        // Dedup the alias's type, and never register excluded built-ins even if opted in.
        update_option(DomainRegistry::OPTION_POST_DOMAINS, [self::CPT, 'service', 'attachment', 'revision', self::CPT]);
        DomainRegistry::reset_observers();

        $this->assertSame([self::CPT], DomainRegistry::opted_in_post_types(), 'Service is the alias; built-ins and duplicates are dropped.');

        $definitions = DomainRegistry::definitions();
        $this->assertArrayHasKey(self::DOMAIN, $definitions);
        $this->assertArrayNotHasKey('wp.post:service', $definitions, 'wp.service already covers the service type.');
        $this->assertArrayNotHasKey('wp.post:attachment', $definitions, 'Media has its own channel.');
        $this->assertArrayNotHasKey('wp.post:revision', $definitions);
        $this->assertSame(self::PROFILE, $definitions[self::DOMAIN]['profile']);
        $this->assertSame(self::INVENTORY_PROFILE, $definitions[self::DOMAIN]['inventory_profile']);
        $this->assertSame('post', $definitions[self::DOMAIN]['kind']);

        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $this->assertInstanceOf(PostTypeObserver::class, $observer);
        $this->assertNotInstanceOf(ServicePostObserver::class, $observer, 'A generic CPT is not the service alias.');
        $this->assertSame(self::CPT, $observer->post_type());
        $this->assertSame(self::PROFILE, $observer->profile());
        $this->assertSame(self::DOMAIN, $observer->domain());

        // The alias is intact and still its own class.
        $service = DomainRegistry::observer_for('wp.service');
        $this->assertInstanceOf(ServicePostObserver::class, $service);
        $this->assertSame(ServicePostObserver::PROFILE, $service->profile());

        $this->assertTrue(DomainRegistry::is_post_domain(self::DOMAIN));
        $watched = DomainRegistry::watched_post_types();
        $this->assertSame(self::DOMAIN, $watched[self::CPT] ?? null);
        $this->assertSame('wp.service', $watched['service'] ?? null);
        $this->assertContains(self::DOMAIN, DomainRegistry::post_domains());
        $this->assertContains('wp.service', DomainRegistry::post_domains());
    }

    public function test_second_cpt_is_observed_end_to_end_under_its_own_domain(): void
    {
        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $this->assertTrue($observer->capabilities()['report']);
        $this->assertTrue($observer->capabilities()['identity_available'], 'The CPT is a DBVC post type, so it has portable identity.');

        $post_id = wp_insert_post(['post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => 'Case study', 'post_content' => 'Body v1', 'post_name' => 'case-study']);
        update_post_meta($post_id, 'client', 'Acme');
        wp_set_object_terms($post_id, ['web', 'brand'], 'portfolio_cat');
        $uid = (string) get_post_meta($post_id, 'vf_object_uid', true);
        $this->assertNotSame('', $uid, 'DBVC assigned portable identity to the opted-in CPT.');

        $job = (new JobStore())->find(self::DOMAIN, 'post:' . $post_id);
        $this->assertIsArray($job, 'The save-side marker is recorded under the wp.post:<type> domain.');

        $summary = $this->run_worker();
        $portfolio = array_values(array_filter($summary['jobs'], static function ($job) {
            return $job['domain'] === self::DOMAIN && strpos((string) $job['object_key'], 'post:') === 0;
        }))[0];
        $this->assertSame('acknowledged', $portfolio['outcome']);
        $this->assertSame(2, $portfolio['events'], 'The post plus the inventory projection that now lists it.');
        $this->assertSame(0, $portfolio['identities_assigned'], 'The domain owns identity via vf_object_uid.');

        $object = (new ObjectStore())->get(self::DOMAIN, $uid, self::PROFILE);
        $this->assertIsArray($object, 'The projection is stored keyed on the CPT domain + profile.');
        $this->assertSame((string) $post_id, $object['storage_key']);
        $this->assertSame(1, $object['snapshot_complete']);
        $canonical = json_decode($object['snapshot_body'], true);
        $this->assertSame('Case study', $canonical['post_title']);
        $this->assertSame(['Acme'], $canonical['meta']['client']);
        $this->assertSame(['brand', 'web'], array_column($canonical['tax_input']['portfolio_cat'], 'slug'));

        $event = $this->event_for($uid);
        $this->assertSame(self::DOMAIN, $event['body']['object']['domain']);
        $this->assertSame(self::PROFILE, $event['body']['projection']['profile']);
        $this->assertSame([], ObservationEvent::validate($event['body']), 'The emitted event is protocol-valid.');

        // The inventory rides its own per-type profile.
        $inventory = (new ObjectStore())->get(self::DOMAIN, BricksOptionCollectionObserver::ORDER_INSTANCE_UID, self::INVENTORY_PROFILE);
        $this->assertIsArray($inventory);
        $this->assertSame(1, $inventory['snapshot_complete']);
        $this->assertSame([$uid], json_decode($inventory['snapshot_body'], true));

        // The wp.service alias is a separate domain and is untouched by the CPT's activity.
        $this->assertNull((new ObjectStore())->get('wp.service', $uid, ServicePostObserver::PROFILE));
    }

    public function test_per_type_meta_allow_and_deny_shape_the_projection(): void
    {
        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $post_id = wp_insert_post(['post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => 'Meta policy']);
        update_post_meta($post_id, 'keep_me', 'yes');
        update_post_meta($post_id, 'drop_me', 'no');
        update_post_meta($post_id, 'also_dropped', 'no');

        // Deny list: a single key is excluded; the projection stays complete (it is out of scope, not masked).
        $deny = function ($ignored, $type) {
            return $type === self::CPT ? array_merge((array) $ignored, ['drop_me']) : $ignored;
        };
        add_filter('dbvc_connected_post_ignored_meta_keys', $deny, 10, 2);
        $denied = json_decode($observer->snapshot(['storage_key' => (string) $post_id])['canonical'], true);
        remove_filter('dbvc_connected_post_ignored_meta_keys', $deny, 10);
        $this->assertArrayHasKey('keep_me', $denied['meta']);
        $this->assertArrayNotHasKey('drop_me', $denied['meta'], 'A per-type denied key never projects.');
        $this->assertArrayHasKey('also_dropped', $denied['meta']);

        // Allow list: only the named key projects, everything else is out of scope.
        $allow = function ($allow, $type) {
            return $type === self::CPT ? ['keep_me'] : $allow;
        };
        add_filter('dbvc_connected_post_meta_allow', $allow, 10, 2);
        $snapshot = $observer->snapshot(['storage_key' => (string) $post_id]);
        remove_filter('dbvc_connected_post_meta_allow', $allow, 10);
        $allowed = json_decode($snapshot['canonical'], true);
        $this->assertSame(['keep_me'], array_keys($allowed['meta']), 'Only allow-listed meta keys are projected.');
        $this->assertTrue($snapshot['complete'], 'An allow-list narrows scope; it does not mask, so the projection stays complete.');
    }

    public function test_create_missing_terms_is_off_by_default_and_opt_in_per_type(): void
    {
        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $this->assertFalse($observer->create_terms(), 'Create-missing-terms defaults off for a generic CPT.');

        $post_id = wp_insert_post(['post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => 'Terms']);
        $body = [
            'post_status' => 'publish',
            'post_title' => 'Terms',
            'post_name' => 'terms',
            'post_content' => '',
            'post_excerpt' => '',
            'post_date_gmt' => '',
            'post_parent_uid' => '',
            'menu_order' => 0,
            'tax_input' => ['portfolio_cat' => [['slug' => 'fresh-cat', 'name' => 'Fresh Cat']]],
            'meta' => [],
        ];

        // Off: a missing term is neither created nor assigned.
        $this->assertTrue($observer->apply_body($post_id, $body) === true);
        $this->assertFalse(get_term_by('slug', 'fresh-cat', 'portfolio_cat'), 'No term is created when the policy is off.');
        $this->assertSame([], wp_get_object_terms($post_id, 'portfolio_cat', ['fields' => 'slugs']));

        // On (per-type filter): the term is created and assigned.
        $create = function ($create, $type) {
            return $type === self::CPT ? true : $create;
        };
        add_filter('dbvc_connected_post_create_terms', $create, 10, 2);
        $this->assertTrue($observer->create_terms());
        $this->assertTrue($observer->apply_body($post_id, $body) === true);
        remove_filter('dbvc_connected_post_create_terms', $create, 10);
        $this->assertInstanceOf(WP_Term::class, get_term_by('slug', 'fresh-cat', 'portfolio_cat'), 'The term is created when the policy is on.');
        $this->assertSame(['fresh-cat'], wp_get_object_terms($post_id, 'portfolio_cat', ['fields' => 'slugs']));
    }

    /**
     * Latest event for one instance UID on the CPT domain.
     *
     * @return array<string, mixed>
     */
    private function event_for(string $uid): array
    {
        $match = null;
        foreach ((new OutboxStore())->all(null, 500) as $event) {
            if ($event['domain'] === self::DOMAIN && $event['body']['object']['instance_uid'] === $uid) {
                $match = $event;
            }
        }
        $this->assertIsArray($match, 'No event for ' . $uid);

        return $match;
    }

    /**
     * @return array<string, mixed>
     */
    private function run_worker(): array
    {
        return DBVC_Connected_Environments_Addon::runtime()->worker()->run(['context' => 'test', 'budget_seconds' => 30]);
    }
}
