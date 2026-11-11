<?php
/**
 * Plugin Name: MAD4B Central Homepage Sections
 * Description: Connects existing Elementor home subtemplates to the JetEngine Look and Feel options page.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) exit;
if (wp_get_environment_type() !== 'staging' || rtrim(home_url(), '/') !== 'https://tours.dev.mad4b.com') return;

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
        static $translated_ids = null;
        if ($translated_ids === null) {
            $translated_ids = $ids;
            $language = (string) apply_filters('wpml_current_language', null);
            if ($language !== '') {
                foreach ($ids as $source_id => $section_key) {
                    $translated_id = (int) apply_filters('wpml_object_id', $source_id, 'elementor_library', false, $language);
                    if ($translated_id > 0) $translated_ids[$translated_id] = $section_key;
                }
            }
        }
        return $translated_ids[(int)($settings['template_id'] ?? 0)] ?? '';
    }
    if ($name === 'shortcode') {
        $text = (string)($settings['shortcode'] ?? '');
        if (preg_match('/\[elementor-template\s+id=["\x27]?(\d+)/', $text, $matches)) {
            return [41727=>'home_section_hero',25909=>'home_section_cities'][(int)$matches[1]] ?? '';
        }
    }
    return '';
}
function mad4b_home_sections_page() {
    if (is_admin()) return false;
    $page_id = (int) get_queried_object_id();
    if ($page_id === 35138) return true;
    $language = (string) apply_filters('wpml_current_language', null);
    if ($language === '') return false;
    $translated_id = (int) apply_filters('wpml_object_id', 35138, 'page', false, $language);
    return $translated_id > 0 && $page_id === $translated_id;
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
    if (array_key_exists($key,$cache)) return $cache[$key];
    if (!mad4b_home_sections_policy_allows($key)) return $cache[$key]=false;
    if ($key === 'home_section_combined') {
        if (!function_exists('shared_presentation_published_facet_terms_v1'))
            return $cache[$key]=false;
        $term=get_term_by('slug','combined-tours','tour-types');
        if (!$term instanceof WP_Term) return $cache[$key]=false;
        $ids=shared_presentation_published_facet_terms_v1('tours-and-activities','tour-types');
        return $cache[$key]=in_array((int)$term->term_id,array_map('intval',$ids),true);
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
        if (!$term_ids) return $cache[$key]=false;
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
        if (!is_array($args)) return $cache[$key]=false;
        $query = new WP_Query($args);
        return $cache[$key]=!empty($query->posts);
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
        clean_post_cache(35138);
        do_action('litespeed_purge_post',35138);
        break;
    }
}, 20, 2);
