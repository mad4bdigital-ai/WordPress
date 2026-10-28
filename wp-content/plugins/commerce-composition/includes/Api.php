<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Read-only public form descriptor. No sale authorization, submitted-price
 * parsing, supplier identity, or WooCommerce order creation.
 */
final class Api
{
    public static function bootstrap(): void
    {
        add_action('rest_api_init',[self::class,'register']);
    }
    public static function register(): void
    {
        register_rest_route('commerce-composition/v1','/configuration/(?P<parent_id>[1-9][0-9]*)',[
            'methods'=>\WP_REST_Server::READABLE,
            'callback'=>[self::class,'describe'],
            'permission_callback'=>'__return_true',
            'args'=>[
                'parent_id'=>['required'=>true,'type'=>'integer','minimum'=>1],
                'rate_id'=>['required'=>false,'type'=>'integer','minimum'=>1],
            ],
        ]);
    }
    public static function describe(\WP_REST_Request $request):\WP_REST_Response|\WP_Error
    {
        $parent=(int)$request['parent_id'];
        $rate=$request->get_param('rate_id');
        $rate=$rate!==null?(int)$rate:null;
        if($parent<1||($rate!==null&&$rate<1))return new \WP_Error(
            'composition_query_invalid','Invalid source identity',['status'=>400]);
        $source=get_post($parent);
        // Do not expose unlisted or restricted source policies. Admins can use the
        // separate Tools interface for privately stored post policies.
        if(!$source || get_post_status($source)!=='publish' ||
            !is_post_publicly_viewable($source))
            return new \WP_Error('composition_source_not_public',
                'Source is not publicly available',['status'=>404]);
        if($rate!==null && get_post_status($rate)!=='publish')
            return new \WP_Error('composition_rate_not_public','Rate is not available',['status'=>404]);
        $descriptor=FormRouter::describe($parent,$rate);
        if(is_wp_error($descriptor))return new \WP_Error(
            'composition_configuration_unavailable',
            'Booking configuration is not available',
            ['status'=>404]);
        // FormRouter intentionally returns only source choice IDs, no customer
        // data, hidden supplier refs, original financial quotes or tax data.
        $response=new \WP_REST_Response($descriptor,200);
        $response->header('Cache-Control','private, no-store, must-revalidate');
        return $response;
    }
}
