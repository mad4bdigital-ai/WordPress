#!/usr/bin/env python3
"""Execute real domain fixtures; mint PASS gates only on a clean CI head after WP proof."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess

PLUGIN = Path(__file__).resolve().parents[1]
REPO = PLUGIN.parents[2]
SPEC = REPO / "specs/007-content-intelligence-workflow-platform"
MATRIX = SPEC / "adaptive-search-runtime-acceptance.json"


def command(argv: list[str]) -> str:
    result = subprocess.run(argv, cwd=REPO, text=True, capture_output=True)
    if result.returncode:
        raise SystemExit(f"SEARCH_CHECK_FAILED:{argv[-1]}\n{result.stdout}\n{result.stderr}")
    return result.stdout


def source_digest() -> str:
    files = sorted(set([
        *PLUGIN.glob("includes/search/**/*.php"),
        *PLUGIN.glob("includes/class-mad4b-scp-search-*.php"),
        *PLUGIN.glob("includes/class-mad4b-scp-adaptive-search-*.php"),
        PLUGIN / "includes/class-mad4b-scp-provider-account-budget-authority.php",
        PLUGIN / "includes/class-mad4b-scp-servers.php",
        PLUGIN / "includes/class-mad4b-scp-remote-work-queue.php",
        PLUGIN / "config/search-runtime-policy.json",
        *PLUGIN.glob("tests/adaptive-search-*"),
        PLUGIN / "tests/fixtures/search-runtime-fixtures.php",
        MATRIX,
    ]))
    manifest = {str(p.relative_to(REPO)): hashlib.sha256(p.read_bytes()).hexdigest() for p in files if p.is_file()}
    return hashlib.sha256(json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def binding() -> dict:
    head = command(["git", "rev-parse", "HEAD"]).strip()
    expected = os.getenv("ASI_EXPECTED_HEAD", "")
    clean = not command(["git", "status", "--porcelain"]).strip()
    exact = bool(os.getenv("GITHUB_ACTIONS") == "true" and expected == head and clean)
    if expected and not exact:
        raise SystemExit("SEARCH_EXACT_HEAD_BINDING_FAILED")
    return {"head_sha": head, "source_sha256": source_digest(), "clean_checkout": clean,
            "exact_head_ci": exact, "workflow_run_id": os.getenv("GITHUB_RUN_ID", ""),
            "workflow_run_attempt": os.getenv("GITHUB_RUN_ATTEMPT", ""), "authorizing": False}


def execute(php: list[str]) -> dict:
    paths = [*PLUGIN.glob("includes/search/**/*.php"), PLUGIN / "includes/class-mad4b-scp-adaptive-search-intelligence.php",
             PLUGIN / "tests/fixtures/search-runtime-fixtures.php", *PLUGIN.glob("tests/adaptive-search-*.php")]
    for path in sorted(set(paths)):
        command(php + ["-l", str(path)])
    suites = []
    for name in ("adaptive-search-runtime.php", "adaptive-search-provider-conformance.php", "adaptive-search-provider-enrollment-runtime.php"):
        raw = command(php + [str(PLUGIN / "tests" / name)])
        report = json.loads(raw)
        if report.get("status") != "PASS" or not report.get("fixtures"):
            raise SystemExit("SEARCH_FIXTURE_FAILURE:" + name)
        for row in report["fixtures"]:
            if row.get("status") != "PASS" or row.get("assertions", 0) <= 0:
                raise SystemExit("SEARCH_EMPTY_OR_FAILED_FIXTURE:" + row.get("fixture", ""))
        suites.append(report)
    parity = {}
    for name in ("adaptive-search-p0-runtime.php", "adaptive-search-cross-fault-runtime.php", "adaptive-search-context-runtime.php"):
        raw = command(php + [str(PLUGIN / "tests" / name)])
        if "PASS" not in raw:
            raise SystemExit("SEARCH_FOUNDATION_NOT_VERIFIED:" + name)
        parity[name] = hashlib.sha256(raw.encode()).hexdigest()
    for name in ("adaptive-search-p0-acceptance-contract.py", "remote-work-queue-contract.py", "egress-policy-contract.py"):
        raw = command(["python3", str(PLUGIN / "tests" / name)])
        parity[name] = hashlib.sha256(raw.encode()).hexdigest()
    report = {"contract": "mad4b.adaptive-search-runtime-evidence.v1", "binding": binding(), "suites": suites,
              "parity_evidence_sha256": parity, "assertions": sum(s["assertions"] for s in suites),
              "fixture_count": sum(s["fixture_count"] for s in suites), "gates": [],
              "state": "RUNTIME_VERIFIED_DISPOSABLE_PENDING", "authorizing": False}
    thresholds = json.loads(MATRIX.read_text())["thresholds"]
    if report["assertions"] < thresholds["min_total_assertions"] or report["fixture_count"] < thresholds["min_total_fixtures"]:
        raise SystemExit("SEARCH_ASSERTION_THRESHOLD_NOT_MET")
    return report


def finalize(report: dict, wordpress: dict) -> dict:
    current = binding()
    if report["binding"] != current or not current["exact_head_ci"]:
        raise SystemExit("SEARCH_GATE_REQUIRES_EXACT_CLEAN_CI_HEAD")
    if wordpress.get("status") != "PASS" or wordpress.get("assertions", 0) < 80 or wordpress.get("cas_workers") != 8 or wordpress.get("cas_winners") != 1:
        raise SystemExit("SEARCH_DISPOSABLE_PROOF_REQUIRED")
    fixtures = {f["fixture"]: f for suite in report["suites"] for f in suite["fixtures"]}
    matrix = json.loads(MATRIX.read_text())
    required = set(json.loads((SPEC / "adaptive-search-intelligence.json").read_text())["required_gates"])
    if set(matrix["gates"]) != required:
        raise SystemExit("SEARCH_ACCEPTANCE_GATE_PARITY_FAILED")
    for gate, rules in matrix["gates"].items():
        if not rules.get("denial_cases") or not rules.get("thresholds"):
            raise SystemExit("SEARCH_GATE_WITHOUT_MEASURABLE_DENIALS:" + gate)
        for fixture in rules["fixtures"]:
            if fixture not in fixtures or fixtures[fixture]["status"] != "PASS":
                raise SystemExit("SEARCH_GATE_FIXTURE_MISSING:" + gate + ":" + fixture)
            minimum = rules["thresholds"]["minimum_assertions_per_fixture"][fixture]
            if fixtures[fixture]["assertions"] < minimum:
                raise SystemExit("SEARCH_GATE_ASSERTION_THRESHOLD:" + gate + ":" + fixture)
        report["gates"].append({"gate": gate, "status": "PASS", "fixtures": rules["fixtures"],
                                 "head_sha": current["head_sha"], "source_sha256": current["source_sha256"],
                                 "evidence_classes": ["exact_head_ci_hermetic_runtime", "disposable_wordpress_mysql"],
                                 "authorizing": False})
    report["disposable_wordpress"] = wordpress
    report["state"] = "REPOSITORY_RUNTIME_VALIDATED"
    report["live_provider_certification"] = "NOT_CLAIMED"
    report["production_authority"] = False
    return report


parser = argparse.ArgumentParser()
parser.add_argument("--output")
parser.add_argument("--finalize")
parser.add_argument("--wordpress")
args = parser.parse_args()
if args.finalize:
    if not args.wordpress:
        raise SystemExit("SEARCH_WORDPRESS_EVIDENCE_REQUIRED")
    target = Path(args.finalize)
    report = finalize(json.loads(target.read_text()), json.loads(Path(args.wordpress).read_text()))
else:
    if not args.output:
        raise SystemExit("SEARCH_OUTPUT_REQUIRED")
    target = Path(args.output)
    report = execute(shlex.split(os.getenv("ASI_PHP_COMMAND", "php")))
target.parent.mkdir(parents=True, exist_ok=True)
target.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")
print(json.dumps({"state": report["state"], "fixtures": report["fixture_count"], "assertions": report["assertions"],
                  "head_sha": report["binding"]["head_sha"], "exact_head_ci": report["binding"]["exact_head_ci"],
                  "gates_emitted": len(report["gates"])}))
