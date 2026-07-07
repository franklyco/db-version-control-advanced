<?php

namespace Dbvc\EntityEditor;

if (! defined('WPINC')) {
    die;
}

/**
 * Preview and apply provider-owned sync JSON files from Entity Editor.
 */
final class ThirdPartySyncFileImportService
{
    private const MODE_PREVIEW = 'preview';
    private const MODE_CREATE_FORM = 'create_form';
    private const MODE_UPDATE_MATCHED_FORM = 'update_matched_form';
    private const MODE_MERGE_SETTINGS = 'merge_settings';
    private const BATCH_LIMIT = 25;

    /**
     * @param array<int,string>|string $paths
     * @param string                   $mode
     * @return array<string,mixed>|\WP_Error
     */
    public static function preview($paths, $mode = self::MODE_PREVIEW)
    {
        $mode = self::normalize_mode($mode);
        $paths = self::normalize_paths($paths);
        if (\is_wp_error($paths)) {
            return $paths;
        }

        $items = [];
        foreach ($paths as $path) {
            $items[] = self::build_preview_item((string) $path);
        }

        return [
            'mode'    => $mode,
            'summary' => self::build_preview_summary($items),
            'items'   => $items,
        ];
    }

    /**
     * @param array<int,string>|string $paths
     * @param string                   $mode
     * @param int                      $user_id
     * @param array<string,mixed>      $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function commit($paths, $mode = self::MODE_CREATE_FORM, $user_id = 0, array $args = [])
    {
        $mode = self::normalize_mode($mode);
        if ($mode === self::MODE_PREVIEW) {
            $mode = self::MODE_CREATE_FORM;
        }

        $preview = self::preview($paths, $mode);
        if (\is_wp_error($preview)) {
            return $preview;
        }

        $confirmations = isset($args['confirmations']) && \is_array($args['confirmations'])
            ? $args['confirmations']
            : [];
        $items = isset($preview['items']) && \is_array($preview['items']) ? $preview['items'] : [];
        $result_items = [];

        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            if ($mode === self::MODE_UPDATE_MATCHED_FORM) {
                $relative_path = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
                $confirmation = self::find_confirmation($confirmations, $relative_path);
                $result_items[] = self::commit_wsform_update_item($item, $confirmation, (int) $user_id);
                continue;
            }

            if ($mode === self::MODE_MERGE_SETTINGS) {
                $result_items[] = self::commit_wsform_settings_item($item, (int) $user_id);
                continue;
            }

            $result_items[] = self::commit_wsform_create_item($item, (int) $user_id);
        }

        delete_transient('dbvc_entity_editor_index_v1');

        return [
            'mode'    => $mode,
            'summary' => self::build_commit_summary($result_items),
            'items'   => $result_items,
        ];
    }

    /**
     * @param string $relative_path
     * @return array<string,mixed>
     */
    private static function build_preview_item($relative_path)
    {
        $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        $payload = [];
        $index_item = null;
        $base = [
            'relative_path'        => $relative_path,
            'entity_kind'          => 'third_party',
            'provider'             => '',
            'object_type'          => '',
            'subtype'              => '',
            'title'                => '',
            'slug'                 => '',
            'uid'                  => '',
            'source_id'            => null,
            'source_status'        => '',
            'detected_action'      => 'blocked',
            'match'                => ['status' => 'none'],
            'warnings'             => [],
            'blocking'             => [],
            'blocker_details'      => [],
            'preview_hash'         => '',
            'available_actions'    => [
                self::MODE_CREATE_FORM => false,
                self::MODE_UPDATE_MATCHED_FORM => false,
                self::MODE_MERGE_SETTINGS => false,
            ],
        ];

        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            $base['blocking'][] = self::reason('indexer_unavailable', __('Entity Editor indexer is unavailable.', 'dbvc'));
            return self::finalize_preview_item($base, $payload, $index_item);
        }

