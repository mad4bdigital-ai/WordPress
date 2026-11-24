<?php
/**
 * Plugin Name: MAD4B Request WooCommerce Email Templates
 * Description: WooCommerce-managed lifecycle email template; existing MAD4B job queue and mail receipts remain authoritative.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/includes/mad4b-company-email-brand-v1.php';

/* Registration happens after WooCommerce loads WC_Email. No email is sent
 * here and no WooCommerce core template or gateway class is modified. */
add_filter('woocommerce_email_classes', static function ($emails) {
    if (!class_exists('WC_Email', false)) return $emails;
    if (!class_exists('MAD4B_WC_Email_Request_Lifecycle', false)) {
        class MAD4B_WC_Email_Request_Lifecycle extends WC_Email {
            public function __construct(bool $ack=false) {
                $this->id = 'mad4b_request_lifecycle';
                $this->title = 'MAD4B — Travel request and advance updates';
                $this->description = 'Customer request, payment-link, advance-received and failed-payment notifications. Sent by the existing MAD4B lifecycle queue; no separate dispatcher.';
                if($ack) {
                    $this->id='mad4b_request_received';
                    $this->title='MAD4B — Travel request received';
                    $this->description='Independent Form 6 enquiry acknowledgement, even without an order or payment. Company Communication controls traveler notifications.';
                } else {
                    $this->title='MAD4B — Travel request and advance updates';
                    $this->description='Payment-link, payment-received, refund and failed-payment notifications; Form 6 acknowledgement is managed separately.';
                }
                $this->customer_email = true;
                $this->email_group = 'payments';
                $this->template_html = 'emails/mad4b-request-lifecycle.php';
                $this->template_plain = 'emails/plain/mad4b-request-lifecycle.php';
                $this->template_base = WPMU_PLUGIN_DIR.'/templates/';
                $this->manual = true; // Never auto-trigger on WooCommerce order status changes.
                parent::__construct();
                $this->email_type = 'html';
            }
            public function get_default_subject() {
                return 'Travel request update — {site_title}';
            }
            public function get_default_heading() {
                return 'Your travel request';
            }
            /**
             * WooCommerce's WC_Email base returns an empty string for plain
             * content. The shared fallback has its own bounded rendering path
             * and never sends, schedules or modifies a lifecycle receipt.
             */
            public function get_content_plain() {
                $experience=MAD4B_Company_Email_Brand_V1::experience('en');
                return wc_get_template_html(
                    $this->template_plain,
                    [
                        'email'=>$this,
                        'event'=>'preview',
                        'request_body'=>'This is a preview of the shared WooCommerce template.',
                        'request_language'=>'en',
                        'email_experience'=>$experience,
                        'advance_order'=>null,
                        'additional_content'=>'',
                    ],
                    '',
                    $this->template_base
                );
            }
            public function get_content_html() {
                $experience=MAD4B_Company_Email_Brand_V1::experience('en');
                $brand=$experience['brand'];
                return MAD4B_Company_Email_Brand_V1::renderScoped($brand,function()use($brand,$experience){
                    $html=wc_get_template_html($this->template_html,[
                        'email'=>$this,
                        'email_heading'=>$this->get_heading(),
                        'event'=>'preview',
                        'request_subject'=>'Travel request',
                        'request_body'=>'This is a preview of the shared WooCommerce template.',
                        'request_language'=>'en',
                        'email_brand'=>$brand,
                        'email_experience'=>$experience,
                        'advance_order'=>null,
                        'additional_content'=>'',
                    ],'',$this->template_base);
                    return method_exists($this,'style_inline')?$this->style_inline($html):$html;
                });
            }
            /**
             * Renders only, and never calls wp_mail. The original
             * RequestLifecycle::sendMail keeps the one idempotency receipt.
             */
            public function render_lifecycle(array $msg, array $job, $order):string {
                $event = (string)($job['event'] ?? '');
                $supported = ['request_received','advance_requested','advance_received',
                    'refund_recorded','payment_failed','payment_cancelled'];
                if (!in_array($event, $supported, true)) return '';
                if ($order !== null && !($order instanceof WC_Order)) return '';
                $experience=MAD4B_Company_Email_Brand_V1::experience(
                    (string)($msg['language']??'en'),(int)($msg['source_id']??0));
                $brand=$experience['brand'];
                $html=MAD4B_Company_Email_Brand_V1::renderScoped($brand,function()use($msg,$order,$event,$brand,$experience){
                    $html=wc_get_template_html($this->template_html,[
                        'email'=>$this,
                        'email_heading'=>sanitize_text_field($msg['subject']??''),
                        'event'=>$event,
                        'request_subject'=>(string)($msg['subject']??''),
                        'request_body'=>(string)($msg['body']??''),
                        'request_language'=>(string)($msg['language']??'en'),
                        'email_brand'=>$brand,
                        'email_experience'=>$experience,
                        'advance_order'=>$order,
                        'additional_content'=>'',
                    ],'',$this->template_base);
                    // RequestLifecycle sends through its own single, receipt-backed wp_mail.
                    // Woo's normal WC_Email::send() inlines CSS; preserve that step here.
                    return method_exists($this,'style_inline')?$this->style_inline($html):$html;
                });
                return is_string($html) && str_contains($html,'mad4b-wc-request-content')?$html:'';
            }
        }
    }
    $emails['MAD4B_WC_Email_Request_Lifecycle'] = new MAD4B_WC_Email_Request_Lifecycle();
    $emails['MAD4B_WC_Email_Request_Received'] = new MAD4B_WC_Email_Request_Lifecycle(true);
    return $emails;
}, 40);


