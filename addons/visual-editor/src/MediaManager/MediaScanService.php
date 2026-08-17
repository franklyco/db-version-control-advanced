<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Error;
use WP_Post;
use WP_Term;

final class MediaScanService
{
    /**
     * @var AcfMediaFieldCatalog
     */
    private $catalog;

    /**
     * @var MediaAssignmentValueClassifier
     */
    private $classifier;

    /**
     * @var callable|null
     */
    private $acf_value_reader;

    public function __construct(AcfMediaFieldCatalog $catalog, MediaAssignmentValueClassifier $classifier, $acf_value_reader = null)
    {
        $this->catalog = $catalog;
        $this->classifier = $classifier;
        $this->acf_value_reader = is_callable($acf_value_reader) ? $acf_value_reader : null;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @param string                           $generation
     * @return array<string, mixed>|WP_Error
     */
    public function scan(array $candidates, $generation)
    {
        $generation = $this->normalizeGeneration($generation);
        if ($generation === '') {
            return new WP_Error('media_scan_generation_invalid', __('The media scan generation is invalid.', 'dbvc'));
        }

        $result = [
            'groups' => [],
            'counts' => [
                'candidates_received' => count($candidates),
                'candidates_ineligible' => 0,
                'supported_fields_scanned' => 0,
                'unsupported_field_observations' => 0,
                'invalid_nonempty_values' => 0,
                'findings' => 0,
            ],
        ];

        foreach ($candidates as $candidate) {
            try {
                $scanned = $this->scanCandidate(is_array($candidate) ? $candidate : [], $generation);
            } catch (\Throwable $throwable) {
                unset($throwable);

                return new WP_Error(
                    'media_scan_candidate_failed',
                    __('A media scan candidate could not be inspected safely.', 'dbvc'),
                    ['retryable' => true]
                );
            }

            if (is_wp_error($scanned)) {
                return $scanned;
            }

            foreach ($scanned['counts'] as $key => $count) {
                if ($key === 'candidates_received') {
                    continue;
                }
                if (isset($result['counts'][$key])) {
                    $result['counts'][$key] += absint($count);
                }
            }

            if (! empty($scanned['group']) && is_array($scanned['group'])) {
                $group_ref = sanitize_key((string) ($scanned['group']['group_ref'] ?? ''));
                if ($group_ref !== '') {
                    $result['groups'][$group_ref] = $scanned['group'];
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param string               $generation
     * @return array<string, mixed>|WP_Error
     */
    private function scanCandidate(array $candidate, $generation)
    {
        $family = sanitize_key((string) ($candidate['family'] ?? ''));
        $subtype = sanitize_key((string) ($candidate['subtype'] ?? ''));
        $entity_id = absint($candidate['id'] ?? 0);
        $entity = $family === 'post' ? get_post($entity_id) : get_term($entity_id, $subtype);

        if (($family === 'post' && ! ($entity instanceof WP_Post))
            || ($family === 'term' && (is_wp_error($entity) || ! ($entity instanceof WP_Term)))) {
            return $this->emptyCandidateResult(true);
        }

        $catalog = $family === 'post'
            ? $this->catalog->forPost($entity)
            : $this->catalog->forTerm($entity);
        if (empty($catalog['eligible'])) {
            return $this->emptyCandidateResult(true);
        }

        if (empty($catalog['available'])) {
            $code = sanitize_key((string) ($catalog['reason'] ?? 'acf_unavailable'));

            return new WP_Error(
                'media_scan_' . ($code !== '' ? $code : 'acf_unavailable'),
                __('ACF media definitions are unavailable for this scan.', 'dbvc'),
                ['retryable' => false]
            );
        }

        if (absint($catalog['counts']['visibility_errors'] ?? 0) > 0) {
            return new WP_Error(
                'media_scan_acf_visibility_failed',
                __('An ACF field-group visibility check failed.', 'dbvc'),
                ['retryable' => true]
            );
        }

        $counts = $this->emptyCandidateResult(false)['counts'];
        $counts['unsupported_field_observations'] = $this->unsupportedCount($catalog['counts'] ?? []);
        $findings = [];

        if ($entity instanceof WP_Post && ! empty($catalog['owner']['id'])) {
            $assessment_featured = ! empty($catalog['owner']['id'])
                && post_type_supports((string) $entity->post_type, 'thumbnail');
            if ($assessment_featured) {
                $counts['supported_fields_scanned']++;
                $raw_featured = get_post_thumbnail_id($entity->ID);
                $classification = $this->classifier->classify('featured_image', $raw_featured);
                if ($classification === MediaAssignmentValueClassifier::EMPTY_VALUE) {
                    $finding = $this->featuredImageFinding($entity, $generation, $raw_featured);
                    $findings[$finding['finding_ref']] = $finding;
                } elseif ($classification === MediaAssignmentValueClassifier::INVALID_NONEMPTY_VALUE) {
                    $counts['invalid_nonempty_values']++;
                }
            }
        }

        $value_cache = [];
        foreach (($catalog['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $counts['supported_fields_scanned']++;
            $raw_value = $this->readAcfValue($field, (string) ($catalog['owner']['acf_object_id'] ?? ''), $value_cache);
            if (is_wp_error($raw_value)) {
                return $raw_value;
            }

            $field_type = sanitize_key((string) ($field['field_type'] ?? ''));
            $classification = $this->classifier->classify($field_type, $raw_value);
            if ($classification === MediaAssignmentValueClassifier::EMPTY_VALUE) {
                $finding = $this->acfFinding($family, $subtype, $entity_id, $field, $generation, $raw_value);
                $findings[$finding['finding_ref']] = $finding;
            } elseif ($classification === MediaAssignmentValueClassifier::INVALID_NONEMPTY_VALUE) {
                $counts['invalid_nonempty_values']++;
            }
        }

        $counts['findings'] = count($findings);
        if (empty($findings)) {
            return [
                'group' => [],
                'counts' => $counts,
            ];
        }

        $group_ref = $this->groupReference($family, $subtype, $entity_id, $generation);

        return [
            'group' => [
                'group_ref' => $group_ref,
                'owner' => [
                    'family' => $family,
                    'subtype' => $subtype,
                    'id' => $entity_id,
                    'acf_object_id' => sanitize_text_field((string) ($catalog['owner']['acf_object_id'] ?? '')),
                ],
                'entity_label' => $this->entityLabel($entity),
                'entity_type_label' => $this->entityTypeLabel($family, $subtype),
                'modified_gmt' => $entity instanceof WP_Post ? sanitize_text_field((string) $entity->post_modified_gmt) : '',
                'status' => 'scan_current',
                'scanned_at' => time(),
                'findings' => $findings,
            ],
            'counts' => $counts,
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @param string               $acf_object_id
     * @param array<string, mixed> $value_cache
     * @return mixed|WP_Error
     */
    private function readAcfValue(array $field, $acf_object_id, array &$value_cache)
    {
        if (! is_callable($this->acf_value_reader) && ! function_exists('get_field')) {
            return new WP_Error(
                'media_scan_acf_value_reader_unavailable',
                __('ACF values cannot be read for this scan.', 'dbvc'),
                ['retryable' => false]
            );
        }

        $selector = trim((string) ($field['selector'] ?? ''));
        $acf_object_id = trim((string) $acf_object_id);
        if ($selector === '' || $acf_object_id === '') {
            return new WP_Error(
                'media_scan_acf_field_context_invalid',
                __('An ACF media field context is invalid.', 'dbvc'),
                ['retryable' => false]
            );
        }

        if (! array_key_exists($selector, $value_cache)) {
            try {
                $value_cache[$selector] = is_callable($this->acf_value_reader)
                    ? call_user_func($this->acf_value_reader, $selector, $acf_object_id)
                    : get_field($selector, $acf_object_id, false);
            } catch (\Throwable $throwable) {
                unset($throwable);

                return new WP_Error(
                    'media_scan_acf_value_read_failed',
                    __('An ACF media value could not be read.', 'dbvc'),
                    ['retryable' => true]
                );
            }
        }

        $value = $value_cache[$selector];
        if (sanitize_key((string) ($field['path_kind'] ?? '')) !== 'group') {
            return $value;
        }

        $path = isset($field['path']) && is_array($field['path']) ? array_values($field['path']) : [];
        if (! empty($path)) {
            array_shift($path);
        }

        foreach ($path as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            if (! is_array($value)) {
                return $value;
            }

            $name = (string) ($segment['name'] ?? '');
            $key = (string) ($segment['key'] ?? '');
            if ($name !== '' && array_key_exists($name, $value)) {
                $value = $value[$name];
            } elseif ($key !== '' && array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * @param WP_Post $post
     * @param string  $generation
     * @param mixed   $raw_value
     * @return array<string, mixed>
     */
    private function featuredImageFinding(WP_Post $post, $generation, $raw_value)
    {
        $identity = 'featured_image';

        return [
            'finding_ref' => $this->findingReference('post', (string) $post->post_type, $post->ID, $identity, $generation),
            'family' => 'featured_image',
            'field' => [
                'field_key' => '',
                'field_name' => '_thumbnail_id',
                'field_label' => __('Featured image', 'dbvc'),
                'field_type' => 'featured_image',
                'selector' => '',
                'path_kind' => 'native',
                'path' => [],
                'group_path' => [],
            ],
            'context_label' => __('Native WordPress field', 'dbvc'),
            'empty_fingerprint' => $this->valueFingerprint($raw_value, $generation),
            'status' => 'missing',
        ];
    }

    /**
     * @param string               $family
     * @param string               $subtype
     * @param int                  $entity_id
     * @param array<string, mixed> $field
     * @param string               $generation
     * @param mixed                $raw_value
     * @return array<string, mixed>
     */
    private function acfFinding($family, $subtype, $entity_id, array $field, $generation, $raw_value)
    {
        $field_type = sanitize_key((string) ($field['field_type'] ?? ''));
        $path_parts = [];
        foreach (($field['path'] ?? []) as $segment) {
            if (is_array($segment)) {
                $path_parts[] = (string) (($segment['key'] ?? '') !== '' ? $segment['key'] : ($segment['name'] ?? ''));
            }
        }
        $identity = implode('/', array_filter($path_parts));
        $context_parts = [];
        if (! empty($field['group_label'])) {
            $context_parts[] = sanitize_text_field((string) $field['group_label']);
        }
        foreach (array_slice(is_array($field['path'] ?? null) ? $field['path'] : [], 0, -1) as $segment) {
            if (is_array($segment) && ! empty($segment['label'])) {
                $context_parts[] = sanitize_text_field((string) $segment['label']);
            }
        }

        return [
            'finding_ref' => $this->findingReference($family, $subtype, $entity_id, $field_type . '|' . $identity, $generation),
            'family' => $field_type === 'gallery' ? 'acf_gallery' : 'acf_image',
            'field' => [
                'field_key' => (string) ($field['field_key'] ?? ''),
                'field_name' => (string) ($field['field_name'] ?? ''),
                'field_label' => sanitize_text_field((string) ($field['field_label'] ?? '')),
                'field_type' => $field_type,
                'selector' => (string) ($field['selector'] ?? ''),
                'path_kind' => sanitize_key((string) ($field['path_kind'] ?? '')),
                'path' => isset($field['path']) && is_array($field['path']) ? $field['path'] : [],
                'group_path' => isset($field['group_path']) && is_array($field['group_path']) ? $field['group_path'] : [],
            ],
            'context_label' => implode(' / ', array_values(array_unique(array_filter($context_parts)))),
            'empty_fingerprint' => $this->valueFingerprint($raw_value, $generation),
            'status' => 'missing',
        ];
    }

    /**
     * @param mixed $entity
     * @return string
     */
    private function entityLabel($entity)
    {
        if ($entity instanceof WP_Post) {
            $title = get_the_title($entity);

            return sanitize_text_field(is_string($title) && $title !== '' ? $title : __('Untitled content', 'dbvc'));
        }

        return $entity instanceof WP_Term
            ? sanitize_text_field((string) $entity->name)
            : '';
    }

    /**
     * @param string $family
     * @param string $subtype
     * @return string
     */
    private function entityTypeLabel($family, $subtype)
    {
        $object = $family === 'post' ? get_post_type_object($subtype) : get_taxonomy($subtype);

        return $object && isset($object->labels->singular_name)
            ? sanitize_text_field((string) $object->labels->singular_name)
            : sanitize_text_field((string) $subtype);
    }

    /**
     * @param array<string, mixed> $counts
     * @return int
     */
    private function unsupportedCount(array $counts)
    {
        $total = 0;
        foreach (['conditional_fields', 'repeater_fields', 'flexible_content_fields', 'unsupported_nested_fields'] as $key) {
            $total += absint($counts[$key] ?? 0);
        }

        return $total;
    }

    /**
     * @param bool $ineligible
     * @return array<string, mixed>
     */
    private function emptyCandidateResult($ineligible)
    {
        return [
            'group' => [],
            'counts' => [
                'candidates_received' => 1,
                'candidates_ineligible' => $ineligible ? 1 : 0,
                'supported_fields_scanned' => 0,
                'unsupported_field_observations' => 0,
                'invalid_nonempty_values' => 0,
                'findings' => 0,
            ],
        ];
    }

    /**
     * @param string $family
     * @param string $subtype
     * @param int    $entity_id
     * @param string $generation
     * @return string
     */
    private function groupReference($family, $subtype, $entity_id, $generation)
    {
        return 'vemg_' . substr($this->identityHash($family . '|' . $subtype . '|' . absint($entity_id), $generation), 0, 20);
    }

    /**
     * @param string $family
     * @param string $subtype
     * @param int    $entity_id
     * @param string $field_identity
     * @param string $generation
     * @return string
     */
    private function findingReference($family, $subtype, $entity_id, $field_identity, $generation)
    {
        $seed = $family . '|' . $subtype . '|' . absint($entity_id) . '|' . (string) $field_identity;

        return 'vemf_' . substr($this->identityHash($seed, $generation), 0, 20);
    }

    /**
     * @param mixed  $value
     * @param string $generation
     * @return string
     */
    private function valueFingerprint($value, $generation)
    {
        return 'vemv_' . substr($this->identityHash(maybe_serialize($value), $generation . '|value'), 0, 24);
    }

    /**
     * @param string $seed
     * @param string $generation
     * @return string
     */
    private function identityHash($seed, $generation)
    {
        return hash_hmac('sha256', (string) $seed, wp_salt('auth') . '|' . (string) $generation);
    }

    /**
     * @param mixed $generation
     * @return string
     */
    private function normalizeGeneration($generation)
    {
        $generation = strtolower(trim((string) $generation));

        return strpos($generation, 'vmsg_') === 0 && preg_match('/^[a-z0-9_]+$/', $generation)
            ? $generation
            : '';
    }
}
