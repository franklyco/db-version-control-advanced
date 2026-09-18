"""Aggregate qa/R5-LATER-PERF-MEASUREMENTS/s*/runN.json → per-scenario per-span median/p10/p90 (+ extras)."""
import json, glob, os, statistics, sys
base = 'docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-MEASUREMENTS'
def pct(v, p):
    v = sorted(v); k = (len(v) - 1) * p; f = int(k); c = min(f + 1, len(v) - 1)
    return v[f] + (v[c] - v[f]) * (k - f)
def fmt(x): return f"{x:,.0f}" if x >= 100 else f"{x:.1f}"
out = []
for s in ['s1', 's2', 's3', 's4', 's5', 's6']:
    runs = [json.load(open(p)) for p in sorted(glob.glob(f'{base}/{s}/run*.json'))]
    disc = sorted(os.path.basename(p) for p in glob.glob(f'{base}/{s}/discarded-*.json'))
    spans = {}
    for r in runs:
        for name, a in r['aggregate'].items():
            spans.setdefault(name, {'total': [], 'count': [], 'max': []})
            spans[name]['total'].append(a['totalMs']); spans[name]['count'].append(a['count']); spans[name]['max'].append(a['maxMs'])
    out.append(f"\n### {s} — {len(runs)} counted runs" + (f" (discarded: {', '.join(disc)})" if disc else ''))
    out.append(f"viewport {runs[0]['viewport']['w']}×{runs[0]['viewport']['h']} @{runs[0]['viewport']['dpr']}x · all runs `hidden:false` · captured {runs[0]['capturedAt']}–{runs[-1]['capturedAt']}")
    out.append('\n| span | count/run | total ms median | p10 | p90 | max single |\n|---|---|---|---|---|---|')
    for name, v in sorted(spans.items(), key=lambda kv: -statistics.median(kv[1]['total'])):
        out.append(f"| `{name.replace('dbvc.ve.', '')}` | {statistics.median(v['count']):g} | {fmt(statistics.median(v['total']))} | {fmt(pct(v['total'], .1))} | {fmt(pct(v['total'], .9))} | {fmt(max(v['max']))} |")
    nav = [r['navigation']['ttfb'] for r in runs if r.get('navigation')]
    if nav: out.append(f"\nPage navigation TTFB (full Bricks render, logged in): median {fmt(statistics.median(nav))} ms · load {fmt(statistics.median([r['navigation']['load'] for r in runs]))} ms · markers {runs[0]['markers']}")
    ex = [r.get('extra') for r in runs if r.get('extra')]
    if ex:
        keys = [k for k in ex[0] if isinstance(ex[0][k], (int, float)) and not isinstance(ex[0][k], bool)]
        if keys:
            out.append('\nExtras (median [min–max]): ' + ' · '.join(f"{k} {fmt(statistics.median([e[k] for e in ex]))} [{fmt(min(e[k] for e in ex))}–{fmt(max(e[k] for e in ex))}]" for k in keys))
        for k in ('batchHist', 'serverWaitMs', 'requests', 'listResource', 'statuses'):
            vals = [e.get(k) for e in ex if e.get(k) is not None]
            if vals: out.append(f"{k}: " + ' · '.join(json.dumps(v, separators=(',', ':')) for v in vals[:5]))
print('\n'.join(out))
