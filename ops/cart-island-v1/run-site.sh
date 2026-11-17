#!/usr/bin/env bash
set -euo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
ROOT=/opt/mad4b-company-provisioning/ops/cart-cache-boundary-v1
PHP=php-guxhp9y6vgh7oouuw8plxrlr
TMP=$(mktemp /run/mad4b-cart-routes-XXXXXXXX.json)
trap 'rm -f "$TMP"' EXIT
WP_CODE=$(cat <<'WP'
$site_host=strtolower((string)wp_parse_url((string)get_option("home"),PHP_URL_HOST));
$mode=(string)get_option("mad4b_woocommerce_public_experience_v1","payment_only");
global $sitepress;
$routes=["/","/es/"];
foreach(["en","es"] as $l) {
 if(is_object($sitepress)&&method_exists($sitepress,"switch_lang"))$sitepress->switch_lang($l);
 $url=get_post_type_archive_link("tours-and-activities");
 $routes[]=is_string($url)?(string)wp_parse_url($url,PHP_URL_PATH):"";
}
foreach(["en","es"] as $l) {
 if(is_object($sitepress)&&method_exists($sitepress,"switch_lang"))$sitepress->switch_lang($l);
 $posts=get_posts(["post_type"=>"tours-and-activities","post_status"=>"publish",
                   "posts_per_page"=>1,"suppress_filters"=>false]);
 $url=$posts?get_permalink($posts[0]->ID):"";
 $routes[]=is_string($url)?(string)wp_parse_url($url,PHP_URL_PATH):"";
}
echo wp_json_encode(["contract"=>"mad4b.cart-cache-boundary.wpml-routes.v1",
                     "site_host"=>$site_host,"mode"=>$mode,"routes"=>$routes],
                    JSON_UNESCAPED_SLASHES);
WP
)
docker exec "$PHP" sh -lc 'cd /var/www/html && wp --allow-root eval "$1" 2>/dev/null' sh "$WP_CODE" > "$TMP"
MODE=$(python3 - "$TMP" <<'PY'
import json,sys
d=json.load(open(sys.argv[1]))
assert d.get('contract')=='mad4b.cart-cache-boundary.wpml-routes.v1'
assert d.get('site_host')=='wayfatours.com'
assert d.get('mode') in ('payment_only','standard')
print(d['mode'])
PY
)
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
OUTPUT="$ROOT/evidence/"$STAMP"-read-only.json"
python3 "$ROOT/probe.py" --mode "$MODE" --routes-json "$TMP" --output "$OUTPUT"
# Report has synthetic cookie categories and public URLs only.
find "$ROOT/evidence" -maxdepth 1 -type f -name '*-read-only.json' -mtime +10 -delete
echo "MAD4B_CART_BOUNDARY_VERIFIED mode=$MODE evidence=$OUTPUT"
