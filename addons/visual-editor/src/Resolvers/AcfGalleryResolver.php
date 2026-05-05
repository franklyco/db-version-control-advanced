<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class AcfGalleryResolver extends AbstractAcfResolver
{
    /**
     * @return string
     */
    public function name()
    {
        return 'acf_gallery';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return $this->supportsAcfSource($descriptor)
            && ($descriptor->source['field_type'] ?? '') === 'gallery';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $raw = $this->getRawAcfValue($descriptor);
        $ids = is_array($raw) ? $raw : [$raw];
        $normalized = [];

        foreach ($ids as $item) {
            $id = 0;

            if (is_numeric($item)) {
                $id = absint($item);
            } elseif (is_array($item) && isset($item['ID'])) {
                $id = absint($item['ID']);
            } elseif (is_array($item) && isset($item['id'])) {
                $id = absint($item['id']);
            } elseif (is_object($item) && isset($item->ID)) {
                $id = absint($item->ID);
            }

            if ($id <= 0) {
                continue;
            }

            $size = isset($descriptor->source['media_size']) ? sanitize_key((string) $descriptor->source['media_size']) : '';
            $normalized[] = [
                'id' => $id,
                'url' => (string) wp_get_attachment_image_url($id, 'full'),
                'renderUrl' => (string) wp_get_attachment_image_url($id, $size !== '' ? $size : 'full'),
                'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
                'caption' => (string) wp_get_attachment_caption($id),
                'title' => (string) get_the_title($id),
            ];
        }

        return $normalized;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        $count = is_array($value) ? count($value) : 0;

        return sprintf(_n('%d image', '%d images', $count, 'dbvc'), $count);
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
     * @return array<string, mixed>
     */
    public function validate(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        $ids = $this->normalizeSubmittedIds($value);

        foreach ($ids as $id) {
            if ($id <= 0) {
                return [
                    'ok' => false,
                    'message' => __('This gallery field expects Media Library image attachments.', 'dbvc'),
                ];
            }

            if (! wp_attachment_is_image($id)) {
                return [
                    'ok' => false,
                    'message' => __('One or more selected gallery items are not valid image attachments.', 'dbvc'),
                ];
            }
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

        return $this->normalizeSubmittedIds($value);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        $ids = $this->normalizeSubmittedIds($value);

        foreach ($ids as $id) {
            if (! wp_attachment_is_image($id)) {
                return [
                    'ok' => false,
                    'message' => __('One or more selected gallery items are not valid image attachments.', 'dbvc'),
                ];
            }
        }

        $result = $this->writeAcfValue($descriptor, $ids);
        if (empty($result['ok'])) {
            return $result;
        }

        return [
            'ok' => true,
            'value' => $this->getValue($descriptor),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeSubmittedIds($value)
    {
        $items = [];

        if ($value === null || $value === '') {
            return [];
        }

        if (is_numeric($value)) {
            $items = [$value];
        } elseif (is_array($value) && isset($value['attachmentIds']) && is_array($value['attachmentIds'])) {
            $items = $value['attachmentIds'];
        } elseif (is_array($value) && isset($value['ids']) && is_array($value['ids'])) {
            $items = $value['ids'];
        } elseif (is_array($value) && isset($value['items']) && is_array($value['items'])) {
            $items = $value['items'];
        } elseif (is_array($value)) {
            $items = $value;
        } else {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            $id = 0;

            if (is_numeric($item)) {
                $id = absint($item);
            } elseif (is_array($item) && isset($item['ID'])) {
                $id = absint($item['ID']);
            } elseif (is_array($item) && isset($item['id'])) {
                $id = absint($item['id']);
            } elseif (is_object($item) && isset($item->ID)) {
                $id = absint($item->ID);
            }

            if ($id <= 0 || in_array($id, $normalized, true)) {
                continue;
            }

            $normalized[] = $id;
        }

        return array_values($normalized);
    }
}
