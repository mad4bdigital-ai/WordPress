<?php
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', str_replace( '.', '_', trim( (string) $value ) ) ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

$GLOBALS['mad4b_test_options'] = array();
$GLOBALS['mad4b_test_filters'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['mad4b_test_options'][ $key ] = $value; return true; }
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );
	if ( isset( $GLOBALS['mad4b_test_filters'][ $tag ] ) && is_callable( $GLOBALS['mad4b_test_filters'][ $tag ] ) ) {
		return call_user_func_array( $GLOBALS['mad4b_test_filters'][ $tag ], $args );
	}
	return $value;
}

final class MAD4B_SCP_Distributed_Lock {
	private static $held = array();
	public static function catalog_name( $scope ) { return 'test-lock-' . substr( hash( 'sha256', (string) $scope ), 0, 24 ); }
	public static function acquire( $name ) {
		if ( isset( self::$held[ $name ] ) ) return new WP_Error( 'test_lock_busy', 'busy' );
		self::$held[ $name ] = true;
		return true;
	}
	public static function release( $name ) { unset( self::$held[ $name ] ); }
}

$root = dirname( __DIR__ );
require $root . '/includes/class-mad4b-scp-search-measurement.php';
require $root . '/includes/class-mad4b-scp-search-eligibility.php';
require $root . '/includes/class-mad4b-scp-search-evidence-policy.php';
require $root . '/includes/class-mad4b-scp-provider-account-budget-authority.php';
require $root . '/includes/class-mad4b-scp-search-decision-policy.php';
require $root . '/includes/class-mad4b-scp-adaptive-search-acceptance.php';

$fail = static function ( $message ) { fwrite( STDERR, "FAIL adaptive-search-p0-runtime: $message\n" ); exit( 1 ); };
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };
$error_code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

// Query canonicalization preserves semantics while removing whitespace noise.
$q = MAD4B_SCP_Search_Measurement::normalize_query( "  Egypt\t  Luxury Tours  ", 'en-US' );
$check( ! is_wp_error( $q ) && 'Egypt Luxury Tours' === $q['normalized_query'], 'query whitespace canonicalization failed' );
$q_accent = MAD4B_SCP_Search_Measurement::normalize_query( 'viaje a Egipto con crucero', 'es-US' );
$check( false !== strpos( $q_accent['normalized_query'], 'Egipto' ), 'query semantic content was lost' );

// Observation comparability is strict by default.
$base = array(
	'query' => 'egypt luxury tours', 'market' => 'US', 'language' => 'en', 'hl' => 'en', 'gl' => 'us',
	'engine' => 'google', 'engine_domain' => 'google.com', 'device' => 'desktop',
	'requested_depth' => 20, 'provider_location_id' => 'us', 'location_precision' => 'country',
	'provider_id' => 'serp_a', 'provider_semantic_profile' => 'google-organic-v1',
);
$c1 = MAD4B_SCP_Search_Measurement::observation_context( $base );
$c2 = MAD4B_SCP_Search_Measurement::observation_context( $base );
$check( MAD4B_SCP_Search_Measurement::compare_contexts( $c1, $c2 )['comparable'], 'same observation context not comparable' );
$other_provider = $base; $other_provider['provider_id'] = 'serp_b';
$c3 = MAD4B_SCP_Search_Measurement::observation_context( $other_provider );
$check( ! MAD4B_SCP_Search_Measurement::compare_contexts( $c1, $c3 )['comparable'], 'provider drift silently comparable' );
$uncertified_cross = $base; $uncertified_cross['cross_provider_comparability_class'] = 'google-us-en-desktop-v1';
$check(
	'mad4b_search_cross_provider_comparability_uncertified' === $error_code( MAD4B_SCP_Search_Measurement::observation_context( $uncertified_cross ) ),
	'uncertified cross-provider comparability class was accepted'
);
$cross_evidence = str_repeat( 'a', 64 );
$cross_a = $base;
$cross_a['cross_provider_comparability_class'] = 'google-us-en-desktop-v1';
$cross_a['cross_provider_comparability_certified'] = true;
$cross_a['cross_provider_comparability_evidence_sha256'] = $cross_evidence;
$cross_b = $other_provider;
$cross_b['cross_provider_comparability_class'] = 'google-us-en-desktop-v1';
$cross_b['cross_provider_comparability_certified'] = true;
$cross_b['cross_provider_comparability_evidence_sha256'] = $cross_evidence;
$cx1 = MAD4B_SCP_Search_Measurement::observation_context( $cross_a );
$cx2 = MAD4B_SCP_Search_Measurement::observation_context( $cross_b );
$check( MAD4B_SCP_Search_Measurement::compare_contexts( $cx1, $cx2 )['comparable'], 'certified cross-provider class not comparable' );
$location_drift = $base; $location_drift['provider_location_id'] = 'new-york-city'; $location_drift['location_precision'] = 'city';
$check( ! MAD4B_SCP_Search_Measurement::compare_contexts( $c1, MAD4B_SCP_Search_Measurement::observation_context( $location_drift ) )['comparable'], 'location drift silently comparable' );
$depth_drift = $base; $depth_drift['requested_depth'] = 100;
$check( ! MAD4B_SCP_Search_Measurement::compare_contexts( $c1, MAD4B_SCP_Search_Measurement::observation_context( $depth_drift ) )['comparable'], 'depth drift silently comparable' );

