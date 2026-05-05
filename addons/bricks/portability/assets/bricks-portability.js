(function () {
  const cfg = window.DBVC_BRICKS_PORTABILITY || {};
  const panel = document.getElementById("dbvc-bricks-panel-portability");
  if (!panel) {
    return;
  }

  const state = {
    domains: [],
    exports: [],
    sessions: [],
    backups: [],
    jobs: [],
    currentSession: null,
    decisions: {},
    manualDecisionRowIds: {},
    selectedRowId: "",
    activeSubtab: "workspace",
    isRowModalOpen: false,
    lastFocusedElement: null,
    dependencyActionFeedback: {},
    sortKey: "",
    sortDirection: "desc",
    page: 1,
    pageSize: 50,
  };

  const els = {
    domainList: document.getElementById("dbvc-bricks-portability-domain-list"),
    exportNotes: document.getElementById("dbvc-bricks-portability-export-notes"),
    exportButton: document.getElementById("dbvc-bricks-portability-export-button"),
    exportsBody: document.getElementById("dbvc-bricks-portability-exports-body"),
    importFile: document.getElementById("dbvc-bricks-portability-import-file"),
    importButton: document.getElementById("dbvc-bricks-portability-import-button"),
    sessionMeta: document.getElementById("dbvc-bricks-portability-session-meta"),
    sessionsBody: document.getElementById("dbvc-bricks-portability-sessions-body"),
    summary: document.getElementById("dbvc-bricks-portability-summary"),
    reviewState: document.getElementById("dbvc-bricks-portability-review-state"),
    refreshSession: document.getElementById("dbvc-bricks-portability-refresh-session"),
    appliedSummary: document.getElementById("dbvc-bricks-portability-applied-summary"),
    stats: document.getElementById("dbvc-bricks-portability-stats"),
    pagination: document.getElementById("dbvc-bricks-portability-pagination"),
    pageSize: document.getElementById("dbvc-bricks-portability-page-size"),
    pageSummary: document.getElementById("dbvc-bricks-portability-page-summary"),
    pagePrev: document.getElementById("dbvc-bricks-portability-page-prev"),
    pageNext: document.getElementById("dbvc-bricks-portability-page-next"),
    filterDomain: document.getElementById("dbvc-bricks-portability-filter-domain"),
    filterStatus: document.getElementById("dbvc-bricks-portability-filter-status"),
    filterDecision: document.getElementById("dbvc-bricks-portability-filter-decision"),
    filterWarning: document.getElementById("dbvc-bricks-portability-filter-warning"),
    filterSearch: document.getElementById("dbvc-bricks-portability-filter-search"),
    hideIdentical: document.getElementById("dbvc-bricks-portability-hide-identical"),
    bulkAction: document.getElementById("dbvc-bricks-portability-bulk-action"),
    bulkApply: document.getElementById("dbvc-bricks-portability-apply-bulk"),
    tableBody: document.getElementById("dbvc-bricks-portability-table-body"),
    detail: document.getElementById("dbvc-bricks-portability-detail"),
    domainSummary: document.getElementById("dbvc-bricks-portability-domain-summary"),
    confirmApplyBoxes: Array.prototype.slice.call(panel.querySelectorAll("[data-portability-confirm-apply]")),
    saveDraftButtons: Array.prototype.slice.call(panel.querySelectorAll("[data-portability-save-draft]")),
    applyButtons: Array.prototype.slice.call(panel.querySelectorAll("[data-portability-apply-button]")),
    backupsBody: document.getElementById("dbvc-bricks-portability-backups-body"),
    jobs: document.getElementById("dbvc-bricks-portability-jobs"),
    successNotice: document.getElementById("dbvc-bricks-notice-success"),
    errorNotice: document.getElementById("dbvc-bricks-notice-error"),
    subtabButtons: Array.prototype.slice.call(panel.querySelectorAll("[data-portability-tab]")),
    subtabPanels: Array.prototype.slice.call(panel.querySelectorAll("[data-portability-panel]")),
    rowModal: document.getElementById("dbvc-bricks-portability-row-modal"),
    rowModalClose: document.getElementById("dbvc-bricks-portability-row-modal-close"),
    rowModalSubtitle: document.getElementById("dbvc-bricks-portability-row-modal-subtitle"),
    sortButtons: Array.prototype.slice.call(panel.querySelectorAll("[data-portability-sort]")),
  };

  const STATUS_META = {
    identical: {
      label: "No Drift",
      description: "Incoming Package and Current Site are equivalent after normalization.",
    },
    new_in_source: {
      label: "Incoming Only",
      description: "This object exists in the Incoming Package but not on the Current Site.",
    },
    missing_from_source: {
      label: "Current Site Only",
      description: "This object exists on the Current Site but is not present in the Incoming Package.",
    },
    same_name_different_id: {
      label: "Same Name, Different ID",
      description: "The row matched by name, but the object identifiers differ.",
    },
    same_id_different_name: {
      label: "Same ID, Different Name",
      description: "The row matched by ID, but the object labels differ.",
    },
    value_changed: {
      label: "Changed Values",
      description: "The same object exists on both sides, but one or more normalized values differ.",
    },
    changed_props: {
      label: "Changed Structure",
      description: "The same object exists on both sides, with changed values plus added or removed properties.",
    },
    added_props: {
      label: "Incoming Adds Properties",
      description: "The Incoming Package adds normalized properties that are not on the Current Site.",
    },
    removed_props: {
      label: "Current Site Has Extra Properties",
      description: "The Current Site contains normalized properties that are not present in the Incoming Package.",
    },
  };

  const MATCH_META = {
    singleton: "Singleton",
    id: "Matched by ID",
    name: "Matched by Name",
    slug: "Matched by Slug",
    token: "Matched by Token",
    selector: "Matched by Selector",
    option_name: "Matched by Option Name",
    unmatched: "No Match",
    target_only: "Current Site Only",
    unknown: "Matched",
  };

  const ACTION_META = {
    keep_current: "Keep Current Site",
    add_incoming: "Add Incoming Package",
    replace_with_incoming: "Replace With Incoming Package",
    skip: "Skip",
  };

  const MANUAL_DECISIONS_FILTER = "__manual_decisions__";
  const STAGED_DECISIONS_FILTER = "__staged_decisions__";

  const DOMAIN_STATUS_META = {
    clean: "Clean",
    has_drift: "Has Drift",
    requires_attention: "Needs Attention",
    has_conflicts: "Has Conflicts",
  };

  const DIFF_BUCKET_META = {
    changed: "Changed",
    added: "Incoming Adds",
    removed: "Current Only",
  };

  const APPROVAL_STATUS_META = {
    applied: "Applied",
    approved: "Approved",
  };

  const VERIFICATION_STATUS_META = {
    verified: "Verified",
    review_recommended: "Review Recommended",
    unsupported: "Unsupported",
    missing: "Missing",
    not_required: "Not Required",
  };

  function setNoticeState(target, visible, message) {
    if (!target) {
      return;
    }
    const p = target.querySelector("p");
    if (p) {
      p.textContent = visible ? String(message || "") : "";
    }
    if (target.classList) {
      target.classList.remove("leave-in-place");
    }
    target.hidden = !visible;
    target.style.display = visible ? "block" : "none";
  }

  function showNotice(type, message) {
    const target = type === "error" ? els.errorNotice : els.successNotice;
    const other = type === "error" ? els.successNotice : els.errorNotice;
    setNoticeState(other, false, "");
    setNoticeState(target, Boolean(message), message || "");
  }

  function setConfirmApplyState(nextChecked, changedBox) {
    const checked = Boolean(nextChecked);
    els.confirmApplyBoxes.forEach(function (box) {
      if (box === changedBox) {
        return;
      }
      box.checked = checked;
    });
  }

  function isApplyConfirmed() {
    return els.confirmApplyBoxes.some(function (box) {
      return Boolean(box && box.checked);
    });
  }

  function activateSubtab(tabKey) {
    const nextTab = String(tabKey || "workspace");
    state.activeSubtab = nextTab;

    els.subtabButtons.forEach(function (button) {
      const isActive = String(button.getAttribute("data-portability-tab") || "") === nextTab;
      button.classList.toggle("nav-tab-active", isActive);
      button.setAttribute("aria-selected", isActive ? "true" : "false");
      button.tabIndex = isActive ? 0 : -1;
    });

    els.subtabPanels.forEach(function (tabPanel) {
      const isActive = String(tabPanel.getAttribute("data-portability-panel") || "") === nextTab;
      tabPanel.hidden = !isActive;
    });
  }

  function api(url, options) {
    const opts = Object.assign({ headers: {} }, options || {});
    const headers = opts.headers || {};
    headers["X-WP-Nonce"] = cfg.nonce || "";
    headers["X-DBVC-Correlation-ID"] = "bricks-portability-" + Date.now();
    opts.headers = headers;
    return fetch(url, opts).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (data) {
        if (!response.ok) {
          const error = new Error(data && data.message ? data.message : "Request failed.");
          error.data = data;
          throw error;
        }
        return data;
      });
    });
  }

  function statusMeta(status) {
    const key = String(status || "");
    if (STATUS_META[key]) {
      return STATUS_META[key];
    }
    return {
      label: humanizeKey(key),
      description: "",
    };
  }

  function statusLabel(status) {
    return statusMeta(status).label;
  }

  function matchLabel(matchStatus) {
    const key = String(matchStatus || "");
    return MATCH_META[key] || humanizeKey(key);
  }

  function actionLabel(action) {
    const key = String(action || "");
    return ACTION_META[key] || humanizeKey(key);
  }

  function actionKeys() {
    return Object.keys(ACTION_META);
  }

  function domainStatusLabel(status) {
    const key = String(status || "");
    return DOMAIN_STATUS_META[key] || humanizeKey(key);
  }

  function diffBucketLabel(bucket) {
    const key = String(bucket || "");
    return DIFF_BUCKET_META[key] || humanizeKey(key);
  }

  function approvalStatusLabel(status) {
    const key = String(status || "");
    return APPROVAL_STATUS_META[key] || humanizeKey(key || "approved");
  }

  function verificationStatusLabel(status) {
    const key = String(status || "");
    return VERIFICATION_STATUS_META[key] || humanizeKey(key);
  }

  function humanizeKey(value) {
    return String(value || "")
      .replace(/[_-]+/g, " ")
      .replace(/\b\w/g, function (match) {
        return match.toUpperCase();
      })
      .trim();
  }

  function formatTimestamp(value) {
    const raw = String(value || "").trim();
    if (!raw) {
      return "";
    }

    const parsed = new Date(raw);
    if (!parsed || Number.isNaN(parsed.getTime())) {
      return raw;
    }

    return parsed.toLocaleString();
  }

  function timestampValue(value) {
    const raw = String(value || "").trim();
    if (!raw) {
      return 0;
    }

    const parsed = Date.parse(raw);
    if (!Number.isNaN(parsed)) {
      return parsed;
    }

    return 0;
  }

  function getRowApproval(row) {
    return {
      decision: String(row && row.approved_action ? row.approved_action : ""),
      approvedAt: String(row && row.approved_at_gmt ? row.approved_at_gmt : ""),
      status: String(row && row.approval_status ? row.approval_status : ""),
    };
  }

  function selectedDomainKeys() {
    return Array.prototype.slice.call(panel.querySelectorAll('input[name="dbvc-bricks-portability-domain"]:checked')).map(function (node) {
      return String(node.value || "");
    }).filter(Boolean);
  }

  function renderDomains() {
    if (!els.domainList) {
      return;
    }
    if (!Array.isArray(state.domains) || state.domains.length === 0) {
      els.domainList.textContent = "No supported portability domains found.";
      return;
    }
    els.domainList.innerHTML = state.domains.map(function (domain) {
      const domainKey = String(domain.domain_key || "");
      const disabled = domain.available ? "" : ' disabled="disabled"';
      const note = domain.available ? "" : ' <em>(not available on this site)</em>';
      const risk = domain.high_risk ? ' <span class="dbvc-bricks-portability-badge">high risk</span>' : "";
      const verification = domain.verification ? ' <span class="dbvc-bricks-portability-badge">verify</span>' : "";
      return '<label class="dbvc-bricks-portability-domain-option"><input type="checkbox" name="dbvc-bricks-portability-domain" value="' + esc(domainKey) + '"' + disabled + ' /> <strong>' + esc(domain.label || domainKey) + "</strong>" + risk + verification + note + "</label>";
    }).join("");
  }

  function renderExports() {
    if (!els.exportsBody) {
      return;
    }
    if (!Array.isArray(state.exports) || state.exports.length === 0) {
      els.exportsBody.innerHTML = '<tr><td colspan="4">No exports yet.</td></tr>';
      return;
    }
    els.exportsBody.innerHTML = state.exports.map(function (item) {
      const domains = Array.isArray(item.selected_domains) ? item.selected_domains.join(", ") : "";
      const downloadUrl = normalizeUrl(item.download_url || "");
      const download = downloadUrl ? '<a class="button button-small" href="' + esc(downloadUrl) + '">Download</a>' : "";
      return "<tr><td><code>" + esc(item.package_id || item.export_id || "") + "</code></td><td>" + esc(item.created_at_gmt || "") + "</td><td>" + esc(domains) + "</td><td>" + download + "</td></tr>";
    }).join("");
  }

  function renderSessions() {
    if (!els.sessionsBody) {
      return;
    }
    if (!Array.isArray(state.sessions) || state.sessions.length === 0) {
      els.sessionsBody.innerHTML = '<tr><td colspan="4">No review sessions yet.</td></tr>';
      return;
    }
    els.sessionsBody.innerHTML = state.sessions.map(function (item) {
      const summary = item.summary && typeof item.summary === "object" ? item.summary : {};
      const draft = item.draft && typeof item.draft === "object" ? item.draft : {};
      const approval = item.approval && typeof item.approval === "object" ? item.approval : {};
      const rollback = item.rollback && typeof item.rollback === "object" ? item.rollback : {};
      const summaryParts = [
        '<div class="dbvc-bricks-portability-session-summary-line">' + esc("Rows: " + String(summary.total_rows || 0) + " | Actionable: " + String(summary.actionable_rows || 0)) + "</div>",
      ];
      if (item.refreshed_at_gmt) {
        summaryParts.push('<div class="description">' + esc("Last compared: " + formatTimestamp(item.refreshed_at_gmt)) + "</div>");
      }
      if (draft.saved_at_gmt) {
        summaryParts.push('<div class="description">' + esc("Draft saved: " + formatTimestamp(draft.saved_at_gmt) + " | Decisions: " + String(draft.decision_count || 0)) + "</div>");
      }
      if (approval.approved_at_gmt) {
        summaryParts.push('<div class="dbvc-bricks-portability-inline-badge dbvc-bricks-portability-inline-badge--approved">' + esc("Date Applied & Approved on Current Site: " + formatTimestamp(approval.approved_at_gmt)) + "</div>");
      }
      if (rollback.rolled_back_at_gmt) {
        summaryParts.push('<div class="dbvc-bricks-portability-inline-badge dbvc-bricks-portability-inline-badge--rollback">' + esc("Rolled Back on Current Site: " + formatTimestamp(rollback.rolled_back_at_gmt)) + "</div>");
      }
      return '<tr><td><code>' + esc(item.session_id || "") + '</code></td><td><code>' + esc(item.package_id || "") + '</code></td><td>' + summaryParts.join("") + '</td><td><button type="button" class="button button-small dbvc-bricks-portability-open-session" data-session-id="' + esc(item.session_id || "") + '">Open</button></td></tr>';
    }).join("");
    Array.prototype.forEach.call(els.sessionsBody.querySelectorAll(".dbvc-bricks-portability-open-session"), function (button) {
      button.addEventListener("click", function () {
        openSession(String(button.getAttribute("data-session-id") || ""));
      });
    });
  }

  function renderBackups() {
    if (!els.backupsBody) {
      return;
    }
    if (!Array.isArray(state.backups) || state.backups.length === 0) {
      els.backupsBody.innerHTML = '<tr><td colspan="4">No backups yet.</td></tr>';
      return;
    }
    els.backupsBody.innerHTML = state.backups.map(function (item) {
      const optionNames = Array.isArray(item.option_names) ? item.option_names.join(", ") : "";
      return '<tr><td><code>' + esc(item.backup_id || "") + '</code></td><td>' + esc(item.created_at_gmt || "") + '</td><td>' + esc(optionNames) + '</td><td><button type="button" class="button button-small dbvc-bricks-portability-rollback" data-backup-id="' + esc(item.backup_id || "") + '">Rollback</button></td></tr>';
    }).join("");
    Array.prototype.forEach.call(els.backupsBody.querySelectorAll(".dbvc-bricks-portability-rollback"), function (button) {
      button.addEventListener("click", function () {
        rollbackBackup(String(button.getAttribute("data-backup-id") || ""));
      });
    });
  }

  function renderJobs() {
    if (!els.jobs) {
      return;
    }
    els.jobs.textContent = JSON.stringify(state.jobs || [], null, 2);
  }

  function renderSessionMeta() {
    if (!els.sessionMeta) {
      return;
    }
    if (!state.currentSession) {
      els.sessionMeta.textContent = "No package loaded yet.";
      return;
    }
    const summary = state.currentSession.summary || {};
    const site = state.currentSession.site && typeof state.currentSession.site === "object" ? state.currentSession.site : {};
    const draft = state.currentSession.draft && typeof state.currentSession.draft === "object" ? state.currentSession.draft : {};
    const approval = state.currentSession.approval && typeof state.currentSession.approval === "object" ? state.currentSession.approval : {};
    const rollback = state.currentSession.rollback && typeof state.currentSession.rollback === "object" ? state.currentSession.rollback : {};
    const incomingSite = [site.site_name, site.home_url].filter(Boolean).join(" | ");
    const lines = [
      "Session: " + String(state.currentSession.session_id || ""),
      "Package: " + String(state.currentSession.package_id || ""),
      "Last Compared: " + String(state.currentSession.refreshed_at_gmt || state.currentSession.created_at_gmt || ""),
      "Rows: " + String(summary.total_rows || 0),
      "Actionable Incoming Changes: " + String(summary.actionable_rows || 0),
      "Warnings: " + String(summary.warning_rows || 0),
    ];
    if (incomingSite) {
      lines.push("Incoming Package Site: " + incomingSite);
    }
    if (draft.saved_at_gmt) {
      lines.push("Draft Saved: " + String(draft.saved_at_gmt));
      lines.push("Draft Decisions: " + String(draft.decision_count || 0));
    }
    if (approval.approved_at_gmt) {
      lines.push("Date Applied & Approved on Current Site: " + String(approval.approved_at_gmt));
      lines.push("Approved Decisions: " + String(approval.decision_count || 0));
    }
    if (rollback.rolled_back_at_gmt) {
      lines.push("Rolled Back on Current Site: " + String(rollback.rolled_back_at_gmt));
      lines.push("Rollback Backup: " + String(rollback.backup_id || ""));
    }
    lines.push("Current Site: This site");
    els.sessionMeta.textContent = lines.join("\n");
  }

  function renderAppliedSummary() {
    if (!els.appliedSummary) {
      return;
    }
    if (!state.currentSession) {
      els.appliedSummary.textContent = "Apply approved changes to record approval timestamps for this package on the current site.";
      return;
    }

    const approval = state.currentSession.approval && typeof state.currentSession.approval === "object" ? state.currentSession.approval : {};
    const rollback = state.currentSession.rollback && typeof state.currentSession.rollback === "object" ? state.currentSession.rollback : {};
    if (!approval.approved_at_gmt) {
      els.appliedSummary.innerHTML = '<div class="dbvc-bricks-portability-inline-badge dbvc-bricks-portability-inline-badge--pending">Date Applied & Approved on Current Site: Not yet recorded for this package.</div>';
      return;
    }

    const detailBits = [
      "Approved rows: " + String(approval.decision_count || 0),
      "Incoming writes applied: " + String(approval.mutating_decision_count || 0),
    ];
    if (approval.backup_id) {
      detailBits.push("Backup: " + String(approval.backup_id || ""));
    }
    const parts = [
      '<div class="dbvc-bricks-portability-inline-badge dbvc-bricks-portability-inline-badge--approved">' + esc("Date Applied & Approved on Current Site: " + formatTimestamp(approval.approved_at_gmt)) + "</div>",
      '<div class="description">' + esc(detailBits.join(" | ")) + "</div>",
    ];
    if (rollback.rolled_back_at_gmt) {
      parts.push('<div class="dbvc-bricks-portability-inline-badge dbvc-bricks-portability-inline-badge--rollback">' + esc("Rolled Back on Current Site: " + formatTimestamp(rollback.rolled_back_at_gmt)) + "</div>");
    }
    els.appliedSummary.innerHTML = parts.join("");
  }

  function renderReviewState() {
    if (!els.reviewState) {
      return;
    }
    if (!state.currentSession) {
      els.reviewState.innerHTML = [
        '<div class="dbvc-bricks-portability-review-state__meta">Import a package to assess current-site freshness for this review session.</div>',
        '<div class="dbvc-bricks-portability-review-state__actions"><button type="button" class="button" id="dbvc-bricks-portability-refresh-session" disabled="disabled">Refresh Current Site Compare</button></div>',
      ].join("");
      return;
    }

    const freshness = state.currentSession.freshness && typeof state.currentSession.freshness === "object" ? state.currentSession.freshness : {};
    const rollback = state.currentSession.rollback && typeof state.currentSession.rollback === "object" ? state.currentSession.rollback : {};
    const isFresh = String(freshness.state || "fresh") !== "stale";
    const changedDomains = Array.isArray(freshness.changed_domains) ? freshness.changed_domains : [];
    const freshnessBadge = '<span class="dbvc-bricks-portability-inline-badge dbvc-bricks-portability-inline-badge--' + esc(isFresh ? "fresh" : "stale") + '">' + esc(isFresh ? "Current Site Compare: Fresh" : "Current Site Compare: Stale") + "</span>";
    const parts = [
      freshnessBadge,
      '<span class="description">' + esc("Last compared: " + formatTimestamp(freshness.last_compared_at_gmt || state.currentSession.refreshed_at_gmt || state.currentSession.created_at_gmt || "")) + "</span>",
    ];
    if (!isFresh && changedDomains.length) {
      parts.push('<span class="description">' + esc("Changed domains since compare: " + changedDomains.join(", ")) + "</span>");
    }
    if (rollback.rolled_back_at_gmt) {
      parts.push('<span class="dbvc-bricks-portability-inline-badge dbvc-bricks-portability-inline-badge--rollback">' + esc("Rolled Back: " + formatTimestamp(rollback.rolled_back_at_gmt)) + "</span>");
    }

    els.reviewState.innerHTML = [
      '<div class="dbvc-bricks-portability-review-state__meta">' + parts.join(" ") + "</div>",
      '<div class="dbvc-bricks-portability-review-state__actions"><button type="button" class="button" id="dbvc-bricks-portability-refresh-session">Refresh Current Site Compare</button></div>',
    ].join("");

    els.refreshSession = document.getElementById("dbvc-bricks-portability-refresh-session");
    if (els.refreshSession) {
      els.refreshSession.addEventListener("click", refreshCurrentSession);
    }
  }

  function hydrateDraftDecisions(session) {
    const draft = session && session.draft && typeof session.draft === "object" ? session.draft : {};
    const approval = session && session.approval && typeof session.approval === "object" ? session.approval : {};
    const decisions = draft.decisions && typeof draft.decisions === "object" ? draft.decisions : {};
    const approvalRows = approval.rows && typeof approval.rows === "object" ? approval.rows : {};
    const hydrated = {};

    (Array.isArray(session && session.rows) ? session.rows : []).forEach(function (row) {
      const rowId = String(row && row.row_id ? row.row_id : "");
      if (!rowId) {
        return;
      }
      const allowed = Array.isArray(row.available_actions) ? row.available_actions : [];
      const approvalDecision = approvalRows[rowId] && typeof approvalRows[rowId] === "object"
        ? String(approvalRows[rowId].decision || "")
        : "";
      const savedDecision = String(decisions[rowId] || approvalDecision || "");
      if (savedDecision && allowed.indexOf(savedDecision) !== -1) {
        hydrated[rowId] = savedDecision;
      }
    });

    return hydrated;
  }

  function hydrateManualDecisionRowIds(session) {
    const draft = session && session.draft && typeof session.draft === "object" ? session.draft : {};
    const approval = session && session.approval && typeof session.approval === "object" ? session.approval : {};
    const manualRowIds = {}

    ;[].concat(
      Array.isArray(draft.manual_rows) ? draft.manual_rows : [],
      Array.isArray(approval.manual_rows) ? approval.manual_rows : []
    ).forEach(function (rowId) {
      const normalizedRowId = String(rowId || "");
      if (!normalizedRowId) {
        return;
      }
      manualRowIds[normalizedRowId] = true;
    });

    return manualRowIds;
  }

  function isManualDecision(row) {
    const rowId = String(row && row.row_id ? row.row_id : "");
    if (!rowId) {
      return false;
    }
    return Boolean(state.manualDecisionRowIds[rowId] || (row && row.manual_decision));
  }

  function isStagedDecision(row) {
    if (!isManualDecision(row)) {
      return false;
    }

    const approval = getRowApproval(row);
    const currentDecision = ensureDecision(row || {});
    if (!approval.approvedAt) {
      return true;
    }

    return String(approval.decision || "") !== currentDecision;
  }

  function currentRows() {
    return state.currentSession && Array.isArray(state.currentSession.rows) ? state.currentSession.rows : [];
  }

  function filteredRows() {
    const selectedDomain = els.filterDomain ? String(els.filterDomain.value || "") : "";
    const selectedStatus = els.filterStatus ? String(els.filterStatus.value || "") : "";
    const selectedDecision = els.filterDecision ? String(els.filterDecision.value || "") : "";
    const selectedWarning = els.filterWarning ? String(els.filterWarning.value || "") : "";
    const hideIdentical = !els.hideIdentical || Boolean(els.hideIdentical.checked);
    const search = els.filterSearch ? String(els.filterSearch.value || "").toLowerCase() : "";
    return currentRows().filter(function (row) {
      if (selectedDomain && String(row.domain_key || "") !== selectedDomain) {
        return false;
      }
      if (selectedStatus && String(row.status || "") !== selectedStatus) {
        return false;
      }
      if (hideIdentical && selectedStatus !== "identical" && String(row.status || "") === "identical") {
        return false;
      }
      if (selectedDecision) {
        if (selectedDecision === STAGED_DECISIONS_FILTER) {
          if (!isStagedDecision(row)) {
            return false;
          }
        } else if (selectedDecision === MANUAL_DECISIONS_FILTER) {
          if (!isManualDecision(row)) {
            return false;
          }
        } else if (ensureDecision(row) !== selectedDecision) {
          return false;
        }
      }
      if (selectedWarning === "with_warnings" && (!Array.isArray(row.warnings) || row.warnings.length === 0)) {
        return false;
      }
      if (selectedWarning === "without_warnings" && Array.isArray(row.warnings) && row.warnings.length > 0) {
        return false;
      }
      if (search) {
        const haystack = [
          String(row.object_label || ""),
          String(row.object_id || ""),
          String(row.domain_label || ""),
          statusLabel(row.status || ""),
          matchLabel(row.match_status || ""),
          actionLabel(ensureDecision(row)),
        ].join(" ").toLowerCase();
        if (haystack.indexOf(search) === -1) {
          return false;
        }
      }
      return true;
    });
  }

  function compareRowIdentity(left, right) {
    const leftKey = [
      String(left && left.domain_label ? left.domain_label : left && left.domain_key ? left.domain_key : ""),
      String(left && left.object_label ? left.object_label : ""),
      String(left && left.object_id ? left.object_id : ""),
    ].join("|").toLowerCase();
    const rightKey = [
      String(right && right.domain_label ? right.domain_label : right && right.domain_key ? right.domain_key : ""),
      String(right && right.object_label ? right.object_label : ""),
      String(right && right.object_id ? right.object_id : ""),
    ].join("|").toLowerCase();

    if (leftKey === rightKey) {
      return 0;
    }

    return leftKey > rightKey ? 1 : -1;
  }

  function sortedRows(rows) {
    const list = Array.isArray(rows) ? rows.slice() : [];
    if (state.sortKey !== "approved_at_gmt") {
      return list;
    }

    const direction = state.sortDirection === "asc" ? 1 : -1;
    list.sort(function (left, right) {
      const leftStamp = timestampValue(getRowApproval(left).approvedAt);
      const rightStamp = timestampValue(getRowApproval(right).approvedAt);

      if (!leftStamp && !rightStamp) {
        return compareRowIdentity(left, right);
      }
      if (!leftStamp) {
        return 1;
      }
      if (!rightStamp) {
        return -1;
      }
      if (leftStamp === rightStamp) {
        return compareRowIdentity(left, right);
      }

      return leftStamp > rightStamp ? direction : -direction;
    });

    return list;
  }

  function totalPagesForCount(totalCount) {
    const pageSize = Math.max(1, Number(state.pageSize || 50));
    return Math.max(1, Math.ceil(Math.max(0, Number(totalCount || 0)) / pageSize));
  }

  function clampCurrentPage(totalCount) {
    const totalPages = totalPagesForCount(totalCount);
    if (state.page < 1) {
      state.page = 1;
    }
    if (state.page > totalPages) {
      state.page = totalPages;
    }
    return totalPages;
  }

  function paginatedRows(rows) {
    const list = Array.isArray(rows) ? rows : [];
    const pageSize = Math.max(1, Number(state.pageSize || 50));
    clampCurrentPage(list.length);
    const start = (state.page - 1) * pageSize;
    return list.slice(start, start + pageSize);
  }

  function renderFilters() {
    if (!state.currentSession) {
      return;
    }
    if (els.filterDomain) {
      const currentValue = String(els.filterDomain.value || "");
      const domainOptions = {};
      currentRows().forEach(function (row) {
        const key = String(row.domain_key || "");
        if (!key) {
          return;
        }
        if (!domainOptions[key]) {
          domainOptions[key] = String(row.domain_label || key);
        }
      });
      const domains = Object.keys(domainOptions).sort();
      els.filterDomain.innerHTML = '<option value="">All</option>' + domains.map(function (value) {
        return '<option value="' + esc(value) + '">' + esc(domainOptions[value] || value) + "</option>";
      }).join("");
      els.filterDomain.value = currentValue && domains.indexOf(currentValue) !== -1 ? currentValue : "";
    }
    if (els.filterStatus) {
      const currentStatus = String(els.filterStatus.value || "");
      const statuses = Array.from(new Set(currentRows().map(function (row) {
        return String(row.status || "");
      }).filter(Boolean))).sort();
      els.filterStatus.innerHTML = '<option value="">All</option>' + statuses.map(function (value) {
        return '<option value="' + esc(value) + '">' + esc(statusLabel(value)) + "</option>";
      }).join("");
      els.filterStatus.value = currentStatus && statuses.indexOf(currentStatus) !== -1 ? currentStatus : "";
    }
    if (els.filterDecision) {
      const currentDecision = String(els.filterDecision.value || "");
      const observedDecisions = Array.from(new Set(currentRows().map(function (row) {
        return ensureDecision(row);
      }).filter(Boolean)));
      const decisionLookup = {};
      observedDecisions.forEach(function (value) {
        decisionLookup[value] = true;
      });
      actionKeys().forEach(function (value) {
        decisionLookup[value] = true;
      });
      const decisions = actionKeys().filter(function (value) {
        return Boolean(decisionLookup[value]);
      });
      els.filterDecision.innerHTML = '<option value="">All</option>' +
        '<option value="' + esc(STAGED_DECISIONS_FILTER) + '">Staged Decisions</option>' +
        '<option value="' + esc(MANUAL_DECISIONS_FILTER) + '">Manual Decisions</option>' +
        decisions.map(function (value) {
        return '<option value="' + esc(value) + '">' + esc(actionLabel(value)) + "</option>";
      }).join("");
      const allowedDecisionValues = decisions.concat([STAGED_DECISIONS_FILTER, MANUAL_DECISIONS_FILTER]);
      els.filterDecision.value = currentDecision && allowedDecisionValues.indexOf(currentDecision) !== -1 ? currentDecision : "";
    }
  }

  function renderPagination(rows) {
    if (!els.pageSummary || !els.pagePrev || !els.pageNext || !els.pageSize) {
      return;
    }

    if (!state.currentSession) {
      els.pageSummary.textContent = "Import a package to page through review rows.";
      els.pagePrev.disabled = true;
      els.pageNext.disabled = true;
      els.pageSize.disabled = true;
      return;
    }

    const list = Array.isArray(rows) ? rows : [];
    const totalRows = list.length;
    const totalPages = clampCurrentPage(totalRows);
    const pageSize = Math.max(1, Number(state.pageSize || 50));
    const start = totalRows > 0 ? ((state.page - 1) * pageSize) + 1 : 0;
    const end = totalRows > 0 ? Math.min(totalRows, start + pageSize - 1) : 0;

    els.pageSize.disabled = false;
    els.pageSize.value = String(pageSize);
    els.pagePrev.disabled = totalRows <= 0 || state.page <= 1;
    els.pageNext.disabled = totalRows <= 0 || state.page >= totalPages;
    els.pageSummary.textContent = totalRows <= 0
      ? "No filtered review rows to display."
      : "Showing " + String(start) + "-" + String(end) + " of " + String(totalRows) + " filtered rows | Page " + String(state.page) + " of " + String(totalPages);
  }

  function ensureDecision(row) {
    const rowId = String(row.row_id || "");
    if (!rowId) {
      return "keep_current";
    }
    if (!state.decisions[rowId]) {
      state.decisions[rowId] = String(row.suggested_action || "keep_current");
    }
    return state.decisions[rowId];
  }

  function renderApprovalCell(row) {
    const approval = getRowApproval(row);
    if (!approval.approvedAt) {
      return '<span class="description">Not yet recorded</span>';
    }

    const badgeClass = "dbvc-bricks-portability-inline-badge dbvc-bricks-portability-inline-badge--" + safeKey(approval.status || "approved");
    return [
      '<div class="dbvc-bricks-portability-approval-cell">',
      '<span class="' + esc(badgeClass) + '">' + esc(approvalStatusLabel(approval.status || "approved")) + "</span>",
      '<div class="dbvc-bricks-portability-approval-cell__time">' + esc(formatTimestamp(approval.approvedAt)) + "</div>",
      "</div>",
    ].join("");
  }

  function renderTable(rows) {
    if (!els.tableBody) {
      return;
    }
    const sortedFilteredRows = Array.isArray(rows) ? rows : sortedRows(filteredRows());
    const pageRows = paginatedRows(sortedFilteredRows);
    if (pageRows.length === 0) {
      els.tableBody.innerHTML = '<tr><td colspan="9">No review rows match the current filters.</td></tr>';
      return;
    }
    els.tableBody.innerHTML = pageRows.map(function (row) {
      const rowId = String(row.row_id || "");
      const warnings = Array.isArray(row.warnings) ? row.warnings.length : 0;
      const decision = ensureDecision(row);
      const options = Array.isArray(row.available_actions) ? row.available_actions : [];
      const selectedClass = rowId === state.selectedRowId ? " is-selected" : "";
      const statusClass = "dbvc-bricks-portability-status dbvc-bricks-portability-status--" + safeKey(row.status || "");
      const actionClass = "dbvc-bricks-portability-action-pill dbvc-bricks-portability-action-pill--" + safeKey(decision);
      return '<tr class="dbvc-bricks-portability-row' + selectedClass + '" data-row-id="' + esc(rowId) + '">' +
        "<td>" + esc(row.domain_label || row.domain_key || "") + "</td>" +
        "<td>" + esc(row.object_label || "Untitled object") + "</td>" +
        "<td><code>" + esc(row.object_id || "") + "</code></td>" +
        "<td>" + esc(matchLabel(row.match_status || "")) + "</td>" +
        '<td><span class="' + esc(statusClass) + '">' + esc(statusLabel(row.status || "")) + "</span></td>" +
        '<td><span class="' + esc(actionClass) + '">' + esc(actionLabel(decision)) + "</span></td>" +
        "<td>" + renderApprovalCell(row) + "</td>" +
        "<td>" + esc(String(warnings)) + "</td>" +
        '<td><select class="dbvc-bricks-portability-decision" data-row-id="' + esc(rowId) + '">' + options.map(function (option) {
          const selected = option === decision ? ' selected="selected"' : "";
          return '<option value="' + esc(option) + '"' + selected + ">" + esc(actionLabel(option)) + "</option>";
        }).join("") + "</select></td>" +
      "</tr>";
    }).join("");

    Array.prototype.forEach.call(els.tableBody.querySelectorAll(".dbvc-bricks-portability-row"), function (rowNode) {
      rowNode.addEventListener("click", function (event) {
        if (event.target && event.target.tagName === "SELECT") {
          return;
        }
        selectRow(String(rowNode.getAttribute("data-row-id") || ""));
      });
    });

    Array.prototype.forEach.call(els.tableBody.querySelectorAll(".dbvc-bricks-portability-decision"), function (select) {
      select.addEventListener("change", function () {
        const rowId = String(select.getAttribute("data-row-id") || "");
        state.decisions[rowId] = String(select.value || "keep_current");
        state.manualDecisionRowIds[rowId] = true;
        renderFilters();
        handleFilterChange();
      });
    });
  }

  function updateSortButtons() {
    els.sortButtons.forEach(function (button) {
      const sortKey = String(button.getAttribute("data-portability-sort") || "");
      const isActive = sortKey === state.sortKey;
      const direction = isActive ? state.sortDirection : "";
      const arrow = !isActive ? "" : direction === "asc" ? " ↑" : " ↓";
      const label = sortKey === "approved_at_gmt"
        ? "Applied / Approved On Current Site"
        : humanizeKey(sortKey);

      button.textContent = label + arrow;
      button.setAttribute("aria-sort", !isActive ? "none" : direction === "asc" ? "ascending" : "descending");
      button.classList.toggle("is-active", isActive);
    });
  }

  function toggleSort(sortKey) {
    const nextKey = String(sortKey || "");
    if (!nextKey) {
      return;
    }

    if (state.sortKey === nextKey) {
      state.sortDirection = state.sortDirection === "asc" ? "desc" : "asc";
    } else {
      state.sortKey = nextKey;
      state.sortDirection = "desc";
    }

    updateSortButtons();
    handleFilterChange();
  }

  function getRowById(rowId) {
    return currentRows().find(function (candidate) {
      return String(candidate.row_id || "") === String(rowId || "");
    }) || null;
  }

  function selectRow(rowId) {
    state.selectedRowId = rowId;
    renderTable();
    openRowModal(getRowById(rowId));
  }

  function openRowModal(row) {
    if (!row) {
      return;
    }

    if (!state.isRowModalOpen) {
      state.lastFocusedElement = document.activeElement && typeof document.activeElement.focus === "function"
        ? document.activeElement
        : null;
    }

    renderRowDetail(row);
    state.isRowModalOpen = true;
    if (els.rowModal) {
      els.rowModal.hidden = false;
      els.rowModal.style.display = "block";
    }
    if (document.body && document.body.classList) {
      document.body.classList.add("dbvc-bricks-portability-modal-open");
    }
    if (els.rowModalClose && typeof els.rowModalClose.focus === "function") {
      els.rowModalClose.focus();
    }
  }

  function closeRowModal(restoreFocus) {
    const shouldRestoreFocus = restoreFocus !== false;
    state.isRowModalOpen = false;
    if (els.rowModal) {
      els.rowModal.hidden = true;
      els.rowModal.style.display = "none";
    }
    if (document.body && document.body.classList) {
      document.body.classList.remove("dbvc-bricks-portability-modal-open");
    }
    if (shouldRestoreFocus && state.lastFocusedElement && typeof state.lastFocusedElement.focus === "function") {
      state.lastFocusedElement.focus();
    }
    state.lastFocusedElement = null;
  }

  function renderRowDetail(row) {
    if (!els.detail) {
      return;
    }
    if (!row) {
      if (els.rowModalSubtitle) {
        els.rowModalSubtitle.textContent = "Select a row to inspect its exact incoming versus current diff.";
      }
      els.detail.innerHTML = '<p class="dbvc-bricks-portability-empty">Select a row to inspect its exact incoming versus current diff.</p>';
      return;
    }

    const sourcePayload = getNormalizedPayload(row.source);
    const targetPayload = getNormalizedPayload(row.target);
    const diffEntries = buildDiffEntries(row, sourcePayload, targetPayload);
    const warnings = uniqueStrings([].concat(row.warnings || [], (row.references && row.references.missing_dependencies) || []));
    const matchText = matchLabel(row.match_status || "");
    const statusInfo = statusMeta(row.status || "");
    const decision = actionLabel(ensureDecision(row));
    const references = renderReferenceSummary(row.references || {});
    const verification = renderVerificationSummary(row.verification || {});
    if (els.rowModalSubtitle) {
      els.rowModalSubtitle.textContent = String(row.domain_label || row.domain_key || "Row") + " | " + String(row.object_label || "Untitled object") + " | ID: " + String(row.object_id || "n/a");
    }

    const headerHtml = [
      '<div class="dbvc-bricks-portability-detail-header">',
      '<div class="dbvc-bricks-portability-detail-meta">',
      '<div class="dbvc-bricks-portability-detail-title">' + esc(row.object_label || "Untitled object") + "</div>",
      '<div class="dbvc-bricks-portability-detail-subtitle">' + esc(String(row.domain_label || row.domain_key || "")) + " | ID: " + esc(String(row.object_id || "n/a")) + "</div>",
      "</div>",
      '<div class="dbvc-bricks-portability-detail-badges">',
      '<span class="dbvc-bricks-portability-chip">' + esc(statusInfo.label) + "</span>",
      '<span class="dbvc-bricks-portability-chip">' + esc(matchText) + "</span>",
      '<span class="dbvc-bricks-portability-chip">' + esc("Decision: " + decision) + "</span>",
      "</div>",
      "</div>",
      '<p class="dbvc-bricks-portability-detail-note">' + esc(statusInfo.description || "Review the exact normalized differences below.") + "</p>",
      references,
      verification,
      warnings.length ? renderWarningList(warnings) : "",
    ].join("");

    const diffHtml = diffEntries.length
      ? '<div class="dbvc-bricks-portability-diff-list">' + diffEntries.map(renderDiffEntry).join("") + "</div>"
      : '<div class="dbvc-bricks-portability-diff-empty">No exact path-level differences for this row after normalization.</div>';

    const snapshotsHtml = [
      '<div class="dbvc-bricks-portability-snapshot-grid">',
      '<div class="dbvc-bricks-portability-snapshot-panel">',
      '<h5>Current Site</h5>',
      renderPayloadSnapshot(targetPayload),
      "</div>",
      '<div class="dbvc-bricks-portability-snapshot-panel">',
      '<h5>Incoming Package</h5>',
      renderPayloadSnapshot(sourcePayload),
      "</div>",
      "</div>",
    ].join("");

    els.detail.innerHTML = headerHtml + diffHtml + snapshotsHtml;
    bindRowDetailActions();
  }

  function normalizeDependencyValue(domainKey, value) {
    const normalized = String(value || "").trim();
    if (!normalized) {
      return "";
    }
    if (String(domainKey || "") === "global_variables") {
      return normalized.replace(/^--+/, "").toLowerCase();
    }
    return normalized.toLowerCase();
  }

  function collectDependencyRowCandidates(row, domainKey) {
    const candidates = [];
    const sides = [row, row && row.source, row && row.target];

    sides.forEach(function (side) {
      if (!side || typeof side !== "object") {
        return;
      }
      candidates.push(side.object_label);
      candidates.push(side.object_id);
      const matchKeys = side.match_keys && typeof side.match_keys === "object" ? side.match_keys : {};
      ["token", "name", "slug", "id", "selector", "map_key"].forEach(function (key) {
        candidates.push(matchKeys[key]);
      });
    });

    return uniqueStrings(candidates.map(function (candidate) {
      return normalizeDependencyValue(domainKey, candidate);
    }));
  }

  function findDependencyRows(domainKey, values) {
    const wanted = uniqueStrings((Array.isArray(values) ? values : []).map(function (value) {
      return normalizeDependencyValue(domainKey, value);
    })).filter(Boolean);
    if (!wanted.length) {
      return [];
    }

    return currentRows().filter(function (row) {
      if (!row || String(row.domain_key || "") !== String(domainKey || "") || !row.source) {
        return false;
      }
      const candidates = collectDependencyRowCandidates(row, domainKey);
      return wanted.some(function (wantedValue) {
        return candidates.indexOf(wantedValue) !== -1;
      });
    });
  }

  function findMetadataRows(domainKey, optionName) {
    const normalizedDomainKey = String(domainKey || "");
    const normalizedOptionName = String(optionName || "");
    if (!normalizedDomainKey || !normalizedOptionName) {
      return [];
    }

    return currentRows().filter(function (row) {
      if (!row || String(row.domain_key || "") !== normalizedDomainKey || String(row.row_type || "") !== "meta") {
        return false;
      }
      if (String(row.object_id || "") === normalizedOptionName) {
        return true;
      }
      const source = row.source && typeof row.source === "object" ? row.source : {};
      return String(source.option_name || "") === normalizedOptionName;
    });
  }

  function categoryDomainKeyFromOption(optionName) {
    const normalized = String(optionName || "");
    if (normalized === "bricks_global_classes_categories") {
      return "global_classes";
    }
    if (normalized === "bricks_global_variables_categories") {
      return "global_variables";
    }
    return "";
  }

  function preferredIncomingDecision(row) {
    const options = Array.isArray(row && row.available_actions) ? row.available_actions : [];
    const suggested = String(row && row.suggested_action ? row.suggested_action : "");
    if ((suggested === "add_incoming" || suggested === "replace_with_incoming") && options.indexOf(suggested) !== -1) {
      return suggested;
    }
    if (options.indexOf("add_incoming") !== -1) {
      return "add_incoming";
    }
    if (options.indexOf("replace_with_incoming") !== -1) {
      return "replace_with_incoming";
    }
    return "";
  }

  function dependencyActionKey(domainKey, rowIds) {
    return String(domainKey || "") + "::" + uniqueStrings(Array.isArray(rowIds) ? rowIds : String(rowIds || "").split(",")).join(",");
  }

  function renderDependencyAction(actionConfig) {
    const domainKey = String(actionConfig && actionConfig.domainKey ? actionConfig.domainKey : "");
    const values = Array.isArray(actionConfig && actionConfig.values) ? actionConfig.values : [];
    const metadataOptionName = String(actionConfig && actionConfig.metadataOptionName ? actionConfig.metadataOptionName : "");
    const rows = metadataOptionName
      ? findMetadataRows(domainKey, metadataOptionName)
      : findDependencyRows(domainKey, values);
    const rowIds = rows.map(function (row) {
      return String(row.row_id || "");
    }).filter(Boolean);
    const actionableRowIds = rowIds.filter(function (rowId) {
      const row = getRowById(rowId);
      return Boolean(preferredIncomingDecision(row));
    });

    if (!rowIds.length) {
      return "";
    }

    const approvedCount = actionableRowIds.filter(function (rowId) {
      const row = getRowById(rowId);
      const preferred = preferredIncomingDecision(row);
      return preferred && ensureDecision(row) === preferred;
    }).length;
    const allApproved = actionableRowIds.length > 0 && approvedCount === actionableRowIds.length;
    const helperText = String(actionConfig.helperText || "");
    const buttonLabel = String(actionConfig.buttonLabel || "Include");
    const nounLabel = String(actionConfig.nounLabel || "dependency");
    const feedback = allApproved
      ? state.dependencyActionFeedback[dependencyActionKey(domainKey, actionableRowIds)] || null
      : null;
    const wrapperClass = "dbvc-bricks-portability-reference-actions" + (feedback && feedback.status === "success" ? " is-success" : "");
    const statusMessage = feedback && feedback.message
      ? feedback.message
      : allApproved
        ? "All linked " + nounLabel + " rows are marked for incoming apply."
        : "Click to mark linked " + nounLabel + " rows for incoming apply.";

    return [
      '<div class="' + esc(wrapperClass) + '">',
      actionableRowIds.length
        ? '<button type="button" class="button button-small dbvc-bricks-portability-reference-action' + (feedback && feedback.status === "success" ? ' is-success' : '') + '" data-domain-key="' + esc(domainKey) + '" data-row-ids="' + esc(actionableRowIds.join(",")) + '" data-noun-label="' + esc(nounLabel) + '">' + esc(buttonLabel) + "</button>"
        : "",
      '<span class="description">' + esc(String(rowIds.length) + " linked " + nounLabel + " row" + (rowIds.length === 1 ? "" : "s") + (helperText ? ". " + helperText : "") + (allApproved ? " Already marked for incoming apply." : approvedCount > 0 ? " " + String(approvedCount) + " already marked." : "")) + "</span>",
      '<span class="dbvc-bricks-portability-reference-action-status" role="status" aria-live="polite">' + esc(statusMessage) + "</span>",
      "</div>",
    ].join("");
  }

  function renderDependencyGroup(groupConfig) {
    const values = Array.isArray(groupConfig && groupConfig.values) ? groupConfig.values : [];
    if (!values.length) {
      return "";
    }

    const actionHtml = groupConfig && groupConfig.action ? renderDependencyAction(groupConfig.action) : "";
    return [
      "<li>",
      "<strong>" + esc(String(groupConfig.label || "")) + ":</strong> ",
      '<span class="dbvc-bricks-portability-reference-analysis__tokens">' + values.map(function (value) {
        return "<code>" + esc(String(value || "")) + "</code>";
      }).join(" ") + "</span>",
      actionHtml,
      "</li>",
    ].join("");
  }

  function renderReferenceSummary(references) {
    const cssVariables = Array.isArray(references.css_variables) ? references.css_variables : [];
    const classNames = Array.isArray(references.class_names) ? references.class_names : [];
    const categoryValues = Array.isArray(references.category_values) ? references.category_values : [];
    const cssVariableDependencies = references.css_variable_dependencies && typeof references.css_variable_dependencies === "object"
      ? references.css_variable_dependencies
      : {};
    const categoryDependencies = references.category_dependencies && typeof references.category_dependencies === "object"
      ? references.category_dependencies
      : {};
    const classDependencyWarnings = Array.isArray(references.class_dependencies_missing_on_current)
      ? references.class_dependencies_missing_on_current
      : [];
    const hasDependencyAnalysis = Boolean(
      classDependencyWarnings.length ||
      (Array.isArray(categoryDependencies.missing_on_current_supplied_by_incoming) && categoryDependencies.missing_on_current_supplied_by_incoming.length) ||
      (Array.isArray(categoryDependencies.missing_on_both) && categoryDependencies.missing_on_both.length) ||
      (Array.isArray(cssVariableDependencies.missing_on_current_supplied_by_incoming) && cssVariableDependencies.missing_on_current_supplied_by_incoming.length) ||
      (Array.isArray(cssVariableDependencies.missing_on_both) && cssVariableDependencies.missing_on_both.length) ||
      (Array.isArray(cssVariableDependencies.possibly_external) && cssVariableDependencies.possibly_external.length)
    );
    if (cssVariables.length === 0 && classNames.length === 0 && categoryValues.length === 0 && !hasDependencyAnalysis) {
      return "";
    }

    const parts = [];
    if (cssVariables.length > 0) {
      parts.push("CSS Variables: " + cssVariables.join(", "));
    }
    if (classNames.length > 0) {
      parts.push("Classes: " + classNames.join(", "));
    }
    if (categoryValues.length > 0) {
      parts.push("Categories: " + categoryValues.join(", "));
    }

    const dependencyGroups = [
      {
        label: "Missing on Current Site but supplied by Incoming Package",
        values: Array.isArray(cssVariableDependencies.missing_on_current_supplied_by_incoming) ? cssVariableDependencies.missing_on_current_supplied_by_incoming : [],
        action: {
          domainKey: "global_variables",
          values: Array.isArray(cssVariableDependencies.missing_on_current_supplied_by_incoming) ? cssVariableDependencies.missing_on_current_supplied_by_incoming : [],
          buttonLabel: "Include These Missing Variables",
          nounLabel: "variable",
          helperText: "Marks the linked Bricks Global CSS Variable rows for incoming apply.",
        },
      },
      {
        label: "Missing on both Current Site and Incoming Package",
        values: Array.isArray(cssVariableDependencies.missing_on_both) ? cssVariableDependencies.missing_on_both : [],
      },
      {
        label: "Possibly external or non-Bricks managed",
        values: Array.isArray(cssVariableDependencies.possibly_external) ? cssVariableDependencies.possibly_external : [],
      },
    ];

    if (categoryDependencies.option_name) {
      dependencyGroups.push({
        label: "Missing categories on Current Site but supplied by Incoming Package",
        values: Array.isArray(categoryDependencies.missing_on_current_supplied_by_incoming) ? categoryDependencies.missing_on_current_supplied_by_incoming : [],
        action: {
          domainKey: categoryDomainKeyFromOption(categoryDependencies.option_name),
          metadataOptionName: String(categoryDependencies.option_name || ""),
          buttonLabel: "Include These Missing Categories",
          nounLabel: "category metadata",
          helperText: "Marks the related Bricks categories metadata row for incoming apply.",
        },
      });
      dependencyGroups.push({
        label: "Missing categories on both Current Site and Incoming Package",
        values: Array.isArray(categoryDependencies.missing_on_both) ? categoryDependencies.missing_on_both : [],
      });
    }

    const dependencyHtml = [];
    dependencyGroups.forEach(function (group) {
      const groupHtml = renderDependencyGroup(group);
      if (groupHtml) {
        dependencyHtml.push(groupHtml);
      }
    });
    if (classDependencyWarnings.length) {
      dependencyHtml.push(renderDependencyGroup({
        label: "Missing class dependencies on Current Site",
        values: classDependencyWarnings,
        action: {
          domainKey: "global_classes",
          values: classDependencyWarnings,
          buttonLabel: "Include These Missing Classes",
          nounLabel: "class",
          helperText: "Marks the linked Bricks Global Classes rows for incoming apply when available in this package.",
        },
      }));
    }

    return [
      parts.length ? '<div class="dbvc-bricks-portability-reference-summary">' + esc(parts.join(" | ")) + "</div>" : "",
      dependencyHtml.length
        ? '<div class="dbvc-bricks-portability-reference-analysis"><strong>Dependency Analysis</strong><ul>' + dependencyHtml.join("") + "</ul></div>"
        : "",
    ].join("");
  }

  function hasVerificationReport(report) {
    return Boolean(report && typeof report === "object" && report.status && String(report.status || "") !== "not_required");
  }

  function renderVerificationBlock(title, report) {
    const detailBits = [];
    if (Array.isArray(report.detected_path) && report.detected_path.length) {
      detailBits.push("Path: " + report.detected_path.join("."));
    }
    if (report.entry_count) {
      detailBits.push("Entries: " + String(report.entry_count));
    }
    if (report.recognized_count || report.unrecognized_count) {
      detailBits.push("Recognized: " + String(report.recognized_count || 0) + " / " + String(report.entry_count || 0));
    }
    return [
      '<div class="dbvc-bricks-portability-verification-item">',
      '<div class="dbvc-bricks-portability-verification-item__title">' + esc(title) + "</div>",
      '<div class="dbvc-bricks-portability-verification-item__status">' + esc(verificationStatusLabel(report.status || "")) + "</div>",
      detailBits.length ? '<div class="description">' + esc(detailBits.join(" | ")) + "</div>" : "",
      Array.isArray(report.warnings) && report.warnings.length
        ? '<ul class="dbvc-bricks-portability-verification-item__warnings">' + report.warnings.map(function (warning) {
          return "<li>" + esc(String(warning || "")) + "</li>";
        }).join("") + "</ul>"
        : "",
      "</div>",
    ].join("");
  }

  function renderVerificationSummary(verification) {
    const source = verification && verification.source && typeof verification.source === "object" ? verification.source : null;
    const target = verification && verification.target && typeof verification.target === "object" ? verification.target : null;
    const blocks = [];

    if (hasVerificationReport(target)) {
      blocks.push(renderVerificationBlock("Current Site Verification", target));
    }
    if (hasVerificationReport(source)) {
      blocks.push(renderVerificationBlock("Incoming Package Verification", source));
    }

    if (!blocks.length) {
      return "";
    }

    return '<div class="dbvc-bricks-portability-verification-summary"><strong>Storage Verification</strong>' + blocks.join("") + "</div>";
  }

  function renderWarningList(warnings) {
    return '<div class="dbvc-bricks-portability-warning-box"><strong>Warnings</strong><ul>' + warnings.map(function (warning) {
      return "<li>" + esc(warning) + "</li>";
    }).join("") + "</ul></div>";
  }

  function applyDependencyDecision(domainKey, rowIds, nounLabel) {
    const targets = uniqueStrings(String(rowIds || "").split(","));
    const label = String(nounLabel || "dependency");
    if (!targets.length) {
      showNotice("error", "No linked dependency rows were available to update.");
      return;
    }

    let appliedCount = 0;
    let skippedCount = 0;
    targets.forEach(function (rowId) {
      const row = getRowById(rowId);
      const decision = preferredIncomingDecision(row);
      if (!row || !decision) {
        skippedCount++;
        return;
      }
      state.decisions[rowId] = decision;
      state.manualDecisionRowIds[rowId] = true;
      appliedCount++;
    });

    if (appliedCount > 0) {
      state.dependencyActionFeedback[dependencyActionKey(domainKey, targets)] = {
        status: "success",
        message: "Included " + String(appliedCount) + " linked " + label + " row" + (appliedCount === 1 ? "" : "s") + ". Ready to apply approved changes.",
      };
    }

    renderFilters();
    handleFilterChange();
    if (state.selectedRowId && state.isRowModalOpen) {
      renderRowDetail(getRowById(state.selectedRowId));
    }

    if (!appliedCount) {
      showNotice("error", "No linked dependency rows could be marked for incoming apply.");
      return;
    }

    showNotice(
      "success",
      "Marked " + String(appliedCount) + " linked dependency row" + (appliedCount === 1 ? "" : "s") + " for incoming apply" + (skippedCount ? ". " + String(skippedCount) + " row" + (skippedCount === 1 ? " was" : "s were") + " skipped." : ".")
    );
  }

  function bindRowDetailActions() {
    if (!els.detail) {
      return;
    }
    Array.prototype.forEach.call(els.detail.querySelectorAll(".dbvc-bricks-portability-reference-action"), function (button) {
      button.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        applyDependencyDecision(
          String(button.getAttribute("data-domain-key") || ""),
          String(button.getAttribute("data-row-ids") || ""),
          String(button.getAttribute("data-noun-label") || "dependency")
        );
      });
    });
  }

  function buildDiffEntries(row, sourcePayload, targetPayload) {
    const pathSummary = row && row.path_summary && typeof row.path_summary === "object" ? row.path_summary : {};
    const entries = [];

    ["changed", "added", "removed"].forEach(function (bucket) {
      const paths = Array.isArray(pathSummary[bucket]) ? pathSummary[bucket] : [];
      paths.forEach(function (path) {
        const safePath = String(path || "");
        entries.push({
          bucket: bucket,
          label: diffBucketLabel(bucket),
          path: safePath,
          currentValue: bucket === "added" ? undefined : getValueAtPath(targetPayload, safePath),
          incomingValue: bucket === "removed" ? undefined : getValueAtPath(sourcePayload, safePath),
        });
      });
    });

    if (entries.length === 0 && sourcePayload !== null && targetPayload === null) {
      entries.push({
        bucket: "added",
        label: diffBucketLabel("added"),
        path: "(entire object)",
        currentValue: undefined,
        incomingValue: sourcePayload,
      });
    }
    if (entries.length === 0 && sourcePayload === null && targetPayload !== null) {
      entries.push({
        bucket: "removed",
        label: diffBucketLabel("removed"),
        path: "(entire object)",
        currentValue: targetPayload,
        incomingValue: undefined,
      });
    }

    return entries;
  }

  function renderDiffEntry(entry) {
    const bucketClass = "dbvc-bricks-portability-diff-entry dbvc-bricks-portability-diff-entry--" + safeKey(entry.bucket || "");
    return [
      '<div class="' + esc(bucketClass) + '">',
      '<div class="dbvc-bricks-portability-diff-path">',
      '<span class="dbvc-bricks-portability-diff-label">' + esc(entry.label || "") + "</span>",
      "<code>" + esc(entry.path || "") + "</code>",
      "</div>",
      '<div class="dbvc-bricks-portability-diff-grid">',
      '<div class="dbvc-bricks-portability-diff-side">',
      '<div class="dbvc-bricks-portability-diff-side-label">Current Site</div>',
      renderValuePreview(entry.currentValue),
      "</div>",
      '<div class="dbvc-bricks-portability-diff-side">',
      '<div class="dbvc-bricks-portability-diff-side-label">Incoming Package</div>',
      renderValuePreview(entry.incomingValue),
      "</div>",
      "</div>",
      "</div>",
    ].join("");
  }

  function renderValuePreview(value) {
    if (typeof value === "undefined") {
      return '<div class="dbvc-bricks-portability-value-empty">Not present</div>';
    }
    return '<pre class="dbvc-bricks-portability-value-preview">' + esc(stringifyValue(value)) + "</pre>";
  }

  function renderPayloadSnapshot(payload) {
    if (payload === null || typeof payload === "undefined") {
      return '<div class="dbvc-bricks-portability-value-empty">Not present</div>';
    }
    return '<pre class="dbvc-bricks-portability-value-preview">' + esc(stringifyValue(payload)) + "</pre>";
  }

  function getNormalizedPayload(side) {
    if (!side || typeof side !== "object") {
      return null;
    }
    return Object.prototype.hasOwnProperty.call(side, "normalized") ? side.normalized : null;
  }

  function getValueAtPath(payload, path) {
    if (!path || path === "(entire object)") {
      return payload;
    }
    const segments = String(path).split(".");
    let current = payload;
    for (let index = 0; index < segments.length; index++) {
      const segment = segments[index];
      if (current === null || typeof current === "undefined") {
        return undefined;
      }
      if (Array.isArray(current)) {
        const nextIndex = Number(segment);
        if (!Number.isInteger(nextIndex) || nextIndex < 0 || nextIndex >= current.length) {
          return undefined;
        }
        current = current[nextIndex];
        continue;
      }
      if (typeof current !== "object" || !Object.prototype.hasOwnProperty.call(current, segment)) {
        return undefined;
      }
      current = current[segment];
    }
    return current;
  }

  function stringifyValue(value) {
    if (typeof value === "string") {
      return JSON.stringify(value);
    }
    if (typeof value === "undefined") {
      return "Not present";
    }
    const encoded = JSON.stringify(value, null, 2);
    return encoded === undefined ? String(value) : encoded;
  }

  function uniqueStrings(values) {
    const seen = {};
    return (Array.isArray(values) ? values : []).map(function (value) {
      return String(value || "").trim();
    }).filter(function (value) {
      if (!value || seen[value]) {
        return false;
      }
      seen[value] = true;
      return true;
    });
  }

  function renderDomainSummary() {
    if (!els.domainSummary) {
      return;
    }
    const summaries = state.currentSession && Array.isArray(state.currentSession.domain_summaries) ? state.currentSession.domain_summaries : [];
    if (summaries.length === 0) {
      els.domainSummary.textContent = "Import a package to load per-domain summaries.";
      return;
    }

    const lines = [];
    summaries.forEach(function (summary) {
      const statuses = summary && typeof summary.statuses === "object" ? summary.statuses : {};
      lines.push(String(summary.domain_label || summary.domain_key || "Domain") + " [" + domainStatusLabel(summary.domain_status || "") + "]");
      lines.push("Rows: " + String(summary.total_rows || 0) + " | Actionable: " + String(summary.actionable_rows || 0) + " | Warnings: " + String(summary.warning_rows || 0));
      Object.keys(statuses).sort().forEach(function (statusKey) {
        lines.push("  - " + statusLabel(statusKey) + ": " + String(statuses[statusKey] || 0));
      });
      lines.push("");
    });

    els.domainSummary.textContent = lines.join("\n").trim();
  }

  function renderSummary() {
    if (!els.summary) {
      return;
    }
    if (!state.currentSession) {
      els.summary.textContent = "Import a package to load drift rows.";
      return;
    }
    const summary = state.currentSession.summary || {};
    els.summary.textContent = "Rows: " + String(summary.total_rows || 0) + " | Actionable Incoming Changes: " + String(summary.actionable_rows || 0) + " | Warnings: " + String(summary.warning_rows || 0);
  }

  function buildStatCard(label, value, detail, modifier) {
    const className = "dbvc-bricks-portability-stat-card" + (modifier ? " dbvc-bricks-portability-stat-card--" + safeKey(modifier) : "");
    return [
      '<div class="' + esc(className) + '">',
      '<div class="dbvc-bricks-portability-stat-card__label">' + esc(label) + "</div>",
      '<div class="dbvc-bricks-portability-stat-card__value">' + esc(String(value)) + "</div>",
      detail ? '<div class="dbvc-bricks-portability-stat-card__detail">' + esc(detail) + "</div>" : "",
      "</div>",
    ].join("");
  }

  function renderStats() {
    if (!els.stats) {
      return;
    }
    if (!state.currentSession) {
      els.stats.textContent = "Import a package to load review totals.";
      return;
    }

    const allRows = currentRows();
    const totalRows = allRows.length;
    const visibleRows = filteredRows().length;
    let incomingCount = 0;
    let currentCount = 0;
    const actionCounts = {};

    actionKeys().forEach(function (actionKey) {
      actionCounts[actionKey] = 0;
    });

    allRows.forEach(function (row) {
      if (row && row.source) {
        incomingCount++;
      }
      if (row && row.target) {
        currentCount++;
      }
      const decision = ensureDecision(row || {});
      if (!Object.prototype.hasOwnProperty.call(actionCounts, decision)) {
        actionCounts[decision] = 0;
      }
      actionCounts[decision]++;
    });

    const cards = [
      buildStatCard("Total Review Rows", totalRows, visibleRows + " visible with current filters", "rows"),
      buildStatCard("Incoming Package", incomingCount, "rows present in uploaded package", "incoming"),
      buildStatCard("Current Site", currentCount, "rows present on this site", "current"),
    ];

    actionKeys().forEach(function (actionKey) {
      cards.push(
        buildStatCard(
          actionLabel(actionKey),
          actionCounts[actionKey] || 0,
          "of " + String(totalRows) + " total review rows",
          actionKey
        )
      );
    });

    els.stats.innerHTML = cards.join("");
  }

  function handleFilterChange() {
    const selectedRow = state.selectedRowId ? getRowById(state.selectedRowId) : null;
    const filtered = filteredRows();
    const sortedFiltered = sortedRows(filtered);
    const stillVisible = selectedRow ? filtered.some(function (row) {
      return String(row.row_id || "") === state.selectedRowId;
    }) : false;

    if (!stillVisible) {
      state.selectedRowId = "";
      closeRowModal(false);
    }

    renderStats();
    renderPagination(sortedFiltered);
    renderTable(sortedFiltered);
    updateSortButtons();
    if (stillVisible && state.isRowModalOpen) {
      renderRowDetail(selectedRow);
    } else if (!stillVisible) {
      renderRowDetail(null);
    }
  }

  function resetToFirstPageAndRender() {
    state.page = 1;
    handleFilterChange();
  }

  function renderSession(session) {
    closeRowModal(false);
    state.currentSession = session;
    state.decisions = hydrateDraftDecisions(session);
    state.manualDecisionRowIds = hydrateManualDecisionRowIds(session);
    state.dependencyActionFeedback = {};
    state.selectedRowId = "";
    state.page = 1;
    renderSessionMeta();
    renderReviewState();
    renderAppliedSummary();
    renderFilters();
    renderSummary();
    renderStats();
    renderDomainSummary();
    renderPagination(sortedRows(filteredRows()));
    renderTable(sortedRows(filteredRows()));
    renderRowDetail(null);
  }

  function exportSelectedDomains() {
    const domains = selectedDomainKeys();
    if (domains.length === 0) {
      showNotice("error", "Select at least one portability domain.");
      return;
    }
    api(cfg.exportEndpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": "bricks-portability-export-" + Date.now(),
      },
      body: JSON.stringify({
        domains: domains,
        notes: els.exportNotes ? String(els.exportNotes.value || "") : "",
      }),
    }).then(function () {
      showNotice("success", (cfg.messages && cfg.messages.exported) || "Export created.");
      return loadStatus();
    }).catch(function (error) {
      showNotice("error", error.message || "Export failed.");
    });
  }

  function importPackage() {
    const file = els.importFile && els.importFile.files ? els.importFile.files[0] : null;
    if (!file) {
      showNotice("error", "Choose a ZIP package to import.");
      return;
    }
    const formData = new FormData();
    formData.append("file", file);
    api(cfg.importEndpoint, {
      method: "POST",
      headers: {
        "Idempotency-Key": "bricks-portability-import-" + Date.now(),
      },
      body: formData,
    }).then(function (session) {
      showNotice("success", (cfg.messages && cfg.messages.imported) || "Package imported.");
      renderSession(session);
      return loadStatus();
    }).catch(function (error) {
      showNotice("error", error.message || "Import failed.");
    });
  }

  function openSession(sessionId) {
    if (!sessionId) {
      return;
    }
    api(String(cfg.sessionEndpointBase || "") + encodeURIComponent(sessionId), {
      method: "GET",
    }).then(function (session) {
      renderSession(session);
    }).catch(function (error) {
      showNotice("error", error.message || "Failed to load review session.");
    });
  }

  function refreshCurrentSession() {
    if (!state.currentSession || !state.currentSession.session_id) {
      showNotice("error", "Open a review session before refreshing the current-site compare.");
      return;
    }

    api(String(cfg.sessionRefreshBase || "") + encodeURIComponent(String(state.currentSession.session_id || "")) + "/refresh", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": "bricks-portability-refresh-" + Date.now(),
      },
      body: JSON.stringify({}),
    }).then(function (session) {
      showNotice("success", (cfg.messages && cfg.messages.refreshed) || "Compare refreshed.");
      renderSession(session);
      return loadStatus();
    }).catch(function (error) {
      showNotice("error", error.message || "Failed to refresh the current-site compare.");
    });
  }

  function saveDraftDecisions() {
    if (!state.currentSession) {
      showNotice("error", "Import a package and review rows before saving a draft.");
      return;
    }

    api(String(cfg.sessionDraftBase || "") + encodeURIComponent(String(state.currentSession.session_id || "")) + "/draft", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": "bricks-portability-draft-" + Date.now(),
      },
      body: JSON.stringify({
        decisions: state.decisions,
        manual_decisions: Object.keys(state.manualDecisionRowIds || {}),
      }),
    }).then(function (session) {
      state.currentSession = session;
      state.decisions = hydrateDraftDecisions(session);
      state.manualDecisionRowIds = hydrateManualDecisionRowIds(session);
      state.sessions = (Array.isArray(state.sessions) ? state.sessions : []).map(function (item) {
        if (String(item && item.session_id ? item.session_id : "") !== String(session.session_id || "")) {
          return item;
        }
        const nextItem = Object.assign({}, item || {});
        nextItem.draft = session.draft || {};
        return nextItem;
      });
      renderSessionMeta();
      renderReviewState();
      renderAppliedSummary();
      renderFilters();
      renderStats();
      renderTable();
      renderSessions();
      if (state.selectedRowId && state.isRowModalOpen) {
        renderRowDetail(getRowById(state.selectedRowId));
      }
      showNotice("success", (cfg.messages && cfg.messages.draftSaved) || "Draft saved.");
    }).catch(function (error) {
      showNotice("error", error.message || "Failed to save draft decisions.");
    });
  }

  function applyBulkAction() {
    const action = els.bulkAction ? String(els.bulkAction.value || "") : "";
    if (!action) {
      return;
    }
    let appliedCount = 0;
    let skippedCount = 0;
    filteredRows().forEach(function (row) {
      const options = Array.isArray(row.available_actions) ? row.available_actions : [];
      if (options.indexOf(action) !== -1) {
        const rowId = String(row.row_id || "");
        state.decisions[rowId] = action;
        state.manualDecisionRowIds[rowId] = true;
        appliedCount++;
      } else {
        skippedCount++;
      }
    });
    renderFilters();
    handleFilterChange();
    if (appliedCount <= 0) {
      showNotice("error", "No filtered rows allow that bulk action.");
      return;
    }
    showNotice(
      "success",
      "Applied " + actionLabel(action) + " to " + String(appliedCount) + " filtered row" + (appliedCount === 1 ? "" : "s") + (skippedCount > 0 ? ". " + String(skippedCount) + " row" + (skippedCount === 1 ? " was" : "s were") + " skipped because that action is not allowed." : ".")
    );
  }

  function applyApprovedChanges() {
    if (!state.currentSession) {
      showNotice("error", "Import a package and review rows before apply.");
      return;
    }
    if (!isApplyConfirmed()) {
      showNotice("error", "Confirm apply before continuing.");
      return;
    }
    api(cfg.applyEndpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": "bricks-portability-apply-" + Date.now(),
      },
      body: JSON.stringify({
        session_id: String(state.currentSession.session_id || ""),
        decisions: state.decisions,
        manual_decisions: Object.keys(state.manualDecisionRowIds || {}),
        confirm_apply: true,
      }),
    }).then(function (result) {
      showNotice("success", (result && result.message) || (cfg.messages && cfg.messages.applied) || "Apply completed.");
      setConfirmApplyState(false);
      return loadStatus();
    }).catch(function (error) {
      showNotice("error", error.message || "Apply failed.");
    });
  }

  function rollbackBackup(backupId) {
    if (!backupId) {
      return;
    }
    if (!window.confirm("Rollback this portability backup?")) {
      return;
    }
    api(String(cfg.backupRollbackBase || "") + encodeURIComponent(backupId) + "/rollback", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": "bricks-portability-rollback-" + Date.now(),
      },
      body: JSON.stringify({
        confirm_rollback: true,
      }),
    }).then(function () {
      showNotice("success", (cfg.messages && cfg.messages.rolledBack) || "Rollback completed.");
      return loadStatus();
    }).catch(function (error) {
      showNotice("error", error.message || "Rollback failed.");
    });
  }

  function loadStatus() {
    return api(cfg.statusEndpoint, { method: "GET" }).then(function (data) {
      state.domains = Array.isArray(data.domains) ? data.domains : [];
      state.exports = Array.isArray(data.recent_exports) ? data.recent_exports : [];
      state.sessions = Array.isArray(data.recent_sessions) ? data.recent_sessions : [];
      state.backups = Array.isArray(data.recent_backups) ? data.recent_backups : [];
      state.jobs = Array.isArray(data.recent_jobs) ? data.recent_jobs : [];
      renderDomains();
      renderExports();
      renderSessions();
      renderBackups();
      renderJobs();
      if (state.currentSession && state.currentSession.session_id) {
        openSession(String(state.currentSession.session_id));
      }
    }).catch(function (error) {
      showNotice("error", error.message || "Failed to load portability status.");
    });
  }

  function esc(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function normalizeUrl(value) {
    return String(value || "").replace(/&amp;/g, "&");
  }

  function safeKey(value) {
    return String(value || "").toLowerCase().replace(/[^a-z0-9_-]+/g, "-");
  }

  function bindSubtabEvents() {
    els.subtabButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        activateSubtab(String(button.getAttribute("data-portability-tab") || "workspace"));
      });
    });
  }

  function bindNoticeDismissHandlers() {
    document.addEventListener("click", function (event) {
      const target = event.target;
      if (!target || !target.closest) {
        return;
      }

      if (target.closest("#dbvc-bricks-notice-success .notice-dismiss")) {
        setNoticeState(els.successNotice, false, "");
      } else if (target.closest("#dbvc-bricks-notice-error .notice-dismiss")) {
        setNoticeState(els.errorNotice, false, "");
      }
    });
  }

  function bindRowModalEvents() {
    if (els.rowModal) {
      els.rowModal.addEventListener("click", function (event) {
        const target = event.target;
        if (!target || !target.closest) {
          return;
        }
        if (target.closest("[data-portability-modal-close]")) {
          closeRowModal();
        }
      });
    }

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && state.isRowModalOpen) {
        closeRowModal();
      }
    });
  }

  if (els.exportButton) {
    els.exportButton.addEventListener("click", exportSelectedDomains);
  }
  if (els.importButton) {
    els.importButton.addEventListener("click", importPackage);
  }
  if (els.bulkApply) {
    els.bulkApply.addEventListener("click", applyBulkAction);
  }
  els.confirmApplyBoxes.forEach(function (checkbox) {
    checkbox.addEventListener("change", function () {
      setConfirmApplyState(Boolean(checkbox.checked), checkbox);
    });
  });
  els.saveDraftButtons.forEach(function (button) {
    button.addEventListener("click", saveDraftDecisions);
  });
  els.applyButtons.forEach(function (button) {
    button.addEventListener("click", applyApprovedChanges);
  });
  if (els.filterDomain) {
    els.filterDomain.addEventListener("change", resetToFirstPageAndRender);
  }
  if (els.filterStatus) {
    els.filterStatus.addEventListener("change", resetToFirstPageAndRender);
  }
  if (els.filterDecision) {
    els.filterDecision.addEventListener("change", resetToFirstPageAndRender);
  }
  if (els.filterWarning) {
    els.filterWarning.addEventListener("change", resetToFirstPageAndRender);
  }
  if (els.filterSearch) {
    els.filterSearch.addEventListener("input", resetToFirstPageAndRender);
  }
  if (els.hideIdentical) {
    els.hideIdentical.addEventListener("change", resetToFirstPageAndRender);
  }
  if (els.pageSize) {
    els.pageSize.addEventListener("change", function () {
      const nextSize = Number(els.pageSize.value || 50);
      state.pageSize = nextSize > 0 ? nextSize : 50;
      state.page = 1;
      handleFilterChange();
    });
  }
  if (els.pagePrev) {
    els.pagePrev.addEventListener("click", function () {
      if (state.page <= 1) {
        return;
      }
      state.page -= 1;
      handleFilterChange();
    });
  }
  if (els.pageNext) {
    els.pageNext.addEventListener("click", function () {
      state.page += 1;
      handleFilterChange();
    });
  }
  els.sortButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      toggleSort(String(button.getAttribute("data-portability-sort") || ""));
    });
  });

  bindSubtabEvents();
  bindNoticeDismissHandlers();
  bindRowModalEvents();
  setNoticeState(els.successNotice, false, "");
  setNoticeState(els.errorNotice, false, "");
  closeRowModal(false);
  renderRowDetail(null);
  if (els.pageSize) {
    state.pageSize = Number(els.pageSize.value || 50) > 0 ? Number(els.pageSize.value || 50) : 50;
  }
  renderReviewState();
  renderAppliedSummary();
  activateSubtab(state.activeSubtab);
  updateSortButtons();
  loadStatus();
})();
