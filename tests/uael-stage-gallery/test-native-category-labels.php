<?php
/** Source-only regression of the ORIGINAL UAEL addon label function. */
define('ABSPATH', '/tmp/');
function add_action(...$args) {return true;}
function add_filter(...$args) {return true;}
require dirname(__DIR__, 2).'/wp-content/mu-plugins/mad4b-uael-tour-gallery-addon.php';
$failures=[];
$expect=static function(string $label, string $actual, string $expected) use (&$failures):void {
   if($actual!==$expected)$failures[]=['test'=>$label,'actual'=>$actual,'expected'=>$expected];
};
$locale=['day'=>'Day','days'=>'Days','tour'=>'Tour Photos'];
$visits=['timeline_unit'=>'stage','day_label'=>'Stop','days_plural'=>'stops',
  'days'=>array_map(static fn($n)=>['day'=>$n,'title'=>'Visit '. $n],range(1,9))];
$multi=['timeline_unit'=>'day','day_label'=>'Day','days_plural'=>'Days',
  'days'=>[['day'=>1],['day'=>2]]];
$label=static fn($section,$timeline,$is_stage,$show=true,$lang=null)=>
  MAD4B_UAEL_Tour_Gallery_Addon_V1::category_label($section,$lang??$locale,$timeline,$is_stage,$show);
$group=['id'=>192,'title'=>'Luxor','days'=>[1,2,5,7]];
$expect('day tour stop numbers',$label($group,$visits,true),'Stops 1, 2, 5, 7 · Luxor');
$expect('single stop',$label(['id'=>192,'title'=>'Valley of the Kings','days'=>[2]],$visits,true),'Stop 2 · Valley of the Kings');
$expect('multi day preserved',$label($group,$multi,false),'Days 1, 2, 5, 7 · Luxor');
$expect('single day preserved',$label(['id'=>192,'title'=>'Luxor','days'=>[1]],$multi,false),'Day 1 · Luxor');
$expect('unmatched stages never labeled as days',$label(['id'=>192,'title'=>'Luxor','days'=>[99]],$visits,true),'Luxor');
$expect('partial stage proof only',$label(['id'=>192,'title'=>'Luxor','days'=>[2,99]],$visits,true),'Stop 2 · Luxor');
$expect('no stage rows fail closed',$label($group,[],true),'Luxor');
$expect('disabled timeline',$label($group,$visits,true,false),'Luxor');
$expect('original tour photos',$label(['id'=>'tour','title'=>'Other','days'=>[]],$visits,true),'Tour Photos');
$es=['day'=>'Día','days'=>'Días','tour'=>'Fotos del viaje'];
$es_stage=['days_plural'=>'paradas','day_label'=>'Parada','days'=>$visits['days']];
$expect('Spanish stops',$label($group,$es_stage,true,true,$es),'Paradas 1, 2, 5, 7 · Luxor');
$expect('Spanish multi days',$label($group,$multi,false,true,$es),'Días 1, 2, 5, 7 · Luxor');
$fr=['day'=>'Jour','days'=>'Jours','tour'=>'Photos du voyage'];
$fr_stage=['days_plural'=>'étapes','day_label'=>'Étape','days'=>$visits['days']];
$expect('French stops',$label($group,$fr_stage,true,true,$fr),'Étapes 1, 2, 5, 7 · Luxor');
$ar=['day'=>'اليوم','days'=>'الأيام','tour'=>'صور الرحلة'];
$ar_stage=['days_plural'=>'مراحل','day_label'=>'محطة','days'=>$visits['days']];
$expect('Arabic stages',$label($group,$ar_stage,true,true,$ar),'مراحل 1, 2, 5, 7 · Luxor');
$expect('original source unchanged',implode(',',$group['days']),'1,2,5,7');
if($failures){echo json_encode(['ok'=>false,'failures'=>$failures],JSON_UNESCAPED_UNICODE)."\n";exit(1);}
echo json_encode(['ok'=>true,'tests'=>14,'code'=>'NATIVE_UAEL_CATEGORY_LABEL_PARITY'],JSON_UNESCAPED_SLASHES)."\n";
