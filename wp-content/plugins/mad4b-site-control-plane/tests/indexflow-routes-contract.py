#!/usr/bin/env python3
"""Run behavioral denial/persistence suites and review the owned registration scope."""
import json
from pathlib import Path
import subprocess
import shutil

plugin = Path(__file__).resolve().parents[1]
repo = plugin.parents[2]
php = shutil.which('php')
subprocess.run(['python3', str(plugin / 'tests/etg-integration-evidence-scope.py'), '--self-test'], check=True)
if not php:
    raise SystemExit('PHP is required for real route-storage behavioral assertions')
for test in [plugin / 'tests/indexflow-routes-adapter-smoke.php',
             plugin.parent / 'etg-dynamic-filter-seo-bridge/tests/indexflow-route-storage-smoke.php']:
    subprocess.run([php, str(test)], check=True)
policy = json.loads((plugin / 'config/adapter-registration-policy.json').read_text())
name = 'MAD4B_SCP_IndexFlow_Routes_Adapter'
assert name in policy['approved_classes'] and name in policy['registered_hook_classes']
assert name not in policy['default_classes']  # explicit owned hook, no default auto-enrollment
operations = json.loads((plugin / 'config/operation-registry.json').read_text())['operations']
row = next(x for x in operations if x['id'] == 'wordpress.indexflow.routes.apply')
assert row['planner'] == 'indexflow/routes-plan' and row['executor'] == 'indexflow/routes-apply'
assert row['owner_confirmation_required'] is True and row['production_allowed'] is False
assert row['generic_mutation_dispatch'] is False and row['retry_semantics'] == 'readback_before_retry'
source = (plugin / 'includes/adapters/class-mad4b-scp-indexflow-routes-adapter.php').read_text()
for guard in ['mutation_requires_certification() { return true;', "mutation_guard('indexflow-routes', true)",
              'current_mutation_context()', 'acquire_reversible_scope', 'restore_reversible_state',
              'expected_dependencies_sha256', 'expected_migration_sha256']:
    assert guard in source, guard
for forbidden in ['flush_rewrite_rules(', 'wp_insert_post(', 'wp_remote_request(', 'shell_exec(', 'eval(']:
    assert forbidden not in source, forbidden
assert 'class-mad4b-scp-indexflow-routes-adapter.php' in (plugin / 'mad4b-site-control-plane.php').read_text()
print('PASS IndexFlow separate scope: plan/apply/readback/undo, provider certification, no activation')
