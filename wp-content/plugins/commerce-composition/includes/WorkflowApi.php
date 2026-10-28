<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class WorkflowApi
{
    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'routes']);
    }
    public static function routes():void
    {
        register_rest_route('commerce-composition/v1','/workflow-actions',[
            [
                'methods'=>\WP_REST_Server::READABLE,
                'callback'=>[self::class,'definitions'],
                'permission_callback'=>static fn()=>current_user_can('manage_options')
            ],
            [
                'methods'=>\WP_REST_Server::CREATABLE,
                'callback'=>[self::class,'execute'],
                'permission_callback'=>static fn()=>current_user_can('manage_options'),
                'args'=>[
                    'action_key'=>['required'=>true,'type'=>'string'],
                    'payload'=>['required'=>false,'type'=>'object']
                ]
            ]
        ]);
    }
    public static function definitions(\WP_REST_Request $req):\WP_REST_Response
    {
        $r=new \WP_REST_Response([
            'schema'=>1,'actions'=>WorkflowActions::definitions()
        ],200);
        $r->header('Cache-Control','private, no-store');
        return $r;
    }
    public static function execute(\WP_REST_Request $req):\WP_REST_Response|\WP_Error
    {
        $r=WorkflowActions::execute((string)$req['action_key'],
            (array)($req['payload']??[]));
        if(is_wp_error($r))return new \WP_Error($r->get_error_code(),
            'Workflow transition cannot be executed',
            ['status'=>in_array($r->get_error_code(),
                ['workflow_action_required_authority_missing'],true)?409:422]);
        $response=new \WP_REST_Response(['schema'=>1,'result'=>$r],200);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
}
