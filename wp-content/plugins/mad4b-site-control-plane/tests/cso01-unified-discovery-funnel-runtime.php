<?php
/** Native isolated regression: writes must not crowd out safe read discovery. */
define( 'ABSPATH', '/fixture/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class MAD4B_SCP_Policy {
    public static function can_read() { return true; }
}
class MAD4B_SCP_ChatGPT_Tool_Projection {
    public static function all_site_ability_names() { return array_keys( $GLOBALS['gateway_abilities'] ); }
    public static function can_apply() { return new WP_Error( 'projection_not_authorized' ); }
}
class CSO_Gateway_Mock_Ability {
    private $args;
    public function __construct( $args ) { $this->args = $args; }
    public function get_meta() { return $this->args['meta']; }
    public function get_label() { return $this->args['label']; }
    public function get_description() { return $this->args['description'] ?? 'travel guide'; }
    public function get_category() { return $this->args['category'] ?? 'travel'; }
}
function wp_has_ability( $name ) { return isset( $GLOBALS['gateway_abilities'][ $name ] ); }
function wp_get_ability( $name ) { return new CSO_Gateway_Mock_Ability( $GLOBALS['gateway_abilities'][ $name ] ); }
function fail_cso( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
$GLOBALS['gateway_abilities'] = array();
for ( $i = 0; $i < 40; $i++ ) {
    $name = sprintf( 'aaa-write/edit-%03d', $i );
    $GLOBALS['gateway_abilities'][ $name ] = array(
        'label' => 'travel guide editor',
        'meta' => array( 'annotations' => array( 'readonly' => false ) ),
    );
}
$GLOBALS['gateway_abilities']['zzz-read/inspect'] = array(
    'label' => 'travel guide read',
    'meta' => array( 'annotations' => array( 'readonly' => true ) ),
);
$GLOBALS['gateway_abilities']['zzz-read/arabic'] = array(
    'label' => 'إعدادات الرِّحلة',
    'description' => 'Localized fields',
    'category' => 'locale',
    'meta' => array( 'annotations' => array( 'readonly' => true ) ),
);
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-unified-capability-gateway.php';
$normal = MAD4B_SCP_Unified_Capability_Gateway::search( array(
    'task' => 'travel guide', 'limit' => 25 ) );
fail_cso( 25 === $normal['count'] && ! $normal['declared_readonly_filter_applied'],
    'baseline mixed search changed unexpectedly' );
$filtered = MAD4B_SCP_Unified_Capability_Gateway::search( array(
    'task' => 'travel guide', 'limit' => 25,
    'declared_readonly_only' => true ) );
fail_cso( ! is_wp_error( $filtered ) && 1 === $filtered['count']
    && 'zzz-read/inspect' === $filtered['items'][0]['ability_name']
    && true === $filtered['items'][0]['declared_readonly']
    && true === $filtered['declared_readonly_filter_applied']
    && 'bounded_declared_readonly_metadata_relevance' === $filtered['search_mode']
    && 'none' === $filtered['authority_effect'],
    'read Ability was crowded out by high-rank write matches' );
$invalid = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
    'action' => 'search', 'task' => 'travel guide',
    'declared_readonly_only' => 'true' ), 'internal' );
fail_cso( is_wp_error( $invalid )
    && 'mad4b_capability_gateway_read_filter_invalid' === $invalid->get_error_code(),
    'string read filter silently changed gateway classification' );
$arabic = MAD4B_SCP_Unified_Capability_Gateway::search( array(
    'task' => 'اعدادات الرحلة',
    'declared_readonly_only' => true,
    'limit' => 25 ) );
fail_cso( ! is_wp_error( $arabic ) && 1 === $arabic['count']
    && 'zzz-read/arabic' === $arabic['items'][0]['ability_name'],
    'Arabic Alef/tashkeel normalization failed on shared gateway search' );
echo "mad4b.cso01.unified-readonly-funnel.v1: PASS\n";
