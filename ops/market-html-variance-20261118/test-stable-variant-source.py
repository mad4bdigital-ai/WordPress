#!/usr/bin/env python3
"""Source-only guard against accidentally restoring per-timestamp cache keys."""
from pathlib import Path
from sys import argv
root=Path(argv[1]).resolve()
overlay=root/'ops/market-html-variance-20261118/wayfa-live-overlay/wp-content'
s=(overlay/'plugins/mad4b-country-switcher/mad4b-country-switcher.php').read_text()
p=(overlay/'plugins/mad4b-traveller-nationality/mad4b-traveller-nationality.php').read_text()
b=(overlay/'mu-plugins/mad4b-visitor-state-batch.php').read_text()
n=(root/'ops/market-html-variance-20261118/wayfa-nginx-private-signed-home.conf').read_text()
a=(overlay/'mu-plugins/431-mad4b-wayfa-private-signed-home-fastcgi.php').read_text()
checks=[
 ('country signer deterministic', "public static function canonical_cookie($country)" in s and "'time'=>self::epoch_slot()" in s),
 ('passport signer deterministic', "public static function canonical_cookie($country)" in p and "'saved_at'=>self::epoch_slot()" in p),
 ('country explicit selection invokes stable signer','self::canonical_cookie($iso)' in s),
 ('passport explicit selection invokes stable signer','self::canonical_cookie($iso)' in p),
 ('one-time cookie upgrade private only',"public static function state(): void" in b and "setcookie('mad4b_country_selected_v1',$canonical" in b and "setcookie('travel_booking_nationality_preference_v1',$canonicalPassport" in b),
 ('migration refuses signed session contexts',"!$sensitive && !$hasWCSession && !is_user_logged_in()" in b),
 ('signed origin admits on verified HMAC', "MAD4B_Country_Switcher::saved_country()" in a and "Travel_Booking_Traveller_Nationality_Switcher::selected_manual()" in a),
 ('signed origin 150-second max', 'const SECONDS=150;' in a),
 ('public html not browser stored', 'private, no-store, max-age=0' in n),
 ('signed host key includes exact verified cookies', 'country=$cookie_mad4b_country_selected_v1:passport=$cookie_travel_booking_nationality_preference_v1' in n),
 ('country server expiry and zero-time grace cutoff',
    'LEGACY_ZERO_EXPIRES=1803859200' in s and 'signed_age_valid' in s),
 ('passport server expiry and zero-time grace cutoff',
    'LEGACY_ZERO_EXPIRES=1803859200' in p and 'signed_age_valid' in p),
 ('no debug header or cookie data in origin responses', 'X-MAD4B-Signed-Cache-Diag' not in n),
 ('checkout and Woo bypass preserved', '$wayfa_html_no_cache_path $wayfa_html_no_cache_request' in n and 'wp_woocommerce_session' not in n.split('map $http_cookie $mad4b_signed_home_cookie_shape_v1 {')[1].split('}')[0]),
 ('signed CSS proof required', '$upstream_http_x_mad4b_private_mobile_css_ready' in n),
 ('client cannot opt into saved public shell', 'HTTP_X_MAD4B_BYPASS_CACHE' in a and 'HTTP_X_MAD4B_MARKET_CONTEXT' in a),
]
for name,ok in checks:
 assert ok,name
 print('PASS',name)
print('STABLE_SIGNED_VARIANT_SOURCE_INVARIANTS',len(checks))
