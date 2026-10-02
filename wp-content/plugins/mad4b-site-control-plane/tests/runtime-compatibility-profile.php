<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$fail=static function($m,$d=null){fwrite(STDERR,'FAIL runtime-compatibility-profile: '.$m.(null!==$d?' '.wp_json_encode($d):'').PHP_EOL);exit(1);};
$check=static function($c,$m,$d=null)use($fail){if(!$c)$fail($m,$d);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$blockers=static function($v){$d=is_wp_error($v)?$v->get_error_data():array();return is_array($d)&&isset($d['blockers'])?$d['blockers']:array();};

$check(class_exists('MAD4B_SCP_Runtime_Compatibility_Profile'),'runtime compatibility profile unavailable');
$base=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check(is_array($base)&&!empty($base['ready'])&&'wp_cli'===$base['execution_context'],'disposable WP-CLI baseline is not compatible',$base);

$active_before=get_option('active_plugins',array());
update_option('active_plugins',array_merge((array)$active_before,array('wordfence/wordfence.php')),false);
$security=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check('mad4b_runtime_compatibility_not_ready'===$code($security)&&in_array('security_firewall_plugin_requires_certification',$blockers($security),true),'security plugin compatibility guard failed open',$security);
update_option('active_plugins',$active_before,false);

$maintenance=trailingslashit(ABSPATH).'.maintenance';
$maintenance_before=is_file($maintenance)?file_get_contents($maintenance):null;
file_put_contents( $maintenance, '<?php $upgrading = ' . time() . ";\n" );
$blocked=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check('mad4b_runtime_compatibility_not_ready'===$code($blocked)&&in_array('wordpress_maintenance_mode_active',$blockers($blocked),true),'maintenance compatibility guard failed open',$blocked);
if(null===$maintenance_before)@unlink($maintenance);else file_put_contents($maintenance,$maintenance_before);

$db_dropin=trailingslashit(WP_CONTENT_DIR).'db.php';
$db_before=is_file($db_dropin)?file_get_contents($db_dropin):null;
if(null===$db_before){
	file_put_contents($db_dropin,"<?php // compatibility fixture only\n");
	$db=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
	$check('mad4b_runtime_compatibility_not_ready'===$code($db)&&in_array('database_router_uncertified',$blockers($db),true),'uncertified database router guard failed open',$db);
	@unlink($db_dropin);
}

$request_fixture=new class{public function get_route(){return '/mcp/mad4b-chatgpt';}};
$bound=MAD4B_SCP_Transport_Context::bind('mad4b-chatgpt',$request_fixture);
$check(!is_wp_error($bound),'could not bind remote transport fixture',$bound);
$context=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check('mad4b_runtime_compatibility_not_ready'===$code($context)&&in_array('remote_transport_wp_cli_context_conflict',$blockers($context),true),'remote transport WP-CLI conflict failed open',$context);
MAD4B_SCP_Transport_Context::clear();

$final=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check(is_array($final)&&!empty($final['ready']),'compatibility profile did not recover after fixture cleanup',$final);
echo "mad4b.runtime-compatibility-profile.real-wordpress.v1: PASS\n";
