<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Pure classification of already validated comparable hashes. Not a normalizer.
 *
 * Environment three-way states and framework status are independent
 * comparisons (see docs/dropins/dbvc-connected-agency/docs/04-identity-and-drift.md).
 * String inequality of versions yields `version_differs`; only a trusted release
 * ordering can refine that to behind/ahead/channel mismatch.
 */
final class Comparison
{
    /**
     * @param string|null        $base     Last mutually confirmed hash, or null when no baseline exists.
     * @param string|null        $source   Current source hash, or null when unavailable.
     * @param string|null        $target   Current target hash, or null when unavailable.
     * @param array<int, string> $profiles Profiles of base, source and target; must be three identical values.
     * @param bool               $complete Every participating projection was complete.
     * @param bool               $fresh    Every participating projection is within the freshness policy.
     * @return string unknown|baseline_required|synchronized|converged|outgoing|incoming|conflict
     */
    public static function environment($base, $source, $target, array $profiles, $complete = true, $fresh = true)
    {
        if (
            ! $complete || ! $fresh || count($profiles) !== 3
            || count(array_unique($profiles, SORT_STRING)) !== 1 || $profiles[0] === ''
            || $source === null || $target === null
        ) {
            return 'unknown';
        }
        if ($base === null) {
            return 'baseline_required';
        }
        if ($source === $target) {
            return $source === $base ? 'synchronized' : 'converged';
        }
        if ($target === $base) {
            return 'outgoing';
        }
        if ($source === $base) {
            return 'incoming';
        }

        return 'conflict';
    }

    /**
     * @param string|null $actual          Current instance hash.
     * @param string|null $adopted_hash    Hash of the adopted framework definition version.
     * @param string|null $adopted_version Adopted framework version label.
     * @param string|null $desired_version Desired framework version label.
     * @param string|null $override_hash   Approved override hash, when present.
     * @return array{drift: string, version: string}
     */
    public static function framework($actual, $adopted_hash, $adopted_version, $desired_version, $override_hash = null)
    {
        $version = ($adopted_version === null || $desired_version === null)
            ? 'unknown'
            : ($adopted_version === $desired_version ? 'current' : 'version_differs');

        if ($actual === null || $adopted_hash === null) {
            return ['drift' => 'unknown', 'version' => $version];
        }

        $drift = $override_hash !== null
            ? ($actual === $override_hash ? 'approved_override' : 'override_changed')
            : ($actual === $adopted_hash ? 'clean' : 'local_drift');

        return ['drift' => $drift, 'version' => $version];
    }
}
