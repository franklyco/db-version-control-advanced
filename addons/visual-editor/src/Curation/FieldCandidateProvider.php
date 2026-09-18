<?php

namespace Dbvc\VisualEditor\Curation;

/**
 * R3-BX — Manual Approved Field Selection candidate enumerator.
 *
 * Walks the site's active ACF field groups and returns one candidate
 * record per **options-page-owned** field. Post-, term-, and user-owned
 * fields are deliberately excluded — R3-BX curates Global / Site-wide
 * controls only. Repeater/flexible/clone containers are emitted as
 * candidate rows (the recommender defers them), but their subfields
 * are NOT enumerated because R5's family support does not yet cover
 * arbitrary repeater subfield surfaces.
 *
 * Test seams (constructor callables) let unit tests hand in fixture
 * field-group arrays with no ACF dependency. Production wires the
 * standard `acf_get_field_groups()` + `acf_get_fields()` pair.
 */
final class FieldCandidateProvider
{
    /**
     * @var callable(): array<int, array<string, mixed>>
     */
    private $fieldGroupsResolver;

    /**
     * @var callable(array<string, mixed>): array<int, array<string, mixed>>
     */
    private $fieldsResolver;

    /**
     * @param (callable(): array<int, array<string, mixed>>)|null $fieldGroupsResolver
     * @param (callable(array<string, mixed>): array<int, array<string, mixed>>)|null $fieldsResolver
     */
    public function __construct($fieldGroupsResolver = null, $fieldsResolver = null)
    {
        $this->fieldGroupsResolver = is_callable($fieldGroupsResolver)
            ? $fieldGroupsResolver
            : static function () {
                return function_exists('acf_get_field_groups') ? (array) acf_get_field_groups() : [];
            };

        $this->fieldsResolver = is_callable($fieldsResolver)
            ? $fieldsResolver
            : static function (array $group) {
                if (! function_exists('acf_get_fields')) {
                    return [];
                }
                $result = acf_get_fields($group);

                return is_array($result) ? $result : [];
            };
    }

    /**
     * Returns every options-page-owned candidate the recommender + admin
     * page will render. Deterministic ordering: options-page slug asc,
     * then field-name path asc, so the admin table + exporter both hand
     * back the same order across reloads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCandidates()
    {
        $records = [];
        $groups = call_user_func($this->fieldGroupsResolver);
        if (! is_array($groups)) {
            return $records;
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $options_pages = $this->extractOptionsPages($group);
            if (empty($options_pages)) {
                continue;
            }

            $group_key = isset($group['key']) ? sanitize_key((string) $group['key']) : '';
            $group_title = isset($group['title']) ? sanitize_text_field((string) $group['title']) : '';

            $fields = call_user_func($this->fieldsResolver, $group);
            if (! is_array($fields)) {
                continue;
            }

            foreach ($options_pages as $options_page) {
                $collected = $this->walkFields($fields, $options_page, $group_key, $group_title, [], []);
                foreach ($collected as $record) {
                    $records[] = $record;
                }
            }
        }

        usort($records, static function (array $a, array $b) {
            $page = strcmp((string) $a['options_page'], (string) $b['options_page']);
            if ($page !== 0) {
                return $page;
            }

            return strcmp((string) $a['field_name_path'], (string) $b['field_name_path']);
        });

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param string                           $options_page
     * @param string                           $group_key
     * @param string                           $group_title
     * @param array<int, string>               $ancestor_labels
     * @param array<int, string>               $ancestor_names
     * @return array<int, array<string, mixed>>
     */
    private function walkFields(array $fields, $options_page, $group_key, $group_title, array $ancestor_labels, array $ancestor_names)
    {
        $records = [];
        $current_tab_label = '';

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

            if ($type === 'tab') {
                $current_tab_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : '';
                continue;
            }

            if ($type === 'message' || $type === 'accordion') {
                continue;
            }

            // ACF field names may be camelCase (e.g. `colorPrimary` in Vertical's palette),
            // so use a case-preserving allowlist instead of sanitize_key (which lowercases).
            $name = isset($field['name']) ? $this->sanitizeFieldName((string) $field['name']) : '';
            $label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : '';
            $key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';

            if ($name === '') {
                continue;
            }

            $branch_labels = $ancestor_labels;
            if ($current_tab_label !== '') {
                $branch_labels[] = $current_tab_label;
                $current_tab_label = '';
            }

            $branch_names = $ancestor_names;
            $branch_names[] = $name;
            $field_name_path = implode('>', $branch_names);

            $instructions = isset($field['instructions']) && is_string($field['instructions'])
                ? sanitize_text_field($field['instructions'])
                : '';

            $is_repeater_family = in_array($type, ['repeater', 'flexible_content', 'clone'], true);

            // ACF `group` fields are structural containers — they namespace
            // their subfields but have no value or editor of their own, so a
            // curation row for a group has nothing to Open. Skip emission and
            // recurse straight into the subfields, which surface as normal
            // candidate rows carrying the group's ancestor labels.
            if ($type === 'group') {
                if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                    $nested = $this->walkFields(
                        $field['sub_fields'],
                        $options_page,
                        $group_key,
                        $group_title,
                        $branch_labels,
                        $branch_names
                    );
                    foreach ($nested as $record) {
                        $records[] = $record;
                    }
                }
                continue;
            }

            $records[] = [
                'id' => 'option:' . $options_page . ':' . $field_name_path,
                'options_page' => $options_page,
                'group_key' => $group_key,
                'group_title' => $group_title,
                'ancestor_labels' => array_values(array_filter($branch_labels, static function ($value) {
                    return $value !== '';
                })),
                'field_key' => $key,
                'field_name_path' => $field_name_path,
                'field_name' => $name,
                'field_label' => $label !== '' ? $label : $name,
                'field_type' => $type,
                'field_instructions' => $instructions,
                'is_inside_repeater' => false,
                'is_repeater_family' => $is_repeater_family,
            ];
        }

        return $records;
    }

    /**
     * Extract the set of options-page menu slugs this field group is
     * exposed on. Skips groups with no options-page location rule.
     *
     * @param array<string, mixed> $group
     * @return array<int, string>
     */
    private function extractOptionsPages(array $group)
    {
        $slugs = [];
        $locations = isset($group['location']) && is_array($group['location']) ? $group['location'] : [];

        foreach ($locations as $rules) {
            if (! is_array($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $param = isset($rule['param']) ? sanitize_key((string) $rule['param']) : '';
                $operator = isset($rule['operator']) ? (string) $rule['operator'] : '==';
                $value = isset($rule['value']) ? sanitize_key((string) $rule['value']) : '';

                if (! in_array($param, ['options_page', 'options_page_key'], true) || $value === '') {
                    continue;
                }
                if (! in_array($operator, ['==', '==='], true)) {
                    continue;
                }

                $normalized = preg_replace('/^acf-options-/', '', $value);
                if (is_string($normalized) && $normalized !== '') {
                    $slugs[$normalized] = true;
                }
            }
        }

        return array_keys($slugs);
    }

    /**
     * Case-preserving field-name sanitizer. ACF field names allow camelCase
     * (Vertical's `colorPrimary`, `colorHeading`, etc.), so we cannot use
     * `sanitize_key` which lowercases. Allow only `[A-Za-z0-9_-]`.
     *
     * @param string $name
     * @return string
     */
    private function sanitizeFieldName($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '', $name);

        return is_string($sanitized) ? $sanitized : '';
    }
}
