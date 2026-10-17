#!/usr/bin/env python3
"""Pre-package change-impact and regression gate for MAD4B.

Non-authorizing: never reads credentials, invokes a live site, installs a ZIP,
or interprets CI success as owner approval. Conservative on unknown source.
"""
from __future__ import annotations

import argparse
import fnmatch
import hashlib
import json
import re
import subprocess
import sys
from collections import defaultdict, deque
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "wp-content/plugins/mad4b-site-control-plane/"
TEST = PLUGIN + "tests/"

# Dependencies are *capability boundaries*, not vendor versions. Tests are
# existing hermetic probes; live evidence is explicitly kept out of CI.
DOMAINS = {
    "core": {
        "paths": [PLUGIN + "includes/*.php", PLUGIN + "mad4b-site-control-plane.php",
                  PLUGIN + "config/*", "tools/mad4b_predictive_dependency_gate.py"],
        "next": ["mcp_transport", "production_guard"],
        "test": "site-info-resilience-runtime.php",
        "live": ["two_site_bootstrap_and_identity", "php_8_5_live_compatibility", "error_envelopes"],
        "risk": "Any changed plugin class may impact bootstrap and request lifecycle",
    },
    "mcp_transport": {
        "paths": [PLUGIN + "includes/*read-consistency*", PLUGIN + "includes/*connector-resilience*",
                  PLUGIN + "includes/*chatgpt-tool-projection*", PLUGIN + "client/*",
                  PLUGIN + "includes/*abilities*", PLUGIN + "includes/*servers*"],
        "next": ["mcp_auth", "mcp_observability"],
        "test": "connector-resilience-contract.py",
        "live": ["two_site_sequential_read", "mcp_host_latency_correlation", "no_opaque_tool_errors"],
        "risk": "Transport failure, timeout, stale schema, or MCP host latency",
    },
    "mcp_auth": {
        "paths": [PLUGIN + "includes/*governed-ability*", PLUGIN + "includes/*enrollment*",
                  PLUGIN + "includes/*authorization*", PLUGIN + "includes/*execution-fence*"],
        "next": ["owner_stepup", "production_guard"],
        "test": "dynamic-first-party-enrollment-runtime.php",
        "live": ["callback_provenance_live", "owner_only_stepup_denial"],
        "risk": "Callback wrappers, OAuth scopes, and first-party provenance",
    },
    "mcp_observability": {
        "paths": [PLUGIN + "includes/*error-contract*", PLUGIN + "includes/*read-consistency*"],
        "next": [],
        "test": "connector-resilience-contract.py",
        "live": ["redacted_correlation_id", "transport_stage_classification"],
        "risk": "Opaque errors or unsafe retry semantics",
    },
    "owner_stepup": {
        "paths": [PLUGIN + "includes/*owner-intent*", PLUGIN + "includes/*full-staging-authority*",
                  PLUGIN + "includes/*authority-convergence*"],
        "next": ["write_convergence", "production_guard"],
        "test": "staging-write-owner-intent-runtime.php",
        "live": ["fresh_site_specific_owner_receipt", "expired_receipt_rejected"],
        "risk": "Stale review, wrong owner, missing exact-head intent",
    },
    "write_convergence": {
        "paths": [PLUGIN + "includes/*staging-write-authority*",
                  PLUGIN + "includes/*full-staging-authority*"],
        "next": ["db_reservation", "package_identity", "provider_closure"],
        "test": "write-only-owner-handoff-runtime.php",
        "live": ["candidate_binding_current", "governed_write_readback"],
        "risk": "Head drift, stale candidate binding, unrelated authority promotion",
    },
    "db_reservation": {
        "paths": [PLUGIN + "includes/*operation-journal*", PLUGIN + "includes/*backup*",
                  PLUGIN + "includes/*recovery*"],
        "next": ["backup_restore"],
        "test": "staging-operation-journal-runtime.php",
        "live": ["database_mutex_concurrency", "unknown_outcome_no_replay"],
        "risk": "Duplicate reservations, deadlocks, interrupted recovery",
    },
    "backup_restore": {
        "paths": [PLUGIN + "includes/*backup*", PLUGIN + "includes/*recovery*"],
        "next": ["production_guard"],
        "test": "native-wpcron-acceptance-runtime.php",
        "live": ["backup_restorability", "rollback_identity", "no_blind_replay"],
        "risk": "Loss of rollback, stale job locks or replay",
    },
    "provider_closure": {
        "paths": [PLUGIN + "includes/*provider*", PLUGIN + "includes/*elementor*",
                  PLUGIN + "includes/*jetsmartfilter*"],
        "next": ["mcp_transport", "owner_stepup"],
        "test": "provider-closure-matrix-contract.py",
        "live": ["elementor_one_ability_etg", "provider_canary_rollback", "provider_shadow_gate"],
        "risk": "Provider behavioral drift or heavy ETG provider reads",
    },
    "skills": {
        "paths": [PLUGIN + "includes/*skill*", PLUGIN + "skills/*",
                  PLUGIN + "config/*skill*"],
        "next": ["provider_closure", "package_identity"],
        "test": "skill-runtime-certification-contract.py",
        "live": ["skills_certification_both_sites", "seed_pack_identity"],
        "risk": "Skills seed drift, stale certification and provider reconcile",
    },
    "adapter_runtime": {
        "paths": ["wp-content/plugins/mcp-adapter.zip", PLUGIN + "includes/*mcp-adapter*",
                  PLUGIN + "config/*provider-profiles*"],
        "next": ["mcp_transport", "package_identity"],
        "test": "mcp-adapter-compatibility-contract.py",
        "live": ["installed_adapter_0_7_0", "no_adapter_downgrade"],
        "risk": "Bundled and active MCP Adapter version mismatch",
    },
    "package_identity": {
        "paths": [PLUGIN + "includes/*package*", PLUGIN + "includes/*self-update*",
                  PLUGIN + "config/*release*", ".github/workflows/mad4b-control-plane-package.yml",
                  ".github/workflows/mad4b-predictive-dependency-parity.yml",
                  ".github/workflows/feature-007-pre-staging-hybrid-audit.yml"],
        "next": ["production_guard"],
        "test": "release-candidate-identity-contract.py",
        "live": ["exact_zip_sha256", "site_build_fingerprint", "staging_rollback"],
        "risk": "Package provenance, source/manifest mismatch and unsafe deployment",
    },
    "production_guard": {
        "paths": [PLUGIN + "includes/*production*", PLUGIN + "includes/*breakglass*"],
        "next": [],
        "test": "staging-write-authority-contract.py",
        "live": ["production_mutation_denied", "developer_breakglass_not_granted"],
        "risk": "Accidental Production mutation, Developer or Breakglass widening",
    },
    "frontend_acceptance": {
        "paths": [PLUGIN + "assets/*", PLUGIN + "includes/*frontend*",
                  PLUGIN + "includes/*native-hybrid*"],
        "next": ["provider_closure"],
        "test": "native-functional-freshness-runtime.php",
        "live": ["live_ajax_parity", "browser_acceptance", "frontend_performance_baseline"],
        "risk": "Frontend drift, AJAX parity, performance and browser evidence",
    },
}
LIMIT_REFERENCED_FILES = 500
MAX_BYTES_PER_PHP = 400_000
CLASS_RE = re.compile(r"\b(?:abstract\s+|final\s+)?class\s+(MAD4B_SCP_[A-Za-z0-9_]+)\b")


