<?php
/** LAB partner assignment editor + publish validation. */
if(!defined('ABSPATH'))exit;
if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function shared_partner_validate_values($org,$product,$role,$exclude=0){
 $org=absint($org);$product=absint($product);$role=sanitize_key($role);
 if(!Shared_Product_Partner_Assignment::validate($org,$product,$role))return new WP_Error('invalid_assignment','Select a published organization, a valid product and an allowed role.');
 $existing=get_posts(['post_type'=>'partner_assignment','post_status'=>['publish','draft','pending','private','future'],'fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true,'meta_query'=>[['key'=>'_organization_id','value'=>$org],['key'=>'_product_id','value'=>$product],['key'=>'_assignment_role','value'=>$role]]]);
 foreach($existing as $id)if((int)$id!==absint($exclude))return new WP_Error('duplicate_assignment','This company, product and role are already linked.');
 return true;
}
add_action('add_meta_boxes_partner_assignment',function(){add_meta_box('shared_partner_details','Company and Product Roles',function($post){
 wp_nonce_field('shared_partner_save','shared_partner_nonce');$org=get_post_meta($post->ID,'_organization_id',true);$product=get_post_meta($post->ID,'_product_id',true);$role=get_post_meta($post->ID,'_assignment_role',true);$status=get_post_meta($post->ID,'_assignment_status',true);
 $orgs=get_posts(['post_type'=>'organization','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
 echo '<p><label>Organization<br><select name="shared_partner_org"><option value="">Select organization</option>';
 foreach($orgs as $o)echo '<option value="'.esc_attr($o->ID).'" '.selected($org,$o->ID,false).'>'.esc_html($o->post_title).' (#'.esc_html($o->ID).')</option>';
 echo '</select></label></p><p><label>Product Post ID<br><input type="number" name="shared_partner_product" min="1" value="'.esc_attr($product).'" required></label></p>';
 echo '<p><label>Role<br><select name="shared_partner_role"><option value="">Select role</option>';
 foreach(Shared_Product_Partner_Assignment::ROLES as $r)echo '<option '.selected($role,$r,false).' value="'.esc_attr($r).'">'.esc_html(ucwords(str_replace('_',' ',$r))).'</option>';
 echo '</select></label></p><p><label>Status<br><select name="shared_partner_status">';foreach(['active','pending','inactive'] as $s)echo '<option value="'.esc_attr($s).'" '.selected($status,$s,false).'>'.esc_html(ucfirst($s)).'</option>';echo '</select></label></p>';
 echo '<p>Publish requires a valid company, product and role. Duplicate combinations are rejected.</p>';
 },'partner_assignment','normal','high');});
add_filter('rest_pre_insert_partner_assignment',function($prepared,$request){
 if(is_wp_error($prepared))return $prepared;
 if(($prepared->post_status??'')!=='publish')return $prepared;
 $id=absint($request['id']??0);$meta=(array)$request->get_param('meta');
 $org=$meta['_organization_id']??($id?get_post_meta($id,'_organization_id',true):0);
 $product=$meta['_product_id']??($id?get_post_meta($id,'_product_id',true):0);
 $role=$meta['_assignment_role']??($id?get_post_meta($id,'_assignment_role',true):'');
 $result=shared_partner_validate_values($org,$product,$role,$id);
 return is_wp_error($result)?new WP_Error($result->get_error_code(),$result->get_error_message(),['status'=>400]):$prepared;
},10,2);
add_action('save_post_partner_assignment',function($id,$post){
 if(wp_is_post_revision($id)||!isset($_POST['shared_partner_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_partner_nonce'])),'shared_partner_save')||!current_user_can('edit_post',$id))return;
 $org=absint($_POST['shared_partner_org']??0);$product=absint($_POST['shared_partner_product']??0);$role=sanitize_key($_POST['shared_partner_role']??'');$status=sanitize_key($_POST['shared_partner_status']??'pending');
 $check=shared_partner_validate_values($org,$product,$role,$id);
 if(!in_array($status,['active','pending','inactive'],true))$check=new WP_Error('invalid_status','Invalid status');
 if(is_wp_error($check)){set_transient('shared_partner_error_'.get_current_user_id(),$check->get_error_message(),60);if($post->post_status==='publish'){wp_update_post(['ID'=>$id,'post_status'=>'draft']);}return;}
 foreach(['_organization_id'=>$org,'_product_id'=>$product,'_assignment_role'=>$role,'_assignment_status'=>$status] as $key=>$val)update_post_meta($id,$key,$val);
},20,2);
add_action('admin_notices',function(){$message=get_transient('shared_partner_error_'.get_current_user_id());if($message){delete_transient('shared_partner_error_'.get_current_user_id());echo '<div class="notice notice-error"><p>'.esc_html($message).'</p></div>';}});
