<?php
declare(strict_types=1);

namespace Jet_Engine\Query_Builder {
    class Manager {
        public static $instance;
        public $queries=array();
        public static function instance(){if(!self::$instance){self::$instance=new self();}return self::$instance;}
        public function get_queries_for_options(){return array(5=>'Tours Slider',9=>'Related Cards');}
        public function get_query_by_id($id){return $this->queries[(int)$id]??null;}
    }
}

namespace {
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function absint($v){return abs((int)$v);}function wp_strip_all_tags($v){return strip_tags((string)$v);}function wp_kses_post($v){return(string)$v;}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}function esc_url_raw($v){return(string)$v;}function _n($s,$p,$n,$d=null){return 1===$n?$s:$p;}function home_url($p=''){return'https://example.test'.('/'===substr((string)$p,0,1)?$p:'/'.$p);}function apply_filters($tag,$value){return$value;}
$GLOBALS['etg_options']=array();function get_option($k,$d=array()){return$GLOBALS['etg_options'][$k]??$d;}function update_option($k,$v,$a=false){$GLOBALS['etg_options'][$k]=$v;return true;}
function wp_get_attachment_image_url($id,$size){return $id?'https://example.test/media/'.(int)$id.'.jpg':false;}function attachment_url_to_postid($url){return preg_match('~/media/(\d+)\.jpg~',(string)$url,$m)?(int)$m[1]:0;}
$GLOBALS['term_meta']=array(10=>array('hero_custom'=>301,'gallery_custom'=>array(302,303),'features'=>array(array('title'=>'Feature A','image'=>304),array('title'=>'Feature B','image'=>305))));function get_term_meta($id,$key,$single=true){return$GLOBALS['term_meta'][(int)$id][$key]??'';}
class WP_Post{public $ID;public $post_title;public $post_excerpt='';public $post_content='';public $post_name='';public $post_date='';public function __construct($id,$title){$this->ID=$id;$this->post_title=$title;}}
function get_post($id){return new WP_Post((int)$id,'Related '.(int)$id);}function get_permalink($p){return'https://example.test/tour/'.(int)$p->ID.'/';}function get_post_thumbnail_id($p){return 400+(int)$p->ID;}

class FakeListingData{
    public $object;
    public function __construct($o){$this->object=$o;}
    public function get_current_object(){return$this->object;}
    public function get_prop($key,$object=null){$object=$object??$this->object;if(is_array($object)&&array_key_exists($key,$object)){return$object[$key];}if(is_object($object)&&isset($object->{$key})){return$object->{$key};}return'';}
    public function get_meta($key,$object=null){return$this->get_prop($key,$object);}
}
class FakeMetaBoxes{
    public function get_fields_for_select($mode){return array(
        array('value'=>'hero_image','label'=>'Hero Image','type'=>'media'),
        array('value'=>'slides','label'=>'Slides','type'=>'repeater'),
        array('value'=>'api_secret','label'=>'API Secret','type'=>'text'),
    );}
}
class FakeRelation{
    public $lastObjectId=0;
    public function get_args($key){$map=array('id'=>3,'name'=>'Tours → Activities','parent_object'=>'posts::tours','child_object'=>'posts::tours');return$map[$key]??'';}
    public function get_children($id,$field){$this->lastObjectId=(int)$id;return array(7,8);}
    public function get_parents($id,$field){$this->lastObjectId=(int)$id;return array(11);}
}
class FakeRelations{
    public $relation;public function __construct(){$this->relation=new FakeRelation();}
    public function get_active_relations($id=null){return null===$id?array(3=>$this->relation):((int)$id===3?$this->relation:null);}
}
$GLOBALS['jet_engine_fake']=(object)array(
    'listings'=>(object)array('data'=>new FakeListingData(array('ID'=>42,'title'=>'Current Tour','hero'=>111,'slides'=>array(array('caption'=>'Slide A','image'=>112),array('caption'=>'Slide B','image'=>113))))),
    'relations'=>new FakeRelations(),
    'meta_boxes'=>new FakeMetaBoxes(),
);
function jet_engine(){return$GLOBALS['jet_engine_fake'];}
class FakeQuery{private $items;public function __construct($items){$this->items=$items;}public function get_items(){return$this->items;}}
\Jet_Engine\Query_Builder\Manager::instance()->queries[5]=new FakeQuery(array(array('ID'=>51,'title'=>'Query A','hero'=>201),array('ID'=>52,'title'=>'Query B','hero'=>202)));

function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}function expect_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}

$root=dirname(__DIR__);
require_once $root.'/includes/JetEngine/ValueNormalizer.php';require_once $root.'/includes/JetEngine/ListingContextResolver.php';require_once $root.'/includes/JetEngine/QueryRunner.php';require_once $root.'/includes/JetEngine/RelationResolver.php';require_once $root.'/includes/JetEngine/FieldDiscovery.php';require_once $root.'/includes/Presentation/ContentSourceResolver.php';require_once $root.'/includes/Presentation/ContentSlotRegistry.php';require_once $root.'/includes/Content/GalleryComposer.php';require_once $root.'/includes/Content/ContentComposer.php';require_once $root.'/includes/Identifiers/QueryId.php';require_once $root.'/includes/Presentation/PresentationResolver.php';require_once $root.'/includes/JetEngine/ListingIntegration.php';

