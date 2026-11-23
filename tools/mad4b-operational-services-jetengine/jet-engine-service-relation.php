<?php
/* Narrow JetEngine managed M:N service eligibility relation.
   Does not assign an itinerary day, create an order, or authorize a Rate. */
wp_set_current_user(1);
if(!current_user_can('manage_options'))throw new RuntimeException('relation_access_denied');
if(!function_exists('jet_engine')||!is_object(jet_engine()->relations->data??null))
 throw new RuntimeException('jet_relation_api_missing');
if(!post_type_exists('operational-services')||
    !post_type_exists('tours-and-activities') ||
    !(bool)get_option('mad4b_operational_services_jet_owner_v1'))
 throw new RuntimeException('relation_source_catalog_unverified');
$w=$GLOBALS['wpdb'];$table=$w->prefix.'jet_post_types';
$matches=[];
foreach((array)$w->get_results("SELECT id,args FROM $table WHERE status='relation'",ARRAY_A) as $row){
 $args=maybe_unserialize($row['args']);
 if(!is_array($args))continue;
 if(($args['parent_object']??'')==='posts::tours-and-activities' &&
    ($args['child_object']??'')==='posts::operational-services')
     $matches[]=(int)$row['id'];
}
if(count($matches)>1)throw new RuntimeException('relation_duplicate_definitions');
$do=getenv('MAD4B_RELATION_EXECUTE')==='YES';
$report=['site'=>home_url('/'),'mode'=>$do?'execute':'preflight',
 'existing_relation_ids'=>$matches,'parent'=>'posts::tours-and-activities',
 'child'=>'posts::operational-services','type'=>'many_to_many',
 'automatic_day_assignment'=>false,'automatic_supplier_price'=>false];
if(!$do||count($matches)===1){
 echo 'JET_OPERATIONAL_RELATION='.wp_json_encode($report,JSON_UNESCAPED_UNICODE)."\n";
 return;
}
$d=jet_engine()->relations->data;
$req=['name'=>'MAD4B Tour ↔ Operational Service Eligibility',
   'parent_object'=>'posts::tours-and-activities',
   'child_object'=>'posts::operational-services',
   'type'=>'many_to_many','parent_control'=>true,'child_control'=>true,
   'parent_manager'=>false,'child_manager'=>false,
   'rest_get_enabled'=>false,'rest_post_enabled'=>false,
   'parent_allow_delete'=>false,'child_allow_delete'=>false,
   'parents_required'=>false,'children_required'=>false,
   'db_table'=>false,'meta_fields'=>[]];
$d->set_request($req);
if(!$d->sanitize_item_request())throw new RuntimeException('relation_source_schema_invalid');
$native=$d->sanitize_item_from_request();
if(($native['args']['parent_object']??'')!==$req['parent_object']||
   ($native['args']['child_object']??'')!==$req['child_object']||
   ($native['args']['type']??'')!=='many_to_many')
 throw new RuntimeException('relation_native_sanitization_invalid');
$id=$d->create_item(false);
if(!$id)throw new RuntimeException('relation_native_creation_failed');
$report['created_relation_id']=$id;
$verify=$w->get_row($w->prepare("SELECT id,args FROM $table WHERE id=%d",$id),ARRAY_A);
if(!$verify||($verify['id']??0)!=$id)throw new RuntimeException('relation_readback_missing');
$args=maybe_unserialize($verify['args']);
if(($args['type']??'')!=='many_to_many')throw new RuntimeException('relation_readback_type_mismatch');
echo 'JET_OPERATIONAL_RELATION='.wp_json_encode($report,JSON_UNESCAPED_UNICODE)."\n";
