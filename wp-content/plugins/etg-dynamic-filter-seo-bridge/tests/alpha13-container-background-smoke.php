<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_title($value){return trim(preg_replace('/[^a-z0-9\-_]+/','-',strtolower((string)$value)),'-');}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
function esc_url_raw($value){return filter_var((string)$value,FILTER_VALIDATE_URL)?(string)$value:'';}
function etg_bg_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_bg_has($needle,$haystack,$message){etg_bg_expect(false!==strpos($haystack,$needle),$message);}

$fixture=array();
function get_post_type($id){return 'attachment';}
function wp_attachment_is_image($id){return true;}
function wp_get_attachment_image_url($id,$size='full'){global $fixture;return isset($fixture[(int)$id]['url'])?$fixture[(int)$id]['url']:false;}
function get_attached_file($id){global $fixture;return isset($fixture[(int)$id]['file'])?$fixture[(int)$id]['file']:'';}
function wp_get_attachment_metadata($id){global $fixture;$row=$fixture[(int)$id]??array();return array('width'=>(int)($row['width']??0),'height'=>(int)($row['height']??0));}
function get_term($id,$taxonomy){$map=array(11=>'cairo');if(!isset($map[(int)$id])){return null;}$o=new stdClass();$o->term_id=(int)$id;$o->slug=$map[(int)$id];$o->taxonomy=$taxonomy;return$o;}
function get_term_by($field,$value,$taxonomy){return null;}

$root=dirname(__DIR__);
require_once $root.'/includes/Presentation/MediaAssetValidator.php';
require_once $root.'/includes/Presentation/ContentSlotRegistry.php';
require_once $root.'/includes/Elementor/ContainerDynamicBackground.php';
require_once $root.'/includes/Presentation/PresentationResolver.php';
require_once $root.'/includes/JetSmartFilters/AjaxFilterStateParser.php';

use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Elementor\ContainerDynamicBackground;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;

$modes=ContentSlotRegistry::mediaModes();
etg_bg_expect(count($modes)===7,'seven governed collection modes remain available');
$registry=new ContentSlotRegistry();
foreach(array_keys($modes)as$mode){
    $image=$registry->get(ContentSlotRegistry::backgroundSlotId('image',$mode));
    $gallery=$registry->get(ContentSlotRegistry::backgroundSlotId('gallery',$mode));
    etg_bg_expect(($image['type']??'')==='image'&&($gallery['type']??'')==='gallery','virtual background slot types: '.$mode);
    etg_bg_expect(($image['media_mode']??'')===$mode&&($gallery['media_mode']??'')===$mode,'virtual slots preserve collection mode: '.$mode);
    etg_bg_expect(($gallery['authorizing']??true)===false&&($gallery['internal']??false)===true,'virtual slots remain internal/non-authorizing: '.$mode);
}
etg_bg_expect(count($registry->defaults())===8,'internal background slots stay outside editable Dynamic Content defaults');

$extension=file_get_contents($root.'/includes/Elementor/ContainerDynamicBackground.php');
$resolverSource=file_get_contents($root.'/includes/Presentation/PresentationResolver.php');
$endpoint=file_get_contents($root.'/includes/Presentation/AjaxPresentationEndpoint.php');
$helper=file_get_contents($root.'/assets/js/ajax-taxonomy-reconcile.js');
$bridge=file_get_contents($root.'/assets/js/ajax-filter-state.js');
$bootstrap=file_get_contents($root.'/includes/Bootstrap.php');
$css=file_get_contents($root.'/assets/css/container-dynamic-background.css');
$js=file_get_contents($root.'/assets/js/container-dynamic-background.js');
$main=file_get_contents($root.'/etg-dynamic-filter-seo-bridge.php');
$shortcodes=file_get_contents($root.'/includes/Elementor/Shortcodes.php');
$listing=file_get_contents($root.'/includes/JetEngine/ListingIntegration.php');
$filterValue=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterValueTag.php');
$termField=file_get_contents($root.'/includes/Elementor/DynamicTags/TermFieldTag.php');
$tagRuntime=file_get_contents($root.'/includes/Elementor/DynamicTags/DynamicTagRuntime.php');
$contentSlot=file_get_contents($root.'/includes/Elementor/DynamicTags/ContentSlotTag.php');
$contentSlotUrl=file_get_contents($root.'/includes/Elementor/DynamicTags/ContentSlotUrlTag.php');
$inventoryValue=file_get_contents($root.'/includes/Elementor/DynamicTags/InventoryValueTag.php');
$inventoryUrl=file_get_contents($root.'/includes/Elementor/DynamicTags/InventoryUrlTag.php');
$registrar=file_get_contents($root.'/includes/Elementor/DynamicTagRegistrar.php');
$editorCss=file_get_contents($root.'/assets/css/elementor-dynamic-tag-editor.css');
$adminCss=file_get_contents($root.'/assets/css/admin-shell-responsive.css');

