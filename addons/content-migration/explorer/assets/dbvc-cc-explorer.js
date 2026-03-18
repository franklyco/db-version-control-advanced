jQuery(function($) {
    const config = window.dbvc_cc_explorer_object || {};
    const restBase = config.rest_base || '';
    const nonce = config.nonce || '';
    const defaults = config.defaults || {};
    const capabilities = config.capabilities || {};
    const aiEnabled = !!capabilities.ai;
    const workbenchEnabled = !!capabilities.workbench;
    const mappingCatalogBridgeEnabled = !!capabilities.mapping_catalog_bridge;
    const mediaMappingBridgeEnabled = !!capabilities.media_mapping_bridge;
    const mappingBridgeEnabled = mappingCatalogBridgeEnabled && mediaMappingBridgeEnabled;
    const workbenchUrl = String(config.workbench_url || '');
    const exportEnabled = !!capabilities.export;

    const state = {
        cy: null,
        domain: '',
        dbvc_cc_domain_ai_health_by_key: {},
        selectedPath: '',
        selectedType: '',
        selectedHasJson: false,
        selectedNodeId: '',
        selectedSourceUrl: '',
        selectedCanonicalUrl: '',
        loadedChildren: new Set(),
        aiPollTimer: null,
        aiJobId: '',
        aiBatchPollTimer: null,
        aiBatchId: '',
        collapsedRoots: new Set(),
        isolatedRootId: '',
        view: {
            layout: 'pyramid',
            nodeType: 'all',
            cpt: 'all',
            collapseLevel: 0,
            search: '',
        },
    };

    const $domain = $('#cc-explorer-domain');
    const $domainAiWarning = $('#cc-explorer-domain-ai-warning');
    const $depth = $('#cc-explorer-depth');
    const $maxNodes = $('#cc-explorer-max-nodes');
    const $viewLayout = $('#cc-view-layout');
    const $viewNodeType = $('#cc-view-node-type');
    const $viewCpt = $('#cc-view-cpt');
    const $viewCollapseLevel = $('#cc-view-collapse-level');
    const $viewSearch = $('#cc-view-search');
    const $viewSearchClear = $('#cc-view-search-clear');
    const $status = $('#cc-explorer-status');
    const $exportStatus = $('#cc-export-status');
    const $inspectorEmpty = $('#cc-explorer-inspector-empty');
    const $inspectorMeta = $('#cc-explorer-inspector-meta');
    const $contentPreview = $('#cc-explorer-content-preview');
    const $contentMode = $('#cc-content-mode');
    const $contentContextArtifact = $('#cc-content-context-artifact');
    const $contentContextLimit = $('#cc-content-context-limit');
    const $contentContextLoadBtn = $('#cc-content-context-load');
    const $contentContextScrubPreviewBtn = $('#cc-content-context-scrub-preview');
    const $contentContextScrubApproveBtn = $('#cc-content-context-scrub-approve');
    const $contentContextScrubStatusBtn = $('#cc-content-context-scrub-status');
    const $contentContextStatus = $('#cc-content-context-status');
    const $contentContextJson = $('#cc-content-context-json');
    const $aiActions = $('#cc-ai-actions');
    const $exportSelectedBtn = $('#cc-export-selected');
    const $rerunAiBtn = $('#cc-rerun-ai');
    const $rerunAiBranchBtn = $('#cc-rerun-ai-branch');
    const $aiBranchStatus = $('#cc-ai-branch-status');
    const $nodeActions = $('#cc-node-actions');
    const $nodeExpandLevels = $('#cc-node-expand-levels');
    const $nodeFocusBtn = $('#cc-node-focus');
    const $nodeFitBranchBtn = $('#cc-node-fit-branch');
    const $nodeIsolateBranchBtn = $('#cc-node-isolate-branch');
    const $nodeClearIsolationBtn = $('#cc-node-clear-isolation');
    const $nodeExpandBranchBtn = $('#cc-node-expand-branch');
    const $nodeToggleBranchBtn = $('#cc-node-toggle-branch');
    const $nodeOpenSourceBtn = $('#cc-node-open-source');
    const $nodeOpenCanonicalBtn = $('#cc-node-open-canonical');
    const $nodeMapImportBtn = $('#cc-node-map-import');

    $depth.val(defaults.depth || 2);
    $maxNodes.val(defaults.max_nodes || 600);
    $viewLayout.val('pyramid');
    $viewNodeType.val('all');
    $viewCpt.val('all');
    $viewCollapseLevel.val(0);
    $viewSearch.val('');
    $nodeExpandLevels.val(2);
    $contentContextArtifact.val('all');
    $contentContextLimit.val(40);

    if (!exportEnabled) {
        $('#cc-export-domain').prop('disabled', true);
        $('#cc-export-selected').prop('disabled', true);
        setExportStatus('Export module is disabled in this phase.');
    }

    if (!aiEnabled) {
        $rerunAiBtn.prop('disabled', true);
        $rerunAiBranchBtn.prop('disabled', true);
        $aiActions.addClass('cc-hidden');
    }

    function setStatus(message, isError = false) {
        $status.text(message || '');
        $status.css('color', isError ? '#d63638' : '#50575e');
    }

    function setExportStatus(message, isError = false, html = false) {
        if (html) {
            $exportStatus.html(message || '');
        } else {
            $exportStatus.text(message || '');
        }
        $exportStatus.css('color', isError ? '#d63638' : '#1d2327');
    }

    function clearAiPoll() {
        if (state.aiPollTimer) {
            clearInterval(state.aiPollTimer);
            state.aiPollTimer = null;
        }
        state.aiJobId = '';
    }

    function clearAiBatchPoll() {
        if (state.aiBatchPollTimer) {
            clearInterval(state.aiBatchPollTimer);
            state.aiBatchPollTimer = null;
        }
        state.aiBatchId = '';
    }

    function formatAiStatus(status, mode) {
        const rawStatus = String(status || '').toLowerCase();
        const statusLabelMap = {
            not_started: 'Not started',
            queued: 'Queued',
            processing: 'Processing',
            done: 'Done',
            fallback_done: 'Done (fallback)',
            failed: 'Failed',
        };
        const modeLabelMap = {
            ai: 'AI',
            fallback: 'Fallback',
            pending: 'Pending',
            failed: 'Failed',
        };

        const statusLabel = statusLabelMap[rawStatus] || (status ? String(status) : 'Unknown');
        const modeLabel = modeLabelMap[String(mode || '').toLowerCase()] || '';
        return modeLabel ? `${statusLabel} (${modeLabel})` : statusLabel;
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function dbvc_cc_get_domain_ai_health(domainKey) {
        const dbvc_cc_key = String(domainKey || '').trim().toLowerCase();
        if (!dbvc_cc_key || !state.dbvc_cc_domain_ai_health_by_key || typeof state.dbvc_cc_domain_ai_health_by_key !== 'object') {
            return null;
        }
        return state.dbvc_cc_domain_ai_health_by_key[dbvc_cc_key] || null;
    }

    function dbvc_cc_domain_has_warning(domainKey) {
        const dbvc_cc_health = dbvc_cc_get_domain_ai_health(domainKey);
        return !!(dbvc_cc_health && dbvc_cc_health.warning_badge);
    }

    function dbvc_cc_build_domain_option_label(domainRecord) {
        const dbvc_cc_domain_key = domainRecord && domainRecord.key ? String(domainRecord.key) : '';
        const dbvc_cc_domain_label = domainRecord && domainRecord.label ? String(domainRecord.label) : dbvc_cc_domain_key;
        if (!dbvc_cc_domain_has_warning(dbvc_cc_domain_key)) {
            return dbvc_cc_domain_label;
        }

        return `${dbvc_cc_domain_label} [AI Warning]`;
    }

    function dbvc_cc_render_domain_ai_warning(domainKey) {
        const dbvc_cc_key = String(domainKey || '').trim().toLowerCase();
        if (!dbvc_cc_key) {
            $domainAiWarning.addClass('cc-hidden').text('').prop('disabled', false).removeAttr('title');
            return;
        }

        const dbvc_cc_health = dbvc_cc_get_domain_ai_health(dbvc_cc_key);
        if (!dbvc_cc_health || !dbvc_cc_health.warning_badge) {
            $domainAiWarning.addClass('cc-hidden').text('').prop('disabled', false).removeAttr('title');
            return;
        }

        const dbvc_cc_message = dbvc_cc_health.warning_message
            ? String(dbvc_cc_health.warning_message)
            : 'AI pass errors detected. Newer content may be missing.';
        $domainAiWarning
            .removeClass('cc-hidden')
            .text('AI Warning: Refresh Domain AI')
            .prop('disabled', false)
            .attr('title', `${dbvc_cc_message} Click to run a full-domain AI refresh.`);
    }

    async function dbvc_cc_run_domain_ai_refresh_from_warning() {
        const dbvc_cc_domain_key = String($domain.val() || '').trim().toLowerCase();
        if (!dbvc_cc_domain_key) {
            setStatus('Select a domain before running AI refresh.', true);
            return;
        }

        if (!dbvc_cc_domain_has_warning(dbvc_cc_domain_key)) {
            setStatus(`No AI warning is currently flagged for ${dbvc_cc_domain_key}.`);
            return;
        }

        $domainAiWarning.prop('disabled', true).text('Refreshing AI...');
        setStatus(`Queueing full-domain AI refresh for ${dbvc_cc_domain_key}...`);

        try {
            const dbvc_cc_response = await apiPost('ai/rerun-branch', {
                domain: dbvc_cc_domain_key,
                path: '',
                run_now: false,
                max_jobs: 0,
            });

            const dbvc_cc_batch_id = dbvc_cc_response && dbvc_cc_response.batch_id ? String(dbvc_cc_response.batch_id) : '';
            const dbvc_cc_total_jobs = Number(dbvc_cc_response && dbvc_cc_response.total_jobs ? dbvc_cc_response.total_jobs : 0);
            setStatus(
                dbvc_cc_batch_id
                    ? `Domain AI refresh queued for ${dbvc_cc_domain_key} (${dbvc_cc_total_jobs} jobs, batch ${dbvc_cc_batch_id}).`
                    : `Domain AI refresh queued for ${dbvc_cc_domain_key}.`
            );

            await loadDomains();
            $domain.val(dbvc_cc_domain_key);
            dbvc_cc_render_domain_ai_warning(dbvc_cc_domain_key);
            setStatus(
                dbvc_cc_batch_id
                    ? `Domain AI refresh queued for ${dbvc_cc_domain_key} (${dbvc_cc_total_jobs} jobs, batch ${dbvc_cc_batch_id}).`
                    : `Domain AI refresh queued for ${dbvc_cc_domain_key}.`
            );
        } catch (error) {
            setStatus(error && error.message ? error.message : 'Failed to queue domain AI refresh.', true);
            dbvc_cc_render_domain_ai_warning(dbvc_cc_domain_key);
        }
    }

    function normalizeUrl(value) {
        const url = String(value || '').trim();
        if (!url) {
            return '';
        }
        if (/^https?:\/\//i.test(url)) {
            return url;
        }
        return '';
    }

    function setInspectorLink(selector, url, fallback = 'n/a') {
        const safeUrl = normalizeUrl(url);
        const $el = $(selector);
        if (!safeUrl) {
            $el.text(fallback);
            return;
        }

        const $link = $('<a>')
            .attr('href', safeUrl)
            .attr('target', '_blank')
            .attr('rel', 'noopener noreferrer')
            .text(safeUrl);
        $el.empty().append($link);
    }

    function dbvc_cc_normalize_mode_label(value) {
        return String(value || '')
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function dbvc_cc_render_badges(containerSelector, badges) {
        const $container = $(containerSelector).empty();
        const items = Array.isArray(badges) ? badges : [];
        if (items.length === 0) {
            $container.text('n/a');
            return;
        }

        items.forEach((badge) => {
            const key = badge && badge.key ? String(badge.key) : 'badge';
            const rawLabel = badge && badge.label ? String(badge.label) : '';
            if (!rawLabel) {
                return;
            }

            const $badge = $('<span></span>')
                .addClass('cc-mode-badge')
                .addClass(`cc-mode-badge-${key}`)
                .text(dbvc_cc_normalize_mode_label(rawLabel));
            $container.append($badge);
        });

        if ($container.children().length === 0) {
            $container.text('n/a');
        }
    }

    function dbvc_cc_normalize_limit(value, fallback = 40) {
        const parsed = parseInt(value, 10);
        if (Number.isNaN(parsed)) {
            return fallback;
        }
        return Math.max(5, Math.min(200, parsed));
    }

    function dbvc_cc_set_content_context_status(message, isError = false) {
        $contentContextStatus.text(message || '');
        $contentContextStatus.css('color', isError ? '#d63638' : '#50575e');
    }

    async function dbvc_cc_load_content_context(pathOverride = '') {
        const targetPath = String(pathOverride || state.selectedPath || '');
        if (!state.domain || !targetPath) {
            dbvc_cc_set_content_context_status('Select a page or section node to inspect content context.', true);
            $contentContextJson.text('');
            return;
        }

        const artifact = String($contentContextArtifact.val() || 'all');
        const limit = dbvc_cc_normalize_limit($contentContextLimit.val(), 40);
        $contentContextLimit.val(limit);
        dbvc_cc_set_content_context_status(`Loading ${artifact} context...`);

        const response = await apiGet('explorer/content-context', {
            domain: state.domain,
            path: targetPath,
            artifact: artifact,
            limit: limit,
        });

        const viewModel = {
            domain: response && response.domain ? response.domain : state.domain,
            path: response && response.path ? response.path : targetPath,
            artifact: response && response.artifact ? response.artifact : artifact,
            limit: response && response.limit ? response.limit : limit,
            phase36: response && response.phase36 ? response.phase36 : {},
            payload: response && response.payload ? response.payload : {},
        };
        $contentContextJson.text(JSON.stringify(viewModel, null, 2));

        const payloadCount = viewModel.payload && typeof viewModel.payload === 'object'
            ? Object.keys(viewModel.payload).length
            : 0;
        dbvc_cc_set_content_context_status(`Loaded ${payloadCount} context payload block(s) for ${targetPath}.`);
    }

    async function dbvc_cc_load_scrub_policy_preview(pathOverride = '') {
        const targetPath = String(pathOverride || state.selectedPath || '');
        if (!state.domain || !targetPath) {
            dbvc_cc_set_content_context_status('Select a page node to preview scrub policy suggestions.', true);
            $contentContextJson.text('');
            return;
        }

        const sampleSize = dbvc_cc_normalize_limit($contentContextLimit.val(), 40);
        $contentContextLimit.val(sampleSize);
        dbvc_cc_set_content_context_status('Loading deterministic scrub preview...');

        const response = await apiGet('explorer/scrub-policy-preview', {
            domain: state.domain,
            path: targetPath,
            sample_size: sampleSize,
        });
        $contentContextJson.text(JSON.stringify(response || {}, null, 2));
        dbvc_cc_set_content_context_status(`Loaded scrub preview for ${targetPath}.`);
    }

    async function dbvc_cc_load_scrub_policy_approval_status(pathOverride = '') {
        const targetPath = String(pathOverride || state.selectedPath || '');
        const params = {};
        if (state.domain) {
            params.domain = state.domain;
        }
        if (targetPath) {
            params.path = targetPath;
        }

        dbvc_cc_set_content_context_status('Loading scrub approval status...');
        const response = await apiGet('explorer/scrub-policy-approval-status', params);
        $contentContextJson.text(JSON.stringify(response || {}, null, 2));
        dbvc_cc_set_content_context_status('Loaded scrub approval status.');
    }

    async function dbvc_cc_approve_scrub_policy_suggestions(pathOverride = '') {
        const targetPath = String(pathOverride || state.selectedPath || '');
        if (!state.domain || !targetPath) {
            dbvc_cc_set_content_context_status('Select a page node to approve scrub suggestions.', true);
            return;
        }

        const sampleSize = dbvc_cc_normalize_limit($contentContextLimit.val(), 40);
        $contentContextLimit.val(sampleSize);
        dbvc_cc_set_content_context_status('Applying approved scrub suggestions to Configure defaults...');

        const response = await apiPost('explorer/scrub-policy-approve', {
            domain: state.domain,
            path: targetPath,
            sample_size: sampleSize,
        });
        $contentContextJson.text(JSON.stringify(response || {}, null, 2));
        dbvc_cc_set_content_context_status('Applied scrub suggestions to Configure defaults.');
    }

    function normalizeSearchQuery(value) {
        return String(value || '').trim().toLowerCase();
    }

    function getNodeByPath(path) {
        if (!state.cy || !path) {
            return null;
        }

        const node = state.cy.nodes().filter((item) => (item.data('path') || '') === path).first();
        if (node && node.length) {
            return node;
        }
        return null;
    }

    function sortNodesByDepthAndPath(nodes) {
        return nodes.sort((left, right) => {
            const leftDepth = Number(left.data('depth') || 0);
            const rightDepth = Number(right.data('depth') || 0);
            if (leftDepth !== rightDepth) {
                return leftDepth - rightDepth;
            }
            const leftPath = String(left.data('path') || left.id());
            const rightPath = String(right.data('path') || right.id());
            return leftPath.localeCompare(rightPath);
        });
    }

    function collectDescendants(rootId) {
        if (!state.cy || !rootId) {
            return new Set();
        }

        const visited = new Set();
        const queue = [rootId];
        while (queue.length > 0) {
            const sourceId = queue.shift();
            const outgoing = state.cy.edges().filter((edge) => edge.data('source') === sourceId);
            outgoing.forEach((edge) => {
                const targetId = edge.data('target');
                if (!targetId || visited.has(targetId)) {
                    return;
                }
                visited.add(targetId);
                queue.push(targetId);
            });
        }

        return visited;
    }

    function collectAncestors(nodeId) {
        if (!state.cy || !nodeId) {
            return new Set();
        }

        const visited = new Set();
        const queue = [nodeId];
        while (queue.length > 0) {
            const targetId = queue.shift();
            const incoming = state.cy.edges().filter((edge) => edge.data('target') === targetId);
            incoming.forEach((edge) => {
                const sourceId = edge.data('source');
                if (!sourceId || visited.has(sourceId)) {
                    return;
                }
                visited.add(sourceId);
                queue.push(sourceId);
            });
        }

        return visited;
    }

    function collectBranchViewNodeIds(rootId) {
        const ids = new Set();
        if (!rootId) {
            return ids;
        }

        ids.add(rootId);
        collectDescendants(rootId).forEach((id) => ids.add(id));
        collectAncestors(rootId).forEach((id) => ids.add(id));
        return ids;
    }

    function collectCollapsedHiddenNodeIds() {
        const hidden = new Set();
        state.collapsedRoots.forEach((rootId) => {
            collectDescendants(rootId).forEach((id) => hidden.add(id));
        });
        return hidden;
    }

    function runLayout(layoutMode) {
        if (!state.cy) {
            return;
        }

        const mode = layoutMode || 'pyramid';
        if ('linear_grid' === mode) {
            const visibleNodes = sortNodesByDepthAndPath(state.cy.nodes().filter((node) => node.style('display') !== 'none'));
            const byDepth = {};
            visibleNodes.forEach((node) => {
                const depth = Number(node.data('depth') || 0);
                if (!byDepth[depth]) {
                    byDepth[depth] = [];
                }
                byDepth[depth].push(node);
            });

            const positions = {};
            const depthKeys = Object.keys(byDepth).map((key) => Number(key)).sort((a, b) => a - b);
            const xGap = 220;
            const yGap = 80;

            depthKeys.forEach((depth) => {
                const col = byDepth[depth] || [];
                col.forEach((node, index) => {
                    positions[node.id()] = {
                        x: (depth + 1) * xGap,
                        y: (index + 1) * yGap,
                    };
                });
            });

            state.cy.layout({
                name: 'preset',
                positions: positions,
                fit: true,
                padding: 24,
                animate: false,
            }).run();
            return;
        }

        state.cy.layout({
            name: 'breadthfirst',
            directed: true,
            padding: 16,
            spacingFactor: 1.25,
            animate: false,
        }).run();
    }

    function refreshCptFilterOptions() {
        if (!state.cy) {
            return;
        }

        const current = $viewCpt.val() || 'all';
        const cpts = new Set();
        state.cy.nodes().forEach((node) => {
            if (node.data('type') !== 'page') {
                return;
            }
            const cpt = String(node.data('cpt') || '').trim();
            if (cpt) {
                cpts.add(cpt);
            }
        });

        const sorted = Array.from(cpts).sort((a, b) => a.localeCompare(b));
        $viewCpt.empty();
        $viewCpt.append('<option value="all">All CPTs</option>');
        sorted.forEach((cpt) => {
            $viewCpt.append(`<option value="${escapeHtml(cpt)}">${escapeHtml(cpt)}</option>`);
        });

        if (current !== 'all' && sorted.includes(current)) {
            $viewCpt.val(current);
        } else {
            $viewCpt.val('all');
        }
    }

    function nodeMatchesSearch(node, query) {
        if (!query) {
            return true;
        }

        const haystack = [
            String(node.data('label') || ''),
            String(node.data('path') || ''),
            String(node.data('source_url') || ''),
            String(node.data('canonical_source_url') || ''),
        ].join('\n').toLowerCase();

        return haystack.indexOf(query) !== -1;
    }

    function applyViewState(runLayoutAfter = true) {
        if (!state.cy) {
            return;
        }

        const hiddenByCollapseRoots = collectCollapsedHiddenNodeIds();
        const typeFilter = state.view.nodeType || 'all';
        const cptFilter = state.view.cpt || 'all';
        const collapseLevel = Number(state.view.collapseLevel || 0);
        const searchQuery = normalizeSearchQuery(state.view.search || '');
        let isolatedNodeIds = null;
        if (state.isolatedRootId) {
            const isolatedNode = state.cy.getElementById(state.isolatedRootId);
            if (isolatedNode && isolatedNode.length) {
                isolatedNodeIds = collectBranchViewNodeIds(state.isolatedRootId);
            } else {
                state.isolatedRootId = '';
            }
        }

        const baseVisibleIds = new Set();
        const searchMatchIds = new Set();

        state.cy.nodes().forEach((node) => {
            const type = String(node.data('type') || '');
            const depth = Number(node.data('depth') || 0);
            const id = node.id();
            let visibleInBase = true;

            if ('domain' !== type && hiddenByCollapseRoots.has(id)) {
                visibleInBase = false;
            }

            if (visibleInBase && isolatedNodeIds && !isolatedNodeIds.has(id)) {
                visibleInBase = false;
            }

            if (visibleInBase && collapseLevel > 0 && depth > collapseLevel) {
                visibleInBase = false;
            }

            if (visibleInBase && typeFilter !== 'all' && type !== typeFilter && type !== 'domain') {
                visibleInBase = false;
            }

            if (visibleInBase && cptFilter !== 'all') {
                if (type === 'page') {
                    const cpt = String(node.data('cpt') || '');
                    if (cpt !== cptFilter) {
                        visibleInBase = false;
                    }
                } else if (type !== 'domain') {
                    visibleInBase = false;
                }
            }

            if (visibleInBase) {
                baseVisibleIds.add(id);
                if (searchQuery && nodeMatchesSearch(node, searchQuery)) {
                    searchMatchIds.add(id);
                }
            }
        });

        let searchableVisibleIds = null;
        if (searchQuery) {
            searchableVisibleIds = new Set();
            searchMatchIds.forEach((id) => {
                if (!baseVisibleIds.has(id)) {
                    return;
                }
                searchableVisibleIds.add(id);
                collectAncestors(id).forEach((ancestorId) => {
                    if (baseVisibleIds.has(ancestorId)) {
                        searchableVisibleIds.add(ancestorId);
                    }
                });
            });
        }

        const visibleIds = new Set();
        state.cy.nodes().forEach((node) => {
            const id = node.id();
            const isSearchMatch = searchQuery && searchMatchIds.has(id);
            const isBaseVisible = baseVisibleIds.has(id);
            const passesSearch = !searchQuery || (searchableVisibleIds && searchableVisibleIds.has(id));
            const visible = isBaseVisible && passesSearch;

            node.data('search_match', isSearchMatch ? '1' : '0');
            node.style('display', visible ? 'element' : 'none');
            if (visible) {
                visibleIds.add(id);
            }
        });

        state.cy.edges().forEach((edge) => {
            const source = String(edge.data('source') || '');
            const target = String(edge.data('target') || '');
            const visible = visibleIds.has(source) && visibleIds.has(target);
            edge.style('display', visible ? 'element' : 'none');
        });

        updateNodeActionButtons();

        if (runLayoutAfter) {
            runLayout(state.view.layout);
        }
    }

    function syncViewStateFromControls() {
        const collapseLevelRaw = parseInt($viewCollapseLevel.val(), 10);
        state.view.layout = $viewLayout.val() || 'pyramid';
        state.view.nodeType = $viewNodeType.val() || 'all';
        state.view.cpt = $viewCpt.val() || 'all';
        state.view.collapseLevel = Number.isNaN(collapseLevelRaw) ? 0 : Math.max(0, Math.min(12, collapseLevelRaw));
        state.view.search = normalizeSearchQuery($viewSearch.val() || '');
        $viewCollapseLevel.val(state.view.collapseLevel);
        $viewSearch.val(state.view.search);
    }

    function resetViewControls() {
        state.collapsedRoots = new Set();
        state.isolatedRootId = '';
        state.view = {
            layout: 'pyramid',
            nodeType: 'all',
            cpt: 'all',
            collapseLevel: 0,
            search: '',
        };
        $viewLayout.val(state.view.layout);
        $viewNodeType.val(state.view.nodeType);
        $viewCpt.val(state.view.cpt);
        $viewCollapseLevel.val(state.view.collapseLevel);
        $viewSearch.val(state.view.search);
    }

    function apiGet(endpoint, params = {}) {
        const url = new URL(restBase + endpoint);
        Object.keys(params).forEach((key) => {
            const value = params[key];
            if (value !== undefined && value !== null && value !== '') {
                url.searchParams.set(key, value);
            }
        });

        return fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': nonce,
            },
        }).then(async (response) => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = data && data.message ? data.message : `Request failed (${response.status})`;
                throw new Error(message);
            }
            return data;
        });
    }

    function apiPost(endpoint, body = {}) {
        return fetch(restBase + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(body),
        }).then(async (response) => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = data && data.message ? data.message : `Request failed (${response.status})`;
                throw new Error(message);
            }
            return data;
        });
    }

    function openExternalUrl(rawUrl) {
        const safeUrl = normalizeUrl(rawUrl);
        if (!safeUrl) {
            return false;
        }
        window.open(safeUrl, '_blank', 'noopener');
        return true;
    }

    function updateNodeActionButtons() {
        const selectedNode = state.selectedPath ? getNodeByPath(state.selectedPath) : null;
        const hasSelectedNode = !!(selectedNode && selectedNode.length);
        const selectedType = hasSelectedNode ? String(selectedNode.data('type') || '') : String(state.selectedType || '');
        const hasChildren = hasSelectedNode && Number(selectedNode.data('children_count') || 0) > 0;
        const navigableType = hasSelectedNode && selectedType !== 'file';
        const canMapSelection = mappingBridgeEnabled
            && workbenchEnabled
            && !!workbenchUrl
            && !!state.domain
            && !!state.selectedPath
            && (selectedType === 'page' || selectedType === 'section');

        $nodeActions.toggleClass('cc-hidden', !state.selectedPath);
        $nodeFocusBtn.prop('disabled', !hasSelectedNode);
        $nodeFitBranchBtn.prop('disabled', !hasSelectedNode);
        $nodeIsolateBranchBtn.prop('disabled', !navigableType);
        $nodeClearIsolationBtn.prop('disabled', !state.isolatedRootId);
        $nodeExpandBranchBtn.prop('disabled', !(navigableType && hasChildren));
        $nodeToggleBranchBtn.prop('disabled', !(navigableType && hasChildren));
        $nodeOpenSourceBtn.prop('disabled', !normalizeUrl(state.selectedSourceUrl));
        $nodeOpenCanonicalBtn.prop('disabled', !normalizeUrl(state.selectedCanonicalUrl));
        $nodeMapImportBtn.prop('disabled', !canMapSelection);

        if (hasSelectedNode && state.isolatedRootId === selectedNode.id()) {
            $nodeIsolateBranchBtn.text('Branch Isolated');
        } else {
            $nodeIsolateBranchBtn.text('Isolate Branch');
        }
    }

    function resetSelection() {
        clearAiPoll();
        clearAiBatchPoll();
        state.selectedPath = '';
        state.selectedType = '';
        state.selectedHasJson = false;
        state.selectedNodeId = '';
        state.selectedSourceUrl = '';
        state.selectedCanonicalUrl = '';
        $exportSelectedBtn.prop('disabled', true);
        $rerunAiBtn.prop('disabled', true);
        $rerunAiBranchBtn.prop('disabled', true);
        $aiActions.addClass('cc-hidden');
        $aiBranchStatus.text('');
        updateNodeActionButtons();
    }

    function resetInspector() {
        $inspectorEmpty.removeClass('cc-hidden');
        $inspectorMeta.addClass('cc-hidden');
        $contentPreview.addClass('cc-hidden');
        $nodeActions.addClass('cc-hidden');
        $('#cc-content-headings').empty();
        $('#cc-content-excerpt').empty();
        $('#cc-content-sections').empty();
        $('#cc-content-signals-list').empty();
        $('#cc-content-readiness-status').text('');
        $('#cc-content-readiness-checks').empty();
        $('#cc-content-readiness-notes').empty();
        $('#cc-content-section-count').text('');
        $('#cc-content-diff-summary').text('');
        $('#cc-content-diff-raw').empty();
        $('#cc-content-diff-sanitized').empty();
        $('#cc-content-audit-summary').text('');
        $('#cc-content-audit-events').empty();
        $('#cc-content-title').text('');
        $('#cc-content-ai-status').text('');
        $('#cc-content-ai-post-type').text('');
        $('#cc-content-ai-categories').text('');
        $('#cc-content-ai-summary-text').text('');
        $('#cc-content-phase36-badges').empty();
        $('#cc-content-phase36-availability').text('');
        $('#cc-content-phase36-summary').empty();
        $('#cc-content-phase36-type-counts').empty();
        $('#cc-content-phase36-scrub-totals').empty();
        dbvc_cc_set_content_context_status('');
        $contentContextJson.text('');
        $('#cc-node-source-url').text('');
        $('#cc-node-canonical-url').text('');
        $('#cc-node-mode-badges').text('');
        $('#cc-content-pii').text('');
        $aiBranchStatus.text('');
        resetSelection();
    }

    function updateInspectorMeta(nodeData, detailData) {
        const detailNode = (detailData && detailData.node) ? detailData.node : {};
        const isPageLike = nodeData.type === 'page';
        const hasJson = !!detailNode.json_exists;
        const nextPath = nodeData.path || '';
        const selectedGraphNode = getNodeByPath(nextPath);
        const sourceUrl = detailNode && detailNode.artifact ? normalizeUrl(detailNode.artifact.source_url) : '';
        const canonicalUrl = detailNode && detailNode.artifact ? normalizeUrl(detailNode.artifact.canonical_source_url) : '';
        if (state.selectedPath && state.selectedPath !== nextPath) {
            clearAiPoll();
            clearAiBatchPoll();
            $aiBranchStatus.text('');
        }

        state.selectedPath = nextPath;
        state.selectedType = nodeData.type || '';
        state.selectedHasJson = hasJson;
        state.selectedNodeId = nodeData.id || (selectedGraphNode && selectedGraphNode.length ? selectedGraphNode.id() : '');
        state.selectedSourceUrl = sourceUrl;
        state.selectedCanonicalUrl = canonicalUrl;

        const branchEligible = !!nextPath && nodeData.type !== 'file';
        $exportSelectedBtn.prop('disabled', !exportEnabled || !state.selectedPath);
        $rerunAiBtn.prop('disabled', !aiEnabled || !(isPageLike && hasJson));
        $rerunAiBranchBtn.prop('disabled', !aiEnabled || !branchEligible);
        if (aiEnabled && ((isPageLike && hasJson) || branchEligible)) {
            $aiActions.removeClass('cc-hidden');
        } else {
            $aiActions.addClass('cc-hidden');
        }

        $('#cc-node-label').text(nodeData.label || '');
        $('#cc-node-type').text(nodeData.type || '');
        $('#cc-node-path').text(nodeData.path || '/');
        setInspectorLink('#cc-node-source-url', sourceUrl);
        setInspectorLink('#cc-node-canonical-url', canonicalUrl);
        $('#cc-node-children').text(String(nodeData.children_count || 0));
        $('#cc-node-json').text(hasJson ? 'Yes' : 'No');
        $('#cc-node-images').text(String(detailNode.image_count || nodeData.image_count || 0));
        $('#cc-node-crawl-status').text((detailNode.status && detailNode.status.crawl) ? detailNode.status.crawl : 'unknown');
        const aiStatus = (detailNode.status && detailNode.status.analysis) ? detailNode.status.analysis : 'not_started';
        const aiMode = (detailNode.status && detailNode.status.ai_mode) ? detailNode.status.ai_mode : '';
        $('#cc-node-ai-status').text(formatAiStatus(aiStatus, aiMode));
        dbvc_cc_render_badges('#cc-node-mode-badges', [
            { key: 'capture_mode', label: detailNode && detailNode.status ? detailNode.status.capture_mode : '' },
            { key: 'section_typing_mode', label: detailNode && detailNode.status ? detailNode.status.section_typing_mode : '' },
            { key: 'scrub_profile', label: detailNode && detailNode.status ? detailNode.status.scrub_profile : '' },
        ]);

        $inspectorEmpty.addClass('cc-hidden');
        $inspectorMeta.removeClass('cc-hidden');
        updateNodeActionButtons();
    }

    function updateAuditTrail(auditData) {
        const summary = (auditData && auditData.summary) ? auditData.summary : {};
        const total = Number(summary.total || 0);
        const stageCounts = (summary.stage_counts && typeof summary.stage_counts === 'object') ? summary.stage_counts : {};
        const statusCounts = (summary.status_counts && typeof summary.status_counts === 'object') ? summary.status_counts : {};
        const pipelineCounts = (summary.pipeline_counts && typeof summary.pipeline_counts === 'object') ? summary.pipeline_counts : {};
        const stageSummary = Object.keys(stageCounts).length > 0
            ? Object.entries(stageCounts).map(([stage, count]) => `${stage}: ${count}`).join(', ')
            : 'none';
        const statusSummary = Object.keys(statusCounts).length > 0
            ? Object.entries(statusCounts).map(([status, count]) => `${status}: ${count}`).join(', ')
            : 'none';
        const pipelineSummary = Object.keys(pipelineCounts).length > 0
            ? Object.entries(pipelineCounts).slice(0, 2).map(([pipelineId, count]) => `${pipelineId}: ${count}`).join(', ')
            : 'none';

        $('#cc-content-audit-summary').text(`Events: ${total} | Stages: ${stageSummary} | Status: ${statusSummary} | Pipelines: ${pipelineSummary}`);

        const events = Array.isArray(auditData && auditData.events) ? auditData.events : [];
        const $auditEvents = $('#cc-content-audit-events').empty();
        events.forEach((event) => {
            const timestamp = event && event.timestamp ? String(event.timestamp) : 'unknown-time';
            const stage = event && event.stage ? String(event.stage) : 'unknown';
            const status = event && event.status ? String(event.status) : 'unknown';
            const path = event && event.path ? String(event.path) : '';
            const pipelineId = event && event.pipeline_id ? String(event.pipeline_id) : '';
            const message = event && event.message ? String(event.message) : '';
            const failureCode = event && event.failure_code ? String(event.failure_code) : '';
            const summaryLine = [
                `${timestamp} | ${stage}/${status}`,
                path ? `path: ${path}` : '',
                pipelineId ? `pipeline: ${pipelineId}` : '',
                message,
                failureCode ? `code: ${failureCode}` : '',
            ].filter(Boolean).join(' | ');
            $auditEvents.append($('<li></li>').text(summaryLine));
        });

        if (events.length === 0) {
            $auditEvents.append($('<li></li>').text('No audit events for selected scope.'));
        }
    }

    function updateContentPreview(contentData) {
        const content = (contentData && contentData.content) ? contentData.content : {};
        const pii = (contentData && contentData.pii_flags) ? contentData.pii_flags : {};
        const analysis = (contentData && contentData.analysis) ? contentData.analysis : {};
        const metrics = (contentData && contentData.metrics) ? contentData.metrics : {};
        const readiness = (contentData && contentData.readiness) ? contentData.readiness : {};
        const comparison = (contentData && contentData.comparison) ? contentData.comparison : {};
        const phase36 = (contentData && contentData.phase36) ? contentData.phase36 : {};

        $('#cc-content-title').text(content.title || '');

        const headings = Array.isArray(content.headings) ? content.headings : [];
        const excerpt = Array.isArray(content.text_excerpt) ? content.text_excerpt : [];
        const sections = Array.isArray(content.sections) ? content.sections : [];
        const sectionCount = Number(content.section_count || 0);

        const $headingsList = $('#cc-content-headings').empty();
        headings.slice(0, 12).forEach((heading) => {
            $headingsList.append(`<li>${escapeHtml(heading)}</li>`);
        });
        if (headings.length === 0) {
            $headingsList.append('<li>No headings found.</li>');
        }

        const $excerptList = $('#cc-content-excerpt').empty();
        excerpt.slice(0, 8).forEach((line) => {
            $excerptList.append(`<li>${escapeHtml(line)}</li>`);
        });
        if (excerpt.length === 0) {
            $excerptList.append('<li>No preview text available.</li>');
        }

        const $sectionsRoot = $('#cc-content-sections').empty();
        sections.slice(0, 12).forEach((section, index) => {
            const level = Number(section && section.level ? section.level : 0);
            const heading = section && section.heading ? section.heading : '(intro section)';
            const tag = section && section.heading_tag ? String(section.heading_tag).toUpperCase() : '';
            const textBlocks = Array.isArray(section && section.text_blocks) ? section.text_blocks : [];
            const links = Array.isArray(section && section.links) ? section.links : [];
            const ctas = Array.isArray(section && section.ctas) ? section.ctas : [];
            const images = Array.isArray(section && section.images) ? section.images : [];

            const summaryPrefix = tag ? `${tag}: ` : (level > 0 ? `H${level}: ` : 'Intro: ');
            const summaryText = `${summaryPrefix}${heading}`;

            const $details = $('<details class="cc-section-group"></details>');
            if (index === 0) {
                $details.attr('open', 'open');
            }
            const $summary = $('<summary></summary>').text(summaryText);
            $details.append($summary);

            const $body = $('<div class="cc-section-body"></div>');

            if (textBlocks.length > 0) {
                const $textList = $('<ul></ul>');
                textBlocks.forEach((line) => {
                    $textList.append($('<li></li>').text(`Text: ${String(line)}`));
                });
                $body.append($textList);
            }

            if (links.length > 0) {
                const $linkList = $('<ul></ul>');
                links.forEach((link) => {
                    const text = link && link.text ? String(link.text) : 'Link';
                    const href = normalizeUrl(link && link.url ? link.url : '');
                    const $li = $('<li></li>');
                    $li.append(document.createTextNode(`Link: ${text}`));
                    if (href) {
                        $li.append(document.createTextNode(' - '));
                        $li.append(
                            $('<a></a>')
                                .attr('href', href)
                                .attr('target', '_blank')
                                .attr('rel', 'noopener noreferrer')
                                .text(href)
                        );
                    }
                    $linkList.append($li);
                });
                $body.append($linkList);
            }

            if (ctas.length > 0) {
                const $ctaList = $('<ul></ul>');
                ctas.forEach((cta) => {
                    const text = cta && cta.text ? String(cta.text) : 'CTA';
                    const href = normalizeUrl(cta && cta.url ? cta.url : '');
                    const $li = $('<li></li>');
                    $li.append(document.createTextNode(`CTA: ${text}`));
                    if (href) {
                        $li.append(document.createTextNode(' - '));
                        $li.append(
                            $('<a></a>')
                                .attr('href', href)
                                .attr('target', '_blank')
                                .attr('rel', 'noopener noreferrer')
                                .text(href)
                        );
                    }
                    $ctaList.append($li);
                });
                $body.append($ctaList);
            }

            if (images.length > 0) {
                const $imageList = $('<ul></ul>');
                images.forEach((image) => {
                    const name = image && image.local_filename ? String(image.local_filename) : '';
                    const source = normalizeUrl(image && image.source_url ? image.source_url : '');
                    const alt = image && image.alt ? String(image.alt) : '';
                    const $li = $('<li></li>');
                    $li.append(document.createTextNode(`Image: ${name || '(unnamed)'}`));
                    if (alt) {
                        $li.append(document.createTextNode(` (alt: ${alt})`));
                    }
                    if (source) {
                        $li.append(document.createTextNode(' - '));
                        $li.append(
                            $('<a></a>')
                                .attr('href', source)
                                .attr('target', '_blank')
                                .attr('rel', 'noopener noreferrer')
                                .text(source)
                        );
                    }
                    $imageList.append($li);
                });
                $body.append($imageList);
            }

            if (textBlocks.length === 0 && links.length === 0 && ctas.length === 0 && images.length === 0) {
                $body.append($('<p></p>').text('No content captured in this section.'));
            }

            $details.append($body);
            $sectionsRoot.append($details);
        });
        if (sections.length === 0) {
            $sectionsRoot.append($('<p></p>').text('No grouped sections available.'));
        }
        $('#cc-content-section-count').text(`Sections detected: ${sectionCount}`);

        const piiMessage = `Emails: ${pii.emails_count || 0}, Phones: ${pii.phones_count || 0}, Forms: ${pii.forms_count || 0}, Legal review: ${pii.requires_legal_review ? 'Yes' : 'No'}`;
        $('#cc-content-pii').text(piiMessage);

        const categoriesRaw = Array.isArray(analysis.categories) ? analysis.categories : [];
        const categories = categoriesRaw.map((item) => {
            if (item && typeof item === 'object' && item.slug) {
                return item.slug;
            }
            return String(item || '');
        }).filter(Boolean);
        $('#cc-content-ai-status').text(`Status: ${formatAiStatus(analysis.status || 'not_started', '')}`);
        $('#cc-content-ai-post-type').text(`Post Type: ${analysis.post_type || 'n/a'}${analysis.post_type_confidence != null ? ` (${analysis.post_type_confidence})` : ''}`);
        $('#cc-content-ai-categories').text(`Categories: ${categories.length ? categories.join(', ') : 'n/a'}`);
        $('#cc-content-ai-summary-text').text(`Summary: ${analysis.summary || 'n/a'}`);

        const signals = [
            `Primary H1: ${metrics.primary_h1 || 'n/a'}`,
            `Headings: ${metrics.headings_count != null ? metrics.headings_count : headings.length} (H1: ${metrics.h1_count != null ? metrics.h1_count : 0})`,
            `Text Blocks: ${metrics.text_blocks_count != null ? metrics.text_blocks_count : excerpt.length} | Words: ${metrics.word_count != null ? metrics.word_count : 'n/a'}`,
            `Sections: ${metrics.section_count != null ? metrics.section_count : sectionCount}`,
            `Links/CTAs: ${metrics.links_count != null ? metrics.links_count : 0}/${metrics.ctas_count != null ? metrics.ctas_count : 0}`,
            `Images: ${metrics.images_count != null ? metrics.images_count : (Array.isArray(content.images) ? content.images.length : 0)}`,
        ];
        const $signalsList = $('#cc-content-signals-list').empty();
        signals.forEach((line) => {
            $signalsList.append($('<li></li>').text(line));
        });

        const phase36Badges = Array.isArray(phase36.badges) ? phase36.badges : [];
        dbvc_cc_render_badges('#cc-content-phase36-badges', phase36Badges);

        const phase36Available = (phase36.available && typeof phase36.available === 'object') ? phase36.available : {};
        const availableSummary = Object.keys(phase36Available)
            .map((key) => `${key}: ${phase36Available[key] ? 'yes' : 'no'}`)
            .join(' | ');
        $('#cc-content-phase36-availability').text(`Artifacts: ${availableSummary || 'none'}`);

        const phase36SummaryLines = [];
        const phase36Elements = (phase36.elements && typeof phase36.elements === 'object') ? phase36.elements : {};
        const phase36Sections = (phase36.sections && typeof phase36.sections === 'object') ? phase36.sections : {};
        const phase36Context = (phase36.context_bundle && typeof phase36.context_bundle === 'object') ? phase36.context_bundle : {};
        const phase36Ingestion = (phase36.ingestion_package && typeof phase36.ingestion_package === 'object') ? phase36.ingestion_package : {};
        const phase36ElementsProcessing = (phase36Elements.processing && typeof phase36Elements.processing === 'object') ? phase36Elements.processing : {};
        const phase36SectionsProcessing = (phase36Sections.processing && typeof phase36Sections.processing === 'object') ? phase36Sections.processing : {};
        phase36SummaryLines.push(`Elements: ${Number(phase36Elements.element_count || 0)}${phase36Elements.truncated ? ' (truncated)' : ''}`);
        phase36SummaryLines.push(`Sections v2: ${Number(phase36Sections.section_count || 0)}`);
        if (phase36ElementsProcessing.is_partial) {
            phase36SummaryLines.push(`Extract partial: ${String(phase36ElementsProcessing.partial_reason || 'unknown')}`);
        }
        if (phase36SectionsProcessing.is_partial) {
            phase36SummaryLines.push(`Segment partial: ${String(phase36SectionsProcessing.partial_reason || 'unknown')}`);
        }
        phase36SummaryLines.push(`Context bundle: outlines ${Number(phase36Context.outline_count || 0)}, sections ${Number(phase36Context.section_count || 0)}`);
        phase36SummaryLines.push(`Ingestion package: sections ${Number(phase36Ingestion.section_count || 0)}, elements ${Number(phase36Ingestion.element_count || 0)}`);
        const $phase36Summary = $('#cc-content-phase36-summary').empty();
        phase36SummaryLines.forEach((line) => {
            $phase36Summary.append($('<li></li>').text(line));
        });

        const typeCounts = (phase36.section_typing && phase36.section_typing.type_counts && typeof phase36.section_typing.type_counts === 'object')
            ? phase36.section_typing.type_counts
            : {};
        const $phase36Types = $('#cc-content-phase36-type-counts').empty();
        Object.keys(typeCounts).forEach((key) => {
            $phase36Types.append($('<li></li>').text(`Type ${key}: ${Number(typeCounts[key] || 0)}`));
        });
        if ($phase36Types.children().length === 0) {
            $phase36Types.append($('<li></li>').text('No section-typing totals available.'));
        }

        const scrubTotals = (phase36.scrub_report && phase36.scrub_report.totals && typeof phase36.scrub_report.totals === 'object')
            ? phase36.scrub_report.totals
            : {};
        const scrubProfile = phase36.scrub_report && phase36.scrub_report.profile ? String(phase36.scrub_report.profile) : 'n/a';
        const $phase36Scrub = $('#cc-content-phase36-scrub-totals').empty();
        $phase36Scrub.append($('<li></li>').text(`Scrub profile: ${dbvc_cc_normalize_mode_label(scrubProfile)}`));
        Object.keys(scrubTotals).forEach((key) => {
            $phase36Scrub.append($('<li></li>').text(`${key}: ${Number(scrubTotals[key] || 0)}`));
        });
        if (Object.keys(scrubTotals).length === 0) {
            $phase36Scrub.append($('<li></li>').text('No scrub totals available.'));
        }

        const readinessStatusMap = {
            ready: 'Ready',
            review: 'Needs Review',
            needs_work: 'Needs Work',
        };
        const readinessStatus = readinessStatusMap[String(readiness.status || '').toLowerCase()] || 'Needs Review';
        const readinessScore = Number(readiness.score || 0);
        const readinessMax = Number(readiness.max || 0);
        $('#cc-content-readiness-status').text(`Status: ${readinessStatus}${readinessMax > 0 ? ` (${readinessScore}/${readinessMax})` : ''}`);

        const readinessChecks = Array.isArray(readiness.checks) ? readiness.checks : [];
        const $readinessChecks = $('#cc-content-readiness-checks').empty();
        readinessChecks.forEach((check) => {
            const passed = !!(check && check.passed);
            const label = check && check.label ? String(check.label) : 'Check';
            $readinessChecks.append($('<li></li>').text(`${passed ? 'PASS' : 'REVIEW'}: ${label}`));
        });
        if (readinessChecks.length === 0) {
            $readinessChecks.append($('<li></li>').text('No readiness checks available.'));
        }

        const readinessNotes = Array.isArray(readiness.notes) ? readiness.notes : [];
        const $readinessNotes = $('#cc-content-readiness-notes').empty();
        readinessNotes.forEach((note) => {
            $readinessNotes.append($('<li></li>').text(String(note)));
        });
        if (readinessNotes.length === 0) {
            $readinessNotes.append($('<li></li>').text('No additional migration notes.'));
        }

        const rawExcerpt = Array.isArray(comparison.raw_excerpt) ? comparison.raw_excerpt : [];
        const sanitizedExcerpt = Array.isArray(comparison.sanitized_excerpt) ? comparison.sanitized_excerpt : [];
        const totalLines = Number(comparison.total_lines || Math.max(rawExcerpt.length, sanitizedExcerpt.length));
        const changedLines = Number(comparison.changed_lines || 0);
        const changedPercent = totalLines > 0 ? Math.round((changedLines / totalLines) * 100) : 0;
        $('#cc-content-diff-summary').text(`Changed lines: ${changedLines}/${totalLines} (${changedPercent}%)`);

        const compareLen = Math.max(rawExcerpt.length, sanitizedExcerpt.length);
        const $rawDiff = $('#cc-content-diff-raw').empty();
        const $sanitizedDiff = $('#cc-content-diff-sanitized').empty();
        for (let index = 0; index < compareLen; index++) {
            const rawLine = String(rawExcerpt[index] || '');
            const sanitizedLine = String(sanitizedExcerpt[index] || '');
            const isChanged = rawLine !== sanitizedLine;
            const $rawLi = $('<li></li>').text(rawLine || ' ');
            const $sanitizedLi = $('<li></li>').text(sanitizedLine || ' ');
            if (isChanged) {
                $rawLi.addClass('cc-diff-changed');
                $sanitizedLi.addClass('cc-diff-changed');
            }
            $rawDiff.append($rawLi);
            $sanitizedDiff.append($sanitizedLi);
        }
        if (compareLen === 0) {
            $rawDiff.append($('<li></li>').text('No raw excerpt available.'));
            $sanitizedDiff.append($('<li></li>').text('No sanitized excerpt available.'));
        }

        $contentPreview.removeClass('cc-hidden');
    }

    function initGraph(elements) {
        if (typeof cytoscape !== 'function') {
            throw new Error('Cytoscape.js failed to load. Check network access and retry.');
        }

        if (state.cy) {
            state.cy.destroy();
        }

        state.cy = cytoscape({
            container: document.getElementById('cc-explorer-graph'),
            elements: elements,
            style: [
                {
                    selector: 'node',
                    style: {
                        'label': 'data(label)',
                        'font-size': '10px',
                        'color': '#111',
                        'text-wrap': 'ellipsis',
                        'text-max-width': 90,
                        'text-valign': 'center',
                        'text-halign': 'center',
                        'width': 22,
                        'height': 22,
                        'background-color': '#94a3b8',
                        'border-width': 1,
                        'border-color': '#334155',
                    },
                },
                {
                    selector: 'node[type = "domain"]',
                    style: {
                        'width': 40,
                        'height': 40,
                        'shape': 'round-rectangle',
                        'background-color': '#0f766e',
                        'border-color': '#134e4a',
                        'color': '#0f172a',
                        'font-weight': 700,
                    },
                },
                {
                    selector: 'node[type = "section"]',
                    style: {
                        'shape': 'ellipse',
                        'background-color': '#f59e0b',
                        'border-color': '#b45309',
                    },
                },
                {
                    selector: 'node[type = "page"]',
                    style: {
                        'shape': 'round-rectangle',
                        'background-color': '#2563eb',
                        'border-color': '#1d4ed8',
                        'color': '#0b1021',
                    },
                },
                {
                    selector: 'node[analysis_status = "queued"], node[analysis_status = "processing"]',
                    style: {
                        'background-color': '#f97316',
                        'border-color': '#c2410c',
                    },
                },
                {
                    selector: 'node[analysis_status = "done"], node[analysis_status = "fallback_done"]',
                    style: {
                        'background-color': '#16a34a',
                        'border-color': '#166534',
                    },
                },
                {
                    selector: 'node[analysis_status = "failed"]',
                    style: {
                        'background-color': '#dc2626',
                        'border-color': '#7f1d1d',
                    },
                },
                {
                    selector: 'node[type = "file"]',
                    style: {
                        'shape': 'diamond',
                        'width': 14,
                        'height': 14,
                        'font-size': '8px',
                        'background-color': '#64748b',
                        'border-color': '#475569',
                    },
                },
                {
                    selector: 'node[search_match = "1"]',
                    style: {
                        'border-width': 4,
                        'border-color': '#f59e0b',
                        'font-weight': 700,
                    },
                },
                {
                    selector: 'edge',
                    style: {
                        'width': 1,
                        'line-color': '#94a3b8',
                        'target-arrow-color': '#94a3b8',
                        'target-arrow-shape': 'triangle',
                        'curve-style': 'bezier',
                    },
                },
                {
                    selector: ':selected',
                    style: {
                        'border-width': 3,
                        'border-color': '#dc2626',
                        'line-color': '#dc2626',
                        'target-arrow-color': '#dc2626',
                    },
                },
            ],
            layout: {
                name: 'preset',
            },
            wheelSensitivity: 0.2,
        });

        state.cy.on('tap', 'node', function(event) {
            const node = event.target;
            handleNodeClick(node.data()).catch((error) => {
                setStatus(error.message || 'Failed to load node details.', true);
            });
        });
    }

    function appendChildElements(childrenPayload) {
        if (!state.cy || !childrenPayload || !childrenPayload.children) {
            return;
        }

        const nodes = childrenPayload.children.nodes || [];
        const edges = childrenPayload.children.edges || [];
        const toAdd = [];

        nodes.forEach((node) => {
            if (!state.cy.getElementById(node.data.id).length) {
                toAdd.push(node);
            }
        });
        edges.forEach((edge) => {
            if (!state.cy.getElementById(edge.data.id).length) {
                toAdd.push(edge);
            }
        });

        if (toAdd.length > 0) {
            state.cy.add(toAdd);
            refreshCptFilterOptions();
            applyViewState(true);
        }
    }

    async function ensureNodeChildrenLoaded(nodeData) {
        if (!nodeData || !state.domain) {
            return false;
        }

        const hasChildren = nodeData.type !== 'file' && Number(nodeData.children_count || 0) > 0;
        if (!hasChildren) {
            return false;
        }
        if (state.loadedChildren.has(nodeData.id)) {
            return false;
        }

        const children = await apiGet('explorer/node/children', {
            domain: state.domain,
            path: nodeData.path || '',
            include_files: false,
        });
        appendChildElements(children);
        state.loadedChildren.add(nodeData.id);
        return true;
    }

    function getSelectedNode() {
        if (!state.selectedPath) {
            return null;
        }
        return getNodeByPath(state.selectedPath);
    }

    function buildCollectionFromNodeIds(nodeIds) {
        if (!state.cy) {
            return null;
        }

        let collection = state.cy.collection();
        nodeIds.forEach((id) => {
            const node = state.cy.getElementById(id);
            if (node && node.length) {
                collection = collection.union(node);
            }
        });

        return collection;
    }

    function toggleBranchCollapseByNodeId(nodeId) {
        if (!nodeId || !state.cy) {
            return null;
        }

        const descendants = collectDescendants(nodeId);
        if (descendants.size === 0) {
            return null;
        }

        const isCollapsed = state.collapsedRoots.has(nodeId);
        if (isCollapsed) {
            state.collapsedRoots.delete(nodeId);
        } else {
            state.collapsedRoots.add(nodeId);
        }
        applyViewState(true);
        updateNodeActionButtons();
        return !isCollapsed;
    }

    async function expandBranchFromNode(nodeData, levels = 2) {
        if (!state.cy || !nodeData || !nodeData.id) {
            return {
                expanded: 0,
                stoppedEarly: false,
            };
        }

        const levelLimit = Math.max(1, Math.min(4, parseInt(levels, 10) || 2));
        const requestLimit = 40;
        let requestCount = 0;
        let expanded = 0;
        const queue = [{
            nodeId: nodeData.id,
            level: 0,
        }];
        const visited = new Set();

        while (queue.length > 0) {
            const current = queue.shift();
            if (!current || visited.has(current.nodeId)) {
                continue;
            }
            visited.add(current.nodeId);

            const node = state.cy.getElementById(current.nodeId);
            if (!node || !node.length) {
                continue;
            }

            if (current.level >= levelLimit) {
                continue;
            }

            if (requestCount >= requestLimit) {
                return {
                    expanded: expanded,
                    stoppedEarly: true,
                };
            }

            const nodePayload = node.data();
            const hasChildren = Number(nodePayload.children_count || 0) > 0 && nodePayload.type !== 'file';
            if (!hasChildren) {
                continue;
            }

            const loaded = await ensureNodeChildrenLoaded(nodePayload);
            if (loaded) {
                expanded++;
                requestCount++;
            }

            state.cy.edges().filter((edge) => edge.data('source') === current.nodeId).forEach((edge) => {
                const targetId = edge.data('target');
                if (!targetId) {
                    return;
                }
                queue.push({
                    nodeId: targetId,
                    level: current.level + 1,
                });
            });
        }

        return {
            expanded: expanded,
            stoppedEarly: false,
        };
    }

    async function refreshSelectedNodeMeta() {
        if (!state.domain || !state.selectedPath) {
            return;
        }

        const detail = await apiGet('explorer/node', {
            domain: state.domain,
            path: state.selectedPath,
        });

        let nodeData = {
            path: state.selectedPath,
            label: state.selectedPath,
            type: state.selectedType,
            children_count: 0,
            image_count: 0,
        };

        if (state.cy) {
            const selectedNode = state.cy.nodes().filter((node) => (node.data('path') || '') === state.selectedPath).first();
            if (selectedNode && selectedNode.length) {
                nodeData = selectedNode.data();
            }
        }

        updateInspectorMeta(nodeData, detail);
    }

    function pollAiStatus(params = {}) {
        clearAiPoll();

        const jobId = params.jobId ? String(params.jobId) : '';
        const domain = params.domain ? String(params.domain) : '';
        const path = params.path ? String(params.path) : '';
        if (!domain || !path) {
            return;
        }

        const pollParams = jobId ? { job_id: jobId } : { domain: domain, path: path };
        if (jobId) {
            state.aiJobId = jobId;
        }

        const runPoll = async () => {
            try {
                const statusPayload = await apiGet('ai/status', pollParams);
                const statusValue = statusPayload && statusPayload.status ? String(statusPayload.status).toLowerCase() : 'unknown';
                const modeValue = statusPayload && statusPayload.mode ? String(statusPayload.mode).toLowerCase() : '';
                const message = statusPayload && statusPayload.message ? String(statusPayload.message) : '';

                const statusLabelMap = {
                    not_started: 'Not started',
                    queued: 'Queued',
                    processing: 'Processing',
                    completed: 'Completed',
                    failed: 'Failed',
                };
                const statusLabel = statusLabelMap[statusValue] || statusValue;
                setStatus(message || `AI status: ${statusLabel}${modeValue ? ` (${modeValue})` : ''}.`, statusValue === 'failed');

                if (state.selectedPath === path) {
                    const mappedStatus = statusValue === 'completed'
                        ? (modeValue === 'fallback' ? 'fallback_done' : 'done')
                        : statusValue;
                    $('#cc-node-ai-status').text(formatAiStatus(mappedStatus, modeValue));
                }

                if (statusValue === 'completed' || statusValue === 'failed') {
                    clearAiPoll();
                    $rerunAiBtn.prop('disabled', false);
                    await refreshSelectedNodeMeta();
                    if (state.cy && state.selectedPath === path) {
                        const graphNode = state.cy.nodes().filter((node) => (node.data('path') || '') === path).first();
                        if (graphNode && graphNode.length) {
                            const mappedStatus = statusValue === 'completed'
                                ? (modeValue === 'fallback' ? 'fallback_done' : 'done')
                                : 'failed';
                            graphNode.data('analysis_status', mappedStatus);
                            graphNode.data('sanitize_status', mappedStatus);
                            graphNode.data('status', statusValue === 'failed' ? 'error' : 'ready');
                        }
                    }
                }
            } catch (error) {
                clearAiPoll();
                $rerunAiBtn.prop('disabled', false);
                setStatus(error.message || 'AI status polling failed.', true);
            }
        };

        runPoll();
        state.aiPollTimer = setInterval(runPoll, 3000);
    }

    function renderBatchProgress(payload) {
        const status = String(payload && payload.status ? payload.status : 'queued');
        const totalJobs = Number(payload && payload.total_jobs ? payload.total_jobs : 0);
        const processedJobs = Number(payload && payload.processed_jobs ? payload.processed_jobs : 0);
        const progressPercent = Number(payload && payload.progress_percent ? payload.progress_percent : 0);
        const counts = (payload && payload.counts) ? payload.counts : {};
        const completed = Number(counts.completed_ai || 0) + Number(counts.completed_fallback || 0);
        const processing = Number(counts.processing || 0);
        const queued = Number(counts.queued || 0);
        const failed = Number(counts.failed || 0);

        return `Branch AI: ${processedJobs}/${totalJobs} (${progressPercent}%) | done ${completed}, processing ${processing}, queued ${queued}, failed ${failed} [${status}]`;
    }

    function pollAiBatchStatus(batchId) {
        clearAiBatchPoll();
        if (!batchId) {
            return;
        }
        state.aiBatchId = String(batchId);

        const runPoll = async () => {
            try {
                const payload = await apiGet('ai/status', {
                    batch_id: state.aiBatchId,
                });
                $aiBranchStatus.text(renderBatchProgress(payload));

                const status = String(payload && payload.status ? payload.status : '');
                const done = status === 'completed' || status === 'completed_with_failures';
                if (done) {
                    clearAiBatchPoll();
                    $rerunAiBranchBtn.prop('disabled', false);
                    if (status === 'completed_with_failures') {
                        setStatus('Branch AI rerun finished with failures. Review branch status details.', true);
                    } else {
                        setStatus('Branch AI rerun completed.');
                    }
                    if (state.selectedPath) {
                        refreshSelectedNodeMeta().catch(() => null);
                    }
                }
            } catch (error) {
                clearAiBatchPoll();
                $rerunAiBranchBtn.prop('disabled', false);
                $aiBranchStatus.text('');
                setStatus(error.message || 'Branch AI status polling failed.', true);
            }
        };

        runPoll();
        state.aiBatchPollTimer = setInterval(runPoll, 4000);
    }

    async function handleNodeClick(nodeData) {
        if (!nodeData || !state.domain) {
            return;
        }

        const path = nodeData.path || '';
        const canExpand = nodeData.type !== 'file' && Number(nodeData.children_count || 0) > 0;
        if (canExpand && !state.loadedChildren.has(nodeData.id)) {
            setStatus(`Loading children for ${nodeData.label || nodeData.id}...`);
            await ensureNodeChildrenLoaded(nodeData);
        }

        const detail = await apiGet('explorer/node', {
            domain: state.domain,
            path: path,
        });
        updateInspectorMeta(nodeData, detail);
        const detailStatus = detail && detail.node && detail.node.status ? detail.node.status : {};
        const aiState = detailStatus.analysis ? String(detailStatus.analysis).toLowerCase() : '';
        if (state.selectedType === 'page' && (aiState === 'queued' || aiState === 'processing')) {
            pollAiStatus({
                jobId: detailStatus.job_id || '',
                domain: state.domain,
                path: path,
            });
        }

        const previewEligible = nodeData.type === 'page' || nodeData.type === 'section';
        if (!previewEligible) {
            $contentPreview.addClass('cc-hidden');
            dbvc_cc_set_content_context_status('Content context is available for page/section nodes only.');
            $contentContextJson.text('');
            setStatus(`Selected node: ${nodeData.label || nodeData.id}`);
            return;
        }

        const [preview, audit] = await Promise.all([
            apiGet('explorer/content', {
                domain: state.domain,
                path: path,
                mode: $contentMode.val() || 'raw',
            }).catch(() => null),
            apiGet('explorer/node/audit', {
                domain: state.domain,
                path: path,
                limit: 25,
            }).catch(() => null),
        ]);

        if (preview) {
            updateContentPreview(preview);
        } else {
            $contentPreview.addClass('cc-hidden');
        }
        if (audit) {
            updateAuditTrail(audit);
        }
        dbvc_cc_load_content_context(path).catch((error) => {
            dbvc_cc_set_content_context_status(error.message || 'Failed to load content context.', true);
            $contentContextJson.text('');
        });

        setStatus(`Selected node: ${nodeData.label || nodeData.id}`);
    }

    async function loadTree() {
        const domain = $domain.val();
        const depth = Math.max(1, Math.min(5, parseInt($depth.val(), 10) || (defaults.depth || 2)));
        const maxNodes = Math.max(100, Math.min(2000, parseInt($maxNodes.val(), 10) || (defaults.max_nodes || 600)));

        if (!domain) {
            setStatus('No domain selected.', true);
            return;
        }

        state.domain = domain;
        state.loadedChildren = new Set([`domain:${domain}`]);
        state.collapsedRoots = new Set();
        state.isolatedRootId = '';
        resetInspector();
        setStatus(`Loading explorer tree for ${domain}...`);

        const payload = await apiGet('explorer/tree', {
            domain: domain,
            depth: depth,
            max_nodes: maxNodes,
            include_files: false,
        });

        const elements = [];
        (payload.cytoscape && payload.cytoscape.nodes ? payload.cytoscape.nodes : []).forEach((node) => elements.push(node));
        (payload.cytoscape && payload.cytoscape.edges ? payload.cytoscape.edges : []).forEach((edge) => elements.push(edge));

        initGraph(elements);
        refreshCptFilterOptions();
        applyViewState(true);

        const totals = payload.totals || {};
        setStatus(`Loaded ${elements.length} graph elements. Pages: ${totals.pages || 0}, Media: ${totals.media_files || 0}, Cache: ${payload.scan_mode || 'fresh'}.`);
    }

    async function loadDomains() {
        setStatus('Loading domains...');
        const payload = await apiGet('explorer/domains');
        const domains = Array.isArray(payload.domains) ? payload.domains : [];

        state.dbvc_cc_domain_ai_health_by_key = {};
        domains.forEach((dbvc_cc_domain_record) => {
            const dbvc_cc_domain_key = dbvc_cc_domain_record && dbvc_cc_domain_record.key
                ? String(dbvc_cc_domain_record.key).trim().toLowerCase()
                : '';
            const dbvc_cc_health = dbvc_cc_domain_record
                && dbvc_cc_domain_record.dbvc_cc_ai_health
                && typeof dbvc_cc_domain_record.dbvc_cc_ai_health === 'object'
                ? dbvc_cc_domain_record.dbvc_cc_ai_health
                : null;
            if (!dbvc_cc_domain_key) {
                return;
            }
            state.dbvc_cc_domain_ai_health_by_key[dbvc_cc_domain_key] = dbvc_cc_health;
        });

        $domain.empty();
        if (domains.length === 0) {
            $domain.append('<option value="">No crawl domains found</option>');
            $domainAiWarning.addClass('cc-hidden').text('');
            setStatus('No crawl domains found. Run a sitemap crawl first.', true);
            return;
        }

        domains.forEach((domain) => {
            const key = domain.key || '';
            if (!key) {
                return;
            }
            const dbvc_cc_option_label = dbvc_cc_build_domain_option_label(domain);
            $domain.append(`<option value="${escapeHtml(key)}">${escapeHtml(dbvc_cc_option_label)}</option>`);
        });

        dbvc_cc_render_domain_ai_warning($domain.val() || '');
        setStatus(`Loaded ${domains.length} domain(s).`);
    }

    async function runExport(scope) {
        const domain = $domain.val();
        if (!domain) {
            setExportStatus('Select a domain first.', true);
            return;
        }

        const format = $('#cc-export-format').val() || 'json';
        const includeAssets = $('#cc-export-assets').is(':checked');
        const useAi = $('#cc-export-use-ai').is(':checked');

        setExportStatus('Building export zip. This may take a moment...');
        const response = await apiPost('export', {
            domain: domain,
            scope: scope,
            format: format,
            include_assets: includeAssets,
            use_ai: useAi,
        });

        const downloadUrl = response.download_url || '';
        const aiStatus = response.ai && response.ai.status ? response.ai.status : 'unknown';
        const pages = response.totals && response.totals.pages ? response.totals.pages : 0;

        if (downloadUrl) {
            setExportStatus(
                `Export complete. Pages: ${pages}. AI status: ${aiStatus}. <a href="${escapeHtml(downloadUrl)}" target="_blank" rel="noopener noreferrer">Download ZIP</a>`,
                false,
                true
            );
            return;
        }

        setExportStatus(`Export completed but no download URL was returned. Job: ${escapeHtml(response.job_id || '')}`, true);
    }

    async function rerunAiForSelectedPath() {
        if (!state.domain || !state.selectedPath) {
            setStatus('Select a page node first.', true);
            return;
        }
        if (!(state.selectedType === 'page' && state.selectedHasJson)) {
            setStatus('Rerun AI is only available for page nodes with JSON artifacts.', true);
            return;
        }

        setStatus('Queueing AI rerun...');
        const response = await apiPost('ai/rerun', {
            domain: state.domain,
            path: state.selectedPath,
            run_now: false,
        });
        $rerunAiBtn.prop('disabled', true);
        setStatus(response.message || 'AI rerun queued.');
        pollAiStatus({
            jobId: response && response.job_id ? response.job_id : '',
            domain: state.domain,
            path: state.selectedPath,
        });
    }

    async function rerunAiForSelectedBranch() {
        if (!state.domain || !state.selectedPath) {
            setStatus('Select a branch node first.', true);
            return;
        }
        if (state.selectedType === 'file') {
            setStatus('Branch AI rerun is not available for file nodes.', true);
            return;
        }

        setStatus('Queueing AI rerun for branch...');
        const response = await apiPost('ai/rerun-branch', {
            domain: state.domain,
            path: state.selectedPath,
            run_now: false,
            max_jobs: 150,
        });

        const batchId = response && response.batch_id ? String(response.batch_id) : '';
        $rerunAiBranchBtn.prop('disabled', true);
        $aiBranchStatus.text(response && response.message ? String(response.message) : 'Branch AI rerun queued.');
        setStatus(`Branch rerun queued${batchId ? ` (${batchId})` : ''}.`);
        if (batchId) {
            pollAiBatchStatus(batchId);
        }
    }

    function focusSelectedNode() {
        const node = getSelectedNode();
        if (!node || !state.cy) {
            setStatus('Select a node first.', true);
            return;
        }

        state.cy.animate({
            center: {
                eles: node,
            },
            duration: 220,
        });
        setStatus(`Focused on ${node.data('label') || node.id()}.`);
    }

    function fitSelectedBranch() {
        if (!state.cy) {
            setStatus('Load explorer data first.', true);
            return;
        }

        const node = getSelectedNode();
        if (!node) {
            setStatus('Select a node first.', true);
            return;
        }

        const ids = new Set([node.id()]);
        collectDescendants(node.id()).forEach((id) => ids.add(id));
        collectAncestors(node.id()).forEach((id) => ids.add(id));
        const collection = buildCollectionFromNodeIds(ids);
        if (!collection || collection.length === 0) {
            setStatus('No branch nodes available to fit.', true);
            return;
        }

        state.cy.fit(collection, 28);
        setStatus(`Fitted branch for ${node.data('label') || node.id()}.`);
    }

    function isolateSelectedBranch() {
        if (!state.cy) {
            setStatus('Load explorer data first.', true);
            return;
        }

        const node = getSelectedNode();
        if (!node) {
            setStatus('Select a node first.', true);
            return;
        }
        if (String(node.data('type') || '') === 'file') {
            setStatus('Branch isolation is only available for directory/page nodes.', true);
            return;
        }

        state.isolatedRootId = node.id();
        applyViewState(true);
        updateNodeActionButtons();
        setStatus(`Isolated branch for ${node.data('label') || node.id()}.`);
    }

    function clearBranchIsolation() {
        if (!state.isolatedRootId) {
            setStatus('No active branch isolation.');
            return;
        }

        state.isolatedRootId = '';
        applyViewState(true);
        updateNodeActionButtons();
        setStatus('Cleared branch isolation.');
    }

    async function expandSelectedBranch() {
        if (!state.cy) {
            setStatus('Load explorer data first.', true);
            return;
        }

        const node = getSelectedNode();
        if (!node) {
            setStatus('Select a node first.', true);
            return;
        }
        if (String(node.data('type') || '') === 'file') {
            setStatus('Cannot expand a file node.', true);
            return;
        }

        const levels = Math.max(1, Math.min(4, parseInt($nodeExpandLevels.val(), 10) || 2));
        $nodeExpandLevels.val(levels);

        setStatus(`Expanding ${node.data('label') || node.id()} to ${levels} level(s)...`);
        const result = await expandBranchFromNode(node.data(), levels);
        applyViewState(true);
        if (result.stoppedEarly) {
            setStatus(`Expanded ${result.expanded} nodes (request limit reached). Refine branch and retry.`, true);
        } else {
            setStatus(`Expanded ${result.expanded} node(s) from ${node.data('label') || node.id()}.`);
        }
    }

    function toggleSelectedBranch() {
        if (!state.cy) {
            setStatus('Load explorer data first.', true);
            return;
        }

        const node = getSelectedNode();
        if (!node) {
            setStatus('Select a parent node first.', true);
            return;
        }

        const nextCollapsedState = toggleBranchCollapseByNodeId(node.id());
        if (nextCollapsedState === null) {
            setStatus('Selected node has no descendants to collapse.', true);
            return;
        }

        setStatus(`${nextCollapsedState ? 'Collapsed' : 'Expanded'} branch for ${node.data('label') || node.id()}.`);
    }

    function isTypingContext(event) {
        const target = event && event.target ? event.target : null;
        if (!target) {
            return false;
        }
        const tagName = target.tagName ? String(target.tagName).toLowerCase() : '';
        if (['input', 'textarea', 'select', 'button'].includes(tagName)) {
            return true;
        }
        return !!target.isContentEditable;
    }

    function openMappingWorkbenchForSelection() {
        if (!mappingBridgeEnabled) {
            setStatus('Mapping bridge feature flags are disabled.', true);
            return;
        }
        if (!workbenchEnabled || !workbenchUrl) {
            setStatus('Mapping Workbench is not enabled.', true);
            return;
        }
        if (!state.domain || !state.selectedPath) {
            setStatus('Select a page or section node before opening mapping.', true);
            return;
        }

        const targetUrl = new URL(workbenchUrl, window.location.origin);
        targetUrl.searchParams.set('domain', String(state.domain));
        targetUrl.searchParams.set('path', String(state.selectedPath));
        window.open(targetUrl.toString(), '_blank', 'noopener');
    }

    function getVisibleSearchMatchCount() {
        if (!state.cy) {
            return 0;
        }
        return state.cy.nodes().filter((node) => {
            return node.data('search_match') === '1' && node.style('display') !== 'none';
        }).length;
    }

    let searchInputTimer = null;

    $('#cc-explorer-load').on('click', function() {
        loadTree().catch((error) => {
            setStatus(error.message || 'Failed to load explorer tree.', true);
        });
    });

    $('#cc-explorer-refresh').on('click', function() {
        loadTree().catch((error) => {
            setStatus(error.message || 'Failed to refresh explorer tree.', true);
        });
    });

    $('#cc-view-apply').on('click', function() {
        if (!state.cy) {
            setStatus('Load explorer data first.', true);
            return;
        }
        syncViewStateFromControls();
        applyViewState(true);
        const searchState = state.view.search ? `, Search: "${state.view.search}" (${getVisibleSearchMatchCount()} matches)` : ', Search: none';
        setStatus(`View applied. Layout: ${state.view.layout}, Type: ${state.view.nodeType}, CPT: ${state.view.cpt}, Depth: ${state.view.collapseLevel || 'all'}${searchState}.`);
    });

    $('#cc-view-reset').on('click', function() {
        if (!state.cy) {
            resetViewControls();
            setStatus('View controls reset.');
            return;
        }
        resetViewControls();
        applyViewState(true);
        setStatus('View reset to defaults.');
    });

    $('#cc-view-expand-all').on('click', function() {
        if (!state.cy) {
            setStatus('Load explorer data first.', true);
            return;
        }
        state.collapsedRoots = new Set();
        state.isolatedRootId = '';
        applyViewState(true);
        updateNodeActionButtons();
        setStatus('All branches expanded.');
    });

    $('#cc-view-collapse-selected').on('click', function() {
        toggleSelectedBranch();
    });

    $viewSearch.on('input', function() {
        if (!state.cy) {
            return;
        }
        if (searchInputTimer) {
            clearTimeout(searchInputTimer);
        }
        searchInputTimer = setTimeout(() => {
            syncViewStateFromControls();
            applyViewState(true);
            if (state.view.search) {
                setStatus(`Search "${state.view.search}" matched ${getVisibleSearchMatchCount()} node(s).`);
            } else {
                setStatus('Search cleared.');
            }
        }, 180);
    });

    $viewSearch.on('keydown', function(event) {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        if (searchInputTimer) {
            clearTimeout(searchInputTimer);
            searchInputTimer = null;
        }
        if (!state.cy) {
            return;
        }
        syncViewStateFromControls();
        applyViewState(true);
        if (state.view.search) {
            setStatus(`Search "${state.view.search}" matched ${getVisibleSearchMatchCount()} node(s).`);
        } else {
            setStatus('Search cleared.');
        }
    });

    $viewSearchClear.on('click', function() {
        $viewSearch.val('');
        if (!state.cy) {
            syncViewStateFromControls();
            setStatus('Search cleared.');
            return;
        }
        syncViewStateFromControls();
        applyViewState(true);
        setStatus('Search cleared.');
    });

    $('#cc-export-domain').on('click', function() {
        if (!exportEnabled) {
            setExportStatus('Export module is disabled in this phase.', true);
            return;
        }
        runExport({
            mode: 'domain',
        }).catch((error) => {
            setExportStatus(error.message || 'Export failed.', true);
        });
    });

    $('#cc-export-selected').on('click', function() {
        if (!exportEnabled) {
            setExportStatus('Export module is disabled in this phase.', true);
            return;
        }
        if (!state.selectedPath) {
            setExportStatus('Select a node first.', true);
            return;
        }

        runExport({
            mode: 'subtree',
            path: state.selectedPath,
        }).catch((error) => {
            setExportStatus(error.message || 'Export failed.', true);
        });
    });

    $rerunAiBtn.on('click', function() {
        if (!aiEnabled) {
            setStatus('AI module is disabled in this phase.', true);
            return;
        }
        rerunAiForSelectedPath().catch((error) => {
            setStatus(error.message || 'AI rerun request failed.', true);
        });
    });

    $rerunAiBranchBtn.on('click', function() {
        if (!aiEnabled) {
            setStatus('AI module is disabled in this phase.', true);
            return;
        }
        rerunAiForSelectedBranch().catch((error) => {
            setStatus(error.message || 'Branch AI rerun request failed.', true);
            $rerunAiBranchBtn.prop('disabled', false);
        });
    });

    $nodeFocusBtn.on('click', function() {
        focusSelectedNode();
    });

    $nodeFitBranchBtn.on('click', function() {
        fitSelectedBranch();
    });

    $nodeIsolateBranchBtn.on('click', function() {
        isolateSelectedBranch();
    });

    $nodeClearIsolationBtn.on('click', function() {
        clearBranchIsolation();
    });

    $nodeExpandBranchBtn.on('click', function() {
        expandSelectedBranch().catch((error) => {
            setStatus(error.message || 'Failed to expand branch.', true);
        });
    });

    $nodeToggleBranchBtn.on('click', function() {
        toggleSelectedBranch();
    });

    $nodeOpenSourceBtn.on('click', function() {
        if (!openExternalUrl(state.selectedSourceUrl)) {
            setStatus('No source URL available for this node.', true);
        }
    });

    $nodeOpenCanonicalBtn.on('click', function() {
        if (!openExternalUrl(state.selectedCanonicalUrl)) {
            setStatus('No canonical URL available for this node.', true);
        }
    });

    $nodeMapImportBtn.on('click', function() {
        openMappingWorkbenchForSelection();
    });

    $domain.on('change', function() {
        const dbvc_cc_selected_domain = String($domain.val() || '');
        resetViewControls();
        resetInspector();
        dbvc_cc_render_domain_ai_warning(dbvc_cc_selected_domain);
        setStatus(`Selected domain: ${dbvc_cc_selected_domain || 'none'}`);
    });

    $domainAiWarning.on('click', function() {
        dbvc_cc_run_domain_ai_refresh_from_warning();
    });

    $contentMode.on('change', function() {
        if (!state.domain || !state.selectedPath || !(state.selectedType === 'page' || state.selectedType === 'section')) {
            return;
        }
        Promise.all([
            apiGet('explorer/content', {
                domain: state.domain,
                path: state.selectedPath,
                mode: $contentMode.val() || 'raw',
            }),
            apiGet('explorer/node/audit', {
                domain: state.domain,
                path: state.selectedPath,
                limit: 25,
            }),
        ]).then(([preview, audit]) => {
            updateContentPreview(preview);
            updateAuditTrail(audit);
            setStatus(`Loaded ${$contentMode.val() || 'raw'} preview for ${state.selectedPath}.`);
        }).catch((error) => {
            setStatus(error.message || 'Failed to load preview mode.', true);
        });
    });

    $contentContextLoadBtn.on('click', function() {
        if (!state.selectedPath) {
            dbvc_cc_set_content_context_status('Select a page or section node to inspect content context.', true);
            return;
        }
        dbvc_cc_load_content_context(state.selectedPath).catch((error) => {
            dbvc_cc_set_content_context_status(error.message || 'Failed to load content context.', true);
            $contentContextJson.text('');
        });
    });

    $contentContextArtifact.on('change', function() {
        if (!state.selectedPath) {
            return;
        }
        dbvc_cc_load_content_context(state.selectedPath).catch((error) => {
            dbvc_cc_set_content_context_status(error.message || 'Failed to load content context.', true);
            $contentContextJson.text('');
        });
    });

    $contentContextScrubPreviewBtn.on('click', function() {
        if (!state.selectedPath) {
            dbvc_cc_set_content_context_status('Select a page node to preview scrub policy suggestions.', true);
            return;
        }
        dbvc_cc_load_scrub_policy_preview(state.selectedPath).catch((error) => {
            dbvc_cc_set_content_context_status(error.message || 'Failed to load scrub preview.', true);
            $contentContextJson.text('');
        });
    });

    $contentContextScrubApproveBtn.on('click', function() {
        if (!state.selectedPath) {
            dbvc_cc_set_content_context_status('Select a page node to approve scrub suggestions.', true);
            return;
        }
        dbvc_cc_approve_scrub_policy_suggestions(state.selectedPath).catch((error) => {
            dbvc_cc_set_content_context_status(error.message || 'Failed to approve scrub suggestions.', true);
            $contentContextJson.text('');
        });
    });

    $contentContextScrubStatusBtn.on('click', function() {
        dbvc_cc_load_scrub_policy_approval_status(state.selectedPath || '').catch((error) => {
            dbvc_cc_set_content_context_status(error.message || 'Failed to load scrub approval status.', true);
            $contentContextJson.text('');
        });
    });

    $(document).on('keydown', function(event) {
        if (!state.selectedPath || !state.cy) {
            return;
        }
        if (isTypingContext(event) || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        const key = String(event.key || '').toLowerCase();
        if (!key) {
            return;
        }

        if (key === 'f') {
            event.preventDefault();
            focusSelectedNode();
            return;
        }
        if (key === 'i') {
            event.preventDefault();
            isolateSelectedBranch();
            return;
        }
        if (key === 'e') {
            event.preventDefault();
            expandSelectedBranch().catch((error) => {
                setStatus(error.message || 'Failed to expand branch.', true);
            });
            return;
        }
        if (key === 'b') {
            event.preventDefault();
            toggleSelectedBranch();
            return;
        }
        if (key === 'r') {
            event.preventDefault();
            if (event.shiftKey) {
                rerunAiForSelectedBranch().catch((error) => {
                    setStatus(error.message || 'Branch AI rerun request failed.', true);
                });
                return;
            }
            rerunAiForSelectedPath().catch((error) => {
                setStatus(error.message || 'AI rerun request failed.', true);
            });
        }
    });

    resetViewControls();
    resetInspector();
    loadDomains()
        .then(() => {
            if ($domain.val()) {
                return loadTree();
            }
            return null;
        })
        .catch((error) => {
            setStatus(error.message || 'Failed to initialize explorer.', true);
        });
});
