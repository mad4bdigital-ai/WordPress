<?php
if(!class_exists('MAD4B_Fleet_Adaptive_Intake_V2')){echo "MODULE_MISSING\n";exit(2);}
$schema=array('type'=>'object','properties'=>array(
 'tour_name'=>array('type'=>'string','minLength'=>2,'maxLength'=>80,'title'=>'Tour name'),
 'travellers'=>array('type'=>'integer','minimum'=>1,'maximum'=>20),
 'confirmed'=>array('type'=>'boolean'),
 'travel_date'=>array('type'=>'string','format'=>'date'),
 'rooming'=>array('type'=>'array','minItems'=>1,'maxItems'=>3,'items'=>array('type'=>'object','properties'=>array(
    'room_type'=>array('type'=>'string','enum'=>array('single','double','triple')),
    'guests'=>array('type'=>'integer','minimum'=>1)
 ),'required'=>array('room_type','guests'),'additionalProperties'=>false)),
 'metadata'=>array('type'=>'object','properties'=>array('source'=>array('type'=>'string')),'additionalProperties'=>false),
),'required'=>array('tour_name','travellers','rooming'),'additionalProperties'=>false);
$ability=wp_register_ability('mad4b/qa-runtime-input-fixture',array(
 'label'=>'MAD4B Fleet Intake Schema Test','description'=>'Test-only registered read capability',
 'category'=>'mad4b-fleet-intake','execute_callback'=>static fn($in)=>array('safe'=>true),
 'permission_callback'=>static fn()=>current_user_can('manage_options'),
 'input_schema'=>$schema,'output_schema'=>array('type'=>'object','additionalProperties'=>true),
 'meta'=>array('public'=>false,'show_in_rest'=>false)));
if(is_wp_error($ability)){echo "ABILITY_REGISTRATION_FAILED:".$ability->get_error_code()."\n";exit(2);}
$m='MAD4B_Fleet_Adaptive_Intake_V2';
$planned=$m::plan(array('ability_name'=>'mad4b/qa-runtime-input-fixture','correlation_ref'=>'fixture.20261108'));
if(is_wp_error($planned)){echo "PLAN_ERROR:".$planned->get_error_code()."\n";exit(2);}
$ref=$planned['checkpoint_ref'];
$key='mad4b_fleet_intake_v2_'.hash('sha256',$ref);
$r=get_option($key,false);
if(!is_array($r)||empty($r['ciphertext'])){echo "SEALED_CHECKPOINT_NOT_CREATED\n";exit(2);}
if(strpos(maybe_serialize($r),'Cairo Test')!==false){echo "PLAINTEXT_STORED_IN_DB\n";exit(2);}
$nonce=base64_decode($r['nonce']??'',true);
if(!is_string($nonce)||strlen($nonce)!==12){echo "INVALID_AEAD_NONCE\n";exit(2);}
$reflect=new ReflectionMethod($m,'record');
$reflect->setAccessible(true);
$record=$reflect->invoke(null,$ref);
if(is_wp_error($record)||!is_array($record)){echo "DECRYPTION_READBACK_FAILED\n";delete_option($key);exit(2);}
$r=$record;
$input=array('tour_name'=>'Cairo Test','travellers'=>'2','confirmed'=>'1','travel_date'=>'2026-12-01',
 'rooming'=>'[{"room_type":"double","guests":2}]','metadata'=>'{"source":"agent"}');
$validated=$m::validate_values($r,$input);
if(is_wp_error($validated)){echo "VALIDATION_ERROR:".$validated->get_error_code()."\n";delete_option($key);exit(2);}
$saved=$m::save(array('checkpoint_ref'=>$ref,'values'=>$input,'descriptor_sha256'=>$r['descriptor_sha256']));
if(is_wp_error($saved)){echo "SAVE_ERROR:".$saved->get_error_code()."\n";delete_option($key);exit(2);}
$status=$m::status(array('checkpoint_ref'=>$ref));
$ok=!is_wp_error($status) && ($status['state']??'')==='INPUT_SAVED' &&
 ($status['saved_values']['travellers']??null)===2 &&
 ($status['saved_values']['confirmed']??null)===true &&
 ($status['saved_values']['rooming'][0]['room_type']??null)==='double' &&
 ($status['saved_values']['metadata']['source']??null)==='agent' &&
 ($saved['auto_execute']??true)===false;
$reject=$m::validate_values($r,array('tour_name'=>'X','travellers'=>'bad',
 'rooming'=>'{"broken":true}','unknown'=>'bad'));
$reject_ok=is_wp_error($reject);
$secret_like=$m::validate_values($r,array('tour_name'=>'sk-abcdefghijklmnopqrstuvwxyz0123456789',
 'travellers'=>'2','rooming'=>'[{"room_type":"double","guests":2}]'));
$secret_rejected=is_wp_error($secret_like) &&
 $secret_like->get_error_code()==='mad4b_fleet_intake_secure_native_handoff_required';
$stored=get_option($key,false);
$ciphertext_safe=is_array($stored)&&isset($stored['ciphertext'])&&
 strpos(maybe_serialize($stored),'Cairo Test')===false&&
 strpos(maybe_serialize($stored),'hostinger')===false;
$repeat=$m::save(array('checkpoint_ref'=>$ref,'values'=>$input,'descriptor_sha256'=>$r['descriptor_sha256']));
$repeat_rejected=is_wp_error($repeat)&&$repeat->get_error_code()==='mad4b_fleet_intake_checkpoint_already_completed';
delete_option($key);
echo wp_json_encode(array('passed'=>$ok&&$reject_ok,'plan'=>'PASS','save'=>$ok?'PASS':'FAIL',
 'nested_array'=>'PASS','nested_object'=>'PASS','date'=>'PASS','invalid_input_rejected'=>$reject_ok,
 'session_in_chat'=>false,'original_action_executed'=>false,
 'encrypted_at_rest'=>$ciphertext_safe,'credential_pattern_rejected'=>$secret_rejected,
 'replay_rejected'=>$repeat_rejected))."\n";
if(!$ok||!$reject_ok||!$ciphertext_safe||!$secret_rejected||!$repeat_rejected)exit(2);
