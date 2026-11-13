<?php
/** Effect-free native fixture for conservative registered source coverage. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class MAD4B_SCP_Policy {
    public static function can_read() { return ! empty( $GLOBALS['fixture_can_read'] ); }
}
class MAD4B_SCP_ChatGPT_Tool_Projection {
    public static function all_site_ability_names() { return array( 'fixture/a', 'fixture/b' ); }
}
class MAD4B_SCP_Operation_Registry {
    public static function status() { ++$GLOBALS['operation_scans']; return array( 'ready' => false, 'state' => 'registered_ability_gap', 'count' => 3 ); }
}
class MAD4B_SCP_Skill_Registry {
    const MAX_SCAN_SKILLS = 500;
    public static function status() { ++$GLOBALS['skill_scans']; return array( 'skill_count' => 500 ); }
}
class MAD4B_SCP_Remote_Operation_Parity {
    public static function status() {
        ++$GLOBALS['remote_scans']; return array( 'ready' => false, 'operation_count' => 12, 'rejected_registration_count' => 4 );
    }
}
require __DIR__ . '/../includes/class-mad4b-scp-capability-source-inventory.php';
$assert = static function ( $v, $message ) { if ( ! $v ) throw new RuntimeException( $message ); };
$GLOBALS['fixture_can_read'] = true;
$GLOBALS['operation_scans'] = $GLOBALS['skill_scans'] = $GLOBALS['remote_scans'] = 0;
$x = MAD4B_SCP_Capability_Source_Inventory::inspect();
$assert( ! is_wp_error( $x ) && 'summary' === $x['mode'] && 5 === $x['source_count'], 'Summary source registry incomplete' );
$assert( 0 === $GLOBALS['operation_scans'] && 0 === $GLOBALS['skill_scans'] && 0 === $GLOBALS['remote_scans'], 'Summary triggered costly provider census' );
$assert( $x['sources'][0]['coverage_state'] === 'NOT_INSPECTED', 'Summary claimed unverified coverage' );
$rows = array();
foreach ( array( 'wordpress_abilities', 'operation_registry', 'managed_skills', 'remote_operation_parity', 'host_authorized_external_federation' ) as $name ) {
    $detail = MAD4B_SCP_Capability_Source_Inventory::inspect( array( 'source_id' => $name ) );
    $assert( ! is_wp_error( $detail ) && 1 === $detail['source_count'], 'Detail must inspect exactly one source' );
    $rows[] = $detail['sources'][0];
}
$assert( 1 === $GLOBALS['operation_scans'] && 1 === $GLOBALS['skill_scans'] && 1 === $GLOBALS['remote_scans'], 'Selected providers scanned more than once' );
$x['sources'] = $rows;
$assert( ! is_wp_error( $x ) && $x['source_count'] === 5 && count( $x['sources'] ) === 5, 'Missing a registered source family' );
$lookup = array();
foreach ( $x['sources'] as $row ) $lookup[$row['source_id']] = $row;
$assert( $lookup['wordpress_abilities']['observed_count'] === 2, 'WordPress source count was omitted' );
$assert( $lookup['operation_registry']['coverage_state'] === 'PARTIAL_OR_UNAVAILABLE', 'Operation registry gaps were hidden' );
$assert( $lookup['managed_skills']['coverage_state'] === 'TRUNCATED_OR_UNKNOWN', 'Skill scan ceiling was hidden' );
$assert( $lookup['remote_operation_parity']['coverage_state'] === 'PARTIAL_OR_REJECTED', 'Rejected operation registration was hidden' );
$assert( $lookup['host_authorized_external_federation']['coverage_state'] === 'EXTERNAL_ATTESTATION_REQUIRED', 'Unconnected external federation was invented' );
$assert( !$x['complete_federation_proven'] && !$x['mutation_performed'], 'Source inventory claimed authorization' );
$assert( is_wp_error( MAD4B_SCP_Capability_Source_Inventory::inspect( array( 'source_id' => 'unknown' ) ) ), 'Unknown source was silently accepted' );
$GLOBALS['fixture_can_read'] = false;
$assert( is_wp_error( MAD4B_SCP_Capability_Source_Inventory::inspect() ), 'Unauthenticated source census succeeded' );
echo "PASS unified source inventory: lazy summary, per-source details, partial/rejected/unknown coverage and policy gate\\n";
