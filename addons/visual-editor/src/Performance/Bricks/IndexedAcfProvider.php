<?php

namespace Dbvc\VisualEditor\Performance\Bricks;

use Bricks\Integrations\Dynamic_Data\Providers\Provider_Acf;
use ReflectionMethod;

/**
 * R5.later-perf-a — Bricks' ACF dynamic-data provider with an indexed
 * nested-group lookup (E-159, D-082).
 *
 * Only ever instantiated by AcfTagIndexShim, only when the installed Bricks
 * code matches a fingerprint in ProviderFingerprint::KNOWN, and only after the
 * registries it produces have been verified identical to the stock provider's
 * on this site (AcfTagIndexShim::verify()).
 *
 * Two methods are overridden:
 *
 * - register_tag(): a verbatim copy of Bricks 2.3.8's implementation
 *   (themes/bricks/includes/integrations/dynamic-data/providers/provider-acf.php,
 *   GPL-2.0-or-later, © Bricks Builder) with exactly two added statements —
 *   `$this->index->indexTag(...)` after each `$this->tags[ $name ] = $tag;` —
 *   and the two private-parent calls routed through reflection. Nothing else
 *   is changed; the fingerprint pin guarantees the copy still matches the
 *   installed original.
 * - get_nested_parent_group_field_data(): the same data as the stock method,
 *   with the linear scan over every registered tag replaced by
 *   AcfTagIndex::lookup(), which returns the identical answer ("first tag in
 *   array order whose current sub-fields carry the key").
 *
 * The constructor is never run (the shim uses newInstanceWithoutConstructor and
 * transplants the stock instance's state), so the three instance filters the
 * base constructor registers are never duplicated.
 */
final class IndexedAcfProvider extends Provider_Acf
{
    /**
     * @var AcfTagIndex
     */
    private $index;

    /**
     * @var array<string, ReflectionMethod>
     */
    private $parentMethods = [];

    /**
     * Prepare an instance without running the Bricks constructor.
     *
     * @param string $name provider name (always 'acf')
     * @return void
     */
    public function adopt($name)
    {
        $this->name = $name;
        $this->tags = [];
        $this->loop_tags = [];
        $this->index = new AcfTagIndex();
    }

    /**
     * @param string $method
     * @param object|null $target
     * @param mixed ...$args
     * @return mixed
     */
    private function callParentPrivate($method, $target, ...$args)
    {
        if (! isset($this->parentMethods[$method])) {
            $reflection = new ReflectionMethod(Provider_Acf::class, $method);
            $reflection->setAccessible(true);
            $this->parentMethods[$method] = $reflection;
        }

        return $this->parentMethods[$method]->invoke($target, ...$args);
    }

