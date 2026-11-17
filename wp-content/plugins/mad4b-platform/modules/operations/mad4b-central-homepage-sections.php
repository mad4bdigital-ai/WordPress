<?php
/**
 * Plugin Name: MAD4B Central Homepage Sections
 * Description: Connects existing Elementor home subtemplates to the JetEngine Look and Feel options page.
 * Version: 1.1.0
 */
if (!defined('ABSPATH')) exit;
// Run on enrolled company sites regardless of Staging or Production.
// Publishing authority stays with Service/Country policy; this only gates presentation.

/** The actual WPML company language set, including languages without a Home
 * translation yet. In wp-admin the frontend WPML language filter can be null,
 * so the site's canonical WPML settings are the authority, not a hardcoded
 * list of languages from the central source.
 */
function mad4b_home_sections_active_languages():array {
    $settings=get_option('icl_sitepress_settings',[]);
    $configured=is_array($settings)?($settings['active_languages']??[]):[];
    $codes=[];
    if(is_array($configured))foreach($configured as $raw){
        if(!is_string($raw))continue;
        $code=strtolower(trim($raw));
        if(preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$code))$codes[$code]=true;
    }
    if(!$codes){
        $wpml=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        if(is_array($wpml))foreach($wpml as $key=>$item){
            $code=strtolower((string)(is_array($item)?($item['code']??$key):$key));
            if(preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$code))$codes[$code]=true;
        }
    }
    if(!$codes){
        $default=(string)apply_filters('wpml_default_language',null);
        if(!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$default))
            $default=strtolower(substr(str_replace('_','-',get_locale()),0,2));
        $codes[$default]=true;
    }
    return array_keys($codes);
}
function mad4b_home_sections_options() {
    static $options = null;
    if ($options === null) {
        $value = get_option('look-and-feel', []);
        $options = is_array($value) ? $value : [];
    }
    return $options;
}
function mad4b_home_sections_key($widget) {
    if (!is_object($widget) || !method_exists($widget, 'get_name')) return '';
    $name = $widget->get_name();
    $settings = method_exists($widget, 'get_settings_for_display') ? $widget->get_settings_for_display() : [];
    if (!is_array($settings)) return '';
    if ($name === 'template') {
        $ids = [
            68807=>'home_section_seo_intro',
            68808=>'home_section_faqs',
            25917=>'home_section_tours',
            54880=>'home_section_daytrips',
            43662=>'home_section_nile',
            28340=>'home_section_combined',
            25921=>'home_section_branding',
            25927=>'home_section_attractions',
            25928=>'home_section_locations',
            27219=>'home_section_reviews',
            43704=>'home_section_blog',
            14557=>'home_section_newsletter'
        ];
        // Resolve Elementor section identity across active WPML translations.
        // Per-language templates are not interchangeable with their source IDs.
        static $translated_ids_by_language=[];
        $language = (string) apply_filters('wpml_current_language', null);
        if (!isset($translated_ids_by_language[$language])) {
            $resolved=$ids;
            if ($language !== '') {
                foreach ($ids as $source_id => $section_key) {
                    $translated_id = (int) apply_filters('wpml_object_id', $source_id, 'elementor_library', false, $language);
                    if ($translated_id > 0) $resolved[$translated_id] = $section_key;
                }
            }
            $translated_ids_by_language[$language]=$resolved;
        }
        $translated_ids=$translated_ids_by_language[$language];
        return $translated_ids[(int)($settings['template_id'] ?? 0)] ?? '';
    }
    if ($name === 'shortcode') {
        $text = (string)($settings['shortcode'] ?? '');
        if (preg_match('/\[elementor-template\s+id=["\x27]?(\d+)/', $text, $matches)) {
            $source_shortcodes=[41727=>'home_section_hero',25909=>'home_section_cities'];
            $shortcode_id=(int)$matches[1];
            if (isset($source_shortcodes[$shortcode_id]))return $source_shortcodes[$shortcode_id];
            // Translate native Elementor shortcode IDs in each WPML language.
            $language=(string)apply_filters('wpml_current_language',null);
            if($language!=='')foreach($source_shortcodes as $source_id=>$section){
                if((int)apply_filters('wpml_object_id',$source_id,'elementor_library',false,$language)===$shortcode_id)
                    return $section;
            }
        }
    }
    return '';
}
function mad4b_home_sections_page() {
    if (is_admin()) return false;
    $source_home=(int)get_option('page_on_front');
    if($source_home<1 || get_post_status($source_home)!=='publish')return false;
    $page_id=(int)get_queried_object_id();
    if($page_id===$source_home)return true;
    $language=(string)apply_filters('wpml_current_language',null);
    if($language==='')return false;
    $translated_id=(int)apply_filters('wpml_object_id',$source_home,'page',false,$language);
    return $translated_id>0 && $page_id===$translated_id;
}
/**
 * Look and Feel is an opt-in presentation switch, never an entitlement.
 * Share the actual company/channel decision with MU Presentation.
 */
