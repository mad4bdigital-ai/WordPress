import importlib.util
from pathlib import Path
import unittest
P=Path(__file__).resolve().parent.parent/'bin/mad4b-public-object-cache-warm.py'
spec=importlib.util.spec_from_file_location('cache_warm',P)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
class Mock:
 def __init__(self,body,rc=0):self.stdout=body;self.stderr='';self.returncode=rc
class RedisEpoch(unittest.TestCase):
 def setUp(self):
  self.old=m.run
 def tearDown(self):
  m.run=self.old
 def read(self,db=0,all_=0,runid='abcdef'):
  m.run=lambda args,timeout=15:Mock(f'cmdstat_flushdb:calls={db}\ncmdstat_flushall:calls={all_}\nrun_id:{runid}\n')
  return m.redis_flush_epoch('fake-redis')
 def test_same_epoch_stable(self):
  self.assertEqual(self.read(),self.read())
 def test_flushdb_increment_invalidates(self):
  self.assertNotEqual(self.read(),self.read(db=1))
 def test_flushall_increment_invalidates(self):
  self.assertNotEqual(self.read(),self.read(all_=1))
 def test_redis_restart_invalidates(self):
  self.assertNotEqual(self.read(),self.read(runid='123456'))
 def test_fails_closed_on_missing_commandstats(self):
  m.run=lambda args,timeout=15:Mock('missing')
  self.assertRaises(RuntimeError,m.redis_flush_epoch,'fake')
 def test_rejects_unavailable_container(self):
  m.run=lambda args,timeout=15:Mock('',rc=1)
  self.assertRaises(RuntimeError,m.redis_flush_epoch,'fake')
if __name__=='__main__':unittest.main()
