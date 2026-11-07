<?php
/* Joined after the original module in a separate PHP CLI stdin process. */
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function error_code($x) { return is_wp_error($x) ? $x->get_error_code() : ''; }
$c='MAD4B_SCP_FAORE_Staging_Canary_Inspection';
$c::boot();
check(count($GLOBALS['hooks']['wp_abilities_api_init']??array())===1,'registration hook missing');
foreach ($GLOBALS['hooks']['wp_abilities_api_init'] as $fn) call_user_func($fn);
check(isset($GLOBALS['abilities'][$c::PLAN]) && isset($GLOBALS['abilities'][$c::VERIFY]), 'read abilities missing');
check(count($GLOBALS['abilities'])===2, 'unexpected write ability registered');
foreach ($GLOBALS['abilities'] as $name=>$ability) {
    check(($ability['category']??'')==='mad4b-read','wrong ability category');
    check(($ability['meta']['annotations']['readonly']??false)===true,'not readonly');
    check(($ability['meta']['mcp']['surface']??'')==='read','wrong MCP surface');
    check(($ability['input_schema']['required']??array())===array('correlation_id','option_suffix'),'typed schema drift');
}
$input=array('correlation_id'=>'faore-staging-write-24d8797756fb','option_suffix'=>'1234567890abcdef');
$key='mad4b_faore_write_canary_1234567890abcdef';
$plan=$c::plan($input);
check(!is_wp_error($plan) && $plan['option_present']===false,'absent canary readback failed');
check($plan['mutation_performed']===false && $plan['rollback_verified']===false,'readback implied mutation');
check($plan['option_key_sha256']===hash('sha256',$key),'actual original canary key namespace mismatch');
$GLOBALS['options'][$key]='private-sentinel-value';
$verify=$c::verify($input);
check(!is_wp_error($verify) && $verify['option_present']===true,'present canary was not found');
check(!array_key_exists('value',$verify) && !str_contains(json_encode($verify),'private-sentinel-value'),'canary value leaked');
check($plan['observation_fingerprint']!==$verify['observation_fingerprint'],'readback fingerprint not state-aware');
foreach (array(
    array('correlation_id'=>'faore-staging-write-24d8797756fb'),
    array('correlation_id'=>'faore-staging-write-24d8797756fb','option_suffix'=>'../bad'),
    array('correlation_id'=>'faore-staging-write-24d8797756fb','option_suffix'=>'1234567890abcdef','arbitrary_option'=>'secret'),
    array('correlation_id'=>'BAD', 'option_suffix'=>'1234567890abcdef'),
) as $bad) {
    check(error_code($c::verify($bad))==='mad4b_faore_canary_input_invalid','invalid input admitted');
}
$GLOBALS['env']='production';
$before=$GLOBALS['option_read_count'];
check(error_code($c::verify($input))==='mad4b_faore_canary_wrong_environment','production not rejected');
check($GLOBALS['option_read_count']===$before,'production read attempted');
$GLOBALS['env']='staging';
MAD4B_SCP_Site_Profile::$status['deployment_binding_match']=false;
check(error_code($c::verify($input))==='mad4b_faore_canary_profile_unverified','stale deployment admitted');
MAD4B_SCP_Site_Profile::$status['deployment_binding_match']=true;
check(!is_wp_error($c::verify($input)),'restored staging profile rejected');
echo "FAORE_STAGING_CANARY_READ_ONLY: PASS (schema, callbacks, prefix, absent/present, no leaks, wrong environment, stale profile)\n";
