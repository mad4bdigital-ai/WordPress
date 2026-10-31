<?php
/**
 * Plugin Name: MAD4B Canonical Gap Queue (Staging)
 * Description: Evidence-driven source data gaps from imported Canonical, rates and review holds. No external scrape or publishing.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Canonical_Gaps_V1 {
 const VERSION='0.1.0';
 const OPTION='mad4b_hub_canonical_gaps_v1';
 const RECEIPT='mad4b_hub_canonical_gaps_receipt_v1';
 private static function t(){global $wpdb;return $wpdb->prefix.'mad4b_hub_canonical_gaps';}
 public static function admin(){return class_exists('MAD4B_Hub_Canonical_Registry_V1') &&
  MAD4B_Hub_Canonical_Registry_V1::site() && current_user_can('manage_options');}
 private static function err($s,$code=409){return new WP_Error('canonical_gaps_'.$s,'Gap queue denied',['status'=>$code]);}
 private static function ready(){return get_option(self::OPTION)==self::VERSION;}
 public static function install(){
  if(!self::admin())return self::err('admin',403);
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  global $wpdb;$t=self::t();$collate=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $t (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   gap_key char(64) NOT NULL,
   canonical_id bigint(20) unsigned NOT NULL,
   source_hub_id bigint(20) unsigned NOT NULL,
   source_type varchar(65) NOT NULL,
   company_id varchar(90) NOT NULL,
   language varchar(12) NOT NULL,
   gap_code varchar(60) NOT NULL,
   severity varchar(3) NOT NULL,
   state varchar(32) NOT NULL,
   provider_hint varchar(65) NOT NULL,
   source_sha256 char(64) NOT NULL,
   detail_json text NOT NULL,
   created_at datetime NOT NULL,
   updated_at datetime NOT NULL,
   PRIMARY KEY  (id),
   UNIQUE KEY gap_key (gap_key),
   KEY company_state (company_id,state),
   KEY entity_type (canonical_id,gap_code)
  ) $collate;");
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))!==$t)return self::err('schema');
  update_option(self::OPTION,self::VERSION,false);
  return ['schema'=>self::VERSION,'table'=>$t,'external_scrape_authorized'=>false];
 }
 private static function missing($v){return !is_scalar($v)||trim((string)$v)==='';}
 private static function gap($code,$severity,$provider,$extra=[]){
  $optional=['inventory_unverified'=>'not_applicable','source_pricing_missing'=>'optional_data_missing','property_identity_missing'=>'optional_data_missing','source_rate_dates_invalid'=>'nonblocking_rate_warning'];
  if($code==='inventory_unverified' && apply_filters('mad4b_canonical_inventory_required',false,$extra)===true)unset($optional['inventory_unverified']);
  $state=$optional[$code]??'needs_evidence';
  if($state!=='needs_evidence')$severity='P3';
  return ['gap_code'=>$code,'severity'=>$severity,'provider_hint'=>$provider,'detail'=>$extra,'state'=>$state];
 }
 private static function collect($entity,$price){
  $snapshot=json_decode((string)$entity['snapshot_json'],true);
  if(!is_array($snapshot)||($snapshot['contract']??'')!=='mad4b.hub.private-canonical-source.v1')return null;
  $type=(string)$entity['source_type'];
  $fields=$snapshot['fields']??[];
  $identity=$fields['identity_facts']??[];
  if(!is_array($identity))$identity=[];
  $gaps=[];
  if($entity['state']==='review_hold')
   $gaps[]=self::gap('entity_identity_review','P0','verified_source_identity',
    ['reason'=>'existing_dedup_review_hold','recheck_after'=>'evidence_review']);
  if($entity['state']==='withdrawn')
   $gaps[]=self::gap('source_withdrawn','P0','source_provider',
    ['reason'=>'removed_from_active_source_snapshot']);
  if($type==='properties' && self::missing($identity['property_place_id']??null))
   $gaps[]=self::gap('property_identity_missing','P0','property_identity_provider',
    ['required'=>'verified_property_place_id_or_independent_business_reference']);
  if(self::missing(wp_strip_all_tags((string)($fields['description']??''))))
   $gaps[]=self::gap('description_missing','P1','source_then_research',
    ['required'=>'source_description_or_licensed_independent_content']);
  $prices=is_array($price)?json_decode((string)$price['observation_json'],true):null;
  if(!is_array($prices)||(!count($prices['starting_prices']??[]) && !count($prices['rates']??[])))
   $gaps[]=self::gap('source_pricing_missing','P0','source_pricing_provider',
    ['required'=>'starting_price_or_valid_source_rate']);
  if(is_array($prices)){
   $invalid=0;
   foreach(($prices['rates']??[]) as $r){
    if(($r['valid_from']??null)!==null && ($r['valid_until']??null)!==null &&
      $r['valid_from']>$r['valid_until'])$invalid++;
   }
   if($invalid)$gaps[]=self::gap('source_rate_dates_invalid','P0','source_pricing_provider',
    ['invalid_rate_count'=>$invalid,'required'=>'source_rate_date_correction']);
   if(!empty($prices['rates']))
    $gaps[]=self::gap('inventory_unverified','P0','source_inventory_provider',
     ['rates_count'=>count($prices['rates']),'required'=>'live_availability_and_allotment_evidence']);
  }
  return $gaps;
 }
 public static function sync($company='tourism-almallahgroup') {
  if(!self::admin()||!self::ready())return self::err('permission',403);
  if($company!=='tourism-almallahgroup')return self::err('unsupported_source',422);
  if(MAD4B_Hub_Auto_Distribute_V1::settings()['enabled'])return self::err('distribution_enabled');
  global $wpdb;
  $entityTable=$wpdb->prefix.'mad4b_hub_canonical_entities';
  $priceTable=$wpdb->prefix.'mad4b_hub_price_observations';
  $t=self::t();
  $entities=$wpdb->get_results($wpdb->prepare(
   "SELECT id,source_hub_id,source_type,language,company_id,state,snapshot_hash,snapshot_json
    FROM $entityTable WHERE company_id=%s ORDER BY id ASC", $company),ARRAY_A);
  if(count($entities)!==827)return self::err('canonical_count_drift');
  $rateRows=$wpdb->get_results($wpdb->prepare(
   "SELECT canonical_id,source_hash,observation_json FROM $priceTable WHERE source_company=%s",$company),ARRAY_A);
  if(count($rateRows)!==827)return self::err('price_coverage_drift');
  $byPrice=[];foreach($rateRows as $p){
   if(isset($byPrice[$p['canonical_id']]))return self::err('duplicate_price_identity');
   $byPrice[$p['canonical_id']]=$p;
  }
  $prepared=[];$keySet=[];$distribution=[];
  foreach($entities as $entity){
   $cid=(int)$entity['id'];
   if(!isset($byPrice[$cid]))return self::err('price_identity_missing');
   $gaps=self::collect($entity,$byPrice[$cid]);
   if(!is_array($gaps))return self::err('canonical_snapshot_corrupt');
   foreach($gaps as $gap){
    $key=hash('sha256',$company.'|'.$cid.'|'.$gap['gap_code']);
    if(isset($keySet[$key]))return self::err('duplicate_gap');
    $keySet[$key]=true;
    $distribution[$gap['gap_code']]=($distribution[$gap['gap_code']]??0)+1;
    $details=wp_json_encode($gap['detail'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $prepared[]=[
     'gap_key'=>$key,'canonical_id'=>$cid,'source_hub_id'=>(int)$entity['source_hub_id'],
     'source_type'=>$entity['source_type'],'company_id'=>$company,'language'=>$entity['language'],
     'gap_code'=>$gap['gap_code'],'severity'=>$gap['severity'],'state'=>$gap['state']??'needs_evidence',
     'provider_hint'=>$gap['provider_hint'],
     'source_sha256'=>hash('sha256',$entity['snapshot_hash'].'|'.$byPrice[$cid]['source_hash']),
     'detail_json'=>$details,
    ];
   }
  }
  $now=gmdate('Y-m-d H:i:s');$created=0;$updated=0;$unchanged=0;$closed=0;
  $lock='mad4b_hub_gap_sync';
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1)return self::err('busy');
  try {
   $wpdb->query('START TRANSACTION');
   foreach($prepared as $p){
    $prior=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE gap_key=%s",$p['gap_key']),ARRAY_A);
    if($prior){
     $same=true;
     foreach($p as $key=>$value)if((string)$prior[$key]!== (string)$value){$same=false;break;}
     if($same){$unchanged++;continue;}
     $p['updated_at']=$now;
     if($wpdb->update($t,$p,['id'=>(int)$prior['id']])===false)throw new RuntimeException('gap_update');
     $updated++;
    }else{
     $p['created_at']=$now;$p['updated_at']=$now;
     if(!$wpdb->insert($t,$p))throw new RuntimeException('gap_insert');
     $created++;
    }
   }
   // A disappeared gap is resolved only if its canonical record still exists and was rescanned.
   $known=$wpdb->get_results($wpdb->prepare(
      "SELECT id,gap_key,state FROM $t WHERE company_id=%s AND state IN ('needs_evidence','not_applicable','optional_data_missing','nonblocking_rate_warning')",$company),ARRAY_A);
   foreach($known as $row)if(!isset($keySet[$row['gap_key']])){
    if($wpdb->update($t,['state'=>'resolved_by_source_recheck','updated_at'=>$now],
      ['id'=>(int)$row['id']])===false)throw new RuntimeException('gap_close');
    $closed++;
   }
   if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit_failed');
  }catch(Throwable $e){
   $wpdb->query('ROLLBACK');return self::err('sync_'.$e->getMessage(),503);
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
  $r=['contract'=>'mad4b.hub.canonical-gap-scan.v1','source_company'=>$company,
   'entities_scanned'=>count($entities),'gaps'=>count($prepared),
   'created'=>$created,'updated'=>$updated,'unchanged'=>$unchanged,'resolved'=>$closed,
   'gap_types'=>$distribution,'scrape_executed'=>false,'source_modified'=>false,
   'publish_authorized'=>false,'commercial_eligibility'=>false,'checked_utc'=>gmdate('c')];
  update_option(self::RECEIPT,$r,false);
  return $r;
 }
 public static function status(){
  if(!self::admin()||!self::ready())return self::err('permission',403);
  global $wpdb;$t=self::t();
  return ['contract'=>'mad4b.hub.canonical-gap-queue.v1',
   'count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE state='needs_evidence'"),
   'by_code'=>$wpdb->get_results("SELECT gap_code,severity,COUNT(*) n FROM $t WHERE state='needs_evidence' GROUP BY gap_code,severity ORDER BY n DESC",ARRAY_A),
   'diagnostic_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE state IN ('not_applicable','optional_data_missing','nonblocking_rate_warning')"),
   'policy_version'=>'mad4b-canonical-optional-v2',
   'last_receipt'=>get_option(self::RECEIPT,[]),
   'external_provider_execution_certified'=>false,'commercial_distribution_authorized'=>false];
 }
 public static function tasks($code='',$offset=0,$limit=30){
  if(!self::admin()||!self::ready())return self::err('permission',403);
  if(!is_string($code)||($code!==''&&!preg_match('/^[a-z_]{3,60}$/D',$code)) ||
      !is_int($offset)||$offset<0||!is_int($limit)||$limit<1||$limit>50)return self::err('scope');
  global $wpdb;$t=self::t();
  $where="WHERE company_id='tourism-almallahgroup' AND state='needs_evidence'";
  if($code!=='')$where.=$wpdb->prepare(" AND gap_code=%s",$code);
  $rows=$wpdb->get_results($wpdb->prepare(
   "SELECT id,canonical_id,source_hub_id,source_type,language,gap_code,severity,state,provider_hint,detail_json
    FROM $t $where ORDER BY severity ASC,id ASC LIMIT %d OFFSET %d",$limit,$offset),ARRAY_A);
  $items=[];foreach($rows as $x){
   $items[]=['task_id'=>(int)$x['id'],'canonical_id'=>(int)$x['canonical_id'],
    'source_hub_id'=>(int)$x['source_hub_id'],'source_type'=>$x['source_type'],
    'language'=>$x['language'],'gap_code'=>$x['gap_code'],'severity'=>$x['severity'],
    'provider_hint'=>$x['provider_hint'],'details'=>json_decode($x['detail_json'],true),
    'plan'=>'source_evidence_required','auto_research_executed'=>false];
  }
  return ['contract'=>'mad4b.hub.canonical-gap-tasks.v1','count'=>count($items),'offset'=>$offset,
   'tasks'=>$items,'execution'=>'NOT_STARTED','source_permissions_not_bypassed'=>true];
 }
 public static function rest(){
  if(!MAD4B_Hub_Canonical_Registry_V1::site())return;
  register_rest_route('mad4b-central/v1','/content-gaps/status',[
   'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
   'callback'=>static function(){return rest_ensure_response(self::status());}
  ]);
  register_rest_route('mad4b-central/v1','/content-gaps/tasks',[
   'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
   'callback'=>static function($r){
    $v=self::tasks((string)($r->get_param('gap_code')??''),
     max(0,(int)($r->get_param('offset')??0)),
     min(50,max(1,(int)($r->get_param('limit')??30))));
    return is_wp_error($v)?$v:rest_ensure_response($v);
   }
  ]);
 }
 public static function menu(){
  if(!MAD4B_Hub_Canonical_Registry_V1::site())return;
  add_submenu_page('edit.php?post_type=mad4b_hub_nile','Canonical Data Gaps','Content Gaps',
   'manage_options','mad4b-canonical-gaps',[__CLASS__,'screen']);
 }
 public static function screen(){
  if(!self::admin())return;
  $status=self::status();$tasks=self::tasks('',0,15);
  echo '<div class="wrap"><h1>Canonical Content Gaps</h1>';
  echo '<p>Read-only source diagnostics and evidence queue. Source-first research, no automatic publishing or rate guessing.</p>';
  echo '<h2>Gap Summary</h2><pre>'.esc_html(wp_json_encode($status,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
  echo '<h2>First 15 Tasks</h2><pre>'.esc_html(wp_json_encode($tasks,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></div>';
 }
 public static function ability_status($input=[]){
  if(!is_array($input)||count($input)>0)return self::err('invalid_ability_input',422);
  return self::status();
 }
 public static function ability_tasks($input=[]){
  if(!is_array($input)||array_diff(array_keys($input),['gap_code','offset','limit']))
   return self::err('invalid_ability_input',422);
  return self::tasks((string)($input['gap_code']??''),
   (int)($input['offset']??0),(int)($input['limit']??30));
 }
 public static function abilities(){
  if(!self::admin()&&!MAD4B_Hub_Canonical_Registry_V1::site())return;
  if(!function_exists('wp_register_ability'))return;
  $definitions=[
   'mad4b/hub-content-gaps'=>[
    'label'=>'Canonical Content Gaps','description'=>'Read the current content, identity, rate and inventory gap summary.',
    'callback'=>[__CLASS__,'ability_status'],
    'input'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
   ],
   'mad4b/hub-content-gap-tasks'=>[
    'label'=>'Canonical Gap Tasks','description'=>'Read private source-first evidence tasks, without running external research.',
    'callback'=>[__CLASS__,'ability_tasks'],
    'input'=>['type'=>'object','properties'=>[
     'gap_code'=>['type'=>'string','maxLength'=>60],
     'offset'=>['type'=>'integer','minimum'=>0],
     'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50],
    ],'additionalProperties'=>false],
   ],
  ];
  foreach($definitions as $name=>$d){
   if(function_exists('wp_has_ability') && wp_has_ability($name))continue;
   wp_register_ability($name,[
    'label'=>$d['label'],'description'=>$d['description'],'category'=>'mad4b-read',
    'execute_callback'=>$d['callback'],'permission_callback'=>[__CLASS__,'admin'],
    'input_schema'=>$d['input'],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,
     'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
     'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]],
   ]);
  }
 }
 public static function boot(){
  add_action('rest_api_init',[__CLASS__,'rest']);
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],40);
 }
}
MAD4B_Hub_Canonical_Gaps_V1::boot();

