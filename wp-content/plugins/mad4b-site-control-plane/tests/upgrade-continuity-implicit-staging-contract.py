from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
src=(ROOT/'includes/class-mad4b-scp-upgrade-continuity.php').read_text(encoding='utf-8')
for marker in (
    "current_environment( array $snapshot = array() )",
    "MAD4B_SCP_Environment::effective()",
    "wordpress_environment_explicit()",
    "$snapshot_environment",
    "$snapshot_origin",
    "$snapshot_issuer",
    "hash_equals( $current_origin, $snapshot_origin )",
    "hash_equals( $expected_issuer, $snapshot_issuer )",
    "self::current_environment( $snapshot )",
):
    assert marker in src, marker
print('mad4b.upgrade-continuity-implicit-staging.v1: PASS')

guard=src.split("public static function guard_reconnect_paths()",1)[1].split("public static function is_known_oauth_protocol_path",1)[0]
assert "MAD4B_SCP_Local_OAuth_Server::runtime_identity_status()" in guard
assert "MAD4B_SCP_Local_OAuth_Server::status()" not in guard

reconnect=src.split("public static function reconnect_status()",1)[1].split("public static function governance_status()",1)[0]
assert "MAD4B_SCP_Local_OAuth_Server::runtime_identity_status()" in reconnect
assert "MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()" in reconnect
assert "MAD4B_SCP_Local_OAuth_Server::status()" not in reconnect
assert "MAD4B_SCP_OAuth_Resource_Bridge::status()" not in reconnect
