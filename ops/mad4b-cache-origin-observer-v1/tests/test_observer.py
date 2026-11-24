import importlib.util
import hashlib
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("cache_observer",ROOT/"bin/mad4b-cache-origin-observer-v1.py")
o=importlib.util.module_from_spec(spec)
spec.loader.exec_module(o)
rid="1"*32
source="MAD4B_CACHE_DECISION_V1 site=source ts=1795377909.032 rid="+rid+" route=home status=200 cache=MISS rt=5.026 origin=5.025 reqskip=0 cookie=0 auth=0 query=0 context=0 ip_hold=0 optin=0 private=0 policy_private=0 set_cookie=0"
class Contracts(unittest.TestCase):
 def test_privacy_flags(self):
  x=o.parse_entry(source,"source")
  self.assertEqual(x["cache"],"MISS")
  self.assertEqual(x["origin_seconds"],5.025)
  self.assertNotIn("rid",x)
  self.assertNotIn("cookie_value",x)
  self.assertNotIn("ip_address",x)
 def test_bad_input_rejected(self):
  self.assertIsNone(o.parse_entry(source.replace("route=home","route=/account/private"),"source"))
  self.assertIsNone(o.parse_entry(source.replace("cookie=0","cookie=not-safe"),"source"))
  self.assertIsNone(o.parse_entry(source.replace("site=source","site=wayfa"),"source"))
  self.assertIsNone(o.parse_entry(source.replace("rt=5.026","rt=999999"),"source"))
 def test_bind_parity_hold_and_no_automatic_restart(self):
  with tempfile.TemporaryDirectory() as d:
   p=Path(d)/"nginx.conf"
   p.write_text("example\n")
   p.chmod(0o644)
   digest=hashlib.sha256(p.read_bytes()).hexdigest()
   class Good:
    returncode=0
    stdout=digest+"  /etc/nginx/conf.d/default.conf\n"
   class Bad:
    returncode=0
    stdout=("0"*64)+"  /etc/nginx/conf.d/default.conf\n"
   with patch.object(o,"run_cmd",return_value=Good()):
    r=o.host_bind_parity(str(p),"fake")
    self.assertEqual(r["state"],"SYNCED")
    self.assertFalse(r["automatic_nginx_restart_allowed"])
   with patch.object(o,"run_cmd",return_value=Bad()):
    self.assertEqual(o.host_bind_parity(str(p),"fake")["state"],"DRIFT_HOLD")
 def test_nginx_patch_does_not_modify_cache_policy(self):
  lines=(ROOT/"patches/toursdev-nginx-cache-classifier-v1.patch").read_text().splitlines()
  self.assertTrue(any("mad4b_cache_decision_v1" in x for x in lines))
  self.assertFalse(any(x.startswith("-") and not x.startswith("---") for x in lines))
  self.assertFalse(any(x.startswith("+") and ("fastcgi_cache_key " in x or "fastcgi_no_cache " in x or "fastcgi_cache_bypass " in x) for x in lines))
 def test_timers_are_independent(self):
  for site in ("source","wayfa"):
   s=(ROOT/"systemd"/("mad4b-"+site+"-cache-origin-observer.service")).read_text()
   t=(ROOT/"systemd"/("mad4b-"+site+"-cache-origin-observer.timer")).read_text()
   self.assertIn("--site "+site,s)
   self.assertIn("OnUnitInactiveSec=1800s",t)
   self.assertIn("PrivateTmp=true",s)
if __name__=="__main__":unittest.main()
