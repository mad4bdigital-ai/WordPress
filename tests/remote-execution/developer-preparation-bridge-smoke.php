<?php
/** Source-only regression: signed Developer info enrichment remains governed. */
define( 'ABSPATH', __DIR__ . '/../../' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class MAD4B_SCP_Capability_Descriptor_Registry {
    public static function describe( $name ) {
        if ( ! empty( $GLOBALS['missing'] ) ) return new WP_Error( 'fixture_descriptor_missing' );
        return array(
            'ability_name' => $name,
            'execution_eligible' => empty( $GLOBALS['ineligible'] ),
            'execution_lane' => ! empty( $GLOBALS['wrong_lane'] ) ? 'admin' : 'developer',
            'input_schema_sha256' => ! empty( $GLOBALS['schema_drift'] ) ? str_repeat( 'b', 64 ) : str_repeat( 'a', 64 ),
            'classification_sha256' => str_repeat( 'c', 64 ),
        );
    }
}
class MAD4B_SCP_Ability_Catalog_Transport {
    public static function current_authority_scope() { return str_repeat( 'd', 64 ); }
}
class MAD4B_SCP_Preparation_Receipt {
    const CONTRACT = 'mad4b.preparation-receipt.fixture.v1';
    public static function issue( $row ) {
        return ! empty( $GLOBALS['receipt_denied'] ) ? new WP_Error( 'fixture_receipt_denied' ) : 'signed-descriptor-fixture';
    }
}
class MAD4B_SCP_Impact_Policy {
    public static function classify( $name, $provider, $input ) {
        return array( 'approval_lane' => ! empty( $GLOBALS['human_only'] ) ? 'human_only' : 'ai_autonomous' );
    }
}
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-developer-preparation-bridge.php';
function check( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $label . PHP_EOL ); exit( 2 ); }
    echo 'PASS ' . $label . PHP_EOL;
}
$input = array( 'input_schema_sha256' => str_repeat( 'a', 64 ), 'ability_name' => 'mad4b/developer-runtime-status' );
$ready = MAD4B_SCP_Developer_Preparation_Bridge::enrich( $input, 'mad4b/developer-runtime-status' );
check( is_array( $ready ) && ( $ready['prepared_dispatch']['preparation_receipt'] ?? '' ) === 'signed-descriptor-fixture'
    && ( $ready['prepared_dispatch']['expected_execution_lane'] ?? '' ) === 'developer'
    && ( $ready['dispatch_tool'] ?? '' ) === 'mad4b/developer-execute'
    && ( $ready['preparation_contract'] ?? '' ) === MAD4B_SCP_Preparation_Receipt::CONTRACT
    && ( $ready['prepared_dispatch']['expected_authority_scope_sha256'] ?? '' ) === str_repeat( 'd', 64 ),
    'signed exact Developer info contract' );
check( MAD4B_SCP_Developer_Preparation_Bridge::approval_lane( 'demo/read', true ) === 'none',
    'read-only approval lane' );
check( MAD4B_SCP_Developer_Preparation_Bridge::approval_lane( 'demo/write', false ) === 'ai_autonomous',
    'classify normal write approval lane' );
$GLOBALS['human_only'] = true;
check( MAD4B_SCP_Developer_Preparation_Bridge::approval_lane( 'demo/write', false ) === 'human_only',
    'human-only approval preserved' );
foreach ( array(
    'missing' => 'fixture_descriptor_missing',
    'ineligible' => 'mad4b_developer_preparation_not_eligible',
    'wrong_lane' => 'mad4b_developer_preparation_not_eligible',
    'schema_drift' => 'mad4b_developer_preparation_schema_drift',
    'receipt_denied' => 'fixture_receipt_denied',
) as $flag => $expected ) {
    $GLOBALS[$flag] = true;
    $result = MAD4B_SCP_Developer_Preparation_Bridge::enrich( $input, 'mad4b/developer-runtime-status' );
    check( is_wp_error( $result ) && $result->get_error_code() === $expected, $flag . ' fails closed' );
    unset( $GLOBALS[$flag] );
}
echo 'PASS developer preparation did not create any grant or execute a tool' . PHP_EOL;
