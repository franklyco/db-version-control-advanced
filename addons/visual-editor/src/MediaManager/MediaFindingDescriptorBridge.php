<?php

namespace Dbvc\VisualEditor\MediaManager;

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use WP_Error;
use WP_Post;
use WP_Term;

/**
 * R2-A descriptor bridge.
 *
 * Exchanges one opaque, non-authoritative Media Manager finding for one fresh
 * standard EditableDescriptor. The target owner and field are resolved only from
 * the current user/site-bound snapshot and the opaque references; no client-supplied
 * owner id, field key/name, ACF object id, selector, or path becomes authority.
 *
 * The bridge revalidates snapshot identity, owner status/capability, field
 * applicability, field family, and the current empty value before minting a
 * descriptor. It stops before Media Library selection and any content mutation:
 * it hydrates no value, opens no media frame, writes nothing, and journals nothing.
 */
final class MediaFindingDescriptorBridge
{
    public const VIEW_MODEL_VERSION = 1;

    /**
     * @var MediaScanCoordinator
     */
    private $coordinator;

    /**
     * @var MediaScanService
     */
    private $scanner;

    /**
     * @var EligibilityPolicy
     */
    private $eligibility;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var EditableRegistry
     */
    private $registry;

    public function __construct(
        MediaScanCoordinator $coordinator,
        MediaScanService $scanner,
        EligibilityPolicy $eligibility,
        CapabilityManager $capabilities,
        EditableRegistry $registry
    ) {
        $this->coordinator = $coordinator;
        $this->scanner = $scanner;
        $this->eligibility = $eligibility;
        $this->capabilities = $capabilities;
        $this->registry = $registry;
    }

    /**
     * @param string               $scan_ref
     * @param string               $group_ref
     * @param string               $finding_ref
     * @param array<string, mixed> $request  Expected keys: generation, expectedRevision.
     * @return array<string, mixed>|WP_Error
     */
    public function bridgeFinding($scan_ref, $group_ref, $finding_ref, array $request = [])
    {
        $resolution = $this->resolveFinding($scan_ref, $group_ref, $finding_ref, $request);
        if (is_wp_error($resolution)) {
            return $resolution;
        }

        $snapshot = $resolution['snapshot'];

        if ($resolution['status'] !== 'writable' || ! ($resolution['descriptor'] instanceof EditableDescriptor)) {
            return $this->statusResult(
                $snapshot,
                $resolution['group_ref'],
                $resolution['finding_ref'],
                $resolution['finding'],
                $resolution['status'],
                $resolution['descriptor_status'],
                $resolution['message']
            );
        }

        $descriptor = $resolution['descriptor'];
        $session_id = $this->registry->persistDetachedDescriptor($descriptor);
        if ($session_id === '') {
            return $this->error('media_finding_descriptor_failed', __('A media finding descriptor could not be prepared.', 'dbvc'), 500);
        }

        return [
            'viewModelVersion' => self::VIEW_MODEL_VERSION,
            'scan' => $this->projectScanIdentity($snapshot),
            'finding' => [
                'findingRef' => $resolution['finding_ref'],
                'groupRef' => $resolution['group_ref'],
                'family' => $resolution['family'],
                'label' => $resolution['label'],
                'status' => 'writable',
                'descriptorStatus' => 'hydrated',
                'message' => __('This supported media field is still empty and ready for a fresh descriptor.', 'dbvc'),
            ],
            'descriptor' => [
                'token' => $descriptor->token,
                'sessionId' => $session_id,
                'input' => isset($descriptor->ui['input']) ? sanitize_key((string) $descriptor->ui['input']) : 'image',
                'family' => $resolution['family'],
                'expectedState' => 'empty',
            ],
            // R2-A stops before Media Library selection and mutation; R2-B/R2-C enable these.
            'availableActions' => [
                'assignMedia' => false,
                'openMediaLibrary' => false,
                'save' => false,
            ],
        ];
    }

