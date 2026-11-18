#!/usr/bin/env python3
"""Permanent source boundary: Central must never own remote site Cron jobs."""
from pathlib import Path
import json,sys
root=Path(sys.argv[1]);ops=root/'ops/host-aware-cron-20261117';plugin=root/'wp-content/mu-plugins/mad4b-site-local-cron-runtime-v1.php';doc=root/'ops/site-local-cron-runtime-20261117/README.md'
assert plugin.is_file() and doc.is_file()
assert not (ops/'hostinger-ssh-cron-dispatch.py').exists()
assert not list((ops/'systemd').glob('mad4b-hostinger-ssh-cron@*'))
s=plugin.read_text()
assert "'central_remote_cron_dispatch_allowed' => false" in s
assert "'execution_owner' => 'site_host_runtime'" in s
assert "'host_native_reconciliation_pending' => true" in s
assert "'native_host_runner_attested' => false" in s
assert "'wordpress_core_request_driven'" in s
assert 'update_option(' not in s and 'wp_remote_post(' not in s
assert 'wp_next_scheduled(' in s and 'action_scheduler_run_queue' in s
print('PASS 10/10 no central execution and site-owned WP-Cron boundaries')
