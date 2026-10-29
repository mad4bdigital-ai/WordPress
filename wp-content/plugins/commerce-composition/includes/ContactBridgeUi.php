<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * FluentCRM's native subscriber info widget; no duplicated CRM fields or
 * derived email matching. Shows a contact's EXPLICITLY linked records only.
 */
final class ContactBridgeUi
{
    public static function bootstrap():void
    {
        add_filter('fluent_crm/subscriber_info_widgets',[self::class,'widget'],50,2);
        add_action('rest_api_init',[self::class,'routes']);
    }
    public static function widget(array $widgets,$subscriber):array
    {
        if(!current_user_can('manage_options') ||
            !is_object($subscriber)||!isset($subscriber->id))return $widgets;
        $relations=ContactBridge::forContact((int)$subscriber->id,40);
        if(is_wp_error($relations))return $widgets;
        $text='<p>'.esc_html__('Record references are linked explicitly. Financial amounts and supplier-private details remain in their owning systems.','woocommerce').'</p>';
        if(!$relations)$text.='<p>'.esc_html__('No verified linked records.','woocommerce').'</p>';
        else{
            $text.='<ul>';
            foreach($relations as $r){
                $desc=$r['label'].' #'.$r['source_id'].' — '.$r['status'].' ('.$r['role'].')';
                $text.='<li>'.esc_html($desc);
                if(!empty($r['field_projection'])) {
                    $text.='<ul>';
                    foreach($r['field_projection'] as $field) {
                        $text.='<li>'.esc_html($field['label']).': '.
                            esc_html($field['state']==='linked'
                                ?__('Linked at source','woocommerce')
                                :__('Not configured','woocommerce')).'</li>';
                    }
                    $text.='</ul>';
                }
                if($r['domain']==='cpt'&&get_edit_post_link($r['source_id'])){
                    $text.=' <a href="'.esc_url(get_edit_post_link($r['source_id'])).'">'.
                        esc_html__('Open source record','woocommerce').'</a>';
                }
                $text.='</li>';
            }
            $text.='</ul>';
        }
        $text.='<p><a href="'.esc_url(add_query_arg([
            'page'=>'commerce-contact-relations',
            'contact_id'=>(int)$subscriber->id
        ],admin_url('tools.php'))).'">'.
            esc_html__('Manage linked records','woocommerce').'</a></p>';
        $widgets[]=[
            'title'=>__('Related inquiries, requests and service bookings','woocommerce'),
            'content'=>$text
        ];
        return $widgets;
    }
    public static function routes():void
    {
        register_rest_route('commerce-composition/v1','/contact-relations',[
            [
                'methods'=>\WP_REST_Server::CREATABLE,
                'callback'=>[self::class,'link'],
                'permission_callback'=>static fn()=>current_user_can('manage_options'),
                'args'=>[
                    'contact_id'=>['required'=>true,'type'=>'integer','minimum'=>1],
                    'source_key'=>['required'=>true,'type'=>'string'],
                    'source_id'=>['required'=>true,'type'=>'integer','minimum'=>1],
                    'role'=>['required'=>true,'type'=>'string'],
                ]
            ]
        ]);
        register_rest_route('commerce-composition/v1','/contact-relations/(?P<contact_id>[1-9][0-9]*)',[
            'methods'=>\WP_REST_Server::READABLE,
            'callback'=>[self::class,'list'],
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
        ]);
    }
    public static function link(\WP_REST_Request $request):\WP_REST_Response|\WP_Error
    {
        $result=ContactBridge::link((int)$request['contact_id'],
            (string)$request['source_key'],(int)$request['source_id'],
            (string)$request['role']);
        if(is_wp_error($result))return new \WP_Error($result->get_error_code(),
            'Contact relationship cannot be established',['status'=>422]);
        $response=new \WP_REST_Response($result,200);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function list(\WP_REST_Request $request):\WP_REST_Response|\WP_Error
    {
        $result=ContactBridge::forContact((int)$request['contact_id']);
        if(is_wp_error($result))return new \WP_Error($result->get_error_code(),
            'Contact relationships not available',['status'=>403]);
        $response=new \WP_REST_Response(['items'=>$result],200);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
}