// URL identity binds canonical aliases without dropping query semantics by default.
$u1 = MAD4B_SCP_Search_Measurement::normalize_url_identity( 'HTTPS://Example.COM:443/tours/?b=2&a=1', 'https://example.com/tours/' );
$u2 = MAD4B_SCP_Search_Measurement::normalize_url_identity( 'https://example.com/tours/' );
$check( ! is_wp_error( $u1 ) && MAD4B_SCP_Search_Measurement::owned_url_matches( $u1, $u2 ), 'canonical URL alias did not match owned identity' );

$rank = MAD4B_SCP_Search_Measurement::normalize_rank_result( array(
	'result_type' => 'organic', 'organic_rank' => 7, 'group_rank' => 7, 'absolute_position' => 11, 'provider_native_position' => 11,
) );
$check( ! is_wp_error( $rank ) && 7 === $rank['organic_rank'] && 11 === $rank['absolute_position'], 'rank semantics collapsed' );

$complete_absent = MAD4B_SCP_Search_Measurement::capture_completeness( array(
	'requested_depth' => 20, 'returned_depth' => 20, 'validated' => true, 'target_found' => false,
) );
$partial_absent = MAD4B_SCP_Search_Measurement::capture_completeness( array(
	'requested_depth' => 20, 'returned_depth' => 10, 'validated' => true, 'target_found' => false, 'partial_reason' => 'provider_truncated',
) );
$check( true === $complete_absent['not_found_within_depth'] && true === $complete_absent['loss_inference_eligible'], 'complete absence not represented correctly' );
$check( false === $partial_absent['not_found_within_depth'] && false === $partial_absent['loss_inference_eligible'], 'partial absence incorrectly created loss eligibility' );

// Eligibility envelope separates crawl/index/canonical/language facts.
$eligible = MAD4B_SCP_Search_Eligibility::resolve( array(
	'http_status' => 200, 'robots_txt_allowed' => true, 'x_robots_tag' => '', 'meta_robots' => 'index,follow',
	'canonical_state' => 'self', 'redirect_state' => 'none', 'sitemap_state' => 'present',
	'hreflang_state' => 'valid', 'language_live' => true, 'object_public' => true,
) );
$check( $eligible['owned_tracking_eligible'], 'eligible owned surface was denied' );
$noindex = MAD4B_SCP_Search_Eligibility::resolve( array(
	'http_status' => 200, 'robots_txt_allowed' => true, 'x_robots_tag' => 'noindex', 'meta_robots' => '',
	'canonical_state' => 'self', 'redirect_state' => 'none', 'language_live' => true, 'object_public' => true,
) );
$check( ! $noindex['indexable'] && ! $noindex['owned_tracking_eligible'], 'X-Robots noindex was ignored' );
$unknown_eligibility = MAD4B_SCP_Search_Eligibility::resolve( array( 'object_public' => true ) );
$check( 'unknown' === $unknown_eligibility['eligibility_state'] && $unknown_eligibility['confidence'] < 0.5, 'unknown eligibility evidence was over-confident' );

