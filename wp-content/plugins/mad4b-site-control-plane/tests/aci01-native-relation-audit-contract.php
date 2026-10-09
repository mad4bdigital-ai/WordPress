<?php
define( 'ABSPATH', __DIR__ );
$GLOBALS['readable']=array(41=>true,42=>true);
function current_user_can($what,$id=null){return $what==='read_post' && !empty($GLOBALS['readable'][$id]);}
final class MAD4B_SCP_ACI01_Runtime_Binding {
    public static function is_valid($x){return is_array($x)&&($x['contract']??'')==='mock.binding.v1';}
    public static function current(){return array('contract'=>'mock.binding.v1','restore_epoch'=>2);}
    public static function same($a,$b){return self::is_valid($a)&&self::is_valid($b)&&$a===$b;}
}
require __DIR__.'/../includes/class-mad4b-scp-aci01-native-relation-audit.php';
function check($ok,$why){if(!$ok){fwrite(STDERR,"FAIL $why\n");exit(1);}}
$c='MAD4B_SCP_ACI01_Native_Relation_Audit';
$b=array('contract'=>'mock.binding.v1','restore_epoch'=>2);
$post=array('provider'=>'wpml','post_id'=>41,'post_type'=>'tour','language'=>'ar',
    'group'=>'102','translations'=>array('ar'=>41,'en'=>42));
$r=$c::assess($post,$post,41,'tour','ar',$b);
check($r['status']==='NEEDS_EVIDENCE','conservative read');
check($r['translation_post_identity_observed']===true,'observed');
check($r['native_relation_certified']===false,'no false WPML certificate');
check($r['translation_count']===2,'translation count');
check($r['paid_calls']===0&&$r['mutation_performed']===false,'no effects');
check(in_array('term_namespace_unverified',$r['reason_codes'],true),'terms remain pending');
check(in_array('attachment_relationships_unverified',$r['reason_codes'],true),'media remains pending');
check($c::assess($post,$post,41,'wrong','ar',$b)['status']==='DENIED','CPT mismatch');
check($c::assess($post,$post,41,'tour','en',$b)['status']==='DENIED','locale mismatch');
$other=$post;$other['translations']['ar']=42;
check($c::assess($other,$other,41,'tour','ar',$b)['status']==='DENIED','identity collision');
$other=$post;$other['group']='different';
check($c::assess($post,$other,41,'tour','ar',$b)['status']==='DENIED','racing group change');
$other=$post;$other['translations']['en']=0;
check($c::assess($other,$other,41,'tour','ar',$b)['status']==='DENIED','invalid relation ID');
$other=$post;$other['translations']['en']=42;unset($GLOBALS['readable'][42]);
check($c::assess($other,$other,41,'tour','ar',$b)['status']==='DENIED','translation not readable');
$GLOBALS['readable'][42]=true;
$other=$post;$other['translations']['en']=42;$other['translations']['es']=42;
check($c::assess($other,$other,41,'tour','ar',$b)['status']==='DENIED','duplicate ID across locales denied');
check($c::assess($post,$post,41,'tour','ar',array())['status']==='DENIED','no binding');
$other=$post;$other['provider']='unknown';
check($c::assess($other,$other,41,'tour','ar',$b)['status']==='DENIED','provider not allowed');
$other=$post;$other['translations']=array_fill_keys(array_map('strval',range(100,141)),42);
check($c::assess($other,$other,41,'tour','ar',$b)['status']==='DENIED','bounded relation inventory');

/** Adversarial preview checks against the public read-only provider seam. */
function is_wp_error($value){return $value instanceof WP_Error;}
class WP_Error {}
final class MAD4B_SCP_Policy { public static function can_read(){return true;} }
final class MAD4B_SCP_Adapter_Registry {
    public static function instance(){return new self();}
    public function get($key){return $key==='translation-bridge' ? $GLOBALS['bridge'] : null;}
}
class ACI01_Audit_Stub_Bridge {
    public $available=true;
    public $status=array('wpml'=>true,'polylang'=>true);
    public $result;
    public $throw=false;
    public $status_drift=false;
    public $status_reads=0;
    public function __construct($observation){$this->result=$observation;}
    public function is_available(){return $this->available;}
    public function translation_status(){
        $this->status_reads++;
        if($this->status_drift && $this->status_reads>1)
            return array('wpml'=>false,'polylang'=>true);
        return $this->status;
    }
    public function translation_get_post($input){
        if($this->throw)throw new RuntimeException('private provider detail');
        return $this->result;
    }
}
$args=array('post_id'=>41,'post_type'=>'tour','locale'=>'ar');
$GLOBALS['bridge']=new ACI01_Audit_Stub_Bridge($post);
$result=$c::preview($args);
check($result['status']==='DENIED'
    && in_array('translation_provider_ambiguous',$result['reason_codes'],true),
    'auto must deny when both translation providers are active');
check($GLOBALS['bridge']->status_reads===1, 'no native reads after provider ambiguity');
$args['provider']='wpml';
check($c::preview($args)['status']==='NEEDS_EVIDENCE',
    'explicit matching provider remains readable without certification');
$args['provider']='polylang';
check(in_array('translation_provider_identity_mismatch',$c::preview($args)['reason_codes'],true),
    'explicit selected provider must match returned identity');
$args['provider']='wpml';
$GLOBALS['bridge']->status=array('wpml'=>false,'polylang'=>true);
check(in_array('translation_provider_not_available',$c::preview($args)['reason_codes'],true),
    'explicit unavailable provider rejected');
$GLOBALS['bridge']=new ACI01_Audit_Stub_Bridge($post);
$GLOBALS['bridge']->throw=true;
check(in_array('translation_provider_exception',$c::preview($args)['reason_codes'],true),
    'third party provider exception must fail closed');
$GLOBALS['bridge']=new ACI01_Audit_Stub_Bridge($post);
$GLOBALS['bridge']->status_drift=true;
check(in_array('snapshot_changed',$c::preview($args)['reason_codes'],true),
    'provider inventory drift must fail closed');
$GLOBALS['bridge']=new ACI01_Audit_Stub_Bridge($post);
$GLOBALS['bridge']->result['post_id']='41garbage';
check($c::preview($args)['status']==='DENIED',
    'native returned ID must be an actual integer, not a castable string');
$GLOBALS['bridge']=new ACI01_Audit_Stub_Bridge($post);
$GLOBALS['bridge']->available=false;
check(in_array('translation_provider_not_available',$c::preview($args)['reason_codes'],true),
    'provider unavailability must fail closed');
echo "ACI01_NATIVE_RELATION_AUDIT: PASS\n";
