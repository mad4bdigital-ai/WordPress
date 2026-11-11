<?php
/**
 * Contract regression:
 * Partial shared-GTM consent must fail closed unless authenticated Google
 * version & public compiled tags exactly match provider-approved consent.
 */
$source=dirname(__DIR__,3).'/mu-plugins/mad4b-gtm-consent-governance.php';
if(!is_file($source)){fwrite(STDERR,"FAIL: shared GTM governance module missing\n");exit(1);}
$php=file_get_contents($source);
$need=[
 'authenticated_google_tag_manager_api_live_version',
 'authenticated_gtm_workspace_export_v2',
 'external_tag_consent_rules',
 'published_external_tag_consent_mismatch',
 'published_provider_version_not_matched',
 'unprotected_published_external_tags',
 'broker_audit_sha256',
 'authenticated_provider_audit_wrong_company',
 'published_js_sha256',
 'workspace_approval_stale',
 'first_party_pii_collection_unreviewed',
 'if($fn!==\'__evl\')',
];
foreach($need as $fragment){
 if(!str_contains($php,$fragment)){
  fwrite(STDERR,"FAIL: governance contract missing ".$fragment."\n");
  exit(1);
 }
}
if(!str_contains($php,"return self::status()['eligible_for_partial_shared_gtm']")) {
 fwrite(STDERR,"FAIL: controller can bypass fail-closed status\n");exit(1);
}
$audit=dirname(__DIR__).'/includes/class-mad4b-google-measurement-gtm-consent-audit.php';
if(!is_file($audit)){fwrite(STDERR,"FAIL: authenticated GTM audit missing\n");exit(1);}
$auditPhp=file_get_contents($audit);
foreach([
  'consentType',
  'additional_consent_types',
  'additional_consent_checks_verified',
  'Required consent types',
] as $rule){
 if(!str_contains($auditPhp,$rule)){
   fwrite(STDERR,"FAIL: missing consent auditing control $rule\n");exit(1);
 }
}
echo "PASS: GTM authenticated live version, exact public consent rules & fail-closed controls\n";
