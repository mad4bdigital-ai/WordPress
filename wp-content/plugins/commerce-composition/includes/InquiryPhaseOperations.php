<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Inquiry-only phase: opt-in persisted Fluent Form -> existing idempotent job,
 * plus server-owned indicative catalog price projection. No new customer,
 * marketing enrollment, payment, Woo order, supplier booking or outbound mail.
 */
final class InquiryPhaseOperations
{
    private const OPTION='commerce_inquiry_phase_config_v1';

    public static function config():array
    {
        $c=get_option(self::OPTION,[]);
        return is_array($c)?$c:[];
    }

    private static function configuredForm(int $formId):bool
    {
        $c=self::config();
        $forms=$c['form_ids']??null;
        if(($c['enabled']??null)!==true || !is_array($forms)
            || $formId<1 || count($forms)>20)return false;
        $ids=array_values(array_unique(array_filter(array_map('intval',$forms),
            static fn($id)=>$id>0)));
        if(!in_array($formId,$ids,true))return false;
        // Approved configuration belongs to a single inspected WordPress site,
        // not a tenant-wide filter accidentally affecting Production.
        return is_string($c['site_url']??null)
            && hash_equals((string)$c['site_url'],(string)home_url());
    }

    private static function adminActor(int $formId):int
    {
        if(!self::configuredForm($formId))return 0;
        $actor=self::config()['actor_id']??null;
        if(!is_int($actor)||$actor<1 || !user_can($actor,'manage_options'))
            return 0;
        return $actor;
    }

