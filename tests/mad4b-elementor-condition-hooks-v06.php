<?php
namespace ElementorPro\Modules\DisplayConditions {
  class Module {
    public int $calls=0;
    public function before_element_render($e){$this->calls++;}
  }
}
namespace {
  define('ABSPATH','/');
  $fixture=sys_get_temp_dir().'/mad4b-elementor-test-'.getmypid();
  if (!is_dir($fixture))mkdir($fixture,0700);
  define('WPMU_PLUGIN_DIR',$fixture);
  $case=$argv[1]??'generic';
  $GLOBALS['case']=$case;
  $GLOBALS['host']=$case==='typed'?'wayfatours.com':'tours.dev.mad4b.com';
  $GLOBALS['lang']=$case==='typed'?'es':'fr';
  $GLOBALS['wp_filter']=[];
  function wp_parse_url($s,$f){return parse_url($s,$f);}
  function home_url($path='/'){return 'https://'.$GLOBALS['host'].$path;}
  function wp_get_environment_type(){return $GLOBALS['case']==='typed'?'production':'staging';}
  function is_admin(){return false;}
  function wp_doing_ajax(){return false;}
  function is_user_logged_in(){return false;}
  function is_preview(){return false;}
  function is_front_page(){return true;}
  function is_home(){return false;}
  function is_tax($x){return false;}
  function is_post_type_archive($x){return false;}
  function is_singular($x){return false;}
  function is_cart(){return false;}
  function is_checkout(){return false;}
  function is_account_page(){return false;}
  function is_wc_endpoint_url(){return false;}
  function get_option($n,$d=''){return '1';}
  function apply_filters($n,$v){return $GLOBALS['lang'];}
  function wp_json_encode($a){return json_encode($a);}
  function _wp_filter_build_unique_id($t,$cb,$p){
   if(is_array($cb) && is_object($cb[0]))return spl_object_hash($cb[0]).$cb[1];
   return is_string($cb)?$cb:'anon';
  }
  class WP_Hook {public array $callbacks=[];}
  function add_action($tag,$cb,$priority=10,$args=1){
    $hook=$GLOBALS['wp_filter'][$tag]??new WP_Hook();
    $id=_wp_filter_build_unique_id($tag,$cb,$priority);
    $hook->callbacks[$priority][$id]=['function'=>$cb,'accepted_args'=>$args];
    $GLOBALS['wp_filter'][$tag]=$hook;
  }
  function add_filter(...$args){}
  $host=$GLOBALS['host'];
  $company=$GLOBALS['case']==='typed'?'wayfatours':'tours-dev';
  $policy=[
   'contract'=>'mad4b.elementor.display-conditions-gate.v1',
   'site_host'=>$host,'environment'=>wp_get_environment_type(),
   'company_code'=>$company,'languages'=>[$GLOBALS['lang']],
   'status'=>'active','routes'=>'public',
   'active_route_scope'=>'front_page_only','revision'=>1,
  ];
  file_put_contents(WPMU_PLUGIN_DIR.'/mad4b-elementor-condition-gate-policy.json',json_encode($policy));
  $source=getenv('MAD4B_GATE_PLUGIN_SOURCE')
     ?:dirname(__DIR__).'/wp-content/mu-plugins/mad4b-elementor-display-conditions-gate.php';
  require $source;
  $native=new \ElementorPro\Modules\DisplayConditions\Module();
  $noop=function(){};
  $base='elementor/frontend/';
  $types=['section','column','widget','container'];
  $tags=$case==='typed'?array_map(fn($x)=>$base.$x.'/before_render',$types):[$base.'before_render'];
  $beforeKeys=[];
  foreach($tags as $tag){
    add_action($tag,$noop,10,1);
    add_action($tag,[$native,'before_element_render'],10,1);
    add_action($tag,'strlen',12,1);
    $beforeKeys[$tag]=array_keys($GLOBALS['wp_filter'][$tag]->callbacks[10]);
  }
  // For hybrid sites the generic and typed handler should remain native.
  if ($case==='hybrid') {
    add_action($base.'container/before_render',[$native,'before_element_render'],10,1);
  }
  $_COOKIE=[];$_SERVER=['REQUEST_METHOD'=>'GET','HTTP_HOST'=>$GLOBALS['host']];
  \MAD4B_Elementor_Condition_Tree_Gate::install();
  foreach($tags as $tag){
    $hook=$GLOBALS['wp_filter'][$tag];
    $items=$hook->callbacks[10];
    $originalKey=_wp_filter_build_unique_id($tag,[$native,'before_element_render'],10);
    if(!isset($items[$originalKey]))throw new \RuntimeException('Native key moved');
    if(array_keys($items)!==$beforeKeys[$tag])
      throw new \RuntimeException('callback order drift');
    $new=$items[$originalKey]['function'];
    if($case==='hybrid'){
     if(!is_array($new)||$new[0]!==$native)throw new \RuntimeException('hybrid modified');
    } elseif(!($new instanceof \Closure)){
     throw new \RuntimeException('not wrapped '.$tag);
    }
    if($hook->callbacks[12]['strlen']['function']!=='strlen')
      throw new \RuntimeException('non-native callback changed');
  }
  if($case!=='hybrid'){
    \MAD4B_Elementor_Condition_Tree_Gate::discover([
     ['id'=>'abcd1234','settings'=>['title'=>'Public'],'elements'=>[]],
     ['id'=>'abcd5678','settings'=>['e_display_conditions'=>['x']],'elements'=>[]]
    ],42);
    foreach($tags as $tag){
      $key=_wp_filter_build_unique_id($tag,[$native,'before_element_render'],10);
      $fn=$GLOBALS['wp_filter'][$tag]->callbacks[10][$key]['function'];
      $fn(new class {function get_id(){return 'abcd1234';}});
      $fn(new class {function get_id(){return 'abcd5678';}});
    }
    if($native->calls!==count($tags))throw new \RuntimeException('native condition routing error');
  }
  unlink(WPMU_PLUGIN_DIR.'/mad4b-elementor-condition-gate-policy.json');
  rmdir(WPMU_PLUGIN_DIR);
  echo 'HOOK_TOPOLOGY_'.$case.'_PASS callback order preserved, native conditions retained, '.$native->calls.' native calls'.PHP_EOL;
}
