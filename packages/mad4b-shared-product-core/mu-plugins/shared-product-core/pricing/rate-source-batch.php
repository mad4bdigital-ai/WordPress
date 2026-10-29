<?php
/** Existing Rate Generator companion: governed CSV preview → optional draft-only batch. */
if (!defined('ABSPATH') || wp_get_environment_type()!=='local' ||
    !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY ||
    !class_exists('Shared_Product_Rate_Generation'))return;

final class Shared_Product_Rate_Source_Batch {
 const MAX_ROWS=30;
 const MAX_BYTES=65536;
 const REQUIRED=['kind','parent_id','currency','price_unit','valid_from','valid_to'];
 const COLUMNS=['kind','parent_id','unit_id','supplier_sku','supplier_cost',
  'pricing_rule_kind','pricing_rule_value','amount','currency','price_unit',
  'valid_from','valid_to','single_factor','double_factor','triple_factor',
  'package_term_id','min_nights','max_nights','max_adults','max_children',
  'inventory_policy','rate_scope','accommodation_assignment_mode','accommodation_stage_source_rate_id'];

 public static function parse($csv) {
  if(!is_string($csv)||$csv===''||strlen($csv)>self::MAX_BYTES||
    str_contains($csv,"\0"))return new WP_Error('rate_batch_csv_invalid_size');
  $file=fopen('php://temp','w+');
  if(!$file)return new WP_Error('rate_batch_buffer_unavailable');
  fwrite($file,$csv);rewind($file);
  try{
   $headers=fgetcsv($file,0,',','"','');
   if(!is_array($headers)||!$headers)return new WP_Error('rate_batch_header_missing');
   if(isset($headers[0]))$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
   $headers=array_map('trim',$headers);
   if(count($headers)!==count(array_unique($headers))||
      array_diff($headers,self::COLUMNS)||
      array_diff(self::REQUIRED,$headers))return new WP_Error('rate_batch_header_unsupported');
   $rows=[];$seen=[];
   while(($line=fgetcsv($file,0,',','"',''))!==false){
    if($line===[null])continue;
    if(count($line)!==count($headers))return new WP_Error('rate_batch_column_count_mismatch');
    $row=array_combine($headers,$line);
    $input=[];
    foreach($row as $key=>$value){
     if(!is_string($value))return new WP_Error('rate_batch_cell_invalid');
     $v=trim($value);
     if($v!=='' )$input[$key]=$v;
    }
    $plan=Shared_Product_Rate_Generation::prepare($input);
    if(is_wp_error($plan))return new WP_Error('rate_batch_row_invalid',
      'Invalid row '.(count($rows)+2).': '.$plan->get_error_code(),
      ['row'=>count($rows)+2,'reason'=>$plan->get_error_code()]);
    if(isset($seen[$plan['fingerprint']]))
     return new WP_Error('rate_batch_duplicate_input',
      'Repeated rate source in rows '.$seen[$plan['fingerprint']].' and '.(count($rows)+2));
    $seen[$plan['fingerprint']]=count($rows)+2;
    $rows[]=['input'=>$input,'plan'=>$plan,'source_line'=>count($rows)+2];
    if(count($rows)>self::MAX_ROWS)return new WP_Error('rate_batch_rows_exceeded');
   }
   if(!$rows)return new WP_Error('rate_batch_rows_missing');
   // Review existing persisted Rates plus distinct, not-yet-persisted rows.
   // Shared_Product_Rate_Generation owns the one Rate scope/window comparator.
   $warnings=[];$existing=[];
   foreach($rows as $i=>$row){
    $review=Shared_Product_Rate_Generation::overlap_review($row['plan']);
    if(is_wp_error($review))return new WP_Error('rate_batch_existing_overlap_inspection_failed',
      'Row '.$row['source_line'].': '.$review->get_error_code());
    $existing[$i]=$review;
    if($review['count']){
     $warnings[]=['kind'=>'existing_rate_overlap','row'=>$row['source_line'],
       'rate_type'=>$row['plan']['post_type'],
       'rate_ids'=>array_column($review['matches'],'rate_id'),
       'published'=>$review['has_published_overlap'],'requires_owner_review'=>true];
    }
   }
   for($i=0;$i<count($rows);$i++)for($j=$i+1;$j<count($rows);$j++){
    $cmp=Shared_Product_Rate_Generation::overlap_pair($rows[$i]['plan'],$rows[$j]['plan']);
    if(is_wp_error($cmp))return new WP_Error('rate_batch_intra_source_inspection_failed',
      'Rows '.$rows[$i]['source_line'].' and '.$rows[$j]['source_line'].': '.$cmp->get_error_code());
    if(!$cmp['overlap'])continue;
    $warnings[]=['kind'=>'in_file_rate_overlap','row'=>$rows[$i]['source_line'],
      'other_row'=>$rows[$j]['source_line'],'rate_type'=>$rows[$i]['plan']['post_type'],
      'certainty'=>$cmp['certainty'],'requires_owner_review'=>true];
   }
   $digest=hash('sha256',$csv);
   // Snapshot price plans AND existing competing Rate state, not merely CSV bytes.
   // An upstream supplier-policy/FX change or Rate update requires a new preview.
   $reviewDigest=hash('sha256',wp_json_encode([
     'source'=>$digest,'plans'=>array_column($rows,'plan'),
     'persisted_overlaps'=>$existing,'review_warnings'=>$warnings]));
   return ['schema'=>'rate-source-batch.v2','digest'=>$digest,'review_digest'=>$reviewDigest,
    'row_count'=>count($rows),'rows'=>$rows,
    'review_warnings'=>$warnings,'review_count'=>count($warnings),
    'requires_owner_review'=>count($warnings)>0,
    'mode'=>'draft_only','publication_authorized'=>false,'checkout_ready'=>false];
  }finally{fclose($file);}
 }
 public static function begin_preview($csv) {
  if(!current_user_can('manage_woocommerce'))return new WP_Error('rate_batch_not_authorized');
  $result=self::parse($csv);
  if(is_wp_error($result))return $result;
  set_transient('shared_rate_batch_preview_'.get_current_user_id(),
   ['sha256'=>$result['digest'],'review_digest'=>$result['review_digest'],
    'count'=>$result['row_count'],'csv'=>$csv],600);
  return $result;
 }
 public static function create_reviewed($csv){
  if(!current_user_can('manage_woocommerce'))return new WP_Error('rate_batch_not_authorized');
  $key='shared_rate_batch_preview_'.get_current_user_id();
  $preview=get_transient($key);
  if(!is_array($preview))return new WP_Error('rate_batch_preview_required_or_expired');
  $result=self::parse($csv);
  if(is_wp_error($result))return $result;
  if(!hash_equals((string)($preview['sha256']??''),$result['digest'])||
     (int)($preview['count']??0)!==$result['row_count'])
   return new WP_Error('rate_batch_changed_since_preview');
  if(!hash_equals((string)($preview['review_digest']??''),$result['review_digest']))
   return new WP_Error('rate_batch_price_or_overlaps_changed_since_preview');
  $created=[];$reused=[];
  foreach($result['rows'] as $row){
   $out=Shared_Product_Rate_Generation::create($row['input']);
   if(is_wp_error($out)){
    // Compensate only records that this batch created and still match the exact expected state.
    $cleanup=true;
    foreach(array_reverse($created) as $item){
     $id=$item['id'];
     $safe=get_post_status($id)==='draft'&&get_post_type($id)===$item['post_type']&&
        (int)get_post_field('post_author',$id)===$item['author']&&
        hash_equals($item['fingerprint'],(string)get_post_meta($id,Shared_Product_Rate_Generation::META,true));
     if($safe)foreach($item['meta'] as $key=>$value){
      $stored=get_post_meta($id,$key,true);
      $same=is_array($value)||is_array($stored)
        ?wp_json_encode($stored)===wp_json_encode($value)
        :(string)$stored===(string)$value;
      if(!$same){$safe=false;break;}
     }
     if($safe)wp_delete_post($id,true);
     if(get_post($id))$cleanup=false;
    }
    return new WP_Error('rate_batch_draft_creation_failed',
     'Draft batch stopped at row '.$row['source_line'].'; clean compensation='.($cleanup?'yes':'no'),
     ['reason'=>$out->get_error_code(),'row'=>$row['source_line'],'compensated'=>$cleanup]);
   }
   if(($out['status']??'')==='created')$created[]=[
    'id'=>(int)$out['post_id'],'fingerprint'=>$row['plan']['fingerprint'],
    'post_type'=>$row['plan']['post_type'],'author'=>get_current_user_id(),
    'meta'=>$row['plan']['meta']];
   else $reused[]=(int)$out['post_id'];
  }
  delete_transient($key);
  return ['status'=>'draft_batch_saved','created_ids'=>array_column($created,'id'),
   'reused_ids'=>$reused,'row_count'=>$result['row_count'],
   'publication_authorized'=>false,'checkout_ready'=>false];
 }
}
