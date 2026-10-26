<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH','/tmp/isolated-wordpress/');
define('MAD4B_SCP_RESTORE_EPOCH_PATH','/tmp/mad4b-recovery-e2e-'.getmypid().'/epoch.json');
class WP_Error {private $c;function __construct($c,$m,$d=[]){$this->c=$c;}function get_error_code(){return $this->c;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($x){return $x==='manage_options';}
function get_option($k,$default=[]){global $options;return $options[$k]??$default;}
function update_option($k,$v,$autoload=false){global $options;$options[$k]=$v;return true;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s));}
function wp_get_environment_type(){return 'local';}
class MAD4B_SCP_Site_Profile {static function write_enabled(){return false;}static function origin_enrolled(){return true;}static function site_uuid(){return '22222222-2222-4222-8222-222222222222';}}
class MAD4B_SCP_Time_Policy {static function now_epoch(){return time();}}
class MAD4B_SCP_Entropy {static function hex($s,$n){return bin2hex(random_bytes($n));}}
class MAD4B_SCP_Schema {static function tables(){return ['approvals'=>'qa_approvals','idempotency'=>'qa_idempotency','work_leases'=>'qa_work_leases'];}}
class MAD4B_SCP_Database_Transaction_Guard {static $log=[];static function begin($s,$t,$w){self::$log[]='begin';return ['test'=>true];}static function commit($tx){self::$log[]='commit';return true;}static function rollback($tx){self::$log[]='rollback';return true;}}
class FakeDB {public $last_error='';public $queries=[];function prepare($q,...$vals){foreach($vals as $v)$q=preg_replace('/%s/',"'".addslashes($v)."'",$q,1);return $q;}function query($q){$this->queries[]=$q;return 1;}}
$wpdb=new FakeDB();
$options=['mad4b_scp_restore_authority_epoch_binding_v1'=>['contract'=>'mad4b.restore-authority-epoch-binding.v1','site_uuid'=>'11111111-1111-4111-8111-111111111111','epoch'=>3,'nonce_sha256'=>str_repeat('b',64),'external_record_sha256'=>str_repeat('c',64)]];
require __DIR__ . '/../includes/class-mad4b-scp-restore-epoch.php';
$prior=str_repeat('c',64);
$r=MAD4B_SCP_Restore_Epoch::recover_from_database_binding($prior,true);
$ready=is_array($r)&&!empty($r['ready']);
$status=MAD4B_SCP_Restore_Epoch::status(false,true);
$again=MAD4B_SCP_Restore_Epoch::recover_from_database_binding($prior,true);
$tests=['E2E_RECOVERY'=>$ready,'RECONCILIATION'=>count($wpdb->queries)===3&&MAD4B_SCP_Database_Transaction_Guard::$log===['begin','commit'],'EPOCH_READBACK'=>!empty($status['ready'])&&$status['epoch']===4,'BINDING'=>($options[MAD4B_SCP_Restore_Epoch::OPTION]['epoch']??0)===4,'NO_REPLAY'=>is_wp_error($again)&&$again->get_error_code()==='mad4b_restore_recovery_evidence_conflict','NO_WRITE_GRANT'=>$ready&&!$r['write_granted']&&!$r['publication_authorized']];
foreach($tests as $k=>$v)echo $k.'='.($v?'PASS':'FAIL').PHP_EOL;
$path=MAD4B_SCP_RESTORE_EPOCH_PATH;@unlink($path);@rmdir(dirname($path));
echo 'SUMMARY='.count(array_filter($tests)).'/'.count($tests).PHP_EOL;
exit(in_array(false,$tests,true)?1:0);
