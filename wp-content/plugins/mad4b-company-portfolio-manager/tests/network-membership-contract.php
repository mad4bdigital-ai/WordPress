<?php
if(!class_exists('MAD4B_Company_Network_Membership_V1',false))
    require_once dirname(__DIR__).'/includes/class-mad4b-company-network-membership.php';
$cls='MAD4B_Company_Network_Membership_V1';
$checks=[
 'class_loaded'=>class_exists($cls,false),
 'staging'=>$cls::staging(),
 'no_existing_registry'=>get_option($cls::STORE,null)===null,
];
$codes=['tours-central','wayfatours'];
$values=[];
foreach($codes as $code){
 $c=$cls::current($code);
 $values[$code]=is_wp_error($c)
    ? ['error'=>$c->get_error_code()]
    : ['state'=>$c['state'],'content_authority'=>$c['content_authority'],
       'rev'=>$c['revision'],'same_host'=>$c['readiness']['same_as_central_host_uuid'],
       'blocking_gates'=>$c['readiness']['blocking_gates'],
       'mapped'=>$c['readiness']['tenant_content_map_count'],
       'runtime_changed'=>$c['runtime_changed']];
 $checks['safe_'.$code]=!is_wp_error($c)&&$c['state']==='connected'
   &&$c['content_authority']==='hub_primary'&&$c['revision']===0
   &&$c['readiness']['can_detach_now']===false
   &&$c['runtime_changed']===false;
 if(is_wp_error($c))continue;
 $prepare=$cls::transition($c,'prepare_detach',0);
 $checks['prepare_'.$code]=!is_wp_error($prepare)
   &&$prepare['state']==='detach_prepared'&&$prepare['revision']===1
   &&is_string($prepare['plan_id'])&&$prepare['content_authority']==='hub_primary';
 $repeat=$cls::transition($prepare,'prepare_detach',1);
 $checks['idempotent_'.$code]=!is_wp_error($repeat)&&$repeat===$prepare;
 $stale=$cls::transition($prepare,'cancel_detach',0);
 $checks['stale_'.$code]=is_wp_error($stale)
   &&$stale->get_error_code()==='mad4b_membership_revision_stale';
 $switchInDetachment=$cls::transition($prepare,'set_authority',1,'company_primary');
 $checks['detach_authority_blocked_'.$code]=is_wp_error($switchInDetachment);
 $cancel=$cls::transition($prepare,'cancel_detach',1);
 $checks['cancel_'.$code]=!is_wp_error($cancel)&&$cancel['state']==='connected'
    &&$cancel['revision']===2;
 $switch=$cls::transition($cancel,'set_authority',2,'company_primary');
 $checks['company_primary_'.$code]=!is_wp_error($switch)
   &&$switch['content_authority']==='company_primary'
   &&$switch['state']==='connected'&&$switch['revision']===3;
 $bad=$cls::transition($switch,'rejoin_apply',3);
 $checks['no_fake_rejoin_'.$code]=is_wp_error($bad);
 $checks['no_runtime_writes_'.$code]=get_option($cls::STORE,null)===null;
}
$checks['different_company_uuid']=$values['tours-central']['same_host']===true
 &&$values['wayfatours']['same_host']===false;
$attempt=$cls::save('tours-central','prepare_detach',0);
$checks['unauthorized_write_denied']=is_wp_error($attempt)
    &&$attempt->get_error_code()==='mad4b_membership_admin_required';
$checks['read_does_not_write']=get_option($cls::STORE,null)===null;
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
 'checks'=>$checks,'companies'=>$values],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
