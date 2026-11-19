<?php
if(!defined('ABSPATH'))exit;

/** Stable Lab-only compatibility runtime for destination taxonomy. */
// Migrated from 092-legacy-location-public-retirement.php

/** Reversible retirement of public legacy Locations CPT in isolated local WordPress Lab. */
add_action('init', static function () {
    if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;
    $type = get_post_type_object('locations');
    if (!$type) return;
    $type->publicly_queryable = false;
    $type->exclude_from_search = true;
    $type->has_archive = false;
    $type->show_in_nav_menus = false;
}, PHP_INT_MAX);


// Migrated from 093-query-builder-filter-bridge.php

/** Generic Query Builder / Smart Filters bridge. Lab-scoped during certification. */
add_action('jet-engine/query-builder/query/after-query-setup', static function($query) {
    if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || !is_tax('destinations')) return;
    if (($query->query_id ?? '') !== 'single_destination_term_archive_related_tours') return;
    if (empty($_REQUEST['jsf']) || !function_exists('jet_smart_filters')) return;
    $filter = jet_smart_filters()->query->get_query_args();
    $terms = $filter['tax_query'] ?? [];
    if (!is_array($terms) || !$terms) return;
    foreach ($terms as $taxonomy => $condition) {
        if (!taxonomy_exists($taxonomy) || !is_array($condition)) return;
        $ids = array_filter(array_map('absint', (array)($condition['terms'] ?? [])));
        if (!$ids) return;
        $terms[$taxonomy]['terms'] = array_values($ids);
    }
    $query->set_filtered_prop('tax_query', $terms);
}, 11);


// Migrated from 097-lab-jsf-destination-rewrite-fix.php

/** ETG WooCommerce Lab: JSF permalink support for hierarchical destinations. */
add_filter('rewrite_rules_array',static function($rules){
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return $rules;
 $key='destination/(?:[^/]+/)*([^/]+)/jsf/(.*)/?$';
 $target='index.php?taxonomy=destinations&term=$matches[1]&jsf=$matches[2]';
 return [$key=>$target]+$rules;
},PHP_INT_MAX);


// Migrated from 098-lab-location-legacy-redirect.php

/** LAB-only historical location permalink canonicalization independent of CPT records. */
add_action('template_redirect',static function(){
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)||is_admin())return;
 $path=wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
 if(!preg_match('~^/(?:(en|fr|de|es|it|ar)/)?locations/([^/]+)/?$~u',$path,$m))return;
 $language=!empty($m[1])?$m[1]:'en';
 $slug=rawurldecode($m[2]);
 $map=[
  'en'=>['east-bank-of-the-nile-river-in-luxor'=>2308,'west-bank-of-the-nile-river-in-luxor'=>2309],
  'fr'=>['rive-est-du-nil-a-louxor'=>2746,'rive-ouest-du-nil-a-louxor'=>2747],
  'de'=>['rive-est-du-nil-a-louxor'=>2749,'westufer-des-nils-in-luxor'=>2750],
  'es'=>['orilla-oriental-del-rio-nilo-en-luxor'=>2635,'orilla-oeste-del-rio-nilo-en-luxor'=>2645],
  'ar'=>['الضفة-الشرقية-لنهر-النيل-في-الأقصر'=>2382,'الضفة-الغربية-لنهر-النيل-في-الأقصر'=>2387]
 ];
 $id=$map[$language][$slug]??null;
 if(!$id)return;
 do_action('wpml_switch_language',$language);
 $target=get_term_link($id,'destinations');
 if(is_wp_error($target))return;
 wp_safe_redirect($target,301,'ETG-LAB-Independent-Locations');exit;
},0);
/* Handle historic links for WPML-hidden languages before WPML's front-end redirect. */
add_action('init',static function(){
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)||is_admin())return;
 $path=wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
 if(!preg_match('~^/(fr|de|ar)/locations/([^/]+)/?$~u',$path,$m))return;
 $slug=rawurldecode($m[2]);$lang=$m[1];
 $map=['fr'=>['rive-est-du-nil-a-louxor'=>2746,'rive-ouest-du-nil-a-louxor'=>2747],'de'=>['rive-est-du-nil-a-louxor'=>2749,'westufer-des-nils-in-luxor'=>2750],'ar'=>['الضفة-الشرقية-لنهر-النيل-في-الأقصر'=>2382,'الضفة-الغربية-لنهر-النيل-في-الأقصر'=>2387]];
 $id=$map[$lang][$slug]??null;if(!$id)return;
 do_action('wpml_switch_language',$lang);$target=get_term_link($id,'destinations');
 if(is_wp_error($target))return;
 wp_safe_redirect($target,301,'ETG-LAB-Hidden-Legacy-Locations');exit;
},50);


// Migrated from 099-lab-destinations-archive-router.php

/** Isolated ETG WooCommerce Lab: render destinations archive before Woo product archive selection. */
add_action('template_redirect', static function () {
    if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || !is_tax('destinations') || is_admin()) return;
    $template=WP_CONTENT_DIR.'/themes/astra-child/taxonomy-destinations.php';
    if (!is_readable($template)) return;
    include $template;
    exit;
}, -10000);

add_action('wp_enqueue_scripts', static function() {
    if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || !is_tax('destinations') || !class_exists('Elementor\Plugin')) return;
    Elementor\Plugin::instance()->frontend->enqueue_styles();
    Elementor\Plugin::instance()->frontend->enqueue_scripts();
}, 999);

/* Stable legacy Locations archive redirect. Applies to archive root only. */
add_action('template_redirect', static function () {
    if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || is_admin()) return;
    $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!in_array($path, ['/locations', '/locations/'], true)) return;
    $term = get_term(665, 'destinations');
    if (!$term || is_wp_error($term)) return;
    $url = get_term_link($term, 'destinations');
    if (is_wp_error($url)) return;
    wp_safe_redirect($url, 301, 'Lab-Legacy-Geo-Archive');
    exit;
}, -11000);

/** Preserve Astra's Elementor full-width archive layout for migrated taxonomy.
 * Previous local-only archive bypassed Theme Builder at template_redirect,
 * causing Astra's plain container, extra 20px gutter and wrong CSS context.
 */
add_filter('astra_get_content_layout',static function($layout){
 if((defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED) && !is_admin() && is_tax('destinations'))return 'page-builder';
 return $layout;
},99);

/** Manually rendered Elementor archives still require their active Kit CSS.
 * The forced taxonomy router runs outside Elementor Theme Builder's normal
 * location bootstrap, which otherwise supplies global color/font tokens.
 */
add_action('wp_enqueue_scripts',static function(){
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)||!is_tax('destinations')||
    !class_exists('\Elementor\Plugin')||!class_exists('\Elementor\Core\Files\CSS\Post'))return;
 $kit=\Elementor\Plugin::instance()->kits_manager->get_kit_for_frontend();
 if(!$kit)return;
 \Elementor\Core\Files\CSS\Post::create($kit->get_main_id())->enqueue();
},50);
add_filter('body_class',static function($classes){
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)||!is_tax('destinations')||
    !class_exists('\Elementor\Plugin'))return $classes;
 $kit=\Elementor\Plugin::instance()->kits_manager->get_kit_for_frontend();
 if($kit)$classes[]='elementor-kit-'.$kit->get_main_id();
 return $classes;
},99);

/** Prevent a second, blank native Astra mobile header above ElementsKit.
 * The destination archive includes its own full Elementor header template.
 */
add_filter('astra_header_display',static function($display){
 if((defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED) && !is_admin() && is_tax('destinations'))return 'disabled';
 return $display;
},99);
