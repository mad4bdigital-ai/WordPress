"""Regression guard for site-only WPML country labels in Fluent Forms and passport switcher."""
from pathlib import Path
import hashlib
import re

root = Path(__file__).resolve().parents[4]
mu = (root / "wp-content/mu-plugins/327-mad4b-fluent-passport-locale.php").read_text()
js_path = root / "wp-content/plugins/etg-traveller-nationality-switcher/assets/nationality.js"
js = js_path.read_text()
native = (root / "wp-content/plugins/etg-traveller-nationality-switcher/etg-traveller-nationality-switcher.php").read_text()
first_paint = (root / "wp-content/mu-plugins/326-mad4b-nationality-server-first-paint.php").read_text()
for guard in [
 "wp_get_environment_type()!=='staging'",
 "e5e64bca-ed62-48d2-b2b3-dff693cd702a",
 "getDisplayRegion('und_'.$code",
 "woocommerce_countries",
 "fluentform/rendering_form",
 "fluentform/rendering_field_data_select",
 "fluentform/rendering_field_data_select_country",
 "fluentform/rendering_field_html_select",
 "fluentform/rendering_field_html_select_country",
 "wp_footer",
 "visitor-preferences:saved",
 "mad4bNationalityEdited",
 "choicesjs",
 "travel_booking_passport_country",
 "country-list",
 "dropdown_1",
 "selected_manual()",
 "LEGACY_VALUES_SHA",
 "function_exists('getFluentFormCountryList')",
 "data-passport-iso",
 "self::language()",
 "['required'=>['value'=>false",
]:
 assert guard in mu, guard
for lang in ["en", "ar", "fr", "de", "es", "it"]:
 assert "'"+lang+"'=>" in mu or "'"+lang+"'=>'"+lang+"'" in mu
codes = re.search(r"private const LEGACY_CODES='([^']+)'", mu).group(1).split()
assert len(codes) == 195, len(codes)
assert codes[54] == "EG"
assert codes[67] == "DE"
assert codes[3] == "US"
assert codes[134] == "PS"
assert "LEGACY_VALUES_SHA='b245925f0bb1c3ee0aa5ac9d9a70c6703c97da9f2aa13f798f9738249610d5b7'" in mu
assert "$opts[$i]['label']=$localized;" in mu
assert "$opts[$i]['value']=" not in mu, "legacy submitted values must remain stable"
assert "mb_strtolower($term,'UTF-8')" in native
assert "mb_strtolower($name,'UTF-8')" in native
assert "The REST route uses the active WPML language" in js
assert "Intl.DisplayNames(['en']" not in js, "localized search must not be rewritten in English"
sha = hashlib.sha256(js_path.read_bytes()).hexdigest()
assert sha in first_paint, "first-paint checksum must match revised localized-search JS"
assert "travelBookingNationalityServerFirstPaint=true" in first_paint
for bad in ["wp_cache_set(", "set_transient(", "update_option(", "delete_option(", "remove_all_actions("]:
 assert bad not in mu, bad
print("PASS: six language labels, 195 legacy values immutable, ISO2 controls, full-page preselection, Choices sync, native/REST Unicode parity")
