<?php
/**
 * Isolated read-only regression using the real Agent_Registry and Policy.
 * No WordPress database, runtime credential, grant mutation or user account.
 */
define('ABSPATH',__DIR__);
define('ARRAY_A','ARRAY_A');
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=array()){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\\-]/','',(string)$s));}
$GLOBALS['env']='staging';
$GLOBALS['ready']=true;
$GLOBALS['production_write_approved']=false;
$GLOBALS['query_rows']=array();
$GLOBALS['prepare_args']=array();
function wp_get_environment_type(){return $GLOBALS['env'];}
class MAD4B_SCP_Environment {
    public static function effective(){return $GLOBALS['env'];}
}
class MAD4B_SCP_Schema {
    public static function tables(){return array('grants'=>'wp_mad4b_grants');}
}
class MAD4B_SCP_Site_Profile {
    public static function configured(){return true;}
    public static function governed_write_ready(){return $GLOBALS['ready'];}
    public static function current_environment(){return $GLOBALS['env'];}
}
class MAD4B_SCP_Governed_Runtime_Gates {
    public static function production_mutation_enabled(){return $GLOBALS['production_write_approved'];}
    public static function production_auto_enable(){return false;}
}
class FakeDB {
    public function prepare($sql,...$args){
        $GLOBALS['prepare_args']=$args;
        $GLOBALS['prepared_sql']=$sql;
        return $sql;
    }
    public function get_results($sql,$mode){return $GLOBALS['query_rows'];}
}
$GLOBALS['wpdb']=new FakeDB();
require dirname(__DIR__).'/includes/class-mad4b-scp-agent-registry.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-policy.php';
function check($ok,$description) {
    if(!$ok){fwrite(STDERR,"FAIL: ".$description."\n");exit(1);}
}
function grant($effect,$env='staging') {
    return array('id'=>1,'agent_id'=>7,'server_id'=>'mad4b-write',
        'ability_name'=>'mad4b/approval-plan','provider'=>'core','effect'=>$effect,
        'environment'=>$env);
}
$grant_args=array(7,'mad4b-write','mad4b/approval-plan','core','staging');
$GLOBALS['query_rows']=array();
$missing=MAD4B_SCP_Agent_Registry::exact_grant(7,'mad4b-write','mad4b/approval-plan','core');
check(is_wp_error($missing)&&$missing->get_error_code()==='mad4b_nhi_grant_missing','missing grant denied');
check($GLOBALS['prepare_args']===$grant_args,'SQL lookup binds exact principal server ability provider and environment');
check(strpos($GLOBALS['prepared_sql'],"environment IN ('all',%s)")!==false,'read scope bounded to exact environment or all');
$GLOBALS['query_rows']=array(grant('allow'));
$granted=MAD4B_SCP_Agent_Registry::exact_grant(7,'mad4b-write','mad4b/approval-plan','core');
check(is_array($granted)&&$granted['effect']==='allow','exact grant accepted');
$GLOBALS['query_rows']=array(grant('allow'),grant('deny'));
$denied=MAD4B_SCP_Agent_Registry::exact_grant(7,'mad4b-write','mad4b/approval-plan','core');
check(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_nhi_grant_denied','explicit deny precedes allow');
$gate=MAD4B_SCP_Policy::mutation_gate_status();
check($gate['effective']===true&&$gate['environment']==='staging','staging mutation gate follows site profile');
$GLOBALS['ready']=false;
$gate=MAD4B_SCP_Policy::mutation_gate_status();
check($gate['effective']===false,'unready staging site cannot mutate');
$GLOBALS['ready']=true;
$GLOBALS['env']='production';
$gate=MAD4B_SCP_Policy::mutation_gate_status();
check($gate['effective']===false,'production requires dedicated governed runtime gate');
$GLOBALS['production_write_approved']=true;
$gate=MAD4B_SCP_Policy::mutation_gate_status();
check($gate['effective']===true&&$gate['environment']==='production','approved production risk gate is supported');
$GLOBALS['ready']=false;
$gate=MAD4B_SCP_Policy::mutation_gate_status();
check($gate['effective']===false,'production also requires enrolled site readiness');
echo "PASS: exact grant, deny precedence, scoped lookup and risk-aware Staging/Production policy (10 checks)\n";
