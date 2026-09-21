<?php

namespace Dbvc\Connected\Capture;

use Dbvc\Connected\Storage\JobStore;

/**
 * Small save-side port. Validates the domain allowlist, keeps only opaque
 * context hints, records the dirty marker and schedules processing. No
 * option values, secrets, network calls or fleet work happen here.
 */
final class DirtyCapture
{
    public const CRON_HOOK = 'dbvc_connected_process_observations';
    public const DELIVERY_CRON_HOOK = 'dbvc_connected_deliver_outbox';
    public const INBOX_CRON_HOOK = 'dbvc_connected_poll_inbox';
    public const DEFAULT_INBOX_POLL_SECONDS = 60;
    public const DEFAULT_DELAY_SECONDS = 20;

    /**
     * @var JobStore
     */
    private $store;

    /**
     * @var array<int, string>
     */
    private $domains;

    /**
     * @param JobStore           $store
     * @param array<int, string> $domains
     */
    public function __construct(JobStore $store, array $domains)
    {
        $this->store = $store;
        $this->domains = array_values(array_map('strval', $domains));
    }

    /**
     * @param string               $domain
     * @param string               $object_key
     * @param array<string, mixed> $context
     * @return int|false Resulting generation, or false when ignored/failed.
     */
    public function signal($domain, $object_key, array $context = [])
    {
        if (! in_array($domain, $this->domains, true) || ! is_string($object_key) || $object_key === '') {
            return false;
        }

        $allowed = array_intersect_key($context, ['origin' => true, 'causation_id' => true, 'source_hook' => true]);
        foreach ($allowed as $key => $value) {
            $allowed[$key] = is_scalar($value) ? substr((string) $value, 0, 128) : null;
        }

        $delay = self::processing_delay();
        $generation = $this->store->mark_dirty($domain, $object_key, $allowed, $delay);
        if ($generation === null) {
            return false;
        }

        self::schedule_processing($delay);

        return $generation;
    }

    /**
     * Explicit reconciliation request for a whole domain (re-enable, lost
     * signals, manual database edits). Events produced from it carry
     * `origin=reconciliation`.
     *
     * @param string $domain
     * @param string $causation_id
     * @return int|false
     */
    public function signal_reconciliation($domain, $causation_id = '')
    {
        return $this->signal($domain, \Dbvc\Connected\Adapters\DomainRegistry::COLLECTION_KEY, [
            'origin' => 'reconciliation',
            'causation_id' => $causation_id !== '' ? $causation_id : null,
            'source_hook' => 'reconciliation',
        ]);
    }

    /**
     * @return int
     */
    public static function processing_delay()
    {
        /**
         * Seconds between a save-side signal and background observation.
         *
         * @param int $delay
         */
        $delay = apply_filters('dbvc_connected_processing_delay', self::DEFAULT_DELAY_SECONDS);

        return max(0, min(3600, (int) $delay));
    }

    /**
     * Schedule one background run unless one is already pending.
     *
     * @param int $delay
     * @return bool
     */
    public static function schedule_processing($delay)
    {
        if (! function_exists('wp_next_scheduled') || wp_next_scheduled(self::CRON_HOOK)) {
            return false;
        }

        $scheduled = wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_HOOK);

        return $scheduled === true;
    }

    /**
     * Schedule one outbound delivery run unless one is already pending.
     *
     * @param int $delay
     * @return bool
     */
    public static function schedule_delivery($delay)
    {
        if (! function_exists('wp_next_scheduled') || wp_next_scheduled(self::DELIVERY_CRON_HOOK)) {
            return false;
        }

        return wp_schedule_single_event(time() + max(1, (int) $delay), self::DELIVERY_CRON_HOOK) === true;
    }

    /**
     * Self-rescheduling inbox poll (single events with jitter; WP-Cron has no
     * built-in minute interval and a LocalWP site needs an explicit runner anyway).
     *
     * @param int|null $delay Seconds; null uses the poll interval plus jitter.
     * @return bool
     */
    public static function schedule_inbox_poll($delay = null)
    {
        if (! function_exists('wp_next_scheduled') || wp_next_scheduled(self::INBOX_CRON_HOOK)) {
            return false;
        }
        if ($delay === null) {
            /**
             * Seconds between inbox polls while enrolled.
             *
             * @param int $seconds
             */
            $interval = max(15, min(3600, (int) apply_filters('dbvc_connected_inbox_poll_interval', self::DEFAULT_INBOX_POLL_SECONDS)));
            $delay = $interval + wp_rand(0, (int) max(1, $interval / 5));
        }

        return wp_schedule_single_event(time() + max(1, (int) $delay), self::INBOX_CRON_HOOK) === true;
    }

    /**
     * @return void
     */
    public static function unschedule_processing()
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_clear_scheduled_hook(self::DELIVERY_CRON_HOOK);
            wp_clear_scheduled_hook(self::INBOX_CRON_HOOK);
        }
    }
}
