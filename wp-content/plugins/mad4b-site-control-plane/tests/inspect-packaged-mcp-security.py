#!/usr/bin/env python3
"""Inspect native MCP implementations in certified provider ZIPs for auth evidence.

The script never executes provider PHP. It extracts only the packaged archives
into a temporary directory and reports REST route declarations, permission
callbacks and the bodies of security-relevant PHP methods from MCP paths. This
is static evidence collection, not a complete PHP semantic analyzer.
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
        "route_files": route_files,
        "route_declarations": route_declarations,
        "security_functions": relevant_functions,
        "marker_counts": summary,
        "markers": markers,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plugins-dir", default="wp-content/plugins")
    parser.add_argument("--output", default="/tmp/mad4b-packaged-mcp-security.json")
    args = parser.parse_args()

    plugins_dir = Path(args.plugins_dir).resolve()
    report = {
        "contract": "mad4b.site-control-plane.packaged-mcp-security-evidence.v3",
        "mode": "static_evidence_only",
        "providers": {},
    }

    with tempfile.TemporaryDirectory(prefix="mad4b-mcp-security-") as tmp:
        temp_root = Path(tmp)
        for provider, archive_name in TARGETS.items():
            report["providers"][provider] = scan_archive(provider, plugins_dir / archive_name, temp_root)

    output = Path(args.output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
