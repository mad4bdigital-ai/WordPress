#!/usr/bin/env python3
"""Validate inert competitor evidence and the optional, non-authorizing backlog."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import stat
import sys
import zipfile
from collections import Counter
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parents[3]
TASK = re.compile(r"^- \[([ x])\] (T(?:39|40)\d{2}) (P[012]) (.+)$")
PHASE = re.compile(r"^## Phase (\d+)\b")
BOUNDARIES = {
    "no_production_authority", "no_breakglass_widening", "no_generic_outbound_http",
    "no_direct_content_mutation_from_signal", "no_vendor_or_business_hardcode",
    "no_documentation_only_completion", "archive_reference_only_no_install_or_execute",
    "marketing_is_not_runtime_evidence", "no_automatic_grants_or_tool_mounts",
    "capability_local_certification", "history_is_not_rollback",
    "new_scopes_require_external_consent", "no_generic_full_access_consent",
    "no_classifier_authority", "no_generated_php_execution", "no_arbitrary_symbol_invocation",
    "preserve_human_owned_changes", "signed_data_pack_non_authorizing",
    "canary_requires_existing_staging_authority",
    "no_cross_site_authority_inference", "automation_kill_switch_required",
    "signatures_do_not_equal_authority", "schema_migration_no_risk_downgrade",
    "fuzzing_disposable_only", "restore_never_replays_authority",
}
EXPECTED_PACKAGES = {
    "ai-engine": ("3.7.6", "ff14536aca8688ddf4631a87e1d33b9fdae983feab0b7c05f2d0cb524aa44ac0"),
    "royal-mcp": ("1.5.0", "ccb8528552f804849726307fe6de1e28bada0404322f79ea5bfcb41edd3b8b70"),
    "miniorange-secure-mcp-server": ("1.4.10", "baf0349514e92b59a57ee88bd5b3fd7095a6a5eec4de91a217f01cd6fee0e838"),
    "easy-mcp-ai": ("1.7.17", "0cf4ab2d7d3e6de0d297ad59df68bdd60722de50e36bc8d792b9a585399be0bb"),
}
EXPECTED_REPORTS = {
    "source-comparison-report.ar.md": "48a28f94e97ec1547d26e208d106e6991d6e39973a5fb9bb76b6f40977915114",
    "source-adaptive-operations-proposal.ar.md": "ce1ebc723b90481fbdb7b01d51335a68cf72a6d989177f703a38b03573447f69",
}


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def local_file(root: Path, relative: str) -> Path:
    path = PurePosixPath(relative)
    if not relative or path.is_absolute() or ".." in path.parts or "\\" in relative:
        raise ValueError("unsafe_reference_path:" + relative)
    full = root / relative
    if full.is_symlink() or not full.is_file() or not full.resolve().is_relative_to(root.resolve()):
        raise ValueError("missing_or_unsafe_file:" + relative)
    return full


def inspect_archive(path: Path, expected_root: str) -> tuple[dict, dict]:
    """Read members in memory; never extract, import, include or execute vendor code."""
    raw = path.read_bytes()
    if len(raw) > 16 * 1024 * 1024:
        raise ValueError("archive_size_limit")
    files = {}
    entries = []
    total = 0
    seen = set()
    with zipfile.ZipFile(path) as archive:
        if len(archive.infolist()) > 3000:
            raise ValueError("archive_entry_limit")
        for info in archive.infolist():
            name = info.filename
            p = PurePosixPath(name)
            if (p.is_absolute() or ".." in p.parts or "\\" in name or ":" in name
                    or not p.parts or p.parts[0] != expected_root or "\x00" in name
                    or name != p.as_posix() + ("/" if info.is_dir() else "")):
                raise ValueError("unsafe_archive_path:" + name)
            folded = name.rstrip("/").casefold()
            if folded in seen:
                raise ValueError("duplicate_archive_path:" + name)
            seen.add(folded)
            if stat.S_ISLNK(info.external_attr >> 16) or info.flag_bits & 1:
                raise ValueError("symlink_or_encrypted_archive_entry:" + name)
            mode = stat.S_IFMT(info.external_attr >> 16)
            if mode not in (0, stat.S_IFREG, stat.S_IFDIR):
                raise ValueError("special_archive_entry:" + name)
            total += info.file_size
            if (info.file_size > 12 * 1024 * 1024 or total > 64 * 1024 * 1024
                    or info.file_size > max(1, info.compress_size) * 200):
                raise ValueError("archive_expansion_limit:" + name)
            if info.is_dir():
                continue
            content = archive.read(info)  # ZipFile checks the CRC.
            if len(content) != info.file_size:
                raise ValueError("archive_member_size_mismatch:" + name)
            files[name] = content
            entries.append({"path": name, "bytes": len(content), "sha256": digest(content)})
    return {"bytes": len(raw), "sha256": digest(raw), "file_count": len(entries),
            "uncompressed_bytes": total, "entries": sorted(entries, key=lambda x: x["path"])}, files


def load(root: Path, relative: str) -> dict:
    return json.loads(local_file(root, relative).read_text(encoding="utf-8"))


def build_ledger(root: Path = ROOT) -> dict:
    tasks_raw = local_file(root, "tasks.md").read_bytes()
    status_raw = local_file(root, "task-status.json").read_bytes()
    overrides = json.loads(status_raw).get("overrides", {})
    rows, seen, phase = [], set(), None
    for line in tasks_raw.decode("utf-8").splitlines():
        match = PHASE.match(line)
        if match:
            phase = int(match[1])
        match = TASK.match(line)
        if not match:
            continue
        checked, task_id, priority, summary = match.groups()
        if task_id in seen:
            raise ValueError("duplicate_task_id:" + task_id)
        seen.add(task_id)
        override = overrides.get(task_id, {})
        state = override.get("status", "DONE" if checked == "x" else "OPEN")
        refs = override.get("evidence_refs", [])
        if state not in {"OPEN", "PARTIAL", "DONE", "DEFERRED"} or (checked == "x") != (state == "DONE"):
            raise ValueError("task_status_checkbox_mismatch:" + task_id)
        if state != "OPEN" and (not refs or not override.get("reason")):
            raise ValueError("task_status_missing_evidence:" + task_id)
        rows.append({"task_id": task_id, "phase": phase, "priority": priority,
                     "summary": summary, "status": state, "evidence_refs": refs,
                     "reason": override.get("reason", "")})
    if set(overrides) - seen:
        raise ValueError("unknown_task_override")
    return {"contract": "mad4b.competitive-experience-task-ledger.v1",
            "source_tasks_sha256": digest(tasks_raw), "status_source_sha256": digest(status_raw),
            "authorizing": False, "release_closure_included": False, "task_count": len(rows),
            "counts": {s: sum(r["status"] == s for r in rows) for s in ("OPEN", "PARTIAL", "DONE", "DEFERRED")},
            "tasks": rows}


def validate(root: Path = ROOT, repo: Path = REPO) -> dict:
    feature = load(root, "extension.json")
    for name in ("README.md", "spec.md", "plan.md", "traceability.md", "contracts/runtime.md",
                 "contracts/acceptance.md", "comparison.md", "source-comparison-report.ar.md",
                 "adaptive-operations.md", "contracts/adaptive-operations-runtime.md", "source-adaptive-operations-proposal.ar.md",
                 "architecture-expansion.md"):
        local_file(root, name)
    if (feature.get("contract") != "mad4b.competitive-experience-extension.v1"
            or feature.get("authorizing") is not False or feature.get("production_authorized") is not False
            or feature.get("release_closure_included") is not False
            or feature.get("status") not in {"SPEC_BACKLOG_ONLY", "UI_IMPLEMENTATION_IN_PROGRESS", "IMPLEMENTATION_IN_PROGRESS"}):
        raise ValueError("extension_must_be_optional_spec_backlog_non_authorizing")
    if (feature.get("required_phase_count"), feature.get("task_count"), feature.get("capability_count")) != (35, 175, 59):
        raise ValueError("extension_inventory_metadata_mismatch")
    if not BOUNDARIES <= set(feature.get("hard_boundaries", [])):
        raise ValueError("extension_hard_boundary_missing")
    manifest = load(root, "artifact-manifest.json")
    if (manifest.get("contract") != "mad4b.competitor-artifact-manifest.v1"
            or manifest.get("reference_only") is not True or manifest.get("runtime_execution_performed") is not False
            or manifest.get("archive_installation_performed") is not False
            or manifest.get("distribution_package_inclusion_allowed") is not False):
        raise ValueError("archives_must_be_inert_references")
    packages = manifest.get("packages", [])
    if Counter(p["id"] for p in packages) != Counter(EXPECTED_PACKAGES.keys()):
        raise ValueError("exact_supplied_archive_set_required")
    members = {}
    for package in packages:
        package_id = package["id"]
        version, sha = EXPECTED_PACKAGES[package_id]
        path = package["path"]
        if path != f"artifacts/{package_id}-{version}.zip" or package.get("version") != version:
            raise ValueError("archive_identity_path_mismatch:" + package_id)
        inventory, content = inspect_archive(local_file(root, path), package_id)
        if inventory["sha256"] != sha:
            raise ValueError("original_archive_bytes_changed:" + package_id)
        if any(package.get(k) != v for k, v in inventory.items()):
            raise ValueError("archive_inventory_drift:" + package_id)
        header = content[package["entry_point"]].decode("utf-8")
        for field, expected in (("Version", version), ("License", package["license"])):
            match = re.search(r"^\s*\*?\s*" + field + r":\s*(.+)$", header, re.MULTILINE)
            if not match or match[1].strip() != expected:
                raise ValueError("archive_header_identity_mismatch:" + package_id + ":" + field)
        members[package_id] = content
    reports = [manifest["source_report"], *manifest.get("additional_source_reports", [])]
    report_paths = {r["path"] for r in reports}
    if report_paths != {"source-comparison-report.ar.md", "source-adaptive-operations-proposal.ar.md"} or len(reports) != 2:
        raise ValueError("exact_two_user_source_reports_required")
    for report in reports:
        raw = local_file(root, report["path"]).read_bytes()
        if (digest(raw) != report["sha256"] or digest(raw) != EXPECTED_REPORTS[report["path"]]
                or len(raw) != report["bytes"]):
            raise ValueError("source_report_bytes_changed")
    sources = load(root, "source-index.json")["evidence"]
    source_ids = set()
    for source in sources:
        if source["id"] in source_ids or source["kind"] not in {"BUNDLED_STATIC", "CLAIMED_UNVERIFIED", "USER_PROPOSAL_UNVERIFIED", "ARCHITECTURE_DERIVATION"}:
            raise ValueError("source_id_or_evidence_class_invalid")
        source_ids.add(source["id"])
        if source["kind"] == "USER_PROPOSAL_UNVERIFIED":
            if source["package_id"] is not None or source["path"] not in report_paths:
                raise ValueError("user_proposal_source_binding_invalid")
            raw = local_file(root, source["path"]).read_bytes()
        elif source["kind"] == "ARCHITECTURE_DERIVATION":
            if source["package_id"] is not None or source["path"] != "architecture-expansion.md":
                raise ValueError("architecture_derivation_source_binding_invalid")
            raw = local_file(root, source["path"]).read_bytes()
        else:
            raw = members[source["package_id"]][source["path"]]
        lines = raw.splitlines(keepends=True)
        start, end = source["line_start"], source["line_end"]
        if not 1 <= start <= end <= len(lines) or digest(b"".join(lines[start - 1:end])) != source["range_sha256"]:
            raise ValueError("source_line_range_digest_mismatch:" + source["id"])
        if source["kind"] == "CLAIMED_UNVERIFIED" and not source["path"].endswith("readme.txt"):
            raise ValueError("marketing_claim_source_mismatch")
        if source.get("runtime_verified") is not False:
            raise ValueError("static_evidence_cannot_claim_runtime_verification")
    ledger = build_ledger(root)
    if load(root, "task-ledger.generated.json") != ledger:
        raise ValueError("generated_task_ledger_drift")
    expected_tasks = {f"T39{i:02}" for i in range(1, 81)} | {f"T40{i:02}" for i in range(1, 96)}
    if {r["task_id"] for r in ledger["tasks"]} != expected_tasks or any(r["status"] not in {"OPEN", "PARTIAL"} for r in ledger["tasks"]):
        raise ValueError("optional_backlog_cannot_claim_runtime_completion")
    partial = {r["task_id"] for r in ledger["tasks"] if r["status"] == "PARTIAL"}
    ui_partial_ids = {"T3906", "T3907", "T3908", "T3909", "T4061"}
    g1_partial_ids = {f"T390{i}" for i in range(1, 6)} | {f"T40{i:02}" for i in range(1, 11)}
    g2_partial_ids = {f"T39{i:02}" for i in range(11, 26)} | {f"T39{i:02}" for i in range(51, 56)}
    g3_partial_ids = {f"T40{i:02}" for i in range(11, 16)} | {f"T40{i:02}" for i in range(21, 36)}
    g4_partial_ids = {f"T39{i:02}" for i in range(31, 46)} | {f"T39{i:02}" for i in range(66, 76)}
    supported_partial_ids = ui_partial_ids | g1_partial_ids | g2_partial_ids | g3_partial_ids | g4_partial_ids
    if partial - supported_partial_ids:
        raise ValueError("implementation_partial_task_owner_invalid:" + ",".join(sorted(partial - supported_partial_ids)))

    ui_partial = partial & ui_partial_ids
    g1_partial = partial & g1_partial_ids
    g2_partial = partial & g2_partial_ids
    g3_partial = partial & g3_partial_ids
    g4_partial = partial & g4_partial_ids
    if partial:
        if feature.get("status") not in {"UI_IMPLEMENTATION_IN_PROGRESS", "IMPLEMENTATION_IN_PROGRESS"}:
            raise ValueError("implementation_progress_requires_implementation_state")

        if ui_partial:
            delivery = load(root, "ui-delivery.json")
            if (delivery.get("contract") != "mad4b.competitive-admin-ui-delivery.v1"
                    or delivery.get("authorizing") is not False or delivery.get("production_authorized") is not False
                    or delivery.get("live_browser_acceptance") is not False or delivery.get("runtime_parity_claimed") is not False
                    or set(delivery.get("partial_task_ids", [])) != ui_partial
                    or not delivery.get("remaining_acceptance")):
                raise ValueError("ui_delivery_boundary_or_progress_invalid")
            for group in ("code_paths", "test_paths"):
                paths = delivery.get(group, [])
                if not paths:
                    raise ValueError("ui_delivery_requires_code_and_tests")
                for entry in paths:
                    path = entry.get("path", "")
                    if not path.startswith("wp-content/plugins/mad4b-site-control-plane/") or ".." in PurePosixPath(path).parts:
                        raise ValueError("ui_delivery_path_outside_plugin")
                    source = repo / path
                    if source.is_symlink() or not source.is_file() or digest(source.read_bytes()) != entry.get("sha256"):
                        raise ValueError("ui_delivery_source_evidence_drift")
            for row in ledger["tasks"]:
                if row["task_id"] in ui_partial and "ui-delivery.json" not in row["evidence_refs"]:
                    raise ValueError("ui_partial_task_missing_delivery_binding:" + row["task_id"])

        if g1_partial:
            delivery = load(root, "g1-delivery.json")
            if (delivery.get("contract") != "mad4b.competitive-g1-delivery.v1"
                    or delivery.get("authorizing") is not False or delivery.get("production_authorized") is not False
                    or delivery.get("runtime_parity_claimed") is not False or delivery.get("live_provider_acceptance") is not False
                    or delivery.get("live_browser_acceptance") is not False
                    or delivery.get("exact_head_binding") != "supplied_by_ci_not_embedded_in_commit"
                    or set(delivery.get("partial_task_ids", [])) != g1_partial
                    or not delivery.get("remaining_acceptance")):
                raise ValueError("g1_delivery_boundary_or_progress_invalid")
            if set(delivery.get("capability_ids", [])) != {"CE001", "CE002", "CE041", "CE042"}:
                raise ValueError("g1_delivery_capability_scope_invalid")
            groups = (
                ("code_paths", "wp-content/plugins/mad4b-site-control-plane/"),
                ("test_paths", "wp-content/plugins/mad4b-site-control-plane/"),
                ("spec_paths", "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/"),
            )
            for group, prefix in groups:
                paths = delivery.get(group, [])
                if not paths:
                    raise ValueError("g1_delivery_requires_code_tests_and_spec")
                for entry in paths:
                    path = entry.get("path", "")
                    if not path.startswith(prefix) or ".." in PurePosixPath(path).parts:
                        raise ValueError("g1_delivery_path_outside_scope:" + group)
                    source = repo / path
                    if source.is_symlink() or not source.is_file() or digest(source.read_bytes()) != entry.get("sha256"):
                        raise ValueError("g1_delivery_source_evidence_drift:" + path)
            for row in ledger["tasks"]:
                if row["task_id"] in g1_partial and "g1-delivery.json" not in row["evidence_refs"]:
                    raise ValueError("g1_partial_task_missing_delivery_binding:" + row["task_id"])

        if g2_partial:
            delivery = load(root, "g2-delivery.json")
            if (delivery.get("contract") != "mad4b.competitive-g2-delivery.v1"
                    or delivery.get("authorizing") is not False or delivery.get("production_authorized") is not False
                    or delivery.get("runtime_parity_claimed") is not False or delivery.get("live_provider_acceptance") is not False
                    or delivery.get("live_browser_acceptance") is not False
                    or delivery.get("exact_head_binding") != "supplied_by_ci_not_embedded_in_commit"
                    or set(delivery.get("partial_task_ids", [])) != g2_partial
                    or not delivery.get("remaining_acceptance")):
                raise ValueError("g2_delivery_boundary_or_progress_invalid")
            if set(delivery.get("capability_ids", [])) != {"CE006", "CE007", "CE008", "CE009", "CE010", "CE011", "CE025", "CE026"}:
                raise ValueError("g2_delivery_capability_scope_invalid")
            groups = (
                ("code_paths", "wp-content/plugins/mad4b-site-control-plane/"),
                ("test_paths", "wp-content/plugins/mad4b-site-control-plane/"),
                ("spec_paths", "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/"),
            )
            for group, prefix in groups:
                paths = delivery.get(group, [])
                if not paths:
                    raise ValueError("g2_delivery_requires_code_tests_and_spec")
                for entry in paths:
                    path = entry.get("path", "")
                    if not path.startswith(prefix) or ".." in PurePosixPath(path).parts:
                        raise ValueError("g2_delivery_path_outside_scope:" + group)
                    source = repo / path
                    if source.is_symlink() or not source.is_file() or digest(source.read_bytes()) != entry.get("sha256"):
                        raise ValueError("g2_delivery_source_evidence_drift:" + path)
            for row in ledger["tasks"]:
                if row["task_id"] in g2_partial and "g2-delivery.json" not in row["evidence_refs"]:
                    raise ValueError("g2_partial_task_missing_delivery_binding:" + row["task_id"])

        if g3_partial:
            delivery = load(root, "g3-delivery.json")
            if (delivery.get("contract") != "mad4b.competitive-g3-delivery.v1"
                    or delivery.get("authorizing") is not False or delivery.get("production_authorized") is not False
                    or delivery.get("runtime_parity_claimed") is not False or delivery.get("live_provider_acceptance") is not False
                    or delivery.get("live_browser_acceptance") is not False
                    or delivery.get("exact_head_binding") != "supplied_by_ci_not_embedded_in_commit"
                    or set(delivery.get("partial_task_ids", [])) != g3_partial
                    or not delivery.get("remaining_acceptance")):
                raise ValueError("g3_delivery_boundary_or_progress_invalid")
            if set(delivery.get("capability_ids", [])) != {"CE043", "CE045", "CE046", "CE047"}:
                raise ValueError("g3_delivery_capability_scope_invalid")
            groups = (
                ("code_paths", "wp-content/plugins/mad4b-site-control-plane/"),
                ("test_paths", "wp-content/plugins/mad4b-site-control-plane/"),
                ("spec_paths", "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/"),
            )
            for group, prefix in groups:
                paths = delivery.get(group, [])
                if not paths:
                    raise ValueError("g3_delivery_requires_code_tests_and_spec")
                for entry in paths:
                    path = entry.get("path", "")
                    if not path.startswith(prefix) or ".." in PurePosixPath(path).parts:
                        raise ValueError("g3_delivery_path_outside_scope:" + group)
                    source = repo / path
                    if source.is_symlink() or not source.is_file() or not source.resolve().is_relative_to(repo.resolve()) or digest(source.read_bytes()) != entry.get("sha256"):
                        raise ValueError("g3_delivery_source_evidence_drift:" + path)
            for row in ledger["tasks"]:
                if row["task_id"] in g3_partial and "g3-delivery.json" not in row["evidence_refs"]:
                    raise ValueError("g3_partial_task_missing_delivery_binding:" + row["task_id"])

        if g4_partial:
            delivery = load(root, "g4-delivery.json")
            if (delivery.get("contract") != "mad4b.competitive-g4-delivery.v1"
                    or delivery.get("authorizing") is not False or delivery.get("production_authorized") is not False
                    or delivery.get("runtime_parity_claimed") is not False or delivery.get("live_provider_acceptance") is not False
                    or delivery.get("live_browser_acceptance") is not False
                    or delivery.get("native_mutation_dispatch_implemented") is not False
                    or delivery.get("status") != "REPOSITORY_G4_FOUNDATION_AND_PREFLIGHT_IMPLEMENTED_PROVIDER_ACCEPTANCE_PENDING"
                    or delivery.get("exact_head_binding") != "supplied_by_ci_not_embedded_in_commit"
                    or delivery.get("provider_family_contract") != "mad4b.g4-provider-family-catalog.v1"
                    or set(delivery.get("partial_task_ids", [])) != g4_partial
                    or not delivery.get("remaining_acceptance")):
                raise ValueError("g4_delivery_boundary_or_progress_invalid")
            if set(delivery.get("capability_ids", [])) != {"CE015", "CE016", "CE017", "CE018", "CE019", "CE020", "CE021", "CE022", "CE032", "CE033", "CE034", "CE035", "CE036", "CE037", "CE038"}:
                raise ValueError("g4_delivery_capability_scope_invalid")
            groups = (
                ("code_paths", "wp-content/plugins/mad4b-site-control-plane/"),
                ("test_paths", "wp-content/plugins/mad4b-site-control-plane/"),
                ("spec_paths", "specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/"),
            )
            for group, prefix in groups:
                paths = delivery.get(group, [])
                if not paths:
                    raise ValueError("g4_delivery_requires_code_tests_and_spec")
                for entry in paths:
                    path = entry.get("path", "")
                    if not path.startswith(prefix) or ".." in PurePosixPath(path).parts:
                        raise ValueError("g4_delivery_path_outside_scope:" + group)
                    source = repo / path
                    if source.is_symlink() or not source.is_file() or not source.resolve().is_relative_to(repo.resolve()) or digest(source.read_bytes()) != entry.get("sha256"):
                        raise ValueError("g4_delivery_source_evidence_drift:" + path)
            for row in ledger["tasks"]:
                if row["task_id"] in g4_partial and "g4-delivery.json" not in row["evidence_refs"]:
                    raise ValueError("g4_partial_task_missing_delivery_binding:" + row["task_id"])
    elif feature.get("status") != "SPEC_BACKLOG_ONLY":
        raise ValueError("implementation_state_without_progress")
    phases = set(range(35))
    for name in ("tasks.md", "plan.md"):
        found = {int(x) for x in re.findall(r"^## Phase (\d+)\b", local_file(root, name).read_text(), re.MULTILINE)}
        if found != phases:
            raise ValueError("extension_phase_parity:" + name)
    matrix = load(root, "capability-matrix.json")
    if (matrix.get("contract") != "mad4b.competitive-capability-matrix.v1" or matrix.get("authorizing") is not False
            or matrix.get("baseline_source_sha") != feature.get("baseline_source_sha")):
        raise ValueError("matrix_identity_or_authority_mismatch")
    streams, capabilities = matrix["workstreams"], matrix["capabilities"]
    stream_ids = {w["id"] for w in streams}
    if len(streams) != 35 or len(stream_ids) != 35:
        raise ValueError("extension_workstream_identity_mismatch")
    if {w["phase"] for w in streams} != phases or len({w["family"] for w in streams}) != 35:
        raise ValueError("extension_workstream_phase_or_family_mismatch")
    mapped = [t for w in streams for t in w["task_ids"]]
    if set(mapped) != expected_tasks or len(mapped) != len(expected_tasks):
        raise ValueError("task_workstream_ownership_mismatch")
    cap_ids = {c["id"] for c in capabilities}
    if cap_ids != {f"CE{i:03}" for i in range(1, 60)} or len(capabilities) != 59:
        raise ValueError("capability_identity_mismatch")
    trace = local_file(root, "traceability.md").read_text()
    for stream in streams:
        if any(r["phase"] != stream["phase"] for r in ledger["tasks"] if r["task_id"] in stream["task_ids"]):
            raise ValueError("task_workstream_phase_mismatch")
        expected_state = "PARTIAL" if partial.intersection(stream["task_ids"]) else "OPEN"
        if f"| {stream['family']} |" not in trace or stream["status"] != expected_state:
            raise ValueError("workstream_traceability_or_status:" + stream["id"])
        if any(dep not in stream_ids for dep in stream["dependencies"]):
            raise ValueError("unknown_workstream_dependency")
        if len(stream["task_ids"]) != 5 or stream["release_blocker"] is not False:
            raise ValueError("extension_must_not_widen_release_blockers")
    visited, pending = set(), set()
    def visit(node):
        if node in pending:
            raise ValueError("workstream_dependency_cycle")
        if node in visited:
            return
        pending.add(node)
        for dep in next(w for w in streams if w["id"] == node)["dependencies"]:
            visit(dep)
        pending.remove(node)
        visited.add(node)
    for node in stream_ids:
        visit(node)
    for cap in capabilities:
        expected_state = "PARTIAL" if partial.intersection(cap["task_ids"]) else "OPEN"
        if cap["workstream_id"] not in stream_ids or cap["implementation_status"] != expected_state or cap["runtime_parity_claimed"] is not False:
            raise ValueError("capability_status_or_owner_invalid")
        owner = next(w for w in streams if w["id"] == cap["workstream_id"])
        if cap["family"] != owner["family"] or cap["risk_class"] not in {"READ_ONLY", "SCOPED_EXTERNAL_READ", "CAPABILITY_LOCAL_MIXED", "HIGH_RISK_EXPLICIT_GATE"}:
            raise ValueError("capability_risk_or_trace_family_mismatch")
        if not set(cap["task_ids"]) <= set(owner["task_ids"]) or not cap["task_ids"]:
            raise ValueError("capability_task_mapping_invalid")
        if not cap["evidence_ids"] or not set(cap["evidence_ids"]) <= source_ids:
            raise ValueError("capability_missing_source_evidence")
        if not cap["acceptance"] or not cap["denial_cases"] or not cap["delta"]:
            raise ValueError("capability_acceptance_incomplete")
        for reference in cap["mad4b_baseline_paths"]:
            local_file(repo, reference)
        if f"| {cap['id']} |" not in trace or f"| {cap['id']} |" not in local_file(root, "comparison.md").read_text():
            raise ValueError("capability_human_matrix_traceability_missing")
    parent = load(root.parents[1], "feature.json")
    freeze = parent["release_closure_scope_freeze"]
    if (parent["required_phase_count"] != 39 or freeze["frozen_task_count"] != 837
            or freeze["max_phase"] != 38 or freeze["new_required_phases_allowed"] is not False):
        raise ValueError("frozen_release_scope_must_not_change")
    ext = parent["optional_spec_extensions"]["competitive_experience"]
    if (ext["path"] != "extensions/competitive-experience" or ext["release_closure_included"] is not False
            or ext.get("contract") != feature["contract"] or ext.get("task_count") != ledger["task_count"]):
        raise ValueError("parent_optional_extension_binding_invalid")
    parent_ledger = load(root.parents[1], "task-ledger.generated.json")
    if parent_ledger["task_count"] != 837 or expected_tasks & {r["task_id"] for r in parent_ledger["tasks"]}:
        raise ValueError("extension_tasks_must_remain_separate_from_frozen_release")
    adaptive = load(root, "adaptive-operations.json")
    adaptive_ids = {f"T40{i:02}" for i in range(1, 96)}
    if (adaptive.get("contract") != "mad4b.adaptive-capability-fabric-v2-spec.v1"
            or adaptive.get("status") != "SPEC_BACKLOG_ONLY" or adaptive.get("authorizing") is not False
            or adaptive.get("production_authorized") is not False or adaptive.get("task_count") != 95
            or set(adaptive.get("task_ids", [])) != adaptive_ids
            or set(adaptive.get("workstream_ids", [])) != {w["id"] for w in streams if w["phase"] >= 16}):
        raise ValueError("adaptive_operations_identity_or_task_binding_invalid")
    forbidden = {"classifier_creates_authority", "confidence_is_safety_proof", "generated_php_execution",
                 "arbitrary_symbol_invocation", "generic_outbound_http", "new_grants_or_mounts_automatic",
                 "host_binary_installation_automatic", "human_edit_overwrite_automatic", "unsafe_read_shadow_allowed",
                 "unknown_external_side_effect_retry", "signed_pack_can_create_authority",
                 "cross_site_authority_inference", "automation_error_budget_bypass", "supply_chain_signature_creates_authority",
                 "schema_migration_can_lower_risk", "fuzzing_live_or_paid_effects", "restore_replays_prior_authority"}
    mandatory = {"canary_requires_existing_staging_authority", "real_rollback_proof_required",
                 "exact_generation_binding_required", "ownership_three_way_cas_required",
                 "data_pack_signature_revocation_anti_replay_required", "promotion_ring_exact_generation_required",
                 "automation_kill_switch_required", "supply_chain_provenance_required", "registry_migration_reversible_required",
                 "fuzzing_disposable_fixture_required", "restore_rebind_current_authority_required"}
    rules = adaptive.get("rules", {})
    if any(rules.get(k) is not False for k in forbidden) or any(rules.get(k) is not True for k in mandatory):
        raise ValueError("adaptive_operations_authority_or_safety_boundary_invalid")
    expected_actions = [["OBSERVE_REGISTERED_METADATA"], ["REFRESH_NON_AUTHORIZING_PROJECTIONS"],
                        ["REPAIR_PROVEN_OWNED_NON_AUTHORIZING_DRIFT"],
                        ["SHADOW_VERIFIED_READ", "RUN_PREAUTHORIZED_DISPOSABLE_CANARY"],
                        ["REFRESH_EXISTING_ELIGIBLE_STAGING_PROJECTION"], []]
    levels = adaptive.get("autonomy_levels", [])
    if len(levels) != 6 or any(level.get("id") != f"L{i}" or level.get("automatic_actions") != expected_actions[i]
                              for i, level in enumerate(levels)):
        raise ValueError("adaptive_autonomy_levels_or_actions_invalid")
    measure = adaptive.get("measurement", {})
    if (measure.get("target_is_aspirational") is not True or measure.get("observed_automation_percent") is not None
            or measure.get("eligible_workload_denominator_required") is not True):
        raise ValueError("adaptive_aspiration_cannot_be_claimed_measurement")
    return {"status": "PASS", "archives": len(packages), "capabilities": len(capabilities),
            "workstreams": len(streams), "extension_tasks": ledger["task_count"],
            "extension_counts": ledger["counts"], "frozen_release_tasks": 837,
            "runtime_parity_claimed": False, "authorizing": False}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--write-ledger", action="store_true")
    args = parser.parse_args()
    try:
        if args.write_ledger:
            (ROOT / "task-ledger.generated.json").write_text(json.dumps(build_ledger(), indent=2) + "\n")
        else:
            print(json.dumps(validate(), sort_keys=True))
    except (ValueError, KeyError, TypeError, OSError, zipfile.BadZipFile) as exc:
        print("COMPETITIVE_EXPERIENCE_SPEC: FAIL: " + str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
