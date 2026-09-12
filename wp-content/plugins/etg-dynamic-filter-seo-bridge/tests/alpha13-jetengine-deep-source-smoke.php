<?php
declare(strict_types=1);

namespace Jet_Engine\Query_Builder {
    class Manager {
        public static $instance; public $queries=array(); public $options=array();
        public static function instance(){if(!self::$instance){self::$instance=new self();}return self::$instance;}
        public function get_queries_for_options(){return$this->options;}
        public function get_query_by_id($id){return$this->queries[(int)$id]??null;}
    }
}

namespace Jet_Engine\Modules\Custom_Content_Types {
    class FakeDB {
        public function query($args,$limit,$offset,$order){
            return array(array('_ID'=>77,'title'=>'CCT Item 77','HeroImage'=>909,'heroimage'=>910));
        }
    }
    class FakeType {
        public $db;
        public function __construct(){$this->db=new FakeDB();}
        public function get_arg($key){return'name'===$key?'Tour Cards':'';}
        public function get_fields_list($scope='all',$format='blocks'){return array('HeroImage'=>array('name'=>'HeroImage','title'=>'Hero Image','type'=>'media'),'Slides'=>array('name'=>'Slides','title'=>'Slides','type'=>'repeater'));}
    }
    class FakeManager {
        public $type;
        public function __construct(){$this->type=new FakeType();}
        public function get_content_types($slug=null){return null===$slug?array('tour_cards'=>$this->type):('tour_cards'===$slug?$this->type:null);}
    }
    class Module {
        public static $instance; public $manager;
        public function __construct(){$this->manager=new FakeManager();}
        public static function instance(){if(!self::$instance){self::$instance=new self();}return self::$instance;}
    }
}

