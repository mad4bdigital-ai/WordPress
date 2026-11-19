#!/usr/bin/env python3
"""Source contract: never permit the three WP release entrypoints to skip Host qualification."""
from pathlib import Path
r=Path(__file__).resolve().parents[2]
inc=r/'wp-content/plugins/mad4b-site-control-plane/includes'
mu=r/'wp-content/mu-plugins/532-mad4b-release-source-admission.php'
selected=(inc/'class-mad4b-scp-selected-head-update.php').read_text()
selfupdate=(inc/'class-mad4b-scp-self-update.php').read_text()
catalog=(inc/'class-mad4b-scp-server-core-catalog.php').read_text()
ui=(inc/'class-mad4b-scp-runtime-components-admin-ui.php').read_text()
company=(inc/'class-mad4b-scp-company-code-release-readiness.php').read_text()
s=mu.read_text()
assert 'MAD4B_Release_Source_Admission::check(' in selected
assert 'MAD4B_Release_Source_Admission::require_for_release( $sha )' in selected
assert 'MAD4B_Release_Source_Admission::check(' in selfupdate
assert 'MAD4B_Release_Source_Admission::require_for_release( $source_sha )' in selfupdate
assert 'private static function apply_verified_archive' in selfupdate
assert selfupdate.index('MAD4B_Release_Source_Admission::require_for_release( $source_sha )') > selfupdate.index('private static function apply_verified_archive')
assert 'mad4b/release-source-preflight-status' in catalog
assert 'MAD4B_Release_Source_Admission::render_selector()' in ui
assert 'MAD4B_Release_Source_Admission::check()' in company
assert 'production_deployment_allowed' not in s or "'release_approved'=>false" in s
assert 'openssl_verify(' in s
assert "'preflight_ready'=>false" in s
assert 'source_not_selected' in s
assert 'signed_host_preflight_signature_invalid' in s
assert 'target_inventory_changed_after_preflight' in s
assert "'grant_created'=>false" in s
assert "'release_approved'=>false" in s
assert 'untrailingslashit(home_url())' in s
assert 'MAD4B_SCP_Site_Profile::site_uuid()' in s
assert 'MAD4B_SCP_Site_Profile::profile_digest()' in s
assert 'current_site_inventory()' in s
print('PASS: selected-head, self-update last boundary, UI and company-readiness source admission contracts')
