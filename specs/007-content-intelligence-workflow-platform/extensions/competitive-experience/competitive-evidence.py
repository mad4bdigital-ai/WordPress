#!/usr/bin/env python3
"""Reproducible Competitive Experience evidence snapshot, history and packaged projection."""
from __future__ import annotations
import base64, hashlib, json, re
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PLUGIN = ROOT.parents[3] / "wp-content/plugins/mad4b-site-control-plane"
SUMMARY = PLUGIN / "config/competitive-evidence-summary.php"
HISTORY = ROOT / "competitive-evidence-history.json"

def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")

def sha(value):
    return hashlib.sha256(canonical(value)).hexdigest()

def load(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))

def build_snapshot(root: Path = ROOT):
    manifest, matrix, sources, ledger = (
        load(root / n)
        for n in ("artifact-manifest.json", "capability-matrix.json", "source-index.json", "task-ledger.generated.json")
    )
    source_map = {r["id"]: r for r in sources["evidence"]}
    task_map = {r["task_id"]: r for r in ledger["tasks"]}
    packages = sorted(
        (
            {
                "id": r["id"],
                "version": r["version"],
                "sha256": r["sha256"],
                "license": r["license"],
                "entry_point": r["entry_point"],
                "file_count": r["file_count"],
                "uncompressed_bytes": r["uncompressed_bytes"],
            }
            for r in manifest["packages"]
        ),
        key=lambda x: x["id"],
    )
    capabilities = []
    for r in matrix["capabilities"]:
        evidence_ids = sorted(r.get("evidence_ids", []))
        task_ids = sorted(r.get("task_ids", []))
        evidence_sources = [
            {
                "id": e,
                "kind": source_map[e]["kind"],
                "package_id": source_map[e].get("package_id"),
                "path": source_map[e]["path"],
                "runtime_verified": bool(source_map[e].get("runtime_verified", False)),
            }
            for e in evidence_ids
        ]
        capabilities.append(
            {
                "id": r["id"],
                "title": r["title"],
                "workstream_id": r["workstream_id"],
                "family": r["family"],
                "evidence_ids": evidence_ids,
                "evidence_classes": sorted({source_map[e]["kind"] for e in evidence_ids}),
                "evidence_sources": evidence_sources,
                "mad4b_foundation_paths": sorted(r.get("mad4b_baseline_paths", [])),
                "baseline_assessment": r["baseline_assessment"],
                "implementation_status": r["implementation_status"],
                "runtime_parity_claimed": bool(r["runtime_parity_claimed"]),
                "risk_class": r["risk_class"],
                "task_ids": task_ids,
                "task_statuses": {t: task_map[t]["status"] for t in task_ids},
                "acceptance_requirements": list(r.get("acceptance", [])),
                "denial_cases": list(r.get("denial_cases", [])),
            }
        )
    capabilities.sort(key=lambda x: x["id"])
    basis = {
        "contract": "mad4b.competitive-evidence-snapshot.v1",
        "packages": packages,
        "capabilities": capabilities,
        "source_generation": sha(sources),
        "task_generation": sha(ledger),
        "matrix_source_sha": matrix["baseline_source_sha"],
    }
    return {
        **basis,
        "generation_sha256": sha(basis),
        "authorizing": False,
        "runtime_certification_inferred": False,
        "marketing_claims_promoted": False,
    }

def semantic_diff(before, after):
    if before.get("contract") != "mad4b.competitive-evidence-snapshot.v1" or after.get("contract") != "mad4b.competitive-evidence-snapshot.v1":
        raise ValueError("snapshot_contract_invalid")
    b = {r["id"]: r for r in before["capabilities"]}
    a = {r["id"]: r for r in after["capabilities"]}
    changed = []
    for key in sorted(set(a) & set(b)):
        if sha(a[key]) != sha(b[key]):
            changed.append(
                {
                    "id": key,
                    "fields": sorted(k for k in set(a[key]) | set(b[key]) if a[key].get(k) != b[key].get(k)),
                    "before_sha256": sha(b[key]),
                    "after_sha256": sha(a[key]),
                }
            )
    bp = {r["id"]: r for r in before.get("packages", [])}
    ap = {r["id"]: r for r in after.get("packages", [])}
    package_changed = []
    for key in sorted(set(ap) & set(bp)):
        if sha(ap[key]) != sha(bp[key]):
            package_changed.append({"id": key, "before_sha256": sha(bp[key]), "after_sha256": sha(ap[key])})
    return {
        "contract": "mad4b.competitive-evidence-diff.v1",
        "before_generation_sha256": before.get("generation_sha256", ""),
        "after_generation_sha256": after.get("generation_sha256", ""),
        "added": sorted(set(a) - set(b)),
        "removed": sorted(set(b) - set(a)),
        "changed": changed,
        "package_added": sorted(set(ap) - set(bp)),
        "package_removed": sorted(set(bp) - set(ap)),
        "package_changed": package_changed,
        "source_drift": before.get("source_generation") != after.get("source_generation"),
        "task_drift": before.get("task_generation") != after.get("task_generation"),
        "matrix_source_drift": before.get("matrix_source_sha") != after.get("matrix_source_sha"),
        "authorizing": False,
    }

