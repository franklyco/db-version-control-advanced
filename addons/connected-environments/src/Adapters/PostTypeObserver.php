<?php

namespace Dbvc\Connected\Adapters;

use Dbvc\Connected\Preparation\MediaReferences;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\DomainObserver;

/**
 * Read-only observer for one custom post type, addressed as the `wp.post:<type>`
 * domain (with `wp.service` retained as a back-compat alias — see
 * {@see ServicePostObserver}). Its type, domain and profiles come from the
 * domain definition, so a single class serves every opted-in post type.
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
 *
 * A per-type meta allow-list narrows the projection to named keys (empty =
 * every key minus the ignored/masked sets); a per-type deny list and the
 * `dbvc_connected_post_ignored_meta_keys` filter extend the ignored set.
 * Portable taxonomy terms transfer via `DBVC_Sync_Posts`; the per-type
 * `create_terms` flag controls whether apply may create a missing term.
 */
class PostTypeObserver implements DomainObserver
{
    /**
     * @var array<int, string>
     */
    protected const DEFAULT_IGNORED_META = [
        'vf_object_uid', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug',
        '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_pingme', '_encloseme',
        // DBVC's own sync bookkeeping: rewritten on every save/import, never portable content.
        'dbvc_post_history', '_dbvc_import_hash',
    ];

    /**
     * @var array<int, string>
     */
    protected const OBSERVED_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    /**
     * @var string
     */
    protected $domain;

    /**
     * @var string
     */
    protected $post_type;

    /**
     * @var string
     */
    protected $profile;

    /**
     * @var string
     */
    protected $inventory_profile;

    /**
     * Extra meta keys excluded from the projection (per-type deny list).
     *
     * @var array<int, string>
     */
    protected $meta_deny_keys;

    /**
     * When non-empty, only these meta keys are projected (per-type allow-list).
     *
     * @var array<int, string>
     */
    protected $meta_allow_keys;

    /**
     * Whether apply may create a taxonomy term the target lacks.
     *
     * @var bool
     */
    protected $create_terms;

    /**
     * Whether this connector will let approved releases apply to this post type
     * (a per-CPT apply allow-list, narrower than the observe set).
     *
     * @var bool
     */
    protected $apply_allowed;

    /**
     * @param array<string, mixed> $definition One `wp.post:<type>` domain definition.
     */
    public function __construct(array $definition = [])
    {
        $type = sanitize_key((string) ($definition['post_type'] ?? ''));
        $this->post_type = $type;
        $this->domain = (string) ($definition['domain'] ?? (DomainRegistry::DOMAIN_POST_PREFIX . $type));
        $this->profile = (string) ($definition['profile'] ?? (DomainRegistry::POST_PROFILE_PREFIX . $type . '-v1'));
        $this->inventory_profile = (string) ($definition['inventory_profile'] ?? (DomainRegistry::POST_PROFILE_PREFIX . $type . '-inventory-v1'));
        $this->meta_deny_keys = array_values(array_map('strval', (array) ($definition['meta_deny'] ?? [])));
        $this->meta_allow_keys = array_values(array_map('strval', (array) ($definition['meta_allow'] ?? [])));
        $this->create_terms = (bool) ($definition['create_terms'] ?? false);
        $this->apply_allowed = (bool) ($definition['apply'] ?? false);
    }

    /**
     * @return string
     */
    public function domain()
    {
        return $this->domain;
    }

    /**
     * @return string
     */
    public function profile()
    {
        return $this->profile;
    }

    /**
     * @return string Coverage/inventory profile for this type.
     */
    public function inventory_profile()
    {
        return $this->inventory_profile;
    }

