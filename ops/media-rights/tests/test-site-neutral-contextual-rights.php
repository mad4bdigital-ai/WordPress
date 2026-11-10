<?php
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WPCLI_REQUIRED');
require '/tmp/contextual-rights-candidate.php';
$c='MAD4B_SCP_Content_Experience_Media_Rights_Candidate';
$fields=['images'=>['kind'=>'image_gallery','storage'=>'ids','publish_rights_policy'=>'require_valid'],'usage'=>['kind'=>'image_usage','references_field'=>'images','publish_rights_policy'=>'require_valid','expiry_required_licenses'=>['permission']]];
$meta=['images'=>[69613],'usage'=>[]];
$a=$c::publish_guard($fields,$meta,'2026-11-10');
if(is_wp_error($a)||($a['license_mode']??null)!=='advisory'||($a['license_warning_count']??0)<1)throw new RuntimeException('CONTEXT_DEFAULT_ADVISORY_FAIL');
$filter=static fn()=> 'enforced';add_filter('mad4b_remote_media_rights_mode',$filter,10,0);
try{$b=$c::publish_guard($fields,$meta,'2026-11-10');}finally{remove_filter('mad4b_remote_media_rights_mode',$filter,10);}
if(!is_wp_error($b)||$b->get_error_code()!=='mad4b_content_experience_media_rights_usage_required')throw new RuntimeException('CONTEXT_STRICT_NOT_ENFORCED');
$meta['usage']=[['attachment_id'=>69613,'license'=>'permission','license_expires_on'=>'2025-01-01']];
$e=$c::publish_guard($fields,$meta,'2026-11-10');
if(!is_wp_error($e)||$e->get_error_code()!=='mad4b_content_experience_media_rights_expired')throw new RuntimeException('EXPIRED_CONTEXT_LICENSE_NOT_BLOCKED');
WP_CLI::line(wp_json_encode(['mode'=>$a['license_mode'],'warning_count'=>$a['license_warning_count'],'enforced_error'=>$b->get_error_code(),'expiry_error'=>$e->get_error_code(),'status'=>'PASS']));
