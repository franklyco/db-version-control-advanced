#!/usr/bin/env python3
"""Lint and run pure PHP template checks in a temporary tree; never touch DBVC/WP."""
import json
import shutil
import subprocess
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
php = shutil.which('php')
if not php:
    print('NOT_RUN: PHP CLI is unavailable. No PHP or WordPress behavior was verified.')
    raise SystemExit(2)

overlay = json.loads((root / 'scaffold/overlay-manifest.json').read_text())
with tempfile.TemporaryDirectory(prefix='dbvc-dropin-php-') as temp:
    stage = Path(temp)
    sources = []
    for item in overlay['files']:
        relative = Path(item['proposed_destination'])
        source = (root / item['source']).resolve()
        if relative.is_absolute() or '..' in relative.parts or root not in source.parents:
            raise SystemExit('Unsafe overlay path')
        dest = stage / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, dest)
        sources.append(dest)
    harness = stage / 'tests/php-contracts.php'
    harness.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(root / 'scaffold/tests/php-contracts.php.stub', harness)
    for path in sources + [harness]:
        result = subprocess.run([php, '-l', str(path)], capture_output=True, text=True)
        if result.returncode:
            print(result.stdout + result.stderr)
            raise SystemExit(result.returncode)
    result = subprocess.run([php, str(harness), str(root / 'contracts/examples')], text=True)
    if result.returncode:
        raise SystemExit(result.returncode)
    print(f'PASS: {len(sources) + 1} PHP files linted; pure contract/bootstrap harness passed.')
    print('WordPress, SQL, network authentication and real Bricks adapters remain untested.')
