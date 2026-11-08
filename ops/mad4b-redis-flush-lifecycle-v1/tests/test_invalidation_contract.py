from pathlib import Path
import unittest
P=Path(__file__).resolve().parent.parent
class ContractTests(unittest.TestCase):
 def test_property_noop_does_not_flush(self):
  s=(P/'bin/mad4b-property-type-fix-scoped.php').read_text()
  self.assertIn("if($schema_mutated){",s)
  self.assertIn("if($jet_schema_mutated || !$wpml_scoped_ok){",s)
  self.assertEqual(s.count('wp_cache_flush();'),1)
  self.assertIn("icl_cache_clear()",s)
  self.assertIn("clean_term_cache(",s)
 def test_guard_never_executes_redis_flush(self):
  s=(P/'bin/company-redis-flush-guard-reconcile-v1.py').read_text()
  self.assertNotIn("redis-cli FLUSH",s)
  self.assertIn("UNKNOWN_CHANGE_REFUSED",s)
  self.assertIn("CONCURRENT_WPCLI_RUNNING",s)
 def test_observer_excludes_raw_payloads(self):
  s=(P/'bin/company-redis-flush-origin-observe-v1.py').read_text()
  self.assertNotIn("redis-cli MONITOR",s)
  self.assertIn("audit_only",s)
 def test_systemd_service_enables_noop_guard(self):
  svc=(P/'systemd/mad4b-company-redis-flush-guard.service').read_text()
  self.assertIn("--apply --strict",svc)
  self.assertIn('NoNewPrivileges=true',svc)
  timer=(P/'systemd/mad4b-company-redis-flush-guard.timer').read_text()
  self.assertIn("OnUnitInactiveSec=30min",timer)
if __name__=='__main__':unittest.main()
