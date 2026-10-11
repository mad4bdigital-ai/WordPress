from pathlib import Path

root = Path(__file__).resolve().parents[1]
remote = (root / "includes/class-mad4b-scp-brand-core-remote-recovery.php").read_text(encoding="utf-8")
remediation = (root / "includes/class-mad4b-scp-operational-remediation.php").read_text(encoding="utf-8")
parity = (root / "includes/class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8")
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

must(workflow, "brand-core-remote-recovery-runtime.php", "native PHP matrix missing")
must(workflow, "class-mad4b-scp-brand-core-remote-recovery.php", "native lint missing")
must(workflow, "brand-core-remote-recovery-contract.py", "static contract gate missing")
must(runbook, "durable_db_recovery", "Host-runner and DB gap not documented")
must(runbook, "include_host_challenge", "nonce-bound read flow missing")
print("mad4b.site-control-plane.brand-core-remote-recovery.contract.v1: PASS")
