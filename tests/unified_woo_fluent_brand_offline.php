<?php
/** Networkless test of existing Woo/Fluent presentation hooks. */
define('ARRAY_A','ARRAY_A');
ob_start();
require __DIR__.'/company_branded_email_offline.php';
ob_end_clean();
function esc_url($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function add_action($hook,$fn,$priority=10,$args=1) {
 $GLOBALS['actions'][$hook][$priority]=$fn;
}
$GLOBALS['sourceLang']='en';
$GLOBALS['foreignLogo']=false;
$GLOBALS['identityValid']=true;
$GLOBALS['tenantHost']='wayfatours.com';
$GLOBALS['mode']=1;
class MailCaptureDatabase {
 public $prefix='wp_';
 public function prepare($sql,...$args) { return [$sql,$args]; }
 public function get_row($params,$output) {
  return ['form_id'=>2,'source_url'=>'https://wayfatours.com/es/contact/','status'=>'read'];
 }
}
$GLOBALS['wpdb']=new MailCaptureDatabase();
require dirname(__DIR__).'/wp-content/plugins/commerce-composition/includes/RequestLifecycle.php';
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-company-email-experience-bridge-v1.php';
$bridge=MAD4B_Company_Email_Experience_Bridge_V1::class;
function t($ok,$info){if(!$ok)throw new RuntimeException('FAIL '.$info);}
t(count($GLOBALS['actions']['woocommerce_email_header'])===2,'Woo header lifecycle scopes hooks');
t(count($GLOBALS['hooks']['woocommerce_email_styles'])===1,'Woo styling hook registered');
t(count($GLOBALS['hooks']['fluentform/email_template_colors'])===1,'Fluent style hook registered');
$brand=MAD4B_Company_Email_Brand_V1::resolve();
$style=$bridge::wooStyles('#base{color:purple;}',null);
t(str_contains($style,'#102D40'),'Woo active brand color overrides purple');
t(str_contains($style,'#B48A46'),'Woo accent inherited');
$bridge::wooHeader('Some email heading',null);
$branded=apply_filters('woocommerce_email_header_image_url',home_url('/'));
t($branded===home_url('/'),'Woo logo URL stays on same company');
t(isset($GLOBALS['hooks']['pre_option_woocommerce_email_header_image'][15]) && $GLOBALS['hooks']['pre_option_woocommerce_email_header_image'][15](false)===$brand['logo_url'],'Woo header image is tenant-scoped');
$bridge::wooHeaderCleanup();
t(empty($GLOBALS['hooks']['pre_option_woocommerce_email_header_image'][15]),'Woo header option restored');
$footer=$bridge::wooFooter('global shop footer');
t(str_contains($footer,'Wayfa Tours. All rights reserved.'),'Woo native footer replaced with brand legal');
t(str_contains($footer,home_url('/privacy/')),'Woo default-language privacy link');

$manual=(object)['id'=>'mad4b_request_lifecycle'];
$bridge::wooHeader('Manual request',$manual);
$manualFooter=$bridge::wooFooter('EXISTING_LOCALIZED_FOOTER',null);
t($manualFooter==='EXISTING_LOCALIZED_FOOTER',
  'Manual Woo lifecycle keeps its own translated footer only');
$bridge::wooHeaderCleanup();
class WC_Order {
 public function get_meta($key,$single=true){return $key==='_wpml_language'?'es':'';}
}
$native=(object)['id'=>'customer_processing_order','object'=>new WC_Order()];
$bridge::wooHeader('Spanish order status',$native);
$nativeFooter=$bridge::wooFooter('Native Woo footer',null);
t(str_contains($nativeFooter,home_url('/es/')) && str_contains($nativeFooter,'Privacidad'),
  'Native Woo footer reads verified stored order language');
$bridge::wooHeaderCleanup();
t(empty($GLOBALS['hooks']['pre_option_blogname'][15]),
  'Native Woo name override cleaned after header');

$form=(object)['id'=>2];$notification=['name'=>'Contact receipt','sendTo'=>['type'=>'field']];
$css=$bridge::fluentColors(['background_color'=>'#f6f6f6','base_color'=>'#444444']);
t($css['base_color']==='#102D40','Fluent colors inherit Elementor');
t($bridge::fluentHeaderImage(false,$form,$notification)===$brand['logo_url'],'Fluent uses approved logo');
t($bridge::fluentHeading(false,$form,$notification)==='Wayfa Tours','Fluent brand heading');
$bridge::fluentMessage('Your request',3001,[], $form);
$fffooter=$bridge::fluentFooter('old footer',$form,$notification);
t(str_contains($fffooter,home_url('/es/')),'Fluent email uses verified saved submission language');
t(str_contains($fffooter,'Privacidad'),'Fluent Spanish footer copy');
t($bridge::fluentCredit('Powered by ...',$form,$notification)==='','No platform promotion in company notice');
t(str_contains($bridge::fluentStyles('body{}',$form,$notification),'#102D40'),'Fluent CSS matches Woo');
$body=$bridge::fluentBody('<html><body>Original unmodified message</body></html>',$notification,[],$form);
t(str_contains($body,'Original unmodified message'),'User existing notification body unchanged');
$enFooter=$bridge::fluentFooter('', $form,$notification);
t(!str_contains($enFooter,'/es/'),'Fluent per-notification language reset after send');

$fake='<html><body>'
 .'<table width="600" id="template_container" style="background:#fff;">'
 .'<tr><td><div id="template_header_image"><img src="'.$brand['logo_url'].'"></div></td></tr>'
 .'<tr><td><table width="600" id="template_header" style="color:#444;">'
 .'<tr><td id="header_wrapper" style="padding:36px 48px;"><h1>Brand heading</h1></td></tr></table></td></tr>'
 .'<tr><td><table width="600" id="template_body"><tr><td>Original content</td></tr></table></td></tr>'
 .'<tr><td><table width="600" id="template_footer"><tr><td>Legal line</td></tr></table></td></tr>'
 .'<tr><td><table width="600" id="visitor_custom_table"><tr><td>Preserve</td></tr></table></td></tr>'
 .'</table></body></html>';
$fixed=$bridge::fluentBody($fake,$notification,[],$form);
t(substr_count($fixed,'width="100%"')===4,'Only four Fluent internal tables fluid');
t(str_contains($fixed,'width="600" id="visitor_custom_table"'),'Custom user table unmodified');
t(str_contains($fixed,'background-color:#102D40'),'Fluent dark company header inlined');
t(str_contains($fixed,'border-top:2px solid #B48A46'),'Fluent brand accent inlined');
t(str_contains($fixed,'<img width="154"'),'Logo width is responsive');
t(str_contains($fixed,'Brand heading')&&str_contains($fixed,'Original content'),'Body and heading retained');

$GLOBALS['identityValid']=false;
t($bridge::wooStyles('ORIGINAL',null)==='ORIGINAL','Unbound company no Woo styling');
t($bridge::fluentColors(['base_color'=>'#111111'])['base_color']==='#111111','Unbound company Fluent unchanged');
t($bridge::fluentCredit('Platform credit',$form,$notification)==='Platform credit','Unbound company platform policy unchanged');
echo json_encode(['state'=>'PASS','cases'=>26,'notifications_enabled'=>0,'send_calls'=>0,'gateway_mutation'=>false]).PHP_EOL;
