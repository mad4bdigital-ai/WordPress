<?php
/**
 * Read-only MAD4B JetEngine Options Page coverage scan for WP-CLI:
 * wp eval-file .../qa/options-page-coverage-scan.php --skip-themes --user=1
 *
 * Only aggregate counts, no field values or names / auth secrets.
 * Never runs a native Save and never changes business configuration.
 */
if(!class_exists('MAD4B_JetEngine_Field_Modes_V1')||
   !class_exists('Jet_Engine_Options_Page_Factory')){
    echo wp_json_encode(['pass'=>false,'reason'=>'JetEngine/Recipe unavailable']).PHP_EOL;
    return;
}
$manager=jet_engine()->options_pages;
if(empty($manager->registered_pages))$manager->register_pages();
$definitions=MAD4B_JetEngine_Field_Modes_V1::pages();
$results=[];
global $wp_scripts,$wp_styles;
foreach($definitions as $slug=>$page){
    $originalPage=$_GET['page']??null;
    $actualSlug=$slug==='etg-dynamic-pricing-controls'
        ? 'dynamic-pricing-controls' : $slug;
    $_GET['page']=$actualSlug;
    $wp_scripts=new WP_Scripts();
    $wp_styles=new WP_Styles();
    if(class_exists('MAD4B_Pricing_Contextual_Help_V1'))
        MAD4B_Pricing_Contextual_Help_V1::assets();
    if(class_exists('MAD4B_Provider_Price_Sources_V1'))
        MAD4B_Provider_Price_Sources_V1::assets();
    MAD4B_JetEngine_Field_Modes_V1::enqueue();
    $registered=isset($wp_scripts->registered['mad4b-jetengine-functional-modes']);
    $depsOk=$wp_scripts->all_deps(['mad4b-jetengine-functional-modes'],false,false);
    ob_start();
    $wp_scripts->do_items(['mad4b-jetengine-functional-modes']);
    $scriptHtml=ob_get_clean();
    $scriptOk=$registered && $depsOk
       && str_contains($scriptHtml,'functional-modes.js')
       && str_contains($scriptHtml,'MAD4BJetModePage');
    $recipeOk=wp_script_is('mad4b-jetengine-recipe-registry','enqueued')
       && wp_style_is('mad4b-jetengine-field-recipe','enqueued');
    $native=$manager->registered_pages[$slug]??null;
    $domCount=null;$definitionCount=null;$unmappedCount=null;$error=null;
    if($native){
        try{
            $native->init_builder();
            $definitionCount=count($native->get_prepared_fields());
            ob_start();
            $native->render_page();
            $html=ob_get_clean();
            preg_match_all('/data-control-name="([^"]+)"/',$html,$matches);
            $allControls=array_unique($matches[1]);
            $domCount=count($allControls);
            $unmappedCount=count(array_diff($allControls,array_keys($page['fields'])));
            unset($html);
        }catch(Throwable $e){
            if(ob_get_level()>0)ob_end_clean();
            $error=get_class($e);
        }
    }
    $results[$slug]=[
        'native_page_present'=>(bool)$native,
        'registered_fields'=>$definitionCount,
        'rendered_controls'=>$domCount,
        'mode_config_fields'=>count($page['fields']),
        'functional_mode_prints'=>$scriptOk,
        'recipe_assets_queued'=>$recipeOk,
        'unmapped_count'=>$unmappedCount,
        'error'=>$error,
    ];
    if($originalPage===null)unset($_GET['page']);else $_GET['page']=$originalPage;
}
$failures=[];
foreach($results as $slug=>$row)
    if(!$row['native_page_present']||!$row['functional_mode_prints']
       ||!$row['recipe_assets_queued']||$row['error']!==null)
        $failures[]=$slug;
echo wp_json_encode([
    'pass'=>!$failures,
    'pages'=>count($results),
    'failure_count'=>count($failures),
    'failed_slugs'=>$failures,
    'results'=>$results,
    'evidence_scope'=>'WordPress native SSR + JS graph. Not browser interaction, visual layout or authenticated Save.',
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
