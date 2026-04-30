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
            if ($this->journal instanceof ChangeJournalRecorder) {
                $this->journal->recordFailure(
                    $change_set_id,
                    $descriptor,
                    $resolver->name(),
                    $old_value,
                    $sanitized,
                    isset($result['message']) ? (string) $result['message'] : __('Save failed.', 'dbvc')
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
            $this->journal->recordSuccess($change_set_id, $descriptor, $resolver->name(), $old_value, $current_value);
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
