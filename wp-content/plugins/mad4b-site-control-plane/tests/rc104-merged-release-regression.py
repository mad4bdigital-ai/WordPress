#!/usr/bin/env python3
"""Executable CI regression for reconciled Staging rc.104 source only.

Not a release attestation or live Staging authorization.
"""
from pathlib import Path
import re
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'mad4b-site-control-plane.php'
INCLUDES = ROOT / 'includes'
RUNTIME_FIXTURE = ROOT / 'tests/approval-plan-route-preflight-runtime.php'
TARGET = '0.4.0-rc.104'


def require(ok, message):
    if not ok:
        raise ValueError(message)


def run(args):
    p = subprocess.run(args, cwd=ROOT, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, text=True, timeout=45, check=False)
    require(p.returncode == 0, 'PHP runtime regression failed: ' +
            p.stdout[-800:] + '\n' + p.stderr[-800:])
    return p.stdout


def main():
    source = PLUGIN.read_text()
    require(re.search(r'(?m)^\s*\* Version: 0\.4\.0-rc\.104\s*$', source),
            'WordPress plugin header did not advance')
    require("define( 'MAD4B_SCP_VERSION', '" + TARGET + "' );" in source,
            'Plugin runtime version is inconsistent')
    require(source.count('0.4.0-rc.103') == 0, 'Old plugin version retained')
    router = (INCLUDES/'class-mad4b-scp-adaptive-operation-router.php').read_text()
    guard = (INCLUDES/'class-mad4b-scp-staging-write-planning-guard.php').read_text()
    require('approval_plan_execution_preflight' in router, 'Merged live preflight missing')
    require('true !== MAD4B_SCP_Policy::can_mutate()' in router,
            'Fail-closed write policy test not retained')
    require('mad4b_remote_plan_server_denied' in guard,
            'Wrong-server denial lost during merge')
    php = shutil.which('php')
    require(php is not None, 'Trusted PHP runner missing')
    for f in (PLUGIN, INCLUDES/'class-mad4b-scp-adaptive-operation-router.php',
              INCLUDES/'class-mad4b-scp-staging-write-planning-guard.php', RUNTIME_FIXTURE):
        run([php, '-l', str(f)])
    outcome = run([php, str(RUNTIME_FIXTURE)])
    require('APPROVAL_PLAN_ROUTE_PREFLIGHT_RUNTIME=PASS' in outcome,
            'Real PHP approval-route regression missing PASS marker')
    wpcli = ROOT / 'tests/wpcli-passive-gate-contract.php'
    require(wpcli.is_file(), 'Merged passive WP-CLI source fixture is absent')
    wpcli_out = run([php, str(wpcli)])
    require('mad4b.wpcli-passive-gate.v1: PASS' in wpcli_out,
            'Real PHP passive WP-CLI contract missing PASS marker')
    print('RC104_INTEGRATED_SOURCE_AND_FAIL_CLOSED_APPROVAL=PASS')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.TimeoutExpired) as exc:
        print('RC104_INTEGRATED_SOURCE=BLOCKED:', exc, file=sys.stderr)
        raise SystemExit(1)
