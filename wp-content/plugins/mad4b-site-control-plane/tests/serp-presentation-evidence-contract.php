<?php
/**
 * Standalone, source-owned SERP evidence contract.
 *
 * Run: php wp-content/plugins/mad4b-site-control-plane/tests/serp-presentation-evidence-contract.php
 * No database, network, WordPress mutation, Production environment or provider
 * credentials are required.
 */
define( 'ABSPATH', __DIR__ );
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10 ) { return true; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $candidate ) { return $candidate instanceof WP_Error; }
}

$source = dirname( __DIR__ ) . '/includes/class-mad4b-scp-research-intelligence.php';
require_once $source;
$policy = new ReflectionMethod( 'MAD4B_SCP_Research_Intelligence', 'validate_serp_presentation_evidence' );
$tests = 0;
$check = static function ( $condition, $message ) use ( &$tests ) {
	$tests++;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};
$code = static function ( $input ) use ( $policy ) {
	$result = $policy->invoke( null, $input );
	return is_wp_error( $result ) ? $result->get_error_code() : 'PASS';
};
$input = array(
	'job_id' => '11111111-2222-4333-8444-555555555555',
	'provider_id' => 'chatgpt_web_search',
	'provider_state' => 'succeeded',
	'query' => 'Cairo day tours',
	'market' => 'EG',
	'language' => 'en',
	'collected_at' => '2026-10-30T20:00:00Z',
	'request_sha256' => str_repeat( 'a', 64 ),
	'results' => array(
		array( 'title' => 'Source Result', 'url' => 'https://example.org/cairo', 'rank' => null ),
	),
);
$result = $policy->invoke( null, $input );
$check( is_array( $result ) && 'web_discovery' === $result['evidence_class'], 'unranked web discovery is accepted and labelled' );
$check( false === $result['independent_ranking_certified'], 'provider attribution is not independent certification' );
$check( false === $result['publishing_authority_created'], 'research does not grant publication' );

$missing = $input;
unset( $missing['market'] );
$check( 'mad4b_serp_policy_required_field' === $code( $missing ), 'missing market rejected' );
$missing = $input;
unset( $missing['provider_id'] );
$check( 'mad4b_serp_policy_required_field' === $code( $missing ), 'missing provider rejected' );
$missing = $input;
$missing['results'] = array();
$check( 'mad4b_serp_policy_result_count' === $code( $missing ), 'empty successful observation rejected' );
$missing['provider_state'] = 'timeout';
$check( 'PASS' === $code( $missing ), 'failed provider empty-result observation preserved' );

$bad = $input;
$bad['results'][0]['title'] = '';
$check( 'mad4b_serp_policy_title_required' === $code( $bad ), 'missing title rejected' );
$bad = $input;
$bad['results'][0]['url'] = 'http://example.org/';
$check( 'mad4b_serp_policy_https_required' === $code( $bad ), 'insecure source rejected' );
$bad['results'][0]['url'] = 'https://user:secret@example.org/';
$check( 'mad4b_serp_policy_https_required' === $code( $bad ), 'credentialed source URL rejected' );
$bad['results'][0]['url'] = 'https://';
$check( 'mad4b_serp_policy_https_required' === $code( $bad ), 'malformed source URL rejected' );

$bad = $input;
$bad['results'][0]['rank'] = 2;
$check( 'mad4b_serp_policy_unverified_rank' === $code( $bad ), 'raw rank rejected' );
$bad['results'][0]['rank_verified'] = true;
$check( 'mad4b_serp_policy_unverified_rank' === $code( $bad ), 'rank_verified flag alone is insufficient' );
$bad['results'][0]['ranking_proof'] = array( 'provider_request_id' => 'req-1' );
$check( 'mad4b_serp_policy_unverified_rank' === $code( $bad ), 'partial rank provenance rejected' );
$bad['results'][0]['ranking_proof'] = array(
	'provider_request_id' => 'req-1',
	'engine' => 'Google',
	'captured_at' => '2026-10-30T20:00:00Z',
	'device' => 'desktop',
	'location' => 'Cairo',
);
$check( 'mad4b_serp_policy_discovery_rank_prohibited' === $code( $bad ), 'web discovery cannot claim a certified rank' );
$bad['provider_id'] = 'measured_serp_provider';
$result = $policy->invoke( null, $bad );
$check( is_array( $result ) && 'provider_attributed_serp' === $result['evidence_class'], 'measured provider result is attributed, not independently certified' );

$bad = $input;
$bad['results'][0]['search_volume'] = 2500;
$check( 'mad4b_serp_policy_unverified_volume' === $code( $bad ), 'unattributed search volume rejected' );
$bad['results'][0]['volume_proof'] = array( 'provider_request_id' => 'vol-1', 'period' => '2026-10' );
$check( 'PASS' === $code( $bad ), 'measured volume accepted with period and provider' );
$bad['results'][0]['rank'] = -1;
$check( 'mad4b_serp_policy_unverified_rank' === $code( $bad ), 'negative rank rejected' );
$bad = $input;
$bad['results'] = array_fill( 0, 101, $input['results'][0] );
$check( 'mad4b_serp_policy_result_count' === $code( $bad ), 'oversized SERP snapshot rejected' );

$source_contents = file_get_contents( $source );
$check( str_contains( $source_contents, "self::validate_serp_presentation_evidence( \$input )" ), 'native record_serp calls release-owned policy' );
$check( str_contains( $source_contents, "MAD4B_CENTRAL_HUB_ENABLED" ), 'Staging central feature gate preserved' );
$check( ! str_contains( $source_contents, 'MAD4B_SERP_Presentation_Policy' ), 'no dependency on site-local Staging Bridge class' );
$check( str_contains( $source_contents, "presentation_policy_contract" ), 'policy lineage included in new artifacts' );
echo "PASS serp-presentation-evidence-contract: {$tests} assertions; no external IO or mutations\n";
