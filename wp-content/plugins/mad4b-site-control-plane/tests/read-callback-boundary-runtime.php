<?php
/** Read permission/execution faults stay failures, never protocol crashes or retries. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $code; private $message; private $data;
 function __construct( $c, $m = '', $d = array() ) { $this->code=$c; $this->message=$m; $this->data=$d; }
 function get_error_code() { return $this->code; }
 function get_error_message() { return $this->message; }
 function get_error_data() { return $this->data; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-connector-resilience.php';
function boundary_check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
$log = tempnam( sys_get_temp_dir(), 'mad4b-read-boundary-' ); ini_set( 'error_log', $log );
$base = array( 'meta'=>array( 'annotations'=>array( 'readonly'=>true ) ),
 'permission_callback'=>static function($input) { return true; },
 'execute_callback'=>static function($input) { return $input; } );
try {
 foreach ( array( 'permission_callback'=>'permission', 'execute_callback'=>'execution' ) as $field=>$stage ) {
  $calls=0; $input=$base;
  $input[$field]=static function($v) use (&$calls) { ++$calls; throw new TypeError('secret-provider-token-123'); };
  $wrapped=MAD4B_SCP_Connector_Resilience::wrap_read_registration($input,'context/brand-core-coverage');
  $failure=$wrapped[$field](array('secret'=>'not logged'));
  boundary_check($failure instanceof WP_Error && 'mad4b_read_'.$stage.'_exception'===$failure->get_error_code(), 'fault became success/unclassified error');
  boundary_check(1===$calls && false===$failure->get_error_data()['blind_retry_allowed'], 'callback fault retried');
  boundary_check(false===$failure->get_error_data()['permission_granted_by_boundary'], 'fault granted permission');
  boundary_check(strpos($failure->get_error_message(),$failure->get_error_data()['correlation_id'])!==false, 'MCP message lost correlation');
  boundary_check(strpos(json_encode($failure->get_error_data()).$failure->get_error_message(),'secret')===false, 'secret exception/input exposed');
  $again=$wrapped[$field](null);
  boundary_check($again->get_error_data()['correlation_id']!==$failure->get_error_data()['correlation_id'], 'correlation reused');
 }
 foreach (array(true,false,new WP_Error('provider_denied','safe denial'),array('state'=>'blocked')) as $result) {
  $input=$base; $input['permission_callback']=static function($v) use ($result) { return $result; };
  $input['execute_callback']=$input['permission_callback'];
  $wrapped=MAD4B_SCP_Connector_Resilience::wrap_read_registration($input,'mad4b/site-profile-status');
  boundary_check($result===$wrapped['permission_callback'](null) && $result===$wrapped['execute_callback'](null), 'original result/denial changed');
 }
 foreach(array(false,'true',null) as $readonly) {
  $input=$base; $input['meta']['annotations']['readonly']=$readonly;
  boundary_check($input===MAD4B_SCP_Connector_Resilience::wrap_read_registration($input,'mad4b/write-operation'), 'write or unknown operation wrapped');
 }
 boundary_check($base===MAD4B_SCP_Connector_Resilience::wrap_read_registration($base,'other/provider'), 'unrelated provider registration changed');
 $entries=file_get_contents($log);
 boundary_check(strpos($entries,'MAD4B_READ_CALLBACK_FAILURE')!==false && strpos($entries,'secret')===false && strpos($entries,__FILE__)===false, 'diagnostic absent or secret/file path logged');
} finally { unlink($log); }
echo "READ_CALLBACK_BOUNDARY: PASS\n";
