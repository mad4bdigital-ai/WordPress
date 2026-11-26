<?php
/**
 * Read-only WP-CLI smoke for archive Editorial source/renderer parity.
 * wp --allow-root eval-file wp-content/plugins/dynamic-filter-seo-bridge/tests/editorial-source-parity.smoke.php
 */
if (!defined('ABSPATH') || !class_exists('WP_CLI')) return;
$g=new \DynamicFilterSEOBridge\Content\GalleryComposer();
$content=new \DynamicFilterSEOBridge\Content\ContentComposer($g);
$slots=new \DynamicFilterSEOBridge\Presentation\ContentSlotRegistry(true);
$records=[
 ['term_id'=>101,'name'=>'Aswan','description'=>'<span style="color:red">Nile and heritage.</span><style>body{display:none}</style>'],
 ['term_id'=>102,'name'=>'Cairo','description'=>'Historical and urban experiences.'],
 ['term_id'=>103,'name'=>'El-Fayoum','description'=>'Oases and natural landscapes.'],
];
$context=[
 'active'=>true,'in_scope'=>true,'runtime_ready'=>true,'filters'=>['destinations'=>['aswan','cairo','fayoum']],
 'provider'=>'jet-engine','query_id'=>'tours_query_archive','language'=>'en',
 'profile'=>['composition_mode'=>'travel','taxonomy_rules'=>['destinations'=>['role'=>'location','priority'=>10]]],
 'terms'=>['location'=>$records[0]],'term_sets'=>['location'=>$records]
];
$resolver=new \DynamicFilterSEOBridge\Presentation\PresentationResolver(static fn()=>$context,$content,$g,$slots);
$cards=$resolver->slot('tour_type_section',$context);
$checks=[
 'multi_name_title'=>$resolver->value('title',$context)==='Aswan, Cairo & El-Fayoum',
 'three_cards'=>substr_count($cards,'data-term-id=')===3,
 'no_term_markup_styles'=>!str_contains($cards,'style=')&&!str_contains($cards,'<style')&&!str_contains($cards,'body{display:none}'),
 'legacy_slot_same_as_native'=>$cards===$resolver->slot('editorial_cards',$context),
 'duplicate_style_slot_omitted'=>$resolver->slot('style_section',$context)==='',
 'isolated_component'=>str_contains($cards,'dfsb-editorial-cards'),
];
$other=$context;$other['query_id']='different_query';
$checks['no_leak_to_other_queries']=!str_contains($resolver->slot('tour_type_section',$other),'dfsb-editorial-cards');
$single=$context;$single['term_sets']['location']=[$records[0]];
$checks['single_term_title']=$content->title($single)==='Aswan';
$copy=$content->intro($single);
$checks['single_term_copy_strips_style']=str_contains($copy,'Nile and heritage.')&&!str_contains($copy,'style=')&&!str_contains($copy,'display:none');
$checks['all_locales']=true;
foreach(['es','ar','de','fr','it'] as $lang){
 $local=$context;$local['language']=$lang;
 $title=$content->title($local);
 $checks['all_locales']=$checks['all_locales'] &&
  str_contains($title,'Aswan')&&str_contains($title,'Cairo')&&str_contains($title,'El-Fayoum');
}
WP_CLI::line(wp_json_encode(['checks'=>$checks,'pass'=>!in_array(false,$checks,true)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
if(in_array(false,$checks,true))WP_CLI::error('EDITORIAL_SOURCE_PARITY_FAILED');

