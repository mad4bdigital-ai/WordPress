<?php
/** Native WordPress nonmutating Tour Rate geography/OR-AND regression. */
defined('ABSPATH') || exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true)){
 echo 'STATUS=SKIP'.PHP_EOL;return;
}
wp_set_current_user(1);
$c='MAD4B_Transport_Tour_Rate_Routing_V1';
$checks=[];$check=static function($n,$ok)use(&$checks){$checks[$n]=(bool)$ok;};
$source=$c::source();
$check('source_pdf_exact_hash',($source['route']['source_sha256']??'')===$c::PDF);
$check('source_51_route_records',count($source['route']['entries']??[])===51);
$check('source_51_pdf_rows',count($source['pdf']??[])===51);
$check('source_never_proves_pickup',!count(array_filter($source['route']['entries'],
    static fn($e)=>$e['exact_pickup_known']===true)));
$check('source_never_proves_dropoff',!count(array_filter($source['route']['entries'],
    static fn($e)=>$e['exact_dropoff_known']===true)));
global $wpdb;
$r=$wpdb->get_row($wpdb->prepare("SELECT meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug=%s",'tour-rates'),ARRAY_A);
$fields=(array)maybe_unserialize($r['meta_fields']??'');
$names=array_column($fields,'name');
$check('tour_rate_52_jetengine_fields',count($fields)===52);
$check('tour_rate_stages_after_stay',array_slice($names,45,6)===
 ['has_transportations','transport_assignment_mode','transport_selection_mode',
  'related_transportations_id','transport_price_policy','_mad4b_transport_stages_v1']);
$check('old_tour_rates_not_auto_enabled',!count(get_posts([
 'post_type'=>'tour-rates','post_status'=>['publish','draft','private','pending'],
 'posts_per_page'=>100,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'has_transportations','meta_value'=>'1'
])));
$opt=get_option('icl_sitepress_settings',[]);
$check('wpml_stages_copy',($opt['translation-management']['custom_fields_translation'][$c::META]??null)===1);
$act=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
sort($act);
$expected=$site==='https://wayfatours.com'?['en','es']:['ar','de','en','es','fr','it'];
$check('lang_matrix_correct',$act===$expected);
$all=get_posts(['post_type'=>'transportations','post_status'=>['draft','publish'],
 'posts_per_page'=>650,'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256','meta_value'=>$c::PDF]);
$check('source_matrix_complete',count($all)===51*count($act));
$byKey=[];
foreach($all as $id){
 $key=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
 $lang=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
 $byKey[$key][$lang]=$id;
}
$check('every_source_key_has_all_locales',count($byKey)===51&&
 !count(array_filter($byKey,static fn($group)=>count($group)!==count($act))));
$sourcePlace=['Cairo:11'=>['cairo','hurghada'],'Aswan:7'=>['aswan-gov','luxor'],
 'Luxor:18'=>['luxor','aswan-gov'],'Luxor:20'=>['luxor','hurghada']];
foreach($sourcePlace as $key=>$slugs){
 $id=(int)($byKey[$key]['en']??0);
 $check('origin_meta_'.$key,get_post_meta($id,'_mad4b_pdf_route_origin_slug_v1',true)===$slugs[0]);
 $check('destination_meta_'.$key,get_post_meta($id,'_mad4b_pdf_route_destination_slugs_v1',true)===[$slugs[1]]);
 $check('not_exact_points_'.$key,
    get_post_meta($id,'_mad4b_pdf_route_pickup_point_verified_v1',true)==='0'&&
    get_post_meta($id,'_mad4b_pdf_route_dropoff_point_verified_v1',true)==='0');
}
foreach($all as $id){
 if(get_post_meta($id,'trans_use_in_tour_rate_generation',true)!=='1'){
  $checks['all_source_opt_in_yes']=false;break;
 }
 if((string)get_post_meta($id,'_mad4b_pdf_route_schema_v1',true)!=='mad4b.transport-pdf-route-evidence.v1'){
  $checks['all_source_route_annotated']=false;break;
 }
}
if(!isset($checks['all_source_opt_in_yes']))$check('all_source_opt_in_yes',true);
if(!isset($checks['all_source_route_annotated']))$check('all_source_route_annotated',true);
$stage=static fn($key,$orig,$dest,$return=false,$kind='transfer',$join='OR')=>[
 'key'=>$key,'label'=>$key,'day_offset'=>2,
 'origin_term_id'=>$orig,'destination_term_id'=>$dest,
 'return_to_origin'=>$return,'required'=>true,'join'=>$join,
 'service_kind'=>$kind,'price_policy'=>'inquiry'
];
$keys=static fn($r,$section)=>is_wp_error($r)?[]:array_column($r[$section],'source_key');
$aswan=$c::candidates($stage('aswan-luxor',122,125),'en');
$check('aswan_luxor_exact_rows',
 $keys($aswan,'qualified_for_draft_composition')===['Aswan:7','Aswan:8']);
$check('aswan_luxor_source_only_yes',!is_wp_error($aswan)&&
 !count(array_filter($aswan['qualified_for_draft_composition'],fn($v)=>!$v['opted_in'])));
$reverse=$c::candidates($stage('luxor-aswan',125,122),'en');
$check('reverse_not_reuse_aswan_services',
 $keys($reverse,'qualified_for_draft_composition')===['Luxor:18','Luxor:19']);
$cairo=$c::candidates($stage('cairo-hurghada',123,124),'en');
$check('cairo_hurghada_only_source_row_11',
 $keys($cairo,'qualified_for_draft_composition')===['Cairo:11']);
$luxor=$c::candidates($stage('luxor-hurghada',125,124),'en');
$check('luxor_hurghada_exact_candidate',
 $keys($luxor,'qualified_for_draft_composition')===['Luxor:20']);
