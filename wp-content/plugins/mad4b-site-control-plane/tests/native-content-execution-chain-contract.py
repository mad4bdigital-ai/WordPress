#!/usr/bin/env python3
"""Fail-closed source contract for the exact native content draft execution lane."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]/"includes"
auth=(root/"class-mad4b-scp-authorization.php").read_text()
adapter=(root/"adapters"/"class-mad4b-scp-adapter-base.php").read_text()
peer=(root/"class-mad4b-scp-mcp-peer-governance.php").read_text()
native=auth.split("// A local enrolled Staging operator is authorized",1)[1].split("$provider = MAD4B_SCP_Authorization::provider_for_execution",1)[0]
assert "'mad4b/content-apply-bundle' === $name" in native
assert "MAD4B_SCP_Policy::can_native_content_draft( $input )" in native
assert "MAD4B_SCP_MCP_Peer_Governance::mutation_guard()" in native
assert "MAD4B_SCP_Restore_Epoch::advance( 'local_native_draft' )" in native
assert "MAD4B_SCP_Audit::record(" in native
assert "return call_user_func( $original, $input )" in native
lane=adapter.split("// Exact enrolled Staging WP-CLI draft uses its own journal",1)[1].split("if ( ! class_exists( 'MAD4B_SCP_Reversible_Adapter_Mutations' ) )",1)[0]
for item in ("'mad4b/content-apply-bundle' === $name","'dynamic-content' === $this->id()","'apply_bundle' === $method","MAD4B_SCP_Policy::can_native_content_draft( $input )"):
    assert item in lane, item
assert "MAD4B_SCP_Reversible_Adapter_Mutations::execute" in adapter
assert "self::is_reviewed_non_transport_route( $route, $route_definition )" in peer
assert "get_class( $cb[0] ) !== $expected" in peer
assert "if ( ! self::looks_like_mcp_route( $route ) ) continue;" in peer
print("PASS: native CLI draft confined; external NHI and unknown MCP remain governed")
