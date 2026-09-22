#!/usr/bin/env python3
import argparse
import copy
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPO = ROOT.parents[2]
PY_EVALUATOR = REPO / 'tools/evaluate-functional-gap-contract-promotion.py'
PHP_PARITY = ROOT / 'tests/functional-gap-evaluator-parity.php'
POLICY_PATH = ROOT / 'config/functional-gap-policy.json'

def load(path):
    return json.loads(Path(path).read_text('utf-8'))

def write(path, value):
    Path(path).write_text(json.dumps(value, indent=2, sort_keys=True) + '\n', 'utf-8')

def decision_state(output, family):
    for row in output.get('decisions', []):
        if row.get('family') == family:
            return row.get('state', '')
    return ''

def first_active(runtime, family):
    for row in runtime.get('families', {}).get(family, []):
        if row.get('active'):
            return row
    raise SystemExit(f'{family}: no active fixture row')

def set_tree_drift(runtime, family, digit):
    row = first_active(runtime, family)
    tree = row.setdefault('plugin_tree', {})
    tree['tree_sha256'] = digit * 64
    tree['scan_stable'] = True
    tree['comparison'] = 'stable_runtime_drift'
    tree.pop('error', None)

def set_unstable(runtime, family):
    row = first_active(runtime, family)
    tree = row.setdefault('plugin_tree', {})
    tree['scan_stable'] = False
    tree['comparison'] = 'runtime_tree_changed_during_scan'
    tree['error'] = 'runtime_tree_unstable'

def set_inactive(runtime, family):
    for row in runtime.get('families', {}).get(family, []):
        row['active'] = False

def set_metadata_census(runtime, family, digit):
    row = first_active(runtime, family)
    tree = row.setdefault('plugin_tree', {})
    tree['tree_sha256'] = ''
    tree['census_sha256'] = digit * 64
    tree['scan_stable'] = True
    tree['scan_attempts'] = 1
    tree['metadata_only'] = True
    tree['content_rehashed'] = False
    tree['comparison'] = 'runtime_only_metadata_identity'
    tree['scan_strategy'] = 'metadata_only_runtime_identity'
    tree.pop('error', None)

def remove_route(runtime, route):
    runtime['rest_routes'] = [row for row in runtime.get('rest_routes', []) if row.get('route') != route]

def wpl_redaction_fail(runtime):
    runtime.setdefault('option_presence', {}).setdefault('wpl_access_token', {})['redacted'] = False

def wpl_secret_read_fail(runtime):
    row = runtime.setdefault('option_presence', {}).setdefault('wpl_access_token', {})
    row['redacted'] = True
    row['value_read'] = True
    row['probe'] = 'omitted_secret_value'

def make_route_public(runtime, route):
    for row in runtime.get('rest_routes', []):
        if row.get('route') == route:
            row['get_permission_callbacks'] = ['__return_true']
            row['get_permission_missing'] = False
            row['get_permission_public'] = True
            return
    raise SystemExit(f'route fixture missing: {route}')

def make_route_callback_drift(runtime, route):
    for row in runtime.get('rest_routes', []):
        if row.get('route') == route:
            row['get_permission_callbacks'] = ['bte_other_non_public_permissions']
            row['get_permission_missing'] = False
            row['get_permission_public'] = False
            return
    raise SystemExit(f'route fixture missing: {route}')

def make_status_option_missing(runtime, key):
    row = runtime.setdefault('option_presence', {}).setdefault(key, {})
    row['exists'] = False

def expected_happy_state(mode):
    return {
        'bounded_read_routes': 'read_contract_candidate',
        'redacted_status': 'redacted_read_contract_candidate',
        'exact_tree_review': 'contract_evidence_review',
        'runtime_only': 'runtime_contract_evidence_captured',
        'premium_semantic': 'semantic_attestation_required',
        'composite_behavioral': 'behavioral_recertification_required',
    }.get(str(mode), 'evidence_unavailable')

