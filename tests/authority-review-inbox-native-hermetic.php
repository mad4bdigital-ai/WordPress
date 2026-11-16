<?php
/**
 * Actual native authority review intake: never an approval ticket or grant.
 * Fake options only. No WordPress, database, credentials or network.
 */
define('ABSPATH',__DIR__.'/');define('WP_CLI',true);
$GLOBALS['qr_options']=array();$GLOBALS['qr_hooks']=array();
$GLOBALS['qr_events']=array();$GLOBALS['qr_grants']=0;
class WP_Error {
 private $code;
 public function __construct($code){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action($hook,$handler,$priority=10,$args=1){$GLOBALS['qr_hooks'][$hook]=$handler;}
function get_option($name,$default=array()){return $GLOBALS['qr_options'][$name]??$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['qr_options'][$name]=$value;return true;}
function do_action($name,$value=array()){$GLOBALS['qr_events'][]=array($name,$value);}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$x));}
function wp_json_encode($x,$options=0){return json_encode($x,$options);}
function current_user_can($cap){return $GLOBALS['qr_admin']&&$cap==='manage_options';}
function add_management_page($a,$b,$cap,$slug,$handler){$GLOBALS['qr_page']=$slug;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function wp_die($why){throw new RuntimeException($why);}
class WP_CLI {
 static $commands=array();
 static function add_command($name,$handler){self::$commands[$name]=$handler;}
 static function error($why){throw new RuntimeException($why);}
 static function line($msg){$GLOBALS['qr_cli_lines'][]=$msg;}
}
class MAD4B_SCP_Site_Profile {
 static function current_environment(){return $GLOBALS['qr_env'];}
 static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}
}
class MAD4B_SCP_Post_Update_Continuation {
 static function approved_grant_baseline(){return array(
   'seal_verified'=>$GLOBALS['qr_sealed'],'captured_before_update'=>$GLOBALS['qr_sealed'],
   'audit_verified'=>$GLOBALS['qr_sealed']);}
}
class MAD4B_SCP_Execution_Authority {
 static function resolve($operation){
   if($operation!=='read')throw new RuntimeException('Non-read in review inbox');
   return $GLOBALS['qr_actor']?array('transport'=>'host_cli',
     'execution_authorized'=>false):new WP_Error('read_denied');
 }
}
function qr_reset(){
 $GLOBALS['qr_env']='staging';$GLOBALS['qr_sealed']=false;
 $GLOBALS['qr_admin']=true;$GLOBALS['qr_actor']=true;
 $GLOBALS['qr_options']=array();$GLOBALS['qr_events']=array();
 $GLOBALS['qr_cli_lines']=array();$GLOBALS['qr_grants']=0;
}
function qr_plan(){
 return array('contract'=>'mad4b.authority-auto-reconciliation.v1',
  'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
  'source_commit_sha'=>str_repeat('a',40),'plan_sha256'=>str_repeat('b',64),
  'agent_public_id'=>'existing-agent',
  'missing_exact_grants'=>array(
     array('reason'=>'missing_exact_grant','ability'=>'mad4b/write-execute','provider'=>'core')),
  'needs_review'=>array(array('ability'=>'mad4b/stale-allow')),
  'subject_conflicts'=>array('oauth_subject_conflict'),
  'candidate_binding_match'=>false);
}
function qr_assert($v,$why){if(!$v)throw new RuntimeException('FAIL AUTHORITY INBOX: '.$why);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authority-reviews-bridge.php';
$c='MAD4B_SCP_Authority_Reviews_Bridge';qr_reset();
qr_assert(isset($GLOBALS['qr_hooks']['mad4b_authority_reconciliation_required'])
  && isset(WP_CLI::$commands['mad4b authority reviews']),
  'Read-only event and CLI hook must be registered');
$p=qr_plan();
$p['contract']='untrusted';$c::capture($p);
qr_assert($c::status()['total']===0,'Unknown event contract may not persist');
$p=qr_plan();$p['site_uuid']='foreign-site';$c::capture($p);
qr_assert($c::status()['total']===0,'Foreign site cannot write review state');
$p=qr_plan();$GLOBALS['qr_env']='production';$c::capture($p);
qr_assert($c::status()['total']===0,'Staging review intake must not run as Production');
$GLOBALS['qr_env']='staging';
$c::capture($p);$state=$c::status();
qr_assert($state['total']===4&&$state['unresolved']===4&&$state['read_only']===true
    && $state['approval_tickets_issued']===0 && $state['mutation_performed']===false,
    'Exact source intake must report four nonauthorizing issues');
$first_ids=array_column($state['entries'],'review_id');
qr_assert(count(array_unique($first_ids))===4,'Deterministic distinct review IDs required');
foreach($state['entries'] as $entry)
 qr_assert($entry['authorization_granted']===false
  && $entry['site_uuid']===MAD4B_SCP_Site_Profile::site_uuid()
  && $entry['plan_sha256']===str_repeat('b',64),
  'One reviewed issue tried to issue a grant');
$c::capture($p);
qr_assert($c::status()['total']===4,'Identical plan must be idempotent');
$GLOBALS['qr_sealed']=true;$c::capture($p);
foreach($c::status()['entries'] as $row){
 if($row['kind']==='exact_grant')
   qr_assert($row['state']==='AWAITING_NATIVE_APPROVAL_EVALUATION'
      && $row['authorization_granted']===false
      && $row['automatic_grant_issued']===false,
      'Sealed baseline is not itself grant approval');
}
$clean=qr_plan();$clean['missing_exact_grants']=array();
$clean['needs_review']=array();$clean['subject_conflicts']=array();
$clean['candidate_binding_match']=true;
$c::capture($clean);
qr_assert($c::status()['total']===4 && $c::status()['unresolved']===0,
  'Old items must be retained as resolved evidence');
$c::capture($p);
qr_assert($c::status()['unresolved']===4
  && array_column($c::status()['entries'],'review_id')===$first_ids,
  'Reopened review must keep stable identity');
try{$c::cli_status(array('site_uuid'=>'foreign'),array());throw new RuntimeException('Unexpected selector');}
catch(RuntimeException $e){qr_assert($e->getMessage()==='No external selector is permitted.',
  'CLI must deny caller-supplied site selectors');}
$GLOBALS['qr_actor']=false;
try{$c::cli_status(array(),array());throw new RuntimeException('Unauthorized CLI read');}
catch(RuntimeException $e){qr_assert($e->getMessage()==='read_denied',
  'CLI must require canonical read identity');}
$GLOBALS['qr_actor']=true;$c::cli_status(array(),array());
$cli=json_decode(end($GLOBALS['qr_cli_lines']),true);
qr_assert($cli['read_only']===true && $cli['unresolved']===4,
  'Native read CLI leaked write authority');
$GLOBALS['qr_admin']=false;
try{$c::admin_page();throw new RuntimeException('Unauthorized Admin page');}
catch(RuntimeException $e){qr_assert($e->getMessage()==='Not authorized.',
  'Admin page must enforce WordPress manage_options');}
$GLOBALS['qr_admin']=true;ob_start();$c::admin_page();$html=ob_get_clean();
qr_assert(strpos($html,'MAD4B Authority Reviews')!==false,
  'Admin read-only issue table did not render');
qr_assert($GLOBALS['qr_grants']===0 && $GLOBALS['qr_events'],
  'Review intake must never issue native grants');
echo "PASS: AUTHORITY_REVIEW_INBOX_NATIVE 12 review only scenarios\n";
