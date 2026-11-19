#!/usr/bin/env python3
"""Test Cron rendering without changing the real host schedule."""
import json
import os
import tempfile
from pathlib import Path
import unittest
from cron_entry import render

def profile(site="source"):
    return {"contract":"mad4b.css.site-owned-cron.profile.v1",
            "site_id":site,"php_container":"php-example12345678901234",
            "site_host":"example.test","environment":"staging",
            "tasks":["public","first_fold"]}

class CronRenderer(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix="site-cron-render-")
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.worker=self.root/"worker.py"
        self.worker.write_text("#!/usr/bin/env python3\n")
        self.cfg=self.root/"site-profile.json"
        self.cfg.write_text(json.dumps(profile()))
        self.cfg.chmod(0o600)

    def test_source_offset(self):
        line=render(str(self.root),0)
        self.assertTrue(line.startswith("*/5 * * * * root "))
        self.assertIn("/run/lock/mad4b-css-site-source.lock",line)
        self.assertIn("/run/lock/mad4b-css-wpcron-worker.lock",line)
        self.assertIn(str(self.worker),line)

    def test_independent_second_offset(self):
        self.cfg.write_text(json.dumps(profile("wayfa")))
        line=render(str(self.root),2)
        self.assertTrue(line.startswith("2-59/5 * * * * root "))
        self.assertIn("mad4b-css-site-wayfa.log",line)
        self.assertNotIn("mad4b-css-site-source.log",line)

    def test_out_of_range_offset_is_rejected(self):
        for offset in (-1,5,60):
            with self.subTest(offset=offset):
                with self.assertRaises(ValueError):
                    render(str(self.root),offset)

    def test_injected_site_id_rejected(self):
        self.cfg.write_text(json.dumps(profile("../../not-allowed")))
        with self.assertRaises(ValueError):
            render(str(self.root),0)

    def test_profile_must_not_be_world_readable(self):
        self.cfg.chmod(0o644)
        with self.assertRaises(ValueError):
            render(str(self.root),0)

    def test_profile_symlink_rejected(self):
        second=self.root/"actual-profile.json"
        self.cfg.rename(second)
        self.cfg.symlink_to(second)
        with self.assertRaises(ValueError):
            render(str(self.root),0)

    def test_site_directory_symlink_rejected(self):
        link=self.root.parent/(self.root.name+"-link")
        link.symlink_to(self.root,target_is_directory=True)
        self.addCleanup(lambda:link.unlink(missing_ok=True))
        with self.assertRaises(ValueError):
            render(str(link),0)

    def test_shared_budget_and_bounded_timeouts_kept(self):
        line=render(str(self.root),0)
        self.assertIn("/usr/bin/timeout -k 10s 1500s",line)
        self.assertIn("/usr/bin/nice -n 18",line)
        self.assertIn("/usr/bin/ionice -c 3",line)

if __name__=="__main__":
    unittest.main(verbosity=2)
