#!/usr/bin/env python3
"""Add signed native no---user WP-CLI request path to original 305.

Purpose: remove blanket CLI denial, never infer authority from WP --user.
Signed broker proof + exact active company native enrollment + immutable
request body, route, host and one-use nonce are required. Original REST owner
authorization, original company scope and 306/312/313 remain authoritative.

The native path is intentionally restricted to existing company WPCLI grant
usage: POST .../commands and GET .../commands/{id}. It cannot create,
rotate or revoke grants/transports through a CLI user override.
"""
from __future__ import annotations
import argparse
from pathlib import Path

ORIGINAL = """    public static function permitted($request = null): bool {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::allowed()
            || !($request instanceof WP_REST_Request)) return false;
        // A WP-CLI user override is not an authenticated owner confirmation.
        if (defined('WP_CLI') && WP_CLI) return false;
"""
UPDATED = """    public static function permitted($request = null): bool {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1', false)
            || !($request instanceof WP_REST_Request)) return false;
        if (defined('WP_CLI') && WP_CLI) {
            // REST uses the original logged-in session gate; the native
            // workload has a separately signed first-party identity.
            if (!MAD4B_Central_Dual_Lane_Registry_V1::central()
                || !class_exists('MAD4B_SCP_Site_Profile',false)
                || !MAD4B_SCP_Site_Profile::origin_enrolled()
                || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
                return false;
            return self::native_cli_permitted($request);
        }
        if (!MAD4B_Central_Dual_Lane_Registry_V1::allowed())
            return false;
"""

BODY = r'''    // Original 305 signed native CLI v1: no WP user override or CLI
    // impersonation. A registered original company native principal is
    // necessary but not sufficient without a fresh signed exact request.
    const NATIVE_CLI_CONTRACT='mad4b.original-305-signed-native-cli.v1';
    const NATIVE_CLI_NONCE='mad4b_original_305_native_cli_nonce_v1_';
    const NATIVE_CLI_FIELDS=['contract','company','environment','site_uuid',
        'canonical_origin','host_id','grant_id','actor_user_id','method',
        'route','body_sha256','issued','expires','nonce'];
    private static $native_cli_context=null;

    private static function actor_id():int {
        return (defined('WP_CLI') && WP_CLI &&
            is_array(self::$native_cli_context))
            ? (int)self::$native_cli_context['actor_user_id']
            : (int)get_current_user_id();
    }

    private static function native_cli_permitted(WP_REST_Request $request):bool {
        $method=(string)$request->get_method();
        $route=(string)$request->get_route();
        $body=(string)$request->get_body();
        // A fresh, signed proof is needed for every *different* request.
        // REST invokes permission_callback and callback for the same object.
        $context=self::$native_cli_context;
        if (is_array($context) &&
            ($context['request_id']??null)===spl_object_id($request) &&
            ($context['route']??null)===$route &&
            ($context['method']??null)===$method &&
            ($context['body_sha256']??null)===hash('sha256',$body))
            return true;
        self::$native_cli_context=null;
        if ($method!=='POST' && $method!=='GET')return false;
        if (!preg_match(
            '~^/mad4b/v1/central-execution-lanes/([a-z][a-z0-9-]{2,47})/commands(?:/([a-f0-9]{32}))?$~D',
            $route,$matches))return false;
        if (($method==='POST' && isset($matches[2])) ||
            ($method==='GET' && !isset($matches[2])))return false;
        if (!class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
            || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
            || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
            || !class_exists('MAD4B_Company_Environment_Registry_V2',false)
            || !function_exists('sodium_crypto_sign_verify_detached'))
            return false;
        $proof_raw=(string)$request->get_header('X-MAD4B-Native-Proof');
        if ($proof_raw==='' || strlen($proof_raw)>4096)return false;
        $blob=base64_decode($proof_raw,true);
        if (!is_string($blob) || strlen($blob)>3000)return false;
        $proof=json_decode($blob,true);
        if (!is_array($proof) ||
            array_keys($proof)!==array_merge(self::NATIVE_CLI_FIELDS,['signature']))
            return false;
        $company=(string)$matches[1];
        $env=$proof['environment']??null;
        $issued=$proof['issued']??null;
        $expires=$proof['expires']??null;
        $now=time();
        if (($proof['contract']??null)!==self::NATIVE_CLI_CONTRACT
            || ($proof['company']??null)!==$company
            || !is_string($env) ||
                !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D',$env)
            || ($proof['method']??null)!==$method
            || ($proof['route']??null)!==$route
            || !is_string($proof['body_sha256']??null)
            || !hash_equals($proof['body_sha256'],hash('sha256',$body))
            || ($proof['host_id']??null)!=='srv2030908'
            || !is_int($issued) || !is_int($expires)
            || $issued<$now-60 || $issued>$now+10
            || $expires<=$now || $expires>$issued+60
            || !is_string($proof['nonce']??null)
            || !preg_match('/^[a-f0-9]{32}$/D',$proof['nonce'])
            || !is_int($proof['actor_user_id']??null)
            || $proof['actor_user_id']<1)return false;
        $record=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback(
            $company,$env);
        if (!is_array($record) ||
            ($record['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
            || ($record['full_wpcli_active']??null)!==true
            || ($record['host_authority_verified']??null)!==true)return false;
        $site=MAD4B_Company_Environment_Registry_V2::target($company,$env);
        if (!is_array($site) ||
            ($site['site_uuid']??null)!==($proof['site_uuid']??null) ||
            ($site['canonical_origin']??null)!==
                ($proof['canonical_origin']??null) ||
            ($site['environment']??null)!==$env)return false;
        $slot_key=MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.
            $company.'_'.$env;
        $slot=get_option($slot_key,false);
        $grant=is_array($slot)
            ? get_option(self::PREFIX.($slot['grant_id']??''),false)
            : false;
        if (!is_array($grant) ||
            ($grant['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
            || ($grant['principal_type']??null)!=='signed_vps_wpcli'
            || ($grant['operation']??null)!=='company.remote.wpcli.full'
            || ($grant['grant_mode']??null)!=='persistent'
            || ($grant['target_host_id']??null)!=='srv2030908'
            || ($grant['grant_id']??null)!==($proof['grant_id']??null)
            || ($grant['company']??null)!==$company
            || ($grant['environment']??null)!==$env
            || ($grant['site_uuid']??null)!==$proof['site_uuid']
            || ($grant['canonical_origin']??null)!==
                $proof['canonical_origin']
            || (int)($grant['central_principal_id']??0)!==
                $proof['actor_user_id'])return false;
        $pub=base64_decode((string)get_option(
            MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        $sig=base64_decode((string)$proof['signature'],true);
        if (!is_string($pub) ||
            strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            !is_string($sig) ||
            strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return false;
        $values=[];
        foreach (self::NATIVE_CLI_FIELDS as $field) {
            $val=$proof[$field];
            if (!is_string($val) && !is_int($val))return false;
            $values[]=(string)$val;
        }
        if (!sodium_crypto_sign_verify_detached(
            $sig,implode("\n",$values),$pub))return false;
        // Only consume the nonce after cryptographic + original enrollment
        // verification. A second request with the same proof cannot proceed.
        if (!add_option(self::NATIVE_CLI_NONCE.$proof['nonce'],[
            'company'=>$company,'grant_id'=>$proof['grant_id'],
            'issued'=>$issued],'',false))return false;
        self::$native_cli_context=[
            'request_id'=>spl_object_id($request),
            'route'=>$route,
            'method'=>$method,
            'body_sha256'=>hash('sha256',$body),
            'actor_user_id'=>$proof['actor_user_id'],
        ];
        return true;
    }

'''

