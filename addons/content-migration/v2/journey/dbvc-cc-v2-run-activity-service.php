<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Run_Activity_Service
{
    /**
     * @var DBVC_CC_V2_Run_Activity_Service|null
     */
    private static $instance = null;

    /**
     * @var int
     */
    private const DEFAULT_LIMIT = 12;

    /**
     * @return DBVC_CC_V2_Run_Activity_Service
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
     * @param string $run_id
     * @param int    $limit
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function get_recent_activity($domain, $run_id, $limit = self::DEFAULT_LIMIT)
    {
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') {
            return [];
        }

        $limit = absint($limit);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $events = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_events($domain);
        if (is_wp_error($events)) {
            return $events;
        }

        if (empty($events)) {
            return [];
        }

        $activity = [];
        for ($index = count($events) - 1; $index >= 0 && count($activity) < $limit; --$index) {
            $event = isset($events[$index]) && is_array($events[$index]) ? $events[$index] : [];
            if (! is_array($event)) {
                continue;
            }

            if ((string) ($event['journey_id'] ?? '') !== $run_id) {
                continue;
            }

            $activity[] = $this->format_activity_item($event, $index);
        }

        return $activity;
    }

    /**
     * @param array<string, mixed> $event
     * @param int                  $index
     * @return array<string, mixed>
     */
    private function format_activity_item(array $event, $index)
    {
        $input_artifacts = isset($event['input_artifacts']) && is_array($event['input_artifacts'])
            ? array_values($event['input_artifacts'])
            : [];
        $output_artifacts = isset($event['output_artifacts']) && is_array($event['output_artifacts'])
            ? array_values($event['output_artifacts'])
            : [];
        $started_at = isset($event['started_at']) ? (string) $event['started_at'] : '';
        $finished_at = isset($event['finished_at']) && (string) $event['finished_at'] !== ''
            ? (string) $event['finished_at']
            : $started_at;
        $page_id = isset($event['page_id']) ? sanitize_text_field((string) $event['page_id']) : '';
        $path = isset($event['path']) ? sanitize_text_field((string) $event['path']) : '';
        $package_id = isset($event['package_id']) ? sanitize_text_field((string) $event['package_id']) : '';
        $step_key = isset($event['step_key']) ? sanitize_key((string) $event['step_key']) : '';
        $status = isset($event['status']) ? sanitize_key((string) $event['status']) : 'queued';
        $run_id = isset($event['journey_id']) ? sanitize_text_field((string) $event['journey_id']) : '';

        return [
            'activityId' => $this->build_activity_id($run_id, $step_key, $page_id, $package_id, $finished_at, $index),
            'runId' => $run_id,
            'stepKey' => $step_key,
            'stepName' => isset($event['step_name']) ? sanitize_text_field((string) $event['step_name']) : '',
            'status' => $status,
            'message' => isset($event['message']) ? sanitize_text_field((string) $event['message']) : '',
            'startedAt' => $started_at,
            'finishedAt' => $finished_at,
            'durationMs' => isset($event['duration_ms']) ? absint($event['duration_ms']) : 0,
            'actor' => isset($event['actor']) ? sanitize_key((string) $event['actor']) : '',
            'trigger' => isset($event['trigger']) ? sanitize_key((string) $event['trigger']) : '',
            'pageId' => $page_id,
            'path' => $path,
            'sourceUrl' => isset($event['source_url']) ? esc_url_raw((string) $event['source_url']) : '',
            'packageId' => $package_id,
            'exceptionState' => isset($event['exception_state']) ? sanitize_key((string) $event['exception_state']) : '',
            'warningCodes' => isset($event['warning_codes']) && is_array($event['warning_codes'])
                ? array_values(array_map('sanitize_key', $event['warning_codes']))
                : [],
            'errorCode' => isset($event['error_code']) ? sanitize_key((string) $event['error_code']) : '',
            'inputArtifacts' => $input_artifacts,
            'outputArtifacts' => $output_artifacts,
            'artifactCounts' => [
                'input' => count($input_artifacts),
                'output' => count($output_artifacts),
            ],
        ];
    }

    /**
     * @param string $run_id
     * @param string $step_key
     * @param string $page_id
     * @param string $package_id
     * @param string $finished_at
     * @param int    $index
     * @return string
     */
    private function build_activity_id($run_id, $step_key, $page_id, $package_id, $finished_at, $index)
    {
        return substr(
            md5(
                implode(
                    '|',
                    [
                        (string) $run_id,
                        (string) $step_key,
                        (string) $page_id,
                        (string) $package_id,
                        (string) $finished_at,
                        (string) $index,
                    ]
                )
            ),
            0,
            12
        );
    }
}
