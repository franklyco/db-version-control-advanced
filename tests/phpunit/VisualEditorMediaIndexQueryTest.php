<?php

/**
 * R2-H Phase 1 Slice 2c (server) — index list query parity.
 *
 * Proves the durable Media Index list honors the same search / entity-family /
 * field-family / sort surface as the ephemeral scan list, so the frontend flip
 * (Slice 2c) loses no filtering. Rows are seeded straight into the store and read
 * back through MediaIndexReadModel::getList; read-time per-user eligibility still
 * applies (every seeded entity is a real, published, eligible object).
 */
final class VisualEditorMediaIndexQueryTest extends WP_UnitTestCase
{
    /**
     * @var array<string, mixed>
     */
    private $previous_options = [];

    /**
     * @var int
     */
    private $administrator_id = 0;

    /**
     * @var \Dbvc\VisualEditor\MediaManager\MediaIndexStore
     */
    private $store;

    /**
     * @var \Dbvc\VisualEditor\MediaManager\MediaIndexReadModel
     */
    private $read_model;

    public function set_up(): void
    {
        parent::set_up();

        foreach ([
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES,
            DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES,
        ] as $option_name) {
            $this->previous_options[$option_name] = get_option($option_name, '__dbvc_missing_option__');
        }

        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_MEDIA_MANAGER_ENABLED, '1');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES, 'post,page,attachment');
        update_option(DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES, 'category,post_tag,post_format,nav_menu,link_category');

        register_post_type('dbvc_ixq', [
            'label' => 'Index Query Content',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
        ]);
        register_taxonomy('dbvc_ixq_tax', 'dbvc_ixq', [
            'label' => 'Index Query Tax',
            'public' => true,
            'show_ui' => true,
        ]);

        $this->administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->administrator_id);

        $capabilities = new \Dbvc\VisualEditor\Permissions\CapabilityManager();
        $policy = new \Dbvc\VisualEditor\MediaManager\EligibilityPolicy($capabilities);
        $this->store = new \Dbvc\VisualEditor\MediaManager\MediaIndexStore();
        $this->read_model = new \Dbvc\VisualEditor\MediaManager\MediaIndexReadModel($this->store, $policy);
        $this->store->maybeUpgrade();
        $this->store->deleteAll();

        $this->seedRows();
    }

    public function tear_down(): void
    {
        $this->store->deleteAll();
        if (taxonomy_exists('dbvc_ixq_tax')) {
            unregister_taxonomy('dbvc_ixq_tax');
        }
        if (post_type_exists('dbvc_ixq')) {
            unregister_post_type('dbvc_ixq');
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

    public function test_search_matches_the_label_substring(): void
    {
        $labels = $this->labels($this->read_model->getList(['search' => 'eta']));

        $this->assertSame(['Beta'], $labels);
    }

    public function test_entity_family_filters_posts_and_terms(): void
    {
        $this->assertSame(
            ['Alpha', 'Beta', 'Gamma'],
            $this->sortedLabels($this->read_model->getList(['entityFamily' => 'post']))
        );
        $this->assertSame(
            ['Delta'],
            $this->labels($this->read_model->getList(['entityFamily' => 'term']))
        );
    }

    public function test_field_family_filters_by_missing_family(): void
    {
        $labels = $this->labels($this->read_model->getList(['fieldFamily' => 'acf_gallery']));

        $this->assertSame(['Gamma'], $labels);
    }

    public function test_sort_orders_by_label_and_missing_count(): void
    {
        $this->assertSame(
            ['Gamma', 'Delta', 'Beta', 'Alpha'],
            $this->labels($this->read_model->getList(['sort' => 'entity_desc']))
        );

        // Alpha carries the most missing fields; missing_desc surfaces it first.
        $missingDesc = $this->labels($this->read_model->getList(['sort' => 'missing_desc']));
        $this->assertSame('Alpha', $missingDesc[0]);
    }

    public function test_query_is_echoed_normalized(): void
    {
        $list = $this->read_model->getList(['sort' => 'bogus', 'entityFamily' => 'nope', 'search' => '  hi  ']);

        $this->assertSame('entity_asc', $list['query']['sort']);
        $this->assertSame('all', $list['query']['entityFamily']);
        $this->assertSame('hi', $list['query']['search']);
    }

    /**
     * @return void
     */
    private function seedRows()
    {
        $generation = $this->store->currentGeneration();

        $alpha = self::factory()->post->create(['post_type' => 'dbvc_ixq', 'post_status' => 'publish', 'post_title' => 'Alpha']);
        $beta = self::factory()->post->create(['post_type' => 'dbvc_ixq', 'post_status' => 'publish', 'post_title' => 'Beta']);
        $gamma = self::factory()->post->create(['post_type' => 'dbvc_ixq', 'post_status' => 'publish', 'post_title' => 'Gamma']);
        $delta = self::factory()->term->create_and_get(['taxonomy' => 'dbvc_ixq_tax', 'name' => 'Delta']);
        $this->assertNotWPError($delta);

        $this->store->upsertEntity([
            'entity_type' => 'post', 'entity_id' => $alpha, 'entity_subtype' => 'dbvc_ixq',
            'label' => 'Alpha', 'missing_count' => 3, 'index_generation' => $generation,
            'family_counts' => ['featured_image' => 1, 'acf_image' => 2, 'acf_gallery' => 0],
        ]);
        $this->store->upsertEntity([
            'entity_type' => 'post', 'entity_id' => $beta, 'entity_subtype' => 'dbvc_ixq',
            'label' => 'Beta', 'missing_count' => 1, 'index_generation' => $generation,
            'family_counts' => ['featured_image' => 1, 'acf_image' => 0, 'acf_gallery' => 0],
        ]);
        $this->store->upsertEntity([
            'entity_type' => 'post', 'entity_id' => $gamma, 'entity_subtype' => 'dbvc_ixq',
            'label' => 'Gamma', 'missing_count' => 2, 'index_generation' => $generation,
            'family_counts' => ['featured_image' => 0, 'acf_image' => 0, 'acf_gallery' => 2],
        ]);
        $this->store->upsertEntity([
            'entity_type' => 'term', 'entity_id' => $delta->term_id, 'entity_subtype' => 'dbvc_ixq_tax',
            'label' => 'Delta', 'missing_count' => 1, 'index_generation' => $generation,
            'family_counts' => ['featured_image' => 0, 'acf_image' => 1, 'acf_gallery' => 0],
        ]);
    }

    /**
     * @param array<string, mixed> $list
     * @return array<int, string>
     */
    private function labels(array $list)
    {
        return array_map(static function ($item) {
            return $item['entity']['label'];
        }, $list['items']);
    }

    /**
     * @param array<string, mixed> $list
     * @return array<int, string>
     */
    private function sortedLabels(array $list)
    {
        $labels = $this->labels($list);
        sort($labels);

        return $labels;
    }
}
