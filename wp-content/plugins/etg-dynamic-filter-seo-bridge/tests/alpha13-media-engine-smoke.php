<?php
declare(strict_types=1);
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function absint($v){return abs((int)$v);}
function get_option($name,$default=array()){global $etg_media_test_option;return 'etg_dfsb_media_discovery'===$name?$etg_media_test_option:$default;}
function etg_media_expect($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function etg_media_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}
$root=dirname(__DIR__);
require_once $root.'/includes/Identifiers/FieldKey.php';
require_once $root.'/includes/Presentation/MediaDiscoveryRegistry.php';
require_once $root.'/includes/Content/GalleryComposer.php';
require_once $root.'/includes/Presentation/ContentSlotRegistry.php';
use ETG\DynamicFilterSEOBridge\Presentation\MediaDiscoveryRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;

$registry=new MediaDiscoveryRegistry();
$clean=$registry->sanitize(array(
    'image'=>array('HeroImage','heroimage','thumbnail_id'),
    'gallery'=>array('HeroGallery','hero_gallery'),
    'taxonomies'=>array('location_jet'=>array('image'=>array('LocationCover'),'gallery'=>array('LocationSlides'))),
));
etg_media_same(array('HeroImage','heroimage','thumbnail_id'),$clean['image'],'media registry preserves case-distinct field identity');
etg_media_same(array('LocationCover'),$clean['taxonomies']['location_jet']['image'],'taxonomy image key preserves case');
$etg_media_test_option=$clean;
$keys=$registry->keysForTaxonomy('location_jet');
etg_media_same(array('LocationCover','HeroImage','heroimage','thumbnail_id'),$keys['image'],'taxonomy-specific image keys precede global scan keys');
etg_media_same(array('LocationSlides','HeroGallery','hero_gallery'),$keys['gallery'],'taxonomy-specific gallery keys precede global scan keys');
etg_media_same(false,$registry->export()['authorizing'],'media registry is non-authorizing');

$styleA=array('term_id'=>300,'name'=>'Cultural','slug'=>'cultural','image_id'=>30,'gallery_ids'=>array(30,31,32),'media_sources'=>array('image'=>array(array('key'=>'StyleHero','ids'=>array(30))),'gallery'=>array(array('key'=>'StyleGallery','ids'=>array(31,32)))));
$styleB=array('term_id'=>400,'name'=>'Luxury','slug'=>'luxury','image_id'=>40,'gallery_ids'=>array(40,41,42),'media_sources'=>array('image'=>array(array('key'=>'StyleHeroAlt','ids'=>array(40))),'gallery'=>array(array('key'=>'StyleGalleryAlt','ids'=>array(41,42)))));
$location=array('term_id'=>100,'name'=>'Cairo','slug'=>'cairo','image_id'=>10,'gallery_ids'=>array(10,11,12),'media_sources'=>array('image'=>array(array('key'=>'LocationCover','ids'=>array(10))),'gallery'=>array(array('key'=>'LocationSlides','ids'=>array(11,12)))));
$tourType=array('term_id'=>200,'name'=>'Half Day','slug'=>'half-day','image_id'=>20,'gallery_ids'=>array(20,21),'media_sources'=>array('image'=>array(array('key'=>'TypeHero','ids'=>array(20))),'gallery'=>array(array('key'=>'TypeGallery','ids'=>array(21)))));
$context=array(
    'terms'=>array('style'=>$styleA,'location'=>$location,'tour_type'=>$tourType),
    'term_sets'=>array('style'=>array($styleA,$styleB),'location'=>array($location),'tour_type'=>array($tourType)),
    'profile'=>array('taxonomy_rules'=>array(
        'location_jet'=>array('role'=>'location','priority'=>10,'gallery_priority'=>20),
        'tour-types_jet'=>array('role'=>'tour_type','priority'=>20,'gallery_priority'=>30),
        'tour-styles_jet'=>array('role'=>'style','priority'=>30,'gallery_priority'=>10),
    )),
);
$gallery=new GalleryComposer();
etg_media_same(array(30,31,32,40,41,42),$gallery->ids($context,'priority'),'priority aggregates every selected Term inside first gallery-priority role');
etg_media_same(array(30,31,32,40,41,42,10,11,12,20,21),$gallery->ids($context,'combined'),'combined merges all active selected Terms in gallery priority order');
etg_media_same(array(31,32,41,42,11,12,21),$gallery->ids($context,'galleries_only'),'galleries-only excludes every primary term image');
etg_media_same(array(30,40,10,20),$gallery->ids($context,'primary_images'),'primary-images returns one primary per selected Term, not one per role');
etg_media_same(array(30,40,10,20,31,41,11,21,32,42,12),$gallery->ids($context,'balanced'),'balanced round-robins across individual selected Term buckets');
etg_media_same(array(10,11,12,20,21,30,31,32,40,41,42),$gallery->ids($context,'role_priority'),'role-priority uses normal profile role priority and preserves all selected Terms');
etg_media_same(array(30,31,32,40,41,42),$gallery->ids($context,'style'),'role-only mode includes all selected Terms for that role');
$trace=$gallery->trace($context,'balanced',30);
etg_media_same('etg.dfsb.media-trace.v1',$trace['contract'],'media trace contract remains backwards-compatible');
etg_media_same(false,$trace['authorizing'],'media trace non-authorizing');
$traceJson=json_encode($trace);
etg_media_expect(false!==strpos($traceJson,'LocationSlides'),'trace preserves exact media Meta key source');
etg_media_expect(false!==strpos($traceJson,'StyleGalleryAlt')&&false!==strpos($traceJson,'"term_id":400'),'trace identifies individual multi-select Terms and their exact source keys');

