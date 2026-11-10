<?php
/**
 * Company-local, read-only three-way rejoin difference preview.
 * Submitted source data is UNTRUSTED. This endpoint never verifies Hub issuer
 * signatures, never updates local content, and never grants network rejoin.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Rejoin_Preview_V1 {
    public const CONTRACT='mad4b.tenant-rejoin-diff-preview.v1';
    private static function fail(string $reason,int $http=422): WP_Error {
        return new WP_Error('mad4b_rejoin_'.$reason,
            'Read-only rejoin preview: '.$reason,['status'=>$http]);
    }
    private static function identity() {
        if(!class_exists('MAD4B_Tenant_Sync',false)
           ||!MAD4B_Tenant_Operation_Authority::scope(false))
            return self::fail('verified_tenant_required',403);
        $profile=MAD4B_Tenant_Sync::profile();
        if(($profile['ready']??false)!==true
           ||!wp_is_uuid((string)($profile['site_uuid']??'')))
            return self::fail('verified_tenant_site_required',409);
        return $profile['site_uuid'];
    }
    public static function compare($payload) {
        $uuid=self::identity();if(is_wp_error($uuid))return $uuid;
        if(!is_array($payload) || array_diff(array_keys($payload),['source','mapping'])
           ||!is_array($payload['source']??null)
           ||!is_array($payload['mapping']??null)
           ||strlen(wp_json_encode($payload))>65536)
            return self::fail('invalid_candidate_payload');
        if(!class_exists('MAD4B_Tenant_Sync_Planner',false))
            return self::fail('sync_planner_unavailable',503);
        $result=MAD4B_Tenant_Sync_Planner::plan(
            $payload['source'],$payload['mapping'],$uuid);
        $conflicts=(array)($result['conflicts']??[]);
        $actions=(array)($result['operations']??[]);
        // Never echo candidate content values from the source or tenant.
        $conflict_fields=[];
        foreach($conflicts as $c)if(is_array($c))
            $conflict_fields[]=['field'=>(string)($c['field']??''),
                'reason'=>(string)($c['reason']??'')];
        $action_fields=[];
        foreach($actions as $action)if(is_array($action))
            $action_fields[]=[
                'field'=>(string)($action['field']??''),
                'action'=>(string)($action['action']??''),
                'fields'=>is_array($action['fields']??null)?array_keys($action['fields']):[],
                'requires_media_resolution'=>($action['requires_media_resolution']??false)===true];
        return [
            'contract'=>self::CONTRACT,
            'tenant_site_uuid'=>$uuid,
            'candidate_source_proof_verified'=>false,
            'candidate_identity_verified'=>false,
            'preview_result'=>$result['status']??'blocked',
            'preview_reason'=>$result['reason']??'unknown',
            'operations'=>$action_fields,
            'conflicts'=>$conflict_fields,
            'preserved_local_fields'=>$result['preserved_local_fields']??[],
            'field_decisions'=>$result['field_decisions']??[],
            'source_changes_shadowed_count'=>count((array)($result['source_changes_shadowed_locally']??[])),
            'requires_provider_signed_baseline'=>true,
            'requires_company_owned_import_readback'=>true,
            'can_apply'=>false,
            'rejoin_completed'=>false,
            'tenant_writes'=>0,
            'hub_writes'=>0,
            'financial_records_touched'=>false
        ];
    }
    public static function permission() {
        return MAD4B_Tenant_Operation_Authority::admin_read()
            ?true:self::fail('admin_required',403);
    }
    public static function routes(): void {
        register_rest_route('mad4b-tenant/v1','/rejoin-diff-preview',[
            'methods'=>'POST','permission_callback'=>[__CLASS__,'permission'],
            'callback'=>static function($request) {
                $payload=$request->get_json_params();
                $response=rest_ensure_response(self::compare($payload));
                if($response instanceof WP_REST_Response)
                    $response->header('Cache-Control','private,no-store');
                return $response;
            }
        ]);
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'routes'],86);
    }
}
MAD4B_Tenant_Rejoin_Preview_V1::boot();
