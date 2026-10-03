from pathlib import Path
root=Path(__file__).resolve().parents[1]
profile=(root/"includes/class-mad4b-scp-wordpress-lifecycle-migration-profile.php").read_text(encoding="utf-8")
config=(root/"config/wordpress-lifecycle-migration-profile.json").read_text(encoding="utf-8")
authorization=(root/"includes/class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
fence=(root/"includes/class-mad4b-scp-execution-fence.php").read_text(encoding="utf-8")
projection=(root/"includes/class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
wpability=(root.parents[2]/"wp-includes/abilities-api/class-wp-ability.php").read_text(encoding="utf-8")
lifecycle=(root/"includes/class-mad4b-scp-plugin-lifecycle.php").read_text(encoding="utf-8")
retention=(root/"tests/plugin-lifecycle-data-retention-contract.py").read_text(encoding="utf-8")
continuity=(root/"tests/runtime-plugin-lifecycle-data-continuity.php").read_text(encoding="utf-8")
update=(root/"tests/post-update-continuation-guards-runtime.php").read_text(encoding="utf-8")
catalog=(root/"tests/catalog-lifecycle-runtime.php").read_text(encoding="utf-8")
backend=(root/"tests/runtime-catalog-table-backend.php").read_text(encoding="utf-8")
workflow=(root.parents[2]/".github/workflows/feature-007-pre-staging-hybrid-audit.yml").read_text(encoding="utf-8")

for hook in ["wp_ability_invoked","wp_pre_execute_ability","wp_ability_permission_result","wp_ability_execute_result"]:
    if hook not in wpability or hook not in config:
        raise SystemExit("FAIL lifecycle migration native hook missing: "+hook)
for marker in ["ReflectionObject","trusted_execution_boundaries"]:
    if marker not in authorization:
        raise SystemExit("FAIL legacy Authorization provenance surface disappeared before parity: "+marker)
for marker in ["ReflectionObject","trusted_final_boundaries"]:
    if marker not in fence:
        raise SystemExit("FAIL legacy Execution Fence provenance surface disappeared before parity: "+marker)
for marker in ["ReflectionObject","callback_identity"]:
    if marker not in projection:
        raise SystemExit("FAIL legacy Projection callback provenance disappeared before parity: "+marker)
for marker in ["legacy_provenance_authoritative","parity_certified","cutover_eligible","automatic_cutover","replacement_performed","no_authority_widening"]:
    if marker not in profile:
        raise SystemExit("FAIL lifecycle migration profile guard missing: "+marker)
for forbidden in ["update_option(","delete_option(","wp_register_ability(","wp_unregister_ability(","setValue("]:
    if forbidden in profile:
        raise SystemExit("FAIL lifecycle migration profile mutates runtime/provenance: "+forbidden)

for marker in ["activation_scope","'network'","is_multisite()","network_activation_controls_site_state","verify_state"]:
    if marker not in lifecycle:
        raise SystemExit("FAIL network lifecycle planning guard missing: "+marker)
for marker in ["uninstall.php","register_uninstall_hook","governance deletion"]:
    if marker not in retention:
        raise SystemExit("FAIL uninstall retention contract missing: "+marker)
for marker in ["mad4b_scp_agent_grants","mad4b_scp_approval_tickets","mad4b_scp_site_profile_v2","CAPTURED","VERIFIED"]:
    if marker not in continuity:
        raise SystemExit("FAIL lifecycle governance continuity evidence missing: "+marker)
for marker in ["rollback invalidates permit","build-only update must auto-rebind","owner_gate"]:
    if marker not in update:
        raise SystemExit("FAIL post-update rollback/continuation evidence missing: "+marker)
for marker in ["205","Network pagination","data retention","restore_current_blog"]:
    if marker not in catalog:
        raise SystemExit("FAIL multisite catalog cleanup evidence missing: "+marker)
for marker in ["rollback(","retire_after_rollback","orphan_bytes","projection"]:
    if marker not in backend:
        raise SystemExit("FAIL catalog rollback/retirement compatibility evidence missing: "+marker)
for marker in ["MAD4B_LIFECYCLE_PHASE=capture","plugin deactivate mad4b-site-control-plane","plugin activate mad4b-site-control-plane","plugin uninstall mad4b-site-control-plane","MAD4B_LIFECYCLE_PHASE=verify"]:
    if marker not in workflow:
        raise SystemExit("FAIL actual disposable WordPress lifecycle acceptance missing: "+marker)

print("mad4b.wordpress-lifecycle-migration.contract.v1: PASS")
