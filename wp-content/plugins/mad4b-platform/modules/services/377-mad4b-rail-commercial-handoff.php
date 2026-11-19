<?php
/**
 * Plugin Name: MAD4B Rail Commercial Handoff
 * Version: 0.1.0
 * Description: Verified supplier evidence to existing Transport Rate drafts; WC checkout remains fail-closed without market-specific operator inventory authority.
 */
defined('ABSPATH') || exit;

final class MAD4B_Rail_Commercial_Handoff_V1 {
    const CONTRACT='mad4b.rail-commercial-handoff.v1';
    const SOURCE='mad4b_rail_supplier_quote_proofs_v1';
    const VERIFIED='VERIFIED_PROVIDER_QUOTE_READY_FOR_RATE_DRAFT';
    const DRAFT='DRAFT_CREATED_PENDING_FINANCE_AND_MARKET_AUTHORITY';

    static function allowed(): bool {
        return class_exists('MAD4B_Rail_Fare_Quote_Intake_V1')
            && class_exists('MAD4B_Rail_Supplier_Proof_V1')
            && class_exists('MAD4B_Rail_Woo_Settings_V1')
            && class_exists('Shared_Product_Rate_Generation')
            && MAD4B_Rail_Fare_Quote_Intake_V1::can_write()
            && current_user_can('manage_woocommerce');
    }

    static function valid_currency(string $code): bool {
        return preg_match('/^[A-Z]{3}$/D',$code)===1 &&
            function_exists('get_woocommerce_currencies') &&
            array_key_exists($code,get_woocommerce_currencies());
    }

