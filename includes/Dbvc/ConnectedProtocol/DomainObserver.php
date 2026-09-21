<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Read-only domain port.
 *
 * Implementations read persisted authoritative state and return projections.
 * They never write, export, call the network, or assign identity: a missing
 * identity mapping is reported as `identity_missing`, not backfilled.
 */
interface DomainObserver
{
    /**
     * @return string Domain identifier, e.g. `bricks.global_class`.
     */
    public function domain();

    /**
     * @return string Projection profile identifier, e.g. `bricks-global-class-v1`.
     */
    public function profile();

    /**
     * Report domain/profile versions, completeness limits and capabilities.
     *
     * @return array<string, mixed> Includes `report`, `prepare`, `apply` booleans and `reason` when unavailable.
     */
    public function capabilities();

    /**
     * Bounded enumeration of the current collection.
     *
     * @param array<string, mixed> $query `cursor` (int offset) and `limit` (int, 0 = unbounded).
     * @return array<string, mixed> `status` (available|unavailable|unsupported), `items`, `next_cursor`,
     *                              `complete` (true only when every member was enumerated by this call),
     *                              `order` (ordered storage keys when complete), `storage_fingerprint`, `problems`.
     */
    public function inventory(array $query);

    /**
     * Read one logical object.
     *
     * @param array<string, mixed> $identity `storage_key` for the domain's native key.
     * @return array<string, mixed> `status` (available|unavailable|unsupported|missing), `exists`,
     *                              `complete`, `profile`, `semantic_hash`, `storage_fingerprint`, `revision`.
     */
    public function snapshot(array $identity);
}
