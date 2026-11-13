<?php
/**
 * Plugin Name: MAD4B Exact Environment Manager Assignment (Central Staging)
 * Description: Owner-authenticated, environment-scoped Portfolio assignment lifecycle. No SSH grants.
 * Version: 0.1.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Environment_Manager_Assignment_V1 {
    const CONTRACT='mad4b.environment-manager-assignment.v1';
    const API='mad4b-central/v1';
    const RIGHTS=['view_company','operations_manage'];

    private static function failure(string $code,int $status=409): WP_Error {
        return new WP_Error('mad4b_environment_manager_'.$code,
            'Exact Staging environment assignment: '.$code,['status'=>$status]);
    }
    private static function live():bool {
        return class_exists('MAD4B_Company_Environment_Portfolio_Evidence_V1',false)
            && MAD4B_Company_Environment_Portfolio_Evidence_V1::enabled()
            && class_exists('MAD4B_Manager_Auto_Write_V1',false)
            && class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && class_exists('MAD4B_Company_Operation_Admission_V1',false);
    }
    private static function manager():array {
        if(!self::live())return [];
        $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
        $owners=(array)get_option(MAD4B_Company_Portfolio_Manager_V1::MANAGERS,[]);
        $record=$owners[$manager]??null;
        if(!wp_is_uuid($manager)||!is_array($record)
            || ($record['manager_id']??null)!==$manager
            || ($record['state']??null)!=='registered')return [];
        return $record;
    }
    private static function principal():int {
        if(!self::live())return 0;
        $owner=MAD4B_Manager_Auto_Write_V1::enrolled_owner();
        if($owner<1)return 0;
        $manager=self::manager();
        $id=(string)($manager['manager_id']??'');
        $rows=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
        $member=is_array($rows)?($rows[$id.'|'.$owner]??null):null;
        return is_array($member)&&($member['state']??null)==='active'
            && (int)($member['user_id']??0)===$owner ? $owner : 0;
    }
    public static function owner(WP_REST_Request $request):bool {
        if(defined('WP_CLI') && WP_CLI)return false;
        $owner=self::principal();
        if(!$owner || get_current_user_id()!==$owner
            || !is_user_logged_in()
            || !current_user_can('manage_options')
            || !current_user_can('activate_plugins'))return false;
        $nonce=(string)$request->get_header('X-WP-Nonce');
        if($nonce!=='')return wp_verify_nonce($nonce,'wp_rest')!==false;
        return class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal',false)
            && MAD4B_Company_Manager_Unified_Administrator_Principal::verified();
    }
    private static function identity(string $company) {
        if(!self::live()||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))
            return self::failure('company_or_staging_invalid',422);
        $read=MAD4B_Company_Environment_Portfolio_Evidence_V1::readback($company,'staging');
        if(($read['signed_identity_verified']??false)!==true
            || ($read['environment']??'')!=='staging'
            || !wp_is_uuid((string)($read['site_uuid']??''))
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($read['portfolio_binding_sha256']??'')))
            return self::failure('current_signed_target_missing');
        if(!self::manager() || !self::principal())
            return self::failure('enrolled_manager_or_membership_missing',403);
        return $read;
    }
    public static function plan(string $company):array {
        $site=self::identity($company);
        if(is_wp_error($site))return [
            'contract'=>self::CONTRACT,'eligible'=>false,
            'reason'=>$site->get_error_code(),
            'mutation_performed'=>false,'grant_issued'=>false
        ];
        $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
        $key=$manager.'|'.$company.'|staging';
        $rows=get_option(MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS,[]);
        $row=is_array($rows)?($rows[$key]??null):null;
        $bound=is_array($row)
            && ($row['company']??'')===$company
            && ($row['environment']??'')==='staging'
            && ($row['manager_id']??'')===$manager
            && ($row['site_uuid']??'')===$site['site_uuid']
            && ($row['canonical_origin']??'')===$site['canonical_origin']
            && hash_equals((string)$site['portfolio_binding_sha256'],
                           (string)($row['binding_sha256']??''));
        return [
            'contract'=>self::CONTRACT,'eligible'=>true,'company'=>$company,
            'environment'=>'staging','manager_id'=>$manager,
            'site_uuid'=>$site['site_uuid'],'canonical_origin'=>$site['canonical_origin'],
            'binding_sha256'=>$site['portfolio_binding_sha256'],
            'revision'=>$bound?(int)($row['revision']??0):0,
            'assignment_state'=>$bound?($row['state']??'missing'):'missing',
            'proposal_sha256'=>$bound?($row['proposal_sha256']??null):null,
            'approved'=>$bound && ($row['approval_sha256']??null)===($row['proposal_sha256']??null)
                && is_string($row['proposal_sha256']??null),
            'next_action'=>!$bound?'propose':match(($row['state']??'')){
                'proposed'=>'approve','approved'=>'activate','active'=>'native_transport_enrollment',
                'revoked'=>'propose',default=>'review'},
            'write_authorized'=>false,'native_executor_authorized'=>false,
            'grant_issued'=>false,'mutation_performed'=>false
        ];
    }
    public static function transition(array $existing,string $action,int $expected,
        string $binding,string $proposal,string $company,string $manager,
        string $origin,string $site,int $owner) {
        $revision=(int)($existing['revision']??0);
        if($revision!==$expected)return self::failure('stale_revision');
        $state=(string)($existing['state']??'missing');
        if($action==='propose') {
            if(!in_array($state,['missing','revoked'],true))
                return self::failure('proposal_already_open');
            $proposalData=[
                'company'=>$company,'environment'=>'staging','manager_id'=>$manager,
                'canonical_origin'=>$origin,'site_uuid'=>$site,
                'binding_sha256'=>$binding,'rights'=>self::RIGHTS,
                'proposal_scope'=>'environment_operations_delegation_only'
            ];
            return $proposalData+[
                'proposal_sha256'=>hash('sha256',wp_json_encode($proposalData,JSON_UNESCAPED_SLASHES)),
                'approval_sha256'=>null,'state'=>'proposed','revision'=>$revision+1,
                'proposed_by'=>$owner,'proposed_at'=>gmdate('c'),
                'remote_write_granted'=>false,'native_executor_enabled'=>false
            ];
        }
        if(!is_array($existing)||($existing['binding_sha256']??'')!==$binding
            || ($existing['company']??'')!==$company || ($existing['environment']??'')!=='staging'
            || ($existing['site_uuid']??'')!==$site)
            return self::failure('exact_binding_changed');
        if(!preg_match('/^[a-f0-9]{64}$/D',$proposal)
           || !hash_equals((string)($existing['proposal_sha256']??''),$proposal))
            return self::failure('proposal_evidence_mismatch');
        $from=['approve'=>'proposed','activate'=>'approved','revoke'=>null];
        if(!array_key_exists($action,$from) ||
            ($action==='revoke'?!in_array($state,['proposed','approved','active'],true)
                :$state!==$from[$action]))return self::failure('transition_denied');
        if($action==='activate' && ($existing['approval_sha256']??'')!==$proposal)
            return self::failure('owner_approval_not_bound');
        $existing['state']=match($action){'approve'=>'approved','activate'=>'active','revoke'=>'revoked'};
        $existing['revision']=$revision+1;
        $existing['last_action']=$action;
        $existing['last_action_by']=$owner;
        $existing['last_action_at']=gmdate('c');
        $existing['remote_write_granted']=false;
        $existing['native_executor_enabled']=false;
        if($action==='approve'){
            $existing['approval_sha256']=$proposal;
            $existing['approved_by']=$owner;
            $existing['approved_at']=gmdate('c');
        }
        return $existing;
    }
    public static function rest_get(WP_REST_Request $request) {
        if(!current_user_can('manage_options'))return self::failure('admin_required',403);
        $plan=self::plan((string)$request['company']);
        // Read-only session readiness for the current authenticated REST request.
        // This is NOT a grant; every POST independently repeats owner() and CAS checks.
        $plan['owner_action_session_verified']=self::owner($request);
        return rest_ensure_response($plan);
    }
    public static function rest_write(WP_REST_Request $request) {
        if(!self::owner($request))return self::failure('original_owner_stepup_required',403);
        $data=$request->get_json_params();
        if(!is_array($data) || array_diff(array_keys($data),
            ['action','expected_revision','expected_binding_sha256','expected_proposal_sha256']))
            return self::failure('invalid_request_shape',422);
        $action=(string)($data['action']??'');
        if(!in_array($action,['propose','approve','activate','revoke'],true)
           || !array_key_exists('expected_revision',$data)
           || !is_int($data['expected_revision']) || $data['expected_revision']<0)
            return self::failure('action_or_revision_invalid',422);
        $company=(string)$request['company'];
        $site=self::identity($company);
        if(is_wp_error($site))return $site;
        $binding=(string)$site['portfolio_binding_sha256'];
        if(!is_string($data['expected_binding_sha256']??null) ||
            !hash_equals($binding,$data['expected_binding_sha256']))
            return self::failure('binding_digest_drift');
        $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
        $key=$manager.'|'.$company.'|staging';
        $wpdb=$GLOBALS['wpdb'];
        $lock='mad4b:environment-manager:'.substr(hash('sha256',$key),0,30);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return self::failure('assignment_locked',423);
        try {
            $store=get_option(MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS,[]);
            if(!is_array($store))return self::failure('assignment_store_invalid',503);
            $prior=$store[$key]??[];
            if(!is_array($prior))return self::failure('assignment_record_invalid',503);
            $next=self::transition($prior,$action,$data['expected_revision'],$binding,
                (string)($data['expected_proposal_sha256']??''),$company,$manager,
                $site['canonical_origin'],$site['site_uuid'],get_current_user_id());
            if(is_wp_error($next))return $next;
            $store[$key]=$next;
            if(!update_option(MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS,$store,false)
                && get_option(MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS,[])!==$store)
                return self::failure('persistence_failed',503);
            $read=self::plan($company);
            if(($read['revision']??-1)!==$next['revision'] ||
               ($read['assignment_state']??'')!==$next['state'])
                return self::failure('independent_readback_failed',503);
            return rest_ensure_response($read);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function boot():void {
        if(!self::live())return;
        add_action('rest_api_init',static function(){
            register_rest_route(self::API,
              '/company-environments/(?P<company>[a-z][a-z0-9-]{2,47})/staging/manager-assignment',[
                ['methods'=>WP_REST_Server::READABLE,
                 'permission_callback'=>static fn()=>current_user_can('manage_options'),
                 'callback'=>[__CLASS__,'rest_get']],
                ['methods'=>WP_REST_Server::CREATABLE,
                 'permission_callback'=>[__CLASS__,'owner'],
                 'callback'=>[__CLASS__,'rest_write']]
              ]);
        });
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b environment-manager-plan',static function($args){
                WP_CLI::line(wp_json_encode(self::plan((string)($args[0]??''))));
            });
        }
    }
}
// Register only after all MU and standard plugins have defined their providers.
// Immediate MU bootstrap can silently omit REST and WP-CLI routes.
add_action('plugins_loaded',[MAD4B_Environment_Manager_Assignment_V1::class,'boot'],90);
