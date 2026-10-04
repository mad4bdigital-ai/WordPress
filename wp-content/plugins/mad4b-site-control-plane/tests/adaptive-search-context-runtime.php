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

$GLOBALS['mad4b_search_context_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['mad4b_search_context_options'] ) ? $GLOBALS['mad4b_search_context_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['mad4b_search_context_options'][ $key ] = $value;
	return true;
}

$root = dirname( __DIR__ );
require $root . '/includes/class-mad4b-scp-search-runtime-context.php';

$fail = static function ( $message ) {
	fwrite( STDERR, "FAIL adaptive-search-context-runtime: $message\n" );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };
$error_code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

$fact = MAD4B_SCP_Search_Runtime_Context::runtime_fact( array(
	'fact_id' => 'language.en-us.live',
	'fact_type' => 'language_state',
	'value' => array( 'language' => 'en', 'locale' => 'en_US', 'live' => true ),
	'source' => 'fixture-language-registry',
	'source_generation' => 'fixture-v1',
	'confidence' => 0.95,
	'observed_at' => '2026-10-05T00:00:00Z',
	'expires_at' => '2026-10-06T00:00:00Z',
) );
$check( ! is_wp_error( $fact ), 'canonical runtime fact rejected' );
$check( 'mad4b.search-runtime-fact.v1' === $fact['contract'], 'runtime fact contract drifted' );
$check( false === $fact['authorizing'] && 64 === strlen( $fact['fingerprint'] ), 'runtime fact authorization/digest invariant failed' );

$bad_fact = MAD4B_SCP_Search_Runtime_Context::runtime_fact( array(
	'fact_id' => 'bad.fact',
	'fact_type' => 'language_state',
	'value' => true,
) );
$check( 'mad4b_search_runtime_fact_provenance_required' === $error_code( $bad_fact ), 'fact without provenance was accepted' );

$profile_input = array(
	'profile' => array(
		'profile_id' => 'brand-us',
		'site_uuid' => 'site-fixture-001',
		'brand_id' => 'brand-fixture',
		'enabled' => true,
		'markets' => array( 'US' ),
		'language_policy' => array( 'resolved_languages' => array( 'en', 'es' ), 'owned_only' => true ),
		'surface_policy' => array( 'allow_archives' => true ),
		'provider_policy' => array( 'preferred_family' => 'serp' ),
		'budget_policy' => array( 'monthly_units' => 1000 ),
		'refresh_policy' => array( 'stable_days' => 14 ),
		'priority_policy' => array( 'commercial_weight' => 50 ),
		'experiment_policy' => array( 'enabled' => false ),
		'objective' => 'rank-and-gap-observation',
	),
	'expected_revision' => 0,
);

$plan = MAD4B_SCP_Search_Runtime_Context::profile_plan( $profile_input );
$check( ! is_wp_error( $plan ), 'profile plan failed' );
$check( 1 === $plan['profile']['revision'], 'profile first revision must be 1' );
$check( false === $plan['authorizing'], 'profile plan became authorizing' );
$check( false === $plan['wordpress_mutation_authority_granted'], 'profile plan granted WordPress mutation authority' );
$check( false === $plan['production_authority_granted'], 'profile plan granted Production authority' );

$wrong_apply = $profile_input;
$wrong_apply['plan_sha256'] = str_repeat( 'a', 64 );
$check(
	'mad4b_search_profile_plan_drift' === $error_code( MAD4B_SCP_Search_Runtime_Context::profile_apply( $wrong_apply ) ),
	'apply accepted wrong exact plan SHA'
);

$apply = $profile_input;
$apply['plan_sha256'] = $plan['plan_sha256'];
$applied = MAD4B_SCP_Search_Runtime_Context::profile_apply( $apply );
$check( ! is_wp_error( $applied ) && true === $applied['applied'], 'profile apply failed' );
$check( false === $applied['authorizing'], 'profile apply became authorizing' );

$verify = MAD4B_SCP_Search_Runtime_Context::profile_verify( array(
	'profile_id' => 'brand-us',
	'expected_revision' => 1,
	'expected_profile_sha256' => $applied['profile']['profile_sha256'],
) );
$check( true === $verify['verified'], 'profile verify failed after exact apply' );

$stale = $profile_input;
$stale['expected_revision'] = 0;
$check(
	'mad4b_search_profile_revision_drift' === $error_code( MAD4B_SCP_Search_Runtime_Context::profile_plan( $stale ) ),
	'stale revision did not fail closed'
);

$site_change = $profile_input;
$site_change['expected_revision'] = 1;
$site_change['profile']['site_uuid'] = 'site-fixture-002';
$check(
	'mad4b_search_profile_site_identity_immutable' === $error_code( MAD4B_SCP_Search_Runtime_Context::profile_plan( $site_change ) ),
	'existing Search Profile changed site identity'
);

$profile_widen = $profile_input;
$profile_widen['profile']['production_authority'] = true;
$profile_widen['expected_revision'] = 1;
$check(
	'mad4b_search_profile_security_widening_denied' === $error_code( MAD4B_SCP_Search_Runtime_Context::profile_plan( $profile_widen ) ),
	'profile encoded Production authority'
);

