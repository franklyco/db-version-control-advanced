<?php

class BricksReferenceMappingTest extends WP_UnitTestCase
{
    /** @var string */
    private $sync_dir = '';

    /** @var string */
    private $sync_option_before = '';

    /** @var mixed */
    private $post_types_before;

    /** @var string */
    private $localize_before = '';

    /** @var string */
    private $bricks_reference_policy_before = '';

    /** @var string */
    private $allow_new_posts_before = '';

    /** @var array<int,string> */
    private $proposal_dirs = [];

    public function set_up(): void
    {
        parent::set_up();

        register_post_type('listing', [
            'public' => true,
            'label'  => 'Listings',
        ]);
        register_post_type('bricks_template', [
            'public' => false,
            'label'  => 'Bricks Templates',
        ]);
        register_taxonomy('feature_tax', ['post'], [
            'public' => true,
            'label'  => 'Features',
        ]);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->sync_option_before = (string) get_option('dbvc_sync_path', '');
        $this->post_types_before = get_option('dbvc_post_types', []);
        $this->localize_before = (string) get_option('dbvc_localize_bricks_entity_references', '');
        $this->bricks_reference_policy_before = (string) get_option('dbvc_bricks_reference_unresolved_policy', '');
        $this->allow_new_posts_before = (string) get_option('dbvc_allow_new_posts', '');

        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-bricks-reference-tests-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);
        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/');

