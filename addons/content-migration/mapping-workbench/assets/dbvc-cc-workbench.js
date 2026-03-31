jQuery(function($) {
    const config = window.dbvc_cc_workbench_object || {};
    const restBase = String(config.rest_base || '');
    const nonce = String(config.nonce || '');
    const defaults = config.defaults || {};
    const capabilities = config.capabilities || {};
    const prefill = config.prefill || {};
    const dbvc_cc_post_type_options = Array.isArray(config.post_types) ? config.post_types : [];

    const dbvc_cc_mapping_catalog_bridge_enabled = !!capabilities.mapping_catalog_bridge;
    const dbvc_cc_media_mapping_bridge_enabled = !!capabilities.media_mapping_bridge;
    const dbvc_cc_mapping_bridge_enabled = dbvc_cc_mapping_catalog_bridge_enabled;
    const dbvc_cc_import_executor_enabled = typeof capabilities.import_executor === 'boolean'
        ? !!capabilities.import_executor
        : dbvc_cc_mapping_catalog_bridge_enabled;

    let selected = null;
    let queueItems = [];
    const dbvc_cc_domain_labels_by_key = {};
    const dbvc_cc_domain_ai_health_by_key = {};
    const mappingState = {
        catalog: null,
        reviewQueueFieldContext: null,
        sectionCandidates: null,
        sectionCandidatesStatus: null,
        mediaCandidates: null,
        mediaCandidatesStatus: null,
        mappingDecision: null,
        mediaDecision: null,
        handoffPayload: null,
        importPlanDryRun: null,
        importExecutorDryRun: null,
        importPreflightApproval: null,
        importExecuteSkeleton: null,
        importRecovery: null,
        importRunHistory: null,
        importRunDetail: null,
        importSelectedRunId: 0,
        importSelectedActionId: 0,
        targetRefIndex: null,
        targetRefIndexFingerprint: '',
        importRunActionFilters: {
            stage: '',
            execution: '',
            rollback: '',
            failedOnly: false,
        },
    };

    const $domain = $('#dbvc-cc-workbench-domain');
    const $domainAiWarning = $('#dbvc-cc-workbench-domain-ai-warning');
    const $limit = $('#dbvc-cc-workbench-limit');
    const $minConfidence = $('#dbvc-cc-workbench-min-confidence');
    const $includeDecided = $('#dbvc-cc-workbench-include-decided');
    const $status = $('#dbvc-cc-workbench-status');
    const $fieldContextNote = $('#dbvc-cc-workbench-field-context-note');
    const $aiRefreshNote = $('#dbvc-cc-workbench-ai-refresh-note');
    const $tbody = $('#dbvc-cc-workbench-table tbody');
    const $empty = $('#dbvc-cc-workbench-empty');
    const $detail = $('#dbvc-cc-workbench-detail-content');
    const $node = $('#dbvc-cc-workbench-node');
    const $sourceUrl = $('#dbvc-cc-workbench-source-url');
    const $json = $('#dbvc-cc-workbench-json');
    const $notes = $('#dbvc-cc-workbench-notes');

    const $mappingDisabled = $('#dbvc-cc-mapping-disabled');
    const $mappingDomain = $('#dbvc-cc-mapping-domain');
    const $mappingPath = $('#dbvc-cc-mapping-path');
    const $mappingObjectPostType = $('#dbvc-cc-mapping-object-post-type');
    const $mappingStatus = $('#dbvc-cc-mapping-status');
    const $mappingCatalogSummary = $('#dbvc-cc-mapping-catalog-summary');
    const $mappingFieldContextSummary = $('#dbvc-cc-mapping-field-context-summary');
    const $mappingSectionsSummary = $('#dbvc-cc-mapping-sections-summary');
    const $mappingMediaSummary = $('#dbvc-cc-mapping-media-summary');
    const $mappingDecisionSummary = $('#dbvc-cc-mapping-decision-summary');
    const $mappingMediaDecisionSummary = $('#dbvc-cc-mapping-media-decision-summary');
    const $mappingHandoffSummary = $('#dbvc-cc-mapping-handoff-summary');
    const $mappingDefaultEntitySummary = $('#dbvc-cc-mapping-default-entity-summary');
    const $mappingPhase4ContextSummary = $('#dbvc-cc-mapping-phase4-context-summary');
    const $mappingImportPlanSummary = $('#dbvc-cc-mapping-import-plan-summary');
    const $mappingImportExecutorSummary = $('#dbvc-cc-mapping-import-executor-summary');
    const $mappingImportApprovalSummary = $('#dbvc-cc-mapping-import-approval-summary');
    const $mappingImportExecuteSummary = $('#dbvc-cc-mapping-import-execute-summary');
    const $mappingImportRecoverySummary = $('#dbvc-cc-mapping-import-recovery-summary');
    const $mappingRunHistorySummary = $('#dbvc-cc-mapping-run-history-summary');
    const $mappingHandoffReviewSummary = $('#dbvc-cc-mapping-handoff-review-summary');
    const $mappingHandoffReviewList = $('#dbvc-cc-mapping-handoff-review-list');
    const $mappingSectionsTableBody = $('#dbvc-cc-mapping-sections-table tbody');
    const $mappingMediaTableBody = $('#dbvc-cc-mapping-media-table tbody');
    const $mappingRunHistoryTableBody = $('#dbvc-cc-mapping-run-history-table tbody');
    const $mappingRunDetailEmpty = $('#dbvc-cc-mapping-run-detail-empty');
    const $mappingRunDetailContent = $('#dbvc-cc-mapping-run-detail-content');
    const $mappingRunDetailId = $('#dbvc-cc-mapping-run-detail-id');
    const $mappingRunDetailSourceUrl = $('#dbvc-cc-mapping-run-detail-source-url');
    const $mappingRunDetailStatus = $('#dbvc-cc-mapping-run-detail-status');
    const $mappingRunDetailRollback = $('#dbvc-cc-mapping-run-detail-rollback');
    const $mappingRunDetailSummary = $('#dbvc-cc-mapping-run-detail-summary');
    const $mappingRunDetailBanner = $('#dbvc-cc-mapping-run-detail-banner');
    const $mappingRunActionsTableBody = $('#dbvc-cc-mapping-run-actions-table tbody');
    const $mappingRunFilterStage = $('#dbvc-cc-mapping-run-filter-stage');
    const $mappingRunFilterExecution = $('#dbvc-cc-mapping-run-filter-execution');
    const $mappingRunFilterRollback = $('#dbvc-cc-mapping-run-filter-rollback');
    const $mappingRunFilterFailedOnly = $('#dbvc-cc-mapping-run-filter-failed-only');
    const $mappingRunActionDetailEmpty = $('#dbvc-cc-mapping-run-action-detail-empty');
    const $mappingRunActionDetailContent = $('#dbvc-cc-mapping-run-action-detail-content');
    const $mappingRunActionDetailSummary = $('#dbvc-cc-mapping-run-action-detail-summary');
    const $mappingRunActionBeforeState = $('#dbvc-cc-mapping-run-action-before-state');
    const $mappingRunActionAfterState = $('#dbvc-cc-mapping-run-action-after-state');
    const $mappingCatalogJson = $('#dbvc-cc-mapping-catalog-json');
    const $mappingSectionsJson = $('#dbvc-cc-mapping-sections-json');
    const $mappingMediaJson = $('#dbvc-cc-mapping-media-json');
    const $mappingDecisionsJson = $('#dbvc-cc-mapping-decisions-json');
    const $mappingHandoffJson = $('#dbvc-cc-mapping-handoff-json');
    const $mappingImportPlanJson = $('#dbvc-cc-mapping-import-plan-json');
    const $mappingImportExecutorJson = $('#dbvc-cc-mapping-import-executor-json');
    const $mappingImportApprovalJson = $('#dbvc-cc-mapping-import-approval-json');
    const $mappingImportExecuteJson = $('#dbvc-cc-mapping-import-execute-json');
    const $mappingImportRecoveryJson = $('#dbvc-cc-mapping-import-recovery-json');
    const $mappingRunHistoryJson = $('#dbvc-cc-mapping-run-history-json');
    const $mappingRunDetailJson = $('#dbvc-cc-mapping-run-detail-json');
    const $mappingDecisionStatus = $('#dbvc-cc-mapping-decision-status');
    const $mappingMediaDecisionStatus = $('#dbvc-cc-mapping-media-decision-status');
    const $mappingHelpModal = $('#dbvc-cc-mapping-help-modal');
    const $mappingHelpDialog = $('#dbvc-cc-mapping-help-dialog');

    let dbvc_cc_last_focused_element = null;

    $limit.val(defaults.limit || 50);
    $minConfidence.val(defaults.min_confidence || 0.75);
    $includeDecided.prop('checked', !!defaults.include_decided);

    function setStatus(message, isError = false) {
        $status.text(message || '');
        $status.css('color', isError ? '#d63638' : '#1d2327');
    }

    function dbvc_cc_set_ai_refresh_note(message = '', isError = false) {
        const note = String(message || '').trim();
        if (!note) {
            $aiRefreshNote.addClass('dbvc-cc-hidden').text('');
            $aiRefreshNote.css('color', '#50575e');
            return;
        }

        $aiRefreshNote.removeClass('dbvc-cc-hidden').text(note);
        $aiRefreshNote.css('color', isError ? '#d63638' : '#50575e');
    }

    function dbvc_cc_set_field_context_note(message = '', isError = false) {
        const note = String(message || '').trim();
        if (!note) {
            $fieldContextNote.addClass('dbvc-cc-hidden').text('');
            $fieldContextNote.css('color', '#8a4b00');
            return;
        }

        $fieldContextNote.removeClass('dbvc-cc-hidden').text(note);
        $fieldContextNote.css('color', isError ? '#d63638' : '#8a4b00');
    }

    function dbvc_cc_set_mapping_status(message, isError = false) {
        $mappingStatus.text(message || '');
        $mappingStatus.css('color', isError ? '#d63638' : '#1d2327');
    }

    function dbvc_cc_open_mapping_help_modal() {
        if ($mappingHelpModal.length === 0) {
            return;
        }

        dbvc_cc_last_focused_element = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;

        $mappingHelpModal.removeAttr('hidden');
        $mappingHelpModal.attr('aria-hidden', 'false');
        $mappingHelpModal.removeClass('dbvc-cc-hidden');
        $mappingHelpModal.css({
            display: 'flex',
            pointerEvents: 'auto',
            visibility: 'visible',
            opacity: '1',
        });
        $('body').addClass('dbvc-cc-modal-open');
        window.setTimeout(function() {
            if ($mappingHelpDialog.length > 0) {
                $mappingHelpDialog.trigger('focus');
            }
        }, 0);
    }

    function dbvc_cc_close_mapping_help_modal() {
        if ($mappingHelpModal.length === 0) {
            return;
        }

        $mappingHelpModal.attr('hidden', 'hidden');
        $mappingHelpModal.attr('aria-hidden', 'true');
        $mappingHelpModal.addClass('dbvc-cc-hidden');
        $mappingHelpModal.css({
            display: 'none',
            pointerEvents: 'none',
            visibility: 'hidden',
            opacity: '0',
        });
        $('body').removeClass('dbvc-cc-modal-open');

        if (dbvc_cc_last_focused_element && typeof dbvc_cc_last_focused_element.focus === 'function') {
            dbvc_cc_last_focused_element.focus();
        }

        dbvc_cc_last_focused_element = null;
    }

    function dbvc_cc_reset_mapping_ui_state() {
        if ($mappingHelpModal.length > 0) {
            $mappingHelpModal.attr('hidden', 'hidden');
            $mappingHelpModal.attr('aria-hidden', 'true');
            $mappingHelpModal.addClass('dbvc-cc-hidden');
            $mappingHelpModal.css({
                display: 'none',
                pointerEvents: 'none',
                visibility: 'hidden',
                opacity: '0',
            });
        }

        $('body').removeClass('dbvc-cc-modal-open');
    }

    function dbvc_cc_probe_mapping_interactivity() {
        const controlSelectors = [
            '#dbvc-cc-mapping-domain',
            '#dbvc-cc-mapping-path',
            '#dbvc-cc-mapping-load-package',
        ];
        let overlayDetected = false;
        let overlayLabel = '';

        controlSelectors.forEach((selector) => {
            const element = document.querySelector(selector);
            if (!element || overlayDetected) {
                return;
            }

            const rect = element.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) {
                return;
            }

            const probeX = rect.left + Math.min(rect.width / 2, 24);
            const probeY = rect.top + Math.min(rect.height / 2, 18);
            const topElement = document.elementFromPoint(probeX, probeY);
            if (!topElement || topElement === element || element.contains(topElement)) {
                return;
            }

            const $topElement = $(topElement);
            if ($topElement.closest('#dbvc-cc-mapping-help-modal').length > 0) {
                dbvc_cc_reset_mapping_ui_state();
                return;
            }

            overlayDetected = true;
            overlayLabel = topElement.id
                ? `#${topElement.id}`
                : topElement.className
                    ? `.${String(topElement.className).trim().replace(/\s+/g, '.')}`
                    : topElement.tagName.toLowerCase();
        });

        if (overlayDetected) {
            dbvc_cc_set_mapping_status(`Mapper controls are currently blocked by ${overlayLabel}.`, true);
        }
    }

    function dbvc_cc_normalize_run_id(value) {
        const parsed = Number(value);
        if (!Number.isFinite(parsed) || parsed <= 0) {
            return 0;
        }

        return Math.trunc(parsed);
    }

    function dbvc_cc_escape_html(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function escapeHtml(value) {
        return dbvc_cc_escape_html(value);
    }

    function dbvc_cc_humanize_identifier(value) {
        const normalizedValue = String(value || '').trim().replace(/[_-]+/g, ' ');
        if (!normalizedValue) {
            return '';
        }

        return normalizedValue.replace(/\b([a-z])/g, function(match, letter) {
            return String(letter || '').toUpperCase();
        });
    }

    function dbvc_cc_get_queue_field_context_meta() {
        return mappingState.reviewQueueFieldContext && typeof mappingState.reviewQueueFieldContext === 'object'
            ? mappingState.reviewQueueFieldContext
            : null;
    }

    function dbvc_cc_get_catalog_field_context_meta() {
        const catalog = mappingState.catalog && typeof mappingState.catalog === 'object'
            ? mappingState.catalog
            : null;
        const acfCatalog = catalog && catalog.acf_catalog && typeof catalog.acf_catalog === 'object'
            ? catalog.acf_catalog
            : null;
        const fieldContext = acfCatalog && acfCatalog.field_context && typeof acfCatalog.field_context === 'object'
            ? acfCatalog.field_context
            : null;

        return fieldContext;
    }

    function dbvc_cc_describe_field_context_meta(meta) {
        if (!meta || typeof meta !== 'object') {
            return 'n/a';
        }

        const integrationMode = String(meta.integration_mode || (meta.consumer_policy && meta.consumer_policy.integration_mode) || 'auto');
        if (integrationMode === 'off') {
            return 'off | deterministic-only';
        }

        const status = String(meta.status || (meta.catalog_meta && meta.catalog_meta.status) || 'missing');
        const available = !!meta.available;
        const degraded = !!meta.degraded || !!(meta.diagnostics && meta.diagnostics.degraded);
        const blocked = !!meta.blocked || !!(meta.diagnostics && meta.diagnostics.blocked);
        const warnings = Array.isArray(meta.warnings)
            ? meta.warnings
            : (meta.diagnostics && Array.isArray(meta.diagnostics.warnings) ? meta.diagnostics.warnings : []);
        const contractVersion = Number(meta.contract_version || (meta.provider && meta.provider.contract_version) || 0);
        const sourceHash = String(meta.source_hash || (meta.catalog_meta && meta.catalog_meta.source_hash) || '');
        const transport = String(meta.transport || 'local');
        const summaryParts = [
            integrationMode,
            transport,
            available ? status : 'unavailable',
        ];

        if (blocked) {
            summaryParts.push('blocked');
        } else if (degraded) {
            summaryParts.push('degraded');
        }

        if (warnings.length > 0) {
            summaryParts.push(`warnings:${warnings.length}`);
        }

        if (contractVersion > 0) {
            summaryParts.push(`contract:v${contractVersion}`);
        }

        if (sourceHash) {
            summaryParts.push(`src:${sourceHash.substring(0, 12)}`);
        }

        return summaryParts.join(' | ');
    }

    function dbvc_cc_build_field_context_note(meta) {
        if (!meta || typeof meta !== 'object') {
            return { message: '', isError: false };
        }

        const integrationMode = String(meta.integration_mode || (meta.consumer_policy && meta.consumer_policy.integration_mode) || 'auto');
        if (integrationMode === 'off') {
            return { message: '', isError: false };
        }

        const diagnostics = meta.diagnostics && typeof meta.diagnostics === 'object'
            ? meta.diagnostics
            : {};
        const warnings = Array.isArray(meta.warnings)
            ? meta.warnings
            : (Array.isArray(diagnostics.warnings) ? diagnostics.warnings : []);
        const warningMessages = warnings
            .map((warning) => warning && warning.message ? String(warning.message).trim() : '')
            .filter((message) => message !== '');
        const summary = dbvc_cc_describe_field_context_meta(meta);

        if (!!diagnostics.blocked) {
            const detail = warningMessages.length > 0 ? warningMessages[0] : 'Operator policy is blocking field-context hints for mapping.';
            return {
                message: `Field context blocked. ${detail} (${summary})`,
                isError: true,
            };
        }

        if (!meta.available) {
            const detail = warningMessages.length > 0 ? warningMessages[0] : 'The Vertical provider is unavailable in this runtime.';
            return {
                message: `Field context unavailable. ${detail} (${summary})`,
                isError: true,
            };
        }

        if (!!diagnostics.degraded || warningMessages.length > 0) {
            const detail = warningMessages.length > 0 ? warningMessages[0] : 'Field-context coverage is degraded, so deterministic mapping will fall back to narrower signals.';
            return {
                message: `Field context degraded. ${detail} (${summary})`,
                isError: false,
            };
        }

        return { message: '', isError: false };
    }

    function dbvc_cc_build_html_attributes(attributes = {}) {
        return Object.keys(attributes).reduce((parts, key) => {
            const value = attributes[key];
            if (value === null || value === undefined || value === false) {
                return parts;
            }

            if (value === true) {
                parts.push(key);
                return parts;
            }

            parts.push(`${key}="${dbvc_cc_escape_html(value)}"`);
            return parts;
        }, []).join(' ');
    }

    function dbvc_cc_get_catalog_post_type_label(slug) {
        const normalizedSlug = String(slug || '').trim();
        if (!normalizedSlug) {
            return '';
        }

        const catalog = mappingState.catalog && typeof mappingState.catalog === 'object'
            ? mappingState.catalog
            : null;
        const cptCatalog = catalog && catalog.cpt_catalog && typeof catalog.cpt_catalog === 'object'
            ? catalog.cpt_catalog
            : {};
        const catalogEntry = cptCatalog[normalizedSlug] && typeof cptCatalog[normalizedSlug] === 'object'
            ? cptCatalog[normalizedSlug]
            : null;

        if (catalogEntry && catalogEntry.label) {
            return String(catalogEntry.label);
        }

        return dbvc_cc_humanize_identifier(normalizedSlug);
    }

    function dbvc_cc_build_fallback_target_ref_meta(targetRef) {
        const normalizedTargetRef = String(targetRef || '').trim();
        if (!normalizedTargetRef) {
            return {
                targetRef: '',
                source: '',
                displayLabel: 'Unmapped',
                detailLabel: '',
                groupKey: '',
                groupTitle: '',
                fieldKey: '',
                fieldName: '',
                fieldLabel: '',
                objectType: '',
                subtype: '',
                description: '',
            };
        }

        const parts = normalizedTargetRef.split(':');
        const source = String(parts[0] || '').trim();

        if (source === 'acf') {
            const groupKey = String(parts[1] || '').trim();
            const fieldKey = String(parts[2] || '').trim();
            const groupTitle = dbvc_cc_humanize_identifier(groupKey);
            const fieldLabel = dbvc_cc_humanize_identifier(fieldKey);
            return {
                targetRef: normalizedTargetRef,
                source: 'acf',
                displayLabel: `ACF: ${groupTitle || groupKey || 'Group'} -> ${fieldLabel || fieldKey || 'Field'}`,
                detailLabel: `key: ${fieldKey || 'n/a'} | group: ${groupKey || 'n/a'} | ref: ${normalizedTargetRef}`,
                groupKey,
                groupTitle,
                fieldKey,
                fieldName: '',
                fieldLabel,
                objectType: '',
                subtype: '',
                description: '',
            };
        }

        if (source === 'meta') {
            const objectType = String(parts[1] || '').trim();
            const subtype = String(parts[2] || '').trim();
            const fieldKey = String(parts[3] || '').trim();
            const subtypeLabel = dbvc_cc_get_catalog_post_type_label(subtype || objectType);
            return {
                targetRef: normalizedTargetRef,
                source: 'meta',
                displayLabel: `Meta: ${subtypeLabel || dbvc_cc_humanize_identifier(subtype || objectType) || 'Field'} -> ${dbvc_cc_humanize_identifier(fieldKey) || fieldKey || 'Field'}`,
                detailLabel: `meta key: ${fieldKey || 'n/a'} | ref: ${normalizedTargetRef}`,
                groupKey: '',
                groupTitle: '',
                fieldKey,
                fieldName: '',
                fieldLabel: dbvc_cc_humanize_identifier(fieldKey),
                objectType,
                subtype,
                description: '',
            };
        }

        if (source === 'core') {
            const fieldKey = String(parts[1] || '').trim();
            const coreLabelMap = {
                post_title: 'Core: Post Title',
                post_content: 'Core: Post Content',
                post_excerpt: 'Core: Post Excerpt',
                post_name: 'Core: Slug',
                menu_order: 'Core: Menu Order',
                featured_image: 'Core: Featured Image',
            };
            return {
                targetRef: normalizedTargetRef,
                source: 'core',
                displayLabel: coreLabelMap[fieldKey] || `Core: ${dbvc_cc_humanize_identifier(fieldKey) || fieldKey || 'Field'}`,
                detailLabel: `field: ${fieldKey || 'n/a'} | ref: ${normalizedTargetRef}`,
                groupKey: '',
                groupTitle: '',
                fieldKey,
                fieldName: '',
                fieldLabel: dbvc_cc_humanize_identifier(fieldKey),
                objectType: '',
                subtype: '',
                description: '',
            };
        }

        return {
            targetRef: normalizedTargetRef,
            source,
            displayLabel: normalizedTargetRef,
            detailLabel: `ref: ${normalizedTargetRef}`,
            groupKey: '',
            groupTitle: '',
            fieldKey: '',
            fieldName: '',
            fieldLabel: '',
            objectType: '',
            subtype: '',
            description: '',
        };
    }

    function dbvc_cc_build_target_ref_index() {
        const index = {};
        const catalog = mappingState.catalog && typeof mappingState.catalog === 'object'
            ? mappingState.catalog
            : null;

        [
            ['core:post_title', 'Core: Post Title', 'post_title'],
            ['core:post_content', 'Core: Post Content', 'post_content'],
            ['core:post_excerpt', 'Core: Post Excerpt', 'post_excerpt'],
            ['core:post_name', 'Core: Slug', 'post_name'],
            ['core:menu_order', 'Core: Menu Order', 'menu_order'],
            ['core:featured_image', 'Core: Featured Image', 'featured_image'],
        ].forEach(([targetRef, displayLabel, fieldKey]) => {
            index[targetRef] = {
                targetRef,
                source: 'core',
                displayLabel,
                detailLabel: `field: ${fieldKey} | ref: ${targetRef}`,
                groupKey: '',
                groupTitle: '',
                fieldKey,
                fieldName: '',
                fieldLabel: dbvc_cc_humanize_identifier(fieldKey),
                objectType: '',
                subtype: '',
                description: '',
            };
        });

        const metaCatalog = catalog && catalog.meta_catalog && typeof catalog.meta_catalog === 'object'
            ? catalog.meta_catalog
            : {};
        Object.keys(metaCatalog).forEach((objectType) => {
            const subtypes = metaCatalog[objectType];
            if (!subtypes || typeof subtypes !== 'object') {
                return;
            }

            Object.keys(subtypes).forEach((subtype) => {
                const entries = subtypes[subtype];
                if (!entries || typeof entries !== 'object') {
                    return;
                }

                const subtypeLabel = dbvc_cc_get_catalog_post_type_label(subtype || objectType);
                Object.keys(entries).forEach((fieldKey) => {
                    const entry = entries[fieldKey] && typeof entries[fieldKey] === 'object'
                        ? entries[fieldKey]
                        : {};
                    const targetRef = `meta:${objectType}:${subtype}:${fieldKey}`;
                    const fieldLabel = dbvc_cc_humanize_identifier(fieldKey) || fieldKey;
                    const description = String(entry.description || '').trim();
                    index[targetRef] = {
                        targetRef,
                        source: 'meta',
                        displayLabel: `Meta: ${subtypeLabel || dbvc_cc_humanize_identifier(subtype || objectType) || 'Field'} -> ${fieldLabel}`,
                        detailLabel: [
                            `meta key: ${fieldKey}`,
                            description ? `description: ${description}` : '',
                            `ref: ${targetRef}`,
                        ].filter(Boolean).join(' | '),
                        groupKey: '',
                        groupTitle: '',
                        fieldKey,
                        fieldName: '',
                        fieldLabel,
                        objectType,
                        subtype,
                        description,
                    };
                });
            });
        });

        const acfGroups = catalog && catalog.acf_catalog && catalog.acf_catalog.groups && typeof catalog.acf_catalog.groups === 'object'
            ? catalog.acf_catalog.groups
            : {};
        Object.keys(acfGroups).forEach((groupKey) => {
            const groupEntry = acfGroups[groupKey];
            if (!groupEntry || typeof groupEntry !== 'object') {
                return;
            }

            const groupTitle = String(groupEntry.title || '').trim() || dbvc_cc_humanize_identifier(groupKey) || groupKey;
            const fields = groupEntry.fields && typeof groupEntry.fields === 'object'
                ? groupEntry.fields
                : {};
            Object.keys(fields).forEach((fieldKey) => {
                const fieldEntry = fields[fieldKey];
                if (!fieldEntry || typeof fieldEntry !== 'object') {
                    return;
                }

                const fieldName = String(fieldEntry.name || '').trim();
                const fieldLabel = String(fieldEntry.label || '').trim()
                    || dbvc_cc_humanize_identifier(fieldName || fieldKey)
                    || fieldKey;
                const targetRef = `acf:${groupKey}:${fieldKey}`;
                index[targetRef] = {
                    targetRef,
                    source: 'acf',
                    displayLabel: `ACF: ${groupTitle} -> ${fieldLabel}`,
                    detailLabel: [
                        fieldName ? `name: ${fieldName}` : '',
                        `key: ${fieldKey}`,
                        `group: ${groupKey}`,
                        `ref: ${targetRef}`,
                    ].filter(Boolean).join(' | '),
                    groupKey,
                    groupTitle,
                    fieldKey,
                    fieldName,
                    fieldLabel,
                    objectType: '',
                    subtype: '',
                    description: '',
                };
            });
        });

        return index;
    }

    function dbvc_cc_get_target_ref_index() {
        const fingerprint = mappingState.catalog && mappingState.catalog.catalog_fingerprint
            ? String(mappingState.catalog.catalog_fingerprint)
            : '';

        if (mappingState.targetRefIndex && mappingState.targetRefIndexFingerprint === fingerprint) {
            return mappingState.targetRefIndex;
        }

        mappingState.targetRefIndex = dbvc_cc_build_target_ref_index();
        mappingState.targetRefIndexFingerprint = fingerprint;
        return mappingState.targetRefIndex;
    }

    function dbvc_cc_get_target_ref_meta(targetRef) {
        const normalizedTargetRef = String(targetRef || '').trim();
        if (!normalizedTargetRef) {
            return dbvc_cc_build_fallback_target_ref_meta('');
        }

        const index = dbvc_cc_get_target_ref_index();
        if (index[normalizedTargetRef] && typeof index[normalizedTargetRef] === 'object') {
            return index[normalizedTargetRef];
        }

        return dbvc_cc_build_fallback_target_ref_meta(normalizedTargetRef);
    }

    function dbvc_cc_build_target_option_markup(targetRef, option = {}) {
        const normalizedTargetRef = String(targetRef || '').trim();
        const targetMeta = dbvc_cc_get_target_ref_meta(normalizedTargetRef);
        const attributes = Object.assign({}, option.attributes || {}, {
            value: normalizedTargetRef,
            'data-target-ref': normalizedTargetRef,
            'data-target-label': targetMeta.displayLabel || normalizedTargetRef,
            'data-target-source': targetMeta.source || '',
            'data-target-group-key': targetMeta.groupKey || '',
            'data-target-group-title': targetMeta.groupTitle || '',
            'data-target-field-key': targetMeta.fieldKey || '',
            'data-target-field-name': targetMeta.fieldName || '',
            title: targetMeta.detailLabel || normalizedTargetRef,
        });
        const optionLabel = String(option.label || targetMeta.displayLabel || normalizedTargetRef || 'Unknown target');
        return `<option ${dbvc_cc_build_html_attributes(attributes)}>${dbvc_cc_escape_html(optionLabel)}</option>`;
    }

    function dbvc_cc_sync_target_select_titles($container = null) {
        const $scope = $container && $container.length ? $container : $(document);
        $scope.find('.dbvc-cc-map-section-target, .dbvc-cc-map-media-target').each(function() {
            const $select = $(this);
            const $selectedOption = $select.find('option:selected');
            const title = String($selectedOption.attr('title') || $selectedOption.data('target-label') || $select.val() || '').trim();
            $select.attr('title', title);
        });
    }

    function dbvc_cc_format_datetime(value) {
        const normalizedValue = String(value || '').trim();
        if (!normalizedValue) {
            return 'n/a';
        }

        return normalizedValue.replace('T', ' ').replace(/Z$/, ' UTC');
    }

    function dbvc_cc_build_state_pill_markup(value, options = {}) {
        const normalizedValue = String(value || '').trim() || 'unknown';
        const normalizedType = String(options.type || '').trim();
        const okStates = new Set(['completed', 'rolled_back']);
        if (normalizedType === 'rollback') {
            okStates.add('available');
        }

        const cssClass = okStates.has(normalizedValue)
            ? 'dbvc-cc-state-pill-ok'
            : 'dbvc-cc-state-pill-warn';

        return `<span class="dbvc-cc-state-pill ${cssClass}">${dbvc_cc_escape_html(normalizedValue.replace(/_/g, ' '))}</span>`;
    }

    function dbvc_cc_get_run_summary_counts(run) {
        const summary = run && typeof run.summary === 'object' && run.summary ? run.summary : {};
        const entityCounts = summary && typeof summary.entity_counts === 'object' && summary.entity_counts ? summary.entity_counts : {};
        const fieldCounts = summary && typeof summary.field_counts === 'object' && summary.field_counts ? summary.field_counts : {};
        const mediaCounts = summary && typeof summary.media_counts === 'object' && summary.media_counts ? summary.media_counts : {};
        const preparedCounts = summary && typeof summary.prepared_counts === 'object' && summary.prepared_counts ? summary.prepared_counts : {};
        const executionFailures = Array.isArray(summary.execution_failures) ? summary.execution_failures : [];

        return {
            entityCompleted: Number(entityCounts.completed || 0),
            fieldCompleted: Number(fieldCounts.completed || 0),
            mediaCompleted: Number(mediaCounts.completed || 0),
            entityPrepared: Number(preparedCounts.entity_writes || 0),
            fieldPrepared: Number(preparedCounts.field_writes || 0),
            mediaPrepared: Number(preparedCounts.media_writes || 0),
            executionFailureCount: executionFailures.length,
        };
    }

    function dbvc_cc_build_run_counts_text(run) {
        const counts = dbvc_cc_get_run_summary_counts(run);
        return `entity:${counts.entityCompleted}/${counts.entityPrepared} | field:${counts.fieldCompleted}/${counts.fieldPrepared} | media:${counts.mediaCompleted}/${counts.mediaPrepared}`;
    }

    function dbvc_cc_build_run_target_label(action) {
        if (!action || typeof action !== 'object') {
            return 'n/a';
        }

        const targetRef = String(action.target_ref || '').trim();
        const objectId = dbvc_cc_normalize_run_id(action.target_object_id);
        if (targetRef) {
            const targetMeta = dbvc_cc_get_target_ref_meta(targetRef);
            let label = String(targetMeta.displayLabel || targetRef);
            if (objectId > 0) {
                label += ` #${objectId}`;
            }
            return label;
        }

        const parts = [];
        const objectType = String(action.target_object_type || '').trim();
        const subtype = String(action.target_subtype || '').trim();
        const metaKey = String(action.target_meta_key || '').trim();

        if (objectType) {
            parts.push(objectType);
        }
        if (subtype) {
            parts.push(subtype);
        }
        if (metaKey) {
            parts.push(metaKey);
        }

        let label = parts.length > 0 ? parts.join(':') : 'n/a';
        if (objectId > 0) {
            label += ` #${objectId}`;
        }

        return label;
    }

    function dbvc_cc_normalize_action_id(action) {
        if (!action || typeof action !== 'object') {
            return 0;
        }

        const actionId = Number(action.id || 0);
        if (actionId > 0) {
            return actionId;
        }

        return Number(action.action_order || 0);
    }

    function dbvc_cc_normalize_for_diff(value) {
        if (Array.isArray(value)) {
            return value.map((item) => dbvc_cc_normalize_for_diff(item));
        }

        if (value && typeof value === 'object') {
            const normalized = {};
            Object.keys(value).sort().forEach((key) => {
                normalized[key] = dbvc_cc_normalize_for_diff(value[key]);
            });
            return normalized;
        }

        return value;
    }

    function dbvc_cc_collect_diff_keys(beforeValue, afterValue, prefix = '') {
        const beforeNormalized = dbvc_cc_normalize_for_diff(beforeValue);
        const afterNormalized = dbvc_cc_normalize_for_diff(afterValue);
        if (JSON.stringify(beforeNormalized) === JSON.stringify(afterNormalized)) {
            return [];
        }

        const beforeIsObject = beforeNormalized && typeof beforeNormalized === 'object' && !Array.isArray(beforeNormalized);
        const afterIsObject = afterNormalized && typeof afterNormalized === 'object' && !Array.isArray(afterNormalized);
        if (beforeIsObject && afterIsObject) {
            const keys = Array.from(new Set([...Object.keys(beforeNormalized), ...Object.keys(afterNormalized)])).sort();
            let changes = [];
            keys.forEach((key) => {
                const nextPrefix = prefix ? `${prefix}.${key}` : key;
                changes = changes.concat(dbvc_cc_collect_diff_keys(beforeNormalized[key], afterNormalized[key], nextPrefix));
            });
            return changes;
        }

        if (Array.isArray(beforeNormalized) && Array.isArray(afterNormalized)) {
            const maxLength = Math.max(beforeNormalized.length, afterNormalized.length);
            let changes = [];
            for (let index = 0; index < maxLength; index += 1) {
                changes = changes.concat(dbvc_cc_collect_diff_keys(beforeNormalized[index], afterNormalized[index], `${prefix}[${index}]`));
            }
            return changes;
        }

        return [prefix || 'value'];
    }

    function dbvc_cc_get_filtered_run_actions(actions = []) {
        const filters = mappingState.importRunActionFilters || {};
        return (Array.isArray(actions) ? actions : []).filter((action) => {
            if (!action || typeof action !== 'object') {
                return false;
            }

            const stage = String(action.stage || '');
            const executionStatus = String(action.execution_status || '');
            const rollbackStatus = String(action.rollback_status || '');
            const hasFailure = !!(action.execution_error || action.rollback_error || executionStatus === 'failed' || rollbackStatus === 'failed');

            if (filters.stage && filters.stage !== stage) {
                return false;
            }
            if (filters.execution && filters.execution !== executionStatus) {
                return false;
            }
            if (filters.rollback && filters.rollback !== rollbackStatus) {
                return false;
            }
            if (filters.failedOnly && !hasFailure) {
                return false;
            }

            return true;
        });
    }

    function dbvc_cc_get_selected_run_action(actions = []) {
        const filteredActions = dbvc_cc_get_filtered_run_actions(actions);
        const selectedActionId = Number(mappingState.importSelectedActionId || 0);
        if (selectedActionId > 0) {
            const selectedAction = filteredActions.find((action) => dbvc_cc_normalize_action_id(action) === selectedActionId);
            if (selectedAction) {
                return selectedAction;
            }
        }

        return filteredActions.length > 0 ? filteredActions[0] : null;
    }

    function dbvc_cc_get_summary_issue_hint(payload, preferredKeys = []) {
        if (!payload || typeof payload !== 'object') {
            return '';
        }

        const collectionKeys = Array.isArray(preferredKeys) && preferredKeys.length > 0
            ? preferredKeys
            : ['issues', 'guard_failures', 'write_barriers', 'execution_failures'];

        for (let index = 0; index < collectionKeys.length; index += 1) {
            const collection = payload[collectionKeys[index]];
            if (!Array.isArray(collection) || collection.length === 0) {
                continue;
            }

            const firstScalar = collection.find((item) => typeof item === 'string' && String(item).trim() !== '');
            if (firstScalar) {
                return String(firstScalar).trim().replace(/_/g, ' ');
            }

            const firstMatch = collection.find((item) => item && typeof item === 'object');
            if (!firstMatch) {
                continue;
            }

            if (Array.isArray(firstMatch.review_reasons) && firstMatch.review_reasons.length > 0) {
                const reviewHint = dbvc_cc_get_summary_issue_hint({ review_reasons: firstMatch.review_reasons }, ['review_reasons']);
                if (reviewHint) {
                    return reviewHint;
                }
            }

            const code = String(firstMatch.code || firstMatch.deferred_reason_code || '').trim();
            const message = String(firstMatch.message || '').trim();
            if (code) {
                return code.replace(/_/g, ' ');
            }
            if (message) {
                return message;
            }
        }

        return '';
    }

    function dbvc_cc_render_handoff_review() {
        const handoffPayload = mappingState.handoffPayload && typeof mappingState.handoffPayload === 'object'
            ? mappingState.handoffPayload
            : null;
        const review = handoffPayload && handoffPayload.review && typeof handoffPayload.review === 'object'
            ? handoffPayload.review
            : {};
        const reasons = Array.isArray(review.reasons) ? review.reasons : [];

        $mappingHandoffReviewList.empty();

        if (!handoffPayload) {
            $mappingHandoffReviewSummary.text('Preview Dry-Run Handoff to inspect review blockers for the selected domain/path.');
            $mappingHandoffReviewList.append('<li>No handoff review loaded yet.</li>');
            return;
        }

        if (reasons.length === 0) {
            $mappingHandoffReviewSummary.text(
                String(handoffPayload.status || '') === 'ready'
                    ? 'Handoff is ready for dry-run planning.'
                    : 'No explicit handoff blockers were returned.'
            );
            $mappingHandoffReviewList.append('<li>No handoff review blockers remain.</li>');
            return;
        }

        const blockingCount = reasons.filter((reason) => reason && reason.blocking).length;
        $mappingHandoffReviewSummary.text(`${reasons.length} review blocker(s) loaded for this domain/path. Blocking:${blockingCount}.`);

        reasons.forEach((reason) => {
            const code = String(reason && reason.code ? reason.code : '').trim();
            const message = String(reason && reason.message ? reason.message : '').trim();
            const context = reason && typeof reason.context === 'object' && reason.context ? reason.context : {};
            const countText = context && Number(context.count || 0) > 0 ? ` (${Number(context.count)} item(s))` : '';
            const chipClass = reason && reason.blocking ? 'dbvc-cc-map-chip-danger' : 'dbvc-cc-map-chip-muted';
            const chipLabel = reason && reason.blocking ? 'blocking' : 'note';
            const heading = code ? code.replace(/_/g, ' ') : 'review reason';

            $mappingHandoffReviewList.append(`
                <li>
                    <span class="dbvc-cc-map-chip ${chipClass}">${dbvc_cc_escape_html(chipLabel)}</span>
                    <strong>${dbvc_cc_escape_html(heading)}</strong>
                    <span>${dbvc_cc_escape_html(message || 'Review this item before continuing.')}${dbvc_cc_escape_html(countText)}</span>
                </li>
            `);
        });
    }

    function dbvc_cc_render_import_run_history() {
        const target = dbvc_cc_get_mapping_target();
        const runHistory = mappingState.importRunHistory && typeof mappingState.importRunHistory === 'object'
            ? mappingState.importRunHistory
            : {};
        const runs = Array.isArray(runHistory.runs) ? runHistory.runs : [];

        $mappingRunHistoryTableBody.empty();

        if (!target.valid) {
            $mappingRunHistoryTableBody.append('<tr><td colspan="5">Select a domain/path to review import runs.</td></tr>');
            return;
        }

        if (runHistory.load_error) {
            $mappingRunHistoryTableBody.append(`<tr><td colspan="5">${dbvc_cc_escape_html(String(runHistory.load_error))}</td></tr>`);
            return;
        }

        if (runs.length === 0) {
            $mappingRunHistoryTableBody.append('<tr><td colspan="5">No import runs were found for this domain/path yet.</td></tr>');
            return;
        }

        runs.forEach((run) => {
            const runId = dbvc_cc_normalize_run_id(run && run.id ? run.id : 0);
            const isActive = runId > 0 && runId === dbvc_cc_normalize_run_id(mappingState.importSelectedRunId);
            const rowClass = isActive ? ' class="is-active"' : '';
            const shortRunUuid = String(run && run.run_uuid ? run.run_uuid : '').substring(0, 18);
            const createdLabel = dbvc_cc_format_datetime(run && run.created_at ? run.created_at : '');
            const rollbackFinishedLabel = dbvc_cc_format_datetime(run && run.rollback_finished_at ? run.rollback_finished_at : '');
            const writesSummary = dbvc_cc_build_run_counts_text(run);
            const failureCount = dbvc_cc_get_run_summary_counts(run).executionFailureCount;
            const errorSummary = String(run && run.error_summary ? run.error_summary : '').trim();

            $mappingRunHistoryTableBody.append(`
                <tr data-run-id="${dbvc_cc_escape_html(runId)}"${rowClass}>
                    <td>
                        <strong>#${dbvc_cc_escape_html(runId || '0')}</strong>
                        <div class="dbvc-cc-map-meta">${dbvc_cc_escape_html(shortRunUuid || 'no uuid')}</div>
                    </td>
                    <td>
                        ${dbvc_cc_build_state_pill_markup(run && run.status ? run.status : 'unknown')}
                        ${errorSummary ? `<div class="dbvc-cc-map-meta">${dbvc_cc_escape_html(errorSummary)}</div>` : ''}
                    </td>
                    <td>
                        <div>${dbvc_cc_escape_html(createdLabel)}</div>
                        <div class="dbvc-cc-map-meta">approved: ${dbvc_cc_escape_html(dbvc_cc_format_datetime(run && run.approved_at ? run.approved_at : ''))}</div>
                    </td>
                    <td>
                        ${dbvc_cc_build_state_pill_markup(run && run.rollback_status ? run.rollback_status : 'unknown', { type: 'rollback' })}
                        <div class="dbvc-cc-map-meta">${dbvc_cc_escape_html(rollbackFinishedLabel)}</div>
                    </td>
                    <td>
                        <div>${dbvc_cc_escape_html(writesSummary)}</div>
                        <div class="dbvc-cc-map-meta">failures: ${dbvc_cc_escape_html(failureCount)}</div>
                    </td>
                </tr>
            `);
        });
    }

    function dbvc_cc_render_run_banner(run) {
        if (!run || typeof run !== 'object') {
            $mappingRunDetailBanner.addClass('dbvc-cc-hidden').removeClass('is-error is-success').text('');
            return;
        }

        const status = String(run.status || '');
        let bannerText = '';
        let bannerClass = 'is-success';
        if (status === 'rolled_back_after_failure') {
            bannerText = 'Automatic rollback completed after an execution failure. Review the selected actions below to confirm the restored state.';
        } else if (status === 'rollback_failed_after_failure') {
            bannerText = 'Automatic rollback failed after an execution failure. Review the failed actions below and use manual rollback if it is still available.';
            bannerClass = 'is-error';
        }

        if (!bannerText) {
            $mappingRunDetailBanner.addClass('dbvc-cc-hidden').removeClass('is-error is-success').text('');
            return;
        }

        $mappingRunDetailBanner.removeClass('dbvc-cc-hidden is-error is-success').addClass(bannerClass).text(bannerText);
    }

    function dbvc_cc_render_selected_run_action_detail(action) {
        if (!action || typeof action !== 'object') {
            $mappingRunActionDetailEmpty.removeClass('dbvc-cc-hidden');
            $mappingRunActionDetailContent.addClass('dbvc-cc-hidden');
            $mappingRunActionDetailSummary.text('');
            $mappingRunActionBeforeState.text('');
            $mappingRunActionAfterState.text('');
            return;
        }

        const beforeState = action.before_state && typeof action.before_state === 'object' ? action.before_state : action.before_state || null;
        const afterState = action.after_state && typeof action.after_state === 'object' ? action.after_state : action.after_state || null;
        const changedKeys = dbvc_cc_collect_diff_keys(beforeState, afterState);
        const summaryParts = [
            `action #${Number(action.action_order || 0)}`,
            `${String(action.stage || 'unknown')} / ${String(action.action_type || 'unknown')}`,
            `target ${dbvc_cc_build_run_target_label(action)}`,
            `execution ${String(action.execution_status || 'unknown')}`,
            `rollback ${String(action.rollback_status || 'unknown')}`,
        ];
        if (changedKeys.length > 0) {
            summaryParts.push(`changed ${changedKeys.slice(0, 8).join(', ')}`);
        } else {
            summaryParts.push('no state diff detected');
        }

        $mappingRunActionDetailEmpty.addClass('dbvc-cc-hidden');
        $mappingRunActionDetailContent.removeClass('dbvc-cc-hidden');
        $mappingRunActionDetailSummary.text(summaryParts.join(' | '));
        $mappingRunActionBeforeState.text(JSON.stringify(beforeState, null, 2));
        $mappingRunActionAfterState.text(JSON.stringify(afterState, null, 2));
    }

    function dbvc_cc_render_import_run_detail() {
        const detail = mappingState.importRunDetail && typeof mappingState.importRunDetail === 'object'
            ? mappingState.importRunDetail
            : null;
        const run = detail && detail.run && typeof detail.run === 'object'
            ? detail.run
            : null;
        const actions = detail && Array.isArray(detail.actions) ? detail.actions : [];

        $mappingRunActionsTableBody.empty();

        if (!run || dbvc_cc_normalize_run_id(run.id) <= 0) {
            $mappingRunDetailEmpty.removeClass('dbvc-cc-hidden');
            $mappingRunDetailContent.addClass('dbvc-cc-hidden');
            $mappingRunDetailId.text('n/a');
            $mappingRunDetailSourceUrl.text('n/a').attr('href', '#');
            $mappingRunDetailStatus.text('n/a');
            $mappingRunDetailRollback.text('n/a');
            $mappingRunDetailSummary.text('');
            $mappingRunDetailBanner.addClass('dbvc-cc-hidden').removeClass('is-error is-success').text('');
            $mappingRunActionsTableBody.append('<tr><td colspan="6">No journaled actions were found for this run.</td></tr>');
            dbvc_cc_render_selected_run_action_detail(null);
            return;
        }

        const sourceUrl = String(run.source_url || '').trim();
        const countsText = dbvc_cc_build_run_counts_text(run);
        const failureCount = dbvc_cc_get_run_summary_counts(run).executionFailureCount;
        const filteredActions = dbvc_cc_get_filtered_run_actions(actions);
        const selectedAction = dbvc_cc_get_selected_run_action(actions);
        const summaryParts = [
            `created ${dbvc_cc_format_datetime(run.created_at || '')}`,
            `started ${dbvc_cc_format_datetime(run.started_at || '')}`,
            countsText,
            `failures:${failureCount}`,
            `filtered actions:${filteredActions.length}/${actions.length}`,
        ];
        if (run.error_summary) {
            summaryParts.push(`error:${String(run.error_summary)}`);
        }

        if (selectedAction) {
            mappingState.importSelectedActionId = dbvc_cc_normalize_action_id(selectedAction);
        } else {
            mappingState.importSelectedActionId = 0;
        }

        $mappingRunDetailEmpty.addClass('dbvc-cc-hidden');
        $mappingRunDetailContent.removeClass('dbvc-cc-hidden');
        $mappingRunDetailId.text(`#${dbvc_cc_normalize_run_id(run.id)} | ${String(run.run_uuid || '').substring(0, 24) || 'no uuid'}`);
        if (sourceUrl) {
            $mappingRunDetailSourceUrl.text(sourceUrl).attr('href', sourceUrl);
        } else {
            $mappingRunDetailSourceUrl.text('n/a').attr('href', '#');
        }
        $mappingRunDetailStatus.html(dbvc_cc_build_state_pill_markup(run.status || 'unknown'));
        $mappingRunDetailRollback.html(dbvc_cc_build_state_pill_markup(run.rollback_status || 'unknown', { type: 'rollback' }));
        $mappingRunDetailSummary.text(summaryParts.join(' | '));
        dbvc_cc_render_run_banner(run);

        if (filteredActions.length === 0) {
            $mappingRunActionsTableBody.append('<tr><td colspan="6">No journaled actions match the current filters.</td></tr>');
            dbvc_cc_render_selected_run_action_detail(null);
            return;
        }

        filteredActions.forEach((action) => {
            const actionOrder = Number(action && action.action_order ? action.action_order : 0);
            const actionId = dbvc_cc_normalize_action_id(action);
            const stageLabel = `${String(action && action.stage ? action.stage : 'unknown')} / ${String(action && action.action_type ? action.action_type : 'unknown')}`;
            const notes = [
                action && action.execution_error ? `execute: ${String(action.execution_error)}` : '',
                action && action.rollback_error ? `rollback: ${String(action.rollback_error)}` : '',
            ].filter(Boolean).join(' | ');
            const isActive = actionId > 0 && actionId === Number(mappingState.importSelectedActionId || 0);

            $mappingRunActionsTableBody.append(`
                <tr data-action-id="${dbvc_cc_escape_html(actionId)}"${isActive ? ' class="is-active"' : ''}>
                    <td>${dbvc_cc_escape_html(actionOrder || 0)}</td>
                    <td>${dbvc_cc_escape_html(stageLabel)}</td>
                    <td>${dbvc_cc_escape_html(dbvc_cc_build_run_target_label(action))}</td>
                    <td>${dbvc_cc_build_state_pill_markup(action && action.execution_status ? action.execution_status : 'unknown')}</td>
                    <td>${dbvc_cc_build_state_pill_markup(action && action.rollback_status ? action.rollback_status : 'unknown', { type: 'rollback' })}</td>
                    <td>${notes ? dbvc_cc_escape_html(notes) : 'n/a'}</td>
                </tr>
            `);
        });

        dbvc_cc_render_selected_run_action_detail(selectedAction);
    }

    function dbvc_cc_normalize_mapping_path(path) {
        const rawValue = String(path || '').trim();
        if (!rawValue) {
            return 'home';
        }

        const stripped = rawValue.replace(/^\/+|\/+$/g, '');
        if (!stripped) {
            return 'home';
        }

        return stripped;
    }

    function dbvc_cc_normalize_domain(domain) {
        return String(domain || '').trim().toLowerCase();
    }

    function dbvc_cc_normalize_post_type(value) {
        return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
    }

    function dbvc_cc_has_select_option($select, optionValue) {
        const normalizedValue = String(optionValue || '');
        return $select.find('option').filter(function() {
            return String($(this).val() || '') === normalizedValue;
        }).length > 0;
    }

    function dbvc_cc_ensure_object_post_type_option(postTypeValue, postTypeLabel = '') {
        const normalizedPostType = dbvc_cc_normalize_post_type(postTypeValue);
        if (!normalizedPostType || dbvc_cc_has_select_option($mappingObjectPostType, normalizedPostType)) {
            return;
        }

        const label = String(postTypeLabel || normalizedPostType);
        $mappingObjectPostType.append(`<option value="${dbvc_cc_escape_html(normalizedPostType)}">${dbvc_cc_escape_html(label)}</option>`);
    }

    function dbvc_cc_populate_object_post_type_options() {
        $mappingObjectPostType.empty();
        $mappingObjectPostType.append('<option value="">Auto (Mapping + AI)</option>');

        const normalizedOptions = [];
        dbvc_cc_post_type_options.forEach((row) => {
            if (!row || typeof row !== 'object') {
                return;
            }

            const value = dbvc_cc_normalize_post_type(row.value);
            if (!value) {
                return;
            }

            normalizedOptions.push({
                value,
                label: String(row.label || value),
            });
        });

        normalizedOptions.sort((left, right) => left.label.localeCompare(right.label));
        normalizedOptions.forEach((option) => {
            dbvc_cc_ensure_object_post_type_option(option.value, option.label);
        });
    }

    function dbvc_cc_set_object_post_type_value(postTypeValue, postTypeLabel = '') {
        const normalizedPostType = dbvc_cc_normalize_post_type(postTypeValue);
        if (!normalizedPostType) {
            $mappingObjectPostType.val('');
            return;
        }

        dbvc_cc_ensure_object_post_type_option(normalizedPostType, postTypeLabel);
        $mappingObjectPostType.val(normalizedPostType);
    }

    function dbvc_cc_has_domain_option($select, domainKey) {
        const normalizedDomain = dbvc_cc_normalize_domain(domainKey);
        if (!normalizedDomain) {
            return false;
        }

        return $select.find('option').filter(function() {
            return String($(this).val() || '') === normalizedDomain;
        }).length > 0;
    }

    function dbvc_cc_append_domain_option($select, domainKey, label = '') {
        const normalizedDomain = dbvc_cc_normalize_domain(domainKey);
        if (!normalizedDomain || dbvc_cc_has_domain_option($select, normalizedDomain)) {
            return;
        }

        const optionLabel = String(label || dbvc_cc_domain_labels_by_key[normalizedDomain] || normalizedDomain);
        $select.append(`<option value="${dbvc_cc_escape_html(normalizedDomain)}">${dbvc_cc_escape_html(optionLabel)}</option>`);
    }

    function dbvc_cc_domain_has_ai_warning(domainKey) {
        const normalizedDomain = dbvc_cc_normalize_domain(domainKey);
        if (!normalizedDomain) {
            return false;
        }

        const dbvc_cc_health = dbvc_cc_domain_ai_health_by_key[normalizedDomain];
        return !!(dbvc_cc_health && dbvc_cc_health.warning_badge);
    }

    function dbvc_cc_get_domain_ai_warning_message(domainKey) {
        const normalizedDomain = dbvc_cc_normalize_domain(domainKey);
        if (!normalizedDomain) {
            return '';
        }

        const dbvc_cc_health = dbvc_cc_domain_ai_health_by_key[normalizedDomain];
        if (!dbvc_cc_health || !dbvc_cc_health.warning_badge) {
            return '';
        }

        if (dbvc_cc_health.warning_message) {
            return String(dbvc_cc_health.warning_message);
        }

        return 'AI pass errors detected. Newer content may be missing.';
    }

    function dbvc_cc_build_domain_option_label(domainKey, label) {
        const normalizedDomain = dbvc_cc_normalize_domain(domainKey);
        const normalizedLabel = String(label || normalizedDomain);
        if (!normalizedDomain || !dbvc_cc_domain_has_ai_warning(normalizedDomain)) {
            return normalizedLabel;
        }

        return `${normalizedLabel} [AI Warning]`;
    }

    function dbvc_cc_render_domain_ai_warning() {
        const dbvc_cc_active_targets = dbvc_cc_get_active_warning_domains();
        if (dbvc_cc_active_targets.length === 0) {
            $domainAiWarning.addClass('dbvc-cc-hidden').text('').prop('disabled', false).removeAttr('title');
            return;
        }

        if (dbvc_cc_active_targets.length === 1) {
            const dbvc_cc_domain_key = dbvc_cc_active_targets[0];
            const dbvc_cc_warning_message = dbvc_cc_get_domain_ai_warning_message(dbvc_cc_domain_key);
            $domainAiWarning
                .removeClass('dbvc-cc-hidden')
                .text('AI Warning: Refresh Domain AI')
                .prop('disabled', false)
                .attr('title', `${dbvc_cc_warning_message || 'AI pass errors detected. Newer content may be missing.'} Click to run a full-domain AI refresh.`);
            return;
        }

        $domainAiWarning
            .removeClass('dbvc-cc-hidden')
            .text(`AI Warning: Refresh ${dbvc_cc_active_targets.length} Domains`)
            .prop('disabled', false)
            .attr('title', `${dbvc_cc_active_targets.length} domains have AI pass errors. Click to queue full-domain AI refresh for each warning domain.`);
    }

    function dbvc_cc_get_warning_domains() {
        return Object.keys(dbvc_cc_domain_ai_health_by_key)
            .filter((domainKey) => dbvc_cc_domain_has_ai_warning(domainKey))
            .sort((left, right) => left.localeCompare(right));
    }

    function dbvc_cc_get_active_warning_domains() {
        const normalizedDomain = dbvc_cc_normalize_domain($domain.val());
        if (normalizedDomain) {
            return dbvc_cc_domain_has_ai_warning(normalizedDomain) ? [normalizedDomain] : [];
        }

        return dbvc_cc_get_warning_domains();
    }

    async function dbvc_cc_queue_domain_ai_refresh(dbvc_cc_domain_key, dbvc_cc_max_jobs = 0, dbvc_cc_offset = 0) {
        return apiRequest('ai/rerun-branch', 'POST', {
            domain: dbvc_cc_domain_key,
            path: '',
            run_now: false,
            max_jobs: Number(dbvc_cc_max_jobs || 0),
            offset: Number(dbvc_cc_offset || 0),
        });
    }

    async function dbvc_cc_queue_domain_ai_refresh_in_chunks(dbvc_cc_domain_key, dbvc_cc_chunk_size = 50) {
        const dbvc_cc_batches = [];
        const dbvc_cc_safe_chunk_size = Math.max(1, Math.min(500, Number(dbvc_cc_chunk_size || 50)));
        let dbvc_cc_offset = 0;
        let dbvc_cc_guard = 0;

        while (dbvc_cc_guard < 500) {
            dbvc_cc_guard += 1;
            const dbvc_cc_queue_response = await dbvc_cc_queue_domain_ai_refresh(
                dbvc_cc_domain_key,
                dbvc_cc_safe_chunk_size,
                dbvc_cc_offset
            );

            const dbvc_cc_batch_id = String(dbvc_cc_queue_response && dbvc_cc_queue_response.batch_id ? dbvc_cc_queue_response.batch_id : '');
            const dbvc_cc_total_jobs = Number(dbvc_cc_queue_response && dbvc_cc_queue_response.total_jobs ? dbvc_cc_queue_response.total_jobs : 0);
            const dbvc_cc_next_offset = Number(
                dbvc_cc_queue_response && dbvc_cc_queue_response.next_offset
                    ? dbvc_cc_queue_response.next_offset
                    : (dbvc_cc_offset + dbvc_cc_total_jobs)
            );
            const dbvc_cc_total_candidate_jobs = Number(
                dbvc_cc_queue_response && dbvc_cc_queue_response.total_candidate_jobs
                    ? dbvc_cc_queue_response.total_candidate_jobs
                    : dbvc_cc_next_offset
            );
            const dbvc_cc_was_truncated = !!(dbvc_cc_queue_response && dbvc_cc_queue_response.was_truncated);

            if (dbvc_cc_batch_id !== '') {
                const dbvc_cc_chunk_start = dbvc_cc_offset + 1;
                const dbvc_cc_chunk_end = Math.max(dbvc_cc_offset, dbvc_cc_next_offset);
                dbvc_cc_batches.push({
                    domain: dbvc_cc_domain_key,
                    batch_id: dbvc_cc_batch_id,
                    chunk_start: dbvc_cc_chunk_start,
                    chunk_end: dbvc_cc_chunk_end,
                });
            }

            if (!dbvc_cc_was_truncated || dbvc_cc_total_jobs <= 0 || dbvc_cc_next_offset <= dbvc_cc_offset || dbvc_cc_next_offset >= dbvc_cc_total_candidate_jobs) {
                break;
            }

            dbvc_cc_offset = dbvc_cc_next_offset;
        }

        if (dbvc_cc_guard >= 500) {
            throw new Error(`AI refresh chunking exceeded guard limit for ${dbvc_cc_domain_key}.`);
        }

        return dbvc_cc_batches;
    }

    async function dbvc_cc_fetch_ai_batch_status(dbvc_cc_batch_id) {
        const dbvc_cc_params = new URLSearchParams({
            batch_id: String(dbvc_cc_batch_id || ''),
        });

        return apiRequest(`ai/status?${dbvc_cc_params.toString()}`);
    }

    async function dbvc_cc_wait_for_ai_batch_completion(dbvc_cc_batch_id, dbvc_cc_options = {}) {
        const dbvc_cc_poll_delay_ms = Number(dbvc_cc_options.pollDelayMs || 1500);
        const dbvc_cc_max_polls = Number(dbvc_cc_options.maxPolls || 240);
        const dbvc_cc_batch_label = String(dbvc_cc_options.batchLabel || dbvc_cc_batch_id);

        let dbvc_cc_polls = 0;
        while (dbvc_cc_polls < dbvc_cc_max_polls) {
            dbvc_cc_polls += 1;
            const dbvc_cc_status_payload = await dbvc_cc_fetch_ai_batch_status(dbvc_cc_batch_id);
            const dbvc_cc_status = String(dbvc_cc_status_payload && dbvc_cc_status_payload.status ? dbvc_cc_status_payload.status : 'queued');
            const dbvc_cc_processed_jobs = Number(dbvc_cc_status_payload && dbvc_cc_status_payload.processed_jobs ? dbvc_cc_status_payload.processed_jobs : 0);
            const dbvc_cc_total_jobs = Number(dbvc_cc_status_payload && dbvc_cc_status_payload.total_jobs ? dbvc_cc_status_payload.total_jobs : 0);

            if (dbvc_cc_status === 'completed' || dbvc_cc_status === 'completed_with_failures') {
                return dbvc_cc_status_payload;
            }

            setStatus(`AI processing ${dbvc_cc_batch_label}: ${dbvc_cc_processed_jobs}/${dbvc_cc_total_jobs} item(s)...`);
            dbvc_cc_set_ai_refresh_note(`AI chunk progress: ${dbvc_cc_batch_label} (${dbvc_cc_processed_jobs}/${dbvc_cc_total_jobs})`);
            await new Promise((resolve) => window.setTimeout(resolve, dbvc_cc_poll_delay_ms));
        }

        const dbvc_cc_timeout_error = new Error(`AI refresh polling timed out for ${dbvc_cc_batch_label}.`);
        dbvc_cc_timeout_error.code = 'dbvc_cc_ai_poll_timeout';
        throw dbvc_cc_timeout_error;
    }

    async function dbvc_cc_wait_for_ai_batch_completion_with_retry(dbvc_cc_batch_id, dbvc_cc_options = {}) {
        const dbvc_cc_retry_limit = Math.max(0, Number(dbvc_cc_options.retryLimit || 1));
        const dbvc_cc_batch_label = String(dbvc_cc_options.batchLabel || dbvc_cc_batch_id);
        let dbvc_cc_attempt = 0;
        let dbvc_cc_last_error = null;

        while (dbvc_cc_attempt <= dbvc_cc_retry_limit) {
            try {
                return await dbvc_cc_wait_for_ai_batch_completion(dbvc_cc_batch_id, dbvc_cc_options);
            } catch (dbvc_cc_error) {
                dbvc_cc_last_error = dbvc_cc_error;
                const dbvc_cc_error_code = String(dbvc_cc_error && dbvc_cc_error.code ? dbvc_cc_error.code : '');
                const dbvc_cc_is_timeout = dbvc_cc_error_code === 'dbvc_cc_ai_poll_timeout';
                if (!dbvc_cc_is_timeout || dbvc_cc_attempt >= dbvc_cc_retry_limit) {
                    throw dbvc_cc_error;
                }

                dbvc_cc_attempt += 1;
                setStatus(`AI polling timed out for ${dbvc_cc_batch_label}. Retrying (${dbvc_cc_attempt}/${dbvc_cc_retry_limit})...`, true);
                dbvc_cc_set_ai_refresh_note(`Retrying timed-out AI chunk: ${dbvc_cc_batch_label} (${dbvc_cc_attempt}/${dbvc_cc_retry_limit})`, true);
            }
        }

        throw dbvc_cc_last_error || new Error(`AI refresh polling failed for ${dbvc_cc_batch_label}.`);
    }

    async function dbvc_cc_run_warning_domain_refreshes() {
        const dbvc_cc_targets = dbvc_cc_get_active_warning_domains();
        if (dbvc_cc_targets.length === 0) {
            setStatus('No AI warning domains available for refresh.');
            dbvc_cc_set_ai_refresh_note('');
            dbvc_cc_render_domain_ai_warning();
            return;
        }

        const dbvc_cc_chunk_size = 50;
        const dbvc_cc_previous_workbench_domain = dbvc_cc_normalize_domain($domain.val());
        const dbvc_cc_previous_mapping_domain = dbvc_cc_normalize_domain($mappingDomain.val());

        $domainAiWarning.prop('disabled', true).text('Refreshing AI...');
        setStatus(`Queueing full-domain AI refresh for ${dbvc_cc_targets.length} domain(s) in chunks of ${dbvc_cc_chunk_size}...`);
        dbvc_cc_set_ai_refresh_note(`Preparing AI chunk queue (${dbvc_cc_chunk_size} items per batch)...`);

        let dbvc_cc_success_count = 0;
        let dbvc_cc_failure_count = 0;
        const dbvc_cc_queued_batches = [];

        try {
            for (const dbvc_cc_domain_key of dbvc_cc_targets) {
                try {
                    const dbvc_cc_domain_batches = await dbvc_cc_queue_domain_ai_refresh_in_chunks(dbvc_cc_domain_key, dbvc_cc_chunk_size);
                    if (dbvc_cc_domain_batches.length > 0) {
                        dbvc_cc_queued_batches.push(...dbvc_cc_domain_batches);
                        dbvc_cc_set_ai_refresh_note(`Queued ${dbvc_cc_domain_batches.length} chunk batch(es) for ${dbvc_cc_domain_key}.`);
                        dbvc_cc_success_count++;
                    } else {
                        dbvc_cc_failure_count++;
                    }
                } catch (dbvc_cc_refresh_error) {
                    dbvc_cc_failure_count++;
                }
            }

            if (dbvc_cc_queued_batches.length > 0) {
                const dbvc_cc_total_batches = dbvc_cc_queued_batches.length;
                let dbvc_cc_completed_batches = 0;
                for (const dbvc_cc_batch of dbvc_cc_queued_batches) {
                    const dbvc_cc_batch_domain = String(dbvc_cc_batch && dbvc_cc_batch.domain ? dbvc_cc_batch.domain : 'domain');
                    const dbvc_cc_chunk_start = Number(dbvc_cc_batch && dbvc_cc_batch.chunk_start ? dbvc_cc_batch.chunk_start : 0);
                    const dbvc_cc_chunk_end = Number(dbvc_cc_batch && dbvc_cc_batch.chunk_end ? dbvc_cc_batch.chunk_end : 0);
                    const dbvc_cc_batch_label = `${dbvc_cc_batch_domain} [${dbvc_cc_chunk_start}-${dbvc_cc_chunk_end}]`;
                    try {
                        const dbvc_cc_batch_status = await dbvc_cc_wait_for_ai_batch_completion_with_retry(
                            dbvc_cc_batch.batch_id,
                            {
                                pollDelayMs: 1500,
                                maxPolls: 240,
                                batchLabel: dbvc_cc_batch_label,
                                retryLimit: 1,
                            }
                        );
                        dbvc_cc_completed_batches += 1;
                        dbvc_cc_set_ai_refresh_note(`AI chunks completed: ${dbvc_cc_completed_batches}/${dbvc_cc_total_batches} (${dbvc_cc_batch_label})`);
                        if (String(dbvc_cc_batch_status && dbvc_cc_batch_status.status ? dbvc_cc_batch_status.status : '') === 'completed_with_failures') {
                            dbvc_cc_failure_count++;
                        }
                    } catch (dbvc_cc_poll_error) {
                        dbvc_cc_completed_batches += 1;
                        dbvc_cc_set_ai_refresh_note(`AI chunk failed: ${dbvc_cc_batch_label} (${dbvc_cc_completed_batches}/${dbvc_cc_total_batches})`, true);
                        dbvc_cc_failure_count++;
                    }
                }
            }

            await dbvc_cc_load_available_domains();
            if (dbvc_cc_previous_workbench_domain) {
                dbvc_cc_set_domain_value($domain, dbvc_cc_previous_workbench_domain);
            }
            if (dbvc_cc_previous_mapping_domain) {
                dbvc_cc_set_domain_value($mappingDomain, dbvc_cc_previous_mapping_domain);
            }
            dbvc_cc_render_domain_ai_warning();

            await loadQueue();

            if (dbvc_cc_failure_count > 0) {
                setStatus(`AI refresh completed with issues for ${dbvc_cc_success_count}/${dbvc_cc_targets.length} domain(s). Queue auto-refreshed.`, true);
                dbvc_cc_set_ai_refresh_note('AI refresh completed with some failed chunks. Queue was auto-refreshed.', true);
                return;
            }

            setStatus(`AI refresh completed for ${dbvc_cc_success_count} domain(s). Queue auto-refreshed.`);
            dbvc_cc_set_ai_refresh_note('AI refresh completed successfully. Queue was auto-refreshed.');
        } finally {
            $domainAiWarning.prop('disabled', false);
            dbvc_cc_render_domain_ai_warning();
        }
    }

    function dbvc_cc_set_domain_value($select, domainKey, label = '') {
        const normalizedDomain = dbvc_cc_normalize_domain(domainKey);
        if (!normalizedDomain) {
            $select.val('');
            return;
        }

        dbvc_cc_append_domain_option($select, normalizedDomain, label);
        $select.val(normalizedDomain);
    }

    function dbvc_cc_populate_domain_selects(domains) {
        $domain.empty();
        $mappingDomain.empty();
        $domain.append('<option value="">All domains</option>');
        $mappingDomain.append('<option value="">Select a domain</option>');

        Object.keys(dbvc_cc_domain_labels_by_key).forEach((key) => {
            delete dbvc_cc_domain_labels_by_key[key];
        });
        Object.keys(dbvc_cc_domain_ai_health_by_key).forEach((key) => {
            delete dbvc_cc_domain_ai_health_by_key[key];
        });

        domains.forEach((domainRecord) => {
            const normalizedDomain = dbvc_cc_normalize_domain(domainRecord && domainRecord.key ? domainRecord.key : '');
            if (!normalizedDomain) {
                return;
            }

            const label = String(domainRecord && domainRecord.label ? domainRecord.label : normalizedDomain);
            const dbvc_cc_health = domainRecord
                && domainRecord.dbvc_cc_ai_health
                && typeof domainRecord.dbvc_cc_ai_health === 'object'
                ? domainRecord.dbvc_cc_ai_health
                : null;
            dbvc_cc_domain_labels_by_key[normalizedDomain] = label;
            dbvc_cc_domain_ai_health_by_key[normalizedDomain] = dbvc_cc_health;

            const dbvc_cc_option_label = dbvc_cc_build_domain_option_label(normalizedDomain, label);
            dbvc_cc_append_domain_option($domain, normalizedDomain, dbvc_cc_option_label);
            dbvc_cc_append_domain_option($mappingDomain, normalizedDomain, label);
        });

        dbvc_cc_render_domain_ai_warning();
    }

    async function dbvc_cc_fetch_domains_payload() {
        try {
            return await apiRequest('workbench/domains');
        } catch (dbvc_cc_workbench_domain_error) {
            return await apiRequest('explorer/domains');
        }
    }

    async function dbvc_cc_load_available_domains() {
        try {
            const payload = await dbvc_cc_fetch_domains_payload();
            const domains = Array.isArray(payload && payload.domains) ? payload.domains : [];
            dbvc_cc_populate_domain_selects(domains);

            if (prefill.domain) {
                dbvc_cc_set_domain_value($domain, prefill.domain);
                dbvc_cc_set_domain_value($mappingDomain, prefill.domain);
            }
            dbvc_cc_render_domain_ai_warning();
        } catch (error) {
            dbvc_cc_populate_domain_selects([]);

            if (prefill.domain) {
                dbvc_cc_set_domain_value($domain, prefill.domain);
                dbvc_cc_set_domain_value($mappingDomain, prefill.domain);
            }
            dbvc_cc_render_domain_ai_warning();
        }
    }

    if (prefill.path) {
        $mappingPath.val(dbvc_cc_normalize_mapping_path(String(prefill.path)));
    }

    dbvc_cc_populate_object_post_type_options();

    function dbvc_cc_get_mapping_target() {
        const domain = String($mappingDomain.val() || '').trim();
        const path = dbvc_cc_normalize_mapping_path(String($mappingPath.val() || ''));
        $mappingPath.val(path);

        if (!domain) {
            return {
                valid: false,
                domain: '',
                path: '',
                message: 'Select a domain before loading mapping artifacts.',
            };
        }

        return {
            valid: true,
            domain,
            path,
            message: '',
        };
    }

    function dbvc_cc_reset_import_execution_state(options = {}) {
        const clearDryRun = !!options.clearDryRun;
        const clearPlan = !!options.clearPlan;
        const clearHandoff = !!options.clearHandoff;

        mappingState.importPreflightApproval = null;
        mappingState.importExecuteSkeleton = null;
        mappingState.importRecovery = null;

        if (clearDryRun) {
            mappingState.importExecutorDryRun = null;
        }
        if (clearPlan) {
            mappingState.importPlanDryRun = null;
        }
        if (clearHandoff) {
            mappingState.handoffPayload = null;
        }
    }

    function dbvc_cc_update_execute_controls() {
        const target = dbvc_cc_get_mapping_target();
        const hasCompletedDryRun = !!(
            mappingState.importExecutorDryRun
            && String(mappingState.importExecutorDryRun.status || '') === 'completed'
        );
        const hasValidApproval = !!(
            mappingState.importPreflightApproval
            && mappingState.importPreflightApproval.approval_valid
            && mappingState.importPreflightApproval.approval
            && mappingState.importPreflightApproval.approval.approval_id
        );
        const hasRollbackableRun = !!(
            mappingState.importExecuteSkeleton
            && Number(mappingState.importExecuteSkeleton.run_id || 0) > 0
            && !!mappingState.importExecuteSkeleton.rollback_available
            && String(mappingState.importExecuteSkeleton.rollback_status || '') === 'available'
        );
        const hasRollbackableSelectedRun = !!(
            mappingState.importRunDetail
            && mappingState.importRunDetail.run
            && dbvc_cc_normalize_run_id(mappingState.importRunDetail.run.id) > 0
            && String(mappingState.importRunDetail.run.rollback_status || '') === 'available'
        );

        $('#dbvc-cc-mapping-approve-import').prop(
            'disabled',
            !dbvc_cc_import_executor_enabled || !dbvc_cc_mapping_bridge_enabled || !target.valid || !hasCompletedDryRun
        );
        $('#dbvc-cc-mapping-run-execute-skeleton').prop(
            'disabled',
            !dbvc_cc_import_executor_enabled || !dbvc_cc_mapping_bridge_enabled || !target.valid || !hasValidApproval
        );
        $('#dbvc-cc-mapping-rollback-run').prop(
            'disabled',
            !dbvc_cc_import_executor_enabled || !dbvc_cc_mapping_bridge_enabled || !hasRollbackableRun
        );
        $('#dbvc-cc-mapping-rollback-selected-run').prop(
            'disabled',
            !dbvc_cc_import_executor_enabled || !dbvc_cc_mapping_bridge_enabled || !hasRollbackableSelectedRun
        );
        $('#dbvc-cc-mapping-download-run-report').prop(
            'disabled',
            !dbvc_cc_mapping_bridge_enabled || !(
                mappingState.importRunDetail
                && mappingState.importRunDetail.run
                && dbvc_cc_normalize_run_id(mappingState.importRunDetail.run.id) > 0
            )
        );
    }

    function dbvc_cc_format_confidence(value) {
        const parsed = Number(value);
        if (Number.isNaN(parsed)) {
            return '0.00';
        }
        return parsed.toFixed(2);
    }

    function dbvc_cc_map_media_role_to_target_ref(role) {
        const key = String(role || '').trim().toLowerCase();
        const roleMap = {
            featured_image: 'core:featured_image',
            hero_background: 'core:featured_image',
            gallery_item: 'meta:post:page:gallery_images',
            inline_illustration: 'core:post_content',
            logo: 'meta:post:page:logo',
            icon: 'meta:post:page:icon',
            video_embed: 'meta:post:page:video_embed_url',
            download_asset: 'meta:post:page:download_asset',
        };

        return roleMap[key] || '';
    }

    function dbvc_cc_update_mapping_availability() {
        const baseSelector = '#dbvc-cc-mapping-domain, #dbvc-cc-mapping-path, #dbvc-cc-mapping-object-post-type, #dbvc-cc-mapping-load-package, #dbvc-cc-mapping-build-catalog, #dbvc-cc-mapping-refresh-catalog, #dbvc-cc-mapping-rebuild-domain, #dbvc-cc-mapping-load-decisions, #dbvc-cc-mapping-load-handoff, #dbvc-cc-mapping-generate-dry-run-plan, #dbvc-cc-mapping-save-decision, #dbvc-cc-mapping-save-media-decision, #dbvc-cc-mapping-refresh-run-history';
        const executorSelector = '#dbvc-cc-mapping-run-executor-dry-run, #dbvc-cc-mapping-approve-import, #dbvc-cc-mapping-run-execute-skeleton, #dbvc-cc-mapping-rollback-run, #dbvc-cc-mapping-rollback-selected-run, #dbvc-cc-mapping-download-run-report';
        const mediaSelector = '#dbvc-cc-mapping-save-media-decision, .dbvc-cc-map-media-target, .dbvc-cc-map-media-override, .dbvc-cc-map-media-ignore';

        if (dbvc_cc_mapping_bridge_enabled) {
            $mappingDisabled.addClass('dbvc-cc-hidden');
            $(baseSelector)
                .prop('disabled', false)
                .removeAttr('aria-disabled')
                .removeClass('is-disabled')
                .css('pointer-events', 'auto');
            if (dbvc_cc_import_executor_enabled) {
                $('#dbvc-cc-mapping-run-executor-dry-run')
                    .prop('disabled', false)
                    .removeAttr('aria-disabled')
                    .removeClass('is-disabled')
                    .css('pointer-events', 'auto');
            } else {
                $(executorSelector).prop('disabled', true);
            }
            if (dbvc_cc_media_mapping_bridge_enabled) {
                $(mediaSelector)
                    .prop('disabled', false)
                    .removeAttr('aria-disabled')
                    .removeClass('is-disabled')
                    .css('pointer-events', 'auto');
            } else {
                $(mediaSelector).prop('disabled', true);
            }
            dbvc_cc_update_execute_controls();
            return;
        }

        $mappingDisabled.removeClass('dbvc-cc-hidden');
        $(baseSelector).prop('disabled', true);
        $(executorSelector).prop('disabled', true);
        return;
    }

    if (!dbvc_cc_import_executor_enabled) {
        $('#dbvc-cc-mapping-run-executor-dry-run, #dbvc-cc-mapping-approve-import, #dbvc-cc-mapping-run-execute-skeleton, #dbvc-cc-mapping-rollback-run, #dbvc-cc-mapping-download-run-report').prop('disabled', true);
    }

    async function apiRequest(path, method = 'GET', data = null) {
        const options = {
            method,
            headers: {
                'X-WP-Nonce': nonce,
            },
        };

        if (method !== 'GET' && data) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }

        const response = await fetch(restBase + path, options);
        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok) {
            const message = payload && payload.message ? payload.message : `Request failed (${response.status})`;
            throw new Error(message);
        }

        return payload;
    }

    function dbvc_cc_get_queue_item_mapping_health(item) {
        return item && item.mapping_health && typeof item.mapping_health === 'object'
            ? item.mapping_health
            : null;
    }

    function dbvc_cc_render_queue_state_cell(item, index) {
        const dbvc_cc_mapping_health = dbvc_cc_get_queue_item_mapping_health(item);
        if (!dbvc_cc_mapping_health) {
            return '<span class="dbvc-cc-state-pill dbvc-cc-state-pill-ok">Ready</span>';
        }

        const dbvc_cc_reasons = Array.isArray(dbvc_cc_mapping_health.reasons) ? dbvc_cc_mapping_health.reasons : [];
        const dbvc_cc_title = dbvc_cc_reasons.length > 0 ? escapeHtml(dbvc_cc_reasons.join(', ')) : '';

        if (dbvc_cc_mapping_health.blocked) {
            return `<span class="dbvc-cc-state-pill dbvc-cc-state-pill-error" title="${dbvc_cc_title}">Blocked</span>`;
        }

        if (dbvc_cc_mapping_health.stale) {
            const dbvc_cc_button = dbvc_cc_mapping_bridge_enabled
                ? `<button type="button" class="button button-small dbvc-cc-queue-rebuild" data-index="${index}">Rebuild</button>`
                : '';

            return `
                <span class="dbvc-cc-state-pill dbvc-cc-state-pill-warn" title="${dbvc_cc_title}">Stale</span>
                <div class="dbvc-cc-state-actions">${dbvc_cc_button}</div>
            `;
        }

        if (dbvc_cc_mapping_health.degraded) {
            return `<span class="dbvc-cc-state-pill dbvc-cc-state-pill-warn" title="${dbvc_cc_title}">Warn</span>`;
        }

        return '<span class="dbvc-cc-state-pill dbvc-cc-state-pill-ok">Ready</span>';
    }

    async function dbvc_cc_rebuild_queue_item_mapping(item) {
        if (!item || !item.domain || !item.path) {
            setStatus('Select a valid queue item to rebuild mapping artifacts.', true);
            return;
        }

        if (!dbvc_cc_mapping_bridge_enabled) {
            setStatus('Mapping bridge feature flags are disabled.', true);
            return;
        }

        const params = new URLSearchParams({
            domain: String(item.domain),
            path: String(item.path),
            build_if_missing: '1',
            force_rebuild: '1',
        });

        setStatus(`Rebuilding mapping artifacts for ${item.domain}/${item.path}...`);

        try {
            await Promise.all([
                apiRequest(`mapping/candidates?${params.toString()}`),
                apiRequest(`mapping/media/candidates?${params.toString()}`),
            ]);
            await loadQueue();
            setStatus(`Rebuilt mapping artifacts for ${item.domain}/${item.path}.`);
        } catch (error) {
            setStatus(error.message || 'Failed to rebuild mapping artifacts for queue item.', true);
        }
    }

    function renderQueue() {
        $tbody.empty();

        if (queueItems.length === 0) {
            $tbody.append('<tr><td colspan="7">No review items found.</td></tr>');
            return;
        }

        queueItems.forEach((item, index) => {
            const reasons = Array.isArray(item.review && item.review.reasons) ? item.review.reasons.join(', ') : '';
            const decision = item.decision && item.decision.status ? item.decision.status : 'pending';
            const confidence = item.suggestion_summary && typeof item.suggestion_summary.post_type_confidence === 'number'
                ? item.suggestion_summary.post_type_confidence.toFixed(2)
                : '0.00';

            const row = `
                <tr data-index="${index}" class="${selected && selected.index === index ? 'is-active' : ''}">
                    <td>${escapeHtml(item.domain || '')}</td>
                    <td>${escapeHtml(item.path || '')}</td>
                    <td>${escapeHtml((item.suggestion_summary && item.suggestion_summary.post_type) || '')}</td>
                    <td>${escapeHtml(confidence)}</td>
                    <td>${escapeHtml(reasons)}</td>
                    <td>${escapeHtml(decision)}</td>
                    <td>${dbvc_cc_render_queue_state_cell(item, index)}</td>
                </tr>
            `;
            $tbody.append(row);
        });
    }

    function setDetailVisible(visible) {
        if (visible) {
            $empty.addClass('dbvc-cc-hidden');
            $detail.removeClass('dbvc-cc-hidden');
        } else {
            $detail.addClass('dbvc-cc-hidden');
            $empty.removeClass('dbvc-cc-hidden');
        }
    }

    function dbvc_cc_seed_mapping_target(domain, path) {
        const domainValue = String(domain || '').trim();
        const pathValue = dbvc_cc_normalize_mapping_path(path || 'home');

        dbvc_cc_reset_import_execution_state({
            clearDryRun: true,
            clearPlan: true,
            clearHandoff: true,
        });
        if (domainValue) {
            dbvc_cc_set_domain_value($mappingDomain, domainValue);
        }
        if (pathValue) {
            $mappingPath.val(pathValue);
        }
        dbvc_cc_set_object_post_type_value('');
        dbvc_cc_refresh_import_run_history_safely({
            preserveSelection: false,
            silent: true,
        });
    }

    async function loadQueue() {
        setStatus('Loading review queue...');

        const params = new URLSearchParams();
        const domain = String($domain.val() || '').trim();
        if (domain) {
            params.set('domain', domain);
        }
        params.set('limit', String($limit.val() || 50));
        params.set('include_decided', $includeDecided.is(':checked') ? '1' : '0');
        params.set('min_confidence', String($minConfidence.val() || 0.75));

        try {
            const payload = await apiRequest(`workbench/review-queue?${params.toString()}`);
            queueItems = Array.isArray(payload.items) ? payload.items : [];
            mappingState.reviewQueueFieldContext = payload && payload.field_context && typeof payload.field_context === 'object'
                ? payload.field_context
                : null;
            selected = null;
            setDetailVisible(false);
            const fieldContextNote = dbvc_cc_build_field_context_note(mappingState.reviewQueueFieldContext);
            dbvc_cc_set_field_context_note(fieldContextNote.message, fieldContextNote.isError);
            renderQueue();
            dbvc_cc_update_mapping_summaries();
            setStatus(`Loaded ${queueItems.length} queue item(s).`);
        } catch (error) {
            queueItems = [];
            mappingState.reviewQueueFieldContext = null;
            selected = null;
            setDetailVisible(false);
            dbvc_cc_set_field_context_note('', false);
            renderQueue();
            dbvc_cc_update_mapping_summaries();
            setStatus(error.message || 'Failed to load review queue.', true);
        }
    }

    async function loadDetail(item, index) {
        if (!item || !item.domain || !item.path) {
            return;
        }

        selected = { item, index };
        renderQueue();
        setStatus('Loading suggestion detail...');

        const params = new URLSearchParams({
            domain: String(item.domain),
            path: String(item.path),
        });

        try {
            const payload = await apiRequest(`workbench/suggestions?${params.toString()}`);
            const suggestionPayload = payload && payload.suggestions ? payload.suggestions : {};
            const decision = payload && payload.decision ? payload.decision : {};

            $node.text(`${item.domain}/${item.path}`);

            const sourceUrl = suggestionPayload && suggestionPayload.source_url ? String(suggestionPayload.source_url) : '';
            if (sourceUrl) {
                $sourceUrl.attr('href', sourceUrl).text(sourceUrl);
            } else {
                $sourceUrl.attr('href', '#').text('');
            }

            $json.text(JSON.stringify(suggestionPayload, null, 2));
            $notes.val(decision && decision.notes ? decision.notes : '');
            dbvc_cc_seed_mapping_target(item.domain, item.path);
            setDetailVisible(true);
            setStatus('Suggestion detail loaded.');
        } catch (error) {
            setStatus(error.message || 'Failed to load suggestion detail.', true);
        }
    }

    async function saveDecision(decision) {
        if (!selected || !selected.item) {
            setStatus('Select a queue item before saving a decision.', true);
            return;
        }

        const payload = {
            domain: selected.item.domain,
            path: selected.item.path,
            decision,
            notes: String($notes.val() || ''),
        };

        setStatus(`Saving decision: ${decision}...`);
        try {
            await apiRequest('workbench/decision', 'POST', payload);
            setStatus('Decision saved. Refreshing queue...');
            await loadQueue();
        } catch (error) {
            setStatus(error.message || 'Failed to save decision.', true);
        }
    }

    function dbvc_cc_render_sections_table() {
        $mappingSectionsTableBody.empty();

        const rows = mappingState.sectionCandidates
            && Array.isArray(mappingState.sectionCandidates.sections)
            ? mappingState.sectionCandidates.sections
            : [];

        if (rows.length === 0) {
            $mappingSectionsTableBody.append('<tr><td colspan="5">No section candidates were found for this node.</td></tr>');
            return;
        }

        rows.forEach((section) => {
            const sectionId = String(section.section_id || '');
            const sectionLabel = String(section.section_label || '').trim();
            const archetype = String(section.section_archetype || 'content');
            const candidates = Array.isArray(section.deterministic_candidates) ? section.deterministic_candidates : [];
            const unresolvedCount = Array.isArray(section.unresolved_fields) ? section.unresolved_fields.length : 0;
            const collectedPreview = String(section.collected_value_preview || '').trim();
            const sectionDisplayLabel = sectionLabel || sectionId || 'section';
            const sectionIdMeta = sectionId && sectionDisplayLabel !== sectionId
                ? `<div class="dbvc-cc-map-meta">id: ${dbvc_cc_escape_html(sectionId)}</div>`
                : '';
            const collectedMeta = collectedPreview
                ? `<div class="dbvc-cc-map-meta dbvc-cc-map-meta-sample">collected: ${dbvc_cc_escape_html(collectedPreview)}</div>`
                : '';

            const options = ['<option value="">Unmapped</option>'];
            candidates.forEach((candidate) => {
                if (!candidate || !candidate.target_ref) {
                    return;
                }
                const targetRef = String(candidate.target_ref);
                const candidateId = String(candidate.candidate_id || '');
                const confidence = dbvc_cc_format_confidence(candidate.confidence || 0);
                const reason = String(candidate.reason || 'deterministic');
                const targetMeta = dbvc_cc_get_target_ref_meta(targetRef);
                const label = `${targetMeta.displayLabel || targetRef} (${confidence})`;
                options.push(dbvc_cc_build_target_option_markup(targetRef, {
                    label,
                    attributes: {
                        'data-candidate-id': candidateId,
                        'data-confidence': confidence,
                        'data-reason': reason,
                    },
                }));
            });

            const rowHtml = `
                <tr data-section-id="${dbvc_cc_escape_html(sectionId)}">
                    <td>
                        <strong>${dbvc_cc_escape_html(sectionDisplayLabel)}</strong>
                        ${sectionIdMeta}
                        ${collectedMeta}
                        <div class="dbvc-cc-map-meta">${candidates.length} candidate(s), ${unresolvedCount} unresolved</div>
                    </td>
                    <td>${dbvc_cc_escape_html(archetype)}</td>
                    <td>
                        <select class="dbvc-cc-map-section-target">
                            ${options.join('')}
                        </select>
                    </td>
                    <td>
                        <input type="text" class="regular-text dbvc-cc-map-section-override" placeholder="meta:post:page:your_field" />
                    </td>
                    <td>
                        <label><input type="checkbox" class="dbvc-cc-map-section-ignore" /> Ignore</label>
                    </td>
                </tr>
            `;

            $mappingSectionsTableBody.append(rowHtml);
        });

        dbvc_cc_sync_target_select_titles($mappingSectionsTableBody);
    }

    function dbvc_cc_build_media_target_options(mediaItem) {
        const optionValues = new Set();
        const roleCandidates = Array.isArray(mediaItem.role_candidates) ? mediaItem.role_candidates : [];
        roleCandidates.forEach((role) => {
            const mapped = dbvc_cc_map_media_role_to_target_ref(role);
            if (mapped) {
                optionValues.add(mapped);
            }
        });

        if (String(mediaItem.media_kind || '') === 'image') {
            optionValues.add('core:featured_image');
        }
        optionValues.add('core:post_content');

        const options = ['<option value="">Unmapped</option>'];
        Array.from(optionValues).forEach((targetRef) => {
            options.push(dbvc_cc_build_target_option_markup(targetRef));
        });

        return options.join('');
    }

    function dbvc_cc_render_media_preview(mediaItem) {
        const kind = String(mediaItem.media_kind || 'file');
        const previewRef = String(mediaItem.preview_ref || '');
        const previewStatus = String(mediaItem.preview_status || '');

        if (kind === 'image' && /^https?:\/\//i.test(previewRef)) {
            return `<img src="${dbvc_cc_escape_html(previewRef)}" alt="preview" class="dbvc-cc-map-preview-image" loading="lazy" />`;
        }

        const fallbackLabel = previewStatus ? `${kind.toUpperCase()} (${previewStatus})` : kind.toUpperCase();
        return `<span class="dbvc-cc-map-preview-fallback">${dbvc_cc_escape_html(fallbackLabel)}</span>`;
    }

    function dbvc_cc_get_media_write_state_index() {
        const index = {};
        const writePreparation = mappingState.importExecuteSkeleton && mappingState.importExecuteSkeleton.write_preparation
            ? mappingState.importExecuteSkeleton.write_preparation
            : (mappingState.importExecutorDryRun && mappingState.importExecutorDryRun.write_preparation
                ? mappingState.importExecutorDryRun.write_preparation
                : null);
        const mediaWrites = writePreparation && Array.isArray(writePreparation.media_writes)
            ? writePreparation.media_writes
            : [];

        mediaWrites.forEach((mediaWrite) => {
            if (!mediaWrite || typeof mediaWrite !== 'object') {
                return;
            }
            const mediaId = String(mediaWrite.media_id || '');
            if (!mediaId || index[mediaId]) {
                return;
            }
            index[mediaId] = mediaWrite;
        });

        return index;
    }

    function dbvc_cc_render_media_write_chip(mediaWrite) {
        if (!mediaWrite || typeof mediaWrite !== 'object') {
            return '<span class="dbvc-cc-map-chip dbvc-cc-map-chip-muted">pending-dry-run</span>';
        }

        const writeStatus = String(mediaWrite.write_status || '');
        const deferredGroup = String(mediaWrite.deferred_reason_group || '').trim();
        if (writeStatus === 'deferred') {
            if (deferredGroup === 'unsupported_shape') {
                return '<span class="dbvc-cc-map-chip dbvc-cc-map-chip-warn">deferred-unsupported-shape</span>';
            }
            if (deferredGroup === 'missing_source') {
                return '<span class="dbvc-cc-map-chip dbvc-cc-map-chip-warn">deferred-missing-source</span>';
            }
            return '<span class="dbvc-cc-map-chip dbvc-cc-map-chip-warn">deferred-policy</span>';
        }

        if (writeStatus === 'prepared') {
            return '<span class="dbvc-cc-map-chip dbvc-cc-map-chip-ok">allowed</span>';
        }

        if (writeStatus === 'blocked') {
            return '<span class="dbvc-cc-map-chip dbvc-cc-map-chip-danger">blocking</span>';
        }

        return `<span class="dbvc-cc-map-chip dbvc-cc-map-chip-muted">${dbvc_cc_escape_html(writeStatus || 'pending-dry-run')}</span>`;
    }

    function dbvc_cc_render_media_table() {
        $mappingMediaTableBody.empty();
        if (!dbvc_cc_media_mapping_bridge_enabled) {
            $mappingMediaTableBody.append('<tr><td colspan="5">Media mapping bridge is disabled for this site. Text-only mapping remains available.</td></tr>');
            return;
        }

        const items = mappingState.mediaCandidates
            && Array.isArray(mappingState.mediaCandidates.media_items)
            ? mappingState.mediaCandidates.media_items
            : [];
        if (items.length === 0) {
            $mappingMediaTableBody.append('<tr><td colspan="5">No media candidates were found for this node.</td></tr>');
            return;
        }

        items.forEach((mediaItem) => {
            const mediaId = String(mediaItem.media_id || '');
            const mediaKind = String(mediaItem.media_kind || 'file');
            const sourceUrl = String(mediaItem.source_url || '');
            const roleCandidates = Array.isArray(mediaItem.role_candidates) ? mediaItem.role_candidates : [];
            const roleLabel = roleCandidates.length ? roleCandidates.join(', ') : 'none';
            const previewStatus = String(mediaItem.preview_status || '');
            const policyTrace = mediaItem.policy_trace || {};
            const domainPolicy = String(policyTrace.dbvc_cc_domain_policy || '');
            const mimeAllowed = policyTrace.dbvc_cc_mime_allowed === false ? 'blocked-mime' : 'allowed-mime';

            const rowHtml = `
                <tr data-media-id="${dbvc_cc_escape_html(mediaId)}" data-media-kind="${dbvc_cc_escape_html(mediaKind)}" data-source-url="${dbvc_cc_escape_html(sourceUrl)}">
                    <td>${dbvc_cc_render_media_preview(mediaItem)}</td>
                    <td>
                        <strong>${dbvc_cc_escape_html(mediaId || 'media')}</strong>
                        <div class="dbvc-cc-map-meta dbvc-cc-map-media-write-status"></div>
                        <div class="dbvc-cc-map-meta">${dbvc_cc_escape_html(mediaKind)} | roles: ${dbvc_cc_escape_html(roleLabel)}</div>
                        <div class="dbvc-cc-map-meta">${dbvc_cc_escape_html(previewStatus || 'preview_status_unknown')} | ${dbvc_cc_escape_html(domainPolicy || 'domain_policy_unknown')} | ${dbvc_cc_escape_html(mimeAllowed)}</div>
                        <div class="dbvc-cc-map-meta dbvc-cc-map-media-write-message"></div>
                        ${sourceUrl ? `<a href="${dbvc_cc_escape_html(sourceUrl)}" target="_blank" rel="noopener noreferrer">Source</a>` : ''}
                    </td>
                    <td>
                        <select class="dbvc-cc-map-media-target">
                            ${dbvc_cc_build_media_target_options(mediaItem)}
                        </select>
                    </td>
                    <td>
                        <input type="text" class="regular-text dbvc-cc-map-media-override" placeholder="meta:post:page:media_field" />
                    </td>
                    <td>
                        <label><input type="checkbox" class="dbvc-cc-map-media-ignore" /> Ignore</label>
                    </td>
                </tr>
            `;

            $mappingMediaTableBody.append(rowHtml);
        });

        dbvc_cc_sync_target_select_titles($mappingMediaTableBody);
        dbvc_cc_update_media_write_annotations();
    }

    function dbvc_cc_update_media_write_annotations() {
        const mediaWriteIndex = dbvc_cc_get_media_write_state_index();
        $mappingMediaTableBody.find('tr[data-media-id]').each(function() {
            const $row = $(this);
            const mediaId = String($row.data('media-id') || '');
            const mediaWrite = mediaWriteIndex[mediaId] || null;
            const message = mediaWrite && mediaWrite.message ? String(mediaWrite.message || '') : '';
            $row.find('.dbvc-cc-map-media-write-status').html(dbvc_cc_render_media_write_chip(mediaWrite));
            $row.find('.dbvc-cc-map-media-write-message').text(message);
        });
    }

    function dbvc_cc_update_mapping_summaries() {
        if (mappingState.catalog) {
            const fingerprint = String(mappingState.catalog.catalog_fingerprint || '');
            const generatedAt = String(mappingState.catalog.generated_at || '');
            $mappingCatalogSummary.text(`${generatedAt || 'generated'} | ${fingerprint ? fingerprint.substring(0, 12) : 'no fingerprint'}`);
            $mappingCatalogJson.text(JSON.stringify(mappingState.catalog, null, 2));
        } else {
            $mappingCatalogSummary.text('n/a');
            $mappingCatalogJson.text('');
        }

        const fieldContextMeta = dbvc_cc_get_catalog_field_context_meta() || dbvc_cc_get_queue_field_context_meta();
        $mappingFieldContextSummary.text(dbvc_cc_describe_field_context_meta(fieldContextMeta));
        if (!mappingState.catalog && !fieldContextMeta) {
            $mappingFieldContextSummary.text('n/a');
        }

        if (mappingState.sectionCandidates) {
            const stats = mappingState.sectionCandidates.stats || {};
            const sectionCount = Number(stats.section_count || 0);
            const unresolvedCount = Number(stats.unresolved_section_count || 0);
            const sectionStatus = mappingState.sectionCandidatesStatus || {};
            const staleText = sectionStatus.stale ? `, stale (${String(sectionStatus.stale_reason || 'unknown')})` : '';
            $mappingSectionsSummary.text(`${sectionCount} section(s), ${unresolvedCount} unresolved${staleText}`);
            $mappingSectionsJson.text(JSON.stringify(mappingState.sectionCandidates, null, 2));
        } else {
            $mappingSectionsSummary.text('n/a');
            $mappingSectionsJson.text('');
        }

        if (mappingState.mediaCandidates) {
            const stats = mappingState.mediaCandidates.stats || {};
            const total = Number(stats.total_candidates || 0);
            const previewCount = Number(stats.with_preview_refs || 0);
            const blockedCount = Number(stats.blocked_url_count || 0);
            const suppressedCount = Number(stats.suppressed_preview_count || 0);
            const policy = mappingState.mediaCandidates.policy || {};
            const previewMode = Number(policy.dbvc_cc_media_preview_thumbnail_enabled || 0) === 1 ? 'preview:on' : 'preview:off';
            const privateHostMode = Number(policy.dbvc_cc_media_block_private_hosts || 0) === 1 ? 'private-host-block:on' : 'private-host-block:off';
            const mediaStatus = mappingState.mediaCandidatesStatus || {};
            const staleText = mediaStatus.stale ? `, stale (${String(mediaStatus.stale_reason || 'unknown')})` : '';
            $mappingMediaSummary.text(`${total} media candidate(s), ${previewCount} preview(s), blocked: ${blockedCount}, suppressed: ${suppressedCount}, ${previewMode}, ${privateHostMode}${staleText}`);
            $mappingMediaJson.text(JSON.stringify(mappingState.mediaCandidates, null, 2));
        } else if (!dbvc_cc_media_mapping_bridge_enabled) {
            $mappingMediaSummary.text('disabled (text-only mapping remains available)');
            $mappingMediaJson.text(JSON.stringify({
                status: 'disabled',
                reason: 'media_mapping_bridge_disabled',
            }, null, 2));
        } else {
            $mappingMediaSummary.text('n/a');
            $mappingMediaJson.text('');
        }

        const decisionSummary = mappingState.mappingDecision
            ? `${String(mappingState.mappingDecision.decision_status || 'pending')} (${Array.isArray(mappingState.mappingDecision.approved_mappings) ? mappingState.mappingDecision.approved_mappings.length : 0} approved)`
            : 'n/a';
        $mappingDecisionSummary.text(decisionSummary);

        const mediaDecisionSummary = mappingState.mediaDecision
            ? `${String(mappingState.mediaDecision.decision_status || 'pending')} (${Array.isArray(mappingState.mediaDecision.approved) ? mappingState.mediaDecision.approved.length : 0} approved)`
            : 'n/a';
        $mappingMediaDecisionSummary.text(mediaDecisionSummary);

        const handoffReviewHint = mappingState.handoffPayload && mappingState.handoffPayload.review && Array.isArray(mappingState.handoffPayload.review.reasons)
            ? dbvc_cc_get_summary_issue_hint({ review_reasons: mappingState.handoffPayload.review.reasons }, ['review_reasons'])
            : '';
        const handoffReviewCount = mappingState.handoffPayload && mappingState.handoffPayload.review
            ? Number(mappingState.handoffPayload.review.reason_count || 0)
            : 0;
        const handoffSummary = mappingState.handoffPayload
            ? `${String(mappingState.handoffPayload.status || 'needs_review')} (${Array.isArray(mappingState.handoffPayload.warnings) ? mappingState.handoffPayload.warnings.length : 0} warning(s), review:${handoffReviewCount}${handoffReviewHint ? `, reason:${handoffReviewHint}` : ''})`
            : 'n/a';
        $mappingHandoffSummary.text(handoffSummary);
        dbvc_cc_render_handoff_review();
        let defaultEntityKey = '';
        let defaultEntityReason = '';
        let defaultEntityOverridePostType = '';
        if (mappingState.handoffPayload && mappingState.handoffPayload.phase4_input && mappingState.handoffPayload.phase4_input.object_hints) {
            const dbvc_cc_hints = mappingState.handoffPayload.phase4_input.object_hints;
            defaultEntityReason = String(dbvc_cc_hints.default_entity_reason || '');
            defaultEntityOverridePostType = String(dbvc_cc_hints.override_post_type || '');
        }
        if (mappingState.importExecutorDryRun && mappingState.importExecutorDryRun.trace && mappingState.importExecutorDryRun.trace.default_entity_key) {
            defaultEntityKey = String(mappingState.importExecutorDryRun.trace.default_entity_key);
        } else if (mappingState.importPlanDryRun && mappingState.importPlanDryRun.phase4_input && mappingState.importPlanDryRun.phase4_input.default_entity_key) {
            defaultEntityKey = String(mappingState.importPlanDryRun.phase4_input.default_entity_key);
        } else if (mappingState.handoffPayload && mappingState.handoffPayload.phase4_input && mappingState.handoffPayload.phase4_input.default_entity_key) {
            defaultEntityKey = String(mappingState.handoffPayload.phase4_input.default_entity_key);
        }
        const dbvc_cc_default_entity_summary_parts = [];
        if (defaultEntityKey) {
            dbvc_cc_default_entity_summary_parts.push(defaultEntityKey);
        }
        if (defaultEntityReason) {
            dbvc_cc_default_entity_summary_parts.push(defaultEntityReason.replace(/_/g, ' '));
        }
        if (defaultEntityOverridePostType) {
            dbvc_cc_default_entity_summary_parts.push(`override:${defaultEntityOverridePostType}`);
        }
        $mappingDefaultEntitySummary.text(dbvc_cc_default_entity_summary_parts.length > 0 ? dbvc_cc_default_entity_summary_parts.join(' | ') : 'n/a');

        let dbvc_cc_phase4_context = null;
        if (mappingState.importExecuteSkeleton && mappingState.importExecuteSkeleton.phase4_context) {
            dbvc_cc_phase4_context = mappingState.importExecuteSkeleton.phase4_context;
        } else if (mappingState.importExecutorDryRun && mappingState.importExecutorDryRun.phase4_context) {
            dbvc_cc_phase4_context = mappingState.importExecutorDryRun.phase4_context;
        }

        if (!dbvc_cc_phase4_context && mappingState.importPlanDryRun) {
            dbvc_cc_phase4_context = {
                handoff_schema_version: String(mappingState.importPlanDryRun.handoff_schema_version || ''),
                handoff_generated_at: String(mappingState.importPlanDryRun.handoff_generated_at || ''),
                default_entity_key: defaultEntityKey,
                default_entity_reason: defaultEntityReason,
                override_post_type: defaultEntityOverridePostType,
                suggested_post_type: mappingState.handoffPayload && mappingState.handoffPayload.phase4_input && mappingState.handoffPayload.phase4_input.object_hints
                    ? String(mappingState.handoffPayload.phase4_input.object_hints.suggested_post_type || '')
                    : '',
            };
        }

        const dbvc_cc_phase4_context_parts = [];
        if (dbvc_cc_phase4_context && dbvc_cc_phase4_context.handoff_schema_version) {
            dbvc_cc_phase4_context_parts.push(`handoff:${String(dbvc_cc_phase4_context.handoff_schema_version)}`);
        }
        if (dbvc_cc_phase4_context && dbvc_cc_phase4_context.default_entity_reason) {
            dbvc_cc_phase4_context_parts.push(`reason:${String(dbvc_cc_phase4_context.default_entity_reason).replace(/_/g, ' ')}`);
        }
        if (dbvc_cc_phase4_context && dbvc_cc_phase4_context.override_post_type) {
            dbvc_cc_phase4_context_parts.push(`override:${String(dbvc_cc_phase4_context.override_post_type)}`);
        }
        if (dbvc_cc_phase4_context && dbvc_cc_phase4_context.suggested_post_type) {
            dbvc_cc_phase4_context_parts.push(`suggested:${String(dbvc_cc_phase4_context.suggested_post_type)}`);
        }
        $mappingPhase4ContextSummary.text(dbvc_cc_phase4_context_parts.length > 0 ? dbvc_cc_phase4_context_parts.join(' | ') : 'n/a');

        const importPlanIssueHint = mappingState.importPlanDryRun
            ? dbvc_cc_get_summary_issue_hint(mappingState.importPlanDryRun, ['issues'])
            : '';
        const importPlanSummary = mappingState.importPlanDryRun
            ? `${String(mappingState.importPlanDryRun.status || 'blocked')} (${Number(mappingState.importPlanDryRun.blocking_issue_count || 0)} blocking issue(s)${importPlanIssueHint ? `, reason:${importPlanIssueHint}` : ''})`
            : 'n/a';
        $mappingImportPlanSummary.text(importPlanSummary);
        const importExecutorIssueHint = mappingState.importExecutorDryRun
            ? dbvc_cc_get_summary_issue_hint(mappingState.importExecutorDryRun, ['issues', 'write_barriers'])
            : '';
        const importExecutorSummary = mappingState.importExecutorDryRun
            ? `${String(mappingState.importExecutorDryRun.status || 'blocked')} (${Number(mappingState.importExecutorDryRun.operation_counts && mappingState.importExecutorDryRun.operation_counts.total_simulated ? mappingState.importExecutorDryRun.operation_counts.total_simulated : 0)} op(s), updates:${Number(mappingState.importExecutorDryRun.operation_counts && mappingState.importExecutorDryRun.operation_counts.entity_updates ? mappingState.importExecutorDryRun.operation_counts.entity_updates : 0)}, creates:${Number(mappingState.importExecutorDryRun.operation_counts && mappingState.importExecutorDryRun.operation_counts.entity_creates ? mappingState.importExecutorDryRun.operation_counts.entity_creates : 0)}, blocked:${Number(mappingState.importExecutorDryRun.operation_counts && mappingState.importExecutorDryRun.operation_counts.entity_blocked ? mappingState.importExecutorDryRun.operation_counts.entity_blocked : 0)}, barriers:${Number(mappingState.importExecutorDryRun.operation_counts && mappingState.importExecutorDryRun.operation_counts.write_barrier_count ? mappingState.importExecutorDryRun.operation_counts.write_barrier_count : 0)}, deferred media:${Number(mappingState.importExecutorDryRun.deferred_media_count || (mappingState.importExecutorDryRun.operation_counts && mappingState.importExecutorDryRun.operation_counts.deferred_media_count ? mappingState.importExecutorDryRun.operation_counts.deferred_media_count : 0))}, ${Number(mappingState.importExecutorDryRun.blocking_issue_count || 0)} blocking issue(s)${importExecutorIssueHint ? `, reason:${importExecutorIssueHint}` : ''})`
            : 'n/a';
        $mappingImportExecutorSummary.text(importExecutorSummary);
        const importApprovalIssueHint = mappingState.importPreflightApproval
            ? dbvc_cc_get_summary_issue_hint(mappingState.importPreflightApproval, ['guard_failures'])
            : '';
        const importApprovalSummary = mappingState.importPreflightApproval
            ? `${String(mappingState.importPreflightApproval.status || 'blocked')} (eligible:${mappingState.importPreflightApproval.approval_eligible ? 'yes' : 'no'}, valid:${mappingState.importPreflightApproval.approval_valid ? 'yes' : 'no'}, deferred media:${Number(mappingState.importPreflightApproval.deferred_media_count || (mappingState.importPreflightApproval.operation_counts && mappingState.importPreflightApproval.operation_counts.deferred_media_count ? mappingState.importPreflightApproval.operation_counts.deferred_media_count : 0))}, execute:${mappingState.importPreflightApproval.approval_valid ? 'continues without deferred media' : 'not armed'}, expires:${String(mappingState.importPreflightApproval.approval && mappingState.importPreflightApproval.approval.expires_at ? mappingState.importPreflightApproval.approval.expires_at : 'n/a')}${importApprovalIssueHint ? `, reason:${importApprovalIssueHint}` : ''})`
            : 'approval required';
        $mappingImportApprovalSummary.text(importApprovalSummary);
        const executeAutoRollbackSummary = mappingState.importExecuteSkeleton
            && mappingState.importExecuteSkeleton.auto_rollback
            && mappingState.importExecuteSkeleton.auto_rollback.attempted
            ? `, auto rollback:${String(mappingState.importExecuteSkeleton.auto_rollback.status || 'unknown')}, restored:${Number(mappingState.importExecuteSkeleton.auto_rollback.restored_count || 0)}, failed:${Number(mappingState.importExecuteSkeleton.auto_rollback.failed_count || 0)}`
            : '';
        const importExecuteIssueHint = mappingState.importExecuteSkeleton
            ? dbvc_cc_get_summary_issue_hint(mappingState.importExecuteSkeleton, ['guard_failures', 'write_barriers', 'execution_failures'])
            : '';
        const importExecuteSummary = mappingState.importExecuteSkeleton
            ? `${String(mappingState.importExecuteSkeleton.status || 'blocked')} (run:${Number(mappingState.importExecuteSkeleton.run_id || 0)}, rollback:${String(mappingState.importExecuteSkeleton.rollback_status || 'n/a')}, guards:${Number(mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.guard_failure_count ? mappingState.importExecuteSkeleton.operation_counts.guard_failure_count : 0)}, barriers:${Number(mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.write_barrier_count ? mappingState.importExecuteSkeleton.operation_counts.write_barrier_count : 0)}, executed entity:${Number(mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.executed_entity_writes ? mappingState.importExecuteSkeleton.operation_counts.executed_entity_writes : 0)}, executed field:${Number(mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.executed_field_writes ? mappingState.importExecuteSkeleton.operation_counts.executed_field_writes : 0)}, executed media:${Number(mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.executed_media_writes ? mappingState.importExecuteSkeleton.operation_counts.executed_media_writes : 0)}, failed entity:${Number(mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.failed_entity_writes ? mappingState.importExecuteSkeleton.operation_counts.failed_entity_writes : 0)}, failed field:${Number(mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.failed_field_writes ? mappingState.importExecuteSkeleton.operation_counts.failed_field_writes : 0)}, failed media:${Number(mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.failed_media_writes ? mappingState.importExecuteSkeleton.operation_counts.failed_media_writes : 0)}, deferred media:${Number(mappingState.importExecuteSkeleton.deferred_media_count || (mappingState.importExecuteSkeleton.operation_counts && mappingState.importExecuteSkeleton.operation_counts.deferred_media_writes ? mappingState.importExecuteSkeleton.operation_counts.deferred_media_writes : 0))}${importExecuteIssueHint ? `, reason:${importExecuteIssueHint}` : ''}${executeAutoRollbackSummary})`
            : 'n/a';
        $mappingImportExecuteSummary.text(importExecuteSummary);
        const importRecoverySummary = mappingState.importRecovery
            ? `${String(mappingState.importRecovery.status || 'unknown')} (run:${Number(mappingState.importRecovery.run && mappingState.importRecovery.run.id ? mappingState.importRecovery.run.id : 0)}, restored:${Number(mappingState.importRecovery.restored_count || 0)}, failed:${Number(mappingState.importRecovery.failed_count || 0)})`
            : (mappingState.importExecuteSkeleton && Number(mappingState.importExecuteSkeleton.run_id || 0) > 0
                ? `${String(mappingState.importExecuteSkeleton.rollback_status || 'not_started')} (run:${Number(mappingState.importExecuteSkeleton.run_id || 0)}, available:${mappingState.importExecuteSkeleton.rollback_available ? 'yes' : 'no'}, auto:${mappingState.importExecuteSkeleton.auto_rollback && mappingState.importExecuteSkeleton.auto_rollback.attempted ? String(mappingState.importExecuteSkeleton.auto_rollback.status || 'unknown') : 'n/a'}, restored:${mappingState.importExecuteSkeleton.auto_rollback && mappingState.importExecuteSkeleton.auto_rollback.attempted ? Number(mappingState.importExecuteSkeleton.auto_rollback.restored_count || 0) : 0})`
                : 'n/a');
        $mappingImportRecoverySummary.text(importRecoverySummary);
        const target = dbvc_cc_get_mapping_target();
        const runHistory = mappingState.importRunHistory && typeof mappingState.importRunHistory === 'object'
            ? mappingState.importRunHistory
            : {};
        const runs = Array.isArray(runHistory.runs) ? runHistory.runs : [];
        const selectedRun = mappingState.importRunDetail && mappingState.importRunDetail.run
            ? mappingState.importRunDetail.run
            : null;
        const runHistorySummary = !target.valid
            ? 'select domain/path'
            : (runHistory.load_error
                ? `error (${String(runHistory.load_error)})`
                : `${runs.length} run(s) loaded${selectedRun && selectedRun.id ? `, selected #${Number(selectedRun.id || 0)}` : ''}`);
        $mappingRunHistorySummary.text(runHistorySummary);

        $mappingDecisionsJson.text(JSON.stringify({
            mapping_decision: mappingState.mappingDecision,
            media_decision: mappingState.mediaDecision,
        }, null, 2));
        $mappingHandoffJson.text(JSON.stringify(mappingState.handoffPayload, null, 2));
        $mappingImportPlanJson.text(JSON.stringify(mappingState.importPlanDryRun, null, 2));
        $mappingImportExecutorJson.text(JSON.stringify(mappingState.importExecutorDryRun, null, 2));
        $mappingImportApprovalJson.text(JSON.stringify(mappingState.importPreflightApproval, null, 2));
        $mappingImportExecuteJson.text(JSON.stringify(mappingState.importExecuteSkeleton, null, 2));
        $mappingImportRecoveryJson.text(JSON.stringify(mappingState.importRecovery, null, 2));
        $mappingRunHistoryJson.text(JSON.stringify(mappingState.importRunHistory, null, 2));
        $mappingRunDetailJson.text(JSON.stringify(mappingState.importRunDetail, null, 2));
        dbvc_cc_update_media_write_annotations();
        dbvc_cc_render_import_run_history();
        dbvc_cc_render_import_run_detail();
        dbvc_cc_update_execute_controls();
    }

    async function dbvc_cc_load_catalog(forceRefresh = false) {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const endpoint = forceRefresh ? 'mapping/catalog/refresh' : 'mapping/catalog/build';
        const response = await apiRequest(endpoint, 'POST', {
            domain: target.domain,
        });

        mappingState.catalog = response && response.catalog ? response.catalog : null;
        mappingState.targetRefIndex = null;
        mappingState.targetRefIndexFingerprint = '';
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    async function dbvc_cc_load_section_candidates(forceRebuild = false) {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const params = new URLSearchParams({
            domain: target.domain,
            path: target.path,
            build_if_missing: '1',
            force_rebuild: forceRebuild ? '1' : '0',
        });
        const response = await apiRequest(`mapping/candidates?${params.toString()}`);
        mappingState.sectionCandidates = response && response.section_candidates ? response.section_candidates : null;
        mappingState.sectionCandidatesStatus = {
            status: response && response.status ? String(response.status) : 'unknown',
            stale: !!(response && response.stale),
            stale_reason: response && response.stale_reason ? String(response.stale_reason) : '',
        };
        dbvc_cc_render_sections_table();
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    async function dbvc_cc_load_media_candidates(forceRebuild = false) {
        if (!dbvc_cc_media_mapping_bridge_enabled) {
            mappingState.mediaCandidates = null;
            mappingState.mediaCandidatesStatus = {
                status: 'disabled',
                stale: false,
                stale_reason: 'media_mapping_bridge_disabled',
            };
            dbvc_cc_render_media_table();
            dbvc_cc_update_mapping_summaries();
            return {
                status: 'disabled',
                media_candidates: null,
            };
        }

        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const params = new URLSearchParams({
            domain: target.domain,
            path: target.path,
            build_if_missing: '1',
            force_rebuild: forceRebuild ? '1' : '0',
        });
        const response = await apiRequest(`mapping/media/candidates?${params.toString()}`);
        mappingState.mediaCandidates = response && response.media_candidates ? response.media_candidates : null;
        mappingState.mediaCandidatesStatus = {
            status: response && response.status ? String(response.status) : 'unknown',
            stale: !!(response && response.stale),
            stale_reason: response && response.stale_reason ? String(response.stale_reason) : '',
        };
        dbvc_cc_render_media_table();
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    function dbvc_cc_apply_loaded_decisions_to_form() {
        const mappingDecision = mappingState.mappingDecision || null;
        const mediaDecision = mappingState.mediaDecision || null;

        if (mappingDecision) {
            const approved = Array.isArray(mappingDecision.approved_mappings) ? mappingDecision.approved_mappings : [];
            const overrides = Array.isArray(mappingDecision.overrides) ? mappingDecision.overrides : [];
            const rejections = Array.isArray(mappingDecision.rejections) ? mappingDecision.rejections : [];

            const approvedBySection = {};
            approved.forEach((row) => {
                if (!row || !row.section_id) {
                    return;
                }
                approvedBySection[String(row.section_id)] = String(row.target_ref || '');
            });

            const overrideBySection = {};
            overrides.forEach((row) => {
                if (!row || !row.section_id) {
                    return;
                }
                overrideBySection[String(row.section_id)] = String(row.override_target_ref || row.target_ref || '');
            });

            const rejectedSections = new Set();
            rejections.forEach((row) => {
                if (!row || !row.section_id) {
                    return;
                }
                rejectedSections.add(String(row.section_id));
            });

            $mappingSectionsTableBody.find('tr[data-section-id]').each(function() {
                const $row = $(this);
                const sectionId = String($row.data('section-id') || '');
                const targetRef = approvedBySection[sectionId] || '';
                const overrideRef = overrideBySection[sectionId] || '';
                const isRejected = rejectedSections.has(sectionId);

                $row.find('.dbvc-cc-map-section-target').val(targetRef);
                $row.find('.dbvc-cc-map-section-override').val(overrideRef);
                $row.find('.dbvc-cc-map-section-ignore').prop('checked', isRejected);
            });
            dbvc_cc_sync_target_select_titles($mappingSectionsTableBody);

            if (mappingDecision.decision_status) {
                $mappingDecisionStatus.val(String(mappingDecision.decision_status));
            }

            const mappingObjectHints = mappingDecision.object_hints && typeof mappingDecision.object_hints === 'object'
                ? mappingDecision.object_hints
                : null;
            if (mappingObjectHints && mappingObjectHints.override_post_type) {
                dbvc_cc_set_object_post_type_value(String(mappingObjectHints.override_post_type));
            } else {
                dbvc_cc_set_object_post_type_value('');
            }
        }

        if (mediaDecision) {
            const approved = Array.isArray(mediaDecision.approved) ? mediaDecision.approved : [];
            const overrides = Array.isArray(mediaDecision.overrides) ? mediaDecision.overrides : [];
            const ignored = Array.isArray(mediaDecision.ignored) ? mediaDecision.ignored : [];

            const approvedByMedia = {};
            approved.forEach((row) => {
                if (!row || !row.media_id) {
                    return;
                }
                approvedByMedia[String(row.media_id)] = String(row.target_ref || '');
            });

            const overrideByMedia = {};
            overrides.forEach((row) => {
                if (!row || !row.media_id) {
                    return;
                }
                overrideByMedia[String(row.media_id)] = String(row.override_target_ref || row.target_ref || '');
            });

            const ignoredMedia = new Set();
            ignored.forEach((row) => {
                if (!row || !row.media_id) {
                    return;
                }
                ignoredMedia.add(String(row.media_id));
            });

            $mappingMediaTableBody.find('tr[data-media-id]').each(function() {
                const $row = $(this);
                const mediaId = String($row.data('media-id') || '');
                const targetRef = approvedByMedia[mediaId] || '';
                const overrideRef = overrideByMedia[mediaId] || '';
                const isIgnored = ignoredMedia.has(mediaId);

                $row.find('.dbvc-cc-map-media-target').val(targetRef);
                $row.find('.dbvc-cc-map-media-override').val(overrideRef);
                $row.find('.dbvc-cc-map-media-ignore').prop('checked', isIgnored);
            });
            dbvc_cc_sync_target_select_titles($mappingMediaTableBody);

            if (mediaDecision.decision_status) {
                $mappingMediaDecisionStatus.val(String(mediaDecision.decision_status));
            }
        }
    }

    async function dbvc_cc_load_decisions() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const mappingParams = new URLSearchParams({
            domain: target.domain,
            path: target.path,
        });

        const [mappingDecisionResponse, mediaDecisionResponse] = await Promise.all([
            apiRequest(`mapping/decision?${mappingParams.toString()}`),
            apiRequest(`mapping/media/decision?${mappingParams.toString()}`),
        ]);

        mappingState.mappingDecision = mappingDecisionResponse && mappingDecisionResponse.mapping_decision
            ? mappingDecisionResponse.mapping_decision
            : null;
        mappingState.mediaDecision = mediaDecisionResponse && mediaDecisionResponse.media_decision
            ? mediaDecisionResponse.media_decision
            : null;

        dbvc_cc_apply_loaded_decisions_to_form();
        dbvc_cc_update_mapping_summaries();

        return {
            mapping: mappingDecisionResponse,
            media: mediaDecisionResponse,
        };
    }

    async function dbvc_cc_load_handoff_payload() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const params = new URLSearchParams({
            domain: target.domain,
            path: target.path,
            build_if_missing: '1',
        });

        dbvc_cc_reset_import_execution_state({
            clearPlan: true,
            clearDryRun: true,
        });
        const response = await apiRequest(`mapping/handoff?${params.toString()}`);
        mappingState.handoffPayload = response || null;
        const dbvc_cc_has_mapping_override = !!(
            mappingState.mappingDecision
            && mappingState.mappingDecision.object_hints
            && mappingState.mappingDecision.object_hints.override_post_type
        );
        if (!dbvc_cc_has_mapping_override && response && response.phase4_input && response.phase4_input.object_hints) {
            dbvc_cc_set_object_post_type_value(String(response.phase4_input.object_hints.override_post_type || ''));
        }
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    async function dbvc_cc_load_import_plan_dry_run() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const params = new URLSearchParams({
            domain: target.domain,
            path: target.path,
            build_if_missing: '1',
        });

        dbvc_cc_reset_import_execution_state({
            clearDryRun: true,
        });
        const response = await apiRequest(`import-plan/dry-run?${params.toString()}`);
        mappingState.importPlanDryRun = response || null;
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    async function dbvc_cc_load_import_executor_dry_run() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const params = new URLSearchParams({
            domain: target.domain,
            path: target.path,
            build_if_missing: '1',
        });

        const response = await apiRequest(`import-executor/dry-run?${params.toString()}`);
        dbvc_cc_reset_import_execution_state();
        mappingState.importExecutorDryRun = response || null;
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    async function dbvc_cc_load_import_run_detail(runId, options = {}) {
        const normalizedRunId = dbvc_cc_normalize_run_id(runId);
        if (normalizedRunId <= 0) {
            mappingState.importSelectedRunId = 0;
            mappingState.importRunDetail = null;
            dbvc_cc_update_mapping_summaries();
            return null;
        }

        const params = new URLSearchParams({
            run_id: String(normalizedRunId),
        });
        const response = await apiRequest(`import-executor/run?${params.toString()}`);
        mappingState.importSelectedRunId = normalizedRunId;
        mappingState.importRunDetail = response || null;
        mappingState.importSelectedActionId = 0;
        if (!options.skipSummaryRefresh) {
            dbvc_cc_update_mapping_summaries();
        }
        return response;
    }

    async function dbvc_cc_load_import_run_history(options = {}) {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            mappingState.importRunHistory = null;
            mappingState.importRunDetail = null;
            mappingState.importSelectedRunId = 0;
            dbvc_cc_update_mapping_summaries();
            return null;
        }

        const params = new URLSearchParams({
            domain: target.domain,
            path: target.path,
            limit: String(Number(options.limit || 12)),
        });
        const response = await apiRequest(`import-executor/runs?${params.toString()}`);
        const runs = response && Array.isArray(response.runs) ? response.runs : [];
        const requestedRunId = dbvc_cc_normalize_run_id(options.selectRunId);
        const preservedRunId = dbvc_cc_normalize_run_id(options.preserveSelection ? mappingState.importSelectedRunId : 0);
        const fallbackRunId = runs.length > 0 ? dbvc_cc_normalize_run_id(runs[0].id || 0) : 0;
        let nextSelectedRunId = requestedRunId || preservedRunId;

        if (nextSelectedRunId > 0 && !runs.some((run) => dbvc_cc_normalize_run_id(run && run.id ? run.id : 0) === nextSelectedRunId)) {
            nextSelectedRunId = 0;
        }
        if (nextSelectedRunId <= 0) {
            nextSelectedRunId = fallbackRunId;
        }

        mappingState.importRunHistory = response || { runs: [] };
        mappingState.importSelectedRunId = nextSelectedRunId;

        if (nextSelectedRunId > 0) {
            return dbvc_cc_load_import_run_detail(nextSelectedRunId);
        }

        mappingState.importRunDetail = null;
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    async function dbvc_cc_refresh_import_run_history_safely(options = {}) {
        try {
            return await dbvc_cc_load_import_run_history(options);
        } catch (error) {
            mappingState.importRunHistory = {
                generated_at: '',
                runs: [],
                load_error: error && error.message ? String(error.message) : 'Failed to load import run history.',
            };
            mappingState.importRunDetail = null;
            mappingState.importSelectedRunId = 0;
            dbvc_cc_update_mapping_summaries();
            if (!options.silent) {
                throw error;
            }
            return null;
        }
    }

    async function dbvc_cc_approve_import_preflight() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const response = await apiRequest('import-executor/preflight-approve', 'POST', {
            domain: target.domain,
            path: target.path,
            build_if_missing: true,
            confirm_approval: true,
        });

        mappingState.importPreflightApproval = response || null;
        mappingState.importExecuteSkeleton = null;
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    async function dbvc_cc_run_import_execute_skeleton() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const approvalToken = mappingState.importPreflightApproval
            && mappingState.importPreflightApproval.approval
            && mappingState.importPreflightApproval.approval.approval_token
            ? String(mappingState.importPreflightApproval.approval.approval_token)
            : '';

        const response = await apiRequest('import-executor/execute', 'POST', {
            domain: target.domain,
            path: target.path,
            build_if_missing: true,
            confirm_execute: true,
            approval_token: approvalToken,
        });

        mappingState.importExecuteSkeleton = response || null;
        mappingState.importRecovery = null;
        if (response && response.preflight_approval) {
            mappingState.importPreflightApproval = {
                ...(mappingState.importPreflightApproval || {}),
                ...response.preflight_approval,
            };
        }
        await dbvc_cc_refresh_import_run_history_safely({
            selectRunId: response && response.run_id ? response.run_id : 0,
            silent: true,
        });
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    async function dbvc_cc_run_import_rollback(runId = 0) {
        const normalizedRunId = dbvc_cc_normalize_run_id(
            runId || (
                mappingState.importExecuteSkeleton && mappingState.importExecuteSkeleton.run_id
                    ? mappingState.importExecuteSkeleton.run_id
                    : 0
            )
        );
        if (normalizedRunId <= 0) {
            throw new Error('Run execute before rollback is available.');
        }

        const response = await apiRequest('import-executor/rollback', 'POST', {
            run_id: normalizedRunId,
        });

        mappingState.importRecovery = response || null;
        if (mappingState.importExecuteSkeleton) {
            const executeRunId = dbvc_cc_normalize_run_id(mappingState.importExecuteSkeleton.run_id || 0);
            if (executeRunId === normalizedRunId) {
                mappingState.importExecuteSkeleton.rollback_available = false;
                mappingState.importExecuteSkeleton.rollback_status = response && response.run && response.run.rollback_status
                    ? String(response.run.rollback_status)
                    : 'completed';
            }
        }
        await dbvc_cc_refresh_import_run_history_safely({
            selectRunId: response && response.run && response.run.id ? response.run.id : normalizedRunId,
            silent: true,
        });
        dbvc_cc_update_mapping_summaries();
        return response;
    }

    function dbvc_cc_build_mapping_decision_payload() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const approvedMappings = [];
        const overrides = [];
        const rejections = [];
        const unresolvedFields = [];

        $mappingSectionsTableBody.find('tr[data-section-id]').each(function() {
            const $row = $(this);
            const sectionId = String($row.data('section-id') || '');
            const $targetSelect = $row.find('.dbvc-cc-map-section-target');
            const selectedTarget = String($targetSelect.val() || '').trim();
            const overrideTarget = String($row.find('.dbvc-cc-map-section-override').val() || '').trim();
            const ignored = $row.find('.dbvc-cc-map-section-ignore').is(':checked');
            const selectedOption = $targetSelect.find('option:selected');
            const candidateId = String(selectedOption.data('candidate-id') || '');
            const candidateConfidence = Number(selectedOption.data('confidence') || 0);
            const reason = String(selectedOption.data('reason') || 'deterministic');
            const resolvedTarget = overrideTarget || selectedTarget;

            if (!sectionId) {
                return;
            }

            if (ignored) {
                rejections.push({
                    section_id: sectionId,
                    reason: 'ignored_by_reviewer',
                });
                return;
            }

            if (!resolvedTarget) {
                unresolvedFields.push({
                    section_id: sectionId,
                    reason: 'missing_target_ref',
                });
                return;
            }

            approvedMappings.push({
                section_id: sectionId,
                candidate_id: candidateId,
                target_ref: resolvedTarget,
                confidence: candidateConfidence,
                reason: reason,
            });

            if (overrideTarget && overrideTarget !== selectedTarget) {
                overrides.push({
                    section_id: sectionId,
                    target_ref: selectedTarget,
                    override_target_ref: overrideTarget,
                    reason: 'manual_override',
                });
            }
        });

        const catalogFingerprint = mappingState.sectionCandidates && mappingState.sectionCandidates.catalog_fingerprint
            ? String(mappingState.sectionCandidates.catalog_fingerprint)
            : (mappingState.catalog && mappingState.catalog.catalog_fingerprint ? String(mappingState.catalog.catalog_fingerprint) : '');
        const objectPostTypeOverride = dbvc_cc_normalize_post_type($mappingObjectPostType.val() || '');

        return {
            domain: target.domain,
            path: target.path,
            catalog_fingerprint: catalogFingerprint,
            decision_status: String($mappingDecisionStatus.val() || 'pending'),
            approved_mappings: approvedMappings,
            approved_media_mappings: [],
            overrides: overrides,
            rejections: rejections,
            unresolved_fields: unresolvedFields,
            unresolved_media: [],
            object_hints: {
                override_post_type: objectPostTypeOverride,
            },
        };
    }

    function dbvc_cc_build_media_decision_payload() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            throw new Error(target.message);
        }

        const approved = [];
        const overrides = [];
        const ignored = [];
        const conflicts = [];

        $mappingMediaTableBody.find('tr[data-media-id]').each(function() {
            const $row = $(this);
            const mediaId = String($row.data('media-id') || '');
            const mediaKind = String($row.data('media-kind') || 'file');
            const sourceUrl = String($row.data('source-url') || '');
            const selectedTarget = String($row.find('.dbvc-cc-map-media-target').val() || '').trim();
            const overrideTarget = String($row.find('.dbvc-cc-map-media-override').val() || '').trim();
            const isIgnored = $row.find('.dbvc-cc-map-media-ignore').is(':checked');
            const resolvedTarget = overrideTarget || selectedTarget;

            if (!mediaId) {
                return;
            }

            if (isIgnored) {
                ignored.push({
                    media_id: mediaId,
                    reason: 'ignored_by_reviewer',
                });
                return;
            }

            if (!resolvedTarget) {
                conflicts.push({
                    media_id: mediaId,
                    reason: 'missing_target_ref',
                });
                return;
            }

            approved.push({
                media_id: mediaId,
                target_ref: resolvedTarget,
                media_kind: mediaKind,
                source_url: sourceUrl,
            });

            if (overrideTarget && overrideTarget !== selectedTarget) {
                overrides.push({
                    media_id: mediaId,
                    target_ref: selectedTarget,
                    override_target_ref: overrideTarget,
                    reason: 'manual_override',
                });
            }
        });

        const catalogFingerprint = mappingState.mediaCandidates && mappingState.mediaCandidates.catalog_fingerprint
            ? String(mappingState.mediaCandidates.catalog_fingerprint)
            : (mappingState.catalog && mappingState.catalog.catalog_fingerprint ? String(mappingState.catalog.catalog_fingerprint) : '');

        return {
            domain: target.domain,
            path: target.path,
            catalog_fingerprint: catalogFingerprint,
            decision_status: String($mappingMediaDecisionStatus.val() || 'pending'),
            approved: approved,
            overrides: overrides,
            ignored: ignored,
            conflicts: conflicts,
        };
    }

    async function dbvc_cc_load_mapping_package() {
        if (!dbvc_cc_mapping_bridge_enabled) {
            dbvc_cc_set_mapping_status('Mapping bridge feature flags are disabled.', true);
            return;
        }

        dbvc_cc_reset_import_execution_state({
            clearDryRun: true,
            clearPlan: true,
            clearHandoff: true,
        });
        dbvc_cc_set_mapping_status('Loading mapping package...');
        try {
            await dbvc_cc_load_catalog(false);
            await dbvc_cc_load_section_candidates(true);
            await dbvc_cc_load_media_candidates(true);
            await dbvc_cc_load_decisions();
            await dbvc_cc_load_handoff_payload();
            await dbvc_cc_load_import_plan_dry_run();
            dbvc_cc_set_mapping_status('Mapping package loaded.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to load mapping package.', true);
        }
    }

    async function dbvc_cc_save_mapping_decision() {
        if (!dbvc_cc_mapping_bridge_enabled) {
            dbvc_cc_set_mapping_status('Mapping bridge feature flags are disabled.', true);
            return;
        }

        dbvc_cc_set_mapping_status('Saving mapping decision...');
        try {
            const payload = dbvc_cc_build_mapping_decision_payload();
            const response = await apiRequest('mapping/decision', 'POST', payload);
            mappingState.mappingDecision = response && response.mapping_decision ? response.mapping_decision : null;
            dbvc_cc_reset_import_execution_state({
                clearDryRun: true,
                clearPlan: true,
                clearHandoff: true,
            });
            dbvc_cc_update_mapping_summaries();
            dbvc_cc_set_mapping_status('Mapping decision saved.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to save mapping decision.', true);
        }
    }

    async function dbvc_cc_save_media_decision() {
        if (!dbvc_cc_media_mapping_bridge_enabled) {
            dbvc_cc_set_mapping_status('Media mapping bridge is disabled. Text-only mapping remains available.', true);
            return;
        }

        dbvc_cc_set_mapping_status('Saving media decision...');
        try {
            const payload = dbvc_cc_build_media_decision_payload();
            const response = await apiRequest('mapping/media/decision', 'POST', payload);
            mappingState.mediaDecision = response && response.media_decision ? response.media_decision : null;
            dbvc_cc_reset_import_execution_state({
                clearDryRun: true,
                clearPlan: true,
                clearHandoff: true,
            });
            dbvc_cc_update_mapping_summaries();
            dbvc_cc_set_mapping_status('Media decision saved.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to save media decision.', true);
        }
    }

    async function dbvc_cc_fetch_mapping_rebuild_batch_status(batchId) {
        const params = new URLSearchParams({
            batch_id: String(batchId || ''),
        });

        return apiRequest(`mapping/domain/rebuild/status?${params.toString()}`);
    }

    async function dbvc_cc_poll_mapping_rebuild_batch(batchId, options = {}) {
        const pollDelayMs = Number(options.pollDelayMs || 1500);
        const maxPolls = Number(options.maxPolls || 200);
        let polls = 0;

        while (polls < maxPolls) {
            polls += 1;
            const statusResponse = await dbvc_cc_fetch_mapping_rebuild_batch_status(batchId);
            const totalJobs = Number(statusResponse && statusResponse.total_jobs ? statusResponse.total_jobs : 0);
            const processedJobs = Number(statusResponse && statusResponse.processed_jobs ? statusResponse.processed_jobs : 0);
            const failedJobs = Number(statusResponse && statusResponse.failed_jobs ? statusResponse.failed_jobs : 0);
            const progressPercent = Number(statusResponse && statusResponse.progress_percent ? statusResponse.progress_percent : 0);
            const status = String(statusResponse && statusResponse.status ? statusResponse.status : 'queued');

            if (status === 'completed_with_failures') {
                dbvc_cc_set_mapping_status(`Domain rebuild completed with issues (${processedJobs}/${totalJobs} jobs, ${failedJobs} failed).`, true);
                return statusResponse;
            }

            if (status === 'completed') {
                dbvc_cc_set_mapping_status(`Domain rebuild completed (${processedJobs}/${totalJobs} jobs).`);
                return statusResponse;
            }

            if (status === 'failed') {
                dbvc_cc_set_mapping_status(`Domain rebuild failed after ${processedJobs}/${totalJobs} jobs.`, true);
                return statusResponse;
            }

            dbvc_cc_set_mapping_status(`Rebuilding mapping artifacts for domain... ${progressPercent}% (${processedJobs}/${totalJobs})`);
            await new Promise((resolve) => window.setTimeout(resolve, pollDelayMs));
        }

        throw new Error('Domain rebuild polling timed out. Check batch status and retry.');
    }

    async function dbvc_cc_rebuild_domain_mapping_artifacts() {
        if (!dbvc_cc_mapping_bridge_enabled) {
            dbvc_cc_set_mapping_status('Mapping bridge feature flags are disabled.', true);
            return;
        }

        const dbvc_cc_domain = dbvc_cc_normalize_domain($mappingDomain.val() || $domain.val());
        if (!dbvc_cc_domain) {
            dbvc_cc_set_mapping_status('Select a domain before rebuilding mapping artifacts.', true);
            return;
        }

        const $button = $('#dbvc-cc-mapping-rebuild-domain');
        const originalLabel = String($button.text() || 'Rebuild Domain Mapping Artifacts');
        $button.prop('disabled', true).text('Rebuilding...');

        dbvc_cc_set_mapping_status(`Queueing mapping rebuild for ${dbvc_cc_domain}...`);

        try {
            const response = await apiRequest('mapping/domain/rebuild', 'POST', {
                domain: dbvc_cc_domain,
                refresh_catalog: true,
                force_rebuild: true,
                run_now: false,
                batch_size: 25,
            });

            const batchId = String(response && response.batch_id ? response.batch_id : '');
            if (!batchId) {
                throw new Error('Domain rebuild did not return a batch ID.');
            }

            await dbvc_cc_poll_mapping_rebuild_batch(batchId, {
                pollDelayMs: 1500,
                maxPolls: 240,
            });
            await loadQueue();

            try {
                await dbvc_cc_load_handoff_payload();
                await dbvc_cc_load_import_plan_dry_run();
            } catch (refreshError) {
                // Rebuild completion should still be treated as success even if target-specific refresh fails.
                dbvc_cc_set_mapping_status(`Domain rebuild completed. Mapping preview refresh failed: ${refreshError.message || 'unknown error'}`, true);
            }
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to rebuild domain mapping artifacts.', true);
        } finally {
            $button.prop('disabled', false).text(originalLabel);
        }
    }

    $('#dbvc-cc-workbench-refresh').on('click', function() {
        loadQueue();
    });

    $domain.on('change', function() {
        const dbvc_cc_selected_domain = dbvc_cc_normalize_domain($domain.val());
        dbvc_cc_render_domain_ai_warning();
        if (!dbvc_cc_selected_domain) {
            return;
        }

        const dbvc_cc_current_mapping_domain = dbvc_cc_normalize_domain($mappingDomain.val());
        if (dbvc_cc_current_mapping_domain !== '') {
            return;
        }

        dbvc_cc_set_domain_value($mappingDomain, dbvc_cc_selected_domain);
        dbvc_cc_refresh_import_run_history_safely({
            preserveSelection: false,
            silent: true,
        });
    });

    $domainAiWarning.on('click', function() {
        dbvc_cc_run_warning_domain_refreshes();
    });

    $tbody.on('click', 'tr[data-index]', function() {
        const index = Number($(this).data('index'));
        const item = queueItems[index];
        loadDetail(item, index);
    });

    $tbody.on('click', '.dbvc-cc-queue-rebuild', function(event) {
        event.preventDefault();
        event.stopPropagation();

        const index = Number($(this).data('index'));
        const item = queueItems[index];
        dbvc_cc_rebuild_queue_item_mapping(item);
    });

    $detail.on('click', 'button[data-decision]', function() {
        const decision = String($(this).data('decision') || '');
        if (!decision) {
            return;
        }

        saveDecision(decision);
    });

    $('#dbvc-cc-workbench-open-mapping').on('click', function() {
        if (selected && selected.item) {
            dbvc_cc_seed_mapping_target(selected.item.domain, selected.item.path);
        }

        const mappingSection = document.querySelector('.dbvc-cc-mapping-bridge');
        if (mappingSection && typeof mappingSection.scrollIntoView === 'function') {
            mappingSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    $('#dbvc-cc-mapping-help-open').on('click', function() {
        dbvc_cc_open_mapping_help_modal();
    });

    $('#dbvc-cc-mapping-help-close').on('click', function(event) {
        event.preventDefault();
        dbvc_cc_close_mapping_help_modal();
    });

    $mappingHelpModal.on('click', '.dbvc-cc-modal-backdrop', function() {
        dbvc_cc_close_mapping_help_modal();
    });

    $mappingDomain.on('change', async function() {
        dbvc_cc_reset_import_execution_state({
            clearDryRun: true,
            clearPlan: true,
            clearHandoff: true,
        });
        dbvc_cc_update_mapping_summaries();
        await dbvc_cc_refresh_import_run_history_safely({
            preserveSelection: false,
            silent: true,
        });
    });

    $mappingPath.on('change', async function() {
        dbvc_cc_reset_import_execution_state({
            clearDryRun: true,
            clearPlan: true,
            clearHandoff: true,
        });
        dbvc_cc_update_mapping_summaries();
        await dbvc_cc_refresh_import_run_history_safely({
            preserveSelection: false,
            silent: true,
        });
    });

    $mappingObjectPostType.on('change', function() {
        dbvc_cc_reset_import_execution_state({
            clearDryRun: true,
            clearPlan: true,
            clearHandoff: true,
        });
        dbvc_cc_update_mapping_summaries();
    });

    $(document).on('keydown', function(event) {
        if (event.key !== 'Escape') {
            return;
        }

        if ($mappingHelpModal.length === 0 || $mappingHelpModal.hasClass('dbvc-cc-hidden')) {
            return;
        }

        dbvc_cc_close_mapping_help_modal();
    });

    $('#dbvc-cc-mapping-load-package').on('click', function() {
        dbvc_cc_load_mapping_package();
    });

    $('#dbvc-cc-mapping-refresh-run-history').on('click', async function() {
        const target = dbvc_cc_get_mapping_target();
        if (!target.valid) {
            dbvc_cc_set_mapping_status(target.message, true);
            return;
        }

        dbvc_cc_set_mapping_status('Refreshing import run history...');
        try {
            await dbvc_cc_refresh_import_run_history_safely({
                preserveSelection: true,
                silent: false,
            });
            dbvc_cc_set_mapping_status('Import run history refreshed.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to load import run history.', true);
        }
    });

    $mappingRunHistoryTableBody.on('click', 'tr[data-run-id]', async function() {
        const runId = dbvc_cc_normalize_run_id($(this).data('run-id'));
        if (runId <= 0) {
            return;
        }

        dbvc_cc_set_mapping_status(`Loading import run #${runId}...`);
        try {
            await dbvc_cc_load_import_run_detail(runId);
            dbvc_cc_set_mapping_status(`Import run #${runId} loaded.`);
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to load import run detail.', true);
        }
    });

    $mappingRunActionsTableBody.on('click', 'tr[data-action-id]', function() {
        const actionId = Number($(this).data('action-id') || 0);
        if (actionId <= 0) {
            return;
        }

        mappingState.importSelectedActionId = actionId;
        dbvc_cc_render_import_run_detail();
    });

    $mappingRunFilterStage.on('change', function() {
        mappingState.importRunActionFilters.stage = String($(this).val() || '');
        mappingState.importSelectedActionId = 0;
        dbvc_cc_render_import_run_detail();
    });

    $mappingRunFilterExecution.on('change', function() {
        mappingState.importRunActionFilters.execution = String($(this).val() || '');
        mappingState.importSelectedActionId = 0;
        dbvc_cc_render_import_run_detail();
    });

    $mappingRunFilterRollback.on('change', function() {
        mappingState.importRunActionFilters.rollback = String($(this).val() || '');
        mappingState.importSelectedActionId = 0;
        dbvc_cc_render_import_run_detail();
    });

    $mappingRunFilterFailedOnly.on('change', function() {
        mappingState.importRunActionFilters.failedOnly = $(this).is(':checked');
        mappingState.importSelectedActionId = 0;
        dbvc_cc_render_import_run_detail();
    });

    $('#dbvc-cc-mapping-download-run-report').on('click', function() {
        const detail = mappingState.importRunDetail && typeof mappingState.importRunDetail === 'object'
            ? mappingState.importRunDetail
            : null;
        const run = detail && detail.run && typeof detail.run === 'object' ? detail.run : null;
        if (!run || dbvc_cc_normalize_run_id(run.id) <= 0) {
            dbvc_cc_set_mapping_status('Select an import run before downloading a report.', true);
            return;
        }

        const reportPayload = {
            generated_at: new Date().toISOString(),
            report_type: 'dbvc_cc_import_run_report',
            run: detail.run,
            actions: Array.isArray(detail.actions) ? detail.actions : [],
        };
        const blob = new Blob([JSON.stringify(reportPayload, null, 2)], { type: 'application/json' });
        const downloadUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = `dbvc-cc-import-run-${dbvc_cc_normalize_run_id(run.id)}.json`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(downloadUrl);
        dbvc_cc_set_mapping_status(`Downloaded JSON report for import run #${dbvc_cc_normalize_run_id(run.id)}.`);
    });

    $('#dbvc-cc-mapping-build-catalog').on('click', async function() {
        dbvc_cc_set_mapping_status('Building catalog...');
        try {
            await dbvc_cc_load_catalog(false);
            dbvc_cc_set_mapping_status('Catalog built/reused.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to build catalog.', true);
        }
    });

    $('#dbvc-cc-mapping-refresh-catalog').on('click', async function() {
        dbvc_cc_set_mapping_status('Refreshing catalog...');
        try {
            await dbvc_cc_load_catalog(true);
            dbvc_cc_set_mapping_status('Catalog refreshed.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to refresh catalog.', true);
        }
    });

    $('#dbvc-cc-mapping-rebuild-domain').on('click', function() {
        dbvc_cc_rebuild_domain_mapping_artifacts();
    });

    $('#dbvc-cc-mapping-load-decisions').on('click', async function() {
        dbvc_cc_set_mapping_status('Loading decisions...');
        try {
            await dbvc_cc_load_decisions();
            dbvc_cc_set_mapping_status('Decisions loaded.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to load decisions.', true);
        }
    });

    $('#dbvc-cc-mapping-load-handoff').on('click', async function() {
        dbvc_cc_set_mapping_status('Loading Phase 4 handoff payload...');
        try {
            await dbvc_cc_load_handoff_payload();
            dbvc_cc_set_mapping_status('Phase 4 handoff payload loaded.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to load handoff payload.', true);
        }
    });

    $('#dbvc-cc-mapping-generate-dry-run-plan').on('click', async function() {
        dbvc_cc_set_mapping_status('Generating dry-run import plan...');
        try {
            await dbvc_cc_load_import_plan_dry_run();
            dbvc_cc_set_mapping_status('Dry-run import plan generated.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to generate dry-run import plan.', true);
        }
    });

    $('#dbvc-cc-mapping-run-executor-dry-run').on('click', async function() {
        if (!dbvc_cc_import_executor_enabled) {
            dbvc_cc_set_mapping_status('Import executor dry-run is currently disabled by feature flags.', true);
            return;
        }

        dbvc_cc_set_mapping_status('Running import executor dry-run...');
        try {
            await dbvc_cc_load_import_executor_dry_run();
            dbvc_cc_set_mapping_status('Import executor dry-run completed.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to run import executor dry-run.', true);
        }
    });

    $('#dbvc-cc-mapping-approve-import').on('click', async function() {
        if (!dbvc_cc_import_executor_enabled) {
            dbvc_cc_set_mapping_status('Import execute approval is currently disabled by feature flags.', true);
            return;
        }

        const dbvc_cc_approval_confirmed = window.confirm(
            'Approve this dry-run for execution? Execute will remain limited to the current dry-run fingerprint and will require a fresh approval again if the mapping target or dry-run changes.'
        );
        if (!dbvc_cc_approval_confirmed) {
            dbvc_cc_set_mapping_status('Preflight approval canceled.');
            return;
        }

        dbvc_cc_set_mapping_status('Approving import preflight...');
        try {
            await dbvc_cc_approve_import_preflight();
            dbvc_cc_set_mapping_status('Preflight approval granted for the current dry-run fingerprint.');
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to approve the current dry-run for execute.', true);
        }
    });

    $('#dbvc-cc-mapping-run-execute-skeleton').on('click', async function() {
        const dbvc_cc_execute_confirmed = window.confirm(
            'Run guarded execute now? This requires the active approval token, will create or update entity records, apply mapped field values, and execute supported media writes for the selected domain/path.'
        );
        if (!dbvc_cc_execute_confirmed) {
            dbvc_cc_set_mapping_status('Phase 4 execute canceled.');
            return;
        }

        dbvc_cc_set_mapping_status('Running guarded execute...');
        try {
            await dbvc_cc_run_import_execute_skeleton();
            const dbvc_cc_execute_status = mappingState.importExecuteSkeleton && mappingState.importExecuteSkeleton.status
                ? String(mappingState.importExecuteSkeleton.status)
                : 'unknown';
            dbvc_cc_set_mapping_status(`Phase 4 execute finished with status: ${dbvc_cc_execute_status}.`);
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to run execute.', true);
        }
    });

    $('#dbvc-cc-mapping-rollback-run').on('click', async function() {
        const runId = Number(
            mappingState.importExecuteSkeleton && mappingState.importExecuteSkeleton.run_id
                ? mappingState.importExecuteSkeleton.run_id
                : 0
        );
        const dbvc_cc_rollback_confirmed = window.confirm(
            `Rollback the latest import run now? This will restore journaled entity, field, and supported media writes for run #${runId}.`
        );
        if (!dbvc_cc_rollback_confirmed) {
            dbvc_cc_set_mapping_status('Rollback canceled.');
            return;
        }

        dbvc_cc_set_mapping_status(`Rolling back import run #${runId}...`);
        try {
            const response = await dbvc_cc_run_import_rollback(runId);
            const rollbackStatus = response && response.status ? String(response.status) : 'unknown';
            dbvc_cc_set_mapping_status(`Rollback finished with status: ${rollbackStatus}.`);
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to roll back the latest import run.', true);
        }
    });

    $('#dbvc-cc-mapping-rollback-selected-run').on('click', async function() {
        const runId = dbvc_cc_normalize_run_id(
            mappingState.importRunDetail && mappingState.importRunDetail.run && mappingState.importRunDetail.run.id
                ? mappingState.importRunDetail.run.id
                : 0
        );
        if (runId <= 0) {
            dbvc_cc_set_mapping_status('Select a run before attempting rollback.', true);
            return;
        }

        const confirmed = window.confirm(
            `Rollback the selected import run now? This will restore journaled entity, field, and supported media writes for run #${runId}.`
        );
        if (!confirmed) {
            dbvc_cc_set_mapping_status('Selected run rollback canceled.');
            return;
        }

        dbvc_cc_set_mapping_status(`Rolling back selected import run #${runId}...`);
        try {
            const response = await dbvc_cc_run_import_rollback(runId);
            const rollbackStatus = response && response.status ? String(response.status) : 'unknown';
            dbvc_cc_set_mapping_status(`Selected run rollback finished with status: ${rollbackStatus}.`);
        } catch (error) {
            dbvc_cc_set_mapping_status(error.message || 'Failed to roll back the selected import run.', true);
        }
    });

    $('#dbvc-cc-mapping-save-decision').on('click', function() {
        dbvc_cc_save_mapping_decision();
    });

    $('#dbvc-cc-mapping-save-media-decision').on('click', function() {
        dbvc_cc_save_media_decision();
    });

    $mappingSectionsTableBody.on('change', '.dbvc-cc-map-section-target', function() {
        dbvc_cc_sync_target_select_titles($(this).closest('tr'));
    });

    $mappingMediaTableBody.on('change', '.dbvc-cc-map-media-target', function() {
        dbvc_cc_sync_target_select_titles($(this).closest('tr'));
    });

    dbvc_cc_reset_mapping_ui_state();
    dbvc_cc_update_mapping_availability();
    dbvc_cc_render_sections_table();
    dbvc_cc_render_media_table();
    dbvc_cc_update_mapping_summaries();

    async function dbvc_cc_initialize() {
        await dbvc_cc_load_available_domains();
        await loadQueue();

        if (dbvc_cc_mapping_bridge_enabled) {
            await dbvc_cc_refresh_import_run_history_safely({
                preserveSelection: false,
                silent: true,
            });
        }

        if (prefill.domain && prefill.path && dbvc_cc_mapping_bridge_enabled) {
            dbvc_cc_load_mapping_package();
        }

        window.setTimeout(dbvc_cc_probe_mapping_interactivity, 0);
    }

    dbvc_cc_initialize();
});
