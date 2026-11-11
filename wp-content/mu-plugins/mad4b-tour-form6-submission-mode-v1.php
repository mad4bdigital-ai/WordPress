<?php
/**
 * Plugin Name: MAD4B Tour Form #6 Submission Mode
 * Description: Public quote-vs-request-advance readiness and distinct consent-aware Fluent Form confirmations.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Form6_Submission_Mode_V1 {
 private const LANG=['en','ar','fr','de','es','it'];
 private static function lang($value):string {
  $code=(string)$value;
  return in_array($code,self::LANG,true)?$code:'en';
 }
 private static function wording(string $lang):array {
  $all=[
   'en'=>[
    'quote_button'=>'Request a quote',
    'quote_note'=>'You won’t be charged when you submit this request. We’ll confirm availability, the final price and any required deposit before you decide to proceed. This is not a confirmed booking.',
    'quote_success'=>'Your quote request has been received. No payment was taken. Our team will review availability and the final trip price before confirming any booking.',
    'advance_button'=>'Submit booking request',
    'advance_note'=>'Your request is saved first. If an advance is approved, you can pay securely now or later. Payment does not confirm the trip.',
    'advance_success'=>'Your request is saved. You can pay the requested advance now or later, if available below. This is not a confirmed booking.',
    'advance_fallback'=>'Request received, but advance checkout is not available for this request. No payment was taken. Our team will review the request and contact you.'
   ],
   'ar'=>[
    'quote_button'=>'اطلب عرض سعر',
    'quote_note'=>'لن يُخصم منك أي مبلغ عند إرسال هذا الطلب. سنؤكد التوافر والسعر النهائي وأي عربون مطلوب قبل أن تقرر المتابعة. إرسال الطلب لا يؤكد الحجز.',
    'quote_success'=>'استلمنا طلب عرض السعر. لم يتم تحصيل أي مبلغ. سيتحقق فريقنا من التوافر والسعر النهائي قبل تأكيد الحجز.',
    'advance_button'=>'أرسل طلب الحجز',
    'advance_note'=>'يُحفظ الطلب أولًا، وبعد تجهيز العربون يمكنك الدفع الآن أو لاحقًا. دفع العربون لا يؤكد الرحلة.',
    'advance_success'=>'تم حفظ طلبك. يمكنك دفع العربون الآن أو لاحقًا عند إتاحته أدناه. الحجز غير مؤكد بعد.',
    'advance_fallback'=>'استلمنا الطلب، لكن دفع العربون غير متاح له حاليًا. لم يتم تحصيل أي مبلغ. سيتواصل معك فريقنا بعد المراجعة.'
   ],
   'es'=>[
    'quote_button'=>'Solicitar presupuesto',
    'quote_note'=>'No se te cobrará al enviar esta solicitud. Confirmaremos la disponibilidad, el precio final y cualquier anticipo requerido antes de que decidas continuar. Aún no es una reserva confirmada.',
    'quote_success'=>'Hemos recibido tu solicitud de presupuesto. No se ha cobrado ningún importe. Nuestro equipo revisará la disponibilidad y el precio final.',
    'advance_button'=>'Enviar solicitud de reserva',
    'advance_note'=>'El anticipo se cobra en la página segura solo tras validar la solicitud. El pago no confirma el viaje ni su precio final.',
    'advance_success'=>'Solicitud guardada. Puedes pagar el anticipo ahora o más tarde si está disponible a continuación. La reserva no está confirmada.',
    'advance_fallback'=>'Solicitud recibida, pero el pago del anticipo no está disponible. No se ha cobrado nada. Nuestro equipo te contactará.'
   ],
   'fr'=>[
    'quote_button'=>'Demander un devis','quote_note'=>'Aucun paiement ne sera prélevé lors de l’envoi de cette demande. Nous confirmerons la disponibilité, le prix final et tout acompte requis avant votre décision. La réservation n’est pas confirmée.',
    'quote_success'=>'Demande de devis reçue. Aucun paiement effectué. Notre équipe vérifiera la disponibilité et le tarif final.',
    'advance_button'=>'Envoyer la demande de réservation',
    'advance_note'=>'L’acompte est prélevé après validation sur la page sécurisée. Il ne confirme ni le voyage ni le prix final.',
    'advance_success'=>'Demande enregistrée. Vous pouvez régler l’acompte maintenant ou plus tard si cette option est disponible ci-dessous.',
    'advance_fallback'=>'Demande reçue, mais l’acompte est indisponible. Aucun paiement prélevé. Notre équipe vous contactera.'
   ],
   'de'=>[
    'quote_button'=>'Angebot anfordern','quote_note'=>'Beim Absenden dieser Anfrage wird nichts berechnet. Wir bestätigen Verfügbarkeit, Endpreis und eine mögliche Anzahlung, bevor Sie fortfahren. Die Buchung ist noch nicht bestätigt.',
    'quote_success'=>'Anfrage eingegangen. Es wurde nichts berechnet. Unser Team prüft Verfügbarkeit und Endpreis.',
    'advance_button'=>'Buchungsanfrage senden',
    'advance_note'=>'Eine Anzahlung wird erst nach Serverprüfung auf der sicheren Zahlungsseite erhoben. Keine Buchungsbestätigung.',
    'advance_success'=>'Anfrage gespeichert. Sie können die Anzahlung jetzt oder später leisten, falls diese Option unten verfügbar ist.',
    'advance_fallback'=>'Anfrage eingegangen, Anzahlung derzeit nicht möglich. Keine Zahlung erfolgt. Wir melden uns.'
   ],
   'it'=>[
    'quote_button'=>'Richiedi preventivo','quote_note'=>'Non ti verrà addebitato nulla all’invio della richiesta. Confermeremo disponibilità, prezzo finale ed eventuale acconto prima che tu decida di procedere. La prenotazione non è ancora confermata.',
    'quote_success'=>'Richiesta ricevuta. Nessun importo addebitato. Verificheremo disponibilità e prezzo finale.',
    'advance_button'=>'Invia richiesta di prenotazione',
    'advance_note'=>'L’acconto viene riscosso alla cassa sicura solo dopo la verifica. Non conferma il viaggio né il prezzo finale.',
    'advance_success'=>'Richiesta salvata. Puoi pagare l’acconto ora o più tardi, se disponibile di seguito.',
    'advance_fallback'=>'Richiesta ricevuta ma pagamento dell’acconto non disponibile. Nessun importo addebitato.'
   ],
  ];
  return $all[$lang]??$all['en'];
 }
 public static function inspect(int $tour):array {
  $mode='quote_only';
  if($tour>0 && get_post_type($tour)==='tours-and-activities' &&
      get_post_status($tour)==='publish' &&
      get_post_field('post_password',$tour)==='' &&
      class_exists('\Commerce\Composition\SingleFluentPaymentRouter') &&
      class_exists('\Commerce\Composition\RequestAdvancePayments') &&
      \Commerce\Composition\SingleFluentPaymentRouter::active($tour,6)){
    $gateways=\Commerce\Composition\RequestAdvancePayments::gateways();
    $available=array_filter($gateways,static fn($item)=>
      !empty($item['enabled'])&&!empty($item['available_now'])&&!empty($item['advance_eligible']));
    // A configured router without a currently usable native gateway cannot
    // direct a visitor to a checkout that has no payment method.
    if($available && \Commerce\Composition\SingleFluentPaymentRouter::rates($tour))
      $mode='advance_checkout';
  }
  return ['mode'=>$mode,'requires_server_rate_verification'=>true,
    'booking_confirmed'=>false,'final_trip_price_agreed'=>false];
 }
 public static function route():void {
  register_rest_route('mad4b/v1','/tour-form6-submission-mode',[
   'methods'=>'GET','permission_callback'=>'__return_true',
   'callback'=>[self::class,'restMode'],
   'args'=>['tour_id'=>['required'=>true,'type'=>'integer'],
            'lang'=>['required'=>false,'type'=>'string','default'=>'en']]
  ]);
 }
 public static function restMode($request) {
  $tour=absint($request['tour_id']);
  if(!$tour||get_post_type($tour)!=='tours-and-activities'||
      get_post_status($tour)!=='publish')
   return new \WP_Error('tour_form_mode_unavailable','Tour unavailable',['status'=>404]);
  $language=self::lang($request->get_param('lang'));
  $result=self::inspect($tour);
  $out=array_merge($result,['labels'=>self::wording($language)]);
  $reply=rest_ensure_response($out);
  $reply->header('Cache-Control','private, no-store, max-age=0');
  return $reply;
 }
 public static function enqueue():void {
  if(!is_singular('tours-and-activities'))return;
  $path=WPMU_PLUGIN_DIR.'/assets/mad4b-tour-form6-submission-mode-v1.js';
  $css=WPMU_PLUGIN_DIR.'/assets/mad4b-tour-form6-submission-mode-v1.css';
  if(!is_readable($path)||!is_readable($css))return;
  $handle='mad4b-tour-form6-submission-mode-v1';
  wp_enqueue_script($handle,content_url('mu-plugins/assets/mad4b-tour-form6-submission-mode-v1.js'),
    [],(string)filemtime($path),true);
  wp_add_inline_script($handle,'window.MAD4BTourForm6ModeV1='.wp_json_encode([
   'tourId'=>get_queried_object_id(),
   'endpoint'=>rest_url('mad4b/v1/tour-form6-submission-mode'),
   'lang'=>self::lang(apply_filters('wpml_current_language','en'))
  ],JSON_UNESCAPED_SLASHES).';','before');
  wp_enqueue_style($handle,content_url('mu-plugins/assets/mad4b-tour-form6-submission-mode-v1.css'),
    [],(string)filemtime($css));
 }
 public static function confirmation($response,$form,$confirmation=null,$sid=null,$data=null){
  if(!is_array($response)||!is_object($form)||(int)($form->id??0)!==6||
      !is_numeric($sid)||((int)$sid)<1||
      ($response['success']??true)===false ||
      !class_exists('\Commerce\Composition\RequestAdvancePayments'))return $response;
  $context=\Commerce\Composition\RequestAdvancePayments::submissionContext(6,(int)$sid);
  if(is_wp_error($context)||get_post_type($context['source_id']??0)!=='tours-and-activities')return $response;
  $lang=self::lang(apply_filters('wpml_current_language','en'));
  $copy=self::wording($lang);
  // Never force a redirect after a successful Fluent Form submission.
  // We only display the payment option *after* the request is committed.
  // We do not create an order or charge from this confirmation callback.
  unset($response['redirectUrl']);
  $orders=\Commerce\Composition\RequestAdvancePayments::findBySource(6,(int)$sid,3);
  $order=count($orders)===1 && \Commerce\Composition\RequestAdvancePayments::isAdvance($orders[0])
   ? $orders[0] : null;
  if($order && ((int)$order->get_meta('_request_submission_id')!==(int)$sid ||
      (int)$order->get_meta('_request_form_id')!==6 ||
      (int)$order->get_meta('_request_source_id')!==(int)($context['source_id']??0)))
    $order=null;
  $labels=[
   'en'=>['title'=>'Request received','reference'=>'Request reference',
    'payment'=>'Optional advance payment','pay'=>'Pay advance securely','later'=>'Pay later — your request is already saved',
    'note'=>'An advance does not confirm availability or the final trip price.','email'=>'A payment link is being prepared for your email.'],
   'ar'=>['title'=>'تم استلام الطلب','reference'=>'رقم الطلب',
    'payment'=>'دفع العربون اختياري الآن','pay'=>'دفع العربون بشكل آمن','later'=>'الدفع لاحقًا — طلبك محفوظ بالفعل',
    'note'=>'دفع العربون لا يؤكد التوافر أو السعر النهائي للرحلة.','email'=>'يجري تجهيز رابط الدفع لإرساله إلى بريدك الإلكتروني.'],
   'es'=>['title'=>'Solicitud recibida','reference'=>'Referencia de solicitud',
    'payment'=>'Anticipo opcional','pay'=>'Pagar anticipo de forma segura','later'=>'Pagar más tarde — solicitud guardada',
    'note'=>'El anticipo no confirma disponibilidad ni precio final.','email'=>'Se está preparando el enlace de pago para tu correo.'],
   'fr'=>['title'=>'Demande reçue','reference'=>'Référence',
    'payment'=>'Acompte facultatif','pay'=>'Payer l’acompte en toute sécurité','later'=>'Payer plus tard — demande enregistrée',
    'note'=>'L’acompte ne confirme ni disponibilité ni tarif final.','email'=>'Le lien de paiement sera préparé pour votre courriel.'],
   'de'=>['title'=>'Anfrage erhalten','reference'=>'Anfragereferenz',
    'payment'=>'Optionale Anzahlung','pay'=>'Anzahlung sicher bezahlen','later'=>'Später bezahlen — Anfrage ist gespeichert',
    'note'=>'Die Anzahlung bestätigt weder Verfügbarkeit noch Endpreis.','email'=>'Der Zahlungslink wird für Ihre E-Mail vorbereitet.'],
   'it'=>['title'=>'Richiesta ricevuta','reference'=>'Riferimento richiesta',
    'payment'=>'Acconto facoltativo','pay'=>'Paga l’acconto in sicurezza','later'=>'Paga dopo — richiesta salvata',
    'note'=>'L’acconto non conferma disponibilità o prezzo finale.','email'=>'Prepariamo il link di pagamento per la tua email.'],
  ];
  // Source post language is stable even when the AJAX endpoint uses the default
  // WPML context, and prevents English copy on Spanish traveller submissions.
  $source=(int)($context['source_id']??0);
  $postLang=(string)apply_filters('wpml_element_language_code',null,[
    'element_id'=>$source,'element_type'=>'post_'.get_post_type($source)
  ]);
  if(in_array($postLang,self::LANG,true))$lang=$postLang;
  // Both the headline and the body must use the original Tour's WPML
  // language; the AJAX endpoint's locale is not a trusted presentation hint.
  $copy=self::wording($lang);
  $display=$labels[$lang]??$labels['en'];
  $current=self::inspect($source);
  // A historical pending order can remain unpaid even while every gateway
  // is disabled. Never advertise its unusable pay URL as an available action.
  $isPayable=$order && $order->needs_payment() &&
    $order->get_meta('_request_collection_state')==='awaiting_payment' &&
    $current['mode']==='advance_checkout';
  $receivedCopy=[
   'en'=>'Your advance has been received. The trip is still awaiting final confirmation.',
   'ar'=>'تم استلام العربون. الرحلة ما زالت في انتظار التأكيد النهائي.',
   'es'=>'Hemos recibido tu anticipo. El viaje sigue pendiente de confirmación.',
   'fr'=>'Votre acompte a été reçu. Le voyage attend toujours la confirmation finale.',
   'de'=>'Ihre Anzahlung ist eingegangen. Die Reise muss noch endgültig bestätigt werden.',
   'it'=>'Abbiamo ricevuto l’acconto. Il viaggio resta in attesa di conferma definitiva.'
  ];
  $message=$order && $order->get_meta('_request_collection_state')==='received'
    ? ($receivedCopy[$lang]??$receivedCopy['en'])
    :($isPayable?$copy['advance_success']:($current['mode']==='advance_checkout'
      ? $copy['advance_fallback']:$copy['quote_success']));
  $reference=$order?(string)$order->get_id():('FF-'.(int)$sid);
  $html='<div class="mad4b-request-confirmation" role="status" aria-live="polite">';
  $html.='<h3>'.esc_html($display['title']).'</h3>';
  $html.='<p>'.esc_html($message).'</p>';
  $html.='<p><strong>'.esc_html($display['reference']).': '.esc_html($reference).'</strong></p>';
  if($isPayable && $order instanceof \WC_Order){
    // Only the existing WooCommerce order is allowed to issue a bearer pay URL.
    $url=$order->get_checkout_payment_url();
    if(is_string($url)&&wp_parse_url($url,PHP_URL_HOST)===wp_parse_url(home_url(),PHP_URL_HOST)){
      $html.='<section class="mad4b-request-payment-panel">';
      $html.='<h4>'.esc_html($display['payment']).'</h4>';
      $html.='<div class="mad4b-request-advance-amount">'.wp_kses_post(wc_price(
        (float)$order->get_total(),['currency'=>$order->get_currency()])).'</div>';
      $html.='<a class="mad4b-request-pay-link" href="'.esc_url($url).'">'.
        esc_html($display['pay']).'</a>';
      $html.='<p>'.esc_html($display['later']).'</p>';
      $html.='<p>'.esc_html($display['note']).'</p>';
      $html.='</section>';
    }
  }
  $html.='</div>';
  $response['message']=$html;
  return $response;
 }
 public static function bootstrap():void {
  add_action('rest_api_init',[self::class,'route']);
  add_action('wp_enqueue_scripts',[self::class,'enqueue'],40);
  add_filter('fluentform/submission_confirmation',[self::class,'confirmation'],10040,5);
 }
}
MAD4B_Tour_Form6_Submission_Mode_V1::bootstrap();
