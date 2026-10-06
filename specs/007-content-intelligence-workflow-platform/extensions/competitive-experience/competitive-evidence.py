#!/usr/bin/env python3
"""Reproducible Competitive Experience evidence snapshot, history and packaged projection."""
from __future__ import annotations
import base64, hashlib, json, re, tempfile
from pathlib import Path
from copy import deepcopy

ROOT = Path(__file__).resolve().parent
PLUGIN = ROOT.parents[3] / "wp-content/plugins/mad4b-site-control-plane"
SUMMARY = PLUGIN / "config/competitive-evidence-summary.php"
HISTORY = ROOT / "competitive-evidence-history.json"
HISTORY_RESOURCE = PLUGIN / "config/competitive-evidence-history.php"
HISTORY_CONTRACT = "mad4b.competitive-evidence-history.v2"
MAX_HISTORY_ENTRIES = 64

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
                "workstream_id": r.get("workstream_id", ""),
                "family": r.get("family", ""),
                "status": r["implementation_status"],
                "risk_class": r["risk_class"],
                "task_ids": r["task_ids"],
                "task_statuses": r["task_statuses"],
                "open_task_ids": sorted(t for t, status in r["task_statuses"].items() if str(status).upper() == "OPEN"),
                "partial_task_ids": sorted(t for t, status in r["task_statuses"].items() if str(status).upper() == "PARTIAL"),
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

def render_php_resource(payload, label):
    body = canonical(payload).decode("utf-8")
    return (
        "<?php\n"
        "if ( ! defined( 'ABSPATH' ) ) {\n"
        "    if ( function_exists( 'http_response_code' ) ) { http_response_code( 404 ); }\n"
        "    exit;\n"
        "}\n"
        + f"/** Generated by competitive-evidence.py. Direct web execution returns HTTP 404 with no {label}. */\n"
        + "$payload = <<<'MAD4B_JSON'\n"
        + body
        + "\nMAD4B_JSON;\n"
        "return json_decode( $payload, true );\n"
    )

def render_php_summary(summary):
    return render_php_resource(summary, "evidence")

def render_php_history(history):
    return render_php_resource(history, "history")

def history_entry(snapshot, summary, revision, previous_generation="", previous_entry_sha256="", state="known_good", drift_flags=None):
    row = {
        "revision": int(revision),
        "generation_sha256": snapshot["generation_sha256"],
        "previous_generation_sha256": previous_generation,
        "previous_entry_sha256": previous_entry_sha256,
        "source_generation": snapshot["source_generation"],
        "task_generation": snapshot["task_generation"],
        "matrix_source_sha": snapshot["matrix_source_sha"],
        "summary_sha256": summary["summary_sha256"],
        "package_fingerprints": {p["id"]: p["sha256"] for p in snapshot["packages"]},
        "state": state,
        "drift_flags": drift_flags or {"source": False, "task": False, "matrix": False, "packages": False},
        "authorizing": False,
    }
    row["entry_sha256"] = sha(row)
    return row

def next_history(history, snapshot, summary):
    history = deepcopy(history)
    entries = history.get("entries", [])
    if len(entries) >= MAX_HISTORY_ENTRIES:
        raise ValueError("history_retention_full")
    previous = entries[-1] if entries else {}
    previous_generation = previous.get("generation_sha256", "")
    previous_entry = previous.get("entry_sha256", "")
    current_packages = {p["id"]: p["sha256"] for p in snapshot["packages"]}
    drift_flags = {
        "source": bool(previous) and previous.get("source_generation") != snapshot["source_generation"],
        "task": bool(previous) and previous.get("task_generation") != snapshot["task_generation"],
        "matrix": bool(previous) and previous.get("matrix_source_sha") != snapshot["matrix_source_sha"],
        "packages": bool(previous) and previous.get("package_fingerprints") != current_packages,
    }
    drifted = any(drift_flags.values())
    entry = history_entry(snapshot, summary, len(entries) + 1, previous_generation, previous_entry, "drifted" if drifted else "known_good", drift_flags)
    entries.append(entry)
    history.update({
        "contract": HISTORY_CONTRACT,
        "retention": {"max_entries": MAX_HISTORY_ENTRIES, "policy": "append_only_fail_closed", "silent_pruning": False},
        "entries": entries,
        "current_generation_sha256": entry["generation_sha256"],
        "current_entry_sha256": entry["entry_sha256"],
        "authorizing": False,
    })
    history.setdefault("alerts", [])
    history.setdefault("acknowledgements", [])
    if drifted:
        alert = {
            "alert_id": "drift-" + entry["generation_sha256"][:16],
            "revision": entry["revision"],
            "generation_sha256": entry["generation_sha256"],
            "reasons": sorted(k for k, v in drift_flags.items() if v),
            "state": "open",
            "authorizing": False,
        }
        alert["alert_sha256"] = sha(alert)
        history["alerts"].append(alert)
    known = [r for r in entries if r.get("state") == "known_good"]
    history["previous_known_good_generation_sha256"] = known[-1]["generation_sha256"] if known else ""
    return history

