<?php
declare(strict_types=1);
// This is an isolated WordPress source fixture: define the bootstrap guard
// before including the actual MU plugin so the test cannot exit early with
// a misleading zero status. No real WordPress, database, or network access.
define('ABSPATH', '/fixture/');
define('DAY_IN_SECONDS', 86400);
function add_action(...$args){}
function add_filter(...$args){}
$GLOBALS['options']=['mad4b_render_query_data_generation'=>'GLOBAL-A','mad4b_render_query_scoped_generation_v1'=>'off'];
$GLOBALS['pt']=['post','page','tours-and-activities','properties','services','product','locations'];
$GLOBALS['tax']=[
 'destinations'=>(object)['public'=>true,'object_type'=>['post','page','tours-and-activities','properties','product']],
 'tour-types'=>(object)['public'=>true,'object_type'=>['tours-and-activities']]
];
$GLOBALS['posts']=[10=>'tours-and-activities',11=>'services'];
function get_option($name,$default=false){return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['options'][$name]=$value;return true;}
function get_post_type_object($name){return in_array($name,$GLOBALS['pt'],true)?(object)['public'=>true]:null;}
function get_taxonomy($name){return $GLOBALS['tax'][$name]??null;}
function get_post_type($id){return $GLOBALS['posts'][$id]??null;}
function wp_is_post_revision($id){return false;}
function wp_generate_uuid4(){return bin2hex(random_bytes(16));}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
require $argv[1];
function chk($ok,$name){if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n";}
$tour=(object)['id'=>193,'name'=>'Featured tours','query_type'=>'posts',
 'query'=>['post_type'=>['tours-and-activities'],'tax_query'=>[['taxonomy'=>'tour-types']]]];
$terms=(object)['id'=>291,'name'=>'Destinations','query_type'=>'terms',
 'query'=>['taxonomy'=>['destinations'],'hide_empty'=>true]];
$unknown=(object)['id'=>999,'query_type'=>'sql','query'=>[]];
$off=mad4b_render_query_effective_generation($tour);
chk($off==='GLOBAL-A','flag off preserves original global generation');
$GLOBALS['options']['mad4b_render_query_scoped_generation_v1']='on';
$t1=mad4b_render_query_effective_generation($tour);
$d1=mad4b_render_query_effective_generation($terms);
chk(str_starts_with($t1,'scoped:v1:') && str_starts_with($d1,'scoped:v1:'),'native scoped groups separate from global key');
chk(mad4b_render_query_effective_generation($unknown)==='GLOBAL-A','unknown SQL stays on original generation');
chk(count(mad4b_render_query_scope_spec($tour))===2,'posts dependency includes CPT plus selected term taxonomy');
chk(count(mad4b_render_query_scope_spec($terms))===6,'terms dependency resolves all public attached CPTs');
chk(mad4b_render_query_scope_bump('post_type','services'),'unrelated source revision saved');
chk(mad4b_render_query_effective_generation($tour)===$t1 && mad4b_render_query_effective_generation($terms)===$d1,'unrelated service mutation leaves both keys stable');
chk(mad4b_render_query_scope_bump_post(10),'tour post change detected');
$t2=mad4b_render_query_effective_generation($tour);
$d2=mad4b_render_query_effective_generation($terms);
chk($t2!==$t1 && $d2===$d1,'tour content metadata does not change destination term membership');
chk(mad4b_render_query_scope_bump('membership_taxonomy','destinations::tours-and-activities'),
  'real destination membership revision saved');
$d2b=mad4b_render_query_effective_generation($terms);
chk($d2b!==$d2,'related post-taxonomy membership invalidates terms');
chk(mad4b_render_query_scope_bump_term('destinations'),'term source revision saved');
$t3=mad4b_render_query_effective_generation($tour);
$d3=mad4b_render_query_effective_generation($terms);
chk($t3===$t2 && $d3!==$d2b,'term meta edits affect dependent terms query only');
$GLOBALS['options']['mad4b_render_query_data_generation']='GLOBAL-B';
chk(mad4b_render_query_effective_generation($tour)===$t3 && mad4b_render_query_effective_generation($terms)===$d3,'legacy fragment global epoch no longer thrashes scoped JetEngine groups');
chk(mad4b_render_query_effective_generation($unknown)==='GLOBAL-B','unrecognized query safely inherits new global epoch');
$GLOBALS['options']['mad4b_render_query_global_safety_generation_v1']='POLICY-CHANGE';
chk(mad4b_render_query_effective_generation($tour)!==$t3 && mad4b_render_query_effective_generation($terms)!==$d3,'permission/market global change still invalidates all');
$bad=(object)['id'=>999,'query_type'=>'posts','query'=>['post_type'=>'any']];
chk(mad4b_render_query_scope_spec($bad)===[],'unscoped any-post query must not opt in');
$t4=mad4b_render_query_effective_generation($tour);
$tour->query['post_status']=['draft'];
chk(mad4b_render_query_effective_generation($tour)!==$t4,'query definition mutation changes cache version');
$GLOBALS['options']['mad4b_render_query_scoped_generation_v1']='off';
chk(mad4b_render_query_effective_generation($tour)==='GLOBAL-B','feature rollback restores original version');
echo "PASS 16/16 scoped query dependency tests\n";
