<?php
defined('ABSPATH') || exit;

/**
 * Read-only inventory of registered MAD4B-related admin routes.
 * No route execution, no sensitive input values, no plugin admin rewrites.
 */
final class MAD4B_Native_WP_Screen_Coverage_V1 {
    private static function relevant(string $parent,string $slug):bool {
        if(str_contains($parent,'mad4b')||str_contains($slug,'mad4b'))return true;
        return in_array($slug,[
            'service-activation','dynamic-pricing-controls',
            'etg-dynamic-pricing-controls','look-and-feel',
        ],true);
    }
    public static function classify(string $parent,string $slug):array {
        if($parent==='mad4b-jetengine' || $slug==='service-activation' ||
           $slug==='look-and-feel' || str_contains($slug,'dynamic-pricing-controls')){
            return ['family'=>'JetEngine / Recipe','adapter'=>'JetEngine Recipe or dedicated pricing','risk'=>'review'];
        }
        if(str_contains($slug,'media')||str_contains($parent,'upload.php'))
            return ['family'=>'Media / Upload','adapter'=>'Core Media opt-in','risk'=>'high'];
        if(str_contains($slug,'pricing')||str_contains($parent,'woocommerce')||
           str_contains($slug,'rate-')||str_contains($slug,'payment'))
            return ['family'=>'Commerce / Pricing','adapter'=>'Commerce controls and field validation','risk'=>'high'];
        if(str_contains($slug,'code')||str_contains($slug,'execution')||
           str_contains($slug,'proxy')||str_contains($slug,'operations')||
           str_contains($slug,'control-plane'))
            return ['family'=>'Operations / Control Plane','adapter'=>'Native form/table audit then isolated opt-in','risk'=>'high'];
        if(str_contains($slug,'content')||str_contains($slug,'hub')||
           str_contains($slug,'review')||str_contains($slug,'taxonomy'))
            return ['family'=>'Content / Editorial','adapter'=>'Core tables and editor-aware adapter','risk'=>'review'];
        if(str_contains($slug,'settings')||str_contains($parent,'options-general.php')||
           str_contains($slug,'brand')||str_contains($slug,'shell'))
            return ['family'=>'Settings / Brand','adapter'=>'Core forms/notice/table scoped adapter','risk'=>'review'];
        return ['family'=>'Company / Other','adapter'=>'Screen audit required before styling','risk'=>'review'];
    }
    public static function scan():array {
        global $submenu;
        $rows=[];
        foreach((array)$submenu as $parent=>$pages){
            foreach((array)$pages as $page){
                $slug=(string)($page[2]??'');
                if(!self::relevant((string)$parent,$slug))continue;
                $class=self::classify((string)$parent,$slug);
                $rows[]=[
                    'parent'=>(string)$parent,
                    'slug'=>$slug,
                    'title'=>wp_strip_all_tags((string)($page[0]??$slug)),
                    'capability'=>(string)($page[1]??''),
                    'family'=>$class['family'],
                    'adapter'=>$class['adapter'],
                    'risk'=>$class['risk'],
                    // Never call "covered" without per-screen browser acceptance.
                    'accepted'=>false,
                ];
            }
        }
        usort($rows,static fn($a,$b)=>strcmp($a['family'],$b['family'])?:strcmp($a['slug'],$b['slug']));
        $groups=[];foreach($rows as $row)$groups[$row['family']]=($groups[$row['family']]??0)+1;
        ksort($groups);
        return ['total'=>count($rows),'groups'=>$groups,'screens'=>$rows,
            'scope'=>'Menu discovery only. Does not certify functional UX or Save.'];
    }
    public static function render():void {
        $data=self::scan();
        echo '<section class="mad4b-native-screen-registry" aria-labelledby="mad4b-native-screens-heading">';
        echo '<h2 id="mad4b-native-screens-heading">MAD4B Admin Screen Coverage Registry</h2>';
        echo '<p>Live discovery from registered admin menu routes. Categories are planning signals, not visual or functional approval. WordPress and plugin actions remain unchanged.</p>';
        echo '<p><strong>'.esc_html((string)$data['total']).'</strong> related admin routes discovered in this session · <strong>0</strong> new cross-screen Adapters automatically applied.</p>';
        echo '<div class="mad4b-native-screen-toolbar"><label for="mad4b-native-screen-query">Search screens';
        echo '<input type="search" id="mad4b-native-screen-query" placeholder="Screen name or slug" autocomplete="off"></label>';
        echo '<label for="mad4b-native-screen-family">Component family<select id="mad4b-native-screen-family">';
        echo '<option value="">All families</option>';
        foreach(array_keys($data['groups']) as $group)
            echo '<option value="'.esc_attr($group).'">'.esc_html($group).'</option>';
        echo '</select></label></div>';
        echo '<p id="mad4b-native-screen-results" role="status" aria-live="polite">'.esc_html((string)$data['total']).' screens shown</p>';
        echo '<div class="mad4b-native-scroll" role="region" tabindex="0" aria-label="MAD4B screen coverage registry">';
        echo '<table class="widefat striped" id="mad4b-native-screen-table"><thead><tr>';
        foreach(['Screen','Parent','Component family','Recommended adapter','Risk'] as $heading)
            echo '<th scope="col">'.esc_html($heading).'</th>';
        echo '</tr></thead><tbody>';
        foreach($data['screens'] as $item){
            echo '<tr data-family="'.esc_attr($item['family']).'"><td><strong>'.
             esc_html($item['title']).'</strong><br><code>'.
             esc_html($item['slug']).'</code></td><td><code>'.
             esc_html($item['parent']).'</code></td><td>'.
             esc_html($item['family']).'</td><td>'.
             esc_html($item['adapter']).'</td><td>'.
             esc_html($item['risk']==='high'?'High — functional QA':'Screen QA pending').
             '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
}
