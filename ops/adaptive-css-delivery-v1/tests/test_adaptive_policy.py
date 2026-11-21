import sys,pathlib,unittest
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'ops'))
import adaptive_policy as p
class TestAdaptive(unittest.TestCase):
 def setUp(self):
  self.c={'site':'wayfa','host':'wayfatours.com','language':'es',
    'market':'EG','currency':'USD','device':'mobile','template':'tour',
    'css_epoch':27,'dependency_sha':'a'*64,'candidate_sha':'b'*64}
  self.key=p.source_variant(self.c)
  self.proof={'site':'wayfa','variant':self.key,'source_current':True,
     'qa_5_viewports':True,'cache_separation_verified':True,
     'signed_release_proof':True}
 def rows(self,base=200,trial=200,huge=False):
  out=[]
  for metric in p.METRICS:
   a=[0]*(len(p.BOUNDS[metric])+1);b=a.copy()
   a[1]=base;b[2 if huge else 1]=trial
   out.extend([{'variant':self.key,'arm':arm,'metric':metric,'histogram':h}
               for arm,h in (('baseline',a),('canary',b))])
  return out
 def test_first_staged(self):
  r=p.decision(self.c,[],0,self.proof)
  self.assertEqual(r['recommended_percent'],1)
  self.assertFalse(r['public_traffic_changed'])
  self.assertFalse(r['can_issue_certificate'])
 def test_no_false_approval(self):
  f=dict(self.proof,cache_separation_verified=False)
  self.assertEqual(p.decision(self.c,[],0,f)['recommended_percent'],0)
 def test_no_sample_auto_promotion(self):
  self.assertEqual(p.decision(self.c,[],1,self.proof)['action'],'hold')
 def test_rollout_eligible_not_executed(self):
  r=p.decision(self.c,self.rows(420,420),1,self.proof)
  self.assertEqual(r['recommended_percent'],5)
  self.assertEqual(r['action'],'eligible_for_manual_ramp')
  self.assertFalse(r['public_traffic_changed'])
 def test_rollout_hold_missing_metric(self):
  r=p.decision(self.c,self.rows()[:-1],1,self.proof)
  self.assertEqual(r['recommended_percent'],1)
 def test_regression_recommend_revert(self):
  x=self.rows(80,80)
  for row in x:
   if row['arm']=='canary':
    row['histogram']=[0]*len(row['histogram'])
    row['histogram'][-1]=80
  self.assertEqual(p.decision(self.c,x,1,self.proof)['recommended_percent'],0)
 def test_trust_scope(self):
  self.c['host']='other.com'
  with self.assertRaises(ValueError):p.source_variant(self.c)
 def test_no_cohort_mixing(self):
  x=self.rows()
  for row in x:row['variant']='c'*64
  self.assertEqual(p.decision(self.c,x,1,self.proof)['action'],'hold')
 def test_duplicate_arm(self):
  x=self.rows()
  x.append(x[0].copy())
  with self.assertRaises(ValueError):p.decision(self.c,x,1,self.proof)
 def test_invalid_percent(self):
  with self.assertRaises(ValueError):p.decision(self.c,[],3,self.proof)
 def test_histogram_bad_input(self):
  with self.assertRaises(ValueError):p.histogram_quantile([1,False], 'lcp')
 def test_variant_changes_epoch(self):
  a=self.key;self.c['css_epoch']+=1
  self.assertNotEqual(a,p.source_variant(self.c))
 def test_variant_changes_market(self):
  a=self.key;self.c['market']='US'
  self.assertNotEqual(a,p.source_variant(self.c))
 def test_overflow_histogram_is_finite_and_fail_closed(self):
  for metric in p.METRICS:
   hist=[0]*(len(p.BOUNDS[metric])+1);hist[-1]=500
   self.assertEqual(p.histogram_quantile(hist,metric),60001)
 def test_max_rollout(self):
  self.assertEqual(p.decision(self.c,self.rows(2000,2000),100,self.proof)['action'],'maintain')
if __name__=='__main__':unittest.main(verbosity=2)
