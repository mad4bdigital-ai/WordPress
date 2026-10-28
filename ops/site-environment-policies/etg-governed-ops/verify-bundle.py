#!/usr/bin/env python3
"""Static source/runtime provenance and anti-execution check; no WP side effects."""
import argparse
import hashlib
import json
from pathlib import Path
p=argparse.ArgumentParser()
p.add_argument('--source',action='store_true')
p.add_argument('--no-dispatch',action='store_true')
p.add_argument('--runtime-mu')
p.add_argument('--runtime-cli')
p.add_argument('--runtime-service')
a=p.parse_args()
root=Path(__file__).resolve().parent
manifest=json.loads((root/'bundle-manifest.json').read_text())
assert manifest['contract']=='mad4b.etg-governed-ops-bundle.v1'
for f,expected in manifest['files'].items():
    file=root/f
    assert hashlib.sha256(file.read_bytes()).hexdigest()==expected,f+' source drift'
    if a.runtime_service and f.endswith('.service'):
        target=Path(a.runtime_service)/f
        assert target.is_file() and not target.is_symlink()
        assert hashlib.sha256(target.read_bytes()).hexdigest()==expected,f+' systemd runtime drift'
    if a.runtime_mu and f.startswith('015-'):
        target=Path(a.runtime_mu)/f
        assert target.is_file() and not target.is_symlink()
        assert hashlib.sha256(target.read_bytes()).hexdigest()==expected,f+' runtime drift'
    if a.runtime_cli and f in ('governed-cron-plan.php','mad4b-governed-cron-runner.php'):
        renamed='governed-cron-runner.php' if f=='mad4b-governed-cron-runner.php' else f
        target=Path(a.runtime_cli)/renamed
        assert target.is_file() and not target.is_symlink()
        assert hashlib.sha256(target.read_bytes()).hexdigest()==expected,f+' runtime drift'
if a.no_dispatch:
    service=(root/'mad4b-etg-staging-cron-preview.service').read_text()
    assert '--preview' in service and '--execute' not in service and '--apply' not in service
    assert '[Timer]' not in service and 'OnCalendar=' not in service
    script=(root/'mad4b-governed-cron-runner.php').read_text()
    logic=(root/'governed-cron-plan.php').read_text()
    for forbidden in ('do_action(', 'do_action_ref_array(', 'wp_unschedule_event(', 'wp_cron(', 'wp_mail(', 'spawn_cron('):
        assert forbidden not in script and forbidden not in logic,forbidden
    assert "in_array('--execute',$argv,true)" in script
    assert 'EXECUTION_LOCKED' in script
print('MAD4B_ETG_GOVERNED_OPS_BUNDLE: PASS')