    /** Only original, persisted form intent may trigger automatic intake. */
    public static function eligible(int $formId,int $submissionId):bool
    {
        if(self::adminActor($formId)<1 || $submissionId<1
            || !RequestCheckout::ready() || !WorkflowJobs::ready())return false;
        $proof=SubmissionProvenance::verify($submissionId,$formId);
        if(is_wp_error($proof))return false;
        global $wpdb;
        $t=$wpdb->prefix.'fluentform_submissions';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT response,status FROM $t WHERE id=%d AND form_id=%d",
            $submissionId,$formId),ARRAY_A);
        if(!is_array($row)
            || in_array($row['status'],['trashed','spam','deleted'],true))return false;
        $answers=json_decode((string)$row['response'],true);
        if(!is_array($answers)||count($answers)>250)return false;
        $intent=FluentSubmissionAdapter::prepareSubmissionIntent(
            $submissionId,$answers,(object)['id'=>$formId]);
        return is_array($intent)
            &&($intent['mode']??null)==='request_only'
            &&($intent['checkout_authority']??null)===false
            &&($intent['cart_authority']??null)===false
            &&($intent['source_parent_id']??0)>0;
    }

    public static function enabledForSubmission($enabled,$formId,$submissionId):bool
    {
        if($enabled===true)return true; // another approved provider
        return is_numeric($formId)&&is_numeric($submissionId)
            &&self::eligible((int)$formId,(int)$submissionId);
    }

    public static function actorForSubmission($actorId,$formId,$submissionId)
    {
        if(is_int($actorId)&&$actorId>0)return $actorId;
        return self::eligible((int)$formId,(int)$submissionId)
            ?self::adminActor((int)$formId):null;
    }

    /**
     * Fresh catalog snapshot PREVIEW only. Not historical price at submission
     * time, not per-person total, not tax-inclusive or an approved quotation.
     */
    public static function estimate(int $formId,int $submissionId):array|\WP_Error
    {
        if($formId<1||$submissionId<1)return new \WP_Error('inquiry_source_invalid');
        $source=SubmissionProvenance::verify($submissionId,$formId);
        if(is_wp_error($source))return $source;
        global $wpdb;
        $t=$wpdb->prefix.'fluentform_submissions';
        $json=$wpdb->get_var($wpdb->prepare(
            "SELECT response FROM $t WHERE id=%d AND form_id=%d",
            $submissionId,$formId));
        if(!is_string($json))return new \WP_Error('inquiry_source_missing');
        $answers=json_decode($json,true);
        if(!is_array($answers)||count($answers)>250)
            return new \WP_Error('inquiry_source_invalid');
        $intent=FluentSubmissionAdapter::prepareSubmissionIntent(
            $submissionId,$answers,(object)['id'=>$formId]);
        if(is_wp_error($intent))return $intent;
        if(($intent['mode']??null)!=='request_only'
            ||($intent['checkout_authority']??true)!==false)
            return new \WP_Error('inquiry_request_only_required');
        $rateId=(int)($intent['source_rate_id']??0);
        $parent=(int)($intent['source_parent_id']??0);
        if($rateId<1)return [
            'status'=>'price_on_request','parent_id'=>$parent,'rate_id'=>null,
            'indicative_only'=>true,'final_quote'=>false,
            'checkout_authorized'=>false,'payment_authorized'=>false
        ];
        $sourceIdentity=NativeOrderItemIdentityResolver::source($rateId);
        if($sourceIdentity['parent']!==$parent)
            return new \WP_Error('inquiry_rate_parent_mismatch');
        $product=wc_get_product($rateId);
        $raw=$product instanceof \WC_Product?$product->get_price():'';
        $currency=get_woocommerce_currency();
        if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
            return new \WP_Error('inquiry_currency_not_certified');
        if(!is_numeric($raw)||(float)$raw<0||!is_finite((float)$raw))
            return [
                'status'=>'price_on_request','parent_id'=>$parent,'rate_id'=>$rateId,
                'currency'=>$currency,'indicative_only'=>true,
                'checkout_authorized'=>false,'payment_authorized'=>false
            ];
        return [
            'status'=>'catalog_estimate_as_of_now','parent_id'=>$parent,
            'rate_id'=>$rateId,'amount'=>(float)$raw,'currency'=>$currency,
            'as_of_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
            'price_unit'=>'unverified','taxes_and_fees'=>'not_finalized',
            'not_a_historical_submission_price'=>true,
            'indicative_only'=>true,'final_quote'=>false,
            'checkout_authorized'=>false,'payment_authorized'=>false,
            'supplier_booking_authorized'=>false
        ];
    }

    public static function readiness():array
    {
        $c=self::config();
        $settings=function_exists('fluentMailGetSettings')?fluentMailGetSettings():[];
        $count=is_array($settings)&&is_array($settings['connections']??null)
            ?count($settings['connections']):0;
        $anti=get_option('_fluentform_global_form_settings',[]);
        $misc=is_array($anti)?($anti['misc']??[]):[];
        $misc=is_array($misc)?$misc:[];
        $recaptcha=get_option('_fluentform_reCaptcha_details',[]);
        $googleV3=is_array($recaptcha)
            &&($recaptcha['api_version']??null)==='v3_invisible'
            &&!empty($recaptcha['siteKey'])
            &&!empty($recaptcha['secretKey'])
            &&get_option('_fluentform_reCaptcha_keys_status',false)===true;

        global $wpdb;
        $feedTable=$wpdb->prefix.'fluentform_form_meta';
        $feeds=0;
        foreach((array)($c['form_ids']??[]) as $formId){
            if(!is_numeric($formId)||(int)$formId<1)continue;
            $feeds+=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$feedTable} WHERE form_id=%d AND meta_key=%s",
                (int)$formId,'fluentcrm_feeds'));
        }
        return [
            'contract'=>'commerce.inquiry-phase-readiness.v1',
            'configured'=>($c['enabled']??false)===true,
            'form_ids'=>array_values(array_map('intval',(array)($c['form_ids']??[]))),
            'review_enabled'=>RequestReview::enabled()&&RequestReview::ready(),
            'crm_original_source_configured'=>count(array_filter(
                ContactBridge::sources(),static fn($v)=>is_array($v)
                &&($v['domain']??null)==='fluent_form'
                &&(bool)array_intersect((array)($v['form_ids']??[]),(array)($c['form_ids']??[]))
            ))>0,
            'crm_native_feed_count'=>$feeds,
            'crm_native_feed_automated'=>$feeds>0,
            'crm_operational_sync'=>class_exists(FluentInquiryCrmIntegration::class)
                ?FluentInquiryCrmIntegration::readiness():['enabled'=>false],
            'estimate_preview_available'=>true,
            'estimate_form_display_bound'=>false,
            'estimate_immutable_snapshot_persisted'=>false,
            'source_options_snapshot_enabled'=>is_array($sc=get_option('commerce_inquiry_snapshot_policy_v1',[]))
                &&($sc['enabled']??null)===true
                &&($sc['site_url']??null)===home_url()
                &&class_exists(InquirySelectionSnapshot::class)
                &&InquirySelectionSnapshot::ready(),
            'product_page_signed_context_receipt'=>class_exists(ProductFormSourceReceipt::class),
            'catalog_price_at_submission_is_reference_only'=>true,
            'boards_ready'=>FluentBoardsTaskBridge::ready(),
            'workflow_queue_ready'=>WorkflowJobs::ready(),
            'smtp_connection_count'=>$count,
            'fluent_honeypot_globally_enabled'=>($misc['honeypotStatus']??null)==='yes',
            'fluent_token_protection_globally_enabled'=>($misc['tokenBasedProtectionStatus']??null)==='yes',
            'google_recaptcha_v3_configured_and_verified'=>$googleV3,
            'email_delivery_certified'=>false,
            'financial_order_authorized'=>false,'checkout_authorized'=>false,
            'supplier_booking_authorized'=>false
        ];
    }

    public static function routes():void
    {
        $auth=static fn()=>RequestReview::enabled()
            && RequestReview::authorized();
        register_rest_route('commerce-composition/v1','/management/inquiry-phase/readiness',[
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>$auth,
            'callback'=>static function(){
                $r=new \WP_REST_Response(self::readiness(),200);
                $r->header('Cache-Control','private,no-store');
                return $r;
            }
        ]);
        register_rest_route('commerce-composition/v1',
            '/management/inquiry-phase/estimate/(?P<form_id>[1-9][0-9]*)/(?P<submission_id>[1-9][0-9]*)',[
                'methods'=>\WP_REST_Server::READABLE,
                'permission_callback'=>$auth,
                'callback'=>static function(\WP_REST_Request $request){
                    $result=self::estimate((int)$request['form_id'],(int)$request['submission_id']);
                    if(is_wp_error($result))return $result;
                    $r=new \WP_REST_Response($result,200);
                    $r->header('Cache-Control','private,no-store');
                    return $r;
                }
            ]);
    }

    public static function bootstrap():void
    {
        add_filter('commerce_composition_enable_auto_form_request_intake',
            [self::class,'enabledForSubmission'],30,3);
        add_filter('commerce_composition_auto_form_request_actor_id',
            [self::class,'actorForSubmission'],30,3);
        add_action('rest_api_init',[self::class,'routes']);
    }
}
