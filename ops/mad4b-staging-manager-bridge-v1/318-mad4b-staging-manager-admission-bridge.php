<?php
/**
 * Plugin Name: MAD4B Company Staging Manager Admission Bridge
 * Description: Exact environment-scoped read-only admission for existing 300/301/305/312 contracts.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Staging_Manager_Admission_Bridge_V1 {
    const CONTRACT='mad4b.staging-manager-admission-bridge.v1';
    const RIGHT=['content.write'=>'content_manage','strategy.write'=>'strategy_manage',
       'operations.write'=>'operations_manage','commerce.write'=>'commerce_manage'];
    private static function deny(string $why):WP_Error {
        return new WP_Error('mad4b_staging_bridge_'.$why,
            'Scoped Staging delegation unavailable: '.$why,['status'=>409]);
    }
    private static function live():bool {
        return class_exists('MAD4B_Company_Environment_Portfolio_Evidence_V1',false)
            && MAD4B_Company_Environment_Portfolio_Evidence_V1::enabled()
            && class_exists('MAD4B_Manager_Auto_Write_V1',false)
            && class_exists('MAD4B_Company_Operation_Admission_V1',false)
            && class_exists('MAD4B_Company_Portfolio_Manager_V1',false);
    }
    public static function binding(string $company,string $environment='staging') {
        if(!self::live() || $environment!=='staging'
            || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))
            return self::deny('staging_and_company_required');
        $r=MAD4B_Company_Environment_Portfolio_Evidence_V1::readback($company,'staging');
        if(($r['contract']??'')!=='mad4b.company-environment-portfolio-evidence.v1'
           || ($r['environment']??'')!=='staging'
           || ($r['company']??'')!==$company
           || ($r['signed_identity_verified']??false)!==true
           || ($r['signed_identity_scope']??'')!=='identity.read'
           || !wp_is_uuid((string)($r['site_uuid']??''))
           || !preg_match('/^[a-f0-9]{64}$/D',(string)($r['portfolio_binding_sha256']??''))
           || !filter_var((string)($r['canonical_origin']??''),FILTER_VALIDATE_URL))
            return self::deny('signed_staging_identity_not_current');
        $target=MAD4B_Manager_Auto_Write_V1::company_target($company);
        if(($target['state']??'')!=='REGISTERED'
           || ($target['environment']??'')!=='staging'
           || ($target['site_uuid']??'')!==$r['site_uuid']
           || ($target['canonical_origin']??'')!==$r['canonical_origin'])
            return self::deny('execution_target_not_exact_staging');
        return [
           'company_code'=>$company,'environment'=>'staging',
           'company_site_uuid'=>$r['site_uuid'],
           'canonical_origin'=>$r['canonical_origin'],
           'binding_sha256'=>$r['portfolio_binding_sha256'],
           'assignment_revision'=>self::revision($company),
           'signed_identity_verified'=>true,'write_execution_authorized'=>false
        ];
    }
    private static function row(string $manager,string $company):?array {
        $stored=get_option(MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS,[]);
        if(!is_array($stored))return null;
        $item=$stored[$manager.'|'.$company.'|staging']??null;
        return is_array($item)?$item:null;
    }
    public static function revision(string $company):int {
        if(!self::live())return 0;
        $id=MAD4B_Company_Operation_Admission_V1::manager_id();
        $row=self::row($id,$company);
        return max(0,(int)($row['revision']??0));
    }
    public static function has_delegation(string $manager,string $company,
        string $right='view_company',int $actor=0):bool {
        $binding=self::binding($company,'staging');
        if(is_wp_error($binding) || !wp_is_uuid($manager))return false;
        $user=$actor ?: get_current_user_id();
        // WordPress's --user override alone never confers an external transport grant.
        $owner=MAD4B_Manager_Auto_Write_V1::enrolled_owner();
        if($owner<1 || $user!==$owner)return false;
        $managers=get_option(MAD4B_Company_Portfolio_Manager_V1::MANAGERS,[]);
        $managerRow=is_array($managers)?($managers[$manager]??null):null;
        if(!is_array($managerRow) || ($managerRow['state']??'')!=='registered'
           || ($managerRow['manager_id']??'')!==$manager
           || MAD4B_Company_Operation_Admission_V1::manager_id()!==$manager)
            return false;
        $members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
        $member=is_array($members)?($members[$manager.'|'.$user]??null):null;
        if(!is_array($member) || ($member['state']??'')!=='active'
           || (int)($member['user_id']??0)!==$user)return false;
        $record=self::row($manager,$company);
        if(!is_array($record)
           || ($record['company']??'')!==$company
           || ($record['environment']??'')!=='staging'
           || ($record['manager_id']??'')!==$manager
           || ($record['site_uuid']??'')!==$binding['company_site_uuid']
           || ($record['canonical_origin']??'')!==$binding['canonical_origin']
           || ($record['state']??'')!=='active'
           || !in_array($right,(array)($record['rights']??[]),true)
           || !is_string($record['binding_sha256']??null)
           || !hash_equals($binding['binding_sha256'],$record['binding_sha256'])
           || !is_string($record['proposal_sha256']??null)
           || !preg_match('/^[a-f0-9]{64}$/D',$record['proposal_sha256'])
           || !is_string($record['approval_sha256']??null)
           || !hash_equals($record['proposal_sha256'],$record['approval_sha256'])
           || (int)($record['revision']??0)<3)
            return false;
        return true;
    }
    public static function entitlement(string $manager,string $company,string $intent,int $actor):array {
        $base=[
          'contract'=>self::CONTRACT,'manager_id'=>$manager,'company_code'=>$company,
          'intent'=>$intent,'user_id'=>$actor,'assignment_revision'=>self::revision($company),
          'assignment_authorized'=>false,'manager_membership_active'=>false,
          'company_binding_verified'=>false,'remote_write_ready'=>false,
          'remote_execution_performed'=>false,'blockers'=>[]
        ];
        $binding=self::binding($company,'staging');
        if(is_wp_error($binding)){
            $base['blockers'][]=$binding->get_error_code();
            return $base;
        }
        $base['company_binding_verified']=true;
        if(!isset(self::RIGHT[$intent])){
            $base['blockers'][]='unsupported_write_intent';return $base;
        }
        $base['assignment_authorized']=self::has_delegation(
            $manager,$company,self::RIGHT[$intent],$actor);
        if(!$base['assignment_authorized']){
            $base['blockers'][]='owner_approved_active_exact_environment_assignment_required';
            return $base;
        }
        $base['manager_membership_active']=true;
        $base['execution_target_environment']='staging';
        $base['execution_target_origin']=$binding['canonical_origin'];
        $base['execution_target_site_uuid']=$binding['company_site_uuid'];
        // Independent native provider is mandatory AFTER the original 305 grant.
        $nativeIntent=$intent==='content.write'?'content.write':'content.write';
        $plan=MAD4B_Company_Execution_Resolver_V1::plan($company,'staging',$nativeIntent);
        $base['native_plan_state']=$plan['state']??'UNKNOWN';
        $base['native_plan_blockers']=$plan['blockers']??[];
        $base['remote_write_ready']=($plan['execution_allowed']??false)===true
            && ($plan['remote_write_permitted']??false)===true
            && !empty($plan['selected_channel'])
            && ($plan['environment']??'')==='staging'
            && ($plan['canonical_origin']??'')===$binding['canonical_origin']
            && ($plan['site_uuid']??'')===$binding['company_site_uuid'];
        if(!$base['remote_write_ready'])$base['blockers'][]='original_native_executor_not_ready';
        return $base;
    }
}
