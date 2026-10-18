#!/usr/bin/env python3
"""Fail-closed read-only trigger verification for readiness/v* Git tag pushes.

Tag evidence is a source provenance receipt, NOT Production authorization.
No network mutation, deployment, grants, or signed live acceptance.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys

SHA = re.compile(r"[a-f0-9]{40}\Z")
TAG = re.compile(r"refs/tags/readiness/v[0-9]+(?:[._-][A-Za-z0-9]+)*\Z")
MASTER = "refs/remotes/origin/master"


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise ValueError(reason)


def git(*arguments: str) -> str:
    result = subprocess.run(
        ["git", *arguments], capture_output=True, text=True, check=False
    )
    require(result.returncode == 0, "Git tag/ancestry verification failed")
    return result.stdout.strip()


def verify(ref: str, head: str, runner=git) -> dict:
    require(bool(TAG.fullmatch(ref)), "Unreviewed or malformed readiness tag name")
    require(bool(SHA.fullmatch(head)), "Exact commit SHA must be 40 lowercase hex characters")
    tag_commit = runner("rev-parse", "--verify", f"{ref}^{{commit}}")
    checkout = runner("rev-parse", "--verify", "HEAD^{commit}")
    master = runner("rev-parse", "--verify", f"{MASTER}^{{commit}}")
    require(bool(SHA.fullmatch(master)), "Unverifiable master ref")
    require(tag_commit == head and checkout == head,
            "Tag ref, event SHA and checked-out commit differ")
    # Raises on non-ancestor, missing master or detached/unrelated history.
    runner("merge-base", "--is-ancestor", head, master)
    return {
        "contract": "mad4b.readiness-tag-trigger-evidence.v1",
        "source": "EXACT_GIT_TAG_AND_MASTER_ANCESTRY",
        "event": "push",
        "ref": ref,
        "exact_head_sha": head,
        "tag_target_sha": tag_commit,
        "master_verified_sha": master,
        "master_ancestor_verified": True,
        "authorizing": False,
        "staging_accepted": False,
        "production_ready": False,
        "production_authorized": False,
        "mutation_performed": False,
    }


def self_test() -> None:
    head, master = "a" * 40, "b" * 40
    ref = "refs/tags/readiness/v2026.10.19-rc1"
    def good(*args):
        if args[0] == "merge-base":
            assert args == ("merge-base", "--is-ancestor", head, master)
            return ""
        if args[-1] == f"{ref}^{{commit}}" or args[-1] == "HEAD^{commit}":
            return head
        if args[-1] == f"{MASTER}^{{commit}}":
            return master
        raise AssertionError(f"Unexpected git command: {args}")
    report = verify(ref, head, good)
    assert report["master_ancestor_verified"] is True
    assert report["production_authorized"] is False
    assert report["mutation_performed"] is False
    for invalid in (
        "refs/tags/v1", "refs/heads/readiness/v1",
        "refs/tags/readiness/other", "refs/tags/readiness/vbad",
        "refs/tags/readiness/v1/extra", "refs/tags/readiness/v1..2",
        "refs/tags/readiness/v1;echo-bypass",
    ):
        try:
            verify(invalid, head, good)
        except ValueError:
            pass
        else:
            raise AssertionError(f"Unsafe tag accepted: {invalid}")
    for invalid_head in ("a" * 39, "Z" * 40, "0" * 41):
        try:
            verify(ref, invalid_head, good)
        except ValueError:
            pass
        else:
            raise AssertionError("Invalid HEAD accepted")
    def wrong_tag(*args):
        return "c" * 40 if args[-1] == f"{ref}^{{commit}}" else good(*args)
    try:
        verify(ref, head, wrong_tag)
    except ValueError:
        pass
    else:
        raise AssertionError("Retargeted tag accepted")
    def untrusted_branch(*args):
        if args[0] == "merge-base":
            raise ValueError("Not an ancestor")
        return good(*args)
    try:
        verify(ref, head, untrusted_branch)
    except ValueError:
        pass
    else:
        raise AssertionError("Non-master tag accepted")
    print("READINESS_TAG_TRIGGER_FAIL_CLOSED_TEST: PASS")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["self-test", "verify"])
    parser.add_argument("--ref", default="")
    parser.add_argument("--head-sha", default="")
    parser.add_argument("--out", default="artifacts/production-readiness")
    args = parser.parse_args()
    try:
        if args.command == "self-test":
            self_test()
            return 0
        if os.environ.get("GITHUB_ACTIONS") == "true":
            require(os.environ.get("GITHUB_EVENT_NAME") == "push",
                    "Tag evaluation requires a push event")
            require(os.environ.get("GITHUB_REF") == args.ref and
                    os.environ.get("GITHUB_SHA") == args.head_sha,
                    "Tag evaluation must be bound to the GitHub event")
        report = verify(args.ref, args.head_sha)
        destination = Path(args.out)
        destination.mkdir(parents=True, exist_ok=True)
        (destination / "tag-trigger-evidence.json").write_text(
            json.dumps(report, indent=2) + "\n", encoding="utf-8"
        )
        (destination / "tag-trigger-evidence.md").write_text(
            "# Readiness tag trigger (source-only)\n\n"
            f"- Ref: \`{args.ref}\`\n"
            f"- Exact commit: \`{args.head_sha}\`\n"
            "- Master ancestry: VERIFIED\n"
            "- Production authorization: **NO-GO**\n",
            encoding="utf-8",
        )
        print("READINESS_TAG_TRIGGER_VERIFIED_SOURCE_ONLY:", args.ref, args.head_sha)
        return 0
    except (ValueError, OSError, subprocess.SubprocessError) as exc:
        print("READINESS_TAG_TRIGGER_FAIL_CLOSED:", exc, file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
