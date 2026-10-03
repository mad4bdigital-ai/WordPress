<?php
/** Disposable real-WordPress journey; run separately for each host declaration. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) throw new RuntimeException( 'Disposable WP-CLI fixture required.' );
if ( ! defined( 'MAD4B_SCP_MCP_CLI_REQUEST' ) ) define( 'MAD4B_SCP_MCP_CLI_REQUEST', true );
$environment=getenv('MAD4B_TEST_ENVIRONMENT'); $declaration=getenv('MAD4B_TEST_DECLARATION');
if (!in_array($environment,array('local','development','staging','production'),true))throw new RuntimeException('Fixture environment missing.');
$checks=0;
$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);++$checks;};
$options_to_restore=array(MAD4B_SCP_Site_Profile::OPTION,MAD4B_SCP_Site_Profile::LEGACY_OPTION,MAD4B_SCP_MCP_Runtime_Recovery::OPTION,'cron');
$previous=array(); foreach($options_to_restore as $option)$previous[$option]=get_option($option,null);
$destination=WPMU_PLUGIN_DIR.'/000-mad4b-mcp-adapter-bootstrap.php';$previous_bytes=is_file($destination)?file_get_contents($destination):null;
$tables=MAD4B_SCP_Schema::tables();global $wpdb;
$authority_rows=static function()use($tables,$wpdb){$out=array();foreach(array('agents','subjects','grants')as$key)$out[$key]=$wpdb->get_results('SELECT * FROM `'.esc_sql($tables[$key]).'` ORDER BY id',ARRAY_A);return $out;};
$before_authority=$authority_rows();$active=get_option('active_plugins');$outbound=0;
$network_guard=static function($preempt,$args,$url)use(&$outbound){++$outbound;return new WP_Error('fixture_network_forbidden','No outbound requests in profile/recovery journey.');};
$clone=static function($url){return 'https://cloned-tenant.invalid';};
add_filter('pre_http_request',$network_guard,PHP_INT_MIN,3);
try {
 delete_option(MAD4B_SCP_Site_Profile::OPTION);delete_option(MAD4B_SCP_Site_Profile::LEGACY_OPTION);delete_option(MAD4B_SCP_MCP_Runtime_Recovery::OPTION);wp_clear_scheduled_hook(MAD4B_SCP_MCP_Runtime_Recovery::HOOK);
 if(is_file($destination))unlink($destination);MAD4B_SCP_Site_Profile::reset_cache();
 $check('implicit'===$declaration?!MAD4B_SCP_Site_Profile::wordpress_environment_explicit():MAD4B_SCP_Site_Profile::wordpress_environment_explicit(),'Wrong host declaration fixture.');
 $check(!MAD4B_SCP_Site_Profile::configured()&&!MAD4B_SCP_Site_Profile::oauth_enabled()&&!MAD4B_SCP_Site_Profile::skills_enabled()&&!MAD4B_SCP_Site_Profile::write_enabled(),'Unknown install gained authority.');
 MAD4B_SCP_MCP_Runtime_Recovery::schedule();$check(false===wp_next_scheduled(MAD4B_SCP_MCP_Runtime_Recovery::HOOK),'Unknown install scheduled repair.');
 $input=array('environment'=>$environment,'expected_profile_digest'=>MAD4B_SCP_Site_Profile::profile_digest(),'expected_revision'=>0,'display_name'=>'Disposable journey','oauth_user_ids'=>array(get_current_user_id()),'oauth_enabled'=>true,'skills_enabled'=>true,'write_enabled'=>false,'managed_runtime_enabled'=>true,'development_origin'=>'https://cloned-tenant.invalid');
 if('implicit'===$declaration && 'production'!==$environment){$input['nonproduction_override_confirmed']=true;$input['nonproduction_override_confirmation']=MAD4B_SCP_Site_Profile::NONPRODUCTION_OVERRIDE_CONFIRMATION;}
 $saved=MAD4B_SCP_Site_Profile::save_current_site($input);
 $check(!is_wp_error($saved),'Initial enrollment: '.(is_wp_error($saved)?$saved->get_error_code():''));
 $check(MAD4B_SCP_Site_Profile::origin_enrolled()&&$environment===MAD4B_SCP_Site_Profile::current_environment()&&1===MAD4B_SCP_Site_Profile::revision(),'Initial readback/environment wrong.');
 $uuid=MAD4B_SCP_Site_Profile::site_uuid();$record=get_option(MAD4B_SCP_Site_Profile::OPTION);
 $eligible='production'!==$environment;
 $check($eligible===MAD4B_SCP_Site_Profile::early_managed_runtime_binding()['eligible'],'MU vs normal enrollment mismatch.');
 $check($eligible===(false!==wp_next_scheduled(MAD4B_SCP_MCP_Runtime_Recovery::HOOK)),'Successful save did not schedule/cancel correctly.');
 $stale=MAD4B_SCP_Site_Profile::save_current_site($input);$check(is_wp_error($stale)&&'mad4b_site_profile_stale'===$stale->get_error_code()&&$record===get_option(MAD4B_SCP_Site_Profile::OPTION),'Stale browser saved.');
 $input['expected_revision']=1;$input['expected_profile_digest']=MAD4B_SCP_Site_Profile::profile_digest();$input['write_enabled']=true;
 if('production'===$environment){
  $denied=MAD4B_SCP_Site_Profile::save_current_site($input);
  $check(is_wp_error($denied)&&'mad4b_site_profile_production_write_confirmation_required'===$denied->get_error_code()&&$record===get_option(MAD4B_SCP_Site_Profile::OPTION),'Production write accepted without acknowledgement.');
  $input['production_write_confirmed']=true;$input['production_write_confirmation']=MAD4B_SCP_Site_Profile::PRODUCTION_WRITE_CONFIRMATION;
 }
 $updated=MAD4B_SCP_Site_Profile::save_current_site($input);$check(!is_wp_error($updated)&&2===MAD4B_SCP_Site_Profile::revision()&&$uuid===MAD4B_SCP_Site_Profile::site_uuid()&&MAD4B_SCP_Site_Profile::write_enabled(),'Write enrollment readback failed.');
 $record=get_option(MAD4B_SCP_Site_Profile::OPTION);

 // Real wp_options CAS proof: a writer holding the exact old serialized value
 // cannot overwrite a newer committed generation, and a stale rollback cannot
 // erase a newer winner.
 $cas_write=new ReflectionMethod('MAD4B_SCP_Site_Profile','persist_record_compare_and_swap');$cas_write->setAccessible(true);
 $cas_restore=new ReflectionMethod('MAD4B_SCP_Site_Profile','restore_record_compare_and_swap');$cas_restore->setAccessible(true);
 $competitor=$record;$competitor['revision']=3;$competitor['display_name']='concurrent-winner';$competitor['updated_at']=gmdate('c');
 update_option(MAD4B_SCP_Site_Profile::OPTION,$competitor,false);MAD4B_SCP_Site_Profile::reset_cache();
 $stale_candidate=$record;$stale_candidate['revision']=3;$stale_candidate['display_name']='stale-loser';$stale_candidate['updated_at']=gmdate('c');
 $check(false===$cas_write->invoke(null,$record,$stale_candidate),'Database CAS accepted an obsolete Site Profile generation.');
 $check($competitor===get_option(MAD4B_SCP_Site_Profile::OPTION),'Failed CAS changed the newer Site Profile winner.');

 $pending=$record;$pending['revision']=3;$pending['mutation_state']='pending_audit';$pending['mutation_id']='aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';$pending['updated_at']=gmdate('c');
 $newer=$record;$newer['revision']=3;$newer['display_name']='newer-after-pending';$newer['updated_at']=gmdate('c');
 update_option(MAD4B_SCP_Site_Profile::OPTION,$newer,false);MAD4B_SCP_Site_Profile::reset_cache();
 $check(false===$cas_restore->invoke(null,$pending,$record),'Stale rollback accepted a generation it no longer owned.');
 $check($newer===get_option(MAD4B_SCP_Site_Profile::OPTION),'Stale rollback erased a newer Site Profile commit.');
 update_option(MAD4B_SCP_Site_Profile::OPTION,$record,false);MAD4B_SCP_Site_Profile::reset_cache();

 $recovery=MAD4B_SCP_MCP_Runtime_Recovery::run();
 $recovery_diagnostic=array(
  'environment'=>$environment,
  'declaration'=>$declaration,
  'error_code'=>is_wp_error($recovery)?$recovery->get_error_code():'',
  'error_data'=>is_wp_error($recovery)?$recovery->get_error_data():null,
  'recovery_status'=>get_option(MAD4B_SCP_MCP_Runtime_Recovery::OPTION,array()),
  'lease_status'=>MAD4B_SCP_Runtime_Maintenance_Lease::status(),
  'mu_refresh_status'=>MAD4B_SCP_MCP_MU_Bootstrap_Refresh::status(),
  'conflict_guard_status'=>MAD4B_SCP_MCP_Runtime_Conflict_Guard::status(),
  'site_profile_status'=>MAD4B_SCP_Site_Profile::status(),
 );
 $recovery_diagnostic_json=wp_json_encode($recovery_diagnostic);
 if($eligible){
  $check(!is_wp_error($recovery),'Non-production recovery failed: '.$recovery_diagnostic_json);
  $check(is_file($destination),'Non-production recovery did not install managed MU bootstrap: '.$recovery_diagnostic_json);
  $check(is_array($recovery)&&array_key_exists('connection_certified',$recovery)&&false===$recovery['connection_certified'],'Recovery returned an invalid certification claim: '.$recovery_diagnostic_json);
 }else{
  $check(is_wp_error($recovery)&&'mad4b_mcp_repair_profile_ineligible'===$recovery->get_error_code()&&!is_file($destination),'Production recovery mutated runtime or returned the wrong eligibility gate: '.$recovery_diagnostic_json);
 }
 $check($record===get_option(MAD4B_SCP_Site_Profile::OPTION)&&$active===get_option('active_plugins')&&$before_authority===$authority_rows(),'Recovery/profile save changed agents, subjects, grants or plugins.');
 $check(!MAD4B_SCP_MCP_Runtime_Recovery::active()&&!MAD4B_SCP_Runtime_Maintenance_Lease::status()['active'],'Recovery leaked lease/privilege.');
 // A copied profile and a related-origin entry cannot authorize the new site.
 add_filter('home_url',$clone);MAD4B_SCP_Site_Profile::reset_cache();
 $check(!MAD4B_SCP_Site_Profile::origin_enrolled()&&!MAD4B_SCP_Site_Profile::oauth_enabled()&&!MAD4B_SCP_Site_Profile::skills_enabled()&&!MAD4B_SCP_Site_Profile::write_enabled()&&!MAD4B_SCP_Site_Profile::early_managed_runtime_binding()['eligible'],'Copy inherited authority from related origin.');
 remove_filter('home_url',$clone);MAD4B_SCP_Site_Profile::reset_cache();
 $alternate='production'===$environment?'staging':'production';$input['environment']=$alternate;$input['expected_revision']=2;$input['expected_profile_digest']=MAD4B_SCP_Site_Profile::profile_digest();$input['write_enabled']=false;$input['production_write_confirmed']=false;unset($input['production_write_confirmation']);
 if('implicit'===$declaration && 'production'!==$alternate){$input['nonproduction_override_confirmed']=true;$input['nonproduction_override_confirmation']=MAD4B_SCP_Site_Profile::NONPRODUCTION_OVERRIDE_CONFIRMATION;}else{unset($input['nonproduction_override_confirmed'],$input['nonproduction_override_confirmation']);}
 $rebound=MAD4B_SCP_Site_Profile::save_current_site($input);
 if('explicit'===$declaration)$check(is_wp_error($rebound)&&'mad4b_site_profile_environment_conflicts_explicit_wordpress'===$rebound->get_error_code()&&$record===get_option(MAD4B_SCP_Site_Profile::OPTION),'Explicit host environment overridden.');
 else {
  $check(!is_wp_error($rebound)&&$uuid!==MAD4B_SCP_Site_Profile::site_uuid()&&1===MAD4B_SCP_Site_Profile::revision()&&$alternate===MAD4B_SCP_Site_Profile::current_environment(),'Environment rebind failed.');
  $check(MAD4B_SCP_Site_Profile_Admin::persisted_readback_matches($input,$rebound,MAD4B_SCP_Site_Profile::status(),MAD4B_SCP_Site_Profile::profile()),'Rebind revision reset rejected by AJAX readback.');
  $check(('production'!==$alternate)===(false!==wp_next_scheduled(MAD4B_SCP_MCP_Runtime_Recovery::HOOK)),'Rebind retained wrong recovery schedule.');
 }
 // A non-administrator cannot enroll even on an exact eligible origin.
 wp_set_current_user(0);$denied=MAD4B_SCP_Site_Profile::save_current_site(array('environment'=>$environment));
 $check(is_wp_error($denied)&&'mad4b_site_profile_admin_required'===$denied->get_error_code(),'Anonymous enrollment accepted.');
 $check(0===$outbound,'Profile/recovery performed outbound I/O.');
 echo wp_json_encode(array('contract'=>'mad4b.real-wordpress-installation-journey.v1','wordpress'=>get_bloginfo('version'),'environment'=>$environment,'declaration'=>$declaration,'assertions'=>$checks,'result'=>'PASS')).PHP_EOL;
} finally {
 remove_filter('home_url',$clone);remove_filter('pre_http_request',$network_guard,PHP_INT_MIN);
 foreach($previous as$option=>$value){if(null===$value)delete_option($option);else update_option($option,$value,false);}
 if(null===$previous_bytes){if(is_file($destination))unlink($destination);}else file_put_contents($destination,$previous_bytes);
 MAD4B_SCP_Site_Profile::reset_cache();
}
