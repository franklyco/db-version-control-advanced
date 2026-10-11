// boot-capture.js — R5.later-perf sweep harness (E-162), scenarios S1/S2.
//
// Run immediately after navigating to `?dbvc_ve_perf=1`. Waits for the page to
// finish loading AND for the overlay's own `dbvc.ve.overlay_boot` span, settles
// 2 s for the delayed badge-refresh timers (250/1000/2500 ms), then captures.
// `hidden` MUST come back false — a backgrounded or occluded tab throttles
// timers ~25x (E-123); discard the run otherwise.
const t0 = Date.now(); while (Date.now() - t0 < 15000) { if (document.readyState === 'complete' && performance.getEntriesByName('dbvc.ve.overlay_boot', 'measure').length) break; await new Promise(r => setTimeout(r, 100)); } await new Promise(r => setTimeout(r, 2000)); const all = performance.getEntriesByType('measure').filter(e => e.name.startsWith('dbvc.ve.')).sort((a, b) => a.startTime - b.startTime);
const agg = {};
for (const e of all) { const k = e.name.slice(8).replace(/\./g, '/'); const a = agg[k] || (agg[k] = [0, 0, 0]); a[0]++; a[1] += e.duration; a[2] = Math.max(a[2], e.duration); }
for (const k in agg) { agg[k][1] = Math.round(agg[k][1] * 10) / 10; agg[k][2] = Math.round(agg[k][2] * 10) / 10; }
const top = all.filter(e => e.duration >= 5).sort((a, b) => b.duration - a.duration).slice(0, 10).map(e => [e.name.slice(8).replace(/\./g, '/'), Math.round(e.startTime), Math.round(e.duration * 10) / 10]);
const nav = performance.getEntriesByType('navigation')[0];
const out = { t: new Date().toISOString().slice(11, 19), vp: [innerWidth, innerHeight, devicePixelRatio], hidden: document.hidden, nav: nav ? [Math.round(nav.responseStart), Math.round(nav.domContentLoadedEventEnd), Math.round(nav.loadEventEnd)] : null, markers: document.querySelectorAll('.dbvc-ve-target').length, ws: window.DBVCVisualEditorWorkspace ? window.DBVCVisualEditorWorkspace.isOpen() : null, spans: all.length, agg, top };
localStorage.setItem('dbvc-perf-capture', JSON.stringify(out));
JSON.stringify(out)
