<?php
/** Read-only validation with an exact enrolled MAD4B Central runtime. */
if(!class_exists('MAD4B_Company_Central_Preferred_Router_V1',false) ||
    !class_exists('MAD4B_Company_Site_Operations_V1',false)){
  echo "MODULE_NOT_LOADED\n";exit(2);
}
$tests=array(
  array('egypt-tour-gates','staging','fleet-intake.save','vps_client_mcp_cross_ssh'),
  array('all-royal-egypt','staging','content.write','vps_client_mcp_cross_ssh'),
  array('tours-central','staging','site.binding.inspect','vps_client_local_wpcli'),
  array('all-royal-egypt','production','fleet-intake.save',null)
);
$all=true;
foreach($tests as $spec){
 $r=MAD4B_Company_Central_Preferred_Router_V1::plan_for(
     $spec[0],$spec[1],$spec[2]);
 $pass=!is_wp_error($r) &&
  ($r['selected_transport']??null)===$spec[3] &&
  ($r['authorization_default']??'')==='company_manager_oauth' &&
  $r['write_execution_authorized']===false &&
  $r['original_operation_executed']===false &&
  $r['original_313_receipt_issued']===false;
 $all=$all&&$pass;
 echo wp_json_encode(array('site'=>$spec[0].':'.$spec[1],
   'operation'=>$spec[2],'expected_transport'=>$spec[3],
   'passed'=>$pass))."\n";
}
$wrong=MAD4B_Company_Central_Preferred_Router_V1::plan_for(
 'egypt-tour-gates','staging','any.arbitrary.write');
$mgr=MAD4B_Company_Site_Operations_V1::plan(
 'egypt-tour-gates','staging','site.binding.inspect');
$all=$all&&is_wp_error($wrong)
 &&$mgr['routing_policy']['preferred_lane']==='vps_client_mcp_cross_ssh'
 &&$mgr['routing_policy']['default_authorization']==='company_manager_oauth';
echo wp_json_encode(array('passed'=>$all,'wrong_operation_rejected'=>is_wp_error($wrong),
 'manager_preferred_route'=>$mgr['routing_policy']['preferred_lane']??'',
 'write_granted'=>false,'operation_executed'=>false))."\n";
if(!$all)exit(2);
