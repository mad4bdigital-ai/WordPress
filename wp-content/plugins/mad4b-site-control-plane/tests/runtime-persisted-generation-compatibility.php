<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$fail=static function($m,$d=null){fwrite(STDERR,'FAIL runtime-persisted-generation-compatibility: '.$m.(null!==$d?' '.wp_json_encode($d):'').PHP_EOL);exit(1);};
$check=static function($c,$m,$d=null)use($fail){if(!$c)$fail($m,$d);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$check(class_exists('MAD4B_SCP_Persisted_Contract_Compatibility'),'persisted-contract compatibility unavailable');
$check(class_exists('MAD4B_SCP_Runtime_Generation_Fence'),'runtime generation fence unavailable');
$current=MAD4B_SCP_Persisted_Contract_Compatibility::assert_write_compatible();
$check(is_array($current)&&!empty($current['ready_for_write']),'current persisted generations not write-compatible',$current);
$captured=MAD4B_SCP_Runtime_Generation_Fence::capture();
$check(is_array($captured),'runtime generation capture failed',$captured);
$schema_before=(int)get_option(MAD4B_SCP_Schema::OPTION,0);
$profile_before=get_option(MAD4B_SCP_Site_Profile::OPTION,null);
$main_before=is_readable(MAD4B_SCP_FILE)?file_get_contents(MAD4B_SCP_FILE):false;
try {
	update_option(MAD4B_SCP_Schema::OPTION,MAD4B_SCP_Schema::VERSION+1,false);
	MAD4B_SCP_Schema::reset_request_cache();
	$future=MAD4B_SCP_Persisted_Contract_Compatibility::assert_write_compatible();
	$check('mad4b_persisted_contract_incompatible'===$code($future),'future schema failed open',$future);
	$storage=MAD4B_SCP_Schema::transactional_storage_status(array(),true);
	$check(is_array($storage)&&empty($storage['ready'])&&in_array('future_schema_downgrade_forbidden',$storage['blockers'],true),'transactional storage ignored future schema',$storage);
	$worker=MAD4B_SCP_Runtime_Generation_Fence::assert_current();
	$check('mad4b_runtime_worker_recycle_required'===$code($worker),'future schema did not fence loaded worker',$worker);
	update_option(MAD4B_SCP_Schema::OPTION,$schema_before,false); MAD4B_SCP_Schema::reset_request_cache();
	if(is_array($profile_before)&&!empty($profile_before)){
		$future_profile=$profile_before;$future_profile['contract']='mad4b.site-profile.v999';$future_profile['version']=999;
		update_option(MAD4B_SCP_Site_Profile::OPTION,$future_profile,false);MAD4B_SCP_Site_Profile::reset_cache();
		$profile_guard=MAD4B_SCP_Persisted_Contract_Compatibility::assert_write_compatible();
		$check('mad4b_persisted_contract_incompatible'===$code($profile_guard),'unknown/future Site Profile failed open',$profile_guard);
		update_option(MAD4B_SCP_Site_Profile::OPTION,$profile_before,false);MAD4B_SCP_Site_Profile::reset_cache();
	}
	$check(false!==$main_before,'main plugin file fixture unavailable');
	file_put_contents(MAD4B_SCP_FILE,$main_before."\n// MAD4B CI stale-worker generation fixture\n"); clearstatcache(true,MAD4B_SCP_FILE);
	$stale=MAD4B_SCP_Runtime_Generation_Fence::assert_current();
	$check('mad4b_runtime_worker_recycle_required'===$code($stale),'on-disk package replacement did not fence loaded worker',$stale);
} finally {
	update_option(MAD4B_SCP_Schema::OPTION,$schema_before,false);MAD4B_SCP_Schema::reset_request_cache();
	if(null===$profile_before||false===$profile_before) delete_option(MAD4B_SCP_Site_Profile::OPTION); else update_option(MAD4B_SCP_Site_Profile::OPTION,$profile_before,false);
	MAD4B_SCP_Site_Profile::reset_cache();
	if(false!==$main_before){file_put_contents(MAD4B_SCP_FILE,$main_before);clearstatcache(true,MAD4B_SCP_FILE);}
}
$check(is_array(MAD4B_SCP_Runtime_Generation_Fence::assert_current()),'generation fence did not recover after exact fixture restoration');
echo "mad4b.persisted-runtime-generation.real-wordpress.v1: PASS\n";
