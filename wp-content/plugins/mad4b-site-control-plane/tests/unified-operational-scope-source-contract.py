#!/usr/bin/env python3
"""Merged WordPress Dedicated source contract; not a Native/Staging pass."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
src=(root/"includes/class-mad4b-scp-context-authority.php").read_text()
jobs=(root/"includes/class-mad4b-scp-content-jobs.php").read_text()
server=(root/"includes/class-mad4b-scp-servers.php").read_text()
bootstrap=(root/"mad4b-site-control-plane.php").read_text()
resolver=(root/"includes/class-mad4b-scp-deployment-mode-resolver.php").read_text()
guard=(root/"includes/class-mad4b-scp-operational-scope-guard.php").read_text()
integrity=(root/"includes/class-mad4b-scp-operational-integrity.php").read_text()
profile=(root/"includes/class-mad4b-scp-site-profile.php").read_text()
assert "MAD4B_SCP_Operational_Scope_Guard::require_current()" in src
assert "if ( 'save_profile' !== (string) $operation )" in src
assert "mad4b_context_source_brand_collision" in src
assert "Unbound legacy assets need reviewed ownership" in src
assert "'' === $asset_brand_id ||" in src and "! hash_equals( strtolower( (string) $verified_scope['brand_ref'] )" in src
assert "'' === $record_brand_id || ! hash_equals" in src
assert "MAD4B_SCP_Operational_Integrity::capture()" in guard
assert "MAD4B_SCP_Operational_Integrity::assert_unchanged" in jobs
assert "tenant_id=%s" in jobs and "site_uuid=%s" in jobs and "brand_id=%s" in jobs
assert "'tenant_id' => (string) $scope['tenant_ref']" in jobs
assert "transactional_storage_status" in jobs
assert "content_job_rollback_uncertain" in jobs
assert "content_job_commit_uncertain" in jobs
assert "MAD4B_SCP_Policy::can_mutate()" in integrity
assert "class-mad4b-scp-operational-scope-guard.php" in bootstrap
assert "get_option( MAD4B_SCP_Site_Profile::OPTION, null )" in resolver
assert "mcp_registration_status()" in resolver
assert "mad4b/deployment-mode-status" in server
assert "function legacy_migration_plan()" in profile
assert "function apply_legacy_migration(" in profile
assert "legacy_v1_migration_available" in profile
assert "legacy_v1_migrated" not in profile
assert "update_option( self::OPTION, $migrated, false )" not in profile
print("PASS merged strict brand scope + no read side-effects source contract")
