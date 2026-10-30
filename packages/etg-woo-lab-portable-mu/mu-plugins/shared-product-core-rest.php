<?php
if (!defined('ABSPATH')) exit;
add_action('rest_api_init',function(){
 if(!class_exists('Shared_Product_Date_Service'))return;
 register_rest_route('shared-product/v1','/rate-dates',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>function($r){
  $id=absint($r->get_param('product_id'));$from=(string)($r->get_param('from')?:gmdate('Y-m-d'));$to=(string)($r->get_param('to')?:gmdate('Y-m-d',strtotime('+180 days')));
  $result=Shared_Product_Date_Service::coverage($id,(string)$r->get_param('season'),absint($r->get_param('program_id')),$from,$to);
  if(is_wp_error($result))return new WP_Error($result->get_error_code(),$result->get_error_message(),['status'=>$result->get_error_code()==='unsupported_product'?404:400]);
  return rest_ensure_response($result);
 }]);
});