def changed_files(repo: Path, base: str, head: str, full_scan: bool) -> list[str]:
    if full_scan:
        return sorted(set([PLUGIN + "includes/class-mad4b-scp-full-staging-authority.php",
                           PLUGIN + "includes/class-mad4b-scp-provider-closure-matrix.php",
                           PLUGIN + "includes/class-mad4b-scp-read-consistency.php",
                           PLUGIN + "includes/class-mad4b-scp-staging-operation-journal.php",
                           PLUGIN + "includes/class-mad4b-scp-skills.php",
                           PLUGIN + "config/runtime-release-policy.json"]))
    if not re.fullmatch(r"[a-fA-F0-9]{40}", base) or not re.fullmatch(r"[a-fA-F0-9]{40}", head):
        raise ValueError("Exact 40-character base/head commit SHAs required")
    cmd = ["git", "-C", str(repo), "diff", "--name-status", "--find-renames",
           "--diff-filter=ACDMRT", base, head]
    r = subprocess.run(cmd, text=True, capture_output=True, timeout=35, check=False)
    if r.returncode:
        raise ValueError("Git diff failed; never infer no changes: " + r.stderr[-400:])
    result = set()
    for line in r.stdout.splitlines():
        cells = line.split("\t")
        if len(cells) < 2:
            raise ValueError("Unrecognized Git change-status record")
        status = cells[0]
        if status.startswith(("R", "C")):
            if len(cells) != 3:
                raise ValueError("Missing old/new path on rename or copy")
            result.update(cells[1:3])  # old class identity and new consumers
        else:
            result.add(cells[1])
    return sorted(x.replace("\\", "/") for x in result if x.strip())


