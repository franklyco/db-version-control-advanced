<?php

/**
 * The joint between the mapping stage and the interpretation boundary.
 *
 * S1b built the interpreter and measured it at 100% field accuracy against the deterministic
 * floor's 4.8%. S1c loaded it and built `DBVC_CC_V2_Interpretation_Bridge_Service`, the adapter
 * that turns the live catalog and the captured artifacts into the boundary's contracts. Neither
 * slice gave it a caller, so a run's recommendations were still produced by the lexical scorer and
 * D-2 — "the AI Layer makes no outbound model call" — was still true of the shipping product.
 * That is D-20, and this class is the half that closes it.
 *
 * It does three things and nothing else:
 *
 *   - decide whether interpretation runs at all (the flag, nothing else);
 *   - call the adapter once per page and hand back `interpretation-result.v1`;
 *   - when the adapter refuses, build the same artifact shape with zero recommendations and a
 *     NAMED reason, so a reviewer reading the run can tell "no key" from "the page had no target
 *     object" from "the model hallucinated a field".
 *
 * It never ranks anything. `DBVC_CC_V2_Mapping_Index_Service` consumes the result and decides
 * which candidate a reviewer sees; the guarded write, dry run and rollback in commitment are not
 * touched by any of this.
 *
 * Provenance is the reason this class exists in its own file rather than inline in the
 * orchestrator. D-3 was artifacts stamped `model: "gpt-4o-mini"` for output no model produced, so
 * every path out of here is either a boundary result (which writes its own provenance and is
 * validated by `DBVC_CC_Interp_Contracts::validate_result()`) or an explicitly floor-labelled
 * record carrying no model keys at all.
 */

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Mapping_Interpretation_Service
{
    /**
     * Options handed to the interpretation boundary, after the service has resolved the provider,
     * the model and the key.
     *
     * The intended use is an injectable `transport`, which is how every branch of the model path
     * is exercised with no network. `api_key` is stripped from whatever a filter returns: the key
     * resolves from a constant, then the environment, then the option, and D-12 was a plaintext
     * key reachable from configuration. A filter must not become a new way to supply one.
     *
     * @var string
     */
    public const FILTER_INTERPRETATION_OPTIONS = 'dbvc_cc_v2_interpretation_options';

    /** Named reasons this service itself produces, beside the boundary's own four. */
    public const SKIP_UNROUTED_PAGE = 'unrouted_page';
    public const SKIP_SCHEMA_UNAVAILABLE = 'target_schema_unavailable';
    public const SKIP_NO_MAPPABLE_CONTENT = 'no_mappable_content';
    public const SKIP_RESULT_INVALID = 'interpreter_result_invalid';

    /**
     * @var DBVC_CC_V2_Mapping_Interpretation_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Mapping_Interpretation_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Does the mapping stage consult the interpreter at all?
     *
     * Deliberately the flag and not `model_is_available()`. An enabled flag with no key must
     * produce a run that says `no_api_key`, which is a configuration mistake an operator can fix;
     * silently ranking lexically and reporting nothing is how V2's `fallback_mode` hid the same
     * situation. Nothing is sent in that case — the model client refuses before it builds a
     * request.
     *
     * @return bool
     */
    public function is_enabled()
    {
        return DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->model_is_enabled();
    }

    /**
     * Interpret one page for the mapping stage.
     *
     * Always returns an `interpretation-result.v1` array, never a WP_Error: a page the interpreter
     * could not handle still has to produce a persistable record saying why, and the mapping stage
     * still has to run. The lexical floor answering is the designed outcome, not a failure.
     *
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $routing_artifact
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> interpretation-result.v1
     */
    public function interpret_page(
        array $page_context,
        array $classification_artifact,
        array $routing_artifact,
        array $catalog,
        array $overrides = []
    ) {
        $object_key = self::resolve_object_key($classification_artifact, $routing_artifact);
        $result = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->interpret_page(
            $page_context,
            $catalog,
            $object_key,
            $this->build_options($overrides)
        );

        if (is_wp_error($result)) {
            return $this->build_unavailable_result($page_context, $object_key, $result);
        }

        return $result;
    }

    /**
     * The target object the page routes to, resolved exactly as the mapping index resolves it.
     *
     * Routing wins over classification because routing is what the mapping index narrows its
     * catalog by. Interpreting against a different object than the one the candidates are drawn
     * from would produce target_refs the mapping stage cannot use.
     *
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $routing_artifact
     * @return string
     */
    public static function resolve_object_key(array $classification_artifact, array $routing_artifact)
    {
        $routing_primary = isset($routing_artifact['primary_route']) && is_array($routing_artifact['primary_route'])
            ? $routing_artifact['primary_route']
            : [];
        if (! empty($routing_primary['object_key'])) {
            return sanitize_key((string) $routing_primary['object_key']);
        }

        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];

        return isset($primary['object_key']) ? sanitize_key((string) $primary['object_key']) : '';
    }

    /**
     * A result for a page the interpreter refused, in the same shape as one it produced.
     *
     * Labelled `floor` with no recommendations: the lexical scorer in the mapping index is what
     * ranks this page, and saying so is the honest record. No model, provider or prompt_version
     * key appears anywhere, which `DBVC_CC_Interp_Contracts::validate_result()` would reject if it
     * did.
     *
     * @param array<string, mixed> $page_context
     * @param string               $object_key
     * @param WP_Error             $error
     * @return array<string, mixed>
     */
    private function build_unavailable_result(array $page_context, $object_key, $error)
    {
        $raw = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact'])
            ? $page_context['raw_artifact']
            : [];
        $code = $error instanceof WP_Error ? (string) $error->get_error_code() : '';

        return [
            'artifact_type' => DBVC_CC_Interp_Contracts::RESULT_TYPE,
            'page_slug' => isset($raw['slug']) ? (string) $raw['slug'] : '',
            'source_url' => isset($raw['source_url']) ? (string) $raw['source_url'] : '',
            'target_object_key' => (string) $object_key,
            'interpreter' => DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR,
            'model_attempt' => [
                'used' => false,
                'attempted' => false,
                'reason' => self::reason_for_error_code($code),
                'detail' => $error instanceof WP_Error ? (string) $error->get_error_message() : '',
            ],
            'recommendations' => [],
            'declined' => [],
            'errors' => $code === '' ? [] : [$code],
            'stats' => [
                'items_in' => 0,
                'recommended' => 0,
                'declined' => 0,
                'auto_accepted' => 0,
            ],
        ];
    }

    /**
     * Map the adapter's error codes onto reasons an operator can act on.
     *
     * @param string $code
     * @return string
     */
    private static function reason_for_error_code($code)
    {
        switch ((string) $code) {
            case 'dbvc_cc_v2_interp_object_key_required':
                return self::SKIP_UNROUTED_PAGE;
            case 'dbvc_cc_v2_interp_schema_invalid':
                return self::SKIP_SCHEMA_UNAVAILABLE;
            case 'dbvc_cc_v2_interp_input_invalid':
                return self::SKIP_NO_MAPPABLE_CONTENT;
            case 'dbvc_cc_v2_interp_result_invalid':
                return self::SKIP_RESULT_INVALID;
        }

        return DBVC_CC_Interp_Model::SKIP_TRANSPORT_ERROR;
    }

    /**
     * Options for one interpretation call.
     *
     * `use_model` is the flag, so a missing key surfaces as `no_api_key` from the model client
     * rather than as the adapter's generic "model disabled". The filtered array cannot introduce
     * an `api_key`: the bridge resolves the real one and the filter's copy is discarded.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function build_options(array $overrides)
    {
        $options = array_merge(['use_model' => true], $overrides);

        /**
         * Filter the options handed to the interpretation boundary for one page.
         *
         * Intended for an injectable `transport`, which is how the model path is exercised with
         * no network. `api_key` is removed from the result; keys resolve from a constant, then
         * the environment, then the option, and must not be settable from configuration.
         *
         * @param array<string, mixed> $options
         */
        $filtered = apply_filters(self::FILTER_INTERPRETATION_OPTIONS, $options);
        if (! is_array($filtered)) {
            return $options;
        }

        unset($filtered['api_key']);

        return $filtered;
    }
}
