<?php
/** Pin a staging-only noncritical leaf-defer policy; no WP bootstrap. */
$source=__DIR__.'/../../../../wp-content/mu-plugins/330-mad4b-staging-leaf-script-defer.php';
if(!is_file($source))$source='/tmp/mad4b-leaf-defer-multilang.php';
$s=file_get_contents($source);
function must($ok,$why){if(!$ok){fwrite(STDERR,"FAIL ".$why."\n");exit(2);}echo "PASS ".$why."\n";}
foreach([
 "wp_get_environment_type()!=='staging'",
 "e5e64bca-ed62-48d2-b2b3-dff693cd702a",
 "HTTP_X_MAD4B_LEAF_DEFER_CONTROL",
 "HTTP_COOKIE","HTTP_AUTHORIZATION","is_user_logged_in()",
 "apply_filters('wpml_current_language'",
 "get_option('mad4b_scp_brand_context_profile_v1'",
 "content_url('/')", '$dependents[$dep]=true',
 '!empty($dependents[$handle])',
 "!empty(\$item->extra['before'])",
 "!empty(\$item->extra['after'])",
 "!empty(\$item->extra['data'])",
 "wp_script_add_data(\$handle,'strategy','defer')",
 "wp_print_footer_scripts",
] as $token)must(strpos($s,$token)!==false,'guard '.$token);
preg_match('/private const CANDIDATES=\[(.*?)\];/s',$s,$m);
must(isset($m[1]),'explicit leaf-only allowlist');
$allowlist=$m[1];
foreach(['uael-isotope','uael-element-resize','uael-fancybox','uael-slick','uael-social-share','mad4b-local-gallery-a11y','mad4b-local-archive-a11y','wpml-language-switcher-block-keyboard-navigation'] as $h){
 must(strpos($allowlist,"'".$h."'")!==false,'audited leaf '.$h);
}
foreach(['wp-hooks','wp-i18n','jet-smart-filters','elementor-frontend','fluent-form-submission','jquery','travel-booking-nationality-switcher'] as $critical){
 must(strpos($allowlist,"'".$critical."'")===false,'never defer critical '.$critical);
}
must(strpos($s,'update_option(')===false&&strpos($s,'wp_cache_set(')===false,'no DB/cache mutation');
