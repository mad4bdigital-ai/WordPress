<?php
require __DIR__ . '/g4-domain-fixture.php';
$desired=array('source_language'=>'ar','nodes'=>array(array('id'=>'root','parent'=>'','language'=>'ar','translation_group'=>'tr1'),array('id'=>'child','parent'=>'root','language'=>'ar','translation_group'=>'tr2')));
$facts=array('source_language'=>'ar','hierarchy_inventory_complete'=>true,'known_parent_ids'=>array(),'existing_parents'=>array(),'objects'=>array('root'=>array('authorized'=>true,'language'=>'ar','translation_group'=>'tr1'),'child'=>array('authorized'=>true,'language'=>'ar','translation_group'=>'tr2')));
g4_valid('wordpress_hierarchy',$desired,$facts);
$bad=$desired;$bad['nodes'][0]['parent']='child';g4_deny('wordpress_hierarchy',$bad,$facts,'hierarchy_cycle');
$bad=$desired;$bad['nodes'][1]['parent']='missing';g4_deny('wordpress_hierarchy',$bad,$facts,'hierarchy_orphan');
$bad=$desired;$bad['nodes'][1]['language']='en';g4_deny('wordpress_hierarchy',$bad,$facts,'hierarchy_ownership_or_language');
$bad=$facts;$bad['objects']['root']['authorized']=false;g4_deny('wordpress_hierarchy',$desired,$bad,'hierarchy_ownership_or_language');
$bad=$facts;$bad['hierarchy_inventory_complete']=false;g4_deny('wordpress_hierarchy',$desired,$bad,'language_or_inventory');
$bad=$desired;$bad['nodes'][1]['translation_group']='tr1';$bad_facts=$facts;$bad_facts['objects']['child']['translation_group']='tr1';g4_deny('wordpress_hierarchy',$bad,$bad_facts,'translation_group_duplicate');
// A cycle through a parent outside the desired delta must not be treated as an opaque known leaf.
$bad=$desired;$bad['nodes']=array($bad['nodes'][1]);$bad_facts=$facts;$bad_facts['known_parent_ids']=array('root');$bad_facts['existing_parents']=array('root'=>'child');g4_deny('wordpress_hierarchy',$bad,$bad_facts,'hierarchy_cycle');
$bad_facts['existing_parents']=array();g4_deny('wordpress_hierarchy',$bad,$bad_facts,'hierarchy_existing_inventory');
$object=array('kind'=>'runtime-cpt','object_id'=>'p1','fields'=>array('title'=>'Hello'));
$object_facts=array('admitted_object_kinds'=>array('runtime-cpt'),'object_id'=>'p1','object_access'=>true,'role_or_sensitive_fields'=>array('foreign_acf_field'),'field_contracts'=>array('title'=>g4_field(),'roles'=>g4_field('local_metadata'),'foreign_acf_field'=>g4_field('local_metadata'),'image'=>array_merge(g4_field('local_media'),array('semantic'=>'media_url'))),'allowed_url_origins'=>array('https://media.example.invalid'));
g4_valid('wordpress_object',$object,$object_facts);
$bad=$object;$bad['fields']=array('roles'=>'administrator');g4_deny('wordpress_object',$bad,$object_facts,'wordpress_role_or_sensitive_field');
$bad=$object;$bad['fields']=array('foreign_acf_field'=>'not-owned');g4_deny('wordpress_object',$bad,$object_facts,'wordpress_role_or_sensitive_field');
$bad=$object;$bad['object_id']='foreign';g4_deny('wordpress_object',$bad,$object_facts,'wordpress_object_scope');
$image=$object;$image['fields']=array('image'=>'https://media.example.invalid/image.jpg');g4_valid('wordpress_object',$image,$object_facts);
foreach(array('http://media.example.invalid/image.jpg','https://127.0.0.1/image.jpg','https://outside.invalid/image.jpg','https://user:pass@media.example.invalid/image.jpg','https://media.example.invalid/image.jpg?token=hidden','https://media.example.invalid/%2e%2e/private.jpg') as $url){$bad=$image;$bad['fields']['image']=$url;g4_deny('wordpress_object',$bad,$object_facts,'media_url_scope');}
$collection=array('object_ids'=>array('event-1'),'limit'=>10,'offset'=>0,'timezone'=>'UTC','masked'=>true);
$collection_facts=array('provider_available'=>true,'timezone'=>'UTC','object_access'=>array('event-1'=>true),'mask_verified'=>array('event-1'=>true),'private_communication'=>array('event-1'=>false));
g4_valid('wordpress_private_collection',$collection,$collection_facts);
$bad=$collection_facts;$bad['provider_available']=false;g4_deny('wordpress_private_collection',$collection,$bad,'collection_runtime_bound_or_timezone');
$bad=$collection;$bad['timezone']='Invalid/Zone';g4_deny('wordpress_private_collection',$bad,$collection_facts,'collection_runtime_bound_or_timezone');
$bad=$collection;$bad['offset']=10001;g4_deny('wordpress_private_collection',$bad,$collection_facts,'collection_runtime_bound_or_timezone');
$bad=$collection_facts;$bad['object_access']['event-1']=false;g4_deny('wordpress_private_collection',$collection,$bad,'collection_private_object');
$bad=$collection_facts;$bad['private_communication']['event-1']=true;g4_deny('wordpress_private_collection',$collection,$bad,'collection_private_object');
g4_done('wordpress');
