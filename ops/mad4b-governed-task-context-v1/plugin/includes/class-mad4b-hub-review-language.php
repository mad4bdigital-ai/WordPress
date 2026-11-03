<?php
defined('ABSPATH') || exit;
/**
 * Bilingual review projection: site operating country, not audience or IP.
 * Translation data must be tied to the exact output digest and authored by
 * a traceable real writer/MCP client. No synthetic translations on page load.
 */
final class MAD4B_Hub_Review_Language {
 const CONTRACT='mad4b.hub.review-localization.v1';
 const COUNTRY_OPTION='mad4b_hub_review_operating_country';
 const ARAB_COUNTRIES=['AE','BH','DZ','EG','IQ','JO','KW','LB','LY','MA','MR','OM','PS','QA','SA','SD','SO','SY','TN','YE','DJ','KM'];
 private static function error($code,$status=400){
  return new WP_Error('mad4b_review_locale_'.$code,$code,['status'=>$status]);
 }
 public static function country(){
  // Explicit reviewed site country; fallback to canonical WooCommerce store
  // address. Never infer operating country from WP locale, visitor IP or SERP market.
  $raw=get_option(self::COUNTRY_OPTION,'');
  if(!is_string($raw)||$raw==='')$raw=get_option('mad4b_site_operating_country','');
  if(!is_string($raw)||$raw==='')$raw=get_option('woocommerce_default_country','');
  $raw=is_string($raw)?$raw:'';
  $raw=strtoupper(trim(explode(':',$raw)[0]));
  $country=(preg_match('/^[A-Z]{2}$/D',$raw)?$raw:'');
  $country=apply_filters('mad4b_hub_review_operating_country',$country);
  return is_string($country)&&preg_match('/^[A-Z]{2}$/D',$country)?$country:'';
 }
 public static function bilingual(){return in_array(self::country(),self::ARAB_COUNTRIES,true);}
 public static function label($english,$arabic){
  if(!self::bilingual())return esc_html($english);
  return '<span lang="ar" dir="rtl" class="mad4b-rv-arlabel">'.esc_html($arabic).'</span><span aria-hidden="true" class="mad4b-rv-labelslash"> / </span><span lang="en" dir="ltr">'.esc_html($english).'</span>';
 }
 public static function status(){
  return ['contract'=>self::CONTRACT,'operating_country'=>self::country(),
    'bilingual'=>self::bilingual(),
    'resolution_source'=>self::source_name(),
    'languages'=>self::bilingual()?['ar','en']:['en'],
    'translation_is_automatic'=>false];
 }
 private static function source_name(){
  if(get_option(self::COUNTRY_OPTION,''))return 'site_review_operating_country';
  if(get_option('mad4b_site_operating_country',''))return 'site_operating_country';
  if(get_option('woocommerce_default_country',''))return 'woocommerce_store_country';
  return 'not_configured';
 }
 private static function option_key($batch){
  return 'mad4b_review_locale_'.substr(hash('sha256',$batch),0,40);
 }
 public static function translations($batch,$digest){
  if(!is_string($batch)||!wp_is_uuid($batch)||
    !is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest))return [];
  $data=get_option(self::option_key($batch),[]);
  $data=apply_filters('mad4b_hub_review_translations',$data,$batch,$digest);
  if(!is_array($data)||($data['contract']??'')!==self::CONTRACT
    ||($data['source_output_sha256']??'')!==$digest ||($data['batch_id']??'')!==$batch)
    return [];
  $langs=[];
  foreach(['ar','en'] as $lang){
   $t=$data['translations'][$lang]??null;
   if(!is_array($t)||!isset($t['title'],$t['summary'],$t['body']))continue;
   if(!is_string($t['body'])||mb_strlen($t['body'])<20||
       mb_strlen($t['body'])>20000)continue;
   $langs[$lang]=[
    'title'=>(string)$t['title'],
    'summary'=>(string)$t['summary'],
    'body'=>(string)$t['body'],
    'limitations'=>isset($t['limitations'])&&is_array($t['limitations'])?$t['limitations']:[],
    'evidence_refs'=>isset($t['evidence_refs'])&&is_array($t['evidence_refs'])?$t['evidence_refs']:[],
    'provenance'=>(string)($t['provenance']??'authored'),
    'verification'=>(string)($t['verification']??'pending'),
    'created_at'=>(string)($t['created_at']??'')
   ];
  }
  return $langs;
 }
 private static function authorized(){
  $user=wp_get_current_user();
  $admin=in_array('administrator',(array)$user->roles,true)&&current_user_can('manage_options');
  $master=in_array('master_admin',(array)$user->roles,true)&&current_user_can('mad4b_manage_company_operations');
  if(!$admin&&!$master)return false;
  if(defined('WP_CLI')&&WP_CLI&&PHP_SAPI==='cli'){
   $runner=WP_CLI::get_runner();$arguments=(array)($runner->arguments??[]);
   $id=(int)($runner->config['user']??0);
   return $admin&&$id===get_current_user_id()&&$id>0
      &&in_array(($arguments[0]??''),['eval','eval-file'],true)
      &&wp_get_environment_type()==='staging';
  }
  $oauth=class_exists('MAD4B_SCP_OAuth_Resource_Bridge')
      &&MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active();
  if($oauth&&class_exists('MAD4B_SCP_OAuth_Subject_User_Bridge')){
   $mapped=MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context();
   return ($mapped['authenticated']??false)===true
     &&(int)($mapped['wp_user_id']??0)===get_current_user_id();
  }
  return is_user_logged_in(); // Browser submissions require CSRF nonce below.
 }
 public static function save($input=[]){
  if(!self::authorized())return self::error('permission_denied',403);
  if(!(defined('WP_CLI')&&WP_CLI)&&!(
    class_exists('MAD4B_SCP_OAuth_Resource_Bridge')
    &&MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
   )){
   if(!wp_verify_nonce((string)($input['wp_nonce']??''),'mad4b_hub_review_translation'))
    return self::error('nonce_invalid',403);
  }
  $batch=(string)($input['batch_id']??'');
  $language=(string)($input['language']??'');
  if(!in_array($language,['ar','en'],true))return self::error('language_invalid');
  if(!wp_is_uuid($batch))return self::error('batch_invalid');
  $review=MAD4B_Hub_Role_Review::preview(['batch_id'=>$batch]);
  if(is_wp_error($review))return $review;
  $digest=(string)($review['output_sha256']??'');
  if(!hash_equals($digest,(string)($input['expected_output_sha256']??'')))
   return self::error('source_revision_changed',409);
  $t=$input['translation']??null;
  if(!is_array($t)||array_diff(array_keys($t),['title','summary','body','limitations','evidence_refs']))
   return self::error('translation_contract_invalid');
  $title=(string)($t['title']??'');
  $summary=(string)($t['summary']??'');
  $body=(string)($t['body']??'');
  if(mb_strlen($title)<4||mb_strlen($title)>250
    ||mb_strlen($summary)<10||mb_strlen($summary)>2000
    ||mb_strlen($body)<20||mb_strlen($body)>20000)
   return self::error('translation_length_invalid');
  $limits=$t['limitations']??[];
  $evidence=$t['evidence_refs']??[];
  if(!is_array($limits)||!is_array($evidence)||count($limits)>30||count($evidence)>100)
   return self::error('translation_list_invalid');
  foreach(array_merge($limits,$evidence) as $v)
   if(!is_string($v)||mb_strlen($v)>1300)return self::error('translation_item_invalid');
  // Translation may not introduce new evidence references.
  $sourceRefs=(array)($review['output']['evidence_refs']??[]);
  foreach($evidence as $ref)if(!in_array($ref,$sourceRefs,true))
   return self::error('translation_evidence_not_source_bound',409);
  $key=self::option_key($batch);
  $old=get_option($key,[]);
  if(!is_array($old))$old=[];
  if($old&&($old['source_output_sha256']??'')!==$digest)return self::error('existing_source_mismatch',409);
  $old['contract']=self::CONTRACT;$old['batch_id']=$batch;
  $old['source_output_sha256']=$digest;
  $old['translations'][$language]=[
   'title'=>$title,'summary'=>$summary,'body'=>$body,
   'limitations'=>$limits,'evidence_refs'=>$evidence,
   'provenance'=>'user_or_mcp_authored',
   'verification'=>'pending_editorial_review',
   'wp_user_id'=>get_current_user_id(),'created_at'=>gmdate('c')
  ];
  // Bound storage is always a review candidate, never a published translation.
  $ok=update_option($key,$old,false);
  if(!$ok && get_option($key,[])!==$old)return self::error('save_failed',500);
  return ['contract'=>self::CONTRACT,'batch_id'=>$batch,'language'=>$language,
    'source_output_sha256'=>$digest,'state'=>'translation_for_review',
    'publication_authorized'=>false,'translation_verified'=>false];
 }
 public static function pending($input=[]){
  if(!current_user_can('edit_posts')&&!current_user_can('manage_options')&&
     !current_user_can('mad4b_manage_company_operations'))return self::error('read_denied',403);
  $inbox=MAD4B_Hub_Role_Review::inbox();
  if(is_wp_error($inbox))return $inbox;
  $items=[];
  foreach((array)($inbox['items']??[]) as $item){
   $batch=(string)($item['batch_id']??'');
   $locale=self::translations($batch,(string)($item['output_sha256']??''));
   $primary=(string)($item['language']??'en');
   $missing=[];
   foreach(self::status()['languages'] as $lang)
    if($lang!==$primary&&!isset($locale[$lang]))$missing[]=$lang;
   if($missing)$items[]=['batch_id'=>$batch,'title'=>$item['title']??'',
      'original_language'=>$primary,'needed_languages'=>$missing,
      'source_output_sha256'=>$item['output_sha256']];
  }
  return ['contract'=>self::CONTRACT,'items'=>$items,'count'=>count($items),
    'operating_country'=>self::country(),'languages'=>self::status()['languages']];
 }
 public static function boot(){
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],63);
  add_action('rest_api_init',[__CLASS__,'routes'],63);
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability'))return;
  foreach(['pending'=>false,'save'=>true] as $name=>$write){
   $id='mad4b/hub-review-localization-'.$name;
   if(wp_has_ability($id))continue;
   wp_register_ability($id,[
    'label'=>'Content Hub Review Localization '.ucfirst($name),
    'description'=>'Source-digest-bound Arabic/English editorial review translations. No publication.',
    'category'=>$write?'mad4b-write':'mad4b-read',
    'execute_callback'=>[__CLASS__,$name],
    'permission_callback'=>static fn()=>is_user_logged_in() && current_user_can('read'),
    'input_schema'=>['type'=>'object','properties'=>[
      'batch_id'=>['type'=>'string'],
      'language'=>['type'=>'string'],
      'expected_output_sha256'=>['type'=>'string'],
      'translation'=>['type'=>'object','additionalProperties'=>true],
      'wp_nonce'=>['type'=>'string']
    ],'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,
      'mcp'=>['public'=>false,'type'=>'tool','surface'=>$write?'write':'read'],
      'annotations'=>['readonly'=>!$write,'destructive'=>false,'idempotent'=>!$write]]
   ]);
  }
 }
 public static function routes(){
  register_rest_route('mad4b-hub/v1','/control-workflow/review-localization/pending',[
   'methods'=>'GET','permission_callback'=>static fn()=>current_user_can('edit_posts')||current_user_can('manage_options'),
   'callback'=>static fn($request)=>self::pending($request->get_params())]);
  register_rest_route('mad4b-hub/v1','/control-workflow/review-localization/save',[
   'methods'=>'POST','permission_callback'=>static fn()=>self::authorized(),
   'callback'=>static fn($request)=>self::save($request->get_params())]);
 }
}
