<?php

namespace Dbvc\EntityEditor;

if (! defined('WPINC')) {
    die;
}

/**
 * Preview and commit raw DBVC entity JSON inside the Entity Editor.
 */
final class RawJsonIntakeService
{
    private const BACKUP_SUBDIR = '.dbvc_entity_editor_backups';
    private const MODE_CREATE_ONLY = 'create_only';
    private const MODE_CREATE_OR_UPDATE = 'create_or_update_matched';
    private const MODE_STAGE_ONLY = 'stage_only';

    /**
     * Inspect a raw JSON payload and return preview data for the requested mode.
     *
     * @param string $content
     * @param string $mode
     * @return array<string,mixed>|\WP_Error
     */
    public static function preview($content, $mode = self::MODE_CREATE_ONLY)
    {
        $mode = self::normalize_mode($mode);
        $payload = self::decode_payload($content);
        if (\is_wp_error($payload)) {
            return $payload;
        }

        return self::build_preview($payload, $mode);
    }

    /**
     * Write a raw JSON payload into sync and optionally import it.
     *
     * @param string $content
     * @param string $mode
     * @param int    $user_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function commit($content, $mode = self::MODE_CREATE_ONLY, $user_id = 0)
    {
        $mode = self::normalize_mode($mode);
        $payload = self::decode_payload($content);
        if (\is_wp_error($payload)) {
            return $payload;
        }

        $entity_kind = self::detect_kind($payload);
        if ($entity_kind !== '') {
            $payload = self::normalize_payload($payload, $entity_kind);
        }

        $preview = self::build_preview($payload, $mode);
        if (\is_wp_error($preview)) {
            return $preview;
        }

        $blocking = isset($preview['blocking']) && \is_array($preview['blocking']) ? $preview['blocking'] : [];
        if (! empty($blocking)) {
            $message = isset($blocking[0]['message']) ? (string) $blocking[0]['message'] : __('This raw JSON payload is blocked for the selected action.', 'dbvc');

            return new \WP_Error(
                'dbvc_entity_editor_raw_intake_blocked',
                $message,
                [
                    'status'  => 409,
                    'preview' => $preview,
                ]
            );
        }

        $relative_path = isset($preview['target_relative_path']) ? (string) $preview['target_relative_path'] : '';
        $entity_kind = isset($preview['entity_kind']) ? (string) $preview['entity_kind'] : '';
        $subtype = isset($preview['subtype']) ? (string) $preview['subtype'] : '';
        $match = isset($preview['match']) && \is_array($preview['match']) ? $preview['match'] : ['status' => 'none'];

        $written = self::write_payload_to_sync($relative_path, $payload, $entity_kind);
        if (\is_wp_error($written)) {
            return $written;
        }

        $action = self::derive_detected_action($mode, $match);
        $created = false;
        $imported = false;
        $import_result = null;
        $wp_entity = null;

        if ($mode === self::MODE_STAGE_ONLY) {
            $action = 'stage';
        } elseif ($entity_kind === 'post') {
            $result = \DBVC_Sync_Posts::import_post_from_json(
                $written['absolute_path'],
                false,
                null,
                null,
                null,
                null,
                false,
                []
            );

            if (\is_wp_error($result)) {
                return $result;
            }

            if ($result !== 'applied') {
                return new \WP_Error(
                    'dbvc_entity_editor_raw_intake_post_skipped',
                    __('The post JSON was written to sync, but DBVC skipped the import.', 'dbvc'),
                    [
                        'status'        => 409,
                        'relative_path' => $relative_path,
                        'result'        => $result,
                    ]
                );
            }

            $imported = true;
            $import_result = [
                'status' => 'applied',
            ];

            $final_payload = self::read_json_file($written['absolute_path']);
            $resolved_match = \is_array($final_payload) ? self::inspect_live_match($final_payload) : $match;
            if (\is_wp_error($resolved_match)) {
                return $resolved_match;
            }

            if (isset($resolved_match['status']) && $resolved_match['status'] === 'matched') {
                $wp_entity = self::format_wp_entity(
                    'post',
                    $subtype,
                    isset($resolved_match['id']) ? (int) $resolved_match['id'] : 0,
                    isset($resolved_match['match_source']) ? (string) $resolved_match['match_source'] : ''
                );
            }

            $created = isset($match['status']) && $match['status'] !== 'matched';
        } else {
            $result = \DBVC_Sync_Taxonomies::import_term_json_file($written['absolute_path'], $subtype);
            if (\is_wp_error($result)) {
                return $result;
            }

            $imported = true;
            $import_result = $result;
            $created = ! empty($result['created']);
            $wp_entity = self::format_wp_entity(
                'term',
                $subtype,
                isset($result['term_id']) ? (int) $result['term_id'] : 0,
                'import'
            );
        }

        if (\class_exists('DBVC_Sync_Logger')) {
            \DBVC_Sync_Logger::log('Entity Editor raw JSON intake', [
                'user_id'       => (int) $user_id,
                'mode'          => $mode,
                'action'        => $action,
                'entity_kind'   => $entity_kind,
                'subtype'       => $subtype,
                'relative_path' => $relative_path,
                'created'       => $created,
                'imported'      => $imported,
                'backup_path'   => $written['backup_path'],
            ]);
        }

        return [
            'action'         => $action,
            'mode'           => $mode,
            'entity_kind'    => $entity_kind,
            'subtype'        => $subtype,
            'relative_path'  => $relative_path,
            'backup_path'    => $written['backup_path'],
            'created'        => $created,
            'imported'       => $imported,
            'matched'        => $match,
            'wp_entity'      => $wp_entity,
            'import_result'  => $import_result,
            'warnings'       => isset($preview['warnings']) && \is_array($preview['warnings']) ? $preview['warnings'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $mode
     * @return array<string,mixed>|\WP_Error
     */
    private static function build_preview(array $payload, string $mode)
    {
        $entity_kind = self::detect_kind($payload);
        if ($entity_kind === '') {
            return new \WP_Error(
                'dbvc_entity_editor_raw_intake_unknown_kind',
                __('Unable to detect whether this JSON is a post/CPT or taxonomy term payload.', 'dbvc'),
                ['status' => 422]
            );
        }

        $payload = self::normalize_payload($payload, $entity_kind);
        $subtype = $entity_kind === 'term'
            ? sanitize_key((string) ($payload['taxonomy'] ?? ''))
            : sanitize_key((string) ($payload['post_type'] ?? ''));
        $slug = $entity_kind === 'term'
            ? sanitize_title((string) ($payload['slug'] ?? ''))
            : sanitize_title((string) ($payload['post_name'] ?? ''));
        $title = $entity_kind === 'term'
            ? (string) ($payload['name'] ?? $payload['term_name'] ?? $slug)
            : (string) ($payload['post_title'] ?? '');
        $uid = self::extract_entity_uid($payload);

        $warnings = [];
        $reasons_by_mode = [
            self::MODE_CREATE_ONLY      => [],
            self::MODE_CREATE_OR_UPDATE => [],
            self::MODE_STAGE_ONLY       => [],
        ];

        if ($entity_kind === 'post') {
            if ($subtype === '') {
                self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE, self::MODE_STAGE_ONLY], 'missing_post_type', __('Post JSON is missing a valid post_type.', 'dbvc'));
            } elseif (! post_type_exists($subtype)) {
                self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE, self::MODE_STAGE_ONLY], 'missing_post_type', sprintf(__('The post type "%s" is not registered on this site.', 'dbvc'), $subtype));
            }

            if ($title === '') {
                self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE, self::MODE_STAGE_ONLY], 'missing_post_title', __('Post JSON must include post_title.', 'dbvc'));
            }

            if ($slug === '' && empty($payload['ID'])) {
                self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE, self::MODE_STAGE_ONLY], 'missing_post_identifier', __('Post JSON must include post_name or ID so DBVC can build a safe sync filename.', 'dbvc'));
            }
        } else {
            if ($subtype === '') {
                self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE, self::MODE_STAGE_ONLY], 'missing_taxonomy', __('Term JSON is missing a valid taxonomy.', 'dbvc'));
            } elseif (! taxonomy_exists($subtype)) {
                self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE, self::MODE_STAGE_ONLY], 'missing_taxonomy', sprintf(__('The taxonomy "%s" is not registered on this site.', 'dbvc'), $subtype));
            }

            if ($slug === '') {
                self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE, self::MODE_STAGE_ONLY], 'missing_term_slug', __('Term JSON must include slug so DBVC can match and stage the entity safely.', 'dbvc'));
            }
        }

        $target_relative_path = '';
        $target_absolute_path = '';
        if (empty($reasons_by_mode[self::MODE_CREATE_ONLY]) && empty($reasons_by_mode[self::MODE_CREATE_OR_UPDATE]) && empty($reasons_by_mode[self::MODE_STAGE_ONLY])) {
            $target_relative_path = self::build_target_relative_path($payload, $entity_kind, $subtype);
            if (\is_wp_error($target_relative_path)) {
                return $target_relative_path;
            }
            $target_absolute_path = self::build_absolute_path((string) $target_relative_path);
        }

        $match = ['status' => 'none'];
        if ($target_relative_path !== '') {
            $match = self::inspect_live_match($payload);
            if (\is_wp_error($match)) {
                self::add_reason(
                    $reasons_by_mode,
                    [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE, self::MODE_STAGE_ONLY],
                    (string) $match->get_error_code(),
                    (string) $match->get_error_message()
                );
                $match = [
                    'status' => 'ambiguous',
                ];
            }
        }

        $file_collision = self::inspect_file_collision((string) $target_relative_path, $payload, $entity_kind, $subtype, $slug, $uid, $match);

        if ($entity_kind === 'post' && isset($match['status']) && $match['status'] !== 'matched' && ! self::can_create_post($subtype)) {
            $warnings[] = [
                'code'    => 'post_creation_disabled',
                'message' => self::build_post_creation_message($subtype),
            ];
            self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY, self::MODE_CREATE_OR_UPDATE], 'post_creation_disabled', self::build_post_creation_message($subtype));
        }

        if (isset($match['status']) && $match['status'] === 'matched') {
            $warnings[] = [
                'code'    => 'matched_entity',
                'message' => __('This payload already matches a live local entity.', 'dbvc'),
            ];
            self::add_reason($reasons_by_mode, [self::MODE_CREATE_ONLY], 'matched_entity', __('Create only is unavailable because this payload already matches a live local entity.', 'dbvc'));
        }

        if (! empty($file_collision['exists'])) {
            $warnings[] = [
                'code'    => 'file_collision',
                'message' => __('The canonical sync path for this payload already exists.', 'dbvc'),
            ];

            self::add_reason(
                $reasons_by_mode,
                [self::MODE_CREATE_ONLY, self::MODE_STAGE_ONLY],
                'file_collision',
                __('The canonical sync file already exists. Choose Create or Update Matched only when the file belongs to the same live entity.', 'dbvc')
            );

            if (
                ! empty($file_collision['compatible_with_match']) &&
                isset($match['status']) &&
                $match['status'] === 'matched'
            ) {
                // Allowed for update mode.
            } else {
                self::add_reason(
                    $reasons_by_mode,
                    [self::MODE_CREATE_OR_UPDATE],
                    'file_collision',
                    __('The canonical sync file already exists and could not be safely associated with the matched live entity.', 'dbvc')
                );
            }
        }

        $available_actions = [
            self::MODE_CREATE_ONLY      => empty($reasons_by_mode[self::MODE_CREATE_ONLY]),
            self::MODE_CREATE_OR_UPDATE => empty($reasons_by_mode[self::MODE_CREATE_OR_UPDATE]),
            self::MODE_STAGE_ONLY       => empty($reasons_by_mode[self::MODE_STAGE_ONLY]),
        ];

        return [
            'mode'                => $mode,
            'entity_kind'         => $entity_kind,
            'subtype'             => $subtype,
            'title'               => $title,
            'slug'                => $slug,
            'uid'                 => $uid,
            'target_relative_path'=> (string) $target_relative_path,
            'target_absolute_path'=> (string) $target_absolute_path,
            'match'               => $match,
            'file_collision'      => $file_collision,
            'available_actions'   => $available_actions,
            'warnings'            => $warnings,
            'blocking'            => $reasons_by_mode[$mode],
            'detected_action'     => $available_actions[$mode] ? self::derive_detected_action($mode, $match) : 'blocked',
        ];
    }

    /**
     * @param string $mode
     * @return string
     */
    private static function normalize_mode($mode)
    {
        $mode = sanitize_key((string) $mode);
        $allowed = [
            self::MODE_CREATE_ONLY,
            self::MODE_CREATE_OR_UPDATE,
            self::MODE_STAGE_ONLY,
        ];

        return \in_array($mode, $allowed, true) ? $mode : self::MODE_CREATE_ONLY;
    }

    /**
     * @param string $content
     * @return array<string,mixed>|\WP_Error
     */
    private static function decode_payload($content)
    {
        $content = \is_string($content) ? trim($content) : '';
        if ($content === '') {
            return new \WP_Error('dbvc_entity_editor_raw_intake_empty', __('Paste a DBVC entity JSON payload before previewing or importing.', 'dbvc'), ['status' => 400]);
        }

        $decoded = json_decode($content, true);
        if (! \is_array($decoded)) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_invalid_json', __('Raw intake JSON is invalid.', 'dbvc'), ['status' => 422]);
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $entity_kind
     * @return array<string,mixed>
     */
    private static function normalize_payload(array $payload, string $entity_kind): array
    {
        if ($entity_kind === 'post') {
            if (! array_key_exists('ID', $payload)) {
                $payload['ID'] = 0;
            }
            if (! array_key_exists('post_content', $payload)) {
                $payload['post_content'] = '';
            }
            if (! array_key_exists('post_excerpt', $payload)) {
                $payload['post_excerpt'] = '';
            }
            if (! array_key_exists('post_status', $payload)) {
                $payload['post_status'] = 'draft';
            }
            if (
                ! array_key_exists('post_name', $payload) &&
                array_key_exists('post_title', $payload) &&
                trim((string) $payload['post_title']) !== ''
            ) {
                $payload['post_name'] = sanitize_title((string) $payload['post_title']);
            }
            if (! isset($payload['meta']) || ! is_array($payload['meta'])) {
                $payload['meta'] = [];
            }
            if (! isset($payload['tax_input']) || ! is_array($payload['tax_input'])) {
                $payload['tax_input'] = [];
            }
        } else {
            if (! isset($payload['meta']) || ! is_array($payload['meta'])) {
                $payload['meta'] = [];
            }
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return string
     */
    private static function detect_kind(array $payload)
    {
        if (isset($payload['taxonomy']) && (isset($payload['slug']) || isset($payload['term_id']))) {
            return 'term';
        }

        if (isset($payload['post_type']) && (isset($payload['ID']) || isset($payload['post_name']) || isset($payload['post_title']))) {
            return 'post';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return string
     */
    private static function extract_entity_uid(array $payload)
    {
        $uid = isset($payload['vf_object_uid']) ? (string) $payload['vf_object_uid'] : '';
        if ($uid === '' && isset($payload['dbvc_object_uid'])) {
            $uid = (string) $payload['dbvc_object_uid'];
        }
        if ($uid === '' && isset($payload['meta']) && \is_array($payload['meta'])) {
            if (isset($payload['meta']['dbvc_post_history']) && \is_array($payload['meta']['dbvc_post_history'])) {
                $uid = isset($payload['meta']['dbvc_post_history']['vf_object_uid']) ? (string) $payload['meta']['dbvc_post_history']['vf_object_uid'] : '';
            }
            if ($uid === '' && isset($payload['meta']['dbvc_term_history']) && \is_array($payload['meta']['dbvc_term_history'])) {
                $history = $payload['meta']['dbvc_term_history'];
                if (isset($history[0]) && \is_array($history[0]) && isset($history[0]['vf_object_uid'])) {
                    $uid = (string) $history[0]['vf_object_uid'];
                }
            }
        }

        return trim($uid);
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $entity_kind
     * @param string              $subtype
     * @return string|\WP_Error
     */
    private static function build_target_relative_path(array $payload, string $entity_kind, string $subtype)
    {
        if ($entity_kind === 'post') {
            if ($subtype === '') {
                return new \WP_Error('dbvc_entity_editor_raw_intake_missing_post_type', __('Unable to determine a sync path for this post payload.', 'dbvc'), ['status' => 422]);
            }

            return $subtype . '/' . \DBVC_Import_Router::determine_post_filename($payload);
        }

        if ($subtype === '') {
            return new \WP_Error('dbvc_entity_editor_raw_intake_missing_taxonomy', __('Unable to determine a sync path for this term payload.', 'dbvc'), ['status' => 422]);
        }

        return 'taxonomy/' . $subtype . '/' . \DBVC_Import_Router::determine_term_filename($payload, $subtype);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|\WP_Error
     */
    private static function inspect_live_match(array $payload)
    {
        $kind = self::detect_kind($payload);
        $subtype = $kind === 'term'
            ? sanitize_key((string) ($payload['taxonomy'] ?? ''))
            : sanitize_key((string) ($payload['post_type'] ?? ''));
        $slug = $kind === 'term'
            ? sanitize_title((string) ($payload['slug'] ?? ''))
            : sanitize_title((string) ($payload['post_name'] ?? ''));
        $uid = self::extract_entity_uid($payload);

        $sources = [];
        if ($kind === 'post') {
            if ($uid !== '') {
                $sources[] = ['source' => 'uid', 'ids' => self::find_post_ids_by_uid($uid, $subtype)];
            }
            if ($slug !== '' && $subtype !== '') {
                $sources[] = ['source' => 'slug', 'ids' => self::find_post_ids_by_slug($slug, $subtype)];
            }
        } elseif ($kind === 'term') {
            if ($uid !== '') {
                $sources[] = ['source' => 'uid', 'ids' => self::find_term_ids_by_uid($uid, $subtype)];
            }
            if ($slug !== '' && $subtype !== '') {
                $sources[] = ['source' => 'slug', 'ids' => self::find_term_ids_by_slug($slug, $subtype)];
            }
        }

        $all_ids = [];
        $best_source = '';
        foreach ($sources as $source) {
            $ids = isset($source['ids']) && \is_array($source['ids']) ? array_values(array_unique(array_map('intval', $source['ids']))) : [];
            if (empty($ids)) {
                continue;
            }

            if (count($ids) > 1) {
                return new \WP_Error(
                    'dbvc_entity_editor_ambiguous_match',
                    __('Multiple matching entities were found; refine identifiers before importing.', 'dbvc'),
                    ['status' => 409, 'candidates' => $ids]
                );
            }

            if ($best_source === '') {
                $best_source = (string) ($source['source'] ?? '');
            }

            $all_ids = array_values(array_unique(array_merge($all_ids, $ids)));
        }

        if (empty($all_ids)) {
            return [
                'status' => 'none',
            ];
        }

        if (count($all_ids) > 1) {
            return new \WP_Error(
                'dbvc_entity_editor_ambiguous_match',
                __('Matched more than one local entity; raw intake was blocked.', 'dbvc'),
                ['status' => 409, 'candidates' => $all_ids]
            );
        }

        return self::format_wp_entity($kind, $subtype, (int) $all_ids[0], $best_source ?: 'unknown');
    }

    /**
     * @param string              $relative_path
     * @param array<string,mixed> $payload
     * @param string              $entity_kind
     * @param string              $subtype
     * @param string              $slug
     * @param string              $uid
     * @param array<string,mixed> $match
     * @return array<string,mixed>
     */
    private static function inspect_file_collision($relative_path, array $payload, $entity_kind, $subtype, $slug, $uid, array $match)
    {
        $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        if ($relative_path === '') {
            return [
                'exists' => false,
            ];
        }

        $absolute_path = self::build_absolute_path($relative_path);
        if (! is_file($absolute_path)) {
            return [
                'exists' => false,
            ];
        }

        $decoded = self::read_json_file($absolute_path);
        if (! is_array($decoded)) {
            return [
                'exists'              => true,
                'relative_path'       => $relative_path,
                'absolute_path'       => $absolute_path,
                'valid_json'          => false,
                'compatible_with_match' => false,
            ];
        }

        $existing_kind = self::detect_kind($decoded);
        $existing_subtype = $existing_kind === 'term'
            ? sanitize_key((string) ($decoded['taxonomy'] ?? ''))
            : sanitize_key((string) ($decoded['post_type'] ?? ''));
        $existing_slug = $existing_kind === 'term'
            ? sanitize_title((string) ($decoded['slug'] ?? ''))
            : sanitize_title((string) ($decoded['post_name'] ?? ''));
        $existing_uid = self::extract_entity_uid($decoded);
        $existing_payload_id = $existing_kind === 'term'
            ? (int) ($decoded['term_id'] ?? 0)
            : (int) ($decoded['ID'] ?? 0);

        $match_id = isset($match['status']) && $match['status'] === 'matched' ? (int) ($match['id'] ?? 0) : 0;
        $compatible_with_match = false;
        if ($match_id > 0) {
            if ($uid !== '' && $existing_uid !== '' && $uid === $existing_uid) {
                $compatible_with_match = true;
            } elseif ($existing_payload_id > 0 && $existing_payload_id === $match_id) {
                $compatible_with_match = true;
            } elseif ($slug !== '' && $existing_slug !== '' && $slug === $existing_slug && $entity_kind === $existing_kind && $subtype === $existing_subtype) {
                $compatible_with_match = true;
            }
        }

        return [
            'exists'                => true,
            'relative_path'         => $relative_path,
            'absolute_path'         => $absolute_path,
            'valid_json'            => true,
            'entity_kind'           => $existing_kind,
            'subtype'               => $existing_subtype,
            'title'                 => $existing_kind === 'term'
                ? (string) ($decoded['name'] ?? $existing_slug)
                : (string) ($decoded['post_title'] ?? ''),
            'slug'                  => $existing_slug,
            'uid'                   => $existing_uid,
            'payload_entity_id'     => $existing_payload_id,
            'compatible_with_match' => $compatible_with_match,
        ];
    }

    /**
     * @param string              $relative_path
     * @param array<string,mixed> $payload
     * @param string              $entity_kind
     * @return array<string,mixed>|\WP_Error
     */
    private static function write_payload_to_sync($relative_path, array $payload, $entity_kind)
    {
        $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        if ($relative_path === '' || strpos($relative_path, '..') !== false || substr($relative_path, -5) !== '.json') {
            return new \WP_Error('dbvc_entity_editor_raw_intake_invalid_path', __('Unable to determine a safe sync path for this raw JSON payload.', 'dbvc'), ['status' => 400]);
        }

        $sync_root = \dbvc_get_sync_path();
        if (! is_dir($sync_root) && ! \wp_mkdir_p($sync_root)) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_sync_missing', __('The DBVC sync folder is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $sync_real = realpath($sync_root);
        if (! $sync_real || ! is_dir($sync_real)) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_sync_missing', __('The DBVC sync folder is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $absolute_path = trailingslashit($sync_real) . $relative_path;
        $directory = dirname($absolute_path);
        $directory_type = $entity_kind === 'term' ? 'taxonomy' : 'post';
        if (! \DBVC_Import_Router::ensure_directory($directory, $directory_type)) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_directory_failed', __('Unable to prepare the destination sync directory.', 'dbvc'), ['status' => 500]);
        }

        if (! \dbvc_is_safe_file_path($absolute_path)) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_invalid_path', __('The resolved sync path is not safe for writing.', 'dbvc'), ['status' => 400]);
        }

        $normalized = \function_exists('dbvc_normalize_for_json') ? \dbvc_normalize_for_json($payload) : $payload;
        $encoded = wp_json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! \is_string($encoded) || $encoded === '') {
            return new \WP_Error('dbvc_entity_editor_raw_intake_encode_failed', __('Unable to encode the raw JSON payload for sync.', 'dbvc'), ['status' => 500]);
        }

        $backup_path = '';
        if (is_file($absolute_path)) {
            $backup = self::create_backup_copy($sync_real, $absolute_path, $relative_path);
            if (\is_wp_error($backup)) {
                return $backup;
            }
            $backup_path = (string) $backup;
        }

        $tmp_path = $absolute_path . '.tmp-' . wp_generate_password(8, false, false);
        $bytes = file_put_contents($tmp_path, $encoded . "\n");
        if (! \is_int($bytes) || $bytes <= 0) {
            @unlink($tmp_path);
            return new \WP_Error('dbvc_entity_editor_raw_intake_write_failed', __('Unable to write the raw JSON payload into sync.', 'dbvc'), ['status' => 500]);
        }

        if (! @rename($tmp_path, $absolute_path)) {
            @unlink($tmp_path);
            return new \WP_Error('dbvc_entity_editor_raw_intake_replace_failed', __('Unable to replace the target sync JSON file atomically.', 'dbvc'), ['status' => 500]);
        }

        return [
            'relative_path' => $relative_path,
            'absolute_path' => $absolute_path,
            'backup_path'   => $backup_path,
        ];
    }

    /**
     * @param string $sync_real
     * @param string $absolute_path
     * @param string $relative_path
     * @return string|\WP_Error
     */
    private static function create_backup_copy($sync_real, $absolute_path, $relative_path)
    {
        $backup_dir = trailingslashit($sync_real) . self::BACKUP_SUBDIR;
        if (! is_dir($backup_dir) && ! \wp_mkdir_p($backup_dir)) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_backup_dir_failed', __('Unable to create the Entity Editor backup directory.', 'dbvc'), ['status' => 500]);
        }

        $safe_name = str_replace(['/', '\\'], '__', ltrim((string) $relative_path, '/'));
        $backup_name = $safe_name . '.' . gmdate('Ymd-His') . '.bak.json';
        $backup_path = trailingslashit($backup_dir) . $backup_name;

        if (! @copy($absolute_path, $backup_path)) {
            return new \WP_Error('dbvc_entity_editor_raw_intake_backup_failed', __('Unable to create a backup before replacing the sync JSON file.', 'dbvc'), ['status' => 500]);
        }

        return self::BACKUP_SUBDIR . '/' . $backup_name;
    }

    /**
     * @param string $mode
     * @param array<string,mixed> $match
     * @return string
     */
    private static function derive_detected_action($mode, array $match)
    {
        if ($mode === self::MODE_STAGE_ONLY) {
            return 'stage';
        }

        if (isset($match['status']) && $match['status'] === 'matched') {
            return 'update_matched';
        }

        return 'create';
    }

    /**
     * @param array<string,array<int,array<string,string>>> $reasons_by_mode
     * @param array<int,string>                             $modes
     * @param string                                        $code
     * @param string                                        $message
     * @return void
     */
    private static function add_reason(array &$reasons_by_mode, array $modes, $code, $message)
    {
        foreach ($modes as $mode) {
            if (! isset($reasons_by_mode[$mode])) {
                continue;
            }

            foreach ($reasons_by_mode[$mode] as $existing) {
                if (isset($existing['code']) && $existing['code'] === $code) {
                    continue 2;
                }
            }

            $reasons_by_mode[$mode][] = [
                'code'    => (string) $code,
                'message' => (string) $message,
            ];
        }
    }

    /**
     * @param string $post_type
     * @return bool
     */
    private static function can_create_post($post_type)
    {
        if (get_option('dbvc_allow_new_posts') !== '1') {
            return false;
        }

        $whitelist = (array) get_option('dbvc_new_post_types_whitelist', []);
        if (! empty($whitelist) && ! in_array($post_type, $whitelist, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $post_type
     * @return string
     */
    private static function build_post_creation_message($post_type)
    {
        $whitelist = (array) get_option('dbvc_new_post_types_whitelist', []);
        if (! empty($whitelist) && ! in_array($post_type, $whitelist, true)) {
            return sprintf(__('DBVC post creation is restricted and "%s" is not in the new-post whitelist.', 'dbvc'), $post_type);
        }

        return __('DBVC is currently configured to block creation of new posts from imports.', 'dbvc');
    }

    /**
     * @param string $relative_path
     * @return string
     */
    private static function build_absolute_path($relative_path)
    {
        return trailingslashit(\dbvc_get_sync_path()) . ltrim(str_replace('\\', '/', (string) $relative_path), '/');
    }

    /**
     * @param int    $entity_id
     * @param string $post_type
     * @param string $match_source
     * @return array<string,mixed>
     */
    private static function format_post_entity($entity_id, $post_type, $match_source)
    {
        $entity_id = (int) $entity_id;
        $post = $entity_id > 0 ? get_post($entity_id) : null;

        return [
            'status'       => $entity_id > 0 ? 'matched' : 'none',
            'id'           => $entity_id,
            'kind'         => 'post',
            'subtype'      => (string) $post_type,
            'label'        => $post instanceof \WP_Post ? (string) $post->post_title : sprintf(__('Post #%d', 'dbvc'), $entity_id),
            'edit_url'     => $entity_id > 0 ? (string) get_edit_post_link($entity_id, '') : '',
            'match_source' => (string) $match_source,
        ];
    }

    /**
     * @param int    $entity_id
     * @param string $taxonomy
     * @param string $match_source
     * @return array<string,mixed>
     */
    private static function format_term_entity($entity_id, $taxonomy, $match_source)
    {
        $entity_id = (int) $entity_id;
        $term = $entity_id > 0 ? get_term($entity_id, $taxonomy) : null;

        return [
            'status'       => $entity_id > 0 ? 'matched' : 'none',
            'id'           => $entity_id,
            'kind'         => 'term',
            'subtype'      => (string) $taxonomy,
            'label'        => $term && ! \is_wp_error($term) ? (string) $term->name : sprintf(__('Term #%d', 'dbvc'), $entity_id),
            'edit_url'     => $entity_id > 0 && \function_exists('get_edit_term_link') ? (string) get_edit_term_link($entity_id, $taxonomy) : '',
            'match_source' => (string) $match_source,
        ];
    }

    /**
     * @param string $kind
     * @param string $subtype
     * @param int    $entity_id
     * @param string $match_source
     * @return array<string,mixed>
     */
    private static function format_wp_entity($kind, $subtype, $entity_id, $match_source)
    {
        if ($kind === 'term') {
            return self::format_term_entity($entity_id, $subtype, $match_source);
        }

        return self::format_post_entity($entity_id, $subtype, $match_source);
    }

    /**
     * @param string $uid
     * @param string $post_type
     * @return array<int,int>
     */
    private static function find_post_ids_by_uid($uid, $post_type)
    {
        $ids = [];
        $uid = trim((string) $uid);
        if ($uid === '') {
            return $ids;
        }

        if (\class_exists('DBVC_Database')) {
            $record = \DBVC_Database::get_entity_by_uid($uid);
            if (\is_object($record) && ! empty($record->object_id)) {
                $ids[] = (int) $record->object_id;
            }
        }

        $query = get_posts([
            'post_type'        => $post_type ? [$post_type] : 'any',
            'post_status'      => 'any',
            'meta_key'         => 'vf_object_uid',
            'meta_value'       => $uid,
            'posts_per_page'   => 20,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        if (\is_array($query)) {
            foreach ($query as $post_id) {
                $ids[] = (int) $post_id;
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @param string $slug
     * @param string $post_type
     * @return array<int,int>
     */
    private static function find_post_ids_by_slug($slug, $post_type)
    {
        $slug = sanitize_title((string) $slug);
        $post_type = sanitize_key((string) $post_type);
        if ($slug === '' || $post_type === '') {
            return [];
        }

        $ids = get_posts([
            'name'             => $slug,
            'post_type'        => [$post_type],
            'post_status'      => 'any',
            'posts_per_page'   => 20,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        return \is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    /**
     * @param string $uid
     * @param string $taxonomy
     * @return array<int,int>
     */
    private static function find_term_ids_by_uid($uid, $taxonomy)
    {
        $ids = [];
        $uid = trim((string) $uid);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($uid === '' || $taxonomy === '') {
            return $ids;
        }

        if (\class_exists('DBVC_Database')) {
            $record = \DBVC_Database::get_entity_by_uid($uid);
            if (
                \is_object($record) &&
                ! empty($record->object_id) &&
                isset($record->object_type) &&
                (string) $record->object_type === 'term:' . $taxonomy
            ) {
                $ids[] = (int) $record->object_id;
            }
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 20,
            'meta_query' => [
                [
                    'key'   => 'vf_object_uid',
                    'value' => $uid,
                ],
            ],
            'fields'     => 'ids',
        ]);

        if (\is_array($terms)) {
            foreach ($terms as $term_id) {
                $ids[] = (int) $term_id;
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @param string $slug
     * @param string $taxonomy
     * @return array<int,int>
     */
    private static function find_term_ids_by_slug($slug, $taxonomy)
    {
        $slug = sanitize_title((string) $slug);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($slug === '' || $taxonomy === '') {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'slug'       => $slug,
            'number'     => 20,
            'fields'     => 'ids',
        ]);

        return \is_array($terms) ? array_values(array_unique(array_map('intval', $terms))) : [];
    }

    /**
     * @param string $absolute_path
     * @return array<string,mixed>|null
     */
    private static function read_json_file($absolute_path)
    {
        if (! is_file($absolute_path)) {
            return null;
        }

        $raw = file_get_contents($absolute_path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
