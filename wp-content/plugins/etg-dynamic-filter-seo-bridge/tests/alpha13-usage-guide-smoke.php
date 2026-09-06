<?php
declare(strict_types=1);
function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function expect_contains($needle,$haystack,$m){expect_true(false!==strpos($haystack,$needle),$m);}
$root=dirname(__DIR__);
$page=file_get_contents($root.'/includes/Admin/UsageGuidePage.php');
$bootstrap=file_get_contents($root.'/includes/Bootstrap.php');
$assets=file_get_contents($root.'/includes/Admin/AdminAssets.php');
$js=file_get_contents($root.'/assets/js/usage-guide.js');

expect_contains("const SLUG = 'etg-dfsb-usage-guide'",$page,'usage guide slug');
foreach(array('Quick Start','Dynamic Tags','Shortcodes','Tokens','Content Slots','Recipes','Safety & SEO') as $label){expect_contains($label,$page,'guide tab '.$label);}
foreach(array('ETG Filter Title','ETG Filter Intro','ETG Filter Result Summary','ETG Filter Keyword','ETG Filter Archive URL','ETG Filter Current URL','ETG Inventory Value','ETG Content Slot','ETG Filter Term Field','ETG Filter Term Section','ETG Filter Image','ETG Filter Image URL','ETG Filter Gallery') as $tag){expect_contains($tag,$page,'document dynamic tag '.$tag);}
foreach(array('etg_filter_h1','etg_filter_intro','etg_filter_sections','etg_filter_gallery','etg_filter_keyword','etg_filter_breadcrumb_context','etg_filter_term','etg_filter_term_section','etg_dynamic_content','etg_dynamic_value') as $shortcode){expect_contains($shortcode,$page,'document shortcode '.$shortcode);}
expect_contains('$this->catalog->build',$page,'runtime token catalog is generated from current Inventory');
expect_contains('$this->slots->all',$page,'content slots are runtime-derived');
expect_contains('DOCUMENTATION ONLY',$page,'guide declares non-authority');
expect_contains('authorizing=false',$page,'AJAX authorizing invariant documented');
expect_contains('url_authority=false',$page,'AJAX URL authority invariant documented');
expect_contains('seo_mutation=false',$page,'AJAX SEO mutation invariant documented');
expect_true(false===strpos($page,'update_option('),'guide cannot persist options');
expect_true(false===strpos($page,'add_option('),'guide cannot add options');
expect_true(false===strpos($page,'delete_option('),'guide cannot delete options');
expect_contains('new UsageGuidePage(',$bootstrap,'Bootstrap registers usage guide');
expect_contains("'etg-dfsb-usage-guide'",$assets,'admin assets include usage guide');
expect_contains('assets/js/usage-guide.js',$assets,'guide interaction script is enqueued');
expect_contains('.etg-copy-button',$js,'copy interaction present');
expect_contains('etg-guide-token-search',$js,'token search interaction present');
expect_true(false===strpos($js,'pushState'),'guide JS cannot push history');
expect_true(false===strpos($js,'replaceState'),'guide JS cannot replace history');

echo "Alpha13 in-product usage guide smoke tests passed.\n";
