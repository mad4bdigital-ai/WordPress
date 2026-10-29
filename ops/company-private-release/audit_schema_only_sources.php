<?php
/** Read-only schema/option KEY audit. No account contents, user metadata or secrets. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('CLI only');
global $wpdb;
$tenant=(string)getenv('MAD4B_TENANT_DOMAIN');
if(!in_array($tenant,['allroyalegypt.com','egypttourgates.com'],true))throw new RuntimeException('Unknown tenant');
$tables=$wpdb->get_col($wpdb->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=%s',DB_NAME));
$families=['elementor','jet','fluent','woocommerce','wc_','rank_math','trustindex','litespeed','quic','gtm','sitekit',
 'wpml','icl','snippet','pixel','facebook','google','social','smtp','mail','cache','license','payment','booking','review'];
$optionKeys=$wpdb->get_col('SELECT option_name FROM '.$wpdb->options.' ORDER BY option_name');
$fields=[];
foreach($families as $family){
 $names=array_values(array_filter($optionKeys,static fn($key)=>stripos($key,$family)!==false));
 $fields[$family]=['count'=>count($names),'keys'=>array_slice($names,0,70)];
}
$pluginTables=[];
foreach($tables as $table){
 if(!str_starts_with($table,$wpdb->prefix))continue;
 $suffix=substr($table,strlen($wpdb->prefix));
 if(preg_match('/jet|fluent|wc_|woocommerce|icl|wpml|rank_math|trustindex|snippets|mail|smtp|social|pixel|actionscheduler|analytics|booking|order|payment|review|option|metadata|cache/i',$suffix)){
  $pluginTables[]=$suffix;
 }
}
$active=array_map('strval',(array)get_option('active_plugins',[]));
sort($active);
$types=$wpdb->get_results('SELECT post_type,COUNT(*) AS n FROM '.$wpdb->posts.' GROUP BY post_type ORDER BY post_type',ARRAY_A);
$termTax=$wpdb->get_results('SELECT taxonomy,COUNT(*) AS n FROM '.$wpdb->term_taxonomy.' GROUP BY taxonomy ORDER BY taxonomy',ARRAY_A);
$hooks=[];
$cron=get_option('cron',[]);
if(is_array($cron))foreach($cron as $stamp=>$jobs){
 if(!is_array($jobs))continue;
 foreach($jobs as $hook=>$events)$hooks[(string)$hook]=true;
}
ksort($hooks);
echo wp_json_encode([
 'contract'=>'mad4b.company.source-schema-metadata.v1','read_only'=>true,
 'raw_values_exported'=>false,'tenant'=>$tenant,'table_total'=>count($tables),
 'options_total'=>count($optionKeys),'option_key_families'=>$fields,
 'integration_table_names'=>$pluginTables,'active_plugin_paths'=>$active,
 'post_type_counts'=>$types,'taxonomy_counts'=>$termTax,
 'scheduled_cron_hook_names'=>array_slice(array_keys($hooks),0,150),
 'no_migration_or_activation_authorized'=>true,'mutations'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
