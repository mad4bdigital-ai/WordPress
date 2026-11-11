<?php
/**
 * Plugin Name: Company-neutral administration links
 * Description: Public admin route aliases for inherited JetEngine settings pages.
 */
defined('ABSPATH') || exit;
if (!is_admin()) return;

function mad4b_company_admin_page_aliases(): array {
    return [
        'etg-dynamic-pricing-controls' => 'dynamic-pricing-controls',
        'abk-selling-options' => 'accommodation-selling-options',
        'abk-date-formats' => 'accommodation-date-formats',
    ];
}

function mad4b_company_admin_link(string $url): string {
    return preg_replace_callback('/([?&]|&amp;)page=([a-z0-9_-]+)(?=[&#]|$)/', static function ($m) {
        return $m[1].'page='.(mad4b_company_admin_page_aliases()[$m[2]] ?? $m[2]);
    }, $url);
}

add_filter('wp_redirect','mad4b_company_admin_link');
add_filter('clean_url',static function ($url,$original,$context) {
    return $context==='display' ? mad4b_company_admin_link($url) : $url;
},20,3);
add_filter('jet-engine/compatibility/translate-string',static function ($label) {
    return ['ETG Dynamic Pricing Controls'=>'Dynamic Pricing Controls','ETG Pricing Controls'=>'Pricing Controls'][$label] ?? $label;
},1);

if (basename($_SERVER['SCRIPT_NAME'] ?? '')==='admin.php' && isset($_GET['page']) && is_string($_GET['page'])) {
    $aliases=mad4b_company_admin_page_aliases();
    $requested=$_GET['page'];
    if (isset($aliases[$requested]) && ($_SERVER['REQUEST_METHOD']??'')==='GET') {
        add_action('admin_init',static function () use ($aliases,$requested) {
            wp_safe_redirect(add_query_arg('page',$aliases[$requested],wp_unslash($_SERVER['REQUEST_URI'])),302);
            exit;
        },0);
    }
    $legacy=array_search($requested,$aliases,true);
    if ($legacy!==false) {
        // WordPress and JetEngine retain the registered capability, nonce and storage prefix.
        $_GET['page']=$legacy;
        $_REQUEST['page']=$legacy;
    }
}
