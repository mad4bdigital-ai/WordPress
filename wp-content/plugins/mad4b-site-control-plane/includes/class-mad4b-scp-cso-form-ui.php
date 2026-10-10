<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** First-party presentation adapter. A ChatGPT resource/widget still needs host acceptance. */
final class MAD4B_SCP_CSO_Form_UI {
    private static $booted=false;
    public static function boot() {
        if ( self::$booted || ! MAD4B_SCP_CSO_Scope::enabled('forms') || ! MAD4B_SCP_CSO_Scope::enabled('discovery') ) return;
        self::$booted=true;
        add_action('admin_post_mad4b_cso_form',array(__CLASS__,'page'));
    }
    public static function presentation(array $input) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled('discovery') || ! MAD4B_SCP_CSO_Scope::enabled('forms') || array_diff(array_keys($input),array('ability_name','target')) ) return MAD4B_SCP_CSO_Scope::error('PRESENTATION_DISABLED_OR_INPUT_INVALID');
        $name=$input['ability_name']??''; $target=$input['target']??array();
        if ( ! is_string($name) || ! is_array($target) || true !== MAD4B_SCP_CSO_Scope::safe_data($target) ) return MAD4B_SCP_CSO_Scope::error('PRESENTATION_TARGET_INVALID');
        $form=MAD4B_SCP_CSO_Forms::schema($name,$target); if ( is_wp_error($form) ) return $form;
        $scope=MAD4B_SCP_CSO_Scope::current(); if ( is_wp_error($scope) ) return $scope;
        $url=add_query_arg(array('action'=>'mad4b_cso_form','ability_name'=>$name,'target'=>wp_json_encode($target)),admin_url('admin-post.php'));
        if ( ! self::same_origin($url,$scope['origin']) ) return MAD4B_SCP_CSO_Scope::error('FIRST_PARTY_ORIGIN_REQUIRED');
        $current=MAD4B_SCP_CSO_Scope::assert_current($scope); if ( is_wp_error($current) ) return $current;
        return array('contract'=>'mad4b.cso01.presentation.v1','form'=>$form,'first_party_url'=>$url,
            'locale'=>$scope['locale']??'en_US','direction'=>0===strpos($scope['locale']??'','ar')?'rtl':'ltr',
            'host_widget_status'=>'CLIENT_RESOURCE_NOT_ACCEPTED','authorizing'=>false,'automatic_commit'=>false,
            'supported_controls'=>array('text','number','checkbox','select','multiselect','date','datetime-local','textarea','typed-json','relationship'),
            'secret_ingress'=>'SEPARATE_FIRST_PARTY_HANDOFF','browser_acceptance'=>'REQUIRED');
    }
    private static function same_origin($url,$origin) {
        $a=wp_parse_url($url); $b=wp_parse_url($origin);
        return is_array($a)&&is_array($b)&&'https'===($a['scheme']??'')&&($a['scheme']??'')===($b['scheme']??'')&&($a['host']??'')===($b['host']??'')&&($a['port']??443)===($b['port']??443);
    }
    public static function page() {
        $scope=MAD4B_SCP_CSO_Scope::current();
        if ( ! MAD4B_SCP_CSO_Scope::enabled('forms') || is_wp_error($scope) || ! is_user_logged_in() || ! function_exists('wp_get_session_token') || ''===wp_get_session_token() ) { status_header(403); exit; }
        // GET only constructs a fresh form for the current cookie actor. No saved
        // form, remote token, posted plaintext secret or state change is accepted.
        if ( 'GET'!==($_SERVER['REQUEST_METHOD']??'') || ! empty($_SERVER['HTTP_AUTHORIZATION']) || ! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ) { status_header(405); exit; }
        $name=isset($_GET['ability_name'])&&is_string($_GET['ability_name'])?wp_unslash($_GET['ability_name']):'';
        $raw=isset($_GET['target'])&&is_string($_GET['target'])?wp_unslash($_GET['target']):'{}';
        $target=strlen($raw)<=8192?json_decode($raw,true):null;
        if ( ! is_array($target) ) { status_header(400); exit; }
        $view=self::presentation(array('ability_name'=>$name,'target'=>$target));
        if ( is_wp_error($view) ) { status_header(403); exit; }
        $endpoint=rest_url('mad4b/v1/conversational-site-operations');
        $js=plugins_url('../assets/cso-form.js',__FILE__); $css=plugins_url('../assets/cso-form.css',__FILE__);
        foreach(array($endpoint,$js,$css) as $url) if(!self::same_origin($url,$scope['origin'])) { status_header(403); exit; }
        $config=array('presentation'=>$view,'endpoint'=>$endpoint,'nonce'=>wp_create_nonce('wp_rest'));
        $json=wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        if(!is_string($json)||strlen($json)>131072) { status_header(413); exit; }
        if(is_wp_error(MAD4B_SCP_CSO_Scope::assert_current($scope))) { status_header(403); exit; }
        $arabic='rtl'===$view['direction']; $title=$arabic?'نموذج الموقع':'Site form';
        nocache_headers();
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        echo '<!doctype html><html lang="'.($arabic?'ar':'en').'" dir="'.esc_attr($view['direction']).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.esc_html($title).'</title><link rel="stylesheet" href="'.esc_url($css).'"></head><body><main id="cso-app"></main><script type="application/json" id="cso-config">'.$json.'</script><script src="'.esc_url($js).'" defer></script></body></html>';
        exit;
    }
}
