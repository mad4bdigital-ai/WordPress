<?php
require __DIR__ . '/g4-domain-fixture.php';
$facts=array('native_format'=>'provider_tree_v1','serialization_contract'=>true,'revision_current'=>true,'editor_locked'=>false,'template_scope_authorized'=>true,'allowed_node_types'=>array('section','text'),'current_ids'=>array('root','child'),'allocated_clone_ids'=>array('copy-root','copy-child'),'control_contracts'=>array('text'=>array('text'=>g4_field())));
$desired=array('native_format'=>'provider_tree_v1','mode'=>'update','nodes'=>array(array('id'=>'root','parent'=>'','type'=>'section','settings'=>array()),array('id'=>'child','parent'=>'root','type'=>'text','settings'=>array('text'=>'Exact content'))));
$out=g4_valid('builder_tree',$desired,$facts);g4_assert($out['rendered_readback_required'] && $out['exact_rollback_acceptance_required'],'Structural plan lost readback/rollback gates.');
foreach(array('serialization_contract','revision_current','template_scope_authorized') as $key){$bad=$facts;$bad[$key]=false;g4_deny('builder_tree',$desired,$bad,'builder_format_lock_or_scope');}
$bad=$facts;$bad['editor_locked']=true;g4_deny('builder_tree',$desired,$bad,'builder_format_lock_or_scope');
$bad=$desired;$bad['native_format']='foreign_format';g4_deny('builder_tree',$bad,$facts,'builder_format_lock_or_scope');
$bad=$desired;$bad['nodes'][0]['parent']='child';g4_deny('builder_tree',$bad,$facts,'hierarchy_cycle');
$bad=$desired;$bad['nodes'][1]['parent']='missing';g4_deny('builder_tree',$bad,$facts,'hierarchy_orphan');
$bad=$desired;$bad['nodes'][1]['id']='root';g4_deny('builder_tree',$bad,$facts,'hierarchy_identity');
$bad=$desired;$bad['nodes'][1]['type']='unknown-block';g4_deny('builder_tree',$bad,$facts,'builder_node_type');
$bad=$desired;$bad['nodes'][1]['settings']['dynamic_expression']='unadmitted';g4_deny('builder_tree',$bad,$facts,'field_authority');
$bad=$desired;$bad['nodes'][1]['settings']['text']='Bearer '.str_repeat('a',32);g4_deny('builder_tree',$bad,$facts,'secret_denied');
$bad=$desired;$bad['nodes'][1]['shortcode']='foreign';g4_deny('builder_tree',$bad,$facts,'fields_invalid');
$clone=$desired;$clone['mode']='clone';$clone['nodes'][0]['id']='copy-root';$clone['nodes'][1]['id']='copy-child';$clone['nodes'][1]['parent']='copy-root';g4_valid('builder_tree',$clone,$facts);
$bad=$clone;$bad['nodes'][1]['id']='child';g4_deny('builder_tree',$bad,$facts,'builder_id_collision');
$bad=$facts;$bad['allocated_clone_ids']=array();g4_deny('builder_tree',$clone,$bad,'builder_id_collision');
$bad=$facts;$bad['control_contracts']['text']['text']['effect']='external';g4_deny('builder_tree',$desired,$bad,'field_effect_or_privacy');
g4_done('builders');
