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

def remove_route(runtime, route):
    runtime['rest_routes'] = [row for row in runtime.get('rest_routes', []) if row.get('route') != route]

def wpl_redaction_fail(runtime):
    runtime.setdefault('option_presence', {}).setdefault('wpl_access_token', {})['redacted'] = False

def cases(base):
    out = []

    happy = copy.deepcopy(base)
    out.append(('happy', happy, {
        'bulk-taxonomy-editor': 'read_contract_candidate',
        'wpl-client': 'redacted_read_contract_candidate',
        'rank-math': 'contract_evidence_review',
        'duplicator': 'runtime_contract_evidence_captured',
        'jetengine': 'semantic_attestation_required',
        'wp-import-export': 'runtime_alignment_or_behavioral_recertification_required',
    }))

    route_missing = copy.deepcopy(base)
    remove_route(route_missing, '/bulk-taxonomy-editor/v1/posts')
    out.append(('bulk-route-missing', route_missing, {'bulk-taxonomy-editor': 'contract_discovery_required'}))

    bulk_drift = copy.deepcopy(base)
    set_tree_drift(bulk_drift, 'bulk-taxonomy-editor', 'a')
    out.append(('bulk-tree-drift', bulk_drift, {'bulk-taxonomy-editor': 'contract_discovery_required'}))

    wpl_redaction = copy.deepcopy(base)
    wpl_redaction_fail(wpl_redaction)
    out.append(('wpl-redaction-failure', wpl_redaction, {'wpl-client': 'contract_discovery_required'}))

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

    composite_inactive = copy.deepcopy(base)
    set_inactive(composite_inactive, 'wp-import-export')
    out.append(('composite-inactive', composite_inactive, {'wp-import-export': 'not_active'}))

    return out

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--repository-evidence', required=True)
    ap.add_argument('--runtime-fixture', required=True)
    ap.add_argument('--output-dir', required=True)
    args = ap.parse_args()

    repo_evidence = Path(args.repository_evidence)
    base = load(args.runtime_fixture)
    outdir = Path(args.output_dir)
    outdir.mkdir(parents=True, exist_ok=True)

    manifest = {'contract': 'mad4b.functional-gap-evaluator-parity-matrix.v1', 'cases': []}

    for name, runtime, expected in cases(base):
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

    write(outdir / 'matrix.json', manifest)
    print(f"mad4b.functional-gap-evaluator-parity-matrix.v1: PASS cases={len(manifest['cases'])}")

if __name__ == '__main__':
    main()
