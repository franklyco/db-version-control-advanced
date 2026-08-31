<?php

namespace Dbvc\VisualEditor\Registry;

/**
 * R3-A — provider-agnostic control registry.
 *
 * A discovery surface for controls that live outside the current render — Shared
 * Globals is the first {@see ControlProvider} adapted onto it (R3-B). The registry
 * collects providers, validates and normalizes each returned {@see ControlRecord},
 * rejects duplicates (observable via `dbvc_visual_editor_control_registry_invalid`
 * for developers/debugging), and re-applies per-user visibility on every list
 * call so the same provider output can serve different users cheaply.
 *
 * **This is not a write authority.** A registered control opens through the
 * existing {@see EditableRegistry}/`MediaFindingDescriptorBridge`/`MutationService`
 * pipeline; the registry is a discovery-only read model (matches the R3 plan's
 * "creates no new write authority" contract).
 *
 * The R3 plan's slice gate: registry output is deterministic, filtered, and
 * cannot introduce write authority. Slice R3-B adds the Shared Globals compat
 * provider; R3-C adds the minimal center UI + open route; R3-D hardens.
 */
final class ControlRegistry
{
    /**
     * @var array<string, ControlProvider>
     */
    private $providers = [];

    /**
     * Registration reasons for a rejected provider or record, observable via
     * the `dbvc_visual_editor_control_registry_invalid` action.
     */
    private const REJECT_PROVIDER_INVALID_ID = 'provider_invalid_id';
    private const REJECT_PROVIDER_DUPLICATE = 'provider_duplicate';
    private const REJECT_RECORD_INVALID = 'record_invalid';
    private const REJECT_RECORD_DUPLICATE_LOCAL_ID = 'record_duplicate_local_id';

    /**
     * Register a provider. Returns true on success, false on rejection (a
     * duplicate provider id, or an id that fails `sanitize_key`).
     *
     * Rejection fires `dbvc_visual_editor_control_registry_invalid` with
     * `$reason` and `$context` so a developer can observe misregistration
     * during test/QA without breaking the runtime.
     *
     * @param ControlProvider $provider
     * @return bool
     */
    public function registerProvider(ControlProvider $provider)
    {
        $id = sanitize_key((string) $provider->getProviderId());
        if ($id === '') {
            $this->observeInvalid(self::REJECT_PROVIDER_INVALID_ID, [
                'class' => get_class($provider),
            ]);

            return false;
        }
        if (isset($this->providers[$id])) {
            $this->observeInvalid(self::REJECT_PROVIDER_DUPLICATE, [
                'provider_id' => $id,
                'class' => get_class($provider),
            ]);

            return false;
        }

        $this->providers[$id] = $provider;

        return true;
    }

    /**
     * True when a provider with this id is registered.
     *
     * @param string $providerId
     * @return bool
     */
    public function hasProvider($providerId)
    {
        return isset($this->providers[sanitize_key((string) $providerId)]);
    }

    /**
     * Currently-registered provider ids, sorted for deterministic output.
     *
     * @return array<int, string>
     */
    public function providerIds()
    {
        $ids = array_keys($this->providers);
        sort($ids);

        return $ids;
    }

    /**
     * List every registered control the CURRENT user can see. Returns the safe
     * summary shape from {@see ControlRecord::toListItem} (no `source` bag —
     * that stays internal to the provider so a client cannot re-target it).
     *
     * Filtering is applied in this order so upstream category/status filters
     * cheaply prune records BEFORE the per-user visibility gate runs:
     * category → status → visibility. Records are returned in stable order
     * (providerId asc, then record id asc) so tests can pin expected output.
     *
     * @param array<string, mixed> $args Keys: category (string), status (string).
     * @return array<int, array<string, mixed>>
     */
    public function listControls(array $args = [])
    {
        $category_filter = isset($args['category']) ? sanitize_key((string) $args['category']) : '';
        $status_filter = isset($args['status']) ? sanitize_key((string) $args['status']) : '';

        $records = $this->collectValidRecords();
        $items = [];

        foreach ($records as $record) {
            if ($category_filter !== '' && $record->category !== $category_filter) {
                continue;
            }
            if ($status_filter !== '' && $record->status !== $status_filter) {
                continue;
            }
            if (! $record->isVisibleToCurrentUser()) {
                continue;
            }
            $items[] = $record->toListItem();
        }

        return $items;
    }

    /**
     * Resolve one record by its public id (`{providerId}:{id}`) for the current
     * user. Returns null when the record is unknown, malformed, or the current
     * user cannot see it — callers MUST fail closed on null.
     *
     * The `source` bag is included here because this method is the internal
     * bridge R3-C uses to hand the provider back its own opaque hint at open
     * time; it never crosses a REST boundary as-is.
     *
     * @param string $publicId
     * @return ControlRecord|null
     */
    public function getVisibleRecord($publicId)
    {
        $publicId = (string) $publicId;
        if ($publicId === '' || strpos($publicId, ':') === false) {
            return null;
        }
        [$providerId, $localId] = explode(':', $publicId, 2);
        $providerId = sanitize_key($providerId);
        $localId = sanitize_key($localId);
        if ($providerId === '' || $localId === '' || ! isset($this->providers[$providerId])) {
            return null;
        }

        $records = $this->collectValidRecords();
        foreach ($records as $record) {
            if ($record->providerId === $providerId && $record->id === $localId) {
                return $record->isVisibleToCurrentUser() ? $record : null;
            }
        }

        return null;
    }

    /**
     * Deterministic list of every valid, de-duplicated record across every
     * provider. Duplicate local ids within one provider are rejected (observed);
     * the same local id across two different providers is kept because their
     * public ids differ.
     *
     * @return array<int, ControlRecord>
     */
    private function collectValidRecords()
    {
        $records = [];
        $provider_ids = array_keys($this->providers);
        sort($provider_ids);

        foreach ($provider_ids as $provider_id) {
            $provider = $this->providers[$provider_id];
            $raw = $provider->getControls();
            if (! is_array($raw)) {
                continue;
            }

            $seen_local_ids = [];
            $provider_records = [];
            foreach ($raw as $entry) {
                $record = ControlRecord::fromArray($entry, $provider_id);
                if ($record === null) {
                    $this->observeInvalid(self::REJECT_RECORD_INVALID, [
                        'provider_id' => $provider_id,
                    ]);
                    continue;
                }
                if (isset($seen_local_ids[$record->id])) {
                    $this->observeInvalid(self::REJECT_RECORD_DUPLICATE_LOCAL_ID, [
                        'provider_id' => $provider_id,
                        'local_id' => $record->id,
                    ]);
                    continue;
                }
                $seen_local_ids[$record->id] = true;
                $provider_records[] = $record;
            }

            usort($provider_records, static function (ControlRecord $a, ControlRecord $b) {
                return strcmp($a->id, $b->id);
            });

            foreach ($provider_records as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param string               $reason
     * @param array<string, mixed> $context
     * @return void
     */
    private function observeInvalid($reason, array $context)
    {
        /**
         * Fires when the control registry rejects a provider registration or a
         * returned record during validation. Useful for developer/QA debugging;
         * consumers should NOT do heavy work in-hook.
         *
         * @param string               $reason  One of the REJECT_* constants
         *                                       (e.g. `provider_duplicate`,
         *                                       `record_invalid`).
         * @param array<string, mixed> $context Structured context about the
         *                                       rejection (provider id, class,
         *                                       local id, etc.). Never carries
         *                                       raw target values.
         */
        do_action('dbvc_visual_editor_control_registry_invalid', sanitize_key((string) $reason), $context);
    }
}
