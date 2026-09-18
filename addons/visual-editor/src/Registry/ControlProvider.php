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

    /**
     * R3-C-1 — mint an authoritative {@see EditableDescriptor} at open time from
     * one of THIS provider's records. Called by
     * {@see ControlRegistry::buildDescriptorForRecord} for a record whose
     * `providerId` matches this provider. The record's opaque `source` bag is
     * available on `$record->source` for the provider's own resolution logic.
     *
     * Returns null when the record no longer resolves — e.g. the underlying
     * ACF field was deleted between list-time and open-time, its type changed
     * to something the provider does not support, capability was revoked, or
     * some other structural precondition failed. Callers MUST fail closed on
     * null (typically 404 or 403).
     *
     * The returned descriptor is capability-neutral — the caller
     * (`ControlCenterOpenController`) still re-checks
     * {@see \Dbvc\VisualEditor\Permissions\CapabilityManager::canEditDescriptor}
     * before attaching it to the session; this method must not assume the
     * current user is authorized.
     *
     * @param ControlRecord        $record       One of THIS provider's records.
     * @param string               $sessionId    Visual Editor session id the
     *                                            resulting descriptor will be
     *                                            attached to.
     * @param array<string, mixed> $pageContext  The session's page context
     *                                            (frontend entity/page metadata).
     * @return EditableDescriptor|null
     */
    public function buildDescriptor(ControlRecord $record, $sessionId, array $pageContext);

    /**
     * R4-A — mint a per-family value summary for one of THIS provider's
     * records. Called by {@see ControlRegistry::buildValueSummaryForRecord}
     * from the R4-A `ControlCenterValueSummariesController` batch endpoint.
     *
     * The summary is a display-only, opaque per-family shape the drawer
     * renders as the row's right-side chip (mockup DESIGN-DECISIONS §5;
     * COMPONENT-NOTES §3 lists the shape per family). Providers MUST:
     *
     * - Escape every text field before returning it — the frontend renders
     *   with `textContent`, so raw HTML would be inert, but returning
     *   pre-escaped strings keeps the contract honest.
     * - For image / gallery families, return only `attachmentId` +
     *   pre-signed URLs — never raw uploaded URLs from unvalidated input.
     * - Recheck capability against the record's owner before returning any
     *   value — the summary is a read-model that surfaces owned data.
     *
     * Returns null when the record has no summary today (family without a
     * factory yet, empty value, provider not yet participating in R4-A
     * value-summary emission). The frontend renders nothing in that case —
     * `record.meta.hasValueSummary=false` is the same signal at list-load
     * time.
     *
     * @param ControlRecord $record     One of THIS provider's records.
     * @param string        $sessionId  Visual Editor session id (opaque to
     *                                  the summary; providers rarely need it,
     *                                  but pass-through matches
     *                                  {@see buildDescriptor}'s signature).
     * @return array<string, mixed>|null
     */
    public function buildValueSummary(ControlRecord $record, $sessionId);
}
