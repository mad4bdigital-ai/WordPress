<?php
/** Stateful installation/enrollment/recovery matrix with shipped profile and lease. */
$root = sys_get_temp_dir() . '/mad4b-profile-journey-' . getmypid();
define( 'ABSPATH', $root . '/' ); define( 'WP_PLUGIN_DIR', $root . '/plugins' ); define( 'WPMU_PLUGIN_DIR', $root . '/mu' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' ); define( 'MAD4B_SCP_FILE', MAD4B_SCP_DIR . 'mad4b-site-control-plane.php' );
class WP_Error { private $code; function __construct( $code, $message = '', $data = null ) { $this->code=$code; } function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function wp_normalize_path( $v ) { return str_replace( '\\', '/', $v ); }
function home_url( $v = '' ) { return rtrim( $GLOBALS['home'], '/' ) . '/' . ltrim( $v, '/' ); }
function wp_parse_url( $v, $part = -1 ) { return parse_url( $v, $part ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_get_environment_type() { return $GLOBALS['wordpress']; }
function apply_filters( $tag, $v, ...$args ) { return 'mad4b_scp_wordpress_environment_explicit' === $tag ? $GLOBALS['explicit'] : $v; }
function current_user_can( $c ) { return $GLOBALS['admin']; }
function get_current_user_id() { return 7; }
function get_userdata( $id ) { return in_array( $id, array( 7, 8 ), true ) ? (object) array( 'ID' => $id ) : false; }
function get_bloginfo( $k ) { return 'Journey'; }
function wp_generate_uuid4() { static $n=0; return sprintf( '11111111-1111-4111-8111-%012d', ++$n ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { if ( $GLOBALS['drop_option'] === $k ) return false; $GLOBALS['options'][ $k ]=$v; return true; }
function add_option( $k, $v, $deprecated = '', $autoload = null ) { if ( array_key_exists( $k, $GLOBALS['options'] ) ) return false; return update_option( $k, $v ); }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function do_action( $tag, ...$args ) { if ( 'mad4b_scp_site_profile_saved' === $tag ) { ++$GLOBALS['saved_hooks']; MAD4B_SCP_MCP_Runtime_Recovery::profile_saved(); } }
function is_admin() { return true; }
function wp_doing_cron() { return false; }
function wp_unslash( $v ) { return $v; }
function wp_verify_nonce( $v, $action ) { return 'valid' === $v; }
function wp_mkdir_p( $v ) { return mkdir( $v, 0700, true ); }
function plugin_basename( $v ) { return 'mad4b-site-control-plane/mad4b-site-control-plane.php'; }
function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled'][ $hook ] ?? false; }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['scheduled'][ $hook ]=$when; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['scheduled'][ $hook ] ); }
class MAD4B_SCP_MCP_Request_Scope { static function current_request_is_protocol_hotpath() { return $GLOBALS['protocol']; } static function current_request_is_endpoint_diagnostic_job() { return false; } }
class MAD4B_SCP_Dependency_Manager { static function mcp_adapter_disk_integrity() { return array( 'ready' => $GLOBALS['integrity'] ); } }
class MAD4B_SCP_Endpoint_Diagnostic { static function build_fingerprint() { return 'journey-build'; } }
class MAD4B_SCP_Audit {
 static function storage_status() { return array( 'ready' => $GLOBALS['audit_ready'] ); }
 static function record( $event, $data, $state ) {
  if ( $GLOBALS['audit_fail'] ) return new WP_Error( 'fixture_audit_failed' );
  if ( $GLOBALS['lose_lease'] && 'mad4b/mcp-runtime-class-set-repair-armed' === $event ) $GLOBALS['options'][MAD4B_SCP_Runtime_Maintenance_Lease::OPTION]['token']='other-worker';
  return true;
 }
}
foreach ( array( 'site-profile', 'runtime-maintenance-lease', 'mcp-mu-bootstrap-refresh', 'mcp-runtime-conflict-guard', 'mcp-runtime-recovery' ) as $file ) require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-' . $file . '.php';
function journey_check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $GLOBALS['label'] . ': ' . $message ); ++$GLOBALS['assertions']; }
function journey_remove( $path ) { if ( is_dir( $path ) && ! is_link( $path ) ) { foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $name ) journey_remove( $path . '/' . $name ); rmdir( $path ); } elseif ( file_exists( $path ) || is_link( $path ) ) unlink( $path ); }
register_shutdown_function( function () use ( $root ) { journey_remove( $root ); } );
mkdir( WP_PLUGIN_DIR . '/mcp-adapter', 0700, true ); mkdir( WPMU_PLUGIN_DIR, 0700, true );
$destination=WPMU_PLUGIN_DIR . '/000-mad4b-mcp-adapter-bootstrap.php';
$GLOBALS['assertions']=0; $journeys=0;
// Host sync is a profile preference plus a separately verified host operation.
// Neither explicit Production nor a missing deployment secret can be overridden.
$sync_cases = array(
 array('profile_only','staging','production',false,true,false,'profile_only'),
 array('profile_only','production','production',true,true,false,'profile_only'),
 array('host_managed','staging','production',false,false,false,'blocked_profile_identity'),
 array('host_managed','staging','production',false,true,false,'blocked_missing_deployment_binding'),
 array('host_managed','staging','production',false,true,true,'awaiting_host_bootstrap'),
 array('host_managed','staging','staging',true,true,true,'host_aligned'),
 array('host_managed','staging','production',true,true,true,'blocked_explicit_host_conflict'),
 array('host_managed','production','production',true,true,true,'blocked_production_or_invalid_target'),
 array('host_managed','invalid','production',false,true,true,'blocked_production_or_invalid_target'),
 array('invented','staging','staging',true,true,true,'blocked_invalid_mode'),
 array('host_managed','development','development',true,true,true,'host_aligned'),
 array('host_managed','local','production',false,true,true,'awaiting_host_bootstrap')
);
foreach($sync_cases as $case) {
 $actual = MAD4B_SCP_Site_Profile::host_environment_sync_assessment($case[0],$case[1],$case[2],$case[3],$case[4],$case[5]);
 journey_check($actual === $case[6], 'host-managed synchronization did not fail closed: ' . implode(':',$case));
}

putenv( 'WP_ENVIRONMENT_TYPE' );
$_SERVER['REQUEST_METHOD']='POST'; $_POST=array( 'action'=>'mad4b_repair_mcp_runtime', 'nonce'=>'valid', 'build'=>'journey-build' );
$environments=array( 'local', 'development', 'staging', 'production' );
$homes=array( 'https://tenant.example', 'https://staging.tenant.example/subdirectory', 'https://tenant.example:8443', 'https://127.0.0.1', 'https://[::1]' );
foreach ( $homes as $home ) foreach ( $environments as $wordpress ) foreach ( $environments as $selected ) foreach ( array( false, true ) as $explicit ) {
 ++$journeys; $label="$home:$wordpress:$selected:" . ( $explicit ? 'explicit' : 'implicit' );
 $options=array( 'active_plugins'=>array( 'mcp-adapter/mcp-adapter.php', 'mad4b-site-control-plane/mad4b-site-control-plane.php' ), 'fixture_grants'=>array( 'sentinel'=>'must-not-change' ) );
 $scheduled=array(); $admin=true; $audit_ready=true; $audit_fail=false; $saved_hooks=0; $drop_option=''; $integrity=true; $lose_lease=false; $protocol=false;
 if ( file_exists( $destination ) ) unlink( $destination ); MAD4B_SCP_Site_Profile::reset_cache();
 journey_check( ! MAD4B_SCP_Site_Profile::configured() && ! MAD4B_SCP_Site_Profile::oauth_enabled() && ! MAD4B_SCP_Site_Profile::write_enabled() && ! MAD4B_SCP_Site_Profile::skills_enabled(), 'fresh install granted authority' );
 MAD4B_SCP_MCP_Runtime_Recovery::schedule(); journey_check( empty( $scheduled ), 'fresh install scheduled repair' );
 $sync_requested = $explicit && $wordpress === $selected && 'production' !== $selected ? 'host_managed' : 'profile_only';
 $input=array( 'environment'=>$selected, 'environment_sync_mode'=>$sync_requested, 'expected_revision'=>0, 'oauth_user_ids'=>array(7), 'oauth_enabled'=>true, 'skills_enabled'=>true, 'managed_runtime_enabled'=>true, 'write_enabled'=>false );
 if(!$explicit && 'production'===$wordpress && 'production'!==$selected){$input['nonproduction_override_confirmed']=true;$input['nonproduction_override_confirmation']=MAD4B_SCP_Site_Profile::NONPRODUCTION_OVERRIDE_CONFIRMATION;}
 $result=MAD4B_SCP_Site_Profile::save_current_site( $input );
 // Without a WP declaration, only the implicit Production default can be overridden.
 $conflict=$explicit && $wordpress!==$selected;
 if ( $conflict ) {
  journey_check( is_wp_error( $result ) && 'mad4b_site_profile_environment_conflicts_explicit_wordpress'===$result->get_error_code(), 'explicit environment conflict accepted' );
  journey_check( false===get_option( MAD4B_SCP_Site_Profile::OPTION ) && 0===$saved_hooks && empty($scheduled), 'denied enrollment persisted/scheduled' ); continue;
 }
 journey_check( ! is_wp_error( $result ), 'valid enrollment failed' );
 $enrolled_sync = MAD4B_SCP_Site_Profile::status();
 journey_check( $enrolled_sync['environment_sync_mode'] === $sync_requested, 'synchronization mode was not persisted with exact Site Profile identity' );
 journey_check( $sync_requested !== 'host_managed' || $enrolled_sync['environment_sync_state'] === 'blocked_missing_deployment_binding', 'host-managed mode claimed synchronization without host binding' );
 $effective=$wordpress===$selected || 'production'===$wordpress ? $selected : $wordpress;
 $enrolled=$effective===$selected;
 journey_check( $effective===MAD4B_SCP_Site_Profile::current_environment() && $enrolled===MAD4B_SCP_Site_Profile::origin_enrolled(), 'effective environment mismatch' );
 $eligible=$enrolled && 'production'!==$selected;
 journey_check( $eligible===MAD4B_SCP_Site_Profile::early_managed_runtime_binding()['eligible'] && $eligible===isset($scheduled[MAD4B_SCP_MCP_Runtime_Recovery::HOOK]), 'regular/MU/scheduled eligibility diverged' );
 $uuid=MAD4B_SCP_Site_Profile::site_uuid(); $before=get_option(MAD4B_SCP_Site_Profile::OPTION); $hook_count=$saved_hooks;
 $stale=MAD4B_SCP_Site_Profile::save_current_site($input);
 journey_check( is_wp_error($stale) && 'mad4b_site_profile_stale'===$stale->get_error_code() && $before===get_option(MAD4B_SCP_Site_Profile::OPTION) && $hook_count===$saved_hooks, 'stale browser form mutated enrollment' );
 $input['expected_revision']=1; $input['write_enabled']=true;
 if ('production'===$selected) {
  $blocked=MAD4B_SCP_Site_Profile::save_current_site($input);
  journey_check(is_wp_error($blocked) && 'mad4b_site_profile_production_write_confirmation_required'===$blocked->get_error_code() && $before===get_option(MAD4B_SCP_Site_Profile::OPTION), 'Production write missing acknowledgement accepted');
  $input['production_write_confirmed']=true; $input['production_write_confirmation']=MAD4B_SCP_Site_Profile::PRODUCTION_WRITE_CONFIRMATION;
 }
 $updated=MAD4B_SCP_Site_Profile::save_current_site($input);
 journey_check(!is_wp_error($updated) && 2===MAD4B_SCP_Site_Profile::revision() && $uuid===MAD4B_SCP_Site_Profile::site_uuid(), 'same identity update failed');
 $persisted=get_option(MAD4B_SCP_Site_Profile::OPTION);
 $recover=MAD4B_SCP_MCP_Runtime_Recovery::run('',true);
 journey_check($eligible ? !is_wp_error($recover) && is_file($destination) && false===$recover['connection_certified'] : is_wp_error($recover) && !file_exists($destination), 'recovery eligibility result wrong');
 journey_check($persisted===get_option(MAD4B_SCP_Site_Profile::OPTION) && array('sentinel'=>'must-not-change')===$options['fixture_grants'], 'repair changed profile/grants');
 journey_check(false===get_option(MAD4B_SCP_Runtime_Maintenance_Lease::OPTION) && !MAD4B_SCP_MCP_Runtime_Recovery::active(), 'lease/privilege leaked');
 // A database copy to a different origin quarantines every feature immediately.
 $original_home=$home; $GLOBALS['home']='https://clone.invalid'; MAD4B_SCP_Site_Profile::reset_cache();
 journey_check(!MAD4B_SCP_Site_Profile::oauth_enabled() && !MAD4B_SCP_Site_Profile::write_enabled() && !MAD4B_SCP_Site_Profile::skills_enabled() && !MAD4B_SCP_Site_Profile::early_managed_runtime_binding()['eligible'], 'copied profile inherited authority');
 $GLOBALS['home']=$original_home; MAD4B_SCP_Site_Profile::reset_cache();
 // Audit failure must restore the exact former profile and never emit save hook.
 $audit_fail=true; $input['expected_revision']=2; $hook_count=$saved_hooks;
 $failed=MAD4B_SCP_Site_Profile::save_current_site($input);
 journey_check(is_wp_error($failed) && 'mad4b_site_profile_audit_failed'===$failed->get_error_code() && $persisted===get_option(MAD4B_SCP_Site_Profile::OPTION) && $hook_count===$saved_hooks, 'audit rollback/save notification failed');
}
// Fencing and storage faults use the real lease implementation, not a permissive stub.
$wordpress='production'; $selected='staging'; $explicit=false; $home='https://tenant.example';
foreach(array('shared_lease','foreign_lease','lease_lost','status_dropped','profile_dropped','http_staging','http_local','invalid_user','non_admin') as $fault) {
 $label=$fault; $options=array('active_plugins'=>array('mcp-adapter/mcp-adapter.php','mad4b-site-control-plane/mad4b-site-control-plane.php')); $scheduled=array(); $admin=true; $audit_ready=true; $audit_fail=false; $saved_hooks=0; $drop_option=''; $integrity=true; $lose_lease=false; $protocol=false;
 if(file_exists($destination))unlink($destination); MAD4B_SCP_Site_Profile::reset_cache();
 $input=array('environment'=>'staging','expected_revision'=>0,'oauth_user_ids'=>array(7),'managed_runtime_enabled'=>true,'nonproduction_override_confirmed'=>true,'nonproduction_override_confirmation'=>MAD4B_SCP_Site_Profile::NONPRODUCTION_OVERRIDE_CONFIRMATION);
 if('http_staging'===$fault || 'http_local'===$fault) {$home='http://localhost:8080';$input['environment']='http_local'===$fault?'local':'staging';}
 else $home='https://tenant.example';
 if('invalid_user'===$fault)$input['oauth_user_ids']=array(999);
 if('non_admin'===$fault)$admin=false;
 if('profile_dropped'===$fault)$drop_option=MAD4B_SCP_Site_Profile::OPTION;
 $saved=MAD4B_SCP_Site_Profile::save_current_site($input);
 if(in_array($fault,array('profile_dropped','http_staging','invalid_user','non_admin'),true)) { journey_check(is_wp_error($saved) && false===get_option(MAD4B_SCP_Site_Profile::OPTION) && 0===$saved_hooks && empty($scheduled), 'failed profile save leaked state'); continue; }
 journey_check(!is_wp_error($saved),'valid fault setup failed');
 if('http_local'===$fault) {journey_check(MAD4B_SCP_Site_Profile::origin_enrolled(),'local HTTP enrollment rejected');continue;}
 $lease=''; if(in_array($fault,array('shared_lease','foreign_lease'),true))$lease=MAD4B_SCP_Runtime_Maintenance_Lease::acquire('runtime_convergence');
 if('lease_lost'===$fault)$lose_lease=true;
 if('status_dropped'===$fault)$drop_option=MAD4B_SCP_MCP_Runtime_Recovery::OPTION;
 $repair=MAD4B_SCP_MCP_Runtime_Recovery::run('foreign_lease'===$fault?'forged-token':$lease,true);
 if('shared_lease'===$fault) {journey_check(!is_wp_error($repair) && MAD4B_SCP_Runtime_Maintenance_Lease::owned($lease,'runtime_convergence'),'borrowed lease reacquired/released');MAD4B_SCP_Runtime_Maintenance_Lease::release($lease,'runtime_convergence');}
 else {journey_check(is_wp_error($repair) && false===get_option(MAD4B_SCP_MCP_Runtime_Recovery::OPTION),'fault published armed result');if('foreign_lease'===$fault)journey_check(!file_exists($destination) && MAD4B_SCP_Runtime_Maintenance_Lease::owned($lease,'runtime_convergence'),'forged lease mutated runtime');}
 journey_check(!MAD4B_SCP_MCP_Runtime_Recovery::active(),'fault leaked active privilege');
}
echo wp_json_encode(array('contract'=>'mad4b.site-profile-lifecycle-matrix.v1','enrollment_pairs'=>$journeys,'fault_cases'=>9,'assertions'=>$GLOBALS['assertions'],'result'=>'PASS')) . PHP_EOL;
