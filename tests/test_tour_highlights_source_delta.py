"""Non-authorizing PR813 source integrity and historical PR808 preservation."""
from pathlib import Path
import hashlib
import json
import unittest
import subprocess
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
        cls.successors=json.loads((ROOT/'docs/release-coverage/pr814-journey-source-successors-20261115.json').read_text())

    def git_source(self, source_commit, path):
        result=subprocess.run(['git','show',source_commit+':'+path],
                              cwd=ROOT,capture_output=True,check=True)
        return result.stdout

    def current_successor_proof(self, path, actual):
        review=self.successors
        self.assertEqual(review['contract'],'mad4b.journey-source-successors-exact-ci-review.v1')
        self.assertEqual(review['historical_source_review'],REVIEW.relative_to(ROOT).as_posix())
        for key,value in (('authorizing',False),('production_authorized',False),
                          ('original313_signed_receipts_issued',False),
                          ('live_evidence_required',True)):
            self.assertIs(review[key],value)
        matches=[x for x in review['exact_current_files'] if x['path']==path]
        self.assertEqual(len(matches),1,path)
        row=matches[0]
        self.assertEqual(row['review_status'],'reviewed_for_ci_only')
        blob=hashlib.sha1(b'blob '+str(len(actual)).encode('ascii')+b'\0'+actual).hexdigest()
        observed_sha=hashlib.sha256(actual).hexdigest()
        expected=(row['bytes'],row['sha256'],row['git_blob_sha'])
        if blob!=row['git_blob_sha']:
            # The frozen PR813/814 successor manifest remains immutable.
            # Only the exact newer Unicode-whitespace presenter receives an
            # additive second certificate, with independent source/QA proof.
            self.assertTrue(path.endswith(
                '/class-mad4b-scp-tour-highlights-presentation-v1.php'))
            proof=json.loads((ROOT/'docs/release-coverage/'
                'pr814-tour-highlights-visible-text-successor-20261115.json').read_text())
            historical=(ROOT/'docs/release-coverage/'
                'pr814-journey-source-successors-20261115.json').read_bytes()
            self.assertEqual(proof['contract'],
                'mad4b.journey-visible-text-successor-exact-source-review.v1')
            self.assertEqual(proof['source_path'],path)
            self.assertEqual(proof['historical_source_blob_sha'],row['git_blob_sha'])
            self.assertEqual(proof['historical_manifest_sha256'],
                hashlib.sha256(historical).hexdigest())
            self.assertEqual(proof['successor_source_commit'],
                '424b0c9c1377eb62dad8ccdb04e5ed85fd82dd59')
            self.assertEqual(actual,self.git_source(proof['successor_source_commit'],path))
            for key,value in (('authorizing',False),('production_authorized',False),
                              ('original313_signed_receipts_issued',False),
                              ('live_evidence_required',True),
                              ('no_editorial_source_mutation',True)):
                self.assertIs(proof[key],value)
            self.assertIn('scripts/qa/tour-highlights-visible-text-contract.php',
                proof['regression_tests'])
            self.assertEqual(
                [(x['site'],x['approved_tour_card_count'],x['blank_cards'])
                 for x in proof['live_readback']['sites']],
                [('tours.dev.mad4b.com',17,0),('wayfatours.com',17,0)])
            expected=(proof['current_bytes'],proof['current_sha256'],
                      proof['current_blob_sha'])
        self.assertEqual((len(actual),observed_sha,blob),expected)
        self.assertIn('tests/test_journey_successor_no_regression.py',row['regression_tests'])
        # New executable PHP source must additionally have the independent
        # exact-blob review, not only the non-authorizing manifest.
        if path.endswith('.php'):
            coverage=json.loads((ROOT/'governance/production-readiness/capability-coverage.json').read_text())
            matches=[x for x in coverage['reviewed_changes']
                     if x['path']==path and x['source_blob_sha']==blob]
            self.assertEqual(len(matches),1,path)
            self.assertEqual(matches[0]['status'],'reviewed_for_ci_only')
            self.assertIs(matches[0]['production_authorized'],False)
            self.assertTrue(matches[0]['live_evidence_required'])

    def reviewed_historical_main(self, actual):
        # Historical PR813 proof is immutable. Accept only the separately
        # reviewed, exact six-line Original305 bootstrap successor; prove its
        # removal reproduces the entire historical current source byte-for-byte.
        meta=json.loads((ROOT/'docs/release-coverage/pr814-pr813-destination-preview-additive-20261114.json').read_text())['original_plugin_entry']
        self.assertEqual(meta['path'],MAIN)
        baseline=(meta['current_bytes'],meta['current_sha256'])
        if (len(actual),hashlib.sha256(actual).hexdigest()) != baseline:
            review=json.loads((ROOT/'ops/pr814-live-preservation/'
                               'pr830-original305-bootstrap-additive-source-review.json').read_text())
            self.assertEqual(review['contract'],'mad4b.pr830-original305-additive-source-review.v1')
            self.assertEqual(review['status'],'reviewed_for_ci_only')
            self.assertEqual(review['source_path'],MAIN)
            for key in ('authorizing','production_authorized','owner_attestation_provided',
                        'host_release_authorized','original305_hold_closed'):
                self.assertIs(review[key],False)
            self.assertIs(review['live_evidence_required'],True)
            self.assertEqual((len(actual),hashlib.sha256(actual).hexdigest()),
                             (review['source_bytes'],review['source_sha256']))
            added=('\n'.join(review['addition_lines'])+'\n').encode()
            self.assertEqual((len(added),hashlib.sha256(added).hexdigest()),
                             (review['addition_bytes'],review['addition_sha256']))
            self.assertEqual(actual.count(added),1)
            self.assertEqual(review['baseline_sha256'],meta['current_sha256'])
            self.assertEqual(review['baseline_bytes'],meta['current_bytes'])
            self.assertIn(b'Original305_Incident_Review::boot();',added)
            self.assertIn(b'Native_Host_Source_Release_Review::boot();',added)
            anchor=b"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-approval-tickets.php';\n"
            self.assertEqual(actual.count(anchor+added),1)
            actual=actual.replace(added,b'')
        self.assertEqual((len(actual),hashlib.sha256(actual).hexdigest()),baseline)
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

    def test_all_reviewed_source_files_retain_historical_and_exact_successor_proofs(self):
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
        successor_paths={x['path'] for x in self.successors['exact_current_files']}
        self.assertEqual(len(successor_paths),8)
        historical_commit=self.successors['historical_base_commit']
        historical_split=self.successors['historical_split_commit']
        historical_successor=self.successors['historical_successor_commit']
        self.assertEqual(historical_commit,'fc0d6c49d43c6818f3da3e6de465d36e4a55050c')
        self.assertEqual(historical_split,self.review['pr813_split_v3_head'])
        self.assertEqual(historical_successor,'c2214ebb92bf9f007b9b2c82bff67c8dfdb5fa5f')
        for row in rows:
            path=row['path']
            with self.subTest(file=path):
                source=ROOT/path
                self.assertTrue(source.is_file() and not source.is_symlink())
                actual=source.read_bytes()
                if path==MAIN:
                    historical=self.reviewed_historical_main(actual)
                    self.assertEqual((len(historical),hashlib.sha256(historical).hexdigest()),
                                     (row['bytes'],row['sha256']))
                    continue
                if path not in successor_paths:
                    # Unchanged documents still match the original immutable
                    # source hash; no lookup against a later commit is needed.
                    self.assertEqual((len(actual),hashlib.sha256(actual).hexdigest()),
                                     (row['bytes'],row['sha256']))
                    continue
                # Preserve both frozen historical source manifests and their
                # source identities. Neither is repinned to the merged HEAD.
                if path.endswith('/class-mad4b-scp-tour-highlights-v1.php'):
                    old=self.git_source(historical_successor,path)
                    self.verify_hook_readiness_successor(row,old)
                elif path.endswith('/class-mad4b-scp-tour-highlights-presentation-v1.php'):
                    old=self.git_source(historical_successor,path)
                    self.verify_destination_preview_addition(row,old)
                else:
                    anchor=(historical_commit if path=='docs/tour-highlights-native-v1.md'
                            else historical_split)
                    old=self.git_source(anchor,path)
                    self.assertEqual((len(old),hashlib.sha256(old).hexdigest()),
                                     (row['bytes'],row['sha256']))
                if path in successor_paths:
                    self.current_successor_proof(path,actual)
                else:
                    self.assertEqual((len(actual),hashlib.sha256(actual).hexdigest()),
                                     (row['bytes'],row['sha256']))

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
        self.assertIn("wp_enqueue_script('tour-journey-split-v3',$assets.'tour-journey-split-v3.js'",presentation)
        self.assertIn("['tour-journey-ui'],(string)filemtime($splitJs),true",presentation)
        self.assertNotIn("wp_add_inline_script('tour-journey-ui'",presentation)
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
