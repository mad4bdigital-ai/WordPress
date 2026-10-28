<?php
/** WooCommerce Settings tab — parameterized existing CPT rate drafts only. */
if (!defined('ABSPATH') || wp_get_environment_type()!=='local' || !class_exists('Shared_Product_Rate_Generation')) return;
add_filter('woocommerce_settings_tabs_array',static function($tabs){
 $tabs['rate_generation']=__('Rate Generation','woocommerce');return $tabs;
},82);
add_action('woocommerce_settings_tabs_rate_generation',static function(){
 if(!current_user_can('manage_woocommerce'))return;
 $types=['tour'=>'Tour Rate','accommodation'=>'Unit / Accommodation Rate','transport'=>'Transport Rate'];
 // A safe editor handoff; GET can prefill a draft proposal but never invokes Create.
 $sourceId=absint($_GET['source_tour_id']??0);
 if($sourceId&&(
    get_post_type($sourceId)!=='tours-and-activities'||
    !current_user_can('edit_post',$sourceId)))$sourceId=0;
 $seedAmount=$sourceId?(string)get_post_meta($sourceId,'start_price',true):'';
 $seedCurrency=$sourceId?(string)get_post_meta($sourceId,'tour_base_currency',true):'';
 $seedFactors=$sourceId&&class_exists('Shared_Tour_Pricing_Entry_v1')
   ?Shared_Tour_Pricing_Entry_v1::factors($sourceId):['single'=>'1','double'=>'1','triple'=>'1'];

 $uid=get_current_user_id();
 $notice=get_transient('shared_product_generation_flash_'.$uid);
 delete_transient('shared_product_generation_flash_'.$uid);
 echo '<h2>Rate Generation — existing CPT drafts</h2>';
 echo '<p>Enter a parent product, amount, currency and date range. Generates an unpublished Rate post in the existing CPT. No prices, inventory or supplier availability are approved automatically.</p>';
 if(is_array($notice)){
  $ok=empty($notice['error']);
  echo '<div class="notice '.($ok?'notice-success':'notice-error').'"><p>'.esc_html($notice['message']??'').'</p>';
  if(!empty($notice['id']))echo '<p><a href="'.esc_url(get_edit_post_link($notice['id'])).'">Review generated Rate draft #'.(int)$notice['id'].'</a></p>';
  echo '</div>';
 }
 // WooCommerce already owns #mainform.
 echo '<div id="shared-rate-generation-fields">';
 wp_nonce_field('shared_product_rate_generation','shared_rate_generation_nonce');
 echo '<table class="form-table"><tbody>';
 $field=static function($label,$html,$desc=''){
  echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.$html;
  if($desc!=='')echo '<p class="description">'.esc_html($desc).'</p>';
  echo '</td></tr>';
 };
 $choices='<select name="kind" id="rate-gen-kind">';
 foreach($types as $value=>$label)$choices.='<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
 $choices.='</select>';
 $field('Rate kind',$choices,'Uses tour-rates, property-rates or transport-rates; never creates a new CPT.');
 $field('Parent product ID','<input name="parent_id" required type="number" min="1" step="1" value="'.esc_attr($sourceId?:'').'" />',
  'Tour ID, Property ID or Transportation ID (existing JetEngine CPT post).');
 $field('Unit ID (accommodation only)','<input name="unit_id" type="number" min="1" step="1"/>',
  'Existing property → unit relation is verified by Booking Experience.');
 foreach($types as $kind=>$label){
  $markup='<select name="price_unit_'.$kind.'" data-rate-kind="'.esc_attr($kind).'">';
  foreach(Shared_Product_Rate_Generation::units($kind) as $value=>$unitLabel)
   $markup.='<option value="'.esc_attr($value).'">'.esc_html($unitLabel).'</option>';
  $markup.='</select>';
  $field('Pricing unit — '.$label,$markup,'Values read from the existing JetEngine glossary.');
 }
 $field('Accommodation terms (optional for draft)',
  '<span data-stay-fields>Minimum nights <input name="min_nights" type="number" min="1" max="365" style="width:72px"/> '.
  'Maximum nights <input name="max_nights" type="number" min="1" max="365" style="width:72px"/> '.
  'Maximum adults <input name="max_adults" type="number" min="1" max="365" style="width:72px"/> '.
  'Maximum children <input name="max_children" type="number" min="0" max="365" style="width:72px"/> '.
  '<select name="inventory_policy"><option value="">Select inventory policy</option>'.
  '<option value="on_request">Supplier confirmation on request</option>'.
  '<option value="allotment">Managed allotment (needs stock setup)</option></select></span>',
  'If left blank, draft is incomplete and cannot be treated as an available stay.');
 $field('Base amount','<input name="amount" type="number" min="0.0001" max="99999999" step="0.0001" value="'.esc_attr(is_numeric($seedAmount)&&(float)$seedAmount>0?$seedAmount:'').'"/>');
 $currency='<select name="currency">';
 foreach(get_woocommerce_currencies() as $code=>$label)
  $currency.='<option value="'.esc_attr($code).'" '.selected($code,$seedCurrency&&array_key_exists($seedCurrency,get_woocommerce_currencies())?$seedCurrency:get_woocommerce_currency(),false).'>'.esc_html($code).'</option>';
 $field('Currency',$currency.'</select>');
 $field('Supplier SKU / reference','<input name="supplier_sku" maxlength="96"/>','Required when using supplier cost.');
 $field('Supplier unit cost','<input name="supplier_cost" type="number" min="0.0001" step="0.0001"/>','Alternative to manual amount; price is proposed, not approved.');
 $field('Price calculation','<select name="pricing_rule_kind"><option value="">Choose policy</option><option value="markup_percent">Markup % of cost</option><option value="target_margin_percent">Target margin % of selling price</option><option value="fixed_profit">Fixed profit</option></select>');
 $field('Policy value','<input name="pricing_rule_value" type="number" min="0.0001" step="0.0001"/>','20% markup and 20% margin give different selling prices.');
 $field('Valid from','<input name="valid_from" type="date" required/>');
 $field('Valid to','<input name="valid_to" type="date" required/>');
 $field('Tour occupancy multipliers',
  'Single <input type="number" name="single_factor" value="'.esc_attr($seedFactors['single']).'" min="0.0001" max="100" step="0.0001" style="width:90px"/> '.
  'Double <input type="number" name="double_factor" value="'.esc_attr($seedFactors['double']).'" min="0.0001" max="100" step="0.0001" style="width:90px"/> '.
  'Triple <input type="number" name="triple_factor" value="'.esc_attr($seedFactors['triple']).'" min="0.0001" max="100" step="0.0001" style="width:90px"/>',
  'Explicit multipliers. Default 1 does not invent a different occupancy price.');
 $field('Package term ID (tour only)','<input name="package_term_id" type="number" min="1" step="1"/>',
  'Optional existing package_levels taxonomy term.');
 $field('Generated Tour accommodation','<select name="accommodation_assignment_mode"><option value="auto">Discover eligible properties by city and country</option><option value="manual">Manual pricing only</option></select>','Multiple cities require an authored stage source. Discovery never confirms inventory.');
 $field('Accommodation stage source Rate ID','<input name="accommodation_stage_source_rate_id" type="number" min="1" step="1"/>','Optional published or draft Rate of the same Tour. Reuses authored geometry and refills eligible properties.');
 $scope='<select name="rate_scope"><option value="both">Both</option><option value="standalone">Standalone</option><option value="tour_component">Tour component</option></select>';
 $field('Transport scope',$scope);
 echo '</tbody></table>';
 echo '<p><button class="button" type="submit" name="operation" value="preview">Preview rate draft</button> ';
 echo '<button class="button button-primary" type="submit" name="operation" value="create">Create unpublished Rate</button></p>';
 echo '<p>Generated drafts stay stop-sale until their owner completes inventory, policy and publication reviews.</p>';
 $batch=get_transient('shared_rate_batch_preview_'.get_current_user_id());
 $csv=is_array($batch)?(string)($batch['csv']??''):'';
 echo '<hr><h3>Batch Rate Source — CSV (Preview before creating Drafts)</h3>';
 echo '<p>Paste a CSV with kind, parent_id, currency, price_unit, valid_from and valid_to columns; optional supplier cost, markup and stay terms. Up to '.(int)Shared_Product_Rate_Source_Batch::MAX_ROWS.' rows, 64 KB. No rate is published or sold by this action.</p>';
 echo '<textarea name="rate_source_csv" rows="8" maxlength="'.(int)Shared_Product_Rate_Source_Batch::MAX_BYTES.'" style="width:100%;max-width:900px" placeholder="Paste CSV header and rows">'.esc_textarea($csv).'</textarea>';
 echo '<p><button class="button" type="submit" name="operation" value="batch_preview" formnovalidate>Validate CSV — no writes</button> ';
 if(is_array($batch)&&$csv!=='')echo '<button class="button button-primary" type="submit" name="operation" value="batch_create" formnovalidate>Create reviewed Draft batch</button>';
 echo '</p>';
 if(is_array($batch)&&$csv!==''){
  $plan=Shared_Product_Rate_Source_Batch::parse($csv);
  if(!is_wp_error($plan)){
   echo '<p><strong>Validated '.(int)$plan['row_count'].' rows. '.(int)($plan['review_count']??0).' overlap warning(s). Review exact source below. All outputs: Draft / Stop Sale.</strong></p>';
   echo '<table class="widefat striped"><thead><tr><th>Row</th><th>Rate</th><th>Parent</th><th>Proposed source price</th><th>Currency</th><th>Potential overlapping Rates (review before publishing)</th><th>Other CSV rows needing review</th></tr></thead><tbody>';
   foreach($plan['rows'] as $line){
    $r=$line['plan'];$params=$r['parameters'];
    $overlap=Shared_Product_Rate_Generation::overlap_review($r);
    $label=is_wp_error($overlap)?'Inspection unavailable: '.$overlap->get_error_code():
      ($overlap['count']?implode(', ',array_column($overlap['matches'],'rate_id')).' — Owner review required':'None detected');
    $siblings=[];
    foreach(($plan['review_warnings']??[]) as $warning)if(($warning['kind']??'')==='in_file_rate_overlap'){
     if((int)$warning['row']===(int)$line['source_line'])$siblings[]='Row '.(int)$warning['other_row'];
     elseif((int)$warning['other_row']===(int)$line['source_line'])$siblings[]='Row '.(int)$warning['row'];
    }
    echo '<tr><td>'.(int)$line['source_line'].'</td><td>'.esc_html($r['post_type']).'</td><td>'.(int)$r['parent_id'].'</td><td>'.esc_html($params['amount']).'</td><td>'.esc_html($params['currency']).'</td><td>'.esc_html($label).'</td><td>'.esc_html($siblings?implode(', ',array_unique($siblings)).' — Owner review required':'None').'</td></tr>';
   }
   echo '</tbody></table>';
  }
 }

 echo '<hr><h3>Rate Choice Inspection — no selling authority</h3>';
 echo '<p>Inspect existing Rates for an actual service date before using their original Tour, Stay or Transport quote provider. If multiple Rates qualify, enter an explicit Rate ID; this screen never chooses the cheapest and never confirms supplier inventory.</p>';
 echo '<table class="form-table"><tbody>';
 $choices='<select name="choice_kind" id="rate-choice-kind">';
 foreach($types as $value=>$label)$choices.='<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
 $field('Service domain',$choices.'</select>');
 $field('Existing parent product ID','<input type="number" name="choice_parent_id" min="1" step="1"/>');
 $field('Service date','<input type="date" name="choice_service_date"/>');
 $field('Checkout (stay only)','<input type="date" name="choice_checkout"/>');
 $field('Unit ID (stay only)','<input type="number" name="choice_unit_id" min="1" step="1"/>');
 $field('Room / adults / children',
   'Rooms <input type="number" name="choice_rooms" value="1" min="1" max="50" style="width:60px"/> '.
   'Adults <input type="number" name="choice_adults" value="2" min="1" max="50" style="width:60px"/> '.
   'Children <input type="number" name="choice_children" value="0" min="0" max="50" style="width:60px"/>');
 $field('Package term ID (tour only)','<input type="number" name="choice_package_term_id" min="1" step="1"/>');
 $field('Tour audience / occupancy','<select name="choice_audience"><option value="general">General</option><option value="egyptians">Egyptian</option></select> '.
   '<select name="choice_occupancy"><option value="double">Double</option><option value="single">Single</option><option value="triple">Triple</option></select>');
 $field('Transport channel','<select name="choice_rate_scope"><option value="tour_component">Within Tour</option><option value="standalone">Standalone (only if enabled)</option></select>');
 $field('Tour / Transport passenger count','<input type="number" name="choice_persons" value="2" min="1" max="99"/>',
   'Used for native price-only quote. Stay uses room/adults/children fields above.');
 $field('Explicit Rate ID (optional)','<input type="number" name="choice_rate_id" min="1" step="1"/>',
   'If several Rates are eligible, inspect their terms and enter the intended Rate ID explicitly.');
 echo '</tbody></table><p><button class="button" type="submit" name="operation" value="choice_preview" formnovalidate>Inspect Rate candidates — read only</button> ';
 echo '<button class="button button-secondary" type="submit" name="operation" value="choice_quote" formnovalidate>Preview explicit Rate price — no booking</button></p>';
 $choice=get_transient('shared_rate_choice_preview_'.get_current_user_id());
 if(is_array($choice)){
  echo '<h4>Rate candidates — '.esc_html($choice['status']).'</h4>';
  echo '<p>Candidate count: '.(int)$choice['candidate_count'].'. Selected ID: '.esc_html($choice['selected_rate_id']?:'None — explicit selection required').'. Checkout: not authorized.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Rate ID</th><th>Assessment</th><th>Reason</th><th>Action</th></tr></thead><tbody>';
  foreach($choice['eligible'] as $item){
   $id=(int)$item['rate_id'];
   echo '<tr><td>'.(int)$id.'</td><td>Candidate for quote</td><td>Availability not confirmed</td><td><a href="'.esc_url(get_edit_post_link($id)).'">Review Rate</a></td></tr>';
  }
  foreach($choice['excluded'] as $item){
   $id=(int)$item['rate_id'];
   echo '<tr><td>'.(int)$id.'</td><td>Excluded</td><td>'.esc_html($item['reason']).'</td><td><a href="'.esc_url(get_edit_post_link($id)).'">Inspect source</a></td></tr>';
  }
  echo '</tbody></table><p>The original pricing and booking providers remain authoritative. This inspection cannot publish Rates, reserve inventory or create Cart/HPOS orders.</p>';
 }
 $handoff=get_transient('shared_rate_quote_preview_'.get_current_user_id());
 if(is_array($handoff)){
  echo '<h4>Native Rate price-only preview</h4>';
  echo '<table class="widefat striped"><tbody>';
  foreach(['kind'=>'Service','rate_id'=>'Selected Rate ID','amount'=>'Calculated total',
    'currency'=>'Currency','native_provider'=>'Original quote provider',
    'native_price_approval_state'=>'Native price source approval',
    'native_price_approval_revision'=>'Native price approval revision',
    'native_status'=>'Quote status','inventory_status'=>'Availability status',
    'request_intake_preflight'=>'Booking request intake preflight',
    'inventory_precheck_required_for_request'=>'Inventory required before request?',
    'booking_request_created'=>'Booking request created?',
    'rate_source_sha256'=>'Source fingerprint'] as $key=>$label){
   $value=$handoff[$key]??null;
   if($value===null)continue;
   if($key==='amount')$value=number_format((float)$value,2,'.','');
   elseif(is_bool($value))$value=$value?'Yes':'No';
   echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';
  }
  echo '</tbody></table><p><strong>Booking requests do not require stock pre-confirmation.</strong> The request may await the supplier; supplier confirmation is needed before promising confirmed service. This screen is diagnostic only: it has not created a booking, enabled payment, or approved Finance/Checkout.</p>';
 }
 echo '<h3>Recently generated Rate drafts — required follow-up</h3>';
 $drafts=get_posts(['post_type'=>['tour-rates','property-rates','transport-rates'],
   'post_status'=>'draft','numberposts'=>12,'orderby'=>'date','order'=>'DESC',
   'suppress_filters'=>true,'meta_query'=>[['key'=>Shared_Product_Rate_Generation::META,'compare'=>'EXISTS']]]);
 if(!$drafts)echo '<p>No generated drafts.</p>';
 else{
  echo '<table class="widefat striped"><thead><tr><th>Rate</th><th>Type</th><th>Readiness</th><th>What remains</th><th>Overlapping Rates</th></tr></thead><tbody>';
  foreach($drafts as $draft){
   $r=Shared_Product_Rate_Generation::readiness($draft->ID);
   if(is_wp_error($r))continue;
   $todo=$r['blocking_conditions'];
   echo '<tr><td><a href="'.esc_url(get_edit_post_link($draft->ID)).'">'.
     esc_html(get_the_title($draft->ID)).' (#'.(int)$draft->ID.')</a></td>';
   echo '<td>'.esc_html($r['kind']).'</td><td>Draft / not for sale</td>';
   echo '<td>'.esc_html($todo?implode('; ',$todo):'Commercial approval and inventory certification required').'</td>';
   $overlap=$r['overlap_review']??null;
   $ids=is_array($overlap)?array_column($overlap['matches']??[],'rate_id'):[];
   echo '<td>'.esc_html(is_wp_error($overlap)?'Unable to inspect':($ids?implode(', ',$ids).' — Review required':'No overlap detected')).'</td></tr>';
  }
  echo '</tbody></table>';
 }
 echo '<p>Source cost, margin, final taxes, supplier availability and payment activation remain governed by their original owners. This page does not approve checkout.</p>';
 echo '</div>';
 echo "<script>document.getElementById('rate-gen-kind').addEventListener('change',function(){document.querySelectorAll('[data-rate-kind]').forEach(function(x){x.disabled=x.dataset.rateKind!==document.getElementById('rate-gen-kind').value;});});document.getElementById('rate-gen-kind').dispatchEvent(new Event('change'));</script>";
});
add_action('admin_init',static function(){
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_GET['page']??'')!=='wc-settings'||
    ($_GET['tab']??'')!=='rate_generation'||!isset($_POST['operation']))return;
 if(!current_user_can('manage_woocommerce'))wp_die('Permission denied',403);
 check_admin_referer('shared_product_rate_generation','shared_rate_generation_nonce');
 if(wp_get_environment_type()!=='local')wp_die('Not a Lab site',403);
 $raw=wp_unslash($_POST);
 $op=$raw['operation']??'';
 if($op==='choice_preview'||$op==='choice_quote'){
  $kind=sanitize_key((string)($raw['choice_kind']??''));
  $candidate=['kind'=>$kind,'parent_id'=>$raw['choice_parent_id']??null,
    'service_date'=>$raw['choice_service_date']??null];
  $domainFields=['tour'=>['audience'=>'choice_audience','occupancy'=>'choice_occupancy',
     'package_term_id'=>'choice_package_term_id'],
   'accommodation'=>['unit_id'=>'choice_unit_id','checkout'=>'choice_checkout',
     'rooms'=>'choice_rooms','adults'=>'choice_adults','children'=>'choice_children'],
   'transport'=>['rate_scope'=>'choice_rate_scope']];
  foreach(($domainFields[$kind]??[]) as $key=>$field)
   if(isset($raw[$field])&&$raw[$field]!=='')$candidate[$key]=$raw[$field];
  if(isset($raw['choice_rate_id'])&&$raw['choice_rate_id']!=='')$candidate['rate_id']=$raw['choice_rate_id'];
  if($op==='choice_quote'&&isset($raw['choice_persons'])&&$raw['choice_persons']!=='')
   $candidate['persons']=$raw['choice_persons'];
  $result=$op==='choice_quote'
    ?Shared_Product_Rate_Generation::quote_for_explicit_rate($candidate)
    :Shared_Product_Rate_Generation::selection_inspect($candidate);
  delete_transient('shared_rate_quote_preview_'.get_current_user_id());
  delete_transient('shared_rate_choice_preview_'.get_current_user_id());
  if(!is_wp_error($result)){
   if($op==='choice_quote'){
    set_transient('shared_rate_quote_preview_'.get_current_user_id(),$result,180);
    $flash=['message'=>'Native price-only quote: '.number_format((float)$result['amount'],2,'.','').' '.
      $result['currency'].'; Rate #'.(int)$result['rate_id'].'. No Cart, stock hold or Checkout.'];
   }else{
    set_transient('shared_rate_choice_preview_'.get_current_user_id(),$result,180);
    $flash=['message'=>'Rate selection inspection: '.$result['status'].'; '.(int)$result['candidate_count'].' candidate(s). Read-only, not available for Checkout.'];
   }
  }else $flash=['error'=>true,'message'=>$result->get_error_code()];
  set_transient('shared_product_generation_flash_'.get_current_user_id(),$flash,90);
  wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=rate_generation'));exit;
 }
 if($op==='batch_preview'||$op==='batch_create'){
  $csv=(string)($raw['rate_source_csv']??'');
  $result=$op==='batch_preview'
   ?Shared_Product_Rate_Source_Batch::begin_preview($csv)
   :Shared_Product_Rate_Source_Batch::create_reviewed($csv);
  $flash=is_wp_error($result)
   ?['error'=>true,'message'=>$result->get_error_code().' — '.$result->get_error_message()]
   :['message'=>$op==='batch_preview'
     ?'CSV validated: '.(int)$result['row_count'].' rows; '.(int)($result['review_count']??0).' overlap warning(s). No Rates created; review the table and confirm.'
     :'Created '.count($result['created_ids']).' draft(s), reused '.count($result['reused_ids']).' draft(s). IDs: '.implode(', ',$result['created_ids'])];
  set_transient('shared_product_generation_flash_'.get_current_user_id(),$flash,120);
  wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=rate_generation'));exit;
 }
 $kind=sanitize_key((string)($raw['kind']??''));
 $payload=['kind'=>$kind,'parent_id'=>$raw['parent_id']??null,'unit_id'=>$raw['unit_id']??null,
  'amount'=>$raw['amount']??null,'currency'=>$raw['currency']??null,
  'price_unit'=>$raw['price_unit_'.$kind]??null,
  'valid_from'=>$raw['valid_from']??null,'valid_to'=>$raw['valid_to']??null];
 foreach(['supplier_sku','supplier_cost','pricing_rule_kind','pricing_rule_value'] as $field)
  if(isset($raw[$field])&&$raw[$field]!=='')$payload[$field]=$raw[$field];
 if($kind==='tour'){
  foreach(['accommodation_assignment_mode','accommodation_stage_source_rate_id'] as $field)if(isset($raw[$field])&&$raw[$field]!=='')$payload[$field]=$raw[$field];
  foreach(['single_factor','double_factor','triple_factor'] as $field)$payload[$field]=$raw[$field]??null;
  if(!empty($raw['package_term_id']))$payload['package_term_id']=$raw['package_term_id'];
 }elseif($kind==='accommodation'){
  foreach(['min_nights','max_nights','max_adults','max_children','inventory_policy'] as $field)
   if(isset($raw[$field])&&$raw[$field]!=='')$payload[$field]=$raw[$field];
 }elseif($kind==='transport')$payload['rate_scope']=$raw['rate_scope']??'both';
 $op=$raw['operation']??'';
 $result=$op==='preview'?Shared_Product_Rate_Generation::prepare($payload):
   ($op==='create'?Shared_Product_Rate_Generation::create($payload):new WP_Error('rate_generator_action_invalid'));
 $flash=is_wp_error($result)?['error'=>true,'message'=>$result->get_error_code()]:
  ($op==='preview'?['message'=>'Draft preview: '.($result['post_type']??'').' from '.
   ($result['parameters']['amount']??'').' '.($result['parameters']['currency']??'') .
   ' · accommodation: '.($result['parameters']['accommodation_assignment']['status']??'manual pricing only').' · planned, not saved']:['message'=>'Rate '.$result['status'].' as draft · accommodation: '.($result['plan']['parameters']['accommodation_assignment']['status']??'manual pricing only'),'id'=>$result['post_id']]);
 set_transient('shared_product_generation_flash_'.get_current_user_id(),$flash,90);
 wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=rate_generation'));exit;
});
