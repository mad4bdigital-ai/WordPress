<?php
/**
 * Plugin Name: MAD4B Company Policy Broker Contract Bridge
 * Description: Company-scoped REST policy distribution plans for original broker execution; no unapproved remote mutations.
 * Version: 0.1.1
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Policy_Broker_Bridge {
    const CONTRACT='mad4b.company-policy-broker-plan.v1';
    const PREFIX='mad4b_policy_broker_intent_v1_';
    private static function permission(): bool {
        return current_user_can('manage_options')
            && class_exists('MAD4B_Company_Strategy_V1')
            && MAD4B_Company_Strategy_V1::central();
    }
    private static function fail(string $code,int $status=422): WP_Error {
        return new WP_Error($code,'Policy broker plan rejected',['status'=>$status]);
    }
    public static function targets(): array {
        $list=class_exists('MAD4B_Company_Environment_Registry_V2')
            ?MAD4B_Company_Environment_Registry_V2::discover():[];
        if(!is_array($list))$list=[];
        $out=[];
        foreach($list as $company=>$entry){
            if(!is_string($company)||!is_array($entry))continue;
            foreach((array)($entry['environments']??[]) as $env=>$row){
                if(!in_array($env,['staging','production'],true)||!is_array($row))continue;
                $uuid=(string)($row['site_uuid']??'');
                $origin=(string)($row['canonical_origin']??'');
                $identity=(bool)($row['authorized_connections']['signed_identity']['centrally_verified']??false);
                $out[]=['company_code'=>$company,'environment'=>$env,'site_uuid'=>$uuid,
                    'canonical_origin'=>$origin,'identity_verified'=>$identity,
                    'executor_allowed'=>$identity && $origin!=='' && $uuid!=='',
                    'report_state'=>'not_a_broker_receipt'];
            }
        }
        return $out;
    }
    public static function plan(array $data) {
        if(!self::permission())return self::fail('policy_broker_central_admin_required',403);
        if(array_diff(array_keys($data),['company_code','environment',
            'site_uuid','canonical_origin','policy','expected_registry_revision','idempotency_key']))
            return self::fail('policy_broker_unknown_fields');
        $code=(string)($data['company_code']??'');
        $environment=(string)($data['environment']??'');
        $uuid=(string)($data['site_uuid']??'');
        $origin=(string)($data['canonical_origin']??'');
        $key=(string)($data['idempotency_key']??'');
        $expected=$data['expected_registry_revision']??null;
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)
            ||!in_array($environment,['staging','production'],true)
            ||!wp_is_uuid($uuid)
            ||!is_int($expected)||$expected<0
            ||!preg_match('/^[a-z0-9:_-]{8,128}$/D',$key))
            return self::fail('policy_broker_identity_or_revision_invalid');
        $target=null;
        foreach(self::targets() as $candidate)
            if($candidate['company_code']===$code&&$candidate['environment']===$environment){
                $target=$candidate;break;
            }
        if(!$target||!$target['identity_verified']
            ||!hash_equals($target['site_uuid'],$uuid)
            ||!hash_equals(rtrim($target['canonical_origin'],'/'),rtrim($origin,'/')))
            return self::fail('policy_broker_target_binding_unverified',409);
        if(!is_array($data['policy']??null)||array_is_list($data['policy']))return self::fail('policy_broker_policy_required');
        $rule=$data['policy'];
        if(array_diff(array_keys($rule),['provider_id','scope','scope_id','values',
            'conditions','priority','state','valid_from','valid_to']))
            return self::fail('policy_broker_policy_fields_invalid');
        if(($rule['state']??'draft')==='active')
            return self::fail('policy_broker_automatic_active_not_allowed',409);
        if(!class_exists('MAD4B_Company_Policy_Registry_V2'))
            return self::fail('policy_broker_registry_unavailable',503);
        $validated=MAD4B_Company_Policy_Registry_V2::validate_rule($rule);
        if(is_wp_error($validated))return self::fail('policy_broker_'.sanitize_key($validated->get_error_code()));
        // Use the target Provider Registry's validated canonical definition,
        // not unchecked caller fields, for the future delivery envelope.
        $body=array_intersect_key($validated,array_flip([
            'provider_id','scope','scope_id','conditions','values',
            'priority','state','valid_from','valid_to']));
        $body['state']=$validated['state'];
        $body['target_origin']=rtrim($origin,'/').'/';
        $body['target_site_uuid']=$uuid;
        $body['expected_registry_revision']=$expected;
        $payload=wp_json_encode($body,JSON_UNESCAPED_SLASHES);
        if(!is_string($payload)||strlen($payload)>8192)
            return self::fail('policy_broker_payload_too_large',413);
        $identity=hash('sha256',$code.'|'.$environment.'|'.$key);
        $store=self::PREFIX.$identity;
        $digest=hash('sha256',$payload);
        $existing=get_option($store,false);
        if(is_array($existing)){
            if(!hash_equals((string)($existing['payload_sha256']??''),$digest))
                return self::fail('policy_broker_idempotency_key_reused',409);
            return ['state'=>'ALREADY_PLANNED','plan'=>$existing,'changed'=>false];
        }
        $plan=[
            'contract'=>self::CONTRACT,
            'company_code'=>$code,'environment'=>$environment,
            'site_uuid'=>$uuid,'canonical_origin'=>rtrim($origin,'/'),
            'request_uri'=>'/wp-json/mad4b-policy/v2/policies','method'=>'POST',
            'payload'=>$body,'payload_sha256'=>$digest,
            'created_at'=>gmdate('c'),
            'expires_at'=>gmdate('c',time()+7*DAY_IN_SECONDS),
            'state'=>'PENDING_ORIGINAL_BROKER_AUTHORIZED_DISPATCH',
            'requires_company_local_authenticated_executor'=>true,
            'requires_original_305_authority'=>true,
            'broker_306_claimed'=>false,'native_312_executed'=>false,
            'original_313_certified'=>false,'remote_write_performed'=>false,
        ];
        if(!add_option($store,$plan,'',false)){
            $other=get_option($store,false);
            if(is_array($other) && hash_equals((string)($other['payload_sha256']??''),$digest))
                return ['state'=>'ALREADY_PLANNED','plan'=>$other,'changed'=>false];
            return self::fail('policy_broker_plan_store_conflict',409);
        }
        do_action('mad4b/company_policy/broker_plan_prepared',$plan);
        return ['state'=>'PLANNED_PENDING_ORIGINAL_BROKER','plan'=>$plan,'changed'=>true];
    }
    public static function routes(): void {
        $perm=static fn()=>self::permission();
        register_rest_route('mad4b-policy/v2','/broker/targets',[
            'methods'=>'GET','permission_callback'=>$perm,
            'callback'=>static fn()=>rest_ensure_response([
                'contract'=>self::CONTRACT,'targets'=>self::targets(),
                'remote_mutation_performed'=>false]),
        ]);
        register_rest_route('mad4b-policy/v2','/broker/plans',[
            'methods'=>'POST','permission_callback'=>$perm,
            'callback'=>static function(WP_REST_Request $request){
                $data=$request->get_json_params();
                if(!is_array($data)||array_is_list($data))
                    return self::fail('policy_broker_json_object_required',400);
                $r=self::plan($data);
                return is_wp_error($r)?$r:rest_ensure_response($r);
            },
        ]);
    }
}
add_action('rest_api_init',['MAD4B_Company_Policy_Broker_Bridge','routes'],88);
