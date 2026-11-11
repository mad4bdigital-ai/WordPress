<?php
/**
 * Plugin Name: MAD4B Tour Form #6 Submission Mode
 * Description: Public quote-vs-request-advance readiness and distinct consent-aware Fluent Form confirmations.
 * Version: 1.0.0
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
    'advance_button'=>'Submit request & continue to secure advance checkout',
    'advance_note'=>'An advance is collected at secure checkout only after our server approves the request. Paying an advance does not confirm the trip or final price.',
    'advance_success'=>'Request received. Continue to secure checkout to review a non-final booking advance. Availability and final trip price remain subject to agreement.',
    'advance_fallback'=>'Request received, but advance checkout is not available for this request. No payment was taken. Our team will review the request and contact you.'
   ],
   'ar'=>[
    'quote_button'=>'اطلب عرض سعر',
    'quote_note'=>'لن يُخصم منك أي مبلغ عند إرسال هذا الطلب. سنؤكد التوافر والسعر النهائي وأي عربون مطلوب قبل أن تقرر المتابعة. إرسال الطلب لا يؤكد الحجز.',
    'quote_success'=>'استلمنا طلب عرض السعر. لم يتم تحصيل أي مبلغ. سيتحقق فريقنا من التوافر والسعر النهائي قبل تأكيد الحجز.',
    'advance_button'=>'أرسل الطلب وتابع إلى دفع العربون الآمن',
    'advance_note'=>'يُحصّل العربون في صفحة الدفع فقط بعد اعتماد الطلب من الخادم. دفع العربون لا يؤكد الرحلة أو السعر النهائي.',
    'advance_success'=>'استلمنا الطلب. تابع إلى صفحة الدفع لمراجعة العربون غير النهائي. التوافر والسعر النهائي يخضعان للموافقة.',
    'advance_fallback'=>'استلمنا الطلب، لكن دفع العربون غير متاح له حاليًا. لم يتم تحصيل أي مبلغ. سيتواصل معك فريقنا بعد المراجعة.'
   ],
   'es'=>[
    'quote_button'=>'Solicitar presupuesto',
    'quote_note'=>'No se te cobrará al enviar esta solicitud. Confirmaremos la disponibilidad, el precio final y cualquier anticipo requerido antes de que decidas continuar. Aún no es una reserva confirmada.',
    'quote_success'=>'Hemos recibido tu solicitud de presupuesto. No se ha cobrado ningún importe. Nuestro equipo revisará la disponibilidad y el precio final.',
    'advance_button'=>'Enviar solicitud y continuar al pago seguro del anticipo',
    'advance_note'=>'El anticipo se cobra en la página segura solo tras validar la solicitud. El pago no confirma el viaje ni su precio final.',
    'advance_success'=>'Solicitud recibida. Continúa al pago seguro para revisar un anticipo no definitivo; el viaje sigue pendiente de confirmación.',
    'advance_fallback'=>'Solicitud recibida, pero el pago del anticipo no está disponible. No se ha cobrado nada. Nuestro equipo te contactará.'
   ],
   'fr'=>[
    'quote_button'=>'Demander un devis','quote_note'=>'Aucun paiement ne sera prélevé lors de l’envoi de cette demande. Nous confirmerons la disponibilité, le prix final et tout acompte requis avant votre décision. La réservation n’est pas confirmée.',
    'quote_success'=>'Demande de devis reçue. Aucun paiement effectué. Notre équipe vérifiera la disponibilité et le tarif final.',
    'advance_button'=>'Envoyer la demande et passer au paiement sécurisé de l’acompte',
    'advance_note'=>'L’acompte est prélevé après validation sur la page sécurisée. Il ne confirme ni le voyage ni le prix final.',
    'advance_success'=>'Demande reçue. Continuez vers le paiement sécurisé de l’acompte non définitif.',
    'advance_fallback'=>'Demande reçue, mais l’acompte est indisponible. Aucun paiement prélevé. Notre équipe vous contactera.'
   ],
   'de'=>[
    'quote_button'=>'Angebot anfordern','quote_note'=>'Beim Absenden dieser Anfrage wird nichts berechnet. Wir bestätigen Verfügbarkeit, Endpreis und eine mögliche Anzahlung, bevor Sie fortfahren. Die Buchung ist noch nicht bestätigt.',
    'quote_success'=>'Anfrage eingegangen. Es wurde nichts berechnet. Unser Team prüft Verfügbarkeit und Endpreis.',
    'advance_button'=>'Anfrage senden und zur sicheren Anzahlungsseite wechseln',
    'advance_note'=>'Eine Anzahlung wird erst nach Serverprüfung auf der sicheren Zahlungsseite erhoben. Keine Buchungsbestätigung.',
    'advance_success'=>'Anfrage eingegangen. Weiter zur sicheren Anzahlungsseite. Der Reiseendpreis bleibt offen.',
    'advance_fallback'=>'Anfrage eingegangen, Anzahlung derzeit nicht möglich. Keine Zahlung erfolgt. Wir melden uns.'
   ],
   'it'=>[
    'quote_button'=>'Richiedi preventivo','quote_note'=>'Non ti verrà addebitato nulla all’invio della richiesta. Confermeremo disponibilità, prezzo finale ed eventuale acconto prima che tu decida di procedere. La prenotazione non è ancora confermata.',
    'quote_success'=>'Richiesta ricevuta. Nessun importo addebitato. Verificheremo disponibilità e prezzo finale.',
    'advance_button'=>'Invia richiesta e vai al pagamento sicuro dell’acconto',
    'advance_note'=>'L’acconto viene riscosso alla cassa sicura solo dopo la verifica. Non conferma il viaggio né il prezzo finale.',
    'advance_success'=>'Richiesta ricevuta. Prosegui al pagamento sicuro per l’acconto non definitivo.',
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
  if(!empty($response['redirectUrl'])){
   // This URL can only originate from a successfully prepared Woo advance
   // order; the existing Woo validation and payment consent remain required.
   $response['message']=$copy['advance_success'];return $response;
  }
  $current=self::inspect((int)$context['source_id']);
  $response['message']=$current['mode']==='advance_checkout'
    ?$copy['advance_fallback']:$copy['quote_success'];
  unset($response['redirectUrl']);
  return $response;
 }
 public static function bootstrap():void {
  add_action('rest_api_init',[self::class,'route']);
  add_action('wp_enqueue_scripts',[self::class,'enqueue'],40);
  add_filter('fluentform/submission_confirmation',[self::class,'confirmation'],10040,5);
 }
}
MAD4B_Tour_Form6_Submission_Mode_V1::bootstrap();
