<?php
/**
 * WP-CLI read-only lifecycle regression cases. Does not create posts.
 * Optional MAD4B_BRAND_TEST_CLASS sets a separately loaded candidate class.
 */
if (!defined('ABSPATH')) {fwrite(STDERR,"WP required\n");exit(2);}
$engine=getenv('MAD4B_BRAND_TEST_CLASS') ?: 'MAD4B_SCP_Brand_Integrity';
if(!class_exists($engine,false)){fwrite(STDERR,"Brand engine missing\n");exit(2);}
$cases=array();
$cases['good_payload']=$engine::payload_preflight(array(
    'post_title'=>'Discover Egypt and the Giza Pyramids',
    'meta'=>array('tour_why_book_with_us'=>'Enjoy a private half-day tour with an Egyptologist guide.')
));
$cases['bad_payload']=$engine::payload_preflight(array(
    'post_content'=>'Why travelers choose Meridian Path for a memorable journey.'
));
$cases['bad_serialized_repeater_payload']=$engine::payload_preflight(array(
    'meta'=>array('itinerary_item'=>array(
        'item-0'=>array('description'=>'We at Meridian Path ensure easy travel.')
    ))
));
$cases['ordinary_geography']=$engine::payload_preflight(array(
    'meta'=>array('faq_item'=>array('item-1'=>array(
        'answer'=>'Abu Simbel is near the southern border with Sudan.'
    )))
));
$cases['editorial_source_link']=$engine::payload_preflight(array(
    'post_content'=>'Read the museum history at <a href="https://museum.example.org/">the museum website</a>.'
));
$cases['promotional_external_link']=$engine::payload_preflight(array(
    'meta'=>array('tour_internal_linking_exploration_content'=>
        '<a href="https://unrelated.example.org/tours/">Explore our tours</a>')
));
$cases['unregistered_elementor_text_setting']=$engine::payload_preflight(array(
    'meta'=>array('_elementor_data'=>array('widget-1'=>array(
        'settings'=>array('cta_microcopy'=>'Why travelers choose Meridian Path for Cairo travel.')
    )))
));
$cases['css_source_not_editorial_copy']=$engine::payload_preflight(array(
    'meta'=>array('_elementor_page_settings'=>array(
        'custom_css'=>'/* Why travelers choose Meridian Path */ .box { color: #fff; }'
    ))
));
$cases['oversized_css_not_a_text_gap']=$engine::payload_preflight(array(
    'meta'=>array('_elementor_page_settings'=>array(
        'custom_css'=>str_repeat('a',524289),
    ))
));
$cases['ordinary_german_grammar']=$engine::payload_preflight(array(
    'meta'=>array('accomodation'=>'Bitte wählen Sie Ihre Präferenzen für die Reise aus.')
));
$cases['oversized_payload']=$engine::payload_preflight(array(
    'meta'=>array('tour_story_content'=>str_repeat('A',524289))
));
$values=array();for($i=0;$i<1003;$i++)$values['field'.$i]='An informative customer-facing sentence '.$i;
$cases['too_many_fields']=$engine::payload_preflight(array('meta'=>$values));
$high=array('id'=>'same',
    'severity'=>'high','source_evidence'=>array('source_sha256'=>str_repeat('a',64)));
$modified=$high;$modified['source_evidence']['source_sha256']=str_repeat('b',64);
$before=array('complete'=>true,'issues'=>array($high));
$cases['unchanged_high_count']=count($engine::newly_introduced_high($before,$before));
$cases['changed_high_count']=count($engine::newly_introduced_high($before,
    array('complete'=>true,'issues'=>array($modified))));
$cases['new_high_count']=count($engine::newly_introduced_high(
    array('complete'=>true,'issues'=>array()),$before));
$ids=get_posts(array('post_type'=>'tours-and-activities','post_status'=>'publish',
    'posts_per_page'=>1,'fields'=>'ids','suppress_filters'=>true));
$real=(int)($ids[0]??0);
if(!$real){fwrite(STDERR,"No published tour available\n");exit(2);}
$report=$engine::scan_post_report($real);
$cases['real_report_complete']=!empty($report['complete']);
$cases['real_update_allowed']=$engine::live_update_preflight($real,$report);
$cases['missing_identity_report']=$engine::scan_post_report($real,array('scope'=>array()));
$full=$engine::discover();
$required_types=array_values(array_filter(array('post','page','product','elementor_library'),
    static fn($type)=>post_type_exists($type)));
$scanned_types=array_values((array)($full['post_types']??array()));
$total_public=0;
foreach($scanned_types as $type)$total_public+=(int)(wp_count_posts($type)->publish??0);
$cases['default_discovery_complete']=!empty($full['complete']);
$cases['default_discovery_inventory']=$full['scanned']===$total_public;
$cases['default_discovery_coverage_gaps']=(int)($full['coverage_gap_count']??-1);
$cases['default_scanned_post_types']=empty(array_diff($required_types,$scanned_types));
$expect=array(
    'good_payload'=>null,
    'bad_payload'=>'mad4b_brand_integrity_publication_blocked',
    'bad_serialized_repeater_payload'=>'mad4b_brand_integrity_publication_blocked',
    'ordinary_geography'=>null,
    'editorial_source_link'=>null,
    'promotional_external_link'=>'mad4b_brand_integrity_publication_blocked',
    'ordinary_german_grammar'=>null,
    'unregistered_elementor_text_setting'=>'mad4b_brand_integrity_publication_blocked',
    'css_source_not_editorial_copy'=>null,
    'oversized_css_not_a_text_gap'=>null,
    'oversized_payload'=>'mad4b_brand_integrity_payload_incomplete',
    'too_many_fields'=>'mad4b_brand_integrity_payload_incomplete',
    'unchanged_high_count'=>0,
    'changed_high_count'=>1,
    'new_high_count'=>1,
    'real_report_complete'=>true,
    'real_update_allowed'=>null,
    'missing_identity_report'=>false,
    'default_discovery_complete'=>true,
    'default_discovery_inventory'=>true,
    'default_discovery_coverage_gaps'=>0,
    'default_scanned_post_types'=>true
);
$failed=array();
foreach($expect as $key=>$wanted){
    $observed=$cases[$key];
    if(is_wp_error($observed))$observed=$observed->get_error_code();
    elseif(is_array($observed)){
        if($key==='missing_identity_report')$observed=!empty($observed['complete']);
        else $observed=null===($observed['allowed']??null)?'invalid_success_contract':null;
    } elseif($key==='real_report_complete')$observed=(bool)$observed;
    if($observed!==$wanted)$failed[]=array('case'=>$key,'expected'=>$wanted,'actual'=>$observed);
}
echo wp_json_encode(array('contract'=>'mad4b.brand-integrity.lifecycle-qa.v1',
    'tests'=>count($expect),'passed'=>count($expect)-count($failed),'failures'=>$failed,
    'post_type'=>get_post_type($real),'actual_post_id'=>$real),JSON_UNESCAPED_UNICODE)."\n";
if($failed)exit(1);
