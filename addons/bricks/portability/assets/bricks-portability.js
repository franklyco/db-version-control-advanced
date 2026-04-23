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
    selectedRowId: "",
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
    filterDomain: document.getElementById("dbvc-bricks-portability-filter-domain"),
    filterStatus: document.getElementById("dbvc-bricks-portability-filter-status"),
    filterSearch: document.getElementById("dbvc-bricks-portability-filter-search"),
    bulkAction: document.getElementById("dbvc-bricks-portability-bulk-action"),
    bulkApply: document.getElementById("dbvc-bricks-portability-apply-bulk"),
    tableBody: document.getElementById("dbvc-bricks-portability-table-body"),
    detail: document.getElementById("dbvc-bricks-portability-detail"),
    domainSummary: document.getElementById("dbvc-bricks-portability-domain-summary"),
    confirmApply: document.getElementById("dbvc-bricks-portability-confirm-apply"),
    applyButton: document.getElementById("dbvc-bricks-portability-apply-button"),
    backupsBody: document.getElementById("dbvc-bricks-portability-backups-body"),
    jobs: document.getElementById("dbvc-bricks-portability-jobs"),
    successNotice: document.getElementById("dbvc-bricks-notice-success"),
    errorNotice: document.getElementById("dbvc-bricks-notice-error"),
  };

  function showNotice(type, message) {
    const target = type === "error" ? els.errorNotice : els.successNotice;
    const other = type === "error" ? els.successNotice : els.errorNotice;
    if (other) {
      other.style.display = "none";
    }
    if (!target) {
      return;
    }
    const p = target.querySelector("p");
    if (p) {
      p.textContent = message || "";
    }
    target.style.display = message ? "block" : "none";
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
      return '<label class="dbvc-bricks-portability-domain-option"><input type="checkbox" name="dbvc-bricks-portability-domain" value="' + esc(domainKey) + '"' + disabled + ' /> <strong>' + esc(domain.label || domainKey) + '</strong>' + risk + verification + note + "</label>";
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
      return '<tr><td><code>' + esc(item.session_id || "") + '</code></td><td><code>' + esc(item.package_id || "") + '</code></td><td>' + esc("rows: " + String(summary.total_rows || 0) + ", actionable: " + String(summary.actionable_rows || 0)) + '</td><td><button type="button" class="button button-small dbvc-bricks-portability-open-session" data-session-id="' + esc(item.session_id || "") + '">Open</button></td></tr>';
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
    const lines = [
      "Session: " + String(state.currentSession.session_id || ""),
      "Package: " + String(state.currentSession.package_id || ""),
      "Rows: " + String(summary.total_rows || 0),
      "Actionable: " + String(summary.actionable_rows || 0),
      "Warnings: " + String(summary.warning_rows || 0),
    ];
    els.sessionMeta.textContent = lines.join("\n");
  }

  function currentRows() {
    return state.currentSession && Array.isArray(state.currentSession.rows) ? state.currentSession.rows : [];
  }

  function filteredRows() {
    const selectedDomain = els.filterDomain ? String(els.filterDomain.value || "") : "";
    const selectedStatus = els.filterStatus ? String(els.filterStatus.value || "") : "";
    const search = els.filterSearch ? String(els.filterSearch.value || "").toLowerCase() : "";
    return currentRows().filter(function (row) {
      if (selectedDomain && String(row.domain_key || "") !== selectedDomain) {
        return false;
      }
      if (selectedStatus && String(row.status || "") !== selectedStatus) {
        return false;
      }
      if (search) {
        const haystack = [
          String(row.object_label || ""),
          String(row.object_id || ""),
          String(row.domain_label || ""),
          String(row.status || "")
        ].join(" ").toLowerCase();
        if (haystack.indexOf(search) === -1) {
          return false;
        }
      }
      return true;
    });
  }

  function renderFilters() {
    if (!state.currentSession) {
      return;
    }
    if (els.filterDomain) {
      const currentValue = String(els.filterDomain.value || "");
      const domains = Array.from(new Set(currentRows().map(function (row) {
        return String(row.domain_key || "");
      }).filter(Boolean)));
      els.filterDomain.innerHTML = '<option value="">All</option>' + domains.map(function (value) {
        return '<option value="' + esc(value) + '">' + esc(value) + '</option>';
      }).join("");
      els.filterDomain.value = currentValue && domains.indexOf(currentValue) !== -1 ? currentValue : "";
    }
    if (els.filterStatus) {
      const currentStatus = String(els.filterStatus.value || "");
      const statuses = Array.from(new Set(currentRows().map(function (row) {
        return String(row.status || "");
      }).filter(Boolean)));
      els.filterStatus.innerHTML = '<option value="">All</option>' + statuses.map(function (value) {
        return '<option value="' + esc(value) + '">' + esc(value) + '</option>';
      }).join("");
      els.filterStatus.value = currentStatus && statuses.indexOf(currentStatus) !== -1 ? currentStatus : "";
    }
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

  function renderTable() {
    if (!els.tableBody) {
      return;
    }
    const rows = filteredRows();
    if (rows.length === 0) {
      els.tableBody.innerHTML = '<tr><td colspan="7">No review rows match the current filters.</td></tr>';
      return;
    }
    els.tableBody.innerHTML = rows.map(function (row) {
      const rowId = String(row.row_id || "");
      const warnings = Array.isArray(row.warnings) ? row.warnings.length : 0;
      const decision = ensureDecision(row);
      const options = Array.isArray(row.available_actions) ? row.available_actions : [];
      return '<tr class="dbvc-bricks-portability-row" data-row-id="' + esc(rowId) + '">' +
        "<td>" + esc(row.domain_label || row.domain_key || "") + "</td>" +
        "<td>" + esc(row.object_label || "") + "</td>" +
        "<td><code>" + esc(row.object_id || "") + "</code></td>" +
        "<td>" + esc(row.match_status || "") + "</td>" +
        "<td><span class=\"dbvc-bricks-portability-status\">" + esc(row.status || "") + "</span></td>" +
        "<td>" + esc(String(warnings)) + "</td>" +
        "<td><select class=\"dbvc-bricks-portability-decision\" data-row-id=\"" + esc(rowId) + "\">" + options.map(function (option) {
          const selected = option === decision ? ' selected="selected"' : "";
          return '<option value="' + esc(option) + '"' + selected + ">" + esc(option) + "</option>";
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
      });
    });
  }

  function selectRow(rowId) {
    state.selectedRowId = rowId;
    const row = currentRows().find(function (candidate) {
      return String(candidate.row_id || "") === rowId;
    });
    if (!row) {
      return;
    }
    if (els.detail) {
      els.detail.textContent = JSON.stringify(row, null, 2);
    }
  }

  function renderDomainSummary() {
    if (!els.domainSummary) {
      return;
    }
    const summaries = state.currentSession && Array.isArray(state.currentSession.domain_summaries) ? state.currentSession.domain_summaries : [];
    els.domainSummary.textContent = JSON.stringify(summaries, null, 2);
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
    els.summary.textContent = "Rows: " + String(summary.total_rows || 0) + " | Actionable: " + String(summary.actionable_rows || 0) + " | Warnings: " + String(summary.warning_rows || 0);
  }

  function renderSession(session) {
    state.currentSession = session;
    state.decisions = {};
    state.selectedRowId = "";
    renderSessionMeta();
    renderFilters();
    renderSummary();
    renderDomainSummary();
    renderTable();
    if (els.detail) {
      els.detail.textContent = "Select a row to inspect its normalized diff preview.";
    }
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
        "Idempotency-Key": "bricks-portability-export-" + Date.now()
      },
      body: JSON.stringify({
        domains: domains,
        notes: els.exportNotes ? String(els.exportNotes.value || "") : ""
      })
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
        "Idempotency-Key": "bricks-portability-import-" + Date.now()
      },
      body: formData
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
      method: "GET"
    }).then(function (session) {
      renderSession(session);
    }).catch(function (error) {
      showNotice("error", error.message || "Failed to load review session.");
    });
  }

  function applyBulkAction() {
    const action = els.bulkAction ? String(els.bulkAction.value || "") : "";
    if (!action) {
      return;
    }
    filteredRows().forEach(function (row) {
      const options = Array.isArray(row.available_actions) ? row.available_actions : [];
      if (options.indexOf(action) !== -1) {
        state.decisions[String(row.row_id || "")] = action;
      }
    });
    renderTable();
  }

  function applyApprovedChanges() {
    if (!state.currentSession) {
      showNotice("error", "Import a package and review rows before apply.");
      return;
    }
    if (!els.confirmApply || !els.confirmApply.checked) {
      showNotice("error", "Confirm apply before continuing.");
      return;
    }
    api(cfg.applyEndpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": "bricks-portability-apply-" + Date.now()
      },
      body: JSON.stringify({
        session_id: String(state.currentSession.session_id || ""),
        decisions: state.decisions,
        confirm_apply: true
      })
    }).then(function () {
      showNotice("success", (cfg.messages && cfg.messages.applied) || "Apply completed.");
      if (els.confirmApply) {
        els.confirmApply.checked = false;
      }
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
        "Idempotency-Key": "bricks-portability-rollback-" + Date.now()
      },
      body: JSON.stringify({
        confirm_rollback: true
      })
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

  if (els.exportButton) {
    els.exportButton.addEventListener("click", exportSelectedDomains);
  }
  if (els.importButton) {
    els.importButton.addEventListener("click", importPackage);
  }
  if (els.bulkApply) {
    els.bulkApply.addEventListener("click", applyBulkAction);
  }
  if (els.applyButton) {
    els.applyButton.addEventListener("click", applyApprovedChanges);
  }
  if (els.filterDomain) {
    els.filterDomain.addEventListener("change", renderTable);
  }
  if (els.filterStatus) {
    els.filterStatus.addEventListener("change", renderTable);
  }
  if (els.filterSearch) {
    els.filterSearch.addEventListener("input", renderTable);
  }

  loadStatus();
})();