$surface_policy = array(
	'allowed_surface_types' => array( 'term_archive', 'virtual_landing_surface', 'paginated_archive' ),
	'allowed_path_prefixes' => array( '/tours/' ),
	'allowed_query_params' => array( 'page' ),
	'max_cardinality' => 100,
	'max_page_number' => 10,
	'require_indexable_virtual' => true,
);
$admitted = MAD4B_SCP_Search_Eligibility::admit_surface( array(
	'surface_type' => 'virtual_landing_surface', 'url' => 'https://example.com/tours/?page=2',
	'estimated_cardinality' => 20, 'page_number' => 2, 'indexable' => true,
), $surface_policy );
$check( ! is_wp_error( $admitted ) && $admitted['admitted'], 'bounded virtual surface was denied' );
$unknown_param = MAD4B_SCP_Search_Eligibility::admit_surface( array(
	'surface_type' => 'virtual_landing_surface', 'url' => 'https://example.com/tours/?destination=cairo',
	'estimated_cardinality' => 20, 'indexable' => true,
), $surface_policy );
$check( 'mad4b_search_surface_query_param_denied' === $error_code( $unknown_param ), 'unknown facet parameter was admitted' );
$too_many = MAD4B_SCP_Search_Eligibility::admit_surface( array(
	'surface_type' => 'virtual_landing_surface', 'url' => 'https://example.com/tours/',
	'estimated_cardinality' => 1000, 'indexable' => true,
), $surface_policy );
$check( 'mad4b_search_surface_cardinality_exceeded' === $error_code( $too_many ), 'surface cardinality cap was not enforced' );
$missing_cardinality = MAD4B_SCP_Search_Eligibility::admit_surface( array(
	'surface_type' => 'virtual_landing_surface', 'url' => 'https://example.com/tours/', 'indexable' => true,
), $surface_policy );
$check( 'mad4b_search_surface_cardinality_required' === $error_code( $missing_cardinality ), 'virtual surface without cardinality estimate was admitted' );
$url_page_bypass = MAD4B_SCP_Search_Eligibility::admit_surface( array(
	'surface_type' => 'virtual_landing_surface', 'url' => 'https://example.com/tours/?page=999',
	'estimated_cardinality' => 20, 'page_number' => 1, 'indexable' => true,
), $surface_policy );
$check(
	in_array( $error_code( $url_page_bypass ), array( 'mad4b_search_surface_pagination_mismatch', 'mad4b_search_surface_pagination_exceeded' ), true ),
	'URL pagination bypassed max_page_number'
);

// Provider evidence rights constrain storage.
$rights = MAD4B_SCP_Search_Evidence_Policy::resolve_retention( array(
	'provider_id' => 'serp_fixture', 'policy_version' => '2026-10',
	'raw_retention_permitted' => false, 'normalized_retention_permitted' => true,
	'max_normalized_retention_seconds' => 86400, 'allowed_storage_regions' => array( 'US' ),
), array( 'raw_retention_seconds' => 3600, 'normalized_retention_seconds' => 604800, 'storage_region' => 'US' ) );
$check( ! is_wp_error( $rights ) && 0 === $rights['raw_retention_seconds'] && 86400 === $rights['normalized_retention_seconds'], 'provider retention constraints not applied' );
$rights_cap = MAD4B_SCP_Search_Evidence_Policy::resolve_retention( array(
	'provider_id' => 'serp_fixture', 'policy_version' => '2026-10', 'raw_retention_permitted' => true,
	'max_raw_retention_seconds' => 120, 'normalized_retention_permitted' => true, 'max_normalized_retention_seconds' => 600,
), array( 'raw_retention_seconds' => 3600, 'normalized_retention_seconds' => 3600 ) );
$check( ! is_wp_error( $rights_cap ) && 120 === $rights_cap['raw_retention_seconds'] && 600 === $rights_cap['normalized_retention_seconds'], 'raw retention cap not enforced' );
$region_denied = MAD4B_SCP_Search_Evidence_Policy::resolve_retention( array(
	'provider_id' => 'serp_fixture', 'allowed_storage_regions' => array( 'US' ),
), array( 'storage_region' => 'EU', 'normalized_retention_seconds' => 60 ) );
$check( 'mad4b_search_evidence_region_denied' === $error_code( $region_denied ), 'provider storage region restriction not enforced' );

