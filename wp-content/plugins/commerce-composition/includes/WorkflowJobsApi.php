<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class WorkflowJobsApi
{
    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'routes']);
    }
    public static function routes():void
    {
        register_rest_route('commerce-composition/v1','/workflow-jobs',[
            [
                'methods'=>\WP_REST_Server::CREATABLE,
                'callback'=>[self::class,'submit'],
                'permission_callback'=>static fn()=>current_user_can('manage_options'),
                'args'=>[
                    'action_key'=>['required'=>true,'type'=>'string'],
                    'payload'=>['required'=>true,'type'=>'object']
                ]
            ],
            [
                'methods'=>\WP_REST_Server::READABLE,
                'callback'=>[self::class,'listing'],
                'permission_callback'=>static fn()=>current_user_can('manage_options'),
                'args'=>[
                    'page'=>['type'=>'integer','default'=>1,'minimum'=>1],
                    'page_size'=>['type'=>'integer','default'=>20,'minimum'=>1,'maximum'=>100],
                    'state'=>['type'=>'string','default'=>'']
                ]
            ]
        ]);
        register_rest_route('commerce-composition/v1','/workflow-jobs/(?P<job_id>[1-9][0-9]*)',[
            'methods'=>\WP_REST_Server::READABLE,
            'callback'=>[self::class,'status'],
            'permission_callback'=>static fn()=>current_user_can('manage_options')
        ]);
    }
    public static function submit(\WP_REST_Request $request):\WP_REST_Response|\WP_Error
    {
        $key=$request->get_header('idempotency-key');
        if(!is_string($key)||$key==='')return new \WP_Error(
            'workflow_idempotency_key_required','An Idempotency-Key header is required',
            ['status'=>400]
        );
        $r=WorkflowJobs::enqueue(
            (string)$request['action_key'],
            (array)$request['payload'],$key,get_current_user_id(),'api'
        );
        if(is_wp_error($r)){
            $code=$r->get_error_code();
            return new \WP_Error($code,'The workflow command was not accepted',[
                'status'=>str_contains($code,'conflict')?409:422
            ]);
        }
        $res=new \WP_REST_Response([
            'schema'=>1,'job'=>$r,
            'status_path'=>'/wp-json/commerce-composition/v1/workflow-jobs/'.$r['job_id']
        ],$r['already_exists']?200:202);
        $res->header('Cache-Control','private, no-store');
        return $res;
    }
    public static function listing(\WP_REST_Request $request):\WP_REST_Response|\WP_Error
    {
        $r=WorkflowJobs::listing(get_current_user_id(),
            (int)$request->get_param('page'),
            (int)$request->get_param('page_size'),
            (string)$request->get_param('state'));
        if(is_wp_error($r))
            return new \WP_Error($r->get_error_code(),
                'Workflow jobs list unavailable',['status'=>422]);
        $response=new \WP_REST_Response(['schema'=>1]+$r,200);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }

    public static function status(\WP_REST_Request $request):\WP_REST_Response|\WP_Error
    {
        $r=WorkflowJobs::status((int)$request['job_id'],get_current_user_id());
        if(is_wp_error($r))
            return new \WP_Error($r->get_error_code(),
                'Workflow job status unavailable',['status'=>404]);
        $res=new \WP_REST_Response(['schema'=>1,'job'=>$r],200);
        $res->header('Cache-Control','private, no-store');
        return $res;
    }
}
