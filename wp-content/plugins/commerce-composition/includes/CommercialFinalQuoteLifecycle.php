<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * QA45 commercial final quote issuer.
 * All monetary and hold values originate from independently verified source
 * receipts, never preview totals or client submissions. Zero mutation until
 * policy, source financial mode, and all 4 authoritative receipts pass.
 */
final class CommercialFinalQuoteLifecycle
{
    private const NS = 'commerce-composition/v1';
    private const CONTRACT = 'commerce.immutable-final-quote.v1';
    private const STORE = 'commerce_final_quote_qa45_';
    private const APPROVAL = 'commerce_final_quote_approval_qa45_';
    private const MAX_MINOR = 1000000000000;

    private static function error(string $reason, int $status = 409): \WP_Error
    {
        return new \WP_Error($reason, 'Final Quote not authorized', ['status' => $status]);
    }

    private static function goodMoney($amount, bool $allowZero = true): bool
    {
        return is_int($amount) && $amount >= ($allowZero ? 0 : 1)
            && $amount <= self::MAX_MINOR;
    }

    private static function sha($x): bool
    {
        return is_string($x) && (bool)preg_match('/^[a-f0-9]{64}$/D', $x);
    }

    /**
     * Pure economics and reference reconciliation, for real source receipts
     * after QA44 source-owned read+verify. Not itself an evidence verifier.
     */
    public static function reconcile(array $claims, array $binding): array|\WP_Error
    {
        foreach (['supplier_cost','tax','availability','final_quote'] as $kind) {
            if (!is_array($claims[$kind] ?? null)
                || !self::sha($claims[$kind]['receipt_digest'] ?? null)
                || !is_string($claims[$kind]['provider_id'] ?? null)
                || !is_string($claims[$kind]['receipt_id'] ?? null)
                || !is_string($claims[$kind]['expires_at_utc'] ?? null))
                return self::error('final_quote_source_receipt_missing');
        }
        $cost=$claims['supplier_cost']; $tax=$claims['tax'];
        $availability=$claims['availability']; $quote=$claims['final_quote'];
        $currency=$quote['currency']??null;
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)
            || ($cost['currency']??null)!==$currency
            || ($tax['currency']??null)!==$currency)
            return self::error('final_quote_source_currency_mismatch');

        foreach ([
            [$cost,'amount_minor',true],[$tax,'amount_minor',true],
            [$quote,'amount_minor',false],[$quote,'supplier_cost_minor',true],
            [$quote,'tax_minor',true],[$quote,'margin_minor',true],
            [$quote,'fee_minor',true],[$quote,'discount_minor',true]
        ] as [$value,$field,$allowZero]) {
            if (!self::goodMoney($value[$field]??null,$allowZero))
                return self::error('final_quote_source_amount_unverified');
        }
        if ($quote['supplier_cost_minor']!==$cost['amount_minor']
            ||$quote['tax_minor']!==$tax['amount_minor'])
            return self::error('final_quote_cost_tax_not_reconciled');
        $expected=$cost['amount_minor'] + $tax['amount_minor']
            + $quote['margin_minor'] + $quote['fee_minor'] - $quote['discount_minor'];
        if ($expected!==$quote['amount_minor'] || !self::goodMoney($expected,false))
            return self::error('final_quote_total_not_reconciled');

        $references=$quote['reference_digests']??null;
        if (!is_array($references) || count($references)!==3
            ||count(array_intersect_key($references,array_flip(
                ['supplier_cost','tax','availability'])))!==3)
            return self::error('final_quote_reference_set_invalid');
        foreach ($references as $kind=>$digest) {
            if (!self::sha($digest) || !hash_equals($claims[$kind]['receipt_digest'],$digest))
                return self::error('final_quote_reference_mismatch');
        }
        if (!is_string($availability['hold_reference']??null)
            || !preg_match('/^[A-Za-z0-9:_-]{8,128}$/D',$availability['hold_reference']))
            return self::error('final_quote_confirmed_hold_missing');
        // Currency and quantities cannot be sourced from preview. Expiry must
        // be bounded by each independently validated receipt.
        $expiry=null; $now=time();
        foreach (['supplier_cost','tax','availability','final_quote'] as $kind) {
            $end=strtotime($claims[$kind]['expires_at_utc']);
            if ($end===false || $end<=$now+60)
                return self::error('final_quote_expiry_not_safe');
            $expiry=$expiry===null?$end:min($expiry,$end);
        }
        if (!is_string($binding['site_uuid']??null)
            ||$binding['site_uuid']===''
            ||!self::sha($binding['policy_digest']??null)
            ||!self::sha($binding['plan_digest']??null)
            ||!is_int($binding['woo_product_id']??null)
            ||!is_int($binding['policy_revision']??null))
            return self::error('final_quote_binding_untrusted');
        // Fix canonical order and avoid array iteration dependence.
        $receiptDigests=[
            'supplier_cost'=>$cost['receipt_digest'],
            'tax'=>$tax['receipt_digest'],
            'availability'=>$availability['receipt_digest'],
            'final_quote'=>$quote['receipt_digest']
        ];
        $record=[
            'contract'=>self::CONTRACT,
            'site_uuid'=>$binding['site_uuid'],
            'policy_revision'=>$binding['policy_revision'],
            'policy_digest'=>$binding['policy_digest'],
            'plan_digest'=>$binding['plan_digest'],
            'woo_product_id'=>$binding['woo_product_id'],
            'service_id'=>$binding['service_id']??null,
            'rate_id'=>$binding['rate_id']??null,
            'sale_role'=>$binding['sale_role']??null,
            'currency'=>$currency,
            'total_minor'=>$quote['amount_minor'],
            'supplier_cost_minor'=>$cost['amount_minor'],
            'tax_minor'=>$tax['amount_minor'],
            'margin_minor'=>$quote['margin_minor'],
            'fee_minor'=>$quote['fee_minor'],
            'discount_minor'=>$quote['discount_minor'],
            'provider_hold_reference'=>$availability['hold_reference'],
            'provider_receipt_digests'=>$receiptDigests,
            'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$expiry),
        ];
        $record['quote_digest']=hash('sha256',wp_json_encode($record,JSON_UNESCAPED_SLASHES));
        return $record;
    }

    public static function evaluate(int $sourceId,array $configuration,string $role,
        string $market='',string $channel='web'): array|\WP_Error
    {
        $probe=CommercialQuoteEvidenceProbe::inspectForIssuer(
            $sourceId,$configuration,$role,$market,$channel);
        if (is_wp_error($probe)) return $probe;
        $line=$probe['selected_sale_line'];
        $financial=null;
        if (function_exists('shared_product_financial_readiness_v2')
            &&get_post_type($line['service_id'])==='tours-and-activities') {
            $financial=shared_product_financial_readiness_v2($line['service_id'],$line['rate_id']);
        }
        $financialApproved=is_array($financial)
            && ($financial['checkout_ready']??false)===true
            && empty($financial['blockers']);
        $policyActive=$probe['company_policy_status']==='active';
        $siteContext=CompanyCommercePolicy::context();
        if (is_wp_error($siteContext)) return $siteContext;
        $authorityReady=($siteContext['site_write_ready']??false)===true;
        $claims=$probe['source_verified_claims'];
        $missing=[];
        foreach (['supplier_cost','tax','availability','final_quote'] as $kind) {
            if (!isset($claims[$kind]))$missing[]='source_receipt_missing:'.$kind;
        }
        if (!$policyActive)$missing[]='company_policy_not_active';
        if (!$financialApproved)$missing[]='native_source_financial_policy_not_approved';
        if (!$authorityReady)$missing[]='site_write_authority_unavailable';

        // Native Woo checkout tax mode is not automatically a tax authority;
        // verified independent tax receipt is mandatory for issuance.
        $record=null;
        if (!array_diff(['supplier_cost','tax','availability','final_quote'],array_keys($claims))) {
            $candidate=self::reconcile($claims,[
                'site_uuid'=>$probe['site_uuid'],
                'policy_revision'=>$probe['policy_revision'],
                'policy_digest'=>$probe['policy_digest'],
                'plan_digest'=>$probe['plan_digest'],
                'woo_product_id'=>$line['woo_product_id'],
                'service_id'=>$line['service_id'],
                'rate_id'=>$line['rate_id'],
                'sale_role'=>$line['role']
            ]);
            if (is_wp_error($candidate))$missing[]=$candidate->get_error_code();
            else $record=$candidate;
        }
        return [
            'contract'=>'commerce.final-quote-issuance-readiness.v1',
            'site_uuid'=>$probe['site_uuid'],
            'policy_revision'=>$probe['policy_revision'],
            'policy_digest'=>$probe['policy_digest'],
            'plan_digest'=>$probe['plan_digest'],
            'source_receipt_states'=>$probe['provider_probes'],
            'source_receipts_observed'=>$probe['source_receipts_observed'],
            'native_financial_mode_approved'=>$financialApproved,
            'company_policy_active'=>$policyActive,
            'site_write_ready'=>$authorityReady,
            'blockers'=>array_values(array_unique($missing)),
            'can_issue'=>empty($missing)&&$record!==null,
            'candidate'=>empty($missing)?$record:null,
            'order_authorized'=>false,
            'payment_authorized'=>false,
            'supplier_booking_authorized'=>false,
            'no_side_effects'=>true
        ];
    }

    /** Immutable, source-proven Final Quote, subject to company approvals. */
    public static function issue(int $sourceId,array $configuration,string $role,
        string $market='',string $channel='web'): array|\WP_Error
    {
        if (!current_user_can('manage_options')||!current_user_can('manage_woocommerce'))
            return self::error('final_quote_issuance_permission_denied',403);
        $evaluated=self::evaluate($sourceId,$configuration,$role,$market,$channel);
        if (is_wp_error($evaluated))return $evaluated;
        if (!$evaluated['can_issue'])
            return self::error('final_quote_issuance_blocked');
        $record=$evaluated['candidate'];
        $key=self::STORE.$record['quote_digest'];
        $before=get_option($key,false);
        if ($before!==false) {
            if (!is_array($before)||$before!==$record)
                return self::error('final_quote_storage_conflict');
            return ['state'=>'idempotent_replay','quote'=>$record,
                'manager_approved'=>false,'traveler_accepted'=>false,
                'order_authorized'=>false,'payment_authorized'=>false,
                'supplier_booking_authorized'=>false];
        }
        if (!add_option($key,$record,'','no')) {
            $read=get_option($key,false);
            if (!is_array($read)||$read!==$record)
                return self::error('final_quote_storage_conflict');
        }
        $readback=get_option($key,false);
        if ($readback!==$record)return self::error('final_quote_persist_readback_failed');
        return ['state'=>'issued_awaiting_approvals','quote'=>$record,
            'manager_approved'=>false,'traveler_accepted'=>false,
            'order_authorized'=>false,'payment_authorized'=>false,
            'supplier_booking_authorized'=>false];
    }

    private static function exactQuote(string $digest):array|\WP_Error
    {
        if (!self::sha($digest))return self::error('final_quote_digest_invalid',400);
        $record=get_option(self::STORE.$digest,false);
        if (!is_array($record)
            ||($record['contract']??null)!==self::CONTRACT
            ||($record['quote_digest']??null)!==$digest)
            return self::error('final_quote_not_found',404);
        $ctx=CompanyCommercePolicy::context();
        if (is_wp_error($ctx))return $ctx;
        $policy=CompanyCommercePolicy::registry();
        if (is_wp_error($policy))return $policy;
        if ($record['site_uuid']!==$ctx['site_uuid']
            ||$record['policy_revision']!==$policy['revision']
            ||$policy['status']!=='active'
            ||strtotime($record['expires_at_utc'])<=time())
            return self::error('final_quote_expired_or_policy_superseded');
        return $record;
    }

    public static function approvalStatus(string $digest):array|\WP_Error
    {
        $record=self::exactQuote($digest);
        if(is_wp_error($record))return $record;
        $status=[];
        foreach (['manager_approval','traveler_acceptance'] as $kind) {
            $r=get_option(self::APPROVAL.$digest.'_'.$kind,false);
            $status[$kind]=is_array($r)
                &&($r['quote_digest']??null)===$digest
                &&($r['kind']??null)===$kind
                &&self::sha($r['receipt_digest']??null)
                &&($r['expires_at_utc']??'')>=gmdate('Y-m-d\TH:i:s\Z')
                ?'source_verified':'awaiting_source_receipt';
        }
        return [
            'contract'=>'commerce.final-quote-approval-status.v1',
            'quote_digest'=>$digest,'expires_at_utc'=>$record['expires_at_utc'],
            'manager_approval'=>$status['manager_approval'],
            'traveler_acceptance'=>$status['traveler_acceptance'],
            'accepted_by_both'=>count(array_filter($status,static fn($v)=>$v==='source_verified'))===2,
            'order_authorized'=>false,'payment_authorized'=>false,
            'supplier_booking_authorized'=>false
        ];
    }

    /**
     * Separate independent, verified manager and traveler source receipts.
     * Admin cannot assert customer acceptance: only the source-owned adapter
     * returning a signature-verified quote-bound receipt makes a write.
     */
    public static function verifyApproval(string $digest,string $kind,int $sourceId,
        array $configuration,string $role,string $market='',string $channel='web'):array|\WP_Error
    {
        if (!current_user_can('manage_options')||!current_user_can('manage_woocommerce'))
            return self::error('final_quote_approval_permission_denied',403);
        if (!in_array($kind,['manager_approval','traveler_acceptance'],true))
            return self::error('final_quote_approval_kind_invalid',400);
        $issued=self::exactQuote($digest);
        if (is_wp_error($issued))return $issued;
        $probe=CommercialQuoteEvidenceProbe::inspectForIssuer(
            $sourceId,$configuration,$role,$market,$channel);
        if (is_wp_error($probe))return $probe;
        if ($issued['plan_digest']!==$probe['plan_digest']
            ||$issued['policy_digest']!==$probe['policy_digest']
            ||$issued['woo_product_id']!==$probe['selected_sale_line']['woo_product_id'])
            return self::error('final_quote_approval_plan_changed');
        $receipt=$probe['source_verified_claims'][$kind]??null;
        $subject=$receipt['approver_subject']??null;
        if (!is_array($receipt)
            ||($receipt['quote_digest']??null)!==$digest
            ||!is_string($subject)
            ||!preg_match($kind==='manager_approval'
                ?'/^wp-user:[1-9][0-9]{0,19}$/D'
                :'/^traveler:[A-Za-z0-9_-]{8,128}$/D',$subject))
            return self::error('final_quote_approval_verified_receipt_missing');
        if ($kind==='manager_approval') {
            $managerId=(int)substr($subject,8);
            $manager=get_userdata($managerId);
            if (!$manager || !user_can($managerId,'manage_woocommerce'))
                return self::error('final_quote_approver_not_company_manager');
        }
        $ctx=CompanyCommercePolicy::context();
        if (is_wp_error($ctx) || ($ctx['site_write_ready']??false)!==true)
            return self::error('final_quote_approval_site_authority_unavailable');
        if (strtotime($receipt['expires_at_utc'])<=time())
            return self::error('final_quote_approval_receipt_expired');
        $approval=[
            'contract'=>'commerce.final-quote-approval-receipt.v1',
            'kind'=>$kind,'quote_digest'=>$digest,
            'receipt_digest'=>$receipt['receipt_digest'],
            'provider_id'=>$receipt['provider_id'],
            'source_owner'=>$receipt['source_owner'],
            'approver_subject'=>$subject,
            'expires_at_utc'=>$receipt['expires_at_utc']
        ];
        $key=self::APPROVAL.$digest.'_'.$kind;
        if (!add_option($key,$approval,'','no')) {
            $existing=get_option($key,false);
            if ($existing!==$approval)
                return self::error('final_quote_approval_conflicting_receipt');
            return ['state'=>'idempotent_verified_approval','kind'=>$kind,'quote_digest'=>$digest,
                'order_authorized'=>false,'payment_authorized'=>false];
        }
        if (get_option($key,false)!==$approval)
            return self::error('final_quote_approval_commit_unconfirmed');
        return ['state'=>'verified_approval_recorded','kind'=>$kind,'quote_digest'=>$digest,
            'order_authorized'=>false,'payment_authorized'=>false];
    }

    public static function permission():bool
    {
        return current_user_can('manage_options')&&current_user_can('manage_woocommerce');
    }

    private static function payload(\WP_REST_Request $request,bool $requireKind=false):array|\WP_Error
    {
        $body=$request->get_json_params();
        if (!is_array($body)||array_is_list($body)
            ||!is_int($body['source_id']??null)
            ||!is_array($body['configuration']??null)
            ||!is_string($body['sale_role']??null))
            return self::error('final_quote_invalid_request',400);
        $allowed=['source_id','configuration','sale_role','market','channel'];
        if($requireKind)$allowed=array_merge($allowed,['quote_digest','kind']);
        if(array_diff(array_keys($body),$allowed)
            ||(isset($body['market'])&&!is_string($body['market']))
            ||(isset($body['channel'])&&!is_string($body['channel']))
            ||($requireKind && (!is_string($body['quote_digest']??null)
                ||!is_string($body['kind']??null))))
            return self::error('final_quote_untrusted_request_fields',400);
        return $body;
    }

    private static function response(array|\WP_Error $result):\WP_REST_Response|\WP_Error
    {
        if(is_wp_error($result))return $result;
        $response=new \WP_REST_Response($result,200);
        $response->header('Cache-Control','private,no-store');
        return $response;
    }

    public static function routes():void
    {
        foreach ([
            '/management/commercial-quote/final/readiness'=>'readiness',
            '/management/commercial-quote/final/issue'=>'issue',
            '/management/commercial-quote/final/approval/verify'=>'verify',
        ] as $route=>$op) {
            register_rest_route(self::NS,$route,[
                'methods'=>\WP_REST_Server::CREATABLE,
                'permission_callback'=>[self::class,'permission'],
                'callback'=>static function(\WP_REST_Request $request)use($op){
                    $body=self::payload($request,$op==='verify');
                    if(is_wp_error($body))return $body;
                    $args=[
                        $body['source_id'],$body['configuration'],$body['sale_role'],
                        $body['market']??'',$body['channel']??'web'
                    ];
                    if($op==='readiness')$result=self::evaluate(...$args);
                    elseif($op==='issue')$result=self::issue(...$args);
                    else $result=self::verifyApproval(
                        $body['quote_digest'],$body['kind'],...$args);
                    if($op==='readiness'&&is_array($result)){
                        // Readiness can mention an issuable immutable candidate,
                        // but MUST NOT disclose unapproved supplier cost amounts.
                        $result['candidate']=$result['can_issue']
                            ?['quote_digest'=>$result['candidate']['quote_digest'],
                              'expires_at_utc'=>$result['candidate']['expires_at_utc']]
                            :null;
                    }
                    return self::response($result);
                }
            ]);
        }
        register_rest_route(self::NS,
            '/management/commercial-quote/final/(?P<quote_digest>[a-f0-9]{64})/status',[
                'methods'=>\WP_REST_Server::READABLE,
                'permission_callback'=>[self::class,'permission'],
                'callback'=>static fn(\WP_REST_Request $request)=>
                    self::response(self::approvalStatus((string)$request['quote_digest']))
            ]);
    }

    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'routes']);
    }
}
