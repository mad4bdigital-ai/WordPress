<?php
/** Synthetic isolated integration of original 300+301 with exact Staging bridge. */
declare(strict_types=1);
define('ABSPATH','/test/');
define('DB_NAME','etg_commerce_staging');
$GLOBALS['user_id']=1;
$GLOBALS['options']=[];
$GLOBALS['target_env']='staging';
$GLOBALS['signed']=true;
function add_action($name,$fn,$priority=10,$args=1):void {}
function wp_get_environment_type(){return 'staging';}
function home_url($x=''){return 'https://tours.dev.mad4b.com'.$x;}
function untrailingslashit($x){return rtrim($x,'/');}
function wp_is_uuid($x){return is_string($x) && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$x)===1;}
function get_option($name,$default=false){return $GLOBALS['options'][$name]??$default;}
function get_current_user_id(){return $GLOBALS['user_id'];}
function current_user_can($cap){return $GLOBALS['user_id']===1 && in_array($cap,['manage_options','activate_plugins'],true);}
function get_user_by($type,$id){return $type==='id' && $id===1?(object)['ID'=>1]:false;}
function user_can($user,$cap){return $user->ID===1 && in_array($cap,['manage_options','activate_plugins'],true);}
function is_wp_error($v){return $v instanceof WP_Error;}
function must($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
class WP_Error {
    private $code;
    public function __construct($code,$msg='',$data=[]){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Site_Profile {
    public static function origin_enrolled(){return true;}
    public static function site_urls_match_enrollment(){return true;}
    public static function oauth_user_ids(){return [1];}
    public static function user_is_enrolled($id){return $id===1;}
}
class MAD4B_Company_Portfolio_Manager_V1 {
    const MANAGERS='managers';const MEMBERS='members';
    const ASSIGNMENTS='legacy_assignments';const REGISTRY='production_only_registry';
    public static function company_binding($company){return new WP_Error('legacy_production_binding_not_enrolled');}
    public static function has_delegation($m,$c,$r,$u){return false;}
}
class MAD4B_Company_Environment_Portfolio_Evidence_V1 {
    const ASSIGNMENTS='mad4b_company_environment_manager_assignments_v1';
    public static function enabled(){return true;}
    public static function readback($company,$env){
        return [
          'contract'=>'mad4b.company-environment-portfolio-evidence.v1',
          'company'=>$company,'environment'=>'staging',
          'signed_identity_verified'=>$GLOBALS['signed'],
          'signed_identity_scope'=>'identity.read',
          'canonical_origin'=>'https://staging.example.test',
          'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
          'portfolio_binding_sha256'=>str_repeat('a',64)
        ];
    }
}
class MAD4B_Company_Execution_Resolver_V1 {
    public static function discover(){
        return ['example-company:'.$GLOBALS['target_env']=>[
          'company_id'=>'example-company','environment'=>$GLOBALS['target_env'],
          'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
          'canonical_origin'=>'https://staging.example.test',
          'identity'=>['site_profile_v4_verified'=>true],
        ]];
    }
    public static function plan($company,$environment,$intent){
        return ['state'=>'BLOCKED_MISSING_AUTHORITY','execution_allowed'=>false,
           'remote_write_permitted'=>false,'selected_channel'=>null,
           'environment'=>$environment,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
           'canonical_origin'=>'https://staging.example.test',
           'blockers'=>['original_native_executor_not_certified']];
    }
}
$manager='d9813e2d-d48a-52c7-ad48-8967a22656df';
$GLOBALS['options']['managers']=[$manager=>[
  'manager_id'=>$manager,'manager_code'=>'wo-vacation','state'=>'registered']];
$GLOBALS['options']['members']=[$manager.'|1'=>['state'=>'active','user_id'=>1]];
require dirname(__DIR__).'/300-mad4b-manager-auto-write.php';
require dirname(__DIR__).'/301-mad4b-company-operation-admission.php';
require dirname(__DIR__).'/318-mad4b-staging-manager-admission-bridge.php';
$bridge=MAD4B_Staging_Manager_Admission_Bridge_V1::class;
$admission=MAD4B_Company_Operation_Admission_V1::class;
$company='example-company';
$r=$bridge::binding($company);
must(!is_wp_error($r),'current staging signed binding accepted');
must($r['company_site_uuid']==='123e4567-e89b-42d3-a456-426614174000','exact target UUID');
must($bridge::has_delegation($manager,$company,'operations_manage',1)===false,'missing assignment denied');
$check=$admission::assess($company,'identity.inspect');
must(!is_wp_error($check) && $check['inspection_admitted']===true,'signed read-only inspection remains allowed');
$deny=$admission::assess($company,'operations.write');
must(!is_wp_error($deny) && $deny['policy_delegation_admitted']===false
    && $deny['write_execution_authorized']===false,'no write on missing assignment');
$key=$manager.'|'.$company.'|staging';
$GLOBALS['options'][MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS]=[$key=>[
 'manager_id'=>$manager,'company'=>$company,'environment'=>'staging',
 'canonical_origin'=>'https://staging.example.test',
 'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
 'binding_sha256'=>str_repeat('a',64),'rights'=>['view_company','operations_manage'],
 'proposal_sha256'=>str_repeat('b',64),'approval_sha256'=>str_repeat('b',64),
 'state'=>'active','revision'=>3
]];
must($bridge::has_delegation($manager,$company,'operations_manage',1),'exact approval and membership active');
$read=$admission::assess($company,'content.read');
must(!is_wp_error($read) && $read['policy_delegation_admitted']===true,'delegated staging reads admitted');
$allow=$admission::assess($company,'operations.write');
must(!is_wp_error($allow) && $allow['policy_delegation_admitted']===true
     && $allow['write_execution_authorized']===false
     && $allow['state']==='ADMITTED_WAITING_NATIVE_EXECUTOR','owner approval does not replace Native Executor');
$GLOBALS['options'][MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS][$key]['state']='revoked';
must($bridge::has_delegation($manager,$company,'operations_manage',1)===false,'revocation effective immediately');
$denied=$admission::assess($company,'operations.write');
must(!is_wp_error($denied) && $denied['policy_delegation_admitted']===false,'revoked grant not admitted');
$GLOBALS['signed']=false;
must(is_wp_error($bridge::binding($company)),'signed V4 proof loss denied');
$GLOBALS['signed']=true;
$GLOBALS['target_env']='production';
must(is_wp_error($bridge::binding($company)),'Production target cannot be relabeled Staging');
echo "MAD4B_STAGING_MANAGER_ORIGINAL_ADMISSION_HERMETIC_PASS\n";
