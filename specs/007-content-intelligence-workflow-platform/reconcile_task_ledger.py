#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent
TASKS = ROOT / "tasks.md"
OVERRIDES = ROOT / "task-ledger-overrides.json"
OUTPUT = ROOT / "task-ledger.generated.json"

TASK_RE = re.compile(r"^- \[(?P<mark>[ x])\] T(?P<id>\d{4}) (?P<priority>P\d) (?P<summary>.+)$")
PHASE_RE = re.compile(r"^## Phase (?P<phase>\d+)\b")
ALLOWED = {"DONE", "PARTIAL", "OPEN", "DEFERRED"}


def git_blob_sha(path: Path) -> str:
    raw = path.read_bytes()
    return hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\\0" + raw).hexdigest()


def build() -> dict:
    override_doc = json.loads(OVERRIDES.read_text(encoding="utf-8"))
    overrides = override_doc.get("overrides", {})
    if not isinstance(overrides, dict):
        raise SystemExit("TASK_LEDGER: overrides must be an object")

    phase = None
    rows = []
    seen = set()
    for raw in TASKS.read_text(encoding="utf-8").splitlines():
        pm = PHASE_RE.match(raw)
        if pm:
            phase = int(pm.group("phase"))
            continue
        tm = TASK_RE.match(raw)
        if not tm:
            continue
        task_id = "T" + tm.group("id")
        if task_id in seen:
            raise SystemExit(f"TASK_LEDGER: duplicate task id {task_id}")
        seen.add(task_id)
        checked = tm.group("mark") == "x"
        override = overrides.get(task_id, {})
        status = override.get("status", "DONE" if checked else "OPEN")
        if status not in ALLOWED:
            raise SystemExit(f"TASK_LEDGER: invalid status {task_id}={status}")
        evidence = override.get("evidence_refs", [])
        reason = str(override.get("reason", "")).strip()

        if checked and status != "DONE":
            raise SystemExit(f"TASK_LEDGER: checked task must be DONE: {task_id}")
        if status == "DONE" and not checked:
            raise SystemExit(f"TASK_LEDGER: DONE override must also be checked in tasks.md: {task_id}")
        if status in {"DONE", "PARTIAL", "DEFERRED"}:
            if not isinstance(evidence, list) or not evidence or any(not str(x).strip() for x in evidence):
                raise SystemExit(f"TASK_LEDGER: {status} task requires evidence refs: {task_id}")
            if not reason:
                raise SystemExit(f"TASK_LEDGER: {status} task requires reason: {task_id}")
        if status == "OPEN" and checked:
            raise SystemExit(f"TASK_LEDGER: OPEN task cannot be checked: {task_id}")

        rows.append({
            "task_id": task_id,
            "phase": phase,
            "priority": tm.group("priority"),
            "summary": tm.group("summary"),
            "status": status,
            "checked": checked,
            "evidence_refs": list(map(str, evidence)),
            "reason": reason,
        })

    unknown = sorted(set(overrides) - seen)
    if unknown:
        raise SystemExit("TASK_LEDGER: override refers to unknown task(s): " + ",".join(unknown))

    counts = {status: sum(1 for row in rows if row["status"] == status) for status in sorted(ALLOWED)}
    by_priority = {}
    for row in rows:
        by_priority.setdefault(row["priority"], {status: 0 for status in sorted(ALLOWED)})
        by_priority[row["priority"]][row["status"]] += 1

    return {
        "contract": "mad4b.feature007-task-ledger.v1",
        "source_tasks_git_blob_sha": git_blob_sha(TASKS),
        "override_git_blob_sha": git_blob_sha(OVERRIDES),
        "classification_policy": {
            "default_unchecked": "OPEN",
            "default_checked": "DONE",
            "non_open_requires_explicit_override_and_evidence": True,
            "bulk_completion_without_evidence_forbidden": True,
        },
        "counts": counts,
        "counts_by_priority": by_priority,
        "task_count": len(rows),
        "tasks": rows,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    built = build()
    payload = json.dumps(built, indent=2, sort_keys=True) + "\n"
    if args.check:
        if not OUTPUT.is_file():
            raise SystemExit("TASK_LEDGER: generated ledger missing")
        try:
            current = json.loads(OUTPUT.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            raise SystemExit(f"TASK_LEDGER: generated ledger is invalid JSON: {exc}")
        if current != built:
            raise SystemExit("TASK_LEDGER: generated ledger drift; run reconcile_task_ledger.py")
        print("FEATURE_007_TASK_LEDGER: PASS")
        print(json.dumps(built["counts"], sort_keys=True))
        return 0
    OUTPUT.write_text(payload, encoding="utf-8")
    print(str(OUTPUT))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
