#!/usr/bin/env python3
"""Regression for first-party session preservation without reimplementing OAuth.

The separate Local OAuth Standalone / OAuth Bridge CI jobs must still execute
the real WordPress HTTP fixture. This inexpensive readiness test prevents
reversal of the exact sequencing and denial policy.
"""
from pathlib import Path
import re

root=Path(__file__).resolve().parents[1]
bridge=(root/'includes/class-mad4b-scp-oauth-resource-bridge.php').read_text()
fixture=(root/'tests/runtime-gateway-cookie-http.php').read_text()

fn=bridge.split('private static function authenticate_rest_request_impl',1)[1]
fn=fn.split('private static function ',1)[0]
required=[
    "$authorization = method_exists( $request, 'get_header' )",
    "$nonce = method_exists( $request, 'get_header' )",
    "'' === $authorization && '' !== $nonce",
    "is_user_logged_in() && current_user_can( 'manage_options' )",
    "wp_verify_nonce( $nonce, 'wp_rest' )",
    "self::reset_verified_bearer_context( false );",
    "MAD4B_SCP_Passwordless_Connection::verify_mcp( $request )",
    "self::reset_verified_bearer_context( true );",
    "self::extract_bearer_token( $authorization )",
]
for mark in required:
    assert mark in fn, f'Missing fail-closed native cookie/bearer contract: {mark}'
assert fn.index("'' === $authorization && '' !== $nonce") < fn.index("MAD4B_SCP_Passwordless_Connection::verify_mcp( $request )")
assert fn.index("MAD4B_SCP_Passwordless_Connection::verify_mcp( $request )") < fn.index("self::extract_bearer_token( $authorization )")
native=fn.split("if ( '' === $authorization && '' !== $nonce",1)[1].split("if ( class_exists( 'MAD4B_SCP_Passwordless_Connection' ) )",1)[0]
assert "return $result;" in native and "reset_verified_bearer_context( false )" in native
assert "reset_verified_bearer_context( true )" not in native
for mark in ("'X-WP-Nonce' => $nonce","$without_nonce = $send( $headers )",
             "'Bearer invalid'", "'invalid-not-a-wordpress-rest-nonce'",
             "wp_remote_retrieve_response_code( $bad_nonce ) < 400",
             "wp_remote_retrieve_response_code( $bad_bearer ) < 400"):
    assert mark in fixture, f'Real WordPress HTTP negative test missing: {mark}'
assert "wp_generate_auth_cookie" in fixture and "WP_Session_Tokens::get_instance" in fixture
assert re.search(r"finally\s*\{[\s\S]*?\$manager->destroy\(\s*\$token\s*\)",fixture)
print('PASS: OAUTH_NATIVE_COOKIE_SEQUENCING_AND_NEGATIVE_HTTP_FIXTURE')
