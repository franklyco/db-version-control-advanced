<?php

/**
 * One translation from a corpus slot's pinned `target-schema.v1.json` to a WordPress target.
 *
 * Three consumers read the same target through this class, so none of them can drift:
 *
 * - the paid wired measurement (`ContentMigrationCorpusWiredMeasurementTest`), which loads the
 *   ACF definitions into the suite's `$GLOBALS['dbvc_test_acf_*']` doubles;
 * - the dev-only provisioner (`scripts/content-migration-target-site.php`), which writes the same
 *   definitions into a disposable LocalWP site as a mu-plugin, so a person can time a manual
 *   migration against exactly the target the tool sees;
 * - the catalog-equality check, which proves the two are the same target.
 *
 * Dev-only. Nothing in the shipped plugin loads this file.
 *
 * ACF field keys. A real ACF install keeps one global store of field keys, and saves a field's
 * value under its name. The pinned schema reuses a leaf name across groups (`heading` in both
 * `page_hero` and `page_intro`, `items` in both `page_cards` and `page_offers`), and two of those
 * groups sit on the same post type. Under real ACF the second `heading` would resolve to the
 * first's definition and both would write the same post meta. So a leaf that appears in more than
 * one group takes its group as a prefix (`page_hero_heading`); every other leaf keeps its own
 * name. `schema_ref()` maps a catalog ref back to the schema's spelling for scoring.
 */
final class DBVC_CC_Corpus_Target_Translation
{
    /**
     * Marks a file this translation generated, so teardown only ever removes its own output.
     */
    public const GENERATED_MARKER = 'dbvc-cc-corpus-target-site: generated';

    /**
     * @var array<string, mixed>
     */
    private $schema;

    /**
     * @var array<string, array<string, string>>|null group => leaf => key
     */
    private $keys = null;

    /**
     * @param array<string, mixed> $schema target-schema.v1
     */
    public function __construct(array $schema)
    {
        $this->schema = $schema;
    }

