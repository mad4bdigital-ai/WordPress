<?php
/**
 * Plugin Name: MAD4B Visitor Switcher Locale Overlay (ETG Staging)
 * Description: Label-only WPML compatibility for market, passport, and WCML currency switchers; preserves ISO values, native persistence, and currency authority.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging'
 || (string)wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return;
function mad4b_etg_switcher_locale_copy() {
 $lang=(string)apply_filters('wpml_current_language',null);
 $copy=[
 'en'=>['Country & passport','Country','Select country','Passport','Passport nationality','Select passport country','Auto · follows country','Manual','Use selected country','Search passport country','No countries found','Searching…','Unable to load countries. Try again.','Currency','Saving…','Saved','Could not save. Try again.'],
 'fr'=>['Pays et passeport','Pays','Choisir un pays','Passeport','Nationalité du passeport','Choisir le pays du passeport','Auto · selon le pays','Manuel','Utiliser le pays choisi','Rechercher un pays','Aucun pays trouvé','Recherche…','Impossible de charger les pays. Réessayez.','Devise','Enregistrement…','Enregistré','Enregistrement impossible. Réessayez.'],
 'de'=>['Land und Reisepass','Land','Land auswählen','Reisepass','Reisepassland','Reisepassland auswählen','Automatisch · nach Land','Manuell','Gewähltes Land verwenden','Reisepassland suchen','Keine Länder gefunden','Suche…','Länder konnten nicht geladen werden.','Währung','Speichern…','Gespeichert','Speichern fehlgeschlagen. Erneut versuchen.'],
 'es'=>['País y pasaporte','País','Elegir país','Pasaporte','Nacionalidad del pasaporte','Elegir país del pasaporte','Automático · según país','Manual','Usar país seleccionado','Buscar país del pasaporte','No se encontraron países','Buscando…','No se pudieron cargar los países.','Moneda','Guardando…','Guardado','No se pudo guardar. Inténtalo de nuevo.'],
 'it'=>['Paese e passaporto','Paese','Seleziona paese','Passaporto','Nazionalità del passaporto','Seleziona paese del passaporto','Automatico · segue il paese','Manuale','Usa il paese selezionato','Cerca paese del passaporto','Nessun paese trovato','Ricerca…','Impossibile caricare i paesi.','Valuta','Salvataggio…','Salvato','Salvataggio non riuscito. Riprova.'],
 'ar'=>['الدولة وجواز السفر','الدولة','اختر الدولة','جواز السفر','جنسية جواز السفر','اختر دولة جواز السفر','تلقائي حسب الدولة','يدوي','استخدم الدولة المختارة','ابحث عن دولة جواز السفر','لا توجد دول مطابقة','جارٍ البحث…','تعذر تحميل الدول. حاول مرة أخرى.','العملة','جارٍ الحفظ…','تم الحفظ','تعذر الحفظ. حاول مرة أخرى.']
 ];
 return $copy[$lang]??$copy['en'];
}
add_filter('do_shortcode_tag',static function($output,$tag){
 if(!is_string($output)||$output===''||!in_array($tag,[
 'header_market_preferences','mad4b_country_switcher',
 'travel_booking_nationality_switcher','pricing_header_switcher'],true))return $output;
 $t=mad4b_etg_switcher_locale_copy();
 if($tag==='header_market_preferences'){
  $output=str_replace(['Country &amp; passport','Country & passport'],esc_html($t[0]),$output);
  $output=str_replace('class="header-market-preferences__current">Country<',
   'class="header-market-preferences__current">'.esc_html($t[1]).'<',$output);
 }
 if($tag==='mad4b_country_switcher'){
  $output=str_replace('<span class="mad4b-switcher-label">Country</span>',
   '<span class="mad4b-switcher-label">'.esc_html($t[1]).'</span>',$output);
  $output=str_replace('<option value="">Select country</option>',
   '<option value="">'.esc_html($t[2]).'</option>',$output);
 }
 if($tag==='travel_booking_nationality_switcher'){
  $labels=['<span class="travel-booking-nationality-label">Passport</span>'=>$t[3],
    'aria-label="Passport nationality"'=>$t[4],
    '<option value="">Select passport country</option>'=>$t[5]];
  foreach($labels as $find=>$value){
   if(str_starts_with($find,'aria-label='))$output=str_replace($find,'aria-label="'.esc_attr($value).'"',$output);
   else $output=str_replace($find,substr($find,0,strpos($find,'>')+1).esc_html($value).substr($find,strpos($find,'</')),$output);
  }
  foreach(['data-mode-auto'=>[6,'Auto · follows country'],
    'data-mode-manual'=>[7,'Manual'],'data-reset-label'=>[8,'Use selected country'],
    'data-placeholder'=>[9,'Search passport country'],'data-empty'=>[10,'No countries found'],
    'data-loading'=>[11,'Searching…'],'data-error'=>[12,'Unable to load countries. Try again.']] as $attr=>$values){
    [$n,$original]=$values;
    $output=str_replace($attr.'="'.esc_attr($original).'"',$attr.'="'.esc_attr($t[$n]).'"',$output);
  }
 }
 if($tag==='pricing_header_switcher'){
  $output=str_replace('aria-label="Currency"','aria-label="'.esc_attr($t[13]).'"',$output);
  $output=str_replace('>Currency</label>','>'.esc_html($t[13]).'</label>',$output);
 }
 return $output;
},90,2);
// Localized statuses only: the original native preference controller remains the single owner.
add_action('wp_enqueue_scripts',static function(){
 $t=mad4b_etg_switcher_locale_copy();
 $map=['Saving…'=>$t[14],'Saved'=>$t[15],'Could not save. Try again.'=>$t[16]];
 $js='document.addEventListener("DOMContentLoaded",()=>{const map='.wp_json_encode($map,JSON_UNESCAPED_UNICODE).';document.querySelectorAll(".header-market-preferences__message").forEach(el=>{const ob=new MutationObserver(()=>{const v=el.textContent;if(Object.prototype.hasOwnProperty.call(map,v)&&v!==map[v])el.textContent=map[v];});ob.observe(el,{childList:true,characterData:true,subtree:true});});});';
 wp_add_inline_script('header-market-preferences-ui',$js,'after');
},100);
