<?php

namespace Dbvc\Connected\Adapters;

use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\DomainObserver;

/**
 * Read-only observer for a Bricks option-backed collection.
 *
 * Verified storage (Bricks 2.3.8, `includes/helpers.php` and
 * `includes/ajax.php`): `bricks_global_classes` and `bricks_global_variables`
 * are sequential PHP arrays of associative entries carrying a stable random
 * `id` and a `name`. Bricks writes the whole collection with a single
 * `update_option()`; it deletes `bricks_global_classes` when the last class
 * is removed and never deletes `bricks_global_variables` through the builder.
 * Global classes moved to the trash leave the active collection (they live in
 * `bricks_global_classes_trash`, which this observer does not read).
 *
 * The observer reads the persisted option row directly (no object cache, no
 * `option_{$name}` filters) so the projection reflects settled database state.
 * It never writes, never assigns identity, and reports the whole collection
 * in one read so per-batch cost is one parse regardless of member count.
 */
final class BricksOptionCollectionObserver implements DomainObserver
{
    public const ORDER_PROFILE = 'bricks-collection-order-v1';
    public const ORDER_INSTANCE_UID = 'collection.order';

    /**
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @param array<string, mixed> $config domain, profile, option, key_field, name_field, volatile_top_level_keys.
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'domain' => '',
            'profile' => '',
            'option' => '',
            'key_field' => 'id',
            'name_field' => 'name',
            'volatile_top_level_keys' => [],
        ], $config);
    }

    /**
     * @return string
     */
    public function domain()
    {
        return (string) $this->config['domain'];
    }

    /**
     * @return string
     */
    public function profile()
    {
        return (string) $this->config['profile'];
    }

