#!/usr/bin/env python3
"""Scope regression for verified legacy/scoped ChatGPT CIMD evidence identity.

Static contract checks are paired with authenticated runtime tests on the
enrolled WordPress installations. No synthetic session or grant is minted.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "includes"

def content(name):
    return (ROOT / ("class-mad4b-scp-" + name + ".php")).read_text()

def contains(where, *markers):
    source = content(where)
    for marker in markers:
        assert marker in source, f"{where}: missing {marker}"

local = content("local-oauth-server")
contains("local-oauth-server",
    "public static function trusted_chatgpt_client_id",
    "public static function trusted_chatgpt_client_ids_match",
    "self::cimd_client_id_allowed( $client_id )",
    "hash_equals( $left, $right )",
    "https://chatgpt.com",
    "client\\.json",
    "chatgpt_expected_redirect_uri",
    "S256",
)
bridge = content("oauth-resource-bridge")
contains("oauth-resource-bridge",
    "public static function verified_bearer_trusted_chatgpt_client_id()",
    "self::verified_bearer_active()",
    "self::verified_bearer_client_is( $id )",
    "unset( $safe_context['client_id'] )",
)
contains("chatgpt-auto-binding",
    "verified_bearer_trusted_chatgpt_client_id()",
    "trusted_chatgpt_client_id( $client_id )",
    "'client_id' => $client_id",
    "hash( 'sha256', $client_id )",
)
contains("external-handshake-evidence",
    "trusted_chatgpt_client_ids_match(",
    "verified_bearer_client_is( $client_id )",
    "trusted_chatgpt_client_id( $recorded_client_id )",
    "'client_id' => $current['client_id']",
    "same_context( $pending, $current )",
    "if ( ! defined( 'REST_REQUEST' )",
)
contains("live-acceptance-observer",
    "verified_bearer_client_is( $client_id )",
    "$attestation['client_id'] = $current['client_id'];",
    "trusted_chatgpt_client_id( isset( $stored['client_id'] )",
)
contains("external-snapshot-finalizer",
    "trusted_chatgpt_client_ids_match(",
    "'client_id' => (string) $external['client_id']",
)
contains("live-acceptance-reconciler",
    "'client_id' => (string) $external['client_id']",
    "verified_bearer_client_is( (string) $external['client_id'] )",
)
for name in (
    "live-acceptance-finalizer",
    "production-unchanged-attestation",
    "production-promotion-attestation",
):
    contains(name, "verified_bearer_client_is( (string) $external['client_id'] )")
contains("reconnect-hardening",
    "verified_bearer_trusted_chatgpt_client_id()",
    "governed_nonproduction_transport()",
)
contains("skill-abilities",
    "trusted_chatgpt_client_ids_match(",
    "'external_client_server_binding_mismatch'",
)
assert "dynamic_client_registration_supported' => false" in local
assert "hash_equals( $left, $right )" in local
assert "$attestation['client_id'] = self::CHATGPT_CLIENT_ID;" not in content("live-acceptance-observer")
assert "'client_id' => self::CHATGPT_CLIENT_ID," not in content("external-snapshot-finalizer")
assert "empty( $external['client_id'] ) || 'https://chatgpt.com/oauth/client.json'" not in content("live-acceptance-finalizer")
assert "empty( $external['client_id'] ) || 'https://chatgpt.com/oauth/client.json'" not in content("production-unchanged-attestation")
print("PASS scoped ChatGPT CIMD evidence contract")
