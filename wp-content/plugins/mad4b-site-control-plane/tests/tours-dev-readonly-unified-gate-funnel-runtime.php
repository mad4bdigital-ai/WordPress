<?php
/** Gate plan can report blockers, but never approve or execute a write. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');
$GLOBALS['extra_gate']=null;
function apply_filters($hook,$value){return $hook==='mad4b_scp_unified_gate_registry'&&$GLOBALS['extra_gate']!==null?$GLOBALS['extra_gate']:$value;}
function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower($x));}
function wp_json_encode($x){return json_encode($x);}
class MAD4B_SCP_Context_Authority {public static function status(){return array('ready'=>true,'site_uuid'=>'test-only-uuid');}public static function review_policy(){return array('mode'=>'strict');}}
class MAD4B_SCP_Schema {public static function critical_ready(){return true;}}
function ok($pass,$label){if(!$pass){fwrite(STDERR,'FAIL: '.$label."\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-unified-gate-funnel.php';
$c='MAD4B_SCP_Unified_Gate_Funnel';$p=$c::plan();
ok($p['gate_count']>=6&&$p['mode']==='read_only'&&$p['execution_authorized']===false&&$p['approval_authorized']===false,'Read-only dependency plan');
$r=$c::readiness(array('content.execution'=>true));
ok($r['readiness']==='blocked'&&$r['execution_authorized']===false,'Caller cannot force execution readiness');
ok(in_array('content.execution',$r['mandatory_blockers'],true),'Execution gate remains blocked even if caller asserts true');
$r=$c::readiness(array('brand.source'=>false));ok(in_array('brand.source',$r['mandatory_blockers'],true),'Caller may only close known gates');
$GLOBALS['extra_gate']=array('bad.id'=>array('surface'=>'content_generation','requires'=>array('unknown.dep')));
try{$c::plan();fwrite(STDERR,"FAIL: missing dependency accepted\n");exit(1);}catch(DomainException $e){ok($e->getMessage()==='gate_dependency_missing','Missing predecessor denied');}
$GLOBALS['extra_gate']=array('abc'=>array('surface'=>'content_generation','requires'=>array('abc')));
try{$c::plan();fwrite(STDERR,"FAIL: cyclic dependency accepted\n");exit(1);}catch(DomainException $e){ok($e->getMessage()==='gate_dependency_cycle','Dependency cycle denied');}
$GLOBALS['extra_gate']=array('abc'=>array('surface'=>'invalid_surface','requires'=>array()));
try{$c::plan();fwrite(STDERR,"FAIL: invalid surface accepted\n");exit(1);}catch(DomainException $e){ok($e->getMessage()==='gate_descriptor_invalid','Unknown surface denied');}
echo "PASS: unified gate funnel read only, no forced authority, invalid graph denied (6 checks)\n";
