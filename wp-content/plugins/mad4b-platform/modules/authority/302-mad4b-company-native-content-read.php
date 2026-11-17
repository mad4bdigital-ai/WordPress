<?php
/**
 * Plugin Name: MAD4B Native Company Content Read (Central Staging)
 * Description: Performs bounded live company WordPress REST reads through exact enrolled company origins.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Native_Content_Read_V1 {
 const CONTRACT='mad4b.company-native-content-read.v1';
 static function ready():bool {
  return class_exists('MAD4B_Company_Operation_Admission_V1')
   && class_exists('MAD4B_Manager_Auto_Write_V1')
   && MAD4B_Company_Operation_Admission_V1::actor();
 }
 static function read(string $company,string $kind='posts',int $per_page=3) {
  if(!self::ready())
   return new WP_Error('mad4b_company_read_admin_required',
     'Enrolled central Administrator authentication required',['status'=>403]);
  if(!in_array($kind,['posts','pages'],true)||$per_page<1||$per_page>10)
   return new WP_Error('mad4b_company_read_invalid_request','Invalid bounded read request',['status'=>422]);
  $admit=MAD4B_Company_Operation_Admission_V1::assess($company,'content.read');
  if(is_wp_error($admit) || empty($admit['policy_delegation_admitted']))
   return new WP_Error('mad4b_company_read_delegation_required',
       'Active manager content read delegation required',['status'=>403]);
  $target=MAD4B_Manager_Auto_Write_V1::company_target($company);
  if(($target['state']??'')!=='REGISTERED')
   return new WP_Error('mad4b_company_read_target_missing','Enrolled company target required',['status'=>409]);
  $url=(string)($target['canonical_origin']??'');
  $bits=wp_parse_url($url);
  if(!is_array($bits)||($bits['scheme']??'')!=='https'||
     !empty($bits['user'])||!empty($bits['pass'])||isset($bits['port'])||
     isset($bits['query'])||isset($bits['fragment'])||
     !in_array($bits['path']??'',['','/'],true))
   return new WP_Error('mad4b_company_read_https_origin_invalid',
     'Exact HTTPS company origin required',['status'=>403]);
  $path='/wp-json/wp/v2/'.$kind.'?context=view&_fields=id,date,slug,link,title,modified&per_page='.$per_page;
  $request=$url.$path;
  $response=wp_safe_remote_get($request,[
    'timeout'=>12,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>65536,
    'headers'=>['Accept'=>'application/json']]);
  if(is_wp_error($response))
   return new WP_Error('mad4b_company_read_transport_failed',
     $response->get_error_code(),['status'=>502]);
  $code=wp_remote_retrieve_response_code($response);
  $items=json_decode(wp_remote_retrieve_body($response),true);
  if($code!==200||!is_array($items)||!array_is_list($items))
   return new WP_Error('mad4b_company_read_provider_unavailable',
     'Company WordPress public read failed',['status'=>502]);
  $out=[];
  foreach($items as $post) {
   if(!is_array($post)||!is_numeric($post['id']??null))continue;
   $out[]=[
    'id'=>(int)$post['id'],'slug'=>(string)($post['slug']??''),
    'date'=>(string)($post['date']??''),'modified'=>(string)($post['modified']??''),
    'link'=>(string)($post['link']??''),
    'title'=>wp_strip_all_tags((string)($post['title']['rendered']??''))];
  }
  return [
   'contract'=>self::CONTRACT,'state'=>'LIVE_COMPANY_PUBLIC_READ',
   'company'=>$company,'target_environment'=>$target['environment'],
   'origin'=>$url,'company_site_uuid'=>$target['site_uuid'],
   'kind'=>$kind,'returned_count'=>count($out),
   'records'=>$out,'data_scope'=>'public_published_only',
   'private_company_content_read_certified'=>false,
   'principal_authorized_by_central_manager'=>true,
   'company_provider_authentication_claimed'=>false,
   'remote_write_authorized'=>false,'mutation_performed'=>false
  ];
 }
 static function rest():void {
  if(!MAD4B_Company_Operation_Admission_V1::central())return;
  register_rest_route('mad4b/v1','/company-native-read/(?P<company>[a-z][a-z0-9-]{2,47})',[
   'methods'=>'GET','permission_callback'=>[__CLASS__,'ready'],
   'callback'=>static function(WP_REST_Request $request) {
    $row=self::read((string)$request['company'],
       (string)($request->get_param('kind')?:'posts'),
       (int)($request->get_param('per_page')?:3));
    return is_wp_error($row)?$row:rest_ensure_response($row);
   }]);
 }
 static function boot():void { add_action('rest_api_init',[__CLASS__,'rest'],70); }
}
MAD4B_Company_Native_Content_Read_V1::boot();
