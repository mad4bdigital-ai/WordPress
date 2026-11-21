<?php
if (!defined('ABSPATH')) exit;
/**
 * Transport selection for MAD4B central providers. This is NOT an authority
 * source: the calling ContentJob/MCP/Native grant remains mandatory.
 * No client-provided URL, tenant selection, or remote fallback is accepted.
 */
final class MAD4B_SCP_Unified_Provider_Routing {
 const CONTRACT='mad4b.unified-provider-routing.v1';
 const FIRECRAWL='firecrawl';
 private static $active_private_request=null;
 public static function boot(){
  add_action('wp_abilities_api_init',[__CLASS__,'register_ability'],42);
 }
 public static function register_ability(){
  if(!function_exists('wp_register_ability') ||
     (function_exists('wp_has_ability') && wp_has_ability('mad4b/provider-routing-status')))return;
  wp_register_ability('mad4b/provider-routing-status',[
    'label'=>'MAD4B Unified Provider Routing Status',
    'description'=>'Read-only provider locality and transport plan; does not grant provider execution or credentials.',
    'category'=>'mad4b-read',
    'execute_callback'=>[__CLASS__,'status'],
    'permission_callback'=>['MAD4B_SCP_Policy','can_read'],
    'input_schema'=>['type'=>'object','additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,
      'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
      'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]],
  ]);
 }
 /**
  * Select a transport from the deployment topology, not untrusted request data.
  * Cloud/external WordPress sites have no private fabric: they use public HTTPS.
  */
 public static function firecrawl_plan($active_mode,$configured_endpoint){
  $mode=(string)$active_mode;
  $public=rtrim((string)$configured_endpoint,'/');
  $blocked=['contract'=>self::CONTRACT,'provider_id'=>self::FIRECRAWL,
    'allowed'=>false,'selected_transport'=>'none','reason'=>'provider_configuration_invalid',
    'dispatch_allowed'=>false,'authorization_checked'=>false,'mutation_performed'=>false,
    'fallback_after_dispatch'=>false];
  if(!in_array($mode,['cloud','self_hosted'],true) ||
     !str_starts_with($public,'https://') || !wp_http_validate_url($public))
    return $blocked;
  if($mode==='cloud' && $public!=='https://api.firecrawl.dev')return $blocked;
  $plan=array_merge($blocked,[
    'allowed'=>true,'selected_transport'=>'central_https',
    'endpoint'=>$public,'reason'=>'external_or_unverified_private_fabric',
    'fallback_policy'=>'pre_dispatch_replan_only_no_replay'
  ]);
  // Environment is deployment-owned, never accepted from MCP/REST input.
  $flag=(string)getenv('MAD4B_FIRECRAWL_PRIVATE_TRANSPORT');
  $local=rtrim((string)getenv('MAD4B_FIRECRAWL_PRIVATE_ENDPOINT'),'/');
  $bound=rtrim((string)getenv('MAD4B_FIRECRAWL_PUBLIC_ORIGIN'),'/');
  if($mode!=='self_hosted' || $flag!=='enabled' || $public!==$bound)return $plan;
  if(!preg_match('#^http://([a-z0-9][a-z0-9.-]{2,125}):([0-9]{2,5})$#D',$local,$m))
    return array_merge($plan,['reason'=>'private_topology_invalid_using_https']);
  if($m[1]!=='mad4b-firecrawl-production-gateway-1' || (int)$m[2]!==8080)
    return array_merge($plan,['reason'=>'private_destination_not_deployment_approved']);
  $resolved=gethostbyname($m[1]);
  if($resolved===$m[1] ||
     !filter_var($resolved,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_RES_RANGE))
    return array_merge($plan,['reason'=>'private_dns_not_verified_using_https']);
  // 172.16.0.0/12 is the installed isolated consumer fabric.
  $oct=array_map('intval',explode('.',$resolved));
  if($oct[0]!==172 || $oct[1]<16 || $oct[1]>31)
    return array_merge($plan,['reason'=>'private_destination_not_consumer_fabric']);
  return array_merge($plan,[
    'selected_transport'=>'private_service_gateway','endpoint'=>$local,
    'reason'=>'deployment_private_fabric_verified'
  ]);
 }
 /** Only the exact in-flight, originally gated Firecrawl request may cross the staging sandbox. */
 public static function transport_permitted($url,$args){
  $a=self::$active_private_request;
  if(!is_array($a)||!is_array($args)||
     (string)$url!==$a['url']||
     strtoupper((string)($args['method']??'GET'))!==(string)($a['method']??'POST')||
     (int)($args['redirection']??1)!==0 ||
     !hash_equals($a['body_sha256'],hash('sha256',(string)($args['body']??''))))
    return false;
  $headers=(array)($args['headers']??[]);
  $basic=(string)($headers['Authorization']??'');
  return str_starts_with($basic,'Basic ') &&
    $a['route']==='private_service_gateway' &&
    (string)getenv('MAD4B_FIRECRAWL_PRIVATE_TRANSPORT')==='enabled' &&
    (string)$url===($a['url']??'') &&
    str_starts_with((string)$url,rtrim((string)getenv('MAD4B_FIRECRAWL_PRIVATE_ENDPOINT'),'/').'/v2/');
 }
 public static function post_scrape($mode,$configured_endpoint,array $args){
  $plan=self::firecrawl_plan($mode,$configured_endpoint);
  if(empty($plan['allowed']))return new WP_Error('mad4b_provider_route_denied','No approved provider route.');
  if(empty($args['headers']['Authorization']) ||
     empty($args['body']) || ($args['redirection']??1)!==0)
    return new WP_Error('mad4b_provider_request_not_bounded','Explicit authenticated provider request required.');
  $url=$plan['endpoint'].'/v2/scrape';
  // A private HTTP URL is legal ONLY for the exact deployment-owned gateway,
  // with network-level isolation and the original provider credentials.
  if($plan['selected_transport']==='private_service_gateway'){
    $args['reject_unsafe_urls']=false;
    if(self::$active_private_request!==null)return new WP_Error('mad4b_provider_private_reentrant_request');
    self::$active_private_request=[
      'url'=>$url,'route'=>'private_service_gateway',
      'body_sha256'=>hash('sha256',(string)$args['body'])
    ];
    try {
      return wp_remote_post($url,$args);
    } finally {self::$active_private_request=null;}
  }
  return wp_safe_remote_post($url,$args);
 }
 public static function request_crawl($mode,$configured_endpoint,$action,$crawl_id,array $args,$next_url=''){
  if(!in_array($action,['start','status'],true))
   return new WP_Error('mad4b_firecrawl_action_invalid');
  if($action==='status' && !preg_match('/^[a-zA-Z0-9-]{16,72}$/D',(string)$crawl_id))
   return new WP_Error('mad4b_firecrawl_crawl_id_invalid');
  $plan=self::firecrawl_plan($mode,$configured_endpoint);
  if(empty($plan['allowed'])||empty($args['headers']['Authorization']))
   return new WP_Error('mad4b_firecrawl_route_denied');
  $path=$action==='start'?'/v2/crawl':'/v2/crawl/'.$crawl_id;
  if($action==='status' && isset($args['skip'])){
   $skip=filter_var($args['skip'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   if($skip===false)return new WP_Error('mad4b_firecrawl_skip_invalid');
   if($skip>0)$path.='?skip='.$skip;
   unset($args['skip']);
  }
  $method=$action==='start'?'POST':'GET';
  if($method==='POST' && empty($args['body']))
   return new WP_Error('mad4b_firecrawl_body_required');
  $url=$plan['endpoint'].$path;
  if($action==='status' && $next_url!==''){
   // Cursor is provider-returned server state, never an arbitrary URL.
   // Keep the exact crawl ID/path on the approved transport endpoint.
   $next=parse_url((string)$next_url);
   $approved=parse_url(rtrim((string)$plan['endpoint'],'/'));
   $public=parse_url(rtrim((string)$configured_endpoint,'/'));
   $matches=static function($candidate,$approved){
     return is_array($approved) &&
       ($candidate['scheme']??'')===($approved['scheme']??'') &&
       ($candidate['host']??'')===($approved['host']??'') &&
       (!isset($candidate['port']) ||
        (int)$candidate['port']===(int)($approved['port']??(($approved['scheme']??'')==='https'?443:80)));
   };
   if(!is_array($next)||
      (!$matches($next,$approved) && !$matches($next,$public))||
      ($next['path']??'')!==$path||
      isset($next['fragment']) ||
      strlen((string)($next['query']??''))>1000)
    return new WP_Error('mad4b_firecrawl_crawl_cursor_origin_invalid');
   $query=(string)($next['query']??'');
   if($query===''||preg_match('/[^A-Za-z0-9._~%=&-]/',$query))
    return new WP_Error('mad4b_firecrawl_crawl_cursor_query_invalid');
   $url.='?'.$query;
  }
  $args['method']=$method;
  $args['redirection']=0;
  $args['sslverify']=true;
  $args['timeout']=min(30,max(5,(int)($args['timeout']??20)));
  if($plan['selected_transport']==='private_service_gateway'){
   if(self::$active_private_request!==null)
    return new WP_Error('mad4b_firecrawl_private_busy');
   $args['reject_unsafe_urls']=false;
   self::$active_private_request=['url'=>$url,'method'=>$method,
     'route'=>'private_service_gateway',
     'body_sha256'=>hash('sha256',(string)($args['body']??''))];
   try{return wp_remote_request($url,$args);}
   finally{self::$active_private_request=null;}
  }
  return wp_safe_remote_request($url,$args);
 }
 public static function status($input=[]){
  if(!class_exists('MAD4B_SCP_Firecrawl_Provider'))return ['contract'=>self::CONTRACT,'ready'=>false];
  $c=MAD4B_SCP_Firecrawl_Provider::config();
  $mode=$c['mode']??'';
  $profile=MAD4B_SCP_Firecrawl_Provider::profiles()[$mode]??[];
  $p=self::firecrawl_plan($mode,$profile['endpoint']??'');
  return [
    'contract'=>self::CONTRACT,'provider'=>'firecrawl',
    'site_uuid'=>class_exists('MAD4B_SCP_Site_Profile')?MAD4B_SCP_Site_Profile::site_uuid():'',
    'selected_transport'=>$p['selected_transport'],'reason'=>$p['reason'],
    'transport_available'=>$p['allowed'],
    'private_fabric_verified'=>$p['selected_transport']==='private_service_gateway',
    'external_hosting_supported'=>true,'remote_sites_require_own_mcp_or_broker_authority'=>true,
    'credentials_exposed'=>false,'original_grant_required'=>true,
    'fallback_after_dispatch'=>false,'mutation_performed'=>false,
    'deployment_managed_by_coolify'=>false
  ];
 }
}
MAD4B_SCP_Unified_Provider_Routing::boot();
