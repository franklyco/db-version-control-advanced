<?php

/**
 * The WordPress adapter for the interpretation boundary.
 *
 * `addons/content-migration/interpretation/` is deliberately WordPress-independent: plain arrays
 * in, plain arrays out, so it can be measured headless against the frozen corpus and so the model
 * and the deterministic floor stay interchangeable. The cost of that independence is that it knows
 * nothing about runs, catalogs or artifacts — and until this file existed, nothing called it at
 * all. `grep -rln "DBVC_CC_Interp" .` returned only its own files and the evaluator, so D-2 ("the
 * AI Layer makes no outbound model call") was still true of the shipping product even after S1b
 * measured the interpreter at 100% field accuracy. That is D-20.
 *
 * This is the whole adapter the V3 guide's section 7.2 describes, and nothing more:
 *
 *   - read the live target field catalog into `corpus-target-schema.v1`;
 *   - read the captured artifacts into `interpretation-input.v1`;
 *   - call the boundary and return `interpretation-result.v1`.
 *
 * It does not decide anything. Which recommendation a reviewer sees is still V2's mapping stage;
 * routing that through this result is the next slice, and until it lands an interpreted run stores
 * the result beside the mapping artifacts rather than driving them.
 *
 * The model is off unless an operator turns it on. The floor is the default so that enabling a
 * paid outbound call per page is always a deliberate act.
 */

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Interpretation_Bridge_Service
{
    /**
     * Core post fields every post-type object can receive, with the purpose text the interpreter
     * reads. The purposes are written for a model, not for a UI: they say what the field is FOR,
     * because that is the signal lexical overlap could not use.
     */
    private const CORE_POST_FIELDS = [
        'core:post_title' => [
            'label' => 'Title',
            'type' => 'text',
            'shape' => 'scalar_string',
            'purpose' => 'The page title. Usually the main heading of the page.',
        ],
        'core:post_name' => [
            'label' => 'Slug',
            'type' => 'slug',
            'shape' => 'slug',
            'purpose' => 'The URL slug for the page.',
        ],
        'core:post_excerpt' => [
            'label' => 'Excerpt',
            'type' => 'textarea',
            'shape' => 'scalar_string',
            'purpose' => 'A short summary of the page, used in listings and search results.',
        ],
        'core:post_content' => [
            'label' => 'Content',
            'type' => 'wysiwyg',
            'shape' => 'rich_text',
            'purpose' => 'The main body content of the page.',
        ],
    ];

    /**
     * @var DBVC_CC_V2_Interpretation_Bridge_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Interpretation_Bridge_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Is the model path enabled, and is there a key to use?
     *
     * Both halves matter. A flag with no key would attempt a call per page and fall back to the
     * floor every time, which costs latency and produces a run whose artifacts all carry a
     * fallback reason — honest, but pointlessly slow.
     *
     * @return bool
     */
    public function model_is_available()
    {
        if (! $this->model_is_enabled()) {
            return false;
        }

        return DBVC_CC_Settings_Service::get_openai_api_key() !== ''
            || $this->anthropic_key() !== '';
    }

    /**
     * @return bool
     */
    public function model_is_enabled()
    {
        return get_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '0') === '1';
    }

    /**
     * Interpret one page's captured content against the live target schema.
     *
     * @param array<string, mixed> $page_context   Carries raw_artifact, elements and sections.
     * @param array<string, mixed> $catalog        target-field-catalog.v2
     * @param string               $object_key     The target object the page routes to.
     * @param array<string, mixed> $options        Overrides, chiefly 'transport' for tests.
     * @return array<string, mixed>|WP_Error interpretation-result.v1
     */
    public function interpret_page(array $page_context, array $catalog, $object_key, array $options = [])
    {
        // An unrouted page has no target object, so there is nothing to map onto. Refusing here
        // beats building a schema whose only fields are the generic core four and reporting the
        // result as if the page had been interpreted against its real destination.
        if (sanitize_key((string) $object_key) === '') {
            return new WP_Error(
                'dbvc_cc_v2_interp_object_key_required',
                __('Interpretation needs the target object the page routes to.', 'dbvc')
            );
        }

        $schema = $this->build_target_schema($catalog, $object_key);
        $schema_errors = DBVC_CC_Interp_Contracts::validate_schema($schema);
        if ($schema_errors !== []) {
            return new WP_Error(
                'dbvc_cc_v2_interp_schema_invalid',
                sprintf(
                    __('The live target schema could not be adapted for interpretation: %s', 'dbvc'),
                    implode('; ', $schema_errors)
                )
            );
        }

        $input = $this->build_interpretation_input($page_context);
        $input_errors = DBVC_CC_Interp_Contracts::validate_input($input);
        if ($input_errors !== []) {
            return new WP_Error(
                'dbvc_cc_v2_interp_input_invalid',
                sprintf(
                    __('The captured artifacts could not be adapted for interpretation: %s', 'dbvc'),
                    implode('; ', $input_errors)
                )
            );
        }

        $result = DBVC_CC_Interp_Service::interpret(
            $input,
            $schema,
            (string) $object_key,
            $this->build_options($options)
        );

        // The boundary validates its own output, but a caller that trusts an unvalidated result
        // would reintroduce D-3 from the other side: provenance that says a model ran when the
        // shape says otherwise.
        $result_errors = DBVC_CC_Interp_Contracts::validate_result($result);
        if ($result_errors !== []) {
            return new WP_Error(
                'dbvc_cc_v2_interp_result_invalid',
                sprintf(
                    __('The interpretation boundary returned an invalid result: %s', 'dbvc'),
                    implode('; ', $result_errors)
                )
            );
        }

        return $result;
    }

    /**
     * Adapt `target-field-catalog.v2` into `corpus-target-schema.v1`.
     *
     * Only the object the page routes to is included. Handing the interpreter every object on the
     * site would inflate the prompt, and the budget ceiling in the model client exists precisely
     * because a 150-page run must not quietly become a five-figure bill.
     *
     * @param array<string, mixed> $catalog
     * @param string               $object_key
     * @return array<string, mixed>
     */
    public function build_target_schema(array $catalog, $object_key)
    {
        $object_key = sanitize_key((string) $object_key);
        $object_catalog = isset($catalog['object_catalog']) && is_array($catalog['object_catalog'])
            ? $catalog['object_catalog']
            : [];

        $entry = isset($object_catalog[$object_key]) && is_array($object_catalog[$object_key])
            ? $object_catalog[$object_key]
            : [];

        $fields = [];
        foreach (self::CORE_POST_FIELDS as $field_ref => $spec) {
            $fields[] = [
                'field_ref' => $field_ref,
                'label' => $spec['label'],
                'type' => $spec['type'],
                'purpose' => $spec['purpose'],
                'value_contract' => ['shape' => $spec['shape']],
            ];
        }

        $fields = array_merge(
            $fields,
            $this->collect_acf_fields($catalog, $object_key),
            $this->collect_meta_fields($catalog, $object_key)
        );

        return [
            'schema' => DBVC_CC_Interp_Contracts::SCHEMA_TYPE,
            'name' => isset($catalog['domain']) ? (string) $catalog['domain'] : '',
            'generated_at' => gmdate('c'),
            'field_ref_format' => 'core:<wp_field> | acf:<group>:<field> | meta:post:<subtype>:<key>',
            'source' => [
                'artifact_type' => isset($catalog['artifact_type']) ? (string) $catalog['artifact_type'] : '',
                'catalog_fingerprint' => isset($catalog['catalog_fingerprint'])
                    ? (string) $catalog['catalog_fingerprint']
                    : '',
            ],
            'objects' => [
                [
                    'object_key' => $object_key,
                    'type_family' => isset($entry['type_family']) ? (string) $entry['type_family'] : 'post_type',
                    'label' => isset($entry['label']) ? (string) $entry['label'] : $object_key,
                    'content_model' => isset($entry['content_model']) ? (string) $entry['content_model'] : '',
                    'entity_role' => isset($entry['entity_role']) ? (string) $entry['entity_role'] : '',
                    'cardinality' => 'one_per_source_url',
                    'fields' => $fields,
                ],
            ],
            'taxonomies' => [],
        ];
    }

    /**
     * Adapt the captured artifacts into `interpretation-input.v1`.
     *
     * Built from `elements.v2` rather than from markdown: the elements artifact already carries
     * the tag, the heading level, the link target and the site-chrome flag, so the kinds are read
     * rather than guessed. The corpus evaluator's own input builder parses markdown instead, and
     * says in its header that it is a stand-in for exactly this.
     *
     * Site chrome is excluded. A logo and a footer menu appear on every page, carry no
     * page-specific meaning, and would spend prompt budget competing with real content.
     *
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    public function build_interpretation_input(array $page_context)
    {
        $raw = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact'])
            ? $page_context['raw_artifact']
            : [];
        $elements_artifact = isset($page_context['elements_artifact']) && is_array($page_context['elements_artifact'])
            ? $page_context['elements_artifact']
            : [];
        $elements = isset($elements_artifact['elements']) && is_array($elements_artifact['elements'])
            ? $elements_artifact['elements']
            : [];

        $items = [];
        $heading_context = [];
        $order = 0;

        foreach ($elements as $element) {
            if (! is_array($element) || ! empty($element['is_site_chrome'])) {
                continue;
            }

            $text = isset($element['text']) ? trim((string) $element['text']) : '';
            $tag = isset($element['tag']) ? strtolower((string) $element['tag']) : '';
            $kind = $this->kind_for_element($element, $tag, $text);
            if ($kind === '' || $text === '') {
                continue;
            }

            if ($kind === DBVC_CC_Interp_Contracts::KIND_HEADING) {
                $level = isset($element['level']) ? (int) $element['level'] : (int) substr($tag, 1);
                $level = $level > 0 ? $level : 2;
                foreach (array_keys($heading_context) as $existing) {
                    if ((int) substr($existing, 1) >= $level) {
                        unset($heading_context[$existing]);
                    }
                }
                $heading_context['h' . $level] = $text;
            }

            $order++;
            $items[] = [
                'item_id' => isset($element['element_id']) && $element['element_id'] !== ''
                    ? (string) $element['element_id']
                    : sprintf('item-%d', $order),
                'kind' => $kind,
                'text' => $text,
                'order' => $order,
                'heading_context' => $heading_context,
            ];
        }

        $items = array_merge($items, $this->head_meta_items($raw, $order));

        return [
            'artifact_type' => DBVC_CC_Interp_Contracts::INPUT_TYPE,
            'page_slug' => isset($raw['slug']) ? (string) $raw['slug'] : '',
            'source_url' => isset($raw['source_url']) ? (string) $raw['source_url'] : '',
            'generated_at' => gmdate('c'),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $element
     * @param string               $tag
     * @param string               $text
     * @return string
     */
    private function kind_for_element(array $element, $tag, $text)
    {
        if (preg_match('/^h[1-6]$/', $tag)) {
            return DBVC_CC_Interp_Contracts::KIND_HEADING;
        }

        if ($tag === 'img' || ! empty($element['image_url'])) {
            return DBVC_CC_Interp_Contracts::KIND_IMAGE;
        }

        // A short linked phrase is a call to action; a long one is body copy that happens to
        // contain a link. 40 characters is the same threshold the corpus input builder uses, so
        // the live path and the measured path agree on what a CTA is.
        $link_target = isset($element['link_target']) ? (string) $element['link_target'] : '';
        if (($tag === 'a' || $tag === 'button' || $link_target !== '') && strlen($text) < 40) {
            return DBVC_CC_Interp_Contracts::KIND_CTA;
        }

        if (in_array($tag, ['p', 'li', 'blockquote', 'figcaption', 'label', 'a', 'button', 'div', 'span'], true)) {
            return DBVC_CC_Interp_Contracts::KIND_BODY;
        }

        return '';
    }

    /**
     * Head metadata as mappable items.
     *
     * Captured meta is migration-planning data the V3 guide lists as missing scope (SEO
     * continuity). Offering it to the interpreter is how a title and description reach
     * `core:post_title` and `core:post_excerpt` rather than being silently dropped.
     *
     * @param array<string, mixed> $raw
     * @param int                  $order
     * @return array<int, array<string, mixed>>
     */
    private function head_meta_items(array $raw, $order)
    {
        $metadata = isset($raw['metadata']) && is_array($raw['metadata']) ? $raw['metadata'] : [];
        $items = [];

        foreach (['title' => 'head:title', 'description' => 'head:description'] as $key => $item_id) {
            $value = isset($metadata[$key]) ? trim((string) $metadata[$key]) : '';
            if ($value === '') {
                continue;
            }

            $order++;
            $items[] = [
                'item_id' => $item_id,
                'kind' => DBVC_CC_Interp_Contracts::KIND_HEAD_META,
                'text' => $value,
                'order' => $order,
                'heading_context' => [],
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $object_key
     * @return array<int, array<string, mixed>>
     */
    private function collect_acf_fields(array $catalog, $object_key)
    {
        $acf_catalog = isset($catalog['acf_catalog']) && is_array($catalog['acf_catalog'])
            ? $catalog['acf_catalog']
            : [];
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups'])
            ? $acf_catalog['groups']
            : [];

        $fields = [];
        foreach ($groups as $group_key => $group) {
            if (! is_array($group) || ! $this->group_matches_object($group, $object_key)) {
                continue;
            }

            $group_fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
            foreach ($group_fields as $field_key => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $field_key = sanitize_key((string) $field_key);
                if ($field_key === '') {
                    continue;
                }

                $fields[] = [
                    'field_ref' => sprintf('acf:%s:%s', sanitize_key((string) $group_key), $field_key),
                    'label' => isset($field['label']) ? (string) $field['label'] : $field_key,
                    'type' => isset($field['type']) ? (string) $field['type'] : 'text',
                    'purpose' => $this->field_purpose($field),
                    'value_contract' => [
                        'shape' => isset($field['value_contract']['shape'])
                            ? (string) $field['value_contract']['shape']
                            : 'scalar_string',
                    ],
                ];
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $object_key
     * @return array<int, array<string, mixed>>
     */
    private function collect_meta_fields(array $catalog, $object_key)
    {
        $meta_catalog = isset($catalog['meta_catalog']['post']) && is_array($catalog['meta_catalog']['post'])
            ? $catalog['meta_catalog']['post']
            : [];

        $fields = [];
        foreach ([$object_key, 'default'] as $subtype) {
            if (! isset($meta_catalog[$subtype]) || ! is_array($meta_catalog[$subtype])) {
                continue;
            }

            foreach ($meta_catalog[$subtype] as $meta_key => $entry) {
                $meta_key = sanitize_key((string) $meta_key);
                if ($meta_key === '' || ! is_array($entry)) {
                    continue;
                }

                $fields[] = [
                    'field_ref' => sprintf('meta:post:%s:%s', sanitize_key((string) $subtype), $meta_key),
                    'label' => isset($entry['label']) ? (string) $entry['label'] : $meta_key,
                    'type' => isset($entry['type']) ? (string) $entry['type'] : 'text',
                    'purpose' => $this->field_purpose($entry),
                    'value_contract' => [
                        'shape' => isset($entry['value_contract']['shape'])
                            ? (string) $entry['value_contract']['shape']
                            : 'scalar_string',
                    ],
                ];
            }
        }

        return $fields;
    }

    /**
     * The catalog resolves purpose text through several providers; take the most specific present.
     *
     * @param array<string, mixed> $entry
     * @return string
     */
    private function field_purpose(array $entry)
    {
        // `description` is last because it is the least curated of the five, but it is the only
        // one a plainly registered post meta key or an ACF field description ever carries, and
        // the live catalog is full of both. Without it those fields reach the model with a label
        // and nothing else, which is the lexical signal the slice exists to stop relying on.
        foreach (['resolved_purpose', 'effective_purpose', 'purpose', 'default_purpose', 'description'] as $key) {
            if (! empty($entry[$key]) && is_string($entry[$key])) {
                return (string) $entry[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $group
     * @param string               $object_key
     * @return bool
     */
    private function group_matches_object(array $group, $object_key)
    {
        $objects = isset($group['object_keys']) && is_array($group['object_keys'])
            ? $group['object_keys']
            : (isset($group['post_types']) && is_array($group['post_types']) ? $group['post_types'] : []);

        if ($objects === []) {
            // A group with no recorded location is offered rather than hidden. A field the
            // interpreter cannot see can never be mapped, and an over-offered field costs a
            // declined candidate; the asymmetry favours offering it.
            return true;
        }

        foreach ($objects as $candidate) {
            if (sanitize_key((string) $candidate) === $object_key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the model configuration, key included, without putting the key in an artifact.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function build_options(array $overrides)
    {
        $settings = DBVC_CC_Settings_Service::get_options();
        $anthropic_key = $this->anthropic_key();

        // Whichever provider has a key wins, Anthropic first. A transport injected by a test
        // bypasses both, which is how every branch here is covered with no network and no key.
        if ($anthropic_key !== '') {
            $options = [
                'provider' => DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC,
                'api_key' => $anthropic_key,
                'model' => isset($settings['anthropic_model']) ? (string) $settings['anthropic_model'] : '',
            ];
        } else {
            $options = [
                'provider' => DBVC_CC_Interp_Model::PROVIDER_OPENAI,
                'api_key' => DBVC_CC_Settings_Service::get_openai_api_key(),
                'model' => isset($settings['openai_model']) ? (string) $settings['openai_model'] : '',
            ];
        }

        // V1 defaulted to gpt-4o-mini and the V3 guide says not to re-adopt that default without
        // revisiting it. An empty model here means the client's own measured default applies.
        if ($options['model'] === 'gpt-4o-mini') {
            $options['model'] = '';
        }

        $options['use_model'] = $this->model_is_available();

        return array_merge($options, $overrides);
    }

    /**
     * @return string
     */
    private function anthropic_key()
    {
        if (defined('DBVC_CC_ANTHROPIC_API_KEY') && is_string(DBVC_CC_ANTHROPIC_API_KEY)) {
            return (string) DBVC_CC_ANTHROPIC_API_KEY;
        }

        $env = getenv('DBVC_CC_ANTHROPIC_API_KEY');

        return is_string($env) ? $env : '';
    }
}
