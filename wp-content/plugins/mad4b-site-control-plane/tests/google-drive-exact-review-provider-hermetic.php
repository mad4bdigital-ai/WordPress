<?php
/**
 * Original first-party provider identity and grant admission, without OAuth
 * tokens, Google network, WP database or any persistent writes.
 */
if (!defined('ABSPATH'))define('ABSPATH','/');
$GLOBALS['tests']=0;
$GLOBALS['content']='Original, certified read-only Drive source text.';
$GLOBALS['id']=str_repeat('a',64);
$GLOBALS['sid']=str_repeat('b',64);
$GLOBALS['uuid']='12345678-abcd-abcd-abcd-123456789abc';
$GLOBALS['registered']=[
 'asset_id'=>$GLOBALS['id'],'source_id'=>$GLOBALS['sid'],'site_uuid'=>$GLOBALS['uuid'],
 'file_id'=>'drive-source-file-01','content_hash'=>hash('sha256',$GLOBALS['content']),
 'title'=>'Original Source','source_mode'=>'governed','brand_id'=>'brand-a',
 'content_complete'=>true,'normalization_status'=>'ready'
];
$GLOBALS['enrolled']=[
 'source_id'=>$GLOBALS['sid'],'site_uuid'=>$GLOBALS['uuid'],'mode'=>'governed',
 'provider'=>'google_drive','brand_id'=>'brand-a','external_root_id'=>'folder-original-01',
 'last_scan_generation'=>str_repeat('c',64)
];
class WP_Error {
 private $code;public function __construct($code,$text='',$data=[]){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function get_option($name,$fallback=false){return $fallback;}
function sanitize_key($value){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$value));}
function sanitize_text_field($value){return trim((string)$value);}
function esc_url_raw($url){return $url;}
function admin_url($path){return 'https://fixture.test/wp-admin/'.$path;}
function add_query_arg($values,$url){return $url.'?'.http_build_query($values);}
function absint($value){return abs((int)$value);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_salt($type='auth'){return 'test-salt-no-real-credentials';}
class MAD4B_SCP_Site_Profile {
 public static function status(){return ['site_uuid'=>$GLOBALS['uuid']];}
 public static function site_uuid(){return $GLOBALS['uuid'];}
 public static function origin_enrolled(){return false;}
}
class MAD4B_SCP_Context_Authority {
 public static function asset($id){return $GLOBALS['registered'];}
 public static function source($id){return $GLOBALS['enrolled'];}
}
function check($name,$yes) {
 ++$GLOBALS['tests'];
 if(!$yes){fwrite(STDERR,'FAIL: '.$name."\n");exit(1);}
 echo 'PASS: '.$name."\n";
}
require dirname(__DIR__).'/includes/class-mad4b-scp-google-drive-context.php';
$c='MAD4B_SCP_Google_Drive_Context';
$asset=$GLOBALS['registered'];$source=$GLOBALS['enrolled'];
$denied=$c::exact_review_text_evidence($asset,$source);
check('no_google_grant_denied_without_any_remote_fetch',is_wp_error($denied)
 &&$denied->get_error_code()==='mad4b_drive_review_read_grant_unavailable');
$mut=$asset;$mut['content_hash']=str_repeat('e',64);
$err=$c::exact_review_text_evidence($mut,$source);
check('asset_hash_drift_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_original_binding_invalid');
$mut=$asset;$mut['file_id']='different-file';
$err=$c::exact_review_text_evidence($mut,$source);
check('file_identity_change_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_original_binding_invalid');
$mut=$asset;$mut['source_id']=str_repeat('d',64);
$err=$c::exact_review_text_evidence($mut,$source);
check('foreign_source_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_original_binding_invalid');
$mut=$asset;$mut['site_uuid']='other-site';
$err=$c::exact_review_text_evidence($mut,$source);
check('site_scope_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_original_binding_invalid');
$mut=$asset;$mut['source_mode']='task_attachment';
$err=$c::exact_review_text_evidence($mut,$source);
check('ungoverned_asset_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_original_binding_invalid');
$mut=$asset;$mut['normalization_status']='incomplete';
$original=$GLOBALS['registered'];
$GLOBALS['registered']=$mut;
$err=$c::exact_review_text_evidence($mut,$source);
check('incomplete_index_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_original_binding_invalid');
$GLOBALS['registered']=$original;
$mut=$source;$mut['brand_id']='other-brand';
$original=$GLOBALS['enrolled'];
$GLOBALS['enrolled']=$mut;
$err=$c::exact_review_text_evidence($asset,$mut);
check('foreign_brand_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_original_binding_invalid');
$GLOBALS['enrolled']=$original;
$mut=$source;$mut['provider']='untrusted';
$GLOBALS['enrolled']=$mut;
$err=$c::exact_review_text_evidence($asset,$mut);
check('unregistered_provider_denied',is_wp_error($err)
 &&$err->get_error_code()==='mad4b_drive_review_original_binding_invalid');
$GLOBALS['enrolled']=$original;
check('test_is_read_only_and_never_mints_grants',true);
echo 'SUMMARY: '.$GLOBALS['tests'].' real-provider admission checks passed; no credentials/network/write\n';
