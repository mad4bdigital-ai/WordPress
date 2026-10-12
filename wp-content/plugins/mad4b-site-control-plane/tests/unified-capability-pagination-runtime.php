<?php
/**
 * Native regression test for the metadata-only capability search cursor.
 * php wp-content/plugins/mad4b-site-control-plane/tests/unified-capability-pagination-runtime.php
 * Includes the real production search implementation, not a cursor-model clone.
 */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
function wp_salt( $scheme = 'auth' ) { return 'fixture-private-hmac-key'; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $v ) ); }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return $GLOBALS['test_user']; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][$name] ); }
function wp_get_ability( $name ) { return $GLOBALS['abilities'][$name] ?? null; }
class MAD4B_SCP_Site_Profile {
    public static function status() { return array( 'profile_digest' => $GLOBALS['test_profile'] ); }
}
class MAD4B_SCP_ChatGPT_Tool_Projection {
    public static function all_site_ability_names() { return array_keys( $GLOBALS['abilities'] ); }
    public static function can_apply() { return new WP_Error( 'fixture_no_projection_authority' ); }
}
class FixtureAbility {
    private $name;
    private $category;
    private $readonly;
    public function __construct( $name, $category, $readonly ) {
        $this->name = $name; $this->category = $category; $this->readonly = $readonly;
    }
    public function get_name() { return $this->name; }
    public function get_label() { return 'Capability ' . $this->name . ( $GLOBALS['test_label_suffix'] ?? '' ); }
    public function get_description() { return 'Managed capability metadata'; }
    public function get_category() { return $this->category; }
    public function get_meta() {
        return array(
            'mcp' => array( 'surface' => 'read' === $this->category ? 'read' : 'admin' ),
            'annotations' => null === $this->readonly ? array() : array( 'readonly' => $this->readonly ),
        );
    }
}
require __DIR__ . '/../includes/class-mad4b-scp-unified-capability-gateway.php';
$assert = static function ( $ok, $message ) {
    if ( ! $ok ) throw new RuntimeException( $message );
};
$GLOBALS['test_user'] = 17;
$GLOBALS['test_profile'] = str_repeat( 'a', 64 );
$GLOBALS['test_label_suffix'] = '';
$GLOBALS['abilities'] = array();
for ( $i = 0; $i < 73; $i++ ) {
    $name = sprintf( 'fixture/capability-%03d', $i );
    $category = $i < 35 ? 'read' : 'admin';
    $GLOBALS['abilities'][$name] = new FixtureAbility( $name, $category, 72 === $i ? null : $i < 35 );
}
$options = array( 'task' => 'capability', 'limit' => 8, 'sort' => 'name' );
$seen = array(); $cursor = null; $pages = 0;
do {
    $request = $options;
    if ( null !== $cursor ) $request['cursor'] = $cursor;
    $result = MAD4B_SCP_Unified_Capability_Gateway::search( $request );
    $assert( ! is_wp_error( $result ), 'Cursor page read failed' );
    $assert( 73 === $result['total_match_count'], 'Total matches truncated' );
    $assert( count( $result['items'] ) <= 8, 'Page budget exceeded' );
    foreach ( $result['items'] as $item ) {
        $assert( ! isset( $seen[$item['ability_name']] ), 'Duplicate capability across pages' );
        $seen[$item['ability_name']] = true;
    }
    $cursor = $result['next_cursor'];
    $pages++;
    $assert( $pages < 15, 'Cursor never terminated' );
} while ( null !== $cursor );
$assert( count( $seen ) === 73 && $pages === 10, 'Not all abilities were streamed' );
$first = MAD4B_SCP_Unified_Capability_Gateway::search( $options );
$cursor = $first['next_cursor'];
$changed = $options; $changed['task'] = 'managed';
$assert( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::search( $changed + array( 'cursor' => $cursor ) ) ), 'Query-drifted cursor was accepted' );
$GLOBALS['test_user'] = 18;
$assert( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::search( $options + array( 'cursor' => $cursor ) ) ), 'Cross-user cursor was accepted' );
$GLOBALS['test_user'] = 17;
$GLOBALS['test_profile'] = str_repeat( 'b', 64 );
$assert( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::search( $options + array( 'cursor' => $cursor ) ) ), 'Cross-profile cursor was accepted' );
$GLOBALS['test_profile'] = str_repeat( 'a', 64 );
$GLOBALS['test_label_suffix'] = ' drift';
$assert( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::search( $options + array( 'cursor' => $cursor ) ) ), 'Changed search snapshot was accepted' );
$GLOBALS['test_label_suffix'] = '';
$tampered = substr( $cursor, 0, -1 ) . ( 'a' === substr( $cursor, -1 ) ? 'b' : 'a' );
$assert( is_wp_error( MAD4B_SCP_Unified_Capability_Gateway::search( $options + array( 'cursor' => $tampered ) ) ), 'Forged cursor was accepted' );
$read = MAD4B_SCP_Unified_Capability_Gateway::search( array( 'browse' => true, 'category' => 'read', 'declared_readonly_only' => true, 'limit' => 25 ) );
$assert( ! is_wp_error( $read ) && $read['total_match_count'] === 35, 'Read-only metadata facet failed' );
$write = MAD4B_SCP_Unified_Capability_Gateway::search( array( 'browse' => true, 'declared_writeonly_only' => true, 'limit' => 25 ) );
$assert( ! is_wp_error( $write ) && $write['total_match_count'] === 37, 'Unknown annotations were promoted to write' );
$all = MAD4B_SCP_Unified_Capability_Gateway::search( array( 'task' => 'Capability', 'keywords' => array( 'fixture', 'missing' ), 'match_mode' => 'all' ) );
$assert( ! is_wp_error( $all ) && 0 === $all['total_match_count'], 'All-term matching failed' );
echo "PASS unified capability cursor: 73 results, 10 pages, scope/snapshot/HMAC rejection, category, read/write, all-term filters\\n";
