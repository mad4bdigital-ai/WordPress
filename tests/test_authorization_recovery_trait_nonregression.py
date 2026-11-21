#!/usr/bin/env python3
"""Prove exact byte-preserving Authorization recovery extraction from reviewed predecessor.

No authority is granted by this test. Requires complete ancestry (fetch-depth: 0).
"""
from pathlib import Path
import subprocess

REPO = Path(__file__).resolve().parents[1]
BASE = "4395f71da86859439f21cfaa6d77cf2b55ee4eb1"
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

def main() -> None:
    original = subprocess.run(
        ["git", "show", BASE + ":" + FACADE], cwd=REPO,
        capture_output=True, text=True, check=True,
    ).stdout
    facade = (REPO / FACADE).read_text(encoding="utf-8")
    trait = (REPO / TRAIT).read_text(encoding="utf-8")
    check(original, facade, trait)
    # Regression: missing source loader/use, or any altered method byte fails.
    for damaged_facade, damaged_trait in (
        (facade.replace(REQUIRE_NEW, ""), trait),
        (facade.replace(USE, ""), trait),
        (facade, trait.replace("blind_retry_allowed", "blind_retry_may_skip", 1)),
    ):
        try:
            check(original, damaged_facade, damaged_trait)
        except AssertionError:
            continue
        raise AssertionError("Unauthorized Authorization source change accepted")
    print("AUTHORIZATION_RECOVERY_TRAIT_BYTE_PARITY: PASS (4 moved methods, loader/use, denials)")

if __name__ == "__main__":
    main()
