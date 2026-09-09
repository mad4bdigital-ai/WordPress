<?php
declare(strict_types=1);
function etg_ui_expect($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function etg_ui_has($needle,$haystack,$m){etg_ui_expect(false!==strpos($haystack,$needle),$m);}
$root=dirname(__DIR__);
$ui=file_get_contents($root.'/includes/Admin/AdminUi.php');
$assets=file_get_contents($root.'/includes/Admin/AdminAssets.php');
$css=file_get_contents($root.'/assets/css/admin-alpha13.css');
$responsiveCss=file_get_contents($root.'/assets/css/admin-shell-responsive.css');
$elementorCss=file_get_contents($root.'/assets/css/elementor-dynamic-tag-editor.css');
$registrar=file_get_contents($root.'/includes/Elementor/DynamicTagRegistrar.php');
$preview=file_get_contents($root.'/includes/Elementor/DynamicTags/PreviewContextTrait.php');
$filterImage=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterImageTag.php');
$filterGallery=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterGalleryTag.php');
$filterSlideshow=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterSlideshowTag.php');
$shell=file_get_contents($root.'/assets/js/admin-shell.js');
$dynamicJs=file_get_contents($root.'/assets/js/dynamic-content-admin.js');
$pages=array(
    'etg-filter-seo'=>'Control Center',
    'etg-dfsb-dynamic-content'=>'Dynamic Content',
    'etg-dfsb-media-lab'=>'Media Lab',
    'etg-dfsb-jetengine-inspector'=>'JetEngine Inspector',
    'etg-dfsb-inventory-control'=>'Inventory Control',
    'etg-filter-seo-publication'=>'SEO Publication',
    'etg-dfsb-usage-guide'=>'Usage Guide',
);
foreach($pages as$slug=>$label){etg_ui_has("'".$slug."'",$assets,'shared assets load on '.$slug);etg_ui_has("'".$slug."'",$shell,'shared browser shell maps '.$slug);etg_ui_has($label,$shell,'shared browser shell labels '.$slug);etg_ui_has($slug,$ui,'server product navigation maps '.$slug);}
foreach(array('DynamicContentPage.php','MediaLabPage.php','JetEngineInspectorPage.php','InventoryControlPage.php')as$file){$source=file_get_contents($root.'/includes/Admin/'.$file);etg_ui_has('AdminUi::renderHeader',$source,$file.' uses shared server header');etg_ui_has('AdminUi::renderTabs',$source,$file.' uses shared server tabs');}
$mediaLab=file_get_contents($root.'/includes/Admin/MediaLabPage.php');
foreach(array('Discovery & Mapping','Collection Modes','Preview & Trace','Elementor Recipes','Use as Image','Use as Gallery')as$label){etg_ui_has($label,$mediaLab,'Media Lab surface '.$label);}
etg_ui_has('authorizing',file_get_contents($root.'/includes/Presentation/MediaDiscoveryRegistry.php'),'media registry declares non-authorizing contract');
$dynamic=file_get_contents($root.'/includes/Admin/DynamicContentPage.php');
foreach(array('Overview','Slots','Combination Editor','Helpers & Recipes','Field Catalog','Runtime Tokens')as$label){etg_ui_has($label,$dynamic,'dynamic content tab '.$label);}
etg_ui_has('PresentationToken::normalize',$dynamic,'dynamic token seed preserves canonical token identity');
etg_ui_expect(false===strpos($dynamic,'strtolower(trim((string)wp_unslash('),'dynamic token seed cannot lowercase case-sensitive meta identity');
$inspector=file_get_contents($root.'/includes/Admin/JetEngineInspectorPage.php');
foreach(array('Diagnostic Lab','Query Builder','Relations','Fields & CCT','Context & Recipes')as$label){etg_ui_has($label,$inspector,'inspector tab '.$label);}
$inventory=file_get_contents($root.'/includes/Admin/InventoryControlPage.php');
foreach(array('Overview','Profile Plans','Safety Contract')as$label){etg_ui_has($label,$inventory,'inventory tab '.$label);}
foreach(array('.etg-product-nav','.etg-subtabs','.etg-table-tools','.etg-sticky-actions','.etg-editor-sections')as$needle){etg_ui_has($needle,$css,'shared CSS '.$needle);}
etg_ui_has('assets/css/admin-shell-responsive.css',$assets,'responsive admin shell override is enqueued');
etg_ui_has("'etg-dfsb-admin-shell-responsive'",$assets,'responsive admin shell has a dedicated style handle');
foreach(array('.etg-dfsb-admin .etg-product-nav','flex-wrap:wrap!important','overflow:visible!important','text-decoration:none!important','.etg-dfsb-admin .nav-tab-wrapper.etg-subtabs','.etg-media-lab .etg-panel__body>form.etg-actions','grid-template-columns:minmax(240px,420px) auto!important','overflow-x:auto!important')as$needle){etg_ui_has($needle,$responsiveCss,'responsive admin shell contract '.$needle);}
etg_ui_expect(false===strpos($responsiveCss,'position:fixed'),'admin shell hardening cannot introduce fixed overlays');
etg_ui_has('elementor/editor/after_enqueue_styles',$registrar,'Elementor editor UX stylesheet uses the supported editor style hook');
etg_ui_has('assets/css/elementor-dynamic-tag-editor.css',$registrar,'Elementor tag popup stylesheet is editor-only');
foreach(array('.elementor-tag-settings-popup','max-height:min(520px,calc(100vh - 140px))!important','overflow-y:auto!important','overscroll-behavior:contain','scrollbar-gutter:stable','@media(max-height:720px)')as$needle){etg_ui_has($needle,$elementorCss,'Elementor dynamic tag popup viewport contract '.$needle);}
etg_ui_has("'label' => 'Preview Filter URL'",$preview,'preview control uses compact label');
etg_ui_has("'placeholder' => '/tours-and-activities/jsf/…'",$preview,'preview control uses compact placeholder');
etg_ui_has('Editor preview only. Leave blank to use no synthetic filter context.',$preview,'preview help is concise');
etg_ui_has('<strong>Live:</strong>',$preview,'live parity notice remains visible but compact');
etg_ui_expect(false===strpos($preview,'Editor-only non-authorizing evidence. The gray example is a placeholder only'),'long preview explanation is no longer rendered');
etg_ui_has('Returns the first healthy image from this collection.',$filterImage,'image tag help is concise');
etg_ui_has('Returns healthy media from the selected collection.',$filterGallery,'gallery tag help is concise');
etg_ui_has('Returns a Gallery value. Animation stays in Elementor.',$filterSlideshow,'slideshow tag help is concise');
etg_ui_has('assets/js/admin-shell.js',$assets,'shared admin shell enqueued');
etg_ui_has('assets/js/dynamic-content-admin.js',$assets,'dynamic content behavior enqueued');
foreach(array('listing_field','listing_meta','term_field','term_meta','context','repeater','query','relation','relation_meta')as$type){etg_ui_has($type,$dynamicJs,'dynamic source UX supports '.$type);}
etg_ui_has("set('source_aggregate','json')",$dynamicJs,'related-cards preset uses a runtime-valid aggregate');
etg_ui_expect(false===strpos($dynamicJs,"set('source_aggregate','list')"),'admin presets cannot emit unsupported list aggregate');
etg_ui_has('enhanceLegacyShell',$shell,'shared JS upgrades legacy Operational/Publication/Guide shells');
etg_ui_has('enhanceLegacyHeader',$shell,'shared JS normalizes legacy page headers');
etg_ui_has('installAutoTableTools',$shell,'shared JS adds bounded table tooling to long legacy tables');
etg_ui_has('data-etg-table-search',$shell,'shared table search behavior present');
etg_ui_has('data-etg-collapsible',$shell,'shared collapsible behavior present');
etg_ui_expect(false===strpos($shell,'pushState')&&false===strpos($shell,'replaceState'),'shared admin shell cannot mutate browser history');
etg_ui_expect(false===strpos($dynamicJs,'pushState')&&false===strpos($dynamicJs,'replaceState'),'dynamic admin UX cannot mutate browser history');
etg_ui_expect(false===strpos($ui,'update_option('),'shared admin UI cannot mutate configuration');
echo "Alpha13 shared admin UI, responsive navigation and Elementor tag-popup smoke tests passed.\n";
