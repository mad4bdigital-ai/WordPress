<?php
// Standalone, read-only contract check: no WordPress bootstrap, DB or HTTP.
define( 'ABSPATH', __DIR__ );
class WP_Error {
	private $code;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require_once __DIR__ . '/../includes/class-mad4b-scp-elementor-native-template-parity.php';

function duplicate( $value ) { return json_decode( json_encode( $value ), true ); }
function assert_result( $label, $before, $after, $expected ) {
	$result = MAD4B_SCP_Elementor_Native_Template_Parity::verify( $before, $after );
	$actual = is_wp_error( $result ) ? $result->get_error_code() : 'allowed';
	if ( $expected !== $actual ) { fwrite( STDERR, $label . ': expected ' . $expected . ', got ' . $actual . PHP_EOL ); exit( 1 ); }
	echo 'PASS ' . $label . PHP_EOL;
}
$base = array( array(
	'id' => 'root', 'elType' => 'container', 'settings' => array(
		'jedv_conditions' => array( array( 'jedv_condition' => 'query-has-items', 'query_id' => '19' ) ),
		'display_condition_list' => array( array( 'display_condition_login_status' => 'subscriber' ) ),
	),
	'elements' => array(
		array( 'id' => 'included', 'elType' => 'container', 'settings' => array(),
			'elements' => array( array( 'id' => 'list1', 'elType' => 'widget', 'widgetType' => 'jet-listing-grid', 'settings' => array( 'query_id' => 19 ) ) ) ),
		array( 'id' => 'excluded', 'elType' => 'container', 'settings' => array(),
			'elements' => array(
				array( 'id' => 'list2', 'elType' => 'widget', 'widgetType' => 'jet-listing-grid', 'settings' => array( 'query_id' => 20 ) ),
				array( 'id' => 'card', 'elType' => 'widget', 'widgetType' => 'icon-box', 'settings' => array( 'title_text' => 'Make it yours' ) ),
			),
		),
	),
) );
assert_result( 'no-change', $base, $base, 'allowed' );
$changed = duplicate( $base );
$changed[0]['elements'][1]['elements'][1]['settings']['title_text'] = 'Localized native text';
assert_result( 'edit-existing-icon-box', $base, $changed, 'allowed' );
$changed = duplicate( $base );
$changed[0]['elements'][1]['elements'][] = array( 'id' => 'new-icon', 'elType' => 'widget', 'widgetType' => 'icon-box', 'settings' => array( 'title_text' => 'Enhance the tour' ) );
assert_result( 'add-native-widget-under-existing-conditions', $base, $changed, 'allowed' );
$changed = duplicate( $base );
$changed[0]['elements'][0]['elements'][0]['settings']['query_id'] = 33;
assert_result( 'existing-provider-settings-may-be-edited-with-provider-authority', $base, $changed, 'allowed' );
$changed = duplicate( $base );
$changed[0]['settings']['jedv_conditions'][0]['query_id'] = '27';
assert_result( 'protect-jetengine-visibility-query', $base, $changed, 'mad4b_elementor_native_parity_drift' );
$changed = duplicate( $base );
unset( $changed[0]['settings']['jedv_conditions'] );
assert_result( 'protect-original-conditions', $base, $changed, 'mad4b_elementor_native_parity_drift' );
$changed = duplicate( $base );
array_shift( $changed[0]['elements'][1]['elements'] );
assert_result( 'protect-original-jetengine-listing', $base, $changed, 'mad4b_elementor_native_parity_owner_removed' );
$changed = duplicate( $base );
$changed[0]['elements'][1]['elements'][] = array( 'id' => 'clone-list', 'elType' => 'widget', 'widgetType' => 'jet-listing-grid', 'settings' => array() );
assert_result( 'block-parallel-jetengine-listing', $base, $changed, 'mad4b_elementor_native_parity_parallel_surface' );
$changed = duplicate( $base );
$changed[0]['elements'][1]['elements'][1]['settings']['display_condition_list'] = array( array( 'display_condition_login_status' => 'subscriber' ) );
assert_result( 'no-new-independent-display-condition', $base, $changed, 'mad4b_elementor_native_parity_parallel_surface' );
$changed = array( array( 'id' => 'wrapper', 'elType' => 'container', 'settings' => array(), 'elements' => duplicate( $base ) ) );
assert_result( 'protect-visibility-owner-ancestry', $base, $changed, 'mad4b_elementor_native_parity_drift' );
$changed = duplicate( $base );
$changed[] = array( 'id' => 'independent', 'elType' => 'widget', 'widgetType' => 'icon-box', 'settings' => array() );
assert_result( 'unrelated-widget-allowed', $base, $changed, 'allowed' );
echo 'RESULT 11/11 passed' . PHP_EOL;
