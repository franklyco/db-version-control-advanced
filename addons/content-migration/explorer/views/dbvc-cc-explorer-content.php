    <div class="cc-explorer-toolbar">
        <label for="cc-explorer-domain"><?php esc_html_e('Domain', 'dbvc'); ?></label>
        <select id="cc-explorer-domain" class="regular-text"></select>
        <button type="button" id="cc-explorer-domain-ai-warning" class="cc-domain-ai-warning cc-hidden" aria-live="polite"></button>

        <label for="cc-explorer-depth"><?php esc_html_e('Depth', 'dbvc'); ?></label>
        <input type="number" id="cc-explorer-depth" min="1" max="5" value="<?php echo esc_attr(isset($options['explorer_default_depth']) ? $options['explorer_default_depth'] : 2); ?>" class="small-text" />

        <label for="cc-explorer-max-nodes"><?php esc_html_e('Max Nodes', 'dbvc'); ?></label>
        <input type="number" id="cc-explorer-max-nodes" min="100" max="2000" step="50" value="<?php echo esc_attr(isset($options['explorer_max_nodes']) ? $options['explorer_max_nodes'] : 600); ?>" class="small-text" />

        <button type="button" class="button button-primary" id="cc-explorer-load"><?php esc_html_e('Load Explorer', 'dbvc'); ?></button>
        <button type="button" class="button" id="cc-explorer-refresh"><?php esc_html_e('Refresh', 'dbvc'); ?></button>
    </div>

    <div class="cc-explorer-view-toolbar">
        <label for="cc-view-layout"><?php esc_html_e('Layout', 'dbvc'); ?></label>
        <select id="cc-view-layout">
            <option value="pyramid"><?php esc_html_e('Pyramid Tree', 'dbvc'); ?></option>
            <option value="linear_grid"><?php esc_html_e('Linear Grid', 'dbvc'); ?></option>
        </select>

        <label for="cc-view-node-type"><?php esc_html_e('Node Type', 'dbvc'); ?></label>
        <select id="cc-view-node-type">
            <option value="all"><?php esc_html_e('All', 'dbvc'); ?></option>
            <option value="page"><?php esc_html_e('Page', 'dbvc'); ?></option>
            <option value="section"><?php esc_html_e('Section', 'dbvc'); ?></option>
            <option value="domain"><?php esc_html_e('Domain', 'dbvc'); ?></option>
        </select>

        <label for="cc-view-cpt"><?php esc_html_e('CPT', 'dbvc'); ?></label>
        <select id="cc-view-cpt">
            <option value="all"><?php esc_html_e('All CPTs', 'dbvc'); ?></option>
        </select>

        <label for="cc-view-collapse-level"><?php esc_html_e('Collapse Depth', 'dbvc'); ?></label>
        <input type="number" id="cc-view-collapse-level" class="small-text" min="0" max="12" value="0" />

        <label for="cc-view-search"><?php esc_html_e('Search', 'dbvc'); ?></label>
        <input type="search" id="cc-view-search" class="regular-text" placeholder="<?php esc_attr_e('Label, path, or source URL', 'dbvc'); ?>" />
        <button type="button" class="button" id="cc-view-search-clear"><?php esc_html_e('Clear Search', 'dbvc'); ?></button>

        <button type="button" class="button" id="cc-view-apply"><?php esc_html_e('Apply View', 'dbvc'); ?></button>
        <button type="button" class="button" id="cc-view-reset"><?php esc_html_e('Reset View', 'dbvc'); ?></button>
        <button type="button" class="button" id="cc-view-collapse-selected"><?php esc_html_e('Toggle Branch Collapse', 'dbvc'); ?></button>
        <button type="button" class="button" id="cc-view-expand-all"><?php esc_html_e('Expand All', 'dbvc'); ?></button>
    </div>

    <div class="cc-explorer-export-toolbar">
        <label for="cc-export-format"><?php esc_html_e('Export Format', 'dbvc'); ?></label>
        <select id="cc-export-format">
            <option value="json">JSON</option>
            <option value="yaml">YAML</option>
            <option value="md">Markdown</option>
        </select>

        <label class="cc-inline-check">
            <input type="checkbox" id="cc-export-assets" checked />
            <?php esc_html_e('Include assets', 'dbvc'); ?>
        </label>
        <label class="cc-inline-check">
            <input type="checkbox" id="cc-export-use-ai" checked />
            <?php esc_html_e('Use AI when available', 'dbvc'); ?>
        </label>

        <button type="button" class="button button-primary" id="cc-export-domain"><?php esc_html_e('Export Domain ZIP', 'dbvc'); ?></button>
        <button type="button" class="button" id="cc-export-selected" disabled><?php esc_html_e('Export Selected Node ZIP', 'dbvc'); ?></button>
    </div>
    <div id="cc-export-status" class="cc-export-status" aria-live="polite"></div>

    <div class="cc-explorer-layout">
        <section class="cc-explorer-graph-panel">
            <div id="cc-explorer-graph"></div>
            <div id="cc-explorer-status" aria-live="polite"></div>
        </section>

        <aside class="cc-explorer-inspector-panel">
            <h2><?php esc_html_e('Node Inspector', 'dbvc'); ?></h2>
            <div id="cc-explorer-inspector-empty"><?php esc_html_e('Select a node to view details.', 'dbvc'); ?></div>

            <dl id="cc-explorer-inspector-meta" class="cc-hidden">
                <dt><?php esc_html_e('Label', 'dbvc'); ?></dt>
                <dd id="cc-node-label"></dd>
                <dt><?php esc_html_e('Type', 'dbvc'); ?></dt>
                <dd id="cc-node-type"></dd>
                <dt><?php esc_html_e('Path', 'dbvc'); ?></dt>
                <dd id="cc-node-path"></dd>
                <dt><?php esc_html_e('Source URL', 'dbvc'); ?></dt>
                <dd id="cc-node-source-url"></dd>
                <dt><?php esc_html_e('Canonical URL', 'dbvc'); ?></dt>
                <dd id="cc-node-canonical-url"></dd>
                <dt><?php esc_html_e('Children', 'dbvc'); ?></dt>
                <dd id="cc-node-children"></dd>
                <dt><?php esc_html_e('JSON', 'dbvc'); ?></dt>
                <dd id="cc-node-json"></dd>
                <dt><?php esc_html_e('Images', 'dbvc'); ?></dt>
                <dd id="cc-node-images"></dd>
                <dt><?php esc_html_e('Crawl Status', 'dbvc'); ?></dt>
                <dd id="cc-node-crawl-status"></dd>
                <dt><?php esc_html_e('AI Status', 'dbvc'); ?></dt>
                <dd id="cc-node-ai-status"></dd>
                <dt><?php esc_html_e('Mode Badges', 'dbvc'); ?></dt>
                <dd id="cc-node-mode-badges"></dd>
            </dl>

            <div id="cc-ai-actions" class="cc-hidden">
                <button type="button" class="button" id="cc-rerun-ai"><?php esc_html_e('Rerun AI', 'dbvc'); ?></button>
                <button type="button" class="button" id="cc-rerun-ai-branch"><?php esc_html_e('Rerun AI for Branch', 'dbvc'); ?></button>
                <p id="cc-ai-branch-status"></p>
            </div>

            <div id="cc-node-actions" class="cc-hidden">
                <h3><?php esc_html_e('Node Actions', 'dbvc'); ?></h3>
                <div class="cc-node-action-grid">
                    <button type="button" class="button" id="cc-node-focus"><?php esc_html_e('Focus Node', 'dbvc'); ?></button>
                    <button type="button" class="button" id="cc-node-fit-branch"><?php esc_html_e('Fit Branch', 'dbvc'); ?></button>
                    <button type="button" class="button" id="cc-node-isolate-branch"><?php esc_html_e('Isolate Branch', 'dbvc'); ?></button>
                    <button type="button" class="button" id="cc-node-clear-isolation"><?php esc_html_e('Clear Isolation', 'dbvc'); ?></button>
                </div>
                <div class="cc-node-action-grid cc-node-action-expand-row">
                    <label for="cc-node-expand-levels"><?php esc_html_e('Expand Levels', 'dbvc'); ?></label>
                    <input type="number" id="cc-node-expand-levels" class="small-text" min="1" max="4" value="2" />
                    <button type="button" class="button" id="cc-node-expand-branch"><?php esc_html_e('Expand Branch', 'dbvc'); ?></button>
                    <button type="button" class="button" id="cc-node-toggle-branch"><?php esc_html_e('Toggle Branch Collapse', 'dbvc'); ?></button>
                </div>
                <div class="cc-node-link-actions">
                    <button type="button" class="button" id="cc-node-open-source" disabled><?php esc_html_e('Open Source', 'dbvc'); ?></button>
                    <button type="button" class="button" id="cc-node-open-canonical" disabled><?php esc_html_e('Open Canonical', 'dbvc'); ?></button>
                    <button type="button" class="button button-primary" id="cc-node-map-import" disabled><?php esc_html_e('Map Collection for Imports', 'dbvc'); ?></button>
                </div>
                <p class="cc-node-shortcuts"><?php esc_html_e('Shortcuts: F focus, I isolate, E expand, B toggle branch, R rerun AI page, Shift+R rerun AI branch.', 'dbvc'); ?></p>
            </div>

            <h3><?php esc_html_e('Content Preview', 'dbvc'); ?></h3>
            <div class="cc-preview-toolbar">
                <label for="cc-content-mode"><?php esc_html_e('Preview Mode', 'dbvc'); ?></label>
                <select id="cc-content-mode">
                    <option value="raw"><?php esc_html_e('Raw', 'dbvc'); ?></option>
                    <option value="sanitized"><?php esc_html_e('Sanitized', 'dbvc'); ?></option>
                </select>
            </div>
            <div id="cc-explorer-content-preview" class="cc-hidden">
                <p id="cc-content-title"></p>
                <div id="cc-content-ai-summary">
                    <strong><?php esc_html_e('AI Suggestions', 'dbvc'); ?></strong>
                    <p id="cc-content-ai-status"></p>
                    <p id="cc-content-ai-post-type"></p>
                    <p id="cc-content-ai-categories"></p>
                    <p id="cc-content-ai-summary-text"></p>
                </div>
                <div id="cc-content-signals" class="cc-content-module">
                    <strong><?php esc_html_e('Content Signals', 'dbvc'); ?></strong>
                    <ul id="cc-content-signals-list"></ul>
                </div>
                <div id="cc-content-phase36" class="cc-content-module">
                    <strong><?php esc_html_e('Phase 3.6 Context', 'dbvc'); ?></strong>
                    <div id="cc-content-phase36-badges" class="cc-badge-row"></div>
                    <p id="cc-content-phase36-availability"></p>
                    <ul id="cc-content-phase36-summary"></ul>
                    <ul id="cc-content-phase36-type-counts"></ul>
                    <ul id="cc-content-phase36-scrub-totals"></ul>
                </div>
                <div id="cc-content-context-inspector" class="cc-content-module">
                    <strong><?php esc_html_e('Content Context Inspector', 'dbvc'); ?></strong>
                    <div class="cc-content-context-toolbar">
                        <label for="cc-content-context-artifact"><?php esc_html_e('Artifact', 'dbvc'); ?></label>
                        <select id="cc-content-context-artifact">
                            <option value="all"><?php esc_html_e('All', 'dbvc'); ?></option>
                            <option value="elements"><?php esc_html_e('Elements', 'dbvc'); ?></option>
                            <option value="sections"><?php esc_html_e('Sections', 'dbvc'); ?></option>
                            <option value="section_typing"><?php esc_html_e('Section Typing', 'dbvc'); ?></option>
                            <option value="context_bundle"><?php esc_html_e('Context Bundle', 'dbvc'); ?></option>
                            <option value="ingestion_package"><?php esc_html_e('Ingestion Package', 'dbvc'); ?></option>
                            <option value="scrub_report"><?php esc_html_e('Scrub Report', 'dbvc'); ?></option>
                        </select>
                        <label for="cc-content-context-limit"><?php esc_html_e('Limit', 'dbvc'); ?></label>
                        <input type="number" id="cc-content-context-limit" class="small-text" min="5" max="200" value="40" />
                        <button type="button" class="button" id="cc-content-context-load"><?php esc_html_e('Load Context', 'dbvc'); ?></button>
                        <button type="button" class="button" id="cc-content-context-scrub-preview"><?php esc_html_e('Scrub Preview', 'dbvc'); ?></button>
                        <button type="button" class="button" id="cc-content-context-scrub-approve"><?php esc_html_e('Approve Suggested', 'dbvc'); ?></button>
                        <button type="button" class="button" id="cc-content-context-scrub-status"><?php esc_html_e('Approval Status', 'dbvc'); ?></button>
                    </div>
                    <p id="cc-content-context-status"></p>
                    <pre id="cc-content-context-json"></pre>
                </div>
                <div id="cc-content-readiness" class="cc-content-module">
                    <strong><?php esc_html_e('Migration Readiness', 'dbvc'); ?></strong>
                    <p id="cc-content-readiness-status"></p>
                    <ul id="cc-content-readiness-checks"></ul>
                    <ul id="cc-content-readiness-notes"></ul>
                </div>
                <div id="cc-content-diff" class="cc-content-module">
                    <strong><?php esc_html_e('Raw vs Sanitized Diff', 'dbvc'); ?></strong>
                    <p id="cc-content-diff-summary"></p>
                    <div class="cc-diff-grid">
                        <div>
                            <p class="cc-diff-label"><?php esc_html_e('Raw', 'dbvc'); ?></p>
                            <ul id="cc-content-diff-raw"></ul>
                        </div>
                        <div>
                            <p class="cc-diff-label"><?php esc_html_e('Sanitized', 'dbvc'); ?></p>
                            <ul id="cc-content-diff-sanitized"></ul>
                        </div>
                    </div>
                </div>
                <div id="cc-content-audit" class="cc-content-module">
                    <strong><?php esc_html_e('Node Audit Trail', 'dbvc'); ?></strong>
                    <p id="cc-content-audit-summary"></p>
                    <ul id="cc-content-audit-events"></ul>
                </div>
                <div>
                    <strong><?php esc_html_e('Headings', 'dbvc'); ?></strong>
                    <ul id="cc-content-headings"></ul>
                </div>
                <div>
                    <strong><?php esc_html_e('Excerpt', 'dbvc'); ?></strong>
                    <ul id="cc-content-excerpt"></ul>
                </div>
                <div>
                    <strong><?php esc_html_e('Section Groups', 'dbvc'); ?></strong>
                    <p id="cc-content-section-count"></p>
                    <div id="cc-content-sections"></div>
                </div>
                <div>
                    <strong><?php esc_html_e('PII Flags', 'dbvc'); ?></strong>
                    <p id="cc-content-pii"></p>
                </div>
            </div>
        </aside>
    </div>
