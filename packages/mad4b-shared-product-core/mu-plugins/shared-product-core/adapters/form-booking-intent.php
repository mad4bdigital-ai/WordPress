<?php
/** Lab-only Fluent Forms compatibility and optional per-traveler collection. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
/**
 * Fluent Form Arrival Date datetime uses d-M-y, e.g. 15-Aug-26.
 * Normalize strictly to ISO at intake. Booking v2 calls it travel_date.
 */
function shared_product_fluent_arrival_iso($raw){
 if(!is_scalar($raw) && $raw!==null)return new WP_Error('invalid_booking_date');
 $value=trim((string)($raw??''));
 if($value==='')return '';
 if(preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$value,$parts)){
  $year=(int)$parts[1];$month=(int)$parts[2];$day=(int)$parts[3];
 }elseif(preg_match('/^([0-9]{1,2})-([A-Za-z]{3})-([0-9]{2}|[0-9]{4})$/D',$value,$parts)){
  $months=['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
           'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
  $month=$months[strtolower($parts[2])]??0;
  $day=(int)$parts[1];$year=(int)$parts[3];
  if(strlen($parts[3])===2)$year+=2000;
 }else{return new WP_Error('invalid_booking_date');}
 if($year<2000||$year>2100||!checkdate($month,$day,$year))
  return new WP_Error('invalid_booking_date');
 return sprintf('%04d-%02d-%02d',$year,$month,$day);
}
function shared_product_form_booking_intent($form_id,$input){
 if(!in_array((int)$form_id,[5,6,7,8],true)||!is_array($input))return new WP_Error('unsupported_booking_form');
 $single=in_array((int)$form_id,[6],true);
 $adults=$input[$single?'no_of_adults':((int)$form_id===5?'numeric_field':'no_of_adults')]??null;
 $children=$input[$single?'no_of_children':((int)$form_id===5?'numeric_field_1':'no_of_children')]??0;
 $infants=$input[$single?'no_of_infants':((int)$form_id===5?'numeric_field_2':'no_of_infants')]??0;
 $counts=[];
 foreach(['adults'=>$adults,'children'=>$children,'infants'=>$infants] as $k=>$v){
  $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$k==='adults'?1:0,'max_range'=>100]]);
  if($n===false||$n===null)return new WP_Error('invalid_traveler_count');$counts[$k]=$n;
 }
 $total=array_sum($counts);if($total>100)return new WP_Error('too_many_travelers');
 $flex=($input['tour_date_flexibility']??'false')==='true';
 $date=shared_product_fluent_arrival_iso($input['datetime']??'');
 $end=$flex?shared_product_fluent_arrival_iso($input['datetime_2']??''):'';
 if(is_wp_error($date))return $date;
 if(is_wp_error($end))return $end;
 if($flex && (!$date||!$end||$end<$date))return new WP_Error('invalid_flexible_window');
 $opt_in=filter_var($input['shared_traveler_details_opt_in']??false,FILTER_VALIDATE_BOOLEAN);
 $rows=$input['shared_traveler_details']??[];
 if(!$opt_in && $rows)return new WP_Error('traveler_details_without_optin');
 if(!is_array($rows)||count($rows)>$total)return new WP_Error('traveler_rows_out_of_range');
 $travelers=[];
 if($opt_in){
  foreach($rows as $i=>$row){
   if(!is_array($row))return new WP_Error('invalid_traveler_row');
   $age=$row['age']??null;$gender=$row['gender']??'';$nationality=$row['nationality']??'';
   if($age!==null&&$age!==''&&filter_var($age,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>120]])===false)return new WP_Error('invalid_traveler_age');
   if(!in_array($gender,['','female','male','other','prefer_not_to_say'],true))return new WP_Error('invalid_traveler_gender');
   if(!is_string($nationality)||($nationality!==''&&!preg_match('/^[A-Z]{2}$/D',$nationality)))return new WP_Error('invalid_traveler_nationality');
   $travelers[]=['age'=>$age===''?null:($age===null?null:(int)$age),'gender'=>$gender,'nationality'=>$nationality];
  }
 }
 $services=array_values(array_intersect((array)($input['checkbox_Services']??[]),['Tours','Properties','Transportations']));
 $mode=$flex||!empty($services)||in_array((int)$form_id,[5,7,8],true)?'request':'booking_candidate';
 return ['schema'=>1,'source_form_id'=>(int)$form_id,'mode'=>$mode,'counts'=>$counts,'total_travelers'=>$total,
  'dates'=>['start'=>$date,'flexible'=>$flex,'end'=>$flex?$end:null],
  'services'=>$services,'selected_posts'=>array_filter(array_map('absint',[$input['cpt_selection']??0,$input['cpt_selection_1']??0,$input['cpt_selection_2']??0])),
  'traveler_details_opt_in'=>$opt_in,'traveler_details'=>$travelers,'status'=>'normalized_only_not_checkout_authority'];
}
/** Embed beside an existing Fluent Form; optional rows never replace server-side validation. */
add_shortcode('shared_product_traveler_repeater',static function($a){
 $a=shortcode_atts(['form_id'=>'6'],$a);
 $id=(int)$a['form_id'];if(!in_array($id,[5,6,7,8],true))return '';
 $adult=$id===5?'numeric_field':'no_of_adults';$child=$id===5?'numeric_field_1':'no_of_children';$infant=$id===5?'numeric_field_2':'no_of_infants';
 $uid='shared-travelers-'.wp_generate_uuid4();
 ob_start(); ?>
 <section id="<?php echo esc_attr($uid); ?>" class="shared-traveler-repeater" data-form-id="<?php echo esc_attr($id); ?>" data-count-fields="<?php echo esc_attr(implode(',',[$adult,$child,$infant])); ?>">
  <label><input type="checkbox" name="shared_traveler_details_opt_in" value="1"> <?php esc_html_e('Add individual traveler details (optional)','woocommerce'); ?></label>
  <div class="shared-traveler-rows" aria-live="polite"></div>
 </section>
 <style>.shared-traveler-rows{display:grid;gap:12px;margin-top:12px}.shared-traveler-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;padding:12px;border:1px solid #ddd;border-radius:8px}.shared-traveler-row label{display:grid;gap:4px}.shared-traveler-row input,.shared-traveler-row select{max-width:100%;min-width:0}</style>
 <script>(function(){const root=document.getElementById(<?php echo wp_json_encode($uid); ?>);if(!root)return;const form=root.closest('form')||document.querySelector('form.fluent_form_'+root.dataset.formId);if(!form)return;const box=root.querySelector('input[type=checkbox]'),out=root.querySelector('.shared-traveler-rows');const names=root.dataset.countFields.split(',');function render(){const count=Math.min(100,names.reduce((sum,n)=>sum+Math.max(0,parseInt(form.querySelector('[name="'+n+'"]')?.value||'0',10)||0),0));const old=Array.from(out.children).map(el=>({age:el.querySelector('[data-field=age]')?.value||'',gender:el.querySelector('[data-field=gender]')?.value||'',nationality:el.querySelector('[data-field=nationality]')?.value||''}));out.replaceChildren();if(!box.checked)return;for(let i=0;i<count;i++){const row=document.createElement('div');row.className='shared-traveler-row';const fields=[['age','number','Age'],['gender','select','Gender'],['nationality','text','Nationality (ISO country code)']];fields.forEach(([field,type,label])=>{const wrap=document.createElement('label');wrap.textContent=label;let input;if(type==='select'){input=document.createElement('select');[['','Prefer not to specify'],['female','Female'],['male','Male'],['other','Other'],['prefer_not_to_say','Prefer not to say']].forEach(([v,t])=>input.add(new Option(t,v)));}else{input=document.createElement('input');input.type=type;if(field==='age'){input.min='0';input.max='120';}if(field==='nationality'){input.maxLength=2;input.placeholder='EG';}}input.name='shared_traveler_details['+i+']['+field+']';input.dataset.field=field;input.value=old[i]?.[field]||'';wrap.append(input);row.append(wrap);});out.append(row);}}box.addEventListener('change',render);names.forEach(n=>form.querySelector('[name="'+n+'"]')?.addEventListener('input',render));render();})();</script>
 <?php return ob_get_clean();
});
