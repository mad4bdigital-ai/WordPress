<?php
/**
 * MAD4B ContentJob typed relationship providers.
 * Native read-only planning: existing Media, Elementor, JetEngine and WPML
 * own every underlying write. No cross-language numeric-ID guessing.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Content_Relationship_Providers {
 const CONTRACT='mad4b.content-relationship-providers.v1';
 private static function nativeMeta(int $postId,string $key):string {
  global $wpdb;
  return (string)$wpdb->get_var($wpdb->prepare(
   "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d
    AND meta_key=%s ORDER BY meta_id DESC LIMIT 1",$postId,$key));
 }
 private static function state(string $kind,string $status,array $extra=[]):array {
  return ['provider_contract'=>self::CONTRACT,'kind'=>$kind,'status'=>$status,
   'write_authorized'=>false,'write_performed'=>false]+$extra;
 }
 private static function localPost(int $id,string $type,string $lang):array {
  if($id<1 || get_post_type($id)!==$type)return ['state'=>'HOLD','reason'=>'source_type_invalid'];
  return MAD4B_SCP_Content_Relationship_Graph::resolve('post',$id,$type,$lang);
 }
 private static function parent(array $source,array $target,string $lang):array {
  $a=(int)($source['post_parent']??0);
  $b=(int)($target['post_parent']??0);
  if(!$a && !$b)return self::state('parent','NOT_APPLICABLE',['owner'=>'wordpress/core-post-parent']);
  if(!$a && $b)return self::state('parent','LOCAL_OVERRIDE_PRESERVED',
      ['owner'=>'wordpress/core-post-parent','actual_target_parent'=>$b]);
  if(!$a)return self::state('parent','NOT_APPLICABLE');
  $type=(string)get_post_type($a);
  $mapped=self::localPost($a,$type,$lang);
  if(($mapped['state']??'')!=='RESOLVED')return self::state('parent','WAITING_DEPENDENCY',
      ['owner'=>'wordpress/core-post-parent','source_parent_id'=>$a,
       'reason'=>$mapped['reason']??'missing_linked_parent']);
  $expected=(int)$mapped['target_id'];
  return self::state('parent',$b===$expected?'VERIFIED':
    ($b===0?'CANDIDATE_EMPTY_TARGET':'LOCAL_OVERRIDE_PRESERVED'),[
      'owner'=>'wordpress/core-post-parent','source_parent_id'=>$a,
      'expected_target_parent'=>$expected,'actual_target_parent'=>$b,
      'eligible_for_provider_write'=>$b===0 && $expected>0]);
 }
 private static function featured(int $source,int $target,string $lang):array {
  $a=(int)self::nativeMeta($source,'_thumbnail_id');
  $b=(int)self::nativeMeta($target,'_thumbnail_id');
  $owner='wordpress/core-featured-image';
  if($b>0) {
   $s=($a>0)?self::localPost($a,'attachment',$lang):[];
   $expected=(int)($s['target_id']??0);
   return self::state('featured_image',$expected>0&&$b===$expected?
     'VERIFIED':'LOCAL_MEDIA_PRESERVED',[
     'owner'=>$owner,'source_attachment_id'=>$a,
     'actual_target_attachment_id'=>$b,'expected_target_attachment_id'=>$expected,
     'needs_media_rights_revalidation'=>false]);
  }
  if(!$a)return self::state('featured_image','NOT_APPLICABLE',['owner'=>$owner]);
  $asset=get_post($a);
  if(!$asset ||$asset->post_type!=='attachment' || !wp_attachment_is_image($a))
   return self::state('featured_image','HOLD_UNVERIFIED_SOURCE_MEDIA',
      ['owner'=>$owner,'source_attachment_id'=>$a]);
  $resolved=self::localPost($a,'attachment',$lang);
  if(($resolved['state']??'')!=='RESOLVED')
   return self::state('featured_image','WAITING_MEDIA_IDENTITY',[
     'owner'=>$owner,'source_attachment_id'=>$a,
     'reason'=>$resolved['reason']??'unlocalized_media']);
  $expected=(int)$resolved['target_id'];
  if(!wp_attachment_is_image($expected))
   return self::state('featured_image','HOLD_MEDIA_NOT_IMAGE',['owner'=>$owner]);
  // A localized image identity is not a rights/alt/quality receipt.
  return self::state('featured_image','CANDIDATE_REQUIRES_MEDIA_RIGHTS',[
    'owner'=>$owner,'source_attachment_id'=>$a,
    'expected_target_attachment_id'=>$expected,
    'provider_write_ability'=>'media/update-attachment',
    'requires_rights_and_alt_proof'=>true]);
 }
 private static function terms(int $source,int $target,string $tax,string $lang):array {
  global $wpdb;
  $st=(array)$wpdb->get_col($wpdb->prepare(
   "SELECT tt.term_id FROM {$wpdb->term_relationships} r
    JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id
    WHERE r.object_id=%d AND tt.taxonomy=%s ORDER BY tt.term_id",$source,$tax));
  $tt=array_map('intval',(array)$wpdb->get_col($wpdb->prepare(
   "SELECT tt.term_id FROM {$wpdb->term_relationships} r
    JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id
    WHERE r.object_id=%d AND tt.taxonomy=%s ORDER BY tt.term_id",$target,$tax)));
  $missing=[];$matches=[];$conflict=[];
  foreach(array_slice($st,0,200) as $tid) {
   $r=MAD4B_SCP_Content_Relationship_Graph::resolve('term',(int)$tid,$tax,$lang);
   if(($r['state']??'')!=='RESOLVED'){
    $missing[]=['source_term_id'=>(int)$tid,'reason'=>$r['reason']??'no_local_term'];
    continue;
   }
   $linked=(int)$r['target_id'];
   $matches[]=['source_term_id'=>(int)$tid,'localized_term_id'=>$linked,
     'already_assigned'=>in_array($linked,$tt,true)];
  }
  $matching=array_values(array_filter($matches,fn($v)=>!$v['already_assigned']));
  if($matching) $conflict[]='editorial_term_set_discrepancy_needs_owner_review';
  return self::state('taxonomy',!$missing&&!$matching?'VERIFIED':
    ($missing?'WAITING_TERM_TRANSLATIONS':'REVIEW_DIFF'),[
    'taxonomy'=>$tax,'owner'=>'wordpress/native-taxonomy+wpml',
    'source_count'=>count($st),'target_count'=>count($tt),
    'resolved'=>$matches,'missing'=>$missing,'differences'=>$conflict,
    'automatic_replace_entire_term_set'=>false]);
 }
 private static function walk($node,string $path,int $depth,array &$results,int &$count,string $lang):void {
  if(!is_array($node)||$depth>9||$count>=300)return;
  $types=['template_id'=>'elementor_library','popup_id'=>'elementor_library',
   'header_template'=>'elementor_library','footer_template'=>'elementor_library',
   'listing_id'=>'jet-engine','lisitng_id'=>'jet-engine',
   'listing_item_id'=>'jet-engine','filter_id'=>'jet-smart-filters',
   'filterid'=>'jet-smart-filters'];
  foreach($node as $key=>$v){
   if($count++>=300)break;
   if(is_string($key)&&isset($types[$key]) && (is_int($v)||is_string($v)) &&
      ctype_digit((string)$v) &&(int)$v>0){
    $r=self::localPost((int)$v,$types[$key],$lang);
    $results[]=[
     'path'=>substr($path.'.'.$key,0,210),
     'source_reference_id'=>(int)$v,'type'=>$types[$key],
     'localized_id'=>($r['state']??'')==='RESOLVED'?(int)$r['target_id']:null,
     'status'=>($r['state']??'')==='RESOLVED'?'RESOLVED':'WAITING_PROVIDER_REFERENCE',
     'reason'=>$r['reason']??null
    ];
    if(count($results)>=70)break;
   }elseif(is_array($v))self::walk($v,$path.'.'.$key,$depth+1,$results,$count,$lang);
  }
 }
 private static function templates(int $source,int $target,string $lang):array {
  $owner='MAD4B_WPML_Structural_Sync_V2+MAD4B_SCP_Elementor_Adapter';
  if(!class_exists('MAD4B_WPML_Structural_Sync_V2',false))
   return self::state('elementor_reference','PROVIDER_NOT_ACTIVE',['owner'=>$owner]);
  $raw=self::nativeMeta($source,'_elementor_data');
  if($raw===''||strlen($raw)>1048576)return self::state('elementor_reference',
     $raw===''?'NOT_APPLICABLE':'HOLD_OVERSIZE_DOCUMENT',['owner'=>$owner]);
  $data=json_decode($raw,true);
  if(!is_array($data))return self::state('elementor_reference',
     'SOURCE_DOCUMENT_INVALID',['owner'=>$owner]);
  $n=0;$refs=[];self::walk($data,'elements',0,$refs,$n,$lang);
  $targetRaw=self::nativeMeta($target,'_elementor_data');
  $hasTarget=is_array(json_decode($targetRaw,true));
  $pending=count(array_filter($refs,fn($v)=>$v['status']!=='RESOLVED'));
  return self::state('elementor_reference',
    !$refs?'NO_EXPLICIT_TEMPLATE_REFERENCES':
     ($pending?'WAITING_PROVIDER_REFERENCE':'REVIEW_WITH_OWNER_ADAPTER'),[
      'owner'=>$owner,'source_document_sha256'=>hash('sha256',$raw),
      'target_document_sha256'=>$targetRaw!==''?hash('sha256',$targetRaw):null,
      'target_has_document'=>$hasTarget,'references'=>$refs,
      'pending_count'=>$pending,
      'write_ability'=>'elementor/set-dynamic-tag or native wpml-structural-sync',
      'original_local_authoring_must_be_preserved'=>true]);
 }
 private static function fields(int $source,int $target,string $lang,array $job):array {
  // Only site-registered explicit schema descriptors. Unknown meta values
  // are not assumed to be IDs; financial/taxonomy records stay provider-owned.
  $descriptors=apply_filters('mad4b/contentjob/typed_relationship_meta_contracts',[],$job);
  if(!is_array($descriptors))$descriptors=[];
  $items=[];$i=0;
  foreach($descriptors as $key=>$type){
   if($i++>=24)break;
   if(!is_string($key)||!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{1,80}$/D',$key) ||
      !in_array($type,['elementor_library','page','post','attachment','jet-engine'],true))
    continue;
   $id=(int)self::nativeMeta($source,$key);
   $actual=(int)self::nativeMeta($target,$key);
   if(!$id && !$actual)continue;
   $mapped=$id?self::localPost($id,$type,$lang):[];
   $expect=(int)($mapped['target_id']??0);
   $items[]=['meta_key'=>$key,'type'=>$type,'source_id'=>$id,
     'existing_target_id'=>$actual,'expected_target_id'=>$expect,
     'state'=>$actual>0?'AUTHOR_OVERRIDE_PRESERVED':
      ($expect?'CANDIDATE_REQUIRES_ORIGINAL_META_ABILITY':'WAITING_REFERENCE'),
     'owner'=>'site-registered-typed-meta-provider'];
  }
  return self::state('typed_meta',!$items?'NO_REGISTERED_MAPPABLE_FIELDS':
    'REVIEW_REQUIRED',['owner'=>'MAD4B_SCP_Semantic_Content_Field_Contracts',
      'items'=>$items,'unregistered_field_guessing'=>false]);
 }
 public static function inspect(array $job,array $family,array $source):array {
  $sourceId=(int)($source['id']??0);
  $targetId=(int)($family['target_id']??0);
  if(($source['kind']??'')!=='post'||$sourceId<1||$targetId<1||
    !get_post($sourceId)||!get_post($targetId))
   return ['contract'=>self::CONTRACT,'state'=>'NO_POST_PAIR',
    'write_performed'=>false];
  $type=(string)get_post_type($sourceId);
  $target=get_post($targetId);$src=get_post($sourceId);
  if($target->post_type!==$type ||$sourceId===$targetId)
    return ['contract'=>self::CONTRACT,'state'=>'HOLD','reason'=>'invalid_source_pair',
      'write_performed'=>false];
  $locale=(string)$family['target_locale'];
  $find=[
    'parent'=>self::parent((array)$src,(array)$target,$locale),
    'featured_image'=>self::featured($sourceId,$targetId,$locale),
    'elementor_template_refs'=>self::templates($sourceId,$targetId,$locale),
    'typed_meta'=>self::fields($sourceId,$targetId,$locale,$job)
  ];
  // Restrict to editorial/display taxonomies until a site-owned taxonomy
  // contract explicitly expands coverage. No rate or supplier taxonomies.
  foreach(['destinations'] as $tax){
   if(taxonomy_exists($tax)&&is_object_in_taxonomy($type,$tax))
    $find['taxonomy_'.$tax]=self::terms($sourceId,$targetId,$tax,$locale);
  }
  return ['contract'=>self::CONTRACT,'state'=>'INSPECTED',
    'source_post_id'=>$sourceId,'target_post_id'=>$targetId,
    'target_locale'=>$locale,
    'registered_source_owners'=>[
      'media'=>'wordpress/core-featured-image',
      'templates'=>'MAD4B_WPML_Structural_Sync_V2',
      'meta'=>'MAD4B_SCP_Semantic_Content_Field_Contracts',
      'taxonomy'=>'wordpress/native-taxonomy+wpml'],
    'relationships'=>$find,'mutation_performed'=>false,
    'provider_write_required'=>true,
    'automatic_overwrite_existing_authoring'=>false,
    'graph_sha256'=>hash('sha256',wp_json_encode(
       [$job['job_id']??null,$job['job_revision']??null,$family['identity_sha256']??null,$find],
       JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
 }
}