        update_option('dbvc_sync_path', $relative ?: 'wp-content/uploads');
        update_option('dbvc_post_types', ['listing', 'bricks_template']);
        update_option('dbvc_localize_bricks_entity_references', '1');
        update_option('dbvc_bricks_reference_unresolved_policy', 'warn');
        update_option('dbvc_allow_new_posts', '0');
    }

    public function tear_down(): void
    {
        update_option('dbvc_sync_path', $this->sync_option_before);
        update_option('dbvc_post_types', $this->post_types_before);
        update_option('dbvc_localize_bricks_entity_references', $this->localize_before);
        update_option('dbvc_bricks_reference_unresolved_policy', $this->bricks_reference_policy_before);
        update_option('dbvc_allow_new_posts', $this->allow_new_posts_before);

        unregister_post_type('listing');
        unregister_post_type('bricks_template');
        unregister_taxonomy('feature_tax');
        $this->delete_directory($this->sync_dir);
        foreach ($this->proposal_dirs as $proposal_dir) {
            $this->delete_directory($proposal_dir);
        }
        $this->proposal_dirs = [];

        parent::tear_down();
    }

    public function test_export_adds_bricks_template_preview_reference_descriptor(): void
    {
        $listing_id = self::factory()->post->create([
            'post_type'   => 'listing',
            'post_title'  => 'Sample Listing',
            'post_status' => 'publish',
            'post_name'   => 'sample-listing',
        ]);
        update_post_meta($listing_id, 'vf_object_uid', 'listing-source-uid');

        $template_id = self::factory()->post->create([
            'post_type'   => 'bricks_template',
            'post_title'  => 'Single Listing Template',
            'post_status' => 'publish',
            'post_name'   => 'single-listing-template',
        ]);
        update_post_meta($template_id, '_bricks_template_settings', [
            'templateConditions'    => [
                [
                    'id'       => 'ulst01',
                    'main'     => 'postType',
                    'postType' => ['listing'],
                ],
            ],
            'templatePreviewPostId' => (string) $listing_id,
            'templatePreviewType'   => 'single',
        ]);

        $stage_dir = trailingslashit($this->sync_dir) . 'stage';
        wp_mkdir_p($stage_dir);

        $export = DBVC_Sync_Posts::stage_post_export($template_id, $stage_dir, 'slug_id');

        $this->assertIsArray($export);
        $decoded = $export['decoded'] ?? [];
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('dbvc_entity_references', $decoded);

        $reference = $decoded['dbvc_entity_references'][0] ?? [];
        $this->assertSame('bricks', $reference['provider'] ?? '');
        $this->assertSame('post', $reference['kind'] ?? '');
        $this->assertSame($listing_id, (int) ($reference['source_id'] ?? 0));
        $this->assertSame('string', $reference['source_value_type'] ?? '');
        $this->assertSame('meta._bricks_template_settings.0.templatePreviewPostId', $reference['path'] ?? '');
        $this->assertSame('listing-source-uid', $reference['context']['vf_object_uid'] ?? '');
        $this->assertSame('listing', $reference['context']['post_type'] ?? '');
        $this->assertSame('sample-listing', $reference['context']['slug'] ?? '');

        DBVC_Backup_Manager::generate_manifest($stage_dir, ['build_media_bundle' => false]);
        $manifest = DBVC_Backup_Manager::read_manifest($stage_dir);
        $this->assertIsArray($manifest);
        $manifest_reference = $manifest['items'][0]['dbvc_entity_references'][0] ?? [];
        $this->assertSame('bricks', $manifest_reference['provider'] ?? '');
        $this->assertSame($listing_id, (int) ($manifest_reference['source_id'] ?? 0));
        $this->assertSame('meta._bricks_template_settings.0.templatePreviewPostId', $manifest_reference['path'] ?? '');
    }

    public function test_export_adds_bricks_template_preview_term_reference_descriptor(): void
    {
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'feature_tax',
            'name'     => 'Feature Alpha',
            'slug'     => 'feature-alpha',
        ]);
        $this->assertInstanceOf(WP_Term::class, $term);
        update_term_meta($term->term_id, 'vf_object_uid', 'feature-term-source-uid');

        $template_id = self::factory()->post->create([
            'post_type'   => 'bricks_template',
            'post_title'  => 'Feature Term Template',
            'post_status' => 'publish',
            'post_name'   => 'feature-term-template',
        ]);
        update_post_meta($template_id, '_bricks_template_settings', [
            'templateConditions'  => [
                [
                    'archiveTerms' => ['feature_tax::all'],
                    'archiveType'  => ['term'],
                    'id'           => 'fterm1',
                    'main'         => 'archiveType',
                ],
            ],
            'templatePreviewTerm' => 'feature_tax::' . $term->term_id,
            'templatePreviewType' => 'archive-term',
        ]);

        $stage_dir = trailingslashit($this->sync_dir) . 'stage-term';
        wp_mkdir_p($stage_dir);

        $export = DBVC_Sync_Posts::stage_post_export($template_id, $stage_dir, 'slug_id');

        $this->assertIsArray($export);
        $decoded = $export['decoded'] ?? [];
        $this->assertIsArray($decoded);
        $reference = $decoded['dbvc_entity_references'][0] ?? [];

        $this->assertSame('bricks', $reference['provider'] ?? '');
        $this->assertSame('term', $reference['kind'] ?? '');
        $this->assertSame($term->term_id, (int) ($reference['source_id'] ?? 0));
        $this->assertSame('string', $reference['source_value_type'] ?? '');
        $this->assertSame('meta._bricks_template_settings.0.templatePreviewTerm', $reference['path'] ?? '');
        $this->assertSame('feature_tax', $reference['context']['taxonomy'] ?? '');
        $this->assertSame('feature-alpha', $reference['context']['slug'] ?? '');
        $this->assertSame('feature-term-source-uid', $reference['context']['vf_object_uid'] ?? '');

        DBVC_Backup_Manager::generate_manifest($stage_dir, ['build_media_bundle' => false]);
        $manifest = DBVC_Backup_Manager::read_manifest($stage_dir);
        $this->assertIsArray($manifest);
        $manifest_reference = $manifest['items'][0]['dbvc_entity_references'][0] ?? [];
        $this->assertSame('term', $manifest_reference['kind'] ?? '');
        $this->assertSame($term->term_id, (int) ($manifest_reference['source_id'] ?? 0));
        $this->assertSame('meta._bricks_template_settings.0.templatePreviewTerm', $manifest_reference['path'] ?? '');
    }

    public function test_import_localizes_bricks_template_preview_reference_from_descriptor(): void
    {
        $local_listing_id = self::factory()->post->create([
            'post_type'   => 'listing',
            'post_title'  => 'Sample Listing',
            'post_status' => 'publish',
            'post_name'   => 'sample-listing',
        ]);
        update_post_meta($local_listing_id, 'vf_object_uid', 'listing-source-uid');

        $template_id = self::factory()->post->create([
            'post_type'   => 'bricks_template',
            'post_title'  => 'Existing Template',
            'post_status' => 'publish',
            'post_name'   => 'single-listing-template',
        ]);
        update_post_meta($template_id, 'vf_object_uid', 'template-source-uid');

        $source_listing_id = 107582;
        $path = $this->write_sync_json(
            'bricks_template/bricks_template-single-listing-template-999999.json',
            [
                'ID'                     => 999999,
                'vf_object_uid'          => 'template-source-uid',
                'post_type'              => 'bricks_template',
                'post_title'             => 'Single Listing Template',
                'post_content'           => '',
                'post_excerpt'           => '',
                'post_status'            => 'publish',
                'post_name'              => 'single-listing-template',
                'dbvc_entity_references' => [
                    [
                        'schema'            => 'dbvc.entity_reference.v1',
                        'provider'          => 'bricks',
                        'kind'              => 'post',
                        'source_id'         => $source_listing_id,
                        'source_value_type' => 'string',
                        'path'              => 'meta._bricks_template_settings.0.templatePreviewPostId',
                        'meta_key'          => '_bricks_template_settings',
                        'context'           => [
                            'post_type'     => 'listing',
                            'slug'          => 'sample-listing',
                            'vf_object_uid' => 'listing-source-uid',
                        ],
                        'policy'            => 'localize_on_import',
                        'confidence'        => 'high',
                    ],
                ],
                'meta'                   => [
                    '_bricks_template_settings' => [
                        [
                            'templateConditions'    => [
                                [
                                    'id'       => 'ulst01',
                                    'main'     => 'postType',
                                    'postType' => ['listing'],
                                ],
                            ],
                            'templatePreviewPostId' => (string) $source_listing_id,
                            'templatePreviewType'   => 'single',
                        ],
                    ],
                ],
                'tax_input'              => [],
            ]
        );

        $result = DBVC_Sync_Posts::import_post_from_json($this->absolute_sync_path($path), false);

        $this->assertSame('applied', $result);

        $settings = get_post_meta($template_id, '_bricks_template_settings', true);
        $this->assertIsArray($settings);
        $this->assertSame((string) $local_listing_id, $settings['templatePreviewPostId'] ?? '');

        $history = get_post_meta($template_id, 'dbvc_post_history', true);
        $this->assertIsArray($history);
        $localization = $history['bricks_reference_localization'][0] ?? [];
        $this->assertSame('resolved', $localization['status'] ?? '');
        $this->assertSame($source_listing_id, (int) ($localization['source_id'] ?? 0));
        $this->assertSame($local_listing_id, (int) ($localization['local_id'] ?? 0));

        $rewritten = json_decode((string) file_get_contents($this->absolute_sync_path($path)), true);
        $this->assertIsArray($rewritten);
        $this->assertSame(
            (string) $local_listing_id,
            $rewritten['meta']['_bricks_template_settings'][0]['templatePreviewPostId'] ?? ''
        );
    }

    public function test_import_localizes_bricks_template_preview_term_reference_from_descriptor(): void
    {
        $local_term = self::factory()->term->create_and_get([
            'taxonomy' => 'feature_tax',
            'name'     => 'Feature Alpha',
            'slug'     => 'feature-alpha',
        ]);
        $this->assertInstanceOf(WP_Term::class, $local_term);
        update_term_meta($local_term->term_id, 'vf_object_uid', 'feature-term-source-uid');

        $template_id = self::factory()->post->create([
            'post_type'   => 'bricks_template',
            'post_title'  => 'Existing Term Template',
            'post_status' => 'publish',
            'post_name'   => 'feature-term-template',
        ]);
        update_post_meta($template_id, 'vf_object_uid', 'term-template-source-uid');

        $source_term_id = 299;
        $path = $this->write_sync_json(
            'bricks_template/bricks_template-feature-term-template-888888.json',
            [
                'ID'                     => 888888,
                'vf_object_uid'          => 'term-template-source-uid',
                'post_type'              => 'bricks_template',
                'post_title'             => 'Feature Term Template',
                'post_content'           => '',
                'post_excerpt'           => '',
                'post_status'            => 'publish',
                'post_name'              => 'feature-term-template',
                'dbvc_entity_references' => [
                    [
                        'schema'            => 'dbvc.entity_reference.v1',
                        'provider'          => 'bricks',
                        'kind'              => 'term',
                        'source_id'         => $source_term_id,
                        'source_value_type' => 'string',
                        'path'              => 'meta._bricks_template_settings.0.templatePreviewTerm',
                        'meta_key'          => '_bricks_template_settings',
                        'context'           => [
                            'taxonomy'      => 'feature_tax',
                            'slug'          => 'feature-alpha',
                            'vf_object_uid' => 'feature-term-source-uid',
                        ],
                        'policy'            => 'localize_on_import',
                        'confidence'        => 'high',
                    ],
                ],
                'meta'                   => [
                    '_bricks_template_settings' => [
                        [
                            'templateConditions'  => [
                                [
                                    'archiveTerms' => ['feature_tax::all'],
                                    'archiveType'  => ['term'],
                                    'id'           => 'fterm1',
                                    'main'         => 'archiveType',
                                ],
                            ],
                            'templatePreviewTerm' => 'feature_tax::' . $source_term_id,
                            'templatePreviewType' => 'archive-term',
                        ],
                    ],
                ],
                'tax_input'              => [],
            ]
        );

        $result = DBVC_Sync_Posts::import_post_from_json($this->absolute_sync_path($path), false);

        $this->assertSame('applied', $result);

        $settings = get_post_meta($template_id, '_bricks_template_settings', true);
        $this->assertIsArray($settings);
        $this->assertSame('feature_tax::' . $local_term->term_id, $settings['templatePreviewTerm'] ?? '');

        $history = get_post_meta($template_id, 'dbvc_post_history', true);
        $this->assertIsArray($history);
        $localization = $history['bricks_reference_localization'][0] ?? [];
        $this->assertSame('term', $localization['kind'] ?? '');
        $this->assertSame('resolved', $localization['status'] ?? '');
        $this->assertSame($source_term_id, (int) ($localization['source_id'] ?? 0));
        $this->assertSame($local_term->term_id, (int) ($localization['local_id'] ?? 0));

        $rewritten = json_decode((string) file_get_contents($this->absolute_sync_path($path)), true);
        $this->assertIsArray($rewritten);
        $this->assertSame(
            'feature_tax::' . $local_term->term_id,
            $rewritten['meta']['_bricks_template_settings'][0]['templatePreviewTerm'] ?? ''
        );
    }

    public function test_proposal_rest_payload_includes_bricks_reference_preflight_summary(): void
    {
        $local_listing_id = self::factory()->post->create([
            'post_type'   => 'listing',
            'post_title'  => 'Sample Listing',
            'post_status' => 'publish',
            'post_name'   => 'sample-listing',
        ]);
        update_post_meta($local_listing_id, 'vf_object_uid', 'listing-source-uid');

        $proposal_id = 'dbvc-bricks-reference-proposal-' . wp_generate_password(8, false, false);
        $source_listing_id = 107582;
        $relative_path = 'bricks_template/bricks_template-single-listing-template-999999.json';
        $reference = [
            'schema'            => 'dbvc.entity_reference.v1',
            'provider'          => 'bricks',
            'kind'              => 'post',
            'source_id'         => $source_listing_id,
            'source_value_type' => 'string',
            'path'              => 'meta._bricks_template_settings.0.templatePreviewPostId',
            'meta_key'          => '_bricks_template_settings',
            'context'           => [
                'post_type'     => 'listing',
                'slug'          => 'sample-listing',
                'vf_object_uid' => 'listing-source-uid',
            ],
            'policy'            => 'localize_on_import',
            'confidence'        => 'high',
        ];

        $payload = [
            'ID'                     => 999999,
            'vf_object_uid'          => 'template-source-uid',
            'post_type'              => 'bricks_template',
            'post_title'             => 'Single Listing Template',
            'post_content'           => '',
            'post_excerpt'           => '',
            'post_status'            => 'publish',
            'post_name'              => 'single-listing-template',
            'dbvc_entity_references' => [$reference],
            'meta'                   => [
                '_bricks_template_settings' => [
                    [
                        'templateConditions'    => [
                            [
                                'id'       => 'ulst01',
                                'main'     => 'postType',
                                'postType' => ['listing'],
                            ],
                        ],
                        'templatePreviewPostId' => (string) $source_listing_id,
                        'templatePreviewType'   => 'single',
                    ],
                ],
            ],
            'tax_input'              => [],
        ];

        $this->write_proposal($proposal_id, $relative_path, $payload, [
            'item_type'              => 'post',
            'vf_object_uid'          => 'template-source-uid',
            'post_id'                => 999999,
            'post_type'              => 'bricks_template',
            'post_title'             => 'Single Listing Template',
            'post_status'            => 'publish',
            'post_name'              => 'single-listing-template',
            'path'                   => $relative_path,
            'hash'                   => md5(wp_json_encode($payload)),
            'content_hash'           => md5('single-listing-template'),
            'media_refs'             => ['meta' => [], 'content' => []],
            'dbvc_entity_references' => [$reference],
        ]);

        $entities_request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/entities');
        $entities_request->set_param('proposal_id', $proposal_id);
        $entities_response = DBVC_Admin_App::get_proposal_entities($entities_request);
        $entities_data = $entities_response->get_data();

        $this->assertSame($proposal_id, $entities_data['proposal_id'] ?? '');
        $this->assertSame('ok', $entities_data['bricks_references']['status'] ?? '');
        $this->assertSame(1, (int) ($entities_data['bricks_references']['total'] ?? 0));
        $this->assertSame(1, (int) ($entities_data['bricks_references']['resolved'] ?? 0));

        $entity = $entities_data['items'][0] ?? [];
        $this->assertSame('ok', $entity['bricks_references']['status'] ?? '');
        $row = $entity['bricks_references']['items'][0] ?? [];
        $this->assertSame('resolved', $row['status'] ?? '');
        $this->assertSame('post', $row['kind'] ?? '');
        $this->assertSame($source_listing_id, (int) ($row['source_id'] ?? 0));
        $this->assertSame($local_listing_id, (int) ($row['local_id'] ?? 0));
        $this->assertSame('meta._bricks_template_settings.0.templatePreviewPostId', $row['path'] ?? '');

        $list_response = DBVC_Admin_App::get_proposals(new WP_REST_Request('GET', '/dbvc/v1/proposals'));
        $list_data = $list_response->get_data();
        $proposal = null;
        foreach (($list_data['items'] ?? []) as $item) {
            if (($item['id'] ?? '') === $proposal_id) {
                $proposal = $item;
                break;
            }
        }

        $this->assertIsArray($proposal);
        $this->assertSame('ok', $proposal['bricks_references']['status'] ?? '');
        $this->assertSame(1, (int) ($proposal['bricks_references']['resolved'] ?? 0));
    }

    public function test_apply_proposal_blocks_unresolved_bricks_reference_when_policy_blocks(): void
    {
        update_option('dbvc_bricks_reference_unresolved_policy', 'block');

        $proposal_id = 'dbvc-bricks-reference-block-' . wp_generate_password(8, false, false);
        $source_listing_id = 987654321;
        $relative_path = 'bricks_template/bricks_template-missing-listing-template-999999.json';
        $reference = $this->build_preview_post_reference($source_listing_id, [
            'post_type'     => 'listing',
            'slug'          => 'missing-listing',
            'vf_object_uid' => 'missing-listing-source-uid',
        ]);

        $payload = $this->build_preview_post_payload($source_listing_id, $reference);
        $this->write_proposal($proposal_id, $relative_path, $payload, [
            'item_type'              => 'post',
            'vf_object_uid'          => 'template-source-uid',
            'post_id'                => 999999,
            'post_type'              => 'bricks_template',
            'post_title'             => 'Missing Listing Template',
            'post_status'            => 'publish',
            'post_name'              => 'missing-listing-template',
            'path'                   => $relative_path,
            'hash'                   => md5(wp_json_encode($payload)),
            'content_hash'           => md5('missing-listing-template'),
            'media_refs'             => ['meta' => [], 'content' => []],
            'dbvc_entity_references' => [$reference],
        ]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/apply');
        $request->set_param('proposal_id', $proposal_id);
        $request->set_param('mode', 'partial');

        $result = DBVC_Admin_App::apply_proposal($request);

        $this->assertWPError($result);
        $this->assertSame('dbvc_bricks_reference_preflight_blocked', $result->get_error_code());

        $error_data = $result->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(409, (int) ($error_data['status'] ?? 0));
        $this->assertSame('block', $error_data['policy'] ?? '');
        $summary = $error_data['bricks_references'] ?? [];
        $this->assertIsArray($summary);
        $this->assertSame('warning', $summary['status'] ?? '');
        $this->assertSame(1, (int) ($summary['total'] ?? 0));
        $this->assertSame(1, (int) ($summary['unresolved'] ?? 0));
        $row = $summary['items'][0] ?? [];
        $this->assertSame('unresolved', $row['status'] ?? '');
        $this->assertSame($source_listing_id, (int) ($row['source_id'] ?? 0));
    }

    public function test_apply_proposal_warn_policy_allows_unresolved_bricks_reference_to_reach_import(): void
    {
        update_option('dbvc_bricks_reference_unresolved_policy', 'warn');

        $proposal_id = 'dbvc-bricks-reference-warn-' . wp_generate_password(8, false, false);
        $source_listing_id = 987654322;
        $relative_path = 'bricks_template/bricks_template-warn-listing-template-999999.json';
        $reference = $this->build_preview_post_reference($source_listing_id, [
            'post_type'     => 'listing',
            'slug'          => 'warn-missing-listing',
            'vf_object_uid' => 'warn-missing-listing-source-uid',
        ]);

        $payload = $this->build_preview_post_payload($source_listing_id, $reference);
        $this->write_proposal($proposal_id, $relative_path, $payload, [
            'item_type'              => 'post',
            'vf_object_uid'          => 'template-source-uid',
            'post_id'                => 999999,
            'post_type'              => 'bricks_template',
            'post_title'             => 'Warn Listing Template',
            'post_status'            => 'publish',
            'post_name'              => 'warn-listing-template',
            'path'                   => $relative_path,
            'hash'                   => md5(wp_json_encode($payload)),
            'content_hash'           => md5('warn-listing-template'),
            'media_refs'             => ['meta' => [], 'content' => []],
            'dbvc_entity_references' => [$reference],
        ]);

        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                'template-source-uid' => [
                    DBVC_NEW_ENTITY_DECISION_KEY => 'accept_new',
                ],
            ],
        ]);

        $filter = static function ($preempt, $incoming_proposal_id, $incoming_mode, $import_options) use ($proposal_id) {
            if ($incoming_proposal_id !== $proposal_id) {
                return $preempt;
            }

            return [
                'imported' => 0,
                'skipped'  => 1,
                'errors'   => [],
            ];
        };
        add_filter('dbvc_import_backup_override', $filter, 10, 4);

        try {
            $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/apply');
            $request->set_param('proposal_id', $proposal_id);
            $request->set_param('mode', 'partial');
            $response = DBVC_Admin_App::apply_proposal($request);
        } finally {
            remove_filter('dbvc_import_backup_override', $filter, 10);
        }

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertSame($proposal_id, $data['proposal_id'] ?? '');
        $this->assertSame(0, (int) ($data['result']['imported'] ?? -1));
        $this->assertSame(1, (int) ($data['result']['skipped'] ?? 0));
    }

    /**
     * @param string              $relative_path
     * @param array<string,mixed> $payload
     * @return string
     */
    private function write_sync_json(string $relative_path, array $payload): string
    {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        $absolute = $this->absolute_sync_path($relative_path);
        wp_mkdir_p(dirname($absolute));
        file_put_contents($absolute, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $relative_path;
    }

    private function absolute_sync_path(string $relative_path): string
    {
        return trailingslashit($this->sync_dir) . ltrim(str_replace('\\', '/', $relative_path), '/');
    }

    /**
     * @param array<string,string> $context
     * @return array<string,mixed>
     */
    private function build_preview_post_reference(int $source_listing_id, array $context): array
    {
        return [
            'schema'            => 'dbvc.entity_reference.v1',
            'provider'          => 'bricks',
            'kind'              => 'post',
            'source_id'         => $source_listing_id,
            'source_value_type' => 'string',
            'path'              => 'meta._bricks_template_settings.0.templatePreviewPostId',
            'meta_key'          => '_bricks_template_settings',
            'context'           => $context,
            'policy'            => 'localize_on_import',
            'confidence'        => 'high',
        ];
    }

    /**
     * @param array<string,mixed> $reference
     * @return array<string,mixed>
     */
    private function build_preview_post_payload(int $source_listing_id, array $reference): array
    {
        return [
            'ID'                     => 999999,
            'vf_object_uid'          => 'template-source-uid',
            'post_type'              => 'bricks_template',
            'post_title'             => 'Single Listing Template',
            'post_content'           => '',
            'post_excerpt'           => '',
            'post_status'            => 'publish',
            'post_name'              => 'single-listing-template',
            'dbvc_entity_references' => [$reference],
            'meta'                   => [
                '_bricks_template_settings' => [
                    [
                        'templateConditions'    => [
                            [
                                'id'       => 'ulst01',
                                'main'     => 'postType',
                                'postType' => ['listing'],
                            ],
                        ],
                        'templatePreviewPostId' => (string) $source_listing_id,
                        'templatePreviewType'   => 'single',
                    ],
                ],
            ],
            'tax_input'              => [],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $manifest_item
     */
    private function write_proposal(string $proposal_id, string $relative_path, array $payload, array $manifest_item): string
    {
        $proposal_id = sanitize_file_name($proposal_id);
        $proposal_dir = trailingslashit(DBVC_Backup_Manager::get_base_path()) . $proposal_id;
        $this->proposal_dirs[] = $proposal_dir;

        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        $payload_path = trailingslashit($proposal_dir) . $relative_path;
        wp_mkdir_p(dirname($payload_path));
        file_put_contents($payload_path, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $manifest = [
            'schema_version' => DBVC_Backup_Manager::SCHEMA_VERSION,
            'backup_name'    => $proposal_id,
            'generated_at'   => gmdate('c'),
            'totals'         => [
                'files'               => 1,
                'media_items'         => 0,
                'missing_import_hash' => 0,
            ],
            'items'          => [$manifest_item],
        ];

        file_put_contents(
            trailingslashit($proposal_dir) . DBVC_Backup_Manager::MANIFEST_FILENAME,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $proposal_dir;
    }

    private function delete_directory(string $path): void
    {
        $path = rtrim($path, '/');
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