def operator_summary(snapshot):
    counts = {}
    rows = []
    for r in snapshot["capabilities"]:
        counts[r["implementation_status"]] = counts.get(r["implementation_status"], 0) + 1
        rows.append(
            {
                "id": r["id"],
                "title": r["title"],
                "status": r["implementation_status"],
                "risk_class": r["risk_class"],
                "task_ids": r["task_ids"],
                "task_statuses": r["task_statuses"],
                "evidence_ids": r["evidence_ids"],
                "evidence_classes": r["evidence_classes"],
                "evidence_sources": r["evidence_sources"],
                "mad4b_foundation_paths": r["mad4b_foundation_paths"],
                "baseline_assessment": r["baseline_assessment"],
                "runtime_parity_claimed": r["runtime_parity_claimed"],
                "acceptance_requirements": r["acceptance_requirements"],
            }
        )
    payload = {
        "contract": "mad4b.competitive-evidence-summary.v2",
        "snapshot_generation_sha256": snapshot["generation_sha256"],
        "package_count": len(snapshot["packages"]),
        "capability_count": len(snapshot["capabilities"]),
        "status_counts": dict(sorted(counts.items())),
        "packages": snapshot["packages"],
        "capabilities": rows,
        "authorizing": False,
    }
    payload["summary_sha256"] = sha(payload)
    return payload

def render_php_summary(summary):
    payload = canonical(summary).decode("utf-8")
    return (
        "<?php\n"
        "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
        "/** Generated by competitive-evidence.py. Direct web execution returns no evidence. */\n"
        "$payload = <<<'MAD4B_JSON'\n"
        + payload
        + "\nMAD4B_JSON;\n"
        "return json_decode( $payload, true );\n"
    )

def history_entry(snapshot, summary, revision, previous_generation=""):
    return {
        "revision": int(revision),
        "generation_sha256": snapshot["generation_sha256"],
        "previous_generation_sha256": previous_generation,
        "source_generation": snapshot["source_generation"],
        "task_generation": snapshot["task_generation"],
        "matrix_source_sha": snapshot["matrix_source_sha"],
        "summary_sha256": summary["summary_sha256"],
        "package_fingerprints": {p["id"]: p["sha256"] for p in snapshot["packages"]},
        "authorizing": False,
    }

def verify_history(snapshot, summary, history_path: Path = HISTORY):
    history = load(history_path)
    if history.get("contract") != "mad4b.competitive-evidence-history.v1" or history.get("authorizing") is not False:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_CONTRACT_INVALID")
    entries = history.get("entries")
    if not isinstance(entries, list) or not entries:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_EMPTY")
    seen = set()
    previous = ""
    for index, row in enumerate(entries, start=1):
        if row.get("revision") != index:
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_REVISION_INVALID")
        generation = row.get("generation_sha256", "")
        if not re.fullmatch(r"[a-f0-9]{64}", generation) or generation in seen:
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_GENERATION_INVALID")
        if row.get("previous_generation_sha256", "") != previous or row.get("authorizing") is not False:
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_CHAIN_INVALID")
        seen.add(generation)
        previous = generation
    current = entries[-1]
    if history.get("current_generation_sha256") != snapshot["generation_sha256"]:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_CURRENT_INVALID")
    if current.get("generation_sha256") != snapshot["generation_sha256"] or current.get("summary_sha256") != summary["summary_sha256"]:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_STALE")
    return history

def verify(root: Path = ROOT, summary_path: Path = SUMMARY, history_path: Path = HISTORY):
    snapshot = build_snapshot(root)
    expected = operator_summary(snapshot)
    expected_php = render_php_summary(expected)
    current_php = Path(summary_path).read_text(encoding="utf-8")
    if current_php != expected_php:
        raise SystemExit("COMPETITIVE_EVIDENCE_SUMMARY_DRIFT")
    history = verify_history(snapshot, expected, history_path)
    print("mad4b.competitive-evidence-snapshot.v1: PASS")
    print(
        f"generation={snapshot['generation_sha256']} packages={len(snapshot['packages'])} "
        f"capabilities={len(snapshot['capabilities'])} history_revision={history['entries'][-1]['revision']}"
    )
    return snapshot

if __name__ == "__main__":
    verify()
