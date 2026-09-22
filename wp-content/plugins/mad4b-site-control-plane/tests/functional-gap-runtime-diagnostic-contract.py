#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
diag = (ROOT / 'tests/runtime-functional-gap-diagnostic.php').read_text('utf-8')

required = [
    "mad4b.runtime-functional-gap-diagnostic.v1",
    "'mutation_performed' => false",
    "'remote_request_performed' => false",
    "'secret_values_returned' => false",
    "'raw_sql_performed' => false",
    "$secret_pattern",
    "'wpl_access_token'",
    "'wpl_api_key'",
    "'rest_routes'",
    "'ajax_hooks'",
    "'option_presence'",
    "'cron_hooks'",
    "'plugin_tree'",
    "hash_file( 'sha256'",
    "RecursiveDirectoryIterator",
]
for marker in required:
    if marker not in diag:
        raise SystemExit(f'missing diagnostic invariant: {marker}')

for forbidden in [
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
    "wp_schedule_event(",
    "wp_clear_scheduled_hook(",
]:
    if forbidden in diag:
        raise SystemExit(f'functional-gap runtime diagnostic must remain read-only: {forbidden}')

if "['value']" in diag and "secret_pattern" not in diag:
    raise SystemExit('diagnostic may expose option values without redaction policy')

print('mad4b.runtime-functional-gap-diagnostic.contract.v1: PASS')
