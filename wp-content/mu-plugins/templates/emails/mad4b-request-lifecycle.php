<?php
/**
 * MAD4B travel request lifecycle email.
 * Rendered by WooCommerce WC_Email; uses the native email header/footer,
 * settings, logo, sender and inline styling. Can be overridden by the theme
 * in woocommerce/emails/mad4b-request-lifecycle.php.
 */
defined('ABSPATH') || exit;
$event = isset($event) ? (string)$event : '';
$request_body = isset($request_body) ? (string)$request_body : '';
$advance_order = isset($advance_order) && $advance_order instanceof WC_Order ? $advance_order : null;
$labels=[
'en'=>['ref'=>'Request reference','amount'=>'Requested advance',
  'pay'=>'Pay advance securely','later'=>'You can pay later. Your request has already been saved. Booking is not confirmed.'],
'ar'=>['ref'=>'رقم الطلب','amount'=>'العربون المطلوب',
  'pay'=>'دفع العربون بشكل آمن','later'=>'يمكنك الدفع لاحقًا. طلبك محفوظ، والحجز غير مؤكد بعد.'],
'es'=>['ref'=>'Referencia de solicitud','amount'=>'Anticipo solicitado',
  'pay'=>'Pagar anticipo de forma segura','later'=>'Puedes pagar más tarde. Tu solicitud ya se guardó. La reserva no está confirmada.'],
'fr'=>['ref'=>'Référence de la demande','amount'=>'Acompte demandé',
  'pay'=>'Payer l’acompte en toute sécurité','later'=>'Vous pourrez payer plus tard. Votre demande est enregistrée, sans confirmation de réservation.'],
'de'=>['ref'=>'Anfragereferenz','amount'=>'Angeforderte Anzahlung',
  'pay'=>'Anzahlung sicher bezahlen','later'=>'Sie können später zahlen. Ihre Anfrage ist gespeichert; die Buchung ist nicht bestätigt.'],
'it'=>['ref'=>'Riferimento richiesta','amount'=>'Acconto richiesto',
  'pay'=>'Paga l’acconto in sicurezza','later'=>'Puoi pagare più tardi. La richiesta è salvata; la prenotazione non è confermata.']
];
$mailLang=(string)($request_language??'en');
$label=$labels[$mailLang]??$labels['en'];
$notice = $request_body;
$pay_url = null;
if ($advance_order && $advance_order->needs_payment()) {
    $notice = str_replace($advance_order->get_checkout_payment_url(), '', $notice);
}
if ($advance_order &&
    in_array($event, ['advance_requested','payment_failed'], true) &&
    $advance_order->needs_payment() &&
    class_exists('\Commerce\Composition\RequestAdvancePayments') &&
    \Commerce\Composition\RequestAdvancePayments::isAdvance($advance_order) &&
    (bool)array_filter(\Commerce\Composition\RequestAdvancePayments::gateways(),
        static fn($g)=>!empty($g['enabled'])&&!empty($g['available_now'])&&!empty($g['advance_eligible']))) {
    $pay_url = $advance_order->get_checkout_payment_url();
    // Existing plain-text composer already appended this link.
    // Prevent a second raw link being printed in the HTML.
    $notice = str_replace($pay_url, '', $notice);
}
do_action('woocommerce_email_header', $email_heading, $email);
?>
<div class="mad4b-wc-request-content">
    <p><?php echo nl2br(esc_html(trim($notice))); ?></p>
    <?php if ($advance_order) : ?>
        <p><strong><?php echo esc_html(sprintf(
            $label['ref'].' #%d',
            $advance_order->get_id()
        )); ?></strong></p>
        <p><?php echo esc_html($label['amount'].':'); ?>
           <strong><?php echo wp_kses_post(wc_price((float)$advance_order->get_total(),
               ['currency'=>$advance_order->get_currency()])); ?></strong></p>
    <?php endif; ?>
    <?php if ($pay_url) : ?>
        <p><a class="button" href="<?php echo esc_url($pay_url); ?>">
          <?php echo esc_html($label['pay']); ?>
        </a></p>
        <p><?php echo esc_html($label['later']); ?></p>
    <?php endif; ?>
    <?php if (!empty($additional_content)) : ?>
        <p><?php echo wp_kses_post($additional_content); ?></p>
    <?php endif; ?>
</div>
<?php do_action('woocommerce_email_footer', $email); ?>
