<?php
/* Hermetic fail-closed regression for both generation snapshot boundaries. */
define( 'ABSPATH', __DIR__ );
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error {
    private $code; private $data;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
class MAD4B_SCP_Read_Consistency {
    public static $count = 0; public static $throw_at = 1;
    public static function snapshot_header( $input ) {
        self::$count++;
        if ( self::$throw_at === self::$count ) throw new RuntimeException( 'simulated secret failure' );
        return array( 'runtime_generation' => str_repeat( 'a', 64 ), 'snapshot_id' => 'test' );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-connector-resilience.php';
$read_calls = 0; $project_calls = 0;
$read = static function () use ( &$read_calls ) { $read_calls++; return array( 'ok' => true ); };
$project = static function () use ( &$project_calls ) { $project_calls++; return array( 'ok' => true ); };
foreach ( array( 1 => 'before_read', 2 => 'after_read' ) as $step => $phase ) {
    MAD4B_SCP_Read_Consistency::$count = 0;
    MAD4B_SCP_Read_Consistency::$throw_at = $step;
    $read_calls = 0; $project_calls = 0;
    $response = MAD4B_SCP_Connector_Resilience::generation_fenced_compact_read( 'test', $read, $project );
    if ( ! is_wp_error( $response ) || 'mad4b_generation_fenced_read_snapshot_exception' !== $response->get_error_code() )
        throw new RuntimeException( 'Expected a contained snapshot failure: ' . $phase );
    $data = $response->get_error_data();
    if ( ( $data['phase'] ?? '' ) !== $phase || ! empty( $data['mutation_performed'] ) || ! empty( $data['raw_error_message_exposed'] )
        || $project_calls !== 0 || $read_calls !== $step - 1 )
        throw new RuntimeException( 'Snapshot error violated fail-closed guarantees: ' . $phase );
}
echo "PASS: GENERATION_SNAPSHOT_EXCEPTION_RUNTIME (2 cases)\n";
