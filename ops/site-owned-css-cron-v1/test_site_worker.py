#!/usr/bin/env python3
"""Fail-closed tests for a site-owned CSS Cron executor. No live job dispatch."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest

HERE=Path(__file__).resolve().parent

def load_worker(profile):
    with tempfile.TemporaryDirectory(prefix="site-css-qa-") as directory:
        path=Path(directory)
        worker=path/"worker.py"
        worker.write_bytes((HERE/"worker.py").read_bytes())
        opt=path/"site-profile.json"
        opt.write_text(json.dumps(profile))
        opt.chmod(0o600)
        spec=importlib.util.spec_from_file_location("mad4b_css_site_unit",worker)
        mod=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        return mod

def sample(site="source",tasks=None):
    return {"contract":"mad4b.css.site-owned-cron.profile.v1",
            "site_id":site,
            "site_host":"tours.dev.mad4b.com" if site=="source" else "wayfatours.com",
            "php_container":"php-wkgtkzta35wkkgouwt6ruljc" if site=="source" else "php-guxhp9y6vgh7oouuw8plxrlr",
            "environment":"staging" if site=="source" else "production",
            "tasks":tasks if tasks is not None else ["public","first_fold"]}

class SiteWorker(unittest.TestCase):
    def test_only_one_runtime_site_is_bound(self):
        m=load_worker(sample())
        self.assertEqual(m.SITE,"source")
        self.assertEqual(list(m.CONTAINERS),["source"])
        self.assertNotIn("wayfa",m.CONTAINERS)

    def test_other_site_receipts_not_claimed(self):
        m=load_worker(sample("wayfa"))
        self.assertEqual(m.SITE,"wayfa")
        self.assertEqual(list(m.CONTAINERS),["wayfa"])

    def test_source_ticket_selection_and_task_allowlist(self):
        m=load_worker(sample(tasks=["public"]))
        pending={"public":{"id":7,"requested_at":1},"first_fold":{"id":8,"requested_at":1}}
        selected=m.available_candidates("source",{"pending":pending},{},100000)
        self.assertEqual(len(selected),1)
        self.assertEqual(selected[0][3:5],("public",7))

    def test_unknown_task_cannot_be_enrolled(self):
        bad=sample(tasks=["php_eval"])
        with self.assertRaises(ValueError):
            load_worker(bad)

    def test_duplicate_tasks_rejected(self):
        with self.assertRaises(ValueError):
            load_worker(sample(tasks=["public","public"]))

    def test_unregistered_site_rejected_until_original_renderer_enrolled(self):
        bad=sample()
        bad["site_id"]="third_party"
        with self.assertRaises(ValueError):
            load_worker(bad)

    def test_environment_mismatch_rejected(self):
        bad=sample()
        bad["environment"]="production"
        with self.assertRaises(ValueError):
            load_worker(bad)

    def test_ticket_completion_requires_first_party_receipt(self):
        m=load_worker(sample())
        success,reason=m.verified_task_outcome("source","public",0,
            "DEFER_PUBLIC_CCSS_RESOURCE_WINDOW source cpu_busy\n","")
        self.assertFalse(success)
        self.assertEqual(reason,"host_resource_deferred")
        success,reason=m.verified_task_outcome("source","public",0,
            "CERTIFIED_PUBLIC_CCSS source manifest_verified\n","")
        self.assertTrue(success)
        success,reason=m.verified_task_outcome("source","public",0,
            "SKIP_PUBLIC_CCSS_UNKNOWN_SUCCESS source\n","")
        self.assertFalse(success)

    def test_retry_policy_keeps_resource_holds_nonfatal(self):
        m=load_worker(sample())
        self.assertEqual(m.retry_policy(5,"host_resource_deferred",1000),(0,1300))

    def test_original_integrity_runner_is_kept(self):
        m=load_worker(sample("wayfa"))
        cmd=m.TASKS["first_fold"]("wayfa")
        self.assertEqual(cmd[0],"/usr/bin/bash")
        self.assertTrue(cmd[-1].endswith("/run-site.sh") or cmd[-1]=="wayfa")
        self.assertEqual(cmd[-1],"wayfa")

    def test_source_browser_and_qa_uses_original_runner(self):
        m=load_worker(sample())
        cmd=m.TASKS["public"]("source")
        self.assertTrue(cmd[1].endswith("run-public-route.py"))
        self.assertEqual(cmd[-1],"source")

if __name__=="__main__":
    unittest.main(verbosity=2)
