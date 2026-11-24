<?php
/** Plugin Name: MAD4B Daily Cost Components
 * Canonical itinerary requirement rows; independent of retail and receipt authority.
 */
defined('ABSPATH')||exit;
final class MAD4B_Daily_Cost_Components_V1 {
 const META='_mad4b_daily_cost_components_v1';
 const PAGE='mad4b-daily-cost-components';
 const CONTRACT='mad4b.daily-cost-components.v1';
 const NATIVE=['accommodation','guide','transportation','flight','tickets','meals','operations','other'];
 static function allowed(int $id):bool {
  return $id>0&&get_post_type($id)==='tours-and-activities'&&
   current_user_can('commerce_manage_pricing')&&current_user_can('edit_post',$id)&&
   !get_user_meta(get_current_user_id(),'mad4b_company_suspension_v1',true)&&
   MAD4B_Package_Components_Editor_Context_V1::canonical($id)===$id;
 }
 static function source(int $id,int $program=0):array|WP_Error {
  if(!self::allowed($id))return new WP_Error('daily_cost_scope_denied');
  return MAD4B_Guide_Service_Composition_V1::source($id,$program);
 }
 static function key(int $program):string {return 'program:'.$program;}
 static function current(int $id,int $program=0):array {
  $all=get_post_meta($id,self::META,true);$r=is_array($all)?($all[self::key($program)]??[]):[];
  return is_array($r)?$r:[];
 }
 static function types():array {
  $types=self::NATIVE;
  foreach(MAD4B_Cost_Source_Registry_V1::mappings() as $k=>$m)$types[]='registry:'.$k;
  return array_values(array_unique($types));
 }
 static function normalize(array $row,array $source):array|WP_Error {
  $day=$row['day_key']??'';$key=$row['component_key']??'';$type=$row['component_type']??'';
  $label=$row['label']??'';$requirement=$row['requirement']??'required';$inclusion=$row['inclusion']??'included';
  $selection=$row['selection_mode']??'inherit';$qty=$row['quantity_source']??'basis';
  $sourceType=$row['source_type']??'native';$ref=$row['source_ref']??0;$fixed=$row['quantity_override']??1;$tier=$row['tier_id']??0;
  if(!is_string($day)||!isset($source['days'][$day])||!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$key)||
   !in_array($type,self::types(),true)||!in_array($sourceType,['native','attraction','activity'],true)||($sourceType!=='native'&&$type!=='tickets')||!is_string($label)||trim($label)===''||strlen($label)>240||
   !in_array($requirement,['required','optional','conditional'],true)||!in_array($inclusion,['included','extra','upgrade'],true)||
   !in_array($selection,['inherit','automatic','manual'],true)||!in_array($qty,['basis','fixed'],true))
   return new WP_Error('daily_cost_component_invalid');
  foreach([$ref,$fixed,$tier] as $v)if(!is_int($v)&&(!is_string($v)||!preg_match('/^[0-9]{1,9}$/D',$v)))return new WP_Error('daily_cost_number_invalid');
  $ref=(int)$ref;$fixed=(int)$fixed;$tier=(int)$tier;
  if($ref<0||$fixed<1||$fixed>500||$tier<0||($tier&&!term_exists($tier,'package_levels')))return new WP_Error('daily_cost_number_invalid');
  if(str_starts_with($type,'registry:')){
   $m=MAD4B_Cost_Source_Registry_V1::mappings()[substr($type,9)]??null;
   if(!$m)return new WP_Error('daily_cost_mapping_missing');
   if($ref&&($m['kind']==='post_type'?(get_post_type($ref)!==$m['slug']||!current_user_can('edit_post',$ref)):
    (!term_exists($ref,$m['slug'])||!current_user_can('edit_term',$ref))))return new WP_Error('daily_cost_source_reference_invalid');
  }elseif($sourceType==='attraction'||$sourceType==='activity'){
   $expected=$sourceType==='attraction'?'attractions':'tours-and-activities';
   if(!$ref||get_post_type($ref)!==$expected||get_post_status($ref)!=='publish'||!current_user_can('edit_post',$ref)||
      ($sourceType==='activity'&&get_post_meta($ref,'tour_mode',true)!=='activity')||
      ($sourceType==='activity'&&(int)($source['tour_id']??0)===$ref))
    return new WP_Error('daily_cost_entrance_source_invalid');
  }elseif($ref)return new WP_Error('daily_cost_native_reference_owned_by_provider');
  $out=['day_key'=>$day,'component_key'=>$key,'component_type'=>$type,'label'=>sanitize_text_field($label),
   'requirement'=>$requirement,'inclusion'=>$inclusion,'selection_mode'=>$selection,'quantity_source'=>$qty,
   'quantity_override'=>$fixed,'tier_id'=>$tier,'source_type'=>$sourceType,'source_ref'=>$ref];
  foreach(['source_row_key','from_ref','to_ref','service_duration','notes'] as $k){
   $v=$row[$k]??'';if(!is_string($v)||strlen($v)>500)return new WP_Error('daily_cost_text_invalid');
   $out[$k]=sanitize_text_field($v);
  }
  return $out;
 }
 static function save(int $id,int $program,array $rows,string $hash,int $revision):array|WP_Error {
  $s=self::source($id,$program);if(is_wp_error($s))return $s;
  $program=(int)($s['program_id']??0);
  if(!hash_equals($s['source_hash'],$hash)||count($rows)>250)return new WP_Error('daily_cost_itinerary_changed_or_limit');
  $clean=[];foreach($rows as $row){
   if(!is_array($row))return new WP_Error('daily_cost_row_invalid');
   $v=self::normalize($row,$s);if(is_wp_error($v))return $v;
   if(isset($clean[$v['component_key']]))return new WP_Error('daily_cost_duplicate_key');
   $clean[$v['component_key']]=$v;
  }
  global $wpdb;$lock='mad4b_daily_cost_'.get_current_blog_id().'_'.$id;
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new WP_Error('daily_cost_busy');
  try{
   $fresh=self::source($id,$program);$old=self::current($id,$program);
   if(is_wp_error($fresh)||!hash_equals($fresh['source_hash'],$hash)||(int)($old['revision']??0)!==$revision)return new WP_Error('daily_cost_stale_revision');
   $all=get_post_meta($id,self::META,true);if($all!==''&&!is_array($all))return new WP_Error('daily_cost_store_invalid');
   if(!is_array($all))$all=[];
   $record=['contract'=>self::CONTRACT,'source_hash'=>$hash,'program_id'=>$program,'revision'=>$revision+1,
    'reviewer_id'=>get_current_user_id(),'updated_at'=>gmdate('c'),'rows'=>array_values($clean),'commercially_approved'=>false];
   $all[self::key($program)]=$record;update_post_meta($id,self::META,$all);
   if(get_post_meta($id,self::META,true)!==$all)return new WP_Error('daily_cost_readback_failed');
   if(class_exists('MAD4B_Dynamic_Cost_Alerts_V1',false))MAD4B_Dynamic_Cost_Alerts_V1::wake();
   return $record;
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 static function inspect(int $id,array $options=[]):array|WP_Error {
  $s=self::source($id,(int)($options['program_id']??0));if(is_wp_error($s))return $s;
  $program=(int)($s['program_id']??0);$r=self::current($id,$program);$rows=[];$covered=[];
  $fresh=isset($r['source_hash'])&&hash_equals($r['source_hash'],$s['source_hash']);
  $ctx=MAD4B_Generic_Cost_Composition_V1::context($options);$fx=null;$fxLoaded=false;
  foreach((array)($r['rows']??[]) as $row){
   $v=self::normalize($row,$s);$state='source_missing';$line=null;$entrance=null;
   if(is_wp_error($v)){$rows[]=array_merge($row,['state'=>'invalid_source_or_day','reference_line'=>null]);continue;}
   if(!$fresh)$state='itinerary_changed';
   elseif($v['tier_id']&&$v['tier_id']!==($ctx['tier_id']??0))$state='tier_selection_required';
   elseif(in_array($v['component_type'],['accommodation','guide'],true))$state='native_provider_delegated';
   elseif($v['component_type']==='tickets'&&$v['source_type']!=='native'){
    $entrance=self::entrance($v);
    if(class_exists('MAD4B_Entrance_Source_Rates_V1',false)){
     $record=MAD4B_Entrance_Source_Rates_V1::current($v['source_ref']);
     $day=$s['days'][$v['day_key']];
     $date=MAD4B_Entrance_Source_Rates_V1::serviceDate((string)($ctx['date']??''),$day);
     if(!empty($record['rows'])){
      $entrance['rate_editor']=MAD4B_Entrance_Source_Rates_V1::url($v['source_ref']);
      $entrance['rate_row_count']=count($record['rows']);
      $entrance['dated_reference']=MAD4B_Entrance_Source_Rates_V1::resolve($record['rows'],$v['source_type'],$date??'', $ctx+['nationality'=>$options['nationality']??'','entrance_cohorts'=>$options['entrance_cohorts']??''],$v['source_row_key'],MAD4B_Generic_Cost_Composition_V1::fxSnapshot());
      $entrance['status']=$entrance['dated_reference']['status'];
      if(!$options){
       $validReference=false;
       foreach($record['rows'] as $rate){
        if(!is_array($rate))continue;
        $checked=MAD4B_Entrance_Source_Rates_V1::normalize($rate,$v['source_type']);
        if(!is_wp_error($checked)&&$checked['review_status']==='reviewed_reference'&&$checked['coverage']==='admission_only'&&($v['source_row_key']===''||$checked['fee_key']===$v['source_row_key']))$validReference=true;
       }
       $entrance['status']=$validReference?'entrance_source_recorded':'entrance_cost_candidate_review_required';
      }
      if($v['quantity_source']!=='basis')$entrance['status']='entrance_quantity_policy_review_required';
     }else $entrance['rate_editor']=MAD4B_Entrance_Source_Rates_V1::url($v['source_ref']);
    }
    $state=$entrance['status'];
   }
   elseif(str_starts_with($v['component_type'],'registry:')&&$v['source_ref']){
    $mappingKey=substr($v['component_type'],9);$m=MAD4B_Cost_Source_Registry_V1::mappings()[$mappingKey];
    $candidates=$m['kind']==='post_type'?MAD4B_Cost_Source_Registry_V1::rows($v['source_ref'],$m['slug']):MAD4B_Cost_Source_Registry_V1::rows($id,'tours-and-activities');
    $match=array_values(array_filter($candidates,static fn($x)=>($x['source']??'')==='registry:'.$mappingKey&&(int)($x['value']['entity_id']??0)===$v['source_ref']&&($v['source_row_key']===''||(string)($x['value']['row_key']??'')===$v['source_row_key'])));
    if(count($match)===1&&($match[0]['status']??'')==='reverify'){
     $a=$match[0]['value'];$state='quantity_or_fx_required';
     // Daily scope: native room/night and guide lines remain delegated, not summed.
     $day=$ctx;$day['days']=1;$day['nights']=1;
     $qtyPolicy=$v['quantity_source']==='fixed'?['quantity_source'=>'fixed','fixed_quantity'=>$v['quantity_override']]:$m;
     $qty=MAD4B_Generic_Cost_Composition_V1::quantity($qtyPolicy,$a['basis'],$day);
     if($qty&&$ctx['currency']){
      if(!$fxLoaded){$fx=MAD4B_Generic_Cost_Composition_V1::fxSnapshot();$fxLoaded=true;}
      $line=MAD4B_Generic_Cost_Composition_V1::line($v['component_key'],$a['amount'],$a['currency'],$qty,$ctx['currency'],$fx);
      if(is_int($line['target_total_minor']))$state='reference_calculated';
     }
    }elseif(count($match)>1)$state='source_row_selection_required';
   }
   if($v['requirement']==='conditional'&&in_array($state,['reference_calculated','entrance_reference_calculated'],true))$state='condition_unresolved';
   $rows[]=$v+['state'=>$state,'reference_line'=>$line,'entrance_source'=>$entrance,'commercially_approved'=>false];
   $covered[$v['day_key']]=true;
  }
  return ['contract'=>self::CONTRACT,'tour_id'=>$id,'program_id'=>$program,'source_hash'=>$s['source_hash'],
   'fresh'=>$fresh,'revision'=>(int)($r['revision']??0),'days'=>$s['days'],'rows'=>$rows,
   'unmapped_days'=>array_values(array_diff(array_keys($s['days']),array_keys($covered))),
   'coverage_certified'=>false,'commercial_total_minor'=>null,'rate_generation_ready'=>false,
   'native_and_daily_not_summed'=>true,'edit_url'=>self::url($id,$program)];
 }
 /** Entrance sources are alternatives, never additive sale prices. */
 static function entrance(array $v):array {
  $id=(int)$v['source_ref'];$type=$v['source_type'];$o=[
   'source_type'=>$type,'source_id'=>$id,'label'=>get_the_title($id),'edit_url'=>get_edit_post_link($id,'raw'),
   'cost_qualified'=>false,'commercially_approved'=>false,'status'=>'entrance_fee_source_review_required'];
  $candidates=[];
  foreach(MAD4B_Cost_Source_Registry_V1::rows($id,get_post_type($id)) as $row){
   $a=$row['value']??[];$m=$a['mapping']??[];
   if((int)($a['entity_id']??0)!==$id||($a['entity_kind']??'')!=='post_type')continue;
   if(!in_array($m['component_key']??'',['entrance_fee','activity_cost'],true))continue;
   if($v['source_row_key']!==''&&(string)($a['row_key']??'')!==$v['source_row_key'])continue;
   $candidates[]=['source'=>$row['source'],'row_key'=>$a['row_key']??null,'amount'=>$a['amount']??null,
    'currency'=>$a['currency']??null,'basis'=>$a['basis']??null,'status'=>$row['status']??'missing'];
  }
  $o['cost_candidates']=$candidates;
  if($type==='activity'){
   $o['selling_reference']=['starting_from'=>get_post_meta($id,'start_price',true),'currency'=>null,
    'price_role'=>'retail_reference_not_supplier_cost'];
   $o['status']='activity_cost_or_transfer_price_required';
  }
  if($candidates)$o['status']='entrance_cost_candidate_review_required';
  return $o;
 }
 static function url(int $id,int $program=0):string {
  return admin_url('admin.php?page='.self::PAGE.'&product_id='.$id.'&program_id='.$program);
 }
 static function menu():void {
  add_submenu_page('mad4b-pricing-workspace','Daily Cost Components','Daily Cost Components','commerce_manage_pricing',self::PAGE,[self::class,'page']);
 }
 static function metabox($post):void {
  if(!self::allowed((int)$post->ID))return;
  add_meta_box('mad4b_daily_cost_components','Daily Cost Components',[self::class,'box'],'tours-and-activities','normal','default');
 }
 static function box($post):void {
  $id=(int)$post->ID;$s=self::inspect($id);
  if(is_wp_error($s)){echo '<p>'.esc_html($s->get_error_code()).'</p>';return;}
  echo '<p>'.esc_html(count($s['rows']).' components across '.count($s['days']).' itinerary days · revision '.$s['revision']).'</p>';
  echo '<p><a class="button button-primary" href="'.esc_url(self::url($id,$s['program_id'])).'">Edit daily cost components</a> <a class="button" href="'.esc_url(MAD4B_Product_Cost_Review_Ledger_V1::link($id)).'">Cost Review Ledger</a></p>';
  foreach($s['days'] as $key=>$day){
   echo '<p><strong>'.esc_html($day['title']).'</strong></p>';
   foreach($s['rows'] as $row)if(($row['day_key']??'')===$key)echo '<p>'.esc_html($row['label'].' — '.$row['state']).'</p>';
  }
 }
 static function select(string $name,array $choices,$value):void {
  echo '<select name="'.esc_attr($name).'">';
  foreach($choices as $key=>$label)echo '<option value="'.esc_attr((string)$key).'"'.selected((string)$key,(string)$value,false).'>'.esc_html($label).'</option>';
  echo '</select>';
 }
 static function rowForm(int $id,array $s,array $row=[]):void {
  $key=$row['component_key']??wp_generate_uuid4();
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="border:1px solid #ccd0d4;padding:12px;margin:8px 0;background:white">';
  wp_nonce_field('mad4b_daily_cost_'.$id);
  foreach(['action'=>'mad4b_daily_cost_save','product_id'=>$id,'program_id'=>$s['program_id'],'source_hash'=>$s['source_hash'],'revision'=>$s['revision'],'component_key'=>$key] as $k=>$v)
   echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$v).'">';
  echo '<p><label>Day ';self::select('day_key',array_column($s['days'],'title','key'),$row['day_key']??'');echo '</label> <label>Service label <input required maxlength="240" name="label" value="'.esc_attr($row['label']??'').'"></label></p>';
  echo '<p><label>Component source ';$types=self::types();self::select('component_type',array_combine($types,$types),$row['component_type']??'other');echo '</label> <label>Entrance source (tickets only) ';self::select('source_type',['native'=>'Native provider / unlinked','attraction'=>'Attraction entrance fee','activity'=>'Activity selling reference or verified cost'],$row['source_type']??'native');echo '</label> <label>Registered source post/term ID <input type="number" min="0" name="source_ref" value="'.esc_attr((string)($row['source_ref']??0)).'"></label></p>';
  foreach(['requirement'=>['required','optional','conditional'],'inclusion'=>['included','extra','upgrade'],'selection_mode'=>['inherit','automatic','manual'],'quantity_source'=>['basis','fixed']] as $k=>$values){
   echo '<label>'.esc_html($k).' ';self::select($k,array_combine($values,$values),$row[$k]??$values[0]);echo '</label> ';
  }
  echo '<p><label>Quantity override <input name="quantity_override" type="number" min="1" max="500" value="'.esc_attr((string)($row['quantity_override']??1)).'"></label> <label>Package level ';
  $tiers=['0'=>'All levels'];$terms=get_terms(['taxonomy'=>'package_levels','hide_empty'=>false]);foreach(is_wp_error($terms)?[]:$terms as $term)$tiers[$term->term_id]=$term->name;
  self::select('tier_id',$tiers,$row['tier_id']??0);echo '</label></p>';
  foreach(['source_row_key'=>'Repeater row key (if applicable)','from_ref'=>'From / origin','to_ref'=>'To / destination','service_duration'=>'Duration / unit notes','notes'=>'Notes'] as $k=>$label)
   echo '<p><label>'.esc_html($label).' <input name="'.esc_attr($k).'" maxlength="500" value="'.esc_attr($row[$k]??'').'"></label></p>';
  echo '<button class="button button-primary" name="operation" value="save">Save component</button> ';
  if($row)echo '<button class="button" name="operation" value="remove">Remove component</button> <strong>'.esc_html($row['state']??'').'</strong>';
  if(!empty($row['entrance_source']))echo '<p><strong>Linked entrance source:</strong> '.esc_html($row['entrance_source']['label']).' — '.esc_html($row['entrance_source']['status']).' <a href="'.esc_url($row['entrance_source']['edit_url']).'">Original editor</a></p>';
  if(!empty($row['entrance_source']['rate_editor']))echo '<p><a href="'.esc_url($row['entrance_source']['rate_editor']).'">Edit Entrance / Activity Internal Rates</a></p>';
  if(isset($row['entrance_source']['selling_reference']['starting_from']))echo '<p>Activity starting price reference: '.esc_html((string)$row['entrance_source']['selling_reference']['starting_from']).' (currency and dated selling eligibility not verified).</p>';
  echo '</form>';
 }
 static function page():void {
  $id=absint($_GET['product_id']??0);$program=absint($_GET['program_id']??0);
  if(!$id){
   if(!current_user_can('commerce_manage_pricing'))wp_die('Denied');
   echo '<div class="wrap"><h1>Daily Cost Components</h1><form method="get"><input type="hidden" name="page" value="'.self::PAGE.'"><label>Tour ID <input type="number" min="1" required name="product_id"></label> <label>Program term ID (if applicable) <input type="number" min="0" name="program_id" value="0"></label> <button class="button">Open itinerary</button></form></div>';return;
  }
  $s=self::inspect($id,['program_id'=>$program]);if(is_wp_error($s))wp_die(esc_html($s->get_error_code()));
  echo '<div class="wrap"><h1>Daily Cost Components — '.esc_html(get_the_title($id)).'</h1><p>Requirement rows only. Native providers own accommodation and guides; registered references use the existing cost registry. No commercial authorization.</p>';
  if(!$s['fresh']&&$s['revision']){
   echo '<p class="notice notice-warning">Itinerary changed. Review all existing component rows and their day mapping before explicitly acknowledging the new source revision. Unmatched rows must be repaired first.</p>';
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   wp_nonce_field('mad4b_daily_cost_'.$id);
   foreach(['action'=>'mad4b_daily_cost_save','product_id'=>$id,'program_id'=>$s['program_id'],'source_hash'=>$s['source_hash'],'revision'=>$s['revision']] as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$v).'">';
   echo '<label><input type="checkbox" required name="full_review" value="yes"> I reviewed the entire current itinerary and all component day assignments.</label> <button class="button" name="operation" value="rebind">Acknowledge itinerary revision (not cost approval)</button></form>';
  }
  echo '<p><a href="'.esc_url(get_edit_post_link($id,'raw')).'">Original tour editor</a> | <a href="'.esc_url(MAD4B_Product_Cost_Review_Ledger_V1::link($id)).'">Cost Review Ledger</a></p>';
  echo '<p>Entrance source catalogs: <a href="'.esc_url(admin_url('edit.php?post_type=attractions')).'">Attractions</a> | <a href="'.esc_url(admin_url('edit.php?post_type=tours-and-activities')).'">Tours &amp; Activities (activity mode only)</a>. Select just one source per entrance requirement. Activity prices are retail references, not verified costs.</p>';
  foreach($s['days'] as $key=>$day){
   echo '<h2>'.esc_html($day['title']).'</h2>';
   foreach($s['rows'] as $row)if(($row['day_key']??'')===$key)self::rowForm($id,$s,$row);
  }
  foreach($s['rows'] as $row)if(!isset($s['days'][$row['day_key']??''])){echo '<h2>Unmatched old day — review required</h2>';self::rowForm($id,$s,$row);}
  echo '<h2>Add component</h2>';self::rowForm($id,$s);echo '</div>';
 }
 static function action():void {
  $id=absint($_POST['product_id']??0);$program=absint($_POST['program_id']??0);
  if(!self::allowed($id))wp_die('Denied','Denied',['response'=>403]);
  check_admin_referer('mad4b_daily_cost_'.$id);
  $source=self::source($id,$program);if(is_wp_error($source))wp_die(esc_html($source->get_error_code()));
  $program=(int)($source['program_id']??0);$old=self::current($id,$program);
  $hash=$_POST['source_hash']??'';if(!is_string($hash)||!hash_equals($source['source_hash'],$hash))wp_die('Itinerary changed; reload.');
  if(($_POST['operation']??'')==='rebind'){
   if(($_POST['full_review']??'')!=='yes')wp_die('Explicit review required.');
   $r=self::save($id,$program,(array)($old['rows']??[]),$hash,absint($_POST['revision']??0));
   if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));
   if(class_exists('MAD4B_Dynamic_Cost_Alerts_V1',false))MAD4B_Dynamic_Cost_Alerts_V1::reconcile($id);
   wp_safe_redirect(self::url($id,$program));exit;
  }
  // A changed source cannot be silently re-certified by editing only one row.
  if(isset($old['source_hash'])&&!hash_equals($old['source_hash'],$hash))wp_die('Source revision changed. Full itinerary review required before editing.');
  $row=[];foreach(['day_key','component_key','component_type','label','requirement','inclusion','selection_mode','quantity_source','quantity_override','tier_id','source_type','source_ref','source_row_key','from_ref','to_ref','service_duration','notes'] as $k){
   $v=$_POST[$k]??'';if(!is_string($v))wp_die('Invalid field');$row[$k]=wp_unslash($v);
  }
  $rows=[];foreach((array)($old['rows']??[]) as $v)if($v['component_key']!==$row['component_key'])$rows[]=$v;
  if(($_POST['operation']??'')!=='remove')$rows[]=$row;
  $r=self::save($id,$program,$rows,$hash,absint($_POST['revision']??0));
  if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));
  if(class_exists('MAD4B_Dynamic_Cost_Alerts_V1',false))MAD4B_Dynamic_Cost_Alerts_V1::reconcile($id);
  wp_safe_redirect(self::url($id,$program));exit;
 }
 static function boot():void {
  add_action('admin_menu',[self::class,'menu'],875);
  add_action('add_meta_boxes_tours-and-activities',[self::class,'metabox']);
  add_action('admin_post_mad4b_daily_cost_save',[self::class,'action']);
 }

}

MAD4B_Daily_Cost_Components_V1::boot();
