<?php
/**
 * WPML + Market Profile audit of the canonical transport-rates CPT.
 * Never returns a binding monetary quote or authorizes a booking.
 */
defined('ABSPATH') || exit;

final class MAD4B_Transport_CPT_Readiness_V1 {
    public const CONTRACT = 'mad4b.transport-cpt-market-readiness.v1';
    private const COPY_KEYS = array(
        'trans_vehicle_category_key','trans_service_kind','trans_route_pattern',
        'trans_schedule_model','trans_inventory_model','trans_service_arrangement',
        'trans_price_role','trans_cost_amount','trans_supplier_discount_pct',
        'trans_source_row_key','trans_review_status',
        '_booking_experience_transport_market_rates_v1',
        '_booking_experience_transport_extras_v1',
    );

    public static function snapshot(): array {
        global $wpdb;
        $settings = get_option('icl_sitepress_settings',array());
        $sync = $settings['custom_posts_sync_option']['transport-rates'] ?? null;
        $fieldTranslation = $settings['translation-management']['custom_fields_translation'] ?? array();
        $missingCopy = array_values(array_filter(self::COPY_KEYS,
            static fn($key) => ($fieldTranslation[$key] ?? null) !== 1));
        $result = array('contract'=>self::CONTRACT, 'canonical_post_type'=>'transport-rates',
            'legacy_cct'=>'transport_rates', 'wpml_post_type_mode'=>$sync,
            'wpml_missing_copy_fields'=>$missingCopy,'rates_count'=>0,
            'published_count'=>0,'draft_count'=>0,'cct_migrated_count'=>0,
            'legacy_cct_duplicate_count'=>0, 'translation_groups'=>0,
            'languages'=>array(),'market_profile_rules_count'=>0,
            'rate_reviews'=>array(),'mutation_performed'=>false,
            'published_price_changed'=>false,'checkout_authorized'=>false);
        $cptRows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug=%s AND status=%s",
            'transport-rates','publish'),ARRAY_A);
        $names = $cptRows ? array_column((array)maybe_unserialize($cptRows[0]['meta_fields']),'name') : array();
        $result['missing_cpt_fields'] = array_values(array_diff(array_slice(self::COPY_KEYS,0,11),$names));
        $posts = get_posts(array('post_type'=>'transport-rates',
            'post_status'=>array('publish','draft','pending','private','future'),
            'posts_per_page'=>500, 'fields'=>'ids', 'suppress_filters'=>true));
        $result['rates_count'] = count($posts);
        $seenCct = array();
        $trids = array();
        foreach ($posts as $postId) {
            $post = get_post($postId);
            if (!$post) continue;
            if ($post->post_status==='publish') $result['published_count']++;
            if ($post->post_status==='draft') $result['draft_count']++;
            $lang = apply_filters('wpml_post_language_details',null,$postId);
            $language = $lang['language_code'] ?? 'unknown';
            $result['languages'][$language] = ($result['languages'][$language] ?? 0)+1;
            $trid = apply_filters('wpml_element_trid',null,$postId,'post_transport-rates');
            $trids[$trid ?: 'p'.$postId] = true;
            $issues = array();
            $cctId = (int)get_post_meta($postId,'source_cct_id',true);
            if ($cctId) {
                $result['cct_migrated_count']++;
                if (isset($seenCct[$cctId])) {
                    $result['legacy_cct_duplicate_count']++;
                    $issues[]='legacy_cct_duplicate';
                }
                $seenCct[$cctId]=true;
            }
            $parentId = (int)get_post_meta($postId,'source_post_id',true);
            $parent = $parentId ? get_post($parentId) : null;
            if (!$parent || $parent->post_type!=='transportations') $issues[] = 'transport_parent_missing';
            $rateMode = (int)get_post_meta($postId,'trans_mode_term_id',true);
            $rateType = (int)get_post_meta($postId,'trans_type_term_id',true);
            if ($parent && $parent->post_type==='transportations') {
                $mode=(int)get_post_meta($parentId,'trans_mode',true);
                $type=(int)get_post_meta($parentId,'trans_type',true);
                if ($rateMode && $mode && $rateMode !== $mode) $issues[] = 'mode_mismatch';
                if ($rateType && $type && $rateType !== $type) $issues[] = 'type_mismatch';
            }
            $validTo=(string)get_post_meta($postId,'valid_to',true);
            if ($validTo!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$validTo)
                && $validTo < wp_date('Y-m-d')) $issues[]='expired';
            $rules = get_post_meta($postId,'_booking_experience_transport_market_rates_v1',true);
            if (!is_array($rules)) $rules=array();
            $result['market_profile_rules_count'] += count($rules);
            if ($rules && class_exists('BookingExperience\Transport_Extras_V1',false)) {
                $normalized=\BookingExperience\Transport_Extras_V1::validate_market($rules);
                if (is_wp_error($normalized)) $issues[] = 'market_rules_invalid';
            }
            if ($issues) $result['rate_reviews'][] = array('id'=>$postId,
                'status'=>$post->post_status,'issues'=>array_values(array_unique($issues)));
        }
        $result['translation_groups']=count($trids);
        $result['markets'] = 'existing_approved_market_profiles_only';
        $result['language_fallback_policy'] = $sync == 2
            ? 'display_original_if_untranslated' : 'translation_required_or_disabled';
        $result['ready_for_translation'] = $sync == 2 && !$missingCopy && !$result['missing_cpt_fields'];
        return $result;
    }
    public static function rest(): void {
        register_rest_route('mad4b-pricing/v1','/transport-cpt-readiness',array(
            'methods'=>'GET','callback'=>static fn()=>rest_ensure_response(self::snapshot()),
            'permission_callback'=>array('MAD4B_Transport_Pricing_Readiness_V1','permitted'),
        ));
    }
    public static function admin(): void {
        $snapshot = self::snapshot();
        echo '<h2>Canonical Transport Rates CPT — WPML & Market Profiles</h2>';
        echo '<p><strong>CPT:</strong> '.esc_html($snapshot['canonical_post_type']).
          ' &nbsp; <strong>Rates:</strong> '.esc_html((string)$snapshot['rates_count']).
          ' &nbsp; <strong>Published:</strong> '.esc_html((string)$snapshot['published_count']).
          ' &nbsp; <strong>Legacy CCT references:</strong> '.esc_html((string)$snapshot['cct_migrated_count']).
          '</p><p><strong>WPML fallback:</strong> '.esc_html($snapshot['language_fallback_policy']).
          ' &nbsp; <strong>Languages:</strong> '.esc_html(wp_json_encode($snapshot['languages'])).
          ' &nbsp; <strong>Market profile rules:</strong> '.esc_html((string)$snapshot['market_profile_rules_count']).'</p>';
        if ($snapshot['wpml_missing_copy_fields'] || $snapshot['missing_cpt_fields']) {
            echo '<p><strong>Incomplete migration:</strong> '.esc_html(implode(', ',array_merge(
                $snapshot['wpml_missing_copy_fields'],$snapshot['missing_cpt_fields']))).'</p>';
        }
        echo '<p>Translation changes editorial text only. Market pricing belongs to approved Market Profiles; display does not authorize checkout.</p>';
        if (!$snapshot['rate_reviews']) return;
        echo '<table class="widefat striped"><thead><tr><th>Rate</th><th>Review issues</th></tr></thead><tbody>';
        foreach ($snapshot['rate_reviews'] as $rate) {
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($rate['id'])).'">'.esc_html((string)$rate['id']).
                 '</a></td><td>'.esc_html(implode(', ',$rate['issues'])).'</td></tr>';
        }
        echo '</tbody></table>';
    }
}
add_action('rest_api_init',array('MAD4B_Transport_CPT_Readiness_V1','rest'));
