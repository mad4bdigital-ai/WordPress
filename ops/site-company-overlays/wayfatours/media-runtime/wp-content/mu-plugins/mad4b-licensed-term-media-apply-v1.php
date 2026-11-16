<?php
/**
 * Plugin Name: MAD4B Licensed Term Media Apply
 * Description: Scoped media write contract for licensed taxonomy assets; no frontend runtime work.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Licensed_Term_Media_Apply_V1',false)) {
final class MAD4B_Licensed_Term_Media_Apply_V1 {
 const CONTRACT='mad4b.licensed-term-media-apply.v1';
 const ALLOWED_SOURCE='/tmp/mad4b-licensed-media-queue/';
 const CREDIT_OPTION='mad4b_licensed_media_credits_v1';
 private static function fail(string $code):WP_Error{return new WP_Error($code,$code);}
 public static function validate(array $request):array|WP_Error {
  $expected=['contract','site_uuid','taxonomy','term_id','expected_featured_image','sha256','local_filename','source_url','author','license_url','license_label','subject','approval_reference'];
  $keys=array_keys($request);sort($keys);sort($expected);
  if($keys!==$expected||($request['contract']??'')!==self::CONTRACT)return self::fail('licensed_term_media_schema_invalid');
  $status=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
  if(!is_array($status)||!hash_equals((string)($status['site_uuid']??''),(string)$request['site_uuid']))return self::fail('licensed_term_media_site_identity_mismatch');
  $taxonomy=(string)$request['taxonomy'];$id=$request['term_id'];
  if(!is_int($id)||$id<1||!taxonomy_exists($taxonomy)||!is_taxonomy_viewable($taxonomy))return self::fail('licensed_term_media_term_not_public');
  $term=get_term($id,$taxonomy);
  if(!$term instanceof WP_Term)return self::fail('licensed_term_media_term_not_found');
  if((string)get_term_meta($id,'featured_image',true)!==(string)$request['expected_featured_image'])return self::fail('licensed_term_media_preimage_drift');
  $sha=(string)$request['sha256'];$filename=(string)$request['local_filename'];
  if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||!preg_match('/^[a-f0-9]{64}\\.jpe?g$/D',$filename)||!str_starts_with($filename,$sha))return self::fail('licensed_term_media_file_identity_invalid');
  $root=realpath(self::ALLOWED_SOURCE);$file=$root?realpath($root.'/'.$filename):false;
  if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file)||!is_readable($file)||filesize($file)>15000000||!hash_equals($sha,hash_file('sha256',$file)))return self::fail('licensed_term_media_checksum_invalid');
  $info=@getimagesize($file);
  if(!is_array($info)||($info[2]??null)!==IMAGETYPE_JPEG||($info[0]??0)<640||($info[1]??0)<360)return self::fail('licensed_term_media_image_invalid');
  foreach(['source_url','license_url'] as $k){
   $u=wp_parse_url((string)$request[$k]);
   if(!is_array($u)||($u['scheme']??'')!=='https'||!empty($u['user'])||!empty($u['pass'])||!in_array((string)($u['host']??''),['commons.wikimedia.org','creativecommons.org'],true))return self::fail('licensed_term_media_provenance_url_invalid');
  }
  if((string)wp_parse_url($request['source_url'],PHP_URL_HOST)!=='commons.wikimedia.org'||(string)wp_parse_url($request['license_url'],PHP_URL_HOST)!=='creativecommons.org')return self::fail('licensed_term_media_provenance_mismatch');
  foreach(['author','license_label','subject','approval_reference'] as $key){if(!is_string($request[$key])||mb_strlen(trim($request[$key]))<4||mb_strlen($request[$key])>240)return self::fail('licensed_term_media_provenance_required');}
  if(!preg_match('/^review:[a-zA-Z0-9_.:-]{10,160}$/D',$request['approval_reference']))return self::fail('licensed_term_media_approval_reference_invalid');
  return ['state'=>'asset_preflight_valid_approval_unverified','site_uuid'=>$request['site_uuid'],'taxonomy'=>$taxonomy,'term_id'=>$id,
   'original_image'=>(string)$request['expected_featured_image'],'sha256'=>$sha,'image_size'=>[$info[0],$info[1]],
   'source_file'=>$file,'license_label'=>$request['license_label'],'review_reference'=>$request['approval_reference'],
   'write_executed'=>false,'human_approval_verified'=>false,'execution_requires_signed_host_operation'=>true];
 }
 public static function execute(array $request):array|WP_Error {
  if(!class_exists('MAD4B_SCP_Host_Agent_Bridge',false)||!MAD4B_SCP_Host_Agent_Bridge::active())return self::fail('licensed_term_media_signed_host_required');
  $v=self::validate($request);if(is_wp_error($v))return $v;
  // This method MUST NOT be exposed through generic REST, an unauthenticated
  // AJAX handler, or a user-controllable WP-CLI entrypoint.
  // A future host operation requires verified human review and a signed,
  // site-bound envelope; the existing host bridge does not dispatch it yet.
  return self::fail('licensed_term_media_signed_operation_not_registered');
 }
 public static function cli($args,$assoc):void {
  if(count($args)!==1||$args[0]!=='preflight'||$assoc)WP_CLI::error('Only explicit read-only preflight supported');
  $input=stream_get_contents(STDIN,12289);$req=json_decode((string)$input,true);
  if(!is_array($req))WP_CLI::error('Invalid manifest');
  $res=self::validate($req);
  if(is_wp_error($res))WP_CLI::error($res->get_error_code());
  WP_CLI::line(wp_json_encode($res,JSON_UNESCAPED_SLASHES));
 }
}
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b licensed-term-media',[MAD4B_Licensed_Term_Media_Apply_V1::class,'cli']);
}
