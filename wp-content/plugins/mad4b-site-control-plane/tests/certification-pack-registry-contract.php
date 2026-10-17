<?php
define('ABSPATH',__DIR__.'/');define('MAD4B_SCP_VERSION','0.4.0-rc.95');
$GLOBALS['opts']=array();
function add_action($h,$c,$p=10){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function sanitize_text_field($v){return trim((string)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_option($k,$d=array()){return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private$c;public function __construct($c,$m='',$d=null){$this->c=$c;}public function get_error_code(){return$this->c;}}
final class MAD4B_SCP_Distributed_Lock{public static function catalog_name($n){return$n;}public static function acquire($n){return true;}public static function release($n){return true;}}
final class MAD4B_SCP_Site_Profile{public static function site_uuid(){return'11111111-1111-4111-8111-111111111111';}public static function current_environment(){return'staging';}}
final class MAD4B_SCP_Restore_Epoch{public static $epoch=7;public static function material(){return array('epoch'=>self::$epoch);}}
final class MAD4B_SCP_Runtime_Generation_Fence{public static function assert_current(array$x=array()){return array('ready'=>true);}}
final class MAD4B_SCP_Provider_Compatibility_Certification{public static function assess_provider($p,$a=null){return array('artifact'=>array('runtime_artifact_fingerprint'=>str_repeat('a',64)));}}
final class MAD4B_SCP_Crypto_Profile{
 public static function verify_digest_for_purpose(array$s,$d,$p){return isset($s['signed_sha256'])&&hash_equals($d,(string)$s['signed_sha256'])&&'certification_pack'===$p?array('valid'=>true):new WP_Error('sig_bad');}
}
final class MAD4B_SCP_Time_Policy{public static function now_epoch(){return 1700000000;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-certification-pack-registry.php';
$fail=static function($m){fwrite(STDERR,"FAIL certification-pack-registry-contract: $m\n");exit(1);};$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$make=function($seq,$prev='',$change='restrictive'){
 $p=array('contract'=>'mad4b.certification-pack.v1','interpreter_contract'=>'mad4b.certification-pack-interpreter.v1','pack_id'=>'provider_policy','pack_version'=>'1.0.'.$seq,'pack_type'=>'policy_overlay','sequence'=>$seq,'previous_pack_sha256'=>$prev,'site_uuid'=>'11111111-1111-4111-8111-111111111111','environment'=>'staging','restore_epoch'=>7,'runtime_generation'=>array('generation_sha256'=>str_repeat('9',64)),'provider_id'=>'p1','capability_id'=>'search.observe','schema_id'=>'mad4b.policy-overlay.v1','artifact_sha256'=>str_repeat('a',64),'change_class'=>$change,'payload'=>array('max_risk'=>'read','activation'=>'unchanged'),'issued_at'=>1700000000,'expires_at'=>1700003600);
 $p['payload_sha256']=hash('sha256',json_encode(array('activation'=>'unchanged','max_risk'=>'read'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 $p['pack_sha256']=MAD4B_SCP_Certification_Pack_Registry::payload_pack_sha256($p);$p['signature']=array('signed_sha256'=>$p['pack_sha256']);return$p;
};
$p1=$make(1);
$v=MAD4B_SCP_Certification_Pack_Registry::verify_pack($p1);$check(is_array($v),'valid pack rejected');
$a=MAD4B_SCP_Certification_Pack_Registry::activate_restrictive($p1,0);$check(is_array($a)&&1===$a['revision'],'initial atomic activation failed');
$p2=$make(2,$p1['pack_sha256']);
$a=MAD4B_SCP_Certification_Pack_Registry::activate_restrictive($p2,1);$check(is_array($a)&&2===$a['revision'],'chain activation failed');
$r=MAD4B_SCP_Certification_Pack_Registry::activate_restrictive($p1,2);$check(is_wp_error($r)&&'mad4b_certification_pack_chain_mismatch'===$r->get_error_code(),'replay/rollback pack was not denied');
$p3=$make(3,$p2['pack_sha256'],'new_authority');
$r=MAD4B_SCP_Certification_Pack_Registry::activate_restrictive($p3,2);$check(is_wp_error($r)&&'mad4b_certification_pack_governed_review_required'===$r->get_error_code(),'new authority auto-activated');
$foreign=$make(3,$p2['pack_sha256']);$foreign['site_uuid']='22222222-2222-4222-8222-222222222222';$foreign['pack_sha256']=MAD4B_SCP_Certification_Pack_Registry::payload_pack_sha256($foreign);$foreign['signature']=array('signed_sha256'=>$foreign['pack_sha256']);
$r=MAD4B_SCP_Certification_Pack_Registry::verify_pack($foreign);$check(is_wp_error($r)&&'mad4b_certification_pack_foreign_binding'===$r->get_error_code(),'foreign pack was not denied');
$p3ok=$make(3,$p2['pack_sha256']);$a=MAD4B_SCP_Certification_Pack_Registry::activate_restrictive($p3ok,2);$check(is_array($a)&&3===$a['revision'],'third chain activation failed');
$rev=MAD4B_SCP_Certification_Pack_Registry::revoke($p2['pack_sha256'],3,'test');$check(is_array($rev)&&true===$rev['revoked'],'revocation failed');
$s=MAD4B_SCP_Certification_Pack_Registry::status();$check(0===$s['active_pack_count'],'chain revocation did not deactivate descendant');
$r=MAD4B_SCP_Certification_Pack_Registry::verify_pack($p2);$check(is_wp_error($r)&&'mad4b_certification_pack_revoked'===$r->get_error_code(),'revoked pack verified');
MAD4B_SCP_Restore_Epoch::$epoch=8;$r=MAD4B_SCP_Certification_Pack_Registry::verify_pack($p1);$check(is_wp_error($r)&&'mad4b_certification_pack_restore_replay'===$r->get_error_code(),'restore replay was not denied');
MAD4B_SCP_Restore_Epoch::$epoch=7;
$resign=static function($p){
 $payload=$p['payload'];ksort($payload);$p['payload_sha256']=hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 $p['pack_sha256']=MAD4B_SCP_Certification_Pack_Registry::payload_pack_sha256($p);$p['signature']=array('signed_sha256'=>$p['pack_sha256']);return$p;
};
$danger=$make(4);$danger['payload']['max_risk']='write';$danger=$resign($danger);
$r=MAD4B_SCP_Certification_Pack_Registry::activate_restrictive($danger,4);
$check(is_wp_error($r)&&'mad4b_certification_pack_governed_review_required'===$r->get_error_code(),'restrictive label automatically activated a broader payload');
$danger=$make(4);$danger['payload']['grants']=array('*');$danger=$resign($danger);
$r=MAD4B_SCP_Certification_Pack_Registry::activate_restrictive($danger,4);
$check(is_wp_error($r)&&'mad4b_certification_pack_governed_review_required'===$r->get_error_code(),'untyped grant payload automatically activated');
$schema=$make(4);$schema['schema_id']='mad4b.unknown.v99';$schema=$resign($schema);
$r=MAD4B_SCP_Certification_Pack_Registry::verify_pack($schema);
$check(is_wp_error($r)&&'mad4b_certification_pack_schema_invalid'===$r->get_error_code(),'unknown interpreter schema accepted');
$saved=$GLOBALS['opts'][MAD4B_SCP_Certification_Pack_Registry::OPTION];
for($i=0;$i<256;$i++)$GLOBALS['opts'][MAD4B_SCP_Certification_Pack_Registry::OPTION]['revoked'][hash('sha256','revoked-'.$i)]=array('revoked_at'=>1,'reason'=>'retained');
$before=$GLOBALS['opts'][MAD4B_SCP_Certification_Pack_Registry::OPTION];
$r=MAD4B_SCP_Certification_Pack_Registry::revoke(str_repeat('f',64),4);
$check(is_wp_error($r)&&$before===$GLOBALS['opts'][MAD4B_SCP_Certification_Pack_Registry::OPTION],'revocation overflow evicted anti-replay evidence');
$GLOBALS['opts'][MAD4B_SCP_Certification_Pack_Registry::OPTION]=$saved;
for($i=0;$i<256;$i++)$GLOBALS['opts'][MAD4B_SCP_Certification_Pack_Registry::OPTION]['lineage'][hash('sha256','lineage-'.$i)]='';
$fresh=$make(4);
$r=MAD4B_SCP_Certification_Pack_Registry::activate_restrictive($fresh,4);
$check(is_wp_error($r)&&'mad4b_certification_pack_lineage_full'===$r->get_error_code(),'lineage bound was bypassed');
echo "mad4b.certification-pack-registry.v1: PASS\n";
