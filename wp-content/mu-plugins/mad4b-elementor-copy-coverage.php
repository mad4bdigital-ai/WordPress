<?php
/**
 * Plugin Name: MAD4B Elementor Copy Coverage
 * Description: Recursive copy provenance scan for Elementor, JetEngine and WPML. Read-only inventory; no frontend fetch or auto-publishing.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Elementor_Copy_Coverage {
 const STORE='mad4b_elementor_copy_coverage_v1';
 const DIRTY='mad4b_elementor_copy_dirty_v1';
 const CONTRACT='mad4b.elementor-copy-coverage.v1';
 private static bool $scanning=false;

 private static function text($v):string {
  return trim((string)preg_replace('/\s+/u',' ',html_entity_decode(wp_strip_all_tags((string)$v),ENT_QUOTES|ENT_HTML5,'UTF-8')));
 }
 private static function key_is_text(string $k):bool {
  $yes=['title','title_text','heading','headline','subtitle','sub_title','description','description_text',
   'editor','html','content','simple_content','text','button','button_text','button_label','item_label',
   'item_title','item_text','item_description','item_editor_content','label','placeholder','field_placeholder',
   'field_fallback','not_found_message','server_error','error_text','empty_text','caption','notice','message',
   'tooltip','tooltip_text','loader_text','apply_button_text','link_text','header_text','footer_text'];
  if(in_array($k,$yes,true))return true;
  if($k===''||str_starts_with($k,'_'))return false;
  if(preg_match('/(?:^|_)(?:icon|image|media|color|font|style|typography|size|width|height|layout|align|justify|flex|border|margin|padding|animation|transition|css|duration|position|source|type|query|meta|callback|taxonomy|tag|id|slug|url|link|options|global|class|format)(?:_|$)/i',$k))return false;
  return (bool)preg_match('/(?:^|_)(?:heading|headline|subtitle|description|message|placeholder|label|caption|content|fallback|tooltip|editor|notice|button_text|text)$/i',$k);
 }
 private static function relevant(string $v):bool {
  $s=self::text($v);
  if(mb_strlen($s)<2)return false;
  if(preg_match('/^(?:https?:\/\/|#[0-9a-f]{3,8}$|[0-9.]+(?:px|em|rem|%)?$|yes$|no$|true$|false$|left$|right$|center$|inherit$|none$|block$|flex-start$)/i',$s))return false;
  return (bool)preg_match('/[A-Za-z\x{0600}-\x{06FF}\x{00C0}-\x{024F}]{2,}/u',$s);
 }
 private static function state(string $v,string $binding):string {
  if($binding!=='')return 'elementor_dynamic';
  if(str_contains($v,'data-etg-dfsb-token')||str_contains($v,'data-etg-dfsb-slot'))return 'dfsb_runtime';
  if(preg_match('/\[elementor-tag\s|\[(?:jet_|wpml_|mad4b_|etg_|rank_math)[\w-]+|%[A-Za-z_]+%/',$v))return 'inline_dynamic';
  if(preg_match('/^(?:Item\s*#\d+|Add Your Heading Text Here|Lorem ipsum|Your Title Here|Tab\s*#\d+)$/i',self::text($v)))return 'placeholder';
  return 'static';
 }
 private static function collect(array $settings,array $post,string $widget,string $widgetId,array &$fields,
   string $path='settings',array $dyn=[],int $depth=0):void {
  if($depth>8)return;
  foreach($settings as $k=>$v){
   $k=(string)$k;if(in_array($k,['__dynamic__','__globals__','jedv_conditions','display_condition_list'],true))continue;
   $here=$path.'.'.$k;
   if(is_string($v)){
    if(!self::key_is_text($k)||!self::relevant($v))continue;
    $h=($widget==='heading'&&$k==='title')?(string)($settings['header_size']??'h2'):'';
    if($k==='html'&&preg_match('/<h1\b/i',$v))$h='h1';
    $fields[]=[
     'id'=>(int)$post['ID'],'post_type'=>$post['post_type'],
     'lang'=>$post['language_code']?:'unassigned','trid'=>(int)$post['trid'],
     'status'=>$post['post_status'],'template'=>$post['post_title'],
     'widget'=>$widget,'widget_id'=>$widgetId,'path'=>$here,'field'=>$k,'heading'=>$h,
     'state'=>self::state($v,is_string($dyn[$k]??null)?$dyn[$k]:''),
     'value'=>mb_substr(self::text($v),0,240),'sha'=>hash('sha256',$v)
    ];
   }elseif(is_array($v)){
    if(preg_match('/(?:^|_)(?:style|color|border|margin|padding|font|typography|background|image|icon|size|dimension|css)(?:_|$)/i',$k))continue;
    if(array_is_list($v)){
     foreach($v as $i=>$item)if(is_array($item))self::collect($item,$post,$widget,$widgetId,
      $fields,$here.'['.$i.']',is_array($item['__dynamic__']??null)?$item['__dynamic__']:[],$depth+1);
    }else self::collect($v,$post,$widget,$widgetId,$fields,$here,
      is_array($v['__dynamic__']??null)?$v['__dynamic__']:[],$depth+1);
   }
  }
 }
 private static function walk(array $items,array $post,array &$fields,int &$widgets):void{
  foreach($items as $n){
   if(!is_array($n))continue;
   $widget=(string)($n['widgetType']??'');
   if($widget!==''){
    ++$widgets;$s=is_array($n['settings']??null)?$n['settings']:[];
    self::collect($s,$post,$widget,(string)($n['id']??''),$fields,'settings',
      is_array($s['__dynamic__']??null)?$s['__dynamic__']:[]);
   }
   if(is_array($n['elements']??null))self::walk($n['elements'],$post,$fields,$widgets);
  }
 }
 private static function posts():array{
  global $wpdb;
  $t=$wpdb->prefix.'icl_translations';
  $isWPML=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t;
  $join=$isWPML?'LEFT JOIN '.$t.' t ON t.element_id=p.ID AND t.element_type=CONCAT("post_",p.post_type)':'';
  $lang=$isWPML?'t.language_code AS language_code,t.trid AS trid':'"en" AS language_code,NULL AS trid';
  $query='SELECT p.ID,p.post_type,p.post_title,p.post_status,'.$lang.' FROM '.$wpdb->posts.
    ' p INNER JOIN '.$wpdb->postmeta.' m ON m.post_id=p.ID AND m.meta_key="_elementor_data" '.
    $join.' WHERE p.post_status IN ("publish","draft","private","future") '.
    ' AND p.post_type NOT IN ("revision","attachment") ORDER BY p.ID';
  $rows=$wpdb->get_results($query,ARRAY_A);
  return is_array($rows)?$rows:[];
 }
 private static function service_state():array{
  $conf=(array)get_option('service-activation',[]);
  $result=[];
  foreach(['tours','properties','transportations','visas'] as $type){
   $result[$type]=[
    'standalone_enabled'=>function_exists('service_channel_allowed')?
     (bool)service_channel_allowed($type,'standalone'):null,
    'mode'=>$conf[$type.'_channel_mode']??null
   ];
  }
  return $result;
 }
 public static function scan(bool $store=true):array{
  if(self::$scanning)return ['complete'=>false,'reason'=>'reentrant'];
  self::$scanning=true;
  try {
   $posts=self::posts();$fields=[];$invalid=[];$widgets=0;$parsed=0;
   $langs=[];
   foreach($posts as $p){
    $id=(int)$p['ID'];$raw=(string)get_post_meta($id,'_elementor_data',true);
    if(trim($raw)===''){$invalid[]=['id'=>$id,'reason'=>'empty'];continue;}
    $tree=json_decode($raw,true);
    if(!is_array($tree)){$invalid[]=['id'=>$id,'reason'=>'json'];continue;}
    $parsed++;$lang=(string)($p['language_code']?:'unassigned');
    $langs[$lang]=($langs[$lang]??0)+1;
    self::walk($tree,$p,$fields,$widgets);
   }
   $states=[];$localeIndex=[];
   foreach($fields as $i=>$row){
    $states[$row['state']]=($states[$row['state']]??0)+1;
    if($row['trid'] && $row['state']==='static'){
     $key=$row['trid'].'|'.$row['widget_id'].'|'.$row['path'];
     $localeIndex[$key][$row['lang']]=$i;
    }
   }
   $alerts=[];$seen=[];
   $default=(string)apply_filters('wpml_default_language','en');
   if(!$default)$default='en';
   foreach($fields as $r){
    if($r['status']!=='publish')continue;
    $code='';$priority=0;$reason='';
    $hero=($r['widget']==='heading' && $r['heading']==='h1' && $r['field']==='title'
      && $r['state']==='static' && $r['lang']!==$default
      && in_array($r['widget_id'],['ea1eb12'],true)
      && str_contains($r['template'],'Home Hero Section 2'));
    if($hero){
     $code='hero_static_locale';$priority=100;$reason='Localized homepage Hero H1 has no dynamic copy source.';
    }elseif($r['field']==='html'&&$r['lang']!==$default &&
      $r['state']==='dfsb_runtime' &&
      str_contains($r['value'],'Select a filter to preview matching tours.')){
     $code='catalog_fallback_en';$priority=95;$reason='Translated archive still has English filter summary fallback.';
    }elseif($r['field']==='item_title'&&str_contains($r['value'],'Day of Weak')){
     $code='filter_week_typo';$priority=75;$reason='Day of Week label has a typo.';
    }elseif($r['state']==='static'&&$r['lang']!==$default&&$r['trid']){
     $k=$r['trid'].'|'.$r['widget_id'].'|'.$r['path'];
     $src=$localeIndex[$k][$default]??null;
     if($src!==null&&$fields[$src]['value']===$r['value']&&mb_strlen($r['value'])>=18
        &&str_word_count($r['value'])>=3){
       $code='same_as_source';$priority=78;$reason='Review long literal shared with source-language template.';
     }
    }
    if(!$code)continue;
    $identity=$code.'|'.$r['id'].'|'.$r['widget_id'].'|'.$r['path'];
    if(isset($seen[$identity]))continue;$seen[$identity]=true;
    $alerts[]=[
      'code'=>$code,'priority'=>$priority,'reason'=>$reason,
      'post_id'=>$r['id'],'locale'=>$r['lang'],'template'=>$r['template'],
      'widget'=>$r['widget'],'widget_id'=>$r['widget_id'],
      'path'=>$r['path'],'value'=>$r['value'],'sha'=>$r['sha']
    ];
   }
   usort($alerts,static fn($a,$b)=>$b['priority']<=>$a['priority']?:$a['post_id']<=>$b['post_id']);
   $result=[
    'contract'=>self::CONTRACT,'version'=>'1.0.0',
    'site'=>home_url('/'),'scanned_at'=>gmdate('c'),
    'records'=>count($posts),'parsed'=>$parsed,
    'invalid'=>$invalid,'widgets'=>$widgets,'fields'=>count($fields),
    'states'=>$states,'languages'=>$langs,
    'alerts_total'=>count($alerts),'alerts_by_code'=>array_count_values(array_column($alerts,'code')),
    'alerts'=>array_slice($alerts,0,400),'truncated'=>count($alerts)>400,
    'service_state'=>self::service_state(),'no_frontend_fetch'=>true,'content_changes'=>false
   ];
   if($store){update_option(self::STORE,$result,false);delete_option(self::DIRTY);}
   return $result;
  }finally{self::$scanning=false;}
 }
 public static function dirty($mid,$id,$key):void{
  if($key!=='_elementor_data')return;
  $p=get_post((int)$id);if(!$p||$p->post_status==='inherit')return;
  $map=(array)get_option(self::DIRTY,[]);
  $map[(int)$id]=gmdate('c');
  if(count($map)>300)$map=array_slice($map,-300,null,true);
  update_option(self::DIRTY,$map,false);
 }
 public static function menu():void{
  add_management_page('MAD4B Elementor Copy Coverage','MAD4B Copy Coverage',
   'manage_options','mad4b-copy-coverage',[self::class,'admin']);
 }
 public static function admin():void{
  if(!current_user_can('manage_options'))wp_die('Forbidden');
  if(isset($_POST['mad4b_scan'])&&check_admin_referer('mad4b_copy_coverage')){
   self::scan(true);
   echo '<div class="notice notice-success"><p>Coverage scan completed.</p></div>';
  }
  $r=get_option(self::STORE,[]);
  echo '<div class="wrap"><h1>MAD4B Elementor Copy Coverage</h1>'.
    '<p>Deep widget controls and nested Elementor repeaters; safe read-only scan, WPML parity, DFSB aware.</p>';
  echo '<form method="post">';
  wp_nonce_field('mad4b_copy_coverage');
  submit_button('Run full coverage scan','primary','mad4b_scan');
  echo '</form>';
  if(!is_array($r)||!$r){echo '<p>No saved scan.</p></div>';return;}
  echo '<p>Last: '.esc_html($r['scanned_at']).
   ' | Posts: '.absint($r['records']).
   ' | Widgets: '.absint($r['widgets']).
   ' | Text fields: '.absint($r['fields']).
   ' | Review items: '.absint($r['alerts_total']).
   ' | Modified since scan: '.count((array)get_option(self::DIRTY,[])).'</p>';
  echo '<table class="widefat striped"><thead><tr><th>Priority</th><th>Language</th><th>Template</th><th>Widget</th><th>Internal field</th><th>Reason and text</th></tr></thead><tbody>';
  foreach(array_slice((array)$r['alerts'],0,120) as $a){
   echo '<tr><td>'.(int)$a['priority'].'</td><td>'.esc_html($a['locale']).
    '</td><td><a href="'.esc_url(get_edit_post_link((int)$a['post_id'])).'">'.
    esc_html($a['template']).'</a></td><td>'.esc_html($a['widget_id']).
    '</td><td><code>'.esc_html($a['path']).'</code></td><td>'.
    esc_html($a['reason']).'<p><small>'.esc_html($a['value']).'</small></p></td></tr>';
  }
  echo '</tbody></table></div>';
 }
 public static function cli($args):void{
  $act=$args[0]??'status';
  if($act==='scan')$r=self::scan(true);
  elseif($act==='preview')$r=self::scan(false);
  elseif($act==='status')$r=get_option(self::STORE,[]);
  elseif($act==='dirty')$r=get_option(self::DIRTY,[]);
  else \WP_CLI::error('Usage: wp mad4b-copy-coverage scan|preview|status|dirty');
  \WP_CLI::line(wp_json_encode($r,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void{
  add_action('updated_post_meta',[self::class,'dirty'],140,3);
  add_action('added_post_meta',[self::class,'dirty'],140,3);
  if(is_admin())add_action('admin_menu',[self::class,'menu'],90);
  if(defined('WP_CLI')&&WP_CLI)\WP_CLI::add_command('mad4b-copy-coverage',[self::class,'cli']);
 }
}
add_action('init',['MAD4B_Elementor_Copy_Coverage','boot'],91);
