<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Run_Action_Summary_Service
{
    /**
     * @var DBVC_CC_V2_Run_Action_Summary_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Run_Action_Summary_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed> $latest
     * @return array<string, mixed>
     */
    public function build_summary(array $latest)
    {
        $rows = isset($latest['latest_stage_by_url']) && is_array($latest['latest_stage_by_url'])
            ? $latest['latest_stage_by_url']
            : [];

        $groups = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = sanitize_key((string) ($row['status'] ?? ''));
            if (! in_array($status, ['failed', 'blocked'], true)) {
                continue;
            }

            $stage_definition = $this->map_step_to_rerun_stage((string) ($row['stepKey'] ?? ''));
            if (empty($stage_definition['stage'])) {
                continue;
            }

            $stage = (string) $stage_definition['stage'];
            if (! isset($groups[$stage])) {
                $groups[$stage] = [
                    'stage' => $stage,
                    'label' => (string) $stage_definition['label'],
                    'description' => (string) $stage_definition['description'],
                    'pageIds' => [],
                    'statuses' => [],
                    'stepKeys' => [],
                    'blockedCount' => 0,
                    'failedCount' => 0,
                ];
            }

            $page_id = sanitize_text_field((string) ($row['pageId'] ?? ''));
            if ($page_id !== '') {
                $groups[$stage]['pageIds'][$page_id] = $page_id;
            }

            $groups[$stage]['statuses'][$status] = $status;
            $step_key = sanitize_key((string) ($row['stepKey'] ?? ''));
            if ($step_key !== '') {
                $groups[$stage]['stepKeys'][$step_key] = $step_key;
            }

            if ($status === 'blocked') {
                ++$groups[$stage]['blockedCount'];
            } elseif ($status === 'failed') {
                ++$groups[$stage]['failedCount'];
            }
        }

        $ordered = [];
        foreach (DBVC_CC_V2_Contracts::get_supported_ai_stages() as $stage) {
            if (! isset($groups[$stage])) {
                continue;
            }

            $candidate = $groups[$stage];
            $page_ids = array_values($candidate['pageIds']);
            sort($page_ids);

            $statuses = array_values($candidate['statuses']);
            sort($statuses);

            $step_keys = array_values($candidate['stepKeys']);
            sort($step_keys);

            $ordered[] = [
                'stage' => $stage,
                'label' => $candidate['label'],
                'description' => $candidate['description'],
                'count' => count($page_ids),
                'pageIds' => $page_ids,
                'statuses' => $statuses,
                'stepKeys' => $step_keys,
                'blockedCount' => (int) $candidate['blockedCount'],
                'failedCount' => (int) $candidate['failedCount'],
            ];
        }

        return [
            'rerunCandidates' => $ordered,
            'counts' => [
                'rerunnableStageCount' => count($ordered),
                'rerunnableUrlCount' => array_reduce(
                    $ordered,
                    static function ($carry, $candidate) {
                        return $carry + (isset($candidate['count']) ? (int) $candidate['count'] : 0);
                    },
                    0
                ),
            ],
        ];
    }

    /**
     * @param string $stage
     * @return array<string, mixed>
     */
    public function get_stage_fixture_definition($stage)
    {
        $definitions = $this->get_rerun_stage_definitions();
        $stage = sanitize_key((string) $stage);

        if (! isset($definitions[$stage])) {
            return [];
        }

        $definition = $definitions[$stage];

        return [
            'stage' => (string) $definition['stage'],
            'label' => (string) $definition['label'],
            'description' => (string) $definition['description'],
            'stepKey' => (string) $definition['defaultStepKey'],
            'stepKeys' => isset($definition['stepKeys']) && is_array($definition['stepKeys'])
                ? array_values($definition['stepKeys'])
                : [],
        ];
    }

    /**
     * @param string $step_key
     * @return array<string, string>
     */
    private function map_step_to_rerun_stage($step_key)
    {
        $step_key = sanitize_key((string) $step_key);
        foreach ($this->get_rerun_stage_definitions() as $definition) {
            $step_keys = isset($definition['stepKeys']) && is_array($definition['stepKeys'])
                ? $definition['stepKeys']
                : [];
            if (! in_array($step_key, $step_keys, true)) {
                continue;
            }

            return [
                'stage' => (string) $definition['stage'],
                'label' => (string) $definition['label'],
                'description' => (string) $definition['description'],
            ];
        }

        return [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_rerun_stage_definitions()
    {
        return [
            DBVC_CC_V2_Contracts::AI_STAGE_CONTEXT_CREATION => [
                'stage' => DBVC_CC_V2_Contracts::AI_STAGE_CONTEXT_CREATION,
                'label' => 'Rerun context pipeline',
                'description' => 'Rebuild context and all downstream recommendations for affected URLs.',
                'defaultStepKey' => DBVC_CC_V2_Contracts::STEP_CONTEXT_CREATION_COMPLETED,
                'stepKeys' => [
                    DBVC_CC_V2_Contracts::STEP_CONTEXT_CREATION_COMPLETED,
                ],
            ],
            DBVC_CC_V2_Contracts::AI_STAGE_INITIAL_CLASSIFICATION => [
                'stage' => DBVC_CC_V2_Contracts::AI_STAGE_INITIAL_CLASSIFICATION,
                'label' => 'Rerun classification',
                'description' => 'Replay initial classification for affected URLs.',
                'defaultStepKey' => DBVC_CC_V2_Contracts::STEP_INITIAL_CLASSIFICATION_COMPLETED,
                'stepKeys' => [
                    DBVC_CC_V2_Contracts::STEP_INITIAL_CLASSIFICATION_COMPLETED,
                ],
            ],
            DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX => [
                'stage' => DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX,
                'label' => 'Rerun mapping pipeline',
                'description' => 'Replay mapping, target transform, and finalization for affected URLs.',
                'defaultStepKey' => DBVC_CC_V2_Contracts::STEP_MAPPING_INDEX_COMPLETED,
                'stepKeys' => [
                    DBVC_CC_V2_Contracts::STEP_MAPPING_INDEX_COMPLETED,
                    DBVC_CC_V2_Contracts::STEP_TARGET_TRANSFORM_COMPLETED,
                ],
            ],
            DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION => [
                'stage' => DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION,
                'label' => 'Rerun recommendation finalization',
                'description' => 'Rebuild final recommendations for affected URLs.',
                'defaultStepKey' => DBVC_CC_V2_Contracts::STEP_RECOMMENDED_MAPPINGS_FINALIZED,
                'stepKeys' => [
                    DBVC_CC_V2_Contracts::STEP_RECOMMENDED_MAPPINGS_FINALIZED,
                ],
            ],
        ];
    }
}
