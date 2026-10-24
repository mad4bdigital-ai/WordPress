#!/usr/bin/env python3
"""Explicit-file, HTTPS-only client for MAD4B Staging attachment-transfer v1.

The caller must separately obtain and authorize source bytes (for example a
ChatGPT attachment materialized into a local workspace). No ChatGPT scraping,
conversation access, or token discovery occurs here.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener, HTTPSHandler

MAX_BYTES = 16 * 1024 * 1024
MAX_CHUNK = 128 * 1024
ALLOWED_ACTIONS = {"begin", "chunk", "status", "finish", "abort"}
TRANSFER_ID = re.compile(r"[a-f0-9]{32}\Z")
SHA256 = re.compile(r"[a-f0-9]{64}\Z")


class TransferError(Exception):
    pass


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        raise TransferError("Redirect refused: transfer origin must stay pinned")


def normalized_site(value: str) -> str:
    parsed = urlsplit(value)
    if (parsed.scheme != "https" or not parsed.hostname or parsed.username
            or parsed.password or parsed.port not in (None, 443)
            or parsed.query or parsed.fragment or parsed.path not in ("", "/")):
        raise TransferError("Destination must be an explicit HTTPS site origin, without credentials or query")
    return "https://" + parsed.hostname.lower()


def file_identity(file: Path) -> tuple[int, str]:
    if not file.is_file() or file.is_symlink():
        raise TransferError("Source must be a regular non-symlink file")
    size = file.stat().st_size
    if not 1 <= size <= MAX_BYTES:
        raise TransferError("Source file exceeds 16 MiB or is empty")
    digest = hashlib.sha256()
    with file.open("rb") as handle:
        while True:
            block = handle.read(MAX_CHUNK)
            if not block:
                break
            digest.update(block)
    return size, digest.hexdigest()


def send(site: str, token: str, action: str, data: dict) -> dict:
    if action not in ALLOWED_ACTIONS:
        raise TransferError("Unknown transfer action")
    url = normalized_site(site) + "/wp-json/mad4b-chatgpt-transfer/v1/" + action
    payload = json.dumps(data, separators=(",", ":")).encode("utf-8")
    request = Request(url, data=payload, method="POST", headers={
        "Authorization": "Bearer " + token,
        "Content-Type": "application/json",
        "Accept": "application/json",
    })
    opener = build_opener(NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
    try:
        with opener.open(request, timeout=35) as response:
            if response.status != 200:
                raise TransferError("Unexpected WordPress HTTP status " + str(response.status))
            if response.headers.get("Content-Length") and int(response.headers["Content-Length"]) > 65536:
                raise TransferError("Oversized WordPress response")
            raw = response.read(65537)
    except HTTPError as error:
        raise TransferError("WordPress returned HTTP " + str(error.code)) from None
    except URLError as error:
        raise TransferError("Unable to reach the pinned HTTPS WordPress origin") from error
    if len(raw) > 65536:
        raise TransferError("Oversized WordPress response")
    try:
        result = json.loads(raw)
    except (ValueError, UnicodeDecodeError) as error:
        raise TransferError("WordPress returned non-JSON response") from error
    if not isinstance(result, dict):
        raise TransferError("WordPress response must be an object")
    if result.get("code"):
        raise TransferError("WordPress rejected transfer: " + str(result["code"])[:90])
    return result


def transfer(site: str, source: Path, token: str, resume_id: str | None = None) -> dict:
    size, sha = file_identity(source)
    if resume_id is not None and not TRANSFER_ID.fullmatch(resume_id):
        raise TransferError("Invalid 32-hex transfer ID")
    if resume_id:
        transfer_id = resume_id
        state = send(site, token, "status", {"transfer_id": transfer_id})
    else:
        state = send(site, token, "begin", {
            "filename": source.name, "size": size, "sha256": sha,
        })
        transfer_id = state.get("transfer_id")
        if not isinstance(transfer_id, str) or not TRANSFER_ID.fullmatch(transfer_id):
            raise TransferError("Server returned invalid transfer identity")
    print("TRANSFER_RESUME_ID: " + transfer_id, file=sys.stderr, flush=True)
    offset = state.get("next_offset")
    if not isinstance(offset, int) or isinstance(offset, bool) or not 0 <= offset <= size:
        raise TransferError("Server returned out-of-range offset")
    with source.open("rb") as file:
        file.seek(offset)
        while offset < size:
            chunk = file.read(min(MAX_CHUNK, size - offset))
            result = send(site, token, "chunk", {
                "transfer_id": transfer_id,
                "offset": offset,
                "data_base64": base64.b64encode(chunk).decode("ascii"),
            })
            new_offset = result.get("next_offset")
            if new_offset != offset + len(chunk):
                raise TransferError("Server chunk acknowledgment mismatch; query status before retrying")
            offset = new_offset
    result = send(site, token, "finish", {"transfer_id": transfer_id})
    if (result.get("verified") is not True or result.get("sha256") != sha
            or result.get("bytes") != size or not isinstance(result.get("attachment_id"), int)
            or result["attachment_id"] <= 0):
        raise TransferError("Attachment receipt did not match local source")
    return {
        "verified": True,
        "attachment_id": result["attachment_id"],
        "attachment_url": result.get("attachment_url"),
        "sha256": sha,
        "bytes": size,
        "publication_performed": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--file", required=True, type=Path)
    parser.add_argument("--site", required=True, help="Exact enrolled HTTPS Staging origin")
    parser.add_argument("--token-env", default="MAD4B_WORDPRESS_OAUTH_TOKEN")
    parser.add_argument("--resume-id", help="Transfer ID returned by an earlier begin request")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        origin = normalized_site(args.site)
        size, digest = file_identity(args.file)
        if args.dry_run:
            print(json.dumps({"dry_run": True, "site": origin, "bytes": size, "sha256": digest}))
            return 0
        token = os.environ.get(args.token_env, "")
        if not token or "\r" in token or "\n" in token:
            raise TransferError("A valid OAuth Bearer token must be supplied through the named environment variable")
        print(json.dumps(transfer(origin, args.file, token, args.resume_id)), flush=True)
        return 0
    except (TransferError, OSError) as error:
        print("TRANSFER_BLOCKED: " + str(error), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
