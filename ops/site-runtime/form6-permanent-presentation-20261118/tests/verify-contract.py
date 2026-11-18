#!/usr/bin/env python3
"""No-web offline validation of the exact live-source change slice."""
import hashlib,json
from pathlib import Path
p=Path(__file__).resolve().parent.parent
m=json.loads((p/'manifest.json').read_text())
assert m['contract']=='mad4b.form6.permanent.presentation-source-reconciliation.v1'
assert len(m['files'])==3
patch=(p/'patches/source-live-form6-policy.patch').read_bytes()
assert hashlib.sha256(patch).hexdigest()==m['patch_sha256']
assert b'shared_form6_stay_ui_contract_v1' in patch
assert b'verified_catalog_only' in patch
assert b'presentation-fluent-quote-v1-' in patch
assert b'hash_file(' in patch
watch=(p/'runtime/company-form6-asset-cache-reconciler-v1.py').read_text()
assert 'mod.version(site,inventory[' in watch
assert 'UNCHANGED' in watch
assert 'FAILED_RETRYABLE' in watch
for site in ('source','wayfa'):
 for suffix in ('service','path','timer'):
  assert (p/'systemd'/f'mad4b-form6-permanent-assets-{site}.{suffix}').exists()
print('FORM6_PERMANENT_RECONCILIATION_CONTRACT_PASS')