def transform(original: str) -> str:
    if 'NATIVE_CLI_CONTRACT=' in original:
        raise RuntimeError("ORIGINAL_305_NATIVE_CLI_ALREADY_PATCHED")
    if original.count(ORIGINAL) != 1:
        raise RuntimeError("ORIGINAL_305_AUTH_ANCHOR_CHANGED")
    target=original.replace(ORIGINAL,UPDATED,1)
    entry="    public static function permitted($request = null): bool {\n"
    if target.count(entry)!=1:raise RuntimeError("ORIGINAL_305_CLASS_ANCHOR_CHANGED")
    target=target.replace(entry,BODY+entry,1)
    # Authenticated native principal is an actor from ORIGINAL signed
    # company enrollment, never a WordPress current-user impersonation.
    occurrences=target.count('get_current_user_id()')
    if occurrences!=13:
        raise RuntimeError("ORIGINAL_305_ACTOR_CALL_SITES_CHANGED:"+str(occurrences))
    start=target.index("    private static function actor_id():int {")
    end=target.index("    private static function native_cli_permitted(",start)
    head,actor,tail=target[:start],target[start:end],target[end:]
    tail=tail.replace("get_current_user_id()","self::actor_id()")
    candidate=head+actor+tail
    scope_old="""        $admitted = MAD4B_Company_Operation_Admission_V1::assess($company, 'operations.write');
        if (is_wp_error($admitted) || empty($admitted['policy_delegation_admitted']))
            return self::error('active_company_operations_delegation_required');
"""
    scope_new="""        // Original REST admission still requires the logged-in owner.
        // Signed native CLI uses the SAME original entitlement ledger,
        // bound to actor_user_id in its separately verified Broker Grant.
        $native = defined('WP_CLI') && WP_CLI &&
            is_array(self::$native_cli_context);
        if ($native) {
            $manager = MAD4B_Company_Operation_Admission_V1::manager_id();
            $rights = MAD4B_Manager_Auto_Write_V1::entitlement(
                $manager,$company,'operations.write',self::actor_id());
            $target = MAD4B_Manager_Auto_Write_V1::company_target($company);
            $admitted = [
                'policy_delegation_admitted'=>
                    !empty($rights['assignment_authorized']),
                'company_site_uuid'=>$target['site_uuid']??null,
                'environment'=>$target['environment']??null,
                'canonical_origin'=>$target['canonical_origin']??null,
            ];
        } else {
            $admitted=MAD4B_Company_Operation_Admission_V1::assess(
                $company,'operations.write');
        }
        if (is_wp_error($admitted) || empty($admitted['policy_delegation_admitted']))
            return self::error('active_company_operations_delegation_required');
"""
    if candidate.count(scope_old)!=1:
        raise RuntimeError("ORIGINAL_305_ADMISSION_ANCHOR_CHANGED")
    return candidate.replace(scope_old,scope_new,1)

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--source",type=Path,required=True)
    parser.add_argument("--output",type=Path,required=True)
    x=parser.parse_args()
    x.output.write_text(transform(x.source.read_text()))
    print("ORIGINAL_305_NO_WP_USER_NATIVE_PRINCIPAL_PATCH_GENERATED")
