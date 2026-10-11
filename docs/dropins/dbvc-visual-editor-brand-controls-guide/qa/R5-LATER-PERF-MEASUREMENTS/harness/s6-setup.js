// s6-setup.js — R5.later-perf sweep harness (E-162), scenario S6 SETUP
// (palette-parent Open -> 19-swatch grid).
//
// Brings the drawer to the state the measured click needs, then returns a
// readiness report. Run the measured click separately (clear User Timing,
// click the row's `[data-dbvc-ve-control-center-action="open"]` button, wait
// for 19 `.dbvc-ve-panel__palette-overview-cell` + `-hex` nodes and
// `dbvc.ve.panel.render`, settle 500 ms, then capture).
//
// Preconditions: the palette parent row lives in the collapsed-by-default
// `Site Settings Advanced` group — expand it (or all groups) via
// localStorage['dbvc.ve.control-center.groups'] before loading, and let the
// row's own value-summary batch finish (the 7 s wait + poll below) so the
// measurement is not queued behind it.
let t0 = Date.now(); while (Date.now() - t0 < 30000) { if (document.readyState === 'complete' && performance.getEntriesByName('dbvc.ve.overlay_boot', 'measure').length) break; await new Promise(r => setTimeout(r, 100)); } await new Promise(r => setTimeout(r, 2000)); document.dispatchEvent(new CustomEvent('dbvc:visual-editor:control-center:toggle')); t0 = Date.now(); while (Date.now() - t0 < 15000) { if (document.querySelectorAll('.dbvc-ve-control-center__row').length > 0 && performance.getEntriesByName('dbvc.ve.drawer.list_fetch', 'measure').length) break; await new Promise(r => setTimeout(r, 50)); } await new Promise(r => setTimeout(r, 500)); performance.setResourceTimingBufferSize(5000); const of = window.fetch; window.__pf = { calls: [], orig: of }; window.fetch = function (u, o) { let rec = null; try { if (/value-summaries/.test(String(u)) && o && o.body) { rec = { s: Math.round(performance.now()), n: (JSON.parse(o.body).publicIds||[]).length, e: null }; window.__pf.calls.push(rec); } } catch (e) {} const p = of.apply(this, arguments); if (rec) p.then(() => { rec.e = Math.round(performance.now()); }, () => { rec.e = Math.round(performance.now()); }); return p; }; const row = document.querySelector('.dbvc-ve-control-center__row[data-public-id="vertical:palette_vertical_global_palette"]'); row.scrollIntoView({ block: 'center' }); await new Promise(r => setTimeout(r, 7000)); t0 = Date.now(); while (Date.now() - t0 < 15000 && window.__pf.calls.some(c => !c.e)) await new Promise(r => setTimeout(r, 200)); const btn = row.querySelector('[data-dbvc-ve-control-center-action="open"]'); JSON.stringify({vs: window.__pf.calls.length, pending: window.__pf.calls.filter(c => !c.e).length, btnVis: btn && btn.offsetParent !== null, hidden: document.hidden})
