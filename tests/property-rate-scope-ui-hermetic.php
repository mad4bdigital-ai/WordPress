<?php
define('ABSPATH','/');
$done=0;$hooks=[];$types=[100=>'properties',101=>'units',999=>'property-rates'];
$meta=[];$writes=[];$parents=[100];$created=null;$redirect=null;
function add_filter($hook,$fn,$priority=10,$count=1){$GLOBALS['hooks'][$hook][]=$fn;}
function add_action($hook,$fn,$priority=10,$count=1){$GLOBALS['hooks'][$hook][]=$fn;}
function current_user_can($cap,...$args){return !in_array($cap,(array)($GLOBALS['deny']??[]),true);}
function wp_unslash($v){return $v;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function absint($v){return abs((int)$v);}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_html($v);}
function selected($a,$b,$echo=true){return $a===$b?' selected="selected"':'';}
function is_admin(){return true;}
function admin_url($s){return '/wp-admin/'.$s;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function get_post_type($id){return $GLOBALS['types'][$id]??'';}
function get_post_status($id){return $id===999?'draft':'publish';}
function get_the_title($id){return 'Native #'.$id;}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
function get_edit_post_link($id,$how=''){return '/wp-admin/post.php?post='.$id.'&action=edit';}
function get_current_user_id(){return 7;}
function wp_insert_post($data,$err=false){$GLOBALS['created']=$data;return 999;}
function add_post_meta($id,$key,$value,$unique){$GLOBALS['writes'][$key]=$value;}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_safe_redirect($url){$GLOBALS['redirect']=$url;}
function check_ajax_referer($action,$field,$die){$GLOBALS['nonceAction']=$action;return ($_POST[$field]??'')==='TEST-NONCE';}
function wp_create_nonce($action){return 'TEST-NONCE';}
function wp_send_json_success($data,$status=200){$GLOBALS['ajaxResult']=['success'=>true,'data'=>$data,'status'=>$status];}
function wp_send_json_error($data,$status=400){throw new RuntimeException('AJAX ERROR:'.($data['code']??''));}
function get_current_screen(){return (object)['post_type'=>'units'];}
function wp_json_encode($v,...$args){return json_encode($v);}
function wp_nonce_field($action){echo 'NONCE:'.esc_html($action);}
function wp_die($message,$title=''){throw new RuntimeException((string)$message);}
function add_meta_box(...$x){}
class WP_Error{public function __construct(public string $code){}}
class WP_Post{public function __construct(public int $ID,public string $post_type) {}}
class FakeDB{public string $prefix='wp_';public function prepare($sql,...$args){return $sql;}
  public function get_col($sql){return $GLOBALS['parents'];}}
$wpdb=new FakeDB();
require __DIR__.'/../wp-content/mu-plugins/mad4b-property-rate-scope-ui-v1.php';
$cls='MAD4B_Property_Rate_Scope_UI_V1';
function ok($valid,$label){$GLOBALS['done']++;if(!$valid)throw new RuntimeException('FAIL '.$label);}
if(($argv[1]??'')==='create'){
  $_POST=['property_id'=>'100','unit_id'=>($argv[2]??'0'),'nonce'=>'TEST-NONCE'];
  $cls::createDraft();
  ok(($GLOBALS['created']['post_status']??'')==='draft','Rate starts Draft');
  ok(($GLOBALS['created']['post_type']??'')==='property-rates','Reuse native post type');
  ok(($GLOBALS['writes']['prop_related_property_id']??null)===100,'original Property linked');
  ok(($GLOBALS['writes']['prop_related_unit_id']??null)===(int)($_POST['unit_id']??-1),'optional Unit link');
  ok(($GLOBALS['writes']['stop_sale']??'')==='1','Stop Sale remains on');
  ok(($GLOBALS['writes']['avilabile_status']??'')==='0','availability unconfirmed');
  ok(!array_key_exists('base_amount',$GLOBALS['writes']),'no invented price');
  ok(!array_key_exists('supplier_cost',$GLOBALS['writes']),'no invented supplier cost');
  ok(($GLOBALS['ajaxResult']['data']['post_status']??'')==='draft','server returns Draft');
  ok(($GLOBALS['ajaxResult']['data']['checkout_ready']??true)===false,'server returns checkout false');
  ok(str_contains((string)($GLOBALS['ajaxResult']['data']['edit_url']??''),'post=999'),'native editor link');
  echo 'PASS '.$GLOBALS['done'].'/'.$GLOBALS['done'].' native AJAX draft creation without price or commercial approval'.PHP_EOL;
  exit;
}
$_GET=['mad4b_rate_scope_filter'=>'unit'];
ok($cls::requestedScope()==='unit','unit scope selected');
$views=$cls::views([]);
ok(isset($views['mad4b_property'],$views['mad4b_unit']) && str_contains($views['mad4b_unit'],'current'),'both rate views and active state');
class FakeQuery{public array $data=['post_type'=>'property-rates','meta_query'=>[['key'=>'tenant_id','value'=>'a']]];
 public function is_main_query(){return true;}public function get($k){return $this->data[$k]??null;}
 public function set($k,$v){$this->data[$k]=$v;}}
$q=new FakeQuery();$cls::filterQuery($q);
ok(($q->data['meta_query'][0]??[])==['relation'=>'AND']||($q->data['meta_query']['relation']??'')==='AND','existing meta query preserved');
ok(str_contains(json_encode($q->data['meta_query']), 'prop_related_unit_id'),'native Unit ID restriction');
$_GET=['mad4b_rate_scope_filter'=>'property'];$q2=new FakeQuery();$cls::filterQuery($q2);
ok(str_contains(json_encode($q2->data['meta_query']),'mad4b_rate_scope_v1'),'explicit Property scope selected');
ok(str_contains(json_encode($q2->data['meta_query']),'NOT EXISTS'),'no Unit link allowed');
ok($cls::unitParent(101)===100,'exact original JetEngine relation');
$parents=[100,200];ok($cls::unitParent(101)===0,'ambiguous parents fail closed');
$parents=[100];
ob_start();$cls::box(new WP_Post(101,'units'));$html=ob_get_clean();
ok(str_contains($html,'Create Unit Rate Draft')&&str_contains($html,'type="button"')&&!str_contains($html,'<form'), 'edit Unit action has no nested form');
$deny=['edit_commerce_rates'];$_GET=['mad4b_rate_scope_filter'=>'unit'];
ok($cls::views([])===[],'unauthorized views hidden');
$deny=[];
echo 'PASS '.$done.'/'.$done.' original Rate scope list and Unit action checks'.PHP_EOL;
