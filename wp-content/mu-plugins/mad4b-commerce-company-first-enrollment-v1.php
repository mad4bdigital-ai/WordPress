<?php
/**
 * Plugin Name: MAD4B Commerce Company First Enrollment
 * Description: Owner-scoped, site-pinned first enrollment only. Draft policies; no commercial activation.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Commerce_Company_First_Enrollment_V1 {
    public const DEFINITION='commerce_management_company_definitions_v1';
    public const POLICY='commerce_company_policy_registry_v1';
    public const NS='commerce-composition/v1';

    public static function permitted(): bool {
        return get_current_user_id()>0
            && current_user_can('manage_options')
            && current_user_can('manage_woocommerce')
            && class_exists('MAD4B_Lifecycle_Access')
            && MAD4B_Lifecycle_Access::can('commerce_manage_operations')
            && MAD4B_Lifecycle_Access::can('commerce_manage_pricing');
    }
    private static function fail(string $code): WP_Error {
        return new WP_Error($code,'Site-bound Commerce first enrollment rejected',
            ['status'=>str_contains($code,'forbidden')?403:409]);
    }
    public static function site(): array|WP_Error {
        if (!class_exists('MAD4B_SCP_Site_Profile'))
            return self::fail('first_enrollment_site_profile_missing');
        $p=MAD4B_SCP_Site_Profile::profile();
        $s=MAD4B_SCP_Site_Profile::status();
        if(!is_array($p)||!is_array($s)||
            ($p['contract']??'')!=='mad4b.site-profile.v2'||
            !is_string($p['site_uuid']??null) ||
            !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$p['site_uuid'])||
            !is_int($p['revision']??null)||$p['revision']<1||
            !in_array(($p['environment']??''),['production','staging'],true)||
            !is_string($s['profile_digest']??null)||
            !preg_match('/^[a-f0-9]{64}$/D',$s['profile_digest'])||
            ($s['authority_ready']??false)!==true||
            ($s['write_enabled']??false)!==true||
            ($s['origin_match']??false)!==true||
            ($s['environment_match']??false)!==true||
            ($s['deployment_binding_match']??false)!==true||
            ($s['profile_authority_quarantined']??true)!==false)
            return self::fail('first_enrollment_site_authority_unready');
        return ['site_uuid'=>$p['site_uuid'],'site_profile_revision'=>$p['revision'],
            'site_profile_digest'=>$s['profile_digest'],'environment'=>$p['environment']];
    }
    private static function request_site(array $input,array $site): bool {
        return is_string($input['site_uuid']??null)&&
            hash_equals($site['site_uuid'],$input['site_uuid'])&&
            is_int($input['site_profile_revision']??null)&&
            $input['site_profile_revision']===$site['site_profile_revision']&&
            is_string($input['site_profile_digest']??null)&&
            hash_equals($site['site_profile_digest'],$input['site_profile_digest'])&&
            ($input['environment']??null)===$site['environment'];
    }
    private static function intent(array $input): bool {
        return is_string($input['intent']??null)
            &&preg_match('/^[A-Za-z0-9._:-]{20,128}$/D',$input['intent']);
    }
    /** Exact, user-authored definition. No silent source discovery or permissions grant. */
    public static function definition_document(array $input,array $site): array|WP_Error {
        if(!self::request_site($input,$site)||!self::intent($input)||
            ($input['expected_revision']??null)!==0)
            return self::fail('first_enrollment_identity_or_revision_invalid');
        $domains=$input['domains']??null;
        $source=$input['source_types']??null;
        if(!is_array($domains)||!is_array($source))
            return self::fail('first_enrollment_definition_invalid');
        $sourceKeys=array_keys($source);sort($sourceKeys);
        if($sourceKeys!==['rates','services'])
            return self::fail('first_enrollment_definition_invalid');
        $known=['forms','services','rates','reviews','quotes','tasks','orders',
            'inventory','payments','suppliers','crm','workflows','accounting','users'];
        if(array_diff(array_keys($domains),$known))
            return self::fail('first_enrollment_domain_unknown');
        foreach($domains as $id=>$state)if(!is_bool($state))
            return self::fail('first_enrollment_domain_value_invalid');
        // Commercially sensitive domains stay explicitly disabled until distinct
        // provider admission and a separately approved policy revision.
        foreach(['orders','inventory','payments','suppliers','accounting'] as $id)
            if(($domains[$id]??false)!==false)
                return self::fail('first_enrollment_financial_domain_forbidden');
        $clean=[];
        foreach(['services','rates'] as $kind){
            $list=$source[$kind];
            if(!is_array($list)||!array_is_list($list)||count($list)>16)
                return self::fail('first_enrollment_source_type_invalid');
            $seen=[];
            foreach($list as $type){
                if(!is_string($type)||
                   !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D',$type)||
                   in_array($type,['post','page','attachment','revision',
                       'shop_order','shop_order_refund','user_request'],true)||
                   isset($seen[$type])||!post_type_exists($type)||
                   !(get_post_type_object($type)->show_ui??false))
                    return self::fail('first_enrollment_source_type_invalid');
                $seen[$type]=true;
            }
            $clean[$kind]=array_keys($seen);
        }
        if(($domains['services']??false) && !$clean['services'])
            return self::fail('first_enrollment_services_required');
        if(($domains['rates']??false) && !$clean['rates'])
            return self::fail('first_enrollment_rates_required');
        if(!isset($input['rate_context_term_fields'])||
            !is_array($input['rate_context_term_fields'])||
            count($input['rate_context_term_fields'])>16||
            !isset($input['form_roles'])||$input['form_roles']!==[]||
            !isset($input['role_personas'])||$input['role_personas']!==[])
            return self::fail('first_enrollment_unreviewed_field_mapping');
        foreach($input['rate_context_term_fields'] as $rate=>$meta){
            if(!in_array($rate,$clean['rates'],true)||
               !is_string($meta)||
               !preg_match('/^_?[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D',$meta))
                return self::fail('first_enrollment_rate_mapping_invalid');
        }
        return [
            'contract'=>'commerce.management.company.v1',
            'site_uuid'=>$site['site_uuid'],
            'site_profile_revision'=>$site['site_profile_revision'],
            'site_profile_digest'=>$site['site_profile_digest'],
            'environment'=>$site['environment'],
            'revision'=>1,'domains'=>$domains,'source_types'=>$clean,
            'rate_context_term_fields'=>$input['rate_context_term_fields'],
            'form_roles'=>[],'role_personas'=>[],
            'enrollment_state'=>'draft_source_visibility_only',
            'actor_id'=>get_current_user_id(),
            'intent_sha256'=>hash('sha256',$input['intent']),
            'created_utc'=>gmdate('c'),
        ];
    }
    public static function initialize_definition(array $input): array|WP_Error {
        if(!self::permitted())return self::fail('first_enrollment_forbidden');
        $site=self::site();if(is_wp_error($site))return $site;
        $doc=self::definition_document($input,$site);
        if(is_wp_error($doc))return $doc;
        if(get_option(self::DEFINITION,null)!==null)
            return self::fail('first_enrollment_definition_already_exists');
        if(!add_option(self::DEFINITION,$doc,'',false))
            return self::fail('first_enrollment_definition_concurrent_write');
        $read=get_option(self::DEFINITION,null);
        if(!is_array($read)||$read!==$doc)
            return self::fail('first_enrollment_definition_readback_failed');
        return ['state'=>'definition_draft_created','revision'=>1,
            'site_uuid'=>$site['site_uuid'],'policy_configured'=>false,
            'commerce_write_authorized'=>false];
    }
    public static function initialize_policy(array $input): array|WP_Error {
        if(!self::permitted())return self::fail('first_enrollment_forbidden');
        $site=self::site();if(is_wp_error($site))return $site;
        if(!self::request_site($input,$site)||!self::intent($input)||
            ($input['expected_revision']??null)!==0||
            ($input['status']??'')!=='draft'||!is_array($input['rules']??null))
            return self::fail('first_enrollment_policy_payload_invalid');
        if(get_option(self::POLICY,null)!==null)
            return self::fail('first_enrollment_policy_already_exists');
        if(!class_exists('Commerce\\Composition\\CompanyCommercePolicy'))
            return self::fail('first_enrollment_native_policy_provider_missing');
        $native='Commerce\\Composition\\CompanyCommercePolicy';
        $ctx=$native::context();if(is_wp_error($ctx))return $ctx;
        if(($ctx['site_write_ready']??false)!==true||
            ($ctx['company_revision']??0)<1)
            return self::fail('first_enrollment_company_binding_unready');
        // Native policy verifies explicit OWNER-SUPPLIED financial defaults.
        // No fallback/approval/supplier contract is ever synthesized here.
        $candidate=[
            'contract'=>$native::CONTRACT,
            'site_uuid'=>$site['site_uuid'],
            'profile_revision'=>$site['site_profile_revision'],
            'profile_digest'=>$site['site_profile_digest'],
            'environment'=>$site['environment'],
            'revision'=>1,'status'=>'draft','rules'=>$input['rules'],
            'intents'=>[]
        ];
        $validated=$native::validateCandidate($candidate);
        if(is_wp_error($validated))return $validated;
        $candidate['intents']=[[
            'intent_sha256'=>hash('sha256',$input['intent']),
            'payload_sha256'=>hash('sha256',wp_json_encode($input)),
            'revision'=>1,'actor_id'=>get_current_user_id(),
            'created_utc'=>gmdate('c')
        ]];
        if(!add_option(self::POLICY,$candidate,'',false))
            return self::fail('first_enrollment_policy_concurrent_write');
        $read=$native::registry();
        if(is_wp_error($read)||($read['revision']??0)!==1||
            ($read['status']??'')!=='draft')
            return self::fail('first_enrollment_policy_readback_failed');
        return ['state'=>'policy_draft_created','revision'=>1,
            'policy_active'=>false,'commercial_write_authorized'=>false,
            'checkout_ready'=>false];
    }
    public static function rest_preview(): WP_REST_Response|WP_Error {
        if(!self::permitted())return self::fail('first_enrollment_forbidden');
        $site=self::site();if(is_wp_error($site))return $site;
        return new WP_REST_Response(['site'=>$site,
            'definition_exists'=>get_option(self::DEFINITION,null)!==null,
            'policy_exists'=>get_option(self::POLICY,null)!==null,
            'source_intent_required'=>true,'can_approve_supplier_cost'=>false,
            'no_auto_generated_financial_settings'=>true],200);
    }
    public static function rest_write(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if(!self::permitted())return self::fail('first_enrollment_forbidden');
        $nonce=$request->get_header('x-wp-nonce');
        if(!is_string($nonce)||!wp_verify_nonce($nonce,'wp_rest'))
            return self::fail('first_enrollment_nonce_invalid');
        $data=$request->get_json_params();
        if(!is_array($data)||array_is_list($data))
            return self::fail('first_enrollment_payload_invalid');
        $kind=$request['kind'];
        $result=$kind==='definition'?self::initialize_definition($data):
            ($kind==='policy'?self::initialize_policy($data):
                self::fail('first_enrollment_kind_invalid'));
        if(is_wp_error($result))return $result;
        $out=new WP_REST_Response($result,200);
        $out->header('Cache-Control','private,no-store');
        return $out;
    }
    public static function routes(): void {
        register_rest_route(self::NS,'/management/company-first-enrollment',[
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>[self::class,'permitted'],
            'callback'=>[self::class,'rest_preview']]);
        register_rest_route(self::NS,'/management/company-first-enrollment/(?P<kind>definition|policy)',[
            'methods'=>WP_REST_Server::CREATABLE,
            'permission_callback'=>[self::class,'permitted'],
            'callback'=>[self::class,'rest_write']]);
    }
}
add_action('rest_api_init',[MAD4B_Commerce_Company_First_Enrollment_V1::class,'routes'],99);

