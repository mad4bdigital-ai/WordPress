#!/usr/bin/env python3
"""Prove exact byte-preserving Authorization recovery extraction from reviewed predecessor.

No authority is granted by this test. Requires complete ancestry (fetch-depth: 0).
"""
from pathlib import Path
import subprocess
import hashlib
import json

REPO = Path(__file__).resolve().parents[1]
BASE = "4395f71da86859439f21cfaa6d77cf2b55ee4eb1"
PR951 = "1d8a9b4a491368c2f648a0f6e424f7972e20dce0"
PR937 = "a044005e3b960c874ad492276a07814bf66e350d"
REVIEW = "governance/release-parity/qa31-pr951-pr937-composite-source-gates-20261120.json"
REVIEWED_MERGE_BLOB = "6283b4a2168dfbbb267d98e7600f896fed8a4f64"
REVIEWED_PR951_BLOB = "4f6c83ce401008c3ae5335895d776511a4cc64fd"
REVIEWED_PR937_BLOB = "a2ea7dd18c8dbe2326ef5c9d2f7c97bfc170f994"
SPLIT_TRAITS = (
    "claimed-callback", "observation", "remote-denial",
)
FACADE = "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authorization.php"
TRAIT = "wp-content/plugins/mad4b-site-control-plane/includes/trait-mad4b-scp-authorization-recovery-state.php"
MARK_BEGIN = "\tprivate static function execution_attempt_sha256("
MARK_END = "\tpublic static function finalize_execution_claim("
REQUIRE_OLD = "if ( ! class_exists( 'MAD4B_SCP_Authorization_Target_Fingerprint' ) ) require_once __DIR__ . '/class-mad4b-scp-authorization-target-fingerprint.php';\n"
REQUIRE_NEW = "require_once __DIR__ . '/trait-mad4b-scp-authorization-recovery-state.php';\n"
CLASS = "final class MAD4B_SCP_Authorization {\n"
USE = "\tuse MAD4B_SCP_Authorization_Recovery_State_Trait;\n"
TRAIT_HEADER = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n/** Original, byte-preserved execution attempt recovery and boundary observation helpers. */\ntrait MAD4B_SCP_Authorization_Recovery_State_Trait {\n"
NAMES = (
    "execution_attempt_sha256", "execution_attempt_state",
    "execution_boundary_verified", "local_approval_planner_execution",
)

def check(original: str, facade: str, trait: str) -> None:
    assert original.count(MARK_BEGIN) == 1 and original.count(MARK_END) == 1
    assert original.count(REQUIRE_OLD) == 1 and original.count(CLASS) == 1
    start, end = original.index(MARK_BEGIN), original.index(MARK_END)
    assert start < end
    extracted = original[start:end]
    assert all(extracted.count("function " + name + "(") == 1 for name in NAMES)
    assert len(extracted.splitlines()) > 95
    assert trait == TRAIT_HEADER + extracted + "}\n", "Trait body does not match previously reviewed original"
    remainder = original[:start] + original[end:]
    expect = remainder.replace(REQUIRE_OLD, REQUIRE_OLD + REQUIRE_NEW).replace(CLASS, CLASS + USE)
    assert facade == expect, "Authorization facade diverged beyond the exact extraction"
    assert len(facade.splitlines()) <= 950, "Authorization size budget exceeded"
    assert all("function " + name + "(" not in facade for name in NAMES)
    assert all(("function " + name + "(") in trait for name in NAMES)

def git_blob(value: str) -> str:
    raw = value.encode("utf-8")
    return hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + bytes([0]) + raw).hexdigest()


def source(ref: str, path: str) -> str:
    return subprocess.run(
        ["git", "show", ref + ":" + path], cwd=REPO,
        capture_output=True, text=True, check=True,
    ).stdout


