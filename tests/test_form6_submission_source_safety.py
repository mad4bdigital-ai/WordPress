"""Form #6 client-side text is never proof of payments or booking readiness."""
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
SRC=ROOT/'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-tour-form6-submission-mode-v1.js'
class Form6Readiness(unittest.TestCase):
 def test_default_is_quote_only_and_advance_requires_server_proof(self):
  text=SRC.read_text()
  self.assertIn("let serverMode = 'quote_only'",text)
  self.assertIn("data.booking_confirmed!==false",text)
  self.assertIn("serverMode='quote_only'",text)
  self.assertIn("response.ok",text)
  self.assertIn("credentials:'same-origin',method:'GET'",text)
  self.assertNotIn("fetch(u,{method:'POST'",text)
  self.assertNotIn('stripe.confirm',text)
 def test_advance_requires_original_rate_and_adults_only(self):
  s=SRC.read_text()
  for term in ("intent.tour_id","intent.rate_id","intent.occupancy",
               "intent.travel_date","intent.adults",
               "Number(intent.children)===0","Number(intent.infants)===0",
               "Number(form.querySelector('[name=\"no_of_children\"]')?.value || 0)===0",
               "Number(form.querySelector('[name=\"no_of_infants\"]')?.value || 0)===0"):
   self.assertIn(term,s)
 def test_ui_only_never_marks_booking_confirmed(self):
  s=SRC.read_text()
  self.assertIn("You won’t be charged when you submit this request.",s)
  self.assertIn("This is not a confirmed booking.",s)
  self.assertIn("if (priceNote) priceNote.remove()",s)
  self.assertIn("Payment does not confirm the trip.",s)
  self.assertIn("form.dataset.mad4bSubmissionMode = mode",s)
  self.assertNotIn('window.location.href=',s)
  self.assertNotIn('localStorage.setItem',s)
if __name__=='__main__':unittest.main()
