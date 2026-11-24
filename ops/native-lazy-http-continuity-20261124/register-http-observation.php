<?php
if(!defined('WP_CLI')||!WP_CLI)exit(9);
$file='/tmp/mad4b-http-family-observation-result.json';
$x=json_decode((string)file_get_contents($file),true);
$c='MAD4B_Native_Layout_Epoch_Continuity_V1';
$host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
if(!is_array($x)||($x['host']??'')!==$host||($x['status']??'')!=='pass_functional'||
 ($x['source_stable']??false)!==true||($x['native_certificate_written']??true)!==false||!class_exists($c))throw new RuntimeException('Not a successful source-stable HTTP observation');
$a=$x['source_before']??[];$b=$x['source_after']??[];$http=$x['http']??[];
if(!is_array($a)||!is_array($b)||!is_array($http)||($http['contract']??'')!=='mad4b.native-listing.public-ssr-http-parity.v1'||
 ($http['site']??'')!==$host||($http['signed_request_accepted']??false)!==true||($http['ordered_semantics_match']??false)!==true||
 ($http['market_attested']??true)!==false||($http['visual_measurement']??true)!==false||
 ($http['native_certificate_written']??true)!==false||(int)($http['ssr_count']??0)<1||
 (int)($http['ssr_count']??0)!==(int)($http['ajax_count']??-1)||
 !preg_match('/^[a-f0-9]{64}$/D',(string)($http['ssr_sha256']??''))||
 ($http['ssr_sha256']??null)!==($http['ajax_sha256']??null))throw new RuntimeException('Invalid HTTP evidence');
foreach(['source_sha256','query_generation','global_data_revision','css_epoch','cli_market','locale','host'] as $k)
 if(!array_key_exists($k,$a)||!array_key_exists($k,$b)||$a[$k]!==$b[$k])throw new RuntimeException('Source changed');
$source=$b['source']??[];
if(!is_array($source)||($source['site_host']??'')!==$host||($source['locale']??'')!==($x['locale']??'')||
 (int)($source['listing_id']??0)!==(int)$x['listing_id']||(int)($source['document_id']??0)!==(int)$x['document_id']||
 (int)($http['query']??0)!==(int)$x['query_id']||(int)($http['listing']??0)!==(int)$x['listing_id']||
 (int)($http['document']??0)!==(int)$x['document_id']||($http['locale']??'')!==$x['locale'])
 throw new RuntimeException('Invalid family relation');
$at=(int)($x['observed_at']??0);if($at<time()-900||$at>time()+120)throw new RuntimeException('Stale observation');
$old=(string)apply_filters('wpml_current_language','');
try{
 do_action('wpml_switch_language',$x['locale']);
 if((string)apply_filters('wpml_current_language','')!==$x['locale'])throw new RuntimeException('Locale context moved');
 $now=$c::family_source($source);
 if(!$now||hash('sha256',serialize($c::canonical_source($now)))!==$b['source_sha256']||
  (int)get_option('mad4b_css_ai_epoch_v1',0)!==$b['css_epoch']||
  (string)get_option('mad4b_render_query_data_generation','initial')!==$b['global_data_revision']||
  (function_exists('mad4b_render_query_generation_for_id')?mad4b_render_query_generation_for_id((int)$x['query_id']):null)!==$b['query_generation'])
 throw new RuntimeException('Live source moved after HTTP');
 $key='mad4b_layout_http_observation_v1_'.substr(hash('sha256',$host.'|'.$x['locale'].'|'.$x['listing_id'].'|'.$x['document_id'].'|'.$x['query_id'].'|'.$source['page_id']),0,40);
 $receipt=['contract'=>'mad4b.native-listing.functional-observation.v1','authority'=>'functional_only_no_native_qualification',
 'site_host'=>$host,'source'=>$now,'source_sha256'=>$b['source_sha256'],'query_id'=>(int)$x['query_id'],
 'query_generation'=>$b['query_generation'],'global_data_revision'=>$b['global_data_revision'],'css_epoch'=>$b['css_epoch'],
 'cli_market'=>$b['cli_market'],'market_attested'=>false,'visual_dependency_complete'=>false,
 'http'=>$http,'observed_at'=>$at,'native_certificate_written'=>false];
 $prior=get_option($key,[]);
 if((int)($prior['observed_at']??0)>$at)throw new RuntimeException('Newer observation already registered');
 update_option($key,$receipt,false);
 if(get_option($key,[])!==$receipt)throw new RuntimeException('Observation readback failed');
 $integration=['decision'=>'layout_http_integration_unavailable'];
 if(method_exists($c,'http_requalification_status')){
  $cohort=array_merge($source,['query_id'=>(int)$x['query_id']]);
  $integration=$c::http_requalification_status($cohort);
  if(($integration['decision']??'')==='original_continuity_eligible'){
   // Original CAS renewal and original native query gate stay authoritative.
   $c::renew(false);
   $integration=$c::http_requalification_status($cohort);
  }
 }
 echo wp_json_encode(['registered'=>true,'option'=>$key,'locale'=>$x['locale'],'source_sha256'=>$b['source_sha256'],'native_certificate_written'=>false,'original_continuity'=>$integration])."\n";
}finally{if($old!=='')do_action('wpml_switch_language',$old);}