def check_reviewed_composition(facade: str) -> None:
    """Accept exactly the separately reviewed merge, never arbitrary drift."""
    review = json.loads((REPO / REVIEW).read_text(encoding="utf-8"))
    assert review["contract"] == "mad4b.qa31-pr951-pr937-composite-source-gates.v1"
    assert review["pr951_exact_head_sha"] == PR951
    assert review["pr937_exact_head_sha"] == PR937
    assert review["authorizing"] is False
    assert review["production_authorized"] is False
    assert review["source_promotion_approved"] is False
    assert review["release_gate"] == "BLOCKED_SOURCE_RECONCILIATION"
    entries = [row for row in review["source_entries"] if row["path"] == FACADE]
    assert len(entries) == 1
    row = entries[0]
    assert row["source_blob_sha"] == REVIEWED_MERGE_BLOB
    assert row["pr951_parent_blob_sha"] == REVIEWED_PR951_BLOB
    assert row["pr937_parent_blob_sha"] == REVIEWED_PR937_BLOB
    assert row["production_authorized"] is False
    assert git_blob(facade) == REVIEWED_MERGE_BLOB, "Unreviewed Authorization facade drift"
    assert git_blob(source(PR937, FACADE)) == REVIEWED_PR937_BLOB

    # All three PR937 delegated traits are independently frozen at their
    # real original Git objects. A later file change cannot silently
    # substitute different implementations for the reviewed facade.
    for suffix in SPLIT_TRAITS:
        trait_name = {
            "claimed-callback": "MAD4B_SCP_Authorization_Claimed_Callback",
            "observation": "MAD4B_SCP_Authorization_Observation",
            "remote-denial": "MAD4B_SCP_Authorization_Remote_Denial",
        }[suffix]
        path = FACADE.replace(
            "class-mad4b-scp-authorization.php",
            "class-mad4b-scp-authorization-" + suffix + ".php",
        )
        assert (REPO / path).read_text(encoding="utf-8") == source(PR937, path), (
            "PR937 Authorization delegated trait drift: " + suffix
        )
        assert facade.count("use " + trait_name + ";") == 1
        assert facade.count("require_once __DIR__ . '/class-mad4b-scp-authorization-" + suffix + ".php';") == 1


def main() -> None:
    original = subprocess.run(
        ["git", "show", BASE + ":" + FACADE], cwd=REPO,
        capture_output=True, text=True, check=True,
    ).stdout
    facade = (REPO / FACADE).read_text(encoding="utf-8")
    trait = (REPO / TRAIT).read_text(encoding="utf-8")
    parent_facade = source(PR951, FACADE)
    assert git_blob(parent_facade) == REVIEWED_PR951_BLOB
    assert trait == source(PR951, TRAIT), "Original Recovery trait changed after PR951"
    # Historical extraction stays byte-exact against the frozen PR951
    # source, irrespective of later separately reviewed trait composition.
    check(original, parent_facade, trait)
    check_reviewed_composition(facade)
    # Old extraction mutants and new independently reviewed merge mutants
    # both fail, rather than relaxing the original parity contract.
    for damaged_facade, damaged_trait in (
        (parent_facade.replace(REQUIRE_NEW, ""), trait),
        (parent_facade.replace(USE, ""), trait),
        (parent_facade, trait.replace("blind_retry_allowed", "blind_retry_may_skip", 1)),
    ):
        try:
            check(original, damaged_facade, damaged_trait)
        except AssertionError:
            continue
        raise AssertionError("Unauthorized Recovery extraction change accepted")
    for tampered in (
        facade.replace(REQUIRE_NEW, "", 1),
        facade.replace(USE, "", 1),
        facade.replace("use MAD4B_SCP_Authorization_Remote_Denial;", "", 1),
        facade + "\n/* unreviewed alteration */\n",
    ):
        try:
            check_reviewed_composition(tampered)
        except AssertionError:
            continue
        raise AssertionError("Unreviewed merged Authorization facade accepted")
    print("AUTHORIZATION_RECOVERY_TRAIT_BYTE_PARITY: PASS (original PR951 extraction + exact PR937 delegated traits, source-only)")

if __name__ == "__main__":
    main()
