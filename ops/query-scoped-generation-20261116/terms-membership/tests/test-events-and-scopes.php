<?php
declare(strict_types=1);
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
function get_object_taxonomies($name){
 $out=[];foreach($GLOBALS['tax'] as $tax=>$info){
  if(in_array($name,(array)($info->object_type??[]),true))$out[]=$tax;
 }return $out;
}
function get_post_type($id){return $GLOBALS['posts'][$id]??null;}
function wp_is_post_revision($id){return false;}
function wp_generate_uuid4(){return bin2hex(random_bytes(16));}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
$GLOBALS['hooks']=[];
$GLOBALS['invalidations']=[];
$GLOBALS['statuses']=[10=>'draft',11=>'publish'];
function add_action($name,$cb,$priority=10,$accepted=1){$GLOBALS['hooks'][$name]=$cb;}
function get_post_status($id){return $GLOBALS['statuses'][(int)$id]??false;}
function mad4b_render_query_invalidate($origin=null,$scoped=false){
 $GLOBALS['invalidations'][]=['origin'=>$origin,'scoped'=>$scoped];
}
function absint($v){return abs((int)$v);}

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
chk(count(mad4b_render_query_scope_spec($terms))===6,'terms dependency resolves all public attached CPT memberships');
chk(str_contains(implode('|',mad4b_render_query_scope_spec($terms)),'mad4b_rq_source_v1_mt_'),
 'terms query has membership-specific source revision');
chk(!str_contains(implode('|',mad4b_render_query_scope_spec($terms)),'mad4b_rq_source_v1_p_'),
 'terms query does not follow unrelated post content meta revisions');
chk(mad4b_render_query_scope_bump('post_type','services'),'unrelated source revision saved');
chk(mad4b_render_query_scope_bump('membership_taxonomy','tour-types::services'),
 'unrelated published service membership revision saved');
chk(mad4b_render_query_effective_generation($tour)===$t1 && mad4b_render_query_effective_generation($terms)===$d1,'unrelated service mutation leaves both keys stable');
chk(mad4b_render_query_scope_bump_post(10),'tour post content mutation detected');
$t2=mad4b_render_query_effective_generation($tour);
$d2=mad4b_render_query_effective_generation($terms);
chk($t2!==$t1 && $d2===$d1,'tour draft meta changes do not invalidate destination terms');
chk(mad4b_render_query_scope_bump_membership_post(10),'published tour membership revision saved');
$t2b=mad4b_render_query_effective_generation($tour);
$d2b=mad4b_render_query_effective_generation($terms);
chk($t2b===$t2 && $d2b!==$d2,'published relation membership invalidates terms, not posts');
chk(mad4b_render_query_scope_bump_term('destinations'),'term source revision saved');
$t3=mad4b_render_query_effective_generation($tour);
$d3=mad4b_render_query_effective_generation($terms);
chk($t3===$t2b && $d3!==$d2b,'term meta edits affect dependent terms query only');
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
echo "PASS 22/22 scoped query dependency and term membership tests\n";
require $argv[2];
$GLOBALS['options']['mad4b_render_query_scoped_generation_v1']='on';
function sim_event($name,...$args){$cb=$GLOBALS['hooks'][$name]??null;
 if(!is_callable($cb))throw new RuntimeException('missing event '.$name);$cb(...$args);
}
$termBase=mad4b_render_query_effective_generation($terms);
sim_event('transition_post_status','draft','draft',(object)['ID'=>10]);
chk(mad4b_render_query_effective_generation($terms)===$termBase,
 'status no-op does not change term membership');
sim_event('transition_post_status','publish','draft',(object)['ID'=>10]);
$termOne=mad4b_render_query_effective_generation($terms);
chk($termOne!==$termBase,'draft-to-publish changes term membership version');
sim_event('transition_post_status','draft','publish',(object)['ID'=>10]);
$termTwo=mad4b_render_query_effective_generation($terms);
chk($termTwo!==$termOne,'publish-to-draft changes term membership version');
sim_event('set_object_terms',10,['x'],[101],'destinations',false,[202]);
chk(mad4b_render_query_effective_generation($terms)===$termTwo,
 'term edits to a draft do not invalidate published membership');
$GLOBALS['statuses'][10]='publish';
sim_event('set_object_terms',10,['x'],[101,102],'destinations',false,[102,101]);
chk(mad4b_render_query_effective_generation($terms)===$termTwo,
 'reordered but identical published term IDs are a no-op');
sim_event('set_object_terms',10,['x'],[101],'destinations',true,[101,102]);
chk(mad4b_render_query_effective_generation($terms)===$termTwo,
 'append an existing published term is a no-op');
sim_event('set_object_terms',10,['x'],[103],'destinations',true,[101,102]);
$termThree=mad4b_render_query_effective_generation($terms);
chk($termThree!==$termTwo,'appending a new published taxonomy relation invalidates');
sim_event('before_delete_post',10);
chk(mad4b_render_query_effective_generation($terms)!==$termThree,
 'deleting a published post invalidates term counts');
chk(count($GLOBALS['invalidations'])===4,
 'only four true published membership operations recorded');
chk(count(array_filter($GLOBALS['invalidations'],static fn($row)=>$row['scoped']===true))===4,
 'every membership event remains scoped, with no global safety broad reset');
echo "PASS 32/32 source/membership event assertions\\n";
