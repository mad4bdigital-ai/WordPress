<?php
// Read-only fixture + native WP/JetEngine checks. No writes or SEO publication.
if (!defined('WP_CLI') || !WP_CLI) throw new \RuntimeException('WP-CLI only');
if (!class_exists('MAD4B\\DFSB\\NativePresentation\\SharedContext')) {
    require_once dirname(__DIR__).'/mad4b-dfsb-native-presentation.php';
}
use MAD4B\DFSB\NativePresentation\SharedContext;

$native=array('resolved'=>true,'page_kind'=>'post_type_archive',
    'language'=>'en','title'=>'Tours','description'=>'Explore tours');
$rule=array('rule_matched'=>true,'has_filters'=>true,
    'provider'=>'jet-engine','query_id'=>'tours-grid',
    'title'=>'5-Day Nile Cruises from Luxor',
    'description'=>'Find selected Nile cruise tours');
$checks=array();
$a=SharedContext::compose($native);
$checks['native_title_fallback']=$a['title']==='Tours';
$checks['native_publisher_rankmath']=$a['seo_publisher']==='rank_math_native';
$b=SharedContext::compose($native,$rule);
$checks['filtered_title']=$b['title']==='5-Day Nile Cruises from Luxor';
$checks['filtered_description']=$b['description']==='Find selected Nile cruise tours';
$checks['provider_query_scope']=$b['provider']==='jet-engine' && $b['query_id']==='tours-grid';
$checks['jsf_owns_filtered_seo']=$b['seo_publisher']==='jetsmartfilters_native_rule';
$checks['no_seo_write']=$b['seo_mutation']===false;
$c=SharedContext::compose($native,array_merge($rule,array('has_filters'=>false)));
$checks['no_filter_no_override']=$c['title']==='Tours' && !$c['filter_rule_matched'];
$d=SharedContext::compose(array('resolved'=>false),$rule);
$checks['invalid_context_no_rule']=!$d['filter_rule_matched'] && $d['title']==='';
SharedContext::register();
$macros=apply_filters('jet-engine/listings/macros-list',array());
$checks['jetengine_macros_available']=isset($macros['mad4b_shared_title'],$macros['mad4b_shared_description'],$macros['mad4b_shared_language']);
$checks['shortcodes_available']=shortcode_exists('mad4b_shared_title') && shortcode_exists('mad4b_shared_description');
$checks['live_seo_not_modified']=!has_filter('rank_math/frontend/title',array(SharedContext::class,'project'))
    && !has_filter('rank_math/frontend/description',array(SharedContext::class,'project'));
foreach($checks as $name=>$pass) echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;
echo 'PASS_TOTAL='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