def derived_class_dependents(repo: Path, changed: list[str], base: str = "") -> tuple[list[str], list[str]]:
    # Dynamic reverse-edge inference: newly modified classes -> PHP consumers.
    classes = set()
    for p in changed:
        if not p.startswith(PLUGIN + "includes/") or not p.endswith(".php"):
            continue
        fp = repo / p
        if fp.is_file() and fp.stat().st_size <= MAX_BYTES_PER_PHP:
            classes.update(CLASS_RE.findall(fp.read_text(encoding="utf-8", errors="replace")))
        # Historical class names are necessary for renamed/deleted classes.
        # Use exact base blobs; absence/failure cannot silently imply safety.
        if re.fullmatch(r"[a-fA-F0-9]{40}", base):
            old = subprocess.run(["git", "-C", str(repo), "show", base + ":" + p],
                                 capture_output=True, timeout=10, check=False)
            if old.returncode == 0:
                if len(old.stdout) > MAX_BYTES_PER_PHP:
                    raise ValueError("Historical PHP blob exceeds bounded scan; review required")
                classes.update(CLASS_RE.findall(old.stdout.decode("utf-8", errors="replace")))
    if not classes:
        return [], []
    roots = (repo / PLUGIN / "includes", repo / PLUGIN / "tests")
    dependents = set()
    for directory in roots:
        if not directory.exists():
            continue
        for fp in sorted(directory.glob("*.php")):
            if fp.stat().st_size > MAX_BYTES_PER_PHP:
                continue
            body = fp.read_text(encoding="utf-8", errors="replace")
            referenced = set(re.findall(r"\bMAD4B_SCP_[A-Za-z0-9_]+\b", body))
            if not classes.isdisjoint(referenced):
                rel = fp.relative_to(repo).as_posix()
                if rel not in changed:
                    dependents.add(rel)
            if len(dependents) > LIMIT_REFERENCED_FILES:
                raise ValueError("Unbounded reverse dependency fanout: fail closed")
    return sorted(classes), sorted(dependents)


def domains_for(paths: list[str]) -> list[str]:
    matched = set()
    for item in paths:
        for domain, spec in DOMAINS.items():
            if any(fnmatch.fnmatchcase(item, pattern) for pattern in spec["paths"]):
                matched.add(domain)
        if item.startswith(PLUGIN + "tests/") and item.endswith((".php", ".py", ".mjs")):
            matched.add("core")
        if item.startswith(PLUGIN + "includes/") and item.endswith(".php"):
            matched.add("core")
    return sorted(matched)


def closure(initial: list[str]) -> tuple[list[str], list[dict]]:
    seen = set(initial)
    queue = deque(sorted(initial))
    edges = []
    while queue:
        source = queue.popleft()
        for target in DOMAINS[source]["next"]:
            edges.append({"source": source, "target": target})
            if target not in seen:
                seen.add(target)
                queue.append(target)
    return sorted(seen), edges


def build_plan(repo: Path, changes: list[str], base: str = "") -> dict:
    names, dependents = derived_class_dependents(repo, changes, base)
    seed = domains_for(changes + dependents)
    if not seed and any(p.startswith(PLUGIN) for p in changes):
        seed = ["core"]
    affected, edges = closure(seed)
    tests = sorted({DOMAINS[d]["test"] for d in affected})
    missing = [name for name in tests if not (repo / TEST / name).is_file()]
    live = sorted({name for d in affected for name in DOMAINS[d]["live"]})
    return {
        "contract": "mad4b.predictive-dependency-gate.v1",
        "read_only": True, "authorizing": False, "mutation_performed": False,
        "changed_files": changes,
        "changed_classes": names,
        "reverse_dependency_files": dependents,
        "direct_domains": seed,
        "transitive_domains": affected,
        "transitive_edges": edges,
        "predicted_failure_modes": [{"domain": d, "risk": DOMAINS[d]["risk"]} for d in affected],
        "mandatory_regressions": tests,
        "missing_regressions": missing,
        "required_live_evidence": live,
        "ci_regression_coverage_ready": not missing,
        "live_evidence_attached": False,
        "staging_write_authorized": False,
        "production_mutation_allowed": False,
        "release_ready": False,
        "release_blockers": ["fresh_exact_site_live_evidence_required"] if affected else [],
    }


