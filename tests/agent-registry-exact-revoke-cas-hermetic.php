<?php
/**
 * Tests the real original Agent Registry conditional DELETE without WordPress
 * or MySQL. All rows are fake, in memory; no actual grant can be modified.
 */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
$GLOBALS['qa_events']=array();
class WP_Error {
    private $code; private $data;
    public function __construct($code,$msg='',$data=null){$this->code=$code;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function do_action($name,$args=array()){$GLOBALS['qa_events'][]=array($name,$args);}
class MAD4B_SCP_Schema {
    public static function tables(){return array('agents'=>'wp_test_agents','grants'=>'wp_test_grants');}
}
class FakeGrantDB {
    public $rows=array();public $last_error='';public $force_error=false;
    public $delete_count=0;public $last_where=array();
    public function prepare($sql,...$args){return $sql;}
    public function get_row($sql,$mode=null){
        return array('id'=>7,'public_id'=>'test-owner-agent','slug'=>'test-owner-agent');
    }
    public function delete($table,$where,$formats){
        if($table!=='wp_test_grants')throw new RuntimeException('Foreign table deletion');
        ++$this->delete_count;$this->last_where=$where;
        if(count($where)!==count($formats))
            throw new RuntimeException('Unsafe DB where/format mismatch');
        if($this->force_error){$this->last_error='simulated write error';return false;}
        foreach($this->rows as $i=>$row){
            $matches=true;
            foreach($where as $key=>$expected){
                if(!array_key_exists($key,$row)||(string)$row[$key] !== (string)$expected)
                    $matches=false;
            }
            if($matches){unset($this->rows[$i]);return 1;}
        }
        return 0;
    }
}
$wpdb=new FakeGrantDB();
function qa_grant($id=17){
    return array('id'=>$id,'agent_id'=>7,'effect'=>'allow',
        'server_id'=>'mad4b-write','ability_name'=>'mad4b/old',
        'provider'=>'core','environment'=>'production');
}
function qa_reset(){
    global $wpdb;
    $wpdb->rows=array(qa_grant());$wpdb->force_error=false;
    $wpdb->delete_count=0;$wpdb->last_where=array();
    $GLOBALS['qa_events']=array();
}
function qa_assert($yes,$msg){
    if(!$yes)throw new RuntimeException('FAIL AGENT CAS: '.$msg);
}
function qa_expect_error($v,$code){
    qa_assert(is_wp_error($v)&&$v->get_error_code()===$code,'expected '.$code);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-agent-registry.php';
$c='MAD4B_SCP_Agent_Registry';
$expected=array('ability_name'=>'mad4b/old','provider'=>'core','environment'=>'production');
qa_reset();
$res=$c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write',array('ability_name'=>'mad4b/old'));
qa_expect_error($res,'mad4b_grant_compare_invalid');
qa_assert($wpdb->delete_count===0,'partial expected object must not attempt deletion');
qa_reset();
$res=$c::revoke_allow_grant_by_id('test-owner-agent',17,'', $expected);
qa_expect_error($res,'mad4b_grant_compare_invalid');
qa_assert($wpdb->delete_count===0,'missing exact server is not a CAS');
qa_reset();
$res=$c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write',array_merge($expected,array('effect'=>'deny')));
qa_expect_error($res,'mad4b_grant_compare_invalid');
qa_assert($wpdb->delete_count===0,'caller may not select extra compare fields');
qa_reset();
$wrong=$expected;$wrong['provider']='foreign';
$res=$c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write',$wrong);
qa_expect_error($res,'mad4b_grant_changed_before_revoke');
qa_assert(count($wpdb->rows)===1 && !$GLOBALS['qa_events'],
    'provider change must not delete or emit success');
qa_reset();
$wrong=$expected;$wrong['environment']='staging';
qa_expect_error($c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write',$wrong),
    'mad4b_grant_changed_before_revoke');
qa_assert(count($wpdb->rows)===1,'environment mismatch not rejected');
qa_reset();
$wrong=$expected;$wrong['ability_name']='mad4b/other';
qa_expect_error($c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write',$wrong),
    'mad4b_grant_changed_before_revoke');
qa_assert(count($wpdb->rows)===1,'ability mismatch not rejected');
qa_reset();
$wpdb->rows[0]['effect']='deny';
qa_expect_error($c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write',$expected),
    'mad4b_grant_changed_before_revoke');
qa_assert(count($wpdb->rows)===1,'explicit deny grant must never be revoked');
qa_reset();
$wpdb->force_error=true;
qa_expect_error($c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write',$expected),
    'mad4b_grant_revoke_failed');
qa_assert(count($wpdb->rows)===1,'write error must not delete');
qa_reset();
qa_assert($c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write',$expected)===true,
    'valid exact delete failed');
qa_assert(!$wpdb->rows && count($GLOBALS['qa_events'])===1
    && $GLOBALS['qa_events'][0][0]==='mad4b_provider_authority_changed',
    'successful CAS must remove exactly one grant and emit invalidation');
qa_assert(array_keys($wpdb->last_where)===array('id','agent_id','effect','server_id',
    'ability_name','provider','environment'),'CAS WHERE must cover full grant fingerprint');
qa_reset();
qa_assert($c::revoke_allow_grant_by_id('test-owner-agent',17,'mad4b-write')===true
    && !$wpdb->rows,'legacy three-parameter callers must still work');
qa_reset();
qa_assert($c::revoke_allow_grant_by_id('test-owner-agent',999,'mad4b-write')===true
    && count($wpdb->rows)===1,
    'legacy zero-row idempotent behavior must remain compatible');
echo "PASS: AGENT_REGISTRY_EXACT_REVOKE_CAS 11 conditional deletion scenarios\n";
