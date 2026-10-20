<?php
/**
 * Interpretation boundary contracts.
 *
 * The interpretation layer is deliberately WordPress-independent: plain arrays in, plain arrays
 * out. That is what makes it measurable headless against the corpus and what makes the model and
 * the deterministic floor interchangeable. A WordPress adapter's only job is to read the live
 * target schema into `target-schema.v1` and the captured artifacts into `interpretation-input.v1`.
 *
 * Provenance is a contract, not a convenience. V2 stamped `model: "gpt-4o-mini"` onto output no
 * model produced (V3 guide D-3), so a reviewer calibrating trust against a confidence number was
 * reading a keyword-overlap score dressed as a model judgement. Here the component that produced a
 * value writes its own provenance, and fields that describe a model run are ABSENT - not null, not
 * defaulted - when no model ran.
 */

if (! defined('WPINC') && PHP_SAPI !== 'cli') {
    die;
}

final class DBVC_CC_Interp_Contracts
{
    public const INPUT_TYPE = 'interpretation-input.v1';
    public const RESULT_TYPE = 'interpretation-result.v1';
    public const SCHEMA_TYPE = 'corpus-target-schema.v1';

    /** Interpreter identities. Written by whichever one actually ran. */
    public const INTERPRETER_MODEL = 'model';
    public const INTERPRETER_FLOOR = 'floor';

    /** What a confidence number actually measures. Never omit this. */
    public const BASIS_MODEL_REPORTED = 'model_reported';
    public const BASIS_LEXICAL_OVERLAP = 'lexical_overlap';

    /**
     * Mirrors DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE's shipped default, so a
     * headless score is comparable with the in-WordPress pipeline.
     */
    public const AUTO_ACCEPT_MIN_CONFIDENCE = 0.92;

    /** Content item kinds the interpreter understands. */
    public const KIND_HEADING = 'heading';
    public const KIND_BODY = 'body';
    public const KIND_CTA = 'cta';
    public const KIND_IMAGE = 'image';
    public const KIND_HEAD_META = 'head_meta';

    /**
     * Value shapes a kind can legitimately land in. Used by the floor and asserted on model output,
     * so neither can propose a heading for an attachment field.
     *
     * @return array<string, array<int, string>>
     */
    public static function compatible_shapes()
    {
        return [
            self::KIND_HEADING => ['scalar_string', 'slug'],
            self::KIND_BODY => ['html', 'scalar_string'],
            self::KIND_CTA => ['url', 'scalar_string'],
            self::KIND_IMAGE => ['attachment_id'],
            self::KIND_HEAD_META => ['scalar_string', 'url'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, string> Validation errors; empty means valid.
     */
    public static function validate_input(array $input)
    {
        $errors = [];
        if (($input['artifact_type'] ?? '') !== self::INPUT_TYPE) {
            $errors[] = 'artifact_type must be ' . self::INPUT_TYPE;
        }
        if (! is_array($input['items'] ?? null) || $input['items'] === []) {
            $errors[] = 'items must be a non-empty array';

            return $errors;
        }
        foreach ($input['items'] as $index => $item) {
            if (! is_array($item)) {
                $errors[] = "items[{$index}] must be an array";
                continue;
            }
            foreach (['item_id', 'kind', 'text'] as $required) {
                if (! isset($item[$required]) || $item[$required] === '') {
                    $errors[] = "items[{$index}].{$required} is required";
                }
            }
            if (isset($item['kind']) && ! isset(self::compatible_shapes()[$item['kind']])) {
                $errors[] = "items[{$index}].kind '{$item['kind']}' is not a known kind";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    public static function validate_schema(array $schema)
    {
        $errors = [];
        if (($schema['schema'] ?? '') !== self::SCHEMA_TYPE) {
            $errors[] = 'schema must be ' . self::SCHEMA_TYPE;
        }
        if (! is_array($schema['objects'] ?? null) || $schema['objects'] === []) {
            $errors[] = 'objects must be a non-empty array';
        }

        return $errors;
    }

    /**
     * Flatten a schema object's fields, including repeater sub-fields, into a mappable list.
     *
     * @param array<string, mixed> $object
     * @return array<int, array<string, mixed>>
     */
    public static function mappable_fields(array $object)
    {
        $fields = [];
        foreach ($object['fields'] ?? [] as $field) {
            if (! is_array($field) || empty($field['field_ref'])) {
                continue;
            }
            $shape = (string) ($field['value_contract']['shape'] ?? 'scalar_string');
            // A repeater itself is not a mapping destination; its sub-fields are.
            if ($shape !== 'repeater') {
                $fields[] = self::normalise_field($field, $object);
            }
            foreach ($field['sub_fields'] ?? [] as $sub) {
                if (is_array($sub) && ! empty($sub['field_ref'])) {
                    $fields[] = self::normalise_field($sub, $object);
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private static function normalise_field(array $field, array $object)
    {
        return [
            'field_ref' => (string) $field['field_ref'],
            'object_key' => (string) ($object['object_key'] ?? ''),
            'label' => (string) ($field['label'] ?? ''),
            'type' => (string) ($field['type'] ?? ''),
            'purpose' => (string) ($field['purpose'] ?? ''),
            'shape' => (string) ($field['value_contract']['shape'] ?? 'scalar_string'),
            'required' => ! empty($field['required']),
        ];
    }

    /**
     * Build the provenance block. The only correct way to construct one.
     *
     * @param string               $interpreter One of the INTERPRETER_* constants.
     * @param string               $basis       One of the BASIS_* constants.
     * @param array<string, mixed> $model_run   Model details; MUST be empty unless a model ran.
     * @return array<string, mixed>
     */
    public static function provenance($interpreter, $basis, array $model_run = [])
    {
        $provenance = [
            'interpreter' => (string) $interpreter,
            'confidence_basis' => (string) $basis,
        ];

        // Model keys exist only when a model produced the value. Absence is the signal.
        if ($interpreter === self::INTERPRETER_MODEL) {
            $provenance['model'] = (string) ($model_run['model'] ?? '');
            $provenance['provider'] = (string) ($model_run['provider'] ?? '');
            $provenance['prompt_version'] = (string) ($model_run['prompt_version'] ?? '');
        }

        return $provenance;
    }

    /**
     * Guards the rule D-3 broke: no model key may appear on floor-produced output.
     *
     * @param array<string, mixed> $result
     * @return array<int, string>
     */
    public static function validate_result(array $result)
    {
        $errors = [];
        if (($result['artifact_type'] ?? '') !== self::RESULT_TYPE) {
            $errors[] = 'artifact_type must be ' . self::RESULT_TYPE;
        }
        foreach ($result['recommendations'] ?? [] as $index => $recommendation) {
            $provenance = $recommendation['provenance'] ?? [];
            $interpreter = (string) ($provenance['interpreter'] ?? '');
            if ($interpreter === '') {
                $errors[] = "recommendations[{$index}] has no provenance.interpreter";
                continue;
            }
            if ($interpreter !== self::INTERPRETER_MODEL) {
                foreach (['model', 'provider', 'prompt_version'] as $model_only) {
                    if (array_key_exists($model_only, $provenance)) {
                        $errors[] = "recommendations[{$index}] is {$interpreter}-produced but carries "
                            . "provenance.{$model_only} - this is the D-3 defect";
                    }
                }
                if (($provenance['confidence_basis'] ?? '') === self::BASIS_MODEL_REPORTED) {
                    $errors[] = "recommendations[{$index}] is {$interpreter}-produced but claims a "
                        . 'model-reported confidence basis';
                }
            }
        }

        return $errors;
    }
}
