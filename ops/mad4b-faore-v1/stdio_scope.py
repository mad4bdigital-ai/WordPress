"""Resolve a single current Central WordPress runtime from live Site Profile facts.

Discovery provides no job-level authority. Reject missing, ambiguous or foreign sites.
"""
import json
import re
import subprocess
from typing import Callable
from urllib.parse import urlsplit
from uuid import UUID

NAME = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$")


def run(argv: list[str], *, payload: str | None = None, timeout: int = 25) -> str:
    p = subprocess.run(argv, input=payload, text=True, capture_output=True,
                       check=False, timeout=timeout)
    if p.returncode:
        if "STAGING_WPCLI_BUSY" in ((p.stderr or "") + (p.stdout or "")):
            raise RuntimeError("CENTRAL_WORDPRESS_BUSY")
        raise RuntimeError("EXACT_HOST_READBACK_UNAVAILABLE")
    if len(p.stdout) > 1_000_000:
        raise RuntimeError("EXACT_HOST_READBACK_UNAVAILABLE")
    return p.stdout.strip()


def _json_last_line(value: str) -> dict:
    try:
        row = json.loads(value.strip().splitlines()[-1])
    except (IndexError, ValueError):
        raise RuntimeError("WORDPRESS_PROFILE_READBACK_INVALID")
    if not isinstance(row, dict):
        raise RuntimeError("WORDPRESS_PROFILE_READBACK_INVALID")
    return row


PROFILE_CODE = """
$s = class_exists('MAD4B_SCP_Site_Profile', false)
  ? MAD4B_SCP_Site_Profile::status() : array();
echo wp_json_encode(array(
 'company_manager_loaded'=>class_exists('MAD4B_Company_Portfolio_Manager_V1',false),
 'contract'=>$s['contract']??null,
 'site_uuid'=>$s['site_uuid']??null,
 'canonical_origin'=>$s['canonical_origin']??null,
 'environment'=>$s['environment']??null,
 'configured'=>$s['configured']??false,
 'origin_match'=>$s['origin_match']??false,
 'environment_match'=>$s['environment_match']??false,
 'deployment_binding_match'=>$s['deployment_binding_match']??false,
 'siteurl'=>get_option('siteurl')
));"""


def _valid_site_row(row: dict, environment: str) -> bool:
    if row.get('company_manager_loaded') is not True:
        return False
    if row.get('contract') != 'mad4b.site-profile.v2':
        return False
    if row.get('environment') != environment:
        return False
    if not all(row.get(k) is True for k in
               ('configured', 'origin_match', 'environment_match',
                'deployment_binding_match')):
        return False
    try:
        site_uuid = row.get('site_uuid', '')
        if not isinstance(site_uuid, str) or str(UUID(site_uuid)) != site_uuid:
            return False
        origin = row.get('canonical_origin', '')
        if not isinstance(origin, str) or origin != row.get('siteurl'):
            return False
        parsed = urlsplit(origin)
        if (parsed.scheme != 'https' or not parsed.hostname or parsed.username
                or parsed.password or parsed.query or parsed.fragment
                or parsed.path not in ('', '/') or parsed.port):
            return False
    except (ValueError, TypeError):
        return False
    return True


def central_target(environment: str = 'staging', *, runner: Callable = run) -> dict:
    if environment not in ('staging', 'production'):
        raise ValueError('CENTRAL_ENVIRONMENT_NOT_ALLOWED')
    inventory = runner(['docker', 'ps',
                        '--filter', 'label=com.docker.compose.service=php',
                        '--format', '{{.Names}}']).splitlines()
    if not inventory or len(inventory) > 30:
        raise RuntimeError('CENTRAL_RUNTIME_INVENTORY_UNBOUNDED')
    matches = []
    saw_busy = False
    for name in inventory:
        if not NAME.fullmatch(name):
            continue
        base = ['docker', 'exec', '-u', 'www-data', name, 'wp',
                '--path=/var/www/html', '--skip-themes']
        try:
            row = _json_last_line(runner(base + ['eval', PROFILE_CODE], timeout=24))
        except RuntimeError as exc:
            if str(exc) == "CENTRAL_WORDPRESS_BUSY":
                saw_busy = True
            continue
        except (subprocess.TimeoutExpired, ValueError, TypeError):
            continue
        if _valid_site_row(row, environment):
            matches.append({
                'container': name,
                'site_uuid': row['site_uuid'],
                'canonical_origin': row['canonical_origin'],
                'environment': environment,
            })
    if saw_busy:
        raise RuntimeError('CENTRAL_WORDPRESS_BUSY')
    if len(matches) != 1:
        raise RuntimeError('CENTRAL_RUNTIME_AMBIGUOUS' if matches
                           else 'CENTRAL_RUNTIME_NOT_VERIFIED')
    return matches[0]
