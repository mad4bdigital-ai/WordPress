<?php
/**
 * Plugin Name: MAD4B Tour Form #6 Submission Mode
 * Description: Public quote-vs-request-advance readiness and distinct consent-aware Fluent Form confirmations.
 * Version: 1.3.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Form6_Submission_Mode_V1 {
 private const LANG=['en','ar','fr','de','es','it'];
 private const REDIRECT_OPTION='mad4b_form6_thankyou_redirect_v1';
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
     'advance_button'=>'Send request — advance option follows',
     'advance_note'=>'Your enquiry is saved before any payment. After submitting, the thank-you message shows a secure advance link when the estimated amount is server-verified. You may pay now or later. Payment does not confirm the trip.',
    'advance_pending_note'=>'Your enquiry is saved even when an optional service needs review. Reservations can prepare the advance payment link after checking the full estimate; submission never requires payment.',
    'advance_success'=>'Your request is saved. You can pay the requested advance now or later, if available below. This is not a confirmed booking.',
     'advance_fallback'=>'Your enquiry was saved. A secure advance link could not be issued automatically for the current estimate or selections. Reservations will review your request and prepare the payment option. No payment was taken.'
   ],
   'ar'=>[
    'quote_button'=>'اطلب عرض سعر',
    'quote_note'=>'لن يُخصم منك أي مبلغ عند إرسال هذا الطلب. سنؤكد التوافر والسعر النهائي وأي عربون مطلوب قبل أن تقرر المتابعة. إرسال الطلب لا يؤكد الحجز.',
    'quote_success'=>'استلمنا طلب عرض السعر. لم يتم تحصيل أي مبلغ. سيتحقق فريقنا من التوافر والسعر النهائي قبل تأكيد الحجز.',
     'advance_button'=>'إرسال الطلب — العربون بعد رسالة الشكر',
     'advance_note'=>'يتم حفظ الاستفسار أولًا. بعد الإرسال يظهر رابط العربون في رسالة الشكر إذا تحقق السيرفر من السعر التقديري. يمكنك الدفع الآن أو لاحقًا، ولا يعتبر الدفع تأكيدًا للحجز.',
    'advance_pending_note'=>'سيُحفظ استفسارك حتى لو كانت هناك خدمات اختيارية تحتاج مراجعة. يجهز فريق الحجوزات رابط العربون بعد مراجعة التقدير الكامل، ولا يلزم الدفع لإرسال الطلب.',
    'advance_success'=>'تم حفظ طلبك. يمكنك دفع العربون الآن أو لاحقًا عند إتاحته أدناه. الحجز غير مؤكد بعد.',
     'advance_fallback'=>'تم حفظ الاستفسار. تعذر إصدار رابط العربون تلقائيًا للخيارات أو السعر الحالي، وسيراجع فريق الحجوزات الطلب لتجهيز طريقة الدفع. لم يتم تحصيل أي مبلغ.'
   ],
   'es'=>[
    'quote_button'=>'Solicitar presupuesto',
    'quote_note'=>'No se te cobrará al enviar esta solicitud. Confirmaremos la disponibilidad, el precio final y cualquier anticipo requerido antes de que decidas continuar. Aún no es una reserva confirmada.',
    'quote_success'=>'Hemos recibido tu solicitud de presupuesto. No se ha cobrado ningún importe. Nuestro equipo revisará la disponibilidad y el precio final.',
     'advance_button'=>'Enviar solicitud — anticipo después',
     'advance_note'=>'Primero se guarda la solicitud. Tras enviarla, la pantalla de confirmación muestra el enlace de anticipo si el servidor valida el precio estimado. Puedes pagar ahora o después. El pago no confirma el viaje.',
    'advance_pending_note'=>'La solicitud se guarda aunque existan servicios por revisar. Reservas preparará el enlace del anticipo tras verificar el presupuesto completo. No se exige pago al enviar.',
    'advance_success'=>'Solicitud guardada. Puedes pagar el anticipo ahora o más tarde si está disponible a continuación. La reserva no está confirmada.',
     'advance_fallback'=>'Solicitud guardada. El enlace de anticipo requiere revisar el precio o las opciones. Reservas preparará la opción de pago. No se ha cobrado nada.'
   ],
   'fr'=>[
    'quote_button'=>'Demander un devis','quote_note'=>'Aucun paiement ne sera prélevé lors de l’envoi de cette demande. Nous confirmerons la disponibilité, le prix final et tout acompte requis avant votre décision. La réservation n’est pas confirmée.',
    'quote_success'=>'Demande de devis reçue. Aucun paiement effectué. Notre équipe vérifiera la disponibilité et le tarif final.',
     'advance_button'=>'Envoyer la demande — acompte ensuite',
     'advance_note'=>'La demande est enregistrée avant tout paiement. La page de remerciement propose un lien d’acompte si le montant estimé est vérifié. Paiement possible maintenant ou plus tard, sans confirmer le voyage.',
    'advance_pending_note'=>'La demande est conservée même si des options doivent être vérifiées. L\'équipe préparera le lien d\'acompte après contrôle du devis. Aucun paiement requis à l\'envoi.',
    'advance_success'=>'Demande enregistrée. Vous pouvez régler l’acompte maintenant ou plus tard si cette option est disponible ci-dessous.',
     'advance_fallback'=>'Demande enregistrée. Le lien d’acompte nécessite la vérification du tarif ou des options. Notre équipe préparera le paiement. Aucun prélèvement.'
   ],
   'de'=>[
    'quote_button'=>'Angebot anfordern','quote_note'=>'Beim Absenden dieser Anfrage wird nichts berechnet. Wir bestätigen Verfügbarkeit, Endpreis und eine mögliche Anzahlung, bevor Sie fortfahren. Die Buchung ist noch nicht bestätigt.',
    'quote_success'=>'Anfrage eingegangen. Es wurde nichts berechnet. Unser Team prüft Verfügbarkeit und Endpreis.',
     'advance_button'=>'Anfrage senden — Anzahlung danach',
     'advance_note'=>'Die Anfrage wird zuerst gespeichert. Nach dem Senden erscheint bei geprüftem Schätzpreis auf der Bestätigungsseite ein Zahlungslink; die Zahlung kann sofort oder später erfolgen und bestätigt keine Buchung.',
    'advance_pending_note'=>'Die Anfrage wird auch bei prüfpflichtigen Extras gespeichert. Der Zahlungslink folgt nach Prüfung des Gesamtpreises. Keine Zahlung beim Absenden erforderlich.',
    'advance_success'=>'Anfrage gespeichert. Sie können die Anzahlung jetzt oder später leisten, falls diese Option unten verfügbar ist.',
     'advance_fallback'=>'Anfrage gespeichert. Der Zahlungslink erfordert die Prüfung des Preises oder der Optionen. Unser Team bereitet ihn vor. Keine Zahlung erfolgt.'
   ],
   'it'=>[
    'quote_button'=>'Richiedi preventivo','quote_note'=>'Non ti verrà addebitato nulla all’invio della richiesta. Confermeremo disponibilità, prezzo finale ed eventuale acconto prima che tu decida di procedere. La prenotazione non è ancora confermata.',
    'quote_success'=>'Richiesta ricevuta. Nessun importo addebitato. Verificheremo disponibilità e prezzo finale.',
     'advance_button'=>'Invia richiesta — acconto dopo',
     'advance_note'=>'La richiesta viene salvata prima del pagamento. La conferma mostra il link per l’acconto se il preventivo è verificato dal server. Puoi pagare subito o più tardi, senza confermare il viaggio.',
    'advance_pending_note'=>'La richiesta viene salvata anche se alcuni servizi devono essere verificati. Il link per l\'acconto segue dopo il controllo del preventivo. Nessun pagamento all\'invio.',
    'advance_success'=>'Richiesta salvata. Puoi pagare l’acconto ora o più tardi, se disponibile di seguito.',
     'advance_fallback'=>'Richiesta salvata. Il link per l’acconto richiede la verifica del prezzo o delle opzioni. Il team preparerà il pagamento. Nessun addebito.'
   ],
  ];
  return $all[$lang]??$all['en'];
 }
 /**
  * Confirmed-submission UX only; never decides whether an advance can be
  * collected. Default remains the existing no-redirect experience.
  */
 public static function redirectSettings():array {
  $raw=get_option(self::REDIRECT_OPTION,[]);
  $raw=is_array($raw)?$raw:[];
  $mode=($raw['mode']??'stay')==='countdown'?'countdown':'stay';
  $sec=$raw['seconds']??15;
  $seconds=is_scalar($sec)&&ctype_digit((string)$sec)?(int)$sec:15;
  return ['mode'=>$mode,'seconds'=>max(1,min(15,$seconds))];
 }
 /** Settings belong in the existing WooCommerce → Settings → Request advances tab. */
 public static function redirectAdminSettings():void {
  if(!class_exists('MAD4B_Lifecycle_Access') ||
     !MAD4B_Lifecycle_Access::can('commerce_manage_policies'))return;
  $v=self::redirectSettings();
  echo '<h3>'.esc_html__('Form 6 · Thank-you payment transition','commerce-composition').'</h3>';
  echo '<p>'.esc_html__('The enquiry is always saved first. This setting only changes how a customer follows an already verified WooCommerce advance link. It never creates or captures a payment.','commerce-composition').'</p>';
  echo '<table class="form-table"><tbody><tr valign="top"><th scope="row"><label for="mad4b_form6_redirect_mode">'.
    esc_html__('After Form 6 submission','commerce-composition').'</label></th><td>';
  echo '<select name="mad4b_form6_redirect_mode" id="mad4b_form6_redirect_mode">';
  echo '<option value="stay"'.selected($v['mode'],'stay',false).'>'.esc_html__('Stay on thank-you page (no automatic redirect)','commerce-composition').'</option>';
  echo '<option value="countdown"'.selected($v['mode'],'countdown',false).'>'.esc_html__('Show a countdown, then redirect to secure advance payment','commerce-composition').'</option>';
  echo '</select><p class="description">'.esc_html__('Only when a safe verified payment URL exists. Customer can cancel and pay later.','commerce-composition').'</p></td></tr>';
  echo '<tr valign="top"><th scope="row"><label for="mad4b_form6_redirect_seconds">'.esc_html__('Countdown (seconds)','commerce-composition').'</label></th><td>';
  echo '<input type="number" id="mad4b_form6_redirect_seconds" name="mad4b_form6_redirect_seconds" min="1" max="15" step="1" class="small-text" value="'.esc_attr((string)$v['seconds']).'" />';
  echo '<p class="description">'.esc_html__('1–15 seconds; default 15. Inactive when no-redirect mode is selected.','commerce-composition').'</p></td></tr></tbody></table>';
 }
 public static function saveRedirectSettings():void {
  if(!class_exists('MAD4B_Lifecycle_Access') ||
      !MAD4B_Lifecycle_Access::can('commerce_manage_policies'))return;
  // Do not overwrite settings when any other WooCommerce tab is saved.
  if(!isset($_POST['mad4b_form6_redirect_mode']))return;
  check_admin_referer('woocommerce-settings');
  $mode=sanitize_key(wp_unslash((string)$_POST['mad4b_form6_redirect_mode']));
  $raw=wp_unslash($_POST['mad4b_form6_redirect_seconds']??'15');
  if(!in_array($mode,['stay','countdown'],true)||!is_scalar($raw)||
     !ctype_digit((string)$raw)||(int)$raw<1||(int)$raw>15){
    if(class_exists('WC_Admin_Settings'))WC_Admin_Settings::add_error(__('Invalid Form 6 thank-you redirect mode or countdown (1–15 seconds).','commerce-composition'));
    return;
  }
  update_option(self::REDIRECT_OPTION,['mode'=>$mode,'seconds'=>(int)$raw],false);
 }
 private static function redirectLabels(string $lang):array {
  $labels=[
   'en'=>['prefix'=>'Redirecting to secure advance payment in','seconds'=>'seconds','cancel'=>'Stay here · pay later','cancelled'=>'Automatic redirect cancelled. You can pay later using the secure link above.'],
   'ar'=>['prefix'=>'سيتم الانتقال إلى صفحة دفع العربون الآمنة خلال','seconds'=>'ثانية','cancel'=>'البقاء هنا · الدفع لاحقًا','cancelled'=>'أُلغي التحويل التلقائي. يمكنك الدفع لاحقًا عبر الرابط أعلاه.'],
   'es'=>['prefix'=>'Redirección al pago seguro en','seconds'=>'segundos','cancel'=>'Permanecer aquí · pagar después','cancelled'=>'Redirección cancelada. Puedes pagar después con el enlace anterior.'],
   'fr'=>['prefix'=>'Redirection vers le paiement sécurisé dans','seconds'=>'secondes','cancel'=>'Rester ici · payer plus tard','cancelled'=>'Redirection annulée. Vous pourrez payer plus tard avec le lien ci-dessus.'],
   'de'=>['prefix'=>'Weiterleitung zur sicheren Zahlung in','seconds'=>'Sekunden','cancel'=>'Hier bleiben · später zahlen','cancelled'=>'Automatische Weiterleitung abgebrochen. Spätere Zahlung über den obigen Link möglich.'],
   'it'=>['prefix'=>'Reindirizzamento al pagamento sicuro tra','seconds'=>'secondi','cancel'=>'Resta qui · paga dopo','cancelled'=>'Reindirizzamento annullato. Puoi pagare in seguito usando il link qui sopra.'],
  ];
  return $labels[$lang]??$labels['en'];
 }
 /** Language-accurate initial submit copy shared by server HTML and browser. */
 public static function travelerLabels(string $language):array {
  return self::wording(self::lang($language));
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
    // Checkout viability can originate from an eligible native Tour Rate OR
    // a valid Direct price governed by the company's weighted source policy.
    // This is a readiness hint, never an order or trusted payment amount.
    $router='\Commerce\Composition\SingleFluentPaymentRouter';
    $explicitInquiry=get_post_meta($tour,'_shared_tour_price_entry_mode_v1',true)==='inquiry';
    if($available && !$explicitInquiry &&
       ($router::rates($tour) || $router::directSourceCandidate($tour)))
      $mode='advance_checkout';
  }
  return ['mode'=>$mode,'requires_server_rate_verification'=>true,'requires_server_price_verification'=>true,
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
   'lang'=>self::lang(apply_filters('wpml_current_language','en')),
   'labels'=>self::travelerLabels(self::lang(apply_filters('wpml_current_language','en')))
  ],JSON_UNESCAPED_SLASHES).';','before');
  wp_enqueue_style($handle,content_url('mu-plugins/assets/mad4b-tour-form6-submission-mode-v1.css'),
    [],(string)filemtime($css));
 }
 public static function confirmation($response,$form,$confirmation=null,$sid=null,$data=null){
  if(!is_array($response)||!is_object($form)||(int)($form->id??0)!==6||
      !is_numeric($sid)||((int)$sid)<1||
      ($response['success']??true)===false)return $response;
  // The enquiry is saved BEFORE preparing the advance. A provider failure
  // must never force redirect or suppress the thank-you acknowledgement.
  unset($response['redirectUrl']);
  if(!class_exists('\\Commerce\\Composition\\RequestAdvancePayments'))return $response;
  $context=\Commerce\Composition\RequestAdvancePayments::submissionContext(6,(int)$sid);
  if(is_wp_error($context)||get_post_type($context['source_id']??0)!=='tours-and-activities')return $response;
  $lang=self::lang(apply_filters('wpml_current_language','en'));
  $copy=self::wording($lang);
  // Never force a redirect after a successful Fluent Form submission.
  // We only display the payment option *after* the request is committed.
  // We do not create an order or charge from this confirmation callback.
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
  $safeUrl=$order instanceof \WC_Order && class_exists('\\Commerce\\Composition\\RequestAdvancePayments')
    ?\Commerce\Composition\RequestAdvancePayments::safePaymentUrl($order):null;
  $isPayable=!empty($safeUrl) && $current['mode']==='advance_checkout';
  $receivedCopy=[
   'en'=>'Your advance has been received. The trip is still awaiting final confirmation.',
   'ar'=>'تم استلام العربون. الرحلة ما زالت في انتظار التأكيد النهائي.',
   'es'=>'Hemos recibido tu anticipo. El viaje sigue pendiente de confirmación.',
   'fr'=>'Votre acompte a été reçu. Le voyage attend toujours la confirmation finale.',
   'de'=>'Ihre Anzahlung ist eingegangen. Die Reise muss noch endgültig bestätigt werden.',
   'it'=>'Abbiamo ricevuto l’acconto. Il viaggio resta in attesa di conferma definitiva.'
  ];
  // The original saved request determines the explanation, never a browser estimate.
  $fallback=$copy['advance_fallback'];
  if(!$order && $current['mode']==='advance_checkout' &&
     class_exists('\Commerce\Composition\SingleFluentPaymentRouter')){
    $window=\Commerce\Composition\SingleFluentPaymentRouter::submissionWindow($context);
    if(is_array($window)&&($window['reason']??'')==='short_notice_written_schedule_required'){
      $shortNoticeCopy=[
       'en'=>'Your request was saved. Your arrival is inside the normal balance-payment deadline. A revised payment schedule must be agreed in writing before an advance link can be issued. No payment was taken.',
       'ar'=>'تم حفظ طلبك. تاريخ الوصول داخل مهلة سداد الرصيد المعتادة. يجب الاتفاق كتابيًا على جدول دفع معدل قبل إصدار رابط العربون. لم يتم تحصيل أي مبلغ.',
       'es'=>'Hemos guardado tu solicitud. La llegada es anterior al plazo habitual del saldo. Debe acordarse por escrito un nuevo calendario de pagos antes de emitir un enlace de anticipo. No se ha cobrado nada.',
       'fr'=>'Votre demande est enregistrée. Votre arrivée est dans le délai habituel de règlement du solde. Un nouvel échéancier doit être convenu par écrit avant tout lien de paiement. Aucun prélèvement.',
       'de'=>'Ihre Anfrage ist gespeichert. Die Anreise liegt innerhalb der üblichen Restzahlungsfrist. Vor einem Anzahlungslink muss ein neuer Zahlungsplan schriftlich vereinbart werden. Keine Zahlung erfolgt.',
       'it'=>'La richiesta è salvata. L’arrivo rientra nella normale scadenza del saldo. Prima del link di acconto occorre concordare per iscritto un nuovo piano di pagamento. Nessun addebito.'
      ];
      $fallback=$shortNoticeCopy[$lang]??$shortNoticeCopy['en'];
    }
  }
  $message=$order && $order->get_meta('_request_collection_state')==='received'
    ? ($receivedCopy[$lang]??$receivedCopy['en'])
    :($isPayable?$copy['advance_success']:($current['mode']==='advance_checkout'
      ? $fallback:$copy['quote_success']));
  // An approved full-due short-notice request is 100% payable, not a 50% deposit.
  // Only an existing verified Woo payment order authorizes this copy.
  if($isPayable && $order instanceof \WC_Order &&
     \Commerce\Composition\RequestAdvancePayments::isFullDueNow($order)){
    $full=[
      'en'=>['payment'=>'Full Est. Payment Due (100%)','pay'=>'Pay the full estimated amount','message'=>'Your request is saved. Under the current payment policy, 100% of the verified estimated request amount plus applicable taxes is due. Paying does not confirm availability or the booking.'],
      'ar'=>['payment'=>'كامل المبلغ التقديري مستحق (100%)','pay'=>'دفع كامل المبلغ التقديري','message'=>'تم حفظ الطلب. وفق سياسة الدفع السارية، يستحق كامل المبلغ المرجعي الموثق (100%) بالإضافة إلى الضرائب. الدفع لا يؤكد التوافر أو الحجز.'],
      'es'=>['payment'=>'Pago estimado total requerido (100%)','pay'=>'Pagar el importe completo','message'=>'Solicitud guardada. La política de pago vigente requiere el 100% del importe estimado verificado, más impuestos. El pago no confirma la disponibilidad ni la reserva.'],
      'fr'=>['payment'=>'Paiement intégral estimé requis (100%)','pay'=>'Payer la totalité','message'=>'Demande enregistrée. La politique de paiement actuelle exige 100% du montant estimé vérifié, plus les taxes. Le paiement ne confirme pas la réservation.'],
      'de'=>['payment'=>'Geschätzte Vollzahlung fällig (100%)','pay'=>'Gesamtbetrag sicher bezahlen','message'=>'Anfrage gespeichert. Gemäß aktueller Zahlungsrichtlinie sind 100% des geprüften Schätzbetrags zuzüglich Steuern fällig. Zahlung bestätigt keine Buchung.'],
      'it'=>['payment'=>'Pagamento integrale stimato richiesto (100%)','pay'=>'Paga l’intero importo','message'=>'Richiesta salvata. Secondo la politica di pagamento vigente è dovuto il 100% dell’importo stimato verificato più le imposte. Il pagamento non conferma la prenotazione.']
    ];
    $wording=$full[$lang]??$full['en'];
    $display['payment']=$wording['payment'];
    $display['pay']=$wording['pay'];
    $message=$wording['message'];
  }
  // Show the saved policy percentage beside the optional payment in the
  // THANK-YOU panel. Never derive it from the browser or today's changed policy.
  if($isPayable && $order instanceof \WC_Order &&
     !\Commerce\Composition\RequestAdvancePayments::isFullDueNow($order)){
    $pct=\Commerce\Composition\RequestAdvancePayments::receiptPercentage($order);
    if($pct!==null)
      $display['payment'].=' ('.rtrim(rtrim(number_format($pct,2,'.',''),'0'),'.').'%)';
  }
  $reference=$order?(string)$order->get_id():('FF-'.(int)$sid);
  $html='<div class="mad4b-request-confirmation" role="status" aria-live="polite">';
  $html.='<h3>'.esc_html($display['title']).'</h3>';
  $html.='<p>'.esc_html($message).'</p>';
  $html.='<p><strong>'.esc_html($display['reference']).': '.esc_html($reference).'</strong></p>';
  if($isPayable && $order instanceof \WC_Order){
    // Only the existing WooCommerce order is allowed to issue a bearer pay URL.
    $url=$safeUrl;
    if(is_string($url)&&$url!==''){
      $html.='<section class="mad4b-request-payment-panel">';
      $html.='<h4>'.esc_html($display['payment']).'</h4>';
      $html.='<div class="mad4b-request-advance-amount">'.wp_kses_post(wc_price(
        (float)$order->get_total(),['currency'=>$order->get_currency()])).'</div>';
      $html.='<a class="mad4b-request-pay-link" href="'.esc_url($url).'">'.
        esc_html($display['pay']).'</a>';
      // Optional transition runs only after a committed Fluent submission and
      // a checked, same-origin Woo URL; no URL is embedded in timer attributes.
      $transition=self::redirectSettings();
      if($transition['mode']==='countdown'){
        $words=self::redirectLabels($lang);
        $html.='<div class="mad4b-form6-payment-countdown" data-mad4b-redirect-seconds="'.
          esc_attr((string)$transition['seconds']).'">';
        $html.='<p class="mad4b-form6-countdown-message">'.esc_html($words['prefix']).
          ' <strong class="mad4b-form6-countdown-number">'.esc_html((string)$transition['seconds']).'</strong> '.
          esc_html($words['seconds']).'</p>';
        $html.='<button type="button" class="mad4b-form6-countdown-cancel">'.esc_html($words['cancel']).'</button>';
        $html.='<p class="mad4b-form6-countdown-cancelled" hidden>'.esc_html($words['cancelled']).'</p>';
        $html.='</div>';
      }
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
  add_action('woocommerce_settings_tabs_request_advances',[self::class,'redirectAdminSettings'],90);
  add_action('woocommerce_update_options_request_advances',[self::class,'saveRedirectSettings'],90);
 }
}
MAD4B_Tour_Form6_Submission_Mode_V1::bootstrap();
