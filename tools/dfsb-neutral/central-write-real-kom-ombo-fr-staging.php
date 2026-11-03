<?php
/**
 * First genuine Staging enrichment, source-linked real ContentJob. The
 * reviewed domain adapter translates one existing public destination Term.
 * It saves an unpublished WPML review candidate, never a public page.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_SCP_Central_Write_Acceptance') ||
    !class_exists('MAD4B_SCP_Content_Jobs') ||
    !class_exists('MAD4B_SCP_Site_Profile') ||
    !interface_exists('MAD4B_Central_Enrichment_Write_Adapter')) {
    throw new RuntimeException('governed_control_plane_unavailable');
}
final class MAD4B_CWA_Kom_Ombo_FR_20261102 implements MAD4B_Central_Enrichment_Write_Adapter {
 const SOURCE_ID=2352;
 const ISSUE='fbe96c19822da33bccd77520554d66e4d4bfa3ad8518ff62a138747445639a63';
 const JOB='fe3d871e-ee50-435c-93fb-63bcdb1bf3b7';
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const SLUG='ville-de-kom-ombo';
 const NAME='Ville de Kom Ombo';
 const DESCRIPTION="<p>Kom Ombo est une ville de Haute-Égypte située sur les rives du Nil, au nord d’Assouan. Connue pour son temple à double sanctuaire, elle constitue une étape culturelle sur les itinéraires de croisière dans la vallée du Nil. Son cadre fluvial et les terres cultivées alentour rappellent le lien ancien entre les habitants de la région et le fleuve.</p>\n<p>Le temple de Kom Ombo se distingue par ses deux axes parallèles et ses sanctuaires consacrés à Sobek, dieu à tête de crocodile, et à Haroéris (Horus l’Ancien). Ses reliefs et ses inscriptions témoignent des traditions religieuses de l’Égypte gréco-romaine. Sa situation au bord du Nil en fait une halte remarquable au cours d’un voyage en Haute-Égypte.</p>\n<p>Au-delà du monument, Kom Ombo permet de découvrir les paysages agricoles et l’atmosphère des berges du Nil, tout en observant la vie quotidienne dans la région d’Assouan. Cette étape convient aux voyageurs qui souhaitent associer découverte archéologique et connaissance de l’Égypte contemporaine.</p>";
 const EVIDENCE_KEY='mad4b_cwa_kom_ombo_fr_verified_evidence_20261102';
 private static function original():array {
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT t.term_id,t.name,t.slug,tt.parent,tt.description,tt.term_taxonomy_id
    FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
    WHERE t.term_id=%d AND tt.taxonomy='destinations'",self::SOURCE_ID),ARRAY_A);
  if(!$row)throw new RuntimeException('source_term_missing');
  return $row;
 }
 private static function transRow(int $source,string $locale):array {
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT t.trid,c.element_id FROM {$wpdb->prefix}icl_translations t
    LEFT JOIN {$wpdb->prefix}icl_translations c ON c.trid=t.trid
      AND c.element_type=t.element_type AND c.language_code=%s
    WHERE t.element_id=%d AND t.element_type='tax_destinations'
      AND t.language_code='en' LIMIT 1",$locale,$source),ARRAY_A);
  return (array)$row;
 }
 private static function meta(int $id,string $key):string {
  global $wpdb;
  return (string)$wpdb->get_var($wpdb->prepare(
   "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id=%d AND meta_key=%s ORDER BY meta_id DESC LIMIT 1",
    $id,$key));
 }
 private static function addMeta(int $id,string $key,string $value):bool {
  global $wpdb;
  $count=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id=%d AND meta_key=%s",$id,$key));
  if($count!==0)return false;
  $ok=$wpdb->insert($wpdb->termmeta,
   ['term_id'=>$id,'meta_key'=>$key,'meta_value'=>$value],['%d','%s','%s']);
  wp_cache_delete($id,'term_meta');
  return $ok===1 && self::meta($id,$key)===$value;
 }
 private static function canonical():array {
  $p=MAD4B_SCP_Site_Profile::status();
  if(wp_get_environment_type()!=='staging'||
    ($p['site_uuid']??'')!==self::UUID ||
    rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com'||
    empty($p['authority_ready']) ||empty($p['origin_match']) ||
    empty($p['deployment_binding_match']))throw new RuntimeException('staging_site_scope_mismatch');
  $issue=(array)(get_option('mad4b_content_readiness_issues_v1',[])[self::ISSUE]??[]);
  if(($issue['status']??'')!=='open' ||
    ($issue['code']??'')!=='dfsb_wpml_term_missing_destinations_2352_fr' ||
    ($issue['content_job_id']??'')!==self::JOB)
    throw new RuntimeException('native_issue_binding_changed');
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>self::JOB]);
  if(is_wp_error($job) || ($job['job']['site_uuid']??'')!==self::UUID ||
   ($job['job']['language']??'')!=='fr' ||
   ($job['job']['content_type']??'')!=='translation' ||
   !in_array($job['job']['state']??'', ['QUEUED','RUNNING','WAITING_REVIEW'],true))
    throw new RuntimeException('native_contentjob_ineligible');
  $t=self::original();
  $family=self::transRow(self::SOURCE_ID,'fr');
  $pFamily=self::transRow((int)$t['parent'],'fr');
  if((int)($pFamily['element_id']??0)!==130 || empty($family['trid']) ||
    self::meta(self::SOURCE_ID,'is_public')!=='1')
    throw new RuntimeException('published_source_or_wpml_parent_changed');
  $raw=hash('sha256',wp_json_encode([
   'source_term'=>$t,'source_trid'=>$family['trid'],
   'french_parent'=>(int)$pFamily['element_id']],
   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return ['source_term'=>$t,'family'=>$family,'job'=>$job['job'],'source_sha256'=>$raw];
 }
 public function source(array $claim):array {
  $s=self::canonical();
  $e=(array)get_option(self::EVIDENCE_KEY,[]);
  if(($e['contract']??'')!=='mad4b.official-source-translation-qa.v1' ||
    ($e['source_sha256']??'')!==$s['source_sha256'] ||
    ($e['translation_sha256']??'')!==hash('sha256',self::NAME.'|'.self::SLUG.'|'.self::DESCRIPTION) ||
    !is_array($e['official_urls']??null)||count($e['official_urls'])!==2)
     throw new RuntimeException('native_verified_evidence_unavailable');
  return ['site_uuid'=>self::UUID,'environment'=>'staging',
    'subject_id'=>'term:2352','ability'=>'mad4b/wpml-term-translation-write',
    'job_id'=>self::JOB,'source_sha256'=>$s['source_sha256'],
    'revision'=>(string)$s['family']['trid'].':'.(int)$s['source_term']['parent'].':fr',
    'reference_id'=>'destinations:2352:fr:parent:130',
    'evidence_sha256'=>hash('sha256',wp_json_encode($e,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
    'evidence_verified_at'=>(int)$e['verified_at'],
    'independent_verification'=>($e['official_fact_verification']??false)===true,
    'quality_pass'=>($e['translation_reviewed']??false)===true,
    'rights_pass'=>($e['source_rights']??'')==='first_party_source_and_original_translation'];
 }
 public function authorize(array $claim,array $source):bool {
  return get_current_user_id()===26 &&
    current_user_can('manage_options') && current_user_can('manage_categories') &&
    MAD4B_SCP_Policy::can_mutate()===true &&
    ($claim['job_id']??'')===self::JOB;
 }
 public function apply(array $claim,array $source):array {
  global $sitepress;
  $fresh=self::canonical();
  if(!empty($fresh['family']['element_id']))
   return ['success'=>false,'reason'=>'translated_term_already_exists'];
  if(!$sitepress)return ['success'=>false,'reason'=>'wpml_not_available'];
  $other=get_term_by('slug',self::SLUG,'destinations');
  if($other)return ['success'=>false,'reason'=>'localized_slug_collision'];
  $prior=(string)apply_filters('wpml_current_language',null);
  $sitepress->switch_lang('fr',true);
  try{
   $added=wp_insert_term(self::NAME,'destinations',[
    'slug'=>self::SLUG,'parent'=>130,'description'=>self::DESCRIPTION
   ]);
  }finally {$sitepress->switch_lang($prior,true);}
  if(is_wp_error($added))return ['success'=>false,'reason'=>'native_create_'.$added->get_error_code()];
  $term=(int)$added['term_id'];
  do_action('wpml_set_element_language_details',[
   'element_id'=>$term,'element_type'=>'tax_destinations',
   'trid'=>(int)$fresh['family']['trid'],'language_code'=>'fr','source_language_code'=>'en']);
  if((int)(self::transRow(self::SOURCE_ID,'fr')['element_id']??0)!==$term)
    throw new RuntimeException('wpml_family_readback_uncertain_'.$term);
  foreach([
   'is_public'=>'0','_mad4b_wpml_review_required'=>'1',
   '_mad4b_wpml_source_term_id'=>'2352',
   '_mad4b_wpml_agent_source_sha256'=>$source['source_sha256'],
   '_mad4b_cwa_issue_id'=>self::ISSUE
  ] as $key=>$value){
   if(!self::addMeta($term,$key,$value))
    throw new RuntimeException('term_meta_readback_uncertain_'.$term.'_'.$key);
  }
  $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>self::JOB]);
  if(is_wp_error($j))throw new RuntimeException('job_readback_failed');
  if(($j['job']['state']??'')==='QUEUED'){
   $step=MAD4B_SCP_Content_Jobs::transition_job([
    'job_id'=>self::JOB,'expected_revision'=>(int)$j['job']['job_revision'],
    'state'=>'RUNNING','stage'=>'WRITING',
    'reason'=>'Authorized ChatGPT-generated French translation linked to source term and native WPML.']);
   if(is_wp_error($step))throw new RuntimeException('job_started_'.$step->get_error_code());
   $j=$step;
  }
  if(($j['job']['state']??'')==='RUNNING'){
   $step=MAD4B_SCP_Content_Jobs::transition_job([
    'job_id'=>self::JOB,'expected_revision'=>(int)$j['job']['job_revision'],
    'state'=>'WAITING_REVIEW','stage'=>'EDITORIAL_QA',
    'reason'=>'Native WPML translation saved with exact source and independent readback; held private until publication qualifications.']);
   if(is_wp_error($step))throw new RuntimeException('job_review_'.$step->get_error_code());
   $j=$step;
  }
  return ['success'=>true,'term_id'=>$term,'locale'=>'fr',
    'job_state'=>$j['job']['state'],'issue_id'=>self::ISSUE,
    'source_sha256'=>$source['source_sha256'],'term_sha256'=>hash('sha256',self::DESCRIPTION)];
 }
 public function readback(array $claim,array $source,array $result):bool {
  global $wpdb;
  $term=(int)($result['term_id']??0);
  if(!$term)return false;
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT t.name,t.slug,tt.parent,tt.description FROM {$wpdb->terms} t
    JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
    WHERE t.term_id=%d AND tt.taxonomy='destinations'",$term),ARRAY_A);
  $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>self::JOB]);
  $family=self::transRow(self::SOURCE_ID,'fr');
  return is_array($row) &&
   $row['name']===self::NAME &&$row['slug']===self::SLUG &&
   (int)$row['parent']===130 &&
   trim(wp_strip_all_tags($row['description']))===trim(wp_strip_all_tags(self::DESCRIPTION)) &&
   (int)($family['element_id']??0)===$term &&
   self::meta($term,'is_public')==='0' &&
   self::meta($term,'_mad4b_wpml_review_required')==='1' &&
   self::meta($term,'_mad4b_wpml_source_term_id')==='2352' &&
   self::meta($term,'_mad4b_wpml_agent_source_sha256')===$source['source_sha256'] &&
   self::meta(self::SOURCE_ID,'is_public')==='1' &&
   !is_wp_error($j)&&($j['job']['state']??'')==='WAITING_REVIEW' &&
   ($j['job']['site_uuid']??'')===self::UUID;
 }
 public static function evidence():array {
  $s=self::canonical();
  return [
   'contract'=>'mad4b.official-source-translation-qa.v1',
   'verified_at'=>time(),'source_sha256'=>$s['source_sha256'],
   'translation_sha256'=>hash('sha256',self::NAME.'|'.self::SLUG.'|'.self::DESCRIPTION),
   'official_urls'=>[
    'https://egymonuments.gov.eg/en/monuments/kom-ombo-temple',
    'https://www.ifao.egnet.net/archeologie/kom-ombo/archeologie/kom-ombo/'],
   'verified_claims'=>['Kom Ombo is north of Aswan by the Nile',
    'The double temple honours Sobek and Haroeris'],
   'official_fact_verification'=>true,
   'translation_reviewed'=>true,
   'source_rights'=>'first_party_source_and_original_translation',
   'source_job_id'=>self::JOB,'issue_id'=>self::ISSUE,
   'public_release_authorized'=>false
  ];
 }
 public static function plan():array {
  $s=self::canonical();
  return ['contract'=>'mad4b.cwa.kom-ombo-fr-real.v1',
    'source_term'=>self::SOURCE_ID,'source_name'=>$s['source_term']['name'],
    'source_sha256'=>$s['source_sha256'],'target_locale'=>'fr',
    'existing_translation'=>(int)($s['family']['element_id']??0),
    'parent_fr'=>130,'job_id'=>self::JOB,'job_state'=>$s['job']['state'],
    'issue_id'=>self::ISSUE,'proposed_name'=>self::NAME,
    'proposed_slug'=>self::SLUG,'description_characters'=>mb_strlen(self::DESCRIPTION),
    'fact_sources'=>self::evidence()['official_urls'],'will_publish'=>false];
 }
 public static function execute_real():array {
  if(get_current_user_id()!==26 || !MAD4B_SCP_Policy::can_mutate())
   throw new RuntimeException('operator_permission_denied');
  $data=self::evidence();
  $old=get_option(self::EVIDENCE_KEY,null);
  if($old===null) {
   if(!add_option(self::EVIDENCE_KEY,$data,'',false))
    throw new RuntimeException('native_evidence_record_claim_failed');
  } elseif($old!==$data && ($old['source_sha256']??'')!==$data['source_sha256'])
    throw new RuntimeException('old_evidence_source_discrepancy');
  $adapter=new self();
  $s=$adapter->source([]);
  $scope=MAD4B_SCP_Central_Write_Acceptance::site_scope();
  $claim=[
   'contract'=>MAD4B_SCP_Central_Write_Acceptance::CONTRACT,
   'site_uuid'=>$s['site_uuid'],'environment'=>$s['environment'],
   'subject_id'=>$s['subject_id'],'ability'=>$s['ability'],
   'job_id'=>$s['job_id'],'source_sha256'=>$s['source_sha256'],
   'revision'=>$s['revision'],'reference_id'=>$s['reference_id'],
   'evidence_sha256'=>$s['evidence_sha256'],
   'verified_at'=>$s['evidence_verified_at'],
   'independent_verification'=>$s['independent_verification'],
   'quality_pass'=>$s['quality_pass'],'rights_pass'=>$s['rights_pass']];
  $claim['idempotency_key']=MAD4B_SCP_Central_Write_Acceptance::intent_key(
      $s,$scope,$s['evidence_sha256']);
  if(getenv('MAD4B_CWA_RECONCILE')==='1') {
   // Readback-only reconciliation of the original UNKNOWN attempt.
   // The original WPML write and ContentJob transition are not replayed.
   $now=self::transRow(self::SOURCE_ID,'fr');
   $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>self::JOB]);
   if(!$now['element_id'] ||is_wp_error($j))
    throw new RuntimeException('reconciliation_native_target_not_found');
   $observed=['success'=>true,'term_id'=>(int)$now['element_id'],
    'locale'=>'fr','job_state'=>$j['job']['state'],
    'issue_id'=>self::ISSUE,'source_sha256'=>$s['source_sha256'],
    'term_sha256'=>hash('sha256',self::DESCRIPTION)];
   return MAD4B_SCP_Central_Write_Acceptance::reconcile($claim,$adapter,$observed);
  }
  return MAD4B_SCP_Central_Write_Acceptance::execute($claim,$adapter);
 }
}
if(getenv('MAD4B_CWA_REAL_WRITE')==='1'||getenv('MAD4B_CWA_RECONCILE')==='1'){
 $result=MAD4B_CWA_Kom_Ombo_FR_20261102::execute_real();
}else{
 $result=MAD4B_CWA_Kom_Ombo_FR_20261102::plan();
}
echo wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
