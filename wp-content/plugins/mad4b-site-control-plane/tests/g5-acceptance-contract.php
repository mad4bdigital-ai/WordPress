<?php
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error { public function __construct( $code, $message = '' ) {} }
function add_action( $hook, $callback, $priority = 10 ) {}
function wp_has_ability( $name ) { return false; }
function wp_register_ability( $name, $args ) {}
class MAD4B_SCP_G5_External_Providers { public static function can_manage( $input = null ) { return true; } }
class MAD4B_SCP_G5_Provider_Profiles { const CONTRACT = 'mad4b.feature007-g5-provider-reference-profiles.v1'; }
class MAD4B_SCP_G5_SEO_Provider_Families { const CONTRACT = 'mad4b.feature007-g5-seo-provider-family.v1'; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g5-acceptance.php';

function g5_accept_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . PHP_EOL ); exit( 1 ); }
}
$status = MAD4B_SCP_G5_Acceptance::status();
g5_accept_assert( 15 === count( $status['task_ids'] ), 'G5 task ownership must remain exact' );
g5_accept_assert( 'blocked' === $status['adversarial_matrix']['budget_exhausted_or_uncertain_charge'], 'uncertain charge must fail closed' );
g5_accept_assert( 'blocked' === $status['adversarial_matrix']['unknown_protocol_or_client_defined_provider'], 'unknown protocol/provider identity must fail closed' );
g5_accept_assert( 'preserve_and_review' === $status['adversarial_matrix']['seo_provider_coexistence_conflict'], 'SEO coexistence must preserve and review conflicts' );
g5_accept_assert( 'denied' === $status['adversarial_matrix']['signal_driven_content_mutation'], 'growth signal cannot authorize content mutation' );
g5_accept_assert( false === $status['live_provider_acceptance'] && false === $status['production_authorized'] && false === $status['authorizing'], 'repository acceptance cannot claim live or Production authority' );
echo "mad4b.feature007-g5-acceptance.v1: PASS\n";
