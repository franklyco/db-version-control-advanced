<?php

require_once dirname(__DIR__, 2) . '/addons/content-migration/tests/corpus/target/corpus-target-translation.php';
require_once dirname(__DIR__, 2) . '/addons/content-migration/tests/corpus/target/corpus-target-projection.php';

if (! function_exists('acf_get_field_groups')) {
    function acf_get_field_groups()
    {
        return isset($GLOBALS['dbvc_test_acf_groups']) && is_array($GLOBALS['dbvc_test_acf_groups'])
            ? $GLOBALS['dbvc_test_acf_groups']
            : [];
    }
}

if (! function_exists('acf_get_fields')) {
    function acf_get_fields($group_key)
    {
        $group_key = sanitize_key((string) $group_key);
        return isset($GLOBALS['dbvc_test_acf_fields'][$group_key]) && is_array($GLOBALS['dbvc_test_acf_fields'][$group_key])
            ? $GLOBALS['dbvc_test_acf_fields'][$group_key]
            : [];
    }
}

/**
 * The corpus target a person migrates into by hand is the target the tool and the paid wired
 * measurement see.
 *
 * The unit-economics baseline times a manual migration against a tool-assisted one. If the two
 * landed in different targets, the comparison would measure the targets, not the methods. So one
 * translation (`DBVC_CC_Corpus_Target_Translation`) builds both the suite's ACF doubles and the
 * mu-plugin a disposable LocalWP site loads (`scripts/content-migration-target-site.php`). These
 * tests pin that translation. The last one compares the doubles with a real provisioned site,
 * through the shipped catalog and bridge, when one is available.
 */
class ContentMigrationCorpusTargetProvisioningTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $domain = 'corpus-target-projection.test';

    /**
     * @var DBVC_CC_Corpus_Target_Translation
     */
    private $target;

    public function set_up(): void
    {
        parent::set_up();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        $this->target = DBVC_CC_Corpus_Target_Translation::from_file($this->schema_path());
    }

    public function tear_down(): void
    {
        $this->delete_domain_artifacts();
        unset($GLOBALS['dbvc_test_acf_groups'], $GLOBALS['dbvc_test_acf_fields']);

        parent::tear_down();
    }

    public function test_every_post_type_field_in_the_pinned_schema_reaches_the_interpreter_with_its_purpose()
    {
        $projection = $this->project_doubles();

        foreach ($this->schema()['objects'] as $object) {
            if (($object['type_family'] ?? '') !== 'post_type') {
                continue;
            }

            $object_key = (string) $object['object_key'];
            $this->assertArrayHasKey($object_key, $projection);
            $offered = [];
            foreach ($projection[$object_key]['objects'][0]['fields'] as $field) {
                $offered[$this->schema_spelling((string) $field['field_ref'])] = $field;
            }

            foreach ($this->schema_fields($object['fields'] ?? []) as $ref => $field) {
                $this->assertArrayHasKey($ref, $offered, sprintf('%s is not offered to the interpreter for %s.', $ref, $object_key));
                if (strpos($ref, 'core:') !== 0) {
                    $this->assertSame(
                        (string) ($field['purpose'] ?? ''),
                        (string) $offered[$ref]['purpose'],
                        sprintf('%s reaches the interpreter without its purpose.', $ref)
                    );
                }
            }

        }
    }

    /**
     * An ACF group is offered only to the object its location rule names (D-27). The other
     * objects still reach the interpreter as collections, with their own fields. (The translation
     * locates the `practice_info` options-page group on a post type of that name, which no object
     * here is, so it is offered to none.)
     */
    public function test_an_acf_group_is_offered_only_to_the_object_its_location_names()
    {
        $projection = $this->project_doubles();

        $expected = [];
        foreach ($this->target->field_groups() as $group) {
            $expected[(string) $group['location'][0][0]['value']][] = (string) $group['key'];
        }

        foreach ($projection as $object_key => $schema) {
            foreach ($schema['objects'] as $object) {
                $groups = [];
                foreach ($object['fields'] as $field) {
                    $parts = explode(':', (string) $field['field_ref']);
                    if ($parts[0] === 'acf') {
                        $groups[$parts[1]] = true;
                    }
                }
                $offered = array_keys($groups);
                sort($offered);
                $want = $expected[(string) $object['object_key']] ?? [];
                sort($want);
                $this->assertSame($want, $offered, sprintf('Groups offered for %s (projected as %s).', $object['object_key'], $object_key));
            }
        }
        $this->assertArrayHasKey('practice_info', $expected, 'The options-page group should exist to be excluded.');
    }

    /**
     * The location rule as the catalog records it decides which object sees a group: a post-type
     * rule offers it to that type, a taxonomy or options-page rule to no post-type object, and a
     * group with no recorded rule is offered rather than hidden. The ACF purpose is read from
     * where the catalog nests it.
     */
    public function test_the_bridge_reads_location_and_purpose_where_the_catalog_writes_them()
    {
        $field = static function ($key, $purpose) {
            return [$key => ['key' => $key, 'label' => $key, 'type' => 'text', 'field_context' => ['resolved_purpose' => $purpose]]];
        };
        $catalog = [
            'object_catalog' => ['page' => ['type_family' => 'post_type', 'label' => 'Page']],
            'acf_catalog' => ['groups' => [
                'on_page' => ['object_context' => ['post_types' => ['page']], 'fields' => $field('a', 'Purpose of a.')],
                'on_post' => ['object_context' => ['post_types' => ['post']], 'fields' => $field('b', 'Purpose of b.')],
                'on_options' => ['object_context' => ['post_types' => [], 'options_pages' => ['settings']], 'fields' => $field('c', 'Purpose of c.')],
                'on_term' => ['object_context' => ['post_types' => [], 'taxonomies' => ['category']], 'fields' => $field('d', 'Purpose of d.')],
                'unlocated' => ['object_context' => [], 'fields' => $field('e', 'Purpose of e.')],
            ]],
        ];

        $schema = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->build_target_schema($catalog, 'page');
        $acf = [];
        foreach ($schema['objects'][0]['fields'] as $entry) {
            if (strpos((string) $entry['field_ref'], 'acf:') === 0) {
                $acf[(string) $entry['field_ref']] = (string) $entry['purpose'];
            }
        }

        $this->assertSame(['acf:on_page:a' => 'Purpose of a.', 'acf:unlocated:e' => 'Purpose of e.'], $acf);
    }

    public function test_a_real_acf_install_can_hold_the_target_without_two_fields_colliding()
    {
        $keys = [];
        $names_by_post_type = [];
        $walk = static function (array $fields, $post_type) use (&$walk, &$keys, &$names_by_post_type) {
            foreach ($fields as $field) {
                $keys[] = (string) $field['key'];
                $names_by_post_type[$post_type][] = (string) $field['name'];
                $walk($field['sub_fields'] ?? [], $post_type);
            }
        };

        foreach ($this->target->field_groups() as $group) {
            $walk($group['fields'], (string) $group['location'][0][0]['value']);
        }

        // ACF keeps one store of field keys for the whole site, and saves a value under the
        // field's name, so a repeated key loses a definition and a repeated name on one post type
        // loses a value.
        $this->assertSame(array_values(array_unique($keys)), $keys, 'Two ACF fields share a key.');
        foreach ($names_by_post_type as $post_type => $names) {
            $this->assertSame(array_values(array_unique($names)), $names, sprintf('Two ACF fields on %s share a name.', $post_type));
        }

        // The schema reuses these leaves across groups, so each takes its group as a prefix.
        $this->assertSame('page_hero_heading', $this->target->acf_key('page_hero', 'heading'));
        $this->assertSame('page_intro_heading', $this->target->acf_key('page_intro', 'heading'));
        $this->assertSame('page_cards_items', $this->target->acf_key('page_cards', 'items'));
        // A leaf used once keeps its own name, so values land where a person would look for them.
        $this->assertSame('card_title', $this->target->acf_key('page_cards', 'card_title'));
        $this->assertSame('role', $this->target->acf_key('team_member', 'role'));
        $this->assertSame('acf:page_hero:heading', $this->target->schema_ref('acf:page_hero:page_hero_heading'));
        $this->assertSame('acf:team_member:role', $this->target->schema_ref('acf:team_member:role'));
    }

    public function test_the_site_mu_plugin_registers_exactly_the_translation()
    {
        $file = wp_normalize_path(get_temp_dir() . 'dbvc-cc-corpus-target-' . wp_generate_password(8, false) . '.php');
        file_put_contents($file, $this->target->render_mu_plugin('target-schema.v1.json'));

        $definition = (static function ($file) {
            include $file;

            return isset($dbvc_cc_corpus_target) ? $dbvc_cc_corpus_target : null;
        })($file);
        $source = (string) file_get_contents($file);
        @unlink($file);

        $this->assertStringContainsString(DBVC_CC_Corpus_Target_Translation::GENERATED_MARKER, $source);
        $this->assertSame([
            'post_types' => $this->target->post_types(),
            'post_meta' => $this->target->post_meta(),
            'field_groups' => $this->target->field_groups(),
        ], $definition);
    }

    /**
     * The discriminating check: the provisioned site's real ACF and the suite's doubles yield the
     * same interpreter-facing target. Needs a projection written on the site by
     * `scripts/content-migration-target-site.php verify`, so it is skipped without one.
     */
    public function test_the_provisioned_site_presents_the_same_target_as_the_wired_measurement()
    {
        $path = getenv('DBVC_CC_TARGET_SITE_PROJECTION');
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            $this->markTestSkipped('Set DBVC_CC_TARGET_SITE_PROJECTION to the JSON the provisioner\'s verify step wrote.');
        }

        $site = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($site);
        $projection = isset($site['projection']) && is_array($site['projection']) ? $site['projection'] : [];

        // One named exclusion, measured rather than assumed: ACF itself registers `_acf_changed`
        // (a revision-tracking flag) as post meta on every post type, so a real ACF site offers it
        // and the doubles, which load no ACF, cannot. It is removed only where it is present, and
        // it must be present, so the exclusion cannot quietly widen or outlive its reason.
        $excluded = 0;
        foreach ($projection as $object_key => $schema) {
            foreach ($schema['objects'] as $index => $object) {
                $kept = array_values(array_filter($object['fields'], static function ($field) {
                    return ($field['field_ref'] ?? '') !== 'meta:post:default:_acf_changed';
                }));
                $excluded += count($object['fields']) - count($kept);
                $projection[$object_key]['objects'][$index]['fields'] = $kept;
            }
        }
        $this->assertGreaterThan(0, $excluded, 'The `_acf_changed` exclusion no longer applies; remove it.');

        $this->assertSame(
            wp_json_encode($this->project_doubles(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            wp_json_encode($projection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function project_doubles()
    {
        foreach ($this->target->post_types() as $post_type => $args) {
            register_post_type($post_type, $args);
        }
        foreach ($this->target->post_meta() as $meta) {
            register_post_meta($meta['object_key'], $meta['meta_key'], $meta['args']);
        }
        // WordPress core registers `footnotes` on `init` for every post type that supports it, so
        // a real site always offers it on `page`. The suite resets post types between tests,
        // which drops it after the first test in a process; registering it here, as `init` does,
        // makes the doubles a real site's regardless of test order.
        register_block_core_footnotes_post_meta();
        $doubles = $this->target->acf_doubles();
        $GLOBALS['dbvc_test_acf_groups'] = $doubles['groups'];
        $GLOBALS['dbvc_test_acf_fields'] = $doubles['fields'];

        $projection = DBVC_CC_Corpus_Target_Projection::project($this->domain, $this->target);
        $this->assertNotWPError($projection);

        return $projection;
    }

    /**
     * Schema fields keyed by the spelling the catalog's refs are mapped back to: a repeater's
     * sub-fields flattened beside it, meta without its subtype.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function schema_fields(array $fields)
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$this->schema_spelling((string) $field['field_ref'])] = $field;
            if (! empty($field['sub_fields'])) {
                $out = array_merge($out, $this->schema_fields($field['sub_fields']));
            }
        }

        return $out;
    }

    /**
     * @param string $ref
     * @return string
     */
    private function schema_spelling($ref)
    {
        if (strpos($ref, 'meta:post:') === 0) {
            $parts = explode(':', $ref);

            return 'meta:' . end($parts);
        }

        if (strpos($ref, 'acf:') === 0 && strpos($ref, '.') !== false) {
            $parts = explode(':', $ref);
            $leaf = explode('.', (string) array_pop($parts));
            $parts[] = (string) end($leaf);
            $ref = implode(':', $parts);
        }

        return $this->target->schema_ref($ref);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema()
    {
        return json_decode((string) file_get_contents($this->schema_path()), true);
    }

    /**
     * @return string
     */
    private function schema_path()
    {
        return dirname(__DIR__, 2)
            . '/addons/content-migration/tests/corpus/target/target-schema.v1.json';
    }

    /**
     * @return void
     */
    private function delete_domain_artifacts()
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
        if (is_wp_error($context)) {
            return;
        }

        $domain_dir = $context['domain_dir'];
        if (! is_dir($domain_dir) || ! dbvc_cc_path_is_within($domain_dir, DBVC_CC_Artifact_Manager::get_storage_base_dir())) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domain_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($domain_dir);
    }
}
