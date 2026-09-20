<?php
declare(strict_types=1);
function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
$GLOBALS['etg_main_post_type']='page';
function get_query_var($key){return 'post_type'===$key?$GLOBALS['etg_main_post_type']:null;}
function expect_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAILED: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}}
class EtgFakeQuery {
    public $id; public $query_id; public $type='posts'; public $postType='property';
    public function __construct($type='posts',$postType='property',$queryId='properties_archive',$id=41){$this->type=$type;$this->postType=$postType;$this->query_id=$queryId;$this->id=$id;}
    public function get_query_type(){return $this->type;}
    public function get_query_args(){return array('post_type'=>$this->postType);}
}
class EtgFakeManager {
    public static $instance; public $queries=array();
    public static function instance(){if(!self::$instance){self::$instance=new self();}return self::$instance;}
    public function get_queries(){return $this->queries;}
}
class_alias('EtgFakeManager','Jet_Engine\\Query_Builder\\Manager');
require_once dirname(__DIR__).'/includes/JetEngine/QueryIdentityResolver.php';
require_once dirname(__DIR__).'/includes/Runtime/PostTypeObserver.php';
use ETG\DynamicFilterSEOBridge\Runtime\PostTypeObserver;
$manager=EtgFakeManager::instance();
$observer=new PostTypeObserver();
$parsed=array('provider'=>'jet-engine','query_id'=>'properties_archive');
$profile=array('require_post_type_binding'=>true,'post_type_authority'=>'query_builder','post_types'=>array('property'));
$manager->queries['properties_archive']=new EtgFakeQuery('posts','property');
$r=$observer->observe($parsed,$profile);
expect_same(true,$r['observed'],'query builder post type observed');
expect_same(true,$r['matches_profile'],'query builder allowed post type matches');
expect_same('query_builder',$r['source'],'query builder is source');
expect_same(array('property'),$r['post_types'],'exact query builder post type captured');
expect_same('properties_archive',$r['sources']['query_builder']['query_id'],'custom Query ID remains observation authority');
expect_same('41',$r['sources']['query_builder']['internal_query_id'],'internal Query Builder ID is evidence only');

$manager->queries['mixed_case']=new EtgFakeQuery('posts','property','myGrid',42);
$manager->queries['mixed_case_lower']=new EtgFakeQuery('posts','property','mygrid',43);
$parsedMixed=array('provider'=>'jet-engine','query_id'=>'myGrid');
$r=$observer->observe($parsedMixed,$profile);
expect_same(true,$r['observed'],'mixed-case Query ID remains observable');
expect_same(true,$r['matches_profile'],'mixed-case Query ID preserves post type authority');
expect_same('myGrid',$r['sources']['query_builder']['query_id'],'post type observer preserves mixed-case provider Query ID');
expect_same('myGrid',$r['sources']['query_builder']['query_builder_query_id'],'post type observer resolves exact mixed-case Query Builder identity');
expect_same('42',$r['sources']['query_builder']['internal_query_id'],'mixed-case observer resolves the correct internal query');

$manager->queries['properties_archive']=new EtgFakeQuery('posts',array('property','product'));
$r=$observer->observe($parsed,$profile);
expect_same(false,$r['matches_profile'],'mixed allowed/unallowed post types fail closed');
expect_same('post_type_mismatch',$r['reason'],'mixed query post types mismatch');
$manager->queries['properties_archive']=new EtgFakeQuery('posts','any');
$r=$observer->observe($parsed,$profile);
expect_same(false,$r['observed'],'post_type any is not accepted as authority');
expect_same('post_type_unbounded',$r['reason'],'unbounded post type reason');
$manager->queries['properties_archive']=new EtgFakeQuery('terms','property');
$r=$observer->observe($parsed,$profile);
expect_same(false,$r['observed'],'non-post query cannot prove post type');
expect_same('query_type_not_posts',$r['reason'],'non-post query type reason');
$manager->queries['properties_archive']=new EtgFakeQuery('posts','property');
$profile['post_type_authority']='main_query';
$GLOBALS['etg_main_post_type']='page';
$r=$observer->observe($parsed,$profile);
expect_same(true,$r['observed'],'main query can be explicit authority');
expect_same(false,$r['matches_profile'],'main query mismatch is visible');
$profile['post_type_authority']='either';
$GLOBALS['etg_main_post_type']='page';
$r=$observer->observe($parsed,$profile);
expect_same(true,$r['matches_profile'],'either prefers observed query builder authority');
$profile['post_type_authority']='both';
$GLOBALS['etg_main_post_type']='property';
$r=$observer->observe($parsed,$profile);
expect_same(true,$r['matches_profile'],'both requires matching authorities');
$GLOBALS['etg_main_post_type']='page';
$r=$observer->observe($parsed,$profile);
expect_same(false,$r['matches_profile'],'both detects authority disagreement/outside allowlist');
fwrite(STDOUT,"Post type authority smoke tests passed.\n");