    /**
     * Server-authoritative revalidation shared by the R2-A bridge and the R2-C save
     * path. Resolves the owner/field only from the snapshot, rechecks eligibility,
     * rescans the single owner, compares the empty fingerprint, and (for a still-empty
     * finding) mints a fresh descriptor and confirms object capability.
     *
     * @param string               $scan_ref
     * @param string               $group_ref
     * @param string               $finding_ref
     * @param array<string, mixed> $request  Expected keys: generation, expectedRevision.
     * @return array<string, mixed>|WP_Error
     */
    public function resolveFinding($scan_ref, $group_ref, $finding_ref, array $request = [])
    {
        $snapshot = $this->coordinator->load((string) $scan_ref);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        $identity = $this->validateSnapshotRequest($snapshot, $request);
        if (is_wp_error($identity)) {
            return $identity;
        }

        $group_ref = strtolower(trim((string) $group_ref));
        if (! preg_match('/^vemg_[a-f0-9]{20}$/', $group_ref)) {
            return $this->error('media_finding_group_invalid', __('The media finding group is invalid.', 'dbvc'), 400);
        }

        $finding_ref = strtolower(trim((string) $finding_ref));
        if (! preg_match('/^vemf_[a-f0-9]{20}$/', $finding_ref)) {
            return $this->error('media_finding_invalid', __('The media finding reference is invalid.', 'dbvc'), 400);
        }

        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];
        $original_group = isset($groups[$group_ref]) && is_array($groups[$group_ref]) ? $groups[$group_ref] : null;
        if ($original_group === null) {
            return $this->error('media_finding_group_unavailable', __('The media finding group is unavailable.', 'dbvc'), 404);
        }

        $original_findings = isset($original_group['findings']) && is_array($original_group['findings'])
            ? $original_group['findings']
            : [];
        $original_finding = isset($original_findings[$finding_ref]) && is_array($original_findings[$finding_ref])
            ? $original_findings[$finding_ref]
            : null;
        if ($original_finding === null) {
            return $this->error('media_finding_unavailable', __('The media finding is unavailable.', 'dbvc'), 404);
        }

        // Recheck owner status, public/show-UI visibility, exclusions, and object capability.
        $owner = $this->resolveEligibleOwner($original_group);
        if (empty($owner)) {
            return $this->resolutionStatus($snapshot, $group_ref, $finding_ref, $original_finding, 'unavailable', 'unavailable', __('This entity is no longer eligible for editing. Refresh the scan before taking further action.', 'dbvc'));
        }

        // Rescan the single owner to recheck field applicability and current empty value.
        $rescanned = $this->scanner->scan([
            [
                'family' => $owner['family'],
                'subtype' => $owner['subtype'],
                'id' => $owner['id'],
            ],
        ], (string) ($snapshot['generation'] ?? ''));
        if (is_wp_error($rescanned)) {
            return $this->resolutionStatus($snapshot, $group_ref, $finding_ref, $original_finding, 'unavailable', 'unavailable', __('This field could not be revalidated safely. Refresh the scan after the provider is available.', 'dbvc'));
        }

        $current_group = isset($rescanned['groups'][$group_ref]) && is_array($rescanned['groups'][$group_ref])
            ? $rescanned['groups'][$group_ref]
            : [];
        $current_findings = isset($current_group['findings']) && is_array($current_group['findings'])
            ? $current_group['findings']
            : [];
        $current_finding = isset($current_findings[$finding_ref]) && is_array($current_findings[$finding_ref])
            ? $current_findings[$finding_ref]
            : null;

        if ($current_finding === null) {
            // The field is no longer confirmed missing (populated after scan, definition changed, or removed).
            return $this->resolutionStatus($snapshot, $group_ref, $finding_ref, $original_finding, 'resolved', 'unavailable', __('This field is no longer confirmed missing. Refresh the scan before taking further action.', 'dbvc'));
        }

        $original_fingerprint = (string) ($original_finding['empty_fingerprint'] ?? '');
        $current_fingerprint = (string) ($current_finding['empty_fingerprint'] ?? '');
        if ($original_fingerprint === ''
            || $current_fingerprint === ''
            || ! hash_equals($original_fingerprint, $current_fingerprint)) {
            return $this->resolutionStatus($snapshot, $group_ref, $finding_ref, $current_finding, 'changed', 'blocked_stale', __('This field is still empty, but its scan evidence changed. Refresh the scan before taking further action.', 'dbvc'));
        }

        // Still missing with matching evidence: mint one fresh standard descriptor.
        $family = sanitize_key((string) ($current_finding['family'] ?? ''));
        if (! in_array($family, ['featured_image', 'acf_image', 'acf_gallery'], true)) {
            return $this->resolutionStatus($snapshot, $group_ref, $finding_ref, $current_finding, 'unavailable', 'unavailable', __('This field family is not supported for remediation.', 'dbvc'));
        }

        $descriptor = $this->buildDescriptor($owner, $finding_ref, $current_finding, $family);

        // Descriptor-level object capability recheck (fails closed on permission loss).
        if (! $this->capabilities->canEditDescriptor($descriptor)) {
            return $this->error('media_finding_forbidden', __('You cannot edit this entity.', 'dbvc'), 403);
        }

