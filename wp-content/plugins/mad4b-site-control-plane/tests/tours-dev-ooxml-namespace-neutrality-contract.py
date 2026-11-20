#!/usr/bin/env python3
"""Exact OOXML namespace allowlist is not a tenant endpoint or a general web bypass."""
import json
from pathlib import Path
import importlib.util

root=Path(__file__).resolve().parents[4]
path='includes/class-mad4b-scp-context-local-document-normalizer.php'
policy=json.loads((root/'wp-content/plugins/mad4b-site-control-plane/tests/site-neutrality-policy.json').read_text())
entry=policy['reviewed_literal_endpoints'][path]
assert entry['hosts']==['schemas.openxmlformats.org']
assert len(entry['rationale'])>80 and 'namespace identifier' in entry['rationale']
# An older audited read-only Google Drive context reader already uses the
# same standard OOXML namespace. This PR adds precisely one more source file.
prior='includes/class-mad4b-scp-google-drive-context.php'
permitted={p for p,x in policy['reviewed_literal_endpoints'].items() if 'schemas.openxmlformats.org' in x['hosts']}
assert permitted=={prior,path},f'OOXML host allowlist grew unexpectedly: {permitted}'
mod=root/'tools/check_plugin_site_neutrality.py'
spec=importlib.util.spec_from_file_location('mad4b_site_neutrality',mod)
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
source=(root/'wp-content/plugins/mad4b-site-control-plane'/path).read_text()
assert not module.inspect(path,source,policy)
assert module.inspect('includes/class-mad4b-scp-site-profile.php',source,policy),'Namespace host not permitted anywhere else'
assert any(x.startswith('UNREVIEWED_LITERAL_HOST') for x in module.inspect(path,source+"\n'https://unsafe-source.example'",policy)),'Unknown source hostname remains blocked'
assert any(x.startswith('FIXED_BUSINESS_MODEL') for x in module.inspect(path,source+"\n'post_type=tours-and-activities'; 'tours-and-activities'",policy)),'Tenant type defaults remain prohibited'
provider=(root/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-provider-field-intelligence.php').read_text()
assert "'tours-and-activities'" not in provider
assert "$subtype=(string)($input['subtype']??'post')" in provider
assert "$subtype=(string)($assoc['taxonomy']??$assoc['post-type']??'post')" in provider
assert "'subtype'=>(string)($request['subtype']??'post')" in provider
print('PASS: exact OOXML namespace is an offline XML name only; other hosts and tenant CPT defaults remain prohibited')
