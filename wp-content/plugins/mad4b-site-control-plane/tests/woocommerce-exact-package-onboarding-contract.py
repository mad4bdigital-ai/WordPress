#!/usr/bin/env python3
"""Offline fail-closed WooCommerce exact-package provenance contract.

This validates registered values and claims, not the archive download itself.
"""
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
registry=json.loads((root/'config/certified-providers.json').read_text())
evidence=json.loads((root/'config/evidence/woocommerce-11.2.1-lab-package.json').read_text())
providers=registry['providers']
assert 'woocommerce' in providers, 'woocommerce_provider_missing'
p=providers['woocommerce']
assert p['plugin_file']=='woocommerce/woocommerce.php'
assert p['archive']=='woocommerce.zip'
assert p['version']=='11.2.1'
assert p['archive_sha256']=='5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586'
assert p['archive_bytes']==18060641
assert p['certification_authority']=='wordpress.org:downloads/plugin/woocommerce.11.2.1.zip'
assert p['require_runtime_integrity'] is True
assert p['contract_mode']=='official_wordpress_org_exact_artifact'
assert len(p['critical_files'])==14
assert p['critical_files']==evidence['critical_files']
assert evidence['archive_sha256']==p['archive_sha256']
assert evidence['archive_bytes']==p['archive_bytes']
assert evidence['plugin_file']==p['plugin_file']
assert evidence['zip_file_count']==6409
assert evidence['zip_unsafe_entries']==0
assert evidence['critical_installed_matches']==len(p['critical_files'])
assert evidence['critical_mismatches']==[]
assert evidence['missing_installed_critical']==[]
assert evidence['missing_critical_in_archive']==[]
assert evidence['deployment_authorized'] is False
assert evidence['review_state']=='source_verified_pending_independent_review'
assert evidence['authorizing'] is False
assert evidence['source_url']=='https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip'
source=(root/'includes/class-mad4b-scp-plugin-package.php').read_text()
router=(root/'includes/class-mad4b-scp-package-route-resolver.php').read_text()
assert "'certified_local_archive'" in source
assert "hash_file( 'sha256', $path )" in source
assert "expected_plan_sha256" in source
assert "MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' )" in source
assert "array_key_exists( $target, $certified_providers )" in router
assert "'staging_required'" in router
assert "'auto_apply_allowed' => false" in router
print("WOOCOMMERCE_LAB_EXACT_PACKAGE_CANDIDATE_CONTRACT_PASS")
