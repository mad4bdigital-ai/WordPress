#!/usr/bin/env python3
"""Verify the exact MCP Adapter release transport/session compatibility matrix.

Usage:
    python3 mcp-adapter-protocol-matrix.py /tmp/mcp-adapter.zip
"""

from __future__ import annotations

import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path


def fail(message: str) -> None:
    raise SystemExit(f"FAIL mcp-adapter-protocol-matrix: {message}")


if len(sys.argv) not in (2, 3):
    fail("expected certified MCP Adapter archive path and optional exact version")

archive_path = Path(sys.argv[1])
requested_version = sys.argv[2] if len(sys.argv) == 3 else None
if not archive_path.is_file():
    fail(f"archive not found: {archive_path}")

profiles_path = Path(__file__).resolve().parents[1] / "config/certified-provider-profiles.json"
profiles = json.loads(profiles_path.read_text(encoding="utf-8"))
catalog = (profiles.get("providers") or {}).get("mcp_adapter") or {}
if not isinstance(catalog, dict):
    fail("certified MCP Adapter profile catalog unavailable")

archive_bytes = archive_path.read_bytes()
archive_sha256 = hashlib.sha256(archive_bytes).hexdigest()

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
    if len(version_values) != 1:
        fail(f"missing or ambiguous upstream plugin version header: {version_values}")
    version = version_values[0]
    if requested_version is not None and requested_version != version:
        fail(f"requested certified version differs from source header: {requested_version} != {version}")
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        fail(f"unreviewed upstream MCP Adapter version syntax: {version}")
    profile = catalog.get(version) or {}
    if (profile.get("version") != version
        or profile.get("archive_sha256") != archive_sha256
        or profile.get("archive_bytes") != len(archive_bytes)):
        fail("MCP Adapter archive does not match the exact certified release profile")
    compatibility = profile.get("transport_compatibility") or {}
    if (compatibility.get("legacy_session_revisions") != ["2024-11-05", "2025-06-18", "2025-11-25"]
        or compatibility.get("modern_per_request_revisions") != ["2026-07-28"]
        or compatibility.get("permission_callback_wp_error_passthrough") is not False
        or compatibility.get("mad4b_transport_admission_bridge_required") is not True):
        fail("MCP Adapter declared protocol or permission semantics require independent review")

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

    # A reviewed upstream release may deliberately collapse WP_Error from a
    # custom permission callback to false. MAD4B must preserve the bounded
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
    "adapter_version": version,
    "archive_sha256": archive_sha256,
    "legacy_session_revisions": compatibility["legacy_session_revisions"],
    "modern_per_request_revisions": compatibility["modern_per_request_revisions"],
    "legacy_session_required_after_initialize": True,
    "modern_2026_session_required": False,
    "permission_callback_wp_error_passthrough": False,
    "mad4b_pre_permission_admission_bridge_required": True,
    "ready": True,
}
print(json.dumps(evidence, indent=2, sort_keys=True))
