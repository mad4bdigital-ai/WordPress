<?php
/**
 * Plugin Name: MAD4B Public Critical CSS Coverage & Dependency Events
 * Version: 1.1.1
 * Description: Source-scoped dirty events and CLI-only public route discovery; no unverified CSS transformations.
 */
defined('ABSPATH') || exit;

final class MAD4B_Public_Critical_CSS_Coverage_V1 {
    private const DIRTY = 'mad4b_ccss_public_dirty_v1';
    private const HOME_DIRTY = 'mad4b_critical_css_home_dirty_v1';
    private const TYPES = ['page','post','tours-and-activities','properties'];
    private const TAXONOMIES = ['category','destinations','tour-types','property-types','tour-categories'];
    private const HOSTS = ['tours.dev.mad4b.com','wayfatours.com'];

    public static function authorized(): bool {
        $host = strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST));
        return in_array($host,self::HOSTS,true);
    }
    public static function supported_type(string $type): bool {
        return in_array($type,self::TYPES,true);
    }
    private static function page_allowed(int $id,string $lang): bool {
        if (!$id) return true;
        // WordPress's assigned Posts Page renders is_home(), not a page
        // template. The original Critical CSS runtime excludes is_home()
        // because the listing/market graph can vary independently. Never
        // queue its WPML translations as certifiable singular page routes:
        // they cannot emit the trusted market headers the publisher needs.
        $postsPage=(int)get_option('page_for_posts',0);
        if ($postsPage>0 && ($id===$postsPage
            || (int)apply_filters('wpml_object_id',$postsPage,'page',false,$lang)===$id))
            return false;
        if (!function_exists('wc_get_page_id')) return true;
        foreach (['cart','checkout','myaccount','terms'] as $slug) {
            if ($slug==='terms')continue;
            $base=(int)wc_get_page_id($slug);
            if ($base>0 && ($base===$id ||
                (int)apply_filters('wpml_object_id',$base,'page',false,$lang)===$id))
                return false;
        }
        return true;
    }
    public static function allowed_path(string $path): bool {
        if ($path==='' || $path[0]!=='/' || strlen($path)>350
            || !preg_match('~^/[a-zA-Z0-9/_\-%\.]*$~D',$path)
            || str_contains($path,'//') || str_contains($path,'..')) return false;
        $parts=array_values(array_filter(explode('/',strtolower(rawurldecode($path)))));
        if (count($parts)>12) return false;
        $sensitive=['wp-admin','wp-login.php','wp-json','feed','cart',
            'checkout','my-account','myaccount','order-pay','order-received',
            'payment-methods','invoice','billing','booking','thank-you',
            'lost-password','account','password-reset','add-payment-method',
            'carrito','carrello','panier','warenkorb','kasse','paiement',
            'pagamento','caisse','mi-cuenta','mon-compte','mio-account',
            'mein-konto','finalizar-compra'];
        foreach ($parts as $part) {
            if (in_array($part,$sensitive,true)) return false;
        }
        return true;
    }
    private static function clean_url($url): ?string {
        if (!is_string($url) || !str_starts_with($url,'https://')) return null;
        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
        $home=strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST));
        if ($home!==$host || (string)wp_parse_url($url,PHP_URL_QUERY)!==''
            || (string)wp_parse_url($url,PHP_URL_FRAGMENT)!=='') return null;
        $path=(string)wp_parse_url($url,PHP_URL_PATH);
        if ($path==='') $path='/';
        return self::allowed_path($path) ? $path : null;
    }
    private static function mark(string $scope,int $id=0,bool $global=false): void {
        if (!self::authorized()) return;
        $dirty=get_option(self::DIRTY,[]);
        if (!is_array($dirty)) $dirty=[];
        $generation=max(0,(int)($dirty['generation']??0))+1;
        $ids=(array)($dirty['ids']??[]);
        $key=$scope.':'.$id;
        $ids[$key]=['generation'=>$generation,'at'=>time()];
        if (count($ids)>100) $ids=array_slice($ids,-100,null,true);
        $dirty=['generation'=>$generation,'at'=>time(),
            'global'=>($global || !empty($dirty['global'])),
            'global_generation'=>$global?$generation:(int)($dirty['global_generation']??0),
            'types'=>array_values(array_unique(array_merge((array)($dirty['types']??[]),[$scope]))),
            'ids'=>$ids];
        update_option(self::DIRTY,$dirty,false);
        // Constant-time per-object invalidation (independent of the size
        // of the bounded event ledger); frontend certificates check it
        // without enumerating all tours or translated pages.
        if (str_starts_with($scope,'post:') && $id>0) {
            update_post_meta($id,'_mad4b_ccss_route_generation_v1',$generation);
        }
        if ($global) {
            update_option('mad4b_ccss_global_generation_v1',$generation,false);
        }
        if (str_starts_with($scope,'taxonomy:') || $scope==='pricing'
            || in_array($scope,['post:tours-and-activities','post:properties','post:post',
                'activation','navigation'],true)) {
            update_option('mad4b_ccss_listing_generation_v1',$generation,false);
        }
        if ($global || $scope==='home') {
            // Only true first-paint/page-template changes invalidate home QA;
            // normal tour edits are delegated to the dependency-aware asset
            // job queue already used by the existing home CSS controller.
            update_option(self::HOME_DIRTY,max(0,(int)get_option(self::HOME_DIRTY,0))+1,false);
        }
    }
    public static function post_updated(int $id,$after,$before): void {
        if (!self::authorized() || !is_object($after) || !is_object($before)) return;
        $type=(string)($after->post_type??'');
        if (!self::supported_type($type)
            && $type!=='elementor_library' && $type!=='tour-rates'
            && $type!=='nav_menu_item') return;
        $fields=['post_status','post_type','post_title','post_content','post_excerpt','post_name','post_parent','menu_order','post_password'];
        foreach ($fields as $field) {
            if (($after->$field??null)!==($before->$field??null)) {
                $scope=$type==='elementor_library'?'template':
                    ($type==='nav_menu_item'?'navigation':
                     ($type==='tour-rates'?'pricing':'post:'.$type));
                self::mark($scope,$id,in_array($type,['elementor_library','nav_menu_item'],true));
                if ($type==='page' && (int)get_option('page_on_front')===$id) self::mark('home',$id,true);
                return;
            }
        }
    }
    public static function post_inserted(int $id,$post,bool $update,$previous=null): void {
        if (!self::authorized() || $update || !is_object($post)
            || (string)($post->post_status??'')!=='publish') return;
        $type=(string)($post->post_type??'');
        if ($type==='nav_menu_item') {
            self::mark('navigation',$id,true);
        }elseif ($type==='elementor_library') {
            self::mark('template',$id,true);
        }elseif (self::supported_type($type)) {
            self::mark('post:'.$type,$id);
            if ($type==='page' && $id===(int)get_option('page_on_front'))
                self::mark('home',$id,true);
        }
    }
    public static function post_deleted(int $id,$post=null): void {
        if (!self::authorized() || !is_object($post)) return;
        $type=(string)($post->post_type??'');
        if ($type==='nav_menu_item') {
            self::mark('navigation',$id,true);
        }elseif ($type==='elementor_library') {
            self::mark('template',$id,true);
        }elseif (self::supported_type($type)) {
            self::mark('post:'.$type,$id);
            if ($type==='page' && $id===(int)get_option('page_on_front'))
                self::mark('home',$id,true);
        }
    }
    public static function terms_set(int $id,$terms,$term_ids,string $taxonomy,
        bool $append,$previous_term_ids): void {
        if (!self::authorized())return;
        $current=array_map('intval',(array)$term_ids);
        $prior=array_map('intval',(array)$previous_term_ids);
        sort($current);sort($prior);
        if ($current===$prior)return;
        if ($taxonomy==='nav_menu') {
            self::mark('navigation',$id,true);
            return;
        }
        if (!in_array($taxonomy,self::TAXONOMIES,true))return;
        $post=get_post($id);
        if (!is_object($post) || !self::supported_type((string)($post->post_type??'')))return;
        self::mark('post:'.$post->post_type,$id);
        if ($post->post_type==='page' && $id===(int)get_option('page_on_front'))
            self::mark('home',$id,true);
    }
    public static function changed_meta($meta_id,int $post_id,string $key,$value): void {
        if (!self::authorized()) return;
        $post=get_post($post_id);
        if (!is_object($post)) return;
        $type=(string)($post->post_type??'');
        if ($type==='nav_menu_item') {
            // Native menu links/order/activation are stored in post meta,
            // not the post_content itself. The header is shared by every
            // public page and its first-screen CSS evidence depends on it.
            if (str_starts_with($key,'_menu_item_'))self::mark('navigation',$post_id,true);
            return;
        }
        if (!self::supported_type($type)
            && $type!=='elementor_library' && $type!=='tour-rates') return;
        $isElementor=in_array($key,['_elementor_data','_elementor_page_settings',
            '_elementor_template_type','_wp_page_template','_elementor_conditions'],true);
        $isVisual=$key==='_thumbnail_id'
            || preg_match('/(?:image|gallery|photo|media|thumb|rate|price|season|tour|trip|itinerary|duration|availability|occupancy|property|destination|highlight|schedule|booking|hero|description|summary|amenities|program|rate_period)/i',$key);
        if (!$isElementor && !$isVisual) return;
        $scope=$type==='elementor_library'?'template':
            ($type==='tour-rates'?'pricing':'post:'.$type);
        self::mark($scope,$post_id,$type==='elementor_library');
        if ($type==='page' && (int)get_option('page_on_front')===$post_id) self::mark('home',$post_id,true);
    }
    public static function term_updated(int $id,int $tt_id=0,string $taxonomy=''): void {
        if ($taxonomy==='nav_menu') {
            self::mark('navigation',$id,true);
            return;
        }
        if (!in_array($taxonomy,self::TAXONOMIES,true)) return;
        // Destination and service taxonomy labels/visibility are part of
        // the dynamic Elementor header. They can change layout/LCP even
        // without touching a stylesheet or homepage Elementor post.
        self::mark('taxonomy:'.$taxonomy,$id,true);
    }
    public static function term_meta_updated($meta_id,int $term_id,string $key,$value): void {
        if (!self::authorized()
            || !preg_match('/(?:image|gallery|photo|media|thumb|hero|description|summary|destination|city|cover|sort|visibility|amenities|price|rate)/i',$key))
            return;
        $term=get_term($term_id);
        if (!is_object($term) || is_wp_error($term)
            || !in_array((string)($term->taxonomy??''),self::TAXONOMIES,true))return;
        self::mark('taxonomy:'.$term->taxonomy,$term_id,true);
    }
    public static function menu_updated(int $menu_id=0): void {
        self::mark('navigation',max(0,$menu_id),true);
    }
    public static function option_added(string $key,$value): void {
        self::option_updated($key,null,$value);
    }
    public static function option_deleted(string $key): void {
        self::option_updated($key,true,null);
    }
    public static function option_updated(string $key,$old,$new): void {
        if (!self::authorized() || $old===$new) return;
        if ($key==='icl_sitepress_settings') {
            $a=is_array($old)?$old:[];
            $b=is_array($new)?$new:[];
            if (($a['active_languages']??[])===($b['active_languages']??[])
                && ($a['default_language']??'')===($b['default_language']??'')) return;
            self::mark('wpml',0,true);return;
        }
        // JetEngine service activation, country/destination activation and
        // native product navigation can regenerate the *header topology*
        // without changing CSS bytes. Invalidate the home first-paint
        // certificate, all public header-dependent certificates and the
        // listing generation exactly when the setting actually changes.
        if (in_array($key,['service-activation','destination-activation',
                'product_navigation_sources','look-and-feel'],true)) {
            self::mark('activation',0,true);
            return;
        }
        if ($key==='elementor_active_kit' || $key==='stylesheet'
            || str_starts_with($key,'theme_mods_')
            || in_array($key,['litespeed.conf.optm-css_comb','litespeed.conf.optm-css_min',
                'litespeed.conf.optm-css_comb_ext_inl','mad4b_perf_critical_css_v1'],true)) {
            self::mark('global_style',0,true);
        }
    }
    private static function lang_for(string $kind,int $id,string $default): string {
        $lang=apply_filters('wpml_element_language_code',null,
            ['element_id'=>$id,'element_type'=>$kind]);
        return is_string($lang) && preg_match('/^[a-z]{2}$/D',$lang) ? $lang : $default;
    }
    public static function catalog(): array {
        if (!self::authorized() || !defined('WP_CLI') || !WP_CLI) return [];
        $settings=get_option('icl_sitepress_settings',[]);
        $langs=(array)($settings['active_languages']??[]);
        $default=(string)($settings['default_language']??'');
        if (!in_array($default,$langs,true) || !preg_match('/^[a-z]{2}$/D',$default)
            || count($langs)>20 || !$langs) return [];
        $host=strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST));
        $rows=[];$seen=[];
        $push=static function($url,$type,$id,$language)use(&$rows,&$seen) {
            $path=self::clean_url($url);
            if ($path===null || !in_array($language,(array)(get_option('icl_sitepress_settings',[])['active_languages']??[]),true)) return;
            $key=$language.'|'.$path;
            if (isset($seen[$key])) return;
            $seen[$key]=true;
            $revision=in_array($type,self::TYPES,true)
                ? (int)get_post_meta((int)$id,'_mad4b_ccss_route_generation_v1',true) : 0;
            $rows[]=['path'=>$path,'type'=>$type,'id'=>(int)$id,'language'=>$language,
                'revision'=>$revision,
                'route_key'=>hash('sha256',$key.'|'.$type.'|'.$id)];
        };
        // Build WPML ownership once, then switch actual SitePress language
        // for permalink calculation. Calling get_permalink() while the
        // request language is EN silently strips /es/, /de/, etc, from
        // translated pages and would create unsafe duplicate certificates.
        $owned=[];
        foreach (self::TYPES as $type) {
            $ids=get_posts(['post_type'=>$type,'post_status'=>'publish','numberposts'=>-1,
                'fields'=>'ids','suppress_filters'=>true,'orderby'=>'ID','order'=>'ASC']);
            foreach ((array)$ids as $id) {
                $id=(int)$id;
                $post=get_post($id);
                if (!is_object($post) || (string)$post->post_status!=='publish'
                    || post_password_required($post)) continue;
                $lang=self::lang_for('post_'.$type,$id,$default);
                if ($type==='page' && !self::page_allowed($id,$lang))continue;
                $owned[$lang][]=['type'=>$type,'id'=>$id,'kind'=>'post'];
            }
        }
        foreach (self::TAXONOMIES as $taxonomy) {
            if (!taxonomy_exists($taxonomy))continue;
            $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>true,'number'=>0,
                'fields'=>'all','suppress_filter'=>true]);
            if (is_wp_error($terms))continue;
            foreach($terms as $term){
                $lang=self::lang_for('tax_'.$taxonomy,(int)$term->term_id,$default);
                $owned[$lang][]=['type'=>$taxonomy,'id'=>(int)$term->term_id,'kind'=>'term'];
            }
        }
        try {
            foreach ($langs as $lang) {
                do_action('wpml_switch_language',$lang);
                foreach (($owned[$lang]??[]) as $row) {
                    $url=$row['kind']==='term'
                        ? get_term_link((int)$row['id'],$row['type'])
                        : get_permalink((int)$row['id']);
                    if (is_wp_error($url)) continue;
                    $push($url,$row['type'],$row['id'],$lang);
                }
                foreach(['tours-and-activities','properties'] as $type){
                    if (!post_type_exists($type))continue;
                    $url=get_post_type_archive_link($type);
                    if (!is_string($url) || !$url)continue;
                    $path=(string)wp_parse_url($url,PHP_URL_PATH);
                    // If WPML did not publish a translated archive, its
                    // default-language URL must not be certified as another locale.
                    if ($lang!==$default && !str_starts_with($path,'/'.$lang.'/'))continue;
                    $push($url,'archive:'.$type,0,$lang);
                }
            }
        } finally {
            do_action('wpml_switch_language',$default);
        }
        usort($rows,static fn($a,$b)=>strcmp($a['language'].'|'.$a['path'],$b['language'].'|'.$b['path']));
        return ['contract'=>'mad4b.public-critical-css.routes.v1','host'=>$host,
            'wpml_languages'=>$langs,'default_language'=>$default,
            'generation'=>(int)(get_option(self::DIRTY,[])['generation']??0),
            'global_generation'=>(int)get_option('mad4b_ccss_global_generation_v1',0),
            'listing_generation'=>(int)get_option('mad4b_ccss_listing_generation_v1',0),
            'routes'=>$rows];
    }
    public static function boot(): void {
        add_action('post_updated',[__CLASS__,'post_updated'],20,3);
        add_action('wp_after_insert_post',[__CLASS__,'post_inserted'],20,4);
        add_action('deleted_post',[__CLASS__,'post_deleted'],20,2);
        add_action('set_object_terms',[__CLASS__,'terms_set'],20,6);
        add_action('updated_post_meta',[__CLASS__,'changed_meta'],20,4);
        add_action('added_post_meta',[__CLASS__,'changed_meta'],20,4);
        add_action('deleted_post_meta',[__CLASS__,'changed_meta'],20,4);
        add_action('edited_term',[__CLASS__,'term_updated'],20,3);
        add_action('created_term',[__CLASS__,'term_updated'],20,3);
        add_action('delete_term',[__CLASS__,'term_updated'],20,3);
        add_action('updated_term_meta',[__CLASS__,'term_meta_updated'],20,4);
        add_action('added_term_meta',[__CLASS__,'term_meta_updated'],20,4);
        add_action('deleted_term_meta',[__CLASS__,'term_meta_updated'],20,4);
        add_action('updated_option',[__CLASS__,'option_updated'],20,3);
        add_action('added_option',[__CLASS__,'option_added'],20,2);
        add_action('deleted_option',[__CLASS__,'option_deleted'],20,1);
        add_action('wp_update_nav_menu',[__CLASS__,'menu_updated'],20,1);
    }
}
add_action('init',['MAD4B_Public_Critical_CSS_Coverage_V1','boot'],3);
