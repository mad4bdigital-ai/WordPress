#!/usr/bin/env python3
"""Bounded source/runtime/UI regression inventory for the PR423 audit findings.

Hermetic fixtures only; no live site or certification authority is created.
Missing executors are a failed check, never an implicit skip/PASS.
"""
from pathlib import Path
import ast
import gettext
import json
import re
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / 'tests'
REPO = ROOT.parents[2]
SOURCE = [
    'admin-action-notice-source-contract.py', 'admin-post-and-route-boundary-source-contract.py',
    'brand-context-reconstruction-source-contract.py', 'business-activity-sync-runtime-contract.py',
    'enrollment-skills-preflight-contract.py', 'imp03-governed-import-contract.py',
    'imp06-import-ux-source-contract.py', 'admin-workspace-contract.py',
    'cross-plugin-artifact-builder-contract.py', 'cross-plugin-source-matrix-contract.py',
    'cross-plugin-workflow-dynamic-contract.py', 'selected-head-update-contract.py',
    'self-update-staging-candidate-contract.py',
]
RUNTIME = [
    'structural-redaction-runtime.php', 'self-update-source-lineage-runtime.php',
    'self-update-readback-runtime.php', 'self-update-continuation-policy-runtime.php',
    'self-update-candidate-drift-bootstrap-runtime.php', 'self-update-auto-update-state-runtime.php',
    'self-update-pointer-runtime.php', 'selected-head-update-runtime.php',
    'optional-governance-ability-registration-runtime.php', 'plugin-update-popup-runtime.php',
    'cross-plugin-popup-inline-runtime.php', 'cross-plugin-deployment-runtime.php',
    'cross-plugin-lifecycle-gates-runtime.php', 'cross-plugin-admin-environment-runtime.php',
    'admin-workspace-runtime.php', 'enrollment-skills-preflight-runtime.php',
    'brand-context-reconstruction-state-machine-runtime.php', 'business-activity-sync-runtime.php',
    'imp06-import-ux-runtime.php',
]

def main():
    php, node = shutil.which('php'), shutil.which('node')
    if not php or not node:
        raise RuntimeError('PHP and Node are required; this suite cannot be silently skipped')
    commands = [[sys.executable, str(TESTS / name)] for name in SOURCE]
    commands += [[php, str(TESTS / name)] for name in RUNTIME]
    commands += [[node, str(TESTS / 'plugin-update-dialog-runtime.cjs')]]
    results = []
    for command in commands:
        run = subprocess.run(command, cwd=REPO, capture_output=True, text=True, timeout=90)
        results.append({'test': Path(command[-1]).name, 'exit': run.returncode})
        print(run.stdout, end='')
        if run.returncode:
            print(run.stderr, file=sys.stderr)
    catalog = gettext.GNUTranslations((ROOT / 'languages/mad4b-site-control-plane-ar.mo').open('rb'))
    popup = (ROOT / 'includes/class-mad4b-scp-plugin-update-popup.php').read_text(encoding='utf8')
    messages = [ast.literal_eval("'" + value + "'") for value in re.findall(
        r"(?:__|esc_html__|esc_attr__)\(\s*'((?:\\.|[^'\\])*)'", popup)]
    missing = sorted({value for value in messages if catalog.gettext(value) == value})
    # Verify actual lifecycle wiring as well as the pure lineage fixture.
    updater = (ROOT / 'includes/class-mad4b-scp-self-update.php').read_text(encoding='utf8')
    for method, end in [('handle_refresh_update', 'handle_native_update'),
                        ('handle_native_update', 'native_host_setup_notice'),
                        ('run_recovery_update', 'persist_recovery_status')]:
        section = updater.split('public static function ' + method + '(', 1)[1]
        if end != 'persist_recovery_status':
            section = section.split('public static function ' + end + '(', 1)[0]
        else:
            section = section.split('private static function persist_recovery_status(', 1)[0]
        if 'self::native_release_state( $manifest, true )' not in section:
            raise AssertionError('Explicit/eligible update path did not refresh exact ancestry: ' + method)
    if missing:
        raise AssertionError('Popup Arabic translations missing: ' + repr(missing))
    replacement = updater.split('private static function apply_verified_archive(', 1)[1]
    admission = replacement.index('self::native_replacement_admission( $target, $channel )')
    if not replacement.index('MAD4B_SCP_Runtime_Maintenance_Lease::acquire(') < admission < replacement.index('self::backup_current()'):
        raise AssertionError('Native ancestry must be checked under the lease before backup/continuation work')
    failed = [row for row in results if row['exit']]
    print(json.dumps({'contract': 'mad4b.post-review-audit.v1', 'tests': len(results),
                      'failed': failed, 'translated_messages': len(set(messages)),
                      'live_acceptance': False, 'authorizing': False}, sort_keys=True))
    return bool(failed)

if __name__ == '__main__':
    sys.exit(main())
