<?php
/** Read-only contract for Look & Feel, native JetSmartFilters and Program isolation. */
if(!class_exists('MAD4B_Rate_Facet_Visibility_V1',false))WP_CLI::error('Native owner unavailable');
class MAD4B_Facet_Widget_Test_Double {
 private $id;
 function __construct(int $id){$this->id=$id;}
 function get_name(){return 'jet-smart-filters-select';}
 function get_settings_for_display(){return ['filter_id'=>[$this->id],'query_id'=>'tour_rates'];}
}
$original=$GLOBALS['wp_the_query']??null;
$GLOBALS['wp_the_query']=new WP_Query(['post_type'=>'tours-and-activities','p'=>60439,'post_status'=>'publish']);
$mode='adaptive_labels';
$options=get_option('look-and-feel',[]);
$override=static function($value)use(&$mode,$options){
 $value=$options;$value['tour_rate_facets_display_mode']=$mode;return $value;
};
add_filter('pre_option_look-and-feel',$override,999);
$results=[];
try{
 $package=new MAD4B_Facet_Widget_Test_Double(57372);
 $season=new MAD4B_Facet_Widget_Test_Double(66030);
 $program=new MAD4B_Facet_Widget_Test_Double(46356);
 foreach(['adaptive_labels','hide_redundant','always_select'] as $name){
  $mode=$name;
  foreach(['package'=>$package,'season'=>$season,'program'=>$program] as $group=>$widget){
   $visible=MAD4B_Rate_Facet_Visibility_V1::should_render(true,$widget);
   $render=MAD4B_Rate_Facet_Visibility_V1::render_content('<select class="native">ORIGINAL</select>',$widget,[]);
   $static=str_contains($render,'mad4b-rate-facet-summary');
   $expectedShow=$name!=='hide_redundant'||$group==='program';
   $expectedStatic=$name==='adaptive_labels'&&$group!=='program';
   if($visible!==$expectedShow||$static!==$expectedStatic){
    WP_CLI::error("Mode contract mismatch {$name}/{$group}:".wp_json_encode(['visible'=>$visible,'static'=>$static]));
   }
   $results[]=['mode'=>$name,'group'=>$group,'visible'=>$visible,'static'=>$static];
  }
 }
 // Two eligible choices must keep the original functional JetSmartFilter;
 // the static label is never a substitute for actual filtering.
 $cache=new ReflectionProperty('MAD4B_Rate_Facet_Visibility_V1','cache');
 $snapshot=$cache->getValue();
 try{
  $cache->setValue(null,[60439=>[
   'rate_ids'=>[61060,61070],
   'package'=>[2354=>'Gold',2355=>'Silver'],
   'season'=>[2736=>['slug'=>'winter','name'=>'Winter'],
              2737=>['slug'=>'summer','name'=>'Summer']]
  ]]);
  $mode='adaptive_labels';
  foreach(['package'=>$package,'season'=>$season] as $group=>$widget){
   $visible=MAD4B_Rate_Facet_Visibility_V1::should_render(true,$widget);
   $html=MAD4B_Rate_Facet_Visibility_V1::render_content('<select class="native">ORIGINAL</select>',$widget,[]);
   if(!$visible||!str_contains($html,'class="native"')||
      str_contains($html,'mad4b-rate-facet-summary'))
    WP_CLI::error('Multiple eligible choices must retain native Select: '.$group);
   $results[]=['mode'=>'adaptive_labels_two_choices','group'=>$group,
      'visible'=>true,'static'=>false];
  }
 }finally{
  $cache->setValue(null,$snapshot);
 }
}finally{
 remove_filter('pre_option_look-and-feel',$override,999);
 $GLOBALS['wp_the_query']=$original;
}
WP_CLI::success(wp_json_encode(['site'=>home_url(),'scenarios'=>count($results),'results'=>$results,
 'program_scope_unchanged'=>true,'read_only'=>true],JSON_UNESCAPED_SLASHES));