foreach(array('ETG Dynamic Background','ETG Dynamic Image','ETG Dynamic Slideshow','Slideshow Source','ETG Collection (Recommended)','Preview Filter URL','Autoplay','Pause on Hover','data-etg-dfsb-media-slot',"'data-etg-dfsb-media-target' => 'gallery'")as$needle){etg_bg_has($needle,$extension,'container extension contract: '.$needle);}
foreach(array('Background Suitability','Slideshow Playback','Wide Hero','When No Suitable Image Exists','suitabilityPolicy','filterSuitableItems','data-etg-dfsb-background-suitability','data-etg-dfsb-background-playback')as$needle){etg_bg_has($needle,$extension,'background suitability/playback contract: '.$needle);}
etg_bg_has('MediaAssetValidator::inspect',$extension,'manual Elementor attachment IDs use shared media health');
etg_bg_has('MediaAssetValidator::inspect',$resolverSource,'all ID-based resolver media uses shared media health');
etg_bg_has("'presentation_state_complete'",$resolverSource,'presentation resolver recognizes taxonomy-presentation completeness independently');
etg_bg_has('ajax-taxonomy-reconcile.js',$endpoint,'AJAX endpoint loads URL/currentQuery reconciliation before presentation bridge');
etg_bg_has("'result_query_complete'",$endpoint,'endpoint separates presentation readiness from full result-query completeness');
etg_bg_has('ajaxFilters/updated',$helper,'reconciliation observes real JetSmartFilters AJAX lifecycle');
etg_bg_has("name.indexOf('_tax_query_')",$helper,'reconciliation replaces stale taxonomy state only');
etg_bg_has('window.setTimeout(function () { reconcile(provider, queryId); }, 10)',$helper,'reconciliation retries after URL mutation but before 25ms presentation send');
etg_bg_has('new ContainerDynamicBackground($this->presentation,$slots)',$bootstrap,'Bootstrap owns container extension with shared slot registry');
etg_bg_expect(false===strpos($main,'new ETG\\DynamicFilterSEOBridge\\Elementor\\ContainerDynamicBackground'),'plugin entrypoint does not create a second container composition root');
etg_bg_has("ETG_DFSB_BOOT_BUILD', 'alpha13-container-background-4'",$main,'Safe Boot generation advances for background/runtime replacement');
etg_bg_has("ETG_DFSB_ASSET_VERSION', '0.4.0-alpha.13-build4'",$main,'changed admin/editor/runtime assets have an explicit cache-bust build');

$parser=new AjaxFilterStateParser(array('location_jet'));
$partial=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>array(11),'_meta_query_price'=>'100')));
etg_bg_expect(($partial['presentation_state_complete']??false)===true,'supported taxonomy presentation remains complete when non-tax result filter exists');
etg_bg_expect(($partial['filtered_query_complete']??true)===false,'full result query remains incomplete for unsupported meta filter');
etg_bg_expect(in_array('native_meta_query',(array)$partial['unsupported_filter_props'],true),'unsupported result filter remains explicit');

