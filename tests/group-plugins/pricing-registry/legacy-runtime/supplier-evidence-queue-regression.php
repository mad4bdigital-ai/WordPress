<?php
defined('ABSPATH')||exit;
$checks=[];$ok=static function(string $name,bool $pass)use(&$checks){$checks[$name]=$pass;};
$known=['tours.dev.mad4b.com'=>[47,13],
        'wayfatours.com'=>[56,17]];
$host=parse_url(home_url('/'),PHP_URL_HOST);
[$n,$properties]=$known[$host]??[0,0];
$doc=MAD4B_Package_Component_Readiness_V1::supplierEvidenceQueue();
$ok('admin_queue_readable',is_array($doc));
if(is_array($doc)){
 $ok('rate_count_matches_current_ledger',$doc['rates']===$n);
 $ok('property_count_matches_current_ledger',$doc['properties']===$properties);
 $ok('original_rows_grouped',count($doc['rows'])===$properties);
 $ok('no_approved_cost_receipts',$doc['approved_cost_receipts']===0);
 $ok('no_checkout_or_mutations',$doc['checkout_authorized']===false&&
  $doc['write_performed']===false);
 $ids=[];$sum=0;$seasonProof=true;$sourceCellProof=true;$unverified=true;
 foreach($doc['rows'] as $item){
  $sum+=count($item['rates']);
  $unverified=$unverified&&!empty($item['missing'])&&
   in_array('price_per_night_basis',$item['missing'],true)&&
   in_array('verified_net_cost_receipt',$item['missing'],true);
  foreach($item['rates'] as $rate){
   $ids[$rate['id']]=true;
   if(!preg_match('/^[A-Z][0-9]+$/D',$rate['cell']))$sourceCellProof=false;
   if(!preg_match('/^2026-\d{2}-\d{2} \/ 202[67]-\d{2}-\d{2}$/D',$rate['calendar']))$seasonProof=false;
   if($rate['status']!=='draft_stop_sale_no_cost_authority')$unverified=false;
   if(get_post_status($rate['id'])!=='draft'||
      get_post_meta($rate['id'],'stop_sale',true)!=='1')$unverified=false;
  }
 }
 $ok('all_rates_grouped_once',$sum===$n&&count($ids)===$n);
 $ok('source_cells_returned',$sourceCellProof);
 $ok('native_calendar_returned',$seasonProof);
 $ok('per_hotel_missing_evidence_listed',$unverified);
 $ok('no_guesstimated_unit_ids_in_queue',
   !array_key_exists('unit_id',$doc['rows'][0]['rates'][0]??[]));
 $hasNotes=!!array_filter($doc['rows'],static fn($x)=>count($x['note_cells'])>0);
 $ok('seasonal_exception_cells_grouped',$hasNotes);
}
$owner=get_current_user_id();wp_set_current_user(0);
$denied=MAD4B_Package_Component_Readiness_V1::supplierEvidenceQueue();
wp_set_current_user($owner);
$ok('anonymous_rejected',is_wp_error($denied)&&
 $denied->get_error_code()==='supplier_evidence_queue_forbidden');
$original=apply_filters('wpml_current_language',null);
try{
 do_action('wpml_switch_language','es');
 $span=MAD4B_Package_Component_Readiness_V1::supplierEvidenceQueue();
 $ok('wpml_cost_queue_preserves_counts',is_array($span)&&
  $span['rates']===$n&&$span['properties']===$properties);
}finally{do_action('wpml_switch_language',$original);}
$failed=array_keys(array_filter($checks,static fn($v)=>!$v));
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.supplier-evidence-queue-regression.v1',
 'passed'=>count($checks)-count($failed),'total'=>count($checks),
 'failures'=>$failed,'source_rates'=>$n,'no_commercial_writes'=>true,
 'checkout_authorized'=>false],JSON_UNESCAPED_SLASHES)."\n";
if($failed&&class_exists('WP_CLI'))WP_CLI::halt(1);

