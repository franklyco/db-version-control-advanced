<?php

namespace Dbvc\VisualEditor\Curation;

/**
 * R3-BX — Persistence for per-field curation decisions.
 *
 * Stores decisions in a single option (`dbvc_visual_editor_curation_decisions`)
 * keyed by the canonical field id ({@see FieldCandidateProvider} record id).
 * Everything is scoped to the curation tool — writes here NEVER touch any
 * Vertical content, ACF field definition, Shared Globals list, or Visual
 * Editor runtime setting. Turning off the kill switch preserves the
 * decisions, so re-enabling the tool later brings them back intact.
 */
final class CurationStore
{
    public const OPTION_KEY = 'dbvc_visual_editor_curation_decisions';

    private const DECISIONS = ['', 'include', 'ignore', 'defer'];
    private const PRIORITIES = ['', 'must', 'should', 'nice'];
    private const CATEGORY_MAX_LENGTH = 60;
    private const NOTES_MAX_LENGTH = 500;

    /**
     * Return the full decision map. Every value is a normalized array
     * even if only some fields were previously written.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAll()
    {
        $raw = get_option(self::OPTION_KEY, []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $id => $decision) {
            $sanitized_id = $this->sanitizeId((string) $id);
            if ($sanitized_id === '') {
                continue;
            }
            if (! is_array($decision)) {
                continue;
            }
            $out[$sanitized_id] = $this->normalizeDecision($decision);
        }

        return $out;
    }

    /**
     * @param string $id
     * @return array<string, mixed>
     */
    public function getDecision($id)
    {
        $all = $this->getAll();
        $sanitized = $this->sanitizeId((string) $id);
        if ($sanitized === '' || ! isset($all[$sanitized])) {
            return $this->emptyDecision();
        }

        return $all[$sanitized];
    }

    /**
     * Write one decision. Returns true when the option was persisted
     * without error. Empty decisions (all fields cleared) prune the id
     * from the map so the option doesn't accumulate ghost entries.
     *
     * @param string               $id
     * @param array<string, mixed> $partial
     * @return bool
     */
    public function setDecision($id, array $partial)
    {
        $sanitized_id = $this->sanitizeId((string) $id);
        if ($sanitized_id === '') {
            return false;
        }

        $all = $this->getAll();
        $existing = isset($all[$sanitized_id]) ? $all[$sanitized_id] : $this->emptyDecision();
        $merged = $this->normalizeDecision(array_merge($existing, $partial), true);

        if ($this->isEmptyDecision($merged)) {
            unset($all[$sanitized_id]);
        } else {
            $all[$sanitized_id] = $merged;
        }

        return (bool) update_option(self::OPTION_KEY, $all, false);
    }

    /**
     * Apply the same partial decision to every id in $ids. Any id that
     * fails validation is silently skipped; the return value reports
     * the count of ids that succeeded.
     *
     * @param array<int, string>   $ids
     * @param array<string, mixed> $partial
     * @return int
     */
    public function setDecisionsBulk(array $ids, array $partial)
    {
        $all = $this->getAll();
        $written = 0;

        foreach ($ids as $id) {
            $sanitized_id = $this->sanitizeId((string) $id);
            if ($sanitized_id === '') {
                continue;
            }
            $existing = isset($all[$sanitized_id]) ? $all[$sanitized_id] : $this->emptyDecision();
            $merged = $this->normalizeDecision(array_merge($existing, $partial), true);

            if ($this->isEmptyDecision($merged)) {
                unset($all[$sanitized_id]);
            } else {
                $all[$sanitized_id] = $merged;
            }
            $written++;
        }

        if ($written === 0) {
            return 0;
        }

        return update_option(self::OPTION_KEY, $all, false) ? $written : 0;
    }

