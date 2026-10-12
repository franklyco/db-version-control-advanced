"""save-run.py — R5.later-perf sweep harness (E-162).

Reads one compact capture (the JSON printed by `capture-compact.js` /
`boot-capture.js`) on stdin and writes the expanded per-run record the
sweep's analysis reads:

    echo '<compact json>' | python3 save-run.py <scenario> <runN> ["notes"]
    -> qa/R5-LATER-PERF-MEASUREMENTS/<scenario>/<runN>.json

Run it from the plugin root (the output path is repo-relative). Scenario
directories are created by hand; a discarded run is saved under a
`discarded-<reason>` name with the reason in `notes` rather than deleted.
Companion: `../analyze.py` aggregates the saved runs into medians/p10/p90.
"""
import sys, json

scenario, run = sys.argv[1], sys.argv[2]
notes = sys.argv[3] if len(sys.argv) > 3 else ''
c = json.load(sys.stdin)
agg = {('dbvc.ve.' + k.replace('/', '.')): {'count': v[0], 'totalMs': v[1], 'maxMs': v[2]} for k, v in c['agg'].items()}
top = [{'name': 'dbvc.ve.' + n.replace('/', '.'), 'startTime': s, 'duration': d} for n, s, d in c.get('top', [])]
data = {'scenario': scenario, 'run': run, 'capturedAt': c['t'], 'viewport': {'w': c['vp'][0], 'h': c['vp'][1], 'dpr': c['vp'][2]}, 'hidden': c['hidden'],
        'navigation': ({'ttfb': c['nav'][0], 'domContentLoaded': c['nav'][1], 'load': c['nav'][2]} if c.get('nav') else None),
        'markers': c.get('markers'), 'workspaceOpen': c.get('ws'), 'extra': c.get('x'), 'spanCount': c['spans'], 'aggregate': agg, 'topSpans': top, 'notes': notes}
path = f'docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R5-LATER-PERF-MEASUREMENTS/{scenario}/{run}.json'
json.dump(data, open(path, 'w'), indent=2)
print(f"{scenario}/{run}: hidden={c['hidden']} vp={c['vp'][0]}x{c['vp'][1]} " + ', '.join(f"{k.replace('dbvc.ve.','')}={v['totalMs']:.0f}ms×{v['count']}" for k, v in agg.items() if v['totalMs'] >= 1))
