<?php
declare(strict_types=1);

function etg_pf_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function etg_pf_has(string $needle,string $haystack,string $message):void{etg_pf_expect(false!==strpos($haystack,$needle),$message);}

$root=dirname(__DIR__);
$bundle=file_get_contents($root.'/includes/Diagnostics/PublicationEvidenceBundle.php');
$endpoint=file_get_contents($root.'/includes/Presentation/AjaxPresentationEndpoint.php');
$readme=file_get_contents($root.'/readme.md');

etg_pf_has("'ajax_rate_protection'=>\$ajaxRateProtection",$bundle,'Evidence Bundle exposes AJAX rate-protection state');
etg_pf_has("'mode'=>\$ajaxPersistentCache?'persistent_object_cache':'external_waf_required'",$bundle,'Evidence Bundle distinguishes built-in persistent-cache protection from external WAF requirement');
etg_pf_has('ajax_rate_protection_persistent_cache_or_waf_evidence',$bundle,'Production evidence list requires AJAX rate-protection proof');
etg_pf_has("return'external_waf_required'",preg_replace('/\s+/','',$endpoint),'Endpoint retains explicit external-WAF boundary without persistent object cache');
etg_pf_expect(false===strpos($endpoint,'get_transient(')&&false===strpos($endpoint,'set_transient('),'Anonymous AJAX protection must not fall back to per-visitor transient/database writes');
etg_pf_has('Operational Alpha `0.4.0-alpha.13`',$readme,'README version is synchronized to Alpha13');
etg_pf_has('presentation state != SEO/indexing authority',$readme,'README preserves the presentation/SEO authority separation');
etg_pf_has('AJAX rate-protection boundary',$readme,'README documents the Production rate-protection requirement');

echo "Alpha13 Production preflight smoke tests passed.\n";
