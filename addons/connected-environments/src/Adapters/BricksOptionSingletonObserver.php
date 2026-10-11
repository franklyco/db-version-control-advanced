<?php

namespace Dbvc\Connected\Adapters;

use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\DomainObserver;

/**
 * Read-only observer for a Bricks option that is a single whole-blob
 * singleton, not a collection of keyed members (M9 slice 6b).
 *
 * Verified storage (Bricks 2.x): `bricks_breakpoints` is a list of breakpoint
 * definitions and `bricks_theme_styles` an associative map (`{global, …}`) —
 * in both cases the whole option is one logical object with no site-local
 * member ids to link across the fleet. The observer therefore hashes the
 * entire option value as one unit and reports a single instance under a
 * deterministic, site-independent identity ({@see SINGLETON_KEY}): every
 * environment reports the same `(domain, instance_uid)`, so the hub aligns the
 * singleton across sites with no name/token linking.
 *
 * Like {@see BricksOptionCollectionObserver} it reads the persisted option row
 * directly (no object cache, no `option_{$name}` filters) and never writes,
 * never assigns identity. It carries NO cross-object reference remap; a domain
 * whose blob references other objects by id (e.g. `bricks_global_settings`'s
 * palette/site-operational mix) is deliberately out of scope here.
 */
final class BricksOptionSingletonObserver implements DomainObserver
{
    /**
     * The single logical object's storage key and portable instance id. One
     * singleton per domain, identical on every site, so the domain string plus
     * this constant is a stable fleet-wide identity with no sidecar mapping.
     */
    public const SINGLETON_KEY = 'singleton';

    /**
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @param array<string, mixed> $config domain, profile, option, volatile_top_level_keys, label.
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'domain' => '',
            'profile' => '',
            'option' => '',
            'volatile_top_level_keys' => [],
            'label' => '',
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
            'shape' => 'singleton',
            'canonicalizer_version' => Canonicalizer::VERSION,
            'source_option' => $this->option_name(),
            'report' => $available,
            'prepare' => false,
            'apply' => false,
            'reason' => $available ? '' : 'bricks_not_available',
            'coverage' => [
                'whole_option' => true,
                'reference_remap' => false,
            ],
        ];
    }

    /**
     * Enumerate the singleton: exactly one member when the option is present,
     * or none when it is absent (so a complete read tombstones a removed
     * option, exactly like an emptied collection). Never reports a collection
     * order projection — there is only one object.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function inventory(array $query)
    {
        $read = $this->read_singleton();
        if ($read['status'] !== 'available') {
            return [
                'status' => $read['status'],
                'reason' => $read['reason'],
                'items' => [],
                'next_cursor' => null,
                'complete' => false,
                'order' => [],
                'order_hash' => null,
                'order_canonical' => null,
                'storage_fingerprint' => $read['storage_fingerprint'],
                'problems' => $read['problems'],
            ];
        }

        $items = [];
        if ($read['present']) {
            $items[] = [
                'storage_key' => self::SINGLETON_KEY,
                'instance_uid' => self::SINGLETON_KEY,
                'display_name' => $this->display_name(),
                'position' => 0,
                'profile' => $this->profile(),
                'complete' => $read['complete'],
                'hash' => $read['hash'],
                'canonical' => $read['canonical'],
                'problems' => $read['problems'],
            ];
        }

        return [
            'status' => 'available',
            'reason' => '',
            'items' => $items,
            'next_cursor' => null,
            // A complete read of the whole option, so verified removals are safe.
            'complete' => $read['complete'],
            'order' => [],
            'order_hash' => null,
            'order_canonical' => null,
            'storage_fingerprint' => $read['storage_fingerprint'],
            'total' => count($items),
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
        if ($storage_key !== self::SINGLETON_KEY) {
            return ['status' => 'unsupported', 'exists' => null, 'complete' => false, 'reason' => 'storage_key_required'];
        }

        $read = $this->read_singleton();
        if ($read['status'] !== 'available') {
            return [
                'status' => $read['status'],
                'exists' => null,
                'complete' => false,
                'reason' => $read['reason'],
                'profile' => $this->profile(),
            ];
        }
        if (! $read['present']) {
            return [
                'status' => 'missing',
                'exists' => false,
                'complete' => true,
                'profile' => $this->profile(),
                'instance_uid' => self::SINGLETON_KEY,
                'semantic_hash' => BricksOptionCollectionObserver::absent_hash(),
                'storage_fingerprint' => $read['storage_fingerprint'],
                'revision' => $read['storage_fingerprint'],
            ];
        }

        return [
            'status' => 'available',
            'exists' => true,
            'complete' => $read['complete'],
            'profile' => $this->profile(),
            'instance_uid' => self::SINGLETON_KEY,
            'semantic_hash' => $read['hash'],
            'canonical' => $read['canonical'],
            'storage_fingerprint' => $read['storage_fingerprint'],
            'revision' => $read['storage_fingerprint'],
            'position' => 0,
            'display_name' => $this->display_name(),
            'problems' => $read['problems'],
        ];
    }

    /**
     * @return bool
     */
    public function bricks_available()
    {
        $detected = defined('BRICKS_VERSION') || (function_exists('wp_get_theme') && wp_get_theme('bricks')->exists());

        /** This filter is shared with {@see BricksOptionCollectionObserver}. */
        return (bool) apply_filters('dbvc_connected_bricks_available', $detected, $this->domain());
    }

    /**
     * Raw container read for conditional writes: the persisted option value
     * exactly as stored, its version-1 fingerprint and the decoded value.
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
     * @return array<int, string>
     */
    public function volatile_top_level_keys()
    {
        return (array) $this->config['volatile_top_level_keys'];
    }

    /**
     * @return string
     */
    private function display_name()
    {
        $label = (string) $this->config['label'];

        return $label !== '' ? $label : self::SINGLETON_KEY;
    }

    /**
     * One persisted read of the whole option, projected as a single object.
     *
     * @return array<string, mixed>
     */
    private function read_singleton()
    {
        if (! $this->bricks_available()) {
            return [
                'status' => 'unavailable',
                'reason' => 'bricks_not_available',
                'present' => false,
                'complete' => false,
                'hash' => null,
                'canonical' => null,
                'storage_fingerprint' => null,
                'problems' => ['bricks_not_available'],
            ];
        }

        $persisted = $this->read_persisted_option();
        $storage_fingerprint = $persisted['present'] ? hash('sha256', (string) $persisted['raw']) : null;
        if (! $persisted['present']) {
            return [
                'status' => 'available',
                'reason' => '',
                'present' => false,
                'complete' => true,
                'hash' => null,
                'canonical' => null,
                'storage_fingerprint' => null,
                'problems' => [],
            ];
        }

        $projection = Canonicalizer::project($persisted['value'], (array) $this->config['volatile_top_level_keys']);

        return [
            'status' => 'available',
            'reason' => '',
            'present' => true,
            'complete' => $projection['ok'],
            'hash' => $projection['ok'] ? $projection['hash'] : BricksOptionCollectionObserver::incomplete_hash(),
            'canonical' => $projection['ok'] ? $projection['canonical'] : null,
            'storage_fingerprint' => $storage_fingerprint,
            'problems' => $projection['ok'] ? [] : ['canonicalization:' . $projection['reason']],
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
