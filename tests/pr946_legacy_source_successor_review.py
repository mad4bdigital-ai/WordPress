#!/usr/bin/env python3
"""PR #946: exact CI-only semantic successors for seven frozen source probes.

This does not alter/waive historical probes, register a production-capable
runtime or approve any release. Each successor is run in a fresh PHP process.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "wp-content/plugins/mad4b-site-control-plane"
EVIDENCE = ROOT / "docs/source-reconciliation/pr946-legacy-source-regression-triage-20261119.json"
CASES = (
    ("tours-dev-agent-authority-source-only-runtime.php",
     "class-mad4b-scp-background-agent-admission.php",
     "tests/background-agent-original-authority-hermetic.php",
     "PASS: BACKGROUND_AGENT_ORIGINAL_AUTHORITY"),
    ("tours-dev-authority-review-source-only-runtime.php",
     "class-mad4b-scp-authority-auto-reconciliation.php",
     "tests/authority-auto-reconcile-host-hermetic.php",
     "PASS: AUTHORITY_AUTO_RECONCILE_HOST"),
    ("tours-dev-content-quality-dependencies-source-only-runtime.php",
     "class-mad4b-scp-readiness-workflow-options.php",
     "tests/readiness-workflow-options-original-hermetic.php",
     "PASS: READINESS_WORKFLOW_OPTIONS_ORIGINAL"),
    ("tours-dev-hub-lifecycle-source-only-runtime.php",
     "class-mad4b-scp-hub-jetengine-consumer-governance.php",
     "tests/hub-jetengine-consumer-fence-original-hermetic.php",
     "PASS: HUB_JETENGINE_CONSUMER_FENCE"),
    ("tours-dev-readiness-preactivation-source-only-runtime.php",
     "class-mad4b-scp-readiness-preactivation.php",
     "tests/readiness-draft-only-original-hermetic.php",
     "PASS: READINESS_DRAFT_ONLY"),
    ("tours-dev-scheduler-source-closure-runtime.php",
     "class-mad4b-scp-brand-experience-quality.php",
     "tests/brand-quality-readiness-coverage-hermetic.php",
     "BRAND_QUALITY_LEDGER_SOURCE_GUARD: PASS"),
    ("tours-dev-unactivated-source-parity-runtime.php",
     "class-mad4b-scp-chatbotx-provider-adapter.php",
     "tests/chatbotx-native-adapter-surface-hermetic.php",
     "PASS: CHATBOTX_NATIVE_ADAPTER_GATES"),
)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def certify_source(row: dict, payload: bytes) -> bool:
    # Exact-current SHA is a CI-only fingerprint, not historical SHA reapproval.
    return (
        row.get("observed_sha256") == sha(payload)
        and row.get("grant_or_runtime_authorization") is False
        and row.get("legacy_regression_result") == "FAIL"
        and len(payload) == row.get("source_bytes")
    )


# Host reconciliation validates genuine effective UID 0. GitHub's ordinary
# PHP CLI runs unprivileged, so run this ONE authority-positive fixture inside
# a non-privileged, networkless root-UID container. Do not weaken source EUID,
# owner or grant guards and do not elevate the other six PHP regressions.
ROOT_ONLY_FIXTURE = "tests/authority-auto-reconcile-host-hermetic.php"


def execution_command(fixture: str, docker_isolated: bool) -> list[str]:
    if docker_isolated or fixture == ROOT_ONLY_FIXTURE:
        return [
            "docker", "run", "--rm", "--network", "none",
            "--read-only", "--cap-drop", "ALL",
            "--security-opt", "no-new-privileges",
            "--user", "0:0",
            "--memory", "256m", "--pids-limit", "100",
            "--tmpfs", "/tmp:rw,size=24m",
            "-v", f"{ROOT}:/src:ro", "-w", "/src",
            "--entrypoint", "php", "php:8.5-cli", fixture,
        ]
    return ["php", str(ROOT / fixture)]


def self_test() -> None:
    reviewed = {case[2] for case in CASES}
    assert len(reviewed) == 7
    for name in reviewed:
        container = execution_command(name, False)
        if name == ROOT_ONLY_FIXTURE:
            assert container[:2] == ["docker", "run"]
            assert "--network" in container and "none" in container
            assert "--read-only" in container
            assert "--cap-drop" in container and "ALL" in container
            assert "--user" in container and "0:0" in container
            assert "no-new-privileges" in container
            assert f"{ROOT}:/src:ro" in container
        else:
            assert container == ["php", str(ROOT / name)]
        assert execution_command(name, True)[0] == "docker"
    print("PR946_LEGACY_RUNNER_ISOLATION: PASS root-only Host authority, 6 ordinary PHP fixtures")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--execute", action="store_true",
                        help="Run all seven actual PHP behavior fixtures.")
    parser.add_argument("--self-test", action="store_true",
                        help="Verify exact CI runner isolation, no execution.")
    parser.add_argument("--docker-isolated", action="store_true",
                        help="Use networkless, read-only Docker rather than host PHP.")
    opts = parser.parse_args()
    assert not opts.docker_isolated or opts.execute
    if opts.self_test:
        assert not opts.execute and not opts.docker_isolated
        self_test()
        return
    evidence = json.loads(EVIDENCE.read_text())
    assert evidence["contract"] == "mad4b.pr946.legacy-source-regression-triage.v1"
    assert evidence["legacy_regression_count"] == len(CASES) == 7
    assert evidence["historical_sha_allowlist_changed"] is False
    assert evidence["bootstrap_changed_to_satisfy_legacy_test"] is False
    assert evidence["production_authorized"] is False
    assert evidence["source_promotion_approved"] is False
    assert evidence["release_gate"] == "BLOCKED_PENDING_INDEPENDENT_SOURCE_REVIEW"
    assert evidence["self_fallback_runtime_unchanged"] is True
    assert len(evidence["cases"]) == len(CASES)
    paths = [r["legacy_test"] for r in evidence["cases"]]
    assert len(set(paths)) == len(CASES)
    bootstrap = (ROOT / PLUGIN / "mad4b-site-control-plane.php").read_text()
    # Match the *current* reviewed bootstrap contract, not the historical
    # blanket "never load" test. Runtime-denial fixtures are still mandatory.
    reviewed_load_counts = {
        "class-mad4b-scp-background-agent-admission.php": 1,
        "class-mad4b-scp-authority-auto-reconciliation.php": 1,
        "class-mad4b-scp-readiness-workflow-options.php": 0,
        "class-mad4b-scp-hub-jetengine-consumer-governance.php": 1,
        "class-mad4b-scp-readiness-preactivation.php": 1,
        "class-mad4b-scp-brand-experience-quality.php": 0,
        "class-mad4b-scp-chatbotx-provider-adapter.php": 1,
    }
    runs: list[dict] = []

    for name, module, fixture, marker in CASES:
        path = PLUGIN + "/tests/" + name
        matching = [r for r in evidence["cases"] if r["legacy_test"] == path]
        assert len(matching) == 1, path
        record = matching[0]
        source_path = PLUGIN + "/includes/" + (
            "adapters/" if module.startswith("class-mad4b-scp-chatbotx") else ""
        ) + module
        assert record["source_path"] == source_path
        origin = ROOT / path
        source = ROOT / source_path
        behavior = ROOT / fixture
        for f in (origin, source, behavior):
            assert f.is_file() and not f.is_symlink(), f
        old_content = origin.read_text()
        current_content = source.read_bytes()
        test_content = behavior.read_text()
        assert certify_source(record, current_content), source_path
        # Ensure an independent, live-source-linked behavior fixture.
        assert module in test_content, fixture
        assert "PASS" in marker
        assert bootstrap.count(module) == reviewed_load_counts[module], module
        if module == "class-mad4b-scp-hub-jetengine-consumer-governance.php":
            assert "MAD4B_SCP_Hub_JetEngine_Consumer_Governance::boot();" in bootstrap
        if module == "class-mad4b-scp-readiness-preactivation.php":
            assert "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-readiness-preactivation.php';" in bootstrap
        historical = record["historical_sha_pinned_by_legacy_test"]
        if historical:
            assert historical in old_content
            assert historical != record["observed_sha256"]
        else:
            # Existing ChatbotX bootstrap load is reviewed, never silently
            # removed merely to appease the historical "unactivated" test.
            assert module in bootstrap
            assert "require_once" in bootstrap.split(module)[0].splitlines()[-1]
        assert not certify_source(record, current_content + b"\nTAMPER")
        result = {"case": name, "source_digest_verified": True,
                  "historical_regression_preserved": True,
                  "release_authorized": False}
        if opts.execute:
            command = execution_command(fixture, opts.docker_isolated)
            outcome = subprocess.run(
                command, cwd=ROOT, capture_output=True, text=True,
                timeout=40, check=False,
            )
            combined = outcome.stdout + "\n" + outcome.stderr
            assert outcome.returncode == 0, fixture + " " + combined[-600:]
            assert marker in outcome.stdout, fixture + " " + combined[-600:]
            assert not re.search(r"(?mi)^(?:FAIL(?:[ :])|Fatal error:|Parse error:)", combined)
            result["positive_php_assertions"] = True
        runs.append(result)
        print("PASS", name, "source binding + " +
              ("PHP behavior" if opts.execute else "static acceptance"))
    assert len(runs) == 7 and all(not r["release_authorized"] for r in runs)
    print(
        "PR946_LEGACY_SUCCESSOR_REVIEW: PASS "
        + str(len(runs)) + "/7 non-authorizing source reviews; "
        + ("seven PHP behaviors verified" if opts.execute
           else "PHP behavior execution still required")
    )


if __name__ == "__main__":
    main()
