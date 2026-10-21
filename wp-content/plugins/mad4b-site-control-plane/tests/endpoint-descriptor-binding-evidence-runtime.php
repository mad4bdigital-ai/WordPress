<?php
/** Regression: a descriptor binding failure must remain visible to endpoint diagnostics. */
define( 'ABSPATH', __DIR__ );
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class MAD4B_SCP_Capability_Descriptor_Registry {
    public static function binding( $ability, $consumer ) {
        if ( $ability === 'mad4b/missing-descriptor' ) return new WP_Error( 'mad4b_chatgpt_projection_ability_unavailable' );
        return array( 'contract' => 'mad4b.capability-descriptor-consumer-binding.v1', 'ability_name' => $ability, 'consumer' => $consumer );
    }
}
require __DIR__ . '/../includes/class-mad4b-scp-server-registration-evidence.php';
$check = static function ( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); };
$evidence = MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence( 'mad4b-enrollment', array( 'mad4b/site-info', 'mad4b/missing-descriptor' ) );
$check( $evidence['ready'] === false && count( $evidence['bindings'] ) === 1 && count( $evidence['blockers'] ) === 1, 'Binding failure silently lost' );
$check( $evidence['blockers'][0] === 'mad4b/missing-descriptor:mad4b_chatgpt_projection_ability_unavailable', 'Failing Ability and code absent' );
$source = file_get_contents( __DIR__ . '/../includes/class-mad4b-scp-connection-status.php' );
$expected = "\$descriptor = isset( \$row['capability_descriptor_evidence'] ) && is_array( \$row['capability_descriptor_evidence'] ) ? \$row['capability_descriptor_evidence'] : array();";
$check( strpos( $source, $expected ) !== false, 'Endpoint reads non-existent registration evidence key' );
$check( strpos( $source, "\$descriptor_blockers = isset( \$descriptor['blockers'] )" ) !== false, 'Endpoint stopped exposing redacted failures' );
echo "PASS enrollment descriptor failure evidence and endpoint diagnostic key parity\n";