        if (! \class_exists('DBVC_Third_Party_Portability')) {
            $base['blocking'][] = self::reason('third_party_portability_unavailable', __('Third-party portability support is unavailable.', 'dbvc'));
            return self::finalize_preview_item($base, $payload, $index_item);
        }

        $loaded = \DBVC_Entity_Editor_Indexer::load_entity_file_for_download($relative_path);
        if (\is_wp_error($loaded)) {
            $base['blocking'][] = self::reason((string) $loaded->get_error_code(), (string) $loaded->get_error_message());
            return self::finalize_preview_item($base, $payload, $index_item);
        }

        $payload = isset($loaded['decoded']) && \is_array($loaded['decoded']) ? $loaded['decoded'] : [];
        $base['mtime'] = isset($loaded['mtime']) ? (int) $loaded['mtime'] : 0;
        $index_item = self::find_index_item($relative_path);

        if (\is_array($index_item) && ! empty($index_item['is_duplicate']) && empty($index_item['is_canonical_duplicate'])) {
            $canonical_path = isset($index_item['duplicate_group']['canonical_relative_path'])
                ? (string) $index_item['duplicate_group']['canonical_relative_path']
                : '';
            $base['blocking'][] = self::reason(
                'stale_duplicate_file',
                $canonical_path !== ''
                    ? sprintf(__('This is an older duplicate provider sync file. Use the canonical row instead: %s', 'dbvc'), $canonical_path)
                    : __('This is an older duplicate provider sync file. Use the canonical row instead.', 'dbvc')
            );
        }

        if (\DBVC_Third_Party_Portability::is_wsform_form_payload($payload)) {
            return self::build_wsform_form_preview($base, $payload, $index_item);
        }

        if (\DBVC_Third_Party_Portability::is_wsform_settings_payload($payload)) {
            return self::build_wsform_settings_preview($base, $payload, $index_item);
        }

