<?php
define('ABSPATH','/');
class WP_Error {public function __construct(public $code){}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$args){} function add_filter(...$args){}
function current_user_can($cap,...$args){return $GLOBALS['permitted']??true;}
function get_post_type($id){return 'operational-services';}
function get_post($id){return (object)['post_type'=>'operational-services','post_status'=>'publish'];}
function get_post_meta($id,$key,$single){return 'unpriced';}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function wp_strip_all_tags($x){return strip_tags($x);}
function get_the_title($id){return 'Service';}
function get_edit_post_link($id,$context){return '/edit/'.$id;}
function admin_url($path){return '/wp-admin/'.$path;}
function has_filter($key){return false;}
function get_current_blog_id(){return 1;}
function get_current_user_id(){return 3;}
function sanitize_textarea_field($x){return trim($x);}
function maybe_unserialize($x){return unserialize($x);}
function add_post_meta($id,$key,$value,$unique){$GLOBALS['stored'][]=serialize($value);return count($GLOBALS['stored']);}
class MockDb{
 public $postmeta='wp_postmeta';
 public function prepare($sql,...$args){return [$sql,$args];}
 public function get_col($q){return array_slice(array_reverse($GLOBALS['stored']??[]),$q[1][2],25);}
 public function get_var($q){return 1;}
}
$wpdb=new MockDb();$GLOBALS['stored']=[];
require __DIR__.'/../wp-content/mu-plugins/mad4b-product-cost-review-ledger-v1.php';
$c='MAD4B_Product_Cost_Review_Ledger_V1';$n=0;
function ck($ok,$why){global $n;if(!$ok)throw new RuntimeException($why);$n++;}
$v=$c::inspect(1);ck($v['commercially_approved']===false,'review never commercial');
$bad=$c::record(1,['expected_fingerprint'=>str_repeat('a',64)]);ck(is_wp_error($bad)&&count($GLOBALS['stored'])===0,'stale input no write');
for($i=1;$i<=30;$i++){
 $r=$c::record(1,['note'=>'Review '.$i,'expected_fingerprint'=>$v['source_fingerprint']]);
 if(is_wp_error($r)||$r['revision']!==$i||$r['checkout_authorized']!==false)throw new RuntimeException('append sequence failed at '.$i);
}
ck(count($GLOBALS['stored'])===30,'full history preserved');
$p1=$c::history(1,1);$p2=$c::history(1,2);
ck(count($p1)===25&&$p1[24]['revision']===30,'recent page');
ck(count($p2)===5&&$p2[0]['revision']===1,'older page retained');
ck($p1[24]['reviewer_id']===3&&$p1[24]['note']==='Review 30','review identity and note retained');
$GLOBALS['permitted']=false;
ck($c::history(1)===[]&&is_wp_error($c::record(1,[])),'permission denial');
echo "PASS $n/$n append-only review history (30 revisions)\n";
