<?php
/** Plugin Name: MAD4B Contact Company Mail & Multilingual Form
 * Description: Scoped delivery for standalone Contact Us form; no admin-email fallback.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Company_Contact_Delivery {
 public static function configured_form():int {return (int)((array)get_option('mad4b_contact_builder_v1',[]))['general_contact_form_id'];}
 public static function recipient():string {
  $o=(array)get_option('contact-and-help-info',[]);
  $s=sanitize_email((string)($o['site_email']??''));return is_email($s)?$s:'';
 }
 public static function render():string {
  $o=(array)get_option('look-and-feel',[]);
  $shortcode=trim((string)($o['contact_page_fluent_shortcode']??''));
  if(!preg_match('/^\[fluentform\s+id=["\']?([1-9][0-9]{0,6})["\']?\s*\]$/i',$shortcode,$m))return '';
  $formId=(int)$m[1];
  // A changed shortcode is a request to select another form, not automatic
  // permission to use an unrelated form with potentially inherited mail feeds.
  if($formId!==self::configured_form())return current_user_can('manage_options')
   ?'<div class="mad4b-contact-form-not-ready" role="status">Selected Fluent Form requires company routing verification before activation.</div>':'';
  // Fail-closed public contact intake until this EXACT Fluent Form's
  // submission-insert hook and company mail receipt pass the live E2E test.
  if(get_option('mad4b_company_contact_form_public_enabled_v1','no')!=='yes'){
   $lang=(string)apply_filters('wpml_current_language',null);
   $messages=['en'=>'Contact messages are temporarily unavailable. Please use the company email or phone listed here.',
    'es'=>'El formulario de contacto no está disponible temporalmente. Utiliza el correo o teléfono indicados aquí.',
    'fr'=>'Le formulaire de contact est temporairement indisponible. Veuillez utiliser nos coordonnées.',
    'de'=>'Das Kontaktformular ist vorübergehend nicht verfügbar. Bitte nutzen Sie unsere Kontaktdaten.',
    'it'=>'Il modulo di contatto è temporaneamente indisponibile. Usa i nostri recapiti.',
    'ar'=>'نموذج التواصل غير متاح مؤقتًا. يُرجى استخدام البريد أو الهاتف المذكورين هنا.'];
   return '<div class=\"mad4b-contact-form-not-ready\" role=\"status\">'.esc_html($messages[$lang]??$messages['en']).'</div>';
  }
  // The editor may select another existing, published form through Look & Feel.
  global $wpdb;$f=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$formId),ARRAY_A);
  if(!$f||$f['status']!=='published')return '';
  // Inbound Contact Us is operational company mail, not a WordPress-core
  // system message. Defer collection until original company sender policy
  // certifies transport and authorizes the company outbound lane.
  $companyRouteReady=class_exists('MAD4B_SCP_Company_Communication',false)
    && MAD4B_SCP_Company_Communication::canSend()
    && class_exists('MAD4B_SCP_Company_Email_Routing',false)
    && MAD4B_SCP_Company_Email_Routing::identity()['verified'];
  if(self::recipient()==='' || !$companyRouteReady){
   $locale=apply_filters('wpml_current_language',null);
   $texts=['en'=>'Contact messaging is being configured.','ar'=>'جارٍ تجهيز استقبال رسائل التواصل.','fr'=>'La messagerie de contact est en cours de configuration.','de'=>'Das Kontaktformular wird eingerichtet.','es'=>'Estamos configurando el formulario de contacto.','it'=>'Il modulo di contatto è in fase di configurazione.'];
   return '<div class="mad4b-contact-form-not-ready" role="status">'.esc_html($texts[$locale]??$texts['en']).'</div>';
  }
  return '<div class="mad4b-contact-fluent-form" data-contact-form-id="'.(int)$formId.'">'.do_shortcode('[fluentform id="'.$formId.'"]').'</div>';
 }
 public static function details():string {
  $out='';foreach(['address','phone','email','whatsapp','hours','map'] as $field)$out.=MAD4B_Contact_Page_Dynamic::field(['field'=>$field]);
  return $out!==''?'<div class="mad4b-company-contact-details">'.$out.'</div>':'';
 }
 public static function localize($form){
  if(!is_object($form)||(int)($form->id??0)!==self::configured_form())return $form;
  $lang=(string)apply_filters('wpml_current_language',null);
  $all=[
   'en'=>['first_name'=>'First name','last_name'=>'Last name','email'=>'Email','subject'=>'Subject','contact_phone'=>'Phone (optional)','message'=>'Your message','submit'=>'Send message'],
   'ar'=>['first_name'=>'الاسم الأول','last_name'=>'اسم العائلة','email'=>'البريد الإلكتروني','subject'=>'الموضوع','contact_phone'=>'الهاتف (اختياري)','message'=>'رسالتك','submit'=>'إرسال الرسالة'],
   'fr'=>['first_name'=>'Prénom','last_name'=>'Nom','email'=>'E-mail','subject'=>'Objet','contact_phone'=>'Téléphone (facultatif)','message'=>'Votre message','submit'=>'Envoyer le message'],
   'de'=>['first_name'=>'Vorname','last_name'=>'Nachname','email'=>'E-Mail','subject'=>'Betreff','contact_phone'=>'Telefon (optional)','message'=>'Ihre Nachricht','submit'=>'Nachricht senden'],
   'es'=>['first_name'=>'Nombre','last_name'=>'Apellido','email'=>'Correo electrónico','subject'=>'Asunto','contact_phone'=>'Teléfono (opcional)','message'=>'Tu mensaje','submit'=>'Enviar mensaje'],
   'it'=>['first_name'=>'Nome','last_name'=>'Cognome','email'=>'E-mail','subject'=>'Oggetto','contact_phone'=>'Telefono (facoltativo)','message'=>'Il tuo messaggio','submit'=>'Invia messaggio']];
  $v=$all[$lang]??$all['en'];
  if(!isset($form->fields['fields'])||!is_array($form->fields['fields']))return $form;
  foreach($form->fields['fields'] as &$f){$k=(string)($f['attributes']['name']??'');
   if($k==='names'&&isset($f['fields'])&&is_array($f['fields'])){
    foreach(['first_name','last_name'] as $sub)if(isset($f['fields'][$sub])){$f['fields'][$sub]['settings']['label']=$v[$sub];$f['fields'][$sub]['attributes']['placeholder']=$v[$sub];}
   }elseif(isset($v[$k])){$f['settings']['label']=$v[$k];$f['attributes']['placeholder']=$v[$k];}
  }unset($f);
  if(isset($form->fields['submitButton']['settings']))$form->fields['submitButton']['settings']['button_ui']['text']=$v['submit'];
  return $form;
 }
 public static function title($attrs=[]):string {
  $a=shortcode_atts(['key'=>'contact_page_heading','tag'=>'h2'],(array)$attrs);
  $key=(string)$a['key'];$tag=(string)$a['tag'];
  if(!in_array($key,['contact_page_heading','contact_page_intro','contact_page_info_heading','contact_page_form_heading'],true) || !in_array($tag,['h1','h2','p'],true))return '';
  $default=[
   'contact_page_heading'=>['en'=>'Contact Us','es'=>'Contacto','fr'=>'Contactez-nous','de'=>'Kontakt','it'=>'Contattaci','ar'=>'اتصل بنا'],
   'contact_page_intro'=>['en'=>'We’d love to hear from you. Tell us how we can help with your trip.','es'=>'Nos encantaría saber de ti. Cuéntanos cómo podemos ayudarte con tu viaje.','fr'=>'Nous serions ravis de vous lire. Dites-nous comment nous pouvons vous aider à organiser votre voyage.','de'=>'Wir freuen uns auf Ihre Nachricht. Sagen Sie uns, wie wir Ihnen bei Ihrer Reise helfen können.','it'=>'Ci farebbe piacere sentirti. Raccontaci come possiamo aiutarti con il tuo viaggio.','ar'=>'يسعدنا التواصل معك. أخبرنا كيف يمكننا مساعدتك في رحلتك.'],
   'contact_page_info_heading'=>['en'=>'Get in touch','es'=>'Ponte en contacto','fr'=>'Nos coordonnées','de'=>'Kontaktinformationen','it'=>'Contattaci','ar'=>'بيانات التواصل'],
   'contact_page_form_heading'=>['en'=>'Send us a message','es'=>'Envíanos un mensaje','fr'=>'Envoyez-nous un message','de'=>'Senden Sie uns eine Nachricht','it'=>'Inviaci un messaggio','ar'=>'أرسل لنا رسالة']
  ];
  $lang=(string)apply_filters('wpml_current_language',null);
  $lang=isset($default[$key][$lang])?$lang:'en';
  $opts=(array)get_option('look-and-feel',[]);
  $raw=trim((string)($opts[$key]??''));$en=$default[$key]['en'];
  $translated=$raw!==''?apply_filters('wpml_translate_single_string',$raw,'MAD4B Contact Us',$key,$lang):'';
  $originalDefaults=[
   'contact_page_heading'=>'Contact Us',
   'contact_page_intro'=>'We’re here to help with your travel plans. Send us a message and our team will reply.',
   'contact_page_info_heading'=>'Get in touch',
   'contact_page_form_heading'=>'Send us a message'
  ];
  if($translated==='' || ($lang!=='en'&&$translated===$raw&&$raw===$originalDefaults[$key]))
   $translated=$default[$key][$lang];
  if($translated==='')return '';
  return '<'.$tag.' class="mad4b-contact-heading mad4b-contact-'.$key.'">'.esc_html($translated).'</'.$tag.'>';
 }
 public static function route_ready():bool {
  if(get_option('mad4b_company_contact_form_public_enabled_v1','no')!=='yes'
     || self::recipient()===''
     || !class_exists('MAD4B_SCP_Company_Communication',false)
     || !MAD4B_SCP_Company_Communication::canSend()
     || !class_exists('MAD4B_SCP_Company_Email_Routing',false)
     || !MAD4B_SCP_Company_Email_Routing::identity()['verified'])return false;
  $config=MAD4B_SCP_Company_Communication::config();
  if(($config['email_transport']??'')==='wordpress_native')return true;
  if(($config['email_transport']??'')!=='fluent_smtp'
     ||!function_exists('fluentMailGetSettings')
     ||!class_exists('FluentMail\\App\\Models\\Settings'))return false;
  $settings=fluentMailGetSettings();$to=self::recipient();
  $id=\FluentMail\App\Models\Settings::findMappingKey((array)($settings['mappings']??[]),$to);
  return !!$id && strcasecmp((string)($settings['connections'][$id]['provider_settings']['sender_email']??''),$to)===0;
 }
 /** Rejection happens before the Fluent Forms row is inserted. */
 public static function validate_submission($errors,$data,$form,$fields){
  if(!is_object($form)||(int)($form->id??0)!==self::configured_form())return $errors;
  if(self::route_ready())return $errors;
  if(!is_array($errors))$errors=[];
  $lang=(string)apply_filters('wpml_current_language',null);
  $texts=['en'=>'Contact messaging is temporarily unavailable. Please use our listed email or telephone.',
   'es'=>'El formulario no está disponible temporalmente. Utiliza nuestro correo o teléfono.',
   'fr'=>'Le formulaire est temporairement indisponible. Veuillez nous contacter par courriel ou téléphone.',
   'de'=>'Das Formular ist vorübergehend nicht verfügbar. Nutzen Sie bitte E-Mail oder Telefon.',
   'it'=>'Il modulo è temporaneamente indisponibile. Usa la nostra email o il telefono.',
   'ar'=>'نموذج التواصل غير متاح مؤقتًا. يرجى استخدام البريد أو الهاتف.'];
  $text=$texts[$lang]??$texts['en'];
  $errors['message'][]=$text;
  return $errors;
 }
 public static function submitted(int $entryId,array $data,$form):void {
  if(!is_object($form)||(int)($form->id??0)!==self::configured_form())return;
  $to=self::recipient();
  if(!self::route_ready() || !$to || !class_exists('MAD4B_SCP_Company_Communication',false)
    || !MAD4B_SCP_Company_Communication::canSend()
    || !class_exists('MAD4B_SCP_Company_Email_Routing',false)
    || !MAD4B_SCP_Company_Email_Routing::identity()['verified'])return;
  // A certified native transport may not expose FluentSMTP mappings.
  // FluentSMTP route validation has already been performed in route_ready().
  $key='mad4b_company_contact_mail_'.$entryId;
  if(!add_option($key,['state'=>'IN_PROGRESS','at'=>gmdate('c')],'','no'))return;
  $name=$data['names']??[];
  $name=is_array($name)?trim(sanitize_text_field((string)($name['first_name']??'')).' '.sanitize_text_field((string)($name['last_name']??''))):sanitize_text_field((string)$name);
  $email=sanitize_email((string)($data['email']??''));
  $subject=sanitize_text_field((string)($data['subject']??''));
  $headers=['Content-Type: text/plain; charset=UTF-8',
    'X-MAD4B-Message-Scope: company',
    'From: '.str_replace(["\r","\n","<",">"],'',sanitize_text_field((string)(MAD4B_SCP_Company_Communication::brandIdentity()['brand_name']??get_option('blogname')))).' Support <'.$to.'>'];
  if(is_email($email))$headers[]='Reply-To: '.$email;
  $body="Contact Us inquiry\nName: $name\nEmail: $email\nPhone: ".sanitize_text_field((string)($data['contact_phone']??''))."\nMessage:\n".sanitize_textarea_field((string)($data['message']??''))."\nEntry ID: $entryId\n";
  $accepted=wp_mail($to,'New Contact Us inquiry'.($subject?' — '.$subject:''),$body,$headers);
  update_option($key,['state'=>$accepted?'MAIL_HANDOFF_ACCEPTED':'MAIL_HANDOFF_FAILED','form_id'=>(int)$form->id,'entry_id'=>$entryId,'at'=>gmdate('c'),'recipient_sha256'=>hash('sha256',strtolower($to))],false);
 }
 public static function status():array {
  $registry=(array)get_option('mad4b_contact_builder_v1',[]);
  $pages=(array)($registry['published_page_ids']??[]);
  $templates=(array)($registry['templates']??[]);
  $menus=(array)($registry['nav_items']??[]);
  $pageStates=[];$menuStates=[];$templateConditions=[];
  foreach($pages as $lang=>$id){
   $pageStates[$lang]=get_post_status((int)$id)?:'MISSING';
   $mid=(int)($menus[$lang]??0);
   $menuStates[$lang]=$mid?(get_post_status($mid)?:'MISSING'):'NOT_CONFIGURED';
   $tpl=(int)($templates[$lang]??0);
   $templateConditions[$lang]=$tpl?get_post_meta($tpl,'_elementor_conditions',true):[];
  }
  $ready=self::route_ready();
  return ['contract'=>'mad4b.contact-us-delivery-readiness.v1',
    'site'=>home_url('/'),'company_email_configured'=>self::recipient()!=='',
    'form_submission_enabled_from_dynamic_contact_page'=>$ready && self::configured_form()>0,
    'form_id'=>self::configured_form(),
    'page_statuses'=>$pageStates,'template_conditions'=>$templateConditions,
    'menu_item_statuses'=>$menuStates,
    'blocking_reason'=>$ready?'':(self::recipient()===''?'COMPANY_CONTACT_EMAIL_NOT_CONFIGURED':'FORM_MAIL_ROUTE_NOT_READY_OR_NOT_PUBLIC'),
    'admin_email_used_as_fallback'=>false,'mail_delivery_tested'=>false,
    'read_only'=>true];
 }
 public static function cli_status($args):void {
  if(($args[0]??'status')!=='status')\WP_CLI::error('Read-only contact status only');
  \WP_CLI::line(wp_json_encode(self::status(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function admin_notice():void {
  $r=(array)get_option('mad4b_contact_builder_v1',[]);
  if(!$r || !current_user_can('manage_options') || self::route_ready())return;
  $p=(string)($_GET['page']??'');
  if(!str_contains($p,'look-and-feel') && !str_contains($p,'contact-and-help-info') && $p!=='mad4b-content-readiness-alerts')return;
  echo '<div class="notice notice-warning"><p><strong>MAD4B Contact Us:</strong> '.
    'The company contact email or live delivery route is not ready. '.
    'The Contact Us pages remain visible, but submission is disabled until the company mail route passes testing and is explicitly enabled. '.
    'No fallback to the central MAD4B administrator inbox is permitted.</p></div>';
 }
 public static function bootstrap():void {
  add_shortcode('mad4b_contact_form',[__CLASS__,'render']);
  if(defined('WP_CLI') && WP_CLI)\WP_CLI::add_command('mad4b contact-us',[__CLASS__,'cli_status']);
  add_action('admin_notices',[__CLASS__,'admin_notice']);
  add_shortcode('mad4b_contact_details',[__CLASS__,'details']);
  add_shortcode('mad4b_contact_title',[__CLASS__,'title']);
  add_filter('fluentform/rendering_form',[__CLASS__,'localize'],25,1);
  add_filter('fluentform/validation_errors',[__CLASS__,'validate_submission'],20,4);
  add_action('fluentform/submission_inserted',[__CLASS__,'submitted'],20,3);
 }
}
add_action('init',[MAD4B_Company_Contact_Delivery::class,'bootstrap'],25);
