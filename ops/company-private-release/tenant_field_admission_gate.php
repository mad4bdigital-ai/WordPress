<?php
/**
 * Tenant field gates for source inventory and NEW-clone promotion.
 *
 * WordPress CLI only. DEFAULT: READ ONLY.
 * No field values, emails, GTM identifiers, API keys or OAuth data are emitted.
 *
 * Usage:
 *   MAD4B_EXPECTED_TENANT_DOMAIN=example.com wp --skip-plugins --skip-themes eval-file -
 *
 * Caller must compare tenant-specific GTM fingerprints externally and provide
 * ownership approval; this script explicitly does NOT grant go-live itself.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Governed audit requires WP-CLI');
}
global $wpdb;
$domain = strtolower(trim((string)getenv('MAD4B_EXPECTED_TENANT_DOMAIN')));
$known = [
    'allroyalegypt.com' => 'egypttourgates.com',
    'egypttourgates.com' => 'allroyalegypt.com',
];
if (!array_key_exists($domain, $known)) {
    throw new RuntimeException('Unregistered target tenant; fail closed');
}
$other = $known[$domain];
$host = strtolower((string)parse_url(home_url(), PHP_URL_HOST));
$siteHost = strtolower((string)parse_url(site_url(), PHP_URL_HOST));
$owned = static function($v) use ($domain): bool {
    if (!is_string($v) || trim($v)==='') return false;
    $h = strtolower((string)parse_url(str_contains($v,'://')?$v:'https://'.$v, PHP_URL_HOST));
    return $h===$domain || $h==='www.'.$domain;
};
$inspect = static function($data, $needle, &$paths, $path = '$', $depth = 0) use (&$inspect): void {
    if ($depth>15 || count($paths)>=300) return;
    if (is_array($data) || is_object($data)) {
        foreach ((array)$data as $k=>$v) {
            $key = (string)$k;
            if (stripos($key,$needle)!==false) {
                $paths[]=['path'=>$path.'[foreign_site_key]','classification'=>'foreign_key'];
            }
            if (preg_match('/(?:secret|private|token|password|nonce|authorization|credential)/i',$key)) continue;
            $inspect($v,$needle,$paths,$path.'.'.preg_replace('/[^a-z0-9_:-]/i','_',substr($key,0,90)),$depth+1);
        }
    } elseif (is_string($data) && stripos($data,$needle)!==false) {
        $paths[]=['path'=>$path,'classification'=>'foreign_value'];
    }
};
$rows=$wpdb->get_results(
    $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s ORDER BY option_name",
    '%'.$wpdb->esc_like($other).'%'), ARRAY_A);
if ($wpdb->last_error) throw new RuntimeException('Option inspection failed');
$foreignOptions=[];
foreach ($rows as $r) {
    $name=$r['option_name'];
    $paths=[];
    $inspect(get_option($name),$other,$paths);
    $foreignOptions[]=['key'=>$name,'paths'=>$paths,'sensitivity'=>preg_match('/cache|adminhash/i',$name)?'derived_or_transient':'tenant_specific_review'];
}
$kit=(int)get_option('elementor_active_kit',0);
$kitPost=$kit>0?get_post($kit):null;
$gtm=get_option('gtm4wp-options',[]);
$container=(is_array($gtm) && is_string($gtm['gtm-code']??null))?(string)$gtm['gtm-code']:'';
$gtmPresent=(bool)preg_match('/^GTM-[A-Z0-9]{4,}$/',$container);
$rank=get_option('rank_math_analytics_all_services',[]);
$foreignRank=0;
if(is_array($rank) && is_array($rank['sites']??null)){
    foreach($rank['sites'] as $key=>$entry){
        if(stripos((string)$key,$other)!==false) $foreignRank++;
    }
}
$snippetCount=0; $formCross=0; $formTotal=null;
$tables=$wpdb->get_col($wpdb->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=%s",DB_NAME));
if($wpdb->last_error)throw new RuntimeException('Table inventory failed');
if(in_array($wpdb->prefix.'snippets',$tables,true)){
    $snippetCount=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}snippets WHERE code LIKE %s",
        '%'.$wpdb->esc_like($other).'%'));
}
if(in_array($wpdb->prefix.'fluentform_form_meta',$tables,true)){
    $formCross=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_form_meta WHERE meta_value LIKE %s",
        '%'.$wpdb->esc_like($other).'%'));
}
if(in_array($wpdb->prefix.'fluentform_forms',$tables,true)){
    $formTotal=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_forms");
}
$flags=[];
$flags['home_matches_tenant']=$owned($host);
$flags['siteurl_matches_tenant']=$owned($siteHost);
$flags['elementor_kit_local']=$kit>0 && $kitPost!==null && $kitPost->post_type==='elementor_library';
$flags['foreign_option_references_absent']=count($rows)===0;
$flags['foreign_rank_math_site_keys_absent']=$foreignRank===0;
$flags['foreign_snippet_references_absent']=$snippetCount===0;
$flags['foreign_form_meta_absent']=$formCross===0;
$flags['gtm_container_present_for_review']=$gtmPresent;
$flags['admin_email_is_valid']=is_email((string)get_option('admin_email',''))!==false;
$litespeed=get_option('litespeed.conf.cdn-ori',null);
// LiteSpeed stores a JSON list (e.g. ["https://example.com"]).
// Validate decoded origin entries rather than treating JSON as a hostname.
$cdnOrigins=[];
if (is_string($litespeed) && trim($litespeed)!=='') {
    $decoded=json_decode($litespeed,true);
    $cdnOrigins=is_array($decoded)?$decoded:[$litespeed];
}
$flags['cdn_origin_is_tenant_domain']=count($cdnOrigins)===0 ||
    (count($cdnOrigins)===count(array_filter($cdnOrigins,static function($item) use($owned){
        return is_string($item) && $owned($item);
    })));
$out=[
 'contract'=>'mad4b.company-copy.tenant-admission-gate.v1',
 'tenant_domain'=>$domain,
 'read_only'=>true,
 'option_rows_with_foreign_domain'=>count($rows),
 'foreign_option_paths'=>$foreignOptions,
 'foreign_snippet_rows'=>$snippetCount,
 'foreign_fluent_form_meta_rows'=>$formCross,
 'fluent_form_count'=>$formTotal,
 'elementor_kit_present'=>$kit>0,
 'gtm_container_configured'=>$gtmPresent,
 'gtm_ownership_and_uniqueness_verified'=>false,
 'smtp_owner_verified'=>false,
 'external_endpoints_approved'=>false,
 'media_origin_tls_and_acl_verified'=>false,
 'conditions'=>$flags,
 'classification'=>in_array(false,$flags,true)?'BLOCKED_BY_LOCAL_EVIDENCE':'PENDING_EXTERNAL_PROOFS',
 'release_allowed'=>false,
 'source_mutation'=>false
];
echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
