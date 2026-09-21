#!/usr/bin/env python3
import json
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
plugin = repo / 'plugins' / 'mad4b-staging-control-plane'
manifest = json.loads((plugin / '.codex-plugin' / 'plugin.json').read_text(encoding='utf-8'))
marketplace = json.loads((repo / '.agents' / 'plugins' / 'marketplace.json').read_text(encoding='utf-8'))
skill = (plugin / 'skills' / 'staging-control' / 'SKILL.md').read_text(encoding='utf-8')
readme = (plugin / 'README.md').read_text(encoding='utf-8')

if manifest.get('name') != 'mad4b-staging-control-plane':
    raise SystemExit('unexpected plugin manifest name')
if manifest.get('skills') != './skills/':
    raise SystemExit('plugin skills path must be relative')
if 'apps' in manifest:
    raise SystemExit('apps mapping must not be committed before a real plugin_asdk_app technical ID exists')
if manifest.get('interface', {}).get('capabilities') != ['Read']:
    raise SystemExit('initial ChatGPT plugin must advertise Read capability only')

entries = marketplace.get('plugins', [])
entry = next((x for x in entries if x.get('name') == 'mad4b-staging-control-plane'), None)
if not entry:
    raise SystemExit('repo marketplace is missing MAD4B Staging plugin')
if entry.get('source', {}).get('path') != './plugins/mad4b-staging-control-plane':
    raise SystemExit('marketplace plugin path is not repo-relative')
if entry.get('policy', {}).get('authentication') != 'ON_INSTALL':
    raise SystemExit('marketplace must require authentication on install')

for marker in [
    'https://staging.egypttourgates.com',
    'mad4b-read',
    'mad4b-write',
    'mad4b-breakglass',
    'do not use write',
]:
    if marker.lower() not in skill.lower():
        raise SystemExit(f'missing staging skill safety marker: {marker}')
for marker in ['plugin_asdk_app', 'MAD4B_MCP_OAUTH_ENABLED', 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', 'mad4b:read']:
    if marker not in readme:
        raise SystemExit(f'missing package README marker: {marker}')

print('mad4b.chatgpt-staging-plugin-package.v1: PASS')
