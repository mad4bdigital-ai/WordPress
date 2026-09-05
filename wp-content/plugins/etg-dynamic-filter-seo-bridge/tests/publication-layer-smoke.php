<?php
$root=dirname(__DIR__);
$files=array(
    'config'=>file_get_contents($root.'/includes/Config/ProfileRegistryNormalizationTrait.php'),
    'policy'=>file_get_contents($root.'/includes/SEO/IndexingPolicy.php'),
    'registry'=>file_get_contents($root.'/includes/SEO/PublicationRegistry.php'),
    'count'=>file_get_contents($root.'/includes/SEO/PublicationResultCountProbe.php'),
    'metadata'=>file_get_contents($root.'/includes/RankMath/MetadataAdapter.php'),
    'sitemap'=>file_get_contents($root.'/includes/RankMath/PublicationSitemapProvider.php'),
    'registrar'=>file_get_contents($root.'/includes/RankMath/PublicationSitemapRegistrar.php'),
    'shortcodes'=>file_get_contents($root.'/includes/Elementor/Shortcodes.php'),
    'admin'=>file_get_contents($root.'/includes/Admin/PublicationPage.php'),
    'scope'=>file_get_contents($root.'/includes/Runtime/RequestScope.php'),
    'builder'=>file_get_contents($root.'/includes/Context/FilterContextBuilder.php'),
);
foreach($files as $name=>$content){if(!is_string($content)||''===$content){fwrite(STDERR,"missing:$name\n");exit(1);}}
$assert=function($condition,$message){if(!$condition){fwrite(STDERR,$message."\n");exit(1);}};
$assert(false!==strpos($files['config'],"'elementor_content_verified'"),'publication verification flag missing');
$assert(false!==strpos($files['policy'],'elementor_content_not_verified'),'Elementor fail-closed gate missing');
$assert(false!==strpos($files['scope'],'evaluateForEvidence'),'non-authorizing evidence scope missing');
$assert(false!==strpos($files['builder'],'buildEvidence'),'evidence context builder missing');
$assert(false!==strpos($files['registry'],'global_bridge_off'),'Global OFF sitemap exclusion missing');
$assert(false!==strpos($files['registry'],'profile_bound_publication_signature_required'),'profile-bound publication signature missing');
$assert(false!==strpos($files['registry'],'sitemap_included'),'publication sitemap decision missing');
$assert(false!==strpos($files['count'],'jet_engine_query_builder_background_tax_query'),'background result-count authority missing');
$assert(false!==strpos($files['sitemap'],'RankMath\\Sitemap\\Providers\\Provider'),'Rank Math sitemap provider missing');
$assert(false!==strpos($files['registrar'],"rank_math/sitemap/providers"),'Rank Math provider registration missing');
$assert(false!==strpos($files['registrar'],'invalidate_storage'),'sitemap freshness invalidation missing');
foreach(array(
    'rank_math/opengraph/facebook/og_title',
    'rank_math/opengraph/facebook/og_description',
    'rank_math/opengraph/twitter/twitter_title',
    'rank_math/opengraph/twitter/twitter_description',
    'rank_math/opengraph/twitter/image',
    'rank_math/json_ld',
    'wpml_hreflangs'
) as $hook){$assert(false!==strpos($files['metadata'],$hook),'missing metadata hook:'.$hook);}
foreach(array('etg_filter_term','etg_filter_term_section','etg_filter_sections') as $shortcode){$assert(false!==strpos($files['shortcodes'],$shortcode),'missing Elementor shortcode:'.$shortcode);}
$assert(false!==strpos($files['admin'],'Elementor Theme Builder support'),'Elementor publication admin guidance missing');
$assert(false!==strpos($files['admin'],'Rank Math dynamic sitemap'),'sitemap admin visibility missing');
$assert(false!==strpos($files['builder'],'tour-styles_jet'),'Production taxonomy role alignment missing');
$assert(false===strpos($files['builder'],"'tour-style_jet'=>'style'"),'stale singular style taxonomy remains');
echo "publication-layer-smoke:ok\n";