function mad4b_home_sections_policy_allows(string $key): bool {
    $surfaces=[
        'home_section_tours'=>['archive','offer'],
        'home_section_daytrips'=>['archive','offer'],
        'home_section_combined'=>['archive','offer'],
        'home_section_nile'=>['navigation','discover'],
    ];
    if (!isset($surfaces[$key])) return true; // editorial site sections
    if (!class_exists('Presentation_Policy_Context_v1')) return false;
    [$surface,$action]=$surfaces[$key];
    $decision=Presentation_Policy_Context_v1::decide('tours',$surface,$action);
    return ($decision['allowed']??false) === true;
}
/** Resolve native source results, never infer product readiness from a heading. */
function mad4b_home_sections_has_content(string $key): bool {
    static $cache=[];
    $language=(string)apply_filters('wpml_current_language',null);
    $memo_key=$key.'|'.$language;
    if (array_key_exists($memo_key,$cache)) return $cache[$memo_key];
    if (!mad4b_home_sections_policy_allows($key)) return $cache[$memo_key]=false;
    if ($key === 'home_section_combined') {
        if (!function_exists('shared_presentation_published_facet_terms_v1'))
            return $cache[$memo_key]=false;
        $term=get_term_by('slug','combined-tours','tour-types');
        if (!$term instanceof WP_Term) return $cache[$memo_key]=false;
        $ids=shared_presentation_published_facet_terms_v1('tours-and-activities','tour-types');
        return $cache[$memo_key]=in_array((int)$term->term_id,array_map('intval',$ids),true);
    }
    if ($key === 'home_section_nile') {
        // Existing taxonomy terms alone are not bookable or publishable content.
        // Match the exact three Nile card facets against currently published
        // and presentation-eligible tours in the CURRENT WPML language.
        $language = (string) apply_filters('wpml_current_language', null);
        $term_ids = [];
        foreach ([1865,1866,2287] as $source_term_id) {
            $translated_id = $language === ''
                ? $source_term_id
                : (int) apply_filters('wpml_object_id', $source_term_id, 'tour-types', false, $language);
            if ($translated_id > 0 && get_term($translated_id, 'tour-types') instanceof WP_Term)
                $term_ids[] = $translated_id;
        }
        if (!$term_ids) return $cache[$memo_key]=false;
        $args = [
            'post_type'=>'tours-and-activities',
            'post_status'=>'publish',
            'posts_per_page'=>1,
            'fields'=>'ids',
            'no_found_rows'=>true,
            'suppress_filters'=>false,
            'ignore_sticky_posts'=>true,
            'tax_query'=>[[
                'taxonomy'=>'tour-types',
                'field'=>'term_id',
                'terms'=>array_values(array_unique($term_ids)),
                'operator'=>'IN',
                'include_children'=>false,
            ]],
        ];
        if (function_exists('shared_presentation_public_tour_query_args_v1'))
            $args = shared_presentation_public_tour_query_args_v1($args);
        if (!is_array($args)) return $cache[$memo_key]=false;
        $query = new WP_Query($args);
        return $cache[$memo_key]=!empty($query->posts);
    }
    return true;
}

add_filter('elementor/frontend/widget/should_render', function($render,$widget) {
    if (!$render || !mad4b_home_sections_page()) return $render;
    $key=mad4b_home_sections_key($widget);
    if (!$key) return $render;
    $options=mad4b_home_sections_options();
    if (($options[$key] ?? 'on') === 'off') return false;
    if (!mad4b_home_sections_policy_allows($key)) return false;
    $emptyKey = ['home_section_nile'=>'home_empty_nile','home_section_combined'=>'home_empty_combined'][$key] ?? '';
    if ($emptyKey && ($options[$emptyKey] ?? 'hide') === 'hide' && !mad4b_home_sections_has_content($key)) return false;
    return $render;
}, 20, 2);
add_filter('elementor/widget/render_content', function($content,$widget) {
    if (!mad4b_home_sections_page()) return $content;
    $key=mad4b_home_sections_key($widget);
    if ($key!=='home_section_nile' && $key!=='home_section_combined') return $content;
    $options=mad4b_home_sections_options();
    $emptyKey=$key==='home_section_nile' ? 'home_empty_nile' : 'home_empty_combined';
    if (($options[$emptyKey] ?? 'hide') !== 'hide') return $content;
    if (preg_match('/class=["\x27][^"\x27]*jet-listing-not-found(?:\s|["\x27])/', $content)
        && !preg_match('/class=["\x27][^"\x27]*jet-listing-grid__item(?:\s|["\x27])/', $content)) {
        return '';
    }
    return $content;
}, 20, 2);


