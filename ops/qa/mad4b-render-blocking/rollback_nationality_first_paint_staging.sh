#!/usr/bin/env bash
# Restore the pre-change nationality UI asset only on the enrolled Staging site.
set -euo pipefail
root=/opt/hostinger-migrations/hosting-3/etg-commerce-staging
asset="$root/site-copy/wp-content/plugins/etg-traveller-nationality-switcher/assets/nationality.js"
module="$root/site-copy/wp-content/mu-plugins/326-mad4b-nationality-server-first-paint.php"
saved="$root/qa/nationality-first-paint-20261101/nationality-before.js"
expected_current=2597f54af9bef4ce90153ec1798853c4ae01b5dd69f96ae68d8efa341d3e7b44
expected_saved=f9048b712a211ea93fa5ebbb0f5497a5112ad4f49caa0e91aacab081c522eed7
site=$(docker exec etg-commerce-staging-php wp option get siteurl --allow-root)
env=$(docker exec etg-commerce-staging-php wp eval 'echo wp_get_environment_type();' --allow-root)
uuid=$(docker exec etg-commerce-staging-php wp option get mad4b_scp_site_profile_v2 --format=json --allow-root | python3 -c 'import json,sys;print(json.load(sys.stdin).get("site_uuid",""))')
if [[ "$site" != https://tours.dev.mad4b.com || "$env" != staging || "$uuid" != e5e64bca-ed62-48d2-b2b3-dff693cd702a ]];then
 echo "FAIL: not the enrolled Staging site" >&2;exit 3
fi
sha(){ sha256sum "$1" | cut -d' ' -f1; }
test -r "$saved" && test "$(sha "$saved")" == "$expected_saved"
if [[ ! -e "$module" ]];then
 echo "No active module to roll back" >&2;exit 4
fi
if [[ "$(sha "$asset")" != "$expected_current" ]];then
 echo "FAIL: JavaScript changed since acceptance; refuse to overwrite" >&2;exit 5
fi
if [[ "$#" -eq 0 || "$1" != apply ]];then
 echo "PLAN only. To restore old asset and remove Staging MU #326, pass 'apply'";exit 0
fi
cp "$saved" "$asset"
chgrp www-data "$asset"
chmod 660 "$asset"
rm "$module"
test "$(sha "$asset")" == "$expected_saved"
echo "STAGING ROLLBACK OK"
