<?php
/**
 * Plugin Name: MAD4B Company Media Review MCP Client
 * Description: Exact Site-Profile-bound company-only media policy read/plan/apply Abilities over existing OAuth/MCP.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Media_MCP_V1 {
    const CONTRACT = 'mad4b.company-media-mcp.v1';
    const STATUS_ABILITY = 'mad4b/company-media-review-status';
    const PLAN_ABILITY = 'mad4b/company-media-review-plan';
    const APPLY_ABILITY = 'mad4b/company-media-review-apply';

    public static function boot():void {
        add_action('wp_abilities_api_init',[__CLASS__,'register_abilities'],45);
    }
    private static function error($code,$message='This company media operation is not authorized.'):WP_Error {
        return new WP_Error($code,$message,['status'=>403]);
    }
    public static function company_site():bool {
        if(!class_exists('MAD4B_SCP_Site_Profile',false)
           || !class_exists('MAD4B_Media_Review_Hierarchy_V1',false)
           || !MAD4B_SCP_Site_Profile::configured()
           || !MAD4B_SCP_Site_Profile::origin_enrolled()
           || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
            return false;
        if(class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
           && MAD4B_Company_Portfolio_Manager_V1::staging())
            return false;
        $state=MAD4B_SCP_Site_Profile::status();
        $effective=MAD4B_Media_Review_Hierarchy_V1::effective();
        return !empty($state['origin_match'])
            && !empty($state['environment_match'])
            && !empty($state['deployment_binding_match'])
            && !empty($effective['policy_ready'])
            && hash_equals((string)$state['site_uuid'],(string)$effective['site_uuid']);
    }
    public static function read_permission($input=[]){
        if(!self::company_site())return self::error('mad4b_company_mcp_site_profile_not_bound');
        if(!class_exists('MAD4B_SCP_OAuth_Resource_Bridge',false)
            || !MAD4B_SCP_Site_Profile::oauth_enabled()
            || !MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active())
            return self::error('mad4b_company_mcp_verified_oauth_required');
        if(!MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
            MAD4B_SCP_OAuth_Resource_Bridge::READ_SCOPE))
            return self::error('mad4b_company_mcp_read_scope_required');
        $id=get_current_user_id();
        if($id<1 || !MAD4B_SCP_Site_Profile::user_is_enrolled($id)
           || !current_user_can('manage_options'))
            return self::error('mad4b_company_mcp_enrolled_admin_required');
        if(class_exists('MAD4B_SCP_OAuth_Subject_User_Bridge',false)){
            $ctx=MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context();
            if(!is_array($ctx)||empty($ctx['authenticated'])
               ||(int)($ctx['wp_user_id']??0)!==$id
               ||!hash_equals((string)MAD4B_SCP_Site_Profile::site_uuid(),
                  (string)($ctx['site_uuid']??'')))
                return self::error('mad4b_company_mcp_verified_subject_site_mismatch');
        }
        return true;
    }
    public static function write_permission($input=[]){
        $r=self::read_permission($input);
        if(is_wp_error($r))return $r;
        if(!MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
            MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE))
            return self::error('mad4b_company_mcp_authority_step_up_required');
        if(!MAD4B_SCP_Site_Profile::write_enabled()
           || !MAD4B_SCP_Site_Profile::governed_write_ready())
            return self::error('mad4b_company_mcp_site_profile_write_not_enabled');
        $gate=class_exists('MAD4B_SCP_Policy',false)
            ? MAD4B_SCP_Policy::mutation_gate_status():[];
        if(empty($gate['effective']))
            return self::error('mad4b_company_mcp_governed_mutation_gate_disabled');
        return true;
    }
    private static function ability_meta(bool $write):array {
        return [
            'public'=>false,'show_in_rest'=>false,
            'mcp'=>[
                'public'=>false,'type'=>'tool',
                'surface'=>$write?'write':'read',
                'site_profile_bound'=>true,
                'company_local_only'=>true,
                'central_policy_mutation_allowed'=>false,
                'production_mutation_allowed'=>true,
                'verified_oauth_bearer_required'=>true,
                'oauth_authority_step_up_required'=>$write,
            ],
            'annotations'=>[
                'readonly'=>!$write,'destructive'=>false,
                'idempotent'=>!$write
            ]
        ];
    }
    public static function register_abilities():void {
        if(!self::company_site()||!function_exists('wp_register_ability'))
            return;
        $schema=[
            'type'=>'object','additionalProperties'=>false,
            'properties'=>[
                'mode'=>['type'=>'string','enum'=>[
                    'inherit','human_only','human_and_ai','ai_only'
                ]]
            ],'required'=>['mode']
        ];
        if(!function_exists('wp_has_ability')||!wp_has_ability(self::STATUS_ABILITY))
            wp_register_ability(self::STATUS_ABILITY,[
                'label'=>'Company Media Review — Current State',
                'description'=>'Read the connected company Site Profile, inherited/local review policy, media jobs and exact apply receipts. No central mutations.',
                'category'=>'mad4b-governance',
                'execute_callback'=>[__CLASS__,'status'],
                'permission_callback'=>[__CLASS__,'read_permission'],
                'input_schema'=>['type'=>'object','additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>self::ability_meta(false),
            ]);
        if(!function_exists('wp_has_ability')||!wp_has_ability(self::PLAN_ABILITY))
            wp_register_ability(self::PLAN_ABILITY,[
                'label'=>'Company Media Review — Change Plan',
                'description'=>'Prepare one exact policy change to this company only with revision and fingerprint; no write.',
                'category'=>'mad4b-governance',
                'execute_callback'=>[__CLASS__,'plan'],
                'permission_callback'=>[__CLASS__,'read_permission'],
                'input_schema'=>$schema,
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>self::ability_meta(false),
            ]);
        if(!function_exists('wp_has_ability')||!wp_has_ability(self::APPLY_ABILITY))
            wp_register_ability(self::APPLY_ABILITY,[
                'label'=>'Company Media Control — Save Company Strategy',
                'description'=>'Save this connected company’s own review strategy under its independently enrolled OAuth identity and Site Profile, after exact plan checks. Central access policy and inherited defaults remain unchanged.',
                'category'=>'mad4b-governance',
                'execute_callback'=>[__CLASS__,'apply'],
                'permission_callback'=>[__CLASS__,'write_permission'],
                'input_schema'=>[
                    'type'=>'object','additionalProperties'=>false,
                    'properties'=>array_merge($schema['properties'],[
                        'site_uuid'=>['type'=>'string','pattern'=>'^[a-f0-9-]{36}$'],
                        'expected_company_revision'=>['type'=>'integer','minimum'=>0],
                        'expected_policy_fingerprint'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                        'plan_sha256'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                    ]),
                    'required'=>[
                        'mode','site_uuid','expected_company_revision',
                        'expected_policy_fingerprint','plan_sha256'
                    ]
                ],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>self::ability_meta(true),
            ]);
    }
    public static function state_snapshot():array {
        $effective=MAD4B_Media_Review_Hierarchy_V1::effective();
        $profile=MAD4B_SCP_Site_Profile::status();
        $oauth=class_exists('MAD4B_SCP_OAuth_Resource_Bridge',false)
            ? MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status():[];
        $gate=class_exists('MAD4B_SCP_Policy',false)
            ? MAD4B_SCP_Policy::mutation_gate_status():[];
        return [
            'contract'=>self::CONTRACT,'site_uuid'=>$effective['site_uuid']??'',
            'company_origin'=>home_url('/'),
            'site_profile_revision'=>(int)($profile['revision']??0),
            'site_profile_oauth_enabled'=>(bool)MAD4B_SCP_Site_Profile::oauth_enabled(),
            'oauth_runtime_ready'=>(bool)($oauth['effective']??false),
            'site_profile_write_enabled'=>(bool)MAD4B_SCP_Site_Profile::write_enabled(),
            'governed_write_ready'=>(bool)($gate['effective']??false),
            'effective_media_policy'=>$effective,
            'content_jobs'=>MAD4B_Media_Review_Hierarchy_V1::media_queue_snapshot(),
            'media_apply_progress'=>MAD4B_Media_Review_Hierarchy_V1::applied_media_snapshot(),
            'central_policy_mutation_allowed'=>false,
            'company_local_control_supported'=>true,
            'mcp_oauth_write_available'=>!empty($oauth['effective'])
                &&MAD4B_SCP_Site_Profile::write_enabled()
                &&!empty($gate['effective']),
            'read_only'=>true,'mutation_performed'=>false
        ];
    }
    public static function status($input=[]){
        $r=self::read_permission($input);
        if(is_wp_error($r))return $r;
        return self::state_snapshot();
    }
    public static function planned_state($input){
        if(!self::company_site()||!is_array($input)
           ||array_keys($input)!==['mode'])
            return self::error('mad4b_company_mcp_plan_invalid');
        $mode=(string)$input['mode'];
        if(!in_array($mode,['inherit','ai_only','human_and_ai','human_only'],true))
            return self::error('mad4b_company_mcp_mode_invalid');
        $current=MAD4B_Media_Review_Hierarchy_V1::effective();
        $material=[
            'contract'=>self::CONTRACT,'site_uuid'=>$current['site_uuid'],
            'expected_company_revision'=>$current['company_revision'],
            'expected_policy_fingerprint'=>$current['policy_fingerprint'],
            'mode'=>$mode
        ];
        return array_merge($material,[
            'plan_sha256'=>hash('sha256',wp_json_encode($material,JSON_UNESCAPED_SLASHES)),
            'old_effective_mode'=>$current['mode'],
            'current_source'=>$current['source'],
            'central_revision'=>$current['central_revision'],
            'central_policy_mutation_allowed'=>false,
            'images_changed'=>false,
            'photos_reviewed'=>false,
            'content_jobs_changed'=>false,
            'mutation_performed'=>false
        ]);
    }
    public static function plan($input=[]){
        $r=self::read_permission($input);
        if(is_wp_error($r))return $r;
        return self::planned_state($input);
    }
    public static function apply($input=[]){
        $permission=self::write_permission($input);
        if(is_wp_error($permission))return $permission;
        if(!is_array($input)||count($input)!==5)
            return self::error('mad4b_company_mcp_apply_payload_invalid');
        $keys=['mode','site_uuid','expected_company_revision',
            'expected_policy_fingerprint','plan_sha256'];
        $given=array_keys($input);sort($given);sort($keys);
        if($given!==$keys || !is_int($input['expected_company_revision']))
            return self::error('mad4b_company_mcp_apply_schema_invalid');
        $expected=self::planned_state(['mode'=>(string)$input['mode']]);
        if(is_wp_error($expected))return $expected;
        foreach(['mode','site_uuid','expected_company_revision',
                  'expected_policy_fingerprint','plan_sha256'] as $field) {
            if(!is_string($input[$field])&&!is_int($input[$field]))
                return self::error('mad4b_company_mcp_wrong_input_type');
            if((string)$input[$field]!== (string)$expected[$field])
                return self::error('mad4b_company_mcp_plan_stale_or_wrong_site');
        }
        return MAD4B_Media_Review_Hierarchy_V1::remote_company_apply(
            $input['mode'],$input['site_uuid'],
            $input['expected_company_revision'],
            $input['expected_policy_fingerprint'],
            $input['plan_sha256']
        );
    }
}
MAD4B_Company_Media_MCP_V1::boot();

