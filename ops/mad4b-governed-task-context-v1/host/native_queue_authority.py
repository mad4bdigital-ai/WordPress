#!/usr/bin/env python3
"""Read-only native WordPress Queue admission probe for MAD4B's SSH operator.

This reports the *current* exact VPS Runner eligibility, not a new grant.
Never claims an mcp_client task or derives WordPress authority from root alone.
"""
import json
import subprocess

CONTRACT = 'mad4b.native-wordpress-queue-authority.v1'
TARGETS = {
    ('tours-central', 'staging'): {
        'container': 'etg-commerce-staging-php',
        'origin': 'https://tours.dev.mad4b.com',
        'wp_actor': 1,
    },
}

def probe(policy, company='tours-central', environment='staging', runner=subprocess.run):
    result = {
        'contract': CONTRACT, 'company': company, 'environment': environment,
        'wordpress_queue_execution_allowed': False,
        'state': 'NOT_EVALUATED',
        'claim_scope': 'vps_runner_assigned_tasks_only',
        'mcp_client_claim_allowed': False,
        'execution_performed': False,
        'source': 'native_wpcli_ability_and_queue_status',
    }
    scope = (policy.get('scopes') or {}).get(company + ':' + environment, {})
    if policy.get('revoked') is True or not (
        scope.get('allow_operator') is True and
        scope.get('allow_mutation') is True
    ):
        result['state'] = 'HOST_SCOPE_NOT_GRANTED'
        return result
    target = TARGETS.get((company, environment))
    if target is None:
        result['state'] = 'WORDPRESS_TARGET_PRINCIPAL_NOT_ENROLLED'
        return result
    base = ['/usr/bin/docker', 'exec', '-u', 'www-data', target['container'],
            'wp', '--path=/var/www/html', '--user=' + str(target['wp_actor']),
            'mad4b', 'hub']
    try:
        checks = []
        for command in (['fallback', 'queue-list'], ['bulk', 'status']):
            call = runner(base + command, capture_output=True, text=True,
                          timeout=15, check=False)
            if call.returncode != 0 or len(call.stdout) > 2_000_000:
                result['state'] = 'WORDPRESS_QUEUE_PROBE_FAILED'
                return result
            checks.append(json.loads(call.stdout))
        identity, queue = checks
    except (OSError, subprocess.TimeoutExpired, ValueError, TypeError):
        result['state'] = 'WORDPRESS_QUEUE_PROBE_UNAVAILABLE'
        return result
    if (identity.get('authenticated_via') != 'verified_wordpress_wpcli' or
        identity.get('chatgpt_oauth_verified') is not False or
        identity.get('site_origin') != target['origin'] or
        identity.get('site_environment') != environment or
        identity.get('actor_wp_user_id') != target['wp_actor'] or
        identity.get('ability') != 'mad4b/hub-bulk-list_queue'):
        result['state'] = 'WORDPRESS_PRINCIPAL_OR_ORIGIN_MISMATCH'
        return result
    if (queue.get('contract') != 'mad4b.hub.bulk-vps-runner.v1' or
        queue.get('registered_executor') != 'vps_runner' or
        queue.get('wordpress_queue_execution_allowed') is not True or
        queue.get('queue_authorization_source') !=
            'verified_wpcli_actor_and_native_queue_policy' or
        queue.get('claim_scope') != 'vps_runner_assigned_tasks_only' or
        queue.get('mcp_client_task_claim_allowed') is not False):
        result['state'] = 'WORDPRESS_QUEUE_RUNNER_NOT_AUTHORIZED'
        return result
    result['wordpress_queue_execution_allowed'] = True
    result['state'] = 'VERIFIED_VPS_ASSIGNED_QUEUE_LANE'
    return result