        $base['blocking'][] = self::reason('unsupported_third_party_payload', __('Entity Editor third-party import supports WS Form form and settings JSON only.', 'dbvc'));
        return self::finalize_preview_item($base, $payload, $index_item);
    }

    /**
     * @param array<string,mixed>      $base
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $index_item
     * @return array<string,mixed>
     */
    private static function build_wsform_form_preview(array $base, array $payload, $index_item)
    {
        $summary = \DBVC_Third_Party_Portability::summarize_wsform_form_payload($payload);
        $label = isset($summary['label']) && (string) $summary['label'] !== ''
            ? (string) $summary['label']
            : __('WS Form', 'dbvc');
        $uid = isset($summary['uid']) ? (string) $summary['uid'] : '';
        $source_id = isset($summary['source_id']) ? (int) $summary['source_id'] : 0;
        $counts = isset($summary['counts']) && \is_array($summary['counts']) ? $summary['counts'] : [];

        $base['provider'] = \DBVC_Third_Party_Portability::PROVIDER_WSFORM;
        $base['object_type'] = 'form';
        $base['subtype'] = 'ws_form_form';
        $base['title'] = $label;
        $base['slug'] = sanitize_title($label);
        $base['uid'] = $uid;
        $base['source_id'] = $source_id > 0 ? $source_id : null;
        $base['source_status'] = isset($summary['source_status']) ? (string) $summary['source_status'] : '';
        $base['counts'] = $counts;

        if (! \DBVC_Third_Party_Portability::is_wsform_available()) {
            $base['blocking'][] = self::reason('wsform_unavailable', __('WS Form is not available on this site.', 'dbvc'));
            return self::finalize_preview_item($base, $payload, $index_item);
        }

        if ($label === '') {
            $base['warnings'][] = self::reason('missing_wsform_label', __('This WS Form payload has no label.', 'dbvc'));
        }

        if ($uid === '') {
            $base['warnings'][] = self::reason('missing_wsform_uid', __('This WS Form payload has no portability UID. DBVC will stamp one into the JSON before creating the form.', 'dbvc'));
        }

        $match = $uid !== '' ? \DBVC_Third_Party_Portability::get_wsform_form_match_by_uid($uid) : null;
        if (\is_array($match)) {
            $match_status = isset($match['status']) ? (string) $match['status'] : '';
            $base['match'] = array_merge($match, [
                'status'          => 'matched',
                'provider_status' => $match_status,
            ]);
            $base['matched_provider_entity'] = $match;
            $base['blocking'][] = self::reason('matched_provider_entity', __('This WS Form JSON already matches a local WS Form by portability UID.', 'dbvc'));
            $base['matched_update'] = [
                'eligible'              => true,
                'requires_confirmation' => true,
                'match_source'          => 'dbvc_portability_uid',
                'wp_entity'             => $match,
                'scope_summary'         => [
                    'whole_form_replace' => true,
                    'groups'             => (int) ($counts['groups'] ?? 0),
                    'sections'           => (int) ($counts['sections'] ?? 0),
                    'fields'             => (int) ($counts['fields'] ?? 0),
                    'actions'            => (int) ($counts['actions'] ?? 0),
                ],
                'confirmation_label'    => __('I confirm replacing this matched WS Form from the selected JSON.', 'dbvc'),
            ];
            $base['available_actions'][self::MODE_UPDATE_MATCHED_FORM] = true;

            return self::finalize_preview_item($base, $payload, $index_item);
        }

        if (empty($base['blocking'])) {
            $base['detected_action'] = self::MODE_CREATE_FORM;
            $base['available_actions'][self::MODE_CREATE_FORM] = true;
        }

        return self::finalize_preview_item($base, $payload, $index_item);
    }

    /**
     * @param array<string,mixed>      $base
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $index_item
     * @return array<string,mixed>
     */
    private static function build_wsform_settings_preview(array $base, array $payload, $index_item)
    {
        $allowed = \DBVC_Third_Party_Portability::get_wsform_setting_option_names();
        $incoming = isset($payload['options']) && \is_array($payload['options']) ? array_keys($payload['options']) : [];
        $mergeable = array_values(array_intersect(array_map('strval', $incoming), $allowed));

        $base['provider'] = \DBVC_Third_Party_Portability::PROVIDER_WSFORM;
        $base['object_type'] = 'settings';
        $base['subtype'] = 'ws_form_settings';
        $base['title'] = __('WS Form Settings', 'dbvc');
        $base['slug'] = 'ws-form-settings';
        $base['settings_preview'] = [
            'allowed_options' => array_values($allowed),
            'incoming_options' => array_values(array_map('strval', $incoming)),
            'mergeable_options' => $mergeable,
            'excluded_keys' => isset($payload['excluded_keys']) && \is_array($payload['excluded_keys']) ? $payload['excluded_keys'] : [],
            'preserves_sensitive_keys' => true,
        ];

        if (empty($mergeable)) {
            $base['blocking'][] = self::reason('wsform_settings_no_mergeable_options', __('This WS Form settings payload has no supported option keys to merge.', 'dbvc'));
        } else {
            $base['detected_action'] = self::MODE_MERGE_SETTINGS;
            $base['available_actions'][self::MODE_MERGE_SETTINGS] = true;
            $base['warnings'][] = self::reason('wsform_settings_preserve_secrets', __('WS Form settings import merges supported non-sensitive options and preserves local secrets.', 'dbvc'));
        }

        return self::finalize_preview_item($base, $payload, $index_item);
    }

    /**
     * @param array<string,mixed> $item
     * @param int                 $user_id
     * @return array<string,mixed>
     */
    private static function commit_wsform_create_item(array $item, $user_id)
    {
        if (empty($item['available_actions'][self::MODE_CREATE_FORM]) || self::has_blocking($item)) {
            return self::merge_item_blocked($item, 'wsform_create_unavailable', __('This WS Form JSON is not eligible for create.', 'dbvc'));
        }

        $payload_result = self::read_payload_for_item($item);
        if (\is_wp_error($payload_result)) {
            return self::merge_item_error($item, $payload_result);
        }

        $absolute_path = $payload_result['absolute_path'];
        $payload = $payload_result['payload'];
        $uid = \DBVC_Third_Party_Portability::extract_wsform_form_uid($payload);
        if ($uid === '') {
            $uid = wp_generate_uuid4();
            $payload = self::stamp_wsform_uid($payload, $uid);
            if (! self::write_json_file($absolute_path, $payload)) {
                return self::merge_item_error(
                    $item,
                    new \WP_Error('dbvc_wsform_uid_write_failed', __('DBVC could not stamp a portability UID into the WS Form JSON before import.', 'dbvc'), ['status' => 500])
                );
            }
            $item['uid'] = $uid;
        }

        $result = \DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($payload);
        if (\is_wp_error($result)) {
            return self::merge_item_error($item, $result);
        }

        $result_item = array_merge($item, [
            'action'        => self::MODE_CREATE_FORM,
            'created'       => ! empty($result['created']),
            'updated'       => false,
            'imported'      => true,
            'status'        => 'created',
            'import_result' => $result,
            'wp_entity'     => isset($result['wp_entity']) && \is_array($result['wp_entity']) ? $result['wp_entity'] : null,
            'blocking'      => [],
        ]);

        self::log_commit('Entity Editor WS Form create', $result_item, (int) $user_id);
        return $result_item;
    }

    /**
     * @param array<string,mixed>      $item
     * @param array<string,mixed>|null $confirmation
     * @param int                      $user_id
     * @return array<string,mixed>
     */
    private static function commit_wsform_update_item(array $item, $confirmation, $user_id)
    {
        $matched_update = isset($item['matched_update']) && \is_array($item['matched_update']) ? $item['matched_update'] : [];
        $matched_entity = isset($matched_update['wp_entity']) && \is_array($matched_update['wp_entity']) ? $matched_update['wp_entity'] : [];
        $matched_id = isset($matched_entity['id']) ? (int) $matched_entity['id'] : 0;

        if (empty($matched_update['eligible']) || empty($item['available_actions'][self::MODE_UPDATE_MATCHED_FORM])) {
            return self::merge_item_blocked($item, 'wsform_update_unavailable', __('This WS Form JSON is not eligible for matched update.', 'dbvc'));
        }

        if (! \is_array($confirmation) || empty($confirmation['confirmed'])) {
            return self::merge_item_blocked($item, 'wsform_update_confirmation_required', __('Confirm the matched WS Form update before DBVC applies this JSON.', 'dbvc'));
        }

        $provided_hash = isset($confirmation['preview_hash']) ? (string) $confirmation['preview_hash'] : '';
        $current_hash = isset($item['preview_hash']) ? (string) $item['preview_hash'] : '';
        if ($provided_hash === '' || $current_hash === '' || ! hash_equals($current_hash, $provided_hash)) {
            return self::merge_item_blocked($item, 'wsform_update_stale_preview', __('The WS Form preview changed. Refresh the preview before updating.', 'dbvc'));
        }

        $confirmed_id = isset($confirmation['matched_entity_id'])
            ? (int) $confirmation['matched_entity_id']
            : (isset($confirmation['match_id']) ? (int) $confirmation['match_id'] : 0);
        if ($matched_id <= 0 || $confirmed_id <= 0 || $matched_id !== $confirmed_id) {
            return self::merge_item_blocked($item, 'wsform_update_match_drift', __('The matched WS Form changed. Refresh the preview before updating.', 'dbvc'));
        }

        $payload_result = self::read_payload_for_item($item);
        if (\is_wp_error($payload_result)) {
            return self::merge_item_error($item, $payload_result);
        }

        $payload = $payload_result['payload'];
        $uid = \DBVC_Third_Party_Portability::extract_wsform_form_uid($payload);
        $current_match = $uid !== '' ? \DBVC_Third_Party_Portability::get_wsform_form_match_by_uid($uid) : null;
        if (! \is_array($current_match) || (int) ($current_match['id'] ?? 0) !== $matched_id) {
            return self::merge_item_blocked($item, 'wsform_update_match_drift', __('The matched WS Form no longer matches this payload UID.', 'dbvc'));
        }

        $result = \DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($payload);
        if (\is_wp_error($result)) {
            return self::merge_item_error($item, $result);
        }

        $result_item = array_merge($item, [
            'action'        => self::MODE_UPDATE_MATCHED_FORM,
            'created'       => false,
            'updated'       => ! empty($result['updated']),
            'imported'      => true,
            'status'        => 'updated',
            'import_result' => $result,
            'wp_entity'     => isset($result['wp_entity']) && \is_array($result['wp_entity']) ? $result['wp_entity'] : null,
            'blocking'      => [],
        ]);

        self::log_commit('Entity Editor WS Form matched update', $result_item, (int) $user_id);
        return $result_item;
    }

    /**
     * @param array<string,mixed> $item
     * @param int                 $user_id
     * @return array<string,mixed>
     */
    private static function commit_wsform_settings_item(array $item, $user_id)
    {
        if (empty($item['available_actions'][self::MODE_MERGE_SETTINGS]) || self::has_blocking($item)) {
            return self::merge_item_blocked($item, 'wsform_settings_merge_unavailable', __('This WS Form settings JSON is not eligible for merge.', 'dbvc'));
        }

        $payload_result = self::read_payload_for_item($item);
        if (\is_wp_error($payload_result)) {
            return self::merge_item_error($item, $payload_result);
        }

        $result = \DBVC_Third_Party_Portability::import_wsform_settings_for_entity_editor($payload_result['payload']);
        if (\is_wp_error($result)) {
            return self::merge_item_error($item, $result);
        }

        $result_item = array_merge($item, [
            'action'        => self::MODE_MERGE_SETTINGS,
            'created'       => false,
            'updated'       => true,
            'imported'      => true,
            'status'        => 'updated',
            'import_result' => $result,
            'blocking'      => [],
        ]);

        self::log_commit('Entity Editor WS Form settings merge', $result_item, (int) $user_id);
        return $result_item;
    }

    /**
     * @param array<string,mixed> $item
     * @return array{absolute_path:string,payload:array<string,mixed>}|\WP_Error
     */
    private static function read_payload_for_item(array $item)
    {
        $relative_path = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
        $absolute_path = self::resolve_absolute_path($relative_path);
        if (\is_wp_error($absolute_path)) {
            return $absolute_path;
        }

        $payload = self::read_json_file((string) $absolute_path);
        if (! \is_array($payload)) {
            return new \WP_Error('dbvc_third_party_import_invalid_json', __('The third-party sync JSON could not be read before import.', 'dbvc'), ['status' => 422]);
        }

        return [
            'absolute_path' => (string) $absolute_path,
            'payload'       => $payload,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $uid
     * @return array<string,mixed>
     */
    private static function stamp_wsform_uid(array $payload, $uid)
    {
        if (! isset($payload['dbvc_portability']) || ! \is_array($payload['dbvc_portability'])) {
            $payload['dbvc_portability'] = [];
        }
        $payload['dbvc_portability']['schema'] = isset($payload['dbvc_portability']['schema']) ? $payload['dbvc_portability']['schema'] : 1;
        $payload['dbvc_portability']['provider'] = \DBVC_Third_Party_Portability::PROVIDER_WSFORM;
        $payload['dbvc_portability']['object_type'] = 'form';
        $payload['dbvc_portability']['uid'] = (string) $uid;

        if (! isset($payload['meta']) || ! \is_array($payload['meta'])) {
            $payload['meta'] = [];
        }
        $payload['meta']['export_object'] = 'form';
        $payload['meta'][\DBVC_Third_Party_Portability::META_PORTABILITY_UID] = (string) $uid;

        return $payload;
    }

    /**
     * @param array<string,mixed>      $item
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $index_item
     * @return array<string,mixed>
     */
    private static function finalize_preview_item(array $item, array $payload = [], $index_item = null)
    {
        $canonical_path = self::get_index_canonical_path($index_item);
        if ($canonical_path !== '') {
            $item['canonical_relative_path'] = $canonical_path;
        }

        $item['blocker_details'] = self::build_blocker_details($item);
        $item['preview_hash'] = self::build_preview_hash($item, $payload, $index_item);

        return $item;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<int,array<string,mixed>>
     */
    private static function build_blocker_details(array $item)
    {
        $details = [];
        $blocking = isset($item['blocking']) && \is_array($item['blocking']) ? $item['blocking'] : [];
        foreach ($blocking as $blocker) {
            if (! \is_array($blocker)) {
                continue;
            }

            $code = isset($blocker['code']) ? (string) $blocker['code'] : '';
            $detail = [
                'code'     => $code,
                'message'  => isset($blocker['message']) ? (string) $blocker['message'] : '',
                'severity' => 'error',
                'category' => __('Third-party import blocker', 'dbvc'),
            ];

            if ($code === 'wsform_unavailable') {
                $detail['category'] = __('Provider unavailable', 'dbvc');
                $detail['provider'] = 'ws_form';
            } elseif ($code === 'matched_provider_entity') {
                $detail['category'] = __('Existing WS Form', 'dbvc');
                $detail['match'] = isset($item['match']) && \is_array($item['match']) ? $item['match'] : [];
            } elseif ($code === 'stale_duplicate_file') {
                $detail['category'] = __('Duplicate provider sync file', 'dbvc');
                if (! empty($item['canonical_relative_path'])) {
                    $detail['canonical_relative_path'] = (string) $item['canonical_relative_path'];
                }
            }

            $details[] = $detail;
        }

        return $details;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,int>
     */
    private static function build_preview_summary(array $items)
    {
        $summary = [
            'requested' => count($items),
            'creatable' => 0,
            'updatable' => 0,
            'mergeable' => 0,
            'blocked'   => 0,
            'skipped'   => 0,
        ];

        foreach ($items as $item) {
            $blocking = isset($item['blocking']) && \is_array($item['blocking']) ? $item['blocking'] : [];
            if (empty($blocking) && ! empty($item['available_actions'][self::MODE_CREATE_FORM])) {
                $summary['creatable']++;
            } elseif (! empty($item['available_actions'][self::MODE_UPDATE_MATCHED_FORM])) {
                $summary['updatable']++;
            } elseif (empty($blocking) && ! empty($item['available_actions'][self::MODE_MERGE_SETTINGS])) {
                $summary['mergeable']++;
            } elseif (! empty($blocking)) {
                $summary['blocked']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,int>
     */
    private static function build_commit_summary(array $items)
    {
        $summary = [
            'requested' => count($items),
            'created'   => 0,
            'updated'   => 0,
            'blocked'   => 0,
            'skipped'   => 0,
            'errors'    => 0,
        ];

        foreach ($items as $item) {
            $status = isset($item['status']) ? (string) $item['status'] : '';
            if (! empty($item['created'])) {
                $summary['created']++;
            } elseif (! empty($item['updated']) || $status === 'updated') {
                $summary['updated']++;
            } elseif ($status === 'blocked') {
                $summary['blocked']++;
            } elseif ($status === 'error') {
                $summary['errors']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * @param string $mode
     * @return string
     */
    private static function normalize_mode($mode)
    {
        $mode = sanitize_key((string) $mode);
        if (! \in_array($mode, [self::MODE_PREVIEW, self::MODE_CREATE_FORM, self::MODE_UPDATE_MATCHED_FORM, self::MODE_MERGE_SETTINGS], true)) {
            return self::MODE_PREVIEW;
        }

        return $mode;
    }

    /**
     * @param array<int,string>|string $paths
     * @return array<int,string>|\WP_Error
     */
    private static function normalize_paths($paths)
    {
        if (\is_string($paths)) {
            $paths = [$paths];
        }

        if (! \is_array($paths)) {
            return new \WP_Error('dbvc_entity_editor_third_party_import_empty', __('Select one third-party sync JSON file to import.', 'dbvc'), ['status' => 400]);
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (! \is_string($path)) {
                continue;
            }

            $path = str_replace('\\', '/', ltrim(trim($path), '/'));
            if ($path === '') {
                continue;
            }

            $normalized[$path] = $path;
        }

        $normalized = array_values($normalized);
        if (empty($normalized)) {
            return new \WP_Error('dbvc_entity_editor_third_party_import_empty', __('Select one third-party sync JSON file to import.', 'dbvc'), ['status' => 400]);
        }

        if (count($normalized) > self::BATCH_LIMIT) {
            return new \WP_Error(
                'dbvc_entity_editor_third_party_import_batch_limit',
                sprintf(__('Third-party Entity Editor import supports up to %d files per request.', 'dbvc'), self::BATCH_LIMIT),
                [
                    'status' => 400,
                    'limit'  => self::BATCH_LIMIT,
                ]
            );
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $confirmations
     * @param string              $relative_path
     * @return array<string,mixed>|null
     */
    private static function find_confirmation(array $confirmations, $relative_path)
    {
        if (isset($confirmations[$relative_path]) && \is_array($confirmations[$relative_path])) {
            return $confirmations[$relative_path];
        }

        foreach ($confirmations as $confirmation) {
            if (! \is_array($confirmation)) {
                continue;
            }

            $path = isset($confirmation['relative_path']) ? (string) $confirmation['relative_path'] : (string) ($confirmation['path'] ?? '');
            $path = str_replace('\\', '/', ltrim($path, '/'));
            if ($path === $relative_path) {
                return $confirmation;
            }
        }

        return null;
    }

    /**
     * @param string $relative_path
     * @return array<string,mixed>|null
     */
    private static function find_index_item($relative_path)
    {
        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            return null;
        }

        $normalized = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        foreach ([false, true] as $force) {
            $index = \DBVC_Entity_Editor_Indexer::get_index($force);
            $items = isset($index['items']) && \is_array($index['items']) ? $index['items'] : [];
            foreach ($items as $item) {
                if (! \is_array($item)) {
                    continue;
                }

                $item_path = isset($item['relative_path']) ? str_replace('\\', '/', ltrim((string) $item['relative_path'], '/')) : '';
                if ($item_path === $normalized) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $index_item
     * @return string
     */
    private static function get_index_canonical_path($index_item)
    {
        if (! \is_array($index_item)) {
            return '';
        }

        if (isset($index_item['duplicate_group']) && \is_array($index_item['duplicate_group'])) {
            return isset($index_item['duplicate_group']['canonical_relative_path'])
                ? (string) $index_item['duplicate_group']['canonical_relative_path']
                : '';
        }

        return '';
    }

    /**
     * @param array<string,mixed>      $item
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $index_item
     * @return string
     */
    private static function build_preview_hash(array $item, array $payload, $index_item = null)
    {
        $blocking_codes = [];
        foreach ((array) ($item['blocking'] ?? []) as $blocker) {
            if (\is_array($blocker) && isset($blocker['code'])) {
                $blocking_codes[] = (string) $blocker['code'];
            }
        }

        return hash('sha256', wp_json_encode([
            'relative_path' => isset($item['relative_path']) ? (string) $item['relative_path'] : '',
            'provider'      => isset($item['provider']) ? (string) $item['provider'] : '',
            'object_type'   => isset($item['object_type']) ? (string) $item['object_type'] : '',
            'uid'           => isset($item['uid']) ? (string) $item['uid'] : '',
            'source_id'     => $item['source_id'] ?? null,
            'mtime'         => isset($item['mtime']) ? (int) $item['mtime'] : 0,
            'match'         => $item['match'] ?? null,
            'blocking'      => $blocking_codes,
            'duplicate'     => \is_array($index_item) ? ($index_item['duplicate_group']['key'] ?? '') : '',
            'payload_hash'  => md5(wp_json_encode($payload)),
        ]));
    }

    /**
     * @param string $relative_path
     * @return string|\WP_Error
     */
    private static function resolve_absolute_path($relative_path)
    {
        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error('dbvc_entity_editor_indexer_unavailable', __('Entity Editor indexer is unavailable.', 'dbvc'), ['status' => 500]);
        }

        return \DBVC_Entity_Editor_Indexer::resolve_entity_file_path_for_import($relative_path);
    }

    /**
     * @param string $absolute_path
     * @return array<string,mixed>|null
     */
    private static function read_json_file($absolute_path)
    {
        if (! is_readable($absolute_path)) {
            return null;
        }

        $raw = file_get_contents($absolute_path);
        if (! \is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string              $absolute_path
     * @param array<string,mixed> $payload
     * @return bool
     */
    private static function write_json_file($absolute_path, array $payload)
    {
        $normalized = \function_exists('dbvc_normalize_for_json') ? dbvc_normalize_for_json($payload) : $payload;
        $encoded = wp_json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! \is_string($encoded) || $encoded === '') {
            return false;
        }

        return file_put_contents($absolute_path, $encoded . "\n") !== false;
    }

    /**
     * @param array<string,mixed> $item
     * @return bool
     */
    private static function has_blocking(array $item)
    {
        return ! empty($item['blocking']) && \is_array($item['blocking']);
    }

    /**
     * @param array<string,mixed> $item
     * @param \WP_Error           $error
     * @return array<string,mixed>
     */
    private static function merge_item_error(array $item, \WP_Error $error)
    {
        return array_merge($item, [
            'action'   => 'error',
            'created'  => false,
            'updated'  => false,
            'imported' => false,
            'status'   => 'error',
            'blocking' => [
                self::reason((string) $error->get_error_code(), (string) $error->get_error_message()),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $item
     * @param string              $code
     * @param string              $message
     * @return array<string,mixed>
     */
    private static function merge_item_blocked(array $item, $code, $message)
    {
        $blocking = isset($item['blocking']) && \is_array($item['blocking']) ? $item['blocking'] : [];
        $blocking[] = self::reason($code, $message);

        return array_merge($item, [
            'action'   => 'blocked',
            'created'  => false,
            'updated'  => false,
            'imported' => false,
            'status'   => 'blocked',
            'blocking' => $blocking,
        ]);
    }

    /**
     * @param string $code
     * @param string $message
     * @return array<string,string>
     */
    private static function reason($code, $message)
    {
        return [
            'code'    => (string) $code,
            'message' => (string) $message,
        ];
    }

    /**
     * @param string              $message
     * @param array<string,mixed> $item
     * @param int                 $user_id
     * @return void
     */
    private static function log_commit($message, array $item, $user_id)
    {
        if (! \class_exists('DBVC_Sync_Logger')) {
            return;
        }

        \DBVC_Sync_Logger::log($message, [
            'relative_path' => isset($item['relative_path']) ? (string) $item['relative_path'] : '',
            'user_id'       => (int) $user_id,
            'provider'      => isset($item['provider']) ? (string) $item['provider'] : '',
            'object_type'   => isset($item['object_type']) ? (string) $item['object_type'] : '',
            'uid'           => isset($item['uid']) ? (string) $item['uid'] : '',
            'action'        => isset($item['action']) ? (string) $item['action'] : '',
            'form_id'       => isset($item['wp_entity']['id']) ? (int) $item['wp_entity']['id'] : 0,
            'status'        => isset($item['status']) ? (string) $item['status'] : '',
        ]);
    }
}
