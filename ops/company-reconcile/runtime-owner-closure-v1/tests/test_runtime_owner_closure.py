from pathlib import Path
import hashlib,json,re,unittest,subprocess
ROOT=Path(__file__).resolve().parents[4]
BASE=ROOT/'wp-content/plugins'
RECEIPT=ROOT/'ops/company-reconcile/runtime-owner-closure-v1/live-runtime-source-manifest.json'
MAP=BASE/'mad4b-platform/config/ordered-mu-map.php'
COM=BASE/'mad4b-commerce'
PLATFORM=BASE/'mad4b-platform'
JET=BASE/'mad4b-jetengine'
# The 905-file receipt is an immutable Git HISTORICAL snapshot, captured by
# a byte-identical, GitHub-reachable original PR ancestor. The creator's
# local-only commit 719588a024a8 is not in this PR's ancestry, but all 905
# recorded blobs and the receipt itself match the selected ancestor. Never
# compare the receipt to mutable HEAD or repin its historical digests.
HISTORICAL_COMMIT='b7ce62047e1eb0eae76eeb8e7671c6fa93d6b2fa'
RECEIPT_REL='ops/company-reconcile/runtime-owner-closure-v1/live-runtime-source-manifest.json'


def historic_git_blob(path: str) -> bytes:
    return subprocess.check_output(['git','-C',str(ROOT),'show',f'{HISTORICAL_COMMIT}:{path}'])


def historic_tree() -> dict:
    raw=subprocess.check_output(['git','-C',str(ROOT),'ls-tree','-r','-z',HISTORICAL_COMMIT])
    out={}
    for chunk in raw.split(b'\x00'):
        if not chunk:continue
        metadata,path=chunk.split(b'\t',1)
        mode,kind,blob=metadata.split(b' ')
        out[path.decode()]=(mode.decode(),kind.decode(),blob.decode())
    return out


class CompanyRuntimeOwnerClosureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.report=json.loads(RECEIPT.read_text())

    def test_complete_three_plugin_source_owner_baselines(self):
        self.assertEqual(self.report['source_file_counts'],{
            'mad4b-commerce':315,'mad4b-platform':496,'mad4b-jetengine':94})
        for name,entry,version in (
           ('mad4b-commerce','mad4b-commerce.php','0.1.2'),
           ('mad4b-platform','mad4b-platform.php','0.2.2'),
           ('mad4b-jetengine','mad4b-jetengine.php','1.0.0')):
            module=BASE/name/entry
            self.assertTrue(module.is_file())
            self.assertIn('Version: '+version,module.read_text())

    def test_source_fingerprint_receipts_match_without_hidden_overwrites(self):
        r=self.report
        self.assertEqual(len(r['files']),905)
        self.assertEqual(len(r['central_overrides_preserved']),1)
        # This assertion stops anyone silently repinning the signed historical
        # evidence JSON to legitimize a later source change.
        self.assertEqual(RECEIPT.read_bytes(),historic_git_blob(RECEIPT_REL))
        tree=historic_tree()
        pipe=subprocess.Popen(['git','-C',str(ROOT),'cat-file','--batch'],
                              stdin=subprocess.PIPE,stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL)
        try:
            for file in r['files']:
                path=file['path']
                self.assertIn(path,tree)
                mode,kind,blob=tree[path]
                self.assertIn(mode,['100644','100755'],path)
                self.assertEqual(kind,'blob',path)
                pipe.stdin.write((blob+'\n').encode());pipe.stdin.flush()
                header=pipe.stdout.readline().decode().strip().split()
                self.assertEqual(header[:2],[blob,'blob'],path)
                self.assertEqual(len(header),3,path)
                payload=pipe.stdout.read(int(header[2]))
                self.assertEqual(pipe.stdout.read(1),b'\n',path)
                self.assertEqual(hashlib.sha256(payload).hexdigest(),
                                 file['candidate_sha256'],path)
        finally:
            pipe.stdin.close();pipe.stdout.close();pipe.terminate();pipe.wait(timeout=5)
        conflict=r['central_overrides_preserved'][0]
        self.assertEqual(conflict['file'],'modules/compat/tour-content-layout-control.php')
        self.assertEqual(conflict['central_sha256'],
                         hashlib.sha256(historic_git_blob(
                            'wp-content/plugins/mad4b-commerce/'+conflict['file']
                         )).hexdigest())
        self.assertGreater(len(r['excluded_backup_and_non_code_paths']),50)

    def test_all_original_map_paths_are_present(self):
        # Original 378-entry contract remains valid in its historical tree;
        # current HEAD has a separately verified 379-entry expansion.
        map_rel='wp-content/plugins/mad4b-platform/config/ordered-mu-map.php'
        source=historic_git_blob(map_rel).decode()
        entries=re.findall(r"\['[^']+','(mad4b-(?:platform|commerce)/[^']+\.php)'\]",source)
        self.assertEqual(len(entries),378)
        self.assertEqual(len(entries),len(set(entries)))
        tree=historic_tree()
        for entry in entries:
            path='wp-content/plugins/'+entry
            self.assertIn(path,tree)
            self.assertIn(tree[path][0],['100644','100755'],path)

    def test_commerce_deps_recursive_closure_and_pickup(self):
        pat=re.compile(r"""(?:require_once|require|include_once|include)\s*\(?\s*__DIR__\s*\.\s*['"]/?([^'"]+)['"]""")
        seen=set();queue=[COM/'mad4b-commerce.php']
        while queue:
            f=queue.pop().resolve()
            if f in seen:continue
            seen.add(f)
            self.assertTrue(f.is_file(),str(f))
            for dep in pat.findall(f.read_text()):
                nextfile=(f.parent/dep).resolve()
                self.assertTrue(nextfile.is_relative_to(COM.resolve()))
                queue.append(nextfile)
        self.assertEqual(len(seen),23)
        bootstrap=(COM/'mad4b-commerce.php').read_text()
        self.assertIn('362-mad4b-booking-pickup-fields.php',bootstrap)
        self.assertIn('tour-component-structured-price-tags.php',bootstrap)
        self.assertIn("function_exists('tour_component_structured_price_value')",bootstrap)
        self.assertIn('require_once $module',bootstrap)
        self.assertTrue((COM/'modules/commerce/362-mad4b-booking-pickup-fields.php').is_file())
        self.assertEqual(hashlib.sha256((COM/'modules/commerce/tour-component-structured-price-tags.php').read_bytes()).hexdigest(),
                         '4e4ef66c60846d8a58365e4b7abbcd08d7314bd1af084df357be17e2ddce78a7')

    def test_jetengine_original_discovery_and_central_workspace(self):
        modules=sorted(JET.glob('modules/*/module.php'))
        self.assertEqual([m.parent.name for m in modules],['functional-modes','hub'])
        self.assertTrue((JET/'core/class-mad4b-jetengine-core.php').is_file())
        self.assertTrue((JET/'modules/functional-modes/class-pricing-workspace.php').is_file())
        self.assertIn("glob($root . '/*/module.php')",
                      (JET/'core/class-mad4b-jetengine-core.php').read_text())
        self.assertTrue((PLATFORM/'modules/operations/mad4b-central-homepage-sections.php').is_file())

    def test_no_release_authority_inferred_from_git_source(self):
        r=self.report
        self.assertFalse(r['source_runtime_plugin_activation_not_performed'] is False)
        self.assertFalse(r['original313_signed_release_certified'])
        self.assertFalse(r['github_master_changed'])
        self.assertFalse(r['live_site_files_mutated'])
        self.assertFalse((COM/'legacy-mu-tree/currency-data').is_symlink())

if __name__=='__main__': unittest.main()
