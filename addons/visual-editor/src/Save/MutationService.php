<?php

namespace Dbvc\VisualEditor\Save;

use Dbvc\VisualEditor\Audit\ChangeLogger;
use Dbvc\VisualEditor\Cache\CacheInvalidator;
use Dbvc\VisualEditor\Journal\ChangeJournalRecorder;
use Dbvc\VisualEditor\Presentation\DescriptorSummaryBuilder;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;

final class MutationService
{
    /**
     * @var ResolverRegistry
     */
    private $resolvers;

    /**
     * @var ValidationService
     */
    private $validator;

    /**
     * @var SanitizationService
     */
    private $sanitizer;

    /**
     * @var ChangeLogger
     */
    private $audit;

    /**
     * @var CacheInvalidator
     */
    private $cache;

    /**
     * @var DescriptorSummaryBuilder
     */
    private $summaries;

    /**
     * @var ChangeJournalRecorder|null
     */
    private $journal;

    public function __construct(ResolverRegistry $resolvers, ValidationService $validator, SanitizationService $sanitizer, ChangeLogger $audit, CacheInvalidator $cache, DescriptorSummaryBuilder $summaries, ?ChangeJournalRecorder $journal = null)
    {
        $this->resolvers = $resolvers;
        $this->validator = $validator;
        $this->sanitizer = $sanitizer;
        $this->audit = $audit;
        $this->cache = $cache;
        $this->summaries = $summaries;
        $this->journal = $journal;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function mutate(EditableDescriptor $descriptor, $value)
    {
        if ($descriptor->status !== 'editable') {
            return [
                'ok' => false,
                'message' => __('Descriptor is not editable.', 'dbvc'),
            ];
        }

        $resolver = $this->resolvers->resolve($descriptor);
        if ($resolver->name() === 'unsupported') {
            return [
                'ok' => false,
                'message' => __('Descriptor is not in the MVP save allowlist.', 'dbvc'),
            ];
        }

        $old_value = $resolver->getValue($descriptor);
        $validation = $this->validator->validate($resolver, $descriptor, $value);
        if (empty($validation['ok'])) {
            return [
                'ok' => false,
                'message' => isset($validation['message']) ? (string) $validation['message'] : __('Validation failed.', 'dbvc'),
            ];
        }

        $sanitized = $this->sanitizer->sanitize($resolver, $descriptor, $value);
        $change_set_id = $this->journal instanceof ChangeJournalRecorder
            ? $this->journal->start($descriptor, $resolver->name())
            : 0;
        $result = $resolver->save($descriptor, $sanitized);
        if (empty($result['ok'])) {
            $journal_context = isset($result['journal']) && is_array($result['journal']) ? $result['journal'] : [];

            if ($this->journal instanceof ChangeJournalRecorder) {
                $this->journal->recordFailure(
                    $change_set_id,
                    $descriptor,
                    $resolver->name(),
                    $old_value,
                    $sanitized,
                    isset($result['message']) ? (string) $result['message'] : __('Save failed.', 'dbvc'),
                    $journal_context
                );
            }

            return [
                'ok' => false,
                'message' => isset($result['message']) ? (string) $result['message'] : __('Save failed.', 'dbvc'),
                'changeSetId' => $change_set_id,
            ];
        }

        $current_value = array_key_exists('value', $result) ? $result['value'] : $resolver->getValue($descriptor);
        $display_value = $resolver->getDisplayValue($descriptor, $current_value);
        $display_mode = $resolver->getDisplayMode($descriptor);
        $display_candidates = $this->resolveDisplayCandidates($resolver, $descriptor, $current_value);
        $entity_summary = $this->summaries->buildEntitySummary($descriptor);
        $source_summary = $this->summaries->buildSourceSummary($descriptor);
        $save_summary = $this->summaries->buildSaveSummary($descriptor, $entity_summary, $source_summary);
        if ($this->journal instanceof ChangeJournalRecorder) {
            $journal_context = isset($result['journal']) && is_array($result['journal']) ? $result['journal'] : [];
            $this->journal->recordSuccess($change_set_id, $descriptor, $resolver->name(), $old_value, $current_value, $journal_context);
        }
        $this->audit->log($descriptor, $old_value, $current_value);
        $this->cache->invalidate($descriptor);

        return [
            'ok' => true,
            'token' => $descriptor->token,
            'status' => 'saved',
            'value' => $current_value,
            'displayValue' => $display_value,
            'displayMode' => $display_mode,
            'displayCandidates' => $display_candidates,
            'displayKey' => isset($descriptor->render['display_key']) ? (string) $descriptor->render['display_key'] : 'default',
            'syncGroup' => isset($descriptor->render['sync_group']) ? (string) $descriptor->render['sync_group'] : '',
            'sourceGroup' => isset($descriptor->render['source_group']) ? (string) $descriptor->render['source_group'] : '',
            'descriptorVersion' => isset($descriptor->mutation['version']) ? absint($descriptor->mutation['version']) : 1,
            'pageContext' => isset($descriptor->page) && is_array($descriptor->page) ? $descriptor->page : [],
            'ownerContext' => isset($descriptor->owner) && is_array($descriptor->owner) ? $descriptor->owner : [],
            'loopContext' => isset($descriptor->loop) && is_array($descriptor->loop) ? $descriptor->loop : [],
            'pathContext' => isset($descriptor->path) && is_array($descriptor->path) ? $descriptor->path : [],
            'mutationContract' => isset($descriptor->mutation) && is_array($descriptor->mutation) ? $descriptor->mutation : [],
            'changeSetId' => $change_set_id,
            'entitySummary' => $entity_summary,
            'sourceSummary' => $source_summary,
            'saveSummary' => $save_summary,
            'message' => __('Saved successfully.', 'dbvc'),
        ];
    }

    /**
     * @param EditableDescriptor $parent_descriptor
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $batch_context
     * @return array<string, mixed>
     */
    public function mutateBatch(EditableDescriptor $parent_descriptor, array $items, array $batch_context = [])
    {
        if (empty($items)) {
            return [
                'ok' => false,
                'message' => __('No fields were provided for the batch save.', 'dbvc'),
            ];
        }

        $prepared = [];

        foreach (array_values($items) as $index => $item) {
            $descriptor = isset($item['descriptor']) && $item['descriptor'] instanceof EditableDescriptor ? $item['descriptor'] : null;
            if (! $descriptor) {
                return [
                    'ok' => false,
                    'message' => __('A batch save field descriptor is missing.', 'dbvc'),
                    'failedIndex' => $index,
                    'stage' => 'preflight',
                ];
            }

            if ($descriptor->status !== 'editable') {
                return [
                    'ok' => false,
                    'message' => __('One of the batch save fields is not editable.', 'dbvc'),
                    'failedIndex' => $index,
                    'stage' => 'preflight',
                ];
            }

            $resolver = $this->resolvers->resolve($descriptor);
            if ($resolver->name() === 'unsupported') {
                return [
                    'ok' => false,
                    'message' => __('One of the batch save fields is not in the save allowlist.', 'dbvc'),
                    'failedIndex' => $index,
                    'stage' => 'preflight',
                ];
            }

            $value = array_key_exists('value', $item) ? $item['value'] : null;
            $old_value = $resolver->getValue($descriptor);
            if (array_key_exists('expectedValue', $item) && ! $this->valuesMatchForStaleCheck($old_value, $item['expectedValue'])) {
                return [
                    'ok' => false,
                    'message' => __('Composite save was blocked because one child field changed after this panel was loaded. Reload the field details and try again.', 'dbvc'),
                    'failedIndex' => $index,
                    'stage' => 'stale',
                ];
            }

            $validation = $this->validator->validate($resolver, $descriptor, $value);
            if (empty($validation['ok'])) {
                return [
                    'ok' => false,
                    'message' => isset($validation['message']) ? (string) $validation['message'] : __('Batch field validation failed.', 'dbvc'),
                    'failedIndex' => $index,
                    'stage' => 'preflight',
                ];
            }

            $prepared[] = [
                'index' => $index,
                'descriptor' => $descriptor,
                'resolver' => $resolver,
                'oldValue' => $old_value,
                'value' => $this->sanitizer->sanitize($resolver, $descriptor, $value),
            ];
        }

        $change_set_id = $this->journal instanceof ChangeJournalRecorder
            ? $this->journal->startBatch($parent_descriptor, 'composite_text', $batch_context)
            : 0;
        $completed = [];

        foreach ($prepared as $item) {
            $descriptor = $item['descriptor'];
            $resolver = $item['resolver'];
            $result = $resolver->save($descriptor, $item['value']);
            if (empty($result['ok'])) {
                $message = isset($result['message']) ? (string) $result['message'] : __('Batch field save failed.', 'dbvc');
                $rollback = $this->rollbackBatchItems($completed, $change_set_id);

                if ($this->journal instanceof ChangeJournalRecorder) {
                    $this->journal->recordBatchItem(
                        $change_set_id,
                        $descriptor,
                        $resolver->name(),
                        $item['oldValue'],
                        $item['value'],
                        'failed',
                        $message,
                        [
                            'batch' => $batch_context,
                            'index' => $item['index'],
                        ]
                    );
                    $this->journal->finishBatch($change_set_id, 'failed', $message);
                }

                return [
                    'ok' => false,
                    'message' => empty($rollback['failed'])
                        ? sprintf(
                            /* translators: %s: save failure message */
                            __('Composite batch save failed before completion and earlier fields were rolled back. %s', 'dbvc'),
                            $message
                        )
                        : sprintf(
                            /* translators: %s: save failure message */
                            __('Composite batch save failed and one or more rollback writes also failed. Review the affected fields before continuing. %s', 'dbvc'),
                            $message
                        ),
                    'failedIndex' => $item['index'],
                    'stage' => 'write',
                    'changeSetId' => $change_set_id,
                    'rollback' => $rollback,
                ];
            }

            $current_value = array_key_exists('value', $result) ? $result['value'] : $resolver->getValue($descriptor);
            $completed[] = [
                'index' => $item['index'],
                'descriptor' => $descriptor,
                'resolver' => $resolver,
                'oldValue' => $item['oldValue'],
                'newValue' => $current_value,
                'journal' => isset($result['journal']) && is_array($result['journal']) ? $result['journal'] : [],
            ];
        }

        $children = [];
        foreach ($completed as $item) {
            $descriptor = $item['descriptor'];
            $resolver = $item['resolver'];
            $display_value = $resolver->getDisplayValue($descriptor, $item['newValue']);
            $display_mode = $resolver->getDisplayMode($descriptor);
            $entity_summary = $this->summaries->buildEntitySummary($descriptor);
            $source_summary = $this->summaries->buildSourceSummary($descriptor);

            if ($this->journal instanceof ChangeJournalRecorder) {
                $this->journal->recordBatchItem(
                    $change_set_id,
                    $descriptor,
                    $resolver->name(),
                    $item['oldValue'],
                    $item['newValue'],
                    'completed',
                    '',
                    array_merge(
                        [
                            'batch' => $batch_context,
                            'index' => $item['index'],
                        ],
                        $item['journal']
                    )
                );
            }

            $this->audit->log($descriptor, $item['oldValue'], $item['newValue']);
            $this->cache->invalidate($descriptor);

            $children[] = [
                'index' => $item['index'],
                'token' => $descriptor->token,
                'value' => $item['newValue'],
                'displayValue' => $display_value,
                'displayMode' => $display_mode,
                'displayCandidates' => $this->resolveDisplayCandidates($resolver, $descriptor, $item['newValue']),
                'entitySummary' => $entity_summary,
                'sourceSummary' => $source_summary,
                'saveSummary' => $this->summaries->buildSaveSummary($descriptor, $entity_summary, $source_summary),
            ];
        }

        if ($this->journal instanceof ChangeJournalRecorder) {
            $this->journal->finishBatch($change_set_id, 'completed');
        }

        return [
            'ok' => true,
            'status' => 'saved',
            'token' => $parent_descriptor->token,
            'changeSetId' => $change_set_id,
            'children' => $children,
            'message' => __('Composite fields saved successfully.', 'dbvc'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $completed
     * @param int $change_set_id
     * @return array<string, mixed>
     */
    private function rollbackBatchItems(array $completed, $change_set_id)
    {
        $rolled_back = [];
        $failed = [];

        foreach (array_reverse($completed) as $item) {
            $descriptor = $item['descriptor'];
            $resolver = $item['resolver'];
            $result = $resolver->save($descriptor, $item['oldValue']);
            $ok = ! empty($result['ok']);
            $status = $ok ? 'rolled_back' : 'rollback_failed';
            $message = isset($result['message']) ? (string) $result['message'] : '';

            if ($this->journal instanceof ChangeJournalRecorder) {
                $this->journal->recordBatchItem(
                    $change_set_id,
                    $descriptor,
                    $resolver->name(),
                    $item['oldValue'],
                    $item['newValue'],
                    $status,
                    $message,
                    [
                        'index' => $item['index'],
                        'rollbackAttempted' => true,
                    ]
                );
            }

            $entry = [
                'index' => $item['index'],
                'token' => $descriptor->token,
                'ok' => $ok,
            ];

            if ($ok) {
                $rolled_back[] = $entry;
            } else {
                $entry['message'] = $message !== '' ? $message : __('Rollback write failed.', 'dbvc');
                $failed[] = $entry;
            }
        }

        return [
            'rolledBack' => array_values($rolled_back),
            'failed' => array_values($failed),
        ];
    }

    /**
     * @param mixed $current_value
     * @param mixed $expected_value
     * @return bool
     */
    private function valuesMatchForStaleCheck($current_value, $expected_value)
    {
        return $this->normalizeValueForStaleCheck($current_value) === $this->normalizeValueForStaleCheck($expected_value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalizeValueForStaleCheck($value)
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($value);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param object             $resolver
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<int, array<string, string>>
     */
    private function resolveDisplayCandidates($resolver, EditableDescriptor $descriptor, $value)
    {
        $candidates = [];

        if (is_object($resolver) && method_exists($resolver, 'getDisplayCandidates')) {
            $candidates = $resolver->getDisplayCandidates($descriptor, $value);
        }

        if (! is_array($candidates) || empty($candidates)) {
            $candidates = [
                [
                    'key' => isset($descriptor->render['display_key']) ? (string) $descriptor->render['display_key'] : 'default',
                    'value' => $resolver->getDisplayValue($descriptor, $value),
                    'mode' => $resolver->getDisplayMode($descriptor),
                ],
            ];
        }

        $normalized = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $normalized[] = [
                'key' => isset($candidate['key']) ? sanitize_key((string) $candidate['key']) : 'default',
                'value' => isset($candidate['value']) ? (string) $candidate['value'] : '',
                'mode' => isset($candidate['mode']) ? sanitize_key((string) $candidate['mode']) : 'text',
            ];
        }

        return $normalized;
    }
}
