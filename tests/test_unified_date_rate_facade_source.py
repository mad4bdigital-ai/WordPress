"""Date facade on Rates is a single field over the native JetSmartFilters provider."""
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
JS=(ROOT/'wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.3.5.js').read_text()
CSS=(ROOT/'wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.3.5.css').read_text()
MU=(ROOT/'wp-content/mu-plugins/mad4b-tour-form6-submission-mode-v1.php').read_text()
class Facade(unittest.TestCase):
 def test_preserve_single_native_provider(self):
  self.assertIn('.jet-date-range[data-filter-id="46259"][data-query-id="tour_rates_accordion"]',JS)
  self.assertIn('rateDateInitialized=new WeakSet()',JS)
  self.assertIn('rateDateInitialized.add(widget)',JS)
  self.assertIn("root.dataset.mad4bDateContext='rates'",JS)
  self.assertIn("picker(root,'rates')",JS)
  self.assertIn('mad4b-ud-rate-filter-ready',JS)
  self.assertIn("toolbar.append(widget,selects[0],selects[1],apply)",JS)
  self.assertIn("document.querySelectorAll('.mad4b-ud-rate-context').forEach(el=>el.remove())",JS)
 def test_immutable_v027_rate_geometry(self):
  self.assertIn("function setPortalGeometry()",JS)
  self.assertIn("window.matchMedia('(max-width:767px)').matches",JS)
  self.assertIn("setPortalGeometry();",JS)
  self.assertIn("maxHeight:'90dvh'",JS)
  self.assertIn("transform:'translate(-50%,-50%)'",JS)
  self.assertIn("width:min(290px,100%)!important",CSS)
  self.assertIn("max-width:100%!important",CSS)
 def test_native_ajax_toolbar_currency_and_wpml_are_source_owned(self):
  for feature in ('nativeAjaxPrefilter()', "group.applyFiltersHandler('ajax')",
   'mad4b-rate-filter-row','mad4b-ud-rate-apply',
   'rewriteContextLinks()', 'rememberCurrencyHandoff()', 'restoreCurrencyHandoff()'):
   self.assertIn(feature,JS)
  self.assertIn('query[mad4b_date_clear]',JS)
  self.assertIn('jet-engine/tour_rates_accordion',JS)
  self.assertIn('jet-engine/tours_query_archive',JS)
  self.assertIn("history.replaceState(history.state,'',next.toString())",JS)
  self.assertIn('a.wpml-ls-link, a.wpml-ls-item-toggle',JS)
  self.assertIn('mad4b-rate-filter-row',CSS)
  for feature in ('syncRateActiveFilters','mad4b-ud-active-date',
                  'mad4b-ud-active-remove','syncQueryCount','ajaxSuccess.mad4bDateCount',
                  "Number.isSafeInteger(Number(pagination.found_posts))"):
   self.assertIn(feature,JS)
  self.assertIn('mad4b-ud-active-remove',CSS)

  self.assertIn("payload.set('query[mad4b_currency]',currency)",JS)
  self.assertIn('/^(USD|EUR|EGP|GBP)$/.test',JS)

 def test_url_apply_clear_and_server_source(self):
  self.assertIn("applyDateIntent('archive',draft)",JS)
  self.assertIn("applyDateIntent('rates',stagedRateIntent||state)",JS)
  self.assertIn("_meta_query_advanced_date__any__tour_rate_start_date__tour_rate_end_date|date",JS)
  self.assertIn("syncLegacy('apply')",JS)
  self.assertIn("syncLegacy('clear')",JS)
  self.assertNotIn("fetch('/wp-admin/admin-ajax.php',{method:'POST'",JS)
 def test_native_date_widget_hidden_only_after_unified_attaches(self):
  self.assertIn(".mad4b-ud-rate-filter-ready .jet-smart-filters-date-range",CSS)
  self.assertIn("display:none!important",CSS)
  self.assertIn("body.single-tours-and-activities",CSS)
  self.assertIn("data-filter-id=\"46259\"",JS)
  self.assertIn("Rate periods only",JS)
  self.assertIn("المغادرة والتوافر غير مؤكدين",JS)
 def test_all_six_language_quote_notes_keep_unconfirmed_status(self):
  for lang in ("en","ar","es","fr","de","it"):
   self.assertIn("'"+lang+"'=>[",MU)
  self.assertIn("لن يُخصم منك أي مبلغ",MU)
  self.assertIn("You won’t be charged when you submit this request",MU)
  self.assertIn("No payment was taken",MU)
  self.assertIn("لا يؤكد الحجز",MU)
  self.assertIn("booking_confirmed'=>false",MU)
  self.assertIn("'methods'=>'GET'",MU)
 def test_no_duplicate_quote_warning(self):
  js=(ROOT/'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-tour-form6-submission-mode-v1.js').read_text()
  self.assertIn("if (priceNote) priceNote.remove()",js)
  self.assertIn("form.dataset.mad4bSubmissionMode = mode",js)
  self.assertIn("serverMode = 'quote_only'",js)
if __name__=='__main__':unittest.main()
