"""Read-only observation of Original Broker 306 pending / 312 claimed queues.

No claims, grants, new jobs, retries, signatures or 313 receipts are produced.
"""
import json
import sys
from typing import Callable

from stdio_scope import central_target, run


def _bounded_cli(container: str, actor: int, words: list[str], *,
                 runner: Callable = run) -> dict:
    if type(actor) is not int or actor < 1:
        raise ValueError('ORIGINAL_BROKER_ACTOR_REQUIRED')
    response = runner(['docker', 'exec', '-u', 'www-data', container, 'wp',
                       '--path=/var/www/html', '--skip-themes',
                       '--user='+str(actor), 'mad4b']+words, timeout=35)
    try:
        record = json.loads(response.strip().splitlines()[-1])
    except (IndexError, ValueError):
        raise RuntimeError('ORIGINAL_BROKER_JSON_INVALID')
    if not isinstance(record, dict):
        raise RuntimeError('ORIGINAL_BROKER_DOCUMENT_INVALID')
    return record


def inspect(*, discover: Callable = central_target,
            actor_resolver: Callable | None = None,
            runner: Callable = run) -> dict:
    if actor_resolver is None:
        sys.path.insert(0, '/opt/mad4b-company-transport')
        from wpcli_dynamic_actor import resolve_actor as enrolled_actor
        actor_resolver = enrolled_actor
    target = discover('staging')
    actor = actor_resolver()
    pending = _bounded_cli(target['container'], actor,
                           ['broker', 'pending', '--limit=10'], runner=runner)
    claimed = _bounded_cli(target['container'], actor,
                           ['broker-authority', 'claimed', '--limit=10'],
                           runner=runner)
    if (pending.get('contract') != 'mad4b.central-vps-broker.v1'
        or claimed.get('contract') != 'mad4b.broker-exact-ssh-authority.v1'
        or not isinstance(pending.get('pending'), list)
        or not isinstance(claimed.get('claimed'), list)
        or len(pending['pending']) > 10
        or len(claimed['claimed']) > 10):
        raise RuntimeError('ORIGINAL_BROKER_CONTRACT_UNVERIFIED')
    return {
        'contract': 'mad4b.faore.original-313-queue-inspection.v1',
        'site_uuid': target['site_uuid'],
        'pending_original_306': sum(isinstance(x, dict) for x in pending['pending']),
        'claimed_original_312': sum(isinstance(x, dict) for x in claimed['claimed']),
        'status': 'READ_ONLY_QUEUE_INSPECTED',
        'original_313_verified': False,
        'new_jobs_created': False,
        'claims_issued': False,
        'mutation_performed': False,
    }


if __name__ == '__main__':
    try:
        print(json.dumps(inspect(), sort_keys=True))
    except Exception as exc:
        print(json.dumps({
            'status': 'BROKER_QUEUE_INSPECTION_UNAVAILABLE',
            'reason': str(exc)[:100],
            'mutation_performed': False,
            'original_313_verified': False,
        }, sort_keys=True))
        sys.exit(2)
