#!/usr/bin/env python3
"""Prevent release-certificate gates from entering ordinary WP plugin startup."""
from pathlib import Path
import json
import re

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'wp-content/plugins/mad4b-site-control-plane'
ENTRY = (PLUGIN / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
IMPL = (PLUGIN / 'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
LIFECYCLE = (PLUGIN / 'includes/class-mad4b-scp-plugin-lifecycle.php').read_text(encoding='utf-8')
CONFIG = json.loads((PLUGIN / 'config/optional-governance-catalog.json').read_text(encoding='utf-8'))
POLICY = (ROOT / 'docs/integrations/plugin-native-runtime-minimal-gates.md').read_text(encoding='utf-8')

assert CONFIG['default_mode'] == 'off', 'Optional governance must stay off for fresh sites'
assert "register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );" in ENTRY
activation = re.search(r'public static function activate\(\) \{(?P<body>.*?)\n\t\}\n\n\tpublic static function boot\(', IMPL, re.S)
assert activation, 'Unexpected native plugin activation code layout; review required'
body = activation.group('body').lower()
for extra in ('certificate', 'certification', 'owner_attest', 'release_receipt', 'independent_certifier'):
    assert extra not in body, f'Unexpected extra activation gate: {extra}'
assert 'install_or_upgrade' in body, 'Schema migration should remain part of activation'
plan = re.search(r'public static function plan\( \$input \) \{(?P<body>.*?)\n\t\}\n\n\tpublic static function mutation_preflight', LIFECYCLE, re.S)
assert plan, 'Cannot inspect plugin lifecycle preflight'
for extra in ('independent_certifier', 'release_certificate', 'owner_attestation'):
    assert extra not in plan.group('body').lower(), f'Unexpected plugin lifecycle gate: {extra}'
assert 'signed release receipt' in POLICY
print('PASS native WordPress plugin operation has no additional release certificate startup gates')
