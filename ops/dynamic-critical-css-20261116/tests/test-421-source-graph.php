<?php
define('ABSPATH','/');
function add_action(){}
function get_post($id){return (object)['post_content'=>$id===33?'Static editorial content':'[jet_listing]'];}
function get_option($key,$fallback=[]){return $key==='icl_sitepress_settings'?['default_language'=>'en']:$fallback;}
function apply_filters($hook,$default,$args=[]){if($hook==='wpml_element_language_code')return ($args['element_id']??0)===107?'es':'en';return $default;}
function get_posts($params){return [100,101,102,103,104,105,107];}
function get_post_meta($id,$field,$single=true){
    $templates=[
        100=>['header',['include/general']],
        101=>['footer',['include/general']],
        102=>['archive',['include/archive']],
        103=>['archive',['include/archive/destinations']],
        104=>['archive',['include/archive/properties_archive']],
        105=>['single',['include/singular/tours-and-activities']],
        107=>['footer',['include/general']],
    ];
    if($field==='_elementor_template_type')return $templates[$id][0]??'';
    if($field==='_elementor_conditions')return $templates[$id][1]??[];
    if($field==='_elementor_data')return 'elementor-definition-'.$id.($_SERVER['GRAPH_CHANGED']??'');
    return '';
}
require '/tmp/mad4b-421-graph-candidate.php';
$r=new ReflectionMethod(MAD4B_Public_Critical_CSS_Coverage_V1::class,'source_graph');
function verify($name,$condition){if(!$condition)throw new Exception($name);echo 'PASS '.$name."\n";}
$dest=$r->invoke(null,'destinations',17);
$property=$r->invoke(null,'archive:properties',0);
$tour=$r->invoke(null,'tours-and-activities',555);
$page=$r->invoke(null,'page',33);
verify('active destination archive selects specific conditions over generic',in_array(103,$dest['document_ids'],true) && !in_array(102,$dest['document_ids'],true));
verify('property archive selects property specific source',in_array(104,$property['document_ids'],true) && !in_array(102,$property['document_ids'],true));
verify('tour single selects actual single document',in_array(105,$tour['document_ids'],true));
verify('plain WordPress page never inherits tour single document',!in_array(105,$page['document_ids'],true));
verify('header and footer document data included in every source',in_array(100,$dest['document_ids'],true) && in_array(101,$tour['document_ids'],true));
verify('archive source graphs are distinct across conditions',$dest['sha']!==$property['sha']);
verify('missing single template retains deterministic source graph',preg_match('/^[a-f0-9]{64}$/',$page['sha'])===1);
verify('all known rules result in configured graph',!in_array('ambiguous',[$dest['status'],$property['status'],$tour['status']],true));
verify('plain editorial page excludes unrelated listing generation', $page['listing_dependency']===false);
verify('tour and taxonomy remain dynamically listing-sensitive', $tour['listing_dependency']===true && $dest['listing_dependency']===true);
$es=$r->invoke(null,'page',33,'es');
verify('Spanish route resolves Spanish Theme Builder footer',in_array(107,$es['document_ids'],true) && !in_array(101,$es['document_ids'],true) && $es['status']==='configured');
verify('English route retains English translated footer',in_array(101,$page['document_ids'],true) && !in_array(107,$page['document_ids'],true));
verify('WPML-specific footer changes per-language graph signature',$es['sha']!==$page['sha']);
echo 'PASS 13/13 conditional Theme Builder graph invariants'."\n";
