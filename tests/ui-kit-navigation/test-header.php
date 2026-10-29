<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
define('WPMU_PLUGIN_DIR', __DIR__);
$GLOBALS['handlers'] = ['filter'=>[],'action'=>[]];
$GLOBALS['locale'] = 'en';
function add_filter($name,$cb,$priority=10,$argc=1){$GLOBALS['handlers']['filter'][$name][]=$cb;}
function add_action($name,$cb,$priority=10){$GLOBALS['handlers']['action'][$name][]=$cb;}
function get_locale(){return $GLOBALS['locale'].'_US';}
function apply_filters($name,$value){return $name==='wpml_current_language'?$GLOBALS['locale']:$value;}
function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function content_url($s){return 'https://example.test/'.$s;}
function is_singular($slug){return $slug==='tours-and-activities';}
function assert_same($x,$y,$msg){if($x!==$y){fwrite(STDERR,'FAIL '.$msg.PHP_EOL);exit(1);}}
class MockWidget {
    public function __construct(private $id,private $type){}
    public function get_name(){return $this->type;}
    public function get_id(){return $this->id;}
}
require dirname(__DIR__,2).'/wp-content/mu-plugins/199-mad4b-client-header-and-tour-toc.php';
$translations=['en'=>'Passport','ar'=>'جواز السفر','fr'=>'Passeport','de'=>'Reisepass','it'=>'Passaporto','es'=>'Pasaporte'];
foreach($translations as $key=>$name) {
    assert_same($name,mad4b_customer_header_passport_label($key),'Localized passport '.$key);
}
$render='<button><span data-header-context-label data-fallback="Market">Market</span></button>';
$expected='<button><span data-header-context-label data-fallback="Passport">Passport</span></button>';
assert_same($expected,mad4b_customer_header_label_html($render,'Passport'),'Desktop client label');
assert_same($render,mad4b_customer_header_label_html($render,'Market'),'Original equivalent');
assert_same('no alteration',mad4b_customer_header_label_html('no alteration','Passport'),'Other markup untouched');
$handler=$GLOBALS['handlers']['filter']['elementor/widget/render_content'][0]??null;
assert_same(true,is_callable($handler),'Elementor hook registered');
assert_same($expected,$handler($render,new MockWidget('c0a29f0','html')),'Target desktop header only');
assert_same($render,$handler($render,new MockWidget('c0a29f5','html')),'Mobile country label remains Country');
assert_same($render,$handler($render,new MockWidget('c0a29f0','heading')),'Non-HTML widgets untouched');
assert_same($render,$handler($render,new MockWidget('someoneelse','html')),'Other HTML widgets untouched');
$GLOBALS['locale']='fr';
assert_same('<button><span data-header-context-label data-fallback="Passeport">Passeport</span></button>',
 $handler($render,new MockWidget('c0a29f0','html')),'WPML french presentation');
echo "PASS: WPML six Passport labels, desktop-only exact HTML widget mutation, all other header widgets untouched\n";