foreach(array('hydrateSlide','IntersectionObserver',"document.addEventListener('visibilitychange'",'sourceItems','responsiveBreakpoints','elementConnected','items.length < minimum','suitabilityPolicy','imageSuitable','suitableGallery','used_fallback')as$needle){etg_bg_has($needle,$js,'background runtime hardening: '.$needle);}
etg_bg_has("playback(state.element) === 'static'",$js,'static playback collapses slideshow runtime to first eligible image');
etg_bg_expect(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'background runtime cannot mutate browser history');
etg_bg_has(':where(.etg-dfsb-dynamic-background)',$css,'ETG positioning uses zero-specificity fallback so Elementor positioning can win');
etg_bg_has('z-index: -1',$css,'ETG stage sits behind native Elementor/UAE children');
etg_bg_has('contain: paint',$css,'background stage owns paint containment');
etg_bg_expect(false===strpos($css,'> :not(.etg-dfsb-background-stage)'),'ETG must not rewrite arbitrary third-party child z-index');
etg_bg_expect(false===strpos($css,'.elementor-background-overlay'),'ETG must not take ownership of Elementor overlay stacking');
etg_bg_expect(false===strpos($css,'will-change:'),'ETG must not permanently promote every slide layer');

etg_bg_has('etg-dfsb-dynamic-background-shortcode',$shortcodes,'background shortcode owns a namespace distinct from Container slideshow runtime');
etg_bg_expect(false===strpos($shortcodes,"classList((string) \$atts['class'], 'etg-dfsb-dynamic-background')"),'shortcode cannot collide with Container slideshow selector');
etg_bg_has("array_key_exists('presentation_state_complete', \$c)",$shortcodes,'shortcodes use taxonomy presentation completeness for AJAX parity');
etg_bg_has('MediaAssetValidator::inspect',$listing,'JetEngine Filter Context validates projected attachment IDs');
etg_bg_has('healthyMediaIds',$listing,'JetEngine Filter Context validates projected galleries');
etg_bg_has('URL Dynamic Tags are resolved at Elementor render time.',$filterValue,'URL Dynamic Tags do not advertise impossible live host-attribute mutation');
etg_bg_has("array('field!' => 'image_url')",$termField,'Term image URL hides text-style AJAX controls');
etg_bg_has('slotOptionsByTypes',$tagRuntime,'Dynamic Tag runtime supports type-scoped slot selectors');
etg_bg_has("slotOptionsByTypes(array('text','html'))",$contentSlot,'generic scalar Content Slot exposes only live-capable text/HTML slots');
etg_bg_has("get_categories(){ return array('url'); }",$contentSlotUrl,'dedicated Content Slot URL tag owns URL category');
etg_bg_has("tokenOptionsByTypes(array('text','html'))",$inventoryValue,'Inventory Value exposes only live-capable text/HTML tokens');
etg_bg_has("tokenOptionsByTypes(array('url'))",$inventoryUrl,'dedicated Inventory URL tag owns URL tokens');
etg_bg_has('ContentSlotUrlTag::class',$registrar,'registrar exposes dedicated Content Slot URL tag');
etg_bg_has('InventoryUrlTag::class',$registrar,'registrar exposes dedicated Inventory URL tag');
etg_bg_has("'/meta/'",$bridge,'pretty URL state recognizes non-taxonomy segment terminators');
etg_bg_has('taxEnd',$bridge,'pretty URL tax parsing is bounded before following meta/search/sort segments');
etg_bg_has('presentationComplete = data.presentation_state_complete === true',$bridge,'browser applies presentation completeness independently from result-query completeness');
etg_bg_has('initialSemanticKeys',$bridge,'browser remembers whether SSR started from a filtered state');
etg_bg_has('clearTransient',$bridge,'browser has a fail-closed stale presentation reset path');
etg_bg_has("restored_initial: false",$bridge,'filtered-origin clear explicitly avoids restoring stale SSR term content');
etg_bg_has("clearTransient('filters_cleared', key)",$bridge,'clearing an initially filtered page blanks stale dynamic presentation');
etg_bg_has('max-height:min(520px,calc(100vh - 140px))',$editorCss,'Elementor Dynamic Tag popup is viewport bounded');
etg_bg_has('max-height:4.2em',$editorCss,'long Dynamic Tag descriptions cannot consume the whole editor viewport');
etg_bg_has('.etg-toolbar',$adminCss,'shared admin shell has responsive toolbar layout');

