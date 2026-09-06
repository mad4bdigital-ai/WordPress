#!/usr/bin/env python3
"""Inspect native MCP implementations in certified provider ZIPs for auth markers.

The script never executes provider PHP. It extracts only the packaged archives
into a temporary directory and reports route/permission evidence from files
whose paths belong to MCP implementations. This is an evidence collector, not
a PHP semantic analyzer.
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

MAX_HITS = 40


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
    for path in files:
        text = path.read_text("utf-8", errors="ignore")
        lines = text.splitlines()
        has_route = False
        for marker_name, regex in MARKERS.items():
            for match in regex.finditer(text):
                line_number = text.count("\n", 0, match.start()) + 1
                line_text = lines[line_number - 1] if 0 < line_number <= len(lines) else ""
                markers[marker_name].append(
                    {
                        "file": path.relative_to(root).as_posix(),
                        "line": line_number,
                        "text": clean_line(line_text),
                    }
                )
                if marker_name == "register_rest_route":
                    has_route = True
                if len(markers[marker_name]) >= MAX_HITS:
                    break
        if has_route:
            route_files.append(path.relative_to(root).as_posix())

    summary = {name: len(hits) for name, hits in markers.items()}
    return {
        "provider": provider,
        "archive": archive.name,
        "present": True,
        "mcp_php_files": [path.relative_to(root).as_posix() for path in files],
        "route_files": route_files,
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
        "contract": "mad4b.site-control-plane.packaged-mcp-security-evidence.v1",
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
