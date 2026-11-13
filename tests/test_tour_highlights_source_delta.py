"""Non-authorizing PR813 source integrity and historical PR808 preservation."""
from pathlib import Path
import hashlib
import json
import unittest
import xml.etree.ElementTree as ET
from tour_highlights_bootstrap_overlay import (
    MAIN,BASE_SHA256,CANDIDATE_SHA256,ANCHOR,INSERT,
    SourceOverlayDenied,original_bootstrap_bytes,current_source_verified,
)

ROOT=Path(__file__).resolve().parents[1]
REVIEW=ROOT/'docs/release-coverage/pr814-pr813-tour-highlights-reconciled-20261113.json'
PLUGIN=ROOT/'wp-content/plugins/mad4b-site-control-plane'

class TourHighlightsReviewedSource(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.review=json.loads(REVIEW.read_text())

    def reviewed_historical_main(self, actual):
        # PR813 added three small *independent* early privacy/reconciliation
        # hooks after the PR808 Tour Highlights overlay. Remove only these
        # exact SHA-bound additions before checking the historical certificate.
        meta=json.loads((ROOT/'docs/release-coverage/pr814-pr813-destination-preview-additive-20261114.json').read_text())['original_plugin_entry']
        self.assertEqual(meta['path'],MAIN)
        self.assertEqual((len(actual),hashlib.sha256(actual).hexdigest()),
                         (meta['current_bytes'],meta['current_sha256']))
        lines=actual.splitlines(True)
        for add in reversed(meta['additions']):
            pos=add['start_line']
            section=b''.join(lines[pos:pos+add['line_count']])
            self.assertEqual(hashlib.sha256(section).hexdigest(),add['sha256'])
            del lines[pos:pos+add['line_count']]
        historic=b''.join(lines)
        self.assertEqual((len(historic),hashlib.sha256(historic).hexdigest()),
                         (meta['historical_bytes'],meta['historical_sha256']))
        return historic

    def test_exact_old_bootstrap_reconstructed_with_only_three_new_lines(self):
        candidate=self.reviewed_historical_main((ROOT/MAIN).read_bytes())
        original=original_bootstrap_bytes(candidate)
        self.assertEqual(hashlib.sha256(original).hexdigest(),BASE_SHA256)
        self.assertEqual(hashlib.sha256(candidate).hexdigest(),CANDIDATE_SHA256)
        self.assertEqual(self.review['reviewed_bootstrap_sha256'],CANDIDATE_SHA256)
        self.assertEqual(candidate.count(ANCHOR+INSERT),1)
        self.assertEqual(len(INSERT.splitlines()),3)
        for altered in (candidate+b'\n',
                        candidate.replace(b'JetEngine AJAX',b'Unverified AJAX'),
                        original,
                        candidate.replace(INSERT,b'')):
            with self.assertRaises(SourceOverlayDenied):
                original_bootstrap_bytes(altered)

    def test_all_reviewed_source_files_have_exact_current_sha(self):
        self.assertEqual(self.review['contract'],'mad4b.pr814.tour-highlights-native-source-reconcile.v1')
        self.assertTrue(self.review['source_only'])
        for flag in ('installed_verified','migration_performed',
                     'editorial_source_facts_approved_by_default',
                     'original313_signed_receipts_issued','production_release_approved'):
            self.assertFalse(self.review[flag])
        rows=self.review['reviewed_files']
        self.assertEqual(len(rows),15)
        self.assertEqual(len({r['path'] for r in rows}),15)
        self.assertEqual(len(self.review['reviewed_files_prior_to_split_v3']),12)
        self.assertFalse(self.review['split_v3_browser_acceptance_run_during_this_merge'])
        self.assertFalse(self.review['split_v3_live_installation_verified_during_this_merge'])
        self.assertTrue(self.review['split_v3_source_only'])
        for row in rows:
            with self.subTest(file=row['path']):
                path=ROOT/row['path']
                self.assertTrue(path.is_file() and not path.is_symlink())
                actual=path.read_bytes()
                if row['path']==MAIN:
                    historical=self.reviewed_historical_main(actual)
                    self.assertEqual((len(historical),hashlib.sha256(historical).hexdigest()),
                                     (row['bytes'],row['sha256']))
                elif row['path'].endswith('/class-mad4b-scp-tour-highlights-v1.php'):
                    self.verify_hook_readiness_successor(row,actual)
                elif row['path'].endswith('/class-mad4b-scp-tour-highlights-presentation-v1.php'):
                    self.verify_destination_preview_addition(row,actual)
                else:
                    self.assertEqual(len(actual),row['bytes'])
                    self.assertEqual(hashlib.sha256(actual).hexdigest(),row['sha256'])

    def verify_destination_preview_addition(self,row,actual):
        review=json.loads((ROOT/'docs/release-coverage/pr814-pr813-destination-preview-additive-20261114.json').read_text())
        item=review['presentation']
        self.assertEqual(item['path'],row['path'])
        self.assertEqual((item['historical_bytes'],item['historical_sha256']),
                         (row['bytes'],row['sha256']))
        self.assertEqual((len(actual),hashlib.sha256(actual).hexdigest()),
                         (item['current_bytes'],item['current_sha256']))
        lines=actual.splitlines(True)
        start=item['insertion_line']
        insertion=b''.join(lines[start:start+item['insertion_line_count']])
        self.assertEqual(hashlib.sha256(insertion).hexdigest(),item['insertion_sha256'])
        original=b''.join(lines[:start]+lines[start+item['insertion_line_count']:])
        self.assertEqual((len(original),hashlib.sha256(original).hexdigest()),
                         (row['bytes'],row['sha256']))
        self.assertFalse(review['production_release_approved'])

    def verify_hook_readiness_successor(self,row,actual):
        review=json.loads((ROOT/'docs/release-coverage/pr814-tour-highlights-hook-readiness-successor-20261114.json').read_text())
        self.assertEqual(review['contract'],'mad4b.pr814.tour-highlights-append-only-review.v1')
        self.assertEqual(review['historical_review'],'docs/release-coverage/pr814-pr813-tour-highlights-reconciled-20261113.json')
        self.assertEqual(review['path'],row['path'])
        self.assertEqual((review['historical_bytes'],review['historical_sha256']),
                         (row['bytes'],row['sha256']))
        self.assertEqual((len(actual),hashlib.sha256(actual).hexdigest()),
                         (review['current_bytes'],review['current_sha256']))
        self.assertTrue(review['source_only'])
        self.assertFalse(review['production_release_approved'])
        self.assertFalse(review['original313_signed_receipts_issued'])
        guard=(b'        // WordPress always supplies both hook APIs in a real site request.\n'
               b'        // During the native Plugins screen zero-touch bootstrap and hermetic\n'
               b'        // inspection they may deliberately be absent. Loading the lightweight\n'
               b'        // Tour class must not force WP/Elementor runtime initialization.\n'
               b"        if (!function_exists('add_filter') || !function_exists('add_action')) return;\n")
        self.assertEqual(actual.count(guard),1)
        historic=actual.replace(guard,b'',1)
        self.assertEqual((len(historic),hashlib.sha256(historic).hexdigest()),
                         (row['bytes'],row['sha256']))
        self.assertEqual(set(review['regression_tests']),{
            'wp-content/plugins/mad4b-site-control-plane/tests/native-plugin-management-menu-runtime.php',
            'tests/native-tour-highlights/acceptance.php',
        })

    def test_native_jetengine_approvals_do_not_mint_published_facts(self):
        source=(PLUGIN/'includes/class-mad4b-scp-tour-highlights-v1.php').read_text()
        presentation=(PLUGIN/'includes/class-mad4b-scp-tour-highlights-presentation-v1.php').read_text()
        for token in ('tour_highlights_mode','tour_highlights_review_status',
                      'tour_highlights_items','tour_highlight_status',
                      'tour_highlights_content','needs_review',
                      'MAD4B_Company_Term_Eligibility_V1::decide('):
            self.assertIn(token,source)
        self.assertIn('jet-engine/listings/allowed-callbacks',source)
        self.assertIn('elementor/frontend/container/should_render',source)
        self.assertIn('tour-journey--places-only',source)
        self.assertIn('wp_add_inline_style',presentation)
        self.assertIn('tour-journey-ui.js',presentation)
        self.assertNotIn('str_contains(',source,'PHP 7.4 declared compatibility must be preserved')
        self.assertNotIn('wp_remote_post(',source)
        self.assertNotIn('wp_insert_post(',source)

    def test_latest_role_migration_is_single_cas_engine_and_qa_is_dynamic(self):
        # The updated PR813 source contained two byte-identical migration
        # entrypoints. Keep only one, with exact Site/Action and CAS gates.
        canonical=ROOT/'scripts/migrations/tour-journey-role-migration.php'
        legacy=ROOT/'scripts/migrations/tour-journey-discovery-roles.php'
        self.assertTrue(canonical.is_file())
        self.assertFalse(legacy.exists(),'Duplicate Elementor role migration returned')
        code=canonical.read_text()
        for required in ("TOUR_JOURNEY_SITE", "TOUR_JOURNEY_ACTION",
                         "'preflight','apply','verify'", "meta_value=%s",
                         "ELEMENTOR_CAS_FAILED", "ELEMENTOR_READBACK_FAILED"):
            self.assertIn(required,code)
        qa=(ROOT/'scripts/qa/tour-journey-acceptance.php').read_text()
        self.assertIn('TOUR_JOURNEY_EXPECT_GROUPS',qa)
        self.assertIn('ctype_digit',qa)
        self.assertIn("get_option('home')",qa)
        self.assertIn('listing_groups',qa)
        self.assertIn('($expectedGroups!==null && $groups!==$expectedGroups)',qa)
        self.assertNotIn('str_contains(',qa,'QA must run with declared PHP 7.4 minimum')
        self.assertNotIn('$groups!==5',qa,'Group count must come from site QA, not a hardcoded fleet rule')

    def test_wpml_grid_localization_is_source_scoped_and_does_not_write(self):
        code=(PLUGIN/'includes/class-mad4b-scp-tour-highlights-v1.php').read_text()
        for required in ('enter_native_group','leave_native_group',
                         'localize_native_grid',
                         'elementor/frontend/widget/before_render',
                         'wpml_current_language',"'wpml_object_id'",
                         'tour-place-group', 'get_post_status($localized)',
                         'get_post_type($localized)'):
            self.assertIn(required,code)
        self.assertNotIn('str_contains(',code)
        self.assertIn("$widget->set_settings('lisitng_id'",code)
        # The localization path modifies only in-memory Elementor settings.
        section=code.split('public static function localize_native_grid(',1)[1].split(
            'private static function source_valid(',1)[0]
        for mutation in ('update_post_meta(', 'wp_update_post(', 'wp_insert_post(',
                         '$wpdb->query(', 'delete_post_meta('):
            self.assertNotIn(mutation,section)

    def test_site_specific_wpml_migrations_are_not_runtime_providers(self):
        script=ROOT/'scripts/migrations/tour-journey-wpml-fr-de-20261113.php'
        content=script.read_text()
        self.assertIn("$site!=='https://tours.dev.mad4b.com'",content)
        self.assertIn("'TOUR_DEST_ACTION'",content)
        self.assertIn("'preflight','apply','verify'",content)
        self.assertIn('DOCUMENT_CAS_CONFLICT',content)
        self.assertIn('LOCAL_LISTING_READBACK_FAILED',content)
        self.assertNotIn('str_contains(',content)
        second=(ROOT/'scripts/migrations/tour-journey-wpml-independent-20261113.php').read_text()
        self.assertIn("get_option('home')!=='https://tours.dev.mad4b.com'",second)
        self.assertIn("'TOUR_DEST_ACTION'",second)
        self.assertIn("'preflight','apply','verify'",second)
        self.assertIn('update_post_meta(',second)
        self.assertFalse(self.review['latest_wpml_migrations_executed'])
        self.assertFalse(self.review['latest_playwright_visual_acceptance_performed'])
        browser=(ROOT/'scripts/qa/tour-journey-browser.py').read_text()
        self.assertIn('https://',browser)
        self.assertIn('playwright.sync_api',browser)

    def test_split_v3_uses_existing_native_jetengine_card_hierarchy(self):
        split_js=(PLUGIN/'assets/tour-journey-split-v3.js').read_text()
        split_css=(PLUGIN/'assets/tour-journey-split-v3.css').read_text()
        presentation=(PLUGIN/'includes/class-mad4b-scp-tour-highlights-presentation-v1.php').read_text()
        engine=(PLUGIN/'includes/class-mad4b-scp-tour-highlights-v1.php').read_text()
        browser=(ROOT/'scripts/qa/tour-journey-split-browser.py').read_text()
        for token in ("'country'", "'state_governorate'", "'place_attraction'",
                      'section.dataset.tourDestinations', 'new Map(terms.map(',
                      "widget.querySelectorAll('.tour-place-group')",
                      "group.querySelectorAll('.jet-listing-grid__item')",
                      'const byId=new Map(',
                      "document.documentElement.lang", "section.dataset.jdSplitReady"):
            self.assertIn(token,split_js)
        for lang in ('en:', 'ar:', 'fr:', 'de:', 'es:', 'it:'):
            self.assertIn(lang,split_js)
        self.assertIn('tour-journey-split-v3.css',presentation)
        self.assertIn('tour-journey-split-v3.js',presentation)
        self.assertIn('wp_add_inline_script',presentation)
        self.assertIn('data-tour-destinations',engine)
        self.assertIn("'parent'=>(int)$term->parent",engine)
        self.assertIn("'kind'=>(string)$place['kind']",engine)
        self.assertIn('.jd-split-v3__panel',split_css)
        self.assertIn('pageOverflow',browser)
        self.assertIn('nativeTemplateCount',browser)
        # The Split display may rearrange existing JetEngine cards, but it
        # must not invent or render a second destination Listing or back-end.
        self.assertNotIn('cloneNode(',split_js)
        self.assertNotIn('wp_remote_post(',engine)
        self.assertNotIn('wp_insert_post(',engine)
        self.assertTrue(self.review['split_v3_existing_native_jetengine_cards_only'])
        self.assertTrue(self.review['split_v3_unverified_related_placements_are_not_asserted_visits'])

    def test_wpml_translation_is_independent_per_locale(self):
        tree=ET.parse(PLUGIN/'wpml-config.xml')
        fields={el.text:el.attrib['action'] for el in tree.findall('.//custom-field')}
        self.assertEqual(fields,{
            'tour_highlights_content':'translate',
            'tour_highlights_intro':'translate',
            'tour_highlights_items':'copy-once',
            'tour_highlights_mode':'copy-once',
            'tour_highlights_display_pattern':'copy-once',
        })
        self.assertNotIn('tour_highlights_review_status',fields)
        self.assertTrue(all(k.startswith('tour_') for k in fields))

if __name__=='__main__':unittest.main()
