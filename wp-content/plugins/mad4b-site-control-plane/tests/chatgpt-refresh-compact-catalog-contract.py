#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ABILITIES = (ROOT / "includes" / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
SERVERS = (ROOT / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
GRANT_RECONCILE = (ROOT / "includes" / "class-mad4b-scp-staging-write-grant-reconciliation.php").read_text(encoding="utf-8")
GRANT_PLAN = (ROOT / "includes" / "class-mad4b-scp-staging-write-grant-reconciliation-plan.php").read_text(encoding="utf-8")
CANDIDATE_BINDING = (ROOT / "includes" / "class-mad4b-scp-staging-write-candidate-binding.php").read_text(encoding="utf-8")
MAIN = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")


def require(condition, message):
    if not condition:
        raise SystemExit(message)


for ability in [
    "context/brand-draft-append",
    "context/materialize-brand-draft",
    "context/reconcile-brand-materialization",
    "context/rollback-materialized-brand-draft",
    "context/source-scan-apply",
]:
    require(f"'{ability}' => 'google_drive_context'" in GRANT_RECONCILE, f"reviewed Google Drive staging grant missing from bounded reconciliation allowlist: {ability}")
for marker in [
    "class-mad4b-scp-staging-write-grant-reconciliation-plan.php",
    "class-mad4b-scp-staging-write-grant-reconciliation.php",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::boot();",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::boot();",
]:
    require(marker in MAIN, f"narrow staging write reconciliation runtime bootstrap missing: {marker}")

# Read transport remains compact, readonly and non-authorizing.
for marker in [
    "'mad4b/tool-discover'",
    "'mad4b/tool-info'",
    "'mad4b/read-execute'",
    "mad4b.chatgpt-read-discovery.v1",
    "mad4b.chatgpt-read-ability-info.v1",
    "mad4b.chatgpt-read-execute.v1",
    "MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate",
    "mad4b_read_dispatch_mutation_denied",
    "true !== $annotations['readonly']",
    "$ability->execute( $params )",
]:
    require(marker in ABILITIES, f"minimal read transport invariant missing: {marker}")

read_dispatch = ABILITIES.split("private function governed_read_target", 1)[1].split("public function tool_discover", 1)[0]
for forbidden in [
    "MAD4B_SCP_Authorization::authorize_mutation",
    "grant_ability(",
    "update_option(",
    "wp_insert_post(",
    "Plugin_Upgrader",
]:
    require(forbidden not in read_dispatch, f"read dispatcher contains mutation/authority side effect: {forbidden}")

# Stable write inventory is now transported through a small exact-target surface.
for marker in [
    "'mad4b/write-discover'",
    "'mad4b/write-info'",
    "'mad4b/write-execute'",
    "'write_dispatch'",
    "mad4b.chatgpt-write-discovery.v1",
    "mad4b.chatgpt-write-ability-info.v1",
    "mad4b.chatgpt-write-execute.v1",
    "MAD4B_SCP_Servers::is_external_write_candidate",
    "MAD4B_SCP_Servers::write_tools()",
    "mad4b_write_dispatch_target_not_runtime_eligible",
    "mad4b_write_dispatch_schema_drift",
    "expected_input_schema_sha256",
    "hash_equals",
    "$ability->execute( $params )",
]:
    require(marker in ABILITIES, f"governed write transport invariant missing: {marker}")

write_target = ABILITIES.split("private function governed_write_target", 1)[1].split("private function ability_input_schema_sha256", 1)[0]
require("false !== $annotations['readonly']" in write_target, "write dispatcher must reject any target not explicitly readonly=false")
require("MAD4B_SCP_Servers::write_tools()" in write_target, "write dispatcher must require current runtime eligibility for execution")

# Pre-authority convergence must never bypass the generic write dispatcher.
# The narrow composite is a separate guarded direct step-up tool.
require("private function is_staging_write_bootstrap_target" not in ABILITIES, "generic write dispatcher must not carry a pre-authority bootstrap exception")
for marker in [
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_step_up_tools()",
]:
    require(marker in SERVERS, f"bounded Write Authority projection missing: {marker}")

for marker in [
    "expected_package_manifest_digest",
    "expected_artifact_identity",
    "AUTHORITY_STEP_UP_SCOPE",
    "CHATGPT_CIMD_CLIENT_ID",
    "mad4b_grant_reconcile_https_required",
    "RECONCILE EXACT STAGING WRITE AUTHORITY",
    "candidate_binding_is_commit_point",
    "post_commit_governance_mutation",
    "mad4b/staging-write-authority-prepared",
]:
    require(marker in GRANT_RECONCILE, f"bounded Write Authority invariant missing: {marker}")

for marker in [
    "expected_package_manifest_digest",
    "expected_artifact_identity",
    "expected_missing_abilities",
    "plan_sha256",
    "mad4b_grant_reconcile_plan_https_required",
]:
    require(marker in GRANT_PLAN, f"four-part exact reconciliation plan binding missing: {marker}")

require("MAD4B_SCP_Staging_Write_Grant_Reconciliation::CONTRACT" in CANDIDATE_BINDING, "candidate binding must authorize the live reconciliation contract constant")
require("mad4b.staging-write-grant-reconciliation.v1" not in CANDIDATE_BINDING, "candidate binding must not hard-code the obsolete reconciliation v1 contract")
require("mad4b/staging-write-grant-reconciliation-complete" not in GRANT_RECONCILE, "no fallible composite completion audit may execute after the candidate-binding commit point")

write_execute = ABILITIES.split("public function write_execute", 1)[1].split("public function filesystem_list", 1)[0]
require("$ability->execute( $params )" in write_execute, "write dispatcher must delegate through the original WP_Ability execute path")
for forbidden in [
    "update_option(",
    "wp_insert_post(",
    "wp_update_post(",
    "$wpdb->",
    "Plugin_Upgrader",
    "grant_ability(",
]:
    require(forbidden not in write_execute, f"write dispatcher contains a direct mutation primitive: {forbidden}")

# The transport permission itself must not substitute for target authorization.
permission_section = ABILITIES.split("public function can_write_dispatch", 1)[1].split("public function write_discover", 1)[0]
require("MAD4B_SCP_Policy::can_admin()" in permission_section, "write dispatcher must require admin identity")
require("MAD4B_SCP_Policy::can_mutate()" in permission_section, "write dispatcher must require the global governed mutation gate")
require("governed_write_target" in permission_section, "write dispatcher permission must validate the exact target")
require("authorize_mutation" not in permission_section, "dispatcher must not mint or replace target authorization")

core_chatgpt = SERVERS.split("'mad4b-chatgpt' => array_merge( array(", 1)[1].split("), $governed_status", 1)[0]
for marker in [
    "'mad4b/tool-discover'",
    "'mad4b/tool-info'",
    "'mad4b/read-execute'",
    "'mad4b/write-discover'",
    "'mad4b/write-info'",
    "'mad4b/write-execute'",
    "'mad4b/plugin-package-plan'",
]:
    require(marker in core_chatgpt, f"required minimal direct ChatGPT tool missing: {marker}")

require("MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" in SERVERS, "bounded Write Authority plan must be projectable on enrolled Staging")
require("MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_step_up_tools()" in SERVERS, "bounded Write Authority apply must be conditionally projectable as a single-app step-up tool")
require("MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" in SERVERS, "full staging read diagnostics must be projectable on enrolled Staging")
require("MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()" in SERVERS, "full staging apply must be conditionally projectable as a single-app step-up tool")
require("MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" not in core_chatgpt, "non-Staging core ChatGPT catalog must not expose Staging authority diagnostics")
require("mad4b/full-staging-authority-apply" not in core_chatgpt, "full staging apply must never be statically mounted in the core ChatGPT catalog")

# Large capability families must not be directly merged back into tools/list.
chatgpt_body = SERVERS.split("public static function chatgpt_tools()", 1)[1].split("private static function chatgpt_internal_enrollment_mutations()", 1)[0]
for forbidden in [
    "self::external_write_tools()",
    "self::write_tools()",
    "$registry->ability_names( 'read' )",
    "self::core_tools( 'mad4b-content' )",
    "self::core_tools( 'mad4b-admin' )",
]:
    require(forbidden not in chatgpt_body, f"large capability catalog leaked back into direct tools/list: {forbidden}")
require("$step_up = array_merge( $narrow_step_up, $full_step_up )" in chatgpt_body, "bounded and full authority step-ups must be composed explicitly")
require("$enrollment_step_up = class_exists( 'MAD4B_SCP_Enrollment_Dispatch' )" in chatgpt_body, "bounded enrollment step-up must be projected explicitly")
require("array( MAD4B_SCP_Enrollment_Dispatch::EXECUTE_ABILITY )" in chatgpt_body, "only the bounded enrollment execute ability may join direct mutation transport")
require("$direct_mutation_transport = array_merge( array( 'mad4b/write-execute' ), $step_up, $enrollment_step_up )" in chatgpt_body, "direct mutations must be normal governed write dispatch plus guarded authority and bounded enrollment step-ups")
require("MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" in chatgpt_body, "unified enrolled Staging tools/list must include read-only full authority diagnostics")
require("MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()" in chatgpt_body, "unified enrolled Staging tools/list must project the composite apply only through the guarded step-up method")
for low_level in [
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
]:
    require(low_level not in chatgpt_body, f"low-level enrollment mutation leaked into direct ChatGPT tools/list: {low_level}")

# The full logical capability universe remains intact behind discovery.
full = SERVERS.split("public static function chatgpt_full_catalog_candidates()", 1)[1].split("public static function is_chatgpt_full_catalog_candidate", 1)[0]
for marker in [
    "self::core_tools( 'mad4b-read' )",
    "self::core_tools( 'mad4b-chatgpt' )",
    "self::chatgpt_enrollment_candidates()",
    "self::core_tools( 'mad4b-content' )",
    "self::core_tools( 'mad4b-admin' )",
    "self::external_write_tools()",
    "array( 'read', 'content', 'admin', 'write' )",
    "$registry->ability_names( $surface )",
]:
    require(marker in full, f"full governed capability universe lost coverage: {marker}")
require("'mad4b/database-raw-query'" in full and "array_diff" in full, "Raw SQL must remain explicitly excluded")
require("MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" in full, "logical discovery must include the bounded Write Authority plan")
require("MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_step_up_tools()" in full, "logical discovery must include the bounded Write Authority step-up when eligible")
require("MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()" in full, "logical discovery must include the guarded full-authority composite step-up when it is eligible")

internal_enrollment = SERVERS.split("private static function chatgpt_internal_enrollment_mutations()", 1)[1].split("private static function chatgpt_enrollment_candidates()", 1)[0]
for low_level in [
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
]:
    require(low_level in internal_enrollment, f"low-level enrollment mutation must remain internal: {low_level}")

enrollment_projection = SERVERS.split("private static function chatgpt_enrollment_candidates()", 1)[1].split("public static function chatgpt_full_catalog_candidates()", 1)[0]
require("self::chatgpt_internal_enrollment_mutations()" in enrollment_projection, "logical ChatGPT discovery must remove low-level enrollment mutations")

print("mad4b.chatgpt-refresh-minimal-catalog.v6: PASS")