    static function proof_preflight(array $proof) {
        if(($proof['contract']??'')!==MAD4B_Rail_Supplier_Proof_V1::CONTRACT)
            return new WP_Error('rail_proof_contract_invalid');
        $id=absint($proof['transport_id']??0);
        $cfg=MAD4B_Rail_Fare_Quote_Intake_V1::config($id);
        if(is_wp_error($cfg))return $cfg;
        $requestKey=(string)($proof['request_key']??'');
        $reqs=(array)get_option(MAD4B_Rail_Fare_Quote_Intake_V1::STORE,[]);
        $req=(array)($reqs[$requestKey]??[]);
        $s=(array)($req['snapshot']??[]);
        if(!$s||($req['contract']??'')!==MAD4B_Rail_Fare_Quote_Intake_V1::CONTRACT||
           in_array(($req['status']??''),['STALE_SOURCE_ROUTE','CANCELLED'],true)||
           ($req['source_sha256']??'')!==($proof['source_sha256']??'')||
           ($proof['request_snapshot']??[])!==$s)
            return new WP_Error('rail_proof_request_stale_or_missing');
        if(($s['site_uuid']??'')!==$cfg['site_uuid']||
           ($proof['site_uuid']??'')!==$cfg['site_uuid']||
           ($proof['issue_id']??'')!==$cfg['issue_id']||
           ($proof['job_id']??'')!==$cfg['content_job_id']||
           (int)($s['transport_id']??0)!==$id||
           (string)($s['train_number']??'')!==$cfg['train_number']||
           (int)($s['from_station_id']??0)!==(int)$cfg['from_station_id']||
           (int)($s['to_station_id']??0)!==(int)$cfg['to_station_id']||
           ($s['provider']??'')!==$cfg['provider'])
            return new WP_Error('rail_supplier_context_mismatch');
        if(!in_array(($s['service_class']??''),$cfg['classes'],true)||
           !in_array(($s['market']??''),$cfg['markets'],true)||
           !MAD4B_Rail_Fare_Quote_Intake_V1::date_valid((string)($s['travel_date']??'')))
            return new WP_Error('rail_supplier_market_class_or_date_invalid');
        $currency=strtoupper((string)($proof['supplier_currency']??''));
        $amount=(string)($proof['supplier_claimed_amount']??'');
        if(!self::valid_currency($currency)||
           !preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D',$amount)||
           (float)$amount<=0)
            return new WP_Error('rail_supplier_price_invalid');
        if(!MAD4B_Rail_Supplier_Proof_V1::allowed_source(
             (string)($proof['source_url']??''),$cfg['provider']))
            return new WP_Error('rail_supplier_official_source_unverified');
        try {
            $expires=new DateTimeImmutable((string)($proof['expires_at']??''));
        }catch(Throwable $e){return new WP_Error('rail_supplier_quote_expiry_invalid');}
        if($expires<=new DateTimeImmutable('now',wp_timezone()))
            return new WP_Error('rail_supplier_quote_expired');
        $woo=MAD4B_Rail_Woo_Settings_V1::evaluate($amount,$currency,false,false);
        // The draft preserves original supplier currency. If different from
        // WC base currency, the FX handoff stays blocked at sale/checkout.
        // Do not silently convert supplier costs to WooCommerce USD.
        $sourceLedger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
        $issue=$sourceLedger[$cfg['issue_id']]??[];
        if(($issue['status']??'')!=='open'||
           ($issue['content_job_id']??'')!==$cfg['content_job_id']||
           !in_array((string)($proof['proof_id']??''),(array)($issue['geo_research']['supplier_proof_ids']??[]),true))
            return new WP_Error('rail_supplier_alert_link_missing');
        $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$cfg['content_job_id']]);
        if(is_wp_error($job)||($job['job']['state']??'')!=='WAITING_REVIEW')
            return new WP_Error('rail_supplier_job_not_reviewable');
        return ['config'=>$cfg,'snapshot'=>$s,'amount'=>$amount,
            'currency'=>$currency,'expires_at'=>$expires,'woo'=>$woo];
    }

    static function verify_supplier(array $proof,array $context) {
        // An untrusted submitted URL or operator-entered price cannot verify itself.
        // Only an installed, authenticated first-party provider adapter can return
        // the complete supplier-owned receipt. No adapter = no financial writes.
        $result=apply_filters('mad4b/rail/verified_provider_quote_v1',
            new WP_Error('rail_authenticated_supplier_adapter_not_connected'),
            $proof,$context);
        if(is_wp_error($result))return $result;
        if(!is_array($result))return new WP_Error('rail_provider_receipt_invalid');
        $s=$context['snapshot'];
        $cfg=$context['config'];
        $expected=[
            'source_trust'=>'authenticated_supplier_api',
            'supplier'=>$cfg['provider'],
            'supplier_quote_reference'=>(string)$proof['operator_reference'],
            'train_number'=>$cfg['train_number'],
            'from_station_id'=>(int)$cfg['from_station_id'],
            'to_station_id'=>(int)$cfg['to_station_id'],
            'travel_date'=>$s['travel_date'],
            'market'=>$s['market'],
            'service_class'=>$s['service_class'],
            'supplier_currency'=>$context['currency'],
            'supplier_amount'=>$context['amount'],
            'availability'=>'confirmed',
            'site_uuid'=>$cfg['site_uuid'],
        ];
        foreach($expected as $k=>$v)if(!array_key_exists($k,$result) ||
            (string)$result[$k] !== (string)$v)
            return new WP_Error('rail_provider_receipt_context_or_amount_mismatch',['field'=>$k]);
        if(!isset($result['provider_receipt_id'])||
           !is_string($result['provider_receipt_id'])||
           strlen($result['provider_receipt_id'])<8)
            return new WP_Error('rail_provider_receipt_identity_missing');
        $exp=isset($result['expires_at'])?(string)$result['expires_at']:'';
        try{$receiptExpires=new DateTimeImmutable($exp);}catch(Throwable $e){
            return new WP_Error('rail_provider_receipt_expiry_invalid');}
        if($receiptExpires<=new DateTimeImmutable('now',wp_timezone()) ||
           $receiptExpires>$context['expires_at'])
            return new WP_Error('rail_provider_receipt_not_current');
        // A supplier selling price is not necessarily our NET COST. We only
        // feed the existing native cost/margin generator when this is explicit.
        if(($result['amount_basis']??'')!=='net_supplier_cost_per_unit' ||
           ($result['net_cost_basis_verified']??null)!==true ||
           !in_array(($result['price_unit']??''),['per_person','per_cabin'],true) ||
           !in_array(($result['tax_disposition']??''),[
               'supplier_cost_includes_supplier_taxes',
               'supplier_cost_excludes_supplier_taxes'],true))
            return new WP_Error('rail_supplier_net_cost_basis_unverified');
        // Final authority is still restricted to draft generation.
        return [
            'price_unit'=>$result['price_unit'],
            'tax_disposition'=>$result['tax_disposition'],
            'provider_receipt_id'=>$result['provider_receipt_id'],
            'provider_receipt_sha256'=>hash('sha256',wp_json_encode($result)),
            'supplier_expiration'=>$receiptExpires->format(DATE_ATOM),
            'source_trust'=>'authenticated_supplier_api',
            'supplier_inventory_verified'=>true,
            'rate_publication_authorized'=>false,
            'woo_checkout_authorized'=>false
        ];
    }

    static function inspect(string $proofId) {
        $all=(array)get_option(self::SOURCE,[]);
        $p=$all[$proofId]??null;
        if(!is_array($p))return new WP_Error('rail_supplier_proof_not_found');
        $pre=self::proof_preflight($p);
        if(is_wp_error($pre))return $pre;
        $verified=self::verify_supplier($p,$pre);
        return [
            'proof_id'=>$proofId,'transport_id'=>$p['transport_id'],
            'preflight_passed'=>true,
            'provider_verification_status'=>is_wp_error($verified)?$verified->get_error_code():'VERIFIED',
            'supplier_expiry'=>$p['expires_at'],
            'proposed_amount'=>$pre['amount'],
            'original_currency'=>$pre['currency'],
            'can_generate_draft'=>!is_wp_error($verified),
            'can_publish_rate'=>false,'can_create_woo_order'=>false,
            'review_notes'=>'Supplier proof, native pricing, market-class routing and Woo checkout authority are independent.'
        ];
    }

    static function reconcile(int $max=6) {
        if(!self::allowed())return new WP_Error('rail_commercial_runtime_write_forbidden');
        $max=max(1,min(20,$max));
        $proofs=(array)get_option(self::SOURCE,[]);
        $report=['scanned'=>0,'draft_created'=>0,'draft_reused'=>0,
            'not_ready'=>[],'errors'=>[],'published'=>0,'woocommerce_orders_created'=>0];
        foreach($proofs as $proofId=>$p) {
            if(++$report['scanned']>$max)break;
            if(!is_array($p))continue;
            if(!empty($p['handoff_rate_id']))continue;
            $pre=self::proof_preflight($p);
            if(is_wp_error($pre)){
                $report['not_ready'][]=['proof_id'=>$proofId,'reason'=>$pre->get_error_code()];
                continue;
            }
            $verified=self::verify_supplier($p,$pre);
            if(is_wp_error($verified)){
                $report['not_ready'][]=['proof_id'=>$proofId,'reason'=>$verified->get_error_code()];
                continue;
            }
            $snap=$pre['snapshot'];
            // The source quote amount is supplier NET COST, not automatically
            // our retail fare. Use the native Shared Product Core cost engine.
            $unit=$verified['price_unit'];
            $policy=MAD4B_Rail_Woo_Settings_V1::policy();
            if(($policy['supplier_pricing_rule_kind']??'')==='not_configured'||
               (float)($policy['supplier_pricing_rule_value']??0)<=0){
                $report['not_ready'][]=['proof_id'=>$proofId,'reason'=>'rail_woocommerce_margin_policy_required'];
                continue;
            }
            $input=[
                'kind'=>'transport','parent_id'=>(int)$snap['transport_id'],
                'supplier_sku'=>'RAIL-'.substr($proofId,0,20),
                'supplier_cost'=>$pre['amount'],'currency'=>$pre['currency'],
                'pricing_rule_kind'=>$policy['supplier_pricing_rule_kind'],
                'pricing_rule_value'=>$policy['supplier_pricing_rule_value'],
                'price_unit'=>$unit,'valid_from'=>$snap['travel_date'],
                'valid_to'=>$snap['travel_date'],'rate_scope'=>'tour_component'
            ];
            $plan=Shared_Product_Rate_Generation::prepare($input);
            if(is_wp_error($plan)){
                $report['errors'][]=['proof_id'=>$proofId,'reason'=>$plan->get_error_code()];
                continue;
            }
            $overlap=Shared_Product_Rate_Generation::overlap_review($plan);
            if(is_wp_error($overlap)||!empty($overlap['has_published_overlap'])){
                $report['not_ready'][]=['proof_id'=>$proofId,
                    'reason'=>'rail_rate_overlapping_or_unreviewable'];
                continue;
            }
            $rate=Shared_Product_Rate_Generation::create($input);
            if(is_wp_error($rate)){
                $report['errors'][]=['proof_id'=>$proofId,'reason'=>$rate->get_error_code()];
                continue;
            }
            $rateId=absint($rate['post_id']);
            if(get_post_type($rateId)!=='transport-rates'||
               get_post_status($rateId)!=='draft'||
               (int)get_post_meta($rateId,'source_post_id',true)!==(int)$snap['transport_id']||
               (string)get_post_meta($rateId,'stop_sale',true)!=='true'){
                $report['errors'][]=['proof_id'=>$proofId,'reason'=>'rail_draft_readback_failed'];
                continue;
            }
            $context=[
                'contract'=>self::CONTRACT,'proof_id'=>$proofId,
                'request_key'=>$p['request_key'],
                'source_sha256'=>$p['source_sha256'],
                'provider_receipt_id'=>$verified['provider_receipt_id'],
                'provider_receipt_sha256'=>$verified['provider_receipt_sha256'],
                'market'=>$snap['market'],'service_class'=>$snap['service_class'],
                'travel_date'=>$snap['travel_date'],
                'supplier_expiration'=>$verified['supplier_expiration'],
                'price_unit'=>$unit,
                'supplier_net_cost'=>$pre['amount'],
                'original_currency'=>$pre['currency'],
                'pricing_rule_kind'=>$policy['supplier_pricing_rule_kind'],
                'pricing_rule_value'=>$policy['supplier_pricing_rule_value'],
                'supplier_tax_disposition'=>$verified['tax_disposition'],
                'requires_fx_for_woo_checkout'=>!empty($pre['woo']['exchange_quote_needed']),
                'status'=>self::DRAFT,
                'market_class_selector_verified'=>false,
                'finance_approved'=>false,
                'rate_publication_authorized'=>false,
                'woo_checkout_authorized'=>false
            ];
            update_post_meta($rateId,'_mad4b_rail_supplier_context_v1',$context);
            $read=get_post_meta($rateId,'_mad4b_rail_supplier_context_v1',true);
            if(($read['proof_id']??'')!==$proofId ||
               get_post_status($rateId)!=='draft'){
                $report['errors'][]=['proof_id'=>$proofId,'reason'=>'rail_context_readback_failed'];
                continue;
            }
            $ledger=(array)get_option(self::SOURCE,[]);
            if(!isset($ledger[$proofId]) ||
               ($ledger[$proofId]['source_sha256']??'')!==$p['source_sha256'])
                return new WP_Error('rail_proof_changed_during_handoff');
            $ledger[$proofId]['status']=self::DRAFT;
            $ledger[$proofId]['handoff_rate_id']=$rateId;
            $ledger[$proofId]['provider_verification_receipt']=$verified;
            update_option(self::SOURCE,$ledger,false);
            $readback=(array)get_option(self::SOURCE,[]);
            if((int)($readback[$proofId]['handoff_rate_id']??0)!==$rateId){
                $report['errors'][]=['proof_id'=>$proofId,'reason'=>'rail_proof_handoff_readback_failed'];
                continue;
            }
            $report[($rate['status']??'')==='created'?'draft_created':'draft_reused']++;
            if(class_exists('MAD4B_SCP_Audit'))
                MAD4B_SCP_Audit::record('mad4b/rail-verified-proof-to-draft',[
                  'site_uuid'=>$p['site_uuid'],'proof_id'=>$proofId,
                  'rate_id'=>$rateId,'rate_published'=>false,
                  'woo_order_created'=>false
                ],'ok');
        }
        return $report;
    }

    static function summary(): array {
        $proofs=(array)get_option(self::SOURCE,[]);
        $status=[];$drafts=[];
        foreach($proofs as $p){
            $st=(string)($p['status']??'UNKNOWN');
            $status[$st]=($status[$st]??0)+1;
            if(!empty($p['handoff_rate_id']))$drafts[]=(int)$p['handoff_rate_id'];
        }
        return [
            'contract'=>self::CONTRACT,'site'=>home_url(),
            'evidence_count'=>count($proofs),'evidence_states'=>$status,
            'linked_draft_rate_ids'=>$drafts,
            'authenticated_supplier_adapter_required'=>true,
            'authenticated_supplier_adapter_connected'=>has_filter('mad4b/rail/verified_provider_quote_v1')!==false,
            'native_supplier_cost_engine_connected'=>is_callable(apply_filters('booking_experience_supplier_pricing_callback',null)),
            'margin_policy_configured'=>MAD4B_Rail_Woo_Settings_V1::policy()['supplier_pricing_rule_kind']!=='not_configured',
            'market_and_class_native_selector_ready'=>false,
            'automated_rate_publication_enabled'=>false,
            'automated_wc_checkout_enabled'=>false,
            'inventory_confirmation_not_inferred'=>true
        ];
    }

    static function woo_settings() {
        if(!current_user_can('manage_woocommerce'))return;
        $s=self::summary();
        echo '<hr><h3>Rail Commercial Handoff</h3>';
        echo '<p>Provider verification → native transport Rate Draft → separate commercial review. An uploaded proof alone cannot publish fares or create a WooCommerce Order.</p>';
        echo '<p>Submitted proof records: <strong>'.(int)$s['evidence_count'].
            '</strong>. Linked draft Rates: <strong>'.count($s['linked_draft_rate_ids']).'</strong>.</p>';
        echo '<p>Supplier verifier: <strong>'.($s['authenticated_supplier_adapter_connected']?'Connected':'Not connected').'</strong> · ';
        echo 'Cost/margin engine: <strong>'.($s['native_supplier_cost_engine_connected']?'Connected':'Missing').'</strong> · ';
        echo 'Markup/margin policy: <strong>'.($s['margin_policy_configured']?'Configured':'Not configured').'</strong>.</p>';
        echo '<p>Automatic publication: <strong>blocked until native market/class selection and signed supplier stock are certified</strong>.</p>';
        echo '<table class="widefat striped" style="max-width:970px"><thead><tr><th>Train</th><th>Supplier</th><th>State</th><th>Action</th></tr></thead><tbody>';
        foreach(MAD4B_Rail_Fare_Quote_Intake_V1::discover() as $c){
            echo '<tr><td>'.esc_html($c['train_number'].' — '.$c['title']).'</td>';
            echo '<td>'.esc_html(strtoupper($c['provider'])).'</td>';
            echo '<td>'.esc_html($s['evidence_count']===0?'Awaiting dated supplier evidence':'Supplier verification / market review').'</td>';
            echo '<td><a href="'.esc_url(admin_url('tools.php?page=mad4b-rail-fares')).'">Request source quote</a></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-rail-proofs')).'">Review supplier evidence</a></p>';
    }
    static function cli($args) {
        $op=$args[0]??'status';
        if($op==='status')$out=self::summary();
        elseif($op==='reconcile')$out=self::reconcile(6);
        else WP_CLI::error('Use status or reconcile');
        if(is_wp_error($out))WP_CLI::error($out->get_error_code());
        WP_CLI::line(wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
}
add_action('woocommerce_settings_tabs_booking_experience',
    ['MAD4B_Rail_Commercial_Handoff_V1','woo_settings'],80);
if(defined('WP_CLI')&&WP_CLI)
    WP_CLI::add_command('mad4b rail-commercial',
        ['MAD4B_Rail_Commercial_Handoff_V1','cli']);
