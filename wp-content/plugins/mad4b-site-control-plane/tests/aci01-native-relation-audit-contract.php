<?php
define( 'ABSPATH', __DIR__ );
$GLOBALS['readable']=array(41=>true,42=>true);
function current_user_can($what,$id=null){return $what==='read_post' && !empty($GLOBALS['readable'][$id]);}
final class MAD4B_SCP_ACI01_Runtime_Binding {
    public static function is_valid($x){return is_array($x)&&($x['contract']??'')==='mock.binding.v1';}
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
echo "ACI01_NATIVE_RELATION_AUDIT: PASS\n";
