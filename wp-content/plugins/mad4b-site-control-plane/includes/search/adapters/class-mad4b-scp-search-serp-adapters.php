<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Secrets exist only during transport. Default descriptors are inactive/uncertified. */
abstract class MAD4B_SCP_Search_HTTP_SERP_Adapter implements MAD4B_SCP_Search_SERP_Adapter, MAD4B_SCP_Search_SERP_Enrollment {
	abstract protected function provider_id();
	abstract protected function endpoint();
	public function normalize_credentials( array $credentials ) {
		$fields = $this->enrollment()['fields']; $out = array();
		if ( array_diff( array_keys( $credentials ), array_keys( $fields ) ) ) return MAD4B_SCP_Search_Contracts::error( 'credentials_invalid' );
		foreach ( $fields as $key => $field ) {
			$value = isset( $credentials[ $key ] ) ? $credentials[ $key ] : null;
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $field['max_length'] || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) || ( empty( $field['allow_spaces'] ) && preg_match( '/\s/', $value ) ) ) return MAD4B_SCP_Search_Contracts::error( 'credentials_invalid', 'Enter all required API credential fields; control characters are not allowed.' );
			$out[ $key ] = $value;
		}
		return $out;
	}
	protected function account_transport( array $credentials, array $query = array(), $authorization = null ) {
		if ( ! MAD4B_SCP_Search_Runtime::can_configure() ) return MAD4B_SCP_Search_Contracts::error( 'configuration_unauthorized' );
		$result = $this->transport( 'GET', $query, null, $authorization, true );
		// Enrollment cannot return a capture/reconciliation error or an upstream URL.
		return is_wp_error( $result ) ? MAD4B_SCP_Search_Contracts::error( 'account_check_failed', 'Connection could not be verified. Check the API credentials and try the account check again.' ) : $result['payload'];
	}
	public function descriptor() {
		$id = $this->provider_id();
		$default = array( 'contract' => 'mad4b.serp-provider-descriptor.v1', 'provider_id' => $id, 'family' => 'serp', 'active' => false, 'certified' => false, 'certification_generation' => '', 'certification_expires_at' => 0, 'capabilities' => array( 'engines' => array( 'google' ), 'devices' => array( 'desktop', 'mobile' ), 'max_depth' => 10, 'features' => array() ), 'usage' => array(), 'economics' => array(), 'health' => array(), 'evidence_rights' => array(), 'account_id' => '', 'secret_handle' => '' );
		$d = apply_filters( 'mad4b_scp_search_serp_descriptor', $default, $id );
		if ( ! is_array( $d ) ) return $default;
		$d['provider_id'] = $id; $d['contract'] = $default['contract'];
		// Certification is supplied by a separately governed server-side authority.
		$fingerprint = MAD4B_SCP_Search_Contracts::digest( array_intersect_key( $d, array_flip( array( 'capabilities', 'economics', 'evidence_rights', 'account_id', 'secret_handle' ) ) ) );
		$receipt = apply_filters( 'mad4b_scp_search_serp_certification', null, $id, $fingerprint );
		$d['certified'] = is_array( $receipt ) && ! empty( $receipt['eligible'] ) && isset( $receipt['provider_id'], $receipt['generation'], $receipt['expires_at'], $receipt['descriptor_fingerprint'] ) && hash_equals( $fingerprint, (string) $receipt['descriptor_fingerprint'] ) && $id === $receipt['provider_id'] && MAD4B_SCP_Search_Contracts::sha( $receipt['generation'] );
		$d['certification_generation'] = $d['certified'] ? $receipt['generation'] : '';
		$d['certification_expires_at'] = $d['certified'] ? $receipt['expires_at'] : 0;
		return $d;
	}
	protected function credential() {
		$d = $this->descriptor(); $guard = MAD4B_SCP_Search_Providers::descriptor_guard( $d, time() ); if ( is_wp_error( $guard ) ) return $guard;
		$credential = apply_filters( 'mad4b_scp_search_secret_resolve', null, isset( $d['secret_handle'] ) ? $d['secret_handle'] : '', $this->provider_id() );
		return is_string( $credential ) && '' !== $credential && strlen( $credential ) <= 4096 && ! preg_match( '/[\r\n]/', $credential ) ? $credential : MAD4B_SCP_Search_Contracts::error( 'secret_unavailable' );
	}
	protected function transport( $method, array $query, $body = null, $authorization = null, $health = false ) {
		$endpoint = $health ? $this->health_endpoint() : $this->endpoint();
		$url = $endpoint; if ( $query ) $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$args = array( 'method' => $method, 'timeout' => 30, 'sslverify' => true, 'redirection' => 0, 'limit_response_size' => MAD4B_SCP_Search_Contracts::MAX_BYTES + 1, 'headers' => array( 'Accept' => 'application/json' ) );
		if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); $args['headers']['Content-Type'] = 'application/json'; }
		if ( null !== $authorization ) $args['headers']['Authorization'] = $authorization;
		if ( ! class_exists( 'MAD4B_SCP_Egress_Policy' ) ) return MAD4B_SCP_Search_Contracts::error( 'egress_unavailable' );
		$args = MAD4B_SCP_Egress_Policy::mark_request( 'serp_capture', $url, $endpoint, $args ); if ( is_wp_error( $args ) ) return $args;
		$response = wp_safe_remote_request( $url, $args );
		// Never return upstream errors: their messages/data may contain credentials or URLs.
		if ( is_wp_error( $response ) ) return MAD4B_SCP_Search_Contracts::error( 'external_effect_unknown', 'Provider transport ended without a verifiable quota receipt; reconciliation is required.' );
		$code = wp_remote_retrieve_response_code( $response ); $raw = wp_remote_retrieve_body( $response );
		if ( 200 !== $code || strlen( $raw ) > MAD4B_SCP_Search_Contracts::MAX_BYTES ) return MAD4B_SCP_Search_Contracts::error( 'external_effect_unknown' );
		$data = json_decode( $raw, true, 16 );
		if ( ! is_array( $data ) || ! MAD4B_SCP_Search_Contracts::bounded( $data ) ) return MAD4B_SCP_Search_Contracts::error( 'external_effect_unknown' );
		$secrets = array();
		if ( isset( $query['api_key'] ) ) $secrets[] = $query['api_key'];
		if ( null !== $authorization ) { $secrets[] = $authorization; $secrets[] = substr( $authorization, 6 ); $decoded = base64_decode( substr( $authorization, 6 ), true ); if ( is_string( $decoded ) ) { $secrets[] = $decoded; foreach ( explode( ':', $decoded ) as $part ) if ( strlen( $part ) > 2 ) $secrets[] = $part; } }
		$redact = static function ( $value ) use ( &$redact, $secrets ) {
			if ( is_string( $value ) ) { foreach ( $secrets as $secret ) $value = str_replace( array( $secret, rawurlencode( $secret ) ), '[REDACTED]', $value ); return $value; }
			if ( is_array( $value ) ) foreach ( $value as $key => $item ) { if ( is_string( $key ) && preg_match( '/api.?key|authorization|token|secret|password|credential/i', $key ) ) unset( $value[ $key ] ); else $value[ $key ] = $redact( $item ); }
			return $value;
		};
		$data = $redact( $data );
		return array( 'payload' => $data, 'raw_sha256' => hash( 'sha256', $raw ) );
	}
	abstract protected function health_endpoint();
	public function health_probe() {
		$credential = $this->credential(); if ( is_wp_error( $credential ) ) return $credential;
		$result = 'serpapi' === $this->provider_id() ? $this->transport( 'GET', array( 'api_key' => $credential ), null, null, true ) : $this->transport( 'GET', array(), null, 'Basic ' . base64_encode( $credential ), true );
		if ( is_wp_error( $result ) ) return $result;
		$payload = $result['payload'];
		return ( 'serpapi' === $this->provider_id() ? isset( $payload['account_id'] ) && ! isset( $payload['error'] ) : isset( $payload['status_code'] ) && 20000 === $payload['status_code'] ) ? array( 'healthy' => true ) : MAD4B_SCP_Search_Contracts::error( 'health_probe_invalid' );
	}
	public function reconcile( array $job ) {
		// An unsupported account/provider reconciliation cannot fabricate a no-effect result.
		return apply_filters( 'mad4b_scp_search_provider_reconciliation', MAD4B_SCP_Search_Contracts::error( 'reconciliation_unavailable' ), $this->provider_id(), $job );
	}
	protected function prepared( array $request, array $market, $location ) {
		if ( empty( $market['provider_locations'][ $this->provider_id() ] ) || empty( $market['country'] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_location_missing' );
		$geo = $market['provider_locations'][ $this->provider_id() ];
		if ( ! is_array( $geo ) || empty( $geo['id'] ) || empty( $geo['precision'] ) ) return MAD4B_SCP_Search_Contracts::error( 'provider_location_invalid' );
		return array( 'request' => $request, 'resolved' => array( 'location_id' => $geo['id'], 'precision' => $geo['precision'], 'country' => $market['country'], 'language_code' => $request['language'] ), 'location' => $geo['id'] );
	}
}

final class MAD4B_SCP_Search_SerpApi_Adapter extends MAD4B_SCP_Search_HTTP_SERP_Adapter {
	protected function provider_id() { return 'serpapi'; }
	protected function endpoint() { return 'https://serpapi.com/search.json'; }
	protected function health_endpoint() { return 'https://serpapi.com/account.json'; }
	public function enrollment() { return array( 'label' => 'SerpApi', 'help_url' => 'https://serpapi.com/manage-api-key', 'help' => 'Use your SerpApi account API key. The account check does not run a search.', 'fields' => array( 'api_key' => array( 'label' => 'API key', 'max_length' => 2048, 'allow_spaces' => false ) ) ); }
	public function credential_value( array $credentials ) { return $credentials['api_key']; }
	public function observe_account( array $credentials ) {
		$c = $this->normalize_credentials( $credentials ); if ( is_wp_error( $c ) ) return $c;
		$p = $this->account_transport( $c, array( 'api_key' => $c['api_key'] ) ); if ( is_wp_error( $p ) ) return $p;
		if ( isset( $p['error'] ) || ! isset( $p['account_id'], $p['account_status'], $p['total_searches_left'] ) || ! is_string( $p['account_id'] ) || '' === $p['account_id'] || strlen( $p['account_id'] ) > 256 || 'Active' !== $p['account_status'] || ! is_int( $p['total_searches_left'] ) || $p['total_searches_left'] < 0 ) return MAD4B_SCP_Search_Contracts::error( 'account_receipt_invalid', 'The account check did not return an active account with a valid allowance.' );
		$quota = array( 'remaining' => $p['total_searches_left'] );
		foreach ( array( 'searches_per_month' => 'monthly_limit', 'this_month_usage' => 'monthly_used' ) as $native => $key ) if ( isset( $p[ $native ] ) && is_int( $p[ $native ] ) && $p[ $native ] >= 0 ) $quota[ $key ] = $p[ $native ];
		$renewal = isset( $p['plan_renewal_date'] ) ? $p['plan_renewal_date'] : null;
		// The API supplies a date, not an exact reset instant or timezone.
		// Keep that native precision; budget certification must supply its own fence.
		if ( is_string( $renewal ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $renewal ) ) { $date = strtotime( $renewal . ' 00:00:00 UTC' ); if ( false !== $date && gmdate( 'Y-m-d', $date ) === $renewal ) $quota['renewal_date'] = $renewal; }
		return array( 'account_ref' => hash( 'sha256', $p['account_id'] ), 'quota' => $quota, 'balance' => null );
	}
	public function prepare( array $request, array $market ) { return $this->prepared( $request, $market, 'location' ); }
	public function execute( array $prepared ) {
		$secret = $this->credential(); if ( is_wp_error( $secret ) ) return $secret;
		$r = $prepared['request'];
		$params = array( 'engine' => $r['engine'], 'q' => $r['query'], 'location' => $prepared['location'], 'gl' => strtolower( $prepared['resolved']['country'] ), 'hl' => $r['language'], 'device' => $r['device'], 'num' => $r['depth'], 'api_key' => $secret );
		if ( ! empty( $r['engine_domain'] ) ) $params['google_domain'] = $r['engine_domain'];
		$response = $this->transport( 'GET', $params ); unset( $secret, $params ); return $response;
	}
	public function normalize( array $payload, array $request ) {
		if ( empty( $payload['search_metadata']['id'] ) || 'Success' !== ( isset( $payload['search_metadata']['status'] ) ? $payload['search_metadata']['status'] : '' ) || ! isset( $payload['organic_results'] ) || ! is_array( $payload['organic_results'] ) ) return MAD4B_SCP_Search_Contracts::error( 'capture_invalid' );
		$results = array();
		foreach ( $payload['organic_results'] as $item ) $results[] = array( 'result_type' => 'organic', 'organic_rank' => isset( $item['position'] ) ? $item['position'] : null, 'group_rank' => isset( $item['position'] ) ? $item['position'] : null, 'absolute_position' => null, 'provider_native_position' => isset( $item['position'] ) ? $item['position'] : null, 'url' => isset( $item['link'] ) ? $item['link'] : '', 'title' => isset( $item['title'] ) ? $item['title'] : '', 'snippet' => isset( $item['snippet'] ) ? $item['snippet'] : '' );
		$features = array();
		foreach ( array_diff( array_keys( $payload ), array( 'search_metadata', 'search_parameters', 'search_information', 'organic_results', 'serpapi_pagination', 'pagination' ) ) as $key ) $features[] = array( 'family' => in_array( $key, array( 'related_questions', 'answer_box', 'knowledge_graph', 'ai_overview' ), true ) ? $key : 'unknown', 'provider_native_type' => $key, 'data' => $payload[ $key ] );
		$p = isset( $payload['search_parameters'] ) ? $payload['search_parameters'] : array();
		if ( ! isset( $p['location_used'], $p['gl'], $p['hl'], $p['engine'], $p['device'] ) || $p['hl'] !== $request['language'] || $p['engine'] !== $request['engine'] || strtolower( $p['device'] ) !== $request['device'] || ( ! empty( $request['engine_domain'] ) && ( ! isset( $p['google_domain'] ) || $p['google_domain'] !== $request['engine_domain'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'geo_fidelity_unproven' );
		return array( 'resolved' => array( 'location_id' => $p['location_used'], 'country' => strtoupper( $p['gl'] ), 'language_code' => $p['hl'], 'precision' => 'provider_reported' ), 'organic_results' => $results, 'features' => $features, 'provider_request_id' => $payload['search_metadata']['id'], 'completeness' => array( 'state' => count( $results ) >= $request['depth'] ? 'complete' : 'partial', 'returned_depth' => count( $results ), 'reason' => count( $results ) >= $request['depth'] ? '' : 'returned_less_than_requested' ), 'cost' => array( 'units' => 1, 'cost_micro' => $this->descriptor()['economics']['max_cost_micro'] ) );
	}
}

final class MAD4B_SCP_Search_DataForSEO_Adapter extends MAD4B_SCP_Search_HTTP_SERP_Adapter {
	protected function provider_id() { return 'dataforseo'; }
	protected function endpoint() { return 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced'; }
	protected function health_endpoint() { return 'https://api.dataforseo.com/v3/appendix/user_data'; }
	public function enrollment() { return array( 'label' => 'DataForSEO', 'help_url' => 'https://app.dataforseo.com/api-access', 'help' => 'Use your API login and API password from API Access. Account balance is money, not a search allowance.', 'fields' => array( 'login' => array( 'label' => 'API login', 'max_length' => 512, 'allow_spaces' => false ), 'password' => array( 'label' => 'API password', 'max_length' => 2048, 'allow_spaces' => true ) ) ); }
	public function normalize_credentials( array $credentials ) { $c = parent::normalize_credentials( $credentials ); return ! is_wp_error( $c ) && false !== strpos( $c['login'], ':' ) ? MAD4B_SCP_Search_Contracts::error( 'credentials_invalid' ) : $c; }
	public function credential_value( array $credentials ) { return $credentials['login'] . ':' . $credentials['password']; }
	public function observe_account( array $credentials ) {
		$c = $this->normalize_credentials( $credentials ); if ( is_wp_error( $c ) ) return $c;
		$p = $this->account_transport( $c, array(), 'Basic ' . base64_encode( $this->credential_value( $c ) ) ); if ( is_wp_error( $p ) ) return $p;
		if ( ! isset( $p['status_code'], $p['tasks'][0]['status_code'], $p['tasks'][0]['result'][0]['money']['balance'] ) || ! is_array( $p['tasks'] ) || ! is_array( $p['tasks'][0]['result'] ) || 20000 !== $p['status_code'] || 20000 !== $p['tasks'][0]['status_code'] || 1 !== count( $p['tasks'] ) || 1 !== count( $p['tasks'][0]['result'] ) ) return MAD4B_SCP_Search_Contracts::error( 'account_receipt_invalid', 'The account check did not return a valid balance receipt.' );
		$balance = $p['tasks'][0]['result'][0]['money']['balance'];
		if ( ( ! is_int( $balance ) && ! is_float( $balance ) ) || ! is_finite( (float) $balance ) || abs( $balance ) > 1000000000 ) return MAD4B_SCP_Search_Contracts::error( 'account_receipt_invalid' );
		return array( 'account_ref' => hash( 'sha256', $c['login'] ), 'quota' => array(), 'balance' => array( 'amount' => $balance, 'currency' => 'USD' ) );
	}
	public function prepare( array $request, array $market ) {
		if ( 'google' !== $request['engine'] ) return MAD4B_SCP_Search_Contracts::error( 'engine_unsupported' );
		$p = $this->prepared( $request, $market, 'location_code' );
		if ( is_wp_error( $p ) || 1 !== preg_match( '/^[0-9]+$/D', (string) $p['location'] ) ) return MAD4B_SCP_Search_Contracts::error( 'location_code_invalid' );
		return $p;
	}
	public function execute( array $prepared ) {
		$credential = $this->credential(); if ( is_wp_error( $credential ) ) return $credential;
		$r = $prepared['request'];
		// The API decodes plus/percent once; preserve literal query operators and symbols.
		$keyword = str_replace( array( '%', '+' ), array( '%25', '%2B' ), $r['query'] );
		$task = array( 'keyword' => $keyword, 'location_code' => (int) $prepared['location'], 'language_code' => $r['language'], 'device' => $r['device'], 'depth' => $r['depth'] );
		if ( ! empty( $r['engine_domain'] ) ) $task['se_domain'] = $r['engine_domain'];
		$response = $this->transport( 'POST', array(), array( $task ), 'Basic ' . base64_encode( $credential ) ); unset( $credential ); return $response;
	}
	public function normalize( array $payload, array $request ) {
		$task = isset( $payload['tasks'][0] ) ? $payload['tasks'][0] : array(); $result = isset( $task['result'][0] ) ? $task['result'][0] : array();
		if ( ! isset( $payload['status_code'], $task['status_code'] ) || 20000 !== $payload['status_code'] || 20000 !== $task['status_code'] || empty( $task['id'] ) || ! isset( $result['items'] ) || ! is_array( $result['items'] ) ) return MAD4B_SCP_Search_Contracts::error( 'capture_invalid' );
		$organic = array(); $features = array();
		foreach ( $result['items'] as $item ) {
			$type = isset( $item['type'] ) ? $item['type'] : 'unknown';
			if ( 'organic' === $type ) $organic[] = array( 'result_type' => 'organic', 'organic_rank' => isset( $item['rank_group'] ) ? $item['rank_group'] : null, 'group_rank' => isset( $item['rank_group'] ) ? $item['rank_group'] : null, 'absolute_position' => isset( $item['rank_absolute'] ) ? $item['rank_absolute'] : null, 'provider_native_position' => isset( $item['rank_absolute'] ) ? $item['rank_absolute'] : null, 'url' => isset( $item['url'] ) ? $item['url'] : '', 'title' => isset( $item['title'] ) ? $item['title'] : '', 'snippet' => isset( $item['description'] ) ? $item['description'] : '' );
			else $features[] = array( 'family' => in_array( $type, array( 'people_also_ask', 'featured_snippet', 'knowledge_graph', 'ai_overview' ), true ) ? $type : 'unknown', 'provider_native_type' => $type, 'data' => $item );
		}
		$depth = isset( $result['se_results_count'] ) ? min( $request['depth'], count( $organic ) ) : count( $organic );
		$cost = isset( $task['cost'] ) && is_numeric( $task['cost'] ) ? (int) ceil( $task['cost'] * 1000000 ) : null;
		if ( ! isset( $result['location_code'], $result['language_code'], $task['data']['device'] ) || $result['language_code'] !== $request['language'] || $task['data']['device'] !== $request['device'] || ( ! empty( $request['engine_domain'] ) && ( ! isset( $result['se_domain'] ) || $result['se_domain'] !== $request['engine_domain'] ) ) ) return MAD4B_SCP_Search_Contracts::error( 'geo_fidelity_unproven' );
		$country = isset( $request['requested_country'] ) ? $request['requested_country'] : '';
		return array( 'resolved' => array( 'location_id' => (string) $result['location_code'], 'country' => $country, 'language_code' => $result['language_code'], 'precision' => 'provider_reported' ), 'organic_results' => $organic, 'features' => $features, 'provider_request_id' => $task['id'], 'completeness' => array( 'state' => $depth >= $request['depth'] ? 'complete' : 'partial', 'returned_depth' => $depth, 'reason' => $depth >= $request['depth'] ? '' : 'returned_less_than_requested' ), 'cost' => array( 'units' => 1, 'cost_micro' => $cost ) );
	}
}
