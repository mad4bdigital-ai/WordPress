#!/usr/bin/env python3
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
catalog = json.loads((ROOT / 'config/adapter-support-catalog.json').read_text('utf-8'))
families = [row for row in catalog.get('families', []) if isinstance(row, dict)]

def matches(plugin_file, row):
    out = []
    for prefix in row.get('match', []) or []:
        if plugin_file.startswith(str(prefix).lstrip('/').replace('\\\\', '/')):
            out.append(('prefix', prefix))
    for base in row.get('versioned_match', []) or []:
        base = str(base).strip('/').replace('\\\\', '/')
        if base and re.match(r'^' + re.escape(base) + r'-v[0-9]+(?:\.[0-9]+)*/', plugin_file):
            out.append(('versioned', base))
    return out

matrix = {
    'custom-mega-menu-v49/custom-mega-menu.php': 'custom-mega-menu',
    'hostinger-easy-onboarding/hostinger-easy-onboarding.php': 'hostinger-onboarding',
    'hostinger-ai-assistant/hostinger-ai-assistant.php': 'hostinger-ai',
    'hostinger-reach/hostinger-reach.php': 'hostinger-reach',
    'duplicator/duplicator.php': 'duplicator',
    'elementskit-lite/elementskit-lite.php': 'elementskit',
    'heic-support/heic-support.php': 'heic-support',
    'wordpress-importer/wordpress-importer.php': 'wordpress-importer',
    'miniorange-secure-mcp-server/miniorange-secure-mcp-server.php': 'external-mcp-server',
    'ai-engine/ai-engine.php': 'ai-engine',
    'hostinger/hostinger.php': 'hostinger',
    # This exact Staging add-on intentionally remains in the JetEngine family.
    'jet-engine-wp-all-import/wpai-jetengine-automagic.php': 'jetengine',
}

for plugin_file, expected_family in matrix.items():
    hits = [(row.get('id'), details) for row in families if (details := matches(plugin_file, row))]
    assert len(hits) == 1, f'{plugin_file}: expected exactly one family, got {hits}'
    assert hits[0][0] == expected_family, f'{plugin_file}: expected {expected_family}, got {hits[0][0]}'

negative = {
    'custom-mega-menu-villain/custom-mega-menu.php',
    'custom-mega-menu-v/custom-mega-menu.php',
    'duplicator-proxy/duplicator.php',
    'hostinger-ai-assistant-proxy/plugin.php',
}
for plugin_file in negative:
    hits = [(row.get('id'), details) for row in families if (details := matches(plugin_file, row))]
    assert not hits, f'{plugin_file}: lookalike must remain unknown, got {hits}'

print(f'mad4b.staging-provider-identity-matrix.contract.v1: PASS identities={len(matrix)} negatives={len(negative)}')
