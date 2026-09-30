from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
reconnect=(ROOT/'includes/class-mad4b-scp-reconnect-hardening.php').read_text(encoding='utf-8')
continuity=(ROOT/'includes/class-mad4b-scp-upgrade-continuity.php').read_text(encoding='utf-8')
isolation=(ROOT/'includes/class-mad4b-scp-mcp-provider-isolation.php').read_text(encoding='utf-8')
for marker in ("$portable_ready", "'connection_mode' => $portable_ready ? 'portable_readonly' : 'site_profile'", "'portable_readonly_effective' => $portable_ready"):
    assert marker in reconnect, marker
assert "MAD4B_SCP_Portable_Readonly_Connection::effective() ) return array();" in continuity
for marker in ("private static function policy_environment()", "suggested_environment()", "wordpress_environment_explicit()", "portable_readonly_bootstrap"):
    assert marker in isolation, marker
print('mad4b.portable-readonly-reconnect.v1: PASS')
