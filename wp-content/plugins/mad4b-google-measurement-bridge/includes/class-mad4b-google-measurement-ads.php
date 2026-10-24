<?php
if (!defined('ABSPATH')) exit;
/**
 * Google Ads API v25 integration: read-only GAQL allowlist, OAuth-only requests.
 * The developer-token header is omitted (sunset 2026-09-09).
 * OAuth project must separately have Google Ads API production access.
 */
final class MAD4B_Google_Measurement_Ads {
 const CONTRACT='mad4b.google-measurement.google-ads.v1';
 const SCOPE='https://www.googleapis.com/auth/adwords';
 const BASE='https://googleads.googleapis.com/v25/';
 const BINDING='mad4b_google_ads_exact_binding_v1';
 public static function boot() {
  add_action('admin_post_mad4b_google_ads_bind',array(__CLASS__,'save'));
 }
 private static function site() {
  if (!MAD4B_Google_Measurement_Bridge::gate())return false;
  if (!class_exists('MAD4B_SCP_Site_Profile'))return false;
  $site=MAD4B_SCP_Site_Profile::status();
  if (!is_array($site)||empty($site['origin_match'])||empty($site['environment_match'])||
      empty($site['site_uuid'])||empty($site['profile_digest']))return false;
  return $site;
 }
 public static function binding() {
  $site=self::site();
  $o=get_option(self::BINDING,array());
  if (!$site||!is_array($o)||empty($o['site_uuid'])||empty($o['profile_digest'])||
      !hash_equals((string)$site['site_uuid'],(string)$o['site_uuid'])||
      !hash_equals((string)$site['profile_digest'],(string)$o['profile_digest'])||
      !preg_match('/^[0-9]{10}$/D',(string)($o['customer_id']??'')))return array('ready'=>false);
  return array('ready'=>true,'customer_id'=>$o['customer_id'],
    'login_customer_id'=>(string)($o['login_customer_id']??''),
    'site_uuid'=>$o['site_uuid']);
 }
 public static function save() {
  if (!current_user_can('manage_options')||!check_admin_referer('mad4b_google_ads_bind'))
   wp_die('Permission denied.');
  $site=self::site();
  if (!$site)wp_die('Site identity unavailable.');
  $customer=isset($_POST['customer_id'])?sanitize_text_field(wp_unslash($_POST['customer_id'])):'';
  $manager=isset($_POST['login_customer_id'])?sanitize_text_field(wp_unslash($_POST['login_customer_id'])):'';
  if (!preg_match('/^[0-9]{10}$/D',$customer)||
      ($manager!==''&&!preg_match('/^[0-9]{10}$/D',$manager)))wp_die('Invalid Ads customer ID.');
  update_option(self::BINDING,array('site_uuid'=>(string)$site['site_uuid'],
    'profile_digest'=>(string)$site['profile_digest'],
    'customer_id'=>$customer,'login_customer_id'=>$manager,
    'bound_by'=>(int)get_current_user_id(),'bound_at'=>time()),false);
  wp_safe_redirect(admin_url('tools.php?page=mad4b-google-measurement&ads_bound=1'));exit;
 }
 private static function request($url,$body=null,$manager='') {
  if (!preg_match('~^https://googleads\.googleapis\.com/v25/(?:customers:listAccessibleCustomers|customers/[0-9]{10}/googleAds:searchStream)$~D',$url))
   return new WP_Error('mad4b_ads_url_rejected','Google Ads endpoint not allowlisted.');
  $token=MAD4B_Google_Measurement_API::token(self::SCOPE);
  if (is_wp_error($token))return $token;
  $headers=array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json');
  if ($manager!=='')$headers['login-customer-id']=$manager;
  $args=array('timeout'=>12,'redirection'=>0,'headers'=>$headers);
  if ($body!==null) {
   $args['method']='POST';$args['headers']['Content-Type']='application/json';
   $args['body']=wp_json_encode($body);
  }
  $result=wp_remote_request($url,$args);unset($token);
  if (is_wp_error($result))return new WP_Error('mad4b_ads_transport','Google Ads API transport failed.');
  $status=(int)wp_remote_retrieve_response_code($result);
  $raw=(string)wp_remote_retrieve_body($result);
  if (strlen($raw)>1048576||$status<200||$status>=300)
   return new WP_Error('mad4b_ads_google_api_error','Google Ads API denied request.',array('http_status'=>$status));
  $data=json_decode($raw,true);
  return is_array($data)?$data:new WP_Error('mad4b_ads_invalid_json','Invalid Google Ads response.');
 }
 public static function reports() {
  return array(
   'customer'=> 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.manager FROM customer LIMIT 1',
   'campaigns'=> 'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign_budget.amount_micros, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date DURING LAST_30_DAYS LIMIT 100',
   'ad_groups'=> 'SELECT ad_group.id, ad_group.name, ad_group.status, campaign.id, campaign.name, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions FROM ad_group WHERE segments.date DURING LAST_30_DAYS LIMIT 100',
   'keywords'=> 'SELECT ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.status, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions FROM keyword_view WHERE segments.date DURING LAST_30_DAYS LIMIT 100',
   'search_terms'=> 'SELECT search_term_view.search_term, campaign.id, ad_group.id, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions FROM search_term_view WHERE segments.date DURING LAST_30_DAYS LIMIT 100',
   'conversions'=> 'SELECT conversion_action.id, conversion_action.name, conversion_action.category, conversion_action.status, conversion_action.primary_for_goal FROM conversion_action LIMIT 100',
   'audiences'=> 'SELECT user_list.id, user_list.name, user_list.description, user_list.membership_status, user_list.size_for_display, user_list.size_for_search FROM user_list LIMIT 100',
   'budgets'=> 'SELECT campaign_budget.id, campaign_budget.name, campaign_budget.amount_micros, campaign_budget.status FROM campaign_budget LIMIT 100',
   'campaign_30_day_daily'=> 'SELECT segments.date, campaign.id, campaign.name, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date DURING LAST_30_DAYS LIMIT 250'
  );
 }
 public static function run($input=array()) {
  if (!self::site())return new WP_Error('mad4b_ads_permission_denied','Owner site read access required.');
  $action=isset($input['action'])?(string)$input['action']:'';
  if ($action==='accounts') {
   $result=self::request(self::BASE.'customers:listAccessibleCustomers');
   if (is_wp_error($result))return $result;
   return array('contract'=>self::CONTRACT,'accessible_customers'=>$result['resourceNames']??array(),
    'binding'=>self::binding(),'developer_token_used'=>false,'read_only'=>true);
  }
  $binding=self::binding();
  if (empty($binding['ready']))return new WP_Error('mad4b_ads_binding_required','Select an exact Google Ads Customer ID.');
  $reports=self::reports();
  if (!isset($reports[$action]))return new WP_Error('mad4b_ads_unknown_report','Unsupported report.');
  $url=self::BASE.'customers/'.$binding['customer_id'].'/googleAds:searchStream';
  $result=self::request($url,array('query'=>$reports[$action]),$binding['login_customer_id']);
  if (is_wp_error($result))return $result;
  return array('contract'=>self::CONTRACT,'action'=>$action,'customer_id'=>$binding['customer_id'],
    'report'=>$result,'read_only'=>true,'developer_token_used'=>false,
    'api_access_level_verified'=>false);
 }
 public static function register() {
  if (!function_exists('wp_register_ability'))return;
  $name='mad4b/google-ads-read';
  if (function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,array('label'=>'MAD4B Google Ads Reports',
   'description'=>'Allowlisted Google Ads API v25 account and campaign reads, OAuth only, no developer token.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'run'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('action'),
    'properties'=>array('action'=>array('type'=>'string','enum'=>array_merge(array('accounts'),array_keys(self::reports()))))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,
    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
