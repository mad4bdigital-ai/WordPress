<?php
/** Exact historical resource state; never a signed Original313 claim. */
define('ABSPATH','/offline');
class MAD4B_Central_VPS_Broker_V1 {const JOB='mad4b_broker_job_';}
$GLOBALS['source_option_rows']=[];
function get_option($name,$default=false){return $GLOBALS['source_option_rows'][$name]??$default;}
function add_action($name,$callback,$priority=10,$args=1){return true;}
function wp_get_environment_type(){return 'staging';}
function untrailingslashit($s){return rtrim($s,'/');}
function home_url($path=''){return 'https://tours.dev.mad4b.com/'.$path;}
function check($pass,$msg){if(!$pass){fwrite(STDERR,"FAIL: ".$msg.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-experience-install-owner-launch.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-native-plugin-install-provider.php';
$class=MAD4B_Company_Experience_Install_Owner_V1::class;
$jobid=str_repeat('a',32);
$sha=str_repeat('b',64);
$state='WPCLI_HISTORICAL_EXECUTION_READBACK_RECONCILED';
$job=[
 'job_id'=>$jobid,'company'=>$class::COMPANY,'environment'=>$class::ENV,
 'site_uuid'=>$class::SITE,'canonical_origin'=>$class::ORIGIN,
 'command_sha256'=>$sha,'state'=>$state,'executed'=>true,
 'independent_readback_verified'=>true,'original_313_certified'=>false];
$history=[
 'contract'=>'mad4b.original-wpcli-historical-evidence-reconciliation.v1',
 'job_id'=>$jobid,'state'=>$state,'observed_operation'=>'plugin.install',
 'original_execution_mutated_production'=>true,'original_313_certified'=>false];
$inventory=[
 'site_uuid'=>$class::SITE,'canonical_origin'=>$class::ORIGIN,
 'contract'=>'mad4b.company-plugin-target-readback.v1',
 'readback_kind'=>'LIVE_COOLIFY_WPCLI_OBSERVATION',
 'install_missing_count'=>0,'activation_needed_count'=>0,
 'observed_at'=>gmdate('c')];
$GLOBALS['source_option_rows'][$class::STORE]=[
 'contract'=>'mad4b.experience-owner-original305.v1','company'=>$class::COMPANY,
 'environment'=>$class::ENV,'site_uuid'=>$class::SITE,'job_id'=>$jobid,
 'command_sha256'=>$sha];
$GLOBALS['source_option_rows'][MAD4B_Central_VPS_Broker_V1::JOB.$jobid]=$job;
$GLOBALS['source_option_rows']['mad4b_original_wpcli_historical_reconciliation_v1_'.$jobid]=$history;
$GLOBALS['source_option_rows']['mad4b_company_plugin_target_inventory_readback_v1']=[
 $class::COMPANY.':'.$class::ENV=>$inventory];
$x=$class::terminal_status();
check(($x['state']??'')==='INSTALLED_HISTORICALLY_RECONCILED','historical exact-source install verified');
check($x['original_313_certified']===false,'historical resource does not mint 313');
check($x['reinstallation_required']===false,'confirmed install prevents unnecessary installation');
check($x['inventory_state']==='NO_MISSING_OR_INACTIVE','fresh exact inventory accepted');
$provider=MAD4B_Company_Native_Plugin_Install_Provider_V1::readback();
check(($provider['state']??'')==='INSTALLED_HISTORICALLY_RECONCILED','provider surfaces already installed');
check(($provider['provider_ready']??false)===false,'historical resource does not grant new executor readiness');
check(($provider['original_313_certified']??true)===false,'provider never forges original 313');
check(($provider['native_305_issued']??true)===false,'no new original 305 is minted');
$GLOBALS['source_option_rows'][MAD4B_Central_VPS_Broker_V1::JOB.$jobid]['site_uuid']='wrong-tenant';
check(!$class::terminal_status(),'cross-company history denied');
$GLOBALS['source_option_rows'][MAD4B_Central_VPS_Broker_V1::JOB.$jobid]['site_uuid']=$class::SITE;
$GLOBALS['source_option_rows'][MAD4B_Central_VPS_Broker_V1::JOB.$jobid]['original_313_certified']=true;
check(!$class::terminal_status(),'unexpected original313 flag cannot masquerade as historical');
$GLOBALS['source_option_rows'][MAD4B_Central_VPS_Broker_V1::JOB.$jobid]['original_313_certified']=false;
$GLOBALS['source_option_rows']['mad4b_original_wpcli_historical_reconciliation_v1_'.$jobid]['original_313_certified']=true;
check(!$class::terminal_status(),'historical metadata cannot assert current signed 313');
$GLOBALS['source_option_rows']['mad4b_original_wpcli_historical_reconciliation_v1_'.$jobid]['original_313_certified']=false;
$GLOBALS['source_option_rows'][MAD4B_Central_VPS_Broker_V1::JOB.$jobid]['independent_readback_verified']=false;
check(!$class::terminal_status(),'independent historical readback required');
$GLOBALS['source_option_rows'][MAD4B_Central_VPS_Broker_V1::JOB.$jobid]['independent_readback_verified']=true;
$GLOBALS['source_option_rows']['mad4b_company_plugin_target_inventory_readback_v1'][$class::COMPANY.':'.$class::ENV]['observed_at']=gmdate('c',time()-7200);
$x=$class::terminal_status();
check($x['inventory_state']==='REFRESH_NEEDED','stale inventory can never claim current readiness');
check($x['original_313_certified']===false,'stale inventory still no 313');
unset($GLOBALS['source_option_rows']['mad4b_original_wpcli_historical_reconciliation_v1_'.$jobid]);
check(!$class::terminal_status(),'missing trusted history fails closed');
echo json_encode(['state'=>'PASS','cases'=>15,'original313_signed'=>false,
 'new305_issued'=>false,'word_press_site_modified'=>false,
 'live_runtime_acceptance'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
