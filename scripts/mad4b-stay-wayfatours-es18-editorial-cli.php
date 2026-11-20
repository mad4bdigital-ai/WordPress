<?php
if (!defined('WP_CLI') || !WP_CLI || rtrim(home_url(),'/')!=='https://wayfatours.com') WP_CLI::error('wrong_site');
$mode=getenv('MAD4B_STAY_MODE')?:'plan';
if(!in_array($mode,['plan','stage','publish','verify'],true)) WP_CLI::error('bad_mode');
$descs=json_decode('{"36140":"Triumph Luxury Hotel, situado en la carretera Katameya Heights, en el Quinto Asentamiento de El Cairo. Las tarifas y la disponibilidad requieren confirmación.","36197":"Tolip Gardens Hotel, situado en el complejo Gardenia de la carretera de El Cairo a Suez. Las tarifas y la disponibilidad requieren confirmación.","36209":"Triumph Plaza Hotel, situado en la calle El-Khalifa El-Maamoun, en Heliópolis, El Cairo. Las tarifas y la disponibilidad requieren confirmación.","36316":"Registro del Saraya Pyramids Hotel. La identidad de este establecimiento y sus categorías de habitación siguen pendientes de confirmación con el proveedor. No hay tarifas ni reservas habilitadas.","36355":"Sofitel Cairo Downtown Nile, situado en el número 1191 de la Corniche del Nilo, en Bulaq, El Cairo. Las tarifas y la disponibilidad requieren confirmación.","36473":"Mövenpick Media City, situado en la carretera de los oasis, en la Ciudad del 6 de Octubre, cerca de Mall of Egypt. Las tarifas requieren confirmación.","36474":"Hilton Pyramids Golf, situado en Dreamland, en la Ciudad del 6 de Octubre, Guiza. Las tarifas y la disponibilidad requieren confirmación.","36475":"Barceló Cairo Pyramids, situado en la calle Al Haram, en Guiza. Las tarifas, la disponibilidad y las condiciones de las habitaciones siguen pendientes de verificación.","36477":"Ramses Hilton, situado en el número 1115 de Corniche El Nile, El Cairo. Hotel junto al Nilo, con acceso al centro de la ciudad. Las tarifas requieren confirmación.","36479":"Hilton Cairo Grand Nile, ubicado en la Corniche del Nilo, en Garden City, El Cairo. Las tarifas y la disponibilidad de habitaciones requieren confirmación.","70732":"Complejo hotelero en Guiza, junto a la carretera desértica de El Cairo a Alejandría. Dispone de jardines y piscina al aire libre y ofrece una ubicación práctica para visitar la zona de las pirámides.","70733":"Hotel urbano situado en Nasr City, El Cairo, con habitaciones, restaurantes y espacios para reuniones y eventos, cerca del Centro Internacional de Convenciones de El Cairo.","70734":"Hotel de lujo situado en el complejo First Residence y First Mall, junto al Nilo en Guiza, con acceso al centro de El Cairo y a importantes lugares culturales.","70735":"Hotel de lujo junto al Nilo, en las torres Nile City de la Corniche de El Cairo. Cuenta con vistas al río, varias opciones gastronómicas, spa y piscina en la azotea.","70736":"Hotel frente al Gran Museo Egipcio en Guiza, cerca de las pirámides y la Esfinge. Ofrece habitaciones con vistas a la ciudad, los jardines o los monumentos antiguos.","70737":"Hotel Hilton en Heliópolis, cerca del Aeropuerto Internacional de El Cairo, adecuado para conexiones aeroportuarias y estancias en el este de la ciudad.","70738":"Hotel de lujo en el extremo sur de la isla de Gezira, con vistas al Nilo y acceso a Zamalek, la Ópera de El Cairo y los principales lugares de interés del centro.","70739":"Hotel situado en la plaza Galae, cerca del Nilo en Dokki, con habitaciones, restaurantes y espacios para reuniones; una ubicación práctica para visitar el centro de El Cairo."}',true,512,JSON_THROW_ON_ERROR);
$names=[36475=>'Barceló Cairo Pyramids',36473=>'Mövenpick Media City',36474=>'Hilton Pyramids Golf',36479=>'Hilton Cairo Grand Nile',36477=>'Ramses Hilton'];
$cohort=['Silver'=>[70732,36475,36316,36197,36209],'Gold'=>[36479,36473,36474,36477,36140,70733],'Diamond'=>[36355,70734,70735,70736,70737,70738,70739]];
$plan=get_option('mad4b_stay_editorial_publish_v1');
if(!is_array($plan)||($plan['catalog_activated']??true)!==false||(int)(wp_count_posts('property-rates')->publish??0)!==0)WP_CLI::error('commercial_preflight_failed');
if(!has_action('wpml_set_element_language_details'))WP_CLI::error('wpml_unavailable');
$items=[];$errors=[];$ids=array_merge(...array_values($cohort));if(count($ids)!==18||count($descs)!==18)WP_CLI::error('cohort_size_mismatch');
foreach($cohort as $tier=>$members)foreach($members as $id){
 $p=get_post($id);$trid=apply_filters('wpml_element_trid',null,$id,'post_properties');
 $lang=apply_filters('wpml_element_language_details',null,['element_id'=>$id,'element_type'=>'post_properties']);
 $terms=wp_get_object_terms($id,'package_levels');
 $es_term=(!is_wp_error($terms)&&count($terms)===1&&$terms[0]->name===$tier)?(int)apply_filters('wpml_object_id',$terms[0]->term_id,'package_levels',false,'es'):0;
 $es=$trid?(int)$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT element_id FROM {$GLOBALS['wpdb']->prefix}icl_translations WHERE trid=%d AND element_type='post_properties' AND language_code='es'",$trid)):0;
 if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish'||!$trid||!$lang||$lang->language_code!=='en'||!$es_term||!isset($descs[$id]))$errors[]="source_$id";
 if($mode==='stage'&&$es)$errors[]="es_already_exists_$id";
 if($mode==='publish'){
   $receipt=$es?get_post_meta($es,'_mad4b_hotel_es_translation_v1',true):null;
   if(!$es||!is_array($receipt)||($receipt['source_id']??0)!==$id||get_post_status($es)!=='draft')$errors[]="not_staged_$id";
 }
 $items[]=['en'=>$id,'trid'=>(int)$trid,'es'=>$es,'title'=>$names[$id]??($p->post_title??''),'tier'=>$tier,'es_term'=>$es_term,'description'=>$descs[$id]];
}
if($errors){WP_CLI::line(wp_json_encode(['mode'=>$mode,'errors'=>$errors]));WP_CLI::error('preflight_blocked');}
if($mode==='plan'){WP_CLI::line(wp_json_encode(['mode'=>$mode,'ready'=>count($items),'existing_es'=>count(array_filter(array_column($items,'es'))),'tier_es_term_ids'=>array_values(array_unique(array_column($items,'es_term')))],JSON_UNESCAPED_UNICODE));return;}

