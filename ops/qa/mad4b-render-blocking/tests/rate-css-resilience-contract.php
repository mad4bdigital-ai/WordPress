<?php
define('ABSPATH','/');
$meta=[];$options=[];$events=[];
function add_action(...$x){}
function wp_get_environment_type(){return 'staging';}
function wp_parse_url($u,$k){return parse_url($u,$k);}
function home_url(){return 'https://tours.dev.mad4b.com';}
function get_option($k,$d=[]){global $options;if($k==='mad4b_scp_brand_context_profile_v1')return ['site_uuid'=>'e5e64bca-ed62-48d2-b2b3-dff693cd702a'];return $options[$k]??$d;}
function get_post_meta($id,$key,$single){global $meta;return $meta[$id][$key]??null;}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_preview(){return false;}
function is_singular($type){return $type==='tours-and-activities';}
function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function wp_next_scheduled($hook){global $events;return $events[$hook]??false;}
function wp_schedule_single_event($time,$hook){global $events;$events[$hook]=$time;return true;}
$check=static function($ok,$label){if(!$ok){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}echo 'PASS '.$label."\n";};
require dirname(__DIR__,4).'/wp-content/mu-plugins/312-mad4b-rate-css-resilience.php';
$selector='.elementor-56197 .elementor-element.elementor-element-2d192ba .jet-toggle__label-text{font-size:var(--e-global-typography-accent-font-size)}';
$css=str_repeat($selector,35);
$check(MAD4B_Rate_CSS_Resilience_V1::valid_css($css),'CSS source-derived selector and typography valid');
$check(!MAD4B_Rate_CSS_Resilience_V1::valid_css($css.'</style><script>bad()</script>'),'injected style terminator rejected');
$meta[56197]['_elementor_css']=['css'=>$css,'status'=>'inline'];
$current=MAD4B_Rate_CSS_Resilience_V1::shadow();
$check($current['source']==='elementor_current_meta','canonical Elementor CSS preferred');
ob_start();MAD4B_Rate_CSS_Resilience_V1::render();$out=ob_get_clean();
$check(substr_count($out,'id="mad4b-rate-css-shadow"')===1,'guaranteed early shadow in tour page HTML');
$check(str_contains($out,'var(--e-global-typography-accent-font-size)'),'global typography is preserved, not overwritten');
$check(str_contains($out,'data-css-sha256="'.hash('sha256',$css).'"'),'exact source CSS fingerprint published');
$options['mad4b_rate_css_shadow_v1']=['css'=>$css,'sha256'=>hash('sha256',$css)];
unset($meta[56197]['_elementor_css']);
$fallback=MAD4B_Rate_CSS_Resilience_V1::shadow();
$check($fallback['source']==='verified_last_known_good','verified fallback survives upstream generated CSS loss');
ob_start();MAD4B_Rate_CSS_Resilience_V1::render();$backup_out=ob_get_clean();
$check(str_contains($backup_out,'data-source="verified_last_known_good"'),'fallback HTML served independently from original style');
$options['mad4b_rate_css_shadow_v1']['css']='incompatible';
$check(MAD4B_Rate_CSS_Resilience_V1::shadow()===null,'corrupted shadow rejected');
MAD4B_Rate_CSS_Resilience_V1::saved(56197);
$t=count($events);MAD4B_Rate_CSS_Resilience_V1::saved(56197);
$check($t===1&&count($events)===1,'event regeneration is deduplicated');
MAD4B_Rate_CSS_Resilience_V1::saved(12345);
$check(count($events)===1,'unrelated content change does not regenerate CSS');
