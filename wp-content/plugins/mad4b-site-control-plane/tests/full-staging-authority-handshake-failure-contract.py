#!/usr/bin/env python3
"""Fail-closed contract for generation-fenced handshake read failures."""
from pathlib import Path
source = (Path(__file__).resolve().parents[1] / "includes" / "class-mad4b-scp-full-staging-authority.php").read_text()
start = source.index("public static function handshake()")
end = source.index("\n\tprivate static function", start)
handshake = source[start:end]
required = (
    "$result = MAD4B_SCP_Connector_Resilience::generation_fenced_compact_read(",
    "'mad4b_generation_fenced_read_failed' === $result->get_error_code()",
    "'state' => 'read_blocked'",
    "'ready_to_apply' => false",
    "'authority_ready' => false",
    "'operational_ready' => false",
    "'authorizing' => false",
    "'mutation_performed' => false",
    "'production_allowed' => false",
    "'source_error_code'",
    "'client_action' => 'inspect_source_error_then_request_fresh_handshake'",
    "return $result;",
)
missing = [piece for piece in required if piece not in handshake]
assert not missing, f"Handshake diagnostic contract missing: {missing}"
assert "'confirmation' => self::CONFIRMATION" in handshake  # normal successful path unchanged
print("FULL_STAGING_HANDSHAKE_FAILURE_CONTRACT: PASS")
