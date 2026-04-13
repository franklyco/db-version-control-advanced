<?php

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap dbvc-cc-workbench-wrap">
    <h1><?php esc_html_e('Content Mapping Workbench', 'dbvc'); ?></h1>
    <p class="description"><?php esc_html_e('Review low-confidence or conflicting mapping suggestions before import planning.', 'dbvc'); ?></p>

    <div class="dbvc-cc-workbench-controls">
        <label for="dbvc-cc-workbench-domain"><?php esc_html_e('Domain', 'dbvc'); ?></label>
        <select id="dbvc-cc-workbench-domain" class="regular-text">
            <option value=""><?php esc_html_e('All domains', 'dbvc'); ?></option>
        </select>
        <button type="button" id="dbvc-cc-workbench-domain-ai-warning" class="dbvc-cc-domain-ai-warning dbvc-cc-hidden" aria-live="polite"></button>

        <label for="dbvc-cc-workbench-limit"><?php esc_html_e('Limit', 'dbvc'); ?></label>
        <input type="number" id="dbvc-cc-workbench-limit" min="1" max="200" value="50" />

        <label for="dbvc-cc-workbench-min-confidence"><?php esc_html_e('Min confidence', 'dbvc'); ?></label>
        <input type="number" id="dbvc-cc-workbench-min-confidence" min="0" max="1" step="0.01" value="0.75" />

        <label>
            <input type="checkbox" id="dbvc-cc-workbench-include-decided" />
            <?php esc_html_e('Include decided items', 'dbvc'); ?>
        </label>

        <button type="button" class="button button-primary" id="dbvc-cc-workbench-refresh"><?php esc_html_e('Refresh Queue', 'dbvc'); ?></button>
    </div>
    <p class="description dbvc-cc-workbench-refresh-note">
        <?php esc_html_e('AI refresh runs update the queue automatically. Use Refresh Queue anytime to reload on demand.', 'dbvc'); ?>
    </p>

    <p id="dbvc-cc-workbench-status" aria-live="polite"></p>
    <p id="dbvc-cc-workbench-ai-refresh-note" class="dbvc-cc-ai-refresh-note dbvc-cc-hidden" aria-live="polite"></p>

    <div class="dbvc-cc-workbench-layout">
        <section class="dbvc-cc-workbench-queue" aria-label="<?php esc_attr_e('Review Queue', 'dbvc'); ?>">
            <h2><?php esc_html_e('Review Queue', 'dbvc'); ?></h2>
            <table class="widefat striped" id="dbvc-cc-workbench-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Domain', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Path', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Post Type', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Confidence', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Reasons', 'dbvc'); ?></th>
                        <th><?php esc_html_e('Decision', 'dbvc'); ?></th>
                        <th><?php esc_html_e('State', 'dbvc'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </section>

        <section class="dbvc-cc-workbench-detail" aria-label="<?php esc_attr_e('Suggestion Detail', 'dbvc'); ?>">
            <h2><?php esc_html_e('Suggestion Detail', 'dbvc'); ?></h2>
            <div id="dbvc-cc-workbench-empty"><?php esc_html_e('Select a queue item to review suggestions.', 'dbvc'); ?></div>
            <div id="dbvc-cc-workbench-detail-content" class="dbvc-cc-hidden">
                <p><strong><?php esc_html_e('Node', 'dbvc'); ?>:</strong> <span id="dbvc-cc-workbench-node"></span></p>
                <p><strong><?php esc_html_e('Source URL', 'dbvc'); ?>:</strong> <a id="dbvc-cc-workbench-source-url" href="#" target="_blank" rel="noopener noreferrer"></a></p>
                <pre id="dbvc-cc-workbench-json"></pre>

                <div class="dbvc-cc-workbench-actions">
                    <label for="dbvc-cc-workbench-notes"><?php esc_html_e('Reviewer notes', 'dbvc'); ?></label>
                    <textarea id="dbvc-cc-workbench-notes" rows="4"></textarea>
                    <button type="button" class="button button-primary" data-decision="approved"><?php esc_html_e('Approve', 'dbvc'); ?></button>
                    <button type="button" class="button" data-decision="edited"><?php esc_html_e('Approve with Edits', 'dbvc'); ?></button>
                    <button type="button" class="button" data-decision="rejected"><?php esc_html_e('Reject', 'dbvc'); ?></button>
                    <button type="button" class="button" id="dbvc-cc-workbench-open-mapping"><?php esc_html_e('Map Collection for Imports', 'dbvc'); ?></button>
                </div>
            </div>
        </section>
    </div>

    <section class="dbvc-cc-mapping-bridge" aria-label="<?php esc_attr_e('Map Collection for Imports', 'dbvc'); ?>">
        <div class="dbvc-cc-mapping-heading-bar">
            <div>
                <h2><?php esc_html_e('Map Collection for Imports', 'dbvc'); ?></h2>
                <p class="description"><?php esc_html_e('Bridge collected artifacts to import targets using catalog, section candidates, and media candidates.', 'dbvc'); ?></p>
            </div>
            <button
                type="button"
                class="button"
                id="dbvc-cc-mapping-help-open"
                aria-haspopup="dialog"
                aria-controls="dbvc-cc-mapping-help-modal"
            >
                <?php esc_html_e('How to Use Mapper', 'dbvc'); ?>
            </button>
        </div>

        <p id="dbvc-cc-mapping-disabled" class="dbvc-cc-hidden">
            <?php esc_html_e('Mapping catalog bridge is disabled. Enable the mapping catalog bridge flag to use these controls. Media mapping can remain disabled for text-only mapping.', 'dbvc'); ?>
        </p>

        <section class="dbvc-cc-mapping-controls" aria-label="<?php esc_attr_e('Mapping Controls', 'dbvc'); ?>">
            <label for="dbvc-cc-mapping-domain"><?php esc_html_e('Domain', 'dbvc'); ?></label>
            <select id="dbvc-cc-mapping-domain" class="regular-text">
                <option value=""><?php esc_html_e('Select a domain', 'dbvc'); ?></option>
            </select>

            <label for="dbvc-cc-mapping-path"><?php esc_html_e('Path', 'dbvc'); ?></label>
            <input type="text" id="dbvc-cc-mapping-path" class="regular-text" placeholder="home" />

            <label for="dbvc-cc-mapping-object-post-type"><?php esc_html_e('Default Post Type Override', 'dbvc'); ?></label>
            <select id="dbvc-cc-mapping-object-post-type" class="regular-text">
                <option value=""><?php esc_html_e('Auto (Mapping + AI)', 'dbvc'); ?></option>
            </select>

            <button type="button" class="button button-primary" id="dbvc-cc-mapping-load-package"><?php esc_html_e('Load Mapping Package', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-build-catalog"><?php esc_html_e('Build Catalog', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-refresh-catalog"><?php esc_html_e('Refresh Catalog', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-rebuild-domain"><?php esc_html_e('Rebuild Domain Mapping Artifacts', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-load-decisions"><?php esc_html_e('Load Decisions', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-load-handoff"><?php esc_html_e('Preview Dry-Run Handoff', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-generate-dry-run-plan"><?php esc_html_e('Generate Dry-Run Plan', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-run-executor-dry-run"><?php esc_html_e('Run Executor Dry-Run', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-approve-import"><?php esc_html_e('Approve Import', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-run-execute-skeleton"><?php esc_html_e('Run Execute', 'dbvc'); ?></button>
            <button type="button" class="button" id="dbvc-cc-mapping-rollback-run"><?php esc_html_e('Rollback Last Run', 'dbvc'); ?></button>
        </section>

        <p id="dbvc-cc-mapping-status" aria-live="polite"></p>

        <section class="dbvc-cc-mapping-summary" aria-label="<?php esc_attr_e('Mapping Summary', 'dbvc'); ?>">
            <p><strong><?php esc_html_e('Catalog', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-catalog-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Section Candidates', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-sections-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Media Candidates', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-media-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Mapping Decision', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-decision-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Media Decision', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-media-decision-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Handoff', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-handoff-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Default Entity', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-default-entity-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Context', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-phase4-context-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Dry-Run Plan', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-import-plan-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Executor Dry-Run', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-import-executor-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Approval', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-import-approval-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Execute', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-import-execute-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Recovery', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-import-recovery-summary">n/a</span></p>
            <p><strong><?php esc_html_e('Phase 4 Run History', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-run-history-summary">n/a</span></p>
        </section>

        <section class="dbvc-cc-handoff-review" aria-label="<?php esc_attr_e('Phase 4 Handoff Review', 'dbvc'); ?>">
            <div class="dbvc-cc-handoff-review-heading">
                <div>
                    <h3><?php esc_html_e('Handoff Review', 'dbvc'); ?></h3>
                    <p id="dbvc-cc-mapping-handoff-review-summary" class="description"><?php esc_html_e('Preview Dry-Run Handoff to inspect review blockers for the selected domain/path.', 'dbvc'); ?></p>
                </div>
            </div>
            <ul id="dbvc-cc-mapping-handoff-review-list" class="dbvc-cc-handoff-review-list">
                <li><?php esc_html_e('No handoff review loaded yet.', 'dbvc'); ?></li>
            </ul>
        </section>

        <section class="dbvc-cc-import-run-history" aria-label="<?php esc_attr_e('Import Run History', 'dbvc'); ?>">
            <div class="dbvc-cc-import-run-history-heading">
                <div>
                    <h3><?php esc_html_e('Import Run History', 'dbvc'); ?></h3>
                    <p class="description"><?php esc_html_e('Review recent runs, rollback state, and journaled actions for the selected domain/path.', 'dbvc'); ?></p>
                </div>
                <button type="button" class="button" id="dbvc-cc-mapping-refresh-run-history"><?php esc_html_e('Refresh Run History', 'dbvc'); ?></button>
            </div>

            <div class="dbvc-cc-import-run-history-layout">
                <section class="dbvc-cc-import-run-list" aria-label="<?php esc_attr_e('Recent Import Runs', 'dbvc'); ?>">
                    <table class="widefat striped" id="dbvc-cc-mapping-run-history-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Run', 'dbvc'); ?></th>
                                <th><?php esc_html_e('Status', 'dbvc'); ?></th>
                                <th><?php esc_html_e('Created', 'dbvc'); ?></th>
                                <th><?php esc_html_e('Recovery', 'dbvc'); ?></th>
                                <th><?php esc_html_e('Writes', 'dbvc'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="5"><?php esc_html_e('Select a domain/path to review import runs.', 'dbvc'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="dbvc-cc-import-run-detail" aria-label="<?php esc_attr_e('Selected Import Run', 'dbvc'); ?>">
                    <div id="dbvc-cc-mapping-run-detail-empty"><?php esc_html_e('Select a recent run to review journaled actions and recovery details.', 'dbvc'); ?></div>
                    <div id="dbvc-cc-mapping-run-detail-content" class="dbvc-cc-hidden">
                        <div class="dbvc-cc-import-run-detail-heading">
                            <div class="dbvc-cc-import-run-detail-meta">
                                <p><strong><?php esc_html_e('Run', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-run-detail-id">n/a</span></p>
                                <p><strong><?php esc_html_e('Source', 'dbvc'); ?>:</strong> <a id="dbvc-cc-mapping-run-detail-source-url" href="#" target="_blank" rel="noopener noreferrer">n/a</a></p>
                                <p><strong><?php esc_html_e('Status', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-run-detail-status">n/a</span></p>
                                <p><strong><?php esc_html_e('Rollback', 'dbvc'); ?>:</strong> <span id="dbvc-cc-mapping-run-detail-rollback">n/a</span></p>
                            </div>
                            <div class="dbvc-cc-import-run-detail-actions">
                                <button type="button" class="button" id="dbvc-cc-mapping-download-run-report"><?php esc_html_e('Download JSON Report', 'dbvc'); ?></button>
                                <button type="button" class="button" id="dbvc-cc-mapping-rollback-selected-run"><?php esc_html_e('Rollback Selected Run', 'dbvc'); ?></button>
                            </div>
                        </div>

                        <p id="dbvc-cc-mapping-run-detail-summary" class="description"></p>
                        <div id="dbvc-cc-mapping-run-detail-banner" class="dbvc-cc-run-banner dbvc-cc-hidden" aria-live="polite"></div>

                        <section class="dbvc-cc-import-run-filters" aria-label="<?php esc_attr_e('Import Run Action Filters', 'dbvc'); ?>">
                            <label for="dbvc-cc-mapping-run-filter-stage">
                                <?php esc_html_e('Stage', 'dbvc'); ?>
                                <select id="dbvc-cc-mapping-run-filter-stage">
                                    <option value=""><?php esc_html_e('All', 'dbvc'); ?></option>
                                    <option value="entity"><?php esc_html_e('Entity', 'dbvc'); ?></option>
                                    <option value="field"><?php esc_html_e('Field', 'dbvc'); ?></option>
                                    <option value="media"><?php esc_html_e('Media', 'dbvc'); ?></option>
                                </select>
                            </label>
                            <label for="dbvc-cc-mapping-run-filter-execution">
                                <?php esc_html_e('Execution', 'dbvc'); ?>
                                <select id="dbvc-cc-mapping-run-filter-execution">
                                    <option value=""><?php esc_html_e('All', 'dbvc'); ?></option>
                                    <option value="completed"><?php esc_html_e('Completed', 'dbvc'); ?></option>
                                    <option value="failed"><?php esc_html_e('Failed', 'dbvc'); ?></option>
                                    <option value="deferred"><?php esc_html_e('Deferred', 'dbvc'); ?></option>
                                </select>
                            </label>
                            <label for="dbvc-cc-mapping-run-filter-rollback">
                                <?php esc_html_e('Rollback', 'dbvc'); ?>
                                <select id="dbvc-cc-mapping-run-filter-rollback">
                                    <option value=""><?php esc_html_e('All', 'dbvc'); ?></option>
                                    <option value="not_started"><?php esc_html_e('Not Started', 'dbvc'); ?></option>
                                    <option value="completed"><?php esc_html_e('Completed', 'dbvc'); ?></option>
                                    <option value="failed"><?php esc_html_e('Failed', 'dbvc'); ?></option>
                                </select>
                            </label>
                            <label class="dbvc-cc-import-run-filter-checkbox">
                                <input type="checkbox" id="dbvc-cc-mapping-run-filter-failed-only" />
                                <?php esc_html_e('Failed Only', 'dbvc'); ?>
                            </label>
                        </section>

                        <table class="widefat striped" id="dbvc-cc-mapping-run-actions-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Order', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Stage', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Target', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Execution', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Rollback', 'dbvc'); ?></th>
                                    <th><?php esc_html_e('Notes', 'dbvc'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="6"><?php esc_html_e('No journaled actions were found for this run.', 'dbvc'); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <section class="dbvc-cc-import-run-action-detail" aria-label="<?php esc_attr_e('Selected Action Diff', 'dbvc'); ?>">
                            <div id="dbvc-cc-mapping-run-action-detail-empty"><?php esc_html_e('Select a journaled action to inspect before/after state.', 'dbvc'); ?></div>
                            <div id="dbvc-cc-mapping-run-action-detail-content" class="dbvc-cc-hidden">
                                <p id="dbvc-cc-mapping-run-action-detail-summary" class="description"></p>
                                <div class="dbvc-cc-import-run-action-diff-layout">
                                    <section class="dbvc-cc-import-run-action-state" aria-label="<?php esc_attr_e('Before State', 'dbvc'); ?>">
                                        <h4><?php esc_html_e('Before', 'dbvc'); ?></h4>
                                        <pre id="dbvc-cc-mapping-run-action-before-state"></pre>
                                    </section>
                                    <section class="dbvc-cc-import-run-action-state" aria-label="<?php esc_attr_e('After State', 'dbvc'); ?>">
                                        <h4><?php esc_html_e('After', 'dbvc'); ?></h4>
                                        <pre id="dbvc-cc-mapping-run-action-after-state"></pre>
                                    </section>
                                </div>
                            </div>
                        </section>
                    </div>
                </section>
            </div>
        </section>

        <div class="dbvc-cc-mapping-grid">
            <section class="dbvc-cc-mapping-panel" aria-label="<?php esc_attr_e('Section Field Mapping', 'dbvc'); ?>">
                <h3><?php esc_html_e('Section Field Mapping', 'dbvc'); ?></h3>
                <table class="widefat striped" id="dbvc-cc-mapping-sections-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Section', 'dbvc'); ?></th>
                            <th><?php esc_html_e('Archetype', 'dbvc'); ?></th>
                            <th><?php esc_html_e('Suggested Target', 'dbvc'); ?></th>
                            <th><?php esc_html_e('Override Target', 'dbvc'); ?></th>
                            <th><?php esc_html_e('Ignore', 'dbvc'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="5"><?php esc_html_e('Load mapping package to populate section candidates.', 'dbvc'); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="dbvc-cc-mapping-actions">
                    <label for="dbvc-cc-mapping-decision-status"><?php esc_html_e('Decision Status', 'dbvc'); ?></label>
                    <select id="dbvc-cc-mapping-decision-status">
                        <option value="pending"><?php esc_html_e('Pending', 'dbvc'); ?></option>
                        <option value="approved"><?php esc_html_e('Approved', 'dbvc'); ?></option>
                        <option value="rejected"><?php esc_html_e('Rejected', 'dbvc'); ?></option>
                    </select>
                    <button type="button" class="button button-primary" id="dbvc-cc-mapping-save-decision"><?php esc_html_e('Save Mapping Decision', 'dbvc'); ?></button>
                </div>
            </section>

            <section class="dbvc-cc-mapping-panel" aria-label="<?php esc_attr_e('Media Mapping', 'dbvc'); ?>">
                <h3><?php esc_html_e('Media Mapping', 'dbvc'); ?></h3>
                <table class="widefat striped" id="dbvc-cc-mapping-media-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Preview', 'dbvc'); ?></th>
                            <th><?php esc_html_e('Media', 'dbvc'); ?></th>
                            <th><?php esc_html_e('Suggested Target', 'dbvc'); ?></th>
                            <th><?php esc_html_e('Override Target', 'dbvc'); ?></th>
                            <th><?php esc_html_e('Ignore', 'dbvc'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="5"><?php esc_html_e('Load mapping package to populate media candidates.', 'dbvc'); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="dbvc-cc-mapping-actions">
                    <label for="dbvc-cc-mapping-media-decision-status"><?php esc_html_e('Decision Status', 'dbvc'); ?></label>
                    <select id="dbvc-cc-mapping-media-decision-status">
                        <option value="pending"><?php esc_html_e('Pending', 'dbvc'); ?></option>
                        <option value="approved"><?php esc_html_e('Approved', 'dbvc'); ?></option>
                        <option value="rejected"><?php esc_html_e('Rejected', 'dbvc'); ?></option>
                    </select>
                    <button type="button" class="button button-primary" id="dbvc-cc-mapping-save-media-decision"><?php esc_html_e('Save Media Decision', 'dbvc'); ?></button>
                </div>
            </section>
        </div>

        <details class="dbvc-cc-mapping-debug">
            <summary><?php esc_html_e('Debug Payloads', 'dbvc'); ?></summary>
            <section aria-label="<?php esc_attr_e('Catalog Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Catalog Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-catalog-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Section Candidates Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Section Candidates Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-sections-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Media Candidates Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Media Candidates Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-media-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Decision Payloads', 'dbvc'); ?>">
                <h4><?php esc_html_e('Decision Payloads', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-decisions-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Phase 4 Handoff Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Phase 4 Handoff Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-handoff-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Phase 4 Dry-Run Plan Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Phase 4 Dry-Run Plan Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-import-plan-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Phase 4 Executor Dry-Run Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Phase 4 Executor Dry-Run Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-import-executor-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Phase 4 Approval Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Phase 4 Approval Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-import-approval-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Phase 4 Execute Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Phase 4 Execute Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-import-execute-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Phase 4 Recovery Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Phase 4 Recovery Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-import-recovery-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Phase 4 Run History Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Phase 4 Run History Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-run-history-json"></pre>
            </section>
            <section aria-label="<?php esc_attr_e('Phase 4 Run Detail Payload', 'dbvc'); ?>">
                <h4><?php esc_html_e('Phase 4 Run Detail Payload', 'dbvc'); ?></h4>
                <pre id="dbvc-cc-mapping-run-detail-json"></pre>
            </section>
        </details>
    </section>

    <div
        id="dbvc-cc-mapping-help-modal"
        class="dbvc-cc-modal dbvc-cc-hidden"
        hidden
        aria-hidden="true"
        style="display:none;pointer-events:none;visibility:hidden;opacity:0;"
        role="dialog"
        aria-modal="true"
        aria-labelledby="dbvc-cc-mapping-help-title"
        aria-describedby="dbvc-cc-mapping-help-description"
    >
        <div
            class="dbvc-cc-modal-backdrop"
            data-dbvc-cc-modal-close="1"
            onclick="this.parentElement.setAttribute('hidden','hidden'); this.parentElement.classList.add('dbvc-cc-hidden'); document.body.classList.remove('dbvc-cc-modal-open');"
        ></div>
        <div class="dbvc-cc-modal-content" id="dbvc-cc-mapping-help-dialog" role="document" tabindex="-1">
            <div class="dbvc-cc-modal-header">
                <div>
                    <h3 id="dbvc-cc-mapping-help-title"><?php esc_html_e('How to Use the Content Mapping Workbench', 'dbvc'); ?></h3>
                    <p id="dbvc-cc-mapping-help-description" class="description">
                        <?php esc_html_e('Use the mapper to turn a crawled domain/path into a reviewed handoff package before import execution.', 'dbvc'); ?>
                    </p>
                </div>
                <button
                    type="button"
                    id="dbvc-cc-mapping-help-close"
                    class="button-link dbvc-cc-modal-close"
                    data-dbvc-cc-modal-close="1"
                    onclick="var modal=this.closest('.dbvc-cc-modal'); if(modal){modal.setAttribute('hidden','hidden'); modal.classList.add('dbvc-cc-hidden');} document.body.classList.remove('dbvc-cc-modal-open'); return false;"
                    aria-label="<?php esc_attr_e('Close mapper help', 'dbvc'); ?>"
                >
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="dbvc-cc-modal-body">
                <section class="dbvc-cc-modal-section" aria-label="<?php esc_attr_e('Mapper workflow steps', 'dbvc'); ?>">
                    <h4><?php esc_html_e('Recommended Workflow', 'dbvc'); ?></h4>
                    <ol class="dbvc-cc-help-step-list">
                        <li>
                            <strong><?php esc_html_e('Choose the crawl scope.', 'dbvc'); ?></strong>
                            <?php esc_html_e('Select the domain and path you want to bridge. Domain lists roll up all crawls saved for that domain, and path defaults to "home" when blank.', 'dbvc'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Load the mapping package.', 'dbvc'); ?></strong>
                            <?php esc_html_e('Use "Load Mapping Package" for the normal first pass. It builds or reuses the target field catalog, loads section candidates, loads media candidates, restores saved decisions, and refreshes the Phase 4 dry-run preview.', 'dbvc'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Review object and section targets.', 'dbvc'); ?></strong>
                            <?php esc_html_e('Use "Default Post Type Override" only when the auto-selected entity type needs a manual correction. For each section row, keep the suggested target, type an override target, or mark the section ignored. Save the section decision set once the rows are correct.', 'dbvc'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Review media targets.', 'dbvc'); ?></strong>
                            <?php esc_html_e('Inspect previews, roles, and source URLs. Keep the suggested media target, type an override, or ignore media that should not bridge into the import payload. Save media decisions separately.', 'dbvc'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Validate the handoff chain.', 'dbvc'); ?></strong>
                            <?php esc_html_e('Use the Phase 4 buttons in order when you need a deeper check: preview the dry-run handoff, generate the dry-run plan, run the executor dry-run, approve the import, then run execute. The Handoff Review panel lists the exact review blockers for the selected domain/path. Review the Import Run History panel to inspect recent runs and journaled actions for the selected domain/path. If a run needs to be reverted, use either "Rollback Last Run" for the latest execute result or "Rollback Selected Run" inside the run history detail view. The summary cards and debug payloads show blocking issues, approval status, executed entity writes, executed field writes, executed media writes, rollback state, and any media work that is still blocked by policy or unsupported target shape.', 'dbvc'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Use rebuild controls only when the crawl changed.', 'dbvc'); ?></strong>
                            <?php esc_html_e('Build or refresh the catalog when DBVC fields changed. Rebuild the domain mapping artifacts when newer crawls or newer AI passes changed what should be available for the selected domain.', 'dbvc'); ?>
                        </li>
                    </ol>
                </section>

                <section class="dbvc-cc-modal-section" aria-label="<?php esc_attr_e('Current mapper behavior', 'dbvc'); ?>">
                    <h4><?php esc_html_e('What the Mapper Does Today', 'dbvc'); ?></h4>
                    <ul class="dbvc-cc-help-bullet-list">
                        <li><?php esc_html_e('Aggregates each domain from the full collection of saved crawls, not just the first crawl that introduced the domain.', 'dbvc'); ?></li>
                        <li><?php esc_html_e('Refreshes queue data automatically after warning-badge AI reruns finish, including chunked reruns for larger domains.', 'dbvc'); ?></li>
                        <li><?php esc_html_e('Supports manual override, manual ignore, and deterministic fallback on both section mappings and media mappings.', 'dbvc'); ?></li>
                        <li><?php esc_html_e('Builds a guarded Phase 4 package that can resolve update-vs-create decisions, require explicit approval before execute, perform post/CPT upserts, apply mapped field values, ingest/reuse mapped media attachments, and journal each write so the latest run can be rolled back.', 'dbvc'); ?></li>
                    </ul>
                </section>

                <section class="dbvc-cc-modal-section" aria-label="<?php esc_attr_e('Mapper features still in progress', 'dbvc'); ?>">
                    <h4><?php esc_html_e('Phase 4 Execution Items Still in Progress', 'dbvc'); ?></h4>
                    <ul class="dbvc-cc-help-bullet-list">
                        <li>
                            <span class="dbvc-cc-chip dbvc-cc-chip-in-progress"><?php esc_html_e('in-progress', 'dbvc'); ?></span>
                            <?php esc_html_e('Media execution now handles featured images, attachment-ID targets, gallery/list attachment targets, and remote URL/embed targets with rollback support. Nested repeater/flexible media targets and broader non-post media target families are still deferred.', 'dbvc'); ?>
                        </li>
                        <li>
                            <span class="dbvc-cc-chip dbvc-cc-chip-in-progress"><?php esc_html_e('in-progress', 'dbvc'); ?></span>
                            <?php esc_html_e('Execute now requires preflight approval, journals rollback data, automatically rolls back journaled entity/field/media writes after execution failures, and exposes recent run history, per-action before/after diffs, and downloadable JSON recovery reports in the mapper. Richer cross-run compare workflows are still in progress.', 'dbvc'); ?>
                        </li>
                        <li>
                            <span class="dbvc-cc-chip dbvc-cc-chip-in-progress"><?php esc_html_e('in-progress', 'dbvc'); ?></span>
                            <?php esc_html_e('A richer interactive mapping canvas is not wired yet. The current mapper is table-driven and review-first.', 'dbvc'); ?>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</div>
