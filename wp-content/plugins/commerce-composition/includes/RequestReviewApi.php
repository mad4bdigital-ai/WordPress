<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class RequestReviewApi
{
    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'routes']);
    }
    public static function routes():void
    {
        $permissions=static fn()=>RequestReview::authorized()&&
            RequestReview::enabled();
        $taskPermission=static fn()=>$permissions()&&
            (current_user_can('commerce_manage_request_tasks') ||
             current_user_can('manage_options'));
        register_rest_route('commerce-composition/v1','/request-reviews',[
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>$permissions,
            'callback'=>[self::class,'listing'],
            'args'=>[
                'page'=>['type'=>'integer','default'=>1,'minimum'=>1],
                'page_size'=>['type'=>'integer','default'=>20,
                    'minimum'=>1,'maximum'=>50]
            ]
        ]);
        register_rest_route('commerce-composition/v1',
            '/request-reviews/(?P<request_id>[1-9][0-9]*)',[
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>$permissions,
            'callback'=>[self::class,'read']
        ]);
        register_rest_route('commerce-composition/v1',
            '/request-reviews/(?P<request_id>[1-9][0-9]*)/supplier-readiness',[
            'methods'=>\WP_REST_Server::READABLE,
            // Capability and provider are checked by the read-only source
            // preview using a site-owned provider registry. Avoid attaching
            // environment-specific capability names to reusable routes.
            'permission_callback'=>static fn()=>
                current_user_can('manage_options')&&RequestReview::enabled(),
            'callback'=>[self::class,'supplierReadiness']
        ]);
        register_rest_route('commerce-composition/v1',
            '/request-reviews/(?P<request_id>[1-9][0-9]*)/contact',[
            [
                'methods'=>\WP_REST_Server::READABLE,
                'permission_callback'=>static fn()=>current_user_can('manage_options')&&RequestReview::enabled(),
                'callback'=>[self::class,'contactPreview']
            ],
            [
                'methods'=>\WP_REST_Server::CREATABLE,
                'permission_callback'=>static fn()=>current_user_can('manage_options')&&RequestReview::enabled(),
                'callback'=>[self::class,'contactEnsure']
            ]
        ]);
        register_rest_route('commerce-composition/v1',
            '/request-reviews/(?P<request_id>[1-9][0-9]*)/task',[
            [
                'methods'=>\WP_REST_Server::READABLE,
                'permission_callback'=>$permissions,
                'callback'=>[self::class,'taskRead']
            ],
            [
                'methods'=>\WP_REST_Server::CREATABLE,
                'permission_callback'=>$taskPermission,
                'callback'=>[self::class,'taskEnsure']
            ]
        ]);
        register_rest_route('commerce-composition/v1',
            '/request-reviews/(?P<request_id>[1-9][0-9]*)/decide',[
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>$permissions,
            'callback'=>[self::class,'decide'],
            'args'=>[
                'to_state'=>['type'=>'string','required'=>true],
                'expected_revision'=>['type'=>'integer','minimum'=>0,
                    'required'=>true],
                'reason_code'=>['type'=>'string','required'=>true]
            ]
        ]);
    }
    public static function listing(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        $v=RequestReview::listing((int)$req['page'],(int)$req['page_size']);
        return self::result($v);
    }
    public static function read(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        return self::result(RequestReview::read((int)$req['request_id']));
    }
    public static function supplierReadiness(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        return self::result(RequestSupplierReadiness::preview((int)$req['request_id']));
    }
    public static function contactPreview(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        return self::result(RequestContactEvidence::preview((int)$req['request_id']));
    }
    public static function contactEnsure(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        $payload=(array)$req->get_json_params();
        if($payload)
            return new \WP_Error('request_contact_payload_forbidden',
                'The verified original Form controls contact identity',['status'=>400]);
        return self::result(RequestContactEvidence::ensure((int)$req['request_id']));
    }
    public static function taskRead(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        return self::result(FluentBoardsTaskBridge::inspect((int)$req['request_id']));
    }
    public static function taskEnsure(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        // Board/stage are taken ONLY from admin-owned site configuration.
        // The API cannot choose another board or inject a customer email.
        if(!empty($req->get_json_params()))
            return new \WP_Error('boards_unexpected_payload',
                'Task creation accepts no client-defined fields',['status'=>400]);
        return self::result(FluentBoardsTaskBridge::ensure((int)$req['request_id']));
    }
    public static function decide(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        $key=$req->get_header('idempotency-key');
        if(!is_string($key)||$key==='')
            return new \WP_Error('review_idempotency_key_required',
                'Idempotency-Key required',['status'=>400]);
        // The request has no free-text notes, customer fields or money.
        $keys=array_keys((array)$req->get_json_params());
        sort($keys,SORT_STRING);
        if($keys!==['expected_revision','reason_code','to_state'])
            return new \WP_Error('review_payload_schema_invalid',
                'Unexpected properties',['status'=>400]);
        return self::result(RequestReview::decide(
            (int)$req['request_id'],(string)$req['to_state'],
            (int)$req['expected_revision'],(string)$req['reason_code'],$key));
    }
    private static function result($value):\WP_REST_Response|\WP_Error
    {
        if(is_wp_error($value)){
            $code=$value->get_error_code();
            return new \WP_Error($code,'Request review command refused',[
                'status'=>str_contains($code,'conflict')?409:422
            ]);
        }
        $response=new \WP_REST_Response(['schema'=>1,'result'=>$value],200);
        $response->header('Cache-Control','private,no-store');
        return $response;
    }
}
