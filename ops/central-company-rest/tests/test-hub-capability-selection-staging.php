<?php
/**
 * Read-only acceptance suite for capability discovery / SelectionManifest /
 * deny-wins inheritance / deterministic mapping. No writes or network.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Hub_Discovery_Planner'))throw new RuntimeException('planner missing');
$cap=MAD4B_Hub_Discovery_Planner::capabilities();
if(($cap['counts']['queries']??0)!==203 ||
   ($cap['counts']['options_pages']??0)!==11 ||
   ($cap['counts']['fluent_forms']??0)!==10)
    throw new RuntimeException('Unexpected provider inventory');
$names=array_column($cap['provider_descriptors'],null,'id');
if($names['jet-form-builder']['active']!==false ||
   $names['jet-engine']['active']!==true ||
   $names['fluent-forms']['active']!==true)
    throw new RuntimeException('Provider activation detection failed');
if(isset($cap['jet_engine']['options_pages'][0]['values']) ||
   isset($cap['fluent_forms'][0]['submissions']))
    throw new RuntimeException('Private values exposed by discovery');
$queries=array_column($cap['jet_engine']['queries'],null,'id');
foreach(array(193,254,255,269,308) as $id){
    if(!isset($queries[$id]))throw new RuntimeException('Native Query missing: '.$id);
}
$look=array_column($cap['jet_engine']['options_pages'],null,'key')['look-and-feel'];
$lookFields=array_column($look['fields'],'type','key');
if(($lookFields['review_gellery_slider']??'')!=='gallery')
    throw new RuntimeException('Option definitions not read correctly');
$layers=array(
 'platform'=>array('deny'=>array('active_pricing'),'allow_read'=>false),
 'source_company'=>array('deny'=>array('export_submissions'),'allow_read'=>true),
 'consumer_company'=>array('deny'=>array('read_catalog')),
 'provider'=>array('deny'=>array()),
 'content_type'=>array('deny'=>array()),
 'selection'=>array('deny'=>array())
);
$inherit=MAD4B_Hub_Discovery_Planner::inherit($layers);
if(is_wp_error($inherit)||$inherit['deny']['active_pricing']!=='platform' ||
   $inherit['deny']['export_submissions']!=='source_company' ||
   $inherit['deny']['read_catalog']!=='consumer_company' ||
   $inherit['runtime_approved']!==false ||
   $inherit['source_read_proven']!==false)
    throw new RuntimeException('Deny-wins inheritance policy failed');
$reordered=array_reverse($layers,true);
if(is_wp_error(MAD4B_Hub_Discovery_Planner::inherit($reordered)))
    throw new RuntimeException('Policy keys order must not matter');
$base=array(
 'contract'=>MAD4B_Hub_Discovery_Planner::CONTRACT,
 'mode'=>'read_only_dry_run',
 'provider'=>'jet-engine',
 'source_company'=>'tourism-almallahgroup',
 'source_site_uuid'=>'a947380c-812c-42d8-a351-46f817b02a41',
 'consumer_site_uuid'=>'bea3d219-5970-491c-84c1-ecf80f795a63',
 'selection'=>array(
   'source_record_ids'=>array(24900),
   'canonical_category_ids'=>array(1863,2103),
   'canonical_taxonomy'=>'tour-types',
   'query_ids'=>array(193,254,255,269,308),
   'listing_ids'=>array(25917),
   'filter_ids'=>array(33503),
   'form_ids'=>array(6),
   'languages'=>array('en','ar'),
   'fields'=>array('title','summary','itinerary'),
   'option_fields'=>array('look-and-feel'=>array('review_gellery_slider'))
 ),
 'policy_layers'=>$layers
);
$valid=MAD4B_Hub_Discovery_Planner::validate($base);
if(is_wp_error($valid))throw new RuntimeException('Valid dry run rejected: '.$valid->get_error_code());
if($valid['runtime_approved']!==false||
   $valid['state']!=='DRY_RUN_NEEDS_EVIDENCE'||
   $valid['query_types'][193]!=='posts'||
   $valid['query_types'][254]!=='terms'||
   count($valid['selected']['verified_local_categories'])!==2 ||
   !in_array('source_record_ids_are_not_target_wordpress_ids',$valid['pending_proofs'],true) ||
   !in_array('forms_schema_and_submission_privacy_review',$valid['pending_proofs'],true))
    throw new RuntimeException('Query Plan failed to retain required ownership proofs');
$invalid=array(
 'different_tenant_source_id'=>array('source_site_uuid'=>$base['consumer_site_uuid']),
 'runtime_requested'=>array('mode'=>'publish'),
 'inactive_provider'=>array('provider'=>'jet-form-builder'),
 'wrong_query_id'=>array('selection'=>array_replace($base['selection'],array('query_ids'=>array(999999)))),
 'wrong_template_id'=>array('selection'=>array_replace($base['selection'],array('listing_ids'=>array(999999)))),
 'wrong_filter_id'=>array('selection'=>array_replace($base['selection'],array('filter_ids'=>array(999999)))),
 'wrong_form_id'=>array('selection'=>array_replace($base['selection'],array('form_ids'=>array(999999)))),
 'unknown_canonical_term'=>array('selection'=>array_replace($base['selection'],array('canonical_category_ids'=>array(999999)))),
 'unselected_locale'=>array('selection'=>array_replace($base['selection'],array('languages'=>array('fr')))),
 'unknown_option_field'=>array('selection'=>array_replace($base['selection'],array('option_fields'=>array('look-and-feel'=>array('totally_unknown_field'))))),
 'private_field'=>array('selection'=>array_replace($base['selection'],array('fields'=>array('customer_email')))),
 'duplicate_query_ids'=>array('selection'=>array_replace($base['selection'],array('query_ids'=>array(193,193)))),
 'missing_policy_layer'=>array('policy_layers'=>array_diff_key($layers,array('provider'=>true)))
);
foreach($invalid as $name=>$patch){
    $candidate=array_replace($base,$patch);
    $result=MAD4B_Hub_Discovery_Planner::validate($candidate);
    if(!is_wp_error($result))
        throw new RuntimeException('Unsafe request not rejected: '.$name);
}
$only=$base;
$only['selection']=array('languages'=>array('en'),
    'option_fields'=>array('look-and-feel'=>array('review_gellery_slider')));
$optionPlan=MAD4B_Hub_Discovery_Planner::validate($only);
if(is_wp_error($optionPlan)||$optionPlan['runtime_approved']!==false)
    throw new RuntimeException('Options-schema-only manifest should be dry-run valid');
$advice=MAD4B_Hub_Discovery_Planner::advise(array(
    'contract'=>MAD4B_Hub_Discovery_Planner::CONTRACT,
    'target_option_page'=>'look-and-feel',
    'source_field_definitions'=>array(
        array('key'=>'review_gellery_slider','type'=>'gallery'),
        array('key'=>'home_page_header_image','type'=>'media'),
        array('key'=>'unrelated_field','type'=>'switcher')
    )
));
if(is_wp_error($advice)||count($advice['suggestions'])!==2 ||
   $advice['suggestions'][0]['confidence']!==0.99 ||
   $advice['model_called']!==false ||
   $advice['activation_performed']!==false)
    throw new RuntimeException('Evidence-based advice failed');
$privateAdvice=MAD4B_Hub_Discovery_Planner::advise(array(
    'contract'=>MAD4B_Hub_Discovery_Planner::CONTRACT,
    'target_option_page'=>'look-and-feel',
    'source_field_definitions'=>array(array('key'=>'customer_email','type'=>'text'))
));
if(!is_wp_error($privateAdvice))throw new RuntimeException('PII advice should be rejected');
add_filter('mad4b_hub_discovery_provider_descriptors',static function($providers){
    return array(array('id'=>'future-external-provider','label'=>'External Catalog'));
});
$dynamic=MAD4B_Hub_Discovery_Planner::capabilities();
$future=array_column($dynamic['provider_descriptors'],null,'id')['future-external-provider']??null;
remove_all_filters('mad4b_hub_discovery_provider_descriptors');
if(!$future||$future['verified']!=='adapter_review_required'||
   $future['active']!==false)
    throw new RuntimeException('Unverified dynamic provider was granted execution');
$graph=MAD4B_Hub_Discovery_Planner::inspect_template(25917);
if(is_wp_error($graph))throw new RuntimeException('Home Tours template graph unavailable');
$filterLinks=array_values(array_filter($graph['bindings'],static fn($row)=>
    ($row['resolved_filter_id']??0)===33503));
$queryLinks=array_values(array_filter($graph['bindings'],static fn($row)=>
    ($row['resolved_query_id']??0)===193));
if(!$filterLinks||!$queryLinks||$graph['activation_performed']!==false ||
   !isset($graph['template_sha256'])||strlen($graph['template_sha256'])!==64)
    throw new RuntimeException('Native existing Query+JetSmartFilters provider graph wrong');
$unknownTemplate=MAD4B_Hub_Discovery_Planner::inspect_template(99999999);
if(!is_wp_error($unknownTemplate))throw new RuntimeException('Unknown template accepted');
echo wp_json_encode(array(
 'contract'=>MAD4B_Hub_Discovery_Planner::CONTRACT,
 'counts'=>$cap['counts'],
 'capability_digest'=>$cap['evidence_sha256'],
 'native_queries_verified'=>array(193,254,255,269,308),
 'selective_manifest_plan'=>'PASS_NO_MUTATION',
 'wpml_local_term_language_checks'=>'PASS',
 'unknown_and_foreign_ids_rejected'=>count($invalid),
 'options_schema_only'=>'PASS_NO_VALUE_READ',
 'inheritance_deny_wins'=>'PASS',
 'provider_dynamic_discovery'=>'UNVERIFIED_FAIL_CLOSED',
 'advice_matches'=>count($advice['suggestions']),
 'existing_template_graph_query_193'=>'PASS',
 'existing_template_graph_filter_33503'=>'PASS',
 'ai_llm_called'=>false,
 'signed_runtime_grant'=>false,
 'database_writes'=>0
),JSON_UNESCAPED_SLASHES),PHP_EOL;
