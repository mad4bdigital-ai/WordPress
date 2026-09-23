#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
diag = (ROOT / 'tests/runtime-functional-gap-diagnostic.php').read_text('utf-8')

required = [
    "Deprecated compatibility wrapper for zero-touch functional-gap evidence",
    "mad4b/functional-gap-runtime-evidence",
    "MAD4B_SCP_Functional_Gap_Evidence::snapshot()",
    "mad4b.functional-gap-zero-touch.v1",
    "mad4b.runtime-functional-gap-evidence.v2",
    "'promotion_authorized'] = false",
    "zero_touch_snapshot_identity_sha256",
    "repository_evidence_valid",
]
for marker in required:
    if marker not in diag:
        raise SystemExit(f'missing compatibility-wrapper invariant: {marker}')

for forbidden in [
    "get_plugins(",
    "RecursiveDirectoryIterator",
    "hash_file(",
    "rest_get_server(",
    "_get_cron_array(",
    "get_option( 'wpl_",
    "update_option(",
    "add_option(",
    "delete_option(",
    "update_post_meta(",
    "delete_post_meta(",
    "wp_remote_get(",
    "wp_remote_post(",
    "wp_remote_request(",
    "$wpdb->query(",
    "$wpdb->insert(",
    "$wpdb->update(",
    "$wpdb->delete(",
    "activate_plugin(",
    "deactivate_plugins(",
]:
    if forbidden in diag:
        raise SystemExit(f'compatibility wrapper reintroduced independent runtime logic: {forbidden}')

print('mad4b.runtime-functional-gap-diagnostic-wrapper.contract.v2: PASS')