def acknowledge_history_alert(history, alert_id, actor):
    history = deepcopy(history)
    alerts = history.get("alerts", [])
    target = next((r for r in alerts if r.get("alert_id") == alert_id), None)
    if not target or target.get("state") != "open":
        raise ValueError("history_alert_not_open")
    actor = str(actor).strip()
    if not actor:
        raise ValueError("history_ack_actor_required")
    target["state"] = "acknowledged"
    basis = dict(target); basis.pop("alert_sha256", None)
    target["alert_sha256"] = sha(basis)
    ack = {
        "ack_revision": len(history.get("acknowledgements", [])) + 1,
        "alert_id": alert_id,
        "generation_sha256": target["generation_sha256"],
        "actor": actor,
        "authorizing": False,
    }
    ack["ack_sha256"] = sha(ack)
    history.setdefault("acknowledgements", []).append(ack)
    previous = ""
    for entry in history.get("entries", []):
        if entry.get("generation_sha256") == target["generation_sha256"]:
            entry["state"] = "known_good"
        entry["previous_entry_sha256"] = previous
        basis = dict(entry); basis.pop("entry_sha256", None)
        entry["entry_sha256"] = sha(basis)
        previous = entry["entry_sha256"]
    history["current_entry_sha256"] = previous
    known = [r["generation_sha256"] for r in history.get("entries", []) if r.get("state") == "known_good"]
    current_generation = history.get("current_generation_sha256", "")
    if known and known[-1] == current_generation:
        history["previous_known_good_generation_sha256"] = known[-2] if len(known) > 1 else ""
    else:
        history["previous_known_good_generation_sha256"] = known[-1] if known else ""
    return history

def verify_history(snapshot, summary, history_path: Path = HISTORY):
    history = load(history_path)
    if history.get("contract") != HISTORY_CONTRACT or history.get("authorizing") is not False:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_CONTRACT_INVALID")
    retention = history.get("retention")
    if retention != {"max_entries": MAX_HISTORY_ENTRIES, "policy": "append_only_fail_closed", "silent_pruning": False}:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_RETENTION_INVALID")
    entries = history.get("entries")
    if not isinstance(entries, list) or not entries or len(entries) > MAX_HISTORY_ENTRIES:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_EMPTY")
    alerts = history.get("alerts", [])
    acks = history.get("acknowledgements", [])
    if not isinstance(alerts, list) or not isinstance(acks, list) or len(alerts) > MAX_HISTORY_ENTRIES or len(acks) > MAX_HISTORY_ENTRIES:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_JOURNAL_UNBOUNDED")
    seen = set()
    previous_generation = ""
    previous_entry = ""
    known_good = []
    for index, row in enumerate(entries, start=1):
        if row.get("revision") != index:
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_REVISION_INVALID")
        generation = row.get("generation_sha256", "")
        if not re.fullmatch(r"[a-f0-9]{64}", generation) or generation in seen:
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_GENERATION_INVALID")
        if row.get("previous_generation_sha256", "") != previous_generation or row.get("previous_entry_sha256", "") != previous_entry or row.get("authorizing") is not False:
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_CHAIN_INVALID")
        if row.get("state") not in {"known_good", "drifted"} or not isinstance(row.get("drift_flags"), dict):
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_STATE_INVALID")
        claimed_entry = row.get("entry_sha256", "")
        basis = dict(row); basis.pop("entry_sha256", None)
        if not re.fullmatch(r"[a-f0-9]{64}", claimed_entry) or claimed_entry != sha(basis):
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_ENTRY_DIGEST_INVALID")
        if row.get("state") == "known_good":
            known_good.append(generation)
        seen.add(generation)
        previous_generation = generation
        previous_entry = claimed_entry
    ack_ids = set()
    for ack in acks:
        claimed = ack.get("ack_sha256", "")
        basis = dict(ack); basis.pop("ack_sha256", None)
        if ack.get("authorizing") is not False or not re.fullmatch(r"[a-f0-9]{64}", claimed) or claimed != sha(basis):
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_ACK_INVALID")
        ack_ids.add(ack.get("alert_id"))
    for alert in alerts:
        claimed = alert.get("alert_sha256", "")
        basis = dict(alert); basis.pop("alert_sha256", None)
        if alert.get("authorizing") is not False or alert.get("state") not in {"open", "acknowledged"} or not re.fullmatch(r"[a-f0-9]{64}", claimed) or claimed != sha(basis):
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_ALERT_INVALID")
        if alert.get("state") == "acknowledged" and alert.get("alert_id") not in ack_ids:
            raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_ACK_MISSING")
    current = entries[-1]
    if history.get("current_generation_sha256") != snapshot["generation_sha256"] or history.get("current_entry_sha256") != previous_entry:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_CURRENT_INVALID")
    if current.get("generation_sha256") != snapshot["generation_sha256"] or current.get("summary_sha256") != summary["summary_sha256"]:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_STALE")
    expected_known = known_good[-2] if len(known_good) > 1 and known_good[-1] == current.get("generation_sha256") else (known_good[-1] if known_good and known_good[-1] != current.get("generation_sha256") else "")
    if history.get("previous_known_good_generation_sha256", "") != expected_known:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_KNOWN_GOOD_INVALID")
    return history

def verify(root: Path = ROOT, summary_path: Path = SUMMARY, history_path: Path = HISTORY):
    snapshot = build_snapshot(root)
    expected = operator_summary(snapshot)
    expected_php = render_php_summary(expected)
    current_php = Path(summary_path).read_text(encoding="utf-8")
    if current_php != expected_php:
        raise SystemExit("COMPETITIVE_EVIDENCE_SUMMARY_DRIFT")
    history = verify_history(snapshot, expected, history_path)
    expected_history_php = render_php_history(history)
    current_history_php = Path(HISTORY_RESOURCE).read_text(encoding="utf-8")
    if current_history_php != expected_history_php:
        raise SystemExit("COMPETITIVE_EVIDENCE_HISTORY_RESOURCE_DRIFT")
    print("mad4b.competitive-evidence-snapshot.v1: PASS")
    print(
        f"generation={snapshot['generation_sha256']} packages={len(snapshot['packages'])} "
        f"capabilities={len(snapshot['capabilities'])} history_revision={history['entries'][-1]['revision']}"
    )
    return snapshot

if __name__ == "__main__":
    verify()
