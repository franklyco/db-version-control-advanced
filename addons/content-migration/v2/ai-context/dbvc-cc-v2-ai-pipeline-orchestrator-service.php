<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_AI_Pipeline_Orchestrator_Service
{
    /**
     * @var DBVC_CC_V2_AI_Pipeline_Orchestrator_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_AI_Pipeline_Orchestrator_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $domain
     * @param string $journey_id
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function run_domain($domain, $journey_id, array $args = [])
    {
        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $context = $journey->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $pipeline_bundle = $this->load_pipeline_bundle($context['domain']);
        if (is_wp_error($pipeline_bundle)) {
            return $pipeline_bundle;
        }

        $url_inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory($context['domain']);
        if (is_wp_error($url_inventory)) {
            return $url_inventory;
        }

        $rows = isset($url_inventory['urls']) && is_array($url_inventory['urls']) ? $url_inventory['urls'] : [];
        $page_filter = $this->normalize_page_filter(isset($args['page_ids']) ? $args['page_ids'] : []);
        $stages = $this->normalize_stages(isset($args['stages']) ? $args['stages'] : []);
        $actor = isset($args['actor']) ? sanitize_key((string) $args['actor']) : 'system';
        $trigger = isset($args['trigger']) ? sanitize_key((string) $args['trigger']) : 'manual';
        $schema_fingerprint = isset($args['schema_fingerprint']) ? sanitize_text_field((string) $args['schema_fingerprint']) : '';

        $pages = [];
        $processed_count = 0;
        $failed_count = 0;
        $skipped_count = 0;
        $context_created_count = 0;
        $classified_count = 0;
        $mapped_count = 0;
        $finalized_count = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['scope_status'] ?? '') !== 'eligible') {
                ++$skipped_count;
                continue;
            }

            $page_id = isset($row['page_id']) ? (string) $row['page_id'] : '';
            if (! empty($page_filter) && ! isset($page_filter[$page_id])) {
                continue;
            }

            $page_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context($context['domain'], $page_id);
            if (is_wp_error($page_context)) {
                ++$failed_count;
                $pages[] = [
                    'pageId' => $page_id,
                    'status' => 'failed',
                    'error' => $page_context->get_error_message(),
                ];
                continue;
            }

            $result = $this->run_page_stages($page_context, $pipeline_bundle, $journey_id, $stages, $schema_fingerprint, $actor, $trigger);
            if (is_wp_error($result)) {
                ++$failed_count;
                $pages[] = [
                    'pageId' => $page_id,
                    'status' => 'failed',
                    'error' => $result->get_error_message(),
                ];
                continue;
            }

            ++$processed_count;
            if (! empty($result['contextStatus'])) {
                ++$context_created_count;
            }
            if (! empty($result['classificationStatus'])) {
                ++$classified_count;
            }
            if (! empty($result['mappingStatus'])) {
                ++$mapped_count;
            }
            if (! empty($result['finalizationStatus'])) {
                ++$finalized_count;
            }
            $pages[] = $result;
        }

        $latest = $journey->get_latest_state($context['domain']);
        $stage_summary = $journey->get_stage_summary($context['domain']);
        if (is_wp_error($latest)) {
            return $latest;
        }
        if (is_wp_error($stage_summary)) {
            return $stage_summary;
        }

        return [
            'runId' => $journey_id,
            'journey_id' => $journey_id,
            'domain' => $context['domain'],
            'pages' => $pages,
            'stats' => [
                'processed_count' => $processed_count,
                'failed_count' => $failed_count,
                'skipped_count' => $skipped_count,
                'context_created_count' => $context_created_count,
                'classified_count' => $classified_count,
                'mapped_count' => $mapped_count,
                'finalized_count' => $finalized_count,
            ],
            'latest' => $latest,
            'stage_summary' => $stage_summary,
        ];
    }

    /**
     * @param string $domain
     * @param string $journey_id
     * @param string $page_id
     * @param string $stage
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function rerun_page($domain, $journey_id, $page_id, $stage, array $args = [])
    {
        $stage = sanitize_key((string) $stage);
        if (! DBVC_CC_V2_Contracts::is_supported_ai_stage($stage)) {
            return new WP_Error(
                'dbvc_cc_v2_invalid_rerun_stage',
                __('The requested V2 rerun stage is not supported.', 'dbvc'),
                ['status' => 400]
            );
        }

        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $context = $journey->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $pipeline_bundle = $this->load_pipeline_bundle($context['domain']);
        if (is_wp_error($pipeline_bundle)) {
            return $pipeline_bundle;
        }

        $page_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context_for_run($journey_id, $page_id);
        if (is_wp_error($page_context)) {
            return $page_context;
        }

        $actor = isset($args['actor']) ? sanitize_key((string) $args['actor']) : 'admin';
        $trigger = isset($args['trigger']) ? sanitize_key((string) $args['trigger']) : 'rerun';
        $schema_fingerprint = isset($args['schema_fingerprint']) ? sanitize_text_field((string) $args['schema_fingerprint']) : '';
        $stages = $stage === DBVC_CC_V2_Contracts::AI_STAGE_CONTEXT_CREATION
            ? DBVC_CC_V2_Contracts::get_supported_ai_stages()
            : [$stage];

        $journey->append_event(
            $context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_STAGE_RERUN_REQUESTED,
                'step_name' => 'Stage rerun requested',
                'status' => 'completed',
                'page_id' => $page_context['page_id'],
                'path' => $page_context['path'],
                'source_url' => $page_context['source_url'],
                'actor' => $actor,
                'trigger' => $trigger,
                'metadata' => [
                    'rerun_stage' => $stage,
                    'stages_run' => $stages,
                ],
                'message' => 'URL stage rerun requested.',
            ]
        );

        $result = $this->run_page_stages($page_context, $pipeline_bundle, $journey_id, $stages, $schema_fingerprint, $actor, $trigger);
        if (is_wp_error($result)) {
            return $result;
        }

        $journey->append_event(
            $context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_STAGE_RERUN_COMPLETED,
                'step_name' => 'Stage rerun completed',
                'status' => 'completed',
                'page_id' => $page_context['page_id'],
                'path' => $page_context['path'],
                'source_url' => $page_context['source_url'],
                'actor' => $actor,
                'trigger' => $trigger,
                'output_artifacts' => isset($result['artifacts']) && is_array($result['artifacts']) ? array_values($result['artifacts']) : [],
                'metadata' => [
                    'rerun_stage' => $stage,
                    'stages_run' => $stages,
                    'review_status' => isset($result['reviewStatus']) ? (string) $result['reviewStatus'] : '',
                ],
                'message' => 'URL stage rerun completed.',
            ]
        );

        $latest = $journey->get_latest_state($context['domain']);
        $stage_summary = $journey->get_stage_summary($context['domain']);
        if (is_wp_error($latest)) {
            return $latest;
        }
        if (is_wp_error($stage_summary)) {
            return $stage_summary;
        }

        return [
            'runId' => $journey_id,
            'pageId' => $page_context['page_id'],
            'domain' => $context['domain'],
            'stage' => $stage,
            'stagesRun' => $stages,
            'page' => $result,
            'latest' => $latest,
            'stageSummary' => $stage_summary,
        ];
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $pipeline_bundle
     * @param string $journey_id
     * @param array<int, string> $stages
     * @param string $schema_fingerprint
     * @param string $actor
     * @param string $trigger
     * @return array<string, mixed>|WP_Error
     */
    private function run_page_stages(array $page_context, array $pipeline_bundle, $journey_id, array $stages, $schema_fingerprint, $actor, $trigger)
    {
        $artifact_service = DBVC_CC_V2_Page_Artifact_Service::get_instance();
        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $artifact_paths = isset($page_context['artifact_paths']) && is_array($page_context['artifact_paths']) ? $page_context['artifact_paths'] : [];
        $write_artifact_paths = isset($page_context['write_artifact_paths']) && is_array($page_context['write_artifact_paths']) ? $page_context['write_artifact_paths'] : $artifact_paths;
        $artifact_relatives = isset($page_context['artifact_relatives']) && is_array($page_context['artifact_relatives']) ? $page_context['artifact_relatives'] : [];
        $write_artifact_relatives = isset($page_context['write_artifact_relatives']) && is_array($page_context['write_artifact_relatives']) ? $page_context['write_artifact_relatives'] : $artifact_relatives;

        $raw_artifact = $artifact_service->read_required_artifact($artifact_paths['raw'], 'dbvc_cc_v2_raw_missing', 'raw page');
        if (is_wp_error($raw_artifact)) {
            return $raw_artifact;
        }

        $source_normalization = $artifact_service->read_required_artifact($artifact_paths['source_normalization'], 'dbvc_cc_v2_source_normalization_missing', 'source normalization');
        if (is_wp_error($source_normalization)) {
            return $source_normalization;
        }

        $elements_artifact = $artifact_service->read_required_artifact($artifact_paths['elements'], 'dbvc_cc_v2_elements_missing', 'elements');
        if (is_wp_error($elements_artifact)) {
            return $elements_artifact;
        }

        $sections_artifact = $artifact_service->read_required_artifact($artifact_paths['sections'], 'dbvc_cc_v2_sections_missing', 'sections');
        if (is_wp_error($sections_artifact)) {
            return $sections_artifact;
        }

        $ingestion_package = $artifact_service->read_required_artifact($artifact_paths['ingestion_package'], 'dbvc_cc_v2_ingestion_missing', 'ingestion package');
        if (is_wp_error($ingestion_package)) {
            return $ingestion_package;
        }

        $page_context['raw_artifact'] = $raw_artifact;
        $page_context['source_normalization_artifact'] = $source_normalization;
        $page_context['elements_artifact'] = $elements_artifact;
        $page_context['sections_artifact'] = $sections_artifact;
        $page_context['ingestion_package_artifact'] = $ingestion_package;

        $source_fingerprint = isset($raw_artifact['content_hash']) ? (string) $raw_artifact['content_hash'] : '';
        $inventory_fingerprint = isset($pipeline_bundle['inventory_fingerprint']) ? (string) $pipeline_bundle['inventory_fingerprint'] : '';
        $effective_schema_fingerprint = $schema_fingerprint !== '' ? $schema_fingerprint : $inventory_fingerprint;
        $artifacts_written = [];
        $result = [
            'pageId' => $page_context['page_id'],
            'status' => 'completed',
            'path' => $page_context['path'],
            'sourceUrl' => $page_context['source_url'],
            'artifacts' => [],
            'contextStatus' => '',
            'classificationStatus' => '',
            'routingStatus' => '',
            'mappingStatus' => '',
            'finalizationStatus' => '',
            'reviewStatus' => '',
        ];

        $run_context_stage = in_array(DBVC_CC_V2_Contracts::AI_STAGE_CONTEXT_CREATION, $stages, true);
        $run_classification_stage = $run_context_stage || in_array(DBVC_CC_V2_Contracts::AI_STAGE_INITIAL_CLASSIFICATION, $stages, true);
        $run_mapping_stage = $run_classification_stage || in_array(DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX, $stages, true);
        $run_finalization_stage = $run_mapping_stage || in_array(DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION, $stages, true);

        if ($run_context_stage) {
            $journey->append_event(
                $page_context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_CONTEXT_CREATION_COMPLETED,
                    'step_name' => 'Context creation completed',
                    'status' => 'started',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'source_fingerprint' => $source_fingerprint,
                    'schema_fingerprint' => $effective_schema_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'message' => 'Context creation started.',
                ]
            );

            $context_artifact = DBVC_CC_V2_Context_Creation_Service::get_instance()->build_artifact(
                $page_context,
                [
                    'input_artifacts' => [
                        $artifact_relatives['raw'],
                        $artifact_relatives['source_normalization'],
                        $artifact_relatives['elements'],
                        $artifact_relatives['sections'],
                    ],
                ]
            );

            if (! $artifact_service->write_page_artifact($write_artifact_paths['context_creation'], $context_artifact, $journey_id)) {
                return $this->append_failure_event(
                    $page_context['domain'],
                    $journey_id,
                    DBVC_CC_V2_Contracts::STEP_CONTEXT_CREATION_COMPLETED,
                    'Context creation completed',
                    new WP_Error('dbvc_cc_v2_context_write_failed', __('Could not write the V2 context creation artifact.', 'dbvc'), ['status' => 500]),
                    $page_context,
                    $actor,
                    $trigger
                );
            }

            $page_context['context_artifact'] = $context_artifact;
            $artifacts_written[] = $write_artifact_relatives['context_creation'];
            $result['contextStatus'] = isset($context_artifact['status']) ? (string) $context_artifact['status'] : 'completed';

            $journey->append_event(
                $page_context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_CONTEXT_CREATION_COMPLETED,
                    'step_name' => 'Context creation completed',
                    'status' => isset($context_artifact['status']) ? (string) $context_artifact['status'] : 'completed',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'input_artifacts' => [
                        $artifact_relatives['raw'],
                        $artifact_relatives['source_normalization'],
                        $artifact_relatives['elements'],
                        $artifact_relatives['sections'],
                    ],
                    'output_artifacts' => [$write_artifact_relatives['context_creation']],
                    'source_fingerprint' => $source_fingerprint,
                    'schema_fingerprint' => $effective_schema_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'metadata' => [
                        'prompt_version' => isset($context_artifact['prompt_version']) ? (string) $context_artifact['prompt_version'] : '',
                        'model' => isset($context_artifact['model']) ? (string) $context_artifact['model'] : '',
                        'fallback_mode' => isset($context_artifact['trace']['fallback_mode']) ? (string) $context_artifact['trace']['fallback_mode'] : '',
                    ],
                    'message' => 'Context creation artifact written.',
                ]
            );
        }

        if ($run_classification_stage) {
            if (! isset($page_context['context_artifact']) || ! is_array($page_context['context_artifact'])) {
                $page_context['context_artifact'] = $artifact_service->read_required_artifact($artifact_paths['context_creation'], 'dbvc_cc_v2_context_missing', 'context creation');
                if (is_wp_error($page_context['context_artifact'])) {
                    return $page_context['context_artifact'];
                }
            }

            $journey->append_event(
                $page_context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_INITIAL_CLASSIFICATION_COMPLETED,
                    'step_name' => 'Initial classification completed',
                    'status' => 'started',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'source_fingerprint' => $source_fingerprint,
                    'schema_fingerprint' => $inventory_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'message' => 'Initial classification started.',
                ]
            );

            $classification_artifact = DBVC_CC_V2_Initial_Classification_Service::get_instance()->build_artifact(
                $page_context,
                $pipeline_bundle,
                $page_context['context_artifact'],
                [
                    'input_artifacts' => [
                        $artifact_relatives['context_creation'],
                        isset($pipeline_bundle['inventory_artifact_relative_path']) ? (string) $pipeline_bundle['inventory_artifact_relative_path'] : '',
                    ],
                    'context_ref' => $artifact_relatives['context_creation'],
                ]
            );

            if (! $artifact_service->write_page_artifact($write_artifact_paths['initial_classification'], $classification_artifact, $journey_id)) {
                return $this->append_failure_event(
                    $page_context['domain'],
                    $journey_id,
                    DBVC_CC_V2_Contracts::STEP_INITIAL_CLASSIFICATION_COMPLETED,
                    'Initial classification completed',
                    new WP_Error('dbvc_cc_v2_classification_write_failed', __('Could not write the V2 initial classification artifact.', 'dbvc'), ['status' => 500]),
                    $page_context,
                    $actor,
                    $trigger
                );
            }

            $review_status = isset($classification_artifact['review']['status']) ? sanitize_key((string) $classification_artifact['review']['status']) : '';
            $event_status = in_array($review_status, ['blocked', 'needs_review'], true) ? 'completed_with_warnings' : 'completed';
            $artifacts_written[] = $write_artifact_relatives['initial_classification'];
            $result['classificationStatus'] = isset($classification_artifact['status']) ? (string) $classification_artifact['status'] : 'completed';
            $result['reviewStatus'] = $review_status;
            $page_context['classification_artifact'] = $classification_artifact;
            $result['classification'] = [
                'objectKey' => isset($classification_artifact['primary_classification']['object_key']) ? (string) $classification_artifact['primary_classification']['object_key'] : '',
                'confidence' => isset($classification_artifact['primary_classification']['confidence']) ? (float) $classification_artifact['primary_classification']['confidence'] : 0.0,
                'reviewStatus' => $review_status,
            ];

            $routing_artifact = DBVC_CC_V2_Routing_Artifact_Service::get_instance()->build_artifact(
                $page_context,
                $page_context['context_artifact'],
                $classification_artifact,
                [
                    'input_artifacts' => [
                        $artifact_relatives['context_creation'],
                        $artifact_relatives['initial_classification'],
                    ],
                    'context_ref' => $artifact_relatives['context_creation'],
                    'classification_ref' => $artifact_relatives['initial_classification'],
                ]
            );

            if (! $artifact_service->write_page_artifact($write_artifact_paths['routing_artifact'], $routing_artifact, $journey_id)) {
                return $this->append_failure_event(
                    $page_context['domain'],
                    $journey_id,
                    DBVC_CC_V2_Contracts::STEP_INITIAL_CLASSIFICATION_COMPLETED,
                    'Initial classification completed',
                    new WP_Error('dbvc_cc_v2_routing_write_failed', __('Could not write the V2 routing artifact.', 'dbvc'), ['status' => 500]),
                    $page_context,
                    $actor,
                    $trigger
                );
            }

            $page_context['routing_artifact'] = $routing_artifact;
            $artifacts_written[] = $write_artifact_relatives['routing_artifact'];
            $result['routingStatus'] = isset($routing_artifact['status']) ? (string) $routing_artifact['status'] : 'completed';

            $journey->append_event(
                $page_context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_INITIAL_CLASSIFICATION_COMPLETED,
                    'step_name' => 'Initial classification completed',
                    'status' => $event_status,
                    'exception_state' => in_array($review_status, ['blocked', 'needs_review'], true) ? $review_status : '',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'input_artifacts' => [
                        $artifact_relatives['context_creation'],
                        isset($pipeline_bundle['inventory_artifact_relative_path']) ? (string) $pipeline_bundle['inventory_artifact_relative_path'] : '',
                    ],
                    'output_artifacts' => [
                        $write_artifact_relatives['initial_classification'],
                        $write_artifact_relatives['routing_artifact'],
                    ],
                    'source_fingerprint' => $source_fingerprint,
                    'schema_fingerprint' => $inventory_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'metadata' => [
                        'prompt_version' => isset($classification_artifact['prompt_version']) ? (string) $classification_artifact['prompt_version'] : '',
                        'model' => isset($classification_artifact['model']) ? (string) $classification_artifact['model'] : '',
                        'fallback_mode' => isset($classification_artifact['trace']['fallback_mode']) ? (string) $classification_artifact['trace']['fallback_mode'] : '',
                        'review_status' => $review_status,
                        'primary_object_key' => isset($classification_artifact['primary_classification']['object_key']) ? (string) $classification_artifact['primary_classification']['object_key'] : '',
                        'primary_confidence' => isset($classification_artifact['primary_classification']['confidence']) ? (float) $classification_artifact['primary_classification']['confidence'] : 0.0,
                        'page_intent' => isset($routing_artifact['primary_route']['page_intent']) ? (string) $routing_artifact['primary_route']['page_intent'] : '',
                        'routing_review_status' => isset($routing_artifact['review']['status']) ? (string) $routing_artifact['review']['status'] : '',
                    ],
                    'message' => 'Initial classification and routing artifacts written.',
                ]
            );
        }

        if (! isset($page_context['classification_artifact']) || ! is_array($page_context['classification_artifact'])) {
            $page_context['classification_artifact'] = $artifact_service->read_required_artifact(
                $artifact_paths['initial_classification'],
                'dbvc_cc_v2_classification_missing',
                'initial classification'
            );
            if (is_wp_error($page_context['classification_artifact'])) {
                return $page_context['classification_artifact'];
            }
        }

        if (! isset($page_context['context_artifact']) || ! is_array($page_context['context_artifact'])) {
            $page_context['context_artifact'] = $artifact_service->read_required_artifact(
                $artifact_paths['context_creation'],
                'dbvc_cc_v2_context_missing',
                'context creation'
            );
            if (is_wp_error($page_context['context_artifact'])) {
                return $page_context['context_artifact'];
            }
        }

        if (! isset($page_context['routing_artifact']) || ! is_array($page_context['routing_artifact'])) {
            $routing_artifact = $artifact_service->read_required_artifact(
                isset($artifact_paths['routing_artifact']) ? $artifact_paths['routing_artifact'] : '',
                'dbvc_cc_v2_routing_missing',
                'routing artifact'
            );
            if (is_wp_error($routing_artifact)) {
                $routing_artifact = DBVC_CC_V2_Routing_Artifact_Service::get_instance()->build_artifact(
                    $page_context,
                    $page_context['context_artifact'],
                    $page_context['classification_artifact'],
                    [
                        'input_artifacts' => [
                            isset($artifact_relatives['context_creation']) ? (string) $artifact_relatives['context_creation'] : '',
                            isset($artifact_relatives['initial_classification']) ? (string) $artifact_relatives['initial_classification'] : '',
                        ],
                        'context_ref' => isset($artifact_relatives['context_creation']) ? (string) $artifact_relatives['context_creation'] : '',
                        'classification_ref' => isset($artifact_relatives['initial_classification']) ? (string) $artifact_relatives['initial_classification'] : '',
                    ]
                );
            }

            $page_context['routing_artifact'] = $routing_artifact;
            $result['routingStatus'] = isset($routing_artifact['status']) ? (string) $routing_artifact['status'] : 'reused';
        }

        $domain_context = $journey->get_domain_context($page_context['domain']);
        if (is_wp_error($domain_context)) {
            return $domain_context;
        }

        $pattern_memory = DBVC_CC_V2_Pattern_Learning_Service::get_instance()->get_pattern_memory($page_context['domain']);
        if (is_wp_error($pattern_memory)) {
            return $pattern_memory;
        }

        $pattern_memory_relative = $artifact_service->get_domain_relative_path(
            $domain_context['pattern_memory_file'],
            $domain_context['domain_dir']
        );

        if ($run_mapping_stage) {
            $journey->append_event(
                $page_context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_MAPPING_INDEX_COMPLETED,
                    'step_name' => 'Mapping index completed',
                    'status' => 'started',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'source_fingerprint' => $source_fingerprint,
                    'schema_fingerprint' => $effective_schema_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'message' => 'Mapping index and media alignment started.',
                ]
            );

            $mapping_index_artifact = DBVC_CC_V2_Mapping_Index_Service::get_instance()->build_artifact(
                $page_context,
                $page_context['classification_artifact'],
                [
                    'catalog' => isset($pipeline_bundle['catalog']) ? $pipeline_bundle['catalog'] : [],
                    'catalog_fingerprint' => isset($pipeline_bundle['catalog_fingerprint']) ? (string) $pipeline_bundle['catalog_fingerprint'] : '',
                ],
                is_array($pattern_memory) ? $pattern_memory : [],
                [
                    'classification_ref' => $artifact_relatives['initial_classification'],
                    'routing_ref' => isset($artifact_relatives['routing_artifact']) ? (string) $artifact_relatives['routing_artifact'] : '',
                    'pattern_memory_ref' => $pattern_memory_relative,
                    'input_artifacts' => [
                        $artifact_relatives['initial_classification'],
                        isset($artifact_relatives['routing_artifact']) ? (string) $artifact_relatives['routing_artifact'] : '',
                        $artifact_relatives['ingestion_package'],
                        isset($pipeline_bundle['catalog_artifact_relative_path']) ? (string) $pipeline_bundle['catalog_artifact_relative_path'] : '',
                    ],
                ]
            );

            $media_candidates_artifact = DBVC_CC_V2_Media_Candidate_Service::get_instance()->build_artifact(
                $page_context,
                $page_context['classification_artifact'],
                [
                    'catalog' => isset($pipeline_bundle['catalog']) ? $pipeline_bundle['catalog'] : [],
                    'catalog_fingerprint' => isset($pipeline_bundle['catalog_fingerprint']) ? (string) $pipeline_bundle['catalog_fingerprint'] : '',
                ],
                [
                    'input_artifacts' => [
                        $artifact_relatives['initial_classification'],
                        $artifact_relatives['raw'],
                        $artifact_relatives['sections'],
                    ],
                ]
            );

            if (
                ! $artifact_service->write_page_artifact($write_artifact_paths['mapping_index'], $mapping_index_artifact, $journey_id)
                || ! $artifact_service->write_page_artifact($write_artifact_paths['media_candidates'], $media_candidates_artifact, $journey_id)
            ) {
                return $this->append_failure_event(
                    $page_context['domain'],
                    $journey_id,
                    DBVC_CC_V2_Contracts::STEP_MAPPING_INDEX_COMPLETED,
                    'Mapping index completed',
                    new WP_Error('dbvc_cc_v2_mapping_index_write_failed', __('Could not write the V2 mapping index or media candidates artifacts.', 'dbvc'), ['status' => 500]),
                    $page_context,
                    $actor,
                    $trigger
                );
            }

            $page_context['mapping_index_artifact'] = $mapping_index_artifact;
            $page_context['media_candidates_artifact'] = $media_candidates_artifact;
            $artifacts_written[] = $write_artifact_relatives['mapping_index'];
            $artifacts_written[] = $write_artifact_relatives['media_candidates'];
            $result['mappingStatus'] = 'completed';

            $journey->append_event(
                $page_context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_MAPPING_INDEX_COMPLETED,
                    'step_name' => 'Mapping index completed',
                    'status' => empty($mapping_index_artifact['unresolved_items']) ? 'completed' : 'completed_with_warnings',
                    'exception_state' => empty($mapping_index_artifact['unresolved_items']) ? '' : 'needs_review',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'input_artifacts' => [
                        $artifact_relatives['initial_classification'],
                        $artifact_relatives['ingestion_package'],
                        $pattern_memory_relative,
                    ],
                    'output_artifacts' => [
                        $write_artifact_relatives['mapping_index'],
                        $write_artifact_relatives['media_candidates'],
                    ],
                    'source_fingerprint' => $source_fingerprint,
                    'schema_fingerprint' => $effective_schema_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'metadata' => [
                        'content_item_count' => isset($mapping_index_artifact['stats']['content_item_count']) ? (int) $mapping_index_artifact['stats']['content_item_count'] : 0,
                        'unresolved_count' => isset($mapping_index_artifact['stats']['unresolved_count']) ? (int) $mapping_index_artifact['stats']['unresolved_count'] : 0,
                        'media_item_count' => isset($media_candidates_artifact['stats']['media_item_count']) ? (int) $media_candidates_artifact['stats']['media_item_count'] : 0,
                    ],
                    'message' => 'Mapping index and media candidate artifacts written.',
                ]
            );

            $journey->append_event(
                $page_context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_TARGET_TRANSFORM_COMPLETED,
                    'step_name' => 'Target transform completed',
                    'status' => 'started',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'source_fingerprint' => $source_fingerprint,
                    'schema_fingerprint' => $effective_schema_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'message' => 'Target transform preview started.',
                ]
            );

            $target_transform_artifact = DBVC_CC_V2_Target_Transform_Service::get_instance()->build_artifact(
                $page_context,
                $page_context['classification_artifact'],
                $mapping_index_artifact,
                $media_candidates_artifact,
                [
                    'classification_ref' => $artifact_relatives['initial_classification'],
                    'input_artifacts' => [
                        $artifact_relatives['mapping_index'],
                        $artifact_relatives['media_candidates'],
                    ],
                ]
            );

            if (! $artifact_service->write_page_artifact($write_artifact_paths['target_transform'], $target_transform_artifact, $journey_id)) {
                return $this->append_failure_event(
                    $page_context['domain'],
                    $journey_id,
                    DBVC_CC_V2_Contracts::STEP_TARGET_TRANSFORM_COMPLETED,
                    'Target transform completed',
                    new WP_Error('dbvc_cc_v2_target_transform_write_failed', __('Could not write the V2 target transform artifact.', 'dbvc'), ['status' => 500]),
                    $page_context,
                    $actor,
                    $trigger
                );
            }

            $page_context['target_transform_artifact'] = $target_transform_artifact;
            $artifacts_written[] = $write_artifact_relatives['target_transform'];

            $journey->append_event(
                $page_context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_TARGET_TRANSFORM_COMPLETED,
                    'step_name' => 'Target transform completed',
                    'status' => isset($target_transform_artifact['stats']['warning_count']) && (int) $target_transform_artifact['stats']['warning_count'] > 0
                        ? 'completed_with_warnings'
                        : 'completed',
                    'exception_state' => isset($target_transform_artifact['resolution_preview']['mode']) && (string) $target_transform_artifact['resolution_preview']['mode'] === DBVC_CC_V2_Contracts::RESOLUTION_MODE_BLOCKED
                        ? 'blocked'
                        : '',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'input_artifacts' => [
                        $artifact_relatives['mapping_index'],
                        $artifact_relatives['media_candidates'],
                    ],
                    'output_artifacts' => [$write_artifact_relatives['target_transform']],
                    'source_fingerprint' => $source_fingerprint,
                    'schema_fingerprint' => $effective_schema_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'metadata' => [
                        'resolution_mode' => isset($target_transform_artifact['resolution_preview']['mode']) ? (string) $target_transform_artifact['resolution_preview']['mode'] : '',
                        'transform_item_count' => isset($target_transform_artifact['stats']['transform_item_count']) ? (int) $target_transform_artifact['stats']['transform_item_count'] : 0,
                    ],
                    'message' => 'Target transform artifact written.',
                ]
            );
        } else {
            $page_context['mapping_index_artifact'] = $artifact_service->read_required_artifact(
                $artifact_paths['mapping_index'],
                'dbvc_cc_v2_mapping_index_missing',
                'mapping index'
            );
            if (is_wp_error($page_context['mapping_index_artifact'])) {
                return $page_context['mapping_index_artifact'];
            }

            $page_context['media_candidates_artifact'] = $artifact_service->read_required_artifact(
                $artifact_paths['media_candidates'],
                'dbvc_cc_v2_media_candidates_missing',
                'media candidates'
            );
            if (is_wp_error($page_context['media_candidates_artifact'])) {
                return $page_context['media_candidates_artifact'];
            }

            $page_context['target_transform_artifact'] = $artifact_service->read_required_artifact(
                $artifact_paths['target_transform'],
                'dbvc_cc_v2_target_transform_missing',
                'target transform'
            );
            if (is_wp_error($page_context['target_transform_artifact'])) {
                return $page_context['target_transform_artifact'];
            }

            $mapping_index_artifact = $page_context['mapping_index_artifact'];
            $media_candidates_artifact = $page_context['media_candidates_artifact'];
            $target_transform_artifact = $page_context['target_transform_artifact'];
            $result['mappingStatus'] = 'reused';
            $result['resolutionMode'] = isset($target_transform_artifact['resolution_preview']['mode']) ? (string) $target_transform_artifact['resolution_preview']['mode'] : '';
        }

        if (! $run_finalization_stage) {
            $result['artifacts'] = $artifacts_written;
            return $result;
        }

        $journey->append_event(
            $page_context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_RECOMMENDED_MAPPINGS_FINALIZED,
                'step_name' => 'Recommended mappings finalized',
                'status' => 'started',
                'page_id' => $page_context['page_id'],
                'path' => $page_context['path'],
                'source_url' => $page_context['source_url'],
                'source_fingerprint' => $source_fingerprint,
                'schema_fingerprint' => $effective_schema_fingerprint,
                'actor' => $actor,
                'trigger' => $trigger,
                'message' => 'Canonical recommendation finalization started.',
            ]
        );

        $mapping_recommendations_artifact = DBVC_CC_V2_Recommendation_Finalizer_Service::get_instance()->build_artifact(
            $page_context,
            $page_context['classification_artifact'],
            $page_context['mapping_index_artifact'],
            $page_context['target_transform_artifact'],
            $page_context['media_candidates_artifact'],
            is_array($pattern_memory) ? $pattern_memory : [],
            [
                'pattern_memory_ref' => $pattern_memory_relative,
                'input_artifacts' => [
                    $artifact_relatives['mapping_index'],
                    $artifact_relatives['target_transform'],
                    $artifact_relatives['media_candidates'],
                ],
            ]
        );

        if (! $artifact_service->write_page_artifact($write_artifact_paths['mapping_recommendations'], $mapping_recommendations_artifact, $journey_id)) {
            return $this->append_failure_event(
                $page_context['domain'],
                $journey_id,
                DBVC_CC_V2_Contracts::STEP_RECOMMENDED_MAPPINGS_FINALIZED,
                'Recommended mappings finalized',
                new WP_Error('dbvc_cc_v2_recommendations_write_failed', __('Could not write the V2 mapping recommendations artifact.', 'dbvc'), ['status' => 500]),
                $page_context,
                $actor,
                $trigger
            );
        }

        $updated_pattern_memory = DBVC_CC_V2_Pattern_Learning_Service::get_instance()->update_pattern_memory(
            $page_context['domain'],
            $journey_id,
            $page_context['classification_artifact'],
            $mapping_recommendations_artifact
        );
        if (is_wp_error($updated_pattern_memory)) {
            return $updated_pattern_memory;
        }

        $review_status = isset($mapping_recommendations_artifact['review']['status']) ? sanitize_key((string) $mapping_recommendations_artifact['review']['status']) : '';
        $event_status = $review_status === 'blocked' ? 'completed_with_warnings' : 'completed';
        $artifacts_written[] = $write_artifact_relatives['mapping_recommendations'];
        $result['finalizationStatus'] = 'completed';
        $result['reviewStatus'] = $review_status;
        $result['resolutionMode'] = isset($target_transform_artifact['resolution_preview']['mode']) ? (string) $target_transform_artifact['resolution_preview']['mode'] : '';

        $journey->append_event(
            $page_context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_RECOMMENDED_MAPPINGS_FINALIZED,
                'step_name' => 'Recommended mappings finalized',
                'status' => $event_status,
                'exception_state' => in_array($review_status, ['blocked', 'needs_review'], true) ? $review_status : '',
                'page_id' => $page_context['page_id'],
                'path' => $page_context['path'],
                'source_url' => $page_context['source_url'],
                'input_artifacts' => [
                    $artifact_relatives['mapping_index'],
                    $artifact_relatives['target_transform'],
                    $artifact_relatives['media_candidates'],
                    $pattern_memory_relative,
                ],
                'output_artifacts' => [$write_artifact_relatives['mapping_recommendations']],
                'source_fingerprint' => $source_fingerprint,
                'schema_fingerprint' => $effective_schema_fingerprint,
                'actor' => $actor,
                'trigger' => $trigger,
                'metadata' => [
                    'review_status' => $review_status,
                    'resolution_mode' => isset($target_transform_artifact['resolution_preview']['mode']) ? (string) $target_transform_artifact['resolution_preview']['mode'] : '',
                    'recommendation_count' => isset($mapping_recommendations_artifact['recommendations']) && is_array($mapping_recommendations_artifact['recommendations'])
                        ? count($mapping_recommendations_artifact['recommendations'])
                        : 0,
                    'media_recommendation_count' => isset($mapping_recommendations_artifact['media_recommendations']) && is_array($mapping_recommendations_artifact['media_recommendations'])
                        ? count($mapping_recommendations_artifact['media_recommendations'])
                        : 0,
                ],
                'message' => 'Canonical mapping recommendations artifact written.',
            ]
        );

        $journey->append_event(
            $page_context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_PATTERN_MEMORY_UPDATED,
                'step_name' => 'Pattern memory updated',
                'status' => 'completed',
                'actor' => $actor,
                'trigger' => $trigger,
                'output_artifacts' => [$pattern_memory_relative],
                'metadata' => [
                    'pattern_group_count' => isset($updated_pattern_memory['stats']['pattern_group_count']) ? (int) $updated_pattern_memory['stats']['pattern_group_count'] : 0,
                ],
                'message' => 'Domain-scoped pattern memory updated from finalized recommendations.',
            ]
        );

        $result['artifacts'] = $artifacts_written;

        return $result;
    }

    /**
     * @param string $domain
     * @param string $journey_id
     * @param string $step_key
     * @param string $step_name
     * @param WP_Error $error
     * @param array<string, mixed> $page_context
     * @param string $actor
     * @param string $trigger
     * @return WP_Error
     */
    private function append_failure_event($domain, $journey_id, $step_key, $step_name, WP_Error $error, array $page_context, $actor, $trigger)
    {
        DBVC_CC_V2_Domain_Journey_Service::get_instance()->append_event(
            $domain,
            [
                'journey_id' => $journey_id,
                'step_key' => $step_key,
                'step_name' => $step_name,
                'status' => 'failed',
                'page_id' => isset($page_context['page_id']) ? (string) $page_context['page_id'] : '',
                'path' => isset($page_context['path']) ? (string) $page_context['path'] : '',
                'source_url' => isset($page_context['source_url']) ? (string) $page_context['source_url'] : '',
                'actor' => $actor,
                'trigger' => $trigger,
                'error_code' => $error->get_error_code(),
                'message' => $error->get_error_message(),
            ]
        );

        return $error;
    }

    /**
     * @param string $domain
     * @return array<string, mixed>|WP_Error
     */
    private function load_pipeline_bundle($domain)
    {
        $inventory_result = DBVC_CC_V2_Target_Object_Inventory_Service::get_instance()->get_inventory($domain, false);
        if (is_wp_error($inventory_result)) {
            return $inventory_result;
        }

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->get_catalog($domain, false);
        if (is_wp_error($catalog_result)) {
            return $catalog_result;
        }

        return [
            'inventory' => isset($inventory_result['inventory']) && is_array($inventory_result['inventory']) ? $inventory_result['inventory'] : [],
            'inventory_fingerprint' => isset($inventory_result['inventory_fingerprint']) ? (string) $inventory_result['inventory_fingerprint'] : '',
            'inventory_artifact_relative_path' => isset($inventory_result['artifact_relative_path']) ? (string) $inventory_result['artifact_relative_path'] : '',
            'catalog' => isset($catalog_result['catalog']) && is_array($catalog_result['catalog']) ? $catalog_result['catalog'] : [],
            'catalog_fingerprint' => isset($catalog_result['catalog_fingerprint']) ? (string) $catalog_result['catalog_fingerprint'] : '',
            'catalog_artifact_relative_path' => isset($catalog_result['artifact_relative_path']) ? (string) $catalog_result['artifact_relative_path'] : '',
        ];
    }

    /**
     * @param mixed $page_ids
     * @return array<string, bool>
     */
    private function normalize_page_filter($page_ids)
    {
        if (! is_array($page_ids)) {
            return [];
        }

        $normalized = [];
        foreach ($page_ids as $page_id) {
            $page_id = sanitize_text_field((string) $page_id);
            if ($page_id !== '') {
                $normalized[$page_id] = true;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $stages
     * @return array<int, string>
     */
    private function normalize_stages($stages)
    {
        if (! is_array($stages) || empty($stages)) {
            return DBVC_CC_V2_Contracts::get_supported_ai_stages();
        }

        $normalized = [];
        foreach ($stages as $stage) {
            $stage = sanitize_key((string) $stage);
            if (DBVC_CC_V2_Contracts::is_supported_ai_stage($stage)) {
                $normalized[] = $stage;
            }
        }

        return empty($normalized) ? DBVC_CC_V2_Contracts::get_supported_ai_stages() : array_values(array_unique($normalized));
    }
}
