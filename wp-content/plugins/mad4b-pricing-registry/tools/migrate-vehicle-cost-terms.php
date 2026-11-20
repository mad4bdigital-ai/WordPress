<?php
/** Native JetEngine vehicle-cost taxonomy, owner-confirmed EGP profiles. */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$tax='transport-vehicle-types';
$apply=defined('MAD4B_VEHICLE_COST_MIGRATE')&&MAD4B_VEHICLE_COST_MIGRATE;
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('wrong_site');
$source=MAD4B_Transport_Distance_Time_V1::current();
if($source['mode']!=='simulation_only'||$source['currency']!=='EGP'||
  $source['role']!=='self_cost'||($source['vehicle_profile_owner']??'legacy_company_option')!=='legacy_company_option')
 WP_CLI::error('legacy_cost_source_not_ready');
$slugs=['limousine'=>'limousine','luxury_7_seats'=>'luxury-7-seats',
 'h1'=>'h1','hiace'=>'hiace','coaster'=>'coaster','bus'=>'bus'];
$meta=MAD4B_Transport_Vehicle_Term_Profiles_V1::META;
$limits=MAD4B_Transport_Vehicle_Term_Profiles_V1::LIMIT;
$oldTax=taxonomy_exists($tax);
global $wpdb;
$existing=$wpdb->get_var($wpdb->prepare(
 "SELECT id FROM {$wpdb->prefix}jet_taxonomies WHERE slug=%s",$tax));
if($existing||$oldTax)WP_CLI::error('taxonomy_exists_requires_review');
foreach($slugs as $key=>$slug)
 if(!isset($source['profiles'][$key]))WP_CLI::error('profile_missing_'.$key);
$backupKey='mad4b_vehicle_term_migration_backup_20261120_v1';
if(get_option($backupKey,false)!==false)WP_CLI::error('migration_already_recorded');
if(!$apply){
 echo wp_json_encode(['checkpoint'=>'PREFLIGHT','site'=>$site,
 'source_revision'=>$source['revision'],'vehicles'=>array_keys($slugs),
 'taxonomy_missing'=>true,'source_owner'=>'legacy_company_option',
 'no_live_change'=>true])."\n";return;
}
if(!add_option($backupKey,['site'=>$site,'at'=>gmdate('c'),
 'pre_migration_company_policy'=>$source,'taxonomy_slug'=>$tax,
 'terms_created'=>[],'canonical_was_active'=>false],'',false))
 WP_CLI::error('backup_create_failed');
$fields=[];$id=940100;
$titles=[
 'fixed'=>'Vehicle BASE — Included Minimum (EGP)',
 'per_km'=>'Operating Cost / KM (EGP)',
 'per_drive_min'=>'Driving Cost / Minute (EGP)',
 'per_wait_min'=>'Waiting Cost / Minute (EGP)',
 'overnight'=>'Overnight Vehicle Cost (EGP)',
 'min_fare'=>'Minimum Fare (EGP)',
 'deadhead_pct'=>'Empty Return Distance (%)'
];
foreach($meta as $key=>$name){
 $fields[]=['name'=>$name,'title'=>$titles[$key],
  'type'=>'number','object_type'=>'field','options_source'=>'manual',
  'default_val'=>'','width'=>'50%','id'=>$id++,
  'min_value'=>0,'max_value'=>$limits[$key],'step_value'=>0.01,
  'description'=>'Actual company cost EGP. Vehicle BASE is included, not additive.'];
}
$fields[]=['name'=>'mad4b_vehicle_cost_basis','title'=>'Calibration basis',
 'type'=>'select','object_type'=>'field','options_source'=>'manual',
 'options'=>[['key'=>'manual_draft','value'=>'Manual draft'],
 ['key'=>'pdf_proposal','value'=>'PDF calibration proposal']],
 'default_val'=>'manual_draft','width'=>'50%','id'=>$id++];
$fields[]=['name'=>'mad4b_vehicle_cost_samples','title'=>'Qualified examples',
 'type'=>'number','object_type'=>'field','default_val'=>0,
 'min_value'=>0,'max_value'=>51,'step_value'=>1,'id'=>$id++];
$fields[]=['name'=>'mad4b_vehicle_dispatch_mode','title'=>'BASE activation',
 'type'=>'select','object_type'=>'field','options_source'=>'manual',
 'options'=>[['key'=>'inherit','value'=>'Inherit company'],
 ['key'=>'enabled','value'=>'Enabled'],['key'=>'disabled','value'=>'Disabled']],
 'default_val'=>'inherit','id'=>$id++];
$request=['name'=>'Transport Vehicle Types','slug'=>$tax,
 'object_type'=>['transportations','transport-rates'],
 'singular_name'=>'Transport Vehicle Type','menu_name'=>'Vehicle Cost Types',
 'public'=>false,'publicly_queryable'=>false,
 'show_ui'=>true,'show_in_menu'=>true,'show_in_nav_menus'=>false,
 'show_in_rest'=>false,'rewrite'=>false,'hierarchical'=>false,
 'with_front'=>false,'show_edit_link'=>true,
 'hide_field_names'=>false,'delete_metadata'=>false,
 'query_var'=>'','rewrite_slug'=>$tax,
 'capability_type'=>'','description'=>'Internal EGP vehicle cost profiles',
 'meta_fields'=>$fields];
wp_set_current_user(1);
jet_engine()->taxonomies->data->set_request($request);
$taxId=jet_engine()->taxonomies->data->create_item(false);
if(!$taxId)WP_CLI::error('native_jetengine_taxonomy_not_created');
$args=['public'=>false,'publicly_queryable'=>false,'show_ui'=>true,
 'rewrite'=>false,'show_in_rest'=>false,'query_var'=>false];
if(!taxonomy_exists($tax))
 register_taxonomy($tax,['transportations','transport-rates'],$args);
$termIds=[];
foreach($slugs as $key=>$slug){
 $term=wp_insert_term(ucwords(str_replace('_',' ',$key)),$tax,
   ['slug'=>$slug]);
 if(is_wp_error($term))WP_CLI::error('term_'.$key.'_'.$term->get_error_code());
 $tid=(int)$term['term_id'];$termIds[$key]=$tid;
 foreach($meta as $field=>$name)
  update_term_meta($tid,$name,$source['profiles'][$key][$field]);
 update_term_meta($tid,'mad4b_vehicle_cost_basis',
   $source['profiles'][$key]['source']);
 update_term_meta($tid,'mad4b_vehicle_cost_samples',
   $source['profiles'][$key]['samples']);
 update_term_meta($tid,'mad4b_vehicle_dispatch_mode','inherit');
}
$backup=get_option($backupKey,[]);
$backup['created_jetengine_taxonomy_id']=$taxId;
$backup['created_term_ids']=$termIds;
update_option($backupKey,$backup,false);
echo wp_json_encode(['checkpoint'=>'SEEDED_NOT_ACTIVE','site'=>$site,
 'taxonomy_jetengine_id'=>$taxId,'terms'=>$termIds,
 'company_option_still_canonical'=>true,'checkout_authorized'=>false])."\n";
