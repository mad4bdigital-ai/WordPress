<?php
/**
 * Plugin Name: MAD4B Company Login Presentation
 * Description: Brand-isolated WordPress login design based on shared UI layout, company-owned values only.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
add_action('login_enqueue_scripts', static function () {
    $name=trim((string)get_option('blogname',''));
    $brand = function_exists('mad4b_tenant_brand_media_value')
        ? mad4b_tenant_brand_media_value('logo_default') : array();
    $url=is_array($brand)?(string)($brand['url']??''):'';
    $style=<<<'CSS'
body.login {
 margin:0;min-height:100vh;display:flex;flex-direction:column;justify-content:center;
 align-items:center;background:radial-gradient(circle at top,#ffd77a 0%,#f3c164 22%,#f4a261 40%,#264653 100%);
 font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
body.login #login{width:380px;max-width:92%;margin:0;padding:0}
body.login #loginform,#lostpasswordform,#registerform{
 background:rgba(255,255,255,.97);padding:32px 30px 28px;border-radius:18px;
 border:1px solid rgba(255,255,255,.7);box-shadow:0 18px 40px rgba(0,0,0,.25)
}
body.login #loginform label{font-size:13px;font-weight:600;color:#1f2933}
.login h1{margin-bottom:18px}
.login h1 a{background-image:none;box-shadow:none!important;width:100%;height:auto;min-height:80px;
 display:flex;align-items:center;justify-content:center;text-indent:0;font-size:26px;font-weight:700;
 color:#264653;text-decoration:none;background-size:contain;background-repeat:no-repeat;background-position:center}
.login form .input,.login input[type=text],.login input[type=password]{
 width:100%;border-radius:10px!important;border:1px solid #d1d5db!important;
 padding:11px 12px!important;font-size:14px!important;background:#f9fafb;box-shadow:none!important
}
.login form .input:focus{border-color:#f4a261!important;box-shadow:0 0 0 2px rgba(244,162,97,.35)!important}
.wp-pwd .button.button-secondary{border-radius:8px;border-color:transparent;background:#e5e7eb;color:#4b5563}
.login .forgetmenot{margin-top:4px}
.login .forgetmenot label{font-weight:500;color:#4b5563}
.login .button-primary{width:100%;border-radius:10px!important;padding:10px 0!important;
 background:#264653!important;border-color:#264653!important;font-size:15px!important;
 font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.login .button-primary:hover{background:#2a9d8f!important;border-color:#2a9d8f!important}
#nav,#backtoblog{text-align:center;margin-top:12px}
#nav a,#backtoblog a{color:#fdfdfd!important;font-size:13px;text-decoration:none}
#nav a:hover,#backtoblog a:hover{text-decoration:underline}
.privacy-policy-page-link{text-align:center;margin-top:6px}
.privacy-policy-page-link a{color:#fefefe!important;font-size:12px;opacity:.85}
.wpml-login-ls{margin-top:18px;padding:6px 12px;border-radius:999px;background:rgba(0,0,0,.35);
 display:flex;align-items:center;gap:8px;color:#fff;font-size:12px}
.wpml-login-ls select{border-radius:999px;border:0;padding:4px 10px;font-size:12px}
.wpml-login-ls .button{border-radius:999px;padding:4px 10px;border:0;background:#f4a261;color:#1f2933}
@media(max-width:480px){body.login #login{width:92%}body.login #loginform{padding:26px 20px 22px}}
CSS;
    if($url && wp_http_validate_url($url)) {
        $style.="\n.login h1 a{font-size:0;background-image:url('".esc_url_raw($url)."');}\n";
    }
    wp_register_style('mad4b-company-login-presentation',false,array(),'0.1.0');
    wp_enqueue_style('mad4b-company-login-presentation');
    wp_add_inline_style('mad4b-company-login-presentation',$style);
},60);
add_filter('login_headerurl',static function(){return home_url('/');},50);
add_filter('login_headertext',static function(){
    return wp_strip_all_tags((string)get_option('blogname',''));
},50);
add_filter('gettext',static function($translated,$text,$domain){
    if($domain==='default'&&$text==='Powered by WordPress'&&$GLOBALS['pagenow']==='wp-login.php')
        return wp_strip_all_tags((string)get_option('blogname',''));
    return $translated;
},20,3);
