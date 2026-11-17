<?php
/** Live source behavior test without touching WordPress database. */
define('ABSPATH','/');
function add_action() {}
function get_post_meta($id,$key,$single=true) {return $key==='_elementor_edit_mode' && $id===22?'builder':'';}
function get_page_template_slug($id) {return match($id){14=>'elementor_header_footer',15=>'../../evil',default=>''};}
require (getenv('MAD4B_421_SOURCE') ?: __DIR__.'/../../../wp-content/mu-plugins/421-mad4b-public-critical-css-coverage.php');
$r=new ReflectionMethod(MAD4B_Public_Critical_CSS_Coverage_V1::class,'source_profile');
function test($name,$actual,$wanted) {if($actual!==$wanted)throw new Exception($name.' expected='.$wanted.' received='.$actual);echo 'PASS '.$name."\n";}
test('standard tour uses shared type source',$r->invoke(null,'tours-and-activities',11),'shared-template:tours-and-activities:default');
test('individually authored Elementor page never joins generic group',$r->invoke(null,'page',22),'elementor-document:22');
test('WP template slug retained as origin',$r->invoke(null,'page',14),'shared-template:page:elementor_header_footer');
test('unsafe WordPress template path falls back to generic source',$r->invoke(null,'page',15),'shared-template:page:default');
test('taxonomy family sourced by taxonomy name',$r->invoke(null,'destinations',12),'taxonomy:destinations');
test('archive family sourced by archive kind',$r->invoke(null,'archive:tours-and-activities',0),'archive:archive:tours-and-activities');
test('unknown type refused',$r->invoke(null,'user',1),'unclassified');
echo 'PASS 7/7 source-profile WP ownership tests'."\n";