$healthyFile=tempnam(sys_get_temp_dir(),'etg-bg-');
$missingFile=$healthyFile.'-missing';
$fixture[101]=array('url'=>'https://example.test/uploads/healthy.jpg','file'=>$healthyFile,'width'=>1600,'height'=>900);
$fixture[102]=array('url'=>'https://example.test/uploads/orphan.jpg','file'=>$missingFile,'width'=>900,'height'=>1600);
$containerReflection=new ReflectionClass(ContainerDynamicBackground::class);
$container=$containerReflection->newInstanceWithoutConstructor();
$normalize=$containerReflection->getMethod('normalizeImage');$normalize->setAccessible(true);
$healthy=$normalize->invoke($container,array('id'=>101,'url'=>'https://example.test/uploads/stale-healthy.jpg'));
$orphan=$normalize->invoke($container,array('id'=>102,'url'=>'https://example.test/uploads/stale-orphan.jpg'));
$urlOnly=$normalize->invoke($container,array('id'=>0,'url'=>'https://cdn.example.test/hero.webp','width'=>1800,'height'=>1000,'aspect_ratio'=>1.8));
etg_bg_expect(($healthy['id']??0)===101&&($healthy['url']??'')===$fixture[101]['url'],'healthy attachment uses canonical inspected URL');
etg_bg_expect(($healthy['width']??0)===1600&&($healthy['height']??0)===900,'healthy attachment preserves validated dimensions');
etg_bg_expect(($orphan['id']??-1)===0&&($orphan['url']??'')==='','orphaned attachment cannot be revived by stale Elementor URL');
etg_bg_expect(($urlOnly['id']??-1)===0&&($urlOnly['url']??'')==='https://cdn.example.test/hero.webp','URL-only dynamic media remains supported');

$policyMethod=$containerReflection->getMethod('suitabilityPolicy');$policyMethod->setAccessible(true);
$filterMethod=$containerReflection->getMethod('filterSuitableItems');$filterMethod->setAccessible(true);
$wide=$policyMethod->invoke($container,array('etg_dfsb_background_suitability'=>'wide'));
etg_bg_expect(($wide['min_width']??0)===1200&&abs((float)($wide['min_ratio']??0)-1.5)<0.0001,'Wide Hero has deterministic minimum width and ratio');
$eligible=$filterMethod->invoke($container,array(
    array('id'=>1,'url'=>'https://example.test/wide.jpg','width'=>1600,'height'=>900,'aspect_ratio'=>1.7778),
    array('id'=>2,'url'=>'https://example.test/portrait.jpg','width'=>900,'height'=>1600,'aspect_ratio'=>0.5625),
    array('id'=>3,'url'=>'https://example.test/small.jpg','width'=>800,'height'=>450,'aspect_ratio'=>1.7778),
),$wide);
etg_bg_expect(count($eligible)===1&&($eligible[0]['id']??0)===1,'Wide Hero excludes portrait and undersized media');
$custom=$policyMethod->invoke($container,array('etg_dfsb_background_suitability'=>'custom','etg_dfsb_background_min_width'=>1500,'etg_dfsb_background_min_height'=>800,'etg_dfsb_background_min_ratio'=>1.6));
$customEligible=$filterMethod->invoke($container,$eligible,$custom);
etg_bg_expect(count($customEligible)===1,'custom suitability accepts media meeting all thresholds');

$resolverReflection=new ReflectionClass(PresentationResolver::class);
$resolver=$resolverReflection->newInstanceWithoutConstructor();
$mediaArray=$resolverReflection->getMethod('mediaArray');$mediaArray->setAccessible(true);
$resolved=$mediaArray->invoke($resolver,array(101,102));
etg_bg_expect(count($resolved)===1&&($resolved[0]['id']??0)===101,'central presentation media array drops orphaned attachment IDs');
etg_bg_expect(($resolved[0]['width']??0)===1600&&($resolved[0]['height']??0)===900,'presentation media exposes read-only dimensions for suitability consumers');
@unlink($healthyFile);

$node='';if(function_exists('shell_exec')){$candidate=@shell_exec('command -v node 2>/dev/null');if(is_string($candidate)){$node=trim($candidate);}}
if(''!==$node){$cmd=escapeshellarg($node).' '.escapeshellarg($root.'/tests/alpha13-browser-ajax-reset-smoke.js');passthru($cmd,$nodeCode);etg_bg_expect(0===$nodeCode,'AJAX browser clear/stale behavior smoke passes when Node is available');}

echo "Alpha13 container, AJAX presentation, background policy and cross-surface hardening smoke tests passed.\n";
