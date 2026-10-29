#!/usr/bin/env python3
"""Synthetic safe/unsafe fixtures for the governed owner-comment DFSB dispatch."""
import base64
import copy
import importlib.util
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("dfsb_dispatch",ROOT/"tools/dfsb-neutral/owner_command_dispatch.py")
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
REPO="mad4bdigital-ai/WordPress"
SHA="a"*40; MASTER="b"*40; OTHER="c"*40; PR=567
VERSION="0.4.0-alpha.15-rc.1"; OWNER="mad4bdigital-ai"
BODY=f"/run-dfsb-release-review {SHA} {PR} {VERSION}"
POLICY={"single_owner_safety":{"authorized_owner_logins":[OWNER]}}

def event(**changes):
    e={"action":"created","sender":{"login":OWNER},
       "comment":{"id":91,"body":BODY,"user":{"login":OWNER}},
       "issue":{"number":PR,"pull_request":{"url":"trusted-pr"}}}
    for key,val in changes.items():
        if key in ("body","id"):e["comment"][key]=val
        elif key=="login":e["comment"]["user"]["login"]=val
        elif key=="sender":e["sender"]["login"]=val
        elif key=="number":e["issue"]["number"]=val
        elif key=="is_pr":e["issue"]["pull_request"]={"url":"trusted-pr"} if val else None
        else:e[key]=val
    return e

def records():
    path=f"repos/{REPO}"
    header=f"<?php\n/**\n * Version: {VERSION}\n */\n"
    return {
      f"{path}/pulls/{PR}":{"state":"closed","merged":True,"base":{"ref":"master"},
         "head":{"sha":SHA,"repo":{"full_name":REPO}}},
      f"{path}/branches/master":{"commit":{"sha":MASTER}},
      f"{path}/compare/{SHA}...{MASTER}":{"behind_by":0,"status":"ahead"},
      f"{path}/contents/{mod.PLUGIN_HEADER}?ref={SHA}":{"encoding":"base64",
         "content":base64.b64encode(header.encode()).decode()},
      f"{path}/issues/{PR}/comments?per_page=100&page=1":[
        {"id":90,"body":f"OWNER_ATTEST_SINGLE_OWNER\nexact_head_sha: {SHA}","user":{"login":OWNER}},
        {"id":91,"body":BODY,"user":{"login":OWNER}}],
    }

def execute(e=None,record=None,kind="issue_comment",repo=REPO):
    rows=records() if record is None else record
    dispatched=[]
    def get(path):return copy.deepcopy(rows[path])
    def post(path,data):dispatched.append((path,data))
    try:
        result=mod.process(e or event(),kind,repo,POLICY,get,post)
        return result,dispatched
    except mod.Denied:return None,dispatched

def denied(*args,**kw):
    result,posted=execute(*args,**kw)
    assert result is None and not posted,(result,posted)

def verify():
    result,posted=execute()
    assert result["result"]=="REVIEW_WORKFLOW_DISPATCH_REQUESTED" and len(posted)==1
    assert posted[0][1]=={"ref":"master","inputs[source_sha]":SHA,
       "inputs[review_pr_number]":str(PR),"inputs[version]":VERSION}
    assert not result["provider_certified"] and not result["staging_authorized"] and not result["production_authorized"]
    for e in (event(body=BODY+"\n/merge"),event(body=BODY+" unsafe"),
              event(body=BODY.replace(SHA,OTHER)),
              event(body=BODY.replace(VERSION,"0.4.0-alpha.14-local.1")),
              event(login="attacker"),event(sender="attacker"),event(action="edited"),
              event(number=999),event(is_pr=False),event(id=0)):
        denied(e)
    denied(kind="commit_comment");denied(repo="another/repo")
    for key,modify in [
        ("pulls",lambda d:d.update({"merged":False})),
        ("pulls",lambda d:d["head"].update({"sha":OTHER})),
        ("pulls",lambda d:d["base"].update({"ref":"dev"})),
        ("compare",lambda d:d.update({"behind_by":1})),
        ("comments",lambda d:d.pop(0)),
        ("comments",lambda d:d.append({"id":89,"body":BODY,"user":{"login":OWNER}})),
    ]:
        r=records()
        target=next((v for k,v in r.items() if (key=="pulls" and k.endswith("/pulls/567")) or
                    (key=="compare" and "/compare/" in k) or
                    (key=="comments" and k.endswith("/comments?per_page=100&page=1"))),None)
        modify(target)
        denied(record=r)
    y=(ROOT/".github/workflows/dfsb-owner-command-review-dispatch.yml").read_text()
    for must in ("\n  issue_comment:\n","types: [created]","actions: write","contents: read",
                 "ref: ${{ github.event.repository.default_branch }}","persist-credentials: false"):
        assert must in y,must
    for nope in ("contents: write","pull_request_target:","gh release create","plugin-package-apply"):
        assert nope not in y,nope
    print("DFSB_OWNER_COMMENT_DISPATCH_SECURITY: PASS")

if __name__=="__main__":verify()
