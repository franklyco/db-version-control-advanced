<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Target_Slot_Graph_Service
{
    /**
     * @var DBVC_CC_V2_Target_Slot_Graph_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Target_Slot_Graph_Service
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
     * @param bool   $force_rebuild
     * @return array<string, mixed>|WP_Error
     */
    public function build_graph($domain, $force_rebuild = false)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $catalog_bundle = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->get_catalog($context['domain'], true);
        if (is_wp_error($catalog_bundle)) {
            return $catalog_bundle;
        }

        $catalog = isset($catalog_bundle['catalog']) && is_array($catalog_bundle['catalog']) ? $catalog_bundle['catalog'] : [];
        $payload = $this->build_payload($context, $catalog_bundle, $catalog);

        $existing = $this->read_json_file($context['target_slot_graph_file']);
        if (
            ! $force_rebuild
            && is_array($existing)
            && isset($existing['slot_graph_fingerprint'])
            && (string) $existing['slot_graph_fingerprint'] === $payload['slot_graph_fingerprint']
        ) {
            return [
                'status' => 'reused',
                'domain' => $context['domain'],
                'generated_at' => isset($existing['generated_at']) ? (string) $existing['generated_at'] : '',
                'catalog_fingerprint' => isset($existing['catalog_fingerprint']) ? (string) $existing['catalog_fingerprint'] : '',
                'slot_graph_fingerprint' => isset($existing['slot_graph_fingerprint']) ? (string) $existing['slot_graph_fingerprint'] : '',
                'slot_graph_file' => $context['target_slot_graph_file'],
                'artifact_relative_path' => $this->get_domain_relative_path($context['target_slot_graph_file'], $context['domain_dir']),
                'slot_graph' => $existing,
            ];
        }

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['target_slot_graph_file'], $payload)) {
            return new WP_Error(
                'dbvc_cc_v2_slot_graph_write_failed',
                __('Could not write the V2 target slot graph artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        return [
            'status' => 'built',
            'domain' => $context['domain'],
            'generated_at' => $payload['generated_at'],
            'catalog_fingerprint' => isset($payload['catalog_fingerprint']) ? (string) $payload['catalog_fingerprint'] : '',
            'slot_graph_fingerprint' => isset($payload['slot_graph_fingerprint']) ? (string) $payload['slot_graph_fingerprint'] : '',
            'slot_graph_file' => $context['target_slot_graph_file'],
            'artifact_relative_path' => $this->get_domain_relative_path($context['target_slot_graph_file'], $context['domain_dir']),
            'slot_graph' => $payload,
        ];
    }

    /**
     * @param string $domain
     * @param bool   $build_if_missing
     * @return array<string, mixed>|WP_Error
     */
    public function get_graph($domain, $build_if_missing = true)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $existing = $this->read_json_file($context['target_slot_graph_file']);
        if (is_array($existing)) {
            return [
                'status' => 'loaded',
                'domain' => $context['domain'],
                'generated_at' => isset($existing['generated_at']) ? (string) $existing['generated_at'] : '',
                'catalog_fingerprint' => isset($existing['catalog_fingerprint']) ? (string) $existing['catalog_fingerprint'] : '',
                'slot_graph_fingerprint' => isset($existing['slot_graph_fingerprint']) ? (string) $existing['slot_graph_fingerprint'] : '',
                'slot_graph_file' => $context['target_slot_graph_file'],
                'artifact_relative_path' => $this->get_domain_relative_path($context['target_slot_graph_file'], $context['domain_dir']),
                'slot_graph' => $existing,
            ];
        }

        if (! $build_if_missing) {
            return new WP_Error(
                'dbvc_cc_v2_slot_graph_missing',
                __('The V2 target slot graph has not been built for this domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        return $this->build_graph($domain, false);
    }

    /**
     * @param array<string, string> $context
     * @param array<string, mixed>  $catalog_bundle
     * @param array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    private function build_payload(array $context, array $catalog_bundle, array $catalog)
    {
        $acf_catalog = isset($catalog['acf_catalog']) && is_array($catalog['acf_catalog']) ? $catalog['acf_catalog'] : [];
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];
        $provider_status = isset($catalog['field_context_provider']) && is_array($catalog['field_context_provider'])
            ? $catalog['field_context_provider']
            : [];

        $slots = [];
        $slots_by_target_ref = [];
        $slots_by_key_path = [];
        $slots_by_name_path = [];
        $slots_by_group_and_acf_key = [];
        $slots_by_object_type = [];
        $slots_by_section_family = [];
        $slots_by_slot_role = [];
        $slots_by_competition_group = [];

        foreach ($groups as $group_key => $group) {
            if (! is_array($group)) {
                continue;
            }

            $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
            foreach ($fields as $field_key => $field) {
                if (! is_array($field)) {
                    continue;
                }

                if ($this->is_container_only_field($field)) {
                    continue;
                }

                $slot = DBVC_CC_Field_Context_Chain_Builder::get_instance()->build_slot_projection($group, $field, $fields);
                if (empty($slot['target_ref'])) {
                    continue;
                }

                $target_ref = (string) $slot['target_ref'];
                $slots[$target_ref] = $slot;
                $slots_by_target_ref[$target_ref] = $target_ref;

                $key_path = isset($slot['key_path']) ? (string) $slot['key_path'] : '';
                if ($key_path !== '') {
                    $slots_by_key_path[$key_path] = $target_ref;
                }

                $name_path = isset($slot['name_path']) ? (string) $slot['name_path'] : '';
                if ($name_path !== '') {
                    $slots_by_name_path[$name_path] = $target_ref;
                }

                $normalized_group_key = sanitize_key((string) $group_key);
                $normalized_field_key = sanitize_key((string) $field_key);
                if ($normalized_group_key !== '' && $normalized_field_key !== '') {
                    if (! isset($slots_by_group_and_acf_key[$normalized_group_key])) {
                        $slots_by_group_and_acf_key[$normalized_group_key] = [];
                    }
                    $slots_by_group_and_acf_key[$normalized_group_key][$normalized_field_key] = $target_ref;
                }

                $object_context = isset($slot['object_context']) && is_array($slot['object_context']) ? $slot['object_context'] : [];
                $post_types = isset($object_context['post_types']) && is_array($object_context['post_types']) ? $object_context['post_types'] : [];
                foreach ($post_types as $post_type) {
                    $post_type = sanitize_key((string) $post_type);
                    if ($post_type === '') {
                        continue;
                    }
                    if (! isset($slots_by_object_type[$post_type])) {
                        $slots_by_object_type[$post_type] = [];
                    }
                    $slots_by_object_type[$post_type][] = $target_ref;
                }

                $section_family = isset($slot['section_family']) ? sanitize_key((string) $slot['section_family']) : '';
                if ($section_family !== '') {
                    if (! isset($slots_by_section_family[$section_family])) {
                        $slots_by_section_family[$section_family] = [];
                    }
                    $slots_by_section_family[$section_family][] = $target_ref;
                }

                $slot_role = isset($slot['slot_role']) ? sanitize_key((string) $slot['slot_role']) : '';
                if ($slot_role !== '') {
                    if (! isset($slots_by_slot_role[$slot_role])) {
                        $slots_by_slot_role[$slot_role] = [];
                    }
                    $slots_by_slot_role[$slot_role][] = $target_ref;
                }

                $competition_group = isset($slot['competition_group']) ? sanitize_text_field((string) $slot['competition_group']) : '';
                if ($competition_group !== '') {
                    if (! isset($slots_by_competition_group[$competition_group])) {
                        $slots_by_competition_group[$competition_group] = [];
                    }
                    $slots_by_competition_group[$competition_group][] = $target_ref;
                }
            }
        }

        ksort($slots);
        ksort($slots_by_target_ref);
        ksort($slots_by_key_path);
        ksort($slots_by_name_path);
        ksort($slots_by_group_and_acf_key);
        ksort($slots_by_object_type);
        ksort($slots_by_section_family);
        ksort($slots_by_slot_role);
        ksort($slots_by_competition_group);
        $slots_by_object_type = $this->sort_index_lists($slots_by_object_type);
        $slots_by_section_family = $this->sort_index_lists($slots_by_section_family);
        $slots_by_slot_role = $this->sort_index_lists($slots_by_slot_role);
        $slots_by_competition_group = $this->sort_index_lists($slots_by_competition_group);

        $slot_graph_fingerprint = hash(
            'sha256',
            (string) wp_json_encode(
                [
                    'catalog_fingerprint' => isset($catalog_bundle['catalog_fingerprint']) ? (string) $catalog_bundle['catalog_fingerprint'] : '',
                    'provider_status' => $provider_status,
                    'slots' => $slots,
                ],
                JSON_UNESCAPED_SLASHES
            )
        );

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-slot-graph.v1',
            'domain' => $context['domain'],
            'generated_at' => current_time('c'),
            'catalog_fingerprint' => isset($catalog_bundle['catalog_fingerprint']) ? (string) $catalog_bundle['catalog_fingerprint'] : '',
            'slot_graph_fingerprint' => $slot_graph_fingerprint,
            'field_context_provider' => $provider_status,
            'source_artifacts' => [
                'target_field_catalog_file' => $context['target_field_catalog_file'],
                'target_field_catalog_relative_path' => $this->get_domain_relative_path($context['target_field_catalog_file'], $context['domain_dir']),
                'target_field_catalog_fingerprint' => isset($catalog_bundle['catalog_fingerprint']) ? (string) $catalog_bundle['catalog_fingerprint'] : '',
            ],
            'slots' => $slots,
            'indexes' => [
                'slots_by_target_ref' => $slots_by_target_ref,
                'slots_by_key_path' => $slots_by_key_path,
                'slots_by_name_path' => $slots_by_name_path,
                'slots_by_group_and_acf_key' => $slots_by_group_and_acf_key,
                'slots_by_object_type' => $slots_by_object_type,
                'slots_by_section_family' => $slots_by_section_family,
                'slots_by_slot_role' => $slots_by_slot_role,
                'slots_by_competition_group' => $slots_by_competition_group,
            ],
            'stats' => [
                'slot_count' => count($slots),
                'group_count' => count($groups),
                'object_type_count' => count($slots_by_object_type),
                'section_family_count' => count($slots_by_section_family),
                'slot_role_count' => count($slots_by_slot_role),
                'competition_group_count' => count($slots_by_competition_group),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return bool
     */
    private function is_container_only_field(array $field)
    {
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $field_context = isset($field['field_context']) && is_array($field['field_context']) ? $field['field_context'] : [];
        $value_contract = isset($field_context['value_contract']) && is_array($field_context['value_contract']) ? $field_context['value_contract'] : [];
        if (array_key_exists('writable', $value_contract)) {
            return empty($value_contract['writable']);
        }

        return in_array($type, ['group', 'repeater', 'flexible_content', 'accordion', 'tab', 'message'], true);
    }

    /**
     * @param array<string, array<int, string>> $index
     * @return array<string, array<int, string>>
     */
    private function sort_index_lists(array $index)
    {
        foreach ($index as $key => $refs) {
            if (! is_array($refs)) {
                continue;
            }

            $refs = array_values(array_unique(array_map('strval', $refs)));
            sort($refs);
            $index[$key] = $refs;
        }

        return $index;
    }

    /**
     * @param string $path
     * @param string $domain_dir
     * @return string
     */
    private function get_domain_relative_path($path, $domain_dir)
    {
        return ltrim(str_replace(wp_normalize_path((string) $domain_dir), '', wp_normalize_path((string) $path)), '/');
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
