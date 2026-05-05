<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Schema_Sync_Service
{
    /**
     * @var DBVC_CC_V2_Schema_Sync_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Schema_Sync_Service
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
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function sync_domain($domain, array $args = [])
    {
        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $context = $journey->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $journey_id = isset($args['journey_id']) ? sanitize_text_field((string) $args['journey_id']) : '';
        if ($journey_id === '') {
            $journey_id = DBVC_CC_V2_Contracts::generate_run_id($context['domain']);
        }

        $actor = isset($args['actor']) ? sanitize_key((string) $args['actor']) : 'system';
        $trigger = isset($args['trigger']) ? sanitize_key((string) $args['trigger']) : 'manual';
        $force_rebuild = ! empty($args['force_rebuild']);

        $journey_start = $this->append_timed_event(
            $context['domain'],
            $journey_id,
            DBVC_CC_V2_Contracts::STEP_DOMAIN_JOURNEY_STARTED,
            'Domain journey started',
            static function () {
                return [
                    'status' => 'completed',
                    'message' => 'Domain journey initialized for target schema sync.',
                ];
            },
            $actor,
            $trigger
        );
        if (is_wp_error($journey_start)) {
            return $journey_start;
        }

        $inventory_result = $this->append_timed_event(
            $context['domain'],
            $journey_id,
            DBVC_CC_V2_Contracts::STEP_TARGET_OBJECT_INVENTORY_BUILT,
            'Target object inventory built',
            function () use ($context, $force_rebuild) {
                $result = DBVC_CC_V2_Target_Object_Inventory_Service::get_instance()->build_inventory($context['domain'], $force_rebuild);
                if (is_wp_error($result)) {
                    return $result;
                }

                return [
                    'status' => 'completed',
                    'output_artifacts' => [$result['artifact_relative_path']],
                    'source_fingerprint' => isset($result['snapshot_hash']) ? (string) $result['snapshot_hash'] : '',
                    'schema_fingerprint' => isset($result['inventory_fingerprint']) ? (string) $result['inventory_fingerprint'] : '',
                    'message' => 'Target object inventory materialized for the source domain.',
                    'metadata' => [
                        'inventory_status' => isset($result['status']) ? (string) $result['status'] : '',
                    ],
                    'result' => $result,
                ];
            },
            $actor,
            $trigger
        );
        if (is_wp_error($inventory_result)) {
            $this->append_failure_event(
                $context['domain'],
                $journey_id,
                DBVC_CC_V2_Contracts::STEP_TARGET_SCHEMA_SYNC_COMPLETED,
                'Target schema sync completed',
                $inventory_result,
                $actor,
                $trigger
            );

            return $inventory_result;
        }

        $inventory_payload = isset($inventory_result['result']) && is_array($inventory_result['result']) ? $inventory_result['result'] : [];

        $catalog_result = $this->append_timed_event(
            $context['domain'],
            $journey_id,
            DBVC_CC_V2_Contracts::STEP_TARGET_SCHEMA_CATALOG_BUILT,
            'Target schema catalog built',
            function () use ($context, $force_rebuild, $inventory_payload) {
                $result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($context['domain'], $force_rebuild);
                if (is_wp_error($result)) {
                    return $result;
                }

                return [
                    'status' => 'completed',
                    'input_artifacts' => isset($inventory_payload['artifact_relative_path']) ? [$inventory_payload['artifact_relative_path']] : [],
                    'output_artifacts' => [$result['artifact_relative_path']],
                    'source_fingerprint' => isset($result['source_snapshot_hash']) ? (string) $result['source_snapshot_hash'] : '',
                    'schema_fingerprint' => isset($result['catalog_fingerprint']) ? (string) $result['catalog_fingerprint'] : '',
                    'message' => 'Target field catalog materialized for narrowed target mapping.',
                    'metadata' => [
                        'catalog_status' => isset($result['status']) ? (string) $result['status'] : '',
                        'inventory_fingerprint' => isset($result['inventory_fingerprint']) ? (string) $result['inventory_fingerprint'] : '',
                    ],
                    'result' => $result,
                ];
            },
            $actor,
            $trigger
        );
        if (is_wp_error($catalog_result)) {
            $this->append_failure_event(
                $context['domain'],
                $journey_id,
                DBVC_CC_V2_Contracts::STEP_TARGET_SCHEMA_SYNC_COMPLETED,
                'Target schema sync completed',
                $catalog_result,
                $actor,
                $trigger
            );

            return $catalog_result;
        }

        $catalog_payload = isset($catalog_result['result']) && is_array($catalog_result['result']) ? $catalog_result['result'] : [];
        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($context['domain'], $force_rebuild);
        if (is_wp_error($slot_graph_result)) {
            $this->append_failure_event(
                $context['domain'],
                $journey_id,
                DBVC_CC_V2_Contracts::STEP_TARGET_SCHEMA_SYNC_COMPLETED,
                'Target schema sync completed',
                $slot_graph_result,
                $actor,
                $trigger
            );

            return $slot_graph_result;
        }

        $slot_graph_payload = isset($slot_graph_result['slot_graph']) && is_array($slot_graph_result['slot_graph'])
            ? $slot_graph_result['slot_graph']
            : [];
        $schema_fingerprint = hash(
            'sha256',
            (string) wp_json_encode(
                [
                    'inventory_fingerprint' => isset($inventory_payload['inventory_fingerprint']) ? (string) $inventory_payload['inventory_fingerprint'] : '',
                    'catalog_fingerprint' => isset($catalog_payload['catalog_fingerprint']) ? (string) $catalog_payload['catalog_fingerprint'] : '',
                    'slot_graph_fingerprint' => isset($slot_graph_payload['slot_graph_fingerprint']) ? (string) $slot_graph_payload['slot_graph_fingerprint'] : '',
                ],
                JSON_UNESCAPED_SLASHES
            )
        );

        $sync_complete = $this->append_timed_event(
            $context['domain'],
            $journey_id,
            DBVC_CC_V2_Contracts::STEP_TARGET_SCHEMA_SYNC_COMPLETED,
            'Target schema sync completed',
            function () use ($inventory_payload, $catalog_payload, $slot_graph_result, $slot_graph_payload, $schema_fingerprint) {
                $output_artifacts = [];
                if (isset($inventory_payload['artifact_relative_path'])) {
                    $output_artifacts[] = (string) $inventory_payload['artifact_relative_path'];
                }
                if (isset($catalog_payload['artifact_relative_path'])) {
                    $output_artifacts[] = (string) $catalog_payload['artifact_relative_path'];
                }
                if (isset($slot_graph_result['artifact_relative_path'])) {
                    $output_artifacts[] = (string) $slot_graph_result['artifact_relative_path'];
                }

                return [
                    'status' => 'completed',
                    'output_artifacts' => $output_artifacts,
                    'source_fingerprint' => isset($catalog_payload['source_snapshot_hash']) ? (string) $catalog_payload['source_snapshot_hash'] : '',
                    'schema_fingerprint' => $schema_fingerprint,
                    'message' => 'Target schema sync completed for the source domain.',
                    'metadata' => [
                        'inventory_fingerprint' => isset($inventory_payload['inventory_fingerprint']) ? (string) $inventory_payload['inventory_fingerprint'] : '',
                        'catalog_fingerprint' => isset($catalog_payload['catalog_fingerprint']) ? (string) $catalog_payload['catalog_fingerprint'] : '',
                        'slot_graph_fingerprint' => isset($slot_graph_payload['slot_graph_fingerprint']) ? (string) $slot_graph_payload['slot_graph_fingerprint'] : '',
                    ],
                ];
            },
            $actor,
            $trigger
        );
        if (is_wp_error($sync_complete)) {
            return $sync_complete;
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
            'inventory' => $inventory_payload,
            'catalog' => $catalog_payload,
            'slot_graph' => $slot_graph_payload,
            'latest' => $latest,
            'stage_summary' => $stage_summary,
            'schema_fingerprint' => $schema_fingerprint,
        ];
    }

    /**
     * @param string   $domain
     * @param string   $journey_id
     * @param string   $step_key
     * @param string   $step_name
     * @param callable $callback
     * @param string   $actor
     * @param string   $trigger
     * @return array<string, mixed>|WP_Error
     */
    private function append_timed_event($domain, $journey_id, $step_key, $step_name, callable $callback, $actor, $trigger)
    {
        $started_at = current_time('c');
        $start_tick = microtime(true);
        $result = call_user_func($callback);
        if (is_wp_error($result)) {
            return $this->append_failure_event($domain, $journey_id, $step_key, $step_name, $result, $actor, $trigger, $started_at, $start_tick);
        }

        $result = is_array($result) ? $result : [];
        $event = [
            'journey_id' => $journey_id,
            'step_key' => $step_key,
            'step_name' => $step_name,
            'status' => isset($result['status']) ? (string) $result['status'] : 'completed',
            'started_at' => $started_at,
            'finished_at' => current_time('c'),
            'duration_ms' => (int) round((microtime(true) - $start_tick) * 1000),
            'actor' => $actor,
            'trigger' => $trigger,
            'input_artifacts' => isset($result['input_artifacts']) ? $result['input_artifacts'] : [],
            'output_artifacts' => isset($result['output_artifacts']) ? $result['output_artifacts'] : [],
            'source_fingerprint' => isset($result['source_fingerprint']) ? (string) $result['source_fingerprint'] : '',
            'schema_fingerprint' => isset($result['schema_fingerprint']) ? (string) $result['schema_fingerprint'] : '',
            'message' => isset($result['message']) ? (string) $result['message'] : '',
            'metadata' => isset($result['metadata']) && is_array($result['metadata']) ? $result['metadata'] : [],
        ];

        $append = DBVC_CC_V2_Domain_Journey_Service::get_instance()->append_event($domain, $event);
        if (is_wp_error($append)) {
            return $append;
        }

        $append['result'] = isset($result['result']) ? $result['result'] : [];
        return $append;
    }

    /**
     * @param string        $domain
     * @param string        $journey_id
     * @param string        $step_key
     * @param string        $step_name
     * @param WP_Error      $error
     * @param string        $actor
     * @param string        $trigger
     * @param string|null   $started_at
     * @param float|null    $start_tick
     * @return WP_Error
     */
    private function append_failure_event($domain, $journey_id, $step_key, $step_name, WP_Error $error, $actor, $trigger, $started_at = null, $start_tick = null)
    {
        DBVC_CC_V2_Domain_Journey_Service::get_instance()->append_event(
            $domain,
            [
                'journey_id' => $journey_id,
                'step_key' => $step_key,
                'step_name' => $step_name,
                'status' => 'failed',
                'started_at' => $started_at !== null ? (string) $started_at : current_time('c'),
                'finished_at' => current_time('c'),
                'duration_ms' => $start_tick !== null ? (int) round((microtime(true) - (float) $start_tick) * 1000) : 0,
                'actor' => $actor,
                'trigger' => $trigger,
                'error_code' => $error->get_error_code(),
                'message' => $error->get_error_message(),
            ]
        );

        return $error;
    }
}
