<?php
/* Hermetic selector fixture: no network, filesystem writes or WordPress mutation. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $result ) { return $result instanceof WP_Error; }
$GLOBALS['requests'] = array();
$GLOBALS['http_fail'] = false;
$GLOBALS['http_status'] = 200;
$GLOBALS['http_body'] = array();
function wp_remote_get( $url, $options ) {
	$GLOBALS['requests'][] = array( 'url' => $url, 'options' => $options );
	if ( $GLOBALS['http_fail'] ) return new WP_Error( 'external_unavailable' );
	return array( 'response' => array( 'code' => $GLOBALS['http_status'] ), 'body' => json_encode( $GLOBALS['http_body'] ) );
}
function wp_safe_remote_get( $url, $options ) { return wp_remote_get( $url, $options ); }
function wp_remote_retrieve_response_code( $result ) { return $result['response']['code']; }
function wp_remote_retrieve_body( $result ) { return $result['body']; }
$GLOBALS['checks'] = 0;
function expect( $condition, $message ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . "\n" ); exit( 1 ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-source-selector.php';
$cls = 'MAD4B_SCP_Staging_Source_Selector';
$repo = 'mad4bdigital-ai/WordPress';
$sha1 = str_repeat( 'a', 40 );
$sha2 = str_repeat( 'b', 40 );
$GLOBALS['http_body'] = array( 'state' => 'open', 'head' => array( 'repo' => array( 'full_name' => $repo ), 'sha' => $sha1 ) );
$pr = array( 'repository' => $repo, 'type' => 'pull_request', 'reference' => '258' );
$first = $cls::resolve( $pr );
expect( is_array( $first ) && $first['resolved_sha'] === $sha1 && $first['remote_verified'], 'PR resolves to immutable SHA' );
expect( $GLOBALS['requests'][0]['url'] === 'https://api.github.com/repos/mad4bdigital-ai/WordPress/pulls/258', 'only fixed API origin' );
expect( $GLOBALS['requests'][0]['options']['redirection'] === 0 && $GLOBALS['requests'][0]['options']['sslverify'] === true, 'no redirect or insecure TLS' );
$pr['reference'] = '7301';
$GLOBALS['http_body']['head']['sha'] = $sha2;
$next = $cls::resolve( $pr );
expect( is_array( $next ) && $next['reference'] === '7301' && $next['resolved_sha'] === $sha2, 'future PR is not hardcoded' );
$pr['repository'] = 'untrusted/other';
expect( is_wp_error( $cls::resolve( $pr ) ), 'non-allowlisted repo refused' );
$pr['repository'] = $repo;
$GLOBALS['http_body']['head']['repo']['full_name'] = 'foreign/fork';
expect( is_wp_error( $cls::resolve( $pr ) ), 'foreign PR head refused' );
$GLOBALS['http_body']['head']['repo']['full_name'] = $repo;
$GLOBALS['http_body']['state'] = 'closed';
expect( is_wp_error( $cls::resolve( $pr ) ), 'closed PR refused' );
$GLOBALS['http_body']['state'] = 'open';
$GLOBALS['http_body'] = array( 'commit' => array( 'sha' => $sha1 ) );
$branch = array( 'repository' => $repo, 'type' => 'branch', 'reference' => 'feature/next-candidate' );
expect( $cls::resolve( $branch )['resolved_sha'] === $sha1, 'dynamic branch resolved' );
expect( false !== strpos( end( $GLOBALS['requests'] )['url'], '/branches/feature%2Fnext-candidate' ), 'branch name safely encoded' );
$branch['reference'] = '../admin';
expect( is_wp_error( $cls::resolve( $branch ) ), 'traversal name refused' );
$branch['reference'] = 'good//bad';
expect( is_wp_error( $cls::resolve( $branch ) ), 'ambiguous ref refused' );
$branch['reference'] = 'feature/next-candidate';
$GLOBALS['http_body'] = array( 'sha' => $sha1 );
$commit = array( 'repository' => $repo, 'type' => 'commit', 'reference' => $sha1 );
expect( $cls::resolve( $commit )['resolved_sha'] === $sha1, 'exact commit supported' );
$GLOBALS['http_body']['sha'] = $sha2;
expect( is_wp_error( $cls::resolve( $commit ) ), 'commit mismatch refused' );
$GLOBALS['http_fail'] = true;
expect( is_wp_error( $cls::resolve( $branch ) ), 'network failure refuses admission' );
$GLOBALS['http_fail'] = false;
$GLOBALS['http_status'] = 301;
expect( is_wp_error( $cls::resolve( $branch ) ), 'redirect/status refused' );
$GLOBALS['http_status'] = 200;
expect( is_wp_error( $cls::resolve( array_merge( $branch, array( 'package_url' => 'https://elsewhere' ) ) ) ), 'untrusted caller URL refused' );
expect( is_wp_error( $cls::resolve( array( 'repository' => $repo, 'type' => 'pull_request', 'reference' => '0' ) ) ), 'zero PR invalid' );
expect( is_wp_error( $cls::resolve( array( 'repository' => $repo, 'type' => 'pull_request', 'reference' => '258/../../' ) ) ), 'path injection refused' );
echo 'MAD4B_DYNAMIC_STAGING_SOURCE: PASS ' . $GLOBALS['checks'] . " scenarios\n";
