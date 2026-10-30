<?php
/** Read-only public template/injection source audit; never returns code or tracking IDs. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI only');
global $wpdb;
$domain=(string)getenv('MAD4B_TENANT_DOMAIN');
$other=['allroyalegypt.com'=>'egypttourgates.com','egypttourgates.com'=>'allroyalegypt.com'][$domain]??null;
if(!$other) throw new RuntimeException('Unknown tenant');
$types=['page','post','elementor_library','jet-engine','custom-css-js','wpcode','wp_navigation','wp_block'];
$statuses=['publish','draft','private'];
$out=['contract'=>'mad4b.company.public-template-source-signal.v1','tenant'=>$domain,
 'read_only'=>true,'no_code_values_exported'=>true,'post_types'=>[],'snippets'=>[],
 'elementor_meta'=>[],'mutations'=>false];
foreach($types as $type){
 $rows=$wpdb->get_results($wpdb->prepare('SELECT ID,post_status,post_content FROM '.$wpdb->posts.' WHERE post_type=%s AND post_status IN ("publish","draft","private")',$type),ARRAY_A);
 $stats=['records'=>count($rows),'foreign_domain_ids'=>[],'gtm_literal_count'=>0,
   'ga4_literal_count'=>0,'email_literal_count'=>0,'template_shortcode_count'=>0];
 foreach($rows as $row){
  $s=(string)$row['post_content'];$id=(int)$row['ID'];
  if(stripos($s,$other)!==false)$stats['foreign_domain_ids'][]=$id;
  $stats['gtm_literal_count']+=preg_match_all('/\bGTM-[A-Z0-9]{4,}\b/',$s);
  $stats['ga4_literal_count']+=preg_match_all('/\bG-[A-Z0-9]{7,}\b/',$s);
  $stats['email_literal_count']+=preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',$s);
  $stats['template_shortcode_count']+=preg_match_all('/\[(?:elementor-template|jet_engine|gallery|trustindex)[^\]]*\]/i',$s);
 }
 $stats['foreign_domain_ids']=array_slice($stats['foreign_domain_ids'],0,35);
 $out['post_types'][$type]=$stats;
}
$meta=$wpdb->get_results('SELECT post_id,meta_value FROM '.$wpdb->postmeta.' WHERE meta_key="_elementor_data"',ARRAY_A);
$metaCount=0;$foreign=[];$tracking=[];$widgetToken=[];
foreach($meta as $row){
 $metaCount++;$v=(string)$row['meta_value'];$id=(int)$row['post_id'];
 if(stripos($v,$other)!==false)$foreign[]=$id;
 if(preg_match('/\bGTM-[A-Z0-9]{4,}\b|\bG-[A-Z0-9]{7,}\b/',$v))$tracking[]=$id;
 if(strpos($v,'jet-options-gallery')!==false || strpos($v,'jet-listing-grid')!==false)$widgetToken[]=$id;
}
$out['elementor_meta']=[
 'documents_with_data'=>$metaCount,'foreign_domain_ids'=>array_slice($foreign,0,30),
 'tracking_literals_doc_ids'=>array_slice($tracking,0,30),
 'dynamic_widget_doc_count'=>count($widgetToken)];
$t=$wpdb->prefix.'snippets';
$present=$wpdb->get_var($wpdb->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=%s AND table_name=%s',DB_NAME,$t));
if($present===$t){
 $columns=$wpdb->get_col('SHOW COLUMNS FROM '.$t);
 if(in_array('code',$columns,true)){
  $rows=$wpdb->get_results('SELECT id,code FROM '.$t.' LIMIT 350',ARRAY_A);
  $out['snippets']=['row_count'=>count($rows),'foreign_domain_ids'=>[],
   'gtm_literal_ids'=>[],'email_literal_ids'=>[]];
  foreach($rows as $r){
   $s=(string)$r['code'];$id=(int)$r['id'];
   if(stripos($s,$other)!==false)$out['snippets']['foreign_domain_ids'][]=$id;
   if(preg_match('/\bGTM-[A-Z0-9]{4,}\b/',$s))$out['snippets']['gtm_literal_ids'][]=$id;
   if(preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',$s))$out['snippets']['email_literal_ids'][]=$id;
  }
 }
}
echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
