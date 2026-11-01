#!/usr/bin/env python3
"""Exact-source write catalog review: candidate declaration is not a grant."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=(root/'includes/class-mad4b-scp-server-write-catalog.php').read_text()

def check(s):
    head=s[s.index('private static function core_write_candidates()'):s.index('private static function registered_adapter_write_candidates()')]
    assert "'mad4b/hub-source-sync-request'" in head
    assert "'mad4b/provider-connection-refresh'" in head
    start=s.index('public static function live_write_provider_snapshot()')
    body=s[start:s.index('\n\t}',start)]
    for token in ('self::write_tools()', 'self::core_write_candidates()', 'self::registered_adapter_write_candidates()',
      "isset( $core[ $ability ] )", "isset( $adapters[ $ability ] )", "'core'"):
        assert token in body, token
    assert body.index("isset( $core[ $ability ] )") < body.index("isset( $adapters[ $ability ] )")
    assert 'update_option(' not in body
    assert 'wp_register_ability(' not in body
    assert 'wp_has_ability(' not in body  # registered elsewhere, not minted by snapshot
    assert 'self::$registered_adapter_write_candidates_cache' not in body

check(source)
for altered in (
  source.replace('self::write_tools();', 'self::catalog_external_write_tools();'),
  source.replace("'mad4b/provider-connection-refresh',", "'mad4b/provider-refresh-unreviewed',"),
  source.replace('if ( isset( $core[ $ability ] ) )', 'if ( isset( $adapters[ $ability ] ) )'),
):
    assert altered!=source
    try:check(altered)
    except (AssertionError,ValueError):continue
    raise AssertionError('unreviewed write catalog mutation was admitted')
print('WRITE_CATALOG_SOURCE_REVIEW: PASS (bounded candidate list and live mounted-only provider resolution)')
