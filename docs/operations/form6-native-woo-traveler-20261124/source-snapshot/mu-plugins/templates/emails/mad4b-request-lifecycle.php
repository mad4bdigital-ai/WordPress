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
$isFullNow=$advance_order && $advance_order->get_meta('_request_payment_schedule')==='full_due_now';
$mailLang=(string)($request_language??'en');
$label=$labels[$mailLang]??$labels['en'];
if($isFullNow){
    $full=[
      'en'=>['amount'=>'Full Estimated Payment (100%)','pay'=>'Pay estimated full amount securely'],
      'ar'=>['amount'=>'المبلغ المستحق بالكامل (100%)','pay'=>'دفع المبلغ كاملًا بأمان'],
      'es'=>['amount'=>'Pago total solicitado (100%)','pay'=>'Pagar importe completo'],
      'fr'=>['amount'=>'Paiement intégral demandé (100%)','pay'=>'Payer la totalité'],
      'de'=>['amount'=>'Vollzahlung angefordert (100%)','pay'=>'Gesamtbetrag sicher bezahlen'],
      'it'=>['amount'=>'Pagamento integrale richiesto (100%)','pay'=>'Paga l’intero importo']
    ];
    $label=array_replace($label,$full[$mailLang]??$full['en']);
}
if($event==='advance_received') {
    $received=$isFullNow
        ?[
            'en'=>'Full estimated payment received','ar'=>'تم استلام كامل القيمة التقديرية',
            'es'=>'Pago estimado total recibido','fr'=>'Paiement intégral estimé reçu',
            'de'=>'Geschätzte Vollzahlung erhalten','it'=>'Pagamento integrale stimato ricevuto'
         ]
        :[
            'en'=>'Advance received','ar'=>'تم استلام العربون',
            'es'=>'Anticipo recibido','fr'=>'Acompte reçu',
            'de'=>'Anzahlung erhalten','it'=>'Acconto ricevuto'
         ];
    $label['amount']=$received[$mailLang]??$received['en'];
}