def test_plan(repo: Path, plan: dict, timeout: int = 75) -> list[dict]:
    out = []
    for name in plan["mandatory_regressions"]:
        path = repo / TEST / name
        command = [sys.executable, str(path)] if name.endswith(".py") else ["php", str(path)]
        try:
            r = subprocess.run(command, cwd=repo, capture_output=True, text=True, timeout=timeout)
            out.append({"name": name, "passed": r.returncode == 0,
                        "error_tail": "" if r.returncode == 0 else (r.stderr + r.stdout)[-900:]})
        except (OSError, subprocess.TimeoutExpired) as exc:
            out.append({"name": name, "passed": False, "error_tail": type(exc).__name__})
    return out


def self_test() -> None:
    # Structural contracts, graph cycles and denial cases.
    assert set(DOMAINS)
    for name, policy in DOMAINS.items():
        assert policy["test"].endswith((".py", ".php")) and policy["live"]
        assert all(d in DOMAINS for d in policy["next"]), name
    c, edges = closure(["mcp_transport"])
    assert {"mcp_auth", "owner_stepup", "write_convergence", "db_reservation",
            "package_identity", "production_guard"}.issubset(set(c))
    assert len(edges) < 120
    d, _ = closure(["provider_closure"])
    assert "owner_stepup" in d and "production_guard" in d
    assert "mcp_transport" in domains_for([PLUGIN + "includes/class-mad4b-scp-read-consistency.php"])
    assert "owner_stepup" in domains_for([PLUGIN + "includes/class-mad4b-scp-staging-write-owner-intent.php"])
    assert "package_identity" in domains_for([".github/workflows/mad4b-control-plane-package.yml"])
    assert "core" in domains_for(["tools/mad4b_predictive_dependency_gate.py"])
    assert "core" in domains_for([PLUGIN + "config/new-provider-contract.json"])
    from tempfile import TemporaryDirectory
    with TemporaryDirectory() as temporary:
        temp = Path(temporary)
        source = temp / PLUGIN / "includes/class-mad4b-scp-read-consistency.php"
        source.parent.mkdir(parents=True)
        source.write_text("<?php class MAD4B_SCP_Read_Consistency {}", encoding="utf-8")
        missing = build_plan(temp, [source.relative_to(temp).as_posix()])
        assert missing["ci_regression_coverage_ready"] is False
        assert missing["missing_regressions"] and missing["release_ready"] is False
        assert "mcp_host_latency_correlation" in missing["required_live_evidence"]
        assert "production_mutation_denied" in missing["required_live_evidence"]
    try:
        changed_files(ROOT, "not-a-sha", "also-not", False)
        raise AssertionError("invalid source commit should be rejected")
    except ValueError:
        pass
    print("PREDICTIVE_DEPENDENCY_GATE_SELFTEST_PASS")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="")
    ap.add_argument("--head", default="")
    ap.add_argument("--full-scan", action="store_true")
    ap.add_argument("--self-test", action="store_true")
    ap.add_argument("--execute", action="store_true")
    ap.add_argument("--enforce", action="store_true")
    ap.add_argument("--output", type=Path)
    args = ap.parse_args()
    if args.self_test:
        self_test()
        if not (args.full_scan or args.base):
            return 0
    changes = changed_files(ROOT, args.base, args.head, args.full_scan)
    plan = build_plan(ROOT, changes, args.base)
    plan["base_sha"] = args.base
    plan["head_sha"] = args.head
    if args.execute and not plan["missing_regressions"]:
        plan["regression_results"] = test_plan(ROOT, plan)
    failed = [x["name"] for x in plan.get("regression_results", []) if not x["passed"]]
    plan["failed_regressions"] = failed
    plan["ci_regression_coverage_ready"] = not plan["missing_regressions"] and not failed
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(plan, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"contract": plan["contract"], "head": args.head,
                      "direct": plan["direct_domains"], "impacted": plan["transitive_domains"],
                      "tests": len(plan["mandatory_regressions"]),
                      "missing": plan["missing_regressions"], "failed": failed,
                      "live_requirements": len(plan["required_live_evidence"]),
                      "release_ready": False}, sort_keys=True))
    if args.enforce and not plan["ci_regression_coverage_ready"]:
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
