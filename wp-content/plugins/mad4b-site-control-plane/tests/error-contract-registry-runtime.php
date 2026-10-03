<?php
$tmp=sys_get_temp_dir().'/mad4b-error-contract-'.preg_replace('/[^a-z0-9]/i','',uniqid('',true));
@mkdir($tmp.'/config',0777,true);
define('ABSPATH',__DIR__);
define('MAD4B_SCP_DIR',$tmp.'/');
copy(dirname(__DIR__).'/config/error-reason-codes.json',$tmp.'/config/error-reason-codes.json');
class WP_Error{
 private $c,$m,$d;
 function __construct($c='',$m='',$d=null){$this->c=(string)$c;$this->m=(string)$m;$this->d=$d;}
 function get_error_code(){return$this->c;}
 function get_error_message(){return$this->m;}
 function get_error_data(){return$this->d;}
 function add_data($d,$c=''){$this->d=$d;}
}
function is_wp_error($v){return$v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
$GLOBALS['filters']=array();
function add_filter($tag,$callback,$priority=10,$accepted_args=1){$GLOBALS['filters'][]=array($tag,$callback,$priority,$accepted_args);return true;}
require dirname(__DIR__).'/includes/class-mad4b-scp-error-contract-registry.php';
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL error-contract-registry-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};

MAD4B_SCP_Error_Contract_Registry::boot();
$check(count($GLOBALS['filters'])===1&&195===$GLOBALS['filters'][0][2],'error wrapper did not register at stable priority',$GLOBALS['filters']);
$status=MAD4B_SCP_Error_Contract_Registry::status();
$check(is_array($status)&&1===$status['schema_version']&&1===preg_match('/^[a-f0-9]{64}$/',$status['registry_sha256']),'registry status invalid',$status);

$original=new WP_Error('mad4b_remote_work_reconciliation_required','human text',array('cause_code'=>'provider_timeout'));
$enriched=MAD4B_SCP_Error_Contract_Registry::enrich_wp_error($original);
$data=$enriched->get_error_data();
$check('provider_timeout'===$data['cause_code'],'existing structured cause was lost',$data);
$check('mad4b.error-response.v1'===$data['mad4b_error']['contract'],'response contract missing',$data);
$check('remote_work'===$data['mad4b_error']['family'],'error family mismatch',$data);
$check('reconcile_before_retry'===$data['mad4b_error']['retry_class'],'exact retry semantics missing',$data);
$check(false===$data['mad4b_error']['message_is_contractual']&&!$data['mad4b_error']['authorizing'],'error metadata became authorizing',$data);

$args=array(
 'permission_callback'=>static function($input=null){return new WP_Error('mad4b_write_dispatch_schema_drift','do not parse me');},
 'execute_callback'=>static function($input=null){return new WP_Error('mad4b_unknown_future_code','future text');},
 'meta'=>array()
);
$wrapped=MAD4B_SCP_Error_Contract_Registry::wrap_ability_args($args,'mad4b/test');
$p=call_user_func($wrapped['permission_callback'],array());
$e=call_user_func($wrapped['execute_callback'],array());
$check('dispatch'===$p->get_error_data()['mad4b_error']['family'],'permission callback was not machine-normalized',$p->get_error_data());
$check('generic_mad4b'===$e->get_error_data()['mad4b_error']['family'],'unknown future code did not preserve generic machine family',$e->get_error_data());
$check('mad4b.error-response.v1'===$wrapped['meta']['mcp']['mad4b_error_contract'],'ability metadata lacks response contract',$wrapped['meta']);

@unlink($tmp.'/config/error-reason-codes.json');@rmdir($tmp.'/config');@rmdir($tmp);
echo "mad4b.error-contract-registry.runtime.v1: PASS\n";
