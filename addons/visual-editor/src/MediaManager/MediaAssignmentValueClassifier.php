<?php

namespace Dbvc\VisualEditor\MediaManager;

final class MediaAssignmentValueClassifier
{
    public const EMPTY_VALUE = 'empty';
    public const ASSIGNED_VALUE = 'assigned';
    public const INVALID_NONEMPTY_VALUE = 'invalid_nonempty';

    /**
     * @param string $field_type
     * @param mixed  $value
     * @return string
     */
    public function classify($field_type, $value)
    {
        $field_type = sanitize_key((string) $field_type);

        if ($field_type === 'gallery') {
            return $this->classifyGallery($value);
        }

        if ($field_type === 'featured_image' || $field_type === 'image') {
            return $this->classifyImage($value);
        }

        return self::INVALID_NONEMPTY_VALUE;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function classifyImage($value)
    {
        if ($this->isCanonicalEmpty($value)) {
            return self::EMPTY_VALUE;
        }

        return $this->extractAttachmentId($value) > 0
            ? self::ASSIGNED_VALUE
            : self::INVALID_NONEMPTY_VALUE;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function classifyGallery($value)
    {
        if ($this->isCanonicalEmpty($value)) {
            return self::EMPTY_VALUE;
        }

        $items = is_array($value) ? $value : [$value];
        $has_valid_id = false;
        $has_invalid_item = false;

        foreach ($items as $item) {
            if ($this->extractAttachmentId($item) > 0) {
                $has_valid_id = true;
            } else {
                $has_invalid_item = true;
            }
        }

        return $has_valid_id && ! $has_invalid_item
            ? self::ASSIGNED_VALUE
            : self::INVALID_NONEMPTY_VALUE;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isCanonicalEmpty($value)
    {
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === '0') {
            return true;
        }

        return is_array($value) && empty($value);
    }

    /**
     * Match the current Visual Editor image/gallery resolver input shapes without
     * treating malformed nonempty storage as an empty assignment.
     *
     * @param mixed $value
     * @return int
     */
    private function extractAttachmentId($value)
    {
        if (is_numeric($value)) {
            return absint($value);
        }

        if (is_array($value)) {
            foreach (['attachmentId', 'ID', 'id'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return absint($value[$key]);
                }
            }
        }

        if (is_object($value) && isset($value->ID) && is_numeric($value->ID)) {
            return absint($value->ID);
        }

        return 0;
    }
}
