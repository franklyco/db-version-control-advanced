<?php
/**
 * The deterministic floor.
 *
 * This is NOT the mapper. It is what runs when the model is unavailable, over budget, or returns
 * an invalid shape, and it labels its own output honestly so nobody mistakes a lexical-overlap
 * score for a judgement (V3 guide section 7.2).
 *
 * It is a compact reimplementation of the signal families V2's 1,330-line mapping index uses -
 * token overlap against field label and purpose, kind-to-shape compatibility, object scoping,
 * heading context - plus one thing V2 lacks: explicit chrome suppression, so a footer phone number
 * is declined rather than mapped onto every page.
 *
 * It is NOT V2's scorer. V2's is entangled with WordPress and the VerticalFramework field-context
 * provider and cannot run headless. The comparison that decides S1 is floor-vs-model on identical
 * inputs; cross-checking against V2's in-WordPress scorer is a separate exercise.
 */

if (! defined('WPINC') && PHP_SAPI !== 'cli') {
    die;
}

final class DBVC_CC_Interp_Floor
{
    /** Site chrome repeats across pages and belongs to a singleton, not to any page field. */
    private const CHROME_PATTERNS = [
        '/^\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}$/',              // a bare phone number
        '/^[\w.+-]+@[\w-]+\.[\w.]+$/',                            // a bare email address
        '/^\d+\s+\w+.*(?:suite|ste\.?|unit)\s/i',                 // a street address with a suite
        '/^(?:skip to content|select page|menu|home|search)$/i',  // nav and a11y furniture
        '/^(?:facebook|instagram|twitter|x|linkedin|youtube|tiktok)$/i',
        '/^(?:©|\(c\)|copyright)\s/i',
        '/^(?:designed by|powered by|website by)\b/i',
        '/^(?:previous|next|\d+)$/i',                             // slider controls
    ];

    private const STOP_WORDS = [
        'the','a','an','and','or','of','for','to','in','on','at','with','your','our','we','you','is',
        'are','be','this','that','it','as','by','from','will','can','all','have','has','not','but',
    ];