    /**
     * @param string $path
     * @return self
     */
    public static function from_file($path)
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $schema = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($schema) || ! isset($schema['objects']) || ! is_array($schema['objects'])) {
            throw new InvalidArgumentException('Not a target-schema.v1 file: ' . $path);
        }

        return new self($schema);
    }

    /**
     * Post types to register, keyed by object key. `page` is WordPress's own.
     *
     * @return array<string, array<string, mixed>>
     */
    public function post_types()
    {
        $types = [];
        foreach ($this->objects() as $object) {
            if (($object['type_family'] ?? '') !== 'post_type' || ($object['object_key'] ?? '') === 'page') {
                continue;
            }

            $types[(string) $object['object_key']] = [
                'public' => true,
                'label' => (string) ($object['label'] ?? $object['object_key']),
                'supports' => ['title', 'editor', 'excerpt'],
            ];
        }

        return $types;
    }

    /**
     * Every post-type object in the schema, `page` included, in schema order.
     *
     * @return array<int, string>
     */
    public function post_type_object_keys()
    {
        $keys = [];
        foreach ($this->objects() as $object) {
            if (($object['type_family'] ?? '') === 'post_type') {
                $keys[] = (string) $object['object_key'];
            }
        }

        return $keys;
    }

    /**
     * Post meta to register, one entry per `meta:` field.
     *
     * @return array<int, array{object_key: string, meta_key: string, args: array<string, mixed>}>
     */
    public function post_meta()
    {
        $meta = [];
        foreach ($this->objects() as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                $field_ref = (string) ($field['field_ref'] ?? '');
                if (strpos($field_ref, 'meta:') !== 0) {
                    continue;
                }

                $parts = explode(':', $field_ref);
                $meta[] = [
                    'object_key' => (string) $object['object_key'],
                    'meta_key' => (string) end($parts),
                    'args' => [
                        'single' => true,
                        'type' => 'string',
                        'show_in_rest' => true,
                        'description' => (string) ($field['purpose'] ?? $field['label'] ?? ''),
                    ],
                ];
            }
        }

        return $meta;
    }

    /**
     * ACF field groups in `acf_add_local_field_group()` form: each group carries its fields,
     * and a repeater carries its sub-fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function field_groups()
    {
        $groups = [];
        foreach ($this->objects() as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                $field_ref = (string) ($field['field_ref'] ?? '');
                if (strpos($field_ref, 'acf:') !== 0) {
                    continue;
                }

                list(, $group_key) = explode(':', $field_ref);
                if (! isset($groups[$group_key])) {
                    $groups[$group_key] = [
                        'key' => $group_key,
                        'name' => $group_key,
                        'title' => ucwords(str_replace('_', ' ', $group_key)),
                        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => (string) $object['object_key']]]],
                        'fields' => [],
                    ];
                }

                $groups[$group_key]['fields'][] = $this->acf_field($field, $group_key);
            }
        }

        return array_values($groups);
    }

    /**
     * The same groups as the suite's ACF doubles read them: `acf_get_field_groups()` returns the
     * groups without fields, `acf_get_fields($group_key)` returns one group's fields.
     *
     * @return array{groups: array<int, array<string, mixed>>, fields: array<string, array<int, array<string, mixed>>>}
     */
    public function acf_doubles()
    {
        $groups = [];
        $fields = [];
        foreach ($this->field_groups() as $group) {
            $fields[(string) $group['key']] = $group['fields'];
            unset($group['fields']);
            $groups[] = $group;
        }

        return ['groups' => $groups, 'fields' => $fields];
    }

    /**
     * The ACF key (and name) a schema leaf takes in its group.
     *
     * @param string $group_key
     * @param string $leaf
     * @return string
     */
    public function acf_key($group_key, $leaf)
    {
        $keys = $this->keys();

        return isset($keys[$group_key][$leaf]) ? $keys[$group_key][$leaf] : (string) $leaf;
    }

    /**
     * Map a catalog `acf:<group>:<key>` ref back to the schema's leaf spelling, `acf:<group>:<leaf>`.
     * Any other ref is returned unchanged.
     *
     * @param string $target_ref
     * @return string
     */
    public function schema_ref($target_ref)
    {
        $target_ref = (string) $target_ref;
        if (strpos($target_ref, 'acf:') !== 0) {
            return $target_ref;
        }

        $parts = explode(':', $target_ref);
        if (count($parts) !== 3) {
            return $target_ref;
        }

        foreach ($this->keys()[$parts[1]] ?? [] as $leaf => $key) {
            if ($key === $parts[2]) {
                return 'acf:' . $parts[1] . ':' . $leaf;
            }
        }

        return $target_ref;
    }

    /**
     * The mu-plugin a disposable site loads. It is self-contained: the definitions are written
     * out as literals, so the site never reads this repository.
     *
     * @param string $source_label Where the schema came from, recorded in the file header.
     * @return string PHP source.
     */
    public function render_mu_plugin($source_label)
    {
        $definition = [
            'post_types' => $this->post_types(),
            'post_meta' => $this->post_meta(),
            'field_groups' => $this->field_groups(),
        ];

        $source = <<<'PHP'
<?php
/**
 * Plugin Name: DBVC corpus target site
 * Description: The content-migration corpus target, generated from a pinned target-schema.v1.json. Dev-only.
 *
 * __MARKER__
 * Source: __SOURCE__
 * Do not edit: rerun the provisioner instead.
 */

if (! defined('ABSPATH')) {
    exit;
}

$dbvc_cc_corpus_target = __DEFINITION__;

add_action('init', static function () use ($dbvc_cc_corpus_target) {
    foreach ($dbvc_cc_corpus_target['post_types'] as $post_type => $args) {
        register_post_type($post_type, $args);
    }
    foreach ($dbvc_cc_corpus_target['post_meta'] as $meta) {
        register_post_meta($meta['object_key'], $meta['meta_key'], $meta['args']);
    }
}, 5);

add_action('acf/init', static function () use ($dbvc_cc_corpus_target) {
    foreach ($dbvc_cc_corpus_target['field_groups'] as $group) {
        acf_add_local_field_group($group);
    }
});

// Registered meta has no editing screen of its own, and the SEO keys are protected (`_`-prefixed),
// so WordPress's Custom Fields box hides them. Without this box a person migrating by hand could
// not populate fields the tool can, and the comparison would be unfair to the manual side.
add_action('add_meta_boxes', static function ($post_type) use ($dbvc_cc_corpus_target) {
    $keys = [];
    foreach ($dbvc_cc_corpus_target['post_meta'] as $meta) {
        if ($meta['object_key'] === $post_type) {
            $keys[$meta['meta_key']] = $meta['args']['description'];
        }
    }
    if ($keys === []) {
        return;
    }
    add_meta_box('dbvc-cc-corpus-target-meta', 'Target meta fields', static function ($post) use ($keys) {
        wp_nonce_field('dbvc_cc_corpus_target_meta', 'dbvc_cc_corpus_target_meta_nonce');
        foreach ($keys as $key => $description) {
            printf(
                '<p><label for="%1$s"><strong>%1$s</strong></label><br><input type="text" class="widefat" id="%1$s" name="dbvc_cc_corpus_target_meta[%1$s]" value="%2$s"><br><span class="description">%3$s</span></p>',
                esc_attr($key),
                esc_attr((string) get_post_meta($post->ID, $key, true)),
                esc_html($description)
            );
        }
    }, $post_type, 'normal');
});

add_action('save_post', static function ($post_id) use ($dbvc_cc_corpus_target) {
    if (
        ! isset($_POST['dbvc_cc_corpus_target_meta_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dbvc_cc_corpus_target_meta_nonce'])), 'dbvc_cc_corpus_target_meta')
        || ! current_user_can('edit_post', $post_id)
        || ! isset($_POST['dbvc_cc_corpus_target_meta']) || ! is_array($_POST['dbvc_cc_corpus_target_meta'])
    ) {
        return;
    }
    $post_type = get_post_type($post_id);
    $submitted = wp_unslash($_POST['dbvc_cc_corpus_target_meta']);
    foreach ($dbvc_cc_corpus_target['post_meta'] as $meta) {
        if ($meta['object_key'] === $post_type && isset($submitted[$meta['meta_key']])) {
            update_post_meta($post_id, $meta['meta_key'], sanitize_text_field((string) $submitted[$meta['meta_key']]));
        }
    }
});

