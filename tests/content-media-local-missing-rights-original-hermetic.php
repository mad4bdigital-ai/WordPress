<?php
/** Native Content Experience storage: existing local image != licensed external source. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$msg='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function absint($v){return max(0,(int)$v);}
function get_post_type($id){return in_array((int)$id,[1,2,3],true)?'attachment':false;}
function current_user_can($cap,$id=0){return $cap==='read_post' &&in_array((int)$id,[1,2,3],true);}
function wp_attachment_is_image($id){return in_array((int)$id,[1,2,3],true);}
function get_attached_file($id){return (int)$id===1 ? __FILE__ : false;}
function local_check($condition,$name){
 if(!$condition){fwrite(STDERR,"FAIL: $name\n");exit(1);}
 $GLOBALS['n']=($GLOBALS['n']??0)+1;
}
class MAD4B_SCP_Content_Experience_Profiles {const MAX_MEDIA_GALLERY_ITEMS=50;}
$base=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/';
require $base.'class-mad4b-scp-content-experience-media-storage.php';
require $base.'class-mad4b-scp-content-experience-media.php';
$c='MAD4B_SCP_Content_Experience_Media';
$usage=['kind'=>'image_gallery_usage','storage'=>'items','max_items'=>3,
 'references_field'=>'gallery','usage_fields'=>['license'],
 'licenses'=>['cc-by-4.0']];
$gallery=['kind'=>'image_gallery','storage'=>'ids','max_items'=>3];
$spec=['gallery'=>$gallery,'gallery_usage'=>$usage];
$local=$c::normalize_meta_value('gallery_usage',
 [['attachment_id'=>1,'license'=>'']],$usage);
local_check(!is_wp_error($local) && ($local[0]['license']??null)==='',
 'Local image missing license must remain blank, not become invented CC license');
$licensed=$c::normalize_meta_value('gallery_usage',
 [['attachment_id'=>1,'license'=>'cc-by-4.0']],$usage);
local_check(!is_wp_error($licensed) && ($licensed[0]['license']??null)==='cc-by-4.0',
 'Exact declared allowlisted license persists without alteration');
$bad=$c::normalize_meta_value('gallery_usage',
 [['attachment_id'=>1,'license'=>'unverified-external-rights']],$usage);
local_check(is_wp_error($bad)&&$bad->get_error_code()==='mad4b_content_experience_media_usage_license_denied',
 'Unknown nonblank license cannot be treated as missing local metadata');
$malformed=$c::normalize_meta_value('gallery_usage',
 [['attachment_id'=>1,'license'=>new WP_Error('unverified_rights')]],$usage);
local_check(is_wp_error($malformed)&&$malformed->get_error_code()==='mad4b_content_experience_media_usage_license_denied',
 'Error-shaped license values cannot crash or enter the allowed license catalog');
$missing=$c::normalize_meta_value('gallery_usage',
 [['attachment_id'=>2,'license'=>'']],$usage);
local_check(is_wp_error($missing)&&$missing->get_error_code()==='mad4b_content_experience_media_usage_license_denied',
 'Missing local image file never qualifies for the advisory exception');
$binding=$c::validate_usage_bindings($spec,['gallery'=>[1],'gallery_usage'=>[]]);
local_check($binding===true,
 'Entirely missing usage on real local gallery must not fabricate per-image licensing');
$binding=$c::validate_usage_bindings($spec,['gallery'=>[1,2],'gallery_usage'=>[]]);
local_check(is_wp_error($binding)&&$binding->get_error_code()==='mad4b_content_experience_media_usage_reference_drift',
 'Mixed gallery with nonexistent local image must retain drift rejection');
$binding=$c::validate_usage_bindings($spec,
 ['gallery'=>[1,2],'gallery_usage'=>[['attachment_id'=>1]]]);
local_check(is_wp_error($binding)&&$binding->get_error_code()==='mad4b_content_experience_media_usage_reference_drift',
 'Partially associated gallery cannot silently pass with missing rights rows');
$binding=$c::validate_usage_bindings($spec,
 ['gallery'=>[1],'gallery_usage'=>[['attachment_id'=>1]]]);
local_check($binding===true,
 'Exact one-to-one media identity remains valid');
$binding=$c::validate_usage_bindings($spec,['gallery_usage'=>[]]);
local_check(is_wp_error($binding)&&$binding->get_error_code()==='mad4b_content_experience_media_usage_reference_required',
 'Usage can never be decoupled from required referenced source gallery');
echo 'PASS: CONTENT_MEDIA_LOCAL_MISSING_RIGHTS_ORIGINAL '.($GLOBALS['n']??0).
 ' source-bound native missing-rights and gallery association cases'.PHP_EOL;
