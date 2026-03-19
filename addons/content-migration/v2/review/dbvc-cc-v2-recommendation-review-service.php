<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Recommendation_Review_Service
{
    /**
     * @var DBVC_CC_V2_Recommendation_Review_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Recommendation_Review_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string               $domain
     * @param string               $run_id
     * @param string               $page_id
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function get_review_payload($domain, $run_id, $page_id, array $args = [])
    {
        $review_context = $this->load_review_context($domain, $page_id);
        if (is_wp_error($review_context)) {
            return $review_context;
        }

        $page_context = $review_context['page_context'];
        $artifact_relatives = isset($page_context['artifact_relatives']) && is_array($page_context['artifact_relatives']) ? $page_context['artifact_relatives'] : [];
        $recommendations = $review_context['mapping_recommendations'];
        $mapping_decisions = $review_context['mapping_decisions'];
        $media_decisions = $review_context['media_decisions'];
        $target_transform = $review_context['target_transform'];
        $current_target_object = $this->build_current_target_object($recommendations, $mapping_decisions);
        $decision_status = $this->resolve_decision_status($mapping_decisions, $media_decisions);
        $manual_override_count = $this->count_overrides($mapping_decisions, $media_decisions);
        $recommendation_fingerprint = $this->compute_fingerprint($recommendations);
        $stale = is_array($mapping_decisions)
            && ! empty($mapping_decisions['recommendation_fingerprint'])
            && (string) $mapping_decisions['recommendation_fingerprint'] !== $recommendation_fingerprint;

        if (! empty($args['record_presentation'])) {
            DBVC_CC_V2_Domain_Journey_Service::get_instance()->append_event(
                $domain,
                [
                    'journey_id' => $run_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_REVIEW_PRESENTED,
                    'step_name' => 'Review presented',
                    'status' => 'completed',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'actor' => isset($args['actor']) ? sanitize_key((string) $args['actor']) : 'admin',
                    'trigger' => isset($args['trigger']) ? sanitize_key((string) $args['trigger']) : 'rest',
                    'input_artifacts' => array_values(
                        array_filter(
                            [
                                isset($artifact_relatives['mapping_recommendations']) ? (string) $artifact_relatives['mapping_recommendations'] : '',
                                isset($artifact_relatives['mapping_decisions']) ? (string) $artifact_relatives['mapping_decisions'] : '',
                                isset($artifact_relatives['media_decisions']) ? (string) $artifact_relatives['media_decisions'] : '',
                            ]
                        )
                    ),
                    'metadata' => [
                        'review_status' => isset($recommendations['review']['status']) ? (string) $recommendations['review']['status'] : '',
                        'decision_status' => $decision_status,
                    ],
                    'message' => 'Review payload opened in the V2 inspector.',
                ]
            );
        }

        return [
            'runId' => $run_id,
            'pageId' => $page_context['page_id'],
            'domain' => $domain,
            'path' => $page_context['path'],
            'sourceUrl' => $page_context['source_url'],
            'summary' => [
                'reviewStatus' => isset($recommendations['review']['status']) ? (string) $recommendations['review']['status'] : 'needs_review',
                'decisionStatus' => $decision_status,
                'reasonCodes' => isset($recommendations['review']['reason_codes']) && is_array($recommendations['review']['reason_codes'])
                    ? array_values($recommendations['review']['reason_codes'])
                    : [],
                'resolutionMode' => isset($current_target_object['resolutionMode']) ? (string) $current_target_object['resolutionMode'] : '',
                'resolutionReason' => isset($target_transform['resolution_preview']['reason']) ? (string) $target_transform['resolution_preview']['reason'] : '',
                'stale' => $stale,
                'manualOverrideCount' => $manual_override_count,
                'counts' => [
                    'recommendations' => isset($recommendations['recommendations']) && is_array($recommendations['recommendations'])
                        ? count($recommendations['recommendations'])
                        : 0,
                    'mediaRecommendations' => isset($recommendations['media_recommendations']) && is_array($recommendations['media_recommendations'])
                        ? count($recommendations['media_recommendations'])
                        : 0,
                    'unresolved' => isset($recommendations['unresolved_items']) && is_array($recommendations['unresolved_items'])
                        ? count($recommendations['unresolved_items'])
                        : 0,
                    'conflicts' => isset($recommendations['conflicts']) && is_array($recommendations['conflicts'])
                        ? count($recommendations['conflicts'])
                        : 0,
                ],
                'currentTargetObject' => $current_target_object,
                'recommendedTargetObject' => isset($recommendations['recommended_target_object']) && is_array($recommendations['recommended_target_object'])
                    ? $recommendations['recommended_target_object']
                    : [],
            ],
            'recommendations' => [
                'classification' => isset($review_context['initial_classification']['primary_classification']) ? $review_context['initial_classification'] : [],
                'recommendedTargetObject' => isset($recommendations['recommended_target_object']) ? $recommendations['recommended_target_object'] : [],
                'candidateTargetObjects' => isset($recommendations['candidate_target_objects']) ? $recommendations['candidate_target_objects'] : [],
                'fieldRecommendations' => isset($recommendations['recommendations']) ? $recommendations['recommendations'] : [],
                'mediaRecommendations' => isset($recommendations['media_recommendations']) ? $recommendations['media_recommendations'] : [],
                'unresolvedItems' => isset($recommendations['unresolved_items']) ? $recommendations['unresolved_items'] : [],
                'conflicts' => isset($recommendations['conflicts']) ? $recommendations['conflicts'] : [],
                'review' => isset($recommendations['review']) ? $recommendations['review'] : [],
            ],
            'decisions' => [
                'mapping' => $this->build_mapping_decision_payload($mapping_decisions, $page_context, $recommendations),
                'media' => $this->build_media_decision_payload($media_decisions, $page_context),
            ],
            'evidence' => [
                'source' => $this->build_source_evidence($review_context),
                'context' => $this->build_context_evidence($review_context),
                'mapping' => $this->build_mapping_evidence($review_context),
                'audit' => $this->build_audit_evidence($review_context, $decision_status, $artifact_relatives),
            ],
            'actions' => [
                'rerunStages' => DBVC_CC_V2_Contracts::get_supported_ai_stages(),
            ],
        ];
    }

    /**
     * @param string               $domain
     * @param string               $run_id
     * @param string               $page_id
     * @param array<string, mixed> $payload
     * @param int                  $user_id
     * @return array<string, mixed>|WP_Error
     */
    public function save_decisions($domain, $run_id, $page_id, array $payload, $user_id)
    {
        $review_context = $this->load_review_context($domain, $page_id);
        if (is_wp_error($review_context)) {
            return $review_context;
        }

        $page_context = $review_context['page_context'];
        $artifact_paths = isset($page_context['artifact_paths']) && is_array($page_context['artifact_paths']) ? $page_context['artifact_paths'] : [];
        $artifact_relatives = isset($page_context['artifact_relatives']) && is_array($page_context['artifact_relatives']) ? $page_context['artifact_relatives'] : [];
        $recommendations = $review_context['mapping_recommendations'];
        $existing_mapping_decisions = $review_context['mapping_decisions'];
        $existing_media_decisions = $review_context['media_decisions'];
        $reviewer_note = sanitize_textarea_field((string) (isset($payload['reviewerNote']) ? $payload['reviewerNote'] : ''));

        $field_recommendations = isset($recommendations['recommendations']) && is_array($recommendations['recommendations'])
            ? $recommendations['recommendations']
            : [];
        $media_recommendations = isset($recommendations['media_recommendations']) && is_array($recommendations['media_recommendations'])
            ? $recommendations['media_recommendations']
            : [];
        $field_index = $this->index_recommendations($field_recommendations);
        $media_index = $this->index_recommendations($media_recommendations);

        $mapping_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => $page_context['page_id'],
            'source_url' => $page_context['source_url'],
            'generated_at' => current_time('c'),
            'recommendation_fingerprint' => $this->compute_fingerprint($recommendations),
            'decision_status' => 'reviewed',
            'target_object_decision' => $this->sanitize_target_object_decision(
                isset($payload['targetObject']) && is_array($payload['targetObject']) ? $payload['targetObject'] : [],
                $recommendations,
                $reviewer_note
            ),
            'approved' => $this->sanitize_decision_selection(
                isset($payload['approvedRecommendationIds']) ? $payload['approvedRecommendationIds'] : [],
                $field_index
            ),
            'overrides' => $this->sanitize_mapping_overrides(
                isset($payload['mappingOverrides']) ? $payload['mappingOverrides'] : [],
                $field_index
            ),
            'rejected' => $this->sanitize_decision_selection(
                isset($payload['rejectedRecommendationIds']) ? $payload['rejectedRecommendationIds'] : [],
                $field_index
            ),
            'unresolved' => $this->sanitize_decision_selection(
                isset($payload['unresolvedRecommendationIds']) ? $payload['unresolvedRecommendationIds'] : [],
                $field_index
            ),
            'reruns' => $this->sanitize_reruns(
                isset($payload['reruns']) ? $payload['reruns'] : [],
                is_array($existing_mapping_decisions) && isset($existing_mapping_decisions['reruns']) ? $existing_mapping_decisions['reruns'] : []
            ),
            'reviewer_meta' => $this->build_reviewer_meta($user_id, $reviewer_note),
        ];

        $media_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'media-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => $page_context['page_id'],
            'source_url' => $page_context['source_url'],
            'generated_at' => current_time('c'),
            'decision_status' => 'reviewed',
            'approved' => $this->sanitize_decision_selection(
                isset($payload['approvedMediaIds']) ? $payload['approvedMediaIds'] : [],
                $media_index
            ),
            'overrides' => $this->sanitize_media_overrides(
                isset($payload['mediaOverrides']) ? $payload['mediaOverrides'] : [],
                $media_index
            ),
            'ignored' => $this->sanitize_decision_selection(
                isset($payload['ignoredMediaIds']) ? $payload['ignoredMediaIds'] : [],
                $media_index
            ),
            'conflicts' => $this->sanitize_decision_selection(
                isset($payload['conflictingMediaIds']) ? $payload['conflictingMediaIds'] : [],
                $media_index
            ),
        ];

        $manual_override_count = $this->count_overrides($mapping_decisions, $media_decisions);
        if ($manual_override_count > 0) {
            $mapping_decisions['decision_status'] = 'overridden';
            $media_decisions['decision_status'] = ! empty($media_decisions['overrides']) ? 'overridden' : 'reviewed';
        } elseif (! empty($mapping_decisions['unresolved']) || ! empty($media_decisions['conflicts'])) {
            $mapping_decisions['decision_status'] = 'needs_review';
            $media_decisions['decision_status'] = 'needs_review';
        }

        if (
            ! DBVC_CC_Artifact_Manager::write_json_file($artifact_paths['mapping_decisions'], $mapping_decisions)
            || ! DBVC_CC_Artifact_Manager::write_json_file($artifact_paths['media_decisions'], $media_decisions)
        ) {
            return new WP_Error(
                'dbvc_cc_v2_review_write_failed',
                __('Could not write the V2 decision artifacts.', 'dbvc'),
                ['status' => 500]
            );
        }

        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $journey->append_event(
            $domain,
            [
                'journey_id' => $run_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_REVIEW_DECISION_SAVED,
                'step_name' => 'Review decision saved',
                'status' => 'completed',
                'page_id' => $page_context['page_id'],
                'path' => $page_context['path'],
                'source_url' => $page_context['source_url'],
                'actor' => 'admin',
                'trigger' => 'rest',
                'output_artifacts' => [
                    $artifact_relatives['mapping_decisions'],
                    $artifact_relatives['media_decisions'],
                ],
                'metadata' => [
                    'decision_status' => $mapping_decisions['decision_status'],
                    'reviewer_note_present' => $reviewer_note !== '',
                ],
                'message' => 'Review decisions were saved for the selected URL.',
            ]
        );

        if ($manual_override_count > 0) {
            $first_override = isset($mapping_decisions['overrides'][0]) && is_array($mapping_decisions['overrides'][0])
                ? $mapping_decisions['overrides'][0]
                : (isset($media_decisions['overrides'][0]) && is_array($media_decisions['overrides'][0]) ? $media_decisions['overrides'][0] : []);

            $journey->append_event(
                $domain,
                [
                    'journey_id' => $run_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_MANUAL_OVERRIDE_SAVED,
                    'step_name' => 'Manual override saved',
                    'status' => 'completed',
                    'page_id' => $page_context['page_id'],
                    'path' => $page_context['path'],
                    'source_url' => $page_context['source_url'],
                    'actor' => 'admin',
                    'trigger' => 'rest',
                    'output_artifacts' => [
                        $artifact_relatives['mapping_decisions'],
                        $artifact_relatives['media_decisions'],
                    ],
                    'override_scope' => isset($first_override['override_scope']) ? (string) $first_override['override_scope'] : 'target_object',
                    'override_target' => isset($first_override['override_target']) ? (string) $first_override['override_target'] : '',
                    'metadata' => [
                        'manual_override_count' => $manual_override_count,
                    ],
                    'message' => 'Manual overrides were recorded for the selected URL.',
                ]
            );
        }

        return $this->get_review_payload(
            $domain,
            $run_id,
            $page_id,
            [
                'record_presentation' => false,
            ]
        );
    }

    /**
     * @param string $domain
     * @param string $page_id
     * @return array<string, mixed>|WP_Error
     */
    private function load_review_context($domain, $page_id)
    {
        $page_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context($domain, $page_id);
        if (is_wp_error($page_context)) {
            return $page_context;
        }

        $artifact_paths = isset($page_context['artifact_paths']) && is_array($page_context['artifact_paths']) ? $page_context['artifact_paths'] : [];
        $required = [
            'mapping_recommendations' => 'mapping recommendations',
            'target_transform' => 'target transform',
            'initial_classification' => 'initial classification',
            'context_creation' => 'context creation',
            'mapping_index' => 'mapping index',
            'media_candidates' => 'media candidates',
            'raw' => 'raw page',
            'source_normalization' => 'source normalization',
        ];

        $loaded = [
            'page_context' => $page_context,
        ];

        foreach ($required as $key => $label) {
            $artifact = DBVC_CC_V2_Page_Artifact_Service::get_instance()->read_required_artifact(
                isset($artifact_paths[$key]) ? $artifact_paths[$key] : '',
                'dbvc_cc_v2_' . $key . '_missing',
                $label
            );
            if (is_wp_error($artifact)) {
                return $artifact;
            }

            $loaded[$key] = $artifact;
        }

        $loaded['mapping_decisions'] = $this->read_json_file(isset($artifact_paths['mapping_decisions']) ? $artifact_paths['mapping_decisions'] : '');
        $loaded['media_decisions'] = $this->read_json_file(isset($artifact_paths['media_decisions']) ? $artifact_paths['media_decisions'] : '');

        return $loaded;
    }

    /**
     * @param array<string, mixed>      $recommendations
     * @param array<string, mixed>|null $mapping_decisions
     * @return array<string, mixed>
     */
    private function build_current_target_object(array $recommendations, $mapping_decisions)
    {
        $recommended = isset($recommendations['recommended_target_object']) && is_array($recommendations['recommended_target_object'])
            ? $recommendations['recommended_target_object']
            : [];
        $decision = is_array($mapping_decisions) && isset($mapping_decisions['target_object_decision']) && is_array($mapping_decisions['target_object_decision'])
            ? $mapping_decisions['target_object_decision']
            : [];

        $target_object_key = ! empty($decision['selected_target_object_key'])
            ? (string) $decision['selected_target_object_key']
            : (isset($recommended['target_object_key']) ? (string) $recommended['target_object_key'] : '');
        $target_family = ! empty($decision['selected_target_family'])
            ? (string) $decision['selected_target_family']
            : (isset($recommended['target_family']) ? (string) $recommended['target_family'] : '');

        return [
            'targetObjectKey' => $target_object_key,
            'targetFamily' => $target_family,
            'label' => $this->resolve_target_label($target_object_key, $recommendations),
            'resolutionMode' => ! empty($decision['selected_resolution_mode'])
                ? (string) $decision['selected_resolution_mode']
                : (isset($recommended['resolution_mode']) ? (string) $recommended['resolution_mode'] : ''),
            'decisionMode' => ! empty($decision['decision_mode']) ? (string) $decision['decision_mode'] : 'accept_recommended',
        ];
    }

    /**
     * @param array<string, mixed>|null $mapping_decisions
     * @param array<string, mixed>|null $media_decisions
     * @return string
     */
    private function resolve_decision_status($mapping_decisions, $media_decisions)
    {
        $mapping_status = is_array($mapping_decisions) && ! empty($mapping_decisions['decision_status'])
            ? sanitize_key((string) $mapping_decisions['decision_status'])
            : 'pending';
        $media_status = is_array($media_decisions) && ! empty($media_decisions['decision_status'])
            ? sanitize_key((string) $media_decisions['decision_status'])
            : 'pending';

        if (in_array('overridden', [$mapping_status, $media_status], true)) {
            return 'overridden';
        }
        if (in_array('needs_review', [$mapping_status, $media_status], true)) {
            return 'needs_review';
        }
        if ($mapping_status !== 'pending' || $media_status !== 'pending') {
            return 'reviewed';
        }

        return 'pending';
    }

    /**
     * @param array<string, mixed>|null $mapping_decisions
     * @param array<string, mixed>|null $media_decisions
     * @return int
     */
    private function count_overrides($mapping_decisions, $media_decisions)
    {
        $count = 0;
        if (is_array($mapping_decisions)) {
            $count += isset($mapping_decisions['overrides']) && is_array($mapping_decisions['overrides'])
                ? count($mapping_decisions['overrides'])
                : 0;
            if (
                isset($mapping_decisions['target_object_decision']['decision_mode'])
                && (string) $mapping_decisions['target_object_decision']['decision_mode'] === 'override'
            ) {
                ++$count;
            }
        }
        if (is_array($media_decisions)) {
            $count += isset($media_decisions['overrides']) && is_array($media_decisions['overrides'])
                ? count($media_decisions['overrides'])
                : 0;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $review_context
     * @return array<string, mixed>
     */
    private function build_source_evidence(array $review_context)
    {
        $raw = $review_context['raw'];
        $source_normalization = $review_context['source_normalization'];

        return [
            'title' => isset($raw['metadata']['title']) ? (string) $raw['metadata']['title'] : '',
            'description' => isset($raw['metadata']['description']) ? (string) $raw['metadata']['description'] : '',
            'headings' => isset($raw['headings']) && is_array($raw['headings']) ? array_values(array_slice($raw['headings'], 0, 8)) : [],
            'textBlocks' => isset($raw['text_blocks']) && is_array($raw['text_blocks']) ? array_values(array_slice($raw['text_blocks'], 0, 6)) : [],
            'links' => isset($source_normalization['normalized']['links']) && is_array($source_normalization['normalized']['links'])
                ? array_values(array_slice($source_normalization['normalized']['links'], 0, 6))
                : [],
            'images' => isset($raw['images']) && is_array($raw['images']) ? array_values(array_slice($raw['images'], 0, 6)) : [],
        ];
    }

    /**
     * @param array<string, mixed> $review_context
     * @return array<string, mixed>
     */
    private function build_context_evidence(array $review_context)
    {
        $context = $review_context['context_creation'];
        $classification = $review_context['initial_classification'];

        return [
            'contextSummary' => isset($context['summary']) && is_array($context['summary']) ? $context['summary'] : [],
            'contextItems' => isset($context['items']) && is_array($context['items']) ? array_values(array_slice($context['items'], 0, 8)) : [],
            'classification' => [
                'primary' => isset($classification['primary_classification']) ? $classification['primary_classification'] : [],
                'alternates' => isset($classification['alternate_classifications']) ? $classification['alternate_classifications'] : [],
                'taxonomyHints' => isset($classification['taxonomy_hints']) ? $classification['taxonomy_hints'] : [],
                'review' => isset($classification['review']) ? $classification['review'] : [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $review_context
     * @return array<string, mixed>
     */
    private function build_mapping_evidence(array $review_context)
    {
        $mapping_index = $review_context['mapping_index'];
        $media_candidates = $review_context['media_candidates'];
        $target_transform = $review_context['target_transform'];
        $recommendations = $review_context['mapping_recommendations'];

        return [
            'contentItems' => isset($mapping_index['content_items']) && is_array($mapping_index['content_items'])
                ? array_values($mapping_index['content_items'])
                : [],
            'unresolvedItems' => isset($mapping_index['unresolved_items']) && is_array($mapping_index['unresolved_items'])
                ? array_values($mapping_index['unresolved_items'])
                : [],
            'mediaCandidates' => isset($media_candidates['media_items']) && is_array($media_candidates['media_items'])
                ? array_values($media_candidates['media_items'])
                : [],
            'transformItems' => isset($target_transform['transform_items']) && is_array($target_transform['transform_items'])
                ? array_values($target_transform['transform_items'])
                : [],
            'resolutionPreview' => isset($target_transform['resolution_preview']) && is_array($target_transform['resolution_preview'])
                ? $target_transform['resolution_preview']
                : [],
            'recommendations' => isset($recommendations['recommendations']) ? $recommendations['recommendations'] : [],
            'mediaRecommendations' => isset($recommendations['media_recommendations']) ? $recommendations['media_recommendations'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $review_context
     * @param string               $decision_status
     * @param array<string, mixed> $artifact_relatives
     * @return array<string, mixed>
     */
    private function build_audit_evidence(array $review_context, $decision_status, array $artifact_relatives)
    {
        return [
            'decisionStatus' => $decision_status,
            'artifactRefs' => $artifact_relatives,
            'trace' => [
                'contextCreation' => isset($review_context['context_creation']['trace']) ? $review_context['context_creation']['trace'] : [],
                'classification' => isset($review_context['initial_classification']['trace']) ? $review_context['initial_classification']['trace'] : [],
                'recommendations' => isset($review_context['mapping_recommendations']['trace']) ? $review_context['mapping_recommendations']['trace'] : [],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $mapping_decisions
     * @param array<string, mixed>      $page_context
     * @param array<string, mixed>      $recommendations
     * @return array<string, mixed>
     */
    private function build_mapping_decision_payload($mapping_decisions, array $page_context, array $recommendations)
    {
        if (is_array($mapping_decisions)) {
            return $mapping_decisions;
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-decisions.v2',
            'journey_id' => isset($recommendations['journey_id']) ? (string) $recommendations['journey_id'] : '',
            'page_id' => $page_context['page_id'],
            'source_url' => $page_context['source_url'],
            'generated_at' => '',
            'recommendation_fingerprint' => $this->compute_fingerprint($recommendations),
            'decision_status' => 'pending',
            'target_object_decision' => [
                'decision_mode' => 'accept_recommended',
                'selected_target_family' => isset($recommendations['recommended_target_object']['target_family']) ? (string) $recommendations['recommended_target_object']['target_family'] : '',
                'selected_target_object_key' => isset($recommendations['recommended_target_object']['target_object_key']) ? (string) $recommendations['recommended_target_object']['target_object_key'] : '',
                'selected_taxonomy' => '',
                'selected_resolution_mode' => isset($recommendations['recommended_target_object']['resolution_mode']) ? (string) $recommendations['recommended_target_object']['resolution_mode'] : '',
                'based_on_recommendation_id' => '',
                'reviewer_note' => '',
            ],
            'approved' => [],
            'overrides' => [],
            'rejected' => [],
            'unresolved' => [],
            'reruns' => [],
            'reviewer_meta' => [],
        ];
    }

    /**
     * @param array<string, mixed>|null $media_decisions
     * @param array<string, mixed>      $page_context
     * @return array<string, mixed>
     */
    private function build_media_decision_payload($media_decisions, array $page_context)
    {
        if (is_array($media_decisions)) {
            return $media_decisions;
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'media-decisions.v2',
            'journey_id' => '',
            'page_id' => $page_context['page_id'],
            'source_url' => $page_context['source_url'],
            'generated_at' => '',
            'decision_status' => 'pending',
            'approved' => [],
            'overrides' => [],
            'ignored' => [],
            'conflicts' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<string, mixed>>
     */
    private function index_recommendations(array $payload)
    {
        $index = [];
        foreach ($payload as $item) {
            if (! is_array($item) || empty($item['recommendation_id'])) {
                continue;
            }

            $index[(string) $item['recommendation_id']] = $item;
        }

        return $index;
    }

    /**
     * @param mixed                             $selection
     * @param array<string, array<string, mixed>> $index
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_decision_selection($selection, array $index)
    {
        if (! is_array($selection)) {
            return [];
        }

        $items = [];
        foreach ($selection as $recommendation_id) {
            $recommendation_id = sanitize_text_field((string) $recommendation_id);
            if ($recommendation_id === '' || ! isset($index[$recommendation_id])) {
                continue;
            }

            $items[$recommendation_id] = [
                'recommendation_id' => $recommendation_id,
                'target_ref' => isset($index[$recommendation_id]['target_ref']) ? (string) $index[$recommendation_id]['target_ref'] : '',
            ];
        }

        return array_values($items);
    }

    /**
     * @param mixed                             $overrides
     * @param array<string, array<string, mixed>> $index
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_mapping_overrides($overrides, array $index)
    {
        if (! is_array($overrides)) {
            return [];
        }

        $items = [];
        foreach ($overrides as $override) {
            if (! is_array($override)) {
                continue;
            }

            $recommendation_id = sanitize_text_field((string) (isset($override['recommendationId']) ? $override['recommendationId'] : ''));
            if ($recommendation_id === '' || ! isset($index[$recommendation_id])) {
                continue;
            }

            $override_scope = sanitize_key((string) (isset($override['overrideScope']) ? $override['overrideScope'] : 'field'));
            $override_target = sanitize_text_field((string) (isset($override['overrideTarget']) ? $override['overrideTarget'] : ''));
            if ($override_target === '') {
                continue;
            }

            $items[$recommendation_id . '|' . $override_target] = [
                'recommendation_id' => $recommendation_id,
                'override_scope' => in_array($override_scope, ['field', 'taxonomy'], true) ? $override_scope : 'field',
                'override_target' => $override_target,
                'reviewer_note' => sanitize_textarea_field((string) (isset($override['reviewerNote']) ? $override['reviewerNote'] : '')),
                'source_ref' => isset($index[$recommendation_id]['source_refs']) && is_array($index[$recommendation_id]['source_refs'])
                    ? array_values($index[$recommendation_id]['source_refs'])
                    : [],
            ];
        }

        return array_values($items);
    }

    /**
     * @param mixed                             $overrides
     * @param array<string, array<string, mixed>> $index
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_media_overrides($overrides, array $index)
    {
        if (! is_array($overrides)) {
            return [];
        }

        $items = [];
        foreach ($overrides as $override) {
            if (! is_array($override)) {
                continue;
            }

            $recommendation_id = sanitize_text_field((string) (isset($override['recommendationId']) ? $override['recommendationId'] : ''));
            if ($recommendation_id === '' || ! isset($index[$recommendation_id])) {
                continue;
            }

            $override_target = sanitize_text_field((string) (isset($override['overrideTarget']) ? $override['overrideTarget'] : ''));
            if ($override_target === '') {
                continue;
            }

            $items[$recommendation_id . '|' . $override_target] = [
                'recommendation_id' => $recommendation_id,
                'override_scope' => 'media',
                'override_target' => $override_target,
                'reviewer_note' => sanitize_textarea_field((string) (isset($override['reviewerNote']) ? $override['reviewerNote'] : '')),
                'source_ref' => isset($index[$recommendation_id]['source_refs']) && is_array($index[$recommendation_id]['source_refs'])
                    ? array_values($index[$recommendation_id]['source_refs'])
                    : [],
            ];
        }

        return array_values($items);
    }

    /**
     * @param array<string, mixed> $target_object
     * @param array<string, mixed> $recommendations
     * @param string               $reviewer_note
     * @return array<string, mixed>
     */
    private function sanitize_target_object_decision(array $target_object, array $recommendations, $reviewer_note)
    {
        $recommended = isset($recommendations['recommended_target_object']) && is_array($recommendations['recommended_target_object'])
            ? $recommendations['recommended_target_object']
            : [];
        $decision_mode = sanitize_key((string) (isset($target_object['decisionMode']) ? $target_object['decisionMode'] : ''));
        if ($decision_mode !== 'override') {
            $decision_mode = 'accept_recommended';
        }

        $selected_resolution_mode = sanitize_key((string) (isset($target_object['selectedResolutionMode']) ? $target_object['selectedResolutionMode'] : ''));
        if (
            ! in_array(
                $selected_resolution_mode,
                [
                    DBVC_CC_V2_Contracts::RESOLUTION_MODE_UPDATE_EXISTING,
                    DBVC_CC_V2_Contracts::RESOLUTION_MODE_CREATE_NEW,
                    DBVC_CC_V2_Contracts::RESOLUTION_MODE_BLOCKED,
                    DBVC_CC_V2_Contracts::RESOLUTION_MODE_SKIP,
                ],
                true
            )
        ) {
            $selected_resolution_mode = isset($recommended['resolution_mode']) ? (string) $recommended['resolution_mode'] : '';
        }

        return [
            'decision_mode' => $decision_mode,
            'selected_target_family' => sanitize_key((string) (isset($target_object['selectedTargetFamily']) ? $target_object['selectedTargetFamily'] : (isset($recommended['target_family']) ? $recommended['target_family'] : ''))),
            'selected_target_object_key' => sanitize_key((string) (isset($target_object['selectedTargetObjectKey']) ? $target_object['selectedTargetObjectKey'] : (isset($recommended['target_object_key']) ? $recommended['target_object_key'] : ''))),
            'selected_taxonomy' => sanitize_key((string) (isset($target_object['selectedTaxonomy']) ? $target_object['selectedTaxonomy'] : '')),
            'selected_resolution_mode' => $selected_resolution_mode,
            'based_on_recommendation_id' => sanitize_text_field((string) (isset($target_object['basedOnRecommendationId']) ? $target_object['basedOnRecommendationId'] : '')),
            'reviewer_note' => $reviewer_note,
        ];
    }

    /**
     * @param mixed $reruns
     * @param mixed $existing_reruns
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_reruns($reruns, $existing_reruns)
    {
        $items = [];
        $all = [];
        if (is_array($existing_reruns)) {
            $all = array_merge($all, $existing_reruns);
        }
        if (is_array($reruns)) {
            $all = array_merge($all, $reruns);
        }

        foreach ($all as $rerun) {
            if (! is_array($rerun)) {
                continue;
            }

            $stage = sanitize_key((string) (isset($rerun['rerunStage']) ? $rerun['rerunStage'] : (isset($rerun['rerun_stage']) ? $rerun['rerun_stage'] : '')));
            if (! DBVC_CC_V2_Contracts::is_supported_ai_stage($stage)) {
                continue;
            }

            $items[$stage] = [
                'rerun_stage' => $stage,
                'requested_at' => ! empty($rerun['requested_at']) ? sanitize_text_field((string) $rerun['requested_at']) : current_time('c'),
            ];
        }

        return array_values($items);
    }

    /**
     * @param int    $user_id
     * @param string $reviewer_note
     * @return array<string, mixed>
     */
    private function build_reviewer_meta($user_id, $reviewer_note)
    {
        $user = get_user_by('id', absint($user_id));

        return [
            'user_id' => absint($user_id),
            'display_name' => $user instanceof WP_User ? (string) $user->display_name : '',
            'reviewed_at' => current_time('c'),
            'reviewer_note' => $reviewer_note,
        ];
    }

    /**
     * @param string               $target_object_key
     * @param array<string, mixed> $recommendations
     * @return string
     */
    private function resolve_target_label($target_object_key, array $recommendations)
    {
        $target_object_key = sanitize_key((string) $target_object_key);
        $candidates = isset($recommendations['candidate_target_objects']) && is_array($recommendations['candidate_target_objects'])
            ? $recommendations['candidate_target_objects']
            : [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            if (isset($candidate['target_object_key']) && (string) $candidate['target_object_key'] === $target_object_key) {
                return isset($candidate['label']) ? (string) $candidate['label'] : $target_object_key;
            }
        }

        return $target_object_key;
    }

    /**
     * @param array<string, mixed> $artifact
     * @return string
     */
    private function compute_fingerprint(array $artifact)
    {
        return hash('sha256', (string) wp_json_encode($artifact, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        $path = (string) $path;
        if ($path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