    /**
     * @return string
     */
    public function post_type()
    {
        return $this->post_type;
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities()
    {
        $available = post_type_exists($this->post_type());
        $dbvc_supported = class_exists('DBVC_Sync_Posts') && in_array($this->post_type(), (array) \DBVC_Sync_Posts::get_supported_post_types(), true);

        return [
            'domain' => $this->domain(),
            'profile' => $this->profile(),
            'order_profile' => $this->inventory_profile(),
            'canonicalizer_version' => Canonicalizer::VERSION,
            'source_option' => 'post_type:' . $this->post_type(),
            'report' => $available,
            'prepare' => $available && $dbvc_supported,
            // Apply also requires this connector to have opted the type into its apply allow-list.
            'apply' => $available && $dbvc_supported && $this->apply_allowed,
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
            return ['status' => 'unsupported', 'exists' => null, 'complete' => false, 'reason' => 'storage_key_required', 'profile' => $this->profile()];
        }
        if (! post_type_exists($this->post_type())) {
            return ['status' => 'unavailable', 'exists' => null, 'complete' => false, 'reason' => 'post_type_not_registered', 'profile' => $this->profile()];
        }

        $member = $this->member($post_id);
        if ($member === null) {
            // A verified single-object read: the post is gone, trashed, or not this post type.
            return [
                'status' => 'missing',
                'exists' => false,
                'complete' => true,
                'profile' => $this->profile(),
                'semantic_hash' => BricksOptionCollectionObserver::absent_hash(),
                'storage_fingerprint' => null,
                'revision' => null,
            ];
        }
        if ($member['instance_uid'] === '') {
            return ['status' => 'identity_missing', 'exists' => true, 'complete' => false, 'reason' => 'identity_missing', 'profile' => $this->profile(), 'storage_key' => (string) $post_id];
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
        $ignored = $this->ignored_meta_keys();
        foreach ($ignored as $key) {
            unset($raw_meta[$key]);
        }
        // A per-type allow-list narrows the projection to named keys; the excluded
        // keys are out of the sync contract entirely (like ignored keys), not masked,
        // so the projection stays complete.
        $allow = $this->meta_allow();
        if ($allow !== []) {
            $raw_meta = array_intersect_key($raw_meta, array_flip($allow));
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

        // Portable media: replace resolvable local attachment references (ids/URLs)
        // with content-hash tokens so the projection hash is site-independent
        // (M7). Apply reverses this to local references before writing.
        $data = MediaReferences::tokenize($data);

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
            'profile' => $this->profile(),
            'complete' => $complete,
            'hash' => $projection['ok'] ? $projection['hash'] : BricksOptionCollectionObserver::incomplete_hash(),
            'canonical' => $projection['ok'] ? $projection['canonical'] : null,
            // post_modified for a cheap change signal, plus the projection hash so a same-second
            // edit that changes content (but not post_modified's second) still moves the fingerprint.
            'storage_fingerprint' => hash('sha256', (string) $post->post_modified_gmt . '|' . wp_json_encode($raw_meta) . '|' . ($projection['ok'] ? (string) $projection['hash'] : 'incomplete')),
            'problems' => array_values(array_unique($problems)),
        ];
    }

    /**
     * Apply a received canonical body to a local post: post fields, managed meta
     * (converged to the body — keys the body omits are removed) and portable
     * terms in dependency order. Media (attachments) are not created here. The
     * caller guards the write with the storage fingerprint and verifies the
     * result by re-snapshotting; this method performs no verification of its own.
     *
     * @param int                  $post_id
     * @param array<string, mixed> $body Canonical post body (post fields, meta, tax_input).
     * @return true|string true on write, or an error slug.
     */
    public function apply_body($post_id, array $body)
    {
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || $post->post_type !== $this->post_type()) {
            return 'target_post_missing';
        }
        if (! in_array((string) ($body['post_status'] ?? ''), self::OBSERVED_STATUSES, true)) {
            return 'unsupported_post_status';
        }

        // Reverse the portable media tokens to this site's own attachment ids/URLs
        // (by content hash) before writing; a token this site cannot resolve is
        // left as-is so the guarded re-snapshot fails rather than storing a broken
        // reference. Attachments are materialized before this point (M7 step 3b).
        $body = MediaReferences::detokenize($body);

        $parent_id = 0;
        $parent_uid = (string) ($body['post_parent_uid'] ?? '');
        if ($parent_uid !== '') {
            $parent = get_posts(['post_type' => $this->post_type(), 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'vf_object_uid', 'meta_value' => $parent_uid, 'posts_per_page' => 1, 'suppress_filters' => true, 'no_found_rows' => true]); // phpcs:ignore WordPress.DB.SlowDBQuery
            if ($parent === []) {
                return 'parent_unresolved';
            }
            $parent_id = (int) $parent[0];
        }

        // Fields this environment masks (privacy) are out of the sync contract: apply must
        // never write or delete them, and the release payload cannot carry their real value.
        // Skipping them here mirrors the projection, which excludes the same masked fields.
        $masked_fields = function_exists('dbvc_get_export_mask_post_fields') ? array_map('strval', (array) dbvc_get_export_mask_post_fields()) : [];
        $is_masked = static function ($field) use ($masked_fields) {
            return in_array((string) $field, $masked_fields, true);
        };

        $update = ['ID' => $post_id, 'post_status' => (string) $body['post_status'], 'post_parent' => $parent_id];
        foreach (['post_title', 'post_name', 'post_content', 'post_excerpt'] as $field) {
            if (! $is_masked($field)) {
                $update[$field] = (string) ($body[$field] ?? '');
            }
        }
        if (! $is_masked('menu_order')) {
            $update['menu_order'] = (int) ($body['menu_order'] ?? 0);
        }
        if (! $is_masked('post_date_gmt') && isset($body['post_date_gmt']) && (string) $body['post_date_gmt'] !== '' && (string) $body['post_date_gmt'] !== '0000-00-00 00:00:00') {
            $update['post_date_gmt'] = (string) $body['post_date_gmt'];
            $update['post_date'] = get_date_from_gmt((string) $body['post_date_gmt']);
        }
        $result = wp_update_post($update, true);
        if (is_wp_error($result)) {
            return 'post_update_failed:' . $result->get_error_code();
        }

        // Managed meta converges to the body: set the body's keys, remove managed keys it omits.
        $target_meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $ignored = $this->ignored_meta_keys();
        // A per-type allow-list means keys outside it are unmanaged: never written and never
        // deleted on the target, exactly as the projection excludes them.
        $allow = $this->meta_allow();
        $allow_active = $allow !== [];
        $allow_lookup = array_flip($allow);
        $current = get_post_meta($post_id);
        $current = is_array($current) ? $current : [];
        // Masked meta is out of the sync contract too: never write it and never delete it,
        // exactly like the projection, which excludes the same masked keys.
        if (function_exists('dbvc_mask_apply_to_meta')) {
            $masked_current = dbvc_mask_apply_to_meta($current);
            foreach ($current as $key => $value) {
                if (! array_key_exists($key, $masked_current) || $masked_current[$key] !== $value) {
                    $ignored[] = (string) $key;
                }
            }
            $ignored = array_values(array_unique($ignored));
        }
        foreach (array_keys($current) as $key) {
            if (in_array($key, $ignored, true) || ($allow_active && ! isset($allow_lookup[(string) $key])) || array_key_exists($key, $target_meta)) {
                continue;
            }
            delete_post_meta($post_id, $key);
        }
        foreach ($target_meta as $key => $values) {
            if (in_array($key, $ignored, true) || ($allow_active && ! isset($allow_lookup[(string) $key]))) {
                continue;
            }
            delete_post_meta($post_id, (string) $key);
            foreach ((is_array($values) ? $values : [$values]) as $value) {
                add_post_meta($post_id, (string) $key, wp_slash($value));
            }
        }

        $tax_input = is_array($body['tax_input'] ?? null) ? $body['tax_input'] : [];
        if (class_exists('DBVC_Sync_Posts')) {
            \DBVC_Sync_Posts::import_tax_input_for_post($post_id, $this->post_type(), $tax_input, $this->create_terms());
        }

        clean_post_cache($post_id);

        return true;
    }

    /**
     * Meta keys the projection (and therefore apply) never manages: the built-in
     * bookkeeping set, the DBVC skip set, the per-type deny list and the generic
     * `dbvc_connected_post_ignored_meta_keys` filter. The `wp.service` alias
     * layers its legacy filter on top (see {@see ServicePostObserver}).
     *
     * @return array<int, string>
     */
    public function ignored_meta_keys()
    {
        $ignored = array_merge(self::DEFAULT_IGNORED_META, (array) apply_filters('dbvc_skip_meta_keys', ['_edit_lock', '_edit_last']));
        $ignored = array_merge($ignored, $this->meta_deny_keys);
        /**
         * Per-type meta keys excluded from a `wp.post:<type>` projection.
         *
         * @param array<int, string> $ignored
         * @param string             $post_type
         */
        $ignored = (array) apply_filters('dbvc_connected_post_ignored_meta_keys', $ignored, $this->post_type());

        return array_values(array_unique(array_map('strval', $ignored)));
    }

    /**
     * The per-type meta allow-list (empty = allow every non-ignored key).
     *
     * @return array<int, string>
     */
    public function meta_allow()
    {
        /**
         * Per-type meta allow-list; when non-empty only these keys are projected.
         *
         * @param array<int, string> $allow
         * @param string             $post_type
         */
        $allow = (array) apply_filters('dbvc_connected_post_meta_allow', $this->meta_allow_keys, $this->post_type());

        return array_values(array_unique(array_map('strval', $allow)));
    }

    /**
     * Whether apply may create a taxonomy term the target lacks (per type).
     *
     * @return bool
     */
    public function create_terms()
    {
        /**
         * Per-type "create missing terms" toggle for apply.
         *
         * @param bool   $create
         * @param string $post_type
         */
        return (bool) apply_filters('dbvc_connected_post_create_terms', $this->create_terms, $this->post_type());
    }

    /**
     * Reversible removal for a reviewed delete: trash the post (never hard-delete
     * via sync — that would be an unrecoverable content write). It leaves observed
     * coverage, so re-snapshot reads `missing`; a reviewed rollback restores it by
     * re-applying the journalled before body (status back to an observed value),
     * with the same post id and portable identity intact.
     *
     * @param int $post_id
     * @return true|string true, or an error slug.
     */
    public function trash($post_id)
    {
        $post = get_post((int) $post_id);
        if (! $post instanceof \WP_Post || $post->post_type !== $this->post_type()) {
            return 'target_post_missing';
        }
        $result = wp_trash_post((int) $post_id);
        clean_post_cache((int) $post_id);

        return $result ? true : 'post_trash_failed';
    }

    /**
     * Compensation for a delete whose verification missed: bring a just-trashed
     * post back to its pre-trash status so a failed delete never leaves the post
     * removed.
     *
     * @param int $post_id
     * @return true|string
     */
    public function untrash($post_id)
    {
        $result = wp_untrash_post((int) $post_id);
        clean_post_cache((int) $post_id);

        return $result ? true : 'post_untrash_failed';
    }

    /**
     * @param string $reason
     * @return array<string, mixed>
     */
    protected function unavailable($reason)
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
