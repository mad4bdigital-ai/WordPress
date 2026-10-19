<?php
/** WordPress adapter tests with distinct source/translation objects. */
require_once dirname(__DIR__) . '/includes/IndexFlow/WordPressRouteEnvironment.php';
use ETG\DynamicFilterSEOBridge\IndexFlow\WordPressRouteEnvironment;
class WP_Term { public $term_id; public $slug; public function __construct($id, $slug) { $this->term_id=$id; $this->slug=$slug; } }
class WP_Post { public $ID; public $post_status='publish'; public $post_type='page'; public function __construct($id) { $this->ID=$id; } }
define('OBJECT', 'OBJECT');
$wpml = true; $missing = false; $owner = 0; $status = 'publish';
function has_filter($name) { return $GLOBALS['wpml']; }
function apply_filters($name, $value, ...$args) {
    if ('wpml_default_language' === $name) return 'en';
    if ('wpml_active_languages' === $name) return array('en'=>array(), 'ar'=>array());
    if ('wpml_object_id' === $name) {
        if ($GLOBALS['missing']) return null;
        if ('location' === $args[0]) return 'ar' === $args[2] ? 11 : 1;
        return 'ar' === $args[2] ? 20 : 10;
    }
    return $value;
}
function get_term($id, $taxonomy) { return 'location' === $taxonomy && in_array($id,array(1,11),true) ? new WP_Term($id, 1===$id?'cairo':'القاهرة') : null; }
function get_post($id) { if (!in_array($id,array(10,20),true)) return null; $p=new WP_Post($id);$p->post_status=$GLOBALS['status'];return $p; }
function get_permalink($id) { return 'https://example.test/page-'.$id.'/'; }
function is_post_type_viewable($type) { return 'page' === $type; }
function get_locale() { return 'en_US'; }
function url_to_postid($url) { return $GLOBALS['owner']; }
function get_page_by_path($path, $output, $types) { return null; }
function get_post_types($args) { return array('page'); }
function get_taxonomies($args) { return array('location'); }
function get_terms($args) { return array(new WP_Term(1, 'cairo')); }
function get_term_link($term) { return 'https://example.test/location/cairo/'; }
function is_wp_error($v) { return false; }
function home_url($path) { return 'https://example.test'.$path; }
function get_option($key, $default=false) { return $GLOBALS['wpOptions'][$key] ?? $default; }
$count=0;
function check($condition, $message) { $GLOBALS['count']++;if(!$condition)throw new RuntimeException($message); }
$env=new WordPressRouteEnvironment();
check($env->term('location',1,'en')['semantic_id'] === $env->term('location',11,'ar')['semantic_id'], 'real translated IDs share default term');
check(!$env->term('location',1,'ar'), 'same numeric ID across languages denied');
$missing=true;check(!$env->term('location',11,'ar'), 'missing translation cannot fall back');check(!$env->page(20,'ar'), 'missing translated page denied');$missing=false;
check($env->page(20,'ar')['id']===20, 'translated native page accepted');
check(!$env->page(10,'ar'), 'wrong language page denied');
$status='draft';check(!$env->page(10,'en'), 'draft template denied');$status='publish';
check(!$env->collisions('https://example.test/new/',0), 'unoccupied path');
$owner=10;check((bool)$env->collisions('https://example.test/page-10/',0), 'native post collision');check(!$env->collisions('https://example.test/page-10/',10), 'native owner exception');$owner=0;
$wpOptions = array('home'=>'https://example.test', 'rewrite_rules'=>array('^tours/location/cairo/?$'=>'index.php?page_id=10&indexflow_route=cairo-en'));
$owner=10;check(!$env->collisions('https://example.test/tours/location/cairo/',0), 'Existing managed rewrite does not collide with its own template');
check((bool)$env->collisions('https://example.test/page-10/',0), 'Managed rule exception cannot hide a real native permalink');$owner=0;
check((bool)$env->collisions('https://example.test/location/cairo/',0), 'native taxonomy collision');
$wpml=false;check((bool)$env->term('location',1,'en'), 'single-language site reads native term');check(!$env->term('location',1,'ar'), 'cannot invent translations without WPML');
check(!$env->page(10,'ar'), 'cannot invent page language without WPML');
echo "WordPress route environment: $count checks PASS (fixtures)\n";
