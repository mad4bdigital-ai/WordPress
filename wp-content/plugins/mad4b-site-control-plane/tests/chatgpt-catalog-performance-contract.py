#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
servers = (root / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
plugin = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime_build = (root / "MAD4B-RUNTIME-BUILD.txt").read_text(encoding="utf-8")
readme = (root / "README.md").read_text(encoding="utf-8")

required_cache_properties = [
    "private static $registered_adapter_write_candidates_cache = null;",
    "private static $external_write_tools_cache = null;",
    "private static $chatgpt_tools_cache = null;",
    "private static $provider_for_ability_cache = array();",
]
for marker in required_cache_properties:
    assert marker in servers, marker

required_fast_paths = [
    "if ( $cacheable && is_array( self::$registered_adapter_write_candidates_cache ) ) return self::$registered_adapter_write_candidates_cache;",
    "if ( $cacheable && is_array( self::$external_write_tools_cache ) ) return self::$external_write_tools_cache;",
    "if ( $cacheable && is_array( self::$chatgpt_tools_cache ) ) return self::$chatgpt_tools_cache;",
    "if ( $cacheable && ! $dynamic_write_resolution && array_key_exists( $cache_key, self::$provider_for_ability_cache ) ) return self::$provider_for_ability_cache[ $cache_key ];",
]
for marker in required_fast_paths:
    assert marker in servers, marker

required_population = [
    "if ( $cacheable ) self::$registered_adapter_write_candidates_cache = $result;",
    "if ( $cacheable ) self::$external_write_tools_cache = $result;",
    "if ( $cacheable ) self::$chatgpt_tools_cache = $tools;",
    "if ( $cacheable ) self::$chatgpt_tools_cache = $tools;",
    "self::$provider_for_ability_cache[ $cache_key ] = $value;",
]
for marker in required_population:
    assert marker in servers, marker

# This hotfix deliberately avoids cross-request caches because catalog authority
# must be recomputed on the next request after profile/grant/provider changes.
for forbidden in [
    "get_transient(",
    "set_transient(",
    "delete_transient(",
    "get_option( 'mad4b_scp_chatgpt_catalog",
    "update_option( 'mad4b_scp_chatgpt_catalog",
]:
    assert forbidden not in servers, f"persistent catalog cache forbidden: {forbidden}"

# Provider resolution must be memoized because the MCP adapter can ask membership
# questions repeatedly while serializing a large tools/list response.
assert '$cache_key = $server_id . "\\0" . $ability_name;' in servers
assert "$dynamic_write_resolution = 'mad4b-write' === $server_id || self::is_external_write_candidate( $ability_name );" in servers
assert "if ( $cacheable && ! $dynamic_write_resolution && array_key_exists( $cache_key, self::$provider_for_ability_cache ) )" in servers
assert "$remember = static function ( $value ) use ( $cache_key, $cacheable, $dynamic_write_resolution )" in servers

# Catalog memoization is legal only after registration lifecycle has settled.
for marker in [
    "private static function catalog_cacheable()",
    "did_action( 'wp_abilities_api_init' ) > 0",
    "did_action( 'rest_api_init' ) > 0",
    "! doing_action( 'wp_abilities_api_init' )",
    "! doing_action( 'rest_api_init' )",
]:
    assert marker in servers, marker

# Runtime write eligibility must stay live within the same PHP request because
# provider certification/isolation can converge after initial discovery.
assert "private static $write_tools_cache" not in servers
write_body = servers.split("public static function write_tools()", 1)[1].split("public static function external_write_tools()", 1)[0]
assert "self::$write_tools_cache" not in write_body
assert "adapter_write_projection()" in write_body

# Preserve the security model while optimizing discovery.
assert "'mad4b/database-raw-query' === $ability_name" in servers
assert "MAD4B_SCP_Developer_Authority::enrollment_tools()" in servers
assert "array_diff( $enrollment_candidates, MAD4B_SCP_Developer_Authority::enrollment_tools() )" in servers
assert "mad4b-developer-breakglass" in servers

assert "0.4.0-rc.57" in plugin
assert "release=0.4.0-rc.57" in runtime_build
assert "request-locally memoized in rc.56" in readme

print("mad4b.chatgpt-catalog-performance.v4: PASS")
