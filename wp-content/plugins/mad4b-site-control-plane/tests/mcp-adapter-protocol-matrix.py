#!/usr/bin/env python3
"""Verify the exact MCP Adapter release transport/session compatibility matrix.

Usage:
    python3 mcp-adapter-protocol-matrix.py /tmp/mcp-adapter.zip
"""

from __future__ import annotations

import json
import re
import sys
import zipfile
from pathlib import Path


def fail(message: str) -> None:
    raise SystemExit(f"FAIL mcp-adapter-protocol-matrix: {message}")


if len(sys.argv) != 2:
    fail("expected exact MCP Adapter archive path")

archive_path = Path(sys.argv[1])
if not archive_path.is_file():
    fail(f"archive not found: {archive_path}")

with zipfile.ZipFile(archive_path) as zf:
    names = zf.namelist()
    mains = [n for n in names if n.endswith("/mcp-adapter.php") or n == "mcp-adapter.php"]
    if len(mains) != 1:
        fail(f"unexpected adapter roots: {mains}")
    prefix = mains[0][:-len("mcp-adapter.php")]

    def source(relative: str) -> str:
        name = prefix + relative
        if name not in names:
            fail(f"missing exact release source: {relative}")
        return zf.read(name).decode("utf-8", errors="strict")

    plugin = source("mcp-adapter.php")
    version_values = []
    for line in plugin.splitlines():
        match = re.match(r"^\s*\*\s*Version:\s*([^\s]+)\s*$", line)
        if match:
            version_values.append(match.group(1))
    if version_values != ["0.7.0"]:
        fail(f"unexpected plugin version header: {version_values}")

    negotiator = source("includes/Core/McpVersionNegotiator.php")
    orchestrator = source("includes/Transport/Infrastructure/McpWireOrchestrator.php")
    handler = source("includes/Transport/Infrastructure/HttpRequestHandler.php")
    transport = source("includes/Transport/HttpTransport.php")
    session = source("includes/Transport/Infrastructure/HttpSessionValidator.php")

    # Exact revision contract: modern 2026 is per-request; legacy identifiers
    # remain negotiable through the 2025 schema.
    for marker in (
        "Schemas::V2026_07_28",
        "Schemas::V2025_11_25",
        "'2025-06-18'",
        "'2024-11-05'",
        "LEGACY_PROTOCOL_VERSIONS",
        "PROTOCOL_VERSION_HEADER_SINCE",
    ):
        if marker not in negotiator:
            fail(f"version negotiator missing marker: {marker}")

    if "return Schemas::V2025_11_25 === $selection && 'initialize' !== $generic['method'];" not in orchestrator:
        fail("legacy session requirement is not exact 2025/non-initialize")
    for marker in (
        "Schemas::V2026_07_28 === $body_revision",
        "Schemas::V2026_07_28 === $header_revision",
        "context_2026_07_28",
        "context_2025_11_25",
    ):
        if marker not in orchestrator:
            fail(f"wire orchestrator missing revision boundary: {marker}")

    # The HTTP handler must consult the wire orchestrator before requiring a
    # session. This prevents applying the legacy session contract to 2026 calls.
    required_order = [
        "$this->orchestrator->requires_2025_11_25_http_session",
        "HttpSessionValidator::validate_session_with_error_handler",
        "$this->orchestrator->process",
    ]
    positions = [handler.find(marker) for marker in required_order]
    if any(pos < 0 for pos in positions) or positions != sorted(positions):
        fail(f"HTTP session/revision ordering drift: {positions}")

    if "McpWireOrchestrator::negotiated_protocol_version" not in handler:
        fail("HTTP handler no longer binds protocol header to negotiated legacy session")
    if "McpVersionNegotiator::requires_protocol_version_header" not in handler:
        fail("legacy protocol-header policy missing")

    # Upstream 0.7.0 still deliberately collapses WP_Error from a custom
    # transport permission callback to false. MAD4B must preserve the bounded
    # error before this seam; if upstream behavior changes we require review.
    permission_block = re.search(
        r"public function check_permission\s*\([^)]*\)\s*\{(?P<body>.*?)\n\t\}",
        transport,
        flags=re.S,
    )
    if not permission_block:
        fail("unable to locate HttpTransport::check_permission")
    body = permission_block.group("body")
    if "is_wp_error( $result )" not in body or "return false;" not in body:
        fail("upstream permission WP_Error behavior changed; review MAD4B admission bridge")

    for marker in (
        "Missing Mcp-Session-Id header",
        "User not authenticated",
        "Invalid or expired session",
    ):
        if marker not in session:
            fail(f"legacy session fail-closed invariant missing: {marker}")

evidence = {
    "contract": "mad4b.mcp-adapter-protocol-matrix.v1",
    "adapter_version": "0.7.0",
    "legacy_session_revisions": ["2024-11-05", "2025-06-18", "2025-11-25"],
    "modern_per_request_revisions": ["2026-07-28"],
    "legacy_session_required_after_initialize": True,
    "modern_2026_session_required": False,
    "permission_callback_wp_error_passthrough": False,
    "mad4b_pre_permission_admission_bridge_required": True,
    "ready": True,
}
print(json.dumps(evidence, indent=2, sort_keys=True))
