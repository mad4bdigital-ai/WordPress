<?php
define('ABSPATH','/');
function add_action($event,$callback,$priority=10){return true;}
require_once '/var/www/html/wp-content/mu-plugins/431-mad4b-wayfa-private-signed-home-fastcgi.php';
$cl='MAD4B_Wayfa_Signed_Home_FastCGI_V1';
function check($label,$result){if(!$result){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(2);}echo 'PASS '.$label.PHP_EOL;}
$valid='mad4b_country_selected_v1='.str_repeat('A',42).'.'.str_repeat('a',64);
check('one syntactically valid signed-cookie candidate',$cl::allowed_cookie_context($valid));
check('tracking + locale allowed',$cl::allowed_cookie_context('_ga=GA1.2.123; '.$valid.'; wp-wpml_current_language=en'));
check('unrecognized booking cookie denied',!$cl::allowed_cookie_context($valid.'; wp_woocommerce_session_x=abc'));
check('duplicate country denied',!$cl::allowed_cookie_context($valid.'; '.$valid));
check('forged nonsigned country format denied',!$cl::allowed_cookie_context('mad4b_country_selected_v1=EG'));
check('invalid passport signature shape denied',!$cl::allowed_cookie_context($valid.'; travel_booking_nationality_preference_v1=abc'));
$nationality='travel_booking_nationality_preference_v1='.str_repeat('B',42).'.'.str_repeat('b',64);
check('one country plus separate nationality signature candidate',$cl::allowed_cookie_context($valid.'; '.$nationality));
check('duplicate nationality denied',!$cl::allowed_cookie_context($valid.'; '.$nationality.'; '.$nationality));
check('malformed market locale denied',!$cl::allowed_cookie_context($valid.'; wp-wpml_current_language=fr'));
check('unknown auxiliary cookie denied',!$cl::allowed_cookie_context($valid.'; secret=abc'));
check('empty token denied',!$cl::allowed_cookie_context('mad4b_country_selected_v1='));
check('newline injection denied',!$cl::allowed_cookie_context($valid."\nX-Inject: 1"));
echo 'SIGNED_ORIGIN_HOME_CACHE_ADMISSION 12'.PHP_EOL;
