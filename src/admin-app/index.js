(() => {
  var e = {
    766: () => {
      (() => {
        "use strict";
        var e, t = {
          435: () => {
            const e = window.wp.element, t = window.wp.components, s = window.ReactJSXRuntime, TooltipWrapper = ({content: n, placement: i = "top", children: l}) => {
              const a = t?.Tooltip;
              return a ? (0, s.jsx)(a, {
                text: n,
                children: l
              }) : (0, s.jsx)("span", {
                className: `dbvc-tooltip dbvc-tooltip--${i}`,
                "data-tooltip": n || "",
                "aria-label": n || "",
                children: l
              });
            }, n = async (e, {signal: t} = {}) => {
              const s = await window.fetch(`${DBVC_ADMIN_APP.root}${e}`, {
                headers: {
                  "X-WP-Nonce": DBVC_ADMIN_APP.nonce
                },
                signal: t
              });
              if (!s.ok) throw new Error(`Request failed (${s.status})`);
              return s.json();
            }, i = async (e, t) => {
              const s = await window.fetch(`${DBVC_ADMIN_APP.root}${e}`, {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                  "X-WP-Nonce": DBVC_ADMIN_APP.nonce
                },
                body: JSON.stringify(t)
              });
              if (!s.ok) {
                const t = await s.text();
                let n = t;
                try {
                  n = JSON.parse(t);
                } catch (e) {}
                const i = new Error(n && n.message || t || `Request failed (${s.status})`);
                throw i.status = s.status, i.body = n, i;
              }
              return s.json();
            }, l = async e => {
              const t = await window.fetch(`${DBVC_ADMIN_APP.root}${e}`, {
                method: "DELETE",
                headers: {
                  "X-WP-Nonce": DBVC_ADMIN_APP.nonce
                }
              });
              if (!t.ok) {
                const e = await t.text();
                throw new Error(e || `Request failed (${t.status})`);
              }
              return t.json();
            }, maskDocsBase = DBVC_ADMIN_APP?.docs?.masking || (window.location && window.location.origin ? `${window.location.origin.replace(/\/$/, "")}/wp-content/plugins/db-version-control-main/docs/meta-masking.md` : "https://example.com/docs/meta-masking.md"), maskDocLink = e => e ? `${maskDocsBase}#${e}` : maskDocsBase, isThenable = e => !!e && ("object" == typeof e || "function" == typeof e) && "function" == typeof e.then, warnedAsyncValue = !1, warnedAsyncKeys = new Set, logAsyncValue = (e, t, s) => {
              const n = `${e || "unknown"}::${t || "unknown"}`;
              if (warnedAsyncKeys.has(n)) return;
              warnedAsyncKeys.add(n), console.warn("DBVC: Promise detected in entity drawer values.", {
                entity_uid: e,
                path: t,
                value: s
              });
            }, a = e => {
              if (!e) return "—";
              if (isThenable(e)) return "[async]";
              const t = new Date(e);
              return Number.isNaN(t.getTime()) ? e : t.toLocaleString();
            }, o = e => null == e ? "—" : isThenable(e) ? "[async]" : "boolean" == typeof e ? e ? "true" : "false" : "number" == typeof e ? e.toString() : "" === e ? "(empty)" : "string" == typeof e ? e : r(e), r = e => {
              if (null == e) return "";
              if (isThenable(e)) {
                if (!warnedAsyncValue) {
                  warnedAsyncValue = !0, console.warn("DBVC: Promise detected in entity drawer values. Inspect the data source returning a Promise.", e);
                }
                return "[async]";
              }
              if ("string" == typeof e) return e;
              if ("number" == typeof e || "boolean" == typeof e) return String(e);
              try {
                return JSON.stringify(e, null, 2);
              } catch (t) {
                return String(e);
              }
            }, c = e => e && (e.diff || e.before || e.after) ? (0, s.jsxs)(s.Fragment, {
              children: [ e.before && (0, s.jsx)("span", {
                children: e.before
              }), e.diff && (0, s.jsx)("mark", {
                children: e.diff || "(empty)"
              }), e.after && (0, s.jsx)("span", {
                children: e.after
              }) ]
            }) : null, d = new Set([ "post_title", "post_name", "post_status", "post_type", "post_excerpt", "post_parent", "post_author", "post_date", "post_date_gmt", "post_modified", "post_modified_gmt", "post_password", "post_mime_type", "post_content_filtered", "menu_order", "comment_status", "ping_status", "guid", "comment_count" ]), u = new Set([ "name", "term_name", "slug", "term_slug", "description", "parent", "parent_slug", "taxonomy", "term_taxonomy" ]), p = {
              meta: "Meta",
              tax: "Taxonomies",
              media: "Media References",
              content: "Content",
              post_fields: "Post Fields",
              term_fields: "Term Fields",
              title: "Title",
              status: "Status",
              post_type: "Post Type",
              post_excerpt: "Excerpt",
              other: "Other"
            }, h = "__dbvc_new_entity__", m = {
              all: "All entities",
              needs_review: "Needs Review",
              needs_review_media: "Needs Review (Media)",
              resolved: "Resolved",
              reused: "Resolved",
              conflict: "Conflict",
              needs_download: "Needs Download",
              missing: "Missing",
              unknown: "Unknown",
              new_entities: "New entities",
              with_decisions: "With selections",
              uid_mismatch: "UID mismatch"
            }, maskActionLabels = {
              ignore: "Ignore masked field",
              auto_accept: "Auto-accept & suppress",
              override: "Override masked value"
            }, v = e => {
              const t = m[e] || e;
              return (0, s.jsx)("span", {
                className: `dbvc-badge dbvc-badge--${e}`,
                children: t
              });
            }, b = e => Boolean(void 0 !== e?.is_new_entity ? e.is_new_entity : "missing_local_post" === e?.diff_state?.reason), f = e => e?.entity_type ? e.entity_type : "string" == typeof e?.post_type && e.post_type.startsWith("term:") ? "term" : "post", g = e => {
              if ("term" === f(e)) {
                const t = (e?.term_taxonomy || e?.post_type || "").replace(/^term:/, "");
                return o(t ? `Term (${t})` : "Term");
              }
              return o(e?.post_type || "Post");
            }, x = e => o("term" === f(e) ? "Term" : e?.post_status || "—"), j = e => o("term" === f(e) ? e?.term_slug || e?.slug || e?.post_name || "—" : e?.post_name || "—"), _ = e => {
              if ("term" === f(e)) {
                const t = e?.name || e?.term_name || e?.post_title || "",
                  s = e?.term_taxonomy || e?.taxonomy || "",
                  n = e?.term_slug || e?.slug || e?.post_name || "";
                if (t) return o(t);
                if (s && n) return o(`${s}/${n}`);
                if (n) return o(n);
              }
              return o(e?.post_title || e?.vf_object_uid || "Entity detail");
            }, normalizeEntitySort = e => (e || "").toString().trim().toLowerCase(), getEntityObjectType = e => {
              if ("term" === f(e)) {
                const t = e?.term_taxonomy || e?.taxonomy;
                if (t) return t;
                return "string" == typeof e?.post_type && e.post_type.startsWith("term:") ? e.post_type.replace(/^term:/, "") : "term";
              }
              return e?.post_type || "post";
            }, getEntitySortName = e => {
              const t = _(e);
              if (t && "Entity detail" !== t) return t;
              const s = j(e);
              return s && "—" !== s ? s : e?.vf_object_uid || "";
            }, compareEntityAlpha = (e, t) => e.localeCompare(t, void 0, {
              numeric: !0,
              sensitivity: "base"
            }), sortEntitiesForTable = e => {
              if (!Array.isArray(e)) return [];
              const t = [ ...e ];
              return t.sort((e, t) => {
                const s = "term" === f(e) ? 1 : 0, n = "term" === f(t) ? 1 : 0;
                if (s !== n) return s - n;
                const i = compareEntityAlpha(normalizeEntitySort(getEntityObjectType(e)), normalizeEntitySort(getEntityObjectType(t)));
                if (i) return i;
                const l = compareEntityAlpha(normalizeEntitySort(getEntitySortName(e)), normalizeEntitySort(getEntitySortName(t)));
                if (l) return l;
                const a = compareEntityAlpha(normalizeEntitySort(j(e)), normalizeEntitySort(j(t)));
                return a || compareEntityAlpha(normalizeEntitySort(e?.vf_object_uid || ""), normalizeEntitySort(t?.vf_object_uid || ""));
              }), t;
            }, y = [ {
              id: "title",
              label: "Title",
              defaultVisible: !0,
              lockVisible: !0,
              renderCell: e => _(e)
            }, {
              id: "post_name",
              label: "Slug",
              defaultVisible: !0,
              renderCell: e => (0, s.jsx)("span", {
                className: "dbvc-text-break",
                children: j(e)
              })
            }, {
              id: "post_type",
              label: "Type",
              defaultVisible: !0,
              renderCell: e => g(e)
            }, {
              id: "post_status",
              label: "Status",
              defaultVisible: !0,
              renderCell: e => x(e)
            }, {
              id: "post_modified",
              label: "Last modified",
              defaultVisible: !0,
              renderCell: e => e.post_modified ? a(e.post_modified) : "—"
            }, {
              id: "content_hash",
              label: "Content hash",
              defaultVisible: !1,
              renderCell: e => {
                var t;
                return (0, s.jsx)("span", {
                  className: "dbvc-text-break",
                  children: null !== (t = e.content_hash) && void 0 !== t ? t : "—"
                });
              }
            }, {
              id: "diff",
              label: "Diff",
              defaultVisible: !0,
              renderCell: (e, t) => (0, s.jsxs)(s.Fragment, {
                children: [ v(t.diffState.needs_review ? "needs_review" : "resolved"), t.hashMissing && (0, 
                s.jsx)("span", {
                  className: "dbvc-badge dbvc-badge--missing",
                  style: {
                    marginLeft: "0.25rem"
                  },
                  children: "Hash missing"
                }), t.entityHasSelections && (0, s.jsx)("span", {
                  className: "dbvc-badge dbvc-badge--pending",
                  style: {
                    marginLeft: "0.25rem"
                  },
                  children: "Pending"
                }) ]
              })
            }, {
              id: "media_refs",
              label: "Media refs",
              defaultVisible: !0,
              renderCell: e => {
                var t, s;
                return (null !== (t = e.media_refs?.meta?.length) && void 0 !== t ? t : 0) + (null !== (s = e.media_refs?.content?.length) && void 0 !== s ? s : 0);
              }
            }, {
              id: "resolver",
              label: "Resolver",
              defaultVisible: !0,
              renderCell: (e, t) => {
                const n = t.isNewEntity, i = t.newDecision || "", l = f(e);
                let a = "term" === l ? "New term" : "New post";
                return "accept_new" === i ? a = "term" === l ? "New term accepted" : "New post accepted" : "decline_new" === i && (a = "term" === l ? "New term declined" : "New post declined"), 
                (0, s.jsxs)("div", {
                  className: "dbvc-resolver-cell",
                  children: [ v(t.mediaStatus), n && (0, s.jsx)("span", {
                    className: "dbvc-badge dbvc-badge--new" + ("decline_new" === i ? " is-declined" : ""),
                    style: {
                      marginLeft: "0.25rem"
                    },
                    children: a
                  }) ]
                });
              }
            }, {
              id: "unresolved_media",
              label: "Unresolved media",
              defaultVisible: !0,
              renderCell: (e, t) => {
                var s;
                return null !== (s = t.summary.unresolved) && void 0 !== s ? s : 0;
              }
            }, {
              id: "meta_diff_count",
              label: "Unresolved meta",
              defaultVisible: !0,
              renderCell: e => {
                var t;
                return null !== (t = e.meta_diff_count) && void 0 !== t ? t : 0;
              }
            }, {
              id: "conflicts",
              label: "Conflicts",
              defaultVisible: !0,
              renderCell: (e, t) => {
                var s;
                return null !== (s = t.summary.conflicts) && void 0 !== s ? s : 0;
              }
            }, {
              id: "decisions",
              label: "Decisions",
              defaultVisible: !0,
              renderCell: (e, t) => t.entityHasSelections ? (0, s.jsxs)("div", {
                className: "dbvc-decisions",
                children: [ t.entityAccepted > 0 && (0, s.jsxs)("span", {
                  className: "dbvc-badge dbvc-badge--accept",
                  children: [ t.entityAccepted, " accept" ]
                }), t.entityNewAccepted > 0 && (0, s.jsxs)("span", {
                  className: "dbvc-badge dbvc-badge--new",
                  children: [ t.entityNewAccepted, " new" ]
                }), t.entityKept > 0 && (0, s.jsxs)("span", {
                  className: "dbvc-badge dbvc-badge--keep",
                  children: [ t.entityKept, " keep" ]
                }) ]
              }) : "—"
            } ], w = ({proposals: e, selectedId: k, onSelect: q, onDelete: z, deleteBusyId: x}) => e.length ? (0, s.jsx)("div", {
              className: "dbvc-proposal-table-wrapper",
              children: (0, s.jsxs)("table", {
                className: "widefat fixed striped dbvc-proposal-table",
                children: [ (0, s.jsx)("thead", {
                  children: (0, s.jsxs)("tr", {
                    children: [ (0, s.jsx)("th", {
                      children: "Proposal"
                    }), (0, s.jsx)("th", {
                      children: "Generated"
                    }), (0, s.jsx)("th", {
                      children: "Files"
                    }), (0, s.jsx)("th", {
                      children: "Media"
                    }), (0, s.jsx)("th", {
                      children: "Status"
                    }), (0, s.jsx)("th", {
                      children: "Decisions"
                    }), (0, s.jsx)("th", {
                      children: "Resolver reused"
                    }), (0, s.jsx)("th", {
                      children: "Resolver unresolved"
                    }), (0, s.jsx)("th", {
                      children: "Actions"
                    }) ]
                  })
                }), (0, s.jsx)("tbody", {
                  children: e.map(e => {
                  var i, l, o, r, c, d, u, p, h, m, v;
                  const b = null !== (i = e.resolver?.metrics) && void 0 !== i ? i : {}, f = e.id === k, g = null !== (l = e.decisions) && void 0 !== l ? l : {}, x = null !== (o = g.accepted) && void 0 !== o ? o : 0, j = null !== (r = g.kept) && void 0 !== r ? r : 0, _ = null !== (c = g.entities_reviewed) && void 0 !== c ? c : 0, y = (null !== (d = g.total) && void 0 !== d ? d : 0) > 0, w = "closed" === e.status ? "Closed" : "Open";
                  return (0, s.jsxs)("tr", {
                    className: `${f ? "is-active" : ""} ${"closed" === e.status ? "is-archived" : ""}`,
                    onClick: () => q(e.id),
                    style: {
                      cursor: "pointer"
                    },
                    children: [ (0, s.jsx)("td", {
                      children: e.title
                    }), (0, s.jsx)("td", {
                      children: a(e.generated_at)
                    }), (0, s.jsx)("td", {
                      children: null !== (u = e.files) && void 0 !== u ? u : "—"
                    }), (0, s.jsx)("td", {
                      children: null !== (p = e.media_items) && void 0 !== p ? p : "—"
                    }), (0, s.jsx)("td", {
                      children: (0, s.jsx)("span", {
                        className: `dbvc-status-badge dbvc-status-badge--${null !== (h = e.status) && void 0 !== h ? h : "draft"}`,
                        children: w
                      })
                    }), (0, s.jsx)("td", {
                      children: y ? (0, s.jsxs)("div", {
                        className: "dbvc-decisions",
                        children: [ (0, s.jsxs)("span", {
                          className: "dbvc-badge dbvc-badge--accept",
                          children: [ x, " accept" ]
                        }), (0, s.jsxs)("span", {
                          className: "dbvc-badge dbvc-badge--keep",
                          children: [ j, " keep" ]
                        }), _ > 0 && (0, s.jsxs)("span", {
                          className: "dbvc-badge dbvc-badge--reviewed",
                          children: [ _, " reviewed" ]
                        }) ]
                      }) : "—"
                    }), (0, s.jsx)("td", {
                      children: null !== (m = b.reused) && void 0 !== m ? m : "—"
                    }), (0, s.jsx)("td", {
                      children: null !== (v = b.unresolved) && void 0 !== v ? v : "—"
                    }), (0, s.jsx)("td", {
                      className: "dbvc-proposal-table__actions",
                      children: !f ? (0, s.jsx)(t.Button, {
                        variant: "tertiary",
                        isDestructive: !0,
                        onClick: t => {
                          t.stopPropagation(), "function" == typeof z && z(e.id);
                        },
                        disabled: x === e.id || e.locked,
                        title: e.locked ? "Locked proposals cannot be deleted." : void 0,
                        children: x === e.id ? "Deleting…" : "Delete"
                      }) : "—"
                    }) ]
                  }, e.id);
                  })
                }) ]
              })
            }) : (0, s.jsx)("p", {
              children: "No proposals found. Generate an export to get started."
            }), C = ({onUploaded: n, onError: i}) => {
            const [l, a] = (0, e.useState)(!1), [o, r] = (0, e.useState)(!1), [c, d] = (0, e.useState)(""), [u, p] = (0, 
              e.useState)(""), [h, m] = (0, e.useState)(!1), [f, g] = (0, e.useState)(!1), v = (0, e.useRef)(null), b = (0, 
              e.useCallback)(async e => {
                const t = e && e[0];
                if (!t) return;
                const s = new window.FormData;
                s.append("file", t), s.append("overwrite", h ? "1" : "0"), f && s.append("fixture_name", t.name), r(!0), d(""), p("");
                try {
                  const e = await (async (e, t) => {
                    const s = await window.fetch(`${DBVC_ADMIN_APP.root}${e}`, {
                      method: "POST",
                      headers: {
                        "X-WP-Nonce": DBVC_ADMIN_APP.nonce
                      },
                      body: t
                    });
                    if (!s.ok) {
                      const e = await s.text();
                      throw new Error(e || `Request failed (${s.status})`);
                    }
                    return s.json();
                  })(f ? "fixtures/upload" : "proposals/upload", s);
                  const l = f ? "Saved dev fixture" : "Uploaded";
                  d(`${l} ${t.name}`), f || "function" != typeof n || n(e.proposal_id, e);
                } catch (e) {
                  const t = e?.message || "Upload failed.";
                  p(t), "function" == typeof i && i(t);
                } finally {
                  r(!1), v.current && (v.current.value = "");
                }
              }, [ h, f, n, i ]);
              return (0, s.jsxs)("div", {
                className: "dbvc-proposal-uploader",
                children: [ (0, s.jsx)("div", {
                  className: `dbvc-proposal-uploader__dropzone${l ? " is-dragging" : ""}${o ? " is-uploading" : ""}`,
                  onDragOver: e => {
                    e.preventDefault(), a(!0);
                  },
                  onDragLeave: e => {
                    e.currentTarget.contains(e.relatedTarget) || a(!1);
                  },
                  onDrop: e => {
                    e.preventDefault(), a(!1), b(e.dataTransfer?.files);
                  },
                  children: [ (0, s.jsxs)("div", {
                    className: "dbvc-proposal-uploader__body",
                    children: [ (0, s.jsxs)("div", {
                      className: "dbvc-proposal-uploader__text",
                      children: [ (0, s.jsx)("strong", {
                        children: "Upload proposal bundle"
                      }), (0, s.jsx)("p", {
                        children: "Drop a DBVC ZIP export here or select a file to register it."
                      }) ]
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-proposal-uploader__actions",
                      children: [ (0, s.jsx)(t.Button, {
                        variant: "secondary",
                        onClick: () => v.current?.click(),
                        disabled: o,
                        children: o ? "Uploading…" : "Select ZIP"
                      }), (0, s.jsx)("span", {
                        className: "dbvc-proposal-uploader__actions-note",
                        children: "ZIP files only"
                      }), (0, s.jsx)("input", {
                        ref: v,
                        type: "file",
                        accept: ".zip",
                        style: {
                          display: "none"
                        },
                        onChange: e => b(e.target.files)
                      }) ]
                    }) ]
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-proposal-uploader__options",
                    children: [ (0, s.jsx)("div", {
                      className: "dbvc-proposal-uploader__option",
                      children: (0, s.jsx)(t.CheckboxControl, {
                        label: "Replace existing proposal when IDs match",
                        help: "Enable if this upload should refresh an existing proposal folder instead of creating a new one.",
                        checked: h,
                        onChange: e => m(e),
                        disabled: o
                      })
                    }), (0, s.jsx)("div", {
                      className: "dbvc-proposal-uploader__option",
                      children: (0, s.jsx)(t.CheckboxControl, {
                        label: "Dev upload (store ZIP in docs/fixtures)",
                        help: "Copies the selected ZIP into docs/fixtures for local QA. Disable to register the proposal normally.",
                        checked: f,
                        onChange: e => g(e),
                        disabled: o
                      })
                    }) ]
                  }) ]
                }), c && (0, s.jsx)("p", {
                  className: "dbvc-proposal-uploader__status",
                  children: c
                }), u && (0, s.jsx)("p", {
                  className: "dbvc-proposal-uploader__error",
                  role: "alert",
                  children: u
                }) ]
              });
            }, N = ({resolver: e}) => {
              var t, n, i, l;
              if (!e) return (0, s.jsx)("p", {
                children: "Resolver metrics unavailable."
              });
              const {metrics: a = {}, conflicts: o = []} = e;
              return (0, s.jsxs)("div", {
                className: "dbvc-admin-app__resolver",
                children: [ (0, s.jsx)("h3", {
                  children: "Resolver Summary"
                }), (0, s.jsxs)("ul", {
                  children: [ (0, s.jsxs)("li", {
                    children: [ "Detected: ", null !== (t = a.detected) && void 0 !== t ? t : 0 ]
                  }), (0, s.jsxs)("li", {
                    children: [ "Reused: ", null !== (n = a.reused) && void 0 !== n ? n : 0 ]
                  }), (0, s.jsxs)("li", {
                    children: [ "Downloaded: ", null !== (i = a.downloaded) && void 0 !== i ? i : 0 ]
                  }), (0, s.jsxs)("li", {
                    children: [ "Unresolved: ", null !== (l = a.unresolved) && void 0 !== l ? l : 0 ]
                  }), (0, s.jsxs)("li", {
                    children: [ "Conflicts: ", o.length ]
                  }) ]
                }), o.length > 0 && (0, s.jsx)("div", {
                  className: "notice notice-warning",
                  children: (0, s.jsxs)("p", {
                    children: [ "The resolver reported ", o.length, " conflict(s). Review before applying." ]
                  })
                }) ]
              });
            }, k = ({entities: t, loading: n, selectedEntityId: i, onSelect: l, columns: a, selectedIds: o = new Set, onToggleSelection: r, onToggleSelectionAll: c}) => {
              if (n) return (0, s.jsx)("p", {
                children: "Loading entities…"
              });
                if (!t.length) return (0, s.jsx)("p", {
                  children: "No entities found for this proposal."
                });
              const d = a && a.length ? a : y, D = "function" == typeof r, R = o instanceof Set ? o : new Set(o), $ = t.map(e => e.vf_object_uid), I = D && t.length > 0 && t.every(e => R.has(e.vf_object_uid)), A = D && !I && t.some(e => R.has(e.vf_object_uid)), formatCellValue = o, E = (0, e.useRef)(null);
              return (0, e.useEffect)(() => {
                D && E.current && (E.current.indeterminate = A);
              }, [ D, A ]), (0, s.jsx)("div", {
                className: "dbvc-entity-table",
                children: (0, s.jsxs)("table", {
                  className: "widefat striped",
                  children: [ (0, s.jsx)("thead", {
                    children: (0, s.jsxs)("tr", {
                      children: [ D && (0, s.jsx)("th", {
                        className: "dbvc-entity-select",
                        children: (0, s.jsx)("input", {
                          type: "checkbox",
                          ref: E,
                          checked: I,
                          onChange: e => c?.($, e.target.checked)
                        })
                      }), d.map(e => (0, s.jsx)("th", {
                        children: e.label
                      }, e.id)) ]
                    })
                  }), (0, s.jsx)("tbody", {
                    children: t.map(e => {
                      var t, n, a, o, c, u, p, h;
                      const m = e.vf_object_uid === i, v = D && R.has(e.vf_object_uid), f = null !== (t = e.resolver?.summary) && void 0 !== t ? t : {}, g = null !== (n = e.diff_state) && void 0 !== n ? n : {}, x = e.media_needs_review ? "needs_review" : null !== (a = e.resolver?.status) && void 0 !== a ? a : "resolved", j = e.overall_status || (g.needs_review ? "needs_review" : "resolved"), _ = "missing_local_hash" === g.reason, y = null !== (o = e.decision_summary) && void 0 !== o ? o : {}, w = null !== (c = y.accepted) && void 0 !== c ? c : 0, C = null !== (u = y.kept) && void 0 !== u ? u : 0, N = null !== (p = y.accepted_new) && void 0 !== p ? p : 0, k = (null !== (h = y.total) && void 0 !== h ? h : 0) > 0, S = b(e), $ = e.new_entity_decision || "", I = e.identity_match || "", A = [ `resolver-${j}`, m ? "is-active" : "", v ? "is-selected" : "" ].filter(Boolean).join(" "), E = t => {
                        t.preventDefault(), t.stopPropagation(), l(e.vf_object_uid);
                      }, M = {
                        summary: f,
                        diffState: g,
                        mediaStatus: x,
                        hashMissing: _,
                        decisionSummary: y,
                        entityHasSelections: k,
                        entityAccepted: w,
                        entityNewAccepted: N,
                        entityKept: C,
                        isNewEntity: S,
                        newDecision: $,
                        identityMatch: I
                      };
                      return (0, s.jsxs)("tr", {
                        className: A,
                        onClick: E,
                        onKeyDown: t => {
                          "Enter" !== t.key && " " !== t.key || E(t);
                        },
                        style: {
                          cursor: "pointer"
                        },
                        role: "button",
                        tabIndex: 0,
                        children: [ D && (0, s.jsx)("td", {
                          className: "dbvc-entity-select",
                          children: (0, s.jsx)("input", {
                            type: "checkbox",
                            checked: v,
                            onChange: t => {
                              t.stopPropagation(), r?.(e.vf_object_uid);
                            },
                            onClick: e => e.stopPropagation()
                          })
                        }), d.map(t => {
                          var n;
                          return (0, s.jsx)("td", {
                            children: t.renderCell ? t.renderCell(e, M) : null !== (n = e[t.id]) && void 0 !== n ? formatCellValue(n) : "—"
                          }, t.id);
                        }) ]
                      }, e.vf_object_uid);
                    })
                  }) ]
                })
              });
            }, S = ({entityDetail: n, resolverInfo: i, resolverDecisionSummary: l = null, decisions: a = {}, onDecisionChange: o, onBulkDecision: r, onResetDecisions: c, onCaptureSnapshot: m, onResolverDecision: v, onResolverDecisionReset: x, onApplyResolverDecisionToSimilar: y, savingPaths: w = {}, bulkSaving: C = !1, resolverSaving: N = {}, resolverError: k = null, decisionError: S, loading: D, error: R, onClose: $, filterMode: A, onFilterModeChange: M, isOpen: U = !0, resettingDecisions: B = !1, snapshotCapturing: O = !1, onHashSync: T, hashSyncing: P = !1, hashSyncTarget: F = "", maskFieldsByEntity: qt = {}}) => {
              var V, L, z, H, K, J, q, W, G, X, Z, Q, Y, ee, te;
              const se = (0, e.useMemo)(() => n?.item?.vf_object_uid ? `dbvc-entity-detail-${n.item.vf_object_uid}` : n?.vf_object_uid ? `dbvc-entity-detail-${n.vf_object_uid}` : "dbvc-entity-detail", [ n ]), ne = (0, 
              e.useRef)(null), ie = (0, e.useRef)(null);
              (0, e.useEffect)(() => {
                if (!$ || !U) return;
                ie.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                const e = ne.current;
                e && e.focus();
                const t = e => {
                  "Escape" === e.key && (e.preventDefault(), $());
                };
                return document.addEventListener("keydown", t), () => {
                  document.removeEventListener("keydown", t), ie.current && "function" == typeof ie.current.focus && ie.current.focus();
                };
              }, [ U, $, se ]);
              const {item: le, current: ae, proposed: oe = {}, diff: re} = null != n ? n : {}, ce = null !== (V = n?.decision_summary) && void 0 !== V ? V : {}, de = null !== (L = null !== (z = n?.diff_state) && void 0 !== z ? z : le?.diff_state) && void 0 !== L ? L : null, ue = ((e, t) => {
                if (!t) return "";
                if ("term" === f(e)) {
                  const s = e?.term_taxonomy || e?.taxonomy || "";
                  return s ? `term.php?taxonomy=${s}&tag_ID=${t}` : `term.php?tag_ID=${t}`;
                }
                return `post.php?post=${t}&action=edit`;
              })(le, null !== (H = de?.local_post_id) && void 0 !== H ? H : null), pe = _(le), he = j(le), me = (e => "term" === f(e) ? "Term slug:" : "Slug:")(le), ve = f(le), be = le?.term_taxonomy || le?.taxonomy || "", fe = (g(le), 
              "missing_local_hash" === de?.reason), ge = b(null != n ? n : {
                diff_state: de
              }), xe = null !== (K = null !== (J = a?.[h]) && void 0 !== J ? J : n?.new_entity_decision) && void 0 !== K ? K : "", je = Boolean(w?.[h]), _e = null !== (q = ce.total) && void 0 !== q ? q : 0, ye = _e > 0 ? `${null !== (W = ce.accepted) && void 0 !== W ? W : 0} accepted · ${null !== (G = ce.kept) && void 0 !== G ? G : 0} kept` : "No selections captured yet.", we = null !== (X = re?.changes) && void 0 !== X ? X : [], entityMaskFields = (0, 
              e.useMemo)(() => {
                const e = n?.vf_object_uid || le?.vf_object_uid || "";
                if (!e || !qt[e]) return [];
                return qt[e];
              }, [ qt, n, le ]), allMetaItems = (0, e.useMemo)(() => {
                if ("all" !== A) return [];
                const e = (t, n = "") => {
                  const s = {};
                  if (!t || "object" != typeof t) return s;
                  Object.keys(t).forEach(i => {
                    const l = t[i], a = n ? `${n}.${i}` : String(i);
                    if (l && "object" == typeof l) Object.assign(s, e(l, a)); else s[a] = void 0 === l ? null : l;
                  });
                  return s;
                }, t = e(ae?.meta || {}, "meta"), s = e(oe?.meta || {}, "meta"), n = new Set([ ...Object.keys(t), ...Object.keys(s) ]), i = Array.from(n);
                return i.sort(), i.map(e => {
                  const n = Object.prototype.hasOwnProperty.call(t, e) ? t[e] : null, i = Object.prototype.hasOwnProperty.call(s, e) ? s[e] : null, l = r(n) === r(i);
                  return {
                    path: e,
                    label: e,
                    section: "meta",
                    from: n,
                    to: i,
                    is_equal: l
                  };
                });
              }, [ A, ae, oe ]), Ce = (0, e.useMemo)(() => "all" === A ? allMetaItems : we, [ allMetaItems, we, A ]), Ne = (0, e.useMemo)(() => (e => {
                const t = {};
                return e.forEach(e => {
                  const [s] = e.path.split("."), n = (d.has(s) ? "post_fields" : u.has(s) ? "term_fields" : s) || "other";
                  t[n] || (t[n] = []), t[n].push(e);
                }), Object.entries(t).map(([e, t]) => ({
                  key: e,
                  label: p[e] || e,
                  items: t
                }));
              })(Ce), [ Ce ]), ke = null !== (Z = i?.attachments) && void 0 !== Z ? Z : [], [Se, De] = (0, 
              e.useState)(""), Re = (0, e.useMemo)(() => {
                if (!Se) return ke;
                const e = Se.toLowerCase();
                return ke.filter(t => {
                  const s = t.descriptor || {};
                  return [ t.original_id, t.reason, t.status, t.decision?.note, s.asset_uid, s.bundle_path, s.path ].filter(Boolean).join(" ").toLowerCase().includes(e);
                });
              }, [ ke, Se ]), $e = "conflicts" === A, Ie = we.length, Ae = (0, e.useMemo)(() => we.filter(e => {
                var t;
                return "accept" !== (null !== (t = a?.[e.path]) && void 0 !== t ? t : "");
              }).length, [ we, a ]), Ee = Ce.length, [Me, Ue] = (0, e.useState)(!1), [Be, Oe] = (0, 
              e.useState)(""), [Te, Pe] = (0, e.useState)("reason"), [Fe, Ve] = (0, e.useState)(""), [Le, ze] = (0, 
              e.useState)(""), [He, Ke] = (0, e.useState)(""), [Je, qe] = (0, e.useState)(""), [We, Ge] = (0, 
              e.useState)(!1), [Xe, Ze] = (0, e.useState)(!1), [Qe, Ye] = (0, e.useState)("");
              (0, e.useEffect)(() => {
                Me || Oe(Ne[0]?.key || "");
              }, [ Ne, Me ]);
              const et = (0, e.useMemo)(() => Me || !Be ? Ne : Ne.filter(e => e.key === Be), [ Ne, Me, Be ]), tt = (0, 
              e.useMemo)(() => {
                if (Me) return Ce;
                const e = Ne.find(e => e.key === Be);
                return e ? e.items : [];
              }, [ Me, Ce, Ne, Be ]), st = (0, e.useMemo)(() => tt.filter(e => !e.is_equal).map(e => e.path).filter(Boolean), [ tt ]), nt = (0, 
              e.useMemo)(() => {
                const e = new Set, t = new Set, s = new Set;
                return ke.forEach(n => {
                  n.reason && e.add(n.reason);
                  const i = n.descriptor || {};
                  i.asset_uid && t.add(i.asset_uid);
                  const l = i.bundle_path || i.path || "";
                  l && s.add(l);
                }), {
                  reason: Array.from(e),
                  asset_uid: Array.from(t),
                  bundle_path: Array.from(s)
                };
              }, [ ke ]);
              (0, e.useEffect)(() => {
                const e = nt[Te] || [];
                e.length ? e.includes(Fe) || Ve(e[0] || "") : Ve("");
              }, [ Te, Fe, nt ]);
              const termSlugDisplay = "term" === ve && be ? `${be}/${he}` : he, termParentSlug = le?.term_parent_slug || le?.parent_slug || oe?.parent_slug || ae?.term_parent_slug || ae?.parent_slug || "", termParentId = le?.term_parent || le?.parent || oe?.parent || ae?.term_parent || ae?.parent || 0, termParentUid = le?.term_parent_uid || oe?.parent_uid || ae?.term_parent_uid || "", termParentDisplay = (() => {
                if ("term" !== ve) return null;
                if (termParentSlug) return termParentSlug;
                if (termParentUid) return termParentUid;
                if (termParentId) return `ID ${termParentId}`;
                return "—";
              })(), titleDisplay = "term" === ve && be ? `${pe} · ${be}` : pe, it = (0, e.useMemo)(() => !Fe && (nt[Te] || []).length ? [] : ke.filter(e => {
                const t = e.descriptor || {};
                switch (Te) {
                 case "asset_uid":
                  return (t.asset_uid || "") === Fe;

                 case "bundle_path":
                  return (t.bundle_path || t.path || "") === Fe;

                 default:
                  return (e.reason || "") === Fe;
                }
              }), [ ke, Te, Fe, nt ]), lt = it.length, at = "reuse" === Le || "map" === Le;
              return $ && !U ? null : (e => {
                const t = (0, s.jsx)("div", {
                  className: "dbvc-admin-app__entity-detail",
                  children: e
                });
                return $ ? (0, s.jsxs)("div", {
                  className: "dbvc-entity-detail-modal",
                  role: "presentation",
                  children: [ (0, s.jsx)("div", {
                    className: "dbvc-entity-detail-modal__overlay",
                    onClick: $,
                    "aria-hidden": "true"
                  }), (0, s.jsx)("div", {
                    className: "dbvc-entity-detail-modal__panel",
                    role: "dialog",
                    "aria-modal": "true",
                    "aria-labelledby": se,
                    children: t
                  }) ]
                }) : t;
              })(D ? (0, s.jsx)("p", {
                children: "Loading entity detail…"
              }) : R ? (0, s.jsx)("div", {
                className: "notice notice-error",
                children: (0, s.jsxs)("p", {
                  children: [ "Failed to load entity detail: ", R ]
                })
              }) : n ? (0, s.jsxs)(s.Fragment, {
                children: [ (0, s.jsxs)("div", {
                  className: "dbvc-entity-toolbar",
                  children: [ (0, s.jsxs)("div", {
                    className: "dbvc-entity-toolbar__meta",
                    children: [ (0, s.jsx)("div", {
                      className: "dbvc-entity-toolbar__title",
                      id: se,
                      children: ue ? (0, s.jsx)("a", {
                        href: ue,
                        target: "_blank",
                        rel: "noreferrer",
                        children: titleDisplay
                      }) : titleDisplay
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-entity-toolbar__sub",
                      children: [ (0, s.jsxs)("span", {
                        children: [ me, " ", ue && "—" !== termSlugDisplay ? (0, s.jsx)("a", {
                          href: ue,
                          target: "_blank",
                          rel: "noreferrer",
                          children: termSlugDisplay
                        }) : termSlugDisplay ]
                      }), "term" === ve && (0, s.jsxs)("span", {
                        children: [ "Taxonomy: ", be || "—" ]
                      }), "term" === ve && (0, s.jsxs)("span", {
                        children: [ "Parent: ", termParentDisplay || "—" ]
                      }), (0, s.jsxs)("span", {
                        children: [ "File: ", le?.path || "—" ]
                      }) ]
                    }) ]
                  }), $ && (0, s.jsx)("button", {
                    type: "button",
                    className: "dbvc-entity-detail__close",
                    onClick: $,
                    "aria-label": "Close entity detail",
                    ref: $ ? ne : void 0,
                    children: "Close"
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-diff-filter",
                    children: [ (0, s.jsx)("span", {
                      children: "View:"
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: $e ? "is-active" : "",
                      onClick: () => M && M("conflicts"),
                      children: "Conflicts & Resolver"
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: "full" === A ? "is-active" : "",
                      onClick: () => M && M("full"),
                      children: "Full Overview"
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: "all" === A ? "is-active" : "",
                      onClick: () => M && M("all"),
                      children: "View All"
                    }) ]
                  }), (0, s.jsx)("p", {
                    className: "dbvc-entity-toolbar__count",
                    children: $e ? `${Ae} field(s) awaiting review · ${Ie} total` : "all" === A ? `${Ee} meta field(s) shown.` : `${Ee} field(s) shown.`
                  }), fe && T && (0, s.jsxs)("div", {
                    className: "notice notice-warning",
                    children: [ (0, s.jsx)("p", {
                      children: "This entity is missing its stored import hash. Sync it to prevent repeated reviews."
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: "button button-secondary",
                      onClick: () => T(n?.vf_object_uid),
                      disabled: P && F === n?.vf_object_uid,
                      children: P && F === n?.vf_object_uid ? "Storing hash…" : "Store import hash"
                    }) ]
                  }), (() => {
                    const e = [];
                    return st.length > 0 && (e.push({
                      key: "accept_all",
                      content: (0, s.jsxs)(s.Fragment, {
                        children: [ (0, s.jsx)("button", {
                          type: "button",
                          className: "button button-secondary",
                          onClick: () => {
                            r && window.confirm(`Accept ${st.length} field(s)?`) && r("accept", st);
                          },
                          disabled: C,
                          children: C ? "Accepting…" : "Accept All Visible"
                        }), (0, s.jsx)("p", {
                          className: "description",
                          children: "Applies the bundle values to every field currently listed."
                        }) ]
                      })
                    }), e.push({
                      key: "keep_all",
                      content: (0, s.jsxs)(s.Fragment, {
                        children: [ (0, s.jsx)("button", {
                          type: "button",
                          className: "button",
                          onClick: () => {
                            r && window.confirm(`Keep current values for ${st.length} field(s)?`) && r("keep", st);
                          },
                          disabled: C,
                          children: C ? "Keeping…" : "Keep All Visible"
                        }), (0, s.jsx)("p", {
                          className: "description",
                          children: "Locks in the live values for each visible field without importing."
                        }) ]
                      })
                    })), "function" == typeof m && e.push({
                      key: "snapshot",
                      content: (0, s.jsxs)(s.Fragment, {
                        children: [ (0, s.jsx)("button", {
                          type: "button",
                          className: "button dbvc-button-inverted",
                          onClick: m,
                          disabled: O || !m,
                          children: O ? "Capturing snapshot…" : "Capture current snapshot"
                        }), (0, s.jsx)("p", {
                          className: "description",
                          children: `Refreshes the “current” baseline from the live ${"term" === ve ? "term" : "post"} before you compare.`
                        }) ]
                      })
                    }), "function" == typeof c && _e > 0 && e.push({
                      key: "clear_decisions",
                      content: (0, s.jsxs)(s.Fragment, {
                        children: [ (0, s.jsx)("button", {
                          type: "button",
                          className: "button",
                          onClick: c,
                          disabled: B,
                          children: B ? "Clearing…" : "Clear all decisions"
                        }), (0, s.jsx)("p", {
                          className: "description",
                          children: "Removes every Accept/Keep choice so you can re-evaluate this entity."
                        }) ]
                      })
                    }), e.length ? (0, s.jsx)("div", {
                      className: "dbvc-entity-action-grid",
                      children: e.map(e => (0, s.jsx)("div", {
                        className: "dbvc-entity-action",
                        children: e.content
                      }, e.key))
                    }) : null;
                  })(), entityMaskFields.length > 0 && (0, s.jsxs)("section", {
                    className: "dbvc-entity-detail__masking",
                    children: [ (0, s.jsx)("h4", {
                      children: "Masked fields"
                    }), (0, s.jsx)("div", {
                      className: "dbvc-mask-field-list",
                      children: entityMaskFields.map((e, t) => {
                        const n = p[e.section] || e.section || "Meta";
                        const i = maskActionLabels[e.default_action] || "Masked field";
                        const l = `dbvc-mask-field__status${"ignore" === e.default_action ? " is-pending" : ""}`;
                        const a = e.vf_object_uid || "mask";
                        return (0, s.jsxs)("div", {
                          className: "dbvc-mask-field",
                          children: [ (0, s.jsxs)("div", {
                            className: "dbvc-mask-field__meta",
                            children: [ (0, s.jsxs)("div", {
                              children: [ (0, s.jsx)("strong", {
                                children: o(e.label || e.meta_path || n)
                              }), (0, s.jsxs)("div", {
                                className: "dbvc-mask-field__label",
                                children: [ n, e.meta_path ? (0, s.jsx)("code", {
                                  children: e.meta_path
                                }) : null ]
                              }) ]
                            }), (0, s.jsx)("span", {
                              className: l,
                              children: i
                            }) ]
                          }), (0, s.jsxs)("div", {
                            className: "dbvc-mask-field__values",
                            children: [ (0, s.jsxs)("span", {
                              children: [ "Proposed: ", o(e.proposed_value) ]
                            }), null != e.current_value ? (0, s.jsxs)("span", {
                              children: [ "Current: ", o(e.current_value) ]
                            }) : null ]
                          }) ]
                        }, `${a}::${e.meta_path || t}`);
                      })
                    }) ]
                  }), (0, s.jsxs)("p", {
                    className: "dbvc-decision-summary",
                    children: [ "Selections: ", ye ]
                  }) ]
                }), ge && (0, s.jsxs)("div", {
                  className: "dbvc-new-entity-card",
                  children: [ (0, s.jsxs)("div", {
                    className: "dbvc-new-entity-card__header",
                    children: [ (0, s.jsx)("span", {
                      className: "dbvc-badge dbvc-badge--new",
                      children: "term" === f(le) ? "New term" : "New post"
                    }), (0, s.jsx)("span", {
                      className: "dbvc-new-entity-card__status",
                      children: "accept_new" === xe ? "Accepted for import" : "decline_new" === xe ? "Declined — will be skipped" : "Pending reviewer decision"
                    }) ]
                  }), (0, s.jsxs)("p", {
                    children: [ "This proposal would create a new ", "term" === f(le) ? g(le) : le?.post_type || "post", " on this site. No UID, original ID, or slug match was detected locally, so choose whether to import it." ]
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-new-entity-actions",
                    children: [ (0, s.jsx)(t.Button, {
                      variant: "accept_new" === xe ? "primary" : "secondary",
                      onClick: () => o && o(h, "accept_new"),
                      disabled: je,
                      isBusy: je && "accept_new" === xe,
                      children: "Accept & import"
                    }), (0, s.jsx)(t.Button, {
                      variant: "decline_new" === xe ? "primary" : "secondary",
                      onClick: () => o && o(h, "decline_new"),
                      disabled: je,
                      isBusy: je && "decline_new" === xe,
                      children: "term" === f(le) ? "Decline new term" : "Decline new post"
                    }), (0, s.jsx)(t.Button, {
                      variant: "tertiary",
                      onClick: () => o && o(h, "clear"),
                      disabled: je || !xe,
                      children: "Clear choice"
                    }) ]
                  }) ]
                }), S && (0, s.jsx)("div", {
                  className: "notice notice-error",
                  children: (0, s.jsx)("p", {
                    children: S
                  })
                }), Ne.length > 0 && (0, s.jsxs)("div", {
                  className: "dbvc-section-nav",
                  children: [ (0, s.jsx)("span", {
                    children: Me ? "Sections:" : "Jump to:"
                  }), Ne.map(e => (0, s.jsx)("button", {
                    type: "button",
                    className: Me || Be !== e.key ? "" : "is-active",
                    onClick: () => {
                      if (Me) {
                        const t = document.getElementById(`dbvc-diff-${e.key}`);
                        t && t.scrollIntoView({
                          behavior: "smooth",
                          block: "start"
                        });
                      } else Oe(e.key);
                    },
                    children: e.label
                  }, e.key)), (0, s.jsx)("button", {
                    type: "button",
                    className: Me ? "is-active" : "",
                    onClick: () => Ue(e => !e),
                    children: Me ? "Focus Section" : "Show All"
                  }) ]
                }), et.length > 0 ? et.map(e => (0, s.jsx)(I, {
                  section: e,
                  decisions: a,
                  onDecisionChange: o,
                  savingPaths: w
                }, e.key)) : (0, s.jsx)("p", {
                  children: $e && Ie > 0 ? "No resolver or media conflicts detected for this entity. Switch to Full Overview to inspect all differences." : "all" === A ? "No meta fields found for this entity." : "No differences detected."
                }), ke.length > 0 && (0, s.jsxs)("div", {
                  className: "dbvc-admin-app__resolver-attachments",
                  children: [ (0, s.jsx)("h4", {
                    children: "Media Resolver"
                  }), (0, s.jsx)("div", {
                    className: "dbvc-panel-search",
                    children: (0, s.jsxs)("label", {
                      children: [ "Search:  ", (0, s.jsx)("input", {
                        type: "search",
                        value: Se,
                        onChange: e => De(e.target.value),
                        placeholder: "Reason, asset UID, note…"
                      }) ]
                    })
                  }), l && (0, s.jsxs)("p", {
                    className: "dbvc-resolver-summary",
                    children: [ "Saved decisions — reuse: ", null !== (Q = l.reuse) && void 0 !== Q ? Q : 0, ", map: ", null !== (Y = l.map) && void 0 !== Y ? Y : 0, ", download:", " ", null !== (ee = l.download) && void 0 !== ee ? ee : 0, ", skip: ", null !== (te = l.skip) && void 0 !== te ? te : 0 ]
                  }), k && (0, s.jsx)("div", {
                    className: "notice notice-error",
                    children: (0, s.jsx)("p", {
                      children: k
                    })
                  }), Re.length > 0 ? (0, s.jsxs)("table", {
                    className: "widefat",
                    children: [ (0, s.jsx)("thead", {
                      children: (0, s.jsxs)("tr", {
                        children: [ (0, s.jsx)("th", {
                          children: "Status"
                        }), (0, s.jsx)("th", {
                          children: "Original ID"
                        }), (0, s.jsx)("th", {
                          children: "Target"
                        }), (0, s.jsx)("th", {
                          children: "Reason"
                        }), (0, s.jsx)("th", {
                          children: "Decision"
                        }), (0, s.jsx)("th", {
                          style: {
                            width: "160px"
                          },
                          children: "Actions"
                        }) ]
                      })
                    }), (0, s.jsx)("tbody", {
                      children: Re.map(e => (0, s.jsx)(E, {
                        attachment: e,
                        saving: !!N[e.original_id],
                        onSave: v,
                        onClear: x,
                        onApplyToSimilar: y
                      }, e.original_id))
                    }) ]
                  }) : (0, s.jsx)("p", {
                    children: "No resolver conflicts match your search."
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-resolver-bulk",
                    children: [ (0, s.jsx)("h5", {
                      children: "Bulk Apply Resolver Decision"
                    }), (0, s.jsx)("p", {
                      className: "description",
                      children: "Match a subset of conflicts and apply a single decision without editing rows individually."
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-resolver-bulk__filters",
                      children: [ (0, s.jsxs)("label", {
                        children: [ "Match by", (0, s.jsxs)("select", {
                          value: Te,
                          onChange: e => Pe(e.target.value),
                          children: [ (0, s.jsx)("option", {
                            value: "reason",
                            children: "Reason"
                          }), (0, s.jsx)("option", {
                            value: "asset_uid",
                            children: "Asset UID"
                          }), (0, s.jsx)("option", {
                            value: "bundle_path",
                            children: "Manifest Path"
                          }) ]
                        }) ]
                      }), (0, s.jsxs)("label", {
                        children: [ "Value", (0, s.jsxs)("select", {
                          value: Fe,
                          onChange: e => Ve(e.target.value),
                          disabled: 0 === (nt[Te] || []).length,
                          children: [ 0 === (nt[Te] || []).length && (0, s.jsx)("option", {
                            value: "",
                            children: "No values detected"
                          }), (nt[Te] || []).map(e => (0, s.jsx)("option", {
                            value: e,
                            children: e || "— Not provided —"
                          }, e || "blank")) ]
                        }) ]
                      }), (0, s.jsxs)("span", {
                        className: "dbvc-resolver-bulk__count",
                        children: [ "Matches: ", lt ]
                      }) ]
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-resolver-controls dbvc-resolver-bulk__controls",
                      children: [ (0, s.jsxs)("select", {
                        value: Le,
                        onChange: e => ze(e.target.value),
                        children: [ (0, s.jsx)("option", {
                          value: "",
                          children: "Select action…"
                        }), (0, s.jsx)("option", {
                          value: "reuse",
                          children: "Reuse existing"
                        }), (0, s.jsx)("option", {
                          value: "download",
                          children: "Download new"
                        }), (0, s.jsx)("option", {
                          value: "map",
                          children: "Map to attachment ID"
                        }), (0, s.jsx)("option", {
                          value: "skip",
                          children: "Skip"
                        }) ]
                      }), at && (0, s.jsx)("input", {
                        type: "number",
                        min: "1",
                        placeholder: "Attachment ID",
                        value: He,
                        onChange: e => Ke(e.target.value)
                      }), (0, s.jsx)("textarea", {
                        value: Je,
                        onChange: e => qe(e.target.value),
                        placeholder: "Optional note",
                        rows: 2
                      }), (0, s.jsxs)("label", {
                        children: [ (0, s.jsx)("input", {
                          type: "checkbox",
                          checked: We,
                          onChange: e => Ge(e.target.checked)
                        }), " ", "Remember as global rule" ]
                      }) ]
                    }), Qe && (0, s.jsx)("div", {
                      className: "notice notice-error",
                      children: (0, s.jsx)("p", {
                        children: Qe
                      })
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: "button button-secondary",
                      onClick: async () => {
                        if (!Le) return void Ye("Select an action to apply.");
                        if (at && !He) return void Ye("Enter a target attachment ID for this action.");
                        if (!lt) return void Ye("No matching conflicts were found for the selected filter.");
                        if (!v) return;
                        if (!window.confirm(`Apply this decision to ${lt} conflict(s) matched by ${"reason" === Te ? "reason" : "asset_uid" === Te ? "asset UID" : "manifest path"}?`)) return;
                        Ze(!0), Ye("");
                        const e = {
                          action: Le,
                          target_id: at ? Number(He) : null,
                          note: Je,
                          persist_global: We
                        };
                        try {
                          for (const t of it) t.original_id && await v(t.original_id, e);
                          qe(""), Ke("");
                        } catch (e) {
                          Ye(e?.message || "Bulk apply failed.");
                        } finally {
                          Ze(!1);
                        }
                      },
                      disabled: Xe,
                      children: Xe ? "Applying…" : "Apply to Matches"
                    }) ]
                  }) ]
                }), (0, s.jsxs)("div", {
                  className: "dbvc-admin-app__entity-columns",
                  children: [ (0, s.jsxs)("div", {
                    children: [ (0, s.jsx)("h4", {
                      children: "Raw Current"
                    }), (0, s.jsx)("pre", {
                      children: JSON.stringify(ae, null, 2)
                    }) ]
                  }), (0, s.jsxs)("div", {
                    children: [ (0, s.jsx)("h4", {
                      children: "Raw Proposed (Bundle)"
                    }), (0, s.jsx)("pre", {
                      children: JSON.stringify(oe, null, 2)
                    }) ]
                  }) ]
                }) ]
              }) : (0, s.jsx)("p", {
                children: "Select an entity to view details."
              }));
            };
            function D({open: e, onClose: n, report: i, onMarkCanonical: l, actionKey: o, bulkMode: r, onBulkModeChange: c, confirmPhrase: d, confirmValue: u, onConfirmChange: p, onBulkCleanup: h, bulkBusy: m, bulkDisabled: v}) {
              var b, f;
              const w = [ {
                value: "slug_id",
                label: "Slug + ID"
              }, {
                value: "slug",
                label: "Slug"
              }, {
                value: "id",
                label: "ID"
              } ];
              return e ? (0, s.jsxs)(t.Modal, {
                title: (0, s.jsxs)("span", {
                  className: "dbvc-duplicates-modal__title",
                  children: [ `Duplicate entities (${null !== (b = i.count) && void 0 !== b ? b : 0})`, (0, s.jsx)("span", {
                    className: "dbvc-duplicates-modal__lede",
                    children: "Choose the filename format to keep and confirm the cleanup to delete the rest of the duplicate JSON files."
                  }) ]
                }),
                onRequestClose: n,
                className: "dbvc-duplicates-modal",
                children: [ (0, s.jsxs)("div", {
                  className: "dbvc-duplicates-modal__bulk",
                  children: [ (0, s.jsxs)("label", {
                    className: "dbvc-duplicates-modal__bulk-select",
                    children: [ "Keep filenames as:", (0, s.jsx)("select", {
                      value: r,
                      onChange: e => c && c(e.target.value),
                      children: w.map(e => (0, s.jsx)("option", {
                        value: e.value,
                        children: e.label
                      }, e.value))
                    }) ]
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-duplicates-modal__bulk-inline",
                    children: [ (0, s.jsx)("input", {
                      type: "text",
                      value: u,
                      placeholder: `Type ${d}`,
                      onChange: e => p && p(e.target.value)
                    }), (0, s.jsx)(t.Button, {
                      variant: "primary",
                      onClick: h,
                      disabled: v,
                      isBusy: m,
                      children: m ? "Cleaning…" : "Bulk clean duplicates"
                    }) ]
                  }) ]
                }), 0 === (null !== (f = i.items) && void 0 !== f ? f : []).length ? (0, 
                s.jsx)("p", {
                  children: "No duplicate manifest entries were detected for this proposal."
                }) : i.items.map(e => {
                  var n, i;
                  const r = null !== (n = e.entries) && void 0 !== n ? n : [], c = r.reduce((e, t) => {
                    const s = t.post_modified ? Date.parse(t.post_modified.replace(" ", "T")) : 0;
                    return Number.isFinite(s) && s > e ? s : e;
                  }, 0);
                  return (0, s.jsxs)("div", {
                    className: "dbvc-duplicate-group",
                    children: [ (0, s.jsxs)("div", {
                      className: "dbvc-duplicate-group__header",
                      children: [ (0, s.jsxs)("div", {
                        children: [ (0, s.jsx)("strong", {
                          children: _(e)
                        }), (0, s.jsxs)("div", {
                          className: "dbvc-duplicate-group__meta",
                          children: [ (0, s.jsxs)("span", {
                            children: [ "UID: ", e.vf_object_uid ]
                          }), (0, s.jsxs)("span", {
                            children: [ "Slug: ", j(e) ]
                          }), (0, s.jsxs)("span", {
                            children: [ "Type: ", g(e) ]
                          }) ]
                        }) ]
                      }), (0, s.jsx)("div", {
                        className: "dbvc-duplicate-group__badges",
                        children: (0, s.jsxs)("span", {
                          className: "dbvc-badge",
                          children: [ null !== (i = e.entries?.length) && void 0 !== i ? i : 0, " files" ]
                        })
                      }) ]
                    }), (0, s.jsxs)("table", {
                      className: "widefat",
                      children: [ (0, s.jsx)("thead", {
                        children: (0, s.jsxs)("tr", {
                          children: [ (0, s.jsx)("th", {
                            children: "Path"
                          }), (0, s.jsx)("th", {
                            children: "Hash"
                          }), (0, s.jsx)("th", {
                            children: "Content hash"
                          }), (0, s.jsx)("th", {
                            children: "Modified"
                          }), (0, s.jsx)("th", {
                            children: "Status"
                          }), (0, s.jsx)("th", {
                            children: "Size"
                          }), (0, s.jsx)("th", {
                            children: "Actions"
                          }) ]
                        })
                      }), (0, s.jsx)("tbody", {
                        children: r.map((n, i) => {
                          const r = `${e.vf_object_uid}::${i}::${n.path || "no-path"}`, d = n.post_modified ? Date.parse(n.post_modified.replace(" ", "T")) : 0, u = c && Number.isFinite(d) && d === c;
                          return (0, s.jsxs)("tr", {
                            className: u ? "is-latest" : "",
                            children: [ (0, s.jsx)("td", {
                              className: "dbvc-text-break",
                              children: n.path || "—"
                            }), (0, s.jsx)("td", {
                              className: "dbvc-text-break",
                              children: n.hash || "—"
                            }), (0, s.jsx)("td", {
                              className: "dbvc-text-break",
                              children: n.content_hash || "—"
                            }), (0, s.jsx)("td", {
                              children: n.post_modified ? a(n.post_modified) : "—"
                            }), (0, s.jsx)("td", {
                              children: n.post_status || "—"
                            }), (0, s.jsx)("td", {
                              children: "number" == typeof n.size ? `${n.size} B` : "—"
                            }), (0, s.jsx)("td", {
                              children: (0, s.jsx)(t.Button, {
                                variant: "secondary",
                                onClick: () => l && l(e.vf_object_uid, n.path),
                                disabled: o === r,
                                children: o === r ? "Marking…" : "Keep this file"
                              })
                            }) ]
                          }, r);
                        })
                      }) ]
                    }) ]
                  }, e.vf_object_uid);
                }), (0, s.jsx)("p", {
                  className: "description",
                  children: "Tip: Clean up duplicate JSON files in the sync folder before exporting new proposals to keep reviewers focused on a single entity."
                }) ]
              }) : null;
            }
            const R = () => {
              var o, r, c, d, u, p, v, f, R, $, I, A, E, U, B, O, T, P, F, V, L, z, H, K, J, q, W;
              const [G, X] = (0, e.useState)([]), [Z, Q] = (0, e.useState)(null), [deleteBusyId, setDeleteBusyId] = (0, e.useState)(null), [Y, ee] = (0, 
              e.useState)(null), [te, se] = (0, e.useState)([]), [ne, ie] = (0, e.useState)("needs_review"), [le, ae] = (0, 
              e.useState)(""), [oe, re] = (0, e.useState)(null), [ce, de] = (0, e.useState)(null), [ue, pe] = (0, 
              e.useState)(!1), [he, me] = (0, e.useState)({}), [ve, be] = (0, e.useState)({}), [fe, ge] = (0, 
              e.useState)(null), [xe, je] = (0, e.useState)(!0), [_e, ye] = (0, e.useState)(!1), [we, Ce] = (0, 
              e.useState)(!1), [Ne, ke] = (0, e.useState)(!1), [Se, De] = (0, e.useState)(!1), [Re, $e] = (0, 
              e.useState)(null), [Ie, Ae] = (0, e.useState)(null), [Ee, Me] = (0, e.useState)(null), [Ue, Be] = (0, 
              e.useState)(null), [Oe, Te] = (0, e.useState)(!1), [Pe, Fe] = (0, e.useState)(!1), [Ve, Le] = (0, 
              e.useState)(!1), [ze, He] = (0, e.useState)(""), [Ke, Je] = (0, e.useState)(null), [qe, We] = (0, 
              e.useState)(null), [Ge, Xe] = (0, e.useState)(!1), [Ze, Qe] = (0, e.useState)("full"), [Ye, et] = (0, 
              e.useState)(!1), [tt, st] = (0, e.useState)("conflicts"), [allEntities, setAllEntities] = (0, e.useState)([]), [allEntitiesLoading, setAllEntitiesLoading] = (0, e.useState)(!1), [nt, it] = (0, e.useState)([]), [lt, at] = (0, 
              e.useState)([]), [ot, rt] = (0, e.useState)({}), [ct, dt] = (0, e.useState)(null), [ut, pt] = (0, 
              e.useState)(!1), [ht, mt] = (0, e.useState)(!1), [vt, bt] = (0, e.useState)(!1), [ft, gt] = (0, 
              e.useState)(!1), [xt, jt] = (0, e.useState)(() => {
                const e = {};
                return y.forEach(t => {
                  e[t.id] = !1 !== t.defaultVisible;
                }), e;
              }), [_t, yt] = (0, e.useState)({
                count: 0,
                items: []
              }), [wt, Ct] = (0, e.useState)(!1), [Nt, kt] = (0, e.useState)(null), [St, Dt] = (0, 
              e.useState)(!1), [duplicateActionKey, setDuplicateActionKey] = (0, e.useState)(""), [duplicateMode, setDuplicateMode] = (0, e.useState)("slug_id"), [duplicateConfirm, setDuplicateConfirm] = (0, e.useState)(""), [duplicateBulkBusy, setDuplicateBulkBusy] = (0, e.useState)(!1), [It, At] = (0, e.useState)(!1), [Et, Mt] = (0, 
              e.useState)(!1), [Ut, Bt] = (0, e.useState)(!1), [unkeepBusy, setUnkeepBusy] = (0, e.useState)(!1), [Ot, Tt] = (0, e.useState)(() => new Set), [actionsTrayOpen, setActionsTrayOpen] = (0, e.useState)(!1), [maskFields, setMaskFields] = (0, e.useState)([]), [maskLoading, setMaskLoading] = (0, e.useState)(!1), [maskError, setMaskError] = (0, e.useState)(null), [maskApplying, setMaskApplying] = (0, e.useState)(!1), [maskAttention, setMaskAttention] = (0, e.useState)(!1), [maskBulkAction, setMaskBulkAction] = (0, e.useState)("ignore"), [maskBulkOverride, setMaskBulkOverride] = (0, e.useState)(""), [maskBulkNote, setMaskBulkNote] = (0, e.useState)(""), [columnToggleOpen, setColumnToggleOpen] = (0, 
              e.useState)(!1), [filterToggleOpen, setFilterToggleOpen] = (0, e.useState)(!1), [objectTypeFilters, setObjectTypeFilters] = (0, e.useState)([]), [uidMismatchFilter, setUidMismatchFilter] = (0, e.useState)(!1), columnToggleRef = (0, e.useRef)(null), filterToggleRef = (0, e.useRef)(null), actionsTrayRef = (0, e.useRef)(null), duplicateConfirmPhrase = "DELETE", Pt = (0, 
              e.useMemo)(() => y.filter(e => xt[e.id]), [ xt ]), Ft = (0, e.useCallback)(e => {
                jt(t => {
                  const s = y.find(t => t.id === e);
                  return s && s.lockVisible ? t : {
                    ...t,
                    [e]: !t[e]
                  };
                });
              }, []), Vt = (0, e.useCallback)(async (e, t = {}) => {
                if (!e) return yt({
                  count: 0,
                  items: []
                }), void kt(null);
                const {signal: s} = t;
                Ct(!0), kt(null);
                try {
                  var i, l;
                  const t = await n(`proposals/${encodeURIComponent(e)}/duplicates`, s ? {
                    signal: s
                  } : void 0);
                  yt({
                    count: null !== (i = t.count) && void 0 !== i ? i : t.items ? t.items.length : 0,
                    items: null !== (l = t.items) && void 0 !== l ? l : []
                  });
                } catch (e) {
                  if ("AbortError" === e.name) return;
                  kt(e?.message || "Failed to load duplicates."), yt({
                    count: 0,
                    items: []
                  });
                } finally {
                  Ct(!1);
                }
              }, []), Lt = (0, e.useRef)(null);
              (0, e.useEffect)(() => {
                Lt.current = oe;
              }, [ oe ]);
              const Ht = (0, e.useCallback)(async (e, t, s) => {
                if (!e) return se([]), [];
                Ce(!0), Ae(null);
                try {
                  var i;
                  const l = t && ![ "needs_review", "needs_review_media", "resolved", "new_entities" ].includes(t) || !t || "all" === t ? "" : `?status=${encodeURIComponent(t)}`, a = await n(`proposals/${encodeURIComponent(e)}/entities${l}`, {
                    signal: s
                  }), o = null !== (i = a.items) && void 0 !== i ? i : [], r = sortEntitiesForTable(o);
                  return se(r), a.decision_summary && X(t => t.map(t => t.id === e ? {
                    ...t,
                    decisions: a.decision_summary
                  } : t)), a.resolver_decisions && X(t => t.map(t => t.id === e ? {
                    ...t,
                    resolver_decisions: a.resolver_decisions
                  } : t)), r;
                } catch (e) {
                  return "AbortError" !== e.name && Ae(e.message), [];
                } finally {
                  Ce(!1);
                }
              }, []), MASK_UNDO_STORAGE_KEY = "DBVC_MASK_UNDO", MASK_CACHE_PREFIX = "DBVC_MASK_CACHE_", MASK_CACHE_TTL = 3e5;
              const fetchAllEntities = (0, e.useCallback)(async (e, t) => {
                if (!e) return setAllEntities([]), [];
                setAllEntitiesLoading(!0);
                try {
                  var s;
                  const i = await n(`proposals/${encodeURIComponent(e)}/entities`, t ? {
                    signal: t
                  } : void 0), l = null !== (s = i.items) && void 0 !== s ? s : [], a = sortEntitiesForTable(l);
                  return setAllEntities(a), a;
                } catch (e) {
                  return "AbortError" !== e.name && setAllEntities([]), [];
                } finally {
                  setAllEntitiesLoading(!1);
                }
              }, []);
              const maskPrefetchRef = (0, e.useRef)({});
              const getMaskPrefetchState = (0, e.useCallback)(e => maskPrefetchRef.current[e], []);
              const setMaskPrefetchState = (0, e.useCallback)((e, t) => {
                if (!e) return;
                maskPrefetchRef.current[e] = t;
              }, []);
              const getMaskCacheKey = (0, e.useCallback)(e => `${MASK_CACHE_PREFIX}${e}`, []);
              const readMaskCache = (0, e.useCallback)(t => {
                if (!t || "undefined" == typeof window || !window.sessionStorage) return null;
                const s = window.sessionStorage.getItem(getMaskCacheKey(t));
                if (!s) return null;
                try {
                  const e = JSON.parse(s);
                  if (!e || !Array.isArray(e.fields)) return null;
                  if (e.updatedAt && Date.now() - e.updatedAt > MASK_CACHE_TTL) return null;
                  return e;
                } catch (e) {
                  return null;
                }
              }, [ getMaskCacheKey ]);
              const writeMaskCache = (0, e.useCallback)((t, s) => {
                if (!t || "undefined" == typeof window || !window.sessionStorage) return;
                try {
                  window.sessionStorage.setItem(getMaskCacheKey(t), JSON.stringify({
                    fields: s,
                    updatedAt: Date.now()
                  }));
                } catch (e) {}
              }, [ getMaskCacheKey ]);
              const [maskProgress, setMaskProgress] = (0, e.useState)(0), loadMasking = (0, e.useCallback)(async (proposalId, t) => {
                if (!proposalId) return setMaskFields([]), setMaskProgress(0), void setMaskAttention(!1);
                setMaskLoading(!0), setMaskError(null), setMaskProgress(0);
                const normalizeRequestOptions = s => {
                  if (!s) return void 0;
                  if ("object" == typeof s && "signal" in s) return s;
                  return {
                    signal: s
                  };
                };
                setMaskPrefetchState(proposalId, "loading");
                try {
                  let s = 1, i = [], l = !0, a = 1, o = 1;
                  for (;l;) {
                    const r = await n(`proposals/${encodeURIComponent(proposalId)}/masking?page=${s}`, normalizeRequestOptions(t));
                    Array.isArray(r?.fields) && (i = i.concat(r.fields));
                    const c = r?.chunk?.total_pages ? parseInt(r.chunk.total_pages, 10) : 1;
                    o = c > 0 ? c : 1, a = s, r?.chunk?.has_more ? s++ : l = !1, setMaskProgress(Math.min(100, Math.round(a / o * 100)));
                  }
                  setMaskFields(i), actionsTrayOpen ? setMaskAttention(!1) : setMaskAttention(i.length > 0), setMaskProgress(100), writeMaskCache(proposalId, i), setMaskPrefetchState(proposalId, "success");
                } catch (error) {
                  if ("AbortError" === error.name) {
                    setMaskPrefetchState(proposalId, "failed");
                    return;
                  }
                  console.error("DBVC masking fetch failed:", error);
                  setMaskError(error?.message || "Failed to load masking candidates."), setMaskPrefetchState(proposalId, "failed");
                } finally {
                  setMaskLoading(!1), setTimeout(() => setMaskProgress(0), 1500);
                }
              }, [ actionsTrayOpen, writeMaskCache, setMaskPrefetchState ]);
              (0, e.useEffect)(() => {
                if (!Z) {
                  setMaskFields([]), setMaskProgress(0);
                  return;
                }
                const cached = readMaskCache(Z);
                if (cached && Array.isArray(cached.fields)) {
                  setMaskFields(cached.fields), setMaskProgress(100), actionsTrayOpen || setMaskAttention(cached.fields.length > 0), maskPrefetchRef.current[Z] = !0;
                } else {
                  setMaskFields([]), setMaskProgress(0), maskPrefetchRef.current[Z] = !1;
                }
              }, [ Z, readMaskCache, actionsTrayOpen ]);
              const [pendingMaskUndo, setPendingMaskUndo] = (0, e.useState)(null), [maskApplyProgress, setMaskApplyProgress] = (0, e.useState)(0), [maskReverting, setMaskReverting] = (0, e.useState)(!1), applyMasking = (0, e.useCallback)(async () => {
                const safeFields = Array.isArray(maskFields) ? maskFields : [];
                if (!Z || !safeFields.length) return setMaskError("No masked fields are available to apply."), void setMaskAttention(!1);
                if ("override" === maskBulkAction && !maskBulkOverride.trim()) return setMaskError("Provide an override value to continue."), void 0;
                const chunkSize = 50, batches = [];
                for (let idx = 0; idx < safeFields.length; idx += chunkSize) {
                  const slice = safeFields.slice(idx, idx + chunkSize).map(e => {
                    const t = {
                      vf_object_uid: e.vf_object_uid,
                      meta_path: e.meta_path,
                      action: maskBulkAction
                    };
                    return "auto_accept" === maskBulkAction && (t.suppress = !0), "override" === maskBulkAction && (t.override_value = maskBulkOverride, maskBulkNote && (t.note = maskBulkNote)), t;
                  });
                  batches.push(slice);
                }
                setMaskApplying(!0), setMaskApplyProgress(0), setMaskError(null);
                try {
                  const responses = [];
                  for (let batchIndex = 0; batchIndex < batches.length; batchIndex++) {
                    const batch = batches[batchIndex];
                    const response = await i(`proposals/${encodeURIComponent(Z)}/masking/apply`, {
                      items: batch
                    });
                    responses.push(response), setMaskApplyProgress(Math.min(99, Math.round((batchIndex + 1) / batches.length * 100)));
                  }
                  setMaskApplyProgress(100);
                  const merged = [];
                  responses.forEach(e => {
                    Array.isArray(e?.entities) && merged.push(...e.entities);
                  });
                  merged.length && se(s => s.map(s => {
                    const n = merged.find(e => e.vf_object_uid === s.vf_object_uid);
                    return n ? {
                      ...s,
                      diff_state: n.diff_state || s.diff_state,
                      decision_summary: n.decision_summary || s.decision_summary,
                      overall_status: n.overall_status || s.overall_status
                    } : s;
                  })), oe && merged.forEach(e => {
                    e.vf_object_uid === oe && de(t => t ? {
                      ...t,
                      diff_state: e.diff_state || t.diff_state,
                      decision_summary: e.decision_summary || t.decision_summary,
                      overall_status: e.overall_status || t.overall_status
                    } : t);
                  });
                  try {
                    const s = {
                      proposalId: Z,
                      items: safeFields
                    };
                    window.sessionStorage && window.sessionStorage.setItem(MASK_UNDO_STORAGE_KEY, JSON.stringify(s)), setPendingMaskUndo(s);
                  } catch (s) {}
                  await Promise.all([loadMasking(Z), Ht(Z, ne), Vt(Z)]), setMaskAttention(!1), it(t => [ ...t, {
                    id: `${Date.now()}-mask`,
                    severity: "success",
                    title: "Masking applied",
                    message: `Updated ${safeFields.length} field${1 === safeFields.length ? "" : "s"}.`,
                    timestamp: new Date().toISOString()
                  } ]);
                } catch (e) {
                  setMaskError(e?.message || "Failed to apply masking rules.");
                } finally {
                  setMaskApplying(!1), setTimeout(() => setMaskApplyProgress(0), 1500);
                }
              }, [ Z, maskFields, maskBulkAction, maskBulkNote, maskBulkOverride ]), undoMasking = (0, e.useCallback)(async () => {
                if (!pendingMaskUndo || !Z) return;
                const e = Array.isArray(pendingMaskUndo.items) ? pendingMaskUndo.items : [];
                if (!e.length) return;
                setMaskApplying(!0), setMaskError(null);
                try {
                  const t = e.map(e => ({
                    vf_object_uid: e.vf_object_uid,
                    meta_path: e.meta_path,
                    action: "ignore"
                  }));
                  await i(`proposals/${encodeURIComponent(Z)}/masking/apply`, {
                    items: t
                  }), window.sessionStorage && window.sessionStorage.removeItem(MASK_UNDO_STORAGE_KEY), setPendingMaskUndo(null), await Promise.all([loadMasking(Z), Ht(Z, ne), Vt(Z)]);
                } catch (e) {
                  setMaskError(e?.message || "Failed to undo masking rules.");
                } finally {
                  setMaskApplying(!1);
                }
              }, [ pendingMaskUndo, Z ]), revertMasking = (0, e.useCallback)(async () => {
                if (!Z) return;
                setMaskReverting(!0), setMaskError(null);
                try {
                  const e = await i(`proposals/${encodeURIComponent(Z)}/masking/revert`, {});
                  window.sessionStorage && window.sessionStorage.removeItem(MASK_UNDO_STORAGE_KEY), setPendingMaskUndo(null), await Promise.all([loadMasking(Z), Ht(Z, ne), Vt(Z)]), it(t => [ ...t, {
                    id: `${Date.now()}-mask-revert`,
                    severity: "info",
                    title: "Masking decisions reverted",
                    message: (e?.cleared?.decisions || 0) > 0 ? `Cleared ${e.cleared.decisions} masked decision${1 === e.cleared.decisions ? "" : "s"}.` : "No masked decisions matched the current masking rules.",
                    timestamp: new Date().toISOString()
                  } ]);
                } catch (e) {
                  setMaskError(e?.message || "Failed to revert masking decisions.");
                } finally {
                  setMaskReverting(!1);
                }
              }, [ Z, loadMasking, Ht, ne, Vt ]);
              const zt = (0, e.useCallback)(async (e = {}) => {
                const {signal: t, focusProposalId: s} = e;
                je(!0), $e(null);
                try {
                  var i;
                  const e = null !== (i = (await n("proposals", t ? {
                    signal: t
                  } : void 0)).items) && void 0 !== i ? i : [];
                  X(e), Q(t => {
                    var n;
                    return s && e.some(e => e.id === s) ? s : t && e.some(e => e.id === t) ? t : null !== (n = e[0]?.id) && void 0 !== n ? n : null;
                  });
                } catch (e) {
                  if ("AbortError" === e.name) return;
                  $e(e.message);
                } finally {
                  je(!1), ye(!0);
                }
              }, []), deleteProposal = (0, e.useCallback)(async e => {
                if (!e) return;
                const t = G.find(t => t.id === e);
                if (!t) return;
                if (e === Z) return void it(t => [ ...t, {
                  id: `${Date.now()}-proposal-delete-blocked`,
                  severity: "warning",
                  title: "Cannot delete current proposal",
                  message: "Select another proposal before deleting this one.",
                  timestamp: new Date().toISOString()
                } ]);
                if (t.locked) return void it(t => [ ...t, {
                  id: `${Date.now()}-proposal-delete-locked`,
                  severity: "warning",
                  title: "Proposal is locked",
                  message: "Locked proposals cannot be deleted.",
                  timestamp: new Date().toISOString()
                } ]);
                if (!window.confirm(`Delete proposal "${e}"? This cannot be undone.`)) return;
                setDeleteBusyId(e);
                try {
                  await l(`proposals/${encodeURIComponent(e)}`), await zt({
                    focusProposalId: Z && Z !== e ? Z : null
                  }), it(t => [ ...t, {
                    id: `${Date.now()}-proposal-delete-success`,
                    severity: "info",
                    title: "Proposal deleted",
                    message: `Deleted ${e}.`,
                    timestamp: new Date().toISOString()
                  } ]);
                } catch (t) {
                  it(e => [ ...e, {
                    id: `${Date.now()}-proposal-delete-error`,
                    severity: "error",
                    title: "Delete failed",
                    message: t?.message || "Unable to delete proposal.",
                    timestamp: new Date().toISOString()
                  } ]);
                } finally {
                  setDeleteBusyId(null);
                }
              }, [ G, Z, zt, it ]), refreshEntities = (0, e.useCallback)(() => {
                Z && Ht(Z, ne);
              }, [ Z, ne, Ht ]), Kt = (0, e.useCallback)(e => {
                e.stopPropagation();
              }, []), Jt = (0, e.useCallback)(e => {
                e.preventDefault(), e.stopPropagation();
              }, []), qt = (0, e.useMemo)(() => ce?.resolver?.attachments?.length ? ce.resolver.attachments : Y?.attachments || [], [ Y, ce ]), Wt = (0, 
              e.useCallback)(() => {
                Z && Vt(Z);
              }, [ Z, Vt ]), Gt = (0, e.useCallback)(async (e, t) => {
                if (Z && e && t) {
                  setDuplicateActionKey(`${e}::${t}`), kt(null);
                  try {
                    await i(`proposals/${encodeURIComponent(Z)}/duplicates/cleanup`, {
                      vf_object_uid: e,
                      keep_path: t
                    }), await Vt(Z), await Ht(Z, ne);
                  } catch (e) {
                    kt(e?.message || "Failed to mark canonical entry.");
                  } finally {
                    setDuplicateActionKey("");
                  }
                }
              }, [ Z, Vt, Ht, ne ]), bulkDuplicateCleanup = (0, e.useCallback)(async () => {
                if (!Z || !_t.count || !duplicateMode) return;
                setDuplicateBulkBusy(!0), kt(null);
                try {
                  await i(`proposals/${encodeURIComponent(Z)}/duplicates/cleanup`, {
                    apply_all: !0,
                    preferred_format: duplicateMode,
                    confirm_token: duplicateConfirm.trim()
                  }), setDuplicateConfirm(""), await Vt(Z), await Ht(Z, ne);
                } catch (e) {
                  kt(e?.message || "Failed to clean duplicates.");
                } finally {
                  setDuplicateBulkBusy(!1);
                }
              }, [ Z, _t.count, duplicateMode, duplicateConfirm, Vt, Ht, ne ]), bulkCleanupDisabled = !_t.count || wt || !duplicateMode || duplicateConfirm.trim().toUpperCase() !== duplicateConfirmPhrase || duplicateBulkBusy, [Xt, Zt] = (0, e.useState)(!1), Qt = (0, e.useCallback)(e => {
                Tt(t => {
                  const s = new Set(t);
                  return s.has(e) ? s.delete(e) : s.add(e), s;
                });
              }, []), Yt = (0, e.useCallback)((e, t) => {
                Tt(s => {
                  const n = new Set(s);
                  return e.forEach(e => {
                    t ? n.add(e) : n.delete(e);
                  }), n;
                });
              }, []), es = (0, e.useCallback)(() => {
                Tt(new Set);
              }, []), ts = (0, e.useCallback)(() => {
                Tt(new Set(te.map(e => e.vf_object_uid)));
              }, [ te ]), ss = (0, e.useMemo)(() => te.filter(e => b(e)), [ te ]), ns = ss.length > 0, is = (0, 
              e.useCallback)(async () => {
                if (Z && ns) {
                  At(!0), kt(null);
                  try {
                    await i(`proposals/${encodeURIComponent(Z)}/entities/accept`, {
                      scope: "new_only"
                    }), await Ht(Z, ne), await Vt(Z);
                  } catch (e) {
                    kt(e?.message || "Failed to accept new entities.");
                  } finally {
                    At(!1);
                  }
                }
              }, [ Z, ns, Ht, ne, Vt ]), ls = (0, e.useCallback)(async () => {
                if (Z && 0 !== Ot.size) {
                  Mt(!0), ge(null);
                  try {
                    await i(`proposals/${encodeURIComponent(Z)}/entities/accept`, {
                      scope: "selected",
                      vf_object_uids: Array.from(Ot)
                    }), await Ht(Z, ne), es();
                  } catch (e) {
                    ge(e?.message || "Failed to accept selected entities.");
                  } finally {
                    Mt(!1);
                  }
                }
              }, [ Z, Ot, Ht, ne, es ]), as = (0, e.useCallback)(async () => {
                if (Z && 0 !== Ot.size) {
                  Bt(!0), ge(null);
                  try {
                    await i(`proposals/${encodeURIComponent(Z)}/entities/unaccept`, {
                      scope: "selected",
                      vf_object_uids: Array.from(Ot)
                    }), await Ht(Z, ne), es();
                  } catch (e) {
                    ge(e?.message || "Failed to unaccept selected entities.");
                  } finally {
                    Bt(!1);
                  }
                }
              }, [ Z, Ot, Ht, ne, es ]), unkeepSelected = (0, e.useCallback)(async () => {
                if (Z && 0 !== Ot.size) {
                  setUnkeepBusy(!0), ge(null);
                  try {
                    await i(`proposals/${encodeURIComponent(Z)}/entities/unkeep`, {
                      scope: "selected",
                      vf_object_uids: Array.from(Ot)
                    }), await Ht(Z, ne), es();
                  } catch (e) {
                    ge(e?.message || "Failed to unkeep selected entities.");
                  } finally {
                    setUnkeepBusy(!1);
                  }
                }
              }, [ Z, Ot, Ht, ne, es ]);
              (0, e.useEffect)(() => {
                Je(null), We(null), Te(!1), Xe(!1), Qe("full"), et(!1), it([]);
              }, [ Z ]), (0, e.useEffect)(() => {
                "partial" !== Ze && et(!1);
              }, [ Ze ]), (0, e.useEffect)(() => {
                if (0 === nt.length) return;
                const e = setTimeout(() => {
                  it(e => e.slice(1));
                }, 6e3);
                return () => clearTimeout(e);
              }, [ nt ]), (0, e.useEffect)(() => {
                const e = new AbortController;
                return zt({
                  signal: e.signal
                }), () => e.abort();
              }, [ zt ]);
              (0, e.useEffect)(() => {
                if ("undefined" == typeof window || !window.sessionStorage) return;
                const e = window.sessionStorage.getItem(MASK_UNDO_STORAGE_KEY);
                if (!e) return void setPendingMaskUndo(null);
                try {
                  const t = JSON.parse(e);
                  t?.proposalId === Z ? setPendingMaskUndo(t) : (window.sessionStorage.removeItem(MASK_UNDO_STORAGE_KEY), setPendingMaskUndo(null));
                } catch (t) {
                  window.sessionStorage.removeItem(MASK_UNDO_STORAGE_KEY), setPendingMaskUndo(null);
                }
              }, [ Z ]), (0, e.useEffect)(() => {
                if (!columnToggleOpen) return;
                const e = e => {
                  columnToggleRef.current && columnToggleRef.current.contains(e.target) || setColumnToggleOpen(!1);
                }, t = e => {
                  "Escape" === e.key && setColumnToggleOpen(!1);
                };
                return document.addEventListener("mousedown", e), document.addEventListener("keyup", t), () => {
                  document.removeEventListener("mousedown", e), document.removeEventListener("keyup", t);
                };
              }, [ columnToggleOpen ]), (0, e.useEffect)(() => {
                if (!actionsTrayOpen) return;
                const e = e => {
                  actionsTrayRef.current && actionsTrayRef.current.contains(e.target) || setActionsTrayOpen(!1);
                }, t = e => {
                  "Escape" === e.key && setActionsTrayOpen(!1);
                };
                return document.addEventListener("mousedown", e), document.addEventListener("keyup", t), () => {
                  document.removeEventListener("mousedown", e), document.removeEventListener("keyup", t);
                };
              }, [ actionsTrayOpen ]);
              const os = (0, e.useCallback)(async (e, t) => {
                if (e) {
                  ke(!0), Me(null);
                  try {
                    const s = await n(`proposals/${encodeURIComponent(e)}/resolver`, {
                      signal: t
                    });
                    ee(s);
                  } catch (e) {
                    "AbortError" !== e.name && Me(e.message);
                  } finally {
                    ke(!1);
                  }
                } else ee(null);
              }, []);
              (0, e.useEffect)(() => {
                if (!Z) return re(null), de(null), void pe(!1);
                const e = new AbortController;
                return Ht(Z, ne, e.signal).then(e => {
                  if (!e.length) return re(null), void pe(!1);
                  const t = Lt.current;
                  t && e.some(e => e.vf_object_uid === t) || (re(null), pe(!1));
                }), () => e.abort();
              }, [ Z, ne, Ht ]), (0, e.useEffect)(() => {
                if (!Z) return;
                const e = new AbortController;
                return os(Z, e.signal), () => e.abort();
              }, [ Z, os ]), (0, e.useEffect)(() => {
                if (!Z) return yt({
                  count: 0,
                  items: []
                }), void kt(null);
                const e = new AbortController;
                return Vt(Z, {
                  signal: e.signal
                }), () => e.abort();
              }, [ Z, Vt ]), (0, e.useEffect)(() => {
                if (!Z || !oe) return de(null), me({}), void be({});
                const e = new AbortController;
                return (async () => {
                  De(!0), Be(null), ge(null), be({});
                  try {
                    var t;
                    const s = await n(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(oe)}`, {
                      signal: e.signal
                    });
                    de(s), me(null !== (t = s.decisions) && void 0 !== t ? t : {});
                  } catch (e) {
                    "AbortError" !== e.name && Be(e.message);
                  } finally {
                    De(!1);
                  }
                })(), () => e.abort();
              }, [ Z, oe ]), (0, e.useEffect)(() => {
                if (!Z || actionsTrayOpen || maskLoading || we) return;
                if (Array.isArray(maskFields) && maskFields.length) return;
                const state = getMaskPrefetchState(Z);
                if (void 0 !== state) return;
                const e = new AbortController;
                return loadMasking(Z, {
                  signal: e.signal
                }), () => e.abort();
              }, [ Z, actionsTrayOpen, maskLoading, maskFields, we, loadMasking, getMaskPrefetchState ]), (0, e.useEffect)(() => {
                if (!Z || !actionsTrayOpen) return;
                const e = new AbortController;
                return loadMasking(Z, {
                  signal: e.signal
                }), () => e.abort();
              }, [ Z, actionsTrayOpen, loadMasking ]), (0, e.useEffect)(() => {
                actionsTrayOpen || setMaskAttention(maskFields.length > 0);
              }, [ maskFields, actionsTrayOpen ]);
              const rs = (0, e.useCallback)((e, t) => {
                const s = Number(e), n = (e = []) => e.map(e => {
                  if ((void 0 !== e.original_id ? e.original_id : e.descriptor?.original_id) === s) {
                    if (t) return {
                      ...e,
                      decision: t
                    };
                    const {decision: s, ...n} = e;
                    return n;
                  }
                  return e;
                });
                ee(e => e ? {
                  ...e,
                  attachments: n(e.attachments || [])
                } : e), de(e => e ? {
                  ...e,
                  resolver: {
                    ...e.resolver || {},
                    attachments: n(e.resolver?.attachments || [])
                  }
                } : e), se(e => e.map(e => e.vf_object_uid === oe ? {
                  ...e,
                  resolver: {
                    ...e.resolver || {},
                    attachments: n(e.resolver?.attachments || [])
                  }
                } : e));
              }, [ oe ]), objectTypeOptions = (0, e.useMemo)(() => {
                const e = new Set;
                return te.forEach(t => {
                  const s = getEntityObjectType(t);
                  s && e.add(s);
                }), Array.from(e).sort((e, t) => compareEntityAlpha(normalizeEntitySort(e), normalizeEntitySort(t)));
              }, [ te ]), objectTypeFilterSet = (0, e.useMemo)(() => new Set(objectTypeFilters), [ objectTypeFilters ]), filterActiveCount = objectTypeFilters.length + (uidMismatchFilter ? 1 : 0), toggleObjectTypeFilter = (0, e.useCallback)(e => {
                setObjectTypeFilters(t => t.includes(e) ? t.filter(t => t !== e) : [ ...t, e ]);
              }, []), clearEntityFilters = (0, e.useCallback)(() => {
                setObjectTypeFilters([]), setUidMismatchFilter(!1);
              }, []), cs = (0, e.useMemo)(() => {
                let e = te;
                if ("with_decisions" === ne && (e = e.filter(e => {
                  var t;
                  return (null !== (t = e.decision_summary?.total) && void 0 !== t ? t : 0) > 0;
                })), "uid_mismatch" === ne && (e = e.filter(e => !!e.uid_mismatch)), le) {
                  const t = le.toLowerCase();
                  e = e.filter(e => [ _(e), j(e), g(e), x(e), e.path, e.term_taxonomy, e.taxonomy ].filter(Boolean).join(" ").toLowerCase().includes(t));
                }
                return objectTypeFilterSet.size > 0 && (e = e.filter(e => objectTypeFilterSet.has(getEntityObjectType(e)))), 
                uidMismatchFilter && (e = e.filter(e => !!e.uid_mismatch)), e;
              }, [ te, le, ne, objectTypeFilterSet, uidMismatchFilter ]), ds = (0, e.useMemo)(() => te.find(e => e.vf_object_uid === oe), [ te, oe ]);
              (0, e.useEffect)(() => {
                Tt(e => {
                  const t = new Set, s = new Set(te.map(e => e.vf_object_uid));
                  return e.forEach(e => {
                    s.has(e) && t.add(e);
                  }), t;
                });
              }, [ te ]);
              const us = (0, e.useMemo)(() => te.filter(e => "missing_local_hash" === e.diff_state?.reason), [ te ]), ps = (0, 
              e.useMemo)(() => G.find(e => e.id === Z), [ G, Z ]), summaryCounts = (0, e.useMemo)(() => {
                const e = {
                  proposed: {
                    total: 0,
                    posts: 0,
                    terms: 0
                  },
                  current: {
                    total: 0,
                    posts: 0,
                    terms: 0
                  }
                };
                return allEntities.forEach(t => {
                  const s = "term" === t.entity_type || "string" == typeof t.post_type && t.post_type.startsWith("term:") || t.term_taxonomy || t.taxonomy, n = s ? "terms" : "posts";
                  e.proposed.total++, e.proposed[n] += 1;
                  const i = !t.is_new_entity && t.identity_match && "none" !== t.identity_match;
                  i && (e.current.total++, e.current[n] += 1);
                }), e;
              }, [ allEntities ]), proposedMediaTotal = null !== (o = ps?.media_items) && void 0 !== o ? o : (null !== (r = Y?.metrics?.detected) && void 0 !== r ? r : 0), currentMediaTotal = null !== (c = Y?.metrics?.reused) && void 0 !== c ? c : 0, filteredTotal = cs.length, maskFieldCount = Array.isArray(maskFields) ? maskFields.length : 0, maskEntityCount = (0, e.useMemo)(() => {
                if (!Array.isArray(maskFields)) return 0;
                const e = new Set;
                return maskFields.forEach(t => {
                  t?.vf_object_uid && e.add(t.vf_object_uid);
                }), e.size;
              }, [ maskFields ]), maskFieldsByEntity = (0, e.useMemo)(() => {
                if (!Array.isArray(maskFields)) return {};
                return maskFields.reduce((e, t) => {
                  const s = t?.vf_object_uid;
                  if (!s) return e;
                  return e[s] || (e[s] = []), e[s].push(t), e;
                }, {});
              }, [ maskFields ]), statusBadges = (0, e.useMemo)(() => {
                const e = {
                  needs_review: 0,
                  needs_review_media: 0,
                  new_entities: 0,
                  with_decisions: 0,
                  uid_mismatch: 0
                };
                return te.forEach(t => {
                  "needs_review" === t.overall_status && e.needs_review++, t.media_needs_review && e.needs_review_media++, t.is_new_entity && e.new_entities++;
                  const s = t.decision_summary || {}, n = null != s.total ? s.total : 0;
                  n > 0 && e.with_decisions++, t.uid_mismatch && e.uid_mismatch++;
                }), [ {
                  id: "needs_review",
                  label: "Needs Review",
                  count: e.needs_review,
                  filter: "needs_review"
                }, {
                  id: "needs_review_media",
                  label: "Unresolved meta",
                  count: e.needs_review_media,
                  filter: "needs_review_media"
                }, {
                  id: "new_entities",
                  label: "New entities",
                  count: e.new_entities,
                  filter: "new_entities"
                }, {
                  id: "with_decisions",
                  label: "Pending decisions",
                  count: e.with_decisions,
                  filter: "with_decisions"
                }, {
                  id: "uid_mismatch",
                  label: "UID mismatch",
                  count: e.uid_mismatch,
                  filter: "uid_mismatch"
                } ];
              }, [ te ]), maskActionOptions = (0, e.useMemo)(() => [ {
                label: "Keep & ignore current meta (uses mask config)",
                value: "ignore"
              }, {
                label: "Auto-accept new meta & suppress future reviews",
                value: "auto_accept"
              }, {
                label: "Override masked value",
                value: "override"
              } ], []), maskTooltips = (0, e.useMemo)(() => ({
                apply: `Apply the configured masking rules to every entity in this proposal. Learn more: ${maskDocLink("live-proposal-masking")}`,
                ignore: `Ignore this masked field so it no longer counts toward Needs Review. Learn more: ${maskDocLink("ignore-masked-field")}`,
                auto: `Auto-accept the masked value and suppress it from future diffs. Learn more: ${maskDocLink("auto-accept-and-suppress")}`,
                override: `Override the masked value with a sanitized replacement. Learn more: ${maskDocLink("override-masked-value")}`,
                revert: `Clear masking decisions that were applied via this tool using the current masking rules. Learn more: ${maskDocLink("revert-masked-decisions")}`
              }), []);
              (0, e.useEffect)(() => {
                if (!cs.length) return re(null), void pe(!1);
                oe && !cs.some(e => e.vf_object_uid === oe) && (re(null), pe(!1));
              }, [ cs, oe ]), (0, e.useEffect)(() => {
                st("conflicts");
              }, [ oe ]), (0, e.useEffect)(() => {
                if (!Z) return setAllEntities([]);
                const e = new AbortController;
                return fetchAllEntities(Z, e.signal), () => {
                  e.abort();
                };
              }, [ Z, fetchAllEntities ]);
              const hs = (0, e.useCallback)(e => {
                if (!e) return re(null), void pe(!1);
                re(t => t === e ? t : e), pe(!0);
              }, []), ms = (0, e.useCallback)(async e => {
                if (Z) {
                  Fe(!0);
                  try {
                    const t = await i(`proposals/${encodeURIComponent(Z)}/status`, {
                      status: e
                    });
                    X(e => e.map(e => e.id === Z ? {
                      ...e,
                      status: t.status
                    } : e));
                  } catch (e) {
                    We(e?.message || "Failed to update proposal status.");
                  } finally {
                    Fe(!1);
                  }
                }
              }, [ Z ]), vs = (0, e.useCallback)(async () => {
                if (Z && 0 !== us.length && window.confirm(`Store import hashes for ${us.length} entit${1 === us.length ? "y" : "ies"}?`)) {
                  He("bulk"), Le(!0), ge(null);
                  try {
                    await i(`proposals/${encodeURIComponent(Z)}/entities/hash-sync`, {
                      vf_object_uids: us.map(e => e.vf_object_uid)
                    }), await Ht(Z, ne);
                  } catch (e) {
                    ge(e?.message || "Failed to store import hashes.");
                  } finally {
                    Le(!1), He("");
                  }
                }
              }, [ Z, us, Ht, ne ]), bs = (0, e.useCallback)(async e => {
                if (Z && e) {
                  He(e), Le(!0), ge(null);
                  try {
                    var t;
                    await i(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(e)}/hash-sync`, {}), 
                    await Ht(Z, ne);
                    const s = await n(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(e)}`);
                    de(s), me(null !== (t = s.decisions) && void 0 !== t ? t : {});
                  } catch (e) {
                    ge(e?.message || "Failed to store import hash.");
                  } finally {
                    Le(!1), He("");
                  }
                }
              }, [ Z, ne, Ht ]), fs = (0, e.useCallback)(() => {
                pe(!1);
              }, []), gs = (0, e.useCallback)(() => {
                Z && (We(null), Je(null), Qe("full"), et(!1), Xe(!0));
              }, [ Z ]), xs = (0, e.useCallback)(() => {
                Oe || Xe(!1);
              }, [ Oe ]), js = (0, e.useCallback)(e => {
                it(t => t.filter(t => t.id !== e));
              }, []), _s = (0, e.useCallback)(async () => {
                if (Z) {
                  Xe(!1), We(null), Je(null), Te(!0);
                  try {
                    var e, t;
                    const o = await i(`proposals/${encodeURIComponent(Z)}/apply`, {
                      mode: Ze,
                      ignore_missing_hash: Ye
                    });
                    Je(o), o.decisions && X(e => e.map(e => e.id === Z ? {
                      ...e,
                      decisions: o.decisions
                    } : e)), o.resolver_decisions && X(e => e.map(e => e.id === Z ? {
                      ...e,
                      resolver_decisions: o.resolver_decisions
                    } : e)), o.status && X(e => e.map(e => e.id === Z ? {
                      ...e,
                      status: o.status
                    } : e));
                    const r = null !== (e = o?.result?.imported) && void 0 !== e ? e : 0, c = null !== (t = o?.result?.skipped) && void 0 !== t ? t : 0, d = Array.isArray(o?.result?.errors) ? o.result.errors : [], u = (new Date).toISOString(), p = Date.now(), h = [];
                    var s, n, l, a;
                    d.length > 0 && h.push(d[0]), o.decisions_cleared && o.auto_clear_enabled && h.push("Reviewer decisions cleared."), 
                    o.ignore_missing_hash && h.push("Hash check override enabled."), o.resolver_decisions && h.push(`Resolver decisions — reuse: ${null !== (s = o.resolver_decisions.reuse) && void 0 !== s ? s : 0}, map: ${null !== (n = o.resolver_decisions.map) && void 0 !== n ? n : 0}, download: ${null !== (l = o.resolver_decisions.download) && void 0 !== l ? l : 0}, skip: ${null !== (a = o.resolver_decisions.skip) && void 0 !== a ? a : 0}`), 
                    "closed" === o.status && h.push("Proposal marked closed."), it(e => [ ...e, {
                      id: p,
                      severity: d.length ? "warning" : "success",
                      title: `Applied ${ps?.title || Z}`,
                      message: `Imported ${r} · Skipped ${c}`,
                      detail: h.join(" "),
                      timestamp: u
                    } ]), at(e => {
                      var t, s;
                      return [ {
                        id: p,
                        timestamp: u,
                        proposalId: Z,
                        proposalTitle: ps?.title || Z,
                        mode: null !== (t = o.mode) && void 0 !== t ? t : Ze,
                        imported: r,
                        skipped: c,
                        errors: d,
                        decisionsCleared: Boolean(o.decisions_cleared && o.auto_clear_enabled),
                        ignoreMissingHash: Boolean(o.ignore_missing_hash),
                        resolverDecisions: null !== (s = o.resolver_decisions) && void 0 !== s ? s : null
                      }, ...e ].slice(0, 10);
                    }), re(null), pe(!1), de(null), me({}), be({});
                    const m = await Ht(Z, ne);
                    m.length && (re(m[0].vf_object_uid), pe(!0)), await os(Z);
                  } catch (e) {
                    const t = e?.message || "Apply request failed.";
                    We(t);
                    const s = (new Date).toISOString(), n = Date.now(), i = [ t ];
                    Ye && i.push("Hash check override requested."), it(e => [ ...e, {
                      id: n,
                      severity: "warning",
                      title: `Apply failed (${ps?.title || Z})`,
                      message: t,
                      detail: i.join(" "),
                      timestamp: s
                    } ]), at(e => [ {
                      id: n,
                      timestamp: s,
                      proposalId: Z,
                      proposalTitle: ps?.title || Z,
                      mode: Ze,
                      imported: 0,
                      skipped: 0,
                      errors: [ t ],
                      decisionsCleared: !1,
                      ignoreMissingHash: Ye
                    }, ...e ].slice(0, 10));
                  } finally {
                    Te(!1);
                  }
                }
              }, [ Z, Ze, Ht, ne, os, ps, Ye ]), ys = (0, e.useCallback)(async (e, t) => {
                if (Z && oe) {
                  ge(null), be(t => ({
                    ...t,
                    [e]: !0
                  }));
                  try {
                    var s, n;
                    const l = await i(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(oe)}/selections`, {
                      path: e,
                      action: t
                    }), a = null !== (s = l.decisions) && void 0 !== s ? s : {}, o = null !== (n = l.summary) && void 0 !== n ? n : null;
                    me(a), de(e => {
                      var t;
                      return e ? {
                        ...e,
                        decisions: a,
                        decision_summary: null != o ? o : e.decision_summary,
                        new_entity_decision: null !== (t = a[h]) && void 0 !== t ? t : e.new_entity_decision
                      } : e;
                    }), se(e => e.map(e => {
                      var t;
                      return e.vf_object_uid === oe ? {
                        ...e,
                        decision_summary: null != o ? o : e.decision_summary,
                        new_entity_decision: null !== (t = a[h]) && void 0 !== t ? t : e.new_entity_decision
                      } : e;
                    })), l.proposal_summary && X(e => e.map(e => e.id === Z ? {
                      ...e,
                      decisions: l.proposal_summary
                    } : e));
                  } catch (e) {
                    ge(e.message);
                  } finally {
                    be(t => {
                      const s = {
                        ...t
                      };
                      return delete s[e], s;
                    });
                  }
                }
              }, [ Z, oe ]), ws = (0, e.useCallback)(async (e, t) => {
                if (Z) {
                  dt(null), rt(t => ({
                    ...t,
                    [e]: !0
                  }));
                  try {
                    var s;
                    const n = await i(`proposals/${encodeURIComponent(Z)}/resolver/${encodeURIComponent(e)}`, t);
                    return rs(e, null !== (s = n.decision) && void 0 !== s ? s : null), n;
                  } catch (e) {
                    throw dt(e.message), e;
                  } finally {
                    rt(t => {
                      const s = {
                        ...t
                      };
                      return delete s[e], s;
                    });
                  }
                }
              }, [ Z, rs ]), Cs = (0, e.useCallback)(async (e, t) => {
                await ws(e, t);
              }, [ ws ]), Ns = (0, e.useCallback)(async (e, t = "proposal") => {
                if (Z) {
                  dt(null), rt(t => ({
                    ...t,
                    [e]: !0
                  }));
                  try {
                    var s;
                    const n = await l(`proposals/${encodeURIComponent(Z)}/resolver/${encodeURIComponent(e)}?scope=${encodeURIComponent(t)}`);
                    rs(e, null !== (s = n.decision) && void 0 !== s ? s : null);
                  } catch (e) {
                    dt(e.message);
                  } finally {
                    rt(t => {
                      const s = {
                        ...t
                      };
                      return delete s[e], s;
                    });
                  }
                }
              }, [ Z, rs ]), ks = (0, e.useCallback)(async (e, t) => {
                const s = e.reason || "";
                if (!s || !qt.length) return;
                const n = qt.filter(t => t.original_id !== e.original_id && (t.reason || "") === s);
                if (n.length) {
                  if (window.confirm(`Apply this ${t.action} decision to ${n.length} other conflict(s) with reason "${s}"?`)) try {
                    await Promise.all(n.map(e => ws(e.original_id, t)));
                  } catch (e) {}
                } else window.alert("No similar conflicts found to apply this decision to.");
              }, [ qt, ws ]), Ss = (0, e.useCallback)(async () => {
                if (Z && oe && window.confirm("Clear all Accept/Keep choices for this entity?")) {
                  ge(null), pt(!0), be({});
                  try {
                    var e, t;
                    const s = await i(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(oe)}/selections`, {
                      action: "clear_all"
                    }), n = null !== (e = s.decisions) && void 0 !== e ? e : {}, l = null !== (t = s.summary) && void 0 !== t ? t : null;
                    me(n), de(e => e ? {
                      ...e,
                      decisions: n,
                      decision_summary: null != l ? l : e.decision_summary
                    } : e), se(e => e.map(e => e.vf_object_uid === oe ? {
                      ...e,
                      decision_summary: null != l ? l : e.decision_summary
                    } : e)), s.proposal_summary && X(e => e.map(e => e.id === Z ? {
                      ...e,
                      decisions: s.proposal_summary
                    } : e));
                  } catch (e) {
                    ge(e.message);
                  } finally {
                    pt(!1);
                  }
                }
              }, [ Z, oe ]), Ds = (0, e.useCallback)(async () => {
                if (Z && oe) {
                  mt(!0), ge(null);
                  try {
                    var e, t;
                    const s = null !== (e = (await i(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(oe)}/snapshot`, {})).snapshot) && void 0 !== e ? e : null;
                    s && de(e => e ? {
                      ...e,
                      current: s,
                      current_source: "snapshot"
                    } : e);
                    const l = await n(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(oe)}`);
                    de(l), me(null !== (t = l.decisions) && void 0 !== t ? t : {}), se(e => e.map(e => {
                      var t;
                      return e.vf_object_uid === oe ? {
                        ...e,
                        decision_summary: null !== (t = l.decision_summary) && void 0 !== t ? t : e.decision_summary
                      } : e;
                    }));
                  } catch (e) {
                    ge(e.message);
                  } finally {
                    mt(!1);
                  }
                }
              }, [ Z, oe ]), Rs = (0, e.useCallback)(async () => {
                if (Z) {
                  bt(!0), ge(null);
                  try {
                    var e, t;
                    const l = await n(`proposals/${encodeURIComponent(Z)}/entities?status=needs_review`), a = Array.isArray(l.items) ? l.items : [], o = a.map(e => e.vf_object_uid).filter(e => "string" == typeof e && e.length > 0), r = o.length ? {
                      entity_ids: o
                    } : {}, c = await i(`proposals/${encodeURIComponent(Z)}/snapshot`, r), d = null !== (e = c.captured) && void 0 !== e ? e : 0, u = null !== (t = c.targets) && void 0 !== t ? t : o.length || a.length || 0, p = (new Date).toISOString();
                    if (it(e => [ ...e, {
                      id: Date.now(),
                      severity: "info",
                      title: "Snapshots captured",
                      message: `Captured ${d}/${u || d} snapshot(s) for proposal ${Z}.`,
                      timestamp: p
                    } ]), await Ht(Z, ne), await os(Z), oe) {
                      var s;
                      const e = await n(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(oe)}`);
                      de(e), me(null !== (s = e.decisions) && void 0 !== s ? s : {});
                    }
                  } catch (e) {
                    ge(e.message);
                  } finally {
                    bt(!1);
                  }
                }
              }, [ Z, oe, ne, Ht, os ]), $s = (0, e.useCallback)(async () => {
                if (window.confirm("This will delete all stored backups, snapshots, and reviewer decisions. Continue?")) {
                  gt(!0), ge(null);
                  try {
                    await l("maintenance/clear-proposals"), await zt(), Q(null), se([]), de(null), ee(null), 
                    me({}), be({}), it(e => [ ...e, {
                      id: Date.now(),
                      severity: "info",
                      title: "Backups cleared",
                      message: "All proposal backups, snapshots, and decisions have been removed.",
                      timestamp: (new Date).toISOString()
                    } ]);
                  } catch (e) {
                    ge(e.message);
                  } finally {
                    gt(!1);
                  }
                }
              }, [ zt ]), Is = (0, e.useCallback)(async (e, t) => {
                if (!Z || !oe || !Array.isArray(t) || 0 === t.length) return;
                const s = t.filter(e => "string" == typeof e && e.length > 0);
                if (!s.length) return;
                const n = e => {
                  var t, s;
                  const n = null !== (t = e.decisions) && void 0 !== t ? t : {}, i = null !== (s = e.summary) && void 0 !== s ? s : null;
                  me(n), de(e => e ? {
                    ...e,
                    decisions: n,
                    decision_summary: null != i ? i : e.decision_summary
                  } : e), se(e => e.map(e => e.vf_object_uid === oe ? {
                    ...e,
                    decision_summary: null != i ? i : e.decision_summary
                  } : e)), e.proposal_summary && X(t => t.map(t => t.id === Z ? {
                    ...t,
                    decisions: e.proposal_summary
                  } : t));
                };
                ge(null), Zt(!0), be(e => {
                  const t = {
                    ...e
                  };
                  return s.forEach(e => {
                    t[e] = !0;
                  }), t;
                });
                try {
                  try {
                    const t = await i(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(oe)}/selections/bulk`, {
                      action: e,
                      paths: s
                    });
                    n(t), be({});
                  } catch (t) {
                    if (404 !== t.status) throw t;
                    await (async () => {
                      for (const t of s) {
                        const s = await i(`proposals/${encodeURIComponent(Z)}/entities/${encodeURIComponent(oe)}/selections`, {
                          path: t,
                          action: e
                        });
                        n(s), be(e => {
                          const s = {
                            ...e
                          };
                          return delete s[t], s;
                        });
                      }
                    })();
                  }
                } catch (e) {
                  ge(e.message);
                } finally {
                  Zt(!1), be({});
                }
              }, [ Z, oe ]), As = null !== (o = ps?.decisions) && void 0 !== o ? o : null, Es = null !== (r = As?.total) && void 0 !== r ? r : 0, Ms = null !== (c = As?.accepted) && void 0 !== c ? c : 0, Us = null !== (d = As?.kept) && void 0 !== d ? d : 0, Bs = null !== (u = As?.accepted_new) && void 0 !== u ? u : 0, Os = null !== (p = As?.entities_reviewed) && void 0 !== p ? p : 0, Ts = null !== (v = ps?.resolver_decisions) && void 0 !== v ? v : null, Ps = null !== (f = Ts?.reuse) && void 0 !== f ? f : 0, Fs = null !== (R = Ts?.map) && void 0 !== R ? R : 0, Vs = null !== ($ = Ts?.download) && void 0 !== $ ? $ : 0, Ls = null !== (I = Ts?.skip) && void 0 !== I ? I : 0, zs = null !== (A = null !== (E = Y?.metrics?.unresolved) && void 0 !== E ? E : ps?.resolver?.metrics?.unresolved) && void 0 !== A ? A : 0, Hs = Array.isArray(Ke?.result?.errors) ? Ke.result.errors : [], Ks = Hs.length ? "notice notice-warning" : "notice notice-success", Js = null !== (U = Ke?.result?.imported) && void 0 !== U ? U : 0, qs = null !== (B = Ke?.result?.skipped) && void 0 !== B ? B : 0, Ws = null !== (O = Ke?.result?.media_resolver?.metrics) && void 0 !== O ? O : {}, Gs = null !== (T = Ws?.unresolved) && void 0 !== T ? T : 0, Xs = null !== (P = Ke?.result?.media_reconcile) && void 0 !== P ? P : null, Zs = (0, 
              e.useCallback)(e => {
                zt({
                  focusProposalId: e
                });
                const t = (new Date).toISOString(), s = Date.now();
                it(n => [ ...n, {
                  id: s,
                  severity: "success",
                  title: "Proposal uploaded",
                  message: `Bundle registered as ${e}`,
                  timestamp: t
                } ]);
              }, [ zt ]), Qs = (0, e.useCallback)(e => {
                const t = (new Date).toISOString(), s = Date.now();
                it(n => [ ...n, {
                  id: s,
                  severity: "error",
                  title: "Upload failed",
                  message: e,
                  timestamp: t
                } ]);
              }, []);
              if (! _e && xe) {
                const loadingIndicator = t?.Spinner ? (0, s.jsx)(t.Spinner, {}) : (0, s.jsx)("span", {
                  style: {
                    width: 32,
                    height: 32,
                    display: "inline-flex",
                    alignItems: "center",
                    justifyContent: "center",
                    borderRadius: "50%",
                    background: "var(--dbvc-color-surface-muted, #f6f7f7)",
                    fontSize: 20
                  },
                  children: "…"
                });
                return (0, s.jsx)("div", {
                  className: "dbvc-admin-app dbvc-admin-app--initializing",
                  children: (0, s.jsxs)("div", {
                    className: "dbvc-admin-app__loading-panel",
                    style: {
                      minHeight: 280,
                      display: "flex",
                      flexDirection: "column",
                      alignItems: "center",
                      justifyContent: "center",
                      gap: 12,
                      textAlign: "center",
                      padding: "32px 16px"
                    },
                    children: [ loadingIndicator, (0, s.jsx)("p", {
                      style: {
                        fontSize: 18,
                        margin: 0
                      },
                      children: "Preparing proposals… please keep this tab open."
                    }), (0, s.jsx)("small", {
                      style: {
                        color: "var(--dbvc-color-text-subtle, #8c8f94)",
                        maxWidth: 420,
                        lineHeight: 1.6
                      },
                      children: "We’re refreshing proposal data and synchronizing reviewer decisions. This can take a moment after updates."
                    }) ]
                  })
                });
              }
              return !_e && Re ? (0, s.jsxs)("p", {
                className: "dbvc-admin-app-error",
                children: [ "Error loading proposals: ", o(Re) ]
              }) : (0, s.jsxs)("div", {
                className: "dbvc-admin-app",
                onClick: Kt,
                onMouseDown: Kt,
                onSubmit: Jt,
                children: [ nt.length > 0 && (0, s.jsx)("div", {
                  className: "dbvc-toasts",
                  children: nt.map(e => (0, s.jsxs)("div", {
                    className: `dbvc-toast dbvc-toast--${e.severity}`,
                    children: [ (0, s.jsxs)("div", {
                      className: "dbvc-toast__content",
                      children: [ (0, s.jsx)("strong", {
                        children: o(e.title)
                      }), (0, s.jsx)("span", {
                        children: o(e.message)
                      }), e.detail && (0, s.jsx)("small", {
                        children: o(e.detail)
                      }), (0, s.jsx)("small", {
                        className: "dbvc-toast__time",
                        children: a(e.timestamp)
                      }) ]
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: "dbvc-toast__dismiss",
                      onClick: () => js(e.id),
                      "aria-label": "Dismiss notification",
                      children: "×"
                    }) ]
                  }, e.id))
                }), (0, s.jsxs)("div", {
                  className: "dbvc-admin-app__header",
                  children: [ (0, s.jsx)("h1", {
                    children: "DBVC Proposals"
                  }), (0, s.jsx)(t.Button, {
                    variant: "secondary",
                    onClick: () => zt(),
                    disabled: xe && _e,
                    children: xe && _e ? "Refreshing…" : "Refresh list"
                  }), (0, s.jsx)(t.Button, {
                    variant: "tertiary",
                    onClick: $s,
                    disabled: ft,
                    isBusy: ft,
                    children: ft ? "Clearing…" : "Clear all backups"
                  }) ]
                }), (0, s.jsx)(C, {
                  onUploaded: Zs,
                  onError: Qs
                }), Re && (0, s.jsx)("div", {
                  className: "notice notice-error",
                  children: (0, s.jsxs)("p", {
                    children: [ "Failed to load proposals: ", o(Re) ]
                  })
                }), (0, s.jsx)(w, {
                  proposals: G,
                  selectedId: Z,
                  onSelect: Q,
                  onDelete: deleteProposal,
                  deleteBusyId: deleteBusyId
                }), ps && (0, s.jsxs)("section", {
                  className: "dbvc-admin-app__detail",
                  children: [ (0, s.jsx)("h2", {
                    children: ps.title
                  }), (0, s.jsxs)("p", {
                    className: "dbvc-admin-app__detail-meta",
                    children: [ "Generated: ", a(ps.generated_at), " · Files:", " ", null !== (F = ps.files) && void 0 !== F ? F : "—", " · Media: ", null !== (V = ps.media_items) && void 0 !== V ? V : "—", " · Status:", " ", (0, 
                    s.jsx)("strong", {
                      children: "closed" === ps.status ? "Closed" : "Open"
                    }), " ", (0, s.jsx)("span", {
                      className: "dbvc-badge dbvc-badge--resolver",
                      children: (0, s.jsx)("a", {
                        href: "#dbvc-global-resolver",
                        children: "Global rules enabled"
                      })
                    }) ]
                  }), Ee && (0, s.jsx)("div", {
                    className: "notice notice-error",
                    children: (0, s.jsxs)("p", {
                      children: [ "Resolver error: ", Ee ]
                    })
                  }), Ne ? (0, s.jsx)("p", {
                    children: "Loading resolver metrics…"
                  }) : (0, s.jsx)(N, {
                    resolver: Y
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-admin-app__actions",
                    children: [ (0, s.jsx)("button", {
                      type: "button",
                      className: "button button-primary",
                      onClick: gs,
                      disabled: Oe || "closed" === ps.status,
                      title: "closed" === ps.status ? "Reopen this proposal before applying new changes." : void 0,
                      children: Oe ? "Applying…" : "Close & Apply Proposal"
                    }), "closed" === ps.status ? (0, s.jsxs)("div", {
                      className: "dbvc-admin-app__status-note",
                      children: [ (0, s.jsx)("p", {
                        children: "This proposal has been closed. Reopen it to continue reviewing this snapshot."
                      }), (0, s.jsx)("button", {
                        type: "button",
                        className: "button",
                        onClick: () => ms("draft"),
                        disabled: Pe,
                        children: Pe ? "Reopening…" : "Reopen proposal"
                      }) ]
                    }) : (0, s.jsx)("p", {
                      className: "description",
                      children: "Applying will close this proposal snapshot. Re-export on the source site for additional changes."
                    }), Es > 0 && (0, s.jsxs)("div", {
                      className: "dbvc-decisions",
                      children: [ (0, s.jsxs)("span", {
                        className: "dbvc-badge dbvc-badge--accept",
                        children: [ Ms, " accept" ]
                      }), Bs > 0 && (0, s.jsxs)("span", {
                        className: "dbvc-badge dbvc-badge--new",
                        children: [ Bs, " new" ]
                      }), (0, s.jsxs)("span", {
                        className: "dbvc-badge dbvc-badge--keep",
                        children: [ Us, " keep" ]
                      }), (0, s.jsxs)("span", {
                        className: "dbvc-badge dbvc-badge--reviewed",
                        children: [ Os, " reviewed" ]
                      }) ]
                    }) ]
                  }), qe && (0, s.jsx)("div", {
                    className: "notice notice-error",
                    children: (0, s.jsxs)("p", {
                      children: [ "Apply failed: ", o(qe) ]
                    })
                  }), Ke && (0, s.jsxs)("div", {
                    className: Ks,
                    children: [ (0, s.jsxs)("p", {
                      children: [ 'Applied proposal "', ps?.title || Z, '". Mode: ', null !== (L = Ke.mode) && void 0 !== L ? L : "full", " · Imported ", Js, " · Skipped", " ", qs, Ke.decisions_cleared && Ke.auto_clear_enabled ? " · Reviewer decisions were cleared." : "", Gs > 0 && ` · ${Gs} resolver conflict(s) remain.` ]
                    }), Xs && (0, s.jsxs)("p", {
                      className: "dbvc-resolver-summary",
                      children: [ "Media reconciliation — created: ", null !== (z = Xs.created) && void 0 !== z ? z : 0, ", unresolved: ", null !== (H = Xs.unresolved) && void 0 !== H ? H : 0 ]
                    }), Ke.resolver_decisions && (0, s.jsxs)("p", {
                      className: "dbvc-resolver-summary",
                      children: [ "Resolver decisions applied — reuse: ", null !== (K = Ke.resolver_decisions.reuse) && void 0 !== K ? K : 0, ", map:", " ", null !== (J = Ke.resolver_decisions.map) && void 0 !== J ? J : 0, ", download: ", null !== (q = Ke.resolver_decisions.download) && void 0 !== q ? q : 0, ", skip:", " ", null !== (W = Ke.resolver_decisions.skip) && void 0 !== W ? W : 0 ]
                    }), Hs.length > 0 && (0, s.jsx)("ul", {
                      children: Hs.map((e, t) => (0, s.jsx)("li", {
                        children: o(e)
                      }, t))
                    }) ]
                  }), Ts && (0, s.jsx)("div", {
                    className: "dbvc-resolver-summary",
                    children: (0, s.jsxs)("span", {
                      children: [ "Resolver decisions — reuse: ", Ps, ", map: ", Fs, ", download:", " ", Vs, ", skip: ", Ls ]
                    })
                  }), lt.length > 0 && (0, s.jsxs)("div", {
                    className: "dbvc-apply-history",
                    children: [ (0, s.jsx)("h3", {
                      children: "Recent Apply Runs"
                    }), (0, s.jsx)("ul", {
                      children: lt.map(e => {
                        var t, n, i, l;
                        return (0, s.jsxs)("li", {
                          children: [ (0, s.jsx)("strong", {
                            children: a(e.timestamp)
                          }), " — Mode ", e.mode, " · Imported ", e.imported, " · Skipped ", e.skipped, e.errors.length ? ` · ${e.errors.length} error(s)` : "", e.decisionsCleared ? " · Selections cleared" : "", e.ignoreMissingHash ? " · Hash override" : "", e.resolverDecisions ? ` · Resolver decisions (reuse ${null !== (t = e.resolverDecisions.reuse) && void 0 !== t ? t : 0}, map ${null !== (n = e.resolverDecisions.map) && void 0 !== n ? n : 0}, download ${null !== (i = e.resolverDecisions.download) && void 0 !== i ? i : 0}, skip ${null !== (l = e.resolverDecisions.skip) && void 0 !== l ? l : 0})` : "" ]
                        }, e.id);
                      })
                    }) ]
                  }), Ge && (0, s.jsxs)(t.Modal, {
                    title: `Apply proposal "${ps?.title || Z}"`,
                    onRequestClose: xs,
                    isDismissible: !Oe,
                    shouldCloseOnClickOutside: !Oe,
                    children: [ (0, s.jsxs)("div", {
                      className: "dbvc-apply-modal__summary",
                      children: [ (0, s.jsx)("p", {
                        children: "This will run the import pipeline for the selected proposal using the chosen mode."
                      }), (0, s.jsx)("p", {
                        className: "dbvc-apply-modal__warning",
                        children: "Applying closes this proposal snapshot. To continue reviewing additional entities, export a new proposal after this step or reopen if necessary."
                      }), Es > 0 ? (0, s.jsxs)(s.Fragment, {
                        children: [ (0, s.jsx)("p", {
                          children: "Reviewer selections captured:"
                        }), (0, s.jsxs)("ul", {
                          children: [ (0, s.jsxs)("li", {
                            children: [ Ms, " field(s) marked Accept" ]
                          }), Bs > 0 && (0, s.jsxs)("li", {
                            children: [ Bs, " new entity approval(s)" ]
                          }), (0, s.jsxs)("li", {
                            children: [ Us, " field(s) kept as-is" ]
                          }), (0, s.jsxs)("li", {
                            children: [ Os, " entity row(s) touched" ]
                          }) ]
                        }), (0, s.jsx)("p", {
                          className: "description",
                          children: "Only fields marked Accept will overwrite live data. Other fields remain unchanged."
                        }) ]
                      }) : (0, s.jsx)("p", {
                        className: "dbvc-apply-modal__warning",
                        children: "No reviewer selections have been recorded. Applying now only imports entities with Accept/Keep selections or new entities you marked “Accept & import.” All other entities are skipped."
                      }) ]
                    }), (0, s.jsx)(t.RadioControl, {
                      label: "Import mode",
                      selected: Ze,
                      options: [ {
                        label: "Full import (copy entire proposal)",
                        value: "full"
                      }, {
                        label: "Partial import (skip unchanged items when hashes match)",
                        value: "partial"
                      } ],
                      onChange: e => Qe(e)
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-apply-mode-help",
                      children: [ (0, s.jsxs)("p", {
                        children: [ (0, s.jsx)("strong", {
                          children: "Full import (recommended):"
                        }), " Applies only the fields you accepted, records the close-out, and leaves untouched entities for the next export." ]
                      }), (0, s.jsxs)("p", {
                        children: [ (0, s.jsx)("strong", {
                          children: "Partial import (legacy):"
                        }), " Only needed for old proposals missing import hashes. Behavior otherwise matches full import." ]
                      }) ]
                    }), "partial" === Ze && (0, s.jsx)(t.CheckboxControl, {
                      label: "Ignore missing import hash validation",
                      checked: Ye,
                      onChange: e => et(e),
                      help: "Use only for legacy backups that predate import hash support. Recommended to resolve hash mismatches when possible."
                    }), (0, s.jsx)("p", {
                      className: "description",
                      children: "Selections will be cleared automatically after a successful apply if the auto-clear setting is enabled in Configure → Import."
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-apply-modal__footer",
                      children: [ (0, s.jsx)(t.Button, {
                        variant: "secondary",
                        onClick: xs,
                        disabled: Oe,
                        children: "Cancel"
                      }), (0, s.jsx)(t.Button, {
                        variant: "primary",
                        onClick: _s,
                        isBusy: Oe,
                        disabled: Oe,
                        children: "Close & Apply Proposal"
                      }) ]
                    }) ]
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-entities-header",
                    children: [ (0, s.jsx)("h3", {
                      children: "Entities"
                    }), wt && (0, s.jsx)("span", {
                      className: "dbvc-duplicates-loading",
                      children: "Checking duplicates…"
                    }) ]
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-entity-summary",
                    children: [ (0, s.jsxs)("div", {
                      className: "dbvc-entity-summary__block",
                      children: [ (0, s.jsx)("strong", {
                        children: "Total Proposed Entities"
                      }), (0, s.jsxs)("div", {
                        className: "dbvc-entity-summary__counts",
                        children: [ (0, s.jsxs)("span", {
                          children: [ "Total: ", summaryCounts.proposed.total ]
                        }), (0, s.jsxs)("span", {
                          children: [ "Posts: ", summaryCounts.proposed.posts ]
                        }), (0, s.jsxs)("span", {
                          children: [ "Terms: ", summaryCounts.proposed.terms ]
                        }), (0, s.jsxs)("span", {
                          children: [ "Media: ", proposedMediaTotal ]
                        }) ]
                      }) ]
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-entity-summary__block",
                      children: [ (0, s.jsx)("strong", {
                        children: "Total Current Entities"
                      }), (0, s.jsxs)("div", {
                        className: "dbvc-entity-summary__counts",
                        children: [ (0, s.jsxs)("span", {
                          children: [ "Total: ", summaryCounts.current.total ]
                        }), (0, s.jsxs)("span", {
                          children: [ "Posts: ", summaryCounts.current.posts ]
                        }), (0, s.jsxs)("span", {
                          children: [ "Terms: ", summaryCounts.current.terms ]
                        }), (0, s.jsxs)("span", {
                          children: [ "Media: ", currentMediaTotal ]
                        }) ]
                      }) ]
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-entity-summary__block",
                      children: [ (0, s.jsx)("strong", {
                        children: "Filtered Results"
                      }), (0, s.jsxs)("div", {
                        className: "dbvc-entity-summary__counts",
                        children: [ (0, s.jsxs)("span", {
                          children: [ "Showing: ", filteredTotal ]
                        }), allEntitiesLoading && (0, s.jsx)("span", {
                          children: "Updating totals…"
                        }) ]
                      }) ]
                    }) ]
                  }), Nt && (0, s.jsx)("div", {
                    className: "notice notice-error",
                    children: (0, s.jsxs)("p", {
                      children: [ "Failed to load duplicate summary: ", Nt ]
                    })
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-admin-app__filters",
                  children: [ (0, s.jsxs)("label", {
                    children: [ "Show: ", (0, s.jsxs)("select", {
                      value: ne,
                      onChange: e => {
                        ie(e.target.value);
                        },
                        children: [ (0, s.jsx)("option", {
                          value: "all",
                          children: m.all
                        }), (0, s.jsx)("option", {
                          value: "needs_review",
                          children: m.needs_review
                        }), (0, s.jsx)("option", {
                          value: "needs_review_media",
                          children: m.needs_review_media
                        }), (0, s.jsx)("option", {
                          value: "resolved",
                          children: m.resolved
                        }), (0, s.jsx)("option", {
                          value: "new_entities",
                          children: m.new_entities
                        }), (0, s.jsx)("option", {
                          value: "with_decisions",
                          children: m.with_decisions
                        }), (0, s.jsx)("option", {
                          value: "uid_mismatch",
                          children: m.uid_mismatch
                        }) ]
                      }) ]
                    }), (0, s.jsxs)("label", {
                      children: [ " Search: ", (0, s.jsx)("input", {
                        type: "search",
                        value: le,
                        onChange: e => {
                          ae(e.target.value);
                        },
                      placeholder: "Title, type, path…"
                    }) ]
                  }) ]
                }), (0, s.jsxs)("div", {
                  className: "dbvc-entity-badges-row",
                  children: [ (0, s.jsx)("div", {
                    className: "dbvc-entity-status-badges",
                    children: statusBadges.map(e => (0, s.jsxs)("button", {
                      type: "button",
                      className: `dbvc-status-badge${ne === e.filter ? " is-active" : ""}`,
                      disabled: 0 === e.count,
                      "aria-pressed": ne === e.filter,
                      onClick: () => ie(ne === e.filter ? "all" : e.filter),
                      children: [ (0, s.jsx)("span", {
                        children: e.label
                      }), (0, s.jsx)("strong", {
                        children: e.count
                      }) ]
                    }, e.id))
                  }) ]
                }), (0, s.jsx)("div", {
                  className: "dbvc-entity-tools-row",
                  children: [ Ot.size > 0 || te.length > Ot.size ? (0, s.jsxs)("div", {
                    className: "dbvc-selection-controls",
                    children: [ te.length > Ot.size ? (0, s.jsxs)(t.Button, {
                      variant: "secondary",
                      className: "dbvc-selection-controls__button",
                      onClick: ts,
                      disabled: 0 === te.length,
                      children: [ (0, s.jsx)("span", {
                        className: "dashicons dashicons-yes",
                        "aria-hidden": "true"
                      }), (0, s.jsxs)("span", {
                        children: [ "Select all (", te.length, ")" ]
                      }) ]
                    }) : null, Ot.size > 0 ? (0, s.jsxs)(t.Button, {
                      variant: "secondary",
                      className: "dbvc-selection-controls__button",
                      onClick: es,
                      children: [ (0, s.jsx)("span", {
                        className: "dashicons dashicons-dismiss",
                        "aria-hidden": "true"
                      }), (0, s.jsxs)("span", {
                        children: [ "Clear selection (", Ot.size, ")" ]
                      }) ]
                    }) : null ]
                  }) : null, (0, s.jsx)("div", {
                    className: `dbvc-filter-toggle${filterToggleOpen ? " is-open" : ""}`,
                    ref: filterToggleRef,
                    onMouseLeave: () => setFilterToggleOpen(!1),
                    children: (0, s.jsxs)(s.Fragment, {
                      children: [ (0, s.jsx)(TooltipWrapper, {
                        content: "Filter entities by type or UID mismatch.",
                        children: (0, s.jsxs)(t.Button, {
                          variant: "secondary",
                          className: "dbvc-filter-toggle__button",
                          onClick: () => setFilterToggleOpen(e => !e),
                          "aria-haspopup": "true",
                          "aria-expanded": filterToggleOpen,
                          "aria-controls": "dbvc-filter-toggle-menu",
                          children: [ (0, s.jsx)("span", {
                            className: "dashicons dashicons-filter",
                            "aria-hidden": "true"
                          }), (0, s.jsx)("span", {
                            className: "dbvc-filter-toggle__button-label",
                            children: "Filters"
                          }), filterActiveCount > 0 && (0, s.jsx)("span", {
                            className: "dbvc-filter-toggle__count",
                            children: filterActiveCount
                          }), (0, s.jsx)("span", {
                            className: "screen-reader-text",
                            children: "Toggle filter menu"
                          }) ]
                        })
                      }), filterToggleOpen && (0, s.jsxs)("div", {
                        id: "dbvc-filter-toggle-menu",
                        className: "dbvc-filter-toggle__menu",
                        role: "menu",
                        children: [ (0, s.jsxs)("div", {
                          className: "dbvc-filter-toggle__group",
                          children: [ (0, s.jsx)("strong", {
                            children: "Object type"
                          }), objectTypeOptions.length ? (0, s.jsx)("div", {
                            className: "dbvc-filter-toggle__options",
                            children: objectTypeOptions.map(e => (0, s.jsxs)("label", {
                              className: "dbvc-filter-toggle__option",
                              children: [ (0, s.jsx)("input", {
                                type: "checkbox",
                                checked: objectTypeFilters.includes(e),
                                onChange: () => toggleObjectTypeFilter(e)
                              }), (0, s.jsx)("span", {
                                children: e
                              }) ]
                            }, e))
                          }) : (0, s.jsx)("span", {
                            className: "dbvc-filter-toggle__empty",
                            children: "No object types available."
                          }) ]
                        }), (0, s.jsxs)("div", {
                          className: "dbvc-filter-toggle__group",
                          children: [ (0, s.jsx)("strong", {
                            children: "vf_object_uid"
                          }), (0, s.jsxs)("label", {
                            className: "dbvc-filter-toggle__option",
                            children: [ (0, s.jsx)("input", {
                              type: "checkbox",
                              checked: uidMismatchFilter,
                              onChange: e => setUidMismatchFilter(e.target.checked)
                            }), (0, s.jsx)("span", {
                              children: "Mismatch only"
                            }) ]
                          }) ]
                        }), (0, s.jsx)("div", {
                          className: "dbvc-filter-toggle__actions",
                          children: (0, s.jsx)(t.Button, {
                            variant: "secondary",
                            onClick: clearEntityFilters,
                            disabled: 0 === filterActiveCount,
                            children: "Clear filters"
                          })
                        }) ]
                      }) ]
                    })
                  }), (0, s.jsx)("div", {
                    className: `dbvc-column-toggle${columnToggleOpen ? " is-open" : ""}`,
                    ref: columnToggleRef,
                    onMouseEnter: () => setColumnToggleOpen(!0),
                    onMouseLeave: () => setColumnToggleOpen(!1),
                    children: (0, s.jsxs)(s.Fragment, {
                      children: [ (0, s.jsx)(TooltipWrapper, {
                        content: "Choose which columns are visible in the All Entities table.",
                        children: (0, s.jsxs)(t.Button, {
                          variant: "secondary",
                          className: "dbvc-column-toggle__button",
                          onClick: () => setColumnToggleOpen(e => !e),
                          "aria-haspopup": "true",
                          "aria-expanded": columnToggleOpen,
                          "aria-controls": "dbvc-column-toggle-menu",
                          children: [ (0, s.jsx)("span", {
                            className: "dashicons dashicons-screenoptions",
                            "aria-hidden": "true"
                          }), (0, s.jsx)("span", {
                            className: "dbvc-column-toggle__button-label",
                            children: "Columns"
                          }), (0, s.jsx)("span", {
                            className: "screen-reader-text",
                            children: "Toggle column visibility menu"
                          }) ]
                        })
                      }), columnToggleOpen && (0, s.jsx)("div", {
                        id: "dbvc-column-toggle-menu",
                        className: "dbvc-column-toggle__menu",
                        role: "menu",
                        children: y.map(e => (0, s.jsxs)("label", {
                          className: "dbvc-column-toggle__option",
                          children: [ (0, s.jsx)("input", {
                            type: "checkbox",
                            checked: xt[e.id],
                            onChange: () => Ft(e.id),
                            disabled: e.lockVisible
                          }), (0, s.jsx)("span", {
                            children: e.label
                          }) ]
                        }, e.id))
                      }) ]
                    })
                  }), ps ? (0, s.jsxs)("div", {
                    className: `dbvc-actions-tray${actionsTrayOpen ? " is-open" : ""}${maskAttention ? " has-attention" : ""}`,
                    ref: actionsTrayRef,
                    children: [ (0, s.jsx)(TooltipWrapper, {
                      content: "Open Accept/Keep actions, snapshots, and masking tools.",
                      children: (0, s.jsxs)(t.Button, {
                        variant: "secondary",
                        className: "dbvc-actions-tray__button",
                        onClick: () => setActionsTrayOpen(e => {
                          const t = !e;
                          return t && setMaskAttention(!1), t;
                        }),
                        "aria-expanded": actionsTrayOpen,
                        "aria-haspopup": "true",
                        children: [ (0, s.jsx)("span", {
                          className: "dashicons dashicons-admin-tools",
                          "aria-hidden": "true"
                        }), (0, s.jsx)("span", {
                          children: "Actions & tools"
                        }), (0, s.jsx)("span", {
                          className: "screen-reader-text",
                          children: "Toggle bulk actions tray"
                        }) ]
                      })
                    }), actionsTrayOpen && (0, s.jsxs)("div", {
                      className: "dbvc-actions-tray__panel",
                      children: [ (0, s.jsxs)("div", {
                        className: "dbvc-actions-tray__section",
                        children: [ (0, s.jsxs)("div", {
                          className: "dbvc-actions-tray__section-heading",
                          children: [ (0, s.jsx)("strong", {
                            children: "Selected entities"
                          }), (0, s.jsx)("p", {
                            children: "Bulk Accept/Keep controls for the entities you checked in the table."
                          }) ]
                        }), (0, s.jsxs)("div", {
                          className: "dbvc-actions-tray__items",
                          children: [ (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              className: "dbvc-new-entities-accept-button",
                              variant: "primary",
                              onClick: ls,
                              disabled: Et || 0 === Ot.size,
                              isBusy: Et,
                              children: Et ? "Accepting selected…" : Ot.size > 0 ? `Accept ${Ot.size} selected` : "Accept selected"
                            }), (0, s.jsx)("p", {
                              children: Ot.size > 0 ? "Apply the proposal values to every selected entity." : "Select one or more entities to enable bulk Accept."
                            }) ]
                          }), (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              variant: "secondary",
                              onClick: as,
                              disabled: Ut || 0 === Ot.size,
                              isBusy: Ut,
                              children: Ut ? "Unaccepting…" : "Unaccept selected"
                            }), (0, s.jsx)("p", {
                              children: "Clear Accept decisions for every selected entity."
                            }) ]
                          }), (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              variant: "secondary",
                              onClick: unkeepSelected,
                              disabled: unkeepBusy || 0 === Ot.size,
                              isBusy: unkeepBusy,
                              children: unkeepBusy ? "Unkeeping…" : "Unkeep selected"
                            }), (0, s.jsx)("p", {
                              children: "Remove Keep decisions so fields return to Needs Review."
                            }) ]
                          }) ]
                        }) ]
                      }), (0, s.jsxs)("div", {
                        className: "dbvc-actions-tray__section",
                        children: [ (0, s.jsxs)("div", {
                          className: "dbvc-actions-tray__section-heading",
                          children: [ (0, s.jsx)("strong", {
                            children: "New entities"
                          }), (0, s.jsx)("p", {
                            children: "Decide what to do with content that doesn’t exist locally yet."
                          }) ]
                        }), (0, s.jsxs)("div", {
                          className: "dbvc-actions-tray__items",
                          children: [ (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              className: "dbvc-new-entities-accept-button",
                              variant: "primary",
                              onClick: is,
                              disabled: It || !ns,
                              isBusy: It,
                              children: It ? "Accepting…" : `Accept all new entities (${ss.length})`
                            }), (0, s.jsx)("p", {
                              children: "Import every pending new post or term with one click."
                            }) ]
                          }), (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              className: "dbvc-new-entities-button",
                              variant: "secondary",
                              onClick: () => {
                                ie("new_entities"), ae("");
                              },
                              children: `Review ${ss.length} new ${1 === ss.length ? "entity" : "entities"}`
                            }), (0, s.jsx)("p", {
                              children: "Open the New entities filter to inspect each diff."
                            }) ]
                          }) ]
                        }) ]
                      }), (0, s.jsxs)("div", {
                        className: "dbvc-actions-tray__section",
                        children: [ (0, s.jsxs)("div", {
                          className: "dbvc-actions-tray__section-heading",
                          children: [ (0, s.jsx)("strong", {
                            children: "Snapshots & maintenance"
                          }), (0, s.jsx)("p", {
                            children: "Keep proposal data in sync before you apply decisions."
                          }) ]
                        }), (0, s.jsxs)("div", {
                          className: "dbvc-actions-tray__items",
                          children: [ (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              variant: "secondary",
                              onClick: refreshEntities,
                              disabled: we,
                              isBusy: we,
                              children: we ? "Refreshing…" : "Refresh entities"
                            }), (0, s.jsx)("p", {
                              children: "Reload entity rows, selections, and resolver stats."
                            }) ]
                          }), (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              variant: "secondary",
                              onClick: Rs,
                              disabled: vt,
                              isBusy: vt,
                              children: vt ? "Capturing snapshots…" : "Capture full snapshot"
                            }), (0, s.jsx)("p", {
                              children: zs > 0 ? `Capture JSON snapshots for ${zs} unresolved entity ${1 === zs ? "diff" : "diffs"}.` : "Capture fresh JSON snapshots for every entity."
                            }) ]
                          }), us.length > 0 && (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              variant: "secondary",
                              onClick: vs,
                              disabled: Ve && "bulk" === ze,
                              isBusy: Ve && "bulk" === ze,
                              children: Ve && "bulk" === ze ? "Storing hashes…" : `Store hashes (${us.length})`
                            }), (0, s.jsx)("p", {
                              children: "Sync import hashes for entities missing their stored value."
                            }) ]
                          }), (0, s.jsxs)("div", {
                            className: "dbvc-actions-tray__item",
                            children: [ (0, s.jsx)(t.Button, {
                              variant: "primary",
                              className: "dbvc-duplicates-button",
                              onClick: () => Dt(!0),
                              disabled: 0 === _t.count,
                              children: _t.count > 0 ? `Resolve ${_t.count} duplicate${1 === _t.count ? "" : "s"}` : "No duplicates detected"
                            }), (0, s.jsx)("p", {
                              children: _t.count > 0 ? "Launch the duplicate modal to pick a canonical entity before continuing." : "All manifest entries look unique."
                            }) ]
                          }) ]
                        }) ]
                      }), (0, s.jsxs)("div", {
                        className: "dbvc-actions-tray__section dbvc-actions-tray__section--masking",
                        children: [ (0, s.jsxs)("div", {
                          className: "dbvc-tools-panel__section dbvc-tools-panel__section--masking",
                          children: [ (0, s.jsxs)("div", {
                            className: "dbvc-tools-panel__section-heading",
                            children: [ (0, s.jsx)("strong", {
                              children: "Meta masking"
                            }), (maskLoading || maskApplying) && (0, s.jsxs)("span", {
                              className: "dbvc-mask-progress",
                              children: [ maskLoading ? maskProgress : maskApplyProgress, maskLoading ? "% loaded" : "% applied" ]
                            }), (0, s.jsx)("a", {
                              href: maskDocLink("live-proposal-masking"),
                              target: "_blank",
                              rel: "noreferrer",
                              children: "Masking guide ↗"
                            }), pendingMaskUndo && (0, s.jsx)(t.Button, {
                              variant: "tertiary",
                              onClick: undoMasking,
                              disabled: maskApplying,
                              children: maskApplying ? "Reverting…" : "Undo last masking"
                            }) ]
                          }), maskError && (0, s.jsx)("div", {
                            className: "notice notice-error",
                            children: (0, s.jsxs)("p", {
                              children: [ "Masking error: ", maskError ]
                            })
                          }), maskLoading ? (0, s.jsx)("p", {
                            children: "Loading masking rules…"
                          }) : maskFieldCount > 0 ? (0, s.jsxs)(s.Fragment, {
                            children: [ (0, s.jsxs)("p", {
                              children: [ "Found ", maskFieldCount, " masked field", 1 === maskFieldCount ? "" : "s", " across ", maskEntityCount, " entit", 1 === maskEntityCount ? "y" : "ies", ". Choose how to handle them." ]
                            }), t?.SelectControl ? (0, s.jsx)(t.SelectControl, {
                              label: "Bulk action",
                              value: maskBulkAction,
                              options: maskActionOptions,
                              onChange: e => setMaskBulkAction(e)
                            }) : (0, s.jsxs)("label", {
                              children: [ "Bulk action", (0, s.jsxs)("select", {
                                value: maskBulkAction,
                                onChange: e => setMaskBulkAction(e.target.value),
                                children: maskActionOptions.map(e => (0, s.jsx)("option", {
                                  value: e.value,
                                  children: e.label
                                }, e.value))
                              }) ]
                            }), "override" === maskBulkAction && (0, s.jsxs)("div", {
                              className: "dbvc-mask-field__override",
                              children: [ t?.TextareaControl ? (0, s.jsx)(t.TextareaControl, {
                                label: "Override value",
                                value: maskBulkOverride,
                                onChange: e => setMaskBulkOverride(e),
                                rows: 3
                              }) : (0, s.jsxs)("label", {
                                children: [ "Override value", (0, s.jsx)("textarea", {
                                  value: maskBulkOverride,
                                  onChange: e => setMaskBulkOverride(e.target.value)
                                }) ]
                              }), t?.TextControl ? (0, s.jsx)(t.TextControl, {
                                label: "Note (optional)",
                                value: maskBulkNote,
                                onChange: e => setMaskBulkNote(e)
                              }) : (0, s.jsxs)("label", {
                                children: [ "Note (optional)", (0, s.jsx)("input", {
                                  type: "text",
                                  value: maskBulkNote,
                                  onChange: e => setMaskBulkNote(e.target.value)
                                }) ]
                              }) ]
                            }), (0, s.jsxs)("div", {
                              className: "dbvc-mask-actions",
                              children: [ (0, s.jsx)(TooltipWrapper, {
                                content: maskTooltips.apply,
                                children: (0, s.jsx)(t.Button, {
                                  variant: "primary",
                                  onClick: applyMasking,
                                  disabled: maskApplying || maskLoading || !maskFieldCount,
                                  isBusy: maskApplying,
                                  children: maskApplying ? "Applying…" : "Apply masking rules"
                                })
                              }), (0, s.jsx)(TooltipWrapper, {
                                content: maskTooltips.revert,
                                children: (0, s.jsx)(t.Button, {
                                  variant: "secondary",
                                  onClick: revertMasking,
                                  disabled: maskReverting || maskApplying || maskLoading,
                                  isBusy: maskReverting,
                                  children: maskReverting ? "Reverting…" : "Revert masking decisions"
                                })
                              }), (0, s.jsx)(t.Button, {
                                variant: "tertiary",
                                onClick: () => Z && loadMasking(Z),
                                disabled: maskLoading,
                                isBusy: maskLoading,
                                children: maskLoading ? "Refreshing…" : "Refresh list"
                              }) ]
                            }) ]
                          }) : (0, s.jsxs)("p", {
                            children: [ "No masked fields pending in this proposal. Learn more in the ", (0, s.jsx)("a", {
                              href: maskDocLink("live-proposal-masking"),
                              target: "_blank",
                              rel: "noreferrer",
                              children: "masking reference"
                            }), "." ]
                          }) ]
                        }) ]
                      }) ]
                    }) ]
                  }) : null ]
                }),

              Ie && (0, s.jsx)("div", {
                className: "notice notice-error",
                children: (0, s.jsxs)("p", {
                  children: [ "Failed to load entities: ", Ie ]
                })
              }), (0, s.jsxs)("div", {
                    className: "dbvc-entity-table-wrapper",
                    children: [ _t.count > 0 && (0, s.jsx)("button", {
                      type: "button",
                      className: "dbvc-duplicates-overlay",
                      onClick: () => Dt(!0),
                      title: "Resolve duplicate manifest entries before continuing.",
                      children: "Duplicate manifest entries detected — resolve these first"
                    }), (0, s.jsx)(k, {
                      entities: cs,
                      loading: we,
                      selectedEntityId: oe,
                      onSelect: hs,
                      columns: Pt,
                      selectedIds: Ot,
                      onToggleSelection: Qt,
                      onToggleSelectionAll: Yt
                    }) ]
                  }), (0, s.jsx)(M, {}), (0, s.jsx)(S, {
                    entityDetail: ce,
                    resolverInfo: ds?.resolver,
                    resolverDecisionSummary: ps?.resolver_decisions,
                    decisions: he,
                    onDecisionChange: ys,
                    onBulkDecision: Is,
                    onResetDecisions: Ss,
                    onCaptureSnapshot: Ds,
                    onResolverDecision: Cs,
                    onResolverDecisionReset: Ns,
                    onApplyResolverDecisionToSimilar: ks,
                    savingPaths: ve,
                    bulkSaving: Xt,
                    resolverSaving: ot,
                    resolverError: ct,
                    decisionError: fe,
                    loading: Se,
                    error: Ue,
                    filterMode: tt,
                    onFilterModeChange: st,
                    isOpen: ue,
                    onClose: fs,
                    resettingDecisions: ut,
                    snapshotCapturing: ht,
                    onHashSync: bs,
                    hashSyncing: Ve,
                    hashSyncTarget: ze,
                    maskFieldsByEntity: maskFieldsByEntity
                  }), (0, s.jsx)(D, {
                    open: St,
                    onClose: () => Dt(!1),
                    onMarkCanonical: Gt,
                    actionKey: duplicateActionKey,
                    report: _t,
                    bulkMode: duplicateMode,
                    onBulkModeChange: setDuplicateMode,
                    confirmPhrase: duplicateConfirmPhrase,
                    confirmValue: duplicateConfirm,
                    onConfirmChange: setDuplicateConfirm,
                    onBulkCleanup: bulkDuplicateCleanup,
                    bulkBusy: duplicateBulkBusy,
                    bulkDisabled: bulkCleanupDisabled
                  }) ]
                }) ]
              });
            };
            class ErrorBoundary extends e.Component {
              constructor(e) {
                super(e), this.state = {
                  hasError: !1,
                  error: null
                };
              }
              static getDerivedStateFromError(e) {
                return {
                  hasError: !0,
                  error: e
                };
              }
              componentDidCatch(e, t) {
                this.props.onError && this.props.onError(e, t);
              }
              render() {
                if (this.state.hasError) return (0, s.jsxs)("div", {
                  className: "dbvc-admin-app dbvc-admin-app--crashed",
                  children: [ (0, s.jsx)("h2", {
                    children: "DBVC Proposals encountered an error"
                  }), (0, s.jsxs)("div", {
                    className: "notice notice-error",
                    children: [ (0, s.jsx)("p", {
                      children: "The interface hit an unexpected error. You can reload this page to try again."
                    }), (0, s.jsx)("p", {
                      children: (0, s.jsx)("button", {
                        type: "button",
                        className: "button",
                        onClick: () => window.location.reload(),
                        children: "Reload"
                      })
                    }) ]
                  }), this.state.error && (0, s.jsxs)("p", {
                    className: "dbvc-admin-app__error-detail",
                    children: [ "Error: ", o(this.state.error.message || this.state.error) ]
                  }) ]
                });
                return this.props.children;
              }
            }
            const logClientError = (e, t) => {
              try {
                const s = {
                  message: e && e.message ? e.message : String(e),
                  stack: e && e.stack ? e.stack : null,
                  componentStack: t && t.componentStack ? t.componentStack : null,
                  path: window.location && window.location.href ? window.location.href : null,
                  context: "admin-app"
                };
                void i("logs/client", s);
              } catch (e) {}
            }, $ = () => {
              const t = document.getElementById("dbvc-admin-app-root");
              if (!t) return;
              const n = e.createRoot ? e.createRoot(t) : null;
              const l = (0, s.jsx)(ErrorBoundary, {
                onError: logClientError,
                children: (0, s.jsx)(R, {})
              });
              n ? n.render(l) : (0, e.render)(l, t);
            };
            "loading" === document.readyState ? document.addEventListener("DOMContentLoaded", $) : $();
            const I = ({section: t, decisions: n, onDecisionChange: i, savingPaths: l}) => {
              const [a, d] = (0, e.useState)(!0);
              return (0, s.jsxs)("div", {
                className: "dbvc-admin-app__diff-section",
                id: `dbvc-diff-${t.key}`,
                children: [ (0, s.jsxs)("button", {
                  type: "button",
                  className: "dbvc-admin-app__diff-toggle",
                  onClick: () => d(e => !e),
                  "aria-expanded": a,
                  children: [ (0, s.jsx)("h4", {
                    children: t.label
                  }), (0, s.jsx)("span", {
                    children: a ? "Collapse" : "Expand"
                  }) ]
                }), a ? (0, s.jsxs)("table", {
                  className: "widefat dbvc-admin-app__diff-table",
                  children: [ (0, s.jsx)("thead", {
                    children: (0, s.jsxs)("tr", {
                      children: [ (0, s.jsx)("th", {
                        style: {
                          width: "25%"
                        },
                        children: "Field"
                      }), (0, s.jsx)("th", {
                        children: "Current"
                      }), (0, s.jsx)("th", {
                        children: "Proposed"
                      }), (0, s.jsx)("th", {
                        style: {
                          width: "20%"
                        },
                        children: "Decision"
                      }) ]
                    })
                  }), (0, s.jsx)("tbody", {
                    children: t.items.map(e => {
                      var t;
                      const a = ((e, t) => {
                        const s = r(e), n = r(t);
                        if (s === n) return null;
                        if (s.length > 5e3 || n.length > 5e3) return null;
                        const i = Math.min(s.length, n.length);
                        let l = 0;
                        for (;l < i && s[l] === n[l]; ) l++;
                        let a = s.length - 1, o = n.length - 1;
                        for (;a >= l && o >= l && s[a] === n[o]; ) a--, o--;
                        return {
                          old: {
                            before: s.slice(0, l),
                            diff: s.slice(l, a + 1),
                            after: s.slice(a + 1)
                          },
                          new: {
                            before: n.slice(0, l),
                            diff: n.slice(l, o + 1),
                            after: n.slice(o + 1)
                          }
                        };
                      })(e.from, e.to), d = null !== (t = n?.[e.path]) && void 0 !== t ? t : "", u = !!l[e.path], m = !!e.is_equal, p = [ "dbvc-diff-row" ];
                      (isThenable(e.from) || isThenable(e.to)) && logAsyncValue(n?.item?.vf_object_uid || n?.vf_object_uid || "", e.path, isThenable(e.from) ? e.from : e.to);
                      return m ? p.push("is-equal") : "accept" === d ? p.push("is-accepted") : "keep" === d ? p.push("is-kept") : p.push("is-unreviewed"), 
                      (0, s.jsxs)("tr", {
                        className: p.join(" "),
                        children: [ (0, s.jsx)("td", {
                          children: (0, s.jsxs)("div", {
                            className: "dbvc-field-label",
                            children: [ o(e.label || e.path), e.path && (0, s.jsx)("div", {
                              className: "dbvc-field-label__key",
                              children: o(e.path)
                            }) ]
                          })
                        }), (0, s.jsx)("td", {
                          children: a && a.old.diff ? c(a.old) : o(e.from)
                        }), (0, s.jsx)("td", {
                          children: a && a.new.diff ? c(a.new) : o(e.to)
                        }), (0, s.jsxs)("td", {
                          children: [ m ? (0, s.jsx)("em", {
                            children: "No difference"
                          }) : (0, s.jsxs)("div", {
                            className: "dbvc-decision-controls",
                            children: [ (0, s.jsxs)("div", {
                              className: "dbvc-decision-options",
                              children: [ (0, s.jsxs)("label", {
                                children: [ (0, s.jsx)("input", {
                                  type: "radio",
                                  name: `decision-${e.path}`,
                                  value: "keep",
                                  checked: "keep" === d,
                                  disabled: u,
                                  onChange: () => i && i(e.path, "keep")
                                }), "Keep" ]
                              }), (0, s.jsxs)("label", {
                                children: [ (0, s.jsx)("input", {
                                  type: "radio",
                                  name: `decision-${e.path}`,
                                  value: "accept",
                                  checked: "accept" === d,
                                  disabled: u,
                                  onChange: () => i && i(e.path, "accept")
                                }), "Accept" ]
                              }) ]
                            }), (0, s.jsx)("button", {
                              type: "button",
                              className: "button-link dbvc-decision-clear",
                              onClick: () => i && i(e.path, "clear"),
                              disabled: u || !d,
                              children: "Clear decision"
                            }) ]
                          }), !m && (0, s.jsxs)("div", {
                            className: "dbvc-decision-state",
                            children: [ (0, s.jsx)("span", {
                              className: "dbvc-decision-status",
                              children: "accept" === d ? "Accepted" : "keep" === d ? "Kept" : "Not reviewed"
                            }), u && (0, s.jsx)("span", {
                              className: "saving",
                              children: "Saving…"
                            }) ]
                          }) ]
                        }) ]
                      }, e.path);
                    })
                  }) ]
                }) : (0, s.jsxs)("div", {
                  className: "dbvc-admin-app__no-diff",
                  children: [ "Collapsed (", t.items.length, " changes)." ]
                }) ]
              });
            }, A = ({src: e, label: t}) => (0, s.jsxs)("div", {
              className: "dbvc-resolver-inline-preview",
              children: [ e ? (0, s.jsx)("img", {
                src: e,
                alt: "",
                className: "dbvc-resolver-inline-preview__image",
                loading: "lazy"
              }) : (0, s.jsx)("span", {
                className: "dbvc-resolver-inline-preview__placeholder",
                children: "—"
              }), (0, s.jsx)("span", {
                className: "dbvc-resolver-inline-preview__label",
                children: null != t ? t : "—"
              }) ]
            }), E = ({attachment: t, saving: n, onSave: i, onClear: l, onApplyToSimilar: a}) => {
              var o, r;
              const c = t.original_id, [d, u] = (0, e.useState)(t.decision?.action || ""), [p, h] = (0, 
              e.useState)(t.decision?.target_id ? String(t.decision.target_id) : ""), [m, b] = (0, 
              e.useState)(t.decision?.note || ""), [f, g] = (0, e.useState)("global" === t.decision?.scope);
              (0, e.useEffect)(() => {
                u(t.decision?.action || ""), h(t.decision?.target_id ? String(t.decision.target_id) : ""), 
                b(t.decision?.note || ""), g("global" === t.decision?.scope);
              }, [ t.decision ]);
              const x = "reuse" === d || "map" === d, j = parseInt(p, 10), _ = d && (!x || j > 0);
              return (0, s.jsxs)("tr", {
                children: [ (0, s.jsx)("td", {
                  children: v(t.status || "unknown")
                }), (0, s.jsx)("td", {
                  children: (0, s.jsx)(A, {
                    src: t.preview?.proposed,
                    label: c
                  })
                }), (0, s.jsx)("td", {
                  children: (0, s.jsx)(A, {
                    src: t.preview?.local,
                    label: null !== (o = t.target_id) && void 0 !== o ? o : "—"
                  })
                }), (0, s.jsx)("td", {
                  children: null !== (r = t.reason) && void 0 !== r ? r : "—"
                }), (0, s.jsx)("td", {
                  children: (0, s.jsxs)("div", {
                    className: "dbvc-resolver-controls dbvc-resolver-controls--decision",
                    children: [ (0, s.jsxs)("div", {
                      className: "dbvc-resolver-control",
                      children: [ (0, s.jsx)("span", {
                        className: "dbvc-resolver-control__label",
                        children: "Decision"
                      }), (0, s.jsxs)("select", {
                        value: d,
                        onChange: e => u(e.target.value),
                        children: [ (0, s.jsx)("option", {
                          value: "",
                          children: "Select…"
                        }), (0, s.jsx)("option", {
                          value: "reuse",
                          children: "Reuse existing"
                        }), (0, s.jsx)("option", {
                          value: "download",
                          children: "Download new"
                        }), (0, s.jsx)("option", {
                          value: "map",
                          children: "Map to attachment ID"
                        }), (0, s.jsx)("option", {
                          value: "skip",
                          children: "Skip"
                        }) ]
                      }) ]
                    }), x && (0, s.jsxs)("div", {
                      className: "dbvc-resolver-control",
                      children: [ (0, s.jsx)("span", {
                        className: "dbvc-resolver-control__label",
                        children: "Target attachment"
                      }), (0, s.jsx)("input", {
                        type: "number",
                        min: "1",
                        placeholder: "Attachment ID",
                        value: p,
                        onChange: e => h(e.target.value)
                      }), (0, s.jsx)("span", {
                        className: "dbvc-field-hint",
                        children: "Provide an attachment ID for reuse/map actions."
                      }) ]
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-resolver-control dbvc-resolver-control--note",
                      children: [ (0, s.jsx)("span", {
                        className: "dbvc-resolver-control__label",
                        children: "Reviewer note"
                      }), (0, s.jsx)("textarea", {
                        value: m,
                        onChange: e => b(e.target.value),
                        placeholder: "Optional note",
                        rows: 2
                      }) ]
                    }), (0, s.jsxs)("label", {
                      className: "dbvc-resolver-remember",
                      children: [ (0, s.jsxs)("span", {
                        className: "dbvc-toggle",
                        children: [ (0, s.jsx)("input", {
                          type: "checkbox",
                          className: "dbvc-toggle__input",
                          checked: f,
                          onChange: e => g(e.target.checked)
                        }), (0, s.jsx)("span", {
                          className: "dbvc-toggle__track",
                          "aria-hidden": "true"
                        }) ]
                      }), (0, s.jsx)("span", {
                        className: "dbvc-toggle__label",
                        children: "Remember for future proposals"
                      }) ]
                    }) ]
                  })
                }), (0, s.jsxs)("td", {
                  children: [ (0, s.jsx)("button", {
                    type: "button",
                    className: "button button-primary",
                    disabled: !_ || n,
                    onClick: () => i && i(c, {
                      action: d,
                      target_id: x ? j : null,
                      note: m,
                      persist_global: f
                    }),
                    children: n ? "Saving…" : "Save"
                  }), t.decision && (0, s.jsx)("button", {
                    type: "button",
                    className: "button-link-delete",
                    onClick: () => l && l(c, "global" === t.decision.scope ? "global" : "proposal"),
                    disabled: n,
                    children: "Clear"
                  }), t.decision && t.reason && a && (0, s.jsx)("button", {
                    type: "button",
                    className: "button-link",
                    onClick: () => {
                      var e, s;
                      return a(t, {
                        action: t.decision?.action,
                        target_id: null !== (e = t.decision?.target_id) && void 0 !== e ? e : null,
                        note: null !== (s = t.decision?.note) && void 0 !== s ? s : "",
                        persist_global: "global" === t.decision?.scope
                      });
                    },
                    disabled: n,
                    children: "Apply to similar"
                  }) ]
                }) ]
              });
            }, M = () => {
              const [t, o] = (0, e.useState)([]), [r, c] = (0, e.useState)(!0), [d, u] = (0, e.useState)(null), [p, h] = (0, 
              e.useState)(0), [m, v] = (0, e.useState)({}), [b, f] = (0, e.useState)(!1), [g, x] = (0, 
              e.useState)(!1), [j, _] = (0, e.useState)(null), [y, w] = (0, e.useState)({
                original_id: "",
                action: "",
                target_id: "",
                note: ""
              }), [C, N] = (0, e.useState)(""), [k, S] = (0, e.useState)(!1), [D, R] = (0, e.useState)(!0), [$, I] = (0, 
              e.useState)(!1), [A, E] = (0, e.useState)({
                imported: 0,
                errors: []
              }), M = (0, e.useRef)(null), [U, B] = (0, e.useState)(""), O = (0, e.useRef)(""), T = (0, 
              e.useMemo)(() => {
                if (!U) return t;
                const e = U.toLowerCase();
                return t.filter(t => [ t.original_id, t.action, t.note, t.target_id, t.saved_at ].filter(Boolean).map(e => String(e).toLowerCase()).some(t => t.includes(e)));
              }, [ U, t ]), P = t.length > 0, F = T.length > 0, V = (Object.values(m).some(Boolean), 
              !j && y.original_id && t.some(e => String(e.original_id) === String(y.original_id))), L = ("reuse" === y.action || "map" === y.action) && y.target_id && t.some(e => String(e.original_id) !== String(y.original_id) && e.target_id && Number(e.target_id) === Number(y.target_id));
              (0, e.useEffect)(() => {
                if (!O.current) {
                  const e = t.find(e => e.target_id);
                  e && (O.current = String(e.target_id));
                }
              }, [ t ]), (0, e.useEffect)(() => {
                let e = !0;
                return (async () => {
                  c(!0), u(null);
                  try {
                    const s = await n("resolver-rules");
                    var t;
                    e && o(null !== (t = s.rules) && void 0 !== t ? t : []);
                  } catch (t) {
                    e && u(t.message);
                  } finally {
                    e && c(!1);
                  }
                })(), () => {
                  e = !1;
                };
              }, [ p ]);
              const z = (e = null) => {
                var t, s, n;
                e ? (_(e), w({
                  original_id: String(null !== (t = e.original_id) && void 0 !== t ? t : ""),
                  action: null !== (s = e.action) && void 0 !== s ? s : "",
                  target_id: e.target_id ? String(e.target_id) : "",
                  note: null !== (n = e.note) && void 0 !== n ? n : ""
                })) : (_(null), w({
                  original_id: "",
                  action: "",
                  target_id: O.current || "",
                  note: ""
                })), N(""), x(!0);
              }, H = () => {
                x(!1), N(""), _(null);
              }, K = (e, t) => {
                w(s => {
                  const n = {
                    ...s,
                    [e]: t
                  };
                  return "action" === e && ("reuse" === t || "map" === t ? !n.target_id && O.current && (n.target_id = O.current) : n.target_id = ""), 
                  n;
                });
              };
              return (0, s.jsxs)("section", {
                className: "dbvc-resolver-rules",
                id: "dbvc-global-resolver",
                children: [ (0, s.jsxs)("div", {
                  className: "dbvc-resolver-rules__header",
                  children: [ (0, s.jsx)("h2", {
                    children: "Global Resolver Rules"
                  }), (0, s.jsx)("button", {
                    type: "button",
                    className: "button button-link",
                    onClick: () => R(e => !e),
                    children: D ? "Show rules" : "Hide rules"
                  }) ]
                }), (0, s.jsx)("p", {
                  className: "description",
                  children: "Decisions saved with “remember for future proposals” appear here. They apply automatically whenever a matching original media ID is detected in any proposal."
                }), !D && (0, s.jsxs)(s.Fragment, {
                  children: [ r && (0, s.jsx)("p", {
                    children: "Loading resolver rules…"
                  }), d && (0, s.jsx)("div", {
                    className: "notice notice-error",
                    children: (0, s.jsx)("p", {
                      children: d
                    })
                  }), !r && !P && (0, s.jsx)("p", {
                    children: "No global rules saved yet."
                  }), P && (0, s.jsx)("div", {
                    className: "dbvc-panel-search",
                    children: (0, s.jsxs)("label", {
                      children: [ "Search: ", (0, s.jsx)("input", {
                        type: "search",
                        value: U,
                        onChange: e => B(e.target.value),
                        placeholder: "ID, action, note…"
                      }) ]
                    })
                  }), !r && P && !F && (0, s.jsxs)("p", {
                    children: [ "No rules match “", U, "”." ]
                  }), !r && F && (0, s.jsxs)("table", {
                    className: "widefat striped",
                    children: [ (0, s.jsx)("thead", {
                      children: (0, s.jsxs)("tr", {
                        children: [ (0, s.jsx)("th", {
                          children: (0, s.jsx)("input", {
                            type: "checkbox",
                            checked: b,
                            onChange: () => {
                              const e = !b;
                              if (f(e), e) {
                                const e = {};
                                t.forEach(t => {
                                  e[t.original_id] = !0;
                                }), v(e);
                              } else v({});
                            }
                          })
                        }), (0, s.jsx)("th", {
                          children: "Original ID"
                        }), (0, s.jsx)("th", {
                          children: "Action"
                        }), (0, s.jsx)("th", {
                          children: "Target"
                        }), (0, s.jsx)("th", {
                          children: "Note"
                        }), (0, s.jsx)("th", {
                          children: "Saved"
                        }), (0, s.jsx)("th", {}) ]
                      })
                    }), (0, s.jsx)("tbody", {
                      children: T.map(e => {
                        var t, n;
                        return (0, s.jsxs)("tr", {
                          children: [ (0, s.jsx)("td", {
                            children: (0, s.jsx)("input", {
                              type: "checkbox",
                              checked: !!m[e.original_id],
                              onChange: () => {
                                return t = e.original_id, void v(e => ({
                                  ...e,
                                  [t]: !e[t]
                                }));
                                var t;
                              }
                            })
                          }), (0, s.jsx)("td", {
                            children: e.original_id
                          }), (0, s.jsx)("td", {
                            children: e.action
                          }), (0, s.jsx)("td", {
                            children: null !== (t = e.target_id) && void 0 !== t ? t : "—"
                          }), (0, s.jsx)("td", {
                            children: null !== (n = e.note) && void 0 !== n ? n : "—"
                          }), (0, s.jsx)("td", {
                            children: a(e.saved_at)
                          }), (0, s.jsxs)("td", {
                            className: "dbvc-resolver-rules__actions",
                            children: [ (0, s.jsx)("button", {
                              type: "button",
                              className: "button-link",
                              onClick: () => z(e),
                              children: "Edit"
                            }), (0, s.jsx)("button", {
                              type: "button",
                              className: "button-link-delete",
                              onClick: () => (async e => {
                                if (window.confirm(`Delete global rule for original ID ${e}?`)) try {
                                  await l(`resolver-rules/${encodeURIComponent(e)}`), h(e => e + 1);
                                } catch (e) {
                                  window.alert(e.message);
                                }
                              })(e.original_id),
                              children: "Delete"
                            }) ]
                          }) ]
                        }, e.original_id);
                      })
                    }) ]
                  }), (0, s.jsxs)("div", {
                    className: "dbvc-resolver-rules__bulk",
                    children: [ (0, s.jsx)("button", {
                      type: "button",
                      className: "button button-primary",
                      onClick: () => z(null),
                      children: "Add Rule"
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: "button",
                      onClick: () => {
                        M.current && M.current.click();
                      },
                      disabled: $,
                      children: $ ? "Importing…" : "Import CSV"
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: "button button-secondary",
                      onClick: async () => {
                        const e = Object.entries(m).filter(([, e]) => e).map(([e]) => e);
                        if (e.length) {
                          if (window.confirm(`Delete ${e.length} selected rule(s)?`)) try {
                            await i("resolver-rules/bulk-delete", {
                              original_ids: e
                            }), v({}), f(!1), h(e => e + 1);
                          } catch (e) {
                            window.alert(e.message);
                          }
                        } else window.alert("Select at least one rule to delete.");
                      },
                      disabled: !Object.values(m).some(Boolean),
                      children: "Delete Selected"
                    }), (0, s.jsx)("button", {
                      type: "button",
                      className: "button button-link",
                      onClick: () => {
                        const e = [ "original_id,action,target_id,note,saved_at" ].concat(t.map(e => {
                          var t;
                          return [ e.original_id, e.action, null !== (t = e.target_id) && void 0 !== t ? t : "", (e.note || "").replace(/"/g, '""'), e.saved_at ].join(",");
                        })).join("\n"), s = new Blob([ e ], {
                          type: "text/csv;charset=utf-8;"
                        }), n = URL.createObjectURL(s), i = document.createElement("a");
                        i.href = n, i.download = "dbvc-resolver-rules.csv", document.body.appendChild(i), 
                        i.click(), document.body.removeChild(i), URL.revokeObjectURL(n);
                      },
                      children: "Export CSV"
                    }), (0, s.jsx)("input", {
                      ref: M,
                      type: "file",
                      accept: ".csv,text/csv",
                      style: {
                        display: "none"
                      },
                      onChange: async e => {
                        const t = e.target.files && e.target.files[0];
                        t && (await (async e => {
                          I(!0), E({
                            imported: 0,
                            errors: []
                          });
                          try {
                            var t;
                            const s = (e => {
                              const t = e.replace(/\r\n/g, "\n").split("\n").map(e => e.trim()).filter(e => e.length > 0).map(e => (e => {
                                const t = [];
                                let s = "", n = !1;
                                for (let i = 0; i < e.length; i++) {
                                  const l = e[i];
                                  '"' === l ? n && '"' === e[i + 1] ? (s += '"', i++) : n = !n : "," !== l || n ? s += l : (t.push(s), 
                                  s = "");
                                }
                                return t.push(s), t;
                              })(e));
                              if (!t.length) return [];
                              let s = t[0].map(e => e.trim().toLowerCase()), n = t.slice(1);
                              s.includes("original_id") || (s = [ "original_id", "action", "target_id", "note" ], 
                              n = t);
                              const i = new Set([ "original_id", "action", "target_id", "note" ]), l = s.map((e, t) => i.has(e) ? e : `col_${t}`);
                              return n.map(e => {
                                const t = {};
                                return l.forEach((s, n) => {
                                  var i;
                                  t[s] = null !== (i = e[n]) && void 0 !== i ? i : "";
                                }), t;
                              }).map(e => {
                                const t = parseInt(e.original_id, 10), s = parseInt(e.target_id, 10);
                                return {
                                  original_id: Number.isFinite(t) ? t : null,
                                  action: (e.action || "").toLowerCase(),
                                  target_id: Number.isFinite(s) ? s : null,
                                  note: e.note || ""
                                };
                              }).filter(e => e.original_id && e.action);
                            })(await e.text());
                            if (!s.length) return void E({
                              imported: 0,
                              errors: [ "The CSV file does not contain any valid rows." ]
                            });
                            const n = await i("resolver-rules/import", {
                              rules: s
                            }), l = n.imported ? n.imported.length : 0;
                            E({
                              imported: l,
                              errors: null !== (t = n.errors) && void 0 !== t ? t : []
                            }), h(e => e + 1);
                          } catch (e) {
                            E({
                              imported: 0,
                              errors: [ e?.message || "Import failed." ]
                            });
                          } finally {
                            I(!1);
                          }
                        })(t), e.target.value = "");
                      }
                    }) ]
                  }), g && (0, s.jsxs)("form", {
                    className: "dbvc-resolver-rule-form",
                    onSubmit: async e => {
                      e.preventDefault(), N("");
                      const t = parseInt(y.original_id, 10);
                      if (!Number.isFinite(t) || t <= 0) return void N("Enter a valid original media ID.");
                      if (!j && V) return void N("A rule already exists for that original media ID. Choose Edit instead.");
                      if (!y.action) return void N("Select an action for this rule.");
                      const s = "reuse" === y.action || "map" === y.action, n = parseInt(y.target_id, 10);
                      if (s && (!Number.isFinite(n) || n <= 0)) N("Enter a target attachment ID for reuse/map actions."); else if (s && L) N("This attachment ID is already mapped to another rule."); else {
                        S(!0);
                        try {
                          await i("resolver-rules", {
                            original_id: t,
                            action: y.action,
                            target_id: s ? n : null,
                            note: y.note
                          }), h(e => e + 1), s && Number.isFinite(n) && n > 0 && (O.current = String(n)), 
                          H();
                        } catch (e) {
                          N(e?.message || "Failed to save rule.");
                        } finally {
                          S(!1);
                        }
                      }
                    },
                    children: [ (0, s.jsx)("h3", {
                      children: j ? "Edit global rule" : "Add global rule"
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-resolver-controls",
                      children: [ (0, s.jsx)("input", {
                        type: "number",
                        min: "1",
                        placeholder: "Original ID",
                        value: y.original_id,
                        onChange: e => K("original_id", e.target.value),
                        readOnly: !!j
                      }), V && (0, s.jsx)("span", {
                        className: "dbvc-field-hint is-warning",
                        children: "Rule already exists for this media ID."
                      }), (0, s.jsxs)("select", {
                        value: y.action,
                        onChange: e => K("action", e.target.value),
                        children: [ (0, s.jsx)("option", {
                          value: "",
                          children: "Select action…"
                        }), (0, s.jsx)("option", {
                          value: "reuse",
                          children: "Reuse existing"
                        }), (0, s.jsx)("option", {
                          value: "download",
                          children: "Download new"
                        }), (0, s.jsx)("option", {
                          value: "map",
                          children: "Map to attachment ID"
                        }), (0, s.jsx)("option", {
                          value: "skip",
                          children: "Skip"
                        }) ]
                      }), ("reuse" === y.action || "map" === y.action) && (0, s.jsx)("input", {
                        type: "number",
                        min: "1",
                        placeholder: "Target attachment ID",
                        value: y.target_id,
                        onChange: e => K("target_id", e.target.value)
                      }), L && (0, s.jsx)("span", {
                        className: "dbvc-field-hint is-warning",
                        children: "This attachment ID is already used by another rule."
                      }), (0, s.jsx)("textarea", {
                        value: y.note,
                        onChange: e => K("note", e.target.value),
                        placeholder: "Optional note",
                        rows: 2
                      }) ]
                    }), C && (0, s.jsx)("div", {
                      className: "notice notice-error",
                      children: (0, s.jsx)("p", {
                        children: C
                      })
                    }), (0, s.jsxs)("div", {
                      className: "dbvc-resolver-rule-form__actions",
                      children: [ (0, s.jsx)("button", {
                        type: "button",
                        className: "button button-link",
                        onClick: H,
                        children: "Cancel"
                      }), (0, s.jsx)("button", {
                        type: "submit",
                        className: "button button-primary",
                        disabled: k,
                        children: k ? "Saving…" : j ? "Update Rule" : "Save Rule"
                      }) ]
                    }) ]
                  }), (A.imported > 0 || A.errors.length > 0) && (0, s.jsxs)("div", {
                    className: "dbvc-resolver-import",
                    children: [ A.imported > 0 && (0, s.jsx)("div", {
                      className: "notice notice-success",
                      children: (0, s.jsxs)("p", {
                        children: [ A.imported, " rule(s) imported successfully." ]
                      })
                    }), A.errors.length > 0 && (0, s.jsxs)("div", {
                      className: "notice notice-warning",
                      children: [ (0, s.jsx)("p", {
                        children: "Import completed with warnings:"
                      }), (0, s.jsx)("ul", {
                        children: A.errors.map((e, t) => (0, s.jsx)("li", {
                          children: o(e)
                        }, t))
                      }) ]
                    }) ]
                  }) ]
                }) ]
              });
            };
          }
        }, s = {};
        function n(e) {
          var i = s[e];
          if (void 0 !== i) return i.exports;
          var l = s[e] = {
            exports: {}
          };
          return t[e](l, l.exports, n), l.exports;
        }
        n.m = t, e = [], n.O = (t, s, i, l) => {
          if (!s) {
            var a = 1 / 0;
            for (d = 0; d < e.length; d++) {
              for (var [s, i, l] = e[d], o = !0, r = 0; r < s.length; r++) (!1 & l || a >= l) && Object.keys(n.O).every(e => n.O[e](s[r])) ? s.splice(r--, 1) : (o = !1, 
              l < a && (a = l));
              if (o) {
                e.splice(d--, 1);
                var c = i();
                void 0 !== c && (t = c);
              }
            }
            return t;
          }
          l = l || 0;
          for (var d = e.length; d > 0 && e[d - 1][2] > l; d--) e[d] = e[d - 1];
          e[d] = [ s, i, l ];
        }, n.o = (e, t) => Object.prototype.hasOwnProperty.call(e, t), (() => {
          var e = {
            378: 0,
            681: 0
          };
          n.O.j = t => 0 === e[t];
          var t = (t, s) => {
            var i, l, [a, o, r] = s, c = 0;
            if (a.some(t => 0 !== e[t])) {
              for (i in o) n.o(o, i) && (n.m[i] = o[i]);
              if (r) var d = r(n);
            }
            for (t && t(s); c < a.length; c++) l = a[c], n.o(e, l) && e[l] && e[l][0](), e[l] = 0;
            return n.O(d);
          }, s = globalThis.webpackChunkdb_version_control_admin_app = globalThis.webpackChunkdb_version_control_admin_app || [];
          s.forEach(t.bind(null, 0)), s.push = t.bind(null, s.push.bind(s));
        })();
        var i = n.O(void 0, [ 681 ], () => n(435));
        i = n.O(i);
      })();
    }
  }, t = {};
  function s(n) {
    var i = t[n];
    if (void 0 !== i) return i.exports;
    var l = t[n] = {
      exports: {}
    };
    return e[n](l, l.exports, s), l.exports;
  }
  s.n = e => {
    var t = e && e.__esModule ? () => e.default : () => e;
    return s.d(t, {
      a: t
    }), t;
  }, s.d = (e, t) => {
    for (var n in t) s.o(t, n) && !s.o(e, n) && Object.defineProperty(e, n, {
      enumerable: !0,
      get: t[n]
    });
  }, s.o = (e, t) => Object.prototype.hasOwnProperty.call(e, t), (() => {
    "use strict";
    s(766);
  })();
})();
