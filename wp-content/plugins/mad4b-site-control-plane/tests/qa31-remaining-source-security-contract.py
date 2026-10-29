#!/usr/bin/env python3
"""Closed-path CI review for unresolved Content Readiness/local Admin sources.

This checks selected source safety invariants and runs real hermetic PHP denial
regressions. CI success is NOT live acceptance, certification or deploy consent.
"""
from pathlib import Path
import json
import shutil
import subprocess
import sys

plugin = Path(__file__).resolve().parents[1]
includes = plugin / "includes"
critical = {
    "class-mad4b-scp-content-completeness.php": (
        "class MAD4B_SCP_Content_Completeness",
        "MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options')",
        "check_admin_referer('mad4b_completeness_save')",
        "if(!self::admin_permission())wp_die('Forbidden',403)",
        "Rules readback mismatch.",
    ),
    "class-mad4b-scp-readiness-integrations.php": (
        "class MAD4B_SCP_Readiness_Integrations",
        "const CAP='mad4b_manage_company_operations'",
        "MAD4B_SCP_Admin_Route_Registry::schedule_submenu",
        "return current_user_can('manage_options') || current_user_can(self::CAP)",
        "add_submenu_page('mad4b-company'",
    ),
    "class-mad4b-scp-readiness-preactivation.php": (
        "class MAD4B_SCP_Readiness_PreActivation",
        "execution_locked() {return true;}",
        "require_human_approval'=>true",
        "check_admin_referer('mad4b_readiness_preactivation_save')",
        "if(!self::can_manage())wp_die('Forbidden',403)",
    ),
    "class-mad4b-scp-readiness-scopes.php": (
        "class MAD4B_SCP_Readiness_Scopes",
        "MAX_ITEMS_PER_WORK_PACKET = 50",
        "MAD4B_SCP_Admin_Route_Registry::schedule_submenu",
        "MAD4B_SCP_Readiness_Integrations::can_manage()",
        "if(!self::can_manage())wp_die('Forbidden',403)",
    ),
    "class-mad4b-scp-readiness-workflow-options.php": (
        "class MAD4B_SCP_Readiness_Workflow_Options",
        "MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options')",
        "check_admin_referer('mad4b_readiness_workflow_save')",
        "External notification adapters are not yet certified.",
        "Readback mismatch; settings not confirmed.",
    ),
    "class-mad4b-scp-local-client-admin.php": (
        "class MAD4B_SCP_Local_Client_Admin",
        "'local' !== wp_get_environment_type()",
        "MAD4B_LOCAL_CLIENT_SITE_UUID",
        "check_admin_referer( 'mad4b_local_client_provision' )",
        "Credential vault write failed; password revoked.",
    ),
    "class-mad4b-scp-operational-remediation.php": (
        "class MAD4B_SCP_Operational_Remediation",
        "if ( ! current_user_can( 'manage_options' ) )",
        "'automatic_execution_allowed' => false",
        "'mutation_performed' => false",
        "'ready_for_automatic_repair' => false",
    ),
    "class-mad4b-scp-operator-journeys.php": (
        "class MAD4B_SCP_Operator_Journeys",
        "'category' => 'mad4b-read'",
        "'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' )",
        "'annotations' => array( 'readonly' => true",
        "'show_in_rest' => false",
    ),
}

for filename, fragments in critical.items():
    path = includes / filename
    if path.is_symlink() or not path.is_file():
        raise SystemExit("QA31_EXACT_SOURCE_MISSING: " + filename)
    source = path.read_text(encoding="utf-8")
    for snippet in fragments:
        if snippet not in source:
            raise SystemExit("QA31_SECURITY_GUARD_DRIFT: " + filename + " :: " + snippet)

policy = plugin / "config/adapter-registration-policy.json"
if policy.is_symlink():
    raise SystemExit("QA31_ADAPTER_SOURCE_SYMLINK")
doc = json.loads(policy.read_text(encoding="utf-8"))
for key, value in (
    ("contract", "mad4b.adapter-registration-policy.v1"),
    ("scope", "bundled_built_in_classes_only"),
    ("default", "inventory_only_source_review_required"),
    ("unknown_plugin_mutation", "deny"),
    ("unknown_plugin_discovery", "read_only"),
    ("new_adapter_without_review", "candidate_only"),
):
    if doc.get(key) != value:
        raise SystemExit("QA31_ADAPTER_REGISTRATION_POLICY_DRIFT: " + key)
if (len(doc.get("approved_classes", [])) != len(set(doc["approved_classes"]))
    or "MAD4B_SCP_ChatbotX_Provider_Adapter" not in doc["approved_classes"]):
    raise SystemExit("QA31_ADAPTER_APPROVED_CLASS_REVIEW_DRIFT")

php = shutil.which("php")
if not php:
    raise SystemExit("QA31_PHP_REQUIRED_FOR_EXECUTED_SECURITY_FIXTURES")
for fixture in (
    "local-client-credential-lifecycle-runtime.php",
    "operational-remediation-control-runtime.php",
    "operator-journeys-runtime.php",
    "content-readiness/isolated-contract.php",
    "content-readiness/preactivation-team-contract.php",
):
    path = plugin / "tests" / fixture
    if not path.is_file() or path.is_symlink():
        raise SystemExit("QA31_FIXTURE_MISSING: " + fixture)
    result = subprocess.run([php, str(path)], capture_output=True, text=True,
                            timeout=25, check=False)
    if result.returncode:
        raise SystemExit("QA31_EXECUTED_DENIAL_REGRESSION_FAILED: "
                         + fixture + "\n" + result.stderr[-700:])
route = plugin / "tests/admin-route-registry-contract.py"
result = subprocess.run([sys.executable, str(route)], capture_output=True,
                        text=True, timeout=25, check=False)
if result.returncode:
    raise SystemExit("QA31_ADMIN_ROUTE_REGRESSION_FAILED: " + result.stderr[-700:])
print("QA31_REMAINING_SOURCE_SECURITY_CONTRACT: PASS 9 sources / 6 executed suites")
