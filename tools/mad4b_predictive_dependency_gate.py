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
import time
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
                  ".github/workflows/mad4b-release-verdict.yml",
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
# Budgets constrain resources, never the number of discovered dependencies.
# A budget violation refuses the result rather than skipping a consumer.
MAX_REVERSE_SCAN_BYTES = 120_000_000
MAX_SINGLE_PHP_BYTES = 8_000_000
MAX_DEPENDENCY_PATH_BYTES = 12_000_000
MAX_ANALYSIS_SECONDS = 45.0
REPORT_CHUNK_SIZE = 250
CLASS_RE = re.compile(r"\b(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(MAD4B_SCP_[A-Za-z0-9_]+)\b")


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


def derived_class_dependents(repo: Path, changed: list[str], base: str = "") -> tuple[list[str], list[str], dict]:
    """Complete reverse discovery or failure. No hit-count/truncation fallback."""
    started = time.monotonic()
    deadline = started + MAX_ANALYSIS_SECONDS

    def ensure_budget() -> None:
        if time.monotonic() >= deadline:
            raise ValueError("Reverse dependency analysis time budget exceeded: fail closed")

    classes = set()
    for path in changed:
        ensure_budget()
        if not path.startswith(PLUGIN) or not path.endswith(".php"):
            continue
        fp = repo / path
        if fp.is_symlink():
            raise ValueError("Symlinked changed PHP source: fail closed")
        if fp.is_file():
            if fp.stat().st_size > MAX_SINGLE_PHP_BYTES:
                raise ValueError("Changed PHP source exceeds single-file resource budget: fail closed")
            classes.update(CLASS_RE.findall(fp.read_text(encoding="utf-8", errors="replace")))
        if re.fullmatch(r"[a-fA-F0-9]{40}", base):
            old = subprocess.run(["git", "-C", str(repo), "show", base + ":" + path],
                                 capture_output=True, timeout=10, check=False)
            ensure_budget()
            if old.returncode == 0:
                if len(old.stdout) > MAX_SINGLE_PHP_BYTES:
                    raise ValueError("Historical PHP source exceeds resource budget: fail closed")
                classes.update(CLASS_RE.findall(old.stdout.decode("utf-8", errors="replace")))
            else:
                # A newly added path is valid, but a missing/corrupt historical
                # object must not silently erase reverse dependencies.
                tracked = subprocess.run(
                    ["git", "-C", str(repo), "ls-tree", "-r", "--name-only", base, "--", path],
                    capture_output=True, text=True, timeout=10, check=False,
                )
                ensure_budget()
                if tracked.returncode != 0 or tracked.stdout.strip():
                    raise ValueError("Historical class source unavailable: fail closed")

    if not classes:
        return [], [], {
            "complete": True, "total": 0, "sha256": hashlib.sha256(b"").hexdigest(),
            "scanned_files": 0, "scanned_bytes": 0, "dependency_path_bytes": 0,
            "elapsed_ms": round((time.monotonic() - started) * 1000),
        }

    # Include bootstrap, nested adapters and any other shipped plugin PHP.
    # Restrict discovery to the Control Plane plugin: other repositories and
    # vendor code must be certified through their explicit provider contracts.
    root = repo / PLUGIN
    if not root.is_dir() or root.is_symlink():
        raise ValueError("Plugin source root unavailable or symlinked: fail closed")
    for entry in root.rglob("*"):
        ensure_budget()
        if entry.is_symlink():
            raise ValueError("Symlinked plugin source entry: fail closed")
    dependents = set()
    scanned_files = scanned_bytes = dependency_path_bytes = 0
    changed_set = set(changed)
    for fp in sorted(root.rglob("*.php")):
        ensure_budget()
        if fp.is_symlink():
            raise ValueError("Symlinked PHP consumer source: fail closed")
        before = fp.stat()
        if before.st_size > MAX_SINGLE_PHP_BYTES:
            raise ValueError("PHP consumer exceeds single-file resource budget: fail closed")
        if scanned_bytes + before.st_size > MAX_REVERSE_SCAN_BYTES:
            raise ValueError("Reverse dependency byte budget exceeded: fail closed")
        body = fp.read_text(encoding="utf-8", errors="replace")
        after = fp.stat()
        if (before.st_size, before.st_mtime_ns, before.st_ino) != (
            after.st_size, after.st_mtime_ns, after.st_ino
        ):
            raise ValueError("PHP consumer changed during analysis: fail closed")
        scanned_files += 1
        scanned_bytes += before.st_size
        referenced = set(re.findall(r"\bMAD4B_SCP_[A-Za-z0-9_]+\b", body))
        if classes.isdisjoint(referenced):
            continue
        rel = fp.relative_to(repo).as_posix()
        if rel not in changed_set and rel not in dependents:
            dependency_path_bytes += len(rel.encode("utf-8")) + 1
            if dependency_path_bytes > MAX_DEPENDENCY_PATH_BYTES:
                raise ValueError("Reverse dependency evidence memory budget exceeded: fail closed")
            dependents.add(rel)
    ensure_budget()
    complete = sorted(dependents)
    return sorted(classes), complete, {
        "complete": True,
        "total": len(complete),
        "sha256": hashlib.sha256("\n".join(complete).encode("utf-8")).hexdigest(),
        "scanned_files": scanned_files,
        "scanned_bytes": scanned_bytes,
        "dependency_path_bytes": dependency_path_bytes,
        "elapsed_ms": round((time.monotonic() - started) * 1000),
        "resource_limits": {
            "scan_bytes": MAX_REVERSE_SCAN_BYTES,
            "single_php_bytes": MAX_SINGLE_PHP_BYTES,
            "dependency_path_bytes": MAX_DEPENDENCY_PATH_BYTES,
            "analysis_seconds": MAX_ANALYSIS_SECONDS,
        },
    }


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
    names, dependents, reverse = derived_class_dependents(repo, changes, base)
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
        "reverse_dependency_evidence": reverse,
        "impact_scope": "complete_exact_dependencies",
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


