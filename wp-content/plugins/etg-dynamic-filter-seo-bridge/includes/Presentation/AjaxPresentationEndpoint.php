<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';
require_once dirname( __DIR__ ) . '/Identifiers/PresentationToken.php';

use ETG\DynamicFilterSEOBridge\Context\FilterContextBuilder;
use ETG\DynamicFilterSEOBridge\Identifiers\PresentationToken;
use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class AjaxPresentationEndpoint {
    const CONTRACT = 'etg.dfsb.ajax-presentation.v1';
    const MAX_TOKENS = 100;
    const MAX_SLOTS = 50;
    const MAX_PAYLOAD_BYTES = 65536;
    const CLIENT_TIMEOUT_MS = 8000;
    const GROUP_RETRY_ATTEMPTS = 4;
    const GROUP_RETRY_DELAY_MS = 125;
    const CERTIFIED_JSF_VERSION = '3.8.3.1';
    const RATE_LIMIT_REQUESTS = 60;
    const RATE_LIMIT_WINDOW_SECONDS = 10;

    // Legacy profile input `elementor_render_when_global_off` is consumed only
    // by FilterContextBuilder as one non-authorizing input to the derived
    // dark_presentation_allowed decision. This public endpoint never treats
    // the legacy flag itself as URL, SEO, indexing or publication authority.

    private $builder;
    private $resolver;
    private $slots;
    private $catalogProvider;
    private $catalogTokens = null;
    private $catalogTokenMeta = null;

    public function __construct( FilterContextBuilder $builder, PresentationResolver $resolver, ContentSlotRegistry $slots, callable $catalogProvider = null ) {$this->builder=$builder;$this->resolver=$resolver;$this->slots=$slots;$this->catalogProvider=$catalogProvider;}
    public function register():void{add_action('rest_api_init',array($this,'routes'));add_action('wp_enqueue_scripts',array($this,'assets'),30);}
    public function routes():void{register_rest_route('etg-dfsb/v1','/ajax-presentation',array('methods'=>'POST','callback'=>array($this,'handle'),'permission_callback'=>'__return_true'));}

    public function assets():void{
        if(is_admin()||!function_exists('jet_smart_filters')){return;}
        $src=plugins_url('assets/js/ajax-filter-state.js',ETG_DFSB_DIR.'etg-dynamic-filter-seo-bridge.php');wp_enqueue_script('etg-dfsb-ajax-filter-state',$src,array(),ETG_DFSB_VERSION,true);
        wp_localize_script('etg-dfsb-ajax-filter-state','ETGDFSB_AJAX',array('endpoint'=>esc_url_raw(rest_url('etg-dfsb/v1/ajax-presentation')),'contract'=>self::CONTRACT,'maxTokens'=>self::MAX_TOKENS,'maxSlots'=>self::MAX_SLOTS,'timeoutMs'=>self::CLIENT_TIMEOUT_MS,'groupRetryAttempts'=>self::GROUP_RETRY_ATTEMPTS,'groupRetryDelayMs'=>self::GROUP_RETRY_DELAY_MS,'jsfVersion'=>$this->jetSmartFiltersVersion(),'supportedJsfVersions'=>array(self::CERTIFIED_JSF_VERSION),'rateLimitMode'=>$this->rateLimitMode(),'rateLimitRequests'=>self::RATE_LIMIT_REQUESTS,'rateLimitWindowSeconds'=>self::RATE_LIMIT_WINDOW_SECONDS));
    }

    public function handle($request){
        $params=is_object($request)&&method_exists($request,'get_json_params')?$request->get_json_params():array();$params=is_array($params)?$params:array();$encoded=function_exists('wp_json_encode')?wp_json_encode($params):json_encode($params);
        if(!is_string($encoded)||strlen($encoded)>self::MAX_PAYLOAD_BYTES){return$this->response('blocked',array(),array(),array('payload_too_large'));}
        $rate=$this->consumeRateLimit();if(empty($rate['allowed'])){return$this->response('blocked',array(),array(),array('rate_limited'),429,(int)($rate['retry_after']??self::RATE_LIMIT_WINDOW_SECONDS));}
        $context=$this->builder->buildAjaxEvidence($params);$blocking=$this->contextBlockingReasons($context);if($blocking){return$this->response('blocked',array(),$context,$blocking);}
        if(empty($context['global_enabled'])&&empty($context['dark_presentation_allowed'])){return$this->response('blocked_dark_rendering',array(),$context,array('dark_presentation_policy_blocked'));}
        $tokenResult=$this->tokenList($params['tokens']??array(),$context);if(!empty($tokenResult['rejected'])){return$this->response('blocked',array(),$context,array('token_not_allowlisted'));}
        $slotResult=$this->slotList($params['slots']??array());if(!empty($slotResult['rejected'])){return$this->response('blocked',array(),$context,array('slot_not_allowlisted'));}
        $values=array('tokens'=>array(),'slots'=>array());
        foreach($tokenResult['allowed']as$token){$value=$this->resolver->value($token,$context);if(is_array($value)||is_object($value)){continue;}$values['tokens'][$token]=array('value'=>(string)$value,'type'=>$this->tokenType($token));}
        foreach($slotResult['allowed']as$slotId){$type=$this->resolver->slotType($slotId);$entry=array('value'=>$this->resolver->slot($slotId,$context),'type'=>$type);if('image'===$type){$entry['image']=$this->resolver->slotImage($slotId,$context);}elseif('gallery'===$type){$entry['gallery']=$this->resolver->slotGallery($slotId,$context,30);}$values['slots'][$slotId]=$entry;}
        return$this->response('ready',$values,$context,array());
    }

    private function contextBlockingReasons(array$context):array{
        $reasons=array();if(empty($context['active'])){$reasons[]='ajax_state_inactive';}if(empty($context['in_scope'])){$reasons[]=(string)($context['scope']['reason']??'ajax_state_out_of_scope');}if(isset($context['scope_valid'])&&empty($context['scope_valid'])){$reasons[]='scope_invalid';}if(empty($context['runtime_ready'])){$reasons[]='runtime_not_ready';}if(empty($context['provider_client_observed'])||empty($context['provider_client_matches_state'])){$reasons[]='provider_state_mismatch';}if(!empty($context['unknown_filters'])){$reasons[]='unknown_filter';}if(!empty($context['malformed'])){$reasons[]='malformed_filter';}if(!empty($context['unsupported_filter_props'])||(array_key_exists('filtered_query_complete',$context)&&empty($context['filtered_query_complete']))){$reasons[]='unsupported_filter_state';}if(!empty($context['missing_terms'])){$reasons[]='missing_term';}if(!empty($context['translation_fallback'])){$reasons[]='translation_fallback';}if(!empty($context['authorizing'])||!empty($context['url_authority'])||empty($context['ajax_only'])){$reasons[]='ajax_authority_boundary_violation';}
        $profile=(array)($context['profile']??array());if(!empty($profile['require_post_type_binding'])){$binding=(array)($context['post_type_binding']??array());if(empty($binding['observed'])){$reasons[]='post_type_unobserved';}elseif(empty($binding['matches_profile'])){$reasons[]='post_type_mismatch';}}
        return array_values(array_unique(array_filter($reasons)));
    }

    private function response(string$status,array$values,array$context,array$reasons,int$httpStatus=200,int$retryAfter=0){
        $body=array(
            'contract' => self::CONTRACT,
            'status' => $status,
            'authorizing' => false,
            'url_authority' => false,
            'seo_mutation' => false,
            'state_transport' => 'ajax',
            'provider' => (string)($context['provider']??''),
            'query_id' => (string)($context['query_id']??''),
            'provider_client_observed' => !empty($context['provider_client_observed']),
            'provider_client_source' => (string)($context['provider_client_source']??''),
            'provider_server_observed' => !empty($context['provider_server_observed']),
            'profile_id' => (string)($context['profile_id']??''),
            'request_path' => (string)($context['request_path']??''),
            'archive_path' => (string)($context['archive_path']??''),
            'evidence_origin' => (string)($context['evidence_origin']??''),
            'dark_presentation_allowed' => !empty($context['dark_presentation_allowed']),
            'dark_presentation_source' => (string)($context['dark_presentation_source']??'blocked'),
            'filters' => (array)($context['filter_values']??$context['filters']??array()),
            'filtered_query_complete' => !empty($context['filtered_query_complete']),
            'result_count' => $context['result_count']??null,
            'result_count_source' => (string)($context['result_count_source']??'unavailable'),
            'result_count_authoritative' => !empty($context['result_count_authoritative']),
            'values' => $values,
            'blocking_reasons' => array_values(array_unique(array_filter(array_map('sanitize_key',$reasons)))),
        );
        if(function_exists('rest_ensure_response')){$response=rest_ensure_response($body);if(is_object($response)&&method_exists($response,'set_status')&&200!==$httpStatus){$response->set_status($httpStatus);}if(is_object($response)&&method_exists($response,'header')){$response->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');$response->header('X-Robots-Tag','noindex, nofollow, noarchive');if($retryAfter>0){$response->header('Retry-After',(string)$retryAfter);}}return$response;}return$body;
    }

    private function rateLimitMode():string{if(function_exists('wp_using_ext_object_cache')&&wp_using_ext_object_cache()&&function_exists('wp_cache_add')&&function_exists('wp_cache_incr')){return'persistent_object_cache';}return'external_waf_required';}
    private function consumeRateLimit():array{
        if('persistent_object_cache'!==$this->rateLimitMode()){return array('allowed'=>true,'count'=>0,'retry_after'=>0,'source'=>'external_waf_required');}
        $remote=isset($_SERVER['REMOTE_ADDR'])&&is_scalar($_SERVER['REMOTE_ADDR'])?trim((string)$_SERVER['REMOTE_ADDR']):'';if(''===$remote||false===filter_var($remote,FILTER_VALIDATE_IP)){return array('allowed'=>true,'count'=>0,'retry_after'=>0,'source'=>'remote_addr_unavailable');}
        $limit=self::RATE_LIMIT_REQUESTS;if(function_exists('apply_filters')){$limit=(int)apply_filters('etg_dfsb_ajax_rate_limit',$limit,self::RATE_LIMIT_WINDOW_SECONDS);}$limit=max(10,min(300,$limit));$window=self::RATE_LIMIT_WINDOW_SECONDS;$now=time();$bucket=(int)floor($now/$window);$salt=function_exists('wp_salt')?wp_salt('nonce'):'etg-dfsb-rate-limit';$key='etg_dfsb_rl_'.substr(hash_hmac('sha256',$remote.'|'.$bucket,$salt),0,32);$retry=max(1,$window-($now%$window));
        if(wp_cache_add($key,1,'etg-dfsb',$window)){return array('allowed'=>true,'count'=>1,'retry_after'=>$retry,'source'=>'object_cache');}$count=wp_cache_incr($key,1,'etg-dfsb');$count=false===$count?$limit+1:(int)$count;return array('allowed'=>$count<=$limit,'count'=>$count,'retry_after'=>$retry,'source'=>'object_cache');
    }

    private function jetSmartFiltersVersion():string{
        foreach(array('JET_SMART_FILTERS_VERSION','JET_SMART_FILTERS_VER')as$constant){if(defined($constant)){$value=trim((string)constant($constant));if(''!==$value){return$value;}}}
        if(defined('WP_PLUGIN_DIR')&&function_exists('get_file_data')){$file=rtrim((string)WP_PLUGIN_DIR,'/\\').'/jet-smart-filters/jet-smart-filters.php';if(is_file($file)){$data=get_file_data($file,array('Version'=>'Version'),'plugin');$version=is_array($data)?trim((string)($data['Version']??'')):'';if(''!==$version){return$version;}}}return'unknown';
    }

    private function tokenList($value,array$context):array{
        $allowed=array();$rejected=array();foreach(array_slice(is_array($value)?$value:array(),0,self::MAX_TOKENS)as$raw){$token=PresentationToken::normalize($raw);if(''===$token||!$this->tokenAllowed($token,$context)){if(''!==trim((string)$raw)){$rejected[]=trim((string)$raw);}continue;}$allowed[]=$token;}return array('allowed'=>array_values(array_unique($allowed)),'rejected'=>array_values(array_unique($rejected)));
    }

    private function tokenAllowed(string$token,array$context):bool{
        if(in_array($token,array('title','intro','keyword','result_count','result_summary','breadcrumb','gallery_ids','image_id','image_url'),true)){return true;}
        if(0===strpos($token,'context:')){return in_array(substr($token,8),array('language','profile_id','provider','query_id','query_builder_query_id','state_transport','ajax_only'),true);}
        if(0===strpos($token,'url:')){return in_array(substr($token,4),array('current','archive'),true);}
        $profile=(array)($context['profile']??array());$roles=array();foreach((array)($profile['taxonomy_rules']??array())as$taxonomy=>$rule){$role=sanitize_key((string)(is_array($rule)?($rule['role']??$taxonomy):$taxonomy));if(''!==$role){$roles[$role]=true;}}
        if(0===strpos($token,'term:')){$parts=explode(':',$token,3);return 3===count($parts)&&isset($roles[sanitize_key($parts[1])])&&in_array(sanitize_key($parts[2]),array('name','slug','description','short_description','seo_title','meta_description','focus_keyword','image_id','image_url','count','location_level'),true);}
        if(0===strpos($token,'terms:')){$parts=explode(':',$token,3);return 3===count($parts)&&isset($roles[sanitize_key($parts[1])])&&in_array(sanitize_key($parts[2]),array('ids','names','slugs','count','descriptions','short_descriptions','seo_titles','meta_descriptions','focus_keywords'),true);}
        if(0===strpos($token,'termmeta:')){$parts=explode(':',$token,3);return 3===count($parts)&&isset($roles[sanitize_key($parts[1])])&&$this->catalogContains($token);}
        if(0===strpos($token,'topology:')){$parts=explode(':',$token,3);$queryKey=QueryId::tokenKey($context['query_id']??'');return 3===count($parts)&&''!==$queryKey&&$parts[1]===$queryKey&&'query_builder_query_id'===sanitize_key($parts[2])&&$this->catalogContains($token);}
        return false;
    }

    private function catalogContains(string$token):bool{$this->loadCatalogTokenMeta();return isset($this->catalogTokens[$token]);}
    private function catalogTokenMeta(string$token):array{$this->loadCatalogTokenMeta();return isset($this->catalogTokenMeta[$token])?(array)$this->catalogTokenMeta[$token]:array();}
    private function loadCatalogTokenMeta():void{
        if(null!==$this->catalogTokens&&null!==$this->catalogTokenMeta){return;}$this->catalogTokens=array();$this->catalogTokenMeta=array();if(!$this->catalogProvider){return;}
        try{$catalog=call_user_func($this->catalogProvider);foreach((array)($catalog['tokens']??array())as$candidate=>$meta){$candidate=PresentationToken::normalize($candidate);if(''===$candidate){continue;}$this->catalogTokens[$candidate]=true;$this->catalogTokenMeta[$candidate]=is_array($meta)?$meta:array();}}catch(\Throwable$error){$this->catalogTokens=array();$this->catalogTokenMeta=array();}
    }
    private function slotList($value):array{$allowed=array();$rejected=array();foreach(array_slice(is_array($value)?$value:array(),0,self::MAX_SLOTS)as$id){$id=sanitize_key((string)$id);if(''===$id){continue;}$slot=$this->slots->get($id);if(!$slot||empty($slot['enabled'])){$rejected[]=$id;continue;}$allowed[]=$id;}return array('allowed'=>array_values(array_unique($allowed)),'rejected'=>array_values(array_unique($rejected)));}
    private function tokenType(string$token):string{$meta=$this->catalogTokenMeta($token);$catalogType=sanitize_key((string)($meta['type']??''));if(in_array($catalogType,array('text','html','url','image'),true)){return$catalogType;}if('intro'===$token||preg_match('/:(?:description|short_description|descriptions|short_descriptions)$/i',$token)){return'html';}if('image_url'===$token||0===strpos($token,'url:')||false!==stripos($token,':image_url')){return'url';}if('image_id'===$token||false!==stripos($token,':image_id')){return'image';}return'text';}
}
