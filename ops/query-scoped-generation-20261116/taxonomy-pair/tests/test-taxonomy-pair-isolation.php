<?php
declare(strict_types=1);
$GLOBALS['options']=['mad4b_render_query_data_generation'=>'GEN-A',
 'mad4b_render_query_scoped_generation_v1'=>'on',
 'mad4b_render_query_global_safety_generation_v1'=>'SAFE-A'];
$GLOBALS['pt']=[
 'tours-and-activities'=>(object)['public'=>true],
 'properties'=>(object)['public'=>true],
 'services'=>(object)['public'=>true],
 'attachment'=>(object)['public'=>true],
 'private-offer'=>(object)['public'=>false],
];
$GLOBALS['tax']=[
 'destinations'=>(object)['public'=>true,'object_type'=>['tours-and-activities','properties']],
 'package_levels'=>(object)['public'=>true,'object_type'=>['tours-and-activities','properties']],
 'private'=>(object)['public'=>false,'object_type'=>['tours-and-activities']]
];
$GLOBALS['posts']=[10=>'tours-and-activities',11=>'properties',12=>'attachment',13=>'private-offer'];
$GLOBALS['statuses']=[10=>'publish',11=>'publish',12=>'inherit',13=>'publish'];
$GLOBALS['hooks']=[];
$GLOBALS['invalidations']=[];
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function get_post_type($id){return $GLOBALS['posts'][$id]??null;}
function get_post_type_object($n){return $GLOBALS['pt'][$n]??null;}
function get_taxonomy($n){return $GLOBALS['tax'][$n]??null;}
function get_object_taxonomies($n){
 $out=[];foreach($GLOBALS['tax'] as $k=>$v)
  if(in_array($n,(array)$v->object_type,true))$out[]=$k;
 return $out;
}
function get_post_status($id){return $GLOBALS['statuses'][$id]??false;}
function wp_is_post_revision($id){return false;}
function wp_generate_uuid4(){return bin2hex(random_bytes(16));}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
function absint($v){return abs((int)$v);}
function add_action($n,$fn,$priority=10,$args=1){$GLOBALS['hooks'][$n]=$fn;}
function mad4b_render_query_invalidate($origin=null,$scoped=false){
 $GLOBALS['invalidations'][]=['origin'=>$origin,'scoped'=>$scoped];
}
function test($ok,$label) {
 static $count=0;$count++;
 if(!$ok){fwrite(STDERR,"FAIL #$count $label\n");exit(1);}
 echo "PASS #$count $label\n";
}
require $argv[1];
$dest=(object)['id'=>291,'query_type'=>'terms','query'=>[
 'taxonomy'=>['destinations'],'hide_empty'=>true]];
$package=(object)['id'=>292,'query_type'=>'terms','query'=>[
 'taxonomy'=>['package_levels'],'hide_empty'=>true]];
$tours=(object)['id'=>293,'query_type'=>'posts','query'=>[
 'post_type'=>['tours-and-activities']]];
$unknown=(object)['id'=>294,'query_type'=>'sql','query'=>[]];
function generations(): array{
 global $dest,$package,$tours;
 return [mad4b_render_query_effective_generation($dest),
   mad4b_render_query_effective_generation($package),
   mad4b_render_query_effective_generation($tours)];
}
function fire($n,...$params){($GLOBALS['hooks'][$n])(...$params);}
$origin=generations();
$dkeys=mad4b_render_query_scope_spec($dest);
$pkeys=mad4b_render_query_scope_spec($package);
test(count($dkeys)===3 && count($pkeys)===3,'public terms query scopes include taxonomy plus two pairs');
test(!array_intersect($dkeys,$pkeys),'two taxonomies have disjoint membership keys and taxonomy revisions');
test(str_contains(implode('|',$dkeys),'mad4b_rq_source_v1_mt_'),'new pair-specific version keys');
test(mad4b_render_query_scope_option('membership_taxonomy','../hack::bad')==='','invalid key cannot inject option');
test(mad4b_render_query_scope_option('membership_taxonomy','destinations::tours-and-activities')!=='',
 'taxonomy/type pair key accepted');
