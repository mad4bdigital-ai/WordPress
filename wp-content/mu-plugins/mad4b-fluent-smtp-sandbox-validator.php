<?php
/**
 * Plugin Name: MAD4B Fluent SMTP Sandbox Credential Validator
 * Version: 0.7.0
 * Description: Test-only SMTP protocol authentication in an isolated loopback sandbox. Never alters Fluent SMTP connections or sends messages.
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Fluent_SMTP_Sandbox_Validator {
    const CONTRACT='mad4b.fluent-smtp-sandbox-auth.v1';
    const RECEIPT='mad4b_fluent_smtp_sandbox_auth_receipts_v1';
    public static function trusted():bool {
        return defined('WP_CLI') && WP_CLI === true && PHP_SAPI === 'cli'
            && class_exists('MAD4B_Secure_Credential_Envelope')
            && !MAD4B_Secure_Credential_Envelope::source();
    }
    private static function error(string $code):WP_Error {
        return new WP_Error('mad4b_smtp_sandbox_'.$code,'SMTP sandbox validation denied or failed.', ['status'=>409]);
    }
    public static function validate_loopback(int $port,string $username,string $password):array|WP_Error {
        if(!self::trusted())return self::error('trusted_recipient_lane_required');
        if($port<20000 || $port>45000 || !str_starts_with($username,'MAD4B_TEST_ONLY_')
           || !str_starts_with($password,'MAD4B_TEST_ONLY_')
           || strlen($username)>100 || strlen($password)>100)return self::error('sandbox_only');
        if(!class_exists('PHPMailer\\PHPMailer\\SMTP'))require_once ABSPATH.WPINC.'/PHPMailer/SMTP.php';
        $smtp=new \PHPMailer\PHPMailer\SMTP();
        $smtp->do_debug=0;
        $connected=false;$authenticated=false;$advertisesAuth=false;
        try {
            // No TLS exemption is possible for external hosts: this adapter is
            // categorically restricted to 127.0.0.1 with fixed fixture names.
            $connected=(bool)$smtp->connect('127.0.0.1',$port,4);
            if($connected){
                $hello=(bool)$smtp->hello('mad4b-sandbox.local');
                $ext=$smtp->getServerExt('AUTH');
                $advertisesAuth=$hello && is_array($ext) && in_array('LOGIN',$ext,true);
                if($advertisesAuth)$authenticated=(bool)$smtp->authenticate($username,$password,'LOGIN');
            }
        }catch(\Throwable $e){$authenticated=false;}
        finally{if($connected){try{$smtp->quit();}catch(\Throwable $e){}}$smtp->close();}
        $r=['contract'=>self::CONTRACT,'transport'=>'SMTP_AUTH_LOGIN',
            'scope'=>'127.0.0.1_TEST_SANDBOX_ONLY','connection_established'=>$connected,
            'auth_advertised'=>$advertisesAuth,'authentication_passed'=>$authenticated,
            'message_sent'=>false,'production_vendor_contacted'=>false,
            'production_connection_modified'=>false,'username_returned'=>false,
            'password_returned'=>false,'vendor_credential_materialization_enabled'=>false];
        // Receipt only: the generic WP environment and credential values are
        // never persisted in Options, logs, MCP or the front end.
        $history=(array)get_option(self::RECEIPT,[]);
        $history[]=['contract'=>self::CONTRACT,'observed_utc'=>gmdate('c'),
            'site_uuid'=>(string)((array)get_option('mad4b_scp_site_profile_v2',[]))['site_uuid'],
            'protocol_authenticated'=>$authenticated,'connected'=>$connected,
            'fixture_only'=>true,'email_sent'=>false,'provider_secret_written'=>false];
        update_option(self::RECEIPT,array_slice($history,-30),false);
        return $r;
    }
    public static function status():array {
        $rows=(array)get_option(self::RECEIPT,[]);
        $accepted=0;$rejected=0;
        foreach($rows as $receipt){
            if(!is_array($receipt)||($receipt['contract']??'')!==self::CONTRACT||
               empty($receipt['fixture_only'])||!empty($receipt['email_sent'])||
               !empty($receipt['provider_secret_written']))continue;
            if(!empty($receipt['protocol_authenticated']))$accepted++;
            else $rejected++;
        }
        return ['contract'=>self::CONTRACT,'receipt_count'=>count($rows),
            'accepted_fixture_authentications'=>$accepted,
            'rejected_fixture_authentications'=>$rejected,
            'sandbox_protocol_test_verified'=>$accepted>0 && $rejected>0,
            'real_vendor_credential_verified'=>false,'active_smtp_config_changed'=>false,
            'email_sent'=>false,'plaintext_secret_returned'=>false];
    }
}
