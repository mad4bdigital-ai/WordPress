"""Exact, nonauthorizing refactor proof for a site-bound Term Meta Trait.

Proves that the PHP implementation is a byte-exact extraction, and remains
read-only: both legacy class and new trait are checked against pinned source.
"""
import hashlib
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
REL='wp-content/plugins/mad4b-site-control-plane/includes/'
BASE='20aab770d21f45442fe09b5ae840a90b7ff8fe5a'
ORIGINAL='9169abd2dbce76dfbe087337799dd475eced8a66'
MAIN='188c7c00411834785f3d00fcd05541d10037f12a'
TRAIT='dedbbb99e50d819009e994ad245a412c738d5f28'
FACADE=REL+'class-mad4b-scp-brand-integrity.php'
TRAIT_FILE=REL+'trait-mad4b-scp-brand-integrity-term-meta.php'
LOADER=b"require_once __DIR__.'/trait-mad4b-scp-brand-integrity-term-meta.php';\n"
USE=b'    use MAD4B_SCP_Brand_Integrity_Term_Meta_Trait;\n'
START=b'    /**\n     * Read-only term-meta audit for public destination/editorial taxonomies.'
END=b'    public static function scan_batch(array $types'
HEADER=b'''<?php
/**
 * Read-only Term Meta and destination taxonomy integrity.
 * Exact extracted methods from MAD4B_SCP_Brand_Integrity; no behavioral changes.
 * This is a Trait owned by the original final class, not a new global writer.
 */
defined('ABSPATH') || exit;

trait MAD4B_SCP_Brand_Integrity_Term_Meta_Trait {
'''

def sha(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\x00'+data).hexdigest()

def reconstruct(main,trait):
    if sha(main)!=MAIN or sha(trait)!=TRAIT:
        raise AssertionError('Unreviewed Brand Integrity facade/trait bytes')
    if main.count(LOADER)!=1 or main.count(USE)!=1:
        raise AssertionError('Missing, duplicate or changed controlled loader/use')
    if not trait.startswith(HEADER) or not trait.endswith(b'}\n'):
        raise AssertionError('Unexpected Trait prefix/suffix')
    block=trait[len(HEADER):-2]
    if block.count(START)!=1 or block.count(b'    public static function scan_term_report(')!=1:
        raise AssertionError('Term Meta method declaration changed')
    if main.count(END)!=1:
        raise AssertionError('Post scanner anchor changed')
    out=main.replace(LOADER,b'',1).replace(USE,b'',1).replace(END,block+END,1)
    if sha(out)!=ORIGINAL:
        raise AssertionError('Inverse does not reconstruct all original bytes')
    return out

class BrandTermTraitSource(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.main=(ROOT/FACADE).read_bytes()
        cls.trait=(ROOT/TRAIT_FILE).read_bytes()

    def test_original_class_reconstructed_exactly(self):
        old=subprocess.check_output(['git','show',BASE+':'+FACADE],cwd=ROOT)
        self.assertEqual(sha(old),ORIGINAL)
        self.assertEqual(reconstruct(self.main,self.trait),old)

    def test_six_term_methods_preserved(self):
        body=self.trait[len(HEADER):-2]
        for name in ('scan_term_report','scan_term_media_origin_report',
                     'term_value_report','unstable_relative_anchor_hrefs',
                     'term_meta_candidate_preflight','scan_term_batch'):
            self.assertEqual(body.count(b'function '+name.encode()+b'('),1,name)
        self.assertNotIn(b'update_term_meta(',body)
        self.assertNotIn(b'wp_insert_term(',body)
        self.assertNotIn(b'wp_update_term(',body)

    def test_modified_class_or_trait_fails_closed(self):
        with self.assertRaises(AssertionError):reconstruct(self.main+b'\n',self.trait)
        with self.assertRaises(AssertionError):reconstruct(self.main,self.trait+b'\n')
        with self.assertRaises(AssertionError):reconstruct(self.main.replace(USE,b'',1),self.trait)
        with self.assertRaises(AssertionError):reconstruct(self.main,self.trait.replace(b'Read-only',b'Read-write',1))

    def test_healthy_file_budgets(self):
        self.assertLessEqual(len(self.main.splitlines()),900)
        self.assertLessEqual(len(self.trait.splitlines()),900)

if __name__=='__main__':unittest.main()
