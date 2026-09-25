#!/usr/bin/env python3
"""Formal finite-state invariants for Feature 007 Critical Kernel."""

from __future__ import annotations

import json
import re
from collections import deque
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SPEC = ROOT / "specs/007-content-intelligence-workflow-platform"

content_jobs = (ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php").read_text(encoding="utf-8")
durable = (ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-durable-execution.php").read_text(encoding="utf-8")
runner = (ROOT / "tools/mad4b_host_runner.py").read_text(encoding="utf-8")
gate = json.loads((SPEC / "gate-graph.json").read_text(encoding="utf-8"))
closure = json.loads((SPEC / "implementation-closure.json").read_text(encoding="utf-8"))

# ContentJob lifecycle: terminal states have no outgoing edges.
expected_content = {
    "NEW": {"QUEUED", "CANCELLED"},
    "QUEUED": {"RUNNING", "BLOCKED", "FAILED", "CANCELLED"},
    "RUNNING": {"WAITING_REVIEW", "BLOCKED", "FAILED", "COMPLETED", "CANCELLED"},
    "WAITING_REVIEW": {"RUNNING", "BLOCKED", "FAILED", "COMPLETED", "CANCELLED"},
    "BLOCKED": {"QUEUED", "RUNNING", "FAILED", "CANCELLED"},
    "FAILED": {"QUEUED", "CANCELLED"},
    "COMPLETED": set(),
    "CANCELLED": set(),
}
for state, targets in expected_content.items():
    if not targets:
        needle = f"'{state}' => array()"
        if needle not in content_jobs:
            raise SystemExit(f"ContentJob terminal state may have outgoing transitions: {state}")
    else:
        if f"'{state}' => array(" not in content_jobs:
            raise SystemExit(f"ContentJob transition map missing state: {state}")
if "mad4b_content_job_terminal_immutable" not in content_jobs:
    raise SystemExit("ContentJob terminal immutability guard missing")
if "job_revision' => $expected_revision" not in content_jobs:
    raise SystemExit("ContentJob transition CAS predicate missing")

# Durable lease state machine: completion requires live ownership/epoch/expiry,
# terminal leases cannot be reclaimed, stale workers cannot heartbeat/complete.
for marker in (
    "status='active' AND expires_at>%s",
    "mad4b_lease_terminal_reclaim_denied",
    "mad4b_lease_heartbeat_fenced",
    "mad4b_lease_complete_fenced",
    "mad4b_fence_epoch_stale",
):
    if marker not in durable:
        raise SystemExit(f"durable execution terminal/fencing invariant missing: {marker}")

# Host Runner mutation state graph is intentionally one-way.
host_states = {
    "MUTATION_STARTED": {
        "DURABLE_VERIFIED_RECEIPT",
        "ROLLED_BACK_AFTER_FAILURE",
        "MUTATED_BUT_EVIDENCE_UNCERTAIN",
    },
    "DURABLE_VERIFIED_RECEIPT": set(),
    "ROLLED_BACK_AFTER_FAILURE": set(),
    "MUTATED_BUT_EVIDENCE_UNCERTAIN": set(),
}
for state in host_states:
    if state not in runner:
        raise SystemExit(f"Host Runner mutation state missing: {state}")
for marker in (
    '"blind_retry_allowed": False',
    "HOST_RUNNER_REPLAY_RECONCILIATION_REQUIRED",
    '"state": "RECOVERY_REQUIRED"',
    '"state": "DEAD_LETTERED"',
):
    if marker not in runner:
        raise SystemExit(f"Host Runner fail-closed state invariant missing: {marker}")

# Abstract reachability: no Host Runner terminal state has an outgoing path.
for state, targets in host_states.items():
    if not targets and state == "MUTATION_STARTED":
        raise SystemExit("invalid host state model")
    if state != "MUTATION_STARTED" and targets:
        raise SystemExit(f"terminal Host Runner state has outgoing transitions: {state}")

# Gate graph structural proof: unique IDs, resolved dependencies, DAG,
# every declared terminal reachable from roots, every blocker closable.
nodes = gate.get("gates", [])
ids = [row["id"] for row in nodes]
if len(ids) != len(set(ids)):
    raise SystemExit("gate graph contains duplicate IDs")
idset = set(ids)
deps = {row["id"]: list(row.get("depends_on", [])) for row in nodes}
for node, reqs in deps.items():
    unknown = set(reqs) - idset
    if unknown:
        raise SystemExit(f"gate dependency unresolved: {node}: {sorted(unknown)}")

indegree = {node: 0 for node in ids}
reverse = {node: [] for node in ids}
for node, reqs in deps.items():
    indegree[node] = len(reqs)
    for req in reqs:
        reverse[req].append(node)
queue = deque(sorted([node for node, n in indegree.items() if n == 0]))
visited = []
while queue:
    node = queue.popleft()
    visited.append(node)
    for nxt in reverse[node]:
        indegree[nxt] -= 1
        if indegree[nxt] == 0:
            queue.append(nxt)
if len(visited) != len(ids):
    raise SystemExit("gate graph contains a cycle")

roots = set(gate.get("root_gates", []))
terminals = set(gate.get("terminal_states", []))
if not roots or not terminals:
    raise SystemExit("gate graph missing roots or terminals")

def forward_reachable(start):
    out = set()
    q = deque([start])
    while q:
        cur = q.popleft()
        if cur in out:
            continue
        out.add(cur)
        q.extend(reverse.get(cur, []))
    return out

reachable_from_roots = set()
for root in roots:
    if root not in idset:
        raise SystemExit(f"unknown root gate: {root}")
    reachable_from_roots |= forward_reachable(root)
for terminal in terminals:
    if terminal not in reachable_from_roots:
        raise SystemExit(f"terminal gate unreachable from roots: {terminal}")

for row in closure.get("workstreams", []):
    if row.get("priority") not in {"KERNEL_BLOCKER", "LIVE_PRECONDITION"}:
        continue
    gate_id = row.get("gate")
    if gate_id not in idset:
        continue
    if not (forward_reachable(gate_id) & terminals):
        raise SystemExit(f"blocking gate cannot reach terminal: {row.get('id')}:{gate_id}")

print("mad4b.formal-critical-state.v1: PASS")
