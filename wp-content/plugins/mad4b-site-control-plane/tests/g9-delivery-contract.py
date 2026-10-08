#!/usr/bin/env python3
"""Verify G9 source fingerprints and canonical PARTIAL evidence, never live acceptance."""
from __future__ import annotations

from collections import Counter
from hashlib import sha256
import json
from pathlib import Path
import re


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise AssertionError(reason)


# parents[0]=tests; [1]=plugin; [2]=plugins; [3]=wp-content; [4]=repository root
ROOT = Path(__file__).resolve().parents[4]
BASE = ROOT / "wp-content/plugins/mad4b-site-control-plane"
EXT = ROOT / "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience"
WORKFLOW = ".github/workflows/feature-007-g9-resilience.yml"
BOOTSTRAP = "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
VALIDATOR = "wp-content/plugins/mad4b-site-control-plane/tests/g9-delivery-contract.py"
EXPECTED = {f"T{i}" for i in (4066, 4067, 4068, 4069, 4070, 4091, 4092, 4093, 4094, 4095)}
REFERENCES = {"g9-delivery.json", "g9-delivery.md"}


def source_bytes(path: str) -> bytes:
    require(isinstance(path, str) and bool(path), "G9_SOURCE_PATH_INVALID")
    relative = Path(path)
    require(not relative.is_absolute() and ".." not in relative.parts
            and "\\" not in path and "\0" not in path
            and relative.as_posix() == path, "G9_SOURCE_PATH_ESCAPE:" + path)
    full = ROOT / relative
    require(full.is_file() and not full.is_symlink(), "G9_SOURCE_UNAVAILABLE:" + path)
    require(full.resolve().is_relative_to(ROOT), "G9_SOURCE_PATH_ESCAPE:" + path)
    # Reject symlinked ancestors even when their current destination is local.
    require(all(not parent.is_symlink() for parent in full.parents
                if parent.is_relative_to(ROOT)), "G9_SOURCE_SYMLINK:" + path)
    data = full.read_bytes()
    require(len(data) > 20, "G9_SOURCE_EMPTY:" + path)
    return data


def exact_scope(value: object, label: str) -> None:
    require(isinstance(value, list) and all(isinstance(item, str) for item in value),
            "G9_TASK_SCOPE_INVALID:" + label)
    require(len(value) == len(EXPECTED) and set(value) == EXPECTED,
            "G9_TASK_SCOPE_DRIFT:" + label)


require(BASE.is_dir(), "G9_REPOSITORY_ROOT_UNRESOLVED")
require((ROOT / WORKFLOW).is_file(), "G9_REPOSITORY_WORKFLOW_UNAVAILABLE")
payload = json.loads((EXT / "g9-delivery.json").read_text(encoding="utf-8"))
require(payload.get("contract") == "mad4b.feature007-g9-delivery.v1", "G9_DELIVERY_CONTRACT_INVALID")
require(payload.get("status") == "REPOSITORY_G9_GUARDED_FOUNDATION_EXTERNAL_ACCEPTANCE_PENDING",
        "G9_DELIVERY_STATUS_INVALID")
require(payload.get("exact_head_binding") == "supplied_by_ci_not_embedded_in_commit",
        "G9_HEAD_BINDING_INVALID")
exact_scope(payload.get("task_ids"), "task_ids")
exact_scope(payload.get("partial_task_ids"), "partial_task_ids")
require(payload.get("task_status") == "PARTIAL", "G9_DELIVERY_TASK_STATE_INVALID")
require(payload.get("integration_pr") == 258 and payload.get("implementation_pr") == 288,
        "G9_INTEGRATION_BINDING_INVALID")
for key in ("runtime_parity_claimed", "live_staging_acceptance",
            "live_host_isolation_verified", "native_fleet_rollback_dispatched",
            "external_effect_reconciliation_verified", "post_restore_acceptance_receipt_issued",
            "all_task_done_claimed", "production_authorized", "new_grants_created",
            "authorizing", "source_files_include_real_execution_adapter",
            "native_journal_linkage_provider_implemented"):
    require(payload.get(key) is False, "UNSUPPORTED_G9_ACCEPTANCE_CLAIM:" + key)

declared: set[str] = set()
for group in ("code_paths", "test_paths", "spec_paths"):
    paths = payload.get(group)
    require(isinstance(paths, list) and bool(paths), "EMPTY_G9_EVIDENCE:" + group)
    for path in paths:
        source_bytes(path)
        require(path not in declared, "DUPLICATE_G9_SOURCE_PATH:" + path)
        declared.add(path)
    # Omitting an owned code/fixture file must not shrink fingerprint coverage.
    if group == "code_paths":
        files = list((BASE / "includes").glob("class-mad4b-scp-g9-*.php"))
        files += [BASE / "includes/class-mad4b-scp-resilience-context.php",
                  BASE / "includes/class-mad4b-scp-resilience-anchor.php"]
    elif group == "test_paths":
        files = list((BASE / "tests").glob("g9-*.php")) + list((BASE / "tests").glob("g9-*.py"))
    else:
        files = list(EXT.glob("g9-*.md"))
    require(set(paths) == {file.relative_to(ROOT).as_posix() for file in files},
            "G9_SOURCE_INVENTORY_DRIFT:" + group)

