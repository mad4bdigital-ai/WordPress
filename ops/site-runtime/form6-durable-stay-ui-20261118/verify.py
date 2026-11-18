#!/usr/bin/env python3
"""Source-locked Form #6 payload and guardrail verifier, no remote writes."""
from pathlib import Path
import hashlib
import json
import shutil
import subprocess

root=Path(__file__).resolve().parent
manifest=json.loads((root/'manifest.json').read_text())
assert manifest['contract']=='mad4b.form6.source-owned-permanent-stay-ui.v1'
assert manifest['deployment_allowed_by_this_manifest'] is False
files=manifest['paths']
assert len(files)==11
for rel,sha in files.items():
    path=root/rel
    assert rel.startswith('payload/') and '..' not in Path(rel).parts
    assert path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest()==sha, rel
php=(root/'payload/wordpress/mu-plugins/shared-product-core/adapters/presentation-form6-occupancy-policy-v1.php').read_text()
js=(root/'payload/wordpress/mu-plugins/shared-product-core/assets/presentation-fluent-quote-v1.js').read_text()
loader=(root/'payload/wordpress/plugins/mad4b-commerce/modules/compat/local-presentation-fluent-projection-v1.php').read_text()
watch=(root/'payload/host/bin/company-form6-asset-cache-reconciler-v1.py').read_text()
pricing=(root/'payload/wordpress/mu-plugins/mad4b-pricing-cache-inheritance.php').read_text()
for phrase in ('function shared_form6_stay_ui_contract_v1','shared.form6.stay-ui.v1',"'detail_controls'=>", "'property_selector'=>", 'foreach($stages as &$stage)'):
    assert phrase in php,phrase
for phrase in ('needsStayDetail=stage=>', 'spec.ui_contract.detail_controls', 'visibleStages', 'if(!units.length){', 'row.remove();', 'sole_property_form_projection'):
    assert phrase in js, phrase
for phrase in ('hash_file(\'sha256\'', 'hash_equals($fingerprint,hash_file', "content_url('mu-plugins/shared-product-core/assets/'.$publicName)"):
    assert phrase in loader, phrase
for phrase in ('def publish_asset','def cache_module','mod.routes(site)','mod.version(site,inventory[\'public_paths\'])','source_composite_sha256'):
    assert phrase in watch,phrase
for phrase in ("'units'",'|stay|accommodation|property|hotel|ship|unit|room|cabin|board|meal|supplier|stage|nights','\'board-type\''):
    assert phrase in pricing,phrase
for site in ('source','wayfa'):
    for ext in ('path','timer','service'):
        assert (root/f'payload/host/systemd/mad4b-form6-permanent-assets-{site}.{ext}').exists()
if shutil.which('node'):
    subprocess.run(['node','--check',str(root/'payload/wordpress/mu-plugins/shared-product-core/assets/presentation-fluent-quote-v1.js')],check=True)
if shutil.which('php'):
    for file in ('payload/wordpress/mu-plugins/shared-product-core/adapters/presentation-form6-occupancy-policy-v1.php','payload/wordpress/mu-plugins/mad4b-pricing-cache-inheritance.php','payload/wordpress/plugins/mad4b-commerce/modules/compat/local-presentation-fluent-projection-v1.php'):
        subprocess.run(['php','-l',str(root/file)],check=True,stdout=subprocess.DEVNULL)
print('FORM6_PERMANENT_SOURCE_VERIFIED',len(files),'SHA-pinned source artifacts')
