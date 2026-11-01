<?php
/** Isolated regression proof: only one pinned listing may skip native CSS. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
$source = dirname(__DIR__, 4) . '/wp-content/mu-plugins/322-mad4b-empty-jet-css-fastpath.php';
if (!is_file($source)) $source = '/var/www/html/wp-content/mu-plugins/322-mad4b-empty-jet-css-fastpath.php';
require $source;
function must($ok, $label): void {
 if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
 echo "PASS: $label\n";
}
$native_calls = 0;
$native = static function($content, $id, $inner) use (&$native_calls) {
 ++$native_calls;
 return "native:" . $content;
};
$class = 'MAD4B_Empty_Jet_CSS_Fastpath_V1';
must($class::render_listing('unchanged', 67125, [], $native) === 'unchanged', 'pinned empty listing');
must($native_calls === 0, 'native renderer not called for pinned listing');
must($class::render_listing('other', 25102, [], $native) === 'native:other', 'tour price listing retains native CSS');
must($class::render_listing('featured', 14962, [], $native) === 'native:featured', 'featured listing retains native CSS');
must($class::render_listing('nested', 67125, [123], $native) === 'native:nested', 'nested templates fail closed');
must($native_calls === 3, 'native renderer invocation counts');
$method = new ReflectionMethod($class, 'pinned_listing_is_unchanged');
must($method->isPrivate(), 'source pin is internal');
