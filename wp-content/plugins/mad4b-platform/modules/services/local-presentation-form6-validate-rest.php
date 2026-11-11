<?php
/** Local-only, admin-inspected, NONFINANCIAL Form 6 candidate. */
defined('ABSPATH') || exit;
add_action('rest_api_init', static function():void {
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
 register_rest_route('shared-product/v1','/presentation-form6/validate',[
  'methods'=>WP_REST_Server::CREATABLE,
  'permission_callback'=>static fn():bool=>
   current_user_can('manage_options') &&
   function_exists('partner_rights_local_write_hold_active') &&
   partner_rights_local_write_hold_active(),
  'callback'=>static function(WP_REST_Request $req){
   $data=$req->get_json_params();
   if(!is_array($data)||array_diff(array_keys($data),['form','selection'])||
      !isset($data['form'],$data['selection'])||
      !is_array($data['form'])||!is_array($data['selection']))
    return new WP_Error('presentation_candidate_invalid_payload','Invalid preview request',['status'=>422]);
   $result=Shared_Product_Presentation_Form6_Candidate_v1::validate($data['form'],$data['selection']);
   if(is_wp_error($result))return new WP_Error($result->get_error_code(),'Preview candidate rejected',['status'=>422]);
   return rest_ensure_response($result);
  }
 ]);
},24);
