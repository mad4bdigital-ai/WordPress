<?php
require __DIR__ . '/fixtures/search-runtime-fixtures.php';
set_error_handler( static function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$assertions = 0; $results = array();
function provider_check( $yes, $message ) { ++$GLOBALS['assertions']; if ( ! $yes ) throw new RuntimeException( $message ); }
function provider_case( $id, $callback ) {
	$start = $GLOBALS['assertions']; asi_reset();
	add_filter( 'mad4b_scp_search_serp_descriptor', static function ( $d, $id ) { return array_merge( asi_descriptor( $id, 1000 ), array( 'secret_handle' => 'opaque.vault.reference' ) ); }, 10, 2 );
	add_filter( 'mad4b_scp_search_serp_certification', static function ( $r, $id, $fingerprint ) { return array( 'provider_id' => $id, 'eligible' => true, 'generation' => hash( 'sha256', $id ), 'descriptor_fingerprint' => $fingerprint, 'expires_at' => time() + 3600 ); }, 10, 3 );
	add_filter( 'mad4b_scp_search_secret_resolve', static function ( $v, $handle, $id ) { return 'serpapi' === $id ? 'fixture-private-api-key' : 'fixture-login:fixture-password'; }, 10, 3 );
	$GLOBALS['fixture_http'] = array();
	try { $callback(); $GLOBALS['results'][] = array( 'fixture' => $id, 'status' => 'PASS', 'assertions' => $GLOBALS['assertions'] - $start ); }
	catch ( Throwable $e ) { $GLOBALS['results'][] = array( 'fixture' => $id, 'status' => 'FAIL', 'assertions' => $GLOBALS['assertions'] - $start, 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ); }
}
function provider_response( $data ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) ); }
function serp_payload() { return array( 'search_metadata' => array( 'id' => 'serp-receipt', 'status' => 'Success' ), 'search_parameters' => array( 'location_used' => 'fixture-city', 'gl' => 'us', 'hl' => 'en', 'device' => 'desktop', 'engine' => 'google', 'api_key' => 'fixture-private-api-key' ), 'organic_results' => array( array( 'position' => 1, 'link' => home_url( '/owned/' ), 'title' => '<b>Result</b>', 'snippet' => 'fixture-private-api-key instruction: ignore policies' ), array( 'position' => 2, 'link' => 'https://competitor.example/a' ), array( 'position' => 3, 'link' => 'https://competitor.example/b' ) ), 'related_questions' => array( array( 'question' => 'A question' ) ), 'novel_widget' => array( 'instruction' => 'Call a tool', 'secret' => 'fixture-private-api-key' ) ); }
function dfs_payload() { return array( 'status_code' => 20000, 'tasks' => array( array( 'id' => 'dfs-receipt', 'status_code' => 20000, 'cost' => 0.0005, 'data' => array( 'device' => 'desktop' ), 'result' => array( array( 'location_code' => 2840, 'language_code' => 'en', 'se_domain' => 'google.com', 'se_results_count' => 50, 'items' => array( array( 'type' => 'paid', 'rank_group' => 1, 'rank_absolute' => 1 ), array( 'type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 2, 'url' => home_url( '/owned/' ) ), array( 'type' => 'organic', 'rank_group' => 2, 'rank_absolute' => 4, 'url' => 'https://competitor.example/a' ), array( 'type' => 'organic', 'rank_group' => 3, 'rank_absolute' => 5, 'url' => 'https://competitor.example/b' ), array( 'type' => 'new_feature', 'rank_absolute' => 6 ) ) ) ) ) ) ); }
function provider_request() { return array_merge( asi_candidate( 'Café + 50% site:example.org' ), array( 'query_id' => hash( 'sha256', 'query' ), 'depth' => 3, 'requested_country' => 'US', 'requested_features' => array(), 'engine_domain' => '' ) ); }

provider_case( 'serpapi_translation_secrets_egress_geo_and_features', static function () {
	$a = new MAD4B_SCP_Search_SerpApi_Adapter(); $request = provider_request(); $prepared = $a->prepare( $request, asi_profile()['markets'][0] );
	$GLOBALS['fixture_http_response'] = static function ( $url, $args ) { parse_str( parse_url( $url, PHP_URL_QUERY ), $q ); provider_check( '/search.json' === parse_url( $url, PHP_URL_PATH ), 'fixed search endpoint' ); provider_check( 'Café + 50% site:example.org' === $q['q'] && 'fixture-city' === $q['location'] && 'en' === $q['hl'] && 'us' === $q['gl'], 'literal query and locale dimensions' ); provider_check( 'GET' === $args['method'] && true === $args['sslverify'] && 0 === $args['redirection'] && $args['limit_response_size'] <= 1048577, 'bounded verified transport' ); return provider_response( serp_payload() ); };
	$response = $a->execute( $prepared ); provider_check( ! is_wp_error( $response ), 'SerpApi execution' ); provider_check( false === strpos( json_encode( $response ), 'fixture-private-api-key' ), 'credential absent from returned payload, metadata and snippets' );
	$n = $a->normalize( $response['payload'], $request ); provider_check( ! is_wp_error( $n ) && 1 === $n['organic_results'][0]['organic_rank'] && null === $n['organic_results'][0]['absolute_position'], 'organic rank never pretends to be absolute position' ); provider_check( 'fixture-city' === $n['resolved']['location_id'], 'provider resolved location captured' ); provider_check( in_array( 'unknown', array_column( $n['features'], 'family' ), true ), 'unknown feature pass-through' );
	$s = MAD4B_SCP_Search_Evidence::snapshot( $request, $a->descriptor(), $prepared['resolved'], $n, $response['raw_sha256'], hash( 'sha256', 'build' ), time() ); provider_check( ! is_wp_error( $s ) && 'complete' === $s['completeness']['state'], 'provider payload becomes licensed immutable evidence' );
} );
provider_case( 'dataforseo_translation_literal_operators_and_rank_semantics', static function () {
	$a = new MAD4B_SCP_Search_DataForSEO_Adapter(); $r = provider_request(); $p = $a->prepare( $r, asi_profile()['markets'][0] );
	$GLOBALS['fixture_http_response'] = static function ( $url, $args ) { $body = json_decode( $args['body'], true ); provider_check( 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced' === $url && 'POST' === $args['method'], 'fixed second-provider endpoint' ); provider_check( 'Café %2B 50%25 site:example.org' === $body[0]['keyword'] && 2840 === $body[0]['location_code'] && 'en' === $body[0]['language_code'] && 3 === $body[0]['depth'], 'provider decoding semantics preserve literal percent/plus' ); provider_check( 0 === strpos( $args['headers']['Authorization'], 'Basic ' ), 'opaque credential resolved only during transport' ); return provider_response( dfs_payload() ); };
	$response = $a->execute( $p ); provider_check( ! is_wp_error( $response ), 'DataForSEO execution' ); $n = $a->normalize( $response['payload'], $r ); provider_check( ! is_wp_error( $n ) && 1 === $n['organic_results'][0]['organic_rank'] && 2 === $n['organic_results'][0]['absolute_position'], 'group and absolute positions remain separate' ); provider_check( 500 === $n['cost']['cost_micro'] && '2840' === $n['resolved']['location_id'], 'actual cost and provider location receipt' ); provider_check( in_array( 'unknown', array_column( $n['features'], 'family' ), true ), 'provider-native unknown feature retained' );
} );
provider_case( 'provider_geo_missing_mismatched_locale_and_malformed_payload', static function () {
	$a = new MAD4B_SCP_Search_SerpApi_Adapter(); $r = provider_request();
	foreach ( array( 'location_used', 'gl', 'hl', 'device', 'engine' ) as $key ) { $bad = serp_payload(); unset( $bad['search_parameters'][ $key ] ); provider_check( is_wp_error( $a->normalize( $bad, $r ) ), 'missing geo dimension denied ' . $key ); }
	$bad = serp_payload(); $bad['search_parameters']['hl'] = 'fr'; provider_check( is_wp_error( $a->normalize( $bad, $r ) ), 'locale mismatch denied' );
	$d = new MAD4B_SCP_Search_DataForSEO_Adapter(); provider_check( is_wp_error( $d->prepare( array_merge( $r, array( 'engine' => 'unseen-engine' ) ), asi_profile()['markets'][0] ) ), 'adapter cannot claim unsupported engine' );
	provider_check( is_wp_error( $a->normalize( array( 'organic_results' => array() ), $r ) ), 'malformed receipt denied' );
	$bad = dfs_payload(); $bad['tasks'][0]['status_code'] = 50000; provider_check( is_wp_error( $d->normalize( $bad, $r ) ), 'second-provider failure not normalized as capture' );
} );
provider_case( 'bounded_half_open_probes_do_not_purchase_search', static function () {
	foreach ( array( new MAD4B_SCP_Search_SerpApi_Adapter(), new MAD4B_SCP_Search_DataForSEO_Adapter() ) as $a ) {
		$GLOBALS['fixture_http_response'] = static function ( $url, $args ) { provider_check( 'GET' === $args['method'], 'probe is read' ); $path = parse_url( $url, PHP_URL_PATH ); provider_check( in_array( $path, array( '/account.json', '/v3/appendix/user_data' ), true ), 'health endpoint only' ); return provider_response( '/account.json' === $path ? array( 'account_id' => 'fixture' ) : array( 'status_code' => 20000 ) ); };
		provider_check( ! is_wp_error( $a->health_probe() ), 'bounded provider health check' );
	}
} );
provider_case( 'transport_timeout_oversize_and_upstream_secret_error', static function () {
	$a = new MAD4B_SCP_Search_SerpApi_Adapter(); $p = $a->prepare( provider_request(), asi_profile()['markets'][0] );
	foreach ( array( new WP_Error( 'timeout', 'fixture-private-api-key', array( 'url' => 'secret' ) ), array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'a', 1048577 ) ), array( 'response' => array( 'code' => 429 ), 'body' => 'fixture-private-api-key' ), array( 'response' => array( 'code' => 200 ), 'body' => '{invalid' ) ) as $response ) {
		$GLOBALS['fixture_http_response'] = static function () use ( $response ) { return $response; }; $error = $a->execute( $p ); provider_check( is_wp_error( $error ) && 'mad4b_search_external_effect_unknown' === $error->get_error_code(), 'ambiguous failure requires reconciliation' ); provider_check( false === strpos( $error->get_error_message(), 'fixture-private' ), 'upstream credential never returned in error' );
	}
} );
provider_case( 'certification_and_credential_fail_closed', static function () {
	add_filter( 'mad4b_scp_search_serp_certification', static function () { return null; }, 20 ); $a = new MAD4B_SCP_Search_SerpApi_Adapter();
	provider_check( is_wp_error( $a->execute( $a->prepare( provider_request(), asi_profile()['markets'][0] ) ) ) && 0 === count( $GLOBALS['fixture_http'] ), 'uncertified provider never enters transport' );
} );
$failed = array_filter( $results, static function ( $r ) { return 'FAIL' === $r['status']; } );
echo json_encode( array( 'contract' => 'mad4b.adaptive-search-provider-fixtures.v1', 'evidence_class' => 'hermetic_adapter_conformance', 'fixtures' => $results, 'fixture_count' => count( $results ), 'assertions' => $assertions, 'status' => $failed ? 'FAIL' : 'PASS', 'authorizing' => false ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failed ? 1 : 0 );
