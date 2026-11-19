<?php
/**
 * MAD4B Affected Tour Strategy — read-only price evidence and verified
 * Last Minute / FOMO preflight. Drafts can only be generated on explicit
 * admin action through the existing Shared Product Core event controller.
 */
defined('ABSPATH') || exit;
final class MAD4B_JetEngine_Affected_Tour_Strategy_V1 {
    public const ACTION='mad4b_jetengine_affected_tour_strategy_v1';
    public static function boot(): void {
        add_action('wp_ajax_'.self::ACTION,[__CLASS__,'ajax']);
    }
    private static function context(int $tour,int $rate,string $audience,
                                    string $occupancy,string $date) {
        if (!$tour || !$rate || get_post_type($tour)!=='tours-and-activities'
            || get_post_status($tour)!=='publish'
            || get_post_type($rate)!=='tour-rates'
            || get_post_status($rate)!=='publish'
            || (int)get_post_meta($rate,'related_tour_id',true)!==$tour) {
            return new WP_Error('affected_strategy_invalid_relation','Published Tour and Rate must be linked');
        }
        if (!in_array($audience,['general','egyptians'],true)
            || !in_array($occupancy,['single','double','triple'],true)) {
            return new WP_Error('affected_strategy_invalid_occupancy','Unsupported audience/occupancy');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)
            || !DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'))
            || DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'))->format('Y-m-d')!==$date) {
            return new WP_Error('affected_strategy_invalid_date','Select a valid travel date');
        }
        return ['tour_id'=>$tour,'rate_id'=>$rate,'travel_date'=>$date,
            'audience'=>$audience,'occupancy'=>$occupancy];
    }
    public static function preview(array $ctx): array {
        $tour=(int)$ctx['tour_id'];$rate=(int)$ctx['rate_id'];
        $price=function_exists('pricing_audience_quote')
            ? pricing_audience_quote($rate,$ctx['audience'],$ctx['occupancy'])
            : ['status'=>'unavailable','reason'=>'pricing_resolver_unavailable'];
        $validated=is_array($price) && ($price['status']??'')==='available';
        $regular=$validated?(float)$price['regular']:null;
        $final=$validated?(float)$price['final']:null;
        $diff=$validated?round($final-$regular,2):null;
        $rateFields=[
            'start'=>(int)get_post_meta($rate,'tour_rate_start_date',true),
            'end'=>(int)get_post_meta($rate,'tour_rate_end_date',true),
            'stopped'=>(string)get_post_meta($rate,'stop_sale',true)==='1',
        ];
        $event=class_exists('MAD4B_Last_Minute_Campaign_Events_V1')
            ? MAD4B_Last_Minute_Campaign_Events_V1::preview([
                'tour_id'=>$tour,'rate_id'=>$rate,
                'travel_date'=>$ctx['travel_date'],'program_term_id'=>0
            ]) : ['status'=>'last_minute_event_engine_missing'];
        // Do not expose the inventory payload itself here; reveal only
        // verifiable eligibility, reason and band for commercial review.
        $allowedStatus=['flag_active','balanced','outside_branch_window',
            'snapshot_unavailable','policy_disabled','rate_not_eligible_for_date',
            'stop_sale','invalid_policy','missing_shared_product_core','invalid_context',
            'program_not_linked','last_minute_event_engine_missing'];
        $status=(string)($event['status']??'unavailable');
        if(!in_array($status,$allowedStatus,true))$status='unavailable';
        $band=in_array(($event['band']??''),['scarce','abundant','balanced'],true)
            ? $event['band'] : null;
        $reason=sanitize_key((string)($event['reason']??''));
        $canDraft= $status==='flag_active'
            && ($event['candidate_enabled']??false)===true
            && $validated
            && $diff !== null;
        $policy=$event['policy']??[];
        $branch=$band && in_array($band,['scarce','abundant'],true) ? $band : null;
        $adjustment=$branch ? (string)($policy[$branch.'_adjustment']??'none') : 'none';
        $percent=$branch && is_numeric($policy[$branch.'_percent']??null)
            ?(float)$policy[$branch.'_percent'] : 0.0;
        $stackAllowed=!empty($policy['allow_stack']);
        $impactValid=$validated && $status==='flag_active'
            && !empty($event['snapshot']['eligible_for_dynamic_pricing'])
            && in_array($adjustment,['discount','markup'],true)
            && $percent>0 && $percent<=60
            && (!$price['discounted'] || $stackAllowed);
        $simulated=$impactValid
            ?round($final*($adjustment==='discount' ?(1-$percent/100):(1+$percent/100)),2)
            :null;
        $scenarioMode=(string)($ctx['scenario_mode']??'inherit');
        $scenarioPercent=(float)($ctx['scenario_percent']??0);
        $scenarioBlocked=$validated && !empty($price['discounted'])
            && $scenarioMode==='discount';
        $scenarioValid=$validated && !$scenarioBlocked
            && in_array($scenarioMode,['discount','markup'],true)
            && $scenarioPercent>0 && $scenarioPercent<=60;
        $scenarioAfter=$scenarioValid
            ?round($final*($scenarioMode==='discount'
                ?(1-$scenarioPercent/100):(1+$scenarioPercent/100)),2)
            :null;
        $sourceFields=[
            'regular'=>($ctx['audience']==='egyptians'?'egyptians_':'')
                .$ctx['occupancy'].'_price',
            'sale'=>($ctx['audience']==='egyptians'?'egyptians_':'')
                .$ctx['occupancy'].'_sale_price',
        ];
        return [
            'tour_id'=>$tour,'rate_id'=>$rate,
            'audience'=>$ctx['audience'],'occupancy'=>$ctx['occupancy'],
            'travel_date'=>$ctx['travel_date'],
            'source_price'=>[
                'status'=>$validated?'available':'unavailable',
                'reason'=>$validated?null:sanitize_key((string)($price['reason']??$price['status']??'unknown')),
                'regular'=>$regular,'current'=>$final,'delta'=>$diff,
                'currency'=>$validated?$price['source_currency']:null,
                'discounted'=>$validated?(bool)($price['discounted']??false):false,
                'regular_field'=>$sourceFields['regular'],
                'sale_field'=>$sourceFields['sale'],
            ],
            'rate'=>[
                'start'=>$rateFields['start']?gmdate('Y-m-d',$rateFields['start']):null,
                'end'=>$rateFields['end']?gmdate('Y-m-d',$rateFields['end']):null,
                'stop_sale'=>$rateFields['stopped']
            ],
            'what_if'=>[
                'status'=>$scenarioMode==='inherit'?'not_requested':
                    ($scenarioBlocked?'existing_sale_stacking_blocked':
                    ($scenarioValid?'illustrative_only':'not_calculable')),
                'mode'=>$scenarioMode,'percent'=>$scenarioPercent,
                'before'=>$scenarioValid?$final:null,
                'after'=>$scenarioAfter,
                'delta'=>$scenarioValid?round($scenarioAfter-$final,2):null,
                'currency'=>$validated?$price['source_currency']:null,
                'not_a_price_quote'=>true,'writes'=>0,
                'market_or_booking_approval'=>false,
            ],
            'policy_impact'=>[
                'status'=>$impactValid?'illustrative_with_verified_inventory'
                    :'not_authorized_for_quote',
                'adjustment'=>$branch?$adjustment:'none',
                'percent'=>$branch?$percent:null,
                'reference_before'=>$impactValid?$final:null,
                'illustrative_after'=>$simulated,
                'illustrative_delta'=>$impactValid?round($simulated-$final,2):null,
                'currency'=>$validated?$price['source_currency']:null,
                'stack_allowed'=>$stackAllowed,
                'not_a_price_quote'=>true,
            ],
            'fomo'=>[
                'status'=>$status,'reason'=>$reason,
                'band'=>$band,
                'verified_inventory'=>in_array($status,['flag_active','balanced','outside_branch_window'],true)
                    && !empty($event['snapshot']['eligible_for_dynamic_pricing']),
                'draft_candidate_allowed'=>$canDraft,
                'flag_from'=>isset($event['flag_window']['flag_opens_on'])
                    ?(string)$event['flag_window']['flag_opens_on']:null,
                'flag_until'=>isset($event['flag_window']['flag_closes_on'])
                    ?(string)$event['flag_window']['flag_closes_on']:null,
                'policy_scope'=>sanitize_key((string)($event['policy_scope']??'none'))
            ],
            'offers'=>[
                'existing_source_discount'=>$validated && !empty($price['discounted']),
                'native_campaign_type_ready'=>post_type_exists('offer-campaign'),
                'native_offer_type'=>'offer-campaign',
                'campaign_review_required'=>true
            ],
            'preview_only'=>true,
            'writes'=>0,
            'checkout_ready'=>false,
            'authority'=>'shared_product_core_current_source_plus_last_minute_event_preview'
        ];
    }
    public static function ajax(): void {
        if(!MAD4B_JetEngine_Pricing_Controls_Access_V1::authorized())
            wp_send_json_error(['message'=>'Not authorized'],403);
        check_ajax_referer(self::ACTION,'nonce');
        $op=isset($_POST['operation'])&&is_string($_POST['operation'])
            ?sanitize_key(wp_unslash($_POST['operation'])):'preview';
        $scenarioMode=isset($_POST['scenario_mode'])&&is_string($_POST['scenario_mode'])
            ?sanitize_key(wp_unslash($_POST['scenario_mode'])):'inherit';
        $scenarioPercent=isset($_POST['scenario_percent'])&&is_scalar($_POST['scenario_percent'])
            ?(string)wp_unslash($_POST['scenario_percent']):'0';
        if(!in_array($scenarioMode,['inherit','discount','markup'],true)
            || !preg_match('/^(?:0|[1-9][0-9]?)(?:\.[0-9]{1,2})?$/D',$scenarioPercent)
            || (float)$scenarioPercent>60){
            wp_send_json_error(['message'=>'Invalid What-if scenario'],400);
        }
        $ctx=self::context(
            absint($_POST['tour_id']??0),absint($_POST['rate_id']??0),
            sanitize_key((string)($_POST['audience']??'')),
            sanitize_key((string)($_POST['occupancy']??'')),
            sanitize_text_field((string)($_POST['travel_date']??''))
        );
        if(is_wp_error($ctx))wp_send_json_error([
            'code'=>$ctx->get_error_code(),
            'message'=>$ctx->get_error_message()
        ],400);
        $ctx['scenario_mode']=$scenarioMode;
        $ctx['scenario_percent']=(float)$scenarioPercent;
        if($op==='preview'){
            wp_send_json_success(self::preview($ctx));
        }
        if($op!=='draft_fomo')wp_send_json_error(['message'=>'Unknown operation'],400);
        // Explicit administrator opt-in is mandatory. Never create an
        // inventory-triggered campaign from an ordinary preview or frontend hit.
        if((string)($_POST['confirm']??'')!=='create_review_draft')
            wp_send_json_error(['message'=>'Explicit draft confirmation required'],400);
        $current=self::preview($ctx);
        if(!$current['fomo']['draft_candidate_allowed']
           || !$current['fomo']['verified_inventory']) {
            wp_send_json_error([
                'message'=>'Live inventory and policy do not qualify for an FOMO draft',
                'code'=>'no_verified_fomo_evidence'
            ],409);
        }
        if(!class_exists('MAD4B_Last_Minute_Campaign_Events_V1')){
            wp_send_json_error(['message'=>'Campaign Event Controller unavailable'],503);
        }
        $receipt=MAD4B_Last_Minute_Campaign_Events_V1::handle([
            'tour_id'=>$ctx['tour_id'],'rate_id'=>$ctx['rate_id'],
            'travel_date'=>$ctx['travel_date'],'program_term_id'=>0
        ]);
        $campaign=absint($receipt['campaign_id']??0);
        if($campaign && (get_post_type($campaign)!=='offer-campaign'
            || get_post_status($campaign)!=='draft')) {
            wp_send_json_error(['message'=>'Draft confirmation failed'],409);
        }
        wp_send_json_success([
            'receipt'=>$receipt,'campaign_id'=>$campaign,
            'campaign_draft'=>!!$campaign,
            'campaign_url'=>$campaign?get_edit_post_link($campaign,'raw'):null,
            'published'=>false, 'checkout_authorized'=>false,
        ]);
    }
}