def write_report(output: Path, plan: dict) -> None:
    """Emit complete deterministic evidence and SHA-verifiable paged copies."""
    paths = plan["reverse_dependency_files"]
    evidence = plan["reverse_dependency_evidence"]
    if paths != sorted(set(paths)):
        raise ValueError("Dependency evidence is not sorted/unique: fail closed")
    actual = hashlib.sha256("\n".join(paths).encode("utf-8")).hexdigest()
    if not evidence["complete"] or evidence["total"] != len(paths) or actual != evidence["sha256"]:
        raise ValueError("Incomplete reverse dependency evidence: fail closed")

    output.parent.mkdir(parents=True, exist_ok=True)
    chunks = []
    directory = output.stem + ".reverse-" + actual
    for start in range(0, len(paths), REPORT_CHUNK_SIZE):
        entries = paths[start:start + REPORT_CHUNK_SIZE]
        part = len(chunks) + 1
        filename = f"part-{part:05}.json"
        digest = hashlib.sha256("\n".join(entries).encode("utf-8")).hexdigest()
        chunks.append({"file": filename, "count": len(entries), "sha256": digest})
        file = output.parent / directory / filename
        file.parent.mkdir(parents=True, exist_ok=True)
        payload = {
            "contract": "mad4b.reverse-dependency-page.v1",
            "part": part, "total": evidence["total"], "full_sha256": actual,
            "sha256": digest, "entries": entries,
        }
        file.write_text(json.dumps(payload, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
                        encoding="utf-8")

    manifest = {
        "contract": "mad4b.reverse-dependency-pagination.v1",
        "directory": directory,
        "page_size": REPORT_CHUNK_SIZE,
        "page_count": len(chunks),
        "complete_sha256": actual,
        "parts": chunks,
    }
    manifest["sha256"] = hashlib.sha256(
        json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()
    plan["reverse_dependency_pagination"] = manifest
    # The primary JSON also carries *every* dependency, never just page one.
    output.write_text(json.dumps(plan, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
                      encoding="utf-8")


def verify_report(output: Path) -> dict:
    """Read-only complete-evidence verification; reject missing/tampered pages."""
    plan = json.loads(output.read_text(encoding="utf-8"))
    manifest = plan["reverse_dependency_pagination"]
    expected_manifest_sha = manifest["sha256"]
    manifest_body = {key: value for key, value in manifest.items() if key != "sha256"}
    manifest_sha = hashlib.sha256(
        json.dumps(manifest_body, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()
    if manifest_sha != expected_manifest_sha:
        raise ValueError("Reverse dependency manifest digest mismatch: fail closed")
    directory = manifest["directory"]
    full_sha = manifest["complete_sha256"]
    if (not re.fullmatch(r"[a-f0-9]{64}", full_sha)
            or directory != output.stem + ".reverse-" + full_sha):
        raise ValueError("Unsafe reverse dependency evidence directory: fail closed")
    if manifest["page_size"] != REPORT_CHUNK_SIZE or manifest["page_count"] != len(manifest["parts"]):
        raise ValueError("Reverse dependency pagination incomplete: fail closed")
    complete = []
    for i, part in enumerate(manifest["parts"], start=1):
        filename = f"part-{i:05}.json"
        if part["file"] != filename:
            raise ValueError("Unexpected reverse dependency page: fail closed")
        page = json.loads((output.parent / directory / filename).read_text(encoding="utf-8"))
        entries = page["entries"]
        digest = hashlib.sha256("\n".join(entries).encode("utf-8")).hexdigest()
        if (page["contract"] != "mad4b.reverse-dependency-page.v1"
                or page["part"] != i
                or page["sha256"] != part["sha256"]
                or digest != part["sha256"]
                or page["full_sha256"] != manifest["complete_sha256"]
                or page["total"] != plan["reverse_dependency_evidence"]["total"]
                or len(entries) != part["count"] or len(entries) > REPORT_CHUNK_SIZE):
            raise ValueError("Reverse dependency page integrity mismatch: fail closed")
        complete.extend(entries)
    digest = hashlib.sha256("\n".join(complete).encode("utf-8")).hexdigest()
    if (complete != plan["reverse_dependency_files"]
            or complete != sorted(set(complete))
            or digest != manifest["complete_sha256"]
            or digest != plan["reverse_dependency_evidence"]["sha256"]
            or not plan["reverse_dependency_evidence"]["complete"]
            or len(complete) != plan["reverse_dependency_evidence"]["total"]):
        raise ValueError("Reverse dependency full evidence incomplete: fail closed")
    return {"verified": True, "dependent_files": len(complete),
            "pages": len(manifest["parts"]), "sha256": digest}


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
    assert "package_identity" in domains_for([".github/workflows/mad4b-release-verdict.yml"])
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
    # Nested sources and modern PHP symbol types must resolve reverse edges.
    with TemporaryDirectory() as temporary:
        temp = Path(temporary)
        source = temp / PLUGIN / "bootstrap" / "symbols.php"
        source.parent.mkdir(parents=True)
        source.write_text(
            "<?php interface MAD4B_SCP_Interface {} "
            "trait MAD4B_SCP_Trait {} enum MAD4B_SCP_Result: string {case OK='ok';} "
            "final readonly class MAD4B_SCP_Example {}"
        )
        consumer = temp / PLUGIN / "includes" / "adapters" / "dependent.php"
        consumer.parent.mkdir(parents=True)
        consumer.write_text(
            "<?php MAD4B_SCP_Interface::class; MAD4B_SCP_Trait::class; "
            "MAD4B_SCP_Result::OK; MAD4B_SCP_Example::class;"
        )
        identities, paths, _ = derived_class_dependents(
            temp, [source.relative_to(temp).as_posix()]
        )
        assert len(identities) == 4 and paths == [consumer.relative_to(temp).as_posix()]
    # >500 consumers remain fully enumerated; no fixed count-based widening.
    with TemporaryDirectory() as temporary:
        temp = Path(temporary)
        source = temp / PLUGIN / "includes/class-mad4b-scp-large-shared.php"
        source.parent.mkdir(parents=True)
        source.write_text("<?php class MAD4B_SCP_Large_Shared {}")
        consumers = temp / PLUGIN / "tests"
        consumers.mkdir(parents=True)
        for i in range(1201):
            (consumers / f"fanout-{i:04}.php").write_text("<?php MAD4B_SCP_Large_Shared::probe();")
        nested = temp / PLUGIN / "includes" / "adapters" / "nested-consumer.php"
        nested.parent.mkdir(parents=True)
        nested.write_text("<?php MAD4B_SCP_Large_Shared::probe();")
        bootstrap = temp / PLUGIN / "bootstrap" / "consumer.php"
        bootstrap.parent.mkdir(parents=True)
        bootstrap.write_text("<?php MAD4B_SCP_Large_Shared::probe();")
        change = [source.relative_to(temp).as_posix()]
        plan = build_plan(temp, change)
        evidence = plan["reverse_dependency_evidence"]
        assert evidence["complete"] is True and evidence["total"] == 1203
        assert len(plan["reverse_dependency_files"]) == 1203
        assert plan["impact_scope"] == "complete_exact_dependencies"
        assert nested.relative_to(temp).as_posix() in plan["reverse_dependency_files"]
        assert bootstrap.relative_to(temp).as_posix() in plan["reverse_dependency_files"]
        assert plan["direct_domains"] == ["core"]
        assert set(plan["transitive_domains"]) != set(DOMAINS)
        assert plan["release_ready"] is False
        assert "high_fanout_full_suite_and_staging_certification_required" not in plan["release_blockers"]
        out = temp / "reports" / "impact.json"
        write_report(out, plan)
        verified = verify_report(out)
        assert verified["verified"] is True and verified["dependent_files"] == 1203
        saved = json.loads(out.read_text())
        pagination = saved["reverse_dependency_pagination"]
        assert pagination["page_count"] == 5 and pagination["page_size"] == REPORT_CHUNK_SIZE
        reconstructed = []
        for chunk in pagination["parts"]:
            page = json.loads((out.parent / pagination["directory"] / chunk["file"]).read_text())
            assert hashlib.sha256("\n".join(page["entries"]).encode("utf-8")).hexdigest() == chunk["sha256"]
            reconstructed.extend(page["entries"])
        assert reconstructed == saved["reverse_dependency_files"]
        assert hashlib.sha256("\n".join(reconstructed).encode("utf-8")).hexdigest() == pagination["complete_sha256"]
        tampered_page = out.parent / pagination["directory"] / pagination["parts"][0]["file"]
        original_page = tampered_page.read_text()
        try:
            changed = json.loads(original_page)
            changed["entries"].pop()
            tampered_page.write_text(json.dumps(changed))
            try:
                verify_report(out)
                raise AssertionError("Altered evidence page must be rejected")
            except ValueError as exc:
                assert "page integrity" in str(exc)
        finally:
            tampered_page.write_text(original_page)
        altered = dict(plan)
        altered["reverse_dependency_files"] = plan["reverse_dependency_files"][:-1]
        try:
            write_report(out, altered)
            raise AssertionError("Truncated reverse dependency evidence must be rejected")
        except ValueError as exc:
            assert "Incomplete reverse dependency evidence" in str(exc)
        # Bounds are on work, elapsed time, memory and file bytes, not hits.
        for name, value, reason in (
            ("MAX_REVERSE_SCAN_BYTES", 1, "byte budget"),
            ("MAX_SINGLE_PHP_BYTES", 1, "single-file"),
            ("MAX_DEPENDENCY_PATH_BYTES", 1, "memory budget"),
            ("MAX_ANALYSIS_SECONDS", 0.0, "time budget"),
        ):
            original = globals()[name]
            try:
                globals()[name] = value
                try:
                    build_plan(temp, change)
                    raise AssertionError(f"{name} must fail closed when exhausted")
                except ValueError as exc:
                    assert reason in str(exc), (name, str(exc))
            finally:
                globals()[name] = original
        symlink = consumers / "fanout-linked.php"
        symlink.symlink_to(consumers / "fanout-0000.php")
        try:
            build_plan(temp, change)
            raise AssertionError("Untrusted symlinked PHP consumer must be rejected")
        except ValueError as exc:
            assert "Symlinked plugin source entry" in str(exc)
        symlink.unlink()
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
    ap.add_argument("--verify-report", type=Path)
    args = ap.parse_args()
    if args.verify_report:
        print(json.dumps(verify_report(args.verify_report), sort_keys=True))
        return 0
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
        write_report(args.output, plan)
    print(json.dumps({"contract": plan["contract"], "head": args.head,
                      "direct": plan["direct_domains"], "impacted": plan["transitive_domains"],
                      "tests": len(plan["mandatory_regressions"]),
                      "missing": plan["missing_regressions"], "failed": failed,
                      "live_requirements": len(plan["required_live_evidence"]),
                      "reverse_dependents": plan["reverse_dependency_evidence"]["total"],
                      "dependency_analysis_complete": plan["reverse_dependency_evidence"]["complete"],
                      "release_ready": False}, sort_keys=True))
    if args.enforce and not plan["ci_regression_coverage_ready"]:
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
