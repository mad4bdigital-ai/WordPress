#!/usr/bin/env python3
import argparse
import hashlib
import json
import re
from pathlib import Path
p=argparse.ArgumentParser()
p.add_argument('--source',action='store_true')
p.add_argument('--brand-neutral',action='store_true')
p.add_argument('--runtime-mu')
a=p.parse_args()
root=Path(__file__).resolve().parent
manifest=json.loads((root/'bundle-manifest.json').read_text())
assert manifest['contract']=='mad4b.environment-sandbox-bundle.v1'
for name,expected in manifest['files'].items():
    file=root/name
    assert file.is_file() and hashlib.sha256(file.read_bytes()).hexdigest()==expected,name+' source mismatch'
    if a.runtime_mu:
        live=Path(a.runtime_mu)/name
        assert live.is_file() and not live.is_symlink()
        assert hashlib.sha256(live.read_bytes()).hexdigest()==expected,name+' runtime mismatch'
if a.brand_neutral:
    pattern=re.compile(r'(?i)(?:\\betg\\b|etg_|_etg|(?:mad4b[.-])etg|tours\\.dev\\.mad4b\\.com|egypttourgates|allroyalegypt)')
    workflow=root/'mad4b-sandbox-environments.yml'
    if not workflow.is_file(): workflow=root.parent.parent/'.github/workflows/mad4b-sandbox-environments.yml'
    owned=[root/name for name in list(manifest['files'])+['README.md','policy-contract.php']] + [workflow]
    for file in owned:
        assert not pattern.search(file.read_text()),'tenant marker in '+file.name
print('MAD4B_ENVIRONMENT_SANDBOX_BUNDLE: PASS')
