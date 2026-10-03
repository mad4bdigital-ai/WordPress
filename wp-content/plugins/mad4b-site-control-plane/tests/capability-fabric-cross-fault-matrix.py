from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

tests_dir = Path(__file__).resolve().parent
repo_root = tests_dir.parents[3]
matrix_path = repo_root / "specs" / "007-content-intelligence-workflow-platform" / "capability-fabric-cross-fault-matrix.json"

matrix = json.loads(matrix_path.read_text(encoding="utf-8"))
if matrix.get("contract") != "mad4b.capability-fabric-cross-fault-matrix.v1":
    raise SystemExit("CROSS_FAULT_MATRIX_CONTRACT_INVALID")

required_dimensions = {
    "long_lived_worker_cache_leakage",
    "read_replica_lag",
    "db_deadlock_or_connection_loss",
    "hook_order_interference",
    "clone_restore_time_travel",
    "subject_revocation",
    "evidence_store_exhaustion",
    "fatal_interruption",
    "post_side_effect_cancellation",
    "mixed_generation_transport",
}
if set(matrix.get("required_dimensions", [])) != required_dimensions:
    raise SystemExit("CROSS_FAULT_REQUIRED_DIMENSION_DRIFT")

registry = {
    "request_generation": ["php", str(tests_dir / "request-generation-runtime.php")],
    "execution_fence_recursion": ["php", str(tests_dir / "runtime-execution-fence-recursion.php")],
    "clone_quarantine": ["python3", str(tests_dir / "clone-authority-quarantine-contract.py")],
    "restore_replay": ["php", str(tests_dir / "runtime-restore-replay-quarantine.php")],
    "subject_lifecycle": ["php", str(tests_dir / "subject-lifecycle-commit-guard-runtime.php")],
    "database_topology": ["php", str(tests_dir / "database-topology-runtime.php")],
    "durable_execution_faults": ["php", str(tests_dir / "durable-execution-runtime-faults.php")],
    "terminal_evidence_faults": ["php", str(tests_dir / "terminal-evidence-fault-runtime.php")],
    "fatal_interruption": ["php", str(tests_dir / "execution-fatal-interruption-runtime.php")],
    "remote_cancellation": ["php", str(tests_dir / "remote-work-queue-cancellation-runtime.php")],
    "catalog_chunk_reassembly": ["node", str(tests_dir / "catalog-chunk-reassembly-adversarial.mjs")],
}

scenarios = matrix.get("scenarios")
if not isinstance(scenarios, list) or not scenarios:
    raise SystemExit("CROSS_FAULT_SCENARIOS_MISSING")

covered = set()
selected_tests = []
scenario_ids = set()
for scenario in scenarios:
    if not isinstance(scenario, dict):
        raise SystemExit("CROSS_FAULT_SCENARIO_INVALID")
    scenario_id = str(scenario.get("id") or "")
    if not scenario_id or scenario_id in scenario_ids:
        raise SystemExit("CROSS_FAULT_SCENARIO_ID_INVALID")
    scenario_ids.add(scenario_id)

    dimensions = scenario.get("dimensions")
    test_ids = scenario.get("test_ids")
    if not isinstance(dimensions, list) or len(dimensions) < 2:
        raise SystemExit(f"CROSS_FAULT_SCENARIO_NOT_COMPOSED:{scenario_id}")
    if not isinstance(test_ids, list) or len(test_ids) < 2:
        raise SystemExit(f"CROSS_FAULT_SCENARIO_TESTS_INVALID:{scenario_id}")

    unknown_dimensions = sorted(set(dimensions) - required_dimensions)
    if unknown_dimensions:
        raise SystemExit(f"CROSS_FAULT_UNKNOWN_DIMENSIONS:{scenario_id}:{unknown_dimensions}")
    covered.update(dimensions)

    for test_id in test_ids:
        if test_id not in registry:
            raise SystemExit(f"CROSS_FAULT_UNKNOWN_TEST:{scenario_id}:{test_id}")
        if test_id not in selected_tests:
            selected_tests.append(test_id)

missing = sorted(required_dimensions - covered)
if missing:
    raise SystemExit(f"CROSS_FAULT_DIMENSIONS_UNCOVERED:{missing}")

for test_id in selected_tests:
    argv = registry[test_id]
    missing_paths = [arg for arg in argv[1:] if not Path(arg).is_file()]
    if missing_paths:
        raise SystemExit(f"CROSS_FAULT_TEST_FILE_MISSING:{test_id}:{missing_paths}")

    completed = subprocess.run(
        argv,
        cwd=str(repo_root),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        check=False,
    )
    sys.stdout.write(f"=== {test_id} ===\n")
    sys.stdout.write(completed.stdout)
    if completed.returncode != 0:
        raise SystemExit(f"CROSS_FAULT_TEST_FAILED:{test_id}:{completed.returncode}")

print(
    "mad4b.capability-fabric-cross-fault-matrix.v1: PASS "
    f"scenarios={len(scenarios)} dimensions={len(covered)} tests={len(selected_tests)}"
)
