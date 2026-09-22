#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
repo = ROOT.parents[2]
script = (repo / 'tools/evaluate-functional-gap-contract-promotion.py').read_text('utf-8')
policy = json.loads((ROOT / 'config/functional-gap-policy.json').read_text('utf-8'))

if policy.get('contract') != 'mad4b.functional-gap-policy.v1':
    raise SystemExit('functional-gap policy contract mismatch')
if policy.get('default_mutation') != 'deny' or policy.get('promotion_authorized') is not False:
    raise SystemExit('functional-gap policy weakened mutation/promotion defaults')

import importlib.util
spec = importlib.util.spec_from_file_location('mad4b_functional_gap_eval', repo / 'tools/evaluate-functional-gap-contract-promotion.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
vectors = [
    ([], '75ba621010ccaf63e7ef664ef0ecbbf26ca60506903cc05e68cba6011d8d692b'),
    ({'a': True, 'b': [], 'c': '✓'}, '859b873c5b7837892deb70de68ea0fb70bc74d09c294f08d689265645b339d9b'),
    ([1, False, None, 'x'], '8ffca4dff324e9febf88bc81127a38695e5746f1d8abffd8487d911bdeb26ca3'),
    ({'empty': {}, 'list': []}, '2b6c715a39a7f4840cda82a57b503fc3284d34b65ae100e3ae103a1f74de084b'),
]
for value, expected in vectors:
    actual = module.canonical_digest(value)
    if actual != expected:
        raise SystemExit(f'canonical digest known-answer mismatch: expected {expected} got {actual}')

supported_modes = {
    'bounded_read_routes',
    'redacted_status',
    'exact_tree_review',
    'runtime_only',
    'premium_semantic',
    'composite_behavioral',
}
families = policy.get('families', {})
if not families:
    raise SystemExit('functional-gap policy family set is empty')

identity_prefixes = {}
for family, row in families.items():
    mode = row.get('evaluation_mode', '')
    if mode not in supported_modes:
        raise SystemExit(f'{family}: unsupported policy evaluation mode: {mode}')
    matches = list(row.get('match', []))
    versioned = list(row.get('versioned_match', []))
    if not matches and not versioned:
        raise SystemExit(f'{family}: runtime identity match set is empty')
    prefixes = [str(x).lstrip('/').replace('\\\\','/') for x in matches]
    for base in versioned:
        base = str(base).strip('/').replace('\\\\','/')
        if not base or any(ch not in 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-' for ch in base):
            raise SystemExit(f'{family}: invalid versioned identity base: {base}')
        prefixes.append(base.lower() + '-v')
    for prefix in prefixes:
        prefix = prefix.lower()
        for known, owner in identity_prefixes.items():
            if owner != family and (prefix.startswith(known) or known.startswith(prefix)):
                raise SystemExit(f'{family}: policy identity overlaps {owner}: {prefix} vs {known}')
        identity_prefixes[prefix] = family
    if row.get('repository_evidence') is True and not row.get('repository_artifacts'):
        raise SystemExit(f'{family}: repository evidence family has no artifacts')
    if mode == 'bounded_read_routes':
        if not row.get('required_get_routes') or not row.get('safe_now') or not row.get('blocked'):
            raise SystemExit(f'{family}: bounded read policy is incomplete')
        if row.get('require_non_public_permissions') is not True:
            raise SystemExit(f'{family}: bounded read permission boundary is not fail-closed')
    if mode == 'redacted_status':
        if not row.get('redacted_secret_option_keys') or not row.get('required_status_option_keys'):
            raise SystemExit(f'{family}: redacted status policy is incomplete')

required = [
    'mad4b.functional-gap-promotion-evaluation.v2',
    'mad4b.functional-gap-policy.v1',
    'POLICY_PATH',
    'policy_sha256',
    'decision_fingerprint',
    'repository_evidence_sha256',
    'canonical_digest',
    'canonical_digest_lines',
    'base64.b64encode',
    'if not value:',
    'return [f"{path}\\tl:0"]',
    '\\tb:',
    '\\ti:',
    '\\ts:',
    '\\tl:',
    '\\tm:',
    'evaluation_mode',
    'bounded_read_routes',
    'redacted_status',
    'exact_tree_review',
    'runtime_only',
    'premium_semantic',
    'composite_behavioral',
    'read_contract_candidate',
    'route_security',
    'get_permission_callbacks',
    'get_permission_missing',
    'get_permission_public',
    'insecure_get_routes',
    'exact_runtime_tree_required_get_routes_and_permissions_verified',
    'redacted_read_contract_candidate',
    'runtime_alignment_required',
    'semantic_attestation_required',
    'runtime_alignment_or_behavioral_recertification_required',
    'runtime_evidence_unstable',
    '"promotion_authorized": False',
    '"production_mutation": False',
    'options.get(key, {}).get("exists") is True',
]
for marker in required:
    if marker not in script:
        raise SystemExit(f'missing policy-driven evaluator invariant: {marker}')

for forbidden in [
    'family="bulk-taxonomy-editor"',
    'family="wpl-client"',
    'for family in ("custom-mega-menu"',
    'for family in ("duplicator"',
    '"functional_ready"',
    '"promotion_authorized": True',
    '"production_mutation": True',
]:
    if forbidden in script:
        raise SystemExit(f'offline evaluator retained hardcoded/authorizing behavior: {forbidden}')

print(f'mad4b.functional-gap-promotion-evaluator.contract.v2: PASS families={len(families)}')
