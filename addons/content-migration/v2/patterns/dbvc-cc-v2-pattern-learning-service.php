<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Pattern_Learning_Service
{
    /**
     * @var DBVC_CC_V2_Pattern_Learning_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Pattern_Learning_Service
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
     * @return array<string, mixed>|WP_Error
     */
    public function get_pattern_memory($domain)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $payload = $this->read_json_file($context['pattern_memory_file']);
        if (is_array($payload)) {
            return $payload;
        }

        return $this->build_default_payload($context['domain']);
    }

    /**
     * @param string $domain
     * @param string $object_key
     * @param array<int, string> $pattern_keys
     * @return array<int, array<string, mixed>>
     */
    public function find_matches($domain, $object_key, array $pattern_keys)
    {
        $memory = $this->get_pattern_memory($domain);
        if (is_wp_error($memory)) {
            return [];
        }

        $object_key = sanitize_key((string) $object_key);
        $lookup_keys = array_values(array_unique(array_filter(array_map('sanitize_key', $pattern_keys))));
        if ($object_key === '' || empty($lookup_keys)) {
            return [];
        }

        $matches = [];
        $groups = isset($memory['pattern_groups']) && is_array($memory['pattern_groups']) ? $memory['pattern_groups'] : [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $group_object_key = isset($group['target_object_key']) ? sanitize_key((string) $group['target_object_key']) : '';
            $pattern_key = isset($group['pattern_key']) ? sanitize_key((string) $group['pattern_key']) : '';
            if ($group_object_key !== $object_key || ! in_array($pattern_key, $lookup_keys, true)) {
                continue;
            }

            $target_refs = isset($group['target_refs']) && is_array($group['target_refs']) ? $group['target_refs'] : [];
            foreach ($target_refs as $target_ref) {
                $target_ref = sanitize_text_field((string) $target_ref);
                if ($target_ref === '') {
                    continue;
                }

                $matches[] = [
                    'pattern_key' => $pattern_key,
                    'target_ref' => $target_ref,
                    'confidence' => isset($group['average_confidence']) ? (float) $group['average_confidence'] : 0.0,
                    'occurrence_count' => isset($group['occurrence_count']) ? (int) $group['occurrence_count'] : 0,
                ];
            }
        }

        usort(
            $matches,
            static function ($left, $right) {
                $left_confidence = isset($left['confidence']) ? (float) $left['confidence'] : 0.0;
                $right_confidence = isset($right['confidence']) ? (float) $right['confidence'] : 0.0;
                if ($left_confidence === $right_confidence) {
                    return strnatcasecmp(
                        isset($left['target_ref']) ? (string) $left['target_ref'] : '',
                        isset($right['target_ref']) ? (string) $right['target_ref'] : ''
                    );
                }

                return $left_confidence < $right_confidence ? 1 : -1;
            }
        );

        return $matches;
    }

    /**
     * @param string $domain
     * @param string $journey_id
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $recommendations_artifact
     * @return array<string, mixed>|WP_Error
     */
    public function update_pattern_memory($domain, $journey_id, array $classification_artifact, array $recommendations_artifact)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $existing = $this->get_pattern_memory($context['domain']);
        if (is_wp_error($existing)) {
            return $existing;
        }

        $next = $this->merge_pattern_payload($existing, $journey_id, $classification_artifact, $recommendations_artifact);
        if (! DBVC_CC_Artifact_Manager::write_json_file($context['pattern_memory_file'], $next)) {
            return new WP_Error(
                'dbvc_cc_v2_pattern_memory_write_failed',
                __('Could not write the V2 domain pattern memory artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        return $next;
    }

    /**
     * @param string $domain
     * @return array<string, mixed>
     */
    private function build_default_payload($domain)
    {
        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'domain-pattern-memory.v1',
            'domain' => sanitize_text_field((string) $domain),
            'generated_at' => current_time('c'),
            'source_journey_ids' => [],
            'pattern_groups' => [],
            'stats' => [
                'pattern_group_count' => 0,
                'pattern_target_ref_count' => 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param string $journey_id
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $recommendations_artifact
     * @return array<string, mixed>
     */
    private function merge_pattern_payload(array $existing, $journey_id, array $classification_artifact, array $recommendations_artifact)
    {
        $payload = $existing;
        $payload['generated_at'] = current_time('c');
        $payload['source_journey_ids'] = isset($payload['source_journey_ids']) && is_array($payload['source_journey_ids'])
            ? array_values(array_unique(array_merge($payload['source_journey_ids'], [$journey_id])))
            : [$journey_id];

        $groups = [];
        $existing_groups = isset($payload['pattern_groups']) && is_array($payload['pattern_groups']) ? $payload['pattern_groups'] : [];
        foreach ($existing_groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $group_key = isset($group['pattern_key']) ? sanitize_key((string) $group['pattern_key']) : '';
            $object_key = isset($group['target_object_key']) ? sanitize_key((string) $group['target_object_key']) : '';
            if ($group_key === '' || $object_key === '') {
                continue;
            }

            $groups[$object_key . '|' . $group_key] = $group;
        }

        foreach ($this->extract_learned_patterns($classification_artifact, $recommendations_artifact) as $learned_pattern) {
            if (! is_array($learned_pattern)) {
                continue;
            }

            $object_key = isset($learned_pattern['target_object_key']) ? sanitize_key((string) $learned_pattern['target_object_key']) : '';
            $pattern_key = isset($learned_pattern['pattern_key']) ? sanitize_key((string) $learned_pattern['pattern_key']) : '';
            if ($object_key === '' || $pattern_key === '') {
                continue;
            }

            $group_index = $object_key . '|' . $pattern_key;
            $existing_group = isset($groups[$group_index]) && is_array($groups[$group_index]) ? $groups[$group_index] : [];
            $target_refs = isset($existing_group['target_refs']) && is_array($existing_group['target_refs']) ? $existing_group['target_refs'] : [];
            $target_refs = array_values(array_unique(array_merge($target_refs, isset($learned_pattern['target_refs']) && is_array($learned_pattern['target_refs']) ? $learned_pattern['target_refs'] : [])));

            $occurrence_count = isset($existing_group['occurrence_count']) ? (int) $existing_group['occurrence_count'] : 0;
            $total_confidence = isset($existing_group['average_confidence'], $existing_group['occurrence_count'])
                ? ((float) $existing_group['average_confidence']) * max(1, (int) $existing_group['occurrence_count'])
                : 0.0;
            $occurrence_count++;
            $total_confidence += isset($learned_pattern['confidence']) ? (float) $learned_pattern['confidence'] : 0.0;

            $groups[$group_index] = [
                'pattern_key' => $pattern_key,
                'target_object_key' => $object_key,
                'candidate_group' => isset($learned_pattern['candidate_group']) ? sanitize_key((string) $learned_pattern['candidate_group']) : '',
                'context_tag' => isset($learned_pattern['context_tag']) ? sanitize_key((string) $learned_pattern['context_tag']) : '',
                'target_refs' => $target_refs,
                'average_confidence' => round($total_confidence / max(1, $occurrence_count), 2),
                'occurrence_count' => $occurrence_count,
                'source_refs' => isset($learned_pattern['source_refs']) && is_array($learned_pattern['source_refs'])
                    ? array_values($learned_pattern['source_refs'])
                    : [],
                'updated_at' => current_time('c'),
            ];
        }

        uasort(
            $groups,
            static function ($left, $right) {
                return strnatcasecmp(
                    isset($left['pattern_key']) ? (string) $left['pattern_key'] : '',
                    isset($right['pattern_key']) ? (string) $right['pattern_key'] : ''
                );
            }
        );

        $payload['pattern_groups'] = array_values($groups);
        $payload['stats'] = [
            'pattern_group_count' => count($payload['pattern_groups']),
            'pattern_target_ref_count' => array_sum(
                array_map(
                    static function ($group) {
                        return is_array($group) && isset($group['target_refs']) && is_array($group['target_refs'])
                            ? count($group['target_refs'])
                            : 0;
                    },
                    $payload['pattern_groups']
                )
            ),
        ];

        return $payload;
    }

    /**
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $recommendations_artifact
     * @return array<int, array<string, mixed>>
     */
    private function extract_learned_patterns(array $classification_artifact, array $recommendations_artifact)
    {
        $automation = DBVC_CC_V2_Contracts::get_automation_settings();
        $minimum_confidence = isset($automation['patternReuseMinConfidence']) ? (float) $automation['patternReuseMinConfidence'] : 0.9;
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];
        $object_key = isset($primary['object_key']) ? sanitize_key((string) $primary['object_key']) : '';
        if ($object_key === '') {
            return [];
        }

        $patterns = [];
        $recommendations = isset($recommendations_artifact['recommendations']) && is_array($recommendations_artifact['recommendations'])
            ? $recommendations_artifact['recommendations']
            : [];
        foreach ($recommendations as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }

            $pattern_key = isset($recommendation['pattern_key']) ? sanitize_key((string) $recommendation['pattern_key']) : '';
            $target_ref = isset($recommendation['target_ref']) ? sanitize_text_field((string) $recommendation['target_ref']) : '';
            $confidence = isset($recommendation['confidence']) ? (float) $recommendation['confidence'] : 0.0;
            if ($pattern_key === '' || $target_ref === '' || $confidence < $minimum_confidence) {
                continue;
            }

            $patterns[] = [
                'pattern_key' => $pattern_key,
                'target_object_key' => $object_key,
                'candidate_group' => isset($recommendation['candidate_group']) ? sanitize_key((string) $recommendation['candidate_group']) : '',
                'context_tag' => isset($recommendation['context_tag']) ? sanitize_key((string) $recommendation['context_tag']) : '',
                'target_refs' => [$target_ref],
                'confidence' => $confidence,
                'source_refs' => isset($recommendation['source_refs']) && is_array($recommendation['source_refs'])
                    ? array_values($recommendation['source_refs'])
                    : [],
            ];
        }

        return $patterns;
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
