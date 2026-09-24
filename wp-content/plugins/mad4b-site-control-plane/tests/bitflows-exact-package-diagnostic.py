#!/usr/bin/env python3
"""Inspect an exact Bit Flows package without authorizing execution.\n\nDefault mode verifies the repository-certified artifact strictly. Candidate mode\naccepts a different exact archive for evidence-only semantic analysis; it never\nchanges certification, grants, activation state, or write authority.\n"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[4]
PLUGIN_ROOT = ROOT / "wp-content/plugins/mad4b-site-control-plane"
ARCHIVE = ROOT / "wp-content/plugins/bit-pi.zip"
CATALOG = PLUGIN_ROOT / "config/certified-providers.json"
CONTRACT = "mad4b.bitflows-exact-package-diagnostic.v1"

MAX_FILES = 20000
MAX_TOTAL_UNCOMPRESSED_BYTES = 512 * 1024 * 1024
MAX_SINGLE_FILE_BYTES = 64 * 1024 * 1024

TARGETS = (
    "backend/app/src/Flow/FlowExecutor.php",
    "backend/app/Model/FlowHistory.php",
    "backend/app/Model/Flow.php",
    "backend/app/Model/FlowNode.php",
    "backend/app/HTTP/Controllers/FlowController.php",
    "backend/app/HTTP/Controllers/WebhookDispatchController.php",
    "backend/app/Services/FlowHistoryService.php",
    "backend/app/Services/LogService.php",
    "backend/app/Model/FlowLog.php",
)


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def unique_suffix(names: list[str], suffix: str) -> str:
    matches = [name for name in names if name == suffix or name.endswith("/" + suffix)]
    if len(matches) != 1:
        raise RuntimeError(f"archive path resolution failed for {suffix}: matches={len(matches)}")
    return matches[0]


def decode_php(raw: bytes, path: str) -> str:
    try:
        return raw.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise RuntimeError(f"non-UTF8 PHP source: {path}") from exc


def detect_plugin_version(archive: zipfile.ZipFile, names: list[str]) -> str:
    try:
        resolved = unique_suffix(names, "bit-pi.php")
    except RuntimeError:
        return ""
    try:
        source = decode_php(archive.read(resolved), resolved)
    except RuntimeError:
        return ""
    header = re.search(r"^[ \t*#/@]*Version:\s*([^\r\n]+)", source, re.I | re.M)
    if header:
        return header.group(1).strip()
    constant = re.search(r"define\s*\(\s*['\"]BIT_?PI_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]", source, re.I)
    return constant.group(1).strip() if constant else ""


def methods(source: str) -> list[str]:
    return sorted(set(re.findall(r"\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", source)))


def function_signature(source: str, method: str) -> str:
    match = re.search(
        r"\bfunction\s+" + re.escape(method) + r"\s*\((.*?)\)\s*(?::\s*([^\{]+))?\{",
        source,
        re.S,
    )
    if not match:
        return ""
    args = re.sub(r"\s+", " ", match.group(1)).strip()
    return_type = re.sub(r"\s+", " ", match.group(2) or "").strip()
    return f"{method}({args})" + (f": {return_type}" if return_type else "")


def function_body(source: str, method: str) -> str:
    match = re.search(r"\bfunction\s+" + re.escape(method) + r"\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{", source, re.S)
    if not match:
        return ""
    start = match.end() - 1
    depth = 0
    quote = ""
    escaped = False
    line_comment = False
    block_comment = False
    i = start
    while i < len(source):
        ch = source[i]
        nxt = source[i + 1] if i + 1 < len(source) else ""
        if line_comment:
            if ch == "\n":
                line_comment = False
            i += 1
            continue
        if block_comment:
            if ch == "*" and nxt == "/":
                block_comment = False
                i += 2
                continue
            i += 1
            continue
        if quote:
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == quote:
                quote = ""
            i += 1
            continue
        if ch in ("'", '"'):
            quote = ch
            i += 1
            continue
        if ch == "/" and nxt == "/":
            line_comment = True
            i += 2
            continue
        if ch == "/" and nxt == "*":
            block_comment = True
            i += 2
            continue
        if ch == "#":
            line_comment = True
            i += 1
            continue
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return source[start + 1 : i]
        i += 1
    raise RuntimeError(f"unbalanced method body: {method}")


def call_expressions(body: str, callee: str, limit: int = 12) -> list[str]:
    rows: list[str] = []
    start_at = 0
    needle = callee + "("
    while len(rows) < limit:
        pos = body.find(needle, start_at)
        if pos < 0:
            break
        open_pos = pos + len(callee)
        depth = 0
        quote = ""
        escaped = False
        i = open_pos
        while i < len(body):
            ch = body[i]
            if quote:
                if escaped:
                    escaped = False
                elif ch == "\\":
                    escaped = True
                elif ch == quote:
                    quote = ""
                i += 1
                continue
            if ch in ("'", '"'):
                quote = ch
                i += 1
                continue
            if ch == "(":
                depth += 1
            elif ch == ")":
                depth -= 1
                if depth == 0:
                    expr = re.sub(r"\s+", " ", body[pos : i + 1]).strip()
                    rows.append(expr[:1200])
                    start_at = i + 1
                    break
            i += 1
        else:
            break
    return rows


def bounded_statements(body: str, pattern: str, limit: int = 24) -> list[str]:
    rows: list[str] = []
    for raw in body.splitlines():
        compact = re.sub(r"\s+", " ", raw).strip()
        if not compact or not re.search(pattern, compact, re.I):
            continue
        if len(compact) > 220:
            compact = compact[:217] + "..."
        rows.append(compact)
        if len(rows) >= limit:
            break
    return rows


def return_expressions(body: str) -> list[str]:
    rows: list[str] = []
    for expr in re.findall(r"\breturn\s+(.{0,240}?);", body, re.S):
        compact = re.sub(r"\s+", " ", expr).strip()
        if len(compact) > 180:
            compact = compact[:177] + "..."
        rows.append(compact)
    return rows


def return_shapes(body: str) -> list[str]:
    rows: list[str] = []
    for expr in re.findall(r"\breturn\s+(.{0,240}?);", body, re.S):
        compact = re.sub(r"\s+", " ", expr).strip()
        if re.fullmatch(r"(?:true|false|null)", compact, re.I):
            shape = compact.lower()
        elif compact.startswith("array(") or compact.startswith("["):
            shape = "array"
        elif compact.startswith("new "):
            shape = "object"
        elif re.fullmatch(r"\$[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]+\])?", compact):
            shape = "variable"
        elif re.fullmatch(r"[0-9]+", compact):
            shape = "integer"
        else:
            shape = "expression"
        rows.append(shape)
    return sorted(set(rows))


def marker_positions(body: str, markers: tuple[str, ...]) -> dict[str, list[int]]:
    positions: dict[str, list[int]] = {}
    for marker in markers:
        found = [m.start() for m in re.finditer(re.escape(marker), body)]
        if found:
            positions[marker] = found
    return positions


def semantic_summary(path: str, source: str) -> dict[str, Any]:
    summary: dict[str, Any] = {
        "path": path,
        "sha256": sha256_bytes(source.encode("utf-8")),
        "methods": methods(source),
        "flow_history_refs": source.count("FlowHistory"),
        "flow_executor_refs": source.count("FlowExecutor"),
        "history_id_refs": len(re.findall(r"history[_A-Za-z]*id|historyId|history_id", source, re.I)),
    }
    if path.endswith("FlowHistoryService.php"):
        service_methods: dict[str, Any] = {}
        for method in ("createHistoryWithTriggerNode", "updateFlowHistoryStatus", "getFlowHistoryStatus"):
            body = function_body(source, method)
            if not body:
                continue
            returns = return_expressions(body)
            service_methods[method] = {
                "signature": function_signature(source, method),
                "log_service_save_calls": call_expressions(body, "LogService::save"),
                "history_insert_calls": call_expressions(body, "FlowHistory::insert"),
                "body_sha256": sha256_bytes(body.encode("utf-8")),
                "return_shapes": return_shapes(body),
                "return_expressions": returns,
                "history_relevant_statements": bounded_statements(
                    body,
                    r"FlowHistory|history[_A-Za-z]*id|historyId|history_id|status|insert|create|save|update",
                    limit=40,
                ),
                "returns_direct_history_id": any(
                    re.fullmatch(r"\$[A-Za-z_][A-Za-z0-9_]*(?:History|history)[A-Za-z0-9_]*Id", expr)
                    or re.fullmatch(r"\$flowHistory->id", expr)
                    for expr in returns
                ),
            }
        summary["service_methods"] = service_methods

    if path.endswith("FlowExecutor.php"):
        body = function_body(source, "execute")
        if not body:
            raise RuntimeError("FlowExecutor::execute body not found")
        summary["execute"] = {
            "body_sha256": sha256_bytes(body.encode("utf-8")),
            "return_shapes": return_shapes(body),
            "return_expressions": return_expressions(body),
            "history_relevant_statements": bounded_statements(
                body,
                r"FlowHistory|history[_A-Za-z]*id|historyId|history_id|parent_history_id|LogService|triggerData",
                limit=48,
            ),
            "create_history_calls": call_expressions(body, "FlowHistoryService::createHistoryWithTriggerNode"),
            "log_service_save_calls": call_expressions(body, "LogService::save"),
            "flow_history_refs": body.count("FlowHistory"),
            "history_id_refs": len(re.findall(r"history[_A-Za-z]*id|historyId|history_id", body, re.I)),
            "provider_execution_ref_markers": len(re.findall(r"execution[_A-Za-z]*id|executionId|execution_id|run[_A-Za-z]*id|runId|run_id", body, re.I)),
            "marker_positions": marker_positions(
                body,
                (
                    "FlowHistory",
                    "FlowHistory::",
                    "new FlowHistory",
                    "->insert(",
                    "::insert(",
                    "->create(",
                    "::create(",
                    "->save(",
                    "->update(",
                    "::execute(",
                    "return ",
                ),
            ),
            "returns_execution_identity_candidate": any(
                re.fullmatch(r"\$flowHistoryId", expr)
                or bool(re.search(r"['\"]flow_history_id['\"]\s*=>\s*\$flowHistoryId", expr))
                for expr in return_expressions(body)
            ),
            "returns_flow_history_status_result": any(
                "FlowHistoryService::updateFlowHistoryStatus($flowHistoryId)" in expr
                for expr in return_expressions(body)
            ),
            "signature": function_signature(source, "execute"),
        }
    if path.endswith("LogService.php"):
        save_body = function_body(source, "save")
        if save_body:
            summary["save"] = {
                "signature": function_signature(source, "save"),
                "return_expressions": return_expressions(save_body),
                "flow_log_calls": call_expressions(save_body, "FlowLog::insert") + call_expressions(save_body, "FlowLog::update"),
                "payload_relevant_statements": bounded_statements(
                    save_body,
                    r"data|payload|input|output|flow_history_id|node_id|status|json|serialize",
                    limit=60,
                ),
            }
    if path.endswith("FlowLog.php"):
        summary["source_markers"] = {
            "trigger_data_refs": len(re.findall(r"trigger[_A-Za-z]*data|triggerData", source, re.I)),
            "payload_refs": len(re.findall(r"payload|data|input|output|variables", source, re.I)),
        }

    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--archive", type=Path, default=ARCHIVE)
    parser.add_argument("--mode", choices=("certified", "candidate"), default="certified")
    parser.add_argument("--candidate-version", default="")
    parser.add_argument("--include-manifest", action="store_true")
    args = parser.parse_args()

    archive_path = args.archive.resolve()
    if not archive_path.is_file():
        raise SystemExit(f"Bit Flows archive not found: {archive_path}")

    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    providers = catalog.get("providers", catalog)
    provider = providers.get("bit_pi")
    if not isinstance(provider, dict):
        raise SystemExit("bit_pi certification entry missing")

    expected_archive = str(provider.get("archive_sha256", "")).lower()
    actual_archive = sha256_file(archive_path)
    archive_matches_catalog = actual_archive == expected_archive
    if args.mode == "certified" and not archive_matches_catalog:
        raise SystemExit(f"Bit Flows archive SHA mismatch: {actual_archive}")

    critical = provider.get("critical_files")
    if not isinstance(critical, dict) or not critical:
        raise SystemExit("Bit Flows critical_files certification is missing")

    evidence: dict[str, Any] = {
        "contract": CONTRACT,
        "diagnostic_mode": args.mode,
        "authorizing": False,
        "mutation_performed": False,
        "provider_id": "bit_pi",
        "provider_version": "",
        "catalog_version": str(provider.get("version", "")),
        "archive": archive_path.name,
        "archive_sha256": actual_archive,
        "catalog_archive_sha256": expected_archive,
        "archive_sha256_matches_catalog": archive_matches_catalog,
        "candidate_attestation_eligible": False,
        "critical_files_verified": 0,
        "critical_files_missing": [],
        "critical_file_mismatches": [],
        "semantic_targets_missing": [],
        "semantic_sources": {},
        "executor_call_sites": [],
        "history_write_sites": [],
        "critical_file_observed_sha256": {},
        "package_file_count": 0,
        "package_manifest_digest": "",
        "package_manifest": [],
        "native_mcp": {
            "catalog_role": str(provider.get("native_mcp_role", "")),
            "catalog_security": provider.get("native_mcp_security", {}) if isinstance(provider.get("native_mcp_security"), dict) else {},
            "server_marker_files": [],
            "server_reference_files": [],
            "route_marker_files": [],
            "client_marker_files": [],
            "mcp_named_paths": [],
            "server_detection_method": "bounded_static_implementation_markers_v2",
            "server_surface_absence_proven": False,
            "no_privileged_side_channel_proven": False,
            "security_recertification_required": args.mode == "candidate",
        },
    }

    with zipfile.ZipFile(archive_path, "r") as archive:
        names = archive.namelist()
        files = sorted(name for name in names if name and not name.endswith("/"))
        if len(files) > MAX_FILES:
            raise SystemExit(f"Bit Flows archive exceeds bounded file-count limit: {len(files)}")

        unsafe_paths = []
        symlink_paths = []
        for info in archive.infolist():
            if info.is_dir():
                continue
            normalized = info.filename.replace("\\", "/")
            parts = [part for part in normalized.split("/") if part not in ("", ".")]
            if normalized.startswith("/") or any(part == ".." for part in parts):
                unsafe_paths.append(info.filename)
            unix_mode = (info.external_attr >> 16) & 0o170000
            if unix_mode == 0o120000:
                symlink_paths.append(info.filename)
            if info.file_size > MAX_SINGLE_FILE_BYTES:
                raise SystemExit(f"Bit Flows archive member exceeds bounded size limit: {info.filename}")

        if unsafe_paths or symlink_paths:
            if args.mode == "certified":
                raise SystemExit("Bit Flows certified archive contains unsafe or symlink paths")

        total_uncompressed = sum(info.file_size for info in archive.infolist() if not info.is_dir())
        if total_uncompressed > MAX_TOTAL_UNCOMPRESSED_BYTES:
            raise SystemExit(f"Bit Flows archive exceeds bounded uncompressed-size limit: {total_uncompressed}")

        top_levels = {name.split("/", 1)[0] for name in files if "/" in name}
        strip_root = len(top_levels) == 1 and all("/" in name for name in files)
        manifest = []
        logical_seen = set()
        duplicate_logical_paths = []
        for name in files:
            raw = archive.read(name)
            logical = name.split("/", 1)[1] if strip_root else name
            logical = logical.replace("\\", "/")
            if logical in logical_seen:
                duplicate_logical_paths.append(logical)
            logical_seen.add(logical)
            manifest.append({"path": logical, "size": len(raw), "sha256": sha256_bytes(raw)})

        if duplicate_logical_paths and args.mode == "certified":
            raise SystemExit("Bit Flows certified archive contains duplicate logical paths")

        manifest = sorted(manifest, key=lambda row: row["path"])
        manifest_json = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("utf-8")
        evidence["package_file_count"] = len(manifest)
        evidence["package_total_uncompressed_bytes"] = total_uncompressed
        evidence["package_manifest_digest"] = sha256_bytes(manifest_json)
        evidence["archive_structure"] = {
            "safe": not unsafe_paths and not symlink_paths and not duplicate_logical_paths,
            "unsafe_paths": sorted(set(unsafe_paths)),
            "symlink_paths": sorted(set(symlink_paths)),
            "duplicate_logical_paths": sorted(set(duplicate_logical_paths)),
            "bounded_file_count": len(files) <= MAX_FILES,
            "bounded_total_uncompressed_bytes": total_uncompressed <= MAX_TOTAL_UNCOMPRESSED_BYTES,
        }
        if args.include_manifest:
            evidence["package_manifest"] = manifest

        detected_version = detect_plugin_version(archive, names)
        requested_version = str(args.candidate_version or "").strip()
        catalog_version = str(provider.get("version", "")).strip()
        evidence["provider_version"] = detected_version or (catalog_version if archive_matches_catalog else "")
        evidence["detected_provider_version"] = detected_version
        evidence["requested_candidate_version"] = requested_version
        evidence["candidate_version_claim_match"] = (
            not requested_version or (bool(detected_version) and requested_version == detected_version)
        )
        if args.mode == "certified" and detected_version and catalog_version and detected_version != catalog_version:
            raise SystemExit(
                f"Bit Flows certified package version header mismatch: detected={detected_version} catalog={catalog_version}"
            )

        for logical, expected in critical.items():
            try:
                resolved = unique_suffix(names, logical)
            except RuntimeError:
                evidence["critical_files_missing"].append(logical)
                if args.mode == "certified":
                    raise SystemExit(f"Bit Flows certified critical file missing: {logical}")
                continue
            actual = sha256_bytes(archive.read(resolved))
            evidence["critical_file_observed_sha256"][logical] = actual
            if actual != str(expected).lower():
                evidence["critical_file_mismatches"].append(
                    {"path": logical, "expected_sha256": str(expected).lower(), "actual_sha256": actual}
                )
            else:
                evidence["critical_files_verified"] += 1

        if args.mode == "certified" and evidence["critical_file_mismatches"]:
            raise SystemExit("Bit Flows critical file certification mismatch")

        for logical in TARGETS:
            try:
                resolved = unique_suffix(names, logical)
            except RuntimeError:
                evidence["semantic_targets_missing"].append(logical)
                if args.mode == "certified":
                    raise
                continue
            source = decode_php(archive.read(resolved), logical)
            evidence["semantic_sources"][logical] = semantic_summary(logical, source)

        for name in names:
            if not name.endswith(".php"):
                continue
            try:
                source = decode_php(archive.read(name), name)
            except RuntimeError:
                continue
            if "FlowExecutor" in source and "::execute(" in source:
                evidence["executor_call_sites"].append(
                    {
                        "path": name,
                        "flow_executor_refs": source.count("FlowExecutor"),
                        "execute_call_refs": source.count("::execute("),
                    }
                )
            if "FlowHistory" in source and re.search(r"(?:FlowHistory|flowHistory|flow_history).{0,160}(?:insert|create|save|update)", source, re.I | re.S):
                evidence["history_write_sites"].append(
                    {"path": name, "flow_history_refs": source.count("FlowHistory")}
                )
            lower_name = name.lower()
            if "/mcp/" in lower_name or lower_name.endswith("/mcp.php") or "mcp" in Path(name).name.lower():
                evidence["native_mcp"]["mcp_named_paths"].append(name)

            # Descriptive references such as "MCP server URL" are common in a
            # client implementation and are not evidence that this package
            # exposes a native MCP server. Preserve them for review only.
            if re.search(r"Model Context Protocol|mcp[_ -]?server", source, re.I):
                evidence["native_mcp"]["server_reference_files"].append(name)

            first_party = "/vendor/" not in lower_name
            server_path_marker = first_party and bool(
                re.search(r"(?:^|/)mcp/(?:[^/]+/)*(?:mcp)?server[^/]*\.php$", lower_name)
                or re.search(r"(?:^|/)mcpserver[^/]*\.php$", lower_name)
            )
            server_code_marker = first_party and bool(
                re.search(r"\bclass\s+[A-Za-z_][A-Za-z0-9_]*McpServer[A-Za-z0-9_]*\b", source, re.I)
                or re.search(r"\bnamespace\s+[^;]*\\Mcp\\Server(?:\\|\s*;)", source, re.I)
                or re.search(r"\bnew\s+(?:\\?[A-Za-z_][A-Za-z0-9_]*\\)*[A-Za-z_][A-Za-z0-9_]*McpServer[A-Za-z0-9_]*\s*\(", source, re.I)
            )
            if server_path_marker or server_code_marker:
                evidence["native_mcp"]["server_marker_files"].append(name)

            route_marker = bool(
                re.search(r"register_rest_route\s*\([^;]{0,1200}['\"][^'\"]*mcp", source, re.I | re.S)
                or re.search(r"add_action\s*\(\s*['\"]wp_ajax_(?:nopriv_)?[^'\"]*mcp", source, re.I)
                or re.search(r"(?:Route|Router)::(?:get|post|put|patch|delete|any)\s*\(\s*['\"][^'\"]*mcp", source, re.I)
                or re.search(r"add_rewrite_rule\s*\([^;]{0,800}['\"][^'\"]*mcp", source, re.I | re.S)
            )
            if route_marker:
                evidence["native_mcp"]["route_marker_files"].append(name)

            if re.search(r"\bMcpClient\b|mcp[_ -]?client", source, re.I):
                evidence["native_mcp"]["client_marker_files"].append(name)

    evidence["executor_call_sites"] = sorted(evidence["executor_call_sites"], key=lambda row: row["path"])
    evidence["history_write_sites"] = sorted(evidence["history_write_sites"], key=lambda row: row["path"])

    for key in ("server_marker_files", "server_reference_files", "route_marker_files", "client_marker_files", "mcp_named_paths"):
        evidence["native_mcp"][key] = sorted(set(evidence["native_mcp"][key]))

    evidence["native_mcp"]["server_surface_detected"] = bool(
        evidence["native_mcp"]["server_marker_files"] or evidence["native_mcp"]["route_marker_files"]
    )
    evidence["native_mcp"]["server_rest_routes_detected"] = bool(evidence["native_mcp"]["route_marker_files"])
    evidence["native_mcp"]["client_surface_detected"] = bool(evidence["native_mcp"]["client_marker_files"])
    evidence["native_mcp"]["server_surface_absence_proven"] = False
    evidence["native_mcp"]["no_privileged_side_channel_proven"] = False

    catalog_role = evidence["native_mcp"]["catalog_role"]
    catalog_security = evidence["native_mcp"]["catalog_security"]
    expected_server_routes = catalog_security.get("server_rest_routes_detected")
    evidence["native_mcp"]["catalog_role_match"] = (
        True
        if not catalog_role
        else (
            evidence["native_mcp"]["client_surface_detected"] and not evidence["native_mcp"]["server_surface_detected"]
            if catalog_role == "client"
            else evidence["native_mcp"]["server_surface_detected"]
            if catalog_role == "server"
            else False
        )
    )
    evidence["native_mcp"]["catalog_server_route_expectation_match"] = (
        True
        if not isinstance(expected_server_routes, bool)
        else bool(expected_server_routes) == evidence["native_mcp"]["server_rest_routes_detected"]
    )

    if args.mode == "certified" and archive_matches_catalog:
        if not evidence["native_mcp"]["catalog_role_match"]:
            raise SystemExit("Bit Flows certified native MCP role no longer matches catalog")
        if not evidence["native_mcp"]["catalog_server_route_expectation_match"]:
            raise SystemExit("Bit Flows certified native MCP server-route expectation no longer matches catalog")

    evidence["native_mcp"]["security_recertification_required"] = bool(
        args.mode == "candidate"
        and (
            not archive_matches_catalog
            or evidence["native_mcp"]["server_surface_detected"]
            or not evidence["native_mcp"]["catalog_role_match"]
            or not evidence["native_mcp"]["catalog_server_route_expectation_match"]
        )
    )

    executor_summary = evidence["semantic_sources"].get("backend/app/src/Flow/FlowExecutor.php", {})
    executor = executor_summary.get("execute", {}) if isinstance(executor_summary, dict) else {}
    history_summary = evidence["semantic_sources"].get("backend/app/Services/FlowHistoryService.php", {})
    history_service = history_summary.get("service_methods", {}) if isinstance(history_summary, dict) else {}
    create_history = history_service.get("createHistoryWithTriggerNode", {})
    update_history = history_service.get("updateFlowHistoryStatus", {})
    correlation_complete = bool(executor) and bool(history_service)
    evidence["execution_correlation"] = {
        "analysis_complete": correlation_complete,
        "execute_returns_identity_candidate": bool(executor.get("returns_execution_identity_candidate")),
        "execute_returns_flow_history_status_result": bool(executor.get("returns_flow_history_status_result")),
        "execute_signature": executor.get("signature", ""),
        "create_history_signature": create_history.get("signature", ""),
        "create_history_return_expressions": create_history.get("return_expressions", []),
        "create_history_returns_direct_history_id": bool(create_history.get("returns_direct_history_id")),
        "update_history_signature": update_history.get("signature", ""),
        "update_history_return_expressions": update_history.get("return_expressions", []),
        "update_history_returns_direct_history_id": bool(update_history.get("returns_direct_history_id")),
        "execute_return_shapes": executor.get("return_shapes", []),
        "execute_return_expressions": executor.get("return_expressions", []),
        "execute_history_relevant_statements": executor.get("history_relevant_statements", []),
        "execute_history_id_refs": executor.get("history_id_refs", 0),
        "execute_flow_history_refs": executor.get("flow_history_refs", 0),
        "provider_execution_ref_markers": executor.get("provider_execution_ref_markers", 0),
        "safe_retry_proven": False,
        "readback_correlation_proven": False,
        "certification_state": "DIAGNOSTIC_ONLY",
    }
    evidence["candidate_summary"] = {
        "exact_archive_observed": True,
        "catalog_identity_match": archive_matches_catalog,
        "critical_baseline_match": not evidence["critical_files_missing"] and not evidence["critical_file_mismatches"],
        "semantic_analysis_complete": correlation_complete and not evidence["semantic_targets_missing"],
        "requires_explicit_certification_update": args.mode == "candidate" and not archive_matches_catalog,
        "normalized_package_identity_present": bool(evidence["package_manifest_digest"]),
        "archive_structure_safe": bool(evidence.get("archive_structure", {}).get("safe")),
        "candidate_version_claim_match": bool(evidence.get("candidate_version_claim_match")),
        "native_mcp_security_review_required": bool(evidence["native_mcp"]["security_recertification_required"]),
        "native_mcp_catalog_role_match": bool(evidence["native_mcp"]["catalog_role_match"]),
        "native_mcp_server_route_expectation_match": bool(evidence["native_mcp"]["catalog_server_route_expectation_match"]),
        "no_privileged_mcp_side_channel_proven": False,
        "write_authority_granted": False,
        "normal_mount_eligible": False,
    }

    encoded = json.dumps(evidence, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.write_text(encoded, encoding="utf-8")
    print(encoded, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
