<?php
/**
 * Static regression gate: authenticated central GTM audit cannot be accidentally
 * exposed as a generic tenant write/mutation tool.
 */
$root=dirname(__DIR__);
$src=file_get_contents($root.'/includes/class-mad4b-google-measurement-gtm-consent-audit.php');
$main=file_get_contents($root.'/mad4b-google-measurement-bridge.php');
$admin=file_get_contents($root.'/includes/class-mad4b-google-measurement-admin.php');
foreach([
 "class_exists('MAD4B_Company_Portfolio_Manager_V1'",
 "current_user_can('manage_options')",
 'MAD4B_Google_Measurement_Bridge::gate()',
 'MAD4B_Google_Measurement_API::token',
 'self::BASE.$path',
 "'publicId'",
 'versions:live',
 'additional_consent_checks_verified',
 'wp_verify_nonce',
 "'public'=>false",
] as $needle){
 if(strpos($src,$needle)===false){fwrite(STDERR,"Missing central audit gate: $needle\n");exit(1);}
}
foreach(['wp_remote_request(', 'wp_delete_post(', 'wp_remote_patch(', 'wp_remote_delete('] as $unsafe){
 if(strpos($src,$unsafe)!==false){fwrite(STDERR,"Unexpected mutation transport: $unsafe\n");exit(1);}
}

if(substr_count($src,'wp_remote_post(')!==1 ||
   strpos($src,'mad4b_gtm_not_central_account')===false ||
   strpos($src,'mad4b_gtm_parent_not_empty')===false ||
   strpos($src,'existing_google_ui')===false ||
   strpos($src,"check_admin_referer('mad4b_gtm_source_create')")===false ||
   strpos($src,"MAD4B_Google_Measurement_Bridge::SCOPE_GTM_EDIT")===false ||
   strpos($src,"created_in_google_pending_site_binding_and_publication")===false ||
   strpos($src,"MAD4B Source Demo | tours.dev.mad4b.com")===false){
 fwrite(STDERR,"Dedicated, empty-account, exact-owner source container creation guard missing.\n");exit(1);
}
if(!str_contains($src,'Authorize GTM Publish with Google') ||
   !str_contains($src,"wp_nonce_field('mad4b_context_google_connect')") ||
   !str_contains($src,'incremental_consent_required') ||
   !str_contains($src,'missing_scope_count') ||
   !str_contains($src,'mad4b-gtm-publish-consent-form')){
 fwrite(STDERR,"Central owner GTM Publish reconsent CTA must use guarded first-party OAuth POST.\n");exit(1);
}
if(strpos($main,"class-mad4b-google-measurement-gtm-consent-audit.php")===false
 || strpos($admin,"resource_binding_not_selected")===false){
 fwrite(STDERR,"Bridge audit wiring and precise missing-binding diagnosis required.\n");exit(1);
}
echo "PASS: central-only, exact verified GTM live consent audit; no company-tag mutation\n";
