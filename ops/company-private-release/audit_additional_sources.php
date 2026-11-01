<?php
/* MAD4B readonly discovery: no raw customer, option, account, GTM, email or credential values. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('CLI only');
global $wpdb;
$tenant=(string)getenv('MAD4B_TENANT_DOMAIN');
$map=['allroyalegypt.com'=>'egypttourgates.com','egypttourgates.com'=>'allroyalegypt.com'];
if(!isset($map[$tenant]))throw new RuntimeException('Unknown tenant');
$other=$map[$tenant];
$like='%'.$wpdb->esc_like($other).'%';
$prefix=$wpdb->prefix;
$tables=$wpdb->get_col($wpdb->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=%s',DB_NAME));
if($wpdb->last_error)throw new RuntimeException('DB read error');
$has=static fn($suffix)=>in_array($prefix.$suffix,$tables,true);
$optionKeys=$wpdb->get_col('SELECT option_name FROM '.$wpdb->options.' ORDER BY option_name');
$prefixFamilies=['woocommerce','elementor','jet_','jet-engine','fluent','rank_math','litespeed','quic','trustindex','gtm4wp','wpml','icl_','astra','sitekit','facebook','pixel','snippet','mad4b','wp_mail'];
$families=[];
foreach($prefixFamilies as $f){
 $keys=array_values(array_filter($optionKeys,static fn($k)=>stripos($k,$f)!==false));
 $families[$f]=['key_count'=>count($keys),'key_names'=>array_slice($keys,0,95)];
}
$presentKeys=['admin_email','new_admin_email','elementor_active_kit','theme_mods_astra',
 'gtm4wp-options','litespeed.cloud._summary','litespeed.conf.cdn-ori',
 'rank_math_analytics_all_services','rank_math_options_titles','rank_math_options_general',
 'fluentform_global_settings','fluent_smtp_settings','woocommerce_email_from_address',
 'trustindex-tripadvisor-page-details','trustindex-tripadvisor-review-download-request-id',
 'look-and-feel','contact-and-help-info'];
$identity=[];
foreach($presentKeys as $key){
 $v=get_option($key,null);
 $identity[$key]=['present'=>$v!==null,'data_kind'=>gettype($v),
   'object_field_names'=>is_array($v)?array_slice(array_keys($v),0,40):null];
}
$primary=['fluentform_forms','fluentform_form_meta','fluentform_submissions','fluentform_entry_details',
 'snippets','jet_post_types','jet_taxonomies','jet_cct_transport_rates',
 'trustindex_tripadvisor_reviews','trustindex_tripadvisor_views',
 'rank_math_redirections','rank_math_404_logs','icl_strings','icl_translations',
 'icl_string_translations','woocommerce_order_items','wc_orders','wc_order_addresses',
 'actionscheduler_actions'];
$tableInventory=[];
foreach($primary as $name){
 $present=$has($name);
 $count=null;
 if($present){
  $count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$prefix.$name);
  if($wpdb->last_error)throw new RuntimeException('Count read failed');
 }
 $tableInventory[$name]=['present'=>$present,'rows'=>$count];
}
$otherTables=[];
foreach($tables as $table){
 if(!str_starts_with($table,$prefix))continue;
 $s=substr($table,strlen($prefix));
 if(in_array($s,$primary,true))continue;
 if(preg_match('/cct_|payment|review|booking|snip|mail|form|option|cache|license|integration|api|webhook/i',$s)){
  $otherTables[]=$s;
 }
}
$cross=[];
$sql=$wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_value LIKE %s ORDER BY option_name LIMIT 110',$like);
$cross['wp_options']=$wpdb->get_col($sql);
$surfaces=[
 'wp_posts_content'=>[$wpdb->posts,'ID','post_content',''],
 'wp_posts_excerpt'=>[$wpdb->posts,'ID','post_excerpt',''],
 'wp_postmeta'=>[$wpdb->postmeta,'post_id','meta_value','meta_key'],
 'wp_termmeta'=>[$wpdb->termmeta,'term_id','meta_value','meta_key'],
 'wp_usermeta'=>[$wpdb->usermeta,'user_id','meta_value','meta_key'],
];
foreach($surfaces as $label=>$args){
 [$table,$id,$value,$key]=$args;
 $sql='SELECT '.$id.' AS item_id'.($key?','.$key.' AS key_name':'').' FROM '.$table.' WHERE '.$value.' LIKE %s LIMIT 35';
 $rs=$wpdb->get_results($wpdb->prepare($sql,$like),ARRAY_A);
 if($wpdb->last_error)throw new RuntimeException('Cross-tenant scan error');
 $cross[$label]=array_map(static fn($r)=>['record_id'=>(int)$r['item_id'],
   'field_key'=>$r['key_name']??null],$rs);
}
$extra=[
 'snippets'=>['id','code','name'],
 'fluentform_form_meta'=>['form_id','value','meta_key'],
 'icl_strings'=>['id','value','name'],
 'icl_string_translations'=>['id','value',''],
];
foreach($extra as $name=>$meta){
 if(!$has($name))continue;
 [$id,$v,$key]=$meta;
 $cols=$wpdb->get_col('SHOW COLUMNS FROM '.$prefix.$name);
 if(!in_array($id,$cols,true)||!in_array($v,$cols,true))continue;
 $sql='SELECT '.$id.' AS item_id'.($key&&in_array($key,$cols,true)?','.$key.' AS field_key':'').
  ' FROM '.$prefix.$name.' WHERE '.$v.' LIKE %s LIMIT 35';
 $rs=$wpdb->get_results($wpdb->prepare($sql,$like),ARRAY_A);
 if($wpdb->last_error)throw new RuntimeException('Extra scan failed');
 $cross[$name]=array_map(static fn($r)=>['record_id'=>(int)$r['item_id'],'field_key'=>$r['field_key']??null],$rs);
}
$cron=get_option('cron',[]);
$hooks=[];
if(is_array($cron)){
 foreach($cron as $key=>$entries){
  if(!is_array($entries))continue;
  foreach($entries as $hook=>$jobs)$hooks[$hook]=($hooks[$hook]??0)+count((array)$jobs);
 }
}
arsort($hooks);
$out=['contract'=>'mad4b.company.data-source-surfaces.v1',
 'tenant'=>$tenant,'read_only'=>true,'raw_values_exported'=>false,
 'source_db_table_count'=>count($tables),'option_family_keys'=>$families,
 'important_settings_presence'=>$identity,'important_table_counts'=>$tableInventory,
 'other_tables_requiring_classification'=>$otherTables,'cross_tenant_reference_samples'=>$cross,
 'scheduled_hooks'=>array_slice($hooks,0,120,true),
 'limit_note'=>'cross-tenant source row samples capped; 35 or more indicates more than listed',
 'automatic_copy_authorized'=>false,'source_mutated'=>false];
echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
