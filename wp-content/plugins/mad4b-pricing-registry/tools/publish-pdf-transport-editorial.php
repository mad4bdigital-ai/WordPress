<?php
/**
 * Publish trusted Self Provider PDF Transportation as editorial/inquiry-only.
 * No source currency, selling price, availability, capacity, fleet claim,
 * booking activation, or photo-publication authorization is inferred.
 *
 * Environment:
 * MAD4B_PDF_PUBLISH_APPLY=1; MAD4B_PDF_PUBLISH_WAYFA_APPROVED=1 for Wayfa
 * MAD4B_PDF_PUBLISH_START=0..50; MAD4B_PDF_PUBLISH_SIZE=5 (source groups)
 * Rollback separate explicit mode MAD4B_PDF_PUBLISH_ROLLBACK=1.
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site=rtrim(home_url('/'),'/');
$wayfa=$site==='https://wayfatours.com';
if($site!=='https://tours.dev.mad4b.com'&&!$wayfa)
 WP_CLI::error('site_not_allowed');
if($wayfa && getenv('MAD4B_PDF_PUBLISH_WAYFA_APPROVED')!=='1')
 WP_CLI::error('wayfa_explicit_approval_required');
$apply=getenv('MAD4B_PDF_PUBLISH_APPLY')==='1';
$rollback=getenv('MAD4B_PDF_PUBLISH_ROLLBACK')==='1';
if($apply&&$rollback)WP_CLI::error('apply_and_rollback_conflict');
$start=filter_var(getenv('MAD4B_PDF_PUBLISH_START')?:0,
 FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>50]]);
$size=filter_var(getenv('MAD4B_PDF_PUBLISH_SIZE')?:5,
 FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10]]);
if($start===false||$size===false)WP_CLI::error('batch_range_invalid');
$source=require WP_PLUGIN_DIR.'/mad4b-pricing-registry/data/transport-pdf-2026-localized.php';
$routes=require WP_PLUGIN_DIR.'/mad4b-pricing-registry/data/transport-pdf-2026-route-evidence.php';
$sha='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
if(!is_array($source)||($source['source_sha256']??null)!==$sha ||
   count($source['rows']??[])!==51 ||
   ($routes['source_sha256']??null)!==$sha ||
   count($routes['entries']??[])!==51)
 WP_CLI::error('trusted_source_fixture_invalid');
$langs=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
sort($langs);$expected=$wayfa?['en','es']:['ar','de','en','es','fr','it'];
if($langs!==$expected)WP_CLI::error('active_language_matrix_changed');
$ids=get_posts(['post_type'=>'transportations','post_status'=>['draft','publish'],
 'posts_per_page'=>650,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256','meta_value'=>$sha]);
if(count($ids)!==51*count($langs))WP_CLI::error('transport_source_count_changed');
$byKey=[];$trids=[];$before=[];
foreach($ids as $id){
 $id=(int)$id;$post=get_post($id);
 $key=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
 $lang=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
 $details=apply_filters('wpml_post_language_details',null,$id);
 $trid=(int)apply_filters('wpml_element_trid',null,$id,'post_transportations');
 if(!isset($routes['entries'][$key])||!in_array($lang,$langs,true)||
  ($details['language_code']??'')!==$lang||!$trid||
  !in_array($post->post_status,['draft','publish'],true)||
  get_post_meta($id,'trans_use_in_tour_rate_generation',true)!=='1'||
  get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)!=='self'||
  get_post_meta($id,'_mad4b_transfer_pdf_2026_source_trust',true)!=='trusted_original_pdf'||
  (string)get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)!=='1'||
  (int)get_post_meta($id,'trans_mode',true)!==568||
  (string)get_post_meta($id,'_mad4b_pdf_route_schema_v1',true)!==
     'mad4b.transport-pdf-route-evidence.v1'||
  get_post_meta($id,'trans_available_status',true)!==''||
  get_post_meta($id,'trans_start_price',true)!==''||
  get_post_meta($id,'trans_type',true)!==''||
  get_post_thumbnail_id($id)!==0)
     WP_CLI::error('editorial_publish_guard_failed_'.$id);
 $byKey[$key][$lang][]=$id;
 $trids[$trid][$lang][]=$id;
 $before[$id]=[
    'source_key'=>$key,'language'=>$lang,'trid'=>$trid,
    'original_status'=>$post->post_status,
    'original_content'=>$post->post_content,
    'original_excerpt'=>$post->post_excerpt,
    'original_description'=>get_post_meta($id,'trans_description',true),
    'description_exists'=>metadata_exists('post',$id,'trans_description'),
    'robots'=>get_post_meta($id,'rank_math_robots',true),
    'robots_exists'=>metadata_exists('post',$id,'rank_math_robots'),
    'marker'=>get_post_meta($id,'_mad4b_pdf_editorial_publication_v1',true),
    'marker_exists'=>metadata_exists('post',$id,'_mad4b_pdf_editorial_publication_v1')
 ];
}
if(count($byKey)!==51||count($trids)!==51)
 WP_CLI::error('wpml_groups_or_source_keys_changed');
foreach([$byKey,$trids] as $groups)
 foreach($groups as $key=>$items){
    if(count($items)!==count($langs))WP_CLI::error('missing_translation_group_'.$key);
    foreach($langs as $lang)
       if(count($items[$lang]??[])!==1)
          WP_CLI::error('duplicate_translation_group_'.$key.'/'.$lang);
 }
$backupKey='mad4b_pdf_transport_editorial_publish_backup_20261120_v1';
$backup=get_option($backupKey,false);
if($rollback){
 if(!is_array($backup)||count($backup['before']??[])!==count($before))
   WP_CLI::error('rollback_backup_missing');
 // Check the entire cohort before changing any content. WPML may set
 // sibling post_status back to Draft as soon as one sibling is restored.
 foreach($backup['before'] as $id=>$v){
  if(!isset($before[$id])||$v['source_key']!==$before[$id]['source_key']||
     $v['language']!==$before[$id]['language']||
     $v['trid']!==$before[$id]['trid'])
       WP_CLI::error('rollback_identity_changed_'.$id);
  $p=get_post($id);
  $marker=(string)get_post_meta($id,'_mad4b_pdf_editorial_publication_v1',true);
  if(!$p||!in_array($p->post_status,['draft','publish'],true))
      WP_CLI::error('rollback_unexpected_status_'.$id);
  if($marker!=='' && $marker!=='mad4b.transport-pdf-editorial.v1')
      WP_CLI::error('rollback_foreign_editorial_marker_'.$id);
  if($marker==='' &&
    ($p->post_content!==$v['original_content'] ||
     $p->post_excerpt!==$v['original_excerpt'] ||
     get_post_meta($id,'trans_description',true)!==$v['original_description']))
     WP_CLI::error('rollback_external_edit_'.$id);
 }
 $restored=0;
 foreach($backup['before'] as $id=>$v){
  $marker=(string)get_post_meta($id,'_mad4b_pdf_editorial_publication_v1',true);
  $post=get_post($id);
  if($marker==='' && $post->post_status===$v['original_status'])
      continue;
  $result=wp_update_post([
    'ID'=>$id,'post_status'=>$v['original_status'],
    'post_content'=>$v['original_content'],
    'post_excerpt'=>$v['original_excerpt']
  ],true);
  if(is_wp_error($result))WP_CLI::error('rollback_post_write_failed_'.$id);
  foreach([
   'trans_description'=>['exists'=>'description_exists','value'=>'original_description'],
   'rank_math_robots'=>['exists'=>'robots_exists','value'=>'robots'],
   '_mad4b_pdf_editorial_publication_v1'=>['exists'=>'marker_exists','value'=>'marker']
  ] as $name=>$x){
    if($v[$x['exists']])update_post_meta($id,$name,$v[$x['value']]);
    else delete_post_meta($id,$name);
  }
  ++$restored;
 }
 foreach($backup['before'] as $id=>$v){
  $p=get_post($id);
  if(!$p || $p->post_status!==$v['original_status'] ||
    $p->post_content!==$v['original_content'] ||
    $p->post_excerpt!==$v['original_excerpt'] ||
    get_post_meta($id,'trans_description',true)!==$v['original_description'])
    WP_CLI::error('rollback_readback_conflict_'.$id);
 }
 WP_CLI::log(wp_json_encode(['status'=>'editorial_publication_rolled_back',
  'site'=>$site,'restored'=>$restored,'source_posts'=>count($ids)]));
 return;
}
$rowByKey=array_column($source['rows'],null,'source_key');
if(array_diff(array_keys($byKey),array_keys($rowByKey))||
 array_diff(array_keys($rowByKey),array_keys($byKey)))
 WP_CLI::error('source_row_keys_changed');
$labels=[
 'en'=>['area'=>'Service area','overview'=>'Transportation service for itinerary planning.',
    'route'=>'Route details','context'=>'The named areas and stops follow the source description. The exact pickup and drop-off point, driving sequence and vehicle availability require confirmation.',
    'price'=>'Pricing and booking','notice'=>'Price and booking are available only after the itinerary, vehicle, availability and final quote are confirmed. No online booking is available for this service.',
    'short'=>'Transportation service · details and price on request'],
 'ar'=>['area'=>'نطاق الخدمة','overview'=>'خدمة انتقالات لتخطيط برنامج الرحلة.',
    'route'=>'تفاصيل المسار','context'=>'المناطق والتوقفات المذكورة مأخوذة من وصف الخدمة الأصلي. يلزم تأكيد نقاط الاستلام والتوصيل وتسلسل الزيارات وتوافر المركبة.',
    'price'=>'السعر والحجز','notice'=>'يُحدد السعر وإمكانية الحجز بعد تأكيد البرنامج والمركبة والتوافر وعرض السعر النهائي. الحجز الإلكتروني لهذه الخدمة غير متاح حاليًا.',
    'short'=>'خدمة انتقالات · التفاصيل والسعر عند الطلب'],
 'es'=>['area'=>'Zona de servicio','overview'=>'Servicio de transporte para planificar su itinerario.',
    'route'=>'Detalles de la ruta','context'=>'Las zonas y paradas mencionadas proceden de la descripción original. Hay que confirmar los puntos de recogida y llegada, la secuencia y la disponibilidad del vehículo.',
    'price'=>'Precio y reserva','notice'=>'El precio y la reserva requieren confirmar el itinerario, el vehículo, la disponibilidad y una cotización final. No se admite reserva online para este servicio.',
    'short'=>'Servicio de transporte · detalles y precio a consultar'],
 'fr'=>['area'=>'Zone de service','overview'=>'Service de transport destiné à la préparation de votre itinéraire.',
    'route'=>'Détails de l’itinéraire','context'=>'Les zones et arrêts mentionnés proviennent de la description source. Les points précis de prise en charge et de dépose, le parcours et la disponibilité du véhicule restent à confirmer.',
    'price'=>'Tarif et réservation','notice'=>'Le tarif et la réservation nécessitent une confirmation de l’itinéraire, du véhicule, de sa disponibilité et du devis final. La réservation en ligne de ce service n’est pas disponible.',
    'short'=>'Transport · détails et tarif sur demande'],
 'de'=>['area'=>'Servicegebiet','overview'=>'Transportdienstleistung zur Planung Ihrer Reiseroute.',
    'route'=>'Routendetails','context'=>'Die genannten Orte und Stopps stammen aus der ursprünglichen Servicebeschreibung. Genaue Abhol- und Absetzorte, Reihenfolge sowie Fahrzeugverfügbarkeit müssen bestätigt werden.',
    'price'=>'Preis und Buchung','notice'=>'Preis und Buchbarkeit werden erst nach Bestätigung von Route, Fahrzeug, Verfügbarkeit und endgültigem Angebot festgelegt. Eine Onlinebuchung ist derzeit nicht möglich.',
    'short'=>'Transportservice · Details und Preis auf Anfrage'],
 'it'=>['area'=>'Area di servizio','overview'=>'Servizio di trasporto per la pianificazione dell’itinerario.',
    'route'=>'Dettagli dell’itinerario','context'=>'Le aree e le fermate indicate provengono dalla descrizione originale. I punti esatti di partenza e arrivo, la sequenza e la disponibilità del veicolo richiedono conferma.',
    'price'=>'Prezzo e prenotazione','notice'=>'Prezzo e prenotazione sono disponibili solo dopo la conferma dell’itinerario, del veicolo, della disponibilità e del preventivo finale. Non è possibile prenotare online questo servizio.',
    'short'=>'Servizio di trasporto · dettagli e prezzo su richiesta']
];
$cities=[
 'Cairo'=>['en'=>'Cairo','ar'=>'القاهرة','es'=>'El Cairo','fr'=>'Le Caire','de'=>'Kairo','it'=>'Il Cairo'],
 'Aswan'=>['en'=>'Aswan','ar'=>'أسوان','es'=>'Asuán','fr'=>'Assouan','de'=>'Assuan','it'=>'Assuan'],
 'Luxor'=>['en'=>'Luxor','ar'=>'الأقصر','es'=>'Lúxor','fr'=>'Louxor','de'=>'Luxor','it'=>'Luxor']
];
$keys=array_keys($rowByKey);
$chosen=array_slice($keys,$start,$size);
$pending=0;$already=0;
foreach($chosen as $key)
 foreach($langs as $lang){
    $id=$byKey[$key][$lang][0];
    if(get_post_status($id)==='publish')$already++;
    else $pending++;
 }
if(!$apply){
 WP_CLI::log(wp_json_encode(['status'=>'dry_run','site'=>$site,'source_groups'=>count($chosen),
 'start'=>$start,'pending'=>$pending,'already_published'=>$already,
 'languages'=>$langs,'photos_authorized'=>false,
 'prices_written'=>false,'post_status_target'=>'publish',
 'available_status_preserved_blank'=>true,'seo_until_approved'=>'noindex']));
 return;
}
$admin=get_user_by('id',1);
if(!$admin||!user_can($admin,'publish_posts'))
 WP_CLI::error('publish_authority_unavailable');
wp_set_current_user($admin->ID);
if($backup===false){
 if(!add_option($backupKey,[
   'created_at'=>gmdate('c'),'source_sha256'=>$sha,
   'site'=>$site,'before'=>$before
 ],'',false)) WP_CLI::error('publication_backup_failed');
}else{
 if(($backup['source_sha256']??null)!==$sha||
   count($backup['before']??[])!==count($before))
    WP_CLI::error('publication_backup_contract_changed');
}
$done=0;
foreach($chosen as $key){
 $row=$rowByKey[$key];
 foreach($langs as $lang){
  $id=$byKey[$key][$lang][0];
  if(get_post_status($id)==='publish'){
    if(get_post_meta($id,'_mad4b_pdf_editorial_publication_v1',true)===
       'mad4b.transport-pdf-editorial.v1'){
       continue;
    }
    // WPML copies post_status to sibling translations as soon as a
    // source-language record is published, but not their editorial copy.
    // Resume only when the target retains the original, pre-write text.
    $saved=$backup['before'][$id]??null;
    $now=get_post($id);
    if(!$saved||$saved['original_status']!=='draft'||
       $now->post_content!==$saved['original_content']||
       $now->post_excerpt!==$saved['original_excerpt']||
       get_post_meta($id,'trans_description',true)!==
          $saved['original_description'])
      WP_CLI::error('externally_published_or_modified_translation_'.$id);
  }
  $l=$labels[$lang];
  $city=$cities[$row['city']][$lang];
  $title=$row['titles'][$lang];
  $content='<p>'.esc_html($l['overview']).'</p>'.
   '<h3>'.esc_html($l['area']).'</h3><p>'.esc_html($city).'</p>'.
   '<h3>'.esc_html($l['route']).'</h3><p><strong>'.esc_html($title).
   '</strong></p><p>'.esc_html($l['context']).'</p>'.
   '<h3>'.esc_html($l['price']).'</h3><p>'.esc_html($l['notice']).'</p>';
  // Keep all source numeric figures and supplier vehicle costs private.
  // Publish status is editorial ONLY, not a change to availability.
  update_post_meta($id,'trans_description',
      $title."\n\n".$l['context']."\n\n".$l['notice']);
  update_post_meta($id,'rank_math_robots',['noindex','follow']);
  update_post_meta($id,'_mad4b_pdf_editorial_publication_v1',
     'mad4b.transport-pdf-editorial.v1');
  $result=wp_update_post([
     'ID'=>$id,'post_status'=>'publish',
     'post_content'=>$content,'post_excerpt'=>$l['short']
  ],true);
  if(is_wp_error($result)){
     WP_CLI::error('post_publish_failed_'.$id.'_'.$result->get_error_code());
  }
  if(get_post_status($id)!=='publish'||
     get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)!=1||
     get_post_meta($id,'trans_available_status',true)!==''||
     get_post_meta($id,'trans_start_price',true)!==''||
     get_post_thumbnail_id($id)!==0)
     WP_CLI::error('publication_post_readback_failed_'.$id);
  ++$done;
 }
}
WP_CLI::log(wp_json_encode([
 'status'=>'editorial_published',
 'site'=>$site,'source_groups'=>count($chosen),
 'newly_published'=>$done,'already_published'=>$already,
 'languages'=>$langs,
 'availability_and_checkout_unchanged'=>true,
 'source_price_disclosed'=>false,'photos_attached'=>false,
 'rollback_option'=>$backupKey
]));
