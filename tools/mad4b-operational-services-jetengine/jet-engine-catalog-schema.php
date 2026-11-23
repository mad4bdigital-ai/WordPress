<?php
/* Exact native JetEngine CPT/Taxonomy schema using its own data APIs.
   Set MAD4B_JET_EXECUTE=YES only for migration execution. */
$do=getenv('MAD4B_JET_EXECUTE')==='YES';
wp_set_current_user(1);
if(!current_user_can('manage_options'))throw new RuntimeException('admin_capability_missing');
if(!function_exists('jet_engine')||!is_object(jet_engine()->cpt->data??null)||
   !is_object(jet_engine()->taxonomies->data??null))
  throw new RuntimeException('jet_engine_native_data_unavailable');
$original='MAD4B_Operational_Services_V1';
if(!class_exists($original,false))throw new RuntimeException('operational_plugin_missing');
$wpdb=$GLOBALS['wpdb'];
$types=$wpdb->prefix.'jet_post_types';
$taxes=$wpdb->prefix.'jet_taxonomies';
foreach([[$types,'operational-services'],[$taxes,'service-family'],[$taxes,'service-type']] as $row){
 $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$row[0]} WHERE slug=%s AND status='publish'",$row[1]));
 if($exists)throw new RuntimeException('jetengine_schema_already_owned:'.$row[1]);
}
$fields=[
 ['service_unit','Unit of calculation','select',
   ['per_service'=>'Per service','per_hour'=>'Per hour','per_day'=>'Per day','per_person'=>'Per person','per_group'=>'Per group','per_room'=>'Per room','per_night'=>'Per night','custom'=>'Custom basis']],
 ['supplier_mode','Supplier mode','select',['self'=>'Company / self','external'=>'External supplier']],
 ['supplier_id','External supplier reference','text',[]],
 ['currency','Cost currency','select',['EGP'=>'EGP','USD'=>'USD','EUR'=>'EUR','GBP'=>'GBP']],
 ['base_cost_minor','Draft cost (minor currency units)','number',[]],
 ['included_wait_minutes','Included waiting minutes','number',[]],
 ['extra_wait_cost_minor','Extra waiting draft cost (minor units)','number',[]],
 ['availability_notes','Availability / shift restrictions','textarea',[]],
 ['location_scope','Service location / meeting point','text',[]],
 ['operational_notes','Supplier and service instructions','textarea',[]],
 ['cost_state','Cost evidence review status','select',
   ['unpriced'=>'Unpriced','draft_cost'=>'Draft internal cost','requires_approval'=>'Requires financial approval']],
 ['cost_evidence_reference','Supplier receipt or internal ledger reference','text',[]]
];
$meta=[];
foreach($fields as $info){
 [$key,$title,$type,$choices]=$info;
 $opts=[];
 foreach($choices as $value=>$label)$opts[]=['key'=>(string)$value,'value'=>(string)$label];
 $field=['name'=>'_mad4b_os_'.$key,'title'=>$title,'type'=>$type,
  'object_type'=>'field','width'=>'100%','options'=>$opts,
  'repeater-fields'=>[],'isNested'=>false,'options_source'=>'manual',
  'description'=>'Internal fulfillment source; no approved cost or Woo billing authority.'];
 if($type==='number')$field['min_value']='0';
 $meta[]=$field;
}
$req=[
 'slug'=>'operational-services','name'=>'Operational Services',
 'singular_name'=>'Operational Service','menu_name'=>'Operational Services',
 'add_new_item'=>'Add Operational Service','edit_item'=>'Edit Operational Service',
 'public'=>false,'publicly_queryable'=>false,'show_ui'=>true,
 'show_in_menu'=>true,'show_in_nav_menus'=>false,'show_in_rest'=>false,
 'query_var'=>false,'rewrite'=>false,'map_meta_cap'=>true,
 'has_archive'=>false,'hierarchical'=>false,'exclude_from_search'=>true,
 'with_front'=>false,'show_edit_link'=>true,'custom_storage'=>false,
 'hide_field_names'=>false,'delete_metadata'=>false,'supports'=>['title','editor','revisions'],
 'capability_type'=>'post','menu_icon'=>'dashicons-groups',
 'meta_fields'=>$meta
];
$cptData=jet_engine()->cpt->data;
$cptData->set_request($req);
if(!$cptData->sanitize_item_request())throw new RuntimeException('cpt_native_validation_failed');
$item=$cptData->sanitize_item_from_request();
if($item===false||$item['slug']!==$req['slug']||
  count($item['meta_fields'])!==count($meta))
   throw new RuntimeException('cpt_native_field_sanitization_failed');
$taxData=jet_engine()->taxonomies->data;
$taxReqs=[];
foreach(['service-family'=>['Service Families','Service Family'],
         'service-type'=>['Service Types','Service Type']] as $slug=>$labels){
  $r=['slug'=>$slug,'name'=>$labels[0],'singular_name'=>$labels[1],
      'object_type'=>['operational-services'],'public'=>false,
      'publicly_queryable'=>false,'show_ui'=>true,'show_in_menu'=>true,
      'show_in_nav_menus'=>false,'show_in_rest'=>false,'rewrite'=>false,
      'hierarchical'=>true,'query_var'=>'','capability_type'=>'post',
      'meta_fields'=>[]];
  $taxData->set_request($r);
  if(!$taxData->sanitize_item_request())throw new RuntimeException('tax_native_validation_failed:'.$slug);
  $x=$taxData->sanitize_item_from_request();
  if($x===false||$x['slug']!==$slug||!in_array('operational-services',$x['object_type'],true))
     throw new RuntimeException('tax_native_sanitization_failed:'.$slug);
  $taxReqs[$slug]=$r;
}
$report=['mode'=>$do?'execute':'preflight',
 'site'=>home_url('/'),'native_cpt_meta_count'=>count($meta),
 'cpt_fields'=>array_column($item['meta_fields'],'name'),
 'native_taxonomies'=>array_keys($taxReqs),
 'before_existing_jet_cpt'=>0];
if(!$do){echo 'JET_CATALOG_SCHEMA_PREFLIGHT='.wp_json_encode($report,JSON_UNESCAPED_UNICODE)."\n";return;}
$id=$cptData->create_item(false);
if(!$id)throw new RuntimeException('native_jet_cpt_creation_failed');
$report['jet_cpt_id']=$id;
foreach($taxReqs as $slug=>$t){
 $taxData->set_request($t);
 $newId=$taxData->create_item(false);
 if(!$newId)throw new RuntimeException('native_jet_tax_creation_failed:'.$slug);
 $report['jet_tax_ids'][$slug]=$newId;
}
if(!update_option('mad4b_operational_services_jet_owner_v1',true,false)&&
  get_option('mad4b_operational_services_jet_owner_v1')!==true)
    throw new RuntimeException('owner_flag_persist_failed');
foreach([[$types,'operational-services'],[$taxes,'service-family'],[$taxes,'service-type']] as $row){
  $n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$row[0]} WHERE slug=%s AND status='publish'",$row[1]));
  if($n!==1)throw new RuntimeException('jet_write_readback_mismatch:'.$row[1].':'.$n);
}
echo 'JET_CATALOG_SCHEMA_EXECUTED='.wp_json_encode($report,JSON_UNESCAPED_UNICODE)."\n";
