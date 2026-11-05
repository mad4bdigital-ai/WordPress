<?php
define('ABSPATH','/tmp/');
class WP_Error{private $c;public function __construct($c,$m=''){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$x){}
function current_user_can($x){return true;}
function get_current_user_id(){return 1;}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$v,$autoload=false){$GLOBALS['options'][$key]=$v;return true;}
function wp_generate_uuid4(){return '11111111-1111-4111-8111-111111111111';}
function wp_json_encode($x){return json_encode($x);}
function sanitize_text_field($s){return (string)$s;}
class MAD4B_SCP_Policy{public static function can_connect_user($id){return true;}}
class MAD4B_SCP_Context_Authority{
 static $source,$assets,$revision=7;
 static function source($id){return (self::$source['source_id']??'')===$id?self::$source:[];}
 static function sources(){return self::$source?[self::$source['source_id']=>self::$source]:[];}
 static function assets(){return self::$assets;}
 static function profile(){return ['site_uuid'=>'site-verified','brand_id'=>'brand-verified'];}
 static function registry_revision(){return self::$revision;}
 static function remove_source($id){if(!self::$source||self::$source['source_id']!==$id)return new WP_Error('missing');self::$source=null;self::$assets=[];self::$revision++;return ['removed'=>true];}
 static function upsert_source($payload){self::$source=['source_id'=>str_repeat('a',64),'site_uuid'=>'site-verified','brand_id'=>'brand-verified']+$payload;self::$revision++;return self::$source;}
}
MAD4B_SCP_Context_Authority::$source=[
 'source_id'=>str_repeat('a',64),'site_uuid'=>'site-verified','brand_id'=>'brand-verified',
 'provider'=>'google_drive','mode'=>'governed','external_root_id'=>'folder','label'=>'Fixture Brand',
 'task_scope'=>'','recursive'=>true,'write_policy'=>'read_only'];
MAD4B_SCP_Context_Authority::$assets=[
 'asset1'=>['source_id'=>str_repeat('a',64),'site_uuid'=>'site-verified','brand_id'=>'brand-verified','review_status'=>'approved']];
require dirname(__DIR__) . '/includes/class-mad4b-scp-source-lifecycle.php';
$id=str_repeat('a',64);$checks=[];
$p=MAD4B_SCP_Source_Lifecycle::archive_plan($id);
$checks['bounded_plan']=is_array($p)&&count($p['assets'])===1;
$wrong=MAD4B_SCP_Source_Lifecycle::archive_source($id,str_repeat('b',64));
$checks['stale_digest_denied']=is_wp_error($wrong)&&count(MAD4B_SCP_Context_Authority::sources())===1;
$a=MAD4B_SCP_Source_Lifecycle::archive_source($id,$p['digest']);
$checks['source_detached']=is_array($a)&&$a['state']==='detached'&&count(MAD4B_SCP_Context_Authority::sources())===0;
$checks['snapshot_preserved']=count(MAD4B_SCP_Source_Lifecycle::archives())===1;
$b=MAD4B_SCP_Source_Lifecycle::restore_source($a['archive_id']);
$checks['read_only_reenrollment']=is_array($b)&&$b['state']==='restored_rescan_required'&&count(MAD4B_SCP_Context_Authority::assets())===0
 &&(MAD4B_SCP_Context_Authority::$source['write_policy']??'')==='read_only';
$again=MAD4B_SCP_Source_Lifecycle::restore_source($a['archive_id']);
$checks['double_restore_denied']=is_wp_error($again);
foreach($checks as $name=>$ok)echo ($ok?'PASS':'FAIL')." $name\n";
echo 'RESULT '.count(array_filter($checks)).'/'.count($checks)."\n";
exit(count(array_filter($checks))===count($checks)?0:1);
