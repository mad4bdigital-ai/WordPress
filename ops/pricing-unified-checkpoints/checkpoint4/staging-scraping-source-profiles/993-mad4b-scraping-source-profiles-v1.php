<?php
/**
 * MAD4B Scraping Source Profiles v1
 * Central-safe source defaults + company/source-specific overrides.
 * Evidence-based pricing and publication eligibility, not an independent
 * authorization system. Mutations require enrolled Staging WP-CLI admin.
 */
defined('ABSPATH') || exit;
final class MAD4B_Scraping_Source_Profiles_V1 {
 const CONTRACT='mad4b.scraping-source-profiles.v1';
 const OPT='mad4b_scraping_source_profiles_v1';
 const FIELDS=['description','excerpt','tour_sub_title','tour_mode','includes','excludes',
  'gallery','amenities','properties','units','transfers','extra_trips','prices','duration','taxonomies','supplier_brand','source_operator'];
 const TIER=['D'=>0,'C'=>1,'B'=>2,'A'=>3];
 const MAX=50;
 private static function err($suffix) {return new WP_Error('mad4b_source_profile_'.$suffix);}
 private static function gate() {
  return defined('WP_CLI') && WP_CLI &&
   wp_get_environment_type()==='staging' && current_user_can('manage_options') &&
   class_exists('MAD4B_SCP_Site_Profile') &&
   MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
 }
 public static function defaults() {
  // Official hotel rooms are useful for editorial room labels and verified
  // published maximum occupancy. They DO NOT certify supplier cost, image
  // usage rights, date-specific availability, tier assignment or checkout.
  // These hosts were verified against their own hotel booking/source pages
  // on 2026-11-21. Site admins still approve each proposed editorial field.
  $officialHosts=[
    'sofitel.accor.com','triumphhotel.com','tolipgroup.com',
    'sonesta.com','jazhotels.com','baronhotels.com','marinasharm.com',
    'pyramisahotels.com'
  ];
  $editorial=[];
  foreach($officialHosts as $host){
    $editorial[$host]=[
      'id'=>'official_hotel_rooms_'.str_replace('.','_',$host),
      'source_host'=>$host,
      'transport'=>'firecrawl',
      'fetch_mode'=>'html_only',
      'field_trust'=>['properties'=>'B','units'=>'B','amenities'=>'B','taxonomies'=>'C'],
      'exclude_fields'=>['prices','gallery','supplier_brand','source_operator'],
      'price_policy'=>'hold',
      'content_policy'=>'manual_review',
      'auto_publish_authorized'=>false,
      'pricing_evidence'=>'none',
      'max_source_age_hours'=>24,
      'min_price_valid_days'=>1
    ];
  }
  return $editorial+[
   'memphistours.com'=>[
    'id'=>'memphis_tours','source_host'=>'memphistours.com',
    'transport'=>'firecrawl',
    'fetch_mode'=>'html_then_browser_if_required',
    'field_trust'=>['description'=>'B','excerpt'=>'B','duration'=>'B',
      'gallery'=>'B','amenities'=>'B','properties'=>'B','units'=>'B','transfers'=>'B','extra_trips'=>'B','prices'=>'B','includes'=>'B','excludes'=>'B',
      'tour_mode'=>'B','tour_sub_title'=>'B','taxonomies'=>'B'],
    'exclude_fields'=>['supplier_brand','source_operator'],
    'price_policy'=>'priced_draft_review',
    'content_policy'=>'manual_review',
    'auto_publish_authorized'=>false,
    'pricing_evidence'=>'exact_duration_price_card',
    'max_source_age_hours'=>24,
    'min_price_valid_days'=>1
   ]
  ];
 }
 private static function normalize_host($input) {
  $host=strtolower(trim((string)$input));
  if(str_starts_with($host,'www.'))$host=substr($host,4);
  if(!preg_match('/^[a-z0-9][a-z0-9.-]{2,190}\.[a-z]{2,}$/D',$host) ||
   str_contains($host,'..') || filter_var($host,FILTER_VALIDATE_IP))return '';
  return $host;
 }
 public static function host($url) {
  $u=wp_parse_url((string)$url);
  return is_array($u) && ($u['scheme']??'')==='https'?
   self::normalize_host($u['host']??''):'';
 }
 private static function store() {
  $r=get_option(self::OPT,[]);
  return is_array($r)?$r:[];
 }
 private static function selector($input) {
  $raw=trim((string)$input);
  if(str_starts_with($raw,'https://')) {
   $host=self::host($raw);
   if(!$host || wp_parse_url($raw,PHP_URL_USER) ||
      wp_parse_url($raw,PHP_URL_PASS) ||
      wp_parse_url($raw,PHP_URL_FRAGMENT) || strlen($raw)>1500)
    return ['', ''];
   return [$host,'url-'.substr(hash('sha256',rtrim($raw,'/')),0,32)];
  }
  $host=self::normalize_host($raw);
  return [$host,$host];
 }
 private static function source($host) {
  $catalog=apply_filters('mad4b_scraping_source_central_defaults_v1',self::defaults());
  return is_array($catalog) && is_array($catalog[$host]??null)?$catalog[$host]:null;
 }
 public static function resolve($url,$company='') {
  $host=self::host($url);
  if(!$host)return self::err('url_invalid');
  $base=self::source($host);
  if(!$base) {
   // Unregistered domains have no implicit trust or action authority.
   return ['contract'=>self::CONTRACT,'source_host'=>$host,
    'source_profile_id'=>'unregistered','profile_state'=>'unregistered',
    'transport'=>'none','fetch_mode'=>'html_only','field_trust'=>[],
    'exclude_fields'=>['prices','gallery','includes','excludes'],
    'price_policy'=>'hold','content_policy'=>'manual_review',
    'auto_publish_authorized'=>false,'source_trust_tier'=>'D',
    'effective_revision'=>0,'profile_digest'=>''];
  }
  $stored=self::store();
  $identity=class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::site_uuid():'';
  $site=get_option('mad4b_source_profile_company_code_v1','site-'.substr(hash('sha256',(string)$identity),0,16));
  $company=$company!==''?sanitize_key($company):sanitize_key($site);
  if(!$company || strlen($company)>80)$company='site-'.substr(hash('sha256',(string)$identity),0,16);
  $overrides=[];
  $url_key='url-'.substr(hash('sha256',rtrim((string)$url,'/')),0,32);
  foreach([['central',$host],[$company,$host],['central',$url_key],
     [$company,$url_key]] as $binding) {
   [$scope,$key]=$binding;
   $o=$stored[$scope][$key]??[];
   if(!is_array($o))continue;
   $overrides[]=$o;
  }
  $fields=(array)$base['field_trust'];
  $excluded=(array)$base['exclude_fields'];
  $publication=(string)$base['content_policy'];
  $prices=(string)$base['price_policy'];
  $fetch=(string)$base['fetch_mode'];
  $revisions=[];
  foreach($overrides as $override) {
   $excluded=array_values(array_unique(array_merge($excluded,(array)($override['exclude_fields']??[]))));
   foreach((array)($override['field_trust']??[]) as $field=>$tier) {
    if(!isset($fields[$field],self::TIER[$tier]))continue;
    if(self::TIER[$tier]<=self::TIER[$fields[$field]])$fields[$field]=$tier;
   }
   if(($override['price_policy']??'')==='hold')$prices='hold';
   elseif(($override['price_policy']??'')==='priced_draft_review' && $prices==='auto_eligible')
    $prices='priced_draft_review';
   if(($override['content_policy']??'')==='manual_review')$publication='manual_review';
   if(($override['fetch_mode']??'')==='html_only')$fetch='html_only';
   $revisions[]=(int)($override['revision']??0);
  }
  $excluded=array_values(array_unique(array_intersect($excluded,self::FIELDS)));
  sort($excluded,SORT_STRING);
  $tiers=array_values(array_filter($fields,static fn($k)=>isset(self::TIER[$k])));
  $min=$tiers?min(array_map(static fn($t)=>self::TIER[$t],$tiers)):0;
  $tier=array_search($min,self::TIER,true);
  $profile=[
   'contract'=>self::CONTRACT,'source_host'=>$host,
   'source_profile_id'=>(string)$base['id'],'profile_state'=>'enrolled',
   'company'=>$company,'transport'=>(string)$base['transport'],
   'fetch_mode'=>$fetch,'field_trust'=>$fields,'exclude_fields'=>$excluded,
   'price_policy'=>$prices,'content_policy'=>$publication,
   'auto_publish_authorized'=>(bool)$base['auto_publish_authorized'] &&
      $publication==='auto_eligible',
   'source_trust_tier'=>$tier?:'D',
   'pricing_evidence'=>(string)$base['pricing_evidence'],
   'max_source_age_hours'=>(int)$base['max_source_age_hours'],
   'min_price_valid_days'=>(int)$base['min_price_valid_days'],
   'effective_revision'=>max($revisions?:[0])
  ];
  $profile['profile_digest']=hash('sha256',
   wp_json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $profile;
 }
 public static function field_allowed($profile,$field) {
  return is_array($profile) && ($profile['profile_state']??'')==='enrolled' &&
    in_array($field,self::FIELDS,true) &&
    !in_array($field,(array)($profile['exclude_fields']??[]),true);
 }
 public static function price_decision($profile,$observation) {
  $decision=['contract'=>'mad4b.source-price-confidence-decision.v1',
   'action'=>'price_pending','score'=>0,'tier'=>'D','reason'=>'evidence_incomplete',
   'rate_creation_allowed'=>false,'rate_publication_allowed'=>false,
   'booking_authorized'=>false,'source_profile_digest'=>(string)($profile['profile_digest']??'')];
  if(!self::field_allowed($profile,'prices') ||
    ($profile['price_policy']??'')==='hold') {
   $decision['reason']='profile_price_excluded_or_hold';return $decision;
  }
  $amount=$observation['amount']??null;
  $currency=$observation['currency']??null;
  $basis=$observation['basis']??null;
  $evidence=$observation['evidence']??null;
  $sha=$observation['source_card_sha256']??null;
  if(!is_string($amount) || !preg_match('/^[1-9][0-9]{1,6}\.[0-9]{2}$/D',$amount)
   || !is_finite((float)$amount) || $currency!=='USD' || $basis!=='per_person'
   || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D',$sha)) {
   $decision['reason']='positive_amount_currency_basis_provenance_required';return $decision;
  }
  if($evidence!=='exact_duration_supplier_price_card' &&
     $evidence!=='authenticated_supplier_api') {
   $decision['reason']='price_evidence_type_untrusted';return $decision;
  }
  $expiry=(string)($observation['valid_until']??'');
  if($expiry!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$expiry) ||
     $expiry<gmdate('Y-m-d'))) {
   $decision['reason']='supplier_price_expired_or_invalid_date';return $decision;
  }
  $captured=(string)($observation['captured_at']??'');
  if($captured!=='' && (!strtotime($captured) ||
    strtotime($captured)<time()-max(1,(int)($profile['max_source_age_hours']??24))*3600)) {
   $decision['reason']='source_price_observation_stale';return $decision;
  }
  $raw=(string)($profile['field_trust']['prices']??'D');
  $tier=isset(self::TIER[$raw])?$raw:'D';
  $level=self::TIER[$tier];
  $authenticated=$evidence==='authenticated_supplier_api' &&
   ($observation['supplier_api_authenticated']??false)===true;
  // Authenticated API evidence is still capped by its actual source trust.
  $score=$authenticated?($level>=3?95:65):($level>=2?78:45);
  $decision['score']=$score;$decision['tier']=$tier;
  if($score<70) {
   $decision['reason']='source_price_trust_below_draft_threshold';return $decision;
  }
  $decision['action']='priced_draft_review';
  $decision['rate_creation_allowed']=true;
  $decision['reason']='source_price_evidenced_needs_commercial_review';
  if($authenticated && $score>=90 &&
    ($profile['price_policy']??'')==='auto_eligible') {
   $decision['action']='auto_apply_eligible_only';
   $decision['reason']='original_commerce_authority_and_availability_still_required';
  }
  return $decision;
 }
 public static function content_decision($profile,$evidence=[]) {
  $result=['contract'=>'mad4b.source-content-publication-decision.v1',
   'action'=>'review','eligible'=>false,'reason'=>'profile_manual_review',
   'original_publish_preflight_required'=>true];
  if(($profile['content_policy']??'')==='auto_eligible' &&
     !empty($profile['auto_publish_authorized']) &&
     ($profile['source_trust_tier']??'D')==='A' &&
     !empty($evidence['fields_complete']) && !empty($evidence['brand_ready']) &&
     !empty($evidence['approved_editorial_authority'])) {
   $result['action']='auto_publish_eligible';
   $result['eligible']=true;
   $result['reason']='requires_existing_content_experience_plan_apply';
  }
  return $result;
 }
 public static function status() {
  $stored=self::store();
  $source=get_option('mad4b_source_content_loop_v1',[]);
  $rows=[];
  foreach((array)$source as $entry) {
   if(!is_array($entry) || empty($entry['url']))continue;
   $p=self::resolve($entry['url']);
   if(is_wp_error($p))continue;
   $rows[]=['url'=>$entry['url'],'post_id'=>(int)($entry['post_id']??0),
    'profile'=>$p,'content_decision'=>self::content_decision($p)];
  }
  return ['contract'=>self::CONTRACT,'version'=>'1.0.0',
   'policy_layers'=>['central_defaults','central_host','company_host',
     'central_exact_url','company_exact_url','field_evidence'],
   'source_count'=>count($rows),
   'profiles'=>$rows,'registered_source_hosts'=>array_keys(self::defaults()),
   'stored_override_scope_count'=>count($stored),
   'auto_publish_performed'=>false,'auto_rate_publish_performed'=>false];
 }
 private static function normalize_override($input) {
  $out=['exclude_fields'=>[],'field_trust'=>[]];
  $exclude=(array)($input['exclude_fields']??[]);
  if(count($exclude)>count(self::FIELDS))return self::err('fields_bounds');
  foreach($exclude as $f) {
   if(!is_string($f) || !in_array($f,self::FIELDS,true))return self::err('field_unknown');
   $out['exclude_fields'][]=$f;
  }
  $out['exclude_fields']=array_values(array_unique($out['exclude_fields']));
  sort($out['exclude_fields'],SORT_STRING);
  $trust=(array)($input['field_trust']??[]);
  foreach($trust as $f=>$t) {
   if(!is_string($f)||!in_array($f,self::FIELDS,true) ||
      !is_string($t)||!isset(self::TIER[$t]))return self::err('trust_invalid');
   $out['field_trust'][$f]=$t;
  }
  ksort($out['field_trust'],SORT_STRING);
  foreach(['price_policy'=>['hold','priced_draft_review','auto_eligible'],
   'content_policy'=>['manual_review','auto_eligible'],
   'fetch_mode'=>['html_only','html_then_browser_if_required']] as $field=>$valid) {
   $v=(string)($input[$field]??'');
   if($v!=='' && !in_array($v,$valid,true))return self::err('policy_value_invalid');
   if($v!=='')$out[$field]=$v;
  }
  return $out;
 }
 public static function plan_override($scope,$host,$input) {
  if(!self::gate())return self::err('owner_staging_required');
  [$host_name,$selector]=self::selector($host);
  if(!$host_name || !$selector || !self::source($host_name))
   return self::err('registered_source_required');
  $scope=sanitize_key($scope);
  if($scope!=='central' && !preg_match('/^[a-z][a-z0-9_-]{2,64}$/D',$scope))
   return self::err('company_scope_invalid');
  $values=self::normalize_override($input);if(is_wp_error($values))return $values;
  $base=self::source($host_name);
  foreach($values['field_trust'] as $field=>$tier) {
   if(!isset($base['field_trust'][$field]) ||
     self::TIER[$tier]>self::TIER[$base['field_trust'][$field]])
    return self::err('trust_escalation_denied');
  }
  // Company-scoped overrides may restrict, not introduce automatic rights.
  if($scope!=='central' && (($values['price_policy']??'')==='auto_eligible' ||
    ($values['content_policy']??'')==='auto_eligible'))
   return self::err('company_auto_escalation_denied');
  $stored=self::store();$prior=$stored[$scope][$selector]??[];
  $revision=(int)($prior['revision']??0)+1;
  $candidate=['contract'=>'mad4b.source-profile-override-plan.v1',
   'scope'=>$scope,'host'=>$host_name,'selector'=>$selector,
   'revision'=>$revision,'input'=>$values,
   'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
   'prior_sha256'=>hash('sha256',wp_json_encode($prior))];
  $candidate['plan_sha256']=hash('sha256',
   wp_json_encode($candidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $candidate+['mutation_performed'=>false];
 }
 public static function apply_override($scope,$host,$input,$expected,$confirm) {
  if($confirm!=='APPLY SOURCE PROFILE POLICY')return self::err('confirmation_required');
  $plan=self::plan_override($scope,$host,$input);
  if(is_wp_error($plan))return $plan;
  if(!preg_match('/^[a-f0-9]{64}$/D',(string)$expected) ||
    !hash_equals($plan['plan_sha256'],(string)$expected))
   return self::err('plan_stale');
  $rows=self::store();
  if(count($rows)>self::MAX)return self::err('registry_capacity');
  $candidate=$plan['input'];$candidate['revision']=$plan['revision'];
  $candidate['modified_at']=gmdate('c');
  $rows[$plan['scope']][$plan['selector']]=$candidate;
  update_option(self::OPT,$rows,false);
  $saved=self::store()[$plan['scope']][$plan['selector']]??[];
  if($saved!==$candidate)return self::err('readback_mismatch');
  return ['contract'=>'mad4b.source-profile-override-apply.v1',
   'state'=>'APPLIED','scope'=>$plan['scope'],'host'=>$plan['host'],
   'selector'=>$plan['selector'],
   'revision'=>$plan['revision'],'plan_sha256'=>$plan['plan_sha256'],
   'effective_profile'=>self::resolve(
     str_starts_with($plan['selector'],'url-')?(string)$host:'https://www.'.$plan['host'].'/')];
 }
 public static function boot() {
  add_action('admin_menu',static function(){
   if(wp_get_environment_type()!=='staging')return;
   add_management_page('MAD4B Scraping Sources','Scraping Sources',
    'manage_options','mad4b-scraping-source-profiles',[__CLASS__,'screen']);
  },30);
 }
 public static function screen() {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  $s=self::status();
  echo '<div class="wrap"><h1>Scraping Source Profiles</h1><p>Central defaults → Company → Source → Field. Read-only overview; manage changes through exact WP-CLI plan/apply. A trust rating never constitutes commercial or booking authority.</p>';
  foreach($s['profiles'] as $item){
   $p=$item['profile'];
   echo '<h2>'.esc_html($p['source_profile_id']).' — '.esc_html($p['source_host']).'</h2><p>Post #'.absint($item['post_id']).' | Price: '.esc_html($p['price_policy']).' | Content: '.esc_html($p['content_policy']).'</p>';
   echo '<table class="widefat striped"><tr><th>Field</th><th>Tier</th><th>Policy</th></tr>';
   foreach($p['field_trust'] as $f=>$tier)
    echo '<tr><td>'.esc_html($f).'</td><td>'.esc_html($tier).'</td><td>'.
     (self::field_allowed($p,$f)?'Included':'Excluded').'</td></tr>';
   echo '</table><p>Excluded: '.esc_html(implode(', ',$p['exclude_fields'])).'</p>';
  }
  echo '</div>';
 }
}
MAD4B_Scraping_Source_Profiles_V1::boot();
// Remote clients may inspect the exact source policy without acquiring a
// content, rate or publication write capability.
add_action('wp_abilities_api_init',static function(){
 if(!function_exists('wp_register_ability') || !function_exists('wp_has_ability'))return;
 $permission=static function() {
  return class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_read();
 };
 if(!wp_has_ability('mad4b/scraping-source-profiles-status'))
  wp_register_ability('mad4b/scraping-source-profiles-status',[
   'label'=>'Scraping Source Profiles Status',
   'description'=>'Read source trust, excluded fields, content and price policies.',
   'category'=>'mad4b-read',
   'execute_callback'=>static fn($i)=>MAD4B_Scraping_Source_Profiles_V1::status(),
   'permission_callback'=>$permission,
   'input_schema'=>['type'=>'object','properties'=>[],
    'additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,
    'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
    'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
  ]);
 if(!wp_has_ability('mad4b/scraping-source-profile-inspect'))
  wp_register_ability('mad4b/scraping-source-profile-inspect',[
   'label'=>'Inspect Scraping Source Profile',
   'description'=>'Read an effective source profile for an exact HTTPS URL.',
   'category'=>'mad4b-read',
   'execute_callback'=>static fn($i)=>MAD4B_Scraping_Source_Profiles_V1::resolve(
     (string)($i['source_url']??''),(string)($i['company']??'')),
   'permission_callback'=>$permission,
   'input_schema'=>['type'=>'object','properties'=>[
    'source_url'=>['type'=>'string','maxLength'=>1500],
    'company'=>['type'=>'string','maxLength'=>80]],
    'required'=>['source_url'],'additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,
    'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
    'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
  ]);
},95);
if(defined('WP_CLI') && WP_CLI) {
 WP_CLI::add_command('mad4b-source-profiles',new class {
  private function show($r) {
   if(is_wp_error($r))WP_CLI::error($r->get_error_code());
   WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  }
  private function args($opts) {
   $ex=trim((string)($opts['exclude']??''));
   $trust=trim((string)($opts['trust']??''));
   $out=['exclude_fields'=>$ex!==''?array_map('trim',explode(',',$ex)):[],
    'field_trust'=>[]];
   if($trust!=='')foreach(explode(',',$trust) as $part) {
    $pair=explode(':',$part,2);
    if(count($pair)!==2)WP_CLI::error('Invalid trust field:tier syntax');
    $out['field_trust'][trim($pair[0])]=trim($pair[1]);
   }
   foreach(['price_policy'=>'price-policy','content_policy'=>'content-policy',
     'fetch_mode'=>'fetch-mode'] as $a=>$b)
    if(isset($opts[$b]))$out[$a]=(string)$opts[$b];
   return $out;
  }
  public function status($args,$opts) {$this->show(MAD4B_Scraping_Source_Profiles_V1::status());}
  public function inspect($args,$opts) {
   $this->show(MAD4B_Scraping_Source_Profiles_V1::resolve((string)($args[0]??''),(string)($opts['company']??'')));
  }
  public function plan($args,$opts) {$this->show(MAD4B_Scraping_Source_Profiles_V1::plan_override(
   (string)($opts['scope']??'central'),(string)($args[0]??''),$this->args($opts)));}
  public function apply($args,$opts) {$this->show(MAD4B_Scraping_Source_Profiles_V1::apply_override(
   (string)($opts['scope']??'central'),(string)($args[0]??''),$this->args($opts),
   (string)($opts['expected-plan']??''),(string)($opts['confirm']??'')));}
 });
}
