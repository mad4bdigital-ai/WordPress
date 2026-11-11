<?php
$m='MAD4B_Fleet_Adaptive_Intake_V2';
$p=$m::auto(array('ability_name'=>'mad4b/tool-discover','correlation_ref'=>'chat.agent.auto.qa'));
if(is_wp_error($p)){echo 'AUTO_ERROR:'.$p->get_error_code()."\n";exit(2);}
$ref=$p['checkpoint_ref'];
$key='mad4b_fleet_intake_v2_'.hash('sha256',$ref);
$check=isset($p['descriptor_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$p['descriptor_sha256']);
if(!$check){delete_option($key);echo "MISSING_PUBLIC_SCHEMA_DIGEST\n";exit(2);}
$save=$m::save(array('checkpoint_ref'=>$ref,'descriptor_sha256'=>$p['descriptor_sha256'],
 'values'=>array('query'=>'remote agent test','limit'=>'5')));
$status=$m::status(array('checkpoint_ref'=>$ref));
$ok=!is_wp_error($save)&&!is_wp_error($status)&&
 ($status['saved_values']['query']??null)==='remote agent test'&&
 ($status['saved_values']['limit']??null)===5&&
 ($save['next_ability']??null)==='mad4b/fleet-intake-status'&&
 ($p['next_ability']??null)==='mad4b/fleet-intake-save'&&
 ($status['auto_execute']??true)===false;
$packet=get_option($key,false);
$encrypted=is_array($packet)&&isset($packet['ciphertext'])&&
 strpos(maybe_serialize($packet),'remote agent test')===false;
delete_option($key);
echo wp_json_encode(array('plan_save_status_pass'=>$ok,'encrypted_at_rest'=>$encrypted,
 'agent_schema_digest_readable'=>$check,'no_ui_required'=>true,
 'original_operation_executed'=>false,'cleanup'=>false===get_option($key,false)))."\n";
if(!$ok||!$encrypted)exit(2);
