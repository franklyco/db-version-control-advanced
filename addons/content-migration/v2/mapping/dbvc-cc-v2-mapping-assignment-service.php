<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Mapping_Assignment_Service
{
    /**
     * @var DBVC_CC_V2_Mapping_Assignment_Service|null
     */
    private static $instance = null;

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private $slot_lookup_cache = [];

    /**
     * @return DBVC_CC_V2_Mapping_Assignment_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string               $domain
     * @param array<string, mixed> $mapping_index_artifact
     * @return array<string, mixed>
     */
    public function assign_content_items($domain, array $mapping_index_artifact)
    {
        $slot_lookup = $this->load_slot_lookup($domain);
        $content_items = isset($mapping_index_artifact['content_items']) && is_array($mapping_index_artifact['content_items'])
            ? $mapping_index_artifact['content_items']
            : [];
        $existing_unresolved = isset($mapping_index_artifact['unresolved_items']) && is_array($mapping_index_artifact['unresolved_items'])
            ? array_values($mapping_index_artifact['unresolved_items'])
            : [];

        $selected_items = [];
        $unresolved_items = $existing_unresolved;
        $claimed_targets = [];
        $claimed_competition_groups = [];
        $context_group_counts = [];
        $section_group_counts = [];

        foreach ($content_items as $content_item) {
            if (! is_array($content_item)) {
                continue;
            }

            $decision = $this->evaluate_content_item(
                $content_item,
                $slot_lookup,
                $claimed_targets,
                $claimed_competition_groups,
                $context_group_counts,
                $section_group_counts
            );
            if (empty($decision['selected_candidate']) || ! is_array($decision['selected_candidate'])) {
                continue;
            }

            $selected_candidate = $decision['selected_candidate'];
            $selection = isset($decision['selection']) && is_array($decision['selection']) ? $decision['selection'] : [];
            $target_ref = isset($selected_candidate['target_ref']) ? (string) $selected_candidate['target_ref'] : '';

            $selected_item = $content_item;
            $selected_item['selected_candidate'] = $selected_candidate;
            $selected_item['selection'] = $selection;
            $selected_items[] = $selected_item;

            if (
                $target_ref !== ''
                && (
                    empty($selection['default_decision'])
                    || (isset($selection['default_decision']) && (string) $selection['default_decision'] !== 'unresolved')
                )
            ) {
                if (! $this->target_ref_is_repeatable($target_ref)) {
                    $claimed_targets[$target_ref] = isset($content_item['item_id']) ? (string) $content_item['item_id'] : '';
                }

                $group_key = $this->resolve_group_key($selected_candidate, $slot_lookup);
                $context_tag = isset($content_item['context_tag']) ? sanitize_key((string) $content_item['context_tag']) : '';
                $section_family = $this->resolve_section_family($selected_candidate, $slot_lookup, $context_tag);
                $competition_group = $this->resolve_competition_group($selected_candidate, $slot_lookup);

                if ($context_tag !== '' && $group_key !== '') {
                    if (! isset($context_group_counts[$context_tag])) {
                        $context_group_counts[$context_tag] = [];
                    }
                    if (! isset($context_group_counts[$context_tag][$group_key])) {
                        $context_group_counts[$context_tag][$group_key] = 0;
                    }
                    ++$context_group_counts[$context_tag][$group_key];
                }

                if ($section_family !== '' && $group_key !== '') {
                    if (! isset($section_group_counts[$section_family])) {
                        $section_group_counts[$section_family] = [];
                    }
                    if (! isset($section_group_counts[$section_family][$group_key])) {
                        $section_group_counts[$section_family][$group_key] = 0;
                    }
                    ++$section_group_counts[$section_family][$group_key];
                }

                if ($competition_group !== '') {
                    $claimed_competition_groups[$competition_group] = isset($content_item['item_id']) ? (string) $content_item['item_id'] : $target_ref;
                }
            }

            if (! empty($selection['default_decision']) && (string) $selection['default_decision'] === 'unresolved') {
                $unresolved_items[] = $this->build_unresolved_item($content_item, $selected_candidate, $selection);
            }
        }

        return [
            'selected_items' => array_values($selected_items),
            'unresolved_items' => $this->dedupe_unresolved_items($unresolved_items),
            'claimed_targets' => $claimed_targets,
        ];
    }

    /**
     * @param array<string, mixed>           $content_item
     * @param array<string, array<string, mixed>> $slot_lookup
     * @param array<string, string>          $claimed_targets
     * @param array<string, string>          $claimed_competition_groups
     * @param array<string, array<string, int>> $context_group_counts
     * @param array<string, array<string, int>> $section_group_counts
     * @return array<string, mixed>
     */
    private function evaluate_content_item(
        array $content_item,
        array $slot_lookup,
        array $claimed_targets,
        array $claimed_competition_groups,
        array $context_group_counts,
        array $section_group_counts
    ) {
        $candidates = isset($content_item['target_candidates']) && is_array($content_item['target_candidates'])
            ? array_values($content_item['target_candidates'])
            : [];
        if (empty($candidates)) {
            return [
                'selected_candidate' => [],
                'selection' => [],
            ];
        }

        $evaluated = [];
        foreach ($candidates as $index => $candidate) {
            if (! is_array($candidate) || empty($candidate['target_ref'])) {
                continue;
            }

            $evaluated[] = $this->evaluate_candidate(
                $content_item,
                $candidate,
                $index,
                $slot_lookup,
                $claimed_targets,
                $claimed_competition_groups,
                $context_group_counts,
                $section_group_counts
            );
        }

        if (empty($evaluated)) {
            return [
                'selected_candidate' => [],
                'selection' => [],
            ];
        }

        usort(
            $evaluated,
            static function ($left, $right) {
                $left_adjusted = isset($left['adjusted_confidence']) ? (float) $left['adjusted_confidence'] : 0.0;
                $right_adjusted = isset($right['adjusted_confidence']) ? (float) $right['adjusted_confidence'] : 0.0;
                if ($left_adjusted === $right_adjusted) {
                    $left_raw = isset($left['confidence']) ? (float) $left['confidence'] : 0.0;
                    $right_raw = isset($right['confidence']) ? (float) $right['confidence'] : 0.0;
                    if ($left_raw === $right_raw) {
                        return strnatcasecmp(
                            isset($left['target_ref']) ? (string) $left['target_ref'] : '',
                            isset($right['target_ref']) ? (string) $right['target_ref'] : ''
                        );
                    }

                    return $left_raw < $right_raw ? 1 : -1;
                }

                return $left_adjusted < $right_adjusted ? 1 : -1;
            }
        );

        $best = $evaluated[0];
        $next = isset($evaluated[1]) ? $evaluated[1] : [];
        $item_type = isset($content_item['item_type']) ? sanitize_key((string) $content_item['item_type']) : '';
        $margin_to_next = round(
            max(
                0.0,
                (float) (isset($best['adjusted_confidence']) ? $best['adjusted_confidence'] : 0.0)
                - (float) (isset($next['adjusted_confidence']) ? $next['adjusted_confidence'] : 0.0)
            ),
            2
        );

        $reason_codes = [];
        $status = 'selected';
        $default_decision = 'approve';

        $is_section_item = $item_type === 'section';
        if ($is_section_item && count($evaluated) > 1 && $margin_to_next < 0.05) {
            $status = 'ambiguous';
            $default_decision = 'unresolved';
            $reason_codes[] = 'low_margin';
        }

        if ($is_section_item && (float) $best['adjusted_confidence'] < 0.76) {
            $status = 'ambiguous';
            $default_decision = 'unresolved';
            $reason_codes[] = 'low_adjusted_confidence';
        }

        if ($is_section_item && ! empty($best['duplicate_penalty_applied']) && count($evaluated) > 1) {
            $status = 'ambiguous';
            $default_decision = 'unresolved';
            $reason_codes[] = 'duplicate_target_pressure';
        }

        if ($is_section_item && ! empty($best['competition_penalty_applied']) && count($evaluated) > 1 && $margin_to_next < 0.08) {
            $status = 'ambiguous';
            $default_decision = 'unresolved';
            $reason_codes[] = 'competition_group_pressure';
        }

        if (! empty($best['winner_changed'])) {
            $reason_codes[] = 'reassigned_after_coherence';
        }

        $unresolved_class = '';
        if ($default_decision === 'unresolved') {
            $unresolved_class = $this->resolve_unresolved_class($content_item, $reason_codes);
        }

        $selected_frontier = $best;
        if ($default_decision === 'unresolved') {
            $selected_frontier = $this->find_raw_frontier_candidate($evaluated);
        }

        $selected_candidate = [
            'target_ref' => isset($selected_frontier['target_ref']) ? (string) $selected_frontier['target_ref'] : '',
            'confidence' => round((float) (isset($selected_frontier['display_confidence']) ? $selected_frontier['display_confidence'] : (isset($selected_frontier['confidence']) ? $selected_frontier['confidence'] : 0.0)), 2),
            'reason' => isset($selected_frontier['reason']) ? sanitize_key((string) $selected_frontier['reason']) : '',
            'pattern_key' => isset($selected_frontier['pattern_key']) ? sanitize_key((string) $selected_frontier['pattern_key']) : '',
        ];

        return [
            'selected_candidate' => $selected_candidate,
            'selection' => [
                'status' => $status,
                'default_decision' => $default_decision,
                'adjusted_confidence' => min(0.99, round((float) (isset($best['adjusted_confidence']) ? $best['adjusted_confidence'] : 0.0), 2)),
                'raw_confidence' => round((float) (isset($best['confidence']) ? $best['confidence'] : 0.0), 4),
                'margin_to_next' => $margin_to_next,
                'candidate_count' => count($evaluated),
                'selected_rank' => (int) (isset($selected_frontier['raw_rank']) ? $selected_frontier['raw_rank'] : 0) + 1,
                'reason_codes' => array_values(array_unique($reason_codes)),
                'unresolved_class' => $unresolved_class,
                'alternatives' => $this->build_selection_alternatives($evaluated),
            ],
        ];
    }

    /**
     * @param array<string, mixed>           $content_item
     * @param array<string, mixed>           $candidate
     * @param int                            $index
     * @param array<string, array<string, mixed>> $slot_lookup
     * @param array<string, string>          $claimed_targets
     * @param array<string, string>          $claimed_competition_groups
     * @param array<string, array<string, int>> $context_group_counts
     * @param array<string, array<string, int>> $section_group_counts
     * @return array<string, mixed>
     */
    private function evaluate_candidate(
        array $content_item,
        array $candidate,
        $index,
        array $slot_lookup,
        array $claimed_targets,
        array $claimed_competition_groups,
        array $context_group_counts,
        array $section_group_counts
    ) {
        $target_ref = sanitize_text_field((string) $candidate['target_ref']);
        $display_confidence = (float) (isset($candidate['confidence']) ? $candidate['confidence'] : 0.0);
        $confidence = (float) (isset($candidate['sort_score']) ? $candidate['sort_score'] : $display_confidence);
        $slot = isset($slot_lookup[$target_ref]) && is_array($slot_lookup[$target_ref]) ? $slot_lookup[$target_ref] : [];
        $adjusted_confidence = $confidence;
        $context_tag = isset($content_item['context_tag']) ? sanitize_key((string) $content_item['context_tag']) : '';
        $section_family = $this->resolve_section_family($candidate, $slot_lookup, $context_tag);
        $group_key = $this->resolve_group_key($candidate, $slot_lookup);
        $competition_group = $this->resolve_competition_group($candidate, $slot_lookup);
        $duplicate_penalty_applied = false;
        $competition_penalty_applied = false;

        if ($target_ref !== '' && isset($claimed_targets[$target_ref]) && ! $this->target_ref_is_repeatable($target_ref)) {
            $adjusted_confidence -= 0.18;
            $duplicate_penalty_applied = true;
        }

        if ($context_tag !== '' && $section_family !== '' && $context_tag === $section_family) {
            $adjusted_confidence += 0.03;
        }

        if ($context_tag !== '' && $group_key !== '' && isset($context_group_counts[$context_tag][$group_key])) {
            $adjusted_confidence += min(0.05, 0.02 * (int) $context_group_counts[$context_tag][$group_key]);
        } elseif ($section_family !== '' && $group_key !== '' && isset($section_group_counts[$section_family][$group_key])) {
            $adjusted_confidence += min(0.04, 0.02 * (int) $section_group_counts[$section_family][$group_key]);
        }

        if ($competition_group !== '' && isset($claimed_competition_groups[$competition_group])) {
            $adjusted_confidence -= 0.12;
            $competition_penalty_applied = true;
        }

        $adjusted_confidence = max(0.05, $adjusted_confidence);

        return [
            'target_ref' => $target_ref,
            'confidence' => round($confidence, 4),
            'display_confidence' => round($display_confidence, 2),
            'adjusted_confidence' => $adjusted_confidence,
            'reason' => isset($candidate['reason']) ? sanitize_key((string) $candidate['reason']) : '',
            'pattern_key' => isset($candidate['pattern_key']) ? sanitize_key((string) $candidate['pattern_key']) : '',
            'raw_rank' => (int) $index,
            'winner_changed' => (int) $index !== 0,
            'duplicate_penalty_applied' => $duplicate_penalty_applied,
            'competition_penalty_applied' => $competition_penalty_applied,
            'group_key' => $group_key,
            'section_family' => $section_family,
            'competition_group' => $competition_group,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $evaluated
     * @return array<string, mixed>
     */
    private function find_raw_frontier_candidate(array $evaluated)
    {
        if (empty($evaluated)) {
            return [];
        }

        usort(
            $evaluated,
            static function ($left, $right) {
                $left_rank = isset($left['raw_rank']) ? (int) $left['raw_rank'] : PHP_INT_MAX;
                $right_rank = isset($right['raw_rank']) ? (int) $right['raw_rank'] : PHP_INT_MAX;
                if ($left_rank === $right_rank) {
                    return strnatcasecmp(
                        isset($left['target_ref']) ? (string) $left['target_ref'] : '',
                        isset($right['target_ref']) ? (string) $right['target_ref'] : ''
                    );
                }

                return $left_rank <=> $right_rank;
            }
        );

        return isset($evaluated[0]) && is_array($evaluated[0]) ? $evaluated[0] : [];
    }

    /**
     * @param array<int, array<string, mixed>> $evaluated
     * @return array<int, array<string, mixed>>
     */
    private function build_selection_alternatives(array $evaluated)
    {
        $alternatives = [];

        foreach (array_slice($evaluated, 0, 3) as $candidate) {
            if (! is_array($candidate) || empty($candidate['target_ref'])) {
                continue;
            }

            $alternatives[] = [
                'target_ref' => (string) $candidate['target_ref'],
                'confidence' => round((float) (isset($candidate['display_confidence']) ? $candidate['display_confidence'] : (isset($candidate['confidence']) ? $candidate['confidence'] : 0.0)), 2),
                'adjusted_confidence' => min(0.99, round((float) (isset($candidate['adjusted_confidence']) ? $candidate['adjusted_confidence'] : 0.0), 2)),
                'raw_adjusted_confidence' => round((float) (isset($candidate['adjusted_confidence']) ? $candidate['adjusted_confidence'] : 0.0), 4),
                'selected_rank' => (int) (isset($candidate['raw_rank']) ? $candidate['raw_rank'] : 0) + 1,
                'reason' => isset($candidate['reason']) ? sanitize_key((string) $candidate['reason']) : '',
                'pattern_key' => isset($candidate['pattern_key']) ? sanitize_key((string) $candidate['pattern_key']) : '',
                'competition_group' => isset($candidate['competition_group']) ? sanitize_text_field((string) $candidate['competition_group']) : '',
            ];
        }

        return $alternatives;
    }

    /**
     * @param array<string, mixed> $content_item
     * @param array<string, mixed> $selected_candidate
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function build_unresolved_item(array $content_item, array $selected_candidate, array $selection)
    {
        $reason_codes = isset($selection['reason_codes']) && is_array($selection['reason_codes'])
            ? array_values(array_map('sanitize_key', $selection['reason_codes']))
            : ['ambiguous_target_selection'];
        return [
            'item_id' => isset($content_item['item_id']) ? (string) $content_item['item_id'] : '',
            'item_type' => isset($content_item['item_type']) ? (string) $content_item['item_type'] : '',
            'source_refs' => isset($content_item['source_refs']) && is_array($content_item['source_refs']) ? array_values($content_item['source_refs']) : [],
            'reason' => 'ambiguous_target_selection',
            'unresolved_class' => isset($selection['unresolved_class']) ? sanitize_key((string) $selection['unresolved_class']) : 'ambiguous_sibling_slots',
            'reason_codes' => $reason_codes,
            'candidate_group' => isset($content_item['candidate_group']) ? (string) $content_item['candidate_group'] : '',
            'context_tag' => isset($content_item['context_tag']) ? (string) $content_item['context_tag'] : '',
            'selected_target_ref' => isset($selected_candidate['target_ref']) ? (string) $selected_candidate['target_ref'] : '',
            'selection' => $selection,
            'notes' => isset($content_item['notes']) && is_array($content_item['notes']) ? array_values($content_item['notes']) : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $unresolved_items
     * @return array<int, array<string, mixed>>
     */
    private function dedupe_unresolved_items(array $unresolved_items)
    {
        $deduped = [];
        foreach ($unresolved_items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item_id = isset($item['item_id']) ? sanitize_text_field((string) $item['item_id']) : '';
            $reason = isset($item['reason']) ? sanitize_key((string) $item['reason']) : '';
            $key = $item_id . '|' . $reason;
            if ($key === '|') {
                $key = md5((string) wp_json_encode($item));
            }

            $deduped[$key] = $item;
        }

        return array_values($deduped);
    }

    /**
     * @param string $domain
     * @return array<string, array<string, mixed>>
     */
    private function load_slot_lookup($domain)
    {
        $domain = sanitize_text_field((string) $domain);
        if ($domain === '') {
            return [];
        }

        if (isset($this->slot_lookup_cache[$domain]) && is_array($this->slot_lookup_cache[$domain])) {
            return $this->slot_lookup_cache[$domain];
        }

        $graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->get_graph($domain, true);
        if (is_wp_error($graph_result)) {
            $this->slot_lookup_cache[$domain] = [];
            return [];
        }

        $slot_graph = isset($graph_result['slot_graph']) && is_array($graph_result['slot_graph']) ? $graph_result['slot_graph'] : [];
        $slots = isset($slot_graph['slots']) && is_array($slot_graph['slots']) ? $slot_graph['slots'] : [];
        $this->slot_lookup_cache[$domain] = $slots;

        return $slots;
    }

    /**
     * @param array<string, mixed>                 $candidate
     * @param array<string, array<string, mixed>> $slot_lookup
     * @return string
     */
    private function resolve_group_key(array $candidate, array $slot_lookup)
    {
        $target_ref = isset($candidate['target_ref']) ? sanitize_text_field((string) $candidate['target_ref']) : '';
        if ($target_ref !== '' && isset($slot_lookup[$target_ref]) && is_array($slot_lookup[$target_ref])) {
            return isset($slot_lookup[$target_ref]['group_key']) ? sanitize_key((string) $slot_lookup[$target_ref]['group_key']) : '';
        }

        if (strpos($target_ref, 'acf:') === 0) {
            $parts = explode(':', $target_ref);
            return isset($parts[1]) ? sanitize_key((string) $parts[1]) : '';
        }

        return '';
    }

    /**
     * @param array<string, mixed>                 $candidate
     * @param array<string, array<string, mixed>> $slot_lookup
     * @param string                               $fallback
     * @return string
     */
    private function resolve_section_family(array $candidate, array $slot_lookup, $fallback = '')
    {
        $target_ref = isset($candidate['target_ref']) ? sanitize_text_field((string) $candidate['target_ref']) : '';
        if ($target_ref !== '' && isset($slot_lookup[$target_ref]) && is_array($slot_lookup[$target_ref])) {
            $section_family = isset($slot_lookup[$target_ref]['section_family']) ? sanitize_key((string) $slot_lookup[$target_ref]['section_family']) : '';
            if ($section_family !== '') {
                return $section_family;
            }
        }

        return sanitize_key((string) $fallback);
    }

    /**
     * @param array<string, mixed>                 $candidate
     * @param array<string, array<string, mixed>> $slot_lookup
     * @return string
     */
    private function resolve_competition_group(array $candidate, array $slot_lookup)
    {
        $target_ref = isset($candidate['target_ref']) ? sanitize_text_field((string) $candidate['target_ref']) : '';
        if ($target_ref === '' || ! isset($slot_lookup[$target_ref]) || ! is_array($slot_lookup[$target_ref])) {
            return '';
        }

        return isset($slot_lookup[$target_ref]['competition_group'])
            ? sanitize_text_field((string) $slot_lookup[$target_ref]['competition_group'])
            : '';
    }

    /**
     * @param array<string, mixed> $content_item
     * @param array<int, string>   $reason_codes
     * @return string
     */
    private function resolve_unresolved_class(array $content_item, array $reason_codes)
    {
        $item_type = isset($content_item['item_type']) ? sanitize_key((string) $content_item['item_type']) : '';
        $reason_codes = array_values(array_map('sanitize_key', $reason_codes));

        if ($item_type === 'media') {
            return 'missing_media_slot';
        }

        if (in_array('competition_group_pressure', $reason_codes, true) || in_array('low_margin', $reason_codes, true)) {
            return 'ambiguous_sibling_slots';
        }

        if (in_array('duplicate_target_pressure', $reason_codes, true)) {
            return 'duplicate_target_collision';
        }

        if (in_array('low_adjusted_confidence', $reason_codes, true)) {
            return 'low_mapping_evidence';
        }

        return 'missing_eligible_slot';
    }

    /**
     * @param string $target_ref
     * @return bool
     */
    private function target_ref_is_repeatable($target_ref)
    {
        return $target_ref === 'core:post_content' || strpos((string) $target_ref, 'taxonomy:') === 0;
    }
}