// Local provider budget is truthful; hard-global needs shared authority.
$budget_input = array(
	'provider_id' => 'serp_fixture', 'credential_ref' => 'secret-ref-not-secret-value',
	'enforcement_mode' => 'local_best_effort', 'units' => 7, 'hard_allowance' => 10, 'protected_reserve' => 2,
	'billing_cycle_id' => '2026-10', 'idempotency_key' => 'job-1',
);
$b1 = MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $budget_input );
$check( ! is_wp_error( $b1 ) && 'local_best_effort' === $b1['enforcement_scope'] && false === $b1['hard_global_guarantee'], 'local budget overstated global guarantee' );
$b2_input = $budget_input; $b2_input['units'] = 2; $b2_input['idempotency_key'] = 'job-2';
$b2 = MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $b2_input );
$check( 'mad4b_provider_budget_exhausted' === $error_code( $b2 ), 'protected reserve did not constrain local budget' );
$commit = MAD4B_SCP_Provider_Account_Budget_Authority::commit( $b1 );
$check( ! is_wp_error( $commit ) && 'committed' === $commit['state'], 'local budget commit failed' );
$reconcile = MAD4B_SCP_Provider_Account_Budget_Authority::reconcile( array(
	'provider_id' => 'serp_fixture', 'credential_ref' => 'secret-ref-not-secret-value',
	'enforcement_mode' => 'local_best_effort', 'billing_cycle_id' => '2026-10',
	'hard_allowance' => 10, 'provider_observed_used' => 9,
) );
$check( ! is_wp_error( $reconcile ) && 9 === $reconcile['effective_used'], 'provider usage reconciliation not applied' );

// Billing-cycle reset and stale local reservation expiry are explicit.
$fresh_account = array(
	'provider_id' => 'serp_fixture', 'credential_ref' => 'fresh-budget-ref', 'enforcement_mode' => 'local_best_effort',
	'units' => 4, 'hard_allowance' => 5, 'protected_reserve' => 0, 'billing_cycle_id' => '2026-10', 'idempotency_key' => 'stale-1',
);
$stale = MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $fresh_account );
$check( ! is_wp_error( $stale ), 'stale reservation fixture failed' );
$state_key = MAD4B_SCP_Provider_Account_Budget_Authority::OPTION_PREFIX . substr( $stale['account_key'], 0, 40 );
$GLOBALS['mad4b_test_options'][ $state_key ]['reservations'][ $stale['reservation_id'] ]['expires_at'] = time() - 1;
$next = $fresh_account; $next['idempotency_key'] = 'stale-2'; $next['units'] = 5;
$next_reservation = MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $next );
$check( ! is_wp_error( $next_reservation ), 'stale reservation did not expire' );
$new_cycle = $fresh_account; $new_cycle['billing_cycle_id'] = '2026-11'; $new_cycle['idempotency_key'] = 'cycle-reset'; $new_cycle['units'] = 5;
$cycle_reservation = MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $new_cycle );
$check( ! is_wp_error( $cycle_reservation ), 'billing-cycle reset did not reset local state' );

$hard = $budget_input; $hard['enforcement_mode'] = 'hard_global'; $hard['idempotency_key'] = 'hard-1'; $hard['units'] = 1;
$unstable_hard = MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $hard );
$check( 'mad4b_provider_budget_global_account_identity_required' === $error_code( $unstable_hard ), 'hard-global budget accepted a site-local credential alias as global account identity' );
$hard['provider_account_ref'] = 'serp-fixture-account-123';
$denied_hard = MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $hard );
$check( 'mad4b_provider_budget_shared_authority_required' === $error_code( $denied_hard ), 'hard-global budget succeeded without shared authority' );
$GLOBALS['mad4b_test_filters']['mad4b_scp_provider_account_budget_authoritative_reserve'] = static function ( $value, $request ) {
	return array(
		'authoritative' => true, 'reservation_id' => 'shared-r1', 'fencing_epoch' => '42',
		'used_after' => 5, 'hard_allowance' => 10, 'backend_id' => 'ci_shared_budget',
	);
};
$hard_ok = MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $hard );
$check( ! is_wp_error( $hard_ok ) && true === $hard_ok['hard_global_guarantee'] && '42' === $hard_ok['fencing_epoch'], 'authoritative shared reservation was not recognized' );

