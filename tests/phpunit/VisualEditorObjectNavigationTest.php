<?php

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\FrontendRuntimeGuard;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Navigation\ObjectNavigationReadModel;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Rest\Controllers\ObjectSearchController;

/**
 * R6-A: object-navigation read model + `object-search` route coverage.
 *
 * The route had no mechanical coverage before R6; this suite pins the
 * backward-compatible popover contract AND the new paginated / typed /
 * honest-route contract the R6 workspace consumes. Fixtures deliberately
 * mix post types, statuses, taxonomies, exclusions, attachments, and a
 * custom-capability CPT so the permission policy is exercised end to end.
 */
final class VisualEditorObjectNavigationTest extends WP_UnitTestCase
{
    private const COOKIE_NAME = 'dbvc_visual_editor_mode';
    private const PUBLIC_CPT = 'dbvc_nav_item';
    private const HIDDEN_CPT = 'dbvc_nav_hidden';
    private const EXCLUDED_CPT = 'dbvc_nav_excl';
    private const CAPPED_CPT = 'dbvc_nav_capped';
    private const PUBLIC_TAX = 'dbvc_nav_tax';
    private const NOQUERY_TAX = 'dbvc_nav_noquery';
    private const EXCLUDED_TAX = 'dbvc_nav_excl_tax';

    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var int
     */
    private $administrator_id = 0;

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, self::EXCLUDED_CPT);
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, self::EXCLUDED_TAX);

        register_post_type(self::PUBLIC_CPT, [
            'label' => 'Nav Items',
            'labels' => ['name' => 'Nav Items', 'singular_name' => 'Nav Item'],
            'public' => true,
            'show_ui' => true,
            'supports' => ['title'],
        ]);
        register_post_type(self::HIDDEN_CPT, [
            'label' => 'Hidden',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
        ]);
        register_post_type(self::EXCLUDED_CPT, [
            'label' => 'Excluded',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title'],
        ]);
        register_post_type(self::CAPPED_CPT, [
            'label' => 'Capped',
            'labels' => ['name' => 'Capped Items', 'singular_name' => 'Capped Item'],
            'public' => true,
            'show_ui' => true,
            'supports' => ['title'],
            'capability_type' => 'dbvc_navcap',
            'map_meta_cap' => true,
        ]);
        register_taxonomy(self::PUBLIC_TAX, self::PUBLIC_CPT, [
            'label' => 'Nav Tax',
            'labels' => ['name' => 'Nav Taxes', 'singular_name' => 'Nav Tax'],
            'public' => true,
            'show_ui' => true,
        ]);
        register_taxonomy(self::NOQUERY_TAX, self::PUBLIC_CPT, [
            'label' => 'No Query Tax',
            'public' => true,
            'publicly_queryable' => false,
            'show_ui' => true,
        ]);
        register_taxonomy(self::EXCLUDED_TAX, self::PUBLIC_CPT, [
            'label' => 'Excluded Tax',
            'public' => true,
            'show_ui' => true,
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        // Custom `capability_type` caps are not implicit for administrators;
        // grant the full set so the admin fixture can author + see every
        // capped post (the limited-user test below grants a subset).
        $administrator = new WP_User($this->administrator_id);
        foreach ([
            'edit_dbvc_navcaps',
            'edit_others_dbvc_navcaps',
            'edit_private_dbvc_navcaps',
            'edit_published_dbvc_navcaps',
            'publish_dbvc_navcaps',
            'read_private_dbvc_navcaps',
        ] as $cap) {
            $administrator->add_cap($cap);
        }
        wp_set_current_user($this->administrator_id);
        $_COOKIE[self::COOKIE_NAME] = '1';
    }

    public function tear_down(): void
    {
        unset($_COOKIE[self::COOKIE_NAME]);

        foreach ([self::PUBLIC_TAX, self::NOQUERY_TAX, self::EXCLUDED_TAX] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                unregister_taxonomy($taxonomy);
            }
        }

        foreach ([self::PUBLIC_CPT, self::HIDDEN_CPT, self::EXCLUDED_CPT, self::CAPPED_CPT] as $post_type) {
            if (post_type_exists($post_type)) {
                unregister_post_type($post_type);
            }
        }

        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }

        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // Route transport
    // ---------------------------------------------------------------------

    public function test_route_returns_403_when_edit_mode_is_inactive(): void
    {
        unset($_COOKIE[self::COOKIE_NAME]);

        $response = $this->controller()->handle($this->request([]));

        $this->assertSame(403, $response->get_status());
        $this->assertFalse($response->get_data()['ok']);
    }

    public function test_route_keeps_legacy_popover_contract_and_widens_it(): void
    {
        $page_id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Legacy Page']);

        $response = $this->controller()->handle($this->request(['objectType' => 'all', 'limit' => 5]));
        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();

        $this->assertTrue($body['ok']);
        $this->assertSame(1, $body['page']);
        $this->assertSame(5, $body['perPage'], '`limit` remains an alias for `perPage`');
        $this->assertFalse($body['hasMore']);
        $this->assertSame(['search' => '', 'objectType' => 'all', 'subtype' => '', 'sort' => 'recent'], $body['query']);
        $this->assertArrayNotHasKey('types', $body, 'types are opt-in via includeTypes');

        $item = $this->findItem($body['items'], 'post', $page_id);
        $this->assertNotNull($item);
        foreach (['objectType', 'id', 'title', 'subtype', 'typeLabel', 'status', 'frontendUrl', 'backendUrl', 'canEdit'] as $legacy_key) {
            $this->assertArrayHasKey($legacy_key, $item, "legacy key {$legacy_key} preserved");
        }
        $this->assertSame('Legacy Page', $item['title']);
        $this->assertSame('publish', $item['statusKey']);
        $this->assertTrue($item['hasFrontendRoute']);
        $this->assertSame(get_permalink($page_id), $item['frontendUrl']);
    }

    public function test_route_caps_per_page_and_floors_page(): void
    {
        $response = $this->controller()->handle($this->request(['perPage' => 500, 'page' => 0]));
        $body = $response->get_data();

        $this->assertSame(ObjectNavigationReadModel::MAX_PER_PAGE, $body['perPage']);
        $this->assertSame(1, $body['page']);
    }

    public function test_route_returns_empty_for_unknown_object_type(): void
    {
        self::factory()->post->create(['post_type' => 'page']);

        $response = $this->controller()->handle($this->request(['objectType' => 'user']));
        $body = $response->get_data();

        $this->assertTrue($body['ok']);
        $this->assertSame([], $body['items']);
        $this->assertFalse($body['hasMore']);
        $this->assertSame('none', $body['query']['objectType']);
    }

    public function test_route_include_types_returns_policy_filtered_discovery(): void
    {
        $response = $this->controller()->handle($this->request(['includeTypes' => '1', 'perPage' => 1]));
        $body = $response->get_data();

        $this->assertArrayHasKey('types', $body);
        $keys = array_map(static function ($type) {
            return $type['objectType'] . ':' . $type['subtype'];
        }, $body['types']);

        $this->assertContains('post:page', $keys);
        $this->assertContains('post:post', $keys);
        $this->assertContains('post:' . self::PUBLIC_CPT, $keys);
        $this->assertContains('post:' . self::CAPPED_CPT, $keys);
        $this->assertContains('term:category', $keys);
        $this->assertContains('term:' . self::PUBLIC_TAX, $keys);
        $this->assertContains('term:' . self::NOQUERY_TAX, $keys);

        $this->assertNotContains('post:attachment', $keys, 'media stays in the native library');
        $this->assertNotContains('post:' . self::HIDDEN_CPT, $keys, 'non-public CPTs are not navigable');
        $this->assertNotContains('post:' . self::EXCLUDED_CPT, $keys, 'admin exclusion list honoured');
        $this->assertNotContains('term:' . self::EXCLUDED_TAX, $keys, 'admin taxonomy exclusion honoured');

        $cpt = $this->findType($body['types'], 'post', self::PUBLIC_CPT);
        $this->assertSame('Nav Items', $cpt['label']);
        $this->assertSame('Nav Item', $cpt['singularLabel']);
        $this->assertTrue($cpt['viewable']);
        $this->assertFalse($cpt['hierarchical']);

        $this->assertTrue($this->findType($body['types'], 'post', 'page')['hierarchical']);
        $this->assertFalse($this->findType($body['types'], 'term', self::NOQUERY_TAX)['viewable']);
    }

    // ---------------------------------------------------------------------
    // Object-type policy
    // ---------------------------------------------------------------------

    public function test_search_excludes_attachments_hidden_and_excluded_types(): void
    {
        $visible_id = self::factory()->post->create(['post_type' => self::PUBLIC_CPT, 'post_title' => 'Visible Item']);
        $hidden_id = self::factory()->post->create(['post_type' => self::HIDDEN_CPT, 'post_title' => 'Hidden Item']);
        $excluded_id = self::factory()->post->create(['post_type' => self::EXCLUDED_CPT, 'post_title' => 'Excluded Item']);
        $attachment_id = self::factory()->attachment->create_object('nav-item.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
            'post_title' => 'Attachment Item',
        ]);

        $result = $this->readModel()->search(['search' => 'Item', 'objectType' => 'post']);
        $ids = array_column($result['items'], 'id');

        $this->assertContains($visible_id, $ids);
        $this->assertNotContains($hidden_id, $ids);
        $this->assertNotContains($excluded_id, $ids);
        $this->assertNotContains($attachment_id, $ids);
    }

    public function test_search_excludes_terms_in_excluded_taxonomies(): void
    {
        $visible = self::factory()->term->create(['taxonomy' => self::PUBLIC_TAX, 'name' => 'Visible Term']);
        $excluded = self::factory()->term->create(['taxonomy' => self::EXCLUDED_TAX, 'name' => 'Excluded Term']);

        $result = $this->readModel()->search(['search' => 'Term', 'objectType' => 'term']);
        $ids = array_column($result['items'], 'id');

        $this->assertContains($visible, $ids);
        $this->assertNotContains($excluded, $ids);
    }

    public function test_subtype_filter_narrows_to_one_type(): void
    {
        $page_id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Subtype Page']);
        $post_id = self::factory()->post->create(['post_type' => 'post', 'post_title' => 'Subtype Post']);

        $result = $this->readModel()->search(['search' => 'Subtype', 'objectType' => 'post', 'subtype' => 'page']);
        $ids = array_column($result['items'], 'id');

        $this->assertSame([$page_id], $ids);
        $this->assertNotContains($post_id, $ids);
    }

    public function test_all_interleaves_posts_and_terms(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Interleave Page']);
        $term_id = self::factory()->term->create(['taxonomy' => self::PUBLIC_TAX, 'name' => 'Interleave Term']);

        $result = $this->readModel()->search(['search' => 'Interleave', 'objectType' => 'all']);
        $types = array_column($result['items'], 'objectType');

        $this->assertSame(['post', 'term'], $types);
        $this->assertSame($post_id, $result['items'][0]['id']);
        $this->assertSame($term_id, $result['items'][1]['id']);
    }

    // ---------------------------------------------------------------------
    // Honest frontend routes
    // ---------------------------------------------------------------------

    public function test_drafts_have_no_frontend_route_but_keep_backend_edit(): void
    {
        $draft_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Route Draft']);
        $pending_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'pending', 'post_title' => 'Route Pending']);
        $future_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'future',
            'post_title' => 'Route Future',
            'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ]);
        $published_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Route Published']);

        $result = $this->readModel()->search(['search' => 'Route', 'objectType' => 'post']);

        foreach ([$draft_id => 'draft', $pending_id => 'pending', $future_id => 'future'] as $id => $status) {
            $item = $this->findItem($result['items'], 'post', $id);
            $this->assertNotNull($item, "{$status} post is still listed (editable)");
            $this->assertFalse($item['hasFrontendRoute'], "{$status} has no live frontend route");
            $this->assertSame('', $item['frontendUrl'], "{$status} frontendUrl is not invented");
            $this->assertSame($status, $item['statusKey']);
            $this->assertNotSame('', $item['backendUrl'], "{$status} keeps the backend edit link");
        }

        $published = $this->findItem($result['items'], 'post', $published_id);
        $this->assertTrue($published['hasFrontendRoute']);
        $this->assertSame(get_permalink($published_id), $published['frontendUrl']);
    }

    public function test_private_posts_have_frontend_route_only_for_readers(): void
    {
        $private_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Route Private']);

        $item = $this->findItem($this->readModel()->search(['search' => 'Route Private', 'objectType' => 'post'])['items'], 'post', $private_id);

        $this->assertNotNull($item);
        $this->assertTrue($item['hasFrontendRoute'], 'administrator can read private posts on the frontend');
        $this->assertSame('private', $item['statusKey']);
    }

    public function test_terms_in_non_queryable_taxonomies_have_no_frontend_route(): void
    {
        $public_term = self::factory()->term->create(['taxonomy' => self::PUBLIC_TAX, 'name' => 'Archive Public']);
        $noquery_term = self::factory()->term->create(['taxonomy' => self::NOQUERY_TAX, 'name' => 'Archive Hidden']);

        $result = $this->readModel()->search(['search' => 'Archive', 'objectType' => 'term']);

        $public = $this->findItem($result['items'], 'term', $public_term);
        $this->assertTrue($public['hasFrontendRoute']);
        $this->assertSame(get_term_link($public_term), $public['frontendUrl']);
        $this->assertNotSame('', $public['backendUrl']);

        $hidden = $this->findItem($result['items'], 'term', $noquery_term);
        $this->assertNotNull($hidden, 'term is still navigable via backend edit');
        $this->assertFalse($hidden['hasFrontendRoute']);
        $this->assertSame('', $hidden['frontendUrl']);
        $this->assertNotSame('', $hidden['backendUrl']);
    }

    // ---------------------------------------------------------------------
    // Pagination
    // ---------------------------------------------------------------------

    public function test_post_pagination_is_bounded_stable_and_non_overlapping(): void
    {
        $created = [];
        for ($index = 0; $index < 25; $index++) {
            $created[] = self::factory()->post->create([
                'post_type' => self::PUBLIC_CPT,
                'post_title' => sprintf('Paged Item %02d', $index),
                'post_date' => gmdate('Y-m-d H:i:s', time() - ($index * MINUTE_IN_SECONDS)),
            ]);
        }

        $seen = [];
        $pages = [];
        foreach ([1, 2, 3] as $page) {
            $result = $this->readModel()->search([
                'objectType' => 'post',
                'subtype' => self::PUBLIC_CPT,
                'page' => $page,
                'perPage' => 10,
            ]);
            $pages[$page] = $result;
            foreach ($result['items'] as $item) {
                $this->assertNotContains($item['id'], $seen, 'no object appears on two pages');
                $seen[] = $item['id'];
            }
        }

        $this->assertCount(10, $pages[1]['items']);
        $this->assertTrue($pages[1]['hasMore']);
        $this->assertCount(10, $pages[2]['items']);
        $this->assertTrue($pages[2]['hasMore']);
        $this->assertCount(5, $pages[3]['items']);
        $this->assertFalse($pages[3]['hasMore']);
        $this->assertSame(3, $pages[3]['page']);

        sort($seen);
        sort($created);
        $this->assertSame($created, $seen, 'three pages cover exactly the fixture set');

        $beyond = $this->readModel()->search(['objectType' => 'post', 'subtype' => self::PUBLIC_CPT, 'page' => 4, 'perPage' => 10]);
        $this->assertSame([], $beyond['items']);
        $this->assertFalse($beyond['hasMore']);
    }

    public function test_term_pagination_reports_has_more(): void
    {
        for ($index = 0; $index < 7; $index++) {
            self::factory()->term->create(['taxonomy' => self::PUBLIC_TAX, 'name' => sprintf('Paged Term %02d', $index)]);
        }

        $first = $this->readModel()->search(['objectType' => 'term', 'subtype' => self::PUBLIC_TAX, 'page' => 1, 'perPage' => 5]);
        $second = $this->readModel()->search(['objectType' => 'term', 'subtype' => self::PUBLIC_TAX, 'page' => 2, 'perPage' => 5]);

        $this->assertCount(5, $first['items']);
        $this->assertTrue($first['hasMore']);
        $this->assertCount(2, $second['items']);
        $this->assertFalse($second['hasMore']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
    }

    public function test_search_leaves_no_posts_where_filter_behind(): void
    {
        $read_model = $this->readModel();
        $read_model->search(['objectType' => 'post']);

        $this->assertFalse(has_filter('posts_where', [$read_model, 'filterPostsWhere']));
        $this->assertFalse(has_filter('posts_orderby', [$read_model, 'filterPostsOrderBy']));
    }

    public function test_untitled_posts_sort_last_under_both_title_orders(): void
    {
        $untitled_a = self::factory()->post->create(['post_type' => 'page', 'post_title' => '']);
        $untitled_b = self::factory()->post->create(['post_type' => 'page', 'post_title' => '']);
        $zulu = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Zulu Untitled Fixture']);
        $alpha = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Alpha Untitled Fixture']);

        $ids = function (array $items) {
            return array_values(array_map(static function ($item) { return $item['id']; }, $items));
        };
        $read_model = $this->readModel();
        $q = function ($sort) use ($read_model) {
            return $read_model->search(['objectType' => 'post', 'subtype' => 'page', 'sort' => $sort, 'perPage' => 30]);
        };

        $asc = $ids($q('title_asc')['items']);
        $this->assertSame([$alpha, $zulu, $untitled_a, $untitled_b], array_values(array_intersect($asc, [$alpha, $zulu, $untitled_a, $untitled_b])), 'A → Z: titled first, untitled last by ID');
        $this->assertSame(count($asc) - 2, array_search($untitled_a, $asc, true), 'untitled rows are the tail of the page, not just after the fixtures');

        $desc = $ids($q('title_desc')['items']);
        $this->assertSame([$zulu, $alpha, $untitled_b, $untitled_a], array_values(array_intersect($desc, [$alpha, $zulu, $untitled_a, $untitled_b])), 'Z → A: titled first, untitled last by ID DESC');

        // Untitled rows keep their display fallback and are not dropped.
        $item = $this->findItem($q('title_asc')['items'], 'post', $untitled_a);
        $this->assertNotNull($item);
        $this->assertNotSame('', $item['title']);

        // Non-title orders are untouched by the orderby filter.
        $recent = $ids($q('recent')['items']);
        $this->assertContains($untitled_b, array_slice($recent, 0, 4), 'recent order is unaffected (fixtures are the newest rows)');
    }

    // ---------------------------------------------------------------------
    // Permission boundary
    // ---------------------------------------------------------------------

    public function test_user_without_edit_others_cap_sees_only_own_capped_posts_with_stable_pages(): void
    {
        $other_author = $this->administrator_id;
        $limited = self::factory()->user->create(['role' => 'editor']);
        $user = new WP_User($limited);
        $user->add_cap('edit_dbvc_navcaps');
        $user->add_cap('publish_dbvc_navcaps');
        $user->add_cap('edit_published_dbvc_navcaps');
        // Deliberately NOT granted: edit_others_dbvc_navcaps, edit_private_dbvc_navcaps.

        $foreign = [];
        for ($index = 0; $index < 6; $index++) {
            $foreign[] = self::factory()->post->create([
                'post_type' => self::CAPPED_CPT,
                'post_author' => $other_author,
                'post_status' => $index % 2 === 0 ? 'publish' : 'private',
                'post_title' => sprintf('Foreign Capped %02d', $index),
                'post_date' => gmdate('Y-m-d H:i:s', time() - $index),
            ]);
        }
        $own = [];
        for ($index = 0; $index < 3; $index++) {
            $own[] = self::factory()->post->create([
                'post_type' => self::CAPPED_CPT,
                'post_author' => $limited,
                'post_title' => sprintf('Own Capped %02d', $index),
                'post_date' => gmdate('Y-m-d H:i:s', time() - 100 - $index),
            ]);
        }

        wp_set_current_user($limited);
        $this->assertTrue((new CapabilityManager())->canUseVisualEditor(), 'editor keeps the base VE capability');

        $first = $this->readModel()->search(['objectType' => 'post', 'subtype' => self::CAPPED_CPT, 'page' => 1, 'perPage' => 2]);
        $second = $this->readModel()->search(['objectType' => 'post', 'subtype' => self::CAPPED_CPT, 'page' => 2, 'perPage' => 2]);

        $this->assertCount(2, $first['items']);
        $this->assertTrue($first['hasMore']);
        $this->assertCount(1, $second['items']);
        $this->assertFalse($second['hasMore']);

        $ids = array_merge(array_column($first['items'], 'id'), array_column($second['items'], 'id'));
        sort($ids);
        sort($own);
        $this->assertSame($own, $ids, 'author restriction is applied in SQL so pages stay dense');
        foreach ($foreign as $foreign_id) {
            $this->assertNotContains($foreign_id, $ids);
        }

        $titles = array_column(array_merge($first['items'], $second['items']), 'title');
        foreach ($titles as $title) {
            $this->assertStringStartsNotWith('Foreign', $title, 'private titles of others are never exposed');
        }
    }

    public function test_user_without_type_edit_cap_does_not_see_the_type_at_all(): void
    {
        self::factory()->post->create(['post_type' => self::CAPPED_CPT, 'post_title' => 'Capped Invisible']);
        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);

        $read_model = $this->readModel();
        $this->assertNotContains(self::CAPPED_CPT, $read_model->getSearchablePostTypes(''));

        $types = array_map(static function ($type) {
            return $type['objectType'] . ':' . $type['subtype'];
        }, $read_model->describeTypes());
        $this->assertNotContains('post:' . self::CAPPED_CPT, $types);

        $result = $read_model->search(['search' => 'Capped Invisible', 'objectType' => 'post']);
        $this->assertSame([], $result['items']);
    }

    // ---------------------------------------------------------------------
    // R6.1-a — sort vocabulary
    // ---------------------------------------------------------------------

    public function test_sort_defaults_preserve_the_pre_r61_popover_behaviour(): void
    {
        $read_model = $this->readModel();

        $this->assertSame('recent', $read_model->normalizeArgs([])['sort'], 'no sort, no search → recent');
        $this->assertSame('relevance', $read_model->normalizeArgs(['search' => 'x'])['sort'], 'no sort + search → relevance (legacy)');
        $this->assertSame('relevance', $read_model->normalizeArgs(['search' => 'x', 'sort' => 'bogus'])['sort'], 'unknown key behaves like absent');
        $this->assertSame('recent', $read_model->normalizeArgs(['sort' => 'relevance'])['sort'], 'relevance without a term is meaningless → recent');
        $this->assertSame('recent', $read_model->normalizeArgs(['search' => 'x', 'sort' => 'recent'])['sort'], 'an explicit key wins over the search default');
        $this->assertSame('title_asc', $read_model->normalizeArgs(['sort' => 'TITLE_ASC'])['sort'], 'keys are sanitised');

        $this->assertSame('relevance', $read_model->resolvePostOrderBy('relevance', 'x'), 'relevance must stay a bare string for WP_Query');
        $this->assertSame(['modified' => 'DESC', 'ID' => 'DESC'], $read_model->resolvePostOrderBy('recent', ''));
        $this->assertSame(['orderby' => 'term_id', 'order' => 'DESC'], $read_model->resolveTermOrder('recent'));
    }

    public function test_route_echoes_the_applied_sort_and_orders_posts_by_each_key(): void
    {
        $base = time() - DAY_IN_SECONDS;
        // Distinct publish dates, then bumped modified dates in a different order.
        $charlie = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Charlie Sort', 'post_date' => gmdate('Y-m-d H:i:s', $base + 300)]);
        $alpha = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Alpha Sort', 'post_date' => gmdate('Y-m-d H:i:s', $base + 100)]);
        $bravo = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Bravo Sort', 'post_date' => gmdate('Y-m-d H:i:s', $base + 200)]);
        // Touch modified: alpha most recently, then charlie, then bravo.
        global $wpdb;
        foreach ([$bravo => 10, $charlie => 20, $alpha => 30] as $id => $offset) {
            $stamp = gmdate('Y-m-d H:i:s', $base + 1000 + $offset);
            $wpdb->update($wpdb->posts, ['post_modified' => $stamp, 'post_modified_gmt' => $stamp], ['ID' => $id]);
            clean_post_cache($id);
        }

        $titles = function (array $items) {
            return array_values(array_map(static function ($item) { return $item['title']; }, $items));
        };
        $controller = $this->controller();
        $req = function ($sort) use ($controller) {
            return $controller->handle($this->request(['objectType' => 'post', 'subtype' => 'page', 'search' => 'Sort', 'sort' => $sort]))->get_data();
        };

        $body = $req('title_asc');
        $this->assertSame('title_asc', $body['query']['sort']);
        $this->assertSame(['Alpha Sort', 'Bravo Sort', 'Charlie Sort'], $titles($body['items']));
        $this->assertSame(['Charlie Sort', 'Bravo Sort', 'Alpha Sort'], $titles($req('title_desc')['items']));
        $this->assertSame(['Charlie Sort', 'Bravo Sort', 'Alpha Sort'], $titles($req('newest')['items']), 'publish date, newest first');
        $this->assertSame(['Alpha Sort', 'Bravo Sort', 'Charlie Sort'], $titles($req('oldest')['items']));
        $this->assertSame(['Alpha Sort', 'Charlie Sort', 'Bravo Sort'], $titles($req('recent')['items']), 'modified date, most recently edited first');

        $relevance = $req('relevance');
        $this->assertSame('relevance', $relevance['query']['sort']);
        $this->assertCount(3, $relevance['items']);

        $legacy = $controller->handle($this->request(['objectType' => 'post', 'subtype' => 'page', 'search' => 'Sort']))->get_data();
        $this->assertSame('relevance', $legacy['query']['sort'], 'the popover (no sort param) keeps relevance while searching');
    }

    public function test_terms_order_by_each_key_and_title_sort_pages_without_overlap(): void
    {
        $names = ['Delta Term', 'Alpha Term', 'Charlie Term', 'Bravo Term'];
        $ids = [];
        foreach ($names as $name) {
            $ids[$name] = self::factory()->term->create(['taxonomy' => self::PUBLIC_TAX, 'name' => $name]);
        }
        $read_model = $this->readModel();
        $titles = function (array $result) {
            return array_values(array_map(static function ($item) { return $item['title']; }, $result['items']));
        };
        $q = function ($sort, $extra = []) use ($read_model) {
            return $read_model->search(array_merge(['objectType' => 'term', 'subtype' => self::PUBLIC_TAX, 'search' => 'Term', 'sort' => $sort], $extra));
        };

        $this->assertSame(['Alpha Term', 'Bravo Term', 'Charlie Term', 'Delta Term'], $titles($q('title_asc')));
        $this->assertSame(['Delta Term', 'Charlie Term', 'Bravo Term', 'Alpha Term'], $titles($q('title_desc')));
        $this->assertSame(['Delta Term', 'Alpha Term', 'Charlie Term', 'Bravo Term'], $titles($q('oldest')), 'creation order');
        $this->assertSame(['Bravo Term', 'Charlie Term', 'Alpha Term', 'Delta Term'], $titles($q('newest')), 'newest created first');
        $this->assertSame($titles($q('newest')), $titles($q('recent')), 'terms have no modified date: recent == newest');
        $this->assertSame('title_asc', $q('title_asc')['sort']);

        // Pagination under a non-default sort stays dense and non-overlapping.
        $seen = [];
        foreach ([1, 2] as $page) {
            $result = $q('title_asc', ['page' => $page, 'perPage' => 3]);
            foreach ($result['items'] as $item) {
                $this->assertNotContains($item['id'], $seen);
                $seen[] = $item['id'];
            }
            $this->assertSame($page === 1, $result['hasMore']);
        }
        sort($seen);
        $expected = array_values($ids);
        sort($expected);
        $this->assertSame($expected, $seen);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function readModel(): ObjectNavigationReadModel
    {
        return new ObjectNavigationReadModel(new CapabilityManager());
    }

    private function controller(): ObjectSearchController
    {
        $capabilities = new CapabilityManager();

        return new ObjectSearchController(
            new EditModeState($capabilities, new PageContextResolver(), new FrontendRuntimeGuard()),
            $capabilities,
            new ObjectNavigationReadModel($capabilities)
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/dbvc/v1/visual-editor/object-search');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function findItem(array $items, string $object_type, int $id)
    {
        foreach ($items as $item) {
            if ($item['objectType'] === $object_type && (int) $item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $types
     * @return array<string, mixed>|null
     */
    private function findType(array $types, string $object_type, string $subtype)
    {
        foreach ($types as $type) {
            if ($type['objectType'] === $object_type && $type['subtype'] === $subtype) {
                return $type;
            }
        }

        return null;
    }
}
