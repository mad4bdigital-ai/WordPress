<?php
/**
 * Plugin Name: MAD4B Taxonomy FAQ — JetEngine Native Repeater Source
 * Description: Context-scoped, WPML-aware FAQ terms for the JetEngine Query Builder.
 */
defined('ABSPATH') || exit;

final class MAD4B_Taxonomy_FAQ_Source {
    const META='mad4b_term_faq_items';
    const MODE='mad4b_term_faq_mode';
    const SOURCE='mad4b_term_faq';
    const MAX=10;
    private static $route_override=null;
    private static $post_override=null;
    private static $locale_override=null;
    private static $last_context=array();

    public static function taxonomies():array {
        return array('destinations','tour-types','tour-styles','season','good-for','duration-options','attractions-types','tour_categories');
    }
    public static function enabled_taxonomies():array {
        $all=self::taxonomies();
        $configured=get_option('mad4b_taxonomy_faq_enabled_taxonomies',null);
        if($configured===null)return $all; // Enabled by default, unless explicitly disabled.
        return array_values(array_intersect($all,array_map('sanitize_key',(array)$configured)));
    }
    public static function with_route(?string $path,callable $fn) {
        $old=self::$route_override;
        self::$route_override=$path;
        try {return $fn();} finally {self::$route_override=$old;}
    }
    public static function with_tour(int $post_id,callable $fn) {
        $post=get_post($post_id);
        if(!$post instanceof WP_Post || $post->post_type!=='tours-and-activities'
           || $post->post_status!=='publish')return $fn();
        $oldPost=self::$post_override;
        $oldLocale=self::$locale_override;
        // Freeze the public WPML language before Elementor switches its
        // internal current object to a reusable template/listing.
        $locale=self::language();
        $details=apply_filters('wpml_post_language_details',null,$post_id);
        if(is_array($details) && in_array((string)($details['language_code']??''),['en','ar','fr','de','es','it'],true))
            $locale=(string)$details['language_code'];
        self::$post_override=$post_id;
        self::$locale_override=$locale;
        $previousWpml=(string)apply_filters('wpml_current_language',null);
        $switch=($previousWpml!=='' && $previousWpml!==$locale && has_action('wpml_switch_language'));
        if($switch)do_action('wpml_switch_language',$locale);
        try{return $fn();}
        finally{
            if($switch)do_action('wpml_switch_language',$previousWpml);
            self::$post_override=$oldPost;self::$locale_override=$oldLocale;
        }
    }

    public static function register_editor_source(array $data):array {
        $data['sources'][]=array('value'=>self::SOURCE,'label'=>'MAD4B — Term FAQ (WPML / DFSB context)');
        return $data;
    }
    public static function filter_items($items,$args,$query) {
        return self::items();
    }
    public static function language():string {
        // REST/AJAX executions normally load WordPress in the default locale.
        // The previously validated route is the authoritative language for
        // fragment rendering; otherwise use the actual WPML page language.
        if(self::$route_override!==null){
            $path=(string)wp_parse_url(self::$route_override,PHP_URL_PATH);
            $parts=explode('/',trim($path,'/'));
            return in_array($parts[0]??'',array('ar','fr','de','es','it'),true)?$parts[0]:'en';
        }
        if(self::$locale_override!==null)return self::$locale_override;
        $lang=(string)apply_filters('wpml_current_language',null);
        return in_array($lang,array('en','ar','fr','de','es','it'),true)?$lang:'en';
    }
    private static function tour_terms(WP_Post $post):array {
        $locale=self::language();
        $selected=[];
        $seen=[];
        $limit=16; // Bound rendering work, never run an unbounded term query.
        foreach(self::enabled_taxonomies() as $taxonomy) {
            if(!taxonomy_exists($taxonomy))continue;
            $terms=get_the_terms($post->ID,$taxonomy);
            if(is_wp_error($terms) || !is_array($terms) || !$terms)continue;
            $ranked=[];
            foreach($terms as $term) {
                if(!$term instanceof WP_Term)continue;
                $id=(int)apply_filters('wpml_object_id',$term->term_id,$taxonomy,false,$locale);
                if($id<=0) {
                    if($locale!=='en')continue;
                    $id=$term->term_id;
                }
                $translated=get_term($id,$taxonomy);
                if(!$translated instanceof WP_Term || $translated->taxonomy!==$taxonomy)continue;
                $tag=$taxonomy.':'.$translated->term_id;
                if(isset($seen[$tag]))continue;
                $seen[$tag]=true;
                $depth=count(get_ancestors($translated->term_id,$taxonomy,'taxonomy'));
                // Do not accidentally exclude the only reviewed FAQ when many
                // terms share a taxonomy (common for tour-styles / good-for).
                $parentsSeen=[];
                $approved= !empty(self::from_term($translated,$parentsSeen));
                $ranked[]=array('term'=>$translated,'depth'=>$depth,'approved'=>$approved);
            }
            // Approved sources first, then specific terms (Cairo before Egypt).
            // The two-term/per-tax cap remains, but no approved term loses to
            // an earlier alphabetical/unreviewed term.
            usort($ranked,static function($a,$b):int {
                return ((int)$b['approved']<=>(int)$a['approved'])
                    ?: (($b['depth']<=>$a['depth']) ?: ($a['term']->term_id<=>$b['term']->term_id));
            });
            $perTax=$taxonomy==='destinations'?5:2;
            foreach(array_slice($ranked,0,$perTax) as $record) {
                $selected[]=$record['term'];
                if(count($selected)>=$limit)break 2;
            }
        }
        return $selected;
    }

