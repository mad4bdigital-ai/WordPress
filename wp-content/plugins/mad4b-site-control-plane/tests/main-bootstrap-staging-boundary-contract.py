#!/usr/bin/env python3
"""Hermetic source-guard regression for exactly the reviewed main bootstrap additions.
No WordPress calls, environment writes, live network or source mutation.
"""
from pathlib import Path
import re

HERE = Path(__file__).resolve().parent
BOOT = HERE.parent / "mad4b-site-control-plane.php"
SOURCE = BOOT.read_text(encoding="utf-8")
STAGING_GUARD = "if ( 'staging' === wp_get_environment_type() ) {"
COMPANY = [
    "class-mad4b-scp-company-communication.php",
    "class-mad4b-scp-company-communication-team.php",
    "class-mad4b-scp-company-master-admin.php",
    "class-mad4b-scp-company-task-handoff.php",
    "class-mad4b-scp-company-user-lifecycle.php",
    "class-mad4b-scp-company-operations-policy.php",
    "class-mad4b-scp-company-release-status.php",
    "class-mad4b-scp-company-owner-activation.php",
    "class-mad4b-scp-company-tenant-migration.php",
    "class-mad4b-scp-migration-decision-catalog.php",
    "class-mad4b-scp-company-migration-decisions.php",
    "class-mad4b-scp-staging-brand-trial.php",
    "class-mad4b-scp-staging-context-fixtures.php",
    "class-mad4b-scp-staging-fixture-drafts.php",
]
READINESS = [
    "class-mad4b-scp-readiness-integrations.php",
    "class-mad4b-scp-readiness-scopes.php",
    "class-mad4b-scp-readiness-preactivation.php",
]

def guarded_block(text: str, comment: str) -> str:
    at = text.find(comment)
    if at < 0:
        raise AssertionError("missing reviewed bootstrap boundary: " + comment)
    open_at = text.find(STAGING_GUARD, at + len(comment))
    if open_at < 0 or open_at > at + len(comment) + 140:
        raise AssertionError("staging gate missing near " + comment)
    opening = text.index("{", open_at)
    depth = 1
    for index in range(opening + 1, len(text)):
        if text[index] == "{":
            depth += 1
        elif text[index] == "}":
            depth -= 1
            if depth == 0:
                return text[opening + 1:index]
    raise AssertionError("staging gate not terminated: " + comment)

def verify(text: str) -> None:
    company = guarded_block(text, "// REV121: explicitly Staging-only Company Operations modules.")
    readiness = guarded_block(text, "// Readiness Pre-Activation is staged as an additive capability only.")
    for name in COMPANY:
        needle = "require_once MAD4B_SCP_DIR . 'includes/" + name + "';"
        assert company.count(needle) == 1 and text.count(needle) == 1, name
    for name in READINESS:
        needle = "require_once MAD4B_SCP_DIR . 'includes/" + name + "';"
        assert readiness.count(needle) == 1 and text.count(needle) == 1, name
        boot = name.replace("class-mad4b-scp-", "").replace(".php", "")
        class_name = "MAD4B_SCP_" + "_".join(piece.capitalize() for piece in boot.split("-"))
        if boot == "readiness-preactivation":
            class_name = "MAD4B_SCP_Readiness_PreActivation"
        assert re.search(
            r"if\s*\(\s*'staging'\s*===\s*wp_get_environment_type\(\)\s*\)\s*\{\s*"
            + r"(?:(?!\}).)*?" + re.escape(class_name + "::boot();"),
            text, flags=re.S
        ), class_name + " invoked outside staging"
    for name in ("class-mad4b-scp-company-email-routing.php",
                 "class-mad4b-scp-company-sender-policy.php"):
        needle = "require_once MAD4B_SCP_DIR . 'includes/" + name + "';"
        assert text.count(needle) == 1, name
        first = text.find("if ( function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type() ) {")
        assert first >= 0 and needle in text[first:text.find("}", first)], name
    # Retain the real early-return, before any full runtime module loads.
    core_gate = "MAD4B_SCP_WPCLI_Passive_Gate::allowed_request()"
    assert text.index(core_gate) < text.index("// REV121:")
    early = text[text.index(core_gate):text.index("// REV121:")]
    assert re.search(r"\breturn\s*;", early), "passive CLI zero-touch gate removed"
    assert "MAD4B_SCP_Hub_JetEngine_Consumer_Governance::boot();" in text
    assert "MAD4B_SCP_Adaptive_Interaction_UI::boot();" in text

verify(SOURCE)
# Negative controls: stale/future refactors must not silently widen production.
for drift in (
    SOURCE.replace(STAGING_GUARD, "if ( true ) {", 1),  # readiness drift
    SOURCE.replace("// REV121: explicitly Staging-only Company Operations modules.\n" + STAGING_GUARD,
                   "// REV121: explicitly Staging-only Company Operations modules.\nif ( true ) {"),
    SOURCE.replace("class-mad4b-scp-company-master-admin.php", "class-mad4b-scp-company-master-admin-disabled.php"),
    SOURCE.replace("MAD4B_SCP_Readiness_PreActivation::boot();", "MAD4B_SCP_Readiness_PreActivation::disabled();"),
):
    try:
        verify(drift)
    except (AssertionError, ValueError):
        continue
    raise AssertionError("mutated source escaped fail-closed bootstrap review")
print("MAIN_BOOTSTRAP_STAGING_BOUNDARY: PASS (14 company + 3 readiness + 2 sender modules; negative controls)")
