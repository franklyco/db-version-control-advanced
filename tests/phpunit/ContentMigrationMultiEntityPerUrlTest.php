<?php

if (! function_exists('acf_get_field_groups')) {
    function acf_get_field_groups()
    {
        return isset($GLOBALS['dbvc_test_acf_groups']) && is_array($GLOBALS['dbvc_test_acf_groups'])
            ? $GLOBALS['dbvc_test_acf_groups']
            : [];
    }
}

if (! function_exists('acf_get_fields')) {
    function acf_get_fields($group_key)
    {
        $group_key = sanitize_key((string) $group_key);
        return isset($GLOBALS['dbvc_test_acf_fields'][$group_key]) && is_array($GLOBALS['dbvc_test_acf_fields'][$group_key])
            ? $GLOBALS['dbvc_test_acf_fields'][$group_key]
            : [];
    }
}

/**
 * One source URL becomes a set of records (V3 guide D-19).
 *
 * A team page holds five people. Before this, `mapping-recommendations.v2` carried one
 * `recommended_target_object` and entity resolution resolved one target per page, so the page
 * could become one post at most however well it was read. These tests run the real pipeline — an
 * ingest run through `POST /dbvc_cc/v2/runs` over artifacts on disk, with the model reached only
 * through the injectable transport — and pin:
 *
 * - a page the interpreter reads as five `team_member` records yields five target entities, each
 *   with its own name, role and biography, and no two records' titles are reported as one field
 *   claimed twice;
 * - every interpreter answer gets a mapping item, including a record's second paragraph that the
 *   section units had no room for, so `unjoined_recommendation_items` is zero;
 * - a set is all-or-nothing and offered, never invented: a set of an object nobody offered, or a
 *   mapping that names no record, fails validation and falls back with the reason named;
 * - with the model off, the artifact carries no trace of any of it.
 */
class ContentMigrationMultiEntityPerUrlTest extends WP_UnitTestCase
{
    /**
     * The five records on the synthetic team page, in page order. The fourth has no role heading,
     * which must leave her role empty rather than borrow a neighbour's.
     */
    private const PEOPLE = [
        ['slug' => 'ada', 'name' => 'Ada Quill, DDS', 'role' => 'Lead Dentist', 'bio' => ['Ada has practised family dentistry for twenty years.', 'Outside the clinic Ada coaches a junior rowing club.']],
        ['slug' => 'ben', 'name' => 'Ben Ortolan', 'role' => 'Registered Hygienist', 'bio' => ['Ben trained at the county college and joined the practice in 2015.']],
        ['slug' => 'cora', 'name' => 'Cora Vintry', 'role' => 'Dental Assistant', 'bio' => ['Cora keeps every chair-side tray ready before the patient sits down.']],
        ['slug' => 'dot', 'name' => 'Dot Pemberly', 'role' => '', 'bio' => ['Dot answers the phones and knows every patient by name.']],
        ['slug' => 'eli', 'name' => 'Eli Marsh', 'role' => 'Office Manager', 'bio' => ['Eli runs scheduling, billing and the insurance paperwork.']],
    ];

    /**
     * @var string
     */
    private $dir = '';

    /**
     * @var string
     */
    private $domain = 'multi-entity.test';

    /**
     * @var int
     */
    private $transport_calls = 0;

    /**
     * @var callable|null
     */
    private $permit = null;

    /**
     * @var callable|null
     */
    private $http_guard = null;

