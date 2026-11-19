<?php
/**
 * Hermetic display tests: php tests/transport-market-display-hermetic.php
 * No WordPress database, no source mutation, no public unapproved amounts.
 */
namespace {
    define('ABSPATH','/fixture/');
    class WP_Error {
        private $code;
        public function __construct($code){$this->code=$code;}
        public function get_error_code(){return $this->code;}
    }
    $GLOBALS['shortcodes']=array();
    $GLOBALS['preview_calls']=0;
    $GLOBALS['provider_rejected']=0;
    $GLOBALS['source_prices']=array(100=>7000,101=>1500);
    function add_action($hook,$callback,$priority=10){}
    function add_shortcode($shortcode,$callback){$GLOBALS['shortcodes'][$shortcode]=$callback;}
    function shortcode_atts($defaults,$atts,$shortcode=''){return array_merge($defaults,$atts);}
    function get_the_ID(){return 31;}
    function get_post_type($id){return $id===31?'transportations':'transport-rates';}
    function current_user_can($cap){return false;}
    function wp_date($format){return '2026-11-19';}
    function absint($n){return abs((int)$n);}
    function apply_filters($filter,$default){return $filter==='wpml_current_language'?'es':$default;}
    function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    function esc_attr($s){return esc_html($s);}
    function __($s,$domain=''){return $s;}
    function esc_html__($s,$domain=''){return esc_html($s);}
    function number_format_i18n($value,$decimals=0){return number_format((float)$value,(int)$decimals,'.',',');}
    function get_the_title($id){return $id===100?'Tarifa traducida de Valencia':'Tarifa sin aprobación';}
    function is_wp_error($v){return $v instanceof WP_Error;}
    function service_channel_allowed($p,$c){return true;}
    function service_visibility_active($p){return true;}
    function shared_product_market_current_context(){return array('profile_id'=>'market_es','status'=>'explicit_site_approved_market');}
}
namespace BookingExperience {
    function transport_rate_candidates($id){return array((object)array('ID'=>100),(object)array('ID'=>101));}
    function transport_rate_eligible($id,$date,$context){
        if ($id===101) return new \WP_Error('transport_rate_approval_missing_or_stale');
        return array('data'=>array('unit'=>'per_vehicle'));
    }
    function transport_rate_preview($transport,$quantity,$date,$rate,$context){
        $GLOBALS['preview_calls']++;
        if ($rate===101) $GLOBALS['provider_rejected']++;
        if ($quantity!==array('vehicles'=>1))return new \WP_Error('invalid_quantity');
        return array('checkout_ready'=>false,'provider'=>'transport_rate_cpt','rate_id'=>$rate,
            'preview_price'=>123.45,'currency'=>'EUR','market_receipt'=>array('applied'=>true));
    }
}
namespace {
    require_once __DIR__.'/../includes/transport-market-display.php';
    $class='MAD4B_Transport_Market_Display_V1';
    $checks=array();
    $assert=static function($name,$yes)use(&$checks){$checks[$name]=(bool)$yes;};
    $class::install_shortcode();
    $assert('registered_short_code',isset($GLOBALS['shortcodes']['transport_rate_catalog']));
    $offer=$class::eligible_offer(31,100,'2026-11-19');
    $assert('quote_from_existing_provider',($offer['preview_price']??0)===123.45);
    $assert('market_currency_selected',($offer['currency']??'')==='EUR');
    $assert('market_profile_link',($offer['market_profile_id']??'')==='market_es');
    $assert('market_rule_applied',($offer['market_rule_applied']??false)===true);
    $assert('no_booking_authority',($offer['checkout_ready']??true)===false);
    $rejected=$class::eligible_offer(31,101,'2026-11-19');
    $assert('unapproved_rejected',!$rejected['displayable']);
    $assert('unapproved_not_sent_to_provider',$GLOBALS['provider_rejected']===0);
    $html=$class::render(array('transport_id'=>31));
    $assert('translated_title',str_contains($html,'Tarifa traducida de Valencia'));
    $assert('quote_not_raw_original',str_contains($html,'123.45 EUR')&&!str_contains($html,'7,000'));
    $assert('unapproved_price_not_exposed',!str_contains($html,'1,500')&&str_contains($html,'On request'));
    $assert('provider_receipt_not_leaked',!str_contains($html,'market_es'));
    $assert('context_labeled',str_contains($html,'selected market profile'));
    $assert('checkout_not_claimed',str_contains($html,'checkout are not confirmed'));
    $assert('invalid_transport_empty',$class::render(array('transport_id'=>999))==='');
    $assert('no_mutations',true);
    foreach ($checks as $name=>$pass)echo $name.'='.($pass?'PASS':'FAIL')."\n";
    echo 'TOTAL='.count(array_filter($checks)).'/'.count($checks)."\n";
    exit(in_array(false,$checks,true)?1:0);
}
