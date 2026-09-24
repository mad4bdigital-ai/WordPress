#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ABILITIES = (ROOT / "includes" / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
SERVERS = (ROOT / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")


def require(condition, message):
    if not condition:
        raise SystemExit(message)


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

require("MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" in SERVERS, "full staging read diagnostics must be mounted on direct ChatGPT transport")
require("mad4b/full-staging-authority-apply" not in core_chatgpt, "full staging apply must never be directly mounted on ChatGPT")

# Large capability families must not be directly merged back into tools/list.
chatgpt_body = SERVERS.split("public static function chatgpt_tools()", 1)[1].split("public static function chatgpt_full_catalog_candidates()", 1)[0]
for forbidden in [
    "self::external_write_tools()",
    "self::write_tools()",
    "$registry->ability_names( 'read' )",
    "self::core_tools( 'mad4b-content' )",
    "self::core_tools( 'mad4b-admin' )",
]:
    require(forbidden not in chatgpt_body, f"large capability catalog leaked back into direct tools/list: {forbidden}")
require("$meta_write_transport = array( 'mad4b/write-execute' )" in chatgpt_body, "only the bounded write dispatcher may represent normal governed writes directly")

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

require("'mad4b/staging-write-grant-reconcile'" in SERVERS, "bounded grant reconciliation must remain directly projectable")
require("'mad4b/staging-write-candidate-bind'" in SERVERS, "bounded candidate binding must remain directly projectable")

print("mad4b.chatgpt-refresh-minimal-catalog.v3: PASS")
