<?php
/** Hermetic no-WordPress-runtime tests of independent signed Staging readback. */
declare(strict_types=1);
define('ABSPATH','/test/');
define('DB_NAME','etg_commerce_staging');
function wp_get_environment_type(){return 'staging';}
function home_url($path=''){return 'https://tours.dev.mad4b.com'.$path;}
function untrailingslashit($x){return rtrim($x,'/');}
function wp_is_uuid($x){return is_string($x) && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$x)===1;}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function add_action($name,$callback){return true;}
function must($ok,$label){if(!$ok)throw new RuntimeException($label);}
final class MAD4B_Environment_Signed_Identity_V4 {
 public static function status($company,$env){return $GLOBALS['signed'];}
}
final class MAD4B_Company_Environment_Registry_V2 {
 public static function target($company,$env){return $GLOBALS['target'];}
}
final class MAD4B_Company_Operation_Admission_V1 {
 public static function manager_id(){return 'd9813e2d-d48a-52c7-ad48-8967a22656df';}
}
final class MAD4B_Company_Portfolio_Manager_V1 {}
require dirname(__DIR__).'/316-mad4b-company-environment-portfolio-evidence.php';

$site='49c562d1-8f2f-456f-b454-26816c6ba4cb';
$origin='https://staging.example.org';
$profile=str_repeat('a',64);
$GLOBALS['target']=[
 'environment'=>'staging','canonical_origin'=>$origin,'site_uuid'=>$site,
 'identity_evidence'=>['profile_digest'=>$profile]
];
$GLOBALS['signed']=[
 'centrally_verified'=>true,'environment'=>'staging',
 'canonical_origin'=>$origin,'site_uuid'=>$site
];
$GLOBALS['options']=[];
$cls=MAD4B_Company_Environment_Portfolio_Evidence_V1::class;
$x=$cls::readback('example-operator','staging');
must(($x['signed_identity_verified']??false)===true,'signed identity verified');
must(($x['required_next_action']??null)==='OWNER_APPROVE_ENVIRONMENT_ASSIGNMENT','missing assignment visible');
must(($x['operations_delegation_approved']??true)===false,'missing delegation rejected');
must(($x['executor_eligible']??true)===false,'signed profile does not give executor permission');
must(($x['grant_issued']??true)===false,'no grant issued');

$key=MAD4B_Company_Operation_Admission_V1::manager_id().'|example-operator|staging';
$hash=$x['portfolio_binding_sha256'];
$GLOBALS['options'][$cls::ASSIGNMENTS]=[$key=>[
 'manager_id'=>MAD4B_Company_Operation_Admission_V1::manager_id(),
 'company'=>'example-operator','environment'=>'staging',
 'site_uuid'=>$site,'canonical_origin'=>$origin,'binding_sha256'=>$hash,
 'state'=>'active','rights'=>['view_company','operations_manage'],
 'proposal_sha256'=>str_repeat('b',64),'approval_sha256'=>str_repeat('b',64)
]];
$x=$cls::readback('example-operator','staging');
must(($x['portfolio_assignment_bound']??false)===true,'exact assignment bound');
must(($x['operations_delegation_approved']??false)===true,'approved assignment recognized');
must(($x['executor_eligible']??true)===false,'even delegated manager cannot auto execute');
$GLOBALS['options'][$cls::ASSIGNMENTS][$key]['approval_sha256']=str_repeat('c',64);
$x=$cls::readback('example-operator','staging');
must(($x['operations_delegation_approved']??true)===false,'invalid owner approval rejected');
$GLOBALS['signed']['canonical_origin']='https://www.example.org';
$x=$cls::readback('example-operator','staging');
must(($x['reason']??'')==='SIGNED_STAGING_SITE_PROFILE_NOT_VERIFIED','signed origin drift denied');
$x=$cls::readback('example-operator','production');
must(($x['reason']??'')==='COMPANY_ENVIRONMENT_INVALID','production request denied');
echo "MAD4B_ENVIRONMENT_PORTFOLIO_EVIDENCE_HERMETIC_PASS\n";
