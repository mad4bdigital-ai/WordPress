#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ABILITIES = (ROOT / "includes" / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
SERVERS = (ROOT / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")


def require(condition, message):
    if not condition:
        raise SystemExit(message)


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
    "get_input_schema",
    "null === $target_input_schema",
    "$ability->execute( $params )",
    "'mutation_performed' => false",
]:
    require(marker in ABILITIES, f"compact read dispatch invariant missing: {marker}")

dispatch = ABILITIES.split("private function governed_read_target", 1)[1].split("public function tool_discover", 1)[0]
for forbidden in [
    "MAD4B_SCP_Authorization::authorize_mutation",
    "grant_ability(",
    "update_option(",
    "wp_insert_post(",
    "Plugin_Upgrader",
]:
    require(forbidden not in dispatch, f"read dispatcher contains mutation/authority side effect: {forbidden}")

execute = ABILITIES.split("public function read_execute", 1)[1].split("public function filesystem_list", 1)[0]
require("governed_read_target" in execute, "read execute must re-check governed readonly target")
require("$ability->execute( $params )" in execute, "read execute must delegate through WP_Ability::execute permission/schema checks")

core_chatgpt = SERVERS.split("'mad4b-chatgpt' => array_merge( array(", 1)[1].split("), $governed_status", 1)[0]
for marker in [
    "'mad4b/tool-discover'",
    "'mad4b/tool-info'",
    "'mad4b/read-execute'",
    "'mad4b/plugin-package-plan'",
]:
    require(marker in core_chatgpt, f"required direct ChatGPT tool missing: {marker}")

chatgpt_body = SERVERS.split("public static function chatgpt_tools()", 1)[1].split("public static function chatgpt_full_catalog_candidates()", 1)[0]
compact_anchor = """		$candidates = array_merge(
			self::core_tools( 'mad4b-chatgpt' ),
			self::core_tools( 'mad4b-enrollment' ),
			self::external_write_tools()
		);"""
require(compact_anchor in chatgpt_body, "enrolled ChatGPT direct catalog is not compact")
require("self::core_tools( 'mad4b-content' )" not in chatgpt_body.split(compact_anchor, 1)[1], "full content catalog leaked back into direct tools/list")
require("self::core_tools( 'mad4b-admin' )" not in chatgpt_body.split(compact_anchor, 1)[1], "full admin catalog leaked back into direct tools/list")

full = SERVERS.split("public static function chatgpt_full_catalog_candidates()", 1)[1].split("public static function is_chatgpt_full_catalog_candidate", 1)[0]
for marker in [
    "self::core_tools( 'mad4b-read' )",
    "self::core_tools( 'mad4b-chatgpt' )",
    "self::core_tools( 'mad4b-enrollment' )",
    "self::core_tools( 'mad4b-content' )",
    "self::core_tools( 'mad4b-admin' )",
    "self::external_write_tools()",
    "array( 'read', 'content', 'admin', 'write' )",
    "$registry->ability_names( $surface )",
]:
    require(marker in full, f"full governed capability universe lost coverage: {marker}")
require("'mad4b/database-raw-query'" in full, "full catalog helper must explicitly remove raw SQL")
require("array_diff" in full, "raw SQL exclusion must be explicit")

require("self::external_write_tools()" in chatgpt_body, "stable governed write catalog must remain directly visible")
require("'mad4b/staging-write-grant-reconcile'" in SERVERS, "bounded grant reconciliation must remain directly projectable")
require("'mad4b/staging-write-candidate-bind'" in SERVERS, "bounded candidate binding must remain directly projectable")

print("mad4b.chatgpt-refresh-compact-catalog.v1: PASS")
