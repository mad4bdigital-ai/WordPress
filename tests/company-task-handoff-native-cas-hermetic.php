<?php
namespace FluentBoards\App\Models {
 final class Task {
   public static $inventory=array();
   public $id;public $board_id;public $status='open';public $assignees=array();public $meta=array();public $fail_next_meta_update=false;
   public function __construct($id,$board,$assigned=array()){
      $this->id=$id;$this->board_id=$board;
      foreach($assigned as $uid)$this->assignees[]=new \WP_User($uid);
   }
   public static function find($id){return self::$inventory[$id]??null;}
   public function getMeta($key,$default=array()){return $this->meta[$key]??$default;}
   public function updateMeta($key,$value){if($this->fail_next_meta_update){$this->fail_next_meta_update=false;return false;}$this->meta[$key]=$value;return true;}
   public function refresh(){return $this;}
   public function load($property){return $this;}
 }
}
namespace FluentBoards\App\Services {
 final class BoardService {
   public function isAlreadyMember($board,$uid){return true;}
   public function addMembersInBoard($board,$uid){return true;}
 }
 final class TaskService {
   public static $fail_when_removing=0;
   public static $mutation_calls=0;
   public function updateAssignee($uid,$task){
     ++self::$mutation_calls;
     if((int)$uid===self::$fail_when_removing)
        throw new \RuntimeException('simulated exact provider failure');
     $before=array_map(static function($person){return $person->ID;},$task->assignees);
     if(in_array($uid,$before,true))$task->assignees=array_values(array_filter(
       $task->assignees,static function($person)use($uid){return $person->ID!==$uid;}));
     else $task->assignees[]=new \WP_User($uid);
     return true;
   }
 }
}
namespace {
define('ABSPATH',__DIR__.'/');
$GLOBALS['handoff_actor']=0;
$GLOBALS['handoff_master']=false;
$GLOBALS['handoff_routes']=array();
$GLOBALS['handoff_hooks']=array();
$GLOBALS['handoff_locks']=0;
$GLOBALS['handoff_releases']=0;
class WP_User {public $ID;public function __construct($id){$this->ID=$id;}}
class WP_Error {
 private $code;private $data;
 public function __construct($code,$message='',$data=array()){$this->code=$code;$this->data=$data;}
 public function get_error_code(){return $this->code;}
 public function get_error_data(){return $this->data;}
}
class WP_REST_Request implements \ArrayAccess {
 private $params;
 public function __construct($params=array()){$this->params=$params;}
 public function get_param($name){return $this->params[$name]??null;}
 #[\ReturnTypeWillChange]
 public function offsetGet($name){return $this->get_param($name);}
 #[\ReturnTypeWillChange]
 public function offsetExists($name){return isset($this->params[$name]);}
 #[\ReturnTypeWillChange]
 public function offsetSet($name,$value){$this->params[$name]=$value;}
 #[\ReturnTypeWillChange]
 public function offsetUnset($name){unset($this->params[$name]);}
}
class Fake_Handoff_DB {
 public function prepare($sql,...$args){return $sql;}
 public function get_var($sql){
   if(strpos($sql,'GET_LOCK')!==false){++$GLOBALS['handoff_locks'];return 1;}
   if(strpos($sql,'RELEASE_LOCK')!==false){++$GLOBALS['handoff_releases'];return 1;}
   throw new \RuntimeException('Unexpected SQL in task handoff test');
 }
}
$GLOBALS['wpdb']=new Fake_Handoff_DB();
function add_action($hook,$cb,$priority=10){$GLOBALS['handoff_hooks'][$hook]=$cb;}
function register_rest_route($namespace,$route,$cfg){
 $key=$namespace.'/'.ltrim($route,'/');
 $GLOBALS['handoff_routes'][$key]=$cfg;
}
function is_user_logged_in(){return $GLOBALS['handoff_actor']>0;}
function current_user_can($cap){return $cap==='mad4b_manage_company_operations'&&$GLOBALS['handoff_master'];}
function get_current_user_id(){return $GLOBALS['handoff_actor'];}
function get_current_blog_id(){return 1;}
function get_userdata($uid){return in_array($uid,array(1,2,3),true)?new WP_User($uid):false;}
function absint($v){return abs((int)$v);}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function current_time($format,$gmt=false){return gmdate('Y-m-d H:i:s');}
function is_wp_error($v){return $v instanceof WP_Error;}
function rest_ensure_response($v){return $v;}
class MAD4B_SCP_Readiness_Workflow_Options {
 public static function settings(){return array('board_id'=>99);}
}
class MAD4B_SCP_Company_Master_Admin {
 public static function managedStaff($uid){return in_array($uid,array(1,2),true);}
}
class MAD4B_SCP_Company_Operations_Policy {
 public static function get(){return array('task_offer_ttl_hours'=>72);}
}
function verify_handoff($ok,$reason){
 if(!$ok)throw new \RuntimeException('FAIL NATIVE HANDOFF: '.$reason);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-task-handoff.php';
$c='MAD4B_SCP_Company_Task_Handoff';
$c::routes();
verify_handoff(count($GLOBALS['handoff_routes'])===6,'Expected six exact tenant task routes');
foreach($GLOBALS['handoff_routes'] as $route=>$r)
 verify_handoff(is_callable($r['permission_callback'])
  && call_user_func($r['permission_callback'])===false,
  'No task route should be public: '.$route);
$task=new \FluentBoards\App\Models\Task(101,99,array(1));
\FluentBoards\App\Models\Task::$inventory[101]=$task;
$offerParams=array('id'=>101,'request_id'=>'offer_request_0001',
 'expected_revision'=>0,'from_user_id'=>1,'to_user_id'=>2,'reason'=>'Source owner handoff');
$guest=$c::offer(new WP_REST_Request($offerParams));
verify_handoff($guest instanceof WP_Error
 && $guest->get_error_code()==='handoff_unauthenticated'
 && $GLOBALS['handoff_locks']===0,'Anonymous cannot obtain task lock');
$GLOBALS['handoff_actor']=3;
$unauthorized=$c::offer(new WP_REST_Request($offerParams));
verify_handoff($unauthorized instanceof WP_Error
 && $unauthorized->get_error_code()==='handoff_actor_denied',
 'Logged-in unrelated company user must not transfer another assignee task');
$GLOBALS['handoff_actor']=1;
$offer=$c::offer(new WP_REST_Request($offerParams));
verify_handoff(is_array($offer)
 && $offer['revision']===1
 && $offer['pending']['from']===1 && $offer['pending']['to']===2
 && $offer['replay']===false
 && array_map(static function($p){return $p->ID;},$task->assignees)===array(1),
 'A pending offer must not transfer ownership before recipient consent');
$duplicate=$c::offer(new WP_REST_Request($offerParams));
verify_handoff(is_array($duplicate) && $duplicate['replay']===true
 && $duplicate['revision']===1,'Exact replay cannot duplicate transfer');
$conflict=$c::offer(new WP_REST_Request(array_replace($offerParams,
 array('request_id'=>'offer_request_0002','expected_revision'=>0))));
verify_handoff($conflict instanceof WP_Error
 && $conflict->get_error_code()==='handoff_revision_conflict',
 'Stale CAS must not replace pending offer');
$readAsTarget=null;
$GLOBALS['handoff_actor']=2;
$readAsTarget=$c::view(new WP_REST_Request(array('id'=>101)));
verify_handoff(is_array($readAsTarget) && $readAsTarget['pending']['to']===2,
 'Intended recipient can read exact task offer');
$GLOBALS['handoff_actor']=3;
verify_handoff($c::view(new WP_REST_Request(array('id'=>101)))->get_error_code()==='handoff_access_denied',
 'Unrelated logged-in user cannot inspect handoff');
$badAccept=$c::accept(new WP_REST_Request(array('id'=>101,'request_id'=>'accept_req_0001',
 'expected_revision'=>1)));
verify_handoff($badAccept instanceof WP_Error
 && $badAccept->get_error_code()==='handoff_accept_denied',
 'Only invited recipient may accept');
$GLOBALS['handoff_actor']=2;
$acceptInput=array('id'=>101,'request_id'=>'accept_req_0001',
 'expected_revision'=>1);
$accepted=$c::accept(new WP_REST_Request($acceptInput));
verify_handoff(is_array($accepted)
 && $accepted['revision']===2 && $accepted['pending']===null
 && $accepted['assignee_ids']===array(2)
 && $accepted['replay']===false,
 'Exact successful acceptance must add recipient then remove source');
$acceptReplay=$c::accept(new WP_REST_Request($acceptInput));
verify_handoff(is_array($acceptReplay)&&$acceptReplay['replay']===true
 && $acceptReplay['revision']===2,
 'Accepted transfer cannot replay a second native assignment');
$badBoard=new \FluentBoards\App\Models\Task(102,999,array(2));
\FluentBoards\App\Models\Task::$inventory[102]=$badBoard;
$wrong=$c::view(new WP_REST_Request(array('id'=>102)));
verify_handoff($wrong instanceof WP_Error
 && $wrong->get_error_code()==='handoff_board_denied',
 'Cross-tenant board identity must always fail');
$before=array_map(static function($p){return $p->ID;},$task->assignees);
$offer2=$c::offer(new WP_REST_Request(array('id'=>101,'request_id'=>'offer_request_0003',
 'expected_revision'=>2,'from_user_id'=>2,'to_user_id'=>1)));
verify_handoff(is_array($offer2) && $offer2['revision']===3,
 'Transfer back requires a fresh source-owner offer');
$GLOBALS['handoff_actor']=1;
\FluentBoards\App\Services\TaskService::$fail_when_removing=2;
$failed=$c::accept(new WP_REST_Request(array('id'=>101,'request_id'=>'accept_req_0002',
 'expected_revision'=>3)));
verify_handoff($failed instanceof WP_Error
 && $failed->get_error_code()==='handoff_transfer_failed'
 && array_map(static function($p){return $p->ID;},$task->assignees)===$before
 && $task->getMeta($c::META)['revision']===3,
 'Provider failure must compensate recipients, preserve owner and pending offer');
\FluentBoards\App\Services\TaskService::$fail_when_removing=0;
$rejected=$c::reject(new WP_REST_Request(array('id'=>101,'request_id'=>'reject_req_0002',
 'expected_revision'=>3)));
verify_handoff(is_array($rejected) && $rejected['revision']===4
 && $rejected['pending']===null
 && $rejected['assignee_ids']===array(2),
 'Recipient rejection must resolve offer without moving native ownership');
$task->fail_next_meta_update=true;
$GLOBALS['handoff_actor']=2;
$unpersisted=$c::offer(new WP_REST_Request(array('id'=>101,'request_id'=>'offer_failmeta_0001',
 'expected_revision'=>4,'from_user_id'=>2,'to_user_id'=>1)));
verify_handoff($unpersisted instanceof WP_Error
 && $unpersisted->get_error_code()==='handoff_persistence_unconfirmed'
 && $task->getMeta($c::META)['revision']===4
 && $task->getMeta($c::META)['pending']===null,
 'Native updateMeta failure must NOT return success or increment durable revision');
// The vendor may successfully update assignments and then fail to persist
// the transfer receipt. That must NOT return success or strand the old owner.
$validAfterFailure=$c::offer(new WP_REST_Request(array(
 'id'=>101,'request_id'=>'offer_valid_0005','expected_revision'=>4,
 'from_user_id'=>2,'to_user_id'=>1)));
verify_handoff(is_array($validAfterFailure)
 && $validAfterFailure['revision']===5
 && $validAfterFailure['pending']['from']===2,
 'A valid offer after a failed save must use the original durable revision');
$GLOBALS['handoff_actor']=1;
$task->fail_next_meta_update=true;
$failedAcceptance=$c::accept(new WP_REST_Request(array(
 'id'=>101,'request_id'=>'accept_failmeta_001','expected_revision'=>5)));
verify_handoff($failedAcceptance instanceof WP_Error
 && $failedAcceptance->get_error_code()==='handoff_persistence_unconfirmed'
 && $task->getMeta($c::META)['revision']===5
 && $task->getMeta($c::META)['pending']['to']===1
 && array_map(static function($p){return $p->ID;},$task->assignees)===array(2),
 'No success without receipt: compensate assignments when native meta write fails');
verify_handoff($GLOBALS['handoff_locks']===$GLOBALS['handoff_releases'],
 'Every native handoff CAS lock must release on success and error');
echo "PASS: NATIVE_TASK_HANDOFF_CAS REST privacy, CAS/replay, recipient consent, provider compensation\n";
}