        return [
            'snapshot' => $snapshot,
            'group_ref' => $group_ref,
            'finding_ref' => $finding_ref,
            'status' => 'writable',
            'descriptor_status' => 'hydrated',
            'family' => $family,
            'label' => $this->findingLabel($current_finding, $family),
            'message' => __('This supported media field is still empty and ready for a fresh descriptor.', 'dbvc'),
            'finding' => $current_finding,
            'descriptor' => $descriptor,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param string               $group_ref
     * @param string               $finding_ref
     * @param array<string, mixed> $finding
     * @param string               $status
     * @param string               $descriptor_status
     * @param string               $message
     * @return array<string, mixed>
     */
    private function resolutionStatus(array $snapshot, $group_ref, $finding_ref, array $finding, $status, $descriptor_status, $message)
    {
        return [
            'snapshot' => $snapshot,
            'group_ref' => $group_ref,
            'finding_ref' => $finding_ref,
            'status' => $status,
            'descriptor_status' => $descriptor_status,
            'family' => sanitize_key((string) ($finding['family'] ?? '')),
            'label' => $this->findingLabel($finding, sanitize_key((string) ($finding['family'] ?? ''))),
            'message' => $message,
            'finding' => $finding,
            'descriptor' => null,
        ];
    }

    /**
     * @param array<string, mixed> $owner
     * @param string               $finding_ref
     * @param array<string, mixed> $finding
     * @param string               $family
     * @return EditableDescriptor
     */
    private function buildDescriptor(array $owner, $finding_ref, array $finding, $family)
    {
        $field = isset($finding['field']) && is_array($finding['field']) ? $finding['field'] : [];
        $entity_family = sanitize_key((string) ($owner['family'] ?? ''));
        $entity_id = absint($owner['id'] ?? 0);
        $subtype = sanitize_key((string) ($owner['subtype'] ?? ''));
        $acf_object_id = sanitize_text_field((string) ($owner['acf_object_id'] ?? ''));
        $scope = $entity_family === 'term' ? 'shared_entity' : 'current_entity';
        $fingerprint = sanitize_text_field((string) ($finding['empty_fingerprint'] ?? ''));
        $label = $this->findingLabel($finding, $family);
        $token = $this->registry->createToken('media_finding|' . sanitize_key((string) $finding_ref));

        $entity = [
            'type' => $entity_family,
            'id' => $entity_id,
            'subtype' => $subtype,
            'acf_object_id' => $acf_object_id !== '' ? $acf_object_id : ($entity_id > 0 ? (string) $entity_id : ''),
        ];

        if ($family === 'featured_image') {
            $source = [
                'type' => 'post_field',
                'source_context' => 'media_manager_finding',
                'field_name' => 'featured_image',
                'field_type' => 'featured_image',
            ];
            $input = 'image';
            $resolver_name = 'post_featured_image';
            $contract = 'post_featured_image';
            $group_path = [];
        } else {
            $field_type = $family === 'acf_gallery' ? 'gallery' : 'image';
            $input = $field_type === 'gallery' ? 'gallery' : 'image';
            $selector = (string) ($field['selector'] ?? '');
            $field_name = (string) ($field['field_name'] ?? '');
            $field_key = (string) ($field['field_key'] ?? '');
            $group_path = isset($field['group_path']) && is_array($field['group_path'])
                ? array_values(array_filter(array_map('strval', $field['group_path'])))
                : [];
            $source = [
                'type' => 'acf_field',
                'source_context' => 'media_manager_finding',
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $selector,
                'field_selector_raw' => $selector,
                'leaf_field_name' => $field_name,
                'leaf_field_key' => $field_key,
                'field_type' => $field_type,
                'group_path' => $group_path,
                'is_grouped_field' => ! empty($group_path),
            ];
            $resolver_name = $field_type === 'gallery' ? 'acf_gallery' : 'acf_image';
            $contract = $field_type === 'gallery' ? 'acf_gallery' : 'acf_image';
        }

        return new EditableDescriptor(
            $token,
            'editable',
            $scope,
            $entity,
            [
                'context' => 'media_manager',
                'attribute' => 'media_manager_finding',
            ],
            $source,
            [
                'label' => $label,
                'badgeLabel' => __('Missing media', 'dbvc'),
                'input' => $input,
            ],
            [
                'name' => $resolver_name,
            ],
            [],
            [
                'type' => $entity_family,
                'id' => $entity_id,
                'subtype' => $subtype,
                'scope' => $scope,
                'isCurrentPageEntity' => false,
                'isLoopOwned' => false,
            ],
            [],
            [
                'fieldName' => isset($source['field_name']) ? (string) $source['field_name'] : '',
                'fieldKey' => isset($source['field_key']) ? (string) $source['field_key'] : '',
                'groupPath' => $group_path,
            ],
            [
                'version' => 1,
                'kind' => 'media',
                'target' => 'field',
                'contract' => $contract,
                'renderContext' => 'media_manager',
                'reloadAfterSave' => true,
                'origin' => 'media_manager_finding',
                'expectedState' => 'empty',
                'expectedEmptyFingerprint' => $fingerprint,
            ]
        );
    }

    /**
     * @param array<string, mixed> $finding
     * @param string               $family
     * @return string
     */
    private function findingLabel(array $finding, $family)
    {
        $field = isset($finding['field']) && is_array($finding['field']) ? $finding['field'] : [];
        $label = sanitize_text_field((string) ($field['field_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        return $family === 'featured_image' ? __('Featured image', 'dbvc') : __('Media field', 'dbvc');
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param string               $group_ref
     * @param string               $finding_ref
     * @param array<string, mixed> $finding
     * @param string               $status
     * @param string               $descriptor_status
     * @param string               $message
     * @return array<string, mixed>
     */
    private function statusResult(array $snapshot, $group_ref, $finding_ref, array $finding, $status, $descriptor_status, $message)
    {
        $family = sanitize_key((string) ($finding['family'] ?? ''));

        return [
            'viewModelVersion' => self::VIEW_MODEL_VERSION,
            'scan' => $this->projectScanIdentity($snapshot),
            'finding' => [
                'findingRef' => sanitize_key((string) $finding_ref),
                'groupRef' => sanitize_key((string) $group_ref),
                'family' => $family,
                'label' => $this->findingLabel($finding, $family),
                'status' => sanitize_key((string) $status),
                'descriptorStatus' => sanitize_key((string) $descriptor_status),
                'message' => sanitize_text_field((string) $message),
            ],
            'descriptor' => null,
            'availableActions' => [
                'assignMedia' => false,
                'openMediaLibrary' => false,
                'save' => false,
                'refreshScan' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function projectScanIdentity(array $snapshot)
    {
        return [
            'scanRef' => sanitize_key((string) ($snapshot['scan_ref'] ?? '')),
            'generation' => sanitize_key((string) ($snapshot['generation'] ?? '')),
            'revision' => absint($snapshot['revision'] ?? 0),
            'state' => sanitize_key((string) ($snapshot['state'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>|null
     */
    private function resolveEligibleOwner(array $group)
    {
        $family = sanitize_key((string) ($group['owner']['family'] ?? ''));
        $subtype = sanitize_key((string) ($group['owner']['subtype'] ?? ''));
        $id = absint($group['owner']['id'] ?? 0);

        if ($family === 'post') {
            $entity = get_post($id);
            $assessment = $entity instanceof WP_Post ? $this->eligibility->assessPost($entity) : [];
        } elseif ($family === 'term') {
            $entity = get_term($id, $subtype);
            $assessment = ! is_wp_error($entity) && $entity instanceof WP_Term
                ? $this->eligibility->assessTerm($entity)
                : [];
        } else {
            return null;
        }

        if (empty($assessment['eligible'])) {
            return null;
        }

        return [
            'family' => $family,
            'subtype' => $subtype,
            'id' => $id,
            'acf_object_id' => sanitize_text_field((string) ($group['owner']['acf_object_id'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $request
     * @return array<string, mixed>|WP_Error
     */
    private function validateSnapshotRequest(array $snapshot, array $request)
    {
        if (array_key_exists('generation', $request)) {
            $generation = sanitize_key((string) $request['generation']);
            if ($generation === '' || ! hash_equals((string) ($snapshot['generation'] ?? ''), $generation)) {
                return $this->error('media_scan_generation_mismatch', __('The media scan generation does not match.', 'dbvc'), 409);
            }
        }

        if (array_key_exists('expectedRevision', $request)) {
            if (! is_numeric($request['expectedRevision'])) {
                return $this->error('media_scan_revision_invalid', __('The media scan revision is invalid.', 'dbvc'), 400);
            }
            if (absint($snapshot['revision'] ?? 0) !== absint($request['expectedRevision'])) {
                return $this->error('media_scan_revision_changed', __('The media scan changed. Refresh the results before continuing.', 'dbvc'), 409);
            }
        }

        return [
            'generation' => sanitize_key((string) ($request['generation'] ?? '')),
            'expectedRevision' => absint($request['expectedRevision'] ?? 0),
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @param int    $status
     * @return WP_Error
     */
    private function error($code, $message, $status)
    {
        return new WP_Error(
            sanitize_key((string) $code),
            sanitize_text_field((string) $message),
            ['status' => absint($status)]
        );
    }
}
