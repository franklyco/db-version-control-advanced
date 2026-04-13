<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SampleDocBuilder
{
    /**
     * Build a sibling markdown doc for a sample template payload.
     *
     * @param string              $entity_kind post|term
     * @param string              $object_key
     * @param array<string,mixed> $variant_payload
     * @return string
     */
    public static function build_sample_doc(string $entity_kind, string $object_key, array $variant_payload): string
    {
        $context = isset($variant_payload['context']) && is_array($variant_payload['context']) ? $variant_payload['context'] : [];
        $template = isset($variant_payload['template']) && is_array($variant_payload['template']) ? $variant_payload['template'] : [];
        $settings = Settings::get_all_settings();
        $guidance = isset($settings['guidance']) && is_array($settings['guidance']) ? $settings['guidance'] : [];
        $global_rules = isset($settings['rules']['global']) && is_array($settings['rules']['global']) ? $settings['rules']['global'] : [];

        $label = isset($context['label']) ? (string) $context['label'] : $object_key;
        $singular_label = isset($context['singular_label']) ? (string) $context['singular_label'] : $label;
        $variant = isset($context['variant']) ? (string) $context['variant'] : 'single';
        $value_style = isset($context['value_style']) ? (string) $context['value_style'] : 'blank';
        $shape_mode = isset($context['shape_mode']) ? (string) $context['shape_mode'] : 'conservative';
        $meta_context = isset($context['meta']) && is_array($context['meta']) ? $context['meta'] : [];
        $core_fields = isset($context['core_fields']) && is_array($context['core_fields']) ? $context['core_fields'] : [];
        $tax_input = isset($context['tax_input']) && is_array($context['tax_input']) ? $context['tax_input'] : [];

        $lines = [];
        $lines[] = '# ' . sprintf('%s sample guidance', $label);
        $lines[] = '';
        $lines[] = sprintf(
            'This file explains how to populate the `%s` sample JSON for the `%s` object on this site.',
            $entity_kind === 'post' ? 'post/CPT' : 'taxonomy term',
            $singular_label
        );
        $lines[] = '';
        $lines[] = '## Overview';
        $lines[] = '';
        $lines[] = '- Object key: `' . $object_key . '`';
        $lines[] = '- Variant: `' . $variant . '`';
        $lines[] = '- Value style: `' . $value_style . '`';
        $lines[] = '- Shape mode: `' . $shape_mode . '`';
        $lines[] = '- Create-template convention: `' . ($entity_kind === 'post' ? 'ID: 0' : 'term_id: 0') . '` and omit top-level `vf_object_uid` for net-new entities.';
        $lines[] = '';
        $lines[] = '## Core field rules';
        $lines[] = '';

        foreach ($core_fields as $field_name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $required = ! empty($definition['required']) ? 'required' : 'optional';
            $type = isset($definition['type']) ? (string) $definition['type'] : '';
            $description = isset($definition['description']) ? (string) $definition['description'] : '';
            $line = '- `' . $field_name . '`';
            if ($type !== '') {
                $line .= ' (' . $type . ', ' . $required . ')';
            } else {
                $line .= ' (' . $required . ')';
            }
            if ($description !== '') {
                $line .= ': ' . $description;
            }
            $lines[] = $line;
        }

        if ($entity_kind === 'post' && ! empty($tax_input)) {
            $lines[] = '';
            $lines[] = '## Taxonomy rules';
            $lines[] = '';
            foreach ($tax_input as $taxonomy => $taxonomy_definition) {
                $taxonomy_definition = is_array($taxonomy_definition) ? $taxonomy_definition : [];
                $label_text = isset($taxonomy_definition['label']) ? (string) $taxonomy_definition['label'] : $taxonomy;
                $lines[] = '- `' . $taxonomy . '` (`' . $label_text . '`): use slug strings or structured term refs. Prefer slugs over numeric IDs.';
            }
        }

        $registered_meta = [];
        $observed_meta = [];
        $acf_meta = [];
        foreach ($meta_context as $path => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $source = isset($definition['source']) ? (string) $definition['source'] : '';
            if ($source === 'acf') {
                $acf_meta[$path] = $definition;
            } elseif ($source === 'observed') {
                $observed_meta[$path] = $definition;
            } else {
                $registered_meta[$path] = $definition;
            }
        }

        if (! empty($registered_meta) || ! empty($observed_meta)) {
            $lines[] = '';
            $lines[] = '## Meta field context';
            $lines[] = '';

            foreach ($registered_meta as $path => $definition) {
                $lines[] = self::build_meta_context_line($path, $definition);
            }

            foreach ($observed_meta as $path => $definition) {
                $frequency = isset($definition['frequency']) ? (float) $definition['frequency'] : 0;
                $line = self::build_meta_context_line($path, $definition);
                if ($frequency > 0) {
                    $line .= ' Observed frequency: ' . rtrim(rtrim(number_format($frequency * 100, 2), '0'), '.') . '%.';
                }
                $lines[] = $line;
            }
        }

        if (! empty($acf_meta)) {
            $lines[] = '';
            $lines[] = '## ACF field context';
            $lines[] = '';

            foreach ($acf_meta as $path => $definition) {
                $lines[] = self::build_acf_context_line($path, $definition);
            }
        }

        $lines[] = '';
        $lines[] = '## Reference guidance';
        $lines[] = '';
        $lines[] = '- Prefer slug-based references when linking to related posts, terms, or parents. The future AI intake layer will resolve those refs against the current site.';
        $lines[] = '- Supported first-pass ACF relationship families in v1 are `post_object`, `relationship`, and `taxonomy` when authored with slug-based refs.';
        $lines[] = '- Do not invent `vf_object_uid`, `dbvc_post_history`, `dbvc_term_history`, `_dbvc_import_hash`, or ACF underscore reference keys in net-new authoring payloads.';
        $lines[] = '- Media-like fields such as image, file, and gallery are intentionally represented with neutral placeholders in v1. Keep them empty or neutral; non-empty values are blocked until media packaging support exists.';

        if (! empty($global_rules)) {
            $allowed_statuses = isset($global_rules['allowed_statuses']) && is_array($global_rules['allowed_statuses']) ? $global_rules['allowed_statuses'] : [];
            if (! empty($allowed_statuses)) {
                $lines[] = '- Allowed statuses from current defaults: `' . implode('`, `', array_map('strval', $allowed_statuses)) . '`.';
            }
        }

        $global_ai_guidance = isset($guidance['global_ai_guidance']) ? trim((string) $guidance['global_ai_guidance']) : '';
        $global_notes_markdown = isset($guidance['global_notes_markdown']) ? trim((string) $guidance['global_notes_markdown']) : '';
        if ($global_ai_guidance !== '' || $global_notes_markdown !== '') {
            $lines[] = '';
            $lines[] = '## User guidance';
            $lines[] = '';
            if ($global_ai_guidance !== '') {
                $lines[] = $global_ai_guidance;
                $lines[] = '';
            }
            if ($global_notes_markdown !== '') {
                $lines[] = $global_notes_markdown;
                $lines[] = '';
            }
        }

        $lines[] = '## Template snapshot';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = (string) wp_json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param string              $path
     * @param array<string,mixed> $definition
     * @return string
     */
    private static function build_meta_context_line(string $path, array $definition): string
    {
        $type = isset($definition['type']) ? (string) $definition['type'] : '';
        $description = isset($definition['description']) ? trim((string) $definition['description']) : '';
        $line = '- `' . $path . '`';
        if ($type !== '') {
            $line .= ' (' . $type . ')';
        }
        if ($description !== '') {
            $line .= ': ' . $description;
        }

        return $line;
    }

    /**
     * @param string              $path
     * @param array<string,mixed> $definition
     * @return string
     */
    private static function build_acf_context_line(string $path, array $definition): string
    {
        $label = isset($definition['label']) ? (string) $definition['label'] : $path;
        $type = isset($definition['type']) ? (string) $definition['type'] : '';
        $required = ! empty($definition['required']) ? 'required' : 'optional';
        $instructions = isset($definition['instructions']) ? trim((string) $definition['instructions']) : '';
        $choices = isset($definition['choices']) && is_array($definition['choices']) ? $definition['choices'] : [];
        $post_types = isset($definition['post_type']) && is_array($definition['post_type']) ? $definition['post_type'] : [];
        $taxonomy_filters = isset($definition['taxonomy_filters']) && is_array($definition['taxonomy_filters']) ? $definition['taxonomy_filters'] : [];

        $line = '- `' . $path . '` (`' . $label . '`)';
        if ($type !== '') {
            $line .= ' [' . $type . ', ' . $required . ']';
        }
        if ($instructions !== '') {
            $line .= ': ' . $instructions;
        }
        if (! empty($choices)) {
            $line .= ' Choices: `' . implode('`, `', array_map('strval', array_keys($choices))) . '`.';
        }
        if (! empty($post_types)) {
            $line .= ' Related post types: `' . implode('`, `', array_map('strval', $post_types)) . '`.';
        }
        if (! empty($taxonomy_filters)) {
            $line .= ' Related taxonomies: `' . implode('`, `', array_map('strval', $taxonomy_filters)) . '`.';
        }
        if (in_array($type, ['post_object', 'relationship'], true)) {
            $line .= ' Use structured slug refs such as `{ "post_type": "...", "slug": "..." }`; DBVC will backfill local IDs after import when needed.';
        } elseif ($type === 'taxonomy') {
            $line .= ' Use structured term refs such as `{ "taxonomy": "...", "slug": "..." }` or plain slugs where the taxonomy is unambiguous.';
            if (! empty($definition['save_terms'])) {
                $line .= ' This field also syncs assigned terms back onto the entity during AI intake import.';
            }
        } elseif (in_array($type, ['group', 'repeater', 'flexible_content', 'clone'], true)) {
            $line .= ' The sample JSON shows the logical authoring shape for this field family, but nested storage translation is still partial in v1. Keep complex values conservative and follow the sample structure exactly.';
        } elseif (in_array($type, ['image', 'file', 'gallery'], true)) {
            $line .= ' Leave this field empty or neutral in v1. Non-empty values are currently blocked until media packaging is supported.';
        }

        return $line;
    }
}
