<?php
// Exercise the real candidate classes without booting WordPress or a database.
define('ABSPATH', __DIR__);
define('WPINC', 'wp-includes');
set_error_handler(function($level, $message) { throw new ErrorException($message, 0, $level); });
$root = $argv[1];
function check($ok, $label) { if (!$ok) throw new Exception($label); echo "PASS $label\n"; }
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function wp_unslash($s) { return stripslashes($s); }
function add_query_arg($args, $url) {
    $parts = explode('?', $url, 2); $query = [];
    if (isset($parts[1])) parse_str($parts[1], $query);
    return $parts[0] . '?' . http_build_query(array_merge($query, $args));
}
function jet_smart_filters() { return $GLOBALS['jsf']; }
function jet_search_ajax_handlers() { return new class {
    function get_custom_search_query_param() { return 'custom_query'; }
}; }
require $root . '/jet-engine/includes/components/blocks-views/dynamic-content/manager.php';
$blocks = (new ReflectionClass('Jet_Engine\\Blocks_Views\\Dynamic_Content\\Manager'))->newInstanceWithoutConstructor();
foreach ([null, [], new stdClass, true, 1.25] as $key) check(false === $blocks->get_block($key), 'invalid block identity rejected: ' . gettype($key));
$block = new class { function block_name() { return 'core/paragraph'; } };
$blocks->register_block($block);
check($blocks->get_block('core/paragraph') === $block, 'registered block preserved');
require $root . '/elementor/core/dynamic-tags/manager.php';
$tags = (new ReflectionClass('Elementor\\Core\\DynamicTags\\Manager'))->newInstanceWithoutConstructor();
$tag = '[elementor-tag id="one" name="test" settings="%7B%7D"]';
foreach ([[['url'=>'image.jpg'], ''], [new stdClass, ''], [null, ''], [0, '0'], [false, ''], ['Cairo', 'Cairo']] as $case) {
    check($tags->parse_tags_text('before ' . $tag . ' after', [], function() use ($case) { return $case[0]; }) === 'before ' . $case[1] . ' after', 'text result type: ' . gettype($case[0]));
}
$structured = ['url'=>'image.jpg', 'id'=>42];
check($tags->parse_tags_text($tag, ['returnType'=>'object'], function() use ($structured) { return $structured; }) === $structured, 'structured image return preserved');
$stringable = new class { function __toString() { return 'Luxor'; } };
check($tags->parse_tags_text($tag, [], function() use ($stringable) { return $stringable; }) === 'Luxor', 'stringable object preserved');
require $root . '/jet-smart-filters/includes/utils.php';
$GLOBALS['jsf'] = (object)['data'=>(object)['url_symbol'=>['provider_id'=>':','items_separator'=>';','key_value'=>':','var_suffix'=>'!']], 'settings'=>(object)['url_structure_type'=>'permalink'], 'URL_aliases'=>(object)['use_url_aliases'=>false]];
$utils = new Jet_Smart_Filters_Utils;
$args = [['value'=>2, 'query_var'=>false, 'query_type'=>'pagination', 'filter_type'=>'pagination']];
$url = $utils->get_filtered_url('https://example.test/fr/tours/?custom_query=Cairo&token=0#results', 'archive', 'jet-engine', $args);
check($url === 'https://example.test/fr/tours/jsf/jet-engine:archive/pagenum/2/?custom_query=Cairo&token=0#results', 'PHP permalink puts page before query and fragment');
$GLOBALS['jsf']->settings->url_structure_type = 'plain';
$url = $utils->get_filtered_url('/tours/?custom_query=Cairo&jsf=old#results', 'archive', 'jet-engine', $args);
parse_str(parse_url($url, PHP_URL_QUERY), $query);
check($query['custom_query']==='Cairo' && $query['jsf']==='jet-engine:archive' && $query['pagenum']==='2' && substr($url,-8)==='#results', 'PHP plain URL preserves search and replaces provider');
$sort = [['value'=>'price:ASC','query_var'=>false,'query_type'=>'sort','filter_type'=>'sorting']];
$url = $utils->get_filtered_url('/tours/', null, 'jet-engine', $sort);
parse_str(parse_url($url, PHP_URL_QUERY), $query);
check($query['sort']==='price:ASC', 'sort type initialized independently');
require $root . '/jet-search/includes/compatibility/jet-engine/manager.php';
$compat = (new ReflectionClass('Jet_Search_Compatibility_JE'))->newInstanceWithoutConstructor();
$_GET = ['custom_query'=>'Cairo', 'jet_ajax_search_settings'=>'{"search_source":"tours"}', 'jet_ajax_search_categories'=>'0', 'access_token'=>'private', 'arbitrary'=>'do not copy'];
$data = $compat->preserve_search_context(['extra_props'=>new stdClass]);
check($data['extra_props']['custom_query']==='Cairo' && $data['extra_props']['jet_ajax_search_categories']==='0', 'custom search and zero category forwarded');
check(!isset($data['extra_props']['access_token'], $data['extra_props']['arbitrary']) && count($data['extra_props'])===3, 'only allowlisted public search fields forwarded');
$data = $compat->preserve_search_context(['extra_props'=>['custom_query'=>'explicit', 'other'=>1]]);
check($data['extra_props']['custom_query']==='explicit' && $data['extra_props']['other']===1, 'explicit AJAX properties take precedence');
$_GET['custom_query'] = ['bad'];
check($compat->preserve_search_context(['other'=>1])===['other'=>1], 'array search not forwarded');
$_GET['custom_query'] = str_repeat('x', 8193);
check($compat->preserve_search_context(['other'=>1])===['other'=>1], 'oversized search not forwarded');
echo "Vendor PHP regression complete\n";
