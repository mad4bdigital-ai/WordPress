<?php
/**
 * MAD4B Rate naming and tour accommodation note (Staging v1).
 * Read-only presentation; financial rate IDs and quote rules remain authoritative.
 */
defined('ABSPATH') || exit;

final class MAD4B_Rate_Naming_And_Note_V1 {
 const GLOBAL_MODE = 'mad4b_rate_naming_policy_v1';
 const TOUR_MODE = 'mad4b_tour_rate_naming_mode_v1';
 const RATE_MODE = '_mad4b_rate_naming_mode_v1';
 const RATE_CUSTOM = '_mad4b_rate_custom_label_v1';
 const NOTE_DEFAULT = 'mad4b_accommodation_note_default_v1';
 const NOTE_ENABLED = 'mad4b_accommodation_note_enabled_v1';
 const NOTE_MODE = 'mad4b_accommodation_note_mode_v1';
 const NOTE_CUSTOM = 'mad4b_accommodation_note_text_v1';
 const LANGUAGES = ['en','ar','fr','de','es','it'];

 static function lang(string $requested=''):string {
  $lang=$requested!==''?$requested:(string)apply_filters('wpml_current_language',get_locale());
  $lang=strtolower(substr(str_replace('_','-',$lang),0,2));
  return in_array($lang,self::LANGUAGES,true)?$lang:'en';
 }
 static function words(string $lang):array {
  $a=[
   'en'=>['rate'=>'Rate','option'=>'Option','dates'=>'Valid','note'=>'Note: Similar accommodation is available in case of fully booked.'],
   'de'=>['rate'=>'Tarif','option'=>'Option','dates'=>'Gültig','note'=>'Hinweis: Bei vollständiger Ausbuchung steht eine vergleichbare Unterkunft zur Verfügung.'],
   'fr'=>['rate'=>'Tarif','option'=>'Option','dates'=>'Valable','note'=>'Remarque : un hébergement similaire peut être proposé en cas de disponibilité épuisée.'],
   'es'=>['rate'=>'Tarifa','option'=>'Opción','dates'=>'Válida','note'=>'Nota: Si el alojamiento está completo, se podrá ofrecer uno similar.'],
   'it'=>['rate'=>'Tariffa','option'=>'Opzione','dates'=>'Valida','note'=>'Nota: in caso di disponibilità esaurita, può essere offerta una sistemazione simile.'],
   'ar'=>['rate'=>'سعر','option'=>'خيار','dates'=>'صالح','note'=>'ملاحظة: قد يتوفر مكان إقامة مماثل في حال اكتمال الحجز.']
  ];
  return $a[$lang]??$a['en'];
 }
 static function meaningful(string $s):bool {
  $s=trim(wp_strip_all_tags($s));
  return $s!=='' && !ctype_digit($s) && !preg_match('/^(rate\s*#?\d+|draft generated rate\b)/iu',$s);
 }
 static function tour(int $id):int {
  if($id>0 && get_post_type($id)==='tours-and-activities')return $id;
  $q=absint(get_queried_object_id());
  if($q>0 && get_post_type($q)==='tours-and-activities')return $q;
  $p=absint(get_the_ID());
  return get_post_type($p)==='tours-and-activities'?$p:0;
 }
 static function tour_mode(int $tour):string {
  if($tour>0){
   $local=(string)get_post_meta($tour,self::TOUR_MODE,true);
   if(in_array($local,['auto','custom','source'],true))return $local;
  }
  $global=(string)get_option(self::GLOBAL_MODE,'auto');
  return in_array($global,['auto','source'],true)?$global:'auto';
 }
 static function name(int $rate,int $tour=0,string $language=''):string {
  if($rate<1 || get_post_type($rate)!=='tour-rates')return '';
  $lang=self::lang($language);
  $words=self::words($lang);
  $parent=absint(get_post_meta($rate,'related_tour_id',true));
  $tour=self::tour($tour) ?: $parent;
  $rateMode=(string)get_post_meta($rate,self::RATE_MODE,true);
  $mode=in_array($rateMode,['auto','custom','source'],true)?$rateMode:self::tour_mode($tour);
  $custom=trim((string)get_post_meta($rate,self::RATE_CUSTOM,true));
  if($mode==='custom' && $custom!==''){
   $translated=apply_filters('wpml_translate_single_string',$custom,'mad4b-rate-names','rate-'.$rate,$lang);
   return sanitize_text_field(is_string($translated)&&trim($translated)!==''?$translated:$custom);
  }
  $rawName=trim((string)get_post_meta($rate,'rate_title',true));
  if(!self::meaningful($rawName))$rawName=trim((string)get_the_title($rate));
  if($mode==='source' && self::meaningful($rawName))return sanitize_text_field($rawName);
  $package=absint(get_post_meta($rate,'related_package_term_id',true));
  $level='';
  if($package){
   $localized=absint(apply_filters('wpml_object_id',$package,'package_levels',false,$lang));
   $term=get_term($localized?:$package,'package_levels');
   if($term instanceof WP_Term && !is_wp_error($term))$level=trim(wp_strip_all_tags($term->name));
  }
  $parts=[];
  if($level!=='')$parts[]=$level;
  if(self::meaningful($rawName))$parts[]=wp_strip_all_tags($rawName);
  $from=get_post_meta($rate,'tour_rate_start_date',true);
  $to=get_post_meta($rate,'tour_rate_end_date',true);
  if(is_numeric($from)&&is_numeric($to)&&(int)$from>0&&(int)$to>0){
   $dateFormat=in_array($lang,['de','it','fr','es','ar'],true)?'d.m.Y':'Y-m-d';
   $parts[]=gmdate($dateFormat,(int)$from).' – '.gmdate($dateFormat,(int)$to);
  }
  // Keep choices unambiguous when source titles are numeric or blank.
  if(!self::meaningful($rawName))$parts[]=$words['rate'].' #'.$rate;
  if(!$parts)$parts[]=($words['rate'].' #'.$rate);
  return sanitize_text_field(implode(' · ',array_unique($parts)));
 }
 static function note(int $tour=0,string $language=''):string {
  $tour=self::tour($tour);
  if(!$tour)return '';
  $lang=self::lang($language);
  $mode=(string)get_post_meta($tour,self::NOTE_MODE,true);
  if($mode==='hide')return '';
  if($mode==='custom'){
   $local=trim((string)get_post_meta($tour,self::NOTE_CUSTOM,true));
   if($local!=='')return wp_strip_all_tags($local);
  }
  $options=get_option('look-and-feel',[]);
  $options=is_array($options)?$options:[];
  if(($options[self::NOTE_ENABLED]??'yes')==='no')return '';
  $default=self::words('en')['note'];
  $value=trim((string)($options[self::NOTE_DEFAULT]??''));
  if($value==='')$value=$default;
  $translated=apply_filters('wpml_translate_single_string',$value,'mad4b-tour-notes',self::NOTE_DEFAULT,$lang);
  if(is_string($translated)&&trim($translated)!==''&&($lang==='en'||$translated!==$value))
   return wp_strip_all_tags($translated);
  if($value===$default)return self::words($lang)['note'];
  return wp_strip_all_tags($value);
 }
 static function note_html($attributes=[]):string {
  $id=is_array($attributes)?absint($attributes['tour_id']??0):0;
  $text=self::note($id);
  return $text===''?'':'<p class="tour-accommodation-note mad4b-dynamic-accommodation-note"><strong>'.esc_html($text).'</strong></p>';
 }
 static function option_fields($fields,$page) {
  if(!is_array($fields)||!is_object($page)||($page->slug??'')!=='look-and-feel')return $fields;
  $known=[];
  foreach($fields as $f)if(is_array($f)&&isset($f['name']))$known[$f['name']]=true;
  $add=[
   ['name'=>'mad4b_tour_accommodation_tab_v1','title'=>'Tour accommodation note','object_type'=>'tab','tab_layout'=>'horizontal'],
   ['name'=>self::NOTE_ENABLED,'title'=>'Display accommodation availability note','type'=>'select','object_type'=>'field','options_source'=>'manual','options'=>[['key'=>'yes','value'=>'Yes (default)'],['key'=>'no','value'=>'No']],'default_val'=>'yes'],
   ['name'=>self::NOTE_DEFAULT,'title'=>'Default accommodation note (WPML)','type'=>'textarea','object_type'=>'field','description'=>'Use WPML String Translation: domain mad4b-tour-notes. Each Tour can override or hide this note.']
  ];
  foreach($add as $field)if(!isset($known[$field['name']]))$fields[]=$field;
  return $fields;
 }
 static function register_tour_fields():void {
  if(!is_admin() || !current_user_can('manage_options'))return;
  $boxes=get_option('jet_engine_meta_boxes',[]);
  if(!is_array($boxes)||!isset($boxes['mad4b_tour_layout_page_options']))return;
  $box=&$boxes['mad4b_tour_layout_page_options'];
  if(!is_array($box))return;
  $fields=(array)($box['meta_fields']??[]);
  $known=array_fill_keys(array_filter(array_map(static fn($r)=>(string)($r['name']??''),$fields)),true);
  $field=static fn($name,$title,$type,$other=[])=>array_merge(['object_type'=>'field','name'=>$name,'title'=>$title,'type'=>$type,'width'=>'100%','options_source'=>'manual','options'=>[],'repeater-fields'=>[],'isNested'=>false],$other);
  $add=[
   $field(self::TOUR_MODE,'Rate naming policy','select',['options'=>[['key'=>'inherit','value'=>'Inherit Rate Generator global policy'],['key'=>'auto','value'=>'Generate a localized label'],['key'=>'custom','value'=>'Use custom Rate labels when provided'],['key'=>'source','value'=>'Source Rate title (localized fallback)']],'default_val'=>'inherit']),
   $field(self::NOTE_MODE,'Accommodation note policy','select',['options'=>[['key'=>'inherit','value'=>'Use Look & Feel default'],['key'=>'custom','value'=>'Use this Tour note'],['key'=>'hide','value'=>'Hide note']],'default_val'=>'inherit']),
   $field(self::NOTE_CUSTOM,'Tour-specific accommodation note (WPML)','textarea',['description'=>'Translate independently for each Tour locale; used only when Custom is selected.'])
  ];
  $changed=false;
  foreach($add as $f)if(!isset($known[$f['name']])){$fields[]=$f;$changed=true;}
  if($changed){$box['meta_fields']=$fields;update_option('jet_engine_meta_boxes',$boxes,false);}
 }
 static function rate_editor_register():void {
  add_meta_box('mad4b_rate_naming_v1','MAD4B · Rate naming',
   [__CLASS__,'rate_editor_box'],'tour-rates','side','default');
 }
 static function rate_editor_box($post):void {
  if(!$post instanceof WP_Post)return;
  $mode=(string)get_post_meta($post->ID,self::RATE_MODE,true);
  if(!in_array($mode,['inherit','auto','custom','source'],true))$mode='inherit';
  $custom=(string)get_post_meta($post->ID,self::RATE_CUSTOM,true);
  wp_nonce_field('mad4b_rate_naming_'.$post->ID,'mad4b_rate_naming_nonce');
  $options=[
   'inherit'=>'Inherit Tour / Global policy',
   'auto'=>'Auto localized name',
   'custom'=>'Custom localized name',
   'source'=>'Original title when descriptive, else Auto'
  ];
  echo '<p><label for="mad4b_rate_name_mode">Display name policy</label><br><select id="mad4b_rate_name_mode" name="mad4b_rate_name_mode" style="width:100%">';
  foreach($options as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($mode,$key,false).'>'.esc_html($label).'</option>';
  echo '</select></p><p><label for="mad4b_rate_custom_name">Custom Rate name (current WPML language)</label><br>';
  echo '<input id="mad4b_rate_custom_name" type="text" maxlength="160" style="width:100%" name="mad4b_rate_custom_name" value="'.esc_attr($custom).'"></p>';
  echo '<p class="description">Used only in Custom mode; editing the name never changes pricing, Rate ID or translated Tour ownership.</p>';
  echo '<p><strong>Current label:</strong> '.esc_html(self::name((int)$post->ID)).'</p>';
 }
 static function rate_editor_save(int $id):void {
  if(wp_is_post_revision($id)||wp_is_post_autosave($id)||
     !current_user_can('edit_post',$id))return;
  $nonce=(string)($_POST['mad4b_rate_naming_nonce']??'');
  if($nonce===''||!wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),
    'mad4b_rate_naming_'.$id))return;
  $mode=sanitize_key((string)wp_unslash($_POST['mad4b_rate_name_mode']??'inherit'));
  if(!in_array($mode,['inherit','auto','custom','source'],true))return;
  $custom=mb_substr(sanitize_text_field((string)wp_unslash($_POST['mad4b_rate_custom_name']??'')),0,160);
  if($mode==='custom' && $custom==='')return;
  if($mode==='inherit')delete_post_meta($id,self::RATE_MODE);
  else update_post_meta($id,self::RATE_MODE,$mode);
  if($custom!==''){
   update_post_meta($id,self::RATE_CUSTOM,$custom);
   do_action('wpml_register_single_string','mad4b-rate-names','rate-'.$id,$custom);
  }
 }
 static function boot():void {
  add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'option_fields'],106,2);
  add_action('admin_init',[__CLASS__,'register_tour_fields'],111);
  add_shortcode('mad4b_tour_accommodation_note',[__CLASS__,'note_html']);
  add_action('add_meta_boxes_tour-rates',[__CLASS__,'rate_editor_register']);
  add_action('save_post_tour-rates',[__CLASS__,'rate_editor_save'],30);
  add_action('init',static function(){
   $o=get_option('look-and-feel',[]);
   $value=is_array($o)?trim((string)($o[self::NOTE_DEFAULT]??'')):'';
   if($value==='')$value=self::words('en')['note'];
   do_action('wpml_register_single_string','mad4b-tour-notes',self::NOTE_DEFAULT,$value);
  },70);
  add_action('wp_enqueue_scripts',static function():void {
   if(!is_singular('tours-and-activities'))return;
   $lang=self::lang();
   if($lang==='en' || !wp_script_is('shared-presentation-fluent-quote-v1','enqueued'))return;
   $labels=[
    'de'=>['submit'=>'Anfrage senden','prefix'=>'Ergebnisse: ','suffix'=>'','years'=>'Jahre','heading'=>'Wichtige Links zu – ','pricing'=>'Preise','tips'=>'Tipps'],
    'fr'=>['submit'=>'Envoyer la demande','prefix'=>'Résultats : ','suffix'=>'','years'=>'ans','heading'=>'Liens utiles pour – ','pricing'=>'Tarifs','tips'=>'Conseils'],
    'es'=>['submit'=>'Enviar solicitud','prefix'=>'Resultados: ','suffix'=>'','years'=>'años','heading'=>'Enlaces útiles de – ','pricing'=>'Precios','tips'=>'Consejos'],
    'it'=>['submit'=>'Invia richiesta','prefix'=>'Risultati: ','suffix'=>'','years'=>'anni','heading'=>'Link utili per – ','pricing'=>'Prezzi','tips'=>'Consigli'],
    'ar'=>['submit'=>'إرسال الطلب','prefix'=>'النتائج: ','suffix'=>'','years'=>'سنة','heading'=>'روابط مهمة – ','pricing'=>'الأسعار','tips'=>'نصائح']
   ][$lang]??null;
   if(!$labels)return;
   $js=<<<'JS'
(function(){
 const t=__MAD4B_RATE_UI_LABELS__;
 const forms=()=>document.querySelectorAll('form.fluent_form_6');
 const localized=new WeakSet();
 function update(){
  forms().forEach(form=>{
   form.querySelectorAll('button.ff-btn-submit').forEach(b=>{
    if(b.textContent.trim()==='Submit'){b.textContent=t.submit;if(b.getAttribute('aria-label')==='Submit')b.setAttribute('aria-label',t.submit);}
   });
   form.querySelectorAll('label,.ff-el-input--label').forEach(label=>{
    label.childNodes.forEach(n=>{
     if(n.nodeType===3 && /\byears?\b/i.test(n.nodeValue))n.nodeValue=n.nodeValue.replace(/\byears?\b/gi,t.years);
    });
   });
   if(!localized.has(form)){
    localized.add(form);
    new MutationObserver(update).observe(form,{subtree:true,childList:true,characterData:true});
   }
  });
  document.querySelectorAll('[data-id="11726ba"] .elementor-heading-title').forEach(el=>{
   const old='Important Links of - ';
   if(el.textContent.startsWith(old))el.textContent=t.heading+el.textContent.slice(old.length);
  });
  document.querySelectorAll('.jet-dropbar__button-text').forEach(el=>{
   if(el.textContent.trim()==='Pricing')el.textContent=t.pricing;
  });
  if(t.tips==='Tipps')document.querySelectorAll('.elementor-icon-list-text').forEach(el=>{
   if(el.textContent.trim()==='Kippen')el.textContent=t.tips;
  });
  document.querySelectorAll('[data-id="69dd135"] .jet-listing-dynamic-field__content').forEach(node=>{
   if(!localized.has(node)){
    localized.add(node);
    new MutationObserver(update).observe(node,{subtree:true,childList:true,characterData:true});
   }
   if(!/^\s*Showing\s+/i.test(node.textContent))return;
   node.childNodes.forEach(n=>{
    if(n.nodeType!==3)return;
    if(/^\s*Showing\s*$/i.test(n.nodeValue))n.nodeValue=t.prefix;
    else if(/^\s*Results?\s*$/i.test(n.nodeValue))n.nodeValue=t.suffix;
   });
  });
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',update,{once:true});
 else update();
 window.addEventListener('load',update,{once:true});
 document.addEventListener('fluentform_rendering_complete',update);
})();
JS;
   wp_add_inline_script('shared-presentation-fluent-quote-v1',
    str_replace('__MAD4B_RATE_UI_LABELS__',wp_json_encode($labels,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$js),'after');
  },150);
  add_action('elementor/dynamic_tags/register','mad4b_register_tour_note_elementor_tag_v1',74);
 }
}
function mad4b_register_tour_note_elementor_tag_v1($manager):void {
 if(!class_exists('Elementor\Core\DynamicTags\Data_Tag')||
    !class_exists('Elementor\Modules\DynamicTags\Module'))return;
 if(!class_exists('MAD4B_Tour_Accommodation_Note_Elementor_Tag')){
  class MAD4B_Tour_Accommodation_Note_Elementor_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
   public function get_name(){return 'mad4b-tour-accommodation-note';}
   public function get_title(){return 'MAD4B · Tour Accommodation Note';}
   public function get_group(){return 'mad4b_tour_content';}
   public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   public function get_value(array $options=[]){return MAD4B_Rate_Naming_And_Note_V1::note();}
  }
 }
 $manager->register_group('mad4b_tour_content',['title'=>'MAD4B Tour Content']);
 $manager->register(new \MAD4B_Tour_Accommodation_Note_Elementor_Tag());
}
MAD4B_Rate_Naming_And_Note_V1::boot();

