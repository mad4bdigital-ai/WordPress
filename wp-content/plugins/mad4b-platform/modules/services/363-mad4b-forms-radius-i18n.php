<?php
/**
 * Plugin Name: MAD4B Native Form Control Radius & Pickup I18n
 * Description: One 7px Fluent Forms control radius; language-safe labels for booking pickup, without enabling hidden WPML languages.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Forms_Radius_I18n {
 const CONTRACT='mad4b.form-controls-i18n.v1';
 const RADIUS='7px';
 public static function language(){
   $wpml=(string)apply_filters('wpml_current_language','');
   if(in_array($wpml,['ar','en','fr','de','it','es'],true))return $wpml;
   $native=strtolower((string)get_locale());
   $candidate=substr($native,0,2);
   return in_array($candidate,['ar','en','fr','de','it','es'],true)?$candidate:'en';
 }
 public static function phrases($lang){
   $all=[
    'ar'=>[
      'mad4b_pickup_current_country'=>['موقعك الحالي — وليس الجنسية','أين تتواجد حاليًا؟','حدد موقعك الحالي لمعرفة متطلبات الاستلام من الفندق. لا نستخدم الجنسية أو جواز السفر.',['EG'=>'أنا موجود في مصر','OTHER'=>'أنا خارج مصر']],
      'mad4b_pickup_booking_status'=>['الاستلام من الفندق — هل حجزت فندقك؟','اختر حالة الحجز','يمكنك تزويدنا ببيانات الفندق لاحقًا.',['booked'=>'نعم، حجزت فندقي','not_booked'=>'لم أحجز الفندق بعد']],
      'mad4b_pickup_hotel_name'=>['اسم فندق الاستلام','اسم الفندق الذي سيتم الاستلام منه','',''],
      'mad4b_pickup_hotel_city'=>['مدينة الفندق أو منطقته','مثال: الأقصر، مرسى علم','',''],
      'mad4b_pickup_hotel_address'=>['عنوان الفندق أو رابط خرائط Google (اختياري)','الشارع أو المنطقة أو رابط الموقع','',''],
    ],
    'fr'=>[
      'mad4b_pickup_current_country'=>['Votre localisation actuelle — pas votre nationalité','Où vous trouvez-vous actuellement ?','Votre lieu de séjour actuel détermine les champs de prise en charge, pas votre passeport.',['EG'=>'Je suis actuellement en Égypte','OTHER'=>'Je suis en dehors de l’Égypte']],
      'mad4b_pickup_booking_status'=>['Prise en charge à l’hôtel — avez-vous réservé ?','Statut de réservation de l’hôtel','Vous pourrez communiquer les détails de l’hôtel ultérieurement.',['booked'=>'Oui, mon hôtel est réservé','not_booked'=>'Pas encore réservé']],
      'mad4b_pickup_hotel_name'=>['Nom de l’hôtel','Nom de votre hôtel de prise en charge','',''],
      'mad4b_pickup_hotel_city'=>['Ville ou quartier de l’hôtel','Ex. : Louxor, Marsa Alam','',''],
      'mad4b_pickup_hotel_address'=>['Adresse de l’hôtel ou lien Google Maps (facultatif)','Adresse ou lien vers la carte','',''],
    ],
    'de'=>[
      'mad4b_pickup_current_country'=>['Ihr aktueller Standort — nicht Ihre Staatsangehörigkeit','Wo befinden Sie sich derzeit?','Für die Hotelabholung zählt Ihr aktueller Standort, nicht Ihr Reisepass.',['EG'=>'Ich bin derzeit in Ägypten','OTHER'=>'Ich befinde mich außerhalb Ägyptens']],
      'mad4b_pickup_booking_status'=>['Hotelabholung — ist Ihr Hotel gebucht?','Buchungsstatus des Hotels','Sie können die Hoteldaten später ergänzen.',['booked'=>'Ja, mein Hotel ist gebucht','not_booked'=>'Hotel noch nicht gebucht']],
      'mad4b_pickup_hotel_name'=>['Hotelname','Name des Abholhotels','',''],
      'mad4b_pickup_hotel_city'=>['Stadt oder Stadtteil des Hotels','z. B. Luxor, Marsa Alam','',''],
      'mad4b_pickup_hotel_address'=>['Hoteladresse oder Google-Maps-Link (optional)','Adresse oder Kartenlink','',''],
    ],
    'it'=>[
      'mad4b_pickup_current_country'=>['La tua posizione attuale — non la nazionalità','Dove ti trovi attualmente?','Per il prelievo in hotel conta la posizione attuale, non il passaporto.',['EG'=>'Mi trovo attualmente in Egitto','OTHER'=>'Mi trovo fuori dall’Egitto']],
      'mad4b_pickup_booking_status'=>['Prelievo in hotel — hai prenotato?','Stato della prenotazione dell’hotel','Puoi comunicare i dettagli dell’hotel in seguito.',['booked'=>'Sì, ho prenotato l’hotel','not_booked'=>'Hotel non ancora prenotato']],
      'mad4b_pickup_hotel_name'=>['Nome dell’hotel','Nome dell’hotel per il prelievo','',''],
      'mad4b_pickup_hotel_city'=>['Città o zona dell’hotel','Es.: Luxor, Marsa Alam','',''],
      'mad4b_pickup_hotel_address'=>['Indirizzo dell’hotel o link Google Maps (facoltativo)','Indirizzo o link alla mappa','',''],
    ],
    'es'=>[
      'mad4b_pickup_current_country'=>['Tu ubicación actual, no tu nacionalidad','¿Dónde te encuentras actualmente?','La recogida en el hotel depende de tu ubicación, no de tu pasaporte.',['EG'=>'Estoy actualmente en Egipto','OTHER'=>'Estoy fuera de Egipto']],
      'mad4b_pickup_booking_status'=>['Recogida en el hotel: ¿ya has reservado?','Estado de reserva del hotel','Puedes proporcionar los datos del hotel más adelante.',['booked'=>'Sí, ya reservé el hotel','not_booked'=>'Aún no he reservado']],
      'mad4b_pickup_hotel_name'=>['Nombre del hotel','Nombre del hotel para la recogida','',''],
      'mad4b_pickup_hotel_city'=>['Ciudad o zona del hotel','P. ej., Luxor, Marsa Alam','',''],
      'mad4b_pickup_hotel_address'=>['Dirección del hotel o enlace de Google Maps (opcional)','Dirección o enlace del mapa','',''],
    ]
   ];
   return $all[$lang]??[];
 }
 public static function legacy_phrases($lang) {
   $items=[
     'ar'=>[
       'tour_name_prefix'=>['اللقب','- اختر اللقب -',['Mr'=>'السيد','Mis'=>'السيدة']],
       'datetime'=>['تاريخ الوصول','تاريخ الوصول',[]],
       'datetime_2'=>['نهاية فترة الوصول المرنة','حتى تاريخ',[]],
       'tour_date_flexibility'=>['مرونة موعد الرحلة','',['true'=>'تاريخ مرن','false'=>'تاريخ ثابت']],
       'dropdownitenary'=>['برنامج الرحلة','- اختر برنامج الرحلة -',['4-Day Itinerary | Aswan / Luxor'=>'برنامج 4 أيام | أسوان / الأقصر','5-Day Itinerary | Luxor / Aswan'=>'برنامج 5 أيام | الأقصر / أسوان','8-Day Itinerary | Luxor / Luxor'=>'برنامج 8 أيام | الأقصر / الأقصر','8-Day Itinerary | Aswan/ Aswan'=>'برنامج 8 أيام | أسوان / أسوان']],
     ],
     'fr'=>[
       'tour_name_prefix'=>['Civilité','- Choisir la civilité -',['Mr'=>'M.','Mis'=>'Mme']],
       'datetime'=>['Date d’arrivée','Date d’arrivée',[]],
       'datetime_2'=>['Fin de la période flexible','Date limite',[]],
       'tour_date_flexibility'=>['Flexibilité des dates','',['true'=>'Date flexible','false'=>'Date fixe']],
       'dropdownitenary'=>['Itinéraire','- Choisir un itinéraire -',['4-Day Itinerary | Aswan / Luxor'=>'Circuit 4 jours | Assouan / Louxor','5-Day Itinerary | Luxor / Aswan'=>'Circuit 5 jours | Louxor / Assouan','8-Day Itinerary | Luxor / Luxor'=>'Circuit 8 jours | Louxor / Louxor','8-Day Itinerary | Aswan/ Aswan'=>'Circuit 8 jours | Assouan / Assouan']],
     ],
     'de'=>[
       'tour_name_prefix'=>['Anrede','- Anrede wählen -',['Mr'=>'Herr','Mis'=>'Frau']],
       'datetime'=>['Anreisedatum','Anreisedatum',[]],
       'datetime_2'=>['Ende des flexiblen Zeitraums','Bis zum Datum',[]],
       'tour_date_flexibility'=>['Flexibilität des Reisedatums','',['true'=>'Flexibles Datum','false'=>'Festes Datum']],
       'dropdownitenary'=>['Reiseroute','- Reiseroute wählen -',['4-Day Itinerary | Aswan / Luxor'=>'4-Tage-Reise | Assuan / Luxor','5-Day Itinerary | Luxor / Aswan'=>'5-Tage-Reise | Luxor / Assuan','8-Day Itinerary | Luxor / Luxor'=>'8-Tage-Reise | Luxor / Luxor','8-Day Itinerary | Aswan/ Aswan'=>'8-Tage-Reise | Assuan / Assuan']],
     ],
     'it'=>[
       'tour_name_prefix'=>['Titolo','- Scegli il titolo -',['Mr'=>'Sig.','Mis'=>'Sig.ra']],
       'datetime'=>['Data di arrivo','Data di arrivo',[]],
       'datetime_2'=>['Fine del periodo flessibile','Fino alla data',[]],
       'tour_date_flexibility'=>['Flessibilità delle date','',['true'=>'Data flessibile','false'=>'Data fissa']],
       'dropdownitenary'=>['Itinerario','- Scegli un itinerario -',['4-Day Itinerary | Aswan / Luxor'=>'Itinerario 4 giorni | Assuan / Luxor','5-Day Itinerary | Luxor / Aswan'=>'Itinerario 5 giorni | Luxor / Assuan','8-Day Itinerary | Luxor / Luxor'=>'Itinerario 8 giorni | Luxor / Luxor','8-Day Itinerary | Aswan/ Aswan'=>'Itinerario 8 giorni | Assuan / Assuan']],
     ],
     'es'=>[
       'tour_name_prefix'=>['Tratamiento','- Seleccionar tratamiento -',['Mr'=>'Sr.','Mis'=>'Sra.']],
       'datetime'=>['Fecha de llegada','Fecha de llegada',[]],
       'datetime_2'=>['Fin del período flexible','Hasta la fecha',[]],
       'tour_date_flexibility'=>['Flexibilidad de fechas','',['true'=>'Fecha flexible','false'=>'Fecha fija']],
       'dropdownitenary'=>['Itinerario','- Seleccionar itinerario -',['4-Day Itinerary | Aswan / Luxor'=>'Itinerario 4 días | Asuán / Luxor','5-Day Itinerary | Luxor / Aswan'=>'Itinerario 5 días | Luxor / Asuán','8-Day Itinerary | Luxor / Luxor'=>'Itinerario 8 días | Luxor / Luxor','8-Day Itinerary | Aswan/ Aswan'=>'Itinerario 8 días | Asuán / Asuán']],
     ],
   ];
   return $items[$lang]??[];
 }
 public static function render_field($field,$form) {
   if((int)($form->id??0)!==6 || !is_array($field))return $field;
   $name=(string)($field['attributes']['name']??'');
   $pickup_fields=[
      'mad4b_pickup_current_country','mad4b_pickup_booking_status',
      'mad4b_pickup_hotel_name','mad4b_pickup_hotel_city',
      'mad4b_pickup_hotel_address'];
   $language=self::language();
   if(!in_array($name,$pickup_fields,true)) {
     $fallback=self::legacy_phrases($language)[$name]??null;
     if(!$fallback){
       if($language==='en' && $name==='tour_name_prefix'){
         foreach($field['settings']['advanced_options'] as &$opt)
           if(($opt['value']??'')==='Mis')$opt['label']='Ms';
         unset($opt);
       }
       return $field;
     }
     [$label,$placeholder,$options]=$fallback;
     // Render-only fallback for WPML string keys renamed by Fluent Forms.
     // Never overwrite a non-English translation already supplied upstream.
     $english=[
       'tour_name_prefix'=>'Name Prefix','datetime'=>'Arrival Date',
       'datetime_2'=>'Arrival Flex to Date',
       'tour_date_flexibility'=>'tour_date_flexibility',
       'dropdownitenary'=>'Dropdown'
     ];
     if(($field['settings']['label']??'')!==($english[$name]??''))return $field;
     $field['settings']['label']=$label;
     if($placeholder!==''){
       if(($field['element']??'')==='select')$field['settings']['placeholder']=$placeholder;
       else {
         $field['attributes']['placeholder']=$placeholder;
         $field['settings']['placeholder']=$placeholder;
       }
     }
     if($options && isset($field['settings']['advanced_options'])){
       foreach($field['settings']['advanced_options'] as &$option){
         $value=(string)($option['value']??'');
         if(isset($options[$value]))$option['label']=$options[$value];
       }unset($option);
     }
     return $field;
   }
   $translations=self::phrases($language);
   if(isset($translations[$name])){
     [$label,$placeholder,$help,$options]=$translations[$name];
     $field['settings']['label']=$label;
     if($help!=='')$field['settings']['help_message']=$help;
     if(in_array($field['element']??'',['select'],true))$field['settings']['placeholder']=$placeholder;
     else $field['attributes']['placeholder']=$placeholder;
     if(is_array($options)&&isset($field['settings']['advanced_options'])){
        foreach($field['settings']['advanced_options'] as &$option){
          $value=(string)($option['value']??'');
          if(isset($options[$value]))$option['label']=$options[$value];
        }
        unset($option);
     }
   }
   // Keep labels visible for screen readers and for locale-specific wording.
   $field['settings']['label_placement']='top';
   if($language==='ar')$field['attributes']['dir']='rtl';
   return $field;
 }
 public static function validation($english,$code){
   $lang=self::language();
   $labels=[
     'location_required'=>[
       'ar'=>'اختر الدولة التي تتواجد فيها حاليًا، وليس جنسيتك.',
       'fr'=>'Indiquez votre pays de résidence actuel, pas votre nationalité.',
       'de'=>'Bitte geben Sie Ihr aktuelles Aufenthaltsland an, nicht Ihre Staatsangehörigkeit.',
       'it'=>'Indica il paese in cui ti trovi ora, non la tua nazionalità.',
       'es'=>'Indica tu país de ubicación actual, no tu nacionalidad.',
     ],
     'hotel_required'=>[
       'ar'=>'حدد حالة حجز الفندق، وأدخل اسمه ومدينته إذا كنت قد حجزته.',
       'fr'=>'Précisez si votre hôtel est réservé et, le cas échéant, son nom et sa ville.',
       'de'=>'Geben Sie den Hotelbuchungsstatus an und ggf. Hotelname und Stadt.',
       'it'=>'Indica se hai prenotato l’hotel e, in tal caso, nome e città.',
       'es'=>'Indica el estado de la reserva y, si está reservado, nombre y ciudad del hotel.',
     ]
   ];
   return $labels[$code][$lang]??$english;
 }
 public static function css(){
   if(is_admin())return;
   echo '<style id="mad4b-unified-fluent-controls-radius">
   :root{--mad4b-form-control-radius:7px}
   .fluentform .ff-el-form-control,
   .fluentform input.ff-el-form-control,
   .fluentform select.ff-el-form-control,
   .fluentform textarea.ff-el-form-control,
   .fluentform .iti__search-input,
   .fluentform .choices__inner,
   .fluentform .select2-selection,
   .fluentform .ff-stepper-btn {
     border-radius:var(--mad4b-form-control-radius)!important;
   }
   .fluentform input.ff-el-form-check-radio{border-radius:50%}
   #fluentform_6 .iti__selected-country,
   .fluentform .iti__selected-country{
     border-radius:var(--mad4b-form-control-radius) 0 0 var(--mad4b-form-control-radius)!important;
   }
   #fluentform_6 [dir="rtl"] .iti__selected-country,
   .fluentform [dir="rtl"] .iti__selected-country{
     border-radius:0 var(--mad4b-form-control-radius) var(--mad4b-form-control-radius) 0!important;
   }
   .fluentform .ff-el-form-control:focus-visible{
     outline-offset:2px;
   }
   </style>';
 }
 public static function boot(){
   add_action('wp_head',[__CLASS__,'css'],105);
   add_filter('fluentform/rendering_field_data_select',[__CLASS__,'render_field'],50,2);
   add_filter('fluentform/rendering_field_data_input_text',[__CLASS__,'render_field'],50,2);
   add_filter('fluentform/rendering_field_data_input_date',[__CLASS__,'render_field'],50,2);
   add_filter('fluentform/rendering_field_data_input_radio',[__CLASS__,'render_field'],50,2);
   add_filter('mad4b/booking_pickup/validation_message',[__CLASS__,'validation'],10,2);
 }
}
MAD4B_Forms_Radius_I18n::boot();
