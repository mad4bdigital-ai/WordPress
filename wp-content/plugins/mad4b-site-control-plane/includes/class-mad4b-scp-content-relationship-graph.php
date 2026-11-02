<?php
/**
 * MAD4B Control Plane — source-backed multilingual ContentJob relationship graph.
 * Discovers native WPML identity across post/term groups, and returns exact
 * localized references. Read-only: never creates a post, term, or relationship.
 * Intentional 1:N providers, market filters and financial relations remain owned
 * by their original domain implementations, NOT inferred by name/slug.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Content_Relationship_Graph {
 const CONTRACT='mad4b.content-relationship-graph.v1';
 const LANGS=['ar','de','en','es','fr','it'];
 private static function hold(string $code,array $extra=[]):array {
  return ['contract'=>self::CONTRACT,'state'=>'HOLD','reason'=>$code,
   'mutation_performed'=>false]+$extra;
 }
 private static function site():array {
  $p=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
  if(!is_array($p)||empty($p['site_uuid'])||empty($p['origin_match'])||
    empty($p['authority_ready'])||empty($p['deployment_binding_match']))return [];
  return ['site_uuid'=>(string)$p['site_uuid'],'environment'=>wp_get_environment_type()];
 }
 private static function validLocale(string $loc):bool {
  if(!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$loc))return false;
  $langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
  if(!is_array($langs)||!$langs)return in_array($loc,self::LANGS,true);
  return isset($langs[$loc]);
 }
 private static function termTaxonomyId(int $id,string $tax):int {
  global $wpdb;
  return (int)$wpdb->get_var($wpdb->prepare(
   "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
    WHERE term_id=%d AND taxonomy=%s LIMIT 1",$id,$tax));
 }
 private static function identity(string $kind,int $id,string $type):?array {
  global $wpdb;
  if($kind==='post') {
   $post=get_post($id);
   if(!$post||$post->post_type!==$type||in_array($post->post_status,
       ['trash','auto-draft','inherit'],true))return null;
   $element=$id;
   $status=(string)$post->post_status;
  }elseif($kind==='term'){
   $element=self::termTaxonomyId($id,$type);
   if(!$element)return null;
   $status='term';
  }else return null;
  $elementType=($kind==='post'?'post_':'tax_').$type;
  $record=$wpdb->get_row($wpdb->prepare(
    "SELECT trid,language_code,source_language_code FROM {$wpdb->prefix}icl_translations
     WHERE element_type=%s AND element_id=%d LIMIT 1",$elementType,$element),ARRAY_A);
  if(!$record||!((int)$record['trid'])||!self::validLocale((string)$record['language_code']))
   return null;
  return ['kind'=>$kind,'type'=>$type,'id'=>$id,'element_id'=>$element,
   'element_type'=>$elementType,'trid'=>(int)$record['trid'],
   'locale'=>$record['language_code'],'status'=>$status];
 }
 private static function group(array $id):array {
  global $wpdb;
  $rows=(array)$wpdb->get_results($wpdb->prepare(
   "SELECT element_id,language_code,source_language_code FROM {$wpdb->prefix}icl_translations
    WHERE trid=%d AND element_type=%s ORDER BY language_code,element_id",
    $id['trid'],$id['element_type']),ARRAY_A);
  $group=[];$duplicate=[];
  foreach($rows as $row){
   $locale=(string)$row['language_code'];
   if(!self::validLocale($locale))continue;
   $element=(int)$row['element_id'];
   if($id['kind']==='term'){
    $n=$wpdb->get_row($wpdb->prepare(
      "SELECT term_id FROM {$wpdb->term_taxonomy}
       WHERE term_taxonomy_id=%d AND taxonomy=%s LIMIT 1",
      $element,$id['type']),ARRAY_A);
    $target=(int)($n['term_id']??0);
   }else $target=$element;
   if(!$target)continue;
   $one=self::identity($id['kind'],$target,$id['type']);
   if(!$one||$one['trid']!==$id['trid']||$one['locale']!==$locale)continue;
   if(isset($group[$locale])){$duplicate[$locale]=true;continue;}
   $group[$locale]=['id'=>$target,'status'=>$one['status'],
    'trid'=>$one['trid'],'locale'=>$locale];
  }
  foreach($duplicate as $locale=>$_)unset($group[$locale]);
  ksort($group);
  return ['members'=>$group,'ambiguous_locales'=>array_keys($duplicate)];
 }
 /**
  * Reuse the certified MAD4B translation-bridge instead of implementing
  * a second post-language/group resolver. ACI01 already audits this same
  * adapter. Cross-site IDs and name/slug guesses are never accepted.
  */
 private static function postViaTranslationBridge(int $postId,string $type,string $target):array {
  if(!class_exists('MAD4B_SCP_Adapter_Registry',false))
    return self::hold('existing_translation_bridge_registry_missing');
  $a=MAD4B_SCP_Adapter_Registry::instance()->get('translation-bridge');
  if(!is_object($a)||!method_exists($a,'translation_get_post')||
     !method_exists($a,'translation_status')||!$a->is_available())
    return self::hold('existing_translation_bridge_missing');
  $status=$a->translation_status();
  if(!is_array($status))return self::hold('translation_provider_inventory_missing');
  if(($status['wpml']??false)===true &&($status['polylang']??false)===true)
    return self::hold('translation_provider_explicit_selection_required');
  $provider=($status['wpml']??false)===true?'wpml':
    ((($status['polylang']??false)===true)?'polylang':'');
  if($provider==='')return self::hold('translation_provider_not_connected');
  $a1=$a->translation_get_post(['post_id'=>$postId,'provider'=>$provider]);
  $a2=$a->translation_get_post(['post_id'=>$postId,'provider'=>$provider]);
  if(is_wp_error($a1)||is_wp_error($a2)||!is_array($a1)||$a1!==$a2)
    return self::hold('translation_bridge_snapshot_unstable');
  if(($a1['post_type']??'')!==$type||
    (int)($a1['post_id']??0)!==$postId||
    !self::validLocale((string)($a1['language']??''))||
    empty($a1['group'])||!is_array($a1['translations']??null))
    return self::hold('translation_bridge_identity_mismatch');
  $family=[];
  foreach($a1['translations'] as $locale=>$memberId){
   if(!is_string($locale)||!self::validLocale($locale)||!is_int($memberId)||$memberId<1)
     return self::hold('bridge_group_has_invalid_member');
   $p=get_post($memberId);
   if(!$p||$p->post_type!==$type||
      in_array($p->post_status,['trash','auto-draft','inherit'],true))
      return self::hold('bridge_group_member_unavailable');
   $family[$locale]=['id'=>$memberId,'locale'=>$locale,'status'=>$p->post_status];
  }
  if(count(array_unique(array_column($family,'id')))!==count($family)||
    ($family[$a1['language']]['id']??0)!==$postId)
    return self::hold('bridge_group_has_duplicate_or_wrong_locale');
  ksort($family);
  $chosen=$family[$target]??null;
  if(!$chosen)return self::hold('target_language_member_missing',
    ['provider'=>$provider,'family'=>['members'=>$family],
    'target_locale'=>$target]);
  return ['contract'=>self::CONTRACT,'state'=>'RESOLVED',
   'provider'=>$provider,'identity_authority'=>MAD4B_SCP_Translation_Bridge_Adapter::CONTRACT,
   'kind'=>'post','object_type'=>$type,'canonical_group'=>(string)$a1['group'],
   'canonical_trid'=>$provider==='wpml'?(int)($a1['trid']??0):null,
   'source_id'=>$postId,'source_locale'=>$a1['language'],
   'target_id'=>$chosen['id'],'target_locale'=>$target,
   'target_status'=>$chosen['status'],
   'family'=>['members'=>$family,'ambiguous_locales'=>[]],
   'identity_sha256'=>hash('sha256',wp_json_encode([$a1,$family,self::site()],
      JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
   'mutation_performed'=>false];
 }
 public static function resolve(string $kind,int $id,string $type,string $target):array {
  if(!self::site())return self::hold('site_binding_unavailable');
  $kind=sanitize_key($kind);
  $type=sanitize_key($type);
  if(!in_array($kind,['post','term'],true) || $id<1 ||
    !preg_match('/^[a-z0-9_-]{2,64}$/D',$type) ||!self::validLocale($target))
    return self::hold('invalid_reference');
  // Posts are resolved by the pre-existing certified translation bridge,
  // NOT by this class' own implementation of WPML translation groups.
  if($kind==='post')return self::postViaTranslationBridge($id,$type,$target);
  $native=self::identity($kind,$id,$type);
  if(!$native)return self::hold('native_wpml_identity_not_found');
  $family=self::group($native);
  if(in_array($target,$family['ambiguous_locales'],true))
    return self::hold('ambiguous_target_language_in_family',
     ['source_identity'=>$native,'family'=>$family]);
  $selected=$family['members'][$target]??null;
  if(!$selected)return self::hold('target_language_member_missing',
      ['source_identity'=>$native,'family'=>$family,'target_locale'=>$target]);
  return ['contract'=>self::CONTRACT,'state'=>'RESOLVED',
   'kind'=>$kind,'object_type'=>$type,'canonical_trid'=>$native['trid'],
   'source_id'=>$id,'source_locale'=>$native['locale'],
   'target_id'=>$selected['id'],'target_locale'=>$target,
   'target_status'=>$selected['status'],'family'=>$family,
   'identity_sha256'=>hash('sha256',wp_json_encode([
     $native,$family,$target,self::site()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
   'mutation_performed'=>false];
 }
 private static function findSubjectSource(array $job):?array {
  $type=(string)($job['target_post_type']??'');
  $subject=(string)($job['subject']??'');
  // Bounded *known* source convention, not arbitrary numbers in the title.
  if($type!=='' && preg_match('/^Elementor section translation\s+([a-z0-9_-]+)\s+#([0-9]+)\s+\[([a-z]{2})\]/D',$subject,$m)
    &&$m[1]===$type &&$m[3]===($job['language']??null))
    return ['kind'=>'post','id'=>(int)$m[2],'type'=>$type];
  // Native ContentJob intake has an immutable readiness issue reference,
  // not arbitrary source IDs embedded in a free-form subject.
  if(preg_match('/wpml-url-issue-ref:([a-f0-9]{64})/D',$subject,$reference)){
   $ledger=(array)get_option('mad4b_content_readiness_issues_v1',[]);
   $issue=$ledger[$reference[1]]??null;
   if(is_array($issue) &&
      ($issue['content_job_id']??null)===($job['job_id']??null) &&
      ($issue['status']??null)==='open' &&
      preg_match('/^dfsb_wpml_(?:term_missing|route_slug)_([a-z0-9_-]+)_([0-9]+)_([a-z]{2})$/D',
        (string)($issue['code']??''),$m) &&
      $m[3]===($job['language']??null))
      return ['kind'=>'term','id'=>(int)$m[2],'type'=>$m[1],
       'evidence_issue_id'=>$reference[1]];
  }
  return null;
 }
 /**
  * Explicit ContentJob integration. Does not fabricate target artifacts:
  * missing translations become dependencies, not duplicate new pages.
  */
 public static function planJob(string $jobId,int $artifactId=0,string $artifactType=''):array {
  $site=self::site();
  if(!$site||!class_exists('MAD4B_SCP_Content_Jobs'))
    return self::hold('site_or_contentjob_unavailable');
  if(!preg_match('/^[0-9a-f-]{36}$/D',$jobId))
    return self::hold('invalid_contentjob_id');
  $r=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobId]);
  if(is_wp_error($r)||!is_array($r['job']??null))
    return self::hold('contentjob_not_found');
  $job=$r['job'];
  if(($job['site_uuid']??null)!==$site['site_uuid'])
    return self::hold('cross_site_job_denied');
  $locale=(string)($job['language']??'');
  if(!self::validLocale($locale))return self::hold('job_target_language_invalid');
  $artifactId=$artifactId?:((ctype_digit((string)($job['current_artifact_id']??'')))
    ?(int)$job['current_artifact_id']:0);
  $source=self::findSubjectSource($job);
  if(!$source&&$artifactId && $artifactType==='post'){
   $p=get_post($artifactId);
   if($p)$source=['kind'=>'post','id'=>$artifactId,'type'=>$p->post_type];
  }
  $result=[
    'contract'=>self::CONTRACT.'.contentjob',
    'job_id'=>$jobId,'job_revision'=>(int)($job['job_revision']??0),
    'job_state'=>$job['state'],'target_locale'=>$locale,
    'artifact_id'=>$artifactId,'source_reference'=>$source,
    'site_uuid'=>$site['site_uuid'],'environment'=>$site['environment'],
    'mutation_performed'=>false,'auto_publish'=>false
  ];
  if(!$source)return $result+['state'=>'HOLD','reason'=>'source_reference_unavailable'];
  $mapped=self::resolve($source['kind'],$source['id'],$source['type'],$locale);
  $result['family']=$mapped;
  if(($mapped['state']??'')!=='RESOLVED')return $result+[
     'state'=>'WAITING_DEPENDENCY','reason'=>$mapped['reason']??'member_missing'];
  if($artifactId && $artifactId!==(int)$mapped['target_id'])
    return $result+['state'=>'HOLD','reason'=>'artifact_not_same_translation_family'];
  if(!$artifactId)return $result+['state'=>'WAITING_ARTIFACT',
    'reason'=>'localized_member_exists_but_not_bound_to_job',
    'candidate_local_id'=>$mapped['target_id']];
  $result['state']='READY';
  $result['reason']='exact_localized_member_verified';
  // Live tasks can request additional typed subgraphs, but only
  // registered source-compatible providers can implement them.
  $extensions=apply_filters('mad4b/contentjob/relationship_graph_extensions',[],
    $job,$mapped);
  $result['extensions']=is_array($extensions)?$extensions:[];
  $result['graph_sha256']=hash('sha256',wp_json_encode([
    $jobId,$job['job_revision'],$mapped,$result['extensions'],$site],
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $result;
 }
 public static function catalog():array {
  return ['contract'=>self::CONTRACT,'supported_reference_kinds'=>[
   'post_translation_family','taxonomy_term_translation_family'],
   'providers'=>['core_native_wpml_trid','site_specific_relationship_extensions'],
   'source_priority'=>'exact WPML family, independent of source locale',
   'contentjob_dependency'=>'WAITING_ARTIFACT or WAITING_DEPENDENCY without synthetic page creation',
   'write_policy'=>'existing feature-specific CWA acceptance and WordPress native permission',
   'unsupported_default'=>['pricing','supplier_bookings','finance','guess_by_slug'],
   'publication_impact'=>false,'mutation_performed'=>false];
 }
}
