<?php
/**
 * Plugin Name: MAD4B Company Environment Portfolio Evidence
 * Description: Central Staging-only, per-environment Portfolio readback; no grants or executor.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Environment_Portfolio_Evidence_V1 {
    const CONTRACT='mad4b.company-environment-portfolio-evidence.v1';
    const ASSIGNMENTS='mad4b_company_environment_manager_assignments_v1';

    public static function enabled(): bool {
        return function_exists('wp_get_environment_type') &&
            wp_get_environment_type()==='staging' &&
            defined('DB_NAME') && DB_NAME==='etg_commerce_staging' &&
            untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }
    private static function deny(string $reason):array {
        return ['contract'=>self::CONTRACT,'eligible'=>false,'reason'=>$reason,
            'mutation_performed'=>false,'grant_issued'=>false,
            'production_mutation_allowed'=>false];
    }
    public static function readback(string $company,string $environment):array {
        if(!self::enabled())return self::deny('CENTRAL_STAGING_ONLY');
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company) ||
            $environment!=='staging')return self::deny('COMPANY_ENVIRONMENT_INVALID');
        if(!class_exists('MAD4B_Environment_Signed_Identity_V4',false) ||
            !class_exists('MAD4B_Company_Environment_Registry_V2',false) ||
            !class_exists('MAD4B_Company_Operation_Admission_V1',false) ||
            !class_exists('MAD4B_Company_Portfolio_Manager_V1',false))
            return self::deny('CENTRAL_NATIVE_PROVIDERS_MISSING');
        $target=MAD4B_Company_Environment_Registry_V2::target($company,'staging');
        $signed=MAD4B_Environment_Signed_Identity_V4::status($company,'staging');
        if(!is_array($target)||!is_array($signed) ||
            ($target['environment']??null)!=='staging' ||
            ($signed['centrally_verified']??false)!==true ||
            ($signed['environment']??null)!=='staging' ||
            ($signed['canonical_origin']??null)!==($target['canonical_origin']??null) ||
            ($signed['site_uuid']??null)!==($target['site_uuid']??null) ||
            !wp_is_uuid((string)($target['site_uuid']??'')))
            return self::deny('SIGNED_STAGING_SITE_PROFILE_NOT_VERIFIED');
        $digest=(string)($target['identity_evidence']['profile_digest']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest))
            return self::deny('PROFILE_DIGEST_MISSING');
        $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
        if(!wp_is_uuid($manager))return self::deny('COMPANY_MANAGER_NOT_REGISTERED');
        $recordKey=$manager.'|'.$company.'|staging';
        $rows=get_option(self::ASSIGNMENTS,[]);
        if(!is_array($rows))return self::deny('ENVIRONMENT_ASSIGNMENT_STORE_INVALID');
        $record=$rows[$recordKey]??null;
        $binding=hash('sha256',implode('|',[$company,'staging',
            $target['site_uuid'],$target['canonical_origin'],$digest]));
        $bound=is_array($record) &&
            ($record['manager_id']??null)===$manager &&
            ($record['company']??null)===$company &&
            ($record['environment']??null)==='staging' &&
            ($record['site_uuid']??null)===$target['site_uuid'] &&
            ($record['canonical_origin']??null)===$target['canonical_origin'] &&
            is_string($record['binding_sha256']??null) &&
            hash_equals($binding,$record['binding_sha256']);
        $active=$bound && ($record['state']??'')==='active' &&
            in_array('operations_manage',(array)($record['rights']??[]),true) &&
            is_string($record['proposal_sha256']??null) &&
            preg_match('/^[a-f0-9]{64}$/D',$record['proposal_sha256'])===1 &&
            is_string($record['approval_sha256']??null) &&
            hash_equals($record['proposal_sha256'],$record['approval_sha256']);
        return [
            'contract'=>self::CONTRACT,'company'=>$company,'environment'=>'staging',
            'canonical_origin'=>$target['canonical_origin'],
            'site_uuid'=>$target['site_uuid'],
            'signed_identity_verified'=>true,'signed_identity_scope'=>'identity.read',
            'portfolio_binding_sha256'=>$binding,
            'portfolio_assignment_state'=>$record['state']??'missing',
            'portfolio_assignment_bound'=>$bound,
            'operations_delegation_approved'=>$active,
            'executor_eligible'=>false, // execution requires original 305 + remote native transport
            'required_next_action'=>$active?'REGISTER_APPROVED_NATIVE_TRANSPORT':'OWNER_APPROVE_ENVIRONMENT_ASSIGNMENT',
            'mutation_performed'=>false,'grant_issued'=>false,
            'production_mutation_allowed'=>false
        ];
    }
    public static function boot():void {
        if(!self::enabled())return;
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b-central/v1',
                '/company-environments/(?P<company>[a-z][a-z0-9-]{2,47})/(?P<environment>staging)/portfolio-readback',[
                    'methods'=>WP_REST_Server::READABLE,
                    'permission_callback'=>static function(){return current_user_can('manage_options');},
                    'callback'=>static function($request){
                        return new WP_REST_Response(self::readback(
                            (string)$request['company'],(string)$request['environment']),200);
                    }
                ]);
        });
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')){
            WP_CLI::add_command('mad4b environment-portfolio-readback',
                static function($args){
                    $company=(string)($args[0]??'');$env=(string)($args[1]??'staging');
                    WP_CLI::line(wp_json_encode(self::readback($company,$env)));
                });
        }
    }
}
MAD4B_Company_Environment_Portfolio_Evidence_V1::boot();