$compile_input = array(
	'profile_id' => 'brand-us',
	'brand_profile' => array(
		'priority_policy' => array( 'brand_weight' => 10 ),
		'objective' => 'brand-objective',
	),
	'market_overlay' => array(
		'markets' => array( 'US', 'CA' ),
		'budget_policy' => array( 'market_cap' => 700 ),
	),
	'language_overlay' => array(
		'language_policy' => array( 'resolved_languages' => array( 'en', 'es' ), 'transcreation' => 'explicit' ),
	),
	'surface_overlay' => array(
		'surface_policy' => array( 'allow_terms' => true ),
	),
	'experiment_policy' => array(
		'experiment_policy' => array( 'enabled' => true, 'cohort' => 'A' ),
	),
	'request_safe_override' => array(
		'objective' => 'request-objective',
		'priority_policy' => array( 'request_urgency' => 90 ),
	),
	'governance_constraints' => array(
		'production' => array( 'mutation_allowed' => false ),
		'egress' => array( 'registered_only' => true ),
	),
	'dependencies' => array(
		'profile' => array( 'revision' => 1 ),
		'language' => array( 'generation' => 4 ),
		'surface' => array( 'generation' => 8 ),
		'provider' => array( 'generation' => 3 ),
		'budget' => array( 'cycle' => '2026-10' ),
		'schema' => array( 'version' => 1 ),
	),
);
$context = MAD4B_SCP_Search_Runtime_Context::compile( $compile_input );
$check( ! is_wp_error( $context ), 'effective context compile failed' );
$check( 'mad4b.effective-search-context.v1' === $context['contract'], 'effective context contract drifted' );
$check( 'request-objective' === $context['objective'], 'request-safe override did not win deterministic precedence' );
$check( array( 'CA', 'US' ) === $context['resolved_markets'], 'market resolution is not deterministic' );
$check( array( 'en', 'es' ) === $context['resolved_languages'], 'language resolution failed' );
$check( true === $context['surface_policy']['allow_archives'] && true === $context['surface_policy']['allow_terms'], 'surface overlays did not compose' );
$check( 50 === $context['priority_policy']['commercial_weight'] && 10 === $context['priority_policy']['brand_weight'] && 90 === $context['priority_policy']['request_urgency'], 'priority overlay composition failed' );
$check( false === $context['authorizing'], 'compiled context became authorizing' );
$check( false === $context['production_authority_granted'] && false === $context['egress_authority_granted'], 'compiled context widened governance authority' );
$check( true === $context['governance_protected_path_present'], 'governance constraints were not kept distinct from overlays' );
$check( count( $context['reason_chain'] ) >= 5, 'reason chain omitted material overlay decisions' );
$check( 64 === strlen( $context['fingerprint'] ) && 0 === strpos( $context['context_id'], 'search-context-' ), 'compiled context identity missing' );

$context_repeat = MAD4B_SCP_Search_Runtime_Context::compile( $compile_input );
$check( hash_equals( $context['fingerprint'], $context_repeat['fingerprint'] ), 'same compiler inputs produced different fingerprints' );
$check( $context['reason_chain'] === $context_repeat['reason_chain'], 'same compiler inputs produced different reason chains' );

$unsafe_overlay = $compile_input;
$unsafe_overlay['request_safe_override']['allow_unregistered_egress'] = true;
$unsafe = MAD4B_SCP_Search_Runtime_Context::compile( $unsafe_overlay );
$check(
	'mad4b_search_context_security_widening_denied' === $error_code( $unsafe ),
	'business overlay widened egress policy'
);

$unsafe_authority = $compile_input;
$unsafe_authority['market_overlay']['wordpress_mutation_authority'] = true;
$unsafe2 = MAD4B_SCP_Search_Runtime_Context::compile( $unsafe_authority );
$check(
	'mad4b_search_context_security_widening_denied' === $error_code( $unsafe2 ),
	'business overlay widened WordPress mutation authority'
);

$before = array(
	'profile' => array( 'revision' => 1 ),
	'language' => array( 'generation' => 4 ),
	'surface' => array( 'generation' => 8 ),
	'provider' => array( 'generation' => 3 ),
	'budget' => array( 'cycle' => '2026-10', 'used' => 10 ),
	'schema' => array( 'version' => 1 ),
);
$after = $before;
$after['budget'] = array( 'cycle' => '2026-10', 'used' => 20 );
$invalid = MAD4B_SCP_Search_Runtime_Context::invalidate_dependencies( $before, $after );
$check( array( 'BUDGET_DRIFT' ) === $invalid['drift_classes'], 'minimal invalidation classified unrelated drift' );
$check( true === $invalid['preserve_historical_evidence'], 'drift invalidation threatened historical evidence' );
$check( in_array( 'budget_projection', $invalid['invalidate_projections'], true ), 'budget drift did not invalidate budget projection' );
$check( ! in_array( 'surface_projection', $invalid['invalidate_projections'], true ), 'budget drift over-invalidated surface projection' );

$language_after = $before;
$language_after['language'] = array( 'generation' => 5 );
$lang_invalid = MAD4B_SCP_Search_Runtime_Context::invalidate_dependencies( $before, $language_after );
$check( array( 'LANGUAGE_DRIFT' ) === $lang_invalid['drift_classes'], 'language drift classification failed' );
$check( in_array( 'owned_target_projection', $lang_invalid['invalidate_projections'], true ), 'language drift missed owned target invalidation' );
$check( ! in_array( 'provider_route_projection', $lang_invalid['invalidate_projections'], true ), 'language drift over-invalidated provider route' );

$status = MAD4B_SCP_Search_Runtime_Context::status();
$check( 'mad4b.search-context-status.v1' === $status['contract'], 'status projection contract drifted' );
$check( 1 === $status['count'] && false === $status['mutation_performed'] && false === $status['authorizing'], 'status projection is not read-only/non-authorizing' );
$check(
	array( 'search_profile', 'brand_profile', 'market_overlay', 'language_overlay', 'surface_overlay', 'experiment_policy', 'request_safe_override' ) === $status['overlay_precedence'],
	'overlay precedence contract drifted'
);

echo "mad4b.adaptive-search-context-runtime.v1: PASS\n";
