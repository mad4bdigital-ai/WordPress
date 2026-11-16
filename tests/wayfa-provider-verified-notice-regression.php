<?php
/** Pure fixture regression; never connects to production or changes WP options. */
define('ABSPATH', __DIR__ . '/');
// WordPress core supplies these string helpers on PHP 7.4. The networkless
// test fixture provides only the same baseline API, not payment/authority.
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strpos((string)$haystack, (string)$needle) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos((string)$haystack, (string)$needle) !== false;
    }
}
$GLOBALS['filters'] = [];
$GLOBALS['opts'] = [];
$GLOBALS['origin'] = 'https://wayfatours.com/';
function add_filter($hook,$cb,$priority=10,$num=1){$GLOBALS['filters'][$hook][]=$cb;}
function add_action($hook,$cb,$priority=10,$num=1){}
function apply_filters($hook,$value,...$args){foreach((array)($GLOBALS['filters'][$hook]??[]) as $f)$value=$f($value,...$args);return $value;}
function get_option($k,$default=null){return $GLOBALS['opts'][$k]??$default;}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
function home_url($path='/'){return $GLOBALS['origin'];}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function get_current_blog_id(){return 1;}
$GLOBALS['wpdb']=new class {function prepare($sql,$arg){return $sql;}function get_var($s){return 1;}};
require __DIR__.'/../ops/site-company-overlays/wayfatours/configuration-runtime/wp-content/mu-plugins/mad4b-configuration-notice-revalidation.php';
$cl=MAD4B_Wayfa_Configuration_Notice_Revalidation_V1::class;
$mk=function($suffix='stripe'){return ['source'=>'admin_notice','code'=>'admin_notice_1c27e337e9f3fd16',
 'label'=>$suffix==='stripe'?"Test mode active: All transactions are simulated. Customers can't make real purchases through Stripe.":'Unrelated plugin says: test mode',
 'state'=>'open', 'observations'=>2, 'reopens'=>0];};
$GLOBALS['opts']['active_plugins']=['woocommerce-gateway-stripe/woocommerce-gateway-stripe.php'];
$GLOBALS['opts']['woocommerce_stripe_settings']=['enabled'=>'yes','testmode'=>'no'];
$GLOBALS['opts'][$cl::STORE]=['stripe'=>$mk(),'another'=>$mk('other')];
$checks=0;
function ok($condition,$explanation){global $checks;if(!$condition)throw new RuntimeException('FAILED: '.$explanation);$checks++;}
$r=$cl::recheck();
ok($r['resolved']===1&&$r['reopened']===0&&$r['checked']===1,'resolved with provider proof');
$rows=$GLOBALS['opts'][$cl::STORE];
ok($rows['stripe']['state']==='resolved'&&$rows['another']['state']==='open','other notices untouched');
ok(($rows['stripe']['resolution_evidence']??'')==='woocommerce_stripe_settings.testmode=no','no credential evidence');
$r=$cl::recheck();ok($r['changed']===0,'idempotent repeat');
$GLOBALS['opts']['woocommerce_stripe_settings']['testmode']='yes';
$r=$cl::recheck();ok($r['reopened']===1&&$GLOBALS['opts'][$cl::STORE]['stripe']['state']==='open','reopen if mode regresses');
$GLOBALS['opts']['active_plugins']=[];
$GLOBALS['opts']['woocommerce_stripe_settings']['testmode']='no';
$r=$cl::recheck();ok($r['changed']===0,'unknown installed gateway does not resolve');
$GLOBALS['opts']['active_plugins']=['woocommerce-gateway-stripe/woocommerce-gateway-stripe.php'];
$GLOBALS['opts']['woocommerce_stripe_settings']['testmode']=null;
$r=$cl::recheck();ok($r['changed']===0,'unknown setting does not resolve');
$GLOBALS['opts']['woocommerce_stripe_settings']['testmode']='no';
$GLOBALS['origin']='https://tours.dev.mad4b.com/';
$r=$cl::recheck();ok($r['status']==='site_scope_mismatch','company site bound');
$GLOBALS['origin']='https://wayfatours.com/';
$GLOBALS['mad4b_configuration_discovery_incomplete']=true;
$r=$cl::recheck();ok($r['status']==='scan_incomplete','incomplete scan does not close notice');
$GLOBALS['mad4b_configuration_discovery_incomplete']=false;
ok($cl::recheck()['resolved']===1,'legitimate provider signal may resolve again');
echo json_encode(['contract'=>'mad4b.wayfa-notice-regression.v1','passed'=>$checks,'tests'=>10])."\n";
