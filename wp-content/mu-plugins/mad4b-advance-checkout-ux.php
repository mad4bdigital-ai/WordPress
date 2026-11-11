<?php
/**
 * Plugin Name: MAD4B Advance Checkout Responsive UI
 * Description: A scoped presentation layer for advance order-pay, retaining WooCommerce/Stripe controls and the original legal snapshot.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Advance_Checkout_UI', false)) {
final class MAD4B_Advance_Checkout_UI {
    private static function order() {
        if (!function_exists('wc_get_order')) return false;
        $id = absint(get_query_var('order-pay'));
        if (!$id) {
            global $wp;
            $id = absint($wp->query_vars['order-pay'] ?? 0);
        }
        return $id ? wc_get_order($id) : false;
    }
    private static function active(): bool {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) return false;
        $o = self::order();
        return $o instanceof \WC_Order
            && class_exists('\Commerce\Composition\RequestAdvancePayments')
            && \Commerce\Composition\RequestAdvancePayments::isAdvance($o);
    }
    public static function boot():void {
        // The existing payment validation, policy fingerprint and Woo/Stripe flow are unchanged.
        remove_action('woocommerce_pay_order_before_submit', ['Commerce\Composition\RequestAdvancePayments','terms'], 10);
        add_action('woocommerce_pay_order_before_submit',[self::class,'terms'],10);
        add_action('woocommerce_pay_order_before_payment',[self::class,'heading'],10);
        add_filter('body_class',[self::class,'bodyClass']);
        add_action('wp_enqueue_scripts',[self::class,'assets'],110);
    }
    public static function bodyClass(array $classes):array {
        if (self::active()) $classes[]='mad4b-advance-checkout-ui';
        return $classes;
    }
    public static function assets():void {
        if (!self::active())return;
        $file=__DIR__.'/assets/mad4b-advance-checkout-ux.css';
        if (!is_file($file))return;
        wp_enqueue_style('mad4b-advance-checkout-ux',
            plugins_url('assets/mad4b-advance-checkout-ux.css',__FILE__),
            [],(string)filemtime($file));
    }
    public static function heading():void {
        if (!self::active())return;
        $stripe=(array)get_option('woocommerce_stripe_settings',[]);
        echo '<div class="mad4b-advance-heading"><span class="mad4b-advance-eyebrow">'
            .esc_html__('Secure payment','woocommerce').'</span>';
        echo '<h2>'.esc_html__('Pay your tour advance','commerce-composition').'</h2>';
        echo '<p>'.esc_html__('Only the advance is payable now. Availability and the final itinerary are confirmed separately.','commerce-composition').'</p>';
        if (($stripe['testmode']??'no')==='yes')echo '<span class="mad4b-advance-sandbox">'.esc_html__('Stripe test environment — no live charge','commerce-composition').'</span>';
        echo '</div>';
    }
    private static function policyBody(string $html):string {
        if ($html==='' || !class_exists('\DOMDocument')) return wp_kses_post($html);
        // The immutable snapshot contains its own compact summary. Suppress that
        // duplicate in the expanded view, but preserve all narrative paragraphs/tables.
        $doc=new \DOMDocument();
        $htmlUtf8='<?xml encoding="UTF-8"><div id="mad4b-policy-body-root">'.$html.'</div>';
        $old=libxml_use_internal_errors(true);
        $ok=$doc->loadHTML($htmlUtf8,LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();libxml_use_internal_errors($old);
        if(!$ok)return wp_kses_post($html);
        $xp=new \DOMXPath($doc);
        $root=$xp->query('//*[@id="mad4b-policy-body-root"]')->item(0);
        if(!$root)return wp_kses_post($html);
        foreach ($xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," mad4b-policy-controls ") or contains(concat(" ",normalize-space(@class)," ")," mad4b-policy-notice ")]',$root) as $node) {
            if($node->parentNode)$node->parentNode->removeChild($node);
        }
        $out='';
        foreach($root->childNodes as $node)$out.=$doc->saveHTML($node);
        return wp_kses_post($out);
    }

    /**
     * The arrival date is taken from the ORIGINAL verified Fluent Forms submission.
     * For new rate-routed orders it must also match the signed server quote and
     * the immutable WooCommerce reference. Never use query strings or browser dates.
     */
    public static function arrivalDateForOrder($o): ?\DateTimeImmutable {
        if (!$o instanceof \WC_Order ||
            !class_exists('\Commerce\Composition\RequestAdvancePayments')) return null;
        $fid=(int)$o->get_meta('_request_form_id');
        $sid=(int)$o->get_meta('_request_submission_id');
        $source=(int)$o->get_meta('_request_source_id');
        if ($fid<1 || $sid<1 || $source<1) return null;
        $verified=\Commerce\Composition\RequestAdvancePayments::submissionContext($fid,$sid);
        if (is_wp_error($verified) || (int)($verified['source_id']??0)!==$source) return null;
        global $wpdb;
        $json=$wpdb->get_var($wpdb->prepare(
            "SELECT response FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d AND form_id=%d LIMIT 1",
            $sid,$fid
        ));
        $response=is_string($json)?json_decode($json,true):null;
        if(!is_array($response))return null;
        $ref=$o->get_meta('_mad4b_single_fluent_reference_v1');
        $cfg=class_exists('\Commerce\Composition\SingleFluentPaymentRouter')
            ?\Commerce\Composition\SingleFluentPaymentRouter::config():[];
        $field=($fid===(int)($cfg['form_id']??0) && is_string($cfg['date_field']??null))
            ? $cfg['date_field']:'datetime';
        $original=$response[$field]??null;
        if(!is_string($original) || strlen($original)>35)return null;
        $arrival=null;
        foreach(['d-M-y','d-M-Y','Y-m-d'] as $fmt){
            $dt=\DateTimeImmutable::createFromFormat('!'.$fmt,trim($original),wp_timezone());
            if($dt && $dt->format($fmt)===trim($original)){
                $arrival=$dt;break;
            }
        }
        if(!$arrival)return null;
        $date=$arrival->format('Y-m-d');
        if(is_array($ref) && ($ref['adapter']??'')==='server_rate_v1'){
            if((int)($ref['form_id']??0)!==$fid ||
               (int)($ref['source']??0)!==$source ||
               (int)($ref['rate']??0)!==(int)($verified['rate_id']??0) ||
               ($ref['date']??'')!==$date)return null;
            $intent=$response['_shared_tour_booking_intent_v2']??null;
            if(!is_array($intent)||!is_string($intent['_request_advance_seal']??null))return null;
            $seal=$intent['_request_advance_seal'];
            unset($intent['_request_advance_seal']);
            $sealExpected=\Commerce\Composition\RequestAdvancePayments::sealIntent($intent,$fid)['_request_advance_seal'];
            if(!hash_equals($sealExpected,$seal) ||
               ($intent['travel_date']??'')!==$date ||
               (int)($intent['tour_id']??0)!==$source ||
               (int)($intent['rate_id']??0)!==(int)($ref['rate']??0))return null;
        }
        return $arrival;
    }

    public static function terms():void {
        $o=self::order();
        if (!$o || !\Commerce\Composition\RequestAdvancePayments::isAdvance($o))return;
        $snapshot=$o->get_meta('_request_customer_policy_snapshot');
        $snap=is_array($snapshot)?$snapshot:[];
        $rules=(array)($snap['rules']??[]);
        $mode=(string)($rules['mode']??'');
        $percent=(float)($rules['percent']??0);
        $days=(int)($rules['balance_due_days']??0);
        echo '<section class="mad4b-advance-agreement" aria-label="'.esc_attr__('Booking advance terms','commerce-composition').'">';
        echo '<h3>'.esc_html__('Your payment agreement','commerce-composition').'</h3>';
        echo '<p class="mad4b-advance-intro">'.esc_html__('Payment records your request and does not confirm a booking.','commerce-composition').'</p>';
        echo '<dl class="mad4b-advance-facts">';
        if($mode==='percent' && $percent>0) {
            echo '<div><dt>'.esc_html__('Advance due now','commerce-composition').'</dt><dd>'.esc_html(wc_format_decimal($percent,0)).'%</dd></div>';
        }
        if(($rules['balance_due_mode']??'')==='days_before_arrival' && $days>0) {
            $arrival=self::arrivalDateForOrder($o);
            if($arrival) {
                $due=$arrival->modify('-'.$days.' days');
                echo '<div><dt>'.esc_html__('Balance due','commerce-composition').'</dt>';
                echo '<dd><time datetime="'.esc_attr($due->format('Y-m-d')).'">'.esc_html(wp_date('F j, Y',$due->getTimestamp(),wp_timezone())).'</time></dd>';
                echo '<small class="mad4b-advance-due-context">'.esc_html(sprintf(__('%1$d days before arrival on %2$s','commerce-composition'),$days,wp_date('F j, Y',$arrival->getTimestamp(),wp_timezone()))).'</small>';
                echo '</div>';
            } else {
                echo '<div><dt>'.esc_html__('Balance due','commerce-composition').'</dt><dd>'.esc_html(sprintf(__('%d days before arrival','commerce-composition'),$days)).'</dd></div>';
            }
        }
        echo '</dl>';
        if(!empty($snap['html']) && is_string($snap['html'])) {
            $details=self::policyBody($snap['html']);
            echo '<details class="mad4b-advance-policy-details">';
            echo '<summary>'.esc_html__('Read the full booking, cancellation and refund policy','commerce-composition').'</summary>';
            echo '<div class="mad4b-advance-policy-content">'.$details.'</div>';
            echo '</details>';
        } else {
            // Legacy records with no HTML snapshot still need the actual terms.
            $plain=(string)$o->get_meta('_request_advance_terms');
            if($plain!=='') {
                echo '<details class="mad4b-advance-policy-details"><summary>'.esc_html__('Read the applicable terms','commerce-composition').'</summary><div class="mad4b-advance-policy-content">';
                echo wpautop(esc_html($plain));
                echo '</div></details>';
            }
        }
        echo '<label class="mad4b-advance-accept">';
        echo '<input type="checkbox" name="request_advance_accept" value="1" required />';
        echo '<span>'.esc_html__('I have reviewed and accept the booking, advance payment, cancellation and refund terms. I understand this payment does not confirm the trip.','commerce-composition').'</span>';
        echo '</label></section>';
    }
}
add_action('plugins_loaded',['MAD4B_Advance_Checkout_UI','boot'],100);
}