$modes=ContentSlotRegistry::mediaModes();
foreach(array('combined','all_terms','priority','galleries_only','primary_images','balanced','role_priority')as$mode){etg_media_expect(isset($modes[$mode]),'slot media modes expose '.$mode);}
$slotRegistry=file_get_contents($root.'/includes/Presentation/ContentSlotRegistry.php');
etg_media_expect(false!==strpos($slotRegistry,"'media_mode'=>'priority'"),'hero image default media mode');
etg_media_expect(false!==strpos($slotRegistry,"'media_mode'=>'combined'"),'hero gallery default media mode');
etg_media_expect(false!==strpos($slotRegistry,'isset(self::mediaModes()[$mediaMode])'),'slot media mode is allowlisted');
$filterImage=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterImageTag.php');
$filterGallery=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterGalleryTag.php');
$slideshow=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterSlideshowTag.php');
$registrar=file_get_contents($root.'/includes/Elementor/DynamicTagRegistrar.php');
$bootstrap=file_get_contents($root.'/includes/Bootstrap.php');
$inspector=file_get_contents($root.'/includes/Presentation/MediaInspector.php');
$discovery=file_get_contents($root.'/includes/Admin/AdminDiscoveryController.php');
$assets=file_get_contents($root.'/includes/Admin/AdminAssets.php');
$discoveryJs=file_get_contents($root.'/assets/js/admin-discovery.js');
foreach(array('galleries_only','primary_images','balanced','role_priority')as$mode){etg_media_expect(false!==strpos($filterImage,$mode),'Filter Image mode '.$mode);etg_media_expect(false!==strpos($filterGallery,$mode),'Filter Gallery mode '.$mode);etg_media_expect(false!==strpos($slideshow,$mode),'Slideshow mode '.$mode);}
etg_media_expect(false!==strpos($slideshow,'GALLERY_CATEGORY'),'slideshow returns Elementor-native gallery data');
etg_media_expect(false!==strpos($registrar,'FilterSlideshowTag::class'),'slideshow tag registered');
etg_media_expect(false!==strpos($bootstrap,'new TermMetaReader($mediaRegistry)'),'runtime injects Media Discovery Registry into TermMetaReader');
etg_media_expect(false!==strpos($bootstrap,'new MediaLabPage'),'Media Lab registered');
etg_media_expect(false!==strpos($bootstrap,'new AdminDiscoveryController'),'fetch-first admin discovery controller registered');
etg_media_expect(false===strpos($slideshow,'update_option('),'slideshow tag cannot mutate configuration');

etg_media_expect(false!==strpos($inspector,'scanTaxonomyMetadata'),'taxonomy metadata supports fetch-first all-safe-meta discovery');
etg_media_expect(false!==strpos($inspector,"'etg.dfsb.taxonomy-metadata-catalog.v1'"),'metadata catalog has explicit contract');
etg_media_expect(false!==strpos($inspector,'sample_values'),'metadata fetch exposes bounded human-readable samples');
etg_media_expect(false!==strpos($inspector,'sensitive($key)'),'sensitive-looking Meta keys stay excluded');
foreach(array('wp_ajax_','check_ajax_referer','manage_options','etg.dfsb.admin-metadata-fetch.v1','relation_meta_requires_runtime_edge_context','ContentSlotRegistry::mediaModes()')as$needle){etg_media_expect(false!==strpos($discovery,$needle),'admin discovery contract: '.$needle);}
etg_media_expect(false!==strpos($discovery,"'authorizing'=>false"),'admin metadata fetch is non-authorizing');
etg_media_expect(false!==strpos($assets,'assets/js/admin-discovery.js'),'shared fetch picker is enqueued');
etg_media_expect(false!==strpos($assets,'wp_localize_script'),'admin discovery uses nonce-scoped localized transport');
etg_media_expect(false!==strpos($discoveryJs,'Fetch Metadata'),'fetch-first UX is visible');
etg_media_expect(false!==strpos($discoveryJs,'Advanced manual key'),'manual key is advanced fallback only');
etg_media_expect(false!==strpos($discoveryJs,'Add Recommended Media'),'Media Lab can stage recommended mappings without IDs');
etg_media_expect(false!==strpos($discoveryJs,'mediaSlotsAction')&&false!==strpos($discoveryJs,'saveMediaModeAction'),'Media Slot modes use fetched labels and explicit save');
etg_media_expect(false===strpos($discoveryJs,'history.pushState')&&false===strpos($discoveryJs,'history.replaceState'),'admin discovery cannot mutate history');

echo "Alpha13 advanced media engine and fetch-first metadata UX smoke tests passed.\n";
