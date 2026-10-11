from pathlib import Path

root = Path(__file__).resolve().parents[1]
remote = (root / "includes/class-mad4b-scp-brand-core-remote-recovery.php").read_text(encoding="utf-8")
remediation = (root / "includes/class-mad4b-scp-operational-remediation.php").read_text(encoding="utf-8")
parity = (root / "includes/class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8")
servers = (root / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
host_bridge = (root / "includes/class-mad4b-scp-host-bridge.php").read_text(encoding="utf-8")
workflow = (root.parents[2] / ".github/workflows/feature-007-spec-ci.yml").read_text(encoding="utf-8")
runbook = (root.parents[2] / "docs/runbooks/brand-core-mcp-complete-recovery.md").read_text(encoding="utf-8")

def must(body, marker, why):
    if marker not in body:
        raise AssertionError(f"{why}: {marker}")

for marker in (
    "final class MAD4B_SCP_Brand_Core_Remote_Recovery",
    "const STATUS_ABILITY = 'mad4b/brand-core-remote-recovery-status'",
    "const PREPARE_ABILITY = 'mad4b/brand-core-remote-recovery-prepare'",
    "public static function status(",
    "public static function prepare(",
    "public static function reduce(",
    "public static function can_read()",
    "current_user_can( 'manage_options' )",
    "'staging' === (string) ( $site['environment'] ?? '' )",
    "'include_provider_scan'",
    "'include_host_challenge'",
    "legacy_owner_transfer_evidence(",
    "MAD4B_SCP_Host_Identity_Live::observe(",
    "MAD4B_SCP_Selected_Head_Update::plan(",
    "MAD4B_SCP_Context_Authority::brand_core_coverage()",
    "MAD4B_SCP_Context_Authority::legacy_reconciliation_census()",
    "hash_equals( $sha, (string) $snapshot['plan_sha256'] )",
    "'durable_db_recovery' => false",
    "'owner_attestation' => false",
    "'certified_package' => false",
    "'draft_acceptance' => false",
    "'full_operational_acceptance' => false",
    "'production_mutation_authorized' => false",
    "'breakglass_authorized' => false",
    "'approval_issued' => false",
    "'mutation_performed' => false",
    "'ready_for_dispatch' => false",
    "'automatic_apply_allowed' => false",
    "'independent_signed_host_executor_required'",
    "'real_cross_request_db_crash_rollback_receipt_required'",
    "'installed_head_and_rollback_readback_required'",
    "'separate_draft_apply_and_cpt_readback_required'",
):
    must(remote, marker, "missing immutable governance condition")

for kind in (
    "source_inventory", "original_file_identity", "owner_attestation",
    "host_identity", "durable_db_recovery", "certified_package",
    "brand_approval", "draft_acceptance"
):
    must(remote, f"'{kind}' => array(", "missing scoped remote stage")

if remote.count("public static function register_abilities(") != 1:
    raise AssertionError("duplicate remote recovery ability registrations")

for forbidden in (
    "eval(", "shell_exec(", "system(", "proc_open(", "exec(",
    "update_option(", "delete_option(", "wp_insert_post(",
    "wp_remote_post(", "file_put_contents(", "wp_register_post_type(",
    "MAD4B_SCP_Context_Authority::legacy_owner_transfer_apply(",
    "MAD4B_SCP_Selected_Head_Update::apply(",
):
    if forbidden in remote:
        raise AssertionError(f"unexpected privileged/side-effectful call: {forbidden}")

for marker in (
    "class-mad4b-scp-brand-core-remote-recovery.php",
    "MAD4B_SCP_Brand_Core_Remote_Recovery::boot();",
):
    must(remediation, marker, "canonical read-only Operational Remediation boot missing")

for marker in (
    "'brand_core_remote_recovery_diagnostics' => array(",
    "'brand_core_remote_recovery_handoff' => array(",
    "'remote_ability' => 'mad4b/brand-core-remote-recovery-status'",
    "'remote_ability' => 'mad4b/brand-core-remote-recovery-prepare'",
    "'authority_surface' => 'mad4b-read'",
    "'production_policy' => 'deny'",
):
    must(parity, marker, "remote discovery contract missing")

for marker in (
    "'mad4b/brand-core-remote-recovery-status'",
    "'mad4b/brand-core-remote-recovery-prepare'",
    "'mad4b/host-doctor'",
    "'mad4b/host-operation-plan'",
    "'mad4b/host-operation-apply'",
):
    must(servers, marker, "missing separate governed Host/Brand MCP mount")

for marker in (
    "MAD4B_SCP_Authorization::claim_mutation( 'mad4b/host-operation-apply'",
    "'approval_required' => (bool) self::$operations[ $operation_id ]['approval_required']",
    "'production_authorized' => false",
):
    must(host_bridge, marker, "signed Host job authorization cannot be bypassed")

must(remote, "'preflight' => 'mad4b/host-operation-plan'", "Host plan must precede apply")
must(remote, "'next' => 'mad4b/host-operation-apply'", "Host executor must be separately governed")
must(remote, "'evidence_drift' => false", "status must begin with empty drift")
must(remote, "'cross_read_snapshot_drift'", "cross-source readback drift must fail closed")

alternative = (root / "includes/class-mad4b-scp-recovery-alternatives.php").read_text(encoding="utf-8")
for marker in (
    "final class MAD4B_SCP_Recovery_Alternatives",
    "public static function assess(",
    "public static function discover(",
    "public static function add_on_suggestions(",
    "signed_offline_staging",
    "standalone_remote_builder",
    "isolated_mariadb_worker",
    "managed_staging_clone",
    "'execution_authorized' => false",
    "'release_or_rollback_certified' => false",
    "'automatic_fallback_allowed' => false",
):
    must(alternative, marker, "alternative route discovery must remain nonauthorizing")
must(remote, "const ALTERNATIVES_ABILITY = 'mad4b/brand-core-recovery-alternatives'", "alternate read ability missing")
must(remote, "MAD4B_SCP_Recovery_Alternatives::discover(", "alternative planning not integrated")

native = (root / "includes/class-mad4b-scp-native-recovery.php").read_text(encoding="utf-8")
native_test = (root / "tests/native-recovery-runtime.php").read_text(encoding="utf-8")
for marker in (
    "final class MAD4B_SCP_Native_Recovery",
    "const OPTION = 'mad4b_scp_native_recovery_scratch_v1'",
    "const PLAN = 'mad4b/native-recovery-plan'",
    "const START = 'mad4b/native-recovery-start'",
    "const READBACK = 'mad4b/native-recovery-readback'",
    "const CLEAN = 'mad4b/native-recovery-cleanup'",
    "public static function cross_request_verified(",
    "add_option( self::OPTION, $scratch, '', false )",
    "delete_option( self::OPTION )",
    "'staging' !== ( $x['configured_environment'] ?? '' )",
    "'staging' !== ( $x['wordpress_environment'] ?? '' )",
    "MAD4B_SCP_Authorization::authorize_mutation(",
    "MAD4B_SCP_Authorization::claim_mutation(",
    "MAD4B_SCP_Authorization::permission_result_from_authorization(",
    "MAD4B_SCP_Audit::record(",
    "MAD4B_SCP_Context_Authority::legacy_owner_transfer_evidence(",
    "MAD4B_SCP_Context_Authority::legacy_owner_transfer_plan(",
    "'mariadb_crash_restart_certified' => false",
    "'rollback_certification_issued' => false",
    "'ownership_transferred' => false",
    "'host_required' => false",
):
    must(native, marker, "native WordPress-only recovery invariant missing")
for dangerous in (
    "shell_exec(", "proc_open(", "system(", "eval(",
    "wpdb->query(", "wpdb->update(", "wpdb->insert(",
    "legacy_owner_transfer_apply(", "update_option(",
    "Plugin_Upgrader(", "wp_remote_post(",
):
    if dangerous in native:
        raise AssertionError("unexpected external execution or non-scratch mutation: " + dangerous)
for marker in ("native-recovery-plan", "native-recovery-start",
               "native-recovery-readback", "native-recovery-cleanup"):
    must(servers, marker, "new Host-free MCP surface not mounted")
must(remediation, "MAD4B_SCP_Native_Recovery::boot();", "native feature not registered")
must(alternative, "'wordpress_native' => array(", "native alternative domain missing")
must(parity, "'brand_core_wordpress_native_probe' => array(", "native scratch operation not indexed")
must(parity, "'brand_core_wordpress_native_shadow' => array(", "native shadow operation not indexed")
must(native_test, "original options unchanged", "scratch refusal fixture missing")
operator = (root / "includes/class-mad4b-scp-operator-journeys.php").read_text(encoding="utf-8")
operator_test = (root / "tests/operator-journeys-runtime.php").read_text(encoding="utf-8")
admin_ux = (root / "includes/class-mad4b-scp-operational-remediation.php").read_text(encoding="utf-8")
for marker in (
    "const ABILITY = 'mad4b/operator-journeys'",
    "'setup_and_permissions' => array(",
    "'forms_and_integration' => array(",
    "'brand_core' => array(",
    "'content_and_drafts' => array(",
    "'workflows' => array(",
    "'plugin_upgrades' => array(",
    "'wordpress_native_recovery' => array(",
    "'diagnose_and_recover' => array(",
    "'REGISTERED_NOT_OPERATIONALLY_VERIFIED'",
    "'execution_authorized' => false",
    "'live_acceptance_verified' => false",
    "'one_click_execution_supported' => false",
):
    must(operator, marker, "missing operator journey or false completion state")
must(operator_test, "nine distinct task journeys", "standalone whole-plugin UX fixture missing")
main_admin = (root / "includes/class-mad4b-scp-admin-ui.php").read_text(encoding="utf-8")
for marker in (
    "'tasks' => __( 'Tasks & Workflows'",
    "if ( 'tasks' === $section ) return array(",
    "if ( 'tasks' === $tab ) self::render_tasks();",
    "private static function render_tasks()",
    "Unable to inspect task routes",
    "Tools listed",
):
    must(main_admin, marker, "main admin journey navigation or outage safety missing")
for marker in (
    "const STATUS = 'mad4b/native-recovery-status'",
    "'SCRATCH_EXPIRED_CLEANUP_AVAILABLE'",
    "'SCRATCH_REQUIRES_INDEPENDENT_READBACK'",
    "'cleanup_intent'",
    "'CLEANED_AUDIT_REPAIR_REQUIRED'",
    "'mad4b_native_scratch_occupied'",
):
    must(native, marker, "resume or truthful cleanup handling missing")
must(admin_ux, "Choose your goal", "goal-first admin navigation not available")
must(admin_ux, "Resume job ID", "interrupted native task cannot be resumed from UI")
must(admin_ux, "is_wp_error( $view )", "diagnostic failure must render usable admin message")
must(remediation, "MAD4B_SCP_Operator_Journeys::boot();", "task index not registered")
must(servers, "'mad4b/operator-journeys'", "task catalog absent from MCP read surface")
must(servers, "'mad4b/native-recovery-status'", "native task state not accessible through MCP")
must(native, "const STATUS = 'mad4b/native-recovery-status'", "native status tool missing")
must(native, "SCRATCH_EXPIRED_CLEANUP_AVAILABLE", "expired job recovery missing")
must(native, "SCRATCH_REQUIRES_INDEPENDENT_READBACK", "active job recovery missing")
must(native, "'next_ability' => self::STATUS", "new status link missing")
acceptance = (root / "includes/class-mad4b-scp-operational-acceptance-evidence.php").read_text(encoding="utf-8")
acceptance_test = (root / "tests/operational-acceptance-evidence-runtime.php").read_text(encoding="utf-8")
for marker in (
    "const ABILITY = 'mad4b/operational-acceptance-evidence'",
    "public static function site_snapshot(",
    "public static function reduce(",
    "public static function collect(",
    "context/legacy-owner-transfer-evidence",
    "MAD4B_SCP_Context_Authority::legacy_owner_transfer_readback(",
    "MAD4B_SCP_Context_Authority::brand_core_coverage(",
    "MAD4B_SCP_Native_Recovery::status()",
    "MAD4B_SCP_Host_Identity_Live::observe(",
    "MAD4B_SCP_Context_Authority::legacy_reconciliation_census()",
    "MAD4B_SCP_Self_Update::current_identity()",
    "'owner_attestation' => false",
    "'durable_db_recovery' => false",
    "'certified_package' => false",
    "'draft_acceptance' => false",
    "'full_operational_acceptance' => false",
    "'owner_transfer_authorized' => false",
    "'breakglass_authorized' => false",
    "'production_mutation_authorized' => false",
    "'mutation_performed' => false",
    "expected_snapshot_sha256",
    "SNAPSHOT_DRIFT_REPLAN",
    "independent_real_mariadb_crash_rollback_executor_and_signed_receipt_missing",
):
    must(acceptance, marker, "non-authorizing operational acceptance evidence contract missing")
for side_effect in (
    "shell_exec(", "proc_open(", "system(", "eval(", "wp_remote_post(",
    "wp_insert_post(", "update_option(", "delete_option(",
    "legacy_owner_transfer_apply(", "control_plane_selected_head_apply(",
):
    if side_effect in acceptance:
        raise AssertionError("unexpected mutation or OS side effect in acceptance read: " + side_effect)
must(acceptance_test, "eight stages; no inferred completion or mutation", "acceptance runtime test missing")
must(servers, "'mad4b/operational-acceptance-evidence'", "unified evidence missing from core MCP read tools")
must(remediation, "MAD4B_SCP_Operational_Acceptance_Evidence::boot();", "new evidence class not booted")
must(operator, "'mad4b/operational-acceptance-evidence'", "operator task path missing unified evidence")
must(workflow, "operational-acceptance-evidence-runtime.php", "native PHP matrix missing acceptance runtime")
must(workflow, "class-mad4b-scp-operational-acceptance-evidence.php", "native PHP lint missing acceptance class")
binding = (root / "includes/class-mad4b-scp-deployment-binding-setup.php").read_text(encoding="utf-8")
binding_test = (root / "tests/deployment-binding-setup-runtime.php").read_text(encoding="utf-8")
for marker in (
    "const PLAN = 'mad4b/deployment-binding-setup-plan'",
    "const STATUS = 'mad4b/deployment-binding-setup-status'",
    "const APPLY = 'mad4b/deployment-binding-setup-apply'",
    "MAD4B_SCP_Site_Profile::deployment_binding_digest()",
    "MAD4B_SCP_Site_Profile::commit_record_with_audit(",
    "MAD4B_SCP_Authorization::authorize_mutation(",
    "MAD4B_SCP_Authorization::claim_mutation(",
    "MAD4B_SCP_Site_Profile_Enrollment::can_execute(",
    "MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(",
    "'existing_binding_must_not_be_replaced'",
    "'HOST_SECRET_NOT_CONFIGURED'",
    "'EXACT_STAGING_IDENTITY_BLOCKED'",
    "expected_site_uuid",
    "expected_profile_digest",
    "_mad4b_approval_ticket_id",
    "'production_authorized' => false",
):
    must(binding, marker, "deployment binding setup must keep exact owner and protected Host trust")
for forbidden in (
    "random_bytes(", "shell_exec(", "proc_open(", "system(",
    "file_put_contents(", "update_option(", "wp_remote_post(",
):
    if forbidden in binding:
        raise AssertionError("MCP-side deployment binding must not manufacture or write a Host secret: " + forbidden)
must(binding_test, "full original identity and feature flags preserved byte-for-byte",
    "binding setup identity regression fixture missing")
must(remediation, "MAD4B_SCP_Deployment_Binding_Setup::boot();",
    "binding setup not registered in canonical remediation plane")
for ability in (
    "'mad4b/deployment-binding-setup-status'",
    "'mad4b/deployment-binding-setup-plan'",
    "'mad4b/deployment-binding-setup-apply'",
):
    must(servers, ability, "binding setup not exposed on governed MCP surface")
must(workflow, "deployment-binding-setup-runtime.php", "binding fixture not in native PHP matrix")
must(workflow, "class-mad4b-scp-deployment-binding-setup.php", "binding source not linted in PHP matrix")
native_binding = (root / "includes/class-mad4b-scp-deployment-binding-native.php").read_text(encoding="utf-8")
native_binding_test = (root / "tests/deployment-binding-native-runtime.php").read_text(encoding="utf-8")
site_profile = (root / "includes/class-mad4b-scp-site-profile.php").read_text(encoding="utf-8")
for marker in (
    "const STATUS = 'mad4b/deployment-binding-native-status'",
    "const PLAN = 'mad4b/deployment-binding-native-plan'",
    "const APPLY = 'mad4b/deployment-binding-native-apply'",
    "MAD4B_SCP_Authorization::authorize_mutation(",
    "MAD4B_SCP_Authorization::claim_mutation(",
    "MAD4B_SCP_Site_Profile::commit_record_with_audit(",
    "MAD4B_SCP_Site_Profile_Enrollment::can_execute(",
    "'staging' !== ( $site['configured_environment'] ?? '' )",
    "'production_authorized' => false",
    "'independent_host_attestation_certified' => false",
    "function private_location(",
    "fileperms(",
    "random_bytes( 48 )",
    "'x' )",
):
    must(native_binding, marker, "missing safe one-approved host-local binding contract")
for forbidden in ("update_option(", "wp_remote_post(", "shell_exec(", "proc_open("):
    if forbidden in native_binding:
        raise AssertionError("native host binding must not write arbitrary WordPress options or execute shell: " + forbidden)
must(native_binding_test, "single approved WordPress native transaction creates and binds key",
    "native binding integration fixture missing")
must(site_profile, "MAD4B_SCP_Deployment_Binding_Native::read_host_secret(",
    "host-local secret is not automatically read during WordPress boot")
must(remediation, "MAD4B_SCP_Deployment_Binding_Native::boot();",
    "native binding not mounted in canonical boot")
for marker in (
    "'mad4b/deployment-binding-native-status'",
    "'mad4b/deployment-binding-native-plan'",
    "'mad4b/deployment-binding-native-apply'",
):
    must(servers, marker, "native host binding missing from typed MCP servers")
must(workflow, "deployment-binding-native-runtime.php", "native binding PHP matrix missing")
must(workflow, "class-mad4b-scp-deployment-binding-native.php", "native binding PHP lint missing")
must(workflow, "brand-core-remote-recovery-runtime.php", "native PHP matrix missing")
must(workflow, "class-mad4b-scp-brand-core-remote-recovery.php", "native lint missing")
must(workflow, "brand-core-remote-recovery-contract.py", "static contract gate missing")
must(runbook, "durable_db_recovery", "Host-runner and DB gap not documented")
must(runbook, "include_host_challenge", "nonce-bound read flow missing")
print("mad4b.site-control-plane.brand-core-remote-recovery.contract.v1: PASS")
