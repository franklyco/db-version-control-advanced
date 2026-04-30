<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class PostFeaturedImageResolver implements ResolverInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'post_featured_image';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return ($descriptor->source['type'] ?? '') === 'post_field'
            && ($descriptor->source['field_name'] ?? '') === 'featured_image';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $post_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;

        return $post_id > 0
            ? $this->normalizeImageValue(get_post_thumbnail_id($post_id), $descriptor)
            : $this->normalizeImageValue(0, $descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        $value = is_array($value) ? $value : [];

        if (! empty($value['renderAttributes']['src'])) {
            return (string) $value['renderAttributes']['src'];
        }

        if (! empty($value['renderUrl'])) {
            return (string) $value['renderUrl'];
        }

        if (! empty($value['url'])) {
            return (string) $value['url'];
        }

        return '';
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
        $value = $this->normalizeImageValue($value, $descriptor);
        $url = '';

        if (! empty($value['renderAttributes']['src'])) {
            $url = (string) $value['renderAttributes']['src'];
        } elseif (! empty($value['renderUrl'])) {
            $url = (string) $value['renderUrl'];
        } else {
            $url = (string) ($value['url'] ?? '');
        }

        return [
            [
                'key' => 'src',
                'value' => $url,
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

        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return [
                'ok' => true,
                'message' => '',
            ];
        }

        if (is_scalar($value)) {
            return [
                'ok' => true,
                'message' => '',
            ];
        }

        if (! is_array($value)) {
            return [
                'ok' => false,
                'message' => __('This featured image field expects a Media Library attachment selection or a local media URL fallback.', 'dbvc'),
            ];
        }

        $id = $this->resolveAttachmentIdFromSubmittedValue($value);
        $url = isset($value['url']) ? trim((string) $value['url']) : '';

        if ($id <= 0 && $url === '') {
            return [
                'ok' => true,
                'message' => '',
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

        if (is_scalar($value) || $value === null) {
            if (is_numeric($value)) {
                $value = [
                    'attachmentId' => absint($value),
                ];
            } else {
                $value = [
                    'url' => is_scalar($value) ? (string) $value : '',
                ];
            }
        }

        $value = is_array($value) ? $value : [];
        $attachment_id = $this->resolveAttachmentIdFromSubmittedValue($value);

        return [
            'attachmentId' => $attachment_id,
            'id' => $attachment_id,
            'url' => isset($value['url']) ? esc_url_raw((string) $value['url']) : '',
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        $post_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;
        if ($post_id <= 0) {
            return [
                'ok' => false,
                'message' => __('Post context is missing.', 'dbvc'),
            ];
        }

        $attachment_id = 0;
        $url = '';

        if (is_array($value)) {
            $attachment_id = $this->resolveAttachmentIdFromSubmittedValue($value);
            $url = isset($value['url']) ? trim((string) $value['url']) : '';
        } elseif (is_numeric($value)) {
            $attachment_id = absint($value);
        } elseif (is_scalar($value) || $value === null) {
            $url = trim((string) $value);
        }

        if ($attachment_id <= 0 && $url !== '') {
            $attachment_id = $this->resolveAttachmentIdFromUrl($url);
        }

        if ($attachment_id > 0 && ! wp_attachment_is_image($attachment_id)) {
            return [
                'ok' => false,
                'message' => __('The submitted media item is not a valid image attachment.', 'dbvc'),
            ];
        }

        if ($url !== '' && $attachment_id <= 0) {
            return [
                'ok' => false,
                'message' => __('The submitted image URL could not be resolved to a local Media Library attachment.', 'dbvc'),
            ];
        }

        $current_attachment_id = get_post_thumbnail_id($post_id);

        if ($attachment_id > 0) {
            $result = set_post_thumbnail($post_id, $attachment_id);
            if ($result === false && $current_attachment_id !== $attachment_id) {
                return [
                    'ok' => false,
                    'message' => __('The featured image could not be updated.', 'dbvc'),
                ];
            }
        } else {
            $result = delete_post_thumbnail($post_id);
            if ($result === false && $current_attachment_id > 0) {
                return [
                    'ok' => false,
                    'message' => __('The featured image could not be cleared.', 'dbvc'),
                ];
            }
        }

        return [
            'ok' => true,
            'value' => $this->getValue($descriptor),
        ];
    }

    /**
     * @param mixed              $value
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function normalizeImageValue($value, EditableDescriptor $descriptor)
    {
        $attachment_id = 0;
        $url = '';

        if (is_numeric($value)) {
            $attachment_id = absint($value);
        } elseif (is_array($value)) {
            if (isset($value['ID'])) {
                $attachment_id = absint($value['ID']);
            } elseif (isset($value['attachmentId'])) {
                $attachment_id = absint($value['attachmentId']);
            } elseif (isset($value['id'])) {
                $attachment_id = absint($value['id']);
            }

            if (isset($value['url'])) {
                $url = esc_url_raw((string) $value['url']);
            }
        } elseif (is_object($value) && isset($value->ID)) {
            $attachment_id = absint($value->ID);
        } elseif (is_string($value)) {
            $url = esc_url_raw($value);
            if ($url !== '') {
                $attachment_id = $this->resolveAttachmentIdFromUrl($url);
            }
        }

        $size = isset($descriptor->source['media_size']) ? sanitize_key((string) $descriptor->source['media_size']) : '';
        $render_url = '';
        $full_url = '';
        $alt = '';
        $caption = '';
        $title = '';

        if ($attachment_id > 0) {
            $full_url = wp_get_attachment_image_url($attachment_id, 'full');
            $render_url = wp_get_attachment_image_url($attachment_id, $size !== '' ? $size : 'full');
            $alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $caption = (string) wp_get_attachment_caption($attachment_id);
            $title = (string) get_the_title($attachment_id);

            if ($url === '') {
                $url = is_string($full_url) ? $full_url : '';
            }
        }

        if (! is_string($render_url) || $render_url === '') {
            $render_url = $url;
        }

        $render_attributes = $this->buildRenderAttributes($attachment_id, $size, $render_url);

        if ($attachment_id <= 0 && $render_attributes['src'] === '' && $url !== '') {
            $render_attributes['src'] = $url;
        }

        return [
            'attachmentId' => $attachment_id,
            'id' => $attachment_id,
            'url' => $url,
            'renderUrl' => $render_url,
            'fullUrl' => is_string($full_url) && $full_url !== '' ? $full_url : $url,
            'renderAttributes' => $render_attributes,
            'alt' => $alt,
            'caption' => $caption,
            'title' => $title,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return int
     */
    private function resolveAttachmentIdFromSubmittedValue(array $value)
    {
        if (isset($value['attachmentId'])) {
            return absint($value['attachmentId']);
        }

        if (isset($value['id'])) {
            return absint($value['id']);
        }

        return 0;
    }

    /**
     * @param int    $attachment_id
     * @param string $size
     * @param string $fallback_src
     * @return array<string, string>
     */
    private function buildRenderAttributes($attachment_id, $size, $fallback_src = '')
    {
        $requested_size = $size !== '' ? $size : 'full';

        if ($attachment_id <= 0) {
            return [
                'src' => $fallback_src,
                'srcset' => '',
                'sizes' => '',
            ];
        }

        $src = wp_get_attachment_image_url($attachment_id, $requested_size);
        $srcset = wp_get_attachment_image_srcset($attachment_id, $requested_size);
        $sizes = wp_get_attachment_image_sizes($attachment_id, $requested_size);

        return [
            'src' => is_string($src) && $src !== '' ? $src : $fallback_src,
            'srcset' => is_string($srcset) ? $srcset : '',
            'sizes' => is_string($sizes) ? $sizes : '',
        ];
    }

    /**
     * @param string $url
     * @return int
     */
    private function resolveAttachmentIdFromUrl($url)
    {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return 0;
        }

        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id > 0 && wp_attachment_is_image($attachment_id)) {
            return $attachment_id;
        }

        $resized_url = preg_replace('/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $url);
        if (is_string($resized_url) && $resized_url !== $url) {
            $attachment_id = attachment_url_to_postid($resized_url);
            if ($attachment_id > 0 && wp_attachment_is_image($attachment_id)) {
                return $attachment_id;
            }
        }

        return 0;
    }
}
