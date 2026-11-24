<?php
/** Plugin Name: MAD4B Entrance and Activity Internal Rates
 * Version: 1.0.0
 * Explicit dated cost references, never supplier receipt or retail authority.
 */
defined('ABSPATH')||exit;
final class MAD4B_Entrance_Source_Rates_V1 {
 const META='_mad4b_entrance_source_rates_v1';
 const PAGE='mad4b-entrance-source-rates';
 const CURRENCIES=['USD','EGP','EUR','GBP'];
 static function kind(int $id):string {
  $t=get_post_type($id);
  return $t==='attractions'?'attraction':($t==='tours-and-activities'&&get_post_meta($id,'tour_mode',true)==='activity'?'activity':'');
 }
 static function allowed(int $id):bool {
  if(!$id||!self::kind($id)||!current_user_can('commerce_manage_pricing')||!current_user_can('edit_post',$id)||!get_current_user_id()||get_user_meta(get_current_user_id(),'mad4b_company_suspension_v1',true))return false;
  $t=get_post_type($id);$canonical=(int)apply_filters('wpml_object_id',$id,$t,false,'en');
  return !$canonical||$canonical===$id;
 }
 static function date($v):bool {
  if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v))return false;
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));return $d&&$d->format('Y-m-d')===$v;
 }
 static function nationality($v):string {
  return is_string($v)&&preg_match('/^[A-Z]{2}$/D',$v)?$v:'';
 }
 static function normalize(array $v,string $kind):array|WP_Error {
  foreach(['fee_key','amount','currency','basis','visitor_category','market_scope','valid_from','valid_to','review_status','evidence_ref','coverage'] as $k)
   if(!is_string($v[$k]??null))return new WP_Error('entrance_field_invalid');
  $key=$v['fee_key'];$amount=$v['amount'];$market=$v['market_scope'];$basis=$v['basis'];$cat=$v['visitor_category'];
  if(!in_array($kind,['attraction','activity'],true)||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$key)||
   !preg_match('/^[0-9]{1,7}(?:\.[0-9]{1,2})?$/D',$amount)||!in_array($v['currency'],self::CURRENCIES,true)||
   !in_array($basis,['per_person','per_group'],true)||!in_array($cat,['adult','child','infant','student','all'],true)||
   ($basis==='per_group'&&$cat!=='all')||(!in_array($market,['all','foreign'],true)&&!self::nationality($market))||
   !self::date($v['valid_from'])||!self::date($v['valid_to'])||$v['valid_from']>$v['valid_to']||
   !in_array($v['review_status'],['draft','reviewed_reference'],true)||
   !in_array($v['coverage'],$kind==='attraction'?['admission_only']:['admission_only','activity_bundle'],true)||
   strlen($v['evidence_ref'])>500)return new WP_Error('entrance_rate_invalid');
  $min=$v['age_min']??'';$max=$v['age_max']??'';
  foreach([$min,$max] as $age)if(!is_string($age)||($age!==''&&(!preg_match('/^[0-9]{1,3}$/D',$age)||(int)$age>120)))return new WP_Error('entrance_age_invalid');
  if($min!==''&&$max!==''&&(int)$min>(int)$max)return new WP_Error('entrance_age_invalid');
  if($basis==='per_group'&&($min!==''||$max!==''))return new WP_Error('entrance_group_age_unsupported');
  if($cat==='student'&&$max==='')return new WP_Error('entrance_student_age_limit_required');
  $e=trim(sanitize_text_field($v['evidence_ref']));
  if(($v['review_status']==='reviewed_reference'||(float)$amount===0.0)&&$e==='')return new WP_Error('entrance_evidence_required');
  if((float)$amount===0.0&&$v['review_status']!=='reviewed_reference')return new WP_Error('entrance_free_fee_requires_review');
  return ['fee_key'=>$key,'amount'=>$amount,'currency'=>$v['currency'],'basis'=>$basis,'visitor_category'=>$cat,
   'market_scope'=>$market,'valid_from'=>$v['valid_from'],'valid_to'=>$v['valid_to'],
   'review_status'=>$v['review_status'],'evidence_ref'=>$e,'coverage'=>$v['coverage'],
   'age_min'=>$min,'age_max'=>$max,'price_role'=>$kind==='activity'?'internal_transfer_cost':'entrance_cost_reference'];
 }
 static function current(int $id):array {
  $v=get_post_meta($id,self::META,true);return is_array($v)?$v:[];
 }
 static function save(int $id,array $rows,int $rev):array|WP_Error {
  if(!self::allowed($id)||count($rows)>100)return new WP_Error('entrance_write_denied');
  $clean=[];foreach($rows as $row){
   if(!is_array($row))return new WP_Error('entrance_row_invalid');
   $v=self::normalize($row,self::kind($id));if(is_wp_error($v))return $v;
   if(isset($clean[$v['fee_key']]))return new WP_Error('entrance_duplicate_key');
   $clean[$v['fee_key']]=$v;
  }
  global $wpdb;$lock='mad4b_entrance_rates_'.get_current_blog_id().'_'.$id;
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new WP_Error('entrance_write_busy');
  try{
   $old=self::current($id);
   if((int)($old['revision']??0)!==$rev)return new WP_Error('entrance_stale_revision');
   $record=['schema'=>'mad4b.entrance-source-rates.v1','revision'=>$rev+1,'source_id'=>$id,'kind'=>self::kind($id),'rows'=>array_values($clean),'reviewer_id'=>get_current_user_id(),'updated_at'=>gmdate('c'),'commercially_approved'=>false];
   update_post_meta($id,self::META,$record);
   if(get_post_meta($id,self::META,true)!==$record)return new WP_Error('entrance_readback_failed');
   if(class_exists('MAD4B_Dynamic_Cost_Alerts_V1',false))MAD4B_Dynamic_Cost_Alerts_V1::wake();
   return $record;
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 static function serviceDate(string $departure,array $day):?string {
  if(!self::date($departure)||!preg_match('/^[1-9][0-9]?$/D',(string)($day['number']??'')))return null;
  $n=(int)$day['number'];return (new DateTimeImmutable($departure,new DateTimeZone('UTC')))->modify('+'.($n-1).' days')->format('Y-m-d');
 }
 /** Exact nationality outranks foreign, which outranks all; category specificity breaks ties. Ambiguity blocks. */
 static function pick(array $rows,string $date,string $nationality,string $category,string $basis,string $key='',?int $age=null):array|WP_Error {
  $matches=[];
  foreach($rows as $r){
   if(($r['review_status']??'')!=='reviewed_reference'||$r['basis']!==$basis||$date<$r['valid_from']||$date>$r['valid_to']||
    ($key!==''&&$key!==$r['fee_key'])||!in_array($r['visitor_category'],[$category,'all'],true))continue;
   if(($r['age_min']??'')!==''||($r['age_max']??'')!==''){
    if($age===null||(($r['age_min']??'')!==''&&$age<(int)$r['age_min'])||(($r['age_max']??'')!==''&&$age>(int)$r['age_max']))continue;
   }
   $m=$r['market_scope'];$rank=$m===$nationality?3:($m==='foreign'&&$nationality!=='EG'?2:($m==='all'?1:0));
   if(!$rank)continue;
   $score=$rank*10+($r['visitor_category']===$category?2:0)+((($r['age_min']??'')!==''||($r['age_max']??'')!=='')?1:0);
   $matches[$score][]=$r;
  }
  if(!$matches)return new WP_Error('entrance_no_eligible_rate');
  $best=$matches[max(array_keys($matches))];
  return count($best)===1?$best[0]:new WP_Error('entrance_ambiguous_rate');
 }
 /** Explicit cohort line: nationality, ticket category, age, quantity, student-ID checked (yes/no).
  * No names/passport numbers are collected. Cohort counts must match the party counts.
  */
 static function cohorts($raw):array|WP_Error {
  if(!is_string($raw)||strlen($raw)>3000)return new WP_Error('entrance_cohort_invalid');
  $out=[];
  foreach(preg_split('/\r?\n/',trim($raw)) as $line){
   if(trim($line)==='')continue;
   $parts=array_map('trim',explode(',',$line));
   if(count($parts)!==5)return new WP_Error('entrance_cohort_invalid');
   [$nat,$cat,$age,$qty,$student]=$parts;$nat=strtoupper($nat);
   if(!self::nationality($nat)||!in_array($cat,['adult','child','infant','student'],true)||
    !preg_match('/^[0-9]{1,3}$/D',$age)||(int)$age>120||!preg_match('/^[1-9][0-9]?$/D',$qty)||(int)$qty>60||
    !in_array($student,['yes','no'],true)||($cat==='student'&&$student!=='yes'))return new WP_Error('entrance_cohort_invalid');
   $out[]=['nationality'=>$nat,'category'=>$cat,'age'=>(int)$age,'quantity'=>(int)$qty,'student_verified'=>$student==='yes'];
   if(count($out)>60)return new WP_Error('entrance_cohort_limit');
  }
  return $out;
 }
 static function resolve(array $rows,string $kind,string $date,array $ctx,string $key='',?array $fx=null):array {
  if(!empty($ctx['entrance_cohorts']))return self::resolveCohorts($rows,$kind,$date,$ctx,$key,$fx);

  $out=['status'=>'entrance_context_required','service_date'=>$date,'lines'=>[],'reference_total_minor'=>null,'cost_qualified'=>false,'commercially_approved'=>false];
  $nat=self::nationality($ctx['nationality']??'');$target=$ctx['currency']??'';
  if(!self::date($date)||!$nat||!in_array($target,self::CURRENCIES,true))return $out;
  $counts=[];foreach(['adult'=>'adults','child'=>'children','infant'=>'infants'] as $cat=>$f){
   $v=$ctx[$f]??null;if(!is_int($v)||$v<0||$v>60||($cat==='adult'&&!$v))return $out;$counts[$cat]=$v;
  }
  $clean=[];$seen=[];
  foreach($rows as $r){if(!is_array($r)){$out['status']='entrance_invalid_rate';return $out;}
   $v=self::normalize($r,$kind);if(is_wp_error($v)||isset($seen[$v['fee_key']])){$out['status']='entrance_invalid_rate';return $out;}
   $seen[$v['fee_key']]=true;$clean[]=$v;}
  $group=self::pick($clean,$date,$nat,'all','per_group',$key);
  $person=[];$errs=[];
  foreach($counts as $cat=>$qty)if($qty){
   $r=self::pick($clean,$date,$nat,$cat,'per_person',$key);
   if(is_wp_error($r))$errs[]=$r->get_error_code();else $person[]=[$r,$qty,$cat];
  }
  if(!is_wp_error($group)&&($person||in_array('entrance_ambiguous_rate',$errs,true))){$out['status']='entrance_ambiguous_basis';return $out;}
  if(is_wp_error($group)&&$group->get_error_code()==='entrance_ambiguous_rate'){$out['status']='entrance_ambiguous_rate';return $out;}
  $selected=!is_wp_error($group)?[[$group,1,'group']]:$person;
  if(is_wp_error($group)&&$errs){$out['status']=in_array('entrance_ambiguous_rate',$errs,true)?'entrance_ambiguous_rate':'entrance_no_eligible_rate';return $out;}
  $total=0;
  foreach($selected as [$r,$qty,$cat]){
   if($r['coverage']!=='admission_only'){$out['status']='activity_bundle_coverage_review_required';return $out;}
   $l=(float)$r['amount']===0.0?['target_total_minor'=>0,'source_total_minor'=>0,'quantity'=>$qty,'source_currency'=>$r['currency'],'target_currency'=>$target,'unit_amount'=>$r['amount']]:
    MAD4B_Generic_Cost_Composition_V1::line($r['fee_key'],$r['amount'],$r['currency'],$qty,$target,$fx);
   if(!is_int($l['target_total_minor']??null)){$out['status']='entrance_fx_or_amount_missing';return $out;}
   $total+=$l['target_total_minor'];if($total>1000000000){$out['status']='entrance_amount_limit';return $out;}
   $out['lines'][]=$l+['fee_key'=>$r['fee_key'],'visitor_category'=>$cat,'market_scope'=>$r['market_scope'],'evidence_ref'=>$r['evidence_ref'],'price_role'=>$r['price_role']];
  }
  $out['status']='entrance_reference_calculated';$out['reference_total_minor']=$total;
  return $out;
 }
 static function resolveCohorts(array $rows,string $kind,string $date,array $ctx,string $key='',?array $fx=null):array {
  $o=['status'=>'entrance_cohort_invalid','service_date'=>$date,'lines'=>[],'reference_total_minor'=>null,'cost_qualified'=>false,'commercially_approved'=>false];
  $groups=self::cohorts($ctx['entrance_cohorts']);
  if(is_wp_error($groups)||!$groups||!self::date($date)||!in_array($ctx['currency']??'',self::CURRENCIES,true))return $o;
  $sum=0;foreach($groups as $g)$sum+=$g['quantity'];
  $party=0;foreach(['adults','children','infants'] as $k){if(!is_int($ctx[$k]??null)||$ctx[$k]<0)return $o;$party+=$ctx[$k];}
  if($party!==$sum||$sum>120){$o['status']='entrance_cohort_party_mismatch';return $o;}
  $clean=[];$seen=[];foreach($rows as $r){
   if(!is_array($r))return $o;$v=self::normalize($r,$kind);
   if(is_wp_error($v)||isset($seen[$v['fee_key']])){$o['status']='entrance_invalid_rate';return $o;}
   $seen[$v['fee_key']]=true;$clean[]=$v;
  }
  // Group rates cannot be multiplied per cohort; require separate group selection and eligibility review.
  foreach($groups as $g){$group=self::pick($clean,$date,$g['nationality'],'all','per_group',$key);
   if(!is_wp_error($group)||$group->get_error_code()==='entrance_ambiguous_rate'){$o['status']='entrance_mixed_group_basis_review_required';return $o;}}
  $total=0;
  foreach($groups as $g){
   $r=self::pick($clean,$date,$g['nationality'],$g['category'],'per_person',$key,$g['age']);
   if(is_wp_error($r)){$o['status']=$r->get_error_code();return $o;}
   if($r['coverage']!=='admission_only'){$o['status']='activity_bundle_coverage_review_required';return $o;}
   $l=(float)$r['amount']===0.0?['target_total_minor'=>0,'source_total_minor'=>0,'quantity'=>$g['quantity'],'source_currency'=>$r['currency'],'target_currency'=>$ctx['currency'],'unit_amount'=>$r['amount']]:
    MAD4B_Generic_Cost_Composition_V1::line($r['fee_key'],$r['amount'],$r['currency'],$g['quantity'],$ctx['currency'],$fx);
   if(!is_int($l['target_total_minor']??null)){$o['status']='entrance_fx_or_amount_missing';return $o;}
   $total+=$l['target_total_minor'];if($total>1000000000){$o['status']='entrance_amount_limit';return $o;}
   $o['lines'][]=$l+['fee_key'=>$r['fee_key'],'cohort'=>$g,'evidence_ref'=>$r['evidence_ref'],'price_role'=>$r['price_role']];
  }
  $o['status']='entrance_reference_calculated';$o['reference_total_minor']=$total;return $o;
 }
 static function url(int $id):string {return admin_url('admin.php?page='.self::PAGE.'&product_id='.$id);}
 static function box($post):void {
  $id=(int)$post->ID;if(!self::allowed($id))return;
  $label=self::kind($id)==='activity'?'Activity Internal Transfer Rates':'Entrance Fee Rates';
  echo '<p>'.esc_html(count((array)(self::current($id)['rows']??[])).' rate rows').'</p><p><a class="button button-primary" href="'.esc_url(self::url($id)).'">'.esc_html($label).'</a></p><p>Cost reference only. Retail rates remain unchanged.</p>';
 }
 static function fields($post):void {
  if(self::allowed((int)$post->ID))add_meta_box('mad4b_entrance_rates',self::kind((int)$post->ID)==='activity'?'Activity Internal Transfer Rates':'Entrance Fee Rates',[self::class,'box'],$post->post_type,'normal','default');
 }
 static function boot():void {
  foreach(['attractions','tours-and-activities'] as $type)add_action('add_meta_boxes_'.$type,[self::class,'fields']);
  add_action('admin_menu',static function(){add_submenu_page('mad4b-pricing-workspace','Entrance & Activity Rates','Entrance & Activity Rates','commerce_manage_pricing',self::PAGE,[self::class,'page']);},876);
  add_action('admin_post_mad4b_entrance_rates_save',[self::class,'action']);
 }
 static function page():void {
  if(!current_user_can('commerce_manage_pricing'))wp_die('Access denied');
  $id=absint($_GET['product_id']??0);
  if(!$id){echo '<div class="wrap"><h1>Entrance &amp; Activity Rates</h1><form method="get"><input type="hidden" name="page" value="'.self::PAGE.'"><label>Attraction or Activity ID <input required type="number" name="product_id" min="1"></label> <button class="button">Open</button></form>';if(class_exists('MAD4B_Entrance_Tariff_Catalog_V1',false))MAD4B_Entrance_Tariff_Catalog_V1::panel();echo '</div>';return;}
  if(!self::allowed($id))wp_die('Access denied');
  $r=self::current($id);$rows=(array)($r['rows']??[]);$rows[]=[];
  echo '<div class="wrap"><h1>'.esc_html(self::kind($id)==='activity'?'Activity Internal Transfer Rates':'Entrance Fee Rates').' — '.esc_html(get_the_title($id)).'</h1><p>Use exact nationality ISO code (EG, US, GB, etc.), foreign (non-EG), or all. Reviewed reference requires evidence. Full activity bundle requires a separate coverage review. No retail or checkout approval.</p>';
  foreach($rows as $v){
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="border:1px solid #ccd0d4;padding:12px;margin:10px 0;background:white">';
   wp_nonce_field('mad4b_entrance_rates_'.$id);
   foreach(['action'=>'mad4b_entrance_rates_save','product_id'=>$id,'revision'=>$r['revision']??0,'fee_key'=>$v['fee_key']??wp_generate_uuid4()] as $k=>$x)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$x).'">';
   foreach(['amount'=>'Amount (major currency units)','market_scope'=>'Nationality / market','valid_from'=>'Valid from','valid_to'=>'Valid to','evidence_ref'=>'Evidence / document reference','age_min'=>'Minimum age (optional)','age_max'=>'Maximum age (optional)'] as $k=>$label)
    echo '<label>'.esc_html($label).' <input '.(in_array($k,['evidence_ref','age_min','age_max'],true)?'':'required').' name="'.esc_attr($k).'" type="'.(str_starts_with($k,'valid_')?'date':'text').'" value="'.esc_attr($v[$k]??($k==='market_scope'?'all':'')).'"></label> ';
   echo '<p>';
   foreach(['currency'=>self::CURRENCIES,'basis'=>['per_person','per_group'],'visitor_category'=>['adult','child','infant','student','all'],'review_status'=>['draft','reviewed_reference'],'coverage'=>self::kind($id)==='attraction'?['admission_only']:['admission_only','activity_bundle']] as $k=>$choices){
    echo '<label>'.esc_html($k).' <select name="'.esc_attr($k).'">';
    foreach($choices as $x)echo '<option value="'.esc_attr($x).'"'.selected($v[$k]??$choices[0],$x,false).'>'.esc_html($x).'</option>';
    echo '</select></label> ';
   }
   echo '</p><button class="button button-primary" name="operation" value="save">Save rate reference</button> ';
   if($v)echo '<button class="button" name="operation" value="remove" formnovalidate>Remove</button>';
   echo '</form>';
  }
  echo '</div>';
 }
 static function action():void {
  $id=absint($_POST['product_id']??0);
  if(!self::allowed($id))wp_die('Access denied','Denied',['response'=>403]);
  check_admin_referer('mad4b_entrance_rates_'.$id);
  $v=[];foreach(['fee_key','amount','currency','basis','visitor_category','market_scope','valid_from','valid_to','review_status','evidence_ref','coverage','age_min','age_max'] as $k){if(!is_string($_POST[$k]??null))wp_die('Invalid field');$v[$k]=wp_unslash($_POST[$k]);}
  $rows=[];foreach((array)(self::current($id)['rows']??[]) as $row)if($row['fee_key']!==$v['fee_key'])$rows[]=$row;
  if(($_POST['operation']??'')!=='remove')$rows[]=$v;
  $r=self::save($id,$rows,absint($_POST['revision']??0));if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));
  wp_safe_redirect(self::url($id));exit;
 }
}
MAD4B_Entrance_Source_Rates_V1::boot();
