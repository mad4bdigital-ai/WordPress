#!/usr/bin/env python3
"""Deny mutation/network leakage from the new presentation layer."""
from pathlib import Path
import gettext
import json
import re

root = Path(__file__).resolve().parents[1]
for path in ('includes/class-mad4b-scp-admin-workspace.php', 'includes/class-mad4b-scp-operator-workspace.php'):
    source = (root / path).read_text()
    for forbidden in ('$_POST', 'wp_remote_', 'wp_safe_remote_', 'update_option(', 'delete_option(', 'admin_post_', '$wpdb', 'grant_ability(', 'wp_register_ability(', 'eval('):
        assert forbidden not in source, (path, forbidden)
js = (root / 'assets/admin-workspace.js').read_text()
for forbidden in ('fetch(', 'XMLHttpRequest', 'innerHTML', 'localStorage', 'sessionStorage', 'form.submit', 'requestSubmit', 'MutationObserver'):
    assert forbidden not in js, forbidden
css = (root / 'assets/admin-workspace.css').read_text()
for marker in ('prefers-reduced-motion', 'forced-colors', 'focus-visible', 'border-inline-start', '[hidden]', '44px'):
    assert marker in css, marker
assert '@import' not in css and 'url(' not in css
bootstrap = (root / 'mad4b-site-control-plane.php').read_text()
assert 'class-mad4b-scp-admin-workspace.php' in bootstrap
assert 'class-mad4b-scp-operator-workspace.php' in bootstrap
catalog = gettext.GNUTranslations((root / 'languages/mad4b-site-control-plane-ar.mo').open('rb'))
source = (root / 'languages/mad4b-site-control-plane-ar.po').read_text()
message = None
count = 0
for line in source.splitlines():
    if line.startswith('msgid '):
        message = json.loads(line[6:])
    elif line.startswith('msgstr ') and message:
        value = json.loads(line[7:])
        assert value and catalog.gettext(message) == value, message
        assert re.findall(r'%[ds]', message) == re.findall(r'%[ds]', value), message
        count += 1
assert count >= 160 and catalog.gettext('Not checked') == 'لم يتم الفحص'
import runpy
runpy.run_path(str(root / 'tests/guided-operator-experience-contract.py'))
print('mad4b.admin-workspace-presentation-boundaries.v1: PASS')
