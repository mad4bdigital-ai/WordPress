<?php
/** Read-only WP-CLI QA. Requires the live MAD4B Site Control Plane. */
if (!class_exists('MAD4B_SCP_Servers') || !class_exists('MAD4B_SCP_Capability_Descriptor_Registry')) {
    echo 'SCP_RUNTIME_NOT_READY'; exit(2);
}
$expected=array(
    'mad4b/fleet-intake-auto'=>'write',
    'mad4b/fleet-intake-plan'=>'write',
    'mad4b/fleet-intake-save'=>'write',
    'mad4b/fleet-intake-status'=>'read'
);
$reports=array();
foreach($expected as $ability=>$lane) {
    $descriptor=MAD4B_SCP_Capability_Descriptor_Registry::describe($ability);
    if(is_wp_error($descriptor)) {
        echo wp_json_encode(array('ability'=>$ability,'error'=>$descriptor->get_error_code()));
        exit(2);
    }
    $pass=$descriptor['classification']===$lane &&
      $descriptor['execution_eligible']===true &&
      $descriptor['execution_lane']===$lane;
    if($lane==='write')$pass=$pass &&
        $descriptor['execution_provider']==='core' &&
        in_array($ability,MAD4B_SCP_Servers::write_tools(),true);
    $reports[]=array('ability'=>$ability,'lane'=>$lane,'passed'=>$pass,
        'blocker'=>$descriptor['execution_blocker']??'');
    if(!$pass){echo wp_json_encode($reports);exit(2);}
}
echo wp_json_encode(array('result'=>'PASS','site'=>home_url(),
   'mutations_performed'=>false,'grant_issued'=>false,'checks'=>$reports))."\n";
