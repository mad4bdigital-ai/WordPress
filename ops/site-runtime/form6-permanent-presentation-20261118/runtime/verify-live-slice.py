#!/usr/bin/env python3
"""Read-only guard: refuse partial/unknown plugin versions when reconciling.

The original live files are deliberately not replaced by Git master content.
Only exact source SHA-256 triples in manifest.json can pass the release gate.
"""
import argparse,hashlib,json
from pathlib import Path
p=Path(__file__).resolve().parent.parent
m=json.loads((p/'manifest.json').read_text())
parser=argparse.ArgumentParser()
parser.add_argument('--site-root',required=True)
a=parser.parse_args()
root=Path(a.site_root).resolve()
if root.name!='wp-content':
 raise SystemExit('Expected exact WordPress wp-content root')
records=[]
for entry in m['files']:
 rel=entry['live_path']
 assert rel.startswith('wp-content/') and '..' not in rel
 path=root/rel[len('wp-content/'):]
 value=hashlib.sha256(path.read_bytes()).hexdigest()
 state=('after' if value==entry['live_sha256'] else
        'before' if value==entry['before_sha256'] else 'drift')
 records.append((rel,state))
states={state for _,state in records}
if states=={'after'}:
 print('PASS exact post-change source slice, no mutation')
elif states=={'before'}:
 print('BASELINE exact pre-change source slice; ready for manual patch review')
else:
 raise SystemExit('ABORT mixed/unknown live code; three-way review required: '+repr(records))