inventory = declared | {BOOTSTRAP, WORKFLOW, VALIDATOR}
records = payload.get("evidence_integrity")
require(isinstance(records, list) and len(records) == len(inventory), "G9_FINGERPRINT_INVENTORY_MISSING")
fingerprinted: set[str] = set()
for record in records:
    require(isinstance(record, dict) and set(record) == {"path", "sha256", "bytes"},
            "G9_FINGERPRINT_SCHEMA_INVALID")
    path = record["path"]
    data = source_bytes(path)
    require(path in inventory and path not in fingerprinted, "G9_FINGERPRINT_INVENTORY_DRIFT:" + path)
    fingerprinted.add(path)
    require(isinstance(record["sha256"], str) and re.fullmatch(r"[a-f0-9]{64}", record["sha256"])
            and record["sha256"] == sha256(data).hexdigest(), "G9_SOURCE_SHA_DRIFT:" + path)
    require(type(record["bytes"]) is int and record["bytes"] == len(data), "G9_SOURCE_BYTES_DRIFT:" + path)
require(fingerprinted == inventory, "G9_FINGERPRINT_INVENTORY_DRIFT")

task_data = (EXT / "tasks.md").read_bytes()
status_data = (EXT / "task-status.json").read_bytes()
status = json.loads(status_data)
ledger = json.loads((EXT / "task-ledger.generated.json").read_text(encoding="utf-8"))
require(status.get("contract") == "mad4b.competitive-experience-task-status.v1"
        and status.get("documentation_only_completion_forbidden") is True,
        "G9_CANONICAL_STATUS_CONTRACT_INVALID")
require(ledger.get("contract") == "mad4b.competitive-experience-task-ledger.v1"
        and ledger.get("authorizing") is False and ledger.get("release_closure_included") is False,
        "G9_CANONICAL_LEDGER_CONTRACT_INVALID")
require(ledger.get("source_tasks_sha256") == sha256(task_data).hexdigest()
        and ledger.get("status_source_sha256") == sha256(status_data).hexdigest(),
        "G9_CANONICAL_LEDGER_SOURCE_DRIFT")
rows = ledger.get("tasks")
require(isinstance(rows, list) and all(isinstance(row, dict) for row in rows), "G9_CANONICAL_LEDGER_INVALID")
require(all(isinstance(row.get("task_id"), str) for row in rows), "G9_CANONICAL_TASK_ID_INVALID")
by_id = {row["task_id"]: row for row in rows}
require(len(by_id) == len(rows) == ledger.get("task_count"), "G9_CANONICAL_TASK_DUPLICATE")
counts = Counter(row.get("status") for row in rows)
require(set(counts) <= {"OPEN", "PARTIAL", "DONE", "DEFERRED"}
        and ledger.get("counts") == {key: counts[key] for key in ("OPEN", "PARTIAL", "DONE", "DEFERRED")},
        "G9_CANONICAL_LEDGER_COUNT_DRIFT")
definitions = re.findall(r"^- \[([ xX])\] (T\d+) (P[0-2]) (.+)$", task_data.decode("utf-8"), re.MULTILINE)
tasks = {task: (mark, priority, summary) for mark, task, priority, summary in definitions}
require(len(tasks) == len(definitions) and set(tasks) == set(by_id), "G9_CANONICAL_TASK_INVENTORY_DRIFT")
overrides = status.get("overrides")
require(isinstance(overrides, dict), "G9_CANONICAL_OVERRIDES_INVALID")
for task in EXPECTED:
    entry, row = overrides.get(task), by_id.get(task)
    require(isinstance(entry, dict) and isinstance(row, dict)
            and entry.get("status") == row.get("status") == "PARTIAL", "G9_CANONICAL_TASK_STATE_DRIFT:" + task)
    require(isinstance(entry.get("reason"), str) and len(entry["reason"].strip()) > 12
            and entry["reason"] == row.get("reason"), "G9_CANONICAL_TASK_REASON_DRIFT:" + task)
    refs = entry.get("evidence_refs")
    require(isinstance(refs, list) and all(isinstance(ref, str) for ref in refs)
            and REFERENCES <= set(refs) and refs == row.get("evidence_refs"),
            "G9_CANONICAL_TASK_EVIDENCE_DRIFT:" + task)
    mark, priority, summary = tasks[task]
    require(mark == " " and row.get("priority") == priority and row.get("summary") == summary,
            "G9_TASK_DEFINITION_DRIFT:" + task)
remaining = payload.get("remaining_acceptance")
require(isinstance(remaining, list) and len(remaining) >= 4
        and all(isinstance(item, str) and bool(item.strip()) for item in remaining), "G9_ACCEPTANCE_GAPS_MISSING")
print("G9 DELIVERY CONTRACT: PASS (10 canonical PARTIAL tasks and exact source fingerprints; no live release claims)")
