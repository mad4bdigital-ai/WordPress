<?php
/** Run: php test_jetengine_term_css_isolation.php path/to/452.php */
declare(strict_types=1);
define('ABSPATH', '/');
function add_filter(...$args): bool {return true;}
require $argv[1] ?? '';
function assert_true(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException($description);
    echo "PASS $description\n";
}
$term='122'; $template='45929';
$menu='elementor-dcss-mad4b-'.substr(hash('sha256',"$term:67125"),0,16);
$fallback='elementor-dcss-mad4b-'.substr(hash('sha256',"$term:listing"),0,16);
$correct='elementor-dcss-mad4b-'.substr(hash('sha256',"$term:$template"),0,16);
$make=static function(string $outer,string $inner,string $id='45929'): string {
 return '<div class="jet-listing-grid__item jet-listing-dynamic-post-122 '.$outer.
 '" data-post-id="122" ><style type="text/css">:is( .jet-listing-dynamic-post-122:not( [class*="elementor-dcss-"] ), .'.$inner.
 ' ) .elementor-element.elementor-element-85bc6bc{background-image:url(/public/attraction.jpg);}</style>'.
 '<div data-elementor-type="jet-listing-items" data-elementor-id="'.$id.'" class="elementor elementor-'.$id.'">Public card</div></div>';
};
$native=$make($menu,$menu);
$cached=$make($menu,$fallback);
$on=MAD4B_Term_CSS_Scoped_V2::canonical($native,[45929=>true]);
$hit=MAD4B_Term_CSS_Scoped_V2::canonical($cached,[45929=>true]);
assert_true($on===$hit,'native MISS and Redis HIT have byte-identical scoped markup');
assert_true(substr_count($on,$correct)===2,'outer class and inline CSS have identical term:listing-document selector');
assert_true(MAD4B_Term_CSS_Scoped_V2::canonical($on,[45929=>true])===$on,'canonicalization is idempotent');
assert_true(MAD4B_Term_CSS_Scoped_V2::canonical($native,[67125=>true])===$native,'unlisted template is left untouched');
$menuCard=$make($menu,$menu,'67125');
assert_true(MAD4B_Term_CSS_Scoped_V2::canonical($menuCard,[45929=>true])===$menuCard,'Mega Menu uses its own CSS namespace');
$unknown=$make($menu,'elementor-dcss-mad4b-deadbeefdeadbeef');
assert_true(MAD4B_Term_CSS_Scoped_V2::canonical($unknown,[45929=>true])===$unknown,'unknown mismatched CSS identity fails closed');
$noCss='<div class="jet-listing-grid__item jet-listing-dynamic-post-122 '.$menu.' data-post-id="122"><div data-elementor-id="45929">Public content</div></div>';
assert_true(MAD4B_Term_CSS_Scoped_V2::canonical($noCss,[45929=>true])===$noCss,'missing inline CSS never rewritten');
$commercial=$make($menu,$menu).'<form id="fluentform_6"><input name="price"></form>';
assert_true(str_contains(MAD4B_Term_CSS_Scoped_V2::canonical($commercial,[45929=>true]),'<form id="fluentform_6">'),'the filter never removes or caches booking markup');
echo "ALL_SCOPED_TERM_CSS_TESTS_PASS\n";
