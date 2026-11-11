<?php
/**
 * Plugin Name: MAD4B WordPress Native Mail Transport
 * Description: Site-local WordPress wp_mail() delivery through the isolated MAD4B Postfix transport.
 */
if (!defined('ABSPATH')) { exit; }
/**
 * WordPress Core password recovery is independent of CRM/campaign settings.
 * An exact in-request WP reset token and exact recipient/message are required.
 * This is mail admission only, never a delivery receipt or permission grant.
 */
final class MAD4B_Native_Core_Password_Recovery_V1 {
    private static ?string $fingerprint = null;
    private static bool $route_approved = false;
    private static function signature($recipient,$subject,$message,$headers): string {
        return hash('sha256', wp_json_encode([
            strtolower((string) $recipient),(string) $subject,
            (string) $message,$headers
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    public static function capture($mail,$key,$login,$user) {
        self::$fingerprint = null;
        self::$route_approved = false;
        if (!is_array($mail) || !($user instanceof WP_User)
            || !is_string($login) || $login !== $user->user_login
            || !is_string($mail['to'] ?? null)
            || !is_email($mail['to'])
            || strtolower($mail['to']) !== strtolower($user->user_email)
            || !is_string($mail['subject'] ?? null)
            || !is_string($mail['message'] ?? null)) return $mail;
        $checked = check_password_reset_key($key,$login);
        if (!($checked instanceof WP_User) || $checked->ID !== $user->ID) return $mail;
        self::$fingerprint = self::signature($mail['to'],
            wp_specialchars_decode($mail['subject']),$mail['message'],$mail['headers'] ?? '');
        return $mail;
    }
    public static function allowed($atts): bool {
        if (self::$fingerprint === null || !is_array($atts)
            || !is_string($atts['to'] ?? null)
            || !is_string($atts['subject'] ?? null)
            || !is_string($atts['message'] ?? null)
            || get_option('mad4b_native_mail_delivery_authorized','no') !== 'yes'
            || !class_exists('MAD4B_SCP_Site_Profile', false)) return false;
        $profile = MAD4B_SCP_Site_Profile::status();
        if (!is_array($profile)
            || ($profile['configured'] ?? false) !== true
            || ($profile['origin_match'] ?? false) !== true
            || ($profile['environment_match'] ?? false) !== true
            || ($profile['deployment_binding_match'] ?? false) !== true
            || ($profile['foreign_profile_detected'] ?? true) !== false
            || ($profile['profile_authority_quarantined'] ?? true) !== false) return false;
        return hash_equals(self::$fingerprint,
            self::signature($atts['to'],$atts['subject'],$atts['message'],$atts['headers'] ?? ''));
    }
    public static function route_after_admission($pre, $atts) {
        // Every attempted message resets routing; never inherit a previous mail.
        self::$route_approved = ($pre === null && self::allowed($atts));
        return $pre;
    }
    public static function route_from($from) {
        if (self::$route_approved && !get_option('mad4b_wordpress_core_mail_connection','')) {
            $host = wp_parse_url(home_url('/'),PHP_URL_HOST);
            return is_string($host) && $host !== ''
                ? 'wordpress@'.$host : $from;
        }
        return $from;
    }
    public static function clear(): void {
        self::$fingerprint = null;
        self::$route_approved = false;
    }
}
add_filter('retrieve_password_notification_email',
    ['MAD4B_Native_Core_Password_Recovery_V1','capture'],PHP_INT_MAX,4);
add_action('wp_mail_succeeded',['MAD4B_Native_Core_Password_Recovery_V1','clear'],PHP_INT_MAX);
add_action('wp_mail_failed',['MAD4B_Native_Core_Password_Recovery_V1','clear'],PHP_INT_MAX);
add_filter('pre_wp_mail',
    ['MAD4B_Native_Core_Password_Recovery_V1','route_after_admission'],
    PHP_INT_MAX,2);
add_filter('wp_mail_from',
    ['MAD4B_Native_Core_Password_Recovery_V1','route_from'],
    PHP_INT_MAX,1);
/**
 * Site transport readiness is independent from company/team communications.
 * Native fallback is used only when FluentSMTP has no configured connection.
 */
function mad4b_site_mail_is_company($atts):bool {
    $headers=is_array($atts)?($atts['headers']??[]):[];
    if(is_string($headers))$headers=preg_split('/\r?\n/',$headers);
    foreach((array)$headers as $key=>$value){
        $line=is_string($key)?$key.': '.$value:(string)$value;
        if(preg_match('/^X-MAD4B-Message-Scope:\s*company\s*$/i',trim($line)))return true;
    }
    return false;
}
function mad4b_site_fluent_smtp_configured():bool {
    if(!function_exists('fluentMailDefaultConnection'))return false;
    try {$c=fluentMailDefaultConnection();return is_array($c)&&!empty($c['provider'])&&!empty($c['sender_email']);}
    catch(Throwable $e){return false;}
}
// A configured FluentSMTP connection owns site delivery. Without one, native
// fallback requires its own transport authorization; queue acceptance is not inbox delivery.
add_filter('pre_wp_mail', static function ($pre) {
    if ($pre !== null) { return $pre; }
    if(mad4b_site_fluent_smtp_configured())return null;
    return get_option('mad4b_native_mail_delivery_authorized', 'no') === 'yes' ? null : false;
}, PHP_INT_MAX - 3, 1);
add_action('phpmailer_init', static function ($mailer) {
    if (defined('MAD4B_NATIVE_MAIL_DISABLED') && MAD4B_NATIVE_MAIL_DISABLED) { return; }
    if(mad4b_site_fluent_smtp_configured())return;
    $mailer->isSMTP();
    $mailer->Host = 'mad4b-mail-native';
    $mailer->Port = 25;
    $mailer->SMTPAuth = false;
    $mailer->SMTPSecure = '';
    $mailer->SMTPAutoTLS = false;
    $mailer->Timeout = 15;
    $mailer->SMTPKeepAlive = false;
}, PHP_INT_MAX);
add_filter('wp_mail_from', static function ($from) {
    if (!is_string($from) || $from === '' || !is_email($from)) {
        return 'wordpress@' . wp_parse_url(home_url('/'), PHP_URL_HOST);
    }
    return $from;
}, 20);

/** WordPress core sender/transport is a site setting, never a company/team identity. */
final class MAD4B_WordPress_Core_Mail_Router {
    private static string $from='';
    public static function connection():array {
        if(!function_exists('fluentMailGetSettings'))return [];
        $key=get_option('mad4b_wordpress_core_mail_connection','');
        $s=fluentMailGetSettings();
        return is_string($key)&&is_array($s['connections'][$key]['provider_settings']??null)
            ?$s['connections'][$key]['provider_settings']:[];
    }
    public static function prepare($atts) {
        self::$from='';
        if(!is_array($atts)||mad4b_site_mail_is_company($atts))return $atts;
        $c=self::connection();$email=$c['sender_email']??'';
        if(!is_string($email)||!is_email($email))return $atts;
        $headers=$atts['headers']??[];
        if(is_string($headers))$headers=preg_split('/\r?\n/',$headers,-1,PREG_SPLIT_NO_EMPTY);
        // Explicit third-party senders retain their identity. Core messages normally omit From.
        foreach((array)$headers as $key=>$value){
            $line=is_string($key)?$key.': '.$value:(string)$value;
            if(preg_match('/^From:/i',trim($line)))return $atts;
        }
        self::$from=$email;
        $headers[]='X-MAD4B-Message-Scope: wordpress_core';
        $atts['headers']=$headers;return $atts;
    }
    public static function from($from){return self::$from!==''?self::$from:$from;}
    public static function clear():void {self::$from='';}
    public static function settings():void {
        register_setting('general','mad4b_wordpress_core_mail_connection',['type'=>'string','sanitize_callback'=>static function($v){
            $s=function_exists('fluentMailGetSettings')?fluentMailGetSettings():[];
            return is_string($v)&&isset($s['connections'][$v])?sanitize_text_field($v):'';
        }]);
        add_settings_field('mad4b_wordpress_core_mail_connection','WordPress core email connection',static function(){
            $s=function_exists('fluentMailGetSettings')?fluentMailGetSettings():[];
            echo '<select name="mad4b_wordpress_core_mail_connection"><option value="">Site mail default</option>';
            foreach(($s['connections']??[]) as $key=>$row)echo '<option value="'.esc_attr($key).'" '.selected(get_option('mad4b_wordpress_core_mail_connection',''),$key,false).'>'.esc_html(($row['title']??'Mail').' — '.($row['provider_settings']['sender_email']??'')).'</option>';
            echo '</select><p class="description">WordPress account, password and administrative notifications. Independent from company and internal-team mail routing. Connections are configured once in FluentSMTP.</p>';
        },'general');
    }
}
add_filter('wp_mail',['MAD4B_WordPress_Core_Mail_Router','prepare'],PHP_INT_MAX,1);
add_filter('wp_mail_from',['MAD4B_WordPress_Core_Mail_Router','from'],PHP_INT_MAX-1,1);
add_action('wp_mail_succeeded',['MAD4B_WordPress_Core_Mail_Router','clear'],PHP_INT_MAX);
add_action('wp_mail_failed',['MAD4B_WordPress_Core_Mail_Router','clear'],PHP_INT_MAX);
add_action('admin_init',['MAD4B_WordPress_Core_Mail_Router','settings']);
