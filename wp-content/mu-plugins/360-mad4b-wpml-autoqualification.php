<?php
/**
 * Plugin Name: MAD4B WPML ContentJob Auto Qualification (Staging)
 * Description: Source verified WPML term/base qualification and reversible canary publication.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_WPML_Auto_Qualification_V1 {
 const CONTRACT='mad4b.wpml-autoqualification.v1';
 const SITE='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const OPERATOR=26;
 const POLICY='mad4b_wpml_auto_qualification_policy_v1';
 const REPORT='mad4b_wpml_auto_qualification_report_v1';
 const LOCK='mad4b_wpml_url_jobs_';
 const MAX_RELEASES=2;
 public static function boot() {
  if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b wpml-auto-qualify',[__CLASS__,'cli']);
  add_action('admin_menu',[__CLASS__,'menu'],95);
 }
 private static function scope(bool $write=false) {
  if(!defined('WP_CLI') || !WP_CLI || PHP_SAPI!=='cli' ||
   wp_get_environment_type()!=='staging' ||
   rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com' ||
   !class_exists('MAD4B_SCP_Site_Profile') ||
   MAD4B_SCP_Site_Profile::site_uuid()!==self::SITE)
   return new WP_Error('wpml_qualification_scope_denied');
  if($write && (get_current_user_id()!==self::OPERATOR ||
     wp_get_current_user()->user_login!=='mad4b_connection_cc0e557e5804' ||
     !class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_mutate()))
   return new WP_Error('wpml_qualification_operator_denied');
  return true;
 }
 private static function nativeMeta(int $id,string $key):string {
  global $wpdb;
  return (string)$wpdb->get_var($wpdb->prepare(
   "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id=%d AND meta_key=%s ORDER BY meta_id DESC LIMIT 1",$id,$key));
 }
 private static function termRow(int $id,string $tax):?array {
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT t.term_id,t.name,t.slug,tt.parent,tt.description,tt.term_taxonomy_id
    FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
    WHERE t.term_id=%d AND tt.taxonomy=%s LIMIT 1",$id,$tax),ARRAY_A);
  return is_array($row)?$row:null;
 }
 private static function wpmlRow(int $term,string $tax):?array {
  global $wpdb;
  $r=$wpdb->get_row($wpdb->prepare(
   "SELECT trid,language_code,source_language_code FROM {$wpdb->prefix}icl_translations
    WHERE element_id=%d AND element_type=%s LIMIT 1",$term,'tax_'.$tax),ARRAY_A);
  return is_array($r)?$r:null;
 }
 private static function reason(string $code,array $extra=[]):array {
  return array_merge(['eligible'=>false,'reason'=>$code],$extra);
 }
 private static function languageTerm(int $source,string $tax,string $lang):int {
  global $wpdb;
  $trid=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s",
   $source,'tax_'.$tax));
  if(!$trid)return 0;
  return (int)$wpdb->get_var($wpdb->prepare(
   "SELECT element_id FROM {$wpdb->prefix}icl_translations
    WHERE trid=%d AND element_type=%s AND language_code=%s LIMIT 1",
    $trid,'tax_'.$tax,$lang));
 }
 private static function termPostCount(int $id,string $locale,string $taxonomy):int {
  global $wpdb;
  $term=self::termRow($id,$taxonomy); if(!$term)return 0;
  return (int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
    JOIN {$wpdb->term_relationships} rel ON rel.object_id=p.ID
    JOIN {$wpdb->prefix}icl_translations lang
     ON lang.element_id=p.ID AND lang.element_type=CONCAT('post_',p.post_type)
    WHERE rel.term_taxonomy_id=%d AND p.post_status='publish'
      AND p.post_password='' AND p.post_type='tours-and-activities'
      AND lang.language_code=%s
      AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} ban
       WHERE ban.post_id=p.ID AND ban.meta_key='tour_public_status'
        AND ban.meta_value IN ('0','false','no','disabled'))
      AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} extra
       WHERE extra.post_id=p.ID AND extra.meta_key='tour_mode' AND extra.meta_value='extra_trip')",
   (int)$term['term_taxonomy_id'],$locale));
 }
 private static function termEvidence(string $tax,int $source,string $locale):array {
  $original=self::termRow($source,$tax);
  if(!$original)return self::reason('canonical_source_missing');
  $langSource=self::wpmlRow($source,$tax);
  if(!$langSource || $langSource['language_code']!=='en')
   return self::reason('canonical_wpml_identity_invalid');
  if($tax==='destinations' && self::nativeMeta($source,'is_public')!=='1')
   return self::reason('source_not_public');
  if($tax==='destinations' && function_exists('destinations_activation_allowed') &&
    !destinations_activation_allowed($source))return self::reason('destination_activation_denied');
  $id=self::languageTerm($source,$tax,$locale);
  if(!$id)return self::reason('translation_missing');
  $translated=self::termRow($id,$tax);
  $relation=self::wpmlRow($id,$tax);
  if(!$translated || !$relation || $relation['trid']!==$langSource['trid'] ||
   $relation['language_code']!==$locale)return self::reason('wpml_family_mismatch');
  if(self::nativeMeta($id,'_mad4b_wpml_review_required')!=='1')
   return self::reason('not_owned_review_candidate',['target_term_id'=>$id]);
  if(self::nativeMeta($id,'_mad4b_wpml_source_term_id')!==(string)$source)
   return self::reason('translation_provenance_missing',['target_term_id'=>$id]);
  if((int)$original['parent']>0) {
   $parent=self::languageTerm((int)$original['parent'],$tax,$locale);
   if(!$parent || $parent!==(int)$translated['parent'])
    return self::reason('translated_parent_incorrect',['target_term_id'=>$id]);
  }
  $sourceText=trim(wp_strip_all_tags($original['description']));
  $text=trim(wp_strip_all_tags($translated['description']));
  if(mb_strlen($sourceText)<200 || mb_strlen($text)<220 ||
   mb_strlen($text)<(int)(mb_strlen($sourceText)*0.42) ||
   trim($original['name'])===trim($translated['name']) ||
   $text===$sourceText)
   return self::reason('localized_editorial_content_incomplete',['target_term_id'=>$id]);
  if($locale==='ar' && !preg_match('/[\x{0600}-\x{06FF}]/u',$translated['name'].$text))
   return self::reason('arabic_language_evidence_missing',['target_term_id'=>$id]);
  if(!preg_match('/^[a-z0-9_-]+$/',$locale))
   return self::reason('invalid_locale');
  if($tax==='destinations') {
   if(function_exists('service_channel_allowed') &&
       !service_channel_allowed('tours-and-activities','destination_discovery'))
    return self::reason('destination_service_not_active',['target_term_id'=>$id]);
   $posts=self::termPostCount($id,$locale,$tax);
   if($posts<1)return self::reason('no_published_localized_tour',['target_term_id'=>$id,'posts'=>$posts]);
  }
  $old=self::nativeMeta($id,'is_public');
  if($old!=='0')return self::reason('nonprivate_candidate_needs_review',['target_term_id'=>$id]);
  // Independent editorial/fact QA is mandatory: length and HTTP tests
  // alone do not verify truth or translation quality.
  $qa=json_decode(self::nativeMeta($id,'_mad4b_wpml_quality_receipt_v1'),true);
  $sourceProof=hash('sha256',$original['name'].'|'.$original['description'].'|'.$source);
  $translationProof=hash('sha256',$translated['name'].'|'.$translated['description'].'|'.$translated['slug']);
  if(!is_array($qa) ||
      ($qa['contract']??'')!=='mad4b.wpml-source-backed-language-qa.v1' ||
      ($qa['source_sha256']??'')!==$sourceProof ||
      ($qa['translation_sha256']??'')!==$translationProof ||
      ($qa['language']??'')!==$locale ||
      empty($qa['fact_check_pass']) || empty($qa['language_review_pass']) ||
      empty($qa['brand_editorial_pass']) ||
      (int)($qa['quality_score']??0)<90)
    return self::reason('verified_editorial_fact_qa_receipt_missing',['target_term_id'=>$id]);
  return ['eligible'=>true,'reason'=>'canary_ready',
   'target_term_id'=>$id,'locale'=>$locale,'taxonomy'=>$tax,
   'source_term_id'=>$source,'previous_visibility'=>$old,
   'source_hash'=>hash('sha256',$original['name'].'|'.$original['description'].'|'.$source),
   'translation_hash'=>hash('sha256',$translated['name'].'|'.$translated['description'].'|'.$translated['slug']),
   'localized_posts'=>isset($posts)?$posts:null];
 }
 private static function baseEvidence(string $type,string $name,string $locale):array {
  if(!class_exists('DynamicFilterSEOBridge\\IndexFlow\\LocalizedRewriteBase'))
   return self::reason('dfsb_base_provider_missing');
  $record=\DynamicFilterSEOBridge\IndexFlow\LocalizedRewriteBase::inspect($type,$name,$locale);
  if(empty($record['ready']) || empty($record['translation_verified']))
   return self::reason((string)($record['reason']??'localized_base_not_verified'));
  // Translation is real, but SEO publication needs an independently working
  // canonical URL, not merely a completed WPML String Translation row.
  return self::reason('awaiting_native_permalink_http_qa',
   ['source_base'=>$record['source_base'],'localized_base'=>$record['localized_base']]);
 }
 public static function inspectIssue(array $issue):array {
  $code=(string)($issue['code']??'');
  if(!preg_match('/^dfsb_wpml_(term_missing|route_slug|base_slug_unverified|cpt_base_unverified)_([a-z0-9_-]+)_([0-9]+)_([a-z]{2})$/D',$code,$m))
   return self::reason('invalid_wpml_issue_code');
  if(in_array($m[1],['term_missing','route_slug'],true))
   return self::termEvidence($m[2],(int)$m[3],$m[4]);
  return self::baseEvidence($m[1]==='cpt_base_unverified'?'post_type':'taxonomy',$m[2],$m[4]);
 }
 private static function candidates():array {
  global $wpdb;
  $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
  $table=MAD4B_SCP_Schema::tables()['content_jobs'];
  $jobs=(array)$wpdb->get_results($wpdb->prepare(
   "SELECT job_id,subject,state,job_revision,language FROM `{$table}`
    WHERE site_uuid=%s AND subject LIKE %s LIMIT 220",
   self::SITE,'%wpml-url-issue-ref:%'),ARRAY_A);
  $linked=[];
  foreach($jobs as $job) {
   if(!preg_match('/wpml-url-issue-ref:([a-f0-9]{64})/D',(string)$job['subject'],$m))continue;
   $linked[$m[1]]=$job;
  }
  $rows=[];
  foreach($ledger as $id=>$issue) {
   if(!is_array($issue) || !str_starts_with((string)($issue['code']??''),'dfsb_wpml_')
      || ($issue['status']??'')!=='open' || empty($issue['content_job_id']) ||
      !isset($linked[$id]) || $linked[$id]['job_id']!==$issue['content_job_id'])continue;
   $rows[$id]=['id'=>$id,'issue'=>$issue,'job'=>$linked[$id],
     'priority'=>(int)($issue['content_job_request']['priority']??0)];
  }
  uasort($rows,static fn($a,$b)=>$b['priority']<=>$a['priority'] ?: strcmp($a['id'],$b['id']));
  return $rows;
 }
 public static function plan():array {
  $rows=self::candidates();$stats=[];$ready=[];$samples=[];
  foreach($rows as $row) {
   $result=self::inspectIssue($row['issue']);
   $reason=$result['reason'];$stats[$reason]=($stats[$reason]??0)+1;
   if($result['eligible'])$ready[]=['issue_id'=>$row['id'],'job_id'=>$row['job']['job_id'],
     'priority'=>$row['priority'],'evidence'=>$result];
   elseif(count($samples)<15)$samples[]=['code'=>$row['issue']['code'],'reason'=>$reason];
  }
  return ['contract'=>self::CONTRACT.'.plan','status'=>'COMPLETE',
    'site_uuid'=>self::SITE,'issues_scanned'=>count($rows),
    'ready_count'=>count($ready),'ready'=>array_slice($ready,0,10),
    'blocked_reasons'=>$stats,'blocked_samples'=>$samples,
    'auto_publication_enabled'=>!empty(((array)get_option(self::POLICY,[]))['enabled']),
    'max_releases_per_run'=>self::MAX_RELEASES,
    'plan_sha256'=>hash('sha256',wp_json_encode([$ready,$stats,self::SITE],JSON_UNESCAPED_SLASHES)),
    'publication_performed'=>false];
 }
 private static function setNativeMeta(int $term,string $key,string $value):bool {
  global $wpdb;
  $count=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id=%d AND meta_key=%s",$term,$key));
  if($count>1)return false;
  if($count===1)$ok=$wpdb->update($wpdb->termmeta,['meta_value'=>$value],
     ['term_id'=>$term,'meta_key'=>$key],['%s'],['%d','%s']);
  else $ok=$wpdb->insert($wpdb->termmeta,
     ['term_id'=>$term,'meta_key'=>$key,'meta_value'=>$value],['%d','%s','%s']);
  wp_cache_delete($term,'term_meta');
  return $ok!==false && self::nativeMeta($term,$key)===$value;
 }
 private static function sameOrigin(string $url):bool {
  return wp_parse_url($url,PHP_URL_HOST)===wp_parse_url(home_url(),PHP_URL_HOST) &&
    wp_parse_url($url,PHP_URL_SCHEME)==='https';
 }
 private static function termUrl(int $id,string $tax,string $locale):string {
  global $sitepress;
  if(!$sitepress)return '';
  $prior=(string)apply_filters('wpml_current_language',null);
  $sitepress->switch_lang($locale,true);
  try{$url=get_term_link($id,$tax);}
  finally{$sitepress->switch_lang($prior,true);}
  return is_string($url)?$url:'';
 }
 private static function canaryUrl(string $url):array {
  if(!self::sameOrigin($url))return self::reason('external_canonical_url');
  $resp=wp_remote_get($url,['timeout'=>9,'redirection'=>0,'sslverify'=>true,
    'headers'=>['Cache-Control'=>'no-cache','User-Agent'=>'MAD4B-WPML-QA/1'],
    'limit_response_size'=>220000]);
  if(is_wp_error($resp))return self::reason('frontend_request_failed');
  $status=(int)wp_remote_retrieve_response_code($resp);
  if($status!==200)return self::reason('frontend_non_200',['http_code'=>$status]);
  $robots=(string)wp_remote_retrieve_header($resp,'x-robots-tag');
  // On Staging, noindex is a mandatory condition for canary.
  if(stripos($robots,'noindex')===false)
   return self::reason('staging_noindex_missing');
  $html=wp_remote_retrieve_body($resp);
  if(!preg_match('~<link[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)~i',$html,$m) &&
     !preg_match('~<link[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']~i',$html,$m))
   return self::reason('canonical_not_exposed');
  if(untrailingslashit(html_entity_decode($m[1]))!==untrailingslashit($url))
   return self::reason('canonical_url_mismatch',['canonical'=>$m[1]]);
  return ['eligible'=>true,'reason'=>'http_200_canonical_noindex_pass','http_code'=>200];
 }
 private static function centralTermAcceptance(array $e,array $issue):array {
  if(!class_exists('MAD4B_SCP_Central_Write_Acceptance',false))
   return self::reason('central_acceptance_unavailable');
  $c='MAD4B_SCP_Central_Write_Acceptance';
  $id=(int)$e['target_term_id'];
  $qa=json_decode(self::nativeMeta($id,'_mad4b_wpml_quality_receipt_v1'),true);
  if(!is_array($qa))return self::reason('central_translation_qa_missing');
  $scope=$c::site_scope();
  $verified=$qa['verified_at']??null;
  $verified=is_int($verified)?$verified:(strtotime((string)$verified)?:0);
  $sourceHash=(string)$e['source_hash'];
  $revision=(string)$e['translation_hash'];
  $reference='term:'.$id.'/locale:'.(string)$e['locale'];
  $proofHash=hash('sha256',wp_json_encode($qa,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  $quality=($qa['fact_check_pass']??false)===true &&
    ($qa['language_review_pass']??false)===true &&
    ($qa['brand_editorial_pass']??false)===true &&
    (int)($qa['quality_score']??0)>=90;
  $native=[
   'site_uuid'=>$scope['site_uuid']??'',
   'environment'=>$scope['environment']??'',
   'subject_id'=>'term:'.$id,'ability'=>'mad4b/wpml-auto-qualify-term',
   'job_id'=>$issue['job']['job_id']??'',
   'source_sha256'=>$sourceHash,'revision'=>$revision,
   'reference_id'=>$reference,'evidence_sha256'=>$proofHash,
   'evidence_verified_at'=>$verified,
   'independent_verification'=>($qa['independent_verification']??null)===true,
   'quality_pass'=>$quality,'rights_pass'=>($qa['rights_pass']??null)===true
  ];
  $claim=[
   'contract'=>$c::CONTRACT,'site_uuid'=>$native['site_uuid'],
   'environment'=>$native['environment'],'subject_id'=>$native['subject_id'],
   'ability'=>$native['ability'],'job_id'=>$native['job_id'],
   'source_sha256'=>$sourceHash,'revision'=>$revision,
   'reference_id'=>$reference,'evidence_sha256'=>$proofHash,
   'verified_at'=>$verified,
   'independent_verification'=>$native['independent_verification'],
   'quality_pass'=>$quality,'rights_pass'=>$native['rights_pass']
  ];
  $claim['idempotency_key']=$c::intent_key($native,$scope,$proofHash);
  $result=$c::verify($claim,$native,$scope);
  return !empty($result['eligible'])?$result:self::reason('central_'.$result['reason']);
 }
 private static function publishTerm(array $e):array {
  $id=(int)$e['target_term_id'];$source=(int)$e['source_term_id'];$tax=$e['taxonomy'];
  $oldSource=self::nativeMeta($source,'is_public');
  if(!self::setNativeMeta($id,'is_public','1'))
    return self::reason('native_meta_visibility_write_failed');
  $url=self::termUrl($id,$tax,$e['locale']);
  $check=$url?self::canaryUrl($url):self::reason('native_wpml_url_unavailable');
  if(empty($check['eligible'])) {
   if(!self::setNativeMeta($id,'is_public','0'))
    throw new RuntimeException('rollback_failed_term_'.$id);
   return array_merge($check,['canary_rolled_back'=>true,'url'=>$url]);
  }
  // Clear review hold only after all validation, and preserve source identity.
  if(!self::setNativeMeta($id,'_mad4b_wpml_review_required','0') ||
     self::nativeMeta($source,'is_public')!==$oldSource) {
   self::setNativeMeta($id,'is_public','0');
   self::setNativeMeta($id,'_mad4b_wpml_review_required','1');
   throw new RuntimeException('review_release_readback_failed_'.$id);
  }
  self::setNativeMeta($id,'_mad4b_wpml_auto_publish_receipt',
    hash('sha256',self::CONTRACT.'|'.$id.'|'.$e['translation_hash'].'|'.$url));
  return ['eligible'=>true,'reason'=>'published_after_canary',
   'target_term_id'=>$id,'url'=>$url,'http_code'=>200,'indexable'=>false];
 }
 public static function run():array {
  $scope=self::scope(true);if(is_wp_error($scope))return ['error'=>$scope->get_error_code()];
  $policy=(array)get_option(self::POLICY,[]);
  if(empty($policy['enabled']) || ($policy['site_uuid']??'')!==self::SITE)
    return ['error'=>'site_auto_publication_disabled'];
  global $wpdb;
  $lock=self::LOCK.get_current_blog_id();
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
    return ['error'=>'readiness_ledger_busy'];
  try {
   $plan=self::plan();$published=[];$held=[];$checked=0;
   foreach($plan['ready'] as $row) {
    if($checked>=self::MAX_RELEASES)break;
    $issue=self::candidates()[$row['issue_id']]??null;
    if(!$issue || $issue['job']['state']!=='QUEUED')continue;
    $e=self::inspectIssue($issue['issue']);
    if(empty($e['eligible']) || $e['translation_hash']!==$row['evidence']['translation_hash'])
     continue;
    $central=self::centralTermAcceptance($e,$issue);
    if(empty($central['eligible'])) {
     $held[]=['issue_id'=>$row['issue_id'],'job_id'=>$row['job_id'],
       'reason'=>$central['reason'],'mutation_performed'=>false];
     continue;
    }
    $checked++;
    $result=self::publishTerm($e);
    $receipt=['issue_id'=>$row['issue_id'],'job_id'=>$issue['job']['job_id'],'at'=>gmdate('c')]+$result;
    if(!empty($result['eligible'])) {
     // The published page is not a completed ContentJob until the native
     // revision-guarded lifecycle records its success as durable audit.
     $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$row['job_id']]);
     $step=is_wp_error($job)?$job:MAD4B_SCP_Content_Jobs::transition_job([
      'job_id'=>$row['job_id'],
      'expected_revision'=>(int)($job['job']['job_revision']??0),
      'state'=>'RUNNING','stage'=>'FINAL_QA',
      'reason'=>'Verified localized WPML term, source identity, published tours and HTTP 200 canonical noindex canary.'
     ]);
     if(!is_wp_error($step)) {
      $done=MAD4B_SCP_Content_Jobs::transition_job([
       'job_id'=>$row['job_id'],
       'expected_revision'=>(int)($step['job']['job_revision']??0),
       'state'=>'COMPLETED','stage'=>'POST_PUBLISH',
       'reason'=>'Native WPML translated term released after public route canary and exact site-level audit.'
      ]);
     } else $done=$step;
     if(is_wp_error($done)) {
      self::setNativeMeta((int)$e['target_term_id'],'is_public','0');
      self::setNativeMeta((int)$e['target_term_id'],'_mad4b_wpml_review_required','1');
      $held[]=$receipt+['reason'=>'native_contentjob_transition_failed',
          'error'=>$done->get_error_code(),'rolled_back'=>true];
     } else {
      $receipt['job_state']='COMPLETED';
      $receipt['job_revision']=(int)$done['job']['job_revision'];
      $published[]=$receipt;
     }
    } else $held[]=$receipt;
   }
   $report=['contract'=>self::CONTRACT.'.run','at'=>gmdate('c'),
    'plan'=>$plan,'published'=>$published,'canary_held'=>$held,
    'published_count'=>count($published),'held_count'=>count($held)];
   update_option(self::REPORT,$report,false);
   return $report;
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 public static function cli(array $args,array $assoc):void {
  $verb=(string)($args[0]??'');
  $scope=self::scope($verb!=='plan'&&$verb!=='status');
  if(is_wp_error($scope))WP_CLI::error($scope->get_error_code());
  if($verb==='plan')$result=self::plan();
  elseif($verb==='status')$result=(array)get_option(self::REPORT,[]);
  elseif($verb==='run')$result=self::run();
  elseif($verb==='enable') {
   update_option(self::POLICY,['enabled'=>true,'site_uuid'=>self::SITE,
    'environment'=>'staging','max_releases'=>self::MAX_RELEASES,
    'approved_at'=>gmdate('c'),'approved_by'=>get_current_user_id()],false);
   $result=['enabled'=>true,'site_uuid'=>self::SITE,'production_enabled'=>false];
  }else WP_CLI::error('Usage: wp mad4b wpml-auto-qualify plan|run|status|enable');
  WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  if(isset($result['error']))WP_CLI::error($result['error']);
 }
 public static function menu():void {
  if(!class_exists('MAD4B_SCP_Admin_UI'))return;
  add_submenu_page(MAD4B_SCP_Admin_UI::PAGE_SLUG,
   'WPML Auto Qualification','WPML Auto Qualification','manage_options',
   'mad4b-wpml-auto-qualification',[__CLASS__,'admin']);
 }
 public static function admin():void {
  if(!current_user_can('manage_options'))return;
  $policy=(array)get_option(self::POLICY,[]);$report=(array)get_option(self::REPORT,[]);
  echo '<div class="wrap"><h1>MAD4B — WPML Auto Qualification</h1><p>WordPress/WPML is the source of truth. No machine-generated copy is approved without translation provenance, real localized products, service eligibility and HTTP/canonical tests.</p>';
  echo '<p><strong>Staging auto-publication: '.(!empty($policy['enabled'])?'Enabled':'Disabled').'</strong>. Production excluded. Staging URLs must remain noindex.</p>';
  echo '<h2>Last native qualification scan</h2><pre style="max-width:1100px;overflow:auto;white-space:pre-wrap">'.esc_html(wp_json_encode([
   'last_run'=>$report['at']??null,'published_count'=>$report['published_count']??0,
   'held_count'=>$report['held_count']??0,
   'blocked_reasons'=>$report['plan']['blocked_reasons']??[],
   'last_publications'=>$report['published']??[],
  ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre>';
  echo '<p>Eligible only after verified WPML mapping, true translated content, valid public parents, live localized tours, unique URL, and HTTP 200 canonical. Failures roll back the visibility change.</p></div>';
 }
}
MAD4B_WPML_Auto_Qualification_V1::boot();
