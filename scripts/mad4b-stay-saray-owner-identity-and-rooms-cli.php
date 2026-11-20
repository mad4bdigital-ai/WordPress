<?php
/**
 * MAD4B bounded Saray Pyramids source-to-WordPress editorial correction.
 * WP-CLI: MAD4B_SARAY_MODE=plan|apply|verify wp eval-file scripts/... --allow-root
 * Requires full WordPress/WPML runtime. Site-scoped, no pricing/inventory/checkout.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) {return;}
global $wpdb;
$mode = getenv('MAD4B_SARAY_MODE') ?: 'plan';
if (!in_array($mode,['plan','apply','verify'],true)) WP_CLI::error('invalid_mode');
$property_id=36316; $es_id=71711;
$property_name='Saray Pyramids & Museum View Hotel';
$addr='El Remaya, 59 Mostafa Ismail, Giza, Egypt';
$official='https://sarayhotels.com/rooms/saray-pyramids-museum-view';
$legacy='https://www.sarayhotels.com/rooms?hotel_id=1';
$rooms=[
 ['slug'=>'standard-room','name'=>'Standard Room','beds'=>1,'url'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view/standard-room/4','desc'=>'A twin/double room with a private bathroom, air conditioning, tea and coffee facilities, a minibar and city-facing views.','es'=>'Habitación estándar con baño privado, aire acondicionado, minibar, cafetera y vistas a la ciudad.'],
 ['slug'=>'deluxe-room','name'=>'Deluxe Room','beds'=>2,'url'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view/deluxe-room/5','desc'=>'Deluxe room category shown with two beds and a private bathroom; air conditioning, Wi-Fi, minibar and in-room safe are listed.','es'=>'Habitación deluxe con dos camas y baño privado. Incluye aire acondicionado, wifi, minibar y caja fuerte.'],
 ['slug'=>'family-room','name'=>'Family Room','beds'=>3,'url'=>'https://sarayhotels.com/rooms/saray-pyramids-museum-view/family-rooms/6','desc'=>'Family room category shown with three beds and a private bathroom; air conditioning, Wi-Fi, minibar and in-room safe are listed.','es'=>'Habitación familiar con tres camas y baño privado. Incluye aire acondicionado, wifi, minibar y caja fuerte.']
];
$old_classes=['Standard Double Room','Deluxe Double Room','Superior Room Pyramids View','Family Suite','Family Suite Terrace & Pyramids View','Junior Suite Pyramids View','Jacuzzi Suite Pyramids View'];
if(rtrim(home_url(),'/')!=='https://wayfatours.com') WP_CLI::error('site_scope_mismatch');
if(!has_action('wpml_set_element_language_details')) WP_CLI::error('wpml_not_active');
$p=get_post($property_id);$es=get_post($es_id);
if(!$p || !$es || $p->post_type!=='properties' || $es->post_type!=='properties' || $p->post_status!=='publish' || $es->post_status!=='publish') WP_CLI::error('posts_scope_mismatch');
$trid=(int)apply_filters('wpml_element_trid',null,$property_id,'post_properties');
$trid_es=(int)apply_filters('wpml_element_trid',null,$es_id,'post_properties');
$translations=$wpdb->get_results($wpdb->prepare("SELECT element_id,language_code FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND element_type='post_properties'",$trid),ARRAY_A);
if(!$trid || $trid!==$trid_es || count($translations)!==2 || (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE element_type='post_properties' AND element_id=%d AND language_code='es' AND trid=%d",$es_id,$trid))!==1) WP_CLI::error('wpml_properties_mapping_mismatch');
$plan=get_option('mad4b_stay_editorial_publish_v1');
if(!is_array($plan) || ($plan['catalog_activated']??true)!==false || (int)(wp_count_posts('property-rates')->publish??0)!==0 || (string)get_post_meta($property_id,'prop_use_in_tour_rate_generation',true)!=='0') WP_CLI::error('commerce_guard_failed');
$parents=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=%s AND parent_object_id=%d",'223',$property_id));
$receipt=get_post_meta($property_id,'_mad4b_property_identity_verified_v3',true);
$verified=is_array($receipt) && ($receipt['schema']??'')==='mad4b.saray-owner-identity.v3' && ($receipt['catalog_activated']??null)===false;
if($mode==='apply' && ($verified || $parents!==0 || $p->post_title!=='Saraya Pyramids Hotel' || $es->post_title!=='Saraya Pyramids Hotel')) WP_CLI::error('apply_not_pristine');
if($mode!=='apply' && !$verified && ($parents!==0 || $p->post_title!=='Saraya Pyramids Hotel' || $es->post_title!=='Saraya Pyramids Hotel')) WP_CLI::error('unexpected_preflight');
$taxonomy=[
 'en'=>['destinations'=>[560,665],'stars'=>[489],'package_levels'=>[2353]],
 'es'=>['destinations'=>[648,669],'stars'=>[655],'package_levels'=>[2991]],
];
foreach($taxonomy as $lang=>$ts)foreach($ts as $tax=>$terms)foreach($terms as $term){
 $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy=%s",$term,$tax));
 if($exists!==1) WP_CLI::error('taxonomy_missing_'.$lang.'_'.$tax.'_'.$term);
}
$existing=$wpdb->get_results($wpdb->prepare("SELECT ID,post_title,post_status FROM {$wpdb->posts} WHERE post_type='units' AND post_title LIKE %s",$wpdb->esc_like($property_name).' — %'),ARRAY_A);
if($mode==='apply' && $existing) WP_CLI::error('duplicate_room_titles_detected');
if($mode==='plan'){
 WP_CLI::line(wp_json_encode(['mode'=>'plan','scope'=>'Wayfatours only','en_property'=>$property_id,'es_property'=>$es_id,'wpml_trid'=>$trid,'target_name'=>$property_name,'current_name'=>$p->post_title,'existing_related_units'=>$parents,'candidate_units'=>array_column($rooms,'name'),'old_catalog_classes'=>$old_classes,'only_current_official_page'=>true,'commerce_unchanged'=>true,'verified_receipt_exists'=>$verified,'will_update_location_and_official_contact'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 return;
}
if($mode==='apply'){
 $before=[];
 foreach(['en'=>$property_id,'es'=>$es_id] as $lang=>$id){
  $post=get_post($id);$metas=[];
  foreach(['prop_email','property_map','prop_use_in_tour_rate_generation','_mad4b_property_official_sources_v1','_mad4b_property_identity_candidate_v2','_mad4b_property_identity_external_review_20261120'] as $key){$metas[$key]=['exists'=>metadata_exists('post',$id,$key),'value'=>get_post_meta($id,$key,true)];}
  $t=[];
  foreach(['destinations','stars','package_levels'] as $tax) $t[$tax]=array_map('intval',wp_get_object_terms($id,$tax,['fields'=>'ids']));
  $before[$lang]=['id'=>$id,'title'=>$post->post_title,'slug'=>$post->post_name,'content'=>$post->post_content,'excerpt'=>$post->post_excerpt,'terms'=>$t,'meta'=>$metas];
 }
 $now=gmdate('c');$roomIds=[];
 $identity=[
  'schema'=>'mad4b.saray-owner-identity.v3','property_id'=>$property_id,
  'site'=>'https://wayfatours.com','previous_name'=>$before['en']['title'],'official_name'=>$property_name,
  'owner_confirmation'=>'Saray Pyramids & Museum View Hotel — صحيح','source_type'=>'explicit_owner_confirmation_and_official_crosscheck',
  'owner_confirmed'=>true,'legal_supplier_contract_verified'=>false,'commercial_authority_granted'=>false,
  'official_url'=>'https://sarayhotels.com/','current_room_index'=>$official,'legacy_room_index'=>$legacy,
  'identity_address'=>$addr,'official_contact'=>'info@sarayhotels.com',
  'current_room_categories'=>array_column($rooms,'name'),'legacy_room_classes'=>$old_classes,
  'legacy_vs_current_catalog_unreconciled'=>true,'max_occupancy_verified'=>false,
  'rooms_allowed_editorial'=>true,'catalog_activated'=>false,'rates_enabled'=>false,'checkout_enabled'=>false,
  'before'=>$before,'recorded_at'=>$now
 ];
 // The same multilingual title is the official brand. Descriptions are independently localized.
 $contents=[
  'en'=>'Saray Pyramids & Museum View Hotel is in El Remaya, Giza, near the Giza Pyramids and Grand Egyptian Museum area. The official website currently lists Standard, Deluxe and Family room categories. Room inventory, maximum guest occupancy, contractual rates and exact photos are not verified; bookings and direct sales are not enabled.',
  'es'=>'Saray Pyramids & Museum View Hotel está situado en El Remaya, Guiza, cerca de las pirámides y la zona del Gran Museo Egipcio. El sitio oficial muestra actualmente habitaciones estándar, deluxe y familiares. El inventario, la ocupación máxima, las tarifas contractuales y las fotografías exactas aún no están verificados; no se habilitan reservas ni ventas.'
 ];
 foreach(['en'=>$property_id,'es'=>$es_id] as $lang=>$id){
  $r=wp_update_post(['ID'=>$id,'post_title'=>$property_name,'post_content'=>wpautop(esc_html($contents[$lang])),'post_excerpt'=>$contents[$lang]],true);
  if(is_wp_error($r)||!$r)WP_CLI::error('property_edit_failed_'.$lang);
  update_post_meta($id,'prop_email','info@sarayhotels.com');
  update_post_meta($id,'property_map',$addr);
  update_post_meta($id,'prop_use_in_tour_rate_generation','0');
  update_post_meta($id,'_mad4b_property_identity_verified_v3',$identity);
  update_post_meta($id,'_mad4b_property_official_sources_v1',[
   'schema'=>'mad4b.hotel-source-reference.v1','source_urls'=>['https://sarayhotels.com/','https://sarayhotels.com/rooms/saray-pyramids-museum-view','https://sarayhotels.com/contact-us'],
   'verified_address'=>$addr,'official_display_name'=>$property_name,'checked_at'=>gmdate('Y-m-d'),
   'source_identity_status'=>'owner_confirmed_official_crosscheck','note'=>'Owner-approved former Saraya record. Contractual supplier identity and legacy-vs-current room taxonomy still require commercial validation.',
   'supplier_price_verified'=>false,'amenities_source'=>'official_room_pages','images_verified'=>false,
   'official_email'=>'info@sarayhotels.com','commercial_authority_granted'=>false
  ]);
  do_action('wpml_switch_language',$lang);
  foreach(['destinations','stars'] as $tax){
   $r=wp_set_object_terms($id,$taxonomy[$lang][$tax],$tax,false);
   if(is_wp_error($r))WP_CLI::error('terms_update_failed_'.$lang.'_'.$tax);
  }
  do_action('wpml_switch_language','en');
 }
 foreach($rooms as $x){
  $title=$property_name.' — '.$x['name'];
  $text='<p>Officially documented room category for '.esc_html($property_name).'.</p><p><strong>Room category:</strong> '.esc_html($x['name']).'.</p><p>'.esc_html($x['desc']).'</p><p><strong>Source:</strong> <a href="'.esc_url($x['url']).'" rel="noopener">Official accommodation details</a>.</p><p>Number of beds is not proof of maximum guest occupancy. No verified inventory, contractual rates, meal-plan inclusion or licensed room photos are available. This editorial entry is not a bookable offer.</p>';
  $uid=wp_insert_post(['post_type'=>'units','post_status'=>'draft','post_title'=>$title,'post_content'=>$text,'post_excerpt'=>'Room category documented by the hotel; commercial capacity, inventory, photos and pricing require review.','post_author'=>(int)$p->post_author?:1],true);
  if(is_wp_error($uid)||!$uid) WP_CLI::error('unit_insert_failed_'.$x['slug']);
  do_action('wpml_set_element_language_details',['element_id'=>$uid,'element_type'=>'post_units','language_code'=>'en','source_language_code'=>null]);
  update_post_meta($uid,'unit_kind','room');
  update_post_meta($uid,'unit_readiness','pending_review');
  update_post_meta($uid,'unit_available','false');
  $fingerprint=hash('sha256',$property_id.'|saray-official-current-v1|'.$x['slug']);
  update_post_meta($uid,'_mad4b_room_import_fingerprint_v1',$fingerprint);
  update_post_meta($uid,'_mad4b_room_source_receipt_v1',[
   'schema'=>'mad4b.hotel-unit-source.v1','property_id'=>$property_id,'room_category'=>$x['name'],
   'source_url'=>$x['url'],'source_type'=>'official_current_room_detail_page','source_confidence'=>'official_current_page',
   'source_beds'=>$x['beds'],'source_bathrooms'=>1,'verified_on'=>gmdate('Y-m-d'),'identity_receipt'=>'_mad4b_property_identity_verified_v3',
   'commercial_status'=>'pending_supplier_review','occupancy_verified'=>false,'inventory_verified'=>false,'rates_verified'=>false,
   'images_verified'=>false,'price_and_units_intentionally_unset'=>true,'source_taxonomy_note'=>'Old seven-category listing not assumed equivalent'
  ]);
  update_post_meta($uid,'_mad4b_room_verified_specs_v2',[
   'schema'=>'mad4b.room-facts.v2','source_url'=>$x['url'],'source_http'=>200,'room_heading_verified'=>true,'recorded_at'=>gmdate('Y-m-d'),
   'facts'=>['beds_listed'=>$x['beds'],'bathrooms_listed'=>1,'amenities'=>['Air conditioning','Wi-Fi','Minibar','Safe']],
   'notes'=>['beds_listed_not_max_occupancy','commercial_stock_not_verified','image_usage_rights_not_granted'],
   'commercial_status'=>'pending_review','price_confirmed'=>false,'inventory_confirmed'=>false,'official_room_media_usage_granted'=>false
  ]);
  update_post_meta($uid,'unit_details',$x['beds'].' bed(s) and 1 bathroom shown on the hotel website. Maximum guest occupancy and bedding configuration require supplier confirmation.');
  update_post_meta($uid,'_mad4b_stay_editorial_published_v1',['schema'=>'mad4b.stay-editorial-publication.v1','source_type'=>'units','catalog_activated'=>false,'original_status'=>'new','published_at'=>$now,'price_or_inventory_mutation'=>false]);
  if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=%s AND parent_object_id=%d AND child_object_id=%d",'223',$property_id,$uid))!==0)WP_CLI::error('unexpected_relation_duplicate_'.$uid);
  if(!$wpdb->insert($wpdb->prefix.'jet_rel_default',['rel_id'=>'223','parent_rel'=>0,'parent_object_id'=>$property_id,'child_object_id'=>$uid],['%s','%d','%d','%d'])) WP_CLI::error('relation_insert_failed_'.$uid);
  $published=wp_update_post(['ID'=>$uid,'post_status'=>'publish'],true);
  if(is_wp_error($published)||get_post_status($uid)!=='publish')WP_CLI::error('unit_publish_failed_'.$uid);
  $roomIds[$x['slug']]=$uid;
 }
 $identity['units_created']=$roomIds;
 update_post_meta($property_id,'_mad4b_property_identity_verified_v3',$identity);
 update_post_meta($es_id,'_mad4b_property_identity_verified_v3',$identity);
 WP_CLI::line(wp_json_encode(['mode'=>'apply','status'=>'published_editorial_only','property_id'=>$property_id,'es_property_id'=>$es_id,'created_units'=>$roomIds,'catalog_activated'=>false,'supplier_rates_confirmed'=>false,'at'=>$now],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 return;
}
if($mode==='verify'){
 $errors=[];$identity=get_post_meta($property_id,'_mad4b_property_identity_verified_v3',true);$roomIds=$identity['units_created']??[];
 if(!is_array($roomIds)||count($roomIds)!==count($rooms))$errors[]='unit_ids_missing';
 if($p->post_title!==$property_name||$es->post_title!==$property_name)$errors[]='title_mismatch';
 if($p->post_name!=='saraya-pyramids-hotel'||$es->post_name!=='saraya-pyramids-hotel')$errors[]='legacy_slug_changed';
 if((string)get_post_meta($property_id,'prop_use_in_tour_rate_generation',true)!=='0')$errors[]='generation_enabled';
 foreach(['en'=>$property_id,'es'=>$es_id] as $lang=>$id){
  foreach(['destinations','stars'] as $tax){
   $actual=$wpdb->get_col($wpdb->prepare("SELECT tt.term_id FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=%d AND tt.taxonomy=%s",$id,$tax));
   $expected=$taxonomy[$lang][$tax];sort($actual,SORT_NUMERIC);sort($expected,SORT_NUMERIC);
   if(array_map('intval',$actual)!==$expected)$errors[]="tax_{$lang}_{$tax}";
  }
 }
 $res=[];foreach($rooms as $x){
  $id=(int)($roomIds[$x['slug']]??0);$post=$id?get_post($id):null;$source=$id?get_post_meta($id,'_mad4b_room_source_receipt_v1',true):null;
  $link=$id?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=%s AND parent_object_id=%d AND child_object_id=%d",'223',$property_id,$id)):0;
  if(!$post||$post->post_type!=='units'||$post->post_status!=='publish'||$post->post_title!==$property_name.' — '.$x['name']||!$link||!is_array($source)||($source['source_url']??'')!==$x['url']||($source['price_and_units_intentionally_unset']??null)!==true||(int)get_post_meta($id,'unit_max_occupancy',true)>0 || (int)get_post_thumbnail_id($id)>0 || get_post_meta($id,'unit_available',true)!=='false'||get_post_meta($id,'unit_readiness',true)!=='pending_review')$errors[]='unit_'.$x['slug'];
  $res[]=['id'=>$id,'title'=>$post->post_title??null,'published'=>$post&&$post->post_status==='publish','relation'=>$link,'occupancy_verified'=>false,'has_image'=>false];
 }
 $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default WHERE rel_id=%s AND parent_object_id=%d",'223',$property_id));
 if($total!==3)$errors[]='relation_count_mismatch';
 if((int)(wp_count_posts('property-rates')->publish??0)!==0 || get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']!==false)$errors[]='commercial_policy_changed';
 WP_CLI::line(wp_json_encode(['mode'=>'verify','error_count'=>count($errors),'errors'=>$errors,'identity_owner_confirmed'=>($identity['owner_confirmed']??false),'name'=>$p->post_title,'units'=>$res,'relations'=>$total,'legacy_extra_classes_still_require_review'=>true,'rates_published'=>(int)(wp_count_posts('property-rates')->publish??0),'catalog_activated'=>get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 if($errors)WP_CLI::error('verification_failed');
}
