<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\IntakeModePolicy;
$passed=0;$errors=[];
$ok=static function($cond,$name)use(&$passed,&$errors){$cond?$passed++:$errors[]=$name;};
$ok(IntakeModePolicy::enabled()===false,'routing_policy_disabled_by_default');
$flag=static fn($ignored)=>[
    'enabled'=>true,
    'default_mode'=>'crm_first',
    'channels'=>['web'=>'order_first','phone'=>'crm_first'],
    'services'=>['properties'=>'crm_first','tours-and-activities'=>'order_first']
];
add_filter('pre_option_commerce_intake_routing_policy_v1',$flag,999);
try {
 $web=IntakeModePolicy::resolve('web',['tours-and-activities']);
 $ok(!is_wp_error($web)&&$web['mode']==='order_first'&&
    $web['rule_source']==='service','service_priority_order_first');
 $stay=IntakeModePolicy::resolve('web',['properties']);
 $ok(!is_wp_error($stay)&&$stay['mode']==='crm_first'&&
    $stay['creates_woo_order_now']===false,'specific_service_overrides_channel');
 $phone=IntakeModePolicy::resolve('phone',['other']);
 $ok(!is_wp_error($phone)&&$phone['mode']==='crm_first',
    'phone_channel_rule');
 $api=IntakeModePolicy::resolve('partner-api',['other']);
 $ok(!is_wp_error($api)&&$api['mode']==='crm_first'&&
    $api['rule_source']==='company_default','custom_channel_default');
 $mixed=IntakeModePolicy::resolve('web',['properties','tours-and-activities']);
 $ok(is_wp_error($mixed)&&$mixed->get_error_code()==='intake_route_services_conflict',
    'mixed_services_must_not_silently_change_mode');
 $bad=IntakeModePolicy::resolve('admin@example.invalid',['other']);
 $ok(is_wp_error($bad),'untrusted_channel_invalid');
 $ok(!is_wp_error($web)&&$web['supplier_booking_authorized']===false&&
    $web['marketing_enrollment_authorized']===false,'no_side_effect_authority');
 $same=IntakeModePolicy::resolve('web',['tours-and-activities']);
 $ok(!is_wp_error($web)&&$web['policy_digest']===$same['policy_digest'],
    'policy_digest_stable');
}finally{
 remove_filter('pre_option_commerce_intake_routing_policy_v1',$flag,999);
}
echo wp_json_encode(['result'=>$errors?'FAIL':'PASS','passed'=>$passed,'failed'=>$errors]).PHP_EOL;
if($errors)exit(2);
