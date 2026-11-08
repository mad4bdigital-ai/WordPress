#!/usr/bin/env python3
"""MAD4B original 305 regression guard: no blanket WP-CLI/--user block.

Read-only structural contract check. Real runtime still verifies Ed25519,
original company enrollment, existing Grant, delegation, and replay.
"""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path

LIVE=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-platform/modules/compat/305-mad4b-central-ssh-command-intake.php')

REQUIRED={
 'signed_native_contract':"const NATIVE_CLI_CONTRACT='mad4b.original-305-signed-native-cli.v1';",
 'native_without_user':"return self::native_cli_permitted($request);",
 'original_broker_signature':'sodium_crypto_sign_verify_detached(',
 'original_enrolled_company_grant':'MAD4B_Company_Native_WPCLI_Enrollment_V1::readback(',
 'single_use_native_nonce':'self::NATIVE_CLI_NONCE.$proof[\'nonce\']',
 'signed_route_and_request_body':'hash_equals($proof[\'body_sha256\'],hash(\'sha256\',$body))',
 'original_company_entitlement':"MAD4B_Manager_Auto_Write_V1::entitlement(",
 'native_scope_ledger':"($grant['principal_type']??null)!=='signed_vps_wpcli'",
 'original_rest_nonce':"wp_verify_nonce($nonce, 'wp_rest')",
 'no_wp_login_fallback':"if (!MAD4B_Central_Dual_Lane_Registry_V1::allowed())",
 'original_command_endpoint':"'callback' => array(__CLASS__, 'accept_command')",
}
PROHIBITED={
 'blanket_wpcli_rejection':"if (defined('WP_CLI') && WP_CLI) return false;",
 'wp_user_impersonation':"wp_set_current_user(",
 'original_signed_grant_bypass':"ORIGINAL_305_GRANT_BYPASS",
}

def evaluate(source:str)->dict:
    present={k:(token in source) for k,token in REQUIRED.items()}
    forbidden={k:(token in source) for k,token in PROHIBITED.items()}
    return {
      'contract':'mad4b.original305-no-wp-user-regression-guard.v1',
      'state':'PASS' if all(present.values()) and not any(forbidden.values()) else 'DENIED_REGRESSION',
      'missing_checks':[key for key,ok in present.items() if not ok],
      'prohibited_patterns':[key for key,found in forbidden.items() if found],
      'no_wp_user_override_required':present['native_without_user'],
      'original_rest_owner_protection_preserved':present['original_rest_nonce'] and present['no_wp_login_fallback'],
      'source_sha256':hashlib.sha256(source.encode()).hexdigest(),
      'code_release_applied':False,
    }

if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--path',type=Path,default=LIVE)
    x=p.parse_args()
    result=evaluate(x.path.read_text())
    print(json.dumps(result,sort_keys=True))
    if result['state']!='PASS':raise SystemExit(2)
