<?php
/**
 * Plugin Name: Shared Property Amenity Offers
 * Description: Property/Unit-specific priced or included offerings referencing the existing descriptive Amenities taxonomy.
 * Version: 0.1.1
 */
defined('ABSPATH') || exit;
final class Shared_Property_Amenity_Offers {
 const META='_shared_amenity_offers_v1';
 const CONTRACT='shared.property-amenity-offers.v1';
 const MODES=['included','paid','on_request'];
 const FULFILLMENT=['prebook','pay_at_property'];
 const BASES=['per_stay','per_night','per_person','per_person_night',
  'per_room','per_room_night','per_cabin','per_cabin_night','per_use'];
 public static function rows($id){
  $data=get_post_meta(absint($id),self::META,true);
  return is_array($data)?$data:[];
 }
 /** Canonical term is the stable pricing identity, never a locale slug. */
 public static function canonical_term($id){
  $id=absint($id);if(!$id||!term_exists($id,'amenities'))return 0;
  if(!has_filter('wpml_object_id'))return $id;
  $english=apply_filters('wpml_object_id',$id,'amenities',false,'en');
  return $english&&term_exists((int)$english,'amenities')?(int)$english:0;
 }
 private static function source_post($post){
  $post=absint($post);$type=get_post_type($post);
  if(!in_array($type,['properties','units'],true)||!has_filter('wpml_object_id'))return $post;
  $default=apply_filters('wpml_default_language','en');
  $source=apply_filters('wpml_object_id',$post,$type,false,$default?:'en');
  return $source&&get_post_type((int)$source)===$type?(int)$source:$post;
 }
 private static function post_language($post){
  if(!has_filter('wpml_post_language_details'))return 'en';
  $details=apply_filters('wpml_post_language_details',null,absint($post));
  return is_array($details)&&!empty($details['language_code'])?
    (string)$details['language_code']:'en';
 }
 /** Explicit local pricing wins; original translation can supply fallback.
  * Missing translated taxonomy identity or relationship denies the offer.
  */
 private static function offers_for_post($post){
  $source=self::source_post($post);
  $rows=[];
  foreach(array_unique([$source,$post]) as $id){
   foreach(self::rows($id) as $row){
    $canonical=self::canonical_term($row['term_id']??0);
    if(!$canonical)continue;
    $rows[$canonical]=$row+['offer_source_post'=>$id];
    $rows[$canonical]['offer_source_post']=$id;
   }
  }
  return $rows;
 }
 public static function normalize($data,$post,$allowedTerms=[]){
  if(!is_array($data)||count($data)>90)return new WP_Error('amenity_offer_invalid_count');
  $out=[];$seen=[];
  foreach($data as $row){
   if(!is_array($row))return new WP_Error('amenity_offer_row_invalid');
   $id=absint($row['term_id']??0);
   if(!$id)continue;
   if(isset($seen[$id]))return new WP_Error('amenity_offer_duplicate_term');
   $seen[$id]=true;
   if(!term_exists($id,'amenities')||!in_array($id,$allowedTerms,true))
    return new WP_Error('amenity_offer_unassigned_term');
   $mode=sanitize_key((string)($row['mode']??'on_request'));
   $basis=sanitize_key((string)($row['basis']??'per_use'));
   $fulfillment=sanitize_key((string)($row['fulfillment']??'prebook'));
   if(!in_array($mode,self::MODES,true)||!in_array($basis,self::BASES,true)||
      !in_array($fulfillment,self::FULFILLMENT,true))return new WP_Error('amenity_offer_invalid_contract');
   $price=null;$currency='';
   if($mode==='paid'){
    $raw=$row['amount']??null;
    if(!is_scalar($raw)||is_bool($raw)||
      !preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D',(string)$raw)||
      (float)$raw<=0)return new WP_Error('amenity_offer_invalid_amount');
    $price=(float)$raw;
    $currency=strtoupper(trim((string)($row['currency']??'')));
    if(!preg_match('/^[A-Z]{3}$/D',$currency))
      return new WP_Error('amenity_offer_invalid_currency');
   }
   if($mode==='included'&&$fulfillment==='pay_at_property')
    return new WP_Error('amenity_offer_included_cannot_be_pay_at_property');
   $out[]=['term_id'=>$id,'mode'=>$mode,'amount'=>$price,'currency'=>$currency,
     'basis'=>$basis,'fulfillment'=>$fulfillment,'active'=>true,
     'evidence_note'=>sanitize_text_field((string)($row['evidence_note']??''))];
  }
  return $out;
 }
 public static function catalog($property,$unit){
  $property=absint($property);$unit=absint($unit);
  if(get_post_type($property)!=='properties'||get_post_type($unit)!=='units')
   return new WP_Error('amenity_catalog_entity_invalid');
  $lang=self::post_language($unit);
  $allowed=[];
  foreach([$property,$unit] as $post){
   $source=self::source_post($post);
   foreach(array_unique([$source,$post]) as $id){
    $terms=wp_get_object_terms($id,'amenities',['fields'=>'ids']);
    if(is_wp_error($terms))return new WP_Error('amenity_catalog_terms_unavailable');
    foreach($terms as $tid){
     $canonical=self::canonical_term($tid);
     if($canonical)$allowed[$canonical]=true;
    }
   }
  }
  $chosen=[];
  // Order: original Property -> locale Property -> original Unit -> locale Unit.
  foreach([$property,$unit] as $post){
   foreach(self::offers_for_post($post) as $canon=>$r){
    if(isset($allowed[$canon]))$chosen[$canon]=$r+
     ['owner_post'=>$post];
    if(isset($chosen[$canon])&&isset($allowed[$canon]))
     $chosen[$canon]['owner_post']=$post;
   }
  }
  $result=[];$seenTerms=[];
  foreach($chosen as $canonical=>$r){
   $local=$canonical;
   if(has_filter('wpml_object_id')){
    $translated=apply_filters('wpml_object_id',$canonical,'amenities',false,$lang);
    if(!$translated||!term_exists((int)$translated,'amenities'))continue;
    $local=(int)$translated;
   }
   if(isset($seenTerms[$local]))return new WP_Error('amenity_catalog_duplicate_translation');
   $seenTerms[$local]=true;
   $term=get_term($local,'amenities');if(!$term||is_wp_error($term))continue;
   $result[]=['term_id'=>$local,'canonical_term_id'=>(int)$canonical,
    'label'=>$term->name,'slug'=>$term->slug,
    'mode'=>$r['mode'],'price'=>$r['amount']??null,'currency'=>$r['currency']??'',
    'basis'=>$r['basis']??'per_use','fulfillment'=>$r['fulfillment']??'prebook',
    'source_entity'=>$r['owner_post'],'source_price_post'=>$r['offer_source_post']??$r['owner_post'],
    'source_type'=>$r['owner_post']===$unit?'unit':'property',
    'status'=>'catalog_only_not_order_authority'];
  }
  return $result;
 }
 public static function quote_selection($selection,$catalog,$context,$currency){
  if(!is_array($selection)||!array_is_list($selection)||count($selection)>30||
     !is_array($catalog)||!is_array($context))return new WP_Error('amenity_selection_invalid');
  $index=[];foreach($catalog as $r)$index[$r['term_id']]=$r;
  $lines=[];$seen=[];$total=0.0;
  foreach($selection as $row){
   if(!is_array($row)||!isset($row['term_id'],$row['quantity'])||
      !is_int($row['term_id'])||!is_int($row['quantity'])||
      $row['quantity']<1||$row['quantity']>100)return new WP_Error('amenity_selection_invalid');
   $id=$row['term_id'];$qty=$row['quantity'];
   if(isset($seen[$id])||!isset($index[$id]))return new WP_Error('amenity_selection_unavailable');
   $seen[$id]=true;$offer=$index[$id];
   if($offer['mode']==='on_request')
    return new WP_Error('amenity_price_on_request');
   if($offer['mode']==='included'){
    $lines[]=['kind'=>'amenity','code'=>'amenity:'.$id,'term_id'=>$id,
      'label'=>$offer['label'],'amount'=>0.0,'currency'=>$currency,
      'source'=>$offer['source_type'],'fulfillment'=>'included',
      'status'=>'included_in_property_or_unit'];continue;
   }
   if($offer['fulfillment']==='pay_at_property'){
    // Never pre-charge unspecified consumption or incidentals.
    $lines[]=['kind'=>'amenity','code'=>'amenity:'.$id,'term_id'=>$id,
      'label'=>$offer['label'],'amount'=>0.0,'currency'=>$currency,
      'source'=>$offer['source_type'],'fulfillment'=>'pay_at_property',
      'indicative_source_unit_price'=>$offer['price'],'source_currency'=>$offer['currency'],
      'indicative_basis'=>$offer['basis'],
      'amount_due_at_property_unverified'=>true,'status'=>'excluded_from_prebook_total'];
    continue;
   }
   $charge=Shared_Extra_Unit_V1::quote($offer['price'],$offer['currency'],
      $currency,$offer['basis'],$context,$qty);
   if(is_wp_error($charge))return $charge;
   $total=round($total+$charge['amount'],2);
   $lines[]=['kind'=>'amenity','code'=>'amenity:'.$id,'term_id'=>$id,
     'label'=>$offer['label'],'amount'=>$charge['amount'],
     'currency'=>$currency,'source'=>$offer['source_type'],
     'fulfillment'=>'prebook','price_receipt'=>$charge,
     'status'=>'nonbinding_prebook_preview'];
  }
  return ['lines'=>$lines,'addon_total'=>$total,'currency'=>$currency,
    'contract'=>self::CONTRACT,'checkout_ready'=>false,'status'=>'nonbinding_amenity_extras_preview'];
 }
 private static function post_types(){return ['properties','units'];}
 public static function register(){
  foreach(self::post_types() as $type){
   add_meta_box('shared-property-amenity-offers','Amenities · Included & Paid Services',
    [__CLASS__,'render'],$type,'normal','default');
  }
 }
 public static function render($post){
  if(!current_user_can('edit_post',$post->ID))return;
  wp_nonce_field('shared_amenity_offers_save','shared_amenity_nonce');
  $existing=self::rows($post->ID);
  $selected=wp_get_object_terms($post->ID,'amenities',['fields'=>'ids']);
  if(is_wp_error($selected))$selected=[];
  $options=get_terms(['taxonomy'=>'amenities','hide_empty'=>false]);
  if(is_wp_error($options))$options=[];
  echo '<p>Choose amenities from the existing WordPress taxonomy. An amenity stays descriptive globally; prices and inclusion status are stored only on this Property or Unit. Unit settings override Property settings. Blank prices are never interpreted as free.</p>';
  echo '<p>Assign the Amenity in the native Taxonomy selector, then tick it here only when you want to configure its local access/price. Ticking also assigns the Term. Unticking removes the local commercial offer, not the descriptive taxonomy relation. Missing commercial rules do not mean free access.</p>';
  echo '<p><label>Find Amenity: <input type="search" class="regular-text" id="shared-amenity-filter" placeholder="Kids, pool, sauna, beach …" autocomplete="off"></label></p>';
  echo '<div style="max-width:100%;overflow:auto"><table class="widefat striped"><thead><tr>';
  foreach(['Use','Amenity','Access','Start price','Currency','Charge basis','Settlement','Source / conditions'] as $h)
   echo '<th>'.esc_html($h).'</th>';
  echo '</tr></thead><tbody>';
  $byId=[];foreach($existing as $r)$byId[(int)$r['term_id']]=$r;
  foreach($options as $t){
   $id=(int)$t->term_id;$r=$byId[$id]??[];
   $checked=isset($byId[$id]);
   $prefix='shared_amenity_offers['.$id.']';
   echo '<tr><td><input type="checkbox" name="'.esc_attr($prefix.'[selected]').'" value="1" '.checked($checked,true,false).'></td>';
   echo '<td><strong>'.esc_html($t->name).'</strong><small style="display:block">'.esc_html($t->slug).'</small></td>';
   echo '<td><select name="'.esc_attr($prefix.'[mode]').'">';
   foreach(['on_request'=>'At request / unpriced','included'=>'Included (no added fee)','paid'=>'Paid starting from'] as $k=>$v)
    echo '<option value="'.esc_attr($k).'" '.selected($r['mode']??'on_request',$k,false).'>'.esc_html($v).'</option>';
   echo '</select></td>';
   foreach(['amount'=>['number','0.01','100000000'],'currency'=>['text','',''],
     'basis'=>null,'fulfillment'=>null,'evidence_note'=>['text','','']] as $field=>$spec){
    if($field==='basis'||$field==='fulfillment'){
     $choices=$field==='basis'?array_combine(self::BASES,self::BASES):
       ['prebook'=>'Prebook / estimated','pay_at_property'=>'Pay at Property'];
     echo '<td><select name="'.esc_attr($prefix.'['.$field.']').'">';
     foreach($choices as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($r[$field]??($field==='basis'?'per_use':'prebook'),$k,false).'>'.esc_html($v).'</option>';
     echo '</select></td>';
    }else{
     $inputName=$prefix.'['.$field.']';
     $attr=($field==='amount')?' min="0" step="0.01"':'';
     echo '<td><input style="max-width:120px" type="'.esc_attr($spec[0]).'"'.
       $attr.' name="'.esc_attr($inputName).'" value="'.esc_attr($r[$field]??'').'"></td>';
    }
   }
   echo '</tr>';
  }
  echo '</tbody></table></div>';
  echo <<<'JS'
<script>
(function(){
  const root=document.getElementById('shared-property-amenity-offers');
  if(!root)return;
  const input=root.querySelector('#shared-amenity-filter');
  const rows=[...root.querySelectorAll('tbody tr')];
  const update=()=>{
    const query=(input?.value||'').trim().toLocaleLowerCase();
    for(const row of rows){
      const selected=row.querySelector('input[type=checkbox]')?.checked;
      const mode=row.querySelector('select[name*="[mode]"]')?.value;
      row.style.display=!query||row.innerText.toLocaleLowerCase().includes(query)?'':'none';
      row.style.opacity=selected?'1':'.65';
      for(const field of ['amount','currency']){
        const el=row.querySelector('[name*="['+field+']"]');
        if(!el)continue;
        el.disabled=!(selected&&mode==='paid');
        el.closest('td').style.opacity=selected&&mode==='paid'?'1':'.4';
      }
    }
  };
  root.addEventListener('change',update);
  input?.addEventListener('input',update);
  update();
})();
</script>
JS;
  echo '<p>Prices do not inherit from Amenity terms. Unselected amenities are not included in this Property/Unit catalog. Existing board plans and legacy services remain separately priced to prevent accidental duplication.</p>';
 }
 public static function save($id){
  if(!current_user_can('edit_post',$id)||
    !isset($_POST['shared_amenity_nonce'])||
    !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_amenity_nonce'])),'shared_amenity_offers_save')||
    wp_is_post_revision($id)||wp_is_post_autosave($id))return;
  $raw=isset($_POST['shared_amenity_offers'])&&is_array($_POST['shared_amenity_offers'])?
    wp_unslash($_POST['shared_amenity_offers']):[];
  $newTerms=[];$offers=[];
  foreach($raw as $tid=>$r){
   if(!is_array($r)||empty($r['selected']))continue;
   $tid=absint($tid);
   if(!$tid||!term_exists($tid,'amenities'))continue;
   $newTerms[]=$tid;
   $offers[]=['term_id'=>$tid,'mode'=>$r['mode']??'on_request',
    'amount'=>$r['amount']??'','currency'=>$r['currency']??'',
    'basis'=>$r['basis']??'per_use','fulfillment'=>$r['fulfillment']??'prebook',
    'evidence_note'=>$r['evidence_note']??''];
  }
  $validated=self::normalize($offers,$id,$newTerms);
  if(is_wp_error($validated)){
   set_transient('shared_amenity_error_'.get_current_user_id(),$validated->get_error_code(),60);
   return;
  }
  // Preserve existing taxonomy assignments not managed by this meta box.
  $existing=wp_get_object_terms($id,'amenities',['fields'=>'ids']);
  if(is_wp_error($existing))return;
  $terms=array_values(array_unique(array_merge(array_map('intval',$existing),$newTerms)));
  $set=wp_set_object_terms($id,$terms,'amenities',false);
  if(is_wp_error($set))return;
  update_post_meta($id,self::META,$validated);
 }
 public static function notice(){
  $key='shared_amenity_error_'.get_current_user_id();
  $error=get_transient($key);
  if(!$error)return;
  delete_transient($key);
  echo '<div class="notice notice-error"><p>Amenity offers were not saved: '.esc_html($error).'</p></div>';
 }
 public static function boot(){
  add_action('add_meta_boxes',[__CLASS__,'register'],30);
  foreach(self::post_types() as $pt)
   add_action('save_post_'.$pt,[__CLASS__,'save'],95);
  add_action('admin_notices',[__CLASS__,'notice']);
 }
}
Shared_Property_Amenity_Offers::boot();