// Decision policy is deterministic and provenance-bound.
$factor = static function ( $value, $source = 'fixture' ) {
	return array( 'value' => $value, 'source' => $source, 'observed_at' => '2026-10-05T00:00:00Z', 'confidence' => 1, 'normalization_version' => 'fixture-v1', 'market' => 'US', 'language' => 'en' );
};
$candidate = array( 'target_id' => 'target-a', 'factors' => array(
	'business_value' => $factor( 90 ), 'information_gain' => $factor( 80 ), 'urgency' => $factor( 70 ),
	'actionability' => $factor( 80 ), 'change_probability' => $factor( 60 ), 'confidence_need' => $factor( 70 ),
	'expected_cost' => $factor( 20 ),
) );
$d1 = MAD4B_SCP_Search_Decision_Policy::evaluate( $candidate );
$d2 = MAD4B_SCP_Search_Decision_Policy::evaluate( $candidate );
$check( ! is_wp_error( $d1 ) && hash_equals( $d1['decision_sha256'], $d2['decision_sha256'] ), 'deterministic decision digest drifted' );
$missing = $candidate; unset( $missing['factors']['information_gain'] );
$check( 'mad4b_search_decision_factor_missing' === $error_code( MAD4B_SCP_Search_Decision_Policy::evaluate( $missing ) ), 'missing required factor was not denied' );
$bad_provenance = $candidate; $bad_provenance['target_id'] = 'target-bad-provenance'; $bad_provenance['factors']['urgency']['source'] = '';
$check( 'mad4b_search_decision_factor_provenance_required' === $error_code( MAD4B_SCP_Search_Decision_Policy::evaluate( $bad_provenance ) ), 'missing factor provenance was not denied' );
$expensive = $candidate; $expensive['target_id'] = 'target-expensive'; $expensive['factors']['expected_cost'] = $factor( 100 );
$cheap = $candidate; $cheap['target_id'] = 'target-cheap'; $cheap['factors']['expected_cost'] = $factor( 0 );
$ranked = MAD4B_SCP_Search_Decision_Policy::rank( array( $expensive, $cheap ) );
$check( ! is_wp_error( $ranked ) && 'target-cheap' === $ranked['items'][0]['target_id'], 'cost penalty did not affect deterministic ranking' );
$tie_a = $candidate; $tie_a['target_id'] = 'a-target';
$tie_b = $candidate; $tie_b['target_id'] = 'b-target';
$tied = MAD4B_SCP_Search_Decision_Policy::rank( array( $tie_b, $tie_a ) );
$check( ! is_wp_error( $tied ) && 'a-target' === $tied['items'][0]['target_id'], 'stable lexical tie-break failed' );

// Machine-measurable acceptance reducer requires exact-head evidence and all fixtures.
$gate_evidence = array();
foreach ( MAD4B_SCP_Adaptive_Search_Acceptance::gates() as $gate => $definition ) {
	$fixtures = array();
	foreach ( $definition['fixtures'] as $fixture ) $fixtures[ $fixture ] = true;
	$gate_evidence[ $gate ] = array( 'fixtures' => $fixtures, 'assertion_count' => $definition['assertion_count_min'], 'exact_head_bound' => true );
}
$acceptance = MAD4B_SCP_Adaptive_Search_Acceptance::evaluate( $gate_evidence );
$check( true === $acceptance['pass'], 'complete exact-head acceptance evidence did not pass' );
$first_gate = array_key_first( $gate_evidence );
$gate_evidence[ $first_gate ]['exact_head_bound'] = false;
$check( false === MAD4B_SCP_Adaptive_Search_Acceptance::evaluate( $gate_evidence )['pass'], 'non-exact-head acceptance evidence incorrectly passed' );

echo "mad4b.adaptive-search-p0-runtime.v1: PASS\n";
