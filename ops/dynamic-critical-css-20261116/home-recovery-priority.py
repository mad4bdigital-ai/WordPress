#!/usr/bin/env python3
"""Reserve Chromium opportunity for a degraded Wayfatours EN/ES home.

Pure policy: local watchdog evidence, never site writes or browser starts.
After 2h degradation, allow 1/4 public windows to prevent starvation.
"""
import json,pathlib,time
CONTRACT='mad4b.performance-continuity.mobile-home.v1'
REPORT=pathlib.Path('/opt/mad4b-company-provisioning/reports/performance-continuity-v2/wayfa-home.json')

def decision(report,now):
    if not isinstance(report,dict) or report.get('contract')!=CONTRACT or report.get('host')!='wayfatours.com':
        return ('allow','missing_or_untrusted_readback')
    if report.get('state')!='degraded':return ('allow','site_home_not_degraded')
    observed=report.get('observed_at');since=report.get('degraded_since')
    if not isinstance(observed,int) or not isinstance(since,int) or observed>now or since>now:
        return ('allow','invalid_age')
    if now-observed>1800:return ('allow','stale_readback_no_priority_lockout')
    age=now-since
    if age<7200:return ('defer','home_restore_urgent_under_2h')
    if (now//420)%4!=0:return ('defer','home_restore_priority_fair_share')
    return ('allow','fair_share_public_route_window')

def from_file(path=REPORT,now=None):
    now=int(time.time()) if now is None else now
    if path.is_symlink() or not path.is_file() or path.stat().st_size>8192:
        return ('allow','missing_or_untrusted_readback')
    try:return decision(json.loads(path.read_text()),now)
    except (ValueError,OSError,TypeError,json.JSONDecodeError):return ('allow','unreadable_readback')
