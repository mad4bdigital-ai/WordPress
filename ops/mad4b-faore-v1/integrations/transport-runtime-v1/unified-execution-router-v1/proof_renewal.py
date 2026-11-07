#!/usr/bin/env python3
"""Refresh existing per-company staging P-256 identity with the original native
host principal; no WordPress user injection, grant, or tenant mutation.

Runs inside the existing dispatch telemetry cycle. The original status contract
is retained for current channel-governance consumers.
"""
import datetime as dt
import json
import os
import subprocess
import tempfile
import time
from pathlib import Path
import sys

ROOT = Path('/opt/mad4b-company-transport/unified-execution-router-v1')
STATUS = ROOT / 'signed-site-proof-renewal-status.json'
CONTRACT = 'mad4b.are-staging-signed-proof-renewal.v1'  # compatibility
SOURCE = Path('/opt/mad4b-company-transport')
sys.path.insert(0, str(SOURCE))
from central_wpcli_runtime import central_wp


def run():
    if os.geteuid() != 0:
        raise RuntimeError('NATIVE_ROOT_REQUIRED')
    # The explicitly enrolled Central runtime is the only target of this call.
    # refresh-all-due obtains the registered staging company identities inside WP.
    enrolled = None
    for attempt in range(4):
        try:
            enrolled = central_wp()
            break
        except RuntimeError as exc:
            if str(exc) not in ('CENTRAL_WORDPRESS_BUSY','STAGING_WPCLI_BUSY'):
                raise
            if attempt == 3:
                return {'contract': CONTRACT, 'state': 'DEFERRED_RETRYABLE_WPCLI_BUSY',
                        'verified': False, 'reason': 'CENTRAL_WORDPRESS_BUSY',
                        'wordpress_user_required': False,
                        'grants_created': 0, 'production_mutation': False}
            time.sleep(2 * (attempt + 1))
    if any(arg == '--user' or arg.startswith('--user=') for arg in enrolled):
        raise RuntimeError('WORDPRESS_USER_IMPERSONATION_NOT_ALLOWED')
    if (len(enrolled)<8 or enrolled[:4] !=
        ['/usr/bin/docker','exec','-u','www-data']
        or enrolled[5] != '/usr/local/bin/wp'
        or '--path=/var/www/html' not in enrolled):
        raise RuntimeError('NATIVE_ROOT_CENTRAL_RUNTIME_NOT_ENROLLED')
    # The web-serving www-data principal is not a host operator. Use a
    # privileged Docker exec on the same enrolled container and site only.
    command = enrolled[:3] + ['root'] + enrolled[4:6] + ['--allow-root'] + enrolled[6:]
    command += ['mad4b', 'environment-signed-v4-native', 'refresh-all-due']
    if any(arg == '--user' or arg.startswith('--user=') for arg in command):
        raise RuntimeError('WORDPRESS_USER_IMPERSONATION_NOT_ALLOWED')
    proc = subprocess.run(command, capture_output=True, text=True, timeout=100)
    if proc.returncode:
        return {'contract': CONTRACT, 'state': 'RETRY_SIGNED_IDENTITY',
                'verified': False, 'reason': 'NATIVE_V4_REFRESH_UNAVAILABLE',
                'wordpress_user_required': False}
    try:
        data = json.loads(proc.stdout.strip().splitlines()[-1])
        if not isinstance(data, dict):
            raise ValueError('INVALID_RESPONSE')
        if 'targets' in data:
            if data.get('contract') != 'mad4b.environment.native-v4-refresh.v1' or not isinstance(data['targets'], list):
                raise ValueError('WRONG_CONTRACT')
            targets = data['targets']
            valid = bool(data.get('all_verified')) and bool(targets)
            if any(not isinstance(row, dict)
                   or row.get('centrally_verified') is not True
                   or row.get('environment') != 'staging'
                   for row in targets):
                valid = False
            return {'contract': CONTRACT, 'state': 'CURRENT' if valid else 'RETRY_SIGNED_IDENTITY',
                    'verified': valid, 'targets': targets,
                    'staging_target_count': len(targets),
                    'wordpress_user_required': False,
                    'grants_created': 0, 'production_mutation': False}
        # Keep older status consumers/test fixtures readable, never authorized.
        if data.get('state') in ('CURRENT', 'SIGNED_V4_CENTRAL_PROOF_CURRENT') and data.get('verified') is True:
            return {'contract': CONTRACT, 'state': data['state'], 'verified': True,
                    'wordpress_user_required': False,
                    'grants_created': 0, 'production_mutation': False}
    except (ValueError, IndexError, TypeError, KeyError):
        pass
    return {'contract': CONTRACT, 'state': 'RETRY_SIGNED_IDENTITY',
            'verified': False, 'reason': 'INVALID_SIGNED_SITE_PROOF_STATUS',
            'wordpress_user_required': False}


def main():
    result = run()
    result['checked_at'] = dt.datetime.now(dt.timezone.utc).isoformat()
    fd, path = tempfile.mkstemp(prefix='.v4-renew-', dir=ROOT)
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump(result, f, sort_keys=True, indent=2)
            f.write('\n')
            f.flush()
            os.fsync(f.fileno())
        os.chmod(path, 0o600)
        os.replace(path, STATUS)
    finally:
        if os.path.exists(path):
            os.unlink(path)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
