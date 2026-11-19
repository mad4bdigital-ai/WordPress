#!/usr/bin/env python3
from pathlib import Path
import sys
source=Path(sys.argv[1]).read_text()
checks={
'no stale HTML during policy or rate refresh':'fastcgi_cache_use_stale off;' in source,
'no stale background revalidation':'fastcgi_cache_background_update off;' in source,
'one browser cache policy owner':'fastcgi_hide_header Cache-Control;' in source,
'error and non-2xx responses retain explicit no-store':
    'add_header Cache-Control $wayfa_browser_html_policy always;' in source,
'signed archive only exact canonical URI':'"/tours-and-activities/" 1;' in source,
'no unfiltered tour route wildcard in signed allowlist':'''map $request_uri $mad4b_signed_home_path_v1 {
    default 0;
    "/" 1;
    "/es/" 1;''' in source and '"/tours-and-activities/" 1;' in source,
'never allow unproven origin signed HTML':'"1|1" 0;' in source and 'mad4b_signed_home_upstream_veto_v1' in source,
'pass market HMAC origin vetting':'$cookie_mad4b_country_selected_v1:passport=$cookie_travel_booking_nationality_preference_v1' in source,
'separate browser no-store for signed HTML':'private, no-store, max-age=0' in source,
'versioned path invalidation still used':'$mad4b_fastcgi_version$mad4b_public_html_global_epoch' in source,
}
for title,result in checks.items():
 assert result,title
 print('PASS',title)
print('SIGNED_ARCHIVE_NGINX_SAFETY_CONTRACT',len(checks))
