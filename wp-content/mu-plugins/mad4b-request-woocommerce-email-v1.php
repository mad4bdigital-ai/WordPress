<?php
/**
 * Plugin Name: MAD4B Request WooCommerce Email Templates
 * Description: WooCommerce-managed lifecycle email template; existing MAD4B job queue and mail receipts remain authoritative.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/* Registration happens after WooCommerce loads WC_Email. No email is sent
 * here and no WooCommerce core template or gateway class is modified. */
add_filter('woocommerce_email_classes', static function ($emails) {
    if (!class_exists('WC_Email', false)) return $emails;
    if (!class_exists('MAD4B_WC_Email_Request_Lifecycle', false)) {
        class MAD4B_WC_Email_Request_Lifecycle extends WC_Email {
            public function __construct() {
                $this->id = 'mad4b_request_lifecycle';
                $this->title = 'MAD4B — Travel request and advance updates';
                $this->description = 'Customer request, payment-link, advance-received and failed-payment notifications. Sent by the existing MAD4B lifecycle queue; no separate dispatcher.';
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
                return wc_get_template_html(
                    $this->template_plain,
                    [
                        'email' => $this,
                        'event' => 'preview',
                        'request_body' => 'This is a preview of the shared WooCommerce template.',
                        'request_language' => 'en',
                        'advance_order' => null,
                        'additional_content' => $this->get_additional_content(),
                    ],
                    '',
                    $this->template_base
                );
            }
            public function get_content_html() {
                return wc_get_template_html(
                    $this->template_html,
                    [
                        'email' => $this,
                        'email_heading' => $this->get_heading(),
                        'event' => 'preview',
                        'request_subject' => 'Travel request',
                        'request_body' => 'This is a preview of the shared WooCommerce template.',
                        'advance_order' => null,
                        'additional_content' => $this->get_additional_content(),
                    ],
                    '',
                    $this->template_base
                );
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
                $html = wc_get_template_html(
                    $this->template_html,
                    [
                        'email' => $this,
                        'email_heading' => sanitize_text_field($msg['subject'] ?? ''),
                        'event' => $event,
                        'request_subject' => (string)($msg['subject'] ?? ''),
                        'request_body' => (string)($msg['body'] ?? ''),
                        'request_language' => (string)($msg['language'] ?? 'en'),
                        'advance_order' => $order,
                        'additional_content' => $this->get_additional_content(),
                    ],
                    '',
                    $this->template_base
                );
                return is_string($html) && str_contains($html, 'mad4b-wc-request-content') ? $html : '';
            }
        }
    }
    $emails['MAD4B_WC_Email_Request_Lifecycle'] = new MAD4B_WC_Email_Request_Lifecycle();
    return $emails;
}, 40);
