#!/usr/bin/env python3
"""Peer route classification guard: narrow known UI routes only."""
from pathlib import Path
source=(Path(__file__).resolve().parents[1]/"includes"/"class-mad4b-scp-mcp-peer-governance.php").read_text()
assert "self::is_reviewed_non_transport_route( $route, $route_definition )" in source
assert "get_class( $cb[0] ) !== $expected" in source
assert "permissionCallback" in source
for name in ("fluent-boards", "fluent-crm", "fluent-support"):
    assert f"/{name}/v2/" in source
assert "if ( ! self::looks_like_mcp_route( $route ) ) continue;" in source
print("PASS: exact reviewed configuration routes; unknown MCP routes still reported")
