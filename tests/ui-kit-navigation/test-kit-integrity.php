<?php
/**
 * Isolated contract: Elementor Kit save / WordPress metadata / company activation.
 * php tests/ui-kit-navigation/test-kit-integrity.php
 */
namespace Elementor {
    class Plugin {
        public static $instance;
        public $kits_manager;
    }
}
namespace {
    define('ABSPATH', __DIR__);
    $GLOBALS['handlers']=[];
    $GLOBALS['kit_id']=60050;
    $GLOBALS['kit_settings']=[
        'system_colors'=>[
            ['_id'=>'primary','color'=>'#102D40'],['_id'=>'secondary','color'=>'#204C50'],
            ['_id'=>'text','color'=>'#102D40'],['_id'=>'accent','color'=>'#B48A46'],
        ],
        'custom_colors'=>[
            ['_id'=>'bnav001','color'=>'#F6F2E9'],
            ['_id'=>'e14cd5e','color'=>'#F6AE22'],
        ],
    ];
    class WP_Error {
        private $code;private $message;private $data;
        public function __construct($code,$message,$data=[]){$this->code=$code;$this->message=$message;$this->data=$data;}
        public function get_error_code(){return $this->code;}
        public function get_error_message(){return $this->message;}
        public function get_error_data(){return $this->data;}
    }
    function is_wp_error($v){return $v instanceof WP_Error;}
    function add_filter($name,$fn,$priority=10,$accepted=1){$GLOBALS['handlers'][$name]=$fn;}
    function get_post_meta($id,$key,$single=true){return $id===60050&&$key==='_elementor_page_settings'?$GLOBALS['kit_settings']:null;}
    function assert_ok($condition,$name){if(!$condition){fwrite(STDERR,"FAIL: ".$name.PHP_EOL);exit(1);}}
    \Elementor\Plugin::$instance=new \Elementor\Plugin();
    \Elementor\Plugin::$instance->kits_manager=new class{
        public function get_active_id(){return 60050;}
    };
    require dirname(__DIR__,2).'/wp-content/mu-plugins/198-mad4b-elementor-kit-integrity.php';
    $good=$GLOBALS['kit_settings'];
    assert_ok(mad4b_kit_integrity_validate_palette($good)===true,'valid Kit');
    assert_ok(mad4b_kit_integrity_admission()===true,'active Kit admitted');
    assert_ok(is_wp_error(mad4b_kit_integrity_admission(70000)),'wrong Kit cannot pass');
    $invalid=$good;
    $invalid['custom_colors'][]=['_id'=>'e14cd5e','color'=>'#B48A46'];
    $reject=mad4b_kit_integrity_validate_palette($invalid);
    assert_ok(is_wp_error($reject)&&$reject->get_error_code()==='mad4b_kit_duplicate_color_id','duplicate IDs denied');
    try {mad4b_kit_integrity_enforce($invalid);assert_ok(false,'invalid must throw');} catch(\RuntimeException $e){
        assert_ok(strpos($e->getMessage(),'MAD4B_KIT_INTEGRITY_DENIED')!==false,'fail closed error');
    }
    $kitDocument=new class{
        public function get_type(){return 'kit';}
        public function get_id(){return 60050;}
    };
    $pageDocument=new class{
        public function get_type(){return 'page';}
        public function get_id(){return 60050;}
    };
    $editor=$GLOBALS['handlers']['elementor/document/save/data'];
    assert_ok($editor(['settings'=>$good],$kitDocument)['settings']===$good,'valid editor data retained');
    assert_ok($editor(['settings'=>$invalid],$pageDocument)['settings']===$invalid,'unrelated pages unaffected');
    try {$editor(['settings'=>$invalid],$kitDocument);assert_ok(false,'editor invalid accepted');}
    catch(\RuntimeException $e){assert_ok(strpos($e->getMessage(),'DENIED')!==false,'editor save blocked');}
    $metadata=$GLOBALS['handlers']['update_post_metadata'];
    assert_ok($metadata(null,60050,'_elementor_page_settings',$good,null)===null,'valid metadata update');
    assert_ok($metadata(null,1000,'_elementor_page_settings',$invalid,null)===null,'unrelated post metadata');
    try {$metadata(null,60050,'_elementor_page_settings',$invalid,null);assert_ok(false,'metadata invalid accepted');}
    catch(\RuntimeException $e){assert_ok(strpos($e->getMessage(),'DENIED')!==false,'post metadata write blocked');}
    $add=$GLOBALS['handlers']['add_post_metadata'];
    try {$add(null,60050,'_elementor_page_settings',$invalid,true);assert_ok(false,'new Kit metadata invalid accepted');}
    catch(\RuntimeException $e){assert_ok(strpos($e->getMessage(),'DENIED')!==false,'add metadata denied');}
    $route=$GLOBALS['handlers']['rest_request_before_callbacks'];
    $request=function($name,$method){
        return new class($name,$method){
            private $route;private $method;
            public function __construct($route,$method){$this->route=$route;$this->method=$method;}
            public function get_route(){return $this->route;}
            public function get_method(){return $this->method;}
        };
    };
    assert_ok($route(null,[],$request('/mad4b/v1/company-activation/commit','POST'))===null,'valid activation allowed');
    $GLOBALS['kit_settings']=$invalid;
    $blocked=$route(null,[],$request('/mad4b/v1/company-activation/commit','POST'));
    assert_ok(is_wp_error($blocked) && $blocked->get_error_code()==='mad4b_company_kit_admission_denied','invalid company activation blocked');
    assert_ok($route(null,[],$request('/mad4b/v1/company-activation/plan','GET'))===null,'plan is readable');
    assert_ok($route(null,[],$request('/some/other/route','POST'))===null,'unrelated route unaffected');
    echo "PASS: active Kit integrity, editor saves, WP metadata pre-write and company activation pre-callback deny/allow\n";
}
