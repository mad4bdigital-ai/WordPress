<?php
/* Disposable zero-network WordPress API fixture: resumable transfer, hash and attachment readback. */
$fakeRoot = sys_get_temp_dir() . '/mad4b-transfer-fixture-' . bin2hex(random_bytes(8));
mkdir($fakeRoot, 0700);
mkdir($fakeRoot . '/wp-admin', 0700);
mkdir($fakeRoot . '/wp-admin/includes', 0700, true);
foreach (array('file.php','media.php','image.php') as $name) file_put_contents($fakeRoot . '/wp-admin/includes/' . $name, "<?php\n");
define('ABSPATH', $fakeRoot . '/');
$GLOBALS['transients'] = array();
$GLOBALS['last_file'] = null;
$GLOBALS['last_hash'] = null;
$GLOBALS['last_id'] = 0;
function is_user_logged_in() {return true;}
function current_user_can($cap) {return in_array($cap, array('upload_files','mad4b_attachment_transfer'), true);}
function get_current_user_id() {return 81;}
function sanitize_key($s) {return $s;}
function home_url($x = '/') {return 'https://staging.example.test/';}
function sanitize_file_name($s) {return basename($s);}
function sanitize_text_field($s) {return $s;}
function get_allowed_mime_types() {return array('txt'=>'text/plain');}
function wp_check_filetype($name) {return array('type'=>'text/plain');}
function wp_check_filetype_and_ext($path,$name) {return array('type'=>'text/plain','ext'=>'txt');}
function get_transient($k) {return isset($GLOBALS['transients'][$k]) ? $GLOBALS['transients'][$k] : false;}
function set_transient($k,$v,$ttl) {$GLOBALS['transients'][$k]=$v;return true;}
function delete_transient($k) {unset($GLOBALS['transients'][$k]);return true;}
function wp_handle_sideload(&$file, $override) {
  if (!is_file($file['tmp_name'])) return array('error'=>'missing');
  $GLOBALS['last_file']=$file['tmp_name'];
  $GLOBALS['last_hash']=hash_file('sha256',$file['tmp_name']);
  return array('file'=>$file['tmp_name'],'type'=>'text/plain','url'=>'https://staging.example.test/test.txt');
}
function wp_insert_attachment($post,$file,$parent,$returnError=false) {$GLOBALS['last_id']=2026;return 2026;}
function wp_generate_attachment_metadata($id,$file) {return array();}
function wp_update_attachment_metadata($id,$meta) {return true;}
function update_post_meta($id,$key,$value) {return true;}
function wp_get_attachment_url($id) {return 'https://staging.example.test/test.txt';}
function is_wp_error($v) {return $v instanceof WP_Error;}
class WP_Error {private $code;function __construct($code,$message='',$data=null){$this->code=$code;}function get_error_code(){return $this->code;}}
class WP_REST_Request {
 private $params=array();
 function __construct($method='POST',$path=''){}
 function set_param($name,$value){$this->params[$name]=$value;}
 function get_param($name){return isset($this->params[$name])?$this->params[$name]:null;}
}
class MAD4B_SCP_Policy {
 static function can_read(){return true;}
 static function can_connect_user($id){return $id===81;}
 static function mutation_gate_status(){return array('effective'=>true,'explicit_kill_switch'=>false);}
}
class MAD4B_SCP_Site_Profile {
 static function configured(){return true;}
 static function current_environment(){return 'staging';}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-chatgpt-file-transfer.php';
function attempt($action,$params) {
 $request=new WP_REST_Request();foreach($params as $key=>$value)$request->set_param($key,$value);
 return call_user_func(array('MAD4B_SCP_ChatGPT_File_Transfer',$action),$request);
}
function check_ok($ok,$label){if(!$ok){fwrite(STDERR,"TRANSFER_ROUNDTRIP_FAIL:$label\n");exit(1);}}
try {
 $data="ChatGPT transfer fixture bytes for WordPress.\n";
 $sha=hash('sha256',$data);
 $start=attempt('begin',array('filename'=>'fixture.txt','size'=>strlen($data),'sha256'=>$sha));
 check_ok(!is_wp_error($start) && strlen($start['transfer_id'])===32,'begin');
 $id=$start['transfer_id'];
 $bad=attempt('chunk',array('transfer_id'=>$id,'offset'=>1,'data_base64'=>base64_encode($data)));
 check_ok(is_wp_error($bad)&&$bad->get_error_code()==='mad4b_transfer_offset','wrong_offset_rejected');
 $status=attempt('status',array('transfer_id'=>$id));
 check_ok($status['next_offset']===0,'resume_before_chunk');
 $part=substr($data,0,15);
 $written=attempt('chunk',array('transfer_id'=>$id,'offset'=>0,'data_base64'=>base64_encode($part)));
 check_ok(!is_wp_error($written)&&$written['next_offset']===15,'chunk1');
 $duplicate=attempt('chunk',array('transfer_id'=>$id,'offset'=>0,'data_base64'=>base64_encode($part)));
 check_ok(is_wp_error($duplicate),'no_replay');
 $second=substr($data,15);
 $written=attempt('chunk',array('transfer_id'=>$id,'offset'=>15,'data_base64'=>base64_encode($second)));
 check_ok(!is_wp_error($written)&&$written['next_offset']===strlen($data),'resume_chunk');
 $done=attempt('finish',array('transfer_id'=>$id));
 check_ok(!is_wp_error($done)&&$done['verified']===true&&$done['attachment_id']===2026,'complete');
 check_ok($GLOBALS['last_hash']===$sha&&$done['sha256']===$sha,'verified_bytes');
 $badStart=attempt('begin',array('filename'=>'bad.txt','size'=>strlen($data),'sha256'=>str_repeat('0',64)));
 check_ok(!is_wp_error($badStart),'bad_hash_begin');
 $id2=$badStart['transfer_id'];
 attempt('chunk',array('transfer_id'=>$id2,'offset'=>0,'data_base64'=>base64_encode($data)));
 $mismatch=attempt('finish',array('transfer_id'=>$id2));
 check_ok(is_wp_error($mismatch)&&$mismatch->get_error_code()==='mad4b_transfer_integrity','hash_mismatch_denied');
 attempt('abort',array('transfer_id'=>$id2));
 echo "CHATGPT_TRANSFER_ROUNDTRIP: PASS\n";
} finally {
 foreach (glob(sys_get_temp_dir().'/mad4b-chatgpt-transfer-v1/*.part') ?: array() as $p) {
   if (is_file($p) && preg_match('/^[a-f0-9]{32}\\.part$/',basename($p))) @unlink($p);
 }
 foreach (array('file.php','media.php','image.php') as $name) @unlink($fakeRoot.'/wp-admin/includes/'.$name);
 @rmdir($fakeRoot.'/wp-admin/includes'); @rmdir($fakeRoot.'/wp-admin'); @rmdir($fakeRoot);
}
