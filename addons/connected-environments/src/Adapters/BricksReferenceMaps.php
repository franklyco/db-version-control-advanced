<?php

namespace Dbvc\Connected\Adapters;

/**
 * Shared id⇄name maps for Bricks cross-object references (global classes and
 * components), used by any observer that tokenizes/detokenizes references with
 * {@see \Dbvc\Connected\Preparation\ReferenceTokens} — the component
 * observer (an option collection) and the Bricks-aware template observer (a
 * post), which live in different class hierarchies. The maps are read directly
 * from the persisted option rows (bypassing caches/filters, the same settled-
 * state guarantee the projections use) and memoized per observer instance.
 */
trait BricksReferenceMaps
{
    /** @var array<string, string>|null */
    private $ref_class_id_to_name = null;
    /** @var array<string, string>|null */
    private $ref_class_name_to_id = null;
    /** @var array<string, string>|null */
    private $ref_component_id_to_name = null;
    /** @var array<string, string>|null */
    private $ref_component_name_to_id = null;

    /**
     * A component carries no top-level name; Bricks names it by its root
     * element's label (and that root element's id equals the component id).
     *
     * @param array<string, mixed> $component
     * @return string
     */
    public static function component_record_name(array $component)
    {
        $elements = isset($component['elements']) && is_array($component['elements']) ? $component['elements'] : [];
        $root = $elements[0] ?? null;

        return is_array($root) && isset($root['label']) && is_scalar($root['label']) ? (string) $root['label'] : '';
    }

    /**
     * @return array<string, string> source global-class id => name
     */
    protected function bricks_class_id_to_name()
    {
        if ($this->ref_class_id_to_name === null) {
            $this->ref_class_id_to_name = [];
            foreach ($this->read_raw_bricks_option('bricks_global_classes') as $record) {
                if (is_array($record) && isset($record['id'], $record['name']) && is_scalar($record['id']) && is_scalar($record['name'])) {
                    $this->ref_class_id_to_name[(string) $record['id']] = (string) $record['name'];
                }
            }
        }

        return $this->ref_class_id_to_name;
    }

    /**
     * @return array<string, string> target global-class name => id
     */
    protected function bricks_class_name_to_id()
    {
        if ($this->ref_class_name_to_id === null) {
            $this->ref_class_name_to_id = [];
            foreach ($this->read_raw_bricks_option('bricks_global_classes') as $record) {
                if (is_array($record) && isset($record['id'], $record['name']) && is_scalar($record['id']) && is_scalar($record['name'])) {
                    // First writer wins, so a duplicate name resolves deterministically.
                    $this->ref_class_name_to_id += [(string) $record['name'] => (string) $record['id']];
                }
            }
        }

        return $this->ref_class_name_to_id;
    }

    /**
     * @return array<string, string> source component id => name
     */
    protected function bricks_component_id_to_name()
    {
        if ($this->ref_component_id_to_name === null) {
            $this->ref_component_id_to_name = [];
            foreach ($this->read_raw_bricks_option('bricks_components') as $record) {
                if (is_array($record) && isset($record['id']) && is_scalar($record['id'])) {
                    $name = self::component_record_name($record);
                    if ($name !== '') {
                        $this->ref_component_id_to_name[(string) $record['id']] = $name;
                    }
                }
            }
        }

        return $this->ref_component_id_to_name;
    }

    /**
     * @return array<string, string> target component name => id
     */
    protected function bricks_component_name_to_id()
    {
        if ($this->ref_component_name_to_id === null) {
            $this->ref_component_name_to_id = [];
            foreach ($this->read_raw_bricks_option('bricks_components') as $record) {
                $name = is_array($record) && isset($record['id']) && is_scalar($record['id']) ? self::component_record_name($record) : '';
                if ($name !== '') {
                    $this->ref_component_name_to_id += [$name => (string) $record['id']];
                }
            }
        }

        return $this->ref_component_name_to_id;
    }

    /**
     * Direct read of a persisted option as a list, bypassing caches and filters.
     *
     * @param string $option
     * @return array<int, mixed>
     */
    protected function read_raw_bricks_option($option)
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ));
        if ($raw === null) {
            return [];
        }
        $value = maybe_unserialize((string) $raw);

        return is_array($value) ? $value : [];
    }
}
