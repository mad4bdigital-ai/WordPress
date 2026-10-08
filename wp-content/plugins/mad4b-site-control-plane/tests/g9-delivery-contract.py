#!/usr/bin/env python3
"""G9 delivery completeness check; prevents repository-only claims of live acceptance."""
from __future__ import annotations
import json
from pathlib import Path

# tests/ -> plugin/ -> plugins/ -> wp-content/ -> repository root
ROOT = Path(__file__).resolve().parents[3]
assert (ROOT / "wp-content/plugins/mad4b-site-control-plane").is_dir(), "G9_REPOSITORY_ROOT_UNRESOLVED"
assert (ROOT / ".github/workflows/feature-007-g9-resilience.yml").is_file(), "G9_REPOSITORY_WORKFLOW_UNAVAILABLE"
DOC = ROOT / "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/g9-delivery.json"
TASKS = ROOT / "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/tasks.md"
payload = json.loads(DOC.read_text(encoding="utf-8"))
expected = {f"T{i}" for i in [4066,4067,4068,4069,4070,4091,4092,4093,4094,4095]}
assert payload["contract"] == "mad4b.feature007-g9-delivery.v1"
assert payload["status"] == "REPOSITORY_G9_GUARDED_FOUNDATION_EXTERNAL_ACCEPTANCE_PENDING"
assert payload["exact_head_binding"] == "supplied_by_ci_not_embedded_in_commit"
assert set(payload["task_ids"]) == expected and len(payload["task_ids"]) == len(expected)
assert payload["task_status"] == "PARTIAL"
assert payload["integration_pr"] == 258 and payload["implementation_pr"] == 288
for key in ("runtime_parity_claimed", "live_staging_acceptance",
            "live_host_isolation_verified", "native_fleet_rollback_dispatched",
            "external_effect_reconciliation_verified", "post_restore_acceptance_receipt_issued",
            "all_task_done_claimed", "production_authorized",
            "new_grants_created", "authorizing", "source_files_include_real_execution_adapter"):
    assert payload.get(key) is False, "UNSUPPORTED_G9_ACCEPTANCE_CLAIM:" + key
seen = set()
for group in ("code_paths", "test_paths", "spec_paths"):
    entries = payload[group]
    assert entries, "EMPTY_G9_EVIDENCE:" + group
    for path in entries:
        relative = Path(path)
        assert not relative.is_absolute() and ".." not in relative.parts
        assert path not in seen, "DUPLICATE_G9_SOURCE_PATH:" + path
        seen.add(path)
        full = ROOT / relative
        assert full.is_file() and not full.is_symlink()
        assert full.resolve().is_relative_to(ROOT.resolve())
        assert len(full.read_bytes()) > 20, "EMPTY_G9_SOURCE:" + path
task_lines = TASKS.read_text(encoding="utf-8").splitlines()
for task in expected:
    assert any(line.startswith("- [ ] " + task + " ") for line in task_lines), "G9_TASK_STATE_DRIFT:" + task
assert len(payload["remaining_acceptance"]) >= 4
print("G9 DELIVERY CONTRACT: PASS (10 PARTIAL tasks, code/tests present; no live release claims)")
