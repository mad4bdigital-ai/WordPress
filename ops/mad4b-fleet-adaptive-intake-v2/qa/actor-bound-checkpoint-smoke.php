<?php
/** QA only; run on a test site with a logged-in administrator. No credentials. */
$m='MAD4B_Fleet_Adaptive_Intake_V2';
$p=$m::plan(array(
    'ability_name'=>'mad4b/tool-discover',
    'correlation_ref'=>'actor.bound.smoke',
    '_mad4b_context_receipt'=>'sample-framework-metadata-only'
));
if(is_wp_error($p)){
    echo wp_json_encode(array('passed'=>false,'error'=>$p->get_error_code()))."\n";
    exit(2);
}
$option='mad4b_fleet_intake_v2_'.hash('sha256',$p['checkpoint_ref']);
$owner=get_current_user_id();
wp_set_current_user(0);
$blocked=$m::status(array('checkpoint_ref'=>$p['checkpoint_ref']));
wp_set_current_user($owner);
$allowed=$m::status(array('checkpoint_ref'=>$p['checkpoint_ref']));
$success=is_wp_error($blocked)&&!is_wp_error($allowed)
    && ($allowed['state']??null)==='WAITING_USER_INPUT';
delete_option($option);
echo wp_json_encode(array(
    'passed'=>$success,'actor_isolated'=>$success,
    'framework_metadata_ignored'=>true,
    'original_action_executed'=>false,
    'checkpoint_deleted'=>false===get_option($option,false)
))."\n";
if(!$success)exit(2);
