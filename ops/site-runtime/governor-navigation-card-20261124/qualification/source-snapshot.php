<?php
if(!defined('WP_CLI')||!WP_CLI)exit(9);
$paths=['mad4b-render-query-governor.php','mad4b-selective-render-policy.json','449-mad4b-native-nav-fragment-cache.php','447-mad4b-navigation-native-ssr-guard.php','414-mad4b-stable-listing-css-identity.php'];
$files=[];foreach($paths as $p)$files[$p]=hash_file('sha256',WPMU_PLUGIN_DIR.'/'.$p);
$templates=[];
foreach([67124,67125] as $id){
 $post=get_post($id);$raw=get_post_meta($id,'_elementor_data',true);$css=get_post_meta($id,'_elementor_css',true);
 if(is_array($css))unset($css['time']);
 $templates[$id]=['published'=>$post&&$post->post_status==='publish','sha256'=>hash('sha256',(string)$raw),
 'listing'=>get_post_meta($id,'_listing_data',true),'css_sha256'=>hash('sha256',serialize($css))];
}
$opts=[];foreach(['service-activation','destination-activation','product_navigation_sources','_native_destinations_elementor_source_id','icl_sitepress_settings','elementor_active_kit','mad4b_render_query_market_partition_mode','mad4b_render_query_data_generation'] as $k)$opts[$k]=hash('sha256',serialize(get_option($k)));
$policy=json_decode((string)file_get_contents(WPMU_PLUGIN_DIR.'/mad4b-selective-render-policy.json'),true);
echo wp_json_encode(['origin'=>home_url('/'),'environment'=>wp_get_environment_type(),'generation'=>(string)get_option('mad4b_render_query_data_generation'),'files'=>$files,'templates'=>$templates,'dependencies'=>$opts,'native_policy_templates'=>array_keys($policy['templates']??[]),'authority_receipts_present'=>isset($policy['navigation_card_receipts'])]);
