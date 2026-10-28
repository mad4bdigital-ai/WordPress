<?php
/** Read-only staging preflight: run via php stdin, no file writes. */
if(PHP_SAPI!=='cli')exit(70);
$root = getenv('MAD4B_WORDPRESS_ROOT') ?: getcwd();
require rtrim($root,'/').'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$required=[
 'elementor/elementor.php'=>'4.3.5',
 'elementor-pro/elementor-pro.php'=>'4.3.2',
 'jet-engine/jet-engine.php'=>'3.8.15.2',
 'jet-smart-filters/jet-smart-filters.php'=>'3.8.5.1',
 'woocommerce/woocommerce.php'=>'11.2.1',
 'sitepress-multilingual-cms/sitepress.php'=>'4.9.5',
];
$all=get_plugins();$active=get_option('active_plugins',[]);$matrix=[];$blockers=[];
foreach($required as$slug=>$reference){$installed=$all[$slug]['Version']??null;$enabled=in_array($slug,$active,true);
 $matrix[$slug]=['reference'=>$reference,'installed'=>$installed,'active'=>$enabled];
 if(!$installed)$blockers[]='missing_plugin:'.$slug;
 elseif(!$enabled)$blockers[]='plugin_not_active:'.$slug;
 elseif(version_compare($installed,$reference,'<'))$blockers[]='plugin_below_lab_baseline:'.$slug;
}
$realUrl=home_url();
if(!str_starts_with($realUrl,'https://'))$blockers[]='public_origin_not_https';
$muSource=file_exists(WPMU_PLUGIN_DIR.'/shared-product-core-loader.php');
if(!$muSource)$blockers[]='shared_product_core_not_installed';
if(!in_array(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,['8.3','8.5'],true))$blockers[]='php_not_tested';
$out=['read_only'=>true,'wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'environment'=>wp_get_environment_type(),
 'public_url'=>$realUrl,'plugins'=>$matrix,'active_plugin_count'=>count($active),'shared_core_present'=>$muSource,
 'blockers'=>$blockers,'verdict'=>$blockers?'BLOCKED':'READY_FOR_INTEGRATION_TEST'];
echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($blockers?2:0);
