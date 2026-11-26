<?php
/**
 * WPML-linked 2026 source operation drafts. Never publishes, quotes, or books.
 *
 * Run dry: wp eval-file /tmp/import-transport-pdf-operations-wpml.php
 * Apply: wp eval 'define("MAD4B_TRANSFER_OPS_APPLY",true);require "...";'
 * Optional: define MAD4B_TRANSFER_OPS_CITY, MAD4B_TRANSFER_OPS_ROW_LIMIT.
 * Guarded Production: define MAD4B_TRANSFER_OPS_WAYFA_APPROVED=true.
 *
 * Idempotence is source-key + WPML language. Does not overwrite editor updates.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit(2);
$site=rtrim(home_url('/'),'/');
$production=$site==='https://wayfatours.com';
if ($site!=='https://tours.dev.mad4b.com' &&
    !($production && defined('MAD4B_TRANSFER_OPS_WAYFA_APPROVED') &&
        MAD4B_TRANSFER_OPS_WAYFA_APPROVED===true))
    WP_CLI::error('site_not_approved');
$data=require WP_PLUGIN_DIR.'/mad4b-pricing-registry/data/transport-pdf-2026-localized.php';
if (($data['contract']??'')!=='mad4b.transfer-pdf-2026-localized.v1' ||
    count($data['rows']??[])!==51 || !empty($data['published_rates_authorized']) ||
    !empty($data['currency_confirmed']) || !empty($data['price_role_confirmed']))
    WP_CLI::error('source_data_contract_invalid');
$active=apply_filters('wpml_active_languages',NULL,array('skip_missing'=>0));
if (!is_array($active) || !isset($active['en'])) WP_CLI::error('wpml_active_languages_missing');
$langs=array_keys($active);
sort($langs);
$expected=$production?array('en','es'):array('ar','de','en','es','fr','it');
if ($langs!==$expected) WP_CLI::error('site_active_languages_changed_'.implode(',',$langs));
if (!function_exists('wpml_get_language_information') &&
    !has_action('wpml_set_element_language_details')) {
    WP_CLI::error('wpml_language_linker_not_available');
}
$admin=get_user_by('id',1);
if (!$admin || !user_can($admin,'edit_others_posts')) WP_CLI::error('author_not_authorized');
$media=get_option('mad4b_transfer_pdf_2026_vehicle_media_map_v1',array());
if (!is_array($media) || count($media)!==6) WP_CLI::error('vehicle_category_media_missing');
foreach ($media as $key=>$attachment) {
    if (!in_array($key,array('limousine','luxury_7_seats','h1','hiace','coaster','bus'),true) ||
        get_post_type((int)$attachment)!=='attachment') WP_CLI::error('invalid_media_ref_'.$key);
}
$cityEnTerms=array('Cairo'=>123,'Aswan'=>122,'Luxor'=>125);
$cityLabels=array(
 'en'=>array('Cairo'=>'Cairo','Aswan'=>'Aswan','Luxor'=>'Luxor'),
 'ar'=>array('Cairo'=>'القاهرة','Aswan'=>'أسوان','Luxor'=>'الأقصر'),
 'fr'=>array('Cairo'=>'Le Caire','Aswan'=>'Assouan','Luxor'=>'Louxor'),
 'de'=>array('Cairo'=>'Kairo','Aswan'=>'Assuan','Luxor'=>'Luxor'),
 'es'=>array('Cairo'=>'El Cairo','Aswan'=>'Asuán','Luxor'=>'Lúxor'),
 'it'=>array('Cairo'=>'Il Cairo','Aswan'=>'Assuan','Luxor'=>'Luxor'));
$descriptions=array(
 'en'=>'Supplier rate-sheet transport operation. Exact route, pickup/drop-off points, included services, vehicle availability and currency must be confirmed before an offer.',
 'ar'=>'بند تشغيل نقل مُدرج في جدول أسعار المورد. يجب تأكيد المسار ونقاط الالتقاء والوصول والخدمات المشمولة وتوافر المركبات والعملة قبل تقديم أي عرض.',
 'fr'=>'Prestation de transport mentionnée au tarif fournisseur. Itinéraire, prise en charge, dépose, prestations comprises, véhicules et devise à confirmer avant toute offre.',
 'de'=>'Transportleistung aus der Preisliste des Anbieters. Strecke, Abholung, Ziel, Leistungsumfang, Fahrzeuge und Währung sind vor einem Angebot zu bestätigen.',
 'es'=>'Operación de transporte de la tarifa del proveedor. Ruta, recogida, destino, servicios incluidos, vehículos y moneda pendientes de confirmar antes de ofrecerla.',
 'it'=>'Servizio di trasporto nel listino del fornitore. Itinerario, ritiro, arrivo, servizi inclusi, veicoli e valuta da confermare prima di qualsiasi offerta.');
$excerpts=array(
 'en'=>'Draft transport operation · price on request, not available for booking',
 'ar'=>'بند نقل مسودة · السعر عند الطلب، غير متاح للحجز',
 'fr'=>'Prestation de transport en brouillon · prix sur demande, réservation indisponible',
 'de'=>'Transportentwurf · Preis auf Anfrage, nicht buchbar',
 'es'=>'Operación de transporte en borrador · precio bajo consulta, no reservable',
 'it'=>'Servizio di trasporto in bozza · prezzo su richiesta, non prenotabile');
$apply=defined('MAD4B_TRANSFER_OPS_APPLY')&&MAD4B_TRANSFER_OPS_APPLY===true;
$filter=defined('MAD4B_TRANSFER_OPS_CITY')?(string)MAD4B_TRANSFER_OPS_CITY:'';
if ($filter!=='' && !isset($cityEnTerms[$filter])) WP_CLI::error('unknown_city_filter');
$limit=defined('MAD4B_TRANSFER_OPS_ROW_LIMIT')?max(0,(int)MAD4B_TRANSFER_OPS_ROW_LIMIT):0;
$selected=array_values(array_filter($data['rows'],static fn($row)=>
    $filter==='' || $row['city']===$filter));
if ($limit>0) $selected=array_slice($selected,0,$limit);
if (!$apply) {
    WP_CLI::log(wp_json_encode(array('status'=>'dry_run','site'=>$site,
        'operation_rows'=>count($selected),'languages'=>$langs,
        'expected_wp_posts'=>count($selected)*count($langs),
        'vehicle_image_refs'=>$media,'post_status'=>'draft',
        'prices_to_publish'=>0,'supplier_currency_known'=>false)));
    return;
}
// Atomic WordPress option lock: concurrent wp-cli invocations must not
// create duplicate WPML translation groups for the same source rows.
$lockKey='mad4b_transfer_pdf_2026_import_lock_v1';
if (!add_option($lockKey,array('site'=>$site,'started_at'=>gmdate('c'),
    'pid'=>getmypid()),'',false)) WP_CLI::error('source_import_in_progress');
register_shutdown_function(static function() use($lockKey) {
    delete_option($lockKey);
});
wp_set_current_user((int)$admin->ID);
$created=array();$existing=0;$translationsByLang=array_fill_keys($langs,0);
$itemsProcessed=0;
foreach($selected as $row) {
    $sourceKey=$row['source_key'];
    if (!preg_match('/^(Cairo|Aswan|Luxor):([1-9][0-9]?)$/D',$sourceKey))
        WP_CLI::error('invalid_source_key');
    $matches=get_posts(array('post_type'=>'transportations','post_status'=>'any',
        'posts_per_page'=>50,'fields'=>'ids','suppress_filters'=>true,
        'meta_key'=>'_mad4b_transfer_pdf_2026_source_key','meta_value'=>$sourceKey));
    $byLang=array();
    foreach($matches as $id) {
        $detail=apply_filters('wpml_post_language_details',null,(int)$id);
        $lang=$detail['language_code']??null;
        if (!$lang || isset($byLang[$lang]) ||
            (!in_array(get_post_status($id),array('draft','publish'),true) ||
             (get_post_status($id)==='publish' &&
               get_post_meta($id,'_mad4b_pdf_editorial_publication_v1',true)!=='mad4b.transport-pdf-editorial.v1')) ||
            get_post_meta($id,'_mad4b_transfer_pdf_2026_sha256',true)!==$data['source_sha256'])
            WP_CLI::error('existing_content_conflict_'.$sourceKey.'_'.(string)$lang);
        $byLang[$lang]=(int)$id;
    }
    foreach(array_merge(array('en'),array_values(array_diff($langs,array('en')))) as $lang) {
        $id=$byLang[$lang]??0;
        $englishId=$byLang['en']??0;
        if (!$id) {
            $term=apply_filters('wpml_object_id',$cityEnTerms[$row['city']],
                'destinations',false,$lang);
            $road=apply_filters('wpml_object_id',568,'transportation-types',false,$lang);
            if (!$term || !$road) WP_CLI::error('translated_geo_term_missing_'.$sourceKey.'_'.$lang);
            $title=$cityLabels[$lang][$row['city']].' — '.$row['titles'][$lang];
            $postId=wp_insert_post(array(
                'post_type'=>'transportations','post_status'=>'draft','post_title'=>$title,
                'post_name'=>'supplier-transfer-2026-'.strtolower($row['city']).'-'.$row['source_row'].'-'.$lang,
                'post_author'=>$admin->ID,'post_content'=>'<p>'.esc_html($descriptions[$lang]).'</p>',
                'post_excerpt'=>$excerpts[$lang],
                'comment_status'=>'closed','ping_status'=>'closed'),true);
            if (is_wp_error($postId) || !$postId) WP_CLI::error('insert_failed_'.$sourceKey.'_'.$lang);
            $id=(int)$postId;
            do_action('wpml_set_element_language_details',array(
                'element_id'=>$id,'element_type'=>'post_transportations',
                'trid'=>$lang==='en'?false:apply_filters('wpml_element_trid',null,$englishId,'post_transportations'),
                'language_code'=>$lang,'source_language_code'=>$lang==='en'?null:'en'));
            $confirmedLang=apply_filters('wpml_post_language_details',null,$id);
            if (($confirmedLang['language_code']??null)!==$lang)
                WP_CLI::error('wpml_language_assignment_failed_'.$sourceKey.'_'.$lang);
            wp_set_object_terms($id,array((int)$road),'transportation-types',false);
            wp_set_object_terms($id,array((int)$term),'destinations',false);
            // Native Tour Component identity requires the canonical EN mode
            // cache; localized WPML taxonomy is stored separately.
            update_post_meta($id,'trans_mode',568);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',$sourceKey);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_sha256',$data['source_sha256']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_source_ar',$row['source_ar']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_city',$row['city']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_service_kind',$row['service_kind']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_service_note',$row['service_note']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_raw_vehicles',$row['vehicle_rates_unverified']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_vehicle_media',array_intersect_key($media,array_flip($row['vehicle_categories'])));
            update_post_meta($id,'_mad4b_transfer_pdf_2026_quality_flags',$row['quality_flags']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_effective_from',$row['effective_from']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_discount_note',$row['supplier_discount_note']);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_review_status','unverified_supplier_contract_draft');
            update_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',$lang);
            update_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',1);
            // Trusted PDF operations are explicitly enrolled for a future
            // regenerated Tour Rate composition. They remain Draft, so
            // component selection and commerce stay independently gated.
            update_post_meta($id,'trans_use_in_tour_rate_generation','1');
            // Do not set trans_start_price / trans_base_currency / trans_type:
            // each PDF row spans multiple vehicle categories; no source currency.
            $created[$lang]=($created[$lang]??0)+1;
        } else ++$existing;
        $translationsByLang[$lang]++;
        $byLang[$lang]=$id;
        if ($lang==='en') $englishId=$id;
    }
    $trids=array();
    foreach($byLang as $lang=>$id) {
        $trids[]=apply_filters('wpml_element_trid',null,$id,'post_transportations');
        if ((!in_array(get_post_status($id),array('draft','publish'),true) ||
              (get_post_status($id)==='publish' &&
                 get_post_meta($id,'_mad4b_pdf_editorial_publication_v1',true)!=='mad4b.transport-pdf-editorial.v1')) ||
            get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)!=1)
            WP_CLI::error('post_not_protected_'.$sourceKey.'_'.$lang);
    }
    if(count(array_unique($trids))!==1 || !$trids[0])
        WP_CLI::error('wpml_translation_group_mismatch_'.$sourceKey);
    ++$itemsProcessed;
}
WP_CLI::log(wp_json_encode(array('status'=>'imported_drafts','site'=>$site,
    'operations'=>$itemsProcessed,'languages'=>$langs,'posts_created'=>$created,
    'preexisting_posts_preserved'=>$existing,'translation_coverage'=>$translationsByLang,
    'public_posts_created'=>0,'approved_rate_posts_created'=>0,
    'currency_confirmed'=>false,'checkout_activated'=>false)));
