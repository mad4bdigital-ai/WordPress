#!/usr/bin/env python3
"""Additive original Central 313b verifier bridge for enrolled dynamic providers.

Original native_finish continues to require independently signed Broker finish,
pending original 313 receipt, a live 312 grant and immutable Job. For a Job
without an existing legacy independent proof, the original verifier delegates
to the persisted independently signed dynamic proof. Legacy proof path is
unchanged. No Job or signature is created by this patch.
"""
from __future__ import annotations
import argparse
from pathlib import Path


def transform(source: str) -> str:
    if "const DYNAMIC_FINALIZER_BRIDGE='v1';" in source:
        raise ValueError("DYNAMIC_FINALIZER_ALREADY_PATCHED")
    old="final class MAD4B_Native_WPCLI_Receipt_Verifier_V1 {\n"
    new=old+" const DYNAMIC_FINALIZER_BRIDGE='v1';\n"
    if source.count(old)!=1:
        raise ValueError("ORIGINAL_313_CLASS_ANCHOR_CHANGED")
    source=source.replace(old,new,1)
    old="""  $row=get_option(self::PREFIX.$id,false);
  if(!is_array($row))return self::denied('INDEPENDENT_PROOF_NOT_PRESENT');
  $x=self::check($row,false);
"""
    new="""  $row=get_option(self::PREFIX.$id,false);
  if(!is_array($row)) {
    // Only the already-enrolled first-party dynamic verifier may supply a
    // separately persisted independent signed evidence. The Broker original
    // 313 native_finish enforces its signed finish and 312 scope *before*
    // calling this adapter. This branch never issues its own receipt.
    if(class_exists('MAD4B_Dynamic_WP_313_Readback_V1',false)
       && is_callable(['MAD4B_Dynamic_WP_313_Readback_V1',
                       'verify_exact_for_native_finish'])) {
      return MAD4B_Dynamic_WP_313_Readback_V1::
        verify_exact_for_native_finish($a,$auth,$original,$pending);
    }
    return self::denied('INDEPENDENT_PROOF_NOT_PRESENT');
  }
  $x=self::check($row,false);
"""
    if source.count(old)!=1:
        raise ValueError("ORIGINAL_313_PROOF_LOOKUP_ANCHOR_CHANGED")
    return source.replace(old,new,1)


if __name__=="__main__":
    p=argparse.ArgumentParser()
    p.add_argument("--source",type=Path,required=True)
    p.add_argument("--output",type=Path,required=True)
    x=p.parse_args()
    x.output.write_text(transform(x.source.read_text()))
    print("ORIGINAL_313_DYNAMIC_BRIDGE_PATCH_PREPARED")
