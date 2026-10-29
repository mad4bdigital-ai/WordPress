<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/Channels.php';
require dirname(__DIR__).'/includes/FormSchema.php';
use Commerce\Composition\FormSchema;

$policy=[
 'schema'=>1,
 'parent'=>['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>201],
 'groups'=>[
  ['key'=>'included_transfer','role'=>'fixed_included','options'=>[['service'=>'transportations','source_type'=>'transportations','source_id'=>301,'label'=>'Included airport transfer']]],
  ['key'=>'hotel','role'=>'choose_one_included','options'=>[['service'=>'properties','source_type'=>'properties','source_id'=>401,'label'=>'Hotel A'],['service'=>'properties','source_type'=>'properties','source_id'=>402,'label'=>'Hotel B']]],
  ['key'=>'extras','role'=>'optional_attached','options'=>[['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>501,'label'=>'Museum'],['service'=>'tours','source_type'=>'tours-and-activities','source_id'=>502,'label'=>'Cruise']]],
 ]
];
$base=['active_services'=>['tours'],'properties_channel_mode'=>'tour_only','transportations_channel_mode'=>'tour_only'];
$passed=0;$failed=[];
function check(bool $ok,string $key):void { global $passed,$failed; if($ok)$passed++;else $failed[]=$key;}
$r=FormSchema::describe($policy,$base);
check($r['ready_to_configure'],'legacy_available');
check(count($r['groups'])===3,'three_groups');
check($r['groups'][0]['control']==='summary','fixed_readonly');
check($r['groups'][1]['control']==='select'&&$r['groups'][1]['required'],'hotel_select_required');
check($r['groups'][2]['control']==='checkbox','extras_checkbox');
check(count($r['groups'][1]['options'])===2,'hotel_alternatives');
check(!$r['checkout_authority'],'form_not_sale');
$more=$base;$more['active_services']=['tours','properties','transportations'];
$more['properties_channel_mode']='standalone_and_tour';
$more['transportations_channel_mode']='standalone_and_tour';
$s=FormSchema::describe($policy,$more);
check($s['ready_to_configure']&&count($s['groups'][1]['options'])===2,'all_active_keeps_package');
$blocked=$base;$blocked['properties_channel_mode']='disabled';
$s=FormSchema::describe($policy,$blocked);
check(!$s['ready_to_configure']&&!$s['groups'][1]['available'],'required_disabled_blocks_form');
$blocked=$base;$blocked['transportations_channel_mode']='disabled';
$s=FormSchema::describe($policy,$blocked);
check(!$s['ready_to_configure']&&!$s['groups'][0]['available'],'included_transport_disabled_blocks_form');
$blocked=$base;$blocked['tours_channel_mode']='disabled';
try{ FormSchema::describe($policy,$blocked);check(false,'disabled_parent_denied');}
catch (\DomainException $e) {check($e->getMessage()==='form_parent_channel_unavailable','disabled_parent_denied');}
$policy['groups'][]=['key'=>'upgrade','role'=>'upgrade_delta','options'=>[['service'=>'properties','source_type'=>'properties','source_id'=>403]]];
$upgrade=FormSchema::describe($policy,$base);
check($upgrade['groups'][3]['required']===false && $upgrade['groups'][3]['control']==='select','upgrade_selector_optional');
echo json_encode(['result'=>$failed?'FAIL':'PASS','passed'=>$passed,'failed'=>$failed],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($failed)exit(1);
