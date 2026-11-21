#!/usr/bin/env python3
"""Source-level fail-closed contract for governed WordPress native update guard.

No site mutation and no credentials. Native integration tests are separately
exercised against ZipArchive with a real installed WordPress PHP runtime.
"""
from pathlib import Path
import json
ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane/includes'
SOURCE=(PLUGIN/'class-mad4b-scp-self-update.php').read_text()
ADDON=(PLUGIN/'class-mad4b-scp-addon-registry.php').read_text()
def allows_source(s):
 guards=[
    'private static function reviewed_archive_no_regression( $path )',
    'mad4b-site-control-plane/includes/class-mad4b-scp-addon-registry.php',
    'mad4b-site-control-plane/includes/class-mad4b-scp-self-update.php',
    "environment_allowed( array( 'staging' ), 'write' )",
    "'site_bound_certified_pair'",
    "'unknown_pair_fallback' => 'shadow'",
    '$certified_pair = 1 === $matching_certificates',
    "'production_authorized' => false",
    'MAD4B_SCP_Post_Update_Continuation::prepare',
    'MAD4B_Release_Source_Admission::require_for_release',
    '$zip->getFromIndex( $i )',
    'mad4b_forward_archive_duplicate_guarded_file',
    'mad4b_forward_archive_guarded_file_missing',
    'mad4b_forward_archive_guarded_file_size_invalid',
    'mad4b_forward_archive_regression_denied',
    '$monotonic_archive = self::reviewed_archive_no_regression( $path );',
    'if ( is_wp_error( $monotonic_archive ) ) return $monotonic_archive;',
 ]
 return all(marker in s for marker in guards)
assert allows_source(SOURCE), 'monotonic update gate is missing required input/path/denial witnesses'
assert SOURCE.count('private static function reviewed_archive_no_regression( $path )')==1
assert SOURCE.count('self::reviewed_archive_no_regression( $path );')==1
start=SOURCE.index('private static function apply_verified_archive(')
section=SOURCE[start:start+3100]
before=section.index('MAD4B_Release_Source_Admission::require_for_release')
check=section.index('self::reviewed_archive_no_regression( $path )')
install=SOURCE.index('new Plugin_Upgrader(',start)
assert 0<=before<check and start+check<install
assert SOURCE.index('MAD4B_SCP_Runtime_Maintenance_Lease::acquire( $lease_owner )',start)>start+check
assert "environment_allowed( array( 'staging' ), 'write' )" in SOURCE
assert "'site_bound_certified_pair'" in ADDON and '$certified_pair = 1 === $matching_certificates' in ADDON
assert "'production_authorized' => false" in ADDON
tests={
 'candidate_old_addon_ring':SOURCE.replace("'site_bound_certified_pair'","'legacy_unbound'"),
 'candidate_old_staging_gate':SOURCE.replace("environment_allowed( array( 'staging' ), 'write' )",'deprecated_site_check'),
 'candidate_duplicate_gate_removed':SOURCE.replace('mad4b_forward_archive_duplicate_guarded_file','old_unguarded_duplicate'),
 'candidate_missing_file_denial_removed':SOURCE.replace('mad4b_forward_archive_guarded_file_missing','old_unguarded_missing'),
 'candidate_zip_size_limit_removed':SOURCE.replace('mad4b_forward_archive_guarded_file_size_invalid','old_unbounded_zip'),
 'candidate_update_last_admission_bypassed':SOURCE.replace(
    '$monotonic_archive = self::reviewed_archive_no_regression( $path );',
    '$monotonic_archive = true;'),
}
assert all(not allows_source(p) for p in tests.values()),'source mutation was not detected'
result={'contract':'mad4b.wordpress-native-archive-forward-only.source-contract.v1',
        'state':'PASS','negative_source_mutations':len(tests),
        'sha_pins_changed':False,'write_performed':False}
print(json.dumps(result,sort_keys=True))
