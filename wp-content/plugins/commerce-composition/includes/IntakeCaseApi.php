<?php
declare(strict_types=1);
namespace Commerce\Composition;

final class IntakeCaseApi
{
    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'routes']);
    }
    public static function routes():void
    {
        $permission=static fn()=>current_user_can('manage_options')&&
            RequestReview::enabled();
        register_rest_route('commerce-composition/v1',
            '/intake-cases/(?P<request_id>[1-9][0-9]*)/order-draft',[
            [
                'methods'=>\WP_REST_Server::READABLE,
                'permission_callback'=>static fn()=>current_user_can('manage_options')&&
                    current_user_can('manage_woocommerce')&&RequestReview::enabled(),
                'callback'=>static function(\WP_REST_Request $r) {
                    $date=$r->get_param('travel_date');
                    if($date!==null&&!is_string($date))
                        return new \WP_Error('intake_rate_date_invalid',
                            'Travel date must be YYYY-MM-DD',
                            ['status'=>400]);
                    return self::result(IntakeOrderDraft::preview(
                        (int)$r['request_id'],$date));
                }
            ],
            [
                'methods'=>\WP_REST_Server::CREATABLE,
                'permission_callback'=>static fn()=>current_user_can('manage_options')&&
                    current_user_can('manage_woocommerce')&&RequestReview::enabled(),
                'callback'=>static function(\WP_REST_Request $r) {
                    if((array)$r->get_json_params()||
                       trim((string)$r->get_body())!=='')
                        return new \WP_Error('intake_order_payload_forbidden',
                            'Draft source and customer must be verified, not supplied',
                            ['status'=>400]);
                    return self::result(IntakeOrderDraft::ensure(
                        (int)$r['request_id']));
                }
            ]
        ]);
        register_rest_route('commerce-composition/v1',
            '/intake-cases/(?P<request_id>[1-9][0-9]*)',[
            [
                'methods'=>\WP_REST_Server::READABLE,
                'permission_callback'=>$permission,
                'callback'=>static function(\WP_REST_Request $r){
                    return self::result(IntakeCaseRegistry::preview(
                        (int)$r['request_id']));
                }
            ],
            [
                'methods'=>\WP_REST_Server::CREATABLE,
                'permission_callback'=>$permission,
                'callback'=>static function(\WP_REST_Request $r){
                    if((array)$r->get_json_params())
                        return new \WP_Error('intake_case_payload_not_allowed',
                            'Route and customer identity are derived only from original evidence',
                            ['status'=>400]);
                    return self::result(IntakeCaseRegistry::ensure(
                        (int)$r['request_id']));
                }
            ]
        ]);
    }
    private static function result($value):\WP_REST_Response|\WP_Error
    {
        if(is_wp_error($value)){
            $code=$value->get_error_code();
            $status=str_contains($code,'conflict')?409:
                (str_contains($code,'access_denied')?403:422);
            return new \WP_Error($code,'Intake case command refused',
                ['status'=>$status]);
        }
        $r=new \WP_REST_Response(['schema'=>1,'result'=>$value],200);
        $r->header('Cache-Control','private,no-store');
        return $r;
    }
}
