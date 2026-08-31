<?php

namespace Dbvc\VisualEditor\Registry;

/**
 * R3-A — provider contract for the {@see ControlRegistry}.
 *
 * A control provider is a small, focused source of registered controls (Shared
 * Globals is the first one — R3-B adapts the existing `SharedGlobalFieldsController`
 * into a provider). A provider exposes a stable id + a list of {@see ControlRecord}s.
 * The registry never trusts a record's identity — it re-validates and re-normalizes
 * on ingest, and every list request re-checks user visibility before returning.
 *
 * Providers **do not** grant edit permission. A registered control opens through the
 * existing {@see EditableRegistry}/`MediaFindingDescriptorBridge`/`MutationService`
 * pipeline; the registry is a discovery surface, not a write authority.
 */
interface ControlProvider
{
    /**
     * A stable, kebab-case-ish key that identifies this provider across sessions.
     * Used to namespace public control ids so two providers can safely register
     * the same local id. Must be non-empty and pass `sanitize_key()` unchanged.
     *
     * @return string
     */
    public function getProviderId();

    /**
     * Return every control this provider knows about right now. The registry
     * validates and de-duplicates the returned records; a provider may return
     * loosely-shaped arrays and let {@see ControlRecord::fromArray} coerce them.
     *
     * Providers must NOT do capability filtering here — that's the registry's
     * job, so the same provider list can serve different users without repeating
     * work. Providers may pre-filter by structural eligibility (excluded post
     * type, missing ACF field, etc.) — those aren't per-user.
     *
     * @return array<int, ControlRecord|array<string, mixed>>
     */
    public function getControls();
}
