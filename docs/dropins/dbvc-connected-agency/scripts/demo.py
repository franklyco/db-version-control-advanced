#!/usr/bin/env python3
"""Print synthetic routing and comparison outcomes. No network or WordPress access."""
import json
from pathlib import Path
from spec_model import compare, route

root = Path(__file__).resolve().parents[1]
examples = root / 'contracts/examples'
registry = json.loads((examples / 'registry.json').read_text())
for name in ['class-observation.json', 'service-observation.json', 'variable-observation.json']:
    event = json.loads((examples / name).read_text())
    print(name + ': ' + json.dumps(route(event, 'a-prod', registry), sort_keys=True))
print('Staging and production changed independently: ' + compare('base', 'staging-edit', 'prod-edit'))
print('Offline a-local remains an authorized pending recipient; no live delivery was performed.')
