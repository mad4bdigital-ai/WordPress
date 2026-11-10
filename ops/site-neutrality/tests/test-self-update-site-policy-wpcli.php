<?php
/** Read-only, WP-CLI-only acceptance. No plan/apply/download/backup mutation. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('CLI_REQUIRED');
$class = class_exists('MAD4B_SCP_Self_Update_Candidate',false) ? 'MAD4B_SCP_Self_Update_Candidate' : 'MAD4B_SCP_Self_Update';
if(!class_exists($class,false)||!class_exists('MAD4B_SCP_Site_Profile',false))throw new RuntimeException('REQUIRED_CLASSES_UNAVAILABLE');
$site=MAD4B_SCP_Site_Profile::status();
$managed=MAD4B_SCP_Site_Profile::managed_runtime_enabled();
$write=MAD4B_SCP_Site_Profile::write_enabled();
$enrolled=MAD4B_SCP_Site_Profile::origin_enrolled();
$urls=MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
$method = new ReflectionMethod($class,'environment_allowed');
$read=$method->invoke(null,false);$mutate=$method->invoke(null,true);
$expect_read=!empty($site['authority_ready'])&&!empty($site['site_uuid'])&&$managed&&$enrolled&&$urls;
$expect_write=$expect_read&&$write;
if($read!==$expect_read || $mutate!==$expect_write)throw new RuntimeException('SITE_AUTHORITY_AND_CHANNEL_POLICY_MISMATCH');
$bootstrap=(new ReflectionMethod($class,'bootstrap_site_eligible'))->invoke(null);
$bootstrap_expected=!empty($site['authority_ready'])&&!empty($site['site_uuid'])&&$enrolled&&$urls&&MAD4B_SCP_Site_Profile::configured();
if($bootstrap!==$bootstrap_expected)throw new RuntimeException('BOOTSTRAP_SITE_IDENTITY_MISMATCH');
wp_set_current_user(0);
$native=$class::can_native_apply([]);
$upload=$class::can_upload_apply([]);
if($native===true||$upload===true||($native!==false&&!is_wp_error($native))||($upload!==false&&!is_wp_error($upload)))throw new RuntimeException('UNAUTHENTICATED_APPLY_ALLOWED');
WP_CLI::line(wp_json_encode(['status'=>'PASS','site'=>home_url(),'environment_label'=>wp_get_environment_type(),'enrolled'=>$enrolled,'managed'=>$managed,'write'=>$write,'release_read'=>$read,'release_write'=>$mutate,'bootstrap_identity'=>$bootstrap,'no_actor_native_denied'=>is_wp_error($native)?$native->get_error_code():'permission_false','no_actor_upload_denied'=>is_wp_error($upload)?$upload->get_error_code():'permission_false','actual_update_performed'=>false]));
