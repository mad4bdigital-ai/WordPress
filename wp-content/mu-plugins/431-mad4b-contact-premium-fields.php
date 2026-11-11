<?php
/**
 * Plugin Name: MAD4B Contact Premium – JetEngine Dynamic Presentation
 * Description: Company-local Elementor Contact hero media, supporting artwork and WPML-ready content from Look and Feel.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Contact_Premium_Fields {
 const GROUP='MAD4B Contact Us';
 private static function defaults():array {
  return [
   'contact_page_hero_eyebrow'=>['en'=>'CONTACT {company}','es'=>'CONTACTA CON {company}','fr'=>'CONTACTEZ {company}','de'=>'KONTAKT ZU {company}','it'=>'CONTATTA {company}','ar'=>'تواصل مع {company}'],
   'contact_page_info_kicker'=>['en'=>'WE ARE HERE FOR YOU','es'=>'ESTAMOS AQUÍ PARA TI','fr'=>'NOUS SOMMES À VOTRE ÉCOUTE','de'=>'WIR SIND FÜR SIE DA','it'=>'SIAMO QUI PER TE','ar'=>'نحن هنا لمساعدتك'],
   'contact_page_form_kicker'=>['en'=>'LET US PLAN YOUR JOURNEY','es'=>'PLANIFIQUEMOS TU VIAJE','fr'=>'ORGANISONS VOTRE VOYAGE','de'=>'PLANEN WIR IHRE REISE','it'=>'ORGANIZZIAMO IL TUO VIAGGIO','ar'=>'لنخطط لرحلتك'],
   'contact_page_benefit_1_title'=>['en'=>'Quick response','es'=>'Respuesta rápida','fr'=>'Réponse rapide','de'=>'Schnelle Antwort','it'=>'Risposta rapida','ar'=>'استجابة سريعة'],
   'contact_page_benefit_1_desc'=>['en'=>'We reply as soon as possible','es'=>'Respondemos lo antes posible','fr'=>'Nous répondons dès que possible','de'=>'Wir antworten so schnell wie möglich','it'=>'Rispondiamo il prima possibile','ar'=>'نرد في أقرب وقت ممكن'],
   'contact_page_benefit_2_title'=>['en'=>'Expert travel support','es'=>'Asesoramiento experto','fr'=>'Conseils de spécialistes','de'=>'Beratung durch Reiseexperten','it'=>'Assistenza di esperti','ar'=>'دعم من خبراء السفر'],
   'contact_page_benefit_2_desc'=>['en'=>'Talk to Egypt travel specialists','es'=>'Habla con especialistas en Egipto','fr'=>'Échangez avec des experts de l’Égypte','de'=>'Sprechen Sie mit Ägypten-Spezialisten','it'=>'Parla con esperti dell’Egitto','ar'=>'تحدث مع متخصصين في رحلات مصر'],
   'contact_page_benefit_3_title'=>['en'=>'Personalized travel plans','es'=>'Viajes a tu medida','fr'=>'Voyages sur mesure','de'=>'Individuelle Reisepläne','it'=>'Viaggi su misura','ar'=>'برامج سفر مخصصة'],
   'contact_page_benefit_3_desc'=>['en'=>'Experiences shaped around you','es'=>'Experiencias diseñadas para ti','fr'=>'Des expériences pensées pour vous','de'=>'Erlebnisse nach Ihren Wünschen','it'=>'Esperienze pensate per te','ar'=>'تجارب مصممة من أجلك'],
   'contact_page_benefit_4_title'=>['en'=>'Secure inquiry','es'=>'Consulta segura','fr'=>'Demande sécurisée','de'=>'Sichere Anfrage','it'=>'Richiesta sicura','ar'=>'استفسار آمن'],
   'contact_page_benefit_4_desc'=>['en'=>'Your message reaches our support team','es'=>'Tu mensaje llega a nuestro equipo','fr'=>'Votre message arrive à notre équipe','de'=>'Ihre Nachricht erreicht unser Team','it'=>'Il messaggio arriva al nostro team','ar'=>'رسالتك تصل إلى فريق الدعم'],
  ];
 }
 private static function lang():string {
  $lang=(string)apply_filters('wpml_current_language',null);
  return in_array($lang,['en','es','fr','de','it','ar'],true)?$lang:'en';
 }
 private static function options():array {return (array)get_option('look-and-feel',[]);}
 private static function company():string {
  if(class_exists('MAD4B_SCP_Company_Communication',false)){
   $brand=MAD4B_SCP_Company_Communication::brandIdentity();
   if(!empty($brand['valid']) && !empty($brand['brand_name']))return sanitize_text_field($brand['brand_name']);
  }
  return sanitize_text_field((string)get_bloginfo('name'));
 }
 public static function value(string $key):string {
  $defaults=self::defaults();if(!isset($defaults[$key]))return '';
  $lang=self::lang();$base=$defaults[$key]['en'];$raw=trim((string)(self::options()[$key]??''));
  $value=$raw===''?$defaults[$key][$lang]:$raw;
  if($raw!==''){
   $translated=(string)apply_filters('wpml_translate_single_string',$raw,self::GROUP,$key,$lang);
   if($lang==='en')$value=$translated!==''?$translated:$raw;
   elseif($translated!=='' && $translated!==$raw)$value=$translated;
   elseif($raw===$base)$value=$defaults[$key][$lang];
  }
  return str_replace('{company}',self::company(),$value);
 }
 public static function copy($atts=[]):string {
  $a=shortcode_atts(['key'=>'','tag'=>'span'],(array)$atts,'mad4b_contact_copy');
  $key=(string)$a['key'];if(!isset(self::defaults()[$key]))return '';
  $tag=in_array($a['tag'],['span','p','div'],true)?$a['tag']:'span';
  $v=self::value($key);
  return $v===''?'':'<'.$tag.' class="mad4b-contact-copy mad4b-contact-copy--'.esc_attr($key).'">'.esc_html($v).'</'.$tag.'>';
 }
 private static function media_attachment_id(string $name):int {
  $keys=['hero'=>'contact_page_hero_image','info'=>'contact_page_info_image'];
  if(!isset($keys[$name]))return 0;
  $stored=self::options()[$keys[$name]]??'';
  if(is_array($stored))$stored=$stored['id']??$stored['url']??'';
  $id=is_numeric($stored)?absint($stored):0;
  if(!$id && is_string($stored) && $stored!==''){
   $url=esc_url_raw($stored,['https']);
   $host=wp_parse_url($url,PHP_URL_HOST);
   if(!$host || strtolower($host)!==strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST)))return 0;
   $id=attachment_url_to_postid($url);
  }
  if(!$id || get_post_type($id)!=='attachment' || !wp_attachment_is_image($id))return 0;
  return $id;
 }
 public static function media($atts=[]):string {
  $a=shortcode_atts(['slot'=>'hero'],(array)$atts,'mad4b_contact_media');
  $slot=(string)$a['slot'];if(!in_array($slot,['hero','info'],true))return '';
  $id=self::media_attachment_id($slot);if(!$id)return '';
  $opts=self::options();$focus=(string)($opts['contact_page_hero_focus']??'center');
  $positions=['center'=>'center 50%','higher'=>'center 30%','lower'=>'center 70%','left'=>'left center','right'=>'right center'];
  $style=$slot==='hero'?' style="object-position:'.esc_attr($positions[$focus]??$positions['center']).'"':'';
  $img=wp_get_attachment_image($id,'full',false,[
   'class'=>'mad4b-contact-'.$slot.'-photo',
   'alt'=>$slot==='hero'?sanitize_text_field((string)(get_post_meta($id,'_wp_attachment_image_alt',true)?:get_the_title($id))):'',
   'loading'=>$slot==='hero'?'eager':'lazy',
   'decoding'=>'async',
   'fetchpriority'=>$slot==='hero'?'high':'auto',
   'sizes'=>$slot==='hero'?'100vw':'(max-width:767px) 100vw, 560px',
  ]);
  if($img==='')return '';
  if($style!=='')$img=preg_replace('/(<img\s)/','$1'.trim($style).' ',$img,1);
  return '<div class="mad4b-contact-'.$slot.'-media" data-wp-attachment-id="'.(int)$id.'">'.$img.'</div>';
 }
 private static function icon(int $index):string {
  $paths=[
   1=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
   2=>'<path d="M4 19v-2a4 4 0 0 1 4-4h2"/><path d="M16 13a4 4 0 0 1 4 4v2"/><circle cx="9" cy="7" r="3"/><circle cx="17" cy="7" r="3"/>',
   3=>'<path d="m3 19 6-13 5 9 3-6 4 10H3Z"/><path d="M9 6l2 3"/>',
   4=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
  ];
  return '<svg viewBox="0 0 24 24" width="27" height="27" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$paths[$index].'</svg>';
 }
 public static function benefit($atts=[]):string {
  $a=shortcode_atts(['index'=>'1'],(array)$atts,'mad4b_contact_benefit');
  $i=absint($a['index']);if($i<1||$i>4)return '';
  $heading=self::value('contact_page_benefit_'.$i.'_title');$desc=self::value('contact_page_benefit_'.$i.'_desc');
  if($heading===''&&$desc==='')return '';
  return '<div class="mad4b-contact-benefit mad4b-contact-benefit--'.$i.'"><span class="mad4b-contact-benefit-icon">'.self::icon($i).'</span><div class="mad4b-contact-benefit-copy"><strong>'.esc_html($heading).'</strong><span>'.esc_html($desc).'</span></div></div>';
 }
 public static function register_strings():void {
  if(!has_action('wpml_register_single_string') && !has_filter('wpml_translate_single_string'))return;
  $o=self::options();foreach(self::defaults() as $key=>$translations){
   $value=trim((string)($o[$key]??$translations['en']));
   if($value!=='')do_action('wpml_register_single_string',self::GROUP,$key,$value);
  }
 }
 public static function init():void {
  add_shortcode('mad4b_contact_copy',[__CLASS__,'copy']);
  add_shortcode('mad4b_contact_media',[__CLASS__,'media']);
  add_shortcode('mad4b_contact_benefit',[__CLASS__,'benefit']);
  if(is_admin())add_action('admin_init',[__CLASS__,'register_strings'],30);
 }
}
add_action('init',[MAD4B_Contact_Premium_Fields::class,'init'],30);
