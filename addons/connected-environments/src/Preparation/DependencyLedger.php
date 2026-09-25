<?php

namespace Dbvc\Connected\Preparation;

use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Adapters\ServicePostObserver;

/**
 * Read-only dependency discovery for one release item on the target: what
 * the object's after-state references and whether the target already has
 * it. Every entry is required; its status is `present` (found on the
 * target), `selected` (supplied by another item of the same release),
 * `unresolved` (neither — including a referenced attachment the target does
 * not yet hold, pending the M7 byte channel) or `unsupported` (a reference
 * prepare cannot carry). An `unresolved` or `unsupported` entry blocks the
 * item; `complete=false` says discovery itself could not finish and is
 * equally visible. Nothing here writes or creates.
 */
final class DependencyLedger
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_SELECTED = 'selected';
    public const STATUS_UNRESOLVED = 'unresolved';
    public const STATUS_UNSUPPORTED = 'unsupported';

    /**
     * @param string                              $domain
     * @param array<string, mixed>                $after     Decoded canonical after-state.
     * @param array<int, array<string, mixed>>    $release_items Every manifest item (domain, instance_uid, body).
     * @param \Dbvc\ConnectedProtocol\DomainObserver $observer
     * @param array<int, string>                  $carried_media_hashes Content hashes this item's release carries bytes for.
     * @return array{entries: array<int, array<string, mixed>>, complete: bool, blocking: array<int, string>}
     */
    public static function build($domain, array $after, array $release_items, $observer, array $carried_media_hashes = [])
    {
        switch ($domain) {
            case DomainRegistry::DOMAIN_BRICKS_GLOBAL_CLASS:
                $entries = self::bricks_member($after, $release_items, 'bricks_global_classes_categories', 'bricks.class_category');
                break;
            case DomainRegistry::DOMAIN_BRICKS_VARIABLE:
                $entries = self::bricks_member($after, $release_items, 'bricks_global_variables_categories', 'bricks.variable_category');
                break;
            case DomainRegistry::DOMAIN_WP_SERVICE:
                $entries = self::service_post($after, $release_items, $observer, $carried_media_hashes);
                break;
            default:
                return ['entries' => [], 'complete' => false, 'blocking' => ['dependency_discovery_unsupported:' . $domain]];
        }
        $blocking = [];
        foreach ($entries as $entry) {
            if (in_array($entry['status'], [self::STATUS_UNRESOLVED, self::STATUS_UNSUPPORTED], true)) {
                $blocking[] = 'dependency_' . $entry['status'] . ':' . $entry['kind'] . ':' . $entry['ref'];
            }
        }

        return ['entries' => $entries, 'complete' => true, 'blocking' => $blocking];
    }

    /**
     * Category reference plus every `var(--name)` the member's settings/value use.
     *
     * @param array<string, mixed>             $after
     * @param array<int, array<string, mixed>> $release_items
     * @param string                           $categories_option
     * @param string                           $category_kind
     * @return array<int, array<string, mixed>>
     */
    private static function bricks_member(array $after, array $release_items, $categories_option, $category_kind)
    {
        $entries = [];
        $category = isset($after['category']) ? $after['category'] : ($after['cat'] ?? null);
        foreach (is_array($category) ? $category : [$category] as $reference) {
            $id = is_scalar($reference) ? trim((string) $reference) : '';
            if ($id === '' || $id === '0') {
                continue;
            }
            $entries[] = self::entry($category_kind, $id, self::category_exists($categories_option, $id) ? self::STATUS_PRESENT : self::STATUS_UNRESOLVED, 'option:' . $categories_option);
        }

        $names = [];
        self::collect_variable_names($after['settings'] ?? ($after['value'] ?? null), $names);
        if ($names !== []) {
            $present = self::variable_names_on_target();
            $selected = self::variable_names_in_release($release_items);
            $self_name = isset($after['name']) && is_scalar($after['name']) ? ltrim((string) $after['name'], '-') : '';
            foreach (array_keys($names) as $name) {
                if ($name === $self_name) {
                    continue;
                }
                $status = isset($selected[$name]) ? self::STATUS_SELECTED : (isset($present[$name]) ? self::STATUS_PRESENT : self::STATUS_UNRESOLVED);
                $entries[] = self::entry('bricks.variable', '--' . $name, $status, isset($selected[$name]) ? 'release:' . $selected[$name] : 'option:bricks_global_variables');
            }
        }

        return $entries;
    }

    /**
     * Parent post, taxonomy terms and media references (present or pending).
     *
     * @param array<string, mixed>                  $after
     * @param array<int, array<string, mixed>>      $release_items
     * @param \Dbvc\ConnectedProtocol\DomainObserver $observer
     * @param array<int, string>                    $carried_media_hashes
     * @return array<int, array<string, mixed>>
     */
    private static function service_post(array $after, array $release_items, $observer, array $carried_media_hashes = [])
    {
        $entries = [];
        $post_type = $observer instanceof ServicePostObserver ? $observer->post_type() : 'post';
        $parent_uid = isset($after['post_parent_uid']) && is_scalar($after['post_parent_uid']) ? trim((string) $after['post_parent_uid']) : '';
        if ($parent_uid !== '') {
            $in_release = false;
            foreach ($release_items as $item) {
                if (($item['domain'] ?? '') === DomainRegistry::DOMAIN_WP_SERVICE && ($item['instance_uid'] ?? '') === $parent_uid) {
                    $in_release = true;
                    break;
                }
            }
            $ids = get_posts(['post_type' => $post_type, 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => 'vf_object_uid', 'meta_value' => $parent_uid, 'posts_per_page' => 1, 'suppress_filters' => true, 'no_found_rows' => true]); // phpcs:ignore WordPress.DB.SlowDBQuery
            $status = $in_release ? self::STATUS_SELECTED : ($ids !== [] ? self::STATUS_PRESENT : self::STATUS_UNRESOLVED);
            $entries[] = self::entry('wp.post_parent', $parent_uid, $status, $ids !== [] ? 'post:' . (int) $ids[0] : '');
        }

        foreach ((array) ($after['tax_input'] ?? []) as $taxonomy => $terms) {
            $taxonomy = (string) $taxonomy;
            if (! taxonomy_exists($taxonomy)) {
                $entries[] = self::entry('wp.taxonomy', $taxonomy, self::STATUS_UNSUPPORTED, 'taxonomy_not_registered');
                continue;
            }
            foreach ((array) $terms as $term) {
                $slug = is_array($term) && isset($term['slug']) ? (string) $term['slug'] : (is_scalar($term) ? (string) $term : '');
                if ($slug === '') {
                    continue;
                }
                $found = get_term_by('slug', $slug, $taxonomy);
                $entries[] = self::entry('wp.term', $taxonomy . ':' . $slug, $found instanceof \WP_Term ? self::STATUS_PRESENT : self::STATUS_UNRESOLVED, $found instanceof \WP_Term ? 'term:' . $found->term_id : 'term_creation_requires_decision');
            }
        }

        // Media (attachments): the body references each by a portable content-hash
        // token. `present` when this site already holds that content by hash (dedup)
        // or the release carries its bytes (apply sideloads it — M7 step 3b) — the
        // post can converge and does not block; `unresolved` otherwise, still
        // blocking, so a post is never applied with a dangling reference.
        $carried = array_fill_keys(array_map([self::class, 'media_hex'], $carried_media_hashes), true);
        foreach (MediaReferences::tokens_in_body($after) as $hash) {
            $local = MediaReferences::local_by_hash($hash);
            if ($local > 0) {
                $entries[] = self::entry('wp.media', MediaReferences::TOKEN_PREFIX . $hash, self::STATUS_PRESENT, 'attachment:' . $local);
            } elseif (isset($carried[$hash])) {
                $entries[] = self::entry('wp.media', MediaReferences::TOKEN_PREFIX . $hash, self::STATUS_PRESENT, 'carried');
            } else {
                $entries[] = self::entry('wp.media', MediaReferences::TOKEN_PREFIX . $hash, self::STATUS_UNRESOLVED, 'media_transfer_pending');
            }
        }

        return $entries;
    }

    /**
     * @param string $kind
     * @param string $ref
     * @param string $status
     * @param string $detail
     * @return array<string, mixed>
     */
    private static function entry($kind, $ref, $status, $detail = '')
    {
        return ['kind' => $kind, 'ref' => $ref, 'required' => true, 'status' => $status, 'detail' => $detail];
    }

    /**
     * @param string $hash sha256 hex, optionally `sha256:`-prefixed.
     * @return string bare lowercase hex, or '' when not a sha256 hex.
     */
    private static function media_hex($hash)
    {
        $hash = strpos((string) $hash, ':') !== false ? substr((string) $hash, strpos((string) $hash, ':') + 1) : (string) $hash;

        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }

    /**
     * @param string $option
     * @param string $id
     * @return bool
     */
    private static function category_exists($option, $id)
    {
        foreach ((array) get_option($option, []) as $record) {
            if (is_array($record) && isset($record['id']) && trim((string) $record['id']) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true> Variable names (without the leading dashes) present on this site.
     */
    private static function variable_names_on_target()
    {
        $names = [];
        foreach ((array) get_option('bricks_global_variables', []) as $record) {
            if (is_array($record) && isset($record['name']) && is_scalar($record['name'])) {
                $names[ltrim((string) $record['name'], '-')] = true;
            }
        }

        return $names;
    }

    /**
     * @param array<int, array<string, mixed>> $release_items
     * @return array<string, string> name => instance uid of the release item that supplies it.
     */
    private static function variable_names_in_release(array $release_items)
    {
        $names = [];
        foreach ($release_items as $item) {
            if (($item['domain'] ?? '') !== DomainRegistry::DOMAIN_BRICKS_VARIABLE || ! is_string($item['body'] ?? null)) {
                continue;
            }
            $decoded = json_decode($item['body'], true);
            if (is_array($decoded) && isset($decoded['name']) && is_scalar($decoded['name'])) {
                $names[ltrim((string) $decoded['name'], '-')] = (string) ($item['instance_uid'] ?? '');
            }
        }

        return $names;
    }

    /**
     * @param mixed               $value
     * @param array<string, true> $names
     * @return void
     */
    private static function collect_variable_names($value, array &$names)
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                self::collect_variable_names($child, $names);
            }
            return;
        }
        if (is_string($value) && preg_match_all('/var\(\s*--([A-Za-z0-9_-]+)/', $value, $matches)) {
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }
    }
}