fire('set_object_terms',10,['pkg'],[99],'package_levels',false,[51]);
$afterPackage=generations();
test($afterPackage[0]===$origin[0] && $afterPackage[1]!==$origin[1],
 'package_levels relation only invalidates package query, not destinations');
test($afterPackage[2]===$origin[2],
 'post list group unchanged by taxonomy membership when not filter-dependent');
fire('set_object_terms',10,['dest'],[99],'destinations',false,[51]);
$afterDest=generations();
test($afterDest[0]!==$afterPackage[0] && $afterDest[1]===$afterPackage[1],
 'real destinations membership invalidates destinations only');
$unmodified=generations();
fire('set_object_terms',10,['dest'],[9,99],'destinations',false,[99,9]);
test(generations()===$unmodified,'same set reordered is no-op');
fire('set_object_terms',10,['dest'],[99],'destinations',true,[99]);
test(generations()===$unmodified,'append existing term id no-op');
fire('set_object_terms',10,['dest'],[101],'destinations',true,[99]);
test(generations()[0]!==$unmodified[0],'append new destination term must invalidate');
$prePrivate=generations();
fire('set_object_terms',10,['private'],[21],'private',false,[11]);
test(generations()===$prePrivate,'private taxonomy membership cannot enter shared query');
fire('set_object_terms',13,['private'],[31],'destinations',false,[12]);
test(generations()===$prePrivate,'private post type has no shared eligibility');
fire('set_object_terms',11,['dest'],[2],'destinations',false,[1]);
test(generations()[0]!==$prePrivate[0],'same taxonomy different public post type invalidates terms');
$base=generations();
test(mad4b_render_query_scope_bump_post(10),'tour post content version bumped');
$afterMeta=generations();
test($afterMeta[0]===$base[0]&&$afterMeta[1]===$base[1]&&$afterMeta[2]!==$base[2],
 'tour metadata only affects matching post query');
$beforeStatus=generations();
fire('transition_post_status','publish','draft',(object)['ID'=>10]);
$afterStatus=generations();
test($afterStatus[0]!==$beforeStatus[0]&&$afterStatus[1]!==$beforeStatus[1],
 'publish of public tour invalidates all attached public taxonomies');
$preDelete=generations();
fire('before_delete_post',10);
test(generations()[0]!==$preDelete[0]&&generations()[1]!==$preDelete[1],
 'delete of published tour refreshes all related term counts');
$preDraft=generations();
fire('transition_post_status','draft','draft',(object)['ID'=>10]);
test(generations()===$preDraft,'no-op status transition does not invalidate');
$GLOBALS['statuses'][10]='draft';
fire('set_object_terms',10,['dest'],[19],'destinations',false,[11]);
test(generations()===$preDraft,'draft term assignment is not public membership');
test(mad4b_render_query_scope_bump('post_type','attachment'),'attachment content revision saved');
test(generations()===$preDraft,'attachment diagnostic source does not affect destination scopes');
test(mad4b_render_query_is_generated_meta_artifact('_mad4b_watermark_fingerprint_v1'),
 'derived watermark scanning fingerprint is not primary image content');
test(!mad4b_render_query_is_generated_meta_artifact('_wp_attachment_image_alt'),
 'real image alt text is still meaningful source content');
test(!mad4b_render_query_is_generated_meta_artifact('_elementor_data'),
 'real Elementor document source remains tracked');
$prior=generations();
$GLOBALS['options']['mad4b_render_query_data_generation']='GEN-B';
test(generations()===$prior,'fragment legacy epoch does not thrash scoped groups');
test(mad4b_render_query_effective_generation($unknown)==='GEN-B',
 'unsafe unscoped queries keep conservative global version');
$GLOBALS['options']['mad4b_render_query_global_safety_generation_v1']='SECURITY-CHANGE';
test(generations()!==$prior,'market/permission global safety event invalidates all');
test(count(array_filter($GLOBALS['invalidations'],
 static fn($e)=>$e['scoped']!==true))===0,
 'every fine-grained membership event uses scoped invalidation');
echo "PASS taxonomy-pair isolation and safe invalidation tests complete\n";
