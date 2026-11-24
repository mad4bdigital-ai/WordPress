<?php
/**
 * Plugin Name: MAD4B Rate Plan Company Policy Recheck Bridge
 * Version: 1.0.0
 * Description: Enqueues original event-driven Rate Planner recheck when company policy or approved source basis changes. No new jobs, rates, approvals or publishing.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Rate_Plan_Company_Policy_Recheck_V1',false)){
final class MAD4B_Rate_Plan_Company_Policy_Recheck_V1 {
    public const CONTRACT='mad4b.rate-plan.company-policy-recheck.v1';
    private const OPTIONS=[
        'mad4b_company_policy_registry_v2',
        'mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1',
        'mad4b_guide_daily_base_cost_v1',
    ];
    public static function relevant($key):bool {
        return is_string($key)&&in_array($key,self::OPTIONS,true);
    }
    /** Only delegate to the existing active plan scheduler, never create a second queue. */
    public static function queue($key):bool {
        if(!self::relevant($key) ||
           !class_exists('MAD4B_Rate_Planner',false)||
           !function_exists('mad4b_rate_authoring_enabled')||
           !mad4b_rate_authoring_enabled())return false;
        MAD4B_Rate_Planner::schedule();
        return true;
    }
    public static function updated($key,$old,$new):void {
        if($old===$new)return;
        self::queue($key);
    }
    public static function added($key,$value):void {self::queue($key);}
    public static function deleted($key):void {self::queue($key);}
    public static function boot():void {
        add_action('updated_option',[self::class,'updated'],110,3);
        add_action('added_option',[self::class,'added'],110,2);
        add_action('deleted_option',[self::class,'deleted'],110,1);
    }
}
MAD4B_Rate_Plan_Company_Policy_Recheck_V1::boot();
}
