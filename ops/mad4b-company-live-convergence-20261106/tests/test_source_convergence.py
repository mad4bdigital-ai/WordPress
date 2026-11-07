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
    canonical=root/'wp-content/plugins/mad4b-site-control-plane/skill-seeds'/skill['name']/'SKILL.md'
    portable=root/'plugins/mad4b-wordpress/skills'/skill['name']/'SKILL.md'
    assert canonical.is_file() and portable.is_file(),skill['name']
    assert canonical.read_bytes()==portable.read_bytes(), 'Portable Skill seed drift: '+skill['name']
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
# Snapshot manifests and governed Skill discovery must remain aligned with live-source changes.
shared_root=root/'packages/mad4b-shared-product-core'
rows=(shared_root/'source-files.sha256').read_text().splitlines()
expected={rel:digest for digest,rel in (line.split('  ',1) for line in rows)}
for rel in ['local-tour-rate-filter-provider-router.php','shared-product-core/adapters/presentation-context-v1.php']:
    assert expected[rel]==hashlib.sha256((shared_root/'mu-plugins'/rel).read_bytes()).hexdigest(),rel
egress=json.loads((root/'wp-content/plugins/mad4b-site-control-plane/config/egress-policy.json').read_text())
source=egress['purposes']['skill_source_discovery']
assert all([source['require_https'] is True,source['sslverify_required'] is True,source['max_redirects']==0,source['reject_unsafe_urls'] is True])
# The separate Experience Footer bootstrap checkpoint must remain only a source
# snapshot plus an independently testable patch, never a complete plugin payload.
exp_root=root/'ops/company-readiness-workflow/host-provisioning-runtime-v1'
exp_source=exp_root/'live-only-module-snapshots/mad4b-experience/src/presentation/bootstrap.php'
exp_patch=exp_root/'patches/mad4b-experience-footer-coexistence-20261106.patch'
exp_test=exp_root/'tests/test_experience_footer_coexistence_patch.py'
assert hashlib.sha256(exp_source.read_bytes()).hexdigest()=='470b00846323ee5b22f1634991b752196d92f38942983b3c4210b556452a0061'
assert exp_patch.is_file() and exp_test.is_file()
# Original Native Schema 313 is an immutable source snapshot of the deployed
# Worker/Broker code. Tests were made repository-relative, without modifying
# executable runtime source or loosening independent 312/313 verification.
schema_root=root/'ops/company-readiness-workflow/original-native-schema-313-v1'
schema_manifest=json.loads((schema_root/'source-manifest.json').read_text())
assert schema_manifest['contract']=='mad4b.original-native-schema-313-source-snapshot.v1'
assert schema_manifest['source_only'] is True
assert schema_manifest['original_313_certified_by_commit'] is False
assert len(schema_manifest['source_sha256'])==9
for file_name,checksum in schema_manifest['source_sha256'].items():
    assert '/' not in file_name and '\\' not in file_name and file_name.endswith('.py')
    candidate=schema_root/file_name
    assert candidate.is_file() and not candidate.is_symlink(),file_name
    assert hashlib.sha256(candidate.read_bytes()).hexdigest()==checksum,file_name
worker=(schema_root/'claimed-job-worker.py').read_text()
assert 'schema.readback.resume' in worker and 'if reserved:' in worker
assert 'time.time()+360' in worker
# Recent signed WP-CLI priority is a read-only scheduling hint, NOT a new
# authority. The source snapshot has an immutable scoped readback manifest.
priority_root=root/'ops/company-readiness-workflow/central-signed-wpcli-priority-v1'
priority_manifest=json.loads((priority_root/'source-manifest.json').read_text())
assert priority_manifest['contract']=='mad4b.central-signed-wpcli-priority-source.v1'
assert priority_manifest['source_only'] is True
assert priority_manifest['wpcli_concurrency_limit_unchanged']==2
assert priority_manifest['original_305_312_313_grants_changed'] is False
assert priority_manifest['original_313_job_certified_by_source_commit'] is False
expected_priority_sources = {
    'wpcli-signed-priority.py', 'wpcli-site-scheduler.py',
    'test_wpcli_signed_priority.py', '70-signed-wpcli-priority.conf',
    '50-original-signed-wpcli-priority.conf', 'vps-broker-poller.py',
    'test_vps_broker_busy.py', 'mad4b-faore-stdio-observe-priority.conf',
    'mad4b-company-self-healing-priority.conf',
}
assert set(priority_manifest['live_sha256'])==expected_priority_sources
assert set(priority_manifest['files'])==expected_priority_sources
for name,digest in priority_manifest['live_sha256'].items():
    assert '/' not in name and (priority_root/name).is_file(),name
    assert hashlib.sha256((priority_root/name).read_bytes()).hexdigest()==digest,name
scheduler=(priority_root/'wpcli-site-scheduler.py').read_text()
assert 'len(SPECS)==11' in scheduler and 'if defer_if_signed_priority(args.unit):return 0' in scheduler
assert 'if defer_if_signed_priority(args.unit):return 0' in scheduler.split('fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)',1)[1]
# Heartbeat cadence must never outlive the *same native* readiness TTL. The
# host timer does not itself issue 305 jobs or grant WordPress permissions.
from configparser import ConfigParser
heartbeat_root=root/'ops/company-readiness-workflow/host-provisioning-runtime-v1'
config=ConfigParser(interpolation=None)
assert config.read(heartbeat_root/'systemd/mad4b-company-plugin-install-capability.timer')
sec=config['Timer']
def unit_seconds(value):
    assert value.endswith('s') and value[:-1].isdigit(),value
    return int(value[:-1])
period=unit_seconds(sec['OnUnitInactiveSec'])
jitter=unit_seconds(sec['RandomizedDelaySec'])
accuracy=unit_seconds(sec['AccuracySec'])
handler=(root/'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-native-plugin-install-handler.php').read_text()
provider=(root/'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-native-plugin-install-provider.php').read_text()
assert 'public const MAX_AGE_SECONDS=600;' in handler
assert "$p['heartbeat_epoch']<$now-self::MAX_AGE_SECONDS" in handler
assert "$cap['heartbeat_epoch']<$now-$class::MAX_AGE_SECONDS" in provider
assert period==300 and period+jitter+accuracy<600
assert sec['Unit']=='mad4b-company-plugin-install-capability.service'
print(json.dumps({'result':'PASS','fingerprints':len(manifest['sha256']),'skills':len(skills['skills']),'release':'2026-11-06-company-settings-live-convergence'}))
