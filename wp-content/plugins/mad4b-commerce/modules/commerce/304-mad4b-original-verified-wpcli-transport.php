<?php
/**
 * Plugin Name: MAD4B Original WPCLI Transport Gate
 * Description: Reuses the original 305 grant and 327 signed host proof; no redundant environment or release gates.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Original_Verified_WPCLI_Transport_V1 {
 public static function active(array $t,array $g):bool {
  // Essential admission only: original grant, exact target, active host proof.
  if (($t['state']??null)!=='NATIVE_ROOT_WPCLI_ACCESS_VERIFIED'
   || ($t['full_wpcli_active']??false)!==true
   || ($t['host_authority_verified']??false)!==true
   || ($t['native_access_validation']??null)!=='signed_root_host_exact_tenant_wpcli'
   || (int)($t['native_access_verified_epoch']??0)<1
   || !preg_match('/^[a-f0-9]{64}$/D',(string)($t['native_access_receipt_sha256']??''))
   || !preg_match('/^[a-f0-9]{64}$/D',(string)($t['native_access_wpcli_readback_sha256']??''))
   || ($t['capabilities']??null)!==['wpcli.full']
   || ($t['principal_type']??null)!=='signed_vps_wpcli'
   || ($g['principal_type']??null)!=='signed_vps_wpcli'
   || ($t['host_id']??null)!==($g['target_host_id']??null)
   || ($t['host_principal']??null)!==($g['target_ssh_principal']??null)
   || !str_starts_with((string)($t['credential_ref']??''),'vault:')
   || ($g['grant_mode']??null)!=='persistent'
   || !array_key_exists('expires_epoch',$g)||$g['expires_epoch']!==null
   || ($g['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
   || ($g['operation']??null)!=='company.remote.wpcli.full'
   || ($t['full_wpcli_grant_id']??null)!==($g['grant_id']??null)
   || ($g['transport_id']??null)!==($t['transport_id']??null)
   || !class_exists('MAD4B_Company_Native_Host_Proof_V1',false))return false;
  $company=$t['company']??null;$env=$t['environment']??null;
  if (!is_string($company)||!is_string($env)
   || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
   || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$env)
   || ($t['company']??null)!==($g['company']??null)
   || ($t['environment']??null)!==($g['environment']??null)
   || ($t['site_uuid']??null)!==($g['site_uuid']??null)
   || ($t['canonical_origin']??null)!==($g['canonical_origin']??null)
   || ($t['central_principal_id']??null)!==($g['central_principal_id']??null)
   || ($t['assignment_revision']??null)!==($g['assignment_revision']??null))return false;
  // Original 327 already validates enrolled 305 pair, signed host key,
  // replay/expiry, and original authorization. Do not duplicate them here.
  $proof=MAD4B_Company_Native_Host_Proof_V1::status($company,$env);
  return is_array($proof) && ($proof['verified']??false)===true
   && ($proof['company']??null)===$company
   && ($proof['environment']??null)===$env;
 }
 public static function permitted(array $t,array $g):bool {
  // Unverified pre-existing transports preserve original 305 admission,
  // but cannot become independently certified without the 312/313 chain.
  if (($t['state']??null)==='PENDING_INDEPENDENT_SSH_VERIFICATION')return true;
  return self::active($t,$g);
 }
}
