"""Contract checks for the optional direct multipart transfer transport."""
from pathlib import Path

source = (Path(__file__).resolve().parents[1] / "includes" / "class-mad4b-scp-chatgpt-file-transfer.php").read_text()
assert "public static function multipart( $request )" in source
assert "register_rest_route( self::NS, '/multipart'" in source
assert "'permission_callback' => array( __CLASS__, 'permitted' )" in source
assert "is_uploaded_file( $file['tmp_name'] )" in source
assert "hash_equals( $expected, (string) hash_file( 'sha256', $file['tmp_name'] ) )" in source
assert "self::MAX_BYTES" in source
assert "self::begin( $begin )" in source
assert "self::chunk( $chunk )" in source
assert "self::finish( $done )" in source
assert "self::abort( $abort )" in source
print("multipart transfer contract PASS")
