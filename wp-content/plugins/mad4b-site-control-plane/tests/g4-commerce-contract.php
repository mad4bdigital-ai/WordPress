<?php
require __DIR__ . '/g4-domain-fixture.php';
$revision=str_repeat('a',64);
$facts=array('runtime_compatible'=>true,'hpos_compatible'=>true,'hooks_bounded'=>true,'objects'=>array('p1'=>array('authorized'=>true,'parent_id'=>'','revision'=>$revision,'stock_concurrency_guard'=>true,'field_contracts'=>array('name'=>g4_field('local_catalog'),'stock'=>g4_field('inventory','integer'),'payment'=>g4_field('payment')))));
$desired=array('items'=>array(array('id'=>'p1','parent_id'=>'','expected_revision'=>$revision,'fields'=>array('name'=>'Product'))));
g4_valid('commerce_catalog',$desired,$facts);
foreach(array('runtime_compatible','hpos_compatible','hooks_bounded') as $key){$bad=$facts;$bad[$key]=false;g4_deny('commerce_catalog',$desired,$bad,'commerce_runtime_or_hooks');}
$bad=$desired;$bad['items'][]=$bad['items'][0];g4_deny('commerce_catalog',$bad,$facts,'commerce_duplicate_or_identity');
$bad=$desired;$bad['items'][0]['parent_id']='foreign';g4_deny('commerce_catalog',$bad,$facts,'commerce_parent_or_revision');
$bad=$desired;$bad['items'][0]['expected_revision']=str_repeat('b',64);g4_deny('commerce_catalog',$bad,$facts,'commerce_parent_or_revision');
$bad=$desired;$bad['items'][0]['fields']=array('payment'=>'capture');g4_deny('commerce_catalog',$bad,$facts,'field_effect_or_privacy');
$stock=$desired;$stock['items'][0]['fields']=array('stock'=>4);$out=g4_valid('commerce_catalog',$stock,$facts);g4_assert('high_risk_inventory'===$out['risk'],'Stock inherited text-field risk.');
$bad=$facts;$bad['objects']['p1']['stock_concurrency_guard']=false;g4_deny('commerce_catalog',$stock,$bad,'commerce_stock_race');
$bad=$facts;$bad['objects']['p1']['authorized']=false;g4_deny('commerce_catalog',$desired,$bad,'commerce_parent_or_revision');
$bad=$facts;$bad['objects']['p1']['field_contracts']['name']['privacy']='pii';g4_deny('commerce_catalog',$desired,$bad,'field_effect_or_privacy');
$private=array('kind'=>'orders','object_ids'=>array('o1'),'fields'=>array('address'),'limit'=>10,'masked'=>true);
$authority=array('object_access'=>array('o1'=>true),'field_masks'=>array('o1'=>array('address'=>true)));
g4_valid('commerce_private',$private,$authority);
$bad=$private;$bad['object_ids'][]='foreign';g4_deny('commerce_private',$bad,$authority,'commerce_private_object');
$bad=$authority;$bad['field_masks']['o1']['address']=false;g4_deny('commerce_private',$private,$bad,'commerce_pii_mask');
$bad=$private;$bad['masked']=false;g4_deny('commerce_private',$bad,$authority,'commerce_private_bound');
foreach(array('shipping','tax','gateway','webhook','notification','refund','payment') as $operation){$out=g4_valid('commerce_financial',array('operation'=>$operation,'object_id'=>'o1'),array('object_access'=>true));g4_assert(false===$out['execution_supported'] && 'high_risk_explicit_gate'===$out['risk'],'Financial effect became ordinary catalog execution.');}
$bad=array('operation'=>'gateway','object_id'=>'o1','client_secret'=>'not-admitted');g4_deny('commerce_financial',$bad,array('object_access'=>true),'fields_invalid');
g4_done('commerce');
