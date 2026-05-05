<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class AcfLinkResolver extends AbstractAcfResolver
{
    /**
     * @return string
     */
    public function name()
    {
        return 'acf_link';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return $this->supportsAcfSource($descriptor)
            && ($descriptor->source['field_type'] ?? '') === 'link';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $value = $this->getRawAcfValue($descriptor);

        return $this->normalizeLinkValue($value);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function validate(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        return [
            'ok' => is_array($value) || is_null($value),
            'message' => __('ACF link fields require a structured link value.', 'dbvc'),
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

        $value = is_array($value) ? $value : [];
        $target = isset($value['target']) ? sanitize_text_field((string) $value['target']) : '';

        return [
            'url' => isset($value['url']) ? esc_url_raw((string) $value['url']) : '',
            'title' => isset($value['title']) ? sanitize_text_field((string) $value['title']) : '',
            'target' => in_array($target, ['', '_blank'], true) ? $target : '',
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        return $this->writeAcfValue($descriptor, $value);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        $value = $this->normalizeLinkValue($value);
        $display_key = $this->resolveDisplayKey($descriptor, $value);

        if ($display_key === 'title') {
            return isset($value['title']) ? (string) $value['title'] : '';
        }

        return isset($value['url']) ? (string) $value['url'] : '';
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

        $value = $this->normalizeLinkValue($value);
        $candidates = [];

        if (! empty($value['url'])) {
            $candidates[] = [
                'key' => 'url',
                'value' => (string) $value['url'],
                'mode' => 'text',
            ];
        }

        if (! empty($value['title']) && $value['title'] !== $value['url']) {
            $candidates[] = [
                'key' => 'title',
                'value' => (string) $value['title'],
                'mode' => 'text',
            ];
        }

        if (empty($candidates)) {
            $candidates[] = [
                'key' => 'url',
                'value' => '',
                'mode' => 'text',
            ];
        }

        return $candidates;
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private function normalizeLinkValue($value)
    {
        if (! is_array($value)) {
            return [
                'url' => is_scalar($value) ? (string) $value : '',
                'title' => '',
                'target' => '',
            ];
        }

        return [
            'url' => isset($value['url']) ? (string) $value['url'] : '',
            'title' => isset($value['title']) ? (string) $value['title'] : '',
            'target' => isset($value['target']) ? (string) $value['target'] : '',
        ];
    }

    /**
     * @param EditableDescriptor         $descriptor
     * @param array<string, string> $value
     * @return string
     */
    private function resolveDisplayKey(EditableDescriptor $descriptor, array $value)
    {
        $display_key = isset($descriptor->render['display_key']) ? (string) $descriptor->render['display_key'] : '';
        if (in_array($display_key, ['url', 'title'], true)) {
            return $display_key;
        }

        $args = isset($descriptor->source['expression_args']) && is_array($descriptor->source['expression_args'])
            ? $descriptor->source['expression_args']
            : [];

        foreach ($args as $arg) {
            $arg = sanitize_key((string) $arg);

            if ($arg === 'title' && ! empty($value['title'])) {
                return 'title';
            }

            if (in_array($arg, ['url', 'link'], true) && ! empty($value['url'])) {
                return 'url';
            }
        }

        return ! empty($value['title']) && empty($value['url']) ? 'title' : 'url';
    }
}
