<?php
/** Disposable real-WordPress journey; run separately for each host declaration. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) throw new RuntimeException( 'Disposable WP-CLI fixture required.' );
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
 $input=array('environment'=>$environment,'expected_revision'=>0,'display_name'=>'Disposable journey','oauth_user_ids'=>array(get_current_user_id()),'oauth_enabled'=>true,'skills_enabled'=>true,'write_enabled'=>false,'managed_runtime_enabled'=>true,'development_origin'=>'https://cloned-tenant.invalid');
 $saved=MAD4B_SCP_Site_Profile::save_current_site($input);
 $check(!is_wp_error($saved),'Initial enrollment: '.(is_wp_error($saved)?$saved->get_error_code():''));
 $check(MAD4B_SCP_Site_Profile::origin_enrolled()&&$environment===MAD4B_SCP_Site_Profile::current_environment()&&1===MAD4B_SCP_Site_Profile::revision(),'Initial readback/environment wrong.');
 $uuid=MAD4B_SCP_Site_Profile::site_uuid();$record=get_option(MAD4B_SCP_Site_Profile::OPTION);
 $eligible='production'!==$environment;
 $check($eligible===MAD4B_SCP_Site_Profile::early_managed_runtime_binding()['eligible'],'MU vs normal enrollment mismatch.');
 $check($eligible===(false!==wp_next_scheduled(MAD4B_SCP_MCP_Runtime_Recovery::HOOK)),'Successful save did not schedule/cancel correctly.');
 $stale=MAD4B_SCP_Site_Profile::save_current_site($input);$check(is_wp_error($stale)&&'mad4b_site_profile_stale'===$stale->get_error_code()&&$record===get_option(MAD4B_SCP_Site_Profile::OPTION),'Stale browser saved.');
 $input['expected_revision']=1;$input['write_enabled']=true;
 if('production'===$environment){
  $denied=MAD4B_SCP_Site_Profile::save_current_site($input);
  $check(is_wp_error($denied)&&'mad4b_site_profile_production_write_confirmation_required'===$denied->get_error_code()&&$record===get_option(MAD4B_SCP_Site_Profile::OPTION),'Production write accepted without acknowledgement.');
  $input['production_write_confirmed']=true;$input['production_write_confirmation']=MAD4B_SCP_Site_Profile::PRODUCTION_WRITE_CONFIRMATION;
 }
 $updated=MAD4B_SCP_Site_Profile::save_current_site($input);$check(!is_wp_error($updated)&&2===MAD4B_SCP_Site_Profile::revision()&&$uuid===MAD4B_SCP_Site_Profile::site_uuid()&&MAD4B_SCP_Site_Profile::write_enabled(),'Write enrollment readback failed.');
 $record=get_option(MAD4B_SCP_Site_Profile::OPTION);$recovery=MAD4B_SCP_MCP_Runtime_Recovery::run();
 $check($eligible?!is_wp_error($recovery)&&is_file($destination)&&false===$recovery['connection_certified']:is_wp_error($recovery)&&!is_file($destination),'Recovery changed Production or failed non-production.');
 $check($record===get_option(MAD4B_SCP_Site_Profile::OPTION)&&$active===get_option('active_plugins')&&$before_authority===$authority_rows(),'Recovery/profile save changed agents, subjects, grants or plugins.');
 $check(!MAD4B_SCP_MCP_Runtime_Recovery::active()&&!MAD4B_SCP_Runtime_Maintenance_Lease::status()['active'],'Recovery leaked lease/privilege.');
 // A copied profile and a related-origin entry cannot authorize the new site.
 add_filter('home_url',$clone);MAD4B_SCP_Site_Profile::reset_cache();
 $check(!MAD4B_SCP_Site_Profile::origin_enrolled()&&!MAD4B_SCP_Site_Profile::oauth_enabled()&&!MAD4B_SCP_Site_Profile::skills_enabled()&&!MAD4B_SCP_Site_Profile::write_enabled()&&!MAD4B_SCP_Site_Profile::early_managed_runtime_binding()['eligible'],'Copy inherited authority from related origin.');
 remove_filter('home_url',$clone);MAD4B_SCP_Site_Profile::reset_cache();
 $alternate='production'===$environment?'staging':'production';$input['environment']=$alternate;$input['expected_revision']=2;$input['write_enabled']=false;$input['production_write_confirmed']=false;unset($input['production_write_confirmation']);
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
