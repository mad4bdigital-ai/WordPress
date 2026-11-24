<?php
define('ABSPATH','/');
function add_action(...$args){}
require __DIR__.'/../wp-content/mu-plugins/mad4b-dynamic-cost-alerts-v1.php';
$c='MAD4B_Dynamic_Cost_Alerts_V1';$n=0;
function ck($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$row=['id'=>'A','source'=>$c::SOURCE,'object_id'=>11,'source_evidence'=>['scope'=>'structural','group'=>'source:hotel','coverage_members'=>['map','post']]];
$scan=['product_id'=>11,'scope'=>'structural','issues'=>[$row],'covered_groups'=>['source:hotel'],'coverage_members'=>['source:hotel'=>['map','post']]];
$old=['other'=>['id'=>'other','source'=>'mad4b-rate-planner','object_id'=>11,'status'=>'open']];
$x=$c::merge($old,[],$scan,'t1');ck($x['A']['status']==='open'&&$x['A']['first_seen']==='t1','open');
$y=$c::merge($x,[],$scan,'t2');ck(count($y)===2&&$y['A']['first_seen']==='t1'&&$y['A']['reopens']===0,'dedup');
$clear=array_replace($scan,['issues'=>[]]);
$z=$c::merge($y,[],$clear,'t3');ck($z['A']['status']==='resolved'&&$z['other']===$old['other'],'exact closure only');
$r=$c::merge($z,[],$scan,'t4');ck($r['A']['status']==='open'&&$r['A']['reopens']===1&&$r['A']['resolved_at']===null,'reopen');
ck($c::merge($x,[],array_replace($clear,['scope'=>'other']),'t')['A']['status']==='open','wrong scope retains');
ck($c::merge($x,[],array_replace($clear,['product_id'=>12]),'t')['A']['status']==='open','wrong product retains');
ck($c::merge($x,[],array_replace($clear,['covered_groups'=>[]]),'t')['A']['status']==='open','partial coverage retains');
ck($c::merge($x,[],array_replace($clear,['coverage_members'=>['source:hotel'=>['map']]]),'t')['A']['status']==='open','removed source not proof');
ck($c::merge($x,[],array_replace($clear,['coverage_members'=>['source:hotel'=>['map','post','new']]]),'t')['A']['status']==='resolved','all previous source members checked');
ck($c::merge([],['A'=>$z['A']],$scan,'t5')['A']['reopens']===1,'archive history reopened');
echo "PASS $n/$n dynamic cost alert scope and lifecycle\n";