$check('unpriced_ambiguous_luxor23_review_only',
 in_array('Luxor:23',$keys($luxor,'needs_route_review'),true));
$alex=$c::candidates($stage('cairo-alex',123,722),'en');
$check('cairo_alexandria_not_auto_selected',
 !is_wp_error($alex)&&!$alex['qualified_for_draft_composition'] &&
 in_array('Cairo:9',$keys($alex,'needs_route_review'),true));
$sokhna=$c::candidates($stage('cairo-sokhna',123,733),'en');
$check('cairo_sokhna_alternative_review',
 !is_wp_error($sokhna)&&!$sokhna['qualified_for_draft_composition']&&
 in_array('Cairo:9',$keys($sokhna,'needs_route_review'),true));
$abu=$c::candidates($stage('aswan-abu',122,2403,true,'sightseeing'),'en');
$check('abu_simbel_roundtrip_exact',
 $keys($abu,'qualified_for_draft_composition')===['Aswan:5']);
$noReturn=$c::candidates($stage('aswan-abu-no',122,2403,false,'sightseeing'),'en');
$check('roundtrip_requires_explicit_return',
 !is_wp_error($noReturn)&&!$noReturn['qualified_for_draft_composition']&&
 in_array('Aswan:5',$keys($noReturn,'needs_route_review'),true));
$edfu=$c::candidates($stage('luxor-edfu',125,2401,true,'sightseeing'),'en');
$check('edfu_single_stop_proposed',
 $keys($edfu,'qualified_for_draft_composition')===['Luxor:16']);
$check('edfu_komombo_multistop_review_not_auto',
 in_array('Luxor:17',$keys($edfu,'needs_route_review'),true));
$station=$c::candidates($stage('aswan-local',122,122,false,'facility'),'en');
$check('local_station_draft_only_needs_review',
 !is_wp_error($station)&&!$station['qualified_for_draft_composition']&&
 in_array('Aswan:1',$keys($station,'needs_route_review'),true));
$wrong=$c::candidates($stage('not-supported',123,125),'en');
$check('nonexistent_route_fails_closed',
 !is_wp_error($wrong)&&!$wrong['qualified_for_draft_composition']);
$inactive=$c::candidates($stage('cairo-test',123,124),'jp');
$check('nonactive_language_rejected',is_wp_error($inactive)&&
 $inactive->get_error_code()==='transport_stage_language_not_active');
$bad=$c::validate_stage(array_merge($stage('x',122,125),['key'=>'X']));
$check('bad_key_rejected',is_wp_error($bad));
$bad2=$c::validate_stage(array_merge($stage('bad-mode',122,125),['join'=>'XOR']));
$check('invalid_operator_rejected',is_wp_error($bad2));
$two=$c::composition(['mode'=>'stages','stages'=>[
 $stage('outbound-leg',122,125,false,'transfer','OR'),
 $stage('return-leg',125,122,false,'transfer','AND')
]],'en');
$check('multi_stage_two_and_interstage',
 !is_wp_error($two)&&count($two['stages'])===2 &&
 $two['between_required_stages_operator']==='AND' &&
 $two['stages'][0]['join_operator']==='OR'&&
 $two['stages'][1]['join_operator']==='AND');
$check('no_commercial_quote_computed',
 !$two['checkout_authorized']&&!$two['price_calculation_performed']&&
 !$two['candidate_ready_for_sale']);
$dup=$c::composition(['mode'=>'stages','stages'=>[
 $stage('same-stage',122,125),$stage('same-stage',125,122)
]],'en');
$check('duplicate_stage_key_rejected',is_wp_error($dup));
$simpleMulti=$c::composition(['mode'=>'simple','stages'=>[
 $stage('first',122,125),$stage('second',125,122)
]],'en');
$check('simple_or_only_one_stage',is_wp_error($simpleMulti));
$stageDef=null;
foreach($fields as $f)
 if(($f['name']??'')==='_mad4b_transport_stages_v1')$stageDef=$f;
$pointFields=(array)($stageDef['repeater-fields']??[]);
$check('native_stage_has_17_point_fields',count($pointFields)===17);
$pointStage=$stage('city-leg',122,125);
$pointStage['required']='1';
$pointStage['return_to_origin']='0';
$pointStage['pickup_point_type']='pending';
$pointStage['pickup_point_ref_id']='0';
$pointStage['pickup_point_notes']='';
$pointStage['dropoff_point_type']='pending';
$pointStage['dropoff_point_ref_id']='0';
$pointStage['dropoff_point_notes']='';
$pointStage['transport_ids']=[];
$validPoint=$c::validate_stage($pointStage);
$check('native_jetengine_stage_values_readable',
 !is_wp_error($validPoint)&&$validPoint['required']===true&&
 $validPoint['return_to_origin']===false);
$check('missing_points_remain_unconfirmed',
 !is_wp_error($validPoint)&&
 $validPoint['pickup_point']['kind']==='pending'&&
 $validPoint['dropoff_point']['kind']==='pending'&&
 $validPoint['supplier_points_confirmed']===false);
$badPoint=$pointStage;$badPoint['pickup_point_type']='airport';
$badPoint['pickup_point_ref_id']='999999';
$invalidPoint=$c::validate_stage($badPoint);
$check('unverified_station_reference_fails_closed',
 is_wp_error($invalidPoint)&&
 $invalidPoint->get_error_code()==='transport_stage_point_reference_unverified');
$failed=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo wp_json_encode([
 'site'=>$site,'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'source_locale_posts'=>count($all),'tour_rate_fields'=>count($fields),
 'candidate_city_matching'=>true,'yes_flag_enforced'=>true,
 'pickup_points_invented'=>false,'checkout_authorized'=>false
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
if($failed)exit(1);