    /**
     * @return string
     */
    public function option_name()
    {
        return (string) $this->config['option'];
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities()
    {
        $available = $this->bricks_available();

        return [
            'domain' => $this->domain(),
            'profile' => $this->profile(),
            'order_profile' => self::ORDER_PROFILE,
            'canonicalizer_version' => Canonicalizer::VERSION,
            'source_option' => $this->option_name(),
            'report' => $available,
            'prepare' => false,
            'apply' => false,
            'reason' => $available ? '' : 'bricks_not_available',
            'coverage' => [
                'active_collection' => true,
                'trash' => false,
                'categories' => false,
                'locked_flags' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function inventory(array $query)
    {
        $read = $this->read_collection();
        if ($read['status'] !== 'available') {
            return [
                'status' => $read['status'],
                'reason' => $read['reason'],
                'items' => [],
                'next_cursor' => null,
                'complete' => false,
                'order' => [],
                'order_hash' => null,
                'storage_fingerprint' => $read['storage_fingerprint'],
                'problems' => $read['problems'],
            ];
        }

        $cursor = isset($query['cursor']) ? max(0, (int) $query['cursor']) : 0;
        $limit = isset($query['limit']) ? max(0, (int) $query['limit']) : 0;
        $members = $read['members'];
        $total = count($members);
        $slice = $limit > 0 ? array_slice($members, $cursor, $limit) : array_slice($members, $cursor);
        $next_cursor = ($limit > 0 && $cursor + $limit < $total) ? $cursor + $limit : null;
        $enumerated_all = $cursor === 0 && $next_cursor === null;

        return [
            'status' => 'available',
            'reason' => '',
            'items' => $slice,
            'next_cursor' => $next_cursor,
            'complete' => $enumerated_all && $read['complete'],
            'order' => $enumerated_all ? $read['order'] : [],
            'order_hash' => $enumerated_all ? $read['order_hash'] : null,
            'order_canonical' => $enumerated_all ? $read['order_canonical'] : null,
            'storage_fingerprint' => $read['storage_fingerprint'],
            'total' => $total,
            'problems' => $read['problems'],
        ];
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    public function snapshot(array $identity)
    {
        $storage_key = isset($identity['storage_key']) ? (string) $identity['storage_key'] : '';
        if ($storage_key === '') {
            return ['status' => 'unsupported', 'exists' => null, 'complete' => false, 'reason' => 'storage_key_required'];
        }

        $read = $this->read_collection();
        if ($read['status'] !== 'available') {
            return [
                'status' => $read['status'],
                'exists' => null,
                'complete' => false,
                'reason' => $read['reason'],
                'profile' => $this->profile(),
            ];
        }

        foreach ($read['members'] as $member) {
            if ($member['storage_key'] === $storage_key) {
                return [
                    'status' => 'available',
                    'exists' => true,
                    'complete' => $member['complete'],
                    'profile' => $this->profile(),
                    'semantic_hash' => $member['hash'],
                    'canonical' => $member['canonical'],
                    'storage_fingerprint' => $read['storage_fingerprint'],
                    'revision' => $read['storage_fingerprint'],
                    'position' => $member['position'],
                    'display_name' => $member['display_name'],
                    'problems' => $member['problems'],
                ];
            }
        }

        return [
            'status' => 'missing',
            'exists' => false,
            'complete' => $read['complete'],
            'profile' => $this->profile(),
            'semantic_hash' => self::absent_hash(),
            'storage_fingerprint' => $read['storage_fingerprint'],
            'revision' => $read['storage_fingerprint'],
        ];
    }

    /**
     * Hash recorded for a verified absent object: the version-1 canonical
     * encoding of `null`, so consumers can recognise it.
     *
     * @return string
     */
    public static function absent_hash()
    {
        return Canonicalizer::hash(Canonicalizer::encode(null));
    }

    /**
     * Hash recorded for a member whose projection could not be completed
     * (for example invalid UTF-8): the canonical encoding of `false`. Always
     * paired with `complete=false`; it never proves equivalence.
     *
     * @return string
     */
    public static function incomplete_hash()
    {
        return Canonicalizer::hash(Canonicalizer::encode(false));
    }

    /**
     * @return bool
     */
    public function bricks_available()
    {
        $detected = defined('BRICKS_VERSION') || (function_exists('wp_get_theme') && wp_get_theme('bricks')->exists());

        /**
         * Override Bricks availability detection (disposable fixtures without the theme).
         *
         * @param bool   $detected
         * @param string $domain
         */
        return (bool) apply_filters('dbvc_connected_bricks_available', $detected, $this->domain());
    }

    /**
     * Raw container read for conditional writes: the persisted option value
     * exactly as stored (bypassing the object cache), its version-1
     * fingerprint and the decoded array.
     *
     * @return array{present: bool, raw: string|null, value: mixed, fingerprint: string|null}
     */
    public function read_container()
    {
        $persisted = $this->read_persisted_option();

        return [
            'present' => $persisted['present'],
            'raw' => $persisted['raw'],
            'value' => $persisted['value'],
            'fingerprint' => $persisted['present'] ? hash('sha256', (string) $persisted['raw']) : null,
        ];
    }

    /**
     * @return string
     */
    public function key_field()
    {
        return (string) $this->config['key_field'];
    }

    /**
     * @return array<int, string>
     */
    public function volatile_top_level_keys()
    {
        return (array) $this->config['volatile_top_level_keys'];
    }

    /**
     * One persisted read of the whole collection.
     *
     * @return array<string, mixed>
     */
    private function read_collection()
    {
        $problems = [];
        if (! $this->bricks_available()) {
            return [
                'status' => 'unavailable',
                'reason' => 'bricks_not_available',
                'members' => [],
                'order' => [],
                'order_hash' => null,
                'order_canonical' => null,
                'complete' => false,
                'storage_fingerprint' => null,
                'problems' => ['bricks_not_available'],
            ];
        }

        $persisted = $this->read_persisted_option();
        $storage_fingerprint = $persisted['present'] ? hash('sha256', (string) $persisted['raw']) : null;
        $value = $persisted['present'] ? $persisted['value'] : [];

        if ($persisted['present'] && ! is_array($value)) {
            return [
                'status' => 'unsupported',
                'reason' => 'collection_not_array',
                'members' => [],
                'order' => [],
                'order_hash' => null,
                'order_canonical' => null,
                'complete' => false,
                'storage_fingerprint' => $storage_fingerprint,
                'problems' => ['collection_not_array'],
            ];
        }

        $key_field = (string) $this->config['key_field'];
        $name_field = (string) $this->config['name_field'];
        $volatile = (array) $this->config['volatile_top_level_keys'];
        $members = [];
        $order = [];
        $key_counts = [];
        $complete = true;
        $position = 0;

        foreach ($value as $entry) {
            $storage_key = is_array($entry) && isset($entry[$key_field]) && is_scalar($entry[$key_field])
                ? trim((string) $entry[$key_field])
                : '';
            if ($storage_key === '' || strlen($storage_key) > 128) {
                $problems[] = 'member_without_stable_key:position=' . $position;
                $complete = false;
                $position++;
                continue;
            }

            $key_counts[$storage_key] = ($key_counts[$storage_key] ?? 0) + 1;
            $projection = Canonicalizer::project($entry, $volatile);
            $display_name = isset($entry[$name_field]) && is_scalar($entry[$name_field]) ? (string) $entry[$name_field] : '';
            $members[] = [
                'storage_key' => $storage_key,
                'display_name' => $display_name,
                'position' => $position,
                'profile' => $this->profile(),
                'complete' => $projection['ok'],
                'hash' => $projection['ok'] ? $projection['hash'] : self::incomplete_hash(),
                'canonical' => $projection['ok'] ? $projection['canonical'] : null,
                'problems' => $projection['ok'] ? [] : ['canonicalization:' . $projection['reason']],
            ];
            $order[] = $storage_key;
            $position++;
        }

        // A duplicated key makes every member carrying it ambiguous; fail closed for all of them.
        $filtered = [];
        foreach ($members as $member) {
            if ($key_counts[$member['storage_key']] > 1) {
                $problems[] = 'duplicate_storage_key:' . $member['storage_key'];
                $complete = false;
                continue;
            }
            $filtered[] = $member;
        }

        $order_projection = Canonicalizer::project($order);

        return [
            'status' => 'available',
            'reason' => '',
            'members' => $filtered,
            'order' => $order,
            'order_hash' => $order_projection['ok'] ? $order_projection['hash'] : null,
            'order_canonical' => $order_projection['ok'] ? $order_projection['canonical'] : null,
            'complete' => $complete,
            'storage_fingerprint' => $storage_fingerprint,
            'problems' => array_values(array_unique($problems)),
        ];
    }

    /**
     * Direct read of the persisted option row, bypassing caches and filters.
     *
     * @return array{present: bool, raw: string|null, value: mixed}
     */
    private function read_persisted_option()
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $this->option_name()
        ));
        if ($raw === null) {
            return ['present' => false, 'raw' => null, 'value' => null];
        }

        return ['present' => true, 'raw' => (string) $raw, 'value' => maybe_unserialize($raw)];
    }
}
