<?php
defined('ABSPATH')||exit;
/**
 * Source-bound Firecrawl research connector, not a general URL fetch endpoint.
 * The immutable ContentJob, original candidate post, source URL and executor task
 * all have to agree; publication and supplier approval remain independent.
 */
final class MAD4B_Firecrawl_Research_Bridge_V1 {
 const CONTRACT='mad4b.firecrawl.research-bridge.v1';
 const ABILITY='mad4b/firecrawl-research-capture';
 const ID='dynamic.firecrawl.research-v1';
 const RECEIPT_META='_mad4b_firecrawl_research_receipts_v1';
 private static $native_signed_admission=null;
 public static function with_signed_native_admission(array $input,$callback){
  if(self::$native_signed_admission!==null || !is_callable($callback))
   return new WP_Error('firecrawl_research_native_admission_invalid');
  self::$native_signed_admission=[
   'job_id'=>(string)($input['job_id']??''),
   'draft_post_id'=>(int)($input['draft_post_id']??0),
   'source_url'=>(string)($input['source_url']??'')];
  try{return call_user_func($callback);}
  finally{self::$native_signed_admission=null;}
 }
 public static function boot(){
  add_action('wp_abilities_api_init',[__CLASS__,'ability'],76);
  add_action('add_meta_boxes_transportations',[__CLASS__,'add_review_box']);
  add_filter('mad4b_hub_vps_capability_contracts',[__CLASS__,'contracts'],49);
  add_filter('mad4b_provider_capability_manifests',[__CLASS__,'manifest'],25);
 }
 /**
  * Read-only source evidence on the native JetEngine transportation editor.
  * No approval, publication, booking, or supplier permission is issued here.
  */
 public static function add_review_box($post){
  if(!is_object($post) || $post->post_type!=='transportations' ||
     !current_user_can('edit_post',$post->ID) ||
     wp_get_environment_type()!=='staging' ||
     wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return;
  add_meta_box('mad4b-transport-source-review',
   __('MAD4B — Source Research Quality','mad4b-platform'),
   [__CLASS__,'render_quality_box'],'transportations','side','high');
 }
 public static function render_quality_box($post){
  if(!is_object($post)||$post->post_type!=='transportations'||
     !current_user_can('edit_post',$post->ID))return;
  $quality=get_post_meta($post->ID,'_mad4b_source_capture_quality_v1',true);
  $source=(string)get_post_meta($post->ID,'_mad4b_source_research_url',true);
  if(!is_array($quality)||!$quality){
   echo '<p>'.esc_html__('No reviewed source capture is recorded for this draft.','mad4b-platform').'</p>';
   return;
  }
  $state=(string)($quality['state']??'UNKNOWN');
  echo '<p><strong>'.esc_html__('Capture status:','mad4b-platform').'</strong> '.esc_html($state).'</p>';
  if($source!=='')
   echo '<p><strong>'.esc_html__('Bound source:','mad4b-platform').'</strong> <a href="'.esc_url($source).'" target="_blank" rel="noopener noreferrer">'.esc_html(wp_parse_url($source,PHP_URL_HOST)).'</a></p>';
  if(!empty($quality['artifact_id']))
   echo '<p><strong>'.esc_html__('Artifact ID:','mad4b-platform').'</strong> <code>'.esc_html((string)$quality['artifact_id']).'</code></p>';
  if(!empty($quality['blocking_reason']))
   echo '<p><strong>'.esc_html__('Access issue:','mad4b-platform').'</strong> '.esc_html((string)$quality['blocking_reason']).'</p>';
  // Alternative public references never replace the source bound to the
  // original ContentJob and must not be interpreted as scraped evidence.
  $references=$quality['reference_candidates']??[];
  if(is_array($references) && $references){
   echo '<p><strong>'.esc_html__('Other official references — review only:','mad4b-platform').'</strong></p><ul>';
   foreach(array_slice($references,0,5) as $candidate){
    if(!is_array($candidate))continue;
    $u=(string)($candidate['url']??'');
    $host=strtolower((string)wp_parse_url($u,PHP_URL_HOST));
    if(wp_parse_url($u,PHP_URL_SCHEME)!=='https' ||
       !in_array($host,['cairo.gov.eg','www.cairo.gov.eg'],true))continue;
    $label=trim((string)($candidate['label']??''));
    echo '<li><a href="'.esc_url($u).'" target="_blank" rel="noopener noreferrer">'.
       esc_html($label!==''?$label:$host).'</a></li>';
   }
   echo '</ul><p><em>'.esc_html__('Not captured by Firecrawl; no schedule or ticket-price approval.','mad4b-platform').'</em></p>';
  }
  $flags=$quality['quality_flags']??[];
  if(is_array($flags) && $flags){
   echo '<p><strong>'.esc_html__('Review flags:','mad4b-platform').'</strong></p><ul>';
   foreach(array_slice($flags,0,12) as $flag)
    if(is_scalar($flag))echo '<li>'.esc_html((string)$flag).'</li>';
   echo '</ul>';
  }
  echo '<p><em>'.esc_html__('Reference only. Supplier verification, fares and live availability remain separate checks.','mad4b-platform').'</em></p>';
 }
 public static function manifest($m){
  // Provider manifests may be inspected before the SCP provider plugin boots.
  // Discovery must be fail-closed rather than fatally abort a signed REST call.
  if(!class_exists('MAD4B_SCP_Firecrawl_Provider',false)){
   $m[]=['provider_id'=>'firecrawl',
    'stages'=>['SITE_DISCOVERY','SOURCE_DISCOVERY','SCRAPING'],
    'capabilities'=>['scrape_url','extract_markdown','source_bound_contentjob'],
    'configured'=>false,'connection_verified'=>false];
   return $m;
  }
  $probe=MAD4B_SCP_Firecrawl_Provider::probe();
  $test=MAD4B_SCP_Firecrawl_Provider::test_status();
  $m[]=['provider_id'=>'firecrawl','stages'=>['SITE_DISCOVERY','SOURCE_DISCOVERY','SCRAPING'],
   'capabilities'=>['scrape_url','extract_markdown','source_bound_contentjob'],
   'configured'=>($probe['ready']??false)===true,
   'connection_verified'=>($test['state']??'')==='reachable'];
  return $m;
 }
 public static function contracts($list){
  $list[]=['id'=>self::ID,'ability'=>self::ABILITY,
   'stages'=>['SITE_DISCOVERY','SOURCE_DISCOVERY','SCRAPING'],
   'executor'=>'vps_runner','transport'=>'wp_ability','read_only'=>false,
   'requires_job'=>true,'owner'=>'firecrawl','enabled'=>true,'priority'=>85,
   'preflight'=>[__CLASS__,'preflight'],'independent_verifier'=>[__CLASS__,'verify']];
  return $list;
 }
 public static function ability(){
  if(!function_exists('wp_register_ability')||wp_has_ability(self::ABILITY))return;
  wp_register_ability(self::ABILITY,[
   'label'=>'Firecrawl bounded ContentJob research capture',
   'description'=>'Scrape only source URL already bound to a central Staging transport candidate and original ContentJob; store immutable evidence, never publish.',
   'category'=>'mad4b-write','execute_callback'=>[__CLASS__,'execute'],
   'permission_callback'=>static fn()=>self::permitted(),
   'input_schema'=>['type'=>'object','properties'=>[
     'job_id'=>['type'=>'string'],'draft_post_id'=>['type'=>'integer'],'source_url'=>['type'=>'string']],
     'required'=>['job_id','draft_post_id','source_url'],'additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,
     'mcp'=>['public'=>false,'type'=>'tool','surface'=>'write'],
     'mad4b_hub_stages'=>['SITE_DISCOVERY','SOURCE_DISCOVERY','SCRAPING'],
     'annotations'=>['readonly'=>false,'destructive'=>false,'idempotent'=>false]]
  ]);
 }
 private static function permitted(){
  if(self::$native_signed_admission!==null)
   return wp_get_environment_type()==='staging'
    && wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com'
    && class_exists('MAD4B_SCP_Policy',false)
    && MAD4B_SCP_Policy::can_mutate();
  return defined('WP_CLI')&&WP_CLI && PHP_SAPI==='cli'
   && wp_get_environment_type()==='staging'
   && wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com'
   && current_user_can('manage_options')
   && class_exists('MAD4B_SCP_Policy')&&MAD4B_SCP_Policy::can_mutate()
   && class_exists('MAD4B_Hub_CLI_Fallback')
   && is_array(MAD4B_Hub_CLI_Fallback::preflight('queue-list'));
 }
 public static function validate($input){
  if(!self::permitted())return new WP_Error('firecrawl_research_admin_stage_required');
  $job=(string)($input['job_id']??'');$id=absint($input['draft_post_id']??0);
  $url=(string)($input['source_url']??'');
  if(!wp_is_uuid($job)||!$id||strlen($url)>1500 || !wp_http_validate_url($url)
     || wp_parse_url($url,PHP_URL_SCHEME)!=='https')
   return new WP_Error('firecrawl_research_input_invalid');
  $post=get_post($id);
  if(!$post || $post->post_type!=='transportations'||$post->post_status!=='draft'
    ||get_post_meta($id,'trans_available_status',true)!=='0')
   return new WP_Error('firecrawl_research_draft_required');
  if(self::$native_signed_admission!==null && (
    self::$native_signed_admission['job_id']!==$job ||
    self::$native_signed_admission['draft_post_id']!==$id ||
    self::$native_signed_admission['source_url']!==$url))
    return new WP_Error('firecrawl_research_signed_input_mismatch');
  $canonical=(string)get_post_meta($id,'_mad4b_source_research_url',true);
  if($url!==$canonical)return new WP_Error('firecrawl_research_source_drift');
  $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
  if(!$host||filter_var($host,FILTER_VALIDATE_IP)||
     str_ends_with($host,'.local') || str_ends_with($host,'.internal'))
   return new WP_Error('firecrawl_research_private_target_denied');
  $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job]);
  if(is_wp_error($j))return $j;
  $row=$j['job'];
  if(!in_array(($row['state']??''),['QUEUED','RUNNING'],true) ||
     !in_array(($row['stage']??''),['SITE_DISCOVERY','SOURCE_DISCOVERY','SCRAPING'],true)
     || !str_contains((string)($row['subject']??''),$url) ||
     ($row['target_post_type']??'')!=='transportations')
   return new WP_Error('firecrawl_research_job_source_mismatch');
  $probe=MAD4B_SCP_Firecrawl_Provider::probe();
  if(($probe['ready']??false)!==true)return new WP_Error('firecrawl_research_not_configured');
  return ['job_id'=>$job,'draft_post_id'=>$id,'source_url'=>$url,
    'job_stage'=>$row['stage']];
 }
 public static function preflight($task,$input){
  if(!is_array($task)||($task['operation']??'')!=='stage_work')return false;
  // A VPS provider contract is not an authorized original SCP capability.
  // Refuse queue routing until original Ability descriptor admits execution.
  if(!class_exists('MAD4B_SCP_Capability_Descriptor_Registry',false))return false;
  $descriptor=MAD4B_SCP_Capability_Descriptor_Registry::binding(self::ABILITY,'authorization');
  if(is_wp_error($descriptor)||($descriptor['execution_lane']??'none')==='none')return false;
  // A WP-CLI WordPress user is not a signed NHI executor. Do not claim and
  // fail a queued task merely because its underlying Ability is mounted.
  if(!class_exists('MAD4B_SCP_Identity_Context',false)
   ||!class_exists('MAD4B_SCP_Agent_Registry',false))return false;
  $identity=MAD4B_SCP_Identity_Context::current();
  if(!is_array($identity)||empty($identity['authenticated'])
   ||empty($identity['subject_fingerprint']))return false;
  $agent=MAD4B_SCP_Agent_Registry::resolve_agent($identity);
  if(is_wp_error($agent)||!is_array($agent))return false;
  $provider=class_exists('MAD4B_SCP_Servers',false)
   ? MAD4B_SCP_Servers::provider_for_ability('mad4b-write',self::ABILITY):null;
  if(!is_string($provider)||$provider==='')return false;
  $grant=MAD4B_SCP_Agent_Registry::exact_grant(
   (int)$agent['id'],'mad4b-write',self::ABILITY,$provider);
  if(is_wp_error($grant)||!is_array($grant)||($grant['effect']??'')!=='allow')return false;
  // Exact grant is necessary but not sufficient: native callback still runs
  // MAD4B authorization, resource guards, approvals and receipt validation.
  $validated=self::validate($input);
  return is_array($validated) &&
    ($task['content_job_id']??'')===$validated['job_id'] &&
    ($task['stage']??'')===$validated['job_stage'];
 }
 private static function prior($id,$job,$url){
  $all=get_post_meta($id,self::RECEIPT_META,true);
  if(!is_array($all))return null;
  $r=$all[hash('sha256',$job.'|'.$url)]??null;
  if(!is_array($r)||empty($r['artifact_id']))return null;
  $a=MAD4B_SCP_Artifacts::get_artifact(['artifact_id'=>$r['artifact_id']]);
  if(is_wp_error($a)||($a['artifact']['job_id']??'')!==$job||
     ($a['artifact']['payload']['source_url']??'')!==$url ||
     ($a['artifact']['payload']['source_sha256']??'')!==($r['source_sha256']??'') ||
     !self::content_substantive((string)($a['artifact']['payload']['markdown']??'')))
     return null;
  return $r;
 }
 /**
  * Reference-quality gate only; it never establishes commercial eligibility.
  * Language mismatches are advisories, while access challenges are rejected.
  */
 public static function content_review($markdown,$source_url=''){
  $raw=trim((string)$markdown);
  $flags=[];$reason='';
  $head=mb_substr($raw,0,1600,'UTF-8');
  if(strlen($raw)<200)$reason='INSUFFICIENT_LENGTH';
  elseif(preg_match('/(?:just a moment\.{0,3}|checking your browser|cloudflare ray id|verify you are human|attention required!.*cloudflare|access denied.*cloudflare)/iu',$head))
   $reason='ACCESS_CHALLENGE';
  elseif(preg_match('/^\s*(?:loading\.{0,3}|جار[يى] التحميل)/iu',$head) && strlen($raw)<600)
   $reason='DYNAMIC_LOADING_SHELL';
  // Count visible words, excluding image labels and URL characters.
  $visible=preg_replace('/!\[[^]]*\]\([^)]+\)/u',' ',$raw);
  $visible=preg_replace('/\[([^]]+)\]\([^)]+\)/u','$1',$visible);
  $visible=preg_replace('~https?://[^\s)]+~u',' ',$visible);
  $visible=preg_replace('/[#*_|]+/u',' ',$visible);
  $visible=trim((string)preg_replace('/\s+/u',' ',$visible));
  $visible_chars=mb_strlen($visible,'UTF-8');
  if($reason==='' && $visible_chars<120)$reason='INSUFFICIENT_VISIBLE_TEXT';

  $arabic=(int)preg_match_all('/\p{Arabic}/u',$visible);
  $latin=(int)preg_match_all('/[A-Za-z]/u',$visible);
  $ratio=($arabic+$latin)>0?round($arabic/($arabic+$latin),3):0.0;
  $language=$arabic>=60 && $ratio>=0.65?'ar':($latin>=100 && $ratio<=0.2?'en':'mixed_or_unknown');
  $expected='';
  $query=(string)wp_parse_url((string)$source_url,PHP_URL_QUERY);
  if($query!==''){parse_str($query,$params);$expected=strtolower((string)($params['lang']??''));}
  if($expected==='' && preg_match('~^/(en|ar|fr|de|es|it)(?:[-/]|$)~i',(string)wp_parse_url((string)$source_url,PHP_URL_PATH),$match))
   $expected=strtolower($match[1]);
  if($expected!=='' && $language!=='mixed_or_unknown' && $language!==$expected)
   $flags[]='SOURCE_LOCALE_MISMATCH';
  $flags[]='SUPPLIER_UNVERIFIED';
  $flags[]='FARES_AND_AVAILABILITY_UNVERIFIED';
  if($reason!=='')$flags[]=$reason;
  return ['substantive'=>$reason==='',
   'state'=>$reason===''?'REFERENCE_REVIEW_REQUIRED':'CAPTURE_REJECTED',
   'reason'=>$reason,'flags'=>$flags,
   'markdown_bytes'=>strlen($raw),'visible_chars'=>$visible_chars,
   'language_hint'=>$language,'source_language_hint'=>$expected,
   'arabic_character_ratio'=>$ratio,
   'supplier_verified'=>false,'fares_verified'=>false,'availability_verified'=>false];
 }
 public static function content_substantive($markdown){
  return self::content_review($markdown)['substantive'];
 }
 /**
  * Converge the existing per-post research quality record on the exact durable
  * Artifact. No JetEngine commercial fields or publication state are changed.
  */
 private static function sync_post_quality($id,$job,$url,$receipt){
  $artifact_id=(string)($receipt['artifact_id']??'');
  if(!wp_is_uuid($artifact_id))return false;
  if((string)get_post_meta($id,'_mad4b_source_research_url',true)!==$url)return false;
  $read=MAD4B_SCP_Artifacts::get_artifact(['artifact_id'=>$artifact_id]);
  if(is_wp_error($read))return false;
  $a=$read['artifact']??[];$p=$a['payload']??[];
  $markdown=(string)($p['markdown']??'');
  $sha=hash('sha256',$markdown);
  if(($a['job_id']??'')!==$job || ($p['source_url']??'')!==$url ||
     ($p['source_sha256']??'')!==$sha || ($receipt['source_sha256']??'')!==$sha)
    return false;
  $quality=self::content_review($markdown,$url);
  if(!$quality['substantive'])return false;
  $key='_mad4b_source_capture_quality_v1';
  $prev=get_post_meta($id,$key,true);
  if(is_array($prev) && ($prev['state']??'')===$quality['state'] &&
     ($prev['artifact_id']??'')===$artifact_id &&
     ($prev['source_sha256']??'')===$sha &&
     ($prev['quality_flags']??[])===$quality['flags'])return true;
  $next=['state'=>$quality['state'],'artifact_id'=>$artifact_id,
   'job_id'=>$job,'source_url'=>$url,'source_sha256'=>$sha,
   'markdown_bytes'=>strlen($markdown),'quality_flags'=>$quality['flags'],
   'language_hint'=>$quality['language_hint'],
   'source_language_hint'=>$quality['source_language_hint'],
   'arabic_character_ratio'=>$quality['arabic_character_ratio'],
   'review_only'=>true,'pricing_verified'=>false,'supplier_verified'=>false,
   'availability_verified'=>false,'publish_authorized'=>false,
   'native_313_receipt_verified'=>false,'checked_at_utc'=>gmdate('c')];
  if(is_array($prev) && !empty($prev['previous_evidence']))
   $next['previous_evidence']=$prev['previous_evidence'];
  elseif(is_array($prev) && !empty($prev['artifact_id']) &&
    $prev['artifact_id']!==$artifact_id)
   $next['previous_evidence']=[
     'state'=>$prev['state']??'','artifact_id'=>$prev['artifact_id'],
     'markdown_bytes'=>$prev['markdown_bytes']??null,
     'source_sha256'=>$prev['source_sha256']??'',
     'checked_at_utc'=>$prev['checked_at_utc']??''];
  $changed=update_post_meta($id,$key,$next,is_array($prev)?$prev:'');
  return (bool)$changed && get_post_meta($id,$key,true)===$next;
 }
 public static function execute($input){
  $v=self::validate($input);if(is_wp_error($v))return $v;
  $job=$v['job_id'];$id=$v['draft_post_id'];$url=$v['source_url'];
  $prior=self::prior($id,$job,$url);
  if($prior){
   if(!self::sync_post_quality($id,$job,$url,$prior))
    return new WP_Error('firecrawl_research_quality_persistence_failed');
   return $prior;
  }
  // Provider-owned credential routing. This method never returns raw keys.
  $captured=MAD4B_SCP_Firecrawl_Provider::scrape_research_url($job,$id,$url,true);
  if(is_wp_error($captured))return $captured;
  $markdown=(string)($captured['markdown']??'');
  if(trim($markdown)===''||strlen($markdown)>MAD4B_Hub_Capture_Parser::MARKDOWN_LIMIT)return new WP_Error('firecrawl_research_capture_invalid');
  $quality=self::content_review($markdown,$url);
  if(!$quality['substantive'])
   return new WP_Error('firecrawl_research_insufficient_js_shell',
    'Capture rejected by research quality gate.',
    ['reason'=>$quality['reason'],'markdown_bytes'=>$quality['markdown_bytes']]);
  $contentHash=hash('sha256',$markdown);
  $saved=MAD4B_SCP_Artifacts::append_artifact([
   'job_id'=>$job,'artifact_type'=>'scraped_page',
   'payload'=>['source_url'=>$url,'source_sha256'=>$contentHash,'markdown'=>$markdown,
     'captured_at'=>$captured['captured_at'],'provider'=>'firecrawl',
     'supplier_verified'=>false,'pricing_verified'=>false],
   'metadata'=>['draft_post_id'=>$id,'http_status'=>$captured['http_status'],
     'research_only'=>true,'publish_authorized'=>false,
     'quality_state'=>$quality['state'],'quality_flags'=>$quality['flags'],
     'language_hint'=>$quality['language_hint'],
     'source_language_hint'=>$quality['source_language_hint']],
   'producer_stage'=>$v['job_stage'],'producer_ref'=>'firecrawl:'.$id,
   'reason'=>'Source-bound transport candidate research capture; no supplier certification or publishing'
  ]);
  if(is_wp_error($saved))return $saved;
  $aid=(string)($saved['artifact']['artifact_id']??'');
  if(!wp_is_uuid($aid))return new WP_Error('firecrawl_research_artifact_receipt_missing');
  $receipt=['contract'=>self::CONTRACT,'state'=>'CAPTURED_FOR_REVIEW','job_id'=>$job,
    'draft_post_id'=>$id,'source_url'=>$url,'source_sha256'=>$contentHash,
    'artifact_id'=>$aid,'evidence_saved'=>true,'supplier_verified'=>false,
    'publish_authorized'=>false];
  $all=get_post_meta($id,self::RECEIPT_META,true);
  if(!is_array($all))$all=[];
  $all[hash('sha256',$job.'|'.$url)]=$receipt;
  if(!update_post_meta($id,self::RECEIPT_META,$all))
   return new WP_Error('firecrawl_research_receipt_persistence_failed');
  if(!self::sync_post_quality($id,$job,$url,$receipt))
   return new WP_Error('firecrawl_research_quality_persistence_failed');
  return $receipt;
 }
 public static function verify($task,$result){
  if(!is_array($task)||!is_array($result))return false;
  $input=$task['payload']['stage_input']??null;
  if(!is_array($input)){
   $payload=json_decode((string)($task['payload_json']??''),true);
   $input=$payload['stage_input']??null;
  }
  if(!is_array($input))return false;
  $v=self::validate($input);
  if(!is_array($v))return false;
  $receipt=self::prior($v['draft_post_id'],$v['job_id'],$v['source_url']);
  if(!is_array($receipt))return false;
  return ($result['job_id']??'')===$v['job_id'] &&
   ($result['completion_state']??'')==='complete' &&
   ($result['result_sha256']??'')===hash('sha256',wp_json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) &&
   ($receipt['state']??'')==='CAPTURED_FOR_REVIEW' &&
   ($receipt['publish_authorized']??null)===false;
 }
}
MAD4B_Firecrawl_Research_Bridge_V1::boot();
