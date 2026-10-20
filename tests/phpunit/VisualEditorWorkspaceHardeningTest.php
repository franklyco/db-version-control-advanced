<?php

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\FrontendRuntimeGuard;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Navigation\ObjectNavigationReadModel;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Rest\Controllers\ObjectSearchController;

/**
 * R6-E: permission boundary, large-site cost bounds, and page-size caps for
 * the Site Manager Workspace's navigation read model + route.
 *
 * Complements VisualEditorObjectNavigationTest (R6-A: policy + honest routes
 * + pagination correctness) and VisualEditorWorkspaceShellTest (R6-D-1: flag,
 * enqueue, builder exclusion) with the release-gate assertions the R6 spec
 * lists under "Performance requirements" and "Permission boundary tests".
 */
final class VisualEditorWorkspaceHardeningTest extends WP_UnitTestCase
{
    private const COOKIE_NAME = 'dbvc_visual_editor_mode';

    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_WORKSPACE_ENABLED, '1');
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles'] = null;
    }

    public function tear_down(): void
    {
        unset($_COOKIE[self::COOKIE_NAME]);
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles'] = null;

        foreach ($this->previous_options as $option_name => $value) {
            if ($value === '__dbvc_missing_option__') {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
        }

        DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // Permission boundary
    // ---------------------------------------------------------------------

    public function test_object_search_route_is_closed_to_users_without_the_base_capability(): void
    {
        $capabilities = new CapabilityManager();
        $controller = new ObjectSearchController(
            new EditModeState($capabilities, new PageContextResolver(), new FrontendRuntimeGuard()),
            $capabilities
        );
        $_COOKIE[self::COOKIE_NAME] = '1';

        wp_set_current_user(0);
        $this->assertFalse($controller->canAccess(), 'logged-out: permission callback denies');

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse($controller->canAccess(), 'subscriber lacks the base capability');

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertFalse($controller->canAccess(), 'author lacks edit_others_posts (base capability)');

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertTrue($controller->canAccess());
        $this->assertSame(200, $controller->handle($this->request(['objectType' => 'post']))->get_status());

        unset($_COOKIE[self::COOKIE_NAME]);
        $this->assertSame(403, $controller->handle($this->request(['objectType' => 'post']))->get_status(), 'capable user without active mode is still refused');
    }

    public function test_users_without_the_base_capability_get_no_visual_editor_assets_at_all(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->go_to(get_permalink($post_id));
        $_COOKIE[self::COOKIE_NAME] = '1';

        $this->runAssetLoaderEnqueue();

        $this->assertFalse(wp_script_is('dbvc-visual-editor-overlay', 'enqueued'), 'no overlay for a subscriber even with the cookie set');
        $this->assertFalse(wp_script_is('dbvc-visual-editor-workspace', 'enqueued'));
        $this->assertSame('', (string) wp_scripts()->get_data('dbvc-visual-editor-overlay', 'data'), 'no bootstrap payload, so no workspace block leaks');
    }

    // ---------------------------------------------------------------------
    // Large-site cost + page-size cap
    // ---------------------------------------------------------------------

    public function test_a_navigation_page_costs_a_bounded_number_of_queries_independent_of_page_size(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);

        for ($index = 0; $index < 60; $index++) {
            self::factory()->post->create(['post_type' => 'page', 'post_title' => sprintf('Large site %02d', $index)]);
        }
        for ($index = 0; $index < 12; $index++) {
            self::factory()->term->create(['taxonomy' => 'category', 'name' => sprintf('Large cat %02d', $index)]);
        }

        $read_model = new ObjectNavigationReadModel(new CapabilityManager());

        $small = $this->coldQueryCount(function () use ($read_model) {
            return $read_model->search(['objectType' => 'post', 'subtype' => 'page', 'perPage' => 5]);
        });
        $large = $this->coldQueryCount(function () use ($read_model) {
            return $read_model->search(['objectType' => 'post', 'subtype' => 'page', 'perPage' => 30]);
        });
        $this->assertCount(5, $small['result']['items']);
        $this->assertCount(30, $large['result']['items']);
        $this->assertLessThanOrEqual(8, $large['queries'], 'a cold 30-row page stays within a handful of queries');
        $this->assertSame($small['queries'], $large['queries'], 'per-item capability / permalink work adds no queries (no N+1)');

        $terms = $this->coldQueryCount(function () use ($read_model) {
            return $read_model->search(['objectType' => 'term', 'subtype' => 'category', 'perPage' => 10]);
        });
        $this->assertCount(10, $terms['result']['items']);
        $this->assertLessThanOrEqual(6, $terms['queries']);

        $types = $this->coldQueryCount(function () use ($read_model) {
            return $read_model->describeTypes();
        });
        // Registry-only; the ≤ 2 allows the options / user-caps cache warm-up a flush forces, never a per-type query.
        $this->assertLessThanOrEqual(2, $types['queries'], 'type discovery costs no per-type queries');

        $searched = $this->coldQueryCount(function () use ($read_model) {
            return $read_model->search(['objectType' => 'all', 'search' => 'Large', 'perPage' => 30]);
        });
        $this->assertLessThanOrEqual(12, $searched['queries'], 'an interleaved all-types search is two bounded queries plus caches');
    }

    public function test_page_size_is_hard_capped_at_thirty_and_never_returns_the_whole_inventory(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);
        $_COOKIE[self::COOKIE_NAME] = '1';

        for ($index = 0; $index < 45; $index++) {
            self::factory()->post->create(['post_type' => 'page', 'post_title' => sprintf('Cap %02d', $index)]);
        }

        $capabilities = new CapabilityManager();
        $controller = new ObjectSearchController(
            new EditModeState($capabilities, new PageContextResolver(), new FrontendRuntimeGuard()),
            $capabilities
        );

        $body = $controller->handle($this->request(['objectType' => 'post', 'subtype' => 'page', 'perPage' => 9999]))->get_data();
        $this->assertSame(ObjectNavigationReadModel::MAX_PER_PAGE, $body['perPage']);
        $this->assertCount(ObjectNavigationReadModel::MAX_PER_PAGE, $body['items']);
        $this->assertTrue($body['hasMore']);

        $body = $controller->handle($this->request(['objectType' => 'post', 'subtype' => 'page']))->get_data();
        $this->assertSame(ObjectNavigationReadModel::DEFAULT_PER_PAGE, $body['perPage'], 'omitting perPage uses the default, not "everything"');
        $this->assertCount(ObjectNavigationReadModel::DEFAULT_PER_PAGE, $body['items']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Run $callback against cold object caches and report the query delta.
     *
     * @return array{result: mixed, queries: int}
     */
    private function coldQueryCount(callable $callback): array
    {
        wp_cache_flush();
        $before = get_num_queries();
        $result = $callback();

        return ['result' => $result, 'queries' => get_num_queries() - $before];
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

    private function runAssetLoaderEnqueue(): void
    {
        $capabilities = new CapabilityManager();
        $page_context = new PageContextResolver(null);
        $edit_mode = new EditModeState($capabilities, $page_context, new FrontendRuntimeGuard());
        $registry = new \Dbvc\VisualEditor\Registry\EditableRegistry($page_context, null);
        $bootstrap_file = dirname(__DIR__, 2) . '/addons/visual-editor/bootstrap.php';
        $loader = new \Dbvc\VisualEditor\Assets\AssetLoader($bootstrap_file, $edit_mode, $registry, $page_context);
        $loader->enqueue();
    }
}
