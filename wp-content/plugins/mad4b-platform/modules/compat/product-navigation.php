<?php
/** Policy-backed native Elementor navigation. */
defined('ABSPATH') || exit;
function product_navigation_registry(): array {
    return apply_filters('product_navigation_registry', [
        'tours'=>['post_type'=>'tours-and-activities','label'=>'Tours & Activities','taxonomies'=>['tour-types','tour_categories','tour-styles','destinations']],
        'properties'=>['post_type'=>'properties','label'=>'Accommodation'],
        'transportations'=>['post_type'=>'transportations','label'=>'Transportation'],
        'visas'=>['post_type'=>'travel-visa','label'=>'Visas'],
    ]);
}
function product_navigation_sections(string $language = '', string $surface = 'header'): array {
    // Elementor renders dynamic menu labels repeatedly in one HTTP request.
    // Reuse the unfiltered read-only catalog sections only for an anonymous
    // public homepage. Reapply the surface filter on EVERY invocation.
    // Do not persist, or cache admin, REST, AJAX, market, or session context.
    static $request_sections = [];
    $anonymous_home = PHP_SAPI !== 'cli'
        && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        && !is_admin() && !wp_doing_ajax() && !is_user_logged_in()
        && !defined('REST_REQUEST') && did_action('wp') > 0
        && is_front_page() && empty($_SERVER['HTTP_COOKIE'])
        && empty($_SERVER['QUERY_STRING'])
        && empty($_SERVER['HTTP_AUTHORIZATION'])
        && empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
        && empty($_SERVER['HTTP_X_GEO_COUNTRY'])
        && empty($_SERVER['HTTP_CF_IPCOUNTRY'])
        && empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY']);
    $memo_key = $language . '|' . (string) apply_filters('wpml_current_language', null);
    if ($anonymous_home && array_key_exists($memo_key, $request_sections)) {
        return apply_filters('product_navigation_sections', $request_sections[$memo_key], $language, $surface);
    }
    $sections=[];
    if (!function_exists('service_channel_allowed')) return [];
    foreach(product_navigation_registry() as $key=>$service) {
        if (!Presentation_Policy_Context_v1::decide($key,'navigation','discover')['allowed'] || !post_type_exists($service['post_type'])) continue;
        $url=get_post_type_archive_link($service['post_type']);
        if (!$url) continue;
        $url=apply_filters('wpml_permalink',$url,$language,true);
        $type=get_post_type_object($service['post_type']);
        $label=apply_filters('wpml_translate_single_string',$service['label'],'Product navigation',$key,$language);
        $children=[];
        foreach(get_object_taxonomies($service['post_type'],'objects') as $taxonomy) {
            if (!$taxonomy->public || !$taxonomy->publicly_queryable || (isset($service['taxonomies']) && !in_array($taxonomy->name,$service['taxonomies'],true))) continue;
            $terms=get_terms(product_navigation_term_args($key,$taxonomy->name,$language,['number'=>4]));
            if(is_wp_error($terms)) continue;
            foreach($terms as $term) {
                $link=get_term_link($term);
                if(!is_wp_error($link)) $children[]=['key'=>$taxonomy->name.':'.$term->term_id,'label'=>$term->name,'url'=>add_query_arg('post_type',$service['post_type'],apply_filters('wpml_permalink',$link,$language,true)),'group'=>$taxonomy->labels->name];
            }
            if(count($children)>=16) break;
        }
        $eligible=product_navigation_eligible_post_ids($key,$language);
        $post_args=['post_type'=>$service['post_type'],'post_status'=>'publish','posts_per_page'=>4,'suppress_filters'=>false,'lang'=>$language];
        if($eligible!==null)$post_args['post__in']=$eligible?:[0];
        $posts=get_posts($post_args);
        foreach($posts as $post)$children[]=['key'=>'post:'.$post->ID,'label'=>get_the_title($post),'url'=>get_permalink($post),'group'=>$type->labels->name];
        $sections[]=['key'=>$key,'label'=>$label,'url'=>$url,'children'=>$children];
    }
    if ($anonymous_home) $request_sections[$memo_key] = $sections;
    return apply_filters('product_navigation_sections',$sections,$language,$surface);
}
function product_navigation_build_native_node(array $node, string $language): array {
    $rows=[['key'=>'home','label'=>__('Home'),'url'=>apply_filters('wpml_home_url',home_url('/')),'children'=>[]]];
    $rows=array_merge($rows,product_navigation_sections($language,'header'));
    $base_menu=get_term_by('slug',apply_filters('product_navigation_menu_slug','main-menu-en'),'nav_menu');
    $menu_id=$base_menu?apply_filters('wpml_object_id',$base_menu->term_id,'nav_menu',false,$language):null;
    $menu=$menu_id?wp_get_nav_menu_items($menu_id):[];
    $front_id=apply_filters('wpml_object_id',(int)get_option('page_on_front'),'page',false,$language);
    foreach($menu?:[] as $item) {
        if($item->menu_item_parent) continue;
        if($item->type==='taxonomy' && taxonomy_exists($item->object)) {
            $id=apply_filters('wpml_object_id',(int)$item->object_id,$item->object,false,$language);
            $taxonomy=get_taxonomy($item->object);$owners=array_filter(product_navigation_registry(),fn($r,$k)=>in_array($r['post_type'],$taxonomy->object_type,true)&&service_channel_allowed($k,'destination_discovery'),ARRAY_FILTER_USE_BOTH);
            if(!$id || !$owners) continue;
            $term=get_term($id,$item->object);if(!$term || is_wp_error($term)) continue;
            $url=get_term_link($term);if(is_wp_error($url)) continue;
            $terms=get_terms(['taxonomy'=>$item->object,'parent'=>$id,'hide_empty'=>true,'number'=>12,'lang'=>$language]);$links=[];
            foreach(is_wp_error($terms)?[]:$terms as $child){$link=get_term_link($child);if(!is_wp_error($link))$links[]=['key'=>'term:'.$child->term_id,'label'=>$child->name,'url'=>$link];}
            $rows[]=['key'=>'term:'.$id,'label'=>$term->name,'url'=>$url,'children'=>$links];continue;
        }
        if($item->object!=='page' || (int)$item->object_id===(int)$front_id) continue;
        $owner=apply_filters('product_navigation_page_service',null,(int)$item->object_id);
        if($owner!==null && !service_channel_allowed($owner,'standalone'))continue;
        $id=apply_filters('wpml_object_id',(int)$item->object_id,'page',false,$language);
        if(!$id) continue;
        $rows[]=['key'=>'page:'.$id,'label'=>get_the_title($id),'url'=>get_permalink($id),'children'=>[]];
    }
    $order=['home'=>0];$position=1;
    foreach($menu?:[] as$item){if($item->menu_item_parent)continue;$key=null;if($item->type==='post_type_archive'){foreach(product_navigation_registry()as$owner=>$service)if($service['post_type']===$item->object)$key=$owner;}elseif($item->type==='taxonomy'){$id=apply_filters('wpml_object_id',(int)$item->object_id,$item->object,false,$language);$key='term:'.$id;}elseif($item->object==='page'){$id=apply_filters('wpml_object_id',(int)$item->object_id,'page',false,$language);$key=(int)$id===(int)$front_id?'home':'page:'.$id;}if($key){$order[$key]=$position++;foreach($rows as&$row)if($row['key']===$key)$row['label']=$item->title;unset($row);}}
    usort($rows,fn($a,$b)=>($order[$a['key']]??$position)<=>($order[$b['key']]??$position));
    $items=[];$children=[];$saved=[];
    $collect=function($nodes)use(&$collect,&$saved){foreach($nodes as$n){$saved[$n['id']]=$n['settings']??[];$collect($n['elements']??[]);}};$collect($node['elements']??[]);
    foreach($rows as $row) {
        $row['key']=product_navigation_canonical_key($row['key']);
        $id=substr(md5($node['id'].$row['key']),0,7);
        $items[]=['_id'=>$id,'product_navigation_key'=>$row['key'],'item_title'=>$row['label'],'item_link'=>['url'=>$row['url'],'is_external'=>'','nofollow'=>''],'item_dropdown_content'=>$row['children']?'yes':'no','element_id'=>''];
        $last=count($items)-1;
        $items[$last]['__dynamic__']=['item_title'=>product_navigation_tag_markup('product-navigation-label',['key'=>$row['key']]),'item_link'=>product_navigation_tag_markup('product-navigation-url',['key'=>$row['key']])];
        $inner=[];
        foreach($row['children'] as $child) {
            $inner[]=['id'=>substr(md5($id.$child['key']),0,7),'elType'=>'widget','widgetType'=>'button','settings'=>['text'=>$child['label'],'link'=>['url'=>$child['url']],'button_type'=>'info','align'=>'left','typography_typography'=>'custom','typography_font_family'=>'Arial','typography_font_size'=>['unit'=>'px','size'=>16],'button_text_color'=>'#202f59','background_color'=>'#FFFFFF00','text_padding'=>['unit'=>'px','top'=>'8','right'=>'12','bottom'=>'8','left'=>'12','isLinked'=>false]],'elements'=>[]];
        }
        foreach($inner as$i=>&$button){$settings=['key'=>$row['key'],'entry'=>$row['children'][$i]['key']];$button['settings']['__dynamic__']=['text'=>product_navigation_tag_markup('product-navigation-label',$settings),'link'=>product_navigation_tag_markup('product-navigation-url',$settings)];}unset($button);
        $groups=[];
        foreach($inner as $i=>$button){$group=$row['children'][$i]['group']??$row['label'];$button['settings']=array_replace($button['settings'],$saved[$button['id']]??[],['text'=>$button['settings']['text'],'link'=>$button['settings']['link']]);$groups[$group][]=$button;}
        $inner=[];
        foreach($groups as$label=>$buttons){$gid=substr(md5($id.$label),0,7);$heading=['id'=>substr(md5($gid.'title'),0,7),'elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>$label,'header_size'=>'h3','title_color'=>'#47729E','typography_typography'=>'custom','typography_font_family'=>'Arial','typography_font_size'=>['unit'=>'px','size'=>18]],'elements'=>[]];$heading['settings']=array_replace($heading['settings'],$saved[$heading['id']]??[],['title'=>$label]);$inner[]=['id'=>$gid,'elType'=>'container','isInner'=>true,'settings'=>array_replace(['_title'=>$label,'content_width'=>'full','width'=>['unit'=>'%','size'=>23],'width_tablet'=>['unit'=>'%','size'=>47],'width_mobile'=>['unit'=>'%','size'=>100],'flex_direction'=>'column','gap'=>['unit'=>'px','size'=>4,'column'=>4,'row'=>4]],$saved[$gid]??[]),'elements'=>array_merge([$heading],$buttons)];}
        $children[]=['id'=>substr(md5($id.'panel'),0,7),'elType'=>'container','isInner'=>true,'settings'=>['_title'=>$row['label'],'content_width'=>'full','flex_direction'=>'row','flex_wrap'=>'wrap','background_background'=>'classic','background_color'=>'#FFFFFF','padding'=>['unit'=>'px','top'=>'20','right'=>'20','bottom'=>'20','left'=>'20','isLinked'=>true]],'elements'=>$inner];
    }
    foreach($children as &$child)$child['settings']=array_replace($child['settings'],$saved[$child['id']]??[]);unset($child);
    $mark=function($nodes)use(&$mark){foreach($nodes as&$n){$n['settings']['_css_classes']=trim(($n['settings']['_css_classes']??'').' product-navigation-generated');if(($n['elType']??'')==='container')$n['settings']['_css_classes'].=isset($n['settings']['width'])?' product-navigation-group':' product-navigation-panel';$n['elements']=$mark($n['elements']??[]);}return $nodes;};
    $node['settings']['menu_items']=$items;$node['elements']=$mark($children);
    return $node;
}
function product_navigation_canonical_key(string $key): string {
    if(preg_match('/^(term|page):(\d+)$/',$key,$m)) {
        $default=apply_filters('wpml_default_language',null);$type=$m[1]==='page'?'page':(get_term((int)$m[2])->taxonomy??'');
        $id=$type?apply_filters('wpml_object_id',(int)$m[2],$type,true,$default):(int)$m[2];
        return $m[1].':'.$id;
    }
    return $key;
}
/** Policy controls topology; the editor controls each authorized native panel. */
function product_navigation_native_node(array $node, string $language): array {
    $fresh=product_navigation_build_native_node($node,$language);
    $stored=[];$unbound=[];
    foreach($node['settings']['menu_items']??[]as$i=>$item){
        $key=$item['product_navigation_key']??null;
        if(!$key){foreach($fresh['settings']['menu_items']as$row)if(($item['_id']??'')===$row['_id']){$key=$row['product_navigation_key'];break;}}
        $pair=['item'=>$item,'child'=>$node['elements'][$i]??null];
        if($key)$stored[product_navigation_canonical_key($key)]=$pair;else$unbound[]=$pair;
    }
    $items=[];$children=[];
    foreach($fresh['settings']['menu_items']as$i=>$row){
        $key=$row['product_navigation_key'];$pair=$stored[$key]??null;
        $item=$pair?$pair['item']:$row;$item['product_navigation_key']=$key;
        $items[]=$item;$children[]=$pair&&$pair['child']?$pair['child']:$fresh['elements'][$i];
    }
    foreach($unbound as$pair){$items[]=$pair['item'];$children[]=$pair['child']??['id'=>substr(md5($node['id'].count($items)),0,7),'elType'=>'container','isInner'=>true,'settings'=>[],'elements'=>[]];}
    $node['settings']['menu_items']=$items;$node['elements']=$children;return $node;
}
add_filter('elementor/frontend/builder_content_data',function($data,$post_id){
    $language=apply_filters('wpml_current_language',null) ?: 'en';
    $walk=function($nodes)use(&$walk,$language){foreach($nodes as &$node){if(($node['widgetType']??'')==='mega-menu' && str_contains($node['settings']['_css_classes']??'','product-native-navigation'))$node=product_navigation_native_node($node,$language);else $node['elements']=$walk($node['elements']??[]);}return $nodes;};
    return $walk($data);
},20,2);
add_action('wp_enqueue_scripts',function(){wp_enqueue_style('product-native-navigation',plugins_url('product-navigation.css',(WPMU_PLUGIN_DIR.'/product-navigation.php')),[],'20261026.2');});
// Elementor output caching must never freeze a policy/language projection.
add_filter('elementor/element/is_dynamic_content',function($dynamic,$raw){return $dynamic || str_contains($raw['settings']['_css_classes']??'','product-navigation-generated') || str_contains($raw['settings']['_css_classes']??'','product-native-navigation');},20,2);
add_action('updated_option',function($name){if(in_array($name,['service-activation','product_navigation_sources'],true)){ // WordPress already invalidates the changed option; keep HTML purge only.
 do_action('mad4b/public_html/safe_purge_all', 'product_navigation');}},20,1);
// Header option images can retain an old origin after a local copy. Resolve only
// the site-icon dynamic image, and only when the exact uploaded file exists.
add_filter('elementor/widget/render_content',function($html,$widget){
    if($widget->get_name()!=='image' || !str_contains($widget->get_settings('__dynamic__')['image']??'','site_icon'))return $html;
    $uploads=wp_get_upload_dir();
    $html=preg_replace_callback('~https?://[^\s"\'<>]+/wp-content/uploads/([^\s"\'<>]+)~',function($m)use($uploads){$path=rawurldecode(explode('?',$m[1])[0]);return !str_contains($path,'..')&&is_file($uploads['basedir'].'/'.$path)?$uploads['baseurl'].'/'.$m[1]:$m[0];},$html);
    $html=str_replace('loading="lazy"','loading="eager" data-no-lazy="1" fetchpriority="high"',$html);
    return $html;
},30,2);
