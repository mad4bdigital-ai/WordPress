#!/usr/bin/env python3
"""Structural checks: no new authority; one common read-only canonical predicate."""
from pathlib import Path
source=(Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-mcp-runtime-conflict-guard.php').read_text()
assert source.count('private static function canonical_runtime_observed(')==1
bootstrap=source.split('public static function bootstrap(',1)[1].split('public static function status(',1)[0]
inspect=source.split('private static function inspect_status(',1)[1].split('private static function canonical_runtime_observed(',1)[0]
helper=source.split('private static function canonical_runtime_observed(',1)[1].split('private static function official_plugin_identity(',1)[0]
assert 'self::canonical_runtime_observed( $status )' in bootstrap
assert 'self::canonical_runtime_observed( $status )' in inspect
for forbidden in ('update_option(', 'delete_option(', 'add_option(', 'activate_plugin(',
                  'deactivate_plugins(', 'ensure_mu_bootstrap(', 'MAD4B_SCP_Audit::record('):
 assert forbidden not in inspect, forbidden
 assert forbidden not in helper, forbidden
for phrase in ("'runtime_from_official_plugin'", "'runtime_class_provenance_ready'",
               "'runtime_class_provenance_failure_count'", "'mu_bootstrap_canonical_symbols_pinned'",
               "'mu_bootstrap_critical_class_set_pinned'"):
 assert phrase in helper,phrase
print('MCP_RUNTIME_INSPECT_CANONICAL_READONLY_CONTRACT_PASS')
