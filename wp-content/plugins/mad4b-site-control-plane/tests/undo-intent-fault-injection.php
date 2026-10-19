<?php
/** Standalone bounded DB fault injection for journal-first governed undo. */
define('ABSPATH', __DIR__);
class WP_Error {
    private $code; private $data;
    public function __construct($code,$message='',$data=array()){$this->code=$code;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($data,$flags=0){return json_encode($data,$flags);}
function wp_generate_uuid4(){static $id=0;return sprintf('00000000-0000-4000-8000-%012d',++$id);}
function get_current_user_id(){return 1;}
function apply_filters($tag,$default){return $default;}
class MAD4B_SCP_Adapter_Base {}
class UndoFixtureAdapter extends MAD4B_SCP_Adapter_Base {
    public $value=array('v'=>'after');public $restore_calls=0;public $fail_restore=false;public $bad_readback=false;
    public function id(){return 'ci-fixture';}
    public function reversible_contract_for($name){return 'ci.restore.v1';}
    public function status(){return array('mutation_requires_certification'=>false);}
    public function read_reversible_state($name,$target){return $this->bad_readback?array('v'=>'unexpected'):$this->value;}
    public function acquire_reversible_undo_scope($name,$target){return array('token'=>'fixture');}
    public function release_reversible_scope($scope){}
    public function restore_reversible_state($name,$target,$state,$record){
        global $wpdb;
        $children=array_filter($wpdb->rows,static function($r){return ($r['parent_mutation_id']??null)==='original';});
        if(($wpdb->rows['original']['status']??'')!=='undo_pending'||count($children)!==1
            ||(reset($children)['status']??'')!=='executing')
            throw new RuntimeException('restore attempted before durable parent+child undo intent');
        $this->restore_calls++;
        if($this->fail_restore)return new WP_Error('ci_restore_failed','failure injection');
        $this->value=$state;return true;
    }
}
class UndoFixtureDB {
    public $rows=array();public $fail_insert=false;public $fail_claim=false;public $fail_child_commit=false;
    public function insert($table,$values,$formats){
        if(count($values)!==count($formats))throw new RuntimeException('mutation insert format mismatch');
        if($this->fail_insert)return false;
        $id=$values['mutation_id'];if(isset($this->rows[$id]))return false;
        $this->rows[$id]=$values;return 1;
    }
    public function update($table,$values,$where,$formats,$where_formats){
        if(count($values)!==count($formats)||count($where)!==count($where_formats))
            throw new RuntimeException('mutation update format mismatch');
        $id=$where['mutation_id']??'';if(!isset($this->rows[$id]))return 0;
        foreach($where as $key=>$v)if(($this->rows[$id][$key]??null)!==$v)return 0;
        if($this->fail_claim && $id==='original' && ($values['status']??'')==='undo_pending')return 0;
        if($this->fail_child_commit && $id!=='original' && ($values['status']??'')==='undone')return false;
        $this->rows[$id]=array_merge($this->rows[$id],$values);return 1;
    }
}
class MAD4B_SCP_Mutation_Manager {
    public static function get($id){global $wpdb;return $wpdb->rows[$id]??null;}
}
class MAD4B_SCP_Schema {
    public static function tables(){return array('mutations'=>'wp_ci_mutations');}
}
class MAD4B_SCP_Adapter_Registry {
    public static $adapter;
    public static function instance(){return new self();}
    public function get($id){return self::$adapter;}
}
class MAD4B_SCP_Policy {public static function can_mutate(){return true;}}
class MAD4B_SCP_Identity_Context {
    public static function current(){return array('subject_type'=>'ci','subject_fingerprint'=>str_repeat('a',64),'request_id'=>'ci-test','approval_ticket_id'=>'ci-ticket');}
    public static function request_id(){return 'ci-test';}
}
class MAD4B_SCP_Agent_Registry {
    public static function resolve_agent($identity){return array('id'=>7);}
}
class MAD4B_SCP_Audit {public static function record($type,$payload,$status='success'){}}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-reversible-adapter-mutations.php';
function test_assert($ok,$label){if(!$ok)throw new RuntimeException('FAIL undo fault injection: '.$label);}
foreach(array('success','claim_fail','insert_fail','restore_fail','bad_readback','child_commit_fail')as$case){
    $wpdb=new UndoFixtureDB();
    $adapter=new UndoFixtureAdapter();MAD4B_SCP_Adapter_Registry::$adapter=$adapter;
    $rollback=array('contract'=>MAD4B_SCP_Reversible_Adapter_Mutations::ROLLBACK_CONTRACT,
      'adapter_id'=>'ci-fixture','ability'=>'ci/write','restore_contract'=>'ci.restore.v1',
      'target'=>array('id'=>'record'),'state'=>array('v'=>'before'));
    $json=wp_json_encode($rollback,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $wpdb->rows['original']=array(
      'mutation_id'=>'original','reversible'=>1,'status'=>'verified',
      'undo_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600),
      'rollback_payload'=>$json,'rollback_payload_sha256'=>hash('sha256',$json),
      'ability_name'=>'ci/write','agent_id'=>7,'provider'=>'ci-fixture','provider_version'=>'1',
      'target_type'=>'ci-object','target_id'=>'record',
      'before_sha256'=>hash('sha256',wp_json_encode(array('v'=>'before'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
      'after_sha256'=>hash('sha256',wp_json_encode(array('v'=>'after'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))
    );
    $wpdb->fail_claim=$case==='claim_fail';
    $wpdb->fail_insert=$case==='insert_fail';
    $wpdb->fail_child_commit=$case==='child_commit_fail';
    $adapter->fail_restore=$case==='restore_fail';$adapter->bad_readback=$case==='bad_readback';
    $result=MAD4B_SCP_Reversible_Adapter_Mutations::undo('original');
    $children=array_values(array_filter($wpdb->rows,static function($row){return ($row['parent_mutation_id']??null)==='original';}));
    if($case==='success'){
        test_assert(is_array($result)&&($result['status']??'')==='undone','happy path failed');
        test_assert(($wpdb->rows['original']['status']??'')==='undone' && count($children)===1
            && $children[0]['status']==='undone' && $adapter->value===array('v'=>'before'),
            'successful undo missing exact child journal/readback');
    } else {
        test_assert(is_wp_error($result),'fault must return WP_Error: '.$case);
        if($case==='bad_readback')test_assert($result->get_error_code()==='mad4b_undo_verification_failed',
            'legacy verification failure API must retain its error code');
        test_assert($adapter->restore_calls===($case==='claim_fail'||$case==='insert_fail'?0:1),'unsafe restore count: '.$case);
        test_assert(($wpdb->rows['original']['status']??'')===($case==='claim_fail'?'verified':'undo_pending'),
            'parent must be a durable undo fence: '.$case);
        test_assert($case==='claim_fail'||is_wp_error(MAD4B_SCP_Reversible_Adapter_Mutations::undo('original')),
            'blind retry must be denied: '.$case);
        if($case==='restore_fail'||$case==='bad_readback'||$case==='child_commit_fail')
            test_assert(count($children)===1&&$children[0]['status']==='executing',
                'uncertain child journal cannot be reported completed');
    }
}
echo "PASS undo intent original CAS, child-before-restore, retry fencing and injected DB/provider failures\n";
