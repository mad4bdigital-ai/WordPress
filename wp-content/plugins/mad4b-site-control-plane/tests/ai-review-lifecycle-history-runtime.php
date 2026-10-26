<?php
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;public function __construct($code,$message=''){$this->code=$code;}public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
$GLOBALS['options']=array();
function get_option($key,$default=array()){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
class MAD4B_SCP_Context_Authority {
    public static $asset=array(),$revision=5;
    public static function asset($id){return self::$asset;}
    public static function registry_revision(){return self::$revision;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-review-lifecycle.php';
$id=str_repeat('a',64);$a=str_repeat('b',64);$b=str_repeat('c',64);
$expect=function($description,$condition){if(!$condition){fwrite(STDERR,"FAIL $description\n");exit(1);}echo $description.": PASS\n";};
MAD4B_SCP_Context_Authority::$asset=array('review_actor_type'=>'ai_agent','review_decision'=>'needs_changes','reviewed_content_hash'=>$a);
$result=MAD4B_SCP_AI_Review_Lifecycle::record_review_result($id,$a,'needs_changes');
$expect('first_review_recorded',is_array($result)&&$result['rounds']===1);
$h=MAD4B_SCP_AI_Review_Lifecycle::read_history($id,$a);
$expect('first_review_one_round',$h['rounds']===1&&$h['unchanged_repair_rounds']===1);
$replay=MAD4B_SCP_AI_Review_Lifecycle::record_review_result($id,$a,'needs_changes');
$expect('same_receipt_idempotent',!empty($replay['already_recorded'])&&$replay['rounds']===1);
MAD4B_SCP_Context_Authority::$revision++;
$result=MAD4B_SCP_AI_Review_Lifecycle::record_review_result($id,$a,'needs_changes');
$expect('unchanged_repair_detected',$result['rounds']===2&&$result['unchanged_repair_rounds']===2);
$expect('different_hash_resets_stall',MAD4B_SCP_AI_Review_Lifecycle::read_history($id,$b)['unchanged_repair_rounds']===0);
MAD4B_SCP_Context_Authority::$revision++;
MAD4B_SCP_Context_Authority::$asset['reviewed_content_hash']=$b;
$result=MAD4B_SCP_AI_Review_Lifecycle::record_review_result($id,$b,'needs_changes');
$expect('new_revision_restarts_repair_stall',$result['rounds']===3&&$result['unchanged_repair_rounds']===1);
MAD4B_SCP_Context_Authority::$revision++;
MAD4B_SCP_Context_Authority::$asset['review_decision']='approve';
$result=MAD4B_SCP_AI_Review_Lifecycle::record_review_result($id,$b,'approve');
$expect('approved_history_keeps_attempt_count',$result['rounds']===4&&$result['unchanged_repair_rounds']===0);
MAD4B_SCP_Context_Authority::$asset['review_actor_type']='wp_admin';
$expect('no_fake_ai_receipt',is_wp_error(MAD4B_SCP_AI_Review_Lifecycle::record_review_result($id,$b,'approve')));
echo "SUMMARY pass=8 fail=0\n";
