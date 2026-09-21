<?php

namespace Dbvc\Connected\Adapters;

use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\DomainObserver;

/**
 * Read-only observer for `wp.service` posts.
 *
 * Identity is DBVC's portable `vf_object_uid` post meta (assigned by
 * `DBVC_Sync_Posts::ensure_post_uid_on_save` on ordinary saves); a post
 * without it is reported as `identity_missing` and never backfilled here.
 * The projection reuses DBVC's export-time owners for metadata:
 * `dbvc_sanitize_post_meta_safe()` (lossless unserialize/normalize) and
 * `dbvc_mask_apply_to_meta()` / `dbvc_mask_apply_to_post_fields()` (the
 * configured masking rules). Any masked or removed field makes the
 * projection `complete=false`: a placeholder can never prove equivalence.
 * Environment-specific fields (author, GUID, modified timestamps, numeric
 * IDs) are excluded; the publish date and status are content.
 */
final class ServicePostObserver implements DomainObserver
{
    public const DOMAIN = 'wp.service';
    public const PROFILE = 'wp-service-v1';
    /**
     * Coverage projection: the sorted set of portable UIDs currently present
     * (identity `collection.order`, like a Bricks collection order). It is
     * complete only when every observed post carries a UID, so the hub can
     * treat "never observed inside a complete inventory" as verified absent.
     */
    public const INVENTORY_PROFILE = 'wp-service-inventory-v1';
    public const DEFAULT_POST_TYPE = 'service';

    /**
     * @var array<int, string>
     */
    private const DEFAULT_IGNORED_META = [
        'vf_object_uid', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug',
        '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_pingme', '_encloseme',
    ];

    /**
     * @var array<int, string>
     */
    private const OBSERVED_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    /**
     * @return string
     */
    public function domain()
    {
        return self::DOMAIN;
    }

    /**
     * @return string
     */
    public function profile()
    {
        return self::PROFILE;
    }

