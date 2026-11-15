#!/usr/bin/env python3
"""CI-only first-party source reviews. Read seven original PHP sources and run
their already-established behavior suites; never create a grant, issue a ticket,
call WordPress live, or authorize Production. Exact blob pin prevents silent
reuse of stale tests after any source change.
"""
from pathlib import Path
import hashlib
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "wp-content/plugins/mad4b-site-control-plane/"
CASES = (
    ("includes/class-mad4b-scp-adaptive-interaction-broker.php",
     "531cbaa5a3632213c6daa4c106ca921095d2266a",
     PLUGIN + "tests/adaptive-interaction-broker/contract-test.php",
     "PASS: native adaptive interaction original checkpoint and readback source assertions"),
    ("includes/class-mad4b-scp-addon-registry.php",
     "69e220a8c11a494da3216a1f98ce2cff770142ce",
     PLUGIN + "tests/wordpress-addon-execution-guard-contract.php",
     "PASS: mad4b.wordpress-addon-execution-binding.v1 original exact source assertions"),
    ("includes/class-mad4b-scp-agent-grant-lifecycle.php",
     "6b8accd1d767a3c5e3eb26e9062cf389d35731b7",
     "tests/agent-grant-plan-original-hermetic.php",
     "PASS: AGENT_GRANT_PLAN_ORIGINAL 82"),
    ("includes/class-mad4b-scp-cli.php",
     "5fbf1f17377975d71e59e51b7b81cd036fb023ae",
     "tests/cli-readiness-original-hermetic.php",
     "PASS: CLI_READINESS_ORIGINAL 43"),
    ("includes/class-mad4b-scp-external-media-ingest.php",
     "e5be10462db5cd269214a5478c406a4cbf7bf34a",
     "tests/external-media-ingest-original-hermetic.php",
     "PASS: EXTERNAL_MEDIA_INGEST_ORIGINAL 51"),
    ("includes/class-mad4b-scp-runtime-obstacle-resolution.php",
     "241a8cad8b0252a16d1a45e9282ccbd683128243",
     PLUGIN + "tests/runtime-obstacle-resolution-runtime.php",
     "PASS: selected folder scope, exact identity, Drive pending"),
    ("includes/class-mad4b-scp-staging-write-planning-guard.php",
     "89f531451a78bcc26d103ca018761e8a6c962ef6",
     "tests/staging-write-planning-guard-original-hermetic.php",
     "PASS: STAGING_WRITE_PLANNING_GUARD_ORIGINAL 64"),
)
assert len(CASES) == 7
for source, git_blob, fixture, marker in CASES:
    source_path = ROOT / PLUGIN / source
    fixture_path = ROOT / fixture
    assert source_path.is_file() and fixture_path.is_file(), (source, "missing")
    assert git_blob == subprocess.check_output(
        ["git", "hash-object", str(source_path)], cwd=ROOT, text=True
    ).strip(), (source, "source SHA changed; new review required")
    fixture_text = fixture_path.read_text(encoding="utf-8")
    assert Path(source).name in fixture_text, (
        source, "original fixture no longer loads this first-party source")
    assert "PASS" in fixture_text and (
        "check" in fixture_text or "assert" in fixture_text or "insist(" in fixture_text
    ), (fixture, "positive result without assertions")
    response = subprocess.run(
        ["php", str(fixture_path)], cwd=ROOT, capture_output=True,
        text=True, timeout=75, check=False
    )
    output = response.stdout + "\n" + response.stderr
    assert response.returncode == 0 and marker in output, (
        source, fixture, response.returncode, output[-800:])
    assert not re.search(r"(?m)^(?:FAIL(?::|\s)|Fatal error:|Parse error:)", output), (
        source, "fatal or negative test evidence")
    print("PASS: ORIGINAL_P0_SOURCE_EVIDENCE", source, git_blob[:12])
print("PASS: ORIGINAL_P0_SOURCE_REVIEW_MATRIX 7 exact-source source-backed cases")
