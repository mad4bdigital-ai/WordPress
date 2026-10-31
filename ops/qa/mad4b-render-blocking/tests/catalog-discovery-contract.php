<?php
/* Staging source-only contract; no WordPress, network or mutations. */
define('ABSPATH','/');define('MINUTE_IN_SECONDS',60);
function add_action(...$a){}
function wp_get_environment_type(){return 'staging';}
function home_url(){return 'https://tours.dev.mad4b.com';}
function wp_parse_url($v,$part){return parse_url($v,$part);}
function get_option($k,$default=[]){if($k==='mad4b_scp_brand_context_profile_v1')return ['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'];return $default;}
function is_admin(){return false;}function is_post_type_archive($a){return $a==='tours-and-activities';}
function wp_doing_ajax(){return false;}function is_feed(){return false;}
function get_post_meta($id,$key,$s){return ['status'=>'inline','time'=>1800000000,
 'css'=>'.elementor-'.$id.' .elementor-element.elementor-element-'.($id===25102?'82a8340':'7d5f640').'{--display:flex;--flex-direction:row;}'.str_repeat(' ',1300)];}
function get_post_modified_time(){return 1790000000;}
function wp_register_style($handle,...$rest){$GLOBALS['handles'][]=$handle;}
function wp_enqueue_style($handle){$GLOBALS['enqueued'][]=$handle;}
function wp_add_inline_style($handle,$css){$GLOBALS['styles'][$handle]=$css;}
function esc_url($a){return $a;}
function content_url($x){return 'https://tours.dev.mad4b.com/wp-content'.$x;}
function is_front_page(){return true;}
function is_preview(){return false;}
function is_user_logged_in(){return false;}
function get_transient(){return false;}
function set_transient(){}function delete_transient(){}
require dirname(__DIR__,4).'/wp-content/mu-plugins/321-mad4b-public-catalog-first-render.php';
$assert=static function($value,$label){if(!$value)throw new RuntimeException($label);echo 'PASS '.$label."\n";};
MAD4B_Public_Catalog_First_Render_V1::archive_css();
$css=$GLOBALS['styles']['mad4b-catalog-listing-native-first-paint']??'';
$assert(str_contains($css,'elementor-element-82a8340') && str_contains($css,'elementor-element-7d5f640'),
 'initial catalog paint includes scoped native Elementor List and Grid CSS');
ob_start();MAD4B_Public_Catalog_First_Render_V1::discover_ard();$ard=ob_get_clean();
$assert(str_contains($ard,'rel="ard"') && str_contains($ard,'https://tours.dev.mad4b.com/.well-known/ard.json'),
 'ARD canonical resource URL does not acquire WPML locale prefix');
$source=file_get_contents(dirname(__DIR__,4).'/wp-content/mu-plugins/321-mad4b-public-catalog-first-render.php');
$assert(str_contains($source,'HTTP_COOKIE') && str_contains($source,'HTTP_AUTHORIZATION') &&
    str_contains($source,'woocommerce_currency') && str_contains($source,'woocommerce_default_customer_address') &&
    str_contains($source,'HTTP_CF_IPCOUNTRY') && str_contains($source,'X-MAD4B-Cache-Eligibility'),
    'anonymous microcache stays conditional on session, currency and geo safeguards');
$assert(str_contains($source,"apply_filters('wpml_object_id'") &&
    str_contains($source,"'elementor-element-82a8340'") &&
    str_contains($source,"'elementor-element-7d5f640'"),
    'CSS uses the WPML translated template and its real Elementor marker, not English-only source');
$assert(str_contains($source,'get_post_modified_time') && str_contains($source,'->update()') &&
    str_contains($source,'mad4b_catalog_css_repair_'),
    'native Elementor CSS invalidation can self-heal without front-end layout click');
