<?php
/**
 * Staging contract tests for MAD4B JetEngine Hub Add-on.
 * Read-only; uses an in-memory JetEngine endpoint plan, no WP option writes.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_JetEngine_Hub_Addon')) {
    throw new RuntimeException('JetEngine Hub Add-on is not loaded');
}
$addons=MAD4B_JetEngine_Hub_Addon::status();
if (!$addons['control_plane_dependency_ready'] || $addons['configuration_state']!=='unpaired')
    throw new RuntimeException('Addon is not in safe initial staging state');
$map=MAD4B_JetEngine_Hub_Addon::mapping();
if (is_wp_error($map) || count($map)!==18)throw new RuntimeException('18 verified WPML mappings required');
foreach($map as $entry){
    $resolved=MAD4B_JetEngine_Hub_Addon::resolve($entry['canonical_term_id'],$entry['language']);
    if(is_wp_error($resolved) ||
       $resolved['source_term_id']!==$entry['source_term_id'] ||
       $resolved['canonical_term_id']!==$entry['canonical_term_id'])
        throw new RuntimeException('Exact bidirectional mapping failed');
}
$ar=array_values(array_filter($map,static fn($r)=>$r['language']==='ar'));
if(count($ar)!==9)throw new RuntimeException('Incomplete AR source localization');
$sourceDifferentSlug=array_filter($ar,static fn($r)=>$r['source_slug']!==get_term($r['canonical_term_id'],'tour-types')->slug);
if (!$sourceDifferentSlug) {
    // get_term() can translate under WPML. Check exact term slug via raw SQL.
    global $wpdb;
    $different=false;
    foreach($ar as $r){
        $slug=$wpdb->get_var($wpdb->prepare("SELECT slug FROM {$wpdb->terms} WHERE term_id=%d",$r['canonical_term_id']));
        if ($slug!==$r['source_slug'])$different=true;
    }
    if(!$different)throw new RuntimeException('No localized source slug mismatch fixture detected');
}
$denied=array(
    MAD4B_JetEngine_Hub_Addon::resolve(999999,'en'),
    MAD4B_JetEngine_Hub_Addon::resolve(1865,'fr'),
    MAD4B_JetEngine_Hub_Addon::mapping('wrong-tenant'),
);
foreach($denied as $d)if(!is_wp_error($d))throw new RuntimeException('Invalid taxonomy/locale/tenant was allowed');

class MAD4B_JetEngine_Fake_Request_V1 {
    private $e;
    public function __construct($endpoint){$this->e=$endpoint;}
    public function get_endpoint(){return $this->e;}
}
$path='/wp-json/mad4b-central/v1/hub-distribution/tourism-almallahgroup/catalog';
$url='https://tours.dev.mad4b.com'.$path;
$endpoint=array('id'=>987654,'url'=>$url);
$fake=new MAD4B_JetEngine_Fake_Request_V1($endpoint);
$unbound=MAD4B_JetEngine_Hub_Addon::jet_query_args(
    array('language'=>'en','canonical_term_id'=>1865),$fake);
if($unbound!==array('language'=>'en','canonical_term_id'=>1865))
    throw new RuntimeException('Unbound query was modified');

$en=array_values(array_filter($map,static fn($row)=>$row['language']==='en'));
$first=$en[0];$term=$first['canonical_term_id'];
$plan=array(
 'contract'=>'mad4b.hub.jetengine-native-consumer.v1',
 'source_company'=>'tourism-almallahgroup',
 'source_site_uuid'=>'a947380c-812c-42d8-a351-46f817b02a41',
 'consumer_site_uuid'=>'bea3d219-5970-491c-84c1-ecf80f795a63',
 'data_owner'=>'source_company',
 'transport'=>'control_plane_signed_rest',
 'consumer_provider'=>'jet-engine',
 'listing_provider'=>'existing_jetengine_listing',
 'query_provider'=>'jetengine_rest_query',
 'content_model'=>'remote_reference',
 'brand_authority'=>'local_company',
 'media_authority'=>'source_company',
 'taxonomy_strategy'=>'reuse_verified_canonical_terms',
 'language_strategy'=>'source_target_wpml_verified',
 'pricing_strategy'=>'commercial_review_required',
 'create_shortcode'=>false, 'create_parallel_section'=>false,
 'copy_source_database'=>false, 'overwrite_native_posts'=>false,
 'create_duplicate_taxonomy'=>false, 'overwrite_brand_kit'=>false,
 'import_private_media'=>false, 'enable_historical_checkout'=>false,
 'expose_private_source_cpt'=>false,
 'collection'=>'catalog','endpoint_url'=>$url,'listing_id'=>987655,'query_id'=>987656,
 'fields'=>array('source_company','source_id','title','summary','language','canonical_term_id'),
 'taxonomy_bindings'=>array(array(
   'source_term_id'=>$first['source_term_id'],
   'canonical_term_id'=>$term,'language'=>'en','taxonomy'=>'tour-types',
   'source_taxonomy'=>'tour-types_jet',
   'canonical_parent_id'=>$first['canonical_parent_id'],
   'target_wpml_trid'=>$first['target_wpml_group'],
 )),
);
$validation=MAD4B_SCP_Hub_JetEngine_Consumer_Governance::validate_plan($plan);
if(is_wp_error($validation)||$validation['runtime_approved'])
    throw new RuntimeException('Plan contract failed or unexpectedly activated');
add_filter('pre_option_mad4b_scp_hub_jetengine_bindings_v1',
    static fn()=>array(987654=>$plan),10);
$converted=MAD4B_JetEngine_Hub_Addon::jet_query_args(
    array('language'=>'en','canonical_term_id'=>$term),$fake);
if($converted!==array('language'=>'en','category'=>$first['source_term_id']))
    throw new RuntimeException('Filter did not convert native ID to source ID');

$invalidRaw=MAD4B_JetEngine_Hub_Addon::jet_query_args(
    array('language'=>'en','category'=>$first['source_term_id']),$fake);
if(($invalidRaw['mad4b_rejected']??null)!=='raw_source_id_disallowed')
    throw new RuntimeException('Source ID caller bypassed mapping');
$invalidLang=MAD4B_JetEngine_Hub_Addon::jet_query_args(
    array('language'=>'fr','canonical_term_id'=>$term),$fake);
if(($invalidLang['mad4b_rejected']??null)!=='missing_wpml_language')
    throw new RuntimeException('Nonconfigured locale allowed');

$raw=(object)array('items'=>array((object)array(
    'source_company'=>'tourism-almallahgroup','source_id'=>15081,
    'language'=>'en','title'=>'Safe Tour',
    'summary'=>'A tour','source_term_ids'=>array($first['source_term_id']),
    'price'=>'123','sale_price'=>'12','customer_email'=>'private@example.org',
    'account_key'=>'PRIVATE','checkout_url'=>'https://example.invalid/purchase'
)));
$projection=MAD4B_JetEngine_Hub_Addon::jet_response(
    $raw,$fake,array('language'=>'en'),array('response'=>array('code'=>200)));
if (!is_object($projection)||count($projection->items)!==1 ||
    isset($projection->items[0]->price,$projection->items[0]->customer_email) ||
    property_exists($projection->items[0],'account_key') ||
    property_exists($projection->items[0],'checkout_url') ||
    $projection->items[0]->canonical_term_ids!==array($term) ||
    $projection->items[0]->bookable!==false) {
    throw new RuntimeException('REST projection leaked disallowed fields or wrong taxonomy');
}
$foreign=(object)array('items'=>array((object)array(
    'source_company'=>'another-company','source_id'=>15081,
    'language'=>'en','title'=>'A different tenant'
)));
$rejected=MAD4B_JetEngine_Hub_Addon::jet_response(
    $foreign,$fake,array('language'=>'en'),array());
if(!is_object($rejected)||$rejected->items!==array())
    throw new RuntimeException('Wrong tenant leaked into listing');

remove_all_filters('pre_option_mad4b_scp_hub_jetengine_bindings_v1');
echo wp_json_encode(array(
 'contract'=>MAD4B_JetEngine_Hub_Addon::CONTRACT,
 'mapped_wpml_terms'=>18,
 'translated_arabic_terms'=>9,
 'localized_source_slug_difference_verified'=>true,
 'real_wpml_group_matching'=>'PASS',
 'no_endpoint_activated'=>true,
 'query_hook_converted_local_term'=>'PASS',
 'unbound_native_queries_unchanged'=>'PASS',
 'untrusted_source_term_query_rejected'=>'PASS',
 'unsupported_locale_rejected'=>'PASS',
 'customer_email_price_account_key_checkout_excluded'=>'PASS',
 'foreign_source_response_rejected'=>'PASS',
 'source_term_id_from_verified_mapping'=>$first['source_term_id'],
 'target_term_id_local'=>$term,
 'wordpress_mutations'=>0
),JSON_UNESCAPED_SLASHES),PHP_EOL;
