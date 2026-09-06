<?php
declare(strict_types=1);
function etg_ui_expect($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function etg_ui_has($needle,$haystack,$m){etg_ui_expect(false!==strpos($haystack,$needle),$m);}
$root=dirname(__DIR__);
$ui=file_get_contents($root.'/includes/Admin/AdminUi.php');
$assets=file_get_contents($root.'/includes/Admin/AdminAssets.php');
$css=file_get_contents($root.'/assets/css/admin-alpha13.css');
$shell=file_get_contents($root.'/assets/js/admin-shell.js');
$dynamicJs=file_get_contents($root.'/assets/js/dynamic-content-admin.js');
$pages=array(
    'etg-filter-seo'=>'Control Center',
    'etg-dfsb-dynamic-content'=>'Dynamic Content',
    'etg-dfsb-jetengine-inspector'=>'JetEngine Inspector',
    'etg-dfsb-inventory-control'=>'Inventory Control',
    'etg-filter-seo-publication'=>'SEO Publication',
    'etg-dfsb-usage-guide'=>'Usage Guide',
);
foreach($pages as$slug=>$label){etg_ui_has("'".$slug."'",$assets,'shared assets load on '.$slug);etg_ui_has("'".$slug."'",$shell,'shared browser shell maps '.$slug);etg_ui_has($label,$shell,'shared browser shell labels '.$slug);etg_ui_has($slug,$ui,'server product navigation maps '.$slug);}
foreach(array('DynamicContentPage.php','JetEngineInspectorPage.php','InventoryControlPage.php')as$file){$source=file_get_contents($root.'/includes/Admin/'.$file);etg_ui_has('AdminUi::renderHeader',$source,$file.' uses shared server header');etg_ui_has('AdminUi::renderTabs',$source,$file.' uses shared server tabs');}
$dynamic=file_get_contents($root.'/includes/Admin/DynamicContentPage.php');
foreach(array('Overview','Slots','Combination Editor','Helpers & Recipes','Field Catalog','Runtime Tokens')as$label){etg_ui_has($label,$dynamic,'dynamic content tab '.$label);}
etg_ui_has('PresentationToken::normalize',$dynamic,'dynamic token seed preserves canonical token identity');
etg_ui_expect(false===strpos($dynamic,'strtolower(trim((string)wp_unslash('),'dynamic token seed cannot lowercase case-sensitive meta identity');
$inspector=file_get_contents($root.'/includes/Admin/JetEngineInspectorPage.php');
foreach(array('Diagnostic Lab','Query Builder','Relations','Fields & CCT','Context & Recipes')as$label){etg_ui_has($label,$inspector,'inspector tab '.$label);}
$inventory=file_get_contents($root.'/includes/Admin/InventoryControlPage.php');
foreach(array('Overview','Profile Plans','Safety Contract')as$label){etg_ui_has($label,$inventory,'inventory tab '.$label);}
foreach(array('.etg-product-nav','.etg-subtabs','.etg-table-tools','.etg-sticky-actions','.etg-editor-sections')as$needle){etg_ui_has($needle,$css,'shared CSS '.$needle);}
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
echo "Alpha13 shared admin UI shell smoke tests passed.\n";