/**
 * Restrict restored Tour grids at their native JetEngine query boundary.
 * Target only the exact Home source, never unrelated uses of these listing cards.
 * The Shared Product Core owns public Tour eligibility, not Elementor.
 */
add_filter('jet-engine/listing/grid/posts-query-args', static function($args,$renderer,$settings) {
    if (!is_array($args) || !is_array($settings) || !mad4b_home_sections_page()) return $args;
    // Exact native Elementor Listing, Tour Type and section identity.
    $bindings=[
        '33747'=>['term'=>'625','key'=>'home_section_combined'],
        '25102'=>['term'=>'192','key'=>'home_section_daytrips'],
    ];
    $source=$bindings[(string)($settings['lisitng_id']??'')]??null;
    if (!$source) return $args;
    $matched=false;
    foreach ((array)($args['tax_query']??[]) as $row) {
        if (is_array($row) && ($row['taxonomy']??'')==='tour-types'
            && in_array($source['term'],array_map('strval',(array)($row['terms']??[])),true)) {
            $matched=true;break;
        }
    }
    if (!$matched) return $args;
    $opts=mad4b_home_sections_options();
    if (($opts[$source['key']]??'on')==='off'
        || !mad4b_home_sections_policy_allows($source['key'])
        || !function_exists('shared_presentation_public_tour_query_args_v1')) {
        $args['post__in']=[0];
        return $args;
    }
    return shared_presentation_public_tour_query_args_v1($args);
}, 2100, 3);

/** Refresh the exact homepage when native JetEngine Look and Feel is saved. */
add_action('update_option_look-and-feel', static function($old,$new): void {
    if (!is_array($old) || !is_array($new)) return;
    $keys=[
        'home_section_hero','home_section_cities','home_section_tours',
        'home_section_seo_intro','home_section_faqs',
        'home_section_daytrips','home_section_nile','home_section_combined',
        'home_section_branding','home_section_attractions','home_section_locations',
        'home_section_reviews','home_section_blog','home_section_newsletter',
        'home_empty_nile','home_empty_combined'
    ];
    foreach ($keys as $key) {
        if (($old[$key]??null) === ($new[$key]??null)) continue;
        $front=(int)get_option('page_on_front');
        if($front>0){
            $pages=[$front];
            foreach(mad4b_home_sections_active_languages() as $lang){
                $translated=(int)apply_filters('wpml_object_id',$front,'page',false,$lang);
                if($translated>0)$pages[]=$translated;
            }
            foreach(array_unique($pages) as $id){
                clean_post_cache((int)$id);
                do_action('litespeed_purge_post',(int)$id);
            }
        }
        // Event-driven public HTML invalidation includes Nginx FastCGI.
        // Never flush Redis or call vendor object-cache purge_all().
        do_action('mad4b/public_html/safe_purge_all','company_home_look_and_feel_changed');
        break;
    }
}, 20, 2);


// WPML language activation changes page/menu eligibility even without an
// Elementor or Look & Feel save. Invalidate only on an actual language-set or
// default-language change, not on each render or every unrelated WPML setting.
add_action('update_option_icl_sitepress_settings',static function($old,$new):void {
    if(!is_array($old)||!is_array($new))return;
    $before=array_values(array_filter((array)($old['active_languages']??[]),'is_string'));
    $after=array_values(array_filter((array)($new['active_languages']??[]),'is_string'));
    sort($before,SORT_STRING);sort($after,SORT_STRING);
    if($before===$after && ($old['default_language']??null)===($new['default_language']??null))return;
    $front=(int)get_option('page_on_front');
    if($front>0)clean_post_cache($front);
    do_action('mad4b/public_html/safe_purge_all','company_wpml_active_home_languages_changed');
},20,2);
