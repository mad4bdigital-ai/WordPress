<?php
require __DIR__.'/fixtures/production-automation-bootstrap.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-governed-runtime-gates.php';
function check($v,$m){if(!$v)throw new Exception($m);echo 'PASS '.$m.PHP_EOL;}
function fields($p){$i=$p['after'];foreach(array('plan_sha256','policy_revision','policy_digest','site_uuid','source_commit_sha','build_fingerprint') as $k)$i['expected_'.$k]=$p[$k];$i['expected_profile_revision']=$p['site_profile_revision'];$i['expected_profile_digest']=$p['site_profile_digest'];$i['confirmation']=$p['required_confirmation'];return $i;}
$c='MAD4B_SCP_Governed_Runtime_Gates';
check(!$c::production_auto_enable(),'default disabled');
$p=$c::admin_policy_plan('automatic');$i=fields($p);
check(!is_wp_error($p)&&$p['ready_to_apply'],'owner can prepare exact automatic policy');
check(is_wp_error($c::apply($i)),'local session cannot use OAuth apply endpoint');
$bad=$i;$bad['raw_sql_breakglass_enabled']=true;
check(is_wp_error($c::admin_policy_apply('automatic',$bad)),'admin policy cannot smuggle SQL authority');
$bad=$i;$bad['confirmation']='';check(is_wp_error($c::admin_policy_apply('automatic',$bad)),'explicit confirmation required');
check(!is_wp_error($c::admin_policy_apply('automatic',$i))&&$c::production_auto_enable(),'automatic policy persists and activates real bootstrap');
check(defined('MAD4B_MCP_MUTATION_ENABLED')&&MAD4B_MCP_MUTATION_ENABLED===true,'runtime mutation constant bootstrapped');
check(end($GLOBALS['events'])['authority_transport']==='wordpress_owner_session','owner consent audited with actual transport');
check(is_wp_error($c::admin_policy_apply('automatic',$i)),'replay of prior revision rejected');
$GLOBALS['sha']=str_repeat('d',40);check($c::production_auto_enable(),'existing policy survives code SHA change without regrant');
$GLOBALS['revision']=2;check(!$c::production_auto_enable(),'profile revision drift disables effective policy');$GLOBALS['revision']=1;
$GLOBALS['bound']=false;check(!$c::production_auto_enable(),'origin drift disables effective policy');$GLOBALS['bound']=true;
$GLOBALS['oauth_opt']=false;check(!$c::production_auto_enable(),'OAuth opt-out revokes activation');$GLOBALS['oauth_opt']=true;
$p=$c::admin_policy_plan('disabled');check(!is_wp_error($c::admin_policy_apply('disabled',fields($p)))&&!$c::production_mutation_enabled(),'owner can revoke Production gate');
$p=$c::admin_policy_plan('automatic');$i=fields($p);$GLOBALS['persist']=false;check(is_wp_error($c::admin_policy_apply('automatic',$i))&&!$c::production_auto_enable(),'persistence failure cannot activate');$GLOBALS['persist']=true;
$GLOBALS['audit']=false;check(is_wp_error($c::admin_policy_apply('automatic',$i))&&!$c::production_auto_enable(),'missing audit blocks activation');$GLOBALS['audit']=true;
foreach(array('staging','development') as $e){$GLOBALS['env']=$e;check(is_wp_error($c::admin_policy_plan('automatic')),'no authority transfer from '.$e);}$GLOBALS['env']='production';
$GLOBALS['user']=9;check(is_wp_error($c::admin_policy_plan('automatic')),'unenrolled admin rejected');$GLOBALS['user']=7;
$GLOBALS['ids']=array(7,9);check(is_wp_error($c::admin_policy_plan('automatic')),'ambiguous owner rejected');$GLOBALS['ids']=array(7);
check(is_wp_error($c::admin_policy_plan(array('automatic'))),'malformed mode rejected');
$_SERVER['REQUEST_METHOD']='GET';try{$c::handle_admin_policy();throw new Exception('accepted GET');}catch(Exception $e){check($e->getMessage()==='POST required.','GET rejected');}
$_SERVER['REQUEST_METHOD']='POST';$GLOBALS['nonce']=false;try{$c::handle_admin_policy();throw new Exception('accepted csrf');}catch(Exception $e){check($e->getMessage()==='nonce','CSRF rejected');}
echo 'production-runtime-admin: PASS'.PHP_EOL;
