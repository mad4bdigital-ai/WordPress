<?php
/**
 * Market-aware, WPML-aware public presentation for EXISTING transport-rates CPT.
 *
 * This is a display adapter only. BookingExperience owns eligibility and quote
 * computation. No base amount is exposed without signed provider approval.
 */
defined('ABSPATH') || exit;

final class MAD4B_Transport_Market_Display_V1 {
    public const CONTRACT = 'mad4b.transport-market-display.v1';
    private const UNIT_COUNTS = array(
        'per_person' => 'persons', 'per_cabin'=>'cabins',
        'per_vehicle'=>'vehicles', 'per_trip'=>'trips', 'per_day'=>'days',
    );
    public static function resolve_date($input): string {
        $date = (string) $input;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) ||
          !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))
            return wp_date('Y-m-d');
        return $date;
    }

    public static function eligible_offer(int $transportId, int $rateId, string $date, string $context = 'standalone'): array {
        if (!function_exists('BookingExperience\\transport_rate_eligible') ||
            !function_exists('BookingExperience\\transport_rate_preview'))
            return array('displayable'=>false, 'reason'=>'transport_provider_missing');

        $eligibility = \BookingExperience\transport_rate_eligible($rateId,$date,$context);
        if (is_wp_error($eligibility))
            return array('displayable'=>false,'reason'=>$eligibility->get_error_code());

        $unit = (string) ($eligibility['data']['unit'] ?? '');
        if (!isset(self::UNIT_COUNTS[$unit]))
            return array('displayable'=>false,'reason'=>'unsupported_price_unit');
        $quantities = array(self::UNIT_COUNTS[$unit]=>1);
        $quote = \BookingExperience\transport_rate_preview(
            $transportId, $quantities, $date, $rateId, $context);
        if (is_wp_error($quote))
            return array('displayable'=>false,'reason'=>$quote->get_error_code());
        if (!is_array($quote) ||
            ($quote['checkout_ready']??null)!==false ||
            ($quote['provider']??'')!=='transport_rate_cpt' ||
            (int)($quote['rate_id']??0)!==$rateId ||
            !is_numeric($quote['preview_price']??null) ||
            !preg_match('/^[A-Z]{3}$/D',(string)($quote['currency']??'')))
            return array('displayable'=>false,'reason'=>'provider_quote_invalid');

        $market = function_exists('shared_product_market_current_context')
            ? shared_product_market_current_context() : array();
        return array('displayable'=>true, 'rate_id'=>$rateId,
            'preview_price'=>(float)$quote['preview_price'],
            'unit'=>$unit, 'quantity'=>1,
            'currency'=>(string)$quote['currency'],
            'language'=>(string) apply_filters('wpml_current_language',null),
            'market_profile_id'=>isset($market['profile_id']) && is_string($market['profile_id']) ?
                 $market['profile_id'] : null,
            'market_context_status'=>$market['status'] ?? 'no_explicit_approved_market',
            'market_rule_applied'=>(bool)($quote['market_receipt']['applied'] ?? false),
            'travel_date'=>$date,
            'checkout_ready'=>false,'availability_checked'=>false,
            'provider'=>'transport_rate_cpt');
    }

    public static function render($atts = array()): string {
        $a=shortcode_atts(array('transport_id'=>0,'date'=>'','context'=>'standalone'),(array)$atts,'transport_rate_catalog');
        $transportId=absint($a['transport_id']) ?: get_the_ID();
        $transportId=(int)$transportId;
        $context = (string)$a['context'];
        if (!in_array($context,array('standalone','tour_component'),true) ||
            get_post_type($transportId)!=='transportations')
            return '';
        if (function_exists('service_visibility_active') &&
            !service_visibility_active('transportations') && !current_user_can('manage_woocommerce'))
            return '';
        if (function_exists('service_channel_allowed') &&
            !service_channel_allowed('transportations',$context) &&
            !current_user_can('manage_woocommerce')) return '';

        if (!function_exists('BookingExperience\\transport_rate_candidates')) return '';
        $rates=\BookingExperience\transport_rate_candidates($transportId);
        if (!$rates) return '<p class="transport-rates-empty">'.
            esc_html__('Transport rates on request','woocommerce').'</p>';

        $date=self::resolve_date($a['date'] ?: wp_date('Y-m-d'));
        ob_start();
        echo '<section class="transport-rates-cpt mad4b-transport-market-rates" data-transport-id="' .
            esc_attr((string)$transportId).'" data-price-source="transport-rates">';
        echo '<h3>'.esc_html__('Transport Rates','woocommerce').'</h3>';
        echo '<div class="transport-rates-table-wrap"><table class="transport-rates-table"><thead><tr>';
        foreach (array(__('Rate','woocommerce'),__('Date','woocommerce'),__('Unit','woocommerce'),
            __('Price','woocommerce'),__('Status','woocommerce')) as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        $shown=0;
        foreach ($rates as $rate) {
            if (!is_object($rate) || get_post_type($rate->ID)!=='transport-rates') continue;
            $offer=self::eligible_offer($transportId,(int)$rate->ID,$date,$context);
            ++$shown;
            echo '<tr data-rate-id="'.esc_attr((string)$rate->ID).'"><th scope="row">'.
               esc_html(get_the_title($rate->ID)).'</th><td>'.esc_html($date).'</td>';
            if (!$offer['displayable']) {
                echo '<td>—</td><td>'.esc_html__('On request','woocommerce').'</td><td>'.
                    esc_html__('Not confirmed','woocommerce').'</td>';
            } else {
                $amount=number_format_i18n($offer['preview_price'],2);
                echo '<td>'.esc_html($offer['unit']).'</td><td>'.esc_html($amount.' '.$offer['currency']).
                    '</td><td>'.esc_html__('Indicative price only','woocommerce').'</td>';
            }
            echo '</tr>';
        }
        if (!$shown) echo '<tr><td colspan="5">'.esc_html__('Transport rates on request','woocommerce').'</td></tr>';
        echo '</tbody></table></div>';
        echo '<p class="transport-rates-disclaimer">'.
            esc_html__('Prices use the selected market profile when verified. Availability, taxes and checkout are not confirmed.','woocommerce').
            '</p></section>';
        return (string)ob_get_clean();
    }

    public static function install_shortcode(): void {
        if (!function_exists('BookingExperience\\transport_rate_candidates')) return;
        // Override only the original provider's registered shortcode. The
        // provider, its signed approvals, and its pricing logic remain intact.
        add_shortcode('transport_rate_catalog',array(__CLASS__,'render'));
    }
}
add_action('init', array('MAD4B_Transport_Market_Display_V1','install_shortcode'), 99);
