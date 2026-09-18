#!/usr/bin/env python3
"""Read-only source inventory; output is written outside the inspected Git repo."""
import argparse
import hashlib
import json
import os
import re
import subprocess
from pathlib import Path
from datetime import datetime, timezone

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--repo', required=True)
parser.add_argument('--output', required=True)
args=parser.parse_args()
repo=Path(args.repo).expanduser().resolve()
out=Path(args.output).expanduser().resolve()
if not (repo/'db-version-control.php').is_file():
    parser.error('Expected DBVC plugin root containing db-version-control.php.')
if out == repo or repo in out.parents:
    parser.error('Choose an output outside the DBVC repository.')
if out.exists():
    parser.error('Output already exists; choose a new report filename.')

def git(*parts):
    proc=subprocess.run(['git','--no-optional-locks','-c','core.fsmonitor=false','-C',str(repo),*parts],
        capture_output=True,text=True,timeout=30,env={**os.environ,'GIT_OPTIONAL_LOCKS':'0'})
    if proc.returncode:
        return {'available':False,'exit_code':proc.returncode}
    return {'available':True,'value':proc.stdout.strip()}

worktree = git('rev-parse', '--show-toplevel')
if worktree['available']:
    git_root = Path(worktree['value']).resolve()
    if out == git_root or git_root in out.parents:
        parser.error('Choose an output outside the actual Git worktree, not merely outside the plugin folder.')

root=Path(__file__).resolve().parents[1]
lock_path=root/'evidence/source-lock.json'
paths=json.loads(lock_path.read_text())['files'] if lock_path.exists() else []
selected=sorted({item['path'] for item in paths}|{
    'includes/class-sync-posts.php','includes/class-sync-taxonomies.php',
    'includes/class-database.php','includes/class-snapshot-manager.php','includes/class-backup-manager.php',
    'addons/connected-environments/bootstrap.php','addons/agency-control/bootstrap.php',
    'addons/visual-editor/bootstrap.php','addons/bricks/bricks-addon.php'})
scan=re.compile(r'\b(?:function\s+[A-Za-z_][A-Za-z_0-9]*|do_action\s*\(|apply_filters\s*\(|add_action\s*\(|register_rest_route\s*\()')
files=[]
for rel in selected:
    path=(repo/rel).resolve()
    if repo not in path.parents or not path.is_file():
        files.append({'path':rel,'status':'missing_or_outside_root'}); continue
    size=path.stat().st_size
    if size>5_000_000:
        files.append({'path':rel,'status':'too_large_for_bounded_read','size':size}); continue
    raw=path.read_bytes(); content=raw.decode('utf-8',errors='replace')
    hits=[{'line':i,'text':line.strip()[:240]} for i,line in enumerate(content.splitlines(),1) if scan.search(line)]
    files.append({'path':rel,'status':'read','sha256':hashlib.sha256(raw).hexdigest(),
                  'size':size,'matches':hits[:100],'matches_truncated':len(hits)>100})
report={'schema_version':'agency-control.local-discovery.v0.1',
        'collected_at':datetime.now(timezone.utc).isoformat(),'repo':str(repo),
        'head':git('rev-parse','HEAD'),'branch':git('branch','--show-current'),
        'status':git('status','--short','--untracked-files=normal'),
        'files':files,'runtime_verified':False,
        'notes':['No fetch/checkout/stash/reset/install/WordPress invocation performed.',
                 'Hook lines are source hints, not verified timing or live behavior.',
                 'Report includes local paths and bounded source lines; review before sharing.']}
out.parent.mkdir(parents=True,exist_ok=True)
with out.open('x',encoding='utf-8') as handle: json.dump(report,handle,indent=2)
print('Wrote source inventory: '+str(out))
