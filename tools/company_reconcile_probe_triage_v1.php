<?php
/**
 * Bounded, read-only host CLI tool: correlates incident candidates with
 * site-local target probes. Not independent proof or a native executor.
 *
 * Usage: php file.php POLICY.json PROBE.json COMPANY UUID HTTPS_ORIGIN ENV
 */
if(PHP_SAPI!=='cli'||$argc!==7){fwrite(STDERR,"Exact site scope and 2 read-only files required\n");exit(2);}
define('ABSPATH','/');
function wp_is_uuid($v){return is_string($v)&&preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D',$v)===1;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-reconcile-depth-audit.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-reconcile-work-plan.php';
[$script,$policyPath,$probePath,$company,$uuid,$origin,$environment]=$argv;
if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
 ||!wp_is_uuid($uuid)
 ||!preg_match('~^https://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$~iD',$origin)
 ||!in_array($environment,['staging','production'],true))exit(2);
foreach([$policyPath,$probePath] as $file) {
 if(!is_file($file)||is_link($file)||!is_readable($file)
    ||filesize($file)>1000000)exit(2);
}
$policy=json_decode(file_get_contents($policyPath),true);
$probe=json_decode(file_get_contents($probePath),true);
if(!is_array($policy)||!is_array($probe))exit(2);
$plan=MAD4B_Company_Reconcile_Work_Plan_V1::build($company,[],$policy);
$depth=$plan['depth_audit']??[];
if(($depth['dependency_graph_valid']??false)!==true)exit(3);
$result=MAD4B_Company_Reconcile_Depth_Audit_V1::correlate_local_probe(
 $depth,$probe,$uuid,$origin,$environment,time(),
 (int)($policy['evidence']['max_age_seconds']??1800));
$result['company_code']=$company;
$result['environment']=$environment;
$result['source_policy_plan_sha256']=$plan['work_plan_sha256'];
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
if(($result['site_binding_verified']??false)!==true)exit(4);
