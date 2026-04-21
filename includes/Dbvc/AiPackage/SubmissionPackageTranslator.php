<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SubmissionPackageTranslator
{
    private const TRANSLATION_SCHEMA_VERSION = 1;

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function translate(array $args)
    {
        $workspace_path = isset($args['workspace_path']) ? wp_normalize_path((string) $args['workspace_path']) : '';
        $content_root = isset($args['content_root_path']) ? wp_normalize_path((string) $args['content_root_path']) : '';
        $translated_root = isset($args['translated_root']) ? wp_normalize_path((string) $args['translated_root']) : '';
        $manifest = isset($args['manifest']) && is_array($args['manifest']) ? $args['manifest'] : [];
        $schema_bundle = isset($args['schema_bundle']) && is_array($args['schema_bundle']) ? $args['schema_bundle'] : [];
        $validation_rules = isset($args['validation_rules']) && is_array($args['validation_rules']) ? $args['validation_rules'] : [];
        $entities = isset($args['entities']) && is_array($args['entities']) ? $args['entities'] : [];

        if ($workspace_path === '' || ! is_dir($workspace_path)) {
            return new \WP_Error('dbvc_ai_translate_workspace_missing', __('AI translation workspace is unavailable.', 'dbvc'));
        }

        if ($content_root === '' || ! is_dir($content_root)) {
            return new \WP_Error('dbvc_ai_translate_content_root_missing', __('AI translation content root is unavailable.', 'dbvc'));
        }

        if ($translated_root === '') {
            return new \WP_Error('dbvc_ai_translate_root_missing', __('AI translation root is unavailable.', 'dbvc'));
        }

        $translated_root_result = Storage::ensure_directory($translated_root);
        if (\is_wp_error($translated_root_result)) {
            return $translated_root_result;
        }

        $sync_root = wp_normalize_path(trailingslashit($translated_root) . 'sync-root');
        $sync_root_result = Storage::ensure_directory($sync_root);
        if (\is_wp_error($sync_root_result)) {
            return $sync_root_result;
        }

        $issues = [];
        $translated_entities = [];
        $counts = [
            'eligible_entities' => 0,
            'translated_entities' => 0,
            'translated_posts' => 0,
            'translated_terms' => 0,
            'create_entities' => 0,
            'update_entities' => 0,
            'skipped_entities' => 0,
        ];

        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $entity_status = isset($entity['status']) ? (string) $entity['status'] : '';
            $entity_path = isset($entity['path']) ? (string) $entity['path'] : '';
            if ($entity_status === 'invalid_json' || $entity_path === '') {
                $counts['skipped_entities']++;
                continue;
            }

            $counts['eligible_entities']++;
            $translation = self::translate_entity($entity, $content_root, $sync_root, $manifest, $schema_bundle, $validation_rules);
            if (\is_wp_error($translation)) {
                $issues[] = self::build_issue('error', 'translation_failed', $translation->get_error_message(), $entity_path);
                $counts['skipped_entities']++;
                continue;
            }

            foreach ($translation['issues'] as $issue) {
                $issues[] = $issue;
            }

            $translated_entities[] = $translation['entity'];

            if (($translation['entity']['status'] ?? '') === 'translated') {
                $counts['translated_entities']++;
                if (($translation['entity']['entity_kind'] ?? '') === 'post') {
                    $counts['translated_posts']++;
                } elseif (($translation['entity']['entity_kind'] ?? '') === 'term') {
                    $counts['translated_terms']++;
                }

                if (($translation['entity']['intent'] ?? '') === 'update') {
                    $counts['update_entities']++;
                } else {
                    $counts['create_entities']++;
                }
            } else {
                $counts['skipped_entities']++;
            }
        }

        $translation_manifest = [
            'translation_schema_version' => self::TRANSLATION_SCHEMA_VERSION,
            'generated_at' => current_time('c'),
            'package_type' => isset($manifest['package_type']) ? (string) $manifest['package_type'] : '',
            'package_schema_version' => isset($manifest['package_schema_version']) ? (int) $manifest['package_schema_version'] : 0,
            'intended_operation' => isset($manifest['intended_operation']) ? (string) $manifest['intended_operation'] : '',
            'counts' => $counts,
            'files' => array_values(array_map(static function (array $entity): array {
                return [
                    'source_path' => (string) ($entity['path'] ?? ''),
                    'translated_path' => (string) ($entity['translated_path'] ?? ''),
                    'entity_kind' => (string) ($entity['entity_kind'] ?? ''),
                    'object_key' => (string) ($entity['object_key'] ?? ''),
                    'intent' => (string) ($entity['intent'] ?? ''),
                    'status' => (string) ($entity['status'] ?? ''),
                    'match_source' => (string) ($entity['match_source'] ?? ''),
                    'resolved_local_id' => isset($entity['resolved_local_id']) ? (int) $entity['resolved_local_id'] : 0,
                    'deferred_relationships' => isset($entity['deferred_relationships']) && is_array($entity['deferred_relationships'])
                        ? $entity['deferred_relationships']
                        : [],
                ];
            }, $translated_entities)),
        ];

        $manifest_path = wp_normalize_path(trailingslashit($translated_root) . 'translation-manifest.json');
        $manifest_json = wp_json_encode($translation_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($manifest_json) || file_put_contents($manifest_path, $manifest_json . "\n") === false) {
            return new \WP_Error('dbvc_ai_translation_manifest_write_failed', __('Unable to write the AI translation manifest.', 'dbvc'));
        }

        return [
            'issues' => $issues,
            'entities' => $translated_entities,
            'artifacts' => [
                'translation_manifest' => 'translated/translation-manifest.json',
                'translated_sync_root' => 'translated/sync-root',
            ],
            'counts' => $counts,
        ];
    }

    /**
     * @param array<string,mixed> $entity
     * @param string              $content_root
     * @param string              $sync_root
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $validation_rules
     * @return array<string,mixed>|\WP_Error
     */
    private static function translate_entity(
        array $entity,
        string $content_root,
        string $sync_root,
        array $manifest,
        array $schema_bundle,
        array $validation_rules
    ) {
        $relative_path = isset($entity['path']) ? (string) $entity['path'] : '';
        $absolute_path = wp_normalize_path(trailingslashit($content_root) . ltrim($relative_path, '/'));
        if (! is_file($absolute_path) || ! is_readable($absolute_path)) {
            return new \WP_Error('dbvc_ai_translate_entity_missing', __('The extracted AI entity file could not be read during translation.', 'dbvc'));
        }

        $raw = file_get_contents($absolute_path);
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($payload)) {
            return new \WP_Error('dbvc_ai_translate_entity_invalid', __('The extracted AI entity file contains invalid JSON.', 'dbvc'));
        }

        $translation = (($entity['entity_kind'] ?? '') === 'term')
            ? self::translate_term_payload($entity, $payload, $manifest, $schema_bundle, $validation_rules)
            : self::translate_post_payload($entity, $payload, $manifest, $schema_bundle, $validation_rules);

        $translated_payload = isset($translation['payload']) && is_array($translation['payload']) ? $translation['payload'] : [];
        $translation_issues = isset($translation['issues']) && is_array($translation['issues']) ? $translation['issues'] : [];
        $status = isset($translation['status']) ? (string) $translation['status'] : 'translated';
        $translated_relative_path = '';

        if ($status === 'translated') {
            if (($entity['entity_kind'] ?? '') === 'term') {
                $taxonomy = isset($translated_payload['taxonomy']) ? sanitize_key((string) $translated_payload['taxonomy']) : '';
                $target_dir = wp_normalize_path(trailingslashit($sync_root) . 'taxonomy/' . $taxonomy);
                $ensure_result = Storage::ensure_directory($target_dir);
                if (\is_wp_error($ensure_result)) {
                    return $ensure_result;
                }

                $filename = class_exists('\DBVC_Import_Router')
                    ? \DBVC_Import_Router::determine_term_filename($translated_payload, $taxonomy)
                    : sanitize_file_name($taxonomy . '-' . ($translated_payload['slug'] ?? 'term') . '.json');
                $target_path = wp_normalize_path(trailingslashit($target_dir) . $filename);
                $translated_relative_path = ltrim(str_replace(trailingslashit($sync_root), '', $target_path), '/');
            } else {
                $post_type = isset($translated_payload['post_type']) ? sanitize_key((string) $translated_payload['post_type']) : '';
                $target_dir = wp_normalize_path(trailingslashit($sync_root) . $post_type);
                $ensure_result = Storage::ensure_directory($target_dir);
                if (\is_wp_error($ensure_result)) {
                    return $ensure_result;
                }

                $filename = class_exists('\DBVC_Import_Router')
                    ? \DBVC_Import_Router::determine_post_filename($translated_payload)
                    : sanitize_file_name($post_type . '-' . ($translated_payload['post_name'] ?? 'post') . '.json');
                $target_path = wp_normalize_path(trailingslashit($target_dir) . $filename);
                $translated_relative_path = ltrim(str_replace(trailingslashit($sync_root), '', $target_path), '/');
            }

            $json = wp_json_encode($translated_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (! is_string($json) || file_put_contents($target_path, $json . "\n") === false) {
                return new \WP_Error('dbvc_ai_translate_write_failed', __('The translated DBVC JSON file could not be written.', 'dbvc'));
            }
        }

        $entity['status'] = $status;
        $entity['translated_path'] = $translated_relative_path === '' ? '' : 'translated/sync-root/' . $translated_relative_path;
        $entity['match_source'] = isset($translation['match_source']) ? (string) $translation['match_source'] : '';
        $entity['resolved_local_id'] = isset($translation['resolved_local_id']) ? (int) $translation['resolved_local_id'] : 0;
        $entity['resolved_uid'] = isset($translation['resolved_uid']) ? (string) $translation['resolved_uid'] : '';
        $entity['deferred_relationships'] = isset($translation['deferred_relationships']) && is_array($translation['deferred_relationships'])
            ? $translation['deferred_relationships']
            : [];

        return [
            'issues' => $translation_issues,
            'entity' => $entity,
        ];
    }

    /**
     * @param array<string,mixed> $entity
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $validation_rules
     * @return array<string,mixed>
     */
    private static function translate_post_payload(
        array $entity,
        array $payload,
        array $manifest,
        array $schema_bundle,
        array $validation_rules
    ): array {
        $issues = [];
        $post_type = isset($payload['post_type']) ? sanitize_key((string) $payload['post_type']) : '';
        $slug = isset($payload['post_name']) ? sanitize_title((string) $payload['post_name']) : '';
        $intent = isset($entity['intent']) ? (string) $entity['intent'] : 'create';
        $catalog_entry = isset($schema_bundle['field_catalog']['post_types'][$post_type]) && is_array($schema_bundle['field_catalog']['post_types'][$post_type])
            ? $schema_bundle['field_catalog']['post_types'][$post_type]
            : [];
        $acf_resolution = self::build_acf_resolution_context(
            isset($schema_bundle['field_catalog']) && is_array($schema_bundle['field_catalog'])
                ? $schema_bundle['field_catalog']
                : []
        );
        $resolved = self::resolve_post_target($payload);
        $resolved_local_id = isset($resolved['local_id']) ? (int) $resolved['local_id'] : 0;
        $resolved_uid = isset($resolved['resolved_uid']) ? (string) $resolved['resolved_uid'] : '';
        $match_source = isset($resolved['match_source']) ? (string) $resolved['match_source'] : '';

        foreach (self::validate_operation_intent($manifest, $intent, (string) ($entity['path'] ?? '')) as $issue) {
            $issues[] = $issue;
        }

        if ($intent === 'update' && $resolved_local_id <= 0) {
            $issues[] = self::build_issue('error', 'update_target_missing', __('A post/CPT update entity could not be matched to a local post by UID, slug, or ID.', 'dbvc'), (string) ($entity['path'] ?? ''));
        }

        $allowed_fields = self::build_allowed_fields($validation_rules, 'post_contract');
        $translated = self::filter_payload_fields($payload, $allowed_fields);
        $translated['post_type'] = $post_type;
        $translated['post_name'] = $slug !== '' ? $slug : sanitize_title((string) ($translated['post_title'] ?? ''));

        if ($intent === 'update' && $resolved_local_id > 0) {
            $translated['ID'] = $resolved_local_id;
            if ($resolved_uid !== '') {
                $translated['vf_object_uid'] = $resolved_uid;
            }
        } else {
            $translated['ID'] = 0;
            unset($translated['vf_object_uid']);
        }

        $reference_result = self::normalize_post_references(
            $translated,
            $post_type,
            (string) ($entity['path'] ?? ''),
            $resolved_local_id
        );
        $translated = $reference_result['payload'];
        foreach ($reference_result['issues'] as $issue) {
            $issues[] = $issue;
        }

        $meta_result = self::translate_acf_meta(
            $translated,
            $catalog_entry,
            $acf_resolution,
            (string) ($entity['path'] ?? '')
        );
        $translated = $meta_result['payload'];
        foreach ($meta_result['issues'] as $issue) {
            $issues[] = $issue;
        }
        $deferred_relationships = self::merge_deferred_relationships(
            isset($reference_result['deferred_relationships']) && is_array($reference_result['deferred_relationships'])
                ? $reference_result['deferred_relationships']
                : [],
            isset($meta_result['deferred_relationships']) && is_array($meta_result['deferred_relationships'])
                ? $meta_result['deferred_relationships']
                : []
        );

        $translated = self::sort_post_payload($translated);

        return [
            'payload' => $translated,
            'issues' => $issues,
            'status' => self::has_error_issue($issues) ? 'blocked' : 'translated',
            'match_source' => $match_source,
            'resolved_local_id' => $resolved_local_id,
            'resolved_uid' => $resolved_uid,
            'deferred_relationships' => $deferred_relationships,
        ];
    }

    /**
     * @param array<string,mixed> $entity
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $validation_rules
     * @return array<string,mixed>
     */
    private static function translate_term_payload(
        array $entity,
        array $payload,
        array $manifest,
        array $schema_bundle,
        array $validation_rules
    ): array {
        unset($schema_bundle);

        $issues = [];
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : '';
        $slug = isset($payload['slug']) ? sanitize_title((string) $payload['slug']) : '';
        $intent = isset($entity['intent']) ? (string) $entity['intent'] : 'create';
        $catalog_entry = isset($schema_bundle['field_catalog']['taxonomies'][$taxonomy]) && is_array($schema_bundle['field_catalog']['taxonomies'][$taxonomy])
            ? $schema_bundle['field_catalog']['taxonomies'][$taxonomy]
            : [];
        $acf_resolution = self::build_acf_resolution_context(
            isset($schema_bundle['field_catalog']) && is_array($schema_bundle['field_catalog'])
                ? $schema_bundle['field_catalog']
                : []
        );
        $resolved = self::resolve_term_target($payload);
        $resolved_local_id = isset($resolved['local_id']) ? (int) $resolved['local_id'] : 0;
        $resolved_uid = isset($resolved['resolved_uid']) ? (string) $resolved['resolved_uid'] : '';
        $match_source = isset($resolved['match_source']) ? (string) $resolved['match_source'] : '';

        foreach (self::validate_operation_intent($manifest, $intent, (string) ($entity['path'] ?? '')) as $issue) {
            $issues[] = $issue;
        }

        if ($intent === 'update' && $resolved_local_id <= 0) {
            $issues[] = self::build_issue('error', 'update_target_missing', __('A taxonomy term update entity could not be matched to a local term by UID, slug, or term ID.', 'dbvc'), (string) ($entity['path'] ?? ''));
        }

        $allowed_fields = self::build_allowed_fields($validation_rules, 'term_contract');
        $translated = self::filter_payload_fields($payload, $allowed_fields);
        $translated['taxonomy'] = $taxonomy;
        $translated['slug'] = $slug;

        if ($intent === 'update' && $resolved_local_id > 0) {
            $translated['term_id'] = $resolved_local_id;
            if ($resolved_uid !== '') {
                $translated['vf_object_uid'] = $resolved_uid;
            }
        } else {
            $translated['term_id'] = 0;
            unset($translated['vf_object_uid']);
        }

        $reference_result = self::normalize_term_references(
            $translated,
            $taxonomy,
            (string) ($entity['path'] ?? '')
        );
        $translated = $reference_result['payload'];
        foreach ($reference_result['issues'] as $issue) {
            $issues[] = $issue;
        }

        $meta_result = self::translate_acf_meta(
            $translated,
            $catalog_entry,
            $acf_resolution,
            (string) ($entity['path'] ?? '')
        );
        $translated = $meta_result['payload'];
        foreach ($meta_result['issues'] as $issue) {
            $issues[] = $issue;
        }
        $deferred_relationships = isset($meta_result['deferred_relationships']) && is_array($meta_result['deferred_relationships'])
            ? $meta_result['deferred_relationships']
            : [];

        $translated = self::sort_term_payload($translated);

        return [
            'payload' => $translated,
            'issues' => $issues,
            'status' => self::has_error_issue($issues) ? 'blocked' : 'translated',
            'match_source' => $match_source,
            'resolved_local_id' => $resolved_local_id,
            'resolved_uid' => $resolved_uid,
            'deferred_relationships' => $deferred_relationships,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function resolve_post_target(array $payload): array
    {
        $post_type = isset($payload['post_type']) ? sanitize_key((string) $payload['post_type']) : '';
        $vf_object_uid = isset($payload['vf_object_uid']) ? trim((string) $payload['vf_object_uid']) : '';
        $slug = isset($payload['post_name']) ? sanitize_title((string) $payload['post_name']) : '';
        $post_id = isset($payload['ID']) ? absint($payload['ID']) : 0;
        $local_id = 0;
        $match_source = '';

        if ($vf_object_uid !== '' && class_exists('\DBVC_Database')) {
            $record = \DBVC_Database::get_entity_by_uid($vf_object_uid);
            if ($record && ! empty($record->object_id)) {
                $candidate_post = get_post((int) $record->object_id);
                if ($candidate_post && (! $post_type || $candidate_post->post_type === $post_type)) {
                    $local_id = (int) $candidate_post->ID;
                    $match_source = 'vf_object_uid';
                }
            }
        }

        if (! $local_id && $slug !== '' && $post_type !== '') {
            $candidate_post = PostLookupService::find_post_by_slug($slug, $post_type);
            if ($candidate_post instanceof \WP_Post) {
                $local_id = (int) $candidate_post->ID;
                $match_source = 'slug';
            }
        }

        if (! $local_id && $post_id > 0) {
            $candidate_post = get_post($post_id);
            if ($candidate_post instanceof \WP_Post && (! $post_type || $candidate_post->post_type === $post_type)) {
                $local_id = (int) $candidate_post->ID;
                $match_source = 'ID';
            }
        }

        $resolved_uid = $vf_object_uid;
        if ($resolved_uid === '' && $local_id > 0) {
            $resolved_uid = (string) get_post_meta($local_id, 'vf_object_uid', true);
        }

        return [
            'local_id' => $local_id,
            'match_source' => $match_source,
            'resolved_uid' => $resolved_uid,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function resolve_term_target(array $payload): array
    {
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : '';
        $vf_object_uid = isset($payload['vf_object_uid']) ? trim((string) $payload['vf_object_uid']) : '';
        $slug = isset($payload['slug']) ? sanitize_title((string) $payload['slug']) : '';
        $term_id = isset($payload['term_id']) ? absint($payload['term_id']) : 0;
        $local_id = 0;
        $match_source = '';

        if ($vf_object_uid !== '' && class_exists('\DBVC_Database')) {
            $record = \DBVC_Database::get_entity_by_uid($vf_object_uid);
            if ($record && ! empty($record->object_id)) {
                $candidate_term = get_term((int) $record->object_id, $taxonomy);
                if ($candidate_term && ! is_wp_error($candidate_term)) {
                    $local_id = (int) $candidate_term->term_id;
                    $match_source = 'vf_object_uid';
                }
            }
        }

        if (! $local_id && $slug !== '' && $taxonomy !== '' && taxonomy_exists($taxonomy)) {
            $candidate_term = get_term_by('slug', $slug, $taxonomy);
            if ($candidate_term && ! is_wp_error($candidate_term)) {
                $local_id = (int) $candidate_term->term_id;
                $match_source = 'slug';
            }
        }

        if (! $local_id && $term_id > 0) {
            $candidate_term = get_term($term_id);
            if ($candidate_term && ! is_wp_error($candidate_term) && (! $taxonomy || $candidate_term->taxonomy === $taxonomy)) {
                $local_id = (int) $candidate_term->term_id;
                $match_source = 'term_id';
            }
        }

        $resolved_uid = $vf_object_uid;
        if ($resolved_uid === '' && $local_id > 0) {
            $resolved_uid = (string) get_term_meta($local_id, 'vf_object_uid', true);
        }

        return [
            'local_id' => $local_id,
            'match_source' => $match_source,
            'resolved_uid' => $resolved_uid,
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param string              $intent
     * @param string              $path
     * @return array<int,array<string,mixed>>
     */
    private static function validate_operation_intent(array $manifest, string $intent, string $path): array
    {
        $issues = [];
        $operation = isset($manifest['intended_operation']) ? (string) $manifest['intended_operation'] : '';

        if ($operation === 'create_only' && $intent === 'update') {
            $issues[] = self::build_issue('error', 'operation_intent_mismatch', __('The AI package is marked create-only but contains an update-intent entity.', 'dbvc'), $path);
        } elseif ($operation === 'update_only' && $intent !== 'update') {
            $issues[] = self::build_issue('error', 'operation_intent_mismatch', __('The AI package is marked update-only but contains a create-intent entity.', 'dbvc'), $path);
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $validation_rules
     * @param string              $contract_key
     * @return array<int,string>
     */
    private static function build_allowed_fields(array $validation_rules, string $contract_key): array
    {
        $contract = isset($validation_rules[$contract_key]) && is_array($validation_rules[$contract_key])
            ? $validation_rules[$contract_key]
            : [];

        $allowed = [];
        foreach (['required_fields', 'optional_fields', 'conditional_identity_fields'] as $list_key) {
            if (! isset($contract[$list_key]) || ! is_array($contract[$list_key])) {
                continue;
            }
            foreach ($contract[$list_key] as $field_name) {
                if (! is_scalar($field_name)) {
                    continue;
                }
                $allowed[(string) $field_name] = (string) $field_name;
            }
        }

        return array_values($allowed);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string>   $allowed_fields
     * @return array<string,mixed>
     */
    private static function filter_payload_fields(array $payload, array $allowed_fields): array
    {
        $filtered = [];
        foreach ($allowed_fields as $field_name) {
            if (array_key_exists($field_name, $payload)) {
                $filtered[$field_name] = $payload[$field_name];
            }
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $post_type
     * @param string              $path
     * @param int                 $resolved_local_id
     * @return array<string,mixed>
     */
    private static function normalize_post_references(array $payload, string $post_type, string $path, int $resolved_local_id): array
    {
        $issues = [];
        $deferred_relationships = [];

        if (isset($payload['tax_input'])) {
            if (is_array($payload['tax_input'])) {
                $tax_input_result = self::normalize_tax_input((array) $payload['tax_input'], $post_type, $path);
                $payload['tax_input'] = $tax_input_result['tax_input'];
                foreach ($tax_input_result['issues'] as $issue) {
                    $issues[] = $issue;
                }
                if (empty($payload['tax_input'])) {
                    unset($payload['tax_input']);
                }
            } else {
                unset($payload['tax_input']);
                $issues[] = self::build_issue(
                    'warning',
                    'tax_input_removed',
                    __('`tax_input` could not be normalized and was removed from the translated payload.', 'dbvc'),
                    $path . '#tax_input'
                );
            }
        }

        if (array_key_exists('post_parent', $payload)) {
            $parent_result = self::normalize_post_parent_reference(
                $payload['post_parent'],
                $post_type,
                $path,
                $resolved_local_id
            );
            foreach ($parent_result['issues'] as $issue) {
                $issues[] = $issue;
            }

            if (! empty($parent_result['preserve_zero'])) {
                $payload['post_parent'] = 0;
            } elseif (($parent_result['local_id'] ?? 0) > 0) {
                $payload['post_parent'] = (int) $parent_result['local_id'];
            } elseif (! empty($parent_result['deferred_reference']) && is_array($parent_result['deferred_reference'])) {
                $deferred_relationships['post_parent'] = $parent_result['deferred_reference'];
                unset($payload['post_parent']);
            } else {
                unset($payload['post_parent']);
            }
        }

        return [
            'payload' => $payload,
            'issues' => $issues,
            'deferred_relationships' => $deferred_relationships,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $catalog_entry
     * @param string              $path
     * @return array<string,mixed>
     */
    private static function translate_acf_meta(array $payload, array $catalog_entry, array $acf_resolution, string $path): array
    {
        $issues = [];
        $deferred_relationships = [];
        if (! isset($payload['meta']) || ! is_array($payload['meta'])) {
            return [
                'payload' => $payload,
                'issues' => $issues,
                'deferred_relationships' => $deferred_relationships,
            ];
        }

        $meta = $payload['meta'];
        $root_field_map = self::build_root_acf_field_map($catalog_entry, $acf_resolution);
        $flat_leaf_alias_map = self::build_flat_acf_leaf_alias_map($catalog_entry, $acf_resolution);
        if (empty($root_field_map) && empty($flat_leaf_alias_map)) {
            return [
                'payload' => $payload,
                'issues' => $issues,
                'deferred_relationships' => $deferred_relationships,
            ];
        }

        $meta_updates = [];
        $processed_roots = [];

        foreach ($root_field_map as $field_name => $field) {
            if (! array_key_exists($field_name, $meta)) {
                continue;
            }

            $field_result = self::translate_acf_field_to_storage(
                $field,
                $meta[$field_name],
                $path . '#meta.' . $field_name,
                $field_name,
                $acf_resolution
            );
            self::merge_acf_translation_result($meta_updates, $issues, $deferred_relationships, $field_result);
            $processed_roots[$field_name] = true;
            unset($meta[$field_name], $meta['_' . $field_name]);
        }

        foreach (array_keys($meta) as $meta_key) {
            if (! is_string($meta_key) || $meta_key === '' || $meta_key[0] === '_') {
                continue;
            }

            if (! isset($flat_leaf_alias_map[$meta_key]) || ! is_array($flat_leaf_alias_map[$meta_key])) {
                continue;
            }

            $alias = $flat_leaf_alias_map[$meta_key];
            $root_name = isset($alias['root_name']) ? (string) $alias['root_name'] : '';
            if ($root_name !== '' && ! empty($processed_roots[$root_name])) {
                unset($meta[$meta_key], $meta['_' . $meta_key]);
                continue;
            }

            $field = isset($alias['field']) && is_array($alias['field']) ? $alias['field'] : [];
            $storage_key = isset($alias['storage_key']) ? (string) $alias['storage_key'] : '';
            if (empty($field) || $storage_key === '') {
                continue;
            }

            $field_result = self::translate_acf_field_to_storage(
                $field,
                $meta[$meta_key],
                $path . '#meta.' . $meta_key,
                $storage_key,
                $acf_resolution
            );
            self::merge_acf_translation_result($meta_updates, $issues, $deferred_relationships, $field_result);
            unset($meta[$meta_key], $meta['_' . $meta_key]);
        }

        foreach ($meta_updates as $meta_key => $meta_value) {
            $meta[$meta_key] = $meta_value;
        }

        $payload['meta'] = $meta;

        return [
            'payload' => $payload,
            'issues' => $issues,
            'deferred_relationships' => $deferred_relationships,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $taxonomy
     * @param string              $path
     * @return array<string,mixed>
     */
    private static function normalize_term_references(array $payload, string $taxonomy, string $path): array
    {
        $issues = [];

        if (isset($payload['parent_slug'])) {
            $parent_slug_result = self::extract_term_reference_slug($payload['parent_slug'], $taxonomy, $path . '#parent_slug');
            foreach ($parent_slug_result['issues'] as $issue) {
                $issues[] = $issue;
            }
            if (($parent_slug_result['slug'] ?? '') !== '') {
                $payload['parent_slug'] = (string) $parent_slug_result['slug'];
            } else {
                unset($payload['parent_slug']);
            }
        }

        if (isset($payload['parent'])) {
            if (is_array($payload['parent']) || is_string($payload['parent'])) {
                $parent_result = self::extract_term_reference_slug($payload['parent'], $taxonomy, $path . '#parent');
                foreach ($parent_result['issues'] as $issue) {
                    $issues[] = $issue;
                }
                if (($parent_result['slug'] ?? '') !== '') {
                    $payload['parent_slug'] = (string) $parent_result['slug'];
                }
                unset($payload['parent']);
            } elseif (is_numeric($payload['parent'])) {
                $parent_id = absint($payload['parent']);
                if ($parent_id > 0 && taxonomy_exists($taxonomy)) {
                    $parent_term = get_term($parent_id, $taxonomy);
                    if ($parent_term && ! is_wp_error($parent_term)) {
                        $payload['parent_slug'] = sanitize_title((string) $parent_term->slug);
                        unset($payload['parent']);
                    } else {
                        $issues[] = self::build_issue(
                            'warning',
                            'term_parent_unresolved',
                            __('A numeric term parent reference could not be matched locally and was removed.', 'dbvc'),
                            $path . '#parent'
                        );
                        unset($payload['parent']);
                    }
                } else {
                    unset($payload['parent']);
                }
            } else {
                $issues[] = self::build_issue(
                    'warning',
                    'term_parent_removed',
                    __('An unsupported term parent reference was removed from the translated payload.', 'dbvc'),
                    $path . '#parent'
                );
                unset($payload['parent']);
            }
        }

        return [
            'payload' => $payload,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string,mixed> $catalog_entry
     * @return array<string,array<string,mixed>>
     */
    private static function build_root_acf_field_map(array $catalog_entry, array $acf_resolution): array
    {
        $map = [];
        $acf_catalog = isset($catalog_entry['acf']) && is_array($catalog_entry['acf']) ? $catalog_entry['acf'] : [];
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];

        foreach ($groups as $group) {
            if (! is_array($group) || empty($group['fields']) || ! is_array($group['fields'])) {
                continue;
            }

            self::collect_root_acf_fields($group['fields'], $map, $acf_resolution);
        }

        ksort($map);

        return $map;
    }

    /**
     * @param array<int,mixed>                 $fields
     * @param array<string,array<string,mixed>> $map
     * @return void
     */
    private static function collect_root_acf_fields(array $fields, array &$map, array $acf_resolution, array $clone_stack = []): void
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = isset($field['type']) ? sanitize_key((string) ($field['type'] ?? '')) : '';
            $name = isset($field['name']) ? (string) ($field['name'] ?? '') : '';
            $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
            $updated_clone_stack = $clone_stack;
            if ($field_key !== '') {
                $updated_clone_stack[] = $field_key;
            }
            if ($type === 'clone') {
                $resolved_fields = self::resolve_clone_targets($field, $acf_resolution, $updated_clone_stack);
                if ($name === '') {
                    self::collect_root_acf_fields($resolved_fields, $map, $acf_resolution, $updated_clone_stack);
                    continue;
                }
            }

            if ($name !== '' && ! isset($map[$name])) {
                $map[$name] = $field;
            }
        }
    }

    /**
     * @param array<string,mixed> $catalog_entry
     * @param array<string,mixed> $acf_resolution
     * @return array<string,array<string,mixed>>
     */
    private static function build_flat_acf_leaf_alias_map(array $catalog_entry, array $acf_resolution): array
    {
        $map = [];
        $acf_catalog = isset($catalog_entry['acf']) && is_array($catalog_entry['acf']) ? $catalog_entry['acf'] : [];
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];

        foreach ($groups as $group) {
            if (! is_array($group) || empty($group['fields']) || ! is_array($group['fields'])) {
                continue;
            }

            self::collect_flat_acf_leaf_aliases($group['fields'], $map, $acf_resolution);
        }

        ksort($map);

        return $map;
    }

    /**
     * @param array<int,mixed>                  $fields
     * @param array<string,array<string,mixed>> $map
     * @param array<string,mixed>               $acf_resolution
     * @param string                            $root_name
     * @param string                            $storage_prefix
     * @return void
     */
    private static function collect_flat_acf_leaf_aliases(
        array $fields,
        array &$map,
        array $acf_resolution,
        string $root_name = '',
        string $storage_prefix = '',
        array $clone_stack = []
    ): void {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = isset($field['type']) ? sanitize_key((string) ($field['type'] ?? '')) : '';
            if (in_array($type, ['tab', 'accordion', 'message'], true)) {
                continue;
            }

            $name = isset($field['name']) ? (string) ($field['name'] ?? '') : '';
            $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
            $updated_clone_stack = $clone_stack;
            if ($field_key !== '') {
                $updated_clone_stack[] = $field_key;
            }

            if ($type === 'clone') {
                $resolved_fields = self::resolve_clone_targets($field, $acf_resolution, $updated_clone_stack);
                $clone_root = $root_name;
                $clone_prefix = $storage_prefix;

                if ($name !== '') {
                    $clone_root = $clone_root === '' ? $name : $clone_root;
                    if (! empty($field['clone']['prefix_name'])) {
                        $clone_prefix = self::append_acf_storage_key($storage_prefix, $name);
                    }
                }

                self::collect_flat_acf_leaf_aliases($resolved_fields, $map, $acf_resolution, $clone_root, $clone_prefix, $updated_clone_stack);
                continue;
            }

            if ($name === '') {
                continue;
            }

            $current_root = $root_name === '' ? $name : $root_name;
            $storage_key = self::append_acf_storage_key($storage_prefix, $name);

            if ($type === 'group') {
                $sub_fields = isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [];
                self::collect_flat_acf_leaf_aliases($sub_fields, $map, $acf_resolution, $current_root, $storage_key, $clone_stack);
                continue;
            }

            if ((self::is_supported_simple_acf_field($field) || self::is_supported_deferred_acf_field($field)) && ! isset($map[$name])) {
                $map[$name] = [
                    'field' => $field,
                    'storage_key' => $storage_key,
                    'root_name' => $current_root,
                ];
            }
        }
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @param string              $storage_key
     * @param array<string,mixed> $acf_resolution
     * @return array<string,mixed>
     */
    private static function translate_acf_field_to_storage(
        array $field,
        $value,
        string $path,
        string $storage_key,
        array $acf_resolution,
        array $clone_stack = []
    ): array {
        $type = isset($field['type']) ? sanitize_key((string) ($field['type'] ?? '')) : '';

        if ($storage_key === '' || in_array($type, ['tab', 'accordion', 'message'], true)) {
            return [
                'updates' => [],
                'issues' => [],
                'deferred_relationships' => [],
            ];
        }

        switch ($type) {
            case 'group':
                return self::translate_acf_group_to_storage($field, $value, $path, $storage_key, $acf_resolution, $clone_stack);

            case 'repeater':
                return self::translate_acf_repeater_to_storage($field, $value, $path, $storage_key, $acf_resolution, $clone_stack);

            case 'flexible_content':
                return self::translate_acf_flexible_to_storage($field, $value, $path, $storage_key, $acf_resolution, $clone_stack);

            case 'clone':
                return self::translate_acf_clone_to_storage($field, $value, $path, $storage_key, $acf_resolution, $clone_stack);
        }

        return self::translate_acf_leaf_to_storage($field, $value, $path, $storage_key);
    }

    /**
     * @param array<int,mixed>    $fields
     * @param array<string,mixed> $input
     * @param string              $path
     * @param string              $storage_prefix
     * @param array<string,mixed> $acf_resolution
     * @param array<int,string>   $clone_stack
     * @return array<string,mixed>
     */
    private static function translate_acf_container_fields(
        array $fields,
        array $input,
        string $path,
        string $storage_prefix,
        array $acf_resolution,
        array $clone_stack = []
    ): array {
        $result = [
            'updates' => [],
            'issues' => [],
            'deferred_relationships' => [],
        ];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = isset($field['type']) ? sanitize_key((string) ($field['type'] ?? '')) : '';
            if (in_array($type, ['tab', 'accordion', 'message'], true)) {
                continue;
            }

            $name = isset($field['name']) ? (string) ($field['name'] ?? '') : '';

            if ($type === 'clone' && $name === '') {
                $inline_clone = self::translate_acf_clone_to_storage($field, $input, $path, $storage_prefix, $acf_resolution, $clone_stack);
                self::merge_acf_translation_result($result['updates'], $result['issues'], $result['deferred_relationships'], $inline_clone);
                continue;
            }

            if ($name === '' || ! array_key_exists($name, $input)) {
                continue;
            }

            $field_result = self::translate_acf_field_to_storage(
                $field,
                $input[$name],
                $path . '.' . $name,
                self::append_acf_storage_key($storage_prefix, $name),
                $acf_resolution,
                $clone_stack
            );
            self::merge_acf_translation_result($result['updates'], $result['issues'], $result['deferred_relationships'], $field_result);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @param string              $storage_key
     * @param array<string,mixed> $acf_resolution
     * @param array<int,string>   $clone_stack
     * @return array<string,mixed>
     */
    private static function translate_acf_group_to_storage(
        array $field,
        $value,
        string $path,
        string $storage_key,
        array $acf_resolution,
        array $clone_stack = []
    ): array {
        if (! is_array($value)) {
            if (self::has_meaningful_value($value)) {
                return [
                    'updates' => [],
                    'issues' => [
                        self::build_issue(
                            'error',
                            'acf_field_unsupported_nonempty',
                            sprintf(__('The ACF group field `%s` must be provided as an object.', 'dbvc'), $storage_key),
                            $path
                        ),
                    ],
                    'deferred_relationships' => [],
                ];
            }

            return [
                'updates' => [],
                'issues' => [],
                'deferred_relationships' => [],
            ];
        }

        $updates = [
            $storage_key => '',
        ];
        $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
        if ($field_key !== '') {
            $updates['_' . $storage_key] = $field_key;
        }

        $result = self::translate_acf_container_fields(
            isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [],
            $value,
            $path,
            $storage_key,
            $acf_resolution,
            $clone_stack
        );
        foreach (isset($result['updates']) && is_array($result['updates']) ? $result['updates'] : [] as $meta_key => $meta_value) {
            $updates[(string) $meta_key] = $meta_value;
        }

        return [
            'updates' => $updates,
            'issues' => isset($result['issues']) && is_array($result['issues']) ? $result['issues'] : [],
            'deferred_relationships' => isset($result['deferred_relationships']) && is_array($result['deferred_relationships'])
                ? $result['deferred_relationships']
                : [],
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @param string              $storage_key
     * @param array<string,mixed> $acf_resolution
     * @param array<int,string>   $clone_stack
     * @return array<string,mixed>
     */
    private static function translate_acf_repeater_to_storage(
        array $field,
        $value,
        string $path,
        string $storage_key,
        array $acf_resolution,
        array $clone_stack = []
    ): array {
        $issues = [];
        $deferred_relationships = [];
        $updates = [];

        if (! is_array($value)) {
            if (self::has_meaningful_value($value)) {
                $issues[] = self::build_issue(
                    'error',
                    'acf_field_unsupported_nonempty',
                    sprintf(__('The ACF repeater field `%s` must be provided as an array of row objects.', 'dbvc'), $storage_key),
                    $path
                );
            }

            return [
                'updates' => $updates,
                'issues' => $issues,
                'deferred_relationships' => $deferred_relationships,
            ];
        }

        if (self::is_associative_array($value)) {
            $value = [$value];
            $issues[] = self::build_issue(
                'warning',
                'acf_repeater_row_wrapped',
                __('A single repeater row object was wrapped into a row collection.', 'dbvc'),
                $path
            );
        }

        $rows = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                if (self::has_meaningful_value($row)) {
                    $issues[] = self::build_issue(
                        'warning',
                        'acf_repeater_row_removed',
                        __('A repeater row was removed because it was not an object.', 'dbvc'),
                        $path
                    );
                }
                continue;
            }

            $rows[] = $row;
        }

        $updates[$storage_key] = count($rows);
        $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
        if ($field_key !== '') {
            $updates['_' . $storage_key] = $field_key;
        }

        foreach ($rows as $index => $row) {
            $row_result = self::translate_acf_container_fields(
                isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [],
                $row,
                $path . '[' . $index . ']',
                $storage_key . '_' . $index,
                $acf_resolution,
                $clone_stack
            );
            self::merge_acf_translation_result($updates, $issues, $deferred_relationships, $row_result);
        }

        return [
            'updates' => $updates,
            'issues' => $issues,
            'deferred_relationships' => $deferred_relationships,
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @param string              $storage_key
     * @param array<string,mixed> $acf_resolution
     * @param array<int,string>   $clone_stack
     * @return array<string,mixed>
     */
    private static function translate_acf_flexible_to_storage(
        array $field,
        $value,
        string $path,
        string $storage_key,
        array $acf_resolution,
        array $clone_stack = []
    ): array {
        $issues = [];
        $deferred_relationships = [];
        $updates = [];

        if (! is_array($value)) {
            if (self::has_meaningful_value($value)) {
                $issues[] = self::build_issue(
                    'error',
                    'acf_field_unsupported_nonempty',
                    sprintf(__('The ACF flexible content field `%s` must be provided as an array of layout rows.', 'dbvc'), $storage_key),
                    $path
                );
            }

            return [
                'updates' => $updates,
                'issues' => $issues,
                'deferred_relationships' => $deferred_relationships,
            ];
        }

        if (self::is_associative_array($value) && isset($value['acf_fc_layout'])) {
            $value = [$value];
            $issues[] = self::build_issue(
                'warning',
                'acf_flexible_row_wrapped',
                __('A single flexible content row was wrapped into a collection.', 'dbvc'),
                $path
            );
        }

        $layouts = isset($field['layouts']) && is_array($field['layouts']) ? $field['layouts'] : [];
        $layouts_by_name = [];
        foreach ($layouts as $layout) {
            if (! is_array($layout)) {
                continue;
            }

            $layout_name = isset($layout['name']) ? sanitize_key((string) ($layout['name'] ?? '')) : '';
            if ($layout_name !== '' && ! isset($layouts_by_name[$layout_name])) {
                $layouts_by_name[$layout_name] = $layout;
            }
        }

        $layout_sequence = [];
        $row_index = 0;
        foreach ($value as $item_index => $row) {
            if (! is_array($row)) {
                if (self::has_meaningful_value($row)) {
                    $issues[] = self::build_issue(
                        'warning',
                        'acf_flexible_row_removed',
                        __('A flexible content row was removed because it was not an object.', 'dbvc'),
                        $path . '[' . $item_index . ']'
                    );
                }
                continue;
            }

            $layout_name = isset($row['acf_fc_layout']) ? sanitize_key((string) ($row['acf_fc_layout'] ?? '')) : '';
            if ($layout_name === '' || ! isset($layouts_by_name[$layout_name])) {
                $issues[] = self::build_issue(
                    'warning',
                    'acf_flexible_layout_removed',
                    __('A flexible content row was removed because its layout could not be matched to the local field definition.', 'dbvc'),
                    $path . '[' . $item_index . '].acf_fc_layout'
                );
                continue;
            }

            $row_input = $row;
            unset($row_input['acf_fc_layout']);

            $row_result = self::translate_acf_container_fields(
                isset($layouts_by_name[$layout_name]['sub_fields']) && is_array($layouts_by_name[$layout_name]['sub_fields'])
                    ? $layouts_by_name[$layout_name]['sub_fields']
                    : [],
                $row_input,
                $path . '[' . $item_index . '].' . $layout_name,
                $storage_key . '_' . $row_index,
                $acf_resolution,
                $clone_stack
            );
            self::merge_acf_translation_result($updates, $issues, $deferred_relationships, $row_result);
            $layout_sequence[] = $layout_name;
            $row_index++;
        }

        $updates[$storage_key] = [$layout_sequence];
        $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
        if ($field_key !== '') {
            $updates['_' . $storage_key] = $field_key;
        }

        return [
            'updates' => $updates,
            'issues' => $issues,
            'deferred_relationships' => $deferred_relationships,
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @param string              $storage_key
     * @param array<string,mixed> $acf_resolution
     * @param array<int,string>   $clone_stack
     * @return array<string,mixed>
     */
    private static function translate_acf_clone_to_storage(
        array $field,
        $value,
        string $path,
        string $storage_key,
        array $acf_resolution,
        array $clone_stack = []
    ): array {
        $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
        $resolved_fields = self::resolve_clone_targets($field, $acf_resolution, $clone_stack);
        if (empty($resolved_fields)) {
            if (self::has_meaningful_value($value)) {
                return [
                    'updates' => [],
                    'issues' => [
                        self::build_issue(
                            'error',
                            'acf_field_unsupported_nonempty',
                            sprintf(__('The clone field `%s` could not be resolved against the current site schema.', 'dbvc'), $storage_key),
                            $path
                        ),
                    ],
                    'deferred_relationships' => [],
                ];
            }

            return [
                'updates' => [],
                'issues' => [],
                'deferred_relationships' => [],
            ];
        }

        if (! is_array($value)) {
            if (self::has_meaningful_value($value)) {
                return [
                    'updates' => [],
                    'issues' => [
                        self::build_issue(
                            'error',
                            'acf_field_unsupported_nonempty',
                            sprintf(__('The clone field `%s` must be provided as an object.', 'dbvc'), $storage_key),
                            $path
                        ),
                    ],
                    'deferred_relationships' => [],
                ];
            }

            return [
                'updates' => [],
                'issues' => [],
                'deferred_relationships' => [],
            ];
        }

        $updated_clone_stack = $clone_stack;
        if ($field_key !== '') {
            $updated_clone_stack[] = $field_key;
        }

        $name = isset($field['name']) ? (string) ($field['name'] ?? '') : '';
        $base_prefix = $storage_key;
        if ($name !== '' && empty($field['clone']['prefix_name'])) {
            $base_prefix = self::remove_acf_storage_suffix($storage_key, $name);
        }

        return self::translate_acf_container_fields(
            $resolved_fields,
            $value,
            $path,
            $base_prefix,
            $acf_resolution,
            $updated_clone_stack
        );
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @param string              $storage_key
     * @return array<string,mixed>
     */
    private static function translate_acf_leaf_to_storage(array $field, $value, string $path, string $storage_key): array
    {
        $issues = [];
        $deferred_relationships = [];
        $updates = [];

        if (self::is_supported_deferred_acf_field($field)) {
            $deferred_result = self::normalize_deferred_acf_value($storage_key, $field, $value, $path);
            foreach ($deferred_result['issues'] as $issue) {
                $issues[] = $issue;
            }

            if (! empty($deferred_result['descriptor']) && is_array($deferred_result['descriptor'])) {
                $deferred_relationships['acf_meta'] = [$deferred_result['descriptor']];
            }

            return [
                'updates' => $updates,
                'issues' => $issues,
                'deferred_relationships' => $deferred_relationships,
            ];
        }

        if (! self::is_supported_simple_acf_field($field)) {
            if (self::has_meaningful_value($value)) {
                $issues[] = self::build_issue(
                    'error',
                    'acf_field_unsupported_nonempty',
                    sprintf(__('The ACF field `%s` contains a non-empty complex value that is not supported by the v1 AI translation path.', 'dbvc'), $storage_key),
                    $path
                );
            }

            return [
                'updates' => $updates,
                'issues' => $issues,
                'deferred_relationships' => $deferred_relationships,
            ];
        }

        $value_result = self::normalize_simple_acf_value($field, $value, $path);
        foreach ($value_result['issues'] as $issue) {
            $issues[] = $issue;
        }

        if (! array_key_exists('value', $value_result)) {
            return [
                'updates' => $updates,
                'issues' => $issues,
                'deferred_relationships' => $deferred_relationships,
            ];
        }

        $normalized_value = $value_result['value'];
        if (is_array($normalized_value)) {
            $normalized_value = [$normalized_value];
        }

        $updates[$storage_key] = $normalized_value;
        $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
        if ($field_key !== '') {
            $updates['_' . $storage_key] = $field_key;
        }

        return [
            'updates' => $updates,
            'issues' => $issues,
            'deferred_relationships' => $deferred_relationships,
        ];
    }

    /**
     * @param array<string,mixed> $field_catalog
     * @return array<string,mixed>
     */
    private static function build_acf_resolution_context(array $field_catalog): array
    {
        $groups_by_key = [];
        $fields_by_key = [];

        foreach (['post_types', 'taxonomies'] as $branch) {
            $entries = isset($field_catalog[$branch]) && is_array($field_catalog[$branch]) ? $field_catalog[$branch] : [];
            foreach ($entries as $entry) {
                if (! is_array($entry) || empty($entry['acf']['groups']) || ! is_array($entry['acf']['groups'])) {
                    continue;
                }

                foreach ($entry['acf']['groups'] as $group_key => $group) {
                    if (! is_array($group) || isset($groups_by_key[$group_key])) {
                        continue;
                    }

                    $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
                    $groups_by_key[$group_key] = $fields;
                    self::index_acf_resolution_fields($fields, $fields_by_key);
                }
            }
        }

        return [
            'groups_by_key' => $groups_by_key,
            'fields_by_key' => $fields_by_key,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,array<string,mixed>> $fields_by_key
     * @return void
     */
    private static function index_acf_resolution_fields(array $fields, array &$fields_by_key): void
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
            if ($field_key !== '' && ! isset($fields_by_key[$field_key])) {
                $fields_by_key[$field_key] = $field;
            }

            if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                self::index_acf_resolution_fields($field['sub_fields'], $fields_by_key);
            }

            if (! empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                        continue;
                    }

                    self::index_acf_resolution_fields($layout['sub_fields'], $fields_by_key);
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $acf_resolution
     * @param array<int,string>   $stack
     * @return array<int,array<string,mixed>>
     */
    private static function resolve_clone_targets(array $field, array $acf_resolution, array $stack): array
    {
        $targets = isset($field['clone']['targets']) && is_array($field['clone']['targets']) ? $field['clone']['targets'] : [];
        if (empty($targets)) {
            return [];
        }

        $fields_by_key = isset($acf_resolution['fields_by_key']) && is_array($acf_resolution['fields_by_key']) ? $acf_resolution['fields_by_key'] : [];
        $groups_by_key = isset($acf_resolution['groups_by_key']) && is_array($acf_resolution['groups_by_key']) ? $acf_resolution['groups_by_key'] : [];
        $resolved = [];

        foreach ($targets as $target) {
            $target = (string) $target;
            if ($target === '' || in_array($target, $stack, true)) {
                continue;
            }

            if (isset($fields_by_key[$target]) && is_array($fields_by_key[$target])) {
                $resolved[] = $fields_by_key[$target];
                continue;
            }

            if (isset($groups_by_key[$target]) && is_array($groups_by_key[$target])) {
                foreach ($groups_by_key[$target] as $group_field) {
                    if (is_array($group_field)) {
                        $resolved[] = $group_field;
                    }
                }
            }
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $meta_updates
     * @param array<int,array<string,mixed>> $issues
     * @param array<string,mixed> $deferred_relationships
     * @param array<string,mixed> $result
     * @return void
     */
    private static function merge_acf_translation_result(array &$meta_updates, array &$issues, array &$deferred_relationships, array $result): void
    {
        $result_updates = isset($result['updates']) && is_array($result['updates']) ? $result['updates'] : [];
        foreach ($result_updates as $meta_key => $meta_value) {
            $meta_updates[(string) $meta_key] = $meta_value;
        }

        $result_issues = isset($result['issues']) && is_array($result['issues']) ? $result['issues'] : [];
        foreach ($result_issues as $issue) {
            if (is_array($issue)) {
                $issues[] = $issue;
            }
        }

        $deferred_relationships = self::merge_deferred_relationships(
            $deferred_relationships,
            isset($result['deferred_relationships']) && is_array($result['deferred_relationships'])
                ? $result['deferred_relationships']
                : []
        );
    }

    /**
     * @param string $prefix
     * @param string $segment
     * @return string
     */
    private static function append_acf_storage_key(string $prefix, string $segment): string
    {
        $segment = sanitize_key($segment);
        if ($segment === '') {
            return $prefix;
        }

        return $prefix === '' ? $segment : $prefix . '_' . $segment;
    }

    /**
     * @param string $storage_key
     * @param string $segment
     * @return string
     */
    private static function remove_acf_storage_suffix(string $storage_key, string $segment): string
    {
        $segment = sanitize_key($segment);
        if ($segment === '') {
            return $storage_key;
        }

        if ($storage_key === $segment) {
            return '';
        }

        $suffix = '_' . $segment;
        if (substr($storage_key, -strlen($suffix)) === $suffix) {
            return substr($storage_key, 0, -strlen($suffix));
        }

        return $storage_key;
    }

    /**
     * @param array<string,mixed> $field
     * @return bool
     */
    private static function is_supported_simple_acf_field(array $field): bool
    {
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $multiple = ! empty($field['multiple']);

        if ($type === 'checkbox') {
            return true;
        }

        if (in_array($type, ['select', 'button_group'], true)) {
            return true;
        }

        if ($multiple) {
            return false;
        }

        return in_array($type, [
            'text',
            'textarea',
            'wysiwyg',
            'email',
            'url',
            'number',
            'range',
            'true_false',
            'select',
            'radio',
            'button_group',
            'color_picker',
            'date_picker',
            'date_time_picker',
            'time_picker',
            'oembed',
        ], true);
    }

    /**
     * @param array<string,mixed> $field
     * @return bool
     */
    private static function is_supported_deferred_acf_field(array $field): bool
    {
        $type = isset($field['type']) ? sanitize_key((string) ($field['type'] ?? '')) : '';

        return in_array($type, [
            'post_object',
            'relationship',
            'taxonomy',
        ], true);
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @return array<string,mixed>
     */
    private static function normalize_simple_acf_value(array $field, $value, string $path): array
    {
        $issues = [];
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $multiple = ! empty($field['multiple']);

        if ($type === 'checkbox' || (($type === 'select' || $type === 'button_group') && $multiple)) {
            if (! is_array($value)) {
                if ($value === '' || $value === null) {
                    return [
                        'value' => [],
                        'issues' => $issues,
                    ];
                }

                $issues[] = self::build_issue(
                    'warning',
                    'acf_choice_wrapped',
                    __('A single choice value was wrapped into an array for a multi-value ACF field.', 'dbvc'),
                    $path
                );
                $value = [$value];
            }

            $normalized_values = [];
            foreach ($value as $item) {
                if (is_bool($item)) {
                    $item = $item ? '1' : '0';
                } elseif ($item === null) {
                    $item = '';
                } elseif (! is_scalar($item)) {
                    $issues[] = self::build_issue(
                        'warning',
                        'acf_field_removed',
                        __('A non-scalar choice value could not be stored safely and was removed.', 'dbvc'),
                        $path
                    );
                    continue;
                }

                $item = (string) $item;
                if ($item === '') {
                    continue;
                }

                $normalized_values[] = $item;
            }

            return [
                'value' => array_values(array_unique($normalized_values)),
                'issues' => $issues,
            ];
        }

        if (is_array($value)) {
            if (count($value) === 1) {
                $first = reset($value);
                if (! is_array($first) && ! is_object($first)) {
                    $issues[] = self::build_issue(
                        'warning',
                        'acf_scalar_collapsed',
                        __('A single-item array value was collapsed to a scalar for a simple ACF field.', 'dbvc'),
                        $path
                    );
                    $value = $first;
                } elseif (($type === 'select' || $type === 'button_group' || $type === 'radio') && is_array($first)) {
                    return [
                        'issues' => array_merge($issues, [
                            self::build_issue(
                                'warning',
                                'acf_field_removed',
                                __('A nested array value could not be stored safely for this ACF field and was removed.', 'dbvc'),
                                $path
                            ),
                        ]),
                    ];
                }
            } elseif (($type === 'select' || $type === 'button_group' || $type === 'radio') && ! $multiple) {
                $first = reset($value);
                if (! is_array($first) && ! is_object($first)) {
                    $issues[] = self::build_issue(
                        'warning',
                        'acf_scalar_collapsed',
                        __('A multi-item array value was collapsed to the first item for a single-value ACF field.', 'dbvc'),
                        $path
                    );
                    $value = $first;
                }
            }

            if (is_array($value)) {
                return [
                    'issues' => array_merge($issues, [
                        self::build_issue(
                            'warning',
                            'acf_field_removed',
                            __('A complex array value could not be stored safely for this ACF field and was removed.', 'dbvc'),
                            $path
                        ),
                    ]),
                ];
            }
        }

        switch ($type) {
            case 'number':
            case 'range':
                if ($value === '' || $value === null) {
                    return [
                        'value' => 0,
                        'issues' => $issues,
                    ];
                }

                if (! is_numeric($value)) {
                    return [
                        'issues' => array_merge($issues, [
                            self::build_issue(
                                'warning',
                                'acf_field_removed',
                                __('A non-numeric value could not be stored safely for a numeric ACF field and was removed.', 'dbvc'),
                                $path
                            ),
                        ]),
                    ];
                }

                return [
                    'value' => 0 + $value,
                    'issues' => $issues,
                ];

            case 'true_false':
                return [
                    'value' => empty($value) ? 0 : 1,
                    'issues' => $issues,
                ];

            default:
                if (is_bool($value)) {
                    $value = $value ? '1' : '';
                } elseif (is_scalar($value) || $value === null) {
                    $value = $value === null ? '' : (string) $value;
                } else {
                    return [
                        'issues' => array_merge($issues, [
                            self::build_issue(
                                'warning',
                                'acf_field_removed',
                                __('An unsupported ACF value shape was removed from the translated payload.', 'dbvc'),
                                $path
                            ),
                        ]),
                    ];
                }

                return [
                    'value' => $value,
                    'issues' => $issues,
                ];
        }
    }

    /**
     * @param string              $field_name
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @return array<string,mixed>
     */
    private static function normalize_deferred_acf_value(string $field_name, array $field, $value, string $path): array
    {
        $issues = [];
        $field_type = isset($field['type']) ? sanitize_key((string) ($field['type'] ?? '')) : '';
        $field_key = isset($field['key']) ? (string) ($field['key'] ?? '') : '';
        $return_format = isset($field['return_format']) ? sanitize_key((string) ($field['return_format'] ?? '')) : '';

        if (! self::has_meaningful_value($value)) {
            return [
                'issues' => [
                    self::build_issue(
                        'warning',
                        'acf_field_deferred_empty',
                        sprintf(__('The ACF field `%s` uses deferred relationship resolution in v1 and was removed because its value was empty.', 'dbvc'), $field_name),
                        $path
                    ),
                ],
                'descriptor' => [],
            ];
        }

        if ($field_type === 'taxonomy') {
            $value_result = self::normalize_deferred_acf_taxonomy_values($field, $value, $path);
        } else {
            $value_result = self::normalize_deferred_acf_post_values($field, $value, $path);
        }

        foreach ($value_result['issues'] as $issue) {
            $issues[] = $issue;
        }

        $values = isset($value_result['values']) && is_array($value_result['values'])
            ? array_values($value_result['values'])
            : [];
        if (empty($values)) {
            return [
                'issues' => $issues,
                'descriptor' => [],
            ];
        }

        return [
            'issues' => $issues,
            'descriptor' => [
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_type' => $field_type,
                'return_format' => $return_format,
                'multiple' => self::acf_field_expects_multiple($field),
                'post_types' => isset($field['post_type']) && is_array($field['post_type'])
                    ? array_values(array_filter(array_map('sanitize_key', $field['post_type'])))
                    : [],
                'taxonomy_filters' => isset($field['taxonomy_filters']) && is_array($field['taxonomy_filters'])
                    ? array_values(array_filter(array_map('sanitize_key', $field['taxonomy_filters'])))
                    : [],
                'field_ui_type' => isset($field['field_type']) ? sanitize_key((string) ($field['field_type'] ?? '')) : '',
                'save_terms' => ! empty($field['save_terms']),
                'load_terms' => ! empty($field['load_terms']),
                'values' => $values,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @return array<string,mixed>
     */
    private static function normalize_deferred_acf_post_values(array $field, $value, string $path): array
    {
        $issues = [];
        $values = [];
        $expects_multiple = self::acf_field_expects_multiple($field);
        $default_post_type = '';

        if (! empty($field['post_type']) && is_array($field['post_type'])) {
            $candidate_types = array_values(array_filter(array_map('sanitize_key', $field['post_type'])));
            if (! empty($candidate_types)) {
                $default_post_type = (string) $candidate_types[0];
            }
        }

        foreach (self::normalize_acf_field_input_items($value, $expects_multiple, $path) as $normalized_item) {
            if (! empty($normalized_item['issue']) && is_array($normalized_item['issue'])) {
                $issues[] = $normalized_item['issue'];
            }
            if (! array_key_exists('value', $normalized_item)) {
                continue;
            }

            $descriptor = self::build_post_reference_descriptor($normalized_item['value'], $default_post_type);
            if (empty($descriptor)) {
                $issues[] = self::build_issue(
                    'warning',
                    'acf_relationship_item_removed',
                    __('A post relationship reference could not be normalized and was removed.', 'dbvc'),
                    $path
                );
                continue;
            }

            $values[] = $descriptor;
        }

        return [
            'issues' => $issues,
            'values' => $values,
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @param mixed               $value
     * @param string              $path
     * @return array<string,mixed>
     */
    private static function normalize_deferred_acf_taxonomy_values(array $field, $value, string $path): array
    {
        $issues = [];
        $values = [];
        $expects_multiple = self::acf_field_expects_multiple($field);
        $taxonomy_filters = isset($field['taxonomy_filters']) && is_array($field['taxonomy_filters'])
            ? array_values(array_filter(array_map('sanitize_key', $field['taxonomy_filters'])))
            : [];
        $default_taxonomy = count($taxonomy_filters) === 1 ? (string) $taxonomy_filters[0] : '';

        foreach (self::normalize_acf_field_input_items($value, $expects_multiple, $path) as $normalized_item) {
            if (! empty($normalized_item['issue']) && is_array($normalized_item['issue'])) {
                $issues[] = $normalized_item['issue'];
            }
            if (! array_key_exists('value', $normalized_item)) {
                continue;
            }

            $descriptor_result = self::build_term_reference_descriptor(
                $normalized_item['value'],
                $default_taxonomy,
                $path,
                $taxonomy_filters
            );
            foreach ($descriptor_result['issues'] as $issue) {
                $issues[] = $issue;
            }
            if (! empty($descriptor_result['value']) && is_array($descriptor_result['value'])) {
                $values[] = $descriptor_result['value'];
            }
        }

        return [
            'issues' => $issues,
            'values' => $values,
        ];
    }

    /**
     * @param mixed $value
     * @param bool  $expects_multiple
     * @param string $path
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_acf_field_input_items($value, bool $expects_multiple, string $path): array
    {
        if (! $expects_multiple) {
            if (is_array($value) && ! self::is_associative_array($value)) {
                $first = reset($value);
                return [[
                    'value' => $first,
                    'issue' => self::build_issue(
                        'warning',
                        'acf_relationship_scalar_collapsed',
                        __('A multi-value input was collapsed to the first item for a single-value ACF relationship field.', 'dbvc'),
                        $path
                    ),
                ]];
            }

            return [['value' => $value]];
        }

        if (is_array($value) && ! self::is_associative_array($value)) {
            return array_map(static function ($item): array {
                return ['value' => $item];
            }, $value);
        }

        return [[
            'value' => $value,
            'issue' => self::build_issue(
                'warning',
                'acf_relationship_wrapped',
                __('A single relationship reference was wrapped into a collection for a multi-value ACF relationship field.', 'dbvc'),
                $path
            ),
        ]];
    }

    /**
     * @param array<string,mixed> $field
     * @return bool
     */
    private static function acf_field_expects_multiple(array $field): bool
    {
        $type = isset($field['type']) ? sanitize_key((string) ($field['type'] ?? '')) : '';
        if ($type === 'relationship') {
            return true;
        }

        return ! empty($field['multiple']);
    }

    /**
     * @param mixed                 $value
     * @param string                $default_taxonomy
     * @param string                $path
     * @param array<int,string>     $taxonomy_filters
     * @return array<string,mixed>
     */
    private static function build_term_reference_descriptor($value, string $default_taxonomy, string $path, array $taxonomy_filters = []): array
    {
        $issues = [];
        $taxonomy = $default_taxonomy;

        if (is_array($value)) {
            $item_taxonomy = isset($value['taxonomy']) ? sanitize_key((string) ($value['taxonomy'] ?? '')) : '';
            if ($item_taxonomy !== '') {
                $taxonomy = $item_taxonomy;
            }
        }

        if ($taxonomy === '') {
            $issues[] = self::build_issue(
                'warning',
                'acf_taxonomy_reference_removed',
                __('A taxonomy ACF relationship reference was removed because the target taxonomy could not be determined.', 'dbvc'),
                $path
            );

            return [
                'issues' => $issues,
                'value' => [],
            ];
        }

        if (! empty($taxonomy_filters) && ! in_array($taxonomy, $taxonomy_filters, true)) {
            $issues[] = self::build_issue(
                'warning',
                'acf_taxonomy_reference_removed',
                __('A taxonomy ACF relationship reference targeted a taxonomy that is not allowed by the local field settings and was removed.', 'dbvc'),
                $path
            );

            return [
                'issues' => $issues,
                'value' => [],
            ];
        }

        $slug_result = self::extract_term_reference_slug($value, $taxonomy, $path);
        foreach ($slug_result['issues'] as $issue) {
            $issues[] = $issue;
        }
        $slug = isset($slug_result['slug']) ? (string) $slug_result['slug'] : '';
        if ($slug === '') {
            return [
                'issues' => $issues,
                'value' => [],
            ];
        }

        return [
            'issues' => $issues,
            'value' => [
                'taxonomy' => $taxonomy,
                'slug' => $slug,
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function is_associative_array($value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private static function merge_deferred_relationships(array $left, array $right): array
    {
        $merged = $left;

        foreach ($right as $key => $value) {
            if (! isset($merged[$key])) {
                $merged[$key] = $value;
                continue;
            }

            if (is_array($merged[$key]) && is_array($value) && ! self::is_associative_array($merged[$key]) && ! self::is_associative_array($value)) {
                $merged[$key] = array_values(array_merge($merged[$key], $value));
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function has_meaningful_value($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::has_meaningful_value($item)) {
                    return true;
                }
            }

            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        return trim((string) $value) !== '';
    }

    /**
     * @param array<string,mixed> $tax_input
     * @param string              $post_type
     * @param string              $path
     * @return array<string,mixed>
     */
    private static function normalize_tax_input(array $tax_input, string $post_type, string $path): array
    {
        $issues = [];
        $normalized = [];

        foreach ($tax_input as $taxonomy_key => $items) {
            $taxonomy = sanitize_key((string) $taxonomy_key);
            if ($taxonomy === '') {
                $issues[] = self::build_issue(
                    'warning',
                    'tax_input_taxonomy_invalid',
                    __('A taxonomy assignment with an invalid taxonomy key was removed.', 'dbvc'),
                    $path . '#tax_input'
                );
                continue;
            }

            if (! taxonomy_exists($taxonomy)) {
                $issues[] = self::build_issue(
                    'warning',
                    'tax_input_taxonomy_unknown',
                    sprintf(__('The taxonomy `%s` is not registered on the current site and was removed.', 'dbvc'), $taxonomy),
                    $path . '#tax_input.' . $taxonomy
                );
                continue;
            }

            if ($post_type !== '' && ! is_object_in_taxonomy($post_type, $taxonomy)) {
                $issues[] = self::build_issue(
                    'warning',
                    'tax_input_taxonomy_unattached',
                    sprintf(__('The taxonomy `%s` is not attached to `%s` and was removed from the translated payload.', 'dbvc'), $taxonomy, $post_type),
                    $path . '#tax_input.' . $taxonomy
                );
                continue;
            }

            if (! is_array($items)) {
                $items = [$items];
            }

            $normalized_items = [];
            foreach ($items as $index => $item) {
                $item_result = self::normalize_taxonomy_item($item, $taxonomy, $path . '#tax_input.' . $taxonomy . '.' . $index);
                foreach ($item_result['issues'] as $issue) {
                    $issues[] = $issue;
                }
                if (($item_result['value'] ?? null) !== null) {
                    $normalized_items[] = $item_result['value'];
                }
            }

            if (! empty($normalized_items)) {
                $normalized[$taxonomy] = $normalized_items;
            }
        }

        return [
            'tax_input' => $normalized,
            'issues' => $issues,
        ];
    }

    /**
     * @param mixed  $item
     * @param string $taxonomy
     * @param string $path
     * @return array<string,mixed>
     */
    private static function normalize_taxonomy_item($item, string $taxonomy, string $path): array
    {
        $issues = [];

        if (is_string($item)) {
            $slug = sanitize_title($item);
            if ($slug === '') {
                $issues[] = self::build_issue(
                    'warning',
                    'tax_input_item_removed',
                    __('An empty taxonomy slug was removed from the translated payload.', 'dbvc'),
                    $path
                );

                return [
                    'value' => null,
                    'issues' => $issues,
                ];
            }

            return [
                'value' => $slug,
                'issues' => $issues,
            ];
        }

        if (is_numeric($item)) {
            $term = get_term(absint($item), $taxonomy);
            if ($term && ! is_wp_error($term)) {
                $issues[] = self::build_issue(
                    'warning',
                    'tax_input_numeric_normalized',
                    __('A numeric taxonomy reference was converted to a slug-based reference during translation.', 'dbvc'),
                    $path
                );

                return [
                    'value' => sanitize_title((string) $term->slug),
                    'issues' => $issues,
                ];
            }

            $issues[] = self::build_issue(
                'warning',
                'tax_input_item_removed',
                __('A numeric taxonomy reference could not be matched locally and was removed.', 'dbvc'),
                $path
            );

            return [
                'value' => null,
                'issues' => $issues,
            ];
        }

        if (! is_array($item)) {
            $issues[] = self::build_issue(
                'warning',
                'tax_input_item_removed',
                __('An unsupported taxonomy reference was removed from the translated payload.', 'dbvc'),
                $path
            );

            return [
                'value' => null,
                'issues' => $issues,
            ];
        }

        if (isset($item['taxonomy']) && sanitize_key((string) $item['taxonomy']) !== '' && sanitize_key((string) $item['taxonomy']) !== $taxonomy) {
            $issues[] = self::build_issue(
                'warning',
                'tax_input_taxonomy_mismatch',
                __('A structured taxonomy reference declared a mismatched taxonomy. The taxonomy key from the payload path was used instead.', 'dbvc'),
                $path
            );
        }

        $slug_result = self::extract_term_reference_slug($item, $taxonomy, $path);
        foreach ($slug_result['issues'] as $issue) {
            $issues[] = $issue;
        }
        $slug = isset($slug_result['slug']) ? (string) $slug_result['slug'] : '';
        if ($slug === '') {
            return [
                'value' => null,
                'issues' => $issues,
            ];
        }

        $value = [
            'slug' => $slug,
        ];

        $name = isset($item['name']) ? sanitize_text_field((string) $item['name']) : '';
        if ($name !== '') {
            $value['name'] = $name;
        }

        if (array_key_exists('parent', $item)) {
            $parent_result = self::extract_term_reference_slug($item['parent'], $taxonomy, $path . '.parent');
            foreach ($parent_result['issues'] as $issue) {
                $issues[] = $issue;
            }
            if (($parent_result['slug'] ?? '') !== '') {
                $value['parent'] = (string) $parent_result['slug'];
            }
        } elseif (array_key_exists('parent_slug', $item)) {
            $parent_result = self::extract_term_reference_slug($item['parent_slug'], $taxonomy, $path . '.parent_slug');
            foreach ($parent_result['issues'] as $issue) {
                $issues[] = $issue;
            }
            if (($parent_result['slug'] ?? '') !== '') {
                $value['parent'] = (string) $parent_result['slug'];
            }
        }

        return [
            'value' => $value,
            'issues' => $issues,
        ];
    }

    /**
     * @param mixed  $value
     * @param string $taxonomy
     * @param string $path
     * @return array<string,mixed>
     */
    private static function extract_term_reference_slug($value, string $taxonomy, string $path): array
    {
        $issues = [];

        if (is_string($value)) {
            $slug = sanitize_title($value);
            if ($slug === '') {
                $issues[] = self::build_issue(
                    'warning',
                    'term_reference_removed',
                    __('An empty taxonomy reference was removed from the translated payload.', 'dbvc'),
                    $path
                );
            }

            return [
                'slug' => $slug,
                'issues' => $issues,
            ];
        }

        if (is_numeric($value)) {
            $term = get_term(absint($value), $taxonomy);
            if ($term && ! is_wp_error($term)) {
                $issues[] = self::build_issue(
                    'warning',
                    'term_reference_numeric_normalized',
                    __('A numeric taxonomy reference was converted to a slug-based reference during translation.', 'dbvc'),
                    $path
                );

                return [
                    'slug' => sanitize_title((string) $term->slug),
                    'issues' => $issues,
                ];
            }

            $issues[] = self::build_issue(
                'warning',
                'term_reference_removed',
                __('A numeric taxonomy reference could not be matched locally and was removed.', 'dbvc'),
                $path
            );

            return [
                'slug' => '',
                'issues' => $issues,
            ];
        }

        if (! is_array($value)) {
            $issues[] = self::build_issue(
                'warning',
                'term_reference_removed',
                __('An unsupported taxonomy reference was removed from the translated payload.', 'dbvc'),
                $path
            );

            return [
                'slug' => '',
                'issues' => $issues,
            ];
        }

        if (isset($value['taxonomy']) && sanitize_key((string) $value['taxonomy']) !== '' && sanitize_key((string) $value['taxonomy']) !== $taxonomy) {
            $issues[] = self::build_issue(
                'warning',
                'term_reference_taxonomy_mismatch',
                __('A taxonomy reference declared a mismatched taxonomy. The local field taxonomy was used instead.', 'dbvc'),
                $path
            );
        }

        $slug = isset($value['slug']) ? sanitize_title((string) $value['slug']) : '';
        if ($slug === '' && isset($value['name']) && is_string($value['name'])) {
            $slug = sanitize_title($value['name']);
        }

        if ($slug === '') {
            $issues[] = self::build_issue(
                'warning',
                'term_reference_removed',
                __('A taxonomy reference could not be normalized because it did not provide a slug.', 'dbvc'),
                $path
            );
        }

        return [
            'slug' => $slug,
            'issues' => $issues,
        ];
    }

    /**
     * @param mixed  $value
     * @param string $post_type
     * @param string $path
     * @param int    $resolved_local_id
     * @return array<string,mixed>
     */
    private static function normalize_post_parent_reference($value, string $post_type, string $path, int $resolved_local_id): array
    {
        $issues = [];
        $local_id = 0;
        $preserve_zero = false;
        $deferred_reference = null;

        if (is_numeric($value)) {
            $candidate_id = absint($value);
            if ($candidate_id === 0) {
                $preserve_zero = true;
            } elseif ($candidate_id > 0) {
                $candidate = get_post($candidate_id);
                if ($candidate instanceof \WP_Post) {
                    $local_id = (int) $candidate->ID;
                    $issues[] = self::build_issue(
                        'warning',
                        'post_parent_numeric_preserved',
                        __('A numeric post parent reference was preserved. Slug-based references remain the preferred AI authoring format.', 'dbvc'),
                        $path . '#post_parent'
                    );
                } else {
                    $issues[] = self::build_issue(
                        'warning',
                        'post_parent_removed',
                        __('A numeric post parent reference could not be matched locally and was removed.', 'dbvc'),
                        $path . '#post_parent'
                    );
                }
            }
        } elseif (is_string($value) || is_array($value)) {
            $deferred_reference = self::build_post_reference_descriptor($value, $post_type);
            $reference = self::resolve_post_reference($value, $post_type);
            $local_id = isset($reference['local_id']) ? (int) $reference['local_id'] : 0;

            if ($local_id <= 0) {
                $issues[] = self::build_issue(
                    'warning',
                    'post_parent_deferred',
                    __('A slug-based post parent reference could not be matched yet and was deferred for post-import resolution.', 'dbvc'),
                    $path . '#post_parent'
                );
            }
        } else {
            $issues[] = self::build_issue(
                'warning',
                'post_parent_removed',
                __('An unsupported post parent reference was removed from the translated payload.', 'dbvc'),
                $path . '#post_parent'
            );
        }

        if ($local_id > 0 && $resolved_local_id > 0 && $local_id === $resolved_local_id) {
            $issues[] = self::build_issue(
                'warning',
                'post_parent_self_reference',
                __('A post parent reference resolved to the current post and was removed.', 'dbvc'),
                $path . '#post_parent'
            );
            $local_id = 0;
        }

        return [
            'local_id' => $local_id,
            'preserve_zero' => $preserve_zero,
            'deferred_reference' => is_array($deferred_reference) ? $deferred_reference : [],
            'issues' => $issues,
        ];
    }

    /**
     * @param mixed  $reference
     * @param string $default_post_type
     * @return array<string,mixed>
     */
    private static function build_post_reference_descriptor($reference, string $default_post_type): array
    {
        if (is_numeric($reference)) {
            $post_id = absint($reference);
            if ($post_id <= 0) {
                return [];
            }

            $descriptor = ['ID' => $post_id];
            if ($default_post_type !== '') {
                $descriptor['post_type'] = $default_post_type;
            }

            return $descriptor;
        }

        if (is_string($reference)) {
            $slug = sanitize_title($reference);
            return $slug === ''
                ? []
                : [
                    'slug' => $slug,
                    'post_type' => $default_post_type,
                ];
        }

        if (! is_array($reference)) {
            return [];
        }

        $slug = isset($reference['slug']) ? sanitize_title((string) $reference['slug']) : '';
        $post_type = isset($reference['post_type']) ? sanitize_key((string) $reference['post_type']) : $default_post_type;
        $uid = isset($reference['vf_object_uid']) ? trim((string) $reference['vf_object_uid']) : '';
        $post_id = isset($reference['ID']) ? absint($reference['ID']) : 0;

        $descriptor = [];
        if ($slug !== '') {
            $descriptor['slug'] = $slug;
        }
        if ($post_type !== '') {
            $descriptor['post_type'] = $post_type;
        }
        if ($uid !== '') {
            $descriptor['vf_object_uid'] = $uid;
        }
        if ($post_id > 0) {
            $descriptor['ID'] = $post_id;
        }

        return $descriptor;
    }

    /**
     * @param mixed  $reference
     * @param string $default_post_type
     * @return array<string,mixed>
     */
    private static function resolve_post_reference($reference, string $default_post_type): array
    {
        if (is_string($reference)) {
            $slug = sanitize_title($reference);
            if ($slug === '') {
                return [
                    'local_id' => 0,
                ];
            }

            return self::resolve_post_target([
                'post_type' => $default_post_type,
                'post_name' => $slug,
            ]);
        }

        if (is_numeric($reference)) {
            return self::resolve_post_target([
                'post_type' => $default_post_type,
                'ID' => absint($reference),
            ]);
        }

        if (! is_array($reference)) {
            return [
                'local_id' => 0,
            ];
        }

        $payload = [
            'post_type' => isset($reference['post_type']) ? sanitize_key((string) $reference['post_type']) : $default_post_type,
            'post_name' => isset($reference['slug']) ? sanitize_title((string) $reference['slug']) : '',
            'ID' => isset($reference['ID']) ? absint($reference['ID']) : 0,
            'vf_object_uid' => isset($reference['vf_object_uid']) ? trim((string) $reference['vf_object_uid']) : '',
        ];

        return self::resolve_post_target($payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function sort_post_payload(array $payload): array
    {
        $order = [
            'ID',
            'vf_object_uid',
            'post_type',
            'post_title',
            'post_name',
            'post_status',
            'post_content',
            'post_excerpt',
            'post_date',
            'post_date_gmt',
            'post_parent',
            'menu_order',
            'post_author',
            'post_password',
            'comment_status',
            'ping_status',
            'meta',
            'tax_input',
        ];

        return self::sort_payload_by_order($payload, $order);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function sort_term_payload(array $payload): array
    {
        $order = [
            'term_id',
            'vf_object_uid',
            'taxonomy',
            'name',
            'slug',
            'description',
            'parent',
            'parent_slug',
            'meta',
        ];

        return self::sort_payload_by_order($payload, $order);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string>   $order
     * @return array<string,mixed>
     */
    private static function sort_payload_by_order(array $payload, array $order): array
    {
        $sorted = [];
        foreach ($order as $field_name) {
            if (array_key_exists($field_name, $payload)) {
                $sorted[$field_name] = $payload[$field_name];
                unset($payload[$field_name]);
            }
        }

        foreach ($payload as $field_name => $value) {
            $sorted[$field_name] = $value;
        }

        return $sorted;
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return bool
     */
    private static function has_error_issue(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            if (($issue['severity'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $severity
     * @param string $code
     * @param string $message
     * @param string $path
     * @return array<string,mixed>
     */
    private static function build_issue(string $severity, string $code, string $message, string $path = ''): array
    {
        return IssueService::build($severity, $code, $message, $path, [
            'stage' => 'translation',
        ]);
    }
}
