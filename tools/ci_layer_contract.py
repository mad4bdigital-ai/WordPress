#!/usr/bin/env python3
"""Source-owned non-authorizing self-test for the independent MCP CI layers."""
from __future__ import annotations

import argparse
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/mad4b-ci-layered-mcp.yml"
SERVER = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-servers.php"
NATIVE = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/runtime-native-capability-semantics.php"
LAYERS = {
    "l1-source": "L1",
    "l2-hermetic": "L2",
    "l2-native-wordpress": "L2",
    "l3-mcp-protocol": "L3",
    "l4-release-integrity": "L4",
}


def ensure(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def inventory(source: str) -> tuple[list[str], list[str]]:
    declared = re.search(
        r"public static function expected_server_ids\(\)\s*\{\s*return array\((.*?)\);",
        source, re.S,
    )
    ensure(declared is not None, "Missing declared MCP server inventory")
    expected = re.findall(r"'(mad4b-[a-z0-9-]+)'", declared.group(1))
    sections = source.split("public function register_servers( $adapter )", 1)
    ensure(len(sections) == 2, "Missing MCP server registration method")
    body = sections[1].split("private function create( $adapter", 1)[0]
    registered = re.findall(
        r"\$this->create\(\s*\$adapter,\s*'(mad4b-[a-z0-9-]+)'", body
    )
    return expected, registered


def verify_registration(source: str) -> None:
    expected, registered = inventory(source)
    ensure(bool(expected), "Empty declared MCP inventory")
    ensure(len(set(expected)) == len(expected), "Duplicate declared MCP server")
    ensure(len(set(registered)) == len(registered), "Duplicate registered MCP server")
    ensure(set(expected) == set(registered), "Declared/registered MCP inventory drift")
    # Count is deliberately not pinned. Additions can pass if registry and
    # registration are consistent; actual functionality remains L2/L3's job.


def parse_jobs(workflow: str) -> dict[str, str]:
    match = re.search(r"^jobs:\s*$", workflow, re.M)
    ensure(match is not None, "Workflow missing jobs")
    rows = re.split(r"(?=^  [a-z][a-z0-9-]*:\s*$)", workflow[match.end():], flags=re.M)
    result: dict[str, str] = {}
    for row in rows:
        found = re.match(r"^  ([a-z][a-z0-9-]*):[ \t]*(?:\r?\n|$)", row)
        if found:
            ensure(found.group(1) not in result, "Duplicate job identifier")
            result[found.group(1)] = row
    return result


def verify_workflow(workflow: str) -> None:
    jobs = parse_jobs(workflow)
    ensure(set(LAYERS).issubset(jobs), "A mandatory layer or native integration job is missing")
    ensure("pull_request:" in workflow and "workflow_dispatch:" in workflow,
           "Missing PR or manual verification entrypoint")
    ensure("contents: read" in workflow, "CI must be read-only")
    ensure(not re.search(r"^\s+contents:\s+write\s*$", workflow, re.M),
           "Repository-write token not allowed")
    for job, layer in LAYERS.items():
        section = jobs[job]
        ensure(f"name: {layer} /" in section, f"Wrong public status identity: {job}")
        ensure(not re.search(r"^\s+needs:", section, re.M),
               f"{job} depends on a different layer")
        ensure("continue-on-error:" not in section,
               f"{job} must not silently green a failure")
        ensure("github.event.pull_request.head.sha || github.sha" in section,
               f"{job} must check out the exact PR head")
    ensure("runtime-native-capability-semantics.php" in jobs["l2-native-wordpress"],
           "No real WordPress meta-capability regression")
    ensure("mcp-preflight-registration-failure-runtime.php" in jobs["l2-hermetic"],
           "Missing catalog fault/adapter rejection regression")
    ensure("mcp-adapter serve --server=mad4b-chatgpt" in jobs["l3-mcp-protocol"],
           "Missing MCP initialize/tools-list protocol execution")
    ensure("curl " in jobs["l3-mcp-protocol"]
           and "unauthenticated" in jobs["l3-mcp-protocol"].lower(),
           "Missing external HTTP unauthorized boundary proof")
    ensure("production_readiness_registry.py self-test" in jobs["l4-release-integrity"],
           "L4 missing dynamic coverage policy regression")
    ensure("production_readiness_matrix.py validate" in jobs["l4-release-integrity"],
           "L4 missing immutable readiness matrix validation")


def verify_native_fixture(source: str) -> None:
    ensure("map_meta_cap( 'edit_post'" in source, "Must exercise the real meta-capability mapper")
    ensure("current_user_can( 'edit_post'" in source, "Must exercise native edit_post")
    ensure("wp_set_current_user( $subscriber_id )" in source, "Missing negative user case")
    ensure("function current_user_can(" not in source,
           "This test must not replace WordPress capabilities with a mock")


def self_test() -> None:
    base = (
        "<?php public static function expected_server_ids() { return array( 'mad4b-read' ); } "
        "public function register_servers( $adapter ) { "
        "$this->create( $adapter, 'mad4b-read', 'description' ); } "
        "private function create( $adapter ) {}"
    )
    verify_registration(base)
    verify_registration(base.replace(
        "array( 'mad4b-read' )", "array( 'mad4b-read', 'mad4b-direct' )"
    ).replace(
        "$this->create( $adapter, 'mad4b-read', 'description' );",
        "$this->create( $adapter, 'mad4b-read', 'description' ); "
        "$this->create( $adapter, 'mad4b-direct', 'description' );",
    ))
    for broken in (
        base.replace("'mad4b-read', 'description'", "'mad4b-other', 'description'"),
        base.replace(
            "$this->create( $adapter, 'mad4b-read', 'description' );",
            "$this->create( $adapter, 'mad4b-read', 'description' ); "
            "$this->create( $adapter, 'mad4b-read', 'description' );",
        ),
    ):
        try:
            verify_registration(broken)
        except ValueError:
            pass
        else:
            raise AssertionError("Mutated MCP inventory evaded CI verifier")
    print("CI_LAYER_MUTATION_AND_INVENTORY_SELF_TEST: PASS")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["check", "self-test"])
    args = parser.parse_args()
    if args.mode == "self-test":
        self_test()
    else:
        verify_registration(SERVER.read_text(encoding="utf-8"))
        verify_workflow(WORKFLOW.read_text(encoding="utf-8"))
        verify_native_fixture(NATIVE.read_text(encoding="utf-8"))
        print("CI_LAYER_ARCHITECTURE: PASS (L1/L2/L3/L4 independent, non-authorizing)")


if __name__ == "__main__":
    main()