    /**
     * Apply a DIFFERENT partial decision to each id in the map. Unlike
     * setDecisionsBulk (which broadcasts one partial to many ids), this method
     * accepts a `[id => partial]` shape so the caller can write, e.g., a
     * per-row suggested priority in a single option write. Sanitisation +
     * validation runs per id (same as setDecision); malformed ids are silently
     * skipped and counted in the return value's `skipped`.
     *
     * @param array<string, array<string, mixed>> $map Keyed by canonical id.
     * @return array{written:int,skipped:int}
     */
    public function setDecisionsPerId(array $map)
    {
        $all = $this->getAll();
        $written = 0;
        $skipped = 0;

        foreach ($map as $id => $partial) {
            $sanitized_id = $this->sanitizeId((string) $id);
            if ($sanitized_id === '' || ! is_array($partial)) {
                $skipped++;
                continue;
            }
            $existing = isset($all[$sanitized_id]) ? $all[$sanitized_id] : $this->emptyDecision();
            $merged = $this->normalizeDecision(array_merge($existing, $partial), true);

            if ($this->isEmptyDecision($merged)) {
                unset($all[$sanitized_id]);
            } else {
                $all[$sanitized_id] = $merged;
            }
            $written++;
        }

        if ($written === 0) {
            return ['written' => 0, 'skipped' => $skipped];
        }
        $ok = update_option(self::OPTION_KEY, $all, false);

        return [
            'written' => $ok ? $written : 0,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param string $id
     * @return bool
     */
    public function clearDecision($id)
    {
        $sanitized_id = $this->sanitizeId((string) $id);
        if ($sanitized_id === '') {
            return false;
        }

        $all = $this->getAll();
        if (! isset($all[$sanitized_id])) {
            return true;
        }

        unset($all[$sanitized_id]);

        return (bool) update_option(self::OPTION_KEY, $all, false);
    }

    /**
     * @return array{include:int,ignore:int,defer:int,undecided:int,total:int}
     */
    public function summarize(array $candidates)
    {
        $decisions = $this->getAll();
        $summary = ['include' => 0, 'ignore' => 0, 'defer' => 0, 'undecided' => 0, 'total' => 0];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || empty($candidate['id'])) {
                continue;
            }
            $summary['total']++;
            $id = (string) $candidate['id'];
            $decision = isset($decisions[$id]) ? (string) $decisions[$id]['decision'] : '';
            if ($decision === 'include' || $decision === 'ignore' || $decision === 'defer') {
                $summary[$decision]++;
            } else {
                $summary['undecided']++;
            }
        }

        return $summary;
    }

    /**
     * @param string $id
     * @return string
     */
    private function sanitizeId($id)
    {
        $id = trim((string) $id);
        if ($id === '') {
            return '';
        }
        // Canonical shape: option:{slug}:{name_path}
        // Allowed: a-z, 0-9, `_`, `-`, `>`, `:`.
        if (! preg_match('/^option:[a-z0-9_\-]+:[a-z0-9_\->]+$/i', $id)) {
            return '';
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $decision
     * @param bool                 $stamp_meta
     * @return array<string, mixed>
     */
    private function normalizeDecision(array $decision, $stamp_meta = false)
    {
        $normalized = $this->emptyDecision();

        $decision_value = isset($decision['decision']) ? (string) $decision['decision'] : '';
        if (in_array($decision_value, self::DECISIONS, true)) {
            $normalized['decision'] = $decision_value;
        }

        $priority = isset($decision['client_priority']) ? (string) $decision['client_priority'] : '';
        if (in_array($priority, self::PRIORITIES, true)) {
            $normalized['client_priority'] = $priority;
        }

        $category = isset($decision['category']) ? sanitize_text_field((string) $decision['category']) : '';
        if (strlen($category) > self::CATEGORY_MAX_LENGTH) {
            $category = substr($category, 0, self::CATEGORY_MAX_LENGTH);
        }
        $normalized['category'] = $category;

        $group = isset($decision['group']) ? sanitize_text_field((string) $decision['group']) : '';
        if (strlen($group) > self::CATEGORY_MAX_LENGTH) {
            $group = substr($group, 0, self::CATEGORY_MAX_LENGTH);
        }
        $normalized['group'] = $group;

        $notes = isset($decision['notes']) ? sanitize_textarea_field((string) $decision['notes']) : '';
        if (strlen($notes) > self::NOTES_MAX_LENGTH) {
            $notes = substr($notes, 0, self::NOTES_MAX_LENGTH);
        }
        $normalized['notes'] = $notes;

        $decided_at = isset($decision['decided_at']) ? absint($decision['decided_at']) : 0;
        $decided_by = isset($decision['decided_by']) ? absint($decision['decided_by']) : 0;

        if ($stamp_meta && ! $this->isEmptyDecision($normalized)) {
            $decided_at = time();
            if (function_exists('get_current_user_id')) {
                $decided_by = (int) get_current_user_id();
            }
        }

        $normalized['decided_at'] = $decided_at;
        $normalized['decided_by'] = $decided_by;

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDecision()
    {
        return [
            'decision' => '',
            'client_priority' => '',
            'category' => '',
            'group' => '',
            'notes' => '',
            'decided_at' => 0,
            'decided_by' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $decision
     * @return bool
     */
    private function isEmptyDecision(array $decision)
    {
        return ($decision['decision'] ?? '') === ''
            && ($decision['client_priority'] ?? '') === ''
            && ($decision['category'] ?? '') === ''
            && ($decision['group'] ?? '') === ''
            && ($decision['notes'] ?? '') === '';
    }
}
