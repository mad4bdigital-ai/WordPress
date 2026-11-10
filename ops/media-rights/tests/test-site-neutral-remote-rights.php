<?php
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WPCLI_REQUIRED');
require '/tmp/remote-media-rights-candidate.php';
$c='MAD4B_SCP_Remote_Media_Rights_Candidate';
// Simulate publish rights state with real methods but reversible test-only metadata.
$id=69551;$file=get_attached_file($id);
if (!is_file((string)$file)) throw new RuntimeException('FIXTURE_MISSING');
$source='https://example.org/image-source.jpg';$content=hash_file('sha256',$file);$source_hash=hash('sha256',$source);
$meta=[
 '_mad4b_remote_media_source_sha256'=>$source_hash,
 '_mad4b_remote_media_content_sha256'=>$content,
 '_mad4b_remote_media_provenance'=>['contract'=>MAD4B_SCP_Remote_Media_Adapter::REMOTE_PROVENANCE_CONTRACT,'content_sha256'=>$content,'source_url_sha256'=>$source_hash,'rights_basis'=>'unknown','rights_note'=>'','rights_reference'=>'','license_expires_on'=>'']
];
$wpdb=$GLOBALS['wpdb'];$original=$wpdb->get_results($wpdb->prepare('SELECT meta_key,meta_value FROM '.$wpdb->postmeta.' WHERE post_id=%d AND meta_key IN (%s,%s,%s)',$id,...array_keys($meta)),ARRAY_A);
if ($original) throw new RuntimeException('TEST_FIXTURE_HAS_ORIGINAL_GOVERNED_METADATA');
$wpdb->query('START TRANSACTION');
try {
 foreach($meta as $key=>$value){if(!$wpdb->insert($wpdb->postmeta,['post_id'=>$id,'meta_key'=>$key,'meta_value'=>maybe_serialize($value)]))throw new RuntimeException('TEMP_META_FAILED');}
 wp_cache_delete($id,'post_meta');
 $default=$c::publish_guard($id,[],[],'2026-11-10');
 if(is_wp_error($default)||($default['license_mode']??'')!=='advisory'||($default['license_warning_count']??-1)!==2)throw new RuntimeException('DEFAULT_ADVISORY_FAILURE:'.wp_json_encode($default));
 $filter=static fn()=> 'enforced';add_filter('pre_option_mad4b_remote_media_rights_mode',$filter,10,0);
 try {$strict=$c::publish_guard($id,[],[],'2026-11-10');} finally {remove_filter('pre_option_mad4b_remote_media_rights_mode',$filter,10);}
 if(!is_wp_error($strict)||$strict->get_error_code()!=='mad4b_remote_media_publish_rights_unproven')throw new RuntimeException('STRICT_MODE_NOT_BLOCKING');
 $meta['_mad4b_remote_media_provenance']['license_expires_on']='2026-01-01';
 $wpdb->update($wpdb->postmeta,['meta_value'=>maybe_serialize($meta['_mad4b_remote_media_provenance'])],['post_id'=>$id,'meta_key'=>'_mad4b_remote_media_provenance']);wp_cache_delete($id,'post_meta');
 $expired=$c::publish_guard($id,[],[],'2026-11-10');
 if(!is_wp_error($expired)||$expired->get_error_code()!=='mad4b_remote_media_publish_rights_expired')throw new RuntimeException('EXPIRED_LICENSE_NOT_BLOCKED');
 WP_CLI::line(wp_json_encode(['default_mode'=>$default['license_mode'],'default_warnings'=>$default['license_warning_count'],'strict_code'=>$strict->get_error_code(),'expired_code'=>$expired->get_error_code(),'state'=>'PASS']));
} finally {$wpdb->query('ROLLBACK');wp_cache_delete($id,'post_meta');}
