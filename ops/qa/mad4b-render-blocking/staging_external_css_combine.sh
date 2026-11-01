#!/usr/bin/env bash
# Operate official LiteSpeed CSS controls on one enrolled Staging WordPress site.
set -euo pipefail
action=plan
if (( $# > 0 ));then action="$1";fi
case "$action" in plan|apply|rollback|verify) ;; *) echo 'Usage: staging_external_css_combine.sh plan|apply|rollback|verify' >&2;exit 2;;esac
wp_container=etg-commerce-staging-php
wpc(){ docker exec "$wp_container" wp "$@" --allow-root; }
site=$(wpc option get siteurl)
env=$(wpc eval 'echo wp_get_environment_type();')
uuid=$(wpc option get mad4b_scp_site_profile_v2 --format=json | python3 -c 'import json,sys; print(json.load(sys.stdin).get("site_uuid",""))')
if [[ "$site" != 'https://tours.dev.mad4b.com' || "$env" != staging ||
      "$uuid" != 'e5e64bca-ed62-48d2-b2b3-dff693cd702a' ]];then
 echo "ERROR: enrolled Staging site identity does not match" >&2
 exit 3
fi
get(){ wpc option get "litespeed.conf.$1"; }
set_opt(){ wpc option update "litespeed.conf.$1" "$2"; }
before_comb=$(get optm-css_comb)
before_inline=$(get optm-css_comb_ext_inl)
printf 'STAGING host=%s action=%s before: css_comb=%s external_inline=%s\n' "$site" "$action" "$before_comb" "$before_inline"
case "$action" in
apply)
 if [[ "$before_comb" == 1 && "$before_inline" == 0 ]];then echo 'Already applied';exit 0;fi
 if [[ "$before_comb" != 0 || "$before_inline" != 1 ]];then echo 'Unexpected starting state: refuse to mutate' >&2;exit 4;fi
 restore(){ set_opt optm-css_comb_ext_inl 1 >/dev/null;set_opt optm-css_comb 0 >/dev/null; }
 trap restore ERR
 set_opt optm-css_comb_ext_inl 0
 set_opt optm-css_comb 1
 trap - ERR
 ;;
rollback)
 if [[ "$before_comb" == 0 && "$before_inline" == 1 ]];then echo 'Already rolled back';exit 0;fi
 if [[ "$before_comb" != 1 || "$before_inline" != 0 ]];then echo 'Unexpected starting state: refuse to mutate' >&2;exit 4;fi
 set_opt optm-css_comb_ext_inl 1
 set_opt optm-css_comb 0
 ;;
esac
after_comb=$(get optm-css_comb)
after_inline=$(get optm-css_comb_ext_inl)
printf 'After: css_comb=%s external_inline=%s\n' "$after_comb" "$after_inline"
if [[ "$action" == apply || "$action" == verify ]];then
 test "$after_comb" == 1 && test "$after_inline" == 0
elif [[ "$action" == rollback ]];then
 test "$after_comb" == 0 && test "$after_inline" == 1
fi
