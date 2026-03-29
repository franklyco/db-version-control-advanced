<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Domain_Journey_REST_Controller
{
    /**
     * @var DBVC_CC_V2_Domain_Journey_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Domain_Journey_REST_Controller
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return void
     */
    public function register_routes()
    {
        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'list_runs'],
                    'permission_callback' => [$this, 'permissions_check'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_run'],
                    'permission_callback' => [$this, 'permissions_check'],
                    'args' => [
                        'domain' => [
                            'required' => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'forceRebuild' => [
                            'required' => false,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                        'sitemapUrl' => [
                            'required' => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'maxUrls' => [
                            'required' => false,
                            'sanitize_callback' => 'absint',
                        ],
                        'crawlOverrides' => [
                            'required' => false,
                            'validate_callback' => static function ($value) {
                                return is_array($value) || null === $value;
                            },
                        ],
                        'qaReplaySourceRunId' => [
                            'required' => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_run'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/visibility',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'set_run_visibility'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'hidden' => [
                        'required' => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/qa/recovery-fixture',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'set_run_recovery_fixture'],
                'permission_callback' => [$this, 'qa_fixture_permissions_check'],
                'args' => [
                    'enabled' => [
                        'required' => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                    'stage' => [
                        'required' => false,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'pageId' => [
                        'required' => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/overview',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_run_overview'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/readiness',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_run_readiness'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/urls/(?P<page_id>[\w-]+)/rerun',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rerun_url_stage'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'stage' => [
                        'required' => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );
    }

    /**
     * @return WP_REST_Response
     */
    public function list_runs($request = null)
    {
        $params = $request instanceof WP_REST_Request ? $this->extract_request_params($request) : [];
        $include_hidden = ! empty($params['includeHidden']);
        $visibility_service = DBVC_CC_V2_Run_Visibility_Service::get_instance();
        $hidden_map = $visibility_service->get_hidden_run_map();
        $runs = DBVC_CC_V2_Domain_Journey_Service::get_instance()->list_latest_states();
        $items = [];
        foreach ($runs as $run) {
            if (! is_array($run)) {
                continue;
            }

            $item = $this->format_run_list_item($run);
            if (! $include_hidden && ! empty($item['hidden'])) {
                continue;
            }

            $items[] = $item;
        }

        return rest_ensure_response(
            [
                'items' => array_values($items),
                'meta' => [
                    'includeHidden' => $include_hidden,
                    'hiddenCount' => count($hidden_map),
                    'visibleCount' => count($items),
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_run($request)
    {
        $params = $this->extract_request_params($request);
        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $force_rebuild = ! empty($params['forceRebuild']);
        $sitemap_url = isset($params['sitemapUrl']) ? esc_url_raw((string) $params['sitemapUrl']) : '';
        $max_urls = isset($params['maxUrls']) ? absint($params['maxUrls']) : 0;
        $crawl_overrides = $this->sanitize_crawl_overrides(isset($params['crawlOverrides']) ? $params['crawlOverrides'] : []);
        $qa_replay_source_run_id = isset($params['qaReplaySourceRunId'])
            ? sanitize_text_field((string) $params['qaReplaySourceRunId'])
            : '';
        $journey_context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($journey_context)) {
            return $journey_context;
        }

        $resolved_domain = isset($journey_context['domain']) ? (string) $journey_context['domain'] : '';
        $run_id = DBVC_CC_V2_Contracts::generate_run_id($resolved_domain);

        $profile_result = DBVC_CC_V2_Run_Profile_Service::get_instance()->persist_latest_profile(
            $resolved_domain,
            [
                'run_id' => $run_id,
                'sitemap_url' => $sitemap_url,
                'max_urls' => $max_urls,
                'force_rebuild' => $force_rebuild,
                'crawl_overrides' => $crawl_overrides,
            ]
        );
        if (is_wp_error($profile_result)) {
            return $profile_result;
        }

        if ($qa_replay_source_run_id !== '') {
            $fixture_result = DBVC_CC_V2_Run_Replay_QA_Fixture_Service::get_instance()->create_replay_run(
                $qa_replay_source_run_id,
                $run_id,
                [
                    'domain' => $resolved_domain,
                ]
            );
            if (is_wp_error($fixture_result)) {
                return $fixture_result;
            }

            return rest_ensure_response($this->format_run_payload($fixture_result));
        }

        $schema_sync = DBVC_CC_V2_Schema_Sync_Service::get_instance()->sync_domain(
            $resolved_domain,
            [
                'journey_id' => $run_id,
                'force_rebuild' => $force_rebuild,
                'actor' => 'admin',
                'trigger' => 'rest',
            ]
        );
        if (is_wp_error($schema_sync)) {
            return $schema_sync;
        }

        $result = $schema_sync;
        if ($sitemap_url !== '') {
            $captured = DBVC_CC_V2_Capture_Orchestrator_Service::get_instance()->run_domain(
                $resolved_domain,
                $run_id,
                $sitemap_url,
                [
                    'schema_fingerprint' => isset($schema_sync['schema_fingerprint']) ? (string) $schema_sync['schema_fingerprint'] : '',
                    'actor' => 'admin',
                    'trigger' => 'rest',
                    'max_urls' => $max_urls,
                    'crawl_overrides' => $crawl_overrides,
                ]
            );
            if (is_wp_error($captured)) {
                return $captured;
            }

            $result = array_merge($schema_sync, $captured);

            $captured_page_ids = $this->extract_completed_page_ids(isset($captured['pages']) ? $captured['pages'] : []);
            if (! empty($captured_page_ids)) {
                $ai_pipeline = DBVC_CC_V2_AI_Pipeline_Orchestrator_Service::get_instance()->run_domain(
                    $resolved_domain,
                    $run_id,
                    [
                        'page_ids' => $captured_page_ids,
                        'schema_fingerprint' => isset($schema_sync['schema_fingerprint']) ? (string) $schema_sync['schema_fingerprint'] : '',
                        'actor' => 'admin',
                        'trigger' => 'rest',
                    ]
                );
                if (is_wp_error($ai_pipeline)) {
                    return $ai_pipeline;
                }

                $result = $this->merge_run_results($schema_sync, $captured, $ai_pipeline);
            }
        }

        return rest_ensure_response($this->format_run_payload($result));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_run($request)
    {
        $run = $this->resolve_run_payload($request);
        if (is_wp_error($run)) {
            return $run;
        }

        $run_id = isset($run['latest']['journey_id']) ? (string) $run['latest']['journey_id'] : '';
        $visibility = DBVC_CC_V2_Run_Visibility_Service::get_instance()->get_visibility_payload($run_id);
        $action_summary = DBVC_CC_V2_Run_Action_Summary_Service::get_instance()->build_summary(
            isset($run['latest']) && is_array($run['latest']) ? $run['latest'] : []
        );

        return rest_ensure_response(
            [
                'runId' => $run_id,
                'domain' => isset($run['latest']['domain']) ? (string) $run['latest']['domain'] : '',
                'status' => isset($run['latest']['status']) ? (string) $run['latest']['status'] : '',
                'updatedAt' => isset($run['latest']['updated_at']) ? (string) $run['latest']['updated_at'] : '',
                'counts' => isset($run['latest']['counts']) ? $run['latest']['counts'] : [],
                'latestSchemaFingerprint' => isset($run['latest']['latest_schema_fingerprint']) ? (string) $run['latest']['latest_schema_fingerprint'] : '',
                'runProfile' => $this->format_run_profile(isset($run['run_profile']) ? $run['run_profile'] : []),
                'hidden' => ! empty($visibility['hidden']),
                'hiddenAt' => isset($visibility['hiddenAt']) ? (string) $visibility['hiddenAt'] : '',
                'actionSummary' => $this->apply_recovery_qa_fixture_to_action_summary($run_id, $action_summary),
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function set_run_visibility($request)
    {
        $params = $this->extract_request_params($request);
        $run_id = sanitize_text_field((string) $request['run_id']);
        $domain = DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $visibility = DBVC_CC_V2_Run_Visibility_Service::get_instance()->set_hidden($run_id, ! empty($params['hidden']));

        return rest_ensure_response(
            [
                'runId' => $run_id,
                'domain' => $domain,
                'hidden' => ! empty($visibility['hidden']),
                'hiddenAt' => isset($visibility['hiddenAt']) ? (string) $visibility['hiddenAt'] : '',
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function set_run_recovery_fixture($request)
    {
        $params = $this->extract_request_params($request);
        $run_id = sanitize_text_field((string) $request['run_id']);
        $enabled = ! array_key_exists('enabled', $params) || ! empty($params['enabled']);
        $service = DBVC_CC_V2_Run_Recovery_QA_Fixture_Service::get_instance();

        if (! $enabled) {
            return rest_ensure_response($service->clear_fixture($run_id));
        }

        $result = $service->seed_fixture(
            $run_id,
            [
                'stage' => isset($params['stage']) ? $params['stage'] : '',
                'pageId' => isset($params['pageId']) ? $params['pageId'] : '',
            ]
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(
            array_merge(
                [
                    'enabled' => true,
                ],
                $result
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_run_readiness($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $payload = DBVC_CC_V2_Package_QA_Service::get_instance()->get_run_readiness(
            $run_id,
            [
                'write_reports' => true,
            ]
        );
        if (is_wp_error($payload)) {
            return $payload;
        }

        return rest_ensure_response($payload);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_run_overview($request)
    {
        $run = $this->resolve_run_payload($request);
        if (is_wp_error($run)) {
            return $run;
        }

        $run_id = isset($run['latest']['journey_id']) ? (string) $run['latest']['journey_id'] : '';
        $domain = isset($run['latest']['domain']) ? (string) $run['latest']['domain'] : '';
        $recent_activity = DBVC_CC_V2_Run_Activity_Service::get_instance()->get_recent_activity(
            $domain,
            $run_id
        );
        if (is_wp_error($recent_activity)) {
            $recent_activity = [];
        }

        return rest_ensure_response(
            [
                'runId' => $run_id,
                'domain' => $domain,
                'inventory' => isset($run['url_inventory']) && is_array($run['url_inventory']) ? $run['url_inventory'] : [],
                'latest' => isset($run['latest']) && is_array($run['latest']) ? $run['latest'] : [],
                'stageSummary' => isset($run['stage_summary']) && is_array($run['stage_summary']) ? $run['stage_summary'] : [],
                'recentActivity' => is_array($recent_activity) ? $recent_activity : [],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rerun_url_stage($request)
    {
        $params = $this->extract_request_params($request);
        $run_id = sanitize_text_field((string) $request['run_id']);
        $page_id = sanitize_text_field((string) $request['page_id']);
        $stage = isset($params['stage']) ? sanitize_key((string) $params['stage']) : '';
        $domain = DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $latest = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_latest_state_for_run($run_id);
        if (is_wp_error($latest)) {
            return $latest;
        }

        $result = DBVC_CC_V2_AI_Pipeline_Orchestrator_Service::get_instance()->rerun_page(
            $domain,
            $run_id,
            $page_id,
            $stage,
            [
                'schema_fingerprint' => isset($latest['latest_schema_fingerprint']) ? (string) $latest['latest_schema_fingerprint'] : '',
                'actor' => 'admin',
                'trigger' => 'rest',
            ]
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @return bool
     */
    public function permissions_check()
    {
        return current_user_can(DBVC_CC_Contracts::ADMIN_CAPABILITY);
    }

    /**
     * @return bool|WP_Error
     */
    public function qa_fixture_permissions_check()
    {
        if (! $this->permissions_check()) {
            return false;
        }

        if (! DBVC_CC_V2_Run_Recovery_QA_Fixture_Service::get_instance()->is_available()) {
            return new WP_Error(
                'dbvc_cc_v2_qa_fixture_unavailable',
                __('The V2 recovery QA fixture helper is unavailable in this environment.', 'dbvc'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * @param WP_REST_Request $request
     * @return array<string, mixed>|WP_Error
     */
    private function resolve_run_payload($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $domain = DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $latest = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_latest_state_for_run($run_id);
        $stage_summary = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_stage_summary_for_run($run_id);
        $url_inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory_for_run($run_id);
        $run_profile = DBVC_CC_V2_Run_Profile_Service::get_instance()->get_profile_for_run($run_id);
        if (is_wp_error($latest)) {
            return $latest;
        }
        if (is_wp_error($stage_summary)) {
            return $stage_summary;
        }
        if (is_wp_error($url_inventory)) {
            $url_inventory = [];
        }
        if (is_wp_error($run_profile)) {
            $run_profile = [];
        }

        return [
            'latest' => $latest,
            'stage_summary' => $stage_summary,
            'url_inventory' => $url_inventory,
            'run_profile' => is_array($run_profile) ? $run_profile : [],
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function format_run_payload(array $run)
    {
        $run_id = isset($run['runId']) ? (string) $run['runId'] : (isset($run['latest']['journey_id']) ? (string) $run['latest']['journey_id'] : '');
        $visibility = DBVC_CC_V2_Run_Visibility_Service::get_instance()->get_visibility_payload($run_id);
        $action_summary = DBVC_CC_V2_Run_Action_Summary_Service::get_instance()->build_summary(
            isset($run['latest']) && is_array($run['latest']) ? $run['latest'] : []
        );

        return [
            'runId' => $run_id,
            'domain' => isset($run['domain']) ? (string) $run['domain'] : (isset($run['latest']['domain']) ? (string) $run['latest']['domain'] : ''),
            'latest' => isset($run['latest']) && is_array($run['latest']) ? $run['latest'] : [],
            'stageSummary' => isset($run['stage_summary']) && is_array($run['stage_summary']) ? $run['stage_summary'] : [],
            'schemaFingerprint' => isset($run['schema_fingerprint']) ? (string) $run['schema_fingerprint'] : '',
            'inventory' => isset($run['inventory']) && is_array($run['inventory']) ? $run['inventory'] : [],
            'urlInventory' => isset($run['url_inventory']) && is_array($run['url_inventory']) ? $run['url_inventory'] : [],
            'catalog' => isset($run['catalog']) && is_array($run['catalog']) ? $run['catalog'] : [],
            'pages' => isset($run['pages']) && is_array($run['pages']) ? $run['pages'] : [],
            'stats' => isset($run['stats']) && is_array($run['stats']) ? $run['stats'] : [],
            'runProfile' => $this->format_run_profile(isset($run['run_profile']) ? $run['run_profile'] : []),
            'hidden' => ! empty($visibility['hidden']),
            'hiddenAt' => isset($visibility['hiddenAt']) ? (string) $visibility['hiddenAt'] : '',
            'actionSummary' => $this->apply_recovery_qa_fixture_to_action_summary($run_id, $action_summary),
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function format_run_list_item(array $run)
    {
        $run_id = isset($run['journey_id']) ? (string) $run['journey_id'] : '';
        $domain = isset($run['domain']) ? (string) $run['domain'] : '';
        $visibility = DBVC_CC_V2_Run_Visibility_Service::get_instance()->get_visibility_payload($run_id);
        $run_profile = $domain !== ''
            ? DBVC_CC_V2_Run_Profile_Service::get_instance()->get_latest_profile($domain)
            : [];
        if (is_wp_error($run_profile)) {
            $run_profile = [];
        }

        return [
            'runId' => $run_id,
            'domain' => $domain,
            'status' => isset($run['status']) ? (string) $run['status'] : '',
            'updatedAt' => isset($run['updated_at']) ? (string) $run['updated_at'] : '',
            'hidden' => ! empty($visibility['hidden']),
            'hiddenAt' => isset($visibility['hiddenAt']) ? (string) $visibility['hiddenAt'] : '',
            'runProfile' => $this->format_run_profile(is_array($run_profile) ? $run_profile : []),
            'actionSummary' => $this->apply_recovery_qa_fixture_to_action_summary(
                $run_id,
                DBVC_CC_V2_Run_Action_Summary_Service::get_instance()->build_summary($run)
            ),
        ];
    }

    /**
     * @param mixed $run_profile
     * @return array<string, mixed>
     */
    private function format_run_profile($run_profile)
    {
        if (! is_array($run_profile)) {
            return [];
        }

        $request = isset($run_profile['request']) && is_array($run_profile['request']) ? $run_profile['request'] : [];

        return [
            'runId' => isset($run_profile['run_id']) ? (string) $run_profile['run_id'] : '',
            'domain' => isset($request['domain']) ? (string) $request['domain'] : '',
            'storedAt' => isset($run_profile['stored_at']) ? (string) $run_profile['stored_at'] : '',
            'sitemapUrl' => isset($request['sitemap_url']) ? (string) $request['sitemap_url'] : '',
            'maxUrls' => isset($request['max_urls']) ? absint($request['max_urls']) : 0,
            'forceRebuild' => ! empty($request['force_rebuild']),
            'crawlOverrides' => isset($request['crawl_overrides']) && is_array($request['crawl_overrides']) ? $request['crawl_overrides'] : [],
        ];
    }

    /**
     * @param WP_REST_Request $request
     * @return array<string, mixed>
     */
    private function extract_request_params($request)
    {
        $params = $request->get_params();
        $json_params = $request->get_json_params();
        if (is_array($json_params) && ! empty($json_params)) {
            $params = array_merge($params, $json_params);
        }

        return is_array($params) ? $params : [];
    }

    /**
     * @param mixed $crawl_overrides
     * @return array<string, mixed>
     */
    private function sanitize_crawl_overrides($crawl_overrides)
    {
        return DBVC_CC_Crawler_Service::sanitize_crawl_overrides($crawl_overrides);
    }

    /**
     * @param mixed $pages
     * @return array<int, string>
     */
    private function extract_completed_page_ids($pages)
    {
        if (! is_array($pages)) {
            return [];
        }

        $page_ids = [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            if (isset($page['status']) && (string) $page['status'] !== 'completed') {
                continue;
            }

            $page_id = isset($page['pageId']) ? sanitize_text_field((string) $page['pageId']) : '';
            if ($page_id !== '') {
                $page_ids[] = $page_id;
            }
        }

        return array_values(array_unique($page_ids));
    }

    /**
     * @param array<string, mixed> $schema_sync
     * @param array<string, mixed> $captured
     * @param array<string, mixed> $ai_pipeline
     * @return array<string, mixed>
     */
    private function merge_run_results(array $schema_sync, array $captured, array $ai_pipeline)
    {
        $page_map = [];
        foreach ([isset($captured['pages']) ? $captured['pages'] : [], isset($ai_pipeline['pages']) ? $ai_pipeline['pages'] : []] as $page_list) {
            if (! is_array($page_list)) {
                continue;
            }

            foreach ($page_list as $page) {
                if (! is_array($page)) {
                    continue;
                }

                $page_id = isset($page['pageId']) ? sanitize_text_field((string) $page['pageId']) : '';
                if ($page_id === '') {
                    continue;
                }

                $page_map[$page_id] = array_merge(isset($page_map[$page_id]) && is_array($page_map[$page_id]) ? $page_map[$page_id] : [], $page);
            }
        }

        return array_merge(
            $schema_sync,
            $captured,
            $ai_pipeline,
            [
                'pages' => array_values($page_map),
                'stats' => array_merge(
                    isset($captured['stats']) && is_array($captured['stats']) ? $captured['stats'] : [],
                    isset($ai_pipeline['stats']) && is_array($ai_pipeline['stats']) ? $ai_pipeline['stats'] : []
                ),
                'inventory' => isset($schema_sync['inventory']) && is_array($schema_sync['inventory']) ? $schema_sync['inventory'] : [],
                'catalog' => isset($schema_sync['catalog']) && is_array($schema_sync['catalog']) ? $schema_sync['catalog'] : [],
                'url_inventory' => isset($captured['url_inventory']) && is_array($captured['url_inventory']) ? $captured['url_inventory'] : [],
            ]
        );
    }

    /**
     * @param string               $run_id
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function apply_recovery_qa_fixture_to_action_summary($run_id, array $summary)
    {
        return DBVC_CC_V2_Run_Recovery_QA_Fixture_Service::get_instance()->apply_to_action_summary($run_id, $summary);
    }
}
