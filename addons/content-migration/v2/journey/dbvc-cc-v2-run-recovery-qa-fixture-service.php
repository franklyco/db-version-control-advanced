<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Run_Recovery_QA_Fixture_Service
{
    private const USER_META_KEY = 'dbvc_cc_v2_qa_recovery_fixture';

    /**
     * @var DBVC_CC_V2_Run_Recovery_QA_Fixture_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Run_Recovery_QA_Fixture_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return bool
     */
    public function is_available()
    {
        $enabled = defined('DBVC_PHPUNIT') && DBVC_PHPUNIT;
        if (! $enabled) {
            $enabled = defined('WP_DEBUG') && WP_DEBUG;
        }

        return (bool) apply_filters('dbvc_cc_v2_enable_recovery_qa_fixture', $enabled);
    }

    /**
     * @param string               $run_id
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public function apply_to_action_summary($run_id, array $summary)
    {
        $fixture = $this->get_fixture();
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '' || empty($fixture) || (string) ($fixture['runId'] ?? '') !== $run_id) {
            return $summary;
        }

        $candidate = isset($fixture['candidate']) && is_array($fixture['candidate'])
            ? $fixture['candidate']
            : [];
        if (empty($candidate)) {
            return $summary;
        }

        $summary['rerunCandidates'] = [$candidate];
        $summary['counts'] = [
            'rerunnableStageCount' => 1,
            'rerunnableUrlCount' => isset($candidate['count']) ? (int) $candidate['count'] : 0,
        ];

        return $summary;
    }

    /**
     * @param string               $run_id
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function seed_fixture($run_id, array $args = [])
    {
        if (! $this->is_available()) {
            return new WP_Error(
                'dbvc_cc_v2_qa_fixture_unavailable',
                __('The V2 recovery QA fixture helper is unavailable in this environment.', 'dbvc'),
                ['status' => 403]
            );
        }

        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') {
            return new WP_Error(
                'dbvc_cc_v2_qa_fixture_run_required',
                __('A V2 run ID is required to seed recovery QA data.', 'dbvc'),
                ['status' => 400]
            );
        }

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

        $stage = isset($args['stage']) ? sanitize_key((string) $args['stage']) : DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION;
        $stage_definition = DBVC_CC_V2_Run_Action_Summary_Service::get_instance()->get_stage_fixture_definition($stage);
        if (empty($stage_definition['stage']) || empty($stage_definition['stepKey'])) {
            return new WP_Error(
                'dbvc_cc_v2_qa_fixture_stage_invalid',
                __('The requested QA recovery stage is not supported.', 'dbvc'),
                ['status' => 400]
            );
        }

        $page = $this->select_page(isset($args['pageId']) ? $args['pageId'] : '', $latest);
        if (empty($page['pageId'])) {
            return new WP_Error(
                'dbvc_cc_v2_qa_fixture_page_missing',
                __('The requested V2 run does not have a page available for seeded recovery QA.', 'dbvc'),
                ['status' => 400]
            );
        }

        $fixture = [
            'runId' => $run_id,
            'domain' => $domain,
            'stage' => (string) $stage_definition['stage'],
            'pageId' => (string) $page['pageId'],
            'path' => (string) $page['path'],
            'sourceUrl' => (string) $page['sourceUrl'],
            'seededAt' => current_time('c'),
            'candidate' => [
                'stage' => (string) $stage_definition['stage'],
                'label' => (string) $stage_definition['label'],
                'description' => (string) $stage_definition['description'],
                'count' => 1,
                'pageIds' => [(string) $page['pageId']],
                'statuses' => ['failed'],
                'stepKeys' => [(string) $stage_definition['stepKey']],
                'blockedCount' => 0,
                'failedCount' => 1,
            ],
        ];

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error(
                'dbvc_cc_v2_qa_fixture_user_missing',
                __('A logged-in user is required to seed V2 recovery QA data.', 'dbvc'),
                ['status' => 401]
            );
        }

        update_user_meta($user_id, self::USER_META_KEY, $fixture);

        return $fixture;
    }

    /**
     * @param string $run_id
     * @return array<string, mixed>
     */
    public function clear_fixture($run_id = '')
    {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            delete_user_meta($user_id, self::USER_META_KEY);
        }

        return [
            'runId' => sanitize_text_field((string) $run_id),
            'enabled' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_fixture()
    {
        if (! $this->is_available()) {
            return [];
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return [];
        }

        $stored = get_user_meta($user_id, self::USER_META_KEY, true);
        if (! is_array($stored)) {
            return [];
        }

        return $this->normalize_fixture($stored);
    }

    /**
     * @param mixed                $page_id
     * @param array<string, mixed> $latest
     * @return array<string, string>
     */
    private function select_page($page_id, array $latest)
    {
        $page_id = sanitize_text_field((string) $page_id);
        $rows = isset($latest['latest_stage_by_url']) && is_array($latest['latest_stage_by_url'])
            ? $latest['latest_stage_by_url']
            : [];

        $pages = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $candidate_page_id = sanitize_text_field((string) ($row['pageId'] ?? ''));
            if ($candidate_page_id === '') {
                continue;
            }

            $pages[$candidate_page_id] = [
                'pageId' => $candidate_page_id,
                'path' => sanitize_text_field((string) ($row['path'] ?? '')),
                'sourceUrl' => esc_url_raw((string) ($row['sourceUrl'] ?? '')),
            ];
        }

        if ($page_id !== '' && isset($pages[$page_id])) {
            return $pages[$page_id];
        }

        if (empty($pages)) {
            return [];
        }

        uasort(
            $pages,
            static function ($left, $right) {
                $left_path = isset($left['path']) ? (string) $left['path'] : '';
                $right_path = isset($right['path']) ? (string) $right['path'] : '';
                $path_compare = strnatcasecmp($left_path, $right_path);
                if ($path_compare !== 0) {
                    return $path_compare;
                }

                return strnatcasecmp(
                    isset($left['pageId']) ? (string) $left['pageId'] : '',
                    isset($right['pageId']) ? (string) $right['pageId'] : ''
                );
            }
        );

        return (array) reset($pages);
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function normalize_fixture(array $stored)
    {
        $candidate = isset($stored['candidate']) && is_array($stored['candidate'])
            ? $stored['candidate']
            : [];

        $page_ids = isset($candidate['pageIds']) && is_array($candidate['pageIds'])
            ? array_values(array_filter(array_map('sanitize_text_field', $candidate['pageIds'])))
            : [];
        $statuses = isset($candidate['statuses']) && is_array($candidate['statuses'])
            ? array_values(array_filter(array_map('sanitize_key', $candidate['statuses'])))
            : [];
        $step_keys = isset($candidate['stepKeys']) && is_array($candidate['stepKeys'])
            ? array_values(array_filter(array_map('sanitize_key', $candidate['stepKeys'])))
            : [];

        return [
            'runId' => sanitize_text_field((string) ($stored['runId'] ?? '')),
            'domain' => sanitize_text_field((string) ($stored['domain'] ?? '')),
            'stage' => sanitize_key((string) ($stored['stage'] ?? '')),
            'pageId' => sanitize_text_field((string) ($stored['pageId'] ?? '')),
            'path' => sanitize_text_field((string) ($stored['path'] ?? '')),
            'sourceUrl' => esc_url_raw((string) ($stored['sourceUrl'] ?? '')),
            'seededAt' => sanitize_text_field((string) ($stored['seededAt'] ?? '')),
            'candidate' => [
                'stage' => sanitize_key((string) ($candidate['stage'] ?? '')),
                'label' => sanitize_text_field((string) ($candidate['label'] ?? '')),
                'description' => sanitize_text_field((string) ($candidate['description'] ?? '')),
                'count' => max(0, absint($candidate['count'] ?? 0)),
                'pageIds' => $page_ids,
                'statuses' => $statuses,
                'stepKeys' => $step_keys,
                'blockedCount' => max(0, absint($candidate['blockedCount'] ?? 0)),
                'failedCount' => max(0, absint($candidate['failedCount'] ?? 0)),
            ],
        ];
    }
}
