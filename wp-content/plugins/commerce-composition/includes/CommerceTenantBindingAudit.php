<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Read-only source binding status. Never rebinds company configuration
 * or endorses one site's prior policy as belonging to a new Site Profile.
 */
final class CommerceTenantBindingAudit
{
    private const ROOT = 'commerce-composition/v1';
    public static function inspect():array
    {
        $profile = class_exists('MAD4B_SCP_Site_Profile')?
            \MAD4B_SCP_Site_Profile::profile():[];
        $state = class_exists('MAD4B_SCP_Site_Profile')?
            \MAD4B_SCP_Site_Profile::status():[];
        $company = get_option('commerce_management_company_definitions_v1',[]);
        $policy = get_option(CompanyCommercePolicy::OPTION,[]);
        $profile = is_array($profile)?$profile:[];
        $state = is_array($state)?$state:[];
        $company = is_array($company)?$company:[];
        $policy = is_array($policy)?$policy:[];
        $siteUuid=(string)($profile['site_uuid']??'');
        $siteDigest=(string)($state['profile_digest']??'');
        $same = static fn(array $x,string $key,string $source):bool =>
            $source!=='' && is_string($x[$key]??null) && hash_equals($source,$x[$key]);
        $companyChecks=[
            'same_site_uuid'=>$same($company,'site_uuid',$siteUuid),
            'same_site_profile_revision'=>(int)($company['site_profile_revision']??-1)===(int)($profile['revision']??0)
                && (int)($profile['revision']??0)>0,
            'same_profile_digest'=>$same($company,'site_profile_digest',$siteDigest),
            'same_environment'=>isset($company['environment'],$profile['environment'])
                && $company['environment']===$profile['environment'],
            'valid_contract'=>($company['contract']??null)==='commerce.management.company.v1'
        ];
        $policyChecks=[
            'same_site_uuid'=>$same($policy,'site_uuid',$siteUuid),
            'same_site_profile_revision'=>(int)($policy['profile_revision']??-1)===(int)($profile['revision']??0)
                &&(int)($profile['revision']??0)>0,
            'same_profile_digest'=>$same($policy,'profile_digest',$siteDigest),
            'same_environment'=>isset($policy['environment'],$profile['environment'])
                && $policy['environment']===$profile['environment'],
            'valid_contract'=>($policy['contract']??null)===CompanyCommercePolicy::CONTRACT
        ];
        $authority=$state['authority_ready']??false;
        $healthy=$authority===true
            &&($state['origin_match']??false)===true
            &&($state['deployment_binding_match']??false)===true
            &&($state['environment_match']??false)===true
            &&($state['profile_authority_quarantined']??true)===false;
        $matched=!in_array(false,$companyChecks,true)&&!in_array(false,$policyChecks,true);
        $foreign=($company!==[]&&!$companyChecks['same_site_uuid'])
            ||($policy!==[]&&!$policyChecks['same_site_uuid']);
        $classification=$foreign?'foreign_or_reenrolled_site_profile':
            (!$matched?'stale_or_incomplete_site_policy':(!$healthy?'site_authority_unready':'site_bound'));
        return [
            'contract'=>'commerce.tenant-binding-audit.v1',
            'site_environment'=>(string)($profile['environment']??'unknown'),
            'site_profile_revision'=>(int)($profile['revision']??0),
            'company_definition_revision'=>(int)($company['revision']??0),
            'policy_business_revision'=>(int)($policy['revision']??0),
            'policy_status'=>(string)($policy['status']??'unconfigured'),
            'site_profile_authority_healthy'=>$healthy,
            'company_binding'=>$companyChecks,
            'policy_binding'=>$policyChecks,
            'classification'=>$classification,
            'policy_resolution_available'=>$matched,
            'commerce_write_authorized'=>false,
            'automatic_rebinding_forbidden'=>true,
            'next_action'=>$matched?'certify_commercial_provider':
                ($foreign?'verify_owner_provenance_and_reenroll_company_policy':
                'review_site_profile_revision_and_policy_binding'),
        ];
    }
    public static function routes():void
    {
        register_rest_route(self::ROOT,'/management/commerce-binding-audit',[
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>static fn()=>current_user_can('manage_options')
                &&current_user_can('manage_woocommerce'),
            'callback'=>static function(){
                $response=new \WP_REST_Response(self::inspect(),200);
                $response->header('Cache-Control','private,no-store');
                return $response;
            }
        ]);
    }
    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'routes']);
    }
}