use ETG\DynamicFilterSEOBridge\JetEngine\ValueNormalizer;use ETG\DynamicFilterSEOBridge\JetEngine\ListingContextResolver;use ETG\DynamicFilterSEOBridge\JetEngine\QueryRunner;use ETG\DynamicFilterSEOBridge\JetEngine\RelationResolver;use ETG\DynamicFilterSEOBridge\JetEngine\FieldDiscovery;use ETG\DynamicFilterSEOBridge\Presentation\ContentSourceResolver;use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;use ETG\DynamicFilterSEOBridge\Content\ContentComposer;use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;use ETG\DynamicFilterSEOBridge\JetEngine\ListingIntegration;

$n=new ValueNormalizer();$listing=new ListingContextResolver($n);$queries=new QueryRunner();$relations=new RelationResolver();$discovery=new FieldDiscovery($n,$listing);$sources=new ContentSourceResolver($listing,$queries,$relations,$n,$discovery);
expect_same('Current Tour',$sources->value(array('alias'=>'current','type'=>'listing_field','field'=>'title','aggregate'=>'first')),'current JetEngine listing field');
expect_same('Slide A, Slide B',$sources->value(array('alias'=>'slides','type'=>'repeater','meta_key'=>'slides','field'=>'caption','aggregate'=>'join','limit'=>10)),'repeater rows aggregate');
expect_same('Query A, Query B',$sources->value(array('alias'=>'query','type'=>'query','query_id'=>5,'field'=>'title','aggregate'=>'join')),'Query Builder items aggregate');
expect_same('201,202',$sources->value(array('alias'=>'query_images','type'=>'query','query_id'=>5,'field'=>'hero','aggregate'=>'gallery')),'Query Builder media aggregate');
expect_same('Related 7, Related 8',$sources->value(array('alias'=>'related','type'=>'relation','relation_id'=>3,'direction'=>'children','field'=>'post_title','aggregate'=>'join')),'relation children hydrate and aggregate');
$context=array('active'=>true,'in_scope'=>true,'scope_valid'=>true,'runtime_ready'=>true,'filters'=>array('location_jet'=>'cairo'),'provider_observation_matches_url'=>true,'terms'=>array('location'=>array('term_id'=>10,'name'=>'Cairo','slug'=>'cairo','image_id'=>0,'gallery_ids'=>array())),'profile'=>array('require_post_type_binding'=>false,'taxonomy_rules'=>array('location_jet'=>array('role'=>'location'))),'post_type_binding'=>array(),'unknown_filters'=>array(),'malformed'=>false,'missing_terms'=>array(),'translation_fallback'=>false);
expect_same('301',$sources->value(array('alias'=>'term_image','type'=>'term_meta','role'=>'location','meta_key'=>'hero_custom','aggregate'=>'image'),$context),'custom taxonomy image meta normalized');
expect_same('Feature A, Feature B',$sources->value(array('alias'=>'term_features','type'=>'repeater','role'=>'location','meta_key'=>'features','field'=>'title','aggregate'=>'join'),$context),'term-anchored repeater rows aggregate');
expect_same('Related 7, Related 8',$sources->value(array('alias'=>'term_related','type'=>'relation','role'=>'location','relation_id'=>3,'direction'=>'children','field'=>'post_title','aggregate'=>'join'),$context),'term-anchored relation aggregate');
expect_same(10,$GLOBALS['jet_engine_fake']->relations->relation->lastObjectId,'relation role anchors on selected term ID');

$fields=$discovery->catalog();expect_true(isset($fields['media']['jetengine_meta_box:hero_image']),'JetEngine media field discovered');expect_true(isset($fields['repeaters']['jetengine_meta_box:slides']),'JetEngine repeater field discovered');expect_true(!isset($fields['fields']['jetengine_meta_box:api_secret']),'sensitive meta key hidden from discovery');expect_true(isset($fields['fields']['current_listing_object:title']),'current listing fields discovered');expect_same(false,$fields['authorizing'],'field discovery is non-authorizing');

$slots=new ContentSlotRegistry();$save=$slots->save(array('id'=>'advanced_hero','label'=>'Advanced Hero','type'=>'image','template'=>'{{resolved}}','sources'=>array(array('alias'=>'missing','type'=>'term_meta','role'=>'location','meta_key'=>'missing','aggregate'=>'image'),array('alias'=>'term_image','type'=>'term_meta','role'=>'location','meta_key'=>'hero_custom','aggregate'=>'image'),array('alias'=>'query_image','type'=>'query','query_id'=>5,'field'=>'hero','aggregate'=>'image')),'fallback_chain'=>array('missing','term_image','query_image')));expect_true($save['saved'],'advanced slot saved');
$gallery=new GalleryComposer();$content=new ContentComposer($gallery);$resolver=new PresentationResolver(function()use($context){return$context;},$content,$gallery,$slots,null,$sources);
$image=$resolver->slotImage('advanced_hero',$context);expect_same(301,$image['id'],'slot fallback chain selects taxonomy media first');expect_same('https://example.test/media/301.jpg',$image['url'],'native media URL produced');
$rows=$resolver->slotRows('advanced_hero','query_image',$context);expect_same(2,count($rows),'slot rows expose query items for listing/repeater helpers');
$integration=new ListingIntegration($resolver);$repeater=$integration->repeaterRows(false,array('_css_classes'=>'foo etg-source--advanced_hero--query_image bar'));expect_same(2,count($repeater),'Dynamic Repeater CSS marker resolves slot source rows');
$catalog=$sources->catalog();expect_true(isset($catalog['queries'][5]),'Query Builder helper catalog available');expect_true(isset($catalog['relations'][3]),'Relation helper catalog available');expect_true(isset($catalog['field_discovery']['media']['jetengine_meta_box:hero_image']),'source catalog exposes field discovery');expect_same(false,$catalog['authorizing'],'source catalog is non-authorizing');

echo "Alpha13 JetEngine advanced content source smoke tests passed.\n";
}
