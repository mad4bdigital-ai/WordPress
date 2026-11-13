#!/usr/bin/env python3
"""Add the first-party signed Code Release grant preclaim to active original 306."""
from __future__ import annotations
import argparse
from pathlib import Path

ANCHOR="def dynamic_execute_original_claimed(job,actor):"
CONDITION="  dynamic_ready=(dynamic_candidate and dynamic_313_original_central_ready(actor))"
CLAIMED="""  if (isinstance(operation,str) and operation.startswith('dynamic:')
      and not dynamic_313_original_central_ready(actor)):
   continue
"""

def transform(source:str)->str:
    if "def dynamic_release_grant_ready(job,command_sha):" in source:
        raise RuntimeError("DYNAMIC_JOB_GRANT_PREFLIGHT_ALREADY_INSTALLED")
    from patch_original_worker import HELPERS
    # Include the production-only, fixed root store paths along with the
    # function. Hermetic test overrides must not hide absent real constants.
    start=HELPERS.index("DYNAMIC_RELEASE_GRANTS=Path(")
    end=HELPERS.index("def dynamic_execute_original_claimed(job,actor):",start)
    helper=HELPERS[start:end]
    if source.count(ANCHOR)!=1 or source.count(CONDITION)!=1 or source.count(CLAIMED)!=1:
        raise RuntimeError("ACTUAL_ORIGINAL_306_WORKER_SOURCE_CHANGED")
    result=source.replace(ANCHOR,helper+ANCHOR,1)
    result=result.replace(CONDITION,
        """  dynamic_ready=(dynamic_candidate and dynamic_313_original_central_ready(actor)
                 and dynamic_release_grant_ready(job,j.get('command_sha256')))""",1)
    result=result.replace(CLAIMED,
        """  if (isinstance(operation,str) and operation.startswith('dynamic:')
      and not (dynamic_313_original_central_ready(actor) and
               dynamic_release_grant_ready(job,row.get('command_sha256')))):
   continue
""",1)
    return result

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--source",type=Path,required=True)
    parser.add_argument("--output",type=Path,required=True)
    opts=parser.parse_args()
    opts.output.write_text(transform(opts.source.read_text()))
    print("ORIGINAL_DYNAMIC_306_SIGNED_GRANT_PREFLIGHT_PREPARED")
