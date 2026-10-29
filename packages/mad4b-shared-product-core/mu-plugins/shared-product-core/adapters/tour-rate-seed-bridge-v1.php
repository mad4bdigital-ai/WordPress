<?php
/**
 * Local Lab: the Tour CPT editor itself is an authoritative seed for the existing
 * native draft Rate Generator. No vendor cost, contract, or alternate price store.
 */
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
final class Shared_Tour_Rate_Seed_Bridge_v1 {
 const META='_shared_tour_rate_seed_input_v1';
 const FIELDS=['valid_from','valid_to','package_term_id','program_term_id','season_term_id','assignment_mode','source_type','source_rate_id','factor_mode'];
 static function seed(int $id):array {
  $m=get_post_meta($id,self::META,true);
  $m=is_array($m)?$m:[];
  $programs=wp_get_post_terms($id,'programs',['fields'=>'ids']);
  if(is_wp_error($programs))$programs=[];
  $seasons=wp_get_post_terms($id,'season',['fields'=>'ids']);
  if(is_wp_error($seasons))$seasons=[];
  $packages=wp_get_post_terms($id,'package_levels',['fields'=>'ids']);
  if(is_wp_error($packages))$packages=[];
  return [
   'valid_from'=>(string)($m['valid_from']??''),
   'valid_to'=>(string)($m['valid_to']??''),
   'package_term_id'=>absint($m['package_term_id']??(count($packages)===1?$packages[0]:0)),
   'program_term_id'=>shared_product_program_mode_supported(get_post_meta($id,'tour_mode',true)) ?absint($m['program_term_id']??(count($programs)===1?$programs[0]:0)):0,
   'season_term_id'=>absint($m['season_term_id']??(count($seasons)===1?$seasons[0]:0)),
   'assignment_mode'=>in_array(($m['assignment_mode']??''),['manual','auto'],true)?$m['assignment_mode']:'manual',
   'source_type'=>($m['source_type']??'direct')==='existing_rate'?'existing_rate':'direct',
   'source_rate_id'=>absint($m['source_rate_id']??0),
   'factor_mode'=>($m['factor_mode']??'manual')==='inherit_source'?'inherit_source':'manual',
  ];
 }
 static function validated(array $raw,int $tour) {
  if(array_diff(array_keys($raw),self::FIELDS))return new WP_Error('tour_seed_unknown_field');
  $out=[];
  foreach(['valid_from','valid_to'] as $key){
   $v=$raw[$key]??'';
   if(!is_string($v))return new WP_Error('tour_seed_date_invalid');
   if($v!==''&&!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$v))
    return new WP_Error('tour_seed_date_invalid');
   if($v!==''){
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));
    if(!$date||$date->format('Y-m-d')!==$v)return new WP_Error('tour_seed_date_invalid');
   }
   $out[$key]=$v;
  }
  if($out['valid_from']!==''&&$out['valid_to']!==''&&$out['valid_from']>$out['valid_to'])
   return new WP_Error('tour_seed_dates_reversed');
  foreach(['package_term_id'=>'package_levels','program_term_id'=>'programs','season_term_id'=>'season'] as $key=>$taxonomy){
   $value=$raw[$key]??0;
   if(!is_scalar($value)||!preg_match('/^\d{1,9}$/D',(string)$value))
    return new WP_Error('tour_seed_term_invalid');
   $term=absint($value);
   if($term){
    if(!term_exists($term,$taxonomy))return new WP_Error('tour_seed_term_not_found');
    if($taxonomy==='programs' && !shared_product_tour_program_allowed_v1($tour,$term))
      return new WP_Error('tour_seed_cruise_program_not_linked');
    if($taxonomy==='programs' || $taxonomy==='package_levels'){
      $allowed=wp_get_post_terms($tour,$taxonomy,['fields'=>'ids']);
      if(is_wp_error($allowed)||($allowed&&!in_array($term,array_map('intval',$allowed),true)))
       return new WP_Error('tour_seed_term_not_related');
    }
   }
   $out[$key]=$term;
  }
  $mode=$raw['assignment_mode']??'manual';
  if(!is_string($mode)||!in_array($mode,['manual','auto'],true))
   return new WP_Error('tour_seed_assignment_invalid');
  $out['assignment_mode']=$mode;
  $origin=$raw['source_type']??'direct';
  if(!in_array($origin,['direct','existing_rate'],true))return new WP_Error('tour_seed_source_type_invalid');
  $sourceRaw=$raw['source_rate_id']??0;
  if(!is_scalar($sourceRaw)||!preg_match('/^\d{1,9}$/D',(string)$sourceRaw))
   return new WP_Error('tour_seed_source_id_invalid');
  $sourceRate=absint($sourceRaw);
  if($origin==='existing_rate'&&(!$sourceRate||get_post_type($sourceRate)!=='tour-rates'||
     (int)get_post_meta($sourceRate,'related_tour_id',true)!==$tour||
     !in_array(get_post_status($sourceRate),['publish','draft'],true)))
     return new WP_Error('tour_seed_source_rate_not_related');
  $factorMode=$raw['factor_mode']??'manual';
  if(!in_array($factorMode,['manual','inherit_source'],true))
   return new WP_Error('tour_seed_factor_mode_invalid');
  $out['source_type']=$origin;$out['source_rate_id']=$sourceRate;
  $out['factor_mode']=$factorMode;
  return $out;
 }
 static function payload(int $tour){
  if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
   return new WP_Error('tour_seed_parent_not_published');
  if(!class_exists('Shared_Tour_Pricing_Entry_v1')||
     !class_exists('Shared_Product_Rate_Generation'))
   return new WP_Error('tour_seed_generator_unavailable');
  $choice=Shared_Tour_Pricing_Entry_v1::chosen($tour);
  $entry=self::seed($tour);
  // Explicit selection of an existing linked Rate is a valid Seed even if the
  // Tour otherwise uses the simplest direct per-person public display mode.
  if(!in_array($choice['resolved'],['seed_rate_simulation','existing_rates'],true)
     && $entry['source_type']!=='existing_rate')
   return new WP_Error('tour_seed_mode_not_enabled');
  $valid=self::validated($entry,$tour);
  if(is_wp_error($valid))return $valid;
  if(!$valid['valid_from']||!$valid['valid_to'])
   return new WP_Error('tour_seed_period_required');
  $value=get_post_meta($tour,'start_price',true);
  $currency=(string)get_post_meta($tour,'tour_base_currency',true);
  $basis=(string)get_post_meta($tour,'tour_price_unit',true);
  $factors=Shared_Tour_Pricing_Entry_v1::factors($tour);
  if($valid['source_type']==='existing_rate'){
   $src=$valid['source_rate_id'];
   $amounts=[];
   foreach(['single','double','triple'] as $name){
     $raw=get_post_meta($src,$name.'_price',true);
     $amounts[$name]=is_numeric($raw)&&(float)$raw>0?(float)$raw:0.0;
   }
   $primary=$amounts['double']>0?$amounts['double']:$amounts['single'];
   if($primary<=0)return new WP_Error('tour_seed_existing_rate_missing_base_price');
   $currency=(string)get_post_meta($src,'base_currency',true);
   $basis=(string)get_post_meta($src,'unit_type',true);
   if($valid['factor_mode']==='inherit_source'){
     foreach($amounts as $name=>$amount){
       if($amount<=0)return new WP_Error('tour_seed_existing_occupancy_missing');
       $factor=$amount/$primary;
       if($factor>100||$factor<0.0001)return new WP_Error('tour_seed_existing_factor_outside_limits');
       $factors[$name]=number_format($factor,4,'.','');
     }
   }
   $value=(string)$primary;
   foreach(['package_term_id'=>'related_package_term_id','program_term_id'=>'related_program_term_id'] as $key=>$meta){
     if($key==='program_term_id'){
       if(!shared_product_program_mode_supported(get_post_meta($tour,'tour_mode',true)))continue;
       $linked=absint(get_post_meta($src,$meta,true));
       if($linked && !shared_product_tour_program_allowed_v1($tour,$linked))
         return new WP_Error('tour_seed_existing_rate_program_unlinked');
       if(!$valid[$key])$valid[$key]=$linked;
     }elseif(!$valid[$key])$valid[$key]=absint(get_post_meta($src,$meta,true));
   }
   if(!$valid['season_term_id']){
     $terms=wp_get_post_terms($src,'season',['fields'=>'ids']);
     if(!is_wp_error($terms)&&count($terms)===1)$valid['season_term_id']=(int)$terms[0];
   }
  }
  if(!is_numeric($value)||(float)$value<=0||
     !preg_match('/^[A-Z]{3}$/D',$currency)||$basis!=='per_person')
   return new WP_Error('tour_seed_retail_input_required');
  $input=['kind'=>'tour','parent_id'=>$tour,'amount'=>(string)$value,
   'currency'=>$currency,'price_unit'=>'per_person',
   'valid_from'=>$valid['valid_from'],'valid_to'=>$valid['valid_to'],
   'single_factor'=>$factors['single'],'double_factor'=>$factors['double'],
   'triple_factor'=>$factors['triple'],
   'accommodation_assignment_mode'=>$valid['assignment_mode'],
   'seed_origin'=>$valid['source_type']==='existing_rate'?'tour_rate':'tour_cpt',
   'seed_source_rate_id'=>$valid['source_type']==='existing_rate'?$valid['source_rate_id']:0];
  foreach(['package_term_id','program_term_id','season_term_id'] as $key)
   if($valid[$key])$input[$key]=$valid[$key];
  return $input;
 }
 static function plan(int $tour){
  $input=self::payload($tour);
  if(is_wp_error($input))return $input;
  return Shared_Product_Rate_Generation::prepare($input);
 }
 static function render_fields(WP_Post $post):void {
  $data=self::seed($post->ID);
  $amount=get_post_meta($post->ID,'start_price',true);
  $currency=(string)get_post_meta($post->ID,'tour_base_currency',true);
  echo '<hr><h4>Rate Generator Seed — saved in this Tour CPT</h4>';
  echo '<p><label>Seed Source <select name="shared_tour_seed_data[source_type]">';
  foreach(['direct'=>'Tour direct selling price','existing_rate'=>'One existing Tour Rate'] as $v=>$label)
    echo '<option value="'.esc_attr($v).'" '.selected($data['source_type'],$v,false).'>'.esc_html($label).'</option>';
  echo '</select></label> ';
  echo '<label>Existing Rate <select name="shared_tour_seed_data[source_rate_id]"><option value="0">Not selected</option>';
  $linked=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft'],
    'posts_per_page'=>75,'fields'=>'ids','suppress_filters'=>true,
    'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$post->ID]]]);
  foreach($linked as $rate)echo '<option value="'.(int)$rate.'" '.
     selected($data['source_rate_id'],(int)$rate,false).'>#'.(int)$rate.' · '.
     esc_html(get_the_title($rate)).'</option>';
  echo '</select></label></p>';
  echo '<p><label>Occupancy factors from <select name="shared_tour_seed_data[factor_mode]">';
  foreach(['manual'=>'Tour editor controls (Single/Double/Triple)','inherit_source'=>'Existing Rate actual occupancy prices (must all exist)'] as $v=>$label)
   echo '<option value="'.esc_attr($v).'" '.selected($data['factor_mode'],$v,false).'>'.esc_html($label).'</option>';
  echo '</select></label></p>';
  echo '<p>The price below is the same <code>start_price</code> field used by the Tour, not a separate selling Rate. Saving this Tour updates the original CPT fields.</p>';
  echo '<p><label>Per-person selling price <input type="number" name="shared_tour_seed_retail_amount" min="0.0001" step="0.0001" max="99999999" value="'.
    esc_attr($amount).'" style="width:160px"></label> ';
  echo '<label>Currency <select name="shared_tour_seed_retail_currency">';
  foreach(get_woocommerce_currencies() as $code=>$label)
   echo '<option value="'.esc_attr($code).'" '.
    selected($currency,$code,false).'>'.esc_html($code).'</option>';
  echo '</select></label></p>';
  foreach(['valid_from'=>'Rate valid from','valid_to'=>'Rate valid to'] as $key=>$label)
   echo '<p><label>'.esc_html($label).' <input type="date" name="shared_tour_seed_data['.
     esc_attr($key).']" value="'.esc_attr($data[$key]).'"></label></p>';
  foreach(['package_term_id'=>['package_levels','Package (optional)'],
           'program_term_id'=>['programs','Program (optional)'],
           'season_term_id'=>['season','Season (optional)']] as $key=>[$tax,$label]){
   echo '<p><label>'.esc_html($label).' <select name="shared_tour_seed_data['.
     esc_attr($key).']"><option value="0">Not specified</option>';
   $terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'number'=>350]);
   if(!is_wp_error($terms))foreach($terms as $term)
    echo '<option value="'.(int)$term->term_id.'" '.selected($term->term_id,$data[$key],false).'>'.
      esc_html($term->name).'</option>';
   echo '</select></label></p>';
  }
  echo '<p><label>Accommodation: <select name="shared_tour_seed_data[assignment_mode]">';
  foreach(['manual'=>'Manual / already included in Tour price','auto'=>'Discover eligible hotels (never confirms availability)'] as $v=>$label)
   echo '<option value="'.esc_attr($v).'" '.
     selected($v,$data['assignment_mode'],false).'>'.esc_html($label).'</option>';
  echo '</select></label></p>';
  $plan=self::plan($post->ID);
  if(is_wp_error($plan))
    echo '<p><small>Seed readiness: <code>'.esc_html($plan->get_error_code()).'</code>. Save the Tour after completing the fields.</small></p>';
  else
    echo '<p><small>Native Generator input ready: '.$plan['parameters']['amount'].' '.
       esc_html($plan['parameters']['currency']).' × season / occupancy factors; will make <strong>Draft + Stop Sale</strong> only.</small></p>';
  if(get_post_status($post->ID)!=='publish')echo '<p>Publish this Tour first to use its native Rate generator.</p>';
  if(current_user_can('manage_woocommerce')){
   echo '<p><button class="button" type="submit" form="shared-tour-seed-post-action" name="seed_operation" value="preview">Preview native Draft</button> ';
   echo '<button class="button button-primary" type="submit" form="shared-tour-seed-post-action" name="seed_operation" value="create">Generate Draft Rate from saved Tour</button></p>';
  }
  echo '<p><small>Save/Update the Tour before generating. The buttons read persisted CPT fields, not unsaved browser values. Identical input reuses the same Draft. Missing supplier purchase cost is allowed.</small></p>';
  $msg=get_transient('shared_tour_seed_flash_'.get_current_user_id().'_'.$post->ID);
  if(is_array($msg)){
    delete_transient('shared_tour_seed_flash_'.get_current_user_id().'_'.$post->ID);
    echo '<div class="notice '.(empty($msg['error'])?'notice-success':'notice-error').'"><p>'.esc_html($msg['message']??'').'</p>';
    if(!empty($msg['post_id']))echo '<p><a href="'.
      esc_url(get_edit_post_link($msg['post_id'])).'">Open generated Draft Rate #'.
      (int)$msg['post_id'].'</a></p>';
    echo '</div>';
  }
 }
 static function save(int $id):void {
  if(wp_is_post_revision($id)||!current_user_can('edit_post',$id))return;
  $nonce=$_POST['shared_tour_price_entry_nonce']??'';
  if(!is_string($nonce)||!wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),
      'shared_tour_price_entry_'.$id))return;
  $raw=wp_unslash($_POST);
  $amount=$raw['shared_tour_seed_retail_amount']??null;
  $currency=$raw['shared_tour_seed_retail_currency']??null;
  $meta=$raw['shared_tour_seed_data']??null;
  if(!is_array($meta)||!is_string($amount)||!is_string($currency))return;
  $valid=self::validated($meta,$id);
  if(is_wp_error($valid))return;
  $allowPrice=$amount!==''&&preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,4})?$/D',$amount)&&
    (float)$amount>0;
  $allowedCurrencies=get_woocommerce_currencies();
  if($amount!==''&&!$allowPrice)return;
  if(!isset($allowedCurrencies[$currency]))return;
  // Canonical Tour CPT fields only; no second price record and no Rate mutation.
  if($allowPrice){
    update_post_meta($id,'start_price',$amount);
    update_post_meta($id,'tour_base_currency',$currency);
    update_post_meta($id,'tour_price_unit','per_person');
  }
  update_post_meta($id,self::META,$valid);
 }
 static function action():void {
  if(wp_get_environment_type()!=='local'||!current_user_can('manage_woocommerce'))
   wp_die('Rate generator unavailable',403);
  $id=absint($_POST['tour_id']??0);
  if(!$id||get_post_type($id)!=='tours-and-activities'||
     !current_user_can('edit_post',$id))wp_die('Invalid Tour',403);
  check_admin_referer('shared_tour_seed_action_'.$id,'shared_tour_seed_nonce');
  $op=sanitize_key((string)($_POST['seed_operation']??''));
  if(!in_array($op,['preview','create'],true))wp_die('Invalid Rate operation',400);
  $input=self::payload($id);
  $result=is_wp_error($input)?$input:
    ($op==='preview'?Shared_Product_Rate_Generation::prepare($input):
      Shared_Product_Rate_Generation::create($input));
  $message=is_wp_error($result)
    ?['error'=>true,'message'=>'Native Rate Generator: '.$result->get_error_code()]
    :($op==='preview'
      ?['message'=>'Native Rate Draft preview is valid: '.
        $result['parameters']['amount'].' '.$result['parameters']['currency'].
        ' · no Rate created.']
      :['message'=>'Native Rate Generator: '.$result['status'].
         '. Draft and Stop Sale until staff approval.',
        'post_id'=>(int)$result['post_id']]);
  set_transient('shared_tour_seed_flash_'.get_current_user_id().'_'.$id,$message,180);
  wp_safe_redirect(get_edit_post_link($id,'')?:admin_url('post.php?post='.$id.'&action=edit'));
  exit;
 }
}
add_action('save_post_tours-and-activities',[Shared_Tour_Rate_Seed_Bridge_v1::class,'save'],900);
add_action('admin_post_shared_tour_seed_rate',[Shared_Tour_Rate_Seed_Bridge_v1::class,'action']);
add_action('admin_footer',static function(){
 $screen=function_exists('get_current_screen')?get_current_screen():null;
 if(!$screen||$screen->base!=='post'||$screen->post_type!=='tours-and-activities')return;
 $id=absint($_GET['post']??0);
 if(!$id||!current_user_can('manage_woocommerce'))return;
 echo '<form id="shared-tour-seed-post-action" method="post" action="'.
  esc_url(admin_url('admin-post.php')).'">';
 echo '<input type="hidden" name="action" value="shared_tour_seed_rate">';
 echo '<input type="hidden" name="tour_id" value="'.$id.'">';
 wp_nonce_field('shared_tour_seed_action_'.$id,'shared_tour_seed_nonce');
 echo '</form>';
});
