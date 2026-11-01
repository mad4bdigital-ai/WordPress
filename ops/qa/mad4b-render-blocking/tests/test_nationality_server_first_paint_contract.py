"""Contract: only anonymous/default Staging SSR removes nationality boot GET."""
from pathlib import Path
r=Path(__file__).resolve().parents[4]
mu=r/'wp-content/mu-plugins/326-mad4b-nationality-server-first-paint.php'
js=r/'wp-content/plugins/etg-traveller-nationality-switcher/assets/nationality.js'
m=mu.read_text();s=js.read_text()
for required in [
"wp_get_environment_type()!=='staging'",
"tours.dev.mad4b.com",
"e5e64bca-ed62-48d2-b2b3-dff693cd702a",
"self::PATHS[$path]",
"'en','/fr/'=>'fr','/de/'=>'de','/ar/'=>'ar','/es/'=>'es','/it/'=>'it'",
"HTTP_COOKIE","HTTP_AUTHORIZATION","HTTP_X_WP_NONCE","HTTP_CF_IPCOUNTRY",
"HTTP_X_GEO_COUNTRY","HTTP_X_FORWARDED_GEO_COUNTRY",
"HTTP_X_MAD4B_CURRENCY","HTTP_X_REQUESTED_WITH",
"HTTP_X_MAD4B_NATIONALITY_CONTROL",
"is_user_logged_in()","is_front_page()","wp_doing_ajax()","is_preview()",
"get_option('woocommerce_default_customer_address')!=='base'",
"get_woocommerce_currency()!=='USD'",
"hash_file('sha256',$script)",
"wp_script_is('travel-booking-nationality-switcher','enqueued')",
"wp_add_inline_script('travel-booking-nationality-switcher'",
"window.travelBookingNationalityServerFirstPaint=true;",
"'before'",
]:
 assert required in m,required
for required in [
"document.querySelectorAll('.travel-booking-nationality-switcher')",
"forms.forEach(enhance)",
"window.travelBookingNationalityServerFirstPaint===true && forms.length",
"document.dispatchEvent(new CustomEvent('header-preferences:state'))",
"}else update();",
"travelBookingNationalityAjax",
"passport-search:sync",
"passport-preference:mode",
"passport-search:sync",
"travel-booking/v1/passport-options" if False else "cfg.dataset.endpoint",
"fetch(url.href",
"select.dispatchEvent(new Event('change'",
]:
 assert required in s,required
for forbidden in ["wp_cache_set(","set_transient(","update_option(","wp_send_json(","remove_all_actions("]:
 assert forbidden not in m,forbidden
assert "fetch(window.travelBookingNationalityAjax" in s
assert "setcookie(" not in m
print("PASS: six-locale Staging-only SSR read bypass, legacy AJAX fallback, country search, session/currency/geo guards, no writes")
