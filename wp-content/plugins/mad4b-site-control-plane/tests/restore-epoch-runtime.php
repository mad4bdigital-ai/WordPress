<?php
$root=sys_get_temp_dir().'/mad4b-restore-epoch-'.preg_replace('/[^a-z0-9]/i','',uniqid('',true));
$wordpress=$root.'/wordpress/';
$external=$root.'/secure/restore-authority-epoch.json';
mkdir($wordpress,0700,true);
define('ABSPATH',$wordpress);
define('MAD4B_SCP_RESTORE_EPOCH_PATH',$external);
$GLOBALS['options']=array();
$GLOBALS['write_enabled']=true;
$GLOBALS['admin']=true;

class WP_Error{private $code;private $data;function __construct($c,$m='',$d=array()){$this->code=$c;$this->data=$d;}function get_error_code(){return $this->code;}function get_error_data(){return $this->data;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['options'])?$GLOBALS['options'][$k]:$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function current_user_can($cap){return !empty($GLOBALS['admin']);}
final class MAD4B_SCP_Site_Profile{
 public static $site_uuid_for_test='123e4567-e89b-42d3-a456-426614174000';
 static function site_uuid(){return self::$site_uuid_for_test;}
 static function origin_enrolled(){return true;}
 static function write_enabled(){return !empty($GLOBALS['write_enabled']);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-restore-epoch.php';

$fail=static function($m,$d=null)use($root){fwrite(STDERR,"FAIL restore-epoch-runtime: {$m}".($d?' '.json_encode($d):'')."\n");@unlink(MAD4B_SCP_RESTORE_EPOCH_PATH);@rmdir(dirname(MAD4B_SCP_RESTORE_EPOCH_PATH));@rmdir(ABSPATH);@rmdir($root);exit(1);};
$check=static function($c,$m,$d=null)use($fail){if(!$c)$fail($m,$d);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};

$initial=MAD4B_SCP_Restore_Epoch::ensure_bound();
$check(is_array($initial)&&1===(int)$initial['epoch'],'initial external epoch did not bind',$initial);
$stale_binding=$GLOBALS['options'][MAD4B_SCP_Restore_Epoch::OPTION];
$initial_external=file_get_contents(MAD4B_SCP_RESTORE_EPOCH_PATH);
$check(is_string($initial_external)&&''!==$initial_external,'initial external epoch record could not be captured');

// Half-present state must never be treated as a fresh install. Binding-only
// represents lost external monotonic state and therefore remains quarantined.
unlink(MAD4B_SCP_RESTORE_EPOCH_PATH);
MAD4B_SCP_Restore_Epoch::reset_request_cache();
$binding_only=MAD4B_SCP_Restore_Epoch::status(true,true);
$check(empty($binding_only['ready'])&&in_array('restore_epoch_external_missing',$binding_only['blockers'],true),'binding-only restore state auto-initialized or failed open',$binding_only);
$check(!file_exists(MAD4B_SCP_RESTORE_EPOCH_PATH),'binding-only restore state recreated external epoch unexpectedly');
file_put_contents(MAD4B_SCP_RESTORE_EPOCH_PATH,$initial_external);
chmod(MAD4B_SCP_RESTORE_EPOCH_PATH,0600);

// External-only represents a restored/lost database binding and must also stay
// quarantined. Initialization is legal only when both stores are truly empty.
unset($GLOBALS['options'][MAD4B_SCP_Restore_Epoch::OPTION]);
MAD4B_SCP_Restore_Epoch::reset_request_cache();
$external_only=MAD4B_SCP_Restore_Epoch::status(true,true);
$check(empty($external_only['ready'])&&in_array('restore_epoch_database_binding_missing',$external_only['blockers'],true),'external-only restore state auto-bound or failed open',$external_only);
$check(!isset($GLOBALS['options'][MAD4B_SCP_Restore_Epoch::OPTION]),'external-only restore state recreated database binding unexpectedly');
$GLOBALS['options'][MAD4B_SCP_Restore_Epoch::OPTION]=$stale_binding;
MAD4B_SCP_Restore_Epoch::reset_request_cache();
$restored_initial=MAD4B_SCP_Restore_Epoch::status(false,true);
$check(!empty($restored_initial['ready'])&&1===(int)$restored_initial['epoch'],'fixture could not restore initial epoch after half-present tests',$restored_initial);

$original_site_uuid='123e4567-e89b-42d3-a456-426614174000';
$foreign_site_uuid='123e4567-e89b-42d3-a456-426614174099';
MAD4B_SCP_Site_Profile::$site_uuid_for_test=$foreign_site_uuid;
MAD4B_SCP_Restore_Epoch::reset_request_cache();
$foreign_clone=MAD4B_SCP_Restore_Epoch::status(false,true);
$check(empty($foreign_clone['ready'])&&in_array('restore_epoch_site_identity_mismatch',$foreign_clone['blockers'],true),'copied restore epoch was accepted on a foreign Site UUID',$foreign_clone);
MAD4B_SCP_Site_Profile::$site_uuid_for_test=$original_site_uuid;
MAD4B_SCP_Restore_Epoch::reset_request_cache();

$advanced=MAD4B_SCP_Restore_Epoch::advance('governed_execution');
$check(is_array($advanced)&&2===(int)$advanced['epoch'],'epoch did not advance before execution',$advanced);
$current_binding=$GLOBALS['options'][MAD4B_SCP_Restore_Epoch::OPTION];
$check($stale_binding['external_record_sha256']!==$current_binding['external_record_sha256'],'epoch advance did not rotate external identity');

$GLOBALS['options'][MAD4B_SCP_Restore_Epoch::OPTION]=$stale_binding;
MAD4B_SCP_Restore_Epoch::reset_request_cache();
$restored=MAD4B_SCP_Restore_Epoch::status(false,true);
$check(empty($restored['ready'])&&in_array('restore_epoch_database_snapshot_detected',$restored['blockers'],true),'stale database snapshot was not quarantined',$restored);
$denied=MAD4B_SCP_Restore_Epoch::ensure_bound();
$check('mad4b_restore_epoch_not_ready'===$code($denied),'stale database snapshot auto-healed or failed open',$denied);

$external_record=json_decode(file_get_contents(MAD4B_SCP_RESTORE_EPOCH_PATH),true);
$ack_while_write=MAD4B_SCP_Restore_Epoch::acknowledge_restore_after_quarantine($external_record['external_record_sha256']);
$check('mad4b_restore_epoch_write_must_be_disabled'===$code($ack_while_write),'restore acknowledgement was allowed while write authority stayed enabled');

$GLOBALS['write_enabled']=false;
$stale_ack=MAD4B_SCP_Restore_Epoch::acknowledge_restore_after_quarantine(str_repeat('0',64));
$check('mad4b_restore_epoch_acknowledgement_stale'===$code($stale_ack),'stale restore acknowledgement was accepted');
$ack=MAD4B_SCP_Restore_Epoch::acknowledge_restore_after_quarantine($external_record['external_record_sha256']);
$check(is_array($ack)&&!empty($ack['ready'])&&2===(int)$ack['epoch'],'write-disabled restore acknowledgement did not rebind exact external epoch',$ack);
$check(empty($GLOBALS['write_enabled']),'restore acknowledgement unexpectedly re-enabled governed write');

@unlink(MAD4B_SCP_RESTORE_EPOCH_PATH);@rmdir(dirname(MAD4B_SCP_RESTORE_EPOCH_PATH));@rmdir(ABSPATH);@rmdir($root);
echo "mad4b.restore-authority-epoch.runtime.v1: PASS\n";
