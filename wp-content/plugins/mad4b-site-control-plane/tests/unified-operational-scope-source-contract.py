#!/usr/bin/env python3
"""Static contract assertions for the operational scope bridge."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
src=(root/"includes/class-mad4b-scp-context-authority.php").read_text()
jobs=(root/"includes/class-mad4b-scp-content-jobs.php").read_text()
server=(root/"includes/class-mad4b-scp-servers.php").read_text()
bootstrap=(root/"mad4b-site-control-plane.php").read_text()
resolver=(root/"includes/class-mad4b-scp-deployment-mode-resolver.php").read_text()
guard=(root/"includes/class-mad4b-scp-operational-scope-guard.php").read_text()
assert "MAD4B_SCP_Operational_Scope_Guard::require_current()" in src
assert "MAD4B_SCP_Operational_Scope_Guard::source_in_scope(" in src
assert "mad4b_context_source_brand_conflict" in src
assert "'brand_id' => ! empty( $current['brand_id'] )" in src
assert "'site_uuid=%s', 'brand_id=%s'" in jobs
assert "'tenant_id' => (string) $scope['tenant_ref']" in jobs
assert "WHERE job_id=%s AND site_uuid=%s AND brand_id=%s" in jobs
assert "MAD4B_SCP_Operational_Scope_Guard::require_brand(" in jobs
assert "'mad4b/deployment-mode-status'" in server
assert "class-mad4b-scp-operational-scope-guard.php" in bootstrap
assert "get_option( MAD4B_SCP_Site_Profile::OPTION, null )" in resolver
assert "'execution_authorized' => false" in resolver
assert "mad4b.wordpress-operational-scope.v1" in guard
print("PASS unified operational integrity source assertions")