    /**
     * @param array<string, mixed> $input  interpretation-input.v1
     * @param array<string, mixed> $schema corpus-target-schema.v1
     * @param string               $object_key Target object the page was classified into.
     * @param array<int, string>   $offered_keys Other objects the page may be read as a set of.
     * @return array<string, mixed> interpretation-result.v1
     */
    public static function interpret(array $input, array $schema, $object_key, array $offered_keys = [])
    {
        $object = DBVC_CC_Interp_Contracts::find_object($schema, $object_key);
        if ($object === null) {
            return self::result($input, $object_key, [], ['unknown_object_key']);
        }

        $records = self::read_as_records($input, $schema, (string) $object_key, $offered_keys);
        if ($records !== null) {
            return $records;
        }

        $fields = DBVC_CC_Interp_Contracts::mappable_fields($object);
        $recommendations = [];
        $declined = [];

        foreach ($input['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $outcome = self::map_item($item, $fields, count($recommendations) + 1);
            if (isset($outcome['declined'])) {
                $declined[] = $outcome['declined'];
            } else {
                $recommendations[] = $outcome['recommendation'];
            }
        }

        return self::result($input, $object_key, $recommendations, [], $declined);
    }

    /**
     * Read a page as a set of records — but only when an object it may hold a set of DECLARES one
     * record per entity. The live catalog cannot say that, so on the live path this never fires
     * and the floor stays the floor; the pinned corpus schema can (`team_member` is
     * `one_per_person`), which is what lets the headless evaluator score D-19 at all.
     *
     * The grouping is structural, not lexical: the shallowest heading depth that repeats opens a
     * record, everything under it belongs to that record until a heading at that depth or above
     * closes it, and a record needs some content beyond its own heading. Nothing here judges
     * meaning, so the heading that opens a record is offered as its name at a confidence that
     * cannot be auto-accepted.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $schema
     * @param string               $object_key
     * @param array<int, string>   $offered_keys
     * @return array<string, mixed>|null A result, or null when the page is one record.
     */
    private static function read_as_records(array $input, array $schema, $object_key, array $offered_keys)
    {
        $entity_object = null;
        foreach (DBVC_CC_Interp_Contracts::collection_objects($schema, $object_key, $offered_keys) as $candidate) {
            if (DBVC_CC_Interp_Contracts::declares_one_per_entity((string) ($candidate['cardinality'] ?? ''))) {
                $entity_object = $candidate;
                break;
            }
        }
        if ($entity_object === null) {
            return null;
        }

        $groups = self::group_records(array_values(array_filter((array) ($input['items'] ?? []), 'is_array')));
        if (count($groups) < 2) {
            return null;
        }

        $fields = DBVC_CC_Interp_Contracts::mappable_fields($entity_object);
        $name_field = null;
        foreach ($fields as $field) {
            if ($field['required'] && in_array($field['shape'], DBVC_CC_Interp_Contracts::compatible_shapes()[DBVC_CC_Interp_Contracts::KIND_HEADING], true)) {
                $name_field = $field;
                break;
            }
        }

        $member_of = [];
        $entities = [];
        foreach ($groups as $index => $group) {
            $entity_id = 'e' . ($index + 1);
            $entities[$entity_id] = [
                'entity_id' => $entity_id,
                'object_key' => (string) $entity_object['object_key'],
                'label' => (string) $group['anchor']['text'],
                'item_ids' => [],
            ];
            $member_of[(string) $group['anchor']['item_id']] = [$entity_id, true];
            foreach ($group['members'] as $member) {
                $member_of[(string) $member['item_id']] = [$entity_id, false];
            }
        }

        $recommendations = [];
        $declined = [];
        foreach ($input['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $item_id = (string) ($item['item_id'] ?? '');
            $text = (string) ($item['text'] ?? '');

            if (! isset($member_of[$item_id])) {
                $declined[] = [
                    'item_id' => $item_id,
                    'reason' => self::is_chrome($text) ? 'site_chrome' : DBVC_CC_Interp_Contracts::DECLINE_PAGE_LEVEL_CONTENT,
                    'source_evidence' => $text,
                ];
                continue;
            }

            list($entity_id, $is_anchor) = $member_of[$item_id];
            if ($is_anchor && $name_field !== null) {
                $recommendation = [
                    'recommendation_id' => 'rec_' . str_pad((string) (count($recommendations) + 1), 3, '0', STR_PAD_LEFT),
                    'item_id' => $item_id,
                    'target_ref' => $name_field['field_ref'],
                    'target_object_key' => $name_field['object_key'],
                    // A structural default, never an auto-accept: the heading's position says it
                    // opens a record, not that its words are the record's name.
                    'confidence' => 0.6,
                    'requires_review' => true,
                    'reason_codes' => ['entity_anchor_structural_default'],
                    'rationale' => sprintf('the heading that opens record %s, offered as its "%s"', $entity_id, $name_field['label']),
                    'source_evidence' => $text,
                    'provenance' => DBVC_CC_Interp_Contracts::provenance(
                        DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR,
                        DBVC_CC_Interp_Contracts::BASIS_LEXICAL_OVERLAP
                    ),
                ];
            } else {
                $outcome = self::map_item($item, $fields, count($recommendations) + 1);
                if (isset($outcome['declined'])) {
                    $declined[] = $outcome['declined'];
                    continue;
                }
                $recommendation = $outcome['recommendation'];
            }

            $recommendation['entity_id'] = $entity_id;
            $entities[$entity_id]['item_ids'][] = $item_id;
            $recommendations[] = $recommendation;
        }

        $result = self::result($input, $object_key, $recommendations, [], $declined);
        $result['entities'] = array_values($entities);

        return $result;
    }

    /**
     * Split a page's items into records by the shallowest heading depth that repeats.
     *
     * Depth is read from the heading context the input already carries, so the markdown-built
     * evaluator input and the elements-built live input group identically.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{anchor: array<string, mixed>, members: array<int, array<string, mixed>>}>
     */
    private static function group_records(array $items)
    {
        $depth_counts = [];
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === DBVC_CC_Interp_Contracts::KIND_HEADING && ! self::is_chrome((string) ($item['text'] ?? ''))) {
                $depth = max(1, count((array) ($item['heading_context'] ?? [])));
                $depth_counts[$depth] = ($depth_counts[$depth] ?? 0) + 1;
            }
        }
        ksort($depth_counts);
        $record_depth = null;
        foreach ($depth_counts as $depth => $count) {
            if ($count >= 2) {
                $record_depth = $depth;
                break;
            }
        }
        if ($record_depth === null) {
            return [];
        }

        $groups = [];
        $current = null;
        foreach ($items as $item) {
            $kind = (string) ($item['kind'] ?? '');
            if ($kind === DBVC_CC_Interp_Contracts::KIND_HEAD_META) {
                continue;
            }
            if ($kind === DBVC_CC_Interp_Contracts::KIND_HEADING) {
                $depth = max(1, count((array) ($item['heading_context'] ?? [])));
                if ($depth === $record_depth) {
                    if ($current !== null) {
                        $groups[] = $current;
                    }
                    $current = ['anchor' => $item, 'members' => []];
                    continue;
                }
                if ($depth < $record_depth) {
                    if ($current !== null) {
                        $groups[] = $current;
                    }
                    $current = null;
                    continue;
                }
            }
            if ($current !== null && ! self::is_chrome((string) ($item['text'] ?? ''))) {
                $current['members'][] = $item;
            }
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        // A heading with nothing under it is a label, not a record.
        return array_values(array_filter(
            $groups,
            static function ($group) {
                return $group['members'] !== [];
            }
        ));
    }

    /**
     * Map one item onto the best lexically-matching field, or decline it.
     *
     * @param array<string, mixed>            $item
     * @param array<int, array<string,mixed>> $fields
     * @param int                             $ordinal
     * @return array{recommendation?: array<string, mixed>, declined?: array<string, mixed>}
     */
    private static function map_item(array $item, array $fields, $ordinal)
    {
        $compatible = DBVC_CC_Interp_Contracts::compatible_shapes();
        $text = (string) ($item['text'] ?? '');
        $kind = (string) ($item['kind'] ?? '');

        if (self::is_chrome($text)) {
            return ['declined' => [
                'item_id' => (string) ($item['item_id'] ?? ''),
                'reason' => 'site_chrome',
                'source_evidence' => $text,
            ]];
        }

        $allowed_shapes = $compatible[$kind] ?? [];
        $best = null;
        $best_score = 0.0;
        $runner_up = 0.0;

        foreach ($fields as $field) {
            if ($allowed_shapes !== [] && ! in_array($field['shape'], $allowed_shapes, true)) {
                continue;
            }
            $score = self::score($item, $field);
            if ($score > $best_score) {
                $runner_up = $best_score;
                $best_score = $score;
                $best = $field;
            } elseif ($score > $runner_up) {
                $runner_up = $score;
            }
        }

        if ($best === null || $best_score <= 0.0) {
            return ['declined' => [
                'item_id' => (string) ($item['item_id'] ?? ''),
                'reason' => 'no_compatible_field',
                'source_evidence' => $text,
            ]];
        }

        // Ambiguity is a first-class outcome: a near-tie is worth less than a clear winner.
        $margin = $best_score - $runner_up;
        $confidence = round(min(0.99, $best_score * (0.6 + 0.4 * min(1.0, $margin / 0.25))), 2);
        $reason_codes = [];
        if ($margin < 0.05) {
            $reason_codes[] = 'ambiguous_near_tie';
        }
        if ($best_score < 0.4) {
            $reason_codes[] = 'weak_lexical_evidence';
        }

        $requires_review = $confidence < DBVC_CC_Interp_Contracts::AUTO_ACCEPT_MIN_CONFIDENCE
            || $reason_codes !== [];

        return ['recommendation' => [
            'recommendation_id' => 'rec_' . str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT),
            'item_id' => (string) ($item['item_id'] ?? ''),
            'target_ref' => $best['field_ref'],
            'target_object_key' => $best['object_key'],
            'confidence' => $confidence,
            'requires_review' => $requires_review,
            'reason_codes' => $reason_codes,
            'rationale' => sprintf(
                'lexical overlap %.2f with "%s" (%s), margin %.2f over the next field',
                $best_score,
                $best['label'],
                $best['shape'],
                $margin
            ),
            'source_evidence' => $text,
            'provenance' => DBVC_CC_Interp_Contracts::provenance(
                DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR,
                DBVC_CC_Interp_Contracts::BASIS_LEXICAL_OVERLAP
            ),
        ]];
    }