PHP;

        return strtr($source, [
            '__MARKER__' => self::GENERATED_MARKER,
            '__SOURCE__' => str_replace(['*/', "\n"], ['* /', ' '], (string) $source_label),
            '__DEFINITION__' => var_export($definition, true),
        ]);
    }

    /**
     * @param array<string, mixed> $field
     * @param string               $group_key
     * @return array<string, mixed>
     */
    private function acf_field(array $field, $group_key)
    {
        $parts = explode(':', (string) $field['field_ref']);
        $leaf = explode('.', (string) end($parts));
        // A real ACF sub-field has its own key; the schema writes the repeater path for
        // readability, so the leaf is the field's own name.
        $key = $this->acf_key($group_key, (string) end($leaf));

        $entry = [
            'key' => $key,
            'name' => $key,
            'label' => (string) ($field['label'] ?? $key),
            'type' => (string) ($field['type'] ?? 'text'),
            'required' => ! empty($field['required']),
            'description' => (string) ($field['purpose'] ?? ''),
            // ACF's own key for the text shown under a field. The catalog reads it as the purpose
            // of last resort, and a person migrating by hand sees it on the edit screen.
            'instructions' => (string) ($field['purpose'] ?? ''),
        ];

        if (! empty($field['sub_fields'])) {
            $entry['sub_fields'] = [];
            foreach ($field['sub_fields'] as $sub) {
                $entry['sub_fields'][] = $this->acf_field($sub, $group_key);
            }
        }

        return $entry;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function keys()
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $groups_by_leaf = [];
        $collect = static function (array $field, $group_key) use (&$collect, &$groups_by_leaf) {
            $parts = explode(':', (string) ($field['field_ref'] ?? ''));
            $leaf = explode('.', (string) end($parts));
            $groups_by_leaf[(string) end($leaf)][$group_key] = true;
            foreach ($field['sub_fields'] ?? [] as $sub) {
                $collect($sub, $group_key);
            }
        };

        foreach ($this->objects() as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                $field_ref = (string) ($field['field_ref'] ?? '');
                if (strpos($field_ref, 'acf:') === 0) {
                    $collect($field, explode(':', $field_ref)[1]);
                }
            }
        }

        $this->keys = [];
        foreach ($groups_by_leaf as $leaf => $groups) {
            foreach (array_keys($groups) as $group_key) {
                $this->keys[$group_key][$leaf] = count($groups) > 1 ? $group_key . '_' . $leaf : $leaf;
            }
        }

        return $this->keys;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function objects()
    {
        return isset($this->schema['objects']) && is_array($this->schema['objects']) ? $this->schema['objects'] : [];
    }
}
