<?php

namespace Dbvc\VisualEditor\MediaManager;

use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Save\MutationService;
use WP_Error;

/**
 * R2-C field-level assignment and save.
 *
 * Reuses the R2-A revalidation as the expected-empty precondition immediately
 * before writing, mutates through the existing MutationService (validation,
 * sanitization, resolver save, journal/audit, and cache invalidation), then
 * rereads the finding through the read model to reconcile the row without a
 * full table reload. The write target is always the freshly server-resolved
 * descriptor; no client-supplied target is trusted.
 */
final class MediaAssignmentService
{
    public const VIEW_MODEL_VERSION = 1;

    /**
     * @var MediaFindingDescriptorBridge
     */
    private $bridge;

    /**
     * @var MutationService
     */
    private $mutations;

    /**
     * @var MediaScanReadModel
     */
    private $read_model;

    public function __construct(
        MediaFindingDescriptorBridge $bridge,
        MutationService $mutations,
        MediaScanReadModel $read_model
    ) {
        $this->bridge = $bridge;
        $this->mutations = $mutations;
        $this->read_model = $read_model;
    }

    /**
     * @param string               $scan_ref
     * @param string               $group_ref
     * @param string               $finding_ref
     * @param array<string, mixed> $request  Expected keys: generation, expectedRevision.
     * @param mixed                $value    Expected keys: attachmentId (image) or attachmentIds (gallery).
     * @return array<string, mixed>|WP_Error
     */
    public function assign($scan_ref, $group_ref, $finding_ref, array $request, $value)
    {
        // Expected-empty precondition: revalidate immediately before the write.
        $resolution = $this->bridge->resolveFinding($scan_ref, $group_ref, $finding_ref, $request);
        if (is_wp_error($resolution)) {
            return $resolution;
        }

        if ($resolution['status'] !== 'writable' || ! ($resolution['descriptor'] instanceof EditableDescriptor)) {
            // The field changed, was populated, or lost eligibility after the scan. Fail closed.
            return new WP_Error(
                'media_assignment_stale',
                sanitize_text_field((string) ($resolution['message'] ?? __('This field can no longer be assigned. Refresh the scan.', 'dbvc'))),
                [
                    'status' => 409,
                    'findingStatus' => sanitize_key((string) $resolution['status']),
                ]
            );
        }

        $family = sanitize_key((string) $resolution['family']);
        $normalized = $this->normalizeValue($family, $value);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        // Write through the shared mutation pipeline (validation, sanitize, resolver
        // save, journal/audit, cache invalidation). MIME/attachment validity is
        // enforced by the resolver.
        $result = $this->mutations->mutate($resolution['descriptor'], $normalized);
        if (empty($result['ok'])) {
            return new WP_Error(
                'media_assignment_save_failed',
                sanitize_text_field((string) ($result['message'] ?? __('The media assignment could not be saved.', 'dbvc'))),
                ['status' => 400]
            );
        }

        // Targeted reread and reconciliation of the group without reloading the table.
        $reconcile = $this->read_model->expandGroup($scan_ref, $group_ref, $request);
        $row = ! is_wp_error($reconcile) && isset($reconcile['row']) && is_array($reconcile['row'])
            ? $reconcile['row']
            : null;
        $scan = ! is_wp_error($reconcile) && isset($reconcile['scan']) && is_array($reconcile['scan'])
            ? $reconcile['scan']
            : $this->read_model->projectSnapshot([]);

        return [
            'viewModelVersion' => self::VIEW_MODEL_VERSION,
            'scan' => $scan,
            'assignment' => [
                'findingRef' => sanitize_key((string) $finding_ref),
                'groupRef' => sanitize_key((string) $group_ref),
                'family' => $family,
                'status' => 'saved',
                'attachmentCount' => $this->attachmentCount($normalized),
                'changeSetId' => isset($result['changeSetId']) ? absint($result['changeSetId']) : 0,
                'message' => __('Media assigned. This field is no longer empty.', 'dbvc'),
            ],
            'row' => $row,
            'reconciled' => $row !== null,
        ];
    }

    /**
     * @param string $family
     * @param mixed  $value
     * @return array<string, mixed>|WP_Error
     */
    private function normalizeValue($family, $value)
    {
        $value = is_array($value) ? $value : [];

        if ($family === 'acf_gallery') {
            $raw = isset($value['attachmentIds']) && is_array($value['attachmentIds']) ? $value['attachmentIds'] : [];
            $ids = [];
            foreach ($raw as $item) {
                $id = absint($item);
                if ($id > 0 && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if (empty($ids)) {
                return new WP_Error(
                    'media_assignment_value_invalid',
                    __('Select at least one Media Library image for this gallery.', 'dbvc'),
                    ['status' => 400]
                );
            }

            return ['attachmentIds' => $ids];
        }

        $id = isset($value['attachmentId']) ? absint($value['attachmentId']) : 0;
        if ($id <= 0) {
            return new WP_Error(
                'media_assignment_value_invalid',
                __('Select a Media Library image to assign.', 'dbvc'),
                ['status' => 400]
            );
        }

        return ['attachmentId' => $id];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return int
     */
    private function attachmentCount(array $normalized)
    {
        if (isset($normalized['attachmentIds']) && is_array($normalized['attachmentIds'])) {
            return count($normalized['attachmentIds']);
        }

        return isset($normalized['attachmentId']) && absint($normalized['attachmentId']) > 0 ? 1 : 0;
    }
}
