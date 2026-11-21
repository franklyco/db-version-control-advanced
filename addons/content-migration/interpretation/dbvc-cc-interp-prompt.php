<?php
/**
 * The versioned prompt contract.
 *
 * One prompt, one version, one place. The version is stamped onto every model-produced
 * recommendation, so a score can always be traced to the prompt that produced it — and a prompt
 * change that moves the numbers is visible rather than mysterious.
 *
 * Bump PROMPT_VERSION for any change to the instructions or the response shape, and re-run
 * `composer corpus:eval` before and after. A prompt edit with no measurement is how V2's mapping
 * accuracy became unfalsifiable.
 */

if (! defined('WPINC') && PHP_SAPI !== 'cli') {
    die;
}

final class DBVC_CC_Interp_Prompt
{
    public const PROMPT_VERSION = 'map-content-to-schema.v2';

    /**
     * Response envelope the model must return. Anything else is an invalid shape and falls back.
     */
    public const RESPONSE_SHAPE = '{"entities"?:[{"entity_id","object_key","label"}],"mappings":[{"item_id","target_ref","confidence","rationale","entity_id"?}],"declined":[{"item_id","reason"}]}';

    /**
     * @return string
     */
    public static function system_prompt()
    {
        return <<<'PROMPT'
You map content captured from a website onto fields in a target CMS schema, for a studio rebuilding
that website. You are given a list of content items and a list of available target fields.

Return ONLY a JSON object, no prose, no code fence:
{"mappings":[{"item_id":"...","target_ref":"...","confidence":0.0,"rationale":"..."}],
 "declined":[{"item_id":"...","reason":"..."}]}
(plus "entities" and a per-mapping "entity_id" only under rule 9)

Rules, in priority order:

1. Never invent a target_ref. Use only field_ref values from the supplied field list, exactly as
   written. A mapping to an unlisted field invalidates the whole response.
2. Decline rather than force. If no field genuinely fits an item, put it in `declined` with a
   reason. An item left unmapped costs a reviewer one glance; an item confidently mapped to the
   wrong field is never reviewed at all and ships wrong.
3. Decline site chrome. A phone number, address, email, social link, navigation label, skip link,
   copyright line or theme credit that repeats across pages belongs to a site-wide singleton, not
   to any page field. Decline it with reason "site_chrome".
4. Respect the field's purpose, not just its label. The purpose text says what the field is for on
   the rendered page; use it.
5. Respect the value shape. Do not map a heading into an attachment field or a paragraph into a
   URL field.
6. Repeater sub-fields are written as `group:items.sub_field`. Several items may map to the same
   sub-field: that is how a repeater gets multiple rows. This is expected, not a conflict.
7. confidence is your own calibrated probability that this mapping is correct, from 0.0 to 1.0.
   Do not inflate it. A mapping you would want a human to check should score below 0.9. Your
   confidence is used to decide whether a human ever sees the mapping, so a confident wrong answer
   is the most expensive thing you can produce.
8. rationale is one short clause naming the evidence you used.
9. One page can hold several records. `collection_objects`, when present, lists objects a single
   page may contain more than one of: people, services, questions. If the page presents distinct
   records of one of them, add "entities":[{"entity_id":"e1","object_key":"...","label":"..."}]
   with one entry per record, its label being the record's own name, and give EVERY mapping an
   "entity_id". Map each record's items onto that object's fields, and keep each record's name,
   role, body and image with that record even when the page's headings split them apart. Decline
   content that belongs to the page rather than to any record (its own heading, an introduction)
   with reason "page_level_content". Never merge two records or split one.
10. Otherwise omit "entities" and "entity_id" entirely and map onto `available_fields` only. A page
   that is one record is the normal case; do not invent a set.
PROMPT;
    }

    /**
     * @param array<string, mixed>            $input  interpretation-input.v1
     * @param array<int, array<string,mixed>> $fields Flattened mappable fields.
     * @param array<string, mixed>            $object The target object being mapped into.
     * @param array<int, array<string,mixed>> $collection_objects Objects the page may hold a set of.
     * @return string
     */
    public static function user_payload(array $input, array $fields, array $object, array $collection_objects = [])
    {
        $field_lines = self::field_lines($fields);

        $item_lines = [];
        foreach ($input['items'] ?? [] as $item) {
            $item_lines[] = [
                'item_id' => $item['item_id'],
                'kind' => $item['kind'],
                'text' => $item['text'],
                'heading_context' => array_values((array) ($item['heading_context'] ?? [])),
            ];
        }

        $payload = [
            'target_object' => [
                'object_key' => $object['object_key'] ?? '',
                'label' => $object['label'] ?? '',
                'entity_role' => $object['entity_role'] ?? '',
                'cardinality' => $object['cardinality'] ?? '',
            ],
            'available_fields' => $field_lines,
        ];

        // Offered only when there is a set to offer, so a page with no candidate collection gets
        // the payload it always got.
        if ($collection_objects !== []) {
            $payload['collection_objects'] = [];
            foreach ($collection_objects as $collection) {
                $payload['collection_objects'][] = [
                    'object_key' => $collection['object_key'] ?? '',
                    'label' => $collection['label'] ?? '',
                    'entity_role' => $collection['entity_role'] ?? '',
                    'cardinality' => $collection['cardinality'] ?? '',
                    'fields' => self::field_lines(DBVC_CC_Interp_Contracts::mappable_fields($collection)),
                ];
            }
        }

        $payload['content_items'] = $item_lines;

        return (string) wp_json_encode_compat($payload);
    }

