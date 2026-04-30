<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Field_Context_Chain_Builder
{
    /**
     * @var DBVC_CC_Field_Context_Chain_Builder|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Field_Context_Chain_Builder
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed>                 $group
     * @param array<string, mixed>                 $field
     * @param array<string, array<string, mixed>> $fields_by_key
     * @return array<string, mixed>
     */
    public function build_slot_projection(array $group, array $field, array $fields_by_key)
    {
        $group_key = isset($group['key']) ? sanitize_key((string) $group['key']) : '';
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        if ($group_key === '' || $field_key === '') {
            return [];
        }

        $field_context = isset($field['field_context']) && is_array($field['field_context']) ? $field['field_context'] : [];
        $group_context = isset($group['field_context']) && is_array($group['field_context']) ? $group['field_context'] : [];
        $ancestor_keys = isset($field['ancestor_field_keys']) && is_array($field['ancestor_field_keys']) ? array_values($field['ancestor_field_keys']) : [];
        $branch_name_path = isset($field['ancestor_name_path']) && is_array($field['ancestor_name_path']) ? array_values($field['ancestor_name_path']) : [];
        $branch_label_path = isset($field['ancestor_label_path']) && is_array($field['ancestor_label_path']) ? array_values($field['ancestor_label_path']) : [];

        $context_chain = [];
        $group_purpose = $this->extract_purpose($group_context);
        $context_chain[] = [
            'level' => 'group',
            'key' => $group_key,
            'key_path' => isset($group_context['key_path']) ? sanitize_text_field((string) $group_context['key_path']) : '',
            'name_path' => isset($group_context['name_path']) ? sanitize_text_field((string) $group_context['name_path']) : '',
            'name' => isset($group['group_name']) ? sanitize_key((string) $group['group_name']) : '',
            'label' => isset($group['title']) ? sanitize_text_field((string) $group['title']) : '',
            'purpose' => $group_purpose,
            'type' => 'group',
        ];

        foreach ($ancestor_keys as $ancestor_key) {
            $ancestor_key = sanitize_key((string) $ancestor_key);
            if ($ancestor_key === '' || ! isset($fields_by_key[$ancestor_key]) || ! is_array($fields_by_key[$ancestor_key])) {
                continue;
            }

            $ancestor = $fields_by_key[$ancestor_key];
            $ancestor_context = isset($ancestor['field_context']) && is_array($ancestor['field_context']) ? $ancestor['field_context'] : [];
            $context_chain[] = [
                'level' => 'container',
                'key' => $ancestor_key,
                'key_path' => isset($ancestor_context['key_path']) ? sanitize_text_field((string) $ancestor_context['key_path']) : '',
                'name_path' => isset($ancestor_context['name_path']) ? sanitize_text_field((string) $ancestor_context['name_path']) : '',
                'name' => isset($ancestor['name']) ? sanitize_key((string) $ancestor['name']) : '',
                'label' => isset($ancestor['label']) ? sanitize_text_field((string) $ancestor['label']) : '',
                'purpose' => $this->extract_purpose($ancestor_context),
                'type' => isset($ancestor['type']) ? sanitize_key((string) $ancestor['type']) : '',
            ];
        }

        $field_purpose = $this->extract_purpose($field_context);
        $context_chain[] = [
            'level' => 'field',
            'key' => $field_key,
            'key_path' => isset($field_context['key_path']) ? sanitize_text_field((string) $field_context['key_path']) : '',
            'name_path' => isset($field_context['name_path']) ? sanitize_text_field((string) $field_context['name_path']) : '',
            'name' => isset($field['name']) ? sanitize_key((string) $field['name']) : '',
            'label' => isset($field['label']) ? sanitize_text_field((string) $field['label']) : '',
            'purpose' => $field_purpose,
            'type' => isset($field['type']) ? sanitize_key((string) $field['type']) : '',
        ];

        $object_context = $this->resolve_object_context($field_context, $group_context, $group);
        $value_contract = isset($field_context['value_contract']) && is_array($field_context['value_contract'])
            ? $field_context['value_contract']
            : [
                'field_type' => isset($field['type']) ? sanitize_key((string) $field['type']) : '',
                'writable' => ! $this->is_container_only_field($field, $field_context),
            ];
        $clone_context = isset($field_context['clone_context']) && is_array($field_context['clone_context'])
            ? $field_context['clone_context']
            : [
                'is_clone_projected' => false,
                'is_directly_writable' => true,
            ];

        $section_family = $this->infer_section_family(
            $branch_name_path,
            isset($field['name']) ? (string) $field['name'] : '',
            isset($field['label']) ? (string) $field['label'] : ''
        );
        $slot_role = $this->infer_slot_role($field, $branch_name_path);
        $is_repeatable = $this->infer_repeatable($field, $ancestor_keys, $fields_by_key);
        $chain_purpose_text = implode(' ', array_values(array_filter(array_map(static function ($item) {
            return is_array($item) && ! empty($item['purpose']) ? sanitize_text_field((string) $item['purpose']) : '';
        }, $context_chain))));

        return [
            'target_ref' => sprintf('acf:%s:%s', $group_key, $field_key),
            'group_key' => $group_key,
            'group_name' => isset($group['group_name']) ? sanitize_key((string) $group['group_name']) : '',
            'group_label' => isset($group['title']) ? sanitize_text_field((string) $group['title']) : '',
            'acf_key' => $field_key,
            'acf_name' => isset($field['name']) ? sanitize_key((string) $field['name']) : '',
            'acf_label' => isset($field['label']) ? sanitize_text_field((string) $field['label']) : '',
            'type' => isset($field['type']) ? sanitize_key((string) $field['type']) : '',
            'container_type' => isset($field['container_type']) ? sanitize_key((string) $field['container_type']) : '',
            'key_path' => isset($field_context['key_path']) ? sanitize_text_field((string) $field_context['key_path']) : '',
            'name_path' => isset($field_context['name_path']) ? sanitize_text_field((string) $field_context['name_path']) : '',
            'branch_name_path' => $branch_name_path,
            'branch_label_path' => $branch_label_path,
            'branch_depth' => count($branch_name_path),
            'context_chain' => $context_chain,
            'chain_purpose_text' => $chain_purpose_text,
            'section_family' => $section_family,
            'slot_role' => $slot_role,
            'is_repeatable' => $is_repeatable,
            'competition_group' => $this->build_competition_group($group_key, $branch_name_path, $section_family, $slot_role, $is_repeatable),
            'object_context' => $object_context,
            'value_contract' => $value_contract,
            'writable' => ! empty($value_contract['writable']) && empty($clone_context['is_clone_projected']) ? true : (! empty($value_contract['writable']) && ! empty($clone_context['is_directly_writable'])),
            'clone_context' => $clone_context,
            'provider_trace' => [
                'status' => isset($field_context['status']) ? sanitize_key((string) $field_context['status']) : '',
                'warnings' => isset($field_context['warnings']) && is_array($field_context['warnings']) ? array_values($field_context['warnings']) : [],
                'matched_by' => isset($field_context['matched_by']) ? sanitize_key((string) $field_context['matched_by']) : '',
                'source_hash' => isset($field_context['source_hash']) ? sanitize_text_field((string) $field_context['source_hash']) : '',
                'schema_version' => isset($field_context['schema_version']) ? sanitize_text_field((string) $field_context['schema_version']) : '',
                'contract_version' => isset($field_context['contract_version']) ? sanitize_text_field((string) $field_context['contract_version']) : '',
                'site_fingerprint' => isset($field_context['site_fingerprint']) ? sanitize_text_field((string) $field_context['site_fingerprint']) : '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $field_context
     * @param array<string, mixed> $group_context
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function resolve_object_context(array $field_context, array $group_context, array $group)
    {
        if (isset($field_context['object_context']) && is_array($field_context['object_context'])) {
            return $field_context['object_context'];
        }

        if (isset($group_context['object_context']) && is_array($group_context['object_context'])) {
            return $group_context['object_context'];
        }

        $location = isset($group['location']) && is_array($group['location']) ? $group['location'] : [];
        return DBVC_CC_Field_Context_Provider_Service::get_instance()->normalize_object_context($location);
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $field_context
     * @return bool
     */
    private function is_container_only_field(array $field, array $field_context)
    {
        $value_contract = isset($field_context['value_contract']) && is_array($field_context['value_contract']) ? $field_context['value_contract'] : [];
        if (array_key_exists('writable', $value_contract) && ! empty($value_contract['writable'])) {
            return false;
        }

        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        return in_array($type, ['group', 'repeater', 'flexible_content', 'accordion', 'tab', 'message'], true);
    }

    /**
     * @param array<int, string> $branch_name_path
     * @param string             $field_name
     * @param string             $field_label
     * @return string
     */
    private function infer_section_family(array $branch_name_path, $field_name, $field_label)
    {
        $haystack = strtolower(implode(' ', array_merge($branch_name_path, [$field_name, $field_label])));
        $map = [
            'hero' => ['hero', 'banner'],
            'intro' => ['intro', 'overview'],
            'features' => ['feature'],
            'pricing' => ['pricing', 'price', 'plan', 'package', 'cost'],
            'process' => ['process', 'workflow', 'timeline', 'step'],
            'services' => ['service'],
            'testimonials' => ['testimonial', 'quote'],
            'faq' => ['faq', 'question', 'answer'],
            'cta' => ['cta', 'call_to_action', 'button'],
            'contact' => ['contact', 'address', 'phone', 'email'],
            'media' => ['image', 'video', 'gallery', 'logo'],
        ];

        foreach ($map as $section_family => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($haystack, $needle) !== false) {
                    return $section_family;
                }
            }
        }

        return 'content';
    }

    /**
     * @param array<string, mixed> $field
     * @param array<int, string>   $branch_name_path
     * @return string
     */
    private function infer_slot_role(array $field, array $branch_name_path = [])
    {
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $name = strtolower((string) ($field['name'] ?? ''));
        $label = strtolower((string) ($field['label'] ?? ''));
        $branch_haystack = strtolower(implode(' ', array_map('strval', $branch_name_path)));
        $haystack = trim($name . ' ' . $label . ' ' . $branch_haystack);

        if (in_array($type, ['image', 'gallery'], true)) {
            return 'image';
        }
        if ($type === 'oembed') {
            return 'video';
        }
        if ($type === 'link') {
            return strpos($haystack, 'button') !== false || strpos($haystack, 'cta') !== false ? 'cta_url' : 'link';
        }
        if ($type === 'url') {
            return strpos($haystack, 'button') !== false || strpos($haystack, 'cta') !== false ? 'cta_url' : 'link';
        }
        if (
            strpos($haystack, 'step_name') !== false
            || strpos($haystack, 'step name') !== false
            || strpos($haystack, 'step_title') !== false
            || strpos($haystack, 'step title') !== false
        ) {
            return 'headline';
        }
        if (strpos($haystack, 'headline') !== false || strpos($haystack, 'heading') !== false || preg_match('/\bh1\b|\btitle\b/', $haystack)) {
            return 'headline';
        }
        if (strpos($haystack, 'subheading') !== false || strpos($haystack, 'subtitle') !== false) {
            return 'subheadline';
        }
        if (strpos($haystack, 'button') !== false || strpos($haystack, 'cta') !== false) {
            return 'cta_label';
        }
        if (strpos($haystack, 'quote') !== false) {
            return 'quote';
        }
        if ($type === 'true_false' || $type === 'select' || $type === 'radio') {
            return 'meta';
        }
        if (in_array($type, ['wysiwyg', 'textarea'], true)) {
            return 'rich_text';
        }

        return 'body';
    }

    /**
     * @param array<string, mixed>                 $field
     * @param array<int, string>                   $ancestor_keys
     * @param array<string, array<string, mixed>> $fields_by_key
     * @return bool
     */
    private function infer_repeatable(array $field, array $ancestor_keys, array $fields_by_key)
    {
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        if (in_array($type, ['repeater', 'flexible_content'], true)) {
            return true;
        }

        foreach ($ancestor_keys as $ancestor_key) {
            $ancestor_key = sanitize_key((string) $ancestor_key);
            if ($ancestor_key === '' || ! isset($fields_by_key[$ancestor_key]) || ! is_array($fields_by_key[$ancestor_key])) {
                continue;
            }

            $ancestor = $fields_by_key[$ancestor_key];
            $ancestor_type = isset($ancestor['type']) ? sanitize_key((string) $ancestor['type']) : '';
            if (in_array($ancestor_type, ['repeater', 'flexible_content'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string            $group_key
     * @param array<int, string> $branch_name_path
     * @param string            $section_family
     * @param string            $slot_role
     * @param bool              $is_repeatable
     * @return string
     */
    private function build_competition_group($group_key, array $branch_name_path, $section_family, $slot_role, $is_repeatable)
    {
        if ($is_repeatable) {
            return '';
        }

        $slot_role = sanitize_key((string) $slot_role);
        if (! in_array($slot_role, ['headline', 'subheadline', 'cta_label', 'cta_url', 'image', 'eyebrow'], true)) {
            return '';
        }

        $root_branch = '';
        if (! empty($branch_name_path)) {
            $root_branch = sanitize_key((string) reset($branch_name_path));
        }
        if ($root_branch === '') {
            $root_branch = sanitize_key((string) $section_family);
        }
        if ($root_branch === '') {
            return '';
        }

        return sanitize_text_field(sanitize_key((string) $group_key) . ':' . $root_branch . ':' . $slot_role);
    }

    /**
     * @param array<string, mixed> $context
     * @return string
     */
    private function extract_purpose(array $context)
    {
        foreach (['resolved_purpose', 'effective_purpose', 'purpose', 'default_purpose', 'gardenai_field_purpose'] as $key) {
            if (! empty($context[$key])) {
                return sanitize_text_field((string) $context[$key]);
            }
        }

        foreach (['vf_field_context', 'context', 'default_context'] as $nested_key) {
            if (isset($context[$nested_key]) && is_array($context[$nested_key])) {
                $purpose = $this->extract_purpose($context[$nested_key]);
                if ($purpose !== '') {
                    return $purpose;
                }
            }
        }

        return '';
    }
}