    /**
     * @return string
     */
    public function post_type()
    {
        /**
         * Post type observed as the `wp.service` domain.
         *
         * @param string $post_type
         */
        $post_type = sanitize_key((string) apply_filters('dbvc_connected_service_post_type', self::DEFAULT_POST_TYPE));

        return $post_type !== '' ? $post_type : self::DEFAULT_POST_TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities()
    {
        $available = post_type_exists($this->post_type());
        $dbvc_supported = class_exists('DBVC_Sync_Posts') && in_array($this->post_type(), (array) \DBVC_Sync_Posts::get_supported_post_types(), true);

        return [
            'domain' => self::DOMAIN,
            'profile' => self::PROFILE,
            'order_profile' => self::INVENTORY_PROFILE,
            'canonicalizer_version' => Canonicalizer::VERSION,
            'source_option' => 'post_type:' . $this->post_type(),
            'report' => $available,
            'prepare' => false,
            'apply' => false,
            'reason' => $available ? ($dbvc_supported ? '' : 'identity_requires_dbvc_post_type') : 'post_type_not_registered',
            // DBVC assigns vf_object_uid on save only for its configured post types; without it members report identity_missing.
            'identity_source' => 'dbvc_post_types',
            'identity_available' => $dbvc_supported,
            'coverage' => [
                'post_fields' => true,
                'post_meta' => true,
                'taxonomies' => true,
                'media_files' => false,
                'revisions' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function inventory(array $query)
    {
        if (! post_type_exists($this->post_type())) {
            return $this->unavailable('post_type_not_registered');
        }

        $cursor = isset($query['cursor']) ? max(0, (int) $query['cursor']) : 0;
        $limit = isset($query['limit']) ? max(0, (int) $query['limit']) : 0;
        $args = [
            'post_type' => $this->post_type(),
            'post_status' => self::OBSERVED_STATUSES,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'posts_per_page' => $limit > 0 ? $limit + 1 : -1,
            'offset' => $cursor,
        ];
        $ids = array_map('intval', (array) get_posts($args));
        $next_cursor = null;
        if ($limit > 0 && count($ids) > $limit) {
            $ids = array_slice($ids, 0, $limit);
            $next_cursor = $cursor + $limit;
        }

        $items = [];
        $problems = [];
        $complete = $cursor === 0 && $next_cursor === null;
        foreach ($ids as $position => $post_id) {
            $member = $this->member($post_id);
            if ($member === null) {
                continue;
            }
            $member['position'] = $cursor + $position;
            if ($member['instance_uid'] === '') {
                $problems[] = 'identity_missing:post=' . $post_id;
                $complete = false;
                continue;
            }
            $items[] = $member;
        }

        $enumerated_all = $cursor === 0 && $next_cursor === null;
        $inventory = $enumerated_all ? $this->inventory_projection_from_uids(array_column($items, 'instance_uid'), $complete) : null;

        return [
            'status' => 'available',
            'reason' => '',
            'items' => $items,
            'next_cursor' => $next_cursor,
            'complete' => $complete,
            'order' => $inventory !== null ? $inventory['uids'] : [],
            'order_hash' => $inventory !== null ? $inventory['hash'] : null,
            'order_canonical' => $inventory !== null ? $inventory['canonical'] : null,
            'storage_fingerprint' => null,
            'total' => count($ids),
            'problems' => array_values(array_unique($problems)),
        ];
    }

    /**
     * Cheap coverage read (IDs and UIDs only, no content): the sorted UID set
     * of every observed-status post of the type, or an incomplete marker when
     * a post has no portable identity yet.
     *
     * @return array<string, mixed> `status`, `complete`, `hash`, `canonical`, `uids`, `problems`.
     */
    public function inventory_projection()
    {
        if (! post_type_exists($this->post_type())) {
            return ['status' => 'unavailable', 'reason' => 'post_type_not_registered', 'complete' => false, 'hash' => null, 'canonical' => null, 'uids' => [], 'problems' => ['post_type_not_registered']];
        }
        $ids = array_map('intval', (array) get_posts([
            'post_type' => $this->post_type(),
            'post_status' => self::OBSERVED_STATUSES,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'posts_per_page' => -1,
        ]));
        $uids = [];
        $problems = [];
        foreach ($ids as $post_id) {
            $uid = get_post_meta($post_id, 'vf_object_uid', true);
            $uid = is_string($uid) ? trim($uid) : '';
            if ($uid === '' || ! \Dbvc\ConnectedProtocol\ObservationEvent::isIdentifier($uid)) {
                $problems[] = 'identity_missing:post=' . $post_id;
                continue;
            }
            $uids[] = $uid;
        }
        $projection = $this->inventory_projection_from_uids($uids, $problems === []);

        return array_merge(['status' => 'available', 'reason' => '', 'problems' => $problems], $projection);
    }

    /**
     * @param array<int, string> $uids
     * @param bool               $complete
     * @return array{complete: bool, hash: string, canonical: string|null, uids: array<int, string>}
     */
    private function inventory_projection_from_uids(array $uids, $complete)
    {
        $uids = array_values(array_unique(array_map('strval', $uids)));
        sort($uids, SORT_STRING);
        if (! $complete) {
            return ['complete' => false, 'hash' => BricksOptionCollectionObserver::incomplete_hash(), 'canonical' => null, 'uids' => $uids];
        }
        $canonical = Canonicalizer::encode($uids);

        return ['complete' => true, 'hash' => Canonicalizer::hash($canonical), 'canonical' => $canonical, 'uids' => $uids];
    }

    /**
     * @param array<string, mixed> $identity `storage_key` = post ID.
     * @return array<string, mixed>
     */
    public function snapshot(array $identity)
    {
        $post_id = isset($identity['storage_key']) ? (int) $identity['storage_key'] : 0;
        if ($post_id <= 0) {
            return ['status' => 'unsupported', 'exists' => null, 'complete' => false, 'reason' => 'storage_key_required', 'profile' => self::PROFILE];
        }
        if (! post_type_exists($this->post_type())) {
            return ['status' => 'unavailable', 'exists' => null, 'complete' => false, 'reason' => 'post_type_not_registered', 'profile' => self::PROFILE];
        }

        $member = $this->member($post_id);
        if ($member === null) {
            // A verified single-object read: the post is gone, trashed, or not this post type.
            return [
                'status' => 'missing',
                'exists' => false,
                'complete' => true,
                'profile' => self::PROFILE,
                'semantic_hash' => BricksOptionCollectionObserver::absent_hash(),
                'storage_fingerprint' => null,
                'revision' => null,
            ];
        }
        if ($member['instance_uid'] === '') {
            return ['status' => 'identity_missing', 'exists' => true, 'complete' => false, 'reason' => 'identity_missing', 'profile' => self::PROFILE, 'storage_key' => (string) $post_id];
        }

        return array_merge($member, [
            'status' => 'available',
            'exists' => true,
            'semantic_hash' => $member['hash'],
            'storage_fingerprint' => $member['storage_fingerprint'],
            'revision' => $member['storage_fingerprint'],
        ]);
    }

    /**
     * @param int $post_id
     * @return array<string, mixed>|null Null when the post is absent, trashed or of another type.
     */
    private function member($post_id)
    {
        $post = get_post((int) $post_id);
        if (! $post instanceof \WP_Post || $post->post_type !== $this->post_type() || ! in_array($post->post_status, self::OBSERVED_STATUSES, true)) {
            return null;
        }

        $uid = get_post_meta($post->ID, 'vf_object_uid', true);
        $uid = is_string($uid) ? trim($uid) : '';
        if ($uid !== '' && ! \Dbvc\ConnectedProtocol\ObservationEvent::isIdentifier($uid)) {
            $uid = '';
        }

        $problems = [];
        $raw_meta = get_post_meta($post->ID);
        $raw_meta = is_array($raw_meta) ? $raw_meta : [];
        $ignored = array_merge(self::DEFAULT_IGNORED_META, (array) apply_filters('dbvc_skip_meta_keys', ['_edit_lock', '_edit_last']));
        /**
         * Meta keys excluded from the `wp.service` projection.
         *
         * @param array<int, string> $ignored
         */
        $ignored = array_values(array_unique(array_map('strval', (array) apply_filters('dbvc_connected_service_ignored_meta_keys', $ignored))));
        foreach ($ignored as $key) {
            unset($raw_meta[$key]);
        }

        $meta = function_exists('dbvc_sanitize_post_meta_safe') ? dbvc_sanitize_post_meta_safe($raw_meta) : $raw_meta;
        $masked = function_exists('dbvc_mask_apply_to_meta') ? dbvc_mask_apply_to_meta($meta) : $meta;
        foreach ($meta as $key => $value) {
            if (! array_key_exists($key, $masked) || $masked[$key] !== $value) {
                $problems[] = 'masked:' . $key;
            }
        }
        ksort($masked, SORT_STRING);

        $parent_uid = '';
        if ((int) $post->post_parent > 0) {
            $parent_uid = (string) get_post_meta((int) $post->post_parent, 'vf_object_uid', true);
        }
        $data = [
            'post_status' => (string) $post->post_status,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_content' => (string) $post->post_content,
            'post_excerpt' => (string) $post->post_excerpt,
            'post_date_gmt' => (string) $post->post_date_gmt,
            'post_parent_uid' => $parent_uid,
            'menu_order' => (int) $post->menu_order,
            'tax_input' => class_exists('DBVC_Sync_Posts') ? \DBVC_Sync_Posts::export_tax_input_portable($post->ID, $post->post_type) : [],
            'meta' => $masked,
        ];
        if (function_exists('dbvc_get_export_mask_post_fields') && function_exists('dbvc_mask_apply_to_post_fields')) {
            $fields = dbvc_get_export_mask_post_fields();
            $masked_data = dbvc_mask_apply_to_post_fields($data, $fields);
            foreach ($data as $key => $value) {
                if (! array_key_exists($key, $masked_data) || $masked_data[$key] !== $value) {
                    $problems[] = 'masked:' . $key;
                }
            }
            $data = $masked_data;
        }

        $projection = Canonicalizer::project($data);
        $complete = $projection['ok'] && $problems === [];
        if (! $projection['ok']) {
            $problems[] = 'canonicalization:' . $projection['reason'];
        }

        return [
            'storage_key' => (string) $post->ID,
            'instance_uid' => $uid,
            'display_name' => (string) $post->post_title,
            'position' => 0,
            'profile' => self::PROFILE,
            'complete' => $complete,
            'hash' => $projection['ok'] ? $projection['hash'] : BricksOptionCollectionObserver::incomplete_hash(),
            'canonical' => $projection['ok'] ? $projection['canonical'] : null,
            'storage_fingerprint' => hash('sha256', (string) $post->post_modified_gmt . '|' . wp_json_encode($raw_meta)),
            'problems' => array_values(array_unique($problems)),
        ];
    }

    /**
     * @param string $reason
     * @return array<string, mixed>
     */
    private function unavailable($reason)
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'items' => [],
            'next_cursor' => null,
            'complete' => false,
            'order' => [],
            'order_hash' => null,
            'order_canonical' => null,
            'storage_fingerprint' => null,
            'total' => 0,
            'problems' => [$reason],
        ];
    }
}
