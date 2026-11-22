"""No host mutation: unit tests of the original release lane's LIVE preflight."""
import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import time
import unittest

SCRIPT=Path(__file__).resolve().parents[1]/'live_source_staging_preview.py'
spec=importlib.util.spec_from_file_location('live_source_staging_preview',SCRIPT)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class LiveStagingPreviewTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.source=self.root/'source';self.source.mkdir()
        self.package=self.root/'source.tar.gz';self.package.write_bytes(b'pinned-v150-test-package')
        self.original_sha=m.EXPECTED_PACKAGE_SHA
        m.EXPECTED_PACKAGE_SHA=m.sha(self.package)
        self.addCleanup(setattr,m,'EXPECTED_PACKAGE_SHA',self.original_sha)
        self.files={}
        for i in range(778):
            name=f'includes/example-{i:03d}.php'
            p=self.source/name;p.parent.mkdir(parents=True,exist_ok=True)
            data=(f'<?php // legitimate-source-{i}\n').encode();p.write_bytes(data)
            self.files[name]=hashlib.sha256(data).hexdigest()
        self.manifest={'contract':'mad4b.toursdev-live.company-control-plane-source-epoch-v2','source_of_truth':'https://tours.dev.mad4b.com','source_code_location':'LIVE_SITE_FILES_NOT_GITHUB','previous_overlay_reuse_allowed':False,'overlay_sha256':m.EXPECTED_PACKAGE_SHA,'executable_overlay_count':778,'overlay_source_file_hashes':self.files}
        self.host={'contract':'mad4b.host-v146.external-host-exact-site-ssh-readback.v1','write_authorized':False,'verified_site_count':5,'observed_epoch':int(time.time()),'sites':[]}
        self.backup={'contract':'mad4b.host-v149.staging-plugin-preimage-offline-restore.v1','target_count':2,'external_staging_plugins_modified':False,'targets':[]}
        self.policy={'contract':'mad4b.company-code-release-policy.v1','live_source_staging_preview_targets':{}}
        for scope,(uuid,hid,origin) in m.EXACT_STAGING.items():
            company,env=scope.split(':')
            self.host['sites'].append({'company':company,'environment':env,'site_uuid':uuid,'host_id':hid,'canonical_origin':origin,'identity_verified':True,'pinned_ssh_verified':True,'host_company_binding_readonly_verified':True})
            self.policy['live_source_staging_preview_targets'][scope]={'site_uuid':uuid,'host_id':hid,'origin':origin,'preview_only':True}
            path=self.root/(company+'.tar.gz')
            with tarfile.open(path,'w:gz') as tar:
                for i,(name,shav) in enumerate(self.files.items()):
                    if i>=755:continue
                    data=(self.source/name).read_bytes() if i<656 else b'<?php // old\n'
                    info=tarfile.TarInfo('mad4b-site-control-plane/'+name);info.size=len(data)
                    tar.addfile(info,io.BytesIO(data))
                extra=b'<?php // tenant-owned\n'
                count=193 if company=='all-royal-egypt' else 195
                for i in range(count):
                    info=tarfile.TarInfo('mad4b-site-control-plane/local/tenant-%03d.php'%i);info.size=len(extra);tar.addfile(info,io.BytesIO(extra))
            self.backup['targets'].append({'company':company,'environment':env,'site_uuid':uuid,'host_id':hid,'offline_plugin_restore_verified':True,'remote_database_backup_verified':False,'archive':str(path),'archive_sha256':m.sha(path)})

    def make(self):
        return m.build_preview(self.policy,self.manifest,self.host,self.backup,source_root=self.source,package=self.package)

    def test_exact_122_file_candidates_per_staging(self):
        result=self.make()
        self.assertEqual(2,len(result['sites']))
        self.assertTrue(result['external_staging_only'])
        self.assertFalse(result['signed_dispatch_authorized'])
        self.assertFalse(result['release_executed'])
        for site in result['sites']:
            self.assertEqual(656,site['equal_count'])
            self.assertEqual(99,site['changed_count'])
            self.assertEqual(23,site['missing_count'])
            self.assertEqual(122,len(site['file_changes']))
            self.assertTrue(all(f['target'].startswith('wp-content/plugins/mad4b-site-control-plane/') for f in site['file_changes']))
        self.assertEqual(193,result['sites'][0]['target_only_files_preserved_count'])
        self.assertEqual(195,result['sites'][1]['target_only_files_preserved_count'])

    def test_source_tamper_denied(self):
        (self.source/'includes/example-000.php').write_bytes(b'bad')
        with self.assertRaisesRegex(m.PreviewDenied,'LIVE_SOURCE_FILE_DRIFT'):self.make()

    def test_archive_tamper_denied(self):
        Path(self.backup['targets'][0]['archive']).write_bytes(b'tampered')
        with self.assertRaisesRegex(m.PreviewDenied,'PREIMAGE_ARCHIVE_DRIFT'):self.make()

    def test_scope_escalation_denied(self):
        self.policy['live_source_staging_preview_targets']['all-royal-egypt:production']={}
        with self.assertRaisesRegex(m.PreviewDenied,'PREVIEW_SCOPES_NOT_ENROLLED'):self.make()

    def test_identity_mismatch_denied(self):
        self.host['sites'][0]['site_uuid']='00000000-0000-0000-0000-000000000000'
        with self.assertRaisesRegex(m.PreviewDenied,'REMOTE_SITE_PROOF_INVALID'):self.make()

    def test_stale_ssh_evidence_denied(self):
        self.host['observed_epoch']-=8000
        with self.assertRaisesRegex(m.PreviewDenied,'STAGING_IDENTITY_NOT_FRESH'):self.make()

    def test_unauthorized_preview_binding_denied(self):
        self.policy['live_source_staging_preview_targets']['all-royal-egypt:staging']['preview_only']=False
        with self.assertRaisesRegex(m.PreviewDenied,'UNTRUSTED_PREVIEW_TARGET'):self.make()

    def test_source_parent_traversal_denied(self):
        first=next(iter(self.files))
        self.manifest['overlay_source_file_hashes']['../escape.php']=self.manifest['overlay_source_file_hashes'].pop(first)
        with self.assertRaisesRegex(m.PreviewDenied,'BAD_SOURCE_PATH'):self.make()

if __name__=='__main__':unittest.main()
