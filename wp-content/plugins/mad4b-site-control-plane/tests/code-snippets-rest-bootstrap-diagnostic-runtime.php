<?php
define( 'ABSPATH', '/tmp/' );
define( 'ARRAY_A', 'ARRAY_A' );

class MAD4B_Code_Snippets_WPDB {
	public $prefix = 'wp_';
	public $rows = array();

	public function prepare( $sql, ...$args ) {
		return $sql;
	}
	public function get_var( $sql ) {
		return 'wp_snippets';
	}
	public function get_results( $sql, $output = null ) {
		return $this->rows;
	}
}
$GLOBALS['wpdb'] = new MAD4B_Code_Snippets_WPDB();
$GLOBALS['wpdb']->rows = array(
	array(
		'id' => 101,
		'name' => 'REST bootstrap helper',
		'scope' => 'global',
		'priority' => 10,
		'active' => 1,
		'code' => "// rest_do_request( '/ignored-comment' );\nadd_action( 'init', function () {\n    do_action( 'rest_api_init' );\n    \$server = rest_get_server();\n} );\n",
	),
	array(
		'id' => 102,
		'name' => 'Route registration only',
		'scope' => 'admin',
		'priority' => 20,
		'active' => 1,
		'code' => "register_rest_route( 'demo/v1', '/status', array() );\n",
	),
	array(
		'id' => 103,
		'name' => 'Comment only',
		'scope' => 'global',
		'priority' => 30,
		'active' => 1,
		'code' => "/* rest_get_server(); rest_do_request(); register_rest_route(); */\n\$x = 1;\n",
	),
);

function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function add_action() {}
function wp_register_ability() {}
function wp_has_ability() { return false; }

final class MAD4B_SCP_Policy { public static function can_read() { return true; } }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-code-snippets-runtime-diagnostic.php';

function mad4b_snippet_assert( $condition, $message, $data = null ) {
	if ( $condition ) return;
	fwrite( STDERR, 'FAIL: ' . $message . ( null !== $data ? ' ' . json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
}

$result = MAD4B_SCP_Code_Snippets_Runtime_Diagnostic::execute();
mad4b_snippet_assert( 'mad4b.code-snippets-rest-bootstrap-diagnostic.v1' === $result['contract'], 'contract drifted', $result );
mad4b_snippet_assert( ! empty( $result['read_only'] ) && empty( $result['mutation_performed'] ), 'diagnostic must remain read-only', $result );
mad4b_snippet_assert( empty( $result['snippet_source_returned'] ) && empty( $result['secret_values_returned'] ), 'source/secrets must not be returned', $result );
mad4b_snippet_assert( 3 === (int) $result['active_snippets_scanned'], 'active snippet scan count drifted', $result );
mad4b_snippet_assert( 2 === (int) $result['matching_snippet_count'], 'comment-only snippet must not produce a match', $result );

$by_id = array();
foreach ( $result['matches'] as $match ) $by_id[ (int) $match['id'] ] = $match;
mad4b_snippet_assert( isset( $by_id[101], $by_id[102] ) && ! isset( $by_id[103] ), 'matching snippet identities drifted', $result );
mad4b_snippet_assert( ! empty( $by_id[101]['rest_bootstrap_risk'] ), 'REST bootstrap helper must be risk-classified', $by_id[101] );
mad4b_snippet_assert( empty( $by_id[102]['rest_bootstrap_risk'] ), 'route registration alone must not be classified as REST bootstrap replay', $by_id[102] );
mad4b_snippet_assert( 64 === strlen( $by_id[101]['code_sha256'] ) && ctype_xdigit( $by_id[101]['code_sha256'] ), 'code identity digest invalid', $by_id[101] );
mad4b_snippet_assert( ! isset( $by_id[101]['code'] ), 'raw snippet source leaked into output', $by_id[101] );

$patterns = array();
foreach ( $by_id[101]['patterns'] as $entry ) $patterns[ $entry['pattern'] ] = $entry;
mad4b_snippet_assert( isset( $patterns['do_action(rest_api_init)'] ), 'REST action replay pattern missing', $by_id[101] );
mad4b_snippet_assert( isset( $patterns['rest_get_server'] ), 'rest_get_server pattern missing', $by_id[101] );
mad4b_snippet_assert( 3 === (int) $patterns['do_action(rest_api_init)']['line'], 'REST action replay line number drifted', $patterns );
mad4b_snippet_assert( 4 === (int) $patterns['rest_get_server']['line'], 'rest_get_server line number drifted', $patterns );

$route_patterns = array_column( $by_id[102]['patterns'], 'pattern' );
mad4b_snippet_assert( in_array( 'register_rest_route', $route_patterns, true ), 'route registration pattern missing', $by_id[102] );

$comment_only = MAD4B_SCP_Code_Snippets_Runtime_Diagnostic::inspect_code_for_test( "// rest_get_server();\n/* do_action('rest_api_init'); */\n\$x = 1;\n" );
mad4b_snippet_assert( empty( $comment_only['patterns'] ), 'comments must be ignored by tokenizer', $comment_only );

echo "mad4b.code-snippets-rest-bootstrap-diagnostic.runtime.v1: PASS\n";
