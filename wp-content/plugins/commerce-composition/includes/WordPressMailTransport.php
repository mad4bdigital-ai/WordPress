<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Transport-neutral guest ownership challenge sender. Delegates to WordPress
 * wp_mail() so an optional SMTP/API plugin may handle delivery later.
 * Disabled by default. No secrets, provider names or contact subscriptions.
 */
final class WordPressMailTransport
{
    public static function config():array
    {
        $value=get_option('commerce_guest_consent_mail',[]);
        return is_array($value)?$value:[];
    }
    public static function ready($current,int $formId):bool
    {
        if($current===true)return true;
        $config=self::config();
        return ($config['enabled']??null)===true
            && ($config['transport']??null)==='wordpress'
            && ($config['delivery_test_approved']??null)===true
            && function_exists('wp_mail')
            && function_exists('home_url');
    }
    public static function send($prior,array $message):bool
    {
        if($prior===true)return true;
        if(!self::ready(false,(int)($message['form_id']??0)) ||
            !is_string($message['email']??null)||
            !is_email($message['email'])||
            !is_string($message['token']??null)||
            !preg_match('/^[a-f0-9]{64}$/D',$message['token']))
            return false;
        $url=add_query_arg('commerce_consent_confirm',
            $message['token'],home_url('/'));
        if(!str_starts_with($url,'https://') &&
            !apply_filters('commerce_composition_consent_allow_local_http',
                false))
            return false;
        $title=__('Confirm your email address','woocommerce');
        $body=__('You requested optional email updates. Confirm that you own this email address by opening the link below and selecting Confirm. No marketing messages will be sent merely because you open the link.','woocommerce');
        $body.="\n\n".$url."\n\n";
        $body.=__('This link expires after 24 hours. If you did not request it, ignore this message.','woocommerce');
        // Plaintext prevents untrusted Form values from being treated as HTML.
        try {
            return wp_mail($message['email'],$title,$body,
                ['Content-Type: text/plain; charset=UTF-8'])===true;
        }catch(\Throwable $e) {
            return false;
        }
    }
    public static function bootstrap():void
    {
        add_filter('commerce_composition_guest_consent_transport_ready',
            [self::class,'ready'],40,2);
        add_filter('commerce_composition_guest_consent_deliver',
            [self::class,'send'],40,2);
    }
}
