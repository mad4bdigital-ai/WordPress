#!/usr/bin/env python3
"""CI-only first-party source reviews. Read twenty-four original PHP sources and run
their already-established behavior suites; never create a grant, issue a ticket,
call WordPress live, or authorize Production. Exact blob pin prevents silent
reuse of stale tests after any source change.
"""
from pathlib import Path
import hashlib
import json
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "wp-content/plugins/mad4b-site-control-plane/"
CASES = (
    ('includes/adapters/class-mad4b-scp-context-adapter.php',
     '565b7c19c4cdc34b8699adc35b200dc6942a0672',
     'wp-content/plugins/mad4b-site-control-plane/tests/context-write-mount-runtime.php',
     'mad4b.site-control-plane.context-write-mount.runtime.v6: PASS'),
    ('includes/class-mad4b-scp-developer-runtime.php',
     'd5c7ac20176958a7567d0d0689da0c286fba622d',
     'tests/remote-execution/operator-grant-rollout-smoke.php',
     'PASS Developer tool inventory operator rollout='),
    ('includes/class-mad4b-scp-full-staging-authority.php',
     'a12b57782106d059c5071a6a25e841da650f696d',
     'wp-content/plugins/mad4b-site-control-plane/tests/write-only-owner-handoff-runtime.php',
     'WRITE_ONLY_OWNER_HANDOFF_RUNTIME_PASS'),
    ('includes/class-mad4b-scp-governance-abilities.php',
     '1ac482b9d70aaefbe21d5486e43904cbd534fa60',
     'wp-content/plugins/mad4b-site-control-plane/tests/provider-mcp-write-compiler-contract.php',
     'MAD4B per-ability MCP write compiler and approval runtime contract passed.'),
    ('includes/class-mad4b-scp-live-truth.php',
     '0b9725cb5e55e910475e763ea190bb80eb5f3055',
     'wp-content/plugins/mad4b-site-control-plane/tests/wp-native-self-approval-runtime.php',
     'PASS: self-approval, single execution claim, local evidence, terminal audit'),
    ('includes/class-mad4b-scp-operational-remediation.php',
     '4277e0a5af4e26818858b3e6a8c0bb14b221774e',
     'wp-content/plugins/mad4b-site-control-plane/tests/operational-remediation-control-runtime.php',
     'OPERATIONAL_REMEDIATION_RUNTIME: PASS 35'),
    ('includes/class-mad4b-scp-reconnect-hardening.php',
     '72b51cf7a2d6246026fa0e835fd699989457d930',
     'wp-content/plugins/mad4b-site-control-plane/tests/reconnect-hardening-runtime.php',
     'mad4b.reconnect-hardening.runtime.v1: PASS'),
    ('includes/class-mad4b-scp-rest-compatibility.php',
     'eb0d11285d0fcf2d2278d98b36c36c0fc59f1d69',
     'wp-content/plugins/mad4b-site-control-plane/tests/provider-diagnostic-zero-touch-runtime.php',
     'mad4b.provider-diagnostic-zero-touch.runtime.v1: PASS'),
    ('includes/class-mad4b-scp-unified-agent-connection.php',
     '071b9bcb7f306c42477fc1aa04a324d318facd24',
     'wp-content/plugins/mad4b-site-control-plane/tests/unified-agent-connection-runtime.php',
     'UNIFIED_CONNECTION_RUNTIME: PASS'),
    ('includes/class-mad4b-scp-developer-authority.php',
     'e8234971d831c79d176b966f59de1d4cf8aa0670',
     'tests/developer-authority-access-original-hermetic.php',
     'PASS: DEVELOPER_AUTHORITY_ACCESS_ORIGINAL 25'),
    ('includes/class-mad4b-scp-mcp-request-scope.php',
     'f6c1444a11872a951b61984da1c0ee4c0395c7b1',
     'tests/mcp-request-scope-original-hermetic.php',
     'PASS: MCP_REQUEST_SCOPE_ORIGINAL 19'),
    ('includes/class-mad4b-scp-reversible-adapter-mutations.php',
     '4e9914592d8ccf224fccbb35ede4d96b0fb96d14',
     'wp-content/plugins/mad4b-site-control-plane/tests/undo-intent-fault-injection.php',
     'PASS undo intent original CAS'),
    ('config/remote-operation-catalog.php',
     '0e1bd3f67f5eac3bbb369eff5d45782fc882f9ae',
     'tests/remote-operation-catalog-original-hermetic.php',
     'PASS: REMOTE_OPERATION_CATALOG_ORIGINAL 129'),
    ('includes/class-mad4b-scp-artifacts.php',
     '7dcbcff762053e27ab858bd2554c277fb6f39d44',
     'tests/artifacts-original-hermetic.php',
     'PASS: ARTIFACTS_ORIGINAL 18'),
    ('includes/class-mad4b-scp-plugin.php',
     '35f46789233a39d303b719e510f71689d6643e82',
     'tests/plugin-bootstrap-original-hermetic.php',
     'PASS: PLUGIN_BOOTSTRAP_ORIGINAL 16'),
    ('includes/class-mad4b-scp-research-intelligence.php',
     '68725816145f91d3d21399fd5f1770821be142c7',
     'tests/research-intelligence-original-hermetic.php',
     'PASS: RESEARCH_INTELLIGENCE_ORIGINAL 18'),
    ('includes/class-mad4b-scp-skill-provider-discovery.php',
     '42bc4e68d42c93b03cccfb9b669242db246ad2ab',
     'tests/skill-provider-source-original-hermetic.php',
     'PASS: SKILL_PROVIDER_SOURCE_ORIGINAL 8'),
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
# Preserve the immutable original P0 SHA pin. Only this exact successor,
# backed by a separate non-authorizing source review and the original runtime
# regression, may supersede the previous Addon Registry source bytes.
REVIEWED_SUCCESSORS = {
    "includes/class-mad4b-scp-addon-registry.php": (
        "69e220a8c11a494da3216a1f98ce2cff770142ce",
        "4db830176dc8da77c29e954a0783b7e4a0bab2d2",
    ),
    "includes/class-mad4b-scp-reconnect-hardening.php": (
        "72b51cf7a2d6246026fa0e835fd699989457d930",
        "388408d37737ee2bacb2b97614be3fa375b334b8",
    ),
    "includes/class-mad4b-scp-artifacts.php": (
        "7dcbcff762053e27ab858bd2554c277fb6f39d44",
        "60750fb4a0cf8180f012254acd3fc2ccc43bba78",
    ),
}
review_ledger = json.loads((ROOT / "governance/production-readiness/capability-coverage.json").read_text(encoding="utf-8"))

assert len(CASES) == 24
for source, git_blob, fixture, marker in CASES:
    source_path = ROOT / PLUGIN / source
    fixture_path = ROOT / fixture
    assert source_path.is_file() and fixture_path.is_file(), (source, "missing")
    actual_blob = subprocess.check_output(
        ["git", "hash-object", str(source_path)], cwd=ROOT, text=True
    ).strip()
    if actual_blob != git_blob:
        pair = REVIEWED_SUCCESSORS.get(source)
        assert pair == (git_blob, actual_blob), (source, "unknown successor; exact original SHA remains protected")
        full_path = PLUGIN + source
        rows = [r for r in review_ledger["reviewed_changes"]
                if r.get("path") == full_path and r.get("source_blob_sha") == actual_blob
                and r.get("status") == "reviewed_for_ci_only"
                and r.get("production_authorized") is False
                and r.get("live_evidence_required") is True
                and fixture in r.get("regression_tests", [])]
        assert len(rows) == 1, (source, "independent exact Git blob review missing")
    fixture_text = fixture_path.read_text(encoding="utf-8")
    assert Path(source).name in fixture_text, (
        source, "original fixture no longer loads this first-party source")
    assert ("PASS" in fixture_text or "passed." in fixture_text) and (
        "check" in fixture_text or "assert" in fixture_text or "insist(" in fixture_text or (("FAIL:" in fixture_text or "FAIL " in fixture_text) and "exit(" in fixture_text)
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
print("PASS: ORIGINAL_P0_SOURCE_REVIEW_MATRIX 24 exact-source source-backed cases")
