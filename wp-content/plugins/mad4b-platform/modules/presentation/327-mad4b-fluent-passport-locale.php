<?php
/**
 * Plugin Name: MAD4B Fluent Passport Nationality Localization (Staging)
 * Description: Localized country names in Fluent Forms; preserves existing saved values.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Fluent_Passport_Locale_V1 {
 private const SITE_UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 private static bool $has_nationality_field=false;
 private const LEGACY_VALUES_SHA='b245925f0bb1c3ee0aa5ac9d9a70c6703c97da9f2aa13f798f9738249610d5b7';
 private const LEGACY_CODES='AF AL DZ US AD AO AG AR AM AU AT AZ BS BH BD BB BY BE BZ BJ BT BO BA BW BR BN BG BF MM BI CV KH CM CA CF TD CL CN CO KM CG CD CR HR CU CY CZ DK DJ DO DM NL TL EC EG AE GQ ER EE SZ ET FJ FI FR GA GM GE DE GH GR GD GT GN GW GY HT HN HU IS IN ID IR IQ IE IL IT CI JM JP JO KZ KE KI KW KG LA LV LB LS LR LY LI LT LU MG MW MY MV ML MT MH MR MU MX FM MD MC MN ME MA MZ NA NR NP NZ NI NE NG KP MK NO OM PK PW PS PA PG PY PE PH PL PT QA RO RU RW LC VC SV WS SM ST SA SN RS SC SL SG SK SI SB SO ZA KR SS ES LK SD SR SZ SE CH SY TW TJ TZ TH TG TO TT TN TR TM TV UG UA UY UZ VU VA VE VN YE ZM ZW';
 private const LOCALES=['en'=>'en','fr'=>'fr','de'=>'de','ar'=>'ar','es'=>'es','it'=>'it'];
 public static function language():string {
  $lang=(string)apply_filters('wpml_current_language',null);
  return self::LOCALES[$lang]??'';
 }
 public static function site_active():bool {
  if(wp_get_environment_type()!=='staging'||(string)wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com'
   ||!class_exists('Locale'))return false;
  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($profile)&&($profile['site_uuid']??'')===self::SITE_UUID&&self::language()!=='';
 }
 public static function active():bool {
  return self::site_active() && function_exists('getFluentFormCountryList');
 }
 public static function localize_woo_countries($countries){
  if(!self::site_active()||!is_array($countries)||count($countries)<190)return $countries;
  $lang=self::language();
  foreach($countries as $iso=>$original){
   if(!is_string($iso)||!preg_match('/^[A-Z]{2}$/D',$iso))continue;
   $localized=self::label($iso,$lang);
   if($localized!=='')$countries[$iso]=$localized;
  }
  return $countries;
 }
 public static function manual_country():string {
  if(!class_exists('Travel_Booking_Traveller_Nationality_Switcher'))return '';
  $code=Travel_Booking_Traveller_Nationality_Switcher::selected_manual();
  return is_string($code)&&preg_match('/^[A-Z]{2}$/D',$code)?$code:'';
 }
 public static function label(string $code,string $lang):string {
  if(!preg_match('/^[A-Z]{2}$/D',$code)||!isset(self::LOCALES[$lang]))return '';
  $name=Locale::getDisplayRegion('und_'.$code,self::LOCALES[$lang]);
  if(!is_string($name)||$name===''||$name===$code)return '';
  return $name;
 }
 public static function captions(string $lang):array {
  $names=[
   'en'=>['Passport issuing country','Select passport country'],
   'ar'=>['دولة إصدار جواز السفر','اختر دولة إصدار جواز السفر'],
   'fr'=>['Pays de délivrance du passeport','Sélectionnez le pays du passeport'],
   'de'=>['Ausstellungsland des Reisepasses','Reisepassland auswählen'],
   'es'=>['País emisor del pasaporte','Selecciona el país del pasaporte'],
   'it'=>['Paese di rilascio del passaporto','Seleziona il paese del passaporto'],
  ];
  return $names[$lang]??$names['en'];
 }
 private static function is_nationality_field(array $data):bool {
  $name=strtolower((string)($data['attributes']['name']??''));
  $label=strtolower((string)($data['settings']['label']??''));
  // WPML may translate the field label before this hook. The legacy select is
  // accepted only by the separately verified 195-option SHA256 contract.
  if($name==='dropdown_1')return true;
  return in_array($name,['country-list','travel_booking_passport_country','nationality','passport_country'],true);
 }
 public static function localize_legacy_select($data,$form) {
  if(!self::active()||!is_array($data)||($data['element']??'')!=='select'
   ||!self::is_nationality_field($data))return $data;
  $opts=$data['settings']['advanced_options']??[];
  if(!is_array($opts)||count($opts)!==195)return $data;
  $values=array_map(static fn($row)=>(string)($row['value']??''),$opts);
  if(!hash_equals(self::LEGACY_VALUES_SHA,hash('sha256',wp_json_encode($values))))return $data;
  $codes=explode(' ',self::LEGACY_CODES);
  if(count($codes)!==count($opts))return $data;
  $language=self::language();
  foreach($opts as $i=>$option){
   if(!is_array($option))return $data;
   $localized=self::label($codes[$i],$language);
   if($localized==='')return $data;
   $opts[$i]['label']=$localized;
   // The original 'Afghan'/'Egyptian'/etc. values MUST stay untouched for legacy submissions.
  }
  $data['settings']['advanced_options']=$opts;
  self::$has_nationality_field=true;
  $manual=self::manual_country();
  $index=array_search($manual,$codes,true);
  if($manual!==''&&$index!==false&&empty($data['attributes']['value']))
   $data['attributes']['value']=(string)$opts[$index]['value'];
  [$title,$placeholder]=self::captions($language);
  $data['settings']['label']=$title;
  $data['settings']['placeholder']=$placeholder;
  return $data;
 }
 public static function tag_legacy_country_options($html,$data,$form){
  if(!self::active()||!is_string($html)||!is_array($data)
   ||($data['element']??'')!=='select'||!self::is_nationality_field($data))return $html;
  $opts=$data['settings']['advanced_options']??[];
  if(!is_array($opts)||count($opts)!==195)return $html;
  $values=array_map(static fn($row)=>(string)($row['value']??''),$opts);
  if(!hash_equals(self::LEGACY_VALUES_SHA,hash('sha256',wp_json_encode($values))))return $html;
  $map=array_combine($values,explode(' ',self::LEGACY_CODES));
  if(!is_array($map))return $html;
  return preg_replace_callback('/<option\\b([^>]*\\bvalue=(["\\x27])([^"\\x27]*)\\2[^>]*)>/i',
   static function($m)use($map){
    $iso=$map[html_entity_decode($m[3],ENT_QUOTES,'UTF-8')]??'';
    return preg_match('/^[A-Z]{2}$/D',$iso)
     ? '<option'.$m[1].' data-passport-iso="'.esc_attr($iso).'">'
     : $m[0];
   },$html);
 }
 public static function print_sync_script():void {
  if(!self::active()||!self::$has_nationality_field)return;
  echo <<<'JS'
<script>
(function(){
 'use strict';
 var syncing=false;
 var names=['country-list','travel_booking_passport_country','dropdown_1'];
 document.addEventListener('change',function(event){
  var el=event.target;
  if(syncing||!(el instanceof HTMLSelectElement)||!names.includes(el.name)||!el.closest('form.frm-fluent-form'))return;
  el.dataset.mad4bNationalityEdited='1';
 },true);
 document.addEventListener('visitor-preferences:saved',function(event){
  var iso=String(event.detail&&event.detail.passport||'').toUpperCase();
  if(!/^[A-Z]{2}$/.test(iso))return;
  document.querySelectorAll('form.frm-fluent-form select').forEach(function(el){
   if(!names.includes(el.name)||el.dataset.mad4bNationalityEdited==='1')return;
   var found=Array.from(el.options).find(function(o){
    return el.name==='dropdown_1'?o.dataset.passportIso===iso:o.value===iso;
   });
   if(!found||found.value===el.value)return;
   syncing=true;
   var widget=window.jQuery && el.classList.contains('ff_has_multi_select')
     ? window.jQuery(el).data('choicesjs') : null;
   if(widget&&typeof widget.removeActiveItems==='function'
     &&typeof widget.setChoiceByValue==='function'){
    widget.removeActiveItems(found.value);
    widget.setChoiceByValue(found.value);
   }
   el.value=found.value;
   el.dispatchEvent(new Event('change',{bubbles:true}));
   syncing=false;
  });
 });
})();
</script>
JS;
 }
 public static function localize_country_field($data,$form){
  if(!self::active()||!is_array($data)||($data['element']??'')!=='select_country'
   ||!self::is_nationality_field($data))return $data;
  self::$has_nationality_field=true;
  [$title,$placeholder]=self::captions(self::language());
  $data['settings']['label']=$title;
  $data['attributes']['placeholder']=$placeholder;
  $manual=self::manual_country();
  if($manual!==''&&empty($data['attributes']['value']))
   $data['attributes']['value']=$manual;
  return $data;
 }
 public static function localize_country_markup($html,$data,$form) {
  if(!self::active()||!is_string($html)||!is_array($data)||!self::is_nationality_field($data))return $html;
  $language=self::language();
  return preg_replace_callback(
   '/(<option\b[^>]*\bvalue=(["\x27])([A-Z]{2})\2[^>]*>)(.*?)(<\/option>)/is',
   static function($m)use($language){
    $label=self::label(strtoupper($m[3]),$language);
    return $label!==''?$m[1].esc_html($label).$m[5]:$m[0];
   },$html);
 }
 private static function fields_include_passport(array $fields):bool {
  foreach($fields as $field){
   if(!is_array($field))continue;
   $name=strtolower((string)($field['attributes']['name']??''));
   $label=strtolower((string)($field['settings']['label']??''));
   if($name==='dropdown_1'){
    $opts=$field['settings']['advanced_options']??[];
    if(is_array($opts)&&count($opts)===195){
     $values=array_map(static fn($row)=>(string)($row['value']??''),$opts);
     if(hash_equals(self::LEGACY_VALUES_SHA,hash('sha256',wp_json_encode($values))))return true;
    }
   }
   // These canonical field names remain stable even when WPML translates labels.
   if(in_array($name,['country-list','travel_booking_passport_country','nationality','passport_country'],true))return true;
   foreach(($field['columns']??[]) as $column)
    if(is_array($column)&&self::fields_include_passport((array)($column['fields']??[])))return true;
   if(!empty($field['fields'])&&self::fields_include_passport((array)$field['fields']))return true;
  }
  return false;
 }
 public static function ensure_booking_field($form){
  if(!self::active()||!is_object($form)||!isset($form->id,$form->fields['fields'])||!is_array($form->fields['fields']))return $form;
  $id=(int)$form->id;
  $title=strtolower((string)($form->title??''));
  // Allow listed legacy booking/inquiry forms, plus new tour or booking forms that are created later.
  if(!in_array($id,[3,5,6,7,8,11],true)&&!preg_match('/tour|booking|reservation/i',$title))return $form;
  if(self::fields_include_passport($form->fields['fields']))return $form;
  [$label,$placeholder]=self::captions(self::language());
  $manual=self::manual_country();
  $field=[
   'element'=>'select_country',
   'attributes'=>['name'=>'travel_booking_passport_country','id'=>'','class'=>'',
    'value'=>$manual,'placeholder'=>$placeholder],
   'settings'=>['label'=>$label,'label_placement'=>'','help_message'=>'',
    'country_list'=>['active_list'=>'all','visible_list'=>[],'hidden_list'=>[]],
    'enable_select_2'=>'yes',
    'validation_rules'=>['required'=>['value'=>false,'message'=>'','global'=>true]]],
  ];
  $fields=$form->fields['fields'];
  $index=count($fields);
  foreach($fields as $i=>$entry)if(($entry['element']??'')==='custom_submit_button'){$index=$i;break;}
  array_splice($fields,$index,0,[$field]);
  $form->fields['fields']=$fields;
  self::$has_nationality_field=true;
  return $form;
 }
 public static function boot():void {
  add_filter('fluentform/rendering_form',[__CLASS__,'ensure_booking_field'],9999,1);
  add_filter('fluentform/rendering_field_data_select',[__CLASS__,'localize_legacy_select'],9999,2);
  add_filter('fluentform/rendering_field_html_select',[__CLASS__,'tag_legacy_country_options'],9999,3);
  add_action('wp_footer',[__CLASS__,'print_sync_script'],55);
  add_filter('fluentform/rendering_field_data_select_country',[__CLASS__,'localize_country_field'],9999,2);
  add_filter('fluentform/rendering_field_html_select_country',[__CLASS__,'localize_country_markup'],9999,3);
 }
}
add_filter('woocommerce_countries',['MAD4B_Fluent_Passport_Locale_V1','localize_woo_countries'],900,1);
add_action('plugins_loaded',['MAD4B_Fluent_Passport_Locale_V1','boot'],100);
