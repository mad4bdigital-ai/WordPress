#!/usr/bin/env python3
"""Executable lazy-admin and serialized-client regression, without live authority."""
from pathlib import Path
import subprocess
ROOT = Path(__file__).resolve().parents[1]
for name in ('operator-control-center', 'chatgpt-connection-admin-ui', 'deferred-admin-reports'):
    subprocess.run(['php', '-l', str(ROOT / f'includes/class-mad4b-scp-{name}.php')], check=True)
subprocess.run(['php', str(ROOT / 'tests/deferred-admin-reports-runtime.php')], check=True, timeout=30)
subprocess.run(['node', str(ROOT / 'tests/deferred-admin-reports-browser.cjs')], check=True, timeout=30)
source = (ROOT / 'includes/class-mad4b-scp-operator-control-center.php').read_text()
assert "wp_ajax_mad4b_admin_report" in source
assert "wp_ajax_nopriv_mad4b_admin_report" not in source
print('DEFERRED_ADMIN_REPORTS_CONTRACT: PASS')
