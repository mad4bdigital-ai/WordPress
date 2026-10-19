#!/usr/bin/env python3
"""Admit only append-only CI reviews and source-derived bootstrap fingerprints."""
import argparse
import copy
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[4]
COVERAGE = 'governance/production-readiness/capability-coverage.json'
BOOT = 'wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'
DELIVERIES = {f'specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/g{i}-delivery.json' for i in range(2, 10)}

def require(value, reason):
    if not value:
        raise ValueError(reason)

def reviews_only(old, new):
    before, after = copy.deepcopy(old), copy.deepcopy(new)
    for field, keys in [('reviewed_capabilities', ('id',)), ('reviewed_changes', ('path', 'source_blob_sha'))]:
        a, b = before.pop(field), after.pop(field)
        prior = {tuple(x[k] for k in keys): x for x in a}
        current = {tuple(x[k] for k in keys): x for x in b}
        require(len(current) == len(b) and all(current.get(k) == v for k, v in prior.items()), 'historical_review_changed')
        for row in b:
            require(row.get('status') == 'reviewed_for_ci_only' and row.get('production_authorized') is False
                    and row.get('live_evidence_required') is True and row.get('owner_role')
                    and row.get('regression_tests') and row.get('control_ids'), 'authorizing_or_incomplete_review')
    require(before == after and new.get('authorizing') is False and new.get('production_promotion_from_ci') is False,
            'coverage_policy_change_forbidden')

def fingerprints_only(old, new, source):
    before, after = copy.deepcopy(old), copy.deepcopy(new)
    for group in ('code_paths', 'test_paths', 'spec_paths', 'workflow_paths', 'evidence_integrity'):
        a, b = before.get(group, []), after.get(group, [])
        require(len(a) == len(b), 'delivery_inventory_changed')
        for left, right in zip(a, b):
            if left == right:
                continue
            require(isinstance(left, dict) and isinstance(right, dict) and left.get('path') == BOOT and right.get('path') == BOOT,
                    'unexpected_delivery_binding_change')
            raw = source(BOOT)
            require(right.get('sha256') == hashlib.sha256(raw).hexdigest(), 'delivery_hash_not_source_derived')
            if 'bytes' in right:
                require(right['bytes'] == len(raw), 'delivery_size_not_source_derived')
            for field in ('sha256', 'bytes'):
                left.pop(field, None)
                right.pop(field, None)
    require(before == after, 'delivery_metadata_or_acceptance_changed')

def self_test():
    row = dict(id='fixture', status='reviewed_for_ci_only', production_authorized=False,
               live_evidence_required=True, owner_role='CI reviewer', regression_tests=['test'], control_ids=['control'])
    old = dict(authorizing=False, production_promotion_from_ci=False, guarantees={'strict': True}, reviewed_capabilities=[], reviewed_changes=[])
    new = copy.deepcopy(old); new['reviewed_capabilities'].append(row); reviews_only(old, new)
    def denied(callback):
        try:
            callback()
        except ValueError:
            return
        raise AssertionError('unsafe evidence scope accepted')
    bad = copy.deepcopy(new); bad['guarantees']['strict'] = False; denied(lambda: reviews_only(old, bad))
    bad = copy.deepcopy(new); bad['reviewed_capabilities'][0]['production_authorized'] = True; denied(lambda: reviews_only(old, bad))
    denied(lambda: reviews_only(new, old))
    a = {'accepted': False, 'code_paths': [{'path': BOOT, 'sha256': 'old', 'bytes': 3}]}
    b = copy.deepcopy(a); b['code_paths'][0].update(sha256=hashlib.sha256(b'new').hexdigest(), bytes=3)
    fingerprints_only(a, b, lambda path: b'new')
    bad = copy.deepcopy(b); bad['accepted'] = True; denied(lambda: fingerprints_only(a, bad, lambda path: b'new'))
    bad = copy.deepcopy(b); bad['code_paths'][0]['sha256'] = 'forged'; denied(lambda: fingerprints_only(a, bad, lambda path: b'new'))
    print('PASS evidence scope rejects policy/approval/history/acceptance/hash forgery')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base'); parser.add_argument('--head'); parser.add_argument('--self-test', action='store_true')
    args = parser.parse_args()
    if args.self_test:
        self_test(); return
    require(args.base and args.head, 'exact_refs_required')
    def raw(ref, path):
        return subprocess.check_output(['git', 'show', ref + ':' + path], cwd=ROOT)
    paths = subprocess.check_output(['git', 'diff', '--name-only', args.base, args.head], cwd=ROOT).decode().splitlines()
    for path in paths:
        if path == COVERAGE:
            reviews_only(json.loads(raw(args.base, path)), json.loads(raw(args.head, path)))
        elif path in DELIVERIES:
            fingerprints_only(json.loads(raw(args.base, path)), json.loads(raw(args.head, path)), lambda source: raw(args.head, source))
    print('PASS narrow ETG integration evidence scope; no grants, policy changes or acceptance promotion')

if __name__ == '__main__':
    main()
