<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$fail=static function($message,$data=null){
	fwrite(STDERR,'FAIL runtime-request-generation: '.$message.(null!==$data?' '.wp_json_encode($data):'').PHP_EOL);
	exit(1);
};
$check=static function($condition,$message,$data=null)use($fail){if(!$condition)$fail($message,$data);};
$code=static function($value){return is_wp_error($value)?$value->get_error_code():'';};

$check(class_exists('MAD4B_SCP_Request_Generation'),'request-generation fence unavailable');
$check(class_exists('MAD4B_SCP_Identity_Context'),'identity context unavailable');
$check(class_exists('MAD4B_SCP_Database_Transaction_Guard'),'transaction guard unavailable');

$original_user=get_current_user_id();
$check($original_user>0,'runtime fixture requires authenticated WP user');

$first=MAD4B_SCP_Request_Generation::begin_long_lived_request('ci-request-a','runtime_fixture');
$check(is_array($first),'first logical request failed',$first);
$first_generation=(int)$first['generation'];
$first_request_id=MAD4B_SCP_Identity_Context::request_id();

MAD4B_SCP_Site_Profile::status();
MAD4B_SCP_Schema::critical_ready();
MAD4B_SCP_Policy_Resolution::config_digest();
MAD4B_SCP_Operation_Registry::status();

wp_set_current_user(0);
$same_request_user_drift=MAD4B_SCP_Request_Generation::admit('runtime_fixture');
$check('mad4b_request_scope_context_drift'===$code($same_request_user_drift),'same-request user switch failed open',$same_request_user_drift);

$second=MAD4B_SCP_Request_Generation::begin_long_lived_request('ci-request-b','runtime_fixture');
$check(is_array($second)&&!empty($second['reset_performed'])&&$first_generation+1===(int)$second['generation'],'new logical request did not reset caches',$second);
$second_request_id=MAD4B_SCP_Identity_Context::request_id();
$check(!hash_equals($first_request_id,$second_request_id),'identity request id leaked across logical requests');

$check(false!==$GLOBALS['wpdb']->query('START TRANSACTION'),'could not create transaction boundary fixture');
$tx=MAD4B_SCP_Request_Generation::begin_long_lived_request('ci-request-c','runtime_fixture');
$check('mad4b_request_scope_transaction_active'===$code($tx),'active caller transaction crossed logical request boundary',$tx);
$check(false!==$GLOBALS['wpdb']->query('ROLLBACK'),'could not rollback transaction boundary fixture');

$ticket='00000000-0000-4000-8000-000000000777';
$identity_result=MAD4B_SCP_Identity_Context::with_approval_ticket_for_request(
	$ticket,
	static function(){
		return MAD4B_SCP_Request_Generation::begin_long_lived_request('ci-request-d','runtime_fixture');
	}
);
$check('mad4b_request_scope_identity_active'===$code($identity_result),'scoped approval identity crossed logical request boundary',$identity_result);

wp_set_current_user($original_user);
$third=MAD4B_SCP_Request_Generation::begin_long_lived_request('ci-request-e','runtime_fixture');
$check(is_array($third),'restored user could not start a fresh logical request',$third);

$projection_key=MAD4B_SCP_ChatGPT_Tool_Projection::OPTION;
$projection_before=get_option($projection_key,null);
$projection_existed=null!==$projection_before && false!==$projection_before;
update_option($projection_key,array('contract'=>'ci-request-generation','revision'=>wp_generate_uuid4()),false);
$projection_generation=MAD4B_SCP_Request_Generation::admit('runtime_fixture');
$check(
	is_array($projection_generation)
	&& !empty($projection_generation['presentation_changed'])
	&& 'none'===(string)$projection_generation['presentation_authority_effect'],
	'non-authorizing projection generation was treated as authority drift',
	$projection_generation
);
if($projection_existed) update_option($projection_key,$projection_before,false); else delete_option($projection_key);
$projection_restore=MAD4B_SCP_Request_Generation::admit('runtime_fixture');
$check(is_array($projection_restore)&&!empty($projection_restore['presentation_changed']),'projection restore generation was not observed',$projection_restore);

$home_before=(string)get_option('home','');
update_option('home',untrailingslashit($home_before).'/worker-recycle-fixture',false);
$worker=MAD4B_SCP_Request_Generation::begin_long_lived_request('ci-request-f','runtime_fixture');
$check('mad4b_request_scope_worker_recycle_required'===$code($worker),'site identity change did not require worker recycle',$worker);
update_option('home',$home_before,false);
wp_set_current_user($original_user);

echo "mad4b.request-scope-generation.real-wordpress.v1: PASS\n";
