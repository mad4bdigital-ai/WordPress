<?php
/**
 * Plugin Name: MAD4B Brand Asset Store — Multi-backend Staging
 * Description: Brand-scoped store choice and recovery orchestration; does not approve brand facts.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_Brand_Asset_Store_V1 {
 const CONTRACT='mad4b.brand-asset-store.v1';
 const OPT='mad4b_brand_asset_store_config_v1';
 const CPT='mad4b_brand_asset';
 const CHOICES=['managed_native','wordpress_media','google_drive'];
 const CATEGORIES=['brand_strategy','tone_of_voice','editorial_guidelines'];
 static function scope() {
  if (wp_get_environment_type()!=='staging' || strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST))!=='tours.dev.mad4b.com') return new WP_Error('wrong_site','Staging site only',['status'=>403]);
  if (!class_exists('MAD4B_SCP_Context_Authority')) return new WP_Error('native_context_unavailable','Native Context authority missing',['status'=>503]);
  $p=MAD4B_SCP_Context_Authority::profile();
  if (!is_array($p)||empty($p['brand_id'])||empty($p['site_uuid'])) return new WP_Error('brand_identity_missing','Site-bound native Brand Profile required',['status'=>409]);
  return ['brand_id'=>(string)$p['brand_id'],'site_uuid'=>(string)$p['site_uuid'],'brand_name'=>(string)($p['brand_name']??'')];
 }
 static function permission($r=null) {
  $s=self::scope();if(is_wp_error($s))return $s;
  return current_user_can('manage_options') ? true : new WP_Error('brand_store_forbidden','Company administrator required',['status'=>403]);
 }
 static function stored($identity) {
  $all=get_option(self::OPT,[]);
  if(!is_array($all))$all=[];
  $key=hash('sha256',$identity['site_uuid'].'|'.$identity['brand_id']);
  $rec=$all[$key]??null;
  if(!is_array($rec)||($rec['brand_id']??'')!==$identity['brand_id']||($rec['site_uuid']??'')!==$identity['site_uuid'])$rec=null;
  return [$all,$key,$rec];
 }
 static function drive_ready() {
  static $cached=null;
  if($cached!==null)return $cached;
  if(!function_exists('wp_get_ability'))return $cached=['ready'=>false,'state'=>'ability_unavailable'];
  $a=wp_get_ability('context/google-drive-status');
  if(!$a)return $cached=['ready'=>false,'state'=>'ability_unavailable'];
  $r=$a->execute([]);
  if(is_wp_error($r))return $cached=['ready'=>false,'state'=>$r->get_error_code()];
  $connection=$r['connection']??[];
  return $cached=['ready'=>!empty($connection['connected'])&&!empty($connection['read_available']),
   'read_available'=>!empty($connection['read_available']),
   'write_available'=>!empty($connection['write_available']),
   'state'=>(string)($connection['health_state']??'unknown'),
   'write'=>!empty($connection['write_available'])];
 }
 static function capabilities() {
  $media=function_exists('shared_mad4b_media_source_plan')&&class_exists('MAD4B_SCP_Context_Authority');
  return [
   'managed_native'=>['discovered'=>true,'ready'=>true,'write'=>true,'native_cpt'=>self::CPT,'confidential_drafts'=>true],
   'wordpress_media'=>['discovered'=>$media,'ready'=>$media,'write'=>$media,'native_adapter'=>'shared_mad4b_media_register','public_media_file_urls'=>true],
   'google_drive'=>array_merge(['discovered'=>class_exists('MAD4B_SCP_Context_Provider_Gateway'),'external_oauth_required'=>true,'write'=>false],self::drive_ready())
  ];
 }
 static function status($args=[]) {
  $id=self::scope();if(is_wp_error($id))return $id;
  [$all,$key,$stored]=self::stored($id);
  $selected=$stored['selected']??'managed_native';
  $providers=self::capabilities();
  $active= !empty($providers[$selected]['ready']) ? $selected : 'managed_native';
  $coverage=class_exists('MAD4B_SCP_Brand_Core_Coverage') ? null : null;
  $ready=false;$missing=self::CATEGORIES;
  if(function_exists('wp_get_ability')) {
   $a=wp_get_ability('context/brand-core-coverage');
   if($a){$r=$a->execute([]);if(is_array($r)){$ready=!empty($r['ready']);$missing=$r['missing_required_context_sets']??$missing;}}
  }
  return ['contract'=>self::CONTRACT,'identity'=>$id,'revision'=>(int)($stored['revision']??0),
   'selected_store'=>$selected,'effective_store'=>$active,'fallback_active'=>$selected!==$active,
   'store_state'=>$selected===$active?'ready':'pending_provider_connection',
   'configuration_saved'=>(bool)$stored,'external_root_id'=>$stored['external_root_id']??'',
   'google_drive_optional'=>true,'google_drive_blocks_recovery'=>false,
   'google_drive_connect_requires_owner_oauth'=>!empty($providers['google_drive']['external_oauth_required']) && empty($providers['google_drive']['ready']),
   'google_drive_settings_url'=>admin_url('admin.php?page=mad4b-control-plane-context&tab=google-drive'),
   'effective_write_store'=>($active==='google_drive' && empty($providers['google_drive']['write_available']))?'managed_native':$active,
   'providers'=>$providers,'brand_core_ready'=>$ready,'missing_brand_core'=>$missing,
   'owner_review_required'=>!$ready,'auto_resume_authorized'=>$ready,
   'native_authority_bridge_state'=>[
    'managed_native'=>'drafts_pending_native_review',
    'wordpress_media'=>'register_native_source_on_explicit_link',
    'google_drive'=>'native_provider_requires_folder_and_oauth'][$selected],
   'content_job_mutation'=>false,'foreign_assets_transfer_automatic'=>false,'production_mutation'=>false];
 }
 static function configure($input) {
  $permit=self::permission();if(is_wp_error($permit))return $permit;
  $id=self::scope();if(is_wp_error($id))return $id;
  $selected=sanitize_key((string)($input['store']??''));
  if(!in_array($selected,self::CHOICES,true))return new WP_Error('invalid_store','Unsupported store',['status'=>422]);
  $root=trim(sanitize_text_field((string)($input['external_root_id']??'')));
  if($selected==='google_drive'&&($root===''||$root==='root'||strlen($root)>128||!preg_match('/^[a-zA-Z0-9_-]+$/D',$root)))
   return new WP_Error('drive_folder_required','Exact Google Drive folder ID required, not My Drive root',['status'=>422]);
  [$all,$key,$old]=self::stored($id);
  $current=(int)($old['revision']??0);
  if(!isset($input['expected_revision'])||(int)$input['expected_revision']!==$current)
   return new WP_Error('store_revision_stale','Refresh store selection before updating',['status'=>409]);
  $caps=self::capabilities();
  if($selected==='wordpress_media'&&empty($caps[$selected]['ready']))
   return new WP_Error('media_adapter_unavailable','Native Media adapter unavailable',['status'=>503]);
  $rec=['contract'=>self::CONTRACT,'site_uuid'=>$id['site_uuid'],'brand_id'=>$id['brand_id'],
   'selected'=>$selected,'external_root_id'=>$selected==='google_drive'?$root:'',
   'revision'=>$current+1,'updated_utc'=>gmdate('c')];
  $all[$key]=$rec;
  if(!update_option(self::OPT,$all,false))return new WP_Error('store_write_failed','Store configuration not persisted',['status'=>500]);
  $out=self::status();
  if(is_wp_error($out)||($out['selected_store']??'')!==$selected||($out['revision']??0)!==$rec['revision'])
   return new WP_Error('store_readback_failed','Store configuration readback failed',['status'=>500]);
  return $out;
 }
 static function recovery_plan($args=[]) {
  $r=self::status();if(is_wp_error($r))return $r;
  $steps=['discover_wordpress_and_connected_sources','verify_brand_and_site_scope','provision_selected_store',
   'reconstruct_evidence_based_drafts','human_review_authoritative_brand_strategy',
   'synthesize_tone_and_editorial','verify_native_brand_core_coverage','resume_original_content_job'];
  return ['contract'=>'mad4b.brand-asset-store-recovery.v1','identity'=>$r['identity'],
   'selected_store'=>$r['selected_store'],'effective_store'=>$r['effective_store'],
   'brand_core_ready'=>$r['brand_core_ready'],'missing_brand_core'=>$r['missing_brand_core'],
   'steps'=>$steps,'current_action'=>$r['brand_core_ready']?'ready_for_native_resume':'collect_evidence_and_review',
   'optional_enrichment'=>['google_drive'=>['required'=>false,'connected'=>!empty($r['providers']['google_drive']['ready']),
    'next_action'=>empty($r['providers']['google_drive']['ready'])?'owner_oauth_via_native_control_plane':'scan_selected_folder']],
   'continue_independent_research'=>true,'publish_without_approval'=>false,
   'foreign_scope_import'=>false,'jobs_transitioned'=>0,'read_only'=>true];
 }
 static function draft($input) {
  $permit=self::permission();if(is_wp_error($permit))return $permit;
  $r=self::status();if(is_wp_error($r))return $r;
  if($r['effective_store']!=='managed_native')return new WP_Error('use_selected_provider','Managed draft write unavailable for active store',['status'=>409]);
  $category=sanitize_key((string)($input['category']??''));
  $content=(string)($input['content']??'');
  if(!in_array($category,self::CATEGORIES,true)||strlen($content)<20||strlen($content)>100000||!mb_check_encoding($content,'UTF-8'))
   return new WP_Error('invalid_draft','Category or UTF-8 content invalid',['status'=>422]);
  $identity=$r['identity'];
  $title=substr(sanitize_text_field((string)($input['title']??$identity['brand_name'].' - '.$category)),0,180);
  $id=wp_insert_post(['post_type'=>self::CPT,'post_status'=>'draft','post_title'=>$title,'post_content'=>wp_kses_post($content)],true);
  if(is_wp_error($id))return $id;
  foreach(['_mad4b_brand_id'=>$identity['brand_id'],'_mad4b_site_uuid'=>$identity['site_uuid'],
   '_mad4b_asset_category'=>$category,'_mad4b_review_state'=>'pending_human_review',
   '_mad4b_content_sha256'=>hash('sha256',$content)] as $k=>$v)update_post_meta($id,$k,$v);
  return ['contract'=>self::CONTRACT,'draft_id'=>$id,'store'=>'managed_native','category'=>$category,
   'state'=>'pending_human_review','native_approval'=>false,'published'=>false,'post_status'=>get_post_status($id)];
 }
 static function link_media($input) {
  $permit=self::permission();if(is_wp_error($permit))return $permit;
  $r=self::status();if(is_wp_error($r))return $r;
  if($r['selected_store']!=='wordpress_media')return new WP_Error('media_store_not_selected','Select Media Library first',['status'=>409]);
  $id=absint($input['attachment_id']??0);
  if(!$id||get_post_type($id)!=='attachment')return new WP_Error('attachment_not_found','Media attachment not found',['status'=>404]);
  $brand=$r['identity']['brand_id'];$site=$r['identity']['site_uuid'];
  $b=get_post_meta($id,'_mad4b_brand_owner_id',true);
  $s=get_post_meta($id,'_mad4b_brand_owner_site_uuid',true);
  if(($b&&$b!==$brand)||($s&&$s!==$site))return new WP_Error('foreign_brand_asset','Attachment belongs to another scope',['status'=>409]);
  if(!function_exists('shared_mad4b_media_register'))return new WP_Error('native_media_adapter_missing','Native Media adapter unavailable',['status'=>503]);
  $a=shared_mad4b_media_register($id);
  if(is_wp_error($a))return $a;
  update_post_meta($id,'_mad4b_brand_owner_id',$brand);
  update_post_meta($id,'_mad4b_brand_owner_site_uuid',$site);
  $term_slug='mad4b-brand-'.substr($brand,0,24);
  $term=get_term_by('slug',$term_slug,'mad4b_brand_collection');
  if(!$term){
   $created=wp_insert_term($r['identity']['brand_name'],'mad4b_brand_collection',['slug'=>$term_slug]);
   if(is_wp_error($created))return $created;
   $term=get_term((int)$created['term_id'],'mad4b_brand_collection');
  }
  if(!$term||is_wp_error($term))return new WP_Error('media_collection_unavailable','Dedicated brand media collection unavailable');
  $claimed=get_term_meta($term->term_id,'_mad4b_brand_id',true);
  if($claimed&&$claimed!==$brand)return new WP_Error('collection_brand_collision','Media collection belongs to another brand');
  update_term_meta($term->term_id,'_mad4b_brand_id',$brand);
  update_term_meta($term->term_id,'_mad4b_site_uuid',$site);
  $bound=wp_set_object_terms($id,[(int)$term->term_id],'mad4b_brand_collection',false);
  if(is_wp_error($bound))return $bound;
  return ['contract'=>self::CONTRACT,'media_id'=>$id,'source_registered'=>true,'collection_id'=>(int)$term->term_id,
   'brand_id'=>$brand,'native_approval'=>false,'state'=>'pending_human_review','readback'=>(bool)get_post_meta($id,'_mad4b_brand_owner_id',true)];
 }
 static function assets($input=[]) {
  $permit=self::permission();if(is_wp_error($permit))return $permit;
  $r=self::status();if(is_wp_error($r))return $r;
  $brand=$r['identity']['brand_id'];$site=$r['identity']['site_uuid'];
  $kind=sanitize_key((string)($input['store']??$r['effective_store']));
  if(!in_array($kind,['managed_native','wordpress_media'],true))return ['contract'=>self::CONTRACT,'items'=>[],'read_from'=>'external_provider_required'];
  $ids=get_posts(['post_type'=>$kind==='managed_native'?self::CPT:'attachment',
   'post_status'=>$kind==='managed_native'?['draft','pending','private','publish']:'inherit',
   'fields'=>'ids','posts_per_page'=>min(50,max(1,absint($input['limit']??20))),
   'meta_query'=>[['key'=>$kind==='managed_native'?'_mad4b_brand_id':'_mad4b_brand_owner_id','value'=>$brand],
    ['key'=>$kind==='managed_native'?'_mad4b_site_uuid':'_mad4b_brand_owner_site_uuid','value'=>$site]]]);
  $items=[];
  foreach($ids as $id)$items[]=['id'=>$id,'title'=>get_the_title($id),'category'=>get_post_meta($id,'_mad4b_asset_category',true),
   'status'=>get_post_meta($id,'_mad4b_review_state',true)?:'pending_human_review',
   'file_exposed_to_public'=>$kind==='wordpress_media'];
  return ['contract'=>self::CONTRACT,'store'=>$kind,'items'=>$items,'owner_scope_verified'=>true];
 }
 const RECOVERY_OPT='mad4b_brand_asset_store_recovery_tickets_v1';
 static function reconcile($input=[],$trusted_hook=false) {
  if(!$trusted_hook){$auth=self::permission();if(is_wp_error($auth))return $auth;}
  $job_id=(string)($input['job_id']??'');
  if(!wp_is_uuid($job_id))return new WP_Error('job_id_required','Exact ContentJob UUID required',['status'=>422]);
  $identity=self::scope();if(is_wp_error($identity))return $identity;
  if(!class_exists('MAD4B_SCP_Content_Jobs'))return new WP_Error('content_jobs_unavailable','Native ContentJob runtime unavailable',['status'=>503]);
  $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
  if(is_wp_error($j))return $j;
  $job=$j['job']??[];
  if(!is_array($job)||($job['site_uuid']??'')!==$identity['site_uuid']||($job['brand_id']??'')!==$identity['brand_id'])
   return new WP_Error('job_scope_mismatch','Job is not owned by this site and brand',['status'=>403]);
  $s=self::status();if(is_wp_error($s))return $s;
  $all=get_option(self::RECOVERY_OPT,[]);
  if(!is_array($all))$all=[];
  $record=['contract'=>'mad4b.brand-asset-store-recovery-ticket.v1','job_id'=>$job_id,
   'site_uuid'=>$identity['site_uuid'],'brand_id'=>$identity['brand_id'],
   'job_revision'=>(int)($job['job_revision']??0),
   'job_state'=>(string)($job['state']??''),
   'job_stage'=>(string)($job['stage']??''),
   'selected_store'=>$s['selected_store'],'effective_store'=>$s['effective_store'],
   'brand_core_ready'=>$s['brand_core_ready'],
   'missing_categories'=>$s['missing_brand_core'],
   'state'=>$s['brand_core_ready']?'READY_FOR_NATIVE_JOB_REEVALUATION':'WAITING_FOR_BRAND_AUTHORITY',
   'owner_review_required'=>!$s['brand_core_ready'],
   'job_transition_performed'=>false,'production_mutation'=>false,
   'checked_at'=>gmdate('c')];
  $all[$job_id]=$record;
  $all=array_slice($all,-100,null,true);
  update_option(self::RECOVERY_OPT,$all,false);
  if(($all[$job_id]['brand_id']??'')!==$identity['brand_id'])
   return new WP_Error('recovery_readback_failed','Brand recovery ticket could not be read back');
  if($s['brand_core_ready']) do_action('mad4b_brand_asset_store_context_ready',$job_id,$record);
  return $record;
 }
 static function queue_job($event) {
  $id=is_array($event)?($event['job_id']??($event['job']['job_id']??'')):'';
  if(!is_string($id)||!wp_is_uuid($id))return;
  $args=['job_id'=>$id];
  if(function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action')) {
   if(!as_has_scheduled_action('mad4b_brand_asset_store_recovery_tick',$args,'mad4b-hub-bulk'))
    as_enqueue_async_action('mad4b_brand_asset_store_recovery_tick',$args,'mad4b-hub-bulk',true);
  }
 }
 static function job_tick($job_id) {
  if(!wp_is_uuid((string)$job_id))return;
  if(!function_exists('wp_set_current_user'))return;
  // Internal Action Scheduler callback is bounded to a site/brand-verified readback.
  // It never approves assets or changes the ContentJob stage.
  $record=self::reconcile(['job_id'=>(string)$job_id],true);
  if(is_wp_error($record))do_action('mad4b_brand_asset_store_recovery_pending',(string)$job_id);
 }
 static function tickets($input=[]) {
  $permit=self::permission();if(is_wp_error($permit))return $permit;
  $id=self::scope();if(is_wp_error($id))return $id;
  $all=get_option(self::RECOVERY_OPT,[]);
  if(!is_array($all))$all=[];
  $out=array_values(array_filter($all,static fn($v)=>is_array($v)&&($v['site_uuid']??'')===$id['site_uuid']&&($v['brand_id']??'')===$id['brand_id']));
  return ['contract'=>self::CONTRACT,'tickets'=>array_slice($out,-25),'scoped'=>true];
 }
 static function can($request=null){return self::permission($request);}
 static function rest() {
  register_rest_route('mad4b/v1','/brand-asset-store/reconcile',
   ['methods'=>'POST','callback'=>static fn($r)=>self::reconcile($r->get_json_params()??[]),
   'permission_callback'=>[__CLASS__,'can']]);
  register_rest_route('mad4b/v1','/brand-asset-store/tickets',
   ['methods'=>'GET','callback'=>static fn($r)=>self::tickets([]),
   'permission_callback'=>[__CLASS__,'can']]);
  $base='/brand-asset-store';
  foreach(['/status'=>'status','/recovery-plan'=>'recovery_plan','/assets'=>'assets'] as $path=>$method)
   register_rest_route('mad4b/v1',$base.$path,['methods'=>'GET','callback'=>static fn($r)=>rest_ensure_response(self::$method($r->get_params())),'permission_callback'=>[__CLASS__,'can']]);
  foreach(['/configure'=>'configure','/draft'=>'draft','/media-link'=>'link_media'] as $path=>$method)
   register_rest_route('mad4b/v1',$base.$path,['methods'=>'POST','callback'=>static function($r)use($method){$v=self::$method($r->get_json_params()??[]);return is_wp_error($v)?$v:rest_ensure_response($v);},'permission_callback'=>[__CLASS__,'can']]);
 }
 static function abilities() {
  if(!function_exists('wp_register_ability'))return;
  foreach(['mad4b/brand-asset-store-status'=>'status','mad4b/brand-asset-store-recovery-plan'=>'recovery_plan','mad4b/brand-asset-store-assets'=>'assets'] as $name=>$method) {
   if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
   wp_register_ability($name,['label'=>ucwords(str_replace('-',' ',basename(str_replace('mad4b/','',$name)))),
    'description'=>'Read-only site-bound Brand Asset Store status, eligible providers and recovery.',
    'category'=>'mad4b-read','execute_callback'=>static fn($args)=>self::$method($args),
    'permission_callback'=>[__CLASS__,'can'],
    'input_schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
     'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]]);
  }
  $defs=[
   'mad4b/brand-asset-store-configure'=>['configure',['store','expected_revision'],['store'=>['type'=>'string','enum'=>self::CHOICES],'expected_revision'=>['type'=>'integer','minimum'=>0],'external_root_id'=>['type'=>'string']]],
   'mad4b/brand-asset-store-reconcile'=>['reconcile',['job_id'],['job_id'=>['type'=>'string']]],
   'mad4b/brand-asset-store-draft-create'=>['draft',['category','content'],['category'=>['type'=>'string','enum'=>self::CATEGORIES],'content'=>['type'=>'string'],'title'=>['type'=>'string']]],
   'mad4b/brand-asset-store-media-link'=>['link_media',['attachment_id'],['attachment_id'=>['type'=>'integer','minimum'=>1]]]
  ];
  foreach($defs as $name=>$definition) {
   if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
   [$method,$required,$properties]=$definition;
   wp_register_ability($name,['label'=>ucwords(str_replace('-',' ',str_replace('mad4b/','',$name))),
    'description'=>'Authorized Wo Vacation Staging Brand Asset Store operation; brand approval stays native.',
    'category'=>'mad4b-write','execute_callback'=>static fn($args)=>self::$method($args),
    'permission_callback'=>[__CLASS__,'can'],
    'input_schema'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,
     'mcp'=>['public'=>false,'surface'=>'write','type'=>'tool'],
     'annotations'=>['readonly'=>false,'destructive'=>false,'idempotent'=>false]]]);
  }
 }
 static function register_cpt() {
  register_taxonomy('mad4b_brand_collection',['attachment'],[
   'label'=>'MAD4B Brand Asset Collections','public'=>false,'hierarchical'=>false,
   'show_ui'=>true,'show_admin_column'=>true,'show_in_rest'=>false,
   'capabilities'=>['manage_terms'=>'manage_options','edit_terms'=>'manage_options',
     'delete_terms'=>'manage_options','assign_terms'=>'manage_options']]);
  register_post_type(self::CPT,['label'=>'Brand Context Drafts','public'=>false,'show_ui'=>false,
   'show_in_rest'=>false,'exclude_from_search'=>true,'supports'=>['title','editor','revisions'],
   'capability_type'=>'post','rewrite'=>false,'query_var'=>false]);
 }
 static function admin_page() {
  if(!current_user_can('manage_options'))return;
  $status=self::status();if(is_wp_error($status)){echo '<div class="wrap"><h1>Brand Asset Store</h1><p>'.esc_html($status->get_error_message()).'</p></div>';return;}
  echo '<div class="wrap"><h1>Brand Asset Store — '.esc_html($status['identity']['brand_name']).'</h1>';
  echo '<p>Managed Native, dedicated WordPress Media Library, and Google Drive are supported store choices. Brand ownership and approval remain separate.</p>';
  echo '<p>Selected: <strong>'.esc_html($status['selected_store']).'</strong>; effective: '.esc_html($status['effective_store']).'; Brand Core ready: '.($status['brand_core_ready']?'yes':'no').'</p>';
  echo '<p><strong>Google Drive is optional.</strong> If Google authorization is unavailable, Brand Context recovery continues through native stores and native review. Google is never a prerequisite for ContentJob research or Brand Core draft creation.</p>';
  echo '<p><a class="button" href="'.esc_url($status['google_drive_settings_url']).'">Connect or repair optional Google Drive</a></p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="mad4b_brand_store_configure">';
  echo '<input type="hidden" name="expected_revision" value="'.(int)$status['revision'].'">';
  wp_nonce_field('mad4b_brand_store_configure');
  echo '<p><label>Store <select name="store">';
  foreach(self::CHOICES as $opt)echo '<option value="'.esc_attr($opt).'" '.selected($status['selected_store'],$opt,false).'>'.esc_html(str_replace('_',' ',ucwords($opt,'_'))).'</option>';
  echo '</select></label></p>';
  echo '<p><label>Google Drive folder ID <input type="text" name="external_root_id" value="'.esc_attr($status['external_root_id']).'" size="45"></label></p>';
  submit_button('Save Brand Asset Store');
  echo '</form><p>WordPress Media files may have public URLs. Do not upload confidential context to the public uploads directory.</p>';
  echo '<pre>'.esc_html(wp_json_encode(self::recovery_plan(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></div>';
 }
 static function admin_save() {
  $p=self::permission();if(is_wp_error($p))wp_die(esc_html($p->get_error_message()));
  check_admin_referer('mad4b_brand_store_configure');
  $result=self::configure(['store'=>sanitize_key(wp_unslash($_POST['store']??'')),
   'external_root_id'=>sanitize_text_field(wp_unslash($_POST['external_root_id']??'')),
   'expected_revision'=>absint($_POST['expected_revision']??-1)]);
  wp_safe_redirect(add_query_arg('saved',is_wp_error($result)?$result->get_error_code():'yes',admin_url('options-general.php?page=mad4b-brand-asset-store')));exit;
 }
 static function alternate_provider_route($result,$context=[],$coverage=[]) {
  $id=self::scope();if(is_wp_error($id))return $result;
  if(($context['brand_id']??'')!==$id['brand_id']||($context['site_uuid']??'')!==$id['site_uuid'])
   return $result;
  $caps=self::capabilities();
  // Media registration is implemented by existing native Context Authority.
  // A managed CPT alone is a draft store, not native approved context authority.
  if(!empty($caps['wordpress_media']['ready']))
   return ['ready'=>true,'write_capable'=>true,'store'=>'wordpress_media',
    'grant_created'=>false,'native_approval_bypassed'=>false];
  return $result;
 }
 static function boot() {
  add_filter('mad4b_brand_core_alternative_store_readiness',[__CLASS__,'alternate_provider_route'],20,3);
  add_action('init',[__CLASS__,'register_cpt'],8);
  add_action('rest_api_init',[__CLASS__,'rest'],30);
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],60);
  add_action('admin_menu',static fn()=>add_options_page('MAD4B Brand Asset Store','Brand Asset Store','manage_options','mad4b-brand-asset-store',[__CLASS__,'admin_page']),85);
  add_action('admin_post_mad4b_brand_store_configure',[__CLASS__,'admin_save']);
  add_action('mad4b_scp_content_job_created',[__CLASS__,'queue_job'],85,1);
  add_action('mad4b_scp_content_job_transitioned',[__CLASS__,'queue_job'],85,1);
  add_action('mad4b_brand_asset_store_recovery_tick',[__CLASS__,'job_tick'],10,1);
 }
}
MAD4B_Brand_Asset_Store_V1::boot();
