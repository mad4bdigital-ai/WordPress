<?php
/**
 * Plugin Name: MAD4B Native Page SEO & Presentation Modes
 * Description: Dynamic site-neutral catalog, search, blog, destination and single-service modes. Rank Math is the SEO source of truth; JetEngine is the editing facade.
 * Version: 0.4.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Native_Page_Modes {
    const PAGE = 'look-and-feel';
    const META_HEADING = '_mad4b_presentation_heading';
    const META_INTRO = '_mad4b_presentation_intro';
    const CLI = 'mad4b-page-modes';
    private static bool $busy = false;

    private static function text($value): string {
        return is_scalar($value) ? (string)$value : '';
    }
    private static function types(): array {
        $out = [];
        foreach (get_post_types(['public'=>true], 'objects') as $key=>$pt) {
            if (in_array($key, ['attachment','elementor_library','e-floating-buttons'], true)) continue;
            if (!$pt->publicly_queryable && $key!=='post') continue;
            if ($key==='post' || !empty($pt->has_archive)
                || in_array($key,['tours-and-activities','properties','transportations'],true)) {
                $out[$key] = $pt;
            }
        }
        ksort($out);
        return $out;
    }
    private static function taxes(): array {
        $keys = ['destinations'];
        if (post_type_exists('tours-and-activities')) {
            foreach (get_object_taxonomies('tours-and-activities','objects') as $name=>$tx) {
                if ($tx->public && $tx->publicly_queryable && $tx->show_ui) $keys[]=$name;
            }
        }
        $out=[];
        foreach(array_unique($keys) as $name)if(taxonomy_exists($name))$out[$name]=get_taxonomy($name);
        ksort($out);
        return $out;
    }
    public static function registry(): array {
        $result=[];
        $add=static function($field,$section,$label,$kind,$source,$key,$post_id=0) use (&$result) {
            $result[$field]=compact('field','section','label','kind','source','key','post_id');
        };
        $add('mad4b_mode_search_title','search_blog','Search · SEO Title template','text','option','search_title');
        if($id=(int)get_option('page_for_posts')) {
            $add('mad4b_mode_blog_title','search_blog','Blog · SEO Title','text','post','rank_math_title',$id);
            $add('mad4b_mode_blog_description','search_blog','Blog · Meta Description','textarea','post','rank_math_description',$id);
        }
        foreach(self::types() as $type=>$pt) {
            $safe=str_replace('-','_',$type);
            if(!empty($pt->has_archive) && $type!=='post' && $type!=='product') {
                $add('mad4b_mode_archive_'.$safe.'_title','archive',
                    $pt->labels->name.' · Archive SEO Title','text','option','pt_'.$type.'_archive_title');
                $add('mad4b_mode_archive_'.$safe.'_description','archive',
                    $pt->labels->name.' · Archive Meta Description','textarea','option','pt_'.$type.'_archive_description');
            }
            $add('mad4b_mode_single_'.$safe.'_title','single',
                $pt->labels->name.' · Single SEO Title pattern','text','option','pt_'.$type.'_title');
            $add('mad4b_mode_single_'.$safe.'_description','single',
                $pt->labels->name.' · Single Meta Description pattern','textarea','option','pt_'.$type.'_description');
        }
        foreach(self::taxes() as $type=>$tx) {
            $safe=str_replace('-','_',$type);
            $add('mad4b_mode_tax_'.$safe.'_title','taxonomy',
                $tx->labels->name.' · SEO Title pattern','text','option','tax_'.$type.'_title');
            $add('mad4b_mode_tax_'.$safe.'_description','taxonomy',
                $tx->labels->name.' · Meta Description pattern','textarea','option','tax_'.$type.'_description');
        }
        return $result;
    }
    public static function jet_fields($fields,$page) {
        // The JetEngine option page is an authoring surface only; skip
        // building 100+ admin controls in normal visitor-facing requests.
        if(!is_admin() && !(defined('WP_CLI') && WP_CLI))return $fields;
        if(!is_object($page)||($page->slug??'')!==self::PAGE || !is_array($fields))return $fields;
        $have=[];
        foreach($fields as $f)if(is_array($f)&&isset($f['name']))$have[$f['name']]=true;
        $groups=[
            'search_blog'=>['mad4b_modes_search_blog_tab','SEO Modes · Search & Blog'],
            'archive'=>['mad4b_modes_archive_tab','SEO Modes · Service Archives'],
            'single'=>['mad4b_modes_single_tab','SEO Modes · Single Service Defaults'],
            'taxonomy'=>['mad4b_modes_tax_tab','SEO Modes · Destination & Taxonomy']
        ];
        foreach($groups as $section=>$tab) {
            $missing=[];
            foreach(self::registry() as $field=>$spec)
                if($spec['section']===$section && !isset($have[$field]))$missing[$field]=$spec;
            if($section==='search_blog'){
                foreach([
                    'mad4b_mode_search_heading'=>['Search · Visible H1 override (EN source)','text'],
                    'mad4b_mode_search_intro'=>['Search · Visible introduction (EN source)','textarea']
                ] as $name=>$data)if(!isset($have[$name]))$missing[$name]=[
                    'field'=>$name,'label'=>$data[0],'kind'=>$data[1],'source'=>'presentation'];
            }
            if(!$missing)continue;
            $fields[]=['name'=>$tab[0],'title'=>$tab[1],'type'=>'text',
                'object_type'=>'tab','width'=>'100%','tab_layout'=>'vertical'];
            foreach($missing as $key=>$s) {
                $fields[]=['name'=>$key,'title'=>$s['label'],'type'=>$s['kind'],
                    'object_type'=>'field','width'=>'100%',
                    'description'=>($s['source']==='presentation'
                        ?'Native presentation override; optional, default-language only.'
                        :'Read/write native Rank Math value; existing translated posts/terms remain unchanged.'),
                    'options'=>[],'repeater-fields'=>[],'options_source'=>'manual',
                    'sanitize'=>'default'];
            }
        }
        return $fields;
    }

    public static function native(array $spec): string {
        if($spec['source']==='post') {
            $id=(int)$spec['post_id'];
            return $id && get_post($id) ? self::text(get_post_meta($id,$spec['key'],true)) : '';
        }
        $all=get_option('rank-math-options-titles',[]);
        return self::text(is_array($all)?($all[$spec['key']]??''):'');
    }
    public static function admin_native_options($options) {
        if(!is_admin() || !current_user_can('manage_options') || !is_array($options))return $options;
        $page = sanitize_key((string)($_GET['page']??''));
        if($page !== self::PAGE) return $options;
        foreach(self::registry() as $field=>$spec)$options[$field]=self::native($spec);
        return $options;
    }
    public static function plan_changes(array $old,array $new): array {
        $nativeChanges=[]; $postChanges=[]; $ignored=[];
        foreach(self::registry() as $field=>$spec){
            if(!array_key_exists($field,$new))continue;
            if($new[$field]===null){$ignored[]=$field;continue;}
            if((string)($old[$field]??'') === (string)$new[$field])continue;
            $value=sanitize_text_field(self::text($new[$field]));
            if($value===self::native($spec))continue;
            if($spec['source']==='option')$nativeChanges[$spec['key']]=$value;
            else $postChanges[(int)$spec['post_id']][$spec['key']]=$value;
        }
        return ['native_options'=>$nativeChanges,'posts'=>$postChanges,
            'ignored_null_fields'=>$ignored,'writes_performed'=>false];
    }
    public static function saved($old,$new): void {
        if(self::$busy || !current_user_can('manage_options') ||
            !is_array($old) || !is_array($new))return;
        $plan=self::plan_changes($old,$new);
        $nativeChanges=$plan['native_options'];
        $postChanges=$plan['posts'];
        if(!$nativeChanges && !$postChanges)return;
        self::$busy=true;
        try {
            if($nativeChanges){
                $rm=(array)get_option('rank-math-options-titles',[]);
                foreach($nativeChanges as $k=>$v)$rm[$k]=$v;
                update_option('rank-math-options-titles',$rm,false);
            }
            foreach($postChanges as $id=>$changes)if(get_post((int)$id)) {
                foreach($changes as $k=>$v)update_post_meta((int)$id,$k,$v);
                do_action('litespeed_purge_post',(int)$id);
                do_action('litespeed_purge_url',get_permalink((int)$id));
            }
            self::purge_global_keys(array_keys($nativeChanges));
            foreach($nativeChanges as $key=>$value)
                self::record_change('rank-math-global','0',$key,$value);
            foreach($postChanges as $id=>$changes)
                foreach($changes as $key=>$value)
                    self::record_change('rank-math-post',(string)$id,$key,$value);
            update_option('mad4b_native_page_modes_last_write',[
                'at'=>gmdate('c'),'option_keys'=>array_keys($nativeChanges),
                'post_ids'=>array_keys($postChanges),'state'=>'native_written'
            ],false);
        } finally {self::$busy=false;}
    }
    private static function purge_url(string $url): void {
        if(!str_starts_with($url,home_url('/')))return;
        do_action('litespeed_purge_url',$url);
        $path=wp_parse_url($url,PHP_URL_PATH) ?: '/';
        if(!preg_match('@^/[a-zA-Z0-9/_%-]*$@D',$path))return;
        $socket=WP_CONTENT_DIR.'/.mad4b-purge/origin.sock';
        if(!file_exists($socket))return;
        $conn=@stream_socket_client('unix://'.$socket,$errno,$error,1);
        if(!$conn)return;
        stream_set_timeout($conn,2);
        $payload=wp_json_encode([$path])."\n";
        if(fwrite($conn,$payload)===strlen($payload))@fgets($conn,4096);
        fclose($conn);
    }
    private static function record_change(string $origin,string $id,string $field,string $value): void {
        // Revision-only signal. No translation, job creation or publication.
        $lang=(string)apply_filters('wpml_current_language','en');
        $source=(string)apply_filters('wpml_default_language','en');
        $identity=home_url('/').'|'.$origin.'|'.$id.'|'.$field.'|'.$lang;
        $token=hash('sha256',$identity);
        $row=['token'=>$token,'origin'=>$origin,'object_id'=>$id,
            'native_key'=>$field,'language'=>$lang,
            'source_language'=>$source,'digest'=>hash('sha256',$value),
            'at'=>gmdate('c'),'stage'=>'SOURCE_CHANGED_REVIEW_REQUIRED',
            'translation_dispatched'=>false];
        $ledger=(array)get_option('mad4b_native_page_modes_changes_v1',[]);
        if(($ledger[$token]['digest']??'')===$row['digest'])return;
        $ledger[$token]=$row;
        if(count($ledger)>250)$ledger=array_slice($ledger,-250,null,true);
        update_option('mad4b_native_page_modes_changes_v1',$ledger,false);
        do_action('mad4b/page_modes/source_changed',$row);
    }
    private static function purge_global_keys(array $keys): void {
        $budget=80;
        foreach($keys as $key){
            if($budget<=0)break;
            if(str_starts_with($key,'pt_') && str_contains($key,'_archive_')) {
                $type=substr($key,3,strpos($key,'_archive_')-3);
                $url=get_post_type_archive_link($type);
                if($url){self::purge_url($url);$budget--;}
            } elseif(preg_match('/^tax_(.+)_(?:title|description)$/D',$key,$m)) {
                $tax=$m[1];
                if(!taxonomy_exists($tax))continue;
                // Bounded purge to avoid an unbounded full-site cache stampede.
                $terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,
                    'number'=>min(20,$budget),'orderby'=>'count','order'=>'DESC']);
                if(is_wp_error($terms))continue;
                foreach($terms as $term) {
                    if($budget<=0)break;
                    $link=get_term_link($term);
                    if(!is_wp_error($link)){self::purge_url($link);$budget--;}
                }
            }
        }
    }
    public static function native_option_changed($old,$new): void {
        if(self::$busy || !is_array($old)||!is_array($new))return;
        $allowed=[];
        foreach(self::registry() as $spec)if($spec['source']==='option')$allowed[$spec['key']]=true;
        $changed=[];
        foreach($allowed as $key=>$_){
            $before=self::text($old[$key]??'');
            $after=self::text($new[$key]??'');
            if($before===$after)continue;
            $changed[]=$key;
            self::record_change('rank-math-global','0',$key,$after);
        }
        self::purge_global_keys($changed);
    }
    public static function native_post_meta_changed($mid,$post_id,$key): void {
        if(self::$busy || !in_array($key,['rank_math_title','rank_math_description'],true))return;
        if(!get_post((int)$post_id))return;
        $value=self::text(get_post_meta((int)$post_id,$key,true));
        self::record_change('rank-math-post',(string)$post_id,$key,$value);
        $link=get_permalink((int)$post_id);
        if($link)self::purge_url($link);
    }
    public static function native_term_meta_changed($mid,$term_id,$key): void {
        if(self::$busy || !in_array($key,['rank_math_title','rank_math_description'],true))return;
        $term=get_term((int)$term_id);
        if(!($term instanceof WP_Term))return;
        $value=self::text(get_term_meta((int)$term_id,$key,true));
        self::record_change('rank-math-term',(string)$term_id,$key,$value);
        $link=get_term_link($term);
        if(!is_wp_error($link))self::purge_url($link);
    }
    private static function locale_override_approved(string $object,int $id): bool {
        $lang=(string)apply_filters('wpml_current_language','en');
        $source=(string)apply_filters('wpml_default_language','en');
        if($lang===$source)return true;
        $marked=$object==='term'?get_term_meta($id,'_mad4b_presentation_authoring_lang',true)
            :get_post_meta($id,'_mad4b_presentation_authoring_lang',true);
        return $marked===$lang;
    }

    public static function context(): array {
        if(is_404())return ['kind'=>'404','heading'=>'','intro'=>'','id'=>0];
        $kind='unknown';$heading='';$intro='';$id=0;
        if(is_search()) {
            $kind='search';$query=get_search_query(false);
            $options=(array)get_option(self::PAGE,[]);
            $default=sprintf(__('Search Results for: %s'),$query);
            $stored=self::text($options['mad4b_mode_search_heading']??'');
            $current=(string)apply_filters('wpml_current_language','en');
            $source=(string)apply_filters('wpml_default_language','en');
            $heading=($stored!=='' && $current===$source)
                ?str_replace('%search_query%',$query,$stored):$default;
            $intro=($current===$source)
                ?self::text($options['mad4b_mode_search_intro']??''):'';
        } elseif(is_front_page()){
            $kind='home';$id=(int)get_option('page_on_front');
            $heading=$id?get_the_title($id):get_bloginfo('name');
        } elseif(is_home()){
            $kind='blog';$id=(int)get_option('page_for_posts');
            $heading=$id?get_the_title($id):__('Blog');
            $intro=$id?get_post_field('post_excerpt',$id):'';
        } elseif(is_post_type_archive()){
            $kind='archive';$type=get_query_var('post_type');
            if(is_array($type))$type=reset($type);
            $pt=get_post_type_object((string)$type);
            $heading=$pt?$pt->labels->name:post_type_archive_title('',false);
            $intro=get_the_archive_description();
        } elseif(is_tax()||is_category()||is_tag()){
            $term=get_queried_object();
            if($term instanceof WP_Term){
                $kind='term';$id=(int)$term->term_id;
                $heading=$term->name;$intro=term_description($id,$term->taxonomy);
                if(self::locale_override_approved('term',$id)) {
                    $headingOverride=get_term_meta($id,self::META_HEADING,true);
                    $introOverride=get_term_meta($id,self::META_INTRO,true);
                    if(is_string($headingOverride)&&$headingOverride!=='')$heading=$headingOverride;
                    if(is_string($introOverride)&&$introOverride!=='')$intro=$introOverride;
                }
            }
        } elseif(is_singular()){
            $post=get_queried_object();
            if($post instanceof WP_Post){
                $kind='single';$id=(int)$post->ID;
                $heading=get_the_title($id);
                $intro=get_post_field('post_excerpt',$id);
                if(self::locale_override_approved('post',$id))
                    foreach(['heading'=>self::META_HEADING,'intro'=>self::META_INTRO] as $key=>$name){
                        $v=get_post_meta($id,$name,true);
                        if(is_string($v)&&trim($v)!==''){
                            if($key==='heading')$heading=$v;else $intro=$v;
                        }
                    }
            }
        }
        return ['kind'=>$kind,'heading'=>(string)$heading,'intro'=>(string)$intro,'id'=>$id];
    }
    public static function text_field(string $field): string {
        if(!in_array($field,['heading','intro'],true))return '';
        return (string)(self::context()[$field]??'');
    }
    public static function shortcode($atts): string {
        $atts=shortcode_atts(['field'=>'heading'],(array)$atts,'mad4b_page_context');
        $field=sanitize_key((string)$atts['field']);
        $text=self::text_field($field);
        return $field==='intro'?wp_kses_post($text):esc_html($text);
    }
    public static function jet_h1_value($current,$settings,$widget) {
        if($current!==null || !is_singular() || !is_array($settings))return $current;
        $post=get_queried_object();
        if(!($post instanceof WP_Post) || $post->post_status!=='publish')return $current;
        if(!self::locale_override_approved('post',$post->ID))return $current;
        $value=get_post_meta($post->ID,self::META_HEADING,true);
        if(!is_string($value)||trim($value)==='')return $current;
        // Only the main queried post's H1 Post Title JetEngine field.
        // Listing cards, recommendations, nested service widgets and SEO
        // tags must not inherit an unrelated post's presentation title.
        if(($settings['field_tag']??'')!=='h1' ||
            !is_object($widget)||!method_exists($widget,'get') ||
            (string)$widget->get('dynamic_field_source')!=='object' ||
            (string)$widget->get('dynamic_field_post_object')!=='post_title')return $current;
        $context=(string)($settings['object_context']??'');
        if($context!=='' && function_exists('jet_engine')){
            $obj=jet_engine()->listings->data->get_object_by_context($context);
            if($obj instanceof WP_Post && $obj->ID!==$post->ID)return $current;
        }
        return sanitize_text_field($value);
    }

    public static function register_tag($manager): void {
        if(!class_exists('\Elementor\Core\DynamicTags\Tag'))return;
        require_once __DIR__.'/mad4b-page-modes/elementor-tag.php';
        $manager->register(new MAD4B_Page_Context_Dynamic_Tag());
    }
    private static function eligible_type(string $type): bool {
        return isset(self::types()[$type]) || in_array($type,['post','page'],true);
    }
    public static function meta_box(): void {
        foreach(array_keys(self::types()) as $type)
            add_meta_box('mad4b_page_context_heading','MAD4B · Presentation Heading',
                [self::class,'render_box'],$type,'normal','default');
        add_meta_box('mad4b_page_context_heading','MAD4B · Presentation Heading',
            [self::class,'render_box'],'page','normal','default');
    }
    public static function render_box($post): void {
        wp_nonce_field('mad4b_page_context_'.$post->ID,'mad4b_page_context_nonce');
        $heading=self::text(get_post_meta($post->ID,self::META_HEADING,true));
        $intro=self::text(get_post_meta($post->ID,self::META_INTRO,true));
        echo '<p>Optional visible heading and intro. Native WordPress title/excerpt remain fallback. Rank Math SEO Title/Description remain independent.</p>';
        echo '<label for="mad4b_page_heading">Visible H1 override</label>';
        echo '<input id="mad4b_page_heading" type="text" class="widefat" name="mad4b_page_heading" value="'.esc_attr($heading).'"/>';
        echo '<label for="mad4b_page_intro">Visible introduction</label>';
        echo '<textarea id="mad4b_page_intro" class="widefat" rows="3" name="mad4b_page_intro">'.esc_textarea($intro).'</textarea>';
        echo '<p>Elementor dynamic tag: MAD4B Page Context · Heading/Intro. Shortcodes: <code>[mad4b_page_context field="heading"]</code> and <code>[mad4b_page_context field="intro"]</code>.</p>';
    }
    public static function save_post(int $id): void {
        if(!isset($_POST['mad4b_page_context_nonce'])||!self::eligible_type(get_post_type($id)?:'')
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_page_context_nonce'])),'mad4b_page_context_'.$id)
            || !current_user_can('edit_post',$id) || wp_is_post_revision($id)
            || (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE))return;
        if(!isset($_POST['mad4b_page_heading'],$_POST['mad4b_page_intro']))return;
        self::persist_overrides('post',$id,
            sanitize_text_field(wp_unslash($_POST['mad4b_page_heading'])),
            wp_kses_post(wp_unslash($_POST['mad4b_page_intro'])));
    }
    private static function persist_overrides(string $type,int $id,string $heading,string $intro): void {
        foreach([self::META_HEADING=>$heading,self::META_INTRO=>$intro] as $key=>$value){
            $old=$type==='post'?get_post_meta($id,$key,true):get_term_meta($id,$key,true);
            if($old===$value)continue;
            if($type==='post'){
                if($value==='')delete_post_meta($id,$key);
                else update_post_meta($id,$key,$value);
            } else {
                if($value==='')delete_term_meta($id,$key);
                else update_term_meta($id,$key,$value);
            }
        }
        $language=(string)apply_filters('wpml_current_language','en');
        if($type==='post')
            update_post_meta($id,'_mad4b_presentation_authoring_lang',$language);
        else update_term_meta($id,'_mad4b_presentation_authoring_lang',$language);
        if($type==='post'){
            clean_post_cache($id);
            do_action('litespeed_purge_post',$id);
            $url=get_permalink($id);
            if($url)self::purge_url($url);
        }else {
            $term=get_term($id);
            if($term && !is_wp_error($term)) {
                $url=get_term_link($term);
                if(!is_wp_error($url))self::purge_url($url);
            }
        }
    }
    public static function term_fields($term): void {
        if(!($term instanceof WP_Term)||!current_user_can('edit_term',$term->term_id))return;
        $h=self::text(get_term_meta($term->term_id,self::META_HEADING,true));
        $i=self::text(get_term_meta($term->term_id,self::META_INTRO,true));
        wp_nonce_field('mad4b_page_context_term_'.$term->term_id,'mad4b_page_context_term_nonce');
        echo '<tr class="form-field"><th><label for="mad4b_term_heading">MAD4B Display H1</label></th><td><input id="mad4b_term_heading" name="mad4b_term_heading" type="text" value="'.esc_attr($h).'"/><p class="description">Optional heading; the native term name remains fallback. Rank Math SEO is separate.</p></td></tr>';
        echo '<tr class="form-field"><th><label for="mad4b_term_intro">MAD4B Display Intro</label></th><td><textarea id="mad4b_term_intro" name="mad4b_term_intro" rows="3">'.esc_textarea($i).'</textarea></td></tr>';
    }
    public static function save_term(int $id): void {
        if(!isset($_POST['mad4b_page_context_term_nonce'])||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_page_context_term_nonce'])),'mad4b_page_context_term_'.$id)
            || !current_user_can('edit_term',$id) ||
            !isset($_POST['mad4b_term_heading'],$_POST['mad4b_term_intro']))return;
        self::persist_overrides('term',$id,
            sanitize_text_field(wp_unslash($_POST['mad4b_term_heading'])),
            wp_kses_post(wp_unslash($_POST['mad4b_term_intro'])));
    }
    public static function menu(): void {
        add_management_page('MAD4B Native SEO Context Modes','MAD4B Page Modes',
            'manage_options','mad4b-page-modes',[self::class,'admin_page']);
    }
    public static function admin_page(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        echo '<div class="wrap"><h1>MAD4B · Native SEO & Presentation Modes</h1>';
        echo '<p>SEO values below are fetched from Rank Math and WordPress, not an independent SEO database. JetEngine Look & Feel provides editable mirrors. Singular posts and terms own their per-record SEO and visible heading overrides.</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=look-and-feel')).'">Edit JetEngine Look &amp; Feel</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=rank-math-options-titles')).'">Rank Math Titles &amp; Meta</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Context</th><th>Native setting</th><th>Value</th></tr></thead><tbody>';
        foreach(self::registry() as $field=>$spec){
            $value=self::native($spec);
            echo '<tr><td>'.esc_html($spec['label']).'</td><td><code>'.esc_html($spec['source']==='post'?'post#'.$spec['post_id'].':'.$spec['key']:$spec['key']).'</code></td><td>'.esc_html($value).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    public static function status(): array {
        $registry=self::registry();$counts=[];
        foreach($registry as $spec)$counts[$spec['section']]=($counts[$spec['section']]??0)+1;
        $public=[];
        foreach(self::types() as $type=>$pt){
            $url=get_post_type_archive_link($type);
            $public[$type]=['archive'=>$url?:null,'has_archive'=>(bool)$pt->has_archive];
        }
        return ['contract'=>'mad4b.native-page-modes.v1','site'=>home_url('/'),
            'contexts'=>count($registry),'sections'=>$counts,'archive_routes'=>$public,
            'blog_page'=>(int)get_option('page_for_posts'),
            'search_pattern'=>self::native(self::registry()['mad4b_mode_search_title']),
            'single_heading_meta'=>self::META_HEADING,
            'term_heading_meta'=>self::META_HEADING,
            'default_seo_source'=>'rank-math-native','no_frontend_fetch'=>true,
            'auto_translate'=>false];
    }
    public static function cli($args): void {
        $mode=$args[0]??'status';
        if($mode==='status')$r=self::status();
        elseif($mode==='registry') {
            $r=[];
            foreach(self::registry() as $key=>$spec)$r[$key]=[
                'source'=>$spec['source'],'native_key'=>$spec['key'],
                'value'=>self::native($spec),'section'=>$spec['section']
            ];
        } else \WP_CLI::error('Use status|registry');
        \WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function boot(): void {
        add_filter('option_look-and-feel',[self::class,'admin_native_options'],90);
        add_action('update_option_look-and-feel',[self::class,'saved'],130,2);
        add_action('add_meta_boxes',[self::class,'meta_box'],70);
        add_action('save_post',[self::class,'save_post'],70);
        add_action('update_option_rank-math-options-titles',[self::class,'native_option_changed'],90,2);
        add_action('updated_post_meta',[self::class,'native_post_meta_changed'],92,3);
        add_action('added_post_meta',[self::class,'native_post_meta_changed'],92,3);
        add_action('updated_term_meta',[self::class,'native_term_meta_changed'],92,3);
        add_action('added_term_meta',[self::class,'native_term_meta_changed'],92,3);
        add_filter('jet-engine/listings/dynamic-field/custom-value',[self::class,'jet_h1_value'],35,3);
        add_shortcode('mad4b_page_context',[self::class,'shortcode']);
        add_action('elementor/dynamic_tags/register',[self::class,'register_tag'],65);
        add_action('admin_menu',[self::class,'menu'],90);
        foreach(array_keys(self::taxes()) as $tax){
            add_action($tax.'_edit_form_fields',[self::class,'term_fields'],90);
            add_action('edited_'.$tax,[self::class,'save_term'],90);
        }
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::add_command(self::CLI,[self::class,'cli']);
    }
}
// Register before JetEngine constructs its options page, not at init priority 85.
add_filter('jet-engine/options-pages/raw-fields',['MAD4B_Native_Page_Modes','jet_fields'],25,2);
add_action('init',['MAD4B_Native_Page_Modes','boot'],85);
