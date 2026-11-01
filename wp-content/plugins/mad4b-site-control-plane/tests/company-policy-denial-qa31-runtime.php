<?php
/** QA31 hermetic company policy negative regression: no WordPress DB or network. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$GLOBALS['qa31_options']=[];
$GLOBALS['qa31_routes']=[];
$GLOBALS['qa31_master']=false;
function add_filter(...$args): void {}
function add_action(...$args): void {}
function get_option($key,$default=false){return $GLOBALS['qa31_options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['qa31_options'][$key]=$value;return true;}
function absint($n): int {return abs((int)$n);}
function get_current_user_id(): int {return 7;}
function current_time($fmt,$gmt=false): string {return '2026-10-31 00:00:00';}
function current_user_can($cap): bool {return false;}
function register_rest_route($ns,$route,$args): void {$GLOBALS['qa31_routes'][$ns.$route]=$args;}
function wp_get_current_user(): object {return (object)['roles'=>$GLOBALS['qa31_master']?['master_admin']:['subscriber']];}
function get_post_type($id): string {return 'product';}
function get_post_types($args,$mode){return ['post','page','product'];}
function get_post_status($id): string {return 'draft';}
function qa31_assert($yes,$message): void {if(!$yes) throw new RuntimeException($message);}
require dirname(__DIR__).'/includes/class-mad4b-scp-company-operations-policy.php';
$cls='MAD4B_SCP_Company_Operations_Policy';
qa31_assert($cls::get()['hard_delete_policy']==='disabled','default hard delete not closed');
qa31_assert($cls::revise(['hard_delete_policy'=>'enabled'],['safe'=>1])===['safe'=>1],'invalid hard delete accepted');
qa31_assert($cls::revise(['administrator'=>true],['safe'=>1])===['safe'=>1],'unknown privilege allowed');
$cls::routes();
$routes=$GLOBALS['qa31_routes']['mad4b/v1/admin/company-policies']??null;
qa31_assert(count($routes??[])===2,'policy REST route missing');
foreach([['GET','read'],['PATCH','update']] as $i=>$expected){
  qa31_assert(($routes[$i]['methods']??'')===$expected[0],'method mismatch');
  qa31_assert(($routes[$i]['callback']??null)===[$cls,$expected[1]],'callback mismatch');
  qa31_assert($routes[$i]['permission_callback']()===false,'company policy REST unauthorized access');
}
$GLOBALS['qa31_master']=true;
qa31_assert($cls::denyPriceMeta(null,22,'_price',100)===null,'price blocked when policy allows');
$GLOBALS['qa31_options'][$cls::OPTION]=['price_edit_policy'=>'blocked','content_publication_policy'=>'review'];
qa31_assert($cls::denyPriceMeta(null,22,'_price',100)===false,'master price mutation not blocked');
qa31_assert($cls::denyPriceMeta(null,22,'post_title','x')===null,'unrelated metadata incorrectly blocked');
$out=$cls::contentPostData(['post_type'=>'post','post_status'=>'publish'],[]);
qa31_assert($out['post_status']==='pending','review-mode publication not held');
qa31_assert($cls::contentPostData(['post_type'=>'post','post_status'=>'draft'],[])['post_status']==='draft','draft was modified');
$GLOBALS['qa31_master']=false;
qa31_assert($cls::denyPriceMeta(null,22,'_price',100)===null,'non-master restriction leakage');
echo "QA31_COMPANY_POLICY_DENIAL: PASS\n";
