<?php
if (!defined('ABSPATH')) exit;
/**
 * Validated SERP evidence intake, not a crawler. Allows independently collected
 * provider observations to be scored without arbitrary outbound requests.
 */
final class MAD4B_Keyword_SERP {
 const CONTRACT='mad4b.keyword-serp-evidence.v1';
 public static function analyze($input=array()){
  if(!MAD4B_Google_Measurement_Bridge::gate())return new WP_Error('keyword_serp_forbidden','Owner permission required.');
  $keyword=trim((string)($input['keyword']??''));
  $provider=trim((string)($input['provider']??''));
  $locale=(string)($input['locale']??'');
  $observed=(string)($input['observed_at']??'');
  $entries=$input['results']??array();
  if(strlen($keyword)<2||strlen($keyword)>160||!preg_match('/^[a-z0-9_-]{2,45}$/D',$provider)||
     !preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$locale)||
     !preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$observed)||
     !is_array($entries)||count($entries)<1||count($entries)>20)
   return new WP_Error('keyword_serp_bad_input','Bounded SERP evidence with provider, date, locale and results required.');
  $rows=array();$hosts=array();$intents=array('informational'=>0,'commercial'=>0,'transactional'=>0,'navigational'=>0,'unknown'=>0);
  foreach($entries as $result){
   if(!is_array($result))return new WP_Error('keyword_serp_bad_result','Invalid result row.');
   $url=(string)($result['url']??'');$title=trim((string)($result['title']??''));
   $rank=(int)($result['rank']??0);
   if(!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('~^https://~i',$url)||
      strlen($url)>500||parse_url($url,PHP_URL_USER)!==null||
      parse_url($url,PHP_URL_PASS)!==null||strlen($title)>240||$rank<1||$rank>100)
    return new WP_Error('keyword_serp_bad_result','Invalid SERP URL, title or rank.');
   $host=strtolower((string)parse_url($url,PHP_URL_HOST));
   if($host===''||filter_var($host,FILTER_VALIDATE_IP))return new WP_Error('keyword_serp_bad_host','Public domain required.');
   $intent=(string)($result['intent']??'unknown');
   if(!isset($intents[$intent]))return new WP_Error('keyword_serp_intent','Invalid intent label.');
   ++$intents[$intent];$hosts[$host]=($hosts[$host]??0)+1;
   $rows[]=array('rank'=>$rank,'url'=>$url,'title'=>$title,'host'=>$host,'intent'=>$intent);
  }
  usort($rows,static function($a,$b){return $a['rank']<=>$b['rank'];});
  $count=count($rows);arsort($intents);$dominant=(string)key($intents);
  $evidence=array('keyword'=>$keyword,'provider'=>$provider,'locale'=>$locale,
   'observed_at'=>$observed,'results'=>$rows);
  return array('contract'=>self::CONTRACT,'evidence_sha256'=>hash('sha256',wp_json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
    'result_count'=>$count,'unique_domains'=>count($hosts),'dominant_intent'=>$dominant,
    'intent_counts'=>$intents,'results'=>$rows,'competitor_domains'=>array_keys($hosts),
    'retrieved_by_bridge'=>false,'external_source_unverified'=>true,
    'recommendation'=>'Verify SERP provider provenance and query context before content generation.',
    'mutation_performed'=>false);
 }
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  $n='mad4b/keyword-serp-evidence-analyze';
  if(function_exists('wp_has_ability')&&wp_has_ability($n))return;
  wp_register_ability($n,array('label'=>'SERP Evidence Review',
   'description'=>'Validate source-attributed SERP observations and derive intent and competitors; no scraping.',
   'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'analyze'),
   'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,
    'required'=>array('keyword','provider','locale','observed_at','results'),
    'properties'=>array('keyword'=>array('type'=>'string','maxLength'=>160),
     'provider'=>array('type'=>'string','maxLength'=>45),
     'locale'=>array('type'=>'string','maxLength'=>5),
     'observed_at'=>array('type'=>'string','maxLength'=>10),
     'results'=>array('type'=>'array','minItems'=>1,'maxItems'=>20,
      'items'=>array('type'=>'object','additionalProperties'=>false,
       'required'=>array('rank','url','title'),
       'properties'=>array('rank'=>array('type'=>'integer','minimum'=>1,'maximum'=>100),
       'url'=>array('type'=>'string','maxLength'=>500),'title'=>array('type'=>'string','maxLength'=>240),
       'intent'=>array('type'=>'string','enum'=>array('informational','commercial','transactional','navigational','unknown'))))))),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
   'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
 }
}
