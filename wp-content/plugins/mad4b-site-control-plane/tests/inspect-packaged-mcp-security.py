#!/usr/bin/env python3
"""Inspect and enforce native MCP security invariants in certified provider ZIPs.

The script never executes provider PHP. It extracts only the packaged archives
into a temporary directory, records REST route declarations and security-
relevant method bodies, and fails when the certified native-MCP permission
chain drifts from the reviewed baseline.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import tempfile
import zipfile
from pathlib import Path

TARGETS = {
    "jetengine": "jet-engine.zip",
    "elementor": "elementor.zip",
    "bit_pi": "bit-pi.zip",
}

MARKERS = {
    "register_rest_route": re.compile(r"register_rest_route\s*\(", re.I),
    "permission_callback": re.compile(r"permission_callback", re.I),
    "current_user_can": re.compile(r"current_user_can\s*\(", re.I),
    "manage_options": re.compile(r"manage_options", re.I),
    "edit_posts": re.compile(r"edit_posts", re.I),
    "edit_post": re.compile(r"edit_post", re.I),
    "is_user_logged_in": re.compile(r"is_user_logged_in\s*\(", re.I),
    "wp_verify_nonce": re.compile(r"wp_verify_nonce\s*\(", re.I),
    "check_ajax_referer": re.compile(r"check_ajax_referer\s*\(", re.I),
    "rest_cookie_check_errors": re.compile(r"rest_cookie_check_errors", re.I),
    "return_true": re.compile(r"__return_true|return\s+true\s*;", re.I),
}

SECURITY_BODY_MARKERS = re.compile(
    r"permission_callback|current_user_can|manage_options|edit_posts|edit_post|"
    r"wp_verify_nonce|check_ajax_referer|rest_cookie_check_errors|__return_true",
    re.I,
)
JETENGINE_TRACE_FUNCTIONS = {
    "get_items_permissions_check",
    "permissions_check",
    "verify_nonce",
    "run_item_permissions_check",
    "run_item",
    "handle_request",
    "check_permissions",
}
FUNCTION_START = re.compile(
    r"(?P<visibility>public|protected|private)?\s*(?:static\s+)?function\s+"
    r"(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{",
    re.I | re.S,
)
MAX_HITS = 40
MAX_FUNCTIONS = 100
MAX_FUNCTION_LINES = 120
ROUTE_CONTEXT_LINES = 16

JETENGINE_EXPECTED_ROUTE_FILES = {
    "jet-engine/includes/core/mcp-tools/rest-api/get-controller.php",
    "jet-engine/includes/core/mcp-tools/rest-api/mcp-controller.php",
    "jet-engine/includes/core/mcp-tools/rest-api/run-controller.php",
}


def safe_extract(archive: Path, destination: Path) -> None:
    with zipfile.ZipFile(archive) as zf:
        for member in zf.infolist():
            normalized = member.filename.replace("\\", "/")
            if normalized.startswith("/") or ".." in Path(normalized).parts:
                raise RuntimeError(f"unsafe ZIP entry: {member.filename}")
        zf.extractall(destination)


def is_mcp_path(path: Path, root: Path) -> bool:
    rel = "/" + path.relative_to(root).as_posix().lower()
    return (
        "/mcp/" in rel
        or "/mcp-tools/" in rel
        or "/mcp_tools/" in rel
        or "/modules/mcp" in rel
        or rel.endswith("/mcp.php")
    )


def clean_line(value: str) -> str:
    value = re.sub(r"\s+", " ", value.strip())
    return value[:320]


def line_number(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def context_excerpt(lines: list[str], center_line: int, radius: int = ROUTE_CONTEXT_LINES) -> list[dict]:
    start = max(1, center_line - radius)
    end = min(len(lines), center_line + radius)
    return [
        {"line": number, "text": clean_line(lines[number - 1])}
        for number in range(start, end + 1)
    ]


def function_body_end(text: str, opening_brace: int) -> int | None:
    depth = 0
    quote = None
    escaped = False
    i = opening_brace
    while i < len(text):
        char = text[i]
        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            i += 1
            continue
        if char in ("'", '"'):
            quote = char
            i += 1
            continue
        if text.startswith("//", i):
            newline = text.find("\n", i + 2)
            i = len(text) if newline < 0 else newline + 1
            continue
        if text.startswith("#", i):
            newline = text.find("\n", i + 1)
            i = len(text) if newline < 0 else newline + 1
            continue
        if text.startswith("/*", i):
            close = text.find("*/", i + 2)
            i = len(text) if close < 0 else close + 2
            continue
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return i + 1
        i += 1
    return None


def security_functions(provider: str, text: str, relative_file: str) -> list[dict]:
    results = []
    for match in FUNCTION_START.finditer(text):
        opening_brace = text.find("{", match.start(), match.end())
        if opening_brace < 0:
            continue
        end = function_body_end(text, opening_brace)
        if end is None:
            continue
        body = text[match.start():end]
        function_name = match.group("name")
        explicitly_traced = provider == "jetengine" and function_name in JETENGINE_TRACE_FUNCTIONS
        if not explicitly_traced and not SECURITY_BODY_MARKERS.search(body):
            continue
        start_line = line_number(text, match.start())
        all_body_lines = body.splitlines()
        body_lines = all_body_lines[:MAX_FUNCTION_LINES]
        results.append(
            {
                "file": relative_file,
                "function": function_name,
                "visibility": (match.group("visibility") or "").lower(),
                "start_line": start_line,
                "explicit_execution_trace": explicitly_traced,
                "truncated": len(all_body_lines) > MAX_FUNCTION_LINES,
                "body": [
                    {"line": start_line + offset, "text": clean_line(value)}
                    for offset, value in enumerate(body_lines)
                ],
            }
        )
        if len(results) >= MAX_FUNCTIONS:
            break
    return results


def scan_archive(provider: str, archive: Path, temp_root: Path) -> dict:
    if not archive.is_file():
        return {"provider": provider, "archive": archive.name, "present": False}

    root = temp_root / provider
    root.mkdir(parents=True, exist_ok=True)
    safe_extract(archive, root)

    files = [
        path for path in root.rglob("*.php")
        if path.is_file() and is_mcp_path(path, root)
    ]
    files.sort()

    markers = {name: [] for name in MARKERS}
    route_files = []
    route_declarations = []
    relevant_functions = []
    for path in files:
        text = path.read_text("utf-8", errors="ignore")
        lines = text.splitlines()
        relative_file = path.relative_to(root).as_posix()
        has_route = False
        for marker_name, regex in MARKERS.items():
            for match in regex.finditer(text):
                line_no = line_number(text, match.start())
                line_text = lines[line_no - 1] if 0 < line_no <= len(lines) else ""
                markers[marker_name].append(
                    {
                        "file": relative_file,
                        "line": line_no,
                        "text": clean_line(line_text),
                    }
                )
                if marker_name == "register_rest_route":
                    has_route = True
                    route_declarations.append(
                        {
                            "file": relative_file,
                            "line": line_no,
                            "context": context_excerpt(lines, line_no),
                        }
                    )
                if len(markers[marker_name]) >= MAX_HITS:
                    break
        if has_route:
            route_files.append(relative_file)
        relevant_functions.extend(security_functions(provider, text, relative_file))
        if len(relevant_functions) >= MAX_FUNCTIONS:
            relevant_functions = relevant_functions[:MAX_FUNCTIONS]

    summary = {name: len(hits) for name, hits in markers.items()}
    return {
        "provider": provider,
        "archive": archive.name,
        "present": True,
        "mcp_php_files": [path.relative_to(root).as_posix() for path in files],
        "route_files": sorted(set(route_files)),
        "route_declarations": route_declarations,
        "security_functions": relevant_functions,
        "marker_counts": summary,
        "markers": markers,
    }


def function_text(provider: dict, file_suffix: str, function_name: str) -> str:
    for item in provider.get("security_functions") or []:
        if item.get("function") != function_name:
            continue
        if not str(item.get("file") or "").endswith(file_suffix):
            continue
        return "\n".join(str(line.get("text") or "") for line in (item.get("body") or []))
    return ""


def require_contains(issues: list[dict], provider: str, contract: str, text: str, needles: list[str]) -> None:
    missing = [needle for needle in needles if needle.lower() not in text.lower()]
    if missing:
        issues.append(
            {
                "provider": provider,
                "contract": contract,
                "reason": "missing_security_evidence",
                "missing": missing,
            }
        )


def enforce_security(report: dict) -> list[dict]:
    issues = []
    providers = report.get("providers") or {}

    jetengine = providers.get("jetengine") or {}
    if not jetengine.get("present"):
        issues.append({"provider": "jetengine", "contract": "package", "reason": "missing"})
    else:
        actual_routes = set(jetengine.get("route_files") or [])
        if actual_routes != JETENGINE_EXPECTED_ROUTE_FILES:
            issues.append(
                {
                    "provider": "jetengine",
                    "contract": "native_rest_routes",
                    "reason": "route_set_drift",
                    "expected": sorted(JETENGINE_EXPECTED_ROUTE_FILES),
                    "actual": sorted(actual_routes),
                }
            )

        require_contains(
            issues,
            "jetengine",
            "mcp_tools_list_admin_gate",
            function_text(jetengine, "mcp-tools/rest-api/get-controller.php", "get_items_permissions_check"),
            ["current_user_can", "manage_options"],
        )
        require_contains(
            issues,
            "jetengine",
            "mcp_json_rpc_admin_gate",
            function_text(jetengine, "mcp-tools/rest-api/mcp-controller.php", "permissions_check"),
            ["current_user_can", "manage_options"],
        )
        require_contains(
            issues,
            "jetengine",
            "feature_run_nonce_helper",
            function_text(jetengine, "mcp-tools/rest-api/run-controller.php", "verify_nonce"),
            ["wp_verify_nonce", "wp_rest"],
        )
        require_contains(
            issues,
            "jetengine",
            "feature_run_route_gate",
            function_text(jetengine, "mcp-tools/rest-api/run-controller.php", "run_item_permissions_check"),
            ["verify_nonce", "Registry", "get_feature", "check_permissions"],
        )
        require_contains(
            issues,
            "jetengine",
            "feature_default_admin_permission",
            function_text(jetengine, "mcp-tools/feature.php", "check_permissions"),
            ["current_user_can", "manage_options"],
        )
        require_contains(
            issues,
            "jetengine",
            "feature_execution_chain",
            function_text(jetengine, "mcp-tools/rest-api/run-controller.php", "run_item"),
            ["Registry", "get_feature", "->run"],
        )

    elementor = providers.get("elementor") or {}
    if not elementor.get("present"):
        issues.append({"provider": "elementor", "contract": "package", "reason": "missing"})
    else:
        if elementor.get("route_files"):
            issues.append(
                {
                    "provider": "elementor",
                    "contract": "abilities_only_transport",
                    "reason": "unexpected_native_rest_route",
                    "actual": elementor.get("route_files"),
                }
            )
        counts = elementor.get("marker_counts") or {}
        if int(counts.get("current_user_can") or 0) < 1 or int(counts.get("edit_posts") or 0) < 1:
            issues.append(
                {
                    "provider": "elementor",
                    "contract": "ability_baseline_permission",
                    "reason": "missing_edit_posts_gate",
                }
            )
        if int(counts.get("edit_post") or 0) < 1:
            issues.append(
                {
                    "provider": "elementor",
                    "contract": "post_specific_permission",
                    "reason": "missing_edit_post_gate",
                }
            )

    bit_pi = providers.get("bit_pi") or {}
    if not bit_pi.get("present"):
        issues.append({"provider": "bit_pi", "contract": "package", "reason": "missing"})
    elif bit_pi.get("route_files") or int((bit_pi.get("marker_counts") or {}).get("register_rest_route") or 0) != 0:
        issues.append(
            {
                "provider": "bit_pi",
                "contract": "mcp_client_only",
                "reason": "unexpected_native_mcp_server_route",
                "actual": bit_pi.get("route_files") or [],
            }
        )

    return issues


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plugins-dir", default="wp-content/plugins")
    parser.add_argument("--output", default="/tmp/mad4b-packaged-mcp-security.json")
    args = parser.parse_args()

    plugins_dir = Path(args.plugins_dir).resolve()
    report = {
        "contract": "mad4b.site-control-plane.packaged-mcp-security.v4",
        "mode": "static_enforced_evidence",
        "providers": {},
    }

    with tempfile.TemporaryDirectory(prefix="mad4b-mcp-security-") as tmp:
        temp_root = Path(tmp)
        for provider, archive_name in TARGETS.items():
            report["providers"][provider] = scan_archive(provider, plugins_dir / archive_name, temp_root)

    issues = enforce_security(report)
    report["security_status"] = "passed" if not issues else "failed"
    report["security_issues"] = issues

    output = Path(args.output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if not issues else 1


if __name__ == "__main__":
    sys.exit(main())
