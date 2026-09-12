<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
function listing_item_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function listing_item_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: {$message}\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$GLOBALS['etg_listing_item_source_meta']=array();
function get_post_meta($postId,$key,$single=true){
    $postId=(int)$postId;
    if(isset($GLOBALS['etg_listing_item_source_meta'][$postId])&&array_key_exists($key,$GLOBALS['etg_listing_item_source_meta'][$postId])){
        return $GLOBALS['etg_listing_item_source_meta'][$postId][$key];
    }
    return $single?'':array();
}

$root=dirname(__DIR__);
require_once $root.'/includes/Diagnostics/ListingConfigurationInspector.php';
use ETG\DynamicFilterSEOBridge\Diagnostics\ListingConfigurationInspector;

$topology=array(
    'available'=>true,
    'bindings'=>array(array(
        'provider'=>'jet-engine',
        'provider_query_id'=>'property_archive',
        'status'=>'verified',
        'post_types'=>array('property'),
        'template_ids'=>array(9001),
    )),
);
$taxonomies=array(
    'property_type'=>array('object_type'=>array('property')),
);

$cleanGrid=function(array $idSetting):string{
    return json_encode(array(array(
        'id'=>'listing-grid',
        'elType'=>'widget',
        'widgetType'=>'jet-listing-grid',
        'settings'=>array_merge(array(
            '_element_id'=>'property_archive',
            'custom_query'=>'yes',
            'custom_query_id'=>'10',
            'custom_post_types'=>array('property'),
            'posts_query'=>array(array('_id'=>'property-tax','type'=>'tax_query','tax_query_taxonomy'=>'property_type')),
        ),$idSetting),
    )),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
};

/* Live Elementor uses the misspelled `lisitng_id`; the inspector must preserve support for it. */
$GLOBALS['etg_listing_item_source_meta'][9001]['_elementor_data']=$cleanGrid(array('lisitng_id'=>'37916'));
$GLOBALS['etg_listing_item_source_meta'][37916]['_listing_data']=json_encode(array('source'=>'posts','post_type'=>'tours-and-activities','tax'=>'category'));
$inspection=(new ListingConfigurationInspector())->inspectRoute('property_archive',$topology,$taxonomies);
listing_item_same(true,$inspection['available'],'verified route inspection is available');
listing_item_same(1,$inspection['drift_count'],'stale referenced Listing Item source is visible as one review finding');
$drift=$inspection['drift'][0];
listing_item_same('listing_item_source_post_type_mismatch',$drift['reason'],'referenced Listing Item source mismatch has explicit reason');
listing_item_same('warning',$drift['severity_hint'],'Listing Item source metadata remains advisory until runtime authority is proven');
listing_item_same('review',$drift['status'],'Listing Item source metadata is review evidence');
listing_item_same(37916,$drift['listing_id'],'live misspelled lisitng_id resolves referenced Listing Item');
listing_item_same(array('property'),$drift['expected_post_types'],'verified Query Builder post type remains expected authority');
listing_item_same(array('tours-and-activities'),$drift['observed_listing_source_post_types'],'stale Listing Item source post type is preserved');
listing_item_same('_listing_data',$drift['source_meta_key'],'evidence identifies the source metadata key');
listing_item_same(false,$drift['authorizing'],'Listing Item source evidence remains non-authorizing');

/* Matching referenced source must not create false-positive drift. */
$GLOBALS['etg_listing_item_source_meta'][37916]['_listing_data']=json_encode(array('source'=>'posts','post_type'=>'property','tax'=>'category'));
$clean=(new ListingConfigurationInspector())->inspectRoute('property_archive',$topology,$taxonomies);
listing_item_same(0,$clean['drift_count'],'matching referenced Listing Item source is clean');

/* Also accept the correctly spelled listing_id for forward/vendor compatibility. */
$GLOBALS['etg_listing_item_source_meta'][9001]['_elementor_data']=$cleanGrid(array('listing_id'=>'40352'));
$GLOBALS['etg_listing_item_source_meta'][40352]['_listing_data']=json_encode(array('source'=>'posts','post_type'=>'units','tax'=>'category'));
$alternate=(new ListingConfigurationInspector())->inspectRoute('property_archive',$topology,$taxonomies);
listing_item_same(1,$alternate['drift_count'],'correctly spelled listing_id is also inspected');
listing_item_same(40352,$alternate['drift'][0]['listing_id'],'correctly spelled listing_id preserves identity');
listing_item_same('warning',$alternate['drift'][0]['severity_hint'],'alternate-key mismatch remains advisory');

/* Non-post listing sources are outside this post-type comparison and are not guessed. */
$GLOBALS['etg_listing_item_source_meta'][40352]['_listing_data']=json_encode(array('source'=>'terms','post_type'=>'units','tax'=>'category'));
$nonPosts=(new ListingConfigurationInspector())->inspectRoute('property_archive',$topology,$taxonomies);
listing_item_same(0,$nonPosts['drift_count'],'non-post Listing Item source is not reinterpreted as posts authority');

echo "Alpha13 Listing Item source drift smoke tests passed.\n";
