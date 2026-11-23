<?php
/** WP-CLI eval-file, source-authorized exact 56-rate property-cost basis migration. No post publishes or payment writes. */
if(!defined('ABSPATH')||PHP_SAPI!=='cli')throw new RuntimeException('WP-CLI only');
if(!class_exists('MAD4B_Hotel_Nightly_Cost_Basis_V1',false))throw new RuntimeException('nightly unit source verifier missing');
$apply=getenv('MAD4B_APPLY_HOTEL_NIGHTLY')==='1';
// WP-CLI starts with user ID 0; original Lifecycle Access denies financial
// writes without an authorized pricing role. Never suppress that guard.
$actor=0;
foreach(get_users(['role'=>'administrator','fields'=>'ID','number'=>30]) as $candidate){
    $candidate=(int)$candidate;
    if(user_can($candidate,'commerce_manage_pricing') &&
       user_can($candidate,'manage_options') &&
       !get_user_meta($candidate,'mad4b_company_suspension_v1',true)){
        $actor=$candidate;break;
    }
}
if(!$actor)throw new RuntimeException('authorized pricing operator unavailable');
wp_set_current_user($actor);
if(!current_user_can('commerce_manage_pricing'))
    throw new RuntimeException('original pricing capability still denied');
$ids=get_posts(['post_type'=>'property-rates','post_status'=>'publish','posts_per_page'=>-1,
    'fields'=>'ids','suppress_filters'=>true,'orderby'=>'ID','order'=>'ASC']);
if(count($ids)!==56)throw new RuntimeException('expected exactly 56 original published hotel Cost Rates');
$records=[];$before=['per_person'=>0,'per_person_night'=>0];
foreach($ids as $id){
    $id=(int)$id;
    $current=(string)get_post_meta($id,'prop_price_unit',true);
    $proof=get_post_meta($id,'_mad4b_hotel_cost_basis_normalized_v1',true);
    if(!in_array($current,['per_person','per_person_night'],true))throw new RuntimeException('unexpected basis '.$id);
    $before[$current]++;
    if($current==='per_person_night' && !MAD4B_Hotel_Nightly_Cost_Basis_V1::effective($id))
        throw new RuntimeException('normalized basis proof mismatch '.$id);
    if($current==='per_person' && $proof!=='')throw new RuntimeException('conflicting existing basis receipt '.$id);
    if((string)get_post_meta($id,'stop_sale',true)!=='1'||
       (string)get_post_meta($id,'avilabile_status',true)!=='0'||
       (string)get_post_meta($id,'mad4b_rate_price_kind_v1',true)!=='cost'||
       (string)get_post_meta($id,'mad4b_rate_scope_v1',true)!=='property'||
       (int)get_post_meta($id,'prop_related_unit_id',true)!==0||
       (string)get_post_meta($id,'currency',true)!=='USD'||
       !MAD4B_Stay_Layers_Admin_V1::owner_self_cost_reference_publishable($id))
        throw new RuntimeException('rate not authorized as historical source-only cost '.$id);
    $e=MAD4B_Hotel_Nightly_Cost_Basis_V1::evidence($id);
    if(empty($e['valid']))throw new RuntimeException('source contract mismatch '.$id.':'.($e['reason']??''));
    $records[]=['id'=>$id,'before_unit'=>$current,'old_basis_receipt'=>$proof,
        'original_sheet_cell'=>$e['source_cell'],'amount_cents'=>$e['source_cost_usd_cents'],
        'occupancy'=>$e['occupancy'],'meal_plan'=>$e['meal_plan']];
}
$policy=MAD4B_Company_Global_Profit_V1::current();
if(is_wp_error($policy)||($policy['scope']??'')!=='company'||
    ($policy['kind']??'')!=='target_margin_percent'||
    !in_array((int)($policy['percent']??0),[20,25],true))
    throw new RuntimeException('existing active global policy missing or unexpected');
$result=['site'=>home_url(),'mode'=>$apply?'apply':'preflight',
 'source_rows'=>count($records),'before'=>$before,
 'global_margin_percent'=>$policy['percent'],'global_margin_kind'=>$policy['kind'],
 'wp_cli_pricing_actor_id'=>$actor,'governance'=>'native_wordpress_pricing_cap_checked',
 'source_sheet_fields_modified'=>false,'new_supplier_approvals'=>0,
 'rate_status_changes'=>0,'woo_orders_modified'=>0,'expected_rows'=>$records];
if(!$apply){echo wp_json_encode($result,JSON_UNESCAPED_SLASHES)."\n";return;}
if($before['per_person']===0){$result['after']=$before;$result['changed']=0;echo wp_json_encode($result,JSON_UNESCAPED_SLASHES)."\n";return;}
if($before['per_person_night']!==0)throw new RuntimeException('partial previous migration not allowed');
global $wpdb;
$lock='mad4b_hotel_nightly_20261123';
if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
    throw new RuntimeException('hotel basis mutation lock busy');
$committed=false;
try {
    if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('transaction unavailable');
    foreach($records as $entry){
        $id=$entry['id'];
        $receipt=['contract'=>MAD4B_Hotel_Nightly_Cost_Basis_V1::CONTRACT,
            'rate_id'=>$id,'original_unit'=>'per_person','normalized_unit'=>'per_person_night',
            'source_cell'=>$entry['original_sheet_cell'],
            'source_cost_usd_cents'=>$entry['amount_cents'],
            'scope'=>'property_cost_not_retail',
            'basis_evidence'=>'owner_approved_uniform_hotels_template_inference',
            'declared_at_utc'=>gmdate('c'),
            'source_value_unchanged'=>true,'source_proposal_untouched'=>true,
            'company_profit_policy_independent'=>true,
            'cost_receipt_signed'=>false,'checkout_authorized'=>false];
        if(!update_post_meta($id,'prop_price_unit','per_person_night')||
           !update_post_meta($id,'_mad4b_hotel_cost_basis_normalized_v1',$receipt))
            throw new RuntimeException('postmeta update failed '.$id);
        if(!MAD4B_Hotel_Nightly_Cost_Basis_V1::effective($id)||
           !MAD4B_Stay_Layers_Admin_V1::owner_self_cost_reference_publishable($id))
            throw new RuntimeException('readback/auth guard failed '.$id);
        $proposal=get_post_meta($id,'_mad4b_supplier_sheet_proposal_v1',true);
        if(!is_array($proposal)||($proposal['price_unit']??'')!=='per_person'||
           !empty($proposal['nightly_basis_verified'])||
           !empty($proposal['cost_receipt_approved'])||
           !empty($proposal['commercial_activation_allowed']))
            throw new RuntimeException('original supplier proof unexpectedly elevated '.$id);
    }
    if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit failed');
    $committed=true;
}finally{
    if(!$committed){$wpdb->query('ROLLBACK');foreach($records as $entry)wp_cache_delete($entry['id'],'post_meta');}
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
}
$result['changed']=count($records);$result['after']=['per_person'=>0,'per_person_night'=>count($records)];
// Report only bounded identity/amount proof; no Woo/form/customer writes.
echo wp_json_encode($result,JSON_UNESCAPED_SLASHES)."\n";