if($mode==='verify'){
 $violations=[];$published=0;$levels=['Silver'=>0,'Gold'=>0,'Diamond'=>0];
 foreach($items as $v){
  $es=$v['es'];$p=$es?get_post($es):null;
  $receipt=$es?get_post_meta($es,'_mad4b_hotel_es_translation_v1',true):null;
  $term=(int)$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT tt.term_id FROM {$GLOBALS['wpdb']->term_relationships} r JOIN {$GLOBALS['wpdb']->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=%d AND tt.taxonomy='package_levels' LIMIT 1",$es));
  $translation=$es?$GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare("SELECT trid,source_language_code FROM {$GLOBALS['wpdb']->prefix}icl_translations WHERE element_type='post_properties' AND element_id=%d AND language_code='es'",$es),ARRAY_A):null;
  if(!$p||$p->post_status!=='publish'||$p->post_title!==$v['title']||$p->post_content!==$v['description']||!is_array($receipt)||($receipt['source_id']??0)!==$v['en']||$term!==$v['es_term']||!$translation||(int)$translation['trid']!==$v['trid']||$translation['source_language_code']!=='en')$violations[]='es_'.$v['en'];
  else{++$published;++$levels[$v['tier']];}
 }
 $output=['mode'=>$mode,'published'=>$published,'expected'=>count($items),'tiers'=>$levels,'violations'=>$violations,'rates_published'=>(int)(wp_count_posts('property-rates')->publish??0),'catalog_activated'=>get_option('mad4b_stay_editorial_publish_v1')['catalog_activated']??null,'at'=>gmdate('c')];
 WP_CLI::line(wp_json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 if($violations)WP_CLI::error('verify_failed');
 return;
}

$out=[];
foreach($items as $v){
 $en=$v['en'];$es=$v['es'];
 if($mode==='stage'){
  $src=get_post($en);
  $es=wp_insert_post(['post_type'=>'properties','post_status'=>'draft','post_title'=>$v['title'],'post_excerpt'=>$v['description'],'post_content'=>$v['description'],'post_author'=>$src->post_author],true);
  if(is_wp_error($es)||!$es)WP_CLI::error('insert_failed_'.$en);
  do_action('wpml_set_element_language_details',['element_id'=>$es,'element_type'=>'post_properties','trid'=>$v['trid'],'language_code'=>'es','source_language_code'=>'en']);
  do_action('wpml_switch_language','es');
  $assigned=wp_set_object_terms($es,[$v['es_term']],'package_levels',false);
  $tt=get_term($v['es_term'],'package_levels');
  $native_term=$tt&&!is_wp_error($tt)?(int)$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_relationships} WHERE object_id=%d AND term_taxonomy_id=%d",$es,$tt->term_taxonomy_id)):0;
  do_action('wpml_switch_language','en');
  if(is_wp_error($assigned)||!$native_term) WP_CLI::error('tier_failed_'.$en);
  foreach(['_thumbnail_id','property_gallery','property_image_vertical','property_grid_image_1_middle_upper','property_grid_image_2','property_grid_image_3_middle_bottom','property_grid_image_4','property_map'] as $key){$value=get_post_meta($en,$key,true);if($value!==''&&$value!==false)update_post_meta($es,$key,$value);}
  update_post_meta($es,'_mad4b_hotel_es_translation_v1',['schema'=>'mad4b.hotel-es-editorial-translation.v1','source_id'=>$en,'tier'=>$v['tier'],'trid'=>$v['trid'],'commercial_ready'=>false,'catalog_activated'=>false,'created_at'=>gmdate('c')]);
 }else{
  $p=get_post($es);
  if(!$p||$p->post_title!==$v['title']||$p->post_content!==$v['description']||get_post_status($es)!=='draft')WP_CLI::error('staged_content_changed_'.$en);
  $r=wp_update_post(['ID'=>$es,'post_status'=>'publish'],true);
  if(is_wp_error($r)||get_post_status($es)!=='publish')WP_CLI::error('publish_failed_'.$en);
 }
 $out[]=['en'=>$en,'es'=>$es,'status'=>get_post_status($es),'tier'=>$v['tier']];
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'count'=>count($out),'rows'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
