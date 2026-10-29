#!/usr/bin/env python3
"""Read-only Coolify CLI inventory for staging registration. Never prints tokens/env values.

Run on a workstation/runner with Coolify CLI already authenticated:
  python3 coolify_readonly_inventory.py --host tours.dev.mad4b.com --host qa02.example.com

Only commands issued: coolify context verify; coolify resource/app/service/server list.
No installation, no deploy, no environment access, no API mutation or SSH.
"""
import argparse
import json
import re
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from urllib.parse import urlsplit

ALLOWED = (
    ('context', 'verify'),
    ('resource', 'list'),
    ('app', 'list'),
    ('service', 'list'),
    ('server', 'list'),
)
CLI_TIMEOUT = 18


def cmd(cmd_argv, *, timeout=CLI_TIMEOUT, context=''):
    if tuple(cmd_argv[1:3]) not in ALLOWED:
        raise ValueError('Command outside read-only allowlist')
    # A named Coolify context is required on hosts whose default is an
    # unrelated cloud account. Keep allowlist validation on the original
    # command, before injecting the context argument.
    if context and not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,63}', context):
        raise ValueError('Invalid Coolify context name')
    args = [cmd_argv[0]]
    if context:
        args.extend(['--context', context])
    args.extend(cmd_argv[1:])
    if tuple(args[1:3]) != ('context', 'verify'):
        args += ['--format', 'json']
    try:
        result = subprocess.run(args, capture_output=True, text=True, timeout=timeout, check=False)
        # Deliberately do not propagate stderr or stdout on failure; may contain credentials.
        return {'ok': result.returncode == 0, 'exit': result.returncode,
                'raw': result.stdout if result.returncode == 0 else ''}
    except (OSError, subprocess.TimeoutExpired):
        return {'ok': False, 'exit': None, 'raw': ''}


def resources_from_json(raw):
    try:
        data = json.loads(raw)
    except (ValueError, TypeError):
        return []
    out = []
    # Coolify resource listing can group objects by type or embed a data list.
    def walk(value, depth=0):
        if depth > 5:
            return
        if isinstance(value, list):
            for item in value[:300]:
                walk(item, depth + 1)
        elif isinstance(value, dict):
            if isinstance(value.get('uuid'), str):
                row = {key: value[key] for key in ('uuid', 'name', 'status', 'type')
                       if key in value and isinstance(value[key], str)}
                # domains are used solely for exact target-host matching; not returned publicly
                domains = [str(value.get(k) or '') for k in ('fqdn', 'domains', 'domain')]
                row['_domains'] = domains
                out.append(row)
            for key in ('data', 'applications', 'services', 'servers', 'resources',
                        'items', 'results', 'databases'):
                if key in value:
                    walk(value[key], depth + 1)
    walk(data)
    # Same resource can occur in multiple Coolify listings; keep every exact domain.
    uniq = {}
    for item in out:
        existing = uniq.setdefault(item['uuid'], item.copy())
        existing['_domains'] = list(dict.fromkeys(
            existing.get('_domains', []) + item.get('_domains', [])))
        for key in ('name','type','status'):
            if key not in existing and key in item:
                existing[key] = item[key]
    return list(uniq.values())


