<?php
/**
 * MAD4B request lifecycle native WooCommerce plain-text fallback.
 * Source-only candidate: live source supplies HTML only.
 * No email is sent here; original RequestLifecycle owns mail delivery.
 */
defined('ABSPATH') || exit;
$event = isset($event) && is_string($event) ? $event : '';
$request_body = isset($request_body) && is_string($request_body) ? $request_body : '';
$request_language = isset($request_language) && is_string($request_language)
    ? $request_language : 'en';
$advance_order = isset($advance_order) && $advance_order instanceof WC_Order
    ? $advance_order : null;
$pay_url = '';
// Strip every stale raw link before deciding whether a current payment CTA is eligible.
// A paid/closed order must not inherit an old URL from a queued message body.
if ($advance_order) {
    $request_body = str_replace($advance_order->get_checkout_payment_url(), '', $request_body);
}
if ($advance_order && $advance_order->needs_payment()
    && in_array($event, ['advance_requested', 'payment_failed'], true)
    && class_exists('\Commerce\Composition\RequestAdvancePayments')
    && \Commerce\Composition\RequestAdvancePayments::isAdvance($advance_order)) {
    $gateways = \Commerce\Composition\RequestAdvancePayments::gateways();
    if (is_array($gateways) && array_filter($gateways, static function ($gateway):bool {
        return is_array($gateway) && !empty($gateway['enabled'])
            && !empty($gateway['available_now'])
            && !empty($gateway['advance_eligible']);
    })) {
        $pay_url = $advance_order->get_checkout_payment_url();
    }
}
echo trim(wp_strip_all_tags($request_body))."\n\n";
if ($advance_order) {
    echo 'Request #'.(int)$advance_order->get_id()."\n";
    echo 'Requested advance: '.wp_strip_all_tags(wc_price(
        (float)$advance_order->get_total(), ['currency'=>$advance_order->get_currency()]))."\n";
}
if ($pay_url !== '') {
    $labels = [
        'ar'=>'رابط دفع العربون','es'=>'Enlace de pago del anticipo',
        'fr'=>'Lien de paiement de l’acompte','de'=>'Link zur Anzahlung',
        'it'=>'Link per l’anticipo','en'=>'Secure advance payment link'
    ];
    echo ($labels[$request_language]??$labels['en']).": ".$pay_url."\n";
}
$notices=[
    'ar'=>'هذا طلب رحلة وليس تأكيد حجز.',
    'es'=>'Esta solicitud no confirma ninguna reserva.',
    'fr'=>'Cette demande ne confirme pas de réservation.',
    'de'=>'Diese Anfrage ist keine Buchungsbestätigung.',
    'it'=>'Questa richiesta non conferma la prenotazione.',
    'en'=>'This is a travel request, not a booking confirmation.'
];
echo "\n".($notices[$request_language]??$notices['en'])."\n";
if (!empty($additional_content) && is_string($additional_content)) {
    echo "\n".trim(wp_strip_all_tags($additional_content))."\n";
}
