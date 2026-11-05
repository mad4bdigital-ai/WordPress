<?php
define('ABSPATH','/tmp/');
class WP_Error { private $c; public function __construct($c,$m=''){$this->c=$c;} public function get_error_code(){return $this->c;} }
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$x){}
function sanitize_text_field($v){return (string)$v;}
function sanitize_key($v){return (string)$v;}
function get_post($id){return (int)$id===45?(object)['ID'=>45,'post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Demo Brief']:null;}
function get_post_mime_type($id){return 'text/plain';}
function get_post_meta($id,$key,$single=true){return '2026/11/brief.txt';}
function get_post_modified_time($fmt,$gmt,$post){return '2026-11-05T01:00:00+00:00';}
function wp_get_upload_dir(){return ['basedir'=>sys_get_temp_dir().'/mad4b-media-fixture'];}
function get_attached_file($id){return wp_get_upload_dir()['basedir'].'/2026/11/brief.txt';}
function wp_check_invalid_utf8($text,$strip=false){return preg_match('//u',$text)?$text:'';}
function hash_input($x){return hash('sha256',$x);}
class MAD4B_SCP_Context_Authority {
 public static $asset=[],$source=[];
 public static function asset($id){return self::$asset;}
 public static function source($id){return self::$source;}
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-wordpress-media-context.php';
require dirname(__DIR__) . '/includes/class-mad4b-scp-context-provider-gateway.php';
$root=wp_get_upload_dir()['basedir'];@mkdir($root.'/2026/11',0775,true);
$path=get_attached_file(45);file_put_contents($path,'Staging brand source readback test.');$bytes=file_get_contents($path);
$source_id=str_repeat('a',64);
MAD4B_SCP_Context_Authority::$source=[
 'source_id'=>$source_id,'provider'=>'wordpress_media','site_uuid'=>'site-fixture','brand_id'=>'brand-fixture'
];
MAD4B_SCP_Context_Authority::$asset=[
 'asset_id'=>hash('sha256',$source_id.'|45'),'source_id'=>$source_id,'file_id'=>'45',
 'provider'=>'wordpress_media','site_uuid'=>'site-fixture','brand_id'=>'brand-fixture',
 'mime_type'=>'text/plain','content_hash'=>hash('sha256',$bytes),
 'version'=>'2026-11-05T01:00:00+00:00','status'=>'ready','content_complete'=>true
];
$checks=[];
$r=MAD4B_SCP_Context_Provider_Gateway::read_context_asset('fixture');
$checks['successful_text_read']=is_array($r)&&$r['content']===$bytes&&$r['content_sha256']===hash('sha256',$bytes);
$checks['read_only_capabilities']=empty(MAD4B_SCP_Context_Provider_Gateway::capabilities()['providers']['wordpress_media']['create_asset']);
MAD4B_SCP_Context_Authority::$asset['content_complete']=false;
$checks['incomplete_refused']=is_wp_error(MAD4B_SCP_Context_Provider_Gateway::read_context_asset('fixture'));
MAD4B_SCP_Context_Authority::$asset['content_complete']=true;
MAD4B_SCP_Context_Authority::$asset['brand_id']='other-brand';
$checks['wrong_brand_refused']=is_wp_error(MAD4B_SCP_Context_Provider_Gateway::read_context_asset('fixture'));
MAD4B_SCP_Context_Authority::$asset['brand_id']='brand-fixture';
file_put_contents($path,'content changed');
$err=MAD4B_SCP_Context_Provider_Gateway::read_context_asset('fixture');
$checks['raw_hash_drift_refused']=is_wp_error($err)&&$err->get_error_code()==='mad4b_media_context_hash_drift';
file_put_contents($path,$bytes);
MAD4B_SCP_Context_Authority::$asset['version']='stale';
$checks['version_drift_refused']=is_wp_error(MAD4B_SCP_Context_Provider_Gateway::read_context_asset('fixture'));
MAD4B_SCP_Context_Authority::$asset['version']='2026-11-05T01:00:00+00:00';
MAD4B_SCP_Context_Authority::$asset['file_id']='../../../etc/passwd';
$checks['malformed_id_refused']=is_wp_error(MAD4B_SCP_Context_Provider_Gateway::read_context_asset('fixture'));
MAD4B_SCP_Context_Authority::$asset['file_id']='45';
MAD4B_SCP_Context_Authority::$source['provider']='google_drive';
$checks['provider_mismatch_refused']=is_wp_error(MAD4B_SCP_Context_Provider_Gateway::read_context_asset('fixture'));
foreach($checks as $name=>$pass)echo ($pass?'PASS':'FAIL')." $name\n";
echo "RESULT ".count(array_filter($checks))."/".count($checks)."\n";
exit(count(array_filter($checks))===count($checks)?0:1);
