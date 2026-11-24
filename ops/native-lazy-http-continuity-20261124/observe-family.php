<?php
if(!defined('WP_CLI')||!WP_CLI)exit(9);
$cfg=json_decode((string)file_get_contents('/tmp/mad4b-http-family-observe-config.json'),true);
$c='MAD4B_Native_Layout_Epoch_Continuity_V1';
$host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
if(!is_array($cfg)||($cfg['host']??'')!==$host||!class_exists($c)||
 !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',(string)($cfg['locale']??'')))throw new RuntimeException('invalid scope');
$old=(string)apply_filters('wpml_current_language','');
try{
 do_action('wpml_switch_language',$cfg['locale']);
 if((string)apply_filters('wpml_current_language','')!==$cfg['locale'])throw new RuntimeException('locale mismatch');
 $page=(int)$cfg['page_id'];$doc=(int)$cfg['document_id'];$listing=(int)$cfg['listing_id'];$query=(int)$cfg['query_id'];
 if(get_post_status($page)!=='publish'||get_post_status($doc)!=='publish'||get_post_status($listing)!=='publish'||
    get_permalink($page)!==$cfg['url']||!mad4b_secondary_grid_lazy_parent_condition_proven($query,$listing,$doc))throw new RuntimeException('source relation changed');
 $engine=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($query);
 if(!$engine||($engine->query_type??'')!=='terms'||mad4b_render_query_sensitive_semantics($engine))throw new RuntimeException('nonpublic query');
 $source=$c::family_source($cfg);
 if(!$source)throw new RuntimeException('source unavailable');
 $market=mad4b_render_query_market_identity();
 echo wp_json_encode(['contract'=>'mad4b.native-listing.http-source-observation.v1','host'=>$host,'locale'=>$cfg['locale'],
 'query_generation'=>function_exists('mad4b_render_query_generation_for_id')?mad4b_render_query_generation_for_id($query):null,
 'global_data_revision'=>(string)get_option('mad4b_render_query_data_generation','initial'),'css_epoch'=>(int)get_option('mad4b_css_ai_epoch_v1',0),
 'cli_market'=>$market,'source_sha256'=>hash('sha256',serialize($c::canonical_source($source))),
 'source'=>$source,'family_assessment'=>$c::assess_family($cfg),
 'native_gate'=>mad4b_secondary_grid_lazy_ajax_parity_proven($query,$listing,$doc)],JSON_UNESCAPED_SLASHES)."\n";
}finally{if($old!=='')do_action('wpml_switch_language',$old);}
