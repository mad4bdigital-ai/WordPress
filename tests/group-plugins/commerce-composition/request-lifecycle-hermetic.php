<?php
namespace Commerce\Composition {
 class RequestAdvancePayments {
 static $ctx=[];static $orders=[];
 static function submissionContext($f,$s){return self::$ctx;}
 static function findBySource(...$a){return self::$orders;}
 static function isAdvance($o){return $o instanceof \WC_Order;}
 }
 class RequestReview {static function authorized(){return true;}static function read($id){return ['sources'=>[['form_id'=>6,'submission_id'=>20]]];}}
 class ContactBridge {static function ready(){return true;}}
 class RequestCheckout {static function ready(){return true;}}
}
namespace {
 define('ARRAY_A','ARRAY_A');
 class WP_Error {function __construct(public string $code){}function get_error_code(){return $this->code;}}
 class WC_Order {function get_meta($k){return ['_request_submission_id'=>20,'_request_form_id'=>6,'_request_source_id'=>7,'_request_rate_id'=>8][$k]??'';}}
 class MAD4B_Lifecycle_Access {static function can($cap,$id=null){return $GLOBALS['allow'];}}
 class DB {
 public string $prefix='fixture_',$last_error='';public array $values=[],$links=[];public bool $held=false;public int $releases=0;
 function prepare($q,...$a){return $q;}
 function get_col($q){return $this->values;}
 function get_var($q){if(str_contains($q,'GET_LOCK')){if($this->held)return 0;$this->held=true;return 1;}if(str_contains($q,'RELEASE_LOCK')){$this->held=false;$this->releases++;return 1;}return 1;}
 function get_results($q,$mode){return $this->links;}
 function get_row($q,$mode){return ['form_id'=>6,'status'=>'read','response'=>'{"email":"fixture@example.invalid"}'];}
 }
 function is_wp_error($v){return $v instanceof WP_Error;}
 function get_option($k,$d=false){return $k==='commerce_request_review_board'?['enabled'=>true,'board_id'=>1,'stage_id'=>2]:$d;}
 function current_user_can(...$a){return false;}
 function apply_filters($k,$v){return $v;}
 function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
 function FluentBoardsApi($v){throw new \RuntimeException('unexpected native task mutation');}
 function wc_get_order($id){return new WC_Order();}
 function ck($v,$m){if(!$v)throw new \RuntimeException($m);echo "PASS $m\n";}
 function err($v,$c){return $v instanceof WP_Error&&$v->code===$c;}
 require dirname(__DIR__,3).'/wp-content/plugins/commerce-composition/includes/RequestLifecycle.php';
 require dirname(__DIR__,3).'/wp-content/plugins/commerce-composition/includes/FluentBoardsTaskBridge.php';
 require dirname(__DIR__,3).'/wp-content/plugins/commerce-composition/includes/WorkflowActions.php';
 $c='Commerce\Composition\RequestLifecycle';$b='Commerce\Composition\FluentBoardsTaskBridge';$n='Commerce\Composition\RequestAdvancePayments';$w='Commerce\Composition\WorkflowActions';
 $GLOBALS['wpdb']=new DB();$db=$GLOBALS['wpdb'];$GLOBALS['allow']=true;
 $p=['form_id'=>6,'submission_id'=>20,'order_id'=>0,'event'=>'request_received','component'=>'mail','refund_id'=>0];
 ck($c::validPayload($p),'native queue payload uses source IDs only');
 ck(!$c::validPayload($p+['email'=>'fixture@example.invalid']),'customer answers rejected in queue payload');
 $n::$ctx=new WP_Error('unverified');
 ck($c::jobContext($p)['source_id']===0,'ack fallback has no price or source authority');
 $p['component']='boards';ck(err($c::jobContext($p),'unverified'),'boards cannot use acknowledgement fallback');
 $n::$ctx=['source_id'=>7,'rate_id'=>8,'data'=>[]];
 $n::$orders=[new WC_Order(),new WC_Order()];
 ck(err($c::execute($p),'lifecycle_order_source_ambiguous'),'multiple advance orders rejected before effects');
 $p['order_id']=10;$n::$ctx['rate_id']=9;ck(err($c::execute($p),'lifecycle_order_source_mismatch'),'mismatched rate rejected before effects');
 $db->values=['{"task_id":27,"board_id":1}'];ck($b::taskForSubmission(6,20)===27,'existing native submission task resolved');
 $db->values=['{"task_id":0,"state":"creating"}'];
 ck(err($b::ensureSubmission($p,[],null,0),'boards_source_task_record_uncertain')&&!$db->held,'uncertain task creation never repeated');
 $db->values=['{"task_id":27}','{"task_id":28}'];
 ck(err($b::ensureSubmission($p,[],null,0),'boards_source_task_record_uncertain'),'duplicate source task records rejected');
 $db->values=[];$db->links=[['task_id'=>null,'state'=>'creating']];
 ck(err($b::ensureSubmission($p,[],null,0),'boards_source_task_record_uncertain'),'review task creation in progress blocks duplicate submission task');
 $db->held=true;
 ck(err($b::ensure(5),'boards_submission_busy')&&$db->held,'review path shares source lock');
 ck(err($b::ensureSubmission($p,[],null,0),'boards_submission_busy')&&$db->held,'submission path shares source lock');
 $db->held=false;
 ck(err($c::sendMail($p,[]),'lifecycle_mail_busy')===false&&!$db->held,'mail policy denial releases lock');
 $GLOBALS['allow']=false;ck(err($w::execute('sync_request_lifecycle',$p),'workflow_action_permission_denied'),'workflow dispatch respects native permission');
 ck(err($w::execute('link_contact_record',[]),'workflow_action_permission_denied'),'existing unrelated workflow authority not broadened');
}
