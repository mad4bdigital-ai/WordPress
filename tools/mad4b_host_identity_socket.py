#!/usr/bin/env python3
"""Single-source, local-only signed MAD4B Host identity challenge service.

Only the existing enrolled Host Runner's Ed25519 key signs. Never exposes
private keys, generic commands, URLs, credentials, filesystem writes or
WordPress grants. Operator must provision and start this service on Staging.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import socket
import stat
import struct

from mad4b_host_runner import load_profile, sign_live_host_identity_challenge

SOCKET_PATTERN = re.compile(r"^/(?:var/run|run)/mad4b-host-runner/[A-Za-z0-9._-]{1,80}\\.sock$")
MAX_REQUEST = 3072
MAX_REPLY = 8192


def reject_duplicates(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Host challenge contains duplicate keys")
        result[key] = value
    return result


def sign_request(raw, profile, *, peer_uid, expected_uid):
    if not isinstance(peer_uid, int) or not isinstance(expected_uid, int) or peer_uid == 0 or peer_uid != expected_uid:
        raise ValueError("Host identity requires the exact non-root site worker Unix peer")
    if not isinstance(raw, bytes) or not raw or len(raw) > MAX_REQUEST or not raw.endswith(b"\n"):
        raise ValueError("Host identity request framing invalid")
    try:
        challenge = json.loads(raw[:-1].decode("utf-8"), object_pairs_hook=reject_duplicates)
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError("Host challenge JSON invalid") from exc
    if not isinstance(challenge, dict):
        raise ValueError("Host identity accepts a single challenge object only")
    return sign_live_host_identity_challenge(profile, challenge)


def verify_socket_location(path):
    if not SOCKET_PATTERN.fullmatch(str(path)):
        raise ValueError("Only private MAD4B Host Runner run-directory sockets are supported")
    root = path.parent
    if root.is_symlink() or not root.is_dir():
        raise ValueError("Host socket directory must be an existing real directory")
    stats = root.stat()
    if stats.st_uid != os.geteuid() or stat.S_IMODE(stats.st_mode) & 0o022:
        raise ValueError("Host socket directory must be owned by Runner and not group/world writable")
    if path.exists() or path.is_symlink():
        raise ValueError("Host identity socket exists; never unlink a possible active service")


def serve(profile_path, socket_path):
    if not hasattr(socket, "SO_PEERCRED") or not hasattr(os, "geteuid") or os.geteuid() == 0:
        raise ValueError("Host signer needs non-root Linux Unix peer credentials")
    profile = load_profile(profile_path)
    if profile.get("environment") != "staging":
        raise ValueError("Only trusted Staging enrollment is supported")
    expected_uid = os.stat(profile["wordpress_root"]).st_uid
    if expected_uid == 0:
        raise ValueError("WordPress runtime root identity cannot receive a Host signing socket")
    # Existing Host key enrollment and filesystem owner checks, not a new key.
    from mad4b_host_runner import _wp_environment_receipt_signing_key
    _wp_environment_receipt_signing_key(profile)
    verify_socket_location(socket_path)
    server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    bound_inode = None
    try:
        server.bind(str(socket_path))
        os.chmod(socket_path, 0o600)
        bound_inode = os.lstat(socket_path).st_ino
        server.listen(4)
        server.settimeout(1.0)
        while True:
            try:
                conn, _ = server.accept()
            except socket.timeout:
                continue
            with conn:
                conn.settimeout(1.0)
                try:
                    credentials = conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12)
                    _, peer_uid, _ = struct.unpack("3i", credentials)
                    raw = bytearray()
                    while len(raw) <= MAX_REQUEST:
                        part = conn.recv(1)
                        if not part:
                            break
                        raw.extend(part)
                        if part == b"\n":
                            break
                    proof = sign_request(bytes(raw), profile, peer_uid=peer_uid, expected_uid=expected_uid)
                    reply = (json.dumps(proof, separators=(",", ":"), ensure_ascii=False) + "\n").encode()
                    if len(reply) > MAX_REPLY:
                        raise ValueError("Host signed reply size over budget")
                    conn.sendall(reply)
                except (ValueError, OSError, TimeoutError, KeyError, TypeError):
                    # Bounded error, no secrets, filesystem paths or raw challenge.
                    try:
                        conn.sendall(b'{"state":"BLOCKED","verified":false}\n')
                    except OSError:
                        pass
    finally:
        server.close()
        if bound_inode is not None:
            try:
                if socket_path.is_socket() and socket_path.lstat().st_ino == bound_inode:
                    socket_path.unlink()
            except OSError:
                pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile", required=True, type=Path)
    parser.add_argument("--socket", required=True, type=Path)
    options = parser.parse_args()
    serve(options.profile, options.socket)


if __name__ == "__main__":
    main()
