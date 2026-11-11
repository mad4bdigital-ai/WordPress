<?php
defined('ABSPATH')||exit;
if(getenv('MAD4B_MAIL_QA_RENDER')!=='1')exit(3);
$mailer=WC()->mailer();
$emails=$mailer->get_emails();
$brand=MAD4B_Company_Email_Brand_V1::resolve();
if(empty($brand['bound'])){echo 'BRAND_NOT_BOUND';exit(4);}
$woo=$emails['WC_Email_Customer_Processing_Order']??null;
if(!$woo){echo 'WOOCOMMERCE_EMAIL_UNAVAILABLE';exit(5);}
ob_start();
do_action('woocommerce_email_header','Your travel update',$woo);
echo '<div id="body_content" style="padding:25px;"><div id="body_content_inner"><p>This is a design-only preview for a company transactional order email.</p><p>There is no real booking, payment, customer or invoice.</p><p><a class="button" href="'.esc_url(home_url('/')).'">Explore our website</a></p></div></div>';
do_action('woocommerce_email_footer',$woo);
$wooHtml=ob_get_clean();
$wooHtml=$woo->style_inline($wooHtml);
$ffForm=(object)['id'=>2,'title'=>'Synthetic preview','status'=>'published'];
$ffNotif=['name'=>'Synthetic notification','enabled'=>true,'subject'=>'Contact request receipt','sendTo'=>['type'=>'field'],'asPlainText'=>'no'];
$ffService=new \FluentForm\App\Services\FormBuilder\Notifications\EmailNotification(wpFluentForm());
$ffHtml=$ffService->getEmailWithTemplate(
 '<p>Thank you for contacting us. This is a design-only Fluent Forms email preview.</p><p>No real submission or traveler record was used.</p>',
 $ffForm,$ffNotif);
// Fluent Forms applies the email_body filter after getEmailWithTemplate().
$ffHtml=apply_filters('fluentform/email_body',$ffHtml,$ffNotif,[],$ffForm);
$root='/tmp/mad4b-mail-preview';
if(!is_dir($root))mkdir($root,0700,true);
file_put_contents($root.'/woo.html',$wooHtml);
file_put_contents($root.'/fluent.html',$ffHtml);
echo wp_json_encode([
 'brand'=>$brand['name'],
 'woo_html_bytes'=>strlen($wooHtml),
 'woo_logo_present'=>$brand['logo_url']!=='' && str_contains($wooHtml,$brand['logo_url']),
 'woo_primary_inlined'=>str_contains(strtolower($wooHtml),'#102d40'),
 'woo_supports_local_links'=>str_contains($wooHtml,home_url('/')),
 'fluent_html_bytes'=>strlen($ffHtml),
 'fluent_logo_present'=>$brand['logo_url']!=='' && str_contains($ffHtml,$brand['logo_url']),
 'fluent_primary'=>str_contains($ffHtml,'#102D40'),
 'fluent_company_footer'=>str_contains($ffHtml,$brand['name']),
 'test_only'=>true,'emails_sent'=>0,'order_mutations'=>0
],JSON_UNESCAPED_SLASHES).PHP_EOL;
