"""Verify only two JS semantic changes address the missing Rate Apply toolbar."""
import hashlib
import json
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
EVIDENCE=ROOT/'docs/release-coverage/unified-date-043-apply-fallback-20261112.json'
BASE='wp-content/plugins/mad4b-commerce/modules/presentation/'
JS=BASE+'assets/unified-date-v0.4.3.js'
PHP=BASE+'unified-date-presentation-v1.php'
CSS=BASE+'assets/unified-date-v0.3.18.css'
ADDED_COMMENT="""    // Elementor layouts do not always place both JetSmartFilters selects
    // beside the native Date Range. Without a toolbar there was no Apply,
    // leaving a user who presses Clear permanently in a Pending state.
    if(group&&!group.querySelector('.mad4b-rate-filter-row')){
      const toolbar=document.createElement('div');"""
ORIGINAL_COMMENT="""    if(group&&selects.length>=2&&!group.querySelector('.mad4b-rate-filter-row')){
      const toolbar=document.createElement('div');"""
ADDED_LAYOUT="""      group.insertBefore(toolbar,widget);
      toolbar.append(widget);
      // Keep the existing combined layout unchanged when the two native
      // select widgets are siblings. Otherwise give the Date + Apply
      // controls a two-column fallback rather than a dead-end date picker.
      if(selects.length>=2){
        toolbar.append(selects[0],selects[1]);
      }else{
        toolbar.classList.add('mad4b-rate-filter-row--date-only');
        toolbar.style.setProperty('grid-template-columns',
          'minmax(0,1fr) minmax(92px,auto)','important');
      }
      toolbar.append(apply);
      note.classList.add('mad4b-rate-filter-help');"""
ORIGINAL_LAYOUT="""      group.insertBefore(toolbar,widget);
      toolbar.append(widget,selects[0],selects[1],apply);
      note.classList.add('mad4b-rate-filter-help');"""
def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()
class NativeDateApply(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m=json.loads(EVIDENCE.read_text())
    def test_three_source_artifacts_fingerprinted(self):
        m=self.m
        self.assertEqual(m['contract'],'mad4b.unified-date-v043-missing-rate-apply-source-fix.v1')
        self.assertEqual(set(m['files']),{JS,PHP,CSS})
        for path,item in m['files'].items():
            self.assertEqual(sha(ROOT/path),item['candidate_sha256'])
        self.assertFalse(m['release_authorized'])
        self.assertFalse(m['original313_receipt_obtained'])
    def test_only_two_small_reviewed_js_transformations(self):
        source=(ROOT/JS).read_text()
        self.assertEqual(source.count(ADDED_COMMENT),1)
        self.assertEqual(source.count(ADDED_LAYOUT),1)
        reverted=source.replace(ADDED_COMMENT,ORIGINAL_COMMENT,1).replace(ADDED_LAYOUT,ORIGINAL_LAYOUT,1)
        self.assertEqual(hashlib.sha256(reverted.encode()).hexdigest(),
                         self.m['files'][JS]['live_source_sha256'])
    def test_native_rate_and_ui_behavior_preserved(self):
        s=(ROOT/JS).read_text()
        self.assertIn("applyDateIntent('rates',stagedRateIntent||state);",s)
        self.assertIn("if(stagedRateIntent&&stagedRateIntent.from&&!validIntent(stagedRateIntent))return;",s)
        self.assertIn("toolbar.append(selects[0],selects[1]);",s)
        self.assertIn("toolbar.classList.add('mad4b-rate-filter-row--date-only');",s)
        self.assertIn("if(group&&!group.querySelector('.mad4b-rate-filter-row')){",s)
        self.assertIn("payload.set('query[mad4b_date_clear]','1')",s)
        self.assertIn("syncLegacy('apply')",s)
        self.assertIn("if(context==='rates'&&!changedDuringRequest)stagedRateIntent=null;",s)
        for term in ('wc_create_order(', 'wp_mail(', 'payment_intent', 'stripe.'):
            self.assertNotIn(term,s.lower())
    def test_current_live_php_still_contains_all_failclosed_lanes(self):
        s=(ROOT/PHP).read_text()
        self.assertIn('unified-date-v0.4.3.js',s)
        self.assertIn('unified-date-v0.3.18.css',s)
        self.assertEqual(s.count('=mad4b_unified_date_intersect_post_ids_v1('),3)
        self.assertIn('|| (int)$value<=0) return [0];',s)
        self.assertTrue(self.m['source_php_has_date_postin_fix'])
if __name__=='__main__':
    unittest.main()
