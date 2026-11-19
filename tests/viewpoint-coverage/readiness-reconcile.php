<?php
declare(strict_types=1);
if(!defined('ABSPATH'))define('ABSPATH','/');
if(!function_exists('add_filter')){function add_filter(...$unused):void{}}
if(!function_exists('add_action')){function add_action(...$unused):void{}}
require_once __DIR__.'/../../wp-content/mu-plugins/mad4b-tour-viewpoint-discovery-loop-v1.php';
$old=[
  ['source'=>'mad4b-tour-photo-quality','code'=>'media_viewpoint_grid_portrait_cover_risk','object_id'=>7],
  ['source'=>'mad4b-tour-photo-quality','code'=>'media_gallery_original_duplicate','object_id'=>7],
  ['source'=>'another-provider','code'=>'media_viewpoint_grid_portrait_cover_risk','object_id'=>9],
  ['source'=>'mad4b-tour-photo-quality','code'=>'media_viewpoint_museum_exterior_overview_review','object_id'=>10]
];
$class=MAD4B_Tour_Viewpoint_Discovery_Loop_V1::class;
$incomplete=$class::reconcile_legacy_viewpoint_issues($old,false);
if($incomplete!==$old)throw new RuntimeException('Must preserve alerts while scan incomplete');
$complete=$class::reconcile_legacy_viewpoint_issues($old,true);
if(count($complete)!==2||$complete[0]['code']!=='media_gallery_original_duplicate'
  ||$complete[1]['source']!=='another-provider')throw new RuntimeException('Incorrect legacy reconciliation');
echo "PASS current-snapshot precedence and incomplete-scan preservation\n";
