// capture-compact.js — R5.later-perf sweep harness (E-162).
//
// Paste into the measured tab's console (or run via the browser tool) AFTER the
// scenario's action has settled. Aggregates every `dbvc.ve.*` User Timing
// measure into the compact shape `save-run.py` expects, stores it in
// localStorage['dbvc-perf-capture'] and returns it.
//
// Span names use `/` separators in the compact form purely so agent tooling
// does not redact them; `save-run.py` restores the `.` form.
const all = performance.getEntriesByType('measure').filter(e => e.name.startsWith('dbvc.ve.')).sort((a, b) => a.startTime - b.startTime);
const agg = {};
for (const e of all) { const k = e.name.slice(8).replace(/\./g, '/'); const a = agg[k] || (agg[k] = [0, 0, 0]); a[0]++; a[1] += e.duration; a[2] = Math.max(a[2], e.duration); }
for (const k in agg) { agg[k][1] = Math.round(agg[k][1] * 10) / 10; agg[k][2] = Math.round(agg[k][2] * 10) / 10; }
const top = all.filter(e => e.duration >= 5).sort((a, b) => b.duration - a.duration).slice(0, 10).map(e => [e.name.slice(8).replace(/\./g, '/'), Math.round(e.startTime), Math.round(e.duration * 10) / 10]);
const nav = performance.getEntriesByType('navigation')[0];
const out = { t: new Date().toISOString().slice(11, 19), vp: [innerWidth, innerHeight, devicePixelRatio], hidden: document.hidden, nav: nav ? [Math.round(nav.responseStart), Math.round(nav.domContentLoadedEventEnd), Math.round(nav.loadEventEnd)] : null, markers: document.querySelectorAll('.dbvc-ve-target').length, ws: window.DBVCVisualEditorWorkspace ? window.DBVCVisualEditorWorkspace.isOpen() : null, spans: all.length, agg, top };
localStorage.setItem('dbvc-perf-capture', JSON.stringify(out));
JSON.stringify(out)