    private static function verified_terms():array {
        if(self::$post_override!==null){
            $post=get_post(self::$post_override);
            return ($post instanceof WP_Post && $post->post_status==='publish'
                && $post->post_type==='tours-and-activities')?self::tour_terms($post):array();
        }
        // A real WordPress term archive takes precedence over URL filter state.
        if (null===self::$route_override) {
            $current=get_queried_object();
            if ($current instanceof WP_Term && in_array($current->taxonomy,self::enabled_taxonomies(),true)) {
                return array($current);
            }
            // Tour single pages already have their own trip-specific accordion.
            // This additional shared FAQ section resolves only relevant,
            // approved taxonomy FAQs from the same published tour's terms.
            if (is_singular('tours-and-activities') && $current instanceof WP_Post
                && $current->post_type==='tours-and-activities'
                && $current->post_status==='publish') {
                return self::tour_terms($current);
            }
        }
        $path=(string)(self::$route_override??($_SERVER['REQUEST_URI']??'/'));
        $urlPath=(string)wp_parse_url($path,PHP_URL_PATH);
        // Query Builder may be invoked by Elementor editor; no FAQ leakage.
        if (!preg_match('~^/(?:en/|ar/|fr/|de/|es/|it/)?tours-and-activities/(?:jsf/.*)?$~D',$urlPath)) {
            return array();
        }
        if (!str_contains($urlPath,'/jsf/')) return array();
        $class='DynamicFilterSEOBridge\\JetSmartFilters\\FilterUrlParser';
        if (!class_exists($class)) return array();
        $parser=new $class(self::taxonomies());
        $result=$parser->parse($path);
        if (empty($result['active']) || !empty($result['malformed']) || !empty($result['unknown_filters'])
            || (string)($result['provider']??'')!=='jet-engine'
            || (string)($result['query_id']??'')!=='tours_query_archive') return array();
        $selected=array();
        $locale=self::language();
        foreach (self::enabled_taxonomies() as $tax) {
            $slug=(string)($result['filters'][$tax]??'');
            if (''===$slug || !taxonomy_exists($tax)) continue;
            $term=get_term_by('slug',$slug,$tax);
            if (!$term instanceof WP_Term) continue;
            $translated=apply_filters('wpml_object_id',$term->term_id,$tax,false,$locale);
            if (is_numeric($translated) && (int)$translated>0) {
                $translatedTerm=get_term((int)$translated,$tax);
                if ($translatedTerm instanceof WP_Term) $term=$translatedTerm;
            } elseif ($locale!=='en') {
                // Never render untranslated English FAQs on another market page.
                continue;
            }
            $selected[]=$term;
        }
        return array_slice($selected,0,4);
    }
    private static function row_items($raw,WP_Term $term):array {
        if (!is_array($raw)) return array();
        $rows=array();
        foreach (array_slice($raw,0,30) as $index=>$row) {
            if (!is_array($row)) continue;
            $q=trim(wp_strip_all_tags((string)($row['faq_question']??'')));
            $a=trim(wp_kses_post((string)($row['faq_answer']??'')));
            if ($q===''||$a===''||(string)($row['faq_review']??'')!=='approved'
                ||in_array((string)($row['faq_enabled']??'1'),array('false','0','off','no'),true)) continue;
            $rows[]=array(
                'faq_question'=>mb_substr($q,0,300),
                'faq_answer'=>$a,
                'faq_uid'=>sanitize_key((string)($row['faq_uid']??'')) ?: 'term-'.$term->term_id.'-'.$index,
                'faq_priority'=>min(999,max(0,(int)($row['faq_priority']??10))),
                'faq_term_id'=>$term->term_id,
                'faq_taxonomy'=>$term->taxonomy,
                'faq_term_name'=>$term->name,
            );
        }
        usort($rows,static function($a,$b){return $a['faq_priority']<=>$b['faq_priority'];});
        return $rows;
    }
    private static function from_term(WP_Term $term,array &$visited,int $depth=0):array {
        if ($depth>4 || isset($visited[$term->taxonomy.':'.$term->term_id])) return array();
        $visited[$term->taxonomy.':'.$term->term_id]=true;
        $mine=self::row_items(get_term_meta($term->term_id,self::META,true),$term);
        $mode=(string)get_term_meta($term->term_id,self::MODE,true);
        if (!in_array($mode,array('inherit','override','append'),true)) $mode='inherit';
        $parent=array();
        if ($term->parent>0 && ($mode==='append'||($mode==='inherit'&&!$mine))) {
            $parentTerm=get_term($term->parent,$term->taxonomy);
            if ($parentTerm instanceof WP_Term) $parent=self::from_term($parentTerm,$visited,$depth+1);
        }
        return $mode==='append'?array_merge($mine,$parent):($mine?:($mode==='override'?array():$parent));
    }
    public static function items():array {
        $terms=self::verified_terms();
        $out=array();$seen=array();$source=array();
        foreach ($terms as $term) {
            $visited=array();
            foreach (self::from_term($term,$visited) as $row) {
                $dedup=mb_strtolower(preg_replace('/\s+/u',' ',trim($row['faq_question'])),'UTF-8');
                if (isset($seen[$dedup])) continue;
                $seen[$dedup]=true;$out[]=$row;$source[]=$term->taxonomy.':'.$term->term_id;
                if (count($out)>=self::MAX) break 2;
            }
        }
        // Unfiltered tours archive: editorially curated copy of original Home FAQ,
        // stored separately by language. Never use global FAQ as term-specific truth.
        if (!$terms && !str_contains((string)(self::$route_override??($_SERVER['REQUEST_URI']??'')),'/jsf/')) {
            $path=(string)wp_parse_url((string)(self::$route_override??($_SERVER['REQUEST_URI']??'')),PHP_URL_PATH);
            if (preg_match('~^/(?:en/|ar/|fr/|de/|es/|it/)?tours-and-activities/$~D',$path)) {
                $all=(array)get_option('mad4b_taxonomy_faq_global_items_v1',array());
                foreach (array_slice((array)($all[self::language()]??array()),0,self::MAX) as $row) {
                    if (!is_array($row)) continue;
                    $q=trim(wp_strip_all_tags((string)($row['faq_question']??'')));
                    $a=trim(wp_kses_post((string)($row['faq_answer']??'')));
                    if($q===''||$a==='')continue;
                    $out[]=array('faq_question'=>$q,'faq_answer'=>$a,
                        'faq_uid'=>sanitize_key((string)($row['faq_uid']??'')),
                        'faq_priority'=>(int)($row['faq_priority']??10),
                        'faq_term_id'=>0,'faq_taxonomy'=>'global');
                }
            }
        }
        self::$last_context=array('locale'=>self::language(),'term_ids'=>array_map(static fn($t)=>$t->term_id,$terms),'source'=>$source,'count'=>count($out));
        return array_slice($out,0,self::MAX);
    }
    public static function context():array {return self::$last_context;}
}
add_filter('jet-engine/query-builder/types/repeater-query/data',array('MAD4B_Taxonomy_FAQ_Source','register_editor_source'));
add_filter('jet-engine/query-builder/types/repeater-query/items/'.MAD4B_Taxonomy_FAQ_Source::SOURCE,
    array('MAD4B_Taxonomy_FAQ_Source','filter_items'),10,3);

/** Shared native Elementor FAQs: hide empty copied sections and localize heading. */
add_shortcode('mad4b_taxonomy_faq_heading', static function():string {
    $headings=array(
        'en'=>'Frequently Asked Questions',
        'ar'=>'الأسئلة الشائعة',
        'fr'=>'Questions fréquentes',
        'de'=>'Häufig gestellte Fragen',
        'es'=>'Preguntas frecuentes',
        'it'=>'Domande frequenti',
    );
    $lang=MAD4B_Taxonomy_FAQ_Source::language();
    return '<h2 class="elementor-heading-title elementor-size-default">'
        .esc_html($headings[$lang]??$headings['en']).'</h2>';
});
add_filter('elementor/frontend/container/should_render',static function($render,$element):bool {
    if(!$render) return false;
    if(!method_exists($element,'get_settings'))return $render;
    $css=(string)$element->get_settings('css_classes');
    $classes=' '.trim($css).' ';
    if(!str_contains($classes,' mad4b-term-faq-section ') &&
       !str_contains($classes,' mad4b-tour-single-taxonomy-faq '))return $render;
    return count(MAD4B_Taxonomy_FAQ_Source::items())>0;
},12,2);
