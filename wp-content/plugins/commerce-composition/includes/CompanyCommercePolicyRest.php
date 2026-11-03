<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class CompanyCommercePolicyRest
{
    private const NS='commerce-composition/v1';
    private static function auth():bool {
        return current_user_can('manage_options')&&current_user_can('manage_woocommerce');
    }
    private static function response(array $body):\WP_REST_Response {
        $r=new \WP_REST_Response(['schema'=>'commerce.company-policy-rest.v1']+$body,200);
        $r->header('Cache-Control','private,no-store');
        return $r;
    }
    private static function fail(\WP_Error $e):\WP_Error {
        $code=$e->get_error_code();
        $status=match(true){
            str_contains($code,'permission')=>403,
            str_contains($code,'invalid'),str_contains($code,'missing')=>400,
            str_contains($code,'not_ready'),str_contains($code,'conflict'),
                str_contains($code,'unconfigured'),str_contains($code,'binding')=>409,
            default=>422
        };
        return new \WP_Error($code,'Commerce policy request unavailable',['status'=>$status]);
    }
    public static function definition():\WP_REST_Response|\WP_Error {
        $context=CompanyCommercePolicy::context();
        if(is_wp_error($context))return self::fail($context);
        $doc=CompanyCommercePolicy::registry();
        return self::response([
            'site_uuid'=>$context['site_uuid'],
            'site_profile_revision'=>$context['profile_revision'],
            'company_definition_revision'=>$context['company_revision'],
            'site_write_ready'=>$context['site_write_ready'],
            'policy_configured'=>!is_wp_error($doc),
            'registry'=>is_wp_error($doc)?null:$doc,
            'blocker'=>is_wp_error($doc)?$doc->get_error_code():null,
            'supported_sale_modes'=>['parent_with_rate','independent_rate','both','disabled'],
            'commercial_write_authorized'=>false
        ]);
    }
    public static function inspect(\WP_REST_Request $request):\WP_REST_Response|\WP_Error {
        $id=(int)$request['source_id'];
        $rid=$request->get_param('rate_id');
        $market=(string)($request->get_param('market')??'');
        $channel=(string)($request->get_param('channel')??'web');
        $decision=CompanyCommercePolicy::resolve($id,$rid===null?null:(int)$rid,
            $market,$channel);
        if(is_wp_error($decision))return self::fail($decision);
        return self::response(['resolution'=>$decision]);
    }
    public static function validate(\WP_REST_Request $request):\WP_REST_Response|\WP_Error {
        $raw=$request->get_json_params();
        if(!is_array($raw)||array_is_list($raw))
            return new \WP_Error('policy_validation_payload_invalid','Invalid policy',['status'=>400]);
        $valid=CompanyCommercePolicy::validateCandidate($raw);
        if(is_wp_error($valid))return self::fail($valid);
        return self::response([
            'state'=>'valid_schema_only','policy_revision'=>$valid['revision'],
            'rule_count'=>count($valid['rules']),
            'policy_digest'=>hash('sha256',wp_json_encode($valid,JSON_UNESCAPED_SLASHES)),
            'no_storage_or_authorization'=>true
        ]);
    }
    public static function replace(\WP_REST_Request $request):\WP_REST_Response|\WP_Error {
        $data=$request->get_json_params();
        if(!is_array($data)||array_is_list($data))
            return new \WP_Error('policy_command_payload_invalid','Invalid policy command',['status'=>400]);
        $intent=$request->get_header('idempotency-key');
        $out=CompanyCommercePolicy::replace($data,(string)$intent);
        if(is_wp_error($out))return self::fail($out);
        return self::response(['result'=>$out]);
    }
    public static function routes():void {
        $auth=static fn()=>self::auth();
        register_rest_route(self::NS,'/management/commerce-policy',[
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>$auth,
            'callback'=>[self::class,'definition']
        ]);
        register_rest_route(self::NS,
            '/management/commerce-policy/resolve/(?P<source_id>[1-9][0-9]*)',[
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>$auth,
            'callback'=>[self::class,'inspect'],
            'args'=>[
                'rate_id'=>['type'=>'integer','minimum'=>1],
                'market'=>['type'=>'string','default'=>''],
                'channel'=>['type'=>'string','default'=>'web']
            ]
        ]);
        register_rest_route(self::NS,'/management/commerce-policy/validate',[
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>$auth,
            'callback'=>[self::class,'validate']
        ]);
        register_rest_route(self::NS,'/management/commerce-policy/commands',[
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>$auth,
            'callback'=>[self::class,'replace']
        ]);
    }
    public static function bootstrap():void {
        add_action('rest_api_init',[self::class,'routes']);
    }
}
