"""Auto-renew bounded local MCP read evidence using the existing tested canary.

No OAuth, enqueue, broker job, write authorization or content export.
"""
import json
import sys
import time
from typing import Callable

from stdio_scope import central_target
from stdio_read_probe import run_once


def run_dynamic(*, discover: Callable = central_target,
                execute: Callable = run_once,
                sleeper: Callable = time.sleep) -> dict:
    for attempt in range(2):
        try:
            target = discover('staging')
            result = execute(
                target['site_uuid'], target['canonical_origin'], target['environment'],
                target=lambda uuid, origin, environment:
                    target['container'] if (
                        uuid == target['site_uuid'] and
                        origin == target['canonical_origin'] and
                        environment == target['environment']) else
                    _reject_scope())
            return dict(result, original_313_verified=False)
        except RuntimeError as exc:
            if attempt or str(exc) not in (
                "CENTRAL_WORDPRESS_BUSY", "MCP_STDIO_READ_SLOT_BUSY"
            ):
                raise
            sleeper(3)
    raise RuntimeError("MCP_READ_RETRY_EXHAUSTED")


def _reject_scope():
    raise RuntimeError('CENTRAL_SCOPE_CHANGED')


if __name__ == '__main__':
    try:
        print(json.dumps(run_dynamic(), sort_keys=True))
    except Exception as exc:
        print(json.dumps({
            'state': 'READ_PROBE_UNAVAILABLE',
            'reason': str(exc)[:110],
            'authorization_granted': False,
            'mutation_performed': False,
            'original_313_verified': False,
        }, sort_keys=True))
        sys.exit(2)
