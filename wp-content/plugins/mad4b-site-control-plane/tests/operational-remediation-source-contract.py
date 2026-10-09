#!/usr/bin/env python3
"""Static source admission for universal, read-only operational remediation."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
control = (ROOT / "includes/class-mad4b-scp-operational-remediation.php").read_text(encoding="utf-8")
staging = (ROOT / "includes/class-mad4b-scp-staging-certification.php").read_text(encoding="utf-8")
preflight = (ROOT / "tests/feature007-manual-preflight.py").read_text(encoding="utf-8")

needed = [
    "final class MAD4B_SCP_Operational_Remediation",
    "const STATUS_ABILITY = 'mad4b/operational-remediation-status'",
    "const PREPARE_ABILITY = 'mad4b/operational-remediation-prepare'",
    "public static function register_abilities()",
    "public static function status( $input = array() )",
    "public static function prepare( $input = array() )",
    "public static function reduce( array $native, array $plan, $live_requested = false )",
    "public static function prepare_from_plan( array $plan, $id, $sha, $source )",
    "native_convergence_readiness_conflict:",
    "PROVIDER_DISCOVERY_REQUIRED",
    "SEPARATE_GOVERNED_APPROVAL_REQUIRED",
    "EXTERNAL_OR_OWNER_EVIDENCE_REQUIRED",
    "REPLAN_REQUIRED",
    "exact_staging_site_binding_unavailable",
    "unauthorized_plan_action:",
    "missing_remediation_action_for_gate:",
    "'ready_for_automatic_repair' => false",
    "'full_release_certified' => false",
    "'ready_for_dispatch' => false",
    "'approval_issued' => false",
    "'mutation_performed' => false",
    "'production_mutation_performed' => false",
    "'include_authoritative_content' => false",
    "'include_rendered_frontend' => false",
    "'surface' => 'read'",
    "'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' )",
]
for term in needed:
    if term not in control:
        raise SystemExit("OPERATIONAL_CONTRACT_MISSING:" + term)
if control.count("final class MAD4B_SCP_Operational_Remediation") != 1:
    raise SystemExit("DUPLICATE_OPERATIONAL_CLASS")
for term in (
    "wp_insert_post(", "wp_update_post(", "update_option(", "file_put_contents(",
    "curl_exec(", "wp_remote_post(", "::apply(", "::execute_mutation(",
    "shell_exec(", "proc_open(", "wp_set_object_terms(",
):
    if term in control:
        raise SystemExit("MUTATION_OR_NETWORK_CALL_IN_READ_ONLY_CONTROL:" + term)
for term in (
    "class-mad4b-scp-operational-remediation.php",
    "MAD4B_SCP_Operational_Remediation::boot();",
):
    if main.count(term) != 1:
        raise SystemExit("PLUG_IN_BOOT_WIRING_INVALID:" + term)
if main.index("class-mad4b-scp-staging-certification.php") > main.index(
    "class-mad4b-scp-operational-remediation.php"
):
    raise SystemExit("DEPENDENCY_BOOT_ORDER_INVALID")
for term in ("public static function convergence_plan(", "public static function status("):
    if term not in staging:
        raise SystemExit("REUSED_NATIVE_REDUCER_NOT_AVAILABLE:" + term)
for term in (
    '"staging-certification-contract.py"',
    '"operational-remediation-control-runtime.php"',
    '"class-mad4b-scp-staging-certification.php"',
    '"class-mad4b-scp-operational-remediation.php"',
    "sorted(owners) != paths",
):
    if term not in preflight:
        raise SystemExit("OFFLINE_PREFLIGHT_COVERAGE_INCOMPLETE:" + term)
print("OPERATIONAL_REMEDIATION_SOURCE_CONTRACT: PASS", len(needed))
