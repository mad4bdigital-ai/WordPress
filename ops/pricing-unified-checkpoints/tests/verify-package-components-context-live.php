<?php
/**
 * WordPress-only, read-only live smoke for conditional Package Components.
 *
 * Run from site WordPress root:
 *   wp eval-file ops/pricing-unified-checkpoints/tests/verify-package-components-context-live.php
 *
 * Does NOT create/modify posts, meta, JetEngine schema, relations, or rates.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI)return;
$class='MAD4B_Package_Components_Editor_Context_V1';
if(!class_exists($class,false))WP_CLI::error('Live conditional Package Components MU plugin not loaded');
$base=home_url();
$wayfa=strpos($base,'wayfatours.com')!==false;
$source=strpos($base,'tours.dev.mad4b.com')!==false;
if(!$wayfa&&!$source)WP_CLI::error('Unexpected target WordPress host');

$cases=$wayfa
    ?[['package',57562,true],['not_package',60579,false],['standalone_cruise',60615,false]]
    :[['package',60629,true],['not_package',64850,false],['standalone_cruise',60729,false]];

$admin=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if(!$admin)WP_CLI::error('Read-only test admin context unavailable');
wp_set_current_user((int)$admin[0]);
set_current_screen('tours-and-activities');
$tests=0;$results=[];
$check=static function(bool $ok,string $label)use(&$tests):void{
    if(!$ok)WP_CLI::error('PACKAGE_CONTEXT_FAIL '.$label);
    $tests++;
};
foreach($cases as [$label,$id,$expected]){
    $check(get_post_type($id)==='tours-and-activities','tour fixture '.$label);
    $view=$class::inspect($id);
    $check($view['show']===$expected,'conditional post type '.$label);
    global $wp_meta_boxes;
    $wp_meta_boxes=[];
    do_action('add_meta_boxes_tours-and-activities',get_post($id));
    $normal=$wp_meta_boxes['tours-and-activities']['normal']['high']??[];
    $exists=isset($normal['mad4b-package-components-context-v1']);
    $check($exists===$expected,'native context metabox registered '.$label);
    ob_start();
    $class::render(get_post($id));
    $html=(string)ob_get_clean();
    $dangerous=(bool)preg_match('/<(?:input|select|textarea|form)\\b/i',$html);
    $check(!$dangerous,'no duplicative input fields '.$label);
    if($expected){
        $check(strpos($html,'#mad4b-guide-itinerary-service')!==false,
            'original native guide editor shortcut');
        $check(strpos($html,'post.php?post=')!==false,
            'original Tour Rate editor shortcut');
        $check($view['cost']['evaluated']===false &&
                $view['cost']['verified']===null &&
                $view['cost']['amount_shown']===false &&
                $view['cost']['checkout_activation']===false,
            'not an approval or pricing editor');
        $check($view['related_tour_id_authority']==='tour-rates.postmeta' &&
                $view['tour_query_313_inventory_only']===true &&
                $view['new_relation_created']===false &&
                $view['new_meta_fields_created']===false,
            'single original relationship source');
        $check(($normal['mad4b-nile-program-first-readiness']??null)===false &&
                ($normal['mad4b-nile-native-publish-readiness']??null)===false,
            'irrelevant standalone cruise panels hidden for Packages');
    }else{
        $check($html==='','no irrelevant panel output '.$label);
    }
    $results[]=['case'=>$label,'id'=>$id,'visible'=>$exists,
        'read_only'=>!$dangerous];
}
if($wayfa){
    $ids=[57562,60437,60439,60441,60443,60623,60627,60629];
    $cruise=0;$rateCount=0;
    foreach($ids as $tour){
        $view=$class::inspect($tour);
        $check($view['show']===true,'all 8 package tour classification '.$tour);
        $cruise+=(int)$view['cruise']['show'];
        $rateCount+=(int)$view['linked_rate_count'];
    }
    $check($cruise===7,'cruise section shown for actual recorded stages only');
    // Historic Draft EN/ES rates were archived into WordPress Trash.
    // Two order-referenced EN drafts must survive; newly generated drafts may
    // increase the count later. The old 32-rate assertion is obsolete.
    $check($rateCount>=2 && get_post_status(60830)==='draft' &&
        get_post_status(61060)==='draft',
        'order-linked draft Tour Rates preserved after selective archive');
    $check($class::inspect(60627)['cruise']['show']===false,
        'no cruise tab on non-cruise package');
    $view=$class::inspect(60661);
    $check($view['show']===true && $view['canonical_tour_id']===60623,
        'source-language WPML fallback without duplicate guide');
}else{
    $view=$class::inspect(64948);
    $check($view['show']===true && $view['canonical_tour_id']===60629,
        'source-language WPML fallback');
}
WP_CLI::line(wp_json_encode([
    'contract'=>'mad4b.package-editor-context-smoke.v1',
    'site'=>$base,'tests_passed'=>$tests,'cases'=>$results,
    'write_performed'=>false,'checkout_changed'=>false,
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
