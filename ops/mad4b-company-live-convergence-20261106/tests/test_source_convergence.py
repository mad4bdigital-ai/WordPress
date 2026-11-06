#!/usr/bin/env python3
"""Pure source/layout/sha validation for the MAD4B 2026-11-06 live checkpoint."""
from pathlib import Path
import hashlib
import json
import re

root=Path(__file__).resolve().parents[3]
bundle=root/'ops/mad4b-company-live-convergence-20261106'
manifest=json.loads((bundle/'SOURCE-FINGERPRINTS.json').read_text())
assert manifest['checkpoint']=='2026-11-06-company-settings-live-convergence'
for relative, expected in manifest['sha256'].items():
    f=root/relative
    assert f.is_file() and not f.is_symlink(), f'Missing or symlink: {relative}'
    actual=hashlib.sha256(f.read_bytes()).hexdigest()
    assert actual==expected, f'Drift in {relative}: {actual[:12]} != {expected[:12]}'
main=(root/'wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php').read_text()
assert 'Version: 0.4.0-rc.121' in main and "define( 'MAD4B_SCP_VERSION', '0.4.0-rc.121' );" in main
assert 'class-mad4b-scp-company-operations-policy.php' in main
assert 'mad4b_company_portfolio_active' in main
assert 'class-mad4b-scp-company-email-routing.php' in main
seeder=(root/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-skill-seeder.php').read_text()
assert 'const SEED_VERSION = 17;' in seeder
skills=json.loads((root/'wp-content/plugins/mad4b-site-control-plane/config/skill-seed-manifest.json').read_text())
assert skills['seed_version']==17
assert any(x['name']=='wordpress-settings-lifecycle' and x['enabled'] for x in skills['skills'])
for skill in skills['skills']:
    assert (root/'wp-content/plugins/mad4b-site-control-plane/skill-seeds'/skill['name']/'SKILL.md').is_file()
rate=(root/'wp-content/mu-plugins/141-mad4b-tour-rate-view-inheritance.php').read_text()
assert "add_action('admin_init',[__CLASS__,'register_tour_editor']" not in rate
assert rate.count("add_action('elementor/frontend/widget/before_render',[__CLASS__,'before_widget'],50);")==1
wpml=(root/'wp-content/mu-plugins/003-mad4b-lifecycle-access.php').read_text()
assert "get_active_languages()" in wpml
assert "apply_filters('wpml_active_languages'" not in wpml
signal=(root/'wp-content/mu-plugins/147-mad4b-tour-rate-cache-lifecycle.php').read_text()
assert "wayfatours.com" in signal and "signal('look-and-feel-rate-policy'" in signal
watcher=(bundle/'host-cache/purge-wayfa-tour-rate-public-cache.py').read_text()
assert 'wayfa-public-v3' in watcher and 'tours-and-activities/' in watcher
assert 'wp-content/cache' not in watcher
print(json.dumps({'result':'PASS','fingerprints':len(manifest['sha256']),'skills':len(skills['skills']),'release':'2026-11-06-company-settings-live-convergence'}))