def matched_hosts(rows, hosts):
    matches = []
    for host in hosts:
        if not re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?', host):
            raise ValueError('Invalid public DNS host')
        hits = []
        for item in rows:
            for value in item.get('_domains', []):
                # Parse each configured URL; never match a hostname contained in
                # the URL's user-info, path, query or another longer hostname.
                for raw in value.split(','):
                    raw = raw.strip()
                    if not raw:
                        continue
                    candidate = urlsplit(raw if '://' in raw else 'https://' + raw)
                    if candidate.scheme == 'https' and candidate.username is None and \
                       candidate.password is None and (candidate.hostname or '').lower() == host:
                        hits.append(item['uuid'])
                        break
                if item['uuid'] in hits:
                    break
        matches.append({'hostname': host, 'matching_resource_uuids': sorted(set(hits)),
                        'registration': 'FOUND' if hits else 'NOT_CONFIRMED'})
    return matches


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--context', default='',
                        help='Explicit authorized Coolify context; never a token')
    parser.add_argument('--host', action='append', default=[],
                        help='Staging DNS hostname; do not provide secrets or IP addresses')
    parser.add_argument('--output', default='', help='Optional local JSON output pathname')
    args = parser.parse_args()
    report = {'contract': 'mad4b.rev143.coolify-readonly-registration.v1',
              'generated_utc': datetime.now(timezone.utc).isoformat(),
              'kind': 'READ_ONLY', 'source': 'Coolify CLI', 'commands': [],
              'coolify_context': args.context or 'default',
              'discovered_resources': [], 'matched_hosts': [],
              'shared_media_activation_performed': False, 'production_mutation': False,
              'status': 'BLOCKED'}
    if not shutil.which('coolify'):
        report['blockers'] = ['COOLIFY_CLI_NOT_INSTALLED_OR_NOT_IN_PATH']
    else:
        verification = cmd(['coolify', 'context', 'verify'], context=args.context)
        report['commands'].append({'command': 'coolify context verify', 'ok': verification['ok']})
        if not verification['ok']:
            report['blockers'] = ['COOLIFY_CONTEXT_NOT_VERIFIED']
        else:
            rows, eligible_domain_rows, failed = [], [], []
            for group, verb in ALLOWED[1:]:
                result = cmd(['coolify', group, verb], context=args.context)
                report['commands'].append({'command': f'coolify {group} {verb} --format json',
                                           'ok': result['ok']})
                if not result['ok']:
                    failed.append(group)
                else:
                    listed = resources_from_json(result['raw'])
                    rows.extend(listed)
                    # Domain evidence must belong to an app/service. A
                    # server's own fqdn never proves tenant registration.
                    if group in ('app', 'service'):
                        eligible_domain_rows.extend(listed)
                    elif group == 'resource':
                        eligible_domain_rows.extend(
                            row for row in listed
                            if row.get('type') in ('application', 'service'))
            # Inventory aggregation is intentionally conservative.
            combined = {}
            for row in rows:
                old = combined.setdefault(row['uuid'], row.copy())
                old['_domains'] = list(dict.fromkeys(
                    old.get('_domains', []) + row.get('_domains', [])))
            rows = list(combined.values())
            report['discovered_resources'] = [
                {key: val for key, val in row.items() if not key.startswith('_')}
                for row in rows]
            report['matched_hosts'] = matched_hosts(eligible_domain_rows, args.host)
            # Coolify CLI v1.10 list APIs may omit ALL domain fields. In that
            # case no host match is proof of anything: require independent
            # target-specific read-only evidence instead of a false negative.
            has_domain_metadata = any(
                any(v for v in row.get('_domains', [])) for row in eligible_domain_rows)
            report['domain_metadata_available'] = has_domain_metadata
            report['blockers'] = ([f'COOLIFY_INVENTORY_UNAVAILABLE:{name}' for name in failed]
                                  + (['COOLIFY_LIST_API_OMITS_DOMAINS']
                                     if args.host and eligible_domain_rows and not has_domain_metadata else [])
                                  + [f'HOST_NOT_REGISTERED_OR_NOT_VISIBLE:{x["hostname"]}'
                                     for x in report['matched_hosts']
                                     if x['registration'] != 'FOUND'
                                     and has_domain_metadata])
            # This script is never an activation certificate: mounts, publisher and
            # owner approvals require separate WordPress/VPS evidence.
            report['status'] = 'INVENTORY_ONLY' if not report['blockers'] else 'BLOCKED'
    rendered = json.dumps(report, ensure_ascii=False, indent=2)
    if args.output:
        with open(args.output, 'w', encoding='utf-8') as f:
            f.write(rendered + '\n')
    print(rendered)
    return 0 if report['status'] == 'INVENTORY_ONLY' else 2

if __name__ == '__main__':
    sys.exit(main())
