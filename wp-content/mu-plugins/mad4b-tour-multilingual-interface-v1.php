<?php
/**
 * MAD4B multilingual presentation parity for dynamic Tour/JetEngine widgets.
 * This layer translates interface labels only, never stored query results,
 * Rate IDs, monetary values, or authored taxonomy relationships.
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Multilingual_Interface_V1 {
 const DOMAIN='mad4b-tour-interface';
 const LABELS=[
  'en'=>[
   'price_rates'=>'Tour prices','governorates'=>'Tour governorates','countries'=>'Tour countries',
   'important'=>'Important links for – ','results'=>'Results: ','tour_one'=>'Tour','tour_many'=>'Tours',
   'all_tours'=>'Explore all tours','tips'=>'Tips','featured'=>'Featured','occupancy_single'=>'Per person in Single','occupancy_double'=>'Per person in Double','occupancy_triple'=>'Per person in Triple','price_on_request'=>'Price on request','package_details'=>'Show %s package details','subscribe'=>'Subscribe','subscription_form'=>'Subscription Form','search'=>'Search ...','choose_package'=>'Select Package','choose_season'=>'Select Season','check_availability'=>'Check Availability'
  ],
  'ar'=>[
   'price_rates'=>'أسعار الرحلة','governorates'=>'محافظات الرحلة','countries'=>'دول الرحلة',
   'important'=>'روابط مهمة للرحلة – ','results'=>'النتائج: ','tour_one'=>'رحلة','tour_many'=>'رحلات',
   'all_tours'=>'استعرض جميع الرحلات','tips'=>'نصائح','featured'=>'مميزة','occupancy_single'=>'للشخص في غرفة فردية','occupancy_double'=>'للشخص في غرفة مزدوجة','occupancy_triple'=>'للشخص في غرفة ثلاثية','price_on_request'=>'السعر عند الطلب','package_details'=>'عرض تفاصيل باقة %s','subscribe'=>'اشترك','subscription_form'=>'نموذج الاشتراك','search'=>'بحث ...','choose_package'=>'اختر الباقة','choose_season'=>'اختر الموسم','check_availability'=>'تحقق من التوافر'
  ],
  'fr'=>[
   'price_rates'=>'Tarifs du circuit','governorates'=>'Gouvernorats visités','countries'=>'Pays visités',
   'important'=>'Liens utiles pour – ','results'=>'Résultats : ','tour_one'=>'circuit','tour_many'=>'circuits',
   'all_tours'=>'Voir tous les circuits','tips'=>'Conseils','featured'=>'En vedette','occupancy_single'=>'Par personne en chambre individuelle','occupancy_double'=>'Par personne en chambre double','occupancy_triple'=>'Par personne en chambre triple','price_on_request'=>'Prix sur demande','package_details'=>'Afficher les détails de la formule %s','subscribe'=>'S’abonner','subscription_form'=>'Formulaire d’abonnement','search'=>'Rechercher ...','choose_package'=>'Choisir la formule','choose_season'=>'Choisir la saison','check_availability'=>'Vérifier les disponibilités'
  ],
  'de'=>[
   'price_rates'=>'Reisepreise','governorates'=>'Besuchte Gouvernements','countries'=>'Bereiste Länder',
   'important'=>'Wichtige Links zu – ','results'=>'Ergebnisse: ','tour_one'=>'Reise','tour_many'=>'Reisen',
   'all_tours'=>'Alle Reisen ansehen','tips'=>'Tipps','featured'=>'Empfohlen','occupancy_single'=>'Pro Person im Einzelzimmer','occupancy_double'=>'Pro Person im Doppelzimmer','occupancy_triple'=>'Pro Person im Dreibettzimmer','price_on_request'=>'Preis auf Anfrage','package_details'=>'Paketdetails für %s anzeigen','subscribe'=>'Abonnieren','subscription_form'=>'Anmeldeformular','search'=>'Suchen ...','choose_package'=>'Paket wählen','choose_season'=>'Saison wählen','check_availability'=>'Verfügbarkeit prüfen'
  ],
  'es'=>[
   'price_rates'=>'Precios del viaje','governorates'=>'Gobernaciones visitadas','countries'=>'Países visitados',
   'important'=>'Enlaces útiles de – ','results'=>'Resultados: ','tour_one'=>'viaje','tour_many'=>'viajes',
   'all_tours'=>'Ver todos los viajes','tips'=>'Consejos','featured'=>'Destacado','occupancy_single'=>'Por persona en habitación individual','occupancy_double'=>'Por persona en habitación doble','occupancy_triple'=>'Por persona en habitación triple','price_on_request'=>'Precio a consultar','package_details'=>'Mostrar detalles del paquete %s','subscribe'=>'Suscribirse','subscription_form'=>'Formulario de suscripción','search'=>'Buscar ...','choose_package'=>'Seleccionar paquete','choose_season'=>'Seleccionar temporada','check_availability'=>'Comprobar disponibilidad'
  ],
  'it'=>[
   'price_rates'=>'Prezzi del viaggio','governorates'=>'Governatorati visitati','countries'=>'Paesi visitati',
   'important'=>'Link utili per – ','results'=>'Risultati: ','tour_one'=>'viaggio','tour_many'=>'viaggi',
   'all_tours'=>'Scopri tutti i viaggi','tips'=>'Consigli','featured'=>'In evidenza','occupancy_single'=>'A persona in camera singola','occupancy_double'=>'A persona in camera doppia','occupancy_triple'=>'A persona in camera tripla','price_on_request'=>'Prezzo su richiesta','package_details'=>'Mostra i dettagli del pacchetto %s','subscribe'=>'Iscriviti','subscription_form'=>'Modulo di iscrizione','search'=>'Cerca ...','choose_package'=>'Seleziona pacchetto','choose_season'=>'Seleziona stagione','check_availability'=>'Verifica disponibilità'
  ]
 ];
 static function lang():string {
  $code=(string)apply_filters('wpml_current_language','en');
  $code=strtolower(substr(str_replace('_','-',$code),0,2));
  return isset(self::LABELS[$code])?$code:'en';
 }
 static function label(string $key,?string $lang=null):string {
  $lang=$lang?:self::lang();
  $en=(string)(self::LABELS['en'][$key]??$key);
  $fallback=(string)(self::LABELS[$lang][$key]??$en);
  $translated=apply_filters('wpml_translate_single_string',$en,self::DOMAIN,$key,$lang);
  return $lang==='en'?$en:(is_string($translated)&&trim($translated)!==''&&$translated!==$en?$translated:$fallback);
 }
 static function register_strings():void {
  foreach(self::LABELS['en'] as $key=>$en)do_action('wpml_register_single_string',self::DOMAIN,$key,$en);
 }
 static function tour_count_word(int $n,string $lang):string {
  if($lang==='ar'){
   if($n===1)return 'رحلة';
   if($n===2)return 'رحلتان';
   if($n>=3&&$n<=10)return 'رحلات';
   return 'رحلة';
  }
  return self::label($n===1?'tour_one':'tour_many',$lang);
 }
 static function translate_count_markup(string $html,string $lang):string {
  if(!str_contains($html,'jet-engine-query-count'))return $html;
  // All values inside JetEngine's count spans are immutable in this filter.
  $html=preg_replace_callback('~Showing[[:space:]]*(<span\b[^>]*\bjet-engine-query-count\b[^>]*>[[:digit:]]+</span>)[[:space:]]*Results?~u',
   static fn($m)=>esc_html(self::label('results',$lang)).$m[1],$html);
  $html=preg_replace_callback('~(<span\b[^>]*\bjet-engine-query-count\b[^>]*>([0-9]+)</span>)[[:space:]]+Tours\b~u',
   static fn($m)=>$m[1].' '.esc_html(self::tour_count_word((int)$m[2],$lang)),$html);
  return $html;
 }
 static function render(string $html,$widget):string {
  if(!is_object($widget)||!method_exists($widget,'get_id'))return $html;
  $lang=self::lang();
  $id=(string)$widget->get_id();
  // Inherited demo content can contain links and branding from a different tenant.
  // Replace only this verified exploration widget when it contains foreign tenant assets.
  if($id==='1649bda' &&
     (str_contains($html,'staging.egypttourgates.com') ||
      str_contains($html,'All Royal Egypt'))){
   $url=(string)get_post_type_archive_link('tours-and-activities');
   $url=(string)apply_filters('wpml_permalink',$url,$lang);
   $host=wp_parse_url($url,PHP_URL_HOST);
   if($host!==wp_parse_url(home_url('/'),PHP_URL_HOST))return '';
   return '<div class="jet-listing jet-listing-dynamic-field display-inline">'.
    '<div class="jet-listing-dynamic-field__inline-wrap"><div class="jet-listing-dynamic-field__content">'.
    '<p class="mad4b-tour-exploration-local-fallback"><a href="'.esc_url($url).'">'.
     esc_html(self::label('all_tours',$lang)).'</a></p></div></div></div>';
  }
  if($id==='1db1ac0' && str_contains($html,'/attractions')){
   // The legacy /attractions route is a confirmed 404 in all tested locales.
   // Send the CTA to a real same-language Tour archive, and label it accordingly.
   $url=(string)get_post_type_archive_link('tours-and-activities');
   $url=(string)apply_filters('wpml_permalink',$url,$lang);
   if(wp_parse_url($url,PHP_URL_HOST)===wp_parse_url(home_url('/'),PHP_URL_HOST)){
    $html=str_replace('href="/attractions"','href="'.esc_url($url).'"',$html);
    $html=str_replace('View All Attractions',esc_html(self::label('all_tours',$lang)),$html);
   }
  }
  if($id==='1a32a3b' && str_contains($html,'fluent_form_2')){
   $html=str_replace('>Subscription Form</legend>','>'.esc_html(self::label('subscription_form',$lang)).'</legend>',$html);
   $html=str_replace('>Subscribe</button>','>'.esc_html(self::label('subscribe',$lang)).'</button>',$html);
   $html=str_replace('aria-label="Subscribe"','aria-label="'.esc_attr(self::label('subscribe',$lang)).'"',$html);
  }
  if(str_contains($html,'jet-ajax-search__field-wrapper'))
   $html=str_replace('>Search ...</label>','>'.esc_html(self::label('search',$lang)).'</label>',$html);
  // Localize the exact JetSmartFilters controls, not arbitrary user text.
  if(str_contains($html,'name="related_package_term_id"'))
   $html=str_replace('Select Package',esc_html(self::label('choose_package',$lang)),$html);
  if(str_contains($html,'name="season"'))
   $html=str_replace('Select Season',esc_html(self::label('choose_season',$lang)),$html);
  if(str_contains($html,'jet-date-range__submit-text'))
   $html=str_replace(['Check Avilability','Check Availability'],
    esc_html(self::label('check_availability',$lang)),$html);
  if($lang==='en')return $html;
  $occupancyIDs=[
    'occupancy_single'=>['4c96633','2846ad7'],
    'occupancy_double'=>['4a6eb34','7807023'],
    'occupancy_triple'=>['c260670','83e6f03']
  ];
  foreach($occupancyIDs as $key=>$ids){
   if(in_array($id,$ids,true)){
    $raw=self::LABELS['en'][$key];
    if(str_contains($html,'>'.$raw.'<'))
      $html=str_replace('>'.$raw.'<','>'.esc_html(self::label($key,$lang)).'<',$html);
    break;
   }
  }
  if(str_contains($html,'Price on request') &&
      (str_contains($html,'class="price-on-request"') || str_contains($html,'class="price-unavailable"')))
    $html=str_replace('>Price on request<','>'.esc_html(self::label('price_on_request',$lang)).'<',$html);
  if($id==='49f37dc' && str_contains($html,' Price rates')){
   $html=preg_replace('~ Price rates(?=</h[1-6]>)~u',' '.esc_html(self::label('price_rates',$lang)),$html);
  }
  if($id==='73b95fc' && str_contains($html,' Tour Governorates')){
   $html=preg_replace('~ Tour Governorates(?=</h[1-6]>)~u',' '.esc_html(self::label('governorates',$lang)),$html);
  }
  if($id==='5b0eb85' && str_contains($html,' Tour Countries')){
   $html=preg_replace('~ Tour Countries(?=</h[1-6]>)~u',' '.esc_html(self::label('countries',$lang)),$html);
  }
  if($id==='11726ba' && str_contains($html,'Important Links of - ')){
   $html=str_replace('Important Links of - ',esc_html(self::label('important',$lang)),$html);
  }
  if(in_array($id,['1b772ff','e446b68'],true) && str_contains($html,'>Featured<'))
   $html=str_replace('>Featured<','>'.esc_html(self::label('featured',$lang)).'<',$html);
  if(str_contains($html,'jet-engine-query-count'))$html=self::translate_count_markup($html,$lang);
  if(str_contains($html,'>Explore all tours <'))
   $html=str_replace('>Explore all tours <','>'.esc_html(self::label('all_tours',$lang)).' <',$html);
  if($lang==='de'&&str_contains($html,'>Kippen<')&&$id==='f1b48e2')
   $html=str_replace('>Kippen<','>'.esc_html(self::label('tips',$lang)).'<',$html);
  return $html;
 }
 static function translate_price_text($translated,$original,$domain) {
  if(!in_array($domain,['woocommerce','pricing'],true) || (is_admin()&&!wp_doing_ajax()))return $translated;
  $lang=self::lang();
  if($lang==='en')return $translated;
  $labels=[
   'ar'=>['From'=>'يبدأ من','Upcoming from'=>'يبدأ من أقرب موعد','per person'=>'للشخص','per trip'=>'للرحلة','per day'=>'لليوم','per room'=>'للغرفة','per vehicle'=>'للمركبة','per cabin'=>'للكابينة','per table'=>'للطاولة'],
   'fr'=>['From'=>'À partir de','Upcoming from'=>'Prochain départ dès','per person'=>'par personne','per trip'=>'par voyage','per day'=>'par jour','per room'=>'par chambre','per vehicle'=>'par véhicule','per cabin'=>'par cabine','per table'=>'par table'],
   'de'=>['From'=>'Ab','Upcoming from'=>'Nächster Termin ab','per person'=>'pro Person','per trip'=>'pro Reise','per day'=>'pro Tag','per room'=>'pro Zimmer','per vehicle'=>'pro Fahrzeug','per cabin'=>'pro Kabine','per table'=>'pro Tisch'],
   'es'=>['From'=>'Desde','Upcoming from'=>'Próxima salida desde','per person'=>'por persona','per trip'=>'por viaje','per day'=>'por día','per room'=>'por habitación','per vehicle'=>'por vehículo','per cabin'=>'por camarote','per table'=>'por mesa'],
   'it'=>['From'=>'Da','Upcoming from'=>'Prossima partenza da','per person'=>'a persona','per trip'=>'per viaggio','per day'=>'al giorno','per room'=>'per camera','per vehicle'=>'per veicolo','per cabin'=>'per cabina','per table'=>'per tavolo']
  ];
  if($original==='Price on request')return self::label('price_on_request',$lang);
  if($original==='Show %s package details')return self::label('package_details',$lang);
  return $labels[$lang][$original] ?? $translated;
 }
 static function enqueue():void {
  wp_register_style('mad4b-tour-locale-layout-v1',false,[],'1.0.0');
  wp_enqueue_style('mad4b-tour-locale-layout-v1');
  wp_add_inline_style('mad4b-tour-locale-layout-v1',
   '.shared-price-amount,.etg-price-number,.etg-price-code,.tk-tour-rate-label{unicode-bidi:isolate}'.
   'html[dir="rtl"] .shared-price-amount,html[dir="rtl"] .etg-tour-price{unicode-bidi:isolate;direction:ltr;display:inline-block}'.
   'html[dir="rtl"] .tk-tour-rate-label{unicode-bidi:plaintext;line-height:1.7}'.
   'html[dir="rtl"] .jet-engine-query-count{unicode-bidi:isolate;display:inline-block;direction:ltr}'.
   'html[dir="rtl"] .tour-component-price{display:inline-flex;direction:rtl;align-items:baseline;justify-content:flex-start;flex-wrap:wrap;gap:0 .3rem;unicode-bidi:isolate}'.
   'html[dir="rtl"] .tour-component-price .woocommerce-Price-amount{direction:ltr;unicode-bidi:isolate;display:inline-block;white-space:nowrap}'.
   'html[dir="rtl"] .tour-component-price .price-label,html[dir="rtl"] .tour-component-price .price-unit{direction:rtl;unicode-bidi:isolate;white-space:nowrap}');
  if(is_front_page()){
   $script=<<<'JS'
(function(){
 const listingId='destination-featured-attraction-listing';
 const sectionId='.elementor-element-35c9e02';
 const featuredLabel=__MAD4B_FEATURED_LABEL__;
 function localizeBadges(){
  document.querySelectorAll('.elementor-element-1b772ff .jet-listing-dynamic-field__content,.elementor-element-e446b68 .jet-listing-dynamic-field__content')
   .forEach(n=>{if(n.textContent.trim()==='Featured')n.textContent=featuredLabel;});
 }
 function refresh(){
  localizeBadges();
  const listing=document.getElementById(listingId);
  if(!listing)return;
  const section=listing.closest(sectionId);
  if(!section)return;
  // The native listing stays the only source of truth: do not invent counts or tours.
  const loaded=listing.querySelector('.jet-listing-grid');
  if(!loaded)return;
  const hasItems=!!listing.querySelector('.jet-listing-grid__item[data-post-id],.jet-listing-grid__item[data-term-id],.jet-listing-grid__item.swiper-slide,.jet-listing-grid__item');
  if(hasItems){section.style.removeProperty('display');section.removeAttribute('aria-hidden');}
  else{section.style.setProperty('display','none','important');section.setAttribute('aria-hidden','true');}
 }
 const connect=()=>{
  const listing=document.getElementById(listingId);
  if(!listing)return;
  refresh();
  new MutationObserver(refresh).observe(listing,{childList:true,subtree:true});
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',connect,{once:true});
 else connect();
 window.addEventListener('load',refresh,{once:true});
})();
JS;
   $script=str_replace('__MAD4B_FEATURED_LABEL__',
     wp_json_encode(self::label('featured',self::lang()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$script);
   wp_register_script('mad4b-destination-nonempty-v1',false,[],'1.0.0',true);
   wp_enqueue_script('mad4b-destination-nonempty-v1');
   wp_add_inline_script('mad4b-destination-nonempty-v1',$script,'after');
  }
 }
 static function boot():void {
  add_action('init',[__CLASS__,'register_strings'],90);
  add_filter('elementor/widget/render_content',[__CLASS__,'render'],19,2);
  add_filter('gettext',[__CLASS__,'translate_price_text'],20,3);
  add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],170);
 }
}
MAD4B_Tour_Multilingual_Interface_V1::boot();

