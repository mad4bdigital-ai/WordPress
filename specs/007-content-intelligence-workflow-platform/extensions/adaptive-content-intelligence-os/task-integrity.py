#!/usr/bin/env python3
"""ACI01 immutable Spec backlog integrity; never grant or acceptance evidence.

The task registry is a SPEC backlog, not a live completion ledger. This script
refuses any false DONE/authority inference and validates cross-file relations.
It does not inspect WordPress, run PHP, accept signed provider certificates,
or certify staging, publication, costs, rights, or a release.
"""
from __future__ import annotations

import argparse
import copy
import hashlib
import json
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parent


class SpecIntegrityError(ValueError):
    pass


def insist(value, reason):
    if not value:
        raise SpecIntegrityError(reason)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def get_json(root, name):
    data = (root / name).read_bytes()
    return json.loads(data), digest(data)


def no_dependency_cycle(items, dep_key, label):
    by_id = {item["id"]: item for item in items}
    visited, pending = set(), set()

    def walk(node):
        insist(node in by_id, label + "_unknown_dependency")
        insist(node not in pending, label + "_cycle")
        if node in visited:
            return
        pending.add(node)
        for parent in by_id[node].get(dep_key, []):
            insist(isinstance(parent, str), label + "_dependency_not_string")
            walk(parent)
        pending.remove(node)
        visited.add(node)

    for item in items:
        walk(item["id"])
    return len(visited)


def validate(manifest, task_registry, gate_registry, requirements):
    insist(manifest.get("contract") == "mad4b.aci-os.spec-kit.v1", "manifest_contract")
    insist(manifest.get("spec_only") is True
           and manifest.get("execution_enabled") is False
           and manifest.get("authorizing") is False
           and manifest.get("production_authorized") is False, "spec_authority_widening")
    tasks = task_registry.get("tasks")
    gates = gate_registry.get("gates")
    reqs = requirements.get("requirements")
    insist(isinstance(tasks, list) and isinstance(gates, list)
           and isinstance(reqs, list), "registry_shape_invalid")
    insist(task_registry.get("status") == "SPEC_BACKLOG_ONLY"
           and gate_registry.get("status") == "SPEC_BACKLOG_ONLY"
           and requirements.get("status") == "SPEC_BACKLOG_ONLY", "live_status_in_spec_registry")

    def rows_by_id(rows, label):
        insist(all(isinstance(x, dict) and isinstance(x.get("id"), str)
                   and re.fullmatch(r"ACI-(?:T\\d{4}|G\\d+|\\d{3})", x["id"])
                   for x in rows), label + "_id_invalid")
        names = [x["id"] for x in rows]
        insist(len(names) == len(set(names)), label + "_duplicate")
        return {x["id"]: x for x in rows}

    ts = rows_by_id(tasks, "tasks")
    gs = rows_by_id(gates, "gates")
    rs = rows_by_id(reqs, "requirements")
    insist(len(tasks) == manifest.get("task_count"), "task_count_mismatch")
    insist(set(ts) == set(manifest.get("task_ids", [])), "task_manifest_drift")
    insist(set(gs) == set(manifest.get("gate_ids", [])), "gate_manifest_drift")
    insist(set(rs) == set(manifest.get("requirement_ids", [])), "requirements_manifest_drift")
    insist(all(x["id"].startswith("ACI-T") for x in tasks), "task_prefix_invalid")
    insist(all(x["id"].startswith("ACI-G") for x in gates), "gate_prefix_invalid")
    insist(all(re.fullmatch(r"ACI-\\d{3}", x["id"]) for x in reqs), "requirement_prefix_invalid")

    for task in tasks:
        insist(task.get("gate") in gs, "task_gate_missing")
        insist(task.get("status") == "OPEN"
               and task.get("execution_effect") == "SPEC_ONLY"
               and task.get("authorizing") is False
               and task.get("completion_claimed") is False, "task_completion_forged")
        insist(isinstance(task.get("execution_dependencies"), list), "task_dependencies_missing")
        insist(all(x in ts for x in task["execution_dependencies"]), "task_unknown_dependency")
        insist(all(x in rs for x in task.get("requirement_ids", [])), "task_unknown_requirement")
        insist(task.get("expected_evidence_contract") ==
               "mad4b.aci.task." + task["id"][-4:] + ".v1", "task_evidence_contract_drift")
    for gate in gates:
        insist(gate.get("status") == "OPEN" and gate.get("authorizing") is False
               and gate.get("completion_claimed") is False, "gate_completion_forged")
        insist(all(x in gs for x in gate.get("depends_on", [])), "gate_unknown_dependency")
        insist(all(x in rs for x in gate.get("requirement_ids", [])), "gate_unknown_requirement")
        insist(isinstance(gate.get("task_ids"), list)
               and len(gate["task_ids"]) == len(set(gate["task_ids"])), "gate_duplicate_task")
        insist(set(gate["task_ids"]) == {
            t["id"] for t in tasks if t["gate"] == gate["id"]
        }, "gate_task_membership_mismatch")
        insist(isinstance(gate.get("conditional_dependencies"), list), "conditional_dependency_shape")
        for dependency in gate["conditional_dependencies"]:
            insist(isinstance(dependency, dict) and dependency.get("gate") in gs
                   and isinstance(dependency.get("when"), str)
                   and bool(dependency["when"]), "conditional_dependency_invalid")
    insist(no_dependency_cycle(tasks, "execution_dependencies", "task") == len(tasks), "task_graph_incomplete")
    insist(no_dependency_cycle(gates, "depends_on", "gate") == len(gates), "gate_graph_incomplete")
    return {"contract": "mad4b.aci01.spec-integrity-audit.v1",
            "status": "PASS_SOURCE_ONLY",
            "task_count": len(tasks),
            "gate_count": len(gates),
            "requirement_count": len(reqs),
            "completion_certified": False,
            "runtime_certified": False,
            "authorizing": False,
            "mutation_performed": False}


