#!/usr/bin/env python3
"""CI-only regression: all company-team REST routes require the company capability."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
source = (ROOT / 'includes/class-mad4b-scp-company-master-admin.php').read_text()
assert re.search(r'public static function can\(\):bool\s*\{return current_user_can\(self::CAP\);\}', source)
assert "'permission_callback'=>array(__CLASS__,'can')" in source
route = source.split('public static function routes():void {', 1)[1].split('public static function overview(',1)[0]
paths = ('/admin/company-team', '/admin/company-team/create', '/admin/company-team/translation-manager', '/admin/company-team/assign-task')
for index, path in enumerate(paths):
    marker = "register_rest_route('mad4b/v1','" + path + "'"
    assert route.count(marker) == 1, 'missing/duplicate route: ' + path
    part = route.split(marker, 1)[1].split('register_rest_route(',1)[0]
    assert part.count("'permission_callback'=>array(__CLASS__,'can')") == 1, 'unguarded route: ' + path
    assert "'methods'=>'" + ('GET' if index == 0 else 'POST') + "'" in part
assert route.count('register_rest_route(') == len(paths), 'unexpected endpoint without admission review'
assert "'status'=>403" in source and 'private static function forbidden()' in source
print('COMPANY_TEAM_ROUTE_AUTHORITY: PASS 4 guarded routes')
