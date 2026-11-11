<?php
/** Plugin Name: MAD4B Contact Us – Dynamic Elementor Fields
 * Description: Company-scoped Contact fields and safe Fluent Forms shortcode for Elementor Theme Builder.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Contact_Page_Dynamic {
 const CONTRACT='mad4b.contact-page-dynamic.v1';
 public static function current_lang():string { $v=apply_filters('wpml_current_language',null);return in_array($v,['en','ar','fr','de','es','it'],true)?$v:'en'; }
 public static function labels():array {
  $all=[
  'en'=>['address'=>'Office address','phone'=>'Call us','email'=>'Email us','whatsapp'=>'WhatsApp','hours'=>'Business hours','map'=>'Find us on the map','missing_form'=>'The contact form is not configured.'],
  'es'=>['address'=>'Dirección de la oficina','phone'=>'Llámanos','email'=>'Envíanos un correo','whatsapp'=>'WhatsApp','hours'=>'Horario de atención','map'=>'Ver en el mapa','missing_form'=>'El formulario de contacto no está configurado.'],
  'fr'=>['address'=>'Adresse du bureau','phone'=>'Appelez-nous','email'=>'Écrivez-nous','whatsapp'=>'WhatsApp','hours'=>'Horaires d’ouverture','map'=>'Voir sur la carte','missing_form'=>'Le formulaire de contact n’est pas configuré.'],
  'de'=>['address'=>'Büroadresse','phone'=>'Rufen Sie uns an','email'=>'Schreiben Sie uns','whatsapp'=>'WhatsApp','hours'=>'Öffnungszeiten','map'=>'Auf der Karte anzeigen','missing_form'=>'Das Kontaktformular ist nicht konfiguriert.'],
  'it'=>['address'=>'Indirizzo dell’ufficio','phone'=>'Chiamaci','email'=>'Scrivici','whatsapp'=>'WhatsApp','hours'=>'Orari di apertura','map'=>'Visualizza sulla mappa','missing_form'=>'Il modulo di contatto non è configurato.'],
  'ar'=>['address'=>'عنوان المكتب','phone'=>'اتصل بنا','email'=>'راسلنا','whatsapp'=>'واتساب','hours'=>'ساعات العمل','map'=>'عرض على الخريطة','missing_form'=>'نموذج التواصل غير مُعدّ.']];
  return $all[self::current_lang()];
 }
 public static function options():array { return (array)get_option('look-and-feel',[]); }
 public static function source():array { return (array)get_option('contact-and-help-info',[]); }
 private static function safe_external(string $value):string { $url=esc_url_raw($value,['https','http','mailto','tel']);return is_string($url)?$url:''; }
 private static function detail_icon(string $name):string {
  $paths=[
   'address'=>'<path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
   'phone'=>'<path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.8 19.8 0 0 1 3 5.18 2 2 0 0 1 5 3h3a2 2 0 0 1 2 1.7l.45 2.83a2 2 0 0 1-.57 1.73L8.1 11.1a16 16 0 0 0 4.8 4.8l1.84-1.78a2 2 0 0 1 1.73-.57l2.83.45A2 2 0 0 1 22 16.92Z"/>',
   'email'=>'<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
   'whatsapp'=>'<path d="M21 11.7a8.9 8.9 0 0 1-9 8.8c-1.5 0-3-.3-4.2-1L3 21l1.5-4.8A8.9 8.9 0 1 1 21 11.7Z"/><path d="M9.2 9a5 5 0 0 0 5.8 5.8l1-.9"/>',
   'hours'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
   'map'=>'<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/><path d="M9 3v15M15 6v15"/>',
  ];
  return '<span class="mad4b-contact-detail-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" focusable="false">'.($paths[$name]??'').'</svg></span>';
 }

 public static function field($attrs=[]):string {
  $a=shortcode_atts(['field'=>''],(array)$attrs,'mad4b_contact_field');$name=(string)$a['field'];$allowed=['address','phone','email','whatsapp','hours','map'];if(!in_array($name,$allowed,true))return '';
  $source=self::source();$options=self::options();$value='';$href='';
  switch($name){
   case 'address':$value=trim((string)($source['site_location_address']??''));$href=self::safe_external((string)($source['site_location_address_link']??''));break;
   case 'phone':$value=trim((string)($source['site_whatsapp_num']??''));$href=self::safe_external((string)($source['site_wa_tel_link']??''));break;
   case 'email':$value=sanitize_email((string)($source['site_email']??''));$href=$value?'mailto:'.$value:'';break;
   case 'whatsapp':$value=trim((string)($source['site_whatsapp_num']??''));$href=self::safe_external((string)($source['site_wa_tel_link']??''));break;
   case 'hours':$value=trim((string)($options['contact_page_office_hours']??''));break;
   case 'map':$href=self::safe_external((string)($source['site_location_address_link']??''));$value=$href?'link':'';break;
  }
  if($value==='')return '';
  $title=self::labels()[$name];
  $overrideKey=['address'=>'contact_page_address_heading','email'=>'contact_page_email_heading',
     'phone'=>'contact_page_phone_heading','whatsapp'=>'contact_page_whatsapp_heading','map'=>'contact_page_map_heading'][$name]??'';
  if($overrideKey!==''){
    $custom=trim((string)($options[$overrideKey]??''));
    if($custom!==''){
      $translated=(string)apply_filters('wpml_translate_single_string',$custom,'MAD4B Contact Us',$overrideKey,self::current_lang());
      $original=['address'=>'Office address','email'=>'Email us','phone'=>'Call us','whatsapp'=>'WhatsApp','map'=>'Find us on the map'][$name]??'';
      if(self::current_lang()==='en'||$translated!==$original)$title=$translated;
    }
  }
  $body=$name==='map'?esc_html($title):nl2br(esc_html($value));
  $body=$href?'<a href="'.esc_url($href).'"'.($name==='map'?' target="_blank" rel="noopener noreferrer"':'').'>'.$body.'</a>':$body;
  return '<div class="mad4b-contact-detail mad4b-contact-detail--'.esc_attr($name).'" data-contact-field="'.esc_attr($name).'">'.self::detail_icon($name).'<div class="mad4b-contact-detail-label">'.esc_html($title).'</div><div class="mad4b-contact-detail-value">'.$body.'</div></div>';
 }
 public static function form():string {
  $o=self::options();$raw=trim((string)($o['contact_page_fluent_shortcode']??''));
  if(!preg_match('/^\[fluentform\s+id=["\']?([1-9][0-9]{0,6})["\']?\s*\]$/i',$raw,$m))return current_user_can('manage_options')?'<p class="mad4b-contact-form-warning">'.esc_html(self::labels()['missing_form']).'</p>':'';
  global $wpdb;$id=(int)$m[1];$tbl=$wpdb->prefix.'fluentform_forms';$row=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$tbl} WHERE id=%d",$id),ARRAY_A);
  if(!$row||($row['status']??'')!=='published')return current_user_can('manage_options')?'<p class="mad4b-contact-form-warning">'.esc_html(self::labels()['missing_form']).'</p>':'';
  return '<div class="mad4b-contact-fluent-form" data-contact-form-id="'.esc_attr((string)$id).'">'.do_shortcode('[fluentform id="'.$id.'"]').'</div>';
 }
 public static function text($attrs=[]):string {
  $key=(string)(shortcode_atts(['key'=>''],(array)$attrs)['key']??'');
  $allowed=['contact_page_heading','contact_page_intro','contact_page_form_heading','contact_page_info_heading'];
  if(!in_array($key,$allowed,true))return '';
  $o=self::options();$raw=(string)($o[$key]??'');
  if(function_exists('icl_t'))$raw=apply_filters('wpml_translate_single_string',$raw,'MAD4B Contact Us',$key);
  return esc_html($raw);
 }
 public static function start(){ add_shortcode('mad4b_contact_field',[__CLASS__,'field']);add_shortcode('mad4b_contact_form',[__CLASS__,'form']);add_shortcode('mad4b_contact_text',[__CLASS__,'text']);
  add_action('wp_enqueue_scripts',static function(){
   if(!is_page())return;
   $id=get_queried_object_id();if(!$id||get_post_meta($id,'_mad4b_contact_page_v1',true)!=='yes')return;
   wp_register_style('mad4b-contact-page-v1',false,[], '1.0.0');wp_enqueue_style('mad4b-contact-page-v1');
   wp_add_inline_style('mad4b-contact-page-v1','.mad4b-contact-page{max-width:1180px;margin:auto;padding:clamp(28px,5vw,72px) 18px}.mad4b-contact-hero{margin-bottom:34px}.mad4b-contact-columns{gap:clamp(18px,3vw,38px)}.mad4b-contact-column{min-width:0;flex:1}.mad4b-contact-detail{border-bottom:1px solid rgba(128,128,128,.24);padding:13px 0;overflow-wrap:anywhere}.mad4b-contact-detail-label{font-size:.9rem;font-weight:600;opacity:.75;margin-bottom:5px}.mad4b-contact-detail-value{font-size:1.05rem}.mad4b-contact-detail-value a{color:var(--e-global-color-primary,inherit);text-decoration:underline}.mad4b-contact-fluent-form .ff-el-form-control{border-radius:var(--mad4b-contact-radius,9px)}.mad4b-contact-fluent-form button.ff-btn-submit{background:var(--e-global-color-primary,#185c73);color:white;border-radius:var(--mad4b-contact-radius,9px)}@media(max-width:767px){.mad4b-contact-columns{flex-direction:column!important}.mad4b-contact-page{padding:32px 16px}}');
  });
 }
}
add_action('init',[MAD4B_Contact_Page_Dynamic::class,'start'],15);
