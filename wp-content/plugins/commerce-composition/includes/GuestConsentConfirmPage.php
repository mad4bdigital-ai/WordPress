<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Accessible, mobile-friendly, no-JavaScript guest proof confirmation. */
final class GuestConsentConfirmPage
{
    public static function bootstrap():void
    {
        add_action('init',[self::class,'preventCache'],0);
        add_action('template_redirect',[self::class,'render'],0);
    }
    public static function preventCache():void
    {
        if(!isset($_GET['commerce_consent_confirm']))return;
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
        if(!defined('DONOTCACHEOBJECT'))define('DONOTCACHEOBJECT',true);
        do_action('litespeed_control_set_nocache','Consent confirmation');
    }
    public static function render():void
    {
        if(!isset($_GET['commerce_consent_confirm']))return;
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){
            status_header(405);
            exit;
        }
        $token=is_string($_GET['commerce_consent_confirm'])
            ?sanitize_text_field(wp_unslash($_GET['commerce_consent_confirm'])):'';
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)){
            status_header(400);
            self::page('Invalid confirmation link',
                'The verification link is invalid.',false,$token);
            exit;
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if($method==='GET'){
            // Do not perform proof transition on link opens or preview bots.
            self::page('Confirm your email address',
                'You requested optional email updates. Selecting Confirm verifies this email address only; it does not subscribe you to marketing.',true,$token);
            exit;
        }
        $submitted=isset($_POST['commerce_consent_token'])
            &&is_string($_POST['commerce_consent_token'])
            ?sanitize_text_field(wp_unslash($_POST['commerce_consent_token'])):'';
        if(!hash_equals($token,$submitted)){
            status_header(400);
            self::page('Verification failed',
                'The confirmation request is invalid.',false,$token);
            exit;
        }
        $result=GuestConsentProof::confirm($token);
        if(is_wp_error($result)){
            status_header(400);
            self::page('Verification link unavailable',
                'This link has expired, was previously used, or is no longer valid. No subscription changes were made.',false,$token);
            exit;
        }
        self::page('Email address verified',
            'Your email ownership has been confirmed. This has not subscribed you to marketing emails.',false,$token);
        exit;
    }
    private static function page(string $heading,string $body,bool $showButton,
        string $token):void
    {
        $url=add_query_arg('commerce_consent_confirm',$token,home_url('/'));
        $title=esc_html($heading);
        $message=esc_html($body);
        $form=$showButton?
            '<form method="post" action="'.esc_url($url).'">'.
            '<input type="hidden" name="commerce_consent_token" value="'.
            esc_attr($token).'">'.
            '<button type="submit">Confirm email address</button></form>':'';
        echo '<!doctype html><html lang="en"><head>'.
            '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'.
            '<meta name="robots" content="noindex,nofollow">'.
            '<title>'.$title.'</title>'.
            '<style>html{font:16px/1.5 system-ui,Arial,sans-serif;color:#19232b;background:#f4f6f8}'.
            'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:1rem;box-sizing:border-box}'.
            'main{max-width:34rem;width:100%;background:white;padding:clamp(1.5rem,4vw,3rem);border:1px solid #dce2e8;border-radius:1rem;box-sizing:border-box}'.
            'h1{font-size:clamp(1.4rem,4vw,1.9rem);margin:0 0 1rem}p{margin:0 0 1.5rem}'.
            'button{width:100%;border:0;border-radius:.6rem;padding:.9rem 1.25rem;font:inherit;color:white;background:#203247;cursor:pointer}'.
            'button:focus-visible{outline:3px solid #7c3aed;outline-offset:3px}'.
            '</style></head><body><main><h1>'.$title.'</h1><p>'.
            $message.'</p>'.$form.'</main></body></html>';
    }
}
