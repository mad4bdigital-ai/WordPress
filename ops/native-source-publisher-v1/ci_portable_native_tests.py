#!/usr/bin/env python3
"""Discover portable Native Source tests; never convert missing Host receipts to CI PASS.

A GitHub runner has source code and the offline signed PHP test image, not the
root-owned Original305/306/312/313 enrolled stores or their trusted receipts.
The latter are tested on an authorized Host and remain a separate release gate.
New test modules are included automatically, and every Host-only exception is
explicitly audited by filename and rationale below (not by operation ID).
"""
from __future__ import annotations
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
TESTS = ROOT / 'tests'
HOST_BOUND_TESTS = {
    'test_native_source_associated_worker_index.py': 'Original306 enrolled worker and signature verifier on host',
    'test_native_source_historical_finish_correlation.py': 'historical Original305 signed Host job receipt store',
    'test_native_source_historical_grants.py': 'original root-owned 312 historical signed Grant store',
    'test_native_source_immutable_plan_v25.py': 'Original306 installed CAS validator and grants',
    'test_native_source_legacy_migration_preview.py': 'first-party legacy Original313 plan store and installed Registry',
    'test_native_source_operation_association.py': 'Original306 installed signed worker index',
    'test_native_source_plan_store_compatibility.py': 'Original306/313 installed original plan metadata',
    'test_native_source_preclaim_v24.py': 'root-owned original first-party grant and finalizer; root-only fixture permissions',
    'test_native_source_readback_provenance.py': 'historical original Git object not guaranteed in remote PR shallow lineage',
    'test_native_source_registry_candidate.py': 'Original313 installed first-party root-owned Registry',
    'test_native_source_registry_extension_v23.py': 'Original313 installed first-party Registry and enrolled public keys',
    'test_native_source_release_readiness_audit.py': 'live original 305/306/312/313 readback and Host worker evidence',
}

def main() -> int:
    available = {p.name for p in TESTS.glob('test_native_source*.py')}
    missing = set(HOST_BOUND_TESTS) - available
    if missing:
        raise RuntimeError('HOST_TEST_CONTRACT_FILE_MISSING:' + ','.join(sorted(missing)))
    portable = sorted(available - set(HOST_BOUND_TESTS))
    if not portable:
        raise RuntimeError('NO_PORTABLE_NATIVE_SOURCE_TESTS')
    loader = unittest.TestLoader()
    suite = unittest.TestSuite()
    for name in portable:
        suite.addTests(loader.discover(str(TESTS), pattern=name, top_level_dir=str(TESTS)))
    count = suite.countTestCases()
    print(json.dumps({
        'contract': 'mad4b.native-source.portable-ci.v1',
        'source_test_modules_discovered': len(available),
        'portable_modules_tested': portable,
        'host_bound_modules_not_run_here': HOST_BOUND_TESTS,
        'portable_test_cases': count,
        'original_host_authority_verified': False,
        'original313_production_receipt_verified': False,
        'release_authorized': False,
        'state': 'SOURCE_ONLY__HOST_EVIDENCE_RELEASE_HOLD',
    }, sort_keys=True), flush=True)
    result = unittest.TextTestRunner(verbosity=1).run(suite)
    return 0 if result.wasSuccessful() else 1

if __name__ == '__main__':
    sys.exit(main())