    /**
     * Overlap of the item's tokens with the field's label and purpose, nudged by heading context
     * and an exact label match. Range 0..1.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $field
     * @return float
     */
    private static function score(array $item, array $field)
    {
        $item_tokens = self::tokens((string) ($item['text'] ?? ''));
        if ($item_tokens === []) {
            return 0.0;
        }
        $field_tokens = self::tokens($field['label'] . ' ' . $field['purpose']);
        if ($field_tokens === []) {
            return 0.0;
        }

        $shared = count(array_intersect($item_tokens, $field_tokens));
        $score = $shared / max(1, min(count($item_tokens), count($field_tokens)));

        // An exact label match is the strongest lexical signal available.
        if (self::normalise((string) ($item['text'] ?? '')) === self::normalise($field['label'])) {
            $score = max($score, 0.9);
        }

        // Heading context naming the field's group is weak corroboration.
        $context = '';
        foreach ((array) ($item['heading_context'] ?? []) as $heading) {
            $context .= ' ' . (string) $heading;
        }
        if ($context !== '' && array_intersect(self::tokens($context), $field_tokens) !== []) {
            $score += 0.05;
        }

        // A required field is a likelier destination than an optional one, all else equal.
        if ($field['required']) {
            $score += 0.03;
        }

        return (float) min(1.0, round($score, 4));
    }

