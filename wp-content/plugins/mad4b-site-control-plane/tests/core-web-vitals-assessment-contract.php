<?php
// Regression contract for the live-source CWV observer. No WP or outbound requests.
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-core-web-vitals.php';
function cwv_expect( $actual, $expected, $test ) {
    if ( $actual !== $expected ) {
        fwrite( STDERR, 'FAIL ' . $test . ': expected ' . $expected . ', got ' . $actual . "\n" );
        exit( 1 );
    }
}
$all_good = array( 'metrics' => array(
    'LARGEST_CONTENTFUL_PAINT_MS' => array( 'percentile' => 2500 ),
    'INTERACTION_TO_NEXT_PAINT' => array( 'percentile' => 200 ),
    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => array( 'percentile' => 0.1 ),
) );
cwv_expect( MAD4B_SCP_Core_Web_Vitals::assess_field_data( $all_good )['verdict'], 'passed', 'inclusive_good_thresholds' );
$bad_inp = $all_good;
$bad_inp['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] = 201;
cwv_expect( MAD4B_SCP_Core_Web_Vitals::assess_field_data( $bad_inp )['verdict'], 'failed', 'inp_above_good' );
$missing_inp = $all_good;
unset( $missing_inp['metrics']['INTERACTION_TO_NEXT_PAINT'] );
cwv_expect( MAD4B_SCP_Core_Web_Vitals::assess_field_data( $missing_inp )['verdict'], 'insufficient_data', 'missing_is_not_failure' );
$invalid_cls = $all_good;
$invalid_cls['metrics']['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] = 120;
cwv_expect( MAD4B_SCP_Core_Web_Vitals::assess_field_data( $invalid_cls )['verdict'], 'insufficient_data', 'invalid_cls_not_guess' );
echo "MAD4B_CWV_FIELD_DATA_CONTRACT_PASS (4 checks)\n";