    /**
     * Stock data assembly; the scan is AcfTagIndex::lookup().
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $parent_field
     * @return array<string, mixed>
     */
    public function get_nested_parent_group_field_data($data = [], $parent_field = [])
    {
        if (! isset($data['name']) || ! isset($parent_field['key'])) {
            return $data;
        }

        $field_key = $parent_field['key'];

        // Concatenate the field name with the parent field name
        $data['name'] = $parent_field['name'] . '_' . $data['name'];

        // Record the parent field locations
        if (! empty($parent_field['_bricks_locations'])) {
            $data['_bricks_locations'] = array_merge($data['_bricks_locations'], $parent_field['_bricks_locations']);
        }

        // Record the parent group names
        if (! empty($parent_field['name'])) {
            $data['parent_group_names'][] = $parent_field['name'];
        }

        $found_name = $this->index->lookup($field_key, $this->tags);

        if ($found_name !== null) {
            // $this->tags[ $found_name ]['field'] is the nested parent group field
            $data = $this->get_nested_parent_group_field_data($data, $this->tags[$found_name]['field']); // Recursive
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Vendored from Bricks 2.3.8 Provider_Acf::register_tag() — see class docblock.
    // -------------------------------------------------------------------------

    public function register_tag( $field, $parent_field = [], $parent_tag = [] ) {
        $contexts = $this->callParentPrivate( 'get_fields_by_context', null );

        $type = $field['type'];

        if ( ! isset( $contexts[ $type ] ) ) {
            return;
        }

        foreach ( $contexts[ $type ] as $context ) {
            // Add parent field name to the field name if needed
            $name = ! empty( $parent_field['name'] ) ? 'acf_' . $parent_field['name'] . '_' . $field['name'] : 'acf_' . $field['name'];

            // Add the context to the field name (legacy)
            if ( $context !== self::CONTEXT_TEXT && $context !== self::CONTEXT_LOOP && empty( $parent_field ) ) {
                $name .= '_' . $context;
            }

            $label = ! empty( $parent_field['label'] ) ? $field['label'] . ' (' . $parent_field['label'] . ')' : $field['label'];

            if ( $context === self::CONTEXT_LOOP ) {
                $label = 'ACF ' . ucfirst( $type ) . ': ' . $label;
            }

            $tag = [
                'group'    => 'ACF',
                'field'    => $field,
                'provider' => $this->name,
            ];

            if ( ! empty( $parent_field ) ) {
                // Add the parent field attributes to the child tag so we could retrieve the value of group sub-fields
                $tag['parent'] = [
                    'key'  => $parent_field['key'],
                    'name' => $parent_field['name'],
                    'type' => $parent_field['type'],
                ];

                // Handle nested group field (@since 1.9.1)
                if ( $parent_field['type'] === 'group' ) {
                    // Group by parent field, better visual in DD dropdown
                    $tag['group'] = 'ACF: ' . $parent_field['label'];

                    $data = [
                        'name'               => $field['name'],
                        '_bricks_locations'  => [],
                        'parent_group_names' => [],
                    ];

                    $nested_group_data = $this->get_nested_parent_group_field_data( $data, $parent_field );

                    $nested_name = 'acf_' . $nested_group_data['name'];

                    if ( $nested_name !== $name ) {
                        // This is a nested group field
                        $tag['nested_group'] = true;
                        // Use the nested name
                        $name = $nested_name;
                        // Save the origin grand grand parent field
                        $tag['_bricks_locations'] = $nested_group_data['_bricks_locations'];
                        // Save the parent group names
                        $tag['parent_group_names'] = $nested_group_data['parent_group_names'];
                        // Group by nested parent field, better visual in DD dropdown
                        $tag['group'] = 'ACF: ' . implode( ' > ', array_reverse( $nested_group_data['parent_group_names'] ) );
                    }
                }

                if ( ! empty( $parent_field['_bricks_locations'] ) ) {
                    $tag['parent']['_bricks_locations'] = $parent_field['_bricks_locations'];
                }

                // Include the parent layout name for flexible content sub-fields (@since 1.6.2)
                if ( $parent_field['type'] === 'flexible_content' ) {
                    $parent_layout = $field['parent_layout'];

                    // Get the parent layout data to retrieve the layout name and label, in case the layout key is different from the layout name (#86c9706hb; @since 2.3.2)
                    $parent_layout_data = $this->callParentPrivate( 'get_flexible_content_parent_layout_data', $this,  $parent_field, $parent_layout );

                    // Use the layout name if found, otherwise fallback to the layout key
                    $parent_layout_name = $parent_layout_data && isset( $parent_layout_data['name'] ) ? $parent_layout_data['name'] : $parent_layout;

                    // Use the layout label if found, otherwise fallback to the layout name
                    $parent_layout_label = $parent_layout_data && isset( $parent_layout_data['label'] ) ? $parent_layout_data['label'] : $parent_layout_name;

                    // Change the name to include the parent layout name, ensure it's unique
                    // e.g. acf_flexible_content_layout_name_sub_field_name
                    $name = 'acf_' . $parent_field['name'] . '_' . $parent_layout_name . '_' . $field['name'];

                    // Change the label to include the parent layout name
                    $label = $field['label'] . ' (' . $parent_field['label'] . ') (' . $parent_layout_label . ')';

                    // Group by parent field + parent layout name, better visual in DD dropdown (@since 2.0)
                    $tag['group'] = 'ACF: ' . $parent_field['label'] . ' (' . $parent_layout_label . ')';
                }
            }

            // Set the tag name and label
            $tag['name']  = '{' . $name . '}';
            $tag['label'] = $label;

            /**
             * Set 'duplicate' if this tag name has been registered before
             *
             * Meaning there is a field with the same 'name' in different field group.
             *
             * @since 1.8
             */
            if ( isset( $this->tags[ $name ] ) ) {
                $tag['duplicate'] = true;
            }

            // Register fields for the Loop context ( e.g. Repeater, Relationship, Flexible content..)
            if ( $context === self::CONTEXT_LOOP || $type === 'group' ) {

                // Register the group field tag as deprecated to be used in case groups are nested inside a repeater (@since 1.5.1)
                if ( $type === 'group' ) {
                    $this->tags[ $name ]               = $tag;
                    $this->index->indexTag( $name, $this->tags[ $name ] ); // R5.later-perf-a
                    $this->tags[ $name ]['deprecated'] = 1;
                } else {
                    $this->loop_tags[ $name ] = $tag;
                }

                // Check for sub-fields (including group field sub-fields)
                if ( ! empty( $field['sub_fields'] ) ) {
                    foreach ( $field['sub_fields'] as $sub_field ) {
                        $this->register_tag( $sub_field, $field, $tag ); // Recursive
                    }
                }

                // Check for flexible content layouts, register their sub-fields (@since 1.6.2)
                if ( $type === 'flexible_content' && ! empty( $field['layouts'] ) ) {
                    foreach ( $field['layouts'] as $layout ) {
                        if ( ! empty( $layout['sub_fields'] ) ) {
                            foreach ( $layout['sub_fields'] as $sub_field ) {
                                $this->register_tag( $sub_field, $field, $tag ); // Recursive
                            }
                        }
                    }
                }
            }

            // Only register fields from other contexts, other than CONTEXT_TEXT, if they are not sub-fields (legacy purposes)
            elseif ( $context === self::CONTEXT_TEXT || empty( $parent_field ) ) {
                $this->tags[ $name ] = $tag;
                    $this->index->indexTag( $name, $this->tags[ $name ] ); // R5.later-perf-a

                if ( $context !== self::CONTEXT_TEXT ) {
                    $this->tags[ $name ]['deprecated'] = 1;
                }

            }
        }
    }
}