def self_test(bundle):
    def reject(change, expected):
        data = copy.deepcopy(bundle)
        change(*data)
        try:
            validate(*data)
        except SpecIntegrityError as exc:
            insist(expected in str(exc), "selftest_wrong_denial_" + str(exc))
        else:
            raise SpecIntegrityError("selftest_false_acceptance_" + expected)

    reject(lambda m, t, g, r: t["tasks"][0].update(status="DONE"), "task_completion_forged")
    reject(lambda m, t, g, r: t["tasks"][0].update(completion_claimed=True), "task_completion_forged")
    reject(lambda m, t, g, r: g["gates"][0].update(completion_claimed=True), "gate_completion_forged")
    reject(lambda m, t, g, r: m.update(production_authorized=True), "spec_authority_widening")
    reject(lambda m, t, g, r: t["tasks"][0].update(execution_dependencies=["ACI-NOT-A-TASK"]), "task_unknown_dependency")
    reject(lambda m, t, g, r: t["tasks"][0].update(execution_dependencies=[t["tasks"][0]["id"]]), "task_cycle")
    reject(lambda m, t, g, r: t["tasks"][0].update(requirement_ids=["ACI-999"]), "task_unknown_requirement")
    reject(lambda m, t, g, r: g["gates"][0].update(task_ids=[]), "gate_task_membership_mismatch")
    reject(lambda m, t, g, r: m.update(task_count=1), "task_count_mismatch")
    return {"cases": 9, "status": "PASS_SOURCE_ONLY", "authorizing": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    names = ("manifest.json", "task-registry.json", "acceptance-gates.json", "requirements.json")
    raw = [get_json(args.root, name) for name in names]
    bundle = tuple(item[0] for item in raw)
    output = validate(*bundle)
    output["source_shas"] = {name: pair[1] for name, pair in zip(names, raw)}
    if args.self_test:
        output["negative_tests"] = self_test(bundle)
    print(json.dumps(output, sort_keys=True, separators=(",", ":")))


if __name__ == "__main__":
    try:
        main()
    except (SpecIntegrityError, OSError, ValueError, KeyError, TypeError) as exc:
        print(json.dumps({"status": "FAIL", "code": str(exc),
                          "authorizing": False}, sort_keys=True), file=sys.stderr)
        raise SystemExit(1)