$experience=is_array($email_experience??null)?$email_experience:[];
$brand=is_array($experience['brand']??null)?$experience['brand']:[];
$links=is_array($experience['links']??null)?$experience['links']:[];
$tour=is_array($experience['tour']??null)?$experience['tour']:[];
$brandName=sanitize_text_field((string)($brand['name']??''));
$primary=(string)($brand['primary']??'#24323B');
$accent=(string)($brand['accent']??'#B48A46');
$surface=(string)($brand['surface']??'#F8F7F4');
$dictionary=[
 'en'=>['status'=>'Your request update','experience'=>'Your travel experience',
        'view_tour'=>'Explore your tour','home'=>'Explore our website',
        'privacy'=>'Privacy policy','terms'=>'Terms & conditions',
        'reply'=>'Questions? Reply to this email.','reference'=>'Request details'],
 'ar'=>['status'=>'آخر مستجدات طلبك','experience'=>'تجربة رحلتك',
        'view_tour'=>'استكشف تفاصيل الرحلة','home'=>'زيارة موقعنا',
        'privacy'=>'سياسة الخصوصية','terms'=>'الشروط والأحكام',
        'reply'=>'عندك استفسار؟ تقدر ترد على الرسالة.','reference'=>'تفاصيل الطلب'],
 'es'=>['status'=>'Actualización de tu solicitud','experience'=>'Tu experiencia de viaje',
        'view_tour'=>'Explora tu viaje','home'=>'Visita nuestro sitio',
        'privacy'=>'Política de privacidad','terms'=>'Términos y condiciones',
        'reply'=>'¿Tienes dudas? Responde a este correo.','reference'=>'Detalles de la solicitud'],
 'fr'=>['status'=>'Actualités de votre demande','experience'=>'Votre expérience de voyage',
        'view_tour'=>'Découvrir votre circuit','home'=>'Visiter notre site',
        'privacy'=>'Politique de confidentialité','terms'=>'Conditions générales',
        'reply'=>'Une question ? Répondez à cet e-mail.','reference'=>'Détails de la demande'],
 'de'=>['status'=>'Aktuelles zu Ihrer Anfrage','experience'=>'Ihr Reiseerlebnis',
        'view_tour'=>'Ihre Reise ansehen','home'=>'Unsere Website besuchen',
        'privacy'=>'Datenschutz','terms'=>'AGB',
        'reply'=>'Fragen? Antworten Sie auf diese E-Mail.','reference'=>'Anfragedetails'],
 'it'=>['status'=>'Aggiornamento della richiesta','experience'=>'La tua esperienza di viaggio',
        'view_tour'=>'Scopri il tuo tour','home'=>'Visita il nostro sito',
        'privacy'=>'Informativa sulla privacy','terms'=>'Termini e condizioni',
        'reply'=>'Hai domande? Rispondi a questa e-mail.','reference'=>'Dettagli richiesta'],
];
$ui=$dictionary[$mailLang]??$dictionary['en'];
$direction=$mailLang==='ar'?'rtl':'ltr';
$notice = $request_body;
$pay_url = null;
if ($advance_order) {
    // A payment URL embedded by a queued body can become stale when payment closes.
    $notice = str_replace($advance_order->get_checkout_payment_url(), '', $notice);
}
if ($advance_order &&
    in_array($event, ['advance_requested','payment_failed'], true) &&
    $advance_order->needs_payment() &&
    class_exists('\Commerce\Composition\RequestAdvancePayments') &&
    \Commerce\Composition\RequestAdvancePayments::isAdvance($advance_order) &&
    // In a background mail worker, is_available() often requires a Woo
    // checkout session/cart. Use native enabled+eligibility here, then let
    // actual order-pay enforce real payment-method availability.
    (bool)array_filter(\Commerce\Composition\RequestAdvancePayments::gateways(),
        static fn($g)=>!empty($g['enabled'])&&!empty($g['advance_eligible']))) {
    $pay_url = \Commerce\Composition\RequestAdvancePayments::safePaymentUrl($advance_order);
    // Existing plain-text composer already appended this link.
    // Prevent a second raw link being printed in the HTML.
    if(is_string($pay_url)&&$pay_url!=='')$notice = str_replace($pay_url, '', $notice);
}
do_action('woocommerce_email_header', $email_heading, $email);
?>
<div class="mad4b-wc-request-content" lang="<?php echo esc_attr($mailLang); ?>" dir="<?php echo esc_attr($direction); ?>" style="color:<?php echo esc_attr($brand['text']??'#24323B'); ?>;line-height:1.65">
    <?php if (!empty($brand['bound'])) : ?>
        <div style="border-top:4px solid <?php echo esc_attr($accent); ?>;padding:16px 0 10px;">
            <p style="margin:0;font-size:12px;letter-spacing:.05em;color:<?php echo esc_attr($primary); ?>;font-weight:700">
                <?php echo esc_html($ui['status']); ?>
            </p>
        </div>
    <?php endif; ?>
    <p style="margin:16px 0 24px;"><?php echo nl2br(esc_html(trim($notice))); ?></p>
    <?php if (!empty($tour['url']) && !empty($tour['title'])) : ?>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr($surface); ?>;margin:14px 0;border-collapse:collapse">
            <tr><td style="padding:18px 20px;">
                <p style="margin:0 0 8px;font-size:12px;color:<?php echo esc_attr($primary); ?>;font-weight:700;">
                    <?php echo esc_html($ui['experience']); ?>
                </p>
                <p style="margin:0 0 12px;font-size:18px;font-weight:700;">
                    <?php echo esc_html($tour['title']); ?>
                </p>
                <a href="<?php echo esc_url($tour['url']); ?>" style="color:<?php echo esc_attr($primary); ?>;font-weight:700;text-decoration:underline">
                    <?php echo esc_html($ui['view_tour']); ?>
                </a>
            </td></tr>
        </table>
    <?php endif; ?>
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
    <?php if (!empty($brand['bound'])) : ?>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:26px 0 0;border-top:1px solid #E1E4E5;border-collapse:collapse">
            <tr><td style="padding:18px 0 0;font-size:13px;line-height:1.75">
                <p style="margin:0 0 10px;"><?php echo esc_html($ui['reply']); ?></p>
                <p style="margin:0;">
                    <?php if (!empty($links['home'])) : ?>
                        <a href="<?php echo esc_url($links['home']); ?>" style="color:<?php echo esc_attr($primary); ?>;"><?php echo esc_html($ui['home']); ?></a>
                    <?php endif; ?>
                    <?php foreach (['privacy','terms'] as $kind) : ?>
                        <?php if (!empty($links[$kind])) : ?>
                            &nbsp; · &nbsp;<a href="<?php echo esc_url($links[$kind]); ?>" style="color:<?php echo esc_attr($primary); ?>;"><?php echo esc_html($ui[$kind]); ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
                <?php if ($brandName!=='') : ?><p style="margin:12px 0 0;font-size:12px;"><?php echo esc_html($brandName); ?></p><?php endif; ?>
            </td></tr>
        </table>
    <?php endif; ?>
</div>
<?php do_action('woocommerce_email_footer', $email); ?>