def expected_drift_state(mode):
    return {
        'bounded_read_routes': 'contract_discovery_required',
        'redacted_status': 'contract_discovery_required',
        'exact_tree_review': 'runtime_alignment_required',
        'premium_semantic': 'runtime_alignment_required',
        'composite_behavioral': 'runtime_alignment_required',
    }.get(str(mode), '')

def cases(base, policy):
    out = []

    families = policy.get('families', {}) if isinstance(policy.get('families', {}), dict) else {}
    happy_expected = {
        family: expected_happy_state(rule.get('evaluation_mode', ''))
        for family, rule in sorted(families.items())
    }
    if not happy_expected or any(state == 'evidence_unavailable' for state in happy_expected.values()):
        raise SystemExit('policy-driven happy-state map is incomplete')
    happy = copy.deepcopy(base)
    out.append(('happy-all-policy-families', happy, happy_expected))

    for family, rule in sorted(families.items()):
        inactive = copy.deepcopy(base)
        set_inactive(inactive, family)
        out.append((f'policy-{family}-inactive', inactive, {family: 'not_active'}))

        unstable = copy.deepcopy(base)
        set_unstable(unstable, family)
        out.append((f'policy-{family}-unstable', unstable, {family: 'runtime_evidence_unstable'}))

        if rule.get('repository_evidence') is True:
            expected = expected_drift_state(rule.get('evaluation_mode', ''))
            if not expected:
                raise SystemExit(f'{family}: repository-backed policy mode has no drift expectation')
            drift = copy.deepcopy(base)
            set_tree_drift(drift, family, 'f')
            out.append((f'policy-{family}-tree-drift', drift, {family: expected}))

    route_missing = copy.deepcopy(base)
    remove_route(route_missing, '/bulk-taxonomy-editor/v1/posts')
    out.append(('bulk-route-missing', route_missing, {'bulk-taxonomy-editor': 'contract_discovery_required'}))

    bulk_drift = copy.deepcopy(base)
    set_tree_drift(bulk_drift, 'bulk-taxonomy-editor', 'a')
    out.append(('bulk-tree-drift', bulk_drift, {'bulk-taxonomy-editor': 'contract_discovery_required'}))

    bulk_public = copy.deepcopy(base)
    make_route_public(bulk_public, '/bulk-taxonomy-editor/v1/posts')
    out.append(('bulk-public-permission', bulk_public, {'bulk-taxonomy-editor': 'contract_discovery_required'}))

    bulk_callback_drift = copy.deepcopy(base)
    make_route_callback_drift(bulk_callback_drift, '/bulk-taxonomy-editor/v1/posts')
    out.append(('bulk-permission-callback-drift', bulk_callback_drift, {'bulk-taxonomy-editor': 'contract_discovery_required'}))

    wpl_redaction = copy.deepcopy(base)
    wpl_redaction_fail(wpl_redaction)
    out.append(('wpl-redaction-failure', wpl_redaction, {'wpl-client': 'contract_discovery_required'}))

    wpl_secret_read = copy.deepcopy(base)
    wpl_secret_read_fail(wpl_secret_read)
    out.append(('wpl-secret-value-read', wpl_secret_read, {'wpl-client': 'contract_discovery_required'}))

    wpl_status_missing = copy.deepcopy(base)
    make_status_option_missing(wpl_status_missing, 'wpl_serial_verified')
    out.append(('wpl-status-option-missing', wpl_status_missing, {'wpl-client': 'contract_discovery_required'}))

    rank_drift = copy.deepcopy(base)
    set_tree_drift(rank_drift, 'rank-math', 'b')
    out.append(('rank-tree-drift', rank_drift, {'rank-math': 'runtime_alignment_required'}))

    rank_unstable = copy.deepcopy(base)
    set_unstable(rank_unstable, 'rank-math')
    out.append(('rank-tree-unstable', rank_unstable, {'rank-math': 'runtime_evidence_unstable'}))

    duplicator_inactive = copy.deepcopy(base)
    set_inactive(duplicator_inactive, 'duplicator')
    out.append(('runtime-only-inactive', duplicator_inactive, {'duplicator': 'not_active'}))

    premium_unstable = copy.deepcopy(base)
    set_unstable(premium_unstable, 'jetengine')
    out.append(('premium-tree-unstable', premium_unstable, {'jetengine': 'runtime_evidence_unstable'}))

    premium_drift = copy.deepcopy(base)
    set_tree_drift(premium_drift, 'jetengine', 'c')
    out.append(('premium-tree-drift', premium_drift, {'jetengine': 'runtime_alignment_required'}))

    jetsmart_drift = copy.deepcopy(base)
    set_tree_drift(jetsmart_drift, 'jetsmartfilters', 'd')
    out.append(('jetsmartfilters-tree-drift', jetsmart_drift, {'jetsmartfilters': 'runtime_alignment_required'}))

    composite_drift = copy.deepcopy(base)
    set_tree_drift(composite_drift, 'wp-import-export', 'e')
    out.append(('composite-component-drift', composite_drift, {'wp-import-export': 'runtime_alignment_required'}))

    composite_inactive = copy.deepcopy(base)
    set_inactive(composite_inactive, 'wp-import-export')
    out.append(('composite-inactive', composite_inactive, {'wp-import-export': 'not_active'}))

    census_a = copy.deepcopy(base)
    set_metadata_census(census_a, 'duplicator', '1')
    out.append(('runtime-only-census-a', census_a, {'duplicator': 'runtime_contract_evidence_captured'}))

    census_b = copy.deepcopy(base)
    set_metadata_census(census_b, 'duplicator', '2')
    out.append(('runtime-only-census-b', census_b, {'duplicator': 'runtime_contract_evidence_captured'}))

    return out

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--repository-evidence', required=True)
    ap.add_argument('--runtime-fixture', required=True)
    ap.add_argument('--output-dir', required=True)
    args = ap.parse_args()

    repo_evidence = Path(args.repository_evidence)
    base = load(args.runtime_fixture)
    policy = load(POLICY_PATH)
    if policy.get('contract') != 'mad4b.functional-gap-policy.v1':
        raise SystemExit('functional-gap parity policy contract mismatch')
    outdir = Path(args.output_dir)
    outdir.mkdir(parents=True, exist_ok=True)

    manifest = {'contract': 'mad4b.functional-gap-evaluator-parity-matrix.v1', 'cases': []}

    for name, runtime, expected in cases(base, policy):
        runtime_path = outdir / f'{name}.runtime.json'
        python_path = outdir / f'{name}.python.json'
        write(runtime_path, runtime)

        subprocess.run([
            sys.executable, str(PY_EVALUATOR),
            '--repository-evidence', str(repo_evidence),
            '--runtime-diagnostic', str(runtime_path),
            '--output', str(python_path),
        ], check=True)

        subprocess.run([
            'php', str(PHP_PARITY),
            str(repo_evidence), str(runtime_path), str(python_path),
        ], check=True)

        result = load(python_path)
        observed = {family: decision_state(result, family) for family in expected}
        if observed != expected:
            raise SystemExit(f'{name}: expected states {expected}, got {observed}')

        manifest['cases'].append({
            'name': name,
            'expected': expected,
            'observed': observed,
            'decision_fingerprint': result.get('decision_fingerprint', ''),
            'runtime_evidence_fingerprint': result.get('runtime_evidence_fingerprint', ''),
            'parity': 'pass',
        })

    by_name = {row['name']: row for row in manifest['cases']}
    census_a = by_name.get('runtime-only-census-a', {}).get('decision_fingerprint', '')
    census_b = by_name.get('runtime-only-census-b', {}).get('decision_fingerprint', '')
    if len(census_a) != 64 or len(census_b) != 64 or census_a == census_b:
        raise SystemExit('runtime-only metadata census drift did not change decision fingerprint')

    write(outdir / 'matrix.json', manifest)
    print(f"mad4b.functional-gap-evaluator-parity-matrix.v1: PASS cases={len(manifest['cases'])}")

if __name__ == '__main__':
    main()