    /**
     * @param string $text
     * @return bool
     */
    private static function is_chrome($text)
    {
        $trimmed = trim((string) $text);
        foreach (self::CHROME_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $text
     * @return array<int, string>
     */
    private static function tokens($text)
    {
        $normalised = self::normalise($text);
        $parts = preg_split('/[^a-z0-9]+/', $normalised) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if (strlen($part) > 2 && ! in_array($part, self::STOP_WORDS, true)) {
                $tokens[$part] = $part;
            }
        }

        return array_values($tokens);
    }

    /**
     * @param string $text
     * @return string
     */
    private static function normalise($text)
    {
        $value = strtolower(trim((string) $text));
        $value = preg_replace('/\s+/u', ' ', $value);

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed>            $input
     * @param string                          $object_key
     * @param array<int, array<string,mixed>> $recommendations
     * @param array<int, string>              $errors
     * @param array<int, array<string,mixed>> $declined
     * @return array<string, mixed>
     */
    private static function result(array $input, $object_key, array $recommendations, array $errors = [], array $declined = [])
    {
        return [
            'artifact_type' => DBVC_CC_Interp_Contracts::RESULT_TYPE,
            'page_slug' => (string) ($input['page_slug'] ?? ''),
            'source_url' => (string) ($input['source_url'] ?? ''),
            'target_object_key' => (string) $object_key,
            'interpreter' => DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR,
            'recommendations' => $recommendations,
            'declined' => $declined,
            'errors' => $errors,
            'stats' => [
                'items_in' => count($input['items'] ?? []),
                'recommended' => count($recommendations),
                'declined' => count($declined),
                'auto_accepted' => count(array_filter(
                    $recommendations,
                    static function ($recommendation) {
                        return empty($recommendation['requires_review']);
                    }
                )),
            ],
        ];
    }
}