    /**
     * @var callable|null
     */
    private $options_filter = null;

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        register_post_type('team_member', [
            'public' => true,
            'label' => 'Team members',
            'supports' => ['title', 'editor', 'excerpt'],
        ]);
        $GLOBALS['dbvc_test_acf_groups'] = [[
            'key' => 'team_member',
            'name' => 'team_member',
            'title' => 'Team Member',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'team_member']]],
        ]];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'team_member' => [
                ['key' => 'role', 'name' => 'role', 'label' => 'Role', 'type' => 'text', 'description' => 'Job title.'],
                ['key' => 'bio', 'name' => 'bio', 'label' => 'Biography', 'type' => 'wysiwyg', 'description' => 'Paragraphs about the person.'],
            ],
        ];

        $this->dir = wp_normalize_path(get_temp_dir() . 'dbvc-cc-multi-entity-' . wp_generate_password(8, false));
        wp_mkdir_p($this->dir);

        $dir = $this->dir;
        $this->permit = static function ($roots) use ($dir) {
            $roots[] = $dir;

            return $roots;
        };
        add_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);

        $this->transport_calls = 0;
        $this->http_guard = static function () {
            return new WP_Error('dbvc_test_no_http', 'The interpretation seam must not reach the network in tests.');
        };
        add_filter('pre_http_request', $this->http_guard, 1);

        $this->delete_domain_artifacts();
        $this->write_team_page();
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', $this->http_guard, 1);
        remove_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);
        if ($this->options_filter !== null) {
            remove_filter(DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS, $this->options_filter);
            $this->options_filter = null;
        }

        delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '0');
        $settings = DBVC_CC_Settings_Service::get_options();
        unset($settings['openai_api_key'], $settings['openai_model']);
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        unset($GLOBALS['dbvc_test_acf_groups'], $GLOBALS['dbvc_test_acf_fields']);
        unregister_post_type('team_member');
        $this->delete_domain_artifacts();

        foreach (glob(trailingslashit($this->dir) . '*') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_a_page_read_as_five_people_yields_five_team_member_entities_each_with_its_own_values()
    {
        $this->enable_model($this->team_answer());
        $this->run_ingest();

        $recommendations = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $entities = DBVC_CC_V2_Contracts::get_target_entities($recommendations);

        $this->assertCount(5, $entities, 'The team page should become five records.');
        $this->assertSame(
            array_column(self::PEOPLE, 'name'),
            array_column($entities, 'label'),
            'Each record is named for its person, in page order.'
        );
        foreach ($entities as $entity) {
            $this->assertSame('team_member', $entity['target_object_key']);
            $this->assertNotSame('', (string) $entity['entity_ref']);
        }

        foreach (self::PEOPLE as $index => $person) {
            $entity_ref = (string) $entities[$index]['entity_ref'];
            $values = $this->values_for($recommendations, $entity_ref);

            $this->assertSame([$person['name']], $values['core:post_title'] ?? [], $person['name'] . ' has the wrong name.');
            $this->assertSame(
                $person['role'] === '' ? [] : [$person['role']],
                $values['acf:team_member:role'] ?? [],
                $person['name'] . ' has the wrong role; a missing role must stay missing.'
            );
            $this->assertSame($person['bio'], $values['acf:team_member:bio'] ?? [], $person['name'] . ' has the wrong biography.');
        }

        foreach ($recommendations['recommendations'] as $recommendation) {
            $this->assertArrayHasKey('entity_ref', $recommendation, 'On a page read as a set, every value names its record.');
        }
        foreach ($recommendations['conflicts'] as $conflict) {
            $this->assertNotSame(
                'core:post_title',
                $conflict['target_ref'] ?? '',
                'Five records each having a title is five fields, not one field claimed five times.'
            );
        }
    }

    public function test_the_built_package_commits_the_five_people_as_five_team_member_posts()
    {
        $this->enable_model($this->team_answer());
        $run = $this->run_ingest();
        $run_id = (string) $run['runId'];
        $page_id = $this->page_id_of($run, '/our-team');

        $this->approve_every_recommendation($run_id, $page_id);
        $package = $this->dispatch('POST', '/runs/' . $run_id . '/package');
        $package_id = (string) ($package['selectedPackageId'] ?? $package['packageId'] ?? '');
        $records = $this->read_package_records($package_id);

        $this->assertCount(5, $records, 'One record per person: ' . wp_json_encode($package['selectedPackage']['qaReport'] ?? $package));
        $this->assertSame(array_column(self::PEOPLE, 'name'), array_column($records, 'entity_label'));
        $this->assertCount(5, array_unique(array_column($records, 'source_url')), 'Each record is imported under its own identity.');
        foreach ($records as $record) {
            $this->assertSame('post_type:team_member', $record['target_entity_key']);
            $this->assertStringStartsWith('https://multi-entity.test/our-team/#' . DBVC_CC_V2_Contracts::ENTITY_FRAGMENT_PREFIX, $record['source_url']);
        }

        $dry_run = $this->dispatch('GET', '/runs/' . $run_id . '/dry-run', ['packageId' => $package_id]);
        $this->assertSame(5, (int) $dry_run['summary']['includedPages'], 'The dry run should plan all five records.');

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval = $this->dispatch('POST', '/runs/' . $run_id . '/preflight-approve', ['packageId' => $package_id, 'confirmApproval' => true]);
        $this->assertTrue((bool) $approval['approvalValid'], wp_json_encode($approval));
        $execute = $this->dispatch('POST', '/runs/' . $run_id . '/execute', [
            'packageId' => $package_id,
            'confirmExecute' => true,
            'approvalTokens' => $approval['approvalTokens'],
        ]);

        $posts = get_posts(['post_type' => 'team_member', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
        $this->assertCount(5, $posts, 'The package should commit five team_member posts: ' . wp_json_encode($execute));
        $this->assertSame(
            array_column(self::PEOPLE, 'name'),
            array_map(
                static function ($post) {
                    return $post->post_title;
                },
                $posts
            )
        );
        foreach (self::PEOPLE as $index => $person) {
            $post_id = $posts[$index]->ID;
            $this->assertSame($person['role'], (string) get_post_meta($post_id, 'role', true), $person['name'] . "'s role.");
            $this->assertSame(
                implode("\n\n", $person['bio']),
                (string) get_post_meta($post_id, 'bio', true),
                $person['name'] . "'s biography, every paragraph in page order."
            );
            $this->assertStringEndsWith(
                '#' . DBVC_CC_V2_Contracts::ENTITY_FRAGMENT_PREFIX . sanitize_title($person['name']),
                (string) get_post_meta($post_id, '_dbvc_cc_source_url', true)
            );
        }

        // The importer's match order runs per record: importing the same package again finds
        // each person by their own source URL and updates them, rather than matching all five
        // to the first or blocking as ambiguous.
        $approval = $this->dispatch('POST', '/runs/' . $run_id . '/preflight-approve', ['packageId' => $package_id, 'confirmApproval' => true]);
        $this->assertTrue((bool) $approval['approvalValid'], wp_json_encode($approval['issues'] ?? $approval));
        $rerun = $this->dispatch('POST', '/runs/' . $run_id . '/execute', [
            'packageId' => $package_id,
            'confirmExecute' => true,
            'approvalTokens' => $approval['approvalTokens'],
        ]);
        $this->assertSame(5, (int) $rerun['summary']['completedPages'], wp_json_encode($rerun['issues'] ?? $rerun));
        $this->assertCount(
            5,
            get_posts(['post_type' => 'team_member', 'post_status' => 'any', 'numberposts' => -1]),
            'A rerun must update the five people, not add more.'
        );
    }

    public function test_the_review_queue_and_inspector_present_the_page_as_its_set_of_records()
    {
        $this->enable_model($this->team_answer());
        $run = $this->run_ingest();
        $run_id = (string) $run['runId'];
        $page_id = $this->page_id_of($run, '/our-team');

        $queue = $this->dispatch('GET', '/runs/' . $run_id . '/exceptions');
        $row = null;
        foreach ($queue['items'] ?? [] as $item) {
            if (($item['pageId'] ?? '') === $page_id) {
                $row = $item;
            }
        }
        $this->assertNotNull($row, 'The team page should be in the exception queue.');
        $this->assertSame(5, (int) $row['recordCount']);
        $this->assertSame(array_column(self::PEOPLE, 'name'), array_column($row['records'], 'label'));

        $detail = $this->dispatch('GET', '/runs/' . $run_id . '/urls/' . $page_id);
        $this->assertCount(5, $detail['recommendations']['targetEntities']);
        foreach ($detail['recommendations']['fieldRecommendations'] as $recommendation) {
            $this->assertContains(
                $recommendation['entity_label'] ?? null,
                array_column(self::PEOPLE, 'name'),
                'Each value names the person it belongs to.'
            );
        }
    }

    public function test_excluding_one_record_packages_the_other_four()
    {
        $this->enable_model($this->team_answer());
        $run = $this->run_ingest();
        $run_id = (string) $run['runId'];
        $page_id = $this->page_id_of($run, '/our-team');

        $this->approve_every_recommendation($run_id, $page_id, ['e4' => ['include' => false]]);
        $detail = $this->dispatch('GET', '/runs/' . $run_id . '/urls/' . $page_id);
        $this->assertFalse($detail['recommendations']['targetEntities'][3]['included']);

        $package = $this->dispatch('POST', '/runs/' . $run_id . '/package');
        $records = $this->read_package_records((string) ($package['selectedPackageId'] ?? ''));

        $this->assertSame(
            ['Ada Quill, DDS', 'Ben Ortolan', 'Cora Vintry', 'Eli Marsh'],
            array_column($records, 'entity_label'),
            'The excluded record is left out; its siblings are untouched.'
        );
    }

    public function test_an_unresolved_item_holds_the_package_until_a_reviewer_leaves_it_unmapped()
    {
        $this->enable_model($this->team_answer());
        $run = $this->run_ingest();
        $run_id = (string) $run['runId'];
        $page_id = $this->page_id_of($run, '/our-team');

        $detail = $this->dispatch('GET', '/runs/' . $run_id . '/urls/' . $page_id);
        $this->dispatch('POST', '/runs/' . $run_id . '/urls/' . $page_id . '/decision', [
            'approvedRecommendationIds' => array_column($detail['recommendations']['fieldRecommendations'], 'recommendation_id'),
        ]);
        $held = $this->dispatch('POST', '/runs/' . $run_id . '/package');
        $this->assertContains('unresolved_items', array_column($held['selectedPackage']['qaReport']['blocking_issues'] ?? [], 'code'));
        $this->assertSame([], $this->read_package_records((string) $held['selectedPackageId']));

        $this->approve_every_recommendation($run_id, $page_id);
        $released = $this->dispatch('POST', '/runs/' . $run_id . '/package');
        $this->assertNotContains('unresolved_items', array_column($released['selectedPackage']['qaReport']['blocking_issues'] ?? [], 'code'));
        $this->assertCount(5, $this->read_package_records((string) $released['selectedPackageId']));
    }

    public function test_two_values_for_one_field_of_one_person_never_auto_accept()
    {
        // Corpus slot 1's team page, measured: a photo's alt text lifted into a paragraph was
        // filed as the person's role beside the real role heading, both confident. One of them
        // is wrong, and neither may be decided without a reviewer.
        $answer = $this->team_answer();
        $answer['mappings'][] = $this->mapping('ben-bio-1', 'acf:team_member:role', 'e2');
        foreach ($answer['mappings'] as $index => $mapping) {
            if ($mapping['item_id'] === 'ben-bio-1' && $mapping['target_ref'] === 'acf:team_member:bio') {
                unset($answer['mappings'][$index]);
            }
        }
        $answer['mappings'] = array_values($answer['mappings']);
        $this->enable_model($answer);
        $this->run_ingest();

        $recommendations = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $contested = [];
        $uncontested_role = null;
        foreach ($recommendations['recommendations'] as $recommendation) {
            if ($recommendation['target_ref'] !== 'acf:team_member:role') {
                continue;
            }
            if ($recommendation['entity_ref'] === 'e2') {
                $contested[] = $recommendation;
            } elseif ($recommendation['entity_ref'] === 'e3') {
                $uncontested_role = $recommendation;
            }
        }

        $this->assertCount(2, $contested, 'Ben should have two role values.');
        foreach ($contested as $recommendation) {
            $this->assertTrue($recommendation['requires_review'], 'A contested value must not auto-accept: ' . $recommendation['source_evidence']);
            $this->assertContains('contested_field_in_record', $recommendation['selection']['reason_codes']);
            $this->assertSame(0.95, (float) $recommendation['confidence'], 'Escalated, not re-scored.');
        }
        $this->assertNotNull($uncontested_role);
        $this->assertFalse($uncontested_role['requires_review'], "Cora's single role is not contested and keeps auto-accepting.");
    }

    public function test_every_interpreter_answer_gets_a_mapping_item_so_none_is_unjoined()
    {
        $this->enable_model($this->team_answer());
        $this->run_ingest();

        $mapping = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);

        $this->assertSame(0, (int) $mapping['interpretation']['unjoined_recommendation_items']);
        $this->assertNotNull(
            $this->find_item_by_source_ref($mapping, 'elements.v2#ada-bio-2'),
            'A record\'s second paragraph has no section unit of its own and must still get an item.'
        );
    }

    public function test_a_set_of_an_object_nobody_offered_fails_validation_and_says_why()
    {
        $answer = $this->team_answer();
        foreach ($answer['entities'] as $index => $entity) {
            $answer['entities'][$index]['object_key'] = 'post_tag';
        }
        $this->enable_model($answer);
        $this->run_ingest();

        $result = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $recommendations = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);

        $this->assertSame(DBVC_CC_Interp_Model::SKIP_INVALID_SHAPE, (string) $result['model_attempt']['reason']);
        $this->assertStringContainsString('was not offered as a collection', (string) $result['model_attempt']['detail']);
        $this->assertArrayNotHasKey('target_entities', $recommendations);
    }

    public function test_a_mapping_that_names_no_record_on_a_page_read_as_a_set_fails_validation()
    {
        $answer = $this->team_answer();
        unset($answer['mappings'][0]['entity_id']);
        $this->enable_model($answer);
        $this->run_ingest();

        $result = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);

        $this->assertSame(DBVC_CC_Interp_Model::SKIP_INVALID_SHAPE, (string) $result['model_attempt']['reason']);
        $this->assertStringContainsString('names no declared entity_id', (string) $result['model_attempt']['detail']);
    }

    public function test_a_model_reading_the_page_as_one_record_writes_the_single_record_shape()
    {
        $this->enable_model([
            'mappings' => [
                ['item_id' => 'team-heading', 'target_ref' => 'core:post_title', 'confidence' => 0.95, 'rationale' => 'page heading'],
                ['item_id' => 'team-intro', 'target_ref' => 'core:post_excerpt', 'confidence' => 0.9, 'rationale' => 'intro'],
            ],
            'declined' => [],
        ]);
        $this->run_ingest();

        $recommendations = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $mapping = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);

        $this->assertSame('model', (string) $mapping['interpretation']['interpreter']);
        $this->assertArrayNotHasKey('target_entities', $recommendations);
        $this->assertArrayNotHasKey('entities', $mapping['interpretation']);
        $this->assertFalse($this->has_key_anywhere($recommendations, 'entity_ref'), 'A single-record page names no record.');
        $this->assertFalse($this->has_key_anywhere($mapping, 'entity_ref'));
    }

    public function test_with_the_model_off_the_artifact_names_one_target_and_no_record()
    {
        $this->run_ingest();

        $recommendations = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $mapping = $this->read_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);

        $this->assertNotSame([], $recommendations);
        $this->assertArrayNotHasKey('target_entities', $recommendations);
        $this->assertArrayNotHasKey('interpretation', $mapping);
        $this->assertFalse($this->has_key_anywhere($recommendations, 'entity_ref'));
        $this->assertFalse($this->has_key_anywhere($mapping, 'entity_ref'));
        $this->assertCount(1, DBVC_CC_V2_Contracts::get_target_entities($recommendations));
        $this->assertSame('', DBVC_CC_V2_Contracts::get_target_entities($recommendations)[0]['entity_ref']);
    }

    /**
     * What a model reading the team page correctly returns.
     *
     * @return array<string, mixed>
     */
    private function team_answer()
    {
        $entities = [];
        $mappings = [];
        foreach (self::PEOPLE as $index => $person) {
            $entity_id = 'e' . ($index + 1);
            $entities[] = ['entity_id' => $entity_id, 'object_key' => 'team_member', 'label' => $person['name']];
            $mappings[] = $this->mapping($person['slug'] . '-name', 'core:post_title', $entity_id);
            if ($person['role'] !== '') {
                $mappings[] = $this->mapping($person['slug'] . '-role', 'acf:team_member:role', $entity_id);
            }
            foreach (array_keys($person['bio']) as $paragraph) {
                $mappings[] = $this->mapping($person['slug'] . '-bio-' . ($paragraph + 1), 'acf:team_member:bio', $entity_id);
            }
        }

        return [
            'entities' => $entities,
            'mappings' => $mappings,
            'declined' => [
                ['item_id' => 'team-heading', 'reason' => 'page_level_content'],
                ['item_id' => 'team-intro', 'reason' => 'page_level_content'],
                ['item_id' => 'head:title', 'reason' => 'page_level_content'],
            ],
        ];
    }

    /**
     * @param string $item_id
     * @param string $target_ref
     * @param string $entity_id
     * @return array<string, mixed>
     */
    private function mapping($item_id, $target_ref, $entity_id)
    {
        return [
            'item_id' => $item_id,
            'target_ref' => $target_ref,
            'confidence' => 0.95,
            'rationale' => 'fixture',
            'entity_id' => $entity_id,
        ];
    }

    /**
     * The values each field of one record would receive, in page order.
     *
     * @param array<string, mixed> $recommendations
     * @param string               $entity_ref
     * @return array<string, array<int, string>>
     */
    private function values_for(array $recommendations, $entity_ref)
    {
        $values = [];
        foreach ($recommendations['recommendations'] as $recommendation) {
            if (($recommendation['entity_ref'] ?? null) !== $entity_ref) {
                continue;
            }
            $values[(string) $recommendation['target_ref']][] = (string) $recommendation['source_evidence'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $answer
     * @return void
     */
    private function enable_model(array $answer)
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');
        $settings = DBVC_CC_Settings_Service::get_options();
        $settings['openai_api_key'] = 'test-key-not-a-real-one';
        $settings['openai_model'] = 'gpt-5.6-terra';
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        $response = [
            'status' => 200,
            'body' => (string) wp_json_encode([
                'choices' => [['message' => ['content' => (string) wp_json_encode($answer)]]],
            ]),
        ];
        $this->options_filter = function ($options) use ($response) {
            $options['transport'] = function () use ($response) {
                $this->transport_calls++;

                return $response;
            };

            return $options;
        };
        add_filter(DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS, $this->options_filter);
    }

    /**
     * A synthetic team page laid out the way corpus slot 1's is: each person's name is an h2
     * and their role an h3 beneath it, and the segmenter opens a section at every heading, so a
     * name sits alone in one section and the role owns the biography in the next (D-9).
     *
     * @return void
     */
    private function write_team_page()
    {
        $slug = 'our-team';
        $source_url = 'https://multi-entity.test/our-team/';
        $elements = [
            ['element_id' => 'team-heading', 'tag' => 'h1', 'level' => 1, 'text' => 'Our People'],
            ['element_id' => 'team-intro', 'tag' => 'p', 'text' => 'Meet the five people who look after your smile.'],
        ];
        $sections = [['section_id' => 'team_s0', 'heading' => 'Our People', 'level' => 1, 'element_ids' => ['team-heading', 'team-intro']]];

        foreach (self::PEOPLE as $person) {
            $elements[] = ['element_id' => $person['slug'] . '-name', 'tag' => 'h2', 'level' => 2, 'text' => $person['name']];
            $sections[] = ['section_id' => 'team_' . $person['slug'] . '_name', 'heading' => $person['name'], 'level' => 2, 'element_ids' => [$person['slug'] . '-name']];

            $body_ids = [];
            if ($person['role'] !== '') {
                $elements[] = ['element_id' => $person['slug'] . '-role', 'tag' => 'h3', 'level' => 3, 'text' => $person['role']];
                $body_ids[] = $person['slug'] . '-role';
            }
            foreach ($person['bio'] as $paragraph => $text) {
                $element_id = $person['slug'] . '-bio-' . ($paragraph + 1);
                $elements[] = ['element_id' => $element_id, 'tag' => 'p', 'text' => $text];
                $body_ids[] = $element_id;
            }
            $sections[] = [
                'section_id' => 'team_' . $person['slug'] . '_body',
                'heading' => $person['role'],
                'level' => $person['role'] !== '' ? 3 : 0,
                'element_ids' => $body_ids,
            ];
        }

        $text_by_id = [];
        foreach ($elements as $index => $element) {
            $elements[$index]['is_site_chrome'] = false;
            $text_by_id[$element['element_id']] = $element['text'];
        }

        $section_artifacts = [];
        $package_sections = [];
        foreach ($sections as $order => $section) {
            $signals = [
                'heading_level' => $section['level'],
                'has_heading' => $section['level'] > 0,
                'text_element_count' => count($section['element_ids']),
                'link_element_count' => 0,
                'cta_keyword_hits' => 0,
                'list_density' => 0,
            ];
            $section_artifacts[] = [
                'section_id' => $section['section_id'],
                'order' => $order + 1,
                'heading' => $section['heading'],
                'section_label_candidate' => $section['heading'],
                'parent_section_id' => null,
                'heading_path' => array_values(array_filter([$section['heading']])),
                'element_ids' => $section['element_ids'],
                'signals' => $signals,
            ];
            $package_sections[] = [
                'section_id' => $section['section_id'],
                'order' => $order + 1,
                'label' => $section['heading'],
                'sample_text' => array_map(
                    static function ($element_id) use ($text_by_id) {
                        return $text_by_id[$element_id];
                    },
                    $section['element_ids']
                ),
                'signals' => $signals,
            ];
        }

        $parts = [
            'page-artifact.v1' => [
                'artifact_schema_version' => 1,
                'artifact_type' => 'page-artifact.v1',
                'journey_id' => 'producer-journey',
                'page_id' => 'producer-page-id',
                'slug' => $slug,
                'source_url' => $source_url,
                'normalized_url' => $source_url,
                'path' => '/our-team',
                'captured_at' => gmdate('c'),
                'content_hash' => hash('sha256', $slug),
                'metadata' => ['title' => 'Our People - Quill Family Dentistry'],
                'headings' => [['level' => 1, 'text' => 'Our People']],
                'text_blocks' => [],
                'links' => [],
                'images' => [],
                'sections_raw' => [],
            ],
            'elements.v2' => [
                'artifact_schema_version' => 2,
                'artifact_type' => 'elements.v2',
                'slug' => $slug,
                'element_count' => count($elements),
                'elements' => $elements,
            ],
            'sections.v2' => [
                'artifact_schema_version' => 2,
                'artifact_type' => 'sections.v2',
                'slug' => $slug,
                'section_count' => count($section_artifacts),
                'sections' => $section_artifacts,
            ],
            'ingestion-package.v2' => [
                'artifact_schema_version' => 2,
                'artifact_type' => 'ingestion-package.v2',
                'slug' => $slug,
                'page_slug' => $slug,
                'sections' => $package_sections,
            ],
        ];

        foreach ($parts as $type => $payload) {
            file_put_contents(trailingslashit($this->dir) . $slug . '.' . $type . '.json', (string) wp_json_encode($payload));
        }
    }

    /**
     * @param array<string, mixed> $mapping
     * @param string               $source_ref
     * @return array<string, mixed>|null
     */
    private function find_item_by_source_ref(array $mapping, $source_ref)
    {
        foreach ($mapping['content_items'] ?? [] as $content_item) {
            if (is_array($content_item) && in_array($source_ref, (array) ($content_item['source_refs'] ?? []), true)) {
                return $content_item;
            }
        }

        return null;
    }

    /**
     * @param mixed  $value
     * @param string $key
     * @return bool
     */
    private function has_key_anywhere($value, $key)
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $index => $child) {
            if ($index === $key || $this->has_key_anywhere($child, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function run_ingest()
    {
        return $this->dispatch('POST', '/runs', ['domain' => $this->domain, 'ingestDir' => $this->dir]);
    }

    /**
     * @param string               $method
     * @param string               $route
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function dispatch($method, $route, array $params = [])
    {
        $request = new WP_REST_Request($method, '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);
        if ($response->is_error()) {
            $error = $response->as_error();
            $this->fail(sprintf('%s %s failed: [%s] %s', $method, $route, $error->get_error_code(), $error->get_error_message()));
        }

        $data = $response->get_data();

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $run
     * @param string               $path
     * @return string
     */
    private function page_id_of(array $run, $path)
    {
        foreach ($run['pages'] ?? [] as $page) {
            if (is_array($page) && untrailingslashit((string) ($page['path'] ?? '')) === $path) {
                return (string) $page['pageId'];
            }
        }
        $this->fail('No page at ' . $path . ' in ' . wp_json_encode($run['pages'] ?? []));

        return '';
    }

    /**
     * A reviewer approving every value the interpreter mapped on the page.
     *
     * @param string                              $run_id
     * @param string                              $page_id
     * @param array<string, array<string, mixed>> $record_decisions Per-record decisions, by entity ref.
     * @return void
     */
    private function approve_every_recommendation($run_id, $page_id, array $record_decisions = [])
    {
        $detail = $this->dispatch('GET', '/runs/' . $run_id . '/urls/' . $page_id);
        $ids = [];
        foreach ($detail['recommendations']['fieldRecommendations'] ?? [] as $recommendation) {
            if (is_array($recommendation) && ! empty($recommendation['recommendation_id'])) {
                $ids[] = (string) $recommendation['recommendation_id'];
            }
        }
        $this->assertNotSame([], $ids, 'The URL detail should list the recommendations to review.');

        // The page heading, the introduction and the head title belong to the page, not to any
        // person, and the interpreter declined them; the reviewer leaves them out.
        $left_unmapped = [];
        foreach ($detail['recommendations']['unresolvedItems'] ?? [] as $item) {
            if (is_array($item) && ! empty($item['item_id'])) {
                $left_unmapped[] = (string) $item['item_id'];
            }
        }

        $this->dispatch('POST', '/runs/' . $run_id . '/urls/' . $page_id . '/decision', [
            'reviewerNote' => 'Approve the five people.',
            'approvedRecommendationIds' => $ids,
            'leftUnmappedItemIds' => $left_unmapped,
            'targetEntities' => $record_decisions,
        ]);
    }

    /**
     * @param string $package_id
     * @return array<int, array<string, mixed>>
     */
    private function read_package_records($package_id)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
        $path = trailingslashit($context['packages_dir']) . $package_id . '/' . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_RECORDS_FILE;
        $this->assertFileExists($path, 'The package build wrote no records.');
        $decoded = json_decode((string) file_get_contents($path), true);

        return isset($decoded['records']) && is_array($decoded['records']) ? $decoded['records'] : [];
    }

    /**
     * @param string $suffix
     * @return array<string, mixed>
     */
    private function read_artifact($suffix)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
        if (is_wp_error($context)) {
            return [];
        }

        $matches = glob(trailingslashit($context['domain_dir']) . '*/our-team' . $suffix);
        if (! is_array($matches) || $matches === []) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($matches[0]), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return void
     */
    private function delete_domain_artifacts()
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
        if (is_wp_error($context)) {
            return;
        }

        $domain_dir = $context['domain_dir'];
        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_dir($domain_dir) || ! dbvc_cc_path_is_within($domain_dir, $base_dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domain_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($domain_dir);
    }

    /**
     * @return void
     */
    private function reset_rest_server()
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
