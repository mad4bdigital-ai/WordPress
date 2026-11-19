<?php
/**
 * WP-CLI live, read-only Stay readiness + native handoff integration contract.
 * Do not call wp_update_post, update_post_meta, publish, or checkout operations.
 */
$dir='/tmp/';
if(!class_exists('MAD4B_Stay_Commercial_Readiness_V1',false))
    require_once $dir.'020-mad4b-stay-commercial-readiness.php';
$c='MAD4B_Stay_Commercial_Readiness_V1';
$ids=get_users(['role'=>'master_admin','fields'=>'ID','number'=>1]);
if(!$ids)$ids=get_users(['role'=>'administrator','fields'=>'ID','number'=>1]);
if(!$ids)throw new RuntimeException('No scoped pricing admin');
wp_set_current_user((int)$ids[0]);
$ok=[];
$assert=static function($name,$pass)use(&$ok){
    if(!$pass)throw new RuntimeException('Failed: '.$name);
    $ok[]=$name;
};
$assert('pricing role authorized',$c::authorized());
$all=$c::audit(['scope'=>'all']);
$assert('inventory audit returned',!is_wp_error($all) && is_array($all));
$sum=$all['summary'];
$assert('unit totals match published/drafts',$sum['total']===
    (int)wp_count_posts('units')->publish+(int)wp_count_posts('units')->draft);
$assert('commercial checkout stays blocked',$sum['commercially_ready']===0 && $all['checkout_ready']===false);
$assert('page bounded',count($all['items'])<=20);
$assert('valid relation or recorded blocker',count($all['items'])>0 &&
    !empty($all['items'][0]['unit_id']));
$priority=$c::audit(['scope'=>'priority']);
$assert('priority filtered',!is_wp_error($priority));
$assert('priority <= draft units',$priority['found']<=$sum['total']-$sum['published']);
foreach($priority['items'] as $item){
    $assert('priority Unit is draft',$item['unit_status']==='draft');
    $assert('priority Property is published',$item['property_status']==='publish');
    $assert('priority Unit not QA',!$item['qa_only']);
}
$draft=$c::audit(['scope'=>'draft_parent']);
$assert('draft parent filtered',!is_wp_error($draft));
foreach($draft['items'] as $item)
    $assert('draft parent selected',$item['property_status']==='draft');
$qa=$c::audit(['scope'=>'qa']);
$assert('QA scan returned',!is_wp_error($qa));
foreach($qa['items'] as $item)$assert('QA unit excluded',$item['qa_only']);
$bad=$c::audit(['scope'=>'admin_access_bypass']);
$assert('unknown scope denied',is_wp_error($bad)
  &&$bad->get_error_code()==='stay_readiness_scope_invalid');
$bad=$c::audit(['paged'=>0]);
$assert('invalid page denied',is_wp_error($bad)
  &&$bad->get_error_code()==='stay_readiness_page_invalid');
$review=$c::handoff(['property_id'=>36260,'unit_id'=>37553,
    'checkin'=>'2027-01-01','nights'=>2,'adults'=>2]);
$assert('read-only handoff returned',!is_wp_error($review));
$assert('native checkout authority remains false',$review['checkout_ready']===false);
$assert('no handoff mutation',$review['mutation_performed']===false);
$assert('self provider preview preserved',$review['provider']==='self');
$_GET['page']='dynamic-pricing-controls';
$c::assets();
$assert('page css registered',wp_style_is('mad4b-stay-readiness','enqueued'));
$assert('page script registered',wp_script_is('mad4b-stay-readiness','enqueued'));
$scripts=wp_scripts()->registered['mad4b-stay-readiness']??null;
$deps=$scripts?$scripts->deps:[];
$assert('prior inline pricing panel dependency',in_array('mad4b-stay-pricing-inline',$deps,true));
$extra=$scripts->extra['before']??[];
$joined=implode('',array_filter((array)$extra,'is_string'));
$assert('readiness AJAX config and panel template',strpos($joined,'MAD4BStayReadiness')!==false
    &&strpos($joined,'Property &')!==false);
wp_set_current_user(0);
$denied=$c::audit(['scope'=>'all']);
$assert('anonymous audit denied',is_wp_error($denied));
$denied=$c::handoff(['property_id'=>36260,'unit_id'=>37553]);
$assert('anonymous handoff denied',is_wp_error($denied));
echo wp_json_encode(['site'=>home_url(),'tests'=>count($ok),'status'=>'PASS',
    'units'=>$sum['total'],'published'=>$sum['published'],
    'priority'=>$priority['found'],'draft_parent'=>$draft['found'],
    'qa'=>$qa['found'],'missing_capacity'=>$sum['missing_capacity'],
    'missing_images'=>$sum['missing_unit_images'],
    'missing_inventory'=>$sum['missing_inventory'],
    'commercially_ready'=>0,'no_mutations'=>true,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
