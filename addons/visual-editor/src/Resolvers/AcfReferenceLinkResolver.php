<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class AcfReferenceLinkResolver extends AbstractAcfResolver
{
    /**
     * @return string
     */
    public function name()
    {
        return 'acf_reference_link';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        $field_type = $descriptor->source['field_type'] ?? '';
        $context = $descriptor->render['context'] ?? '';

        return $this->supportsAcfSource($descriptor)
            && $context === 'link_href'
            && in_array($field_type, ['post_object', 'relationship', 'taxonomy'], true);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $field_type = (string) ($descriptor->source['field_type'] ?? '');
        $reference_id = $this->getSingleReferenceId($descriptor);

        if ($reference_id <= 0) {
            return '';
        }

        if (in_array($field_type, ['post_object', 'relationship'], true)) {
            $url = get_permalink($reference_id);

            return is_string($url) ? $url : '';
        }

        if ($field_type === 'taxonomy') {
            $term = $this->getResolvedTerm($descriptor, $reference_id);
            if (! $term || is_wp_error($term)) {
                return '';
            }

            $url = get_term_link($term);

            return is_string($url) && ! is_wp_error($url) ? $url : '';
        }

        return '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        return is_string($value) ? $value : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    public function getDisplayMode(EditableDescriptor $descriptor)
    {
        unset($descriptor);

        return 'text';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<int, array<string, string>>
     */
    public function getDisplayCandidates(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        return [
            [
                'key' => 'url',
                'value' => is_string($value) ? $value : '',
                'mode' => 'text',
            ],
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function validate(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        if (! is_scalar($value) && $value !== null) {
            return [
                'ok' => false,
                'message' => __('This related-object link field expects a single URL value.', 'dbvc'),
            ];
        }

        return [
            'ok' => true,
            'message' => '',
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function sanitize(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        return esc_url_raw((string) $value);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        $field_type = (string) ($descriptor->source['field_type'] ?? '');
        $url = is_string($value) ? trim($value) : '';

        if ($url === '') {
            return $this->writeAcfValue($descriptor, $this->buildEmptyReferenceValue($field_type));
        }

        if (in_array($field_type, ['post_object', 'relationship'], true)) {
            $post_id = url_to_postid($url);
            if ($post_id <= 0 || ! $this->postMatchesDescriptor($descriptor, $post_id)) {
                return [
                    'ok' => false,
                    'message' => __('The submitted URL could not be resolved to an allowed related post.', 'dbvc'),
                ];
            }

            $payload = $field_type === 'relationship' ? [$post_id] : $post_id;

            return $this->writeAcfValue($descriptor, $payload);
        }

        if ($field_type === 'taxonomy') {
            $term_id = $this->resolveTermIdFromUrl($descriptor, $url);
            if ($term_id <= 0) {
                return [
                    'ok' => false,
                    'message' => __('The submitted URL could not be resolved to an allowed term.', 'dbvc'),
                ];
            }

            return $this->writeAcfValue($descriptor, $term_id);
        }

        return [
            'ok' => false,
            'message' => __('This related-object link field is not supported for saving.', 'dbvc'),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int
     */
    private function getSingleReferenceId(EditableDescriptor $descriptor)
    {
        $field_type = (string) ($descriptor->source['field_type'] ?? '');
        $raw_value = $this->getRawAcfValue($descriptor);
        $ids = [];

        if (is_array($raw_value)) {
            foreach ($raw_value as $item) {
                $ids[] = $this->extractReferenceId($item);
            }
        } else {
            $ids[] = $this->extractReferenceId($raw_value);
        }

        $ids = array_values(array_filter(array_map('absint', $ids)));
        $ids = array_values(array_unique($ids));

        if ($field_type === 'relationship') {
            return count($ids) === 1 ? $ids[0] : 0;
        }

        return isset($ids[0]) && count($ids) === 1 ? $ids[0] : 0;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function extractReferenceId($value)
    {
        if (is_object($value)) {
            if (isset($value->ID)) {
                return absint($value->ID);
            }

            if (isset($value->term_id)) {
                return absint($value->term_id);
            }
        }

        if (is_array($value)) {
            if (isset($value['ID'])) {
                return absint($value['ID']);
            }

            if (isset($value['term_id'])) {
                return absint($value['term_id']);
            }
        }

        return is_numeric($value) ? absint($value) : 0;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param int                $post_id
     * @return bool
     */
    private function postMatchesDescriptor(EditableDescriptor $descriptor, $post_id)
    {
        $post = get_post($post_id);
        if (! $post) {
            return false;
        }

        $allowed_post_types = isset($descriptor->source['reference_post_types']) && is_array($descriptor->source['reference_post_types'])
            ? $descriptor->source['reference_post_types']
            : [];

        if (empty($allowed_post_types)) {
            return true;
        }

        return in_array((string) $post->post_type, $allowed_post_types, true);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param int                $term_id
     * @return \WP_Term|false|\WP_Error
     */
    private function getResolvedTerm(EditableDescriptor $descriptor, $term_id)
    {
        $taxonomies = isset($descriptor->source['reference_taxonomies']) && is_array($descriptor->source['reference_taxonomies'])
            ? $descriptor->source['reference_taxonomies']
            : [];

        if (! empty($taxonomies)) {
            $term = get_term($term_id, $taxonomies[0]);

            return $term instanceof \WP_Term || is_wp_error($term) ? $term : false;
        }

        $term = get_term($term_id);

        return $term instanceof \WP_Term || is_wp_error($term) ? $term : false;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $url
     * @return int
     */
    private function resolveTermIdFromUrl(EditableDescriptor $descriptor, $url)
    {
        $taxonomies = isset($descriptor->source['reference_taxonomies']) && is_array($descriptor->source['reference_taxonomies'])
            ? $descriptor->source['reference_taxonomies']
            : [];

        if (empty($taxonomies)) {
            return 0;
        }

        $normalized_url = untrailingslashit((string) $url);
        $terms = get_terms(
            [
                'taxonomy' => $taxonomies,
                'hide_empty' => false,
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return 0;
        }

        foreach ($terms as $term) {
            if (! $term instanceof \WP_Term) {
                continue;
            }

            $term_url = get_term_link($term);
            if (is_wp_error($term_url) || ! is_string($term_url)) {
                continue;
            }

            if (untrailingslashit($term_url) === $normalized_url) {
                return absint($term->term_id);
            }
        }

        return 0;
    }

    /**
     * @param string $field_type
     * @return mixed
     */
    private function buildEmptyReferenceValue($field_type)
    {
        if ($field_type === 'relationship') {
            return [];
        }

        return '';
    }
}
