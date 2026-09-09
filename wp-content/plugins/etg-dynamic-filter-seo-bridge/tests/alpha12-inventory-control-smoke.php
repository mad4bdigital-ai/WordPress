<?php
declare(strict_types=1);
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function expect_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}
require_once dirname(__DIR__).'/includes/Diagnostics/InventoryProfilePlanner.php';
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner;

$snapshot=array('snapshot_fingerprint'=>'prod-like','inventory'=>array(
 'post_types'=>array(
  'tours-and-activities'=>array('label'=>'Tours & Activities','has_archive'=>true,'archive_paths'=>array('current'=>'/tours-and-activities/')),
  'properties'=>array('label'=>'Properties','has_archive'=>true,'archive_paths'=>array('current'=>'/properties/')),
 ),
 'taxonomies'=>array(
  'location_jet'=>array('label'=>'Location','publicly_queryable'=>true,'hierarchical'=>true,'object_type'=>array('tours-and-activities')),
  'tour-types_jet'=>array('label'=>'Tour Types','publicly_queryable'=>true,'hierarchical'=>true,'object_type'=>array('tours-and-activities')),
  'tour-styles_jet'=>array('label'=>'Tour Styles','publicly_queryable'=>true,'hierarchical'=>true,'object_type'=>array('tours-and-activities')),
  'durations_jet'=>array('label'=>'Durations','publicly_queryable'=>true,'hierarchical'=>true,'object_type'=>array('tours-and-activities')),
  'property-location'=>array('label'=>'Property Location','publicly_queryable'=>true,'hierarchical'=>true,'object_type'=>array('properties')),
 ),
 'query_builder'=>array(
  'available'=>true,'identity_index_complete'=>true,
  'identity_index'=>array(
   array('id'=>'5','custom_query_id'=>'tours_query_archive','identity_key'=>'tours_query_archive','type'=>'posts','post_types'=>array('tours-and-activities'),'post_type_bounded'=>true),
   array('id'=>'239','custom_query_id'=>'properties_archive_qb','identity_key'=>'properties_archive_qb','type'=>'posts','post_types'=>array('properties'),'post_type_bounded'=>true),
   array('id'=>'1','custom_query_id'=>'locations_exept_current','identity_key'=>'locations_exept_current','type'=>'posts','post_types'=>array('locations'),'post_type_bounded'=>true),
   array('id'=>'70','custom_query_id'=>'locations_exept_current','identity_key'=>'locations_exept_current','type'=>'posts','post_types'=>array('locations'),'post_type_bounded'=>true),
  ),
  'identity_conflicts'=>array(array('identity_key'=>'locations_exept_current','count'=>2)),
 ),
 'elementor_topology'=>array('available'=>true,'bindings'=>array(
  array('provider'=>'jet-engine','provider_query_id'=>'tours_query_archive','status'=>'verified','query_builder_internal_id'=>'5','query_builder_custom_query_id'=>'tours_query_archive','query_type'=>'posts','post_types'=>array('tours-and-activities'),'template_ids'=>array(30843)),
  array('provider'=>'jet-engine','provider_query_id'=>'property_query_archive','status'=>'verified','query_builder_internal_id'=>'239','query_builder_custom_query_id'=>'properties_archive_qb','query_type'=>'posts','post_types'=>array('properties'),'template_ids'=>array(37924)),
 )),
));
$profile=array('id'=>'tours','enabled'=>false,'post_types'=>array(),'require_post_type_binding'=>false,'post_type_authority'=>'query_builder','archive_paths'=>array('/tours-and-activities/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_query_archive')),'taxonomy_rules'=>array('location_jet'=>array('role'=>'location'),'tour-types_jet'=>array('role'=>'tour_type'),'tour-styles_jet'=>array('role'=>'style')),'allowed_taxonomy_sets'=>array('location_jet','location_jet+tour-types_jet'));
$composed=array('id'=>'composed','enabled'=>false,'post_types'=>array(),'require_post_type_binding'=>false,'post_type_authority'=>'query_builder','archive_paths'=>array('/destinations/'),'routes'=>array(
 array('provider'=>'jet-engine','query_id'=>'tours_query_archive'),
 array('provider'=>'jet-engine','query_id'=>'property_query_archive'),
),'taxonomy_rules'=>array(),'allowed_taxonomy_sets'=>array());
$plan=(new InventoryProfilePlanner())->plan($snapshot,array('tours'=>$profile,'composed'=>$composed));
expect_same('etg.dfsb.inventory-profile-plan.v1',$plan['contract'],'contract');
expect_same(false,$plan['authorizing'],'plan non-authorizing');
$p=$plan['proposals']['tours'];
expect_same(true,$p['safe_to_apply'],'verified route can produce safe structural patch');
expect_same(array('tours-and-activities'),$p['proposed_profile']['post_types'],'post type auto-discovered');
expect_same(true,$p['proposed_profile']['require_post_type_binding'],'post type binding enforced');
expect_same(false,$p['proposed_profile']['enabled'],'proposal cannot enable profile');
expect_same('tours_query_archive',$p['proposed_profile']['routes'][0]['provider_query_id'],'provider namespace explicit');
expect_same('tours_query_archive',$p['proposed_profile']['routes'][0]['query_builder_query_id'],'Query Builder namespace explicit');
expect_same(false,$p['taxonomy_candidates']['durations_jet']['configured'],'new attached taxonomy remains opt-in');
expect_same(false,$p['taxonomy_candidates']['durations_jet']['indexing_set_added'],'taxonomy suggestion never grants indexing set');
expect_true(!in_array('locations_exept_current',$p['blocking_reasons'],true),'unrelated identity collision does not poison Tours proposal');
$c=$plan['proposals']['composed'];
expect_same(false,$c['safe_to_apply'],'composed profile with mixed post-type routes cannot be auto-applied');
expect_same('blocked',$c['status'],'composed mixed-authority proposal is blocked');
expect_true(in_array('routes_resolve_to_different_post_type_sets',$c['blocking_reasons'],true),'mixed post-type routes fail closed');
expect_same(array(),$c['observed_post_types'],'mixed post-type routes do not collapse into one observed authority set');
expect_same(false,$c['proposed_profile']['enabled'],'blocked composed proposal remains disabled');

$page=file_get_contents(dirname(__DIR__).'/includes/Admin/InventoryControlPage.php');
$compact=preg_replace('/\s+/','',$page);
expect_true(false!==strpos($page,'Global bridge must be OFF'),'apply locked when Global is on');
expect_true(false!==strpos($compact,'hash_equals($expectedFingerprint,$actualFingerprint)'),'inventory fingerprint concurrency guard');
expect_true(false!==strpos($compact,'hash_equals($expectedRevision,$this->config->revision())'),'configuration revision concurrency guard');
expect_true(false!==strpos($compact,"\$raw['enabled']=false"),'apply forces profile disabled');
expect_true(false!==strpos($compact,"\$config['enabled']=false"),'apply keeps Global off');
expect_true(false!==strpos($page,'allowed_taxonomy_sets')||false!==strpos(strtolower($page),'allowed taxonomy sets'),'UI declares indexing sets are not granted');
expect_true(false!==strpos($page,'provider_observation_verified'),'authority change invalidates publication evidence');
expect_true(false!==strpos($page,'result_count_parity_verified'),'result-count evidence invalidated');

echo "Alpha12 inventory control smoke tests passed.\n";
