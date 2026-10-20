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
    public const PROMPT_VERSION = 'map-content-to-schema.v1';

    /**
     * Response envelope the model must return. Anything else is an invalid shape and falls back.
     */
    public const RESPONSE_SHAPE = '{"mappings":[{"item_id","target_ref","confidence","rationale"}],"declined":[{"item_id","reason"}]}';

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
PROMPT;
    }

    /**
     * @param array<string, mixed>            $input  interpretation-input.v1
     * @param array<int, array<string,mixed>> $fields Flattened mappable fields.
     * @param array<string, mixed>            $object The target object being mapped into.
     * @return string
     */
    public static function user_payload(array $input, array $fields, array $object)
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

        $item_lines = [];
        foreach ($input['items'] ?? [] as $item) {
            $item_lines[] = [
                'item_id' => $item['item_id'],
                'kind' => $item['kind'],
                'text' => $item['text'],
                'heading_context' => array_values((array) ($item['heading_context'] ?? [])),
            ];
        }

        return (string) wp_json_encode_compat([
            'target_object' => [
                'object_key' => $object['object_key'] ?? '',
                'label' => $object['label'] ?? '',
                'entity_role' => $object['entity_role'] ?? '',
                'cardinality' => $object['cardinality'] ?? '',
            ],
            'available_fields' => $field_lines,
            'content_items' => $item_lines,
        ]);
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
     * @return array<int, string> Errors; empty means usable.
     */
    public static function validate_response($decoded, array $fields, array $input)
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
            if (! isset($known_fields[$target_ref])) {
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
