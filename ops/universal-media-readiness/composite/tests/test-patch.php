<?php
if(!defined('ABSPATH'))define('ABSPATH','/');
require dirname(__DIR__,4).'/wp-content/mu-plugins/mad4b-composite-media-patch.php';
function run_case($name,$old,$schema,$op,$expect){$op['expected_sha256']=hash('sha256',json_encode($old,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$r=MAD4B_Composite_Media_Patch_V1::plan($old,$schema,$op);if(($r['ok']??false)!==$expect)throw new Exception($name.' '.json_encode($r));echo 'PASS '.$name."\n";}
$g=['type'=>'gallery'];$r=['type'=>'repeater','repeater_fields'=>[['name'=>'photo','type'=>'media'],['name'=>'caption','type'=>'text']]];
run_case('csv append','10,11',$g,['action'=>'append_gallery','media'=>12],true);
run_case('csv type mismatch','10,11',$g,['action'=>'append_gallery','media'=>'https://example.com/a.jpg'],false);
run_case('object gallery append',[['id'=>4,'url'=>'https://example.com/4.jpg']],$g,['action'=>'append_gallery','media'=>5,'attachment_id'=>5,'url'=>'https://example.com/5.jpg'],true);
run_case('object unknown fields rejected',[['id'=>4,'url'=>'https://example.com/4.jpg','caption'=>'Keep']],$g,['action'=>'append_gallery','media'=>5,'attachment_id'=>5,'url'=>'https://example.com/5.jpg'],false);
run_case('repeater item preserving caption',['item-0'=>['photo'=>'before','caption'=>'untouched']],$r,['action'=>'set_repeater_media','row'=>'item-0','field'=>'photo','media'=>123],true);
run_case('repeater reject text field',['item-0'=>['photo'=>2,'caption'=>'safe']],$r,['action'=>'set_repeater_media','row'=>'item-0','field'=>'caption','media'=>123],false);
$old=['item-0'=>['photo'=>2,'caption'=>'safe']];$op=['action'=>'set_repeater_media','row'=>'item-0','field'=>'photo','media'=>123,'expected_sha256'=>str_repeat('0',64)];$out=MAD4B_Composite_Media_Patch_V1::plan($old,$r,$op);if(($out['error']??'')!=='preimage_drift')throw new Exception('CAS rejection');echo "PASS drift rejection\n";

run_case('url gallery append','https://wayfatours.com/a.jpg',$g,['action'=>'append_gallery','media'=>'https://wayfatours.com/b.jpg'],true);
run_case('empty array gallery format unknown',[],$g,['action'=>'append_gallery','media'=>42],false);
run_case('unknown repeater row',['item-0'=>['photo'=>1]],$r,['action'=>'set_repeater_media','row'=>'item-99','field'=>'photo','media'=>42],false);
run_case('unchanged external repeater data',['item-0'=>['photo'=>'old','caption'=>'keep'],'item-1'=>['photo'=>5,'extra'=>'keep2']],$r,['action'=>'set_repeater_media','row'=>'item-0','field'=>'photo','media'=>23],true);

$nested=['type'=>'repeater','repeater_fields'=>[['name'=>'gellery','type'=>'gallery','value_format'=>'url'],['name'=>'title','type'=>'text']]];
run_case('nested repeater gallery append',['item-0'=>['title'=>'Keep','gellery'=>'https://wayfatours.com/one.jpg']],$nested,['action'=>'append_repeater_gallery','row'=>'item-0','field'=>'gellery','media'=>'https://wayfatours.com/two.jpg'],true);
run_case('nested gallery empty string append',['item-0'=>['title'=>'Keep','gellery'=>'']],$nested,['action'=>'append_repeater_gallery','row'=>'item-0','field'=>'gellery','media'=>'https://wayfatours.com/two.jpg'],true);
run_case('nested gallery invalid text field',['item-0'=>['title'=>'Keep','gellery'=>'']],$nested,['action'=>'append_repeater_gallery','row'=>'item-0','field'=>'title','media'=>'https://wayfatours.com/two.jpg'],false);
