"""Regression for live-origin Wayfa/Tours.dev guards; temp files only, no browser."""
import importlib.util
import os
from pathlib import Path
import shutil
import tempfile
import time
import unittest
from unittest.mock import patch

PKG=Path(__file__).resolve().parents[1]
A="0123456789abcdef0123456789abcdef"
B="1234567890abcdef1234567890abcdef"
C="2234567890abcdef1234567890abcdef"

def load(name):
    path=PKG/"bin"/name
    spec=importlib.util.spec_from_file_location(name.replace("-","_").replace(".","_"),path)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

def fixture_cache(root, token, asset):
    f=root/token[0]/token[1:3]/token
    f.parent.mkdir(parents=True,exist_ok=True)
    f.write_bytes(b'HTTP/1.1 200 OK\r\n\r\n<html>tours-and-activities <link href="/wp-content/litespeed/'+asset.encode()+b'"></html>')
    old=time.time()-10
    os.utime(f,(old,old))
    return f

class NativeRecovery(unittest.TestCase):
    def test_wayfa_restore_and_precise_evict(self):
        g=load("wayfa-litespeed-fastcgi-asset-coherence-v1.py")
        with tempfile.TemporaryDirectory() as t:
            p=Path(t);pub=p/"pub"; store=p/"store";cache=p/"cache"
            for d in (pub/"css",pub/"js",store/"css",store/"js",cache): d.mkdir(parents=True,exist_ok=True)
            copy=store/"css"/(A+".css")
            copy.write_bytes(b"/* retained wayfa css */")
            old=time.time()-10;os.utime(copy,(old,old))
            good=fixture_cache(cache,"a"*32,"css/"+A+".css")
            bad=fixture_cache(cache,"b"*32,"js/"+B+".js")
            with patch.object(g,"PUBLIC",pub),patch.object(g,"STORE",store),patch.object(g,"REPORT",p/"receipt.json"),patch.object(g,"secure_directory",lambda *args,**kw:None),patch.object(g,"nginx_cache_dir",lambda:cache),patch.object(os,"chown",lambda *a,**k:None):
                result=g.execute(True)
                self.assertEqual(result["restored_missing_public_bundles"],1)
                self.assertEqual(result["fastcgi_invalid_objects_evicted"],1)
                self.assertTrue(good.exists())
                self.assertFalse(bad.exists())
                self.assertEqual((pub/"css"/(A+".css")).read_bytes(),copy.read_bytes())
                again=g.execute(True)
                self.assertEqual(again["restored_missing_public_bundles"],0)
                self.assertEqual(again["fastcgi_invalid_objects_evicted"],0)
                self.assertFalse(again["wp_db_write"])

    def test_toursdev_reference_only_archive_restore_precise_evict(self):
        g=load("toursdev-litespeed-fastcgi-coherence-v1.py")
        with tempfile.TemporaryDirectory() as t:
            p=Path(t);pub=p/"pub";store=p/"store";cache=p/"cache"
            for d in (pub/"css",pub/"js",store/"css",store/"js",cache):d.mkdir(parents=True,exist_ok=True)
            css=pub/"css"/(A+".css");css.write_bytes(b"/* source css */")
            unused=pub/"js"/(C+".js");unused.write_bytes(b"/* unused */")
            old=time.time()-10;os.utime(css,(old,old))
            healthy=fixture_cache(cache,"a"*32,"css/"+A+".css")
            def fake_archive(src,dst):
                shutil.copy2(src,dst)
                return True
            with patch.object(g,"PUBLIC",pub),patch.object(g,"STORE",store),patch.object(g,"REPORT",p/"receipt.json"),patch.object(g,"safe_public",lambda:None),patch.object(g,"safe_archive",lambda:None),patch.object(g,"cache_root",lambda:cache),patch.object(g,"atomic_archive",fake_archive),patch.object(os,"chown",lambda *a,**k:None):
                first=g.run(True)
                self.assertEqual(first["archived"],1)
                self.assertFalse((store/"js"/(C+".js")).exists())
                css.unlink()
                second=g.run(True)
                self.assertEqual(second["restored"],1)
                self.assertTrue(healthy.exists())
                bad=fixture_cache(cache,"b"*32,"js/"+B+".js")
                third=g.run(True)
                self.assertEqual(third["evicted_cache_objects"],1)
                self.assertFalse(bad.exists())
                self.assertTrue(healthy.exists())
                repeat=g.run(True)
                self.assertEqual(repeat["evicted_cache_objects"],0)
                self.assertEqual(repeat["restored"],0)
                self.assertFalse(repeat["db_changed"])

    def test_both_native_inotify_watchers(self):
        for name in ("wayfa-litespeed-asset-event-watch-v2.py","toursdev-litespeed-asset-event-watch-v2.py"):
            with self.subTest(name=name),tempfile.TemporaryDirectory() as t:
                g=load(name)
                watcher=g.Inotify()
                try:
                    root=Path(t);watcher.add(root,"asset");f=root/(A+".css")
                    f.write_bytes(b"synthetic")
                    self.assertTrue(any(n==f.name and flags & g.IN_CLOSE_WRITE for _,flags,n in watcher.get_events(500)))
                    f.unlink()
                    self.assertTrue(any(n==f.name and flags & g.IN_DELETE for _,flags,n in watcher.get_events(500)))
                finally:watcher.close()

    def test_bundle_lock(self):
        path=PKG/"install.py"
        spec=importlib.util.spec_from_file_location("package_installer",path)
        mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
        self.assertEqual(len(mod.verify_package()["files"]),10)
        self.assertEqual(set(mod.SITES),{"wayfa","toursdev"})
        self.assertTrue(all(len(x["start"])==2 for x in mod.SITES.values()))

if __name__=="__main__":unittest.main()