/**
 * Request-advance Woo orders have a single customer lifecycle owner: the
 * existing receipt-backed MAD4B WC_Email class. Prevent generic Woo status
 * emails from duplicating payment/request messages. Native invoice/order-pay
 * and all regular Woo products/orders remain untouched.
 *
 * If the original lifecycle worker or company verified transport is not
 * eligible, do not suppress Woo's own customer fallback.
 */
$mad4b_advance_native_status_owner=static function($enabled,$order=null,$email=null){
    if(!$enabled || !($order instanceof WC_Order)
       ||!class_exists('\Commerce\Composition\RequestAdvancePayments',false)
       ||!\Commerce\Composition\RequestAdvancePayments::isAdvance($order))
        return $enabled;
    if(!class_exists('\Commerce\Composition\RequestLifecycle',false)
       ||!class_exists('MAD4B_SCP_Company_Communication',false)
       ||!MAD4B_SCP_Company_Communication::canSend()
       ||!\Commerce\Composition\RequestLifecycle::travelerAllowed())
        return $enabled;
    $conf=\Commerce\Composition\RequestLifecycle::config();
    if(empty($conf['enabled'])||empty($conf['mail'])
       ||!class_exists('MAD4B_Lifecycle_Access',false)
       ||!\MAD4B_Lifecycle_Access::can('commerce_run_workflows',(int)($conf['actor_id']??0)))
        return $enabled;
    $emailClasses=WC()->mailer()->get_emails();
    $lifecycle=$emailClasses['MAD4B_WC_Email_Request_Lifecycle']??null;
    if(!$lifecycle instanceof WC_Email || !$lifecycle->is_enabled())
        return $enabled;
    return false; // Custom WC_Email lifecycle is the sole customer owner.
};
foreach(['customer_on_hold_order','customer_processing_order',
         'customer_completed_order','customer_failed_order',
         'customer_refunded_order'] as $mad4b_email_id) {
    add_filter('woocommerce_email_enabled_'.$mad4b_email_id,
        $mad4b_advance_native_status_owner,35,3);
}
unset($mad4b_email_id,$mad4b_advance_native_status_owner);
