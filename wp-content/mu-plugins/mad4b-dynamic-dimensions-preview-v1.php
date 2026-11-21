<?php
/**
 * Plugin Name: MAD4B Dynamic Dimension Markup Preview
 * Description: Read-only, source-backed two-dimension Tour Type + Package Level pricing evaluation.
 * Version: 0.1.0
 * Scope: Pricing previews only; never mutates public prices, checkout, or WooCommerce orders.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Dynamic_Dimensions_V1', false)) {
final class MAD4B_Dynamic_Dimensions_V1 {
    const OPTION = 'mad4b_dynamic_dimension_markup_v1';
    const VERSION = 'mad4b.dynamic-dimensions.v1';

    public static function settings() {
        $v = get_option(self::OPTION, []);
        if (!is_array($v) || ($v['schema'] ?? '') !== self::VERSION
            || ($v['enabled'] ?? null) !== true
            || ($v['sequence'] ?? []) !== ['tour_type','package_level'])
            return new WP_Error('dimension_policy_not_configured');
        $rows = $v['type_rules'] ?? null;
        if (!is_array($rows) || !$rows || count($rows) > 40)
            return new WP_Error('dimension_type_rules_invalid');
        $map = [];
        foreach ($rows as $r) {
            $id = (int)($r['term_id'] ?? 0);
            $percent = $r['increase_percent'] ?? null;
            if ($id < 1 || isset($map[$id]) || !is_numeric($percent)
                || (float)$percent < 0 || (float)$percent > 300
                || empty($r['enabled']))
                return new WP_Error('dimension_type_rule_invalid');
            $map[$id] = ['term_id'=>$id,'increase_percent'=>(float)$percent,
                'label'=>(string)($r['label'] ?? '')];
        }
        return ['rules'=>$map,'policy'=>$v];
    }

    private static function raw_terms(int $id, string $taxonomy): array {
        global $wpdb;
        return array_map('intval',(array)$wpdb->get_col($wpdb->prepare(
            "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
              JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
              WHERE tr.object_id=%d AND tt.taxonomy=%s ORDER BY tt.term_id",
            $id,$taxonomy
        )));
    }

    /**
     * Resolve a caller's WPML term ID to the actual term assigned to this
     * translated Tour. Do not accept a related term unless it resolves to
     * exactly one stored relationship for the same package identity.
     *
     * @return int|WP_Error
     */
    public static function package_for_tour(int $tour, int $requested) {
        $assigned = array_values(array_unique(self::raw_terms($tour, 'package_levels')));
        if ($requested < 1 || !$assigned) return new WP_Error('dimension_package_not_offered');
        $source = get_term($requested, 'package_levels');
        if (!($source instanceof WP_Term)) return new WP_Error('dimension_package_not_offered');
        if (in_array($requested, $assigned, true)) return $requested;

        $languageDetails = apply_filters('wpml_post_language_details', null, $tour);
        $language = is_array($languageDetails) ?
            (string)($languageDetails['language_code'] ?? '') : '';
        if ($language === '') return new WP_Error('dimension_package_not_offered');

        $translation = apply_filters('wpml_object_id', $requested,
            'package_levels', false, $language);
        $translation = is_numeric($translation) ? (int)$translation : 0;
        // The explicit WPML mapping is merely a candidate. A real original
        // WordPress relationship is the final authority for eligibility.
        if ($translation < 1 || !in_array($translation, $assigned, true))
            return new WP_Error('dimension_package_not_offered');

        // Never let a broken or inconsistent WPML map exchange one Package
        // Level for a different package-level identity.
        $sourceCanonical = apply_filters('wpml_object_id', $requested,
            'package_levels', false, 'en');
        $targetCanonical = apply_filters('wpml_object_id', $translation,
            'package_levels', false, 'en');
        if (!is_numeric($sourceCanonical) || !is_numeric($targetCanonical)
            || (int)$sourceCanonical < 1
            || (int)$sourceCanonical !== (int)$targetCanonical)
            return new WP_Error('dimension_package_wpml_conflict');
        return $translation;
    }

    public static function preview(int $tour, int $package) {
        $requestedPackage = $package;
        $cfg = self::settings();
        if (is_wp_error($cfg)) return $cfg;
        if (!function_exists('shared_product_pricing_setting')
            || shared_product_pricing_setting('pricing_engine_enabled') !== true)
            return new WP_Error('dimension_pricing_engine_disabled');
        if (get_post_type($tour) !== 'tours-and-activities'
            || get_post_status($tour) !== 'publish')
            return new WP_Error('dimension_tour_not_published');
        $resolvedPackage = self::package_for_tour($tour, $package);
        if (is_wp_error($resolvedPackage)) return $resolvedPackage;
        $package = $resolvedPackage;
        $matching = [];
        foreach (self::raw_terms($tour,'tour-types') as $term_id) {
            if (isset($cfg['rules'][$term_id])) $matching[] = $cfg['rules'][$term_id];
        }
        if (count($matching) !== 1)
            return new WP_Error(count($matching) ? 'dimension_type_ambiguous' : 'dimension_tour_type_not_priced');
        $price = get_post_meta($tour,'start_price',true);
        $currency = (string)get_post_meta($tour,'tour_base_currency',true);
        $unit = (string)get_post_meta($tour,'tour_price_unit',true);
        if (!is_numeric($price) || !is_finite((float)$price) || (float)$price <= 0
            || !preg_match('/^[A-Z]{3}$/D',$currency) || $unit !== 'per_person')
            return new WP_Error('dimension_base_price_unavailable');

        $base = round((float)$price,2);
        $type = $matching[0];
        $classified = round($base * (1+$type['increase_percent']/100),2);
        if (!function_exists('shared_product_package_level_preview'))
            return new WP_Error('dimension_native_package_provider_missing');

        // WPML term ownership is language-specific; preserve caller language.
        $before = (string)apply_filters('wpml_current_language',null);
        $details = apply_filters('wpml_post_language_details',null,$tour);
        $language = is_array($details) ? (string)($details['language_code'] ?? '') : '';
        try {
            if ($language && $language !== $before) do_action('wpml_switch_language',$language);
            $native = shared_product_package_level_preview($tour,null,$package,$classified);
        } finally {
            if ($language && $language !== $before && $before) do_action('wpml_switch_language',$before);
        }
        if (is_wp_error($native)) return $native;
        $final = round((float)($native['adjusted_amount'] ?? 0),2);
        if ($final <= 0) return new WP_Error('dimension_calculation_invalid');

        return [
            'contract'=>self::VERSION,'status'=>'preview_only','tour_id'=>$tour,
            'package_term_id'=>$package,'requested_package_term_id'=>$requestedPackage,
            'term_translation_resolved'=>$package !== $requestedPackage,
            'language'=>$language,
            'source'=>'tour_start_price','currency'=>$currency,'price_unit'=>$unit,
            'base_price'=>$base,'classification'=>[
                'term_id'=>$type['term_id'],'label'=>$type['label'],
                'increase_percent'=>$type['increase_percent'],'price_after'=>$classified
            ],'package_level'=>[
                'term_id'=>$package,'factor_percent'=>(float)$native['percent'],
                'increase_percent'=>(float)$native['percent']-100,
            ],'final_preview_price'=>$final,
            'checkout_ready'=>false,'commercial_active'=>false,
            'published_tour_prices_changed'=>false,
        ];
    }

    public static function route() {
        register_rest_route('mad4b-pricing/v1','/dimension-markup-preview',[
            'methods'=>'GET',
            'permission_callback'=>static function(){
                return current_user_can('commerce_manage_pricing')
                    && (current_user_can('manage_woocommerce')
                    || current_user_can('mad4b_manage_company_operations'));
            },
            'callback'=>static function($request){
                $result=self::preview(absint($request->get_param('tour_id')),
                    absint($request->get_param('package_term_id')));
                return is_wp_error($result) ? $result : rest_ensure_response($result);
            },
            'args'=>[
                'tour_id'=>['required'=>true,'sanitize_callback'=>'absint'],
                'package_term_id'=>['required'=>true,'sanitize_callback'=>'absint']
            ]
        ]);
    }

    public static function menu() {
        if (!current_user_can('commerce_manage_pricing')) return;
        add_submenu_page('mad4b-pricing-workspace','Dynamic Dimensions Preview',
            'Dynamic Dimensions Preview','commerce_manage_pricing',
            'mad4b-dynamic-dimensions',[__CLASS__,'page']);
    }
    public static function page() {
        if (!current_user_can('commerce_manage_pricing')) wp_die('Pricing permission required');
        $cfg=self::settings();
        echo '<div class="wrap"><h1>Dynamic Pricing — Tour Type + Package Level</h1>';
        echo '<p>Source-backed <strong>read-only preview</strong>: Base Price × Tour Type markup × Package Level markup. No checkout, booking or public price changes.</p>';
        if (is_wp_error($cfg)) {echo '<p>'.esc_html($cfg->get_error_code()).'</p></div>';return;}
        echo '<h2>Tour Type rules</h2><table class="widefat striped" style="max-width:780px"><thead><tr><th>Type</th><th>Term ID</th><th>Markup</th></tr></thead><tbody>';
        foreach ($cfg['rules'] as $r) echo '<tr><td>'.esc_html($r['label']).'</td><td>'.(int)$r['term_id'].'</td><td>+'.esc_html((string)$r['increase_percent']).'%</td></tr>';
        echo '</tbody></table><h2>Package Level rules (native JetEngine)</h2><table class="widefat striped" style="max-width:780px"><thead><tr><th>Level</th><th>Markup</th></tr></thead><tbody>';
        foreach ((array)get_option('etg-dynamic-pricing-controls_shared_package_pricing_rows',[]) as $r) {
            if (!is_array($r)||empty($r['enabled']))continue;
            $t=get_term((int)($r['package_term_id']??0),'package_levels');
            $name=$t instanceof WP_Term?$t->name:(string)($r['package_term_id']??'');
            echo '<tr><td>'.esc_html($name).'</td><td>+'.esc_html((string)((float)$r['percent']-100)).'%</td></tr>';
        }
        echo '</tbody></table><h2>Quote preview</h2><form method="get">';
        echo '<input type="hidden" name="page" value="mad4b-dynamic-dimensions"><label for="dd-tour">Tour ID </label> <input id="dd-tour" type="number" min="1" name="tour_id" required value="'.esc_attr((string)absint($_GET['tour_id']??0)).'"> ';
        echo '<label for="dd-package">Package Term ID </label> <input id="dd-package" type="number" min="1" name="package_term_id" required value="'.esc_attr((string)absint($_GET['package_term_id']??0)).'"> ';
        submit_button('Preview', 'secondary', 'submit', false);echo '</form>';
        if (!empty($_GET['tour_id'])&&!empty($_GET['package_term_id'])) {
            $v=self::preview(absint($_GET['tour_id']),absint($_GET['package_term_id']));
            echo '<pre style="background:#fff;padding:12px;max-width:780px;overflow:auto">'.esc_html(
                is_wp_error($v)?$v->get_error_code():wp_json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)
            ).'</pre>';
        }
        echo '</div>';
    }
}
add_action('rest_api_init',['MAD4B_Dynamic_Dimensions_V1','route']);
add_action('admin_menu',['MAD4B_Dynamic_Dimensions_V1','menu'],120);
}