    /**
     * @param array<int, array<string,mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private static function field_lines(array $fields)
    {
        $field_lines = [];
        foreach ($fields as $field) {
            $field_lines[] = [
                'field_ref' => $field['field_ref'],
                'label' => $field['label'],
                'type' => $field['type'],
                'shape' => $field['shape'],
                'purpose' => $field['purpose'],
                'required' => (bool) $field['required'],
            ];
        }

        return $field_lines;
    }

    /**
     * Validate a parsed model response against the supplied inputs.
     *
     * Strict on purpose: a hallucinated field_ref or an unknown item_id means the response cannot
     * be trusted at all, so the whole thing is rejected and the floor runs instead. Partial trust
     * in a model response is how wrong mappings reach a package.
     *
     * @param mixed                           $decoded
     * @param array<int, array<string,mixed>> $fields
     * @param array<string, mixed>            $input
     * @param array<int, array<string,mixed>> $collection_objects Objects the page may hold a set of.
     * @return array<int, string> Errors; empty means usable.
     */
    public static function validate_response($decoded, array $fields, array $input, array $collection_objects = [])
    {
        if (! is_array($decoded)) {
            return ['response was not a JSON object'];
        }
        if (! isset($decoded['mappings']) || ! is_array($decoded['mappings'])) {
            return ['response has no mappings array'];
        }

        $known_fields = [];
        foreach ($fields as $field) {
            $known_fields[$field['field_ref']] = true;
        }
        $known_items = [];
        foreach ($input['items'] ?? [] as $item) {
            $known_items[(string) $item['item_id']] = true;
        }

        $errors = [];
        $entity_fields = self::validate_entities($decoded, $collection_objects, $errors);
        foreach ($decoded['mappings'] as $index => $mapping) {
            if (! is_array($mapping)) {
                $errors[] = "mappings[{$index}] is not an object";
                continue;
            }
            $item_id = (string) ($mapping['item_id'] ?? '');
            $target_ref = (string) ($mapping['target_ref'] ?? '');
            if (! isset($known_items[$item_id])) {
                $errors[] = "mappings[{$index}] references unknown item_id '{$item_id}'";
            }

            // Under rule 9 a mapping belongs to a declared record and lands in that record's
            // object; otherwise it lands in the target object. Either way the field must exist.
            $entity_id = array_key_exists('entity_id', $mapping) ? (string) $mapping['entity_id'] : null;
            if ($entity_fields !== null) {
                if ($entity_id === null || ! isset($entity_fields[$entity_id])) {
                    $errors[] = "mappings[{$index}] names no declared entity_id";
                } elseif (! isset($entity_fields[$entity_id][$target_ref])) {
                    $errors[] = "mappings[{$index}] invented target_ref '{$target_ref}' for its entity's object";
                }
            } elseif ($entity_id !== null) {
                $errors[] = "mappings[{$index}] carries an entity_id but no entities were declared";
            } elseif (! isset($known_fields[$target_ref])) {
                $errors[] = "mappings[{$index}] invented target_ref '{$target_ref}'";
            }
            $confidence = $mapping['confidence'] ?? null;
            if (! is_numeric($confidence) || (float) $confidence < 0.0 || (float) $confidence > 1.0) {
                $errors[] = "mappings[{$index}] confidence is not a number in 0..1";
            }
        }
        if (isset($decoded['declined']) && ! is_array($decoded['declined'])) {
            $errors[] = 'declined must be an array when present';
        }

        return $errors;
    }

    /**
     * Validate a declared set of records and index each entity's legal fields.
     *
     * @param array<string, mixed>            $decoded
     * @param array<int, array<string,mixed>> $collection_objects
     * @param array<int, string>              $errors
     * @return array<string, array<string, bool>>|null Fields by entity_id; null when no set was declared.
     */
    private static function validate_entities(array $decoded, array $collection_objects, array &$errors)
    {
        if (! array_key_exists('entities', $decoded)) {
            return null;
        }
        if (! is_array($decoded['entities']) || $decoded['entities'] === []) {
            $errors[] = 'entities must be a non-empty array when present';

            return [];
        }

        $fields_by_object = [];
        foreach ($collection_objects as $object) {
            $key = (string) ($object['object_key'] ?? '');
            $fields_by_object[$key] = [];
            foreach (DBVC_CC_Interp_Contracts::mappable_fields($object) as $field) {
                $fields_by_object[$key][$field['field_ref']] = true;
            }
        }

        $entity_fields = [];
        foreach ($decoded['entities'] as $index => $entity) {
            if (! is_array($entity)) {
                $errors[] = "entities[{$index}] is not an object";
                continue;
            }
            $entity_id = (string) ($entity['entity_id'] ?? '');
            $object_key = (string) ($entity['object_key'] ?? '');
            if ($entity_id === '' || isset($entity_fields[$entity_id])) {
                $errors[] = "entities[{$index}] needs a unique entity_id";
                continue;
            }
            if (! isset($fields_by_object[$object_key])) {
                // A set of an object nobody offered as a collection, or of a page, is the model
                // deciding the data model. That is never its call.
                $errors[] = "entities[{$index}] uses object_key '{$object_key}', which was not offered as a collection";
                continue;
            }
            if (trim((string) ($entity['label'] ?? '')) === '') {
                $errors[] = "entities[{$index}] has no label";
            }
            $entity_fields[$entity_id] = $fields_by_object[$object_key];
        }

        return $entity_fields;
    }
}

if (! function_exists('wp_json_encode_compat')) {
    /**
     * json_encode with WordPress's flags when available, so the module runs headless too.
     *
     * @param mixed $value
     * @return string
     */
    function wp_json_encode_compat($value)
    {
        if (function_exists('wp_json_encode')) {
            return (string) wp_json_encode($value);
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