namespace {
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function absint($v){return abs((int)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function attachment_url_to_postid($url){return 0;}
function apply_filters($tag,$value){return$value;}
function get_term_meta($id,$key,$single=true){$map=array(10=>array('HeroImage'=>811,'heroimage'=>812));return$map[(int)$id][$key]??'';}

class DeepListingData {
    public $object;
    public function __construct(){$this->object=array('ID'=>42,'HeroImage'=>701,'heroimage'=>702);}
    public function get_current_object(){return$this->object;}
    public function get_prop($key,$object=null){$object=$object??$this->object;return is_array($object)&&array_key_exists($key,$object)?$object[$key]:'';}
    public function get_meta($key,$object=null){return$this->get_prop($key,$object);}
}
class DeepRelation {
    private $id;private $parent;private $child;
    public function __construct($id,$parent,$child){$this->id=$id;$this->parent=$parent;$this->child=$child;}
    public function get_args($key){$map=array('id'=>$this->id,'name'=>'Deep Relation '.$this->id,'parent_object'=>$this->parent,'child_object'=>$this->child,'meta_fields'=>array(array('name'=>'PriorityScore','title'=>'Priority Score','type'=>'text')));return$map[$key]??'';}
    public function get_children($id,$field){return 4===$this->id?array(77):array();}
    public function get_parents($id,$field){return array();}
    public function get_meta($parent,$child,$key){return'PriorityScore'===$key?'high':'';}
}
class DeepRelations {
    public $relations;
    public function __construct(){$this->relations=array(4=>new DeepRelation(4,'posts::tours','cct::tour_cards'));}
    public function get_active_relations($id=null){return null===$id?$this->relations:($this->relations[(int)$id]??null);}
}
$GLOBALS['deep_engine']=(object)array('listings'=>(object)array('data'=>new DeepListingData()),'relations'=>new DeepRelations());
function jet_engine(){return$GLOBALS['deep_engine'];}

class RecursiveQuery {
    public function get_items(){return$GLOBALS['deep_query_runner']->items(88,20);}
}

function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function expect_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}

$root=dirname(__DIR__);
require_once $root.'/includes/Identifiers/FieldKey.php';
require_once $root.'/includes/JetEngine/ValueNormalizer.php';
require_once $root.'/includes/JetEngine/ListingContextResolver.php';
require_once $root.'/includes/JetEngine/QueryRunner.php';
require_once $root.'/includes/JetEngine/RelationResolver.php';
require_once $root.'/includes/JetEngine/FieldDiscovery.php';
require_once $root.'/includes/Presentation/ContentSourceResolver.php';

use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;
use ETG\DynamicFilterSEOBridge\JetEngine\ValueNormalizer;
use ETG\DynamicFilterSEOBridge\JetEngine\ListingContextResolver;
use ETG\DynamicFilterSEOBridge\JetEngine\QueryRunner;
use ETG\DynamicFilterSEOBridge\JetEngine\RelationResolver;
use ETG\DynamicFilterSEOBridge\JetEngine\FieldDiscovery;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSourceResolver;

expect_same('HeroImage',FieldKey::normalize('HeroImage'),'field key identity preserves case');
expect_same('heroimage',FieldKey::normalize('heroimage'),'lower-case field remains distinct');
expect_same('',FieldKey::normalize('bad/key'),'unsafe field key rejected');

$n=new ValueNormalizer();$listing=new ListingContextResolver($n);
expect_same(701,$listing->meta('HeroImage'),'listing meta keeps upper-case identity');
expect_same(702,$listing->meta('heroimage'),'case-only listing meta remains distinct');

$queries=new QueryRunner();$GLOBALS['deep_query_runner']=$queries;
\Jet_Engine\Query_Builder\Manager::instance()->options=array(88=>'Recursive Query');
\Jet_Engine\Query_Builder\Manager::instance()->queries[88]=new RecursiveQuery();
$recursive=$queries->items(88,20);
expect_same(array(),$recursive,'recursive Query Builder source fails closed instead of recursing');
expect_true(!empty($queries->describe(88)['guarded']),'query source declares recursion guard');

$relations=new RelationResolver();$options=$relations->options();
expect_true(isset($options[4]),'CCT relation discovered');
expect_same('Priority Score',$options[4]['meta_fields']['PriorityScore'],'relation meta field discovery preserves key case');
$items=$relations->items(4,42,'children',10);
expect_same(1,count($items),'CCT relation child hydrated');
expect_same(77,(int)$items[0]['_ID'],'CCT row identity preserved');
expect_same(909,(int)$items[0]['HeroImage'],'CCT field hydrated from CCT DB');
expect_same(array('high'),$relations->metaValues(4,42,'children','PriorityScore',10),'relation edge meta resolved');

$fields=new FieldDiscovery($n,$listing);$fieldCatalog=$fields->catalog();
expect_true(isset($fieldCatalog['cct']['tour_cards']),'CCT type appears in field discovery');
$cctMedia=false;foreach($fieldCatalog['media'] as$field){if('jetengine_cct'===($field['source']??'')&&'HeroImage'===($field['key']??'')){$cctMedia=true;break;}}
expect_true($cctMedia,'CCT media field discovered with exact-case key');

$sources=new ContentSourceResolver($listing,$queries,$relations,$n,$fields);
$context=array('terms'=>array('location'=>array('term_id'=>10,'slug'=>'cairo')));
expect_same('811',$sources->value(array('alias'=>'term_img','type'=>'term_meta','role'=>'location','meta_key'=>'HeroImage','aggregate'=>'image'),$context),'taxonomy meta exact-case source resolves');
expect_same('909',$sources->value(array('alias'=>'cct_img','type'=>'relation','relation_id'=>4,'direction'=>'children','field'=>'HeroImage','aggregate'=>'image'),$context),'related CCT media source resolves');
expect_same('high',$sources->value(array('alias'=>'priority','type'=>'relation_meta','relation_id'=>4,'direction'=>'children','meta_key'=>'PriorityScore','aggregate'=>'first'),$context),'relation meta source resolves');
$diag=$sources->diagnose(array('alias'=>'bad_relation','type'=>'relation_meta','relation_id'=>999,'meta_key'=>'PriorityScore'),$context);
expect_same(false,$diag['available'],'missing relation diagnostic fails closed');
expect_same('relation_not_found',$diag['reason'],'missing relation diagnostic reason exposed');
expect_same(false,$sources->catalog()['authorizing'],'deep source catalog is non-authorizing');

echo "Alpha13 JetEngine deep source contracts passed.\n";
}
