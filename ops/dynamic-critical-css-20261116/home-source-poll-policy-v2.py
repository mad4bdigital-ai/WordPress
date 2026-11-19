#!/usr/bin/env python3
"""Bounded trusted home-source CSS drift polling on measured fallback.

Only reduces a read-only public EN/ES observation interval. Never creates
certificates or changes WordPress, LiteSpeed, or the browser admission gate.
"""
import json,time,pathlib
LEDGER=pathlib.Path('/opt/mad4b-company-provisioning/reports/performance-continuity-v2/wayfa-home.json')
CONTRACT='mad4b.performance-continuity.mobile-home.v1'

def cooldown(now,report=None):
    report=LEDGER if report is None else pathlib.Path(report)
    if report.is_symlink() or not report.is_file() or report.stat().st_size>8192:return 3600
    try:d=json.loads(report.read_text())
    except (OSError,ValueError,TypeError,json.JSONDecodeError):return 3600
    if (d.get('contract')!=CONTRACT or d.get('host')!='wayfatours.com'
        or d.get('state')!='degraded'):return 3600
    seen=d.get('observed_at')
    if not isinstance(seen,int) or seen<0 or seen>now or now-seen>1800:return 3600
    routes=d.get('routes')
    if not isinstance(routes,dict) or set(routes)!=set(('en','es')):return 3600
    if not any(r.get('http_status')==200 and r.get('css_ready')==0
               for r in routes.values() if isinstance(r,dict)):return 3600
    # Critical recovery: one cheap two-home observer opportunity per 5min.
    # All Chromium/SSL/cache rules remain unchanged.
    return 300
