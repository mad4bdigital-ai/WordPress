<?php
if(!defined('ABSPATH'))define('ABSPATH','/');
error_reporting(E_ALL);
const ARRAY_A = 'ARRAY_A';
class WP_Error {public function __construct(public string $code){} public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$f=0){return json_encode($v,$f|JSON_THROW_ON_ERROR);}
function maybe_unserialize($v){return is_string($v)&&preg_match('/^(a|O|s|i|b|d):/',$v)?unserialize($v,['allowed_classes'=>false]):$v;}
function maybe_serialize($v){return is_array($v)?serialize($v):$v;}
function get_post_type($id){return 'attachment';}
function get_post_mime_type($id){return 'image/jpeg';}
function get_attached_file($id){return __FILE__;}
function wp_get_attachment_url($id){return 'https://wayfatours.com/wp-content/uploads/test-'.$id.'.jpg';}
function home_url($v='/'){return 'https://wayfatours.com'.$v;}
function wp_parse_url($v,$part){return parse_url($v,$part);}
function wp_cache_delete($id,$type){}
function clean_term_cache($id,$type){}
function clean_post_cache($id){}
$GLOBALS['opts']=[];
function get_option($k,$default=false){return $GLOBALS['opts'][$k]??$default;}
function add_option($k,$value){if(array_key_exists($k,$GLOBALS['opts']))return false;$GLOBALS['opts'][$k]=$value;return true;}
function update_option($k,$value){if(($GLOBALS['fail_option_update_prefix']??'')!==''&&str_starts_with($k,$GLOBALS['fail_option_update_prefix']))return false;$GLOBALS['opts'][$k]=$value;return true;}
function delete_option($k){unset($GLOBALS['opts'][$k]);return true;}
class MAD4B_SCP_Host_Agent_Bridge {static function active(){return true;} static function agent(){return ['public_id'=>'unit-agent'];} static function site(){return ['site_uuid'=>'test-site'];}}
class MAD4B_SCP_Context_Authority {static function ai_review_policy_status(){return ['ready'=>true,'mode'=>'ai_only','ai_agent_public_id'=>'unit-agent'];}}
class MAD4B_SCP_Audit {static function record($event,$data){return !empty($GLOBALS['force_audit_failure'])?new WP_Error('simulated_audit_failure'):['event'=>$event];}}
class MAD4B_Composite_Media_Integrity_V1 {static function fields($kind,$type){return ['gallery'=>['type'=>'gallery','format'=>'url','repeater_fields'=>[]],'property_gallery'=>['type'=>'gallery','format'=>'id','repeater_fields'=>[]], 'slides'=>['type'=>'repeater','repeater_fields'=>[['name'=>'photo','type'=>'media','value_format'=>null],['name'=>'caption','type'=>'text']]],'extras'=>['type'=>'repeater','repeater_fields'=>[['name'=>'gellery','type'=>'gallery','value_format'=>'url'],['name'=>'title','type'=>'text']]]];}}
class FakeWPDB {
 public $postmeta='fake_postmeta';public $termmeta='fake_termmeta';public $meta='10,11';public $duplicate=false;public $writes=0;public $drift_on_write=false;
 public function prepare($sql,...$vals){return json_encode(['sql'=>$sql,'vals'=>$vals]);}
 public function get_results($prepared,$mode){$p=json_decode($prepared,true);return $this->duplicate?[['meta_id'=>9,'meta_value'=>$this->meta],['meta_id'=>10,'meta_value'=>$this->meta]]:[['meta_id'=>9,'meta_value'=>$this->meta]];}
 public function query($prepared){$p=json_decode($prepared,true);[$new,$id,$old]=$p['vals'];if($this->drift_on_write||$this->meta!==$old)return 0;$this->meta=$new;$this->writes++;return 1;}
}
$GLOBALS['wpdb']=new FakeWPDB();
require dirname(__DIR__,4).'/wp-content/mu-plugins/mad4b-composite-media-patch.php';
require dirname(__DIR__,4).'/wp-content/mu-plugins/mad4b-composite-media-adapter.php';
function assert_ok($condition,$label){if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$target=['kind'=>'post','type'=>'tours-and-activities','id'=>69819];
$base=['operation'=>['action'=>'append_gallery'],'expected_value_sha256'=>hash('sha256',json_encode('10,11',JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'sha256'=>hash_file('sha256',__FILE__),'attachment_id'=>42,'field'=>'gallery','site_uuid'=>'test-site','contract'=>'mad4b.media.composite-attachment.v1'];
$pre=MAD4B_Composite_Media_Adapter_V1::run($base,$target,'post_registered_composite','preflight');assert_ok(!is_wp_error($pre)&&$pre['status']==='preflight_valid'&&$GLOBALS['wpdb']->writes===0,'preflight shuffled keys no write');
$review=['rights_evidence'=>str_repeat('licensed image with rights verified ',2),'subject_description'=>str_repeat('verified subject for this tour ',2),'rights_verified'=>true,'visual_match_verified'=>true,'reviewed_sha256'=>hash_file('sha256',__FILE__),'decision'=>'approve'];
$approved=MAD4B_Composite_Media_Adapter_V1::run(['review'=>$review,'asset'=>$base],$target,'post_registered_composite','review');assert_ok(!is_wp_error($approved)&&$approved['status']==='approved','signed reviewed asset and field');
$applied=MAD4B_Composite_Media_Adapter_V1::run($base,$target,'post_registered_composite','apply');assert_ok(!is_wp_error($applied)&&$GLOBALS['wpdb']->meta==='10,11,42'&&$GLOBALS['wpdb']->writes===1,'atomic gallery append, readback');
$again=MAD4B_Composite_Media_Adapter_V1::run($base,$target,'post_registered_composite','apply');assert_ok(is_wp_error($again)&&$GLOBALS['wpdb']->writes===1,'reject stale retry');
$GLOBALS['wpdb']->meta='10,11';$GLOBALS['wpdb']->duplicate=true;
$dup=MAD4B_Composite_Media_Adapter_V1::run($base,$target,'post_registered_composite','preflight');assert_ok(is_wp_error($dup)&&str_contains($dup->get_error_code(),'duplicate'),'reject duplicate metadata rows');$GLOBALS['wpdb']->duplicate=false;
$GLOBALS['wpdb']->meta=serialize(['item-0'=>['photo'=>6,'caption'=>'untouched']]);
$repeat=$base;$repeat['field']='slides';$repeat['operation']=['field'=>'photo','row'=>'item-0','action'=>'set_repeater_media'];$repeat['expected_value_sha256']=hash('sha256',json_encode(['item-0'=>['photo'=>6,'caption'=>'untouched']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$pre=MAD4B_Composite_Media_Adapter_V1::run($repeat,$target,'post_registered_composite','preflight');assert_ok(!is_wp_error($pre)&&$GLOBALS['wpdb']->writes===1,'repeater format and field preflight');
$reviewed=MAD4B_Composite_Media_Adapter_V1::run(['asset'=>$repeat,'review'=>$review],$target,'post_registered_composite','review');assert_ok(!is_wp_error($reviewed),'repeater review');
$done=MAD4B_Composite_Media_Adapter_V1::run($repeat,$target,'post_registered_composite','apply');$data=unserialize($GLOBALS['wpdb']->meta);assert_ok(!is_wp_error($done)&&$data['item-0']['photo']===42&&$data['item-0']['caption']==='untouched','repeater atomic update preserves caption');

// Fail the audit *after* the database CAS, then assert exact raw rollback.
$GLOBALS['wpdb']->meta='10,11';$GLOBALS['force_audit_failure']=false;
$rollback=$base;$rollback['attachment_id']=43;
$approved=MAD4B_Composite_Media_Adapter_V1::run(['asset'=>$rollback,'review'=>$review],$target,'post_registered_composite','review');
assert_ok(!is_wp_error($approved),'rollback candidate approved');
$GLOBALS['force_audit_failure']=true;$before=$GLOBALS['wpdb']->writes;
$failed=MAD4B_Composite_Media_Adapter_V1::run($rollback,$target,'post_registered_composite','apply');
assert_ok(is_wp_error($failed)&&$GLOBALS['wpdb']->meta==='10,11'&&$GLOBALS['wpdb']->writes===$before+2,'simulated failed audit restores exact raw value');
$GLOBALS['force_audit_failure']=false;
// Simulate concurrent update between preflight and the SQL conditional compare-and-swap.
$GLOBALS['wpdb']->meta='10,11';$other=$base;$other['attachment_id']=44;
$approved=MAD4B_Composite_Media_Adapter_V1::run(['asset'=>$other,'review'=>$review],$target,'post_registered_composite','review');
assert_ok(!is_wp_error($approved),'CAS collision candidate approved');
$GLOBALS['wpdb']->drift_on_write=true;$before=$GLOBALS['wpdb']->writes;
$failed=MAD4B_Composite_Media_Adapter_V1::run($other,$target,'post_registered_composite','apply');
assert_ok(is_wp_error($failed)&&$GLOBALS['wpdb']->meta==='10,11'&&$GLOBALS['wpdb']->writes===$before,'concurrent CAS collision performs no write');
$GLOBALS['wpdb']->drift_on_write=false;

$GLOBALS['wpdb']->meta=serialize(['item-0'=>['title'=>'keep','gellery'=>'']]);
$nested=$base;$nested['field']='extras';$nested['operation']=['field'=>'gellery','row'=>'item-0','action'=>'append_repeater_gallery'];
$nested['expected_value_sha256']=hash('sha256',json_encode(['item-0'=>['title'=>'keep','gellery'=>'']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$p=MAD4B_Composite_Media_Adapter_V1::run($nested,$target,'post_registered_composite','preflight');assert_ok(!is_wp_error($p),'nested gallery empty preflight using JetEngine URL format');
$a=MAD4B_Composite_Media_Adapter_V1::run(['asset'=>$nested,'review'=>$review],$target,'post_registered_composite','review');assert_ok(!is_wp_error($a),'nested gallery reviewed');
$done=MAD4B_Composite_Media_Adapter_V1::run($nested,$target,'post_registered_composite','apply');$data=unserialize($GLOBALS['wpdb']->meta);
assert_ok(!is_wp_error($done)&&$data['item-0']['title']==='keep'&&$data['item-0']['gellery']==='https://wayfatours.com/wp-content/uploads/test-42.jpg','nested gallery actual CAS write retains row text');

// Real property gallery representation: array of id/url objects.
$GLOBALS['wpdb']->meta=serialize([['id'=>6,'url'=>'https://wayfatours.com/old.jpg']]);
$prop=$base;$prop['field']='property_gallery';
$prop['expected_value_sha256']=hash('sha256',json_encode([['id'=>6,'url'=>'https://wayfatours.com/old.jpg']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$pre=MAD4B_Composite_Media_Adapter_V1::run($prop,$target,'post_registered_composite','preflight');assert_ok(!is_wp_error($pre),'property object-gallery preflight');
$approved=MAD4B_Composite_Media_Adapter_V1::run(['asset'=>$prop,'review'=>$review],$target,'post_registered_composite','review');assert_ok(!is_wp_error($approved),'property object-gallery review');
$done=MAD4B_Composite_Media_Adapter_V1::run($prop,$target,'post_registered_composite','apply');$items=unserialize($GLOBALS['wpdb']->meta);
assert_ok(!is_wp_error($done)&&count($items)===2&&$items[0]['id']===6&&$items[1]['id']===42,'property object-gallery append preserves previous entry');

// Restore is forbidden if another editor changed the gallery after apply.
$appliedSnapshot=$GLOBALS['wpdb']->meta;
$GLOBALS['wpdb']->meta=serialize([['id'=>6,'url'=>'https://wayfatours.com/old.jpg'],['id'=>42,'url'=>'https://wayfatours.com/wp-content/uploads/test-42.jpg'],['id'=>99,'url'=>'https://wayfatours.com/edited.jpg']]);
$denied=MAD4B_Composite_Media_Adapter_V1::run(['field'=>'property_gallery','identity_sha256'=>$done['identity_sha256'],'receipt_sha256'=>$done['receipt_sha256']],$target,'post_registered_composite','revoke');
assert_ok(is_wp_error($denied)&&str_contains($denied->get_error_code(),'value_drift'),'restore refuses post-apply changes');
$GLOBALS['wpdb']->meta=$appliedSnapshot;
$denied=MAD4B_Composite_Media_Adapter_V1::run(['field'=>'property_gallery','identity_sha256'=>$done['identity_sha256'],'receipt_sha256'=>str_repeat('0',64)],$target,'post_registered_composite','revoke');
assert_ok(is_wp_error($denied),'restore refuses wrong receipt');
// Signed restore uses the original receipt and cannot be replayed.
$restored=MAD4B_Composite_Media_Adapter_V1::run(['receipt_sha256'=>$done['receipt_sha256'],'identity_sha256'=>$done['identity_sha256'],'field'=>'property_gallery'],$target,'post_registered_composite','revoke');
$items=unserialize($GLOBALS['wpdb']->meta);
assert_ok(!is_wp_error($restored)&&$restored['status']==='restored_verified'&&count($items)===1&&$items[0]['id']===6,'signed restore of exact gallery preimage');
$repeatRestore=MAD4B_Composite_Media_Adapter_V1::run(['field'=>'property_gallery','identity_sha256'=>$done['identity_sha256'],'receipt_sha256'=>$done['receipt_sha256']],$target,'post_registered_composite','revoke');
assert_ok(is_wp_error($repeatRestore),'reject replay of signed restore');

// A failed restore-index update after the media SQL write must never be
// reported as successful. Roll back the raw value and unconsume the review.
$GLOBALS['wpdb']->meta='10,11';$fragile=$base;$fragile['attachment_id']=78;
$approved=MAD4B_Composite_Media_Adapter_V1::run(['asset'=>$fragile,'review'=>$review],$target,'post_registered_composite','review');
assert_ok(!is_wp_error($approved),'index failure test review');
$GLOBALS['fail_option_update_prefix']='mad4b_composite_restore_index_v1_';
$failed=MAD4B_Composite_Media_Adapter_V1::run($fragile,$target,'post_registered_composite','apply');
assert_ok(is_wp_error($failed)&&$GLOBALS['wpdb']->meta==='10,11','index persistence failure rolls back exact raw media');
$GLOBALS['fail_option_update_prefix']='';
$retried=MAD4B_Composite_Media_Adapter_V1::run($fragile,$target,'post_registered_composite','apply');
assert_ok(!is_wp_error($retried)&&$GLOBALS['wpdb']->meta==='10,11,78','approved review can retry after failed ledger write');

$GLOBALS['wpdb']->meta=serialize([['id'=>6,'url'=>'https://wayfatours.com/old.jpg']]);
$z=$base;$z['field']='property_gallery';$z['attachment_id']=91;
$z['expected_value_sha256']=hash('sha256',json_encode([['id'=>6,'url'=>'https://wayfatours.com/old.jpg']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$ok=MAD4B_Composite_Media_Adapter_V1::run(['asset'=>$z,'review'=>$review],$target,'post_registered_composite','review');
assert_ok(!is_wp_error($ok),'restore-failure setup review');
$applied=MAD4B_Composite_Media_Adapter_V1::run($z,$target,'post_registered_composite','apply');
assert_ok(!is_wp_error($applied),'restore-failure setup apply');
$persisted=$GLOBALS['wpdb']->meta;
$revoke=['field'=>'property_gallery','identity_sha256'=>$applied['identity_sha256'],'receipt_sha256'=>$applied['receipt_sha256']];
$GLOBALS['fail_option_update_prefix']='mad4b_composite_restore_index_v1_';
$failed=MAD4B_Composite_Media_Adapter_V1::run($revoke,$target,'post_registered_composite','revoke');
assert_ok(is_wp_error($failed)&&$GLOBALS['wpdb']->meta===$persisted,'partial revoke failure preserves image metadata');
$GLOBALS['fail_option_update_prefix']='';
$again=MAD4B_Composite_Media_Adapter_V1::run($revoke,$target,'post_registered_composite','revoke');
assert_ok(!is_wp_error($again)&&$again['status']==='restored_verified','retry revoke after ledger failure');
