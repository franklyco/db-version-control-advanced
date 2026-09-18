#!/usr/bin/env python3
"""Validate local handoff structure/checksums/links/examples. No installs or network."""
import hashlib
import json
import re
import sys
from pathlib import Path
from spec_model import validate_event

ROOT = Path(__file__).resolve().parents[1]


def files():
    return sorted(p for p in ROOT.rglob('*') if p.is_file() and '__pycache__' not in p.parts and p.suffix != '.pyc')


def validate():
    errors = []
    for path in ROOT.rglob('*'):
        if path.is_symlink():
            errors.append('Unexpected symlink: ' + str(path.relative_to(ROOT)))
    paths = files()
    for path in paths:
        if path.suffix == '.json':
            try:
                json.loads(path.read_text())
            except Exception as exc:
                errors.append(f'JSON {path.name}: {exc}')
        if path.suffix == '.php':
            errors.append('Executable PHP belongs outside the docs drop-in: ' + str(path.relative_to(ROOT)))
        if path.suffix == '.md':
            for link in re.findall(r'\]\(([^)]+)\)', path.read_text()):
                if re.match(r'^(?:https?:|mailto:|#)', link):
                    continue
                target = (path.parent / link.split('#')[0]).resolve()
                if ROOT not in target.parents or not target.exists():
                    errors.append(f'Broken/outside Markdown link in {path.relative_to(ROOT)}: {link}')
    overlay = json.loads((ROOT / 'scaffold/overlay-manifest.json').read_text())
    seen = set()
    for entry in overlay['files']:
        source, dest = entry['source'], entry['proposed_destination']
        if dest.startswith('/') or '..' in Path(dest).parts or dest in seen:
            errors.append('Unsafe/duplicate overlay destination: ' + dest)
        seen.add(dest)
        if not (ROOT / source).is_file() or not source.endswith('.stub'):
            errors.append('Missing/non-stub overlay source: ' + source)
    for path in (ROOT / 'contracts/examples').glob('*-observation.json'):
        try:
            validate_event(json.loads(path.read_text()))
        except Exception as exc:
            errors.append(f'Example {path.name}: {exc}')
    checksum_path = ROOT / 'FILE-CHECKSUMS.json'
    if not checksum_path.exists():
        errors.append('FILE-CHECKSUMS.json missing')
    else:
        expected = json.loads(checksum_path.read_text())['sha256']
        actual = {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest()
                  for p in paths if p != checksum_path}
        for name in sorted(set(expected) | set(actual)):
            if expected.get(name) != actual.get(name):
                errors.append('Checksum/file-set mismatch: ' + name)
    if errors:
        print('\n'.join(errors), file=sys.stderr)
        return 1
    print(f'PASS: {len(paths)} files; JSON, local links, overlay paths, examples and SHA-256 checksums.')
    print('Not a full JSON Schema validator or a PHP/WordPress runtime test.')
    return 0


if __name__ == '__main__':
    raise SystemExit(validate())
